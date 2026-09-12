<?php

/*
 * Derived from AI Engine 3.7.7 (labs/mcp-core.php), Copyright (C) Jordy Meow,
 * GPLv2 or later. Modified 2026 by Jorge Barnaby: renamed throughout, block-aware HTML sanitising, protected options,
 * preview mode, and the change-journal tools.
 * See the plugin header in guarded-mcp.php for the full attribution.
 */

class GMCP_Tools_Core {
  private $core = null;

  #region Initialize
  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }
  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_rest_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }
  #endregion

  #region Helpers
  private function add_result_text( array &$r, string $text ): void {
    if ( !isset( $r['result']['content'] ) ) {
      $r['result']['content'] = [];
    }
    $r['result']['content'][] = [ 'type' => 'text', 'text' => $text ];
  }

  /**
  * A tool failure the model is supposed to read and act on.
  *
  * These used to be JSON-RPC errors. A protocol error carries no result at all, so a
  * client reading result.content found nothing there, called the response malformed and
  * discarded it whole, including on calls that had already done their work: that is how
  * a created post came back with no readable ID. An isError result is handed to the
  * model as the tool's answer instead, which is what a refusal is for.
  *
  * -32601 is "method not found", a genuine protocol-level condition, so that one stays a
  * real JSON-RPC error. Everything else is an outcome. tools-admin.php and server.php's
  * catch block draw the line in the same place.
  *
  * The code is kept in the text because the result shape has nowhere else to put it, and
  * it is what separates a bad argument from a failed write. Several callers pass a
  * WP_Error code, which says more than either.
  */
  private function error( array $r, string $message, int|string $code = -32603 ): array {
    if ( $code === -32601 ) {
      unset( $r['result'] );
      $r['error'] = [ 'code' => $code, 'message' => $message ];
      return $r;
    }
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message . ' [error ' . $code . ']' ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }
  private function clean_html( string $v ): string {
    return wp_kses_post( wp_unslash( $v ) );
  }

  /**
  * Whether this site has opted into storing raw HTML in post content.
  *
  * Off by default. A site that genuinely needs an iframe, inline SVG, a <style> block
  * or Outlook conditional comments written by an agent can turn it on, the same way
  * gmcp_allow_remote_install and gmcp_allow_unfiltered_widget_html work.
  */
  private function raw_post_html_allowed(): bool {
    return (bool) apply_filters( 'gmcp_allow_unfiltered_post_html', false );
  }

  /**
  * Post content, sanitized.
  *
  * This used to store verbatim for anyone holding unfiltered_html, which reads as a
  * privilege check and is not one: a bearer-token request runs as an administrator and
  * the OAuth path already demands manage_options, so the capability is always true and
  * the sanitizing branch was unreachable. A script tag written through wp_create_post
  * therefore executed on the public page, and because these tools sit at the "write"
  * access level, a token deliberately limited to "read and write, no destructive tools"
  * could do it. The capability describes who sent the request; the risk is about who
  * wrote the markup, and an agent relays content anonymous people authored.
  */
  private function store_html( string $v ): string {
    if ( $this->raw_post_html_allowed() ) {
      return $v;
    }
    return $this->sanitize_post_html( $v );
  }

  /**
  * Sanitize post content without destroying Gutenberg.
  *
  * wp_kses_post() cannot be pointed at block markup wholesale. Block attributes live in
  * the delimiter comment as JSON, and Gutenberg escapes quotes and angle brackets inside
  * it as HTML entities. kses does not recognise such a comment and escapes the whole
  * "<!--" opener, which corrupts the block irrecoverably. Any block storing rich text in
  * an attribute hits this, and so does any attribute containing an apostrophe.
  *
  * So the block structure is walked instead: only the rendered HTML inside each block is
  * filtered, and the attributes are left to core to decode and re-encode as JSON, which
  * never routes them through kses at all. They come back semantically identical, with
  * "<" re-encoded as "\u003c", which is Gutenberg's other escaping form and parses back
  * to the same value.
  *
  * Content with no block delimiters is ordinary HTML and is filtered directly.
  */
  private function sanitize_post_html( string $v ): string {
    if ( strpos( $v, '<!-- wp:' ) === false ) {
      return wp_kses_post( $v );
    }
    $filter = function ( array $blocks ) use ( &$filter ) {
      foreach ( $blocks as &$block ) {
        if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
          $block['innerHTML'] = wp_kses_post( $block['innerHTML'] );
        }
        if ( !empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
          foreach ( $block['innerContent'] as &$chunk ) {
            if ( is_string( $chunk ) ) {
              $chunk = wp_kses_post( $chunk );
            }
          }
          unset( $chunk );
        }
        if ( !empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
          $block['innerBlocks'] = $filter( $block['innerBlocks'] );
        }
      }
      return $blocks;
    };
    return serialize_blocks( $filter( parse_blocks( $v ) ) );
  }

  // Return stored post_content verbatim for read tools (wp_get_post, snapshot).
  // The DB value is NOT slashed, so clean_html()'s wp_unslash() would strip the
  // real backslash from block-JSON Unicode escapes (Gutenberg's \uXXXX form for
  // < and >, as Rank Math FAQ blocks use) and wp_kses_post() would drop the
  // admin-authored markup that store_html() preserved on write. A read must
  // round-trip losslessly through store_html(), so it returns the value as-is.
  private function read_html( string $v ): string {
    return $v;
  }

  // Prepare post_content for wp_create_post. If the caller already sent HTML,
  // Gutenberg blocks, or shortcodes, keep it as-is (sanitized like the update
  // path) instead of running the markdown parser. Parsedown would HTML-encode
  // the quotes in shortcode attributes ([x a="b"] -> a=&quot;b&quot;), auto-link
  // URLs, and <p>-wrap lines, silently breaking shortcode rendering. Markdown
  // conversion is reserved for plain prose with no existing markup.
  private function prepare_new_content( string $v ): string {
    $hasBlocks = strpos( $v, '<!-- wp:' ) !== false;
    $hasHtml = (bool) preg_match( '/<(?:p|div|h[1-6]|ul|ol|li|figure|table|blockquote|section|img|a|br|span|strong|em)\b[^>]*>/i', $v );
    // Generic shortcode detection (independent of whether the shortcode is
    // registered on THIS site): an attribute assignment inside brackets
    // [name attr="x"] or a closing [/name]. Deliberately does not match a
    // Markdown link [text](url), which has neither "=" nor a leading slash.
    $hasShortcode = (bool) preg_match( '/\[[a-zA-Z][\w-]*\s+[^\]]*?=[^\]]*\]|\[\/[a-zA-Z]/', $v );
    if ( $hasBlocks || $hasHtml || $hasShortcode ) {
      return $this->store_html( $v );
    }
    // Also through store_html: Parsedown runs without safe mode, so it passes raw HTML
    // through untouched, and the sniff above deliberately does not look for <script>,
    // which means a bare script tag took this branch and skipped sanitising entirely.
    return $this->store_html( $this->core->markdown_to_html( $v ) );
  }

  // Recursively blank out every block's attributes. Gallery/media blocks (e.g.
  // meow-gallery) store their whole image list as JSON in the block-delimiter
  // comment, which can be hundreds of KB and overflows the tool's token cap on
  // read. Keep the small delimiter marker and the inner prose/HTML.
  private function strip_block_attrs( array $blocks ): array {
    foreach ( $blocks as &$b ) {
      $b['attrs'] = [];
      if ( !empty( $b['innerBlocks'] ) ) {
        $b['innerBlocks'] = $this->strip_block_attrs( $b['innerBlocks'] );
      }
    }
    unset( $b );
    return $blocks;
  }

  // Return the post content with block-attribute JSON stripped, so a gallery-heavy
  // post collapses to its few KB of actual prose without re-rendering any block.
  private function prose_content( string $v ): string {
    // $v is raw post_content from get_post(), which is NOT slashed; wp_unslash()
    // here would strip the real backslash from block-JSON Unicode escapes (the
    // \uXXXX form Gutenberg uses for < and > in Rank Math FAQ etc.) and corrupt it.
    return trim( serialize_blocks( $this->strip_block_attrs( parse_blocks( $v ) ) ) );
  }
  private function post_excerpt( WP_Post $p ): string {
    return wp_trim_words( wp_strip_all_tags( $p->post_excerpt ?: $p->post_content ), 55 );
  }
  private function empty_schema(): array {
    return [ 'type' => 'object', 'properties' => (object) [] ];
  }

  // v1 block types accepted by wp_write_blocks. Kept intentionally small and
  // conservative: only core blocks whose canonical save markup is stable across
  // WP 6.x, so the output opens in the block editor without "invalid content"
  // warnings. The 'html' type is the escape hatch for anything not covered.
  private static $write_block_types = [
    'paragraph', 'heading', 'list', 'quote', 'image', 'buttons',
    'group', 'columns', 'separator', 'spacer', 'code', 'html',
  ];

  // Render a simplified block-spec array into canonical Gutenberg markup.
  // Returns [ markup, error ] with exactly one non-null. Aborts on the first bad
  // block so we never write a half-built page.
  private function blocks_to_markup( $blocks, string $path = 'blocks' ): array {
    if ( !is_array( $blocks ) || $blocks === [] ) {
      return [ null, $path . ' must be a non-empty array of block specs.' ];
    }
    $out = [];
    foreach ( $blocks as $i => $block ) {
      $at = $path . '[' . $i . ']';
      if ( !is_array( $block ) || empty( $block['type'] ) || !is_string( $block['type'] ) ) {
        return [ null, $at . ' is missing a string "type".' ];
      }
      if ( !in_array( $block['type'], self::$write_block_types, true ) ) {
        return [ null, $at . ' has unsupported type "' . $block['type'] . '". Supported: ' . implode( ', ', self::$write_block_types ) . '.' ];
      }
      list( $markup, $err ) = $this->render_block_spec( $block['type'], $block, $at );
      if ( $err !== null ) {
        return [ null, $err ];
      }
      $out[] = $markup;
    }
    return [ implode( "\n\n", $out ), null ];
  }

  // Build the canonical markup for one supported block. Returns [ markup, error ].
  private function render_block_spec( string $type, array $b, string $at ): array {
    switch ( $type ) {
      case 'paragraph':
        return [ "<!-- wp:paragraph -->\n<p>" . $this->clean_html( $b['content'] ?? '' ) . "</p>\n<!-- /wp:paragraph -->", null ];

      case 'heading':
        $level = isset( $b['level'] ) ? (int) $b['level'] : 2;
        if ( $level < 1 || $level > 6 ) {
          return [ null, $at . ' heading level must be between 1 and 6.' ];
        }
        $attrs = $level === 2 ? '' : ' ' . wp_json_encode( [ 'level' => $level ] );
        $text = $this->clean_html( $b['content'] ?? '' );
        return [ '<!-- wp:heading' . $attrs . " -->\n<h" . $level . ' class="wp-block-heading">' . $text . '</h' . $level . ">\n<!-- /wp:heading -->", null ];

      case 'list':
        $items = $b['items'] ?? null;
        if ( !is_array( $items ) || $items === [] ) {
          return [ null, $at . ' list requires a non-empty "items" array of strings.' ];
        }
        $ordered = !empty( $b['ordered'] );
        $tag = $ordered ? 'ol' : 'ul';
        $listAttrs = $ordered ? ' ' . wp_json_encode( [ 'ordered' => true ] ) : '';
        $lis = '';
        foreach ( $items as $it ) {
          $lis .= "<!-- wp:list-item -->\n<li>" . $this->clean_html( is_string( $it ) ? $it : '' ) . "</li>\n<!-- /wp:list-item -->\n";
        }
        return [ '<!-- wp:list' . $listAttrs . " -->\n<" . $tag . ' class="wp-block-list">' . rtrim( $lis, "\n" ) . '</' . $tag . ">\n<!-- /wp:list -->", null ];

      case 'quote':
        $qInner = "<!-- wp:paragraph -->\n<p>" . $this->clean_html( $b['content'] ?? '' ) . "</p>\n<!-- /wp:paragraph -->";
        $cite = ( isset( $b['citation'] ) && $b['citation'] !== '' ) ? '<cite>' . $this->clean_html( $b['citation'] ) . '</cite>' : '';
        return [ "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . $qInner . $cite . "</blockquote>\n<!-- /wp:quote -->", null ];

      case 'image':
        $url = esc_url_raw( $b['url'] ?? '' );
        if ( $url === '' ) {
          return [ null, $at . ' image requires a "url".' ];
        }
        $alt = esc_attr( $b['alt'] ?? '' );
        $caption = ( isset( $b['caption'] ) && $b['caption'] !== '' ) ? '<figcaption class="wp-element-caption">' . $this->clean_html( $b['caption'] ) . '</figcaption>' : '';
        $img = '<img src="' . $url . '" alt="' . $alt . '"/>';
        return [ "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $img . $caption . "</figure>\n<!-- /wp:image -->", null ];

      case 'buttons':
        $buttons = $b['buttons'] ?? null;
        if ( !is_array( $buttons ) || $buttons === [] ) {
          return [ null, $at . ' buttons requires a non-empty "buttons" array of {text, url}.' ];
        }
        $btnInner = '';
        foreach ( $buttons as $bi => $btn ) {
          if ( !is_array( $btn ) || empty( $btn['text'] ) ) {
            return [ null, $at . ' button[' . $bi . '] requires "text".' ];
          }
          $href = esc_url_raw( $btn['url'] ?? '' );
          $hrefAttr = $href !== '' ? ' href="' . $href . '"' : '';
          $btnInner .= "<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\"" . $hrefAttr . '>' . $this->clean_html( $btn['text'] ) . "</a></div>\n<!-- /wp:button -->\n";
        }
        return [ "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">" . rtrim( $btnInner, "\n" ) . "</div>\n<!-- /wp:buttons -->", null ];

      case 'group':
        list( $gInner, $gErr ) = $this->blocks_to_markup( $b['blocks'] ?? null, $at . '.blocks' );
        if ( $gErr !== null ) {
          return [ null, $gErr ];
        }
        return [ "<!-- wp:group -->\n<div class=\"wp-block-group\">" . $gInner . "</div>\n<!-- /wp:group -->", null ];

      case 'columns':
        $columns = $b['columns'] ?? null;
        if ( !is_array( $columns ) || $columns === [] ) {
          return [ null, $at . ' columns requires a non-empty "columns" array (an array of block-spec arrays).' ];
        }
        $colsInner = '';
        foreach ( $columns as $ci => $colBlocks ) {
          list( $colInner, $colErr ) = $this->blocks_to_markup( $colBlocks, $at . '.columns[' . $ci . ']' );
          if ( $colErr !== null ) {
            return [ null, $colErr ];
          }
          $colsInner .= "<!-- wp:column -->\n<div class=\"wp-block-column\">" . $colInner . "</div>\n<!-- /wp:column -->\n";
        }
        return [ "<!-- wp:columns -->\n<div class=\"wp-block-columns\">" . rtrim( $colsInner, "\n" ) . "</div>\n<!-- /wp:columns -->", null ];

      case 'separator':
        return [ "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->", null ];

      case 'spacer':
        $h = isset( $b['height'] ) ? (int) $b['height'] : 100;
        if ( $h < 1 || $h > 2000 ) {
          return [ null, $at . ' spacer height must be between 1 and 2000 (px).' ];
        }
        return [ '<!-- wp:spacer ' . wp_json_encode( [ 'height' => $h . 'px' ] ) . " -->\n<div style=\"height:" . $h . "px\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->", null ];

      case 'code':
        return [ "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . esc_html( wp_unslash( (string) ( $b['content'] ?? '' ) ) ) . "</code></pre>\n<!-- /wp:code -->", null ];

      case 'html':
        // core/html stores raw HTML and is always valid on re-open. Sanitize to post-safe HTML.
        return [ "<!-- wp:html -->\n" . $this->clean_html( $b['content'] ?? '' ) . "\n<!-- /wp:html -->", null ];
    }
    return [ null, $at . ' could not be rendered.' ];
  }

  /**
   * Compile a wp_alter_post regex search into a delimited PCRE pattern.
   *
   * The documented contract is a BARE pattern plus an optional flags string; we wrap it
   * with a safe delimiter internally. This is what makes Gutenberg block markers work:
   * they contain "/" (e.g. <!-- /wp:paragraph -->), which collides with the "/" delimiter,
   * so "/" is tried last when picking a delimiter. For backward compatibility a pattern
   * that already compiles as a fully delimited PCRE (and no separate flags were given) is
   * honored as-is. Returns [ compiled, error ]; exactly one is non-null.
   */
  private function compile_alter_regex( string $pattern, string $flags = '' ): array {
    $flags = trim( $flags );
    if ( $flags !== '' && !preg_match( '/^[imsxuADSUXJ]+$/', $flags ) ) {
      return [ null, 'Invalid regex flags "' . $flags . '". Allowed: i, m, s, x, u, A, D, S, U, X, J.' ];
    }

    // Backward compat: an already-delimited pattern that compiles is used verbatim.
    if ( $flags === '' && $pattern !== '' && $this->preg_compile_error( $pattern ) === null ) {
      return [ $pattern, null ];
    }

    // Bare pattern: wrap with the first delimiter not present in the pattern ("/" last).
    $delimiter = '';
    foreach ( [ '~', '#', '%', '!', '@', '/' ] as $candidate ) {
      if ( strpos( $pattern, $candidate ) === false ) {
        $delimiter = $candidate;
        break;
      }
    }
    if ( $delimiter === '' ) {
      // Pattern uses every candidate; fall back to "~" and escape its occurrences.
      $delimiter = '~';
      $pattern = str_replace( '~', '\~', $pattern );
    }
    $compiled = $delimiter . $pattern . $delimiter . $flags;

    $err = $this->preg_compile_error( $compiled );
    if ( $err !== null ) {
      return [ null, 'Invalid regex pattern: ' . $err . ' (compiled to ' . $compiled . ')' ];
    }
    return [ $compiled, null ];
  }

  /**
   * Test-compile a PCRE pattern without emitting warnings. Returns null on success, or a
   * human-readable PCRE error message (echoing the real engine message when available).
   */
  private function preg_compile_error( string $pattern ): ?string {
    set_error_handler( fn () => true );
    $result = preg_match( $pattern, '' );
    restore_error_handler();
    if ( $result !== false ) {
      return null;
    }
    return function_exists( 'preg_last_error_msg' )
      ? preg_last_error_msg()
      : 'PCRE error code ' . preg_last_error();
  }

  /**
   * Bust post caches after a write so a follow-up wp_get_post in the next request
   * returns fresh data on sites with persistent object caches (Redis, Memcached) or
   * page caches (LiteSpeed, WP Rocket, Cloudflare, etc.). wp_insert_post / wp_update_post
   * call clean_post_cache themselves; this is idempotent and also fans out third-party
   * purge hooks plus a generic gmcp_post_changed action so sites can wire their own.
   *
   * Per-request dedupe: agentic clients often hit the same post several times in quick
   * succession (e.g. wp_alter_post twice on the same page within the same JSON-RPC call),
   * which would multiply expensive third-party purges (Cloudflare global, Algolia reindex).
   * We keep a static set of post IDs already busted in this PHP request and short-circuit
   * repeats. The $context array is forwarded to gmcp_post_changed so handlers can
   * coalesce or defer purges across requests on their own (e.g. flush at end of batch).
   */
  /**
  * Option names are case-sensitive in the database, but sanitize_key() lowercases,
  * so an option such as "WPLANG" was silently unreadable and a write to it created a
  * second, lowercase row instead. Strip only what cannot legally appear in an option
  * name and leave the case alone.
  */
  private function clean_option_key( $key ): string {
    return trim( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key ) );
  }

  /**
  * Whether a post meta key can be stored as the caller wrote it, and if not, why not.
  *
  * The five meta tools used to put the key through sanitize_key(), which lowercases and
  * drops everything outside a-z, 0-9, "_" and "-". WordPress stores far more than that,
  * so "myPlugin_Data" was unreachable: a read addressed "myplugin_data" and got the wrong
  * row or none, a write created that other row instead, and nothing said the key had been
  * changed. The key is now kept exactly as given, and the two cases below are refused
  * rather than rewritten, because both end with a row under a name nobody asked for.
  *
  * No unslashing happens here, and that is deliberate. Arguments reach this class from
  * json_decode() of the raw request body in GMCP_Server, never from $_POST, so nothing
  * added slashes on the way in and wp_unslash() would eat the backslashes out of a key
  * that has them of its own. Slashing on the way OUT is a different question and belongs
  * at each call site, because the two halves of WordPress disagree: add_metadata(),
  * update_metadata() and delete_metadata() all unslash the key, and get_metadata() does
  * not. So a write passes wp_slash( $key ) and a read passes $key, and only then do the
  * writer and the reader mean the same row.
  *
  * There is nothing to smuggle past by keeping the case. No guard stands in front of a
  * meta key at any access level, and the one case-sensitive comparison in this file,
  * META_KEYS_NEVER_COPIED, is made against keys already present on the source post rather
  * than against anything a caller typed. GMCP_Core::field_looks_secret() lowercases what
  * it is handed before matching, so redaction is unaffected either way.
  *
  * @return true|string True if the key is usable, otherwise the refusal message.
  */
  private function meta_key_allowed( string $key ) {
    // Both strings WordPress itself calls "no key". add_metadata(), update_metadata(),
    // delete_metadata() and get_metadata_raw() all gate on ! $meta_key, which is false for
    // "" and for "0" alike. Measured: update_post_meta( $id, "0", ... ) returns false and
    // writes nothing, and a row forced into wp_postmeta under "0" cannot be read back or
    // deleted through WordPress at all, because get_post_meta( $id, "0" ) answers with the
    // whole set instead. Refusing says that; letting it through would have this tool
    // report a write that cannot happen, or answer a read of one key with every key.
    if ( $key === '' || $key === '0' ) {
      return 'A meta key is required, and ' . ( $key === '' ? 'the empty string' : 'the string "0"' )
        . ' is not one: WordPress tests a meta key for truth before using it, so "" and "0" both mean "no key given" to it. Nothing can be written under either, and a read of one answers with every key on the post instead.';
    }
    // wp_postmeta.meta_key is varchar(255), counted in characters and not in bytes: a
    // 255-character multibyte key stores whole, measured. Past 255 nothing is truncated,
    // which is the good news and the reason to refuse here rather than let it through:
    // wpdb rejects the field ("Processing the value for the following field failed:
    // meta_key"), update_post_meta() returns false and writes nothing, and these tools do
    // not read that return value, so the answer would have been "Meta updated" over an
    // empty result. Refusing on the way in is the same outcome with the truth attached.
    $length = mb_strlen( $key );
    if ( $length > 255 ) {
      return 'The meta key is ' . $length . ' characters. wp_postmeta.meta_key holds 255, and a longer one is refused by the database rather than shortened, so no row can exist under it and no write to it would store anything. Use a key of 255 characters or fewer.';
    }
    return true;
  }

  /**
  * The spelling a post's meta is actually filed under, when it is not the one asked for.
  *
  * Keeping the key verbatim fixes the half of this that was the plugin's doing. The other
  * half belongs to the database and cannot be fixed from here, only reported. Row lookup
  * uses the column's collation, utf8mb4_unicode_520_ci on a stock WordPress, so
  * "myPlugin_Data" and "myplugin_data" are one row to every SELECT, UPDATE and DELETE
  * WordPress runs. Reads are not: get_metadata() answers out of the meta cache, a PHP
  * array keyed by the spelling in the row, and PHP array keys compare exactly.
  *
  * So a write aimed at a key that differs only in case from one already on the post lands
  * in that row, leaves its spelling alone, and is then invisible to a read of the key that
  * was just written. Measured, not reasoned: writing "Existing_Key" over a row stored as
  * "existing_key" leaves one row, still called "existing_key", holding the new value,
  * and get_post_meta( $id, "Existing_Key" ) then answers "". Nothing errors anywhere.
  *
  * The writers call this after the write and say so when it happened, which is the one
  * thing the caller cannot find out for itself.
  *
  * @return string The differing spelling, or '' when the row is filed exactly as asked.
  */
  private function meta_key_stored_as( int $post_id, string $key ): string {
    global $wpdb;
    $found = $wpdb->get_col( $wpdb->prepare(
      "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
      $post_id,
      $key
    ) );
    // A site on a case-sensitive collation can hold both spellings at once, and then the
    // exact one is the row that was written and there is nothing to report.
    if ( !$found || in_array( $key, $found, true ) ) {
      return '';
    }
    return (string) reset( $found );
  }

  /**
  * @see GMCP_Core::option_guard() for the rule and why it lives there.
  * @return true|string True if the key is allowed, otherwise the refusal message.
  */
  private function option_allowed( string $key ) {
    return GMCP_Core::option_guard( $key );
  }

  /**
  * Options that may not be deleted, each with what actually breaks if it goes.
  *
  * These are separate from option_guard(), which is about secrecy. These are rows
  * WordPress either cannot run without or cannot rebuild, so losing one is not a
  * recoverable mistake. The value is the sentence the refusal says, so the reason and
  * the rule cannot drift apart.
  *
  * rewrite_rules is deliberately NOT here. It is a derived cache, WordPress regenerates
  * it on the next permalink flush, and deleting it is a normal repair for broken
  * permalinks rather than damage.
  */
  private const OPTIONS_NEVER_DELETED = [
    'siteurl' => 'WordPress builds every URL from it, so with the row gone the site cannot resolve its own address; wp-admin and this endpoint included, which means there is no way back in through the API that deleted it.',
    'home' => 'Same as siteurl: the front page and every link on it become unreachable, and so does the route this request arrived on.',
    'template' => 'It names the active theme. With no theme WordPress has nothing to render the front end with.',
    'stylesheet' => 'It names the active child or parent theme. Removing it leaves WordPress looking for a theme directory that is not identified anywhere.',
    'active_plugins' => 'Every plugin deactivates at once, this one among them, so the API that deleted the row is no longer running to restore it.',
    'db_version' => 'It records which schema the database is on. Missing, WordPress runs its upgrade routine against a schema it can no longer place.',
    'initial_db_version' => 'It records the schema the site was installed at, which upgrade routines read to decide what to skip.',
    'cron' => 'The entire schedule is this one row. Deleting it silently drops every scheduled event on the site: publishing, backups, renewals, WooCommerce actions. Nothing errors; things just stop happening.',
    'admin_email' => 'It is where recovery mail, password resets and fatal-error notices go. Without it nobody is told when the site is in trouble.',
  ];

  /**
  * A meta value as it should be STORED, from a value that arrived as a tool argument.
  *
  * Two corrections, and they live here rather than in a caller for the reason
  * option_write_policy() gives about options: three tools write post meta, and until this
  * existed they disagreed about both. wp_write_post_meta_chunk was right, and the tool
  * every caller actually reaches for was wrong.
  *
  * JSON for an array is decoded, the same rule wp_update_option and the chunk writer
  * already follow: a caller that sends an Elementor payload as JSON must not leave a JSON
  * string in a row that every reader expects to hold an array. Anything else is stored
  * verbatim, a string that merely contains a backslash included.
  *
  * The result is then slashed, because update_post_meta() unslashes whatever it is given.
  * Without that a regex, a Windows path or a JSON payload is stored with its backslashes
  * stripped: "{"re":"\\d+"}" becomes "{"re":"\d+"}", which is no longer parseable JSON,
  * and the tool answers "Meta updated" over it. A success message across a mangled write
  * is worse than a refusal, because nothing downstream has any reason to look again.
  */
  private function prepare_meta_value( $value ) {
    if ( is_string( $value ) && isset( $value[0] ) && ( $value[0] === '[' || $value[0] === '{' ) ) {
      $decoded = json_decode( $value, true );
      if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
        $value = $decoded;
      }
    }
    // Deep by way of map_deep(), so a nested array is slashed at every leaf, and a
    // non-string leaf is returned untouched rather than cast.
    return wp_slash( $value );
  }

  private function bust_post_cache( int $post_id, array $context = [] ): void {
    if ( $post_id <= 0 ) {
      return;
    }
    static $already_busted = [];
    if ( isset( $already_busted[ $post_id ] ) ) {
      return;
    }
    $already_busted[ $post_id ] = true;

    clean_post_cache( $post_id );
    $context = wp_parse_args( $context, [
      'source' => 'mcp',
      'tool' => null,
      'batch' => false,
    ] );
    do_action( 'gmcp_post_changed', $post_id, $context );
    do_action( 'litespeed_purge_post', $post_id );
    if ( function_exists( 'rocket_clean_post' ) ) {
      rocket_clean_post( $post_id );
    }
  }

  /**
  * Purge the whole-site page caches this plugin can name, and say which ones did anything.
  *
  * Same stance as bust_post_cache(): purge what we can name and hand the rest to a hook.
  * Every entry is guarded, so a name that has since changed is a no-op instead of a fatal,
  * and a plugin that is not installed is simply never reported as purged. What is returned
  * is what actually ran, because the caller has to be able to tell what is still stale.
  *
  * @return string[] Names of the caches that were purged.
  */
  private function purge_page_caches(): array {
    $purged = [];

    // LiteSpeed documents its purge as an action, and has_action() both guards the call
    // and answers whether anything listened. docs.litespeedtech.com/lscache/lscwp/api/
    if ( has_action( 'litespeed_purge_all' ) ) {
      do_action( 'litespeed_purge_all' );
      $purged[] = 'LiteSpeed Cache (whole site)';
    }
    // WP Rocket's documented whole-domain purge, the site-wide sibling of the
    // rocket_clean_post() that bust_post_cache() already calls.
    // docs.wp-rocket.me/article/92-rocketcleandomain
    if ( function_exists( 'rocket_clean_domain' ) ) {
      rocket_clean_domain();
      $purged[] = 'WP Rocket (whole domain)';
    }
    // W3 Total Cache's public API function, declared in its w3-total-cache-api.php.
    if ( function_exists( 'w3tc_flush_all' ) ) {
      w3tc_flush_all();
      $purged[] = 'W3 Total Cache (all engines)';
    }
    // WP Super Cache's own clear, from wp-cache-phase2.php. Despite the name it has
    // nothing to do with core's wp_cache_flush(): this one empties the static page files.
    if ( function_exists( 'wp_cache_clear_cache' ) ) {
      wp_cache_clear_cache();
      $purged[] = 'WP Super Cache (static page files)';
    }
    // SpeedyCache 1.4 keeps its purge on a static class in main/delete.php rather than
    // behind a function or an action, so this is the only public entry point it offers.
    if ( is_callable( [ '\SpeedyCache\Delete', 'all_cache' ] ) ) {
      call_user_func( [ '\SpeedyCache\Delete', 'all_cache' ] );
      $purged[] = 'SpeedyCache (cached HTML)';
    }
    return $purged;
  }

  // How many URLs one wp_purge_url call may name.
  //
  // Twenty because that is the size of the problem the tool exists for: a template or a
  // menu change that touched a handful of pages. It is not a memory or a packet limit.
  // The real per-URL cost is that W3 Total Cache's flush_url() fans out to whatever CDN
  // and reverse-proxy add-ons that install has configured, so each URL can be a network
  // round trip on a stranger's infrastructure, and WP Rocket's is a recursive glob over
  // the cache directory. Past twenty the honest answer is that the site is stale, not
  // that twenty-five pages are, and wp_flush_cache says that in one call.
  //
  // Over the cap the call is refused rather than trimmed, for the reason the whole
  // surface is written: purging the first twenty and dropping the rest would hand back a
  // success naming URLs that are still being served from cache.
  private const PURGE_URL_MAX = 20;

  /**
  * Turn what a caller passed into a URL on THIS site, or say why it is not one.
  *
  * A cache purge is the one operation here that can reach past the site: LiteSpeed and
  * WP Rocket take the URL at its word, and a site that has wired gmcp_url_purged to a
  * CDN will hand whatever arrives to that CDN's API. So "a URL" means a URL under
  * home_url(), and anything else is refused before a single purge runs.
  *
  * Three of the checks are load-bearing and look like fussiness until you write the
  * hostile input for them:
  *
  * The host is compared for equality, never with strpos(). "example.com.evil.com" ends
  * with nothing suspicious and contains the real host; only an exact match refuses it.
  *
  * A leading "//" is not a path. wp_parse_url( '//evil.com/x' ) reports host evil.com,
  * so a protocol-relative URL that looks like a path to the eye is a foreign domain to
  * the parser, and it has to take the full-URL branch to be refused there.
  *
  * The path prefix matters only on a subdirectory install, where home is
  * https://host/blog/ and https://host/ is a different site sharing the host. Comparing
  * against "/blog" alone would also accept "/blogger", so the match is the directory or
  * something below it.
  *
  * What comes back is rebuilt from home_url()'s own scheme, host and port rather than
  * echoed from the input, so a caller reaching an https site over http gets the URL
  * WordPress itself would generate, which is the one the caches are keyed by. Userinfo
  * and the fragment are dropped on the way through: neither reaches the server, so
  * neither can be part of a cache key.
  *
  * @return array{0: string, 1: string} [canonical URL, reason for refusal]; exactly one is non-empty.
  */
  private function purge_url_target( string $raw ): array {
    $home = wp_parse_url( home_url( '/' ) );
    $home_host = strtolower( (string) ( $home['host'] ?? '' ) );
    $home_port = isset( $home['port'] ) ? (int) $home['port'] : 0;
    $home_dir = '/' . trim( (string) ( $home['path'] ?? '' ), '/' );
    $home_root = ( $home['scheme'] ?? 'http' ) . '://' . $home_host . ( $home_port ? ':' . $home_port : '' );

    $hash = strpos( $raw, '#' );
    if ( $hash !== false ) {
      $raw = substr( $raw, 0, $hash );
    }
    // After the fragment, not before: "/page/ #top" would otherwise leave a trailing
    // space inside the URL handed to every purge below.
    $raw = trim( $raw );
    if ( $raw === '' ) {
      return [ '', 'an empty string is not a URL' ];
    }
    // Checked before the branch, because it is wrong in both. Every purge downstream
    // turns the URL into a filesystem path under its own cache directory and globs it,
    // so a ".." segment is an instruction to look outside that directory. No permalink
    // this site can generate contains one.
    if ( in_array( '..', explode( '/', explode( '?', $raw, 2 )[0] ), true ) ) {
      return [ '', 'the path steps up through "..", which no permalink on this site does and which would reach outside a cache directory' ];
    }

    // A bare path is resolved through home_url(), which is the only reading that is
    // right on a subdirectory install: "/contact/" under a site at /blog/ means
    // /blog/contact/, and that is the URL its caches hold.
    if ( $raw[0] === '/' && substr( $raw, 0, 2 ) !== '//' ) {
      return [ home_url( $raw ), '' ];
    }

    $parts = wp_parse_url( $raw );
    if ( !is_array( $parts ) || empty( $parts['host'] ) ) {
      return [ '', 'this is neither a full URL nor a path beginning with "/". Pass ' . $home_root . '/page/ or /page/' ];
    }
    $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
    if ( $scheme !== '' && $scheme !== 'http' && $scheme !== 'https' ) {
      return [ '', 'the scheme is "' . $scheme . '", and only http and https name a page this site serves' ];
    }
    $host = strtolower( (string) $parts['host'] );
    $port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
    if ( $host !== $home_host || $port !== $home_port ) {
      return [ '', 'it is on "' . $host . ( $port ? ':' . $port : '' ) . '", and this site is "' . $home_host . ( $home_port ? ':' . $home_port : '' ) . '". This tool purges only its own site' ];
    }
    $path = (string) ( $parts['path'] ?? '' );
    if ( $path === '' ) {
      $path = '/';
    }
    if ( $home_dir !== '/' && $path !== $home_dir && strpos( $path, $home_dir . '/' ) !== 0 ) {
      return [ '', 'it is outside "' . $home_dir . '/", which is where this site lives on that host' ];
    }
    $query = (string) ( $parts['query'] ?? '' );
    return [ $home_root . $path . ( $query !== '' ? '?' . $query : '' ), '' ];
  }

  /**
  * Purge one URL from the page caches this plugin can name, and say what each one did.
  *
  * Same stance as purge_page_caches(), and the same guards for the same reason: a name
  * that has since changed is a no-op rather than a fatal. The difference is that here
  * "absent" has to be reported as loudly as "purged", because a caller who asked for one
  * URL and got an empty list has not been told the page is fresh — it is still being
  * served from whatever cache this tool could not see.
  *
  * The object cache is deliberately not touched. Its entries are keyed by post, option
  * and term, never by URL, so there is nothing here to select on; the only lever is
  * wp_cache_flush(), which drops every entry for every page and is precisely the
  * site-wide rebuild this tool exists to avoid. wp_flush_cache scope "post" is the
  * narrow version, and the reply points at it.
  *
  * @return array{purged: string[], declined: string[], absent: string[]}
  */
  private function purge_url_caches( string $url ): array {
    $purged = [];
    $declined = [];
    $absent = [];

    // LiteSpeed's documented per-URL purge action, registered beside litespeed_purge_all
    // in its src/api.cls.php. It takes a full URL or a bare path.
    // docs.litespeedtech.com/lscache/lscwp/api/
    if ( has_action( 'litespeed_purge_url' ) ) {
      do_action( 'litespeed_purge_url', $url );
      $purged[] = 'LiteSpeed Cache';
    }
    else {
      $absent[] = 'LiteSpeed Cache';
    }
    // WP Rocket's per-URL clear, the narrow sibling of the rocket_clean_domain() that
    // purge_page_caches() calls. It is RECURSIVE and its own documentation says so:
    // passing /blog/ clears everything matching /blog/(.*) as well, with no wildcard.
    // That is more than was asked for, so it is reported rather than left to be found
    // out. docs.wp-rocket.me/article/91-rocketcleanfiles
    if ( function_exists( 'rocket_clean_files' ) ) {
      rocket_clean_files( $url );
      $purged[] = 'WP Rocket (and everything below this path: its per-URL clear is recursive)';
    }
    else {
      $absent[] = 'WP Rocket';
    }
    // W3 Total Cache's public per-URL flush, declared in its w3-total-cache-api.php
    // beside the w3tc_flush_all() used for the whole site.
    if ( function_exists( 'w3tc_flush_url' ) ) {
      w3tc_flush_url( $url );
      $purged[] = 'W3 Total Cache';
    }
    else {
      $absent[] = 'W3 Total Cache';
    }
    // WP Super Cache's per-URL delete, from wp-cache-phase2.php. It returns false rather
    // than raising when it will not act, and it refuses any URL carrying "?" outright,
    // so the return value is reported instead of assumed. Treating a false as a purge is
    // the exact failure this tool is written against.
    if ( function_exists( 'wpsc_delete_url_cache' ) ) {
      if ( wpsc_delete_url_cache( $url ) ) {
        $purged[] = 'WP Super Cache';
      }
      else {
        $declined[] = 'WP Super Cache did nothing with this URL. It refuses any URL carrying a query string, and it matches on the path below the site home';
      }
    }
    else {
      $absent[] = 'WP Super Cache';
    }
    // SpeedyCache keeps its purges on a static class in main/delete.php rather than
    // behind functions or actions; Delete::url() is the per-URL sibling of the
    // Delete::all_cache() purge_page_caches() calls, and it takes a URL or a list.
    if ( is_callable( [ '\SpeedyCache\Delete', 'url' ] ) ) {
      call_user_func( [ '\SpeedyCache\Delete', 'url' ], $url );
      $purged[] = 'SpeedyCache';
    }
    else {
      $absent[] = 'SpeedyCache';
    }
    return [ 'purged' => $purged, 'declined' => $declined, 'absent' => $absent ];
  }

  /** Meta keys that say who is editing the source post rather than what it contains. */
  private const META_KEYS_NEVER_COPIED = [ '_edit_lock', '_edit_last' ];

  /**
  * Copy meta rows from one post to another.
  *
  * get_post_meta( $id ) with no key returns every key as a LIST of its rows, and those
  * rows are the raw database strings: WordPress only unserializes when you name a key.
  * Both facts are load-bearing. Keeping the list is what makes a key with several rows
  * arrive as several rows instead of collapsing into one, and the raw strings have to be
  * put back through maybe_unserialize() before they are written, because maybe_serialize()
  * on the way in deliberately re-serializes a string that already looks serialized. Handing
  * it the raw row stored s:48:"a:2:{...}" and an array key read back as a string. Nothing
  * is serialized here, for the reason the wp_update_post_meta handler gives: WordPress
  * serializes arrays itself, so doing it here would double-serialize them instead.
  *
  * wp_slash() is not decoration. add_post_meta() runs wp_unslash() on the value, which
  * would strip the backslashes out of an Elementor JSON payload (\/ and <) and
  * corrupt it silently. Slashing first makes the round trip exact.
  *
  * @return array{0: array<string,array{bytes:int,rows:int}>, 1: array<string,string>} what was copied, and key => why it was skipped.
  */
  private function copy_post_meta( int $from, int $to, array $only, bool $overwrite ): array {
    $source = get_post_meta( $from );
    $copied = [];
    $skipped = [];

    // Requested keys are matched against the source verbatim rather than sanitize_key()'d.
    // The only keys ever written are keys that already exist on the source, so there is
    // nothing to sanitize, and lowercasing the request would just fail to find a
    // mixed-case key that is really there.
    $wanted = [];
    foreach ( $only as $key ) {
      $key = (string) $key;
      if ( $key === '' ) {
        continue;
      }
      $wanted[ $key ] = true;
      if ( !isset( $source[ $key ] ) ) {
        $skipped[ $key ] = 'not set on post #' . $from;
      }
    }

    foreach ( $source as $key => $rows ) {
      if ( $wanted && !isset( $wanted[ $key ] ) ) {
        continue;
      }
      if ( in_array( $key, self::META_KEYS_NEVER_COPIED, true ) ) {
        $skipped[ $key ] = 'names whoever is editing post #' . $from . ', so it belongs to that post and not to its content';
        continue;
      }
      if ( !$overwrite && metadata_exists( 'post', $to, $key ) ) {
        $skipped[ $key ] = 'already set on post #' . $to . '; pass overwrite to replace it';
        continue;
      }
      // Replace rather than append, so overwriting a multi-valued key leaves the target
      // holding the source's rows and not both sets. A no-op when the key is absent.
      delete_post_meta( $to, $key );
      $bytes = 0;
      foreach ( (array) $rows as $value ) {
        // Bytes are counted on the stored row, which is what actually moved.
        $bytes += strlen( (string) $value );
        // The KEY needs wp_slash as much as the value does: add_metadata() unslashes both,
        // so a source key holding a backslash arrived on the target without it, under a
        // name the report did not mention. Measured: copying a key spelled back\slash_Key
        // filed it as backslash_Key while answering that back\slash_Key had been copied.
        add_post_meta( $to, wp_slash( $key ), wp_slash( maybe_unserialize( $value ) ) );
      }
      $copied[ $key ] = [ 'bytes' => $bytes, 'rows' => count( (array) $rows ) ];
    }
    return [ $copied, $skipped ];
  }

  // How much one chunked meta value may stage, and how long an unfinished one survives.
  //
  // 4MB is measured against the real constraint. A staged value is one row in wp_options,
  // which is LONGTEXT, so what actually binds is MySQL's max_allowed_packet: 16MB on a
  // stock MariaDB/MySQL, and the whole INSERT has to fit inside it. 4MB leaves room for
  // that and is still an order of magnitude past the largest Elementor page anyone
  // reports, which is a few hundred KB.
  //
  // 15 minutes is chosen because a chunked write is one continuous exchange, so a longer
  // gap means the caller went away. The expiry is the only thing keeping abandoned
  // sessions from accumulating in wp_options, which is how this could otherwise be used
  // to fill the database.
  private const META_CHUNK_MAX_BYTES = 4194304;
  private const META_CHUNK_TTL = 900;

  // How much one chunked READ hands back at a time.
  //
  // 64KB by default because the answer is read by a model and has to fit in what it can
  // hold. The 256KB ceiling is not where the response path breaks, and it would be
  // dishonest to imply that it is. It was measured, against WordPress 7.1 on PHP 8.2
  // with the web SAPI at memory_limit 256M, by raising this constant and walking the
  // slice size up through the real endpoint:
  //
  //   256KB  ->   350KB response, 26ms, peak 57.7MB
  //     4MB  ->  5.59MB response,        peak 72.4MB
  //     8MB  -> 11.2MB  response,        peak 89.1MB
  //    15MB  -> 21.0MB  response, 430ms, peak 118.5MB
  //
  // Nothing failed. 15MB is not an arbitrary stopping point either: it is very near the
  // largest value that can exist to be read, because the row has to arrive through
  // MySQL's max_allowed_packet, 16MB on a stock MariaDB. So on a default-sized host this
  // tool can hand back any meta value a site can hold, in one call, and the ceiling is a
  // policy rather than a limit.
  //
  // What the sweep does establish is the slope, which is the useful number: each byte of
  // slice costs about 4 bytes of peak memory (base64, then the inner JSON, then the
  // response JSON that escapes it) and about 1.33 bytes of response. That is what makes
  // a ceiling worth keeping on a smaller host. Lowering memory_limit and repeating the
  // sweep against a 15MB value, the first failure is at 4MB of slice on a 64M host; 2MB
  // still succeeds. The failure is worth knowing because it is not uniform: between
  // roughly 96M and 112M the fatal lands inside the audit log's wp_strip_all_tags() over
  // the whole response text, and the request dies as an HTTP 500 with an empty body, so
  // the caller gets nothing rather than an error it can read.
  //
  // 256KB is therefore kept for the caller, not for the server: it is 1MB of peak memory
  // and 350KB of response, sixteen times under the smallest failure measured, and already
  // more base64 than a model has any use for in one answer. Latency is not a reason in
  // either direction; at 256KB the slice size is not measurable next to the cost of
  // loading the value (26ms on a 512KB value, 107ms on a 15MB one, whatever the length).
  //
  // A longer length is clamped rather than refused: bytes_returned reports what actually
  // came back, so a caller that adds it to offset still walks to the end and never skips
  // the bytes it did not get.
  private const META_READ_CHUNK_BYTES = 65536;
  private const META_READ_CHUNK_MAX_BYTES = 262144;

  /**
  * Where a half-written meta value waits.
  *
  * A transient, for two reasons. It expires on its own, so an abandoned session cleans
  * itself up with no cron and no bookkeeping of its own. And the name starts with "gmcp_",
  * which GMCP_Core::option_guard() refuses, so the staging buffer cannot be read or
  * rewritten through wp_get_option and wp_update_option. The one place it must never live
  * is the live meta row, where a reader would take a partial value for the finished one.
  */
  private function meta_chunk_transient( string $session ): string {
    return 'gmcp_meta_chunk_' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $session ), 0, 64 );
  }
  #endregion

  #region Creating posts

  /**
  * The most items one wp_create_posts call will accept, and why it is this number.
  *
  * Not a guess about what an agent finds convenient. Two bounds already exist downstream
  * and a batch that walks past either one stops being legible, which is the whole reason
  * batch writing was held back rather than shipped with the rest.
  *
  * GMCP_Changes::MAX_RECORDS is 40 records per call. Past that the audit row stops listing
  * what happened and starts counting it, so the one row a reviewer opens to see what the
  * agent did says "and 31 more".
  *
  * GMCP_Journal::LIMIT is 40 entries for the WHOLE journal, not per call. This is the
  * sharper bound and the easier one to miss: a batch that emits more than forty journal
  * entries does not merely lose its own earliest rows, it evicts everything else already
  * on the undo list. One convenient call would clear the site's undo history.
  *
  * Measured here on the fixture, one wp_create_post costs one audit record for the post
  * plus one record and one journal entry per meta key. So twenty items carrying a single
  * meta key each is forty audit records, exactly at the summary cap, and twenty journal
  * entries, half the journal window, which leaves room for the option rows a page save
  * triggers underneath: the triage item this came from is a reporter counting nine journal
  * rows after what they thought was one action.
  *
  * Twenty is also comfortably more than the complaint. The reporter made seven
  * near-identical page calls and eight menu-item calls.
  */
  const CREATE_BATCH_MAX = 20;

  /**
  * One post created, exactly as wp_create_post creates it.
  *
  * EXTRACTED RATHER THAN COPIED, which is the only reason "every guard on wp_create_post
  * applies to every item" is a fact about the code instead of a claim about it. A second
  * copy of this would drift, and the direction it drifts in is the batch path missing a
  * filter the single path had already learned to apply. prepare_new_content() is the one
  * that matters: it routes a bare script tag through store_html(), and its own comment
  * records that the markup sniff deliberately does not look for <script>, so the branch
  * that catches one is easy to lose and silent when lost. There is one implementation and
  * both callers reach it.
  *
  * The title check is NOT here. It belongs to the caller because the two callers make it
  * at different moments: the single tool refuses one call, and the batch refuses the whole
  * list before it writes anything.
  *
  * @param array  $a    One item: post_title, post_content, post_excerpt, post_status,
  *                     post_type, post_name, meta_input.
  * @param string $tool Which tool is asking. Cache-bust context only.
  * @return int|WP_Error The new post ID.
  */
  private function create_one_post( array $a, string $tool ) {
    $ins = [
      'post_title' => sanitize_text_field( $a['post_title'] ),
      'post_status' => sanitize_key( $a['post_status'] ?? 'draft' ),
      'post_type' => sanitize_key( $a['post_type'] ?? 'post' ),
    ];
    if ( $a['post_content'] ?? '' ) {
      $ins['post_content'] = $this->prepare_new_content( $a['post_content'] );
    }
    if ( $a['post_excerpt'] ?? '' ) {
      $ins['post_excerpt'] = $this->clean_html( $a['post_excerpt'] );
    }
    if ( $a['post_name'] ?? '' ) {
      $ins['post_name'] = sanitize_title( $a['post_name'] );
    }

    // Handle JSON strings for meta_input (some MCP clients send objects as JSON strings)
    $meta_input = $a['meta_input'] ?? [];
    if ( is_string( $meta_input ) ) {
      $meta_input = json_decode( $meta_input, true ) ?? [];
    }
    if ( !empty( $meta_input ) && is_array( $meta_input ) ) {
      $ins['meta_input'] = $meta_input;
    }

    $new = wp_insert_post( wp_slash( $ins ), true );
    if ( is_wp_error( $new ) ) {
      return $new;
    }
    if ( empty( $ins['meta_input'] ) && !empty( $meta_input ) && is_array( $meta_input ) ) {
      foreach ( $meta_input as $k => $v ) {
        // Pass the value as-is: update_post_meta() serializes arrays itself.
        // maybe_serialize() here double-serialized nested arrays, so they read
        // back as a string and consumers (e.g. Noptin) rejected them as legacy.
        update_post_meta( $new, sanitize_key( $k ), $v );
      }
    }
    $this->bust_post_cache( (int) $new, [ 'tool' => $tool ] );
    return (int) $new;
  }

  /**
  * What a batch can say about undoing itself, which is less than a reader expects.
  *
  * MEASURED, NOT ASSUMED, because the opposite is the obvious thing to believe and it is
  * wrong. A batch runs inside one tool call, so it does inherit the call grouping: every
  * row it journals carries one call id and one wp_undo_change on that group reverses all
  * of them. But a CREATION is not a row the journal has. GMCP_Journal::observe_post()
  * returns early unless the operation is an update, on the reasoning that undo restores
  * fields and a creation has no previous field to restore, so the journal's vocabulary is
  * options, post updates and post meta, and nothing in it means "remove this post".
  *
  * Checked on a running site rather than read off the source: wp_create_post with no
  * meta_input leaves the journal empty and wp_list_changes returns []. With two meta keys
  * it leaves exactly those two rows, sharing one call id, and no row for the post. The
  * audit log does record the creation, so the two subsystems genuinely differ here.
  *
  * So the reply says what is true of this call rather than what is true in general. When
  * the batch wrote meta there is a real group and it is named; when it did not there is no
  * group and naming one would be a false yes pointed at the caller. Either way the created
  * posts come off with wp_delete_post and the reply says so, because a caller who reverts
  * the group and believes the pages are gone is in a worse position than one who was told
  * nothing.
  *
  * @param bool $wrote_meta Whether any item carried meta_input.
  */
  private function batch_undo_note( bool $wrote_meta ): string {
    $note = 'wp_undo_change cannot remove a created post: the change journal records option'
      . ' writes, post updates and custom fields, and has no entry for a creation. Delete the'
      . ' ids above with wp_delete_post instead.';
    if ( !$wrote_meta ) {
      return $note . ' This call journalled nothing, so it has no group to revert.';
    }
    if ( !class_exists( 'GMCP_Journal' ) || !$this->core->get_option( 'mcp_change_journal' ) ) {
      return $note . ' The change journal is off on this site, so nothing was recorded.';
    }
    $group = '';
    if ( class_exists( 'GMCP_Changes' ) ) {
      foreach ( GMCP_Changes::captured() as $record ) {
        if ( ( $record['call'] ?? '' ) !== '' ) {
          $group = GMCP_Journal::GROUP_PREFIX . $record['call'];
          break;
        }
      }
    }
    $note .= ' The custom fields this call wrote ARE journalled, grouped under this one call';
    return $group === ''
      ? $note . '; wp_list_changes shows the group.'
      : $note . ' as "' . $group . '", and one wp_undo_change on that id removes all of them'
        . ' while leaving the posts standing.';
  }

  #endregion

  #region Tools Definitions
  private function tools(): array {
    return [

      /* -------- Plugins -------- */
      'wp_list_plugins' => [
        'name' => 'wp_list_plugins',
        'description' => 'List installed plugins. Returns {plugin, Name, Version, active}, where "plugin" is the plugin file that the activate, deactivate, delete and update tools take.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'search' => [ 'type' => 'string' ] ],
        ],
        'accessLevel' => 'read',
      ],

      /* -------- Users -------- */
      'wp_get_users' => [
        'name' => 'wp_get_users',
        'description' => 'Retrieve users (fields: ID, user_login, display_name, roles). If no limit supplied, returns 10. `paged` ignored if `offset` is used.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'search' => [ 'type' => 'string' ],
            'role' => [ 'type' => 'string' ],
            'limit' => [ 'type' => 'integer' ],
            'offset' => [ 'type' => 'integer' ],
            'paged' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_create_user' => [
        'name' => 'wp_create_user',
        'description' => 'Create a user. Requires user_login and user_email. Optional: user_pass (random if omitted), display_name, role.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'user_login' => [ 'type' => 'string' ],
            'user_email' => [ 'type' => 'string' ],
            'user_pass' => [ 'type' => 'string' ],
            'display_name' => [ 'type' => 'string' ],
            'role' => [ 'type' => 'string' ],
          ],
          'required' => [ 'user_login', 'user_email' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_update_user' => [
        'name' => 'wp_update_user',
        'description' => 'Update a user – pass ID plus a “fields” object (user_email, display_name, user_pass, role).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'fields' => [
              'type' => 'object',
              'properties' => [
                'user_email' => [ 'type' => 'string' ],
                'display_name' => [ 'type' => 'string' ],
                'user_pass' => [ 'type' => 'string' ],
                'role' => [ 'type' => 'string' ],
              ],
              'additionalProperties' => true
            ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Comments -------- */
      'wp_get_comments' => [
        'name' => 'wp_get_comments',
        'description' => 'Retrieve comments (fields: comment_ID, comment_post_ID, comment_type, comment_author, comment_content, comment_date, comment_approved). Returns 10 by default. Filter by commenter with `user_id` (registered user ID) or `author_email`. Use `type` to filter by comment type; pass `type: "note"` to read WordPress 6.9 editor Notes (block-level feedback), where comment_approved "0" means open/unresolved and "1" means resolved. When reading notes, all statuses are returned unless you pass an explicit `status`.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer' ],
            'status' => [ 'type' => 'string' ],
            'type' => [ 'type' => 'string', 'description' => 'Filter by comment type, e.g. "comment", "pingback", or "note" (WP 6.9 editor Notes). Omit to return all types.' ],
            'search' => [ 'type' => 'string' ],
            'user_id' => [ 'type' => 'integer', 'description' => 'Filter by the registered user ID of the commenter.' ],
            'author_email' => [ 'type' => 'string', 'description' => 'Filter by the commenter email address.' ],
            'limit' => [ 'type' => 'integer' ],
            'offset' => [ 'type' => 'integer' ],
            'paged' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_create_comment' => [
        'name' => 'wp_create_comment',
        'description' => 'Insert a comment. Requires post_id and comment_content. Optional author, author_email, author_url.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer' ],
            'comment_content' => [ 'type' => 'string' ],
            'comment_author' => [ 'type' => 'string' ],
            'comment_author_email' => [ 'type' => 'string' ],
            'comment_author_url' => [ 'type' => 'string' ],
            'comment_approved' => [ 'type' => 'string' ],
          ],
          'required' => [ 'post_id', 'comment_content' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_update_comment' => [
        'name' => 'wp_update_comment',
        'description' => 'Update a comment – pass comment_ID plus fields (comment_content, comment_approved).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'comment_ID' => [ 'type' => 'integer' ],
            'fields' => [
              'type' => 'object',
              'properties' => [
                'comment_content' => [ 'type' => 'string' ],
                'comment_approved' => [ 'type' => 'string' ],
              ],
              'additionalProperties' => true
            ],
          ],
          'required' => [ 'comment_ID' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_delete_comment' => [
        'name' => 'wp_delete_comment',
        'description' => 'Delete a comment. Without force it goes to the trash IF this site has the trash enabled; a site with EMPTY_TRASH_DAYS set to 0 has no trash and the comment is destroyed either way. The reply says which happened. `force` true always destroys it. Set preview to true to be shown the comment and what would happen to its replies, without deleting it.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'comment_ID' => [ 'type' => 'integer' ],
            'force' => [ 'type' => 'boolean' ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would happen and change nothing.' ],
          ],
          'required' => [ 'comment_ID' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Options -------- */
      'wp_list_changes' => [
        'name' => 'wp_list_changes',
        'description' => 'List recent changes this API made that can be put back, newest first. Each entry has an id to pass to wp_undo_change. Only writes made through this API are recorded, never changes a person made in wp-admin.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'limit' => [ 'type' => 'integer', 'description' => 'How many to return. Default 20.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_undo_change' => [
        'name' => 'wp_undo_change',
        'description' => 'Put one recorded change back the way it was, by the id from wp_list_changes. Restores only the fields that changed, so later unrelated edits are left alone. Cannot be applied twice. Pass "call" instead of "id" to put back everything one tool call did: a single call routinely changes several things, since writing a page can move an option another plugin keeps in step with it and a page-builder save writes a handful of meta keys, and those arrive as separate entries sharing a call id. Reverting a whole call goes newest first, because two entries can touch the same row and replaying them in the order they happened leaves the value the call set rather than the value it found. Partial success is reported as partial: an individual entry can still be irreversible on its own.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'string', 'description' => 'The change id from wp_list_changes.' ],
            'call' => [ 'type' => 'string', 'description' => 'A call id from wp_list_changes, to put back every change that call made. Use instead of id, not with it.' ],
          ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_get_option' => [
        'name' => 'wp_get_option',
        'description' => 'Get a single WordPress option value (scalar or array) by key. Set raw to true to read the stored value straight from the database, bypassing the object cache and any option_* filters (e.g. Polylang filters sticky_posts per-language on REST requests, so a normal read can differ from the DB / wp-cli).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'key' => [ 'type' => 'string' ],
            'raw' => [ 'type' => 'boolean', 'description' => 'Read the unfiltered value directly from the database (bypasses object cache and option_* filters).' ],
          ],
          'required' => [ 'key' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_update_option' => [
        'name' => 'wp_update_option',
        'description' => 'Create or update a WordPress option. Arrays/objects are stored natively (a JSON string is decoded back to an array first). WordPress refreshes the option cache automatically, but full-page caches (Varnish, WP Rocket, Cloudflare) are not purged, so a front-end may lag until its cache expires; integrations can hook the gmcp_mutate action to purge on writes.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'key' => [ 'type' => 'string' ],
            // No type constraint here on purpose: WordPress options accept any
            // value (string, number, boolean, array, object). Declaring a union
            // that includes "object"/"array" makes ChatGPT reject the schema,
            // and the runtime normalizer would strip the type anyway and log a
            // warning every list_tools call. Keep it permissive from the start.
            'value' => [ 'description' => 'Option value. Accepts strings, numbers, booleans, arrays, or objects (non-scalars are JSON-serialised).' ],
          ],
          'required' => [ 'key', 'value' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_delete_option' => [
        'name' => 'wp_delete_option',
        'description' => 'Delete a WordPress option row outright. Use this when a stored value has to be ABSENT rather than empty, because some code only rebuilds a cache when it finds nothing: Elementor regenerates its theme-builder conditions cache only when the stored value is not an array, so a stored empty array reads as "already computed" and never self-heals, and removing the row is the only way back. Refuses the same credential-shaped keys wp_get_option and wp_update_option refuse, plus a short list of options WordPress cannot run without or cannot rebuild (siteurl, home, template, stylesheet, active_plugins, db_version, initial_db_version, cron, admin_email). rewrite_rules IS deletable: WordPress regenerates it, and deleting it is a normal permalink repair. A deletion is written to the audit log, but the undo journal keeps created and updated options only, so the deletion does NOT appear in wp_list_changes and wp_undo_change CANNOT put it back. Read the value with wp_get_option first if you might want it again. Reports honestly when the option did not exist; that is not a failure and nothing was deleted.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'key' => [ 'type' => 'string', 'description' => 'Option name to delete.' ],
          ],
          'required' => [ 'key' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Theme mods -------- */
      // The customizer's storage: theme_mods_<stylesheet> is a single option row
      // holding a serialized array, and wp_update_option does a full-array replace on
      // it. That is the wrong primitive for changing one customizer value, because the
      // same row also holds nav_menu_locations, sidebars_widgets, custom_logo and
      // header_image, and a mistake on any of them is a broken site with no error. These
      // tools use set_theme_mod / remove_theme_mod, which merge or remove a single key,
      // and they pass the row through the same option_guard the option tools use so a
      // site that has protected theme_mods_<stylesheet> is not read around.
      'wp_get_theme_mod' => [
        'name' => 'wp_get_theme_mod',
        'description' => 'Get a single theme modification value for the active theme. Theme mods are the customizer\'s storage: they live in the theme_mods_<stylesheet> option as a serialized array, alongside nav_menu_locations, sidebars_widgets, custom_logo and others. Use this rather than wp_get_option on the row, because a raw option read hands back the whole array and a single value needs extracting. Pass a default to use when the key is not set.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'key' => [ 'type' => 'string', 'description' => 'The theme mod key, e.g. body_background_color or nav_menu_locations.' ],
            'default' => [ 'description' => 'Value to return when the key is not set. Any type.' ],
          ],
          'required' => [ 'key' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_set_theme_mod' => [
        'name' => 'wp_set_theme_mod',
        'description' => 'Set a single theme modification value for the active theme. Uses set_theme_mod(), which merges one key into the theme_mods_<stylesheet> array rather than replacing the whole row, so nav_menu_locations, sidebars_widgets and the other keys in that row are left alone. This is the safe primitive for changing a customizer value: wp_update_option on theme_mods_* does a full-array replace and a mistake wipes every other mod. The write is journalled, so wp_undo_change can put it back.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'key' => [ 'type' => 'string', 'description' => 'The theme mod key.' ],
            'value' => [ 'description' => 'The value to store. Any type; arrays and objects are stored natively.' ],
          ],
          'required' => [ 'key', 'value' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_list_theme_mods' => [
        'name' => 'wp_list_theme_mods',
        'description' => 'List every theme modification value for the active theme. Returns the full theme_mods_<stylesheet> array, which holds every customizer setting plus nav_menu_locations, sidebars_widgets, custom_logo and header_image. Credential-shaped values are redacted by the same rule the option tools use.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'admin',
      ],
      'wp_remove_theme_mod' => [
        'name' => 'wp_remove_theme_mod',
        'description' => 'Remove a single theme modification value for the active theme, so the customizer falls back to its default for that key. Uses remove_theme_mod(), which removes one key from the theme_mods_<stylesheet> array rather than deleting the row. The removal is journalled as an update (the previous value is kept), so wp_undo_change can put it back.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'key' => [ 'type' => 'string', 'description' => 'The theme mod key to remove.' ],
          ],
          'required' => [ 'key' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Caches -------- */
      'wp_flush_cache' => [
        'name' => 'wp_flush_cache',
        'description' => 'Empty caches so the next request rebuilds from the database. scope "object" calls wp_cache_flush(): on a shared Redis or Memcached that can evict OTHER sites\' entries too, and every subsequent request on this site rebuilds from the database until the cache refills, so it is a real load spike, not a free operation. scope "transients" removes only EXPIRED transients, which is stale data; unexpired ones are left alone because they hold work already done. scope "post" purges the caches for one post (pass ID). scope "all" (default) does object plus transients. Every scope also purges the page-cache plugins this plugin can name (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, SpeedyCache) and fires the gmcp_cache_flushed action. It does NOT purge any CDN or reverse proxy (Cloudflare, Varnish, Fastly, a host edge cache), nor caches already handed to visitors: the result lists exactly what was purged and what was not, and you must purge the rest yourself.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'scope' => [
              'type' => 'string',
              'enum' => [ 'object', 'transients', 'post', 'all' ],
              'description' => 'What to empty. Default "all" (object cache + expired transients).',
            ],
            'ID' => [ 'type' => 'integer', 'description' => 'Post ID. Required when scope is "post".' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],

      'wp_purge_url' => [
        'name' => 'wp_purge_url',
        'description' => 'Purge the cached copy of particular URLs on this site, instead of dropping every cache at once the way wp_flush_cache does. Use it after changing one page, or a template that affects a handful of pages: a whole-site flush on a busy site sends every visitor to the database at once. Takes "urls", a list of up to 20; each entry is a full URL on this site or a path beginning with "/" (resolved against the site home, which matters on a subdirectory install). A URL on ANY other host is refused and NOTHING is purged, even if only one entry in the list is foreign, because a purge is handed on to caches and CDNs that take it at its word and no tool here asks anyone to drop somebody else\'s page. More than 20 URLs is refused rather than trimmed, so no URL is ever left believed-fresh. It purges the per-URL caches of the page-cache plugins this plugin can name (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SpeedyCache) and fires the gmcp_url_purged action for each URL. WP Rocket\'s per-URL clear is recursive, so it also drops everything below the path. It does NOT touch the object cache: those entries are keyed by post, option and term rather than by URL, so the only lever is the all-or-nothing flush this tool exists to avoid; use wp_flush_cache scope "post" for one post, or scope "object" for the lot. It does NOT purge any CDN or reverse proxy (Cloudflare, Varnish, Fastly, a host edge cache) unless the site has wired one to the hook itself. The reply names, per URL, which purges ran and which of those plugins were absent, and when it recognised no page cache at all it says so instead of reporting success.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'urls' => [
              'type' => 'array',
              'items' => [ 'type' => 'string' ],
              'description' => 'Up to 20 URLs on this site. Full URL or a path beginning with "/". A foreign host, or more than 20 entries, refuses the whole call.',
            ],
          ],
          'required' => [ 'urls' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Counts -------- */
      'wp_count_posts' => [
        'name' => 'wp_count_posts',
        'description' => 'Return counts of posts by status. Optional post_type (default post).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'post_type' => [ 'type' => 'string' ] ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_count_terms' => [
        'name' => 'wp_count_terms',
        'description' => 'Return total number of terms in a taxonomy.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'taxonomy' => [ 'type' => 'string' ] ],
          'required' => [ 'taxonomy' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_count_media' => [
        'name' => 'wp_count_media',
        'description' => 'Return number of attachments (optionally after/before date).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'after' => [ 'type' => 'string' ],
            'before' => [ 'type' => 'string' ],
          ],
        ],
        'accessLevel' => 'read',
      ],

      /* -------- Post-types -------- */
      'wp_get_post_types' => [
        'name' => 'wp_get_post_types',
        'description' => 'List public post types (key, label).',
        'inputSchema' => $this->empty_schema(),
        'accessLevel' => 'read',
      ],

      /* -------- Posts -------- */
      'wp_get_posts' => [
        'name' => 'wp_get_posts',
        'description' => 'Retrieve posts (fields: ID, title, status, excerpt, link). No full content. **If no limit is supplied it returns 10 posts by default.** `paged` is ignored if `offset` is used. Filter by author with `author` (user ID) or `author_name` (user slug).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_type' => [ 'type' => 'string' ],
            'post_status' => [ 'type' => 'string' ],
            'search' => [ 'type' => 'string' ],
            'author' => [ 'type' => 'integer', 'description' => 'Filter by author user ID.' ],
            'author_name' => [ 'type' => 'string', 'description' => 'Filter by author user slug (nicename). Ignored if author is set.' ],
            'author__not_in' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Exclude posts by these author user IDs.' ],
            'after' => [ 'type' => 'string' ],
            'before' => [ 'type' => 'string' ],
            'limit' => [ 'type' => 'integer' ],
            'offset' => [ 'type' => 'integer' ],
            'paged' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_get_post' => [
        'name' => 'wp_get_post',
        'description' => 'Get basic post data by ID: title, content, status, dates, permalink. Reads through the WordPress object cache; if you just wrote with wp_create_post / wp_update_post / wp_alter_post, the write tools bust caches automatically so a follow-up read returns fresh data. For complete data including all meta and terms, use wp_get_post_snapshot instead. Set content_format to "prose" to strip block-attribute JSON (e.g. huge gallery blobs) and return just the prose.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'content_format' => [ 'type' => 'string', 'enum' => [ 'full', 'prose' ], 'description' => 'full (default) returns raw content; prose strips block-attribute JSON, keeping prose, headings and block markers.' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_get_post_snapshot' => [
        'name' => 'wp_get_post_snapshot',
        'description' => 'Get complete post data in ONE call: all post fields, all meta, all terms/taxonomies, featured image, and author. Use this for WooCommerce products, events, or any post type where you need full context. Reduces 10-20 API calls to just 1. Returns structured JSON with post, meta, terms, thumbnail, and author keys.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'Post ID' ],
            'include' => [
              'type' => 'array',
              'description' => 'Optional: fields to include (default: all). Options: meta, terms, thumbnail, author',
              'items' => [ 'type' => 'string' ],
            ],
            'exclude' => [
              'type' => 'array',
              'description' => 'Optional: fields to exclude from post data. Options: content (useful for posts with huge content like many galleries)',
              'items' => [ 'type' => 'string' ],
            ],
            'content_format' => [ 'type' => 'string', 'enum' => [ 'full', 'prose' ], 'description' => 'full (default) returns raw content; prose strips block-attribute JSON (huge gallery blobs), keeping prose and block markers. Ignored if content is excluded.' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_create_post' => [
        'name' => 'wp_create_post',
        'description' => 'Create a new post, page, or any custom post type. post_title is required. post_content accepts HTML, Gutenberg blocks, and shortcodes (stored as-is, attribute quotes preserved); plain prose with no markup is converted from Markdown. post_status defaults to "draft" and post_type defaults to "post" – pass post_type: "page" for a page, or any registered CPT slug (product, event, etc.). Set categories later with wp_add_post_terms; meta_input is an associative array of custom-field key/value pairs. For small surgical edits to an existing post (insert/replace a paragraph or shortcode without resending the whole body), use wp_alter_post instead.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_title' => [ 'type' => 'string' ],
            'post_content' => [ 'type' => 'string' ],
            'post_excerpt' => [ 'type' => 'string' ],
            'post_status' => [ 'type' => 'string' ],
            'post_type' => [ 'type' => 'string' ],
            'post_name' => [ 'type' => 'string' ],
            'meta_input' => [ 'type' => 'object', 'description' => 'Associative array of custom fields.' ],
          ],
          'required' => [ 'post_title' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_create_posts' => [
        'name' => 'wp_create_posts',
        'description' => 'Create up to ' . self::CREATE_BATCH_MAX . ' posts, pages or custom post types in ONE call, instead of that many wp_create_post calls. Pass "items", an ordered array of objects taking exactly the arguments wp_create_post takes (post_title required; post_content, post_excerpt, post_status, post_type, post_name, meta_input optional). Every item goes through the same code path as a single create, so the same HTML filtering, the same status and type defaults and the same meta handling apply to each one; a batch is not a way to write content a single call would have filtered. NOT TRANSACTIONAL, and nothing here is: items run in order and the run STOPS at the first failure, so earlier items are already written and cannot be rolled back. The reply is three lists, what was created with its new id, what failed and why, and what was never attempted, plus retry_from_index so you can resend the remainder without re-reading the list. Obvious mistakes are caught before anything is written: if any item is missing post_title the whole call refuses and creates nothing. Undo is limited and the reply says how: wp_undo_change has no entry for a creation and cannot remove a created post, so use wp_delete_post on the returned ids.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'items' => [
              'type' => 'array',
              'description' => 'Ordered list of posts to create. Each entry takes the same arguments as wp_create_post. Maximum ' . self::CREATE_BATCH_MAX . ' entries; a longer list is refused whole and nothing is created.',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'post_title' => [ 'type' => 'string' ],
                  'post_content' => [ 'type' => 'string' ],
                  'post_excerpt' => [ 'type' => 'string' ],
                  'post_status' => [ 'type' => 'string' ],
                  'post_type' => [ 'type' => 'string' ],
                  'post_name' => [ 'type' => 'string' ],
                  'meta_input' => [ 'type' => 'object', 'description' => 'Associative array of custom fields.' ],
                ],
                'required' => [ 'post_title' ],
              ],
            ],
          ],
          'required' => [ 'items' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_duplicate_post' => [
        'name' => 'wp_duplicate_post',
        'description' => 'Duplicate an existing post, page or custom post type, copying its content, excerpt, type, parent, menu order and comment/ping settings. The copy is a DRAFT unless you pass post_status, whatever the source\'s status was: a duplicate going live on a misread instruction is exactly what this plugin exists to prevent, so publishing is always a separate, deliberate call. include_meta (default true) copies every meta key except _edit_lock and _edit_last; the copy happens inside PHP, so an Elementor _elementor_data blob of any size moves without passing through a tool argument. include_terms (default true) copies the term assignments of every taxonomy registered to the post type. Returns the new post ID. The new post is NOT journalled, so wp_undo_change will not remove it: the journal records modifications and deliberately not creations, because putting back a creation means deleting something and that is not the same write in reverse. Delete it with wp_delete_post if you need it gone. The copied meta IS journalled, one entry per key, so an individual field can be put back with wp_undo_change; a value larger than a megabyte is recorded as changed without a copy and says so.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'Post to duplicate.' ],
            'post_title' => [ 'type' => 'string', 'description' => 'Title for the copy. Defaults to the source title.' ],
            'post_status' => [ 'type' => 'string', 'description' => 'Status for the copy. Defaults to "draft"; the source status is never inherited.' ],
            'include_meta' => [ 'type' => 'boolean', 'description' => 'Copy custom fields (default true).' ],
            'include_terms' => [ 'type' => 'boolean', 'description' => 'Copy taxonomy assignments (default true).' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_update_post' => [
        'name' => 'wp_update_post',
        'description' => 'Update post fields and/or meta in ONE call. Pass ID + "fields" object (post_title, post_content, post_status, etc.) and/or "meta_input" object for custom fields. Post fields may also be passed at the top level (e.g. ID + post_title directly). Efficient for WooCommerce products: update title + price + stock together. Note: post_category REPLACES categories; use wp_add_post_terms to append instead. Use schedule_for to easily schedule posts. Taking an Elementor library template out of publish is refused while other posts render it, since that leaves each of them showing nothing; despite_references true goes ahead anyway. Set preview to true to be shown which fields would change and how, without writing.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'The ID of the post to update.' ],
            'despite_references' => [ 'type' => 'boolean', 'description' => 'Unpublish an Elementor library template even though other posts render it. Each of them is left showing nothing where the design was.' ],
            'fields' => [
              'type' => 'object',
              'properties' => [
                'post_title' => [ 'type' => 'string' ],
                'post_content' => [ 'type' => 'string' ],
                'post_status' => [ 'type' => 'string' ],
                'post_name' => [ 'type' => 'string' ],
                'post_excerpt' => [ 'type' => 'string' ],
                'post_category' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
              ],
              'additionalProperties' => true
            ],
            'meta_input' => [
              'type' => 'object',
              'description' => 'Associative array of custom fields. Keys are written exactly as given, case included; an empty key, the key "0", or one longer than 255 characters refuses the whole call before anything is written.'
            ],
            'schedule_for' => [
              'type' => 'string',
              'description' => 'Schedule post for future publication. Provide local datetime (e.g., "2026-02-02 09:00:00"). Automatically sets status to "future" and calculates GMT from WordPress timezone.'
            ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would happen and change nothing.' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_delete_post' => [
        'name' => 'wp_delete_post',
        'description' => 'Delete, trash, or remove a post, page, or any custom post type by ID. Without force the post normally goes to the trash and can be restored, but not always: attachments have no trash in WordPress, and neither does a site with EMPTY_TRASH_DAYS set to 0. In both cases a call without force destroys the post. The reply says which of the two happened, so do not assume it was reversible. With force: true it is always permanently destroyed. Works for posts, pages, products, events, attachments, or any registered CPT. An Elementor library template that other posts still render is refused, trashing included, because a trashed template renders as nothing on those pages exactly as a deleted one does; the refusal names them and despite_references true goes ahead anyway. Set preview to true to be told what would be deleted, including anything attached to it, without deleting anything.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'force' => [ 'type' => 'boolean' ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would happen and change nothing.' ],
            'despite_references' => [ 'type' => 'boolean', 'description' => 'Delete an Elementor library template even though other posts render it. Each of them is left showing nothing where the design was.' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_alter_post' => [
        'name' => 'wp_alter_post',
        'description' => 'Search-and-replace inside a post field without re-uploading the entire content. Efficient for making small edits to long content. With regex=true, pass a BARE PHP-PCRE pattern (no delimiters) in "search" and put any modifiers in "flags" (e.g. flags="i"); the pattern is wrapped with a safe delimiter internally, so patterns containing "/" (like Gutenberg block markers <!-- /wp:paragraph -->) work without escaping. Example: search="(<!-- /wp:paragraph -->)\\s*$" with flags="" appends to the last paragraph block. Backslashes must be JSON-escaped (\\s, \\d). A fully delimited pattern (/.../i) is also accepted for backward compatibility. Set preview to true to be shown every match and its replacement in context, without writing. Worth doing before any regex replace.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'Post ID.' ],
            'field' => [ 'type' => 'string', 'description' => 'Field to modify: post_content, post_excerpt, or post_title.' ],
            'search' => [ 'type' => 'string', 'description' => 'Text to search for, or (with regex=true) a bare PCRE pattern without delimiters, e.g. <!-- /wp:paragraph -->\\s*$' ],
            'replace' => [ 'type' => 'string', 'description' => 'Replacement text. In regex mode, backreferences like $1 / \\1 are supported.' ],
            'regex' => [ 'type' => 'boolean', 'description' => 'Treat search as a regex pattern (default: false).' ],
            'flags' => [ 'type' => 'string', 'description' => 'Optional PCRE modifier letters applied in regex mode, e.g. "i" (case-insensitive), "s" (dotall), "m" (multiline). Allowed: i, m, s, x, u, A, D, S, U, X, J.' ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would happen and change nothing.' ],
          ],
          'required' => [ 'ID', 'field', 'search', 'replace' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_write_blocks' => [
        'name' => 'wp_write_blocks',
        'description' => 'Build a valid Gutenberg (block editor) layout on an existing post or page from a simple block spec, so the result opens cleanly in the editor with no "invalid content" warnings. Create the post first with wp_create_post, then pass its ID plus "blocks", an ordered array of specs like {"type":"heading","level":2,"content":"..."}. Supported types: paragraph (content), heading (content, level 1-6), list (items[], ordered), quote (content, citation), image (url, alt, caption), buttons (buttons[] of {text,url}), group (blocks[]), columns (columns[] of block-spec arrays), separator, spacer (height px), code (content), html (content, raw HTML escape hatch). content fields accept inline HTML. mode replaces (default), appends, or prepends. For prose you do not need to lay out visually, plain wp_create_post/wp_update_post with Markdown is simpler; use this when you want real, editable blocks.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'Target post/page ID (create it first with wp_create_post).' ],
            'blocks' => [
              'type' => 'array',
              'description' => 'Ordered array of block specs. Each item is an object with a "type" and the fields for that type (see the tool description).',
              'items' => [ 'type' => 'object', 'additionalProperties' => true ],
            ],
            'mode' => [ 'type' => 'string', 'enum' => [ 'replace', 'append', 'prepend' ], 'description' => 'replace (default) overwrites post_content; append/prepend add the blocks to the existing content.' ],
          ],
          'required' => [ 'ID', 'blocks' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_list_block_patterns' => [
        'name' => 'wp_list_block_patterns',
        'description' => 'List the block patterns registered on this site (core, theme, and plugin patterns). Patterns are ready-made, pre-validated block layouts (hero/banner sections, pricing tables, testimonials, galleries, calls to action) authored by the theme, so inserting one is on-brand and always opens cleanly in the editor. Discover a layout here, insert it with wp_insert_block_pattern, then adjust the placeholder text with wp_alter_post. Returns compact metadata (name, title, categories, description) by default; set include_content to true to also get the raw block markup. Filter with search (matches title/name/description/keywords) and/or category (e.g. "call-to-action", "gallery", "testimonials").',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'search' => [ 'type' => 'string', 'description' => 'Case-insensitive filter on title, name, description, and keywords.' ],
            'category' => [ 'type' => 'string', 'description' => 'Pattern category slug, e.g. "featured", "call-to-action", "gallery", "testimonials".' ],
            'include_content' => [ 'type' => 'boolean', 'description' => 'Include each match\'s raw block markup (default false; can be large).' ],
            'limit' => [ 'type' => 'integer', 'description' => 'Max patterns to return (default 50, max 500).' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_insert_block_pattern' => [
        'name' => 'wp_insert_block_pattern',
        'description' => 'Insert a registered block pattern into a post or page by its name (get names from wp_list_block_patterns). Pattern markup is pre-validated theme/core content, so the result is on-brand and valid in the editor. mode "append" (default) adds it to the end, so you can compose a full page from several patterns in successive calls; "replace" overwrites the content; "prepend" adds it to the top. After inserting, swap placeholder text with wp_alter_post.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'Target post/page ID (create it first with wp_create_post).' ],
            'pattern' => [ 'type' => 'string', 'description' => 'Pattern name (slug) from wp_list_block_patterns, e.g. "core/query-standard-posts" or "twentytwentyfive/hero".' ],
            'mode' => [ 'type' => 'string', 'enum' => [ 'append', 'replace', 'prepend' ], 'description' => 'append (default), replace, or prepend the pattern content.' ],
          ],
          'required' => [ 'ID', 'pattern' ],
        ],
        'accessLevel' => 'write',
      ],

      /* -------- Post-meta -------- */
      'wp_get_post_meta' => [
        'name' => 'wp_get_post_meta',
        'description' => 'Get specific post meta field(s). Provide "key" to fetch a single value; omit to fetch all custom fields. The key is matched EXACTLY as given, case and punctuation included, so "myPlugin_Data" and "myplugin_data" are different rows here; earlier versions lowercased the key, so one that seemed to work before may now correctly return nothing, and the spelling to use is the one this tool lists when you omit "key". If you need ALL meta along with post data and terms, use wp_get_post_snapshot instead for efficiency.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'key' => [ 'type' => 'string' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_update_post_meta' => [
        'name' => 'wp_update_post_meta',
        'description' => 'Update post meta efficiently. Use "meta" object to update MULTIPLE fields at once (e.g., {_price: "19.99", _stock: "50", _sku: "WIDGET"}), or use "key"+"value" for a single field. Essential for WooCommerce products and custom post types. A value may be an array or object, and a string that is valid JSON for one is decoded before storing, the same way wp_update_option and wp_write_post_meta_chunk do, so a small Elementor payload no longer needs the chunk API. Backslashes are preserved, so a regex, a Windows path or a JSON payload is stored as it was sent. Keys are written EXACTLY as given, case included, so "myPlugin_Data" creates that key and not the "myplugin_data" earlier versions silently wrote instead. One thing is not decided here: the database matches an existing row case-insensitively, so writing "myPlugin_Data" where "myplugin_data" is already on the post updates that row and leaves its spelling alone, and reads are exact and would then miss it. That is reported in the answer when it happens, naming the row the value is really in. An empty key, the key "0" (WordPress cannot address either), or one longer than 255 characters (the width of wp_postmeta.meta_key) is refused and nothing at all is written. Use wp_write_post_meta_chunk instead when the value is too large to pass in one tool argument. The previous value is journalled, so wp_undo_change can put it back; a value larger than a megabyte, or one whose field name or contents look like a credential, is recorded as changed without a copy and says which.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'meta' => [ 'type' => 'object', 'description' => 'Key/value pairs to set. Alternative: provide "key" + "value".' ],
            'key' => [ 'type' => 'string' ],
            'value' => [ 'type' => [ 'string', 'number', 'boolean', 'array', 'object' ] ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_delete_post_meta' => [
        'name' => 'wp_delete_post_meta',
        'description' => 'Delete custom field(s) from a post. Provide value to remove a single row; omit value to delete all rows for the key. The key is passed through EXACTLY as given; earlier versions lowercased it first. Matching is then left to the database, which ignores case, so this deletes a row spelled "myplugin_data" when asked for "myPlugin_Data". List the keys on the post first if which row goes matters.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'key' => [ 'type' => 'string' ],
            'value' => [ 'type' => [ 'string', 'number', 'boolean' ] ],
          ],
          'required' => [ 'ID', 'key' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_copy_post_meta' => [
        'name' => 'wp_copy_post_meta',
        'description' => 'Copy custom fields from one post to another inside PHP, so a value too large to survive a tool argument never has to leave the server: an Elementor _elementor_data blob is routinely over 100KB and cannot be read out and written back reliably. Copies every key by default; pass "keys" to copy only some, spelled exactly as they are on the source, case included, and a key that does not match is reported as skipped rather than guessed at. A key that already exists on the target is SKIPPED, not merged, unless overwrite is true. _edit_lock and _edit_last are never copied because they say who is editing the source, not what it contains. A key with several rows keeps all of them. Reports bytes copied per key and the reason for every skip. Each key written is journalled separately, so wp_undo_change can put one field back at a time; a key larger than a megabyte is recorded as changed without a copy and says so.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'from_id' => [ 'type' => 'integer', 'description' => 'Post to copy meta from.' ],
            'to_id' => [ 'type' => 'integer', 'description' => 'Post to copy meta to.' ],
            'keys' => [
              'type' => 'array',
              'items' => [ 'type' => 'string' ],
              'description' => 'Meta keys to copy. Omit to copy every key on the source.',
            ],
            'overwrite' => [ 'type' => 'boolean', 'description' => 'Replace keys that already exist on the target (default false, which skips them).' ],
          ],
          'required' => [ 'from_id', 'to_id' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_write_post_meta_chunk' => [
        'name' => 'wp_write_post_meta_chunk',
        'description' => 'Write a post meta value that is too large to pass in one tool argument, a piece at a time. Pick any "session" id and send successive calls with the same session, ID and key; each call appends and answers with chunk_index, bytes_written and total_bytes staged. Nothing touches the post until the call that sets final: true, which assembles the staged bytes, writes the meta row and clears the staging, so an abandoned or half-sent value can never be read as real. A session is bound to the post and key it opened with and refuses a chunk aimed anywhere else. If the assembled string is valid JSON for an array or object it is decoded before storing, the same way wp_update_option decodes a JSON string, so a JSON-encoded Elementor payload becomes the array WordPress expects instead of a string; anything else is stored verbatim. Staging is capped and abandoned sessions expire. The key is written EXACTLY as given, case included (earlier versions lowercased it), and an empty key, the key "0", or one longer than 255 characters is refused. "written_to" in the final answer names the row the bytes actually went into: if the post already held a key differing only in case, the database counts that as the same key and the value lands there under the old spelling, which is the name to read it back by. The final write is journalled, so wp_undo_change can put the previous value back, unless it was larger than a megabyte, which is likely for the payloads this tool exists for; the entry says so either way.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'session' => [ 'type' => 'string', 'description' => 'Any id you choose, reused for every chunk of one value.' ],
            'ID' => [ 'type' => 'integer', 'description' => 'Target post ID.' ],
            'key' => [ 'type' => 'string', 'description' => 'Target meta key.' ],
            'data' => [ 'type' => 'string', 'description' => 'This chunk of the value, appended to what is already staged.' ],
            'final' => [ 'type' => 'boolean', 'description' => 'True on the last chunk: assemble and write the meta row (default false).' ],
          ],
          'required' => [ 'session', 'ID', 'key', 'data' ],
        ],
        'accessLevel' => 'write',
      ],

      'wp_read_post_meta_chunk' => [
        'name' => 'wp_read_post_meta_chunk',
        'description' => 'Read a post meta value that is too large to return in one response, a piece at a time: the mirror of wp_write_post_meta_chunk. Every call answers with offset, bytes_returned, total_bytes and more; walk the value by calling again with offset set to offset + bytes_returned, until more is false. "data" is ALWAYS base64: decode each piece and concatenate the DECODED bytes. It is base64 because a slice can end in the middle of a multibyte character, and only base64 carries those bytes through a JSON response unchanged. "represents" says what the bytes are, so a caller never has to guess: "raw" for a value stored as a string, or "json" when WordPress stored an array or an object, in which case the bytes are the same JSON that wp_get_post_meta prints for that row and can be handed straight back to wp_write_post_meta_chunk to reproduce it. A key with several rows is addressed with "index", and "rows" says how many there are. "sha256" hashes the WHOLE value rather than the piece, so it is identical on every call of one walk: if it changes, the value was rewritten mid-walk and the pieces already collected belong to a different document, so start again. The key is matched EXACTLY, case included (earlier versions lowercased it), so spell it as wp_get_post_meta lists it; a refusal names the near-miss spelling on the post when there is one. Refuses a key the post does not have, an index that does not exist and an offset past the end. length defaults to 65536 bytes and is capped at 262144; a longer request is clamped, and bytes_returned says what came back.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer', 'description' => 'Post to read from.' ],
            'key' => [ 'type' => 'string', 'description' => 'Meta key to read.' ],
            'index' => [ 'type' => 'integer', 'description' => 'Which row of a multi-valued key, counting from 0 (default 0). "rows" in the answer says how many exist.' ],
            'offset' => [ 'type' => 'integer', 'description' => 'Byte to start at (default 0). Byte, not character.' ],
            'length' => [ 'type' => 'integer', 'description' => 'How many bytes to return (default 65536, capped at 262144).' ],
          ],
          'required' => [ 'ID', 'key' ],
        ],
        'accessLevel' => 'read',
      ],

      /* -------- Featured image -------- */
      'wp_set_featured_image' => [
        'name' => 'wp_set_featured_image',
        'description' => 'Attach or remove a featured image (thumbnail) for a post/page. Provide media_id to attach, omit or null to remove.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer' ],
            'media_id' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'write',
      ],

      /* -------- Taxonomies / Terms -------- */
      'wp_get_taxonomies' => [
        'name' => 'wp_get_taxonomies',
        'description' => 'List taxonomies for a post type.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'post_type' => [ 'type' => 'string' ] ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_get_terms' => [
        'name' => 'wp_get_terms',
        'description' => 'List terms of a taxonomy.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'taxonomy' => [ 'type' => 'string' ],
            'search' => [ 'type' => 'string' ],
            'parent' => [ 'type' => 'integer' ],
            'limit' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'taxonomy' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_create_term' => [
        'name' => 'wp_create_term',
        'description' => 'Create a term.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'taxonomy' => [ 'type' => 'string' ],
            'term_name' => [ 'type' => 'string' ],
            'slug' => [ 'type' => 'string' ],
            'description' => [ 'type' => 'string' ],
            'parent' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'taxonomy', 'term_name' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_update_term' => [
        'name' => 'wp_update_term',
        'description' => 'Update a term.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'term_id' => [ 'type' => 'integer' ],
            'taxonomy' => [ 'type' => 'string' ],
            'name' => [ 'type' => 'string' ],
            'slug' => [ 'type' => 'string' ],
            'description' => [ 'type' => 'string' ],
            'parent' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'term_id', 'taxonomy' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_delete_term' => [
        'name' => 'wp_delete_term',
        'description' => 'Delete a term. Set preview to true to be told how many posts use this term, without deleting it.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'term_id' => [ 'type' => 'integer' ],
            'taxonomy' => [ 'type' => 'string' ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would happen and change nothing.' ],
          ],
          'required' => [ 'term_id', 'taxonomy' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_get_post_terms' => [
        'name' => 'wp_get_post_terms',
        'description' => 'Get terms attached to a post.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'taxonomy' => [ 'type' => 'string' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_add_post_terms' => [
        'name' => 'wp_add_post_terms',
        'description' => 'Attach or replace terms for a post. Set "append=true" to ADD terms to existing ones, or "append=false" (default) to REPLACE all terms. Use for categories, tags, or WooCommerce attributes (pa_color, pa_size, etc.).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'taxonomy' => [ 'type' => 'string' ],
            'terms' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
            'append' => [ 'type' => 'boolean' ],
          ],
          'required' => [ 'ID', 'terms' ],
        ],
        'accessLevel' => 'write',
      ],

      /* -------- Media -------- */
      'wp_get_media' => [
        'name' => 'wp_get_media',
        'description' => 'List media items. Filter by uploader with `author` (user ID) or `author_name` (user slug).',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'search' => [ 'type' => 'string' ],
            'author' => [ 'type' => 'integer', 'description' => 'Filter by uploader user ID.' ],
            'author_name' => [ 'type' => 'string', 'description' => 'Filter by uploader user slug (nicename). Ignored if author is set.' ],
            'after' => [ 'type' => 'string' ],
            'before' => [ 'type' => 'string' ],
            'limit' => [ 'type' => 'integer' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_upload_media' => [
        'name' => 'wp_upload_media',
        'description' => 'Upload a file to the WordPress Media Library. Provide either a url (WordPress will download it) or base64-encoded content with a filename. Base64 mode is useful for local files but doubles the payload size — keep files under a few MB to avoid memory or timeout issues.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'url' => [
              'type' => 'string',
              'description' => 'URL to download the file from. Use this OR base64/filename.',
            ],
            'base64' => [
              'type' => 'string',
              'description' => 'Base64-encoded file content. Must be used together with filename.',
            ],
            'filename' => [
              'type' => 'string',
              'description' => 'Filename with extension (e.g. photo.jpg). Required when using base64.',
            ],
            'title' => [ 'type' => 'string' ],
            'description' => [ 'type' => 'string' ],
            'alt' => [ 'type' => 'string' ],
          ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_upload_request' => [
        'name' => 'wp_upload_request',
        'description' => 'Upload a local file to the WordPress Media Library via a temporary upload endpoint. Use this instead of wp_upload_media when you have a local file (not a URL) — passing large base64 strings through MCP is impractical and will likely exceed context limits. Call this tool with the filename and optional metadata; it returns a one-time upload URL. Then use curl to POST the file: curl -X POST -F "file=@/local/path/file.jpg" "<upload_url>". The upload URL expires after 5 minutes and can only be used once.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'filename' => [
              'type' => 'string',
              'description' => 'Filename with extension (e.g. photo.jpg).',
            ],
            'title' => [ 'type' => 'string' ],
            'description' => [ 'type' => 'string' ],
            'alt' => [ 'type' => 'string' ],
          ],
          'required' => [ 'filename' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_update_media' => [
        'name' => 'wp_update_media',
        'description' => 'Update attachment meta.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'title' => [ 'type' => 'string' ],
            'caption' => [ 'type' => 'string' ],
            'description' => [ 'type' => 'string' ],
            'alt' => [ 'type' => 'string' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'write',
      ],
      'wp_delete_media' => [
        'name' => 'wp_delete_media',
        'description' => 'Delete an attachment and its files from disk. This is always permanent: WordPress has no trash for attachments, so there is nothing to restore from and force makes no difference. Set preview to true to be told what the file is and where it is used, without deleting it.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'ID' => [ 'type' => 'integer' ],
            'force' => [ 'type' => 'boolean' ],
            'preview' => [ 'type' => 'boolean', 'description' => 'Describe what would happen and change nothing.' ],
          ],
          'required' => [ 'ID' ],
        ],
        'accessLevel' => 'admin',
      ],


    ];
  }
  #endregion

  #region Tool Registration
  public function register_rest_tools( array $prev ): array {
    $tools = $this->tools();

    // All 36 core tools enabled and tested with ChatGPT.
    // Automatic validation in mcp.php fixes problematic type definitions.

    // Add category and annotations to each tool
    foreach ( $tools as &$tool ) {
      if ( !isset( $tool['category'] ) ) {
        $tool['category'] = 'WordPress';
      }

      // Add MCP tool annotations based on tool name/behavior
      if ( !isset( $tool['annotations'] ) ) {
        $name = $tool['name'];

        // Read-only tools (safe, no modifications)
        $is_readonly = (
          strpos( $name, 'wp_get_' ) === 0 ||
          strpos( $name, 'wp_list_' ) === 0 ||
          strpos( $name, 'wp_count_' ) === 0
        );

        // Destructive tools (can delete/destroy data)
        $is_destructive = (
          strpos( $name, 'wp_delete_' ) === 0 ||
          $name === 'wp_update_user' // Can change passwords/roles
        );

        $tool['annotations'] = [
          'readOnlyHint' => $is_readonly,
          'destructiveHint' => !$is_readonly && $is_destructive,
          'openWorldHint' => false, // All operate on closed WordPress system
        ];
      }
    }

    $merged = array_merge( $prev, array_values( $tools ) );
    return $merged;
  }
  #endregion

  #region Callback
  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    // Security check is already done in the MCP auth layer
    // If we reach here, the user is authorized to use MCP
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    return $this->dispatch( $tool, $args, $id );
  }
  #endregion


  #region Preview

  /**
  * Tools that can say what they would do instead of doing it.
  *
  * Not every write needs this. Setting a title is its own preview: you can see the
  * string you passed. These are the ones where the result is not visible in the call.
  * A regex replace matches somewhere you did not look, a delete takes children and
  * comments with it, and an update to a long body replaces text you never read.
  *
  * The value is the sentence shown in the tool description, so the schema and the
  * behaviour cannot drift.
  */
  /** How many matches a preview shows in context. The rest are counted, not collected. */
  const PREVIEW_MATCHES = 10;

  const PREVIEWABLE = [
    'wp_delete_post' => 'Set preview to true to be told what would be deleted, including anything attached to it, without deleting anything.',
    'wp_update_post' => 'Set preview to true to be shown which fields would change and how, without writing.',
    'wp_alter_post' => 'Set preview to true to be shown every match and its replacement in context, without writing. Worth doing before any regex replace.',
    'wp_delete_term' => 'Set preview to true to be told how many posts use this term, without deleting it.',
    'wp_delete_media' => 'Set preview to true to be told what the file is and where it is used, without deleting it.',
    'wp_delete_comment' => 'Set preview to true to be shown the comment and what would happen to its replies, without deleting it.',
  ];

  private function preview( string $tool, array $a, array $r ): array {
    switch ( $tool ) {
      case 'wp_delete_post':
        return $this->preview_delete_post( $a, $r );
      case 'wp_update_post':
        return $this->preview_update_post( $a, $r );
      case 'wp_alter_post':
        return $this->preview_alter_post( $a, $r );
      case 'wp_delete_term':
        return $this->preview_delete_term( $a, $r );
      case 'wp_delete_media':
        return $this->preview_delete_media( $a, $r );
      case 'wp_delete_comment':
        return $this->preview_delete_comment( $a, $r );
    }
    $r = $this->error( $r, "No preview is implemented for {$tool}.", -32603 );
    return $r;
  }

  /**
  * Say which of the two very different things a delete just did.
  *
  * "Deleted" was the answer for both, and they are not the same event. Without `force`
  * these tools normally trash, and the description said so flatly, so an agent reported
  * "moved to the trash, you can restore it" to a person whose post no longer existed.
  *
  * Two ways a delete that reads as reversible is not. Attachments have no trash in
  * WordPress at all, so wp_delete_post on one always destroys it, and wp_delete_post's
  * own description listed attachments among the things it works on. And a site with
  * EMPTY_TRASH_DAYS set to 0 has no trash for anything: core's wp_trash_post sees the
  * zero and deletes outright. That is an ordinary hardening setting, not an exotic one.
  *
  * Reporting what happened rather than refusing is deliberate. Refusing would be the
  * stronger guard and would also mean a site with the trash switched off cannot delete
  * anything without passing a flag that means "I accept this is irreversible", which it
  * would then have to pass every time, which is how a flag stops being read. The audit
  * log and the reply now carry the truth, and the truth is what reaches the person.
  */
  private function deletion_outcome( string $subject, bool $trashed ): string {
    return $trashed
      ? $subject . ' moved to the trash. It can be restored from there.'
      : $subject . ' permanently deleted. There is no trash to restore it from.';
  }

  /** Every preview says the same thing at the end, so a model cannot read one as done. */
  private function preview_text( array $r, string $body ): array {
    $this->add_result_text( $r, $body . "\n\nNothing has been changed. Call the same tool again without preview to go ahead." );
    return $r;
  }

  private function preview_delete_post( array $a, array $r ): array {
    $post = get_post( (int) ( $a['ID'] ?? 0 ) );
    if ( !$post ) {
      $r = $this->error( $r, 'No post with ID ' . (int) ( $a['ID'] ?? 0 ) . '.', -32602 );
      return $r;
    }
    $force = !empty( $a['force'] );

    // Children are the part nobody expects. wp_delete_post() promotes a page's children
    // to top level rather than deleting them, and attachments to a post are deleted
    // with it, and neither is visible from the call.
    $children = get_posts( [
      'post_parent' => $post->ID,
      'post_type' => 'any',
      'post_status' => 'any',
      'numberposts' => 50,
      'suppress_filters' => false,
    ] );
    $attachments = 0;
    $other_children = 0;
    foreach ( $children as $child ) {
      if ( $child->post_type === 'attachment' ) {
        $attachments++;
      }
      else {
        $other_children++;
      }
    }
    $comments = (int) get_comments_number( $post->ID );

    $lines = [];
    $lines[] = ( $force ? 'PERMANENTLY DELETE' : 'Move to trash' ) . ': ' . $post->post_type
      . ' #' . $post->ID . ' "' . $post->post_title . '" (' . $post->post_status . ')';
    $lines[] = 'URL: ' . get_permalink( $post );
    $lines[] = 'Last changed: ' . $post->post_modified_gmt . ' GMT';
    $lines[] = 'Body length: ' . strlen( $post->post_content ) . ' characters';
    if ( $comments ) {
      $lines[] = $comments . ' comment(s), which go with it.';
    }
    if ( $attachments ) {
      $lines[] = $attachments . ' attached file(s), which are deleted with it.';
    }
    if ( $other_children ) {
      $lines[] = $other_children . ' child item(s). WordPress moves these to the top level rather than deleting them.';
    }
    $lines[] = $force
      ? 'force is true, so this bypasses the trash and cannot be undone.'
      : 'This goes to the trash and can be restored.';

    return $this->preview_text( $r, implode( "\n", $lines ) );
  }

  private function preview_update_post( array $a, array $r ): array {
    $post = get_post( (int) ( $a['ID'] ?? 0 ) );
    if ( !$post ) {
      $r = $this->error( $r, 'No post with ID ' . (int) ( $a['ID'] ?? 0 ) . '.', -32602 );
      return $r;
    }

    $lines = [ 'Post #' . $post->ID . ' "' . $post->post_title . '"' ];
    $changes = 0;
    foreach ( [ 'post_title', 'post_status', 'post_excerpt', 'post_name', 'post_content' ] as $field ) {
      if ( !array_key_exists( $field, $a ) ) {
        continue;
      }
      $new = (string) $a[ $field ];
      $old = (string) $post->$field;
      if ( $new === $old ) {
        $lines[] = $field . ': unchanged';
        continue;
      }
      $changes++;
      if ( $field === 'post_content' ) {
        $lines[] = 'post_content: ' . strlen( $old ) . ' characters would be replaced with ' . strlen( $new ) . '.';
        $lines[] = '  currently starts: ' . $this->snippet( $old );
        $lines[] = '  would start:      ' . $this->snippet( $new );
      }
      else {
        $lines[] = $field . ': "' . $this->snippet( $old ) . '" becomes "' . $this->snippet( $new ) . '"';
      }
    }
    if ( !$changes ) {
      $lines[] = 'Nothing in this call would change anything.';
    }
    return $this->preview_text( $r, implode( "\n", $lines ) );
  }

  /**
  * The one that earns its keep.
  *
  * A regex replace is the tool most likely to quietly ruin a post: the pattern matches
  * in a place nobody looked, or matches nothing at all and reports success either way.
  * This shows every match in context with the text that would take its place.
  */
  private function preview_alter_post( array $a, array $r ): array {
    $post = get_post( (int) ( $a['ID'] ?? 0 ) );
    if ( !$post ) {
      $r = $this->error( $r, 'No post with ID ' . (int) ( $a['ID'] ?? 0 ) . '.', -32602 );
      return $r;
    }
    $field = sanitize_key( $a['field'] ?? '' );
    if ( !in_array( $field, [ 'post_content', 'post_excerpt', 'post_title' ], true ) ) {
      $r = $this->error( $r, 'field must be post_content, post_excerpt or post_title.', -32602 );
      return $r;
    }
    $subject = (string) $post->$field;
    $search = (string) ( $a['search'] ?? '' );
    $replace = (string) ( $a['replace'] ?? '' );

    $matches = [];
    $count = 0;
    $pattern = null;

    if ( !empty( $a['regex'] ) ) {
      // The same compiler the real handler uses. A preview built on its own idea of
      // what the pattern means is worse than no preview: it would report matches that
      // the write then does not make, or miss ones it does.
      list( $pattern, $error ) = $this->compile_alter_regex( $search, isset( $a['flags'] ) && is_string( $a['flags'] ) ? $a['flags'] : '' );
      if ( $error !== null ) {
        $r = $this->error( $r, $error, -32602 );
        return $r;
      }
      // Count without materialising anything. Asking for every match with
      // PREG_OFFSET_CAPTURE and slicing to ten afterwards means one array entry and one
      // preg_replace call per match: a pattern matching every character of a 400 KB post
      // exhausted 128 MB. The fatal net turned that into a tool error rather than a dead
      // connection, but a preview is the cautious option and has no business being the
      // expensive one.
      $count = @preg_match_all( $pattern, $subject );
      if ( $count === false ) {
        $msg = function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : 'PCRE error code ' . preg_last_error();
        $r = $this->error( $r, 'That pattern failed against this content: ' . $msg, -32602 );
        return $r;
      }
      // Then walk out only the handful actually shown.
      // Each match is found against the WHOLE subject at an offset, so its groups and any
      // lookaround are resolved in the real context.
      $offset = 0;
      while ( count( $matches ) < self::PREVIEW_MATCHES
        && @preg_match( $pattern, $subject, $found, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
        $text = (string) $found[0][0];
        $at = (int) $found[0][1];
        $groups = array_map( function ( $group ) {
          return is_array( $group ) ? (string) $group[0] : (string) $group;
        }, $found );
        $matches[] = [ 'text' => $text, 'at' => $at, 'becomes' => $this->expand_replacement( $replace, $groups ) ];
        // A zero-width match would leave the offset where it was and spin forever.
        $offset = $at + max( 1, strlen( $text ) );
      }
    }
    else {
      if ( $search === '' ) {
        $r = $this->error( $r, 'search cannot be empty.', -32602 );
        return $r;
      }
      $count = substr_count( $subject, $search );
      $offset = 0;
      while ( count( $matches ) < self::PREVIEW_MATCHES
        && ( $at = strpos( $subject, $search, $offset ) ) !== false ) {
        $matches[] = [ 'text' => $search, 'at' => $at, 'becomes' => $replace ];
        $offset = $at + strlen( $search );
      }
    }

    if ( !$count ) {
      return $this->preview_text( $r, 'No match in ' . $field . ' of post #' . $post->ID
        . '. Running this for real would report success and change nothing, which is the failure worth catching here.' );
    }

    $lines = [ $count . ' match(es) in ' . $field . ' of post #' . $post->ID . ':' ];
    foreach ( $matches as $index => $match ) {
      $before = substr( $subject, max( 0, $match['at'] - 40 ), min( 40, $match['at'] ) );
      $after = substr( $subject, $match['at'] + strlen( $match['text'] ), 40 );
      $lines[] = '';
      $lines[] = ( $index + 1 ) . '. at character ' . $match['at'];
      $lines[] = '   ...' . $this->flatten( $before ) . '[' . $this->flatten( $match['text'] ) . ']' . $this->flatten( $after ) . '...';
      $lines[] = '   becomes: ...' . $this->flatten( $before ) . '[' . $this->flatten( (string) $match['becomes'] ) . ']' . $this->flatten( $after ) . '...';
    }
    if ( $count > count( $matches ) ) {
      $lines[] = '';
      $lines[] = '(' . ( $count - count( $matches ) ) . ' further match(es) not shown.)';
    }
    return $this->preview_text( $r, implode( "\n", $lines ) );
  }

  private function preview_delete_term( array $a, array $r ): array {
    $taxonomy = sanitize_key( $a['taxonomy'] ?? '' );
    $term = get_term( (int) ( $a['term_id'] ?? 0 ), $taxonomy ?: '' );
    if ( !$term || is_wp_error( $term ) ) {
      $r = $this->error( $r, 'No such term.', -32602 );
      return $r;
    }
    $children = get_terms( [ 'taxonomy' => $term->taxonomy, 'parent' => $term->term_id, 'hide_empty' => false ] );
    $child_count = is_wp_error( $children ) ? 0 : count( $children );

    $lines = [
      'Delete term "' . $term->name . '" (' . $term->taxonomy . ' #' . $term->term_id . ')',
      $term->count . ' item(s) currently use it. They are not deleted, they simply lose the term.',
    ];
    if ( $child_count ) {
      $lines[] = $child_count . ' child term(s). WordPress moves these up to this term\'s parent rather than deleting them.';
    }
    if ( (int) get_option( 'default_category' ) === (int) $term->term_id ) {
      $lines[] = 'This is the default category. WordPress refuses to delete it.';
    }
    return $this->preview_text( $r, implode( "\n", $lines ) );
  }

  private function preview_delete_media( array $a, array $r ): array {
    $id = (int) ( $a['ID'] ?? 0 );
    $post = get_post( $id );
    if ( !$post || $post->post_type !== 'attachment' ) {
      $r = $this->error( $r, 'No attachment with ID ' . $id . '.', -32602 );
      return $r;
    }
    $file = get_attached_file( $id );
    $url = wp_get_attachment_url( $id );

    // A file still referenced by a post body is the case that hurts, and it is invisible
    // from the attachment record: the reference is text in someone else's content.
    global $wpdb;
    $uses = 0;
    if ( $url ) {
      $uses = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_content LIKE %s",
        '%' . $wpdb->esc_like( $url ) . '%'
      ) );
    }
    $featured = (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
      $id
    ) );

    $lines = [
      'Delete attachment #' . $id . ' "' . $post->post_title . '"',
      'File: ' . ( $file ?: 'unknown' ),
      'Size: ' . ( $file && file_exists( $file ) ? size_format( filesize( $file ) ) : 'unknown' ),
    ];
    $lines[] = $uses
      ? $uses . ' post(s) reference this file in their content. Those references will break.'
      : 'No post content references this file.';
    $lines[] = $featured
      ? $featured . ' post(s) use it as their featured image.'
      : 'It is not any post\'s featured image.';
    $lines[] = 'Deleting an attachment removes the file and every generated size from disk. It cannot be undone.';

    return $this->preview_text( $r, implode( "\n", $lines ) );
  }

  private function preview_delete_comment( array $a, array $r ): array {
    // comment_ID, exactly as the tool's own schema spells it. A preview reading a
    // different key than the write would describe the wrong comment.
    $id = (int) ( $a['comment_ID'] ?? 0 );
    $comment = get_comment( $id );
    if ( !$comment ) {
      $r = $this->error( $r, 'No comment with ID ' . $id . '.', -32602 );
      return $r;
    }
    $replies = get_comments( [ 'parent' => $id, 'count' => true ] );
    $force = !empty( $a['force'] );

    $lines = [
      ( $force ? 'PERMANENTLY DELETE' : 'Move to trash' ) . ': comment #' . $comment->comment_ID
        . ' by ' . $comment->comment_author . ' on post #' . $comment->comment_post_ID,
      'Status: ' . wp_get_comment_status( $comment ),
      'Text: ' . $this->snippet( $comment->comment_content, 200 ),
    ];
    if ( $replies ) {
      $lines[] = $replies . ' direct repl(y/ies). WordPress leaves these in place, orphaned, rather than deleting them.';
    }
    return $this->preview_text( $r, implode( "\n", $lines ) );
  }


  /**
  * Work out what a match becomes, from the groups it actually captured.
  *
  * The obvious version runs preg_replace over the matched fragment on its own, and it is
  * wrong in the one direction a preview must never be wrong in. A pattern whose match
  * depends on its surroundings resolves differently in isolation: (?<=foo)bar matches the
  * bar after foo in the real body, but re-run against the fragment "bar" the lookbehind
  * has nothing before it, so preg_replace returns the fragment unchanged and the preview
  * reports that bar becomes bar. The operator reads "no change", approves, and the write
  * replaces it. That is the failure the preview exists to prevent, inverted: it reported
  * safety and the write was not safe.
  *
  * The match itself is found against the whole subject, so its groups are already correct.
  * Only the substitution has to be done here, by expanding the replacement's references
  * against those groups, the way PCRE would.
  *
  * Handles the three reference forms PHP accepts: \n, $n and ${n}. A backslash-escaped
  * literal dollar is left alone, which matches PHP closely enough for a preview.
  */
  private function expand_replacement( string $replace, array $groups ): string {
    $expanded = preg_replace_callback(
      '/\\\\(\d{1,2})|\$\{(\d{1,2})\}|\$(\d{1,2})/',
      function ( array $reference ) use ( $groups ) {
        foreach ( [ 1, 2, 3 ] as $slot ) {
          if ( isset( $reference[ $slot ] ) && $reference[ $slot ] !== '' ) {
            $index = (int) $reference[ $slot ];
            return isset( $groups[ $index ] ) ? $groups[ $index ] : '';
          }
        }
        return '';
      },
      $replace
    );
    return $expanded === null ? $replace : $expanded;
  }

  /** A short, single-line version of a value, for showing inside a sentence. */
  private function snippet( string $value, int $length = 80 ): string {
    $flat = $this->one_line( $value );
    return mb_strlen( $flat ) > $length ? mb_substr( $flat, 0, $length ) . '…' : $flat;
  }

  private function one_line( string $value ): string {
    return trim( $this->flatten( $value ) );
  }

  /**
  * Newlines collapsed so a match fits on one line, but edges left alone.
  *
  * Trimming here would eat the space either side of a match and make the preview read
  * as though the replacement also removed the spacing. The preview has to show what
  * would happen, including the boring parts.
  */
  private function flatten( string $value ): string {
    return preg_replace( '/\s+/', ' ', $value );
  }

  #endregion

  #region Dispatcher
  private function dispatch( string $tool, array $a, ?int $id ): array {
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // Accept common aliases for the primary record id. The post tools use the
    // WordPress-native "ID" (matching wp_update_post() / $post->ID), while
    // wp_set_featured_image, the comment tools, and the SEO/Woo suites use
    // "post_id". Agents hopping between tools guess the wrong spelling and hit a
    // bare "ID required". No tool in this suite uses two of these keys to mean
    // two different things, so mirroring them is safe; each handler still reads
    // its own canonical key.
    $idAliases = [ 'ID', 'post_id', 'id' ];
    $primaryId = null;
    foreach ( $idAliases as $k ) {
      if ( isset( $a[ $k ] ) && $a[ $k ] !== '' ) {
        $primaryId = $a[ $k ];
        break;
      }
    }
    if ( $primaryId !== null ) {
      foreach ( $idAliases as $k ) {
        if ( !isset( $a[ $k ] ) || $a[ $k ] === '' ) {
          $a[ $k ] = $primaryId;
        }
      }
    }

    // Say what would happen, change nothing. Placed before the switch so a preview
    // cannot fall through into the real handler by way of a missed early return in one
    // of forty case blocks.
    if ( !empty( $a['preview'] ) && isset( self::PREVIEWABLE[ $tool ] ) ) {
      return $this->preview( $tool, $a, $r );
    }

    switch ( $tool ) {

      /* ===== Users ===== */
      case 'wp_get_users':
        $q = [
          'search' => '*' . esc_attr( $a['search'] ?? '' ) . '*',
          'role' => $a['role'] ?? '',
          'number' => max( 1, min( 500, intval( $a['limit'] ?? 10 ) ) ),
        ];
        if ( isset( $a['offset'] ) ) {
          $q['offset'] = max( 0, intval( $a['offset'] ) );
        }
        if ( isset( $a['paged'] ) ) {
          $q['paged'] = max( 1, intval( $a['paged'] ) );
        }
        $rows = [];
        foreach ( get_users( $q ) as $u ) {
          $rows[] = [
            'ID' => $u->ID,
            'user_login' => $u->user_login,
            'display_name' => $u->display_name,
            'roles' => $u->roles,
          ];
        }
        $this->add_result_text( $r, wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_create_user':
        // Same object-level gap as wp_update_user: the MCP gate only checks the
        // administrator role. wp_insert_user() runs no capability checks, so
        // require create_users (which on Multisite is a network-only capability,
        // correctly denying per-site Administrators) and refuse to assign a role
        // the caller cannot grant (e.g. administrator).
        if ( !current_user_can( 'create_users' ) ) {
          $r = $this->error( $r, 'You are not allowed to create users.', -32603 );
          break;
        }
        $role = sanitize_key( $a['role'] ?? get_option( 'default_role', 'subscriber' ) );
        require_once ABSPATH . 'wp-admin/includes/user.php'; // get_editable_roles()
        if ( $role !== '' && !array_key_exists( $role, get_editable_roles() ) ) {
          $r = $this->error( $r, 'You are not allowed to assign this role.', -32603 );
          break;
        }
        $data = [
          'user_login' => sanitize_user( $a['user_login'] ),
          'user_email' => sanitize_email( $a['user_email'] ),
          'user_pass' => $a['user_pass'] ?? wp_generate_password( 12, true ),
          'display_name' => sanitize_text_field( $a['display_name'] ?? '' ),
          'role' => $role,
        ];
        $uid = wp_insert_user( $data );
        if ( is_wp_error( $uid ) ) {
          $r = $this->error( $r, $uid->get_error_message(), $uid->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'User created ID ' . $uid );
        }
        break;

      case 'wp_update_user':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $target_id = intval( $a['ID'] );
        // Object-level authorization. The MCP gate only checks that the caller
        // holds the administrator role, not that they may touch THIS user.
        // wp_update_user() runs no capability checks of its own, so without this
        // a Multisite per-site Administrator could edit users they cannot touch
        // in wp-admin (e.g. set a new password on the Network Owner). Delegating
        // to edit_user enforces the same boundary core does, for every auth path.
        // Reported by Charles Vosburgh via responsible disclosure.
        if ( !current_user_can( 'edit_user', $target_id ) ) {
          $r = $this->error( $r, 'You are not allowed to edit this user.', -32603 );
          break;
        }
        $upd = [ 'ID' => $target_id ];
        if ( !empty( $a['fields'] ) && is_array( $a['fields'] ) ) {
          foreach ( $a['fields'] as $k => $v ) {
            $upd[ $k ] = ( $k === 'role' ) ? sanitize_key( $v ) : sanitize_text_field( $v );
          }
        }
        // A role change is a promotion/demotion. Require promote_user on the
        // target and refuse any role the caller cannot themselves assign, so a
        // lower admin cannot grant a role above their own reach.
        if ( isset( $upd['role'] ) && $upd['role'] !== '' ) {
          require_once ABSPATH . 'wp-admin/includes/user.php'; // get_editable_roles()
          if ( !current_user_can( 'promote_user', $target_id ) || !array_key_exists( $upd['role'], get_editable_roles() ) ) {
            $r = $this->error( $r, 'You are not allowed to assign this role.', -32603 );
            break;
          }
        }
        $u = wp_update_user( $upd );
        if ( is_wp_error( $u ) ) {
          $r = $this->error( $r, $u->get_error_message(), $u->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'User #' . $u . ' updated' );
        }
        break;

        /* ===== Comments ===== */
      case 'wp_get_comments':
        $args = [
          'post_id' => isset( $a['post_id'] ) ? intval( $a['post_id'] ) : '',
          'status' => $a['status'] ?? 'approve',
          'search' => $a['search'] ?? '',
          'number' => max( 1, min( 500, intval( $a['limit'] ?? 10 ) ) ),
        ];
        // WP 6.9 Notes are comments with comment_type 'note'. Filter by type when
        // asked (unset = all types, preserving prior behavior). Notes track their
        // state via comment_status (hold = open, approve = resolved), so when
        // reading notes without an explicit status, return all statuses; otherwise
        // the 'approve' default would hide every open note.
        if ( isset( $a['type'] ) && $a['type'] !== '' ) {
          $args['type'] = sanitize_key( $a['type'] );
          if ( $args['type'] === 'note' && !isset( $a['status'] ) ) {
            $args['status'] = 'all';
          }
        }
        if ( isset( $a['user_id'] ) ) {
          $args['user_id'] = intval( $a['user_id'] );
        }
        if ( $a['author_email'] ?? '' ) {
          $args['author_email'] = sanitize_email( $a['author_email'] );
        }
        if ( isset( $a['offset'] ) ) {
          $args['offset'] = max( 0, intval( $a['offset'] ) );
        }
        if ( isset( $a['paged'] ) ) {
          $args['paged'] = max( 1, intval( $a['paged'] ) );
        }
        $list = [];
        foreach ( get_comments( $args ) as $c ) {
          $list[] = [
            'comment_ID' => $c->comment_ID,
            'comment_post_ID' => $c->comment_post_ID,
            'comment_type' => $c->comment_type,
            'comment_author' => $c->comment_author,
            'comment_content' => wp_trim_words( wp_strip_all_tags( $c->comment_content ), 40 ),
            'comment_date' => $c->comment_date,
            'comment_approved' => $c->comment_approved,
          ];
        }
        $this->add_result_text( $r, wp_json_encode( $list, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_create_comment':
        if ( empty( $a['post_id'] ) || empty( $a['comment_content'] ) ) {
          $r = $this->error( $r, 'post_id & comment_content required', -32602 );
          break;
        }
        $ins = [
          'comment_post_ID' => intval( $a['post_id'] ),
          'comment_content' => $this->clean_html( $a['comment_content'] ),
          'comment_author' => sanitize_text_field( $a['comment_author'] ?? '' ),
          'comment_author_email' => sanitize_email( $a['comment_author_email'] ?? '' ),
          'comment_author_url' => esc_url_raw( $a['comment_author_url'] ?? '' ),
          'comment_approved' => $a['comment_approved'] ?? 1,
        ];
        $cid = wp_insert_comment( $ins );
        if ( is_wp_error( $cid ) ) {
          /** @var WP_Error $cid */
          $r = $this->error( $r, $cid->get_error_message(), $cid->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'Comment created ID ' . $cid );
        }
        break;

      case 'wp_update_comment':
        if ( empty( $a['comment_ID'] ) ) {
          $r = $this->error( $r, 'comment_ID required', -32602 );
          break;
        }
        $c = [ 'comment_ID' => intval( $a['comment_ID'] ) ];
        if ( !empty( $a['fields'] ) && is_array( $a['fields'] ) ) {
          foreach ( $a['fields'] as $k => $v ) {
            $c[ $k ] = ( $k === 'comment_content' ) ? $this->clean_html( $v ) : sanitize_text_field( $v );
          }
        }
        $cid = wp_update_comment( $c, true );
        if ( is_wp_error( $cid ) ) {
          $r = $this->error( $r, $cid->get_error_message(), $cid->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'Comment #' . $cid . ' updated' );
        }
        break;

      case 'wp_delete_comment':
        if ( empty( $a['comment_ID'] ) ) {
          $r = $this->error( $r, 'comment_ID required', -32602 );
          break;
        }
        $comment_id = intval( $a['comment_ID'] );
        $done = wp_delete_comment( $comment_id, !empty( $a['force'] ) );
        if ( $done ) {
          $after = get_comment( $comment_id );
          $this->add_result_text( $r, $this->deletion_outcome(
            'Comment #' . $comment_id, $after && $after->comment_approved === 'trash' ) );
        }
        else {
          $r = $this->error( $r, 'Deletion failed', -32603 );
        }
        break;

        /* ===== Change journal ===== */
      case 'wp_list_changes':
        if ( !class_exists( 'GMCP_Journal' ) || !$this->core->get_option( 'mcp_change_journal' ) ) {
          $r = $this->error( $r, 'The change journal is switched off for this site, so nothing is being recorded and nothing can be reverted. Turn it on under the MCP Server screen in the admin menu.', -32603 );
          break;
        }
        $limit = isset( $a['limit'] ) ? max( 1, min( 40, (int) $a['limit'] ) ) : 20;
        $this->add_result_text( $r, wp_json_encode( GMCP_Journal::recent( $limit ), JSON_PRETTY_PRINT ) );
        break;

      case 'wp_undo_change':
        if ( !class_exists( 'GMCP_Journal' ) || !$this->core->get_option( 'mcp_change_journal' ) ) {
          $r = $this->error( $r, 'The change journal is switched off for this site, so there is nothing on record to revert.', -32603 );
          break;
        }
        $undo_id = (string) ( $a['id'] ?? '' );
        $undo_call = (string) ( $a['call'] ?? '' );
        if ( $undo_id === '' && $undo_call === '' ) {
          $r = $this->error( $r, 'Pass id to put back one change, or call to put back everything one tool call did. Both come from wp_list_changes.', -32602 );
          break;
        }
        // Refused rather than resolved to one of them. The two mean different amounts of
        // undo, and guessing which was meant is the wrong way to be helpful about a write.
        if ( $undo_id !== '' && $undo_call !== '' ) {
          $r = $this->error( $r, 'Pass id or call, not both: one puts back a single change and the other puts back every change from one call.', -32602 );
          break;
        }
        $undo = $undo_call !== ''
          ? GMCP_Journal::revert_call( $undo_call )
          : GMCP_Journal::revert( $undo_id );
        if ( !$undo['ok'] ) {
          $r = $this->error( $r, $undo['message'], -32602 );
          break;
        }
        $this->add_result_text( $r, $undo['message'] );
        break;

        /* ===== Options ===== */
      case 'wp_get_option':
        $opt_key = $this->clean_option_key( $a['key'] );
        $permitted = $this->option_allowed( $opt_key );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        if ( !empty( $a['raw'] ) ) {
          // Read straight from the DB so neither the object cache nor an
          // option_* filter can mask the stored value. Mirrors what `wp-cli
          // option get` returns under CLI (where front-end filters aren't loaded).
          global $wpdb;
          $stored = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $opt_key
          ) );
          $val = is_null( $stored ) ? false : maybe_unserialize( $stored );
        }
        else {
          $val = get_option( $opt_key );
        }
        $this->add_result_text( $r, wp_json_encode( $val, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_update_option':
        $value = $a['value'];
        // MCP clients commonly send array/object option values as a JSON string.
        // Decode them back to native PHP arrays before writing: storing the raw
        // JSON string for an array option (e.g. sticky_posts) corrupts it and can
        // fatal hooks that expect an array (Polylang's sync_sticky_posts runs
        // array_diff on it). Scalars and plain strings are left untouched.
        if ( is_string( $value ) && isset( $value[0] ) && ( $value[0] === '[' || $value[0] === '{' ) ) {
          $decoded = json_decode( $value, true );
          if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
            $value = $decoded;
          }
        }
        $key = $this->clean_option_key( $a['key'] );
        $permitted = $this->option_allowed( $key );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        // The write policy, which option_allowed() does not cover and must not: that
        // one answers "is this row a secret" and gates reads too, and reading siteurl
        // is ordinary. This one answers "is this write safe", and without it every
        // refusal wp_update_settings makes was reachable here by naming the same row.
        $policy = GMCP_Core::option_write_policy( $key, $value );
        if ( $policy !== true ) {
          $r['error'] = [ 'code' => -32600, 'message' => $policy ];
          break;
        }
        // update_option() returns false both when the write fails AND when the value
        // already equals what is stored. Reporting the second as an error told the
        // agent its write had failed when the option held exactly what it asked for,
        // which invites a pointless retry loop. Compare against the stored value to
        // tell the two apart.
        //
        // Autoload is passed as null rather than 'yes': forcing it on meant every
        // option an agent touched was loaded into memory on every single request
        // thereafter, including options that exist to be read once. null keeps
        // whatever the option already had, and lets WordPress decide for a new one.
        $set = update_option( $key, $value, null );
        if ( $set ) {
          $this->add_result_text( $r, 'Option "' . $key . '" updated' );
        }
        elseif ( get_option( $key ) === $value ) {
          $this->add_result_text( $r, 'Option "' . $key . '" already had that value' );
        }
        else {
          $r = $this->error( $r, 'Update failed', -32603 );
        }
        break;

      case 'wp_delete_option':
        $key = $this->clean_option_key( $a['key'] ?? '' );
        if ( $key === '' ) {
          $r = $this->error( $r, 'key required', -32602 );
          break;
        }
        // The same guard wp_get_option and wp_update_option pass. There is one
        // sensitivity list on this site and this is it.
        $permitted = $this->option_allowed( $key );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        // Anything the shared write policy will not let you change, this will not let you
        // remove. Deleting a row is the harsher edit of the two, so a key too dangerous to
        // set cannot be safe to drop, and composing the two lists here means a key added to
        // the shared one is covered the day it is added rather than the day someone
        // notices. The entries this file names itself win, because they answer the question
        // that was actually asked: "set new_admin_email instead" is the right answer to a
        // write and not to a deletion.
        $undeletable = self::OPTIONS_NEVER_DELETED;
        foreach ( GMCP_Core::unwritable_options() as $name => $why ) {
          if ( !isset( $undeletable[ $name ] ) ) {
            $undeletable[ $name ] = $why;
          }
        }
        if ( isset( $undeletable[ strtolower( $key ) ] ) ) {
          $r = $this->error(
            $r,
            'The option "' . $key . '" cannot be deleted through this API: '
              . $undeletable[ strtolower( $key ) ]
              . ' Change it with wp_update_option if you need a different value.',
            -32600
          );
          break;
        }
        // "Absent" and "stored as false" are different states and get_option() returns
        // false for both, so ask with a default nothing can legitimately hold. Saying a
        // row was deleted when there was none to delete is the one answer this tool must
        // not give: the caller is deleting precisely because absence is what it needs.
        $absent = '__gmcp_option_absent__';
        $previous = get_option( $key, $absent );
        if ( $previous === $absent ) {
          $this->add_result_text( $r, 'Option "' . $key . '" does not exist; nothing was deleted.' );
          break;
        }
        // delete_option() fires deleted_option, which is what carries the deletion into
        // the audit log. The undo journal deliberately records created and updated
        // options only, so the deletion is on record but wp_undo_change cannot reverse it.
        if ( delete_option( $key ) ) {
          $text = 'Option "' . $key . '" deleted. The deletion is in the audit log, but it is not in the undo journal, so wp_undo_change cannot put it back.';
          // Which is exactly why the value comes back with the answer: nothing else kept a
          // copy, so this reply is the only chance to put the row back by hand. Withheld
          // when it looks credential-shaped, by the same test that keeps such values out
          // of the journal, so deleting cannot become a way to read one out. Withheld too
          // when it is large, on the journal's threshold, since a reply is a worse place
          // to carry a megabyte than the journal was.
          $encoded = wp_json_encode( $previous, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
          if ( GMCP_Core::holds_credential( $previous ) ) {
            $text .= ' The value is not repeated here because it holds something credential-shaped.';
          }
          elseif ( !is_string( $encoded ) || strlen( $encoded ) > GMCP_Journal::MAX_VALUE ) {
            $text .= ' The value was too large to repeat here, so it is gone.';
          }
          else {
            $text .= "\n\nWhat was removed, since nothing else kept it:\n" . $encoded;
          }
          $this->add_result_text( $r, $text );
        }
        else {
          $r = $this->error( $r, 'Deleting option "' . $key . '" failed.', -32603 );
        }
        break;

        /* ===== Theme mods ===== */
      case 'wp_get_theme_mod':
        $key = $this->clean_option_key( $a['key'] ?? '' );
        if ( $key === '' ) {
          $r = $this->error( $r, 'key required', -32602 );
          break;
        }
        // The row holds every customizer value plus nav_menu_locations and
        // sidebars_widgets, so it answers to the same guard as any other option. A site
        // that has protected theme_mods_<stylesheet> is not read around by a tool that
        // happens to know a different way in.
        $row = 'theme_mods_' . get_option( 'stylesheet' );
        $permitted = $this->option_allowed( $row );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        $has_default = array_key_exists( 'default', $a );
        $value = $has_default ? get_theme_mod( $key, $a['default'] ) : get_theme_mod( $key );
        // A theme mod can hold anything, including a value a theme author put under a
        // key named api_key. Redact the same way wp_list_theme_mods does, so a single-key
        // read does not become a way to read a secret the list tool would have blanked.
        if ( GMCP_Core::field_looks_secret( $key ) ) {
          $value = '[redacted]';
        }
        else {
          $value = GMCP_Core::redact( $value );
        }
        $r = $this->json( $r, $value );
        break;

      case 'wp_set_theme_mod':
        $key = $this->clean_option_key( $a['key'] ?? '' );
        if ( $key === '' ) {
          $r = $this->error( $r, 'key required', -32602 );
          break;
        }
        $value = $a['value'] ?? null;
        // set_theme_mod() merges one key into the theme_mods_<stylesheet> array and
        // calls update_option() under the hood, so the row passes through the same guard
        // and the same write policy as any other option write. Without the guard, a
        // site that has protected the row through gmcp_protected_options would be
        // written around by a tool that happens to know a different way in; without the
        // policy, a future rule added to option_write_policy() for theme_mods_* would
        // be bypassed here the day it is added.
        $row = 'theme_mods_' . get_option( 'stylesheet' );
        $permitted = $this->option_allowed( $row );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        $policy = GMCP_Core::option_write_policy( $row, $value );
        if ( $policy !== true ) {
          $r['error'] = [ 'code' => -32600, 'message' => $policy ];
          break;
        }
        set_theme_mod( $key, $value );
        $this->add_result_text( $r, 'Theme mod "' . $key . '" set for "' . get_option( 'stylesheet' ) . '".' );
        break;

      case 'wp_list_theme_mods':
        $row = 'theme_mods_' . get_option( 'stylesheet' );
        $permitted = $this->option_allowed( $row );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        $mods = get_theme_mods();
        if ( !is_array( $mods ) ) {
          $mods = [];
        }
        // Credential-shaped values are redacted by the same rule the option tools use,
        // so a theme mod named api_key or similar is not handed to the model in plain
        // text. redact() walks the value and blanks the leaves the same way it does for
        // an audit-log argument, keeping the shape so the caller can still see what is
        // there without seeing what was in it.
        $mods = GMCP_Core::redact( $mods );
        $r = $this->json( $r, $mods );
        break;

      case 'wp_remove_theme_mod':
        $key = $this->clean_option_key( $a['key'] ?? '' );
        if ( $key === '' ) {
          $r = $this->error( $r, 'key required', -32602 );
          break;
        }
        $row = 'theme_mods_' . get_option( 'stylesheet' );
        $permitted = $this->option_allowed( $row );
        if ( $permitted !== true ) {
          $r = $this->error( $r, $permitted, -32600 );
          break;
        }
        // remove_theme_mod() removes one key from the array and writes the row back,
        // so the previous value is journalled by the change layer the same way an
        // update is, and wp_undo_change can put it back.
        remove_theme_mod( $key );
        $this->add_result_text( $r, 'Theme mod "' . $key . '" removed for "' . get_option( 'stylesheet' ) . '". The customizer will use its default for this key.' );
        break;

        /* ===== Caches ===== */
      case 'wp_flush_cache':
        // An unrecognised scope is refused rather than quietly treated as "all". "all" is
        // the widest thing this tool does, and a typo should never widen what was asked
        // for; failing the call costs one retry and cannot surprise anyone.
        $scope = (string) ( $a['scope'] ?? 'all' );
        if ( !in_array( $scope, [ 'object', 'transients', 'post', 'all' ], true ) ) {
          $r = $this->error( $r, 'Unknown scope "' . $scope . '". Use object, transients, post or all.', -32602 );
          break;
        }
        $purged = [];
        $unpurged = [];

        if ( $scope === 'post' ) {
          $flush_id = intval( $a['ID'] ?? 0 );
          if ( !$flush_id || !get_post( $flush_id ) ) {
            $r = $this->error( $r, 'scope "post" needs the ID of an existing post.', -32602 );
            break;
          }
          // The existing per-post buster, which already fans out to the post-level
          // purges of LiteSpeed and WP Rocket and to gmcp_post_changed. A site-wide
          // page purge is deliberately NOT fired here: the caller asked about one post.
          // Note that bust_post_cache() ignores a repeat for the same post within one
          // PHP request, so flushing a post a tool just wrote in the same batched call
          // is already done rather than done twice.
          $this->bust_post_cache( $flush_id, [ 'tool' => 'wp_flush_cache' ] );
          $purged[] = 'Post #' . $flush_id . ': object cache entries, plus the per-post purges of any LiteSpeed or WP Rocket install';
          $unpurged[] = 'Every other post, and any site-wide page cache. Use scope "all" for those.';
        }
        else {
          if ( $scope === 'object' || $scope === 'all' ) {
            if ( wp_cache_flush() ) {
              $purged[] = 'Object cache: every entry, so the next request rebuilds from the database';
            }
            else {
              $unpurged[] = 'Object cache: wp_cache_flush() reported failure, so assume it still holds its entries';
            }
          }
          if ( $scope === 'transients' || $scope === 'all' ) {
            // Expired transients only. Deleting unexpired ones throws away work that has
            // already been done rather than data that has gone stale, which is a cost
            // with no cache-correctness benefit, so this tool does not offer it.
            delete_expired_transients();
            $purged[] = wp_using_ext_object_cache()
              ? 'Expired transients: this site keeps transients in the object cache, where they expire on their own, so there was nothing in the database to remove'
              : 'Expired transients (unexpired ones are left alone: they hold work already done, not stale data)';
          }
          $page_caches = $this->purge_page_caches();
          if ( $page_caches ) {
            $purged[] = 'Page caches: ' . implode( ', ', $page_caches );
          }
          else {
            $unpurged[] = 'No page-cache plugin this tool can recognise is active (it knows LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache and SpeedyCache). Any other one is untouched.';
          }
        }

        // The delegation half of the plugin's cache stance: anything we cannot name is
        // somebody else's to purge, and this is where they hook to do it.
        do_action( 'gmcp_cache_flushed', $scope, [ 'source' => 'mcp', 'tool' => 'wp_flush_cache', 'ID' => intval( $a['ID'] ?? 0 ) ] );

        $unpurged[] = 'Any CDN or reverse proxy: Cloudflare, Varnish, Fastly, a host edge cache. None of these can be reached from PHP, so PURGE THESE YOURSELF or the front end keeps serving the old page. A site can wire the gmcp_cache_flushed action to do it automatically.';
        $unpurged[] = 'Pages already delivered to visitors: browser caches and service workers keep serving what they have until it expires.';

        $lines = [ 'Scope: ' . $scope, '', 'Purged:' ];
        foreach ( $purged as $line ) {
          $lines[] = '- ' . $line;
        }
        if ( !$purged ) {
          $lines[] = '- nothing';
        }
        $lines[] = '';
        $lines[] = 'NOT purged, and still stale until you deal with it:';
        foreach ( $unpurged as $line ) {
          $lines[] = '- ' . $line;
        }
        $this->add_result_text( $r, implode( "\n", $lines ) );
        break;

      case 'wp_purge_url':
        $raw_urls = $a['urls'] ?? null;
        // One URL sent as a bare string is the shape a model reaches for first and it is
        // unambiguous, so it is accepted rather than refused over punctuation.
        if ( is_string( $raw_urls ) ) {
          $raw_urls = [ $raw_urls ];
        }
        if ( !is_array( $raw_urls ) || !$raw_urls ) {
          $r = $this->error( $r, 'wp_purge_url needs "urls": a list of one or more URLs on this site.', -32602 );
          break;
        }
        if ( count( $raw_urls ) > self::PURGE_URL_MAX ) {
          $r = $this->error( $r, 'That call named ' . count( $raw_urls ) . ' URLs, and wp_purge_url takes at most ' . self::PURGE_URL_MAX . ' in one call. Nothing was purged. Split the list, or use wp_flush_cache if the whole site really is stale. The cap is refused rather than trimmed so that no URL is left believed-fresh.', -32602 );
          break;
        }

        // Every URL is resolved before any of them is purged, and one bad entry refuses
        // the whole call. Purging nineteen and burying "number seven was refused" in the
        // reply is the shape of mistake that gets skimmed past, and stopping here costs
        // nothing because nothing has happened yet.
        $purge_targets = [];
        $purge_refused = [];
        foreach ( $raw_urls as $raw_url ) {
          if ( !is_scalar( $raw_url ) ) {
            $purge_refused[] = 'an entry that is not a string';
            continue;
          }
          [ $purge_target, $purge_why ] = $this->purge_url_target( (string) $raw_url );
          if ( $purge_target === '' ) {
            $purge_refused[] = '"' . (string) $raw_url . '": ' . $purge_why;
            continue;
          }
          // Two spellings of one page, "/contact/" and its full URL, are one purge.
          $purge_targets[ $purge_target ] = true;
        }
        if ( $purge_refused ) {
          $r = $this->error(
            $r,
            'Nothing was purged, because this call names something that is not a URL on this site (' . home_url( '/' ) . "):\n- "
              . implode( "\n- ", $purge_refused )
              . "\nThe whole call is refused rather than the bad entries alone, so that no page is left believed-fresh.",
            -32602
          );
          break;
        }
        $purge_targets = array_keys( $purge_targets );

        $purge_lines = [];
        $purge_absent = [];
        $purge_any = false;
        // Purged and recognised are different claims, and the headline needs both. A site
        // running only WP Super Cache, asked for URLs that all carry a query string, has
        // purged nothing AND has a page cache, so neither "purged" nor "no page cache
        // here" would be true of it.
        $purge_recognised = false;
        foreach ( $purge_targets as $purge_target ) {
          $purge_outcome = $this->purge_url_caches( $purge_target );
          // Which plugins are absent is a property of the site rather than of the URL, so
          // every pass reports the same list and it is stated once, below, instead of
          // being repeated under each URL.
          $purge_absent = $purge_outcome['absent'];
          $purge_lines[] = $purge_target;
          foreach ( $purge_outcome['purged'] as $purge_name ) {
            $purge_lines[] = '  purged: ' . $purge_name;
            $purge_any = true;
            $purge_recognised = true;
          }
          foreach ( $purge_outcome['declined'] as $purge_note ) {
            $purge_lines[] = '  NOT purged: ' . $purge_note;
            $purge_recognised = true;
          }
          if ( !$purge_outcome['purged'] && !$purge_outcome['declined'] ) {
            $purge_lines[] = '  purged: nothing, for the reason above';
          }
        }

        // Sites wire their own CDN and reverse-proxy purges, and a per-URL purge needs the
        // URL, which gmcp_cache_flushed's scope string has nowhere to carry. Hence a
        // URL-shaped action of its own, fired once per URL.
        //
        // gmcp_cache_flushed still fires when nothing listens to the new one. A site that
        // wired a CDN purge to it before this tool existed must not be silently bypassed
        // by a tool added afterwards: dropping the whole edge cache is broader than was
        // asked for, but a reply saying "purged" while the edge keeps serving the old page
        // is the failure this plugin is written against, and over-purging is the direction
        // that can be walked back. Wiring gmcp_url_purged is what turns the fallback off.
        $purge_hook_wired = has_action( 'gmcp_url_purged' );
        foreach ( $purge_targets as $purge_target ) {
          do_action( 'gmcp_url_purged', $purge_target, [ 'source' => 'mcp', 'tool' => 'wp_purge_url' ] );
        }
        if ( !$purge_hook_wired ) {
          do_action( 'gmcp_cache_flushed', 'url', [ 'source' => 'mcp', 'tool' => 'wp_purge_url', 'urls' => $purge_targets ] );
        }

        $lines = [];
        if ( $purge_any ) {
          $lines[] = 'Purged ' . count( $purge_targets ) . ( count( $purge_targets ) === 1 ? ' URL on ' : ' URLs on ' ) . home_url( '/' ) . '.';
        }
        elseif ( $purge_recognised ) {
          $lines[] = 'NOTHING WAS PURGED. The page caches this tool recognised here were asked and did nothing with these URLs; each one says why below.';
        }
        else {
          $lines[] = 'NOTHING WAS PURGED. No page-cache plugin that this tool can purge a single URL of is active on this site: it knows LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache and SpeedyCache, and none of them answered. If this site caches pages some other way, these URLs are still being served from it.';
        }
        $lines[] = '';
        $lines = array_merge( $lines, $purge_lines );
        $lines[] = '';
        $lines[] = 'NOT purged, and still stale until you deal with it:';
        // Only worth saying when some of the five WERE here. When none were, the opening
        // line has already named all five and repeating them reads as a second finding.
        if ( $purge_absent && $purge_recognised ) {
          $lines[] = '- Not active here, so nothing was asked of them: ' . implode( ', ', $purge_absent ) . '.';
        }
        $lines[] = '- Any other page-cache plugin: this tool knows only the five named above.';
        $lines[] = '- The object cache. Its entries are keyed by post, option and term rather than by URL, so there is no per-URL purge to make. wp_flush_cache scope "post" clears one post; scope "object" clears every entry and makes the next request for every page rebuild from the database.';
        $lines[] = $purge_hook_wired
          ? '- Any CDN or reverse proxy, unless the gmcp_url_purged listener this site has wired reaches it. Nothing in PHP reaches Cloudflare, Varnish, Fastly or a host edge cache on its own, so confirm that listener covers these URLs before believing the front end is fresh.'
          : '- Any CDN or reverse proxy: Cloudflare, Varnish, Fastly, a host edge cache. None of these can be reached from PHP and nothing on this site listens to gmcp_url_purged, so PURGE THESE YOURSELF or the front end keeps serving the old page.';
        $lines[] = '- Pages already delivered to visitors: browser caches and service workers keep serving what they have until it expires.';
        $lines[] = '';
        $lines[] = $purge_hook_wired
          ? 'Hooks: gmcp_url_purged fired once per URL. gmcp_cache_flushed was NOT fired, because this site listens to gmcp_url_purged and a site-wide purge is not what was asked for.'
          : 'Hooks: gmcp_url_purged fired once per URL, and nothing on this site listens to it. gmcp_cache_flushed also fired with scope "url", as the fallback for a CDN purge wired before this tool existed; a listener there that ignores its scope argument will have purged the whole site.';
        $this->add_result_text( $r, implode( "\n", $lines ) );
        break;

        /* ===== Counts ===== */
      case 'wp_count_posts':
        $pt = sanitize_key( $a['post_type'] ?? 'post' );
        $obj = wp_count_posts( $pt );
        $this->add_result_text( $r, wp_json_encode( $obj, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_count_terms':
        $tax = sanitize_key( $a['taxonomy'] );
        $total = wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        if ( is_wp_error( $total ) ) {
          $r = $this->error( $r, $total->get_error_message(), $total->get_error_code() );
        }
        else {
          $this->add_result_text( $r, (string) $total );
        }
        break;

      case 'wp_count_media':
        $args = [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids' ];
        $d = [];
        if ( $a['after'] ?? '' ) {
          $d['after'] = $a['after'];
        }
        if ( $a['before'] ?? '' ) {
          $d['before'] = $a['before'];
        }
        if ( $d ) {
          $args['date_query'] = [ $d ];
        }
        $total = count( get_posts( $args ) );
        $this->add_result_text( $r, (string) $total );
        break;

        /* ===== Post-types ===== */
      case 'wp_get_post_types':
        $out = [];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
          $out[] = [ 'key' => $pt->name, 'label' => $pt->label ];
        }
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Plugins ===== */
      case 'wp_list_plugins':
        if ( !function_exists( 'get_plugins' ) ) {
          require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $search = sanitize_text_field( $a['search'] ?? '' );
        $out = [];
        // The array key is the plugin file ("akismet/akismet.php"), which is the
        // identifier every activate/deactivate/delete/update tool takes. Dropping it
        // meant the one tool that lists plugins could not tell a caller how to name
        // one, so an agent had to guess the file from the display name.
        foreach ( get_plugins() as $file => $p ) {
          if ( !$search || stripos( $p['Name'], $search ) !== false ) {
            $out[] = [
              'plugin' => $file,
              'Name' => $p['Name'],
              'Version' => $p['Version'],
              'active' => is_plugin_active( $file ),
            ];
          }
        }
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Posts: list ===== */
      case 'wp_get_posts':
        $q = [
          'post_type' => sanitize_key( $a['post_type'] ?? 'post' ),
          'post_status' => sanitize_key( $a['post_status'] ?? 'publish' ),
          's' => sanitize_text_field( $a['search'] ?? '' ),
          'posts_per_page' => max( 1, min( 500, intval( $a['limit'] ?? 10 ) ) ),
        ];
        if ( isset( $a['offset'] ) ) {
          $q['offset'] = max( 0, intval( $a['offset'] ) );
        }
        if ( isset( $a['paged'] ) ) {
          $q['paged'] = max( 1, intval( $a['paged'] ) );
        }
        if ( isset( $a['author'] ) ) {
          $q['author'] = intval( $a['author'] );
        }
        elseif ( $a['author_name'] ?? '' ) {
          $q['author_name'] = sanitize_title( $a['author_name'] );
        }
        if ( !empty( $a['author__not_in'] ) && is_array( $a['author__not_in'] ) ) {
          $q['author__not_in'] = array_map( 'intval', $a['author__not_in'] );
        }
        $date = [];
        if ( $a['after'] ?? '' ) {
          $date['after'] = $a['after'];
        }
        if ( $a['before'] ?? '' ) {
          $date['before'] = $a['before'];
        }
        if ( $date ) {
          $q['date_query'] = [ $date ];
        }
        $rows = [];
        foreach ( get_posts( $q ) as $p ) {
          $rows[] = [
            'ID' => $p->ID,
            'post_title' => $p->post_title,
            'post_status' => $p->post_status,
            'post_excerpt' => $this->post_excerpt( $p ),
            'permalink' => get_permalink( $p ),
          ];
        }
        $this->add_result_text( $r, wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Posts: single ===== */
      case 'wp_get_post':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'Post ID required (pass "ID", e.g. {"ID": 123}; "post_id" is also accepted).', -32602 );
          break;
        }
        $p = get_post( intval( $a['ID'] ) );
        if ( !$p ) {
          $r = $this->error( $r, 'Post not found', -32602 );
          break;
        }
        $out = [
          'ID' => $p->ID,
          'post_title' => $p->post_title,
          'post_status' => $p->post_status,
          'post_content' => ( ( $a['content_format'] ?? 'full' ) === 'prose' )
            ? $this->prose_content( $p->post_content )
            : $this->read_html( $p->post_content ),
          'post_excerpt' => $this->post_excerpt( $p ),
          'permalink' => get_permalink( $p ),
          'post_date' => $p->post_date,
          'post_modified' => $p->post_modified,
        ];
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Posts: snapshot ===== */
      case 'wp_get_post_snapshot':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'Post ID required (pass "ID", e.g. {"ID": 123}; "post_id" is also accepted).', -32602 );
          break;
        }

        $post_id = intval( $a['ID'] );
        $p = get_post( $post_id );

        if ( !$p ) {
          $r = $this->error( $r, 'Post not found', -32602 );
          break;
        }

        $include = $a['include'] ?? [ 'meta', 'terms', 'thumbnail', 'author' ];
        $exclude = $a['exclude'] ?? [];

        // Handle JSON strings (some MCP clients send arrays as JSON strings)
        if ( is_string( $include ) ) {
          $include = json_decode( $include, true ) ?? [];
        }
        if ( is_string( $exclude ) ) {
          $exclude = json_decode( $exclude, true ) ?? [];
        }

        $snapshot = [
          'post' => [
            'ID' => $p->ID,
            'post_title' => $p->post_title,
            'post_type' => $p->post_type,
            'post_status' => $p->post_status,
            'post_excerpt' => $this->post_excerpt( $p ),
            'post_name' => $p->post_name,
            'permalink' => get_permalink( $p ),
            'post_date' => $p->post_date,
            'post_modified' => $p->post_modified,
          ],
        ];

        // Include content unless excluded (useful for posts with huge content)
        if ( !in_array( 'content', $exclude ) ) {
          $snapshot['post']['post_content'] = ( ( $a['content_format'] ?? 'full' ) === 'prose' )
            ? $this->prose_content( $p->post_content )
            : $this->read_html( $p->post_content );
        }

        // Include all post meta
        if ( in_array( 'meta', $include ) ) {
          $snapshot['meta'] = [];
          $all_meta = get_post_meta( $post_id );
          foreach ( $all_meta as $key => $value ) {
            if ( is_array( $value ) && count( $value ) === 1 ) {
              $snapshot['meta'][ $key ] = maybe_unserialize( $value[0] );
            }
            else {
              $snapshot['meta'][ $key ] = array_map( 'maybe_unserialize', $value );
            }
          }
        }

        // Include all taxonomies and their terms
        if ( in_array( 'terms', $include ) ) {
          $snapshot['terms'] = [];
          $taxonomies = get_object_taxonomies( $p->post_type );
          foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'all' ] );
            if ( !is_wp_error( $terms ) && !empty( $terms ) ) {
              $snapshot['terms'][ $taxonomy ] = array_map( function ( $t ) {
                return [
                  'term_id' => $t->term_id,
                  'name' => $t->name,
                  'slug' => $t->slug,
                ];
              }, $terms );
            }
          }
        }

        // Include featured image
        if ( in_array( 'thumbnail', $include ) ) {
          $thumb_id = get_post_thumbnail_id( $post_id );
          if ( $thumb_id ) {
            $snapshot['thumbnail'] = [
              'ID' => $thumb_id,
              'url' => wp_get_attachment_url( $thumb_id ),
              'alt' => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
            ];
          }
        }

        // Include author
        if ( in_array( 'author', $include ) ) {
          $author = get_userdata( $p->post_author );
          if ( $author ) {
            $snapshot['author'] = [
              'ID' => $author->ID,
              'display_name' => $author->display_name,
              'user_login' => $author->user_login,
            ];
          }
        }

        $this->add_result_text( $r, wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Posts: create ===== */
      case 'wp_create_post':
        if ( empty( $a['post_title'] ) ) {
          $r = $this->error( $r, 'post_title required', -32602 );
          break;
        }
        $new = $this->create_one_post( $a, 'wp_create_post' );
        if ( is_wp_error( $new ) ) {
          $r = $this->error( $r, $new->get_error_message(), $new->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'Post created ID ' . $new );
        }
        break;

        /* ===== Posts: create in a batch ===== */
      case 'wp_create_posts':
        // Same concession the single create makes for meta_input: some MCP clients send a
        // structured argument as a JSON string.
        $items = $a['items'] ?? null;
        if ( is_string( $items ) ) {
          $items = json_decode( $items, true );
        }
        if ( !is_array( $items ) || !$items ) {
          $r = $this->error( $r, '"items" must be a non-empty array of post objects, each shaped like a wp_create_post call.', -32602 );
          break;
        }
        // Positions, so every index the reply prints indexes the list the caller sent.
        $items = array_values( $items );

        // THE CAP, CHECKED BEFORE ANYTHING IS WRITTEN. A cap enforced part way through
        // would be the batch's own failure mode with none of its reporting: some posts
        // written, and a refusal that reads as though none were.
        if ( count( $items ) > self::CREATE_BATCH_MAX ) {
          $r = $this->error( $r, 'A batch is capped at ' . self::CREATE_BATCH_MAX . ' items and this one has '
            . count( $items ) . '. Nothing was created. The cap is what keeps a batch legible afterwards: past it the'
            . ' audit log stops listing the changes and starts counting them, and the change journal holds '
            . GMCP_Journal::LIMIT . ' entries for the whole site, so one oversized call would push everything else off'
            . ' the undo list. Send the first ' . self::CREATE_BATCH_MAX . ' and then the rest.', -32602 );
          break;
        }

        // VALIDATED UP FRONT, AND ONLY FOR WHAT CAN HONESTLY BE CHECKED WITHOUT WRITING.
        // Stopping at the first failure still leaves the earlier items written, so the
        // mistakes worth catching are the ones a hand-built list of fifteen really makes:
        // an entry that is not an object, and a missing title. Both are certain, both are
        // free, and catching them here means a typo at item twelve costs nothing instead
        // of leaving eleven pages behind.
        //
        // It deliberately does NOT check post_type or post_status against what is
        // registered. Those would be guards the single create does not have, and a batch
        // that refuses what wp_create_post accepts is as much a divergence as one that
        // accepts what wp_create_post refuses. It is the same rule read the other way.
        //
        // So this narrows the window rather than closing it. wp_insert_post can still fail
        // on the seventh item for a reason nothing here could predict, a database error or
        // a plugin vetoing the save, which is why the three lists below exist at all.
        $unfit = [];
        foreach ( $items as $i => $item ) {
          if ( !is_array( $item ) ) {
            $unfit[] = 'item ' . $i . ' is not an object';
            continue;
          }
          if ( empty( $item['post_title'] ) ) {
            $unfit[] = 'item ' . $i . ' has no post_title';
          }
        }
        if ( $unfit ) {
          $r = $this->error( $r, 'Nothing was created. ' . count( $unfit ) . ' of ' . count( $items )
            . ' items would have failed, and they were found before anything was written: '
            . implode( '; ', $unfit ) . '.', -32602 );
          break;
        }

        $batch_created = [];
        $batch_failed = [];
        $batch_wrote_meta = false;
        $batch_stopped = -1;
        foreach ( $items as $i => $item ) {
          if ( !empty( $item['meta_input'] ) ) {
            $batch_wrote_meta = true;
          }
          $made = $this->create_one_post( $item, 'wp_create_posts' );
          if ( is_wp_error( $made ) ) {
            $batch_failed[] = [
              'index' => $i,
              'post_title' => (string) $item['post_title'],
              'reason' => $made->get_error_message(),
            ];
            $batch_stopped = $i;
            break;
          }
          $batch_created[] = [
            'index' => $i,
            'ID' => (int) $made,
            // The STORED title, read raw. The filtering happened on the way in and the
            // reply should show what the site now holds rather than echo what was asked
            // for: a caller comparing the two is exactly how a filter that stopped running
            // would be noticed.
            'post_title' => (string) get_post_field( 'post_title', (int) $made, 'raw' ),
          ];
        }

        // THE UNATTEMPTED HALF IS THE USABLE HALF. A list of what was skipped is only
        // decorative if the caller still has to work out where to resume, so this names
        // the slice point: retry_from_index indexes the array they sent, and resending
        // items from there is the whole remainder and nothing already written.
        $batch_untried = [];
        if ( $batch_stopped >= 0 ) {
          for ( $i = $batch_stopped + 1; $i < count( $items ); $i++ ) {
            $batch_untried[] = [
              'index' => $i,
              'post_title' => (string) ( $items[ $i ]['post_title'] ?? '' ),
            ];
          }
        }

        $batch_summary = 'Created ' . count( $batch_created ) . ' of ' . count( $items ) . '.';
        if ( $batch_failed ) {
          $batch_summary .= ' Item ' . $batch_stopped . ' failed, so the run stopped there and '
            . count( $batch_untried ) . ' later item' . ( count( $batch_untried ) === 1 ? ' was' : 's were' )
            . ' never attempted. The items before it are already written and nothing here rolls them back.';
        }

        $this->add_result_text( $r, wp_json_encode( [
          'summary' => $batch_summary,
          'created' => $batch_created,
          // At most one entry, because the run stops at the first failure. A list rather
          // than a lone object so the caller parses all three the same way.
          'failed' => $batch_failed,
          'not_attempted' => $batch_untried,
          'retry_from_index' => $batch_stopped >= 0 ? $batch_stopped : null,
          'undo' => $this->batch_undo_note( $batch_wrote_meta ),
        ], JSON_PRETTY_PRINT ) );
        break;

        /* ===== Posts: duplicate ===== */
      case 'wp_duplicate_post':
        $src = get_post( intval( $a['ID'] ?? 0 ) );
        if ( !$src ) {
          $r = $this->error( $r, 'No post with ID ' . intval( $a['ID'] ?? 0 ) . '.', -32602 );
          break;
        }
        $dup = [
          'post_title' => ( $a['post_title'] ?? '' ) !== '' ? sanitize_text_field( $a['post_title'] ) : $src->post_title,
          // A copy is a draft unless the caller says otherwise, and the source's own
          // status is never inherited. The whole premise of this plugin is that one
          // misread sentence should not put something in front of the public, and
          // "duplicate this page" read off a comment would otherwise publish a second
          // live page nobody asked for. Going live stays a separate, deliberate call.
          'post_status' => sanitize_key( $a['post_status'] ?? 'draft' ),
          'post_type' => $src->post_type,
          // Stored content is copied verbatim, not re-sanitized. It is already on this
          // site and store_html() is about markup an agent is introducing; running it
          // over an existing page would quietly rewrite the original's markup in the copy.
          'post_content' => $src->post_content,
          'post_excerpt' => $src->post_excerpt,
          'post_parent' => $src->post_parent,
          'menu_order' => $src->menu_order,
          'comment_status' => $src->comment_status,
          'ping_status' => $src->ping_status,
        ];
        // wp_insert_post rather than a direct write, so the change listener sees the
        // creation and the undo journal can account for it.
        $copy_id = wp_insert_post( wp_slash( $dup ), true );
        if ( is_wp_error( $copy_id ) ) {
          $r = $this->error( $r, $copy_id->get_error_message(), $copy_id->get_error_code() );
          break;
        }
        $copy_id = (int) $copy_id;

        $dup_lines = [ 'Duplicated post #' . $src->ID . ' as new post ID ' . $copy_id . ' ("' . $dup['post_title'] . '", status ' . $dup['post_status'] . ').' ];

        if ( !isset( $a['include_meta'] ) || $a['include_meta'] ) {
          // The target is brand new, so overwrite is the honest setting: there is
          // nothing of its own to protect.
          [ $dup_copied, $dup_skipped ] = $this->copy_post_meta( (int) $src->ID, $copy_id, [], true );
          $dup_lines[] = 'Meta: ' . count( $dup_copied ) . ' key(s) copied, ' . array_sum( array_column( $dup_copied, 'bytes' ) ) . ' bytes'
            . ( $dup_skipped ? ', skipped ' . implode( ', ', array_keys( $dup_skipped ) ) : '' ) . '.';
        }
        else {
          $dup_lines[] = 'Meta: not copied (include_meta false).';
        }

        if ( !isset( $a['include_terms'] ) || $a['include_terms'] ) {
          $dup_terms = [];
          foreach ( get_object_taxonomies( $src->post_type ) as $dup_tax ) {
            $dup_ids = wp_get_object_terms( $src->ID, $dup_tax, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $dup_ids ) || !$dup_ids ) {
              continue;
            }
            wp_set_object_terms( $copy_id, $dup_ids, $dup_tax );
            $dup_terms[] = $dup_tax . ' (' . count( $dup_ids ) . ')';
          }
          $dup_lines[] = 'Terms: ' . ( $dup_terms ? implode( ', ', $dup_terms ) : 'none assigned on the source' ) . '.';
        }
        else {
          $dup_lines[] = 'Terms: not copied (include_terms false).';
        }

        $this->bust_post_cache( $copy_id, [ 'tool' => 'wp_duplicate_post' ] );
        $this->add_result_text( $r, implode( "\n", $dup_lines ) );
        break;

        /* ===== Posts: write blocks ===== */
      case 'wp_write_blocks':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'Post ID required (pass "ID"; create the post first with wp_create_post).', -32602 );
          break;
        }
        $wb_id = intval( $a['ID'] );
        $wb_post = get_post( $wb_id );
        if ( !$wb_post ) {
          $r = $this->error( $r, 'Post ' . $wb_id . ' not found.', -32602 );
          break;
        }
        // Some MCP clients send arrays as JSON strings.
        $wb_blocks = $a['blocks'] ?? null;
        if ( is_string( $wb_blocks ) ) {
          $wb_blocks = json_decode( $wb_blocks, true );
        }
        list( $wb_markup, $wb_err ) = $this->blocks_to_markup( $wb_blocks );
        if ( $wb_err !== null ) {
          $r = $this->error( $r, $wb_err, -32602 );
          break;
        }
        $wb_mode = in_array( $a['mode'] ?? 'replace', [ 'replace', 'append', 'prepend' ], true ) ? ( $a['mode'] ?? 'replace' ) : 'replace';
        if ( $wb_mode === 'append' ) {
          $wb_content = trim( $wb_post->post_content . "\n\n" . $wb_markup );
        }
        elseif ( $wb_mode === 'prepend' ) {
          $wb_content = trim( $wb_markup . "\n\n" . $wb_post->post_content );
        }
        else {
          $wb_content = $wb_markup;
        }
        $wb_res = wp_update_post( wp_slash( [ 'ID' => $wb_id, 'post_content' => $wb_content ] ), true );
        if ( is_wp_error( $wb_res ) ) {
          $r = $this->error( $r, $wb_res->get_error_message(), $wb_res->get_error_code() );
          break;
        }
        $this->bust_post_cache( $wb_id, [ 'tool' => 'wp_write_blocks' ] );
        $this->add_result_text( $r, 'Wrote ' . count( $wb_blocks ) . ' block(s) to post ' . $wb_id . ' (mode: ' . $wb_mode . ').' );
        break;

        /* ===== Block patterns: list ===== */
      case 'wp_list_block_patterns':
        if ( !class_exists( 'WP_Block_Patterns_Registry' ) ) {
          $r = $this->error( $r, 'Block patterns are not available on this site.', -32603 );
          break;
        }
        $bp_all = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
        $bp_search = isset( $a['search'] ) ? strtolower( trim( (string) $a['search'] ) ) : '';
        $bp_cat = isset( $a['category'] ) ? sanitize_title( $a['category'] ) : '';
        $bp_content = !empty( $a['include_content'] );
        $bp_limit = isset( $a['limit'] ) ? max( 1, min( 500, (int) $a['limit'] ) ) : 50;
        $bp_list = [];
        foreach ( $bp_all as $pat ) {
          $cats = (array) ( $pat['categories'] ?? [] );
          if ( $bp_cat !== '' && !in_array( $bp_cat, array_map( 'sanitize_title', $cats ), true ) ) {
            continue;
          }
          if ( $bp_search !== '' ) {
            $hay = strtolower( ( $pat['title'] ?? '' ) . ' ' . ( $pat['name'] ?? '' ) . ' ' . ( $pat['description'] ?? '' ) . ' ' . implode( ' ', (array) ( $pat['keywords'] ?? [] ) ) );
            if ( strpos( $hay, $bp_search ) === false ) {
              continue;
            }
          }
          $entry = [
            'name' => $pat['name'] ?? '',
            'title' => $pat['title'] ?? '',
            'categories' => array_values( $cats ),
            'description' => $pat['description'] ?? '',
          ];
          if ( $bp_content ) {
            $entry['content'] = $pat['content'] ?? '';
          }
          $bp_list[] = $entry;
          if ( count( $bp_list ) >= $bp_limit ) {
            break;
          }
        }
        $this->add_result_text( $r, wp_json_encode( [ 'count' => count( $bp_list ), 'total_registered' => count( $bp_all ), 'patterns' => $bp_list ], JSON_PRETTY_PRINT ) );
        break;

        /* ===== Block patterns: insert ===== */
      case 'wp_insert_block_pattern':
        if ( empty( $a['ID'] ) || empty( $a['pattern'] ) ) {
          $r = $this->error( $r, 'Both "ID" and "pattern" (a name from wp_list_block_patterns) are required.', -32602 );
          break;
        }
        if ( !class_exists( 'WP_Block_Patterns_Registry' ) ) {
          $r = $this->error( $r, 'Block patterns are not available on this site.', -32603 );
          break;
        }
        $bp_name = sanitize_text_field( $a['pattern'] );
        $bp_reg = WP_Block_Patterns_Registry::get_instance();
        if ( !$bp_reg->is_registered( $bp_name ) ) {
          $r = $this->error( $r, 'Pattern "' . $bp_name . '" is not registered. Use wp_list_block_patterns to see available names.', -32602 );
          break;
        }
        $bp_pat = $bp_reg->get_registered( $bp_name );
        $bp_markup = (string) ( $bp_pat['content'] ?? '' );
        if ( $bp_markup === '' ) {
          $r = $this->error( $r, 'Pattern "' . $bp_name . '" has no content.', -32603 );
          break;
        }
        $bp_id = intval( $a['ID'] );
        $bp_post = get_post( $bp_id );
        if ( !$bp_post ) {
          $r = $this->error( $r, 'Post ' . $bp_id . ' not found.', -32602 );
          break;
        }
        $bp_mode = in_array( $a['mode'] ?? 'append', [ 'replace', 'append', 'prepend' ], true ) ? ( $a['mode'] ?? 'append' ) : 'append';
        if ( $bp_mode === 'replace' ) {
          $bp_new = $bp_markup;
        }
        elseif ( $bp_mode === 'prepend' ) {
          $bp_new = trim( $bp_markup . "\n\n" . $bp_post->post_content );
        }
        else {
          $bp_new = trim( $bp_post->post_content . "\n\n" . $bp_markup );
        }
        $bp_res = wp_update_post( wp_slash( [ 'ID' => $bp_id, 'post_content' => $bp_new ] ), true );
        if ( is_wp_error( $bp_res ) ) {
          $r = $this->error( $r, $bp_res->get_error_message(), $bp_res->get_error_code() );
          break;
        }
        $this->bust_post_cache( $bp_id, [ 'tool' => 'wp_insert_block_pattern' ] );
        $this->add_result_text( $r, 'Inserted pattern "' . $bp_name . '" into post ' . $bp_id . ' (mode: ' . $bp_mode . ').' );
        break;

        /* ===== Posts: update ===== */
      case 'wp_update_post':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'Post ID required (pass "ID", e.g. {"ID": 123}; "post_id" is also accepted).', -32602 );
          break;
        }
        $post_id = intval( $a['ID'] );
        $c = [ 'ID' => $post_id ];

        // Handle JSON strings (some MCP clients send objects as JSON strings)
        $fields_raw = $a['fields'] ?? null;
        $fields = $fields_raw;
        if ( is_string( $fields ) ) {
          $fields = json_decode( $fields, true );
          // Detect truncated/malformed JSON
          if ( $fields === null && strlen( $fields_raw ) > 0 ) {
            $r = $this->error( $r, 'Fields parameter is invalid JSON (possibly truncated). Content may be too large for the transport. Raw length: ' . strlen( $fields_raw ) . ' bytes', -32602 );
            break;
          }
        }
        $fields = $fields ?? [];
        if ( !is_array( $fields ) ) {
          $fields = [];
        }

        // Convenience: also accept post fields passed at the top level instead of
        // nested in "fields". Agents routinely send { ID, post_title } directly and
        // would otherwise get a misleading "no fields provided" error. Nested
        // values win on conflict.
        $topLevelFields = [ 'post_title', 'post_content', 'post_status', 'post_name',
          'post_excerpt', 'post_category', 'post_type', 'post_author', 'post_parent',
          'post_date', 'menu_order', 'comment_status', 'ping_status', 'page_template' ];
        foreach ( $topLevelFields as $fk ) {
          if ( array_key_exists( $fk, $a ) && !array_key_exists( $fk, $fields ) ) {
            $fields[ $fk ] = $a[ $fk ];
          }
        }

        // Track what we're trying to update for verification
        $content_to_verify = null;
        if ( !empty( $fields ) && is_array( $fields ) ) {
          foreach ( $fields as $k => $v ) {
            $c[ $k ] = in_array( $k, [ 'post_content', 'post_excerpt' ], true ) ? $this->store_html( $v ) : sanitize_text_field( $v );
          }
          if ( isset( $c['post_content'] ) ) {
            $content_to_verify = $c['post_content'];
          }
        }

        // Handle schedule_for convenience parameter
        if ( !empty( $a['schedule_for'] ) ) {
          $schedule_date = sanitize_text_field( $a['schedule_for'] );
          $c['post_status'] = 'future';
          $c['post_date'] = $schedule_date;
          $c['post_date_gmt'] = get_gmt_from_date( $schedule_date );
          $c['edit_date'] = true; // Required for WordPress to respect date changes
        }

        // Handle JSON strings for meta_input
        $meta_raw = $a['meta_input'] ?? null;
        $meta_input = $meta_raw;
        if ( is_string( $meta_input ) ) {
          $meta_input = json_decode( $meta_input, true );
          if ( $meta_input === null && strlen( $meta_raw ) > 0 ) {
            $r = $this->error( $r, 'meta_input parameter is invalid JSON (possibly truncated).', -32602 );
            break;
          }
        }
        $meta_input = $meta_input ?? [];
        $has_meta = !empty( $meta_input ) && is_array( $meta_input );
        $has_fields = count( $c ) > 1;

        // Error if nothing to update
        if ( !$has_fields && !$has_meta ) {
          $hint = '';
          if ( isset( $a['fields'] ) || isset( $a['meta_input'] ) ) {
            $hint = ' (parameters were provided but parsed as empty - check for malformed JSON)';
          }
          $r = $this->error( $r, 'No fields or meta_input provided to update. Pass post fields inside a "fields" object (or at the top level), e.g. {"ID": 123, "fields": {"post_title": "..."}}, and/or "meta_input" for custom fields.' . $hint, -32602 );
          break;
        }

        // Meta keys are checked here, before the post fields are written, because this
        // tool writes the two in one call and a key refused after wp_update_post() has
        // run would leave the post half updated. wp_create_post already stores these keys
        // verbatim, since wp_insert_post() writes meta_input itself; this tool used to
        // sanitize_key() them, so the same key meant two different rows depending on
        // which tool the caller reached for. @see meta_key_allowed().
        if ( $has_meta ) {
          $meta_refusal = null;
          foreach ( array_keys( $meta_input ) as $meta_k ) {
            $meta_why = $this->meta_key_allowed( (string) $meta_k );
            if ( $meta_why !== true ) {
              $meta_refusal = $meta_why;
              break;
            }
          }
          if ( $meta_refusal !== null ) {
            $r = $this->error( $r, $meta_refusal . ' Nothing was written, post fields included.', -32602 );
            break;
          }
        }

        // Taking a referenced template out of publish is the same breakage as deleting it,
        // reached by a different route. Checked only when the status is actually leaving
        // publish, so editing a template that is already a draft is not made harder for no
        // reason, and before anything is written.
        if ( isset( $c['post_status'] ) && empty( $a['despite_references'] ) ) {
          if ( get_post_status( $post_id ) === 'publish' && $c['post_status'] !== 'publish' ) {
            $refs = GMCP_Core::template_reference_guard( $post_id, 'Unpublishing' );
            if ( $refs !== true ) {
              $r = $this->error( $r, $refs, -32600 );
              break;
            }
          }
        }

        // Detect trash / untrash transitions and route through wp_trash_post() /
        // wp_untrash_post() so the proper hooks fire (ACF cleanup, search-index purges,
        // SEO plugins, etc.). A bare wp_update_post( ['post_status' => 'trash'] ) just
        // flips the status field and skips all of that.
        $u = $post_id;
        if ( isset( $c['post_status'] ) ) {
          $current = get_post( $post_id );
          $current_status = $current ? $current->post_status : null;
          $target_status = $c['post_status'];

          if ( $target_status === 'trash' && $current_status !== 'trash' ) {
            $trashed = wp_trash_post( $post_id );
            if ( !$trashed ) {
              $r = $this->error( $r, 'wp_trash_post failed', -32603 );
              break;
            }
            unset( $c['post_status'] );
            $has_fields = count( $c ) > 1;
          }
          elseif ( $current_status === 'trash' && $target_status !== 'trash' ) {
            $untrashed = wp_untrash_post( $post_id );
            if ( !$untrashed ) {
              $r = $this->error( $r, 'wp_untrash_post failed', -32603 );
              break;
            }
            // Leave post_status in $c: wp_untrash_post restores to a previous status, and
            // a subsequent wp_update_post() will set the explicit one the caller asked for.
          }
        }

        // Update post fields if any
        if ( $has_fields ) {
          $u = wp_update_post( wp_slash( $c ), true );
          if ( is_wp_error( $u ) ) {
            $r = $this->error( $r, $u->get_error_message(), $u->get_error_code() );
            break;
          }
        }

        // Update meta if any
        $meta_notes = [];
        if ( $has_meta ) {
          foreach ( $meta_input as $k => $v ) {
            // Pass the value as-is: update_post_meta() serializes arrays itself.
            // maybe_serialize() here double-serialized nested arrays.
            // wp_slash on the key alone, because update_metadata() unslashes it.
            update_post_meta( $u, wp_slash( (string) $k ), $v );
            $meta_elsewhere = $this->meta_key_stored_as( (int) $u, (string) $k );
            if ( $meta_elsewhere !== '' ) {
              $meta_notes[ (string) $k ] = 'went into the row already spelled "' . $meta_elsewhere
                . '", which the database treats as the same key; read it back under that name.';
            }
          }
        }

        $this->bust_post_cache( (int) $u, [ 'tool' => 'wp_update_post' ] );

        // Verify the update actually took effect
        $updated_post = get_post( $u );
        $result = [
          'post_id' => $u,
          'post_modified' => $updated_post->post_modified,
        ];
        if ( $meta_notes ) {
          $result['meta_key_notes'] = $meta_notes;
        }

        // Verify content was saved correctly if we tried to update it
        if ( $content_to_verify !== null ) {
          $saved_content = $updated_post->post_content;
          $result['content_length'] = strlen( $saved_content );
          if ( $saved_content !== $content_to_verify ) {
            $result['warning'] = 'Content differs from input (sanitization applied or save failed)';
            $result['expected_length'] = strlen( $content_to_verify );
          }
        }

        if ( !empty( $a['schedule_for'] ) ) {
          $result['scheduled_for'] = $a['schedule_for'];
        }

        $this->add_result_text( $r, wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Posts: delete ===== */
      case 'wp_delete_post':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $delete_id = intval( $a['ID'] );
        // Trashing is guarded as well as forcing, which departs from how the rest of this
        // tool treats the two. Everywhere else the trash is the recoverable half; here it
        // is not, because a trashed template renders as nothing on every page that
        // references it, exactly as a deleted one does.
        if ( empty( $a['despite_references'] ) ) {
          $refs = GMCP_Core::template_reference_guard( $delete_id, 'Deleting' );
          if ( $refs !== true ) {
            $r = $this->error( $r, $refs, -32600 );
            break;
          }
        }
        $del = wp_delete_post( $delete_id, !empty( $a['force'] ) );
        if ( $del ) {
          $this->bust_post_cache( $delete_id, [ 'tool' => 'wp_delete_post' ] );
          // Asked of the database after the fact rather than inferred from the flag,
          // because the flag is not what decides it. @see deletion_outcome().
          $after = get_post( $delete_id );
          $this->add_result_text( $r, $this->deletion_outcome(
            'Post #' . $delete_id, $after && $after->post_status === 'trash' ) );
        }
        else {
          $r = $this->error( $r, 'Deletion failed', -32603 );
        }
        break;

        /* ===== Posts: alter (search/replace) ===== */
      case 'wp_alter_post':
        if ( empty( $a['ID'] ) || empty( $a['field'] ) || !isset( $a['search'] ) || !isset( $a['replace'] ) ) {
          $r = $this->error( $r, 'ID, field, search, and replace required', -32602 );
          break;
        }
        $post_id = intval( $a['ID'] );
        $field = sanitize_key( $a['field'] );
        $search = $a['search'];
        $replace = $a['replace'];
        $is_regex = !empty( $a['regex'] );
        $flags = isset( $a['flags'] ) && is_string( $a['flags'] ) ? $a['flags'] : '';

        // Validate field
        $allowed_fields = [ 'post_content', 'post_excerpt', 'post_title' ];
        if ( !in_array( $field, $allowed_fields, true ) ) {
          $r = $this->error( $r, 'Field must be: post_content, post_excerpt, or post_title', -32602 );
          break;
        }

        $post = get_post( $post_id );
        if ( !$post ) {
          $r = $this->error( $r, 'Post not found', -32602 );
          break;
        }

        $content = $post->$field;
        $count = 0;

        if ( $is_regex ) {
          list( $compiled, $regex_err ) = $this->compile_alter_regex( $search, $flags );
          if ( $regex_err !== null ) {
            $r = $this->error( $r, $regex_err, -32602 );
            break;
          }
          $new_content = preg_replace( $compiled, $replace, $content, -1, $count );
          if ( $new_content === null ) {
            $msg = function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : 'PCRE error code ' . preg_last_error();
            $r = $this->error( $r, 'Regex replacement failed: ' . $msg, -32603 );
            break;
          }
        }
        else {
          $new_content = str_replace( $search, $replace, $content, $count );
        }

        if ( $count === 0 ) {
          $this->add_result_text( $r, 'No occurrences found; post unchanged.' );
          break;
        }

        // wp_update_post() runs wp_unslash() internally, which would strip the
        // backslash from Unicode escapes like \u003c in block JSON (Rank Math
        // FAQ, etc.) and silently corrupt the post. Pre-slash to compensate.
        $update = wp_update_post( wp_slash( [ 'ID' => $post_id, $field => $new_content ] ), true );
        if ( is_wp_error( $update ) ) {
          $r = $this->error( $r, $update->get_error_message(), $update->get_error_code() );
          break;
        }

        $this->bust_post_cache( $post_id, [ 'tool' => 'wp_alter_post' ] );
        $this->add_result_text( $r, $count . ' replacement' . ( $count === 1 ? '' : 's' ) . ' applied to ' . $field . ' of post #' . $post_id );
        break;

        /* ===== Post-meta ===== */
      case 'wp_get_post_meta':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $pid = intval( $a['ID'] );
        // Compared against '' rather than tested for truthiness. The difference is "0",
        // which is falsy in PHP and which WordPress cannot address either: this way it
        // reaches meta_key_allowed() and is refused by name, instead of quietly turning
        // into "no key given" and answering a read of one key with every key.
        $get_key = (string) ( $a['key'] ?? '' );
        if ( $get_key === '' ) {
          $out = get_post_meta( $pid );
        }
        else {
          $get_why = $this->meta_key_allowed( $get_key );
          if ( $get_why !== true ) {
            $r = $this->error( $r, $get_why, -32602 );
            break;
          }
          // No wp_slash: get_metadata() does not unslash the key. @see meta_key_allowed().
          $out = get_post_meta( $pid, $get_key, true );
        }
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_update_post_meta':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $pid = intval( $a['ID'] );

        // Handle JSON strings for meta (some MCP clients send objects as JSON strings)
        $meta = $a['meta'] ?? null;
        if ( is_string( $meta ) ) {
          $meta = json_decode( $meta, true );
        }

        // Never maybe_serialize() here: update_post_meta() serializes arrays itself, and
        // doing it twice stored a nested array as its own serialization. prepare_meta_value()
        // is the single answer to what a value should look like on the way in, so the map
        // form and the key/value form can no longer treat the same bytes differently.
        if ( !empty( $meta ) && is_array( $meta ) ) {
          $pairs = [];
          foreach ( $meta as $k => $v ) {
            $pairs[ (string) $k ] = $v;
          }
        }
        elseif ( isset( $a['key'], $a['value'] ) ) {
          $pairs = [ (string) $a['key'] => $a['value'] ];
        }
        else {
          $r = $this->error( $r, 'meta array or key/value required', -32602 );
          break;
        }

        // Every key is checked before any of them is written. A refusal halfway through a
        // "meta" object would leave the post holding some of the call and not the rest,
        // with the answer describing neither.
        $bad_key = null;
        foreach ( array_keys( $pairs ) as $k ) {
          $why = $this->meta_key_allowed( $k );
          if ( $why !== true ) {
            $bad_key = $why;
            break;
          }
        }
        if ( $bad_key !== null ) {
          $r = $this->error( $r, $bad_key . ' Nothing was written.', -32602 );
          break;
        }
        $meta_lines = [ 'Meta updated for post #' . $pid ];
        foreach ( $pairs as $k => $v ) {
          // Key and value are slashed for the same reason and by different routes.
          // update_metadata() unslashes both, so a key holding a backslash would be stored
          // without it under a name no reader asks for (@see meta_key_allowed()), and a
          // value holding one would lose it silently, which is what prepare_meta_value()
          // exists to stop. That helper also decodes a JSON string into the array a reader
          // expects, and it is shared with the chunk writer so the two cannot disagree
          // about the same bytes.
          update_post_meta( $pid, wp_slash( $k ), $this->prepare_meta_value( $v ) );
          $elsewhere = $this->meta_key_stored_as( $pid, $k );
          if ( $elsewhere !== '' ) {
            $meta_lines[] = 'Note: "' . $k . '" went into the row already spelled "' . $elsewhere
              . '". The database treats the two as one key, so the value is stored, but a read of "'
              . $k . '" will not find it. Read it back as "' . $elsewhere . '".';
          }
        }
        $this->add_result_text( $r, implode( "\n", $meta_lines ) );
        break;

      case 'wp_delete_post_meta':
        // The key is tested against '' rather than with empty(), so "0" gets the refusal
        // from meta_key_allowed() that says why WordPress cannot address it, rather than
        // this generic line. @see meta_key_allowed().
        if ( empty( $a['ID'] ) || (string) ( $a['key'] ?? '' ) === '' ) {
          $r = $this->error( $r, 'ID & key required', -32602 );
          break;
        }
        $pid = intval( $a['ID'] );
        $key = (string) $a['key'];
        $del_why = $this->meta_key_allowed( $key );
        if ( $del_why !== true ) {
          $r = $this->error( $r, $del_why, -32602 );
          break;
        }
        // wp_slash on the key because delete_metadata() unslashes it. @see meta_key_allowed().
        $key = wp_slash( $key );
        // delete_post_meta() serializes the match value itself; don't pre-serialize.
        $done = isset( $a['value'] ) ? delete_post_meta( $pid, $key, $a['value'] ) : delete_post_meta( $pid, $key );
        if ( $done ) {
          $this->add_result_text( $r, 'Meta deleted on post #' . $pid );
        }
        else {
          $r = $this->error( $r, 'Deletion failed', -32603 );
        }
        break;

      case 'wp_copy_post_meta':
        $from_id = intval( $a['from_id'] ?? 0 );
        $to_id = intval( $a['to_id'] ?? 0 );
        if ( !$from_id || !get_post( $from_id ) ) {
          $r = $this->error( $r, 'No source post with ID ' . $from_id . '.', -32602 );
          break;
        }
        if ( !$to_id || !get_post( $to_id ) ) {
          $r = $this->error( $r, 'No target post with ID ' . $to_id . '.', -32602 );
          break;
        }
        // Some MCP clients send arrays as JSON strings.
        $copy_keys = $a['keys'] ?? [];
        if ( is_string( $copy_keys ) ) {
          $copy_keys = json_decode( $copy_keys, true ) ?? [];
        }
        [ $copied, $skipped ] = $this->copy_post_meta( $from_id, $to_id, (array) $copy_keys, !empty( $a['overwrite'] ) );

        $copy_lines = [];
        if ( $copied ) {
          $copy_lines[] = 'Copied ' . count( $copied ) . ' key(s) from post #' . $from_id . ' to post #' . $to_id
            . ', ' . array_sum( array_column( $copied, 'bytes' ) ) . ' bytes in total:';
          foreach ( $copied as $copy_key => $copy_stat ) {
            $copy_lines[] = '- ' . $copy_key . ': ' . $copy_stat['bytes'] . ' bytes'
              . ( $copy_stat['rows'] > 1 ? ' across ' . $copy_stat['rows'] . ' rows' : '' );
          }
        }
        else {
          $copy_lines[] = 'Nothing was copied from post #' . $from_id . ' to post #' . $to_id . '.';
        }
        if ( $skipped ) {
          $copy_lines[] = '';
          $copy_lines[] = 'Skipped:';
          foreach ( $skipped as $copy_key => $copy_why ) {
            $copy_lines[] = '- ' . $copy_key . ': ' . $copy_why;
          }
        }
        $this->bust_post_cache( $to_id, [ 'tool' => 'wp_copy_post_meta' ] );
        $this->add_result_text( $r, implode( "\n", $copy_lines ) );
        break;

      case 'wp_write_post_meta_chunk':
        $chunk_session = (string) ( $a['session'] ?? '' );
        $chunk_pid = intval( $a['ID'] ?? 0 );
        $chunk_key = (string) ( $a['key'] ?? '' );
        $chunk_data = $a['data'] ?? null;
        $chunk_name = $this->meta_chunk_transient( $chunk_session );
        if ( $chunk_name === $this->meta_chunk_transient( '' ) ) {
          $r = $this->error( $r, 'session required, and it must contain letters, digits, "-" or "_".', -32602 );
          break;
        }
        if ( !$chunk_pid || !get_post( $chunk_pid ) ) {
          $r = $this->error( $r, 'No post with ID ' . $chunk_pid . '.', -32602 );
          break;
        }
        $chunk_why = $this->meta_key_allowed( $chunk_key );
        if ( $chunk_why !== true ) {
          $r = $this->error( $r, $chunk_why, -32602 );
          break;
        }
        if ( !is_string( $chunk_data ) ) {
          $r = $this->error( $r, 'data must be a string; send the value in pieces, not as an object.', -32602 );
          break;
        }

        $staged = get_transient( $chunk_name );
        if ( !is_array( $staged ) ) {
          $staged = [ 'ID' => $chunk_pid, 'key' => $chunk_key, 'chunks' => 0, 'data' => '' ];
        }
        // A session stands for one value. Letting a later chunk point somewhere else
        // would splice two payloads together and write the result to whichever target
        // the last call named, which is a corrupt value on a post nobody was writing to.
        if ( $staged['ID'] !== $chunk_pid || $staged['key'] !== $chunk_key ) {
          $r = $this->error(
            $r,
            'Session "' . $chunk_session . '" is staging post #' . $staged['ID'] . ' meta "' . $staged['key']
              . '", and this chunk targets post #' . $chunk_pid . ' meta "' . $chunk_key
              . '". Use a different session id for a different value.',
            -32600
          );
          break;
        }
        if ( strlen( $staged['data'] ) + strlen( $chunk_data ) > self::META_CHUNK_MAX_BYTES ) {
          $r = $this->error(
            $r,
            'This chunk would take session "' . $chunk_session . '" past the ' . self::META_CHUNK_MAX_BYTES
              . '-byte staging limit. Nothing was appended; the ' . strlen( $staged['data'] )
              . ' bytes already staged are untouched and expire on their own.',
            -32600
          );
          break;
        }

        $staged['data'] .= $chunk_data;
        $staged['chunks']++;

        if ( empty( $a['final'] ) ) {
          // Re-setting the transient also restarts the expiry, so a session that is
          // still being fed stays alive and one that stops being fed goes away.
          set_transient( $chunk_name, $staged, self::META_CHUNK_TTL );
          $this->add_result_text( $r, wp_json_encode( [
            'session' => $chunk_session,
            'chunk_index' => $staged['chunks'] - 1,
            'bytes_written' => strlen( $chunk_data ),
            'total_bytes' => strlen( $staged['data'] ),
            'final' => false,
          ], JSON_PRETTY_PRINT ) );
          break;
        }

        // The decode-and-slash this writer used to carry itself now lives in
        // prepare_meta_value(), which wp_update_post_meta asks too. Keeping two copies is
        // how the two tools came to disagree about the same bytes in the first place.
        $chunk_value = $this->prepare_meta_value( $staged['data'] );
        $chunk_stored_as = is_array( $chunk_value ) ? 'array' : 'string';
        // The key is slashed separately: update_metadata() unslashes it too, and a key with
        // a backslash would be stored without one under a name no reader asks for. It is
        // the easier of the two to miss, because nothing about the stored value looks
        // wrong. @see meta_key_allowed().
        update_post_meta( $chunk_pid, wp_slash( $chunk_key ), $chunk_value );
        // written_to names the row the bytes are in, not the row that was asked for, so a
        // caller can hand it straight to wp_read_post_meta_chunk. @see meta_key_stored_as().
        $chunk_elsewhere = $this->meta_key_stored_as( $chunk_pid, $chunk_key );
        $chunk_filed_as = $chunk_elsewhere !== '' ? $chunk_elsewhere : $chunk_key;
        delete_transient( $chunk_name );
        $this->bust_post_cache( $chunk_pid, [ 'tool' => 'wp_write_post_meta_chunk' ] );

        $this->add_result_text( $r, wp_json_encode( [
          'session' => $chunk_session,
          'chunk_index' => $staged['chunks'] - 1,
          'bytes_written' => strlen( $chunk_data ),
          'total_bytes' => strlen( $staged['data'] ),
          'final' => true,
          'written_to' => [ 'ID' => $chunk_pid, 'key' => $chunk_filed_as ],
          'stored_as' => $chunk_stored_as,
          'note' => ( $chunk_elsewhere !== '' ? 'The post already held a row spelled "' . $chunk_elsewhere . '", which the database treats as the same key as "' . $chunk_key . '", so the value went there and that row keeps its own spelling; read it back under written_to.key. ' : '' )
            . 'The previous value of each key is journalled, so wp_undo_change can put a field back. A value past a megabyte, or one that looks credential-shaped, is recorded without a copy and the entry says so.',
        ], JSON_PRETTY_PRINT ) );
        break;

      case 'wp_read_post_meta_chunk':
        // read level, and that is the whole reach it has. Post meta reads are ungated
        // today: wp_get_post_meta is itself a read tool and returns any key on any post
        // in full, with no option_guard() equivalent standing in front of it. Walking the
        // same value in pieces therefore exposes nothing a read-only token could not
        // already ask for in one call. If a guard is ever put on post meta reads it has
        // to be put on both tools, or this one becomes the way around it.
        $read_pid = intval( $a['ID'] ?? 0 );
        $read_key = (string) ( $a['key'] ?? '' );
        if ( !$read_pid || !get_post( $read_pid ) ) {
          $r = $this->error( $r, 'No post with ID ' . $read_pid . '.', -32602 );
          break;
        }
        $read_why = $this->meta_key_allowed( $read_key );
        if ( $read_why !== true ) {
          $r = $this->error( $r, $read_why, -32602 );
          break;
        }

        // get_post_meta() WITHOUT single, so a key with several rows arrives as several
        // rows and can be addressed one at a time. wp_get_post_meta's single read hands
        // back row 0 and never mentions the others, which is the thing worth not
        // repeating in a tool whose job is to return a value completely.
        // No wp_slash: get_metadata() does not unslash the key. @see meta_key_allowed().
        $read_rows = get_post_meta( $read_pid, $read_key );
        if ( !is_array( $read_rows ) || $read_rows === [] ) {
          // Reads compare the spelling exactly and the database does not, so the likeliest
          // reason a key is missing is that it is there under another capitalisation. Say
          // which one, or the caller is left guessing at the tool that used to lowercase
          // for it. @see meta_key_stored_as().
          $read_elsewhere = $this->meta_key_stored_as( $read_pid, $read_key );
          $r = $this->error(
            $r,
            'Post #' . $read_pid . ' has no meta key "' . $read_key . '".'
              . ( $read_elsewhere !== '' ? ' It does have "' . $read_elsewhere . '", which differs only in ways the database ignores; keys are matched exactly here, so ask for that spelling.' : '' ),
            -32602
          );
          break;
        }
        $read_count = count( $read_rows );
        $read_index = intval( $a['index'] ?? 0 );
        if ( $read_index < 0 || !array_key_exists( $read_index, $read_rows ) ) {
          $r = $this->error(
            $r,
            'Post #' . $read_pid . ' meta "' . $read_key . '" has ' . $read_count . ' row'
              . ( $read_count === 1 ? '' : 's' ) . ', numbered 0 to ' . ( $read_count - 1 )
              . '; index ' . $read_index . ' is not one of them.',
            -32602
          );
          break;
        }
        $read_value = $read_rows[ $read_index ];

        // What the bytes ARE, which the answer has to name or the caller is reassembling
        // something it cannot identify. A value stored as a string is walked verbatim. An
        // array or object has no string to walk: WordPress unserialized it on the way out,
        // and the serialized row underneath is an internal encoding no caller asked for.
        // So those are walked as the same JSON wp_get_post_meta already prints for that
        // row, which keeps the two read tools answering with identical bytes for identical
        // values, and which wp_write_post_meta_chunk decodes back into the array, so the
        // chunked pair is a round trip rather than two tools that merely both exist.
        if ( is_string( $read_value ) ) {
          $read_bytes = $read_value;
          $read_repr = 'raw';
        }
        else {
          $read_bytes = wp_json_encode( $read_value, JSON_PRETTY_PRINT );
          $read_repr = 'json';
          if ( !is_string( $read_bytes ) ) {
            $r = $this->error(
              $r,
              'Post #' . $read_pid . ' meta "' . $read_key . '" row ' . $read_index
                . ' holds a ' . gettype( $read_value ) . ' that cannot be encoded as JSON, so there is'
                . ' no sequence of bytes to walk. Read it with wp_get_post_meta instead.',
              -32603
            );
            break;
          }
        }
        $read_total = strlen( $read_bytes );

        $read_offset = intval( $a['offset'] ?? 0 );
        if ( $read_offset < 0 || $read_offset > $read_total ) {
          $r = $this->error(
            $r,
            'offset ' . $read_offset . ' is outside post #' . $read_pid . ' meta "' . $read_key
              . '", which is ' . $read_total . ' bytes; offsets run from 0 to ' . $read_total . '.',
            -32602
          );
          break;
        }
        $read_length = isset( $a['length'] ) ? intval( $a['length'] ) : self::META_READ_CHUNK_BYTES;
        if ( $read_length < 1 ) {
          $r = $this->error( $r, 'length must be at least 1 byte.', -32602 );
          break;
        }
        $read_length = min( $read_length, self::META_READ_CHUNK_MAX_BYTES );

        // substr() and strlen(), never mb_substr() and mb_strlen(). The offset is a byte
        // offset, so a slice can begin or end inside a multibyte character, and that is
        // harmless precisely because nothing here tries to repair it: the caller
        // concatenates the pieces and the character is whole again. What would not be
        // harmless is returning the slice as text. WordPress serializes the response with
        // wp_json_encode(), which on invalid UTF-8 falls back to _wp_json_sanity_check()
        // and runs the string through mb_convert_encoding(), substituting a placeholder
        // for the partial character and reporting no error, so every chunk boundary that
        // landed inside a character would come back subtly wrong and the caller would
        // never know. base64 has no opinion about what the bytes mean, so it carries them.
        $read_slice = substr( $read_bytes, $read_offset, $read_length );
        $read_returned = strlen( $read_slice );

        $this->add_result_text( $r, wp_json_encode( [
          'ID' => $read_pid,
          'key' => $read_key,
          'index' => $read_index,
          'rows' => $read_count,
          'represents' => $read_repr,
          'encoding' => 'base64',
          'offset' => $read_offset,
          'bytes_returned' => $read_returned,
          'total_bytes' => $read_total,
          'more' => ( $read_offset + $read_returned ) < $read_total,
          // The hash covers the whole value, not this slice. A value can be rewritten
          // between chunk one and chunk five, and a caller that reassembled two halves of
          // two documents has no other way to find out. This does not prevent that and is
          // not meant to: it makes it detectable, which is the part a caller cannot do
          // for itself. It also lets a caller check its own reassembly at the end.
          'sha256' => hash( 'sha256', $read_bytes ),
          'data' => base64_encode( $read_slice ),
        ], JSON_PRETTY_PRINT ) );
        break;

        /* ===== Featured image ===== */
      case 'wp_set_featured_image':
        if ( empty( $a['post_id'] ) ) {
          $r = $this->error( $r, 'post_id required', -32602 );
          break;
        }
        $post_id = intval( $a['post_id'] );
        $media_id = isset( $a['media_id'] ) ? intval( $a['media_id'] ) : 0;
        if ( $media_id ) {
          $done = set_post_thumbnail( $post_id, $media_id );
          if ( $done ) {
            $this->add_result_text( $r, 'Featured image set on post #' . $post_id );
          }
          else {
            $r = $this->error( $r, 'Failed to set thumbnail', -32603 );
          }
        }
        else {
          delete_post_thumbnail( $post_id );
          $this->add_result_text( $r, 'Featured image removed from post #' . $post_id );
        }
        break;

        /* ===== Taxonomies ===== */
      case 'wp_get_taxonomies':
        $pt = sanitize_key( $a['post_type'] ?? 'post' );
        $out = [];
        foreach ( get_object_taxonomies( $pt, 'objects' ) as $t ) {
          $out[] = [ 'key' => $t->name, 'label' => $t->label ];
        }
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_get_terms':
        $tax = sanitize_key( $a['taxonomy'] );
        $args = [
          'taxonomy' => $tax,
          'hide_empty' => false,
          'number' => intval( $a['limit'] ?? 0 ),
          'search' => $a['search'] ?? '',
        ];
        if ( isset( $a['parent'] ) ) {
          $args['parent'] = intval( $a['parent'] );
        }
        $out = [];
        foreach ( get_terms( $args ) as $t ) {
          $out[] = [ 'term_id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count ];
        }
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_create_term':
        if ( empty( $a['term_name'] ) ) {
          $r = $this->error( $r, 'term_name required', -32602 );
          break;
        }
        $tax = sanitize_key( $a['taxonomy'] );
        $args = [];
        if ( $a['slug'] ?? '' ) {
          $args['slug'] = sanitize_title( $a['slug'] );
        }
        if ( $a['description'] ?? '' ) {
          $args['description'] = sanitize_text_field( $a['description'] );
        }
        if ( isset( $a['parent'] ) ) {
          $args['parent'] = intval( $a['parent'] );
        }
        $term = wp_insert_term( sanitize_text_field( $a['term_name'] ), $tax, $args );
        if ( is_wp_error( $term ) ) {
          $r = $this->error( $r, $term->get_error_message(), $term->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'Term ' . $term['term_id'] . ' created' );
        }
        break;

      case 'wp_update_term':
        $tid = intval( $a['term_id'] ?? 0 );
        if ( !$tid ) {
          $r = $this->error( $r, 'term_id required', -32602 );
          break;
        }
        $tax = sanitize_key( $a['taxonomy'] );
        $uargs = [];
        foreach ( [ 'name', 'slug', 'description', 'parent' ] as $f ) {
          if ( isset( $a[$f] ) ) {
            $uargs[$f] = $a[$f];
          }
        }
        $t = wp_update_term( $tid, $tax, $uargs );
        if ( is_wp_error( $t ) ) {
          $r = $this->error( $r, $t->get_error_message(), $t->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'Term ' . $tid . ' updated' );
        }
        break;

      case 'wp_delete_term':
        $tid = intval( $a['term_id'] ?? 0 );
        if ( !$tid ) {
          $r = $this->error( $r, 'term_id required', -32602 );
          break;
        }
        $tax = sanitize_key( $a['taxonomy'] );
        $d = wp_delete_term( $tid, $tax );
        if ( $d ) {
          $this->add_result_text( $r, 'Term ' . $tid . ' deleted' );
        }
        else {
          $r = $this->error( $r, 'Deletion failed', -32603 );
        }
        break;

      case 'wp_get_post_terms':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $tax = sanitize_key( $a['taxonomy'] ?? 'category' );
        $out = [];
        foreach ( wp_get_post_terms( intval( $a['ID'] ), $tax, [ 'fields' => 'all' ] ) as $t ) {
          $out[] = [ 'term_id' => $t->term_id, 'name' => $t->name ];
        }
        $this->add_result_text( $r, wp_json_encode( $out, JSON_PRETTY_PRINT ) );
        break;

      case 'wp_add_post_terms':
        if ( empty( $a['ID'] ) || empty( $a['terms'] ) ) {
          $r = $this->error( $r, 'ID & terms required', -32602 );
          break;
        }
        $terms = $a['terms'];
        // Handle JSON strings (some MCP clients send arrays as JSON strings)
        if ( is_string( $terms ) ) {
          $terms = json_decode( $terms, true ) ?? [];
        }
        $tax = sanitize_key( $a['taxonomy'] ?? 'category' );
        $append = !isset( $a['append'] ) || $a['append'];
        $set = wp_set_post_terms( intval( $a['ID'] ), $terms, $tax, $append );
        if ( is_wp_error( $set ) ) {
          $r = $this->error( $r, $set->get_error_message(), $set->get_error_code() );
        }
        else {
          $this->add_result_text( $r, 'Terms set for post #' . $a['ID'] );
        }
        break;

        /* ===== Media: list ===== */
      case 'wp_get_media':
        $q = [
          'post_type' => 'attachment',
          's' => $a['search'] ?? '',
          'posts_per_page' => max( 1, min( 500, intval( $a['limit'] ?? 10 ) ) ),
          'post_status' => 'inherit',
        ];
        if ( isset( $a['author'] ) ) {
          $q['author'] = intval( $a['author'] );
        }
        elseif ( $a['author_name'] ?? '' ) {
          $q['author_name'] = sanitize_title( $a['author_name'] );
        }
        $d = [];
        if ( $a['after'] ?? '' ) {
          $d['after'] = $a['after'];
        }
        if ( $a['before'] ?? '' ) {
          $d['before'] = $a['before'];
        }
        if ( $d ) {
          $q['date_query'] = [ $d ];
        }
        $list = [];
        foreach ( get_posts( $q ) as $m ) {
          $list[] = [ 'ID' => $m->ID, 'title' => $m->post_title, 'url' => wp_get_attachment_url( $m->ID ) ];
        }
        $this->add_result_text( $r, wp_json_encode( $list, JSON_PRETTY_PRINT ) );
        break;

        /* ===== Media: upload ===== */
      case 'wp_upload_media':
        $has_url = !empty( $a['url'] );
        $has_base64 = !empty( $a['base64'] ) && !empty( $a['filename'] );
        if ( !$has_url && !$has_base64 ) {
          $r = $this->error( $r, 'Provide either url, or base64 + filename.', -32602 );
          break;
        }
        try {
          require_once ABSPATH . 'wp-admin/includes/file.php';
          require_once ABSPATH . 'wp-admin/includes/media.php';
          require_once ABSPATH . 'wp-admin/includes/image.php';

          if ( $has_url ) {
            $tmp = download_url( $a['url'] );
            if ( is_wp_error( $tmp ) ) {
              // WP_Error codes are strings (e.g. http_request_failed); Exception's
              // $code must be an int, so keep the code in the message instead.
              throw new Exception( 'Download failed (' . $tmp->get_error_code() . '): ' . $tmp->get_error_message() );
            }
            // URLs like https://picsum.photos/800/600 have no file extension, so
            // basename() yields a name that media_handle_sideload() rejects. Sniff
            // the real type of the downloaded file and append a proper extension.
            $name = basename( wp_parse_url( $a['url'], PHP_URL_PATH ) );
            if ( $name === '' || pathinfo( $name, PATHINFO_EXTENSION ) === '' ) {
              $ext = '';
              $check = wp_check_filetype_and_ext( $tmp, $name ?: 'image' );
              if ( !empty( $check['ext'] ) ) {
                $ext = $check['ext'];
              }
              elseif ( function_exists( 'mime_content_type' ) ) {
                $map = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
                $ext = $map[ mime_content_type( $tmp ) ] ?? '';
              }
              $name = ( $name ?: 'image' ) . ( $ext ? '.' . $ext : '' );
            }
            $file = [ 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp ];
          }
          else {
            $decoded = base64_decode( $a['base64'], true );
            if ( $decoded === false ) {
              throw new Exception( 'Invalid base64 data.' );
            }
            $tmp = wp_tempnam( $a['filename'] );
            file_put_contents( $tmp, $decoded );
            $file = [ 'name' => sanitize_file_name( $a['filename'] ), 'tmp_name' => $tmp ];
          }

          $id = media_handle_sideload( $file, 0, $a['description'] ?? '' );
          wp_delete_file( $tmp );
          if ( is_wp_error( $id ) ) {
            throw new Exception( 'Sideload failed (' . $id->get_error_code() . '): ' . $id->get_error_message() );
          }
          if ( $a['title'] ?? '' ) {
            wp_update_post( wp_slash( [ 'ID' => $id, 'post_title' => sanitize_text_field( $a['title'] ) ] ) );
          }
          if ( $a['alt'] ?? '' ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $a['alt'] ) );
          }
          $this->add_result_text( $r, wp_get_attachment_url( $id ) );
        }
        catch ( \Throwable $e ) {
          $r = $this->error( $r, $e->getMessage(), $e->getCode() ?: -32603 );
        }
        break;

        /* ===== Media: upload alternative (two-step) ===== */
      case 'wp_upload_request':
        if ( empty( $a['filename'] ) ) {
          $r = $this->error( $r, 'filename required', -32602 );
          break;
        }
        try {
          $token = wp_generate_password( 32, false );
          $transient_key = 'gmcp_upload_' . $token;
          $data = [
            'filename' => sanitize_file_name( $a['filename'] ),
            'title' => $a['title'] ?? '',
            'description' => $a['description'] ?? '',
            'alt' => $a['alt'] ?? '',
          ];
          set_transient( $transient_key, $data, 5 * MINUTE_IN_SECONDS );
          $upload_url = rest_url( 'mcp/v1/upload/' . $token );
          $this->add_result_text( $r, wp_json_encode( [
            'upload_url' => $upload_url,
            'expires_in' => '5 minutes',
            'usage' => 'curl -X POST -F "file=@/path/to/' . $a['filename'] . '" "' . $upload_url . '"',
          ], JSON_PRETTY_PRINT ) );
        }
        catch ( \Throwable $e ) {
          $r = $this->error( $r, $e->getMessage(), $e->getCode() ?: -32603 );
        }
        break;

        /* ===== Media: update ===== */
      case 'wp_update_media':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $upd = [ 'ID' => intval( $a['ID'] ) ];
        if ( $a['title'] ?? '' ) {
          $upd['post_title'] = sanitize_text_field( $a['title'] );
        }
        if ( $a['caption'] ?? '' ) {
          $upd['post_excerpt'] = $this->clean_html( $a['caption'] );
        }
        if ( $a['description'] ?? '' ) {
          $upd['post_content'] = $this->clean_html( $a['description'] );
        }
        $u = wp_update_post( wp_slash( $upd ), true );
        if ( is_wp_error( $u ) ) {
          $r = $this->error( $r, $u->get_error_message(), $u->get_error_code() );
        }
        else {
          if ( $a['alt'] ?? '' ) {
            update_post_meta( $u, '_wp_attachment_image_alt', sanitize_text_field( $a['alt'] ) );
          }
          $this->add_result_text( $r, 'Media #' . $u . ' updated' );
        }
        break;

        /* ===== Media: delete ===== */
      case 'wp_delete_media':
        if ( empty( $a['ID'] ) ) {
          $r = $this->error( $r, 'ID required', -32602 );
          break;
        }
        $media_id = intval( $a['ID'] );
        $d = wp_delete_post( $media_id, !empty( $a['force'] ) );
        if ( $d ) {
          // An attachment has no trash, so this is always the permanent branch. Asked
          // the same way as the others anyway: a claim worth making is worth checking,
          // and a future WordPress that grows a trash for attachments would otherwise
          // leave this saying the wrong thing forever.
          $after = get_post( $media_id );
          $this->add_result_text( $r, $this->deletion_outcome(
            'Media #' . $media_id, $after && $after->post_status === 'trash' ) );
        }
        else {
          $r = $this->error( $r, 'Deletion failed', -32603 );
        }
        break;


      default: $r = $this->error( $r, 'Unknown tool', -32601 );
    }

    // Generic post-write hook: fires after any successful content-mutating tool
    // (create/update/delete of posts, terms, meta, media, comments, users,
    // options...). Integrations can hook this to purge page/object caches, reindex
    // search, write an audit log, etc. The options/object cache is already updated
    // by WordPress, but full-page caches (Varnish, WP Rocket, Cloudflare) are not,
    // so a cache layer should listen here. Reads never trigger it.
    //
    // A failure is an isError result now, not an error field, so both have to be tested
    // or every refused write would announce itself as a change and purge caches for
    // nothing.
    if ( empty( $r['error'] ) && empty( $r['result']['isError'] ) && $this->is_mutating_tool( $tool ) ) {
      do_action( 'gmcp_mutate', $tool, $a, $r );
    }
    return $r;
  }

  /**
  * The only "admin" tools that do not change anything. Everything else at that level
  * does, including all five delete tools.
  *
  * This is a list of exceptions rather than a list of mutating tools, and that is
  * deliberate. It used to name the mutating ones, which meant every delete tool was
  * missing and gmcp_mutate never fired on a deletion: anyone using the hook to
  * purge a full-page cache kept serving deleted posts. Listing the reads instead
  * makes the failure mode a needless cache purge rather than a silently stale page,
  * and a new admin tool is covered the day it is added instead of the day someone
  * notices.
  */
  private const NON_MUTATING_ADMIN_TOOLS = [ 'wp_get_users', 'wp_get_option', 'wp_get_theme_mod', 'wp_list_theme_mods' ];

  // Whether a tool changes site state (so the gmcp_mutate hook should fire).
  private function is_mutating_tool( string $tool ): bool {
    $defs = $this->tools();
    $level = $defs[ $tool ]['accessLevel'] ?? '';
    if ( $level === 'write' ) {
      return true;
    }
    if ( $level === 'admin' ) {
      return !in_array( $tool, self::NON_MUTATING_ADMIN_TOOLS, true );
    }
    return false;
  }
  #endregion
}
