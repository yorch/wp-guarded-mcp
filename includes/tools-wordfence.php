<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Wordfence, read from the outside: is the site being watched, and is the watcher working.
*
* This group answers the question an agent is asked first and could not answer at all before
* it existed — "is this site okay" — from the security plugin's own state rather than from a
* guess about the WordPress version. It is the second group of its kind, after WP Activity
* Log, and the two are different instruments: the log answers "what happened", this answers
* "what is set up to notice, and what has it noticed".
*
* READ ONLY, AND THAT IS THE WHOLE SLICE. There is no write tool here and there will not be
* one. Every write Wordfence offers is a way to make the watcher quieter or to hand it an
* instruction: dismissing an issue hides a real finding from the person who would act on it,
* whitelisting an IP exempts an address from every rule, switching the firewall out of
* blocking mode turns protection off, and changing a scan setting decides what is never
* looked at. An agent that can do any of those and then read the state back can confirm its
* own silence, which is the same defect the WP Activity Log group exists to prevent.
*
* The option writer does not reach any of it, and that is measured rather than assumed.
* Wordfence 9.0.2 keeps its entire configuration in its own table (wp_wfconfig, read through
* wfConfig::get), not in wp_options: a wp option list on a fresh install holds only
* wordfence_version, wordfence_ls_version, wordfence_installed, wordfenceActivated,
* wordfence_case and wf_plugin_act_error. Writing wordfence_version through wp_update_option
* succeeds and then does not stick, because Wordfence rewrites it on load — a write that
* reports success and changes nothing, which is worth knowing before anyone adds a guard
* that would protect nothing. What protects this group is that it has no writers, not a
* prefix rule.
*
* WHERE A ZERO COMES FROM. Measured on a fresh install: wp_wfissues, wp_wfblocks7 and
* wfhits all hold zero rows, and wfConfig has no lastScanCompleted row at all. "No issues"
* there means no scan has ever run, which is the opposite of "the site is clean", and the
* two are indistinguishable from the counts alone. So every reply carries a scan block whose
* last_completed is never / ok / failed, taken from the same key Wordfence's own status
* widget reads (wordfenceClass::ajax_activityLogUpdate_callback reads lastScanCompleted and
* compares it to 'ok'), and the count of issues is only reported next to it. This is the
* third time a third-party API has answered "none" for "not running" in this repository —
* Gravity Forms' missing tables and Elementor Pro's on-demand tables were the first two —
* and the fix is the same: report the state, not just the number.
*
* WHAT IS NEVER RETURNED.
*
* No configuration value that is a credential or a person. wp_wfconfig holds apiKey (the
* Wordfence Central / API key), encKey and longEncKey (the encryption keys for stored
* config), cbl_cookieVal (the country-blocking bypass cookie), whitelistHash, and
* alertEmails (the address alerts are sent to). None of those is named anywhere in this
* file, so none can be returned by a future edit that adds "just one more field".
*
* No IP address, at any level, and no list of them. Same reasoning as the WP Activity Log
* group: an IP is a fact about a person, not about the site, and this group has no write
* tool, so a list of them cannot feed any action this plugin can take. The lockout tool
* exists for the operator who is being brute-forced and needs to see what is blocked, and it
* is admin level for that reason; the briefing counts lockouts without listing them.
*
* No issue's long message or data blob. wp_wfissues.longMsg and .data carry absolute
* filesystem paths and, for a malware finding, the matched content itself. A read tool that
* returns them hands a model the server's layout and the payload it is looking for, and this
* plugin's audit log would then hold both for its retention window. shortMsg is the finding;
* longMsg is the evidence, and the evidence belongs on Wordfence's own screen.
*
* No tool starts a scan, and none is planned. A scan makes outbound requests to Wordfence's
* servers, can run for a long time on a shared host, and is the operator's decision on a
* schedule they chose. Reporting that no scan has run is this group's job; making one run is
* not.
*/
class GMCP_Tools_Wordfence {

  /** Issue table, without a prefix. Wordfence names it through wfDB::networkTable(). */
  const T_ISSUES = 'wfIssues';

  /** Lockout table, without a prefix. */
  const T_BLOCKS = 'wfblocks7';

  /** Wordfence's own progress log. Read only to count recent rows, never for its message. */
  const T_STATUS = 'wfStatus';

  /**
  * Scan stage states, as Wordfence actually reports them.
  *
  * Measured from wfScanner's own constants on 9.0.2: pending, running, running-warning,
  * complete-success, complete-warning, premium, disabled. The first version of this list was
  * written from memory and was wrong in both directions — it carried 'done', 'skipped' and
  * 'failed', which Wordfence never reports, so every finished stage came back as
  * unrecognised:complete-success; and it was missing 'running-warning', which is a running
  * scan. Reported as-is rather than translated, because 'premium' is a real answer: the stage
  * does not exist on this licence, so the scan does not cover it.
  */
  const STAGE_STATUSES = [ 'pending', 'running', 'running-warning', 'complete-success', 'complete-warning', 'premium', 'disabled' ];

  /**
  * Severity names to the numbers wfissues.severity actually stores.
  *
  * The column is a tinyint holding 0, 25, 50, 75 or 100 (wfIssues::SEVERITY_NONE through
  * SEVERITY_CRITICAL, measured on 9.0.2). A name is accepted from the caller and converted
  * here because MySQL compares 'high' against a tinyint as 0, so the first version's
  * name filter silently matched severity-0 rows or nothing at all — a confident "no
  * high-severity findings" that was really a type mismatch.
  */
  const SEVERITIES = [
    'none'     => 0,
    'low'      => 25,
    'medium'   => 50,
    'high'     => 75,
    'critical' => 100,
  ];

  /**
  * The block types wfblocks7 stores, by number, from wfBlock's own constants.
  *
  * The table holds nine kinds of block, and only five of them are about one address. A
  * country block (3) and a pattern block (4) store an all-zero IP, which came back through
  * inet_ntop as '::' and was presented as an address; a rate block (5, 6) is a rule rather
  * than a blocked host. Naming them is what lets the reply be honest about which is which.
  */
  const BLOCK_TYPES = [
    1 => 'ip_manual',
    2 => 'wfsn_temporary',
    3 => 'country',
    4 => 'pattern',
    5 => 'rate_block',
    6 => 'rate_throttle',
    7 => 'lockout',
    8 => 'ip_automatic_temporary',
    9 => 'ip_automatic_permanent',
  ];

  /** The block types that name one address, which are the only ones wf_lockouts lists. */
  const IP_BLOCK_TYPES = [ 1, 2, 7, 8, 9 ];

  /** A page of issues or lockouts. */
  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 100;

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
  * Whether Wordfence is loaded, checked per call.
  *
  * The version constant plus the two classes every reader here calls. A plugin can be
  * deactivated between the group being switched on and a call arriving, and Wordfence
  * defines WORDFENCE_VERSION at file load, so the constant alone would still resolve on a
  * copy that bailed out later.
  */
  private function loaded(): bool {
    return defined( 'WORDFENCE_VERSION' )
      && class_exists( 'wfConfig' )
      && class_exists( 'wfScanner' );
  }

  /**
  * One Wordfence setting, read through its own accessor.
  *
  * wfConfig::get() answers the default for a row that does not exist, which is why the
  * caller passes a sentinel rather than null: "this row is absent" and "this row is empty"
  * are different facts, and Wordfence uses the absence of lastScanCompleted to mean "no
  * scan has ever completed".
  */
  private function config( string $name, $default = null ) {
    if ( !$this->loaded() ) {
      return $default;
    }
    return wfConfig::get( $name, $default );
  }

  private function tools(): array {
    return [
      'wf_status' => [
        'name' => 'wf_status',
        'description' => 'Report whether Wordfence is watching this site and whether the watching is working: version and licence, whether the firewall is enabled and in which mode, whether login security is on and at what thresholds, whether scheduled scans are on, whether a scan is running, whether a scan has ever COMPLETED, the counts of scan issues by status and severity, how many blocks are in force by type, and a warnings list for the states that mean protection is weaker than it looks (firewall in learning mode, no completed scan, automatic updates off, scanning failed). Read level and counts only: it never returns an IP address, an issue\'s long message or data blob, the message a failed scan recorded, or any Wordfence setting that is a credential or a person, because those live in the same configuration table as the ones it reports. Use wf_scan_issues and wf_lockouts for rows; both are admin level. Changes nothing.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
        'accessLevel' => 'read',
      ],
      'wf_scan_issues' => [
        'name' => 'wf_scan_issues',
        'description' => 'List the findings from Wordfence\'s last scan, newest first: id, when it was found, whether it is new or already being ignored, the issue type, its severity, and its short message. Admin level, because a finding names things about this site that are worth keeping behind the same door as this plugin\'s own log — a suspicious-admin-user issue names an account, and a file-change issue names a path. What is NOT returned, at any level: the issue\'s long message and its data blob. Those carry absolute filesystem paths and, for a malware finding, the matched content itself; the short message is the finding and the evidence stays on Wordfence\'s screen. If no scan has ever completed there are no rows and this tool says so rather than reporting an empty, clean-looking list.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [
              'type' => 'string',
              'enum' => [ 'new', 'ignoreP', 'ignoreC' ],
              'description' => 'Only issues in this state: new, ignoreP (ignored permanently) or ignoreC (ignored until it changes). Omit for all of them.',
            ],
            'severity' => [
              'type' => 'string',
              'enum' => [ 'none', 'low', 'medium', 'high', 'critical' ],
              'description' => 'Only issues of this severity, by name. The column stores a number (0, 25, 50, 75, 100), so the name is converted here rather than passed through: MySQL compares a word against a tinyint as 0, which would return severity-0 rows or nothing and read as "no findings at that level".',
            ],
            'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Rows to return. Default 20, maximum 100.' ],
            'offset' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Rows to skip, newest first.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wf_lockouts' => [
        'name' => 'wf_lockouts',
        'description' => 'List the IP addresses Wordfence has blocked, newest first: the address, the kind of block, the reason, when it was blocked and last seen, how many times it was seen, and when the block expires. Only the block types that name one address are listed — manual and automatic IP blocks, and lockouts — because a country or pattern block stores an all-zero address and a rate block is a rule rather than a blocked host; those are counted by type in wf_status instead. Admin level and not read level, because an IP address is a fact about a person rather than about the site, and this group deliberately returns no address at all to a read key. "Active" means the block has not expired yet. The block parameters are not returned: they are a serialised structure whose shape belongs to Wordfence, and reading one out is how a reader ends up printing whatever a future version puts in it. If nothing matches this returns an empty list with a note saying so, which is a real answer rather than a failure.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'active_only' => [ 'type' => 'boolean', 'description' => 'Only blocks that have not expired yet. Default false, which lists the log including expired entries.' ],
            'type' => [ 'type' => 'string', 'enum' => [ 'ip_manual', 'wfsn_temporary', 'lockout', 'ip_automatic_temporary', 'ip_automatic_permanent' ], 'description' => 'Only this kind of block. Only the types that name one address are listed: country, pattern and rate blocks have no single address, so they are counted in wf_status rather than returned here.' ],
            'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Rows to return. Default 20, maximum 100.' ],
            'offset' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Rows to skip, newest first.' ],
          ],
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

    // The plugin has to be loaded for any of this, including wf_status: the status tool's
    // job is to say whether Wordfence is there, and it can only say that from inside a class
    // that runs. So the check is a report rather than a gate, and every tool answers.
    try {
      switch ( $tool ) {
        case 'wf_status':      $r = $this->status( $args, $r ); break;
        case 'wf_scan_issues': $r = $this->scan_issues( $args, $r ); break;
        case 'wf_lockouts':    $r = $this->lockouts( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'Wordfence threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }

    return $r;
  }

  #region Tools

  private function status( array $args, array $r ): array {
    $state = $this->watch_state();

    $out = [
      'wordfence' => [
        'loaded' => $this->loaded(),
        'version' => defined( 'WORDFENCE_VERSION' ) ? (string) WORDFENCE_VERSION : null,
        'waf_version' => defined( 'WFWAF_VERSION' ) ? (string) WFWAF_VERSION : null,
        'build' => defined( 'WORDFENCE_BUILD_NUMBER' ) ? (string) WORDFENCE_BUILD_NUMBER : null,
      ],
      'watch_state' => $state,
      'licence' => [
        'paid' => (bool) $this->config( 'isPaid', false ),
        'type' => $this->scalar_config( 'licenseType' ),
        'note' => 'A free licence is not a fault. It is reported because scan coverage depends on it: stages that only exist on a paid licence report their status as premium, and a reader who does not know that reads premium as unfinished.',
      ],
      'firewall' => $this->firewall_state(),
      'login_security' => [
        'enabled' => (bool) $this->config( 'loginSecurityEnabled', false ),
        'max_failures' => $this->int_config( 'loginSec_maxFailures' ),
        'lockout_minutes' => $this->int_config( 'loginSec_lockoutMins' ),
        'note' => 'Thresholds only. Wordfence stores the addresses it has locked out in its own table and this group returns them from wf_lockouts at admin level, never here.',
      ],
      'scanning' => $this->scan_state(),
      'blocking' => $this->blocking_state(),
    ];

    $out['warnings'] = $this->warnings( $out );
    if ( !$out['warnings'] ) {
      $out['warnings_note'] = 'No state here is one this plugin treats as weaker-than-it-looks. That is a statement about the configuration, not about the site: it says the firewall, the scanner and login security are set up to notice, not that nothing has happened.';
    }

    return $this->json( $r, $out );
  }

  private function scan_issues( array $args, array $r ): array {
    if ( !$this->loaded() ) {
      return $this->json( $r, [
        'scan' => [ 'last_completed' => 'unknown', 'plugin_loaded' => false ],
        'issues' => null,
        'note' => 'Wordfence is not loaded, so there is no scan result to read. This is not the same as a site with no findings.',
      ] );
    }

    $table = $this->table( self::T_ISSUES );
    if ( !$this->table_exists( $table ) ) {
      return $this->json( $r, [
        'scan' => $this->scan_state(),
        'issues' => null,
        'note' => 'Wordfence is loaded but its issues table does not exist (' . $table . '), which is what an install that never finished activating looks like. There is no scan result to read, and that is not the same as no findings.',
      ] );
    }

    $where = [];
    $params = [];
    if ( isset( $args['status'] ) && $args['status'] !== '' ) {
      $where[] = 'status = %s';
      $params[] = (string) $args['status'];
    }
    if ( isset( $args['severity'] ) && $args['severity'] !== '' ) {
      $name = strtolower( (string) $args['severity'] );
      if ( !isset( self::SEVERITIES[ $name ] ) ) {
        // Refused rather than passed through, because passing a word into a numeric column
        // is the defect this mapping exists to prevent.
        return $this->error( $r, 'Unknown severity "' . $args['severity'] . '". Use one of: ' . implode( ', ', array_keys( self::SEVERITIES ) ) . '.' );
      }
      $where[] = 'severity = %d';
      $params[] = self::SEVERITIES[ $name ];
    }
    $limit = $this->limit( $args );
    $offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

    // shortMsg only. longMsg and data are the evidence, and they carry absolute paths and
    // matched payloads; they are named nowhere in this file on purpose.
    $sql = 'SELECT id, time, status, type, severity, shortMsg FROM ' . $table
      . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
      . ' ORDER BY time DESC, id DESC LIMIT %d OFFSET %d';
    $rows = $this->rows( $sql, array_merge( $params, [ $limit + 1, $offset ] ) );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'Wordfence\'s issues could not be read: ' . $rows );
    }

    $truncated = count( $rows ) > $limit;
    if ( $truncated ) {
      array_pop( $rows );
    }

    $issues = [];
    foreach ( $rows as $row ) {
      $code = (int) $row['severity'];
      $issues[] = [
        'id' => (int) $row['id'],
        'found' => $this->iso( (int) $row['time'] ),
        'status' => (string) $row['status'],
        'type' => (string) $row['type'],
        'severity' => [
          'code' => $code,
          'name' => array_search( $code, self::SEVERITIES, true ) ?: null,
        ],
        'message' => wp_strip_all_tags( (string) $row['shortMsg'] ),
      ];
    }

    $scan = $this->scan_state();
    $out = [
      'scan' => $scan,
      'count' => count( $issues ),
      'offset' => $offset,
      'truncated' => $truncated,
      'issues' => $issues,
      'withheld' => [ 'longMsg', 'data' ],
    ];
    if ( $truncated ) {
      $out['next_offset'] = $offset + count( $issues );
    }
    // The row count is only meaningful next to whether a scan ever ran. Said here as well
    // as in wf_status, because this is the reply a reader gets when they asked for findings.
    if ( $scan['last_completed'] === 'never' ) {
      $out['note'] = 'No scan has ever completed on this site, so this list is empty because nothing has looked — not because nothing was found.';
    }

    return $this->json( $r, $out );
  }

  private function lockouts( array $args, array $r ): array {
    if ( !$this->loaded() ) {
      return $this->json( $r, [ 'lockouts' => null, 'note' => 'Wordfence is not loaded, so it is not locking anything out.' ] );
    }

    $table = $this->table( self::T_BLOCKS );
    if ( !$this->table_exists( $table ) ) {
      return $this->json( $r, [ 'lockouts' => null, 'note' => 'Wordfence is loaded but its lockout table does not exist (' . $table . '), so there is no lockout record to read.' ] );
    }

    $where = [];
    $params = [];
    // Only the block types that name one address. A country block and a pattern block store
    // an all-zero IP, which inet_ntop renders as '::' and the first version presented as an
    // address; a rate block is a rule rather than a blocked host. Listing them here would be
    // an address-shaped value that is not an address.
    $where[] = 'type IN (' . implode( ',', array_map( 'intval', self::IP_BLOCK_TYPES ) ) . ')';
    if ( isset( $args['type'] ) && $args['type'] !== '' ) {
      $wanted = strtolower( (string) $args['type'] );
      $code = array_search( $wanted, self::BLOCK_TYPES, true );
      if ( $code === false || !in_array( $code, self::IP_BLOCK_TYPES, true ) ) {
        return $this->error( $r, 'Unknown block type "' . $args['type'] . '". This tool lists the types that name one address: ' . implode( ', ', array_map( static function ( $t ) {
          return self::BLOCK_TYPES[ $t ];
        }, self::IP_BLOCK_TYPES ) ) . '. Country, pattern and rate blocks have no single address and are not listed here; their counts are in wf_status.' );
      }
      $where[] = 'type = %d';
      $params[] = (int) $code;
    }
    if ( !empty( $args['active_only'] ) ) {
      // Wordfence stores expiration as a unix timestamp, and 0 means "does not expire",
      // which is what a permanent block looks like. Written as an explicit OR rather than
      // "> now" so a permanent block is not reported as expired.
      $where[] = '(expiration = 0 OR expiration > %d)';
      $params[] = time();
    }
    $limit = $this->limit( $args );
    $offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

    // parameters is not selected. It is a serialised structure whose shape belongs to
    // Wordfence, and printing whatever a future version puts in it is how a reader becomes
    // a leak.
    $sql = 'SELECT id, type, IP, blockedTime, reason, lastAttempt, blockedHits, expiration FROM ' . $table
      . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
      . ' ORDER BY blockedTime DESC, id DESC LIMIT %d OFFSET %d';
    $rows = $this->rows( $sql, array_merge( $params, [ $limit + 1, $offset ] ) );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'Wordfence\'s lockouts could not be read: ' . $rows );
    }

    $truncated = count( $rows ) > $limit;
    if ( $truncated ) {
      array_pop( $rows );
    }

    $now = time();
    $out_rows = [];
    foreach ( $rows as $row ) {
      $expiration = (int) $row['expiration'];
      $out_rows[] = [
        'id' => (int) $row['id'],
        'ip' => $this->readable_ip( (string) $row['IP'] ),
        'type' => (int) $row['type'],
        'type_name' => self::BLOCK_TYPES[ (int) $row['type'] ] ?? ( 'unknown:' . (int) $row['type'] ),
        'reason' => (string) $row['reason'],
        'blocked' => $this->iso( (int) $row['blockedTime'] ),
        'last_seen' => (int) $row['lastAttempt'] > 0 ? $this->iso( (int) $row['lastAttempt'] ) : null,
        'hits' => (int) $row['blockedHits'],
        'expires' => $expiration === 0 ? 'never' : $this->iso( $expiration ),
        'active' => $expiration === 0 || $expiration > $now,
      ];
    }

    $out = [
      'count' => count( $out_rows ),
      'offset' => $offset,
      'truncated' => $truncated,
      'lockouts' => $out_rows,
      'withheld' => [ 'parameters' ],
    ];
    if ( $truncated ) {
      $out['next_offset'] = $offset + count( $out_rows );
    }
    if ( !$out_rows ) {
      $out['note'] = empty( $args['active_only'] )
        ? 'Wordfence has no lockouts recorded on this site.'
        : 'No lockout is currently in force. An empty list here means nothing is blocked right now, which is not the same as nothing ever having been blocked; call without active_only to see the log.';
    }

    return $this->json( $r, $out );
  }

  #endregion

  #region State

  /**
  * What the plugin is, independent of what it has found.
  *
  * Shaped like the WP Activity Log group's log_state block, and for the same reason: the
  * three ways a zero can be wrong here are "the plugin is not loaded", "its tables were
  * never created" and "no scan has ever run", and all three look like a clean site from a
  * count of zero findings.
  */
  private function watch_state(): array {
    $state = [
      'tables' => [ 'present' => [], 'missing' => [] ],
      'readable' => false,
    ];

    if ( !$this->loaded() ) {
      $state['note'] = 'Wordfence is not loaded, so nothing on this site is being watched by it. This is not the same as a site with nothing to report.';
      return $state;
    }

    foreach ( [ self::T_ISSUES, self::T_BLOCKS ] as $suffix ) {
      $table = $this->table( $suffix );
      if ( $this->table_exists( $table ) ) {
        $state['tables']['present'][] = $suffix;
      } else {
        $state['tables']['missing'][] = $suffix;
      }
    }
    $state['readable'] = !$state['tables']['missing'];
    if ( !$state['readable'] ) {
      $state['note'] = 'Wordfence is loaded but its tables are incomplete (missing: ' . implode( ', ', $state['tables']['missing'] ) . '). That is what an install that never finished activating looks like, and it is not the same as a site with no findings.';
    }

    return $state;
  }

  /**
  * The firewall's mode, and what that mode means.
  *
  * waf_status is a string, and the value that matters is learning-mode: the firewall is on,
  * is learning what normal traffic looks like, and is NOT blocking. An operator who reads
  * "firewallEnabled: true" and stops there believes they are protected while nothing is
  * being blocked, which is the exact overclaim this block exists to prevent.
  */
  private function firewall_state(): array {
    $enabled = (bool) $this->config( 'firewallEnabled', false );
    $status = $this->scalar_config( 'waf_status' );

    $out = [
      'enabled' => $enabled,
      'status' => $status,
    ];
    if ( !$enabled ) {
      $out['meaning'] = 'The firewall is switched off, so requests are not being inspected at all.';
    } elseif ( $status === 'learning-mode' ) {
      $out['meaning'] = 'The firewall is enabled but is in learning mode: it is watching traffic and is not blocking anything yet. Protection is weaker than "enabled" suggests until the site leaves learning mode.';
    } elseif ( $status === '' || $status === null ) {
      $out['meaning'] = 'The firewall is enabled and Wordfence reports no mode. It is not possible from this value alone to say whether it is blocking, so treat it as unknown rather than as protected.';
    } else {
      $out['meaning'] = 'The firewall is enabled and reporting mode "' . $status . '".';
    }
    return $out;
  }

  /**
  * Whether the scanner has ever run, and what it left behind.
  *
  * lastScanCompleted is read exactly the way Wordfence's own status widget reads it
  * (wordfenceClass::ajax_activityLogUpdate_callback): 'ok' means a scan completed, absent or
  * empty means none ever has, and any other value is the failure message the scanner wrote.
  * The absence is the important one — it is what a fresh install looks like, and it is why
  * a count of zero issues must never be reported without it.
  */
  private function scan_state(): array {
    if ( !$this->loaded() ) {
      return [ 'last_completed' => 'unknown', 'plugin_loaded' => false ];
    }

    $marker = $this->config( 'lastScanCompleted', '__ABSENT__' );
    $out = [
      'scheduled' => (bool) $this->config( 'scheduledScansEnabled', false ),
      'type' => $this->scalar_config( 'scanType' ),
      'running' => false,
      'failed' => false,
      'issues' => null,
    ];

    if ( $marker === '__ABSENT__' || $marker === null || $marker === '' ) {
      $out['last_completed'] = 'never';
      $out['note'] = 'No scan has ever completed on this site. A count of zero findings here means nothing has looked, not that nothing is there.';
    } elseif ( $marker === 'ok' ) {
      $out['last_completed'] = 'ok';
    } else {
      $out['last_completed'] = 'failed';
      // The stored value is the exception message the scanner caught, and exception messages
      // from the filesystem or HTTP layers routinely carry absolute paths and hostnames —
      // the same content this group withholds from longMsg at every level. So a read key is
      // told that it failed and nothing more.
      if ( $this->caller_is_admin() ) {
        $out['failure'] = wp_strip_all_tags( (string) $marker );
      } else {
        $out['failure_withheld'] = 'The scanner recorded a failure message and it is not returned to a read key, because it is an exception message and those carry filesystem paths and hostnames. The fact that the last scan failed is above.';
      }
      $out['note'] = 'The last scan did not complete, so any findings below are from an earlier run.';
    }

    // The scanner's own view, which is richer than the marker: a run in progress, a run that
    // died part way, and the stage table. Each call is guarded, because Wordfence moves
    // these between versions and a missing method must not fatal a read.
    try {
      if ( class_exists( 'wfScanner' ) && method_exists( 'wfScanner', 'shared' ) ) {
        $scanner = wfScanner::shared();
        if ( method_exists( $scanner, 'lastScanTime' ) ) {
          $last = $scanner->lastScanTime();
          if ( $last ) {
            $out['last_scan_time'] = $this->caller_is_admin()
              ? $this->iso( (int) $last )
              : $this->recency_bucket( (int) $last );
          }
        }
        if ( method_exists( $scanner, 'stageStatus' ) ) {
          $stages = (array) $scanner->stageStatus();
          $summary = [];
          foreach ( $stages as $stage => $status ) {
            $status = (string) $status;
            // An unrecognised status is reported as itself rather than folded into pending,
            // because folding would describe a scan that is not running as one that is.
            $summary[ $stage ] = in_array( $status, self::STAGE_STATUSES, true ) ? $status : 'unrecognised:' . $status;
          }
          $out['stages'] = (object) $summary;
          // Asked of the scanner rather than inferred from the stage names, because
          // 'running-warning' is a running scan and the first version of this only looked
          // for the literal 'running', so a scan that had hit a warning was reported as not
          // running and the warning that says so was suppressed.
          $out['running'] = method_exists( $scanner, 'isRunning' ) ? (bool) $scanner->isRunning() : in_array( 'running', $summary, true );
        }
      }
      if ( class_exists( 'wfIssues' ) && method_exists( 'wfIssues', 'shared' ) ) {
        $issues = wfIssues::shared();
        if ( method_exists( $issues, 'hasScanFailed' ) ) {
          $out['failed'] = (bool) $issues->hasScanFailed();
        }
      }
    } catch ( \Throwable $e ) {
      // An exception message is not returned to a read key, for the reason the failure text
      // above is not: it can name a path or a host.
      $out['scanner_error'] = $this->caller_is_admin()
        ? $e->getMessage()
        : 'the scanner threw an error; its message is not returned to a read key';
    }

    $out['issues'] = $this->issue_counts();
    return $out;
  }

  /** Issue counts by status and severity, read straight from the table. */
  private function issue_counts() {
    $table = $this->table( self::T_ISSUES );
    if ( !$this->table_exists( $table ) ) {
      return null;
    }
    $rows = $this->rows( 'SELECT status, severity, COUNT(*) AS n FROM ' . $table . ' GROUP BY status, severity', [] );
    if ( is_string( $rows ) ) {
      // A failed query is not a zero. Returned as an error string so the caller can say so
      // rather than reporting a clean site.
      return [ 'error' => $rows ];
    }
    $by_status = [];
    $by_severity = [];
    $total = 0;
    foreach ( $rows as $row ) {
      $n = (int) $row['n'];
      $total += $n;
      $by_status[ (string) $row['status'] ] = ( $by_status[ (string) $row['status'] ] ?? 0 ) + $n;
      $by_severity[ (string) $row['severity'] ] = ( $by_severity[ (string) $row['severity'] ] ?? 0 ) + $n;
    }
    return [
      'total' => $total,
      'by_status' => (object) $by_status,
      'by_severity' => (object) $by_severity,
    ];
  }

  /** How much is blocked right now, and how much has been. Counts only. */
  private function blocking_state(): array {
    $table = $this->table( self::T_BLOCKS );
    if ( !$this->table_exists( $table ) ) {
      return [ 'blocks_by_type' => null, 'active_ip_blocks' => null, 'note' => 'The block table does not exist, so there is nothing to count.' ];
    }
    $in = implode( ',', array_map( 'intval', self::IP_BLOCK_TYPES ) );
    $by_type = $this->rows( 'SELECT type, COUNT(*) AS n FROM ' . $table . ' GROUP BY type', [] );
    $active = $this->rows(
      'SELECT COUNT(*) AS n FROM ' . $table . ' WHERE type IN (' . $in . ') AND ( expiration = 0 OR expiration > %d )',
      [ time() ]
    );
    if ( is_string( $by_type ) || is_string( $active ) ) {
      return [
        'blocks_by_type' => null,
        'active_ip_blocks' => null,
        'error' => is_string( $by_type ) ? $by_type : $active,
        'note' => 'The block counts could not be read, so they are not reported. A zero here would read as "nothing is blocked".',
      ];
    }
    $types = [];
    foreach ( $by_type as $row ) {
      $code = (int) $row['type'];
      $types[ self::BLOCK_TYPES[ $code ] ?? ( 'unknown:' . $code ) ] = (int) $row['n'];
    }
    return [
      // Named as blocks rather than lockouts, because wfblocks7 holds nine kinds and only
      // some of them are one address. The first version called the whole table "lockouts",
      // which overcounted on any site with country or pattern blocks.
      'blocks_by_type' => (object) $types,
      'active_ip_blocks' => (int) $active[0]['n'],
      'note' => 'Counts only, by block type. "Active" counts the block types that name one address (manual and automatic IP blocks, and lockouts) and that have not expired; country, pattern and rate blocks are counted in blocks_by_type but not as active addresses. The addresses themselves are in wf_lockouts, which is admin level: an IP is a fact about a person and this group returns none to a read key.',
    ];
  }

  /**
  * The states that mean protection is weaker than the settings suggest.
  *
  * Not a score and not a verdict: each entry is a sentence about one fact, so a reader can
  * disagree with one without discarding the rest. This is where the group earns its keep —
  * "firewall enabled" and "firewall blocking" are different claims and only the second is
  * protection.
  */
  private function warnings( array $status ): array {
    $out = [];
    $firewall = $status['firewall'];
    if ( !$firewall['enabled'] ) {
      $out[] = 'The firewall is switched off.';
    } elseif ( ( $firewall['status'] ?? '' ) === 'learning-mode' ) {
      $out[] = 'The firewall is in learning mode: it is watching but not blocking.';
    }

    $scan = $status['scanning'];
    if ( ( $scan['last_completed'] ?? '' ) === 'never' ) {
      $out[] = 'No scan has ever completed, so nothing has been checked on this site by Wordfence.';
    } elseif ( ( $scan['last_completed'] ?? '' ) === 'failed' ) {
      $out[] = 'The last scan did not complete. Read wf_status with an admin key for the message the scanner recorded.';
    }
    if ( empty( $scan['scheduled'] ) ) {
      $out[] = 'Scheduled scans are switched off, so nothing will scan this site unless somebody starts one.';
    }
    if ( !empty( $scan['running'] ) ) {
      $out[] = 'A scan is running right now, so the findings below are from a run that is not finished.';
    }

    if ( empty( $status['login_security']['enabled'] ) ) {
      $out[] = 'Login security is switched off, so there is no brute-force protection or 2FA enforcement from Wordfence.';
    }

    if ( !$this->config( 'autoUpdate', false ) ) {
      $out[] = 'Wordfence\'s own automatic updates are off. Wordfence ships firewall rules and vulnerability data, and a copy that is not updated stops recognising new attacks.';
    }

    if ( ( $status['licence']['type'] ?? '' ) === 'free' ) {
      $out[] = 'This site is on the free licence, so some scan stages do not exist (they report premium) and the real-time firewall rules are delayed rather than immediate.';
    }

    return $out;
  }

  #endregion

  #region Reading

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

  /**
  * A Wordfence table name for this network.
  *
  * Through wfDB::networkTable() when it exists, because Wordfence keeps these tables
  * network-wide on multisite and hardcoding base_prefix would be right by accident. The
  * fallback lowercases the suffix, which is what the measured names look like
  * (wp_wfissues, not wp_wfIssues) and what MySQL on a case-sensitive filesystem requires.
  */
  private function table( string $suffix ): string {
    global $wpdb;
    if ( class_exists( 'wfDB' ) && method_exists( 'wfDB', 'networkTable' ) ) {
      $name = wfDB::networkTable( $suffix );
      if ( is_string( $name ) && $name !== '' ) {
        return $name;
      }
    }
    return $wpdb->base_prefix . strtolower( $suffix );
  }

  /** Whether a table exists, by exact name. */
  private function table_exists( string $table ): bool {
    global $wpdb;
    // esc_like, because a table name contains underscores and LIKE treats each as a
    // single-character wildcard.
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
  }

  /**
  * A scalar config value, with Wordfence's absent sentinel turned into null.
  *
  * A value that is not a scalar is reported as null rather than cast: casting a serialised
  * structure prints it, and this reader has no business printing the inside of a setting.
  */
  private function scalar_config( string $name ): ?string {
    $value = $this->config( $name, '__ABSENT__' );
    if ( $value === '__ABSENT__' || $value === null || !is_scalar( $value ) ) {
      return null;
    }
    return (string) $value;
  }

  private function int_config( string $name ): ?int {
    $value = $this->scalar_config( $name );
    return $value === null || $value === '' ? null : (int) $value;
  }

  #endregion

  #region Helpers

  private function iso( int $timestamp ): string {
    return $timestamp > 0 ? gmdate( 'c', $timestamp ) : '';
  }

  /**
  * A stored IP address made printable, through Wordfence's own inverse.
  *
  * Measured the hard way: wfBlocks7 stores the address as packed binary (inet_pton of
  * '203.0.113.77' is the 16-byte ::ffff:cb00714d), so returning the column directly puts null
  * bytes and a stray 'qM' in a reply where an address belongs. wfUtils::inet_ntop() is the
  * function Wordfence's own screens use and it also strips the IPv4-mapped IPv6 prefix, which
  * is the difference between '203.0.113.77' and '::ffff:203.0.113.77'. A value that neither
  * function can read is reported as unreadable rather than printed raw, because a column of
  * bytes presented as an address is worse than no address at all.
  */
  private function readable_ip( string $raw ): string {
    if ( $raw === '' ) {
      return '';
    }
    if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' ) ) {
      $out = @wfUtils::inet_ntop( $raw );
      if ( is_string( $out ) && $out !== '' ) {
        return $out;
      }
    }
    $out = @inet_ntop( $raw );
    return is_string( $out ) && $out !== '' ? $out : '[address stored in a form this plugin cannot read]';
  }

  /**
  * How recent a timestamp is, as a bucket rather than an instant.
  *
  * Used where a caller may not be given the exact time: a precise scan time tells a read key
  * when an administrator last ran maintenance, which is the same behavioural signal the row
  * tools are admin for.
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

  private function limit( array $args ): int {
    $limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
    if ( $limit < 1 ) {
      $limit = self::DEFAULT_LIMIT;
    }
    return min( $limit, self::MAX_LIMIT );
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
