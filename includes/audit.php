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
* PRUNING IS BOUNDED THREE WAYS. Age alone lets a runaway agent fill a disk in a day.
* A row cap alone lets one enormous entry do it. A byte cap alone throws away last week
* because of something that happened last year. So all three, and whichever is hit first
* wins.
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
  */
  const DB_VERSION = '2';
  const CRON_HOOK = 'gmcp_audit_prune';

  /** The last row written under the old hash construction. @see hash_boundary(). */
  const BOUNDARY_OPTION = 'gmcp_audit_hash_boundary';

  /** Per-entry cap on the recorded arguments, before the row is written. */
  const MAX_ARGS = 64000;

  /**
  * And on the recorded changes, which share the entry with them.
  *
  * Smaller than the argument cap on purpose. A summary is meant to be read, and forty
  * field diffs nobody reads still cost ninety days of disk under the retention bounds.
  */
  const MAX_CHANGES = 16000;

  /** Defaults, overridable from the settings screen. */
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
      KEY actor (actor)
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
  * ID. post_title is last because it is the only identifier a create has.
  */
  private function target( array $args ): string {
    foreach ( [ 'plugin', 'stylesheet', 'ID', 'post_id', 'item_id', 'widget_id', 'menu',
      'key', 'sidebar', 'user_id', 'comment_ID', 'term_id', 'id', 'slug', 'name', 'post_title' ] as $key ) {
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
  private function storable_args( array $args ): string {
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
    $json = wp_json_encode( $redacted, JSON_UNESCAPED_SLASHES );
    if ( $json === false ) {
      return '{"__gmcp":"arguments could not be encoded"}';
    }
    if ( strlen( $json ) > self::MAX_ARGS ) {
      // Truncated rather than dropped, and the original size recorded, so a reader can
      // tell a large call from a missing one.
      return wp_json_encode( [
        '__gmcp_truncated' => strlen( $json ),
        'preview' => mb_substr( $json, 0, 2000 ),
      ], JSON_UNESCAPED_SLASHES );
    }
    return $json;
  }

  public function record( $call ): void {
    if ( !is_array( $call ) || empty( $call['tool'] ) ) {
      return;
    }
    global $wpdb;

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
      'target' => $this->target( $args ),
      'outcome' => $failed ? 'refused' : 'ok',
      'ms' => (int) ( $call['duration_ms'] ?? 0 ),
      'args' => $this->storable_args( $args ),
      'changes' => class_exists( 'GMCP_Changes' )
        ? GMCP_Changes::summarise( GMCP_Changes::captured(), self::MAX_CHANGES )
        : null,
      'detail' => $this->detail( $call, $failed ),
    ];

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

    if ( $written === false ) {
      // A lost audit entry is the failure this table exists to prevent, and it is the
      // one failure that leaves no trace of itself: the next row chains to the one
      // before, so nothing downstream ever notices. The likeliest cause is a schema
      // older than the code, which the constructor tries to rule out. Say so loudly
      // rather than returning quietly, because the alternative is a log that is wrong
      // and looks intact.
      error_log( '[Guarded MCP] audit entry NOT recorded for ' . $row['tool'] . ': '
        . ( $wpdb->last_error ?: 'the database reported no error' ) );
    }
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
    return mb_substr( wp_strip_all_tags( $text ), 0, 1000 );
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
  * @param array $filters tool, actor, outcome, since, until, search, limit, offset
  */
  public static function query( array $filters = [] ): array {
    global $wpdb;
    $table = self::table();
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
      // Changes are searched too, which is how "what touched post 12" gets an answer: a
      // bulk call records one target and a dozen changed objects, and the target column
      // only ever names the first.
      $where[] = '(target LIKE %s OR detail LIKE %s OR args LIKE %s OR changes LIKE %s)';
      $like = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
      array_push( $params, $like, $like, $like, $like );
    }

    $limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 50 ) ) );
    $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

    $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where )
      . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    array_push( $params, $limit, $offset );

    return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];
  }

  public static function count( array $filters = [] ): int {
    global $wpdb;
    return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
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
    return (int) $wpdb->get_var(
      'SELECT COALESCE(SUM(COALESCE(LENGTH(args), 0) + COALESCE(LENGTH(changes), 0)'
      . ' + COALESCE(LENGTH(detail), 0)), 0) FROM ' . self::table()
    );
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
        if ( (string) $row['hash'] === '' ) {
          $imported++;
          continue;
        }
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

  #endregion

  #region Pruning

  public static function retention_days(): int {
    $core = $GLOBALS['gmcp_core'] ?? null;
    $days = $core ? (int) $core->get_option( 'mcp_audit_days', self::DEFAULT_DAYS ) : self::DEFAULT_DAYS;
    return max( 1, min( 3650, $days ?: self::DEFAULT_DAYS ) );
  }

  /**
  * Apply all three bounds, oldest first.
  *
  * @return array{age:int,rows:int,bytes:int} how many were removed by each bound
  */
  public static function prune(): array {
    global $wpdb;
    $table = self::table();
    $removed = [ 'age' => 0, 'rows' => 0, 'bytes' => 0 ];

    $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::retention_days() * DAY_IN_SECONDS ) );
    $removed['age'] = (int) $wpdb->query(
      $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %s", $cutoff )
    );

    $count = self::count();
    if ( $count > self::MAX_ROWS ) {
      $removed['rows'] = self::drop_oldest( $count - self::MAX_ROWS );
    }

    // Bytes last, because dropping by age or count may already have solved it, and this
    // is the bound most likely to remove something recent.
    $guard = 0;
    while ( self::bytes() > self::MAX_BYTES && self::count() > 1 && $guard < 200 ) {
      $removed['bytes'] += self::drop_oldest( max( 100, (int) ( self::count() * 0.05 ) ) );
      $guard++;
    }

    return $removed;
  }

  private static function drop_oldest( int $howMany ): int {
    global $wpdb;
    $table = self::table();
    return (int) $wpdb->query( $wpdb->prepare(
      "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", max( 1, $howMany )
    ) );
  }

  public static function clear(): void {
    global $wpdb;
    $wpdb->query( 'TRUNCATE TABLE ' . self::table() );
    // TRUNCATE resets the auto-increment, so the next row written takes an id that used
    // to belong to a row signed by the older construction. Leaving the boundary where it
    // was would have verify() check brand new rows the old way and report every one of
    // them as tampered with. Nothing older survives a clear, so nothing needs the old
    // construction any more.
    update_option( self::BOUNDARY_OPTION, 0, false );
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

    // Both names, because a site upgrading from the plugin's previous name may still
    // carry the older one and nothing else looks at it any more.
    $old = get_option( 'gmcp_activity', null );
    if ( !is_array( $old ) || !$old ) {
      $old = get_option( 'reeve_activity', null );
    }

    // Whatever happens below, these rows are retired. Deleting them only on the import
    // path left them behind on every reactivation of a site whose table already had
    // rows, so the option came back each time and nothing ever removed it.
    $forget = function () {
      delete_option( 'gmcp_activity' );
      delete_option( 'reeve_activity' );
    };

    if ( !is_array( $old ) || !$old ) {
      $forget();
      return;
    }
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
