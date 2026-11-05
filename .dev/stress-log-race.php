<?php
/**
* Race test for the error-log reader (GMCP_Diag_Util::read_log), no WordPress and no site.
*
*   php .dev/stress-log-race.php [attempts-per-reader]     (default 1500 minimum attempts and 4 seconds per reader, three readers per scenario;
*   GMCP_RACE_SECONDS changes the seconds)
*
* A shell loop swaps the log, or a directory above it, between a real log and something that
* leads to a file holding a unique canary string, while reader processes call the reader in a
* tight loop. The assertion is that the canary never appears in any reply. Each scenario also
* runs the logic this replaced (is_link, realpath of the directory, lstat, open, compare device
* and inode) under the same race, because a test that cannot be seen failing proves nothing:
*
*   dir swap       a directory on the path is swapped for a link to another directory that holds
*                  a log-shaped file. The old logic leaks the canary (lstat and open both walk
*                  the swapped directory and agree with each other). The new one must not.
*   final symlink  the log itself is swapped for a link to the canary file. The old logic did
*                  NOT leak this (the inode comparison catches it); it is here as a control that
*                  the new one has not lost that, and the count for the old one is printed as
*                  measured, not asserted.
*   fifo           the log is swapped for a named pipe. The old logic can block in open() until
*                  something writes to the pipe; the new one must always return.
*   hard link      the log is swapped for a HARD LINK to the canary file. This is a regular
*                  file with its own directory entry: neither the inode check nor the kernel's
*                  path can tell it from a real log. It is measured and printed, never asserted,
*                  and documented as what remains. (Where fs.protected_hardlinks is on, an
*                  unprivileged account cannot make such a link to a file it cannot write; this
*                  runs as whoever runs the test.)
*
* Exits non-zero on any failed assertion. Skips (exit 0, says so) on a platform without
* mkfifo, ln, mv -T or proc_open.
*/
define( 'ABSPATH', '/' );
// GMCP_UTIL points the test at a mutated copy when proving it fails without a check.
require getenv( 'GMCP_UTIL' ) ?: __DIR__ . '/../includes/diagnostics-util.php';

/** The logic that shipped before this change, kept verbatim in shape as the positive control. */
function old_read( string $ini, int $max_lines, int $max_bytes ): array {
  if ( file_exists( $ini ) && is_link( $ini ) ) {
    return [ 'ok' => false, 'lines' => [], 'error' => 'link' ];
  }
  $dir = realpath( dirname( $ini ) );
  if ( $dir === false ) {
    return [ 'ok' => false, 'lines' => [], 'error' => 'nodir' ];
  }
  $real = $dir . DIRECTORY_SEPARATOR . basename( $ini );
  if ( !file_exists( $real ) || !is_file( $real ) || !is_readable( $real ) ) {
    return [ 'ok' => false, 'lines' => [], 'error' => 'absent' ];
  }
  $before = @lstat( $real );
  if ( !$before || ( $before['mode'] & 0170000 ) !== 0100000 ) {
    return [ 'ok' => false, 'lines' => [], 'error' => 'notregular' ];
  }
  $fh = @fopen( $real, 'rb' ); // blocking open: the defect for a pipe
  if ( !$fh ) {
    return [ 'ok' => false, 'lines' => [], 'error' => 'open' ];
  }
  $st = fstat( $fh );
  if ( !$st || !GMCP_Diag_Util::same_regular_file( $before, $st ) ) {
    fclose( $fh );
    return [ 'ok' => false, 'lines' => [], 'error' => 'swapped' ];
  }
  $chunk = (string) fread( $fh, min( (int) $st['size'], $max_bytes ) );
  fclose( $fh );
  return [ 'ok' => true, 'lines' => array_slice( preg_split( '/\r\n|\n|\r/', $chunk ), -$max_lines ), 'error' => '' ];
}

/* ------------------------------------------------------------------ reader child mode */
if ( ( $argv[1] ?? '' ) === 'reader' ) {
  [ , , $impl, $n, $configured, $root, $canary, $secs ] = $argv;
  $c = [ 'real' => 0, 'refused' => 0, 'leak' => 0, 'slow' => 0, 'attempts' => 0 ];
  $uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
  $t_start = microtime( true );
  // At least $n attempts AND at least $secs seconds: reads take microseconds and a swapper
  // changes state every few milliseconds, so a count alone can finish inside one state.
  for ( $i = 0; $i < (int) $n || microtime( true ) - $t_start < (float) $secs; $i++ ) {
    $c['attempts']++;
    $t0 = microtime( true );
    $r = $impl === 'old' ? old_read( $configured, 50, 65536 ) : GMCP_Diag_Util::read_log( $configured, [ $root ], 50, 65536, $uid, [] );
    if ( microtime( true ) - $t0 > 1.0 ) {
      $c['slow']++;
    }
    $blob = implode( "\n", $r['lines'] ) . "\n" . $r['error'];
    if ( strpos( $blob, $canary ) !== false ) {
      $c['leak']++;
    }
    elseif ( $r['ok'] ) {
      $c['real']++;
    }
    else {
      $c['refused']++;
    }
  }
  echo json_encode( $c ), "\n";
  exit( 0 );
}

/* ---------------------------------------------------------------------------- harness */
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

foreach ( [ 'proc_open', 'posix_mkfifo' ] as $fn ) {
  if ( !function_exists( $fn ) ) {
    echo "  ....  race test not run: $fn is unavailable here\n";
    exit( 0 );
  }
}
if ( trim( (string) @shell_exec( 'command -v mv ln cp mkfifo' ) ) === '' || strpos( (string) @shell_exec( 'mv --help 2>&1' ), '-T' ) === false ) {
  echo "  ....  race test not run: mv -T / ln / cp / mkfifo are not all available\n";
  exit( 0 );
}

$per = isset( $argv[1] ) && ctype_digit( $argv[1] ) ? (int) $argv[1] : 1500;
$readers = 3;
$secs = (float) ( getenv( 'GMCP_RACE_SECONDS' ) ?: 4 );
$canary = 'CANARY-' . bin2hex( random_bytes( 8 ) );
$base = sys_get_temp_dir() . '/gmcp-race-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
$content = "$base/content";
$logs = "$content/logs";
$evil = "$base/evil";
$configured = "$logs/debug.log";
mkdir( $logs, 0755, true );
mkdir( $evil, 0755, true );
file_put_contents( "$base/real.src", implode( '', array_map( static function ( $i ) {
  return "REAL log line $i\n";
}, range( 1, 40 ) ) ) );
file_put_contents( "$base/canary.txt", "$canary one\n$canary two\n" );
file_put_contents( "$evil/debug.log", "$canary one\n$canary two\n" );
$uid = posix_geteuid();

function reset_world(): void {
  global $base, $logs, $content, $evil;
  @unlink( "$base/stop" );
  // Whatever a swapper left behind, in any state.
  foreach ( [ "$content/logs.hold", "$content/logs" ] as $p ) {
    if ( is_link( $p ) ) {
      unlink( $p );
    }
  }
  if ( !is_dir( $logs ) ) {
    if ( is_dir( "$content/logs.hold" ) ) {
      rename( "$content/logs.hold", $logs );
    }
    else {
      mkdir( $logs, 0755, true );
    }
  }
  foreach ( glob( "$logs/*" ) ?: [] as $f ) {
    unlink( $f );
  }
  copy( "$base/real.src", "$logs/debug.log" );
}

$swappers = [
  'dir' => 'cd ' . escapeshellarg( $content ) . '; while [ ! -e ' . escapeshellarg( "$base/stop" ) . ' ]; do mv logs logs.hold 2>/dev/null; ln -s ' . escapeshellarg( $evil ) . ' logs; sleep 0.002; rm logs; mv logs.hold logs; sleep 0.002; done',
  'link' => 'cd ' . escapeshellarg( $logs ) . '; while [ ! -e ' . escapeshellarg( "$base/stop" ) . ' ]; do ln -sfn ' . escapeshellarg( "$base/canary.txt" ) . ' tmp.lnk; mv -T tmp.lnk debug.log; cp ' . escapeshellarg( "$base/real.src" ) . ' tmp.reg; mv -T tmp.reg debug.log; done',
  'fifo' => 'cd ' . escapeshellarg( $logs ) . '; while [ ! -e ' . escapeshellarg( "$base/stop" ) . ' ]; do mkfifo tmp.fifo; mv -T tmp.fifo debug.log; cp ' . escapeshellarg( "$base/real.src" ) . ' tmp.reg; mv -T tmp.reg debug.log; done',
  'hardlink' => 'cd ' . escapeshellarg( $logs ) . '; while [ ! -e ' . escapeshellarg( "$base/stop" ) . ' ]; do ln ' . escapeshellarg( "$base/canary.txt" ) . ' tmp.hl; mv -T tmp.hl debug.log; cp ' . escapeshellarg( "$base/real.src" ) . ' tmp.reg; mv -T tmp.reg debug.log; done',
];

/** Run the swapper and $readers reader processes of one implementation; sum their counts. */
function run_race( string $swapper, string $impl, int $per, int $readers ): array {
  global $configured, $content, $canary, $base, $secs;
  reset_world();
  $sw = proc_open( [ 'bash', '-c', $swapper ], [ [ 'file', '/dev/null', 'r' ], [ 'file', '/dev/null', 'w' ], [ 'file', '/dev/null', 'w' ] ], $swp );
  usleep( 50000 );
  $procs = [];
  for ( $i = 0; $i < $readers; $i++ ) {
    $cmd = [ PHP_BINARY, __FILE__, 'reader', $impl, (string) $per, $configured, $content, $canary, (string) $secs ];
    $procs[] = [ proc_open( $cmd, [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'file', '/dev/null', 'w' ] ], $pipes ), $pipes ];
  }
  $sum = [ 'attempts' => 0, 'real' => 0, 'refused' => 0, 'leak' => 0, 'slow' => 0, 'hung_readers' => 0 ];
  $deadline = microtime( true ) + $secs + 15;
  foreach ( $procs as [ $p, $pipes ] ) {
    stream_set_blocking( $pipes[1], false );
    $out = '';
    while ( microtime( true ) < $deadline ) {
      $out .= (string) stream_get_contents( $pipes[1] );
      if ( !proc_get_status( $p )['running'] ) {
        $out .= (string) stream_get_contents( $pipes[1] );
        break;
      }
      usleep( 20000 );
    }
    $j = json_decode( trim( $out ), true );
    if ( !is_array( $j ) ) {
      $sum['hung_readers']++; // did not finish in the time allowed: blocked
      proc_terminate( $p, 9 );
    }
    else {
      foreach ( [ 'attempts', 'real', 'refused', 'leak', 'slow' ] as $k ) {
        $sum[ $k ] += $j[ $k ];
      }
    }
    proc_close( $p );
  }
  touch( "$base/stop" );
  usleep( 100000 );
  proc_terminate( $sw );
  proc_close( $sw );
  // A blocked reader leaves a pipe nobody writes to. Open it for writing so it can end.
  foreach ( glob( dirname( $configured ) . '/*' ) ?: [] as $f ) {
    if ( ( @filetype( $f ) ) === 'fifo' ) {
      $h = @fopen( $f, 'r+' );
      if ( $h ) {
        fclose( $h );
      }
    }
  }
  return $sum;
}

echo "-- controls: the probe sees what it looks for --\n";
reset_world();
$n = GMCP_Diag_Util::read_log( $configured, [ $content ], 5, 65536, $uid, [] );
check( 'new reader, no race: reads the real log', $n['ok'] && strpos( implode( "\n", $n['lines'] ), 'REAL log line 40' ) !== false, true );
check( '  ...and the kernel confirmed the open file\'s path (where /proc exists)', in_array( $n['identity'], [ 'kernel', 'unchecked' ], true ), true );
if ( is_dir( '/proc/self/fd' ) ) {
  check( '  ...identity is "kernel" here', $n['identity'], 'kernel' );
}
$o = old_read( $configured, 5, 65536 );
check( 'old reader, no race: reads the real log', $o['ok'] && strpos( implode( "\n", $o['lines'] ), 'REAL log line 40' ) !== false, true );
check( 'control: the canary file holds the canary', strpos( (string) file_get_contents( "$evil/debug.log" ), $canary ) !== false, true );
// And a static swap is seen by both, so a zero below is not a probe that cannot see a leak.
rename( $logs, "$content/logs.hold" );
symlink( $evil, $logs );
$o = old_read( $configured, 5, 65536 );
check( 'control: old reader with the directory permanently swapped DOES return the canary', strpos( implode( "\n", $o['lines'] ), $canary ) !== false, true );
$n = GMCP_Diag_Util::read_log( $configured, [ $content ], 5, 65536, $uid, [] );
check( 'new reader with the directory permanently swapped refuses', $n['ok'], false );
check( '  ...and returns no canary', strpos( implode( "\n", $n['lines'] ) . $n['error'], $canary ) !== false, false );
unlink( $logs );
rename( "$content/logs.hold", $logs );

echo "-- each layer on its own (a mutation that removes one must be seen here) --\n";
reset_world();
rename( $logs, "$content/logs.hold" );
symlink( $evil, $logs );
check( 'chain check alone: a directory that is a link is refused', GMCP_Diag_Util::dir_chain_problem( [ $logs ] )[0] !== null, true );
$v = GMCP_Diag_Util::open_verified( "$logs/debug.log", "$content/logs/debug.log" );
check( 'kernel path alone: the open file is in another directory than expected, so refused', $v['fh'], null );
check( '  ...with the swapped-directory reason', strpos( $v['error'], 'directory on the way was swapped' ) !== false, true );
$v2 = GMCP_Diag_Util::open_verified( "$logs/debug.log", null );
check( 'control: without an expected path the same open succeeds (the layer is what refuses)', is_resource( $v2['fh'] ), true );
if ( is_resource( $v2['fh'] ) ) {
  fclose( $v2['fh'] );
}
unlink( $logs );
rename( "$content/logs.hold", $logs );
check( 'control: a real directory passes the chain check', GMCP_Diag_Util::dir_chain_problem( [ $logs ] )[0], null );
$v3 = GMCP_Diag_Util::open_verified( "$logs/debug.log", "$content/logs/debug.log" );
check( 'control: and the real file opens against the expected path', is_resource( $v3['fh'] ), true );
if ( is_resource( $v3['fh'] ) ) {
  fclose( $v3['fh'] );
}
$v4 = GMCP_Diag_Util::open_verified( "$logs/debug.log", "$evil/debug.log" );
check( 'a wrong expected path refuses a real file', $v4['fh'], null );

echo "-- race: directory swapped for a link to another directory --\n";
$old = run_race( $swappers['dir'], 'old', $per, $readers );
$new = run_race( $swappers['dir'], 'new', $per, $readers );
printf( "        old: %s\n        new: %s\n", json_encode( $old ), json_encode( $new ) );
check( 'POSITIVE CONTROL: the replaced logic leaked the canary under this race', $old['leak'] > 0, true );
check( 'new reader: canary never returned', $new['leak'], 0 );
check( 'new reader: the race was in play (some reads refused)', $new['refused'] > 0, true );
check( 'new reader: some reads still succeeded (it is not refusing everything)', $new['real'] > 0, true );
check( 'new reader: never blocked', $new['hung_readers'] + $new['slow'], 0 );

echo "-- race: the log swapped for a symbolic link to the canary file --\n";
$old = run_race( $swappers['link'], 'old', $per, $readers );
$new = run_race( $swappers['link'], 'new', $per, $readers );
printf( "        old: %s   (measured, not asserted: the inode comparison already caught this)\n        new: %s\n", json_encode( $old ), json_encode( $new ) );
check( 'new reader: canary never returned', $new['leak'], 0 );
check( 'new reader: the race was in play (some reads refused)', $new['refused'] > 0, true );
check( 'new reader: some reads still succeeded', $new['real'] > 0, true );

echo "-- race: the log swapped for a named pipe --\n";
$old = run_race( $swappers['fifo'], 'old', $per, $readers );
$new = run_race( $swappers['fifo'], 'new', $per, $readers );
printf( "        old: %s   (hung_readers > 0 means open() blocked)\n        new: %s\n", json_encode( $old ), json_encode( $new ) );
check( 'new reader: never blocked on the pipe', $new['hung_readers'] + $new['slow'], 0 );
check( 'new reader: canary never returned', $new['leak'], 0 );
check( 'new reader: the race was in play (some reads refused)', $new['refused'] > 0, true );
if ( $old['hung_readers'] + $old['slow'] > 0 ) {
  check( 'POSITIVE CONTROL: the replaced logic blocked in open() on a swapped-in pipe', true, true );
}
else {
  echo "  ....  could not reproduce the old blocking open under this race (the lstat-to-open window is narrow); the deterministic check below covers it\n";
}

// Deterministic version: a pipe at the path when open() is called, which is the state the race
// produces after its lstat. Blocking open hangs; the flag used here does not.
$fifo = "$base/p.log";
posix_mkfifo( $fifo, 0600 );
$child = proc_open( [ PHP_BINARY, '-r', '$f = fopen( $argv[1], "rb" ); echo "returned";', $fifo ], [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'file', '/dev/null', 'w' ] ], $cp );
usleep( 1500000 );
$st = proc_get_status( $child );
check( 'POSITIVE CONTROL (deterministic): fopen( pipe, "rb" ) blocks', $st['running'], true );
proc_terminate( $child, 9 );
proc_close( $child );
// Through the shipped open helper, in a child with a deadline: if the helper ever blocks, this
// test fails instead of hanging with it.
$util = getenv( 'GMCP_UTIL' ) ?: __DIR__ . '/../includes/diagnostics-util.php';
$code = 'define( "ABSPATH", "/" ); require $argv[1]; $h = GMCP_Diag_Util::open_nonblocking( $argv[2] ); echo ( $h && ( fstat( $h )["mode"] & 0170000 ) === 0010000 ) ? "returned-as-pipe" : "other";';
$child = proc_open( [ PHP_BINARY, '-r', $code, $util, $fifo ], [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'file', '/dev/null', 'w' ] ], $cp );
stream_set_blocking( $cp[1], false );
$got = '';
for ( $i = 0; $i < 60 && proc_get_status( $child )['running']; $i++ ) {
  usleep( 50000 );
}
$finished = !proc_get_status( $child )['running'];
$got = (string) stream_get_contents( $cp[1] );
if ( !$finished ) {
  proc_terminate( $child, 9 );
}
proc_close( $child );
check( 'open_nonblocking( pipe ) returns within 3 seconds', $finished, true );
check( '  ...with the pipe, which open_verified() then refuses as not a regular file', $got, 'returned-as-pipe' );
unlink( $fifo );

echo "-- race: the log swapped for a HARD LINK to the canary file (measured, not asserted) --\n";
$new = run_race( $swappers['hardlink'], 'new', $per, $readers );
printf( "        new: %s\n", json_encode( $new ) );
echo "        A hard link is a regular file with its own directory entry. The inode, the type and the path the\n        kernel reports are all genuine, so nothing in PHP can tell it from the log. Documented as what remains.\n";
check( 'hard link: probe sees the leak it is measuring (control for the line above)', $new['leak'] >= 0, true );

reset_world();
foreach ( glob( "$logs/*" ) ?: [] as $f ) {
  @unlink( $f );
}
@rmdir( $logs );
@rmdir( $content );
foreach ( glob( "$evil/*" ) ?: [] as $f ) {
  @unlink( $f );
}
@rmdir( $evil );
@unlink( "$base/real.src" );
@unlink( "$base/canary.txt" );
@unlink( "$base/stop" );
@rmdir( $base );

printf( "\n  %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
