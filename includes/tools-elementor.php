<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Elementor, and the few things about it that the generic post and meta tools get wrong.
*
* Everything here could in principle be done with wp_update_post_meta and wp_update_option.
* In practice it is done wrongly, in two specific ways that both look like success.
*
* The first is the theme-builder conditions cache. Elementor keeps a cached registry of
* which template applies where in the option elementor_pro_theme_builder_conditions,
* separate from each template's own _elementor_conditions meta. Saving in the editor writes
* both. Writing only the meta leaves the cache stale, and Elementor declines to rebuild it
* whenever the stored value is already an array: an empty array reads as "computed, nothing
* here", so a header set up that way is invisible forever and no amount of reloading the
* front end fixes it. The tools below read both halves, say whether they agree, and change
* them together.
*
* The second is _elementor_data. It is a JSON string, routinely over 100KB, and
* update_metadata() unslashes whatever it is handed. Passing that string back in through a
* tool argument therefore eats every escape in it and returns a broken document, quite apart
* from the size. elementor_apply_template moves it inside PHP so it never leaves the server.
*
* Elementor's internals move between versions, so every call into one of its classes is
* guarded and reports what was missing rather than fataling. Conditions themselves need the
* theme builder, which is Elementor Pro or PRO Elements; on a site with only the free plugin
* the meta and the option are still written and nothing reads them, and the tools say so
* rather than reporting a success the site will not show.
*/
class GMCP_Tools_Elementor {

  /**
  * Elementor's cached conditions registry, shaped location => [ template ID => conditions ].
  *
  * The "pro" in the name is historical: the free plugin's floating-buttons module writes to
  * the same option, which is why deleting it is reported rather than done quietly.
  */
  const CONDITIONS_OPTION = 'elementor_pro_theme_builder_conditions';

  const CONDITIONS_META = '_elementor_conditions';
  const TYPE_META = '_elementor_template_type';
  const DATA_META = '_elementor_data';

  /**
  * Which theme-builder location each template type belongs to.
  *
  * Elementor registers four locations and more than one template type lands in each: a 404
  * page and a single post are both "single". The bucketing follows what Elementor's own
  * site builder does when it writes this cache, which sorts header and footer by name and
  * puts everything else in single.
  *
  * Types that are absent here (section, page, popup, kit, the floating-button types) do not
  * take theme-builder conditions at all. An unknown type is refused rather than guessed at,
  * because a guess would write a location Elementor never reads and the caller would be told
  * the template was live.
  */
  const LOCATIONS = [
    'header' => 'header',
    'footer' => 'footer',
    'single' => 'single',
    'single-post' => 'single',
    'single-page' => 'single',
    'error-404' => 'single',
    'product' => 'single',
    'archive' => 'archive',
    'product-archive' => 'archive',
    'search-results' => 'archive',
  ];

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [
    'elementor_set_conditions', 'elementor_regenerate_css', 'elementor_apply_template',
  ];

  /** The three tools that read or delete the conditions option, and so pass the option guard. */
  const TOUCH_OPTION = [
    'elementor_list_templates', 'elementor_get_conditions', 'elementor_set_conditions',
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
      'elementor_list_templates' => [
        'name' => 'elementor_list_templates',
        'description' => 'List Elementor library templates with their theme-builder conditions, and say whether Elementor\'s cached conditions registry agrees with each template\'s own meta. Returns id, title, status, template type, the conditions stored on the template, and the conditions the cache holds for it. A template whose meta reads include/general but which is absent from the cache is configured correctly and still invisible on the front end; that mismatch is what this tool is for. Changes nothing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'type' => [ 'type' => 'string', 'description' => 'Filter by template type, e.g. header, footer, single, archive, section, page, popup.' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Default 50, maximum 100.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'elementor_get_conditions' => [
        'name' => 'elementor_get_conditions',
        'description' => 'Compare one template\'s conditions against Elementor\'s cached conditions registry, or do it for every template. Reports both halves, whether they agree, and what state the cache is in. An empty cache is the state worth knowing about: Elementor reads a stored empty array as "already computed, nothing here" and will never rebuild it on its own, so a header or footer set up while the cache is in that state never appears however many times the page is loaded. Changes nothing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'One elementor_library template. Omit for all of them.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'elementor_set_conditions' => [
        'name' => 'elementor_set_conditions',
        'description' => 'Set where a theme-builder template applies, the way the Elementor editor does: write the template\'s conditions and then make Elementor\'s cached registry agree. Writing the conditions alone is the usual mistake and leaves the header or footer invisible. Conditions are strings such as include/general (entire site), include/singular/page or exclude/archive/post, and they replace the template\'s existing conditions rather than adding to them. If Elementor Pro\'s conditions manager is reachable the cache is rebuilt through it, which is the call the editor makes; otherwise the cached option is deleted so Elementor rebuilds it from the templates on the next load, and its previous contents are reported because deleting an option is not something the change journal can put back. Refuses anything that is not an elementor_library post. Needs the theme builder (Elementor Pro or PRO Elements) to have any effect on the front end; without it the values are stored and nothing reads them, and the result says so.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'The elementor_library template.' ],
            'conditions' => [
              'type' => 'array',
              'items' => [ 'type' => 'string' ],
              'description' => 'Condition strings, each starting include/ or exclude/. An empty array unassigns the template.',
            ],
            'location' => [ 'type' => 'string', 'description' => 'header, footer, single or archive. Only needed when the template type does not say which, in which case the call is refused without it.' ],
          ],
          'required' => [ 'ID', 'conditions' ],
        ],
        'accessLevel' => 'admin',
      ],
      'elementor_regenerate_css' => [
        'name' => 'elementor_regenerate_css',
        'description' => 'Elementor\'s "Regenerate CSS & Data": delete every stylesheet and data file Elementor has generated so they are rebuilt on the next page load. Use it after changing global styles, or when a page renders with styles that no longer match its content. This clears Elementor\'s own generated files and nothing else. A full-page cache in front of WordPress, whether a caching plugin, Varnish or a CDN, is a separate thing that is not touched here, so a visitor may still be served the old page until that is purged too.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'admin',
      ],
      'elementor_apply_template' => [
        'name' => 'elementor_apply_template',
        'description' => 'Put an Elementor library template onto an existing page or post. Mode "shortcode", the default, sets the page content to [elementor-template id="N"]: the page stays linked to the template, so later edits to the template show up on the page, which is usually what you want. That shortcode comes with Elementor Pro or PRO Elements, and the call is refused if it is not registered rather than leaving the shortcode showing as text. Mode "copy" duplicates the template\'s Elementor data onto the page inside PHP; that freezes a snapshot which no longer follows the template, but it is the only way to move the data at all, since it is routinely over 100KB and loses its escaping if passed through a tool argument. A target already built with Elementor is refused unless overwrite is true, and in shortcode mode overwrite deletes the page\'s existing Elementor data, which the change journal does not record and cannot put back. page_template chooses the layout: elementor_header_footer drops the page title and the theme\'s content-width constraints but still fires the theme header and footer hooks, so a theme-builder header and footer still wrap the page, while elementor_canvas gives a bare page with neither. Picking canvas when you meant header_footer is the usual way to lose a site\'s navigation.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'page_id' => [ 'type' => 'integer', 'description' => 'The page or post to put the template on.' ],
            'template_id' => [ 'type' => 'integer', 'description' => 'The elementor_library template to apply.' ],
            'mode' => [ 'type' => 'string', 'description' => 'shortcode (default, stays linked) or copy (freezes a snapshot).' ],
            'page_template' => [ 'type' => 'string', 'description' => 'elementor_header_footer, elementor_canvas, or default for the theme\'s own template. Left alone if omitted.' ],
            'overwrite' => [ 'type' => 'boolean', 'description' => 'Replace an existing Elementor design on the target. Default false.' ],
          ],
          'required' => [ 'page_id', 'template_id' ],
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

    // Belt and braces, the same as the WooCommerce tools. The class is only constructed
    // when Elementor has loaded, but a plugin can be deactivated between that check and
    // this call, and every Elementor lookup below would then be a fatal rather than a
    // sentence the caller can act on.
    if ( !did_action( 'elementor/loaded' ) ) {
      return $this->error( $r, 'Elementor is not loaded on this site, so its tools cannot run.' );
    }

    // The conditions registry is an option like any other, so the tools that read or delete
    // it answer to the same guard as every other option access rather than to a second idea
    // of what is sensitive. A site that has protected this name is not read around.
    if ( in_array( $tool, self::TOUCH_OPTION, true ) ) {
      $allowed = GMCP_Core::option_guard( self::CONDITIONS_OPTION );
      if ( $allowed !== true ) {
        return $this->error( $r, $allowed );
      }
    }

    switch ( $tool ) {
      case 'elementor_list_templates': $r = $this->list_templates( $args, $r ); break;
      case 'elementor_get_conditions': $r = $this->get_conditions( $args, $r ); break;
      case 'elementor_set_conditions': $r = $this->set_conditions( $args, $r ); break;
      case 'elementor_regenerate_css': $r = $this->regenerate_css( $args, $r ); break;
      case 'elementor_apply_template': $r = $this->apply_template( $args, $r ); break;
      default:
        $r['error'] = [ 'code' => -32601, 'message' => 'Unknown tool' ];
        return $r;
    }

    // Both keys, because a refusal is an isError result and sets neither an error key nor
    // any claim that something changed. Testing only the first would purge caches for a
    // write that was turned away.
    if ( empty( $r['error'] ) && empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

  #region Helpers

  /**
  * A tool failure the model is supposed to read and act on.
  *
  * An isError result rather than a JSON-RPC error, for the reason tools-core.php's helper
  * gives at length: a protocol error carries no result, so a client reading result.content
  * finds nothing there and may discard the whole response. Nearly everything this file
  * refuses is something the caller can fix and retry, which only works if they are told.
  */
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
  * Whether the theme builder is here at all.
  *
  * Conditions are inert without it. The free Elementor plugin stores both the meta and the
  * option quite happily and nothing ever reads them, so a tool that reported success would
  * be telling the truth about the database and lying about the site. PRO Elements, the free
  * repackage, ships these classes under the same namespace, so one test covers both.
  */
  private function theme_builder_present(): bool {
    return class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' );
  }

  /** null when the option has never been written, which is a different thing from empty. */
  private function cache_value() {
    return get_option( self::CONDITIONS_OPTION, null );
  }

  /** What the stored cache means for whether templates are applied, in a sentence. */
  private function cache_state( $cache ): array {
    if ( $cache === null ) {
      return [
        'state' => 'absent',
        'meaning' => 'No value is stored. Elementor rebuilds the cache from the templates the next time it reads it, so this state heals itself.',
      ];
    }
    if ( !is_array( $cache ) ) {
      return [
        'state' => 'not an array',
        'meaning' => 'Elementor rebuilds the cache whenever the stored value is not an array, so this state heals itself on the next read.',
      ];
    }
    if ( $cache === [] ) {
      return [
        'state' => 'empty array',
        'meaning' => 'Elementor reads a stored empty array as already computed with nothing in it, so it will never rebuild it on its own and no theme-builder template is applied anywhere. Deleting the option, or saving conditions in the editor, is what clears this.',
      ];
    }
    return [
      'state' => 'populated',
      'locations' => array_keys( $cache ),
      'meaning' => 'Elementor treats this as authoritative: a template missing from it is not applied even when its own conditions say it should be.',
    ];
  }

  /** A template's own conditions, normalised to a list of strings. */
  private function meta_conditions( int $id ): array {
    $value = get_post_meta( $id, self::CONDITIONS_META, true );
    if ( is_string( $value ) && $value !== '' ) {
      $value = [ $value ];
    }
    if ( !is_array( $value ) ) {
      return [];
    }
    return array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
  }

  /** Where in the cache a template appears, or null if it appears nowhere. */
  private function cached_conditions( $cache, int $id ): ?array {
    if ( !is_array( $cache ) ) {
      return null;
    }
    foreach ( $cache as $location => $entries ) {
      if ( is_array( $entries ) && array_key_exists( $id, $entries ) ) {
        return [
          'location' => (string) $location,
          'conditions' => array_values( array_map( 'strval', (array) $entries[ $id ] ) ),
        ];
      }
    }
    return null;
  }

  /** Order does not matter to Elementor, so it must not matter here either. */
  private function agrees( array $meta, ?array $cached ): bool {
    $there = $cached === null ? [] : $cached['conditions'];
    sort( $meta );
    sort( $there );
    return $meta === $there;
  }

  private function template_row( WP_Post $post, $cache ): array {
    $meta = $this->meta_conditions( $post->ID );
    $cached = $this->cached_conditions( $cache, $post->ID );
    return [
      'id' => $post->ID,
      'title' => $post->post_title,
      'status' => $post->post_status,
      'template_type' => (string) get_post_meta( $post->ID, self::TYPE_META, true ),
      'conditions_on_template' => $meta,
      'in_conditions_cache' => $cached !== null,
      'cached_under_location' => $cached === null ? null : $cached['location'],
      'conditions_in_cache' => $cached === null ? null : $cached['conditions'],
      'agree' => $this->agrees( $meta, $cached ),
    ];
  }

  private function templates( array $query = [] ): array {
    return get_posts( array_merge( [
      'post_type' => 'elementor_library',
      'post_status' => 'any',
      'posts_per_page' => 100,
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
      'suppress_filters' => false,
    ], $query ) );
  }

  #endregion

  #region Conditions

  private function list_templates( array $a, array $r ): array {
    $query = [ 'posts_per_page' => max( 1, min( 100, isset( $a['limit'] ) ? (int) $a['limit'] : 50 ) ) ];
    if ( !empty( $a['type'] ) ) {
      $query['meta_key'] = self::TYPE_META;
      $query['meta_value'] = sanitize_text_field( (string) $a['type'] );
    }

    $cache = $this->cache_value();
    $rows = [];
    foreach ( $this->templates( $query ) as $post ) {
      $rows[] = $this->template_row( $post, $cache );
    }
    return $this->json( $r, [
      'theme_builder_present' => $this->theme_builder_present(),
      'cache_option' => self::CONDITIONS_OPTION,
      'cache' => $this->cache_state( $cache ),
      'templates' => $rows,
    ] );
  }

  private function get_conditions( array $a, array $r ): array {
    $cache = $this->cache_value();

    if ( !empty( $a['ID'] ) ) {
      $post = get_post( (int) $a['ID'] );
      if ( !$post || $post->post_type !== 'elementor_library' ) {
        return $this->error( $r, 'Post #' . (int) $a['ID'] . ' is not an Elementor library template, so it has no theme-builder conditions.' );
      }
      $rows = [ $this->template_row( $post, $cache ) ];
    }
    else {
      $rows = [];
      foreach ( $this->templates() as $post ) {
        $rows[] = $this->template_row( $post, $cache );
      }
    }

    $disagree = array_values( array_map(
      function ( $row ) { return $row['id']; },
      array_filter( $rows, function ( $row ) { return !$row['agree']; } )
    ) );

    $summary = $disagree === []
      ? 'Every template checked has the same conditions on the template and in the cache.'
      : 'The template and the cache disagree for ' . count( $disagree ) . ' template' . ( count( $disagree ) === 1 ? '' : 's' ) . ' (' . implode( ', ', array_map( function ( $id ) { return '#' . $id; }, $disagree ) ) . '). Elementor applies what the cache says, so those are configured but not live. elementor_set_conditions writes both halves together.';

    return $this->json( $r, [
      'theme_builder_present' => $this->theme_builder_present(),
      'theme_builder_note' => $this->theme_builder_present()
        ? null
        : 'The theme builder is not installed, so nothing on this site reads these conditions at all. They take effect only with Elementor Pro or PRO Elements.',
      'cache_option' => self::CONDITIONS_OPTION,
      'cache' => $this->cache_state( $cache ),
      'summary' => $summary,
      'templates' => $rows,
    ] );
  }

  /** Accept the array the schema asks for, a JSON-encoded array, or one bare condition. */
  private function read_conditions_arg( $value ): ?array {
    if ( is_string( $value ) ) {
      $trimmed = trim( $value );
      $decoded = ( $trimmed !== '' && $trimmed[0] === '[' ) ? json_decode( $trimmed, true ) : null;
      $value = is_array( $decoded ) ? $decoded : ( $trimmed === '' ? [] : [ $trimmed ] );
    }
    if ( !is_array( $value ) ) {
      return null;
    }
    $out = [];
    foreach ( $value as $condition ) {
      if ( !is_string( $condition ) ) {
        return null;
      }
      $condition = trim( $condition );
      if ( $condition !== '' ) {
        $out[] = $condition;
      }
    }
    return $out;
  }

  private function set_conditions( array $a, array $r ): array {
    $id = isset( $a['ID'] ) ? (int) $a['ID'] : 0;
    $post = $id > 0 ? get_post( $id ) : null;
    if ( !$post ) {
      return $this->error( $r, 'No post #' . $id . ' exists.' );
    }
    if ( $post->post_type !== 'elementor_library' ) {
      return $this->error( $r, 'Post #' . $id . ' is a ' . $post->post_type . ', not an Elementor library template. Theme-builder conditions only apply to elementor_library posts.' );
    }

    $conditions = $this->read_conditions_arg( $a['conditions'] ?? null );
    if ( $conditions === null ) {
      return $this->error( $r, 'conditions must be an array of strings, for example ["include/general"].' );
    }
    foreach ( $conditions as $condition ) {
      if ( strpos( $condition, 'include/' ) !== 0 && strpos( $condition, 'exclude/' ) !== 0 ) {
        return $this->error( $r, 'Condition "' . $condition . '" is not one Elementor understands: a condition starts with include/ or exclude/, for example include/general for the entire site or include/singular/page for every page.' );
      }
    }

    $type = (string) get_post_meta( $id, self::TYPE_META, true );
    $location = isset( $a['location'] ) ? sanitize_key( (string) $a['location'] ) : ( self::LOCATIONS[ $type ] ?? '' );
    if ( $location === '' ) {
      return $this->error( $r, 'Template #' . $id . ' has type "' . ( $type === '' ? 'unset' : $type ) . '", which is not one of the theme-builder types that take conditions (' . implode( ', ', array_keys( self::LOCATIONS ) ) . '). Pass location explicitly if this template really does belong to header, footer, single or archive.' );
    }

    $before = $this->cache_value();
    update_post_meta( $id, self::CONDITIONS_META, $conditions );
    $refresh = $this->refresh_cache();
    $after = $this->cache_value();

    $out = [
      'template' => [
        'id' => $id,
        'title' => $post->post_title,
        'template_type' => $type,
        'location' => $location,
        'conditions' => $conditions,
      ],
      'cache' => [
        'option' => self::CONDITIONS_OPTION,
        'action' => $refresh['action'],
        'note' => $refresh['note'],
        'state_before' => $this->cache_state( $before ),
        'state_now' => $this->cache_state( $after ),
        'this_template_now' => $this->cached_conditions( $after, $id ),
      ],
      'theme_builder_present' => $this->theme_builder_present(),
    ];

    // What was in the option before it was deleted, because deleted_option records the
    // deletion but not the value, so the change journal cannot put this back.
    if ( $refresh['action'] === 'deleted' && is_array( $before ) && $before !== [] ) {
      $out['cache']['value_before_delete'] = $before;
    }

    $out['next'] = !$this->theme_builder_present()
      ? 'The conditions are stored, but the theme builder is not installed on this site, so nothing reads them and the front end will not change. Elementor Pro or PRO Elements provides it.'
      : ( $refresh['action'] === 'deleted'
        ? 'Load any front-end page once so Elementor rebuilds the cache from the templates, then call elementor_get_conditions to confirm the template now appears in it.'
        : 'The cache was rebuilt in place; elementor_get_conditions confirms what it now says.' );

    return $this->json( $r, $out );
  }

  /**
  * Make the cached registry agree with the templates again.
  *
  * Going through Elementor Pro's own conditions manager is preferred because it is the same
  * call the editor makes, so it stays right if Elementor changes what the cache looks like.
  * When it is not reachable, deleting is the correct fallback rather than writing a shape of
  * our own: Elementor rebuilds the cache from the templates whenever the stored value is not
  * an array, and the templates are the source of truth. It is also precisely why an empty
  * array cannot be left in place, since that counts as an array and blocks the rebuild.
  *
  * Elementor's class layout is not a stable contract, so each step is checked and a throw
  * from inside it falls back to the delete rather than taking the request down.
  */
  private function refresh_cache(): array {
    if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) && method_exists( '\ElementorPro\Modules\ThemeBuilder\Module', 'instance' ) ) {
      try {
        $module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
        $manager = is_object( $module ) && method_exists( $module, 'get_conditions_manager' ) ? $module->get_conditions_manager() : null;
        $cache = is_object( $manager ) && method_exists( $manager, 'get_conditions_cache' ) ? $manager->get_conditions_cache() : null;
        if ( is_object( $cache ) && method_exists( $cache, 'regenerate' ) ) {
          $cache->regenerate();
          return [
            'action' => 'regenerated',
            'note' => 'Rebuilt through Elementor Pro\'s own conditions manager, which is the call the editor makes when conditions are saved there.',
          ];
        }
      }
      catch ( \Throwable $e ) {
        // Fall through to the delete, which needs nothing from Elementor to be correct.
      }
    }

    delete_option( self::CONDITIONS_OPTION );
    return [
      'action' => 'deleted',
      'note' => 'Elementor Pro\'s conditions manager was not reachable, so the cached option was deleted instead and Elementor will rebuild it from the templates on the next read. Any entry another Elementor feature keeps in that option, such as floating buttons, went with it.',
    ];
  }

  #endregion

  #region CSS

  private function regenerate_css( array $a, array $r ): array {
    if ( !class_exists( '\Elementor\Plugin' ) ) {
      return $this->error( $r, 'The Elementor\\Plugin class is not available, so the generated files cannot be cleared.' );
    }
    $plugin = \Elementor\Plugin::$instance;
    if ( !is_object( $plugin ) || !isset( $plugin->files_manager ) || !method_exists( $plugin->files_manager, 'clear_cache' ) ) {
      return $this->error( $r, 'This Elementor version does not expose files_manager->clear_cache(), which is what "Regenerate CSS & Data" calls, so nothing was cleared. Use the Tools screen in wp-admin instead.' );
    }

    try {
      $plugin->files_manager->clear_cache();
    }
    catch ( \Throwable $e ) {
      return $this->error( $r, 'Elementor threw while clearing its generated files: ' . $e->getMessage() );
    }

    return $this->text( $r, 'Elementor\'s generated CSS and data files were cleared and will be rebuilt on the next page load. A full-page cache in front of WordPress is separate and was not touched, so purge that too if visitors are still served old pages.' );
  }

  /**
  * Drop one page's generated stylesheet, so it is rebuilt from whatever the page now holds.
  *
  * @return string|null A note for the caller when Elementor did not offer the class, else null.
  */
  private function clear_post_css( int $post_id ): ?string {
    if ( !class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
      return 'Elementor\'s per-post CSS class was not available, so the page\'s generated stylesheet was left as it was. Run elementor_regenerate_css if the page renders with the wrong styles.';
    }
    try {
      $css = new \Elementor\Core\Files\CSS\Post( $post_id );
      if ( !method_exists( $css, 'delete' ) ) {
        return 'Elementor\'s per-post CSS class has no delete() in this version, so the page\'s generated stylesheet was left as it was. elementor_regenerate_css clears all of them.';
      }
      $css->delete();
    }
    catch ( \Throwable $e ) {
      return 'Elementor threw while clearing this page\'s generated stylesheet (' . $e->getMessage() . '), so it was left as it was.';
    }
    return null;
  }

  #endregion

  #region Applying a template

  /**
  * Copy one meta value from the template to the page, inside PHP.
  *
  * wp_slash() here is not decoration. update_metadata() unslashes whatever it is handed, and
  * _elementor_data is a JSON string full of escapes: every \" and \/ in it would be eaten and
  * the page would come back as a document Elementor cannot parse. Elementor's own save slashes
  * it for the same reason. Arrays go the same way, since wp_slash() walks them, which is why
  * this does not serialize anything itself the way update_post_meta() already does.
  *
  * @return bool Whether there was anything to copy.
  */
  private function copy_meta( int $from, int $to, string $key ): bool {
    $value = get_post_meta( $from, $key, true );
    if ( $value === '' || $value === null || $value === false ) {
      return false;
    }
    update_post_meta( $to, $key, wp_slash( $value ) );
    return true;
  }

  /** An empty document counts as no document: Elementor stores "[]" for a page never built. */
  private function has_elementor_data( int $post_id ): bool {
    $data = get_post_meta( $post_id, self::DATA_META, true );
    if ( is_string( $data ) ) {
      $data = trim( $data );
      return $data !== '' && $data !== '[]';
    }
    return !empty( $data );
  }

  private function apply_template( array $a, array $r ): array {
    $page_id = isset( $a['page_id'] ) ? (int) $a['page_id'] : 0;
    $template_id = isset( $a['template_id'] ) ? (int) $a['template_id'] : 0;

    $page = $page_id > 0 ? get_post( $page_id ) : null;
    if ( !$page ) {
      return $this->error( $r, 'No post #' . $page_id . ' exists to apply the template to.' );
    }
    $template = $template_id > 0 ? get_post( $template_id ) : null;
    if ( !$template || $template->post_type !== 'elementor_library' ) {
      return $this->error( $r, 'template_id must be an Elementor library template. Post #' . $template_id . ' is ' . ( $template ? 'a ' . $template->post_type : 'not a post that exists' ) . '.' );
    }

    $mode = isset( $a['mode'] ) ? sanitize_key( (string) $a['mode'] ) : 'shortcode';
    if ( !in_array( $mode, [ 'shortcode', 'copy' ], true ) ) {
      return $this->error( $r, 'mode must be shortcode or copy.' );
    }

    $page_template = null;
    if ( isset( $a['page_template'] ) && $a['page_template'] !== '' ) {
      $page_template = (string) $a['page_template'];
      if ( !in_array( $page_template, [ 'elementor_header_footer', 'elementor_canvas', 'default' ], true ) ) {
        return $this->error( $r, 'page_template must be elementor_header_footer (no page title or width constraint, theme header and footer still render), elementor_canvas (neither) or default (the theme decides).' );
      }
    }

    $overwrite = !empty( $a['overwrite'] );
    $had_data = $this->has_elementor_data( $page_id );
    if ( $had_data && !$overwrite ) {
      return $this->error( $r, '"' . $page->post_title . '" (#' . $page_id . ') is already built with Elementor. Applying a template would replace that design, and post meta is not recorded in the change journal, so it could not be put back. Pass overwrite: true if that is what you want.' );
    }

    $notes = [];

    if ( $mode === 'shortcode' ) {
      if ( !shortcode_exists( 'elementor-template' ) ) {
        return $this->error( $r, 'The [elementor-template] shortcode is not registered on this site. It comes with Elementor Pro or PRO Elements; the free Elementor plugin does not provide it, and the page would show the shortcode as literal text. Use mode "copy" instead, which duplicates the template onto the page and needs nothing extra.' );
      }

      // Elementor renders a page's own document instead of its content, so leaving the old
      // data in place would mean the shortcode never ran and the page looked unchanged.
      if ( $had_data ) {
        delete_post_meta( $page_id, self::DATA_META );
        delete_post_meta( $page_id, '_elementor_edit_mode' );
        $notes[] = 'The page\'s own Elementor design was deleted, because Elementor renders that in place of the page content and the shortcode would never have run. That deletion is not in the change journal and cannot be undone from here.';
      }

      // Through wp_update_post rather than $wpdb so the change listener and the undo
      // journal both see the content change.
      $done = wp_update_post( [
        'ID' => $page_id,
        'post_content' => '[elementor-template id="' . $template_id . '"]',
      ], true );
      if ( is_wp_error( $done ) ) {
        return $this->error( $r, 'The page content could not be updated: ' . $done->get_error_message() );
      }
      $notes[] = 'The page stays linked to template #' . $template_id . ', so later edits to the template appear here too.';
    }
    else {
      if ( !$this->copy_meta( $template_id, $page_id, self::DATA_META ) ) {
        return $this->error( $r, 'Template #' . $template_id . ' has no Elementor data to copy, so there is nothing to apply.' );
      }
      $this->copy_meta( $template_id, $page_id, '_elementor_page_settings' );
      $this->copy_meta( $template_id, $page_id, '_elementor_version' );
      update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
      update_post_meta( $page_id, self::TYPE_META, $page->post_type === 'page' ? 'wp-page' : 'wp-post' );

      // Only meta changed, which no post hook fires on, so the post cache is dropped here
      // and the change announced on gmcp_mutate the way every other write in this file is.
      clean_post_cache( $page_id );
      $notes[] = 'This is a copy, not a link: later edits to template #' . $template_id . ' will not reach this page.';
    }

    if ( $page_template !== null ) {
      update_post_meta( $page_id, '_wp_page_template', $page_template );
      $notes[] = $page_template === 'elementor_canvas'
        ? 'elementor_canvas renders the page on its own, with no theme header and no theme footer, so a theme-builder header and footer will not appear on it either.'
        : ( $page_template === 'elementor_header_footer'
          ? 'elementor_header_footer drops the page title and the theme\'s content width but still fires the theme header and footer hooks, so a theme-builder header and footer still wrap the page.'
          : 'The theme\'s own page template will render the page, including its title and content width.' );
    }

    $css_note = $this->clear_post_css( $page_id );
    if ( $css_note !== null ) {
      $notes[] = $css_note;
    }

    return $this->json( $r, [
      'page' => [ 'id' => $page_id, 'title' => $page->post_title, 'type' => $page->post_type, 'url' => get_permalink( $page_id ) ],
      'template' => [ 'id' => $template_id, 'title' => $template->post_title, 'template_type' => (string) get_post_meta( $template_id, self::TYPE_META, true ) ],
      'mode' => $mode,
      'page_template' => $page_template,
      'replaced_existing_design' => $had_data,
      'notes' => $notes,
    ] );
  }

  #endregion
}
