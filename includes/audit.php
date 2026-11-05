<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The record of what was done through this API, kept properly.
*
* This replaces an earlier version that lived in a single option row. That version said
* so in its own docblock: an option is a read-modify-write, so two calls landing together
* could lose an entry, and it held a hundred rows at most. Fine for glancing at, useless
* for answering "what happened on the fourteenth" three months later.
*
* A table fixes both. An INSERT cannot lose a concurrent entry, rows are indexed by time,
* tool and actor, and pruning is one DELETE rather than rewriting a serialized blob.
*
* Four things are worth explaining, because each is a decision rather than a default.
*
* ARGUMENTS ARE RECORDED, REDACTED. An audit entry that does not say what was asked for
* is half an entry. But wp_create_user and wp_update_user take a password, and
* wp_update_option takes whatever a settings array happens to hold, so writing arguments
* verbatim would put plaintext credentials in a table meant to be kept for months.
* Everything goes through GMCP_Core::redact(), which keeps the shape and blanks the
* leaves, and user_pass is dropped unconditionally whatever the detector thinks. The
* marker is itself information: it records that a secret was passed, without recording it.
*
* EACH ROW HASHES THE ONE BEFORE IT. Nothing here can stop somebody with database access
* editing a row, and pretending otherwise would be worse than not trying. What the chain
* does is make an edit visible: recomputing it finds the first row that no longer matches.
* That is the difference between a history and an audit.
*
* THE LOG IS BOUNDED THREE WAYS, AND ONLY ONE OF THEM DELETES. Age alone lets a runaway
* agent fill a disk in a day, and a row cap or a byte cap that deleted the oldest rows
* would let any key erase real history by flooding. So age is the only bound that removes
* anything. The row and byte bounds stop the log recording instead, say so (an admin
* notice, a Site Health test, the audit screen, the error log), and make the plugin refuse
* every tool above read level until there is room again, because a write nobody can see
* is worse than a write that did not happen. @see prune(), @see refuse_while_stopped().
*
* WHAT WAS CALLED IS NOT WHAT CHANGED. An entry saying wp_update_post ran on post 12 with
* certain arguments does not say that the post went from private to publish, and that is
* usually the thing somebody is looking for. GMCP_Changes watches WordPress during the
* call and reports what actually moved; the changes column holds a summary of it. A
* summary rather than the values, because field-level copies of post bodies would eat the
* pruning bounds, and because some of those values are passwords. @see
* GMCP_Changes::summarise().
*/
class GMCP_Audit {

  /**
  * 2 added the changes column, and with it the canonical hash encoding.
  *
  * The column is nullable, and rows written before the upgrade keep verifying under the
  * construction that signed them, so an existing log verifies unchanged rather than
  * announcing on day one that every row has been tampered with. @see hash().
  *
  * 3 indexed outcome. No column was added and no row was rewritten, so nothing the chain
  * signs is touched; the bump exists only because the constructor re-runs install() when
  * the stored version differs, and that is what makes a site already carrying rows build
  * the index.
  */
  const DB_VERSION = '3';
  const CRON_HOOK = 'gmcp_audit_prune';

  /** The last row written under the old hash construction. @see hash_boundary(). */
  const BOUNDARY_OPTION = 'gmcp_audit_hash_boundary';

  /**
  * Set when a hard bound has been reached and the log has stopped recording.
  *
  * Distinguishes "this call was never made" from "this call happened and could not be
  * written down", which is the same distinction the rest of this file draws for a failed
  * query. An operator reading an audit log with a gap in it needs to know which one it is.
  */
  const STOPPED_OPTION = 'gmcp_audit_stopped';

  /** Per-entry cap on the recorded arguments, before the row is written. */
  const MAX_ARGS = 64000;

  /**
  * And for a refused call, which costs the caller nothing: a key limited to one harmless
  * tool can send refused calls as fast as the endpoint answers, each one recorded. At the
  * full cap a few hundred of them reached the byte bound; at this one the same flood has
  * to be some thirty times larger, and the row still says what was asked for.
  */
  const MAX_REFUSED_ARGS = 2000;

  /**
  * And on the recorded changes, which share the entry with them.
  *
  * Smaller than the argument cap on purpose. A summary is meant to be read, and forty
  * field diffs nobody reads still cost ninety days of disk under the retention bounds.
  */
  const MAX_CHANGES = 16000;

  /**
  * Defaults. The retention is a setting; the row and byte bounds are not, and can be
  * changed through the gmcp_audit_max_rows and gmcp_audit_max_bytes filters. @see max_rows().
  */
  const DEFAULT_DAYS = 90;
  const MAX_ROWS = 50000;
  const MAX_BYTES = 50000000;

  /** Arguments never written down, whatever the credential detector makes of them. */
  const NEVER_RECORD = [ 'user_pass', 'password', 'pass' ];

  public function __construct() {
    // WordPress does not run the activation hook when a plugin is updated in place, so a
    // site that upgrades without deactivating first would carry yesterday's table and
    // every insert naming the new column would fail. Failing inserts in an audit log are
    // the one loss this whole file exists to prevent, so the schema is checked here as
    // well. The check is a comparison against an autoloaded option that is already in
    // memory, not a query: cheap enough to do on every request that loads the plugin.
    if ( get_option( 'gmcp_audit_db_version' ) !== self::DB_VERSION ) {
      self::install();
    }
    add_action( 'gmcp_tool_called', [ $this, 'record' ], 5 );
    add_action( self::CRON_HOOK, [ __CLASS__, 'prune' ] );

    // Scheduling used to happen in the activation hook alone, which runs once: network
    // activation scheduled the main site only, and every other site (whose table is built
    // lazily, above) was never pruned. Checked on init, where the cron array is loaded, so
    // a site that lost the event, or never had it, gets it back.
    add_action( 'init', [ __CLASS__, 'schedule' ] );

    // A log that has stopped recording has to say so, and has to stop the plugin doing
    // things nobody will be able to see afterwards. @see refuse_while_stopped().
    add_filter( 'gmcp_tools', [ __CLASS__, 'remember_levels' ], 9999 );
    add_filter( 'gmcp_callback', [ __CLASS__, 'refuse_while_stopped' ], 1, 4 );
    add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
    add_filter( 'site_status_tests', [ __CLASS__, 'register_site_health' ] );
  }

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'gmcp_audit';
  }

  /**
  * Create or update the table.
  *
  * dbDelta is fussy: two spaces after PRIMARY KEY, KEY rather than INDEX, and lowercase
  * types, or it silently decides nothing needs doing.
  */
  public static function install(): void {
    global $wpdb;
    if ( get_option( 'gmcp_audit_db_version' ) === self::DB_VERSION ) {
      return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = self::table();
    $collate = $wpdb->get_charset_collate();

    // outcome is keyed on its own rather than as (outcome, ts), although the list is
    // always read newest first. InnoDB appends the primary key to every secondary index,
    // so KEY outcome (outcome) is physically (outcome, id), and the list orders by id
    // rather than by ts: query() maps "when" to id because ts has second resolution and a
    // burst of calls sorts arbitrarily within a second. The plain key therefore already
    // hands the refusals view its ORDER BY id DESC, and a pager reads successive pages
    // straight off the index. Naming ts would build (outcome, ts, id), which no longer
    // supplies that ordering and sends the same query to a filesort.
    //
    // Two distinct values is poor selectivity, and that is the right trade here: refusals
    // are a small fraction of the table and are the view an operator opens first, while
    // for outcome = 'ok' the optimizer ignores the index and walks the primary key, which
    // is what it should do.
    dbDelta( "CREATE TABLE {$table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      ts datetime NOT NULL,
      actor bigint(20) unsigned NOT NULL DEFAULT 0,
      actor_name varchar(191) NOT NULL DEFAULT '',
      client varchar(191) NOT NULL DEFAULT '',
      auth_method varchar(32) NOT NULL DEFAULT '',
      tool varchar(64) NOT NULL DEFAULT '',
      target varchar(191) NOT NULL DEFAULT '',
      outcome varchar(16) NOT NULL DEFAULT '',
      ms int(11) NOT NULL DEFAULT 0,
      args longtext NULL,
      changes longtext NULL,
      detail text NULL,
      prev_hash char(64) NOT NULL DEFAULT '',
      hash char(64) NOT NULL DEFAULT '',
      PRIMARY KEY  (id),
      KEY ts (ts),
      KEY tool (tool),
      KEY actor (actor),
      KEY outcome (outcome)
    ) {$collate};" );

    // Which rows predate the canonical hash encoding, noted after the table exists and
    // before anything new is written, so verify() can check them the way the version that
    // wrote them checked them. Recorded once and never overwritten: running this again
    // once new rows existed would sweep them into the old construction and report the lot
    // as broken. A fresh install finds an empty table and records 0, which is right.
    if ( get_option( self::BOUNDARY_OPTION, null ) === null ) {
      update_option( self::BOUNDARY_OPTION, (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ), false );
    }

    update_option( 'gmcp_audit_db_version', self::DB_VERSION );
  }

  /** Daily, from activation. Cleared on deactivation so a disabled plugin schedules nothing. */
  public static function schedule(): void {
    if ( !wp_next_scheduled( self::CRON_HOOK ) ) {
      wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
    }
  }

  public static function unschedule(): void {
    $next = wp_next_scheduled( self::CRON_HOOK );
    if ( $next ) {
      wp_unschedule_event( $next, self::CRON_HOOK );
    }
  }

  #region Writing

  /**
  * Identify what a call was aimed at, for the indexed column.
  *
  * Ordered most specific first, so an update naming both an ID and a title records the
  * ID. post_title is near the end because it is the only identifier a create has, and an
  * id-shaped argument beside it is always the better answer. Three placements are worth
  * saying out loud, because the list is otherwise read as arbitrary.
  *
  * hook carries wp_run_cron_event and wp_unschedule_cron_event, which have no other
  * identifying argument at all. A refused cron call used to record no target, so the one
  * row an operator most wanted to read said only that something was refused.
  *
  * to_id and page_id name the post being written TO. wp_copy_post_meta also takes
  * from_id, and elementor_apply_template also takes template_id; neither is listed,
  * because each is required alongside its partner and so could never be reached, and
  * because the source of a copy is not what the call changed. Both still appear in the
  * arguments, which the deep search reads.
  *
  * entry_id and form_id are the Gravity Forms group's identifiers. entry_id is ahead of
  * form_id and of the generic id because a call that names an entry is aimed at that
  * entry; naming only the form (gf_list_entries, gf_set_form_active) falls through to
  * form_id. Without both, every row those tools wrote recorded an empty target, which is
  * the one column an operator filters on.
  *
  * scope is last because it names a mode rather than a thing. It is reached only for a
  * wp_flush_cache that named no post, where "object" or "transients" is the whole of what
  * the call was aimed at; anything more specific outranks it.
  */
  private function target( array $args ): string {
    foreach ( [ 'plugin', 'stylesheet', 'hook', 'ID', 'post_id', 'item_id', 'widget_id',
      'menu', 'key', 'sidebar', 'user_id', 'comment_ID', 'entry_id', 'term_id', 'form_id',
      'to_id', 'page_id', 'id', 'slug', 'name', 'post_title', 'scope' ] as $key ) {
      if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
        $value = (string) $args[ $key ];
        if ( $value !== '' ) {
          return mb_substr( $value, 0, 191 );
        }
      }
    }
    return '';
  }

  /**
  * Top-level argument names that identify a thing rather than authenticate to it.
  *
  * The credential patterns are written for field names inside a VALUE, where a field
  * called "key" is very likely a secret. At the top level of a tool call it is the
  * opposite: wp_update_option's "key" is the option's name, and wp_update_post_meta's is
  * the meta key. Redacting those leaves an audit entry saying an option was changed
  * without saying which, which is most of what the entry was for.
  *
  * Restored only at the top level and only for scalars, so a nested "key" inside a
  * settings array is still redacted and the change journal's stricter test is untouched.
  */
  const IDENTIFIER_ARGS = [ 'key', 'meta_key', 'option_key' ];

  /** Arguments as they will be stored: redacted, size-capped, and honest about both. */
  public static function storable_args( array $args, bool $failed = false ): string {
    foreach ( self::NEVER_RECORD as $field ) {
      if ( array_key_exists( $field, $args ) ) {
        $args[ $field ] = '[redacted]';
      }
    }
    $redacted = GMCP_Core::redact( $args );
    foreach ( self::IDENTIFIER_ARGS as $field ) {
      if ( isset( $args[ $field ] ) && is_scalar( $args[ $field ] ) ) {
        $redacted[ $field ] = $args[ $field ];

        // A call that names a thing and supplies its content: if the NAME is
        // credential-shaped then the content is a credential, however innocent the
        // argument holding it looks. wp_update_option passes the option name as "key"
        // and the secret as "value", and "value" matches no pattern, so a password
        // written to smtp_pwd was recorded in the clear for the full retention window.
        //
        // The write itself is allowed because option_guard's list, which gates reads and
        // writes, is deliberately narrower than the field-name list, which only decides
        // what gets written down. That asymmetry is right, and it is exactly why the
        // recording side has to look at the name rather than only at the field it arrives
        // under.
        if ( GMCP_Core::field_looks_secret( (string) $args[ $field ] ) ) {
          foreach ( [ 'value', 'meta_value', 'option_value' ] as $companion ) {
            if ( array_key_exists( $companion, $redacted ) ) {
              $redacted[ $companion ] = '[redacted]';
            }
          }
        }
      }
    }
    // Free text as well as names: wp_update_post's content, excerpt and title are strings
    // that start with a tag or a word, so redact() above, which judges field names, keeps
    // them whole, and a body with {"api_key":"..."} or a pasted define('DB_PASSWORD', ...) in
    // it was written to this table verbatim. Only the first MAX_ARGS bytes of a leaf are
    // scanned because no more than that can be stored: the encoded call is capped there.
    $redacted = GMCP_Core::blank_credential_strings( $redacted, 0, self::MAX_ARGS );
    $json = wp_json_encode( $redacted, JSON_UNESCAPED_SLASHES );
    if ( $json === false ) {
      return '{"__gmcp":"arguments could not be encoded"}';
    }
    // A refused call is held to a much smaller cap. @see MAX_REFUSED_ARGS.
    $cap = $failed ? self::MAX_REFUSED_ARGS : self::MAX_ARGS;
    if ( strlen( $json ) > $cap ) {
      // Truncated rather than dropped, and the original size recorded, so a reader can
      // tell a large call from a missing one.
      return wp_json_encode( [
        '__gmcp_truncated' => strlen( $json ),
        'preview' => mb_substr( $json, 0, $failed ? 1000 : 2000 ),
      ], JSON_UNESCAPED_SLASHES );
    }
    return $json;
  }

  public function record( $call ): void {
    if ( !is_array( $call ) || empty( $call['tool'] ) ) {
      return;
    }
    global $wpdb;

    // At a hard bound, refuse to write rather than write into a log that will have its
    // recent rows deleted to make room. The row this would have added is lost either way;
    // what the refusal protects is the rows already there. over_bound() also raises the
    // alarm the first time it finds the log full. @see prune(), @see refuse_while_stopped().
    if ( self::over_bound() ) {
      return;
    }
    $args = is_array( $call['args'] ?? null ) ? $call['args'] : [];
    $failed = ( ( $call['status'] ?? '' ) !== 'success' )
      || !empty( $call['result']['result']['isError'] );

    $user = $call['user_id'] ?? 0;
    $row = [
      'ts' => gmdate( 'Y-m-d H:i:s' ),
      'actor' => (int) $user,
      'actor_name' => $user ? (string) ( get_userdata( $user )->user_login ?? '' ) : '',
      // The nearest thing to who was driving, and it is not the actor. A static bearer
      // token borrows the lowest-numbered administrator, so actor says "admin" whoever
      // sent the request. An OAuth grant names the app, a named key names its label, and
      // a shared token names only itself; falling back to the client id means the column
      // says "bearer" or "key:3" instead of nothing at all, which is a smaller claim but
      // a true one.
      'client' => mb_substr( (string) ( $call['client_name'] ?: ( $call['client_id'] ?? '' ) ), 0, 191 ),
      'auth_method' => mb_substr( (string) ( $call['auth_method'] ?? '' ), 0, 32 ),
      'tool' => mb_substr( (string) $call['tool'], 0, 64 ),
      'target' => self::scrub_text( $this->target( $args ), '[redacted]' ),
      'outcome' => $failed ? 'refused' : 'ok',
      'ms' => (int) ( $call['duration_ms'] ?? 0 ),
      'args' => self::storable_args( $args, $failed ),
      'changes' => class_exists( 'GMCP_Changes' )
        ? GMCP_Changes::summarise( GMCP_Changes::captured(), self::MAX_CHANGES )
        : null,
      'detail' => $this->detail( $call, $failed ),
    ];
    self::append( $row );
  }

  /**
  * Append one row to the chain. The chain's single writer: record(), note() and
  * record_event() all come through here, because two insert paths would be two places to
  * get the previous hash wrong, and a row that does not chain is reported as tampering.
  *
  * The read of the previous hash and the insert have to be one step. Parallel tool calls
  * arrive as separate requests, and two that read the same predecessor before either has
  * inserted write rows with the same prev_hash: the second row's link then fails to match
  * what precedes it, verify() reports a missing row in a log nobody touched, and the fork
  * is permanent because nothing short of clear() removes it. A named lock serialises them.
  * Not SELECT ... FOR UPDATE: that is a no-op on MyISAM, and on InnoDB the gap locks two
  * writers take on the last row are compatible, so both then wait on each other's insert
  * and one is killed with a deadlock, losing its entry.
  *
  * The lock name carries the database and table name: the name space is server-wide, and
  * a multisite network's blogs have no reason to queue behind one another. If the lock
  * cannot be had in five seconds the row is written anyway, because an entry that might sit
  * on a fork is a better outcome than an entry that does not exist; the timeout is logged.
  * A server with no GET_LOCK answers something that is neither '1' nor '0' and is treated as
  * having no lock to take, silently: null from a server that errors on the call, and the
  * string '1=1' from the SQLite drop-in (measured), which reads the call as a comparison.
  *
  * @param array $row the columns, without prev_hash and hash.
  */
  private static function append( array $row ): void {
    global $wpdb;

    // HyperDB and LudicrousDB may send the SELECT below to a lagging replica, which forks
    // the chain with no concurrency at all. The lock, the read and the insert must all go
    // to the primary.
    if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
      $wpdb->send_reads_to_masters();
    }

    $noisy = $wpdb->suppress_errors( true );
    $name = 'gmcp_audit_' . md5( DB_NAME . self::table() );
    $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
    $wpdb->suppress_errors( $noisy );
    $locked = ( (string) $got === '1' );
    if ( (string) $got === '0' ) {
      error_log( '[Guarded MCP] audit chain lock not obtained within 5 seconds for ' . GMCP_Core::log_text( $row['tool'] )
        . '; writing the entry unserialised, so the chain may fork here.' );
    }

    try {
      // As the database will hold them. The hash is computed in PHP before the insert, and
      // wpdb strips what the column's character set cannot represent (a four-byte
      // character on a utf8mb3 table), so hashing the original would make the row fail its
      // own check on the first full walk. Same call wpdb makes itself, run first.
      if ( method_exists( $wpdb, 'strip_invalid_text_for_column' ) ) {
        foreach ( [ 'actor_name', 'client', 'auth_method', 'tool', 'target', 'outcome', 'detail' ] as $column ) {
          if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) ) {
            $clean = $wpdb->strip_invalid_text_for_column( self::table(), $column, $row[ $column ] );
            if ( is_string( $clean ) ) {
              $row[ $column ] = $clean;
            }
          }
        }
      }

      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant, not user input
      $prev = (string) $wpdb->get_var( "SELECT hash FROM " . self::table() . " ORDER BY id DESC LIMIT 1" );
      $row['prev_hash'] = $prev;
      $row['hash'] = self::hash( $row, $prev );

      // Errors suppressed across the insert, and deliberately. wpdb prints a failed query
      // straight to output when WP_DEBUG is on, and this runs inside the tool dispatcher's
      // finally block, so the error text lands in front of the JSON-RPC body and the client
      // gets a parse error instead of its result. A site with an out-of-date table would
      // find every tool call broken rather than one audit entry missing. last_error is
      // still set while suppressed, so nothing is lost but the printing.
      $noisy = $wpdb->suppress_errors( true );
      $written = $wpdb->insert( self::table(), $row );
      $wpdb->suppress_errors( $noisy );
    }
    finally {
      // Released explicitly. A persistent connection keeps its locks after the request.
      if ( $locked ) {
        $noisy = $wpdb->suppress_errors( true );
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        $wpdb->suppress_errors( $noisy );
      }
    }

    if ( $written === false ) {
      // A lost audit entry is the failure this table exists to prevent, and it is the
      // one failure that leaves no trace of itself: the next row chains to the one
      // before, so nothing downstream ever notices. The likeliest cause is a schema
      // older than the code, which the constructor tries to rule out. Say so loudly
      // rather than returning quietly, because the alternative is a log that is wrong
      // and looks intact.
      // last_error is the database's own text, which on a duplicate key or a rejected value
      // can quote the row it was writing: the arguments are in that row.
      error_log( '[Guarded MCP] audit entry NOT recorded for ' . GMCP_Core::log_text( $row['tool'] ) . ': '
        . GMCP_Core::log_text( $wpdb->last_error ?: 'the database reported no error' ) );
    }
  }

  /**
  * Record something the plugin itself did, outside any tool call.
  *
  * For actions taken from the settings screen that bear on the log, so that they leave a
  * row in the chain they affect. Not reachable from a tool.
  */
  public static function note( string $tool, string $detail, string $target = '' ): void {
    if ( self::over_bound() ) {
      return;
    }
    $user = get_current_user_id();
    self::append( [
      'ts' => gmdate( 'Y-m-d H:i:s' ),
      'actor' => $user,
      'actor_name' => $user ? (string) ( get_userdata( $user )->user_login ?? '' ) : '',
      'client' => 'settings screen',
      'auth_method' => 'session',
      'tool' => mb_substr( $tool, 0, 64 ),
      'target' => mb_substr( $target, 0, 191 ),
      'outcome' => 'ok',
      'ms' => 0,
      'args' => '{}',
      'changes' => null,
      'detail' => mb_substr( $detail, 0, 1000 ),
    ] );
  }

  /**
  * Write a row that is not a tool call.
  *
  * The approval queue has events no tool call describes: a person approving a request
  * from wp-admin, a request expiring, a replay being refused. They belong in the same
  * hash chain as the calls they concern, or the log would tell the story of a request
  * and omit the only part a person did.
  *
  * Arguments are taken as already redacted and are redacted again here, so a caller that
  * forgets cannot put a credential in the table. Does nothing when the log is switched
  * off or at a hard bound, for the same reasons record() does not.
  *
  * @param array $fields tool (an event name), actor, client, auth_method, target, outcome,
  *                      args (array), detail
  */
  public static function record_event( array $fields ): void {
    $core = $GLOBALS['gmcp_core'] ?? null;
    if ( !$core || !$core->get_option( 'mcp_activity_log' ) ) {
      return;
    }
    if ( self::over_bound() ) {
      return;
    }
    $actor = (int) ( $fields['actor'] ?? 0 );
    $args = GMCP_Core::blank_credential_strings( GMCP_Core::redact( is_array( $fields['args'] ?? null ) ? $fields['args'] : [] ), 0, self::MAX_ARGS );
    $json = wp_json_encode( $args, JSON_UNESCAPED_SLASHES );
    self::append( [
      'ts' => gmdate( 'Y-m-d H:i:s' ),
      'actor' => $actor,
      'actor_name' => $actor ? (string) ( get_userdata( $actor )->user_login ?? '' ) : '',
      'client' => mb_substr( (string) ( $fields['client'] ?? '' ), 0, 191 ),
      'auth_method' => mb_substr( (string) ( $fields['auth_method'] ?? '' ), 0, 32 ),
      'tool' => mb_substr( (string) ( $fields['tool'] ?? '' ), 0, 64 ),
      'target' => mb_substr( (string) ( $fields['target'] ?? '' ), 0, 191 ),
      'outcome' => ( $fields['outcome'] ?? 'ok' ) === 'refused' ? 'refused' : 'ok',
      'ms' => 0,
      'args' => $json === false ? '{}' : mb_substr( $json, 0, self::MAX_ARGS ),
      'changes' => null,
      'detail' => mb_substr( GMCP_Core::scrub_preview_tokens( wp_strip_all_tags( (string) ( $fields['detail'] ?? '' ) ) ), 0, 1000 ),
    ] );
  }

  /**
  * The human-readable half: why a call was refused, or what it reported doing.
  *
  * A refusal message is the interesting content in this whole table. It is the sentence
  * that says a guard fired, and it is the reason refusals are recorded at all.
  */
  private function detail( array $call, bool $failed ): string {
    $text = (string) ( $call['error_msg'] ?? '' );
    if ( $text === '' ) {
      $text = (string) ( $call['result']['result']['content'][0]['text'] ?? '' );
    }
    // Cut before stripping, not after. wp_strip_all_tags() runs strip_tags plus two PCRE
    // passes over whatever it is handed, and what it is handed here is the tool's entire
    // reply: a chunked read of a 15MB meta value arrives as 21MB of response text, all of
    // it stripped so that 1000 characters can be kept. Measured, that is the first thing
    // to fail on a mid-sized host, and it fails in the worst available way, taking the
    // request down as an HTTP 500 with an empty body, so the caller is told nothing at
    // all. A generous pre-cut bounds the work without changing what a reader sees: the
    // margin is wide enough that the second cut, not the first, decides the result for
    // anything but text that is almost entirely markup, where a shorter line is the
    // right answer anyway.
    $text = wp_strip_all_tags( mb_substr( $text, 0, 8000 ) );
    // A preview link is a bearer credential and its tool's reply is where it is handed out.
    // The reply is recorded here, so the token is cut out of it: an audit log that holds a
    // working link to an unpublished post is a second place that link can be read from.
    return self::scrub_text( mb_substr( GMCP_Core::scrub_preview_tokens( $text ), 0, 1000 ), '[redacted: the reply held something credential-shaped]' );
  }

  /**
  * A short piece of free text as the log may keep it: itself, or $instead when it carries a
  * credential. For the columns that hold text a tool was handed or answered with (the target
  * a call names, the reply's first thousand characters), which no field name classifies.
  * Only text already cut to its storable length is passed, so the scan is bounded.
  */
  public static function scrub_text( string $text, string $instead ): string {
    return $text !== '' && GMCP_Credential_Scan::text_holds( $text ) ? $instead : $text;
  }

  /**
  * One row's link in the chain.
  *
  * Two constructions, and which one a row uses is decided by its id rather than by its
  * contents. Everything at or below the boundary recorded at upgrade time was written by
  * the version that hashed eleven columns joined by a separator, and is verified exactly
  * as that version verified it. Everything above it uses the canonical encoding below.
  *
  * The old construction is not re-signed and not reinterpreted, because a chain the
  * plugin rewrites on demand proves nothing, and an upgraded site being told on day one
  * that its whole audit log has been tampered with is no better.
  *
  * THE CANONICAL ENCODING. Each field contributes its name, the byte length of its
  * value, and the value; the whole is prefixed with a version tag and the field count.
  * Length prefixes are the point rather than decoration. A plain separator join says
  * only "these pieces in this order", so content can be moved from one column into the
  * next behind a separator, and while a fixed field list survives that, a list whose
  * length varies does not: an entry written with a changes column could have its changes
  * appended to the detail column and the column blanked, and the shorter recomputation
  * would rebuild the same string and call the row intact. That was reachable, since a
  * refusal message quotes what the caller asked for. Committing to each field's name and
  * length makes every input unambiguous, so no rearrangement between columns produces the
  * same digest and a thirteenth column later needs no further thought.
  */
  const HASH_FIELDS = [ 'ts', 'actor', 'actor_name', 'client', 'auth_method', 'tool',
    'target', 'outcome', 'ms', 'args', 'changes', 'detail' ];

  /** How the rows written before the canonical encoding were hashed. Unchanged, forever. */
  const LEGACY_HASH_FIELDS = [ 'ts', 'actor', 'actor_name', 'client', 'auth_method', 'tool',
    'target', 'outcome', 'ms', 'args', 'detail' ];

  private static function hash( array $row, string $prev, bool $legacy = false ): string {
    if ( $legacy ) {
      $parts = [];
      foreach ( self::LEGACY_HASH_FIELDS as $field ) {
        $parts[] = (string) ( $row[ $field ] ?? '' );
      }
      return hash( 'sha256', $prev . "\x1f" . implode( "\x1f", $parts ) );
    }
    $parts = [];
    foreach ( self::HASH_FIELDS as $field ) {
      $value = (string) ( $row[ $field ] ?? '' );
      $parts[] = $field . ':' . strlen( $value ) . ':' . $value;
    }
    return hash( 'sha256', 'gmcp/2' . "\x1f" . count( self::HASH_FIELDS ) . "\x1f"
      . $prev . "\x1f" . implode( "\x1f", $parts ) );
  }

  /**
  * The last row written before the canonical encoding, or 0 when there is none.
  *
  * Recorded once, at the upgrade that added the changes column, because a row cannot say
  * for itself which construction signed it. It is not a secret and not a second chain:
  * moving it only makes rows verify under the wrong construction and fail, which is a
  * false alarm rather than a way past one.
  */
  public static function hash_boundary(): int {
    return (int) get_option( self::BOUNDARY_OPTION, 0 );
  }

  #endregion

  #region Reading

  /**
  * The WHERE for a set of filters, and the parameters that go with it.
  *
  * Shared so that counting and listing cannot disagree. They used to: count() accepted
  * a filter array and ignored it, returning the whole table however the list had been
  * narrowed. Nothing noticed while the screen showed a fixed fifty rows and quoted the
  * total separately, and it became wrong the moment a pager divided one by the other.
  *
  * @return array{0:string,1:array} the WHERE clause without the keyword, and its params
  */
  private static function where( array $filters ): array {
    global $wpdb;
    $where = [ '1=1' ];
    $params = [];

    foreach ( [ 'tool' => 'tool', 'outcome' => 'outcome' ] as $key => $column ) {
      if ( !empty( $filters[ $key ] ) ) {
        $where[] = "{$column} = %s";
        $params[] = (string) $filters[ $key ];
      }
    }
    if ( !empty( $filters['actor'] ) ) {
      $where[] = 'actor = %d';
      $params[] = (int) $filters['actor'];
    }
    if ( !empty( $filters['since'] ) ) {
      $where[] = 'ts >= %s';
      $params[] = (string) $filters['since'];
    }
    if ( !empty( $filters['until'] ) ) {
      $where[] = 'ts <= %s';
      $params[] = (string) $filters['until'];
    }
    if ( !empty( $filters['search'] ) ) {
      // A leading-wildcard LIKE cannot use an index, so every column named here is read
      // in full for every row considered. target and detail are bounded at 191 and 1000
      // bytes; args and changes are longtext, 64000 and 16000 bytes to a row against a
      // 50MB budget across the table. Searching all four therefore costs two orders of
      // magnitude more than searching the two small ones, on a screen where most searches
      // are looking for a post id or a phrase from a refusal.
      //
      // So the default is the two small columns, and deep puts the other two back rather
      // than removing the capability. Searching changes is how "what touched post 12"
      // gets an answer: a bulk call records one target and a dozen changed objects, and
      // the target column only ever names the first. That question still has an answer.
      // It now has to be asked for, and the screen has to offer the asking.
      $columns = [ 'target', 'detail' ];
      if ( !empty( $filters['deep'] ) ) {
        array_push( $columns, 'args', 'changes' );
      }
      $like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
      $clauses = [];
      foreach ( $columns as $column ) {
        $clauses[] = "{$column} LIKE %s";
        $params[] = $like;
      }
      $where[] = '(' . implode( ' OR ', $clauses ) . ')';
    }

    return [ implode( ' AND ', $where ), $params ];
  }

  /**
  * @param array $filters tool, actor, outcome, since, until, search, deep, limit, offset
  */
  public static function query( array $filters = [] ): array {
    global $wpdb;
    [ $where, $params ] = self::where( $filters );

    $limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 50 ) ) );
    $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

    // Allowlisted, because an order-by column cannot be a bound parameter and this one
    // arrives from a query string. "when" maps to id rather than ts: they agree on order,
    // ts has second resolution so a burst of calls sorts arbitrarily within a second, and
    // id is unique and already the primary key.
    $columns = [ 'when' => 'id', 'id' => 'id', 'tool' => 'tool', 'outcome' => 'outcome', 'ms' => 'ms' ];
    $by = $columns[ (string) ( $filters['orderby'] ?? '' ) ] ?? 'id';
    $dir = strtolower( (string) ( $filters['order'] ?? '' ) ) === 'asc' ? 'ASC' : 'DESC';
    // Ties broken by id so a page boundary cannot show the same row twice or skip one.
    $order = $by === 'id' ? "id {$dir}" : "{$by} {$dir}, id DESC";

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant, $where/$order are built from whitelisted columns
    $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where
      . ' ORDER BY ' . $order . ' LIMIT %d OFFSET %d';
    array_push( $params, $limit, $offset );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from a plugin constant table name and whitelisted columns
    return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
  }

  /**
  * How much of an export may be held in memory at once, as payload bytes.
  *
  * Not a row count, because rows are not a fixed size. This is the number that decides
  * how many of them actually come back. @see export_rows().
  */
  const EXPORT_MAX_BYTES = 20000000;

  /** Rows per query while exporting, so one result set cannot be the thing that breaks. */
  const EXPORT_CHUNK = 500;

  /**
  * The rows the current filters select, newest first, for a file the reader keeps.
  *
  * It builds its WHERE with where(), the same one the list and the count use, so an
  * export cannot select a different set from the screen that offered it. A second filter
  * builder would drift, and the first time it did, the export would quietly disagree with
  * the page it claims to be a copy of while looking exactly as authoritative.
  *
  * IT CAN RETURN FEWER ROWS THAN MATCH, AND THE CALLER MUST SAY SO. $limit is a row
  * ceiling, and rows have no fixed size: args alone is capped at MAX_ARGS bytes each, so
  * the default 50,000 rows is up to three gigabytes and no PHP process will hold it.
  * Pretending otherwise would mean an export that dies half-written, which on this screen
  * is worse than a short one. So the walk also stops at EXPORT_MAX_BYTES of payload.
  *
  * Because rows come newest first, stopping always cuts the oldest end: what comes back
  * is the newest N that fit, never a hole in the middle. Compare the returned count
  * against count( $filters ) to find out whether it happened, and tell the reader when it
  * did. A truncated export that looks complete is the failure worth avoiding here.
  *
  * The real ceiling depends on what was logged. At the byte budget above, an ordinary log
  * of a few hundred bytes a row exports the whole 50,000; a log full of maximal argument
  * blobs stops after roughly three hundred rows. Both are honest, and only the second
  * needs saying to the reader.
  *
  * The budget is set where it is because the payload is not what the export costs. Twenty
  * megabytes of columns measured a hundred and five megabytes of PHP, once the rows are
  * arrays of strings, which is most of the forty the front end is given and a good share
  * of the two hundred and fifty-six an administration screen raises itself to. Whatever
  * writes the file should write it a row at a time rather than build one string from all
  * of them, or it doubles that again for nothing.
  *
  * @param array $filters the same shape query() takes; limit, offset and orderby are
  *   ignored, since an export is always the whole match, newest first
  * @return array<int,array<string,mixed>> whole rows, detail, args, changes, prev_hash
  *   and hash included
  */
  public static function export_rows( array $filters, int $limit = 50000 ): array {
    global $wpdb;
    [ $where, $params ] = self::where( $filters );
    $limit = max( 1, min( self::MAX_ROWS, $limit ) );

    // Walked by id rather than by OFFSET: an offset makes the database count past every
    // row it already returned, so the last page of a large export costs the most.
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant, $where is built from whitelisted columns
    $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where
      . ' AND id < %d ORDER BY id DESC LIMIT %d';

    $rows = [];
    $bytes = 0;
    $before = PHP_INT_MAX;

    while ( count( $rows ) < $limit ) {
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from a plugin constant table name and whitelisted columns
      $chunk = $wpdb->get_results( $wpdb->prepare( $sql, array_merge(
        $params, [ $before, min( self::EXPORT_CHUNK, $limit - count( $rows ) ) ]
      ) ), ARRAY_A );
      if ( !$chunk ) {
        break;
      }
      foreach ( $chunk as $row ) {
        $before = (int) $row['id'];
        $rows[] = $row;
        $bytes += strlen( (string) $row['args'] ) + strlen( (string) $row['changes'] )
          + strlen( (string) $row['detail'] );
        if ( $bytes >= self::EXPORT_MAX_BYTES ) {
          return $rows;
        }
      }
    }

    return $rows;
  }

  /** How many rows match, so a pager can divide by a page size and be right. */
  public static function count( array $filters = [] ): int {
    global $wpdb;
    [ $where, $params ] = self::where( $filters );
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant, $where is built from whitelisted columns
    $sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where;
    return (int) ( $params
      ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) )
      : $wpdb->get_var( $sql ) );
    // phpcs:enable
  }

  /** One entry by id, or null. The detail view's whole source. */
  public static function get( int $id ): ?array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant
      'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id
    ), ARRAY_A );
    return $row ?: null;
  }

  /**
  * Whether one entry still matches its own hash.
  *
  * The chain verdict names an id, and until this existed the screen could not say
  * anything about that id: the reader was told entry 412 was where the chain broke and
  * had no way to look at 412. This answers for a single row, which is the question a
  * person actually has once they have the number.
  *
  * It checks the row against itself and against the link it claims. It cannot tell you
  * the chain is sound, because that is a property of the walk, not of one row.
  *
  * @return array{ok:bool,checked:bool,reason:string}
  */
  public static function verify_row( int $id ): array {
    $row = self::get( $id );
    if ( !$row ) {
      return [ 'ok' => false, 'checked' => false, 'reason' => 'no such entry' ];
    }
    if ( (string) $row['hash'] === '' ) {
      // Only the oldest rows can be unsigned. @see verify().
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant
      $earlier = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
        "SELECT COUNT(*) FROM " . self::table() . " WHERE id < %d AND hash <> ''", $id
      ) );
      if ( $earlier > 0 || (string) $row['prev_hash'] !== '' ) {
        return [ 'ok' => false, 'checked' => true,
          'reason' => 'this entry has no hash but follows entries that were signed, so its hash was removed' ];
      }
      return [ 'ok' => true, 'checked' => false,
        'reason' => 'carried over from before the log was chained, so it was never signed' ];
    }
    if ( self::hash( $row, (string) $row['prev_hash'], (int) $row['id'] <= self::hash_boundary() )
      !== (string) $row['hash'] ) {
      return [ 'ok' => false, 'checked' => true,
        'reason' => 'this entry does not match its own hash, so its contents changed after it was written' ];
    }
    return [ 'ok' => true, 'checked' => true, 'reason' => '' ];
  }

  /**
  * The tools and accounts that actually appear, for the filter menus.
  *
  * Drawn from the log rather than from the registry on purpose: a menu listing every
  * tool the plugin has is a menu of mostly empty results, and it would omit the entries
  * that matter most, namely calls to tools that have since been switched off.
  *
  * @return array{tools:string[],actors:array<int,string>}
  */
  public static function facets(): array {
    global $wpdb;
    $table = self::table();
    $tools = $wpdb->get_col( "SELECT DISTINCT tool FROM {$table} WHERE tool <> '' ORDER BY tool ASC" );
    $actors = [];
    foreach ( $wpdb->get_results(
      "SELECT DISTINCT actor, actor_name FROM {$table} WHERE actor > 0 ORDER BY actor_name ASC", ARRAY_A
    ) as $row ) {
      $actors[ (int) $row['actor'] ] = (string) $row['actor_name'];
    }
    return [ 'tools' => array_map( 'strval', $tools ?: [] ), 'actors' => $actors ];
  }

  /**
  * Payload bytes rather than the table's reported size, which lags and rounds.
  *
  * Each column is coalesced separately rather than the sum being coalesced once. Most
  * rows have no changes recorded, and adding LENGTH(changes) to the old expression would
  * have made the whole addition NULL for every one of them, so the byte bound would have
  * quietly measured only the handful of rows that changed something.
  */
  public static function bytes(): int {
    global $wpdb;
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant
    return (int) $wpdb->get_var(
      'SELECT COALESCE(SUM(COALESCE(LENGTH(args), 0) + COALESCE(LENGTH(changes), 0)'
      . ' + COALESCE(LENGTH(detail), 0)), 0) FROM ' . self::table()
    );
    // phpcs:enable
  }

  /**
  * How many rows the screen checks by default.
  *
  * Verifying the whole table means reading every recorded argument back out of the
  * database, which on a full table is tens of megabytes, so it is not something to do on
  * every page load. Recent rows are also where tampering matters: somebody hiding what
  * they did last night is the case this exists for.
  */
  const VERIFY_RECENT = 1000;

  /** Rows per query when walking. Bounds memory; the chain is carried across chunks. */
  const VERIFY_CHUNK = 500;

  /**
  * Recompute the chain.
  *
  * Walks forward in id order, since each row depends on the one before, and starts from
  * whatever the first row it sees claims as its predecessor. That is correct for a window
  * as well as for the whole table: a chain that has been pruned, or that is being checked
  * from the middle, legitimately begins pointing at something no longer present.
  *
  * A row deleted inside the walk is caught by that same link, since the row after it
  * carries a prev_hash that no longer matches what now precedes it. A row deleted at the
  * very start of the walk is not, because the first row's claim about its predecessor is
  * what the walk adopts, and there is nothing left to contradict it.
  *
  * Scope matters and is reported rather than assumed. This used to read the oldest 5,000
  * rows of a table allowed to hold 50,000, and then say "the chain is intact across 5,000
  * entries", which is true and reads as coverage. It meant that tampering with anything
  * recent was never examined, and that a green line on the settings screen said least
  * about the period somebody would most want to check.
  *
  * @param string $scope 'recent' for the newest VERIFY_RECENT rows, 'all' for everything.
  * @return array{ok:bool,checked:int,imported:int,total:int,complete:bool,scope:string,broken_at:?int,reason:string}
  */
  public static function verify( string $scope = 'recent' ): array {
    global $wpdb;
    $table = self::table();
    $total = self::count();

    // For a window, find where it starts and walk forward from there. Walking backwards
    // would mean holding the whole window to reverse it, which is what the chunking is
    // here to avoid.
    $from = 0;
    if ( $scope !== 'all' && $total > self::VERIFY_RECENT ) {
      $from = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::VERIFY_RECENT - 1
      ) );
    }

    // Rows at or below this were signed by the older construction. @see hash().
    $boundary = self::hash_boundary();

    $prev = '';
    $checked = 0;
    $imported = 0;
    $signed_seen = false;
    $after = $from > 0 ? $from - 1 : 0;

    while ( true ) {
      $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after, self::VERIFY_CHUNK
      ), ARRAY_A );
      if ( !$rows ) {
        break;
      }
      foreach ( $rows as $row ) {
        $after = (int) $row['id'];

        // Rows carried over from the option-based version have no hash: they were never
        // part of a chain and cannot retroactively join one. Counted and reported, not
        // treated as a break, or every upgraded site would be told on day one that its
        // audit log had been tampered with.
        //
        // But only as a prefix. Such rows exist because the import ran against an empty
        // table, so they are the oldest rows there are, with no predecessor. An empty hash
        // after a signed row is an edit: blanking the newest rows used to leave nothing
        // after them to contradict it, and the walk reported the log intact. The id
        // boundary is not the test, because it is recorded before the import runs and so
        // sits below the imported rows on a fresh table.
        if ( (string) $row['hash'] === '' ) {
          if ( $signed_seen || (string) $row['prev_hash'] !== '' ) {
            return self::verdict( false, $checked, $imported, $total, $scope, (int) $row['id'],
              'this row has no hash but follows rows that were signed, so its hash was removed' );
          }
          $imported++;
          continue;
        }
        $signed_seen = true;
        if ( $checked === 0 ) {
          $prev = (string) $row['prev_hash'];
        }
        if ( (string) $row['prev_hash'] !== $prev ) {
          return self::verdict( false, $checked, $imported, $total, $scope, (int) $row['id'],
            'a row is missing before this one, or its link was rewritten' );
        }
        if ( self::hash( $row, $prev, (int) $row['id'] <= $boundary ) !== (string) $row['hash'] ) {
          return self::verdict( false, $checked, $imported, $total, $scope, (int) $row['id'],
            'this row does not match its own hash, so its contents changed after it was written' );
        }
        $prev = (string) $row['hash'];
        $checked++;
      }
      unset( $rows );
    }

    return self::verdict( true, $checked, $imported, $total, $scope, null, '' );
  }

  private static function verdict( bool $ok, int $checked, int $imported, int $total,
    string $scope, ?int $broken, string $reason ): array {
    return [
      'ok' => $ok,
      'checked' => $checked,
      'imported' => $imported,
      'total' => $total,
      'scope' => $scope,
      // Whether every row in the table was looked at. A caller that only knows "intact"
      // cannot tell a fully verified log from a tenth of one, which is the mistake the
      // previous wording invited.
      'complete' => ( $checked + $imported ) >= $total,
      'broken_at' => $broken,
      'reason' => $reason,
    ];
  }

  /** Where the last full walk's verdict is kept. Not autoloaded; read only by the screen. */
  const LAST_FULL_OPTION = 'gmcp_audit_last_full_verify';

  /**
  * Keep a full check's verdict, because the answer outlives the page it was asked on.
  *
  * The button posts, redirects and prints one notice, so the verdict was gone by the
  * next load and the screen could only ever show the window check. What is stored is a
  * statement about a moment: when the walk ran, what it found, and how large the table
  * was, so a later read can say whether it still describes the table in front of it.
  *
  * It does not stand in for the check and must never be rendered as current state. A row
  * edited in place changes neither the count nor the highest id, so nothing recorded here
  * can notice that; only walking again can. Hence the timestamp travels with the verdict
  * everywhere it is shown.
  */
  public static function remember_full_check( array $verdict ): void {
    global $wpdb;
    update_option( self::LAST_FULL_OPTION, [
      'ok' => (bool) $verdict['ok'],
      'checked' => (int) $verdict['checked'],
      'imported' => (int) $verdict['imported'],
      'total' => (int) $verdict['total'],
      'broken_at' => $verdict['broken_at'] === null ? null : (int) $verdict['broken_at'],
      'reason' => (string) $verdict['reason'],
      'ran_at' => gmdate( 'Y-m-d H:i:s' ),
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant
      'high_water' => (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::table() ),
    ], false );
  }

  /**
  * That verdict, with whether the table has moved under it since.
  *
  * `current` is the narrow claim that no row has been added and none removed: the count
  * and the highest id are both where the walk left them. It is not a claim that the log
  * is still intact, which is why the caller is given the date as well and not this flag
  * alone.
  *
  * @return array{ok:bool,checked:int,imported:int,total:int,broken_at:?int,reason:string,ran_at:string,high_water:int,current:bool}|null
  */
  public static function last_full_check(): ?array {
    global $wpdb;
    $last = get_option( self::LAST_FULL_OPTION, null );
    if ( !is_array( $last ) || empty( $last['ran_at'] ) ) {
      return null;
    }
    $last['current'] = (int) $last['total'] === self::count()
      // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant
      && (int) $last['high_water'] === (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::table() );
    return $last;
  }

  #endregion

  #region Pruning

  public static function retention_days(): int {
    $core = $GLOBALS['gmcp_core'] ?? null;
    $days = $core ? (int) $core->get_option( 'mcp_audit_days', self::DEFAULT_DAYS ) : self::DEFAULT_DAYS;
    return max( 1, min( 3650, $days ?: self::DEFAULT_DAYS ) );
  }

  /**
  * Apply the bounds.
  *
  * AGE is the only bound that deletes rows, and it deletes exactly the rows the retention
  * setting says are past keeping. The row and byte bounds exist to stop the table growing
  * without limit, but they must never be the thing that removes a recent, genuine entry.
  *
  * They could, until this was fixed: both bounds called drop_oldest(), so exceeding either
  * deleted the oldest rows whatever their age. Any key can add rows — a refused call is
  * recorded, and mcp_ping is reachable from a key scoped to it — so a flood pushed the
  * table over a bound and the next cron run deleted real history from the old end, while
  * verify() still reported the chain intact because a loss at the oldest end is invisible
  * to a walk that starts at the boundary. README says nothing exposed through MCP can prune
  * or clear the log; this is the path that made that untrue.
  *
  * So the row and byte bounds no longer delete anything. When one is reached the log stops
  * recording, says so (stopped(), the admin notice, Site Health, the log screen, the error
  * log), and the plugin refuses every tool above read level until there is room, so
  * nothing happens that the log cannot show. That trades a bounded loss of detail at the
  * NEW end for an unbounded loss of evidence at the old one, which is the right way round.
  * Two things keep that trade cheap: a refused call is stored with at most
  * MAX_REFUSED_ARGS of arguments, so a flood of them is expensive to mount, and the bounds
  * are well past what a site records in its retention window.
  *
  * @return array{age:int,stopped:string} rows removed by age, and why recording stopped
  */
  public static function prune(): array {
    global $wpdb;
    $table = self::table();
    $removed = [ 'age' => 0, 'stopped' => '' ];

    $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::retention_days() * DAY_IN_SECONDS ) );
    $removed['age'] = (int) $wpdb->query(
      $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", $cutoff )
    );

    // Measured afresh, not from the cache, and the marker follows what is found: set when
    // still over a bound after the retention delete, cleared once the site is back under
    // them. Recording resumes by itself the moment the numbers allow it.
    self::forget_usage();
    $removed['stopped'] = self::over_bound_reason();
    return $removed;
  }

  /**
  * The row bound. Filterable, because it is not a setting: a site that genuinely records
  * more than this inside its retention window can raise it, knowing the table grows with it.
  */
  public static function max_rows(): int {
    return max( 1, (int) apply_filters( 'gmcp_audit_max_rows', self::MAX_ROWS ) );
  }

  /** The byte bound. @see max_rows(). */
  public static function max_bytes(): int {
    return max( 1, (int) apply_filters( 'gmcp_audit_max_bytes', self::MAX_BYTES ) );
  }

  /** Where the last measurement of the table is kept, so a call does not scan it. */
  const USAGE_TRANSIENT = 'gmcp_audit_usage';

  /** @var array|null the measurement for this request. */
  private static $usage = null;

  /** Drop the remembered measurement, so the next question measures the table. */
  public static function forget_usage(): void {
    self::$usage = null;
    delete_transient( self::USAGE_TRANSIENT );
  }

  /**
  * How full the table is: rows and payload bytes.
  *
  * Asked before every tool call, and the byte figure is a SUM(LENGTH()) over longtext
  * columns, which reads every row's off-page data: tens of megabytes near the bound, per
  * call, reads included. So the answer is kept for a short while. Far from a bound it is
  * kept for two minutes, since the table cannot move far in that time; within a tenth of
  * one it is kept for five seconds, so the overshoot past a bound stays small. A measurement
  * taken fresh is also where the stopped marker is brought into line with the numbers.
  *
  * @return array{rows:int,bytes:int}
  */
  private static function usage(): array {
    if ( self::$usage !== null ) {
      return self::$usage;
    }
    $cached = get_transient( self::USAGE_TRANSIENT );
    if ( is_array( $cached ) && isset( $cached['rows'], $cached['bytes'] ) ) {
      return self::$usage = [ 'rows' => (int) $cached['rows'], 'bytes' => (int) $cached['bytes'] ];
    }
    $usage = [ 'rows' => self::count(), 'bytes' => self::bytes() ];
    $near = $usage['rows'] >= 0.9 * self::max_rows() || $usage['bytes'] >= 0.9 * self::max_bytes();
    set_transient( self::USAGE_TRANSIENT, $usage, $near ? 5 : 2 * MINUTE_IN_SECONDS );
    self::$usage = $usage;
    self::sync_stopped( self::reason_for( $usage ) );
    return $usage;
  }

  private static function reason_for( array $usage ): string {
    if ( $usage['rows'] >= self::max_rows() ) {
      return 'rows';
    }
    if ( $usage['bytes'] >= self::max_bytes() ) {
      return 'bytes';
    }
    return '';
  }

  /**
  * Bring the stopped marker in line with what was measured.
  *
  * The marker used to be set only by prune(), which runs daily or on a button, so a log
  * could be dead for a day with nothing saying so. Set here the first time the log is
  * found full, with one line in the error log, and cleared as soon as it is not.
  */
  private static function sync_stopped( string $reason ): void {
    $state = get_option( self::STOPPED_OPTION, null );
    $is_set = is_array( $state ) && !empty( $state['at'] );
    if ( $reason === '' ) {
      if ( $state !== null ) {
        delete_option( self::STOPPED_OPTION );
      }
      return;
    }
    if ( !$is_set || ( $state['why'] ?? '' ) !== $reason ) {
      update_option( self::STOPPED_OPTION, [ 'at' => $is_set ? (int) $state['at'] : time(), 'why' => $reason ], false );
    }
    if ( !$is_set ) {
      error_log( '[Guarded MCP] the audit log is full (' . ( $reason === 'bytes' ? 'size' : 'entry-count' )
        . ' limit) and has stopped recording. Tools above read level are refused until it has room. '
        . 'Rows past the retention window are removed daily; the limits can be changed with the '
        . 'gmcp_audit_max_rows and gmcp_audit_max_bytes filters.' );
    }
  }

  /**
  * Whether the log is at or past a hard bound, and which one.
  *
  * Counted rather than estimated, but not on every call. @see usage().
  */
  public static function over_bound(): bool {
    return self::over_bound_reason() !== '';
  }

  private static function over_bound_reason(): string {
    return self::reason_for( self::usage() );
  }

  /**
  * Why recording is currently stopped, or null.
  *
  * Read by the settings screen, the admin notice, Site Health and the tools that report on
  * the log, so a missing recent entry can be told apart from one that never happened.
  * Shaped as a small array rather than a bool because when it stopped and at which bound
  * both matter to whoever has to decide whether to raise the limit or lower the retention.
  */
  public static function stopped(): ?array {
    // Asked of the numbers, which also brings the marker up to date when they are fresh.
    // The marker alone can be stale (the retention delete may have made room since) or
    // missing, and neither should change the answer.
    if ( !self::over_bound() ) {
      return null;
    }
    $state = get_option( self::STOPPED_OPTION, null );
    $usage = self::usage();
    return [
      'since' => gmdate( 'c', is_array( $state ) && !empty( $state['at'] ) ? (int) $state['at'] : time() ),
      'bound' => self::over_bound_reason(),
      'rows' => $usage['rows'],
      'max_rows' => self::max_rows(),
      'bytes' => $usage['bytes'],
      'max_bytes' => self::max_bytes(),
    ];
  }

  /**
  * The access level of every tool offered on this request, learned from the registry.
  *
  * Collected at the end of the gmcp_tools filter, after every group has added its own, so
  * the refusal below can tell a read from a write without reaching into the server's
  * private tables. @see refuse_while_stopped().
  *
  * @var array<string,string>
  */
  private static $levels = [];

  public static function remember_levels( $tools ) {
    if ( is_array( $tools ) ) {
      foreach ( $tools as $tool ) {
        if ( is_array( $tool ) && isset( $tool['name'] ) ) {
          self::$levels[ (string) $tool['name'] ] = (string) ( $tool['accessLevel'] ?? 'admin' );
        }
      }
    }
    return $tools;
  }

  /**
  * While the log is full, refuse every tool that is not a read.
  *
  * Stopping the recording alone would let whoever filled the log act freely afterwards,
  * with nothing written down: the cheapest way to switch an audit log off is to fill it.
  * So the plugin fails closed. Reads still answer, which is how the operator finds out and
  * how an agent can report the problem; everything that writes is refused, with the reason.
  * A tool this request has no level for counts as not a read, except when the registry was
  * never consulted at all (a resource read, which only ever borrows read tools).
  *
  * Hooked ahead of every tool group on gmcp_callback, so nothing else runs first. The
  * refusal is itself a refused call, and is not recorded for the same reason.
  */
  public static function refuse_while_stopped( $result, $tool = '', $args = [], $id = 0 ) {
    if ( $result !== null || !is_string( $tool ) || $tool === '' ) {
      return $result;
    }
    $level = self::$levels[ $tool ] ?? ( self::$levels ? 'admin' : 'read' );
    if ( $level === 'read' || !self::over_bound() ) {
      return $result;
    }
    $why = self::over_bound_reason() === 'bytes' ? 'size' : 'entry-count';
    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => [
        'content' => [ [ 'type' => 'text', 'text' =>
          "Refused: the audit log has reached its {$why} limit and has stopped recording, and this plugin does not "
          . "allow changes it cannot record. Read-only tools still work. A site administrator can free room by "
          . "waiting for entries to pass the retention window or lowering it, then using Prune now on the Audit "
          . "log screen, or raise the limit with the gmcp_audit_max_rows / gmcp_audit_max_bytes filters." ] ],
        'isError' => true,
      ],
    ];
  }

  /** The notice on every admin screen, for the people who can do something about it. */
  public static function admin_notice(): void {
    if ( !current_user_can( 'manage_options' ) ) {
      return;
    }
    $state = self::stopped();
    if ( !$state ) {
      return;
    }
    printf(
      '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
      esc_html__( 'Guarded MCP: the audit log is full and has stopped recording.', 'guarded-mcp' ),
      esc_html__( 'Tools that change the site are refused until it has room. Reads still work.', 'guarded-mcp' ),
      esc_url( GMCP_Settings::page_url( 'logs' ) ),
      esc_html__( 'Open the audit log', 'guarded-mcp' )
    );
  }

  /** @param array $tests */
  public static function register_site_health( $tests ) {
    $tests['direct']['gmcp_audit_log'] = [
      'label' => __( 'MCP audit log', 'guarded-mcp' ),
      'test' => [ __CLASS__, 'site_health_test' ],
    ];
    return $tests;
  }

  public static function site_health_test(): array {
    $state = self::stopped();
    $usage = self::usage();
    $near = $usage['rows'] >= 0.9 * self::max_rows() || $usage['bytes'] >= 0.9 * self::max_bytes();
    $result = [
      'label' => __( 'The MCP audit log has room', 'guarded-mcp' ),
      'status' => 'good',
      'badge' => [ 'label' => __( 'MCP', 'guarded-mcp' ), 'color' => 'blue' ],
      'description' => '<p>' . esc_html__( 'Entries are being recorded.', 'guarded-mcp' ) . '</p>',
      'actions' => '',
      'test' => 'gmcp_audit_log',
    ];
    if ( $state ) {
      $result['label'] = __( 'The MCP audit log is full and has stopped recording', 'guarded-mcp' );
      $result['status'] = 'critical';
      $result['badge']['color'] = 'red';
      $result['description'] = '<p>' . esc_html__( 'Tools that change the site are refused until the log has room, because the plugin does not allow changes it cannot record. Entries past the retention window are removed daily. The limits can be changed with the gmcp_audit_max_rows and gmcp_audit_max_bytes filters.', 'guarded-mcp' ) . '</p>';
    }
    elseif ( $near ) {
      $result['label'] = __( 'The MCP audit log is nearly full', 'guarded-mcp' );
      $result['status'] = 'recommended';
      $result['badge']['color'] = 'orange';
      $result['description'] = '<p>' . esc_html__( 'The log is within a tenth of its entry or size limit. When it is reached, recording stops and tools that change the site are refused.', 'guarded-mcp' ) . '</p>';
    }
    if ( $state || $near ) {
      $result['actions'] = sprintf( '<p><a href="%s">%s</a></p>',
        esc_url( GMCP_Settings::page_url( 'logs' ) ), esc_html__( 'Open the audit log', 'guarded-mcp' ) );
    }
    return $result;
  }

  public static function clear(): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant; TRUNCATE takes no parameters
    $wpdb->query( 'TRUNCATE TABLE ' . self::table() );
    // TRUNCATE resets the auto-increment, so the next row written takes an id that used
    // to belong to a row signed by the older construction. Leaving the boundary where it
    // was would have verify() check brand new rows the old way and report every one of
    // them as tampered with. Nothing older survives a clear, so nothing needs the old
    // construction any more.
    update_option( self::BOUNDARY_OPTION, 0, false );
    // Room again, so the remembered measurement and the stopped marker both describe a
    // table that no longer exists.
    self::forget_usage();
    delete_option( self::STOPPED_OPTION );
    // The remembered full check describes rows that no longer exist. Kept, it would sit
    // under an empty log quoting a count from before the clear.
    delete_option( self::LAST_FULL_OPTION );
  }

  #endregion

  /**
  * Move whatever the option-based version recorded into the table.
  *
  * Runs once. The old rows carried no arguments and no actor, so those columns stay
  * empty rather than being invented, and the detail says where the row came from. An
  * imported row is outside the hash chain by definition, which is why it says so.
  */
  public static function adopt_activity_option(): void {
    global $wpdb;

    $old = get_option( 'gmcp_activity', null );

    // Whatever happens below, these rows are retired. Deleting them only on the import
    // path left them behind on every reactivation of a site whose table already had
    // rows, so the option came back each time and nothing ever removed it.
    $forget = function () {
      delete_option( 'gmcp_activity' );
    };

    if ( !is_array( $old ) || !$old ) {
      $forget();
      return;
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is a plugin constant
    if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ) > 0 ) {
      $forget();
      return;
    }

    foreach ( $old as $entry ) {
      if ( empty( $entry['tool'] ) ) {
        continue;
      }
      $wpdb->insert( self::table(), [
        'ts' => gmdate( 'Y-m-d H:i:s', (int) ( $entry['t'] ?? time() ) ),
        'tool' => mb_substr( (string) $entry['tool'], 0, 64 ),
        'target' => mb_substr( (string) ( $entry['target'] ?? '' ), 0, 191 ),
        'outcome' => empty( $entry['ok'] ) ? 'refused' : 'ok',
        'ms' => (int) ( $entry['ms'] ?? 0 ),
        'client' => mb_substr( (string) ( $entry['who'] ?? '' ), 0, 191 ),
        'args' => null,
        'detail' => mb_substr( (string) ( $entry['err'] ?? '' ), 0, 1000 ),
        'prev_hash' => '',
        'hash' => '',
      ] );
    }
    $forget();
  }
}
