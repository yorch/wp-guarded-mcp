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
// GMCP_UTIL points the test at a mutated copy when proving it fails without a fix.
require getenv( 'GMCP_UTIL' ) ?: __DIR__ . '/../includes/diagnostics-util.php';

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
// The HTTP library upper-cases percent escapes before its hooks run, so the string checked
// here has to be the string that is sent.
check( 'rebuilt URL: lowercase escapes in the path are upper-cased', $fetch( 'https://example.com/caf%c3%a9/' )['url'], 'https://example.com/caf%C3%A9/' );
check( 'rebuilt URL: ...and in the query', $fetch( 'https://example.com/?q=%c3%a9&r=%C3%A9&s=%e2%82%ac' )['url'], 'https://example.com/?q=%C3%A9&r=%C3%A9&s=%E2%82%AC' );
check( 'control: a URL with no escapes is unchanged by that', $fetch( 'https://example.com/a-b/?q=1' )['url'], 'https://example.com/a-b/?q=1' );

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

echo "-- resolve_entry (the CURLOPT_RESOLVE pin) --\n";
check( 'a name pinned to an IPv4 address', GMCP_Diag_Util::resolve_entry( 'example.com', 443, '93.184.216.34' ), 'example.com:443:93.184.216.34' );
check( 'a name pinned to an IPv6 address is bracketed', GMCP_Diag_Util::resolve_entry( 'example.com', 8080, '2606:2800:220:1:248:1893:25c8:1946' ), 'example.com:8080:[2606:2800:220:1:248:1893:25c8:1946]' );
check( 'a name pinned to ::1 is bracketed', GMCP_Diag_Util::resolve_entry( 'dev.test', 80, '::1' ), 'dev.test:80:[::1]' );
check( 'an IPv6 literal host has no entry (libcurl rejects "::1:80:[::1]")', GMCP_Diag_Util::resolve_entry( '::1', 80, '::1' ), null );
check( 'a bracketed IPv6 literal host has no entry', GMCP_Diag_Util::resolve_entry( '[::1]', 80, '::1' ), null );
check( 'an IPv4 literal host has no entry', GMCP_Diag_Util::resolve_entry( '127.0.0.1', 8223, '127.0.0.1' ), null );
check( 'control: a name that merely contains digits and dots still gets one', GMCP_Diag_Util::resolve_entry( 'a1.2b.test', 80, '10.0.0.1' ), 'a1.2b.test:80:10.0.0.1' );

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
// The file that is read is the one that was looked at.
file_put_contents( "$dir/real.log", "secret line\n" );
check( 'control: the target of the link reads fine on its own', GMCP_Diag_Util::read_tail( "$dir/real.log", 5, 100 )['lines'], [ 'secret line' ] );
check( 'control: lstat sees a regular file there', ( lstat( "$dir/real.log" )['mode'] & 0170000 ) === 0100000, true );
symlink( "$dir/real.log", "$dir/link.log" );
check( 'control: the link resolves to a readable file (a plain fopen would read it)', @file_get_contents( "$dir/link.log" ), "secret line\n" );
$t = GMCP_Diag_Util::read_tail( "$dir/link.log", 5, 100 );
check( 'a symbolic link is refused', $t['ok'], false );
check( '  ...and nothing was read through it', $t['lines'], [] );
if ( function_exists( 'posix_mkfifo' ) && @posix_mkfifo( "$dir/pipe.log", 0600 ) ) {
  $t0 = microtime( true );
  $t = GMCP_Diag_Util::read_tail( "$dir/pipe.log", 5, 100 );
  check( 'a FIFO is refused', $t['ok'], false );
  check( '  ...without blocking on it', microtime( true ) - $t0 < 2, true );
  unlink( "$dir/pipe.log" );
}
else {
  echo "  ....  FIFO case not run: posix_mkfifo is unavailable here\n";
}
$ls = lstat( "$dir/real.log" );
check( 'same_regular_file: control, the same file', GMCP_Diag_Util::same_regular_file( $ls, $ls ), true );
check( 'same_regular_file: another inode', GMCP_Diag_Util::same_regular_file( $ls, [ 'ino' => $ls['ino'] + 1 ] + $ls ), false );
check( 'same_regular_file: another device', GMCP_Diag_Util::same_regular_file( $ls, [ 'dev' => $ls['dev'] + 1 ] + $ls ), false );
check( 'same_regular_file: a link where a file was', GMCP_Diag_Util::same_regular_file( [ 'mode' => 0120777 ] + $ls, $ls ), false );
check( 'same_regular_file: a pipe was opened', GMCP_Diag_Util::same_regular_file( $ls, [ 'mode' => 0010600 ] + $ls ), false );
unlink( "$dir/link.log" );

echo "-- resolve_log, dir_chain_problem, open_verified, read_log --\n";
// (The race itself is .dev/stress-log-race.php. These are the pieces, one at a time.)
mkdir( "$dir/site/wp-content/logs", 0755, true );
mkdir( "$dir/elsewhere", 0755 );
file_put_contents( "$dir/site/wp-content/logs/debug.log", "alpha\nbeta\n" );
file_put_contents( "$dir/elsewhere/other.log", "gamma\n" );
$roots = [ "$dir/site/wp-content", "$dir/site" ];
$loc = GMCP_Diag_Util::resolve_log( "$dir/site/wp-content/logs/debug.log", $roots );
check( 'resolve_log: a path under the content directory is anchored to it', $loc['ok'] && $loc['below_root'], true );
check( '  ...expected path is the root plus the configured components', $loc['expected'], realpath( "$dir/site/wp-content" ) . '/logs/debug.log' );
check( '  ...and the one directory below the root is the one to check', $loc['dirs'], [ realpath( "$dir/site/wp-content" ) . '/logs' ] );
check( 'resolve_log: the longest root wins (content directory, not the install root)', strpos( $loc['expected'], realpath( "$dir/site/wp-content" ) ) === 0 && $loc['dirs'] === [ realpath( "$dir/site/wp-content" ) . '/logs' ], true );
check( 'resolve_log: ".." is refused', GMCP_Diag_Util::resolve_log( "$dir/site/wp-content/../wp-content/logs/debug.log", $roots )['ok'], false );
check( 'resolve_log: "." is refused', GMCP_Diag_Util::resolve_log( "$dir/site/wp-content/./logs/debug.log", $roots )['ok'], false );
check( 'resolve_log: a relative path is refused', GMCP_Diag_Util::resolve_log( 'logs/debug.log', $roots )['ok'], false );
check( 'resolve_log: ...and the refusal says it is a relative path and what to do', [ strpos( GMCP_Diag_Util::resolve_log( 'error_log', $roots )['error'], 'relative' ) !== false, strpos( GMCP_Diag_Util::resolve_log( 'error_log', $roots )['error'], 'absolute path' ) !== false ], [ true, true ] );
check( 'resolve_log: control: the same file as an absolute path under the content directory is accepted', GMCP_Diag_Util::resolve_log( "$dir/site/wp-content/error_log", $roots )['ok'], true );
check( 'resolve_log: a NUL byte is refused', GMCP_Diag_Util::resolve_log( "$dir/site/wp-content/logs/debug.log\0.txt", $roots )['ok'], false );
$out = GMCP_Diag_Util::resolve_log( "$dir/elsewhere/other.log", $roots );
check( 'resolve_log: a path outside both roots is canonicalised once, not anchored', $out['ok'] && !$out['below_root'], true );
check( '  ...expected is the canonical directory plus the name', $out['expected'], realpath( "$dir/elsewhere" ) . '/other.log' );
check( 'resolve_log: a missing directory outside the roots says so', GMCP_Diag_Util::resolve_log( "$dir/nowhere/x.log", $roots )['ok'], false );
check( 'dir_chain_problem: control, plain directories pass', GMCP_Diag_Util::dir_chain_problem( [ "$dir/site/wp-content/logs" ] )[0], null );
symlink( "$dir/elsewhere", "$dir/site/wp-content/linked" );
check( 'dir_chain_problem: a directory that is a link is refused', GMCP_Diag_Util::dir_chain_problem( [ "$dir/site/wp-content/linked" ] )[0] !== null, true );
check( 'dir_chain_problem: a component that is a file is refused', GMCP_Diag_Util::dir_chain_problem( [ "$dir/site/wp-content/logs/debug.log" ] )[0] !== null, true );
check( 'dir_chain_problem: a missing component is refused', GMCP_Diag_Util::dir_chain_problem( [ "$dir/site/wp-content/gone" ] )[0] !== null, true );
unlink( "$dir/site/wp-content/linked" );
chmod( "$dir/elsewhere", 0777 );
check( 'dir_chain_problem: a world-writable directory without the sticky bit is reported, not refused', [ GMCP_Diag_Util::dir_chain_problem( [ "$dir/elsewhere" ], 12345, [] )[0], count( GMCP_Diag_Util::dir_chain_problem( [ "$dir/elsewhere" ], 12345, [] )[1] ) ], [ null, 1 ] );
chmod( "$dir/elsewhere", 01777 );
check( 'dir_chain_problem: control, the sticky bit (as /tmp has it) is not reported', GMCP_Diag_Util::dir_chain_problem( [ "$dir/elsewhere" ], 12345, [] )[1], [] );
chmod( "$dir/elsewhere", 0755 );
check( 'dir_chain_problem: control, a private directory is not reported', GMCP_Diag_Util::dir_chain_problem( [ "$dir/elsewhere" ], 12345, [] )[1], [] );
$v = GMCP_Diag_Util::open_verified( "$dir/site/wp-content/logs/debug.log", $loc['expected'] );
check( 'open_verified: the real file opens at its expected path', is_resource( $v['fh'] ), true );
if ( is_resource( $v['fh'] ) ) {
  check( '  ...and nothing was read yet (the handle is positioned at the start)', ftell( $v['fh'] ), 0 );
  fclose( $v['fh'] );
}
$v = GMCP_Diag_Util::open_verified( "$dir/site/wp-content/logs/debug.log", "$dir/elsewhere/debug.log" );
check( 'open_verified: a file that is not at the expected path is refused', $v['fh'], null );
$full = GMCP_Diag_Util::read_log( "$dir/site/wp-content/logs/debug.log", $roots, 10, 1000, 12345, [] );
check( 'read_log: control, reads the lines', $full['lines'], [ 'alpha', 'beta' ] );
check( 'read_log: and reports the path the kernel must see', $full['expected'], $loc['expected'] );
rename( "$dir/site/wp-content/logs", "$dir/site/wp-content/logs.hold" );
symlink( "$dir/elsewhere", "$dir/site/wp-content/logs" );
file_put_contents( "$dir/elsewhere/debug.log", "CANARY\n" );
$full = GMCP_Diag_Util::read_log( "$dir/site/wp-content/logs/debug.log", $roots, 10, 1000, 12345, [] );
check( 'read_log: control, the swapped directory really does contain a readable debug.log', @file_get_contents( "$dir/site/wp-content/logs/debug.log" ), "CANARY\n" );
check( 'read_log: a directory below the root swapped for a link is refused', $full['ok'], false );
check( '  ...and nothing of the other file is returned', strpos( implode( '', $full['lines'] ) . $full['error'], 'CANARY' ), false );
unlink( "$dir/site/wp-content/logs" );
rename( "$dir/site/wp-content/logs.hold", "$dir/site/wp-content/logs" );

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
  [ 'define() of a password constant', "PHP Notice: in wp-config.php: define( 'DB_PASSWORD', 'Sup3rS3cretDbPw' );", 'Sup3rS3cretDbPw', "define( 'DB_PASSWORD'," ],
  [ 'define() with double quotes and no spaces', 'define("AUTH_KEY","pU7vXyZ0ab9Qq1RrTt");', 'pU7vXyZ0ab9Qq1RrTt', 'define(' ],
  [ 'pw= in a URL', 'GET /connect?user=bob&pw=correcthorse&x=1', 'correcthorse', 'user=bob' ],
  [ 'pw: in a dump', 'login attempt pw: "my pw value" for bob', 'my pw value', 'login attempt' ],
  [ 'AWS access key id', 'S3 client built with AKIAIOSFODNN7EXAMPLE for bucket x', 'AKIAIOSFODNN7EXAMPLE', 'for bucket x' ],
  [ 'AWS secret access key, bare', 'signing with wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY failed', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'failed' ],
  [ 'AWS secret by name', 'aws_secret_access_key = wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'bPxRfiCYEXAMPLEKEY', 'aws_secret_access_key' ],
  [ 'SQL values', 'WordPress database error Duplicate entry for query INSERT INTO wp_users (user_login) VALUES (\'bob\') ' . str_repeat( 'x', 140 ) . ' SECRET_TAIL_VALUE', 'SECRET_TAIL_VALUE', 'INSERT INTO wp_users' ],
];
foreach ( $cases as [ $label, $in, $gone, $kept ] ) {
  $out = $s( $in );
  check( "removed: $label", $has( $out, $gone ), false );
  check( "  ...rest of the line kept ($label)", $has( $out, $kept ), true );
  check( "  ...control: the probe sees it in the input ($label)", $has( $in, $gone ), true );
}

echo "-- scrub_log_line: cookies, escaped JSON, DSNs, vendor keys, private keys --\n";
$more = [
  [ 'Cookie header', 'curl sent Cookie: wordpress_logged_in_5d41402abc4b2a76b9719d911017c592=admin%7C1699999999%7CAbCdEf0123%7Cdeadbeef; wp-settings-1=mfold%3Do', 'AbCdEf0123', 'curl sent Cookie:' ],
  [ 'Cookie header: the user name in it too', 'curl sent Cookie: wordpress_logged_in_5d41402abc4b2a76b9719d911017c592=zoeadmin%7C1699999999%7CT0k3n%7Chmac', 'zoeadmin', 'curl sent' ],
  [ 'Set-Cookie header', 'response Set-Cookie: wordpress_sec_5d41402abc4b2a76b9719d911017c592=admin|1699999999|TokenValueHere|hmacvalue; path=/wp-admin; HttpOnly', 'TokenValueHere', 'response Set-Cookie:' ],
  [ 'cookie in a dump of $_COOKIE', '[wordpress_logged_in_5d41402abc4b2a76b9719d911017c592] => admin|1699999999|TokenValueHere|hmacvalue', 'TokenValueHere', '=>' ],
  [ 'a short WordPress cookie name, k=v', 'COOKIE wordpress_logged_in_abc=admin%7C123%7CZZZtok%7Chmac1 seen', 'ZZZtok', 'COOKIE' ],
  [ 'PHP session id', 'request cookie PHPSESSID=0123456789abcdef0123456789abcdef done', '0123456789abcdef0123456789abcdef', 'done' ],
  [ 'post password cookie', 'seen wp-postpass_abc123=%24P%24Bxyz done', 'Bxyz', 'done' ],
  [ 'commenter e-mail cookie', 'seen comment_author_email_5d41402abc4b2a76b9719d911017c592=bob%40customer-domain.org done', 'customer-domain', 'done' ],
  [ 'passwd=', 'GET /x?passwd=hunter2pw&a=1', 'hunter2pw', 'a=1' ],
  [ 'pwd= from a login form', 'POST /wp-login.php log=bob&pwd=correcthorsebattery&wp-submit=Log+In', 'correcthorsebattery', 'wp-submit' ],
  [ 'password in escaped JSON', 'body {\"password\":\"hunter2pw\",\"user\":\"bob\"}', 'hunter2pw', 'bob' ],
  [ 'secret in escaped JSON, a value with spaces', 'body {\"client_secret\":\"two words here\",\"x\":1}', 'two words here', '\"x\":1' ],
  [ 'secret in escaped JSON, a number', 'body {\"secret\":99887766,\"x\":1}', '99887766', '\"x\":1' ],
  [ 'redis DSN with an empty user', 'cannot connect redis://:r3d1sPassw0rd@cache.internal:6379/0', 'r3d1sPassw0rd', 'cache.internal' ],
  [ 'mysql DSN', 'PDOException mysql://app:Pa55w0rd@db.internal:3306/wp', 'Pa55w0rd', 'db.internal:3306/wp' ],
  [ 'DSN whose password contains @', 'dsn mysql://app:p4@ssw0rd@db.internal/wp', 'ssw0rd', 'db.internal/wp' ],
  [ 'amqp DSN', 'amqp://guest:gu3stPw@rabbit:5672/', 'gu3stPw', 'rabbit:5672' ],
  [ 'a host before @ is not the host', 'redirect https://example.com@evil.example/path', 'example.com@', 'evil.example/path' ],
  [ 'Stripe secret key', 'Stripe error with key ' . 'sk_' . 'live_abcdefghijklmnopqrstuvwx', 'sk_live_abcdef', 'Stripe error with key' ],
  [ 'Stripe webhook secret', 'verify failed whsec_abcdefghijklmnopqrstuvwxyz', 'whsec_abcdef', 'verify failed' ],
  [ 'GitHub token', 'push with ghp_abcdefghijklmnopqrstuvwxyz0123456789 failed', 'ghp_abcdef', 'push with' ],
  [ 'Google API key', 'GET https://maps.googleapis.com/maps/api/js?x=1 AIzaSyA-abcdefghijklmnopqrstuvwxyz012345 denied', 'AIzaSyA-abcdef', 'maps/api/js?x=1' ],
  [ 'Slack token', 'webhook xoxb-1234567890-abcdefghijkl used', 'xoxb-1234567890', 'webhook' ],
  [ 'GitLab token', 'clone with glpat-abcdefghij0123456789 refused', 'glpat-abcdefghij', 'refused' ],
  [ 'Mailgun key', 'mailgun auth ' . 'key-' . '0123456789abcdef0123456789abcdef' . ' bad', 'key-0123456789abcdef', 'bad' ],
  [ 'SendGrid key', 'smtp login SG.abcdefghijklmnop.qrstuvwxyz012345 refused', 'SG.abcdefghijklmnop', 'refused' ],
  [ 'a private key on one line (escaped newlines)', 'config key=-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSj\n-----END PRIVATE KEY----- tail', 'MIIEvQIBADANBgkq', 'tail' ],
  [ 'a private key cut off before its end marker', 'config -----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun', 'MIIEowIBAAKC', 'config' ],
  [ 'the first line of a multi-line private key', '-----BEGIN OPENSSH PRIVATE KEY-----', 'OPENSSH PRIVATE KEY', '[private-key-block]' ],
  [ 'a base64 line of a key printed over several lines, with slashes', 'VaYf7yG5uQ9o/ZqWZbq3Fv4sPzRtL8mNoPqRsTuVwXyZ0123456789abcdefghiJ', 'VaYf7yG5uQ9o', 'base64 line redacted' ],
  [ 'a base64 line with letters only', 'abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz', 'abcdefghijklmnopqrstuvwxyzabcdefghijkl', 'base64 line redacted' ],
];
foreach ( $more as [ $label, $in, $gone, $kept ] ) {
  $out = $s( $in );
  check( "removed: $label", $has( $out, $gone ), false );
  check( "  ...rest of the line kept ($label)", $has( $out, $kept ), true );
  check( "  ...control: the probe sees it in the input ($label)", $has( $in, $gone ), true );
}

echo "-- scrub_log_lines: a private key over several lines --\n";
$pem = [
  'PHP Notice:  key material follows',
  '-----BEGIN PRIVATE KEY-----',
  'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj',
  'short/line+with=chars',
  '-----END PRIVATE KEY-----',
  'PHP Notice:  after the key',
];
$o = GMCP_Diag_Util::scrub_log_lines( $pem, $ctx );
check( 'same number of lines out as in', count( $o ), count( $pem ) );
check( 'no body line survives', $has( implode( "\n", $o ), 'short/line' ) || $has( implode( "\n", $o ), 'MIIEvQ' ), false );
check( 'control: the probe sees the body in the input', $has( implode( "\n", $pem ), 'short/line' ), true );
check( 'the line before is kept', $o[0], 'PHP Notice:  key material follows' );
check( 'the line after is kept', $o[5], 'PHP Notice:  after the key' );
$pem_cut = [ '-----BEGIN RSA PRIVATE KEY-----', 'abc/def+ghi', '[04-Nov-2026 22:36:32 UTC] PHP Warning:  next record', 'ordinary line' ];
$o = GMCP_Diag_Util::scrub_log_lines( $pem_cut, $ctx );
check( 'a key with no end marker ends at the next log record', [ $has( $o[1], 'abc/def' ), $o[2], $o[3] ], [ false, '[04-Nov-2026 22:36:32 UTC] PHP Warning:  next record', 'ordinary line' ] );
$o = GMCP_Diag_Util::scrub_log_lines( array_merge( [ '-----BEGIN PRIVATE KEY-----' ], array_fill( 0, 150, 'x/y+z' ), [ 'plain after' ] ), $ctx );
check( 'a key with no end marker and no new record cannot swallow more than 100 lines', end( $o ), 'plain after' );
check( 'control: a certificate (public) is not withheld', GMCP_Diag_Util::scrub_log_lines( [ '-----BEGIN CERTIFICATE-----', 'short', '-----END CERTIFICATE-----' ], $ctx )[1], 'short' );

echo "-- scrub_log_line: shapes a log can print a secret in that carry no 'name=value' --\n";
// Each secret below came back unchanged before its rule existed. Each has a harmless line next to it
// that the same rule must leave alone, and a control that the probe sees the secret in the input.
$shapes = [
  'PHP serialized string' => [ 's:9:"user_pass";s:7:"hunter2xq";', 'hunter2xq' ],
  'serialized inside an array' => [ 'a:2:{s:5:"login";s:5:"admin";s:8:"password";s:9:"hunter2xq";}', 'hunter2xq' ],
  'serialized with an escaped quote in the value' => [ 's:8:"password";s:9:"hun\\"ter2xq";', 'ter2xq' ],
  'element named like a secret' => [ '<Password>hunter2xq</Password>', 'hunter2xq' ],
  'element with an attribute' => [ '<db_password type="string">hunter2xq</db_password>', 'hunter2xq' ],
  'mysqldump -pPASSWORD' => [ 'mysqldump -u root -phunter2xq wordpress > /tmp/x.sql', 'hunter2xq' ],
  'mysql -pPASSWORD quoted' => [ "mysql -h db -u wp -p'hunter2xq' wordpress", 'hunter2xq' ],
  '--password VALUE' => [ 'mysqldump --user=root --password hunter2xq db', 'hunter2xq' ],
  '--password=VALUE' => [ 'mysqldump --user=root --password=hunter2xq db', 'hunter2xq' ],
  '--api-key VALUE' => [ 'tool --api-key hunter2xq --verbose', 'hunter2xq' ],
  'URL-encoded token' => [ 'token%3Dhunter2xq', 'hunter2xq' ],
  'URL-encoded password after an encoded ampersand' => [ 'a=1%26password%3Dhunter2xq%26b=2', 'hunter2xq' ],
  'double-encoded password' => [ 'password%253Dhunter2xq', 'hunter2xq' ],
  'Azure sig, percent-encoded value' => [ 'SharedAccessSignature=sv=2020-08-04&sig=Hunter2xQ%2Bef&se=2030', 'Hunter2xQ' ],
  'Azure sig, in a URL' => [ 'GET https://acct.blob.core.windows.net/c/b?sv=2020&sig=Hunter2xQ%2Fef%3D&sp=r', 'Hunter2xQ' ],
];
foreach ( $shapes as $label => [ $line, $secret ] ) {
  check( "control: the probe sees the secret in the input ($label)", $has( $line, $secret ), true );
  $out = GMCP_Diag_Util::scrub_log_line( $line, $ctx );
  check( "removed: $label", $has( $out, $secret ), false );
  check( "  ...and not by emptying the line ($label)", $out !== '' && preg_last_error() === PREG_NO_ERROR, true );
}
check( 'the fields around a serialized secret survive', GMCP_Diag_Util::scrub_log_line( 'a:2:{s:5:"login";s:5:"admin";s:8:"password";s:9:"hunter2xq";}', $ctx ), 'a:2:{s:5:"login";s:5:"admin";s:8:"password";s:10:"[redacted]";}' );
check( 'the next URL-encoded field survives', GMCP_Diag_Util::scrub_log_line( 'a=1%26password%3Dhunter2xq%26b=2', $ctx ), 'a=1%26password%3D[redacted]%26b=2' );
check( 'the Azure fields around the signature survive', GMCP_Diag_Util::scrub_log_line( 'x?sv=2020&sig=Hunter2xQ%2Bef&se=2030', $ctx ), 'x?sv=2020&sig=[redacted]&se=2030' );
foreach ( [
  '<Passwords count="3"/>',
  '<Passwords>',
  '<Password/>',
  '<Pass>',
  '<note>the password field is empty</note>',
  '<div class="x">plain text</div>',
  's:5:"login";s:5:"admin";',
  's:4:"city";s:4:"Oslo";',
  'mysqldump -u root -p wordpress',
  'mysqldump -P3306 -h db wordpress',
  'ls -plan and tar -pxf a.tar',
  'rsync -p --password-file /etc/rsync.pass src dst',
  'tool --keyfile /tmp/k --no-token-check yes',
  'tokenizer%20extension%20ok',
  'sig_handler installed',
  'signal 11 and design%3Dfinal',
  'a%3Db and 100%25 done and %26amp',
] as $line ) {
  check( 'unchanged: ' . $line, GMCP_Diag_Util::scrub_log_line( $line, $ctx ), $line );
}

echo "-- scrub_log_lines: a value printed on the line after its name --\n";
$dump = [ 'array(2) {', '  ["login"]=>', '  string(5) "admin"', '  ["password"]=>', '  string(9) "hunter2xq"', '}' ];
$o = GMCP_Diag_Util::scrub_log_lines( $dump, $ctx );
check( 'control: the probe sees the secret in the input', $has( implode( "\n", $dump ), 'hunter2xq' ), true );
check( 'var_dump: the value after a secret name is withheld', $has( implode( "\n", $o ), 'hunter2xq' ), false );
check( 'var_dump: the name line, the other field and the structure stay', [ $o[0], $o[1], $o[2], $o[3], $o[5] ], [ 'array(2) {', '  ["login"]=>', '  string(5) "admin"', '  ["password"]=>', '}' ] );
check( 'var_dump: the same number of lines', count( $o ), count( $dump ) );
$o = GMCP_Diag_Util::scrub_log_lines( [ "  'db_password' =>", "  'hunter2xq',", "  'login' =>", "  'admin'," ], $ctx );
check( 'var_export: the value after a secret name is withheld, the other field is not', [ $has( implode( "\n", $o ), 'hunter2xq' ), $o[3] ], [ false, "  'admin'," ] );
$o = GMCP_Diag_Util::scrub_log_lines( [ '  [secret_options] =>', '  Array', '  (', '      [login] => admin', '  )' ], $ctx );
check( 'a secret name followed by an array: the structure is kept and its members judged by their own names', array_slice( $o, 1 ), [ '  Array', '  (', '      [login] => admin', '  )' ] );
$o = GMCP_Diag_Util::scrub_log_lines( [ '  ["password"]=>', '[04-Nov-2026 22:36:32 UTC] PHP Warning:  next record' ], $ctx );
check( 'a name at the end of the window does not swallow the next record', $o[1], '[04-Nov-2026 22:36:32 UTC] PHP Warning:  next record' );
$o = GMCP_Diag_Util::scrub_log_lines( [ 'ordinary line =>', 'following line' ], $ctx );
check( 'control: a line ending in => whose name is not secret withholds nothing', $o[1], 'following line' );

echo "-- scrub_log_lines: a key block that closes and opens on one line --\n";
$two = [ 'x -----BEGIN PRIVATE KEY-----AAAA-----END PRIVATE KEY----- y -----BEGIN PRIVATE KEY-----', 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcw', 'short/body+line', '-----END PRIVATE KEY-----', 'after' ];
$o = GMCP_Diag_Util::scrub_log_lines( $two, $ctx );
check( 'control: the probe sees the body in the input', $has( implode( "\n", $two ), 'short/body' ), true );
check( 'the lines of the second block are withheld', $has( implode( "\n", $o ), 'short/body' ) || $has( implode( "\n", $o ), 'MIIEvQ' ), false );
check( 'the line after the end marker is kept', end( $o ), 'after' );
$one = [ '-----BEGIN PRIVATE KEY-----AAAA-----END PRIVATE KEY----- then more', 'ordinary next line' ];
check( 'control: a block that closes on its own line does not withhold the next line', GMCP_Diag_Util::scrub_log_lines( $one, $ctx )[1], 'ordinary next line' );

echo "-- scrub_log_line: pathological 5000-character lines (a pattern that backtracks shows here) --\n";
$bad = [
  'letters' => str_repeat( 'a', 5000 ),
  'scheme repeats' => str_repeat( 'a://', 1250 ),
  'scheme then no @' => 'a://' . str_repeat( 'x', 4990 ),
  'at signs' => str_repeat( '@', 5000 ),
  'scheme then at signs' => 'a://' . str_repeat( '@', 4990 ),
  'password= repeats' => str_repeat( 'password=', 550 ),
  'escaped JSON, unterminated' => str_repeat( '\"secret\":\"', 450 ),
  'escaped JSON, no closing' => '\"secret\":\"' . str_repeat( 'a', 4980 ),
  'key then no value' => str_repeat( 'secret ', 700 ),
  'BEGIN repeats' => str_repeat( '-----BEGIN PRIVATE KEY-----', 185 ),
  'BEGIN then text' => '-----BEGIN PRIVATE KEY-----' . str_repeat( 'x', 4960 ),
  'Cookie: repeats' => str_repeat( 'Cookie:', 700 ),
  'cookie names repeat' => str_repeat( 'wordpress_logged_in_', 250 ),
  'PHPSESSID repeats' => str_repeat( 'PHPSESSID=', 500 ),
  'colons' => str_repeat( ':', 5000 ),
  'dots and digits' => str_repeat( '1.', 2500 ),
  'quotes' => str_repeat( '"', 5000 ),
  'backslashes' => str_repeat( '\\', 5000 ),
  'vendor prefixes' => str_repeat( 'sk_live_', 600 ),
  'base64 almost' => str_repeat( 'A', 4999 ) . '!',
  'Authorization repeats' => str_repeat( 'Authorization:', 350 ),
  'brackets' => str_repeat( '[a]', 1600 ),
  'serialized prefixes' => str_repeat( 's:5:"pass', 500 ),
  'serialized names, no value' => str_repeat( 's:9:"user_pass";', 300 ),
  'serialized, open string' => 's:9:"password";s:7:"' . str_repeat( 'a', 4970 ),
  'elements open' => str_repeat( '<password>', 500 ),
  'elements, a long name' => '<' . str_repeat( 'pass', 1250 ) . '>x</a>',
  'elements, no close' => '<password>' . str_repeat( 'x', 4980 ),
  'elements, many tags' => str_repeat( '<a>', 1600 ),
  'encoded names' => str_repeat( 'token%3D', 600 ),
  'encoded percent signs' => str_repeat( '%25', 1600 ),
  'encoded name, long value' => 'password%3D' . str_repeat( 'a%2B', 1600 ),
  'option repeats' => str_repeat( '--password ', 450 ),
  'option, long value' => '--password ' . str_repeat( 'x', 4980 ),
  'mysql repeats' => str_repeat( 'mysql -', 700 ),
  'mysql, long tail' => 'mysql ' . str_repeat( 'x ', 2490 ),
  'mysql words, no -p' => str_repeat( 'mysql ', 800 ),
  'sig repeats' => str_repeat( 'sig=', 1250 ),
  'sig, long encoded value' => 'sig=' . str_repeat( 'a%2B', 1200 ),
];
$worst = 0.0;
foreach ( $bad as $label => $line ) {
  $t0 = microtime( true );
  $r = GMCP_Diag_Util::scrub_log_line( $line, $ctx );
  $dt = microtime( true ) - $t0;
  $worst = max( $worst, $dt );
  // A regular expression that gives up (backtrack limit) makes preg_replace() return null, which the
  // scrubber casts to an empty line: quick, and a silent failure. So speed alone proves nothing.
  check( sprintf( 'pathological line "%s" (%d chars) scrubs in under 0.5 s', $label, strlen( $line ), $dt ), $dt < 0.5 && is_string( $r ), true );
  check( '  ...and no regular expression gave up on it (no PCRE error, a non-empty result)', preg_last_error() === PREG_NO_ERROR && $r !== '', true );
}
printf( "        slowest of %d pathological lines: %.1f ms\n", count( $bad ), $worst * 1000 );
// The PCRE JIT hides a quadratic pattern (it is the default on most builds) and some hosts run
// without it, so the same kind of lines are timed in a child process started with it off (a
// pattern already compiled with the JIT stays compiled with it, so ini_set() here would change
// nothing). Before the name-based rule was anchored to the start of a run of name characters,
// each of these took about 0.3 s.
$jit_lines = [ str_repeat( 'a_', 2500 ), str_repeat( '[a]', 1600 ), str_repeat( 'wordpress_logged_in_', 250 ), str_repeat( 'a', 5000 ), 'a://' . str_repeat( 'x', 4990 ) ];
foreach ( $bad as $label => $line ) {
  // The new rules, at the length they see (the scrubber cuts at 2000) and at 5000.
  if ( preg_match( '/serialized|element|encoded|option|mysql|sig/', $label ) ) {
    $jit_lines[] = substr( $line, 0, 2000 );
    $jit_lines[] = $line;
  }
}
$child_code = 'define( "ABSPATH", "/" ); require $argv[1]; $worst = 0; $empty = 0; $n = 0;'
  . ' foreach ( json_decode( (string) file_get_contents( $argv[2] ), true ) as $l ) { $n++;'
  . ' $t0 = microtime( true ); $r = GMCP_Diag_Util::scrub_log_line( $l, [ "patterns" => [ "pass", "pwd", "secret", "token", "key", "auth", "salt", "nonce", "credential", "bearer", "signature", "licence", "license", "private" ] ] );'
  . ' $worst = max( $worst, microtime( true ) - $t0 ); if ( $r === "" || preg_last_error() !== PREG_NO_ERROR ) { $empty++; } }'
  . ' echo json_encode( [ ini_get( "pcre.jit" ), $worst, $empty, $n ] );';
$util_path = getenv( 'GMCP_UTIL' ) ?: __DIR__ . '/../includes/diagnostics-util.php';
$jit_file = tempnam( sys_get_temp_dir(), 'gmcp-jit' );
file_put_contents( $jit_file, (string) json_encode( $jit_lines ) );
$child_out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -r ' . escapeshellarg( $child_code ) . ' ' . escapeshellarg( $util_path ) . ' ' . escapeshellarg( $jit_file ) . ' 2>&1' );
unlink( $jit_file );
$child = json_decode( $child_out, true );
check( 'JIT-off child ran with the JIT off', is_array( $child ) ? (string) $child[0] : $child_out, '0' );
check( 'JIT off: the child scrubbed every line it was given (' . count( $jit_lines ) . ')', is_array( $child ) ? $child[3] : -1, count( $jit_lines ) );
check( 'JIT off: every pathological line, the new rules at 2000 and 5000 characters included, scrubs in under 0.1 s (the slowest of them)', is_array( $child ) && $child[1] < 0.1, true );
check( 'JIT off: and none was emptied, or hit a PCRE error, by a regular expression giving up', is_array( $child ) ? $child[2] : -1, 0 );
printf( "        slowest with the JIT off: %.1f ms\n", is_array( $child ) ? $child[1] * 1000 : -1 );
// scrub_log_line() cuts a line at 2000 characters before any rule runs, so the time above is the
// time for 2000 characters. Time the rules with the cut taken away from their input as well: the
// same lines at 2000 characters are what they actually see.
$lines5k = [];
for ( $i = 0; $i < 40; $i++ ) {
  $lines5k[] = str_repeat( 'password=a ', 180 );
}
$t0 = microtime( true );
GMCP_Diag_Util::scrub_log_lines( $lines5k, $ctx );
check( '40 long lines of repeated credentials scrub in under 2 s', microtime( true ) - $t0 < 2, true );

echo "-- scrub_log_line: left alone --\n";
$keep = [
  'PHP Warning:  Undefined array key "foo" in [content]/plugins/x/y.php on line 12',
  'PHP Notice:  Function _load_textdomain_just_in_time was called incorrectly.',
  'cURL error 7: Failed to connect to 127.0.0.1 port 8080',
  'connect to 10.0.0.5 port 3306 failed',
  'Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
  '[04-Nov-2026 22:36:32 UTC] PHP Deprecated:  Creation of dynamic property is deprecated',
  'Call to undefined function some_very_long_but_ordinary_function_name_without_digits()',
  // The new shapes must not eat ordinary text: "pw" is a whole word only, and a define() of something that is not a credential is just a notice.
  'power=5 and pwa=1 and showpw=2 were set',
  "define( 'WP_DEBUG', true ); define( 'WP_CONTENT_URL', 'https://x.test/c' );",
  // New shapes must not eat ordinary text either.
  'Cookie policy page loaded and set cookie banner to dismissed',
  'wordpress_test_cookie=WP%20Cookie%20check',
  'seen cookie wp-settings-1=mfold%3Do',
  'fetched https://example.com/blog/post?id=3&ref=a@b and moved on',
  'GET http://example.com:8080/a/b@c/d HTTP/1.1',
  'Using skeleton_live_view and task_key_ready and ghost_writer_enabled',
  'The SG.1 and SG.2 labels were shown; key-value pairs follow',
  'BEGIN CERTIFICATE rotation done; the private key was not printed',
  'a/b/c/some/long/path/with/segments/that/is/not/base64/at/all/and/has/spaces too',
  '{\"user\":\"bob\",\"city\":\"Oslo\"}',
  'the file was 0123456789abcdef.jpg and a_long_but_plain_identifier_with_no_digits_in_it_at_all_x',
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

// The upload and export endpoints hand out a token in the path for the same reason the
// download one does, and their replies are copied into the same audit log. Each check has
// a control on a nearby ordinary URL, so "the token is gone" cannot be the scrubber
// replacing the whole string.
foreach ( [
  'upload' => "https://x/wp-json/mcp/v1/upload/$tok",
  'export' => "https://x/wp-json/mcp/v1/export/$tok",
  'download' => "https://x/wp-json/mcp/v1/download/$tok",
] as $route => $text ) {
  $out = $sp( $text );
  check( "scrubbed: a $route token (token gone)", strpos( $out, $tok ) === false, true );
  check( "  ...and the route is kept ($route)", strpos( $out, "/$route/[redacted]" ) !== false, true );
}
check( 'control: an ordinary URL is untouched', $sp( 'https://x/wp-json/mcp/v1/http' ), 'https://x/wp-json/mcp/v1/http' );
check( 'control: a token-shaped word is untouched', $sp( 'upload your files here' ), 'upload your files here' );
// A tool reply is JSON, and wp_json_encode escapes the slashes. Scrubbing only the plain
// spelling left the token in the log while the unit test, written from the same reading of
// the code as the code under test, agreed with it.
foreach ( [ 'upload', 'download', 'export' ] as $route ) {
  $escaped = 'http:\/\/x\/wp-json\/mcp\/v1\/' . $route . '\/' . $tok;
  $out = $sp( $escaped );
  check( "scrubbed: a JSON-escaped $route token (token gone)", strpos( $out, $tok ) === false, true );
  check( "  ...and the escaped route is kept ($route)", strpos( $out, '\\/' . $route . '\\/[redacted]' ) !== false, true );
}

echo "-- scrub_log_line: shapes found missing in review --\n";
$sec = 'Zx9Qw3Lp7Rt2';
$rev = [
  'an escaped quote inside a define() value'  => [ "define('DB_PASSWORD', 'ab\\'cdef12345');", 'cdef12345', "define('DB_PASSWORD', [redacted]);" ],
  'an escaped quote inside a quoted value'    => [ "smtp_pass = 'ab\\'cdef12345' tail", 'cdef12345', 'smtp_pass = [redacted] tail' ],
  'a double-quoted value with an escaped quote' => [ 'smtp_pass = "ab\\"cdef12345" tail', 'cdef12345', 'smtp_pass = [redacted] tail' ],
  'a CDATA element'                           => [ "<password><![CDATA[Zx9<Qw3>Lp7]]></password>", 'Lp7', '<password>[redacted]</password>' ],
  'a CDATA element with attributes'           => [ "<api_key id=\"1\"><![CDATA[$sec]]></api_key>", $sec, '<api_key id="1">[redacted]</api_key>' ],
  'JSON in a URL, percent-encoded'            => [ "GET /x?data=%7B%22access_token%22%3A%22$sec%22%2C%22b%22%3A1%7D", $sec, 'GET /x?data=%7B%22access_token%22%3A%22[redacted]%22%2C%22b%22%3A1%7D' ],
  'curly double quotes around a value'        => [ "password: “Zx9 Qw3 Lp7” tail", 'Qw3', 'password: [redacted] tail' ],
  'curly single quotes around a value'        => [ "password = ‘Zx9 Qw3 Lp7’ tail", 'Qw3', 'password = [redacted] tail' ],
  'curly quotes around the name too'          => [ "“password”: “Zx9 Qw3”", 'Qw3', '“password”: [redacted]' ],
  'a fullwidth equals sign'                   => [ "password＝{$sec} next", $sec, 'password＝[redacted] next' ],
  'a fullwidth colon'                         => [ "password：{$sec} next", $sec, 'password：[redacted] next' ],
  'pw with a fullwidth colon'                 => [ "pw：{$sec} next", $sec, 'pw：[redacted] next' ],
  'Authorization: Token'                      => [ "Authorization: Token {$sec}abc", $sec, 'Authorization: [redacted]' ],
  'a key whose value is a one-line private key' => [ 'key: -----' . 'BEGIN OPENSSH PRIVATE KEY----- abc -----END OPENSSH PRIVATE KEY-----', 'abc', 'key: [private-key-block]' ],
];
foreach ( $rev as $label => [ $text, $leak, $want ] ) {
  check( "control: the $label probe holds what must go", strpos( $text, $leak ) !== false, true );
  $out = GMCP_Diag_Util::scrub_log_line( $text, [] );
  check( "scrubbed: $label", $out, $want );
  check( "stable: $label is unchanged by a second pass", GMCP_Diag_Util::scrub_log_line( $out, [] ), $out );
}
check( 'control: curly quotes round a harmless value are kept', GMCP_Diag_Util::scrub_log_line( 'title: “Hello there” and more', [] ), 'title: “Hello there” and more' );
check( 'control: an element not named like a secret keeps its CDATA', GMCP_Diag_Util::scrub_log_line( '<title><![CDATA[Hello]]></title>', [] ), '<title><![CDATA[Hello]]></title>' );

printf( "\n  %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
