<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Beaver Builder, read from the outside: what a page is built from, never what it says.
*
* The trap this group exists for is the same one the Elementor and Yoast groups document, in
* a different place: **the layout is not post_content.** A Beaver Builder page keeps its
* layout in post meta — `_fl_builder_data` (published) and `_fl_builder_draft` (unpublished
* changes), plus `_fl_builder_data_settings` — and the front end renders from those, not from
* the post body. Measured on 2.11.1.2: writing post_content through the generic tools changes
* the post and leaves the page rendering exactly as before, which reads as a successful edit
* to anyone checking the editor and as no change at all to a visitor.
*
* THE DRAFT COPY IS THE OTHER HALF OF THAT. A page can hold unpublished builder changes, so a
* reader that reports only `_fl_builder_data` can describe a layout no visitor sees. Every
* reply says whether drafted changes exist, and the layout tool reports both copies' node
* counts rather than quietly choosing one.
*
* READ ONLY, WITH ONE EXCEPTION THAT IS NOT A LAYOUT WRITE. `beaver_clear_cache` deletes the
* generated CSS/JS files for a post (or every post) so the builder regenerates them on the
* next request. It is the Elementor group's "regenerate CSS" by another name: the files are
* derived, the builder recreates them, and a stale one is why an edit appears not to have
* taken effect. It is admin level and announces itself on gmcp_mutate like every other write.
*
* WHAT IS NEVER RETURNED. A node's `settings` is where the content lives, and on this builder
* that includes the HTML module — arbitrary markup, script included — and the layout-level
* `css` and `js` fields in `_fl_builder_data_settings`, which Beaver Builder treats as
* executable for the whole page. A reader that returned settings would hand a model both a
* script injection primitive and a reconnaissance tool, and this plugin copies every reply
* into its own audit log for the retention window. So the tools report the SHAPE: node types,
* how many of each, how deep the tree is, and which keys a layout's settings hold — never a
* value. There is no tool that writes layout data, at any level, and that was decided against a
* written proposal rather than for want of one. Three measured reasons, each of which killed a
* narrower design that looked safe on its own:
*
*   1. Applying a saved template, or simply enabling the builder on a page, activates `html`
*      modules and layout `js`/`css` that are ALREADY STORED. The caller never submits script;
*      it picks a template that contains one. A module allowlist constrains new input and this
*      route adds none.
*   2. The change journal cannot undo a layout write. Its meta restore calls update_post_meta(),
*      and GMCP_Core::layout_meta_refusal() refuses those keys during a tool call, so the
*      plugin's own undo would be blocked by the plugin's own guard. A snapshot the tool writes
*      is not a journal entry either, so wp_undo_change would not find it.
*   3. A module allowlist does not prove non-executable content: `text`, `button` and `heading`
*      all take values that can carry markup or a link, and nothing here has read Beaver's
*      renderers to say otherwise.
*
* The smallest version that survived review was one plain-text field on an existing heading node
* of a draft post, with a dedicated journal kind and a renderer audit. That is a great deal of
* machinery for one field, so the group stays read-only and says so here.
*
* Module and template names are site configuration, so they are returned: knowing a page uses
* `html` and `heading` modules is how an operator finds the page that is misbehaving. Which is
* also why the node types are named rather than counted as "modules".
*/
class GMCP_Tools_Beaver {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [ 'beaver_clear_cache' ];

  /** Set when a write turned out to be a no-op, so gmcp_mutate does not fire for it. */
  private $noop = false;

  /** The builder's own template post type. */
  const TEMPLATE_CPT = 'fl-builder-template';

  /** Revisions the builder keeps for itself; listed nowhere, because they are its own storage. */
  const HISTORY_CPT = 'fl-builder-history';

  /** Published and unpublished layout meta, measured on 2.11.1.2. */
  const META_PUBLISHED = '_fl_builder_data';
  const META_DRAFT = '_fl_builder_draft';
  const META_SETTINGS_PUBLISHED = '_fl_builder_data_settings';
  const META_SETTINGS_DRAFT = '_fl_builder_draft_settings';
  const META_ENABLED = '_fl_builder_enabled';

  /**
  * Layout setting keys whose VALUE is never returned, listed so the reply can say they exist.
  *
  * `js` and `css` are executable for the whole page. They are reported by name because an
  * operator asking "does this layout carry custom code" deserves a yes or no; the code itself
  * is not this plugin's to hand out.
  */
  const CODE_SETTING_KEYS = [ 'js', 'css' ];

  /** A page of templates. */
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
  * Whether Beaver Builder is loaded, checked per call.
  *
  * The version constant plus the model class every reader here calls. The builder can be
  * deactivated between the group being switched on and a call arriving, and the constant
  * alone would still resolve on a copy that loaded its files and then bailed out.
  */
  private function loaded(): bool {
    return defined( 'FL_BUILDER_VERSION' ) && class_exists( 'FLBuilderModel' );
  }

  /** Whether this is the free version. `FL_BUILDER_LITE` is defined only on Lite. */
  private function is_lite(): bool {
    return defined( 'FL_BUILDER_LITE' );
  }

  private function tools(): array {
    return [
      'beaver_status' => [
        'name' => 'beaver_status',
        'description' => 'Report whether Beaver Builder is loaded, which version and whether it is the free or the premium one, how many modules and templates this site has enabled, and how the builder is enqueueing its generated CSS and JS (a file, or inline in the page). Read level, and the block to read first when a builder tool answers something surprising: "the builder is not loaded" and "the page has no layout" are different answers and only this one tells them apart. Changes nothing.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
        'accessLevel' => 'read',
      ],
      'beaver_layout' => [
        'name' => 'beaver_layout',
        'description' => 'Report what one post is built from, WITHOUT returning what it says: whether the builder is enabled on it, how many nodes the published layout holds and how many the unpublished draft holds, a count of node types (row, column, html, heading and so on), the depth of the tree, whether the layout carries custom CSS or JS (named, never returned), and whether there are drafted changes a visitor cannot see. Node settings are never returned at any level, because they hold the content and, for the HTML module, arbitrary markup including script. Read level: node types and counts describe the site, not a person. Use it to find the page that is misbehaving before opening it in the builder.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The post, page or template id.' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'read',
      ],
      'beaver_templates' => [
        'name' => 'beaver_templates',
        'description' => 'List the saved Beaver Builder templates: id, title, status, the template type the builder records for it, and how many nodes its layout holds. Read level. Template CONTENT is not returned, for the same reason a page\'s layout settings are not: it is the markup, and the HTML module makes it executable. There is no tool that applies a template to a page or imports one — applying a saved template is reversible because the previous layout is still there, but importing one rewrites the whole design system from an archive built elsewhere in a single call, with no restore tool behind it.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'status' => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'any' ], 'description' => 'Only templates in this status. Default any, which includes trash.' ],
            'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Rows to return. Default 20, maximum 100.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'beaver_clear_cache' => [
        'name' => 'beaver_clear_cache',
        'description' => 'Delete the CSS and JS files Beaver Builder generated for one post, or for every post, so the builder regenerates them on the next request. This is the fix for the most common "my edit did not take effect" on this builder: the layout is stored correctly and the page is still serving the old generated stylesheet. Admin level and recorded like any other write, but it is not a layout change: the files are derived, the builder recreates them, and nothing in the page\'s own data is touched. When the site enqueues the builder\'s CSS inline instead of from files there is nothing to delete, and the reply says so rather than reporting a success that did nothing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'The post to clear. 0 (the default) clears every post\'s generated assets.' ],
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

    try {
      switch ( $tool ) {
        case 'beaver_status':      $r = $this->status( $args, $r ); break;
        case 'beaver_layout':      $r = $this->layout( $args, $r ); break;
        case 'beaver_templates':   $r = $this->templates( $args, $r ); break;
        case 'beaver_clear_cache': $r = $this->clear_cache( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      // The exception text is not returned: it can carry paths and implementation detail, and
      // these tools are readable at the read level. The fact and the tool are enough.
      return $this->error( $r, 'Beaver Builder threw an error while running ' . $tool . '. Its message is not returned to a tool caller; the site error log has it.' );
    }

    // Announced only when something actually changed: a clear aimed at a missing post, or at a
    // site that enqueues its CSS inline, changes nothing and must not report a mutation.
    if ( empty( $r['result']['isError'] ) && !$this->noop && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }

    return $r;
  }

  #region Reads

  private function status( array $args, array $r ): array {
    $state = $this->builder_state();
    if ( !$state['loaded'] ) {
      return $this->json( $r, [ 'builder' => $state, 'note' => 'Beaver Builder is not loaded, so there is nothing to report about it. This is not the same as a site with no layouts.' ] );
    }

    return $this->json( $r, [
      'builder' => $state,
      'modules' => [
        'enabled' => $this->module_names(),
        'note' => 'Module names, not their settings. The html module is the one to know about: it stores arbitrary markup, script included.',
      ],
      'templates' => $this->template_counts(),
      'note' => $state['note'] ?? null,
    ] );
  }

  /** One post's layout SHAPE: counts, types, depth, and whether a draft copy exists. */
  private function layout( array $args, array $r ): array {
    if ( !$this->loaded() ) {
      return $this->error( $r, 'Beaver Builder is not loaded on this site, so its layouts cannot be read.' );
    }
    $post_id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;
    if ( $post_id < 1 ) {
      return $this->error( $r, 'A post id is required.' );
    }
    $post = get_post( $post_id );
    if ( !$post ) {
      return $this->json( $r, [ 'builder' => $this->builder_state(), 'layout' => null, 'note' => 'No post with id ' . $post_id . ' exists on this site.' ] );
    }

    $enabled = $this->builder_enabled( $post_id );
    $published = $this->node_shape( $this->layout_data( 'published', $post_id ) );
    $draft = $this->node_shape( $this->layout_data( 'draft', $post_id ) );
    $settings = $this->settings_keys( $post_id );

    // Whether a draft exists, and whether it DIFFERS, are two facts and neither is a node count.
    // Editing text, a setting or a node's position leaves the count alone, and a draft that
    // removed every node has a count of zero — the first version compared counts and so
    // reported neither. The comparison is on the stored values, which are never returned.
    $draft_present = metadata_exists( 'post', $post_id, self::META_DRAFT );
    $published_raw = get_post_meta( $post_id, self::META_PUBLISHED, true );
    $draft_raw = get_post_meta( $post_id, self::META_DRAFT, true );
    $diverges = $draft_present && $draft_raw !== $published_raw;

    $out = [
      'builder' => $this->builder_state(),
      'post' => [
        'ID' => $post_id,
        'type' => (string) $post->post_type,
        'status' => (string) $post->post_status,
        'title' => (string) $post->post_title,
      ],
      'builder_enabled' => $enabled,
      'published' => $published,
      'draft' => $draft,
      'draft_present' => $draft_present,
      'draft_differs' => $diverges,
      'layout_settings' => $settings,
    ];

    if ( !$enabled ) {
      $out['note'] = 'The builder is not enabled on this post, so the front end renders its ordinary post content and any layout meta below is leftover. This is the state to check before concluding that an edit did not take effect.';
    } elseif ( $diverges ) {
      // The trap the class comment describes: the published copy and the draft disagree, so
      // a reader looking at only one of them describes a page that is not what visitors see.
      $out['note'] = 'This post has unpublished builder changes: the draft copy differs from the published one, which holds ' . $published['nodes'] . ' node(s) and ' . $draft['nodes'] . ' in draft. Visitors see the published copy until the layout is saved.';
    }

    return $this->json( $r, $out );
  }

  private function templates( array $args, array $r ): array {
    if ( !$this->loaded() ) {
      return $this->error( $r, 'Beaver Builder is not loaded on this site, so its templates cannot be read.' );
    }
    $status = isset( $args['status'] ) ? (string) $args['status'] : 'any';
    if ( !in_array( $status, [ 'publish', 'draft', 'any' ], true ) ) {
      return $this->error( $r, 'Unknown status "' . $status . '". Use one of: publish, draft, any.' );
    }
    $limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
    $limit = min( max( 1, $limit ), self::MAX_LIMIT );

    $posts = get_posts( [
      'post_type'   => self::TEMPLATE_CPT,
      'post_status' => $this->status_list( $status ),
      'numberposts' => $limit,
      'orderby'     => 'ID',
      'order'       => 'DESC',
    ] );

    $rows = [];
    foreach ( $posts as $post ) {
      $shape = $this->node_shape( $this->layout_data( 'published', (int) $post->ID ) );
      $rows[] = [
        'ID' => (int) $post->ID,
        'title' => (string) $post->post_title,
        'status' => (string) $post->post_status,
        // The builder records what kind of template this is in its own meta; reported as
        // stored rather than mapped to a guess.
        'template_type' => (string) get_post_meta( (int) $post->ID, '_fl_builder_template_type', true ),
        'nodes' => $shape['nodes'],
        'node_types' => $shape['by_type'],
      ];
    }

    return $this->json( $r, [
      'builder' => $this->builder_state(),
      'count' => count( $rows ),
      'templates' => $rows,
      'note' => 'Node types and counts only. Template content is the markup, and the html module makes it executable, so it is not returned by any tool here.',
    ] );
  }

  #endregion

  #region Write

  /** Delete generated CSS/JS so the builder regenerates them. Derived files only. */
  private function clear_cache( array $args, array $r ): array {
    if ( !$this->loaded() ) {
      return $this->error( $r, 'Beaver Builder is not loaded on this site, so there are no generated assets to clear.' );
    }
    $post_id = isset( $args['ID'] ) ? (int) $args['ID'] : 0;

    // The builder can enqueue its CSS inline instead of from files. Saying "cleared" there
    // would be a success that did nothing, so the state is read first and reported.
    $method = $this->asset_method();
    if ( $method === 'inline' ) {
      $this->noop = true;
      return $this->json( $r, [
        'cleared' => false,
        'enqueue_method' => $method,
        'note' => 'This site enqueues the builder\'s CSS inline rather than from generated files, so there is nothing to delete. An edit that appears not to have taken effect here is a page-cache question, not a builder one.',
      ] );
    }

    if ( $post_id > 0 ) {
      if ( !get_post( $post_id ) ) {
        $this->noop = true;
        return $this->json( $r, [ 'cleared' => false, 'note' => 'No post with id ' . $post_id . ' exists on this site, so nothing was cleared.' ] );
      }
      // delete_asset_cache() works on the model's current post, so the id is set first — and
      // restored afterwards, because set_post_id() mutates global model state that a later
      // call in the same request would otherwise inherit.
      $previous = method_exists( '\FLBuilderModel', 'get_post_id' ) ? (int) \FLBuilderModel::get_post_id() : 0;
      try {
        \FLBuilderModel::set_post_id( $post_id );
        \FLBuilderModel::delete_asset_cache();
      } finally {
        \FLBuilderModel::set_post_id( $previous );
      }
      return $this->json( $r, [ 'cleared' => true, 'scope' => 'post', 'ID' => $post_id, 'enqueue_method' => $method, 'note' => 'The builder regenerates these files on the next request. The reply reports that the deletion was asked for, not which files existed: the builder owns that path.' ] );
    }

    \FLBuilderModel::delete_asset_cache_for_all_posts();
    return $this->json( $r, [ 'cleared' => true, 'scope' => 'all', 'enqueue_method' => $method ] );
  }

  #endregion

  #region State and shape

  /** What the builder is, independent of what any page holds. */
  private function builder_state(): array {
    $state = [
      'loaded' => $this->loaded(),
      'version' => defined( 'FL_BUILDER_VERSION' ) ? (string) FL_BUILDER_VERSION : null,
      'edition' => $this->loaded() ? ( $this->is_lite() ? 'lite' : 'premium' ) : null,
    ];
    if ( !$state['loaded'] ) {
      $state['note'] = 'Beaver Builder is not loaded.';
      return $state;
    }
    $state['enqueue_method'] = $this->asset_method();
    if ( $this->is_lite() ) {
      $state['note'] = 'This is the free edition. Some modules and templates are premium-only, so a module that is missing here may not be a fault.';
    }
    return $state;
  }

  /** How the builder enqueues its generated CSS: from a file, or inline. */
  private function asset_method(): string {
    if ( !$this->loaded() || !method_exists( '\FLBuilderModel', 'get_asset_enqueue_method' ) ) {
      return 'unknown';
    }
    try {
      return (string) \FLBuilderModel::get_asset_enqueue_method();
    } catch ( \Throwable $e ) {
      return 'unknown';
    }
  }

  /** Whether the builder is enabled on a post. */
  private function builder_enabled( int $post_id ): bool {
    if ( !$this->loaded() || !method_exists( '\FLBuilderModel', 'is_builder_enabled' ) ) {
      return false;
    }
    return (bool) \FLBuilderModel::is_builder_enabled( $post_id );
  }

  /**
  * One copy of a post's layout, as stored, or an empty array.
  *
  * Through the builder's own reader rather than unserialising the meta here: the model
  * applies the same filters the builder applies, so this sees what the builder sees.
  */
  private function layout_data( string $status, int $post_id ): array {
    if ( !$this->loaded() || !method_exists( '\FLBuilderModel', 'get_layout_data' ) ) {
      return [];
    }
    try {
      $data = \FLBuilderModel::get_layout_data( $status, $post_id );
    } catch ( \Throwable $e ) {
      return [];
    }
    return is_array( $data ) ? $data : [];
  }

  /**
  * A layout reduced to its SHAPE: how many nodes, of which types, how deep.
  *
  * Settings are not read. That is the whole point of this function: a node's settings is the
  * content, and for the html module it is markup that runs.
  */
  private function node_shape( array $data ): array {
    $by_type = [];
    $depth = 0;
    $invalid = 0;
    $cycle = false;
    foreach ( $data as $node ) {
      if ( !is_object( $node ) || !isset( $node->node ) ) {
        // Reported rather than folded into "unknown": a node the builder's own reader handed
        // back and this cannot place is a fact about the layout, not a module type.
        $invalid++;
        continue;
      }
      $type = isset( $node->type ) ? (string) $node->type : 'unknown';
      $by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
    }
    // Depth by walking parents with a visited set: a corrupted layout can contain a cycle, and
    // a bounded-but-blind walk would report a depth of 100 for it, which reads as a very deep
    // page rather than as corruption.
    foreach ( $data as $node ) {
      if ( !is_object( $node ) ) {
        continue;
      }
      [ $d, $c ] = $this->node_depth( $node, $data, [] );
      $depth = max( $depth, $d );
      $cycle = $cycle || $c;
    }
    ksort( $by_type );
    return [
      'nodes' => count( $data ),
      'by_type' => (object) $by_type,
      'depth' => $depth,
      'invalid_nodes' => $invalid,
      'cycle_detected' => $cycle,
    ];
  }

  /** A node's depth and whether the walk met a cycle. */
  private function node_depth( $node, array $data, array $seen ): array {
    if ( !is_object( $node ) || empty( $node->parent ) ) {
      return [ 1, false ];
    }
    $id = isset( $node->node ) ? (string) $node->node : '';
    if ( $id !== '' && isset( $seen[ $id ] ) ) {
      return [ 1, true ];
    }
    if ( $id !== '' ) {
      $seen[ $id ] = true;
    }
    if ( count( $seen ) > 100 ) {
      return [ count( $seen ), true ];
    }
    $parent = $data[ (string) $node->parent ] ?? null;
    if ( !is_object( $parent ) ) {
      return [ 1, false ];
    }
    [ $d, $c ] = $this->node_depth( $parent, $data, $seen );
    return [ 1 + $d, $c ];
  }

  /**
  * The NAMES of a layout's settings keys, and whether it carries custom code.
  *
  * `js` and `css` are executable for the whole page, so they are reported as present and
  * never returned. Every other key is reported by name for the same reason a schema is
  * useful: an operator can see that a layout overrides its row width without being handed
  * the markup.
  */
  private function settings_keys( int $post_id ): array {
    $keys = [];
    $code = [];
    foreach ( [ self::META_SETTINGS_PUBLISHED, self::META_SETTINGS_DRAFT ] as $meta_key ) {
      $raw = get_post_meta( $post_id, $meta_key, true );
      if ( is_object( $raw ) ) {
        $raw = get_object_vars( $raw );
      }
      if ( !is_array( $raw ) ) {
        continue;
      }
      foreach ( array_keys( $raw ) as $key ) {
        $keys[ (string) $key ] = true;
        if ( in_array( (string) $key, self::CODE_SETTING_KEYS, true ) ) {
          $value = $raw[ $key ];
          // "carries custom code" is the fact worth having; the code is not returned.
          // OR'd across the published and draft copies: the first version let an empty draft
          // value overwrite a non-empty published one, so a live layout that carries code
          // reported that it does not.
          $code[ (string) $key ] = ( $code[ (string) $key ] ?? false ) || ( is_string( $value ) && trim( $value ) !== '' );
        }
      }
    }
    return [
      'keys' => array_keys( $keys ),
      'carries_custom_code' => (object) $code,
      'note' => 'Setting names only. The js and css fields hold code that runs on every page using this layout, so whether they are set is reported and their contents are not.',
    ];
  }

  /** Module names this site has enabled. */
  private function module_names(): array {
    if ( !$this->loaded() || !method_exists( '\FLBuilderModel', 'get_enabled_modules' ) ) {
      return [];
    }
    try {
      $modules = \FLBuilderModel::get_enabled_modules();
    } catch ( \Throwable $e ) {
      return [];
    }
    $names = [];
    foreach ( (array) $modules as $key => $value ) {
      // The builder has returned both a map and a list across versions; both are handled
      // rather than assumed, so a version change reports the names instead of the keys.
      $names[] = is_string( $value ) ? $value : (string) $key;
    }
    sort( $names );
    return $names;
  }

  /** How many templates exist, by status. */
  private function template_counts(): array {
    $counts = [];
    foreach ( [ 'publish', 'draft', 'trash' ] as $status ) {
      $ids = get_posts( [
        'post_type'   => self::TEMPLATE_CPT,
        'post_status' => $status,
        'numberposts' => -1,
        'fields'      => 'ids',
      ] );
      $counts[ $status ] = is_array( $ids ) ? count( $ids ) : 0;
    }
    return $counts;
  }

  /**
  * The post statuses a caller's status argument means.
  *
  * 'any' is expanded explicitly: WordPress's 'any' excludes trash, so a description promising
  * trash and a query using 'any' would disagree.
  */
  private function status_list( string $status ): array {
    if ( $status === 'any' ) {
      return [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
    }
    return [ $status ];
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
