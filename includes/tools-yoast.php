<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Yoast SEO, and the thing about it the generic post-meta tools get wrong.
*
* Since Yoast 14.0 the front end reads SEO metadata from wp_yoast_indexable, a
* derived table, not from _yoast_wpseo_* post meta. The post meta is still the
* source of truth — the admin editor writes it, and the Indexable_Post_Watcher
* builds the indexable from it on wp_insert_post. But writing
* _yoast_wpseo_title directly through update_post_meta() updates the post meta
* while the indexable row stays stale. The front end renders the old title.
* The admin meta box shows the new one. A human checking the admin thinks it
* worked; a visitor sees the old value.
*
* This group writes through WPSEO_Meta::set_value() (the internal API that
* validates the key and adds the _yoast_wpseo_ prefix) and then rebuilds the
* indexable by calling the Indexable_Post_Watcher directly, not by firing
* wp_insert_post and hoping the watcher is hooked. The read goes through
* YoastSEO()->meta->for_post(), which is the same surface the front end uses,
* so the read-back confirms what a visitor will see, not what the post meta
* holds.
*
* Yoast's classes move between versions, so every call into one is guarded
* and reports what was missing rather than fataling. The class is only
* constructed when the group is switched on, but the per-call check in
* handle_call() is what keeps the tools honest when Yoast has been
* deactivated between the two.
*/
class GMCP_Tools_Yoast {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [
    'yoast_set_post_seo', 'yoast_reindex',
  ];

  /**
  * The user-editable SEO fields, mapped from the short internal key (the one
  * WPSEO_Meta::set_value takes) to the _yoast_wpseo_ meta key. Computed fields
  * (linkdex, content_score, inclusive_language_score,
  * estimated-reading-time-minutes) are deliberately absent: they are analysis
  * outputs, not inputs, and writing them would be the same shape of silent
  * success this group exists to prevent.
  */
  const WRITABLE_FIELDS = [
    'title'                   => '_yoast_wpseo_title',
    'metadesc'                => '_yoast_wpseo_metadesc',
    'focuskw'                 => '_yoast_wpseo_focuskw',
    'canonical'               => '_yoast_wpseo_canonical',
    'bctitle'                 => '_yoast_wpseo_bctitle',
    'meta-robots-noindex'     => '_yoast_wpseo_meta-robots-noindex',
    'meta-robots-nofollow'    => '_yoast_wpseo_meta-robots-nofollow',
    'meta-robots-adv'         => '_yoast_wpseo_meta-robots-adv',
    'opengraph-title'         => '_yoast_wpseo_opengraph-title',
    'opengraph-description'   => '_yoast_wpseo_opengraph-description',
    'opengraph-image'         => '_yoast_wpseo_opengraph-image',
    'opengraph-image-id'      => '_yoast_wpseo_opengraph-image-id',
    'twitter-title'           => '_yoast_wpseo_twitter-title',
    'twitter-description'     => '_yoast_wpseo_twitter-description',
    'twitter-image'           => '_yoast_wpseo_twitter-image',
    'twitter-image-id'        => '_yoast_wpseo_twitter-image-id',
    'schema_page_type'        => '_yoast_wpseo_schema_page_type',
    'schema_article_type'     => '_yoast_wpseo_schema_article_type',
    'is_cornerstone'          => '_yoast_wpseo_is_cornerstone',
  ];

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

  private function tools(): array {
    return [
      'yoast_get_post_seo' => [
        'name' => 'yoast_get_post_seo',
        'description' => 'Read the SEO metadata Yoast renders for a post on the front end: title, meta description, canonical URL, primary focus keyword, robots directives, OpenGraph and Twitter card fields, breadcrumb title, cornerstone flag, and schema types. Reads through Yoast\'s own Surfaces API (YoastSEO()->meta->for_post), which is the same path the front end uses, so the value returned is what a visitor sees — not the raw _yoast_wpseo_* post meta, which can be stale when the wp_yoast_indexable row has not been rebuilt. Use this rather than wp_get_post_meta on _yoast_wpseo_* keys, because reading the post meta directly can disagree with what the front end renders.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID to read SEO metadata for.' ],
          ],
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'yoast_set_post_seo' => [
        'name' => 'yoast_set_post_seo',
        'description' => 'Write SEO metadata for a post through Yoast\'s own API, then rebuild the indexable so the front end shows the new value on the next load. Each field is written through WPSEO_Meta::set_value (the internal API that validates the key and adds the _yoast_wpseo_ prefix), and after all fields are written the indexable is rebuilt by calling the Indexable_Post_Watcher directly. The write is verified against the raw stored _yoast_wpseo_* meta (not the rendered surface, which replaces %%variables%% and fills in defaults), and a field Yoast left unchanged or altered is reported as such. Values must be strings, numbers or booleans; an empty string clears a field. Orders and subscriptions are refused. Only user-editable fields are writable: title, metadesc, focuskw, canonical, bctitle, meta-robots-noindex, meta-robots-nofollow, meta-robots-adv, opengraph-title, opengraph-description, opengraph-image, opengraph-image-id, twitter-title, twitter-description, twitter-image, twitter-image-id, schema_page_type, schema_article_type, is_cornerstone. Computed analysis fields (linkdex, content_score, inclusive_language_score, estimated-reading-time-minutes) are refused. The write is journalled through update_post_meta, so wp_undo_change can put it back.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID to write SEO metadata for.' ],
            'fields' => [
              'type' => 'object',
              'description' => 'A map of SEO field keys to values. Keys are the short internal names (title, metadesc, focuskw, canonical, bctitle, meta-robots-noindex, meta-robots-nofollow, meta-robots-adv, opengraph-title, opengraph-description, opengraph-image, opengraph-image-id, twitter-title, twitter-description, twitter-image, twitter-image-id, schema_page_type, schema_article_type, is_cornerstone). Unknown keys are refused.',
              'additionalProperties' => true,
            ],
          ],
          'required' => [ 'post_id', 'fields' ],
        ],
        'accessLevel' => 'admin',
      ],
      'yoast_reindex' => [
        'name' => 'yoast_reindex',
        'description' => 'Rebuild the Yoast indexable for a single post, so wp_yoast_indexable matches the _yoast_wpseo_* post meta. This is the step that makes a Yoast write visible on the front end: Yoast reads from the indexable table, not from post meta, and a write to post meta alone is a silent success until the indexable is rebuilt. yoast_set_post_seo does this automatically; this tool is for rebuilding after an external write (an import, a WP-CLI script, a direct database edit). Without a post_id the tool refuses and says to use `wp yoast index` from WP-CLI, because a full-site reindex takes minutes on a large site and a tool call takes seconds — the same honesty as the backup tool.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID whose indexable to rebuild. Required: a full-site reindex is a WP-CLI job, not a tool call.' ],
          ],
          'required' => [ 'post_id' ],
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

    // Belt and braces, the same as the Elementor and Kirki tools. The class is
    // only constructed when the group is switched on, but Yoast can be
    // deactivated between that and this call, and every Yoast lookup below
    // would then be a fatal rather than a sentence the caller can act on.
    if ( !defined( 'WPSEO_VERSION' ) ) {
      return $this->error( $r, 'Yoast SEO is not loaded on this site, so its tools cannot run.' );
    }

    switch ( $tool ) {
      case 'yoast_get_post_seo': $r = $this->get_post_seo( $args, $r ); break;
      case 'yoast_set_post_seo': $r = $this->set_post_seo( $args, $r ); break;
      case 'yoast_reindex': $r = $this->reindex( $args, $r ); break;
      default:
        return $this->error( $r, 'Unknown tool', -32601 );
    }

    if ( empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

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

  /**
  * Check that the Surfaces API is available and return it, or an error result.
  *
  * Yoast 14.0 introduced YoastSEO(); older versions do not have it, and the
  * tools cannot read from the indexable table without it. This is a separate
  * check from defined('WPSEO_VERSION') because a site can have an old Yoast
  * installed that defines WPSEO_VERSION but does not expose the Surfaces API.
  */
  private function surfaces_available( array $r ) {
    if ( !function_exists( 'YoastSEO' ) ) {
      return [ $this->error( $r, 'Yoast SEO is installed but the Surfaces API (YoastSEO()) is not available. This tool needs Yoast 14.0 or later.' ), null ];
    }
    try {
      $yoast = YoastSEO();
    } catch ( Throwable $e ) {
      return [ $this->error( $r, 'YoastSEO() threw an exception: ' . $e->getMessage() ), null ];
    }
    return [ null, $yoast ];
  }

  /**
  * Resolve the Indexable_Post_Watcher instance from Yoast's DI container.
  *
  * The watcher is the class that builds an indexable from post meta. Firing
  * wp_insert_post and hoping the watcher is hooked is not reliable: the hook
  * may not be registered in all request contexts (REST, CLI, cron), and even
  * when it is, there is no guarantee it ran before the read-back. Calling the
  * watcher's build_indexable() method directly is the only honest rebuild.
  *
  * Returns [ null, $watcher ] on success, [ $error_result, null ] on failure.
  */
  private function get_post_watcher( array $r ) {
    [ $err, $yoast ] = $this->surfaces_available( $r );
    if ( $err ) {
      return [ $err, null ];
    }
    try {
      $watcher = $yoast->classes->get( \Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher::class );
    } catch ( Throwable $e ) {
      return [ $this->error( $r, 'Could not resolve the Indexable_Post_Watcher from Yoast\'s container: ' . $e->getMessage() ), null ];
    }
    if ( !is_object( $watcher ) || !method_exists( $watcher, 'build_indexable' ) ) {
      return [ $this->error( $r, 'The Indexable_Post_Watcher was resolved but does not have a build_indexable method. The Yoast version may be incompatible.' ), null ];
    }
    return [ null, $watcher ];
  }

  /**
  * Read the SEO metadata for a post through Yoast's Surfaces API.
  *
  * YoastSEO()->meta->for_post() returns a Meta_Surface object whose properties
  * are the values the front end renders. The data comes from
  * wp_yoast_indexable, not from _yoast_wpseo_* post meta, so this is the value
  * a visitor sees. The properties are accessed through the presentation layer,
  * which nests OpenGraph, Twitter, robots, and schema under sub-objects.
  */
  private function get_post_seo( array $args, array $r ): array {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
      return $this->error( $r, 'A valid post_id is required.' );
    }
    $post = get_post( $post_id );
    if ( !$post ) {
      return $this->error( $r, 'Post ' . $post_id . ' does not exist.' );
    }

    [ $err, $yoast ] = $this->surfaces_available( $r );
    if ( $err ) {
      return $err;
    }

    try {
      $meta = $yoast->meta->for_post( $post_id );
    } catch ( Throwable $e ) {
      return $this->error( $r, 'YoastSEO()->meta->for_post() threw an exception: ' . $e->getMessage() );
    }

    if ( !$meta ) {
      return $this->error( $r, 'Yoast returned no SEO metadata for post ' . $post_id . '. The post may be of a type Yoast does not index (revisions, autosaves), or the indexable has not been built yet. Run yoast_reindex for this post.' );
    }

    // The presentation layer holds the rendered values. Access each property
    // defensively, because the shape varies between Yoast versions.
    $data = [];
    $presentation = null;
    try {
      $presentation = $meta->presentation;
    } catch ( Throwable $e ) {
      // Fall through: the properties below are accessed through method calls
      // or direct property access, and the presentation may not be set on
      // all versions.
    }

    // Core fields available through the surface directly.
    foreach ( [ 'title', 'description', 'canonical' ] as $prop ) {
      try {
        $val = $meta->{$prop};
        if ( $val !== null && $val !== false ) {
          $data[ $prop ] = $val;
        }
      } catch ( Throwable $e ) {}
    }

    // Fields nested in the presentation object.
    if ( $presentation ) {
      $presentation_props = [
        'open_graph_title'        => 'opengraph_title',
        'open_graph_description'  => 'opengraph_description',
        'open_graph_image'        => 'opengraph_image',
        'twitter_title'           => 'twitter_title',
        'twitter_description'     => 'twitter_description',
        'twitter_image'           => 'twitter_image',
        'meta_robots_noindex'     => 'robots_noindex',
        'meta_robots_nofollow'    => 'robots_nofollow',
        'breadcrumb_title'        => 'breadcrumb_title',
      ];
      foreach ( $presentation_props as $our_name => $their_name ) {
        try {
          $val = $presentation->{$their_name};
          if ( $val !== null && $val !== false ) {
            $data[ $our_name ] = $val;
          }
        } catch ( Throwable $e ) {}
      }
    }

    // The primary focus keyword and cornerstone flag come from post meta
    // directly, since the surface does not always expose them. These are the
    // source-of-truth values, not the indexable, but they are what the admin
    // editor shows and what the indexable is built from.
    $data['primary_focus_keyword'] = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
    $data['is_cornerstone'] = get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true );

    // Also read the raw post meta for comparison, so the caller can see
    // whether the indexable agrees with the post meta. A disagreement means
    // the indexable is stale and yoast_reindex is needed.
    $raw = [];
    foreach ( self::WRITABLE_FIELDS as $short => $meta_key ) {
      $val = get_post_meta( $post_id, $meta_key, true );
      if ( $val !== '' ) {
        $raw[ $short ] = $val;
      }
    }
    $data['_raw_post_meta'] = $raw;
    $data['_indexable_stale'] = !empty( $raw ) && $this->indexable_is_stale( $post_id, $raw );

    return $this->json( $r, $data );
  }

  /**
  * Check whether the indexable disagrees with the post meta for any field.
  *
  * This is a heuristic: it compares a few key fields between the post meta
  * and the indexable table. A disagreement means the indexable is stale.
  */
  private function indexable_is_stale( int $post_id, array $raw ): bool {
    global $wpdb;
    $table = $wpdb->prefix . 'yoast_indexable';
    // Check the table exists before querying.
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
      return true; // No indexable table at all means stale.
    }
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT title, description, canonical, primary_focus_keyword FROM `{$table}` WHERE object_id = %d AND object_type = 'post' LIMIT 1",
      $post_id
    ), ARRAY_A );
    if ( !$row ) {
      return true; // No indexable row means stale (or not yet built).
    }
    // Compare key fields. If any disagree, the indexable is stale.
    $checks = [
      'title' => $raw['title'] ?? '',
      'metadesc' => $raw['metadesc'] ?? '',
      'canonical' => $raw['canonical'] ?? '',
      'focuskw' => $raw['focuskw'] ?? '',
    ];
    foreach ( $checks as $short => $val ) {
      $col = [ 'title' => 'title', 'metadesc' => 'description', 'canonical' => 'canonical', 'focuskw' => 'primary_focus_keyword' ][ $short ];
      if ( (string) $val !== '' && (string) $val !== (string) ( $row[ $col ] ?? '' ) ) {
        return true;
      }
    }
    return false;
  }

  /**
  * Write SEO metadata for a post through Yoast's API and rebuild the indexable.
  */
  private function set_post_seo( array $args, array $r ): array {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
      return $this->error( $r, 'A valid post_id is required.' );
    }
    $post = get_post( $post_id );
    if ( !$post ) {
      return $this->error( $r, 'Post ' . $post_id . ' does not exist.' );
    }

    $fields = $args['fields'] ?? [];
    if ( !is_array( $fields ) || empty( $fields ) ) {
      return $this->error( $r, 'A non-empty fields map is required.' );
    }

    // Validate every key against the allowlist before writing any. This is the
    // enforcement the adversarial review asked for: WPSEO_Meta::set_value()
    // will accept computed fields like linkdex and content_score, so the tool
    // must refuse them itself.
    $unknown = array_diff_key( $fields, self::WRITABLE_FIELDS );
    if ( !empty( $unknown ) ) {
      $keys = implode( ', ', array_keys( $unknown ) );
      return $this->error( $r, 'Unknown or non-writable SEO field(s): ' . $keys . '. Writable fields: ' . implode( ', ', array_keys( self::WRITABLE_FIELDS ) ) . '. Computed analysis fields (linkdex, content_score, inclusive_language_score, estimated-reading-time-minutes) are not writable.' );
    }

    // A post-type policy belongs to the post type: with HPOS off an order or a subscription
    // is a posts row, and this tool writes meta on any post id it is given.
    $commerce = GMCP_Core::commerce_post_guard( $post_id, 'Writing SEO metadata on' );
    if ( $commerce !== true ) {
      return $this->error( $r, $commerce, -32600 );
    }

    // Scalars only. An array or object would be serialised into a meta row Yoast never
    // writes and its sanitisers do not expect, and after earlier fields had been written.
    // null means "clear", the same as an empty string.
    $want = [];
    foreach ( $fields as $short_key => $value ) {
      if ( $value === null ) {
        $want[ $short_key ] = '';
      } elseif ( is_bool( $value ) ) {
        $want[ $short_key ] = $value ? '1' : '0';
      } elseif ( is_scalar( $value ) ) {
        $want[ $short_key ] = (string) $value;
      } else {
        return $this->error( $r, 'Field "' . $short_key . '" must be a string, number or boolean (an empty string clears it); arrays and objects are not accepted. Nothing was written.' );
      }
    }

    // Write each field through WPSEO_Meta::set_value() if available, falling
    // back to update_post_meta with the full _yoast_wpseo_ key. Both write to
    // the same place; set_value slashes the value and adds the prefix, but is
    // not available on all Yoast versions.
    $before = [];
    foreach ( $want as $short_key => $value ) {
      $before[ $short_key ] = (string) get_post_meta( $post_id, self::WRITABLE_FIELDS[ $short_key ], true );
    }
    foreach ( $want as $short_key => $value ) {
      $meta_key = self::WRITABLE_FIELDS[ $short_key ];
      if ( class_exists( 'WPSEO_Meta' ) && method_exists( 'WPSEO_Meta', 'set_value' ) ) {
        WPSEO_Meta::set_value( $short_key, $value, $post_id );
      } else {
        update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
      }
    }

    // The verdict is the raw stored meta, never the rendered surface. YoastSEO()->meta
    // returns the title as a visitor sees it: %%variables%% replaced, an empty value
    // replaced by the template default, entities texturised. Comparing that with what was
    // written called every ordinary write a failure.
    $verified = [];
    $unchanged = [];
    $normalised = [];
    $rejected = [];
    foreach ( $want as $short_key => $value ) {
      $stored = (string) get_post_meta( $post_id, self::WRITABLE_FIELDS[ $short_key ], true );
      if ( $stored === $value ) {
        if ( $before[ $short_key ] === $value ) {
          $unchanged[] = $short_key;
        } else {
          $verified[] = $short_key;
        }
      } elseif ( $stored === $before[ $short_key ] ) {
        $rejected[] = $short_key . ' (asked for ' . wp_json_encode( $value ) . ', still ' . wp_json_encode( $stored ) . ')';
      } else {
        $normalised[] = $short_key . ' (asked for ' . wp_json_encode( $value ) . ', stored ' . wp_json_encode( $stored ) . ')';
      }
    }

    // Rebuild the indexable by calling the watcher directly, not by firing
    // wp_insert_post and hoping the watcher is hooked. Yoast older than 14 has no
    // indexables at all, and there post meta is the whole story.
    $rebuilt = '';
    if ( !function_exists( 'YoastSEO' ) ) {
      $rebuilt = ' This Yoast version has no indexables, so the post meta is what the front end reads.';
    } else {
      $off = $this->indexing_disabled_reason();
      if ( $off !== null ) {
        $rebuilt = ' The indexable was not rebuilt: ' . $off;
      } else {
        [ $err, $watcher ] = $this->get_post_watcher( $r );
        if ( $err ) {
          $rebuilt = ' WARNING: the indexable could not be rebuilt (' . $err['result']['content'][0]['text'] . '), so the front end may still show the old values. Run yoast_reindex for this post or `wp yoast index` from CLI.';
        } else {
          try {
            $watcher->build_indexable( $post_id );
            $rebuilt = ' Indexable rebuild requested.';
          } catch ( Throwable $e ) {
            $rebuilt = ' WARNING: the indexable rebuild threw an exception: ' . $e->getMessage() . '. The front end may still show the old values. Run yoast_reindex for this post or `wp yoast index` from CLI.';
          }
        }
      }
    }

    $msg = 'SEO metadata for post ' . $post_id . '.';
    if ( $verified ) {
      $msg .= ' Written and confirmed in the stored post meta: ' . implode( ', ', $verified ) . '.';
    }
    if ( $unchanged ) {
      $msg .= ' Already set to that value: ' . implode( ', ', $unchanged ) . '.';
    }
    if ( $normalised ) {
      $msg .= ' Stored, but changed by Yoast on the way in: ' . implode( '; ', $normalised ) . '.';
    }
    $msg .= $rebuilt;
    if ( $rejected ) {
      return $this->error( $r, $msg . ' NOT written, Yoast left the previous value: ' . implode( '; ', $rejected ) . '.', -32603 );
    }
    return $this->text( $r, $msg );
  }

  /**
  * Why Yoast will not write indexables on this site, or null when it will.
  *
  * Yoast only indexes in production (wp_get_environment_type()); on staging, local and
  * development the watcher does nothing, which otherwise looks like a post type that cannot
  * be indexed.
  */
  private function indexing_disabled_reason(): ?string {
    try {
      if ( function_exists( 'YoastSEO' ) ) {
        $yoast = YoastSEO();
        $helper = $yoast->helpers->indexable ?? null;
        if ( is_object( $helper ) && method_exists( $helper, 'should_index_indexables' ) ) {
          if ( $helper->should_index_indexables() ) {
            return null;
          }
          $env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
          return 'Yoast does not write indexables in this environment (wp_get_environment_type() is "' . $env . '"; it indexes in "production" only, unless the Yoast\WP\SEO\should_index_indexables filter says otherwise).';
        }
      }
    } catch ( Throwable $e ) {
      return null;
    }
    return null;
  }

  /**
  * Rebuild the indexable for a single post.
  */
  private function reindex( array $args, array $r ): array {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
      return $this->error( $r, 'A post_id is required. A full-site reindex takes minutes on a large site and a tool call takes seconds, so it is a WP-CLI job: run `wp yoast index` from the server, not a tool call.' );
    }
    $post = get_post( $post_id );
    if ( !$post ) {
      return $this->error( $r, 'Post ' . $post_id . ' does not exist.' );
    }

    $commerce = GMCP_Core::commerce_post_guard( $post_id, 'Rebuilding the SEO indexable of' );
    if ( $commerce !== true ) {
      return $this->error( $r, $commerce, -32600 );
    }

    $off = $this->indexing_disabled_reason();
    if ( $off !== null ) {
      return $this->error( $r, 'Nothing was rebuilt. ' . $off );
    }

    [ $err, $watcher ] = $this->get_post_watcher( $r );
    if ( $err ) {
      return $err;
    }

    // Compare the row before and after, so "rebuilt" is only said when something shows it.
    // build_indexable() returns early without throwing for non-indexable post types.
    global $wpdb;
    $table = $wpdb->prefix . 'yoast_indexable';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
      return $this->error( $r, 'Yoast\'s indexable table (' . $table . ') does not exist on this site, so there is nothing to rebuild into. Yoast creates it on activation or its first indexing run; run `wp yoast index` from CLI or re-save the post in the editor.' );
    }
    $fetch = function () use ( $wpdb, $table, $post_id ) {
      return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE object_id = %d AND object_type = 'post' LIMIT 1", $post_id ), ARRAY_A );
    };
    $before = $fetch();

    try {
      $watcher->build_indexable( $post_id );
    } catch ( Throwable $e ) {
      return $this->error( $r, 'The indexable rebuild threw an exception: ' . $e->getMessage() );
    }

    $after = $fetch();
    if ( !$after ) {
      return $this->error( $r, 'The indexable rebuild ran but no indexable row exists for post ' . $post_id . '. The post type may not be indexable by Yoast (revisions, autosaves, and excluded post types are not).' );
    }
    if ( $before && $before === $after ) {
      return $this->text( $r, 'The indexable row for post ' . $post_id . ' exists and the rebuild left it unchanged: it was already current, or Yoast skipped this post.' );
    }
    return $this->text( $r, 'Indexable ' . ( $before ? 'rebuilt' : 'created' ) . ' for post ' . $post_id . '. The front end will show the current SEO metadata on the next page load.' );
  }

}
