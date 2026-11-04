<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Form submissions from Contact Form 7 (through Flamingo), Fluent Forms and Ninja Forms.
* READ ONLY, on one switch.
*
* A submission is the one record on a WordPress site that a member of the public wrote. This
* group follows the Gravity Forms group's posture and then deliberately gives up everything
* that group can do beyond reading, because the three plugins here have nothing this plugin
* can verify a write against. There is no tool that edits, deletes, trashes, marks as spam or
* adds a note to a submission, and there will not be one in this group: a write would be an
* unrecoverable change to evidence a business may be obliged to keep, and this plugin has no
* restore tool behind it.
*
* Every claim below about how a third-party plugin stores data was written from memory of its
* source and could NOT be checked against an installed copy. Each is tagged UNVERIFIED. The
* code is built so that a wrong guess makes a tool say it cannot read something, not make it
* return a confident zero or a value it should have withheld.
*
* WHERE EACH PLUGIN KEEPS A SUBMISSION (all UNVERIFIED):
*   Contact Form 7 keeps nothing. Flamingo, a separate plugin, stores each message as a post of
*   type flamingo_inbound with the answers in post meta rows named _field_<tag name>, a _meta
*   row holding the visitor's IP address and user agent, and the form recorded as a term of
*   the flamingo_inbound_channel taxonomy. A spam message has the status flamingo-spam.
*   Fluent Forms keeps them in {prefix}fluentform_submissions, the answers as one JSON
*   document in the response column, and the form's field schema as JSON in
*   {prefix}fluentform_forms.form_fields.
*   Ninja Forms keeps them as posts of type nf_sub, the answers in _field_<field id> meta and
*   the form id in _form_id; the form's fields are rows of {prefix}nf3_fields.
*
* TWO CLOSED DOORS THESE TOOLS DEPEND ON.
*  1. The generic post readers. Flamingo and Ninja Forms store submissions as posts, and
*     wp_get_post_meta is a read-level tool that returns any key on any post, so an
*     admin-only tool here would be a door beside an open one. GMCP_Core::
*     personal_data_post_guard() is asked by wp_get_post, wp_get_post_meta,
*     wp_read_post_meta_chunk and wp_get_posts for exactly these post types. It does not
*     cover every generic route to a post (see docs/guardrails.md for what is left).
*  2. The values. Nothing below returns an IP address, a user agent or a referring URL, at
*     any level: those are in the plugins' tables, and are simply never selected.
*
* ACCESS LEVELS. The three briefings are 'read' and return counts only, with a log_state block.
* The list and get tools are 'admin', because a row names a person. The same split as
* gf_forms_briefing against gf_list_entries and wsal_events_briefing against wsal_list_events.
*
* WHAT IS RETURNED OF AN ANSWER is decided by an ALLOWLIST built from the form's own schema,
* the lesson of the WP Activity Log group: a denylist of "things that look secret" returned a
* credential in plaintext there. A value is returned only when (a) the field is present in
* the form's current schema, (b) its type is on the plugin's list of plain-input types, and
* (c) its name does not look credential-shaped by GMCP_Core::field_looks_secret(), the
* single answer the journal and the audit log also ask. Password and file-upload fields fail
* (b) by not being on the list, and are named in a withheld list so an absence is visible. If
* the schema cannot be read at all, no value is returned. A field that was answered but has
* since been removed from the form is withheld for the same reason: nothing says what it was.
*
* NO SEARCH over answers, at any level. A LIKE across submissions is an oracle: "does any
* entry contain diff@" confirms a value no tool will print, one guess at a time. The filters
* are structured columns only: form, status, and date. Same reasoning as the WSAL group, and
* the reason Gravity Forms' own search was rebuilt rather than passed through.
*
* log_state IN EVERY REPLY. A zero from these plugins can mean none arrived, the plugin that
* stores them is not installed (Contact Form 7 alone stores nothing, so a count of zero from
* it means nothing at all), a table was never created, or a form is set not to store.
* log_state says which of those applies. This repo has been bitten by an API answering
* "none" for "not installed" three times before this group existed.
*/
class GMCP_Tools_Forms {

  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 50;
  const MAX_PAGE = 10000;
  const MAX_WINDOW_DAYS = 90;
  const PREVIEW_FIELDS = 3;
  const PREVIEW_CHARS = 80;
  const VALUE_CHARS = 2000;
  const MAX_FIELDS = 100;
  const MAX_WITHHELD_LISTED = 30;

  /** Flamingo's post type and taxonomy (UNVERIFIED). */
  const FLAMINGO_TYPE = 'flamingo_inbound';
  const FLAMINGO_CHANNEL = 'flamingo_inbound_channel';
  /** Ninja Forms' submission post type (UNVERIFIED). */
  const NINJA_SUB_TYPE = 'nf_sub';

  /** Contact Form 7 tag base types whose answers are plain input and may be returned. */
  const CF7_TYPES = [ 'text', 'email', 'url', 'tel', 'number', 'range', 'date', 'textarea', 'select', 'checkbox', 'radio', 'acceptance' ];

  /** Fluent Forms element names that hold plain input (UNVERIFIED names). */
  const FLUENT_ELEMENTS = [
    'input_name', 'input_email', 'input_text', 'textarea', 'address', 'select_country', 'country-list',
    'select', 'input_radio', 'input_checkbox', 'input_url', 'input_number', 'input_date', 'phone',
    'ratings', 'rangeslider', 'gdpr_agreement', 'terms_and_condition',
  ];

  /** Ninja Forms field types that hold plain input (UNVERIFIED names). */
  const NINJA_TYPES = [
    'textbox', 'textarea', 'email', 'number', 'phone', 'listselect', 'listradio', 'listcheckbox',
    'listmultiselect', 'listcountry', 'liststate', 'date', 'checkbox', 'firstname', 'lastname',
    'address', 'address2', 'city', 'zip', 'starrating',
  ];

  public function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  /** Only tools for a plugin that is present are offered; handle_call() answers for one that has gone. */
  public function register_tools( $tools ) {
    $mine = [];
    foreach ( $this->tools() as $name => $tool ) {
      if ( strpos( $name, 'cf7_' ) === 0 && !$this->cf7_present() ) {
        continue;
      }
      if ( strpos( $name, 'fluentform_' ) === 0 && !$this->fluent_loaded() ) {
        continue;
      }
      if ( strpos( $name, 'ninjaform_' ) === 0 && !$this->ninja_loaded() ) {
        continue;
      }
      $mine[] = $tool;
    }
    return array_merge( is_array( $tools ) ? $tools : [], $mine );
  }

  #region Presence

  private function cf7_loaded(): bool {
    return defined( 'WPCF7_VERSION' ) && class_exists( 'WPCF7_ContactForm' );
  }

  private function flamingo_loaded(): bool {
    return defined( 'FLAMINGO_VERSION' ) && post_type_exists( self::FLAMINGO_TYPE );
  }

  /** Either half: Flamingo may hold messages from a form plugin that has since gone. */
  private function cf7_present(): bool {
    return $this->cf7_loaded() || $this->flamingo_loaded();
  }

  private function fluent_loaded(): bool {
    return defined( 'FLUENTFORM_VERSION' );
  }

  private function ninja_loaded(): bool {
    return class_exists( 'Ninja_Forms' ) && function_exists( 'Ninja_Forms' );
  }

  #endregion

  private function tools(): array {
    $window = [ 'days' => [ 'type' => 'integer', 'description' => 'Window in days, 1 to ' . self::MAX_WINDOW_DAYS . '. Default 7.' ] ];
    $paging = [
      'date_from' => [ 'type' => 'string', 'description' => 'Earliest date, YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, compared with the stored timestamp as it is stored.' ],
      'date_to' => [ 'type' => 'string', 'description' => 'Latest date, same formats.' ],
      'page' => [ 'type' => 'integer', 'description' => 'Page number, 1-based. Default 1.' ],
      'limit' => [ 'type' => 'integer', 'description' => 'Rows per page, 1 to ' . self::MAX_LIMIT . '. Default ' . self::DEFAULT_LIMIT . '.' ],
    ];
    $no_search = ' There is deliberately no search over answers: a text search over submissions would let a caller confirm a value the tools withhold one guess at a time, so filters are by form, status and date only.';
    $withheld = ' Only fields present in the form\'s current definition and of a plain-input type are returned; password and file-upload fields, credential-shaped field names, and answers to fields the form no longer has are withheld and named. IP addresses, user agents and referring URLs are never returned. Personal data submitted by the public, so admin level; the reply is also copied into this plugin\'s audit log for its retention window.';

    return [
      'cf7_messages_briefing' => [
        'name' => 'cf7_messages_briefing',
        'description' => 'Count the messages Flamingo has stored from Contact Form 7 forms: by status and by form over a window of at most ' . self::MAX_WINDOW_DAYS . ' days. Counts only, no message content and no personal data. Every reply carries a log_state block, which is the part to read first: Contact Form 7 does not store submissions at all, so without Flamingo (or a form set not to store) a count of zero means nothing was kept, which is different from nothing having arrived. The block says whether each plugin is loaded, how many forms are set not to store, and how many messages exist.',
        'inputSchema' => [ 'type' => 'object', 'properties' => $window ],
        'accessLevel' => 'read',
      ],
      'cf7_list_messages' => [
        'name' => 'cf7_list_messages',
        'description' => 'Page through the messages Flamingo has stored from Contact Form 7: ID, date, form, status, and a short preview of the first few answers. Filters: form (the form\'s slug, as shown in the briefing), status (active, spam, trash or all; default active and spam), date.' . $no_search . $withheld,
        'inputSchema' => [
          'type' => 'object',
          'properties' => array_merge( [
            'form' => [ 'type' => 'string', 'description' => 'The form\'s channel slug, as listed by cf7_messages_briefing.' ],
            'status' => [ 'type' => 'string', 'description' => 'active, spam, trash or all. Omit for active and spam.' ],
          ], $paging ),
        ],
        'accessLevel' => 'admin',
      ],
      'cf7_get_message' => [
        'name' => 'cf7_get_message',
        'description' => 'Read one Flamingo message: date, form, status, the mail subject and every returnable answer.' . $withheld,
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'message_id' => [ 'type' => 'integer', 'description' => 'The Flamingo message (post) ID.' ] ],
          'required' => [ 'message_id' ],
        ],
        'accessLevel' => 'admin',
      ],

      'fluentform_briefing' => [
        'name' => 'fluentform_briefing',
        'description' => 'Count Fluent Forms submissions: by status and by form over a window of at most ' . self::MAX_WINDOW_DAYS . ' days. Counts only, no content and no personal data. Every reply carries a log_state block separating "no submissions" from "the submissions table does not exist" and from "Fluent Forms is not loaded". A form can be set not to keep entries; this tool cannot see that setting, so a form with zero is not proven to have received nothing.',
        'inputSchema' => [ 'type' => 'object', 'properties' => $window ],
        'accessLevel' => 'read',
      ],
      'fluentform_list_entries' => [
        'name' => 'fluentform_list_entries',
        'description' => 'Page through Fluent Forms submissions: ID, form, date, status and a short preview of the first few answers. Filters: form_id, status (the plugin\'s own status word, for example unread, read, spam, trashed; default everything except trashed), date.' . $no_search . $withheld,
        'inputSchema' => [
          'type' => 'object',
          'properties' => array_merge( [
            'form_id' => [ 'type' => 'integer', 'description' => 'Limit to one form.' ],
            'status' => [ 'type' => 'string', 'description' => 'One status word, letters and underscores only.' ],
          ], $paging ),
        ],
        'accessLevel' => 'admin',
      ],
      'fluentform_get_entry' => [
        'name' => 'fluentform_get_entry',
        'description' => 'Read one Fluent Forms submission: form, serial number, date, status and every returnable answer, labelled from the form.' . $withheld,
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'entry_id' => [ 'type' => 'integer', 'description' => 'The submission ID.' ] ],
          'required' => [ 'entry_id' ],
        ],
        'accessLevel' => 'admin',
      ],

      'ninjaform_briefing' => [
        'name' => 'ninjaform_briefing',
        'description' => 'Count Ninja Forms submissions: by status and by form over a window of at most ' . self::MAX_WINDOW_DAYS . ' days. Counts only, no content and no personal data. Every reply carries a log_state block separating "no submissions" from "Ninja Forms is not loaded", from "its tables are missing", and, per form, from "this form has no action that records submissions", which is a form that cannot have any.',
        'inputSchema' => [ 'type' => 'object', 'properties' => $window ],
        'accessLevel' => 'read',
      ],
      'ninjaform_list_entries' => [
        'name' => 'ninjaform_list_entries',
        'description' => 'Page through Ninja Forms submissions: ID, form, date, status and a short preview of the first few answers. Filters: form_id, status (active, trash or all; default active), date.' . $no_search . $withheld,
        'inputSchema' => [
          'type' => 'object',
          'properties' => array_merge( [
            'form_id' => [ 'type' => 'integer', 'description' => 'Limit to one form.' ],
            'status' => [ 'type' => 'string', 'description' => 'active, trash or all. Omit for active.' ],
          ], $paging ),
        ],
        'accessLevel' => 'admin',
      ],
      'ninjaform_get_entry' => [
        'name' => 'ninjaform_get_entry',
        'description' => 'Read one Ninja Forms submission: form, sequence number, date, status and every returnable answer, labelled from the form.' . $withheld,
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'entry_id' => [ 'type' => 'integer', 'description' => 'The submission (post) ID.' ] ],
          'required' => [ 'entry_id' ],
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

    // Not a gate: every tool answers, including when its plugin has gone, because the
    // answer "this plugin is not here" with the state attached is the one an operator needs.
    // The read paths below check readable before touching a table.
    try {
      switch ( $tool ) {
        case 'cf7_messages_briefing':    $r = $this->cf7_briefing( $args, $r ); break;
        case 'cf7_list_messages':        $r = $this->cf7_list( $args, $r ); break;
        case 'cf7_get_message':          $r = $this->cf7_get( $args, $r ); break;
        case 'fluentform_briefing':      $r = $this->fluent_briefing( $args, $r ); break;
        case 'fluentform_list_entries':  $r = $this->fluent_list( $args, $r ); break;
        case 'fluentform_get_entry':     $r = $this->fluent_get( $args, $r ); break;
        case 'ninjaform_briefing':       $r = $this->ninja_briefing( $args, $r ); break;
        case 'ninjaform_list_entries':   $r = $this->ninja_list( $args, $r ); break;
        case 'ninjaform_get_entry':      $r = $this->ninja_get( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'A form plugin threw an error while running ' . $tool . ': ' . $e->getMessage(), -32603 );
    }
    return $r;
  }

  #region Shared reading

  /** Rows as arrays, or the database error as a string. A failed query is never read as empty. */
  private function rows( string $sql, array $params = [] ) {
    global $wpdb;
    $prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
    $wpdb->last_error = '';
    $rows = $wpdb->get_results( $prepared, ARRAY_A );
    if ( $wpdb->last_error !== '' ) {
      return $wpdb->last_error;
    }
    return is_array( $rows ) ? $rows : [];
  }

  private function table_exists( string $table ): bool {
    global $wpdb;
    // esc_like: an underscore is a one-character wildcard in LIKE, so without it
    // "wp_fluentform_forms" would also match "wpxfluentform_forms".
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
  }

  private function tables_report( array $suffixes ): array {
    global $wpdb;
    $present = [];
    $missing = [];
    foreach ( $suffixes as $suffix ) {
      if ( $this->table_exists( $wpdb->prefix . $suffix ) ) {
        $present[] = $suffix;
      } else {
        $missing[] = $suffix;
      }
    }
    return [ 'present' => $present, 'missing' => $missing ];
  }

  private function caller_is_admin(): bool {
    return 'admin' === (string) apply_filters( 'gmcp_caller_access_level', 'admin' );
  }

  /** "Newest" as an exact time for an admin, and as a bucket for anyone else. */
  private function newest_report( ?string $newest_mysql_gmt, string $note_key = 'newest' ): array {
    if ( $newest_mysql_gmt === null || $newest_mysql_gmt === '' ) {
      return [ $note_key => null ];
    }
    $ts = strtotime( $newest_mysql_gmt . ' UTC' );
    if ( !$ts ) {
      return [ $note_key => null ];
    }
    if ( $this->caller_is_admin() ) {
      return [ $note_key => gmdate( 'c', $ts ) ];
    }
    $age = max( 0, time() - $ts );
    $bucket = $age < HOUR_IN_SECONDS ? 'within the last hour'
      : ( $age < DAY_IN_SECONDS ? 'within the last day'
      : ( $age < 7 * DAY_IN_SECONDS ? 'within the last week'
      : ( $age < 30 * DAY_IN_SECONDS ? 'within the last month' : 'older than a month' ) ) );
    return [ $note_key . '_bucket' => $bucket ];
  }

  private function window_days( array $args ): int {
    $days = isset( $args['days'] ) ? (int) $args['days'] : 7;
    return $days < 1 ? 7 : min( $days, self::MAX_WINDOW_DAYS );
  }

  /** [limit, page, offset], bounded. A page beyond MAX_PAGE is refused by the caller as an error string. */
  private function paging( array $args ) {
    $limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
    if ( $limit < 1 ) {
      $limit = self::DEFAULT_LIMIT;
    }
    $limit = min( $limit, self::MAX_LIMIT );
    $page = isset( $args['page'] ) ? (int) $args['page'] : 1;
    if ( $page < 1 ) {
      $page = 1;
    }
    if ( $page > self::MAX_PAGE ) {
      return 'page may not exceed ' . self::MAX_PAGE . '.';
    }
    return [ $limit, $page, ( $page - 1 ) * $limit ];
  }

  /**
  * A date argument as a MySQL datetime, or an error string. Refused rather than guessed: a
  * string strtotime() half understands becomes a window that is quietly wrong.
  */
  private function date_arg( array $args, string $name, bool $end ) {
    if ( !isset( $args[ $name ] ) || $args[ $name ] === '' ) {
      return null;
    }
    $v = (string) $args[ $name ];
    if ( !preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/', $v, $m ) || !checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
      return $name . ' has to be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.';
    }
    return isset( $m[4] ) ? $v : $v . ( $end ? ' 23:59:59' : ' 00:00:00' );
  }

  /** A display string for an answer: scalars as they are, arrays flattened two levels, objects dropped. */
  private function display_value( $value, int $depth = 0 ): string {
    if ( is_array( $value ) ) {
      if ( $depth >= 2 ) {
        return '';
      }
      $parts = [];
      $assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
      foreach ( $value as $k => $v ) {
        $s = $this->display_value( $v, $depth + 1 );
        if ( $s === '' ) {
          continue;
        }
        $parts[] = $assoc ? $k . ': ' . $s : $s;
      }
      return implode( ', ', $parts );
    }
    if ( is_scalar( $value ) || $value === null ) {
      return (string) $value;
    }
    return '';
  }

  private function preview( $value ): string {
    $s = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $this->display_value( $value ) ) ) );
    return mb_substr( $s, 0, self::PREVIEW_CHARS );
  }

  private function clip( string $value ): string {
    $s = wp_strip_all_tags( $value );
    return mb_strlen( $s ) > self::VALUE_CHARS ? mb_substr( $s, 0, self::VALUE_CHARS ) . ' [truncated]' : $s;
  }

  /**
  * Split an entry's answers into what may be returned and what is withheld.
  *
  * $schema maps an answer's name to [ label, type_ok ]; a name that is not in it is not
  * returned, whatever it holds. $answers maps name to raw value. The result keeps the
  * schema's order, which is the form's order, rather than the storage order.
  *
  * @param array<string,array{label:string,type:string,ok:bool,hint?:string}> $schema
  * @param array<string,mixed> $answers
  * @return array{fields:array,withheld:array,withheld_count:int}
  */
  private function filter_answers( array $schema, array $answers ): array {
    $fields = [];
    $withheld = [];
    $seen = [];
    foreach ( $schema as $name => $def ) {
      $name = (string) $name;
      if ( !array_key_exists( $name, $answers ) ) {
        continue;
      }
      $seen[ $name ] = true;
      if ( !$def['ok'] ) {
        $withheld[] = [ 'name' => $name, 'reason' => 'field type "' . $def['type'] . '" is not a plain-input type' ];
        continue;
      }
      if ( GMCP_Core::field_looks_secret( $name ) || ( isset( $def['hint'] ) && $def['hint'] !== '' && GMCP_Core::field_looks_secret( (string) $def['hint'] ) ) ) {
        $withheld[] = [ 'name' => $name, 'reason' => 'field name looks credential-shaped' ];
        continue;
      }
      if ( count( $fields ) >= self::MAX_FIELDS ) {
        $withheld[] = [ 'name' => $name, 'reason' => 'more than ' . self::MAX_FIELDS . ' fields; the rest are not listed' ];
        continue;
      }
      $fields[] = [ 'name' => $name, 'label' => $def['label'], 'type' => $def['type'], 'value' => $answers[ $name ] ];
    }
    foreach ( $answers as $name => $_ ) {
      if ( !isset( $seen[ (string) $name ] ) ) {
        $withheld[] = [ 'name' => (string) $name, 'reason' => 'not in the form\'s current definition, so nothing says what it is' ];
      }
    }
    return [
      'fields' => $fields,
      'withheld' => array_slice( $withheld, 0, self::MAX_WITHHELD_LISTED ),
      'withheld_count' => count( $withheld ),
    ];
  }

  /** Shape filtered answers for get tools (full value, clipped) or for list previews (short). */
  private function render_fields( array $filtered, bool $preview ): array {
    $out = [];
    foreach ( $filtered['fields'] as $f ) {
      $text = $preview ? $this->preview( $f['value'] ) : $this->clip( $this->display_value( $f['value'] ) );
      if ( $preview && $text === '' ) {
        continue;
      }
      $out[] = [ 'name' => $f['name'], 'label' => $f['label'], 'value' => $text ];
      if ( $preview && count( $out ) >= self::PREVIEW_FIELDS ) {
        break;
      }
    }
    return $out;
  }

  private function unreadable( array $r, array $state, string $what ): array {
    return $this->json( $r, [
      'log_state' => $state,
      $what => null,
      'note' => 'There is no readable log of submissions for this plugin, so nothing is reported. This is not the same as there being none.',
    ] );
  }

  #endregion

  #region Contact Form 7 and Flamingo

  private function cf7_state(): array {
    global $wpdb;
    $cf7 = $this->cf7_loaded();
    $flamingo = $this->flamingo_loaded();
    $state = [
      'contact_form_7_loaded' => $cf7,
      'flamingo_loaded' => $flamingo,
      'storage' => $flamingo ? 'flamingo' : 'none',
      'readable' => $flamingo,
      'messages' => null,
      'forms' => null,
      'forms_not_storing' => null,
    ];
    if ( $cf7 ) {
      $forms = get_posts( [ 'post_type' => 'wpcf7_contact_form', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids' ] );
      $state['forms'] = count( $forms );
      if ( class_exists( 'WPCF7_ContactForm' ) && method_exists( 'WPCF7_ContactForm', 'get_instance' ) ) {
        $not = 0;
        $known = true;
        foreach ( $forms as $form_id ) {
          $form = \WPCF7_ContactForm::get_instance( $form_id );
          if ( !$form || !method_exists( $form, 'is_true' ) ) {
            $known = false;
            break;
          }
          if ( $form->is_true( 'do_not_store' ) ) {
            $not++;
          }
        }
        $state['forms_not_storing'] = $known ? $not : null;
      }
    }
    if ( !$flamingo ) {
      $state['note'] = $cf7
        ? 'Contact Form 7 does not keep submissions itself, and Flamingo (which does) is not loaded, so no messages are being stored. A count of zero here means "nothing is kept", not "nothing arrived".'
        : 'Neither Contact Form 7 nor Flamingo is loaded, so there is nothing to read.';
      return $state;
    }
    $counts = $this->rows( "SELECT post_status AS k, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status", [ self::FLAMINGO_TYPE ] );
    if ( is_string( $counts ) ) {
      $state['readable'] = false;
      $state['note'] = 'Flamingo is loaded but its messages could not be counted: ' . $counts;
      return $state;
    }
    $state['messages'] = (int) array_sum( array_column( $counts, 'n' ) );
    if ( !$cf7 ) {
      $state['note'] = 'Flamingo is loaded but Contact Form 7 is not, so forms cannot be looked up and answers are withheld (their types cannot be checked).';
    }
    elseif ( $state['forms_not_storing'] ) {
      $state['note_forms'] = $state['forms_not_storing'] . ' form(s) are set not to store messages, so zero for those means "not kept".';
    }
    $newest = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_date_gmt) FROM {$wpdb->posts} WHERE post_type = %s", self::FLAMINGO_TYPE ) );
    return array_merge( $state, $this->newest_report( $newest ) );
  }

  private function flamingo_status_name( string $status ): string {
    return [ 'publish' => 'active', 'flamingo-spam' => 'spam', 'trash' => 'trash' ][ $status ] ?? $status;
  }

  /** Status words a caller may filter by, as the post statuses they stand for. */
  private function flamingo_statuses( $status ) {
    if ( $status === null || $status === '' ) {
      return [ 'publish', 'flamingo-spam' ];
    }
    $map = [ 'active' => [ 'publish' ], 'spam' => [ 'flamingo-spam' ], 'trash' => [ 'trash' ], 'all' => [ 'publish', 'flamingo-spam', 'trash' ] ];
    return $map[ (string) $status ] ?? 'status has to be one of: active, spam, trash, all.';
  }

  private function cf7_briefing( array $args, array $r ): array {
    global $wpdb;
    $state = $this->cf7_state();
    if ( !$state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'counts' => null, 'note' => 'No counts are reported, because there is no readable message log. A zero would read as "nothing arrived".' ] );
    }
    $days = $this->window_days( $args );
    $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    $by_status = $this->rows( "SELECT post_status AS k, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_date_gmt >= %s GROUP BY post_status", [ self::FLAMINGO_TYPE, $since ] );
    $by_form = $this->rows(
      "SELECT t.slug AS k, COUNT(DISTINCT p.ID) AS n FROM {$wpdb->posts} p
       JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
       JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
       JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
       WHERE p.post_type = %s AND p.post_date_gmt >= %s GROUP BY t.slug ORDER BY n DESC LIMIT 100",
      [ self::FLAMINGO_CHANNEL, self::FLAMINGO_TYPE, $since ]
    );
    if ( is_string( $by_status ) || is_string( $by_form ) ) {
      return $this->error( $r, 'The messages could not be counted: ' . ( is_string( $by_status ) ? $by_status : $by_form ), -32603 );
    }
    $status_out = [];
    foreach ( $by_status as $row ) {
      $status_out[ $this->flamingo_status_name( (string) $row['k'] ) ] = (int) $row['n'];
    }
    $form_out = [];
    foreach ( $by_form as $row ) {
      $form_out[ (string) $row['k'] ] = (int) $row['n'];
    }
    return $this->json( $r, [
      'log_state' => $state,
      'window' => [ 'days' => $days, 'since' => gmdate( 'c', strtotime( $since . ' UTC' ) ) ],
      'counts' => [
        'total' => (int) array_sum( $status_out ),
        'by_status' => (object) $status_out,
        'by_form_channel' => (object) $form_out,
      ],
      'note' => 'by_form_channel counts a message under every channel term it carries, so the parts can add up to more than the total.',
    ] );
  }

  /**
  * A schema for a Flamingo message: tag name => label/type/ok, from its Contact Form 7 form.
  *
  * Returns [] when the form cannot be found, which filter_answers() turns into "everything
  * withheld" rather than "everything returned". The channel terms are tried in turn because a
  * message can carry a parent channel as well as the form's own (UNVERIFIED).
  */
  private function cf7_schema( int $message_id, bool &$typed ): array {
    $typed = false;
    if ( !$this->cf7_loaded() ) {
      return [];
    }
    $slugs = wp_get_object_terms( $message_id, self::FLAMINGO_CHANNEL, [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $slugs ) || !$slugs ) {
      return [];
    }
    foreach ( $slugs as $slug ) {
      $form_post = get_page_by_path( (string) $slug, OBJECT, 'wpcf7_contact_form' );
      if ( !$form_post ) {
        continue;
      }
      $form = \WPCF7_ContactForm::get_instance( $form_post );
      if ( !$form || !method_exists( $form, 'scan_form_tags' ) ) {
        continue;
      }
      $schema = [];
      foreach ( (array) $form->scan_form_tags() as $tag ) {
        $name = is_object( $tag ) ? (string) ( $tag->name ?? '' ) : '';
        if ( $name === '' ) {
          continue;
        }
        $base = (string) ( $tag->basetype ?? rtrim( (string) ( $tag->type ?? '' ), '*' ) );
        $schema[ $name ] = [ 'label' => $name, 'type' => $base, 'ok' => in_array( $base, self::CF7_TYPES, true ) ];
      }
      $typed = true;
      return $schema;
    }
    return [];
  }

  /** The answers stored on a Flamingo message: _field_<name> rows only, so _meta (IP, user agent) is never read. */
  private function cf7_answers( int $message_id ): array {
    $all = get_post_meta( $message_id );
    $answers = [];
    foreach ( (array) $all as $key => $values ) {
      if ( strpos( (string) $key, '_field_' ) !== 0 ) {
        continue;
      }
      $answers[ substr( (string) $key, 7 ) ] = maybe_unserialize( is_array( $values ) ? ( $values[0] ?? '' ) : $values );
    }
    return $answers;
  }

  private function cf7_list( array $args, array $r ): array {
    global $wpdb;
    $state = $this->cf7_state();
    if ( !$state['readable'] ) {
      return $this->unreadable( $r, $state, 'messages' );
    }
    $statuses = $this->flamingo_statuses( $args['status'] ?? null );
    if ( is_string( $statuses ) ) {
      return $this->error( $r, $statuses );
    }
    $page = $this->paging( $args );
    if ( is_string( $page ) ) {
      return $this->error( $r, $page );
    }
    [ $limit, $page_no, $offset ] = $page;
    $from = $this->date_arg( $args, 'date_from', false );
    $to = $this->date_arg( $args, 'date_to', true );
    foreach ( [ $from, $to ] as $d ) {
      if ( is_string( $d ) && !preg_match( '/^\d{4}-/', $d ) ) {
        return $this->error( $r, $d );
      }
    }

    $where = [ 'p.post_type = %s', 'p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')' ];
    $params = array_merge( [ self::FLAMINGO_TYPE ], $statuses );
    $join = '';
    if ( isset( $args['form'] ) && $args['form'] !== '' ) {
      $join = " JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
                JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = %s";
      array_unshift( $params, self::FLAMINGO_CHANNEL, sanitize_title( (string) $args['form'] ) );
    }
    if ( $from ) {
      $where[] = 'p.post_date_gmt >= %s';
      $params[] = $from;
    }
    if ( $to ) {
      $where[] = 'p.post_date_gmt <= %s';
      $params[] = $to;
    }
    $base = "FROM {$wpdb->posts} p{$join} WHERE " . implode( ' AND ', $where );
    $total = $this->rows( "SELECT COUNT(DISTINCT p.ID) AS n {$base}", $params );
    $rows = $this->rows( "SELECT DISTINCT p.ID AS id, p.post_date_gmt AS date_gmt, p.post_status AS status {$base} ORDER BY p.ID DESC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) );
    if ( is_string( $total ) || is_string( $rows ) ) {
      return $this->error( $r, 'The messages could not be listed: ' . ( is_string( $total ) ? $total : $rows ), -32603 );
    }

    $out = [];
    $schemas = [];
    foreach ( $rows as $row ) {
      $mid = (int) $row['id'];
      $channels = wp_get_object_terms( $mid, self::FLAMINGO_CHANNEL, [ 'fields' => 'slugs' ] );
      $typed = false;
      $schema = $this->cf7_schema( $mid, $typed );
      $filtered = $this->filter_answers( $schema, $this->cf7_answers( $mid ) );
      $out[] = [
        'message_id' => $mid,
        'date' => (string) $row['date_gmt'] . ' UTC',
        'form_channels' => is_wp_error( $channels ) ? [] : array_values( $channels ),
        'status' => $this->flamingo_status_name( (string) $row['status'] ),
        'preview' => $this->render_fields( $filtered, true ),
        'withheld_count' => $filtered['withheld_count'],
      ];
    }
    return $this->json( $r, [
      'log_state' => $state,
      'total' => (int) ( $total[0]['n'] ?? 0 ),
      'page' => $page_no,
      'limit' => $limit,
      'messages' => $out,
    ] );
  }

  private function cf7_get( array $args, array $r ): array {
    global $wpdb;
    $state = $this->cf7_state();
    if ( !$state['readable'] ) {
      return $this->unreadable( $r, $state, 'message' );
    }
    $mid = (int) ( $args['message_id'] ?? 0 );
    $rows = $this->rows( "SELECT ID AS id, post_date_gmt AS date_gmt, post_status AS status FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", [ $mid, self::FLAMINGO_TYPE ] );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The message could not be read: ' . $rows, -32603 );
    }
    if ( !$rows ) {
      return $this->json( $r, [ 'log_state' => $state, 'message' => null, 'note' => 'No Flamingo message with ID ' . $mid . ' exists.' ] );
    }
    $typed = false;
    $schema = $this->cf7_schema( $mid, $typed );
    $filtered = $this->filter_answers( $schema, $this->cf7_answers( $mid ) );
    $channels = wp_get_object_terms( $mid, self::FLAMINGO_CHANNEL, [ 'fields' => 'slugs' ] );
    $subject = (string) get_post_meta( $mid, '_subject', true );
    $message = [
      'message_id' => $mid,
      'date' => (string) $rows[0]['date_gmt'] . ' UTC',
      'form_channels' => is_wp_error( $channels ) ? [] : array_values( $channels ),
      'status' => $this->flamingo_status_name( (string) $rows[0]['status'] ),
      'subject' => $this->clip( $subject ),
      'fields' => $this->render_fields( $filtered, false ),
      'withheld' => $filtered['withheld'],
      'withheld_count' => $filtered['withheld_count'],
      'fields_typed_from_form' => $typed,
    ];
    if ( !$typed ) {
      $message['warning'] = 'The Contact Form 7 form this message came from could not be found, so its fields cannot be typed and none of the answers are returned.';
    }
    return $this->json( $r, [ 'log_state' => $state, 'message' => $message ] );
  }

  #endregion

  #region Fluent Forms

  private function fluent_state(): array {
    global $wpdb;
    $state = [
      'plugin_loaded' => $this->fluent_loaded(),
      'tables' => [ 'present' => [], 'missing' => [] ],
      'readable' => false,
      'submissions' => null,
      'forms' => null,
    ];
    if ( !$state['plugin_loaded'] ) {
      $state['note'] = 'Fluent Forms is not loaded, so there is no submission log to read. This is not the same as the log being empty.';
      return $state;
    }
    $state['tables'] = $this->tables_report( [ 'fluentform_forms', 'fluentform_submissions' ] );
    if ( $state['tables']['missing'] ) {
      $state['note'] = 'Fluent Forms is loaded but its tables (' . implode( ', ', $state['tables']['missing'] ) . ') do not exist, which is what a plugin that was installed and never activated looks like. There is nothing to read, and that is not the same as there being no submissions.';
      return $state;
    }
    $c = $this->rows( "SELECT COUNT(*) AS n, MAX(created_at) AS newest FROM {$wpdb->prefix}fluentform_submissions" );
    $f = $this->rows( "SELECT COUNT(*) AS n FROM {$wpdb->prefix}fluentform_forms" );
    if ( is_string( $c ) || is_string( $f ) || !$c || !$f ) {
      $state['note'] = 'The Fluent Forms tables exist but could not be read: ' . ( is_string( $c ) ? $c : ( is_string( $f ) ? $f : 'the query returned nothing' ) );
      return $state;
    }
    $state['readable'] = true;
    $state['submissions'] = (int) $c[0]['n'];
    $state['forms'] = (int) $f[0]['n'];
    $state['note'] = 'A form can be configured not to keep entries; this tool does not read that setting (UNVERIFIED that such a setting exists), so a form with no submissions is not proven to have received none. Timestamps are in the time zone Fluent Forms stored them in.';
    // Fluent stores local time (UNVERIFIED), so the "gmt" parameter is a misnomer here and the
    // bucket is computed as if it were UTC; at worst it is off by the site's offset.
    return array_merge( $state, $this->newest_report( $c[0]['newest'] ) );
  }

  private function fluent_briefing( array $args, array $r ): array {
    global $wpdb;
    $state = $this->fluent_state();
    if ( !$state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'counts' => null, 'note' => 'No counts are reported, because there is no readable submissions table. A zero would read as "nothing arrived".' ] );
    }
    $days = $this->window_days( $args );
    $since = gmdate( 'Y-m-d H:i:s', time() + (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) - $days * DAY_IN_SECONDS );
    $by_status = $this->rows( "SELECT status AS k, COUNT(*) AS n FROM {$wpdb->prefix}fluentform_submissions WHERE created_at >= %s GROUP BY status", [ $since ] );
    $by_form = $this->rows(
      "SELECT s.form_id AS k, f.title AS title, COUNT(*) AS n FROM {$wpdb->prefix}fluentform_submissions s
       LEFT JOIN {$wpdb->prefix}fluentform_forms f ON f.id = s.form_id
       WHERE s.created_at >= %s GROUP BY s.form_id, f.title ORDER BY n DESC LIMIT 100",
      [ $since ]
    );
    if ( is_string( $by_status ) || is_string( $by_form ) ) {
      return $this->error( $r, 'The submissions could not be counted: ' . ( is_string( $by_status ) ? $by_status : $by_form ), -32603 );
    }
    $status_out = [];
    foreach ( $by_status as $row ) {
      $status_out[ (string) $row['k'] ] = (int) $row['n'];
    }
    $forms_out = [];
    foreach ( $by_form as $row ) {
      $forms_out[] = [ 'form_id' => (int) $row['k'], 'title' => $row['title'] === null ? '(form no longer exists)' : (string) $row['title'], 'count' => (int) $row['n'] ];
    }
    return $this->json( $r, [
      'log_state' => $state,
      'window' => [ 'days' => $days, 'since' => $since . ' (site time)' ],
      'counts' => [ 'total' => (int) array_sum( $status_out ), 'by_status' => (object) $status_out, 'by_form' => $forms_out ],
    ] );
  }

  /**
  * A Fluent form's field schema: answer name => label/type/ok, or null when it cannot be read.
  *
  * Walked defensively because the document nests: containers hold columns that hold fields,
  * and a name field holds sub-fields (UNVERIFIED structure). Only a node that carries
  * attributes.name is a field. A node whose element is not on the allowlist is recorded with
  * ok=false so it is named as withheld instead of vanishing.
  */
  private function fluent_schema( int $form_id ): ?array {
    global $wpdb;
    $rows = $this->rows( "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", [ $form_id ] );
    if ( is_string( $rows ) || !$rows ) {
      return null;
    }
    $doc = json_decode( (string) $rows[0]['form_fields'], true );
    if ( !is_array( $doc ) || !isset( $doc['fields'] ) || !is_array( $doc['fields'] ) ) {
      return null;
    }
    $schema = [];
    $walk = function ( $nodes, int $depth ) use ( &$walk, &$schema ) {
      if ( !is_array( $nodes ) || $depth > 6 ) {
        return;
      }
      foreach ( $nodes as $node ) {
        if ( !is_array( $node ) ) {
          continue;
        }
        $name = isset( $node['attributes']['name'] ) ? (string) $node['attributes']['name'] : '';
        $element = (string) ( $node['element'] ?? '' );
        if ( $name !== '' ) {
          $label = (string) ( $node['settings']['label'] ?? $name );
          $schema[ $name ] = [ 'label' => $label, 'type' => $element, 'ok' => in_array( $element, self::FLUENT_ELEMENTS, true ) ];
        }
        // A container's own children: columns of fields, or a nested field list. Sub-fields of
        // a name or address field are NOT walked into: their answers sit inside the parent's
        // value, so the parent's type decides.
        if ( $name === '' ) {
          if ( isset( $node['columns'] ) && is_array( $node['columns'] ) ) {
            foreach ( $node['columns'] as $col ) {
              $walk( $col['fields'] ?? [], $depth + 1 );
            }
          }
          if ( isset( $node['fields'] ) && is_array( $node['fields'] ) ) {
            $walk( $node['fields'], $depth + 1 );
          }
        }
      }
    };
    $walk( $doc['fields'], 0 );
    return $schema;
  }

  private function fluent_answers( $response ): ?array {
    $decoded = json_decode( (string) $response, true );
    return is_array( $decoded ) ? $decoded : null;
  }

  private function fluent_list( array $args, array $r ): array {
    global $wpdb;
    $state = $this->fluent_state();
    if ( !$state['readable'] ) {
      return $this->unreadable( $r, $state, 'entries' );
    }
    $page = $this->paging( $args );
    if ( is_string( $page ) ) {
      return $this->error( $r, $page );
    }
    [ $limit, $page_no, $offset ] = $page;
    $from = $this->date_arg( $args, 'date_from', false );
    $to = $this->date_arg( $args, 'date_to', true );
    foreach ( [ $from, $to ] as $d ) {
      if ( is_string( $d ) && !preg_match( '/^\d{4}-/', $d ) ) {
        return $this->error( $r, $d );
      }
    }
    $where = [];
    $params = [];
    if ( !empty( $args['form_id'] ) ) {
      $where[] = 's.form_id = %d';
      $params[] = (int) $args['form_id'];
    }
    if ( isset( $args['status'] ) && $args['status'] !== '' ) {
      if ( !preg_match( '/^[a-z_]{1,20}$/', (string) $args['status'] ) ) {
        return $this->error( $r, 'status has to be one word of letters and underscores.' );
      }
      $where[] = 's.status = %s';
      $params[] = (string) $args['status'];
    }
    else {
      $where[] = "s.status <> 'trashed'";
    }
    if ( $from ) {
      $where[] = 's.created_at >= %s';
      $params[] = $from;
    }
    if ( $to ) {
      $where[] = 's.created_at <= %s';
      $params[] = $to;
    }
    $clause = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
    $t = $wpdb->prefix . 'fluentform_submissions';
    $total = $this->rows( "SELECT COUNT(*) AS n FROM {$t} s{$clause}", $params );
    // Columns named, never SELECT *: ip, browser, device and the source URL are in this table
    // and are simply never read.
    $rows = $this->rows( "SELECT s.id, s.form_id, s.serial_number, s.status, s.created_at, s.response FROM {$t} s{$clause} ORDER BY s.id DESC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) );
    if ( is_string( $total ) || is_string( $rows ) ) {
      return $this->error( $r, 'The submissions could not be listed: ' . ( is_string( $total ) ? $total : $rows ), -32603 );
    }
    $schemas = [];
    $out = [];
    foreach ( $rows as $row ) {
      $fid = (int) $row['form_id'];
      if ( !array_key_exists( $fid, $schemas ) ) {
        $schemas[ $fid ] = $this->fluent_schema( $fid );
      }
      $answers = $this->fluent_answers( $row['response'] );
      $filtered = $this->filter_answers( $schemas[ $fid ] ?? [], $answers ?? [] );
      $out[] = [
        'entry_id' => (int) $row['id'],
        'form_id' => $fid,
        'serial_number' => (int) $row['serial_number'],
        'status' => (string) $row['status'],
        'created_at' => (string) $row['created_at'],
        'preview' => $this->render_fields( $filtered, true ),
        'withheld_count' => $filtered['withheld_count'],
      ];
    }
    return $this->json( $r, [ 'log_state' => $state, 'total' => (int) ( $total[0]['n'] ?? 0 ), 'page' => $page_no, 'limit' => $limit, 'entries' => $out ] );
  }

  private function fluent_get( array $args, array $r ): array {
    global $wpdb;
    $state = $this->fluent_state();
    if ( !$state['readable'] ) {
      return $this->unreadable( $r, $state, 'entry' );
    }
    $eid = (int) ( $args['entry_id'] ?? 0 );
    $rows = $this->rows( "SELECT id, form_id, serial_number, status, created_at, updated_at, response FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", [ $eid ] );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The submission could not be read: ' . $rows, -32603 );
    }
    if ( !$rows ) {
      return $this->json( $r, [ 'log_state' => $state, 'entry' => null, 'note' => 'No Fluent Forms submission with ID ' . $eid . ' exists.' ] );
    }
    $row = $rows[0];
    $fid = (int) $row['form_id'];
    $schema = $this->fluent_schema( $fid );
    $answers = $this->fluent_answers( $row['response'] );
    $filtered = $this->filter_answers( $schema ?? [], $answers ?? [] );
    $title = $this->rows( "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d", [ $fid ] );
    $entry = [
      'entry_id' => $eid,
      'form_id' => $fid,
      'form_title' => ( is_array( $title ) && $title ) ? (string) $title[0]['title'] : '',
      'serial_number' => (int) $row['serial_number'],
      'status' => (string) $row['status'],
      'created_at' => (string) $row['created_at'],
      'updated_at' => (string) $row['updated_at'],
      'fields' => $this->render_fields( $filtered, false ),
      'withheld' => $filtered['withheld'],
      'withheld_count' => $filtered['withheld_count'],
    ];
    if ( $schema === null ) {
      $entry['warning'] = 'The form definition for this submission could not be read (the form may have been deleted), so its fields cannot be typed and none of the answers are returned.';
    }
    elseif ( $answers === null ) {
      $entry['warning'] = 'The stored response is not a JSON document this tool can read, so no answers are returned.';
    }
    return $this->json( $r, [ 'log_state' => $state, 'entry' => $entry ] );
  }

  #endregion

  #region Ninja Forms

  private function ninja_state(): array {
    global $wpdb;
    $state = [
      'plugin_loaded' => $this->ninja_loaded(),
      'tables' => [ 'present' => [], 'missing' => [] ],
      'submission_post_type_registered' => post_type_exists( self::NINJA_SUB_TYPE ),
      'readable' => false,
      'submissions' => null,
      'forms' => null,
    ];
    if ( !$state['plugin_loaded'] ) {
      $state['note'] = 'Ninja Forms is not loaded, so there is no submission log to read. This is not the same as the log being empty.';
      return $state;
    }
    $state['tables'] = $this->tables_report( [ 'nf3_forms', 'nf3_fields' ] );
    if ( $state['tables']['missing'] ) {
      $state['note'] = 'Ninja Forms is loaded but its tables (' . implode( ', ', $state['tables']['missing'] ) . ') do not exist, so there are no forms to read answers against. This is not the same as there being no submissions.';
      return $state;
    }
    $c = $this->rows( "SELECT COUNT(*) AS n, MAX(post_date_gmt) AS newest FROM {$wpdb->posts} WHERE post_type = %s", [ self::NINJA_SUB_TYPE ] );
    $f = $this->rows( "SELECT COUNT(*) AS n FROM {$wpdb->prefix}nf3_forms" );
    if ( is_string( $c ) || is_string( $f ) || !$c || !$f ) {
      $state['note'] = 'Ninja Forms\' data could not be read: ' . ( is_string( $c ) ? $c : ( is_string( $f ) ? $f : 'the query returned nothing' ) );
      return $state;
    }
    $state['readable'] = true;
    $state['submissions'] = (int) $c[0]['n'];
    $state['forms'] = (int) $f[0]['n'];
    return array_merge( $state, $this->newest_report( $c[0]['newest'] ) );
  }

  /** form_id => bool|null: does the form have an active action that records submissions (UNVERIFIED: nf3_actions, type save). */
  private function ninja_recording(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'nf3_actions';
    if ( !$this->table_exists( $table ) ) {
      return [];
    }
    $rows = $this->rows( "SELECT parent_id, active FROM {$table} WHERE `type` = 'save'" );
    if ( is_string( $rows ) ) {
      return [];
    }
    $out = [];
    foreach ( $rows as $row ) {
      $out[ (int) $row['parent_id'] ] = ( $out[ (int) $row['parent_id'] ] ?? false ) || (int) $row['active'] === 1;
    }
    return $out;
  }

  private function ninja_briefing( array $args, array $r ): array {
    global $wpdb;
    $state = $this->ninja_state();
    if ( !$state['readable'] ) {
      return $this->json( $r, [ 'log_state' => $state, 'counts' => null, 'note' => 'No counts are reported, because Ninja Forms\' data is not readable. A zero would read as "nothing arrived".' ] );
    }
    $days = $this->window_days( $args );
    $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    $by_status = $this->rows( "SELECT post_status AS k, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_date_gmt >= %s GROUP BY post_status", [ self::NINJA_SUB_TYPE, $since ] );
    $by_form = $this->rows(
      "SELECT pm.meta_value AS k, COUNT(*) AS n FROM {$wpdb->posts} p
       JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_form_id'
       WHERE p.post_type = %s AND p.post_date_gmt >= %s GROUP BY pm.meta_value ORDER BY n DESC LIMIT 100",
      [ self::NINJA_SUB_TYPE, $since ]
    );
    $forms = $this->rows( "SELECT id, title FROM {$wpdb->prefix}nf3_forms ORDER BY id LIMIT 300" );
    if ( is_string( $by_status ) || is_string( $by_form ) || is_string( $forms ) ) {
      return $this->error( $r, 'The submissions could not be counted: ' . ( is_string( $by_status ) ? $by_status : ( is_string( $by_form ) ? $by_form : $forms ) ), -32603 );
    }
    $recording = $this->ninja_recording();
    $counts = [];
    foreach ( $by_form as $row ) {
      $counts[ (int) $row['k'] ] = (int) $row['n'];
    }
    $forms_out = [];
    foreach ( $forms as $form ) {
      $fid = (int) $form['id'];
      $forms_out[] = [
        'form_id' => $fid,
        'title' => (string) $form['title'],
        'submissions_in_window' => $counts[ $fid ] ?? 0,
        'records_submissions' => $recording ? ( $recording[ $fid ] ?? false ) : null,
      ];
    }
    $status_out = [];
    foreach ( $by_status as $row ) {
      $status_out[ (string) $row['k'] === 'publish' ? 'active' : (string) $row['k'] ] = (int) $row['n'];
    }
    return $this->json( $r, [
      'log_state' => $state,
      'window' => [ 'days' => $days, 'since' => gmdate( 'c', strtotime( $since . ' UTC' ) ) ],
      'counts' => [ 'total' => (int) array_sum( $status_out ), 'by_status' => (object) $status_out, 'by_form' => $forms_out ],
      'note' => 'records_submissions is whether the form has an active action that stores submissions. false means the form cannot have any, so its zero is "not kept". null means that could not be determined (the actions table is missing or unreadable), which is not a no.',
    ] );
  }

  /** A Ninja form's field schema: field id => label/type/ok, or null when it cannot be read. */
  private function ninja_schema( int $form_id ): ?array {
    global $wpdb;
    $rows = $this->rows( "SELECT id, `label`, `type`, `key` FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d ORDER BY `order` ASC, id ASC", [ $form_id ] );
    if ( is_string( $rows ) || !$rows ) {
      return null;
    }
    $schema = [];
    foreach ( $rows as $row ) {
      $type = (string) $row['type'];
      // The field's key is the only readable name Ninja Forms gives it (the answer is filed
      // under the numeric id), so it is also asked about, as a hint beside the id.
      $schema[ (string) $row['id'] ] = [ 'label' => (string) $row['label'], 'type' => $type, 'hint' => (string) ( $row['key'] ?? '' ), 'ok' => in_array( $type, self::NINJA_TYPES, true ) ];
    }
    return $schema;
  }

  /**
  * Answers on a submission, read ONLY for the field ids the schema names, so nothing is
  * read that the form does not define. The schema's names are the keys; a submission's own
  * meta is never enumerated.
  */
  private function ninja_answers( int $sub_id, ?array $schema ): array {
    $answers = [];
    foreach ( array_keys( $schema ?? [] ) as $field_id ) {
      $meta = get_post_meta( $sub_id, '_field_' . $field_id );
      if ( $meta ) {
        $answers[ (string) $field_id ] = maybe_unserialize( $meta[0] );
      }
    }
    return $answers;
  }

  private function ninja_statuses( $status ) {
    if ( $status === null || $status === '' ) {
      return [ 'publish' ];
    }
    $map = [ 'active' => [ 'publish' ], 'trash' => [ 'trash' ], 'all' => [ 'publish', 'trash' ] ];
    return $map[ (string) $status ] ?? 'status has to be one of: active, trash, all.';
  }

  private function ninja_list( array $args, array $r ): array {
    global $wpdb;
    $state = $this->ninja_state();
    if ( !$state['readable'] ) {
      return $this->unreadable( $r, $state, 'entries' );
    }
    $statuses = $this->ninja_statuses( $args['status'] ?? null );
    if ( is_string( $statuses ) ) {
      return $this->error( $r, $statuses );
    }
    $page = $this->paging( $args );
    if ( is_string( $page ) ) {
      return $this->error( $r, $page );
    }
    [ $limit, $page_no, $offset ] = $page;
    $from = $this->date_arg( $args, 'date_from', false );
    $to = $this->date_arg( $args, 'date_to', true );
    foreach ( [ $from, $to ] as $d ) {
      if ( is_string( $d ) && !preg_match( '/^\d{4}-/', $d ) ) {
        return $this->error( $r, $d );
      }
    }
    $where = [ 'p.post_type = %s', 'p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')' ];
    $params = array_merge( [ self::NINJA_SUB_TYPE ], $statuses );
    if ( !empty( $args['form_id'] ) ) {
      $where[] = 'pm.meta_value = %s';
      $params[] = (string) (int) $args['form_id'];
    }
    if ( $from ) {
      $where[] = 'p.post_date_gmt >= %s';
      $params[] = $from;
    }
    if ( $to ) {
      $where[] = 'p.post_date_gmt <= %s';
      $params[] = $to;
    }
    $base = "FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_form_id' WHERE " . implode( ' AND ', $where );
    $total = $this->rows( "SELECT COUNT(*) AS n {$base}", $params );
    $rows = $this->rows( "SELECT p.ID AS id, p.post_date_gmt AS date_gmt, p.post_status AS status, pm.meta_value AS form_id {$base} ORDER BY p.ID DESC LIMIT %d OFFSET %d", array_merge( $params, [ $limit, $offset ] ) );
    if ( is_string( $total ) || is_string( $rows ) ) {
      return $this->error( $r, 'The submissions could not be listed: ' . ( is_string( $total ) ? $total : $rows ), -32603 );
    }
    $schemas = [];
    $out = [];
    foreach ( $rows as $row ) {
      $fid = (int) $row['form_id'];
      if ( !array_key_exists( $fid, $schemas ) ) {
        $schemas[ $fid ] = $this->ninja_schema( $fid );
      }
      $filtered = $this->filter_answers( $schemas[ $fid ] ?? [], $this->ninja_answers( (int) $row['id'], $schemas[ $fid ] ) );
      $out[] = [
        'entry_id' => (int) $row['id'],
        'form_id' => $fid,
        'date' => (string) $row['date_gmt'] . ' UTC',
        'status' => (string) $row['status'] === 'publish' ? 'active' : (string) $row['status'],
        'preview' => $this->render_fields( $filtered, true ),
        'withheld_count' => $filtered['withheld_count'],
      ];
    }
    return $this->json( $r, [ 'log_state' => $state, 'total' => (int) ( $total[0]['n'] ?? 0 ), 'page' => $page_no, 'limit' => $limit, 'entries' => $out ] );
  }

  private function ninja_get( array $args, array $r ): array {
    global $wpdb;
    $state = $this->ninja_state();
    if ( !$state['readable'] ) {
      return $this->unreadable( $r, $state, 'entry' );
    }
    $eid = (int) ( $args['entry_id'] ?? 0 );
    $rows = $this->rows( "SELECT ID AS id, post_date_gmt AS date_gmt, post_status AS status FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", [ $eid, self::NINJA_SUB_TYPE ] );
    if ( is_string( $rows ) ) {
      return $this->error( $r, 'The submission could not be read: ' . $rows, -32603 );
    }
    if ( !$rows ) {
      return $this->json( $r, [ 'log_state' => $state, 'entry' => null, 'note' => 'No Ninja Forms submission with ID ' . $eid . ' exists.' ] );
    }
    $fid = (int) get_post_meta( $eid, '_form_id', true );
    $schema = $this->ninja_schema( $fid );
    $filtered = $this->filter_answers( $schema ?? [], $this->ninja_answers( $eid, $schema ) );
    $title = $this->rows( "SELECT title FROM {$wpdb->prefix}nf3_forms WHERE id = %d", [ $fid ] );
    $entry = [
      'entry_id' => $eid,
      'form_id' => $fid,
      'form_title' => ( is_array( $title ) && $title ) ? (string) $title[0]['title'] : '',
      'sequence_number' => (int) get_post_meta( $eid, '_seq_num', true ),
      'date' => (string) $rows[0]['date_gmt'] . ' UTC',
      'status' => (string) $rows[0]['status'] === 'publish' ? 'active' : (string) $rows[0]['status'],
      'fields' => $this->render_fields( $filtered, false ),
      'withheld' => $filtered['withheld'],
      'withheld_count' => $filtered['withheld_count'],
    ];
    if ( $schema === null ) {
      $entry['warning'] = 'The form definition for this submission could not be read (the form may have been deleted), so its fields cannot be typed and none of the answers are returned.';
    }
    return $this->json( $r, [ 'log_state' => $state, 'entry' => $entry ] );
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
