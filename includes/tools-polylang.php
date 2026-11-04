<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Polylang (free), read only.
*
* A multilingual site stores which post is the translation of which in a taxonomy
* (post_translations) that nothing in WordPress core describes, so the generic tools cannot
* answer "is this the same page in German?" and an agent that guesses from titles will link
* the wrong posts. Polylang answers it through its public pll_* functions, which these tools
* call and never bypass: nothing here reads Polylang's terms directly.
*
* READ ONLY, and not by omission. Linking two posts as translations, or giving a post a
* language, changes which page a visitor is shown for their locale and which URL search
* engines treat as the original; Polylang also rewrites related terms and media when a
* language is saved. That belongs to a person in Polylang's own screen until there is a way to
* undo it, and the change journal does not see Polylang's term relationships.
*
* WPML is not part of this group. It is deferred, not forgotten: its data model is different
* (a trid shared through its own icl_translations table), it is reached through filters rather
* than functions, its "not configured" state has several shapes (setup wizard unfinished, no
* secondary language, string translation module off), and it could not be installed to measure.
* A WPML reader written from memory is exactly how a confident "no translations" is produced
* on a site that has them.
*
* The three states an answer can be in are kept apart on purpose, because they look identical
* as an empty list:
*   - not configured: Polylang is not loaded, or is loaded with no languages defined;
*   - not translated: the post has no language, or its post type is not one Polylang manages;
*   - no translations: the post has a language and genuinely stands alone.
*
* Every Polylang call is guarded by function_exists() and wrapped, so a version that lacks one
* produces a sentence rather than a fatal.
*/
class GMCP_Tools_Polylang {

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

  /** Functions every tool here calls. A missing one is reported by name. */
  private function missing_functions(): array {
    $need = [ 'pll_languages_list', 'pll_default_language', 'pll_get_post_language', 'pll_get_post_translations' ];
    return array_values( array_filter( $need, function ( $f ) {
      return !function_exists( $f );
    } ) );
  }

  private function tools(): array {
    return [
      'pll_list_languages' => [
        'name' => 'pll_list_languages',
        'description' => 'List the languages Polylang is configured with: slug, name and locale for each, and which one is the default. The reply carries a state field that separates "Polylang has no languages defined" (not_configured) from a configured site, so an empty list is never read as a site with nothing to translate. Includes languages that currently have no content. Read only.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
        'accessLevel' => 'read',
      ],
      'pll_get_post_translations' => [
        'name' => 'pll_get_post_translations',
        'description' => 'For one post, return its language and its translation group: the language and ID of every post Polylang links to it as a translation, with each one\'s status and post type (not its title or content). The state field says which kind of answer this is: not_configured (Polylang is not loaded or has no languages), post_type_not_translated (Polylang does not manage this post type), language_not_assigned (the post has no language, so it has no group), no_translations (it has a language and is the only post in its group), or translated. Siblings can include drafts and private posts, which is why this is not a read-level tool. Read only; there is deliberately no tool that links posts or sets a language.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'post_id' => [ 'type' => 'integer', 'description' => 'The post ID to look up.' ],
          ],
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

    // Polylang can be deactivated between the group being switched on and a call. "Not loaded"
    // is an error here, not an empty list: an empty list would read as "no languages".
    if ( !defined( 'POLYLANG_VERSION' ) ) {
      return $this->error( $r, 'Polylang is not loaded on this site, so its tools cannot run. This is not the same as the site having no translations (state: not_configured).' );
    }
    $missing = $this->missing_functions();
    if ( $missing ) {
      return $this->error( $r, 'This version of Polylang does not provide ' . implode( ', ', $missing ) . ', so the tool cannot answer. Nothing was guessed.' );
    }

    try {
      switch ( $tool ) {
        case 'pll_list_languages':        $r = $this->list_languages( $r ); break;
        case 'pll_get_post_translations': $r = $this->post_translations( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'Polylang threw an error while running ' . $tool . ': ' . $e->getMessage() );
    }
    return $r;
  }

  #region Tools

  /**
  * The configured languages as rows, or a string saying why they cannot be read.
  *
  * One call per field, joined by position, because pll_languages_list() returns the single field
  * it is asked for; the counts must agree or the join would pair a name with the wrong slug.
  */
  private function languages() {
    $slugs = pll_languages_list( [ 'hide_empty' => false, 'fields' => 'slug' ] );
    if ( !is_array( $slugs ) ) {
      return 'pll_languages_list did not return a list.';
    }
    $names = pll_languages_list( [ 'hide_empty' => false, 'fields' => 'name' ] );
    $locales = pll_languages_list( [ 'hide_empty' => false, 'fields' => 'locale' ] );
    if ( !is_array( $names ) || !is_array( $locales ) || count( $names ) !== count( $slugs ) || count( $locales ) !== count( $slugs ) ) {
      return 'pll_languages_list returned lists of different lengths for slug, name and locale, so they cannot be matched up.';
    }
    $rows = [];
    foreach ( array_values( $slugs ) as $i => $slug ) {
      $rows[] = [ 'slug' => (string) $slug, 'name' => (string) array_values( $names )[ $i ], 'locale' => (string) array_values( $locales )[ $i ] ];
    }
    return $rows;
  }

  private function list_languages( array $r ): array {
    $rows = $this->languages();
    if ( is_string( $rows ) ) {
      return $this->error( $r, $rows );
    }
    if ( !$rows ) {
      return $this->json( $r, [
        'state' => 'not_configured',
        'note' => 'Polylang is loaded but no language is defined. This is not a site with one language and nothing to translate; the setup in Languages > Settings has not been done.',
        'languages' => [],
        'default' => null,
      ] );
    }
    $default = pll_default_language( 'slug' );
    return $this->json( $r, [
      'state' => 'configured',
      'default' => is_string( $default ) && $default !== '' ? $default : null,
      'languages' => $rows,
    ] );
  }

  private function post_translations( array $args, array $r ): array {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    $post = $post_id > 0 ? get_post( $post_id ) : null;
    if ( !$post ) {
      return $this->error( $r, 'No post with ID ' . $post_id . ' exists.' );
    }
    $rows = $this->languages();
    if ( is_string( $rows ) ) {
      return $this->error( $r, $rows );
    }
    $base = [ 'post_id' => $post_id, 'post_type' => $post->post_type ];
    if ( !$rows ) {
      return $this->json( $r, $base + [ 'state' => 'not_configured', 'language' => null, 'translations' => [], 'note' => 'Polylang is loaded but no language is defined, so no post has a language or a translation group.' ] );
    }

    // Optional in older versions; when absent the answer is "unknown", not "translated".
    if ( function_exists( 'pll_is_translated_post_type' ) && !pll_is_translated_post_type( $post->post_type ) ) {
      return $this->json( $r, $base + [ 'state' => 'post_type_not_translated', 'language' => null, 'translations' => [], 'note' => 'Polylang is not set to translate the "' . $post->post_type . '" post type, so it has no language and no translation group.' ] );
    }

    $language = pll_get_post_language( $post_id, 'slug' );
    if ( !is_string( $language ) || $language === '' ) {
      return $this->json( $r, $base + [ 'state' => 'language_not_assigned', 'language' => null, 'translations' => [], 'note' => 'This post has no language assigned in Polylang, so it has no translation group. That is different from having no translations.' ] );
    }

    $group = pll_get_post_translations( $post_id );
    if ( !is_array( $group ) ) {
      return $this->error( $r, 'pll_get_post_translations did not return a list for post ' . $post_id . '.' );
    }
    $siblings = [];
    foreach ( $group as $lang => $sibling_id ) {
      $sibling_id = (int) $sibling_id;
      if ( $sibling_id === $post_id ) {
        continue;
      }
      $siblings[] = [
        'language'    => (string) $lang,
        'post_id'     => $sibling_id,
        'post_status' => get_post_status( $sibling_id ) ?: 'missing',
        'post_type'   => get_post_type( $sibling_id ) ?: null,
      ];
    }
    usort( $siblings, function ( $a, $b ) {
      return strcmp( $a['language'], $b['language'] );
    } );
    // Languages the group does not cover, so "German is missing" is a fact and not an inference.
    $have = array_map( 'strval', array_keys( $group ) );
    $have[] = $language;
    $missing = array_values( array_diff( array_column( $rows, 'slug' ), $have ) );

    return $this->json( $r, $base + [
      'state' => $siblings ? 'translated' : 'no_translations',
      'language' => $language,
      'translations' => $siblings,
      'languages_without_a_translation' => $missing,
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

  private function json( array $r, $data ): array {
    $r['result'] = [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ] ] ];
    return $r;
  }

  #endregion

}
