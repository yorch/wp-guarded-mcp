<?php
/*
 * Standalone test for includes/markdown-blocks.php. No WordPress, no Docker:
 *
 *   php .dev/test-markdown-blocks.php
 *
 * Exits 1 on any failed assertion. Each hostile case is paired with a control in the same
 * block, so an "absent" check cannot pass just because the converter produced nothing.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/markdown-blocks.php';

$pass = 0;
$fail = 0;

function show( string $label, string $md ): void {
  [ $markup, $err, $count ] = GMCP_Markdown_Blocks::to_markup( $md );
  echo "---- $label\n";
  echo "input:  " . json_encode( $md ) . "\n";
  echo $err !== null ? "error:  $err\n" : "output:\n$markup\n(top-level blocks: $count)\n";
}

function expect( string $label, bool $ok, string $detail = '' ): void {
  global $pass, $fail;
  if ( $ok ) {
    $pass++;
    echo "  PASS  $label\n";
  }
  else {
    $fail++;
    echo "  FAIL  $label" . ( $detail !== '' ? "  [$detail]" : '' ) . "\n";
  }
}

function md( string $s ): string {
  return GMCP_Markdown_Blocks::to_markup( $s )[0] ?? '';
}

function inl( string $s ): string {
  return GMCP_Markdown_Blocks::inline( $s );
}

// ---- Basic blocks: output shape, exact --------------------------------------------------

show( 'headings, paragraph', "# Title\n\nHello *world* and **bold**.\n\n### Third" );
expect(
  'heading 1 carries level attr',
  md( '# Title' ) === "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Title</h1>\n<!-- /wp:heading -->"
);
expect(
  'heading 2 has no level attr (Gutenberg canonical)',
  md( '## Sub' ) === "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Sub</h2>\n<!-- /wp:heading -->"
);
expect( 'hashtag without space is a paragraph, not a heading', strpos( md( '#hashtag' ), '<p>#hashtag</p>' ) !== false );
expect( 'seven hashes is a paragraph', strpos( md( '####### x' ), '<p>####### x</p>' ) !== false );
expect( 'closing hashes are stripped', strpos( md( '## Head ##' ), '>Head</h2>' ) !== false );

show( 'unordered list', "- one\n- two\n* three" );
expect( 'bullets of mixed markers form one list', substr_count( md( "- one\n- two\n* three" ), '<!-- wp:list-item -->' ) === 3 );
expect( 'unordered list uses ul', strpos( md( "- a" ), '<ul class="wp-block-list">' ) !== false );

show( 'ordered list, start at 3', "3. three\n4. four" );
expect( 'ordered list carries start attr', strpos( md( "3. three\n4. four" ), '<!-- wp:list {"ordered":true,"start":3} -->' ) !== false );
expect( 'ordered list starting at 1 has no start attr', strpos( md( "1. a\n2. b" ), '{"ordered":true}' ) !== false && strpos( md( "1. a" ), 'start=' ) === false );

show( 'lazy continuation and indented continuation', "- first item\nstill first\n  indented under it\n- second" );
expect( 'lazy and indented lines join the item above', strpos( md( "- first item\nstill first\n  indented under it\n- second" ), '<li>first item still first indented under it</li>' ) !== false );

show( 'blockquote containing a list and a paragraph', "> quoted para\n>\n> - q1\n> - q2" );
expect( 'blockquote wraps inner blocks', strpos( md( "> quoted para\n>\n> - q1\n> - q2" ), '<blockquote class="wp-block-quote"><!-- wp:paragraph -->' ) !== false );
expect( 'blockquote inner list is a real list', substr_count( md( "> a\n>\n> - q1\n> - q2" ), '<!-- wp:list-item -->' ) === 2 );

show( 'fenced code keeps markup literal', "```php\n<?php echo \"<b>\" & 1; ?>\n```" );
expect(
  'fenced code is escaped and not inline-parsed',
  md( "```\n*not italic* <b>x</b>\n```" ) === "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>*not italic* &lt;b&gt;x&lt;/b&gt;</code></pre>\n<!-- /wp:code -->"
);
expect( 'tilde fence works', strpos( md( "~~~\nx\n~~~" ), '<code>x</code>' ) !== false );
expect( 'unclosed fence runs to end without error', strpos( md( "```\nabc" ), '<code>abc</code>' ) !== false );

show( 'image alone in a paragraph becomes an image block', "![A cat](https://example.com/cat.jpg)" );
expect(
  'standalone image is an image block',
  md( '![A cat](https://example.com/cat.jpg)' ) === "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/cat.jpg\" alt=\"A cat\"/></figure>\n<!-- /wp:image -->"
);

// ---- Inline ----------------------------------------------------------------------------

show( 'inline emphasis, code, links', "Use `wp_get_posts` or [the docs](https://example.com/docs?a=1&b=2) for **bold _nested_ text**." );
expect( 'code span', inl( 'a `x*y*z` b' ) === 'a <code>x*y*z</code> b' );
expect( 'bold with italic nested inside', inl( '**bold *and italic* inside**' ) === '<strong>bold <em>and italic</em> inside</strong>' );
expect( 'underscore emphasis', inl( '_one_ and __two__' ) === '<em>one</em> and <strong>two</strong>' );
expect( 'snake_case is not emphasis', inl( 'snake_case_name' ) === 'snake_case_name' );
expect( 'link, ampersand escaped in href', inl( '[d](https://example.com/?a=1&b=2)' ) === '<a href="https://example.com/?a=1&amp;b=2">d</a>' );
expect( 'emphasis inside link label', inl( '[**x**](https://example.com)' ) === '<a href="https://example.com"><strong>x</strong></a>' );
expect( 'inline image, http only', inl( 'see ![p](https://example.com/p.png) here' ) === 'see <img src="https://example.com/p.png" alt="p"/> here' );
expect( 'mailto link allowed', inl( '[mail](mailto:a@example.com)' ) === '<a href="mailto:a@example.com">mail</a>' );

// Italic wrapping bold is documented as unsupported: it must stay literal, never mis-nest tags.
$mixed = inl( '*italic with **bold** inside*' );
expect( 'italic around bold stays literal and well-nested', $mixed === '*italic with <strong>bold</strong> inside*', $mixed );

$triple = inl( '***both***' );
expect( 'triple asterisk output is balanced', substr_count( $triple, '<strong>' ) === substr_count( $triple, '</strong>' ), $triple );

// ---- Hostile input: each one paired with a control that the converter DID handle ----------

show( 'HOSTILE: javascript: link, control is a good link in the same paragraph',
  "[bad](javascript:alert(1)) and [good](https://example.com/ok)" );
$h = inl( '[bad](javascript:alert(1)) and [good](https://example.com/ok)' );
expect( 'control: good link is produced', strpos( $h, '<a href="https://example.com/ok">good</a>' ) !== false, $h );
expect( 'javascript: link is not an anchor', stripos( $h, 'href="javascript' ) === false && stripos( $h, '<a href="javascript' ) === false, $h );
expect( 'javascript: text is shown as text', strpos( $h, '[bad](javascript:alert(1))' ) !== false, $h );

$h2 = inl( '[x](javascript:alert) [y](JaVaScRiPt:alert) [z](data:text/html,x) [ok](http://example.com)' );
expect( 'control: http link produced next to refused schemes', strpos( $h2, '<a href="http://example.com">ok</a>' ) !== false, $h2 );
expect( 'mixed-case javascript: refused', stripos( $h2, '<a href="javascript' ) === false && stripos( $h2, 'href="JaVaScRiPt' ) === false, $h2 );
expect( 'data: URL refused', stripos( $h2, 'data:' ) !== false && stripos( $h2, '<a href="data' ) === false, $h2 );

$h3 = inl( '![x](javascript:alert(1)) ![ok](https://example.com/i.png)' );
expect( 'control: https image produced', strpos( $h3, '<img src="https://example.com/i.png" alt="ok"/>' ) !== false, $h3 );
expect( 'javascript: image src refused', stripos( $h3, 'src="javascript' ) === false, $h3 );

// The two cases above use parentheses in the URL, which the URL pattern refuses before any
// scheme check runs. These have none, so they reach the scheme check itself.
$h4 = inl( '![x](javascript:alert) ![y](data:image/svg+xml;base64,PHN2Zz4=) ![ok](https://example.com/j.png)' );
expect( 'control: https image produced beside schemeless-parenthesis forms', strpos( $h4, '<img src="https://example.com/j.png" alt="ok"/>' ) !== false, $h4 );
expect( 'image with javascript: scheme (no parentheses) refused', stripos( $h4, 'src="javascript' ) === false, $h4 );
expect( 'image with data: scheme refused', stripos( $h4, 'src="data' ) === false, $h4 );
$h5 = md( '![x](javascript:alert)' );
expect( 'standalone javascript: image is paragraph text, not an image block', strpos( $h5, 'wp:image' ) === false && stripos( $h5, 'src=' ) === false, $h5 );

show( 'HOSTILE: <script> and raw HTML in a paragraph', "<script>alert(1)</script>\n\nkeep <img src=x onerror=alert(1)> this *one*" );
$s = md( "<script>alert(1)</script>\n\nkeep <img src=x onerror=alert(1)> this *one*" );
expect( 'control: markdown italic still works next to raw HTML', strpos( $s, '<em>one</em>' ) !== false, $s );
expect( 'script tag is escaped to text', strpos( $s, '&lt;script&gt;alert(1)&lt;/script&gt;' ) !== false, $s );
expect( 'no live script tag', stripos( $s, '<script' ) === false, $s );
expect( 'no live img tag from raw HTML', stripos( $s, '<img src=x' ) === false, $s );
expect( 'raw img with onerror is text, not an element', strpos( $s, '&lt;img src=x onerror=alert(1)&gt;' ) !== false, $s );

show( 'HOSTILE: block-comment injection tries to smuggle a block', "<!-- wp:html -->\n<script>x()</script>\n<!-- /wp:html -->\n\n- item" );
$b = md( "<!-- wp:html -->\n<script>x()</script>\n<!-- /wp:html -->\n\n- item" );
expect( 'control: the list still becomes a list block', strpos( $b, '<!-- wp:list' ) !== false, $b );
expect( 'block comment in source is escaped, no html block created', strpos( $b, '<!-- wp:html' ) === false || strpos( $b, '&lt;!-- wp:html' ) !== false, $b );
expect( 'source block delimiters cannot appear as live comments', substr_count( $b, '<!-- wp:html' ) === 0, $b );

show( 'HOSTILE: attribute breakout through link text and URL', '[x"onmouseover="alert(1)](https://example.com/"onclick="x)' );
$q = inl( '[x"onmouseover="alert(1)](https://example.com/"onclick="x)' );
expect( 'quote-laden link is not an anchor (its text is shown as text)', strpos( $q, '<a ' ) === false && strpos( $q, '[x"onmouseover' ) !== false, $q );
expect( 'no attribute injected into anchor', strpos( $q, '<a href="https://example.com/"onclick' ) === false, $q );

$alt = inl( '![a"onerror="x](https://example.com/i.png)' );
expect( 'quote in alt is escaped inside attribute', strpos( $alt, 'alt="a&quot;onerror=&quot;x"' ) !== false, $alt );

show( 'HOSTILE: code inside image alt must not break the attribute', '![see `code` here](https://example.com/c.png)' );
$ca = inl( '![see `code` here](https://example.com/c.png)' );
expect( 'alt contains plain text, not a <code> tag', strpos( $ca, 'alt="see code here"' ) !== false, $ca );

show( 'HOSTILE: nested emphasis and unclosed markers', "**unclosed and *open\n\n***\n\n** **\n\n*a **b* c**" );
$n = inl( '**unclosed and *open' );
expect( 'unclosed bold is literal', $n === '**unclosed and <em>open</em>' || $n === '**unclosed and *open', $n );
$n2 = inl( '** **' );
expect( 'empty-looking bold is literal', $n2 === '** **', $n2 );
$n3 = inl( '*a **b* c**' );
expect( 'crossed emphasis does not produce crossed tags', substr_count( $n3, '<strong>' ) === substr_count( $n3, '</strong>' ) && substr_count( $n3, '<em>' ) === substr_count( $n3, '</em>' ), $n3 );

show( 'HOSTILE: NUL bytes and invalid UTF-8', "ok\x00<b>x</b>\n\nbad \xc3\x28 byte" );
$u = md( "ok\x00<b>x</b>\n\nbad \xc3\x28 byte" );
expect( 'NUL removed, HTML still escaped', strpos( $u, "\x00" ) === false && strpos( $u, '&lt;b&gt;' ) !== false, bin2hex( substr( $u, 0, 40 ) ) );
expect( 'invalid UTF-8 does not empty the paragraph', strpos( $u, 'byte' ) !== false, $u );

show( 'HOSTILE: placeholder forgery in the source', "A \x00C0\x00 and \x00L0\x00 literal" );
$pf = md( "A \x00C0\x00 and \x00L0\x00 literal" );
expect( 'forged placeholders are not resolved into code or links', strpos( $pf, '<code>' ) === false && strpos( $pf, '<a ' ) === false && strpos( $pf, "\x00" ) === false, $pf );

// ---- Empty and degenerate input -----------------------------------------------------------

$e = GMCP_Markdown_Blocks::to_markup( "   \n\n  " );
expect( 'whitespace-only input is an error, not an empty write', $e[0] === null && $e[1] !== null );
$e2 = GMCP_Markdown_Blocks::to_markup( '' );
expect( 'empty string is an error', $e2[0] === null && $e2[1] !== null );
$deep = str_repeat( '>', 50 ) . ' deep';
$d = GMCP_Markdown_Blocks::to_markup( $deep );
expect( 'deep quote nesting terminates and is bounded', $d[0] !== null && substr_count( $d[0], '<blockquote' ) <= GMCP_Markdown_Blocks::MAX_QUOTE_DEPTH );
expect( 'count of top-level blocks is reported', GMCP_Markdown_Blocks::to_markup( "# a\n\nb\n\n- c" )[2] === 3 );

// ---- Unsupported syntax stays text -----------------------------------------------------------

show( 'unsupported syntax passes through as text', "Setext\n===\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n---\n\n1. one\n   1. nested" );
$t = md( "Setext\n===\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n---" );
expect( 'table and rule are paragraph text', substr_count( $t, '<!-- wp:paragraph -->' ) >= 3 && strpos( $t, '| a | b |' ) !== false, $t );
$nl = md( "1. one\n   1. nested" );
expect( 'nested ordered item is text inside the item', strpos( $nl, '<li>one 1. nested</li>' ) !== false, $nl );
$ot = md( "in 2019. was a year" );
expect( 'ordered marker not at 1 does not interrupt a paragraph', strpos( $ot, '<p>in 2019. was a year</p>' ) !== false, $ot );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
