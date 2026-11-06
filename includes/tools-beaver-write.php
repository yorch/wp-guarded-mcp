<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Creating a Beaver Builder page, from a typed schema, with sanitising this plugin owns.
*
* WHY THIS IS CREATE-ONLY, AND WHY THAT IS NOT AN OVERSIGHT. Beaver's write path applies no
* sanitisation of its own: FLBuilderModel::update_layout_data() writes `_fl_builder_data` with
* no capability check, no kses and no sanitize_settings() — all of that lives in
* save_settings(), which the builder's own UI uses and a direct writer never reaches. So the
* tool below sanitises every value itself. That is the whole control, and it is why the
* accepted shape is a small typed schema rather than a settings array.
*
* The second reason is undo. This plugin's change journal restores post meta through
* update_post_meta(), and GMCP_Core::layout_meta_refusal() refuses layout keys during a tool
* call, so the journal cannot currently restore a layout — its own restore would be blocked by
* this plugin's own guard. Rather than ship a write with no undo, this tool refuses to touch a
* page that already has a layout. A page it creates has no previous layout to lose, and the
* page itself can be deleted with the ordinary post tools. Editing an existing layout waits for
* a `builder` journal kind and a narrow per-operation exemption; both are named in the PR.
*
* WHAT IS NEVER ACCEPTED, at any level, and is refused by name rather than ignored:
*   - `bb_js_code` and `bb_css_code`: the shared advanced path on every module. The JS is
*     concatenated into the layout's script, the CSS is compiled into SCSS.
*   - `custom_attributes` and `attributes`: rendered by concatenation without escaping.
*   - `popup` and `lightbox_content_html`: echoed raw.
*   - `photo_url` / `photo_src`: a caller-controlled URL string, where the attachment id is
*     resolved server-side and is the safe form.
* A caller that sends one of these gets a refusal naming the key, not a silent drop: a value
* that is quietly removed looks like it was written.
*
* The group switch is mcp_tools_beaver_write, separate from the read-only mcp_tools_beaver
* group, so a site can have the reader without the writer.
*/
class GMCP_Tools_Beaver_Write {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [ 'beaver_create_page' ];

  /**
  * The module types this tool will build, and the ONLY fields each one may carry.
  *
  * Keyed by Beaver's real slugs, which are not the module labels: the Text Editor module is
  * `rich-text` and the image module is `photo`. Measured on 2.11.1.2 — a caller naming `text`
  * or `image` gets a refusal that says so.
  */
  const MODULES = [
    'heading'   => [ 'text', 'tag' ],
    'rich-text' => [ 'text' ],
    'button'    => [ 'text', 'link', 'link_target' ],
    'photo'     => [ 'photo' ],
  ];

  /** Heading tags, as a fixed set. Beaver takes the tag as free text in the layout data. */
  const TAGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

  /** Link targets Beaver recognises. */
  const TARGETS = [ '_self', '_blank' ];

  /**
  * Settings keys that are refused even when they arrive under an accepted module.
  *
  * Listed so the refusal can name the reason. Every one of these is a raw sink in Beaver's
  * render path, verified from its source.
  */
  const REFUSED_KEYS = [
    'bb_js_code'            => 'it is concatenated into the layout\'s JavaScript and runs on every page using it',
    'bb_css_code'           => 'it is compiled into the layout\'s stylesheet',
    'custom_attributes'     => 'they are merged into the wrapper tag without escaping',
    'attributes'            => 'they are concatenated into the img tag without escaping',
    'popup'                 => 'the popup markup is echoed raw',
    'lightbox_content_html' => 'the lightbox markup is echoed raw',
    'photo_url'             => 'a caller-controlled image URL; use the attachment id instead',
    'photo_src'             => 'a caller-controlled image URL; use the attachment id instead',
  ];

  /** How many modules one call may create. */
  const MAX_MODULES = 50;

  /** Set when a write turned out to be a no-op, so gmcp_mutate does not fire for it. */
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

  /** Whether Beaver Builder is loaded, checked per call. */
  private function loaded(): bool {
    return defined( 'FL_BUILDER_VERSION' ) && class_exists( 'FLBuilderModel' );
  }

  private function tools(): array {
    return [
      'beaver_create_page' => [
        'name' => 'beaver_create_page',
        'description' => 'Create a page and build its Beaver Builder layout from a typed schema. Admin level. Each entry in `modules` is one module the tool builds a row and column for, in this order, and the layout is written through the builder\'s own writer. Accepted types and the ONLY fields each may carry: heading {text, tag}, rich-text {text}, button {text, link, link_target}, photo {photo: an attachment id}. Everything is sanitised HERE on write, because Beaver\'s own write path applies no sanitisation at all — text goes through wp_kses_post, links through esc_url_raw with a policy that refuses non-http(s) and protocol-relative targets (esc_url keeps //host), the heading tag is a fixed enum, and a photo must be an existing attachment id. A settings key that is a raw sinking point in Beaver\'s render path is REFUSED BY NAME rather than ignored: bb_js_code, bb_css_code (the shared advanced path on every module), custom_attributes, attributes, popup, lightbox_content_html, photo_url and photo_src. The page is created as a draft unless told otherwise, and the reply reports what was stored by reading the layout back, not what was sent. It refuses to touch a page that already has a layout: there is no layout undo yet, so this tool only ever creates the first one.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'title' => [ 'type' => 'string', 'description' => 'The page title.' ],
            'status' => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ], 'description' => 'Post status. Default draft: a layout built by a tool should be looked at before it is public.' ],
            'modules' => [
              'type' => 'array',
              'description' => 'Modules in order. Each is an object with a "type" from heading, rich-text, button, photo, plus that type\'s fields.',
              'items' => [ 'type' => 'object' ],
            ],
          ],
          'required' => [ 'title', 'modules' ],
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

    if ( !$this->loaded() ) {
      return $this->error( $r, 'Beaver Builder is not loaded on this site, so a layout cannot be built. Nothing was created.' );
    }

    try {
      switch ( $tool ) {
        case 'beaver_create_page': $r = $this->create_page( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      // Not the exception text: it can carry paths, and this tool's caller may be a read key's
      // session. The fact and the tool are enough.
      return $this->error( $r, 'Beaver Builder threw an error while running ' . $tool . '. Its message is not returned to a tool caller; the site error log has it.' );
    }

    if ( empty( $r['result']['isError'] ) && !$this->noop && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }

    return $r;
  }

  #region Create

  private function create_page( array $args, array $r ): array {
    $title = isset( $args['title'] ) && is_string( $args['title'] ) ? trim( $args['title'] ) : '';
    if ( $title === '' ) {
      return $this->error( $r, 'A title is required.' );
    }
    $status = isset( $args['status'] ) ? (string) $args['status'] : 'draft';
    if ( !in_array( $status, [ 'draft', 'publish' ], true ) ) {
      return $this->error( $r, 'Unknown status "' . $status . '". Use draft or publish.' );
    }
    $requested = isset( $args['modules'] ) && is_array( $args['modules'] ) ? $args['modules'] : [];
    if ( !$requested ) {
      return $this->error( $r, 'At least one module is required; an empty layout is not a page worth creating through this tool.' );
    }
    if ( count( $requested ) > self::MAX_MODULES ) {
      return $this->error( $r, 'That call named ' . count( $requested ) . ' modules; this tool builds at most ' . self::MAX_MODULES . ' in one page.' );
    }

    // Every module is validated and sanitised BEFORE the page exists, so a refusal leaves
    // nothing behind to clean up.
    $built = [];
    foreach ( $requested as $index => $module ) {
      $one = $this->sanitise_module( $module, $index );
      if ( is_string( $one ) ) {
        return $this->error( $r, $one );
      }
      $built[] = $one;
    }

    $post_id = wp_insert_post( [
      'post_title'   => $title,
      'post_type'    => 'page',
      'post_status'  => $status,
      'post_content' => '',
    ], true );
    if ( is_wp_error( $post_id ) ) {
      return $this->error( $r, 'The page could not be created: ' . $post_id->get_error_message() );
    }
    $post_id = (int) $post_id;

    $layout = $this->build_layout( $built );

    // The layout keys are refused by GMCP_Core::protected_meta_refusal(), whose WordPress-level
    // filter fires for every writer during a tool call. This scopes the permission to this
    // callback and nothing else; it is a static depth counter, not a flag a caller can set.
    $stored = GMCP_Core::with_layout_write( function () use ( $post_id, $layout ) {
      $previous = (int) \FLBuilderModel::get_post_id();
      try {
        \FLBuilderModel::set_post_id( $post_id );
        \FLBuilderModel::update_layout_data( $layout, 'published', $post_id );
        \FLBuilderModel::update_layout_settings( [], 'published', $post_id );
        \FLBuilderModel::enable();
        \FLBuilderModel::delete_asset_cache();
      } finally {
        \FLBuilderModel::set_post_id( $previous );
      }
      return \FLBuilderModel::get_layout_data( 'published', $post_id );
    } );

    // What was STORED, read back through the builder, not what was sent.
    $stored = is_array( $stored ) ? $stored : [];
    $types = [];
    foreach ( $stored as $node ) {
      if ( is_object( $node ) && isset( $node->type ) ) {
        $types[] = (string) $node->type;
      }
    }
    sort( $types );

    return $this->json( $r, [
      'ID' => $post_id,
      'status' => $status,
      'builder_enabled' => (bool) \FLBuilderModel::is_builder_enabled( $post_id ),
      'stored_nodes' => count( $stored ),
      'stored_types' => $types,
      'note' => 'The reply is the layout read back out of the builder, not the modules that were sent. Editing this page\'s layout is refused: there is no layout undo yet, so this tool only creates the first one. Publish it from wp-admin when it looks right.',
    ] );
  }

  /**
  * One module, validated and sanitised, as a node waiting for its parent.
  *
  * @return array|string The node settings, or a refusal sentence.
  */
  private function sanitise_module( $module, int $index ) {
    if ( !is_array( $module ) ) {
      return 'Module ' . $index . ' is not an object.';
    }
    $type = isset( $module['type'] ) ? (string) $module['type'] : '';
    if ( $type === '' ) {
      return 'Module ' . $index . ' has no type.';
    }
    if ( isset( self::REFUSED_KEYS[ $type ] ) ) {
      return 'Module ' . $index . ' asks for "' . $type . '", which is not a module type and never will be: ' . self::REFUSED_KEYS[ $type ] . '.';
    }
    if ( !isset( self::MODULES[ $type ] ) ) {
      $known = implode( ', ', array_keys( self::MODULES ) );
      return 'Module ' . $index . ' has type "' . $type . '". This tool builds only: ' . $known . '. (Beaver\'s Text Editor module is "rich-text" and its image module is "photo"; "text" and "image" are not types.)';
    }

    // A refused key is refused wherever it appears, including next to an accepted one.
    foreach ( array_keys( self::REFUSED_KEYS ) as $refused ) {
      if ( array_key_exists( $refused, $module ) ) {
        return 'Module ' . $index . ' carries "' . $refused . '", which is refused: ' . self::REFUSED_KEYS[ $refused ] . '.';
      }
    }

    $allowed = self::MODULES[ $type ];
    foreach ( array_keys( $module ) as $key ) {
      if ( $key !== 'type' && !in_array( $key, $allowed, true ) ) {
        return 'Module ' . $index . ' (' . $type . ') carries an unsupported field "' . $key . '". Accepted: ' . implode( ', ', $allowed ) . '.';
      }
    }

    $out = [];
    switch ( $type ) {
      case 'heading':
        $text = $this->plain_text( $module['text'] ?? '' );
        if ( $text === '' ) {
          return 'Module ' . $index . ' (heading) has no text.';
        }
        $tag = isset( $module['tag'] ) ? strtolower( (string) $module['tag'] ) : 'h2';
        if ( !in_array( $tag, self::TAGS, true ) ) {
          return 'Module ' . $index . ' (heading) asks for tag "' . $tag . '". Use one of: ' . implode( ', ', self::TAGS ) . '. The tag is an element name in Beaver\'s output, so free text there is an injection point.';
        }
        // The stored value is what the renderer echoes, and it echoes it RAW, so the
        // sanitiser has to run here rather than being trusted to the builder.
        $out = [ 'text' => $text, 'tag' => $tag ];
        break;

      case 'rich-text':
        $text = $this->html_text( $module['text'] ?? '' );
        if ( $text === '' ) {
          return 'Module ' . $index . ' (rich-text) has no text.';
        }
        $out = [ 'text' => $text ];
        break;

      case 'button':
        $text = $this->plain_text( $module['text'] ?? '' );
        if ( $text === '' ) {
          return 'Module ' . $index . ' (button) has no text.';
        }
        $link = $this->safe_link( $module['link'] ?? '', $index );
        if ( is_string( $link ) && $link !== '' && $link[0] !== '/' && strpos( $link, ':' ) === false ) {
          return $link;
        }
        $target = isset( $module['link_target'] ) ? (string) $module['link_target'] : '_self';
        if ( !in_array( $target, self::TARGETS, true ) ) {
          return 'Module ' . $index . ' (button) asks for target "' . $target . '". Use _self or _blank.';
        }
        $out = [ 'text' => $text, 'link' => $link, 'link_target' => $target ];
        break;

      case 'photo':
        $att = isset( $module['photo'] ) ? (int) $module['photo'] : 0;
        if ( $att < 1 || get_post_type( $att ) !== 'attachment' ) {
          return 'Module ' . $index . ' (photo) needs "photo" set to an existing attachment id.';
        }
        // The id, never a URL: Beaver resolves the id server-side, and a URL string is a
        // caller-controlled sink.
        $out = [ 'photo' => $att, 'photo_source' => 'library' ];
        break;
    }

    return [ 'type' => $type, 'settings' => $out ];
  }

  /**
  * A layout graph: one row per module, one column inside it, the module inside that.
  *
  * Built here rather than accepted from the caller, so the parent/position chain is valid by
  * construction. Beaver's clean_layout_data() silently drops nodes it cannot place, so a
  * graph a caller assembled badly would produce an empty page and a success reply.
  */
  private function build_layout( array $built ): array {
    $layout = [];
    $next = 1;
    $position = 0;
    foreach ( $built as $module ) {
      $row = (string) $next++;
      $col = (string) $next++;
      $node = (string) $next++;
      $layout[ $row ] = (object) [ 'node' => $row, 'type' => 'row', 'parent' => null, 'position' => $position, 'settings' => (object) [] ];
      $layout[ $col ] = (object) [ 'node' => $col, 'type' => 'column', 'parent' => $row, 'position' => 0, 'settings' => (object) [] ];
      $layout[ $node ] = (object) [ 'node' => $node, 'type' => $module['type'], 'parent' => $col, 'position' => 0, 'settings' => (object) $module['settings'] ];
      $position++;
    }
    return $layout;
  }

  #endregion

  #region Sanitising

  /**
  * Text that must stay literal: no tags, no entities, no shortcode brackets.
  *
  * Beaver's heading and button renderers echo their text RAW. Escaping to entities is not
  * enough on its own for a value that is also passed through do_shortcode() when the assembled
  * layout is rendered, so square brackets are neutralised here: a shortcode is a way to run
  * code from a string, and this tool's input is a string from a model.
  */
  private function plain_text( $value ): string {
    $text = is_scalar( $value ) ? (string) $value : '';
    $text = wp_strip_all_tags( $text );
    $text = str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], $text );
    return trim( $text );
  }

  /**
  * Text that may carry formatting, but no script and no shortcodes.
  *
  * wp_kses_post is WordPress's own allowlist for post content: it strips script, event handlers
  * and embeds. Shortcode brackets are neutralised for the same reason as above.
  */
  private function html_text( $value ): string {
    $text = is_scalar( $value ) ? (string) $value : '';
    $text = wp_kses_post( $text );
    $text = str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], $text );
    return trim( $text );
  }

  /**
  * A link this site can justify, or '' / a refusal sentence.
  *
  * Beaver renders the link through esc_url(), which strips javascript: and data: but KEEPS a
  * protocol-relative "//host" — measured on this stack. A protocol-relative link leaves the
  * site on whatever scheme the visitor arrived with, so it is refused here rather than relied
  * on to be stripped.
  */
  private function safe_link( $value, int $index ) {
    $raw = is_scalar( $value ) ? trim( (string) $value ) : '';
    if ( $raw === '' ) {
      return '';
    }
    if ( strpos( $raw, '//' ) === 0 ) {
      return 'Module ' . $index . ' (button) link starts with "//", which is protocol-relative and points at another host. Beaver\'s esc_url keeps it. Give an absolute http(s) URL or a path on this site.';
    }
    if ( $raw[0] === '/' ) {
      return esc_url_raw( $raw );
    }
    $scheme = strtolower( (string) wp_parse_url( $raw, PHP_URL_SCHEME ) );
    if ( !in_array( $scheme, [ 'http', 'https' ], true ) ) {
      return 'Module ' . $index . ' (button) link must be http(s) or a path beginning with "/", not "' . $scheme . '".';
    }
    return esc_url_raw( $raw );
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
