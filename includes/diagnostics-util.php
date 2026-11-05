<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The logic behind the diagnostic tools that does not need WordPress, kept apart so it can be
* run on its own.
*
* Three things live here because each is a decision about hostile input, and a decision of
* that kind should be testable without standing up a site: whether a URL may be fetched by the
* server (the SSRF question), what a line of a PHP error log may be allowed to say once it
* leaves the machine, and how to read the end of a file without reading the file.
*
* Nothing here calls a WordPress function, with one exception made deliberately explicit:
* the filterable list of credential-shaped field names is passed in by the caller, so the
* single list in GMCP_Core::credential_field_patterns() stays the only one.
*/
final class GMCP_Diag_Util {
  /**
  * A quoted value for the scrubs below, as a regular expression fragment: "..." or '...' in which
  * a backslash and the character after it count as one (so an escaped quote does not end the
  * value), or a curly-quoted one. Possessive, so a long unterminated value is read once.
  */
  const QUOTED_VALUE = '"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|“[^”]*+”|‘[^’]*+’';

  /**
  * The markers this scrubber writes in place of what it removed, as a regular expression
  * fragment. A later rule must not read one as the value after a secret-named name: given
  * "key: [private-key-block]" the name rule ended the value at the "]" and wrote a second marker
  * over it, leaving a stray "]" behind and making a second pass change the line.
  */
  const OWN_MARKER = '\[(?:redacted|private-key-block|jwt|token|hash|email|ip|api key|gmcp key|aws key id|aws secret|base64 line redacted)\]';

  /** The substrings that make a field name secret in a log line, when the caller supplies none. */
  const DEFAULT_SECRET_PATTERNS = [ 'pass', 'pwd', 'secret', 'token', 'key', 'auth', 'salt', 'nonce', 'credential', 'bearer', 'signature', 'licence', 'license', 'private' ];


  /* ------------------------------------------------------------------ IP addresses */

  /**
  * What kind of address this is, for the question "may the server connect to it".
  *
  * Returns one of:
  *   public    a routable address.
  *   private   RFC 1918, CGNAT, unique-local. Reachable only from inside a network.
  *   loopback  127.0.0.0/8 and ::1.
  *   never     link-local (which is where cloud metadata services live), unspecified,
  *             multicast, broadcast, documentation and benchmarking ranges, anything
  *             outside IPv6 global unicast. Refused even for a site that is itself local,
  *             because no site is served from these and the one service that lives there
  *             hands out credentials.
  *   invalid   not an IP address.
  *
  * Written out rather than delegated to FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE, whose
  * coverage has changed between PHP releases (CGNAT, for one, was not covered by older
  * ones), and which say nothing about an address embedded in an IPv6 form.
  */
  public static function classify_ip( string $ip ): string {
    if ( filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
      return 'invalid';
    }
    $packed = @inet_pton( $ip );
    if ( $packed === false ) {
      return 'invalid';
    }
    if ( strlen( $packed ) === 4 ) {
      return self::classify_v4( $packed );
    }

    // IPv6. Anything that embeds an IPv4 address is judged by the address it embeds,
    // otherwise ::ffff:127.0.0.1 is "public" and the whole check is one prefix away from
    // being bypassed.
    $zero10 = str_repeat( "\0", 10 );
    if ( substr( $packed, 0, 10 ) === $zero10 && substr( $packed, 10, 2 ) === "\xff\xff" ) {
      return self::classify_v4( substr( $packed, 12, 4 ) );
    }
    if ( $packed === str_repeat( "\0", 16 ) ) {
      return 'never';
    }
    if ( $packed === str_repeat( "\0", 15 ) . "\1" ) {
      return 'loopback';
    }
    if ( substr( $packed, 0, 12 ) === str_repeat( "\0", 12 ) ) {
      return 'never'; // ::/96, the deprecated IPv4-compatible form.
    }
    if ( substr( $packed, 0, 12 ) === "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) ) {
      return self::classify_v4( substr( $packed, 12, 4 ) ); // 64:ff9b::/96, NAT64.
    }
    if ( substr( $packed, 0, 2 ) === "\x20\x02" ) {
      return self::classify_v4( substr( $packed, 2, 4 ) ); // 2002::/16, 6to4.
    }
    if ( substr( $packed, 0, 4 ) === "\x20\x01\x00\x00" ) {
      return 'never'; // 2001::/32, Teredo.
    }
    if ( substr( $packed, 0, 4 ) === "\x20\x01\x0d\xb8" ) {
      return 'never'; // 2001:db8::/32, documentation.
    }
    if ( substr( $packed, 0, 4 ) === "\xfd\x00\x0e\xc2" ) {
      return 'never'; // fd00:ec2::/32, the AWS IPv6 metadata range.
    }
    $b0 = ord( $packed[0] );
    $b1 = ord( $packed[1] );
    if ( $b0 === 0xfe && ( $b1 & 0xc0 ) === 0x80 ) {
      return 'never'; // fe80::/10, link-local.
    }
    if ( ( $b0 & 0xfe ) === 0xfc ) {
      return 'private'; // fc00::/7, unique-local.
    }
    if ( ( $b0 & 0xe0 ) === 0x20 ) {
      return 'public'; // 2000::/3, global unicast.
    }
    return 'never';
  }

  private static function classify_v4( string $packed ): string {
    $n = unpack( 'N', $packed )[1];
    // [network, prefix length, class]. First match wins; the order is most specific first.
    static $ranges = [
      [ '0.0.0.0', 8, 'never' ],
      [ '10.0.0.0', 8, 'private' ],
      [ '100.64.0.0', 10, 'private' ],
      [ '127.0.0.0', 8, 'loopback' ],
      [ '169.254.0.0', 16, 'never' ],
      [ '172.16.0.0', 12, 'private' ],
      [ '192.0.0.0', 24, 'never' ],
      [ '192.0.2.0', 24, 'never' ],
      [ '192.168.0.0', 16, 'private' ],
      [ '198.18.0.0', 15, 'never' ],
      [ '198.51.100.0', 24, 'never' ],
      [ '203.0.113.0', 24, 'never' ],
      [ '224.0.0.0', 4, 'never' ],
      [ '240.0.0.0', 4, 'never' ],
    ];
    foreach ( $ranges as [ $net, $bits, $class ] ) {
      $mask = $bits === 0 ? 0 : ( ( 0xFFFFFFFF << ( 32 - $bits ) ) & 0xFFFFFFFF );
      if ( ( $n & $mask ) === ( ip2long( $net ) & $mask ) ) {
        return $class;
      }
    }
    return 'public';
  }

  /* --------------------------------------------------------------------- hostnames */

  /** Lower-cased, without a trailing dot, without the brackets around an IPv6 literal. */
  public static function canonical_host( string $host ): string {
    $host = strtolower( trim( $host ) );
    if ( $host !== '' && $host[0] === '[' && substr( $host, -1 ) === ']' ) {
      $host = substr( $host, 1, -1 );
    }
    return rtrim( $host, '.' );
  }

  /**
  * Whether a site's own host name says it is a local or development site.
  *
  * Only then is a private or loopback address an acceptable place for the site to live. On a
  * production host name an address in those ranges is a misconfiguration at best and at worst
  * somebody pointing the name at an internal service, so it is refused and a site that really
  * does resolve itself privately opens it with the gmcp_fetch_allow_private filter.
  */
  public static function local_looking_host( string $host ): bool {
    $host = self::canonical_host( $host );
    if ( $host === '' ) {
      return false;
    }
    if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
      return in_array( self::classify_ip( $host ), [ 'private', 'loopback' ], true );
    }
    if ( strpos( $host, '.' ) === false ) {
      return true; // localhost, or a container service name such as "wordpress".
    }
    foreach ( [ '.localhost', '.local', '.test', '.internal', '.lan', '.localdomain', '.home.arpa', '.ddev.site', '.lndo.site' ] as $tld ) {
      if ( substr( $host, -strlen( $tld ) ) === $tld ) {
        return true;
      }
    }
    return false;
  }

  /* ------------------------------------------------------------------- fetch policy */

  /** First path segments (relative to the site's path) a diagnostic fetch will not request. */
  const BLOCKED_SEGMENTS = [
    'wp-admin', 'wp-json', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php', 'wp-signup.php',
    'wp-activate.php', 'wp-mail.php', 'wp-trackback.php', 'wp-comments-post.php',
  ];

  /** Query keys that mean "do something" on common plugins, or that would make the request a different kind of request. */
  const BLOCKED_QUERY_KEYS = [
    'add-to-cart', 'wc-ajax', 'remove_item', 'undo_item', 'action', 'rest_route', '_wpnonce',
    'doing_wp_cron', 'gmcp_preview',
  ];

  /**
  * The path a server would route, and a refusal when the path is not a plain one.
  *
  * Decoded until it stops changing (capped), then refused if it was ever encoded in a way no
  * browser produces: an encoded unreserved character (letter, digit, "-", "_", "~"), an
  * encoded dot, slash, backslash or NUL, a path that is still encoded after one decode (a
  * double encoding is only ever sent to confuse a second decoder), or one that decodes to a
  * control character. Legitimate encoding (%20, a UTF-8 sequence) is untouched.
  *
  * @return array{path:string,error:string}
  */
  public static function normalise_path( string $path ): array {
    $bad = static function ( string $why ): array {
      return [ 'path' => '', 'error' => $why ];
    };
    if ( preg_match( '/%(2e|2f|5c|00)/i', $path ) ) {
      return $bad( 'A path containing an encoded dot, slash, backslash or NUL is refused.' );
    }
    if ( preg_match_all( '/%([0-9a-f]{2})/i', $path, $m ) ) {
      foreach ( $m[1] as $hex ) {
        $c = hexdec( $hex );
        if ( ( $c >= 0x30 && $c <= 0x39 ) || ( $c >= 0x41 && $c <= 0x5a ) || ( $c >= 0x61 && $c <= 0x7a ) || $c === 0x2d || $c === 0x5f || $c === 0x7e ) {
          return $bad( 'A path with an encoded letter, digit, hyphen, underscore or tilde is refused: nothing that visits a page writes it that way.' );
        }
      }
    }
    $once = rawurldecode( $path );
    if ( preg_match( '/%[0-9a-f]{2}/i', $once ) ) {
      return $bad( 'A path that is still percent-encoded after decoding (double encoding) is refused.' );
    }
    if ( preg_match( '/[\x00-\x1f\x7f\\\\]/', $once ) ) {
      return $bad( 'A path that decodes to a control character or a backslash is refused.' );
    }
    foreach ( explode( '/', $once ) as $segment ) {
      if ( $segment === '.' || $segment === '..' ) {
        return $bad( 'A path with dot segments is refused.' );
      }
    }
    return [ 'path' => $once, 'error' => '' ];
  }

  /**
  * Every name a query key can arrive in PHP as, lowercased.
  *
  * PHP does not keep the key it was sent. It decodes it ("+" is a space), drops leading
  * spaces, turns "." and " " into "_" and, in a key with "[", either takes what is before it
  * as the name (an array key) or turns the "[" into "_" (no closing bracket). So
  * "rest.route", "rest+route" and "rest[route" are all $_GET['rest_route'], which WordPress
  * serves as the REST API. Returned: the key decoded, the key as PHP stores it, and the
  * array-key base, so a blocked name is matched whichever spelling reached it.
  *
  * @return string[]
  */
  public static function query_key_variants( string $raw ): array {
    $key = urldecode( $raw );
    $nul = strpos( $key, "\0" );
    if ( $nul !== false ) {
      $key = substr( $key, 0, $nul );
    }
    $key = ltrim( $key, ' ' );
    $out = [ strtolower( rawurldecode( $raw ) ), strtolower( $key ) ];
    $out[] = strtolower( strtr( $key, [ '.' => '_', ' ' => '_', '[' => '_' ] ) );
    $br = strpos( $key, '[' );
    if ( $br !== false && strpos( $key, ']', $br ) !== false ) {
      $out[] = strtolower( strtr( substr( $key, 0, $br ), [ '.' => '_', ' ' => '_' ] ) );
    }
    return array_values( array_unique( $out ) );
  }

  /**
  * May the server fetch this URL, and if so what exactly should it request.
  *
  * The one rule is that the URL must be a page of THIS site: same scheme, same host, same
  * port, and a path inside the site's own path. Everything else here exists because "same
  * host" is checked on text, and the thing that is actually contacted is an address.
  *
  *  - The URL is plain printable ASCII with no backslash, so a parser that treats
  *    `http://good\@evil/` differently from the HTTP client cannot be exploited; the URL
  *    that is sent is rebuilt from the parsed parts and never the string that was supplied.
  *  - No user information, no encoded dot/slash/backslash/NUL, no dot segments.
  *  - The host is resolved here, every address returned must be acceptable (so a name with
  *    one public and one internal record is refused), and the caller pins the connection to
  *    the address that was checked, so the answer cannot change between this check and the
  *    connect. An IP literal is its own answer.
  *  - Addresses in the 'never' class are refused regardless of the site. Private and
  *    loopback ones only when the site's own host name is a local one (or $allow_private).
  *
  * @param callable $resolve fn( string $host ): string[] — the addresses for a name.
  * @return array{ok:bool,reason:string,url:string,scheme:string,host:string,port:int,ips:string[],pin:string}
  */
  public static function check_fetch_url( string $url, string $home, callable $resolve, bool $allow_private = false ): array {
    $fail = static function ( string $reason ): array {
      return [ 'ok' => false, 'reason' => $reason, 'url' => '', 'scheme' => '', 'host' => '', 'port' => 0, 'ips' => [], 'pin' => '' ];
    };

    $url = trim( $url );
    if ( $url === '' ) {
      return $fail( 'A URL is required.' );
    }
    if ( strlen( $url ) > 2048 ) {
      return $fail( 'That URL is longer than 2048 characters.' );
    }
    if ( preg_match( '/[^\x21-\x7E]/', $url ) || strpos( $url, '\\' ) !== false ) {
      return $fail( 'The URL must be plain ASCII with no spaces, control characters or backslashes. Percent-encode anything else.' );
    }
    $u = parse_url( $url );
    $h = parse_url( $home );
    if ( !is_array( $u ) || !is_array( $h ) || empty( $u['scheme'] ) || empty( $u['host'] ) || empty( $h['scheme'] ) || empty( $h['host'] ) ) {
      return $fail( 'That is not an absolute http(s) URL.' );
    }
    $scheme = strtolower( $u['scheme'] );
    $hscheme = strtolower( $h['scheme'] );
    if ( $scheme !== 'http' && $scheme !== 'https' ) {
      return $fail( 'Only http and https URLs are fetched.' );
    }
    if ( isset( $u['user'] ) || isset( $u['pass'] ) ) {
      return $fail( 'A URL carrying a user name or password is refused.' );
    }
    $host = self::canonical_host( $u['host'] );
    $hhost = self::canonical_host( $h['host'] );
    if ( $host === '' || $host !== $hhost ) {
      return $fail( 'Only pages on this site\'s own host (' . $hhost . ') can be fetched.' );
    }
    if ( $scheme !== $hscheme ) {
      return $fail( 'The scheme must be ' . $hscheme . ', the same as the site\'s own address.' );
    }
    $port = isset( $u['port'] ) ? (int) $u['port'] : ( $scheme === 'https' ? 443 : 80 );
    $hport = isset( $h['port'] ) ? (int) $h['port'] : ( $hscheme === 'https' ? 443 : 80 );
    if ( $port !== $hport ) {
      return $fail( 'The port must be ' . $hport . ', the same as the site\'s own address.' );
    }

    $path = $u['path'] ?? '/';
    if ( $path === '' ) {
      $path = '/';
    }
    $norm = self::normalise_path( $path );
    if ( $norm['error'] !== '' ) {
      return $fail( $norm['error'] );
    }
    // Every comparison below is made on the DECODED path: a server decodes before it routes,
    // so "/wp%2Djson/", "/wp-login.ph%70" and "/index.php/wp-json/" are the same request as
    // the plain spellings, and a list compared against the text that was sent is a list the
    // sender gets to spell around.
    $decoded = $norm['path'];
    $base = rtrim( $h['path'] ?? '', '/' );
    if ( $base !== '' && $decoded !== $base && strpos( $decoded, $base . '/' ) !== 0 ) {
      return $fail( 'The path is outside the site (' . $base . '/).' );
    }
    $segments = explode( '/', ltrim( substr( $decoded, strlen( $base ) ), '/' ) );
    $first = strtolower( $segments[0] );
    if ( in_array( $first, self::BLOCKED_SEGMENTS, true ) ) {
      return $fail( '"' . $first . '" is not a page a visitor sees, and is not fetched.' );
    }
    $last = count( $segments ) - 1;
    foreach ( $segments as $i => $segment ) {
      $seg = strtolower( $segment );
      if ( substr( $seg, -4 ) !== '.php' ) {
        continue;
      }
      // "/index.php/wp-json/..." is how WordPress is reached without rewrite rules: PATH_INFO
      // carries the route, so index.php may only ever be the last thing in the path. Any other
      // PHP file, and a PHP file with a path after it, is not a request for a page.
      if ( $i !== $last || $seg !== 'index.php' ) {
        return $fail( 'A request for a PHP file other than a bare index.php is not a request for a page, and is not made.' );
      }
    }
    $query = $u['query'] ?? '';
    if ( $query !== '' ) {
      foreach ( explode( '&', $query ) as $pair ) {
        foreach ( self::query_key_variants( explode( '=', $pair, 2 )[0] ) as $key ) {
          if ( in_array( $key, self::BLOCKED_QUERY_KEYS, true ) ) {
            return $fail( 'The query parameter "' . $key . '" is not fetched: it either changes state on common plugins or carries a preview credential.' );
          }
        }
      }
    }

    // Addresses. A literal is its own resolution.
    if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
      $ips = [ $host ];
    }
    else {
      $ips = array_values( array_unique( array_filter( array_map( 'strval', (array) $resolve( $host ) ) ) ) );
    }
    if ( !$ips ) {
      return $fail( 'The host name "' . $host . '" did not resolve.' );
    }
    $allow = $allow_private || self::local_looking_host( $hhost );
    foreach ( $ips as $ip ) {
      $class = self::classify_ip( $ip );
      if ( $class === 'public' ) {
        continue;
      }
      if ( ( $class === 'private' || $class === 'loopback' ) && $allow ) {
        continue;
      }
      return $fail( 'The host resolves to ' . $ip . ', which is ' . ( $class === 'never' ? 'in a range the server never connects to (link-local, reserved, multicast or metadata)' : 'a ' . $class . ' address, and this does not look like a local development site' ) . '.' );
    }

    $rebuilt = $scheme . '://' . ( strpos( $host, ':' ) !== false ? '[' . $host . ']' : $host )
      . ( $port !== ( $scheme === 'https' ? 443 : 80 ) ? ':' . $port : '' )
      . $path . ( $query !== '' ? '?' . $query : '' );
    // The HTTP library upper-cases every percent escape (it normalises the address before it
    // sends it), so the string checked here has to be spelled the way it will be sent: a URL
    // that differs from the one on the wire is a URL nothing downstream can recognise as ours.
    $rebuilt = preg_replace_callback( '/%[0-9a-fA-F]{2}/', static function ( array $m ): string {
      return strtoupper( $m[0] );
    }, $rebuilt );

    return [ 'ok' => true, 'reason' => '', 'url' => $rebuilt, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips, 'pin' => $ips[0] ];
  }

  /**
  * The CURLOPT_RESOLVE entry that pins a host name to the address that was checked, or null
  * when the host is itself an IP address (nothing to resolve, nothing to pin).
  *
  * The format is "name:port:address", an IPv6 address in brackets. Measured against libcurl
  * 8.5: "name:80:[::1]" is accepted and connects to ::1; "::1:80:[::1]" (a literal as the
  * name) is rejected with "Couldn't parse CURLOPT_RESOLVE entry" and fails the request.
  */
  public static function resolve_entry( string $host, int $port, string $ip ): ?string {
    if ( filter_var( self::canonical_host( $host ), FILTER_VALIDATE_IP ) !== false ) {
      return null;
    }
    return $host . ':' . $port . ':' . ( strpos( $ip, ':' ) !== false ? '[' . $ip . ']' : $ip );
  }

  /** A redirect target resolved against the URL that issued it. Pure string work; the result is then validated like any other URL. */
  public static function resolve_location( string $base, string $location ): string {
    $location = trim( $location );
    if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
      return $location;
    }
    $b = parse_url( $base );
    if ( !is_array( $b ) || empty( $b['scheme'] ) || empty( $b['host'] ) ) {
      return $location;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
    if ( strpos( $location, '//' ) === 0 ) {
      return $b['scheme'] . ':' . $location;
    }
    if ( $location !== '' && $location[0] === '/' ) {
      return $origin . $location;
    }
    if ( $location !== '' && $location[0] === '?' ) {
      return $origin . ( $b['path'] ?? '/' ) . $location;
    }
    $dir = preg_replace( '#/[^/]*$#', '/', $b['path'] ?? '/' );
    return $origin . $dir . $location;
  }

  /* ---------------------------------------------------------------------- page text */

  /**
  * What a visitor would read on a page, and the head metadata a search engine would.
  *
  * Parsed with DOM rather than with regular expressions, because "strip the tags" done
  * with a pattern is a place where hostile markup wins. Scripts, styles, templates, SVG
  * and anything marked hidden are removed before the text is taken, so the excerpt is what
  * is displayed, not what is merely present. CSS class based hiding cannot be seen from
  * here and is not claimed to be.
  *
  * @return array{title:string,meta_robots:string,canonical:string,h1:string,text:string,text_chars:int,text_truncated:bool,expected_count:?int}
  */
  public static function extract_page( string $html, int $max_chars = 4000, ?string $expected = null ): array {
    $out = [ 'title' => '', 'meta_robots' => '', 'canonical' => '', 'h1' => '', 'text' => '', 'text_chars' => 0, 'text_truncated' => false, 'expected_count' => null ];
    if ( trim( $html ) === '' ) {
      return $out;
    }
    $text = '';
    if ( class_exists( 'DOMDocument' ) ) {
      $prev = libxml_use_internal_errors( true );
      $doc = new DOMDocument();
      // The XML declaration forces UTF-8; without it loadHTML assumes Latin-1 and mangles
      // every multi-byte character on the page.
      $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
      libxml_clear_errors();
      libxml_use_internal_errors( $prev );
      $xp = new DOMXPath( $doc );

      $t = $xp->query( '//title' );
      if ( $t && $t->length ) {
        $out['title'] = self::squash( $t->item( 0 )->textContent );
      }
      foreach ( $xp->query( '//meta[@name]' ) as $m ) {
        if ( strtolower( $m->getAttribute( 'name' ) ) === 'robots' ) {
          $out['meta_robots'] = self::squash( $m->getAttribute( 'content' ) );
          break;
        }
      }
      foreach ( $xp->query( '//link[@rel][@href]' ) as $l ) {
        if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $l->getAttribute( 'rel' ) ) ) ), true ) ) {
          $out['canonical'] = self::squash( $l->getAttribute( 'href' ) );
          break;
        }
      }
      $h1 = $xp->query( '//h1' );
      if ( $h1 && $h1->length ) {
        $out['h1'] = self::squash( $h1->item( 0 )->textContent );
      }

      $drop = $xp->query( '//script|//style|//noscript|//template|//svg|//head|//iframe|//object|//embed|//*[@hidden]|//*[@aria-hidden="true"]|//*[contains(translate(@style," ",""),"display:none")]|//*[contains(translate(@style," ",""),"visibility:hidden")]' );
      $nodes = [];
      foreach ( $drop as $n ) {
        $nodes[] = $n;
      }
      foreach ( $nodes as $n ) {
        if ( $n->parentNode ) {
          $n->parentNode->removeChild( $n );
        }
      }
      // textContent glues "<h1>Title</h1><p>Text</p>" into "TitleText". A space after every
      // block-level element is what a reader's eye puts there.
      $blocks = [];
      foreach ( $xp->query( '//p|//div|//br|//h1|//h2|//h3|//h4|//h5|//h6|//li|//tr|//td|//th|//section|//article|//header|//footer|//nav|//aside|//main|//blockquote|//pre|//dt|//dd|//figcaption' ) as $n ) {
        $blocks[] = $n;
      }
      foreach ( $blocks as $n ) {
        if ( $n->parentNode ) {
          $n->parentNode->insertBefore( $doc->createTextNode( ' ' ), $n->nextSibling );
        }
      }
      $body = $xp->query( '//body' );
      $text = $body && $body->length ? $body->item( 0 )->textContent : $doc->textContent;
    }
    else {
      // No DOM extension: a cruder answer that says less rather than one that is wrong.
      if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
        $out['title'] = self::squash( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
      }
      $stripped = preg_replace( '#<(script|style|noscript|template|svg|head)\b.*?</\1\s*>#is', ' ', $html );
      $text = html_entity_decode( strip_tags( (string) $stripped ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    $text = self::squash( $text );
    $out['text_chars'] = mb_strlen( $text );
    if ( $expected !== null && $expected !== '' ) {
      $out['expected_count'] = mb_substr_count( mb_strtolower( $text ), mb_strtolower( $expected ) );
    }
    if ( $out['text_chars'] > $max_chars ) {
      $text = mb_substr( $text, 0, $max_chars );
      $out['text_truncated'] = true;
    }
    $out['text'] = $text;
    foreach ( [ 'title', 'meta_robots', 'canonical', 'h1' ] as $k ) {
      $out[ $k ] = mb_substr( $out[ $k ], 0, 500 );
    }
    return $out;
  }

  private static function squash( string $s ): string {
    $s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
    $s = preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $s );
    return trim( (string) preg_replace( '/\s+/u', ' ', (string) $s ) );
  }

  /* ----------------------------------------------------------------- the error log */

  /**
  * Whether what was opened is the regular file that was looked at: a regular file, on the same
  * device, with the same inode. Pure, so the swap it exists to catch can be tested without
  * racing one.
  *
  * @param array $lstat stat() of the path before opening, taken without following a link.
  * @param array $fstat fstat() of the handle that was then opened.
  */
  public static function same_regular_file( array $lstat, array $fstat ): bool {
    return ( ( $fstat['mode'] ?? 0 ) & 0170000 ) === 0100000
      && ( ( $lstat['mode'] ?? 0 ) & 0170000 ) === 0100000
      && ( $lstat['dev'] ?? null ) === ( $fstat['dev'] ?? false )
      && ( $lstat['ino'] ?? null ) === ( $fstat['ino'] ?? false );
  }

  /**
  * Where, lexically, a configured log path sits relative to the install, and the path the kernel
  * must report for the file once it is open.
  *
  * A path under the content directory or the WordPress root is rebuilt from that root's
  * canonical form plus the components the configuration names, with no "." or ".." allowed. It
  * is NOT canonicalised as a whole: realpath() of the whole path would follow a directory that
  * has been swapped for a link, and the answer would then agree with whatever the swap
  * pointed at. Everything below the root is therefore a component the install put there, and a
  * component that is a link is refused (see dir_chain_problem()). A path outside both roots
  * (a host's /var/log/php/error.log) has no install to anchor to, so its directory is
  * canonicalised once and trusted from there up.
  *
  * @param string[] $roots the content directory and the WordPress root.
  * @return array{ok:bool,error:string,expected:string,dirs:string[],below_root:bool}
  */
  public static function resolve_log( string $configured, array $roots ): array {
    $fail = static function ( string $why ): array {
      return [ 'ok' => false, 'error' => $why, 'expected' => '', 'dirs' => [], 'below_root' => false ];
    };
    if ( $configured === '' || $configured[0] !== '/' || strpos( $configured, "\0" ) !== false ) {
      return $fail( 'The configured log path is not an absolute path (a relative error_log is not read: the directory PHP resolves it against depends on how PHP is run, so this tool will not guess which file that is). Set error_log to an absolute path, or use WP_DEBUG_LOG.' );
    }
    $best = null;
    clearstatcache( true ); // including the realpath cache, which is not re-validated for minutes
    foreach ( $roots as $root ) {
      $root = rtrim( (string) $root, '/\\' );
      if ( $root === '' ) {
        continue;
      }
      $real = realpath( $root );
      if ( $real === false ) {
        continue;
      }
      foreach ( array_unique( [ $root, $real ] ) as $prefix ) {
        if ( strpos( $configured, $prefix . '/' ) === 0 && ( $best === null || strlen( $prefix ) > strlen( $best[0] ) ) ) {
          $best = [ $prefix, $real ];
        }
      }
    }
    if ( $best !== null ) {
      $rel = array_values( array_filter( explode( '/', substr( $configured, strlen( $best[0] ) + 1 ) ), static function ( $seg ) {
        return $seg !== '';
      } ) );
      foreach ( $rel as $seg ) {
        if ( $seg === '.' || $seg === '..' ) {
          return $fail( 'The configured log path contains "." or ".." components, so it is not read.' );
        }
      }
      if ( !$rel ) {
        return $fail( 'The configured log path is a directory.' );
      }
      $dirs = [];
      $cur = $best[1];
      foreach ( array_slice( $rel, 0, -1 ) as $seg ) {
        $cur .= '/' . $seg;
        $dirs[] = $cur;
      }
      return [ 'ok' => true, 'error' => '', 'expected' => $cur . '/' . end( $rel ), 'dirs' => $dirs, 'below_root' => true, 'root' => $best[1] ];
    }
    $dir = realpath( dirname( $configured ) );
    if ( $dir === false ) {
      return $fail( 'The directory the log would be written to does not exist.' );
    }
    return [ 'ok' => true, 'error' => '', 'expected' => rtrim( $dir, '/' ) . '/' . basename( $configured ), 'dirs' => [], 'below_root' => false, 'root' => $dir ];
  }

  /**
  * A reason the directories between the install and the log cannot be trusted, or null.
  *
  * Refused: a component that is a symbolic link or is not a directory, or is missing. Reported
  * but not refused (the second element): a directory that accounts other than the web user can
  * write to (world-writable without the sticky bit, or group-writable by a group the web user is
  * not in), because whoever can write to a directory can rename what is in it, which is the
  * race this module narrows. Refusing there would disable the tool on shared hosts for a
  * condition the other checks already catch when it is acted on.
  *
  * @param string[] $dirs absolute directories, outermost first.
  * @param int[]    $gids the groups the web user belongs to; null $uid skips the permission report.
  * @return array{0:?string,1:string[]}
  */
  public static function dir_chain_problem( array $dirs, ?int $uid = null, array $gids = [] ): array {
    $weak = [];
    clearstatcache( true );
    foreach ( $dirs as $d ) {
      $st = @lstat( $d );
      if ( !$st ) {
        return [ 'A directory on the path to the log (' . basename( $d ) . ') is missing.', $weak ];
      }
      $type = $st['mode'] & 0170000;
      if ( $type === 0120000 ) {
        return [ 'A directory on the path to the log (' . basename( $d ) . ') is a symbolic link, which is not followed: a link can be swapped to point somewhere else.', $weak ];
      }
      if ( $type !== 0040000 ) {
        return [ 'A component on the path to the log (' . basename( $d ) . ') is not a directory.', $weak ];
      }
      if ( $uid !== null ) {
        $m = $st['mode'];
        if ( $m & 01000 ) {
          continue; // sticky: others can add to the directory but cannot rename or remove what is not theirs
        }
        if ( $m & 0002 ) {
          $weak[] = basename( $d ) . ' is writable by every account on the host';
        }
        elseif ( ( $m & 0020 ) && !in_array( (int) $st['gid'], $gids, true ) ) {
          $weak[] = basename( $d ) . ' is writable by a group the web server user is not in';
        }
      }
    }
    return [ null, $weak ];
  }

  /**
  * The paths by which this process holds a file open, read from /proc, or null where /proc
  * cannot say (not Linux, or hidden). The kernel reports the path of the open file itself, with
  * every link already resolved, so it names where the bytes in the handle really are, whatever
  * the path used to open them said. A file opened and then unlinked reports " (deleted)".
  *
  * @param array $fstat fstat() of the handle.
  * @return string[]|null
  */
  private static function open_paths( array $fstat ): ?array {
    $dir = '/proc/self/fd';
    if ( !@is_dir( $dir ) ) {
      return null;
    }
    $names = @scandir( $dir );
    if ( !is_array( $names ) ) {
      return null;
    }
    $paths = [];
    foreach ( $names as $n ) {
      if ( !ctype_digit( $n ) ) {
        continue;
      }
      $x = @stat( "$dir/$n" );
      if ( !$x || $x['ino'] !== $fstat['ino'] || $x['dev'] !== $fstat['dev'] ) {
        continue;
      }
      $l = @readlink( "$dir/$n" );
      if ( is_string( $l ) ) {
        $paths[] = $l;
      }
    }
    return $paths ?: null;
  }

  /**
  * fopen() for reading with O_NONBLOCK ("n"). Opening a pipe for reading blocks until a writer
  * appears; a file that is swapped for a pipe between the check and this call would hold the
  * worker there for good. Non-blocking, the open returns, and the caller sees from fstat()
  * that it is not a regular file. Harmless for a regular file. Kept apart so a test can open a
  * pipe through exactly this.
  *
  * @return resource|false
  */
  public static function open_nonblocking( string $path ) {
    return @fopen( $path, 'rn' );
  }

  /**
  * Open a file for reading only if it is, and stays, the regular file at the expected path.
  *
  * PHP cannot ask the kernel to refuse a link (there is no O_NOFOLLOW), so this does not claim
  * to prevent a swap; it makes the swap visible and refuses to read when it is seen:
  *
  *  1. lstat() the path: a link, a pipe or a device is refused untouched.
  *  2. Open with the "n" mode flag (O_NONBLOCK). A pipe swapped in after step 1 would otherwise
  *     block this worker in open() until something wrote to it; non-blocking, the open returns
  *     and step 3 refuses it.
  *  3. fstat() the handle: a regular file, with the device and inode that step 1 saw.
  *  4. lstat() the path again: still that regular file. A path that stopped being it while it
  *     was being opened is refused, even though the handle is now fixed, because "what was
  *     read" and "what is at the path" should not have been allowed to disagree.
  *  5. Where /proc exists, ask the kernel where the open file really is and require it to be
  *     $expected. That is the check that catches a swapped DIRECTORY, which the three above
  *     cannot: lstat and open both walk the swapped directory and agree with each other.
  *
  * Everything after this reads the handle and never the path again.
  *
  * @return array{fh:?resource,error:string,identity:string,stat:array}
  */
  public static function open_verified( string $path, ?string $expected = null ): array {
    $o = [ 'fh' => null, 'error' => '', 'identity' => 'unchecked', 'stat' => [] ];
    // PHP keeps a stat cache (and a realpath cache) for the request. It is cleared when PHP
    // itself changes a file, not when someone else does, so a second look at the same path is
    // not guaranteed to be a second look. Not measured to matter here; cheap enough not to rely on.
    clearstatcache( true );
    $before = @lstat( $path );
    if ( !$before || ( $before['mode'] & 0170000 ) !== 0100000 ) {
      $o['error'] = 'The path is not a regular file (a symbolic link, pipe or device is not read).';
      return $o;
    }
    $fh = self::open_nonblocking( $path );
    if ( !$fh ) {
      $o['error'] = 'The file could not be opened for reading.';
      return $o;
    }
    $st = fstat( $fh );
    if ( !$st || !self::same_regular_file( $before, $st ) ) {
      fclose( $fh );
      $o['error'] = 'The path is not the regular file it was a moment ago (it was replaced while being opened), so it was not read.';
      return $o;
    }
    clearstatcache( true, $path );
    $after = @lstat( $path );
    if ( !$after || !self::same_regular_file( $after, $st ) ) {
      fclose( $fh );
      $o['error'] = 'The path stopped being that regular file while it was being opened (replaced or rotated), so it was not read. Asking again is safe.';
      return $o;
    }
    if ( $expected !== null ) {
      $paths = self::open_paths( $st );
      if ( $paths !== null ) {
        if ( !in_array( $expected, $paths, true ) ) {
          fclose( $fh );
          $o['error'] = 'The file that was opened is not at the path the log is configured to live at (a directory on the way was swapped), so it was not read.';
          return $o;
        }
        $o['identity'] = 'kernel';
      }
    }
    $o['fh'] = $fh;
    $o['stat'] = $st;
    return $o;
  }

  /**
  * The end of a file, read from the end.
  *
  * Never reads more than $max_bytes (+1, to learn whether the first line it has is whole),
  * so a log of several gigabytes costs the same as a log of several kilobytes. The handle is
  * checked after it is opened rather than the path before, because a path can change
  * between a check and an open and a handle cannot (see open_verified()).
  *
  * @param string|null $expected the canonical path the file must be open at (open_verified()).
  * @return array{ok:bool,error:string,size:int,mtime:int,lines:string[],lines_available:int,starts_mid_file:bool,identity:string}
  */
  public static function read_tail( string $path, int $max_lines, int $max_bytes, ?string $expected = null ): array {
    $r = [ 'ok' => false, 'error' => '', 'size' => 0, 'mtime' => 0, 'lines' => [], 'lines_available' => 0, 'starts_mid_file' => false, 'identity' => 'unchecked' ];
    $o = self::open_verified( $path, $expected );
    if ( !$o['fh'] ) {
      $r['error'] = $o['error'];
      return $r;
    }
    $fh = $o['fh'];
    $st = $o['stat'];
    $r['identity'] = $o['identity'];
    $size = (int) $st['size'];
    $r['size'] = $size;
    $r['mtime'] = (int) $st['mtime'];
    if ( $size === 0 ) {
      fclose( $fh );
      $r['ok'] = true;
      return $r;
    }
    $from = max( 0, $size - $max_bytes );
    $lead = $from > 0 ? 1 : 0;
    fseek( $fh, $from - $lead );
    $chunk = (string) fread( $fh, $size - $from + $lead );
    fclose( $fh );

    $whole_first = true;
    if ( $lead ) {
      $whole_first = $chunk !== '' && ( $chunk[0] === "\n" || $chunk[0] === "\r" );
      $chunk = substr( $chunk, 1 );
      $r['starts_mid_file'] = true;
    }
    $lines = preg_split( '/\r\n|\n|\r/', $chunk );
    if ( $lines && end( $lines ) === '' ) {
      array_pop( $lines );
    }
    if ( $lead && !$whole_first ) {
      array_shift( $lines ); // a partial line says nothing reliable
    }
    $r['lines_available'] = count( $lines );
    $r['lines'] = array_slice( $lines, -$max_lines );
    $r['ok'] = true;
    return $r;
  }

  /**
  * A configured log path, checked and read: resolve_log(), then dir_chain_problem(), then
  * read_tail() against the path the kernel must report. The one entry point the tool and the
  * race test both use, so the test exercises what ships.
  *
  * Where /proc cannot confirm the open file's path, the directory chain is checked again after
  * the read and the lines are discarded if it no longer holds.
  *
  * @param string[] $roots content directory and WordPress root.
  * @return array{ok:bool,error:string,size:int,mtime:int,lines:string[],lines_available:int,starts_mid_file:bool,identity:string,warnings:string[],expected:string}
  */
  public static function read_log( string $configured, array $roots, int $max_lines, int $max_bytes, ?int $uid = null, array $gids = [] ): array {
    $r = [ 'ok' => false, 'error' => '', 'size' => 0, 'mtime' => 0, 'lines' => [], 'lines_available' => 0, 'starts_mid_file' => false, 'identity' => 'unchecked', 'warnings' => [], 'expected' => '' ];
    $loc = self::resolve_log( $configured, $roots );
    if ( !$loc['ok'] ) {
      $r['error'] = $loc['error'];
      return $r;
    }
    $r['expected'] = $loc['expected'];
    $perm_dirs = array_merge( [ $loc['root'] ], $loc['dirs'] );
    [ $problem, $weak ] = self::dir_chain_problem( $perm_dirs, $uid, $gids );
    if ( $problem !== null ) {
      $r['error'] = $problem;
      return $r;
    }
    $t = self::read_tail( $loc['expected'], $max_lines, $max_bytes, $loc['expected'] );
    $t['warnings'] = $weak ? [ 'Directories on the path to the log are writable by other accounts (' . implode( '; ', $weak ) . '). Whoever can write to a directory can swap the log for something else while it is being read. The reader refuses a swap it sees, but a race cannot be ruled out from inside PHP.' ] : [];
    $t['expected'] = $loc['expected'];
    if ( $t['ok'] && $t['identity'] !== 'kernel' ) {
      [ $again ] = self::dir_chain_problem( $perm_dirs );
      if ( $again !== null ) {
        return [ 'ok' => false, 'error' => $again . ' (found after the read; nothing is returned).', 'lines' => [] ] + $r;
      }
    }
    return $t;
  }

  /* ----------------------------------------------------------------- preview tokens */

  /**
  * A preview link's token cut out of a string, whichever way the parameter name was spelled.
  *
  * PHP turns "gmcp.preview", "gmcp preview" and "gmcp[preview" into $_GET['gmcp_preview'],
  * and a client may percent-encode any character of the name or the "=", so a scrubber that
  * looks for the literal "gmcp_preview=" misses a link that works. Each letter of the name
  * is therefore matched as itself or as its %XX form, the separator as any character PHP
  * maps to "_" (or its encoding), and the value as the run of token characters after the
  * "=" (or %3D). The value is replaced even when it is not 40 hex characters: a shorter
  * string after that key is still something somebody meant as a credential.
  *
  * Used for anything that is written down: the audit log, the debug log, the error-log
  * reader. Not for what a tool returns to its caller about the site's own data.
  */
  public static function scrub_preview_tokens( string $text ): string {
    if ( stripos( $text, 'preview' ) === false && stripos( $text, 'download' ) === false
      && stripos( $text, 'upload' ) === false && stripos( $text, 'export' ) === false
      && stripos( $text, '%' ) === false ) {
      return $text;
    }
    // The one-time URLs are bearer credentials too, and the tool reply that hands one out is
    // copied into this log. Their tokens are alphanumeric, so they need none of the
    // percent-encoding handling below: cut the path segment and keep the route. Upload,
    // download and export differ only in the route they sit under. The reply is JSON, so its
    // slashes may arrive escaped as \/ and the pattern has to accept either spelling; the
    // first version did not, and a token in an escaped URL sat in the log unredacted.
    $text = (string) preg_replace( '#(\\\\?/(?:download|upload|export)\\\\?/)[A-Za-z0-9]{8,}#i', '$1[redacted]', $text );
    static $re = null;
    if ( $re === null ) {
      $lit = static function ( string $word ): string {
        $out = '';
        foreach ( str_split( $word ) as $ch ) {
          $out .= '(?:' . $ch . '|%' . strtoupper( bin2hex( $ch ) ) . '|%' . strtoupper( bin2hex( strtoupper( $ch ) ) ) . ')';
        }
        return $out;
      };
      $sep = '(?:[._ +\\[]|%5[Ff]|%2[Ee]|%20|%2[Bb]|%5[Bb])';
      $re = '/(' . $lit( 'gmcp' ) . $sep . $lit( 'preview' ) . '(?:\\]|%5[Dd])?(?:=|%3[Dd]))[A-Za-z0-9_%.-]+/i';
    }
    return (string) preg_replace( $re, '$1[redacted]', $text );
  }

  /* ------------------------------------------------------------------- log scrubbing */

  /**
  * One line of a PHP error log, made safe to hand to a model.
  *
  * "Safe" is claimed narrowly. A log line is free text written by PHP, by plugins and
  * sometimes by visitors (a 404 URL, a user agent, a post title in a notice), so what is
  * done here is bounded: structure that is recognisably secret is removed, and everything
  * else is passed through unchanged. A secret in an unrecognisable shape inside a message
  * survives, which is why the tool is at administration level and says so.
  *
  * What is removed: credentials by field name (the same list the audit log and the change
  * journal use, passed in as $ctx['patterns']), bearer and basic credentials, user
  * information in URLs, JWTs, password hashes, this plugin's own keys, long opaque tokens,
  * e-mail addresses, public IP addresses, the argument values in stack frames, and the
  * values in a failed SQL query beyond its first stretch. Absolute paths are shortened to
  * the content directory or the WordPress root.
  *
  * @param array{patterns?:string[],content_dir?:string,abspath?:string} $ctx
  */
  public static function scrub_log_line( string $line, array $ctx = [] ): string {
    $line = mb_convert_encoding( $line, 'UTF-8', 'UTF-8' );
    $line = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $line );
    $truncated = false;
    if ( mb_strlen( $line ) > 2000 ) {
      $line = mb_substr( $line, 0, 2000 );
      $truncated = true;
    }

    // Paths, longest first so the content directory is not swallowed by the root.
    $roots = [];
    foreach ( [ 'content_dir' => '[content]', 'abspath' => '[abspath]' ] as $k => $label ) {
      $p = isset( $ctx[ $k ] ) ? rtrim( (string) $ctx[ $k ], '/\\' ) : '';
      if ( $p !== '' ) {
        $roots[ $p ] = $label;
      }
    }
    uksort( $roots, static function ( $a, $b ) {
      return strlen( $b ) <=> strlen( $a );
    } );
    foreach ( $roots as $p => $label ) {
      $line = str_replace( $p, $label, $line );
    }

    // Stack frame: "#3 /path/file.php(45): func('arg', Array)". The arguments are whatever
    // the call was made with, and zend.exception_ignore_args is off on many hosts.
    if ( preg_match( '/^#\d+\s/', $line ) ) {
      $line = (string) preg_replace_callback( '/\):\s.*$/', static function ( $m ) {
        return (string) preg_replace( '/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', "'…'", $m[0] );
      }, $line );
    }

    // A failed query is printed whole, values included.
    $line = (string) preg_replace_callback( '/(\bfor query\s)(.*)$/s', static function ( $m ) {
      return mb_strlen( $m[2] ) > 120 ? $m[1] . mb_substr( $m[2], 0, 120 ) . ' …[rest of the query omitted]' : $m[0];
    }, $line );

    // A private key printed whole (a JSON-escaped one is a single line), or from its first line
    // to the end of the line when the log line was cut before the end marker. The lines of a key
    // printed over several lines are handled by scrub_log_lines(); only a line holding the
    // beginning of one is seen here.
    $line = (string) preg_replace( '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY[A-Z0-9 ]*-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY[A-Z0-9 ]*-----|$)/s', '[private-key-block]', $line );

    // Cookies. A Cookie or Set-Cookie header is redacted to the end of the line: its value
    // holds the login cookie (user name, expiry, session token and hash), and the name of a
    // WordPress cookie is long and hex, so the name-based rules below would turn the NAME into
    // a token marker and leave the value. Then the same cookies by name, for dumps of $_COOKIE
    // and of a parsed header ("[wordpress_logged_in_<hash>] => admin|...").
    $line = (string) preg_replace( '/\b((?:Set-)?Cookie2?\s*:\s*)[^\r\n]*/i', '$1[redacted]', $line );
    $line = (string) preg_replace(
      '/(?<![A-Za-z0-9_-])((?:wordpress_(?:logged_in|sec|rec)_[A-Za-z0-9]*|wordpress_[a-f0-9]{32}|wp-postpass_[A-Za-z0-9]*|wp_woocommerce_session_[A-Za-z0-9]*|comment_author_(?:email_|url_)?[A-Za-z0-9]*|PHPSESSID)\]?["\']?\s*(?:=>|[:=])\s*)("[^"]*"|\'[^\']*\'|[^\s;,&)\]}]+)/i',
      '$1[redacted]',
      $line
    );

    // Credentials carried as headers or inside URLs.
    $line = (string) preg_replace( '/\b(Authorization\s*:\s*)(?:Bearer|Basic|Digest|Token)?\s*[^\s,;]+/i', '$1[redacted]', $line );
    $line = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted]', $line );
    // Everything between "scheme://" and the last "@" of the authority, so an empty user name
    // (redis://:password@host) and a password that itself contains "@" are covered, and so is
    // "https://example.com@evil.example/", where the part before the "@" is not the host.
    $line = (string) preg_replace( '~(\b[a-z][a-z0-9+.-]*://)[^/\s?#]*@~i', '$1[redacted]@', $line );

    // key=value, key: value, "key":"value", key => value, where the key looks secret. This
    // is also what clears ?token=... in a logged URL.
    $patterns = array_values( array_filter( array_map( 'strval', $ctx['patterns'] ?? self::DEFAULT_SECRET_PATTERNS ), 'strlen' ) );
    $patterns[] = 'gmcp_preview';
    $line = self::scrub_preview_tokens( $line );
    if ( $patterns ) {
      $alt = implode( '|', array_map( static function ( $p ) {
        return preg_quote( $p, '#' );
      }, $patterns ) );
      $line = (string) preg_replace(
        // The lookbehind keeps a match from starting in the middle of a run of name characters.
        // Without it the leading [A-Za-z0-9_.\-\[\]]* is tried from every position of a long run
        // and backtracks over all of it each time, which is quadratic: measured at about 0.3 s for
        // one 2000-character line with the PCRE JIT off (0.4 ms with it). The match that matters
        // starts at the beginning of the run anyway, so what is redacted does not change.
        //
        // A quote around the name or the value may be a curly one (a value copied out of a document
        // keeps its typographic quotes), the separator may be a fullwidth "＝" or "：", and a quoted
        // value may hold an escaped quote of its own ('ab\'cd'): a rule that ended the value at the
        // first quote it met left the rest of the secret behind.
        '#(?<![A-Za-z0-9_.\-\[\]])((?:["\']|“|”|‘|’)?[A-Za-z0-9_.\-\[\]]*(?:' . $alt . ')[A-Za-z0-9_.\-\[\]]*(?:["\']|“|”|‘|’)?)(\s*(?:=>|[:=]|＝|：)\s*)(?!' . self::OWN_MARKER . ')(' . self::QUOTED_VALUE . '|[^\s&,;)\]}]+)#i',
        '$1$2[redacted]',
        $line
      );
      // The same in a URL, where a JSON object is encoded whole: %22access_token%22%3A%22value%22.
      // The value ends at an encoded quote, comma or closing brace, or at the next parameter.
      $line = (string) preg_replace(
        '#(%22[A-Za-z0-9_.\-]{0,60}?(?:' . $alt . ')[A-Za-z0-9_.\-]{0,60}%22(?:%253[Aa]|%3[Aa])(?:%22)?)(?!' . self::OWN_MARKER . ')((?:[^\s&,;)\]}"\'%]|%(?!22|2[Cc]|7[Dd]))+)#i',
        '$1[redacted]',
        $line
      );
      // The same thing inside a JSON string that is itself inside a log line, where the
      // quotes arrive escaped: {\"password\":\"value\"}. The rule above needs the key to be
      // followed by its separator, and a backslash is in the way.
      $line = (string) preg_replace(
        '#(\\\\"[A-Za-z0-9_.\-]*(?:' . $alt . ')[A-Za-z0-9_.\-]*\\\\"\s*:\s*)(\\\\"(?:(?!\\\\").)*\\\\"|[^\s,}\]\\\\]+)#i',
        '$1[redacted]',
        $line
      );
      // PHP serialized data: s:9:"user_pass";s:7:"hunter2"; (an options dump, a session, a
      // user meta row). The name and the value are separate strings, so no separator sits
      // between them for the rule above. The value is read up to its closing quote, which is
      // as far as a value without a quote in it goes; one with a quote in it is cut short, and
      // the rest stays. The name is bounded so a long line cannot make this quadratic.
      $line = (string) preg_replace(
        '#(\bs:\d{1,5}:"[^"]{0,100}?(?:' . $alt . ')[^"]{0,100}";\s*)(?:s:\d{1,7}:"(?:[^"\\\\]|\\\\.)*";|[idb]:[^;]{0,60};)#i',
        '$1s:10:"[redacted]";',
        $line
      );
      // An element named like a secret: <Password>value</Password>, <api_key id="1">value</api_key>.
      // Closed on the same line and by the same name; an element left open (a value cut off by
      // the log line's end, or one printed over several lines) is not recognised. The value may be
      // a CDATA section, whose content can hold "<" and ">": <password><![CDATA[x]]></password>.
      $line = (string) preg_replace(
        '#(<([A-Za-z0-9_.:\-]{0,60}?(?:' . $alt . ')[A-Za-z0-9_.:\-]{0,60})(?:\s[^<>]{0,300})?>)(?:<!\[CDATA\[(?:(?!\]\]>).){0,2000}\]\]>|[^<]{1,2000})(</\2\s*>)#i',
        '$1[redacted]$3',
        $line
      );
      // The same assignment URL-encoded, which is how it sits in a logged query string or in a
      // form body that was encoded twice: token%3Dabc, %26password%3Dabc, password%253Dabc. The
      // value ends at an encoded "&", ";", "," or space, which are what separate the next field.
      $line = (string) preg_replace(
        '#(?<![A-Za-z0-9_.\-\[\]])(["\']?[A-Za-z0-9_.\-\[\]]*(?:' . $alt . ')[A-Za-z0-9_.\-\[\]]*["\']?)((?:%253[Dd]|%3[Dd]))(?!' . self::OWN_MARKER . ')((?:[^\s&,;)\]}"\'%]|%(?!26|3[Bb]|2[Cc]|20|25(?:26|3[Bb])))+)#i',
        '$1$2[redacted]',
        $line
      );
      // A command line: --password hunter2, --api-key hunter2 (the "=" form is the name rule's).
      // Only for option names that are a secret by themselves, because the next word of
      // "--key-file x" or "--no-token-check yes" is not one.
      $line = (string) preg_replace(
        '#(?<![A-Za-z0-9_\-])(--(?:[A-Za-z0-9]+-)?(?:password|passwd|passphrase|pass|pwd|secret|token|api-?key|auth-?token|access-?key|client-?secret)\s+)("[^"]{1,300}"|\'[^\']{1,300}\'|[^\s"\'-][^\s"\']{0,300})#i',
        '$1[redacted]',
        $line
      );
    }

    // Shapes the name-based rule above cannot see.
    //  - define( 'DB_PASSWORD', 'value' ): the name and the value are separated by a comma, not
    //    by "=" or ":", and a PHP notice quoting a line of wp-config.php prints exactly this.
    //  - pw=value: "pw" is too short to be one of the substring patterns without matching
    //    "power" and "pwa", so it is matched as a whole word.
    $line = (string) preg_replace(
      '/(\bdefine\s*\(\s*([\'"])[A-Za-z0-9_]*(?:PASS|PWD|PW|SECRET|KEY|SALT|TOKEN|AUTH|CREDENTIAL)[A-Za-z0-9_]*\2\s*,\s*)(?:' . self::QUOTED_VALUE . '|[^\s,)]+)/i',
      '$1[redacted]',
      $line
    );
    $line = (string) preg_replace(
      '/(?<![A-Za-z0-9_])(pw)(\s*(?:=>|[:=]|＝|：)\s*)(?!' . self::OWN_MARKER . ')(' . self::QUOTED_VALUE . '|[^\s&,;)\]}]+)/i',
      '$1$2[redacted]',
      $line
    );
    // A database client's -pPASSWORD, written with no space (with a space, "-p" is a prompt and
    // has no password after it). Only after a command that is one of those clients: -p is a
    // port, a path or a pattern in most other programs. Case matters: -P is the port.
    $line = (string) preg_replace(
      '~((?i:\b(?:mysql[a-z]*|mariadb[a-z-]*))\b[^\r\n]{0,400}?\s-p)(?=[^\s-])("[^"]*"|\'[^\']*\'|\S+)~',
      '$1[redacted]',
      $line
    );
    // Azure shared access signatures: sig=<base64, percent-encoded> in a connection string or a
    // URL. "sig" is too short to be one of the substring patterns without matching "signal" and
    // "design", so it is a whole word, like "pw".
    $line = (string) preg_replace(
      '/(?<![A-Za-z0-9_])(sig)(%253[Dd]|%3[Dd]|\s*(?:=>|[:=])\s*)(?!' . self::OWN_MARKER . ')("[^"]*"|\'[^\']*\'|(?:[^\s&,;)\]}"\'%]|%(?!26|3[Bb]|2[Cc]|20))+)/i',
      '$1$2[redacted]',
      $line
    );
    // AWS: an access key id has a fixed prefix; a secret access key is 40 characters of base64
    // that may contain "/" and "+", which the long-token rule below treats as path delimiters,
    // so one is matched here when it mixes upper case, lower case and digits (a 40-character
    // lower-case hex string is a commit hash and is left alone).
    $line = (string) preg_replace( '/\b(?:AKIA|ASIA|AGPA|AIDA|AROA|ANPA|ANVA|AIPA)[A-Z0-9]{16}\b/', '[aws key id]', $line );
    $line = (string) preg_replace_callback( '#(?<![A-Za-z0-9/+=_.-])[A-Za-z0-9/+]{40}(?![A-Za-z0-9/+=_-])#', static function ( $m ) {
      return ( preg_match( '/[A-Z]/', $m[0] ) && preg_match( '/[a-z]/', $m[0] ) && preg_match( '/\d/', $m[0] ) ) ? '[aws secret]' : $m[0];
    }, $line );

    // Keys with a fixed vendor prefix, which a plugin's failed API call prints and which are
    // shorter than the generic long-token rule or contain only letters: payment, source
    // hosting, chat, mail and maps providers that WordPress sites commonly talk to.
    $line = (string) preg_replace( '/\b(?:[sr]k_(?:live|test)_[A-Za-z0-9]{10,}|whsec_[A-Za-z0-9]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|xox[abeprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{30,}|SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}|glpat-[A-Za-z0-9_-]{16,}|key-[0-9a-f]{32})(?![A-Za-z0-9_-])/', '[api key]', $line );
    $line = (string) preg_replace( '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/', '[jwt]', $line );
    $line = (string) preg_replace( '#\$(?:P|H)\$[A-Za-z0-9./]{20,}|\$wp\$2y\$\d+\$[A-Za-z0-9./]{20,}|\$2[aby]\$\d{2}\$[A-Za-z0-9./]{40,}#', '[hash]', $line );
    $line = (string) preg_replace( '/\bgmcp_[A-Za-z0-9]{4,}_[A-Za-z0-9]{6,}/', '[gmcp key]', $line );
    $line = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $line );

    // Public addresses only: the loopback and private ones in "cURL error 7 ... 127.0.0.1"
    // are the diagnosis, and are not a person.
    $line = (string) preg_replace_callback( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', static function ( $m ) {
      return self::classify_ip( $m[0] ) === 'public' ? '[ip]' : $m[0];
    }, $line );
    $line = (string) preg_replace_callback( '/(?<![A-Za-z0-9:])[0-9a-fA-F]{0,4}(?::[0-9a-fA-F]{0,4}){2,7}(?![A-Za-z0-9:])/', static function ( $m ) {
      return self::classify_ip( $m[0] ) === 'public' ? '[ip]' : $m[0];
    }, $line );

    // Anything long enough, unbroken, and mixing letters with digits is far likelier a key
    // than a word. Slashes and dots delimit a path component and are excluded.
    $line = (string) preg_replace_callback( '#(?<![A-Za-z0-9_/.\\\\-])[A-Za-z0-9_+\-]{32,}(?![A-Za-z0-9_/.\\\\-])#', static function ( $m ) {
      return ( preg_match( '/\d/', $m[0] ) && preg_match( '/[A-Za-z]/', $m[0] ) ) ? '[token]' : $m[0];
    }, $line );

    // A line that is nothing but base64 is the body of something that was printed over several
    // lines (a key, a certificate, an attachment), and one such line is not worth the risk
    // of being a secret. Only when it fills the line: base64 inside a sentence is left to the
    // rules above.
    if ( preg_match( '~^\s*[A-Za-z0-9+/]{40,}={0,2}\s*$~', $line ) ) {
      $line = '[base64 line redacted]';
    }

    return $truncated ? $line . ' …[line truncated]' : $line;
  }

  /**
  * Several lines of a log, scrubbed one by one, plus the two things a single line cannot know:
  * that it is inside a private key that began on an earlier line, and that it is the value of
  * a field whose name was on the line before.
  *
  * From a line holding "-----BEGIN ... PRIVATE KEY-----" with no end marker, every following
  * line is withheld up to the line with the end marker. "No end marker" means the last BEGIN on
  * the line comes after its last END: a line that closes one block and opens a second is still
  * inside a key. A missing end marker cannot swallow the log: the block also ends at a line
  * that starts a new log record (a timestamp in brackets or "PHP "), which no line of a key
  * does, and after 100 lines. A window that starts in the middle of a key has no beginning to
  * see; its base64 lines are caught by the whole-line rule in scrub_log_line().
  *
  * var_dump(), var_export() and print_r() of a nested value put the name and the value on two
  * lines (`["password"]=>` then `string(7) "hunter2"`). When a line ends in "=>" and its name
  * looks secret, the next line is withheld, unless it opens an array or an object, whose own
  * members are judged by their own names.
  *
  * @param string[] $lines
  * @return string[] the same number of lines.
  */
  public static function scrub_log_lines( array $lines, array $ctx = [] ): array {
    $out = [];
    $in_key = 0;
    $value_next = false;
    $alt = self::secret_name_alternation( $ctx );
    $record_start = '/^\[\d{1,2}-[A-Za-z]{3}-\d{4}[ \]]|^\[\d{4}-\d\d-\d\d|^PHP /';
    foreach ( $lines as $line ) {
      $line = (string) $line;
      $was_value_next = $value_next;
      $value_next = false;
      if ( $in_key > 0 ) {
        if ( preg_match( '/-----END [A-Z0-9 ]*PRIVATE KEY[A-Z0-9 ]*-----/', $line ) ) {
          $in_key = 0;
          $out[] = '[private-key-block ends]';
          continue;
        }
        if ( $in_key < 100 && !preg_match( $record_start, $line ) ) {
          $in_key++;
          $out[] = '[private-key-block line]';
          continue;
        }
        $in_key = 0; // a new record, or too long: stop withholding and scrub this line normally
      }
      if ( $was_value_next && !preg_match( $record_start, $line ) && !preg_match( '/^\s*(?:array|object|stdClass|\(|\{|\)|\})/i', $line ) && trim( $line ) !== '' ) {
        $out[] = '[redacted]';
        continue;
      }
      $begin = self::last_offset( $line, '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY[A-Z0-9 ]*-----/' );
      if ( $begin !== -1 && $begin > self::last_offset( $line, '/-----END [A-Z0-9 ]*PRIVATE KEY[A-Z0-9 ]*-----/' ) ) {
        $in_key = 1;
      }
      if ( $alt !== '' && preg_match( '/^(.{0,300}?)=>\s*$/', $line, $m ) && preg_match( '#(?:' . $alt . ')#i', $m[1] ) ) {
        $value_next = true;
      }
      $out[] = self::scrub_log_line( $line, $ctx );
    }
    return $out;
  }

  /** The offset where the last match of $pattern begins in $text, or -1 for none. */
  private static function last_offset( string $text, string $pattern ): int {
    $last = -1;
    if ( preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
      foreach ( $m[0] as $hit ) {
        $last = max( $last, (int) $hit[1] );
      }
    }
    return $last;
  }

  /** The words that make a field name secret, as a regex alternation. */
  private static function secret_name_alternation( array $ctx ): string {
    $patterns = array_values( array_filter( array_map( 'strval', $ctx['patterns'] ?? self::DEFAULT_SECRET_PATTERNS ), 'strlen' ) );
    $patterns[] = 'gmcp_preview';
    return implode( '|', array_map( static function ( $p ) {
      return preg_quote( $p, '#' );
    }, $patterns ) );
  }
}
