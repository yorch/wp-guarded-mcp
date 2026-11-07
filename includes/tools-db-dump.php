<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The database itself: a manifest of its tables, and — only after a person says so — one dump.
*
* WHY THIS IS ITS OWN GROUP AND OFF BY DEFAULT. A dump of the database is every post body, every
* option row, every user, and the private message a customer typed into a form. It leaves the
* server whole, in one file, and nothing in it can be redacted the way a reply can: no field-name
* rule finds a visitor's address inside a gzipped INSERT statement. So the capability has to be a
* decision the owner of the site makes, not one an update makes for them, and it is off until that
* switch is turned on.
*
* WHAT A MANIFEST IS. Three scopes, and which tables each covers is spelled out by
* {@see self::table_names()} rather than guessed here:
*
*   site      the current site's own tables, with another blog's tables and the network's
*             excluded on a multisite. On a single install this is the whole WordPress database.
*   network   every table named with the install's base prefix: all blogs plus the network rows.
*   database  every base table in the configured database, including tables that are not
*             WordPress's at all.
*
* A manifest is the table count, the exact table list, a row count estimate and a size per table,
* and a digest. It returns no row data, no credentials and no SQL. It says plainly that the row
* counts are estimates `SHOW TABLE STATUS` reports, not a count of rows.
*
* WHAT THE DIGEST COVERS, AND WHY NOT THE NUMBERS. The digest is over the scope and the sorted
* table names. The row estimates and sizes are deliberately NOT in it: on InnoDB they are
* statistics that move on ordinary writes, so a digest built from them would stop matching while a
* person was reading the approval screen and every pull would be refused for a reason nobody could
* act on. What a person approves is the SET OF TABLES, and a table that appears or disappears
* between the manifest and the pull changes the digest and is refused. Row counts on the screen
* are what that set holds now, and the screen says so.
*
* WHAT A PULL IS. Two gates, and neither is the other's substitute:
*
*   1. The call always waits in the approvals queue, in every `mcp_approval_mode` including
*      `never`. The tool registration declares `requiresApproval` for `mode=pull`.
*   2. The approval is bound to the arguments, which include the scope and the manifest digest, and
*      the pull recomputes the manifest and refuses if the present digest is not the one passed.
*      An agent cannot be approved for one set of tables and then dump another.
*
* WHAT BUILDS IT, AND WHAT IT SAYS ABOUT ITSELF. A maintained dump utility is preferred, at a
* pinned absolute path ({@see self::UTILITY_PATHS}), because a hand-written dumper is a worse
* dumper: it will be missing something. It is run with an argv ARRAY through proc_open — never a
* command string — with no flag the caller can influence, and its credentials go through a private
* `--defaults-extra-file` at mode 0600 rather than argv or an environment variable. Where no such
* utility exists at a pinned path (a container built without the client tools has neither), the
* fallback in {@see self::php_dump()} writes the same artifact from `SHOW CREATE TABLE` and
* retrieved rows. That is a visible downgrade, not a silent one: the manifest names the engine a
* pull would use (`dump_engine`), the pull's reply names the engine that ran, and
* docs/guardrails.md says what the fallback does not write (triggers, routines, events, views).
*
* A PARTIAL FILE IS NEVER A SUCCESS. After the dump the artifact is read back through gzip, and it
* must be non-empty, must decompress, must hold a `CREATE TABLE` for every table in the approved
* list, and must end with the dump tool's own `-- Dump completed` trailer. Any of those failing
* means nothing is kept and the reply says the dump was not built.
*
* WHERE IT SITS. The artifact is a temporary file outside every web-served root (the same
* {@see GMCP_Tools_Export::temp_dir()} answer the archive uses), mode 0600, streamed once by the
* REST endpoint in server.php and removed after it is sent, on expiry, and on every failure path —
* a shutdown callback is registered before a byte is written, because the PHP time limit on a large
* dump ends the request where none of the unlinks below run.
*
* WHAT IT INCLUDES, BY THE OWNER'S DECISION. The dump includes this plugin's own activity log
* (`*_gmcp_audit`) and the approval queue (`*_gmcp_approvals`). That is a named exception to two
* rules this plugin keeps everywhere else — the audit log has no bulk export, and no tool reads,
* lists, answers or edits the approval queue — and it is recorded as an exception in AGENTS.md and
* docs/guardrails.md rather than left for a reader to notice. The reasoning is that a dump is
* either the database or it is a lie about the database: a table list that quietly omits the two
* tables whose whole purpose is to say what happened would hand a person a backup that restores
* into a site with no history and no way to tell. No tool reads either table into a reply. This
* one hands out one gzipped artifact, through a queue a person answers, and its reply carries a
* count and a digest.
*
* WHAT DELIBERATELY IS NOT HERE. No tool reads rows, runs a query, or edits the database, at any
* level: this group answers "what tables are there" and "hand me the dump a person approved", and
* nothing else. There is no restore tool, and none of the scopes takes a table name from the
* caller.
*/
class GMCP_Tools_Db_Dump {

  /** How long a minted dump URL lives. */
  const TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

  /** The option row that holds one unspent token. Its name is credential-shaped on purpose. */
  const TOKEN_OPTION_PREFIX = 'gmcp_db_dump_token_';

  /** Temporary files all start with this, so a sweep can find one left by an old row. */
  const TEMP_PREFIX = 'gmcp-dbdump-';
  const CRON_HOOK = 'gmcp_db_dump_cleanup';

  const SCOPES = [ 'site', 'network', 'database' ];

  /**
  * A database with more tables than this is refused rather than dumped.
  *
  * Every table costs a `CREATE TABLE` check in the verification and a line in the manifest, and a
  * reply that took minutes to assemble from a list nobody reads is not a useful manifest. Over the
  * cap the call refuses and says how far it got, rather than returning a truncated count.
  */
  const MAX_TABLES = 2000;

  /** Rows per INSERT statement and rows held in memory at once, in the PHP fallback. */
  const ROWS_PER_STATEMENT = 200;
  const READ_BATCH = 500;

  /** How long the dump utility may run before it is killed and the pull refuses. */
  const DUMP_TIMEOUT = 300;

  /**
  * Where a maintained dump utility is looked for, as absolute paths in order of preference.
  *
  * `mariadb-dump` first because this plugin speaks to MariaDB and MySQL through WordPress's own
  * mysqli driver, whose client is the one shipped alongside it. Nothing here searches `PATH` and
  * nothing takes a path from a caller: an argv[0] an agent can choose is a way to run a program of
  * its choosing with the site's database credentials. A site that must move one can filter it
  * ({@see self::utility_paths()}), and the test suite pins the fallback on a container that has
  * neither.
  */
  const UTILITY_PATHS = [ '/usr/bin/mariadb-dump', '/usr/bin/mysqldump' ];

  /** The last line a successful dump writes. Its presence is the completeness check. */
  const TRAILER = '-- Dump completed';

  const ENGINE_UTILITY = 'mariadb-dump/mysqldump (pinned path)';
  const ENGINE_PHP = 'php-fallback';

  public function __construct() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  /**
  * Register the expiry sweep even when the group is switched off.
  *
  * Registered from GMCP_Core::init(), so a token minted before somebody turned the group off still
  * has its dump removed when the scheduled event fires. Turning a capability off should not leave
  * a copy of the database on the disk.
  */
  public static function boot(): void {
    add_action( self::CRON_HOOK, [ __CLASS__, 'cleanup_expired' ] );
  }

  /** Whether the group's switch is on. The REST endpoint asks this too. */
  public static function enabled(): bool {
    $core = $GLOBALS['gmcp_core'] ?? null;
    return $core && (bool) $core->get_option( 'mcp_tools_db_dump' );
  }

  public function register_tools( $tools ) {
    return array_merge( is_array( $tools ) ? $tools : [], array_values( $this->tools() ) );
  }

  private function tools(): array {
    return [
      'wp_db_dump_request' => [
        'name' => 'wp_db_dump_request',
        'description' => 'Inspect this site\'s database as a manifest, or dump it to one gzipped file that only a person can approve. scope=site (the default) is the current site\'s own tables; scope=network is every table named with the install\'s base prefix, which on a multisite is all blogs plus the network rows; scope=database is every base table in the configured database, including tables that are not WordPress\'s. mode=manifest (the default) returns the table count, the exact table list, a row count ESTIMATE per table (SHOW TABLE STATUS statistics, not a count of rows), the total size, and a manifest_digest. It returns no rows, no credentials and no SQL. mode=pull takes the manifest_digest from a manifest for the same scope: it recomputes the manifest and refuses if the set of tables changed, then asks a signed-in administrator to approve THIS exact call on the Approvals screen. Until that person approves it, nothing is dumped and no token exists. The dump is built by a maintained dump utility at a pinned absolute path where the server has one, and otherwise by a PHP fallback that writes table structure and rows only; which one ran is named in the reply. It is checked for completeness (it must decompress, hold a CREATE TABLE for every approved table and end with a dump-completed trailer) and a partial dump is never reported as success. After approval, repeat the identical call and it returns a one-time URL, valid for five minutes, that streams the .sql.gz. The file is never returned in a tool reply, because replies are copied into the audit log. The dump includes every table in scope, which means this plugin\'s own activity log and approval queue are in it: that is a deliberate, documented exception. The pull leg always needs human approval, whatever the site\'s approval mode is set to. Admin level.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'scope' => [
              'type' => 'string',
              'enum' => self::SCOPES,
              'default' => 'site',
              'description' => 'site: this site\'s own tables. network: every table under the install\'s base prefix. database: every base table in the configured database.',
            ],
            'mode' => [
              'type' => 'string',
              'enum' => [ 'manifest', 'pull' ],
              'default' => 'manifest',
              'description' => 'manifest reports the tables without reading any row. pull dumps them, after human approval, and returns a one-time URL.',
            ],
            'manifest_digest' => [
              'type' => 'string',
              'description' => 'Required for mode=pull: the manifest_digest returned for exactly this scope. A changed set of tables is refused rather than dumped under an older approval.',
            ],
          ],
        ],
        'accessLevel' => 'admin',
        // Read by GMCP_Approvals::requires_human_approval(). The pull leg waits in every approval
        // mode; the manifest leg does not wait at all.
        'requiresApproval' => [ 'argument' => 'mode', 'values' => [ 'pull' ] ],
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || $tool !== 'wp_db_dump_request' ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];
    $mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'manifest';
    if ( !in_array( $mode, [ 'manifest', 'pull' ], true ) ) {
      return $this->error( $r, 'Unknown mode "' . $mode . '". Use manifest or pull.' );
    }
    $scope = isset( $args['scope'] ) ? (string) $args['scope'] : 'site';
    if ( !in_array( $scope, self::SCOPES, true ) ) {
      return $this->error( $r, 'Unknown scope "' . $scope . '". Use site, network or database.' );
    }

    // A dump left behind by an earlier run is cheap to remove here, and it is removed even when
    // this call is going to fail. The cron event and the REST endpoint sweep as well; this is the
    // path that runs most often.
    self::cleanup_expired();

    $manifest = self::manifest( $scope );
    if ( isset( $manifest['refused'] ) ) {
      return $this->error( $r, $manifest['message'] );
    }
    if ( $mode === 'manifest' ) {
      return $this->json( $r, $manifest );
    }
    return $this->pull( $args, $manifest, $r );
  }

  #region The manifest

  /**
  * Describe the tables a scope covers, without reading a row.
  *
  * @return array{refused:true,message:string}|array The manifest, or the refusal to return.
  */
  public static function manifest( string $scope ): array {
    global $wpdb;
    $suppressed = $wpdb->suppress_errors( true );
    try {
      $names = self::table_names( $scope );
      if ( is_string( $names ) ) {
        return [ 'refused' => true, 'message' => $names ];
      }
    }
    finally {
      $wpdb->suppress_errors( $suppressed );
    }
    if ( $names === [] ) {
      // Said plainly, because "0 tables" from a scope that could not see any is the same output
      // a broken probe produces, and a dump of nothing is not a dump.
      return [ 'refused' => true, 'message' => 'That scope covers no table in this database, so there is nothing to describe and nothing to dump. On a multisite, scope=site on a blog created a moment ago can be empty; scope=network or scope=database covers the tables that exist.' ];
    }
    if ( count( $names ) > self::MAX_TABLES ) {
      return [ 'refused' => true, 'message' => 'That scope covers ' . count( $names ) . ' tables, over the ' . self::MAX_TABLES . '-table limit for one dump. Nothing was described and nothing will be dumped: narrow the scope rather than receiving a count that stopped early.' ];
    }

    $status = self::table_status();
    $tables = [];
    $total_bytes = 0;
    $total_rows = 0;
    foreach ( $names as $name ) {
      $estimate = (int) ( $status[ $name ]['rows'] ?? 0 );
      $bytes = (int) ( $status[ $name ]['bytes'] ?? 0 );
      $total_rows += $estimate;
      $total_bytes += $bytes;
      $tables[] = [
        'name' => $name,
        'row_count_estimate' => $estimate,
        'bytes' => $bytes,
      ];
    }

    $digest = self::digest( $scope, $names );
    $engine = self::engine();

    return [
      'scope' => $scope,
      'table_count' => count( $names ),
      'tables' => $tables,
      'total_bytes' => $total_bytes,
      'row_count_estimate_total' => $total_rows,
      'row_counts_are_estimates' => true,
      'manifest_digest' => $digest,
      'digest_covers' => 'the scope and the sorted table names. The row counts and sizes are NOT in the digest: they are estimates that move on ordinary writes, so a digest built from them would stop matching while a person was reading the approval screen.',
      'dump_engine' => $engine,
      'note' => 'A manifest is a table list and estimates, never a row. A dump includes every table in scope, which means this plugin\'s own activity log and its approval queue are in it — a documented and deliberate exception to the rule that no tool exports the log or reads the queue. The row figures are the statistics SHOW TABLE STATUS reports, not a count of rows.',
    ];
  }

  /**
  * The tables a scope covers, sorted, or the sentence to refuse with.
  *
  * WHAT EACH SCOPE MEANS, EXACTLY:
  *
  *   database  every BASE TABLE the database reports. Views are not tables: a view is a query, and
  *             dumping one writes a definition that only restores onto a schema that already has
  *             the tables behind it.
  *   network   every base table whose name begins with the install's base prefix. On a single
  *             install that is the same set as `site`, because there is only one blog.
  *   site      every base table whose name begins with THIS blog's prefix, minus another blog's
  *             tables (`wp_2_posts` when this is not blog 2) and, on a multisite, minus the
  *             network's own rows (`wp_blogs`, `wp_site`, `wp_sitemeta`, ...), which share blog 1's
  *             prefix and are not this blog's.
  *
  * The consequence worth naming rather than hiding: the shared user tables (`wp_users`,
  * `wp_usermeta`) are named with the BASE prefix, so on a multisite they are in a `site` dump for
  * blog 1 and not for blog 2, and a dump that must carry them for every blog is the `network`
  * scope.
  *
  * @return string[]|string Sorted table names, or the refusal sentence.
  */
  public static function table_names( string $scope ) {
    global $wpdb;
    if ( !in_array( $scope, self::SCOPES, true ) ) {
      return 'Unknown scope "' . $scope . '". Use site, network or database.';
    }
    $rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
    if ( $wpdb->last_error ) {
      // The message is not returned: it can name the database, the user and the privileges.
      return 'The database could not list its tables, so nothing was described. Its error is in the site error log.';
    }
    $base = (string) $wpdb->base_prefix;
    $own = (string) $wpdb->prefix;
    $blog_id = (int) $wpdb->blogid;
    $network_tables = [];
    if ( is_multisite() ) {
      foreach ( (array) $wpdb->ms_global_tables as $suffix ) {
        $network_tables[] = $base . $suffix;
      }
    }

    $names = [];
    foreach ( (array) $rows as $row ) {
      $name = (string) ( $row[0] ?? '' );
      $type = strtoupper( (string) ( $row[1] ?? '' ) );
      if ( $name === '' || $type !== 'BASE TABLE' ) {
        continue;
      }
      if ( $scope === 'database' ) {
        $names[] = $name;
        continue;
      }
      if ( strpos( $name, $base ) !== 0 ) {
        // Not this install's table at all: scope=database is the one that carries it.
        continue;
      }
      if ( $scope === 'network' ) {
        $names[] = $name;
        continue;
      }
      if ( strpos( $name, $own ) !== 0 ) {
        continue;
      }
      if ( is_multisite() ) {
        if ( in_array( $name, $network_tables, true ) ) {
          continue;
        }
        if ( preg_match( '#^' . preg_quote( $base, '#' ) . '(\d+)_#', $name, $m ) && (int) $m[1] !== $blog_id ) {
          continue;
        }
      }
      $names[] = $name;
    }
    usort( $names, static function ( $a, $b ) {
      return strcmp( $a, $b );
    } );
    return $names;
  }

  /**
  * The digest a person approves: the scope and the sorted table names.
  *
  * Versioned, so a later manifest that covers more cannot be confused with one built by this code.
  *
  * @param string[] $names
  */
  private static function digest( string $scope, array $names ): string {
    $sorted = $names;
    usort( $sorted, static function ( $a, $b ) {
      return strcmp( $a, $b );
    } );
    $digest = hash_init( 'sha256' );
    hash_update( $digest, "gmcp-db-dump-manifest-v1\0" . $scope . "\0" );
    foreach ( $sorted as $name ) {
      hash_update( $digest, $name . "\0" );
    }
    return hash_final( $digest );
  }

  /**
  * Per-table row estimate and size, from the database's own statistics.
  *
  * `SHOW TABLE STATUS` reports `Rows` as an ESTIMATE for InnoDB and an exact figure for MyISAM; it
  * is the only figure available without counting every row, and every reply says it is an estimate.
  *
  * @return array<string,array{rows:int,bytes:int}>
  */
  private static function table_status(): array {
    global $wpdb;
    $suppressed = $wpdb->suppress_errors( true );
    try {
      $rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
    }
    finally {
      $wpdb->suppress_errors( $suppressed );
    }
    $out = [];
    foreach ( (array) $rows as $row ) {
      $name = (string) ( $row['Name'] ?? '' );
      if ( $name === '' ) {
        continue;
      }
      $out[ $name ] = [
        'rows' => (int) ( $row['Rows'] ?? 0 ),
        'bytes' => (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ),
      ];
    }
    return $out;
  }

  #endregion

  #region The pull

  /**
  * Build the dump for one approved pull and mint its one-time token.
  *
  * The manifest digest is recomputed here and compared with the caller's copy. That is what makes
  * the approval bind to a set of tables rather than to a scope name: an approval is for one
  * manifest, and a table that appeared after the person read the screen gets a new request.
  */
  private function pull( array $args, array $manifest, array $r ): array {
    $given = isset( $args['manifest_digest'] ) ? strtolower( trim( (string) $args['manifest_digest'] ) ) : '';
    if ( $given === '' ) {
      return $this->error( $r, 'mode=pull needs manifest_digest: run mode=manifest for this scope first, then pass the manifest_digest it returned.' );
    }
    if ( !hash_equals( (string) $manifest['manifest_digest'], $given ) ) {
      return $this->error( $r, 'The set of tables in this scope has changed since that manifest, so this dump was not built. Run mode=manifest again, read the table list, and pass the new manifest_digest. The approval has to be for the tables that are actually there.' );
    }
    $names = [];
    foreach ( (array) $manifest['tables'] as $table ) {
      $names[] = (string) $table['name'];
    }

    $built = $this->build( (string) $manifest['scope'], $names );
    if ( isset( $built['error'] ) ) {
      return $this->error( $r, $built['error'] );
    }

    try {
      $token = bin2hex( random_bytes( 32 ) );
    }
    catch ( \Throwable $e ) {
      self::unlink_dump( (string) $built['path'] );
      return $this->error( $r, 'A one-time token could not be generated, so the dump was removed and no URL was issued.' );
    }
    $filename = sanitize_file_name( 'db-dump-' . (string) $manifest['scope'] . '-' . gmdate( 'Ymd-His' ) . '.sql.gz' );
    $stored = [
      'path' => (string) $built['path'],
      'filename' => $filename,
      'expires' => time() + self::TOKEN_TTL,
      'site' => get_current_blog_id(),
      'bytes' => (int) $built['bytes'],
      'tables' => (int) $manifest['table_count'],
      'scope' => (string) $manifest['scope'],
    ];
    if ( !add_option( self::TOKEN_OPTION_PREFIX . $token, $stored, '', false ) ) {
      self::unlink_dump( (string) $built['path'] );
      return $this->error( $r, 'The one-time token could not be stored, so the dump was removed and no URL was issued.' );
    }
    // The file now has an owner: the row above. The shutdown callback that removes it on any
    // failure path is disarmed by this call.
    self::disarm_shutdown();
    wp_schedule_single_event( time() + self::TOKEN_TTL + MINUTE_IN_SECONDS, self::CRON_HOOK );

    return $this->json( $r, [
      'status' => 'ready',
      'scope' => (string) $manifest['scope'],
      // Early in the reply on purpose: the audit log keeps only the first 1000 characters of a
      // result text, and a token placed after that would be absent from the log whether or not
      // the scrubber worked. Keeping it here makes the suite's absence check meaningful.
      'download_url' => rest_url( 'mcp/v1/db-dump/' . $token ),
      'expires_in' => '5 minutes',
      'size' => (int) $built['bytes'],
      'manifest_digest' => (string) $manifest['manifest_digest'],
      'table_count' => (int) $manifest['table_count'],
      'dump_engine' => (string) $built['engine'],
      'note' => 'One use only. The file is streamed by the endpoint and deleted after it is sent; its bytes are never put in a tool reply or in the audit log. The token in this URL is redacted from the log.',
    ] );
  }

  /**
  * Build the artifact: dump, gzip, verify, and hand back the path of something proven whole.
  *
  * @param string[] $names The approved table list, in the manifest's order.
  * @return array{path:string,bytes:int,engine:string}|array{error:string}
  */
  private function build( string $scope, array $names ): array {
    $dir = self::temp_dir();
    if ( $dir === null ) {
      return [ 'error' => 'No writable temporary directory outside the web root was found, so a dump was not built. The manifest is still available.' ];
    }
    $plain = tempnam( $dir, self::TEMP_PREFIX );
    if ( $plain === false ) {
      return [ 'error' => 'A temporary file could not be created outside the web root, so a dump was not built. The manifest is still available.' ];
    }
    @chmod( $plain, 0600 );
    $gzipped = null;
    $keep = false;
    // Until the token row exists the artifact has no owner, and a request that dies here (the PHP
    // time limit on a large dump, an out-of-memory fatal, a client that went away) ran none of the
    // unlinks below. A shutdown callback runs on every one of those ends.
    self::arm_shutdown( static function () use ( &$plain, &$gzipped, &$keep ) {
      if ( $keep ) {
        return;
      }
      if ( is_string( $plain ) ) {
        GMCP_Tools_Db_Dump::unlink_dump( $plain );
      }
      if ( is_string( $gzipped ) ) {
        GMCP_Tools_Db_Dump::unlink_dump( $gzipped );
      }
    } );

    $engine = self::engine();
    $failure = $engine === self::ENGINE_PHP
      ? self::php_dump( $plain, $scope, $names )
      : self::utility_dump( $plain, $names );
    if ( is_string( $failure ) ) {
      self::unlink_dump( $plain );
      self::disarm_shutdown();
      return [ 'error' => $failure ];
    }

    $gzipped = tempnam( $dir, self::TEMP_PREFIX );
    if ( $gzipped === false ) {
      self::unlink_dump( $plain );
      self::disarm_shutdown();
      return [ 'error' => 'A temporary file could not be created for the compressed dump, so a dump was not built.' ];
    }
    @chmod( $gzipped, 0600 );
    $compress = self::gzip_file( $plain, $gzipped );
    self::unlink_dump( $plain );
    $plain = null;
    if ( $compress !== true ) {
      self::unlink_dump( $gzipped );
      $gzipped = null;
      self::disarm_shutdown();
      return [ 'error' => 'The dump could not be compressed, so nothing was kept. The site error log has the reason.' ];
    }

    // The verification reads the artifact that will actually be served, not the intermediate file:
    // this is the check that a partial dump can never be answered as a success.
    $problem = self::verify( $gzipped, $names );
    if ( $problem !== '' ) {
      $size = (int) @filesize( $gzipped );
      self::unlink_dump( $gzipped );
      $gzipped = null;
      self::disarm_shutdown();
      GMCP_Logging::error( 'Database dump failed its completeness check (' . $problem . '); ' . $size . ' bytes were removed. Tables expected: ' . count( $names ) . '.' );
      return [ 'error' => 'The dump did not pass its completeness check (' . $problem . '), so nothing was kept and no URL was issued. A dump that stops early is not reported as one that succeeded; try again, or narrow the scope.' ];
    }

    $bytes = (int) @filesize( (string) $gzipped );
    $keep = true;
    return [ 'path' => (string) $gzipped, 'bytes' => $bytes, 'engine' => $engine ];
  }

  /** The shutdown callback registered by build(), held so it can be disarmed. */
  private static $shutdown = null;

  private static function arm_shutdown( callable $cleanup ): void {
    self::$shutdown = $cleanup;
    register_shutdown_function( static function () {
      if ( is_callable( GMCP_Tools_Db_Dump::$shutdown ) ) {
        call_user_func( GMCP_Tools_Db_Dump::$shutdown );
      }
    } );
  }

  /**
  * Disarm the cleanup registered by build().
  *
  * Called once the token row exists: from then on the row owns the file, and the sweep that removes
  * an expired row removes its dump with it. The callback itself is not unregistered (PHP has no
  * way to do that), so the flag it closes over is what makes it do nothing.
  */
  private static function disarm_shutdown(): void {
    self::$shutdown = null;
  }

  /**
  * Run a maintained dump utility with an argv array. Returns null on success, or a sentence.
  *
  * NO SHELL, NO STRING. proc_open() takes the array, so a table name is never parsed by a shell;
  * a command string would put every one of them through one, on a database whose table names are
  * not ours to trust.
  *
  * NO CALLER-CONTROLLED FLAGS. Every option below is a constant of this class. The database name
  * and the table names are the only variable arguments, they come from SHOW TABLES, and a name
  * that begins with "-" is refused rather than handed to a program that would read it as an
  * option.
  *
  * CREDENTIALS IN A FILE, NOT IN argv. They go through --defaults-extra-file, mode 0600, created
  * here and removed in the finally block. argv is readable by every process on the host through
  * /proc, and is what `ps` prints; an environment variable is inherited by anything the dump tool
  * or a hook it runs starts.
  *
  * @param string   $sql_path Where the SQL goes. Opened here at 0600 and handed to the child as
  *                           its stdout, so the tool never creates a file with its own umask.
  * @param string[] $names
  * @return null|string
  */
  private static function utility_dump( string $sql_path, array $names ) {
    $binary = self::utility_binary();
    if ( $binary === null ) {
      return 'The dump utility this scope prefers is not present at a pinned path on the server running this plugin, and the fallback was not chosen, so no dump was built.';
    }
    if ( !defined( 'DB_NAME' ) || !defined( 'DB_USER' ) || !defined( 'DB_HOST' ) ) {
      return 'The database name, user or host is not available to PHP (wp-config.php may be supplying the connection another way), so the dump utility cannot be pointed at this database.';
    }
    $host_parts = self::utility_host_parts( (string) DB_HOST );
    if ( $host_parts === null ) {
      return 'The configured database host is a form the dump utility cannot take (a list of hosts), so no dump was built.';
    }
    foreach ( $names as $name ) {
      if ( $name === '' || $name[0] === '-' ) {
        return 'A table in this scope has a name that begins with "-", which the dump utility would read as an option, so nothing was dumped.';
      }
    }

    $credential_file = tempnam( (string) self::temp_dir(), self::TEMP_PREFIX );
    if ( $credential_file === false ) {
      return 'A temporary file for the database credentials could not be created, so no dump was built.';
    }
    @chmod( $credential_file, 0600 );
    $error_file = tempnam( (string) self::temp_dir(), self::TEMP_PREFIX );
    if ( $error_file === false ) {
      self::unlink_dump( $credential_file );
      return 'A temporary file for the dump tool\'s output could not be created, so no dump was built.';
    }
    @chmod( $error_file, 0600 );
    $handle = @fopen( $sql_path, 'wb' );
    if ( $handle === false ) {
      self::unlink_dump( $credential_file );
      self::unlink_dump( $error_file );
      return 'The dump file could not be opened for writing, so no dump was built.';
    }
    @chmod( $sql_path, 0600 );

    $argv = array_merge(
      [
        $binary,
        // FIRST, as the client tool requires, and never anywhere else.
        '--defaults-extra-file=' . $credential_file,
        '--single-transaction',
        '--skip-lock-tables',
        '--quick',
        '--no-tablespaces',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        (string) DB_NAME,
      ],
      array_values( $names )
    );

    $exit = null;
    $killed = false;
    $process = null;
    try {
      if ( !self::write_credentials( $credential_file, $host_parts ) ) {
        return 'The private file holding the database credentials could not be written, so no dump was built.';
      }
      $descriptors = [
        0 => [ 'file', '/dev/null', 'r' ],
        1 => $handle,
        2 => [ 'file', $error_file, 'w' ],
      ];
      // A minimal environment rather than the inherited one: nothing in this process's environment
      // (a MYSQL_PWD set by a host, a proxy variable) reaches the child.
      $env = [ 'PATH' => '/usr/local/bin:/usr/bin:/bin' ];
      $process = @proc_open( $argv, $descriptors, $pipes, null, $env );
      if ( !is_resource( $process ) ) {
        return 'The dump utility could not be started, so no dump was built.';
      }
      $deadline = microtime( true ) + self::DUMP_TIMEOUT;
      while ( true ) {
        $status = proc_get_status( $process );
        if ( empty( $status['running'] ) ) {
          $exit = (int) ( $status['exitcode'] ?? -1 );
          break;
        }
        if ( microtime( true ) > $deadline ) {
          $killed = true;
          proc_terminate( $process, 9 );
          break;
        }
        usleep( 50000 );
      }
      $closed = proc_close( $process );
      $process = null;
      if ( $exit === null || $exit === -1 ) {
        $exit = (int) $closed;
      }
    }
    finally {
      // Both handles are the child's, and both are ours to close.
      if ( is_resource( $handle ) ) {
        fclose( $handle );
      }
      self::unlink_dump( $credential_file );
    }

    if ( $killed ) {
      self::unlink_dump( $error_file );
      return 'The dump utility was still running after ' . self::DUMP_TIMEOUT . ' seconds and was stopped, so no dump was built. A database this size needs a backup run from the server, not through a web request.';
    }
    $reported = self::read_briefly( $error_file, 4000 );
    self::unlink_dump( $error_file );
    if ( $exit !== 0 ) {
      if ( $reported !== '' ) {
        // For the operator reading the site error log, never for the caller: it names the
        // database, the user and privileges. log_text() because this is a channel a credential
        // can reach.
        GMCP_Logging::error( 'Database dump utility failed (exit ' . (int) $exit . '): ' . GMCP_Core::log_text( $reported ) );
      }
      return 'The dump utility exited with an error, so nothing was kept. Its message is in the site error log and is not returned here: it names the database and the account.';
    }
    return null;
  }

  /**
  * Write the private defaults file the dump tool reads its credentials from.
  *
  * The option-file syntax quotes with double quotes and escapes backslashes and quotes; a password
  * holding either would otherwise end the value early and put the rest of it into an option the
  * tool parses as something else.
  *
  * @param array{host:string,socket:string|null} $host_parts
  */
  private static function write_credentials( string $path, array $host_parts ): bool {
    $quote = static function ( string $value ): string {
      return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
    };
    $lines = [
      '[client]',
      'user=' . $quote( (string) DB_USER ),
      'password=' . $quote( defined( 'DB_PASSWORD' ) ? (string) DB_PASSWORD : '' ),
      'host=' . $quote( $host_parts['host'] ),
    ];
    if ( $host_parts['socket'] !== null ) {
      $lines[] = 'socket=' . $quote( $host_parts['socket'] );
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    $written = @file_put_contents( $path, implode( "\n", $lines ) . "\n", LOCK_EX );
    @chmod( $path, 0600 );
    return is_int( $written ) && $written > 0;
  }

  /**
  * DB_HOST as the client tool wants it, or null when it is a form it cannot take.
  *
  * wpdb accepts "host", "host:port", "host:/path/to.sock" and a comma-separated list of hosts with
  * DB_HOST_ADDRESSES. An option file holds one host and one socket, so the list form is refused
  * here and the caller falls back to the PHP dumper, which uses wpdb's own connection and needs no
  * parsing at all.
  *
  * @return array{host:string,socket:string|null}|null
  */
  private static function utility_host_parts( string $db_host ): ?array {
    $db_host = trim( $db_host );
    if ( $db_host === '' || strpos( $db_host, ',' ) !== false ) {
      return null;
    }
    if ( strpos( $db_host, ':' ) === false ) {
      return [ 'host' => $db_host, 'socket' => null ];
    }
    [ $host, $rest ] = explode( ':', $db_host, 2 );
    if ( $rest !== '' && $rest[0] === '/' ) {
      return [ 'host' => $host === '' ? 'localhost' : $host, 'socket' => $rest ];
    }
    if ( ctype_digit( $rest ) ) {
      // A port. The option file has no port key, so it goes back into the host, which is the form
      // both client tools accept.
      return [ 'host' => $host . ':' . $rest, 'socket' => null ];
    }
    return null;
  }

  /**
  * Write the whole dump as SQL, for a server with no dump utility at a pinned path.
  *
  * A visible downgrade: the manifest says this is what a pull would use, the reply says it is what
  * ran, and docs/guardrails.md says what it does not write — triggers, stored routines, events and
  * views, which `mysqldump`/`mariadb-dump` do write when asked. It writes what a database is made of
  * otherwise: one `DROP TABLE IF EXISTS` and the table's own `CREATE TABLE` statement, then the rows
  * as INSERT statements in batches, read a batch at a time so a large table costs memory in
  * proportion to the batch rather than to the table.
  *
  * @param string   $sql_path
  * @param string   $scope
  * @param string[] $names
  * @return null|string Null on success, or the sentence to refuse with.
  */
  private static function php_dump( string $sql_path, string $scope, array $names ) {
    global $wpdb;
    $out = @fopen( $sql_path, 'wb' );
    if ( $out === false ) {
      return 'The dump file could not be opened for writing, so no dump was built.';
    }
    @chmod( $sql_path, 0600 );
    $started = true;
    try {
      $started = self::write_all( $out, "-- Guarded MCP database dump\n"
        . "-- Written by the PHP fallback: no mysqldump or mariadb-dump is present at a pinned path.\n"
        . '-- scope: ' . $scope . "\n"
        . '-- tables: ' . count( $names ) . "\n"
        . '-- date: ' . gmdate( 'c' ) . "\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n" );
      foreach ( $names as $name ) {
        if ( !$started ) {
          break;
        }
        $started = self::php_dump_table( $out, $name );
      }
      if ( $started ) {
        $started = self::write_all( $out, "SET FOREIGN_KEY_CHECKS=1;\n" . self::TRAILER . ' on ' . gmdate( 'c' ) . "\n" );
      }
    }
    finally {
      if ( is_resource( $out ) ) {
        fclose( $out );
      }
    }
    if ( !$started ) {
      $error = $wpdb->last_error === '' ? 'the output file could not be written' : 'a query failed';
      if ( $wpdb->last_error !== '' ) {
        // Same rule as the utility's stderr: the operator gets it, the caller does not.
        GMCP_Logging::error( 'Database dump (PHP fallback) stopped: ' . GMCP_Core::log_text( (string) $wpdb->last_error ) );
      }
      return 'The dump stopped part-way (' . $error . '), so nothing was kept. The site error log has the reason.';
    }
    return null;
  }

  /** One table's structure and rows. False when a query or a write failed. */
  private static function php_dump_table( $out, string $name ): bool {
    global $wpdb;
    $quoted = self::quote_ident( $name );
    $suppressed = $wpdb->suppress_errors( true );
    try {
      $create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $quoted, ARRAY_N );
      if ( !is_array( $create ) || !isset( $create[1] ) || (string) $create[1] === '' ) {
        return false;
      }
      $columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $quoted, ARRAY_A );
      if ( !is_array( $columns ) ) {
        return false;
      }
      // `SHOW CREATE TABLE` reports an auto-increment value as it stands, which is what makes the
      // restored table continue where this one left off rather than at 1.
      $head = "\n--\n-- Table structure for table " . $quoted . "\n--\n"
        . 'DROP TABLE IF EXISTS ' . $quoted . ";\n"
        . rtrim( (string) $create[1], ";" ) . ";\n";
      if ( !self::write_all( $out, $head ) ) {
        return false;
      }
      if ( !$columns ) {
        return true;
      }
      $names = [];
      $types = [];
      foreach ( $columns as $column ) {
        $field = (string) ( $column['Field'] ?? '' );
        $names[] = self::quote_ident( $field );
        $types[] = strtolower( (string) ( $column['Type'] ?? '' ) );
      }
      $select = implode( ',', $names );
      $offset = 0;
      while ( true ) {
        $rows = $wpdb->get_results(
          'SELECT ' . $select . ' FROM ' . $quoted . ' LIMIT ' . (int) self::READ_BATCH . ' OFFSET ' . (int) $offset,
          ARRAY_N
        );
        if ( !is_array( $rows ) ) {
          return false;
        }
        if ( $rows === [] ) {
          return true;
        }
        $tuples = [];
        foreach ( $rows as $row ) {
          $values = [];
          foreach ( array_values( $row ) as $index => $value ) {
            $values[] = self::sql_value( $value, $types[ $index ] ?? '' );
          }
          $tuples[] = '(' . implode( ',', $values ) . ')';
          if ( count( $tuples ) >= self::ROWS_PER_STATEMENT ) {
            if ( !self::write_all( $out, 'INSERT INTO ' . $quoted . ' (' . $select . ') VALUES ' . implode( ',', $tuples ) . ";\n" ) ) {
              return false;
            }
            $tuples = [];
          }
        }
        if ( $tuples ) {
          if ( !self::write_all( $out, 'INSERT INTO ' . $quoted . ' (' . $select . ') VALUES ' . implode( ',', $tuples ) . ";\n" ) ) {
            return false;
          }
        }
        if ( count( $rows ) < self::READ_BATCH ) {
          return true;
        }
        $offset += self::READ_BATCH;
      }
    }
    finally {
      $wpdb->suppress_errors( $suppressed );
    }
  }

  /** A table or column name as SQL: backticks, with an embedded backtick doubled. */
  private static function quote_ident( string $name ): string {
    return '`' . str_replace( '`', '``', $name ) . '`';
  }

  /**
  * One value as an SQL literal.
  *
  * Binary columns are written as hex: a byte string is not text, and a charset conversion on the
  * way through an INSERT is how a serialized PHP string in a blob comes back a different length.
  * Numeric columns are written bare so that a decimal keeps its scale. Everything else is a quoted
  * string escaped by the connection WordPress is already using, so the escaping bits match the
  * server's own mode.
  *
  * @param mixed $value
  */
  private static function sql_value( $value, string $type ): string {
    global $wpdb;
    if ( $value === null ) {
      return 'NULL';
    }
    if ( strpos( $type, 'blob' ) !== false || strpos( $type, 'binary' ) !== false || strpos( $type, 'bit' ) === 0 ) {
      return '_binary 0x' . bin2hex( (string) $value );
    }
    if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)/', $type ) && is_numeric( $value ) ) {
      return (string) $value;
    }
    return "'" . $wpdb->_real_escape( (string) $value ) . "'";
  }

  /** Write a string to a handle, reporting a short write as a failure rather than ignoring it. */
  private static function write_all( $handle, string $text ): bool {
    $length = strlen( $text );
    $written = 0;
    while ( $written < $length ) {
      $chunk = @fwrite( $handle, substr( $text, $written, 1048576 ) );
      if ( $chunk === false || $chunk === 0 ) {
        return false;
      }
      $written += $chunk;
    }
    return true;
  }

  /** Copy a file through gzip into another file. True on success. */
  private static function gzip_file( string $source, string $target ) {
    $in = @fopen( $source, 'rb' );
    if ( $in === false ) {
      return 'The dump file could not be read back for compression.';
    }
    $out = @gzopen( $target, 'wb9' );
    if ( $out === false ) {
      fclose( $in );
      return 'The compressed dump could not be opened for writing.';
    }
    $ok = true;
    try {
      while ( !feof( $in ) ) {
        $chunk = fread( $in, 1048576 );
        if ( $chunk === false ) {
          $ok = false;
          break;
        }
        if ( $chunk === '' ) {
          break;
        }
        if ( gzwrite( $out, $chunk ) !== strlen( $chunk ) ) {
          $ok = false;
          break;
        }
      }
    }
    finally {
      fclose( $in );
      gzclose( $out );
    }
    @chmod( $target, 0600 );
    return $ok;
  }

  /**
  * Read the artifact back and prove it is whole.
  *
  * Four questions, and the answer is a sentence naming the one that failed or an empty string:
  *
  *   - is there anything in the file at all;
  *   - does it decompress (a truncated gz fails its CRC, which gzread reports);
  *   - is there a `CREATE TABLE` for EVERY table in the approved list, custom-prefix and all, so a
  *     dump that stopped after the tenth table cannot pass on the first ten;
  *   - does it end with the dump tool's own completion trailer, which is the one line a dump that
  *     was cut off never reaches.
  *
  * Streamed, in one pass, with a fixed buffer: a dump of a real site is larger than a request's
  * memory, and holding it to count a string is how a check on a large database becomes a fatal.
  * The scan carries the tail of the previous chunk so a marker split across two chunks is found.
  *
  * @param string[] $names
  * @return string Empty when the artifact is whole, otherwise what failed.
  */
  private static function verify( string $path, array $names ): string {
    $size = @filesize( $path );
    if ( $size === false || $size <= 0 ) {
      return 'the file is empty';
    }
    $expected = [];
    foreach ( $names as $name ) {
      $expected[ 'CREATE TABLE `' . str_replace( '`', '``', $name ) . '`' ] = $name;
    }
    $found = [];
    $tail = '';
    $bytes = 0;
    $handle = @gzopen( $path, 'rb' );
    if ( $handle === false ) {
      return 'it could not be opened for reading';
    }
    try {
      while ( !gzeof( $handle ) ) {
        $chunk = gzread( $handle, 1048576 );
        if ( $chunk === false ) {
          return 'it does not decompress';
        }
        if ( $chunk === '' ) {
          break;
        }
        $bytes += strlen( $chunk );
        $window = $tail . $chunk;
        foreach ( $expected as $marker => $name ) {
          if ( !isset( $found[ $name ] ) && strpos( $window, $marker ) !== false ) {
            $found[ $name ] = true;
          }
        }
        $tail = substr( $window, -512 );
      }
    }
    finally {
      gzclose( $handle );
    }
    if ( $bytes <= 0 ) {
      return 'it is empty once decompressed';
    }
    $missing = count( $expected ) - count( $found );
    if ( $missing > 0 ) {
      return $missing . ' of ' . count( $expected ) . ' tables have no CREATE TABLE in the file';
    }
    if ( strpos( $tail, self::TRAILER ) === false ) {
      return 'it does not end with a "Dump completed" trailer';
    }
    return '';
  }

  /**
  * The engine a pull would use, and whether a maintained utility is reachable.
  *
  * The label carries no path: an absolute server path in a reply is a filesystem disclosure the
  * caller did not need, and the person answering the approval is told what ran either way.
  */
  public static function engine(): string {
    if ( self::utility_binary() === null ) {
      return self::ENGINE_PHP;
    }
    if ( !defined( 'DB_NAME' ) || !defined( 'DB_USER' ) || !defined( 'DB_HOST' ) ) {
      return self::ENGINE_PHP;
    }
    if ( self::utility_host_parts( (string) DB_HOST ) === null ) {
      return self::ENGINE_PHP;
    }
    return self::ENGINE_UTILITY;
  }

  /** The pinned dump utility that exists here, or null. @see self::UTILITY_PATHS */
  private static function utility_binary(): ?string {
    foreach ( self::utility_paths() as $path ) {
      if ( is_string( $path ) && $path !== '' && @is_file( $path ) && @is_executable( $path ) ) {
        return $path;
      }
    }
    return null;
  }

  /**
  * The absolute paths a maintained dump utility may be found at.
  *
  * Filterable so a host that keeps its client tools elsewhere can say so. Nothing from a caller
  * reaches this: an argv[0] an agent can choose would run a program of its choosing with this
  * site's database credentials behind it.
  *
  * @return string[]
  */
  private static function utility_paths(): array {
    $paths = apply_filters( 'gmcp_db_dump_utility_paths', self::UTILITY_PATHS );
    return is_array( $paths ) ? array_values( array_filter( $paths, 'is_string' ) ) : self::UTILITY_PATHS;
  }

  /** At most $limit bytes of a file, for a message that is logged and not returned. */
  private static function read_briefly( string $path, int $limit ): string {
    $handle = @fopen( $path, 'rb' );
    if ( $handle === false ) {
      return '';
    }
    $text = (string) @fread( $handle, max( 1, $limit ) );
    fclose( $handle );
    return trim( $text );
  }

  #endregion

  #region Tokens and temporary files

  /**
  * A temporary directory outside every web-served path this site knows about.
  *
  * Asked of the export group rather than answered again here: "is this directory inside something
  * the web server serves" is one question, and two answers to it would eventually disagree — the
  * half that drifted would put a dump of the database where a URL reaches it.
  */
  public static function temp_dir(): ?string {
    if ( !class_exists( 'GMCP_Tools_Export' ) ) {
      return null;
    }
    return GMCP_Tools_Export::temp_dir();
  }

  /**
  * Consume one token and hand back the file behind it.
  *
  * Read, then delete with a CONDITIONAL statement, so two concurrent requests cannot both stream
  * the same dump. The expiry check is here rather than left to an option cache, because the row can
  * outlive its own deadline. Same shape as GMCP_Tools_Export::take_token(), and it is a copy rather
  * than a shared call for one reason: the two differ in the row prefix they own, and a shared
  * helper that took a prefix would let a future caller pass the wrong one and spend somebody
  * else's token.
  *
  * @return array{path:string,filename:string}|array{error:string,status:int}
  */
  public static function take_token( string $token ): array {
    global $wpdb;
    $invalid = [ 'error' => 'Invalid or expired dump token.', 'status' => 403 ];
    if ( !preg_match( '/^[A-Za-z0-9]{16,128}$/', $token ) ) {
      return $invalid;
    }
    $name = self::TOKEN_OPTION_PREFIX . $token;
    $stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
    if ( $stored === null ) {
      return $invalid;
    }
    $won = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $stored ) );
    wp_cache_delete( $name, 'options' );
    if ( $won !== 1 ) {
      return $invalid;
    }
    $data = maybe_unserialize( $stored );
    if ( !is_array( $data ) || empty( $data['path'] ) || empty( $data['filename'] ) ) {
      if ( is_array( $data ) && !empty( $data['path'] ) ) {
        self::unlink_dump( (string) $data['path'] );
      }
      return $invalid;
    }
    if ( (int) ( $data['expires'] ?? 0 ) < time() ) {
      self::unlink_dump( (string) $data['path'] );
      return $invalid;
    }
    $path = (string) $data['path'];
    if ( !self::safe_dump_path( $path ) ) {
      self::unlink_dump( $path );
      return [ 'error' => 'That dump is no longer available.', 'status' => 403 ];
    }
    return [ 'path' => $path, 'filename' => (string) $data['filename'] ];
  }

  /**
  * Look at a token without spending it: what a HEAD request is answered from.
  *
  * A link previewer, a mail scanner or a chat client's unfurler sends HEAD, and WordPress routes
  * HEAD to the GET handler; when that handler spent the token on the way to answering, the dump was
  * gone before the person it was minted for had clicked.
  *
  * @return array{filename:string,size:int}|array{error:string,status:int}
  */
  public static function peek_token( string $token ): array {
    global $wpdb;
    $invalid = [ 'error' => 'Invalid or expired dump token.', 'status' => 403 ];
    if ( !preg_match( '/^[A-Za-z0-9]{16,128}$/', $token ) ) {
      return $invalid;
    }
    $stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::TOKEN_OPTION_PREFIX . $token ) );
    if ( $stored === null ) {
      return $invalid;
    }
    $data = maybe_unserialize( $stored );
    if ( !is_array( $data ) || empty( $data['path'] ) || empty( $data['filename'] ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
      return $invalid;
    }
    $path = (string) $data['path'];
    if ( !self::safe_dump_path( $path ) || is_link( $path ) || !is_file( $path ) || !is_readable( $path ) ) {
      return [ 'error' => 'That dump is no longer available.', 'status' => 403 ];
    }
    $size = filesize( $path );
    return [ 'filename' => (string) $data['filename'], 'size' => $size === false ? 0 : (int) $size ];
  }

  /** Drop an unspent token and its file, e.g. because the group has been switched off. */
  public static function discard_token( string $token ): void {
    if ( !preg_match( '/^[A-Za-z0-9]{16,128}$/', $token ) ) {
      return;
    }
    $name = self::TOKEN_OPTION_PREFIX . $token;
    $stored = get_option( $name, null );
    delete_option( $name );
    if ( is_array( $stored ) && !empty( $stored['path'] ) ) {
      self::unlink_dump( (string) $stored['path'] );
    }
  }

  /**
  * Remove token rows that have expired and temporary dumps that no row owns.
  *
  * Called by the cron event, by every tool call and by the REST endpoint. Bounded by the number of
  * live dumps, which is small; a site with none pays one LIKE.
  */
  public static function cleanup_expired(): void {
    global $wpdb;
    $like = $wpdb->esc_like( self::TOKEN_OPTION_PREFIX ) . '%';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
    foreach ( (array) $rows as $row ) {
      $data = maybe_unserialize( $row['option_value'] ?? '' );
      $expires = is_array( $data ) ? (int) ( $data['expires'] ?? 0 ) : 0;
      if ( $expires > 0 && $expires >= time() ) {
        continue;
      }
      if ( is_array( $data ) && !empty( $data['path'] ) ) {
        self::unlink_dump( (string) $data['path'] );
      }
      delete_option( (string) ( $row['option_name'] ?? '' ) );
    }
    $dir = self::temp_dir();
    if ( $dir !== null ) {
      foreach ( (array) glob( $dir . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . '*' ) as $file ) {
        $mtime = @filemtime( $file );
        if ( $mtime !== false && $mtime < time() - self::TOKEN_TTL - MINUTE_IN_SECONDS ) {
          @unlink( $file );
        }
      }
    }
  }

  /** Whether a path is a regular file we created, directly in our temp directory. */
  private static function safe_dump_path( string $path ): bool {
    $dir = self::temp_dir();
    if ( $dir === null || is_link( $path ) || !is_file( $path ) ) {
      return false;
    }
    $real = realpath( $path );
    if ( $real === false || dirname( $real ) !== $dir ) {
      return false;
    }
    return strpos( basename( $real ), self::TEMP_PREFIX ) === 0;
  }

  /** Unlink a temp file without following a link or leaving the temp directory. */
  public static function unlink_dump( string $path ): void {
    $dir = self::temp_dir();
    if ( $dir === null ) {
      return;
    }
    if ( is_link( $path ) ) {
      @unlink( $path );
      return;
    }
    $real = realpath( $path );
    if ( $real === false || dirname( $real ) !== $dir || strpos( basename( $real ), self::TEMP_PREFIX ) !== 0 ) {
      return;
    }
    if ( is_file( $real ) ) {
      @unlink( $real );
    }
  }

  #endregion

  #region Replies

  private function error( array $r, string $message, int $code = -32602 ): array {
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message . ' [error ' . $code . ']' ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }

  private function json( array $r, array $payload ): array {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no silent repair of bytes: a manifest is written as it is measured.
    $text = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $text === false ? '{}' : $text ] ],
      'isError' => false,
    ];
    return $r;
  }

  #endregion
}
