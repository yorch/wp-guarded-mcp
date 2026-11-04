<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Expiring links that show one unpublished post to someone who has no account.
*
* WordPress shows a draft only to a logged-in user who can edit it, so "send this to the
* client to look at" has no answer without handing over a login. This is the answer, built
* so that what a link can do is small enough to state in one sentence: it shows ONE post, for
* a limited time, and only while that post is still a draft, pending or scheduled.
*
* The link is stored, not signed. A stateless signature would be shorter, but it cannot be
* revoked, its lifetime would be whatever the signer wrote into it, and a post that was
* published and later reverted to a draft would bring every old link back to life. The
* table is one option row of { sha256(token): post, expiry }. The token is 160 random bits,
* shown once and never stored, so the row cannot be turned back into a working link and a
* guess is not a realistic attack. The option name contains gmcp_, so GMCP_Core's option
* policy refuses every generic tool that might read or write it: an agent cannot extend a
* link's lifetime by editing the row, because it cannot reach the row.
*
* HOW A LINK IS VERIFIED (front end, every request):
*
*   pre_get_posts          Runs only for the MAIN query of a front-end request that carries
*                          the gmcp_preview parameter. The token is looked up, must be
*                          unexpired, and the request must already be a single-post request
*                          for exactly the post the token was issued for (p or page_id equal
*                          to its id). Only then is the query told it may return that post in
*                          an unpublished status. Nothing else is widened: not another
*                          query, not another request, not another id.
*   posts_results          The result is checked against the grant: exactly one row, that
*                          id, an unpublished status. Anything else is emptied, so a
*                          mismatch is a 404, never a different post.
*   template_redirect      Before anything is rendered: the queried object is checked once
*                          more, and the response is made not indexable, not cacheable and
*                          not referrable (the token is in the URL, and a Referer header
*                          would carry it to every external image on the page).
*
* The grant is never a way to see a PUBLISHED post: the query asks for draft, pending and
* future only, so on a published post the query matches nothing and the answer is a 404.
* Publishing, trashing, or deleting a post revokes its links outright, so reverting later
* does not revive them.
*/
class GMCP_Preview {

  const OPTION = 'gmcp_preview_links';
  const PARAM = 'gmcp_preview';
  const DEFAULT_HOURS = 24;
  const MAX_HOURS = 168;
  const MAX_ACTIVE = 100;
  const MAX_PER_POST = 5;

  /** The only statuses a link can show. private, trash, inherit and auto-draft are not on it, on purpose. */
  const STATUSES = [ 'draft', 'pending', 'future' ];

  /** @var array{post:int,exp:int}|null The grant this request was verified against. */
  private $active = null;

  public function __construct() {
    add_action( 'pre_get_posts', [ $this, 'pre_get_posts' ], 1 );
    add_filter( 'posts_results', [ $this, 'posts_results' ], 1, 2 );
    add_action( 'template_redirect', [ $this, 'template_redirect' ], 0 );
    add_action( 'transition_post_status', [ $this, 'status_changed' ], 10, 3 );
    add_action( 'before_delete_post', [ $this, 'post_deleted' ] );
  }

  /* ----------------------------------------------------------------------- the store */

  /** @return array<string,array{post:int,exp:int,by:int,at:int}> */
  private static function all(): array {
    $links = get_option( self::OPTION, [] );
    return is_array( $links ) ? $links : [];
  }

  private static function save( array $links ): void {
    update_option( self::OPTION, $links, false );
  }

  private static function hash( string $token ): string {
    return hash( 'sha256', $token );
  }

  /** Expired entries removed. Pure function of its input and the clock. */
  private static function live( array $links ): array {
    $now = time();
    return array_filter( $links, static function ( $g ) use ( $now ) {
      return is_array( $g ) && (int) ( $g['exp'] ?? 0 ) > $now && (int) ( $g['post'] ?? 0 ) > 0;
    } );
  }

  /**
  * Why a post cannot be given a link, or null when it can.
  *
  * Asked at creation and again at use, so the two cannot disagree about what is allowed.
  */
  public static function refusal_for( $post ): ?string {
    if ( !$post instanceof WP_Post ) {
      return 'No such post.';
    }
    if ( !in_array( $post->post_status, self::STATUSES, true ) ) {
      return 'Post ' . $post->ID . ' is "' . $post->post_status . '". A preview link is for a draft, a pending post or a scheduled one; a published post needs no link, and private and trashed posts are deliberately not shareable this way.';
    }
    if ( !function_exists( 'is_post_type_viewable' ) || !is_post_type_viewable( $post->post_type ) ) {
      return 'Posts of type "' . $post->post_type . '" are not viewable on the front end, so there is nothing for a link to show.';
    }
    return null;
  }

  /**
  * Issue a link.
  *
  * @return array{ok:bool,error?:string,url?:string,expires?:int,hours?:int}
  */
  public static function create( WP_Post $post, int $hours, int $user_id ): array {
    $why = self::refusal_for( $post );
    if ( $why !== null ) {
      return [ 'ok' => false, 'error' => $why ];
    }
    if ( $hours < 1 || $hours > self::MAX_HOURS ) {
      return [ 'ok' => false, 'error' => 'hours must be between 1 and ' . self::MAX_HOURS . ' (7 days). A longer-lived link is a standing exposure of an unpublished post; make a new one when the old one runs out.' ];
    }
    $links = self::live( self::all() );
    if ( count( $links ) >= self::MAX_ACTIVE ) {
      return [ 'ok' => false, 'error' => 'There are already ' . self::MAX_ACTIVE . ' live preview links on this site. Revoke some with wp_revoke_preview_links first.' ];
    }
    $for_post = count( array_filter( $links, static function ( $g ) use ( $post ) {
      return (int) $g['post'] === (int) $post->ID;
    } ) );
    if ( $for_post >= self::MAX_PER_POST ) {
      return [ 'ok' => false, 'error' => 'Post ' . $post->ID . ' already has ' . self::MAX_PER_POST . ' live links. Revoke them with wp_revoke_preview_links, or wait for them to expire.' ];
    }

    $token = bin2hex( random_bytes( 20 ) );
    $now = time();
    $exp = $now + $hours * HOUR_IN_SECONDS;
    $links[ self::hash( $token ) ] = [ 'post' => (int) $post->ID, 'exp' => $exp, 'by' => $user_id, 'at' => $now ];
    self::save( $links );

    // The stored state, not the return value of the write. update_option() answers false
    // for "unchanged" as well as for "failed", and a link that was not stored would be
    // handed out as if it worked and then 404 for whoever it was sent to.
    wp_cache_delete( self::OPTION, 'options' );
    $check = self::all();
    if ( !isset( $check[ self::hash( $token ) ] ) ) {
      return [ 'ok' => false, 'error' => 'The link could not be stored (the options write did not take), so none was issued.' ];
    }

    // Pretty permalinks do not exist for a draft, so the plain query form is the only one
    // that resolves, and for a page it must be page_id.
    $args = $post->post_type === 'page'
      ? [ 'page_id' => $post->ID ]
      : [ 'p' => $post->ID, 'post_type' => $post->post_type ];
    $args[ self::PARAM ] = $token;
    return [ 'ok' => true, 'url' => add_query_arg( $args, home_url( '/' ) ), 'expires' => $exp, 'hours' => $hours ];
  }

  /**
  * Remove links. A post id removes that post's, null removes all of them.
  *
  * @return int How many live links were removed.
  */
  public static function revoke( ?int $post_id ): int {
    $links = self::all();
    $before = count( self::live( $links ) );
    if ( $post_id === null ) {
      $links = [];
    }
    else {
      foreach ( $links as $h => $g ) {
        if ( !is_array( $g ) || (int) ( $g['post'] ?? 0 ) === $post_id ) {
          unset( $links[ $h ] );
        }
      }
    }
    $links = self::live( $links );
    self::save( $links );
    return $before - count( $links );
  }

  /** Live links for a post, without anything that could rebuild one. */
  public static function count_for( int $post_id ): int {
    return count( array_filter( self::live( self::all() ), static function ( $g ) use ( $post_id ) {
      return (int) $g['post'] === $post_id;
    } ) );
  }

  /* ------------------------------------------------------------------ verification */

  /** The token on this request, if it has the right shape. Anything else is treated as absent. */
  private function token(): ?string {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a capability URL, not a form; the token IS the authority
    $raw = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? wp_unslash( $_GET[ self::PARAM ] ) : '';
    return preg_match( '/^[a-f0-9]{40}$/', $raw ) ? $raw : null;
  }

  public function pre_get_posts( $query ): void {
    if ( $this->active !== null || !$query instanceof WP_Query || !$query->is_main_query() ) {
      return;
    }
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
      return;
    }
    $token = $this->token();
    if ( $token === null ) {
      return;
    }
    // Feeds, embeds and trackbacks are answered before template_redirect runs, so the
    // headers below would never be sent for them. They are not previews; refuse to widen.
    if ( $query->is_feed() || $query->is_embed() || $query->is_trackback() || $query->is_robots() || !$query->is_singular() ) {
      return;
    }

    $links = self::live( self::all() );
    $grant = $links[ self::hash( $token ) ] ?? null;
    if ( !$grant ) {
      return; // unknown or expired: the request proceeds as an ordinary one, and a draft is a 404
    }
    $id = (int) $grant['post'];

    // Exactly the post the link was issued for. A request for any other id, or for none,
    // gets nothing extra, so one link can never be used to browse.
    $requested = (int) ( $query->get( 'p' ) ?: $query->get( 'page_id' ) );
    if ( $requested !== $id ) {
      return;
    }
    $post = get_post( $id );
    if ( self::refusal_for( $post ) !== null ) {
      return;
    }

    $query->set( 'post_type', $post->post_type );
    $query->set( 'post_status', self::STATUSES );
    $query->set( 'no_found_rows', true );
    $this->active = [ 'post' => $id, 'exp' => (int) $grant['exp'] ];
  }

  public function posts_results( $posts, $query ) {
    if ( $this->active === null || !$query instanceof WP_Query || !$query->is_main_query() ) {
      return $posts;
    }
    if ( count( $posts ) !== 1 || (int) $posts[0]->ID !== $this->active['post'] || !in_array( $posts[0]->post_status, self::STATUSES, true ) ) {
      $this->active = null;
      return [];
    }
    return $posts;
  }

  public function template_redirect(): void {
    if ( $this->active === null ) {
      return;
    }
    global $wp_query;
    if ( !is_singular() || (int) get_queried_object_id() !== $this->active['post'] || $this->active['exp'] <= time() ) {
      $this->active = null;
      $wp_query->set_404();
      status_header( 404 );
      nocache_headers();
      return;
    }

    nocache_headers();
    if ( !headers_sent() ) {
      header( 'Cache-Control: private, no-store, max-age=0', true );
      header( 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true );
      header( 'Referrer-Policy: no-referrer', true );
    }
    foreach ( [ 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTCDN' ] as $constant ) {
      if ( !defined( $constant ) ) {
        define( $constant, true );
      }
    }
    add_filter( 'wp_robots', static function ( $robots ) {
      return array_merge( (array) $robots, [ 'noindex' => true, 'nofollow' => true, 'noarchive' => true, 'nosnippet' => true ] );
    }, 999 );
    // redirect_canonical would send the visitor to the post's "real" address, dropping the
    // token on the way and turning a working link into a 404.
    add_filter( 'redirect_canonical', '__return_false' );
    remove_action( 'template_redirect', 'wp_old_slug_redirect' );
  }

  /* --------------------------------------------------------------- lifecycle cleanup */

  /**
  * Publishing, trashing or otherwise moving a post out of the shareable states ends its
  * links. Without this a post published and then reverted to a draft would revive every
  * link ever issued for it that had not yet expired.
  */
  public function status_changed( $new_status, $old_status, $post ): void {
    if ( $new_status === $old_status || in_array( $new_status, self::STATUSES, true ) || !$post instanceof WP_Post ) {
      return;
    }
    if ( self::count_for( (int) $post->ID ) > 0 ) {
      self::revoke( (int) $post->ID );
    }
  }

  public function post_deleted( $post_id ): void {
    if ( self::count_for( (int) $post_id ) > 0 ) {
      self::revoke( (int) $post_id );
    }
  }
}
