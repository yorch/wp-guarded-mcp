<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Whether free TEXT carries a credential, as opposed to a structure with a secret-named field.
*
* GMCP_Core::holds_credential() judges names: it opens arrays, objects, JSON and serialized
* strings and asks whether any field is called something secret. A post body is none of those.
* It is prose with, now and then, a pasted wp-config line, a JSON settings snippet or an
* Authorization header in it, and a string that does not begin with "{" or "[" was never opened,
* so the body went into the journal and the audit log whole. This is the check for that shape.
*
* It is a heuristic and says so. It finds the shapes people actually paste, not every secret:
* a password that is a bare word with no assignment around it is not recognisable as one.
* What it is built to avoid is the opposite failure, because a false positive here costs an
* undo, and the whole value of the journal is that an ordinary edit can be put back. So prose
* that merely uses the word "password" is not flagged, a block attribute called "authorId" or
* "keywords" is not flagged, and a value is judged by the name it sits under AND by what it
* looks like.
*
* Pure: no WordPress call. The one outside dependency is GMCP_Core::field_looks_secret(), asked
* for names that are only weakly secret-shaped so that the filterable list stays the single list.
*
* Failing closed: a PCRE error (backtrack or JIT limit) answers "yes, credential", never "no".
*/
final class GMCP_Credential_Scan {

  /** Past this depth an array is refused, matching GMCP_Core::holds_credential(). */
  const MAX_DEPTH = 64;

  /** Last name segments that name a secret outright. */
  const STRONG_LAST = [
    'password', 'passwd', 'passphrase', 'pass', 'pwd', 'secret', 'secrets', 'token', 'credential',
    'credentials', 'salt', 'bearer', 'apikey', 'secretkey', 'accesskey', 'privatekey', 'authkey',
    'authorization',
  ];

  /** A name ending in "key" is a secret when the segment before it says what kind of key. */
  const KEY_QUALIFIERS = [
    'api', 'secret', 'access', 'private', 'auth', 'license', 'licence', 'encryption', 'signing',
    'client', 'app', 'master', 'site', 'secure', 'in', 'nonce', 'consumer', 'webhook',
  ];

  /**
  * How secret-shaped a field name is: 2 for a name that is a secret outright (DB_PASSWORD,
  * api_key, client_secret, accessToken), 1 for one that merely contains a credential pattern
  * (author, keywords, monkey: judged by GMCP_Core), 0 for neither.
  *
  * The LAST segment decides, so "passwordLabel" and "password_placeholder" are labels and
  * "DB_PASSWORD" is a password. camelCase is split first.
  */
  public static function name_strength( string $name ): int {
    $snake = (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $name );
    $segments = preg_split( '/[^a-z0-9]+/', strtolower( $snake ), -1, PREG_SPLIT_NO_EMPTY );
    if ( !$segments ) {
      return 0;
    }
    $last = end( $segments );
    $before = count( $segments ) > 1 ? $segments[ count( $segments ) - 2 ] : '';
    if ( in_array( $last, self::STRONG_LAST, true ) ) {
      return 2;
    }
    if ( $last === 'key' && in_array( $before, self::KEY_QUALIFIERS, true ) ) {
      return 2;
    }
    if ( class_exists( 'GMCP_Core' ) && GMCP_Core::field_looks_secret( $name ) ) {
      return 1;
    }
    return 0;
  }

  /** Whether a value found under a name is credential-shaped, given how secret the name is. */
  private static function value_fits( int $strength, string $value, bool $quoted ): bool {
    $value = trim( $value );
    if ( $value === '' || strtolower( $value ) === '[redacted]' ) {
      return false;
    }
    $len = strlen( $value );
    $token = (bool) preg_match( '/^[A-Za-z0-9_\-.\/+=:@$!#%^*~]+$/', $value );
    $digit = (bool) preg_match( '/\d/', $value );
    $alpha = (bool) preg_match( '/[A-Za-z]/', $value );
    if ( $strength === 2 ) {
      // Quoted: an assignment of anything non-trivial. Unquoted ("DB_PASSWORD=...", a query
      // string) needs the shape of a token too, or "password=required" in prose would count.
      return $quoted ? $len >= 4 : ( $token && $len >= 6 && ( $digit || $len >= 16 ) );
    }
    // A weak name (author, keywords) holds a credential only when the value looks like one.
    return $token && $len >= 16 && $digit && $alpha;
  }

  /**
  * Entities and escapes that stand for a quote, undone, so an attribute written as
  * &quot;api_key&quot; or \"api_key\" or \u0022api_key\u0022 reads like the plain form.
  */
  private static function unescape_quotes( string $text ): string {
    if ( strpos( $text, '&' ) === false && strpos( $text, '\\' ) === false ) {
      return $text;
    }
    return strtr( $text, [
      '&quot;' => '"', '&#34;' => '"', '&#x22;' => '"', '&#039;' => "'", '&#39;' => "'", '&#x27;' => "'",
      '&apos;' => "'", '\\u0022' => '"', '\\u0027' => "'", '\\"' => '"', "\\'" => "'",
    ] );
  }

  /** True when the text holds something credential-shaped. Strings only; see holds(). */
  public static function text_holds( string $text ): bool {
    if ( $text === '' ) {
      return false;
    }
    $text = self::unescape_quotes( $text );

    // Shapes that identify themselves, whatever surrounds them.
    $fixed = '/-----BEGIN [A-Z ]{0,20}PRIVATE KEY-----'
      . '|(?i:\bBearer\s+[A-Za-z0-9._~+\/=-]{16,})'
      . '|(?i:\bAuthorization\s*[:=]\s*["\']?(?:Basic|Bearer|Token|Digest)\s+[A-Za-z0-9._~+\/=-]{8,})'
      . '|\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}'
      . '|\bsk-[A-Za-z0-9_-]{20,}'
      . '|\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}'
      . '|\b(?:AKIA|ASIA)[A-Z0-9]{16}\b'
      . '|\bxox[abprs]-[A-Za-z0-9-]{10,}'
      . '|\bAIza[0-9A-Za-z_-]{30,}'
      . '|\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*'
      . '|\bgmcp_[A-Za-z0-9]{4,}_[A-Za-z0-9]{16,}'
      . '|(?i:\b[a-z][a-z0-9+.-]*:\/\/)[^\/\s:@<>"\']+:[^\/\s@<>"\']+@/';
    $hit = preg_match( $fixed, $text );
    if ( $hit === false || $hit === 1 ) {
      return true;
    }

    // name => "value", "name": "value", name = 'value', $name = "value".
    $found = preg_match_all(
      '/(?<![A-Za-z0-9_])\$?["\']?([A-Za-z_][A-Za-z0-9_.\-]{1,63})["\']?[ \t]*(?:=>|:|=)[ \t]*(["\'])((?:(?!\2)[^\r\n\\\\]){1,512})\2/',
      $text, $m, PREG_SET_ORDER
    );
    if ( $found === false ) {
      return true;
    }
    foreach ( $m as $one ) {
      $strength = self::name_strength( $one[1] );
      if ( $strength > 0 && self::value_fits( $strength, $one[3], true ) ) {
        return true;
      }
    }

    // define( 'DB_PASSWORD', 'value' ): the comma is the separator, so the rule above misses it.
    $found = preg_match_all(
      '/\bdefine\s*\(\s*(["\'])([A-Za-z0-9_]{1,64})\1\s*,\s*(["\'])((?:(?!\3)[^\r\n\\\\]){1,512})\3/i',
      $text, $m, PREG_SET_ORDER
    );
    if ( $found === false ) {
      return true;
    }
    foreach ( $m as $one ) {
      $strength = self::name_strength( $one[2] );
      if ( $strength > 0 && self::value_fits( $strength, $one[4], true ) ) {
        return true;
      }
    }

    // DB_PASSWORD=value and ?token=value: an unquoted assignment, secret-named outright only.
    $found = preg_match_all(
      '/(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]{1,63})[ \t]*=[ \t]*([^\s<>"\'&;,)]{6,})/',
      $text, $m, PREG_SET_ORDER
    );
    if ( $found === false ) {
      return true;
    }
    foreach ( $m as $one ) {
      if ( self::name_strength( $one[1] ) === 2 && self::value_fits( 2, $one[2], false ) ) {
        return true;
      }
    }
    return false;
  }

  /**
  * Whether a value holds a credential: the structural test GMCP_Core::holds_credential() makes,
  * plus the text test on every string in it. Arrays and objects are walked.
  *
  * @param int $window When positive, only the first $window bytes of a string are scanned.
  *                    For a caller that can show no more than that much of it.
  */
  public static function holds( $value, int $depth = 0, int $window = 0 ): bool {
    if ( $depth === 0 && class_exists( 'GMCP_Core' ) && GMCP_Core::holds_credential( $value, 0, self::MAX_DEPTH ) ) {
      return true;
    }
    if ( $depth > self::MAX_DEPTH ) {
      return true;
    }
    if ( is_string( $value ) ) {
      return self::text_holds( $window > 0 && strlen( $value ) > $window ? substr( $value, 0, $window ) : $value );
    }
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) ) {
      return false;
    }
    foreach ( $value as $inner ) {
      if ( self::holds( $inner, $depth + 1, $window ) ) {
        return true;
      }
    }
    return false;
  }
}
