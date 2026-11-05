<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Whether free TEXT carries a credential, as opposed to a structure with a secret-named field,
* and where in the text it is.
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
* looks like. A value that is plainly a placeholder (WordPress's own sample wp-config line,
* "YOUR_TOKEN_HERE", "********") is not a credential either: see is_placeholder().
*
* Two answers from one pass. text_holds() says whether there is anything, which is what the
* journal asks (a value it cannot keep whole it does not keep). spans() says where, which is what
* the audit log asks, so that it can blank the secret and keep the rest of the value: a short
* decoy that made the scanner answer "yes" used to wipe the whole of a post body from the log,
* script tag and all.
*
* Pure: no WordPress call. The one outside dependency is GMCP_Core::field_looks_secret(), asked
* for names that are only weakly secret-shaped so that the filterable list stays the single list.
*
* Failing closed: a PCRE error (backtrack or JIT limit) answers "yes, credential", never "no",
* and the span answer for it is the whole text. Every expression here is linear in the length
* of the text: the name classes are bounded or possessive, and a run of name characters is only
* ever started at its beginning. That is measured, not assumed, with the PCRE JIT on and off
* (.dev/test-redact-timing.php), because a quadratic scan of text an agent controls is a way to
* stop a call from being recorded at all.
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
  * Whole values that are what a sample or a template says where a secret goes, compared
  * case-insensitively. "password_here" is the line WordPress's own sample config ships with and
  * "put your unique phrase here" is the one beside the salts; "string" and "example" are what a
  * schema or a doc page writes. Short on purpose: every entry is a value that is then NOT
  * recorded as a credential, so each has to be something no one chooses as a secret.
  * The shapes in is_placeholder() are the other half of the list.
  */
  const PLACEHOLDER_VALUES = [ 'password_here', 'put your unique phrase here', 'string', 'example' ];

  /** @var int[] name => strength, per request. Bounded: a text full of distinct names must not grow it. */
  private static $strength_cache = [];

  /**
  * How secret-shaped a field name is: 2 for a name that is a secret outright (DB_PASSWORD,
  * api_key, client_secret, accessToken), 1 for one that merely contains a credential pattern
  * (author, keywords, monkey: judged by GMCP_Core), 0 for neither.
  *
  * The LAST segment decides, so "passwordLabel" and "password_placeholder" are labels and
  * "DB_PASSWORD" is a password. camelCase is split first.
  */
  public static function name_strength( string $name ): int {
    if ( isset( self::$strength_cache[ $name ] ) ) {
      return self::$strength_cache[ $name ];
    }
    $strength = self::name_strength_uncached( $name );
    if ( count( self::$strength_cache ) >= 512 ) {
      self::$strength_cache = [];
    }
    self::$strength_cache[ $name ] = $strength;
    return $strength;
  }

  private static function name_strength_uncached( string $name ): int {
    $snake = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $name );
    $segments = preg_split( '/[^a-z0-9]+/', strtolower( is_string( $snake ) ? $snake : $name ), -1, PREG_SPLIT_NO_EMPTY );
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

  /**
  * Whether a value is what a template writes where a secret goes, rather than a secret.
  *
  *  - one of PLACEHOLDER_VALUES;
  *  - "your_password", "YOUR_TOKEN_HERE", "your-api-key-here", "api_key_here": words, with no
  *    digit, that begin "your" or end "here" (a real secret that happens to start with "your_"
  *    carries digits, and is still flagged);
  *  - a run of three or more "*" or "x" ("********", "xxxxxxxx");
  *  - "<token>", "{{ token }}", "${DB_PASS}", "$DB_PASS": a template or shell substitution.
  *
  * Judged on the value alone, and only after the NAME has already said this is a credential
  * field, so a placeholder is not a way to hide a secret under another name.
  */
  public static function is_placeholder( string $value ): bool {
    $value = trim( $value );
    if ( $value === '' ) {
      return false;
    }
    if ( in_array( strtolower( $value ), self::PLACEHOLDER_VALUES, true ) ) {
      return true;
    }
    if ( preg_match( '/^(?:\*{3,}|[xX]{3,})$/', $value ) ) {
      return true;
    }
    if ( preg_match( '/^(?:<[^<>\s]{1,60}>|\{\{[^{}]{1,60}\}\}|\$\{[A-Za-z_][A-Za-z0-9_]{0,60}\}|\$[A-Za-z_][A-Za-z0-9_]{0,60})$/', $value ) ) {
      return true;
    }
    if ( preg_match( '/^[A-Za-z_ -]{1,80}$/', $value ) && preg_match( '/^your[ _-]|[ _-]here$/i', $value ) ) {
      return true;
    }
    return false;
  }

  /** Whether a value found under a name is credential-shaped, given how secret the name is. */
  private static function value_fits( int $strength, string $value, bool $quoted ): bool {
    $value = trim( $value );
    if ( $value === '' || strtolower( $value ) === '[redacted]' || self::is_placeholder( $value ) ) {
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

  /** Spellings of a quote that undo to the plain character, longest first. */
  const QUOTE_ESCAPES = [
    '&quot;' => '"', '&#x22;' => '"', '&#34;' => '"', '&#039;' => "'", '&#x27;' => "'", '&#39;' => "'",
    '&apos;' => "'", '\\u0022' => '"', '\\u0027' => "'", '\\"' => '"', "\\'" => "'",
  ];

  /**
  * Entities and escapes that stand for a quote, undone, so an attribute written as
  * &quot;api_key&quot; or \"api_key\" or \u0022api_key\u0022 reads like the plain form.
  *
  * Returns the plain text and the offsets needed to carry a position in it back to the text it
  * came from, which spans() needs to cut the right bytes out of the original. The offsets are
  * [ position in the plain text, matching position in the original ] at the start of the text
  * and after each replacement, in order.
  *
  * @return array{0:string,1:?array} [ plain, null when nothing was undone ]
  */
  private static function unescape_quotes( string $text ): array {
    if ( strpos( $text, '&' ) === false && strpos( $text, '\\' ) === false ) {
      return [ $text, null ];
    }
    $found = preg_match_all( '/&(?:quot|apos|#x22|#34|#039|#x27|#39);|\\\\u0022|\\\\u0027|\\\\["\']/', $text, $m, PREG_OFFSET_CAPTURE );
    if ( !$found ) {
      return [ $text, null ];
    }
    $plain = '';
    $map = [ [ 0, 0 ] ];
    $last = 0;
    foreach ( $m[0] as [ $token, $at ] ) {
      $plain .= substr( $text, $last, $at - $last ) . self::QUOTE_ESCAPES[ $token ];
      $last = $at + strlen( $token );
      $map[] = [ strlen( $plain ), $last ];
    }
    $plain .= substr( $text, $last );
    return [ $plain, $map ];
  }

  /** A position in the unescaped text, as the position in the original it stands for. */
  private static function original_offset( int $pos, ?array $map ): int {
    if ( $map === null ) {
      return $pos;
    }
    $lo = 0;
    $hi = count( $map ) - 1;
    while ( $lo < $hi ) {
      $mid = intdiv( $lo + $hi + 1, 2 );
      if ( $map[ $mid ][0] <= $pos ) {
        $lo = $mid;
      }
      else {
        $hi = $mid - 1;
      }
    }
    return $map[ $lo ][1] + ( $pos - $map[ $lo ][0] );
  }

  /**
  * Shapes that identify themselves, whatever surrounds them. \K moves the start of the match
  * past the words that label a secret, so what is blanked is the secret and not its label.
  * Possessive quantifiers and a lookbehind in place of \b keep every one of them linear.
  */
  const FIXED = '/-----BEGIN [A-Z ]{0,20}PRIVATE KEY-----'
    . '|(?i:\bBearer\s++)\K[A-Za-z0-9._~+\/=-]{16,}'
    . '|(?i:\b(?:Bearer|Basic)\s++)\K(?=[A-Za-z0-9._~+\/=-]*[0-9=+\/])[A-Za-z0-9._~+\/=-]{8,}'
    . '|(?i:\bAuthorization\s*+[:=]\s*+["\']?+(?:Basic|Bearer|Token|Digest)\s++)\K[A-Za-z0-9._~+\/=-]{8,}'
    . '|\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}'
    . '|\bsk-[A-Za-z0-9_-]{20,}'
    . '|\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}'
    . '|\b(?:AKIA|ASIA)[A-Z0-9]{16}\b'
    . '|\bxox[abprs]-[A-Za-z0-9-]{10,}'
    . '|\bAIza[0-9A-Za-z_-]{30,}'
    . '|\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*'
    . '|\bgmcp_[A-Za-z0-9]{4,}_[A-Za-z0-9]{16,}/';

  /** user:password@ in a URL; the match is the user and the password, as two groups. */
  const USERINFO = '/(?<![A-Za-z0-9+.\-])[a-z][a-z0-9+.\-]*+:\/\/\K([^\/\s:@<>"\']++):([^\/\s@<>"\']++)(?=@)/i';

  /** User names and passwords a documentation page puts in a URL; such a pair is not a credential. */
  const SAMPLE_URL_USERS = [ 'user', 'username', 'login', 'name' ];
  const SAMPLE_URL_PASSWORDS = [ 'pass', 'password', 'passwd', 'pwd', 'secret' ];

  /**
  * Where the credentials in a text are, as [ offset, length ] byte ranges of the TEXT GIVEN,
  * merged and in order. An empty array means none. $first stops at the first one found, which
  * is all text_holds() needs.
  *
  * What a range covers is the secret itself where the shape has a label (the quoted value after
  * "api_key":, the token after "Bearer", the user:password of a URL) and the whole match where
  * it has not (an "sk_live_..." key), so a caller that blanks the ranges leaves the field names
  * and everything around them readable.
  *
  * @return array|null null when PCRE gave up, which a caller must read as "yes, and everywhere".
  */
  private static function find( string $text, bool $first ): ?array {
    if ( $text === '' ) {
      return [];
    }
    [ $plain, $map ] = self::unescape_quotes( $text );
    $spans = [];
    $add = static function ( int $at, int $len ) use ( &$spans, $map ) {
      $from = self::original_offset( $at, $map );
      $to = self::original_offset( $at + $len, $map );
      $spans[] = [ $from, max( 0, $to - $from ) ];
    };

    $n = preg_match_all( self::FIXED, $plain, $m, PREG_OFFSET_CAPTURE );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m[0] as [ $str, $at ] ) {
      $len = strlen( $str );
      if ( strpos( $str, '-----BEGIN' ) === 0 ) {
        // A key is a block: from its first line to the end marker, or to the end of what we
        // were given when the text was cut before it.
        $end = strpos( $plain, '-----END ', $at );
        $close = $end === false ? false : strpos( $plain, '-----', $end + 9 );
        $len = $close === false ? strlen( $plain ) - $at : $close + 5 - $at;
      }
      $add( $at, $len );
      if ( $first ) {
        return $spans;
      }
    }

    $n = preg_match_all( self::USERINFO, $plain, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m as $one ) {
      if ( in_array( strtolower( $one[1][0] ), self::SAMPLE_URL_USERS, true ) && in_array( strtolower( $one[2][0] ), self::SAMPLE_URL_PASSWORDS, true ) ) {
        continue;
      }
      $add( $one[1][1], strlen( $one[1][0] ) + 1 + strlen( $one[2][0] ) );
      if ( $first ) {
        return $spans;
      }
    }

    // name => "value", "name": "value", name = 'value', $name = "value".
    $n = preg_match_all(
      '/(?<![A-Za-z0-9_])\$?["\']?([A-Za-z_][A-Za-z0-9_.\-]{1,63})["\']?[ \t]*(?:=>|:|=)[ \t]*(["\'])((?:(?!\2)[^\r\n\\\\]){1,512})\2/',
      $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m as $one ) {
      $strength = self::name_strength( $one[1][0] );
      if ( $strength > 0 && self::value_fits( $strength, $one[3][0], true ) ) {
        $add( $one[3][1], strlen( $one[3][0] ) );
        if ( $first ) {
          return $spans;
        }
      }
    }

    // define( 'DB_PASSWORD', 'value' ): the comma is the separator, so the rule above misses it.
    $n = preg_match_all(
      '/\bdefine\s*+\(\s*+(["\'])([A-Za-z0-9_]{1,64})\1\s*+,\s*+(["\'])((?:(?!\3)[^\r\n\\\\]){1,512})\3/i',
      $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m as $one ) {
      $strength = self::name_strength( $one[2][0] );
      if ( $strength > 0 && self::value_fits( $strength, $one[4][0], true ) ) {
        $add( $one[4][1], strlen( $one[4][0] ) );
        if ( $first ) {
          return $spans;
        }
      }
    }

    // DB_PASSWORD=value and ?token=value: an unquoted assignment, secret-named outright only.
    $n = preg_match_all(
      '/(?<![A-Za-z0-9_])([A-Za-z_][A-Za-z0-9_]{1,63})[ \t]*=[ \t]*([^\s<>"\'&;,)]{6,})/',
      $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m as $one ) {
      if ( self::name_strength( $one[1][0] ) === 2 && self::value_fits( 2, $one[2][0], false ) ) {
        $add( $one[2][1], strlen( $one[2][0] ) );
        if ( $first ) {
          return $spans;
        }
      }
    }

    // Merge: sorted, and overlapping or touching ranges become one.
    usort( $spans, static function ( $a, $b ) {
      return $a[0] <=> $b[0];
    } );
    $merged = [];
    foreach ( $spans as $s ) {
      $last = count( $merged ) - 1;
      if ( $last >= 0 && $s[0] <= $merged[ $last ][0] + $merged[ $last ][1] ) {
        $merged[ $last ][1] = max( $merged[ $last ][1], $s[0] + $s[1] - $merged[ $last ][0] );
      }
      else {
        $merged[] = $s;
      }
    }
    return $merged;
  }

  /** True when the text holds something credential-shaped. Strings only; see holds(). */
  public static function text_holds( string $text ): bool {
    $found = self::find( $text, true );
    return $found === null || (bool) $found;
  }

  /**
  * Where the credentials are, as [ offset, length ] byte ranges of $text, in order and not
  * overlapping. When the scan itself fails the answer is the whole text, never "nowhere".
  *
  * @return array<int,array{0:int,1:int}>
  */
  public static function spans( string $text ): array {
    $found = self::find( $text, false );
    return $found === null ? [ [ 0, strlen( $text ) ] ] : $found;
  }

  /**
  * The text with each credential in it replaced by $marker and everything else kept.
  * What the audit log writes for a string argument: the call's shape and its harmless content
  * stay readable, and the secret does not.
  */
  public static function redact_spans( string $text, string $marker = '[redacted]' ): string {
    $spans = self::spans( $text );
    if ( !$spans ) {
      return $text;
    }
    $out = '';
    $at = 0;
    foreach ( $spans as [ $from, $len ] ) {
      $out .= substr( $text, $at, $from - $at ) . $marker;
      $at = $from + $len;
    }
    return $out . substr( $text, $at );
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
