<?php
/**
* What each channel that records text writes down, judged on the same inputs.
*
* Run from anywhere:  php .dev/test-credential-channels.php
* Exit status is the number of failed checks, capped at 255. Not shipped. Needs no WordPress.
*
* Real includes/core.php, credential-scan.php, diagnostics-util.php, audit.php, changes.php and
* logging.php are loaded behind a handful of WordPress stand-ins (lib-core-bootstrap.php).
*
* Every "is absent" check sits beside a control that finds a present value of the same kind, and
* every secret is built from a marker that must come back out of the output for text that holds
* no secret, so a probe that returns nothing cannot pass.
*/

require __DIR__ . '/lib-core-bootstrap.php';
require_once __DIR__ . '/../includes/changes.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/logging.php';

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
function has( string $haystack, string $needle ): bool {
  return strpos( $haystack, $needle ) !== false;
}

/* ------------------------------------------------------------------------------------------
 * The debug log: GMCP_Core::log_text() and GMCP_Logging
 * ---------------------------------------------------------------------------------------- */
echo "-- the debug log: shapes the plugin recognises elsewhere are scrubbed here too --\n";

$pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun\n-----END RSA PRIVATE KEY-----";
// [ label, text, the secret that must not survive ]
$log_secrets = [
  [ 'a Stripe live key', 'charge failed for sk_' . 'live_4eC39HqLyjWDarjtT1zdp7dc' . ' here', '4eC39HqLyjWDarjtT1zdp7dc' ],
  [ 'a GitHub token', 'clone https with ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789 failed', 'aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789' ],
  [ 'an AWS access key id', 'region key AKIAIOSFODNN7EXAMPLE rejected', 'AKIAIOSFODNN7EXAMPLE' ],
  [ 'a JWT', 'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk was refused', 'dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk' ],
  [ 'a PEM block', "key was " . $pem, 'MIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun' ],
  [ 'redis user information', 'cannot reach redis://:hunter2cache@cache.internal:6379', 'hunter2cache' ],
  [ 'mysql user information', 'cannot reach mysql://appuser:hunter2db@db.internal/wp', 'hunter2db' ],
  [ 'escaped JSON', 'payload {\"password\":\"hunter2 escaped\"}', 'hunter2 escaped' ],
  [ 'pw=', 'form pw=hunter2pw&x=1', 'hunter2pw' ],
  [ 'a define() line', "define('DB_PASSWORD','hunter22define');", 'hunter22define' ],
  [ 'an unquoted multi-word value after a key', 'mail failed smtp_pwd=correct horse battery staple', 'horse' ],
  [ 'a value after "password:"', 'password: correct horse', 'horse' ],
  [ 'a quoted multi-word value', 'secret = "two words"', 'words' ],
  [ 'a Bearer header', 'Authorization: Bearer abcdefghijklmnop12345678', 'abcdefghijklmnop12345678' ],
  [ 'a token in a query', 'GET /x?a=1&api_key=k3yk3yk3y9&b=2', 'k3yk3yk3y9' ],
];
foreach ( $log_secrets as [ $label, $text, $secret ] ) {
  $line = GMCP_Core::log_text( 'markerXYZ ' . $text );
  check( "CONTROL: $label: the input holds the secret and the marker", has( 'markerXYZ ' . $text, $secret ) && has( $line, 'markerXYZ' ), true );
  check( "log: $label is not written", has( $line, $secret ), false );
}
// What the wider value rule must NOT eat: the part of the line that is not the secret.
check( 'log: a value ends at "&", so the next parameter is still readable',
  GMCP_Core::log_text( 'a=1&smtp_pwd=hunter2 horse&next=keepme' ), 'a=1&smtp_pwd=[redacted]&next=keepme' );
check( 'log: a quoted value is taken whole and what follows it is kept',
  GMCP_Core::log_text( 'secret = "two words" and then more' ), 'secret = [redacted] and then more' );
check( 'CONTROL: text with no secret in it is written exactly as it came',
  GMCP_Core::log_text( 'Registered client: Claude Desktop 1.2.3 on example' ), 'Registered client: Claude Desktop 1.2.3 on example' );
// Stable under a second pass: Logging::write() scrubs a line a call site already scrubbed.
$stable = true;
foreach ( $log_secrets as [ $label, $text, $secret ] ) {
  $once = GMCP_Core::log_text( $text );
  $stable = $stable && GMCP_Core::log_line( $once ) === $once;
}
check( 'log: scrubbing an already scrubbed line changes nothing', $stable, true );
check( 'log: an array argument is scrubbed as JSON and still shows its harmless fields',
  [ has( GMCP_Core::log_text( [ 'smtp_pwd' => 'hunter2', 'note' => 'visible' ] ), 'hunter2' ), has( GMCP_Core::log_text( [ 'smtp_pwd' => 'hunter2', 'note' => 'visible' ] ), 'visible' ) ], [ false, true ] );

echo "-- the debug log: one value is one line --\n";
$forged = "tok\n[Guarded MCP OAuth] ❌ Authorize refused: forged by a client\r\n[Guarded MCP] WARNING: and another";
$line = GMCP_Core::log_text( $forged );
check( 'CONTROL: the probe really holds line breaks', [ has( $forged, "\n" ), has( $forged, "\r" ) ], [ true, true ] );
check( 'a newline or carriage return in a request value is not written as one', [ has( $line, "\n" ), has( $line, "\r" ) ], [ false, false ] );
check( 'it is shown as an escape, so the reader can see what was sent', has( $line, '\\n[Guarded MCP' ), true );
check( 'no control character of any kind survives', preg_match( '/[\x00-\x1F\x7F]/', GMCP_Core::log_text( "a\x00b\x07c\x1bd\te\x7ff" ) ), 0 );
check( 'CONTROL: the Unicode line separators are shown too', [ has( GMCP_Core::log_text( "a\u{2028}b\u{2029}c\u{0085}d" ), "\u{2028}" ), has( GMCP_Core::log_text( "a\u{2028}b" ), 'u2028' ) ], [ false, true ] );

$errlog = sys_get_temp_dir() . '/gmcp-channels-' . getmypid() . '.log';
@unlink( $errlog );
ini_set( 'error_log', $errlog );
GMCP_Logging::warn( "client said\n[Guarded MCP] ERROR: forged, smtp_pwd=hunter2writer" );
$written = (string) @file_get_contents( $errlog );
@unlink( $errlog );
check( 'CONTROL: GMCP_Logging wrote the line to the log it was pointed at', has( $written, 'client said' ), true );
check( 'GMCP_Logging::write() writes one line for a message with a newline in it', substr_count( rtrim( $written, "\n" ), "\n" ), 0 );
check( 'and scrubs a credential assignment in it', has( $written, 'hunter2writer' ), false );

echo "-- the debug log: a scrub that cannot finish says so, it does not write nothing --\n";
$was = [ ini_get( 'pcre.backtrack_limit' ), ini_get( 'pcre.jit' ) ];
$hard = [ 'an assignment' => str_repeat( 'pass=abc ', 400 ), 'vendor keys' => str_repeat( 'x sk_live_abcdefgh1234 ', 100 ), 'a long name run' => str_repeat( 'a-pass', 2000 ) . '=x' ];
$gave_up = [];
$gave_up_text = [];
ini_set( 'pcre.jit', '0' );
ini_set( 'pcre.backtrack_limit', '1' );
foreach ( $hard as $label => $text ) {
  $gave_up[ $label ] = GMCP_Core::log_text( $text );
  $gave_up_text[ $label ] = GMCP_Core::scrub_written_text( $text );
}
ini_set( 'pcre.backtrack_limit', $was[0] );
ini_set( 'pcre.jit', $was[1] );
foreach ( $hard as $label => $text ) {
  check( "CONTROL: $label scrubs to something real under a normal limit (so the limit is what changed it)",
    GMCP_Core::log_text( $text ) !== GMCP_Core::UNSCRUBBABLE && GMCP_Core::log_text( $text ) !== '' && trim( GMCP_Core::log_text( $text ) ) !== '…[line truncated]', true );
  check( "log_text of $label with PCRE given up is the withheld marker, not \"\" and not a bare truncation note", $gave_up[ $label ], GMCP_Core::UNSCRUBBABLE );
  // The assignment scrub has nothing to read in text with no credential word in it, so it does not
  // run a pattern over it and cannot give up on it; the vendor-key rules are the log scrub's.
  if ( $label !== 'vendor keys' ) {
    check( "scrub_written_text of $label with PCRE given up is the withheld marker, not \"\"", $gave_up_text[ $label ], GMCP_Core::UNSCRUBBABLE );
  }
}

echo "-- every error_log() in the plugin prints client text through log_text() --\n";
// A lint over the source: for each error_log( ... ); statement, remove every GMCP_Core::log_text(...)
// call, then look for what a client controls in what is left. Not a proof, a tripwire: a new
// error_log() that prints $reason, an exception message or a request parameter without the helper
// fails here. The probe is shown to find a violation and to accept a wrapped one.
function error_log_violations( string $source ): array {
  $found = [];
  // One statement is exempt: the audit log's "log is full" line compares $reason, which is the
  // string 'rows' or 'bytes' chosen by reason_for(), and prints neither client text nor the variable.
  $clientish = '/\$reason|getMessage\(|\$_GET|\$_POST|\$_REQUEST|\$_SERVER|get_param\(|\$params\b|\$arguments|\$args\b|\$stray|\$grant_type|\$client_name|\$client_info|\$requested_version|\$method\b|\$path\b|\$body\b|\$data\[/';
  $offset = 0;
  while ( ( $at = strpos( $source, 'error_log(', $offset ) ) !== false ) {
    $offset = $at + 10;
    $before = substr( $source, max( 0, $at - 1 ), 1 );
    if ( $before !== ' ' && $before !== "\n" && $before !== "\t" && $before !== '' ) {
      continue; // wp_error_log( or a method of the same name
    }
    $depth = 1;
    $i = $at + 10;
    $n = strlen( $source );
    $quote = '';
    for ( ; $i < $n && $depth > 0; $i++ ) {
      $c = $source[ $i ];
      if ( $quote !== '' ) {
        if ( $c === '\\' ) {
          $i++;
        }
        elseif ( $c === $quote ) {
          $quote = '';
        }
        continue;
      }
      if ( $c === "'" || $c === '"' ) {
        $quote = $c;
      }
      elseif ( $c === '(' ) {
        $depth++;
      }
      elseif ( $c === ')' ) {
        $depth--;
      }
    }
    $statement = substr( $source, $at + 10, $i - $at - 11 );
    // Cut every wrapped call, innermost parentheses included.
    while ( ( $w = strpos( $statement, 'log_text(' ) ) !== false ) {
      $d = 1;
      $j = $w + 9;
      $q = '';
      for ( ; $j < strlen( $statement ) && $d > 0; $j++ ) {
        $c = $statement[ $j ];
        if ( $q !== '' ) {
          if ( $c === '\\' ) {
            $j++;
          }
          elseif ( $c === $q ) {
            $q = '';
          }
          continue;
        }
        if ( $c === "'" || $c === '"' ) {
          $q = $c;
        }
        elseif ( $c === '(' ) {
          $d++;
        }
        elseif ( $c === ')' ) {
          $d--;
        }
      }
      $statement = substr( $statement, 0, $w ) . substr( $statement, $j );
    }
    // Literal text is not a value: blank every quoted string.
    $bare = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $statement );
    $head = trim( (string) preg_replace( '/\s+/', ' ', substr( $statement, 0, 80 ) ) );
    $exempt = strpos( $head, '\'[Guarded MCP] the audit log is full (\' . ( $reason === \'bytes\'' ) === 0;
    if ( preg_match( $clientish, (string) $bare ) && !$exempt ) {
      $found[] = trim( preg_replace( '/\s+/', ' ', substr( $statement, 0, 110 ) ) );
    }
  }
  return $found;
}
check( 'CONTROL: the lint flags an unwrapped request-derived value', count( error_log_violations( "<?php error_log( 'x ' . \$reason );" ) ), 1 );
check( 'CONTROL: the lint flags an unwrapped exception message', count( error_log_violations( "<?php error_log( 'x ' . \$e->getMessage() );" ) ), 1 );
check( 'CONTROL: the lint accepts the same value through log_text()', count( error_log_violations( "<?php error_log( 'x ' . GMCP_Core::log_text( \$reason ) );" ) ), 0 );
check( 'CONTROL: the lint accepts text and counters', count( error_log_violations( "<?php error_log( '[x] ' . count( \$tools ) . ' tools' );" ) ), 0 );
$statements = 0;
$violations = [];
foreach ( glob( __DIR__ . '/../includes/*.php' ) as $file ) {
  $source = (string) file_get_contents( $file );
  $statements += substr_count( $source, 'error_log(' );
  foreach ( error_log_violations( $source ) as $v ) {
    $violations[] = basename( $file ) . ': ' . $v;
  }
}
check( 'CONTROL: the lint saw the plugin\'s error_log() calls (it can find a present one)', $statements > 40, true );
check( 'no error_log() in includes/ prints request-derived text without log_text()', $violations, [] );

echo "\n$total checks, $fails failed\n";
exit( min( 255, $fails ) );
