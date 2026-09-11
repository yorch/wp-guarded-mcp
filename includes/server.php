<?php

/*
 * Derived from AI Engine 3.7.7 (labs/mcp.php), Copyright (C) Jordy Meow,
 * GPLv2 or later. Modified 2026 by Jorge Barnaby: renamed throughout, prompts and resources added, named keys, an audit
 * hook, per-tool access gating and the URL-token ceiling.
 * See CREDITS.md for the full statement of changes.
 */

/**
* Guarded MCP
*
* This class implements a Model Context Protocol (MCP) server for WordPress.
*
* Current Implementation:
* - Single Streamable HTTP endpoint (/mcp/v1/http), used by Claude, Claude Code and ChatGPT
* - Authentication via OAuth (see oauth.php) or a static bearer token
* - Optional URL-token endpoint (/mcp/v1/{token}) for clients that cannot send headers
* - Properly handles agent cancellation signals (notifications/cancelled) to free workers immediately
* - Caps how long an idle stream holds a PHP worker (see Connection Management below)
* - Sends heartbeat signals to detect dead connections quickly
*
* The legacy SSE transport (/mcp/v1/sse plus /messages, driven by a bundled mcp.js Node
* relay) was removed in 3.6, once the MCP spec retired it. Streamable HTTP still answers
* with text/event-stream framing, which is why the SSE handling below is still needed.
*
* Connection Management:
* - Agents send notifications/cancelled when done, triggering immediate stream closure
* - An idle timeout frees the worker even when agents forget to disconnect. It is
*   180 seconds normally, and 30 seconds when MCP debug logging is on. Size PHP
*   workers off 180s, not 30s: an agent that opens streams and never sends DELETE
*   holds one worker per stream for the full three minutes. Override with the
*   gmcp_stream_max_time filter (see below) if that is too long for the host.
* - Heartbeat comments (every 10s) help proxies and connection_aborted() detect dead sockets
*/

class GMCP_Server {
  private $core = null;
  private $namespace = 'mcp/v1';
  // Reported to clients in serverInfo. Tracks the plugin so a bug report names a
  // version that exists; it used to be a hardcoded 0.0.1 for every release.
  private $server_version = GMCP_VERSION;
  private $protocol_version = '2025-06-18';
  private $supported_protocol_versions = [ '2024-11-05', '2025-06-18' ];
  private $queue_key = 'gmcp_msg';
  private $session_id = null;
  private $logging = false;
  private $last_action_time = 0;
  private $mcp_role = 'admin';
  /**
  * Tool names the presented key is limited to, or null when it is not limited.
  * null and [] must stay distinct: an empty list would otherwise read as "no tools",
  * and every unscoped caller would lose everything.
  */
  private $token_tools = null;
  private $token_id = '';
  private $tool_access_levels = [];
  // Required argument names per tool, taken from each tool's own inputSchema.
  private $tool_required_args = [];
  // Placeholder for OAuth integration. Currently unused and kept for
  // future implementation once the security model is revised.
  private $oauth = null;
  // Resolved during auth so the MCP Logs feature can attribute tool calls
  // to a specific connector (Claude, ChatGPT, Claude Code, …) or 'bearer'.
  // Lives on the instance for the duration of one HTTP request.
  private $auth_client_id = null;
  private $auth_client_name = null;
  private $auth_method = null; // 'oauth' | 'bearer' | null

  #region Initialize
  public function __construct( $core ) {
    $this->core = $core;

    // Set logging based on option
    $this->logging = $this->core->get_option( 'mcp_debug_mode', false );

    // OAuth 2.1 with Dynamic Client Registration. Lives alongside the bearer
    // token: bearer is for dev tools (Claude Code, scripts), OAuth is for
    // browser-driven clients like Claude Desktop. The new module enforces
    // strict redirect_uri matching, PKCE S256, and refresh-token rotation.
    $this->oauth = new GMCP_OAuth( $core, $this );

    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  /**
  * The OAuth module, so the settings screen can list and revoke grants without
  * constructing a second instance (which would double-register its hooks).
  */
  public function get_oauth() {
    return $this->oauth;
  }

  public function is_logging_enabled() {
    return $this->logging;
  }

  public function rest_api_init() {
    // No shared token to load any more. A key carries its own level, so mcp_role has
    // nothing left to say and the default stands until a key replaces it.

    // So the change journal can ask whether this caller could make the write it is
    // about to replay. Registered here rather than in the constructor because it is
    // only meaningful once auth has been resolved for a REST request.
    add_filter( 'gmcp_can_call_tool', [ $this, 'filter_can_call_tool' ], 10, 2 );

    // Auth filter runs for both bearer token and OAuth token paths; register
    // unconditionally so that OAuth-only deployments (no static bearer set) work.
    static $filter_added = false;
    if ( !$filter_added ) {
      add_filter( 'gmcp_allow', [ $this, 'auth_via_bearer_token' ], 10, 2 );
      $filter_added = true;
    }

    // Extend the CORS allow-headers list for our MCP routes. The Streamable HTTP
    // transport sends Mcp-Protocol-Version and Mcp-Session-Id on every request;
    // WP core's default allow-list does not include them, so the browser-side
    // preflight from claude.ai (and similar web connectors) was rejecting the
    // actual POST and the client reported "Couldn't reach the MCP server".
    add_filter( 'rest_allowed_cors_headers', function ( $headers ) {
      $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
      if ( strpos( $uri, '/' . $this->namespace . '/' ) === false ) {
        return $headers;
      }
      foreach ( [ 'Mcp-Protocol-Version', 'Mcp-Session-Id', 'Accept' ] as $h ) {
        if ( !in_array( $h, $headers, true ) ) {
          $headers[] = $h;
        }
      }
      return $headers;
    } );

    // Streamable HTTP endpoint (modern MCP transport). Always registered when
    // the MCP module is enabled — auth is enforced by can_access_mcp(), which
    // accepts either a bearer token or an OAuth access token.
    register_rest_route( $this->namespace, '/http', [
      'methods' => [ 'GET', 'POST', 'DELETE' ],
      'callback' => [ $this, 'handle_streamable_http' ],
      'permission_callback' => function ( $request ) {
        return $this->can_access_mcp( $request );
      },
      'show_in_index' => false,
    ] );

    // There is no token-in-URL route any more. It existed for hosts that strip the
    // Authorization header, and it put the credential in the request path, where it was
    // written to the access log of every proxy and web server in front of the site: one
    // copy per request, kept for as long as logs are kept, and read by people who are not
    // thinking about credentials. Measured on a development site, 27 copies of a working
    // administrator credential sat in the access log while the plugin's own debug trace
    // held none.
    //
    // What made it removable rather than merely unwise is that authorization_header()
    // recovers the header from REDIRECT_HTTP_AUTHORIZATION and apache_request_headers().
    // On Apache the header usually arrives and is only missing from $_SERVER, which was
    // the common case this route was carrying.

    // File upload endpoint for wp_upload_request
    // Uses a one-time token in the URL for authentication (no bearer header needed from curl)
    register_rest_route( $this->namespace, '/upload/(?P<token>[a-zA-Z0-9]+)', [
      'methods' => 'POST',
      'callback' => [ $this, 'handle_upload' ],
      'permission_callback' => '__return_true',
      'show_in_index' => false,
    ] );
  }
  #endregion

  #region Auth (Bearer token)
  /**
  * SECURITY: MCP provides powerful WordPress management capabilities, so access must be strictly controlled.
  *
  * By default, only administrators can access MCP endpoints. This prevents lower-privileged users
  * (subscribers, contributors, etc.) from executing dangerous operations like creating admin users,
  * deleting content, or modifying settings.
  *
  * When a bearer token is configured, it overrides the default admin check, but access is DENIED
  * unless a valid token is provided. This ensures MCP is secure even with default settings.
  */
  public function can_access_mcp( $request ) {
    // Default to requiring administrator capability for security. Checked via
    // manage_options rather than the 'administrator' role name, so that
    // admin-equivalent accounts (custom roles, individually granted caps) are
    // not locked out. Same reasoning as user_can_authorize() in oauth.php.
    $is_admin = current_user_can( 'manage_options' );
    return apply_filters( 'gmcp_allow', $is_admin, $request );
  }

  /**
  * The Authorization header, from wherever this server actually put it.
  *
  * WP_REST_Request reads headers out of $_SERVER, and Apache does not always populate
  * HTTP_AUTHORIZATION there: the header arrives, mod_php can see it through
  * apache_request_headers(), but $_SERVER never gets it unless a rewrite rule copies it
  * across. The official WordPress .htaccess does that with
  * "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]", so a site whose
  * .htaccess was hand-written, reset, or never generated silently loses bearer auth and
  * gets a 401 that looks like a bad token.
  *
  * That is the failure the URL-token route exists to work around, and since that route
  * is now held to a lower ceiling, it is worth reading the header properly first.
  * CGI and FastCGI setups also expose it as REDIRECT_HTTP_AUTHORIZATION.
  */
  private function authorization_header( $request ) {
    $hdr = $request instanceof WP_REST_Request ? $request->get_header( 'authorization' ) : '';
    if ( !empty( $hdr ) ) {
      return $hdr;
    }
    if ( !empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
      return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ( function_exists( 'apache_request_headers' ) ) {
      foreach ( (array) apache_request_headers() as $name => $value ) {
        if ( strcasecmp( $name, 'Authorization' ) === 0 && !empty( $value ) ) {
          return $value;
        }
      }
    }
    return '';
  }

  public function auth_via_bearer_token( $allow, $request ) {
    // Skip if already authenticated as admin
    if ( $allow ) {
      return $allow;
    }

    $hdr = $this->authorization_header( $request );

    // No header, nothing to check. Saying so in the log matters because a stripped
    // header is now the failure, rather than something the URL route quietly papered over.
    if ( !$hdr ) {
      if ( $this->logging ) {
        error_log( '[Guarded MCP] ❌ No authorization header provided. Server may be stripping headers.' );
      }
      return $allow;
    }

    // Check for Bearer token in header
    if ( $hdr && preg_match( '/Bearer\s+(.+)/i', $hdr, $m ) ) {
      $token = trim( $m[1] );
      $auth_result = 'none';

      // Check if it's an OAuth token
      if ( $this->oauth ) {
        $token_data = $this->oauth->validate_token( $token );
        if ( $token_data ) {
          // Defense in depth: even if a token was issued (or stored from before
          // the authorize-time admin gate landed), only accept it if the linked
          // user still holds administrator capability. Otherwise a Subscriber's
          // OAuth token would inherit the global mcp_role and reach admin tools.
          if ( !$this->oauth->user_can_authorize( $token_data['user_id'] ) ) {
            if ( $this->logging ) {
              error_log( '[Guarded MCP] ❌ OAuth token rejected: user ' . $token_data['user_id'] . ' is not an administrator.' );
            }
            return false;
          }
          // Set current user based on OAuth token
          wp_set_current_user( $token_data['user_id'] );
          $auth_result = 'oauth';
          $this->auth_method = 'oauth';
          $this->auth_client_id = $token_data['client_id'] ?? null;
          $this->auth_client_name = $token_data['client_name'] ?? null;
          return true;
        }
      }

      // Named keys are the only static credential. The shared bearer token that used to
      // be checked here is gone: it was stored in the clear because the screen showed it
      // back, carried no identity, could not expire and could not be scoped to anything.
      // A key is hashed, shown once, labelled, expirable and limited to named tools.
      $key = class_exists( 'GMCP_Tokens' ) ? GMCP_Tokens::match( $token ) : null;
      if ( $key ) {
        // The key's own owner, not the lowest-numbered administrator. match() has already
        // refused the key if that account no longer holds manage_options, so by here the
        // owner is someone who may still authorise this.
        $owner = (int) ( $key['owner'] ?? 0 );
        if ( $owner > 0 && ( $user = get_userdata( $owner ) ) ) {
          wp_set_current_user( $user->ID, $user->user_login );
        }
        elseif ( $admin = $this->core->get_admin_user() ) {
          // Only a key migrated from the retired shared token reaches this, because that
          // token had no identity to carry over. The screen asks you to replace it.
          wp_set_current_user( $admin->ID, $admin->user_login );
        }
        $this->mcp_role = $key['level'];
        $this->token_tools = $key['tools'] ? $key['tools'] : null;
        $this->token_id = $key['id'];
        $this->auth_method = 'bearer';
        $this->auth_client_id = 'key:' . $key['id'];
        $this->auth_client_name = $key['label'];
        GMCP_Tokens::touch( $key['id'] );
        if ( $this->logging ) {
          error_log( '[Guarded MCP] 🔐 Key auth OK: ' . $key['label'] );
        }
        return true;
      }

      if ( $this->logging && $auth_result === 'none' ) {
        error_log( '[Guarded MCP] ❌ Bearer token invalid.' );
      }
      // Explicitly deny access for invalid tokens
      return false;
    }

    return $allow;
  }

  #endregion

  #region Helpers (log / JSON-RPC utils)
  /**
   * Release the PHP session lock as early as possible. Long MCP calls (e.g. content
   * mutations on large posts) can otherwise serialize behind any other request from the
   * same client that opened a session, since PHP holds an exclusive write lock on the
   * session file for the lifetime of the request. The result is the ~max_execution_time
   * hangs operators see on busy sites. Closing the session is idempotent and safe — if
   * no session is active the call is a no-op.
   */
  private function release_session_lock(): void {
    if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
      session_write_close();
    }
  }

  private function log( $msg ) {
    // This method is for internal UI logs - keep it minimal
    if ( $this->logging ) {
      // Only log important messages to UI
      if ( strpos( $msg, 'queued' ) === false && strpos( $msg, 'flush' ) === false ) {
        GMCP_Logging::log( "[Guarded MCP] {$msg}" );
      }
    }
  }

  /** Wrap a JSON-RPC error object */
  private function rpc_error( $id, int $code, string $msg, $extra = null ): array {
    $err = [ 'code' => $code, 'message' => $msg ];
    if ( $extra !== null ) {
      $err['data'] = $extra;
    }
    return [ 'jsonrpc' => '2.0', 'id' => $id, 'error' => $err ];
  }

  /** Format tool result for MCP protocol */
  private function format_tool_result( $result ): array {
    // If result is a string, wrap it in the MCP content format
    if ( is_string( $result ) ) {
      return [
        'content' => [
          [
            'type' => 'text',
            'text' => $result,
          ],
        ],
      ];
    }

    // If result has 'content' key, assume it's already properly formatted
    if ( is_array( $result ) && isset( $result['content'] ) ) {
      return $result;
    }

    // If result is an array without 'content' key, wrap it as JSON
    if ( is_array( $result ) ) {
      return [
        'content' => [
          [
            'type' => 'text',
            'text' => wp_json_encode( $result, JSON_PRETTY_PRINT ),
          ],
        ],
        'data' => $result,
      ];
    }

    // For any other type, convert to string and wrap
    return [
      'content' => [
        [
          'type' => 'text',
          'text' => (string) $result,
        ],
      ],
    ];
  }
  #endregion

  #region Handle direct JSON-RPC
  /**
  * Shared JSON-RPC processor: takes a decoded request body, dispatches the method,
  * and returns an immediate WP_REST_Response. Used by the Streamable HTTP POST handler
  * (the modern transport for Claude Desktop, Claude.ai, ChatGPT, Claude Code).
  */
  private function handle_direct_jsonrpc( WP_REST_Request $request, $data ) {
    $this->release_session_lock();
    $id = $data['id'] ?? null;
    $method = $data['method'] ?? null;

    if ( json_last_error() !== JSON_ERROR_NONE ) {
      $response = new WP_REST_Response( [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => [ 'code' => -32700, 'message' => 'Parse error: invalid JSON' ]
      ], 200 );
      $response->set_headers( [ 'Content-Type' => 'application/json' ] );
      $session_header = $request->get_header( 'mcp-session-id' );
      if ( !empty( $session_header ) ) {
        return $this->attach_session_header( $response, sanitize_text_field( $session_header ) );
      }
      return $response;
    }

    if ( !is_array( $data ) || !$method ) {
      $response = new WP_REST_Response( [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [ 'code' => -32600, 'message' => 'Invalid Request' ]
      ], 200 );
      $response->set_headers( [ 'Content-Type' => 'application/json' ] );
      $session_header = $request->get_header( 'mcp-session-id' );
      if ( !empty( $session_header ) ) {
        return $this->attach_session_header( $response, sanitize_text_field( $session_header ) );
      }
      return $response;
    }

    $session_header = $request->get_header( 'mcp-session-id' );
    $session_id = '';
    if ( !empty( $session_header ) ) {
      $session_id = sanitize_text_field( $session_header );
    }

    if ( $method === 'initialize' || empty( $session_id ) ) {
      $session_id = wp_generate_uuid4();
      if ( $this->logging ) {
        error_log( '[Guarded MCP] 🆔 Direct session initialized: ' . $session_id );
      }
    }

    try {
      $reply = null;

      switch ( $method ) {
        case 'initialize':
          // Check if client requests a specific protocol version
          $params = $data['params'] ?? [];
          $requested_version = $params['protocolVersion'] ?? null;
          $client_info = $params['clientInfo'] ?? null;

          if ( $this->logging && $client_info ) {
            $client_name = $client_info['name'] ?? 'unknown';
            $client_version = $client_info['version'] ?? 'unknown';
            error_log( "[Guarded MCP] Client: {$client_name} v{$client_version}" );
          }

          // Negotiate protocol version: use client's version if supported
          $negotiated_version = $this->protocol_version;
          if ( $requested_version && in_array( $requested_version, $this->supported_protocol_versions, true ) ) {
            $negotiated_version = $requested_version;
          }
          else if ( $requested_version && $requested_version !== $this->protocol_version ) {
            if ( $this->logging ) {
              GMCP_Logging::warn( "[Guarded MCP] Client requested unsupported protocol version {$requested_version}" );
            }
          }

          $reply = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
              'protocolVersion' => $negotiated_version,
              'serverInfo' => (object) [
                'name' => 'WordPress - ' . get_bloginfo( 'name' ),
                'version' => $this->server_version,
              ],
              'capabilities' => (object) [
                'tools' => new stdClass(),
                'prompts' => new stdClass(),
                'resources' => new stdClass(),
              ],
            ],
          ];
          break;

        case 'tools/list':
          $tools = $this->get_tools_list();

          // Debug logging for tools/list
          if ( $this->logging ) {
            $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
            error_log( '[Guarded MCP Direct] 📋 tools/list requested by: ' . $user_agent );
            error_log( '[Guarded MCP Direct] 📊 Returning ' . count( $tools ) . ' tools' );
            if ( count( $tools ) > 0 ) {
              $tool_names = array_column( $tools, 'name' );
              error_log( '[Guarded MCP Direct] 🛠️ Tool names: ' . implode( ', ', $tool_names ) );
            }
            else {
              error_log( '[Guarded MCP Direct] ⚠️ WARNING: No tools returned!' );
            }
          }

          $reply = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [ 'tools' => $tools ],
          ];
          break;

        case 'tools/call':
          $params = $data['params'] ?? [];
          $tool = $params['name'] ?? '';
          $arguments = $params['arguments'] ?? [];

          if ( $this->logging ) {
            error_log( '[Guarded MCP Direct] 🔧 tools/call - Tool: ' . $tool );
            error_log( '[Guarded MCP Direct] 🔧 tools/call - Arguments: ' . wp_json_encode( $arguments ) );
          }

          try {
            $reply = $this->execute_tool( $tool, $arguments, $id );
            if ( $this->logging ) {
              error_log( '[Guarded MCP Direct] ✅ tools/call - Success for tool: ' . $tool );
            }
          }
          catch ( Exception $e ) {
            if ( $this->logging ) {
              error_log( '[Guarded MCP Direct] ❌ tools/call - Error: ' . $e->getMessage() );
            }
            throw $e;
          }
          break;

        case 'notifications/initialized':
          // This is a notification from the client indicating it has initialized
          // No response needed for notifications
          // Client initialized - no need to log
          return $this->attach_session_header( new WP_REST_Response( null, 204 ), $session_id );
          break;

        case 'prompts/list':
          $reply = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [ 'prompts' => GMCP_Prompts::listing( [ $this, 'resource_permitted' ] ) ],
          ];
          break;

        case 'prompts/get':
          $params = is_array( $data['params'] ?? null ) ? $data['params'] : [];
          // params.name is whatever the client sent. An array reaching a string cast logs
          // "Array to string conversion", and with WP_DEBUG_DISPLAY that text lands in
          // front of the JSON-RPC body and the client gets a parse error.
          $prompt_name = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';
          $rendered = GMCP_Prompts::render(
            $prompt_name,
            is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [],
            [ $this, 'resource_permitted' ]
          );
          if ( $rendered === null ) {
            $reply = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'error' => [ 'code' => -32602, 'message' => 'Unknown prompt: ' . $prompt_name ],
            ];
          }
          elseif ( isset( $rendered['__gmcp_unavailable'] ) ) {
            // Deliberately distinct from "unknown", so a client holding a listing from
            // when those tools were switched on can tell a withdrawn prompt from one
            // that never existed, and can say which tools it needs.
            $reply = [
              'jsonrpc' => '2.0',
              'id' => $id,
              'error' => [
                'code' => -32602,
                'message' => 'The prompt "' . $prompt_name . '" drives tools this connection cannot reach: '
                  . implode( ', ', (array) $rendered['__gmcp_unavailable'] )
                  . '. It is not offered in prompts/list for the same reason.',
              ],
            ];
          }
          else {
            $reply = [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $rendered ];
          }
          break;

        // A resource is the one path where a person, not a model, decides what enters
        // the conversation. Every one is backed by a tool and goes through the same
        // gate, so it cannot become a second door into the same room.
        case 'resources/list':
          $reply = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [ 'resources' => GMCP_Resources::listing( [ $this, 'resource_permitted' ] ) ],
          ];
          break;

        case 'resources/templates/list':
          $reply = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [ 'resourceTemplates' => GMCP_Resources::templates( [ $this, 'resource_permitted' ] ) ],
          ];
          break;

        case 'resources/read':
          $uri = (string) ( $data['params']['uri'] ?? '' );
          $contents = GMCP_Resources::read( $uri, [ $this, 'resource_permitted' ] );
          // One code for "no such thing" and for "not yours to read". Telling the two
          // apart would turn resources/read into a way to ask which post IDs exist.
          $reply = $contents === null
            ? [
              'jsonrpc' => '2.0',
              'id' => $id,
              'error' => [ 'code' => -32002, 'message' => 'No readable resource at ' . $uri ],
            ]
            : [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $contents ];
          break;

        default:
          // Check if it's a notification (no id)
          if ( $id === null && strpos( $method, 'notifications/' ) === 0 ) {
            if ( $this->logging ) {
              error_log( '[Guarded MCP] 📨 Notification received: ' . $method );
            }
            return $this->attach_session_header( new WP_REST_Response( null, 204 ), $session_id );
          }

          $reply = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [ 'code' => -32601, 'message' => "Method not found: {$method}" ]
          ];
      }

      // Ensure proper JSON-RPC response
      $response = new WP_REST_Response( $reply, 200 );
      $response->set_headers( [ 'Content-Type' => 'application/json' ] );
      return $this->attach_session_header( $response, $session_id );

    }
    catch ( Throwable $e ) {
      if ( $this->logging ) {
        error_log( '[Guarded MCP] ❌ Exception in handle_direct_jsonrpc: ' . $e->getMessage() );
      }

      $error_response = new WP_REST_Response( [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => [ 'code' => -32603, 'message' => 'Internal error', 'data' => $e->getMessage() ]
      ], 200 );
      $error_response->set_headers( [ 'Content-Type' => 'application/json' ] );
      return $this->attach_session_header( $error_response, $session_id );
    }
  }
  #endregion

  #region Session helpers
  private function attach_session_header( WP_REST_Response $response, string $session_id ) {
    if ( empty( $session_id ) ) {
      return $response;
    }

    $response->header( 'Mcp-Session-Id', $session_id );

    if ( $this->logging ) {
      error_log( '[Guarded MCP] 🪪 Response session header: ' . $session_id );
    }

    return $response;
  }
  #endregion

  #region Handle Streamable HTTP (Modern MCP transport)
  /**
   * Handle Streamable HTTP requests per MCP specification.
   * This is the modern transport used by Claude Code and other MCP clients.
   *
   * - POST: Send JSON-RPC request, receive JSON response (or SSE for streaming)
   * - GET: Open SSE stream for server-initiated messages
   * - DELETE: Terminate the session
   *
   * @see https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http
   */
  public function handle_streamable_http( WP_REST_Request $request ) {
    $method = $request->get_method();

    switch ( $method ) {
      case 'POST':
        return $this->handle_streamable_http_post( $request );

      case 'GET':
        return $this->handle_streamable_http_get( $request );

      case 'DELETE':
        return $this->handle_streamable_http_delete( $request );

      default:
        return new WP_REST_Response( [
          'error' => 'Method not allowed'
        ], 405 );
    }
  }

  /**
   * Handle POST requests for Streamable HTTP.
   * This processes JSON-RPC requests and returns JSON responses.
   */
  private function handle_streamable_http_post( WP_REST_Request $request ) {
    $this->release_session_lock();
    $raw_body = $request->get_body();

    if ( empty( $raw_body ) ) {
      return new WP_REST_Response( [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => [ 'code' => -32700, 'message' => 'Parse error: empty body' ]
      ], 400 );
    }

    $data = json_decode( $raw_body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
      return new WP_REST_Response( [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => [ 'code' => -32700, 'message' => 'Parse error: invalid JSON' ]
      ], 400 );
    }

    // Log the request if debugging is enabled
    if ( $this->logging && isset( $data['method'] ) ) {
      error_log( '[Guarded MCP HTTP] ↓ ' . $data['method'] );
    }

    // Reuse the existing direct JSON-RPC handler
    return $this->handle_direct_jsonrpc( $request, $data );
  }

  /**
   * Handle GET requests for Streamable HTTP.
   * This opens an SSE stream for server-to-client messages.
   * Used when the server needs to send notifications or progress updates.
   */
  private function handle_streamable_http_get( WP_REST_Request $request ) {
    // Check Accept header - must accept text/event-stream
    $accept = $request->get_header( 'accept' );
    if ( strpos( $accept, 'text/event-stream' ) === false ) {
      return new WP_REST_Response( [
        'error' => 'Accept header must include text/event-stream'
      ], 406 );
    }

    // Get or create session ID
    $session_header = $request->get_header( 'mcp-session-id' );
    $session_id = !empty( $session_header ) ? sanitize_text_field( $session_header ) : wp_generate_uuid4();

    if ( $this->logging ) {
      error_log( '[Guarded MCP HTTP] 📡 SSE stream opened for session: ' . substr( $session_id, 0, 8 ) . '...' );
    }

    // Set up SSE output
    @ini_set( 'zlib.output_compression', '0' );
    @ini_set( 'output_buffering', '0' );
    @ini_set( 'implicit_flush', '1' );
    if ( function_exists( 'ob_implicit_flush' ) ) {
      ob_implicit_flush( true );
    }

    header( 'Content-Type: text/event-stream' );
    header( 'Cache-Control: no-cache' );
    header( 'X-Accel-Buffering: no' );
    header( 'Connection: keep-alive' );
    header( 'Mcp-Session-Id: ' . $session_id );

    while ( ob_get_level() ) {
      ob_end_flush();
    }

    $this->session_id = $session_id;
    $this->last_action_time = time();

    // Send initial connection event
    echo "event: open\n";
    echo 'data: {"session":"' . esc_js( $session_id ) . "\"}\n\n";
    flush();

    $max_time = $this->logging ? 30 : 60 * 3;
    /**
    * How long an idle SSE stream may hold a PHP worker, in seconds.
    *
    * Each open stream occupies one worker until this elapses, so a client that opens
    * streams without ever sending DELETE can pin the whole pool on a small host.
    * Lower this when that happens; the client simply reconnects.
    *
    * Resolved once per stream, not inside the loop below, which spins five times a second.
    *
    * @param int $max_time Seconds. 180 normally, 30 when MCP logging is enabled.
    * @param string $session_id The session this stream belongs to.
    */
    $max_time = (int) apply_filters( 'gmcp_stream_max_time', $max_time, $session_id );
    if ( $max_time < 5 ) {
      $max_time = 5;
    }

    // Main SSE loop - listen for server-initiated messages
    while ( true ) {
      $idle = ( time() - $this->last_action_time ) >= $max_time;

      if ( connection_aborted() || $idle ) {
        if ( $this->logging ) {
          error_log( '[Guarded MCP HTTP] 🔚 SSE closed (' . ( $idle ? 'idle' : 'abort' ) . ')' );
        }
        break;
      }

      // Check for queued messages
      foreach ( $this->fetch_messages( $session_id ) as $msg ) {
        if ( isset( $msg['method'] ) && $msg['method'] === 'gmcp/kill' ) {
          echo "event: close\ndata: {}\n\n";
          flush();
          exit;
        }

        echo "event: message\n";
        echo 'data: ' . wp_json_encode( $msg, JSON_UNESCAPED_UNICODE ) . "\n\n";
        flush();
        $this->last_action_time = time();
      }

      // Heartbeat every 10 seconds
      $time_since_last = time() - $this->last_action_time;
      if ( $time_since_last >= 10 && $time_since_last % 10 === 0 ) {
        echo ": heartbeat\n\n";
        flush();
      }

      usleep( 200000 ); // 200ms
    }

    exit;
  }

  /**
   * Handle DELETE requests for Streamable HTTP.
   * This terminates the session and cleans up any resources.
   */
  private function handle_streamable_http_delete( WP_REST_Request $request ) {
    $session_header = $request->get_header( 'mcp-session-id' );

    if ( empty( $session_header ) ) {
      return new WP_REST_Response( [
        'error' => 'Mcp-Session-Id header required'
      ], 400 );
    }

    $session_id = sanitize_text_field( $session_header );

    if ( $this->logging ) {
      error_log( '[Guarded MCP HTTP] 🗑️ Session terminated: ' . substr( $session_id, 0, 8 ) . '...' );
    }

    // Queue kill signal for any active SSE streams
    $this->store_message( $session_id, [
      'jsonrpc' => '2.0',
      'method' => 'gmcp/kill'
    ] );

    // Clean up any remaining transients for this session
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_' . "{$this->queue_key}_{$session_id}_" ) . '%';
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
      )
    );

    // Return 204 No Content on successful termination
    return new WP_REST_Response( null, 204 );
  }
  #endregion

  #region Access Control
  /**
  * Whether the Access Level setting narrows this request.
  *
  * It governs the shared bearer token, which is what its description has always
  * said: one secret handed to a script, so the owner decides how far it reaches.
  * An OAuth connection is the opposite case. It belongs to one person who signed
  * in as themselves, and the authorize step already refuses anyone without
  * manage_options, so narrowing them again by a global role meant an
  * administrator on Claude Desktop silently lost every admin-level tool, with no
  * reason given and no setting on screen to explain it (the selector only appears
  * when a bearer token is configured).
  *
  * Both the listing and the execution gate call this. They used to decide it
  * separately, which is how they drifted apart in the first place.
  */
  private function role_filter_applies(): bool {
    return $this->auth_method !== 'oauth' && $this->mcp_role !== 'admin';
  }

  /**
  * A named key's tool list, applied on top of everything else.
  *
  * Deliberately separate from role_filter_applies(). That returns false for an
  * admin-level caller, and a key scoped to three tools is still scoped to three tools
  * whatever its level says. Anything placed behind that check would not gate the caller
  * it exists to gate.
  */
  /**
  * Could this caller, right now, call this tool?
  *
  * The single answer to that question, so nothing has to reimplement it and drift. The
  * resource layer is handed it as a callable, and the change journal reaches it through
  * the gmcp_can_call_tool filter before replaying a write.
  *
  * It runs the same gates as execute_tool, in the same order, including the URL-token
  * ceiling. Leaving that ceiling out would mean a caller barred from a tool because its
  * secret is in the request path could still reach that tool's effect by another route.
  */
  public function resource_permitted( string $tool ): bool {
    if ( empty( $this->tool_access_levels ) ) {
      $this->get_tools_list();
    }
    // A tool that is not registered at all, because its group is switched off, has no
    // level to check and must not fall through to a default that lets it past.
    if ( !isset( $this->tool_access_levels[ $tool ] ) ) {
      return false;
    }
    if ( !$this->token_allows_tool( $tool ) ) {
      return false;
    }
    if ( $this->role_filter_applies() && !$this->role_has_access( $this->tool_access_levels[ $tool ] ) ) {
      return false;
    }
    return true;
  }

  /**
  * Answer gmcp_can_call_tool for anything that needs the decision but cannot see this
  * object. Defaults to false at the call site, so a missing server fails closed.
  */
  public function filter_can_call_tool( $allowed, $tool ) {
    return $this->resource_permitted( (string) $tool );
  }

  private function token_allows_tool( string $tool ): bool {
    if ( $this->token_tools === null ) {
      return true;
    }
    // Always reachable, whatever the key is scoped to. It changes nothing, and every
    // tool description tells the model to call it when something fails; a health check
    // that is itself refused turns one broken call into a client that gives up.
    if ( $tool === 'mcp_ping' ) {
      return true;
    }
    return in_array( $tool, $this->token_tools, true );
  }

  private function role_has_access( string $toolLevel ): bool {
    if ( $this->mcp_role === 'admin' ) {
      return true;
    }
    if ( $this->mcp_role === 'readwrite' ) {
      return in_array( $toolLevel, [ 'read', 'write' ] );
    }
    if ( $this->mcp_role === 'readonly' ) {
      return $toolLevel === 'read';
    }
    return false;
  }
  #endregion

  #region Tools Definitions
  private function get_tools_list() {
    $base_tools = [
      [
        'name' => 'mcp_ping',
        'description' => 'Simple connectivity check. Returns the current GMT time and the WordPress site name. Whenever a tool call fails (error or timeout), immediately invoke mcp_ping to verify the server; if mcp_ping itself does not respond, assume the server is temporarily unreachable and pause additional tool calls.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => (object) [],
          'required' => []
        ],
        'annotations' => [
          'readOnlyHint' => true,
          'destructiveHint' => false,
          'openWorldHint' => false,
        ],
        'accessLevel' => 'read',
      ],
    ];

    if ( $this->logging ) {
      error_log( '[Guarded MCP] 🔧 get_tools_list() - Starting with ' . count( $base_tools ) . ' base tools' );
    }

    $filtered_tools = apply_filters( 'gmcp_tools', $base_tools );

    if ( $this->logging ) {
      error_log( '[Guarded MCP] 🔧 get_tools_list() - After filters: ' . count( $filtered_tools ) . ' tools' );
    }

    // Build access level map for defense-in-depth checks in execute_tool()
    foreach ( $filtered_tools as $tool ) {
      if ( isset( $tool['name'] ) ) {
        $this->tool_access_levels[ $tool['name'] ] = $tool['accessLevel'] ?? 'admin';
        $required = $tool['inputSchema']['required'] ?? [];
        $this->tool_required_args[ $tool['name'] ] = is_array( $required ) ? $required : [];
      }
    }

    // Filter tools by access level based on the MCP role.
    //
    // This applies to the shared bearer token only, which is what the setting has
    // always described: one secret handed to a script, so the site owner decides
    // how far it reaches. An OAuth connection is the opposite case. It belongs to
    // one person, they signed in as themselves, and authorize_token() already
    // refuses anyone without manage_options, so filtering them again by a global
    // role meant an administrator on Claude Desktop silently lost every
    // admin-level tool with no visible reason and no setting on screen to explain
    // it (the selector only appears when a bearer token is configured).
    if ( $this->role_filter_applies() ) {
      $filtered_tools = array_filter( $filtered_tools, function ( $tool ) {
        $level = $tool['accessLevel'] ?? 'admin';
        return $this->role_has_access( $level );
      } );
    }

    // A scoped key sees only the tools it may call. Hiding them is not the security
    // boundary (execute_tool checks again), it is so the model is not offered a menu
    // of things that will be refused.
    if ( $this->token_tools !== null ) {
      $filtered_tools = array_filter( $filtered_tools, function ( $tool ) {
        return $this->token_allows_tool( $tool['name'] ?? '' );
      } );
    }

    $normalized_tools = [];
    foreach ( $filtered_tools as $tool_index => $tool_definition ) {
      $normalized = $this->normalize_tool_definition( $tool_definition, $tool_index );
      if ( $normalized ) {
        $normalized_tools[] = $normalized;
      }
    }

    if ( $this->logging ) {
      error_log( '[Guarded MCP] 🔧 get_tools_list() - Normalized tools: ' . count( $normalized_tools ) );
    }

    return $normalized_tools;
  }
  #endregion

  #region Resources Definitions
  private function get_resources_list() {
    return [];
  }
  #endregion

  #region Prompts Definitions
  private function get_prompts_list() {
    return [];
  }
  #endregion

  #region Tool Normalization Helpers
  private function normalize_tool_definition( $tool, $index ) {
    // NOTE: tool-registration warnings below are always emitted (no $this->logging
    // gate). Each fires only when a tool is silently auto-fixed or auto-skipped at
    // registration — exactly the case where the author needs to know. They're rare
    // in normal operation and the only reliable diagnostic when something is off.
    if ( !is_array( $tool ) ) {
      error_log( '[Guarded MCP] ⚠️ Tool definition at index ' . $index . ' skipped (expected array).' );
      return null;
    }

    $name = isset( $tool['name'] ) ? trim( (string) $tool['name'] ) : '';
    if ( $name === '' ) {
      error_log( '[Guarded MCP] ⚠️ Tool skipped due to missing name at index ' . $index );
      return null;
    }

    $normalized_schema = $this->normalize_input_schema( $tool['inputSchema'] ?? null, $name );
    if ( !$normalized_schema ) {
      error_log( '[Guarded MCP] ⚠️ Tool "' . $name . '" skipped due to invalid input schema.' );
      return null;
    }

    $normalized = [
      'name' => $name,
      'inputSchema' => $normalized_schema,
    ];

    if ( isset( $tool['description'] ) && $tool['description'] !== '' ) {
      $normalized['description'] = wp_strip_all_tags( (string) $tool['description'] );
    }

    if ( isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ) {
      $annotations = $this->normalize_annotations( $tool['annotations'], $name );
      if ( !empty( $annotations ) ) {
        $normalized['annotations'] = $annotations;
      }
    }

    return $normalized;
  }

  private function normalize_input_schema( $schema, string $tool_name ) {
    if ( !is_array( $schema ) ) {
      return null;
    }

    $type = isset( $schema['type'] ) ? (string) $schema['type'] : 'object';
    if ( $type !== 'object' ) {
      error_log( '[Guarded MCP] ⚠️ Tool "' . $tool_name . '" has unsupported schema type: ' . $type );
      return null;
    }

    $properties = [];
    if ( isset( $schema['properties'] ) && ( is_array( $schema['properties'] ) || is_object( $schema['properties'] ) ) ) {
      foreach ( (array) $schema['properties'] as $prop_name => $definition ) {
        if ( !is_array( $definition ) ) {
          $definition = [];
        }

        if ( isset( $definition['type'] ) ) {
          // Validate type definition
          if ( is_array( $definition['type'] ) ) {
            // Array of types (union types) - validate they're compatible with MCP clients
            $type_array = array_map( 'strval', $definition['type'] );

            // Check for complex types that need additional schema details
            $complex_types = array_intersect( $type_array, [ 'object', 'array' ] );
            if ( !empty( $complex_types ) ) {
              error_log(
                '[Guarded MCP] ⚠️ Tool "' . $tool_name . '" property "' . $prop_name .
                '" has problematic union type with complex types: [' . implode( ', ', $type_array ) .
                ']. This breaks ChatGPT. Auto-fixing by removing type constraint.'
              );
              // Auto-fix: Remove the type constraint to accept any value
              unset( $definition['type'] );
              // Keep description if present, or add one
              if ( !isset( $definition['description'] ) ) {
                $definition['description'] = 'Value can be of any type';
              }
            }
            else {
              $definition['type'] = $type_array;
            }
          }
          else {
            $definition['type'] = (string) $definition['type'];
          }
        }

        $properties[ $prop_name ] = $definition;
      }
    }

    $required = [];
    if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
      foreach ( $schema['required'] as $field ) {
        $field_name = trim( (string) $field );
        if ( $field_name !== '' ) {
          $required[] = $field_name;
        }
      }
      $required = array_values( array_unique( $required ) );
    }

    $normalized = [
      'type' => 'object',
      'properties' => empty( $properties ) ? new stdClass() : $properties,
    ];

    if ( !empty( $required ) ) {
      $normalized['required'] = $required;
    }

    if ( array_key_exists( 'additionalProperties', $schema ) ) {
      $normalized['additionalProperties'] = (bool) $schema['additionalProperties'];
    }

    return $normalized;
  }

  private function normalize_annotations( array $annotations, string $tool_name ): array {
    $allowed_keys = [ 'title', 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ];
    $normalized = [];

    foreach ( $annotations as $key => $value ) {
      if ( !in_array( $key, $allowed_keys, true ) ) {
        continue;
      }

      if ( in_array( $key, [ 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ], true ) ) {
        $normalized[ $key ] = (bool) $value;
      }
      elseif ( $key === 'title' ) {
        $normalized['title'] = wp_strip_all_tags( (string) $value );
      }
    }

    if ( empty( $normalized ) && $this->logging && !empty( $annotations ) ) {
      error_log( '[Guarded MCP] 🔎 Tool "' . $tool_name . '" included unsupported annotation keys.' );
    }

    return $normalized;
  }
  #endregion

  #region Tools Call (execute_tool)

  // Armed while a tool runs, so the shutdown net below can answer for it.
  private static $currentToolCall = null;
  private static $shutdownNetRegistered = false;
  // Emergency memory reserve, released by the net so it can run even after an
  // out-of-memory fatal on hosts where ini_set is disabled.
  private static $memoryReserve = null;

  /**
   * A tool callback that dies hard (out of memory, fatal error) would end the
   * request as a raw 500 with an empty body, and MCP clients then treat the
   * WHOLE server as unreachable (Anthropic aborts the conversation with
   * "Connection error while communicating with MCP server"). This shutdown
   * net answers with a valid JSON-RPC tool error instead, so only the tool
   * fails and the client/model can react to it.
   */
  private function arm_fatal_net( $tool, $id ) {
    self::$currentToolCall = [ 'tool' => $tool, 'id' => $id ];

    if ( self::$memoryReserve === null ) {
      self::$memoryReserve = str_repeat( 'x', 2 * 1024 * 1024 );
    }
    if ( self::$shutdownNetRegistered ) {
      return;
    }
    self::$shutdownNetRegistered = true;
    // WordPress's own fatal handler runs first (registered at bootstrap) and
    // exits after printing its "critical error" 500, which would keep our net
    // from ever running. WP_SANDBOX_SCRAPING is core's shutdown-time escape
    // hatch for "the request handles fatals itself" (the enabled filter is
    // only consulted at bootstrap, so it cannot be used here).
    if ( !defined( 'WP_SANDBOX_SCRAPING' ) ) {
      define( 'WP_SANDBOX_SCRAPING', true );
    }
    register_shutdown_function( function () {
      $ctx = self::$currentToolCall;
      if ( empty( $ctx ) ) {
        return;
      }
      $err = error_get_last();
      if ( !$err || !in_array( $err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
        return;
      }
      // An OOM can leave ZERO headroom, killing this emitter itself. Free the
      // reserve first (works everywhere), then lift the limit where allowed
      // (the request is over anyway).
      self::$memoryReserve = null;
      @ini_set( 'memory_limit', '-1' );
      // Discard any partial/buffered output so the JSON is the only body.
      while ( ob_get_level() > 0 ) {
        @ob_end_clean();
      }
      if ( !headers_sent() ) {
        http_response_code( 200 );
        header( 'Content-Type: application/json' );
      }
      $msg = 'The tool "' . $ctx['tool'] . '" crashed on this site (' .
        substr( $err['message'], 0, 300 ) . '). The other tools should still work.';
      echo '{"jsonrpc":"2.0","id":' . json_encode( $ctx['id'] ) .
        ',"result":{"content":[{"type":"text","text":' . json_encode( $msg ) . '}],"isError":true}}';
    } );
  }

  private function execute_tool( $tool, $args, $id ) {
    $start = microtime( true );
    $response = null;
    $status = 'error';
    $error_msg = null;
    $this->arm_fatal_net( $tool, $id );

    // Paired with gmcp_tool_called in the finally block below. The change journal uses
    // the pair to tell an agent's writes from a person's: it listens to WordPress itself,
    // so without a "a tool call is in flight" signal it would also record somebody saving
    // a settings page by hand.
    //
    // It has to fire here and not inside arm_fatal_net(), where it first landed. There is
    // no $args in that scope, so every single tool call logged an undefined-variable
    // warning, and on a site with WP_DEBUG_DISPLAY the warning text prepends the JSON-RPC
    // body and the client gets a parse error instead of a result.
    do_action( 'gmcp_tool_start', $tool, $args );

    try {
      // Ensure tool access levels are populated (each HTTP request starts fresh)
      if ( empty( $this->tool_access_levels ) ) {
        $this->get_tools_list();
      }

      // Defense in depth: verify tool access even if it wasn't filtered from the listing
      $tool_level = $this->tool_access_levels[ $tool ] ?? 'admin';
      if ( $this->role_filter_applies() && !$this->role_has_access( $tool_level ) ) {
        $error_msg = "Access denied: tool '{$tool}' requires '{$tool_level}' access.";
        $response = $this->rpc_error( $id, -32600, $error_msg );
        return $response;
      }

      if ( !$this->token_allows_tool( $tool ) ) {
        $error_msg = "Access denied: the key this request used is limited to a specific list of tools, and '{$tool}' is not on it.";
        $response = $this->rpc_error( $id, -32600, $error_msg );
        return $response;
      }

      // Unconditional, unlike the role filter below it: role_filter_applies() returns
      // false for OAuth callers and for an admin-role bearer token, so anything placed
      // behind it would not gate the callers this is meant to gate.

      // Enforce the tool's own inputSchema "required" list before dispatching.
      // Handlers read their arguments directly ($a['key']), so a call missing one
      // raised an "Undefined array key" warning and then behaved as if an empty
      // value had been passed. The model saw a confusing result instead of the one
      // thing it could act on: which argument it forgot.
      $missing = [];
      foreach ( $this->tool_required_args[ $tool ] ?? [] as $field ) {
        if ( !is_string( $field ) || !array_key_exists( $field, (array) $args ) ) {
          $missing[] = is_string( $field ) ? $field : '(unnamed)';
        }
      }
      if ( !empty( $missing ) ) {
        $error_msg = "Missing required argument(s) for '{$tool}': " . implode( ', ', $missing ) . '.';
        $response = [
          'jsonrpc' => '2.0',
          'id' => $id,
          'result' => [
            'content' => [ [ 'type' => 'text', 'text' => $error_msg ] ],
            'isError' => true,
          ],
        ];
        return $response;
      }

      // Handle built-in tools first
      if ( $tool === 'mcp_ping' ) {
        if ( $this->logging ) {
          $this->log( '🛠️ Tool: mcp_ping' );
        }
        $ping_data = [
          'time' => gmdate( 'Y-m-d H:i:s' ),
          'name' => get_bloginfo( 'name' ),
        ];
        $response = [
          'jsonrpc' => '2.0',
          'id' => $id,
          'result' => [
            'content' => [
              [
                'type' => 'text',
                'text' => 'Ping successful: ' . wp_json_encode( $ping_data, JSON_PRETTY_PRINT ),
              ],
            ],
            'data' => $ping_data,
          ],
        ];
        $status = 'success';
        return $response;
      }

      // Let other modules handle their tools
      if ( $this->logging ) {
        // Log tool calls with more context
        $args_preview = '';
        if ( !empty( $args ) ) {
          // Show key args for common tools
          if ( isset( $args['ID'] ) ) {
            $args_preview = ' (ID: ' . $args['ID'] . ')';
          }
          elseif ( isset( $args['query'] ) ) {
            $args_preview = ' (query: "' . substr( $args['query'], 0, 30 ) . '...")';
          }
          elseif ( isset( $args['message'] ) ) {
            $args_preview = ' (message: "' . substr( $args['message'], 0, 30 ) . '...")';
          }
        }
        // Log to both error log and UI
        error_log( '[Guarded MCP] 🛠️ ' . $tool . $args_preview );
        $this->log( '🛠️ Tool: ' . $tool . $args_preview );
      }
      $filtered = apply_filters( 'gmcp_callback', null, $tool, $args, $id, $this );

      if ( $filtered !== null ) {
        // Check if it's already a full JSON-RPC response (backward compatibility)
        // array_key_exists, not isset: a notification carries a null id, and isset()
        // is false for null, so a fully-formed response would fall through to the
        // wrapping branch below and be delivered as a SUCCESS whose text is the
        // serialized error object.
        if ( is_array( $filtered ) && isset( $filtered['jsonrpc'] ) && array_key_exists( 'id', $filtered ) ) {
          $response = $filtered;
          $tool_failed = !empty( $filtered['result']['isError'] );
          $status = ( isset( $filtered['error'] ) || $tool_failed ) ? 'error' : 'success';
          if ( $status === 'error' ) {
            $error_msg = $filtered['error']['message']
              ?? ( $filtered['result']['content'][0]['text'] ?? null );
          }
          return $response;
        }

        // Otherwise, wrap the result in proper JSON-RPC format
        $response = [
          'jsonrpc' => '2.0',
          'id' => $id,
          'result' => $this->format_tool_result( $filtered ),
        ];
        $status = 'success';
        return $response;
      }

      throw new Exception( "Unknown tool: {$tool}" );
    }
    catch ( Throwable $e ) {
      // A failing tool is reported as a tool-level error (isError result),
      // NOT a JSON-RPC protocol error: clients treat protocol errors as a
      // broken server, while an isError result lets the model read the
      // message and adapt. Throwable also catches TypeError & friends.
      $error_msg = $e->getMessage();
      $response = [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
          'content' => [
            [
              'type' => 'text',
              'text' => 'The tool "' . $tool . '" failed: ' . $error_msg,
            ],
          ],
          'isError' => true,
        ],
      ];
      return $response;
    }
    finally {
      self::$currentToolCall = null;
      $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
      // Fire the action even on access denials and errors so admins can see
      // attempted-but-blocked tool calls in MCP Logs.
      do_action( 'gmcp_tool_called', [
        'tool' => $tool,
        'args' => $args,
        'result' => $response,
        'status' => $status,
        'error_msg' => $error_msg,
        'duration_ms' => $duration_ms,
        'client_id' => $this->auth_client_id,
        'client_name' => $this->auth_client_name,
        'auth_method' => $this->auth_method,
        'request_id' => $id,
        'user_id' => get_current_user_id(),
      ] );
    }
  }
  #endregion

  #region Handle /upload (one-time file upload via token)
  public function handle_upload( WP_REST_Request $request ) {
    $token = $request->get_param( 'token' );
    if ( empty( $token ) ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing token.' ], 400 );
    }

    $transient_key = 'gmcp_upload_' . $token;
    $data = get_transient( $transient_key );
    if ( empty( $data ) ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid or expired upload token.' ], 403 );
    }

    // Immediately delete the transient so the token can only be used once
    delete_transient( $transient_key );

    $files = $request->get_file_params();
    if ( empty( $files['file'] ) ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => 'No file provided. Use: curl -X POST -F "file=@/path/to/file" "<url>"' ], 400 );
    }

    $uploaded = $files['file'];
    if ( $uploaded['error'] !== UPLOAD_ERR_OK ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => 'Upload error code: ' . $uploaded['error'] ], 400 );
    }

    // media_handle_sideload() needs a user with upload_files. The request itself carries
    // no identity: it is authenticated by the one-time token consumed above, which was
    // issued to an already-authorised MCP caller. Resolve a real administrator rather
    // than assuming user 1 exists and still holds the role, which upstream did.
    if ( !current_user_can( 'upload_files' ) ) {
      $admin = $this->core->get_admin_user();
      if ( !$admin ) {
        return new WP_REST_Response(
          [ 'success' => false, 'message' => 'No administrator account is available to own the upload.' ],
          500
        );
      }
      wp_set_current_user( $admin->ID, $admin->user_login );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Use the filename from the transient (sanitized at creation time)
    $file = [
      'name' => $data['filename'],
      'tmp_name' => $uploaded['tmp_name'],
    ];

    $attachment_id = media_handle_sideload( $file, 0, $data['description'] );
    if ( is_wp_error( $attachment_id ) ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $attachment_id->get_error_message() ], 500 );
    }

    if ( !empty( $data['title'] ) ) {
      wp_update_post( [ 'ID' => $attachment_id, 'post_title' => sanitize_text_field( $data['title'] ) ] );
    }
    if ( !empty( $data['alt'] ) ) {
      update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $data['alt'] ) );
    }

    return new WP_REST_Response( [
      'success' => true,
      'attachment_id' => $attachment_id,
      'url' => wp_get_attachment_url( $attachment_id ),
    ], 200 );
  }
  #endregion

  #region Message Queue (per-message transient)
  private function transient_key( $sess, $id ) {
    return "{$this->queue_key}_{$sess}_{$id}";
  }

  private function store_message( $sess, $payload ) {
    if ( !$sess ) {
      return;
    }
    $idKey = array_key_exists( 'id', $payload ) ? ( $payload['id'] ?? 'NULL' ) : 'N/A';
    set_transient( $this->transient_key( $sess, $idKey ), $payload, 30 );
    $this->log( "queued #{$idKey}" );
  }

  private function fetch_messages( $sess ) {
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_' . "{$this->queue_key}_{$sess}_" ) . '%';

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
      ),
      ARRAY_A
    );

    $msgs = [];
    foreach ( $rows as $r ) {
      $msgs[] = maybe_unserialize( $r['option_value'] );
      delete_option( $r['option_name'] );
    }
    usort( $msgs, fn ( $a, $b ) => ( $a['id'] ?? 0 ) <=> ( $b['id'] ?? 0 ) );
    if ( $msgs ) {
      $this->log( 'flush ' . count( $msgs ) . ' msg(s)' );
    }
    return $msgs;
  }
  #endregion

  #region Resources (note)
  /*--------------------------------------------------*/
  /**
  * MCP also supports “resources” – static or dynamic data a client can
  * retrieve by URL (e.g. `mcp://resource/posts/123`).
  */
  #endregion
}
