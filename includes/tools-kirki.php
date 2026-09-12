<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Kirki Customizer Framework, and the things about it the generic option and theme_mod
* tools get wrong.
*
* Kirki stores its values in one of three places, and which one depends on the field's
* option_type and option_name: a theme_mod (the default), a single named option row
* holding a serialized array, or a standalone option row per field. The generic tools
* can read and write all three, but they cannot tell the caller which one a given field
* uses, and writing through the wrong one is a silent success: the value lands in a row
* nothing reads, and the front end keeps rendering the old value. This group resolves
* the field's storage from its registration and writes through the right path.
*
* The other half is CSS. Kirki generates CSS from each field's output argument, and in
* modern Kirki (v4+) that CSS is inline and recomputed on every front-end page load, so a
* value written through this group takes effect on the next load without any extra step.
* The Google Fonts cache is the one thing that can lag: Kirki downloads font files and
* caches the remote CSS, and changing a typography field does not invalidate that cache
* on its own. kirki_regenerate_css clears it.
*
* Kirki's classes move between versions, so every call into one is guarded and reports
* what was missing rather than fataling. The class is only constructed when the group is
* switched on, but the per-call check in handle_call() is what keeps the tools honest
* when Kirki has been deactivated between the two.
*/
class GMCP_Tools_Kirki {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [
    'kirki_set_field_value', 'kirki_regenerate_css',
  ];

  /**
  * Options that hold Kirki's font and CSS caches, and what each one is.
  *
  * kirki_downloaded_font_files maps remote font URLs to local files under
  * wp-content/fonts/. kirki_remote_url_contents caches the CSS Kirki fetched from
  * Google's font API. Both are left alone by the generic option tools because neither
  * name looks credential-shaped, and both are safe to delete: Kirki rebuilds them on
  * the next page load that needs them.
  */
  const FONT_OPTION = 'kirki_downloaded_font_files';
  const FONT_TRANSIENT = 'kirki_remote_url_contents';

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
      'kirki_list_fields' => [
        'name' => 'kirki_list_fields',
        'description' => 'List every Kirki field registered on this site with its type, settings key, section, default value, storage model (option_type and option_name), transport and output rules. Reads from Kirki\'s own field registry, so it shows what the theme actually registered rather than what happens to be stored. A field that is registered but never had a value set reports its default here and reads back as that default from kirki_get_field_value. Use this before writing a value to find the field\'s settings key and storage model, since writing through the wrong storage is a silent success.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'config_id' => [ 'type' => 'string', 'description' => 'Filter by Kirki config ID. Omit for all configs.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'kirki_get_field_value' => [
        'name' => 'kirki_get_field_value',
        'description' => 'Get a single Kirki field\'s stored value, applying the field\'s registered default and resolving the storage model (theme_mod, grouped option or standalone option) automatically. This is the read that matches kirki_set_field_value: the same field id reads back what was written. Credential-shaped values are redacted by the same rule the option tools use, so a field named api_key does not hand its value to the model.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'config_id' => [ 'type' => 'string', 'description' => 'The Kirki config ID the field was registered under. Omit for the global config.' ],
            'field_id' => [ 'type' => 'string', 'description' => 'The field\'s settings key, as shown by kirki_list_fields.' ],
          ],
          'required' => [ 'field_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'kirki_set_field_value' => [
        'name' => 'kirki_set_field_value',
        'description' => 'Set a single Kirki field\'s value, writing through the correct storage for that field: set_theme_mod for theme_mod fields, a merged array write for option fields with an option_name, and a standalone option write for option fields without one. The field\'s option_type and option_name are resolved from its registration, so the caller does not need to know the storage model. For option storage the write passes through the same option guard and write policy as wp_update_option, so a field whose resolved option name is siteurl or a credential-shaped key is refused here the same way. The write is journalled, so wp_undo_change can put it back. Kirki regenerates its CSS inline on every front-end page load, so the new value takes effect on the next load without an extra step; run kirki_regenerate_css afterwards only if the field is a typography field and you want the Google Fonts cache cleared.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'config_id' => [ 'type' => 'string', 'description' => 'The Kirki config ID. Omit for the global config.' ],
            'field_id' => [ 'type' => 'string', 'description' => 'The field\'s settings key, as shown by kirki_list_fields.' ],
            'value' => [ 'description' => 'The value to store. Any type.' ],
          ],
          'required' => [ 'field_id', 'value' ],
        ],
        'accessLevel' => 'admin',
      ],
      'kirki_regenerate_css' => [
        'name' => 'kirki_regenerate_css',
        'description' => 'Clear Kirki\'s Google Fonts cache so font choices are re-fetched on the next page load. Kirki generates its CSS inline and recomputes it on every front-end request, so a value written through kirki_set_field_value takes effect on the next load without this call. What can lag is the Google Fonts cache: Kirki downloads font files and caches the remote CSS, and changing a typography field does not invalidate that cache on its own. This deletes the downloaded-fonts option and the remote-CSS transient, and reports what was cleared. It does not touch a full-page cache (Varnish, WP Rocket, Cloudflare), so a visitor may still be served the old page until that is purged too; run wp_flush_cache for the page-cache half.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'admin',
      ],
      'kirki_export_config' => [
        'name' => 'kirki_export_config',
        'description' => 'Export the site\'s Kirki configuration as a JSON document: every registered field with its type, settings key, default, storage model and current stored value, plus every config. Read-only. This is not an import tool: there is no kirki_import_config, deliberately, because importing rewrites the whole design system from an archive built elsewhere in a single call with no restore tool behind it. Credential-shaped values are redacted, so the export is safe to share.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'admin',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // Belt and braces, the same as the Elementor tools. The class is only constructed
    // when the group is switched on, but Kirki can be deactivated between that and this
    // call, and every Kirki lookup below would then be a fatal rather than a sentence
    // the caller can act on. This is a runtime honesty check, not a security guard:
    // the guards that matter live in GMCP_Core::option_guard() and
    // option_write_policy(), which this group consults on every write.
    if ( !class_exists( 'Kirki' ) ) {
      return $this->error( $r, 'Kirki is not loaded on this site, so its tools cannot run.' );
    }

    switch ( $tool ) {
      case 'kirki_list_fields': $r = $this->list_fields( $args, $r ); break;
      case 'kirki_get_field_value': $r = $this->get_field_value( $args, $r ); break;
      case 'kirki_set_field_value': $r = $this->set_field_value( $args, $r ); break;
      case 'kirki_regenerate_css': $r = $this->regenerate_css( $args, $r ); break;
      case 'kirki_export_config': $r = $this->export_config( $args, $r ); break;
      default:
        return $this->error( $r, 'Unknown tool', -32601 );
    }

    if ( empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

  #region Helpers

  /**
  * Read a value back through Kirki's own API after a write and confirm it matches.
  *
  * A tool reporting "set" and a value actually landing where Kirki reads it are
  * different claims. set_theme_mod and update_option can both return without error
  * while writing nothing or the wrong row, so the only honest success is a read-back
  * through the same path Kirki uses. The comparison is loose (==) because Kirki may
  * cast or normalise a value on the way in, and the stored form is the one that
  * matters, not the one the caller sent.
  */
  private function verify_write( array $r, string $field_id, string $config_id, $value, string $where ): array {
    $stored = \Kirki::get_option( $config_id, $field_id );
    if ( $stored == $value ) {
      return $this->text( $r, 'Kirki field "' . $field_id . '" set (' . $where . '). Verified by reading back through Kirki::get_option().' );
    }
    return $this->error( $r, 'Kirki field "' . $field_id . '" was written but the read-back through Kirki::get_option() did not match. The value may have been cast or rejected by a sanitizer. Requested: ' . wp_json_encode( $value ) . ', stored: ' . wp_json_encode( $stored ), -32603 );
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

  /**
  * Resolve a field's registration from Kirki's two registries.
  *
  * Kirki has two static arrays: $fields (legacy fields built through the Compatibility
  * Field class) and $all_fields (modern fields built through the new base Field class).
  * A field can be in either, and the two disagree on which keys are present, so both
  * are checked and the one that has it wins. Returns null when the field is not
  * registered, which is different from a field that is registered with a null default.
  */
  private function find_field( string $field_id ): ?array {
    // The global Kirki class is Kirki\Compatibility\Kirki, aliased to Kirki. Both static
    // arrays are public and keyed by the settings id.
    if ( isset( \Kirki::$all_fields[ $field_id ] ) ) {
      return \Kirki::$all_fields[ $field_id ];
    }
    if ( isset( \Kirki::$fields[ $field_id ] ) ) {
      return \Kirki::$fields[ $field_id ];
    }
    return null;
  }

  /**
  * The config args for a config id, or the global config's defaults.
  *
  * Kirki::$config is keyed by config id and each entry is an array of args. The global
  * config (id '') is added at boot and normalised to id 'global'. A field's option_type
  * and option_name come from its own args merged with its config's, so resolving the
  * config is the first step to resolving the storage.
  */
  private function config_args( string $config_id ): array {
    if ( $config_id === '' ) {
      $config_id = 'global';
    }
    if ( isset( \Kirki::$config[ $config_id ] ) ) {
      return \Kirki::$config[ $config_id ];
    }
    // Fall back to the global config, which Kirki always adds at boot.
    return \Kirki::$config['global'] ?? [
      'option_type' => 'theme_mod',
      'option_name' => '',
    ];
  }

  /**
  * Resolve a field's storage model from its registration and its config.
  *
  * Returns the option_type ('theme_mod' or 'option'), the option_name (empty for
  * theme_mod or standalone option), and the settings key as stored. For option fields
  * with an option_name, the stored settings key is the field_id; Kirki's Values::get_value
  * extracts it from the grouped option by stripping the option_name[...] wrapper.
  */
  private function storage( array $field ): array {
    $option_type = $field['option_type'] ?? 'theme_mod';
    $option_name = $field['option_name'] ?? '';
    $settings = $field['settings'] ?? '';

    // For option fields with an option_name, Kirki wraps the settings key as
    // "option_name[field_id]" in its internal registry. The stored key inside the
    // grouped option is the bare field_id, so strip the wrapper if present. The
    // wrapper is well-formed: it starts with "option_name[" and ends with "]". A
    // settings key that does not match that shape is left alone, since it may be a
    // nested path Kirki handles differently.
    if ( is_string( $settings ) && $option_name !== '' ) {
      $prefix = $option_name . '[';
      $suffix = ']';
      if ( strpos( $settings, $prefix ) === 0 && substr( $settings, -strlen( $suffix ) ) === $suffix ) {
        $settings = substr( $settings, strlen( $prefix ), -strlen( $suffix ) );
      }
    }

    return [
      'option_type' => $option_type,
      'option_name' => $option_name,
      'settings' => is_string( $settings ) ? $settings : '',
    ];
  }

  #endregion

  #region Tools

  private function list_fields( array $a, array $r ): array {
    $filter_config = $a['config_id'] ?? '';

    // Merge both registries, with $all_fields taking precedence since it holds the
    // modern field definitions. A field in both is the same field, so the dedup is by
    // settings key.
    $all = \Kirki::$all_fields;
    foreach ( \Kirki::$fields as $key => $field ) {
      if ( !isset( $all[ $key ] ) ) {
        $all[ $key ] = $field;
      }
    }

    $out = [];
    foreach ( $all as $key => $field ) {
      $config_id = $field['kirki_config'] ?? 'global';
      if ( $filter_config !== '' && $config_id !== $filter_config ) {
        continue;
      }
      $storage = $this->storage( $field );
      $default = $field['default'] ?? null;
      // A field named api_key can have a secret as its default, so the default is
      // redacted the same way the value is. The field definition is still exported so
      // the caller can see the field exists.
      if ( GMCP_Core::field_looks_secret( $key ) ) {
        $default = GMCP_Core::REDACTION_MARKER;
      }
      $out[] = [
        'settings' => $key,
        'type' => $field['type'] ?? '',
        'label' => $field['label'] ?? '',
        'section' => $field['section'] ?? '',
        'default' => $default,
        'config_id' => $config_id,
        'option_type' => $storage['option_type'],
        'option_name' => $storage['option_name'],
        'transport' => $field['transport'] ?? 'refresh',
        'has_output' => !empty( $field['output'] ),
      ];
    }

    return $this->json( $r, [
      'count' => count( $out ),
      'fields' => $out,
    ] );
  }

  private function get_field_value( array $a, array $r ): array {
    $field_id = (string) ( $a['field_id'] ?? '' );
    if ( $field_id === '' ) {
      return $this->error( $r, 'field_id required' );
    }
    $config_id = (string) ( $a['config_id'] ?? '' );
    if ( $config_id === '' ) {
      $config_id = 'global';
    }

    $field = $this->find_field( $field_id );
    if ( !$field ) {
      return $this->error( $r, 'No Kirki field registered with settings "' . $field_id . '". Call kirki_list_fields to see what is registered.' );
    }

    // Kirki::get_option resolves the storage model and applies the field's default,
    // which is exactly what we want: a field that was never set reads back as its
    // default rather than as null.
    $value = \Kirki::get_option( $config_id, $field_id );

    // Credential-shaped values are redacted by the same rule the option tools use. A
    // theme can register a field named api_key, and its value is a secret the model
    // should not see in plain text. The field name is the signal, the same way it is
    // for an option key.
    if ( GMCP_Core::field_looks_secret( $field_id ) ) {
      $value = GMCP_Core::REDACTION_MARKER;
    }
    else {
      $value = GMCP_Core::redact( $value );
    }

    $storage = $this->storage( $field );
    return $this->json( $r, [
      'field_id' => $field_id,
      'value' => $value,
      'option_type' => $storage['option_type'],
      'option_name' => $storage['option_name'],
    ] );
  }

  private function set_field_value( array $a, array $r ): array {
    $field_id = (string) ( $a['field_id'] ?? '' );
    if ( $field_id === '' ) {
      return $this->error( $r, 'field_id required' );
    }
    $config_id = (string) ( $a['config_id'] ?? '' );
    if ( $config_id === '' ) {
      $config_id = 'global';
    }
    $value = $a['value'] ?? null;

    $field = $this->find_field( $field_id );
    if ( !$field ) {
      return $this->error( $r, 'No Kirki field registered with settings "' . $field_id . '". Call kirki_list_fields to see what is registered.' );
    }

    // The field's own config is the one that knows its storage model, so verification
    // reads through it rather than through the config_id the caller passed. A caller
    // who omits config_id defaults to "global", but a field registered under a named
    // config would then be read through the wrong storage model and the verification
    // would fail even though the write landed correctly.
    $field_config = $field['kirki_config'] ?? 'global';

    $storage = $this->storage( $field );
    $option_type = $storage['option_type'];
    $option_name = $storage['option_name'];
    $settings = $storage['settings'];

    if ( $settings === '' ) {
      return $this->error( $r, 'The field "' . $field_id . '" has no resolved settings key, so its value cannot be written.' );
    }

    if ( $option_type === 'theme_mod' ) {
      // set_theme_mod merges one key into the theme_mods_<stylesheet> array, which is
      // the safe primitive: a full-array replace on the row would wipe
      // nav_menu_locations, sidebars_widgets and every other mod. The row passes
      // through the same guard and write policy as any other option, because
      // set_theme_mod calls update_option under the hood.
      $row = 'theme_mods_' . get_option( 'stylesheet' );
      $permitted = GMCP_Core::option_guard( $row );
      if ( $permitted !== true ) {
        return $this->error( $r, $permitted, -32600 );
      }
      $policy = GMCP_Core::option_write_policy( $row, $value );
      if ( $policy !== true ) {
        return $this->error( $r, $policy, -32600 );
      }
      set_theme_mod( $settings, $value );
      return $this->verify_write( $r, $field_id, $field_config, $value, 'theme_mod "' . $settings . '"' );
    }

    if ( $option_type === 'option' && $option_name !== '' ) {
      // Grouped option: the field's value is one key inside a named option array. A
      // full replace on the option would wipe every sibling field in the same group,
      // so this is a read-merge-write. The option name passes through the same guard
      // and write policy as wp_update_option, so a grouped option named siteurl or a
      // credential-shaped key is refused the same way.
      $permitted = GMCP_Core::option_guard( $option_name );
      if ( $permitted !== true ) {
        return $this->error( $r, $permitted, -32600 );
      }
      $current = get_option( $option_name, [] );
      $current = is_array( $current ) ? $current : [];
      $new = $current;
      $new[ $settings ] = $value;
      $policy = GMCP_Core::option_write_policy( $option_name, $new );
      if ( $policy !== true ) {
        return $this->error( $r, $policy, -32600 );
      }
      update_option( $option_name, $new, null );
      return $this->verify_write( $r, $field_id, $field_config, $value, 'option "' . $option_name . '"["' . $settings . '"]' );
    }

    if ( $option_type === 'option' ) {
      // Standalone option: the field's settings key is the option name. The same guard
      // and policy apply, so a field whose settings key is siteurl or a credential-
      // shaped name is refused here the same way wp_update_option would refuse it.
      $permitted = GMCP_Core::option_guard( $settings );
      if ( $permitted !== true ) {
        return $this->error( $r, $permitted, -32600 );
      }
      $policy = GMCP_Core::option_write_policy( $settings, $value );
      if ( $policy !== true ) {
        return $this->error( $r, $policy, -32600 );
      }
      update_option( $settings, $value, null );
      return $this->verify_write( $r, $field_id, $field_config, $value, 'option "' . $settings . '"' );
    }

    return $this->error( $r, 'The field "' . $field_id . '" uses an unknown option_type "' . $option_type . '", so its value cannot be written.' );
  }

  private function regenerate_css( array $a, array $r ): array {
    $cleared = [];
    $absent = [];

    // The downloaded-fonts option maps remote font URLs to local files. Deleting it
    // makes Kirki re-download on the next page load that needs them.
    if ( get_option( self::FONT_OPTION, null ) !== null ) {
      delete_option( self::FONT_OPTION );
      $cleared[] = self::FONT_OPTION . ' (downloaded font files map)';
    }
    else {
      $absent[] = self::FONT_OPTION . ' (was not set)';
    }

    // The remote-CSS transient caches the CSS Kirki fetched from Google's font API.
    if ( get_transient( self::FONT_TRANSIENT ) !== false ) {
      delete_transient( self::FONT_TRANSIENT );
      $cleared[] = self::FONT_TRANSIENT . ' (remote font CSS cache)';
    }
    else {
      $absent[] = self::FONT_TRANSIENT . ' (was not set)';
    }

    $msg = "Kirki Google Fonts cache cleared.\n";
    if ( $cleared ) {
      $msg .= "Cleared: " . implode( '; ', $cleared ) . ".\n";
    }
    if ( $absent ) {
      $msg .= "Already absent: " . implode( '; ', $absent ) . ".\n";
    }
    $msg .= "Kirki generates its CSS inline and recomputes it on every front-end page load, so a value written through kirki_set_field_value takes effect on the next load without this call. This clears the Google Fonts cache only. A full-page cache (Varnish, WP Rocket, Cloudflare) is separate and not touched here; run wp_flush_cache for the page-cache half.";

    return $this->text( $r, $msg );
  }

  private function export_config( array $a, array $r ): array {
    // Merge both field registries, same as list_fields.
    $all = \Kirki::$all_fields;
    foreach ( \Kirki::$fields as $key => $field ) {
      if ( !isset( $all[ $key ] ) ) {
        $all[ $key ] = $field;
      }
    }

    $fields = [];
    foreach ( $all as $key => $field ) {
      $storage = $this->storage( $field );
      $config_id = $field['kirki_config'] ?? 'global';

      // The stored value, resolved through Kirki's own value API so the export shows
      // what the front end actually sees, including defaults for unset fields.
      $value = \Kirki::get_option( $config_id, $key );

      // Credential-shaped values are redacted, so the export is safe to share. A field
      // named api_key has its value and default blanked; the field definition is still
      // exported so the recipient can see the field exists.
      $default = $field['default'] ?? null;
      if ( GMCP_Core::field_looks_secret( $key ) ) {
        $value = GMCP_Core::REDACTION_MARKER;
        $default = GMCP_Core::REDACTION_MARKER;
      }
      else {
        $value = GMCP_Core::redact( $value );
      }

      $fields[] = [
        'settings' => $key,
        'type' => $field['type'] ?? '',
        'label' => $field['label'] ?? '',
        'section' => $field['section'] ?? '',
        'default' => $default,
        'config_id' => $config_id,
        'option_type' => $storage['option_type'],
        'option_name' => $storage['option_name'],
        'transport' => $field['transport'] ?? 'refresh',
        'has_output' => !empty( $field['output'] ),
        'value' => $value,
      ];
    }

    // Configs: strip the 'id' key which duplicates the array key, and keep the rest.
    $configs = [];
    foreach ( \Kirki::$config as $id => $args ) {
      $configs[ $id ] = array_diff_key( $args, [ 'id' => 1 ] );
    }

    return $this->json( $r, [
      'version' => defined( 'KIRKI_VERSION' ) ? KIRKI_VERSION : 'unknown',
      'theme' => get_option( 'stylesheet' ),
      'configs' => $configs,
      'fields' => $fields,
    ] );
  }

  #endregion

}
