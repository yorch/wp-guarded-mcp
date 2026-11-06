<?php

/*
 * Derived from AI Engine 3.7.7 (labs/mcp-rest.php), Copyright (C) Jordy Meow,
 * GPLv2 or later. Modified 2026 by Jorge Barnaby: renamed throughout.
 * See the plugin header in guarded-mcp.php for the full attribution.
 */

class GMCP_Tools_Rest {
  // Bump the suffix when build_schema_from_args() changes so old cached schemas are ignored.
  // v5: _fields advertised.
  // v6: a resource whose route belongs to a guarded post type is not generated.
  //
  // Public, and a constant rather than a property, because GMCP_Core clears it on an
  // upgrade and the name has to have one home. Spelled out in two files, it is a name that
  // gets bumped in one of them.
  const CACHE_KEY = 'gmcp_tools_cache_v6';
  private $cache_key = self::CACHE_KEY;
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
        // A route that a guarded post type has taken over is not turned into tools at all.
        // The names above are fixed, so today a guarded type cannot reach here; a plugin
        // that registers one with show_in_rest and a rest_base of "posts", "pages" or
        // "media" could make these tools read and write it through its own controller, past
        // every guard the generic post tools ask. Not generating the tools is the answer
        // that needs no second list of what to refuse. @see resource_is_guarded().
        if ( $this->resource_is_guarded( $resource ) ) {
          continue;
        }
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

  /**
  * Whether a REST resource name belongs to a post type the generic post tools must not
  * read or write: a form submission type, a commerce type, or a type that holds another
  * plugin's configuration.
  *
  * Both lists, whatever the caller's level, because the tools are generated once and cached
  * for every key. An order type is then also unavailable to an admin key here, which costs
  * nothing: the WooCommerce tools are the way to read one.
  */
  private function resource_is_guarded( string $resource ): bool {
    $guarded = array_merge(
      array_map( 'strtolower', array_map( 'strval', GMCP_Core::personal_data_post_types() ) ),
      GMCP_Core::commerce_post_types(),
      array_map( 'strtolower', array_map( 'strval', array_keys( GMCP_Core::plugin_config_post_types() ) ) )
    );
    foreach ( get_post_types( [ 'show_in_rest' => true ], 'objects' ) as $type ) {
      $base = $type->rest_base ? (string) $type->rest_base : (string) $type->name;
      if ( $base === $resource && in_array( strtolower( (string) $type->name ), $guarded, true ) ) {
        return true;
      }
    }
    return false;
  }

  /**
  * Whether a REST resource is the route of a post type, whose rows are posts and whose ids are
  * post ids. The users, terms and comments routes are not, and a row's id there names something
  * else, so the post guard is not asked of it.
  */
  private function resource_is_post_route( string $resource ): bool {
    foreach ( get_post_types( [ 'show_in_rest' => true ], 'objects' ) as $type ) {
      $base = $type->rest_base ? (string) $type->rest_base : (string) $type->name;
      if ( $base === $resource ) {
        return true;
      }
    }
    return false;
  }

  /**
  * Judges the rows of a finished post listing, one by one, by their id, and takes out the id that
  * was only asked for to judge by.
  *
  * The query-side guard (with_readable_posts_sql(): the SQL, and a `the_posts` filter at
  * PHP_INT_MAX) is not the last word on what the response holds. A callback that another plugin
  * registers while the query runs (from `pre_get_posts`, at PHP_INT_MAX) runs behind both, and
  * adds rows after they were judged: measured, a file attached to a form submission, an order's
  * file and a post that shares an order's id came back to a read-only key from list_media,
  * list_posts and list_pages. Nothing registered during the dispatch can run behind this: it is
  * called with the finished data. A row with no id in it was not made by the controller (the id is
  * always asked for), so it is dropped. A row whose id is not a number (a template's id is
  * `theme//slug`) is not a row of the posts table and is left alone.
  *
  * The page can then be short, which says a row was left out; the REST tools cannot fetch more.
  *
  * @param mixed $data
  * @return mixed
  */
  private function judge_post_rows( $data, bool $strip_id ) {
    if ( !is_array( $data ) ) {
      return $data;
    }
    $out = [];
    foreach ( $data as $row ) {
      if ( !is_array( $row ) || !array_key_exists( 'id', $row ) ) {
        continue;
      }
      if ( is_int( $row['id'] ) || ( is_string( $row['id'] ) && ctype_digit( $row['id'] ) ) ) {
        if ( GMCP_Core::post_read_guard( (int) $row['id'] ) !== true ) {
          continue;
        }
      }
      if ( $strip_id ) {
        unset( $row['id'] );
      }
      $out[] = $row;
    }
    return $out;
  }

  /**
  * A page of a post listing built with the offset counted here and not by the database, for the
  * case where the query's guard is in doubt (@see GMCP_Core::with_readable_posts_sql()). The
  * controller is asked for windows of 100 rows from offset 0, each row is judged by its id as in
  * judge_post_rows(), and the rows before the caller's offset are passed over as they are read,
  * inside a bound of 5000 rows looked at, so a page past it is short, as for the other listings.
  *
  * @return array|null The rows, or null when the controller refused a window (the caller then
  *                    keeps its own answer, which has been judged and so is safe).
  */
  private function page_in_php( WP_REST_Request $request, bool $strip_id ): ?array {
    $per = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 10 ) ) );
    $offset = $request->get_param( 'offset' );
    $skip = $offset !== null && $offset !== '' ? max( 0, (int) $offset ) : ( max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ) - 1 ) * $per;
    $out = [];
    $raw = 0;
    $scanned = 0;
    while ( $scanned < 5000 && count( $out ) < $per ) {
      $window = clone $request;
      $window->set_param( 'per_page', 100 );
      $window->set_param( 'page', 1 );
      $window->set_param( 'offset', $raw );
      $held = null;
      $response = GMCP_Core::with_readable_posts_sql( static function () use ( $window ) {
        return rest_do_request( $window );
      }, $held );
      if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
        return null;
      }
      $rows = $response->get_data();
      if ( !is_array( $rows ) || !$rows ) {
        break;
      }
      foreach ( $this->judge_post_rows( $rows, false ) as $row ) {
        // judge_post_rows() leaves out what is refused, so what it returns is the readable rows.
        if ( $skip > 0 ) {
          $skip--;
          continue;
        }
        $out[] = $row;
        if ( count( $out ) >= $per ) {
          break;
        }
      }
      $scanned += count( $rows );
      $raw += count( $rows );
      if ( count( $rows ) < 100 ) {
        break;
      }
    }
    if ( $strip_id ) {
      foreach ( $out as $i => $row ) {
        unset( $out[ $i ]['id'] );
      }
    }
    return $out;
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
      // A field that carries a page builder's layout is not offered: the tool would refuse it.
      if ( in_array( strtolower( trim( (string) $name ) ), GMCP_Core::layout_rest_fields(), true ) ) {
        continue;
      }
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
      // The same read policies the generic post tools ask, about the post and about what it
      // hangs from. The controller refuses an id of the wrong type with a 404 and so answers
      // for a submission's own id, but a media row attached to one is a perfectly good
      // attachment to the media controller, and an update or a delete replies with the row.
      $row_id = intval( $args['id'] );
      $held = GMCP_Core::post_read_guard( $row_id );
      if ( $held !== true ) {
        if ( $held !== GMCP_Core::post_not_found_text( $row_id ) ) {
          return $this->error( $id, $held, -32600 );
        }
        // Below admin, a post this key may not read is asked of the controller as an id that
        // names nothing, so its answer is the controller's own answer for one that does not
        // exist and cannot differ from it.
        $row_id = 0;
        $args['id'] = 0;
      }
      // And the write policy, for a write: the read guard above is silent at admin level, and the
      // controller only checks that the row is of the route's type, so an ordinary page or
      // attachment that shares its id with an order (measured: delete_pages at admin trashed it,
      // which hid every note of the order) was written and deleted through here while the generic
      // post tools refused. @see GMCP_Core::hpos_order_type()
      if ( $action !== 'get' && $row_id > 0 ) {
        $write_held = GMCP_Core::commerce_post_guard( $row_id, $action === 'delete' ? 'Deleting' : 'Updating' );
        if ( $write_held !== true ) {
          return $this->error( $id, $write_held, -32600 );
        }
      }
      $path .= '/' . $row_id;
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

    // The arguments that name another post, judged before the controller writes anything. The net
    // would refuse them too, but from inside the controller after it had inserted the row, so the
    // reply said refused over a draft that stood in the table. @see GMCP_Core::rest_write_refusal().
    if ( $method === 'POST' ) {
      $refused = GMCP_Core::rest_write_refusal( $action === 'update' ? (int) ( $args['id'] ?? 0 ) : 0, $args );
      if ( $refused !== null ) {
        return $this->error( $id, $refused . ' Nothing was written.', 'gmcp_refused' );
      }
    }

    $request = new WP_REST_Request( $method, $path );

    if ( $method === 'GET' ) {
      foreach ( $args as $key => $value ) {
        $request->set_param( $key, $value );
      }
    }
    else {
      // Content goes through the same sanitiser the core tools use before it reaches the
      // REST controller. The key's user is an administrator, so the controller would
      // store it verbatim; the sanitising it applies to lesser users never runs.
      $args = $this->sanitize_write_args( $args );
      if ( $args === null ) {
        return $this->error( $id, 'This tool cannot write content on this install: the content sanitiser is not available, so the write was refused rather than stored unfiltered.', 'gmcp_no_sanitiser' );
      }
      $request->set_body_params( $args );
    }

    // A listing is filtered where it is built (the SQL guard, and a row check inside the query),
    // and the finished rows are judged once more here, by id, after the dispatch: another plugin's
    // query filter can widen the SQL (measured: with `OR 1=1` appended to the WHERE, list_media
    // returned a file attached to a form submission to a read-only key) and can add rows from a
    // callback it registers while the query runs, behind the query-side checks. Only for GET, and
    // only for the lifetime of this one dispatch. @see GMCP_Core::with_readable_posts_sql()
    $listing = $method === 'GET' && $action === 'list';
    // The rows of a post listing are judged by id AFTER the response is built, by this code and not
    // by a hook, so nothing that runs inside the dispatch can run behind the judge. The id is asked
    // of the controller even when the caller's _fields leaves it out, and taken out again below.
    $judge_ids = $listing && $this->resource_is_post_route( $resource );
    $sql_held = null;
    $id_added = false;
    if ( $judge_ids ) {
      $fields = $request->get_param( '_fields' );
      if ( is_array( $fields ) ) {
        $fields = implode( ',', array_map( 'strval', $fields ) );
      }
      if ( is_string( $fields ) && trim( $fields ) !== '' && !in_array( 'id', array_map( 'trim', explode( ',', $fields ) ), true ) ) {
        $request->set_param( '_fields', $fields . ',id' );
        $id_added = true;
      }
    }
    // Rows a create makes, so one the net refuses AFTER it was inserted can be taken back out.
    $made = [];
    $track = static function ( $post_id, $post = null, $update = false ) use ( &$made ) {
      if ( !$update ) {
        $made[] = (int) $post_id;
      }
    };
    if ( $action === 'create' ) {
      add_action( 'wp_insert_post', $track, 0, 3 );
    }
    // Every registered REST field is held away from a page builder's layout for this dispatch, and a
    // field that carries one is removed. @see GMCP_Core::guard_rest_fields()
    $release_fields = GMCP_Core::guard_rest_fields();
    try {
      $response = $listing
        ? GMCP_Core::with_readable_posts_sql( static function () use ( $request ) {
          return rest_do_request( $request );
        }, $sql_held, true )
        : rest_do_request( $request );
    }
    catch ( GMCP_Policy_Refusal | GMCP_Drafts_Only_Refusal $refusal ) {
      // The controller inserts, then applies the featured image, terms and meta, and the net throws
      // from that tail. rest_write_refusal() names what it can before the call; this is for the rest.
      // A refused create must not leave a row, so it is removed, and the reply says which.
      remove_action( 'wp_insert_post', $track, 0 );
      $gone = [];
      $stuck = [];
      foreach ( $made as $new_id ) {
        try {
          if ( wp_delete_post( $new_id, true ) ) {
            $gone[] = $new_id;
            continue;
          }
        }
        catch ( Throwable $e ) {
          // Reported below.
        }
        $stuck[] = $new_id;
      }
      $note = $gone ? ' The ' . ( count( $gone ) === 1 ? 'post' : 'posts' ) . ' this call had already inserted (#' . implode( ', #', $gone ) . ') ' . ( count( $gone ) === 1 ? 'was' : 'were' ) . ' removed again, so nothing was created.' : '';
      if ( $action === 'update' ) {
        // The controller applies the post's own fields before the registered ones, so a refusal
        // raised from a field's callback comes after part of the update was stored.
        $note .= ' The other fields of this call may already have been applied: read the post to see what it holds.';
      }
      if ( $stuck ) {
        $note .= ' Could not remove #' . implode( ', #', $stuck ) . ', which this call had already inserted: delete it with wp_delete_post.';
      }
      return $this->error( $id, $refusal->getMessage() . $note, 'gmcp_refused' );
    }
    finally {
      $release_fields();
      if ( $action === 'create' ) {
        remove_action( 'wp_insert_post', $track, 0 );
      }
    }

    // The SQL guard was in doubt for this listing (the finished query lost it, or was answered
    // without the database): the controller's page and offset counted rows the caller may not read,
    // so the page is built again with the offset passed over here, row by row. Otherwise a short
    // page marked where a hidden row sits, and a page the controller took for out of range was an
    // error. Also for an error, which the same doubt can cause (the controller counted the pages
    // from rows another plugin supplied); a page_in_php() that cannot answer leaves the error as it was.
    if ( $judge_ids && $sql_held === false ) {
      $paged = $this->page_in_php( $request, $id_added );
      if ( $paged !== null ) {
        return $paged;
      }
    }

    if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
      $error_obj = is_wp_error( $response ) ? $response : $response->as_error();

      // A fully-formed response, which execute_tool() detects and does not re-wrap.
      return $this->error(
        $id,
        $error_obj->get_error_message(),
        // $response is a WP_Error when the dispatch itself failed, and a WP_Error has no
        // get_status(). An error with an empty code used to fatal here.
        $error_obj->get_error_code() ?: ( is_wp_error( $response ) ? 'rest_error' : $response->get_status() ),
        $error_obj->get_error_data()
      );
    }

    $data = $response->get_data();

    if ( $judge_ids ) {
      $data = $this->judge_post_rows( $data, $id_added );
    }

    // Return just the data - execute_tool will wrap it properly
    return $data;
  }

  /**
  * Run the markup in a write through GMCP_Tools_Core's own sanitiser.
  *
  * Called, not copied: the post tools and these must not drift apart, which is the same
  * rule the option and post-type policies in GMCP_Core follow. store_html() is private and
  * keeps no state (it reads one filter and calls wp_kses_post and the block walker), so an
  * instance made without its constructor is enough and registers no second set of hooks.
  *
  * Fields that hold markup are cleaned as post content; the title is plain text. A REST
  * field may be a string or an object with a raw member, and both are handled.
  *
  * @return array|null the arguments, cleaned; null when the sanitiser cannot be reached,
  *   so the caller refuses instead of storing unfiltered.
  */
  private function sanitize_write_args( array $args ): ?array {
    try {
      $core = ( new ReflectionClass( 'GMCP_Tools_Core' ) )->newInstanceWithoutConstructor();
      $store = new ReflectionMethod( 'GMCP_Tools_Core', 'store_html' );
      $store->setAccessible( true );
    }
    catch ( Throwable $e ) {
      return null;
    }
    $markup = [ 'content', 'excerpt', 'caption', 'description' ];
    $plain = [ 'title', 'alt_text' ];
    foreach ( $args as $key => $value ) {
      if ( !in_array( $key, array_merge( $markup, $plain ), true ) ) {
        continue;
      }
      $clean = function ( $v ) use ( $key, $markup, $core, $store ) {
        if ( !is_string( $v ) ) {
          return $v;
        }
        return in_array( $key, $markup, true ) ? $store->invoke( $core, $v ) : sanitize_text_field( $v );
      };
      if ( is_array( $value ) ) {
        foreach ( [ 'raw', 'rendered' ] as $member ) {
          if ( isset( $value[ $member ] ) ) {
            $value[ $member ] = $clean( $value[ $member ] );
          }
        }
        $args[ $key ] = $value;
      }
      else {
        $args[ $key ] = $clean( $value );
      }
    }
    return $args;
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
