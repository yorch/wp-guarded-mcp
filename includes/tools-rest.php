<?php

/*
 * Derived from AI Engine 3.7.7 (labs/mcp-rest.php), Copyright (C) Jordy Meow,
 * GPLv2 or later. Modified 2026 by Jorge Barnaby: renamed throughout.
 * See CREDITS.md for the full statement of changes.
 */

class GMCP_Tools_Rest {
  // Bump the suffix when build_schema_from_args() changes so old cached schemas are ignored.
  // v5: _fields advertised.
  private $cache_key = 'gmcp_tools_cache_v5';
  private $allowed = [ 'posts', 'pages', 'media' ];

  public function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_rest_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  public function register_rest_tools( $prevTools ) {
    $cached = get_transient( $this->cache_key );

    if ( !$cached ) {
      $tools = [];
      $server = rest_get_server();
      $routes = $server->get_routes();

      foreach ( $this->allowed as $resource ) {
        $base = "/wp/v2/{$resource}";
        $item = "{$base}/(?P<id>[\d]+)";

        if ( isset( $routes[ $base ] ) ) {
          foreach ( $routes[ $base ] as $endpoint ) {
            if ( !empty( $endpoint['methods']['GET'] ) ) {
              $tools[ "list_{$resource}" ] = [
                'name' => "list_{$resource}",
                'description' => "List {$resource} through the WordPress REST API. Every field of every row "
                  . 'is returned by default, which includes the fully rendered content and a block of '
                  . '_links per row, so a page of real posts is large out of all proportion to what is '
                  . 'usually wanted. Name the fields with _fields, e.g. "id,title,status,link", whenever '
                  . 'the whole record is not needed. wp_get_posts is the lighter tool when a plain list '
                  . 'of posts will do.',
                'category' => 'Dynamic REST',
                'inputSchema' => $this->build_schema_from_args( $endpoint['args'] ),
                'outputSchema' => $this->build_output_schema(),
                'accessLevel' => 'read',
              ];
              break;
            }
          }
        }

        if ( isset( $routes[ $item ] ) ) {
          foreach ( $routes[ $item ] as $endpoint ) {
            if ( !empty( $endpoint['methods']['GET'] ) ) {
              $tools[ "get_{$resource}" ] = [
                'name' => "get_{$resource}",
                'description' => "Get single {$resource} by ID",
                'category' => 'Dynamic REST',
                'inputSchema' => $this->build_schema_from_args( $endpoint['args'] ),
                'outputSchema' => $this->build_output_schema(),
                'accessLevel' => 'read',
              ];
              break;
            }
          }
        }

        if ( isset( $routes[ $base ] ) ) {
          foreach ( $routes[ $base ] as $endpoint ) {
            if ( !empty( $endpoint['methods']['POST'] ) ) {
              $tools[ "create_{$resource}" ] = [
                'name' => "create_{$resource}",
                'description' => "Create {$resource}",
                'category' => 'Dynamic REST',
                'inputSchema' => $this->build_schema_from_args( $endpoint['args'] ),
                'outputSchema' => $this->build_output_schema(),
                'accessLevel' => 'write',
              ];
              break;
            }
          }
        }

        if ( isset( $routes[ $item ] ) ) {
          foreach ( $routes[ $item ] as $endpoint ) {
            $methods = array_keys( $endpoint['methods'] );
            if ( array_intersect( [ 'POST', 'PUT', 'PATCH' ], $methods ) ) {
              $tools[ "update_{$resource}" ] = [
                'name' => "update_{$resource}",
                'description' => "Update {$resource}",
                'category' => 'Dynamic REST',
                'inputSchema' => $this->build_schema_from_args( $endpoint['args'] ),
                'outputSchema' => $this->build_output_schema(),
                'accessLevel' => 'write',
              ];
              break;
            }
          }
        }

        if ( isset( $routes[ $item ] ) ) {
          foreach ( $routes[ $item ] as $endpoint ) {
            if ( !empty( $endpoint['methods']['DELETE'] ) ) {
              $tools[ "delete_{$resource}" ] = [
                'name' => "delete_{$resource}",
                'description' => "Delete {$resource}",
                'category' => 'Dynamic REST',
                'inputSchema' => $this->build_schema_from_args( $endpoint['args'] ),
                'outputSchema' => $this->build_output_schema(),
                'accessLevel' => 'admin',
              ];
              break;
            }
          }
        }
      }

      set_transient( $this->cache_key, $tools, DAY_IN_SECONDS );
      $cached = $tools;
    }

    return array_merge( array_values( $cached ), $prevTools );
  }

  private function build_schema_from_args( $args ) {
    $schema = [
      'type' => 'object',
      'properties' => [],
      'required' => [],
    ];

    // JSON Schema keys worth forwarding from WordPress REST arg definitions.
    // PHP callbacks (sanitize_callback/validate_callback) and WP-only keys (arg_options,
    // required) are intentionally excluded - clients would choke on them.
    $allowed_keys = [
      'type', 'description', 'enum', 'default', 'format',
      'items', 'properties', 'additionalProperties',
      'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
      'minLength', 'maxLength', 'pattern',
      'minItems', 'maxItems', 'uniqueItems',
      'oneOf', 'anyOf', 'allOf',
    ];

    foreach ( $args as $name => $def ) {
      $property = [];
      foreach ( $allowed_keys as $key ) {
        if ( array_key_exists( $key, $def ) ) {
          $property[ $key ] = $def[ $key ];
        }
      }
      if ( !isset( $property['type'] ) ) {
        $property['type'] = 'string';
      }
      if ( !isset( $property['description'] ) ) {
        $property['description'] = '';
      }

      $schema['properties'][ $name ] = $this->normalize_schema_node( $property );

      if ( !empty( $def['required'] ) ) {
        $schema['required'][] = $name;
      }
    }

    // _fields is a WordPress-wide REST parameter rather than an endpoint one, so it is
    // absent from $args and never reached a caller, even though rest_do_request() has
    // always honoured it. Advertising costs nothing and the difference is not marginal:
    // three empty pages came back as 4556 bytes, and as 186 with four fields named. Most
    // of the rest is per-row _links the model has no way to follow and rendered content
    // it did not ask for, so the gap widens with real pages rather than closing.
    //
    // Added here rather than per-tool because it trims any response, a create or update
    // reply included, and here is the one place these schemas are built.
    $schema['properties']['_fields'] = [
      'type' => 'string',
      'description' => 'Comma-separated list of fields to return, e.g. "id,title,status,link". '
        . 'Omit to get every field, which includes rendered content and a _links block per row '
        . 'and is usually far more than is wanted. Nested fields use dots, e.g. "title.rendered".',
    ];

    return $this->normalize_schema_node( $schema );
  }

  /**
   * JSON Schema requires "properties" to be an object. WP REST arg definitions
   * commonly set it to an empty PHP array (e.g. the "meta" arg on media/posts),
   * which json_encode would serialize as [] and Claude's MCP validator rejects.
   * Walk the schema and cast any empty "properties" / object-typed
   * "additionalProperties" to (object)[] so they serialize as {}.
   */
  private function normalize_schema_node( $node ) {
    if ( !is_array( $node ) ) {
      return $node;
    }

    if ( array_key_exists( 'properties', $node ) ) {
      if ( is_array( $node['properties'] ) ) {
        if ( empty( $node['properties'] ) ) {
          $node['properties'] = (object) [];
        }
        else {
          foreach ( $node['properties'] as $key => $child ) {
            $node['properties'][ $key ] = $this->normalize_schema_node( $child );
          }
        }
      }
    }

    if ( isset( $node['items'] ) ) {
      $node['items'] = $this->normalize_schema_node( $node['items'] );
    }

    if ( isset( $node['additionalProperties'] ) && is_array( $node['additionalProperties'] ) ) {
      $node['additionalProperties'] = empty( $node['additionalProperties'] )
        ? (object) []
        : $this->normalize_schema_node( $node['additionalProperties'] );
    }

    foreach ( [ 'oneOf', 'anyOf', 'allOf' ] as $combinator ) {
      if ( isset( $node[ $combinator ] ) && is_array( $node[ $combinator ] ) ) {
        foreach ( $node[ $combinator ] as $i => $child ) {
          $node[ $combinator ][ $i ] = $this->normalize_schema_node( $child );
        }
      }
    }

    // Some clients (notably Google Gemini) only allow "enum" on string-typed
    // properties and reject the request with a 400 otherwise. WordPress REST
    // sometimes defines integer enums, e.g. Jetpack's publicize "status" => [0, 1].
    // Drop the enum when the type is not a string; the value still works, it just
    // loses the schema-level enumeration. Coercing to string instead would risk
    // breaking the endpoint's own integer validation when the tool is called.
    if ( isset( $node['enum'] ) ) {
      $type = $node['type'] ?? null;
      $isStringType = $type === 'string'
        || ( is_array( $type ) && in_array( 'string', $type, true ) )
        || ( $type === null && count( array_filter( (array) $node['enum'], function ( $v ) {
          return !is_string( $v );
        } ) ) === 0 );
      if ( !$isStringType ) {
        unset( $node['enum'] );
      }
    }

    return $node;
  }

  private function build_output_schema() {
    return [
      'type' => 'object',
      'properties' => [
        'content' => [
          'type' => 'array',
          'items' => [
            'type' => 'object',
            'properties' => [
              'type' => [
                'type' => 'string',
                'description' => 'Block type, e.g. text or image',
              ],
              'text' => [
                'type' => 'string',
                'description' => 'Human-readable content',
              ],
            ],
            'required' => [ 'type', 'text' ],
          ],
        ],
      ],
      'required' => [ 'content' ],
    ];
  }

  public function handle_call( $existing, $tool, $args, $id ) {
    if ( !empty( $existing ) ) {
      return $existing;
    }

    $tools = get_transient( $this->cache_key );
    if ( !isset( $tools[ $tool ] ) ) {
      return $existing;
    }

    // Security check is already done in the MCP auth layer
    // If we reach here, the user is authorized to use MCP

    list( $action, $resource ) = explode( '_', $tool, 2 );
    $path = "/wp/v2/{$resource}";
    $method = 'GET';

    if ( in_array( $action, [ 'get', 'update', 'delete' ], true ) ) {
      if ( empty( $args['id'] ) ) {
        return $this->error( $id, 'Missing parameter: id', -32602 );
      }
      $path .= '/' . intval( $args['id'] );
    }

    switch ( $action ) {
      case 'create':
      case 'update':
        $method = 'POST';
        break;
      case 'delete':
        $method = 'DELETE';
        break;
      default:
        $method = 'GET';
        break;
    }

    $request = new WP_REST_Request( $method, $path );

    if ( $method === 'GET' ) {
      foreach ( $args as $key => $value ) {
        $request->set_param( $key, $value );
      }
    }
    else {
      $request->set_body_params( $args );
    }

    $response = rest_do_request( $request );

    if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
      $error_obj = is_wp_error( $response ) ? $response : $response->as_error();

      // A fully-formed response, which execute_tool() detects and does not re-wrap.
      return $this->error(
        $id,
        $error_obj->get_error_message(),
        $error_obj->get_error_code() ?: $response->get_status(),
        $error_obj->get_error_data()
      );
    }

    $data = $response->get_data();

    // Return just the data - execute_tool will wrap it properly
    return $data;
  }

  /**
  * A tool failure the model is supposed to read and act on.
  *
  * These used to be JSON-RPC errors. A protocol error carries no result at all, so a
  * client reading result.content found nothing there, called the response malformed and
  * discarded it whole. An isError result is handed to the model as the tool's answer
  * instead. tools-core.php, tools-woo.php, tools-admin.php and server.php's catch block
  * draw the line in the same place, at -32601 for "method not found". Nothing in this
  * file reports that: an unrecognised tool is passed on untouched for another provider
  * or the server to answer, so there is no case here that stays a protocol error.
  *
  * The code, and whatever WordPress attached to the error, go into the text because the
  * result shape has nowhere else to put them. The code is no longer cast to int on the
  * way through, which used to turn every WP_Error name into a 0.
  */
  private function error( $id, string $message, $code, $data = null ): array {
    $text = $message . ' [error ' . $code . ']';
    if ( !empty( $data ) ) {
      $text .= ' ' . wp_json_encode( $data );
    }
    return [
      'jsonrpc' => '2.0',
      'id' => $id,
      'result' => [
        'content' => [ [ 'type' => 'text', 'text' => $text ] ],
        'isError' => true,
      ],
    ];
  }
}
