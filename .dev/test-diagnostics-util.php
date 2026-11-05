<?php
/**
* Standalone checks for includes/diagnostics-util.php. No WordPress, no network, no site.
*
*   php .dev/test-diagnostics-util.php
*
* Exits non-zero on any failure. Each "refused" or "removed" check sits next to a control
* that proves the same probe finds a case that IS allowed or IS present, because an absence
* check with no control passes just as happily when the probe is broken.
*
* What this does not cover: the HTTP request, DNS pinning, the front-end preview hooks and
* anything that needs WordPress. Those are in the smoke suites, which need a site.
*/
define( 'ABSPATH', '/' );
require __DIR__ . '/../includes/diagnostics-util.php';

$pass = 0;
$fail = 0;
function check( string $label, $actual, $expected ): void {
  global $pass, $fail;
  if ( $actual === $expected ) {
    $pass++;
    echo "  PASS  $label\n";
  }
  else {
    $fail++;
    echo "  FAIL  $label (got " . var_export( $actual, true ) . ', want ' . var_export( $expected, true ) . ")\n";
  }
}

// A fake resolver, so nothing here touches DNS.
$dns = [
  'example.com' => [ '93.184.216.34' ],
  'mixed.example.com' => [ '93.184.216.34', '10.0.0.5' ],
  'meta.example.com' => [ '169.254.169.254' ],
  'internal.example.com' => [ '10.1.2.3' ],
  'dev.test' => [ '127.0.0.1' ],
  'v6.example.com' => [ '2606:2800:220:1:248:1893:25c8:1946' ],
  'nx.example.com' => [],
  // Hosts that DO resolve to a public address, so refusing them can only be the host rule
  // and not a failed lookup standing in for it.
  'evil.com' => [ '6.6.6.6' ],
  'www.example.com' => [ '93.184.216.35' ],
  'example.com.evil.com' => [ '6.6.6.6' ],
];
$resolve = static function ( string $h ) use ( &$dns ): array {
  return $dns[ $h ] ?? [];
};
$fetch = static function ( string $url, string $home = 'https://example.com', bool $allow = false ) use ( $resolve ): array {
  return GMCP_Diag_Util::check_fetch_url( $url, $home, $resolve, $allow );
};

echo "-- classify_ip --\n";
foreach ( [
  [ '93.184.216.34', 'public' ], [ '8.8.8.8', 'public' ], [ '127.0.0.1', 'loopback' ], [ '127.255.255.254', 'loopback' ],
  [ '10.0.0.1', 'private' ], [ '172.16.0.1', 'private' ], [ '172.31.255.255', 'private' ], [ '172.32.0.1', 'public' ],
  [ '192.168.1.1', 'private' ], [ '100.64.0.1', 'private' ], [ '169.254.169.254', 'never' ], [ '0.0.0.0', 'never' ],
  [ '224.0.0.1', 'never' ], [ '255.255.255.255', 'never' ], [ '::1', 'loopback' ], [ '::', 'never' ],
  [ '::ffff:127.0.0.1', 'loopback' ], [ '::ffff:169.254.169.254', 'never' ], [ '::ffff:8.8.8.8', 'public' ],
  [ '64:ff9b::7f00:1', 'loopback' ], [ '2002:7f00:1::1', 'loopback' ], [ 'fe80::1', 'never' ], [ 'fd12:3456::1', 'private' ],
  [ 'fd00:ec2::254', 'never' ], [ '2606:2800:220:1::1', 'public' ], [ 'ff02::1', 'never' ], [ '2001:db8::1', 'never' ],
  [ 'not-an-ip', 'invalid' ], [ '2130706433', 'invalid' ], [ '0x7f.1', 'invalid' ], [ '127.1', 'invalid' ], [ 'fe80::1%eth0', 'invalid' ],
] as [ $ip, $want ] ) {
  check( "classify $ip", GMCP_Diag_Util::classify_ip( $ip ), $want );
}

echo "-- check_fetch_url: what is allowed (controls) --\n";
$r = $fetch( 'https://example.com/' );
check( 'control: the site root', $r['ok'], true );
check( 'control: rebuilt URL', $r['url'], 'https://example.com/' );
check( 'control: pinned to the checked address', $r['pin'], '93.184.216.34' );
check( 'control: a page with a query', $fetch( 'https://EXAMPLE.com./about/?x=1#frag' )['url'], 'https://example.com/about/?x=1' );
check( 'control: explicit default port', $fetch( 'https://example.com:443/a' )['ok'], true );
check( 'control: index.php is a page', $fetch( 'https://example.com/index.php?p=3' )['ok'], true );
check( 'control: a site in a subdirectory', $fetch( 'https://example.com/blog/post/', 'https://example.com/blog' )['ok'], true );
check( 'control: IPv6 answer', $fetch( 'https://v6.example.com/', 'https://v6.example.com' )['ok'], true );

echo "-- check_fetch_url: what is refused --\n";
$refused = [
  'other host' => [ 'https://evil.com/' ],
  'sub-domain of the site' => [ 'https://www.example.com/' ],
  'host as suffix trick' => [ 'https://example.com.evil.com/' ],
  'userinfo' => [ 'https://user:pw@example.com/' ],
  'userinfo host trick' => [ 'https://example.com@evil.com/' ],
  'backslash trick' => [ 'https://evil.com\\@example.com/' ],
  'space' => [ 'https://example.com/a b' ],
  'newline' => [ "https://example.com/a\r\nHost: evil" ],
  'non-ASCII' => [ "https://example.com/\u{00e9}" ],
  'wrong scheme (downgrade)' => [ 'http://example.com/' ],
  'file scheme' => [ 'file:///etc/passwd' ],
  'gopher' => [ 'gopher://example.com/' ],
  'wrong port' => [ 'https://example.com:8443/' ],
  'port zero trick' => [ 'https://example.com:80/' ],
  'protocol-relative' => [ '//example.com/' ],
  'no scheme' => [ 'example.com/' ],
  'empty' => [ '' ],
  'IP literal for another host' => [ 'https://127.0.0.1/' ],
  'decimal IP' => [ 'https://2130706433/' ],
  'encoded slash' => [ 'https://example.com/a%2f..%2fb' ],
  'encoded dot' => [ 'https://example.com/%2e%2e/x' ],
  'dot segments' => [ 'https://example.com/a/../wp-admin/' ],
  'wp-admin' => [ 'https://example.com/wp-admin/' ],
  'wp-admin, mixed case' => [ 'https://example.com/WP-Admin/admin-ajax.php?x=1' ],
  'wp-login' => [ 'https://example.com/wp-login.php?action=logout' ],
  'wp-cron' => [ 'https://example.com/wp-cron.php' ],
  'xmlrpc' => [ 'https://example.com/xmlrpc.php' ],
  'rest api' => [ 'https://example.com/wp-json/wp/v2/users' ],
  'rest via rest_route' => [ 'https://example.com/?rest_route=/wp/v2/users' ],
  'any other php file' => [ 'https://example.com/wp-content/plugins/x/do.php' ],
  'add-to-cart' => [ 'https://example.com/?add-to-cart=5' ],
  'add-to-cart, encoded key' => [ 'https://example.com/?add%2Dto%2Dcart=5' ],
  'action param' => [ 'https://example.com/?action=delete' ],
  'preview credential' => [ 'https://example.com/?p=3&gmcp_preview=abc' ],
  'host that does not resolve' => [ 'https://nx.example.com/', 'https://nx.example.com' ],
  'one public, one private record' => [ 'https://mixed.example.com/', 'https://mixed.example.com' ],
  'name resolving to metadata' => [ 'https://meta.example.com/', 'https://meta.example.com' ],
  'production name resolving privately' => [ 'https://internal.example.com/', 'https://internal.example.com' ],
  'path outside the site subdirectory' => [ 'https://example.com/other-app/', 'https://example.com/blog' ],
  'prefix of the subdirectory only' => [ 'https://example.com/blogger/', 'https://example.com/blog' ],
  'long URL' => [ 'https://example.com/' . str_repeat( 'a', 2100 ) ],
];
foreach ( $refused as $label => $args ) {
  $r = $fetch( ...$args );
  check( "refused: $label", $r['ok'], false );
  check( "  ...and says why ($label)", $r['reason'] !== '', true );
}

check( 'scheme alone differs (same port)', $fetch( 'https://example.com:8080/', 'http://example.com:8080' )['ok'], false );
check( 'control: same scheme, same non-default port', $fetch( 'http://example.com:8080/', 'http://example.com:8080' )['ok'], true );

echo "-- check_fetch_url: local development sites --\n";
check( 'localhost on loopback', $fetch( 'http://localhost:8080/', 'http://localhost:8080', false )['ok'], false ); // 'localhost' is not in $dns: does not resolve here
$dns['localhost'] = [ '127.0.0.1' ];
check( 'control: localhost on loopback, site is localhost', $fetch( 'http://localhost:8080/x', 'http://localhost:8080' )['ok'], true );
check( 'localhost, wrong port', $fetch( 'http://localhost:9999/x', 'http://localhost:8080' )['ok'], false );
check( 'control: .test site on loopback', $fetch( 'https://dev.test/', 'https://dev.test' )['ok'], true );
$dns['dev.test'] = [ '169.254.169.254' ];
check( 'a local-looking site pointed at metadata is refused', $fetch( 'https://dev.test/', 'https://dev.test' )['ok'], false );
check( 'literal loopback IP site', $fetch( 'http://127.0.0.1:8080/a', 'http://127.0.0.1:8080' )['ok'], true );
check( 'literal metadata IP site is refused', $fetch( 'http://169.254.169.254/', 'http://169.254.169.254' )['ok'], false );
check( 'filter opens a private resolve for a production name', $fetch( 'https://internal.example.com/', 'https://internal.example.com', true )['ok'], true );
check( 'the filter does not open the never class', ( function () use ( $fetch ) {
  return $fetch( 'https://meta.example.com/', 'https://meta.example.com', true )['ok'];
} )(), false );

echo "-- resolve_location --\n";
foreach ( [
  [ 'https://example.com/a/b', '/c', 'https://example.com/c' ],
  [ 'https://example.com/a/b', 'c', 'https://example.com/a/c' ],
  [ 'https://example.com:8443/a/b?x=1', '?y=2', 'https://example.com:8443/a/b?y=2' ],
  [ 'https://example.com/a', '//evil.com/x', 'https://evil.com/x' ],
  [ 'https://example.com/a', 'http://evil.com/', 'http://evil.com/' ],
  [ 'https://example.com/a', 'https://example.com/z', 'https://example.com/z' ],
] as [ $base, $loc, $want ] ) {
  check( "resolve $loc against $base", GMCP_Diag_Util::resolve_location( $base, $loc ), $want );
}
check( 'a redirect off-site fails validation after resolution', $fetch( GMCP_Diag_Util::resolve_location( 'https://example.com/a', '//evil.com/x' ) )['ok'], false );
check( 'control: a redirect on-site passes validation after resolution', $fetch( GMCP_Diag_Util::resolve_location( 'https://example.com/a', '/b/' ) )['ok'], true );

echo "-- extract_page --\n";
$html = '<!doctype html><html><head><title> My  Page </title><meta name="robots" content="noindex, follow">'
  . '<link rel="canonical" href="https://example.com/canon/"><style>.x{color:red}</style></head><body>'
  . '<h1>Hello <b>World</b></h1><p>Visible text, Needle and needle.</p>'
  . '<script>var secretScript="SCRIPT_TEXT";</script><noscript>NOSCRIPT_TEXT</noscript>'
  . '<div style="display: none">HIDDEN_STYLE</div><div hidden>HIDDEN_ATTR</div><span aria-hidden="true">ARIA</span>'
  . '<!-- COMMENT_TEXT --><p>caf&eacute; &amp; more</p><svg><text>SVG_TEXT</text></svg></body></html>';
$p = GMCP_Diag_Util::extract_page( $html, 4000, 'needle' );
check( 'title', $p['title'], 'My Page' );
check( 'meta robots', $p['meta_robots'], 'noindex, follow' );
check( 'canonical', $p['canonical'], 'https://example.com/canon/' );
check( 'h1', $p['h1'], 'Hello World' );
check( 'control: visible text is present', strpos( $p['text'], 'Visible text' ) !== false, true );
check( 'control: entities decode', strpos( $p['text'], 'café & more' ) !== false, true );
foreach ( [ 'SCRIPT_TEXT', 'NOSCRIPT_TEXT', 'HIDDEN_STYLE', 'HIDDEN_ATTR', 'ARIA', 'COMMENT_TEXT', 'SVG_TEXT', 'color:red' ] as $needle ) {
  check( "not in the visible text: $needle", strpos( $p['text'], $needle ) !== false, false );
}
check( 'expected string counted case-insensitively', $p['expected_count'], 2 );
check( 'expected string absent counts 0, not null', GMCP_Diag_Util::extract_page( $html, 4000, 'zzz' )['expected_count'], 0 );
check( 'no expected string leaves the count null', $p['expected_count'] !== null && GMCP_Diag_Util::extract_page( $html )['expected_count'] === null, true );
$short = GMCP_Diag_Util::extract_page( $html, 10 );
check( 'excerpt is capped', mb_strlen( $short['text'] ), 10 );
check( 'and says it was cut', $short['text_truncated'], true );
check( 'full length is still reported', $short['text_chars'] > 10, true );
check( 'empty body', GMCP_Diag_Util::extract_page( '' )['text'], '' );

echo "-- read_tail --\n";
$dir = sys_get_temp_dir() . '/gmcp-diag-' . getmypid();
mkdir( $dir );
$f = "$dir/debug.log";
$body = '';
for ( $i = 1; $i <= 1000; $i++ ) {
  $body .= "line $i\n";
}
file_put_contents( $f, $body );
$t = GMCP_Diag_Util::read_tail( $f, 5, 1048576 );
check( 'control: last five lines', $t['lines'], [ 'line 996', 'line 997', 'line 998', 'line 999', 'line 1000' ] );
check( 'whole file read, so it does not start mid-file', $t['starts_mid_file'], false );
$t = GMCP_Diag_Util::read_tail( $f, 5000, 100 );
check( 'byte cap bounds what is read', array_sum( array_map( 'strlen', $t['lines'] ) ) <= 100, true );
check( 'byte cap: the newest line is still last', end( $t['lines'] ), 'line 1000' );
check( 'byte cap: says it starts mid-file', $t['starts_mid_file'], true );
check( 'byte cap: a partial first line is dropped', preg_match( '/^line \d+$/', $t['lines'][0] ), 1 );
check( 'empty file is ok with no lines', GMCP_Diag_Util::read_tail( ( function () use ( $dir ) { file_put_contents( "$dir/e.log", '' ); return "$dir/e.log"; } )(), 5, 100 )['lines'], [] );
check( 'a directory is refused', GMCP_Diag_Util::read_tail( $dir, 5, 100 )['ok'], false );
check( 'a missing file is refused', GMCP_Diag_Util::read_tail( "$dir/none.log", 5, 100 )['ok'], false );
file_put_contents( "$dir/crlf.log", "a\r\nb\r\nc" );
check( 'CRLF and an unterminated last line', GMCP_Diag_Util::read_tail( "$dir/crlf.log", 10, 100 )['lines'], [ 'a', 'b', 'c' ] );
$big = "$dir/big.log";
$fh = fopen( $big, 'wb' );
ftruncate( $fh, 3 * 1024 * 1024 * 1024 ); // sparse: 3 GiB that costs nothing
fseek( $fh, 3 * 1024 * 1024 * 1024 );
fwrite( $fh, "\ntail one\ntail two\n" );
fclose( $fh );
$mem = memory_get_peak_usage();
$t = GMCP_Diag_Util::read_tail( $big, 2, 65536 );
check( 'a 3 GiB file: last lines found', $t['lines'], [ 'tail one', 'tail two' ] );
check( 'a 3 GiB file: memory stayed small', memory_get_peak_usage() - $mem < 4 * 1024 * 1024, true );
unlink( $big );

echo "-- scrub_log_line --\n";
$ctx = [ 'content_dir' => '/srv/www/site/wp-content', 'abspath' => '/srv/www/site/', 'patterns' => [ 'pass', 'pwd', 'secret', 'token', 'key', 'auth', 'salt', 'nonce', 'credential', 'bearer', 'signature', 'licence', 'license', 'private' ] ];
$s = static function ( string $l ) use ( $ctx ): string {
  return GMCP_Diag_Util::scrub_log_line( $l, $ctx );
};
$has = static function ( string $haystack, string $needle ): bool {
  return strpos( $haystack, $needle ) !== false;
};

// Each case: [ label, input, secret that must be gone, text that must survive ]
$cases = [
  [ 'password in a query', 'GET /login?user=bob&password=hunter2xyz&x=1', 'hunter2xyz', 'user=bob' ],
  [ 'token in a URL', 'request to https://api.test/v1?access_token=AbC123SeCrEt&page=2 failed', 'AbC123SeCrEt', 'page=2' ],
  [ 'JSON secret', 'payload {"client_secret":"s3cr3tvalue","name":"ok"}', 's3cr3tvalue', '"name":"ok"' ],
  [ 'PHP array dump', "[api_key] => sk_live_abcdef0123456789\n", 'sk_live_abcdef0123456789', '[api_key]' ],
  [ 'Authorization header', 'curl sent Authorization: Bearer abcdefghijklmnop1234', 'abcdefghijklmnop1234', 'curl sent' ],
  [ 'bare Bearer', 'header was Bearer eyAbCdEfGhIjKlMnOpQr', 'eyAbCdEfGhIjKlMnOpQr', 'header was' ],
  [ 'basic auth in URL', 'cURL error: https://admin:p4ssw0rd@db.internal/x', 'p4ssw0rd', 'db.internal' ],
  [ 'JWT', 'cookie eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'eyJzdWIiOi', 'cookie' ],
  [ 'phpass hash', 'user_pass: $P$BabcdefghijklmnopqrstuvwxyzABC0', '$P$Babcdef', 'user_pass' ],
  [ 'bcrypt hash', 'hash $2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234 end', '$2y$10$abcdef', 'end' ],
  [ 'this plugin\'s key', 'used gmcp_a1b2c3d4_0123456789abcdef0123 to call', 'gmcp_a1b2c3d4_0123', 'to call' ],
  [ 'email address', 'Mail failed for alice@customer-domain.org (subject x)', 'alice@customer-domain.org', 'Mail failed for' ],
  [ 'public IPv4', 'Client 203.0.114.9 requested /x', '203.0.114.9', 'requested /x' ],
  [ 'public IPv6', 'Client 2606:2800:220:1:248:1893:25c8:1946 requested /x', '2606:2800:220:1:248:1893:25c8:1946', 'requested /x' ],
  [ 'opaque token', 'Stripe said ABCDEF0123456789abcdef0123456789ABCD was invalid', 'ABCDEF0123456789abcdef0123456789ABCD', 'was invalid' ],
  [ 'stack frame arguments', "#2 /srv/www/site/wp-includes/user.php(120): wp_authenticate('admin', 'MyPassw0rd!', Object(WP_User))", 'MyPassw0rd', 'wp_authenticate(' ],
  [ 'SQL values', 'WordPress database error Duplicate entry for query INSERT INTO wp_users (user_login) VALUES (\'bob\') ' . str_repeat( 'x', 140 ) . ' SECRET_TAIL_VALUE', 'SECRET_TAIL_VALUE', 'INSERT INTO wp_users' ],
];
foreach ( $cases as [ $label, $in, $gone, $kept ] ) {
  $out = $s( $in );
  check( "removed: $label", $has( $out, $gone ), false );
  check( "  ...rest of the line kept ($label)", $has( $out, $kept ), true );
  check( "  ...control: the probe sees it in the input ($label)", $has( $in, $gone ), true );
}

echo "-- scrub_log_line: left alone --\n";
$keep = [
  'PHP Warning:  Undefined array key "foo" in [content]/plugins/x/y.php on line 12',
  'PHP Notice:  Function _load_textdomain_just_in_time was called incorrectly.',
  'cURL error 7: Failed to connect to 127.0.0.1 port 8080',
  'connect to 10.0.0.5 port 3306 failed',
  'Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
  '[04-Nov-2026 22:36:32 UTC] PHP Deprecated:  Creation of dynamic property is deprecated',
  'Call to undefined function some_very_long_but_ordinary_function_name_without_digits()',
];
foreach ( $keep as $line ) {
  check( 'unchanged: ' . substr( $line, 0, 50 ), GMCP_Diag_Util::scrub_log_line( $line, [] ), $line );
}

echo "-- scrub_log_line: paths and bounds --\n";
check( 'content dir shortened', $s( 'in /srv/www/site/wp-content/plugins/x/a.php on line 3' ), 'in [content]/plugins/x/a.php on line 3' );
check( 'root shortened', $s( 'in /srv/www/site/wp-includes/load.php' ), 'in [abspath]/wp-includes/load.php' );
check( 'content dir wins over root even when nested', $has( $s( '/srv/www/site/wp-content/x' ), '[abspath]' ), false );
$long = $s( str_repeat( 'a ', 2000 ) );
check( 'a very long line is cut', mb_strlen( $long ) < 2100, true );
check( 'and says so', $has( $long, 'line truncated' ), true );
check( 'invalid UTF-8 does not survive', preg_match( '//u', $s( "bad \xC3\x28 bytes" ) ), 1 );
check( 'control characters removed', $s( "a\x00b\x1bc" ), 'abc' );

echo "-- check_fetch_url: spellings of a blocked target --\n";
// Each of these reached a 200 from wp-json / wp-login / the draft preview before the path
// and the query keys were compared in their decoded, PHP-normalised form.
$tok = str_repeat( 'a1', 20 );
foreach ( [
  'index.php before the REST route' => '/index.php/wp-json/wp/v2/users',
  'index.php PATH_INFO page' => '/index.php/about/',
  'php file with a path after it' => '/wp-content/plugins/x/do.php/more',
  'encoded hyphen in wp-json' => '/wp%2Djson/wp/v2/users',
  'encoded p in wp-login.php' => '/wp-login.ph%70',
  'encoded p in wp-cron.php' => '/wp-cron.ph%70',
  'encoded p in wp-mail.php' => '/wp-mail.ph%70',
  'encoded p in xmlrpc.php' => '/xmlrpc.ph%70',
  'encoded letter in wp-admin' => '/%77p-admin/',
  'double encoding' => '/wp%252Djson/',
  'double encoded slash' => '/a%252fb',
  'encoded control character' => '/a%0Ab',
  'rest.route' => '/?rest.route=/wp/v2/users',
  'rest+route' => '/?rest+route=/wp/v2/users',
  'rest[route' => '/?rest[route=/wp/v2/users',
  'encoded dot in key' => '/?rest%2Eroute=/wp/v2/users',
  'leading space in key' => '/?%20rest_route=/wp/v2/users',
  'gmcp.preview' => "/?p=3&gmcp.preview=$tok",
  'gmcp+preview' => "/?p=3&gmcp+preview=$tok",
  'gmcp[preview' => "/?p=3&gmcp[preview=$tok",
  'doing.wp.cron' => '/?doing.wp.cron=1',
  'remove.item' => '/?remove.item=abc',
  'undo.item' => '/?undo.item=abc',
  '.wpnonce' => '/?.wpnonce=abc',
  'array-valued action' => '/?action[]=x',
  'encoded action letter' => '/?%61ction=x',
] as $label => $tail ) {
  $r = $fetch( 'https://example.com' . $tail );
  check( "refused: $label", $r['ok'], false );
}
check( 'control: an ordinary page', $fetch( 'https://example.com/about/' )['ok'], true );
check( 'control: an encoded space is fine', $fetch( 'https://example.com/a%20b/' )['ok'], true );
check( 'control: an encoded UTF-8 letter is fine', $fetch( 'https://example.com/caf%C3%A9/' )['ok'], true );
check( 'control: index.php with a query', $fetch( 'https://example.com/index.php?p=3' )['ok'], true );
check( 'control: a key that merely contains a blocked word', $fetch( 'https://example.com/?actions=1&rest=2' )['ok'], true );
check( 'control: a dotted key that is not blocked', $fetch( 'https://example.com/?a.b=1' )['ok'], true );
check( 'control: a path that merely contains "wp-json" later', $fetch( 'https://example.com/blog/wp-json-guide/' )['ok'], true );

echo "-- query_key_variants (what PHP calls the key) --\n";
foreach ( [ 'rest.route', 'rest route', 'rest+route', 'rest[route', 'rest%2Eroute', ' rest_route' ] as $k ) {
  check( "variants of '$k' include rest_route", in_array( 'rest_route', GMCP_Diag_Util::query_key_variants( $k ), true ), true );
}
check( 'array base', in_array( 'action', GMCP_Diag_Util::query_key_variants( 'action[]' ), true ), true );
check( 'control: an unrelated key', in_array( 'rest_route', GMCP_Diag_Util::query_key_variants( 'resting' ), true ), false );

echo "-- scrub_preview_tokens --\n";
$sp = static function ( string $t ): string {
  return GMCP_Diag_Util::scrub_preview_tokens( $t );
};
foreach ( [
  'literal' => "https://x/?p=3&gmcp_preview=$tok",
  'dot' => "https://x/?p=3&gmcp.preview=$tok",
  'plus' => "https://x/?p=3&gmcp+preview=$tok",
  'space' => "https://x/?p=3&gmcp preview=$tok",
  'bracket' => "https://x/?p=3&gmcp[preview]=$tok",
  'encoded underscore' => "https://x/?p=3&gmcp%5Fpreview=$tok",
  'encoded dot' => "https://x/?p=3&gmcp%2Epreview=$tok",
  'encoded space' => "https://x/?p=3&gmcp%20preview=$tok",
  'encoded equals' => "https://x/?p=3&gmcp_preview%3D$tok",
  'encoded letter in the name' => "https://x/?p=3&gmcp_%70review=$tok",
  'upper case' => "https://x/?p=3&GMCP_PREVIEW=$tok",
] as $label => $text ) {
  $out = $sp( $text );
  check( "scrubbed: $label (token gone)", strpos( $out, $tok ) === false, true );
  check( "  ...and marked ($label)", strpos( $out, '[redacted]' ) !== false, true );
}
check( 'control: text with no preview key is untouched', $sp( "see https://x/?p=3&other=$tok" ), "see https://x/?p=3&other=$tok" );
check( 'control: the word preview alone is untouched', $sp( 'a preview of the page' ), 'a preview of the page' );
check( 'scrub_log_line applies it too', strpos( GMCP_Diag_Util::scrub_log_line( "404 /?gmcp.preview=$tok", [] ), $tok ) === false, true );

printf( "\n  %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
