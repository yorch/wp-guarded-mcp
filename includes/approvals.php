<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* A queue of tool calls that wait for a person.
*
* The two-step confirmation in GMCP_Core::confirm_gate() makes an irreversible call take
* two requests, and both requests come from the agent. A token the server mints is one the
* server hands straight back to whoever asked, so an agent that has been talked into
* "delete plugin X" by a comment it read simply makes the second call. It raises the bar
* from one call to two and no further, and the docblock there says so.
*
* This is the other kind of gate. When it is switched on, a call to a gated tool does not
* run. It is written down as a pending request and the agent is told to wait. A person
* signed in to wp-admin approves or declines it on the Approvals screen, and only then does
* the agent's identical retry run, once.
*
* WHAT MAKES IT OUT OF BAND. There is no approval token. Nothing is minted that the agent
* could be handed, guess, or read back: the request id the agent is told is a reference and
* grants nothing, and the retry is matched on what it IS (the key, the tool, a keyed hash of
* the arguments) against a row only a person can move to "approved". The row changes state
* in exactly one place, decide(), which refuses unless the request is a real wp-admin
* session: a logged-in cookie that belongs to the current user, manage_options, a nonce, and
* a request that is not a tool call or a REST request. No MCP tool reads, lists, approves,
* declines or edits this table, and a test asserts that at every access level.
*
* WHAT IT BINDS. An approval is for one key, one tool and one set of arguments. The
* arguments are reduced to canonical JSON (object keys sorted, so the order a client happens
* to serialise them in does not matter) and hashed with HMAC under a key the database does
* not hold. Keyed on purpose: arguments can contain a password, and a plain digest of a
* low-entropy secret stored beside the row is an offline guess waiting to be made.
*
* WHAT IT DOES NOT DO. It does not replace the two-step; an approved call still goes
* through confirm_gate() if the tool has one. It does not make a gated tool safe, only
* attended. And what the person sees is the redacted arguments (GMCP_Audit::storable_args),
* so a credential inside them is approved unseen; the hash covers the real value.
*/
class GMCP_Approvals {

  const DB_VERSION = '1';
  const DB_VERSION_OPTION = 'gmcp_approvals_db_version';

  /** never: nothing waits. deletes: deletions and anything unclassified. site: and site-wide changes. */
  const MODES = [ 'never', 'deletes', 'site' ];

  /** A request nobody has answered lapses after this long. */
  const PENDING_TTL = HOUR_IN_SECONDS;
  /** An approval nobody has used lapses after this long. */
  const APPROVAL_TTL = 15 * MINUTE_IN_SECONDS;
  /**
  * How long an identical call is refused after a person said no. Without it a declined
  * agent re-asks on every turn and the queue fills with the same question.
  */
  const DECLINE_COOLDOWN = 15 * MINUTE_IN_SECONDS;
  /** Open requests one credential may hold, and the queue as a whole. */
  const MAX_OPEN_PER_IDENTITY = 20;
  const MAX_OPEN_TOTAL = 200;
  /** Settled rows are kept this long for the screen; the audit log keeps the history. */
  const RETENTION_DAYS = 30;
  /**
  * How long an account that a tool call created, or whose password, address or role a tool
  * call changed, cannot answer a request. @see watch_accounts().
  */
  const TOUCHED_WINDOW = DAY_IN_SECONDS;
  const TOUCHED_META = 'gmcp_changed_by_mcp';

  /**
  * Verbs that destroy something. Matched against the name's segments, and only ever
  * widens the gate: a tool that annotation or level already calls destructive stays so.
  */
  const DESTRUCTIVE_VERBS = [ 'delete', 'remove', 'unenroll', 'unschedule', 'trash', 'purge',
    'uninstall', 'cancel', 'drop', 'erase', 'wipe', 'destroy', 'revoke', 'reset', 'run' ];

  /** The verbs of tools that only look. Used for admin-level tools, which carry no annotation in the optional groups. */
  const READ_VERBS = [ 'get', 'list', 'count' ];

  #region Setup

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'gmcp_approvals';
  }

  /**
  * Create or update the table, and listen for the daily housekeeping.
  *
  * Called on every request that loads the plugin, like the audit log's constructor, because
  * WordPress does not fire the activation hook when a plugin is updated in place.
  */
  public static function boot(): void {
    if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
      self::install();
    }
    add_action( GMCP_Audit::CRON_HOOK, [ __CLASS__, 'prune' ] );
    self::watch_accounts();
  }

  public static function install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    $collate = $wpdb->get_charset_collate();
    // Times are unix integers so that "has it expired" is one comparison in the same
    // statement that changes the row, which is what makes a claim atomic.
    dbDelta( "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      created int(10) unsigned NOT NULL DEFAULT 0,
      expires int(10) unsigned NOT NULL DEFAULT 0,
      status varchar(12) NOT NULL DEFAULT 'pending',
      identity varchar(100) NOT NULL DEFAULT '',
      label varchar(191) NOT NULL DEFAULT '',
      auth_method varchar(16) NOT NULL DEFAULT '',
      tool varchar(64) NOT NULL DEFAULT '',
      tool_class varchar(16) NOT NULL DEFAULT '',
      args_mac char(64) NOT NULL DEFAULT '',
      args_view longtext NULL,
      decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
      decided_at int(10) unsigned NOT NULL DEFAULT 0,
      approved_until int(10) unsigned NOT NULL DEFAULT 0,
      used_at int(10) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY  (id),
      KEY status_expires (status,expires),
      KEY call_lookup (args_mac,status)
    ) {$collate};" );
    update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
  }

  /** The configured mode. An unrecognised stored value is the strictest one, not 'never'. */
  public static function mode(): string {
    $core = $GLOBALS['gmcp_core'] ?? null;
    $mode = $core ? (string) $core->get_option( 'mcp_approval_mode', 'never' ) : 'never';
    return in_array( $mode, self::MODES, true ) ? $mode : 'site';
  }

  #endregion

  #region Classification (pure)

  /**
  * What kind of tool this is, from the tool's own registration.
  *
  * Derived rather than listed, because a list of tool names is the thing that drifts: the
  * twelfth optional group would arrive ungated. The inputs are the metadata every tool
  * already carries. In order:
  *
  *   unknown      no definition, or no recognised access level. Gated in every mode.
  *   destructive  destructiveHint, or a destructive verb in the name. This outranks the
  *                access level, so a tool that contradicts itself is held.
  *   read         access level read, or an admin-level tool that is annotated read-only or
  *                whose verb is get/list/count.
  *   content      write level. The README defines that level as "create and update, no
  *                destructive tools"; smoke-admin.sh asserts no write tool breaks it.
  *   site         anything else at admin level: plugins, themes, users, options, settings,
  *                menus, the optional groups' writes.
  *
  * @param array|null $tool The registered definition: name, accessLevel, annotations.
  */
  public static function classify( ?array $tool ): string {
    $name = is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ? strtolower( $tool['name'] ) : '';
    $level = is_array( $tool ) ? ( $tool['accessLevel'] ?? null ) : null;
    if ( $name === '' || !in_array( $level, [ 'read', 'write', 'admin' ], true ) ) {
      return 'unknown';
    }
    $annotations = is_array( $tool['annotations'] ?? null ) ? $tool['annotations'] : [];
    $segments = explode( '_', $name );

    if ( !empty( $annotations['destructiveHint'] ) || array_intersect( $segments, self::DESTRUCTIVE_VERBS ) ) {
      return 'destructive';
    }
    if ( $level === 'read' ) {
      return 'read';
    }
    if ( $level === 'write' ) {
      return 'content';
    }
    // The verb is the segment after the group prefix (wp_, gf_, ld_, ...), not any segment:
    // a mutating tool with "list" somewhere in its name must not be waved through.
    if ( !empty( $annotations['readOnlyHint'] ) || in_array( $segments[1] ?? '', self::READ_VERBS, true ) ) {
      return 'read';
    }
    return 'site';
  }

  /** Strictest last. A call is held under the strictest class any part of it earns. */
  const CLASS_RANK = [ 'read' => 0, 'content' => 1, 'site' => 2, 'account' => 3, 'destructive' => 4, 'unknown' => 5 ];

  /**
  * What kind of CALL this is: the tool's class, widened by what the arguments ask for.
  *
  * classify() judges the registration, and a registration cannot see that a harmless-looking
  * update is being asked to delete. A status of "trash" (or "auto-draft") on any write is a
  * deletion that WordPress finishes on its own schedule, so it is held as the deletion it is
  * whatever the tool is called. This only ever widens: a read tool that is merely LISTING
  * trashed things keeps its class, because naming a status to look at is not a write.
  *
  * The tools in this plugin that take a status refuse trash themselves (GMCP_Core::
  * status_write_refusal()), so for those this is the second lock. It exists for the
  * tool nobody has written yet, and for a plugin's tool the first lock cannot reach.
  *
  * @param array|null $tool The registered definition.
  * @param array      $args The call's arguments.
  */
  public static function classify_call( ?array $tool, array $args ): string {
    $class = self::classify( $tool );
    if ( $class !== 'read' && self::asks_for_deletion_status( $args ) ) {
      $class = self::stricter( $class, 'destructive' );
    }
    if ( $class !== 'read' && self::changes_who_can_sign_in( $tool, $args ) ) {
      $class = self::stricter( $class, 'account' );
    }
    return $class;
  }

  /**
  * Whether a user tool's call creates an account or changes how one signs in or what it may do.
  *
  * Why this is not left to the 'site' class. 'site' waits only in the wider mode, so in mode
  * 'deletes' an admin key could create an administrator with a password it chose, sign in to
  * wp-login.php as that account, and approve its own held calls: a logged-in cookie that
  * belongs to the current user is all human_session() asks. Account creation and account
  * takeover are the one kind of site change that defeats the gate itself, so they wait in
  * every mode that is not 'never'.
  *
  * Judged on the tool's NAME having a user segment (wp_create_user, wp_update_user, and any
  * tool of that shape added later) and then on the arguments, failing closed:
  *
  *   - every creation waits (it always carries an address, a login and a role);
  *   - an update waits unless it touches display fields only AND the target holds none of
  *     GMCP_Core::privileged_capabilities(). Any password, address, role, or a field this
  *     list does not know, waits. Any role waits, even a demotion: "the lowest role" is a
  *     site's own decision (default_role can be anything), and a call that names a role is
  *     not worth guessing about.
  *
  * Only ever widens. A user tool that is merely reading keeps its class.
  */
  private static function changes_who_can_sign_in( ?array $tool, array $args ): bool {
    $name = is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ? strtolower( $tool['name'] ) : '';
    $segments = explode( '_', $name );
    if ( !array_intersect( $segments, [ 'user', 'users' ] ) ) {
      return false;
    }
    if ( !in_array( 'update', $segments, true ) ) {
      // create, set, reset, add, grant: anything that is not an update has no display-only
      // exemption to speak of.
      return true;
    }
    $id = $args['ID'] ?? null;
    if ( !is_numeric( $id ) || (int) $id <= 0 || GMCP_Core::user_is_privileged( (int) $id ) ) {
      return true;
    }
    foreach ( array_keys( $args ) as $key ) {
      if ( !in_array( $key, [ 'ID', 'confirm', 'fields' ], true ) ) {
        return true;
      }
    }
    $fields = $args['fields'] ?? null;
    if ( is_string( $fields ) ) {
      $fields = json_decode( $fields, true );
    }
    if ( is_object( $fields ) ) {
      $fields = get_object_vars( $fields );
    }
    if ( !is_array( $fields ) || !$fields ) {
      return true;
    }
    $display_only = [ 'display_name', 'first_name', 'last_name', 'nickname', 'description', 'user_url', 'user_nicename' ];
    foreach ( array_keys( $fields ) as $key ) {
      if ( $key === 'ID' ) {
        // Forced back to the top-level id by the tool, but a nested one is not display.
        return true;
      }
      if ( !in_array( strtolower( (string) $key ), $display_only, true ) ) {
        return true;
      }
    }
    return false;
  }

  private static function stricter( string $a, string $b ): string {
    $rank = self::CLASS_RANK;
    return ( $rank[ $b ] ?? 99 ) > ( $rank[ $a ] ?? 99 ) ? $b : $a;
  }

  /** Whether a top-level or `fields` status argument is one GMCP_Core refuses to write. */
  private static function asks_for_deletion_status( array $args ): bool {
    $scopes = [ $args ];
    if ( isset( $args['fields'] ) ) {
      $fields = $args['fields'];
      if ( is_string( $fields ) ) {
        $fields = json_decode( $fields, true );
      }
      if ( is_object( $fields ) ) {
        $fields = get_object_vars( $fields );
      }
      // A `fields` that is not an object we can read is not one we can vouch for.
      if ( !is_array( $fields ) ) {
        return true;
      }
      $scopes[] = $fields;
    }
    foreach ( $scopes as $scope ) {
      foreach ( [ 'post_status', 'status', 'comment_approved' ] as $key ) {
        if ( array_key_exists( $key, $scope ) && GMCP_Core::status_write_refusal( $scope[ $key ] ) !== null ) {
          return true;
        }
      }
    }
    return false;
  }

  /** Whether a class of tool waits for a person under a mode. */
  public static function needs_approval( string $mode, string $class ): bool {
    if ( $mode === 'never' ) {
      return false;
    }
    if ( $class === 'unknown' || $class === 'destructive' || $class === 'account' ) {
      return true;
    }
    // Any mode that is not 'never' or 'deletes' is read as the strictest.
    return $class === 'site' && $mode !== 'deletes';
  }

  #endregion

  #region Binding (pure but for the HMAC key)

  /**
  * Arguments in one form per meaning: objects sorted by key, lists left in order.
  *
  * So {"a":1,"b":2} and {"b":2,"a":1} are the same call, and [1,2] and [2,1] are not.
  * `confirm` is dropped when the tool declares it as its two-step token, because the retry
  * that carries the token is the same call finishing, and an approval that did not cover it
  * would have the person approve every deletion twice. @see finish().
  */
  public static function canonical_json( array $args, bool $drop_confirm = false ) {
    if ( $drop_confirm && isset( $args['confirm'] ) && is_string( $args['confirm'] ) ) {
      unset( $args['confirm'] );
    }
    // Plain json_encode, not wp_json_encode: that one repairs invalid UTF-8 by substituting
    // characters, so two different byte strings could hash alike, and an approval must bind
    // to the exact bytes. Failing to encode is a refusal in gate(), not a quiet repair.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
    return json_encode(
      self::canonicalise( $args ),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
  }

  private static function canonicalise( $value ) {
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) ) {
      return $value;
    }
    $out = [];
    foreach ( $value as $k => $inner ) {
      $out[ $k ] = self::canonicalise( $inner );
    }
    if ( $out !== array_values( $out ) ) {
      ksort( $out, SORT_STRING );
    }
    return $out;
  }

  /**
  * The keyed hash a request is matched on, or false when the arguments cannot be encoded.
  *
  * Folds in the tool name, so a hash cannot be carried from one tool to another even if
  * a caller forgot the tool column. $secret is injectable for tests.
  *
  * @return string|false
  */
  public static function args_mac( string $tool, array $args, bool $drop_confirm = false, ?string $secret = null ) {
    $json = self::canonical_json( $args, $drop_confirm );
    if ( $json === false ) {
      return false;
    }
    if ( $secret === null ) {
      $secret = wp_salt( 'auth' );
    }
    return hash_hmac( 'sha256', $tool . "\x1f" . $json, $secret );
  }

  /** Whether the tool's schema declares `confirm` as a string, i.e. as the two-step token. */
  private static function declares_confirm( ?array $tool ): bool {
    $props = $tool['inputSchema']['properties']['confirm'] ?? null;
    return is_array( $props ) && ( $props['type'] ?? '' ) === 'string';
  }

  #endregion

  #region The dispatcher's side

  /**
  * Decide what happens to a call that has passed every other check.
  *
  * Returns either [ 'run' => true, 'claim' => int ] or a reply to give instead of running.
  * Any failure to read or write the queue is a refusal, never a pass: a gate that opens
  * when its table is missing is a gate that is open.
  *
  * @param array $caller identity (who this is), label, auth_method, user_id
  */
  public static function gate( string $tool, array $args, ?array $definition, array $caller ): array {
    // wpdb prints a failed query straight into the response when WP_DEBUG_DISPLAY is on,
    // which would put text in front of the JSON-RPC body. Errors are read from last_error
    // below and turned into a refusal, so nothing is lost by not printing them.
    global $wpdb;
    $noisy = $wpdb->suppress_errors( true );
    try {
      return self::gate_inner( $tool, $args, $definition, $caller );
    }
    finally {
      $wpdb->suppress_errors( $noisy );
    }
  }

  private static function gate_inner( string $tool, array $args, ?array $definition, array $caller ): array {
    $mode = self::mode();
    if ( $mode === 'never' ) {
      return [ 'run' => true, 'claim' => 0 ];
    }
    $class = self::classify_call( $definition, $args );
    if ( !self::needs_approval( $mode, $class ) ) {
      return [ 'run' => true, 'claim' => 0 ];
    }

    global $wpdb;
    $table = self::table();
    $identity = mb_substr( (string) ( $caller['identity'] ?? '' ), 0, 100 );
    if ( $identity === '' ) {
      $identity = 'anonymous';
    }
    $mac = self::args_mac( $tool, $args, self::declares_confirm( $definition ) );
    if ( $mac === false ) {
      return self::refusal( 'This call could not be queued for approval because its arguments could not be encoded as JSON, so nothing ran.' );
    }

    self::expire_stale();
    $now = time();

    // 1. A person has approved exactly this call. Claim it in the same statement that
    // checks it, so two retries racing each other cannot both run.
    $candidate = $wpdb->get_var( $wpdb->prepare(
      "SELECT id FROM {$table} WHERE identity = %s AND tool = %s AND args_mac = %s
        AND status = 'approved' AND approved_until >= %d ORDER BY id ASC LIMIT 1",
      $identity, $tool, $mac, $now
    ) );
    if ( $wpdb->last_error ) {
      return self::refusal( 'The approval queue could not be read, so the call was not run.' );
    }
    if ( $candidate ) {
      $claimed = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'used', used_at = %d
          WHERE id = %d AND status = 'approved' AND approved_until >= %d",
        $now, (int) $candidate, $now
      ) );
      if ( $claimed === 1 ) {
        self::event( 'approval:used', 'ok', $caller, $tool, (int) $candidate, 'Approved call is running (single use).' );
        return [ 'run' => true, 'claim' => (int) $candidate ];
      }
    }

    // 2. Already waiting. Same id, no second row, so polling does not grow the queue.
    $open = $wpdb->get_row( $wpdb->prepare(
      "SELECT id, expires FROM {$table} WHERE identity = %s AND tool = %s AND args_mac = %s
        AND status = 'pending' AND expires > %d ORDER BY id DESC LIMIT 1",
      $identity, $tool, $mac, $now
    ), ARRAY_A );
    if ( $open ) {
      return self::waiting( (int) $open['id'], (int) $open['expires'], $tool );
    }

    // 3. A person said no a moment ago.
    $declined = $wpdb->get_var( $wpdb->prepare(
      "SELECT id FROM {$table} WHERE identity = %s AND tool = %s AND args_mac = %s
        AND status = 'declined' AND decided_at > %d ORDER BY id DESC LIMIT 1",
      $identity, $tool, $mac, $now - self::DECLINE_COOLDOWN
    ) );
    if ( $declined ) {
      return self::refusal( sprintf(
        'A site administrator declined request #%d for this exact call, so it did not run. Do not retry it; ask the user what they want instead.',
        (int) $declined
      ) );
    }

    // 4. An approval for this very call was already spent. The retry is refused and a fresh
    // request is opened, so the person is asked again rather than the old answer being
    // honoured twice. Recorded, because "the agent ran it again" is worth a line.
    $spent = $wpdb->get_var( $wpdb->prepare(
      "SELECT id FROM {$table} WHERE identity = %s AND tool = %s AND args_mac = %s
        AND status = 'used' AND approved_until >= %d ORDER BY id DESC LIMIT 1",
      $identity, $tool, $mac, $now
    ) );
    if ( $spent ) {
      self::event( 'approval:replay_refused', 'refused', $caller, $tool, (int) $spent,
        'An identical call arrived after its approval had been used once; it did not run and a new request was opened.' );
    }

    // 5. Ask.
    $by_identity = (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE identity = %s AND status = 'pending' AND expires > %d", $identity, $now ) );
    $total = (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND expires > %d", $now ) );
    if ( $by_identity >= self::MAX_OPEN_PER_IDENTITY || $total >= self::MAX_OPEN_TOTAL ) {
      return self::refusal( 'Too many calls are already waiting for approval, so this one was not queued and did not run. Ask the site administrator to review the Approvals screen.' );
    }

    $inserted = $wpdb->insert( $table, [
      'created' => $now,
      'expires' => $now + self::PENDING_TTL,
      'status' => 'pending',
      'identity' => $identity,
      'label' => mb_substr( (string) ( $caller['label'] ?? '' ), 0, 191 ),
      'auth_method' => mb_substr( (string) ( $caller['auth_method'] ?? '' ), 0, 16 ),
      'tool' => mb_substr( $tool, 0, 64 ),
      'tool_class' => $class,
      'args_mac' => $mac,
      // Redacted by the one function the audit log uses, so what a person reads here and
      // what the log keeps cannot disagree about what a credential is.
      'args_view' => GMCP_Audit::storable_args( $args ),
    ] );
    if ( !$inserted ) {
      return self::refusal( 'The approval queue could not be written, so the call was not run.' );
    }
    $id = (int) $wpdb->insert_id;
    self::event( 'approval:requested', 'ok', $caller, $tool, $id,
      'Held for human approval (' . $class . ' tool, mode ' . $mode . ').' );
    return self::waiting( $id, $now + self::PENDING_TTL, $tool );
  }

  /**
  * After the tool has run: give an unspent approval back if the call only issued a token.
  *
  * confirm_gate() answers the first leg of a two-step with a token and changes nothing. If
  * that consumed the approval, the person would be asked again for the second leg, which is
  * the same decision twice. So when the tool says it withheld, the row goes back to
  * 'approved' with its ORIGINAL deadline: the window does not restart, and the second leg
  * spends it. A leg that actually ran is never given back.
  */
  public static function finish( int $claim ): void {
    if ( $claim <= 0 || !GMCP_Core::$gate_withheld ) {
      return;
    }
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
      "UPDATE " . self::table() . " SET status = 'approved', used_at = 0
        WHERE id = %d AND status = 'used' AND approved_until >= %d",
      $claim, time()
    ) );
  }

  private static function waiting( int $id, int $expires, string $tool ): array {
    $text = sprintf(
      'This call has NOT run. It is waiting for human approval (request #%1$d). A signed-in site administrator has to approve it on the MCP Server > Approvals screen in wp-admin; no tool can do that for you, so do not try. The request lapses at %2$s. After it is approved, repeat this exact call (same tool, same arguments) within %3$d minutes and it will run once. Tell the user that approval is needed.',
      $id, gmdate( 'H:i \U\T\C', $expires ), (int) ( self::APPROVAL_TTL / MINUTE_IN_SECONDS )
    );
    return [
      'run' => false,
      'is_error' => false,
      'text' => $text,
      'data' => [
        'status' => 'awaiting_human_approval',
        'request_id' => $id,
        'tool' => $tool,
        'expires_at' => gmdate( 'c', $expires ),
      ],
    ];
  }

  private static function refusal( string $text ): array {
    return [ 'run' => false, 'is_error' => true, 'text' => $text, 'data' => [ 'status' => 'not_run' ] ];
  }

  #endregion

  #region The person's side

  /**
  * Whether THIS request is a person at wp-admin, as opposed to a request that merely has
  * an administrator's identity.
  *
  * The distinction is the whole feature. A bearer key and an OAuth grant both end with
  * wp_set_current_user() on the account that owns them, so current_user_can('manage_options')
  * is true for an agent. What an agent does not have is the logged-in cookie: it is checked
  * against the current user, so an identity set by anything other than that cookie fails
  * here. A request that already ran tool_start or arrived over REST is refused outright,
  * because an approval must not be reachable from inside the thing being approved.
  */
  public static function human_session(): bool {
    return self::session_problem() === null;
  }

  /**
  * Why this request is not a person answering, or null when it is.
  *
  *   not_a_session  no wp-admin page load by a signed-in manage_options user's own cookie.
  *   recent_change  that user's account was created, or had its password, address or role
  *                  changed, by a tool call within TOUCHED_WINDOW.
  *
  * The second exists because the first is a statement about the COOKIE and not about the
  * PERSON. An agent that can create an administrator, or reset an administrator's password,
  * can then sign in at wp-login.php as that account and hold a perfectly valid cookie. The
  * tools that do it are held in every mode but 'never' (classify_call()), and this is the
  * second lock for the call that slipped through (mode 'never' when it was made, a key from
  * before the setting, a plugin's own tool): what a tool call set up in the last day cannot
  * vouch for a tool call.
  *
  * The cost is that an administrator whose own password an agent changed, or who asked an
  * agent to make them an account, answers from another administrator's account or waits a
  * day. Deliberately not clearable from any screen, since a button would be the way round.
  */
  public static function session_problem(): ?string {
    if ( !is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || did_action( 'gmcp_tool_start' ) ) {
      return 'not_a_session';
    }
    $user = get_current_user_id();
    if ( $user <= 0 || !current_user_can( 'manage_options' ) ) {
      return 'not_a_session';
    }
    if ( (int) wp_validate_auth_cookie( '', 'logged_in' ) !== $user ) {
      return 'not_a_session';
    }
    return self::account_changed_by_tool( $user ) ? 'recent_change' : null;
  }

  /** Whether a tool call touched this account's sign-in or role inside the window. */
  public static function account_changed_by_tool( int $user_id ): bool {
    $at = (int) get_user_meta( $user_id, self::TOUCHED_META, true );
    return $at > 0 && ( time() - $at ) < self::TOUCHED_WINDOW;
  }

  /**
  * Remember accounts that a tool call created or re-credentialed.
  *
  * Listens to WordPress, not to the two user tools, for the reason the change journal does:
  * a tool of another group, or a plugin's callback during a tool call, changes a password
  * through the same functions. Only while a tool call is in flight, so a person editing a
  * profile in wp-admin marks nobody. A password and an address are compared against what the
  * row held before, so saving a display name does not start a day's lockout.
  */
  private static function watch_accounts(): void {
    add_action( 'user_register', [ __CLASS__, 'mark_account' ], 10, 1 );
    add_action( 'profile_update', function ( $user_id, $old = null ) {
      $now = get_userdata( (int) $user_id );
      if ( $now && $old instanceof WP_User && ( $now->user_pass !== $old->user_pass || $now->user_email !== $old->user_email ) ) {
        self::mark_account( $user_id );
      }
    }, 10, 2 );
    add_action( 'set_user_role', [ __CLASS__, 'mark_account' ], 10, 1 );
    add_action( 'add_user_role', [ __CLASS__, 'mark_account' ], 10, 1 );
    add_action( 'wp_set_password', function ( $password, $user_id ) {
      self::mark_account( $user_id );
    }, 10, 2 );
  }

  public static function mark_account( $user_id ): void {
    if ( (int) $user_id > 0 && GMCP_Core::tool_in_flight() ) {
      update_user_meta( (int) $user_id, self::TOUCHED_META, time() );
    }
  }

  public static function nonce_action( string $verdict, int $id ): string {
    return 'gmcp_approval_' . $verdict . '_' . $id;
  }

  /**
  * Approve or decline one pending request.
  *
  * The only place a request leaves 'pending' by a person's hand. Every refusal says why and
  * none of them changes anything.
  *
  * @return array{0:bool,1:string} success, and the sentence to show.
  */
  public static function decide( int $id, string $verdict, string $nonce ): array {
    if ( !in_array( $verdict, [ 'approve', 'decline' ], true ) || $id <= 0 ) {
      return [ false, __( 'That is not a request this screen can answer.', 'guarded-mcp' ) ];
    }
    $problem = self::session_problem();
    if ( $problem === 'recent_change' ) {
      return [ false, __( 'This account was created, or had its password, email address or role changed, by an AI tool call in the last 24 hours, so it cannot approve requests yet. Approve from another administrator account that no tool has changed, or wait until the day is up.', 'guarded-mcp' ) ];
    }
    if ( $problem !== null ) {
      return [ false, __( 'Approvals can only be given from a signed-in wp-admin session.', 'guarded-mcp' ) ];
    }
    if ( !wp_verify_nonce( $nonce, self::nonce_action( $verdict, $id ) ) ) {
      return [ false, __( 'That form has expired. Reload the page and try again.', 'guarded-mcp' ) ];
    }

    global $wpdb;
    $table = self::table();
    self::expire_stale();
    $now = time();
    $user = get_current_user_id();

    if ( $verdict === 'approve' ) {
      $changed = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'approved', decided_by = %d, decided_at = %d, approved_until = %d
          WHERE id = %d AND status = 'pending' AND expires > %d",
        $user, $now, $now + self::APPROVAL_TTL, $id, $now
      ) );
    }
    else {
      $changed = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'declined', decided_by = %d, decided_at = %d
          WHERE id = %d AND status = 'pending' AND expires > %d",
        $user, $now, $id, $now
      ) );
    }
    if ( $changed !== 1 ) {
      return [ false, __( 'That request is no longer waiting: it was already answered or it expired.', 'guarded-mcp' ) ];
    }

    $row = self::get( $id );
    self::event( 'approval:' . ( $verdict === 'approve' ? 'approved' : 'declined' ), 'ok',
      [ 'identity' => 'wp-admin', 'label' => 'wp-admin', 'auth_method' => 'wp-admin', 'user_id' => $user ],
      (string) ( $row['tool'] ?? '' ), $id,
      $verdict === 'approve'
        ? sprintf( 'Approved for key %s; valid for %d minutes, single use.', (string) ( $row['identity'] ?? '' ), (int) ( self::APPROVAL_TTL / MINUTE_IN_SECONDS ) )
        : sprintf( 'Declined for key %s.', (string) ( $row['identity'] ?? '' ) ) );

    return [ true, $verdict === 'approve'
      ? __( 'Approved. The agent can now repeat the call once, within 15 minutes.', 'guarded-mcp' )
      : __( 'Declined. The agent was not told anything beyond that the call did not run.', 'guarded-mcp' ) ];
  }

  #endregion

  #region Reading and housekeeping

  public static function get( int $id ): ?array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
    return $row ?: null;
  }

  /** @return array[] Requests still waiting, oldest first, so none is buried by a newer one. */
  public static function pending(): array {
    global $wpdb;
    return (array) $wpdb->get_results( $wpdb->prepare(
      'SELECT * FROM ' . self::table() . " WHERE status = 'pending' AND expires > %d ORDER BY id ASC LIMIT 200", time() ), ARRAY_A );
  }

  public static function pending_count(): int {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
      'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending' AND expires > %d", time() ) );
  }

  /** @return array[] The most recent settled or approved requests. */
  public static function recent( int $limit = 25 ): array {
    global $wpdb;
    return (array) $wpdb->get_results( $wpdb->prepare(
      'SELECT * FROM ' . self::table() . " WHERE status <> 'pending' ORDER BY id DESC LIMIT %d", max( 1, min( 100, $limit ) ) ), ARRAY_A );
  }

  /**
  * Move lapsed rows to 'expired', one at a time, and say so in the audit log.
  *
  * Pending rows lapse an hour after they were asked; approved rows that were never used
  * lapse fifteen minutes after approval. Bounded, so a flood of lapsed rows cannot make
  * one tool call slow. Run on every gate, decision and screen view, and daily.
  */
  public static function expire_stale(): void {
    global $wpdb;
    $table = self::table();
    $now = time();
    $rows = (array) $wpdb->get_results( $wpdb->prepare(
      "SELECT id, status, identity, tool FROM {$table}
        WHERE ( status = 'pending' AND expires <= %d ) OR ( status = 'approved' AND approved_until < %d )
        ORDER BY id ASC LIMIT 50", $now, $now ), ARRAY_A );
    foreach ( $rows as $row ) {
      $changed = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'expired' WHERE id = %d AND status = %s", (int) $row['id'], $row['status'] ) );
      if ( $changed === 1 ) {
        self::event( 'approval:expired', 'ok',
          [ 'identity' => 'system', 'label' => 'system', 'auth_method' => 'system', 'user_id' => 0 ],
          (string) $row['tool'], (int) $row['id'],
          ( $row['status'] === 'approved'
            ? 'Approved but never used within the window; lapsed.'
            : 'Nobody answered within the hour; lapsed.' ) . ' Key ' . $row['identity'] . '.' );
      }
    }
  }

  /** Daily: lapse what is due, then drop settled rows past retention. Never touches an open one. */
  public static function prune(): void {
    global $wpdb;
    self::expire_stale();
    $wpdb->query( $wpdb->prepare(
      'DELETE FROM ' . self::table() . " WHERE status IN ('declined','expired','used') AND created < %d",
      time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) );
  }

  /** One line in the audit log's hash chain. @see GMCP_Audit::record_event(). */
  private static function event( string $event, string $outcome, array $caller, string $tool, int $id, string $detail ): void {
    if ( !class_exists( 'GMCP_Audit' ) ) {
      return;
    }
    GMCP_Audit::record_event( [
      'tool' => $event,
      'actor' => (int) ( $caller['user_id'] ?? 0 ),
      'client' => (string) ( $caller['label'] ?? '' ),
      'auth_method' => (string) ( $caller['auth_method'] ?? '' ),
      'target' => $tool,
      'outcome' => $outcome,
      // Reference data only. No arguments: they are in the queue row, redacted, and the
      // audit entry for the held call itself.
      'args' => [ 'request' => $id, 'tool' => $tool, 'caller' => (string) ( $caller['identity'] ?? '' ) ],
      'detail' => $detail,
    ] );
  }

  #endregion
}
