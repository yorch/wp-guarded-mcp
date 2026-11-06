<?php
/**
* Rebuilds includes/meta-key-fold-map.php from a live MariaDB or MySQL server. Not shipped.
*
*   GMCP_FOLD_DSN='mysql:host=127.0.0.1;port=33310' GMCP_FOLD_USER=root GMCP_FOLD_PASS= \
*     php .dev/build-meta-key-fold.php > includes/meta-key-fold-map.php
*
* WHY THE MAP IS MEASURED AND NOT WRITTEN FROM THE UNICODE TABLES. A meta key is compared by the
* wp_postmeta.meta_key column's collation, and utf8mb4_unicode_520_ci (WordPress's default) is
* accent-insensitive, case-insensitive, ignores some characters altogether and expands others
* ("ß" is "ss"). So update_metadata() filed a write for "_thumbnaíl_id" under the existing
* "_thumbnail_id" row. What a collation treats as equal is a property of the server's build of
* it, and it differs between servers, so this asks the server: it loads every code point into
* a table, then for each collation lists the code points that compare equal to a string of 1 to 3
* characters over [a-z0-9_-], the code points that compare equal to nothing (ignorable in the
* middle of a key), and the ones that compare equal to a space (and so are trimmed from the end).
* A code point that two collations disagree on goes into the "alt" table as an alternative
* spelling, never into the main map.
*
* The collations are the ones a WordPress install ends up with: utf8mb4_unicode_520_ci (core's
* default), utf8mb4_unicode_ci, utf8mb4_general_ci (a stock MariaDB server's default) and
* uca1400_ai_ci (MariaDB 11's). MySQL 8's utf8mb4_0900_ai_ci cannot be built by MariaDB; the fold
* covers it with the generic steps in GMCP_Meta_Key (decomposition, accent removal, lower
* case), which only over-fold. Over-folding is the safe direction: it can refuse a key a
* database would have kept apart, never admit one it would not.
*
* Needs the PDO MySQL driver and the right to create a scratch database, which it drops.
*/

if ( PHP_SAPI !== 'cli' ) {
  exit( 1 );
}

$dsn = getenv( 'GMCP_FOLD_DSN' ) ?: 'mysql:host=127.0.0.1;port=3306';
$pdo = new PDO( $dsn, getenv( 'GMCP_FOLD_USER' ) ?: 'root', getenv( 'GMCP_FOLD_PASS' ) ?: '', [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
$db = 'gmcp_fold_build';
$pdo->exec( "DROP DATABASE IF EXISTS $db" );
$pdo->exec( "CREATE DATABASE $db CHARACTER SET utf8mb4" );
$pdo->exec( "USE $db" );
$pdo->exec( 'SET NAMES utf8mb4' );

// Every scalar value but the surrogates, stored byte-exact.
$pdo->exec( 'CREATE TABLE cp ( cp INT PRIMARY KEY, s VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin ) ENGINE=InnoDB' );
$batch = [];
for ( $cp = 0; $cp <= 0x10FFFF; $cp++ ) {
  if ( $cp >= 0xD800 && $cp <= 0xDFFF ) {
    continue;
  }
  $batch[] = '(' . $cp . ",CONVERT(0x" . bin2hex( mb_chr( $cp, 'UTF-8' ) ) . ' USING utf8mb4))';
  if ( count( $batch ) >= 4000 ) {
    $pdo->exec( 'INSERT INTO cp VALUES ' . implode( ',', $batch ) );
    $batch = [];
  }
}
if ( $batch ) {
  $pdo->exec( 'INSERT INTO cp VALUES ' . implode( ',', $batch ) );
}

$collations = [ 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'uca1400_ai_ci' ];
$eq = [];      // collation => cp => target
$middle = [];  // cp => true, ignorable between two characters
$space = [];   // cp => true, equal to a space, so trimmed from the end
$used = [];
foreach ( $collations as $collation ) {
  $exists = $pdo->query( "SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = '$collation'" )->fetchColumn();
  if ( !$exists ) {
    fwrite( STDERR, "skipping $collation: not on this server\n" );
    continue;
  }
  $used[] = $collation;
  $pdo->exec( 'DROP TABLE IF EXISTS cpc' );
  $pdo->exec( "CREATE TABLE cpc ( cp INT PRIMARY KEY, s VARCHAR(8) CHARACTER SET utf8mb4 COLLATE $collation, KEY(s) ) ENGINE=InnoDB" );
  $pdo->exec( 'INSERT INTO cpc SELECT cp, s FROM cp' );
  $pdo->exec( 'DROP TABLE IF EXISTS tg' );
  $pdo->exec( "CREATE TABLE tg ( t VARCHAR(4) CHARACTER SET utf8mb4 COLLATE $collation, KEY(t) ) ENGINE=InnoDB" );
  $alphabet = array_merge( range( 'a', 'z' ), range( '0', '9' ), [ '_', '-' ] );
  $rows = [];
  foreach ( $alphabet as $a ) {
    $rows[] = "('$a')";
  }
  $pdo->exec( 'INSERT INTO tg VALUES ' . implode( ',', $rows ) );
  $pdo->exec( "INSERT INTO tg SELECT CONCAT( a.t, b.t ) FROM tg a, tg b WHERE LENGTH( a.t ) = 1 AND LENGTH( b.t ) = 1" );
  $pdo->exec( "INSERT INTO tg SELECT CONCAT( a.t, b.t ) FROM tg a, tg b WHERE LENGTH( a.t ) = 2 AND LENGTH( b.t ) = 1" );
  // Shortest target first; a code point equal to several strings keeps the shortest.
  $stmt = $pdo->query( 'SELECT c.cp, tg.t FROM cpc c JOIN tg ON c.s = tg.t ORDER BY c.cp, LENGTH( tg.t )' );
  foreach ( $stmt as $row ) {
    $eq[ $collation ][ (int) $row[0] ] = $eq[ $collation ][ (int) $row[0] ] ?? $row[1];
  }
  foreach ( $pdo->query( "SELECT cp FROM cpc WHERE CONCAT( 'a', s, 'b' ) = 'ab' AND CONCAT( 'a', s, s, 'b' ) = 'ab'" ) as $row ) {
    $middle[ (int) $row[0] ] = true;
  }
  foreach ( $pdo->query( "SELECT cp FROM cpc WHERE CONCAT( 'a', s ) = 'a'" ) as $row ) {
    if ( !isset( $middle[ (int) $row[0] ] ) ) {
      $space[ (int) $row[0] ] = true;
    }
  }
}
// A code point ignorable in one collation and a space-trim in another is only a trim.
foreach ( array_keys( $space ) as $cp ) {
  unset( $middle[ $cp ] );
}
$version = (string) $pdo->query( 'SELECT VERSION()' )->fetchColumn();
$pdo->exec( "DROP DATABASE $db" );

// The main map is the first collation's; the others add what it lacks and disagreements become alternatives.
$map = [];
$alt = [];
foreach ( $used as $collation ) {
  foreach ( $eq[ $collation ] ?? [] as $cp => $target ) {
    if ( isset( $middle[ $cp ] ) || isset( $space[ $cp ] ) ) {
      continue;
    }
    if ( !isset( $map[ $cp ] ) ) {
      $map[ $cp ] = $target;
    }
    elseif ( $map[ $cp ] !== $target ) {
      $alt[ $cp ] = $target;
    }
  }
}

function gmcp_ranges( array $cps ): string {
  sort( $cps );
  $out = [];
  $start = $prev = null;
  foreach ( $cps as $cp ) {
    if ( $start === null ) {
      $start = $prev = $cp;
    }
    elseif ( $cp === $prev + 1 ) {
      $prev = $cp;
    }
    else {
      $out[] = $start === $prev ? dechex( $start ) : dechex( $start ) . '-' . dechex( $prev );
      $start = $prev = $cp;
    }
  }
  if ( $start !== null ) {
    $out[] = $start === $prev ? dechex( $start ) : dechex( $start ) . '-' . dechex( $prev );
  }
  return implode( ' ', $out );
}

// Range compaction needs consecutive code points with the SAME target, so group by target.
$by_target = [];
foreach ( $map as $cp => $target ) {
  $by_target[ $target ][] = $cp;
}
ksort( $by_target );

echo "<?php\n";
echo "/**\n";
echo "* GENERATED by .dev/build-meta-key-fold.php from a live server. Do not edit by hand; rebuild.\n";
echo "*\n";
echo "* What the meta_key column's collations treat as equal to [a-z0-9_-], measured over every Unicode\n";
echo "* scalar value (see GMCP_Meta_Key and the generator for how and why).\n";
echo "*\n";
echo "* Built from: " . implode( ', ', $used ) . "\n";
echo "* Server: " . $version . "\n";
echo "*/\n\n";
echo "if ( !defined( 'ABSPATH' ) ) {\n  exit;\n}\n\n";
echo "return [\n";
echo "  // Code points a key may contain anywhere without changing what the database compares.\n";
echo "  'ignore' => '" . gmcp_ranges( array_keys( $middle ) ) . "',\n";
echo "  // Code points equal to a space: they compare as one, and trailing ones are not compared at all.\n";
echo "  'space' => '" . gmcp_ranges( array_keys( $space ) ) . "',\n";
echo "  // Code points equal to the string on the left, as hexadecimal ranges.\n";
echo "  'map' => [\n";
foreach ( $by_target as $target => $cps ) {
  echo "    '" . $target . "' => '" . gmcp_ranges( $cps ) . "',\n";
}
echo "  ],\n";
echo "  // Where collations disagree: code point => the other spelling (the main map holds the first).\n";
echo "  'alt' => [\n";
foreach ( $alt as $cp => $target ) {
  echo '    ' . $cp . " => '" . $target . "',\n";
}
echo "  ],\n";
echo "];\n";
fwrite( STDERR, sprintf( "map %d code points, ignore %d, space %d, alt %d\n", count( $map ), count( $middle ), count( $space ), count( $alt ) ) );
