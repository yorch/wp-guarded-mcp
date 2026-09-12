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
  * The two ways one post comes to depend on a template, and neither is a foreign key.
  *
  * elementor_apply_template's shortcode mode writes [elementor-template id="N"] into the
  * page content; Elementor's own template widgets store the id under a template_id setting
  * inside _elementor_data. Nothing in WordPress or in Elementor stops the template being
  * deleted or unpublished underneath either of them, and neither failure says anything: the
  * shortcode page prints its own source, the widget page renders a gap.
  */
  const TEMPLATE_SHORTCODE = 'elementor-template';
  const TEMPLATE_ID_SETTINGS = [ 'template_id', 'templateID' ];

  /**
  * Statuses in which a reference is being rendered for somebody right now.
  *
  * Everything else is reported too rather than filtered out. A draft's reference breaks the
  * moment the draft is published, which is after the template is gone and nobody is looking,
  * and a scheduled post is a draft with an alarm clock on it. The status travels with every
  * row so the caller can tell a live page from one that is only waiting.
  */
  const LIVE_STATUSES = [ 'publish', 'private' ];

  /**
  * How many candidate rows a reference search pulls back before it gives up and says so.
  *
  * A cap rather than a page, because the question this feeds is "is anything still pointing
  * at this template", and a truncated yes answers it as well as a complete one does. A
  * truncated no does not, which is why hitting the cap is reported rather than rounded down
  * to an empty list.
  */
  const SCAN_LIMIT = 500;

  /**
  * Where Elementor records which kit is in force, and what a kit is.
  *
  * A kit is an elementor_library post whose template type is "kit"; the option names the one
  * Elementor reads. The two can disagree, and the disagreement is silent: Elementor's kit
  * manager substitutes an empty kit whenever the option names a post that is missing, trashed
  * or not a kit, and an empty kit answers every settings question with Elementor's own
  * built-in defaults. Nothing in the editor says so. That is why the report checks the id it
  * gets back against the id it asked for rather than trusting the object.
  */
  const KIT_OPTION = 'elementor_active_kit';
  const KIT_TYPE = 'kit';
  const KIT_SETTINGS_META = '_elementor_page_settings';

  /**
  * The kit settings that hold the globals, and which of the two groups each one is.
  *
  * System globals are the four Elementor ships and cannot be removed; custom globals are the
  * ones somebody added. Both are repeaters of rows keyed by _id, and a design refers to a row
  * by that _id and never by its label.
  */
  const GLOBAL_COLOURS = [ 'system_colors' => 'system', 'custom_colors' => 'custom' ];
  const GLOBAL_FONTS = [ 'system_typography' => 'system', 'custom_typography' => 'custom' ];

  /**
  * Site-wide kit settings worth naming, and what each one is in plain words.
  *
  * A list rather than a dump of everything, because the kit carries eighty-odd keys on a
  * free install and most of them are one facet of a background control. Every key a caller
  * has actually changed is reported in full regardless of this list, so a setting missing
  * from here is still visible; what this list buys is a name for the ones worth reading.
  *
  * Keys absent from a given Elementor version are reported as not reported rather than as
  * unset, because those are different claims and only one of them is true.
  */
  const KIT_SETTINGS = [
    'site_name' => 'Site name, as Elementor\'s own site-identity widgets print it',
    'site_description' => 'Site description, as Elementor\'s own widgets print it',
    'site_logo' => 'Site logo Elementor\'s own widgets use',
    'site_favicon' => 'Favicon Elementor sets',
    'default_generic_fonts' => 'Fallback family appended after every global font',
    'container_width' => 'Default content width',
    'container_padding' => 'Default padding inside a container',
    'space_between_widgets' => 'Default space between widgets',
    'default_page_template' => 'Page layout a new page is given',
    'page_title_selector' => 'CSS selector Elementor hides when a page hides its title',
    'stretched_section_container' => 'Element a stretched section is stretched to fit',
    'active_breakpoints' => 'Which responsive breakpoints exist on this site',
    'body_background_background' => 'Kind of site-wide page background',
    'body_background_color' => 'Site-wide page background colour',
    'global_image_lightbox' => 'Whether images open in Elementor\'s lightbox',
  ];

  /** Documents pulled into memory at once while counting global usage. */
  const USAGE_PAGE = 25;

  /**
  * Longest a single setting value is reported inline, in bytes of JSON.
  *
  * Anything longer is described rather than printed. A kit can hold custom CSS or an
  * imported breakpoint set of any size, and a report that quietly becomes 200KB is a report
  * nobody can afford to ask for twice.
  */
  const SETTING_VALUE_LIMIT = 400;

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
    'elementor_set_conditions', 'elementor_regenerate_css', 'elementor_set_active_kit', 'elementor_apply_template',
  ];

  /** The three tools that read or delete the conditions option, and so pass the option guard. */
  const TOUCH_OPTION = [
    'elementor_list_templates', 'elementor_get_conditions', 'elementor_set_conditions',
  ];

  /**
  * Tools that read only WordPress's own tables and so still answer with Elementor gone.
  *
  * The reference query asks wp_posts and wp_postmeta what points at a template. Deactivating
  * Elementor does not remove a single one of those rows, and it is exactly the moment the
  * answer is worth having, because every referencing page has just started printing the
  * shortcode as literal text. Refusing here would withhold the diagnosis for the fault.
  */
  const NEEDS_NO_ELEMENTOR = [ 'elementor_template_references' ];

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
      'elementor_set_active_kit' => [
        'name' => 'elementor_set_active_kit',
        'description' => 'Make one Elementor kit the active one. A kit holds the site\'s global colours, fonts, layout defaults and theme-style rules, so most of what "wire up a theme" means lives in it rather than in any page. Elementor records the active one in a single option, which is why this looks small and is not: switching it changes every page at once. Two things come with the switch and both matter. Elementor compiles the kit\'s colours and typography into generated CSS files, so the files are cleared here as well and rebuilt on the next page load; without that the site keeps rendering the old kit\'s design while reporting the new one as active, which is the exact shape of silent failure the conditions tools exist for. And the previous kit is named in the reply, because it is the only record of what to switch back to: this is not journalled and there is no undo for it. Refuses anything that is not a published kit, including an ordinary library template, since Elementor reads the option without checking and an id pointing at the wrong thing leaves the site with no usable global styles at all. List the kits with elementor_list_templates and type "kit".',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'The kit to activate. It must be an elementor_library post whose template type is kit.' ],
          ],
          'required' => [ 'ID' ],
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
      'elementor_template_references' => [
        'name' => 'elementor_template_references',
        'description' => 'Find every post that depends on an Elementor library template, before deleting or unpublishing it. Covers both routes a reference takes: the [elementor-template id="N"] shortcode that elementor_apply_template writes into page content, and a template_id setting inside a page\'s _elementor_data, which is how Elementor\'s own template and loop widgets embed one template in another. Shortcodes are parsed rather than substring-matched, so id="12" is not reported as a reference to template 1 or 123. Drafts, scheduled and trashed posts are included and labelled, because a draft\'s reference breaks when it is published, which is long after the template is gone. Revisions and auto-drafts are excluded. Reads only the posts and postmeta tables, so it still answers when Elementor is deactivated, which is when every referencing page has just started printing the shortcode as text. Reports what it did not search. Changes nothing, and refuses nothing: no tool in this plugin consults this before deleting a template, so this is a question to ask first, not a guard that will stop you.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'template_id' => [ 'type' => 'integer', 'description' => 'The template to look for. An id that is not a template, or no longer a post at all, is searched for anyway: that is how dangling references left behind by a deletion are found.' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Most referring posts to return per route. Default 50, maximum 200. A truncated answer says so.' ],
          ],
          'required' => [ 'template_id' ],
        ],
        'accessLevel' => 'read',
      ],
      'elementor_kit_report' => [
        'name' => 'elementor_kit_report',
        'description' => 'Report Elementor\'s kit: the global colours and fonts the whole site draws from, the site-wide settings the kit holds, and whether the kit Elementor is reading is the one you think it is. Changes nothing, at all, by design: a kit write against an internal shape that moved between Elementor versions corrupts a site\'s global styling everywhere at once, which is not a risk worth an agent taking, so there is no kit write tool here and this is not an omission to be filled in. Reports which kit is active with its ID and title, every global with its label, its value and the globals/... reference a design uses to point at it, and any other kit in the library, since a site with two kits where the inactive one is the edited one is the usual reason a global change appears to do nothing. With include_usage it also counts which designs refer to each global, which is what separates a colour that is safe to change from one that is on every button. Settings whose shape it does not recognise are reported as stored rather than dropped. Works on the free Elementor plugin: the kit is part of Elementor itself and not of the theme builder, unlike this file\'s conditions tools.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'include_usage' => [
              'type' => 'boolean',
              'description' => 'Count which designs refer to each global. Default false, because it reads every Elementor document on the site that uses any global at all, and those documents are routinely over 100KB each. Ask for it when you are about to change or remove a global, not to look around.',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Most referring posts listed per global when include_usage is set. Default 20, maximum 100. Counts are not capped by this; only the listed rows are.',
            ],
          ],
        ],
        'accessLevel' => 'read',
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
    if ( !did_action( 'elementor/loaded' ) && !in_array( $tool, self::NEEDS_NO_ELEMENTOR, true ) ) {
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

    // And the kit report reads elementor_active_kit, which is an option like any other and
    // answers to the same guard for the same reason: a site that has protected that name is
    // not read around by a tool that happens to know a different way in.
    if ( $tool === 'elementor_kit_report' ) {
      $allowed = GMCP_Core::option_guard( self::KIT_OPTION );
      if ( $allowed !== true ) {
        return $this->error( $r, $allowed );
      }
    }

    switch ( $tool ) {
      case 'elementor_list_templates': $r = $this->list_templates( $args, $r ); break;
      case 'elementor_get_conditions': $r = $this->get_conditions( $args, $r ); break;
      case 'elementor_set_conditions': $r = $this->set_conditions( $args, $r ); break;
      case 'elementor_set_active_kit': $r = $this->set_active_kit( $args, $r ); break;
      case 'elementor_regenerate_css': $r = $this->regenerate_css( $args, $r ); break;
      case 'elementor_apply_template': $r = $this->apply_template( $args, $r ); break;
      case 'elementor_template_references': $r = $this->template_references( $args, $r ); break;
      case 'elementor_kit_report': $r = $this->kit_report( $args, $r ); break;
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

  /** Elementor's own name for the option holding the active kit, from its Kits Manager. */
  const ACTIVE_KIT_OPTION = 'elementor_active_kit';

  private function set_active_kit( array $a, array $r ): array {
    $id = (int) ( $a['ID'] ?? 0 );
    if ( $id <= 0 ) {
      return $this->error( $r, 'ID is required and must be the id of a kit.' );
    }
    $post = get_post( $id );
    if ( !$post || $post->post_type !== 'elementor_library' ) {
      return $this->error( $r, "Post {$id} is not an elementor_library template, so it cannot be a kit." );
    }
    // Checked rather than trusted. Elementor reads this option and looks the post up
    // without asking what it is, so an id pointing at an ordinary template leaves the site
    // with no usable global styles and no error saying why.
    $type = (string) get_post_meta( $id, self::TYPE_META, true );
    if ( $type !== 'kit' ) {
      return $this->error(
        $r,
        "Template {$id} is a \"" . ( $type ?: 'template with no type' ) . "\", not a kit. Only a kit holds the global colours, fonts and theme styles this option points at. List them with elementor_list_templates and type \"kit\"."
      );
    }
    if ( $post->post_status !== 'publish' ) {
      return $this->error(
        $r,
        "Kit {$id} is \"{$post->post_status}\", not published. Elementor reads the active kit through the post, and a kit that is not published gives the site no global styles at all."
      );
    }

    $before = (int) get_option( self::ACTIVE_KIT_OPTION );
    if ( $before === $id ) {
      return $this->text( $r, "Kit {$id} \"" . get_the_title( $id ) . "\" was already the active one. Nothing changed." );
    }

    update_option( self::ACTIVE_KIT_OPTION, $id );

    // Asked of the option rather than assumed from the write, for the reason this file
    // gives everywhere else: a tool reporting a switch and a switch happening are
    // different claims, and another plugin filtering the option would make them differ.
    $after = (int) get_option( self::ACTIVE_KIT_OPTION );
    if ( $after !== $id ) {
      return $this->error(
        $r,
        "The option was written but reads back as {$after} rather than {$id}, so something on this site is filtering it. The active kit has NOT been changed."
      );
    }

    // The second half, and the half that gets forgotten. A kit's colours and typography
    // are compiled into generated CSS files; switching the option without clearing them
    // leaves every page rendering the old kit while this tool reports the new one.
    $css = 'not cleared';
    if ( class_exists( '\Elementor\Plugin' ) ) {
      $plugin = \Elementor\Plugin::$instance;
      if ( is_object( $plugin ) && isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
        try {
          $plugin->files_manager->clear_cache();
          $css = 'cleared, and will rebuild on the next page load';
        }
        catch ( \Throwable $e ) {
          $css = 'could not be cleared: ' . $e->getMessage();
        }
      }
    }

    return $this->json( $r, [
      'active_kit' => $id,
      'title' => get_the_title( $id ),
      'previous_kit' => $before ?: null,
      'previous_title' => $before ? get_the_title( $before ) : null,
      'generated_css' => $css,
      // The previous id is the only record of what to go back to, so it is said rather
      // than left to be worked out later.
      'note' => 'This is not journalled and wp_undo_change cannot reverse it. To switch back, call this again with '
        . ( $before ? 'ID ' . $before . '.' : 'the kit that was active before, which this site did not have recorded.' )
        . ( $css === 'cleared, and will rebuild on the next page load'
            ? ' A full-page cache in front of WordPress is separate and was not touched.'
            : ' The generated CSS was NOT cleared, so pages may keep rendering the previous kit until it is: call elementor_regenerate_css.' ),
    ] );
  }

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

      // Setting post_content replaces it whole, so any template the page already pointed at
      // through a shortcode stops being pointed at. That is a reference this tool is about to
      // break, and the page it breaks it on is the one in front of us, so it is named rather
      // than left for the caller to discover from a rendering difference.
      $replaced = self::matching_other_templates( $page->post_content, $template_id );
      if ( $replaced !== [] ) {
        $notes[] = count( $replaced ) === 1
          ? 'The content being replaced already pointed at template #' . $replaced[0] . ' through a shortcode. That link is gone now; only #' . $template_id . ' is left.'
          : 'The content being replaced already pointed at templates #' . implode( ', #', $replaced ) . ' through shortcodes. Those links are gone now; only #' . $template_id . ' is left.';
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

      // The reference this call just created is the one nothing else in the plugin watches.
      // Both warnings are about the same silent failure from opposite ends: a template that
      // cannot render leaves the shortcode printing as text, and a deletion later does the
      // same thing to a page that worked. Saying it here is the only place either is cheap.
      if ( !in_array( $template->post_status, self::LIVE_STATUSES, true ) ) {
        $notes[] = 'Template #' . $template_id . ' is ' . $template->post_status . ', not published, and Elementor renders nothing for a template in that state. Publish it, or this page shows an empty space where the template should be.';
      }
      $notes[] = 'Nothing refuses a later delete or unpublish of template #' . $template_id . ' on account of this page. elementor_template_references is the query that finds what points at a template; ask it before removing one.';
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

  #region References

  /**
  * What still points at a template, by both routes, with two very different costs.
  *
  * The shortcode half has to read post_content, and WordPress indexes nothing in that column:
  * wp_posts carries indexes on post_name, post_parent, post_author and the type/status/date
  * triple, and not one of them narrows a LIKE on the body. So this is a full scan of wp_posts
  * and its cost is the size of the content column rather than the row count. Nothing indexes
  * it, and the fix would be a fulltext index this plugin has no business adding to somebody
  * else's table, so the scan is named rather than hidden. What keeps it survivable is that
  * the pattern is the shortcode name, which almost no row contains.
  *
  * The _elementor_data half looks worse and is far cheaper. wp_postmeta is indexed on
  * meta_key, so naming the key first narrows the LIKE to the posts actually built with
  * Elementor. That pattern also carries the id, which matters here and not above: these rows
  * run past 100KB each and pulling every one of them back to look inside is the expensive
  * mistake available in this query.
  *
  * Both prefilters are deliberately looser than the answer. The precision is added afterwards
  * in PHP, where the shortcode is parsed and the JSON decoded, because matching id="12" as a
  * substring reports template 1 and template 123 as well, and a refusal built on that would
  * block a deletion that was safe.
  */
  private function template_references( array $a, array $r ): array {
    $template_id = isset( $a['template_id'] ) ? (int) $a['template_id'] : 0;
    if ( $template_id <= 0 ) {
      return $this->error( $r, 'template_id must be the positive ID of an Elementor library template.' );
    }
    $limit = max( 1, min( 200, isset( $a['limit'] ) ? (int) $a['limit'] : 50 ) );

    // A missing template, or one that is not a template at all, is searched for rather than
    // refused. An id whose post is gone is exactly the interesting case: the references it
    // left behind are still in the content and still rendering nothing.
    $template = get_post( $template_id );

    $shortcode = self::shortcode_references( $template_id, $limit );
    $data = self::data_references( $template_id, $limit );
    $rows = array_merge( $shortcode['rows'], $data['rows'] );

    $live = 0;
    foreach ( $rows as $row ) {
      if ( $row['live'] ) {
        $live++;
      }
    }
    $truncated = $shortcode['truncated'] || $data['truncated'];

    // Every note here describes something about the rows, so each is held back when there are
    // no rows for it to describe. An empty answer that still warns about what is "below" reads
    // as a partial one, and the whole value of an empty answer is that it is trustworthy.
    $notes = [];
    if ( !$template ) {
      $notes[] = 'There is no post #' . $template_id . ' on this site.'
        . ( $rows === [] ? '' : ' Everything listed is a dangling reference: the shortcode prints as text and the widget renders nothing.' );
    }
    elseif ( $template->post_type !== 'elementor_library' ) {
      $notes[] = 'Post #' . $template_id . ' is a ' . $template->post_type . ', not an Elementor library template.'
        . ( $rows === [] ? ' The search ran anyway.' : ' The search ran anyway, so everything listed points at an id that is not a template.' );
    }
    elseif ( !in_array( $template->post_status, self::LIVE_STATUSES, true ) && $rows !== [] ) {
      $notes[] = 'The template itself is ' . $template->post_status . ', not published. Elementor renders nothing for a template in that state, so every reference listed is already broken.';
    }

    if ( !did_action( 'elementor/loaded' ) ) {
      $notes[] = 'Elementor is not loaded on this site. These rows come from the posts and postmeta tables, which Elementor\'s absence does not change, so the list is complete,'
        . ( $rows === [] ? '' : ' but every page listed is currently rendering its raw shortcode or an empty gap.' );
    }
    elseif ( $shortcode['rows'] !== [] && !shortcode_exists( self::TEMPLATE_SHORTCODE ) ) {
      $notes[] = 'The [' . self::TEMPLATE_SHORTCODE . '] shortcode is not registered on this site: it comes with Elementor Pro or PRO Elements, not with the free plugin. Every shortcode reference listed is printing as literal text on the front end right now, whatever state the template is in.';
    }

    if ( $truncated ) {
      $notes[] = 'More than ' . self::SCAN_LIMIT . ' candidate rows matched, so this list is incomplete. Treat it as "at least these", never as "only these".';
    }

    $summary = $rows === []
      ? 'Nothing found that references template #' . $template_id . ' by either route.'
      : count( $rows ) . ' reference' . ( count( $rows ) === 1 ? '' : 's' ) . ' to template #' . $template_id . ', ' . $live . ' of them on a published or private post. Deleting or unpublishing this template breaks every one of them, and no tool in this plugin will stop you: wp_delete_post and wp_update_post do not consult this query.';

    return $this->json( $r, [
      'template' => [
        'id' => $template_id,
        'exists' => (bool) $template,
        'title' => $template ? $template->post_title : null,
        'post_type' => $template ? $template->post_type : null,
        'status' => $template ? $template->post_status : null,
        'template_type' => $template ? (string) get_post_meta( $template_id, self::TYPE_META, true ) : null,
      ],
      'elementor_loaded' => (bool) did_action( 'elementor/loaded' ),
      'shortcode_registered' => shortcode_exists( self::TEMPLATE_SHORTCODE ),
      'counts' => [
        'total' => count( $rows ),
        'live' => $live,
        'shortcode' => count( $shortcode['rows'] ),
        'elementor_data' => count( $data['rows'] ),
      ],
      'notes' => $notes,
      'references' => $rows,
      'truncated' => $truncated,
      'searched' => [
        'shortcode' => 'Every post except revisions and auto-drafts, matched on the literal "[' . self::TEMPLATE_SHORTCODE . '" and then parsed with shortcode_parse_atts. post_content carries no index, so this is a full scan of the posts table.',
        'elementor_data' => 'Only posts holding an ' . self::DATA_META . ' row that already names this id, found through the meta_key index and then JSON-decoded to confirm the id is a widget setting rather than a coincidence in the text.',
        'candidates_examined' => [ 'shortcode' => $shortcode['candidates'], 'elementor_data' => $data['candidates'] ],
      ],
      'not_searched' => [
        'Post revisions and auto-drafts, which are copies nobody renders.',
        'Options, so a template embedded through a widget, a theme option or the site editor is not found.',
        'Theme and plugin files, so a do_shortcode() or a hard-coded template id in PHP is not found.',
        'Other sites in a multisite network: this reads the current site\'s tables only.',
        'Any settings key other than ' . implode( ' and ', self::TEMPLATE_ID_SETTINGS ) . ', so a plugin storing a template id under its own name is not found.',
      ],
      'summary' => $summary,
    ] );
  }

  /** Posts whose content holds [elementor-template id="N"], parsed rather than matched. */
  public static function shortcode_references( int $template_id, int $limit ): array {
    global $wpdb;

    $like = '%' . $wpdb->esc_like( '[' . self::TEMPLATE_SHORTCODE ) . '%';
    $candidates = $wpdb->get_results( $wpdb->prepare(
      "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts}
        WHERE post_content LIKE %s AND post_type != 'revision' AND post_status != 'auto-draft'
        ORDER BY ID ASC LIMIT %d",
      $like,
      self::SCAN_LIMIT + 1
    ) );
    $candidates = is_array( $candidates ) ? $candidates : [];
    $truncated = count( $candidates ) > self::SCAN_LIMIT;
    $candidates = array_slice( $candidates, 0, self::SCAN_LIMIT );

    $rows = [];
    foreach ( $candidates as $candidate ) {
      foreach ( self::matching_shortcodes( (string) $candidate->post_content, $template_id ) as $found ) {
        if ( count( $rows ) >= $limit ) {
          return [ 'rows' => $rows, 'candidates' => count( $candidates ), 'truncated' => true ];
        }
        $rows[] = self::reference_row( $candidate, 'shortcode', 'Post content holds ' . $found . '.' );
      }
    }
    return [ 'rows' => $rows, 'candidates' => count( $candidates ), 'truncated' => $truncated ];
  }

  /**
  * Every template the shortcodes in one body name, as id => the shortcode that named it.
  *
  * shortcode_parse_atts rather than a pattern over the id, because the id can be written
  * id="45", id='45' or id=45 and a pattern that covers all three either misses a form or
  * matches 456. Parsing settles that, and settles attribute order and extra attributes with
  * it. An id that is not a number parses to 0, which is no template, so it is dropped.
  *
  * The lookahead after the name is what stops [elementor-template-something] being read as
  * this shortcode. \b would not: the hyphen is already a word boundary.
  */
  private static function templates_in_shortcodes( string $content ): array {
    if ( strpos( $content, '[' . self::TEMPLATE_SHORTCODE ) === false ) {
      return [];
    }
    $pattern = '/\[' . preg_quote( self::TEMPLATE_SHORTCODE, '/' ) . '(?=[\s\/\]])([^\]]*)\]/';
    if ( !preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
      return [];
    }

    $found = [];
    foreach ( $matches as $match ) {
      $atts = shortcode_parse_atts( $match[1] );
      if ( !is_array( $atts ) || !isset( $atts['id'] ) ) {
        continue;
      }
      $id = (int) trim( (string) $atts['id'] );
      if ( $id > 0 && !isset( $found[ $id ] ) ) {
        $found[ $id ] = self::snippet( $match[0] );
      }
    }
    return $found;
  }

  /** The shortcodes in one body that really do name this template. */
  private static function matching_shortcodes( string $content, int $template_id ): array {
    $found = self::templates_in_shortcodes( $content );
    return isset( $found[ $template_id ] ) ? [ $found[ $template_id ] ] : [];
  }

  /** Template ids a body's shortcodes name, other than the one about to replace them. */
  private static function matching_other_templates( string $content, int $except ): array {
    $ids = array_keys( self::templates_in_shortcodes( $content ) );
    return array_values( array_diff( $ids, [ $except ] ) );
  }

  /** Posts whose _elementor_data embeds this template through a widget's template_id. */
  public static function data_references( int $template_id, int $limit ): array {
    global $wpdb;

    // Quoted and bare forms of each settings key. The bare one is a prefix match and will
    // also pull 456 back for 45; the decode below is what removes it.
    $likes = [];
    foreach ( self::TEMPLATE_ID_SETTINGS as $setting ) {
      $likes[] = '%' . $wpdb->esc_like( '"' . $setting . '":"' . $template_id . '"' ) . '%';
      $likes[] = '%' . $wpdb->esc_like( '"' . $setting . '":' . $template_id ) . '%';
    }
    $where = implode( ' OR ', array_fill( 0, count( $likes ), 'm.meta_value LIKE %s' ) );

    $candidates = $wpdb->get_results( $wpdb->prepare(
      "SELECT p.ID, p.post_title, p.post_type, p.post_status, m.meta_value
        FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
        WHERE m.meta_key = %s AND ( {$where} )
          AND p.post_type != 'revision' AND p.post_status != 'auto-draft'
        ORDER BY p.ID ASC LIMIT %d",
      array_merge( [ self::DATA_META ], $likes, [ self::SCAN_LIMIT + 1 ] )
    ) );
    $candidates = is_array( $candidates ) ? $candidates : [];
    $truncated = count( $candidates ) > self::SCAN_LIMIT;
    $candidates = array_slice( $candidates, 0, self::SCAN_LIMIT );

    $rows = [];
    foreach ( $candidates as $candidate ) {
      $raw = (string) $candidate->meta_value;
      $found = self::data_widget_hits( $raw, $template_id );
      $hits = $found['hits'];

      // Three different reasons the decode found nothing, and only one of them is a no.
      //
      // The bare-integer prefilter above is a prefix match, so template 113 pulls back every
      // document naming 1136 as well. A clean decode that finds nothing and no exact literal
      // anywhere is that case, and it is a real negative: reporting it would be the false
      // match this whole design exists to avoid, and it would block a safe deletion.
      //
      // The other two are reported rather than dropped. A document that will not parse cannot
      // be ruled out, and an exact literal sitting somewhere the walk does not look is very
      // likely a reference through something newer than this code. Silence on either is the
      // one outcome the caller has no way back from.
      if ( $hits === [] ) {
        if ( !$found['parsed'] ) {
          $hits = [ 'the document could not be parsed, so whether it references this template was not settled either way' ];
        }
        elseif ( self::names_id_exactly( $raw, $template_id ) ) {
          $hits = [ 'the document names this id exactly, but not under a settings key this recognises, so it may be a reference through a widget this does not know' ];
        }
        else {
          continue;
        }
      }
      foreach ( $hits as $hit ) {
        if ( count( $rows ) >= $limit ) {
          return [ 'rows' => $rows, 'candidates' => count( $candidates ), 'truncated' => true ];
        }
        $rows[] = self::reference_row( $candidate, 'elementor_data', ucfirst( $hit ) . '.' );
      }
    }
    return [ 'rows' => $rows, 'candidates' => count( $candidates ), 'truncated' => $truncated ];
  }

  /**
  * Where in one Elementor document this template id is used as a widget setting.
  *
  * Whether the document parsed at all travels back with the answer, because an empty list
  * from a document that parsed and an empty list from one that did not are opposite results.
  */
  private static function data_widget_hits( string $json, int $template_id ): array {
    $document = json_decode( $json, true );
    if ( !is_array( $document ) ) {
      return [ 'parsed' => false, 'hits' => [] ];
    }
    $hits = [];
    self::walk_for_template( $document, $template_id, $hits );
    return [ 'parsed' => true, 'hits' => array_values( array_unique( $hits ) ) ];
  }

  /**
  * Whether the raw document contains this id as a complete settings value, not a prefix of one.
  *
  * The delimiters are what make it exact. Elementor writes its documents with json_encode,
  * which puts no space after a colon, so a bare integer value is always followed by a comma
  * or a closing brace and "template_id":113 cannot be read out of "template_id":1136.
  */
  private static function names_id_exactly( string $json, int $template_id ): bool {
    foreach ( self::TEMPLATE_ID_SETTINGS as $setting ) {
      foreach ( [ '"' . $template_id . '"', $template_id . ',', $template_id . '}' ] as $value ) {
        if ( strpos( $json, '"' . $setting . '":' . $value ) !== false ) {
          return true;
        }
      }
    }
    return false;
  }

  /**
  * Walk an Elementor document looking for a settings key that names this template.
  *
  * Elementor nests sections inside containers inside columns to whatever depth the page was
  * built to, and the widget that carries the reference can sit at any of them, so the walk
  * has to be general. Only a value directly under "settings" counts: the same integer in a
  * heading's text is not a reference, and treating it as one is how this would start
  * refusing safe deletions.
  */
  private static function walk_for_template( array $node, int $template_id, array &$hits ): void {
    if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
      $widget = '';
      foreach ( [ 'widgetType', 'elType' ] as $key ) {
        if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && $node[ $key ] !== '' ) {
          $widget = $node[ $key ];
          break;
        }
      }
      foreach ( self::TEMPLATE_ID_SETTINGS as $setting ) {
        if ( !isset( $node['settings'][ $setting ] ) ) {
          continue;
        }
        $value = $node['settings'][ $setting ];
        if ( ( is_string( $value ) || is_int( $value ) ) && (int) $value === $template_id ) {
          $hits[] = 'the ' . ( $widget === '' ? 'element' : '"' . self::snippet( $widget ) . '" widget' ) . ' embeds it through its ' . $setting . ' setting';
        }
      }
    }

    foreach ( $node as $key => $child ) {
      if ( $key !== 'settings' && is_array( $child ) ) {
        self::walk_for_template( $child, $template_id, $hits );
      }
    }
  }

  /** One referring post, with enough of its state to tell a live page from a waiting one. */
  private static function reference_row( $post, string $via, string $detail ): array {
    return [
      'id' => (int) $post->ID,
      'title' => (string) $post->post_title,
      'post_type' => (string) $post->post_type,
      'status' => (string) $post->post_status,
      'live' => in_array( (string) $post->post_status, self::LIVE_STATUSES, true ),
      'url' => get_permalink( (int) $post->ID ),
      'via' => $via,
      'detail' => $detail,
    ];
  }

  /** Somebody else wrote this text, so it is trimmed before it goes back out in a sentence. */
  private static function snippet( string $text, int $length = 120 ): string {
    $text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
    return strlen( $text ) > $length ? substr( $text, 0, $length ) . '...' : $text;
  }

  #endregion

  #region Kit

  /**
  * What Elementor's kit says the site's globals and site-wide settings are.
  *
  * Two sources, because neither alone tells the truth.
  *
  * Elementor's own kit document is authoritative for what is in force: it merges the kit's
  * saved settings over its controls' defaults, so on a site nobody has touched it still
  * reports the four system colours the editor shows, which the stored meta does not contain
  * at all. Reading only the meta would report a fresh site as having no globals.
  *
  * The stored meta is authoritative for what somebody chose. A value in the merged view may
  * be a default nobody has ever seen, and "this colour is Elementor's #6EC1E4 because nobody
  * changed it" and "this colour is #6EC1E4 because somebody picked it" are different facts
  * when the question is whether changing it is safe.
  *
  * Between them there is still a gap, and it is named in the report rather than papered over.
  * Elementor does not register the same controls on every request. Measured on Elementor
  * 4.2.4 against one site: 403 kit controls through the REST endpoint this tool answers on,
  * 146 through WP-CLI, with container_width and space_between_widgets among the ones the
  * smaller set lacks. A saved value still comes through either way, because the merge puts
  * it there, but a setting nobody has changed and whose control is not registered is absent
  * from the merge entirely and its shipped default cannot be read at all. The report says
  * "not reported" for those rather than "not set": the first is true and the second would be
  * a false negative about a setting that is live on the front end.
  */
  private function kit_report( array $a, array $r ): array {
    $option = get_option( self::KIT_OPTION, null );
    $active_id = is_scalar( $option ) ? (int) $option : 0;
    $post = $active_id > 0 ? get_post( $active_id ) : null;
    $kits = $this->kit_posts();
    $state = $this->kit_state( $option, $post, $kits );

    $live = $this->kit_settings_from_elementor( $active_id );
    $saved = get_post_meta( $active_id > 0 ? $active_id : 0, self::KIT_SETTINGS_META, true );
    $saved = is_array( $saved ) ? $saved : [];

    $colours = [];
    foreach ( self::GLOBAL_COLOURS as $key => $group ) {
      $colours = array_merge( $colours, $this->global_rows( $live['settings'], $saved, $key, $group, 'colors' ) );
    }
    $fonts = [];
    foreach ( self::GLOBAL_FONTS as $key => $group ) {
      $fonts = array_merge( $fonts, $this->global_rows( $live['settings'], $saved, $key, $group, 'typography' ) );
    }

    $usage = null;
    if ( !empty( $a['include_usage'] ) ) {
      $limit = max( 1, min( 100, isset( $a['limit'] ) ? (int) $a['limit'] : 20 ) );
      $usage = $this->globals_usage( array_merge( $colours, $fonts ), $limit );
      $colours = $this->attach_usage( $colours, $usage );
      $fonts = $this->attach_usage( $fonts, $usage );
    }

    return $this->json( $r, [
      'kit' => [
        'option' => self::KIT_OPTION,
        'active_id' => $active_id > 0 ? $active_id : null,
        'title' => $post ? $post->post_title : null,
        'status' => $post ? $post->post_status : null,
        'template_type' => $post ? (string) get_post_meta( $post->ID, self::TYPE_META, true ) : null,
        'state' => $state['state'],
        'meaning' => $state['meaning'],
      ],
      'read_through_elementor' => $live['available'],
      'read_note' => $live['reason'],
      'theme_builder_note' => 'The kit is part of Elementor itself, not of the theme builder, so everything below is live on a site with only the free plugin. That is the opposite of this file\'s conditions tools, which store their values happily and are read by nothing without Elementor Pro or PRO Elements. Pro adds kit settings of its own, and on a site without it those are absent below rather than reported as empty.'
          . ( $this->theme_builder_present() ? ' The theme builder is present on this site.' : ' The theme builder is not present on this site.' ),
      'globals' => [
        'colors' => $colours,
        'fonts' => $fonts,
      ],
      'settings' => $this->setting_rows( $live['settings'], $saved ),
      'saved_on_the_kit' => $this->saved_rows( $saved ),
      'saved_note' => $saved === []
        ? 'The kit has no stored settings at all, so every value above is a default Elementor ships rather than a choice anybody made here.'
        : 'Every key the kit has explicitly stored, reported as stored whether or not anything here recognises its shape. The four globals keys are left out because they are reported in full above.',
      'other_kits' => $this->other_kit_rows( $kits, $active_id ),
      'usage' => $usage === null ? null : [
        'documents_examined' => $usage['documents_examined'],
        'truncated' => $usage['truncated'],
        'method' => 'Every post holding an ' . self::DATA_META . ' row that mentions a global at all, matched on the reference string inside the stored document rather than by decoding it. Decoding is what elementor_template_references does and is what makes that tool exact; here the documents are routinely over 100KB each and there is one pass over all of them, so the exactness traded away is this: a design that merely quotes a reference string in its own text counts as using it. The closing quote in the needle is what stops a global named "brand" being counted for every use of "brand-dark".',
        'not_searched' => [
          'Post revisions and auto-drafts.',
          'Anything outside ' . self::DATA_META . ', so a global used by a theme, a plugin or a template stored in an option is not counted.',
          'Other sites in a multisite network.',
        ],
      ],
      'summary' => $this->kit_summary( $state, $colours, $fonts, $usage ),
    ] );
  }

  /**
  * Every post that is a kit, active or not, trash included.
  *
  * Queried by the template-type meta rather than asked of Elementor, because Elementor's kit
  * manager will not hand back a trashed kit and a trashed kit is exactly the one worth
  * seeing: it is still named by the option, and a site in that state renders from Elementor's
  * built-in defaults while the library still shows the kit somebody was editing.
  *
  * post_status has to be spelled out. WP_Query's "any" is not any: it excludes trash.
  */
  private function kit_posts(): array {
    return $this->templates( [
      'post_status' => [ 'publish', 'private', 'draft', 'pending', 'future', 'trash' ],
      'posts_per_page' => 50,
      'meta_key' => self::TYPE_META,
      'meta_value' => self::KIT_TYPE,
    ] );
  }

  /** Which of the six states the site is in, and what it means for what renders. */
  private function kit_state( $option, $post, array $kits ): array {
    if ( $option === null || $option === '' || (int) $option <= 0 ) {
      return $kits === []
        ? [
          'state' => 'no kit',
          'meaning' => 'Elementor has not created a kit on this site yet, and there are no global colours or fonts to report. It creates one when the site settings are first opened in the editor. This is not an error and nothing is broken; the site simply renders with the defaults Elementor ships.',
        ]
        : [
          'state' => 'no active kit',
          'meaning' => 'There ' . ( count( $kits ) === 1 ? 'is a kit' : 'are at least ' . count( $kits ) . ' kits' ) . ' in the library but the ' . self::KIT_OPTION . ' option names none of them, so Elementor is reading none of them. Whatever is stored on those kits has no effect until one is made active.',
        ];
    }
    if ( !$post ) {
      return [
        'state' => 'active kit missing',
        'meaning' => 'The ' . self::KIT_OPTION . ' option names post #' . (int) $option . ', which does not exist on this site. Elementor substitutes an empty kit without saying so, which means every global is silently its built-in default right now, whatever the site settings panel last showed.',
      ];
    }
    if ( $post->post_status === 'trash' ) {
      return [
        'state' => 'active kit trashed',
        'meaning' => 'The active kit, post #' . $post->ID . ', is in the trash. Elementor refuses a trashed kit and substitutes an empty one without saying so, so every global is its built-in default right now even though the kit and its settings are still there.',
      ];
    }
    if ( $post->post_type !== 'elementor_library' || (string) get_post_meta( $post->ID, self::TYPE_META, true ) !== self::KIT_TYPE ) {
      return [
        'state' => 'active kit is not a kit',
        'meaning' => 'The ' . self::KIT_OPTION . ' option names post #' . $post->ID . ', which is a ' . $post->post_type . ' and not a kit. Elementor substitutes an empty kit, so every global is its built-in default right now.',
      ];
    }
    return [
      'state' => 'active',
      'meaning' => 'Post #' . $post->ID . ' is the kit Elementor reads, and the globals below are what the site is drawing from.',
    ];
  }

  /**
  * Elementor's merged view of the kit, or a sentence saying why there is not one.
  *
  * Every step is guarded and every failure names what was missing, because a version that
  * moves any of this should cost the caller a report rather than the request. The identity
  * check at the end is the one that matters: the kit manager answers a bad id with an empty
  * kit rather than with nothing, and an empty kit reports Elementor's built-in defaults as
  * confidently as a real one. Returning those as the site's globals would be the false yes.
  */
  private function kit_settings_from_elementor( int $kit_id ): array {
    $absent = function ( string $reason ) {
      return [ 'available' => false, 'reason' => $reason, 'settings' => [], 'controls' => 0 ];
    };

    if ( $kit_id <= 0 ) {
      return $absent( 'There is no active kit to read, so the globals below come from the kit\'s stored settings alone and every default Elementor would have supplied is missing from them.' );
    }
    if ( !class_exists( '\Elementor\Plugin' ) || !isset( \Elementor\Plugin::$instance ) || !isset( \Elementor\Plugin::$instance->kits_manager ) ) {
      return $absent( 'Elementor\'s kit manager is not available in this version, so nothing here could ask Elementor what the kit holds. The globals below come from the kit\'s stored settings alone, which contain only what somebody changed.' );
    }

    $manager = \Elementor\Plugin::$instance->kits_manager;
    if ( !method_exists( $manager, 'get_kit' ) ) {
      return $absent( 'Elementor\'s kit manager has no get_kit method in this version, so nothing here could ask it for the kit. The globals below come from the kit\'s stored settings alone.' );
    }

    try {
      $kit = $manager->get_kit( $kit_id );
    }
    catch ( \Throwable $e ) {
      return $absent( 'Elementor threw reading the kit: ' . $this->snippet( $e->getMessage() ) . '. The globals below come from the kit\'s stored settings alone.' );
    }

    if ( !is_object( $kit ) || !method_exists( $kit, 'get_settings' ) || !method_exists( $kit, 'get_main_id' ) ) {
      return $absent( 'Elementor answered with something that is not a kit document, so nothing here could read its settings. The globals below come from the kit\'s stored settings alone.' );
    }
    if ( (int) $kit->get_main_id() !== $kit_id ) {
      return $absent( 'Elementor answered with its empty placeholder kit rather than with post #' . $kit_id . ', which is what it does when the active kit is missing, trashed or not a kit. Its settings would be Elementor\'s built-in defaults and not this site\'s, so they are not reported as though they were. The globals below come from the kit\'s stored settings alone.' );
    }

    try {
      $settings = $kit->get_settings();
      $controls = method_exists( $kit, 'get_controls' ) ? $kit->get_controls() : [];
    }
    catch ( \Throwable $e ) {
      return $absent( 'Elementor threw reading the kit\'s settings: ' . $this->snippet( $e->getMessage() ) . '. The globals below come from the kit\'s stored settings alone.' );
    }

    return [
      'available' => true,
      'reason' => 'Read from Elementor\'s own kit document, which merges what the kit has stored over the defaults of the controls it registers. ' . ( is_array( $controls ) ? count( $controls ) : 0 ) . ' controls were registered on this request. Elementor does not register the same set every time, so a setting reported below as not reported is one this request could not see, which is not the same as one the site does not have.',
      'settings' => is_array( $settings ) ? $settings : [],
      'controls' => is_array( $controls ) ? count( $controls ) : 0,
    ];
  }

  /**
  * One group of globals, as rows a caller can act on.
  *
  * The _id is the half that matters and the label is the half that is read: a design points
  * at globals/colors?id=primary and never at "Primary", so renaming a global in the editor
  * changes nothing about what refers to it and deleting one leaves every reference dangling
  * under the old id. The reference string travels with every row for that reason.
  *
  * saved_on_the_kit is a property of the whole group rather than of the row, and says so:
  * Elementor stores the repeater whole, so either the kit holds this group's values or it
  * holds none of them and every row in it is a default.
  */
  private function global_rows( array $settings, array $saved, string $key, string $group, string $kind ): array {
    if ( !isset( $settings[ $key ] ) && isset( $saved[ $key ] ) ) {
      $settings[ $key ] = $saved[ $key ];
    }
    if ( !isset( $settings[ $key ] ) || !is_array( $settings[ $key ] ) ) {
      return [];
    }

    $stored = array_key_exists( $key, $saved );
    $rows = [];
    foreach ( $settings[ $key ] as $index => $entry ) {
      // A row this does not recognise is reported as stored. Dropping it would report a
      // global that exists and is referenced as one that does not, and the caller would
      // then be told a colour was free to remove.
      if ( !is_array( $entry ) || !isset( $entry['_id'] ) || !is_scalar( $entry['_id'] ) ) {
        $rows[] = [
          'id' => null,
          'label' => null,
          'group' => $group,
          'setting' => $key,
          'reference' => null,
          'unrecognised' => 'Entry ' . (int) $index . ' of ' . $key . ' is not a row with an _id, so nothing here can name it or say what refers to it. It is reported as stored.',
          'stored' => $this->setting_value( $entry ),
        ];
        continue;
      }

      $id = (string) $entry['_id'];
      $row = [
        'id' => $id,
        'label' => isset( $entry['title'] ) && is_scalar( $entry['title'] ) ? (string) $entry['title'] : '',
        'group' => $group,
        'setting' => $key,
        'reference' => 'globals/' . $kind . '?id=' . $id,
        'saved_on_the_kit' => $stored,
      ];
      if ( $kind === 'colors' ) {
        $row['value'] = isset( $entry['color'] ) && is_scalar( $entry['color'] ) ? (string) $entry['color'] : null;
      }
      else {
        $row['font_family'] = isset( $entry['typography_font_family'] ) && is_scalar( $entry['typography_font_family'] ) ? (string) $entry['typography_font_family'] : null;
      }

      // Whatever else the row carries, minus the keys holding nothing. A font row holds every
      // typography control whether or not it was set, and the set differs between versions;
      // a colour row is not meant to hold anything else, and if it does that is worth seeing
      // rather than hiding. The empty ones are counted rather than dropped quietly, because a
      // count is what tells the caller that what they cannot see is empty and not missing.
      $rest = $entry;
      unset( $rest['_id'], $rest['title'], $rest[ $kind === 'colors' ? 'color' : 'typography_font_family' ] );
      $held = array_filter( $rest, function ( $value ) { return $this->holds_anything( $value ); } );
      $row['other_keys'] = $held === [] ? null : $this->setting_value( $held );
      $row['other_keys_empty'] = count( $rest ) - count( $held );
      $rows[] = $row;
    }
    return $rows;
  }

  /** The named site-wide settings, each saying which of the two sources knew about it. */
  private function setting_rows( array $settings, array $saved ): array {
    $rows = [];
    foreach ( self::KIT_SETTINGS as $key => $label ) {
      $from_elementor = array_key_exists( $key, $settings );
      $from_kit = array_key_exists( $key, $saved );
      $rows[] = [
        'key' => $key,
        'label' => $label,
        'reported' => $from_elementor || $from_kit,
        'value' => $from_elementor ? $this->setting_value( $settings[ $key ] ) : ( $from_kit ? $this->setting_value( $saved[ $key ] ) : null ),
        'saved_on_the_kit' => $from_kit,
        'note' => ( $from_elementor || $from_kit )
          ? null
          : 'Not reported, which is not the same as not set. Neither Elementor nor the kit\'s stored settings offered this key on this request: either this version has no such setting, or it has one whose control it does not register outside its editor and which nobody here has changed. Either way whatever it ships is still in force on the front end and this cannot see it.',
      ];
    }
    return $rows;
  }

  /** Every key the kit has actually stored, minus the globals, which are reported properly. */
  private function saved_rows( array $saved ): array {
    $rows = [];
    foreach ( $saved as $key => $value ) {
      if ( isset( self::GLOBAL_COLOURS[ $key ] ) || isset( self::GLOBAL_FONTS[ $key ] ) ) {
        continue;
      }
      $rows[ (string) $key ] = $this->setting_value( $value );
    }
    return $rows;
  }

  /** The other kits in the library, which is where "I changed it and nothing happened" lives. */
  private function other_kit_rows( array $kits, int $active_id ): array {
    $rows = [];
    foreach ( $kits as $kit ) {
      if ( (int) $kit->ID === $active_id ) {
        continue;
      }
      $rows[] = [
        'id' => (int) $kit->ID,
        'title' => (string) $kit->post_title,
        'status' => (string) $kit->post_status,
        'note' => 'Not the active kit. Editing it changes nothing on the front end until ' . self::KIT_OPTION . ' names it.',
      ];
    }
    return $rows;
  }

  /**
  * How many designs refer to each global, and which.
  *
  * The prefilter is "globals" with no slash after it, and that is not a typo. Elementor
  * stores its documents with json_encode's default escaping, so the stored text reads
  * globals\/colors?id=primary and a LIKE for "globals/" matches nothing at all on any site.
  * That failure is silent and looks exactly like a site where no global is used anywhere,
  * which is the answer that would tell a caller every global was safe to delete.
  *
  * One pass over the documents rather than one query per global, and the documents are read
  * a page at a time and dropped, because they are routinely over 100KB and a site with a few
  * hundred pages would otherwise be asked to hold all of them in memory at once.
  */
  private function globals_usage( array $rows, int $limit ): array {
    global $wpdb;

    $needles = [];
    foreach ( $rows as $row ) {
      if ( empty( $row['reference'] ) ) {
        continue;
      }
      // A needle is the reference between delimiters, and an id carrying one of those
      // delimiters cannot be looked for that way. Such a row is left out of the scan rather
      // than searched for with a needle that cannot match, because the nothing that came
      // back would be indistinguishable from a global nobody uses, and the caller would be
      // told it was safe to delete. Elementor generates its ids, so this is a hand-edited
      // kit or an import, which is the case least worth guessing about.
      if ( strpbrk( (string) ( $row['id'] ?? '' ), "\"\\" ) !== false ) {
        continue;
      }
      $needles[ $row['reference'] ] = substr( (string) $row['reference'], strlen( 'globals' ) ) . '"';
    }
    $found = [];
    foreach ( array_keys( $needles ) as $reference ) {
      $found[ $reference ] = [ 'count' => 0, 'posts' => [] ];
    }
    if ( $needles === [] ) {
      return [ 'documents_examined' => 0, 'truncated' => false, 'by_reference' => $found ];
    }

    $like = '%' . $wpdb->esc_like( 'globals' ) . '%';
    $examined = 0;
    $offset = 0;
    while ( $examined < self::SCAN_LIMIT ) {
      $batch = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_type, p.post_status, m.meta_value
          FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
          WHERE m.meta_key = %s AND m.meta_value LIKE %s
            AND p.post_type != 'revision' AND p.post_status != 'auto-draft'
          ORDER BY p.ID ASC LIMIT %d OFFSET %d",
        self::DATA_META,
        $like,
        self::USAGE_PAGE,
        $offset
      ) );
      if ( !is_array( $batch ) || $batch === [] ) {
        break;
      }
      foreach ( $batch as $document ) {
        $json = (string) $document->meta_value;
        foreach ( $needles as $reference => $needle ) {
          if ( strpos( $json, $needle ) === false ) {
            continue;
          }
          $found[ $reference ]['count']++;
          if ( count( $found[ $reference ]['posts'] ) < $limit ) {
            $found[ $reference ]['posts'][] = $this->reference_row( $document, 'elementor_data', 'Its design refers to ' . $reference . '.' );
          }
        }
        $examined++;
      }
      if ( count( $batch ) < self::USAGE_PAGE ) {
        break;
      }
      $offset += self::USAGE_PAGE;
    }

    return [
      'documents_examined' => $examined,
      'truncated' => $examined >= self::SCAN_LIMIT,
      'by_reference' => $found,
    ];
  }

  /** Put the counts back on the rows they belong to. */
  private function attach_usage( array $rows, array $usage ): array {
    foreach ( $rows as $index => $row ) {
      $reference = $row['reference'] ?? null;
      if ( $reference === null ) {
        continue;
      }
      if ( !isset( $usage['by_reference'][ $reference ] ) ) {
        $rows[ $index ]['used_by_count'] = null;
        $rows[ $index ]['used_by_note'] = 'Not counted. This global\'s id contains a quote or a backslash, which cannot be looked for inside a stored design, and a count of zero from a search that could not have succeeded would read as nobody using it.';
        continue;
      }
      $rows[ $index ]['used_by_count'] = $usage['by_reference'][ $reference ]['count'];
      $rows[ $index ]['used_by'] = $usage['by_reference'][ $reference ]['posts'];
    }
    return $rows;
  }

  /**
  * Whether a stored value holds anything somebody chose.
  *
  * unit, sizes and isLinked are scaffolding: Elementor writes them on every slider and every
  * dimension control whether or not the control was set, so a font nobody has styled still
  * carries a line height of "px" with no number behind it. They do not count towards a value
  * on their own, which is the one piece of Elementor's shapes this assumes, and it is assumed
  * here rather than scattered so that a version that changes it has one place to be fixed.
  */
  private function holds_anything( $value ): bool {
    if ( is_array( $value ) ) {
      foreach ( $value as $key => $item ) {
        if ( in_array( $key, [ 'unit', 'sizes', 'isLinked' ], true ) ) {
          continue;
        }
        if ( $this->holds_anything( $item ) ) {
          return true;
        }
      }
      return false;
    }
    return $value !== '' && $value !== null && $value !== false;
  }

  /** A value small enough to print, or a sentence saying what was there instead. */
  private function setting_value( $value ) {
    $encoded = wp_json_encode( $value );
    if ( !is_string( $encoded ) ) {
      return [ 'not_reported' => 'This value could not be encoded as JSON, so its shape is not reported. It is stored on the kit and nothing here has changed it.' ];
    }
    if ( strlen( $encoded ) <= self::SETTING_VALUE_LIMIT ) {
      return $value;
    }
    return [ 'not_reported' => 'A ' . gettype( $value ) . ' of ' . strlen( $encoded ) . ' bytes of JSON, too large to print here. It is stored on the kit and nothing here has changed it.' ];
  }

  /** The one sentence a caller reads before the rest of it. */
  private function kit_summary( array $state, array $colours, array $fonts, ?array $usage ): string {
    if ( $state['state'] !== 'active' ) {
      return $state['meaning'];
    }

    $summary = count( $colours ) . ' global colour' . ( count( $colours ) === 1 ? '' : 's' )
      . ' and ' . count( $fonts ) . ' global font' . ( count( $fonts ) === 1 ? '' : 's' ) . '.';

    if ( $usage === null ) {
      return $summary . ' Whether anything on the site actually uses them was not asked: pass include_usage to find out before changing or removing one.';
    }

    // Named by reference rather than by id, because the ids are not unique across the two
    // kinds: a site has a colour called primary and a font called primary, and a list of bare
    // ids saying five things are unused reads as one thing listed five times.
    $unused = [];
    foreach ( array_merge( $colours, $fonts ) as $row ) {
      if ( isset( $row['used_by_count'] ) && $row['used_by_count'] === 0 && !empty( $row['reference'] ) ) {
        $unused[] = (string) $row['reference'];
      }
    }
    $read = (int) $usage['documents_examined'];
    $summary .= ' ' . $read . ( $read === 1 ? ' design was' : ' designs were' ) . ' read for references.';
    if ( $usage['truncated'] ) {
      return $summary . ' More than ' . self::SCAN_LIMIT . ' designs use globals, so the counts below are "at least these" and nothing can be called unused on this answer.';
    }
    if ( $unused === [] ) {
      return $summary . ' Every global is referred to by at least one of them.';
    }
    $shown = array_slice( $unused, 0, 12 );
    $more = count( $unused ) - count( $shown );
    return $summary . ' Referred to by none of them: ' . implode( ', ', $shown )
      . ( $more > 0 ? ' and ' . $more . ' more, all listed above with a count of zero' : '' )
      . '. Nothing outside those designs was searched, so that is "unused by any Elementor design on this site", not "unused".';
  }

  #endregion

}
