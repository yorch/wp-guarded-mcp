<?php
/**
* Tests GMCP_Meta_Key, the fold every meta-key policy asks. Not shipped.
*
*   php .dev/test-meta-key-fold.php
*
* Runs without WordPress. With GMCP_FOLD_DSN (and GMCP_FOLD_USER / GMCP_FOLD_PASS) set it also
* checks the fold against a live MariaDB or MySQL server, in both directions:
*
*   soundness     a key and its fold compare EQUAL under the meta_key collation, for thousands of
*                 random keys built from the characters the map says are equivalent. A fold that
*                 reports a protected name for a key the database would keep apart fails here.
*   completeness  a protected name with each of its characters swapped for a random equivalent
*                 spelling, ignorable characters sprinkled in and spaces appended, which the
*                 database still compares equal to the name, folds back to it. A variant the
*                 fold misses is the bypass this class exists to close.
*
* Exit status is the number of failed checks, capped at 255.
*/

define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/meta-key.php';

$pass = 0;
$fail = 0;
function mk_check( string $label, $actual, $expected ): void {
  global $pass, $fail;
  if ( $actual === $expected ) {
    $pass++;
    return;
  }
  $fail++;
  echo '  FAIL  ' . $label . ' (got ' . var_export( $actual, true ) . ', want ' . var_export( $expected, true ) . ")\n";
}

$protected = [ '_thumbnail_id', '_menu_item_object_id', '_menu_item_type', '_menu_item_object', '_wp_trash_meta_time', '_wp_trash_meta_status' ];

// Plain keys are the case and trailing space fold, and nothing else.
mk_check( 'ascii lowercases', GMCP_Meta_Key::fold( '_THUMBNAIL_ID' ), '_thumbnail_id' );
mk_check( 'ascii trailing spaces go', GMCP_Meta_Key::fold( '_thumbnail_id   ' ), '_thumbnail_id' );
mk_check( 'ascii leading space stays (the database compares it)', GMCP_Meta_Key::fold( ' _thumbnail_id' ), ' _thumbnail_id' );
mk_check( 'ascii inner space stays', GMCP_Meta_Key::fold( 'a b' ), 'a b' );
mk_check( 'empty key', GMCP_Meta_Key::fold( '' ), '' );
mk_check( 'a non-string has no fold', GMCP_Meta_Key::folds( 12 ), [] );

// The reviewer's attack, and its relatives. Each of these is "_thumbnail_id" to utf8mb4_unicode_520_ci.
$thumb_variants = [
  "_thumbna\u{00ED}l_id",           // i with acute
  "_thumbnai\u{0301}l_id",          // i + combining acute
  "_thumb\u{200B}nail_id",          // zero width space
  "_thumb\u{00AD}nail_id",          // soft hyphen
  "_th\u{00FC}mbnail_id",           // u with diaeresis
  "\u{FF3F}thumbnail_id",           // fullwidth low line
  "_thumbnail_\u{FF49}d",           // fullwidth i
  "_thumbnail_\u{0131}d",           // dotless i
  "_\u{1D5E3}humbnail_id",          // a mathematical sans-serif P, not a t: a control, below
  "_thumbnail_id \u{00A0}",         // trailing no-break space
  "_thumbnail_id\u{0000}",          // NUL
  "_thumbnail_id\x01\x02",          // control characters
  "_THUMBNA\u{00CD}L_ID",           // upper case with accent
  "_thumb\u{0000}nail_id",
];
foreach ( $thumb_variants as $variant ) {
  $is = GMCP_Meta_Key::is( $variant, '_thumbnail_id' );
  // One of the entries above is a control that must NOT fold to the name.
  if ( $variant === "_\u{1D5E3}humbnail_id" ) {
    mk_check( 'control, not the same key: ' . bin2hex( $variant ), $is, false );
  }
  else {
    mk_check( 'folds to _thumbnail_id: ' . bin2hex( $variant ), $is, true );
  }
}

// The deletion schedule, where the accent sits inside the prefix the old check compared bytes of.
mk_check( 'prefix with an accent in it', GMCP_Meta_Key::starts_with( "_wp_tr\u{00E1}sh_meta_time", '_wp_trash_meta_' ), true );
mk_check( 'prefix with a combining mark', GMCP_Meta_Key::starts_with( "_wp_trash\u{0301}_meta_time", '_wp_trash_meta_' ), true );
mk_check( 'prefix, shouted', GMCP_Meta_Key::starts_with( '_WP_TRASH_META_TIME', '_wp_trash_meta_' ), true );
mk_check( 'prefix, sharp s read as "s" by utf8mb4_general_ci', GMCP_Meta_Key::starts_with( "_wp_tra\u{00DF}h_meta_time", '_wp_trash_meta_' ), true );
mk_check( 'sharp s has both spellings', GMCP_Meta_Key::folds( "a\u{00DF}" ), [ 'ass', 'as' ] );
mk_check( 'control: an ordinary key is not that prefix', GMCP_Meta_Key::starts_with( '_wp_trash_time', '_wp_trash_meta_' ), false );
mk_check( 'control: leading space is a different key', GMCP_Meta_Key::starts_with( ' _wp_trash_meta_time', '_wp_trash_meta_' ), false );

// Keys that merely contain accents are still themselves.
mk_check( 'cafe note', GMCP_Meta_Key::fold( "caf\u{00E9}_note" ), 'cafe_note' );
foreach ( $protected as $name ) {
  mk_check( 'control, unrelated accented key is not ' . $name, GMCP_Meta_Key::is( "caf\u{00E9}_note", $name ), false );
  mk_check( 'control, the name itself folds to itself: ' . $name, GMCP_Meta_Key::is( $name, $name ), true );
  mk_check( 'control, a longer key is not ' . $name, GMCP_Meta_Key::is( $name . '_x', $name ), false );
}

// A character the measured table does not have goes through the generic steps.
if ( class_exists( 'Normalizer' ) ) {
  mk_check( 'generic fold of a roman numeral', GMCP_Meta_Key::fold( "\u{2177}" ), 'viii' );
}
else {
  echo "  note  intl is absent, so the generic decomposition fold was not tested\n";
}

// The map against itself: every code point it lists folds to what it lists.
$raw = require __DIR__ . '/../includes/meta-key-fold-map.php';
$expand = static function ( string $ranges ): array {
  $out = [];
  foreach ( preg_split( '/\s+/', trim( $ranges ), -1, PREG_SPLIT_NO_EMPTY ) as $range ) {
    $b = explode( '-', $range );
    for ( $cp = hexdec( $b[0] ); $cp <= hexdec( $b[1] ?? $b[0] ); $cp++ ) {
      $out[] = $cp;
    }
  }
  return $out;
};
$mapped = 0;
$wrong = 0;
foreach ( $raw['map'] as $target => $ranges ) {
  foreach ( $expand( $ranges ) as $cp ) {
    $mapped++;
    if ( $cp === 0xDF ) {
      continue; // two spellings, checked above
    }
    if ( GMCP_Meta_Key::fold( 'x' . mb_chr( $cp ) . 'x' ) !== 'x' . $target . 'x' ) {
      $wrong++;
    }
  }
}
mk_check( 'the map lists a few thousand code points', $mapped > 2500, true );
mk_check( 'every mapped code point folds to its target', $wrong, 0 );
$ignored = $expand( $raw['ignore'] );
$leaks = 0;
foreach ( $ignored as $cp ) {
  if ( GMCP_Meta_Key::fold( 'a' . mb_chr( $cp ) . 'b' ) !== 'ab' ) {
    $leaks++;
  }
}
mk_check( 'the map lists over a thousand ignorable code points', count( $ignored ) > 1000, true );
mk_check( 'every ignorable code point vanishes from the middle of a key', $leaks, 0 );
mk_check( 'the table is in the file at the moment it was built from (alt)', $raw['alt'], [ 223 => 's' ] );

// Against the server that decides, when there is one.
$dsn = getenv( 'GMCP_FOLD_DSN' );
if ( $dsn ) {
  $pdo = new PDO( $dsn, getenv( 'GMCP_FOLD_USER' ) ?: 'root', getenv( 'GMCP_FOLD_PASS' ) ?: '', [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ] );
  $pdo->exec( 'SET NAMES utf8mb4' );
  mt_srand( 20260705 );
  $collations = [ 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ];
  if ( (int) $pdo->query( "SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = 'uca1400_ai_ci'" )->fetchColumn() ) {
    $collations[] = 'uca1400_ai_ci';
  }
  $rounds = 0;
  foreach ( $collations as $collation ) {
    $eq = $pdo->prepare( "SELECT CONVERT(? USING utf8mb4) COLLATE $collation = CONVERT(? USING utf8mb4) COLLATE $collation" );
    $nothing = $pdo->prepare( "SELECT CONCAT( 'a', CONVERT(? USING utf8mb4), 'b' ) COLLATE $collation = 'ab'" );
    // What THIS collation treats as equal. The map is a union over collations, so it over-folds for
    // any one of them by design; building variants from the union would test the over-fold, and
    // every collation would then "fail" soundness for characters it never claimed to equate.
    $equiv = [];
    $pairs = [];
    foreach ( $raw['map'] as $target => $ranges ) {
      foreach ( $expand( $ranges ) as $cp ) {
        $pairs[] = [ mb_chr( $cp ), (string) $target ];
      }
    }
    foreach ( $raw['alt'] as $cp => $target ) {
      $pairs[] = [ mb_chr( $cp ), (string) $target ];
    }
    foreach ( $pairs as [ $ch, $target ] ) {
      $eq->execute( [ $ch, $target ] );
      if ( (int) $eq->fetchColumn() === 1 ) {
        $equiv[ $target ][] = $ch;
      }
    }
    $ign = [];
    foreach ( $ignored as $cp ) {
      $nothing->execute( [ mb_chr( $cp ) ] );
      if ( (int) $nothing->fetchColumn() === 1 ) {
        $ign[] = mb_chr( $cp );
      }
    }
    $unsound = 0;
    $missed = 0;
    $samples = 0;
    for ( $i = 0; $i < 3000; $i++ ) {
      // Completeness: a protected name, spelled any way this collation says is equivalent.
      $name = $protected[ $i % count( $protected ) ];
      $variant = '';
      foreach ( str_split( $name ) as $ch ) {
        if ( mt_rand( 0, 2 ) === 0 && !empty( $equiv[ $ch ] ) ) {
          $variant .= $equiv[ $ch ][ mt_rand( 0, count( $equiv[ $ch ] ) - 1 ) ];
        }
        else {
          $variant .= $ch;
        }
        if ( $ign && mt_rand( 0, 5 ) === 0 ) {
          $variant .= $ign[ mt_rand( 0, count( $ign ) - 1 ) ];
        }
      }
      $variant .= str_repeat( ' ', mt_rand( 0, 2 ) );
      $eq->execute( [ $variant, $name ] );
      if ( (int) $eq->fetchColumn() === 1 ) {
        $samples++;
        if ( !GMCP_Meta_Key::is( $variant, $name ) ) {
          $missed++;
          if ( $missed <= 3 ) {
            echo '  miss  ' . $collation . ' ' . bin2hex( $variant ) . ' is ' . $name . " to the database\n";
          }
        }
      }
      // Soundness: the database agrees the key IS (at least) one of the spellings the fold gives it.
      $agreed = false;
      foreach ( GMCP_Meta_Key::folds( $variant ) as $fold ) {
        $eq->execute( [ $variant, $fold ] );
        if ( (int) $eq->fetchColumn() === 1 ) {
          $agreed = true;
          break;
        }
      }
      if ( !$agreed ) {
        $unsound++;
        if ( $unsound <= 3 ) {
          echo '  unsound  ' . $collation . ' ' . bin2hex( $variant ) . " is none of its folds to the database\n";
        }
      }
    }
    mk_check( $collation . ': the variants are mostly equal to the name in the database (the probe works)', $samples > 500, true );
    mk_check( $collation . ': no variant the database treats as a protected name escaped the fold', $missed, 0 );
    mk_check( $collation . ': every key equals one of its folds in the database', $unsound, 0 );
    $rounds++;
  }
  // Control for the control: a probe that compares the wrong things would pass the above for nothing.
  $eq = $pdo->prepare( "SELECT CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_520_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_520_ci" );
  $eq->execute( [ "_thumbna\u{00ED}l_id", '_thumbnail_id' ] );
  mk_check( 'db probe control: the accent attack key equals _thumbnail_id', (int) $eq->fetchColumn(), 1 );
  $eq->execute( [ '_thumbnail_ie', '_thumbnail_id' ] );
  mk_check( 'db probe control: a different key does not', (int) $eq->fetchColumn(), 0 );
  echo "  note  checked against the server in $rounds collations\n";
}
else {
  echo "  note  GMCP_FOLD_DSN is not set, so the fold was not checked against a database\n";
}

echo $pass . ' passed, ' . $fail . " failed\n";
exit( min( 255, $fail ) );
