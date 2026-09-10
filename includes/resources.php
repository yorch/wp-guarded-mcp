<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Things on this site a person can hand to their agent directly.
*
* A tool is something the model decides to call. A resource is something the person
* picks: in a client like Claude Desktop it appears in an attach menu, and the content
* goes into the conversation because somebody chose it, not because a model guessed.
* That difference matters here more than usual. This plugin's threat model is a model
* being talked into something by text it read on the site, and a resource is the one
* path where a human is the one pointing.
*
* Every resource is backed by a tool. Reading reeve://post/12 is the same read as
* wp_get_posts, and it goes through the same gate: a key that may not call wp_get_posts
* may not read post resources either. Without that, resources would be a second door
* into the same room with a different lock on it.
*
* Nothing here writes.
*/
class REEVE_Resources {

  /** How many of each post type to offer in the listing. The templates cover the rest. */
  const LISTING = 20;

  /**
  * The fixed entries. Post listings are added by list_all() so this stays declarative.
  *
  * @return array<string,array{uri:string,name:string,description:string,mimeType:string,tool:string}>
  */
  private static function fixed(): array {
    return [
      'reeve://site/briefing' => [
        'uri' => 'reeve://site/briefing',
        'name' => 'Site briefing',
        'description' => 'Versions, theme, active plugins, post types with counts, the comment queue and the permalink structure. What you would want an agent to read before you ask it anything.',
        'mimeType' => 'application/json',
        'tool' => 'wp_site_briefing',
      ],
      'reeve://site/health' => [
        'uri' => 'reeve://site/health',
        'name' => 'Site health report',
        'description' => 'The results of the WordPress Site Health checks that run without a loopback request.',
        'mimeType' => 'application/json',
        'tool' => 'wp_get_site_health',
      ],
      'reeve://comments/pending' => [
        'uri' => 'reeve://comments/pending',
        'name' => 'Comments awaiting moderation',
        'description' => 'The comment queue, oldest first.',
        'mimeType' => 'application/json',
        'tool' => 'wp_get_comments',
      ],
    ];
  }

  /**
  * @param callable $permitted Given a tool name, whether this caller may reach it.
  */
  public static function listing( callable $permitted ): array {
    $out = [];
    foreach ( self::fixed() as $entry ) {
      if ( $permitted( $entry['tool'] ) ) {
        unset( $entry['tool'] );
        $out[] = $entry;
      }
    }

    if ( !$permitted( 'wp_get_posts' ) ) {
      return $out;
    }

    // Recent posts and pages by name, so the attach menu is useful without the person
    // having to know an ID. Everything else is reachable through the template.
    $recent = get_posts( [
      'post_type' => [ 'post', 'page' ],
      'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
      'numberposts' => self::LISTING,
      'orderby' => 'modified',
      'order' => 'DESC',
      'suppress_filters' => false,
    ] );
    foreach ( $recent as $post ) {
      $out[] = [
        'uri' => 'reeve://post/' . $post->ID,
        'name' => $post->post_title !== '' ? $post->post_title : '(untitled)',
        'description' => ucfirst( $post->post_type ) . ', ' . $post->post_status
          . ', last changed ' . $post->post_modified_gmt . ' GMT',
        'mimeType' => 'text/markdown',
      ];
    }
    return $out;
  }

  public static function templates( callable $permitted ): array {
    if ( !$permitted( 'wp_get_posts' ) ) {
      return [];
    }
    return [
      [
        'uriTemplate' => 'reeve://post/{id}',
        'name' => 'Any post or page by ID',
        'description' => 'The title, status, dates, taxonomy terms and full body of one post, page or custom post type item.',
        'mimeType' => 'text/markdown',
      ],
    ];
  }

  /**
  * @param callable $permitted Given a tool name, whether this caller may reach it.
  * @return array|null The contents payload, or null when the URI names nothing readable.
  */
  public static function read( string $uri, callable $permitted ): ?array {
    $fixed = self::fixed();
    if ( isset( $fixed[ $uri ] ) ) {
      if ( !$permitted( $fixed[ $uri ]['tool'] ) ) {
        return null;
      }
      $text = self::render_fixed( $uri );
      return $text === null ? null : self::contents( $uri, $fixed[ $uri ]['mimeType'], $text );
    }

    if ( preg_match( '#^reeve://post/(\d+)$#', $uri, $m ) ) {
      if ( !$permitted( 'wp_get_posts' ) ) {
        return null;
      }
      return self::read_post( (int) $m[1], $uri );
    }

    return null;
  }

  private static function render_fixed( string $uri ): ?string {
    if ( $uri === 'reeve://site/briefing' || $uri === 'reeve://site/health' ) {
      // Both live in the admin tool class, which a site can switch off. Saying so is
      // better than an empty document that looks like the site has nothing to report.
      $tools = apply_filters( 'reeve_tools', [] );
      $names = array_column( $tools, 'name' );
      $needed = $uri === 'reeve://site/briefing' ? 'wp_site_briefing' : 'wp_get_site_health';
      if ( !in_array( $needed, $names, true ) ) {
        return wp_json_encode( [ 'unavailable' => 'The site administration tools are switched off, so this resource has nothing to read.' ], JSON_PRETTY_PRINT );
      }
    }

    switch ( $uri ) {
      case 'reeve://site/briefing':
      case 'reeve://site/health':
        // Rendered through the tool itself rather than reimplemented, so the two can
        // never drift and a fix in one is a fix in both.
        return self::via_tool( $uri === 'reeve://site/briefing' ? 'wp_site_briefing' : 'wp_get_site_health' );

      case 'reeve://comments/pending':
        $comments = get_comments( [ 'status' => 'hold', 'number' => 50, 'order' => 'ASC' ] );
        $rows = [];
        foreach ( $comments as $comment ) {
          $rows[] = [
            'comment_ID' => (int) $comment->comment_ID,
            'post_ID' => (int) $comment->comment_post_ID,
            'author' => $comment->comment_author,
            'author_email' => $comment->comment_author_email,
            'author_url' => $comment->comment_author_url,
            'date' => $comment->comment_date_gmt . ' GMT',
            'content' => $comment->comment_content,
          ];
        }
        return wp_json_encode( $rows, JSON_PRETTY_PRINT );
    }
    return null;
  }

  /** Borrow a tool's own output so a resource and its tool can never disagree. */
  private static function via_tool( string $tool ): ?string {
    $result = apply_filters( 'reeve_callback', null, $tool, [], 0, null );
    if ( !is_array( $result ) ) {
      return null;
    }
    $text = $result['result']['content'][0]['text'] ?? null;
    return is_string( $text ) ? $text : null;
  }

  private static function read_post( int $id, string $uri ): ?array {
    $post = get_post( $id );
    if ( !$post || $post->post_type === 'revision' || $post->post_status === 'auto-draft' ) {
      return null;
    }

    $terms = [];
    foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
      $names = wp_get_post_terms( $id, $taxonomy, [ 'fields' => 'names' ] );
      if ( !is_wp_error( $names ) && $names ) {
        $terms[] = $taxonomy . ': ' . implode( ', ', $names );
      }
    }

    // A front-matter header rather than JSON: the body is the point, and wrapping a
    // whole post body in a JSON string makes it unreadable to a person checking what
    // they just attached.
    $header = "---\n"
      . 'title: ' . $post->post_title . "\n"
      . 'id: ' . $post->ID . "\n"
      . 'type: ' . $post->post_type . "\n"
      . 'status: ' . $post->post_status . "\n"
      . 'url: ' . get_permalink( $post ) . "\n"
      . 'published: ' . $post->post_date_gmt . " GMT\n"
      . 'modified: ' . $post->post_modified_gmt . " GMT\n"
      . ( $terms ? implode( "\n", $terms ) . "\n" : '' )
      . "---\n\n";

    return self::contents( $uri, 'text/markdown', $header . $post->post_content );
  }

  private static function contents( string $uri, string $mime, string $text ): array {
    return [
      'contents' => [
        [ 'uri' => $uri, 'mimeType' => $mime, 'text' => $text ],
      ],
    ];
  }
}
