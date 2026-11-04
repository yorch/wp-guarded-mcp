<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Advanced Custom Fields, and the thing about it the generic post-meta tools
* get wrong.
*
* ACF stores values in post meta as `fieldname` = value, but also stores a
* hidden `_fieldname` = `field_123abc` (the field key reference). The field key
* reference is the only way ACF knows the field type, return format, and
* sub-field definitions. Without it, get_field() returns the wrong thing: a
* bare ID instead of a WP_Post for post object fields, a bare attachment ID
* instead of an image array for image fields, a row count instead of rows for
* repeater fields, or null for options-page fields on ACF 5.11+.
*
* Writing `my_field` through update_post_meta() without writing
* `_my_field = field_123abc` is a silent success: the value is in the
* database, get_post_meta() reads it, but get_field() returns the wrong type
* or shape. This group writes through update_field(), which resolves the field
* definition, writes both the value and the key reference, and runs the
* field-type update_value filters that expand repeaters, store image IDs, and
* serialize arrays correctly.
*
* For options-page writes (post_id = 'option' or a custom options page), the
* resolved option name is `{$prefix}_{$field_name}`, and the write passes
* through the same option_guard and option_write_policy as wp_update_option.
* The post_id is restricted to a known allowlist, not an arbitrary string,
* because ACF treats any string as an option prefix and an unrestricted
* post_id could target any option row.
*
* ACF's classes move between versions, so every call into one is guarded and
* reports what was missing rather than fataling. The class is only
* constructed when the group is switched on, but the per-call check in
* handle_call() is what keeps the tools honest when ACF has been deactivated
* between the two.
*/
class GMCP_Tools_Acf {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [
    'acf_set_field_value',
  ];

  /**
  * The allowed post_id forms, and how to decode each.
  *
  * ACF's acf_decode_post_id() accepts any string as an option prefix, which
  * means an unrestricted post_id can target any option row. This allowlist
  * restricts to the documented, predictable forms. Anything else is refused
  * with a clear error, not passed through to ACF.
  */
  const POST_ID_PATTERNS = [
    'option', 'options',           // Default options page
    // Numeric post IDs, user_*, term_*, category_*, comment_* are validated
    // dynamically below, not listed here.
  ];

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

  private function tools(): array {
    return [
      'acf_list_fields' => [
        'name' => 'acf_list_fields',
        'description' => 'List every ACF field group and its fields registered on this site, with each field\'s key (field_123abc), name, label, type, instructions, required flag, and return format. Reads from ACF\'s own field registry (acf_get_field_groups and acf_get_fields), so it shows what the theme or plugin actually registered — both UI-defined groups (stored as acf-field-group posts) and PHP-registered groups (acf_add_local_field_group). Repeater and group sub_fields and flexible-content layouts (with the layout name used as acf_fc_layout) are listed under their parent. Optionally takes a post_id to filter to groups whose location rules apply to that post. Use this before writing a value to find the field key, since writing through update_post_meta without the field key reference is a silent success.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'description' => 'Optional. Filter to field groups whose location rules match this post. Accepts the same post_id forms as acf_get_field_value (integer, "user_2", "category_3", "option").' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'acf_get_field_value' => [
        'name' => 'acf_get_field_value',
        'description' => 'Read a single ACF field value through get_field(), which applies the field\'s return format: a WP_Post for post object fields, an image array for image fields, an array of rows for repeaters. This is the value the theme sees, not the raw stored meta. Without the field key reference (_fieldname = field_123abc), get_field() returns the wrong type or null, so this read confirms the field is properly registered. Only a field ACF has registered is read; any other name is refused rather than read as raw meta. Credential-shaped field values are redacted by the same rule the option tools use, and an options-page field whose option row is protected is refused. Pass format=false to read the raw stored value without formatting.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'field' => [ 'type' => 'string', 'description' => 'The field name or field key (field_123abc).' ],
            'post_id' => [ 'description' => 'Where the value is stored. Integer for a post, "user_2" for user meta, "category_3" for term meta, "option" or "options" for the options page. Defaults to the current post.' ],
            'format' => [ 'type' => 'boolean', 'description' => 'Whether to apply the field\'s return format (default true). False returns the raw stored value.' ],
          ],
          'required' => [ 'field' ],
        ],
        'accessLevel' => 'admin',
      ],
      'acf_set_field_value' => [
        'name' => 'acf_set_field_value',
        'description' => 'Write a single ACF field value through update_field(), which resolves the field definition, writes both the value and the hidden field key reference (_fieldname = field_123abc), and runs the field-type update_value filters that expand repeaters, store image IDs, and serialize arrays correctly. This is the safe primitive for writing an ACF value: update_post_meta alone writes the value without the key reference, and get_field() then returns the wrong type or null. For options-page writes (post_id = "option"), the resolved option name (options_{$field_name}) passes through the same option_guard and option_write_policy as wp_update_option, so a credential-shaped field name is refused. The post_id is restricted to known forms (integer, user_*, term_*, category_*, option, options); arbitrary strings are refused because ACF treats any string as an option prefix. post_id is required. Success is judged on the raw stored value, not on the return value of update_field() (which is false when nothing changed); a value ACF normalised is reported with what was actually stored. A null value deletes the stored value. Writes to orders and subscriptions are refused. The write is journalled, so wp_undo_change can put it back.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'field' => [ 'type' => 'string', 'description' => 'The field name or field key (field_123abc). Using the field key is more reliable for fields that have no stored value yet.' ],
            'value' => [ 'description' => 'The value to store. Any type; the field type\'s update_value filter handles serialization.' ],
            'post_id' => [ 'description' => 'Where to store the value. Integer for a post, "user_2" for user meta, "category_3" for term meta, "option" or "options" for the options page. Defaults to the current post. Arbitrary strings are refused.' ],
          ],
          'required' => [ 'field', 'value' ],
        ],
        'accessLevel' => 'admin',
      ],
      'acf_get_field_objects' => [
        'name' => 'acf_get_field_objects',
        'description' => 'List every ACF field on a given post, user, term, or options page, with each field\'s key, name, label, type, and current formatted value. Uses get_field_objects(), which respects the field key references and only returns fields ACF actually recognizes — unlike get_post_meta(), which returns everything including values written without a key reference. This is the "what does ACF see on this post" view. Credential-shaped values are redacted. Returns an empty list when no field group applies, which means ACF has no registered fields for this post_id — not that the values are missing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'description' => 'Where to read from. Integer for a post, "user_2" for user meta, "category_3" for term meta, "option" or "options" for the options page. Defaults to the current post.' ],
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

    if ( !class_exists( 'ACF' ) ) {
      return $this->error( $r, 'ACF is not loaded on this site, so its tools cannot run.' );
    }

    switch ( $tool ) {
      case 'acf_list_fields': $r = $this->list_fields( $args, $r ); break;
      case 'acf_get_field_value': $r = $this->get_field_value( $args, $r ); break;
      case 'acf_set_field_value': $r = $this->set_field_value( $args, $r ); break;
      case 'acf_get_field_objects': $r = $this->get_field_objects( $args, $r ); break;
      default:
        return $this->error( $r, 'Unknown tool', -32601 );
    }

    if ( empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
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
  * Validate and normalise a post_id argument.
  *
  * ACF's acf_decode_post_id() accepts any string as an option prefix, which
  * means an unrestricted post_id can target any option row. This allowlist
  * restricts to the documented, predictable forms and refuses anything else
  * with a clear error, so a caller cannot use post_id = 'siteurl' to write to
  * the siteurl option through ACF's option-page path.
  *
  * Returns [ null, $post_id ] on success, [ $error_message, null ] on failure.
  */
  private function validate_post_id( $post_id ) {
    // Default: current post. ACF uses false for this.
    if ( $post_id === null || $post_id === '' || $post_id === false ) {
      return [ null, false ];
    }
    // Integer post IDs.
    if ( is_int( $post_id ) || ( is_string( $post_id ) && ctype_digit( $post_id ) ) ) {
      $id = (int) $post_id;
      if ( $id <= 0 ) {
        return [ 'post_id must be a positive integer or a known ACF location string (user_*, term_*, category_*, option, options).', null ];
      }
      return [ null, $id ];
    }
    if ( !is_string( $post_id ) ) {
      return [ 'post_id must be an integer or a known ACF location string.', null ];
    }
    // Known string forms.
    $lower = strtolower( $post_id );
    if ( in_array( $lower, [ 'option', 'options' ], true ) ) {
      return [ null, 'option' ];
    }
    // user_*, term_*, category_*, comment_* with a numeric suffix.
    $prefixes = [ 'user_', 'term_', 'category_', 'comment_' ];
    foreach ( $prefixes as $prefix ) {
      if ( strpos( $post_id, $prefix ) === 0 ) {
        $suffix = substr( $post_id, strlen( $prefix ) );
        if ( ctype_digit( $suffix ) && (int) $suffix > 0 ) {
          return [ null, $post_id ];
        }
      }
    }
    // {taxonomy}_N for a taxonomy that is actually registered (product_cat_12), which is the
    // form ACF itself documents for term fields. Only a registered taxonomy qualifies: the
    // prefix is checked against the registry, never against the caller's say-so.
    if ( preg_match( '/^([a-z0-9_\-]+)_(\d+)$/i', $post_id, $m ) && (int) $m[2] > 0 && taxonomy_exists( strtolower( $m[1] ) ) ) {
      return [ null, strtolower( $m[1] ) . '_' . (int) $m[2] ];
    }
    // The post_id of an options page a theme or plugin registered (ACF Pro / SCF). Same rule:
    // looked up in ACF's own registry, so an arbitrary string still cannot name an option row.
    // The resulting option names are put through option_guard() by the callers.
    foreach ( $this->registered_options_page_ids() as $page_id ) {
      if ( $post_id === $page_id ) {
        return [ null, $page_id ];
      }
    }
    return [ 'post_id "' . $post_id . '" is not a recognised ACF location. Allowed: integer, "option", "options", "user_N", "term_N", "category_N", "comment_N", "{registered taxonomy}_N", or the post_id of a registered ACF options page. Arbitrary strings are refused because ACF treats any string as an option prefix, which could target any option row.', null ];
  }

  /**
  * The post_id values of the options pages ACF knows about, beyond the default "options".
  * ACF free has no options pages at all, so this is empty there rather than an error.
  *
  * @return string[]
  */
  private function registered_options_page_ids(): array {
    if ( !function_exists( 'acf_get_options_pages' ) ) {
      return [];
    }
    try {
      $pages = acf_get_options_pages();
    } catch ( Throwable $e ) {
      return [];
    }
    $ids = [];
    foreach ( is_array( $pages ) ? $pages : [] as $page ) {
      $pid = is_array( $page ) ? ( $page['post_id'] ?? '' ) : '';
      if ( is_string( $pid ) && $pid !== '' && !in_array( $pid, [ 'options', 'option' ], true ) && preg_match( '/^[A-Za-z0-9_\-]+$/', $pid ) ) {
        $ids[] = $pid;
      }
    }
    return array_values( array_unique( $ids ) );
  }

  /** Whether a validated post_id names an options page (default or registered) rather than an object. */
  private function is_option_location( $post_id ): bool {
    return $post_id === 'option' || ( is_string( $post_id ) && in_array( $post_id, $this->registered_options_page_ids(), true ) );
  }

  /**
  * Resolve a selector to the registered ACF field get_field() would use, or null.
  *
  * acf_maybe_get_field() is the very resolver get_field() runs first, here in its loose mode
  * so that a registered field with no stored value yet (and so no _name reference) still
  * resolves by name, as update_field() does. When neither finds anything, get_field() does not fail: it builds a dummy field and returns the raw
  * meta row stored under the selector. That is the door this guards. Asking the same resolver
  * means "registered" here and "registered" there cannot disagree.
  *
  * @return array|null The field array, or null when the selector is not a registered field.
  */
  private function resolve_field( string $selector, $post_id ): ?array {
    try {
      if ( function_exists( 'acf_maybe_get_field' ) ) {
        $pid   = function_exists( 'acf_get_valid_post_id' ) ? acf_get_valid_post_id( $post_id ) : $post_id;
        $field = acf_maybe_get_field( $selector, $pid, false );
        return is_array( $field ) && !empty( $field['key'] ) ? $field : null;
      }
      $field = acf_get_field( $selector );
      if ( !$field ) {
        $key   = acf_get_reference( $selector, $post_id );
        $field = $key ? acf_get_field( $key ) : false;
      }
      return is_array( $field ) && !empty( $field['key'] ) ? $field : null;
    } catch ( Throwable $e ) {
      return null;
    }
  }

  /**
  * Resolve the option name ACF will write to for an options-page field.
  *
  * For post_id = 'option', ACF writes to `options_{$field_name}` with
  * reference `_options_{$field_name}`. For a custom options page (post_id =
  * 'my_settings'), it writes to `my_settings_{$field_name}`. This method
  * resolves the prefix from the post_id and returns the full option name, so
  * the option guard can check it before the write.
  *
  * Returns null for non-option post_ids (post/user/term meta), where the
  * option guard does not apply.
  */
  private function resolve_option_name( $post_id, string $field_name ): ?string {
    if ( $post_id === 'option' || $post_id === 'options' ) {
      return 'options_' . $field_name;
    }
    // A registered options page stores under its own post_id as the prefix. Only ids ACF
    // itself lists get this far (validate_post_id), so the prefix is never caller-chosen.
    if ( is_string( $post_id ) && in_array( $post_id, $this->registered_options_page_ids(), true ) ) {
      return $post_id . '_' . $field_name;
    }
    return null;
  }

  /**
  * Read the field key reference (_fieldname) from the right meta table.
  *
  * ACF stores the reference in the meta table that matches the post_id type:
  * post meta for integers, user meta for user_N, term meta for term_N and
  * category_N, comment meta for comment_N, and wp_options for option/options.
  * The verification must read from the right table, or it will always report
  * a failure for non-post targets.
  */
  private function read_reference( $post_id, string $ref_key ) {
    // Options page: stored in wp_options.
    if ( $post_id === 'option' || $post_id === 'options' ) {
      return get_option( '_options_' . substr( $ref_key, 1 ) );
    }
    if ( is_string( $post_id ) && in_array( $post_id, $this->registered_options_page_ids(), true ) ) {
      return get_option( '_' . $post_id . '_' . substr( $ref_key, 1 ) );
    }
    // {registered taxonomy}_N is term meta.
    if ( is_string( $post_id ) && preg_match( '/^([a-z0-9_\-]+)_(\d+)$/i', $post_id, $m ) && taxonomy_exists( strtolower( $m[1] ) ) ) {
      return get_metadata( 'term', (int) $m[2], $ref_key, true );
    }
    // Integer post ID: post meta.
    if ( is_int( $post_id ) || ( is_string( $post_id ) && ctype_digit( $post_id ) ) ) {
      return get_metadata( 'post', (int) $post_id, $ref_key, true );
    }
    if ( !is_string( $post_id ) ) {
      return null;
    }
    // Prefixed forms: user_*, term_*, category_*, comment_*.
    if ( strpos( $post_id, 'user_' ) === 0 ) {
      $id = (int) substr( $post_id, 5 );
      return $id > 0 ? get_metadata( 'user', $id, $ref_key, true ) : null;
    }
    if ( strpos( $post_id, 'term_' ) === 0 || strpos( $post_id, 'category_' ) === 0 ) {
      $id = (int) substr( $post_id, strpos( $post_id, '_' ) + 1 );
      return $id > 0 ? get_metadata( 'term', $id, $ref_key, true ) : null;
    }
    if ( strpos( $post_id, 'comment_' ) === 0 ) {
      $id = (int) substr( $post_id, 8 );
      return $id > 0 ? get_metadata( 'comment', $id, $ref_key, true ) : null;
    }
    return null;
  }

  /**
  * One field as the listing shows it, with the children a caller needs to build a valid
  * value: a repeater's sub_fields, a flexible content field's layouts (each with its own
  * sub_fields, and the layout name that goes in acf_fc_layout), a group's or clone's
  * children. Without them an agent cannot write a repeater row at all.
  */
  private function describe_field( array $field, int $depth = 0 ): array {
    $out = [
      'key'         => $field['key'] ?? '',
      'name'        => $field['name'] ?? '',
      'label'       => $field['label'] ?? '',
      'type'        => $field['type'] ?? '',
      'instructions'=> $field['instructions'] ?? '',
      'required'    => !empty( $field['required'] ),
      'return_format' => $field['return_format'] ?? null,
    ];
    if ( $depth > 6 ) {
      return $out;
    }
    if ( !empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
      $out['sub_fields'] = array_map( function ( $sub ) use ( $depth ) {
        return is_array( $sub ) ? $this->describe_field( $sub, $depth + 1 ) : [];
      }, array_values( $field['sub_fields'] ) );
    }
    if ( !empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
      $layouts = [];
      foreach ( $field['layouts'] as $layout ) {
        if ( !is_array( $layout ) ) {
          continue;
        }
        $layouts[] = [
          'key'        => $layout['key'] ?? '',
          'name'       => $layout['name'] ?? '',
          'label'      => $layout['label'] ?? '',
          'sub_fields' => array_map( function ( $sub ) use ( $depth ) {
            return is_array( $sub ) ? $this->describe_field( $sub, $depth + 1 ) : [];
          }, array_values( (array) ( $layout['sub_fields'] ?? [] ) ) ),
        ];
      }
      $out['layouts'] = $layouts;
    }
    return $out;
  }

  /**
  * List all registered ACF field groups and their fields.
  */
  private function list_fields( array $args, array $r ): array {
    $post_id = $args['post_id'] ?? null;
    if ( $post_id !== null ) {
      [ $err, $post_id ] = $this->validate_post_id( $post_id );
      if ( $err ) {
        return $this->error( $r, $err );
      }
    }

    $groups = acf_get_field_groups( $post_id !== null ? [ 'post_id' => $post_id ] : [] );
    if ( !is_array( $groups ) ) {
      $groups = [];
    }

    $result = [];
    foreach ( $groups as $group ) {
      $fields = acf_get_fields( $group );
      $field_list = [];
      if ( is_array( $fields ) ) {
        foreach ( $fields as $field ) {
          $field_list[] = $this->describe_field( $field );
        }
      }
      $result[] = [
        'key'     => $group['key'] ?? '',
        'title'   => $group['title'] ?? '',
        'fields'  => $field_list,
      ];
    }

    return $this->json( $r, [ 'groups' => $result ] );
  }

  /**
  * Read a single field value through get_field().
  */
  private function get_field_value( array $args, array $r ): array {
    $field = $args['field'] ?? '';
    if ( !is_string( $field ) || $field === '' ) {
      return $this->error( $r, 'A field name or key is required.' );
    }
    [ $err, $post_id ] = $this->validate_post_id( $args['post_id'] ?? null );
    if ( $err ) {
      return $this->error( $r, $err );
    }
    $format = $args['format'] ?? true;
    $format = (bool) $format;

    // Only a registered ACF field is read. For a name ACF does not know, get_field() does not
    // fail: it builds a dummy field and returns the raw meta row stored under that name, for a
    // user, term, comment or post alike. That made this tool a reader of any meta key at all
    // (a two-factor seed, session tokens), with the value then copied into the audit log. The
    // tool's job is "what ACF sees", so a selector ACF cannot resolve has nothing to show.
    $field_obj = $this->resolve_field( $field, $post_id );
    if ( $field_obj === null ) {
      return $this->error( $r, 'Field "' . $field . '" is not a registered ACF field for this post_id, so it is not read. This tool shows what ACF sees; raw meta is read through the post, user and term meta tools, which apply their own rules.', -32600 );
    }
    $field_name = (string) ( $field_obj['name'] ?? $field );

    // The meta key actually read is the selector when it is a name, and the field's own name
    // when it is a key. Judge both, plus the field key, so neither spelling gets around a
    // credential-shaped name.
    $secret = GMCP_Core::field_looks_secret( $field ) || GMCP_Core::field_looks_secret( $field_name );

    // On an options page the value is an option row, and the same policy as any other option
    // read applies to that row.
    if ( $this->is_option_location( $post_id ) ) {
      $prefix = $post_id === 'option' ? 'options' : (string) $post_id;
      $rows = [ $prefix ];
      foreach ( array_unique( [ $field, $field_name ] ) as $n ) {
        $rows[] = $prefix . '_' . $n;
        $rows[] = '_' . $prefix . '_' . $n;
      }
      foreach ( $rows as $row ) {
        $guard = GMCP_Core::option_guard( $row );
        if ( $guard !== true ) {
          return $this->error( $r, $guard, -32600 );
        }
      }
    }

    if ( $secret ) {
      $value = '[redacted]';
    } else {
      try {
        $value = get_field( $field, $post_id, $format );
      } catch ( Throwable $e ) {
        return $this->error( $r, 'get_field() threw an exception: ' . $e->getMessage() );
      }
      $value = GMCP_Core::redact( $value );
    }

    return $this->json( $r, [ 'field' => $field, 'value' => $value, 'formatted' => $format ] );
  }

  /**
  * Write a single field value through update_field().
  */
  private function set_field_value( array $args, array $r ): array {
    $field = $args['field'] ?? '';
    if ( !is_string( $field ) || $field === '' ) {
      return $this->error( $r, 'A field name or key is required.' );
    }
    [ $err, $post_id ] = $this->validate_post_id( $args['post_id'] ?? null );
    if ( $err ) {
      return $this->error( $r, $err );
    }
    // update_field() with no post_id writes to "the current post", which over REST resolves
    // to nothing and fails with a message that says nothing. Ask for the target outright.
    if ( $post_id === false ) {
      return $this->error( $r, 'post_id is required to write a field: there is no current post over this API.' );
    }
    $value = $args['value'] ?? null;

    // A post-type policy belongs to the post type. An order and a subscription are posts when
    // HPOS is off, and update_field() by key writes the field's meta on any post id whether or
    // not a field group applies to it.
    if ( is_int( $post_id ) ) {
      $commerce = GMCP_Core::commerce_post_guard( $post_id, 'Writing an ACF field on' );
      if ( $commerce !== true ) {
        return $this->error( $r, $commerce, -32600 );
      }
    }

    // Resolve the field object BEFORE applying the option guard. The guard
    // must check the resolved option name (options_{$field_name}), not the
    // raw selector the caller passed. If the caller passes a field key
    // (field_abc123), the guard needs the field's real name, not the key,
    // because ACF writes to options_{$field_name}, not options_{$field_key}.
    $field_obj = $this->resolve_field( $field, $post_id );
    if ( !$field_obj ) {
      return $this->error( $r, 'Field "' . $field . '" is not registered on this site, or no field group applies to this post_id. Writing an unregistered field through update_field() would store the value without a field key reference, and get_field() would then return the wrong type or null — the silent success this tool exists to prevent.' );
    }

    $field_name = $field_obj['name'] ?? $field;

    // Credential-shaped fields: refuse the write, not just redact the reply.
    // The value would be stored in plain text in post meta or wp_options, and
    // the audit log would record it. This matches the option tools, which
    // refuse credential-shaped writes rather than allowing and redacting.
    // The decision is made on the resolved field name, not the selector.
    if ( GMCP_Core::field_looks_secret( $field_name ) || GMCP_Core::field_looks_secret( $field ) ) {
      return $this->error( $r, 'Field "' . $field_name . '" looks like it holds a credential, so it is not writable through the API. This is the same rule the option tools use.', -32600 );
    }

    // For options-page writes, resolve the option name and apply the option
    // guard and write policy before writing. ACF treats any string as an option
    // prefix, so the tool must check the resolved name, not trust ACF to refuse.
    $option_name = $this->resolve_option_name( $post_id, $field_name );
    if ( $option_name !== null ) {
      $permitted = GMCP_Core::option_guard( $option_name );
      if ( $permitted !== true ) {
        return $this->error( $r, $permitted, -32600 );
      }
      $policy = GMCP_Core::option_write_policy( $option_name, $value );
      if ( $policy !== true ) {
        return $this->error( $r, $policy, -32600 );
      }
      // Also check the prefix itself (e.g. 'options') is not protected.
      $prefix_guard = GMCP_Core::option_guard( $post_id === 'option' ? 'options' : (string) $post_id );
      if ( $prefix_guard !== true ) {
        return $this->error( $r, $prefix_guard, -32600 );
      }
    }

    // update_field() returns false both when the write failed and when the new value equals the
    // stored one (update_metadata() and update_option() say so), so its return value cannot be
    // the verdict. The verdict is the raw stored value: format_value false, compared with what
    // was asked for. The formatted value is the theme's view (a date in d/m/Y, a select as
    // {value,label}) and would call a correct write a failure.
    $before = $this->raw_value( $field_name, $post_id );

    try {
      $result = update_field( $field, $value, $post_id );
    } catch ( Throwable $e ) {
      return $this->error( $r, 'update_field() threw an exception: ' . $e->getMessage() );
    }

    $after = $this->raw_value( $field_name, $post_id );

    $field_type   = $field_obj['type'] ?? 'text';
    $simple_types = [ 'text', 'textarea', 'number', 'email', 'url', 'password', 'range', 'date_picker', 'date_time_picker', 'time_picker', 'select', 'radio', 'checkbox', 'true_false', 'button_group', 'color_picker' ];
    $note = '';
    if ( in_array( $field_type, $simple_types, true ) ) {
      if ( $this->same_value( $after, $value ) ) {
        $note = $this->same_value( $before, $value ) ? ' The stored value was already this, so nothing changed.' : '';
      } elseif ( $this->same_value( $after, $before ) ) {
        // Neither the requested value nor a changed one: the write did not land.
        return $this->error( $r, 'Field "' . $field . '" was not changed: update_field() returned ' . wp_json_encode( $result ) . ' and the stored value is still ' . wp_json_encode( $after ) . ', not the requested ' . wp_json_encode( $value ) . '. A sanitizer or a filter on the field may have rejected it.', -32603 );
      } else {
        // Stored, but as something else: ACF normalised it. The write landed, so it is not
        // an error, and the caller is told what is actually there.
        $note = ' ACF stored it as ' . wp_json_encode( $after ) . ' rather than the ' . wp_json_encode( $value ) . ' requested.';
      }
    } elseif ( $result === false ) {
      // A complex type has no scalar to compare. false from update_field() is ambiguous, and
      // the reference below is the only thing that can be confirmed.
      $note = ' update_field() returned false, which ACF also does when nothing changed; the stored value of a ' . $field_type . ' field cannot be compared here.';
    }

    // Confirm the field key reference was written, which is the thing that makes get_field()
    // return the right type. The meta type must match the post_id.
    $ref_key = '_' . $field_name;
    $ref = $this->read_reference( $post_id, $ref_key );
    if ( empty( $ref ) ) {
      return $this->error( $r, 'Field "' . $field . '" was written but the field key reference (_' . $field_name . ') was not stored. get_field() will not return the correct type without it.', -32603 );
    }

    $removed = $value === null ? ' A null value deletes the field\'s stored value.' : '';
    return $this->text( $r, 'ACF field "' . $field_name . '" set for ' . ( is_int( $post_id ) ? 'post ' . $post_id : '"' . $post_id . '"' ) . '. Verified against the raw stored value and the field key reference.' . $note . $removed );
  }

  /** The raw stored value of a field, with no return-format applied. */
  private function raw_value( string $field_name, $post_id ) {
    try {
      return get_field( $field_name, $post_id, false );
    } catch ( Throwable $e ) {
      return null;
    }
  }

  /**
  * Whether two values are the same thing as ACF stores it: meta is text, so 5 and "5" and
  * true and "1" agree, and arrays are compared element by element the same way.
  */
  private function same_value( $a, $b ): bool {
    if ( is_array( $a ) || is_array( $b ) ) {
      if ( !is_array( $a ) || !is_array( $b ) || count( $a ) !== count( $b ) ) {
        return false;
      }
      foreach ( $a as $k => $v ) {
        if ( !array_key_exists( $k, $b ) || !$this->same_value( $v, $b[ $k ] ) ) {
          return false;
        }
      }
      return true;
    }
    if ( $a === null || $a === '' || $a === false ) {
      return $b === null || $b === '' || $b === false;
    }
    if ( is_bool( $b ) ) {
      return (string) $a === ( $b ? '1' : '0' );
    }
    if ( is_scalar( $a ) && is_scalar( $b ) ) {
      return (string) $a === (string) $b;
    }
    return $a == $b;
  }

  /**
  * List every ACF field on a given post_id with values.
  */
  private function get_field_objects( array $args, array $r ): array {
    [ $err, $post_id ] = $this->validate_post_id( $args['post_id'] ?? null );
    if ( $err ) {
      return $this->error( $r, $err );
    }

    try {
      $fields = get_field_objects( $post_id );
    } catch ( Throwable $e ) {
      return $this->error( $r, 'get_field_objects() threw an exception: ' . $e->getMessage() );
    }

    if ( !is_array( $fields ) ) {
      $fields = [];
    }

    $result = [];
    foreach ( $fields as $name => $field ) {
      $value = $field['value'] ?? null;
      // Credential-shaped values are redacted.
      $protected_row = false;
      if ( $this->is_option_location( $post_id ) ) {
        $prefix        = $post_id === 'option' ? 'options' : (string) $post_id;
        $protected_row = GMCP_Core::option_guard( $prefix . '_' . $name ) !== true;
      }
      if ( $protected_row || GMCP_Core::field_looks_secret( (string) $name ) || GMCP_Core::field_looks_secret( (string) ( $field['name'] ?? '' ) ) ) {
        $value = '[redacted]';
      } else {
        $value = GMCP_Core::redact( $value );
      }
      $result[ $name ] = [
        'key'   => $field['key'] ?? '',
        'label' => $field['label'] ?? '',
        'type'  => $field['type'] ?? '',
        'value' => $value,
      ];
    }

    return $this->json( $r, [ 'fields' => $result ] );
  }

}
