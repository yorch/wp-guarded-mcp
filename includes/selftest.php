<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Answers "why is my agent getting a 401" without making the site owner guess.
*
* The failure this exists for is genuinely hard to diagnose from the client end. Apache
* receives the Authorization header but does not put it in $_SERVER unless a rewrite rule
* copies it there, and WordPress only reads $_SERVER. The client then sees a plain 401,
* which looks exactly like a wrong token, so people rotate the token repeatedly and get
* nowhere. The plugin reads the header from a fallback for this reason, but the fallback
* is not available on every stack.
*
* So the check calls the plugin's own endpoint over HTTP, the same way an agent would,
* and reports which of the three possible stories is true: the endpoint is unreachable,
* the credentials never arrived, or everything works.
*/
class GMCP_SelfTest {

  /**
  * A full readiness report: every step a client walks during setup, checked in order.
  *
  * A client that cannot configure itself says only "couldn't determine the server
  * settings", which covers half a dozen unrelated causes and names none of them. This
  * runs the same steps and reports each one, so the failing step is visible rather than
  * inferred.
  *
  * @return array<int,array{id:string,status:string,label:string,detail:string}>
  *   status is one of ok, warn, fail, skip.
  */
  public static function report(): array {
    $core = $GLOBALS['gmcp_core'] ?? null;
    $endpoint = rest_url( 'mcp/v1/http' );
    $origin = untrailingslashit( home_url() );
    $checks = [];

    // 1. Transport. A hosted client will not even attempt plain HTTP.
    $https = strpos( $endpoint, 'https://' ) === 0;
    $local = preg_match( '#^https?://(localhost|127\.0\.0\.1|\[::1\])#', $endpoint );
    $checks[] = [
      'id' => 'https',
      'status' => $https ? 'ok' : ( $local ? 'warn' : 'fail' ),
      'label' => $https ? 'The site is served over HTTPS' : 'The site is not served over HTTPS',
      'detail' => $https
        ? ''
        : ( $local
          ? 'This looks like a local site. A desktop client may accept it, but a hosted connector such as the one at claude.ai requires a public HTTPS address.'
          : 'Remote clients refuse to connect over plain HTTP, and this alone produces "couldn\'t determine the server settings". Install a certificate, then update the site address under Settings, General.' ),
    ];

    // 2. Discovery. Both URL shapes matter: a host serving /.well-known/ from disk
    // breaks the root pair while the REST-nested pair keeps working, and that split is
    // the clearest signal of what is wrong.
    $root = self::fetch_json( $origin . '/.well-known/oauth-protected-resource' );
    $nested = self::fetch_json( rest_url( 'mcp/v1/.well-known/oauth-protected-resource' ) );
    $root_prm = $root['data'];
    $nested_prm = $nested['data'];
    $root_ok = isset( $root_prm['authorization_servers'] );
    $nested_ok = isset( $nested_prm['authorization_servers'] );

    if ( !$root['reached'] && !$nested['reached'] ) {
      // Neither request completed, so nothing was learned about discovery.
      $checks[] = [
        'id' => 'discovery',
        'status' => 'skip',
        'label' => 'Could not check discovery from this server',
        'detail' => 'This site could not make a request to itself, which many hosts block deliberately. It says nothing about whether a client can reach the endpoint. Run .dev/diagnose-connector.sh from your own machine to check from outside.',
      ];
    }
    elseif ( $root_ok && $nested_ok ) {
      $checks[] = [ 'id' => 'discovery', 'status' => 'ok', 'label' => 'Discovery documents are being served', 'detail' => '' ];
    }
    elseif ( $nested_ok && !$root_ok ) {
      $checks[] = [
        'id' => 'discovery',
        'status' => 'fail',
        'label' => 'Something is serving /.well-known/ before WordPress',
        'detail' => 'The documents under the REST route work, but the ones at the site root do not, and strict clients ask for the root ones first. A physical .well-known directory left by a certificate tool, or a web-server alias for ACME challenges, both cause this. Let /.well-known/oauth-* fall through to WordPress.',
      ];
    }
    else {
      $checks[] = [
        'id' => 'discovery',
        'status' => 'fail',
        'label' => 'Discovery documents are not reachable',
        'detail' => 'Neither the site root nor the REST route returned usable metadata. A security plugin or firewall blocking unauthenticated REST requests will do this.',
      ];
    }

    // 3. The advertised address. This is the one that catches proxies and CDNs, and
    // nothing else about the site looks wrong when it is the cause.
    $advertised = $root_prm['resource'] ?? ( $nested_prm['resource'] ?? '' );
    if ( $advertised === '' ) {
      $checks[] = [ 'id' => 'address', 'status' => 'skip', 'label' => 'Could not read the advertised address', 'detail' => '' ];
    }
    elseif ( untrailingslashit( $advertised ) === untrailingslashit( $endpoint ) ) {
      $checks[] = [ 'id' => 'address', 'status' => 'ok', 'label' => 'The advertised address matches this endpoint', 'detail' => '' ];
    }
    else {
      $checks[] = [
        'id' => 'address',
        'status' => 'fail',
        'label' => 'The advertised address does not match this endpoint',
        'detail' => sprintf(
          'Clients are told the endpoint is %1$s, but it is actually %2$s, and they reject the mismatch. Every address in the discovery documents comes from the site address WordPress has stored, so this happens when a proxy or CDN sits in front and WordPress believes it is somewhere else. Check Settings, General, and whether the proxy passes X-Forwarded-Proto.',
          $advertised,
          $endpoint
        ),
      ];
    }

    // 4. Registration. Without it a client cannot configure itself at all.
    $registered = wp_remote_post( rest_url( 'mcp/v1/oauth/register' ), [
      'timeout' => 15,
      'headers' => [ 'Content-Type' => 'application/json' ],
      'body' => wp_json_encode( [
        'client_name' => 'Guarded MCP setup check',
        'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ],
      ] ),
    ] );
    if ( is_wp_error( $registered ) ) {
      $checks[] = [ 'id' => 'register', 'status' => 'skip', 'label' => 'Could not check client registration from here', 'detail' => 'This site could not call itself, which many hosts block. It does not mean a client cannot connect.' ];
    }
    else {
      $code = (int) wp_remote_retrieve_response_code( $registered );
      $checks[] = [
        'id' => 'register',
        'status' => ( $code === 200 || $code === 201 ) ? 'ok' : 'fail',
        'label' => ( $code === 200 || $code === 201 )
          ? 'Clients can register themselves'
          : "Client registration returned HTTP {$code}",
        'detail' => ( $code === 200 || $code === 201 )
          ? ''
          : 'A client cannot set itself up without this. Something in front of the REST API is refusing the request.',
      ];
    }

    // 5. The bearer path, which is separate from OAuth and fails differently.
    $token = $core ? (string) $core->get_option( 'mcp_bearer_token' ) : '';
    if ( $token === '' ) {
      $checks[] = [ 'id' => 'bearer', 'status' => 'skip', 'label' => 'No bearer token set, so nothing to check', 'detail' => 'OAuth clients do not need one. Generate a token below if you want to connect a command-line agent.' ];
    }
    else {
      $result = self::run();
      $map = [ 'ok' => 'ok', 'error' => 'fail', 'warning' => 'warn', 'unknown' => 'skip' ];
      $checks[] = [
        'id' => 'bearer',
        'status' => $map[ $result['status'] ] ?? 'skip',
        'label' => $result['summary'],
        'detail' => $result['detail'],
      ];
    }

    return $checks;
  }

  /**
  * Fetch a URL and decode it as JSON.
  *
  * Returns whether the request even completed, separately from what came back. That
  * distinction is the whole point: a host that blocks a site from calling itself is
  * extremely common, and reporting "discovery is broken" in that case would send people
  * to fix something that was never wrong. "Could not check" and "checked, and it is
  * wrong" are different answers and must not collapse into one.
  *
  * @return array{reached:bool,data:array}
  */
  private static function fetch_json( string $url ): array {
    $response = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => [ 'Accept' => 'application/json' ] ] );
    if ( is_wp_error( $response ) ) {
      return [ 'reached' => false, 'data' => [] ];
    }
    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    return [ 'reached' => true, 'data' => is_array( $decoded ) ? $decoded : [] ];
  }

  /**
  * Whether a WP_Error from an HTTP request is a certificate problem rather than a
  * connection one. WordPress surfaces the raw cURL text, so this matches on it:
  * cURL 60 is an unverifiable certificate, 51 a host mismatch, 35 a handshake failure.
  */
  private static function is_tls_error( WP_Error $error ): bool {
    $message = strtolower( $error->get_error_message() );
    foreach ( [ 'certificate', 'ssl', 'tls', 'curl error 60', 'curl error 51', 'curl error 35' ] as $needle ) {
      if ( strpos( $message, $needle ) !== false ) {
        return true;
      }
    }
    return false;
  }

  /**
  * @return array{status:string,summary:string,detail:string,tools:?int}
  *   status is one of ok, warning, error, unknown.
  */
  public static function run(): array {
    $url = rest_url( 'mcp/v1/http' );

    // A key made for this one request and revoked at the end, rather than the site's own
    // credential. Keys are stored hashed, so there is no long-lived secret to borrow any
    // more, and this is better than borrowing one anyway: the check no longer depends on
    // a credential existing, so it answers on a site that has only ever used OAuth. It is
    // scoped to mcp_ping and read level, so the worst a leaked probe could do is say
    // hello.
    if ( !class_exists( 'GMCP_Tokens' ) ) {
      return [
        'status' => 'unknown',
        'summary' => 'Cannot test the key path on this install.',
        'detail' => 'The key store is not loaded, so there is nothing to authenticate with.',
        'tools' => null,
      ];
    }
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
    $probe = GMCP_Tokens::create(
      __( 'Connection check', 'guarded-mcp' ), 'readonly', 0, [ 'mcp_ping' ],
      $admins ? (int) $admins[0]->ID : 0
    );
    $token = $probe['secret'];
    // Revoked however this returns, including on an early return below. A probe key left
    // behind would be a credential nobody made a decision about, sitting on the list.
    $revoke = function () use ( $probe ) { GMCP_Tokens::revoke( $probe['id'] ); };

    // TLS is verified, because this request carries the site's bearer token, which is
    // the most valuable secret the plugin holds. An earlier version turned verification
    // off so that a self-signed staging certificate would not read as a broken
    // endpoint, which was the wrong trade: it meant handing a real credential to
    // whatever answered, with no way to know it was the right host. A certificate
    // problem is diagnosed below instead, without sending the token at all.
    $response = wp_remote_post( $url, [
      'timeout' => 15,
      'redirection' => 2,
      'sslverify' => true,
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
      ],
      'body' => wp_json_encode( [
        'jsonrpc' => '2.0',
        'id' => 1,
        // Not tools/list: the probe key is scoped, and listing would return only what it
        // may call, which is one tool. This asks the question the check is actually
        // about, which is whether the credential arrived at all.
        'method' => 'tools/call',
        'params' => [ 'name' => 'mcp_ping', 'arguments' => new stdClass() ],
      ] ),
    ] );
    $revoke();

    if ( is_wp_error( $response ) ) {
      // A certificate failure is worth naming, because the fix is completely different
      // from a blocked loopback and the raw cURL text does not make that obvious.
      // Probe again to see whether the endpoint is actually there, deliberately with no
      // Authorization header: the point is to learn reachability, not to retry the
      // credential over a connection that just failed to prove who it is talking to.
      if ( self::is_tls_error( $response ) ) {
        $probe = wp_remote_post( $url, [
          'timeout' => 10,
          'redirection' => 2,
          'sslverify' => false,
          'headers' => [ 'Content-Type' => 'application/json' ],
          'body' => wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ] ),
        ] );
        $reachable = !is_wp_error( $probe );
        return [
          'status' => 'warning',
          'summary' => $reachable
            ? 'The endpoint is there, but this site cannot verify its TLS certificate.'
            : 'This site cannot verify its own TLS certificate, and the endpoint did not answer.',
          'detail' => 'The token was not sent, because there was no way to confirm what would receive it. This is normal on a local or staging site with a self-signed certificate, and your agent may well connect without trouble. On a production site it means the certificate is expired, self-signed, or missing part of its chain, and it is worth fixing. Error: ' . $response->get_error_message(),
          'tools' => null,
        ];
      }

      // Plenty of hosts block a site from calling itself. That is not the same as the
      // endpoint being broken, and saying so would send people to fix the wrong thing.
      return [
        'status' => 'unknown',
        'summary' => 'This site could not call its own endpoint, so the test could not run.',
        'detail' => 'Many hosts block loopback requests. That does not mean your agent cannot connect: it only means this check cannot verify it from here. Error: ' . $response->get_error_message(),
        'tools' => null,
      ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 401 || $code === 403 ) {
      return [
        'status' => 'error',
        'summary' => 'The endpoint is reachable, but it rejected the site\'s own token.',
        'detail' => 'Almost always this means the web server is not passing the Authorization header through to PHP, which is common on Apache. Re-saving your permalink structure regenerates the .htaccess rule WordPress uses to forward it. If your host does not use .htaccess, ask them to pass the Authorization header, or connect with OAuth instead, which does not rely on it.',
        'tools' => null,
      ];
    }

    if ( $code !== 200 ) {
      return [
        'status' => 'error',
        'summary' => "The endpoint answered with HTTP {$code}.",
        'detail' => 'Something between the request and this plugin is interfering: a security plugin, a firewall, or a rule that blocks REST requests. The endpoint is ' . esc_url_raw( $url ) . '.',
        'tools' => null,
      ];
    }

    if ( !isset( $body['result']['tools'] ) || !is_array( $body['result']['tools'] ) ) {
      return [
        'status' => 'error',
        'summary' => 'The endpoint answered, but not with a valid tool list.',
        'detail' => 'Something is altering the response body. A plugin that appends output to REST responses will do this. Response began: ' . esc_html( mb_substr( wp_remote_retrieve_body( $response ), 0, 200 ) ),
        'tools' => null,
      ];
    }

    $count = count( $body['result']['tools'] );
    return [
      'status' => 'ok',
      'summary' => "Working. The endpoint answered and offered {$count} tools.",
      'detail' => 'Your agent should be able to connect using the endpoint and token above.',
      'tools' => $count,
    ];
  }

  /**
  * The same check, surfaced in Site Health, which is where a site owner already looks
  * when something is wrong and is a place a support request will often start.
  */
  public static function register_site_health(): void {
    add_filter( 'site_status_tests', function ( $tests ) {
      $tests['direct']['gmcp_endpoint'] = [
        'label' => __( 'MCP endpoint', 'guarded-mcp' ),
        'test' => [ __CLASS__, 'site_health_test' ],
      ];
      return $tests;
    } );
  }

  public static function site_health_test(): array {
    $result = self::run();

    $map = [
      'ok' => [ 'good', 'blue' ],
      'warning' => [ 'recommended', 'orange' ],
      'error' => [ 'critical', 'red' ],
      'unknown' => [ 'recommended', 'gray' ],
    ];
    list( $status, $colour ) = $map[ $result['status'] ] ?? [ 'recommended', 'gray' ];

    return [
      'label' => $result['summary'],
      'status' => $status,
      'badge' => [ 'label' => __( 'MCP', 'guarded-mcp' ), 'color' => $colour ],
      'description' => '<p>' . esc_html( $result['detail'] ) . '</p>',
      'actions' => sprintf(
        '<p><a href="%s">%s</a></p>',
        esc_url( GMCP_Settings::page_url() ),
        esc_html__( 'Open MCP Server settings', 'guarded-mcp' )
      ),
      'test' => 'gmcp_endpoint',
    ];
  }
}
