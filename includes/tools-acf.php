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
        'description' => 'List every ACF field group and its fields registered on this site, with each field\'s key (field_123abc), name, label, type, instructions, required flag, and return format. Reads from ACF\'s own field registry (acf_get_field_groups and acf_get_fields), so it shows what the theme or plugin actually registered — both UI-defined groups (stored as acf-field-group posts) and PHP-registered groups (acf_add_local_field_group). Optionally takes a post_id to filter to groups whose location rules apply to that post. Use this before writing a value to find the field key, since writing through update_post_meta without the field key reference is a silent success.',
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
        'description' => 'Read a single ACF field value through get_field(), which applies the field\'s return format: a WP_Post for post object fields, an image array for image fields, an array of rows for repeaters. This is the value the theme sees, not the raw stored meta. Without the field key reference (_fieldname = field_123abc), get_field() returns the wrong type or null, so this read confirms the field is properly registered. Credential-shaped field values are redacted by the same rule the option tools use. Pass format=false to read the raw stored value without formatting.',
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
        'description' => 'Write a single ACF field value through update_field(), which resolves the field definition, writes both the value and the hidden field key reference (_fieldname = field_123abc), and runs the field-type update_value filters that expand repeaters, store image IDs, and serialize arrays correctly. This is the safe primitive for writing an ACF value: update_post_meta alone writes the value without the key reference, and get_field() then returns the wrong type or null. For options-page writes (post_id = "option"), the resolved option name (options_{$field_name}) passes through the same option_guard and option_write_policy as wp_update_option, so a credential-shaped field name is refused. The post_id is restricted to known forms (integer, user_*, term_*, category_*, option, options); arbitrary strings are refused because ACF treats any string as an option prefix. The write is journalled, so wp_undo_change can put it back.',
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
    return [ 'post_id "' . $post_id . '" is not a recognised ACF location. Allowed: integer, "option", "options", "user_N", "term_N", "category_N", "comment_N". Arbitrary strings are refused because ACF treats any string as an option prefix, which could target any option row.', null ];
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
    // Custom options pages: any string that is not a known meta type.
    // But we only reach here for the allowlisted post_id forms, and the only
    // string forms in the allowlist are 'option', 'options', and the
    // user_/term_/category_/comment_ prefixed ones. So there is no custom
    // options-page path through this tool — it is refused by validate_post_id.
    return null;
  }

  /**
  * Resolve the real field name from a selector (field name or field key).
  *
  * The credential redaction decision must be made on the resolved field
  * name, not the selector the caller passed. A caller who asks for
  * "field_abc123" (the field key) for a field named "api_key" should not
  * get the value back in plain text just because the key does not look
  * secret-shaped.
  *
  * Returns the field name, or null if the field cannot be resolved.
  */
  private function resolve_field_name( string $selector, $post_id ): ?string {
    $field_obj = acf_get_field( $selector );
    if ( $field_obj && !empty( $field_obj['name'] ) ) {
      return $field_obj['name'];
    }
    // Maybe it is a field name, not a key. Try the reference.
    $field_key = acf_get_reference( $selector, $post_id );
    if ( $field_key ) {
      $field_obj = acf_get_field( $field_key );
      if ( $field_obj && !empty( $field_obj['name'] ) ) {
        return $field_obj['name'];
      }
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
          $field_list[] = [
            'key'         => $field['key'] ?? '',
            'name'        => $field['name'] ?? '',
            'label'       => $field['label'] ?? '',
            'type'        => $field['type'] ?? '',
            'instructions'=> $field['instructions'] ?? '',
            'required'    => !empty( $field['required'] ),
            'return_format' => $field['return_format'] ?? null,
          ];
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

    try {
      $value = get_field( $field, $post_id, $format );
    } catch ( Throwable $e ) {
      return $this->error( $r, 'get_field() threw an exception: ' . $e->getMessage() );
    }

    // Credential-shaped field values are redacted, the same as the option
    // tools. The decision is made on the resolved field NAME, not the
    // selector the caller passed: a caller who asks for "field_abc123" (the
    // field key) should not get the value of a field named "api_key" back in
    // plain text just because the key does not look secret-shaped.
    $field_name = $this->resolve_field_name( $field, $post_id );
    if ( $field_name !== null && GMCP_Core::field_looks_secret( $field_name ) ) {
      $value = '[redacted]';
    } else {
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
    $value = $args['value'] ?? null;

    // Resolve the field object BEFORE applying the option guard. The guard
    // must check the resolved option name (options_{$field_name}), not the
    // raw selector the caller passed. If the caller passes a field key
    // (field_abc123), the guard needs the field's real name, not the key,
    // because ACF writes to options_{$field_name}, not options_{$field_key}.
    $field_obj = acf_get_field( $field );
    if ( !$field_obj ) {
      // Maybe the caller passed a field name, not a key. Try to find it by
      // name on the target post_id.
      $field_key = acf_get_reference( $field, $post_id );
      if ( $field_key ) {
        $field_obj = acf_get_field( $field_key );
      }
    }
    if ( !$field_obj ) {
      return $this->error( $r, 'Field "' . $field . '" is not registered on this site, or no field group applies to this post_id. Writing an unregistered field through update_field() would store the value without a field key reference, and get_field() would then return the wrong type or null — the silent success this tool exists to prevent.' );
    }

    $field_name = $field_obj['name'] ?? $field;

    // Credential-shaped fields: refuse the write, not just redact the reply.
    // The value would be stored in plain text in post meta or wp_options, and
    // the audit log would record it. This matches the option tools, which
    // refuse credential-shaped writes rather than allowing and redacting.
    // The decision is made on the resolved field name, not the selector.
    if ( GMCP_Core::field_looks_secret( $field_name ) ) {
      return $this->error( $r, 'Field "' . $field_name . '" looks like it holds a credential, so it is not writable through the API. This is the same rule the option tools use.', -32600 );
    }

    // For options-page writes, resolve the option name and apply the option
    // guard and write policy before writing. This is the guard the adversarial
    // review asked for: ACF treats any string as an option prefix, so the
    // tool must check the resolved name, not trust ACF to refuse. The name
    // is resolved from the field's real name, not the selector.
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

    try {
      $result = update_field( $field, $value, $post_id );
    } catch ( Throwable $e ) {
      return $this->error( $r, 'update_field() threw an exception: ' . $e->getMessage() );
    }

    if ( $result === false ) {
      return $this->error( $r, 'update_field() returned false for field "' . $field . '". The value may not have been stored.' );
    }

    // Verify by reading back through get_field(). The comparison is loose
    // (==) because get_field() with format_value=true returns a formatted
    // value (WP_Post, image array, row array) that may not equal the raw
    // value sent. For complex field types, compare the raw stored meta
    // instead, since the formatted value is a different shape.
    $field_type = $field_obj['type'] ?? 'text';
    $simple_types = [ 'text', 'textarea', 'number', 'email', 'url', 'password', 'range', 'date_picker', 'date_time_picker', 'time_picker', 'select', 'radio', 'checkbox', 'true_false', 'button_group', 'color_picker' ];
    if ( in_array( $field_type, $simple_types, true ) ) {
      try {
        $stored = get_field( $field_name, $post_id, true );
      } catch ( Throwable $e ) {
        $stored = null;
      }
      // Loose comparison: ACF may cast or normalise.
      if ( $stored != $value ) {
        return $this->error( $r, 'Field "' . $field . '" was written but the read-back through get_field() did not match. The value may have been cast or rejected by a sanitizer. Requested: ' . wp_json_encode( $value ) . ', stored: ' . wp_json_encode( $stored ), -32603 );
      }
    }
    // For complex field types (image, post_object, repeater, relationship,
    // gallery, flexible_content), the formatted value is a different shape
    // from the raw value sent, so a comparison would always fail. Instead,
    // confirm the field key reference was written, which is the thing that
    // makes get_field() return the right type. The meta type must match
    // the post_id: post meta for integers, user meta for user_*, etc.
    $ref_key = '_' . $field_name;
    $ref = $this->read_reference( $post_id, $ref_key );
    if ( empty( $ref ) ) {
      return $this->error( $r, 'Field "' . $field . '" was written but the field key reference (_' . $field_name . ') was not stored. get_field() will not return the correct type without it.', -32603 );
    }

    return $this->text( $r, 'ACF field "' . $field_name . '" set for ' . ( is_int( $post_id ) ? 'post ' . $post_id : '"' . $post_id . '"' ) . '. Verified by reading back through get_field() and confirming the field key reference.' );
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
      if ( GMCP_Core::field_looks_secret( $name ) ) {
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
