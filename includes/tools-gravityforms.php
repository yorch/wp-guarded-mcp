<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Gravity Forms: submissions, on their own switch.
*
* This group exists because the generic tools cannot reach the data at all, not because
* they reach it wrongly. An entry lives in wp_gf_entry, its answers in wp_gf_entry_meta
* keyed by input id, its notes in wp_gf_entry_notes; a form's schema lives in
* wp_gf_form_meta as a serialized array. Nothing in wp_get_posts, wp_get_post_meta or the
* option tools touches any of that, so there is no silent-success fix here the way there is
* for Yoast or Kirki: there is simply no other way in.
*
* Two things shape every choice below.
*
* The first is that an entry is the one record on a WordPress site that a member of the
* public wrote, that nobody with administrator rights typed, and that a business may be
* legally obliged to keep. The threat is therefore not a bad read, it is a bad write: the
* agent reads a message field, the message contains an instruction, and the instruction
* asks for a value in some entry to be corrected, or an entry to be removed. A submission
* edit destroys the only copy and there is no restore tool behind it, so gf_update_entry_field
* carries the same server-minted two-step token as a deletion. The bar is set by the effect,
* not by whether the tool's name contains "delete".
*
* The second is that the form schema names which fields exist, and that is what makes a
* bounded write possible at all: an input id can be checked against the form before it is
* written, and a field whose type means something other than free text (a password, an
* upload, an opt-in the business needs to be able to trust) is refused rather than
* overwritten. Writing an entry field also re-runs whatever add-on hooks are attached to
* the form, so the tool is not the isolated data edit its name suggests, and it says so.
*
* Access levels follow the same reasoning as the WooCommerce group: form definitions are
* read, because a form is configuration; anything that returns or changes a submission is
* admin, because a submission is somebody's personal data. A password-type field is never
* returned and never writable, because Gravity Forms hands its value back in plain text
* through this API and a password reaching a model, an audit row or the debug log is the
* exact leak this plugin's redaction rules exist to prevent.
*/
class GMCP_Tools_Gravityforms {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [
    'gf_update_entry', 'gf_update_entry_field', 'gf_delete_entry',
    'gf_add_entry_note', 'gf_set_form_active',
  ];

  /** Entry statuses Gravity Forms stores. Nothing else is writable. */
  const STATUSES = [ 'active', 'spam', 'trash' ];

  /**
  * What "no status filter" means: the Gravity Forms admin's own default view.
  *
  * Spam is included because a spam entry is one an operator still wants to see and
  * manage; trash is not, because it is already deleted as far as the site is concerned.
  * This is stated with the version it was measured on rather than as "the admin default",
  * which is a claim that goes stale silently.
  */
  const DEFAULT_STATUS_SET = [ 'active', 'spam' ];

  /** Field types a submission value may be written through. Everything else is refused. */
  const WRITE_ALLOWED_TYPES = [
    'text', 'textarea', 'number', 'select', 'radio', 'checkbox',
    'email', 'website', 'phone', 'date', 'time', 'name', 'address',
  ];

  /**
  * Field types whose value is never returned.
  *
  * Gravity Forms' password field returns its value in plain text through GFAPI::get_entry
  * (measured on 3.1.2), and encrypts it at rest only when the site has opted in through the
  * gform_encrypt_password filter, which defaults to off. So returning it would put a
  * credential in front of a model and into the audit log's detail column. Refused on read
  * as well as write, because both directions are the same leak. A credit-card field is
  * excluded for the same reason: whatever it holds is financial data.
  *
  * This list is not only about gf_get_entry. A field filter or a free-text search on one of
  * these inputs is a yes/no oracle that reads the value a character at a time, so both of
  * those paths check this list too rather than trusting the direct read to be the only way.
  */
  const READ_EXCLUDED_TYPES = [ 'password', 'creditcard' ];

  /** Entry query operators accepted from a caller. Anything else is refused, not passed on. */
  const ALLOWED_OPERATORS = [ 'is', '=', 'isnot', '<>', '!=', 'contains', 'in', 'not in' ];

  const DEFAULT_LIMIT = 20;
  const MAX_LIMIT = 100;
  /** A page beyond this is refused rather than turned into an enormous OFFSET. */
  const MAX_PAGE = 10000;
  const FORM_LIST_CAP = 300;
  const PREVIEW_FIELDS = 3;
  const PREVIEW_CHARS = 80;

  /** Set by a write that turned out to be a no-op, so gmcp_mutate does not fire for it. */
  private $noop = false;

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
  * Whether Gravity Forms is really usable, checked per call rather than only at
  * registration.
  *
  * The class is constructed whenever the group is switched on, but Gravity Forms can be
  * deactivated between that and a call. The version constant plus the API class is the
  * same shape as the Yoast group's defined('WPSEO_VERSION') check: a bare class_exists on
  * an autoloaded class can be true for a plugin that registered an autoloader and then
  * bailed out, and every GFAPI call below would be a fatal rather than a sentence.
  */
  private function loaded(): bool {
    // GFCommon and GFForms are classes Gravity Forms defines when its bootstrap reaches
    // common.php, and GFAPI when it reaches includes/api.php; the two are loaded a few
    // lines apart. A bare class_exists('GFAPI') would also be true on a copy that
    // registered its autoloader and then bailed out, which is the trap the Elementor and
    // Yoast groups each document. Requiring both, plus the method the tools call, is the
    // same shape as those.
    return class_exists( 'GFCommon' )
      && class_exists( 'GFAPI' )
      && method_exists( 'GFAPI', 'get_entries' );
  }

  private function tools(): array {
    return [
      'gf_list_forms' => [
        'name' => 'gf_list_forms',
        'description' => 'List every Gravity Form on the site that is not in the trash, with its ID, title, whether it is active, how many fields it has, and how many entries it holds. Entry counts are the active and spam entries (the default view in the Gravity Forms admin); trashed entries are counted separately and a trashed form is not listed at all, so the totals here describe what is live rather than everything the tables have ever held. Use gf_get_form for one form\'s fields.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'active_only' => [ 'type' => 'boolean', 'description' => 'Only forms that are currently accepting submissions. Default false, which also lists inactive forms.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'gf_get_form' => [
        'name' => 'gf_get_form',
        'description' => 'Read one Gravity Form\'s schema: its ID, title, description, whether it is active, how many entries it holds, and its fields. Each field is returned as id, label, type, whether it is required, and for multi-input fields (name, address and the like) the sub-inputs with the input id each one is written through; choice fields also return their choices. This is the map you need before calling gf_update_entry_field, because a submission value is addressed by input id, not by field label. Form CONFIGURATION is returned (labels, types, choices) but the notification and confirmation settings are reduced to a count, because those carry recipient addresses and redirect URLs with tokens in them. Submissions are never returned here; that is gf_get_entry, and it is an admin tool.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'form_id' => [ 'type' => 'integer', 'description' => 'The form ID, as shown by gf_list_forms.' ],
          ],
          'required' => [ 'form_id' ],
        ],
        'accessLevel' => 'read',
      ],
      'gf_forms_briefing' => [
        'name' => 'gf_forms_briefing',
        'description' => 'One call that answers "what is the state of the forms on this site": how many forms exist, how many are active, entries by status (active, spam, trash), how many entries arrived in the last seven days, and when the newest entry was submitted. Counts only: no submission content and no personal data is returned, which is why this is a read tool while the tools that return individual entries are not. Trashed forms and their entries are excluded, as in gf_list_forms.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => new \stdClass(),
        ],
        'accessLevel' => 'read',
      ],
      'gf_list_entries' => [
        'name' => 'gf_list_entries',
        'description' => 'Search and page through form submissions. Returns each entry\'s ID, form, date, status, read/starred flags, and a short preview of its first few answers with their field labels, so a caller can tell entries apart without pulling every value. This returns personal data that members of the public submitted, so it is an admin tool, and the preview is bounded (three fields, each shortened) rather than the full submission. Filter by form, by status (active, spam, trash or all), by a substring search, by one field filter, and by date. "search" needs form_id and looks for the term inside the form\'s readable answer fields; it deliberately does not use Gravity Forms\' own "any field" search, which compares the term against every meta value including a password field\'s and with the count in the reply is a way to read one a character at a time. A field filter on a password or credit-card field is refused for the same reason, and search and field_filter cannot be combined because Gravity Forms applies one match mode to a whole filter set. Gravity Forms silently falls back to matching EVERYTHING when it does not recognise an operator or cannot parse a date, so the operator, the filtered input id and the date are all checked here and refused rather than passed through.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'form_id' => [ 'type' => 'integer', 'description' => 'Limit to one form. Omit or 0 for all forms.' ],
            'status' => [ 'type' => 'string', 'description' => 'One of active, spam, trash, or all. Omit for the admin default view: active and spam, no trash.' ],
            'search' => [ 'type' => 'string', 'description' => 'Free-text search across the values of any field.' ],
            'field_filter' => [
              'type' => 'object',
              'description' => 'Filter by one field. Requires form_id. { "key": "3" (input id), "value": "text", "operator": "contains" }. Operators: is, isnot, contains, in, not in. Defaults to is.',
              'properties' => [
                'key' => [ 'type' => 'string' ],
                'value' => [ 'type' => [ 'string', 'array' ] ],
                'operator' => [ 'type' => 'string' ],
              ],
            ],
            'date_from' => [ 'type' => 'string', 'description' => 'Earliest entry date, YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.' ],
            'date_to' => [ 'type' => 'string', 'description' => 'Latest entry date, same formats.' ],
            'order' => [ 'type' => 'string', 'description' => 'asc or desc by entry ID. Default desc (newest first).' ],
            'page' => [ 'type' => 'integer', 'description' => 'Page number, 1-based. Default 1.' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Entries per page, 1 to 100. Default 20.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_get_entry' => [
        'name' => 'gf_get_entry',
        'description' => 'Read one full submission: every answer keyed by input id and labelled with its field, plus the entry\'s date, status, read and starred flags, how many notes it has, and who created it. This is personal data submitted by a member of the public, so it is an admin tool. Password-type fields are never returned, because Gravity Forms hands their value back in plain text through this API. The tracking fields (IP address, user agent, source URL) are omitted unless include_tracking is true, because they are personal data that most callers do not need. Note that the reply text is recorded in this plugin\'s audit log for its retention window, as every tool reply is; the audit log is administrator-only and is where the record of who read what lives.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'entry_id' => [ 'type' => 'integer', 'description' => 'The entry ID, as shown by gf_list_entries.' ],
            'include_tracking' => [ 'type' => 'boolean', 'description' => 'Include the submitting IP address, user agent and source URL. Default false.' ],
          ],
          'required' => [ 'entry_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_update_entry' => [
        'name' => 'gf_update_entry',
        'description' => 'Change an entry\'s status or its read/starred flags. Status is one of active, spam or trash; moving a submission to spam or trash that way is reversible, and this writes no field VALUES. The change is verified by reading the entry back, and the reply says whether it actually changed or already held that value. Not recorded by wp_undo_change: Gravity Forms stores entries in its own tables, which the change journal does not read, so there is no undo beyond setting the value back. Treating an entry as spam or trash may also run whatever add-on hooks the form has attached.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'entry_id' => [ 'type' => 'integer' ],
            'status' => [ 'type' => 'string', 'description' => 'active, spam or trash.' ],
            'is_read' => [ 'type' => 'boolean' ],
            'is_starred' => [ 'type' => 'boolean' ],
          ],
          'required' => [ 'entry_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_update_entry_field' => [
        'name' => 'gf_update_entry_field',
        'description' => 'Overwrite one answer in one submission. THIS IS DESTRUCTIVE: the previous value is replaced and is not recorded anywhere, and there is no restore tool, so the old answer cannot be recovered even by hand afterwards. Because of that it takes two steps, exactly like a deletion: the first call changes nothing and returns a confirmation token, and only a second call carrying that token writes. The field must be a question the form actually asks, addressed by input id (see gf_get_form); the value is written through Gravity Forms\' own API, which re-runs the form\'s add-on hooks. Fields whose type is not plain text-ish are refused: a password, an upload, an opt-in/consent field, a repeater, or anything a plugin added are refused by name, because changing them is either a leak or a change to something the business relies on. Writing the same value it already holds reports that nothing was written and needs no second step.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'entry_id' => [ 'type' => 'integer' ],
            'input_id' => [ 'type' => 'string', 'description' => 'The input id to write: a field id like "3", or a sub-input id like "1.3" for one part of a name or address field.' ],
            'value' => [ 'type' => [ 'string', 'number' ], 'description' => 'The new value. Scalar only; for a multi-input field write each sub-input separately.' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token. Call without it first; the first reply supplies it.' ],
          ],
          'required' => [ 'entry_id', 'input_id', 'value' ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_delete_entry' => [
        'name' => 'gf_delete_entry',
        'description' => 'Permanently delete one submission. Two steps: the first call changes nothing and returns a confirmation token naming the entry, and only a second call carrying it deletes. It removes the entry row, its answers and its notes. It does not necessarily remove files the entry uploaded (those live in the uploads directory) or data an add-on stored in another table, so "deleted" here means the submission itself. There is no restore tool and the deletion is not recorded by wp_undo_change.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'entry_id' => [ 'type' => 'integer' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token. Call without it first; the first reply supplies it.' ],
          ],
          'required' => [ 'entry_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_list_entry_notes' => [
        'name' => 'gf_list_entry_notes',
        'description' => 'List the notes attached to one submission, oldest first: each note\'s id, date, author name, type and text. Notes are staff annotations about an entry (and any notes the form\'s add-ons added); they can contain personal data, so this is an admin tool.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'entry_id' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'entry_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_add_entry_note' => [
        'name' => 'gf_add_entry_note',
        'description' => 'Add a note to one submission, attributed to the authenticated administrator and typed as a user note. The text is sanitized and is readable from the entry afterwards, so treat it as something that will be read back, including by an agent in a later session. Not recorded by wp_undo_change; deleting the note is a separate manual step.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'entry_id' => [ 'type' => 'integer' ],
            'note' => [ 'type' => 'string', 'description' => 'The note text.' ],
          ],
          'required' => [ 'entry_id', 'note' ],
        ],
        'accessLevel' => 'admin',
      ],
      'gf_set_form_active' => [
        'name' => 'gf_set_form_active',
        'description' => 'Turn a form on or off, so it stops or starts accepting submissions. Reversible: call it again with the opposite value to put it back, which is the only way back because this is not recorded by wp_undo_change. A page already open in someone\'s browser may fail to submit if the form is switched off mid-completion. The change is verified by reading the form back.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'form_id' => [ 'type' => 'integer' ],
            'active' => [ 'type' => 'boolean', 'description' => 'true to accept submissions, false to stop.' ],
          ],
          'required' => [ 'form_id', 'active' ],
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
    $this->noop = false;

    // Belt and braces, the same as the other optional groups. The class is only
    // constructed when the group is switched on, but Gravity Forms can be deactivated
    // between that and this call, and every GFAPI call below would then be a fatal.
    if ( !$this->loaded() ) {
      return $this->error( $r, 'Gravity Forms is not loaded on this site, so its tools cannot run.' );
    }

    try {
      switch ( $tool ) {
        case 'gf_list_forms':        $r = $this->list_forms( $args, $r ); break;
        case 'gf_get_form':          $r = $this->get_form( $args, $r ); break;
        case 'gf_forms_briefing':    $r = $this->forms_briefing( $args, $r ); break;
        case 'gf_list_entries':      $r = $this->list_entries( $args, $r ); break;
        case 'gf_get_entry':         $r = $this->get_entry( $args, $r ); break;
        case 'gf_update_entry':      $r = $this->update_entry( $args, $r ); break;
        case 'gf_update_entry_field':$r = $this->update_entry_field( $args, $r ); break;
        case 'gf_delete_entry':      $r = $this->delete_entry( $args, $r ); break;
        case 'gf_list_entry_notes':  $r = $this->list_entry_notes( $args, $r ); break;
        case 'gf_add_entry_note':    $r = $this->add_entry_note( $args, $r ); break;
        case 'gf_set_form_active':   $r = $this->set_form_active( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'Gravity Forms threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }

    if ( empty( $r['result']['isError'] ) && !$this->noop && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

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

  /**
  * Every form that is not in the trash, active and inactive alike, keyed by ID.
  *
  * get_forms($active,$trash) treats $active=false as "only INACTIVE", not "any", so
  * asking once either way misses half the forms. Trashed forms are deliberately left out;
  * every caller that reports totals says so, rather than presenting a site-wide number
  * that quietly omits them.
  */
  private function forms_all(): array {
    $out = [];
    foreach ( [ true, false ] as $active ) {
      $forms = \GFAPI::get_forms( $active, false );
      if ( !is_array( $forms ) ) {
        continue;
      }
      foreach ( $forms as $form ) {
        if ( is_array( $form ) && isset( $form['id'] ) ) {
          $out[ (int) $form['id'] ] = $form;
        }
      }
    }
    ksort( $out, SORT_NUMERIC );
    return $out;
  }

  /** A form by ID, or null. Trashed forms are returned too, so callers can say what they are. */
  private function form( int $form_id ): ?array {
    if ( $form_id <= 0 ) {
      return null;
    }
    $form = \GFAPI::get_form( $form_id );
    return is_array( $form ) && isset( $form['id'] ) ? $form : null;
  }

  /** A submission by ID, or null. Leaves WP_Error and null indistinguishable, which is what callers want. */
  private function entry( int $entry_id ): ?array {
    if ( $entry_id <= 0 ) {
      return null;
    }
    $entry = \GFAPI::get_entry( $entry_id );
    return is_array( $entry ) && isset( $entry['id'] ) ? $entry : null;
  }

  /**
  * Notes for an entry, always a list of arrays.
  *
  * Two traps are handled here rather than at each call site. GFAPI::get_notes returns
  * FALSE when there are none (count(false) is a TypeError in PHP 8), and it returns the
  * rows as stdClass objects, not arrays, so `$note['value']` fatals on real data while
  * looking correct. Both were hit while writing this file; a note was written and the tool
  * then reported an error, which is the worst of both shapes.
  */
  private function notes( int $entry_id ): array {
    $notes = \GFAPI::get_notes( [ 'entry_id' => $entry_id ] );
    if ( !is_array( $notes ) ) {
      return [];
    }
    return array_map( function ( $note ) { return (array) $note; }, $notes );
  }

  /**
  * The sub-inputs a field exposes to an entry, in GF's own terms, or an empty array.
  *
  * GF_Field::$inputs is populated by the form editor for multi-input fields, but a form
  * built through the API can leave it empty even when the field is one (a checkbox stores
  * its values under "4.1", "4.2" while the field itself has no inputs array). Falling back
  * to get_entry_inputs() and then synthesizing checkbox inputs from the choices keeps
  * resolve_input() honest on both: important because an undeclared multi-input field would
  * otherwise let its aggregate id ("4") be treated as a writable single input, and GF
  * stores that as an empty string, so the write would succeed and change nothing.
  */
  private function field_inputs( $field, string $field_id ): array {
    if ( is_array( $field->inputs ?? null ) && $field->inputs ) {
      return $field->inputs;
    }
    if ( method_exists( $field, 'get_entry_inputs' ) ) {
      $derived = $field->get_entry_inputs();
      if ( is_array( $derived ) && $derived ) {
        return $derived;
      }
    }
    if ( ( $field->type ?? '' ) === 'checkbox' && is_array( $field->choices ?? null ) ) {
      $out = [];
      foreach ( $field->choices as $i => $choice ) {
        if ( is_array( $choice ) ) {
          $out[] = [ 'id' => $field_id . '.' . ( $i + 1 ), 'label' => (string) ( $choice['text'] ?? '' ) ];
        }
      }
      return $out;
    }
    return [];
  }

  /**
  * Map every input id on a form to the label a person would recognise.
  *
  * Keyed by input id because that is what an entry value is keyed by, and because two
  * fields can share a label. A multi-input field (name, address) contributes one entry per
  * sub-input; the field's own id is recorded as an aggregate so a caller can be told to use
  * a sub-input rather than writing the aggregate, which Gravity Forms stores as an empty
  * string.
  */
  private function inputs( array $form ): array {
    $map = [];
    foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
      if ( !is_object( $field ) || !isset( $field->id ) ) {
        continue;
      }
      $fid = (string) $field->id;
      $type = (string) ( $field->type ?? '' );
      $label = (string) ( $field->label ?? '' );
      $subs = $this->field_inputs( $field, $fid );

      if ( $subs ) {
        $map[ $fid ] = [ 'label' => $label, 'type' => $type, 'aggregate' => true ];
        foreach ( $subs as $input ) {
          if ( isset( $input['id'] ) ) {
            $map[ (string) $input['id'] ] = [
              'label' => $label . ': ' . (string) ( $input['label'] ?? '' ),
              'type' => $type,
              'aggregate' => false,
            ];
          }
        }
      } else {
        $map[ $fid ] = [ 'label' => $label, 'type' => $type, 'aggregate' => false ];
      }
    }
    return $map;
  }

  /**
  * Resolve an input id against a form, refusing an aggregate id of a multi-input field.
  *
  * @return array [ $info|null, $message ] — one of the two is set.
  */
  private function resolve_input( array $form, string $input_id ): array {
    $input_id = trim( $input_id );
    if ( $input_id === '' || !preg_match( '/^[0-9]+(\.[0-9]+)?$/', $input_id ) ) {
      return [ null, 'An input id must look like "3" or "1.3", not "' . $input_id . '".' ];
    }
    $map = $this->inputs( $form );
    if ( !isset( $map[ $input_id ] ) ) {
      return [ null, 'The form has no input with id "' . $input_id . '". Call gf_get_form for the list of input ids; a value can only be written to an input the form actually asks for.' ];
    }
    if ( !empty( $map[ $input_id ]['aggregate'] ) ) {
      return [ null, 'Input "' . $input_id . '" is the whole multi-input field, which Gravity Forms stores empty. Write each part separately, e.g. "1.3" and "1.6" for a name field.' ];
    }
    $info = $map[ $input_id ];
    $info['input_id'] = $input_id;
    return [ $info, '' ];
  }

  /** Normalize an entry value for display: scalars as-is, arrays joined, nothing evaluated. */
  private function display_value( $value ): string {
    if ( is_array( $value ) ) {
      $parts = array_map( function ( $v ) { return is_scalar( $v ) ? (string) $v : ''; }, $value );
      return implode( ', ', array_filter( $parts, function ( $v ) { return $v !== ''; } ) );
    }
    if ( is_scalar( $value ) || $value === null ) {
      return (string) $value;
    }
    return '';
  }

  /** A short, tag-free preview of a value for a listing or a confirmation summary. */
  private function preview( $value ): string {
    $s = wp_strip_all_tags( $this->display_value( $value ) );
    $s = trim( preg_replace( '/\s+/', ' ', $s ) );
    return mb_substr( $s, 0, self::PREVIEW_CHARS );
  }

  /**
  * The first few non-empty answers of an entry, as {input_id,label,value} rows.
  *
  * Used by the list view and by the deletion summary so a person can see WHICH submission
  * is about to disappear. Bounded on purpose: this is the one place entry content appears
  * outside gf_get_entry.
  */
  private function entry_preview( array $form, array $entry, int $count = self::PREVIEW_FIELDS ): array {
    $map = $this->inputs( $form );
    $reserved = $this->reserved_keys();
    $out = [];
    foreach ( $entry as $key => $value ) {
      if ( count( $out ) >= $count ) {
        break;
      }
      $key = (string) $key;
      if ( isset( $reserved[ $key ] ) || !isset( $map[ $key ] ) || !empty( $map[ $key ]['aggregate'] ) ) {
        continue;
      }
      if ( in_array( $map[ $key ]['type'], self::READ_EXCLUDED_TYPES, true ) ) {
        continue;
      }
      $text = $this->preview( $value );
      if ( $text === '' ) {
        continue;
      }
      $out[] = [ 'input_id' => $key, 'label' => $map[ $key ]['label'], 'value' => $text ];
    }
    return $out;
  }

  /** Entry keys that are metadata, not answers, so they are not mistaken for fields. */
  private function reserved_keys(): array {
    return array_flip( [
      'id', 'form_id', 'post_id', 'date_created', 'date_updated', 'is_starred', 'is_read',
      'ip', 'source_url', 'user_agent', 'currency', 'payment_status', 'payment_date',
      'payment_amount', 'payment_method', 'transaction_id', 'is_fulfilled', 'created_by',
      'transaction_type', 'status', 'source_id', 'submission_speeds', 'meta',
    ] );
  }

  /** Normalize the "any/one/several" status filter into the array Gravity Forms expects. */
  private function status_set( $status ): array {
    if ( !is_string( $status ) || trim( $status ) === '' ) {
      return self::DEFAULT_STATUS_SET;
    }
    $status = strtolower( trim( $status ) );
    if ( $status === 'all' ) {
      return self::STATUSES;
    }
    if ( in_array( $status, self::STATUSES, true ) ) {
      return [ $status ];
    }
    return self::DEFAULT_STATUS_SET;
  }

  /**
  * Validate a date filter.
  *
  * Shape is not enough. Gravity Forms wraps the value in new DateTime(), which does not
  * throw on 2024-02-30 or 2024-13-45 — it rolls them over — so a calendar-invalid date is
  * applied to a range nobody asked for and reports no error. This therefore parses the
  * value and requires it to round-trip, which rejects both a shape error and a date that
  * does not exist. A value Gravity Forms cannot parse matches every entry instead of none,
  * so the answer is to refuse, not to pass it on.
  */
  private function valid_date( string $value ): bool {
    if ( !preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value ) ) {
      return false;
    }
    $format = strlen( $value ) > 10
      ? ( substr_count( $value, ':' ) === 2 ? 'Y-m-d H:i:s' : 'Y-m-d H:i' )
      : 'Y-m-d';
    $parsed = \DateTime::createFromFormat( '!' . $format, $value );
    return $parsed !== false && $parsed->format( $format ) === $value;
  }

  #endregion

  #region Reads

  private function list_forms( array $args, array $r ): array {
    $active_only = !empty( $args['active_only'] );
    $forms = $this->forms_all();
    $rows = [];
    $truncated = false;
    foreach ( $forms as $form_id => $form ) {
      if ( count( $rows ) >= self::FORM_LIST_CAP ) {
        $truncated = true;
        break;
      }
      $is_active = (int) ( $form['is_active'] ?? 0 ) === 1;
      if ( $active_only && !$is_active ) {
        continue;
      }
      $rows[] = [
        'id' => $form_id,
        'title' => (string) ( $form['title'] ?? '' ),
        'is_active' => $is_active,
        'field_count' => count( (array) ( $form['fields'] ?? [] ) ),
        'entry_count' => (int) \GFAPI::count_entries( $form_id, [ 'status' => self::DEFAULT_STATUS_SET ] ),
        'trashed_count' => (int) \GFAPI::count_entries( $form_id, [ 'status' => [ 'trash' ] ] ),
        'last_entry' => $this->last_entry_date( $form_id ),
      ];
    }
    $out = [ 'forms' => $rows, 'count' => count( $rows ), 'trashed_forms_excluded' => true ];
    if ( $truncated ) {
      $out['truncated'] = 'Only the first ' . self::FORM_LIST_CAP . ' forms are listed.';
    }
    return $this->json( $r, $out );
  }

  private function last_entry_date( int $form_id ): ?string {
    $entries = \GFAPI::get_entries( $form_id, [], [ 'key' => 'id', 'direction' => 'DESC', 'is_numeric' => true ], [ 'offset' => 0, 'page_size' => 1 ] );
    if ( !is_array( $entries ) || empty( $entries ) ) {
      return null;
    }
    return (string) ( $entries[0]['date_created'] ?? '' ) ?: null;
  }

  private function get_form( array $args, array $r ): array {
    $form_id = (int) ( $args['form_id'] ?? 0 );
    $form = $this->form( $form_id );
    if ( !$form ) {
      return $this->error( $r, 'No form with ID ' . $form_id . ' exists.' );
    }

    $fields = [];
    foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
      if ( !is_object( $field ) || !isset( $field->id ) ) {
        continue;
      }
      $row = [
        'id' => (int) $field->id,
        'label' => (string) ( $field->label ?? '' ),
        'type' => (string) ( $field->type ?? '' ),
        'isRequired' => !empty( $field->isRequired ),
      ];
      if ( $this->field_inputs( $field, (string) $field->id ) ) {
        $row['inputs'] = [];
        foreach ( $this->field_inputs( $field, (string) $field->id ) as $input ) {
          if ( isset( $input['id'] ) ) {
            $row['inputs'][] = [ 'id' => (string) $input['id'], 'label' => (string) ( $input['label'] ?? '' ) ];
          }
        }
      }
      if ( is_array( $field->choices ?? null ) ) {
        $row['choices'] = [];
        foreach ( $field->choices as $choice ) {
          if ( is_array( $choice ) ) {
            $row['choices'][] = [
              'text' => (string) ( $choice['text'] ?? '' ),
              'value' => (string) ( $choice['value'] ?? '' ),
            ];
          }
        }
      }
      $fields[] = $row;
    }

    return $this->json( $r, [
      'id' => (int) $form['id'],
      'title' => (string) ( $form['title'] ?? '' ),
      'description' => (string) ( $form['description'] ?? '' ),
      'is_active' => (int) ( $form['is_active'] ?? 0 ) === 1,
      'is_trash' => (int) ( $form['is_trash'] ?? 0 ) === 1,
      'entry_count' => (int) \GFAPI::count_entries( (int) $form['id'], [ 'status' => self::DEFAULT_STATUS_SET ] ),
      'fields' => $fields,
      // Counts only: notification recipients and confirmation redirect URLs can carry
      // addresses and tokens, and neither is needed to write a field value.
      'notifications_count' => is_array( $form['notifications'] ?? null ) ? count( $form['notifications'] ) : 0,
      'confirmations_count' => is_array( $form['confirmations'] ?? null ) ? count( $form['confirmations'] ) : 0,
    ] );
  }

  private function forms_briefing( array $args, array $r ): array {
    $forms = $this->forms_all();
    $active = 0;
    foreach ( $forms as $form ) {
      if ( (int) ( $form['is_active'] ?? 0 ) === 1 ) {
        $active++;
      }
    }

    // Counted per form rather than with form_id 0. Measured on 3.1.2: get_entries(0,…) and
    // count_entries(0,…) apply no form restriction at all, and that includes entries whose
    // form is in the trash. Summing over forms_all() keeps every number in this reply
    // describing the same population as the form counts above it, which is what the note
    // promises; the aggregate path silently mixed the two.
    $by_status = array_fill_keys( self::STATUSES, 0 );
    $since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
    $last_7_days = 0;
    $newest = null;
    foreach ( $forms as $form_id => $form ) {
      foreach ( self::STATUSES as $status ) {
        $by_status[ $status ] += (int) \GFAPI::count_entries( $form_id, [ 'status' => [ $status ] ] );
      }
      $last_7_days += (int) \GFAPI::count_entries( $form_id, [ 'status' => self::DEFAULT_STATUS_SET, 'start_date' => $since ] );
      $last = $this->last_entry_date( $form_id );
      if ( $last !== null && ( $newest === null || $last > $newest ) ) {
        $newest = $last;
      }
    }

    return $this->json( $r, [
      'forms' => count( $forms ),
      'forms_active' => $active,
      'forms_inactive' => count( $forms ) - $active,
      'entries_by_status' => $by_status,
      'entries_last_7_days' => $last_7_days,
      'newest_entry' => $newest,
      'note' => 'Every count covers only the forms that are not in the trash, and their entries. Trashed forms and their entries are excluded throughout.',
    ] );
  }

  private function list_entries( array $args, array $r ): array {
    $form_id = (int) ( $args['form_id'] ?? 0 );
    $forms = $this->forms_all();

    if ( $form_id > 0 && !isset( $forms[ $form_id ] ) ) {
      return $this->error( $r, 'No form with ID ' . $form_id . ' exists.' );
    }

    $status_arg = isset( $args['status'] ) ? strtolower( trim( (string) $args['status'] ) ) : '';
    if ( $status_arg !== '' && $status_arg !== 'all' && !in_array( $status_arg, self::STATUSES, true ) ) {
      return $this->error( $r, 'status must be one of: ' . implode( ', ', self::STATUSES ) . ', all — or omitted for the default view (active and spam, no trash).' );
    }

    $has_filter = isset( $args['field_filter'] ) && is_array( $args['field_filter'] );
    $criteria = [ 'status' => $this->status_set( $status_arg ) ];

    $search = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';
    if ( $search !== '' ) {
      // Gravity Forms' own "any field" search compares the term against every meta value,
      // including a password field's, and with the total in the reply that is a boolean
      // oracle: a caller can test a guess one character at a time. It is also an EXACT match
      // by default, so it silently finds nothing for a word inside a message. The search is
      // therefore rebuilt here as an explicit OR over the form's readable answer fields,
      // using a substring match. That needs to know which form.
      if ( $form_id <= 0 ) {
        return $this->error( $r, 'search needs form_id. Gravity Forms\' own search compares against every field including a password field, so this tool searches one form\'s readable answer fields instead; name the form.' );
      }
      if ( $has_filter ) {
        return $this->error( $r, 'Use either search or field_filter, not both: search matches ANY of the form\'s fields and field_filter is a single condition, and Gravity Forms applies one match mode to a whole filter set, so combining them would quietly change what field_filter means.' );
      }
      $searchable = [];
      foreach ( $this->inputs( $forms[ $form_id ] ) as $input_id => $info ) {
        if ( !empty( $info['aggregate'] ) || in_array( $info['type'], self::READ_EXCLUDED_TYPES, true ) ) {
          continue;
        }
        $searchable[] = (string) $input_id;
      }
      if ( !$searchable ) {
        return $this->error( $r, 'Form ' . $form_id . ' has no answer fields that can be searched.' );
      }
      $criteria['field_filters']['mode'] = 'any';
      foreach ( $searchable as $input_id ) {
        $criteria['field_filters'][] = [ 'key' => $input_id, 'operator' => 'contains', 'value' => $search ];
      }
    }

    if ( $has_filter ) {
      $ff = $args['field_filter'];
      if ( $form_id <= 0 ) {
        return $this->error( $r, 'field_filter needs form_id, because the input id is only meaningful against one form.' );
      }
      $key = isset( $ff['key'] ) ? (string) $ff['key'] : '';
      [ $info, $msg ] = $this->resolve_input( $forms[ $form_id ], $key );
      if ( !$info ) {
        return $this->error( $r, $msg );
      }
      if ( in_array( $info['type'], self::READ_EXCLUDED_TYPES, true ) ) {
        return $this->error( $r, 'Input "' . $key . '" is a "' . $info['type'] . '" field. Filtering on it is refused: a match-or-not answer reads its value one query at a time, which is the same read this group refuses to return.' );
      }
      $operator = isset( $ff['operator'] ) && $ff['operator'] !== '' ? strtolower( trim( (string) $ff['operator'] ) ) : 'is';
      if ( !in_array( $operator, self::ALLOWED_OPERATORS, true ) ) {
        return $this->error( $r, 'Operator "' . $operator . '" is not one this tool accepts (' . implode( ', ', self::ALLOWED_OPERATORS ) . '). Gravity Forms silently treats an operator it does not know as "match everything", so an unrecognised one is refused rather than passed through.' );
      }
      if ( !array_key_exists( 'value', $ff ) ) {
        return $this->error( $r, 'field_filter needs a value.' );
      }
      $criteria['field_filters'][] = [ 'key' => $key, 'operator' => $operator, 'value' => $ff['value'] ];
    }

    foreach ( [ 'date_from' => 'start_date', 'date_to' => 'end_date' ] as $arg => $key ) {
      if ( isset( $args[ $arg ] ) && (string) $args[ $arg ] !== '' ) {
        $value = (string) $args[ $arg ];
        if ( !$this->valid_date( $value ) ) {
          return $this->error( $r, $arg . ' must look like YYYY-MM-DD or YYYY-MM-DD HH:MM:SS. A value Gravity Forms cannot parse makes it match every entry instead of none, so a malformed date is refused.' );
        }
        $criteria[ $key ] = $value;
      }
    }

    $limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
    $limit = max( 1, min( self::MAX_LIMIT, $limit ) );
    $page = isset( $args['page'] ) ? (int) $args['page'] : 1;
    $page = max( 1, $page );
    if ( $page > self::MAX_PAGE ) {
      return $this->error( $r, 'page must be ' . self::MAX_PAGE . ' or less. A larger page turns the offset into a scan of the whole entries table from a single call.' );
    }
    $order = ( isset( $args['order'] ) && strtolower( (string) $args['order'] ) === 'asc' ) ? 'ASC' : 'DESC';

    $total = 0;
    $entries = \GFAPI::get_entries(
      $form_id,
      $criteria,
      [ 'key' => 'id', 'direction' => $order, 'is_numeric' => true ],
      [ 'offset' => ( $page - 1 ) * $limit, 'page_size' => $limit ],
      $total
    );
    if ( !is_array( $entries ) ) {
      return $this->error( $r, 'Gravity Forms returned an error while searching entries.' );
    }

    $rows = [];
    foreach ( $entries as $entry ) {
      $eid = (int) ( $entry['id'] ?? 0 );
      $eform = $this->form( (int) ( $entry['form_id'] ?? 0 ) );
      $rows[] = [
        'id' => $eid,
        'form_id' => (int) ( $entry['form_id'] ?? 0 ),
        'form_title' => $eform ? (string) ( $eform['title'] ?? '' ) : '',
        'date_created' => (string) ( $entry['date_created'] ?? '' ),
        'status' => (string) ( $entry['status'] ?? '' ),
        'is_read' => (int) ( $entry['is_read'] ?? 0 ) === 1,
        'is_starred' => (int) ( $entry['is_starred'] ?? 0 ) === 1,
        'preview' => $eform ? $this->entry_preview( $eform, $entry ) : [],
      ];
    }

    return $this->json( $r, [
      'entries' => $rows,
      'count' => count( $rows ),
      'total' => (int) $total,
      'page' => $page,
      'limit' => $limit,
      'statuses_queried' => array_values( $criteria['status'] ),
      'note' => 'Values are previews, bounded and shortened. Use gf_get_entry for a full submission.',
    ] );
  }

  private function get_entry( array $args, array $r ): array {
    $entry_id = (int) ( $args['entry_id'] ?? 0 );
    $entry = $this->entry( $entry_id );
    if ( !$entry ) {
      return $this->error( $r, 'No entry with ID ' . $entry_id . ' exists.' );
    }
    $form = $this->form( (int) ( $entry['form_id'] ?? 0 ) );
    $map = $form ? $this->inputs( $form ) : [];
    $reserved = $this->reserved_keys();

    $include_tracking = !empty( $args['include_tracking'] );

    $out = [
      'entry_id' => $entry_id,
      'form_id' => (int) ( $entry['form_id'] ?? 0 ),
      'form_title' => $form ? (string) ( $form['title'] ?? '' ) : '',
      'date_created' => (string) ( $entry['date_created'] ?? '' ),
      'date_updated' => (string) ( $entry['date_updated'] ?? '' ),
      'status' => (string) ( $entry['status'] ?? '' ),
      'is_read' => (int) ( $entry['is_read'] ?? 0 ) === 1,
      'is_starred' => (int) ( $entry['is_starred'] ?? 0 ) === 1,
      'created_by' => isset( $entry['created_by'] ) && $entry['created_by'] !== null ? (int) $entry['created_by'] : null,
      'notes_count' => count( $this->notes( $entry_id ) ),
    ];
    if ( !$form ) {
      $out['warning'] = 'The form this entry belongs to (ID ' . (int) ( $entry['form_id'] ?? 0 ) . ') no longer exists, so the answers cannot be labelled by field and are not returned. The entry row itself is still here.';
    }

    if ( $include_tracking ) {
      $out['tracking'] = [
        'ip' => (string) ( $entry['ip'] ?? '' ),
        'user_agent' => (string) ( $entry['user_agent'] ?? '' ),
        'source_url' => (string) ( $entry['source_url'] ?? '' ),
      ];
    }

    $fields = [];
    foreach ( $entry as $key => $value ) {
      $key = (string) $key;
      if ( isset( $reserved[ $key ] ) || !isset( $map[ $key ] ) || !empty( $map[ $key ]['aggregate'] ) ) {
        continue;
      }
      if ( in_array( $map[ $key ]['type'], self::READ_EXCLUDED_TYPES, true ) ) {
        $fields[] = [ 'input_id' => $key, 'label' => $map[ $key ]['label'], 'type' => $map[ $key ]['type'], 'value' => '[not returned: password field]' ];
        continue;
      }
      $fields[] = [
        'input_id' => $key,
        'label' => $map[ $key ]['label'],
        'type' => $map[ $key ]['type'],
        'value' => $this->display_value( $value ),
      ];
    }
    $out['fields'] = $fields;
    $out['fields_returned'] = count( $fields );

    return $this->json( $r, $out );
  }

  private function list_entry_notes( array $args, array $r ): array {
    $entry_id = (int) ( $args['entry_id'] ?? 0 );
    if ( !$this->entry( $entry_id ) ) {
      return $this->error( $r, 'No entry with ID ' . $entry_id . ' exists.' );
    }
    $rows = [];
    foreach ( $this->notes( $entry_id ) as $note ) {
      $rows[] = [
        'id' => (int) ( $note['id'] ?? 0 ),
        'date_created' => (string) ( $note['date_created'] ?? '' ),
        'user_name' => (string) ( $note['user_name'] ?? '' ),
        'note_type' => (string) ( $note['note_type'] ?? '' ),
        'value' => $this->display_value( $note['value'] ?? '' ),
      ];
    }
    if ( !$rows ) {
      return $this->text( $r, 'Entry ' . $entry_id . ' has no notes.' );
    }
    return $this->json( $r, [ 'entry_id' => $entry_id, 'notes' => $rows, 'count' => count( $rows ) ] );
  }

  #endregion

  #region Writes

  private function update_entry( array $args, array $r ): array {
    $entry_id = (int) ( $args['entry_id'] ?? 0 );
    $before = $this->entry( $entry_id );
    if ( !$before ) {
      return $this->error( $r, 'No entry with ID ' . $entry_id . ' exists.' );
    }

    $changes = [];

    if ( array_key_exists( 'status', $args ) ) {
      $status = strtolower( trim( (string) $args['status'] ) );
      if ( !in_array( $status, self::STATUSES, true ) ) {
        return $this->error( $r, 'status must be one of: ' . implode( ', ', self::STATUSES ) . '.' );
      }
      $changes['status'] = $status;
    }
    foreach ( [ 'is_read', 'is_starred' ] as $flag ) {
      if ( array_key_exists( $flag, $args ) ) {
        $changes[ $flag ] = !empty( $args[ $flag ] ) ? 1 : 0;
      }
    }
    if ( !$changes ) {
      return $this->error( $r, 'Nothing to change: supply status, is_read or is_starred.' );
    }

    $applied = [];
    $unchanged = [];
    $failed = [];
    foreach ( $changes as $property => $value ) {
      $current = $property === 'status' ? (string) ( $before['status'] ?? '' ) : (int) ( $before[ $property ] ?? 0 );
      if ( (string) $current === (string) $value ) {
        $unchanged[] = $property;
        continue;
      }
      $result = \GFAPI::update_entry_property( $entry_id, $property, $value );
      if ( $result === false ) {
        // Recorded, not returned from here: properties are written one at a time, so an
        // earlier one may already have changed. Returning immediately would report a clean
        // failure while the stored entry disagrees with it, which is the same misleading
        // shape as a silent success seen from the other side.
        $failed[] = $property;
        continue;
      }
      $applied[] = $property;
    }

    // Read back from stored state, not from the return value: update_entry_property
    // returns the number of rows touched, which is 0 for a legitimate idempotent write and
    // false on error, so the count says nothing about whether the value is now what was
    // asked for.
    $after = $this->entry( $entry_id );
    if ( !$after ) {
      return $this->error( $r, 'Entry ' . $entry_id . ' could not be read back after the update.' );
    }
    $mismatch = [];
    foreach ( $applied as $property ) {
      $expected = (string) $changes[ $property ];
      $stored = $property === 'status' ? (string) ( $after['status'] ?? '' ) : (string) (int) ( $after[ $property ] ?? 0 );
      if ( $stored !== $expected ) {
        $mismatch[] = $property . ' (wanted "' . $expected . '", stored "' . $stored . '")';
      }
    }
    if ( $mismatch ) {
      return $this->error( $r, 'Entry ' . $entry_id . ' was updated but the stored value does not match for: ' . implode( '; ', $mismatch ) . '.', -32603 );
    }

    if ( $failed ) {
      $changed_txt = $applied
        ? 'These properties DID change and are still changed: ' . implode( ', ', $applied ) . '.'
        : 'No property changed.';
      return $this->error( $r, 'Entry ' . $entry_id . ': Gravity Forms refused to update ' . implode( ', ', $failed ) . ' (it returns false when a submissions block is in progress or the row could not be written). ' . $changed_txt, -32603 );
    }

    if ( !$applied ) {
      // Nothing was written, so nothing mutated: the hook is for tools that changed content.
      $this->noop = true;
    }

    $msg = 'Entry ' . $entry_id . ': ';
    $msg .= $applied ? 'changed ' . implode( ', ', $applied ) . '.' : 'nothing changed.';
    if ( $unchanged ) {
      $msg .= ' Already held the requested value for: ' . implode( ', ', $unchanged ) . '.';
    }
    return $this->text( $r, $msg );
  }

  private function update_entry_field( array $args, array $r ): array {
    $entry_id = (int) ( $args['entry_id'] ?? 0 );
    $entry = $this->entry( $entry_id );
    if ( !$entry ) {
      return $this->error( $r, 'No entry with ID ' . $entry_id . ' exists.' );
    }
    $form = $this->form( (int) ( $entry['form_id'] ?? 0 ) );
    if ( !$form ) {
      return $this->error( $r, 'The form this entry belongs to (ID ' . (int) ( $entry['form_id'] ?? 0 ) . ') no longer exists, so its fields cannot be validated.' );
    }

    $input_id = isset( $args['input_id'] ) ? (string) $args['input_id'] : '';
    [ $info, $msg ] = $this->resolve_input( $form, $input_id );
    if ( !$info ) {
      return $this->error( $r, $msg );
    }

    if ( !in_array( $info['type'], self::WRITE_ALLOWED_TYPES, true ) ) {
      return $this->error( $r, 'Input "' . $input_id . '" is a "' . $info['type'] . '" field, which this tool does not write. Only plain answer fields can be changed (text, textarea, number, select, radio, checkbox, email, website, phone, date, time, name, address). A password or upload field is refused because writing it is a leak; an opt-in/consent field, a repeater and any field a plugin added are refused because changing them is a change to something the site relies on. Use the form\'s own editor for those.' );
    }

    if ( !array_key_exists( 'value', $args ) || !is_scalar( $args['value'] ) ) {
      return $this->error( $r, 'A scalar value is required. For a multi-input field, write each sub-input separately.' );
    }
    $value = (string) $args['value'];

    $current = $this->display_value( $entry[ $input_id ] ?? '' );
    if ( $current === $value ) {
      $this->noop = true;
      return $this->text( $r, 'Input "' . $input_id . '" on entry ' . $entry_id . ' already holds that value. Nothing was written.' );
    }

    // The overwrite destroys the only copy, so it takes the same server-minted two-step as
    // a deletion. The target binds the entry, the input and a fingerprint of the new value,
    // so a token minted to write one value cannot be replayed to write a different one.
    $target = $entry_id . ':' . $input_id . ':' . md5( $value );
    $summary = 'This overwrites the "' . $info['label'] . '" answer on entry ' . $entry_id
      . ' (form "' . (string) ( $form['title'] ?? '' ) . '"), from "' . $this->preview( $current ) . '" to "' . $this->preview( $value ) . '".'
      . ' The previous value is not recorded anywhere and cannot be recovered.';
    $gate = \GMCP_Core::confirm_gate( 'gf_update_entry_field', $target, $args, $summary );
    if ( $gate !== true ) {
      return $this->error( $r, $gate );
    }

    $result = \GFAPI::update_entry_field( $entry_id, $input_id, $value );
    if ( $result === false ) {
      return $this->error( $r, 'Gravity Forms refused to write input "' . $input_id . '" on entry ' . $entry_id . '.' );
    }
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Gravity Forms returned an error writing input "' . $input_id . '": ' . $result->get_error_message() );
    }

    // Read back from stored state. update_entry_field returns true, false, the entry array
    // or a WP_Error depending on the path, so the return value is not the evidence.
    $after = $this->entry( $entry_id );
    $stored = $after ? $this->display_value( $after[ $input_id ] ?? '' ) : null;
    if ( $stored !== $value ) {
      return $this->error( $r, 'Input "' . $input_id . '" on entry ' . $entry_id . ' was written but reads back as "' . $this->preview( (string) $stored ) . '" rather than the requested value.', -32603 );
    }

    return $this->text( $r, 'Entry ' . $entry_id . ': input "' . $input_id . '" (' . $info['label'] . ') updated and verified by reading it back.' );
  }

  private function delete_entry( array $args, array $r ): array {
    $entry_id = (int) ( $args['entry_id'] ?? 0 );
    $entry = $this->entry( $entry_id );
    if ( !$entry ) {
      return $this->error( $r, 'No entry with ID ' . $entry_id . ' exists.' );
    }
    $form = $this->form( (int) ( $entry['form_id'] ?? 0 ) );
    $preview = $form ? $this->entry_preview( $form, $entry, 1 ) : [];
    $who = $preview ? ( $preview[0]['label'] . ': ' . $preview[0]['value'] ) : 'no non-empty answer to show';

    $summary = 'This permanently deletes entry ' . $entry_id . ' from form "' . ( $form ? (string) ( $form['title'] ?? '' ) : 'unknown' )
      . '" (' . (string) ( $entry['date_created'] ?? '' ) . '), submitted as: ' . $who . '.'
      . ' Its answers and notes go with it. Files it uploaded and data an add-on stored elsewhere are not necessarily removed.';

    $gate = \GMCP_Core::confirm_gate( 'gf_delete_entry', $entry_id, $args, $summary );
    if ( $gate !== true ) {
      return $this->error( $r, $gate );
    }

    // Still there? The first call was supposed to change nothing, so this also proves the
    // token step did not half-run.
    if ( !$this->entry( $entry_id ) ) {
      return $this->error( $r, 'Entry ' . $entry_id . ' no longer exists, so nothing was deleted.' );
    }

    $result = \GFAPI::delete_entry( $entry_id );
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Gravity Forms could not delete entry ' . $entry_id . ': ' . $result->get_error_message() );
    }
    if ( $result !== true ) {
      return $this->error( $r, 'Gravity Forms did not report a successful delete for entry ' . $entry_id . '.' );
    }

    if ( $this->entry( $entry_id ) ) {
      return $this->error( $r, 'Entry ' . $entry_id . ' was reported deleted but still reads back.', -32603 );
    }
    return $this->text( $r, 'Entry ' . $entry_id . ' deleted.' );
  }

  private function add_entry_note( array $args, array $r ): array {
    $entry_id = (int) ( $args['entry_id'] ?? 0 );
    if ( !$this->entry( $entry_id ) ) {
      return $this->error( $r, 'No entry with ID ' . $entry_id . ' exists.' );
    }
    if ( !isset( $args['note'] ) || !is_string( $args['note'] ) ) {
      return $this->error( $r, 'note must be a string.' );
    }
    $note = wp_kses_post( $args['note'] );
    if ( trim( wp_strip_all_tags( $note ) ) === '' ) {
      return $this->error( $r, 'A non-empty note is required.' );
    }

    // The author and the note type are fixed here rather than taken from the caller, so a
    // note cannot be attributed to another user or forged as a notification note.
    $user = wp_get_current_user();
    $user_id = (int) $user->ID;
    $user_name = $user->display_name ? (string) $user->display_name : 'GMCP';

    $before = count( $this->notes( $entry_id ) );
    $result = \GFAPI::add_note( $entry_id, $user_id, $user_name, $note, 'user', null );
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Gravity Forms could not add the note: ' . $result->get_error_message() );
    }

    // Read back: a new note must exist and carry the text that was written.
    $notes = $this->notes( $entry_id );
    if ( count( $notes ) <= $before ) {
      return $this->error( $r, 'Gravity Forms did not report an error but no note was added to entry ' . $entry_id . '.', -32603 );
    }
    $found = false;
    foreach ( $notes as $n ) {
      if ( $this->display_value( $n['value'] ?? '' ) === $this->display_value( $note ) ) {
        $found = true;
        break;
      }
    }
    if ( !$found ) {
      return $this->error( $r, 'A note count increased on entry ' . $entry_id . ' but the note text does not read back as written.', -32603 );
    }

    return $this->text( $r, 'Note added to entry ' . $entry_id . '.' );
  }

  private function set_form_active( array $args, array $r ): array {
    $form_id = (int) ( $args['form_id'] ?? 0 );
    $form = $this->form( $form_id );
    if ( !$form ) {
      return $this->error( $r, 'No form with ID ' . $form_id . ' exists.' );
    }
    if ( !array_key_exists( 'active', $args ) ) {
      return $this->error( $r, 'active is required: true to accept submissions, false to stop.' );
    }
    $desired = !empty( $args['active'] ) ? 1 : 0;
    $before = (int) ( $form['is_active'] ?? 0 ) === 1 ? 1 : 0;

    if ( $before === $desired ) {
      $this->noop = true;
      return $this->text( $r, 'Form ' . $form_id . ' is already ' . ( $desired ? 'active' : 'inactive' ) . '. Nothing was written.' );
    }

    $result = \GFAPI::update_form_property( $form_id, 'is_active', $desired );
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Gravity Forms could not change the form status: ' . $result->get_error_message() );
    }

    $after = $this->form( $form_id );
    $stored = $after ? ( (int) ( $after['is_active'] ?? 0 ) === 1 ? 1 : 0 ) : null;
    if ( $stored !== $desired ) {
      return $this->error( $r, 'Form ' . $form_id . ' was updated but is_active reads back as ' . var_export( $stored, true ) . ' rather than ' . $desired . '.', -32603 );
    }

    return $this->text( $r, 'Form ' . $form_id . ' is now ' . ( $desired ? 'active' : 'inactive' ) . '. Call this tool again with active=' . ( $desired ? 'false' : 'true' ) . ' to reverse it.' );
  }

  #endregion

}
