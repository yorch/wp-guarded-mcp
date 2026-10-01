<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* WP Activity Log, read from the outside, with a deliberate refusal to be a people-search.
*
* This group answers "what has been happening on this site", from the third-party log a
* security plugin keeps, rather than from this plugin's own record of its own tool calls.
* The two are different instruments: GMCP's log answers "what did the agent do" and this one
* answers "what did the site do", including the periods when no agent was talking to it.
*
* READ ONLY, AND THAT IS THE WHOLE SLICE. There is no write tool here and there will not be
* one. An audit log exists to be evidence; every writer that can reach it is a way to
* reduce what it holds, and this plugin already ships one generic option writer that could
* reach it. The prerequisite for this group is commit 87d96c0, which refuses option writes
* naming an activity log (GMCP_Core::option_write_policy() and the wsal_ prefix), because an
* agent that can silence the log and then read the log back can confirm its own silence.
*
* WHAT A LOG LIKE THIS IS, AND WHY IT IS NOT AN ORDINARY DATA SOURCE. Every row is a
* behaviour record: a timestamp, an action, and — for anything a person did — an account
* name, an IP address and a browser string. Read as a table it is a record of what happened;
* read as a set of persons it is a way to find out who administers the site and when they
* are at their desk. This plugin has no restore tool and no bulk export, and the reply to a
* read tool is copied into this plugin's own audit log for its full retention window, where
* nothing can remove it. So the design question is not "what does the table hold" but "what
* is worth making permanent, in two logs, to answer a question about site operations".
*
* WHAT IS HERE.
*
* wsal_events_briefing is the read-level tool and returns counts only: how many events by
* severity, object, event type and alert family, over a window of at most 90 days, together
* with a log_state block. Counts are not personal data, and the briefing is what an operator
* or an agent actually wants first — "was there a spike", "did anything happen at all".
*
* wsal_list_events and wsal_get_event are admin, matching this plugin's own log reader:
* wp_get_audit_log is admin, so a third-party log is not exposed more widely than the
* plugin's own record of itself. They return rows, and rows carry an actor, so they sit
* behind the same door.
*
* THE log_state BLOCK IS THE POINT OF THE BRIEFING, and this repo has now been bitten three
* times by a third-party API answering "none" for "not installed". This plugin's log records
* events; if the counters say zero, that means one of: nothing happened, the tables were
* never created because the plugin was installed and never activated, the log is configured
* to write to an external database so the local tables are legitimately empty, or the query
* failed and the result was read as empty. All four look identical as a number. So every
* reply carries whether the plugin is loaded, whether both tables exist, whether storage is
* local, what the log's own reach is (rows, how recent the newest event is, and for an admin
* caller the exact oldest and newest), whether the site prunes the log and if so after how
* long, and how many alerts are switched off. "No failed logins in 30 days" and "failed
* logins are not being recorded" are different answers, and only the block can tell them
* apart.
*
* Two things that look like external storage are not. An ARCHIVE connection moves records
* older than a chosen age out of the live table on a schedule; recent logging stays local,
* so treating it as external would refuse to read a log that is present and working and say
* something untrue about the site. And WSAL keeps its own settings in sitemeta on multisite,
* so they are read through WSAL's accessor rather than get_option(), which finds nothing
* there and would report local storage with a confident zero.
*
* WHAT IS DELIBERATELY ABSENT, and is not an omission to be helpfully filled in.
*
* No tool searches the log's text. An occurrence's human-readable message is not a column:
* it is rendered in PHP from a stored template plus that row's metadata, so there is nothing
* to search with an index, and a free-text search would have to be a LIKE across the
* metadata table. That table holds the same IP, browser string and session id the list tools
* omit, plus the serialised user objects. A search over it is an oracle: asking whether
* "diff@" appears anywhere confirms a value that no tool will return, one guess at a time.
* The filters here are structured columns only (alert id, severity, object, event type,
* actor, post, date range), which are the ones a caller can already see.
*
* No tool lists the IP addresses, at any level. This was cut rather than gated. An IP is not
* a fact about the site, it is a fact about a person, and this group has no write tool, so
* a list of them cannot feed any action this plugin is able to take. Its only use from an
* agent is identifying or locating someone, and because every reply is copied into this
* plugin's audit log it would make those addresses permanent in a chain with no deletion.
* The plugin's own screen serves the operator who genuinely needs them.
*
* No tool ranks users by activity. A per-account event count with a last-seen time is
* profiling, whatever it is called, and it is also wrong in a specific way: actions taken
* through the REST endpoint are recorded against the WordPress user the static bearer token
* borrows, so the ranking would attribute an agent's work to whichever human that token
* resolved to.
*
* No tool returns a person, and this is enforced by an allowlist rather than a denylist. The
* metadata on an event described the SITE, and only those names are returned: which post,
* which role, which plugin, which option row. Everything else is withheld by construction,
* and the names of what was withheld are listed so an absence is visible. This is the
* opposite of how it started, and the change was made because the denylist version was a
* credential-disclosure defect rather than an incomplete list: measured on this stack, a
* user-meta write (alert 4015) stores the field's name in `custom_field_name` and BOTH
* values in `new_value` and `old_value`, with no name anywhere that looks secret on its own.
* A reader that excluded by name returned a user's API token in plaintext, and its previous
* value beside it. The lesson is the one the message renderer already encoded: a list of
* things to allow is the only kind of list that stays correct when the data changes shape.
*
* No tool returns the alert catalogue. The interesting half of it is the list of
* alerts that are switched off, which together with a count of what was recorded is a map
* of what this site does not log. The generic half — what alert id 2001 means — travels
* with each row instead, as the alert id, its unfilled template and its severity name, so
* there is no separate tool whose purpose is to describe blind spots.
*
* No message is rendered with the plugin's own renderer. Two measured reasons, both in the
* plugin's code rather than in this one. Alert::get_message() appends the alert's metadata
* block to whatever it returns, and for a user event that block carries the first name, the
* last name and the email address; and it resolves a placeholder whose value is a serialised
* object by returning the serialised object itself, whose comment in the source reads "This
* isn't 100% correct". The wrapper on top of it, Occurrences_Entity::get_alert_message(),
* returns an empty string unless the caller has already loaded the metadata, so a reader
* that trusts it produces blank messages that look like results. This group fills the stored
* template itself, from an allowlist, and leaves every placeholder it does not recognise
* standing as literal text.
*
* The group is its own switch, mcp_tools_wp_activity_log, defaulting off, so an existing
* site is not handed a reader for a log it may not have agreed to keep.
*/
class GMCP_Tools_Wsal {

  /** The occurrence table, without a prefix. */
  const T_OCCURRENCES = 'wsal_occurrences';

  /** The metadata table, without a prefix. */
  const T_METADATA = 'wsal_metadata';

  /**
  * The severity codes the log actually writes, and their names.
  *
  * Hard-coded rather than asked of the plugin for two reasons. The column is a varchar
  * holding numbers, so a comparison has to be against a known list rather than a range —
  * '500' >= '1000' is true as text. And the plugin's own name lookup answers 'Unknown' for
  * a code it does not know, which is a value rather than a detected failure, so a code
  * outside this list is reported as an anomaly instead of being quietly labelled.
  */
  const SEVERITIES = [
    100 => 'Notification',
    200 => 'Informational',
    250 => 'Low',
    300 => 'Medium',
    400 => 'High',
    500 => 'Critical',
  ];

  /**
  * Alert families, as name => the alert ids that mean it.
  *
  * Explicit ids rather than a range or a prefix, because alert numbering in this plugin is
  * historical and grouped by subsystem rather than contiguous. The families are the four
  * questions worth asking about a site's security in one call.
  */
  const FAMILIES = [
    // Logins and logouts, including the failed attempts that matter.
    'logins'   => [ 1000, 1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 1009, 1010 ],
    // Password changes, on a user and on the site.
    'passwords' => [ 1000, 4001, 4002, 4003, 4004, 4005, 4006, 4007, 4008, 4009 ],
    // Role and capability changes, including user creation and deletion.
    'roles'    => [ 4000, 4001, 4010, 4011, 4012, 4013, 4014, 4015, 4016, 4017 ],
    // Plugin and theme installs, updates, activations and deletions.
    'plugins'  => [ 5000, 5001, 5002, 5003, 5004, 5005, 5006, 5007, 5010, 5011, 5012, 5013, 5014, 5015, 5016, 5017, 5018, 5022, 5023, 5024, 5050, 5051, 5052, 5053, 5054, 5055 ],
  ];

  /**
  * Alerts whose message is built from a username a visitor typed, so the filled message is
  * never returned for them.
  *
  * The failed-login alerts put the attempted username into %Users%. People mistype their
  * password into the username box often enough that the field regularly holds a plaintext
  * password, which is a credential. Those rows are still listed, with their name and time,
  * and their message is withheld rather than filled.
  */
  const LOGIN_ATTEMPT_ALERTS = [ 1002, 1003 ];

  /**
  * The metadata names the message filler is allowed to read, and nothing else is.
  *
  * An allowlist rather than a denylist because the metadata table is written by a plugin
  * this one does not control: a name absent from here is left as a literal placeholder, so
  * a new piece of metadata that a future version stores cannot arrive in a reply because
  * nobody remembered to exclude it. The scalar names are safe by being about the site
  * (a post, a role, a plugin), not about a person.
  */
  const FILLABLE = [
    'PostTitle', 'PostType', 'PostStatus', 'PostID', 'OldTitle', 'NewTitle',
    'OldStatus', 'NewStatus', 'OldRole', 'NewRole', 'OldValue', 'NewValue',
    'CustomFieldName', 'MetaKey', 'WidgetName', 'MenuName', 'WidgetType',
    'PluginName', 'ThemeName', 'File', 'FileName', 'EventType', 'Object',
    'ColorScheme', 'OptionName',
  ];

  /**
  * Nested names the filler may read out of a serialised object, as meta name => key.
  *
  * Only these two, and only by an explicit top-level key. The contents of a serialised value
  * are otherwise never read: this is the field that carries the user's email in
  * NewUserData, and a reader that walks it to find something is a reader that can return
  * everything in it.
  */
  const FILLABLE_NESTED = [
    'PluginData' => 'Name',
    'Theme'      => 'Name',
  ];

  /**
  * The metadata names wsal_get_event may return, and nothing else is.
  *
  * An ALLOWLIST, which is the same choice the message filler makes and for the same reason.
  * It used to be a denylist, and that was a credential-disclosure defect rather than a
  * missing entry: measured on this stack, a user-profile change (alert 4016) stores the
  * field's name in `custom_field_name` and its value in a companion key (`new_value`, or
  * `new_nickname` when the field is the nickname), with no name anywhere that looks secret,
  * so `wsal_get_event` returned the value in plaintext. A denylist can only exclude what
  * somebody remembered to name, and the value that matters is written under an innocent key,
  * so the reader has to be the one holding the list.
  *
  * What is here is what describes the SITE: which post, which role, which plugin, which
  * option row. Anything whose contents are a person, a credential or a filesystem path is
  * absent by construction rather than by being excluded afterwards.
  */
  const RETURNED_META = [
    'Object', 'EventType', 'EventID', 'Severity',
    'PostID', 'PostType', 'PostStatus', 'PostTitle', 'PostUrl', 'PostDate',
    'OldTitle', 'NewTitle', 'OldStatus', 'NewStatus',
    'OldRole', 'NewRole', 'Roles', 'AddedRoles', 'RemovedRoles',
    'PluginName', 'CurrentPluginVersion', 'NewPluginVersion',
    // These two hold serialised objects. They are allowed through only to be resolved to a
    // single scalar — the plugin's or theme's display name — and never printed as stored.
    'PluginData', 'Theme',
    'ThemeName', 'WidgetName', 'WidgetType', 'MenuID', 'MenuName',
    'AttachmentID', 'CommentID', 'CommentStatus', 'MetaID',
    'CurrentUserID', 'TargetUserID', 'NewUserID',
  ];

  /**
  * Metadata names that describe a person, listed so the reply can say the row held them.
  *
  * This is not the enforcement — RETURNED_META is. It exists so a reader who sees the key
  * named in withheld_metadata knows the event involved a person without being handed the
  * person, which is the same shape the message withholding already uses.
  */
  const PERSONAL_META = [
    'FirstName', 'LastName', 'new_firstname', 'old_firstname',
    'new_displayname', 'old_displayname', 'Author', 'TargetUsername',
    'new_value', 'old_value', 'MetaValue', 'MetaValueNew', 'MetaValueOld',
    'new_nickname', 'old_nickname', 'new_lastname', 'old_lastname',
    'MetaKey', 'custom_field_name', 'OptionName', 'TargetUserData',
    'NewUserData', 'OldUserData', 'CurrentUserData', 'Users',
  ];

  /** A page of events. Small by default because a reply is copied into another log. */
  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 100;

  /** The longest window a count may cover. */
  const MAX_WINDOW_DAYS = 90;

  /** How much of a metadata value is ever printed. */
  const META_VALUE_CHARS = 512;

  /** Roughly how large a reply is allowed to get before it stops and says so. */
  const REPLY_BUDGET = 65536;

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
  * Whether WP Activity Log is loaded, checked per call.
  *
  * The version constant, a class and a method, the same shape the other optional groups use.
  * A plugin can be deactivated between the group being switched on and a call arriving, and
  * a group that assumed otherwise would report a version that is no longer running.
  */
  private function loaded(): bool {
    return defined( 'WSAL_VERSION' )
      && class_exists( '\WSAL\Controllers\Alert' )
      && method_exists( '\WSAL\Controllers\Alert', 'get_original_alert_message' );
  }

  private function tools(): array {
    return [
      'wsal_events_briefing' => [
        'name' => 'wsal_events_briefing',
        'description' => 'Count what the site has been doing, from WP Activity Log: events by severity, by object (post, user, plugin, theme), by event type (created, updated, deleted) and by security family (logins, passwords, roles, plugins), over a window of at most 90 days. Counts only — this plugin does not return the individual events here, because an event row names a person and a reply is copied into this plugin\'s own audit log for its retention window; wsal_list_events reads rows and is admin-only. Every reply also carries a log_state block, which is the part worth reading first: whether WP Activity Log is loaded, whether its tables exist, whether it is configured to store somewhere other than this database, how many events it holds and how recent the newest one is, and how many alert types are switched off. That block is what separates "nothing happened" from "nothing is being recorded", which look identical as a number and which this plugin has confused before. A read-level key is given a recency bucket rather than an exact time, because a precise "newest" plus the per-alert counts is enough to tell when someone last logged in.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'days' => [
              'type' => 'integer',
              'minimum' => 1,
              'maximum' => 90,
              'description' => 'How many days back to count. Default 7, maximum 90. Measured from now, in the site timezone.',
            ],
            'family' => [
              'type' => 'string',
              'enum' => [ 'logins', 'passwords', 'roles', 'plugins' ],
              'description' => 'Count only this family of security events instead of everything.',
            ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wsal_list_events' => [
        'name' => 'wsal_list_events',
        'description' => 'List individual events from WP Activity Log, newest first, including the actor\'s username and role. Admin level, the same as wp_get_audit_log, because an event row names a person and this plugin does not expose a third-party log more widely than its own. Filters are structured columns only: alert_id, a minimum severity, object, event_type, user_id, post_id, a security family and a date range. There is no free-text search over the log — an occurrence\'s message is rendered rather than stored, and searching it would mean searching the metadata table, which is an oracle for the IP addresses and user emails the tools deliberately withhold. Each row carries the alert\'s stored template with only allowlisted placeholders filled: the site facts (post title, role, plugin name) appear, and anything the filler does not recognise stays as literal %Name% text rather than being resolved. Rows concerning a failed login do not return their message at all, because the attempted username is where a mistyped password ends up. Paged, with truncated and next_offset when the reply reaches its size budget.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'alert_id' => [ 'type' => 'integer', 'description' => 'Only events of this exact alert type.' ],
            'min_severity' => [ 'type' => 'string', 'enum' => [ 'Notification', 'Informational', 'Low', 'Medium', 'High', 'Critical' ], 'description' => 'Only events at this severity or above. Names, not numbers, because the stored column is text and comparing it numerically is not what it looks like.' ],
            'object' => [ 'type' => 'string', 'description' => 'Only events about this kind of thing: post, user, plugin, theme, option, menu, widget, and so on.' ],
            'event_type' => [ 'type' => 'string', 'description' => 'Only events of this type, such as created, updated, deleted, activated or deactivated.' ],
            'user_id' => [ 'type' => 'integer', 'description' => 'Only events attributed to this WordPress user. Note that events recorded through the REST endpoint are attributed to the user its token borrows, and that command-line activity is recorded with no user at all.' ],
            'post_id' => [ 'type' => 'integer', 'description' => 'Only events concerning this post, page or other post-type row.' ],
            'family' => [ 'type' => 'string', 'enum' => [ 'logins', 'passwords', 'roles', 'plugins' ], 'description' => 'Only this family of security events.' ],
            'since' => [ 'type' => 'string', 'description' => 'Only events at or after this time. ISO 8601 with an explicit zone, for example 2026-09-01T00:00:00Z. A value with no zone is refused rather than guessed, because the site timezone and UTC differ by hours and the guess would be silently wrong.' ],
            'until' => [ 'type' => 'string', 'description' => 'Only events before this time. ISO 8601 with an explicit zone.' ],
            'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Rows to return. Default 20, maximum 100.' ],
            'offset' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Rows to skip, counting from the newest. Prefer before_id for paging: this log is being written to, and a new event arriving between two calls shifts every row, so an offset page can repeat or skip an event. Use this only for a first jump.' ],
            'before_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Return only events with an id below this one. This is the stable way to page: take next_before_id from the previous reply and pass it back, and no event is repeated or skipped if new ones arrive in between.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wsal_get_event' => [
        'name' => 'wsal_get_event',
        'description' => 'Read one WP Activity Log event in full, by its id: the same fields wsal_list_events returns, plus that row\'s metadata as name/value pairs. Admin level, because it returns a row, a row names a person, and the metadata is where a person\'s data lives. The metadata is an ALLOWLIST, not a list of exclusions: only names that describe the site are returned — which post, which role, which plugin, which option row — and everything else is withheld, because a value the site treats as secret is written under a key that looks innocent (a user-meta write stores the field name in one key and both its values in others, with nothing in the names to give it away). The names of the withheld keys are listed in withheld_metadata so an absence is visible rather than silent. A value stored as a serialised object is never read, only marked, because casting one still exposes every property it holds — that is where a user\'s email lives. Filesystem paths under WordPress are returned relative to the content directory. The event\'s message is filled from the alert\'s stored template using only allowlisted site facts, never by the plugin\'s own renderer, which appends user details and can emit a whole serialised object where a name was expected.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The event id, as returned by wsal_list_events.' ],
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

    // The plugin has to be loaded for any of this, including the briefing: the briefing's
    // job is to say whether it is, and it can only say that from inside a class that runs.
    // So the loaded() check is a report rather than a gate — every tool answers, and the
    // ones that need data say why they have none.
    try {
      switch ( $tool ) {
        case 'wsal_events_briefing': $r = $this->briefing( $args, $r ); break;
        case 'wsal_list_events':     $r = $this->list_events( $args, $r ); break;
        case 'wsal_get_event':       $r = $this->get_event( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'WP Activity Log threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }

    return $r;
  }

  #region Tools

  /** Counts over a window, with the log's own state attached to every reply. */
  private function briefing( array $args, array $r ): array {
    $state = $this->log_state();
    if ( true !== $state['readable'] ) {
      // Not readable is a real answer, not an error: the operator asked what is in the log
      // and the honest reply is that there is no local log to read. Returning counts of zero
      // here is the exact defect this block exists to prevent.
      return $this->json( $r, [
        'log_state' => $state,
        'counts'    => null,
        'note'      => 'No counts are reported, because there is no readable local activity log. A zero here would mean "nothing happened", and that is not what this is.',
      ] );
    }

    $days = $this->window_days( $args );
    $since = $this->days_ago( $days );

    $where = [ 'site_id = %d', 'created_on >= %f' ];
    $params = [ $this->site_id(), $since ];

    $family = isset( $args['family'] ) ? (string) $args['family'] : '';
    if ( $family !== '' && !isset( self::FAMILIES[ $family ] ) ) {
      return $this->error( $r, 'Unknown family "' . $family . '". Use one of: ' . implode( ', ', array_keys( self::FAMILIES ) ) . '.' );
    }
    if ( $family !== '' ) {
      $ids = self::FAMILIES[ $family ];
      $where[] = 'alert_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
      $params = array_merge( $params, $ids );
    }

    $sql = function ( string $column ) use ( $where ): string {
      return 'SELECT ' . $column . ' AS k, COUNT(*) AS n FROM ' . $this->table( self::T_OCCURRENCES )
        . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY ' . $column . ' ORDER BY n DESC';
    };

    $by_severity = $this->counted( $sql( 'severity' ), $params );
    $by_object   = $this->counted( $sql( 'object' ), $params );
    $by_type     = $this->counted( $sql( 'event_type' ), $params );
    $by_alert    = $this->counted( $sql( 'alert_id' ), $params );

    if ( is_string( $by_severity ) ) {
      return $this->error( $r, 'The activity log could not be counted: ' . $by_severity );
    }

    // Severity is a varchar holding numbers, so the name is resolved here from the code and
    // anything that is not a code this plugin knows is reported rather than labelled.
    $severities = [];
    $unknown = [];
    foreach ( $by_severity as $row ) {
      $code = (int) $row['k'];
      if ( isset( self::SEVERITIES[ $code ] ) ) {
        $severities[ self::SEVERITIES[ $code ] ] = $row['n'];
      } else {
        $unknown[ (string) $row['k'] ] = $row['n'];
      }
    }

    $out = [
      'log_state' => $state,
      'window'    => [ 'days' => $days, 'since' => gmdate( 'c', (int) floor( $since ) ), 'family' => $family ?: null ],
      'counts'    => [
        'total'           => array_sum( array_column( $by_severity, 'n' ) ),
        'by_severity'     => (object) $severities,
        'by_object'       => $this->pairs( $by_object ),
        'by_event_type'   => $this->pairs( $by_type ),
        'by_alert_id'     => $this->pairs( $by_alert ),
        'suppressed_message_alerts' => $this->suppressed_in_window( $by_alert ),
      ],
    ];
    if ( $unknown ) {
      $out['counts']['unknown_severity_codes'] = (object) $unknown;
      $out['note'] = 'Some rows carry a severity code this plugin does not recognise. They are counted under unknown_severity_codes rather than being given a name, because "Unknown" is a value and not a detected failure.';
    }

    return $this->json( $r, $out );
  }

  /** One page of events. */
  private function list_events( array $args, array $r ): array {
    $state = $this->log_state();
    if ( true !== $state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'events' => null, 'note' => 'There is no readable local activity log, so no events are reported.' ] );
    }

    [ $where, $params, $err ] = $this->build_where( $args );
    if ( $err !== null ) {
      return $this->error( $r, $err );
    }

    $limit  = $this->limit( $args );
    $offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

    // A keyset cursor rather than offset paging, because this log is being written to by the
    // site while it is being read. With OFFSET, an event arriving between two calls shifts
    // every later row down, so the next page repeats one; pruning shifts them up and the next
    // page skips one. Ordering by id and asking for "below this id" has neither problem.
    if ( isset( $args['before_id'] ) && (int) $args['before_id'] > 0 ) {
      $where[] = 'id < %d';
      $params[] = (int) $args['before_id'];
      $offset = 0;
    }

    // Ordered by id rather than by created_on: the timestamp is a double and two events in
    // the same request can share it, which makes paging on it skip or repeat rows.
    $sql = 'SELECT id, alert_id, created_on, severity, object, event_type, post_id, post_type, user_id, username, user_roles'
      . ' FROM ' . $this->table( self::T_OCCURRENCES )
      . ' WHERE ' . implode( ' AND ', $where )
      . ' ORDER BY id DESC LIMIT %d OFFSET %d';
    $rows = $this->rows( $sql, array_merge( $params, [ $limit + 1, $offset ] ) );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The activity log could not be read: ' . $rows );
    }

    $truncated = count( $rows ) > $limit;
    if ( $truncated ) {
      array_pop( $rows );
    }

    $template = $this->alert_templates( array_column( $rows, 'alert_id' ) );
    // One query for every row's fillable metadata, rather than one per row.
    $fill = $this->fillable_metadata( array_column( $rows, 'id' ) );

    $events = [];
    $size = 0;
    foreach ( $rows as $row ) {
      $event = $this->event_shape( $row, $template, $fill[ (int) $row['id'] ] ?? [] );
      $size += strlen( wp_json_encode( $event ) );
      if ( $size > self::REPLY_BUDGET && $events ) {
        $truncated = true;
        break;
      }
      $events[] = $event;
    }

    $out = [
      'log_state' => $state,
      // ids, alert ids and the count come before any name, because this reply is copied into
      // this plugin's own audit log up to a character limit, and what is not copied cannot
      // be erased later.
      'count'     => count( $events ),
      'offset'    => $offset,
      'truncated' => $truncated,
      'events'    => $events,
    ];
    if ( $truncated ) {
      $out['next_offset'] = $offset + count( $events );
      // The cursor to hand back. Taken from the last row actually returned, so the next call
      // starts strictly below it and nothing is repeated.
      $last = end( $events );
      if ( is_array( $last ) && isset( $last['id'] ) ) {
        $out['next_before_id'] = (int) $last['id'];
      }
    }

    return $this->json( $r, $out );
  }

  /** One event and its metadata. */
  private function get_event( array $args, array $r ): array {
    $state = $this->log_state();
    if ( true !== $state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'event' => null, 'note' => 'There is no readable local activity log, so no event is reported.' ] );
    }

    $id = isset( $args['id'] ) ? (int) $args['id'] : 0;
    if ( $id < 1 ) {
      return $this->error( $r, 'An event id is required.' );
    }

    $sql = 'SELECT id, alert_id, created_on, severity, object, event_type, post_id, post_type, user_id, username, user_roles'
      . ' FROM ' . $this->table( self::T_OCCURRENCES )
      . ' WHERE id = %d AND site_id = %d';
    $rows = $this->rows( $sql, [ $id, $this->site_id() ] );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The activity log could not be read: ' . $rows );
    }
    if ( !$rows ) {
      // A specific, bounded claim: this id is not in this site's log. Not "no events".
      return $this->json( $r, [ 'log_state' => $state, 'event' => null, 'note' => 'No event with id ' . $id . ' is in this site\'s activity log.' ] );
    }

    $row = $rows[0];
    $template = $this->alert_templates( [ $row['alert_id'] ] );
    $fill = $this->fillable_metadata( [ (int) $row['id'] ] );
    $event = $this->event_shape( $row, $template, $fill[ (int) $row['id'] ] ?? [] );
    [ $metadata, $extra ] = $this->safe_metadata( $id, $row );
    $event['metadata'] = $metadata;
    foreach ( $extra as $k => $v ) {
      $event[ $k ] = $v;
    }

    return $this->json( $r, [ 'log_state' => $state, 'event' => $event ] );
  }

  #endregion

  #region The log's own state

  /**
  * What the log is, independent of what it holds.
  *
  * The four ways a zero can be wrong are separated here, so no tool has to guess. readable
  * is true only when the plugin is loaded, both tables are present and storage is local.
  */
  private function log_state(): array {
    $state = [
      'plugin_loaded'   => $this->loaded(),
      'storage'         => 'local',
      'tables'          => [ 'present' => [], 'missing' => [] ],
      'readable'        => false,
      'events'          => null,
      'oldest'          => null,
      'newest'          => null,
      'newest_age'      => null,
      'retention'       => null,
      'disabled_alerts' => null,
    ];
    if ( !$state['plugin_loaded'] ) {
      $state['note'] = 'WP Activity Log is not loaded, so there is no activity log to read. This is not the same as the log being empty.';
      return $state;
    }

    // An external adapter means the local tables are legitimately empty and a count of zero
    // says nothing about the site. Reported rather than counted around.
    //
    // Read through WSAL's own accessor, because WSAL stores these in sitemeta on multisite
    // (Settings_Helper::get_option_value_internal does get_network_option(null, ...)), and a
    // plain get_option() finds nothing there and reports local storage with a confident zero
    // — the exact failure this block exists to prevent. The accessor only reads.
    foreach ( [ 'adapter-connection' ] as $option ) {
      if ( !empty( $this->wsal_option( $option ) ) ) {
        $state['storage'] = 'external';
        $state['note'] = 'This site stores its activity log somewhere other than this database (wsal_' . $option . ' is set), so this plugin cannot read it. Reporting zero events here would mean "nothing happened", which is not what an empty local table means.';
        return $state;
      }
    }

    // An ARCHIVE connection is not external storage. Archiving moves records older than a
    // chosen age out of the live table on a schedule (WSAL's archiving-date and
    // archiving-run-every); the recent log stays local. Treating it as external would refuse
    // to read a log that is present and working, and would say something untrue about the
    // site. So it is reported as a limit on how far back the oldest entry goes, not as a
    // reason to answer nothing.
    $state['archiving'] = !empty( $this->wsal_option( 'archive-connection' ) );

    foreach ( [ self::T_OCCURRENCES, self::T_METADATA ] as $suffix ) {
      if ( $this->table_exists( $this->table( $suffix ) ) ) {
        $state['tables']['present'][] = $suffix;
      } else {
        $state['tables']['missing'][] = $suffix;
      }
    }

    $state['retention'] = $this->wsal_option( 'pruning-date', null );
    // The retention value is only in force when pruning is switched on; reporting a number
    // from an option that is not being acted on would state a policy the site does not have.
    if ( 'yes' !== $this->wsal_option( 'pruning-date-e', '' ) ) {
      $state['retention'] = null;
      $state['retention_note'] = 'No pruning is in force on this site, so the log keeps everything until it is pruned by hand.';
    }
    $disabled = $this->wsal_option( 'disabled-alerts', [] );
    $state['disabled_alerts'] = is_array( $disabled ) ? count( $disabled ) : 0;

    if ( $state['tables']['missing'] ) {
      $state['note'] = 'WP Activity Log is loaded but its tables were never created, which is what a plugin that was installed and never activated looks like. There is no log to read, and this is not the same as the log being empty.';
      return $state;
    }

    $state['readable'] = true;

    $sql = 'SELECT COUNT(*) AS n, MIN(created_on) AS oldest, MAX(created_on) AS newest'
      . ' FROM ' . $this->table( self::T_OCCURRENCES ) . ' WHERE site_id = %d';
    $rows = $this->rows( $sql, [ $this->site_id() ] );
    if ( is_string( $rows ) || !$rows ) {
      $state['readable'] = false;
      $state['note'] = 'The activity log tables exist but could not be read: ' . ( is_string( $rows ) ? $rows : 'the query returned nothing' );
      return $state;
    }

    $row = $rows[0];
    $state['events'] = (int) $row['n'];

    if ( $row['oldest'] !== null && $row['oldest'] !== '' ) {
      $oldest = (float) $row['oldest'];
      $newest = (float) $row['newest'];
      // The precise timestamps are at ADMIN level only. A read key polling this block and
      // reading `newest` learns the minute of the most recent event, which for a login
      // alert is "when the administrator sat down" — the same behavioural signal the row
      // tools are admin for. A bucket carries the operational meaning ("stale", "live")
      // without the minute.
      if ( $this->caller_is_admin() ) {
        $state['oldest']     = gmdate( 'c', (int) floor( $oldest ) );
        $state['newest']     = gmdate( 'c', (int) floor( $newest ) );
        $state['oldest_age'] = $this->age( $oldest );
        $state['newest_age'] = $this->age( $newest );
      } else {
        $state['how_recent']  = $this->recency_bucket( $newest );
        $state['how_old']     = $this->recency_bucket( $oldest );
        $state['timestamps_note'] = 'Exact timestamps are not reported to a read key. A precise "newest" plus the per-alert counts is enough to tell when someone last logged in, which is why the row tools are admin level.';
      }
      $gap = time() - (int) floor( $newest );
      if ( $gap > 7 * DAY_IN_SECONDS ) {
        $state['note'] = 'The newest entry in this log is more than a week old. On a site that is being used, an activity log with nothing recent is itself worth reporting: it means either nothing is happening or nothing is being recorded.';
      }
    }

    if ( $state['disabled_alerts'] > 0 ) {
      $state['note_disabled'] = $state['disabled_alerts'] . ' alert type(s) are switched off on this site, so some categories of event are not being recorded. A count of zero in those categories means "not recorded" rather than "did not happen".';
    }

    return $state;
  }

  /** Whether a table exists, by exact name. */
  private function table_exists( string $table ): bool {
    global $wpdb;
    // esc_like, because table names contain an underscore and LIKE treats it as a
    // single-character wildcard: without this, "wp_wsal_occurrences" also matches
    // "wpxwsal_occurrences".
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
  }

  /**
  * One of WP Activity Log's own settings, read the way WSAL reads it.
  *
  * On multisite these live in sitemeta, so a plain get_option() finds nothing and every
  * caller would conclude the setting is unset. Asked through WSAL's own accessor where it
  * exists, which only reads, and falling back to get_option() elsewhere.
  */
  private function wsal_option( string $name, $default = null ) {
    if ( class_exists( '\WSAL\Helpers\Settings_Helper' )
      && method_exists( '\WSAL\Helpers\Settings_Helper', 'get_option_value' ) ) {
      $value = \WSAL\Helpers\Settings_Helper::get_option_value( $name, $default );
      // The accessor returns null when the option is absent, which is the same answer as an
      // unset option and not the same as an empty one.
      return null === $value ? $default : $value;
    }
    return get_option( 'wsal_' . $name, $default );
  }

  /** A table name for this site's log. */
  private function table( string $suffix ): string {
    global $wpdb;
    // base_prefix, not prefix: this plugin keeps one network-wide table pair, so on
    // multisite prefix would name a table that does not exist. Not verified on a multisite
    // install — this stack is single-site, where the two are the same string.
    return $wpdb->base_prefix . $suffix;
  }

  private function site_id(): int {
    return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
  }

  /** Seconds ago as a number and a phrase. */
  private function age( float $timestamp ): array {
    $seconds = max( 0, time() - (int) floor( $timestamp ) );
    return [
      'seconds' => $seconds,
      'human'   => human_time_diff( (int) floor( $timestamp ), time() ),
    ];
  }

  /**
  * How recent a timestamp is, as a bucket rather than an instant.
  *
  * Used where a caller may not be given the exact time. The buckets keep the operational
  * meaning — is the log live, is it stale — without the minute, which for a login alert is
  * a fact about when a person was at their desk.
  */
  private function recency_bucket( float $timestamp ): string {
    $age = max( 0, time() - (int) floor( $timestamp ) );
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
  * when it is absent is deliberate: a caller this cannot identify is treated as the more
  * privileged one for the purpose of what is withheld, so a missing filter withholds more
  * rather than less.
  */
  private function caller_is_admin(): bool {
    return 'admin' === (string) apply_filters( 'gmcp_caller_access_level', 'admin' );
  }

  #endregion

  #region Reading

  /**
  * A count grouped by a column, or the error as a string.
  *
  * The wrapper around this plugin's own query layer returns an empty array when the query
  * fails — a missing table answers exactly like an empty one — so every read here checks
  * last_error as well, and the failure is returned rather than read as zero.
  */
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

  /** Which of the window's alerts have their message withheld. */
  private function suppressed_in_window( array $by_alert ): object {
    $out = [];
    foreach ( $by_alert as $row ) {
      if ( in_array( (int) $row['k'], self::LOGIN_ATTEMPT_ALERTS, true ) ) {
        $out[ (string) $row['k'] ] = $row['n'];
      }
    }
    return (object) $out;
  }

  #endregion

  #region The message

  /**
  * The stored templates for these alert ids, fetched once.
  *
  * The unfilled template, never the plugin's filled message. The filled one appends the
  * alert's metadata block, which carries first name, last name and email for a user event,
  * and it resolves a placeholder whose value is a serialised object by returning the object.
  */
  private function alert_templates( array $alert_ids ): array {
    $out = [];
    if ( !$this->loaded() ) {
      return $out;
    }
    foreach ( array_unique( array_map( 'intval', $alert_ids ) ) as $alert_id ) {
      if ( $alert_id < 1 ) {
        continue;
      }
      // An unknown id is NOT asked for. Measured: get_original_alert_message(999999) returns
      // the translated string "Alert message not found.", which would otherwise be returned
      // as though it were the event's message — a value standing in for a failure, which is
      // the same defect as a zero standing in for "not installed".
      if ( !$this->alert_is_known( $alert_id ) ) {
        continue;
      }
      $message = \WSAL\Controllers\Alert::get_original_alert_message( $alert_id );
      $out[ $alert_id ] = is_string( $message ) ? $message : '';
    }
    return $out;
  }

  /**
  * Whether WP Activity Log defines this alert id.
  *
  * Asked of the plugin's own lists — the live catalogue and the deactivated one, because a
  * deactivated alert still appears in the log and its template is still stored. Both are
  * read-only.
  */
  private function alert_is_known( int $alert_id ): bool {
    if ( !class_exists( '\WSAL\Controllers\Alert_Manager' ) ) {
      return false;
    }
    $alerts = method_exists( '\WSAL\Controllers\Alert_Manager', 'get_alerts' )
      ? (array) \WSAL\Controllers\Alert_Manager::get_alerts()
      : [];
    if ( isset( $alerts[ $alert_id ] ) ) {
      return true;
    }
    if ( method_exists( '\WSAL\Controllers\Alert', 'get_deactivated_alerts_array' ) ) {
      $deactivated = (array) \WSAL\Controllers\Alert::get_deactivated_alerts_array();
      if ( isset( $deactivated[ $alert_id ] ) ) {
        return true;
      }
    }
    return false;
  }

  /**
  * Fill a stored template from allowlisted metadata and nothing else.
  *
  * Every placeholder the allowlist does not cover is left standing as %Name%. A reader that
  * cannot resolve a placeholder should be able to see that it did not, rather than see a
  * blank or a guess where a value was expected.
  */
  private function fill_message( string $template, array $meta ): string {
    if ( $template === '' ) {
      return '';
    }
    $out = preg_replace_callback( '/%([A-Za-z0-9_]+)(?:->([A-Za-z0-9_]+))?%/', function ( $m ) use ( $meta ) {
      $name = $m[1];
      $key  = $m[2] ?? '';

      if ( $key !== '' ) {
        $field = self::FILLABLE_NESTED[ $name ] ?? '';
        if ( $field === '' || $field !== $key ) {
          return $m[0];
        }
        $value = $this->nested_scalar( $meta[ $name ] ?? null, $field );
        return $value === null ? $m[0] : $value;
      }

      if ( !in_array( $name, self::FILLABLE, true ) ) {
        return $m[0];
      }
      $value = $meta[ $name ] ?? null;
      if ( !is_scalar( $value ) ) {
        // Covers a serialised object and an absent name in one branch: neither is a value
        // this tool prints, and the placeholder stays visible.
        return $m[0];
      }
      return (string) $value;
    }, $template );

    return trim( wp_strip_all_tags( (string) $out ) );
  }

  /**
  * One named key out of a serialised value, without reading anything else in it.
  *
  * The nested read exists for PluginData->Name and Theme->Name, which are the plugin's or
  * theme's display name. Safe unserialisation is not enough on its own: it prevents code
  * execution, and casting the result still exposes every property, including the email in a
  * NewUserData object. So only a scalar at the named top-level key is returned, and a value
  * that does not parse is not a value.
  */
  private function nested_scalar( $raw, string $field ): ?string {
    if ( !is_string( $raw ) || $raw === '' ) {
      return null;
    }
    $decoded = @unserialize( $raw, [ 'allowed_classes' => [ 'stdClass' ] ] );
    if ( is_object( $decoded ) ) {
      $decoded = get_object_vars( $decoded );
    }
    if ( !is_array( $decoded ) || !array_key_exists( $field, $decoded ) ) {
      return null;
    }
    $value = $decoded[ $field ];
    return is_scalar( $value ) ? (string) $value : null;
  }

  #endregion

  #region Rows and metadata

  /** One row, shaped for a reply. */
  private function event_shape( array $row, array $templates, array $fill = [] ): array {
    $alert_id = (int) $row['alert_id'];
    $code = (int) $row['severity'];
    $timestamp = (float) $row['created_on'];
    $suppressed = in_array( $alert_id, self::LOGIN_ATTEMPT_ALERTS, true );

    $event = [
      'id'         => (int) $row['id'],
      'alert_id'   => $alert_id,
      'severity'   => [
        'code' => $code,
        'name' => self::SEVERITIES[ $code ] ?? null,
      ],
      'object'     => (string) $row['object'],
      'event_type' => (string) $row['event_type'],
      'time'       => [
        'utc'  => gmdate( 'c', (int) floor( $timestamp ) ),
        'site' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) floor( $timestamp ) ), 'Y-m-d H:i:s' ),
      ],
    ];
    if ( $event['severity']['name'] === null ) {
      $event['severity']['note'] = 'This severity code is not one this plugin recognises, so it is reported without a name rather than being labelled Unknown.';
    }

    // An actor is present but never the first thing in the object, because this reply is
    // copied into another log up to a character limit.
    $event['actor'] = [
      'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
      'username' => (string) $row['username'],
      'roles'   => (string) $row['user_roles'],
    ];
    if ( $row['user_id'] === null ) {
      $event['actor']['note'] = 'No user id is recorded for this event. Command-line and system activity is recorded this way, which is why a user id of 0 and a missing user id are kept as different values.';
    }

    if ( (int) $row['post_id'] > 0 ) {
      $event['post'] = [
        'id'   => (int) $row['post_id'],
        'type' => (string) $row['post_type'],
      ];
    }

    $template = $templates[ $alert_id ] ?? '';
    if ( $suppressed ) {
      $event['message'] = null;
      $event['message_withheld'] = 'The message for this alert type is not returned, because it is built from the username someone typed at the login form and that field regularly contains a mistyped password. The event itself, its time and its actor are above.';
    } elseif ( $template === '' ) {
      $event['message'] = null;
      $event['message_note'] = 'WP Activity Log has no stored template for this alert id, so there is no message to fill rather than an empty one.';
    } else {
      $event['message_template'] = $template;
      $filled = $this->fill_message( $template, $fill );
      $event['message'] = $filled === '' ? null : $filled;
      if ( $filled === '' ) {
        // A template that produced nothing is not the same as no template, and it is the
        // failure the plugin's own wrapper produces silently. It is reported as such.
        $event['message_note'] = 'This alert has a stored template but nothing could be filled into it from the allowed metadata, so the message is absent rather than blank.';
      }
    }

    return $event;
  }

  /**
  * The allowlisted metadata for a set of occurrences, in one query.
  *
  * Separate from safe_metadata() because the two answer different questions: this one
  * supplies values to fill a message template, and it reads only the names on the allowlist.
  * safe_metadata() returns a filtered name/value map for one event and works by exclusion.
  */
  private function fillable_metadata( array $occurrence_ids ): array {
    global $wpdb;
    $ids = array_values( array_filter( array_map( 'intval', $occurrence_ids ), function ( $id ) {
      return $id > 0;
    } ) );
    if ( !$ids ) {
      return [];
    }

    $names = array_merge( self::FILLABLE, array_keys( self::FILLABLE_NESTED ) );
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $nameholders  = implode( ',', array_fill( 0, count( $names ), '%s' ) );

    $sql = 'SELECT occurrence_id, name, value FROM ' . $this->table( self::T_METADATA )
      . ' WHERE occurrence_id IN (' . $placeholders . ') AND name IN (' . $nameholders . ')';
    $rows = $this->rows( $sql, array_merge( $ids, $names ) );
    if ( is_string( $rows ) ) {
      return [];
    }

    $out = [];
    foreach ( $rows as $meta ) {
      $out[ (int) $meta['occurrence_id'] ][ (string) $meta['name'] ] = $meta['value'];
    }
    return $out;
  }

  /**
  * The metadata for one occurrence, filtered to an allowlist.
  *
  * Deny by default, which is the opposite of how this started. The names returned are ones
  * that describe the site; every other name on the row is withheld, and the names of the
  * withheld keys are reported so an absence is visible rather than silent.
  *
  * A value is additionally dropped when the name holding it — `MetaKey`, `custom_field_name`,
  * `OptionName` — itself looks credential-shaped, because that is a field the site chose to
  * call secret and the sibling check the earlier version used looked in the wrong place: it
  * asked whether a sibling's NAME looked secret, while the signal is in the VALUE of the
  * name-holding key.
  */
  private function safe_metadata( int $occurrence_id, array $row ): array {
    global $wpdb;
    $sql = 'SELECT name, value FROM ' . $this->table( self::T_METADATA ) . ' WHERE occurrence_id = %d';
    $rows = $this->rows( $sql, [ $occurrence_id ] );
    if ( is_string( $rows ) ) {
      // A query failure is reported rather than returning an empty map, which would be
      // indistinguishable from a row that simply has no metadata.
      return [ (object) [], [ 'metadata_error' => $rows ] ];
    }
    if ( !$rows ) {
      return [ (object) [], [ 'metadata_note' => 'This event has no metadata rows.' ] ];
    }

    // Does any name on the row hold the name of a field the site called secret?
    $secret_field = false;
    foreach ( $rows as $meta ) {
      if ( in_array( (string) $meta['name'], [ 'MetaKey', 'custom_field_name', 'OptionName', 'CustomFieldName' ], true )
        && GMCP_Core::field_looks_secret( (string) $meta['value'] ) ) {
        $secret_field = true;
        break;
      }
    }

    $out = [];
    $withheld = [];
    $marked = false;
    foreach ( $rows as $meta ) {
      $name = (string) $meta['name'];
      if ( !in_array( $name, self::RETURNED_META, true ) ) {
        $withheld[] = $name;
        if ( in_array( $name, self::PERSONAL_META, true ) ) {
          $marked = true;
        }
        continue;
      }
      if ( $secret_field && in_array( $name, [ 'OldValue', 'NewValue' ], true ) ) {
        $withheld[] = $name;
        $marked = true;
        continue;
      }

      $value = (string) $meta['value'];
      if ( $this->looks_serialised( $value ) ) {
        // A serialised value is never printed. Casting one still exposes every property it
        // holds, so the rule is about not reading it rather than about reading it
        // carefully. The two names allowed through are resolved to the single scalar worth
        // having — a plugin's or theme's display name — and everything else in them is
        // left unread.
        $field = self::FILLABLE_NESTED[ $name ] ?? '';
        $resolved = $field === '' ? null : $this->nested_scalar( $value, $field );
        $out[ $name ] = $resolved === null
          ? '[stored as a serialised value, not printed]'
          : $resolved . ' [resolved from a serialised value; the rest is not read]';
        continue;
      }
      if ( strlen( $value ) > self::META_VALUE_CHARS ) {
        $value = substr( $value, 0, self::META_VALUE_CHARS ) . '… [truncated]';
      }
      $out[ $name ] = $this->relativise( $value );
    }

    $extra = [];
    if ( $withheld ) {
      // The names, not the values. A reader can see that the event concerned a person or a
      // field the site calls secret, which is the operational fact, without being handed it.
      $extra['withheld_metadata'] = array_values( array_unique( $withheld ) );
    }
    if ( $marked ) {
      $extra['metadata_note'] = 'Some metadata on this event concerns a person or a field the site treats as secret, so it is not returned. The names are listed in withheld_metadata. Read the event on WP Activity Log\'s own screen if you need the detail.';
    }

    return [ (object) $out, $extra ];
  }

  /** Whether a value is a PHP-serialised structure. */
  private function looks_serialised( string $value ): bool {
    return (bool) preg_match( '/^(a|O|s|i|b|d):\d+:/', $value );
  }

  /**
  * An absolute path under the plugins or uploads directory, made relative.
  *
  * The plugin's own messages embed the install path — "Install location:
  * /var/www/html/wp-content/plugins/..." — which tells a caller the server's layout for no
  * benefit, since the same fact is available as a plugin basename.
  */
  private function relativise( string $value ): string {
    $roots = [];
    if ( defined( 'WP_PLUGIN_DIR' ) ) {
      $roots[] = WP_PLUGIN_DIR;
    }
    if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
      $roots[] = WPMU_PLUGIN_DIR;
    }
    if ( defined( 'WP_CONTENT_DIR' ) ) {
      $roots[] = WP_CONTENT_DIR;
    }
    if ( defined( 'ABSPATH' ) ) {
      $roots[] = rtrim( ABSPATH, '/' );
    }
    foreach ( $roots as $root ) {
      if ( $root !== '' && strpos( $value, $root ) === 0 ) {
        $short = substr( $value, strlen( $root ) );
        return ltrim( $short, '/' );
      }
    }
    return $value;
  }

  #endregion

  #region Input

  /** The window in days, bounded. */
  private function window_days( array $args ): int {
    $days = isset( $args['days'] ) ? (int) $args['days'] : 7;
    if ( $days < 1 ) {
      $days = 7;
    }
    return min( $days, self::MAX_WINDOW_DAYS );
  }

  private function days_ago( int $days ): float {
    return (float) ( time() - ( $days * DAY_IN_SECONDS ) );
  }

  private function limit( array $args ): int {
    $limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
    if ( $limit < 1 ) {
      $limit = self::DEFAULT_LIMIT;
    }
    return min( $limit, self::MAX_LIMIT );
  }

  /**
  * The WHERE clause and its parameters, or a message explaining why the input was refused.
  *
  * A date without a zone is refused rather than guessed: the site timezone and UTC differ by
  * hours on most sites, and a guess produces a window that is quietly off by that much.
  */
  private function build_where( array $args ): array {
    $where = [ 'site_id = %d' ];
    $params = [ $this->site_id() ];
    $since_ts = null;
    $until_ts = null;

    if ( isset( $args['alert_id'] ) ) {
      $where[] = 'alert_id = %d';
      $params[] = (int) $args['alert_id'];
    }
    if ( isset( $args['min_severity'] ) ) {
      $name = (string) $args['min_severity'];
      $codes = [];
      $floor = null;
      foreach ( self::SEVERITIES as $code => $label ) {
        if ( $label === $name ) {
          $floor = $code;
        }
      }
      if ( $floor === null ) {
        return [ [], [], 'Unknown severity "' . $name . '". Use one of: ' . implode( ', ', self::SEVERITIES ) . '. Severity is compared by these names rather than by number, because the stored column is text and comparing it numerically is not what it looks like.' ];
      }
      foreach ( self::SEVERITIES as $code => $label ) {
        if ( $code >= $floor ) {
          $codes[] = $code;
        }
      }
      $where[] = 'severity IN (' . implode( ',', array_fill( 0, count( $codes ), '%s' ) ) . ')';
      foreach ( $codes as $code ) {
        $params[] = (string) $code;
      }
    }
    if ( isset( $args['object'] ) && $args['object'] !== '' ) {
      $where[] = 'object = %s';
      $params[] = (string) $args['object'];
    }
    if ( isset( $args['event_type'] ) && $args['event_type'] !== '' ) {
      $where[] = 'event_type = %s';
      $params[] = (string) $args['event_type'];
    }
    if ( isset( $args['user_id'] ) ) {
      $where[] = 'user_id = %d';
      $params[] = (int) $args['user_id'];
    }
    if ( isset( $args['post_id'] ) ) {
      $where[] = 'post_id = %d';
      $params[] = (int) $args['post_id'];
    }
    if ( isset( $args['family'] ) && $args['family'] !== '' ) {
      $family = (string) $args['family'];
      if ( !isset( self::FAMILIES[ $family ] ) ) {
        return [ [], [], 'Unknown family "' . $family . '". Use one of: ' . implode( ', ', array_keys( self::FAMILIES ) ) . '.' ];
      }
      $ids = self::FAMILIES[ $family ];
      $where[] = 'alert_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
      $params = array_merge( $params, $ids );
    }

    foreach ( [ 'since' => '>=', 'until' => '<' ] as $key => $op ) {
      if ( !isset( $args[ $key ] ) || $args[ $key ] === '' ) {
        continue;
      }
      $ts = $this->parse_timestamp( (string) $args[ $key ] );
      if ( is_string( $ts ) ) {
        return [ [], [], $ts ];
      }
      // The window cap has to bind here too, or `since` is a way to ask for the whole
      // history one page at a time and the cap on `days` means nothing. This is the same
      // shape as a rate limit that only counts one of its two entry points.
      if ( $ts < $this->days_ago( self::MAX_WINDOW_DAYS ) ) {
        return [ [], [], 'A date older than ' . self::MAX_WINDOW_DAYS . ' days is refused. This tool reads at most a ' . self::MAX_WINDOW_DAYS . '-day window, and a start date before that would be a way to page through the whole log while the window limit still looked in force. Use the plugin\'s own screen for older events.' ];
      }
      if ( $key === 'since' ) {
        $since_ts = $ts;
      } else {
        $until_ts = $ts;
      }
      $where[] = 'created_on ' . $op . ' %f';
      $params[] = $ts;
    }
    if ( $since_ts !== null && $until_ts !== null && $until_ts <= $since_ts ) {
      // Refused rather than returned empty. An empty list here would read as "no events in
      // that period", and the real answer is that the period does not exist.
      return [ [], [], 'The until date is not after the since date, so the range is empty. Nothing would be returned and that would read as "no events", which is a different fact.' ];
    }

    return [ $where, $params, null ];
  }

  /** An ISO 8601 timestamp as a float, or a message explaining what was wrong. */
  private function parse_timestamp( string $value ) {
    // An explicit zone is required. A bare local time is ambiguous here and the ambiguity
    // resolves to a window that is wrong by the site's offset, silently.
    if ( !preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
      return 'The date "' . $value . '" has no timezone. Write it in ISO 8601 with an explicit zone, such as 2026-09-01T00:00:00Z, so it is not read as site time on one interpretation and UTC on another.';
    }
    // Parsed strictly rather than with strtotime, which accepts relative input such as
    // "tomorrow+0000" and silently rolls an impossible date like 2026-02-31 into March.
    // A date this tool cannot read exactly is refused, because the alternative is a window
    // that is quietly not the one that was asked for.
    $format = 'Y-m-d\TH:i:sP';
    $parsed = \DateTimeImmutable::createFromFormat( $format, $value )
      ?: \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $value );
    if ( !$parsed ) {
      $loose = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s.uP', $value );
      $parsed = $loose ?: null;
    }
    if ( !$parsed ) {
      return 'The date "' . $value . '" could not be read as an exact time. Write it as ISO 8601 with seconds and an explicit zone, such as 2026-09-01T00:00:00Z.';
    }
    $errors = \DateTimeImmutable::getLastErrors();
    if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
      return 'The date "' . $value . '" is not a real time — for example a day that does not exist in that month. It is refused rather than moved to the nearest valid date, because a moved date is a window nobody asked for.';
    }
    return (float) $parsed->getTimestamp();
  }

  #endregion

  #region Helpers

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
