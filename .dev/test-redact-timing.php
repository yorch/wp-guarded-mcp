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
  $cmd = escapeshellarg( $php ) . ' -d pcre.jit=' . $jit . ' -d pcre.backtrack_limit=1000000 -d max_execution_time=0 ' . escapeshellarg( $self ) . ' --child';
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
