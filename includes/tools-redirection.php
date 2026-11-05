<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Redirection (the plugin by John Godley), and why a redirect is not a small thing to hand an agent.
*
* A redirect is a rule that sends every visitor who asks for one URL somewhere else. Written to
* a site's own domain it is housekeeping; written to somebody else's it is an open redirect, the
* phishing primitive that borrows the site's reputation. It is also the one change that can lock
* the operator out: a rule on /wp-login.php or /wp-admin/ takes the way back in. An agent that
* reads comments and post bodies written by strangers is exactly the caller that should not be
* able to do either on one instruction, so the policy is in this file and is not a setting:
*
*  - The target is a path on this site, or an absolute URL on this site's own host. An https URL
*    on another host is accepted only if that host is in the list returned by the
*    gmcp_redirect_allowed_hosts filter (default: empty). No wildcard, no userinfo, no port.
*  - The source is a literal path. A regular expression is refused unless the site opts in with
*    gmcp_redirect_allow_regex, and even then it is refused when it matches a protected path.
*  - A source on wp-login, wp-admin, wp-cron.php, xmlrpc.php, the REST API, this plugin's own route
*    or the OAuth discovery documents is refused, however it is spelled.
*  - Only the plain "URL" match with the plain "redirect" action is managed here. The other
*    match types (agent, referrer, cookie, IP, login status...) make a redirect conditional on
*    who is asking, which is how a redirect is shown to a crawler and hidden from the owner.
*
* The policy is a property of the redirect and not of the tool that is writing it, so it runs on
* the stored result as well as on the request: enabling an existing redirect re-checks it, and
* every write is read back and the stored row is held to the same rules. A row that fails the
* read-back is switched off rather than left live.
*
* There is deliberately no delete tool. Disabling is the reversible form of removal and loses
* nothing; a deletion also loses the redirect's hit count and cannot be put back, and nothing
* here needs it.
*
* The plugin's own PHP classes are used for every read and write (Red_Item). Nothing here writes
* SQL against its tables; the only query is SHOW TABLES, to tell "no redirects" from "no tables".
*
* Facts about Redirection's API that this file relies on were first written from memory and
* have since been checked by running smoke-redirection.sh against Redirection 5.10.1: Red_Item
* get_by_id, create, update, get_filtered and to_json behave as used here, with the corrections
* noted where they apply (get_filtered clamps per_page to at least 5, to_json reports a redirect
* nobody has followed with last_access "-", and the regular-expression wrapper is '@..@s[i]').
* Each call into the plugin is still guarded, so a version that differs produces a sentence
* rather than a fatal or a silent success.
*/
class GMCP_Tools_Redirection {

  const MUTATING = [ 'redirection_create', 'redirection_update', 'redirection_set_enabled' ];

  /** Largest page the list will return, whatever the caller asks for. */
  const MAX_PER_PAGE = 50;

  const ALLOWED_CODES = [ 301, 302, 303, 307, 308 ];

  /** Longest URL or path accepted for a source or a target. */
  const MAX_URL = 2000;

  public function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  public function register_tools( $tools ) {
    return array_merge( is_array( $tools ) ? $tools : [], array_values( $this->tools() ) );
  }

  /** Whether Redirection is loaded and exposes what these tools call. Checked per call. */
  private function loaded(): bool {
    return defined( 'REDIRECTION_VERSION' )
      && class_exists( 'Red_Item' )
      && method_exists( 'Red_Item', 'get_by_id' )
      && method_exists( 'Red_Item', 'create' )
      && method_exists( 'Red_Item', 'get_filtered' );
  }

  private function tools(): array {
    $policy = 'The target must be a path on this site (starting with a single /) or an http(s) URL on this site\'s own host; an https URL on any other host is refused unless the site has listed that host through the gmcp_redirect_allowed_hosts filter, which is empty by default. The target must also come through WordPress\'s redirect sanitiser unchanged (it deletes characters such as ^ \' { | and $, so /^/evil.com would be sent as //evil.com). A source on wp-login.php, wp-admin, wp-cron.php, xmlrpc.php, the REST API, this plugin\'s endpoint or the OAuth discovery documents is refused, and so is a regular-expression source unless the site opts in through gmcp_redirect_allow_regex (a % is refused in a pattern).';
    return [
      'redirection_list' => [
        'name' => 'redirection_list',
        'description' => 'List redirects from the Redirection plugin, newest first, at most ' . self::MAX_PER_PAGE . ' per call. Each entry carries its source, target, status code, whether it is enabled and its hit count, plus policy_violations: the reasons this plugin would refuse to enable it, which is how a redirect to a foreign host created in the Redirection screen shows up. The reply carries a log_state block: whether Redirection is loaded, whether its tables exist, and how many redirects it holds. Read that first: zero redirects, and Redirection not installed or never set up, are different answers. Only the plain URL redirect type is described in full; other match types are listed with their type so they are not mistaken for plain ones.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'per_page' => [ 'type' => 'integer', 'description' => 'Entries per page, 1 to ' . self::MAX_PER_PAGE . ' (default 25).' ],
            'page' => [ 'type' => 'integer', 'description' => 'Page number, starting at 1.' ],
          ],
        ],
        'accessLevel' => 'write',
      ],
      'redirection_get' => [
        'name' => 'redirection_get',
        'description' => 'Read one redirect by its ID: source, target, status code, enabled, hit count, group, and the policy_violations this plugin would raise against it.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer', 'description' => 'The redirect ID.' ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'write',
      ],
      'redirection_create' => [
        'name' => 'redirection_create',
        'description' => 'Create a redirect through the Redirection plugin\'s own classes. A redirect sends every visitor of one URL elsewhere, so it is an admin tool. ' . $policy . ' The redirect is created live unless enabled is false. The stored row is read back and held to the same rules; if it fails, it is switched off and the reply says so. Only the plain URL match and redirect action are created. The change journal does not cover it (the rows live in Redirection\'s own tables); the audit log records the call, and redirection_set_enabled with enabled false is the way to take it back out.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'source' => [ 'type' => 'string', 'description' => 'The path to redirect from, starting with / (for example /old-page). A query string is allowed.' ],
            'target' => [ 'type' => 'string', 'description' => 'Where to send visitors: a path on this site, or a URL on this site\'s host.' ],
            'code' => [ 'type' => 'integer', 'description' => 'HTTP status: 301, 302, 303, 307 or 308 (default 301).' ],
            'title' => [ 'type' => 'string', 'description' => 'Optional label shown in the Redirection screen.' ],
            'group_id' => [ 'type' => 'integer', 'description' => 'Redirection group ID (default 1, the group Redirection creates on setup).' ],
            'enabled' => [ 'type' => 'boolean', 'description' => 'Whether the redirect is live (default true).' ],
            'regex' => [ 'type' => 'boolean', 'description' => 'Treat the source as a regular expression. Refused unless the site allows it.' ],
          ],
          'required' => [ 'source', 'target' ],
        ],
        'accessLevel' => 'admin',
      ],
      'redirection_update' => [
        'name' => 'redirection_update',
        'description' => 'Change the source, target, status code, title or group of an existing redirect. Fields not named keep their stored value, and the whole resulting redirect is checked against the policy, not only the fields named, so a redirect that already points somewhere forbidden cannot be edited into place; disable it instead. ' . $policy . ' Redirects of any match type other than the plain URL type are refused, because rewriting one would silently drop its conditions. The reply carries the stored values before and after.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer', 'description' => 'The redirect ID.' ],
            'source' => [ 'type' => 'string', 'description' => 'New source path.' ],
            'target' => [ 'type' => 'string', 'description' => 'New target.' ],
            'code' => [ 'type' => 'integer', 'description' => 'New HTTP status code.' ],
            'title' => [ 'type' => 'string', 'description' => 'New label.' ],
            'group_id' => [ 'type' => 'integer', 'description' => 'Move to this Redirection group.' ],
          ],
          'required' => [ 'id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'redirection_set_enabled' => [
        'name' => 'redirection_set_enabled',
        'description' => 'Switch a redirect on or off. Switching off is always allowed and is the reversible way to remove a redirect. Switching ON re-checks the stored redirect against the policy first and refuses if it fails, because enabling is what makes a redirect that someone else wrote start sending visitors; the reply lists the reasons. The state is read back from Redirection after the change.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'id' => [ 'type' => 'integer', 'description' => 'The redirect ID.' ],
            'enabled' => [ 'type' => 'boolean', 'description' => 'true to enable, false to disable.' ],
          ],
          'required' => [ 'id', 'enabled' ],
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // Redirection can be deactivated between the group being switched on and a call. Every
    // lookup below would then be a fatal, or worse an empty answer read as "no redirects".
    if ( !$this->loaded() ) {
      return $this->error( $r, 'Redirection is not loaded on this site (or this version does not provide Red_Item::get_by_id, create and get_filtered), so its tools cannot run. This is not the same as there being no redirects. log_state: ' . wp_json_encode( $this->log_state() ) );
    }

    try {
      switch ( $tool ) {
        case 'redirection_list':        $r = $this->list_redirects( $args, $r ); break;
        case 'redirection_get':         $r = $this->get_redirect( $args, $r ); break;
        case 'redirection_create':      $r = $this->create_redirect( $args, $r ); break;
        case 'redirection_update':      $r = $this->update_redirect( $args, $r ); break;
        case 'redirection_set_enabled': $r = $this->set_enabled( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'Redirection threw an error while running ' . $tool . ': ' . $e->getMessage() . ' The state of the redirect is unknown; read it back with redirection_get before retrying.' );
    }

    if ( empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

  #region Policy

  /**
  * Hosts, other than this site's own, that a redirect may point at over https.
  *
  * Sanitised on the way out of the filter: anything that is not a bare lower-case ASCII host
  * name is dropped, so a filter returning "*", "evil.com/path" or "https://x" matches nothing
  * rather than everything.
  */
  public static function allowed_hosts(): array {
    $hosts = apply_filters( 'gmcp_redirect_allowed_hosts', [] );
    $out = [];
    foreach ( is_array( $hosts ) ? $hosts : [] as $host ) {
      $host = strtolower( trim( (string) $host ) );
      if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host ) ) {
        $out[] = $host;
      }
    }
    return array_values( array_unique( $out ) );
  }

  public static function regex_allowed(): bool {
    return true === apply_filters( 'gmcp_redirect_allow_regex', false );
  }

  /**
  * Paths a source must never cover, normalised: this site's login, admin, REST root, this
  * plugin's endpoint, and the OAuth discovery documents. Taken from WordPress rather than
  * hard-coded, so a site that moved wp-login.php or lives in a subdirectory is still covered.
  */
  public static function protected_paths(): array {
    $urls = [
      wp_login_url(), admin_url(), rest_url(), rest_url( 'mcp/v1/http' ),
      home_url( '/wp-login.php' ), home_url( '/wp-admin/' ), home_url( '/wp-json/' ),
      home_url( '/.well-known/' ),
      // Neither is a page. WordPress and its hosts call them on a schedule, and a redirect on
      // one stops scheduled posts, updates and WooCommerce jobs without anything on screen.
      home_url( '/wp-cron.php' ), home_url( '/xmlrpc.php' ),
    ];
    $paths = [];
    foreach ( $urls as $url ) {
      $path = wp_parse_url( (string) $url, PHP_URL_PATH );
      if ( is_string( $path ) && $path !== '' ) {
        $paths[] = self::canonical_path( $path );
      }
    }
    return array_values( array_unique( array_filter( $paths, function ( $p ) {
      return $p !== '' && $p !== '/';
    } ) ) );
  }

  /** Lower-cased, percent-decoded (to a fixed point, bounded), no trailing slash. */
  public static function canonical_path( string $path ): string {
    for ( $i = 0; $i < 4; $i++ ) {
      $decoded = rawurldecode( $path );
      if ( $decoded === $path ) {
        break;
      }
      $path = $decoded;
    }
    $path = strtolower( $path );
    return $path === '/' ? '/' : rtrim( $path, '/' );
  }

  /**
  * Reasons a source is refused, or [] if it is acceptable.
  *
  * @param string   $source    The source as it will be stored.
  * @param bool     $regex     Whether it is a regular expression.
  * @param string[] $protected Output of protected_paths(), injectable so the rule can be run
  *                            without WordPress.
  * @param string   $home_path The path component of the home URL ('' or '/blog').
  */
  public static function source_problems( string $source, bool $regex, array $protected, string $home_path = '', bool $regex_ok = false ): array {
    $problems = [];
    if ( $source === '' || strlen( $source ) > self::MAX_URL ) {
      return [ 'The source is empty or longer than ' . self::MAX_URL . ' characters.' ];
    }
    if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $source ) ) {
      return [ 'The source contains a space, control character or backslash; encode it (a space is %20).' ];
    }

    if ( $regex ) {
      if ( !$regex_ok ) {
        return [ 'Regular-expression sources are refused on this site: a pattern cannot be proven not to cover the login page or this plugin\'s endpoint. A site that accepts that risk can allow them through the gmcp_redirect_allow_regex filter.' ];
      }
      // Allowed by the site, so hold it to the one thing that can be tested: it must compile
      // and must not match any protected path.
      //
      // The pattern is decoded twice before it runs, and neither decode is visible from here:
      // Redirection's sanitiser rawurldecodes the source once when it is saved, and
      // Red_Regex::__construct rawurldecodes the stored form again when a request is matched.
      // So '^/wp%252dlogin\.php' is stored as '^/wp%2dlogin\.php' and matches /wp-login.php at
      // runtime, and a probe of the text as typed never sees it. A '%' in a regular expression
      // is therefore refused outright (a literal percent sign is written \x25), and the
      // decoded forms are probed as well, in case a stored row from the admin screen has one.
      if ( strpos( $source, '%' ) !== false ) {
        return [ 'A regular-expression source may not contain "%": Redirection percent-decodes the pattern twice, once when it is saved and once when it matches, so the pattern it runs is not the one that was checked. Write a literal percent sign as \\x25.' ];
      }
      $forms = array_unique( [ $source, rawurldecode( $source ), rawurldecode( rawurldecode( $source ) ) ] );
      // Redirection's own wrapper (Red_Regex::get_regex) is '@' . pattern . '@s' with an 'i' only
      // when the redirect ignores case. Both are probed: 'i' matches a superset, 's' is the default.
      $patterns = [];
      foreach ( $forms as $form ) {
        foreach ( [ 's', 'si' ] as $flags ) {
          $patterns[] = '@' . str_replace( '@', '\\@', $form ) . '@' . $flags;
        }
      }
      foreach ( $patterns as $pattern ) {
        if ( @preg_match( $pattern, '' ) === false ) {
          return [ 'The regular expression does not compile.' ];
        }
      }
      $bases = [ '/wp-login.php', '/wp-admin/', '/wp-admin/admin-ajax.php', '/wp-json/', '/wp-json/mcp/v1/http', '/index.php?rest_route=/mcp/v1/http', '/.well-known/oauth-protected-resource', '/wp-cron.php', '/xmlrpc.php' ];
      $probes = $protected;
      foreach ( $bases as $base ) {
        // With a query string too: '^/wp-login\.php\?' only matches a request that has one.
        $probes[] = $base;
        $probes[] = $base . ( strpos( $base, '?' ) === false ? '?' : '&' ) . 'a=1';
      }
      foreach ( array_unique( $probes ) as $probe ) {
        foreach ( $patterns as $pattern ) {
          if ( @preg_match( $pattern, $probe ) === 1 ) {
            $problems[] = 'The regular expression matches ' . $probe . ', which a redirect must never cover.';
            break;
          }
        }
      }
      return $problems;
    }

    if ( $source[0] !== '/' || ( isset( $source[1] ) && $source[1] === '/' ) ) {
      return [ 'The source must be a path starting with a single / (for example /old-page).' ];
    }

    $path = $source;
    $query = '';
    $cut = strcspn( $source, '?#' );
    if ( $cut < strlen( $source ) ) {
      $path = substr( $source, 0, $cut );
      $query = substr( $source, $cut );
    }
    $canonical = self::canonical_path( $path );

    // A rest_route query reaches the REST API from any path, so the query is checked as well.
    if ( stripos( rawurldecode( rawurldecode( $query ) ), 'rest_route' ) !== false ) {
      $problems[] = 'The source carries a rest_route query, which is how the REST API (and this plugin\'s endpoint) is reached on any path.';
    }

    $segments = explode( '/', ltrim( $canonical, '/' ) );
    foreach ( $segments as $i => $seg ) {
      if ( $seg === '.' || $seg === '..' || ( $seg === '' && $i < count( $segments ) - 1 ) ) {
        return [ 'The source has an empty, "." or ".." path segment; give the path in its plain form.' ];
      }
    }

    // The same path with the site's own subdirectory and a leading index.php removed, so
    // /blog/wp-admin and /index.php/wp-json/ are seen for what they are.
    $variants = [ $canonical ];
    $strip = strtolower( rtrim( $home_path, '/' ) );
    $stripped = $canonical;
    if ( $strip !== '' && ( $stripped === $strip || strpos( $stripped, $strip . '/' ) === 0 ) ) {
      $stripped = substr( $stripped, strlen( $strip ) );
      $variants[] = $stripped === '' ? '/' : $stripped;
    }
    foreach ( $variants as $v ) {
      if ( strpos( $v, '/index.php/' ) === 0 ) {
        $variants[] = substr( $v, strlen( '/index.php' ) );
      }
    }
    $first_segments = [ 'wp-admin', 'wp-login.php', 'wp-json', '.well-known', 'wp-cron.php', 'xmlrpc.php' ];
    foreach ( array_unique( $variants ) as $v ) {
      $first = explode( '/', ltrim( $v, '/' ) )[0];
      if ( in_array( $first, $first_segments, true ) ) {
        $problems[] = 'The source is under /' . $first . ', which a redirect must never cover: it would lock the operator out, stop scheduled work or break this plugin\'s connection.';
        break;
      }
    }
    foreach ( $protected as $p ) {
      if ( $canonical === $p || strpos( $canonical, $p . '/' ) === 0 ) {
        $problems[] = 'The source is ' . $p . ' or beneath it, which this site uses for login, administration or its API.';
        break;
      }
    }
    return array_values( array_unique( $problems ) );
  }

  /**
  * Reasons a target is refused, or [] if it is acceptable.
  *
  * @param string   $home_host  Lower-case host of the home URL.
  * @param string   $home_port  Port of the home URL, '' when it is the scheme default.
  * @param string[] $allowed    Output of allowed_hosts().
  */
  public static function target_problems( string $target, bool $regex, string $home_host, string $home_port, array $allowed ): array {
    if ( $target === '' || strlen( $target ) > self::MAX_URL ) {
      return [ 'The target is empty or longer than ' . self::MAX_URL . ' characters.' ];
    }
    // Backslash and whitespace are refused outright: browsers read "/\evil.com" as
    // "//evil.com" and strip tabs and newlines from inside a URL.
    if ( preg_match( '/[\x00-\x20\x7f\\\\]/', $target ) ) {
      return [ 'The target contains a space, control character or backslash; encode it (a space is %20).' ];
    }

    // The target is handed to wp_redirect(), which runs wp_sanitize_redirect() on it and deletes
    // every character outside a short allow-list. Deleting is the hole: '/^/evil.com' passes a
    // check that looks only at the first two characters, is stored unchanged, and leaves
    // wp_redirect() as '//evil.com', a protocol-relative URL to another site. So the target is
    // only accepted if sanitising it changes nothing, and every check below then runs on text
    // that is exactly what a browser will be sent. In a regular-expression redirect "$1" is
    // replaced by the captured text before wp_redirect() sees it, so it is stood in for here.
    $sent = $regex ? (string) preg_replace( '/\$(?:\d+|\{\d+\})/', '1', $target ) : $target;
    if ( wp_sanitize_redirect( $sent ) !== $sent ) {
      return [ 'The target contains a character WordPress deletes from a redirect location (anything outside letters, digits and - ~ + _ . ? # = & ; , / : % ! * [ ] ( ) @), and deleting it can turn the target into another one, for example "/^/evil.com" into "//evil.com". Percent-encode it.' ];
    }

    // In a regular-expression redirect "$1" copies what the visitor typed into the target. A
    // target that STARTS with it is the classic open redirect (/$1 with the request "//evil.com").
    if ( $regex && ( $dollar = strpos( $target, '$' ) ) !== false ) {
      if ( !preg_match( '#^/[A-Za-z0-9._~-]+/#', substr( $target, 0, $dollar ) ) ) {
        return [ 'In a regular-expression redirect, a captured group ($1) is only accepted after a fixed first path segment (for example /new/$1), because a target that starts with it can be made to point at another site.' ];
      }
    }

    if ( $target[0] === '/' ) {
      if ( isset( $target[1] ) && $target[1] === '/' ) {
        return [ 'The target starts with //, which a browser reads as another site. Use a single / for a path on this site.' ];
      }
      return [];
    }

    if ( !preg_match( '#^(https?)://#i', $target ) ) {
      return [ 'The target must be a path on this site (starting with /) or an http(s) URL.' ];
    }
    $parts = wp_parse_url( $target );
    if ( !is_array( $parts ) || empty( $parts['host'] ) ) {
      return [ 'The target is not a URL that can be parsed.' ];
    }
    if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
      return [ 'The target carries credentials in front of the host, which is how a URL is made to look like it points somewhere it does not.' ];
    }
    $host = strtolower( (string) $parts['host'] );
    if ( !preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
      return [ 'The target\'s host is not a plain ASCII host name (use the punycode form for an internationalised one).' ];
    }
    $scheme = strtolower( (string) $parts['scheme'] );
    $port = isset( $parts['port'] ) ? (string) $parts['port'] : '';

    if ( $host === $home_host ) {
      if ( $port !== '' && $port !== $home_port ) {
        return [ 'The target names a different port on this site\'s host.' ];
      }
      return [];
    }
    if ( in_array( $host, $allowed, true ) ) {
      if ( $scheme !== 'https' || $port !== '' ) {
        return [ 'An allow-listed host is accepted over https on the default port only.' ];
      }
      return [];
    }
    return [ 'The target is on ' . $host . ', which is not this site (' . $home_host . ') and is not in the gmcp_redirect_allowed_hosts list. A redirect to a site you do not control is an open redirect.' ];
  }

  /** The policy bound to this site, applied to a source and a target. */
  private function problems_for( string $source, string $target, bool $regex ): array {
    $home = wp_parse_url( home_url() );
    $home_host = strtolower( (string) ( $home['host'] ?? '' ) );
    $home_port = isset( $home['port'] ) ? (string) $home['port'] : '';
    $home_path = (string) ( $home['path'] ?? '' );
    $problems = array_merge(
      self::source_problems( $source, $regex, self::protected_paths(), $home_path, self::regex_allowed() ),
      self::target_problems( $target, $regex, $home_host, $home_port, self::allowed_hosts() )
    );
    // A redirect from a path to itself loops the visitor until the browser gives up.
    if ( !$regex && $source !== '' && $target !== '' ) {
      $s = self::canonical_path( (string) strtok( $source, '?#' ) );
      $t_path = $target[0] === '/' ? $target : (string) wp_parse_url( $target, PHP_URL_PATH );
      if ( $s === self::canonical_path( (string) strtok( $t_path, '?#' ) ) && strpos( $source, '?' ) === false && strpos( $target, '?' ) === false
        && ( $target[0] === '/' || strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) ) === $home_host ) ) {
        $problems[] = 'The source and the target are the same path, which would redirect to itself.';
      }
    }
    return $problems;
  }

  /** Every reason a stored (or proposed) redirect, in the shape item_shape() returns, breaks the policy. */
  private function shape_problems( array $s ): array {
    $problems = [];
    if ( $s['incomplete'] ) {
      $problems[] = 'Redirection did not report this redirect\'s source and target in a form this plugin can read, so it cannot be checked.';
      return $problems;
    }
    if ( $s['match_type'] !== 'url' ) {
      $problems[] = 'Match type "' . $s['match_type'] . '" is conditional on the visitor; only the plain "url" match is managed here.';
    }
    if ( $s['action_type'] !== 'url' ) {
      $problems[] = 'Action type "' . $s['action_type'] . '" is not a redirect to a URL.';
    }
    if ( !in_array( $s['action_code'], self::ALLOWED_CODES, true ) ) {
      $problems[] = 'Status code ' . $s['action_code'] . ' is not one of ' . implode( ', ', self::ALLOWED_CODES ) . '.';
    }
    if ( $s['action_type'] === 'url' ) {
      $problems = array_merge( $problems, $this->problems_for( (string) $s['source'], (string) $s['target'], (bool) $s['regex'] ) );
    }
    return $problems;
  }

  #endregion

  #region Reading Redirection

  /**
  * One redirect, normalised. Redirection's to_json() is the source of the fields; a field it
  * did not return stays null and marks the shape incomplete, so a gap in what this file
  * understood of the plugin fails closed instead of reading as an empty, harmless value.
  */
  private function item_shape( $item ): array {
    $j = ( is_object( $item ) && method_exists( $item, 'to_json' ) ) ? $item->to_json() : ( is_array( $item ) ? $item : [] );
    $j = is_array( $j ) ? $j : [];

    $ad = $j['action_data'] ?? null;
    $target = is_array( $ad ) ? ( $ad['url'] ?? null ) : ( is_string( $ad ) ? $ad : null );

    $enabled = null;
    if ( is_object( $item ) && method_exists( $item, 'is_enabled' ) ) {
      $enabled = (bool) $item->is_enabled();
    } elseif ( array_key_exists( 'enabled', $j ) ) {
      $enabled = (bool) $j['enabled'];
    } elseif ( isset( $j['status'] ) ) {
      $enabled = ( $j['status'] === 'enabled' );
    }

    $source = isset( $j['url'] ) && is_string( $j['url'] ) ? $j['url'] : null;
    $type = isset( $j['action_type'] ) ? (string) $j['action_type'] : '';
    $incomplete = $source === null || !isset( $j['id'] ) || ( $type === 'url' && !is_string( $target ) );

    return [
      'id'          => isset( $j['id'] ) ? (int) $j['id'] : 0,
      'source'      => $source,
      'target'      => is_string( $target ) ? $target : null,
      'regex'       => !empty( $j['regex'] ),
      'enabled'     => $enabled,
      'match_type'  => isset( $j['match_type'] ) ? (string) $j['match_type'] : '',
      'action_type' => $type,
      'action_code' => isset( $j['action_code'] ) ? (int) $j['action_code'] : 0,
      'title'       => isset( $j['title'] ) ? (string) $j['title'] : '',
      'group_id'    => isset( $j['group_id'] ) ? (int) $j['group_id'] : 0,
      'hits'        => isset( $j['hits'] ) ? (int) $j['hits'] : null,
      // to_json() reports a redirect nobody has followed as the string '-', which is not a date.
      'last_access' => isset( $j['last_access'] ) && $j['last_access'] && $j['last_access'] !== '-' ? (string) $j['last_access'] : null,
      'position'    => isset( $j['position'] ) ? (int) $j['position'] : null,
      'match_data'  => $j['match_data'] ?? null,
      'incomplete'  => $incomplete,
    ];
  }

  /** The shape, without the internal fields, plus the policy verdict. */
  private function public_shape( array $s ): array {
    $problems = $this->shape_problems( $s );
    unset( $s['match_data'], $s['incomplete'], $s['position'] );
    $s['policy_violations'] = $problems;
    return $s;
  }

  private function fetch( int $id ) {
    $item = \Red_Item::get_by_id( $id );
    return ( is_object( $item ) && !is_wp_error( $item ) ) ? $item : null;
  }

  private function table_exists( string $table ): bool {
    global $wpdb;
    // esc_like: an underscore in a table name is a LIKE wildcard.
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
  }

  /**
  * What Redirection is, independent of what it holds, so a zero can be told from an absence.
  * `redirects` is null whenever it was not counted, and never 0 as a default.
  */
  private function log_state(): array {
    global $wpdb;
    $state = [
      'plugin_loaded' => $this->loaded(),
      'version'       => defined( 'REDIRECTION_VERSION' ) ? (string) REDIRECTION_VERSION : null,
      'tables'        => [ 'present' => [], 'missing' => [] ],
      'readable'      => false,
      'redirects'     => null,
    ];
    if ( !$state['plugin_loaded'] ) {
      $state['note'] = 'Redirection is not loaded, so there is nothing to count. This is not the same as there being no redirects.';
      return $state;
    }
    foreach ( [ 'redirection_items', 'redirection_groups' ] as $suffix ) {
      if ( $this->table_exists( $wpdb->prefix . $suffix ) ) {
        $state['tables']['present'][] = $suffix;
      } else {
        $state['tables']['missing'][] = $suffix;
      }
    }
    if ( $state['tables']['missing'] ) {
      $state['note'] = 'Redirection is loaded but its tables are missing, which is what a plugin that was installed and never set up looks like. There are no redirects to list, and that is not the same as there being none.';
      return $state;
    }
    try {
      $page = \Red_Item::get_filtered( [ 'page' => 0, 'per_page' => 1, 'orderby' => 'id', 'direction' => 'desc' ] );
    } catch ( \Throwable $e ) {
      $state['note'] = 'Redirection\'s tables exist but counting them failed: ' . $e->getMessage();
      return $state;
    }
    if ( !is_array( $page ) || !isset( $page['total'] ) || !is_numeric( $page['total'] ) ) {
      $state['note'] = 'Redirection\'s tables exist but its query layer did not return a total, so no count is reported.';
      return $state;
    }
    $state['readable'] = true;
    $state['redirects'] = (int) $page['total'];
    return $state;
  }

  #endregion

  #region Tools

  private function list_redirects( array $args, array $r ): array {
    $state = $this->log_state();
    if ( !$state['readable'] ) {
      return $this->error( $r, 'Redirects cannot be read. ' . ( $state['note'] ?? '' ) . ' log_state: ' . wp_json_encode( $state ) );
    }
    $per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? 25 ) ) );
    $page = max( 1, (int) ( $args['page'] ?? 1 ) );

    // Red_Item::get_filtered clamps the page size to at least 5 and then offsets by page * that
    // clamped size. Asking it for 3 rows therefore returns 5, and page 2 starts at row 10, not 3:
    // rows were skipped. So it is only ever asked for a size it will honour, and the page this
    // caller wants is cut out of what comes back.
    $chunk = max( 5, $per_page );
    $start = ( $page - 1 ) * $per_page;
    $first = intdiv( $start, $chunk );
    $local = $start - $first * $chunk;
    $result = \Red_Item::get_filtered( [ 'page' => $first, 'per_page' => $chunk, 'orderby' => 'id', 'direction' => 'desc' ] );
    if ( !is_array( $result ) || !isset( $result['items'] ) || !is_array( $result['items'] ) ) {
      return $this->error( $r, 'Redirection\'s query layer returned something this plugin does not recognise (no items list), so no redirects are reported.' );
    }
    $rows = array_values( $result['items'] );
    if ( $local + $per_page > $chunk ) {
      $more = \Red_Item::get_filtered( [ 'page' => $first + 1, 'per_page' => $chunk, 'orderby' => 'id', 'direction' => 'desc' ] );
      if ( is_array( $more ) && isset( $more['items'] ) && is_array( $more['items'] ) ) {
        $rows = array_merge( $rows, array_values( $more['items'] ) );
      }
    }
    // The cap is enforced here too: it must hold even if the plugin ignores per_page.
    $items = array_slice( $rows, $local, $per_page );
    $out = [];
    foreach ( $items as $item ) {
      $out[] = $this->public_shape( $this->item_shape( $item ) );
    }
    return $this->json( $r, [
      'log_state' => $state,
      'page'      => $page,
      'per_page'  => $per_page,
      'total'     => isset( $result['total'] ) ? (int) $result['total'] : $state['redirects'],
      'redirects' => $out,
    ] );
  }

  private function get_redirect( array $args, array $r ): array {
    $id = (int) ( $args['id'] ?? 0 );
    if ( $id < 1 ) {
      return $this->error( $r, 'id must be a positive integer.' );
    }
    $item = $this->fetch( $id );
    if ( !$item ) {
      return $this->error( $r, 'No redirect with ID ' . $id . ' exists.' );
    }
    return $this->json( $r, [ 'redirect' => $this->public_shape( $this->item_shape( $item ) ) ] );
  }

  /** Validate the parts shared by create and update. Returns [ code, error-or-null ]. */
  private function validated_code( $raw ): array {
    $code = $raw === null ? 301 : (int) $raw;
    if ( !in_array( $code, self::ALLOWED_CODES, true ) ) {
      return [ 0, 'code must be one of ' . implode( ', ', self::ALLOWED_CODES ) . '.' ];
    }
    return [ $code, null ];
  }

  private function group_exists( int $group_id ): ?bool {
    if ( !class_exists( 'Red_Group' ) || !method_exists( 'Red_Group', 'get' ) ) {
      return null;
    }
    $g = \Red_Group::get( $group_id );
    return !empty( $g );
  }

  private function create_redirect( array $args, array $r ): array {
    $source = (string) ( $args['source'] ?? '' );
    $target = (string) ( $args['target'] ?? '' );
    $regex = !empty( $args['regex'] );
    [ $code, $bad ] = $this->validated_code( $args['code'] ?? null );
    if ( $bad ) {
      return $this->error( $r, $bad );
    }
    $group_id = (int) ( $args['group_id'] ?? 1 );
    $title = mb_substr( trim( wp_strip_all_tags( (string) ( $args['title'] ?? '' ) ) ), 0, 200 );
    $enabled = !array_key_exists( 'enabled', $args ) || !empty( $args['enabled'] );

    $problems = $this->problems_for( $source, $target, $regex );
    if ( $problems ) {
      return $this->error( $r, 'Refused, nothing was created: ' . implode( ' ', $problems ) );
    }
    $exists = $this->group_exists( $group_id );
    if ( $exists === null ) {
      return $this->error( $r, 'This version of Redirection does not provide Red_Group::get, so group ' . $group_id . ' cannot be checked and nothing was created.' );
    }
    if ( !$exists ) {
      return $this->error( $r, 'No Redirection group with ID ' . $group_id . ' exists. Nothing was created.' );
    }

    $item = \Red_Item::create( [
      'url'         => $source,
      'match_type'  => 'url',
      'action_type' => 'url',
      'action_code' => $code,
      'action_data' => [ 'url' => $target ],
      'regex'       => $regex,
      'group_id'    => $group_id,
      'title'       => $title,
      'position'    => 0,
      // Honoured by Red_Item::create at insert time. Disabling afterwards leaves the redirect
      // live between the insert and the disable(), which on a request-per-visitor site is not
      // a theoretical window, so a redirect meant to be off is never written on.
      'enabled'     => $enabled,
    ] );
    if ( is_wp_error( $item ) ) {
      return $this->error( $r, 'Redirection refused to create the redirect: ' . $item->get_error_message() );
    }
    if ( !is_object( $item ) || !method_exists( $item, 'get_id' ) || !$item->get_id() ) {
      return $this->error( $r, 'Redirection did not return the redirect it was asked to create, so whether one exists is unknown. Check redirection_list before retrying.', -32603 );
    }
    $new_id = (int) $item->get_id();

    // Held to the policy as STORED. Redirection sanitises what it is given, so the row can
    // differ from the request, and the request was only ever a claim.
    $stored = $this->fetch( $new_id );
    if ( !$stored ) {
      return $this->error( $r, 'Redirect ' . $new_id . ' was reported created but cannot be read back.', -32603 );
    }
    $shape = $this->item_shape( $stored );
    $stored_problems = $this->shape_problems( $shape );
    if ( $stored_problems ) {
      $off = $this->force_disable( $new_id );
      return $this->error( $r, 'Redirect ' . $new_id . ' was created but the stored row breaks the policy (' . implode( ' ', $stored_problems ) . ') ' . $off . ' Review it in the Redirection screen.', -32603 );
    }
    if ( $enabled !== $shape['enabled'] ) {
      $off = $enabled ? '' : $this->force_disable( $new_id );
      return $this->error( $r, 'Redirect ' . $new_id . ' was created but its enabled state reads back as ' . var_export( $shape['enabled'], true ) . ', not the requested ' . var_export( $enabled, true ) . '. ' . $off, -32603 );
    }
    $note = $shape['target'] !== $target ? 'Redirection stored the target as "' . $shape['target'] . '" rather than "' . $target . '"; the stored form passes the policy.' : null;
    return $this->json( $r, [ 'created' => true, 'verified_by_reading_back' => true, 'note' => $note, 'redirect' => $this->public_shape( $shape ) ] );
  }

  private function update_redirect( array $args, array $r ): array {
    $id = (int) ( $args['id'] ?? 0 );
    if ( $id < 1 ) {
      return $this->error( $r, 'id must be a positive integer.' );
    }
    if ( !array_intersect( [ 'source', 'target', 'code', 'title', 'group_id' ], array_keys( $args ) ) ) {
      return $this->error( $r, 'Name at least one of source, target, code, title or group_id to change.' );
    }
    $item = $this->fetch( $id );
    if ( !$item ) {
      return $this->error( $r, 'No redirect with ID ' . $id . ' exists.' );
    }
    $before = $this->item_shape( $item );
    if ( $before['incomplete'] || $before['match_type'] !== 'url' || $before['action_type'] !== 'url' ) {
      return $this->error( $r, 'Redirect ' . $id . ' is not a plain URL redirect (match "' . $before['match_type'] . '", action "' . $before['action_type'] . '"), so it is not edited here: rewriting it would drop its conditions.' );
    }
    if ( $before['regex'] && !self::regex_allowed() ) {
      return $this->error( $r, 'Redirect ' . $id . ' is a regular-expression redirect, and regular expressions are refused on this site (gmcp_redirect_allow_regex).' );
    }

    $after = $before;
    if ( array_key_exists( 'source', $args ) ) {
      $after['source'] = (string) $args['source'];
    }
    if ( array_key_exists( 'target', $args ) ) {
      $after['target'] = (string) $args['target'];
    }
    if ( array_key_exists( 'code', $args ) ) {
      [ $code, $bad ] = $this->validated_code( $args['code'] ?? 0 );
      if ( $bad ) {
        return $this->error( $r, $bad );
      }
      $after['action_code'] = $code;
    }
    if ( array_key_exists( 'title', $args ) ) {
      $after['title'] = mb_substr( trim( wp_strip_all_tags( (string) $args['title'] ) ), 0, 200 );
    }
    if ( array_key_exists( 'group_id', $args ) ) {
      $after['group_id'] = (int) $args['group_id'];
      $exists = $this->group_exists( $after['group_id'] );
      if ( $exists === null ) {
        return $this->error( $r, 'This version of Redirection does not provide Red_Group::get, so the group cannot be checked and nothing was changed.' );
      }
      if ( !$exists ) {
        return $this->error( $r, 'No Redirection group with ID ' . $after['group_id'] . ' exists. Nothing was changed.' );
      }
    }
    $problems = $this->shape_problems( $after );
    if ( $problems ) {
      return $this->error( $r, 'Refused, nothing was changed: ' . implode( ' ', $problems ) . ' If the redirect as stored is the problem, disable it with redirection_set_enabled.' );
    }

    $details = [
      'url'         => $after['source'],
      'match_type'  => 'url',
      'action_type' => 'url',
      'action_code' => $after['action_code'],
      'action_data' => [ 'url' => $after['target'] ],
      'regex'       => $after['regex'],
      'group_id'    => $after['group_id'],
      'title'       => $after['title'],
      // The sanitiser's get_position() answers 0 when none is given, which would move the
      // redirect to the front of its group and change which of two overlapping rules wins.
      'position'    => (int) ( $before['position'] ?? 0 ),
    ];
    // Source flags (ignore case, query, trailing slash) live in match_data; an update that
    // omits it would put them back to defaults and change which URLs the redirect matches.
    if ( is_array( $before['match_data'] ) ) {
      $details['match_data'] = $before['match_data'];
    }
    $result = $item->update( $details );
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Redirection refused the change: ' . $result->get_error_message() );
    }

    $stored = $this->fetch( $id );
    if ( !$stored ) {
      return $this->error( $r, 'Redirect ' . $id . ' cannot be read back after the update.', -32603 );
    }
    $now = $this->item_shape( $stored );
    $stored_problems = $this->shape_problems( $now );
    if ( $stored_problems ) {
      $off = $this->force_disable( $id );
      return $this->error( $r, 'Redirect ' . $id . ' was updated but the stored row breaks the policy (' . implode( ' ', $stored_problems ) . ') ' . $off . ' It was previously: ' . wp_json_encode( [ 'source' => $before['source'], 'target' => $before['target'], 'code' => $before['action_code'] ] ), -32603 );
    }
    foreach ( [ 'action_code', 'group_id' ] as $f ) {
      if ( $now[ $f ] !== $after[ $f ] ) {
        return $this->error( $r, 'Redirect ' . $id . ' was updated but ' . $f . ' reads back as ' . wp_json_encode( $now[ $f ] ) . ', not the requested ' . wp_json_encode( $after[ $f ] ) . '.', -32603 );
      }
    }
    return $this->json( $r, [
      'updated' => true,
      'verified_by_reading_back' => true,
      'before' => [ 'source' => $before['source'], 'target' => $before['target'], 'code' => $before['action_code'], 'title' => $before['title'], 'group_id' => $before['group_id'] ],
      'redirect' => $this->public_shape( $now ),
    ] );
  }

  private function set_enabled( array $args, array $r ): array {
    $id = (int) ( $args['id'] ?? 0 );
    if ( $id < 1 || !array_key_exists( 'enabled', $args ) || !is_bool( $args['enabled'] ) ) {
      return $this->error( $r, 'id (a positive integer) and enabled (true or false) are both required.' );
    }
    $want = $args['enabled'];
    $item = $this->fetch( $id );
    if ( !$item ) {
      return $this->error( $r, 'No redirect with ID ' . $id . ' exists.' );
    }
    $shape = $this->item_shape( $item );
    if ( $want ) {
      // Enabling is the act that makes someone else's rule start sending visitors, so the
      // stored rule is held to the policy first. Disabling asks nothing: it is the safe direction.
      $problems = $this->shape_problems( $shape );
      if ( $problems ) {
        return $this->error( $r, 'Refused, redirect ' . $id . ' stays as it is: ' . implode( ' ', $problems ) );
      }
    }
    $method = $want ? 'enable' : 'disable';
    if ( !method_exists( $item, $method ) ) {
      return $this->error( $r, 'This version of Redirection\'s Red_Item has no ' . $method . '() method, so the state cannot be changed from here.' );
    }
    if ( $shape['enabled'] === $want ) {
      return $this->json( $r, [ 'changed' => false, 'note' => 'Redirect ' . $id . ' was already ' . ( $want ? 'enabled' : 'disabled' ) . '.', 'redirect' => $this->public_shape( $shape ) ] );
    }
    $item->$method();

    $stored = $this->fetch( $id );
    $now = $stored ? $this->item_shape( $stored ) : null;
    if ( !$now || $now['enabled'] !== $want ) {
      return $this->error( $r, 'Redirect ' . $id . ' did not read back as ' . ( $want ? 'enabled' : 'disabled' ) . ' after the change.', -32603 );
    }
    return $this->json( $r, [ 'changed' => true, 'verified_by_reading_back' => true, 'redirect' => $this->public_shape( $now ) ] );
  }

  /** Switch a redirect off after it failed its read-back; returns a sentence saying what happened. */
  private function force_disable( int $id ): string {
    $item = $this->fetch( $id );
    if ( $item && method_exists( $item, 'disable' ) ) {
      $item->disable();
      $again = $this->fetch( $id );
      if ( $again && $this->item_shape( $again )['enabled'] === false ) {
        return 'It has been switched off.';
      }
    }
    return 'IT COULD NOT BE SWITCHED OFF AUTOMATICALLY AND MAY BE LIVE; disable it in the Redirection screen now.';
  }

  #endregion

  #region Helpers

  private function error( array $r, string $message, int $code = -32602 ): array {
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message . ' [error ' . $code . ']' ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }

  private function text( array $r, string $message ): array {
    $r['result'] = [ 'content' => [ [ 'type' => 'text', 'text' => $message ] ] ];
    return $r;
  }

  private function json( array $r, $data ): array {
    return $this->text( $r, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
  }

  #endregion

}
