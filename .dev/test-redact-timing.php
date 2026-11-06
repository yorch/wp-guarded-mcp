<?php
/**
* How long the credential scrubs take on text an agent controls, and that what they return is
* still the text, with the PCRE JIT on and off.
*
* Run from anywhere:  php .dev/test-redact-timing.php
* Exit status is the number of failed checks, capped at 255. Not shipped. Needs no WordPress.
*
* WHY THIS EXISTS. redact() runs the assignment scrub over every string in every tool call's
* arguments, from gmcp_tool_called, after the tool has already run. That expression had a
* leading name class with no lookbehind and no possessive quantifier, so it was quadratic: with
* the JIT on, "pass" repeated to 64 KB took 0.45 s and to 512 KB 31 s; with it off, 16 KB of
* "a" took 20 s and 16 KB of "pass" over three minutes. An argument of that size, which is a post
* body and not an attack, made the process hit max_execution_time, so the tool's effect was
* stored and the audit row never was. A regex that gives up is the other half: preg_replace()
* returns null, a cast makes that "", and the argument was recorded as an empty string, a false
* record. So both are asserted, and neither alone is enough: a scrub that returns "" in a
* millisecond looks fast.
*
* The test runs itself twice in child processes, one with pcre.jit=0 and one with pcre.jit=1,
* because the setting belongs to the process and the JIT-off build is the slow one.
*/

if ( ( $argv[1] ?? '' ) === '--child' ) {
  child();
  exit( 0 );
}

$php = PHP_BINARY;
$self = __FILE__;
$fails = 0;
$total = 0;
function check( string $label, $got, $want ): void {
  global $fails, $total;
  $total++;
  if ( $got === $want ) {
    echo "ok   $label\n";
    return;
  }
  $fails++;
  echo "FAIL $label\n     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n";
}

/**
* Runs the child with a wall-clock limit and returns [ output lines, whether it was killed ].
* A scrub that never finishes must read as a failure with a name, not as a hung test run.
*/
function run_child( string $php, string $self, int $jit, int $limit ): array {
  $cmd = escapeshellarg( $php ) . ' -d pcre.jit=' . $jit . ' -d pcre.backtrack_limit=1000000 -d max_execution_time=0 -d memory_limit=3G ' . escapeshellarg( $self ) . ' --child';
  $proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
  stream_set_blocking( $pipes[1], false );
  stream_set_blocking( $pipes[2], false );
  $buffer = '';
  $deadline = microtime( true ) + $limit;
  $killed = false;
  while ( true ) {
    $buffer .= (string) stream_get_contents( $pipes[1] );
    $buffer .= (string) stream_get_contents( $pipes[2] );
    $status = proc_get_status( $proc );
    if ( !$status['running'] ) {
      $buffer .= (string) stream_get_contents( $pipes[1] );
      break;
    }
    if ( microtime( true ) > $deadline ) {
      proc_terminate( $proc, 9 );
      $killed = true;
      break;
    }
    usleep( 20000 );
  }
  proc_close( $proc );
  return [ explode( "\n", $buffer ), $killed ];
}

foreach ( [ 0, 1 ] as $jit ) {
  echo "-- pcre.jit=$jit --\n";
  [ $out, $killed ] = run_child( $php, $self, $jit, 90 );
  $rows = [];
  $started = '';
  foreach ( $out as $line ) {
    $row = json_decode( $line, true );
    if ( is_array( $row ) && isset( $row['start'] ) ) {
      $started = $row['start'];
    }
    elseif ( is_array( $row ) && isset( $row['case'] ) ) {
      $rows[ $row['case'] ] = $row;
      $started = '';
    }
    elseif ( trim( $line ) !== '' ) {
      echo "     child: $line\n";
    }
  }
  check( "jit=$jit: the child finished inside 90 s" . ( $killed ? " (killed while running: $started)" : '' ), $killed, false );
  check( "jit=$jit: the child ran every case", count( $rows ) >= 20, true );
  check( "jit=$jit: the child confirms its own jit setting", $rows['env']['jit'] ?? null, (string) $jit );
  foreach ( $rows as $name => $row ) {
    if ( $name === 'env' ) {
      continue;
    }
    check( "jit=$jit: $name finished within its bound ({$row['seconds']}s of {$row['bound']}s)", $row['seconds'] <= $row['bound'], true );
    check( "jit=$jit: $name returned something non-empty (a gave-up regex is not a fast one)", $row['nonempty'], true );
    if ( isset( $row['same'] ) ) {
      check( "jit=$jit: $name is the same text as the reference", $row['same'], true );
    }
  }
}

echo "\n$total checks, $fails failed\n";
exit( min( 255, $fails ) );

/** Everything below runs inside a child process. */
function child(): void {
  require __DIR__ . '/lib-core-bootstrap.php';
  require_once __DIR__ . '/../includes/changes.php';
  require_once __DIR__ . '/../includes/audit.php';

  $emit = static function ( string $case, float $seconds, float $bound, bool $nonempty, ?bool $same = null ) {
    $row = [ 'case' => $case, 'seconds' => round( $seconds, 3 ), 'bound' => $bound, 'nonempty' => $nonempty ];
    if ( $same !== null ) {
      $row['same'] = $same;
    }
    echo json_encode( $row ), "\n";
    flush();
  };
  $begin = static function ( string $case ) {
    echo json_encode( [ 'start' => $case ] ), "\n";
    flush();
  };
  echo json_encode( [ 'case' => 'env', 'jit' => (string) ini_get( 'pcre.jit' ), 'seconds' => 0, 'bound' => 1, 'nonempty' => true ] ), "\n";

  // The same text, run at the size an agent can send. Bounds are an order of magnitude over what
  // the fixed code measures on a slow machine and two orders under the defect.
  $inputs = [
    'pass x 140000 (560 KB)' => str_repeat( 'pass', 140000 ),
    'a x 16 KB' => str_repeat( 'a', 16384 ),
    'a x 512 KB' => str_repeat( 'a', 524288 ),
    'a.a. x 16 KB' => str_repeat( 'a.', 8192 ),
    'svg path 8 KB' => 'M' . str_repeat( '10.5,20.25 ', 750 ),
    'token= x 16 KB' => str_repeat( 'token=', 2730 ),
    'k= x 64 KB' => str_repeat( 'k=', 32768 ),
    'x="  x 16 KB' => str_repeat( 'x="', 5461 ),
    'spaces' => 'a' . str_repeat( ' ', 65536 ) . 'b',
    'urls a:// x 16 KB' => str_repeat( 'a://', 4096 ),
    'password= chained 64 KB' => str_repeat( 'password=', 7282 ),
    'password: chained 64 KB' => str_repeat( 'password:', 7282 ),
    'distinct names x=1 64 KB' => implode( ' ', array_map( function ( $i ) { return "n$i=1"; }, range( 1, 9000 ) ) ),
    'distinct quoted names 64 KB' => implode( ' ', array_map( function ( $i ) { return "q$i=\"v\""; }, range( 1, 7000 ) ) ),
    'define( chain 32 KB' => str_repeat( 'define("a","b") ', 2000 ),
    // Second review: every new rule has an input that would make it quadratic if it were.
    'backslashes x 512 KB' => str_repeat( '\\', 524288 ),
    'escaped quotes \\" x 128 KB' => str_repeat( '\\"', 65536 ),
    'double-escaped quotes \\\\" x 96 KB' => str_repeat( '\\\\"', 32768 ),
    'a name of 512 KB then a secret' => str_repeat( 'a', 524288 ) . '_password="Zx9Qw3Lp7Rt2"',
    'a dotted name a. x 512 KB' => str_repeat( 'a.', 262144 ) . '=1',
    'a hyphenated name a- x 512 KB' => str_repeat( 'a-', 262144 ) . '=1',
    'name chars then no separator, 512 KB' => str_repeat( 'a_', 262144 ),
    'unterminated BEGIN lines x 17500 (560 KB)' => str_repeat( "-----" . "BEGIN RSA PRIVATE KEY-----\n", 17500 ),
    'BEGIN END BEGIN END x 8000' => str_repeat( "-----" . "BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----\n", 8000 ),
    '&gt; x 100 KB' => str_repeat( '&gt;', 25000 ),
    "k&gt;'x' x 64 KB" => str_repeat( "k&gt;'x' ", 8000 ),
    'escaped define chain 48 KB' => str_repeat( "define('PASSWORD','b\\'c') ", 2000 ),
    'escaped assignment chain 64 KB' => str_repeat( "smtp_pass='b\\'c' ", 4000 ),
    'quote then no end, escapes x 64 KB' => "smtp_pass='" . str_repeat( 'b\\\'', 20000 ),
    'CDATA open x 32 KB' => str_repeat( '<password><![CDATA[', 1700 ),
    'CDATA closed chain 64 KB' => str_repeat( '<password><![CDATA[x]]></password>', 1800 ),
    'curly quotes open x 64 KB' => str_repeat( 'password: “', 5000 ),
    'fullwidth separators x 64 KB' => str_repeat( 'password＝', 8000 ),
    'tabs between names x 64 KB' => str_repeat( "pass\t", 13000 ),
    'every whitespace control x 64 KB' => str_repeat( "Bearer\t\x0B\x0C\r\n\u{0085}\u{2028} ", 5000 ),
    'Basic words x 64 KB' => str_repeat( 'Basic information ', 3600 ),
    'Bearer tokens x 128 KB' => str_repeat( 'Bearer abc12345 ', 8000 ),
    'base64 words after Basic x 128 KB' => str_repeat( 'Basic dXNlcjpwYXNzMQ== ', 5600 ),
    'URL-encoded JSON open x 80 KB' => str_repeat( '%22token%22%3A%22', 5000 ),
    '[array][index] names x 64 KB' => str_repeat( "\$a['password']['x'] ", 3000 ),
    // Third review: the input-tag, CLI-user and vendor-prefix rules each get an input that would be
    // quadratic if the rule were.
    '<input open x 160 KB' => str_repeat( '<input ', 23000 ),
    '<input name value open x 96 KB' => str_repeat( '<input name="password" value="', 3100 ),
    '<input tags with a secret name x 128 KB' => str_repeat( '<input name="api_key" value="x"> ', 3900 ),
    '<input 1000 attributes' => '<input ' . str_repeat( 'a="b" ', 160 ) . 'name="password" value="Zx9Qw3Lp7Rt2">',
    'curl -u a: x 64 KB' => str_repeat( 'curl -u a:', 6500 ),
    '-u x 64 KB' => str_repeat( '-u ', 21000 ),
    'cli user pairs x 96 KB' => str_repeat( '-u a:Zx9Qw3 ', 8000 ),
    '://: x 64 KB' => str_repeat( '://:', 16000 ),
    'empty-user userinfo chain 64 KB' => str_repeat( 'a://:b@', 9000 ),
    'whsec_ x 64 KB' => str_repeat( 'whsec_', 10000 ),
    'key- x 64 KB' => str_repeat( 'key-', 16000 ),
    'SG. x 64 KB' => str_repeat( 'SG.', 21000 ),
    'telegram digits:AA x 96 KB' => str_repeat( '1234567890:AA', 7400 ),
    'hooks.slack.com/services/T x 96 KB' => str_repeat( 'hooks.slack.com/services/T', 3700 ),
    'Authorization: Key x 96 KB' => str_repeat( 'Authorization: Key ', 5000 ),
    'update_option chain 64 KB' => str_repeat( "update_option('a_token','b') ", 2300 ),
  ];

  foreach ( $inputs as $label => $text ) {
    $begin( "scrub_written_text of $label" );
    $t = microtime( true );
    $out = GMCP_Core::scrub_written_text( $text );
    $emit( "scrub_written_text of $label", microtime( true ) - $t, 2.0, $out !== '' );

    $begin( "redact of $label" );
    $t = microtime( true );
    $out = GMCP_Core::redact( [ 'post_content' => $text ] );
    $emit( "redact of $label", microtime( true ) - $t, 2.0, ( $out['post_content'] ?? '' ) !== '' );

    $begin( "storable_args of $label" );
    $t = microtime( true );
    $json = GMCP_Audit::storable_args( [ 'key' => 'blogdescription', 'value' => 'x', 'zz' => $text ] );
    $emit( "storable_args of $label", microtime( true ) - $t, 2.0, $json !== '' && strpos( $json, 'blogdescription' ) !== false );

    $begin( "credential scan of $label" );
    $t = microtime( true );
    $holds = GMCP_Credential_Scan::text_holds( $text );
    $spans = method_exists( 'GMCP_Credential_Scan', 'spans' ) ? GMCP_Credential_Scan::spans( $text ) : [];
    $emit( "credential scan of $label", microtime( true ) - $t, 2.0, is_bool( $holds ) && is_array( $spans ) );

    $begin( "log_text of $label" );
    $t = microtime( true );
    $line = GMCP_Core::log_text( $text );
    $emit( "log_text of $label", microtime( true ) - $t, 2.0, $line !== '' );
  }

  // Fourth review: the scanner read each unquoted value to the end of its run of non-space characters,
  // and a text that is one run with a secret-named assignment every few bytes ("password=|" repeated)
  // was therefore read to its end once per assignment. Half a megabyte took 23 to 50 s through holds().
  // Each input below is 512 KB, goes through the three entry points the journal, the audit log and the
  // approval view use, and has to finish inside two seconds with the JIT on or off. A text the scanner
  // gives up on is answered as a credential, which is why the answer is part of the check: the gave-up
  // answer must be "yes" and the quick answer must not be an error.
  $half = 524288;
  $adversarial = [
    'password=this. repeated' => str_repeat( 'password=this.', intdiv( $half, 14 ) ),
    'password=| repeated' => str_repeat( 'password=|', intdiv( $half, 10 ) ),
    'pw=| repeated' => str_repeat( 'pw=|', intdiv( $half, 4 ) ),
    'pw=this. repeated' => str_repeat( 'pw=this.', intdiv( $half, 8 ) ),
    'password=password= chained, no space' => str_repeat( 'password=', intdiv( $half, 9 ) ),
    'one 512 KB value after password=' => 'password=' . str_repeat( 'Ab3', intdiv( $half, 3 ) ),
    'password=<600 bytes> repeated' => str_repeat( 'password=' . str_repeat( 'Ab3dE', 120 ), intdiv( $half, 609 ) ),
    'db_pass=a_ repeated (identifier)' => str_repeat( 'db_pass=a_', intdiv( $half, 10 ) ),
    'token=a-b repeated (words)' => str_repeat( 'token=a-', intdiv( $half, 8 ) ),
    'secret: nested names, no space' => str_repeat( 'secret:a=api_key:', intdiv( $half, 17 ) ),
    'api_key=process.env. repeated' => str_repeat( 'api_key=process.env.', intdiv( $half, 20 ) ),
    'DB_PASSWORD=config( repeated' => str_repeat( 'DB_PASSWORD=config(', intdiv( $half, 19 ) ),
    'curl -ua: repeated (attached short form)' => str_repeat( 'curl -ua:', intdiv( $half, 9 ) ),
    'curl -u a: repeated' => str_repeat( 'curl -u a:', intdiv( $half, 10 ) ),
    '<input " repeated' => str_repeat( '<input "', intdiv( $half, 8 ) ),
    '<input name="password" value=" repeated' => str_repeat( '<input name="password" value="', intdiv( $half, 31 ) ),
    '<input a=\'b" repeated, mixed quotes' => str_repeat( '<input a=\'b"', intdiv( $half, 13 ) ),
    '<input then one 512 KB tag' => '<input ' . str_repeat( 'a="b" ', intdiv( $half, 6 ) ) . 'name="password" value="Kx92mQ7zp">',
    'redis://:a: repeated (empty user, colon)' => str_repeat( 'redis://:a:', intdiv( $half, 11 ) ),
    'secret_key_base: and primary_key: repeated' => str_repeat( 'primary_key:storage_key=', intdiv( $half, 24 ) ),
  ];
  foreach ( $adversarial as $label => $text ) {
    $begin( "scan of $label (512 KB)" );
    $t = microtime( true );
    $holds = GMCP_Credential_Scan::holds( $text );
    $spans = GMCP_Credential_Scan::spans( $text );
    $emit( "scan of $label (512 KB)", microtime( true ) - $t, 2.0, is_bool( $holds ) && is_array( $spans ) );
  }

  // Cost that grows with the NUMBER of leaves, not their size: a million one-character strings is
  // 4 MB and a million secret-looking assignments 40 MB, both well inside what a request may carry.
  // Each walk used to take 2 to 18 s with the JIT off. The probe of the walk that records must also
  // say what it did not read, so "nonempty" here is a real property: the record exists and names the
  // omission, or the answer is "not safe". It is not the absence of an error.
  $leafy = [
    '1M leaves of "a"' => array_fill( 0, 1000000, 'a' ),
  ];
  $assign = [];
  for ( $i = 0; $i < 1000000; $i++ ) {
    $assign[ "f$i" ] = "p{$i}_password=\"Zx9Qw3Lp7Rt2\"";
  }
  $leafy['1M distinct secret-looking assignments'] = $assign;
  unset( $assign );
  foreach ( $leafy as $label => $tree ) {
    $begin( "leafy storable_args of $label" );
    $t = microtime( true );
    $json = GMCP_Audit::storable_args( [ 'key' => 'blogdescription', 'value' => 'x', 'zz' => $tree ] );
    $emit( "leafy storable_args of $label", microtime( true ) - $t, 2.0, $json !== '' && ( strpos( $json, '__gmcp_truncated' ) !== false || strpos( $json, '__gmcp_omitted' ) !== false ) );

    $begin( "leafy event_args of $label" );
    $t = microtime( true );
    $ev = GMCP_Audit::event_args( [ 'zz' => $tree, 'caller' => 'key:1' ] );
    $emit( "leafy event_args of $label", microtime( true ) - $t, 2.0, isset( $ev['zz']['__gmcp_omitted'] ) && ( $ev['caller'] ?? '' ) === 'key:1' );

    $begin( "leafy log_text of $label" );
    $t = microtime( true );
    $line = GMCP_Core::log_text( $tree );
    $emit( "leafy log_text of $label", microtime( true ) - $t, 2.0, $line !== '' && $line !== GMCP_Core::UNSCRUBBABLE );

    $begin( "leafy holds of $label" );
    $t = microtime( true );
    $held = GMCP_Credential_Scan::holds( $tree );
    $emit( "leafy holds of $label", microtime( true ) - $t, 2.0, $held === true );

    $begin( "leafy redact_reversible of $label" );
    $t = microtime( true );
    $rev = GMCP_Core::redact_reversible( $tree );
    $emit( "leafy redact_reversible of $label", microtime( true ) - $t, 2.0, $rev === [ false, null ] );
  }
  unset( $leafy, $tree );

  // The old output, on the probe cases where the old expression finished: same text out.
  // Reference = the expression this change replaced, run on inputs small enough for it.
  $reference = static function ( string $text ): string {
    $names = [];
    foreach ( GMCP_Core::credential_field_patterns() as $p ) {
      $names[] = preg_quote( $p, '/' );
    }
    $re = '/([A-Za-z0-9_.\-\[\]]*(?:' . implode( '|', $names ) . ')[A-Za-z0-9_.\-\[\]]*)'
      . '(["\']?\s*(?:=>|=|:|%3D|%3A|%3d|%3a)\s*["\']?)(?!\[redacted\])[^\s&,;"\'}\])<>]+/i';
    return (string) preg_replace( $re, '$1$2[redacted]', $text );
  };
  // scrub_written_text() keeps the old meaning exactly (a value ends at the first delimiter); only
  // how it finds the name changed. The debug log's wider rule, which takes a multi-word value
  // whole, is a separate function and is asserted in test-credential-channels.php.
  $probes = [
    'plain assignment' => 'smtp_pwd=hunter2 and more',
    'json' => '{"api_key":"abc123","note":"hello"}',
    'query' => 'https://x.test/?a=1&token=abcdef&b=2',
    'header-ish' => 'x-auth: abcdef123',
    'brackets' => 'form[password]=secret1&x=1',
    'two' => 'password=one1 and token=two2',
    'nothing' => 'Nothing here to hide, just prose: with a colon.',
    'authorid' => 'authorId=42 monkey=3',
  ];
  foreach ( $probes as $label => $text ) {
    $t = microtime( true );
    $got = GMCP_Core::scrub_written_text( $text );
    $emit( "reference probe: $label", microtime( true ) - $t, 1.0, $got !== '', $got === $reference( $text ) );
  }
}
