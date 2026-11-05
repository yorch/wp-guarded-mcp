<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Stream, read from the outside: the second activity log this plugin can read, and the one
* most sites actually have.
*
* Stream and WP Activity Log answer the same question and are separate groups rather than
* one group with an adapter, because their data is not the same shape and a shared tool name
* would have to lie about at least one of them. Stream stores a record as one row with a
* connector, a context, an action and a rendered summary (measured on 4.4.0: wp_stream has
* ID, site_id, blog_id, object_id, user_id, user_role, summary, created, connector, context,
* action, ip), while WP Activity Log stores an alert id, a severity and a metadata table.
* What is shared is the policy, and that is shared by being copied deliberately: read only,
* counts at read level, rows at admin, no IP address at any level, and a log_state block so a
* zero is never ambiguous.
*
* READ ONLY, AND THAT IS THE WHOLE SLICE. There is no write tool here and there will not be
* one. Stream's own settings are the reason this needed a guard rather than a promise: they
* live in the wp_stream option row (and wp_stream_network on multisite), and measured on this
* stack, wp_update_option with {"general_records_ttl": 1} was accepted and stored, which
* shortens the log's retention to a day; the same row also holds exclude rules, which decide
* what is never recorded, and the list of roles allowed to read the log. That is the shape of
* the wsal_ hole commit 87d96c0 closed, one plugin over, so wp_stream is refused by
* GMCP_Core::audit_log_option_prefixes() for the same reason: an agent that can shorten the
* retention and then read the log back can confirm its own silence. The prefix covers
* wp_stream, wp_stream_db, wp_stream_scheduler_backend and wp_stream_network because they all
* begin with it, which is why the rule is a prefix rather than a list of rows.
*
* WHAT IS NEVER RETURNED.
*
* No IP address, at any level. Stream records one per row in a column called ip, and it is
* not selected by any query in this file. Same reasoning as the WP Activity Log group: an IP
* is a fact about a person rather than about the site, this group has no write tool, so a
* list of them cannot feed any action the plugin can take, and every reply is copied into
* this plugin's own audit log where it would stay for the retention window.
*
* No metadata value outside an allowlist, and the allowlist exists because of a measured
* value rather than a worry. Stream's meta table stores a repeated key called user_meta whose
* values depend on position: on a record for a created user, the FIRST of them was the new
* account's email address, in plaintext, under a name that says nothing. That is the same
* shape as WP Activity Log's new_value, and the same answer: the reader holds the list of
* names it will return, and everything else is withheld by construction. user_meta and
* display_name are not on that list, and their names are reported in withheld_metadata so an
* absence is visible.
*
* No free-text search over the summary. Stream stores a rendered summary, so unlike WP
* Activity Log there is something to search, and that is exactly why it is not offered: a
* LIKE across the summary is an oracle for values the tools withhold — asking whether a
* guessed address appears anywhere in the log confirms it one guess at a time. The filters
* are structured columns only: connector, context, action, user, object and a date range,
* which are the ones a caller can already see.
*
* No per-user activity ranking, for the reason the WP Activity Log group gives: a per-account
* count with a last-seen time is profiling, and it is also wrong, because work done through
* this plugin's REST endpoint is recorded against whichever WordPress user the key borrows.
*
* THE log_state BLOCK. Stream is a plugin that can be installed, activated, and then quietly
* stop recording: its exclude rules decide what is never logged, its connectors can be
* removed by another plugin, and its tables can be missing on a site where it was installed
* and never activated. All of those look like a log with no recent entries, which looks like
* a quiet site. So every reply reports whether the plugin is loaded, whether both tables
* exist, how many records the log holds and how recent the newest is, what the retention
* setting is and whether it came from the option row or from Stream's default, and how many
* exclude rules are in force. The default matters: measured on a fresh install, the wp_stream
* option row does not exist at all and Stream uses 30 days in memory, so a reader that
* reported "no retention configured" would be describing a policy the site does not have.
*
* One more measured difference from WP Activity Log, recorded so nobody "fixes" it later:
* Stream stores created as a UTC datetime (class-log.php writes current_time('mysql', true)),
* not a float timestamp, so every comparison here is a string comparison against gmdate().
*/
class GMCP_Tools_Stream {

  /** The records table, without a prefix. */
  const T_RECORDS = 'stream';

  /** The metadata table, without a prefix. */
  const T_META = 'stream_meta';

  /**
  * The metadata names that describe the SITE, and nothing else is returned.
  *
  * An allowlist rather than a denylist, for the reason the WP Activity Log group records at
  * length: a denylist can only exclude what somebody remembered to name, and the value that
  * matters is written under an innocent key. Measured on 4.4.0, a record for a created user
  * carries the new account's email in user_meta, which is the kind of name a denylist author
  * would not think to write down.
  *
  * post_title, singular_name, post_date, post_date_gmt, new_status and old_status were
  * measured on this stack. post_type, plugin_name, theme_name and option_name are named here
  * because they describe the site by construction and Stream's connectors write them under
  * those names; a value that turns out not to be a scalar is withheld at read time anyway.
  */
  const RETURNED_META = [
    'post_title', 'post_type', 'singular_name', 'post_date', 'post_date_gmt',
    'new_status', 'old_status', 'plugin_name', 'theme_name', 'option_name',
  ];

  /**
  * Metadata names known to describe a person, listed so the reply can say the row held them.
  *
  * Not the enforcement — RETURNED_META is. This exists so a reader who sees the key named in
  * withheld_metadata knows the record involved a person without being handed the person.
  */
  const PERSONAL_META = [ 'user_meta', 'display_name', 'user_email', 'roles' ];

  /** A page of records. Small by default because a reply is copied into another log. */
  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 100;

  /** The longest window a count may cover. */
  const MAX_WINDOW_DAYS = 90;

  /** How much of a metadata value is ever printed. */
  const META_VALUE_CHARS = 512;

  /** Roughly how large a reply is allowed to get before it stops and says so. */
  const REPLY_BUDGET = 65536;

  /** Stream's default retention, in days, when the option row does not exist. */
  const DEFAULT_TTL_DAYS = 30;

  public function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  public function register_tools( $tools ) {
    return array_merge( is_array( $tools ) ? $tools : [], array_values( $this->tools() ) );
  }

  /**
  * Whether Stream is loaded, checked per call.
  *
  * The namespaced plugin class plus the global accessor function, the same shape the other
  * optional groups use. Stream defines no version constant (measured on 4.4.0), so presence
  * is asked of the two symbols a reader here would call.
  */
  private function loaded(): bool {
    return class_exists( '\WP_Stream\Plugin' ) && function_exists( 'wp_stream_get_instance' );
  }

  private function tools(): array {
    return [
      'stream_events_briefing' => [
        'name' => 'stream_events_briefing',
        'description' => 'Count what Stream has recorded about this site: events by connector (posts, users, plugins, themes and so on), by context and by action, over a window of at most 90 days. Counts only — no individual record is returned here, because a record names a person and a reply is copied into this plugin\'s own audit log for its retention window; stream_list_records reads rows and is admin-only. Every reply carries a log_state block, which is the part worth reading first: whether Stream is loaded, whether its tables exist, how many records the log holds, how recent the newest one is, what the retention setting is and whether that came from the stored option or from Stream\'s own default, and how many exclude rules are in force. That block is what separates "nothing happened" from "nothing is being recorded" — Stream can be installed and quietly recording almost nothing, and both look like a quiet site. A read-level key is given a recency bucket rather than an exact time, because a precise "newest" plus the per-connector counts is enough to tell when somebody last worked on the site.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'days' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'How many days back to count. Default 7, maximum 90. Measured from now, in UTC, which is how Stream stores record times.' ],
            'connector' => [ 'type' => 'string', 'description' => 'Count only this connector, for example posts, users, plugins or themes. A name Stream does not use simply matches nothing, and the reply says the total is zero rather than guessing at a spelling.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'stream_list_records' => [
        'name' => 'stream_list_records',
        'description' => 'List individual Stream records, newest first, including the actor\'s user id and role. Admin level, the same as wp_get_audit_log, because a record names a person and this plugin does not expose a third-party log more widely than its own. Filters are structured columns only: connector, context, action, user_id, object_id and a date range. There is no free-text search over the log — Stream stores a rendered summary, and a LIKE across it is an oracle for the values the tools withhold, confirming a guessed address one attempt at a time. Each row carries the summary Stream itself rendered. IP addresses are never returned at any level, and the actor is present but never the first thing in the object, because this reply is copied into this plugin\'s audit log up to a character limit and what is not copied cannot be erased later. Paged, with truncated and next_before_id when the reply reaches its size budget.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'connector' => [ 'type' => 'string', 'description' => 'Only records from this connector.' ],
            'context' => [ 'type' => 'string', 'description' => 'Only records with this context.' ],
            'action' => [ 'type' => 'string', 'description' => 'Only records with this action, such as created, updated or deleted.' ],
            'user_id' => [ 'type' => 'integer', 'description' => 'Only records attributed to this WordPress user. Note that work done through this plugin\'s REST endpoint is recorded against the user the key borrows, and command-line work is recorded with a user id of 0.' ],
            'object_id' => [ 'type' => 'integer', 'description' => 'Only records about this object, such as a post id.' ],
            'since' => [ 'type' => 'string', 'description' => 'Only records at or after this time. ISO 8601 with an explicit zone, for example 2026-09-01T00:00:00Z. A value with no zone is refused rather than guessed.' ],
            'until' => [ 'type' => 'string', 'description' => 'Only records before this time. ISO 8601 with an explicit zone.' ],
            'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Rows to return. Default 20, maximum 100.' ],
            'before_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Return only records with an id below this one. This is the stable way to page: take next_before_id from the previous reply and pass it back, and no record is repeated or skipped if new ones arrive in between.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'stream_get_record' => [
        'name' => 'stream_get_record',
        'description' => 'Read one Stream record in full, by its id: the same fields stream_list_records returns, plus that record\'s metadata as name/value pairs. Admin level, because it returns a row, a row names a person, and the metadata is where a person\'s data lives. The metadata is an ALLOWLIST, not a list of exclusions: only names that describe the site — which post, which status, which plugin, which option — are returned, and everything else is withheld, because Stream writes a person\'s data under names that give nothing away. Measured on 4.4.0: a record for a created user carries the new account\'s email address in a repeated key called user_meta. The names of the withheld keys are listed in withheld_metadata so an absence is visible rather than silent. No IP address is returned, at any level.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The record id, as returned by stream_list_records.' ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // A report rather than a gate, for the reason the WP Activity Log group gives: the
    // briefing's job is to say whether the log is loaded, and it can only say that from
    // inside a class that runs.
    try {
      switch ( $tool ) {
        case 'stream_events_briefing': $r = $this->briefing( $args, $r ); break;
        case 'stream_list_records':    $r = $this->list_records( $args, $r ); break;
        case 'stream_get_record':      $r = $this->get_record( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'Stream threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }

    return $r;
  }

  #region Tools

  private function briefing( array $args, array $r ): array {
    $state = $this->log_state();
    if ( true !== $state['readable'] ) {
      // A real answer, not an error: there is no local log to count, and returning zero
      // would be the exact defect this block exists to prevent.
      return $this->json( $r, [
        'log_state' => $state,
        'counts' => null,
        'note' => 'No counts are reported, because there is no readable Stream log. A zero here would mean "nothing happened", and that is not what this is.',
      ] );
    }

    $days = $this->window_days( $args );
    $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

    $where = [ 'blog_id = %d', 'created >= %s' ];
    $params = [ $this->blog_id(), $since ];
    if ( isset( $args['connector'] ) && $args['connector'] !== '' ) {
      $where[] = 'connector = %s';
      $params[] = (string) $args['connector'];
    }

    $sql = function ( string $column ) use ( $where ): string {
      return 'SELECT ' . $column . ' AS k, COUNT(*) AS n FROM ' . $this->table( self::T_RECORDS )
        . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY ' . $column . ' ORDER BY n DESC';
    };

    $by_connector = $this->counted( $sql( 'connector' ), $params );
    $by_context   = $this->counted( $sql( 'context' ), $params );
    $by_action    = $this->counted( $sql( 'action' ), $params );

    if ( is_string( $by_connector ) || is_string( $by_context ) || is_string( $by_action ) ) {
      // All three are checked, not just the first: a failed query returns the error as a
      // string, and pairs() would then be handed a string and throw — turning a database
      // error into "Stream threw an error" and losing the reason.
      return $this->error( $r, 'The Stream log could not be counted: ' . ( is_string( $by_connector ) ? $by_connector : ( is_string( $by_context ) ? $by_context : $by_action ) ) );
    }

    $out = [
      'log_state' => $state,
      'window' => [ 'days' => $days, 'since' => $since, 'connector' => isset( $args['connector'] ) && $args['connector'] !== '' ? (string) $args['connector'] : null ],
      'counts' => [
        'total' => array_sum( array_column( $by_connector, 'n' ) ),
        'by_connector' => $this->pairs( $by_connector ),
        'by_context' => $this->pairs( $by_context ),
        'by_action' => $this->pairs( $by_action ),
      ],
    ];
    if ( $state['exclude_rules'] > 0 ) {
      $out['note'] = $state['exclude_rules'] . ' exclude rule(s) are in force, so some categories of event are never recorded. A zero in those categories means "not recorded" rather than "did not happen".';
    }

    return $this->json( $r, $out );
  }

  private function list_records( array $args, array $r ): array {
    $state = $this->log_state();
    if ( true !== $state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'records' => null, 'note' => 'There is no readable Stream log, so no records are reported.' ] );
    }

    [ $where, $params, $err ] = $this->build_where( $args );
    if ( $err !== null ) {
      return $this->error( $r, $err );
    }

    $limit = $this->limit( $args );

    // A keyset cursor rather than offset paging, because the log is being written to while
    // it is read. id is the ordering column because created is a datetime with one-second
    // resolution and two records in the same request can share it.
    if ( isset( $args['before_id'] ) && (int) $args['before_id'] > 0 ) {
      $where[] = 'ID < %d';
      $params[] = (int) $args['before_id'];
    }

    // ip is not selected, and is named nowhere in this file. See the class comment.
    $sql = 'SELECT ID, created, connector, context, action, object_id, user_id, user_role, summary'
      . ' FROM ' . $this->table( self::T_RECORDS )
      . ' WHERE ' . implode( ' AND ', $where )
      . ' ORDER BY ID DESC LIMIT %d';
    $rows = $this->rows( $sql, array_merge( $params, [ $limit + 1 ] ) );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The Stream log could not be read: ' . $rows );
    }

    $truncated = count( $rows ) > $limit;
    if ( $truncated ) {
      array_pop( $rows );
    }

    $records = [];
    $size = 0;
    foreach ( $rows as $row ) {
      $record = $this->record_shape( $row );
      $size += strlen( wp_json_encode( $record ) );
      if ( $size > self::REPLY_BUDGET && $records ) {
        $truncated = true;
        break;
      }
      $records[] = $record;
    }

    $out = [
      'log_state' => $state,
      'count' => count( $records ),
      'truncated' => $truncated,
      'records' => $records,
    ];
    if ( $truncated ) {
      $last = end( $records );
      if ( is_array( $last ) && isset( $last['id'] ) ) {
        $out['next_before_id'] = (int) $last['id'];
      }
    }

    return $this->json( $r, $out );
  }

  private function get_record( array $args, array $r ): array {
    $state = $this->log_state( false );
    if ( true !== $state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'record' => null, 'note' => 'There is no readable Stream log, so no record is reported.' ] );
    }

    $id = isset( $args['id'] ) ? (int) $args['id'] : 0;
    if ( $id < 1 ) {
      return $this->error( $r, 'A record id is required.' );
    }

    $sql = 'SELECT ID, created, connector, context, action, object_id, user_id, user_role, summary'
      . ' FROM ' . $this->table( self::T_RECORDS ) . ' WHERE ID = %d AND blog_id = %d';
    $rows = $this->rows( $sql, [ $id, $this->blog_id() ] );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The Stream log could not be read: ' . $rows );
    }
    if ( !$rows ) {
      return $this->json( $r, [ 'log_state' => $state, 'record' => null, 'note' => 'No Stream record with id ' . $id . ' is in this site\'s log.' ] );
    }

    $record = $this->record_shape( $rows[0] );
    [ $metadata, $extra ] = $this->safe_metadata( $id );
    $record['metadata'] = $metadata;
    foreach ( $extra as $k => $v ) {
      $record[ $k ] = $v;
    }

    return $this->json( $r, [ 'log_state' => $state, 'record' => $record ] );
  }

  #endregion

  #region The log's own state

  /**
  * What the log is, independent of what it holds.
  *
  * readable is true only when the plugin is loaded and both tables are present. The
  * retention value is reported with where it came from, because the option row is absent on
  * a fresh install and Stream then uses 30 days in memory: reporting "no retention
  * configured" would describe a policy the site does not have.
  */
  private function log_state( bool $with_totals = true ): array {
    $state = [
      'plugin_loaded' => $this->loaded(),
      'tables' => [ 'present' => [], 'missing' => [] ],
      'readable' => false,
      'records' => null,
      'oldest' => null,
      'newest' => null,
      'retention' => null,
      'retention_source' => null,
      'exclude_rules' => 0,
    ];
    if ( !$state['plugin_loaded'] ) {
      $state['note'] = 'Stream is not loaded, so there is no activity log to read. This is not the same as the log being empty.';
      return $state;
    }

    foreach ( [ self::T_RECORDS, self::T_META ] as $suffix ) {
      if ( $this->table_exists( $this->table( $suffix ) ) ) {
        $state['tables']['present'][] = $suffix;
      } else {
        $state['tables']['missing'][] = $suffix;
      }
    }

    [ $settings, $source ] = $this->settings();
    $state['retention_source'] = $source;
    $ttl = isset( $settings['general_records_ttl'] ) ? (int) $settings['general_records_ttl'] : null;
    if ( $ttl === null ) {
      $ttl = self::DEFAULT_TTL_DAYS;
      $state['retention_source'] = $source === 'stored' ? 'default' : $source;
    }
    $state['retention'] = [
      'days' => $ttl,
      'keep_indefinitely' => !empty( $settings['general_keep_records_indefinitely'] ),
      'source' => $state['retention_source'],
    ];
    if ( !empty( $settings['general_keep_records_indefinitely'] ) ) {
      $state['retention_note'] = 'This site keeps records indefinitely, so nothing is pruned and the log grows without limit. The days value is stored but not acted on while that is set.';
    }
    $state['exclude_rules'] = is_array( $settings['exclude_rules'] ?? null )
      // Stream stores exclude rules COLUMN by column, keyed by row id: exclude_row,
      // author_or_role, connector, context, action, ip_address (class-log.php's
      // exclude_rules_by_rows()). Counting the outer array counts those six columns, so a
      // site with one rule reported six, and a site whose rules had all been removed still
      // reported six. The row ids under exclude_row are the rules.
      ? count( (array) ( $settings['exclude_rules']['exclude_row'] ?? [] ) )
      : 0;

    if ( $state['tables']['missing'] ) {
      $state['note'] = 'Stream is loaded but its tables were never created (missing: ' . implode( ', ', $state['tables']['missing'] ) . '), which is what a plugin installed and never activated looks like. There is no log to read, and this is not the same as the log being empty.';
      return $state;
    }

    $state['readable'] = true;
    if ( !$with_totals ) {
      return $state;
    }

    $rows = $this->rows(
      'SELECT COUNT(*) AS n, MIN(created) AS oldest, MAX(created) AS newest FROM ' . $this->table( self::T_RECORDS ) . ' WHERE blog_id = %d',
      [ $this->blog_id() ]
    );
    if ( is_string( $rows ) || !$rows ) {
      $state['readable'] = false;
      $state['note'] = 'The Stream tables exist but could not be read: ' . ( is_string( $rows ) ? $rows : 'the query returned nothing' );
      return $state;
    }

    $state['records'] = (int) $rows[0]['n'];
    if ( $state['records'] > 0 && !empty( $rows[0]['newest'] ) ) {
      $newest = $this->to_timestamp( (string) $rows[0]['newest'] );
      $oldest = $this->to_timestamp( (string) $rows[0]['oldest'] );
      if ( $this->caller_is_admin() ) {
        $state['oldest'] = $this->iso( $oldest );
        $state['newest'] = $this->iso( $newest );
      } else {
        // A precise "newest" plus the per-connector counts is enough to tell when somebody
        // last worked on the site, which is why the row tools are admin level.
        $state['how_recent'] = $newest === null ? null : $this->recency_bucket( $newest );
        $state['how_old'] = $oldest === null ? null : $this->recency_bucket( $oldest );
        $state['timestamps_note'] = 'Exact timestamps are not reported to a read key. A precise "newest" plus the per-connector counts is enough to tell when someone last worked on the site, which is why the row tools are admin level.';
      }
      if ( $newest !== null && time() - $newest > 7 * DAY_IN_SECONDS ) {
        $state['note'] = 'The newest record in this log is more than a week old. On a site that is being used, a log with nothing recent is itself worth reporting: it means either nothing is happening or nothing is being recorded.';
      }
    }

    return $state;
  }

  /**
  * Stream's settings and where they came from.
  *
  * The option row is absent on a fresh install (measured: a new 4.4.0 site has wp_stream_db
  * and wp_stream_scheduler_backend and no wp_stream row at all), so an absent row means
  * "defaults are in force", not "no retention configured". On multisite the values may live
  * in the network option, which is checked too rather than reported as local defaults.
  */
  private function settings(): array {
    $stored = get_option( 'wp_stream', null );
    if ( is_array( $stored ) && $stored ) {
      return [ $stored, 'stored' ];
    }
    if ( is_multisite() ) {
      $network = get_site_option( 'wp_stream_network', null );
      if ( is_array( $network ) && $network ) {
        return [ $network, 'network' ];
      }
    }
    return [ [], 'defaults' ];
  }

  /** A table name for this site's log. */
  private function table( string $suffix ): string {
    global $wpdb;
    // base_prefix, not prefix: Stream keeps one network-wide table pair with site_id and
    // blog_id columns, so on multisite prefix would name a table that does not exist. Not
    // verified on a multisite install — this stack is single-site, where the two are equal.
    $base = $wpdb->base_prefix . $suffix;
    if ( $wpdb->prefix !== $wpdb->base_prefix && !$this->table_exists( $base ) && $this->table_exists( $wpdb->prefix . $suffix ) ) {
      return $wpdb->prefix . $suffix;
    }
    return $base;
  }

  /** Whether a table exists, by exact name. */
  private function table_exists( string $table ): bool {
    global $wpdb;
    // esc_like, because a table name contains underscores and LIKE treats each as a
    // single-character wildcard.
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
  }

  #endregion

  #region Rows and metadata

  /** One record, shaped for a reply. No ip, and the actor is never first. */
  private function record_shape( array $row ): array {
    $created = (string) $row['created'];
    $out = [
      'id' => (int) $row['ID'],
      'time' => [
        'utc' => $created,
        'site' => get_date_from_gmt( $created, 'Y-m-d H:i:s' ),
      ],
      'connector' => (string) $row['connector'],
      'context' => (string) $row['context'],
      'action' => (string) $row['action'],
      'summary' => wp_strip_all_tags( (string) $row['summary'] ),
      'actor' => [
        'user_id' => (int) $row['user_id'],
        'role' => (string) $row['user_role'],
      ],
    ];
    if ( (int) $row['user_id'] === 0 ) {
      $out['actor']['note'] = 'No user id is recorded for this event. Command-line and system activity is recorded this way, which is why 0 is kept as a value rather than treated as absent.';
    }
    if ( (int) $row['object_id'] > 0 ) {
      $out['object_id'] = (int) $row['object_id'];
    }
    return $out;
  }

  /**
  * The metadata for one record, filtered to an allowlist.
  *
  * Deny by default. The names returned are ones that describe the site; every other name on
  * the row is withheld, and the names of the withheld keys are reported so an absence is
  * visible rather than silent. A value that is not a scalar is never printed, and a
  * serialised value is never read: the same rule the WP Activity Log group applies, because
  * casting one still exposes every property it holds.
  */
  private function safe_metadata( int $record_id ): array {
    $rows = $this->rows( 'SELECT meta_key, meta_value FROM ' . $this->table( self::T_META ) . ' WHERE record_id = %d', [ $record_id ] );
    if ( is_string( $rows ) ) {
      // A query failure is reported rather than returning an empty map, which would be
      // indistinguishable from a record that simply has no metadata.
      return [ (object) [], [ 'metadata_error' => $rows ] ];
    }
    if ( !$rows ) {
      return [ (object) [], [ 'metadata_note' => 'This record has no metadata rows.' ] ];
    }

    $out = [];
    $withheld = [];
    $marked = false;
    foreach ( $rows as $meta ) {
      $name = (string) $meta['meta_key'];
      if ( !in_array( $name, self::RETURNED_META, true ) ) {
        $withheld[] = $name;
        if ( in_array( $name, self::PERSONAL_META, true ) ) {
          $marked = true;
        }
        continue;
      }
      $value = (string) $meta['meta_value'];
      if ( $this->looks_serialised( $value ) ) {
        $out[ $name ] = '[stored as a serialised value, not printed]';
        continue;
      }
      if ( strlen( $value ) > self::META_VALUE_CHARS ) {
        $value = substr( $value, 0, self::META_VALUE_CHARS ) . '… [truncated]';
      }
      $out[ $name ] = $value;
    }

    $extra = [];
    if ( $withheld ) {
      $extra['withheld_metadata'] = array_values( array_unique( $withheld ) );
    }
    if ( $marked ) {
      $extra['metadata_note'] = 'Some metadata on this record concerns a person and is not returned. The names are listed in withheld_metadata. Measured on Stream 4.4.0, a record for a created user carries the new account\'s email address in user_meta, which is why that name is not on the allowlist. Read the record on Stream\'s own screen if you need the detail.';
    }

    return [ (object) $out, $extra ];
  }

  /** Whether a value is a PHP-serialised structure. */
  private function looks_serialised( string $value ): bool {
    return (bool) preg_match( '/^(a|O|s|i|b|d):\d+:/', $value );
  }

  #endregion

  #region Input

  private function window_days( array $args ): int {
    $days = isset( $args['days'] ) ? (int) $args['days'] : 7;
    if ( $days < 1 ) {
      $days = 7;
    }
    return min( $days, self::MAX_WINDOW_DAYS );
  }

  private function limit( array $args ): int {
    $limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
    if ( $limit < 1 ) {
      $limit = self::DEFAULT_LIMIT;
    }
    return min( $limit, self::MAX_LIMIT );
  }

  /**
  * The site whose records these tools report.
  *
  * Stream keeps one network-wide table pair with a blog_id column, so every query filters on
  * it. Without that filter a caller on one site of a network would list and count every other
  * site's records, including summaries that name their users. Not verified on a multisite
  * install: this stack is single-site, where every row is blog 1 and the filter changes
  * nothing, so the branch that matters here is the one this cannot exercise.
  */
  private function blog_id(): int {
    return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
  }

  /**
  * The WHERE clause and its parameters, or a message explaining why the input was refused.
  *
  * A date without a zone is refused rather than guessed: the site timezone and UTC differ by
  * hours on most sites, and a guess produces a window that is quietly off by that much.
  */
  private function build_where( array $args ): array {
    // Stream keeps one network-wide table pair with a blog_id column, so every query is
    // scoped to the site making the call. Without this a caller on one site of a network
    // would list and read every other site's records, including summaries that name their
    // users. Not verified on a multisite install — this stack is single-site, where every
    // row is blog 1 and the filter is a no-op.
    $where = [ 'blog_id = %d' ];
    $params = [ $this->blog_id() ];
    $since_ts = null;
    $until_ts = null;

    foreach ( [ 'connector', 'context', 'action' ] as $column ) {
      if ( isset( $args[ $column ] ) && $args[ $column ] !== '' ) {
        $where[] = $column . ' = %s';
        $params[] = (string) $args[ $column ];
      }
    }
    if ( isset( $args['user_id'] ) ) {
      $where[] = 'user_id = %d';
      $params[] = (int) $args['user_id'];
    }
    if ( isset( $args['object_id'] ) ) {
      $where[] = 'object_id = %d';
      $params[] = (int) $args['object_id'];
    }

    foreach ( [ 'since' => '>=', 'until' => '<' ] as $key => $op ) {
      if ( !isset( $args[ $key ] ) || $args[ $key ] === '' ) {
        continue;
      }
      $ts = $this->parse_timestamp( (string) $args[ $key ] );
      if ( is_string( $ts ) ) {
        return [ [], [], $ts ];
      }
      // A `since` before the window is refused rather than clamped: the caller asked for a
      // range this tool does not offer, and silently moving the start would answer a
      // different question. The cap is on the RANGE argument, not on the log's reach —
      // paging back with before_id past a window's worth of records is deliberate, and the
      // admin level is what makes it acceptable. The first version of this comment claimed
      // the cap stopped paging through the whole history, which it does not.
      if ( $ts < time() - ( self::MAX_WINDOW_DAYS * DAY_IN_SECONDS ) ) {
        return [ [], [], 'A date older than ' . self::MAX_WINDOW_DAYS . ' days is refused. This tool takes a range of at most ' . self::MAX_WINDOW_DAYS . ' days, and a start date before that is a range it does not offer. Paging back through older records with before_id is still possible, at admin level; Stream\'s own screen is the other way to reach them.' ];
      }
      if ( $key === 'since' ) {
        $since_ts = $ts;
      } else {
        $until_ts = $ts;
      }
      // created is a UTC datetime, not a timestamp, so the comparison is a string one.
      $where[] = 'created ' . $op . ' %s';
      $params[] = gmdate( 'Y-m-d H:i:s', $ts );
    }
    if ( $since_ts !== null && $until_ts !== null && $until_ts <= $since_ts ) {
      // Refused rather than returned empty. An empty list here would read as "no records in
      // that period", and the real answer is that the period does not exist.
      return [ [], [], 'The until date is not after the since date, so the range is empty. Nothing would be returned and that would read as "no records", which is a different fact.' ];
    }

    return [ $where, $params, null ];
  }

  /** An ISO 8601 timestamp as an integer, or a message explaining what was wrong. */
  private function parse_timestamp( string $value ) {
    if ( !preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
      return 'The date "' . $value . '" has no timezone. Write it in ISO 8601 with an explicit zone, such as 2026-09-01T00:00:00Z, so it is not read as site time on one interpretation and UTC on another.';
    }
    // Parsed strictly rather than with strtotime, which accepts relative input and silently
    // rolls an impossible date like 2026-02-31 into March. A date this tool cannot read
    // exactly is refused, because the alternative is a window nobody asked for.
    $parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:sP', $value )
      ?: \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $value )
      ?: \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s.uP', $value );
    if ( !$parsed ) {
      return 'The date "' . $value . '" could not be read as an exact time. Write it as ISO 8601 with seconds and an explicit zone, such as 2026-09-01T00:00:00Z.';
    }
    $errors = \DateTimeImmutable::getLastErrors();
    if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
      return 'The date "' . $value . '" is not a real time — for example a day that does not exist in that month. It is refused rather than moved to the nearest valid date, because a moved date is a window nobody asked for.';
    }
    return $parsed->getTimestamp();
  }

  #endregion

  #region Reading

  /** A count grouped by a column, or the error as a string. */
  private function counted( string $sql, array $params ) {
    $rows = $this->rows( $sql, $params );
    if ( is_string( $rows ) ) {
      return $rows;
    }
    $out = [];
    foreach ( $rows as $row ) {
      $out[] = [ 'k' => $row['k'], 'n' => (int) $row['n'] ];
    }
    return $out;
  }

  /** Rows as arrays, or the database error as a string. */
  private function rows( string $sql, array $params ) {
    global $wpdb;
    $prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
    $wpdb->last_error = '';
    $rows = $wpdb->get_results( $prepared, ARRAY_A );
    if ( $wpdb->last_error !== '' ) {
      return $wpdb->last_error;
    }
    if ( $rows === null ) {
      return 'the query returned nothing and reported no rows';
    }
    return is_array( $rows ) ? $rows : [];
  }

  /** Counts grouped by a column as a name => count object. */
  private function pairs( array $rows ): object {
    $out = [];
    foreach ( $rows as $row ) {
      $out[ (string) $row['k'] ] = $row['n'];
    }
    return (object) $out;
  }

  #endregion

  #region Helpers

  /** A UTC datetime as a unix timestamp, or null when it cannot be read. */
  private function to_timestamp( string $value ): ?int {
    if ( $value === '' || $value === '0000-00-00 00:00:00' ) {
      return null;
    }
    $parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
    return $parsed ? $parsed->getTimestamp() : null;
  }

  private function iso( ?int $timestamp ): ?string {
    return $timestamp === null ? null : gmdate( 'c', $timestamp );
  }

  /**
  * How recent a timestamp is, as a bucket rather than an instant.
  *
  * Used where a caller may not be given the exact time: the buckets keep the operational
  * meaning — is the log live, is it stale — without the minute, which for a login record is
  * a fact about when a person was at their desk.
  */
  private function recency_bucket( int $timestamp ): string {
    $age = max( 0, time() - $timestamp );
    if ( $age < HOUR_IN_SECONDS ) {
      return 'within the last hour';
    }
    if ( $age < DAY_IN_SECONDS ) {
      return 'within the last day';
    }
    if ( $age < 7 * DAY_IN_SECONDS ) {
      return 'within the last week';
    }
    if ( $age < 30 * DAY_IN_SECONDS ) {
      return 'within the last month';
    }
    return 'older than a month';
  }

  /**
  * Whether the caller of this request holds an admin-level credential.
  *
  * The level is published by the server when it authenticates a key. Defaulting to admin
  * when it is absent is the permissive direction; that is safe only because these tools are
  * reachable solely through the server, which sets the level whenever it authenticates.
  */
  private function caller_is_admin(): bool {
    return 'admin' === (string) apply_filters( 'gmcp_caller_access_level', 'admin' );
  }

  private function error( array $r, string $message, int $code = -32602 ): array {
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message . ' [error ' . $code . ']' ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }

  private function text( array $r, string $message ): array {
    $r['result'] = [ 'content' => [ [ 'type' => 'text', 'text' => $message ] ] ];
    return $r;
  }

  private function json( array $r, $data ): array {
    return $this->text( $r, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
  }

  #endregion
}
