<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Rank Math and All in One SEO: per-post SEO metadata, on one switch.
*
* HOW EACH PLUGIN KEEPS THE DATA decides how each is written, and the two differ. Every claim
* about a third-party plugin below was written from memory of its source and could NOT be
* checked against an installed copy when this file was written. Each is tagged UNVERIFIED so a
* reviewer knows which lines to test first; the code is built so that a wrong guess produces a
* refusal or a read-back mismatch, and never a success message over nothing.
*
* RANK MATH keeps per-post SEO in ordinary post meta (UNVERIFIED): rank_math_title,
* rank_math_description, rank_math_focus_keyword (comma separated, the first is primary),
* rank_math_canonical_url, rank_math_facebook_title, rank_math_facebook_description and
* rank_math_robots, a serialised array that holds "index" or "noindex" beside any other
* directive ("nofollow", "noarchive" and so on). Its front end reads those rows directly
* (UNVERIFIED); there is no derived index of them of the kind Yoast keeps, so a meta write is
* the plugin's own write and the stale-index trap this repo documents for Yoast does not
* apply. That is also why these writes go through update_post_meta() and are recorded by the
* change journal like any other meta write. If Rank Math does keep a cache of its own, this
* group does not know about it, and the reply says the rendered output was not checked.
*
* ALL IN ONE SEO keeps per-post SEO in its OWN TABLE, {prefix}aioseo_posts (UNVERIFIED), one
* row per post, with columns including title, description, keyphrases (a JSON document),
* canonical_url, robots_default, robots_noindex, og_title and og_description. Post meta holds
* none of it, so the generic meta tools cannot reach it at all, and the change journal does
* not watch that table: a write here CANNOT be undone with wp_undo_change. That is why the
* reply to every AIOSEO write carries the previous values, which are the only copy anyone
* gets. The write goes through the plugin's own model
* (\AIOSEO\Plugin\Common\Models\Post::getPost() then save(), UNVERIFIED) rather than an
* UPDATE of our own, so the plugin's sanitising and its row defaults apply, and the result is
* then read back from the table itself.
*
* ACCESS LEVELS. The readers are 'read': what a page declares to search engines is public the
* moment the page is published, and the generic post readers already return a draft's title
* and body at this level. The writers are 'write', and that is a decision to explain rather
* than to assume, because noindex and a canonical URL are higher impact than a title: noindex
* removes a page from search results and a canonical pointing elsewhere hands its ranking to
* another page. The reasons it is still 'write': for Rank Math the same effect is already one
* wp_update_post_meta call away at 'write' (the generic meta tool has no policy on
* rank_math_robots), so a higher level here would only move the request to a tool with fewer
* guards; and a key that may edit a post's content and publish it can already do more to its
* search presence than any field here. The honest remainder is AIOSEO, whose table no
* generic tool reaches, so there this group is the one route and a 'write' key gains a
* capability it did not have. That is flagged in the docs rather than hidden. What is done to
* narrow the impact: robots is a three-way enum with no free-text directives, a canonical must
* point at this site unless the site opts another host in through gmcp_seo_canonical_hosts,
* every reply carries what was there before, and the commerce post guard applies.
*
* WHAT IS DELIBERATELY NOT HERE. No nofollow, noarchive, nosnippet or max-snippet controls:
* each is another way to change how a page is shown, none is needed to fix a title or an
* indexing mistake, and each is another unverified column. No schema or redirect controls.
* No site-wide settings: those are options, and an option has its policy in
* GMCP_Core::option_write_policy(), which these tools never bypass because they never write
* an option. AIOSEO's focus keyphrase is READ but not written (see set_aioseo()): it lives in
* a JSON column whose encoding the model owns, and a guess there would store a double-encoded
* string that the plugin then fails to parse.
*/
class GMCP_Tools_Seo {

  /** Tools here that change the site, and so announce themselves on gmcp_mutate. */
  const MUTATING = [ 'rankmath_set_post_seo', 'aioseo_set_post_seo' ];

  const TITLE_MAX = 300;
  const DESCRIPTION_MAX = 1000;
  const KEYWORD_MAX = 200;
  const URL_MAX = 2000;

  /** The values robots accepts. 'default' hands the decision back to the site-wide setting. */
  const ROBOTS = [ 'default', 'index', 'noindex' ];

  /**
  * Rank Math: tool argument => post meta key.
  *
  * robots is handled separately because its stored form is an array that mixes the
  * index/noindex decision with directives this group does not touch.
  */
  const RM_META = [
    'title'          => 'rank_math_title',
    'description'    => 'rank_math_description',
    'focus_phrase'   => 'rank_math_focus_keyword',
    'canonical'      => 'rank_math_canonical_url',
    'og_title'       => 'rank_math_facebook_title',
    'og_description' => 'rank_math_facebook_description',
  ];
  const RM_ROBOTS = 'rank_math_robots';

  /** AIOSEO: tool argument => column. focus_phrase is read-only there. */
  const AIO_COLUMNS = [
    'title'          => 'title',
    'description'    => 'description',
    'canonical'      => 'canonical_url',
    'og_title'       => 'og_title',
    'og_description' => 'og_description',
  ];
  /** Columns that must exist for the table to be treated as the shape this code was written for. */
  const AIO_REQUIRED = [
    'post_id', 'title', 'description', 'keyphrases', 'canonical_url',
    'robots_default', 'robots_noindex', 'og_title', 'og_description',
  ];

  /**
  * AIOSEO's other per-post robots flags. Inert while robots_default is 1, live the moment it is 0.
  * Robots::post() returns the post-type setting for a row with robots_default = 1 and reads
  * these columns for one with 0, so a stale value left by an import or an old edit starts
  * to apply when an explicit index/noindex is stored.
  */
  const AIO_ROBOTS_FLAGS = [ 'robots_noarchive', 'robots_nosnippet', 'robots_nofollow', 'robots_noimageindex', 'robots_noodp', 'robots_notranslate' ];

  public function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  /**
  * Only the tools for a plugin that is loaded are offered, so a site running one of the two
  * is not shown four tools of which two can only fail. handle_call() checks again per call,
  * for the plugin that was deactivated between listing and calling.
  */
  public function register_tools( $tools ) {
    $mine = [];
    foreach ( $this->tools() as $name => $tool ) {
      if ( strpos( $name, 'rankmath_' ) === 0 && !$this->rankmath_loaded() ) {
        continue;
      }
      if ( strpos( $name, 'aioseo_' ) === 0 && !$this->aioseo_loaded() ) {
        continue;
      }
      $mine[] = $tool;
    }
    return array_merge( is_array( $tools ) ? $tools : [], $mine );
  }

  /** Rank Math: its version constant and its accessor function (UNVERIFIED names). */
  private function rankmath_loaded(): bool {
    return defined( 'RANK_MATH_VERSION' ) && function_exists( 'rank_math' );
  }

  /** AIOSEO: its version constant, its accessor and the model class the writer needs (UNVERIFIED names). */
  private function aioseo_loaded(): bool {
    return defined( 'AIOSEO_VERSION' )
      && function_exists( 'aioseo' )
      && class_exists( '\AIOSEO\Plugin\Common\Models\Post' )
      && method_exists( '\AIOSEO\Plugin\Common\Models\Post', 'getPost' );
  }

  private function write_properties(): array {
    return [
      'post_id' => [ 'type' => 'integer', 'description' => 'The post ID.' ],
      'title' => [ 'type' => 'string', 'description' => 'SEO title (at most ' . self::TITLE_MAX . ' characters, tags stripped). May contain the plugin\'s own template variables, which are stored as written and not expanded. Empty string clears it so the plugin\'s default applies.' ],
      'description' => [ 'type' => 'string', 'description' => 'Meta description (at most ' . self::DESCRIPTION_MAX . ' characters). Empty string clears it.' ],
      'canonical' => [ 'type' => 'string', 'description' => 'Canonical URL. Must be http(s) and on this site\'s host unless the site allows another through the gmcp_seo_canonical_hosts filter. A canonical on another host tells search engines to rank that page instead of this one. Empty string clears it.' ],
      'robots' => [ 'type' => 'string', 'enum' => self::ROBOTS, 'description' => '"noindex" asks search engines to drop the page, "index" asks them to keep it, "default" removes the per-post decision so the site-wide setting for this post type applies. Other robots directives are left exactly as they are.' ],
      'og_title' => [ 'type' => 'string', 'description' => 'Social (OpenGraph) title. Empty string clears it.' ],
      'og_description' => [ 'type' => 'string', 'description' => 'Social (OpenGraph) description. Empty string clears it.' ],
    ];
  }

  private function tools(): array {
    $rm_props = $this->write_properties();
    $rm_props['focus_phrase'] = [ 'type' => 'string', 'description' => 'Focus keyword(s), comma separated; the first is the primary. At most ' . self::KEYWORD_MAX . ' characters. Empty string clears it.' ];
    $aio_props = $this->write_properties();

    return [
      'rankmath_get_post_seo' => [
        'name' => 'rankmath_get_post_seo',
        'description' => 'Read the SEO metadata Rank Math holds for one post: title, description, focus keywords, canonical URL, OpenGraph title and description, and whether the post is set to index, noindex or to inherit the site-wide default. Values are the stored per-post settings, with template variables unexpanded, not the rendered tags: a null means no per-post value is set, which is not the same as the page having no title. The reply carries a seo_state block saying whether Rank Math is loaded and whether the post type can be indexed at all. What the site-wide setting resolves to is not computed here.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'post_id' => [ 'type' => 'integer', 'description' => 'The post ID.' ] ],
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'read',
      ],
      'rankmath_set_post_seo' => [
        'name' => 'rankmath_set_post_seo',
        'description' => 'Set Rank Math SEO metadata for one post. Only the fields named are changed; an empty string clears a field. Rank Math keeps these in post meta and reads them from there, so this writes the meta, then reads the stored rows back from the database and fails if they differ from what was asked. The reply lists the previous value of every field it changed. robots accepts noindex, index or default only and keeps any other directive on the post (nofollow, noarchive) untouched. A canonical must be on this site. Orders and subscriptions are refused. The change is recorded by the change journal, except that a field whose meta key looks credential-shaped is recorded without a copy of its old value (the focus keyword key contains the word "key"), so the old value in the reply is the only copy. noindex and canonical change how the page appears in search results; this tool is write level because the generic meta tool already reaches the same rows at that level, and the effect is reversible by setting the field back. The rendered page and any page cache or sitemap are not checked.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => $rm_props,
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'write',
      ],
      'aioseo_get_post_seo' => [
        'name' => 'aioseo_get_post_seo',
        'description' => 'Read the SEO metadata All in One SEO holds for one post, from its own table: title, description, focus keyphrase, canonical URL, OpenGraph title and description, and whether the post is set to index, noindex or to use the site-wide default. The reply carries a seo_state block that separates "the plugin has no row for this post, so it uses its defaults" from "the plugin\'s table is missing", which are different situations and both look like a post with no SEO data. Values are stored templates, not rendered tags.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'post_id' => [ 'type' => 'integer', 'description' => 'The post ID.' ] ],
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'read',
      ],
      'aioseo_set_post_seo' => [
        'name' => 'aioseo_set_post_seo',
        'description' => 'Set All in One SEO metadata for one post through the plugin\'s own model, then read the row back from its table and fail if it differs from what was asked. Only the fields named are changed; an empty string clears a field. The focus keyphrase is not writable here: it is a JSON column whose encoding the plugin owns. All in One SEO keeps this data in its own table, which the change journal does not watch, so a write CANNOT be undone with wp_undo_change; the reply lists the previous value of every field it changed and that is the only copy. robots accepts noindex, index or default only. A canonical must be on this site. Orders and subscriptions are refused. noindex and canonical change how the page appears in search results; this tool is write level on the reasoning in the docs, and that reasoning is weaker here than for Rank Math because no generic tool reaches this table.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => $aio_props,
          'required' => [ 'post_id' ],
        ],
        'accessLevel' => 'write',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // Per call as well as at registration: either plugin can be deactivated between the group
    // being listed and a call, and every lookup below would then be a fatal.
    if ( strpos( $tool, 'rankmath_' ) === 0 && !$this->rankmath_loaded() ) {
      return $this->error( $r, 'Rank Math is not loaded on this site, so its tools cannot run.' );
    }
    if ( strpos( $tool, 'aioseo_' ) === 0 && !$this->aioseo_loaded() ) {
      return $this->error( $r, 'All in One SEO is not loaded on this site (or its post model is not where this tool expects it), so its tools cannot run.' );
    }

    try {
      switch ( $tool ) {
        case 'rankmath_get_post_seo': $r = $this->get_rankmath( $args, $r ); break;
        case 'rankmath_set_post_seo': $r = $this->set_rankmath( $args, $r ); break;
        case 'aioseo_get_post_seo':   $r = $this->get_aioseo( $args, $r ); break;
        case 'aioseo_set_post_seo':   $r = $this->set_aioseo( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( GMCP_Drafts_Only_Refusal $e ) {
      // A policy refusal from the net under WordPress, not a fault in the SEO plugin. Say
      // what the policy said; "the plugin threw an error" would send a reader to debug it.
      return $this->error( $r, $e->getMessage(), -32600 );
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'The SEO plugin threw an error while running ' . $tool . ': ' . $e->getMessage() . ' Nothing should be assumed about what was written; read the post back.', -32603 );
    }

    if ( empty( $r['result']['isError'] ) && in_array( $tool, self::MUTATING, true ) ) {
      do_action( 'gmcp_mutate', $tool, $args, $r );
    }
    return $r;
  }

  #region Shared checks

  /**
  * The post a call is about, or an error string.
  *
  * Reads and writes both require a post that exists and whose type can have a public URL:
  * an SEO title on a revision or a menu item is a row nothing renders, and a tool that said
  * "updated" over it would be the silent success this repo exists to refuse. Writes also go
  * through the commerce and drafts-only post guards, as every generic post and meta writer does.
  *
  * @return \WP_Post|string
  */
  private function resolve_post( array $args, bool $for_write ) {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
      return 'A valid post_id is required.';
    }
    $post = get_post( $post_id );
    if ( !$post ) {
      return 'Post ' . $post_id . ' does not exist.';
    }
    // The read policies about the post's type, ahead of everything that would return a value
    // from it: a form submission is never read through these tools, and an order only by an
    // admin key. Reads and writes alike, since a write's reply and the SEO fields it reports
    // are a read of the same post.
    $read_policy = GMCP_Core::post_read_guard( $post_id );
    if ( $read_policy !== true ) {
      return $read_policy;
    }
    // Asked first, so that on a site where a filter has made an order type viewable the policy
    // about the object is still the thing that answers.
    //
    // post_write_guard() is the commerce policy and the drafts-only policy together, as every
    // generic post and meta writer asks. The drafts-only half matters most for All in One SEO:
    // it writes its own table through its own database layer, which no WordPress hook the
    // drafts-only net listens to ever sees, so this explicit question is the only thing that
    // stops a drafts-only key setting the title and robots of a live page.
    if ( $for_write ) {
      $guard = GMCP_Core::post_write_guard( $post_id, 'Setting SEO fields on' );
      if ( $guard !== true ) {
        return $guard;
      }
    }
    if ( in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) || wp_is_post_revision( $post ) ) {
      return 'Post ' . $post_id . ' is a ' . ( $post->post_status === 'auto-draft' ? 'blank auto-draft' : 'revision or attachment child' ) . ', which has no SEO settings of its own.';
    }
    if ( !is_post_type_viewable( $post->post_type ) ) {
      return 'Post ' . $post_id . ' is a "' . $post->post_type . '", a post type that has no public page, so an SEO plugin does not index it and there is nothing to set.';
    }
    return $post;
  }

  /** The argument names a write accepts, so a typo is refused rather than ignored. */
  private function unknown_args( array $args, array $allowed ): array {
    return array_values( array_diff( array_keys( $args ), $allowed ) );
  }

  /**
  * Turn a caller's write arguments into validated values, or an error string.
  *
  * Returns field => value for each field named. A string means "set this"; the empty string
  * means "clear this". Fields that are not named are not in the result, which is how an
  * omitted field stays untouched.
  *
  * No sanitize_text_field(): it deletes every %xx pair, and both plugins' template
  * variables are percent-delimited (%date%, %sep%), so it would turn "%date%" into "%".
  * Tags are stripped and line breaks collapsed instead.
  *
  * @return array|string
  */
  private function clean_fields( array $args, array $allowed ) {
    $out = [];
    $limits = [
      'title' => self::TITLE_MAX, 'description' => self::DESCRIPTION_MAX,
      'og_title' => self::TITLE_MAX, 'og_description' => self::DESCRIPTION_MAX,
      'focus_phrase' => self::KEYWORD_MAX,
    ];
    foreach ( $allowed as $field ) {
      if ( !array_key_exists( $field, $args ) ) {
        continue;
      }
      $value = $args[ $field ];
      if ( $value === null ) {
        $value = '';
      }
      if ( !is_string( $value ) ) {
        return 'The field "' . $field . '" has to be a string (an empty string clears it).';
      }
      if ( $field === 'robots' ) {
        if ( !in_array( $value, self::ROBOTS, true ) ) {
          return 'robots has to be one of: ' . implode( ', ', self::ROBOTS ) . '.';
        }
        $out[ $field ] = $value;
        continue;
      }
      if ( $field === 'canonical' ) {
        $url = $this->canonical_or_error( $value );
        if ( $url['error'] !== null ) {
          return $url['error'];
        }
        $out[ $field ] = $url['value'];
        continue;
      }
      $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
      if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limits[ $field ] ) {
        return 'The field "' . $field . '" is ' . mb_strlen( $text ) . ' characters; the limit here is ' . $limits[ $field ] . '.';
      }
      $out[ $field ] = $text;
    }
    return $out;
  }

  /**
  * A canonical URL that is safe to store, or the reason it is not.
  *
  * A canonical is an instruction to search engines about WHICH page to rank. One pointing at
  * another host moves this page's standing there, so a host that is not this site's is
  * refused unless the site names it. The default list is this site's own host (home and
  * siteurl, which differ on some installs). No credentials in a URL, no other schemes.
  *
  * @return array{value:?string,error:?string}
  */
  private function canonical_or_error( string $value ): array {
    $value = trim( $value );
    if ( $value === '' ) {
      return [ 'value' => '', 'error' => null ];
    }
    if ( strlen( $value ) > self::URL_MAX ) {
      return [ 'value' => null, 'error' => 'The canonical URL is longer than ' . self::URL_MAX . ' characters.' ];
    }
    $parts = wp_parse_url( $value );
    if ( !is_array( $parts ) || empty( $parts['host'] ) || !in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) {
      return [ 'value' => null, 'error' => 'The canonical has to be an absolute http or https URL.' ];
    }
    if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
      return [ 'value' => null, 'error' => 'A canonical URL may not carry credentials.' ];
    }
    $hosts = [];
    foreach ( [ home_url(), site_url() ] as $own ) {
      $h = wp_parse_url( $own, PHP_URL_HOST );
      if ( $h ) {
        $hosts[] = strtolower( $h );
      }
    }
    $hosts = array_merge( $hosts, array_map( 'strtolower', (array) apply_filters( 'gmcp_seo_canonical_hosts', [] ) ) );
    if ( !in_array( strtolower( $parts['host'] ), $hosts, true ) ) {
      return [ 'value' => null, 'error' => 'The canonical points at "' . $parts['host'] . '", which is not this site. A canonical on another host asks search engines to rank that page instead of this one, so it is refused. A site that needs it can allow the host through the gmcp_seo_canonical_hosts filter.' ];
    }
    return [ 'value' => esc_url_raw( $value ), 'error' => null ];
  }

  private function bust( int $post_id, string $tool ): void {
    clean_post_cache( $post_id );
    do_action( 'gmcp_post_changed', $post_id, [ 'source' => 'mcp', 'tool' => $tool, 'batch' => false ] );
  }

  #endregion

  #region Rank Math

  /**
  * One meta row read from the database, bypassing the object cache.
  *
  * The write path asserts STORED state, so the read-back must not be answered by the cache
  * update_post_meta() has just refreshed. Returns [exists, value].
  */
  private function stored_meta( int $post_id, string $key ): array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
      $post_id, $key
    ), ARRAY_A );
    if ( !is_array( $row ) ) {
      return [ false, null ];
    }
    return [ true, maybe_unserialize( $row['meta_value'] ) ];
  }

  /**
  * The robots decision a Rank Math robots array amounts to.
  *
  * Rank Math falls back to the post type's setting only when the stored array is EMPTY.
  * A non-empty array with no index/noindex in it (say ["nofollow"]) is used as it is, and
  * Paper::validate_robots() then adds "index" and "follow" itself. So that case is not
  * "default": the page is indexed, whatever the post type's setting says.
  */
  private function rm_robots_summary( $stored_exists, $directives ): string {
    if ( !$stored_exists || !is_array( $directives ) || !$directives ) {
      return 'default';
    }
    if ( in_array( 'noindex', $directives, true ) ) {
      return 'noindex';
    }
    return 'index';
  }

  private function get_rankmath( array $args, array $r ): array {
    $post = $this->resolve_post( $args, false );
    if ( is_string( $post ) ) {
      return $this->error( $r, $post );
    }
    $data = [ 'post_id' => $post->ID, 'post_type' => $post->post_type, 'post_status' => $post->post_status ];
    foreach ( self::RM_META as $field => $key ) {
      [ $exists, $value ] = $this->stored_meta( $post->ID, $key );
      $data[ $field ] = ( $exists && $value !== '' ) ? $value : null;
    }
    [ $exists, $directives ] = $this->stored_meta( $post->ID, self::RM_ROBOTS );
    $data['robots'] = $this->rm_robots_summary( $exists, $directives );
    $data['robots_directives'] = ( $exists && is_array( $directives ) ) ? array_values( $directives ) : [];
    $data['seo_state'] = [
      'plugin_loaded' => true,
      'source' => 'post meta (rank_math_*)',
      'note' => 'A null field has no per-post value, so the plugin falls back to its template for this post type. "robots": "default" means no robots directive at all is stored, which is the only case in which Rank Math applies the site-wide setting for the post type (that setting is not resolved here). Any stored directive, even one that is not index or noindex, switches that fallback off: "index" is reported when the stored set has no noindex, because Rank Math adds "index" itself.',
    ];
    return $this->json( $r, $data );
  }

  private function set_rankmath( array $args, array $r ): array {
    $allowed = array_merge( [ 'post_id' ], array_keys( self::RM_META ), [ 'robots' ] );
    $unknown = $this->unknown_args( $args, $allowed );
    if ( $unknown ) {
      return $this->error( $r, 'Unknown field(s): ' . implode( ', ', $unknown ) . '. Settable: ' . implode( ', ', array_slice( $allowed, 1 ) ) . '.' );
    }
    $post = $this->resolve_post( $args, true );
    if ( is_string( $post ) ) {
      return $this->error( $r, $post, -32600 );
    }
    $wanted = $this->clean_fields( $args, array_slice( $allowed, 1 ) );
    if ( is_string( $wanted ) ) {
      return $this->error( $r, $wanted );
    }
    if ( !$wanted ) {
      return $this->error( $r, 'Name at least one field to set.' );
    }

    $previous = [];
    $expected = [];
    $notes = [];

    foreach ( $wanted as $field => $value ) {
      if ( $field === 'robots' ) {
        continue;
      }
      $key = self::RM_META[ $field ];
      [ $had, $old ] = $this->stored_meta( $post->ID, $key );
      $previous[ $field ] = $had ? $old : null;
      if ( $value === '' ) {
        delete_post_meta( $post->ID, $key );
        $expected[ $field ] = [ $key, null ];
      } else {
        // wp_slash for the reason GMCP_Tools_Core::prepare_meta_value() gives: update_post_meta()
        // unslashes, so an unslashed backslash is eaten on the way in.
        update_post_meta( $post->ID, $key, wp_slash( $value ) );
        $expected[ $field ] = [ $key, $value ];
      }
    }

    if ( isset( $wanted['robots'] ) ) {
      [ $had, $old ] = $this->stored_meta( $post->ID, self::RM_ROBOTS );
      $previous['robots'] = $this->rm_robots_summary( $had, $old );
      $previous['robots_directives'] = ( $had && is_array( $old ) ) ? array_values( $old ) : [];
      // "default" clears EVERY robots directive on the post. Rank Math falls back to the post
      // type's setting only when the stored array is empty, and Paper::validate_robots() adds
      // "index" to anything else, so leaving a stray "nofollow" behind would not hand the
      // decision back, it would pin the page to index. "index" and "noindex" keep the
      // other directives (nofollow, noarchive...) that were already on the post.
      $kept = array_values( array_filter( ( $had && is_array( $old ) ) ? $old : [], function ( $d ) {
        return is_string( $d ) && $d !== 'index' && $d !== 'noindex';
      } ) );
      $new = $wanted['robots'] === 'default' ? [] : array_merge( [ $wanted['robots'] ], $kept );
      if ( $new ) {
        update_post_meta( $post->ID, self::RM_ROBOTS, wp_slash( $new ) );
      } else {
        delete_post_meta( $post->ID, self::RM_ROBOTS );
      }
      $expected['robots'] = [ self::RM_ROBOTS, $new ?: null ];
      if ( $wanted['robots'] === 'default' && $kept ) {
        $notes[] = 'robots "default" removed every robots directive on this post, including ' . implode( ', ', $kept ) . ', because Rank Math only falls back to the post type\'s setting when none is stored. Put them back with the Rank Math editor if they were wanted.';
      }
    }

    // Read every touched row back from the database. A mismatch is an error and not a note:
    // "updated" over a row that holds something else is the defect this check exists for.
    $stored = [];
    $mismatch = [];
    foreach ( $expected as $field => [ $key, $want ] ) {
      [ $exists, $got ] = $this->stored_meta( $post->ID, $key );
      $stored[ $field ] = $exists ? $got : null;
      if ( $want === null ? $exists : ( !$exists || $got !== $want ) ) {
        $mismatch[] = $field;
      }
    }
    $this->bust( $post->ID, 'rankmath_set_post_seo' );

    if ( $mismatch ) {
      return $this->error( $r, 'Read back from the database, these fields do not hold what was asked: ' . implode( ', ', $mismatch ) . '. Stored now: ' . wp_json_encode( $stored ) . '. Previous values: ' . wp_json_encode( $previous ) . '.', -32603 );
    }
    return $this->json( $r, [
      'post_id' => $post->ID,
      'changed' => array_keys( $expected ),
      'previous' => $previous,
      'stored' => $stored,
      'notes' => $notes,
      'verified' => 'Each field was read back from wp_postmeta and matches. The rendered page, a page cache and the sitemap were not checked.',
    ] );
  }

  #endregion

  #region All in One SEO

  private function aio_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'aioseo_posts';
  }

  /**
  * The state of AIOSEO's table: present, and shaped the way this code was written for.
  *
  * @return array{present:bool,missing_columns:string[],table:string}
  */
  private function aio_table_state(): array {
    global $wpdb;
    $table = $this->aio_table();
    $present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    $missing = [];
    if ( $present ) {
      $have = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
      $have = is_array( $have ) ? $have : [];
      $missing = array_values( array_diff( self::AIO_REQUIRED, $have ) );
    }
    return [ 'present' => $present, 'missing_columns' => $missing, 'table' => $table ];
  }

  /** One post's row straight from the table, or null when the plugin has none. */
  private function aio_row( int $post_id ): ?array {
    global $wpdb;
    $table = $this->aio_table();
    $row = $wpdb->get_row( $wpdb->prepare(
      "SELECT title, description, keyphrases, canonical_url, robots_default, robots_noindex, og_title, og_description FROM `{$table}` WHERE post_id = %d LIMIT 1",
      $post_id
    ), ARRAY_A );
    return is_array( $row ) ? $row : null;
  }

  /** The robots flags that are set to 1 on a post's row, for the flags that exist in this version. */
  private function aio_stale_robots_flags( int $post_id ): array {
    global $wpdb;
    $table = $this->aio_table();
    $have = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
    $cols = array_values( array_intersect( self::AIO_ROBOTS_FLAGS, is_array( $have ) ? $have : [] ) );
    if ( !$cols ) {
      return [];
    }
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT `' . implode( '`, `', $cols ) . "` FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );
    $set = [];
    foreach ( is_array( $row ) ? $row : [] as $col => $val ) {
      if ( (int) $val === 1 ) {
        $set[] = $col;
      }
    }
    return $set;
  }

  private function aio_robots( ?array $row ): string {
    if ( !$row || (int) $row['robots_default'] === 1 ) {
      return 'default';
    }
    return (int) $row['robots_noindex'] === 1 ? 'noindex' : 'index';
  }

  private function aio_focus( ?array $row ): ?string {
    if ( !$row || !isset( $row['keyphrases'] ) || $row['keyphrases'] === '' || $row['keyphrases'] === null ) {
      return null;
    }
    $decoded = json_decode( (string) $row['keyphrases'], true );
    $phrase = is_array( $decoded ) ? ( $decoded['focus']['keyphrase'] ?? null ) : null;
    return is_string( $phrase ) && $phrase !== '' ? $phrase : null;
  }

  private function aio_blank( $value ): ?string {
    return ( $value === null || $value === '' ) ? null : (string) $value;
  }

  private function get_aioseo( array $args, array $r ): array {
    $post = $this->resolve_post( $args, false );
    if ( is_string( $post ) ) {
      return $this->error( $r, $post );
    }
    $state = $this->aio_table_state();
    $seo_state = [
      'plugin_loaded' => true,
      'table' => $state['table'],
      'table_present' => $state['present'],
      'missing_columns' => $state['missing_columns'],
    ];
    if ( !$state['present'] || $state['missing_columns'] ) {
      $seo_state['row_present'] = null;
      $seo_state['note'] = !$state['present']
        ? 'All in One SEO is loaded but its posts table does not exist, so there is no stored SEO data to read. This is not the same as a post with none set.'
        : 'All in One SEO\'s posts table is missing columns this tool reads (' . implode( ', ', $state['missing_columns'] ) . '), so its version is not one this tool was written for and nothing is reported.';
      return $this->json( $r, [ 'post_id' => $post->ID, 'seo' => null, 'seo_state' => $seo_state ] );
    }

    $row = $this->aio_row( $post->ID );
    $seo_state['row_present'] = $row !== null;
    $seo_state['source'] = 'the plugin\'s own table';
    $seo_state['note'] = $row === null
      ? 'The plugin has no row for this post, which means every field is its default (usually a template), not that the post is excluded from search.'
      : 'Stored values; template variables are not expanded. robots "default" means the plugin ignores any per-post decision and uses the site-wide setting for this post type, which is not resolved here.';
    return $this->json( $r, [
      'post_id' => $post->ID,
      'post_type' => $post->post_type,
      'post_status' => $post->post_status,
      'seo' => [
        'title' => $this->aio_blank( $row['title'] ?? null ),
        'description' => $this->aio_blank( $row['description'] ?? null ),
        'focus_phrase' => $this->aio_focus( $row ),
        'canonical' => $this->aio_blank( $row['canonical_url'] ?? null ),
        'robots' => $this->aio_robots( $row ),
        'og_title' => $this->aio_blank( $row['og_title'] ?? null ),
        'og_description' => $this->aio_blank( $row['og_description'] ?? null ),
      ],
      'seo_state' => $seo_state,
    ] );
  }

  private function set_aioseo( array $args, array $r ): array {
    $allowed = array_merge( [ 'post_id' ], array_keys( self::AIO_COLUMNS ), [ 'robots' ] );
    $unknown = $this->unknown_args( $args, $allowed );
    if ( $unknown ) {
      $hint = in_array( 'focus_phrase', $unknown, true ) ? ' The focus keyphrase is read-only for All in One SEO (a JSON column the plugin encodes itself).' : '';
      return $this->error( $r, 'Unknown field(s): ' . implode( ', ', $unknown ) . '. Settable: ' . implode( ', ', array_slice( $allowed, 1 ) ) . '.' . $hint );
    }
    $post = $this->resolve_post( $args, true );
    if ( is_string( $post ) ) {
      return $this->error( $r, $post, -32600 );
    }
    $wanted = $this->clean_fields( $args, array_slice( $allowed, 1 ) );
    if ( is_string( $wanted ) ) {
      return $this->error( $r, $wanted );
    }
    if ( !$wanted ) {
      return $this->error( $r, 'Name at least one field to set.' );
    }

    // The table has to exist and have the shape this was written for BEFORE anything is
    // attempted, because the model's save() on a table it cannot find is a database error
    // that reads, in the reply, like any other failure.
    $state = $this->aio_table_state();
    if ( !$state['present'] ) {
      return $this->error( $r, 'All in One SEO is loaded but its posts table (' . $state['table'] . ') does not exist, so nothing can be stored. Nothing was written.' );
    }
    if ( $state['missing_columns'] ) {
      return $this->error( $r, 'All in One SEO\'s posts table lacks the column(s) ' . implode( ', ', $state['missing_columns'] ) . ', so this version is not one the tool was written for. Nothing was written.' );
    }

    $before = $this->aio_row( $post->ID );
    $previous = [];
    foreach ( array_keys( $wanted ) as $field ) {
      $previous[ $field ] = $field === 'robots'
        ? $this->aio_robots( $before )
        : $this->aio_blank( $before[ self::AIO_COLUMNS[ $field ] ] ?? null );
    }

    $class = '\AIOSEO\Plugin\Common\Models\Post';
    $model = $class::getPost( $post->ID );
    if ( !is_object( $model ) || !method_exists( $model, 'save' ) ) {
      return $this->error( $r, 'All in One SEO\'s post model did not return an object with a save() method, so this version is not one the tool was written for. Nothing was written.' );
    }
    if ( empty( $model->post_id ) ) {
      $model->post_id = $post->ID;
    }

    $expect = [];
    $notes = [];
    foreach ( $wanted as $field => $value ) {
      if ( $field === 'robots' ) {
        // robots_default = 1 makes the plugin ignore every per-post robots column, so an
        // explicit decision has to clear it, and "default" has to set it.
        $was_default = $this->aio_robots( $before ) === 'default';
        $model->robots_default = $value === 'default' ? 1 : 0;
        $model->robots_noindex = $value === 'noindex' ? 1 : 0;
        $expect['robots'] = $value;
        // Going from default to an explicit decision switches the plugin from the post-type
        // setting to THIS ROW, and every other robots flag on it starts to apply with it,
        // including stale ones the person never chose here. Start from none.
        if ( $value !== 'default' && $was_default ) {
          $stale = $this->aio_stale_robots_flags( $post->ID );
          foreach ( self::AIO_ROBOTS_FLAGS as $flag ) {
            $model->{$flag} = 0;
          }
          if ( $stale ) {
            $notes[] = 'The row held robots flags (' . implode( ', ', $stale ) . ') that did nothing while the post followed the site-wide setting; they would have started to apply, so they were reset to off together with this decision.';
          }
        }
        continue;
      }
      $column = self::AIO_COLUMNS[ $field ];
      $model->{$column} = $value;
      $expect[ $field ] = $value;
    }
    $model->save();

    // Stored state, from the table itself and not from the model that was just saved.
    $after = $this->aio_row( $post->ID );
    $stored = [];
    $mismatch = [];
    foreach ( $expect as $field => $want ) {
      if ( $field === 'robots' ) {
        $got = $this->aio_robots( $after );
        $stored['robots'] = $got;
        if ( $got !== $want ) {
          $mismatch[] = 'robots';
        }
        continue;
      }
      $got = $after === null ? null : $this->aio_blank( $after[ self::AIO_COLUMNS[ $field ] ] ?? null );
      $stored[ $field ] = $got;
      if ( ( $want === '' ? null : $want ) !== $got ) {
        $mismatch[] = $field;
      }
    }
    $this->bust( $post->ID, 'aioseo_set_post_seo' );

    if ( $after === null || $mismatch ) {
      return $this->error( $r, ( $after === null ? 'After save(), the plugin\'s table has no row for post ' . $post->ID . '. ' : '' )
        . 'Read back from the table, these fields do not hold what was asked: ' . ( $mismatch ? implode( ', ', $mismatch ) : 'all of them' )
        . '. The plugin may have normalised a value, or the write did not happen. Stored now: ' . wp_json_encode( $stored )
        . '. Previous values (not journalled, this is the only copy): ' . wp_json_encode( $previous ) . '.', -32603 );
    }
    return $this->json( $r, [
      'post_id' => $post->ID,
      'changed' => array_keys( $expect ),
      'previous' => $previous,
      'stored' => $stored,
      'notes' => $notes,
      'journal' => 'Not recorded. The change journal does not watch All in One SEO\'s table, so wp_undo_change cannot put this back; "previous" is the only copy.',
      'verified' => 'Each field was read back from the plugin\'s own table and matches. The rendered page, a page cache and the sitemap were not checked.',
    ] );
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
