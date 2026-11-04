<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Converts a small subset of Markdown into core Gutenberg block markup.
*
* Pure on purpose: no WordPress function is called, so the conversion can be exercised by
* a standalone script (.dev/test-markdown-blocks.php). Its output is not trusted for that
* reason. The caller sends it through the same post-HTML path as every other write
* (GMCP_Tools_Core::store_html), which runs kses over each block's HTML. This class's own
* job is narrower: the source cannot say anything it was not meant to. All HTML in the
* source is escaped rather than passed through, and links accept only http, https and
* mailto, images only http and https.
*
* Supported: paragraphs; ATX headings (# to ######); bulleted and numbered lists; block-
* quotes, which may contain the other block types; fenced code (``` or ~~~); inline code,
* **bold** and __bold__, *italic* and _italic_, [text](url) and ![alt](url); and a
* paragraph that is nothing but one image, which becomes an image block.
*
* Everything else is paragraph text, shown as typed. That includes setext headings, tables,
* "---" rules, HTML, autolinks, reference links, backslash escapes and character references
* (&amp; stays "&amp;"). Nested lists are not supported: an indented line continues the item
* above it as text. A line such as "* * *" is read as a list item. An emphasis marker that
* does not close is shown as typed. Italic that wraps bold, and bold-and-italic together
* (***x***), are not recognised.
*/
final class GMCP_Markdown_Blocks {

  // Deeper nesting than this is paragraph text. Bounds the recursion for a line of '>'s.
  const MAX_QUOTE_DEPTH = 5;

  // Delimits placeholders for code spans and links while the inline pass runs. NUL is
  // removed from the source first, so a placeholder can never collide with source text.
  const TOKEN = "\x00";

  // Set during one inline pass; read only by inline() and its helpers.
  private static $codes = [];
  private static $links = [];

  /**
  * Convert Markdown to block markup.
  *
  * @return array{0: ?string, 1: ?string, 2: int} [ markup, error, top-level block count ],
  *   with exactly one of markup and error non-null.
  */
  public static function to_markup( string $markdown ): array {
    $src = str_replace( [ "\r\n", "\r", "\x00" ], [ "\n", "\n", '' ], $markdown );
    if ( trim( $src ) === '' ) {
      return [ null, 'markdown is empty.', 0 ];
    }
    $blocks = self::parse_blocks( explode( "\n", $src ), 0 );
    if ( $blocks === [] ) {
      return [ null, 'markdown contained no content.', 0 ];
    }
    return [ implode( "\n\n", $blocks ), null, count( $blocks ) ];
  }

  /**
  * Inline Markdown to HTML, with every piece of source text escaped.
  */
  public static function inline( string $text ): string {
    $text = str_replace( "\x00", '', $text );
    self::$codes = [];
    self::$links = [];

    // Code spans first: what is inside them is literal, so nothing else may see it.
    $text = preg_replace_callback(
      '/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s',
      function ( $m ) {
        self::$codes[] = $m[2];
        return self::TOKEN . 'C' . ( count( self::$codes ) - 1 ) . self::TOKEN;
      },
      $text
    ) ?? $text;

    // Images and links. A match that is not allowed is left as typed, so it is shown as
    // text, and the emphasis pass may still apply inside it.
    $text = preg_replace_callback(
      '/(!?)\[([^\[\]\n]*)\]\(([^()\s]*)(?:\s+"[^"\n]*")?\)/',
      function ( $m ) {
        $bang = $m[1] === '!';
        $label = $m[2];
        $url = $m[3];
        if ( $bang ) {
          if ( !preg_match( '#^https?://[^\s"<>\x00-\x1f]+$#i', $url ) ) {
            return $m[0];
          }
          self::$links[] = '<img src="' . self::esc_attr( $url ) . '" alt="' . self::esc_attr( self::plain( $label ) ) . '"/>';
        }
        else {
          if ( !preg_match( '#^(?:https?://|mailto:)[^\s"<>\x00-\x1f]+$#i', $url ) ) {
            return $m[0];
          }
          self::$links[] = '<a href="' . self::esc_attr( $url ) . '">' . self::emphasis( self::esc_text( $label ) ) . '</a>';
        }
        return self::TOKEN . 'L' . ( count( self::$links ) - 1 ) . self::TOKEN;
      },
      $text
    ) ?? $text;

    $text = self::emphasis( self::esc_text( $text ) );

    // Placeholders can nest (a link label may hold a code span), so resolve until none remain.
    for ( $pass = 0; $pass < 5 && strpos( $text, self::TOKEN ) !== false; $pass++ ) {
      $text = preg_replace_callback(
        '/\x00([CL])(\d+)\x00/',
        function ( $m ) {
          $i = (int) $m[2];
          if ( $m[1] === 'C' ) {
            return '<code>' . self::esc_text( self::$codes[ $i ] ?? '' ) . '</code>';
          }
          return self::$links[ $i ] ?? '';
        },
        $text
      ) ?? $text;
    }
    // A placeholder that somehow survives five passes is removed rather than emitted.
    $text = str_replace( self::TOKEN, '', $text );

    self::$codes = [];
    self::$links = [];
    return $text;
  }

  #region Blocks

  private static function parse_blocks( array $lines, int $depth ): array {
    $out = [];
    $n = count( $lines );
    $i = 0;

    while ( $i < $n ) {
      $line = $lines[ $i ];

      if ( trim( $line ) === '' ) {
        $i++;
        continue;
      }

      // Fenced code. The body is kept as typed; a fence that never closes runs to the end.
      if ( preg_match( '/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $m ) && !( $m[1][0] === '`' && strpos( $m[2], '`' ) !== false ) ) {
        $char = $m[1][0];
        $len = strlen( $m[1] );
        $close = '/^ {0,3}' . preg_quote( $char, '/' ) . '{' . $len . ',}[ \t]*$/';
        $body = [];
        $i++;
        while ( $i < $n && !preg_match( $close, $lines[ $i ] ) ) {
          $body[] = $lines[ $i ];
          $i++;
        }
        $i++; // the closing fence, if there was one
        $out[] = self::code_block( implode( "\n", $body ) );
        continue;
      }

      if ( preg_match( '/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m ) ) {
        $out[] = self::heading( strlen( $m[1] ), self::inline( trim( $m[2] ?? '' ) ) );
        $i++;
        continue;
      }

      if ( $depth < self::MAX_QUOTE_DEPTH && preg_match( '/^ {0,3}>/', $line ) ) {
        $inner = [];
        while ( $i < $n && preg_match( '/^ {0,3}>/', $lines[ $i ] ) ) {
          $inner[] = preg_replace( '/^ {0,3}> ?/', '', $lines[ $i ] );
          $i++;
        }
        $out[] = self::quote_block( self::parse_blocks( $inner, $depth + 1 ) );
        continue;
      }

      $first = self::list_marker( $line );
      if ( $first !== null ) {
        [ $block, $i ] = self::list_at( $lines, $i, $first, $depth );
        $out[] = $block;
        continue;
      }

      $para = [];
      while ( $i < $n && trim( $lines[ $i ] ) !== '' && ( $para === [] || !self::starts_block( $lines[ $i ], $depth ) ) ) {
        $para[] = trim( $lines[ $i ] );
        $i++;
      }
      $out[] = self::paragraph_block( implode( "\n", $para ) );
    }

    return $out;
  }

  /**
  * Whether a line, met in the middle of a paragraph, would start a block and so end it.
  * As in CommonMark, an ordered list can interrupt a paragraph only when it starts at 1,
  * so "in 2019. was" stays text.
  */
  private static function starts_block( string $line, int $depth ): bool {
    if ( preg_match( '/^ {0,3}(#{1,6})(?:[ \t]|$)/', $line ) ) {
      return true;
    }
    if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/', $line ) ) {
      return true;
    }
    if ( $depth < self::MAX_QUOTE_DEPTH && preg_match( '/^ {0,3}>/', $line ) ) {
      return true;
    }
    $mk = self::list_marker( $line );
    return $mk !== null && ( !$mk['ordered'] || $mk['start'] === 1 );
  }

  /** @return ?array{ordered: bool, start: int, text: string} */
  private static function list_marker( string $line ): ?array {
    if ( preg_match( '/^ {0,3}[-*+][ \t]+(.*)$/', $line, $m ) ) {
      return [ 'ordered' => false, 'start' => 1, 'text' => $m[1] ];
    }
    if ( preg_match( '/^ {0,3}(\d{1,9})[.)][ \t]+(.*)$/', $line, $m ) ) {
      return [ 'ordered' => true, 'start' => (int) $m[1], 'text' => $m[2] ];
    }
    return null;
  }

  /**
  * Collect one list. Items of the same kind continue it; a blank line does not end it when
  * the next item is the same kind. An indented line continues the item above as text, and
  * an unindented line that is not a marker continues it lazily, as CommonMark does.
  * Anything else ends the list.
  *
  * @return array{0: string, 1: int} [ markup, index of the first line after the list ]
  */
  private static function list_at( array $lines, int $i, array $first, int $depth ): array {
    $n = count( $lines );
    $ordered = $first['ordered'];
    $start = $first['start'];
    $items = [];
    $items[] = $first['text'];
    $i++;

    while ( $i < $n ) {
      $line = $lines[ $i ];
      $mk = self::list_marker( $line );
      // Indented by two or more: continues the item above as text, marker or not. Nested
      // lists are not supported, so a sub-item is kept as text rather than made a sibling.
      if ( preg_match( '/^\s{2,}\S/', $line ) ) {
        $items[ count( $items ) - 1 ] .= ' ' . trim( $line );
        $i++;
        continue;
      }
      if ( $mk !== null && $mk['ordered'] === $ordered ) {
        $items[] = $mk['text'];
        $i++;
        continue;
      }
      if ( trim( $line ) === '' ) {
        // A blank line separates items of one list, not two lists.
        $j = $i;
        while ( $j < $n && trim( $lines[ $j ] ) === '' ) {
          $j++;
        }
        $next = $j < $n ? self::list_marker( $lines[ $j ] ) : null;
        if ( $next !== null && $next['ordered'] === $ordered ) {
          $i = $j;
          continue;
        }
        break;
      }
      if ( $mk === null && !self::starts_block( $line, $depth ) ) {
        $items[ count( $items ) - 1 ] .= ' ' . trim( $line );
        $i++;
        continue;
      }
      break;
    }

    return [ self::list_block( $ordered, $start, $items ), $i ];
  }

  private static function heading( int $level, string $html ): string {
    $attrs = $level === 2 ? '' : ' ' . json_encode( [ 'level' => $level ] );
    return '<!-- wp:heading' . $attrs . " -->\n<h" . $level . ' class="wp-block-heading">' . $html . '</h' . $level . ">\n<!-- /wp:heading -->";
  }

  private static function paragraph_block( string $source ): string {
    // A paragraph that is one image and nothing else becomes an image block.
    if ( preg_match( '#^!\[([^\[\]\n]*)\]\((https?://[^()\s"<>\x00-\x1f]+)\)$#i', $source, $m ) ) {
      $img = '<img src="' . self::esc_attr( $m[2] ) . '" alt="' . self::esc_attr( self::plain( $m[1] ) ) . '"/>';
      return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $img . "</figure>\n<!-- /wp:image -->";
    }
    return "<!-- wp:paragraph -->\n<p>" . self::inline( $source ) . "</p>\n<!-- /wp:paragraph -->";
  }

  private static function code_block( string $code ): string {
    return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . self::esc_text( $code ) . "</code></pre>\n<!-- /wp:code -->";
  }

  private static function quote_block( array $inner ): string {
    return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . implode( "\n\n", $inner ) . "</blockquote>\n<!-- /wp:quote -->";
  }

  private static function list_block( bool $ordered, int $start, array $items ): string {
    $tag = $ordered ? 'ol' : 'ul';
    $attrs = [];
    $tagAttrs = '';
    if ( $ordered ) {
      $attrs['ordered'] = true;
      if ( $start !== 1 ) {
        $attrs['start'] = $start;
        $tagAttrs = ' start="' . $start . '"';
      }
    }
    $json = $attrs ? ' ' . json_encode( $attrs ) : '';
    $lis = '';
    foreach ( $items as $item ) {
      $lis .= "<!-- wp:list-item -->\n<li>" . self::inline( trim( $item ) ) . "</li>\n<!-- /wp:list-item -->\n";
    }
    return '<!-- wp:list' . $json . ' -->' . "\n" . '<' . $tag . $tagAttrs . ' class="wp-block-list">' . rtrim( $lis, "\n" ) . '</' . $tag . ">\n<!-- /wp:list -->";
  }

  #endregion

  #region Inline helpers

  /** Bold, then italic in the text between bold spans, so the two never cross a tag. */
  private static function emphasis( string $s ): string {
    $out = '';
    $pos = 0;
    while ( preg_match( '/\*\*(?=\S)((?:(?!\*\*).)+?)(?<=\S)\*\*|__(?=\S)((?:(?!__).)+?)(?<=\S)__/s', $s, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
      $start = $m[0][1];
      $inner = $m[1][0] !== '' ? $m[1][0] : $m[2][0];
      $out .= self::italic( substr( $s, $pos, $start - $pos ) );
      $out .= '<strong>' . self::italic( $inner ) . '</strong>';
      $pos = $start + strlen( $m[0][0] );
    }
    return $out . self::italic( substr( $s, $pos ) );
  }

  private static function italic( string $s ): string {
    $s = preg_replace( '/(?<![\w*])\*(?![\s*])([^*\n]+?)(?<![\s*])\*(?![\w*])/', '<em>$1</em>', $s ) ?? $s;
    return preg_replace( '/(?<![\w_])_(?![\s_])([^_\n]+?)(?<![\s_])_(?![\w_])/', '<em>$1</em>', $s ) ?? $s;
  }

  /**
  * Plain text for an attribute such as an image's alt. Code spans are replaced by their
  * text, so no <code> tag can end up inside an attribute. Takes the label either before
  * or after the inline pass, which is why both forms are handled.
  */
  private static function plain( string $label ): string {
    $label = preg_replace_callback( '/\x00C(\d+)\x00/', fn ( $m ) => self::$codes[ (int) $m[1] ] ?? '', $label ) ?? $label;
    $label = preg_replace( '/\x00L\d+\x00/', '', $label ) ?? $label;
    $label = preg_replace_callback( '/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s', fn ( $m ) => $m[2], $label ) ?? $label;
    return $label;
  }

  private static function esc_text( string $s ): string {
    return htmlspecialchars( $s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
  }

  private static function esc_attr( string $s ): string {
    return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
  }

  #endregion
}
