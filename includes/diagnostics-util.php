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

    return [ 'ok' => true, 'reason' => '', 'url' => $rebuilt, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips, 'pin' => $ips[0] ];
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
  * The end of a file, read from the end.
  *
  * Never reads more than $max_bytes (+1, to learn whether the first line it has is whole),
  * so a log of several gigabytes costs the same as a log of several kilobytes. The handle is
  * checked after it is opened rather than the path before, because a path can change
  * between a check and an open and a handle cannot: it must be a regular file.
  *
  * @return array{ok:bool,error:string,size:int,mtime:int,lines:string[],lines_available:int,starts_mid_file:bool}
  */
  public static function read_tail( string $path, int $max_lines, int $max_bytes ): array {
    $r = [ 'ok' => false, 'error' => '', 'size' => 0, 'mtime' => 0, 'lines' => [], 'lines_available' => 0, 'starts_mid_file' => false ];
    // lstat() does not follow a link and does not open anything, so a symbolic link, a FIFO
    // (an open for reading would block this worker until something wrote to it) or a device is
    // refused before it is touched. The caller checked the same things a moment earlier; a file
    // can be swapped in the gap, which is why this asks again and then compares what it opened
    // with what it looked at.
    $before = @lstat( $path );
    if ( !$before || ( $before['mode'] & 0170000 ) !== 0100000 ) {
      $r['error'] = 'The path is not a regular file (a symbolic link, pipe or device is not read).';
      return $r;
    }
    $fh = @fopen( $path, 'rb' );
    if ( !$fh ) {
      $r['error'] = 'The file could not be opened for reading.';
      return $r;
    }
    $st = fstat( $fh );
    if ( !$st || !self::same_regular_file( $before, $st ) ) {
      fclose( $fh );
      $r['error'] = 'The path is not the regular file it was a moment ago (it was replaced while being opened), so it was not read.';
      return $r;
    }
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
    if ( stripos( $text, 'preview' ) === false && stripos( $text, '%' ) === false ) {
      return $text;
    }
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

    // Credentials carried as headers or inside URLs.
    $line = (string) preg_replace( '/\b(Authorization\s*:\s*)(?:Bearer|Basic|Digest|Token)?\s*[^\s,;]+/i', '$1[redacted]', $line );
    $line = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted]', $line );
    $line = (string) preg_replace( '#(\b[a-z][a-z0-9+.-]*://)[^/\s:@]+(?::[^/\s@]*)?@#i', '$1[redacted]@', $line );

    // key=value, key: value, "key":"value", key => value, where the key looks secret. This
    // is also what clears ?token=... in a logged URL.
    $patterns = array_values( array_filter( array_map( 'strval', $ctx['patterns'] ?? [ 'pass', 'pwd', 'secret', 'token', 'key', 'auth', 'salt', 'nonce', 'credential', 'bearer', 'signature', 'licence', 'license', 'private' ] ), 'strlen' ) );
    $patterns[] = 'gmcp_preview';
    $line = self::scrub_preview_tokens( $line );
    if ( $patterns ) {
      $alt = implode( '|', array_map( static function ( $p ) {
        return preg_quote( $p, '#' );
      }, $patterns ) );
      $line = (string) preg_replace(
        '#(["\']?[A-Za-z0-9_.\-\[\]]*(?:' . $alt . ')[A-Za-z0-9_.\-\[\]]*["\']?)(\s*(?:=>|[:=])\s*)("[^"]*"|\'[^\']*\'|[^\s&,;)\]}]+)#i',
        '$1$2[redacted]',
        $line
      );
    }

    // Shapes the name-based rule above cannot see.
    //  - define( 'DB_PASSWORD', 'value' ): the name and the value are separated by a comma, not
    //    by "=" or ":", and a PHP notice quoting a line of wp-config.php prints exactly this.
    //  - pw=value: "pw" is too short to be one of the substring patterns without matching
    //    "power" and "pwa", so it is matched as a whole word.
    $line = (string) preg_replace(
      '/(\bdefine\s*\(\s*([\'"])[A-Za-z0-9_]*(?:PASS|PWD|PW|SECRET|KEY|SALT|TOKEN|AUTH|CREDENTIAL)[A-Za-z0-9_]*\2\s*,\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,)]+)/i',
      '$1[redacted]',
      $line
    );
    $line = (string) preg_replace(
      '/(?<![A-Za-z0-9_])(pw)(\s*(?:=>|[:=])\s*)("[^"]*"|\'[^\']*\'|[^\s&,;)\]}]+)/i',
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

    return $truncated ? $line . ' …[line truncated]' : $line;
  }
}
