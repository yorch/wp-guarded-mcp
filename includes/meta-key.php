<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The spelling a meta key has when the database compares it, so a policy about a key can be asked
* about the key as the database will see it and not as the caller typed it.
*
* WHY. wp_postmeta.meta_key is compared by its column's collation, utf8mb4_unicode_520_ci on a
* stock WordPress: accent-insensitive, case-insensitive, blind to some characters altogether
* (combining marks, soft hyphens, zero-width and control characters), expanding others ("ß" is
* "ss", "æ" is "ae") and ignoring trailing spaces. So update_post_meta( $id, '_thumbnaíl_id', 9 )
* finds the existing "_thumbnail_id" row and writes it, and a policy that judged the key by
* strtolower( rtrim() ) saw a different key and let it through. Measured on MariaDB 10.11 with a
* readwrite key: wp_update_post_meta on "_thumbnail_id" was refused, on "_thumbnaíl_id" it was
* stored, and the page then showed a form submission's upload as its featured image. The same fold
* reaches the _menu_item_* pointers and the _wp_trash_meta_ keys, whose refusal is what keeps a
* write key from scheduling a deletion. SQLite compares exactly, which is why a suite run there
* never sees it.
*
* Resolving the key through the database (find the row it would hit) is not enough: with no row
* yet, the write creates one under the accented spelling, and every later query that asks for
* "_thumbnail_id" (a WP_Query meta_key, an admin screen) matches it through the same collation.
* So the policy judges the folded spelling, whether or not a row exists.
*
* HOW. The table in meta-key-fold-map.php was measured, not written from the Unicode tables: the
* generator loads every code point into a scratch table on a live server and lists what each of
* the collations a WordPress install uses treats as equal to a string over [a-z0-9_-], what it
* treats as nothing, and what it treats as a space. Rebuild with .dev/build-meta-key-fold.php.
* Code points the table does not know go through the generic steps (compatibility decomposition,
* accent removal, lower case), which also covers collations the generator could not build (MySQL
* 8's utf8mb4_0900_ai_ci). Every step errs toward folding more: a refusal of a key the database
* would have kept apart costs an odd key, an admission of one it would not costs the guard.
*
* folds() returns one spelling per disagreement between collations (today only "ß": "ss" in the
* Unicode collations and "s" in utf8mb4_general_ci). A policy asks whether ANY of them is the
* protected key.
*/
class GMCP_Meta_Key {

  /** @var array{ignore:array<int,true>,space:array<int,true>,map:array<int,string>,alt:array<int,string>}|null */
  private static $tables = null;

  /**
  * Every spelling the database may compare this key as, lower case, the first being the main one.
  *
  * @param mixed $key
  * @return string[] Empty for a key that is not a string.
  */
  public static function folds( $key ): array {
    if ( !is_string( $key ) ) {
      return [];
    }
    // Printable ASCII is the whole story for nearly every key: case and trailing space.
    if ( preg_match( '/^[\x20-\x7e]*$/', $key ) === 1 ) {
      return [ strtolower( rtrim( $key ) ) ];
    }
    $tables = self::tables();
    if ( !mb_check_encoding( $key, 'UTF-8' ) ) {
      // Invalid UTF-8 is replaced by the database or refused by it; fold what survives.
      $key = (string) mb_convert_encoding( $key, 'UTF-8', 'UTF-8' );
    }
    $points = preg_split( '//u', $key, -1, PREG_SPLIT_NO_EMPTY );
    if ( !is_array( $points ) ) {
      $points = str_split( $key );
    }
    $main = '';
    $alt = '';
    $has_alt = false;
    foreach ( $points as $char ) {
      $cp = mb_ord( $char, 'UTF-8' );
      $piece = self::fold_point( $cp, $char, $tables, false );
      $main .= $piece;
      if ( isset( $tables['alt'][ $cp ] ) ) {
        $has_alt = true;
        $alt .= $tables['alt'][ $cp ];
      }
      else {
        $alt .= $piece;
      }
    }
    $out = [ self::trim_end( $main ) ];
    if ( $has_alt ) {
      $out[] = self::trim_end( $alt );
    }
    return array_values( array_unique( $out ) );
  }

  /** The main folded spelling. */
  public static function fold( $key ): string {
    $folds = self::folds( $key );
    return $folds ? $folds[0] : '';
  }

  /** Whether the database may compare this key as exactly $name (given lower case). */
  public static function is( $key, string $name ): bool {
    return in_array( $name, self::folds( $key ), true );
  }

  /** Whether the database may compare this key as one that begins with $prefix (lower case). */
  public static function starts_with( $key, string $prefix ): bool {
    foreach ( self::folds( $key ) as $fold ) {
      if ( strncmp( $fold, $prefix, strlen( $prefix ) ) === 0 ) {
        return true;
      }
    }
    return false;
  }

  /** Trailing space-like characters are not compared (the collations pad with spaces). */
  private static function trim_end( string $folded ): string {
    return rtrim( $folded, " \t\n\r\0\x0B" );
  }

  /**
  * One code point's contribution: nothing, a space, the string the measured table gives it, or the
  * generic fold of an unknown one.
  */
  private static function fold_point( int $cp, string $char, array $tables, bool $nested ): string {
    if ( isset( $tables['ignore'][ $cp ] ) ) {
      return '';
    }
    if ( isset( $tables['space'][ $cp ] ) ) {
      return ' ';
    }
    if ( isset( $tables['map'][ $cp ] ) ) {
      return $tables['map'][ $cp ];
    }
    if ( $cp < 0x80 ) {
      return strtolower( $char );
    }
    if ( $nested ) {
      return mb_strtolower( $char, 'UTF-8' );
    }
    // Not measured. Decompose (so "í" becomes "i" plus a mark, and a fullwidth or ligature form its
    // plain letters), drop the marks, and let WordPress's own table take what is left.
    $text = $char;
    if ( class_exists( 'Normalizer' ) ) {
      $norm = Normalizer::normalize( $char, Normalizer::FORM_KD );
      if ( is_string( $norm ) && $norm !== '' ) {
        $text = $norm;
      }
    }
    if ( function_exists( 'remove_accents' ) ) {
      $text = remove_accents( $text );
    }
    $out = '';
    $parts = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
    foreach ( is_array( $parts ) ? $parts : [ $text ] as $part ) {
      $part_cp = mb_ord( $part, 'UTF-8' );
      if ( $part_cp === $cp ) {
        $out .= mb_strtolower( $part, 'UTF-8' );
      }
      else {
        $out .= self::fold_point( $part_cp, $part, $tables, true );
      }
    }
    return $out;
  }

  /** The measured table, expanded once. */
  private static function tables(): array {
    if ( self::$tables !== null ) {
      return self::$tables;
    }
    $raw = require __DIR__ . '/meta-key-fold-map.php';
    $expand = static function ( string $ranges ): array {
      $out = [];
      foreach ( preg_split( '/\s+/', trim( $ranges ), -1, PREG_SPLIT_NO_EMPTY ) as $range ) {
        $bounds = explode( '-', $range );
        $from = hexdec( $bounds[0] );
        $to = isset( $bounds[1] ) ? hexdec( $bounds[1] ) : $from;
        for ( $cp = $from; $cp <= $to; $cp++ ) {
          $out[ $cp ] = true;
        }
      }
      return $out;
    };
    $map = [];
    foreach ( $raw['map'] as $target => $ranges ) {
      foreach ( array_keys( $expand( $ranges ) ) as $cp ) {
        $map[ $cp ] = (string) $target;
      }
    }
    self::$tables = [
      'ignore' => $expand( $raw['ignore'] ),
      'space' => $expand( $raw['space'] ),
      'map' => $map,
      'alt' => $raw['alt'],
    ];
    return self::$tables;
  }
}
