<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Creating a Beaver Builder page, from a typed schema, with sanitising this plugin owns.
*
* WHY THIS IS CREATE-ONLY. Beaver's write path applies no sanitisation of its own:
* FLBuilderModel::update_layout_data() writes `_fl_builder_data` with no capability check, no
* kses and no sanitize_settings() — all of that lives in save_settings(), which the builder's
* own UI uses and a direct writer never reaches. So the tool below sanitises every value itself.
* That is the whole control, and it is why the accepted shape is a small typed schema rather
* than a settings array. The tool has no argument that names an existing post, so it cannot
* replace or extend a layout that is already there: every call makes a new page.
*
* UNDO. This file used to say the journal could not restore a layout. It can. The change journal records the three layout
* rows (`_fl_builder_data`, `_fl_builder_data_settings`, `_fl_builder_enabled`) as custom fields
* added, and wp_undo_change on them works both ways (measured): removing a row, and putting it
* back from the journal, which restores exactly the sanitised value this tool wrote and nothing
* a caller could choose. What the journal does not have is an entry for the CREATION of the page
* (it has no kind that means "remove this post"), so a created page is removed with
* wp_delete_post, and the reply says so.
*
* WHAT THE REPLY CLAIMS. The reply is built from the stored row, read straight from the
* database after the write: the builder's own reader returns the array its writer cached in the
* same request, so a read through it proves nothing about what reached the table. If the stored
* layout is not the one that was built, the page is deleted again and the call fails; a tool
* that answers failure over a page it left behind, or success over a layout it half stored, is
* the defect this file's reply is written to avoid.
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

  /** Bytes in one text or link field, bytes across a page, characters in a title. */
  const MAX_TEXT = 100000;
  const MAX_TOTAL = 400000;
  const MAX_TITLE = 200;

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
        'description' => 'Create a page and build its Beaver Builder layout from a typed schema. Admin level. Each entry in `modules` becomes one row with one column holding that module, in this order, written through the builder\'s own writer. Accepted types and the ONLY fields each may carry: heading {text, tag}, rich-text {text}, button {text, link, link_target}, photo {photo: an attachment id}. Everything is sanitised HERE on write, because Beaver\'s own write path applies no sanitisation at all: text goes through wp_kses_post (headings and buttons as plain text), a button link must be an absolute http(s) URL with a host and no user information, or a path on this site, and is judged after sanitising (a protocol-relative or backslash target is refused), the heading tag is a fixed enum, and a photo must be an existing image attachment that a featured image could use (a form submission\'s upload, or an order\'s, is refused). A line of rich text that is only a URL becomes a link instead of an embed, so rendering the page does not fetch an address the caller chose. A settings key that is a raw sinking point in Beaver\'s render path is REFUSED BY NAME rather than ignored: bb_js_code, bb_css_code, custom_attributes, attributes, popup, lightbox_content_html, photo_url and photo_src. At most 50 modules, 100000 bytes in a field and 400000 in all. The page is created as a draft unless told otherwise, and made public last. The reply reports what is stored in the database (read after the write, not the builder\'s cache); if the stored layout is not the one built, or anything fails after the page exists, the page is deleted again and the call fails. This tool only creates: it has no argument that names an existing page. Remove a page it made with wp_delete_post.',
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
    if ( mb_strlen( $title ) > self::MAX_TITLE ) {
      return $this->error( $r, 'The title is ' . mb_strlen( $title ) . ' characters; this tool takes at most ' . self::MAX_TITLE . '.' );
    }
    $status = isset( $args['status'] ) && is_string( $args['status'] ) ? $args['status'] : 'draft';
    if ( !in_array( $status, [ 'draft', 'publish' ], true ) ) {
      return $this->error( $r, 'Unknown status. Use draft or publish.' );
    }
    // The same questions every other tool that creates a post asks before it writes. The post
    // type is fixed at "page", but the lists behind these are filterable, and a site that adds
    // "page" to one of them has made a decision this tool must not walk around.
    $commerce = GMCP_Core::commerce_post_guard( 0, 'Creating', 'page' );
    if ( $commerce !== true ) {
      return $this->error( $r, $commerce, -32600 );
    }
    $submission = GMCP_Core::personal_data_retype_guard( 0, 'page' );
    if ( $submission !== true ) {
      return $this->error( $r, $submission, -32600 );
    }
    // A key restricted to drafts may not publish. (Such a key is capped below admin and never
    // reaches this tool; asked anyway so the answer does not depend on that cap.) The net under
    // wp_insert_post would stop it too, but only as an exception from inside WordPress.
    $drafts = GMCP_Core::drafts_only_status_guard( $status, 'Creating a page' );
    if ( $drafts !== true ) {
      return $this->error( $r, $drafts, -32600 );
    }
    // The transport's manage_options does not imply these for a custom role, and neither is a
    // capability the tool may assume. Checked before anything is created.
    if ( !current_user_can( 'edit_pages' ) ) {
      return $this->error( $r, 'Creating a page needs the edit_pages capability on this site, which the credential in use does not hold.', -32600 );
    }
    if ( $status === 'publish' && !current_user_can( 'publish_pages' ) ) {
      return $this->error( $r, 'Publishing a page needs the publish_pages capability, which the credential in use does not hold. Create it as a draft instead.', -32600 );
    }

    // Some clients send an array as a JSON string; the other create tools accept that.
    $requested = $args['modules'] ?? [];
    if ( is_string( $requested ) ) {
      $requested = json_decode( $requested, true );
    }
    if ( !is_array( $requested ) || !$requested ) {
      return $this->error( $r, 'At least one module is required; an empty layout is not a page worth creating through this tool.' );
    }
    if ( !array_is_list( $requested ) ) {
      return $this->error( $r, '"modules" must be a list of module objects, in order.' );
    }
    if ( count( $requested ) > self::MAX_MODULES ) {
      return $this->error( $r, 'That call named ' . count( $requested ) . ' modules; this tool builds at most ' . self::MAX_MODULES . ' in one page.' );
    }

    // Every module is validated and sanitised BEFORE the page exists, so a refusal leaves
    // nothing behind to clean up.
    $built = [];
    $total = 0;
    foreach ( $requested as $index => $module ) {
      $one = $this->sanitise_module( $module, $index );
      if ( is_string( $one ) ) {
        return $this->error( $r, $one );
      }
      $total += $one['bytes'];
      if ( $total > self::MAX_TOTAL ) {
        return $this->error( $r, 'The modules carry more than ' . self::MAX_TOTAL . ' bytes of text between them; this tool builds a page, not a document store.' );
      }
      $built[] = $one;
    }

    $layout = $this->build_layout( $built );

    // Always created as a draft, and made live last, after the layout has been stored and read
    // back: creating it live first left a published empty page standing whenever the layout
    // write failed, and fired every publish hook for a page that then had to be deleted.
    try {
      $post_id = wp_insert_post( [
        'post_title'   => sanitize_text_field( $title ),
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_content' => '',
      ], true );
    } catch ( \Throwable $e ) {
      // A net under wp_insert_post throws BEFORE the row exists. Nothing was written.
      // The guards' own refusals are sentences written for the caller; any other exception is not.
      $why = $e instanceof GMCP_Drafts_Only_Refusal || $e instanceof GMCP_Policy_Refusal
        ? $e->getMessage()
        : 'WordPress threw ' . get_class( $e ) . '; the message is in the site error log.';
      if ( !( $e instanceof GMCP_Drafts_Only_Refusal || $e instanceof GMCP_Policy_Refusal ) ) {
        error_log( 'guarded-mcp beaver_create_page: ' . GMCP_Core::log_text( $e->getMessage() ) );
      }
      return $this->error( $r, 'The page could not be created, and nothing was written: ' . $why, -32600 );
    }
    if ( is_wp_error( $post_id ) ) {
      return $this->error( $r, 'The page could not be created: ' . $post_id->get_error_message() );
    }
    $post_id = (int) $post_id;

    // From here on a row exists. Any failure removes it again and says so, so the reply never
    // answers "failed" over a page that is still there.
    try {
      $this->write_layout( $post_id, $layout );
      $problem = $this->verify_stored( $post_id, $built );
      if ( $problem === null && $status === 'publish' ) {
        $published = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ], true );
        if ( is_wp_error( $published ) ) {
          $problem = 'The layout was stored but the page could not be published: ' . $published->get_error_message();
        } elseif ( get_post_status( $post_id ) !== 'publish' ) {
          $problem = 'The layout was stored but the page did not reach the publish status.';
        }
      }
    } catch ( \Throwable $e ) {
      $problem = 'Writing the layout failed (' . get_class( $e ) . '; the message is in the site error log).';
      error_log( 'guarded-mcp beaver_create_page: ' . GMCP_Core::log_text( $e->getMessage() ) );
    }

    if ( $problem !== null ) {
      // The removal can fail for the reason the write did (the builder clears its layout from a
      // delete hook), so it is not trusted to succeed: the reply says what is actually there.
      try {
        wp_delete_post( $post_id, true );
      } catch ( \Throwable $e ) {
        error_log( 'guarded-mcp beaver_create_page: removing the page failed: ' . GMCP_Core::log_text( $e->getMessage() ) );
      }
      clean_post_cache( $post_id );
      $left = get_post( $post_id ) instanceof \WP_Post;
      return $this->error( $r, $problem . ( $left
        ? ' The page it had created (ID ' . $post_id . ') could not be removed; delete it with wp_delete_post.'
        : ' The page it had created was removed again, so nothing is left behind.' ) );
    }

    // What was STORED, read from the table, not what was sent and not the builder's cache.
    $stored = $this->stored_layout( $post_id );
    $counts = [];
    $modules = [];
    foreach ( $stored as $node ) {
      $counts[ $node->type ] = ( $counts[ $node->type ] ?? 0 ) + 1;
      if ( $node->type === 'module' ) {
        $modules[] = (string) ( $node->settings->type ?? '' );
      }
    }
    ksort( $counts );
    sort( $modules );

    return $this->json( $r, [
      'ID' => $post_id,
      'status' => get_post_status( $post_id ),
      'builder_enabled' => (bool) get_post_meta( $post_id, '_fl_builder_enabled', true ),
      'stored_nodes' => count( $stored ),
      'stored_node_types' => $counts,
      'stored_module_types' => $modules,
      'note' => 'The reply is the layout read back out of the database, not the modules that were sent. This tool only creates: it has no argument that names an existing page. To remove this page use wp_delete_post; the change journal records the layout rows but has no entry for the creation of a page.' . ( $status === 'publish' ? '' : ' It is a draft; publish it from wp-admin when it looks right.' ),
    ] );
  }

  /** Write the layout through the builder's own writer. Throws; the caller removes the page. */
  private function write_layout( int $post_id, array $layout ): void {
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
  }

  /**
  * The layout row as the table holds it, bypassing every cache.
  *
  * FLBuilderModel::get_layout_data() returns the array update_layout_data() cached in the same
  * request, so a check made through it passes whatever reached the table.
  *
  * @return object[] Nodes by id, or [] when there is no readable row.
  */
  private function stored_layout( int $post_id ): array {
    global $wpdb;
    $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_fl_builder_data'", $post_id ) );
    if ( count( $rows ) !== 1 ) {
      return [];
    }
    $data = maybe_unserialize( $rows[0] );
    if ( !is_array( $data ) ) {
      return [];
    }
    $nodes = [];
    foreach ( $data as $id => $node ) {
      if ( is_object( $node ) && isset( $node->type ) ) {
        $nodes[ (string) $id ] = $node;
      }
    }
    return $nodes;
  }

  /**
  * Whether what is stored is what was built: the node counts, and the value of every field this
  * tool set on every module. Builder filters and the builder's own cleaning drop nodes without
  * saying so, and the tool must not call that a page.
  *
  * @return string|null A sentence, or null when the stored layout matches.
  */
  private function verify_stored( int $post_id, array $built ): ?string {
    $stored = $this->stored_layout( $post_id );
    $want = count( $built );
    $have = [ 'row' => 0, 'column-group' => 0, 'column' => 0, 'module' => 0 ];
    $mods = [];
    foreach ( $stored as $node ) {
      if ( isset( $have[ $node->type ] ) ) {
        $have[ $node->type ]++;
      }
      if ( $node->type === 'module' ) {
        $mods[] = $node;
      }
    }
    foreach ( $have as $type => $n ) {
      if ( $n !== $want ) {
        return 'The layout that reached the database holds ' . $n . ' ' . $type . ' node(s) where ' . $want . ' were built, so something between the tool and the table changed it.';
      }
    }
    // Modules in page order: by the position of the row each one hangs from.
    $row_position = function ( $node ) use ( $stored ) {
      $col = $stored[ (string) $node->parent ] ?? null;
      $grp = $col ? ( $stored[ (string) $col->parent ] ?? null ) : null;
      $row = $grp ? ( $stored[ (string) $grp->parent ] ?? null ) : null;
      return $row ? (int) $row->position : -1;
    };
    usort( $mods, function ( $a, $b ) use ( $row_position ) {
      return $row_position( $a ) <=> $row_position( $b );
    } );
    foreach ( $built as $i => $module ) {
      $node = $mods[ $i ] ?? null;
      if ( !$node || !is_object( $node->settings ?? null ) || ( $node->settings->type ?? '' ) !== $module['type'] ) {
        return 'Module ' . $i . ' is not in the stored layout as a ' . $module['type'] . ' module.';
      }
      foreach ( $module['settings'] as $key => $value ) {
        if ( !isset( $node->settings->$key ) || (string) $node->settings->$key !== (string) $value ) {
          return 'Module ' . $i . ' (' . $module['type'] . ') was stored with a different "' . $key . '" than the one that was built, so something between the tool and the table changed it.';
        }
      }
    }
    if ( !get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
      return 'The layout was stored but the builder is not enabled on the page.';
    }
    return null;
  }

  /**
  * One module, validated and sanitised, as a node waiting for its parent.
  *
  * @return array|string The node, or a refusal sentence.
  */
  private function sanitise_module( $module, int $index ) {
    if ( !is_array( $module ) ) {
      return 'Module ' . $index . ' is not an object.';
    }
    $type = isset( $module['type'] ) && is_string( $module['type'] ) ? $module['type'] : '';
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
    $bytes = 0;
    foreach ( [ 'text', 'link' ] as $field ) {
      if ( isset( $module[ $field ] ) && is_string( $module[ $field ] ) ) {
        $bytes += strlen( $module[ $field ] );
        if ( strlen( $module[ $field ] ) > self::MAX_TEXT ) {
          return 'Module ' . $index . ' (' . $type . ') carries ' . strlen( $module[ $field ] ) . ' bytes in "' . $field . '"; this tool takes at most ' . self::MAX_TEXT . ' per field.';
        }
      }
    }

    $out = [];
    switch ( $type ) {
      case 'heading':
        $text = $this->plain_text( $module['text'] ?? '' );
        if ( $text === '' ) {
          return 'Module ' . $index . ' (heading) has no text.';
        }
        $tag = isset( $module['tag'] ) ? strtolower( is_string( $module['tag'] ) ? $module['tag'] : '' ) : 'h2';
        if ( !in_array( $tag, self::TAGS, true ) ) {
          return 'Module ' . $index . ' (heading) asks for an unsupported tag. Use one of: ' . implode( ', ', self::TAGS ) . '. The tag is an element name in Beaver\'s output, so free text there is an injection point.';
        }
        // The module's own field is `heading`, and its renderer echoes it RAW, so the sanitiser
        // has to run here rather than being trusted to the builder. (This was stored under
        // `text`, which the heading module never reads: the page rendered an empty heading.)
        $out = [ 'heading' => $text, 'tag' => $tag ];
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
        if ( $link['error'] !== null ) {
          return $link['error'];
        }
        $target = isset( $module['link_target'] ) && is_string( $module['link_target'] ) ? $module['link_target'] : '_self';
        if ( !in_array( $target, self::TARGETS, true ) ) {
          return 'Module ' . $index . ' (button) asks for an unsupported target. Use _self or _blank.';
        }
        $out = [ 'text' => $text, 'link' => $link['url'], 'link_target' => $target ];
        break;

      case 'photo':
        $att = isset( $module['photo'] ) && is_scalar( $module['photo'] ) ? (int) $module['photo'] : 0;
        $why = $this->photo_refusal( $att );
        if ( $why !== null ) {
          return 'Module ' . $index . ' (photo): ' . $why;
        }
        // The id, never a URL from the caller. The module renders `photo_src`, not the id, so the
        // address is derived here from the attachment (a caller's own `photo_src` is refused by
        // name): without it the module rendered Beaver's placeholder pixel.
        $out = [ 'photo' => $att, 'photo_source' => 'library', 'photo_src' => esc_url_raw( (string) wp_get_attachment_url( $att ) ) ];
        break;
    }

    return [ 'type' => $type, 'settings' => $out, 'bytes' => $bytes ];
  }

  /**
  * Why this id may not be a photo module's image, or null when it may.
  *
  * The module dereferences the id when the page is RENDERED, for every visitor, so it is a
  * pointer to a post exactly as a featured image is, and the same policy applies: the one
  * function that judges `_thumbnail_id` is asked, so the two cannot drift apart (a visitor's
  * upload attached to a form submission was accepted here while wp_update_post_meta refused it
  * as a featured image: measured).
  */
  private function photo_refusal( int $att ): ?string {
    if ( $att < 1 || get_post_type( $att ) !== 'attachment' ) {
      return 'needs "photo" set to an existing attachment id.';
    }
    $read = GMCP_Core::post_read_guard( $att );
    if ( $read !== true ) {
      return $read;
    }
    $pointer = GMCP_Core::pointer_meta_refusal( '_thumbnail_id', $att, 0 );
    if ( $pointer !== null ) {
      return str_replace( 'as a featured image', 'as a photo', $pointer );
    }
    if ( !wp_attachment_is_image( $att ) ) {
      return 'attachment #' . $att . ' is not an image, and a photo module would print the file as one.';
    }
    if ( !wp_get_attachment_url( $att ) ) {
      return 'attachment #' . $att . ' has no file to show.';
    }
    return null;
  }

  /**
  * A layout graph the way the builder's own add_row() / add_col_group() / add_module() make
  * one: row > column-group > column > module, one of each per module, with the builder's
  * default settings under the fields this tool sets.
  *
  * Built here rather than accepted from the caller, so the parent/position chain is valid by
  * construction. The column-group level was missing once (row > column > module), and the page
  * stored twelve nodes, answered success and rendered no module at all: Beaver's renderer
  * walks row > group > column and skips what does not fit. The suite renders the page now.
  */
  private function build_layout( array $built ): array {
    $layout = [];
    $new_id = function () use ( &$layout ) {
      do {
        $id = (string) \FLBuilderModel::generate_node_id();
      } while ( isset( $layout[ $id ] ) );
      return $id;
    };
    $row_defaults = \FLBuilderModel::get_row_defaults();
    foreach ( $built as $position => $module ) {
      $row = $new_id();
      $layout[ $row ] = (object) [ 'node' => $row, 'type' => 'row', 'parent' => null, 'position' => $position, 'settings' => is_object( $row_defaults ) ? clone $row_defaults : (object) [] ];
      $group = $new_id();
      $layout[ $group ] = (object) [ 'node' => $group, 'type' => 'column-group', 'parent' => $row, 'position' => 0, 'settings' => '' ];
      $col = $new_id();
      $layout[ $col ] = (object) [ 'node' => $col, 'type' => 'column', 'parent' => $group, 'position' => 0, 'settings' => (object) [ 'size' => 100 ] ];
      // A module node is `type = 'module'` with the module's slug in settings->type; a node
      // whose type is the slug ('heading') is skipped by the renderer.
      $defaults = \FLBuilderModel::get_module_defaults( $module['type'] );
      $settings = is_object( $defaults ) ? clone $defaults : (object) [];
      foreach ( $module['settings'] as $key => $value ) {
        $settings->$key = $value;
      }
      $settings->type = $module['type'];
      $node = $new_id();
      $layout[ $node ] = (object) [ 'node' => $node, 'type' => 'module', 'parent' => $col, 'position' => 0, 'settings' => $settings ];
    }
    return $layout;
  }

  #endregion

  #region Sanitising

  /**
  * Text that must stay literal: no tags, no shortcode brackets.
  *
  * Beaver's heading and button renderers echo their text RAW. Brackets are neutralised because
  * the assembled layout can pass through do_shortcode() when it is rendered (a shortcode is a
  * way to run code from a string, and this tool's input is a string from a model), and a stray
  * angle bracket that strip_tags left is written as the entity it means.
  */
  private function plain_text( $value ): string {
    $text = is_scalar( $value ) ? (string) $value : '';
    $text = wp_strip_all_tags( $text );
    $text = str_replace( [ '<', '>', '[', ']' ], [ '&lt;', '&gt;', '&#91;', '&#93;' ], $text );
    return trim( $text );
  }

  /**
  * Text that may carry formatting, but no script and no shortcodes.
  *
  * wp_kses_post is WordPress's own allowlist for post content: it strips script, event handlers
  * and embeds. Shortcode brackets are neutralised for the same reason as above.
  *
  * A line that is only a URL is turned into a link first. The rich-text module renders through
  * WP_Embed::autoembed(), which turns such a line into an oEmbed: the server then fetches an
  * address the caller chose, every time the page renders and no one has been asked.
  */
  private function html_text( $value ): string {
    $text = is_scalar( $value ) ? (string) $value : '';
    $text = preg_replace_callback( '~^([ \t]*)(https?://[^\s"<>]+)([ \t]*)$~im', function ( $m ) {
      return $m[1] . '<a href="' . esc_url( $m[2] ) . '">' . esc_html( $m[2] ) . '</a>' . $m[3];
    }, $text ) ?? '';
    $text = wp_kses_post( $text );
    $text = str_replace( [ '[', ']' ], [ '&#91;', '&#93;' ], $text );
    return trim( $text );
  }

  /**
  * A link this site can justify.
  *
  * Beaver renders the link through esc_url(), which strips javascript: and data: but KEEPS a
  * protocol-relative "//host" — measured on this stack. So the value is judged AFTER sanitising,
  * on what will be stored, and in the form a browser reads it: a browser deletes tabs and
  * newlines inside a URL, so "/<tab>/evil.example" is "//evil.example" to it, and esc_url_raw()
  * deletes the same characters (the check used to run on the raw value for a path and was
  * bypassed by exactly that).
  *
  * Accepted: a path on this site ("/x", not "//x" and not "/\x"), or an absolute http(s) URL with
  * a host and no user information. "http:evil.example" is neither: it is a relative reference on
  * an http page and an absolute one on an https page, and "https://trusted@evil.example" shows a
  * host that is not the destination.
  *
  * @return array{url:string,error:?string}
  */
  private function safe_link( $value, int $index ): array {
    $fail = function ( string $why ) use ( $index ) {
      return [ 'url' => '', 'error' => 'Module ' . $index . ' (button) link ' . $why . ' Give an absolute http(s) URL or a path on this site.' ];
    };
    $raw = is_scalar( $value ) ? trim( (string) $value ) : '';
    if ( $raw === '' ) {
      return [ 'url' => '', 'error' => null ];
    }
    $seen = preg_replace( '/[\x00-\x1f\x7f]/', '', $raw );
    if ( $seen === '' || $seen === null ) {
      return $fail( 'is not a URL this tool will store.' );
    }
    if ( preg_match( '~^/[/\\\\]~', $seen ) ) {
      return $fail( 'is protocol-relative and points at another host; Beaver\'s esc_url keeps it.' );
    }
    $is_path = $seen[0] === '/';
    if ( !$is_path && !preg_match( '~^https?://[^/?#\s]+~i', $seen ) ) {
      return $fail( 'must be http(s) with a host, or a path beginning with "/".' );
    }
    $clean = esc_url_raw( $seen, [ 'http', 'https' ] );
    if ( $clean === '' ) {
      return $fail( 'is not a URL this tool will store.' );
    }
    if ( preg_match( '~^/[/\\\\]~', $clean ) ) {
      return $fail( 'sanitises to a protocol-relative target.' );
    }
    if ( !$is_path ) {
      $parts = wp_parse_url( $clean );
      if ( empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || !in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
        return $fail( 'has no host, or carries user information ("user@host" shows a host that is not the destination).' );
      }
    }
    return [ 'url' => $clean, 'error' => null ];
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
