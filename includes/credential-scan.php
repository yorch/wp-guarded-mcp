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
* "YOUR_TOKEN_HERE", "********", "${DB_PASS}" in capitals) is not a credential either: see
* is_placeholder(). Nor is prose that uses the words: "Basic HTML/CSS", "Day pass: 10am-6pm",
* "Token: 1st-come-first-served", or a key that is published to every visitor (a reCAPTCHA
* site key, a Stripe publishable key). The classes that remain flagged are listed in
* docs/guardrails.md.
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
    'authorization', 'pw', 'pswd', 'psw', 'sessionid', 'phpsessid', 'jsessionid',
  ];

  /**
  * Two-word endings that name a secret although neither word does alone: a session id, an
  * authorisation code, Rails' secret_key_base. Checked on the last two (or three) segments.
  */
  const SECRET_ENDINGS = [ 'session,id', 'auth,code', 'key,base' ];

  /** A name ending in "key" is a secret when the segment before it says what kind of key. */
  const KEY_QUALIFIERS = [
    'api', 'secret', 'access', 'private', 'auth', 'license', 'licence', 'encryption', 'signing',
    'client', 'app', 'master', 'site', 'secure', 'in', 'nonce', 'consumer', 'webhook',
    // What a key is for, or whose: AccountKey (Azure), STORAGE_KEY, SSH_KEY, DEPLOY_KEY, JWT_KEY, the
    // vendor names that head an .env line. A bare "key" is still not a name: "Key: 3 bedrooms".
    'account', 'storage', 'ssh', 'deploy', 'jwt', 'hmac', 'session', 'shared', 'service', 'primary',
    'secondary', 'root', 'rsa', 'pgp', 'gpg', 'stripe', 'openai', 'anthropic', 'sendgrid', 'mailgun',
    'twilio', 'aws', 'azure', 'github', 'gitlab', 'slack', 'paypal', 'mailchimp', 'algolia', 'firebase',
    'supabase',
  ];

  /**
  * Whole values that are what a sample or a template says where a secret goes, compared
  * case-insensitively. "password_here" is the line WordPress's own sample config ships with and
  * "put your unique phrase here" is the one beside the salts; "string" and "example" are what a
  * schema or a doc page writes. Short on purpose: every entry is a value that is then NOT
  * recorded as a credential, so each has to be something no one chooses as a secret.
  * The shapes in is_placeholder() are the other half of the list.
  */
  const PLACEHOLDER_VALUES = [
    'password_here', 'put your unique phrase here', 'string', 'example',
    // What a schema, a form-field doc or an empty config writes where a secret would go.
    'required', 'optional', 'none', 'null', 'nil', 'undefined', 'empty', 'hidden', 'redacted', 'n/a', 'tbd', 'todo',
    'changeme', 'change_me', 'change-me', 'secret', 'password', 'token',
  ];

  /**
  * Names, lower case and spelled exactly, of keys that are published to every visitor: the
  * attribute a reCAPTCHA widget carries in the page's own HTML. "site_key" is not here on
  * purpose: the same words name a private key in plugins that are not a widget.
  */
  const PUBLIC_KEY_NAMES = [ 'sitekey', 'data-sitekey' ];

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
    // Public by design: a reCAPTCHA site key is printed in the page's own HTML for every visitor.
    if ( in_array( strtolower( $name ), self::PUBLIC_KEY_NAMES, true ) ) {
      return 0;
    }
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
    // wordpress_logged_in_<hash> and wordpress_sec_<hash>: the cookies that are a login.
    if ( preg_match( '/^wordpress_(?:logged_in|sec)_[0-9a-f]{8,}$/i', $name ) ) {
      return 2;
    }
    if ( in_array( $before . ',' . $last, self::SECRET_ENDINGS, true ) ) {
      return 2;
    }
    // secret_key_base: the qualifier is the word before "key".
    if ( $last === 'base' && $before === 'key' && count( $segments ) > 2 && in_array( $segments[ count( $segments ) - 3 ], self::KEY_QUALIFIERS, true ) ) {
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
  *  - a run of three or more "*", "x" or bullet characters ("********", "xxxxxxxx", what a password
  *    field shows);
  *  - "<token>", "{{ token }}", "${DB_PASS}", "$DB_PASS": a template or an environment variable,
  *    which is written in capitals, digits and underscores. "$uperS3cret99" is a password that
  *    starts with a dollar sign and is not a placeholder (it was one, when any "$name" counted).
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
    if ( preg_match( '/^(?:\*{3,}|[xX]{3,})$/', $value ) || preg_match( '/^(?:\x{2022}|\x{25CF}|\x{00B7}){3,}$/u', $value ) ) {
      return true;
    }
    if ( preg_match( '/^(?:<[^<>\s]{1,60}>|\{\{[^{}]{1,60}\}\}|\$\{[A-Z_][A-Z0-9_]{0,60}\}|\$[A-Z_][A-Z0-9_]{0,60})$/', $value ) ) {
      return true;
    }
    if ( preg_match( '/^[A-Za-z_ -]{1,80}$/', $value ) && preg_match( '/^your[ _-]|[ _-]here$/i', $value ) ) {
      return true;
    }
    return false;
  }

  /**
  * Whether a value found under a name is credential-shaped, given how secret the name is.
  *
  * $soft is for an unquoted value under a name that is only a secret in some uses ("token",
  * "secret", "key" as the last word), as opposed to one that names a password. Prose uses the
  * first kind of word, "Token: 1st-come-first-served", and a value made of short words and numbers
  * joined by hyphens or slashes is not a secret there. A password is never given that benefit:
  * a passphrase is often exactly such words.
  */
  private static function value_fits( int $strength, string $value, bool $quoted, bool $soft = false ): bool {
    $value = trim( $value );
    if ( $value === '' || strtolower( $value ) === '[redacted]' || self::is_placeholder( $value ) || self::is_public_value( $value ) ) {
      return false;
    }
    // The scheme of an Authorization header written as code ('Bearer ' . $token), and a read from the
    // environment or a variable (process.env.API_KEY, $this->secret, ENV['TOKEN']): where the secret
    // is fetched from, not the secret.
    if ( preg_match( '/^(?:Bearer|Basic|Token|Digest)\s*$/i', $value ) || self::is_code_reference( $value ) ) {
      return false;
    }
    $len = strlen( $value );
    $token = (bool) preg_match( '/^[A-Za-z0-9_\-.\/+=:@$!#%^*~]+$/', $value );
    $digit = (bool) preg_match( '/\d/', $value );
    $alpha = (bool) preg_match( '/[A-Za-z]/', $value );
    if ( $strength === 2 ) {
      // Quoted: an assignment of anything non-trivial. Unquoted ("DB_PASSWORD=...", a query
      // string) needs the shape of a token too, or "password=required" in prose would count.
      if ( $quoted ) {
        return $len >= 4;
      }
      // A variable read, not a value: lowercase words joined by underscores or dots, no digit
      // (password = encryption_algorithm.password, token = request.access_token, url_user_password).
      // Unquoted only: a quoted "correct.horse.battery.staple" is a value. The cost is a passphrase
      // written exactly like that after an unquoted password name.
      if ( !$digit && preg_match( '/^_*+[a-z]++(?:[._]++[a-z]++)++$/', $value ) ) {
        return false;
      }
      // A password name only: no digit, and still a secret when it is mixed the way a generator mixes
      // it: letters with a capital inside the word (ten characters or more), or eight characters or
      // more with a symbol inside it (not the "**" of markdown emphasis around a word). No sentence is written either way. Passwords are
      // very often exactly this ("Txmyfk%WbZBu"), and a digit is not a rule that generators follow.
      $core = trim( $value, '!@#$%^&*~' );
      $mixed = !$soft && $alpha && ( ( strlen( $core ) >= 10 && (bool) preg_match( '/^[A-Za-z]++$/', $core ) && (bool) preg_match( '/[a-z][A-Z]/', $core ) )
        || ( $len >= 8 && (bool) preg_match( '/[!@#$%^&*~]/', $core ) ) );
      return $token && $len >= 6 && ( $digit || $len >= 16 || $mixed ) && !( $soft && self::prose_like( $value ) );
    }
    // A weak name (author, keywords) holds a credential only when the value looks like one.
    return $token && $len >= 16 && $digit && $alpha;
  }

  /**
  * Whether a value reads a secret from somewhere rather than being one: an environment lookup,
  * a request variable, a property of an object, a constant, a function call. The roots are the
  * ones code actually uses; a passphrase of dotted words ("correct.horse.battery.staple") is not
  * one of them, which is why this is a list of roots and not "looks like an identifier".
  */
  private static function is_code_reference( string $value ): bool {
    return (bool) preg_match( '/^\$?(?:process\.env|import\.meta\.env|os\.environ|os\.getenv|ENV|env|secrets|vars|config|settings|options|this|self|static|parent|_(?:GET|POST|REQUEST|SERVER|ENV|COOKIE))(?:\.|->|::|\[|\()/i', $value )
      || (bool) preg_match( '/^(?:getenv|env|config|get_option|get_site_option|constant|sanitize_\w+|wp_unslash|esc_\w+)\(/i', $value );
  }

  /**
  * A value that is published to every visitor by design, so recording it is not a leak: a
  * Stripe publishable key. (A Maps key is restricted by referrer rather than public, and an
  * AIza key can equally be a server secret, so those stay flagged.)
  */
  private static function is_public_value( string $value ): bool {
    return (bool) preg_match( '/^pk_(?:live|test)_/', $value );
  }

  /**
  * Whether a token reads as words and numbers rather than as a secret: a version ("v2.1.0"), or
  * two or more hyphen- or slash-separated pieces that are each a word or a short number with an
  * ordinal or clock suffix ("2024-2025", "1st-come-first-served", "10am-6pm", "HTML/CSS"). A UUID
  * or a hex string is not: its pieces mix digits into letters.
  *
  * Used only where a wrongly flagged line costs something (an undo, a value hidden from an
  * approver) and only for names that are not a password. It is a cost taken on purpose: a token
  * that is nothing but hyphenated groups of letters is read as prose here.
  */
  private static function prose_like( string $value ): bool {
    if ( preg_match( '/^v?\d+(?:\.\d+)+$/i', $value ) ) {
      return true;
    }
    $parts = preg_split( '/[-\/]/', $value );
    if ( !is_array( $parts ) || count( $parts ) < 2 || count( $parts ) > 40 ) {
      return false;
    }
    foreach ( $parts as $part ) {
      if ( !preg_match( '/^(?:[A-Za-z]{1,30}|\d{1,4}(?:st|nd|rd|th|am|pm)?)$/i', $part ) ) {
        return false;
      }
    }
    return true;
  }

  /**
  * Whether the token after "Bearer" or "Basic" is a credential rather than the next word of a
  * sentence ("Basic HTML/CSS for beginners", "Basic 2024/2025 membership").
  *
  * Basic carries base64 of "user:password", so it is judged by decoding: the base64 alphabet and
  * padding, twelve characters or more, and a decoded text that is printable with a colon in it.
  * A word that merely uses base64 letters does not decode to that. Bearer is an opaque token, so it
  * is eight characters or more with a digit, or twenty or more letters, and must not be words and
  * numbers joined by slashes or hyphens ("Bearer 4-year-old nephew"). A header written out in full
  * ("Authorization: Bearer ...") is judged by the looser rule in FIXED instead.
  */
  private static function bearer_basic_fits( string $label, string $token ): bool {
    if ( strtolower( $label ) === 'basic' ) {
      if ( strlen( $token ) < 12 || !preg_match( '/^[A-Za-z0-9+\/]++={0,2}$/', $token ) ) {
        return false;
      }
      $bare = rtrim( $token, '=' );
      $pad = strlen( $bare ) % 4;
      if ( $pad === 1 ) {
        return false;
      }
      $decoded = base64_decode( $bare . str_repeat( '=', $pad ? 4 - $pad : 0 ), true );
      return is_string( $decoded ) && (bool) preg_match( '/^[\x20-\x7E]+$/', $decoded ) && strpos( $decoded, ':' ) > 0;
    }
    if ( self::prose_like( $token ) ) {
      return false;
    }
    return preg_match( '/\d/', $token ) ? true : ( strlen( $token ) >= 20 && strpos( $token, '-' ) === false );
  }

  /** Spellings of a quote or an arrow that undo to the plain character. */
  const QUOTE_ESCAPES = [
    '&quot;' => '"', '&#x22;' => '"', '&#34;' => '"', '&#039;' => "'", '&#x27;' => "'", '&#39;' => "'",
    '&apos;' => "'", '&gt;' => '>', '&#62;' => '>', '&#x3e;' => '>', '&#x3E;' => '>',
  ];

  /**
  * Entities and escapes that stand for a quote (or the ">" of an arrow), undone, so an attribute
  * written as &quot;api_key&quot; or \"api_key\" or \u0022api_key\u0022, a JSON string escaped
  * twice (\\"api_key\\"), or a code block's 'secret' =&gt; 'value' reads like the plain form.
  *
  * A run of backslashes in front of a quote counts as one escape however long it is, which is what
  * a string that was escaped more than once looks like. The run is only read from its beginning
  * (the lookbehind), so a long line of backslashes is not scanned again from every position.
  *
  * Returns the plain text and the offsets needed to carry a position in it back to the text it
  * came from, which spans() needs to cut the right bytes out of the original. The offsets are
  * [ position in the plain text, matching position in the original ] at the start of the text
  * and after each replacement, in order.
  *
  * Undoing "\'" also splits a PHP value that holds an escaped quote, which is why the quoted
  * assignment rules are asked of the original text as well. @see find()
  *
  * @return array{0:string,1:?array} [ plain, null when nothing was undone ]
  */
  private static function unescape_quotes( string $text ): array {
    if ( strpos( $text, '&' ) === false && strpos( $text, '\\' ) === false ) {
      return [ $text, null ];
    }
    $found = preg_match_all( '/&(?:quot|apos|gt|#x22|#34|#039|#x27|#39|#62|#x3[eE]);|(?<!\\\\)\\\\++(?:["\']|u00(?:22|27))/', $text, $m, PREG_OFFSET_CAPTURE );
    if ( !$found ) {
      return [ $text, null ];
    }
    $plain = '';
    $map = [ [ 0, 0 ] ];
    $last = 0;
    foreach ( $m[0] as [ $token, $at ] ) {
      if ( $token[0] === '\\' ) {
        $end = substr( $token, -1 );
        $quote = ( $end === "'" || $end === '7' ) ? "'" : '"';
      }
      else {
        $quote = self::QUOTE_ESCAPES[ $token ] ?? '"';
      }
      $plain .= substr( $text, $last, $at - $last ) . $quote;
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
  *
  * Not here: "pk_live_" and "pk_test_" (a Stripe publishable key is printed in the page for every
  * visitor), and Bearer and Basic on their own, which need a judgment about the token that a
  * regular expression cannot make. @see BEARER_BASIC
  */
  const FIXED = '/-----BEGIN [A-Z ]{0,20}PRIVATE KEY-----'
    . '|(?i:\bAuthorization\s*+[:=]\s*+["\']?+(?:Basic|Bearer|Token|Digest|OAuth|ApiKey|Api-Key|Hawk|NTLM|Negotiate|Bot|Key|JWT)\s++)\K[A-Za-z0-9._~+\/=-]{8,}'
    . '|\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,}'
    . '|\bsk-[A-Za-z0-9_-]{20,}'
    . '|\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}'
    . '|\b(?:AKIA|ASIA)[A-Z0-9]{16}\b'
    . '|\bxox[abprse]-[A-Za-z0-9-]{10,}'
    . '|\bAIza[0-9A-Za-z_-]{30,}'
    . '|\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*'
    . '|\bgmcp_[A-Za-z0-9]{4,}_[A-Za-z0-9]{16,}'
    // Other vendors whose keys carry a prefix of their own. Each is a prefix plus a body of the length
    // the vendor issues, so a word that merely starts the same way is not one.
    . '|\bwhsec_[A-Za-z0-9]{24,}|\bnpm_[A-Za-z0-9]{30,}|\bglpat-[A-Za-z0-9_-]{20,}'
    . '|\bsh(?:pat|pss|pca|ppa)_[a-f0-9]{32}\b|\bSG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}'
    . '|\bdop_v1_[a-f0-9]{64}\b|\bhf_[A-Za-z0-9]{30,}|\bkey-[0-9a-f]{32}\b'
    . '|\bya29\.[A-Za-z0-9_-]{30,}|\bsq0(?:atp|csp)-[A-Za-z0-9_-]{22,}|\bpypi-AgEI[A-Za-z0-9_-]{50,}'
    . '|\brubygems_[a-f0-9]{48}\b|\b[0-9]{8,10}:AA[A-Za-z0-9_-]{33}\b'
    // A webhook URL is a bearer credential whose secret is its last path segment.
    . '|\bhooks\.slack\.com\/services\/T[A-Z0-9]{6,}\/B[A-Z0-9]{6,}\/\K[A-Za-z0-9]{16,}'
    . '|\bdiscord(?:app)?\.com\/api\/webhooks\/[0-9]{15,}\/\K[A-Za-z0-9_-]{50,}/';

  /** "Bearer x" and "Basic x": the label is group 1, the token group 2. @see bearer_basic_fits() */
  const BEARER_BASIC = '/(?i:\b(Bearer|Basic)\s++)\K([A-Za-z0-9._~+\/=-]{8,})/';

  /** user:password@ in a URL; the match is the user and the password, as two groups. */
  const USERINFO = '/(?<![A-Za-z0-9+.\-])[a-z][a-z0-9+.\-]*+:\/\/\K([^\/\s:@<>"\']*+):([^\/\s@<>"\']++)(?=@)/i';

  /** User names and passwords a documentation page puts in a URL; such a pair is not a credential. */
  const SAMPLE_URL_USERS = [ 'user', 'username', 'login', 'name' ];
  const SAMPLE_URL_PASSWORDS = [ 'pass', 'password', 'passwd', 'pwd', 'secret' ];

  /** curl -u user:password, --user user:password, --proxy-user, -U: the pair is the credential. */
  const CLI_USER = '/(?<![A-Za-z0-9_\-])(?:-u|-U|--user|--proxy-user)(?:[ \t]++|=)["\']?+([^\s:"\'$<>]{1,64}):([^\s"\']{3,})/';

  /** An <input> tag, whose name and value are two attributes and so no assignment for the rules below. */
  const INPUT_TAG = '/<input\b[^<>]{0,1000}+>/i';

  /** One attribute of a tag, quoted: group 1 its name, 2 or 3 its value. */
  const TAG_ATTRIBUTE = '/(?<![A-Za-z0-9_\-])(name|type|value)[ \t\r\n]*+=[ \t\r\n]*+(?:"([^"]*+)"|\'([^\']*+)\')/i';

  /** Input types that carry a label or a flag in "value", not what was typed. */
  const LABEL_INPUT_TYPES = [ 'submit', 'button', 'reset', 'image', 'checkbox', 'radio', 'file' ];

  /** A name, a separator and a quoted value; a backslash and the character after it count as one in the value. */
  const QUOTED_ASSIGNMENT = '/(?<![A-Za-z0-9_.\-])\$?["\']?+([A-Za-z0-9_.\-]++)["\']?+\]?+[ \t]*+(?:=>|:|=)[ \t]*+(["\'])((?:\\\\.|(?!\2)[^\r\n\\\\]){1,512}+)\2/';

  /** define( 'NAME', 'value' ) and the option and storage setters that take a name and a value, the value allowed to hold escaped quotes. */
  const DEFINE_CALL = '/\b(?:define|update_option|add_option|update_site_option|add_site_option|set_theme_mod|set_transient|set_site_transient|setItem)\s*+\(\s*+(["\'])([A-Za-z0-9_.\-]{1,64})\1\s*+,\s*+(["\'])((?:\\\\.|(?!\3)[^\r\n\\\\]){1,512}+)\3/i';

  /**
  * name => "value", "name": "value", name = 'value', $name = "value", $cfg['name'] = 'value' and
  * define( 'NAME', 'value' ), asked of $subject, with each span put back through $map to the text
  * the caller gave. A value is a run of characters in which a backslash and the character after it
  * count as one, so "p\u0026ss" and 'ab\'cd' are read whole.
  *
  * @param array $spans Collected [ offset, length ] pairs, by reference.
  * @return bool|null null when PCRE gave up; true when $first and one was found
  */
  private static function quoted_spans( string $subject, ?array $map, bool $first, array &$spans ): ?bool {
    $rules = [ [ self::QUOTED_ASSIGNMENT, 1, 3 ], [ self::DEFINE_CALL, 2, 4 ] ];
    foreach ( $rules as [ $re, $name_group, $value_group ] ) {
      $n = preg_match_all( $re, $subject, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
      if ( $n === false ) {
        return null;
      }
      foreach ( $m as $one ) {
        $strength = self::name_strength( $one[ $name_group ][0] );
        if ( $strength > 0 && self::value_fits( $strength, $one[ $value_group ][0], true ) ) {
          $from = self::original_offset( $one[ $value_group ][1], $map );
          $to = self::original_offset( $one[ $value_group ][1] + strlen( $one[ $value_group ][0] ), $map );
          $spans[] = [ $from, max( 0, $to - $from ) ];
          if ( $first ) {
            return true;
          }
        }
      }
    }
    return false;
  }

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
    // A private key is a block. One that is not closed spans to the end of the text, and anything
    // after its start is inside it: a text of seventeen thousand "-----BEGIN" lines used to search
    // to the end for a closing marker once per line. $covered is where the block already found
    // ends, and a later start inside it is skipped, so the search runs at most once per block.
    $covered = 0;
    foreach ( $m[0] as [ $str, $at ] ) {
      if ( $at < $covered ) {
        continue;
      }
      $len = strlen( $str );
      if ( strpos( $str, '-----BEGIN' ) === 0 ) {
        // From its first line to the end marker, or to the end of what we were given when the
        // text was cut before it.
        $end = strpos( $plain, '-----END ', $at );
        $close = $end === false ? false : strpos( $plain, '-----', $end + 9 );
        $len = $close === false ? strlen( $plain ) - $at : $close + 5 - $at;
      }
      $covered = $at + $len;
      $add( $at, $len );
      if ( $first ) {
        return $spans;
      }
    }

    $n = preg_match_all( self::BEARER_BASIC, $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m as $one ) {
      if ( self::bearer_basic_fits( $one[1][0], $one[2][0] ) ) {
        $add( $one[2][1], strlen( $one[2][0] ) );
        if ( $first ) {
          return $spans;
        }
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
      // "cvs://:pserver:anoncvs@host": a root with no user, whose "password" is more fields. A
      // password after an empty user is one piece (redis://:secret@host).
      if ( $one[1][0] === '' && strpos( $one[2][0], ':' ) !== false ) {
        continue;
      }
      $add( $one[1][1], strlen( $one[1][0] ) + 1 + strlen( $one[2][0] ) );
      if ( $first ) {
        return $spans;
      }
    }

    $n = preg_match_all( self::CLI_USER, $plain, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );
    if ( $n === false ) {
      return null;
    }
    foreach ( $m as $one ) {
      $pass = $one[2][0];
      // -u 1000:1000 and --user www-data:www-data are a user and a group (docker, useradd, chown).
      // A password has a digit, a symbol or length, and is not the user's own name.
      if ( $pass === $one[1][0] || ( ctype_digit( $one[1][0] ) && ctype_digit( $pass ) )
        || !preg_match( '/\d|[^A-Za-z]|^.{10,}$/', $pass ) ) {
        continue;
      }
      if ( ( in_array( strtolower( $one[1][0] ), self::SAMPLE_URL_USERS, true ) && in_array( strtolower( $pass ), self::SAMPLE_URL_PASSWORDS, true ) )
        || preg_match( '/^\$\{?[A-Za-z_][A-Za-z0-9_]*\}?$/', $pass ) || !self::value_fits( 2, $pass, true ) ) {
        continue;
      }
      $add( $one[2][1], strlen( $pass ) );
      if ( $first ) {
        return $spans;
      }
    }

    // <input name="api_key" value="...">: the value is the secret and the name is another attribute,
    // in either order. A button or a checkbox puts a label or a flag in "value", and a value with a
    // space in it is a label too.
    $n = preg_match_all( self::INPUT_TAG, $plain, $tags, PREG_OFFSET_CAPTURE );
    if ( $n === false ) {
      return null;
    }
    foreach ( $tags[0] as [ $tag, $tag_at ] ) {
      $got = preg_match_all( self::TAG_ATTRIBUTE, $tag, $attrs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
      if ( $got === false ) {
        return null;
      }
      $name = $type = null;
      $value = null;
      foreach ( $attrs as $attr ) {
        $key = strtolower( $attr[1][0] );
        $text = isset( $attr[3] ) && $attr[3][1] >= 0 ? $attr[3] : $attr[2];
        if ( $key === 'name' && $name === null ) {
          $name = $text[0];
        }
        elseif ( $key === 'type' && $type === null ) {
          $type = strtolower( $text[0] );
        }
        elseif ( $key === 'value' && $value === null ) {
          $value = $text;
        }
      }
      if ( $name === null || $value === null || in_array( $type, self::LABEL_INPUT_TYPES, true ) || preg_match( '/\s/', $value[0] )
        || self::name_strength( $name ) !== 2 || !self::value_fits( 2, $value[0], true ) ) {
        continue;
      }
      $add( $tag_at + $value[1], strlen( $value[0] ) );
      if ( $first ) {
        return $spans;
      }
    }

    // Quoted assignments, in the text with its quote escapes undone (so a JSON string that was
    // escaped for embedding reads as JSON) and, when anything was undone, in the text as it was
    // written too, because "\'" inside a value is part of the value and undoing it cut the value short.
    $got = self::quoted_spans( $plain, $map, $first, $spans );
    if ( $got === null ) {
      return null;
    }
    if ( $got ) {
      return $spans;
    }
    if ( $map !== null ) {
      $got = self::quoted_spans( $text, null, $first, $spans );
      if ( $got === null ) {
        return null;
      }
      if ( $got ) {
        return $spans;
      }
    }

    // DB_PASSWORD=value, ?token=value and "password: value": an unquoted assignment, secret-named
    // outright only, and the value has to look like one (six characters with a digit, or sixteen),
    // which is what keeps "Password: required" and "token=none" in prose unflagged.
    //
    // A bare "pass" is not a name here: "Day pass: 10am-6pm" and "Hall pass=2nd-floor" are prose, and
    // db_pass, smtp_pass, DB_PASS (a qualified name) still are. "::" is a scope operator, not a
    // separator. The name is the whole run of name characters, however long, because a bound let a
    // secret hide behind a long name.
    //
    // The name and separator are matched on their own and the value is read only for a secret name,
    // so a value is never consumed on behalf of a name that is not one: "redirect:https://x?token=..."
    // must still show the token, and "https://x" is not "https" assigned "//x". The value is read at
    // most once per byte (a name that starts inside an earlier credential's span is skipped) and up
    // to 512 bytes at a time, which keeps a text of "password=password=password=..." linear. The
    // name run is read from its beginning only (the lookbehind), possessively, so a long run of
    // name characters is not read again from each position in it.
    $n = preg_match_all(
      '/(?<![A-Za-z0-9_.\-])([A-Za-z0-9_.\-]++)["\']?+\]?+[ \t]*+(?:=|:(?![\/:]))[ \t]*+/',
      $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    if ( $n === false ) {
      return null;
    }
    $covered = 0;
    foreach ( $m as $one ) {
      $value_at = $one[0][1] + strlen( $one[0][0] );
      $bare_pass = (bool) preg_match( '/^[._\-]*+pass[._\-]*+$/i', $one[1][0] );
      // A bare "pass" is a password in "pass=hunter67" (a query string, a log line) and a word in
      // "Day pass: 10am-6pm", so it counts only with "=", and only as a soft name (below).
      if ( $one[0][1] < $covered || self::name_strength( $one[1][0] ) !== 2 || ( $bare_pass && strpos( $one[0][0], '=' ) === false ) ) {
        continue;
      }
      $value = '';
      for ( $from = $value_at; ; $from += 512 ) {
        $got = preg_match( '/\G[^\s<>"\'&;,)]{1,512}/', $plain, $v, 0, $from );
        if ( $got === false ) {
          return null;
        }
        if ( !$got ) {
          break;
        }
        $value .= $v[0];
        if ( strlen( $v[0] ) < 512 ) {
          break;
        }
      }
      // Not soft: a name that ends in a password word, or in "pass" qualified with "_" or "." or a
      // capital (db_pass, DB_PASS, smtpPass). A hyphen does not qualify: "Season-Pass: 2024-2025".
      $soft = $bare_pass || !( preg_match( '/(?:password|passwd|passphrase|pwd|pw)[._\-]*+$/i', $one[1][0] )
        || preg_match( '/[_.]pass[._\-]*+$/i', $one[1][0] ) || preg_match( '/[a-z]Pass[._\-]*+$/', $one[1][0] ) );
      if ( strlen( $value ) >= 6 && self::value_fits( 2, $value, false, $soft ) ) {
        $add( $value_at, strlen( $value ) );
        $covered = $value_at + strlen( $value );
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

  /** Most nodes (arrays, strings, numbers) a value may have for a walk over it to be made at all. */
  const MAX_NODES = 100000;

  /** Most bytes of strings a value may hold for a walk over it to be made at all. */
  const MAX_BYTES = 8388608;

  /**
  * Whether a value is small enough to be walked and scanned. The cost of reading a value is
  * linear in its nodes and in its bytes, and a value a client sent (an option, a meta array, a
  * call's arguments) can have a million of the first inside a size the request allows: a million
  * one-character leaves took 2 s in holds() and a million secret-looking assignments 5 s. Counting
  * stops at the limit, so the count itself costs a fixed amount however large the value is.
  *
  * A caller that gets false must treat the value as NOT SAFE: never as clean.
  */
  public static function within_budget( $value ): bool {
    $nodes = 0;
    $bytes = 0;
    return self::spend( $value, 0, $nodes, $bytes );
  }

  private static function spend( $value, int $depth, int &$nodes, int &$bytes ): bool {
    if ( ++$nodes > self::MAX_NODES ) {
      return false;
    }
    if ( is_string( $value ) ) {
      $bytes += strlen( $value );
      return $bytes <= self::MAX_BYTES;
    }
    if ( is_object( $value ) ) {
      $value = get_object_vars( $value );
    }
    if ( !is_array( $value ) || $depth > self::MAX_DEPTH ) {
      return true;
    }
    foreach ( $value as $inner ) {
      if ( !self::spend( $inner, $depth + 1, $nodes, $bytes ) ) {
        return false;
      }
    }
    return true;
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
    // Too much to read is not "nothing found". The answer here decides whether a value may be
    // kept, so a value that cannot be read inside the budget is one that is not kept.
    if ( $depth === 0 && !self::within_budget( $value ) ) {
      return true;
    }
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
