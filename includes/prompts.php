<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Ready-made workflows, surfaced in the client as things you can pick.
*
* MCP has a prompts capability that almost nobody implements, and it solves the problem
* every new user of a tool server has: the tools are all there, and you still have to
* already know what to ask for. A prompt turns "you now have 66 tools" into a short menu
* of jobs worth doing, which the client shows alongside its own commands.
*
* These are deliberately about upkeep rather than authoring. An agent writing your posts
* is a matter of taste; an agent working through a comment queue, finding drafts everyone
* forgot, or reading what actually changed last week is unambiguously useful and is work
* nobody enjoys.
*
* A prompt is only text. It cannot do anything the tools do not already allow, and it runs
* under whatever access level the caller has, so a read-only token given the comment triage
* prompt can report and cannot act.
*
* That is a claim about the prompt, not a promise that every tool it names will answer. A
* prompt whose tools are not registered is hidden from the listing rather than offered and
* then refused, but a tool that exists at a level above the caller's still returns a
* refusal mid-task, and the templates are written to carry on rather than stop when that
* happens.
*/
class REEVE_Prompts {

  public function __construct() {
    add_filter( 'reeve_prompts', [ $this, 'register' ] );
  }

  /**
  * @return array<int,array{name:string,title:string,description:string,arguments:array,template:string}>
  */
  public function register( array $prev ): array {
    $prompts = [
      [
        'name' => 'triage_comments',
        'tools' => [ 'wp_get_comments' ],
        'title' => 'Triage the comment queue',
        'description' => 'Read the pending comments and recommend approve, spam or trash for each, with a reason.',
        'arguments' => [
          [ 'name' => 'limit', 'description' => 'How many to look at. Default 20.', 'required' => false, 'default' => '20' ],
        ],
        'template' => 'Use wp_get_comments to fetch up to {limit} comments with status "hold" on this WordPress site.'
          . "\n\n" . 'For each one, judge whether it is genuine, spam, or abusive, and say which and why in one line. '
          . 'Weigh the actual text, whether it engages with the post, and whether the author name or URL looks promotional.'
          . "\n\n" . 'Then give me a single table: comment id, author, your verdict, your one-line reason. '
          . 'Do not approve, spam or delete anything yet. Wait for me to tell you which of your recommendations to apply.',
      ],
      [
        'name' => 'stale_drafts',
        'tools' => [ 'wp_get_posts' ],
        'title' => 'Find forgotten drafts',
        'description' => 'List drafts that have not been touched in a while, with a short summary of each, so you can decide what to finish or bin.',
        'arguments' => [
          [ 'name' => 'months', 'description' => 'How old, in months. Default 6.', 'required' => false, 'default' => '6' ],
        ],
        'template' => 'Find every draft post on this WordPress site that has not been modified in the last {months} months. '
          . 'Use wp_get_posts with post_status "draft".'
          . "\n\n" . 'For each, give me: the title, how long it has been sitting there, roughly how complete it looks '
          . 'judging by its length and whether it ends mid-thought, and a one-line summary of what it is about.'
          . "\n\n" . 'Sort them oldest first. Recommend which are worth finishing and which are worth deleting, and say why. '
          . 'Do not delete anything.',
      ],
      [
        'name' => 'whats_changed',
        'tools' => [ 'wp_get_posts', 'wp_get_comments' ],
        'title' => 'What changed on the site recently',
        'description' => 'A plain summary of recent posts, edits, comments and new users, for catching up after time away.',
        'arguments' => [
          [ 'name' => 'days', 'description' => 'How far back to look. Default 7.', 'required' => false, 'default' => '7' ],
        ],
        'template' => 'Give me a summary of what has changed on this WordPress site in the last {days} days.'
          . "\n\n" . 'Cover posts and pages published or modified, and comments received, using wp_get_posts '
          . 'with a date filter and wp_get_comments. If wp_get_users is available to you, include users '
          . 'registered in the same period; if it is not, say so in one line and carry on rather than stopping.'
          . "\n\n" . 'Write it as a short briefing I can read in under a minute, not a list of raw records. '
          . 'Lead with anything that looks unusual: a spike in comments, a user registering with an odd address, '
          . 'a published post I might not have expected.',
      ],
      [
        'name' => 'update_review',
        'tools' => [ 'wp_list_plugins', 'wp_list_themes' ],
        'title' => 'Review pending updates',
        'description' => 'List plugins and themes with updates available and advise on the order to apply them.',
        'arguments' => [],
        'template' => 'List every plugin and theme on this WordPress site that has an update available, using wp_list_plugins '
          . 'and wp_list_themes.'
          . "\n\n" . 'For each, tell me the installed version and the available one, and whether the jump is a patch, '
          . 'a minor or a major version.'
          . "\n\n" . 'Then recommend an order to apply them, putting security-relevant and low-risk patch updates first '
          . 'and major version jumps last, and flag any that I should back up before touching. '
          . 'Do not install anything yet.',
      ],
      [
        'name' => 'content_audit',
        'tools' => [ 'wp_get_posts' ],
        'title' => 'Audit published content',
        'description' => 'Look over published posts for missing excerpts, absent featured images, thin content and untagged items.',
        'arguments' => [
          [ 'name' => 'limit', 'description' => 'How many posts to examine. Default 30.', 'required' => false, 'default' => '30' ],
        ],
        'template' => 'Examine the most recent {limit} published posts on this WordPress site.'
          . "\n\n" . 'For each, check whether it has an excerpt, a featured image, at least one category or tag, '
          . 'and whether the body is substantial or unusually short.'
          . "\n\n" . 'Give me a table of only the posts with something missing, one row each, saying what is missing. '
          . 'Then tell me which three would most repay fixing first and why. Change nothing.',
      ],
      [
        'name' => 'site_health_brief',
        'tools' => [ 'wp_get_site_health' ],
        'title' => 'Explain this site\'s health',
        'description' => 'Run the Site Health checks and translate the results into plain language with a recommended order of work.',
        'arguments' => [],
        'template' => 'Run wp_get_site_health on this WordPress site.'
          . "\n\n" . 'Explain each critical and recommended finding in plain language: what it means, what the actual '
          . 'consequence is if I ignore it, and roughly how hard it is to fix. Skip anything that is only informational.'
          . "\n\n" . 'Then give me a short ordered list of what to do first, weighing real risk against effort. '
          . 'Be honest when something is technically flagged but does not matter much for a site like this one.',
      ],
    ];

    return array_merge( $prev, $prompts );
  }

  /** All registered prompts, including any a site has added through the filter. */
  public static function all(): array {
    $prompts = apply_filters( 'reeve_prompts', [] );
    return is_array( $prompts ) ? $prompts : [];
  }

  /**
  * The catalogue as the protocol wants it: no templates, only the metadata.
  *
  * Prompts whose tools are not registered on this site are left out entirely. The
  * administration tools are off by default, so a stock install was offering "Explain this
  * site's health" and "Review pending updates" in the client's menu, and picking either
  * sent the agent at a tool that does not exist. A menu entry that cannot work is worse
  * than no entry: the person has already decided to do the thing before they find out.
  *
  * @param callable|null $permitted Given a tool name, whether this caller can reach it.
  */
  public static function listing( ?callable $permitted = null ): array {
    $out = [];
    foreach ( self::all() as $prompt ) {
      if ( empty( $prompt['name'] ) ) {
        continue;
      }
      if ( $permitted !== null && !self::usable( $prompt, $permitted ) ) {
        continue;
      }
      $out[] = [
        'name' => $prompt['name'],
        'title' => $prompt['title'] ?? $prompt['name'],
        'description' => $prompt['description'] ?? '',
        'arguments' => self::arguments( $prompt ),
      ];
    }
    return $out;
  }

  /** Every tool a prompt drives has to be reachable, or the prompt is not offered. */
  private static function usable( array $prompt, callable $permitted ): bool {
    foreach ( (array) ( $prompt['tools'] ?? [] ) as $tool ) {
      if ( !is_string( $tool ) || !$permitted( $tool ) ) {
        return false;
      }
    }
    return true;
  }

  /**
  * A prompt's declared arguments, in the shape the protocol requires.
  *
  * MCP wants a list of objects, each with a name. A prompt registered through the filter
  * can declare anything, and `(array) 'age'` produces `["age"]`, a bare string where an
  * object belongs. One malformed third-party prompt would make the whole listing invalid
  * to a strict client, so entries that are not shaped like arguments are dropped rather
  * than passed through.
  */
  private static function arguments( array $prompt ): array {
    $out = [];
    foreach ( (array) ( $prompt['arguments'] ?? [] ) as $argument ) {
      if ( !is_array( $argument ) || empty( $argument['name'] ) || !is_string( $argument['name'] ) ) {
        continue;
      }
      $row = [ 'name' => $argument['name'] ];
      if ( isset( $argument['description'] ) && is_string( $argument['description'] ) ) {
        $row['description'] = $argument['description'];
      }
      $row['required'] = !empty( $argument['required'] );
      $out[] = $row;
    }
    return $out;
  }

  /**
  * Render one prompt into the message a client will send.
  *
  * Substitution is driven by the prompt's OWN declared arguments. It used to be driven by
  * a hardcoded map of three names, which was wrong in both directions: a third-party
  * prompt declaring an "age" argument had the caller's value dropped and `{age}` delivered
  * to the model as a literal brace, while an unrelated literal `{days}` in that same
  * template was rewritten to 7. It also meant content_audit advertised a default of 30 and
  * rendered 20.
  *
  * @return array|null The messages payload, or null when the name is unknown.
  */
  public static function render( string $name, array $args, ?callable $permitted = null ): ?array {
    foreach ( self::all() as $prompt ) {
      if ( ( $prompt['name'] ?? '' ) !== $name ) {
        continue;
      }
      // Same gate as the listing. Without it the listing filter is decoration: a key
      // that is not offered a prompt reaches the identical text through the other verb,
      // by name. The client is not the boundary.
      if ( $permitted !== null && !self::usable( $prompt, $permitted ) ) {
        $missing = [];
        foreach ( (array) ( $prompt['tools'] ?? [] ) as $tool ) {
          if ( !is_string( $tool ) || !$permitted( $tool ) ) {
            $missing[] = is_string( $tool ) ? $tool : '(unnamed)';
          }
        }
        return [ '__reeve_unavailable' => $missing ];
      }
      $text = (string) ( $prompt['template'] ?? '' );

      foreach ( self::arguments( $prompt ) as $argument ) {
        $key = $argument['name'];
        $declared = self::declared( $prompt, $key );
        $fallback = isset( $declared['default'] ) ? (string) $declared['default'] : '';
        $text = str_replace( '{' . $key . '}', self::value( $args[ $key ] ?? null, $fallback ), $text );
      }

      return [
        'description' => $prompt['description'] ?? '',
        'messages' => [
          [
            'role' => 'user',
            'content' => [ 'type' => 'text', 'text' => $text ],
          ],
        ],
      ];
    }
    return null;
  }

  private static function declared( array $prompt, string $name ): array {
    foreach ( (array) ( $prompt['arguments'] ?? [] ) as $argument ) {
      if ( is_array( $argument ) && ( $argument['name'] ?? '' ) === $name ) {
        return $argument;
      }
    }
    return [];
  }

  /**
  * Validate an argument, or fall back. Never repair one.
  *
  * These land in a prompt a model acts on, so a value carrying instructions of its own has
  * no business being interpolated. The first version stripped non-digits, which is
  * repairing rather than validating and quietly produced a different number than the
  * caller asked for: "1e3" became 13, 1.5 became 15, "-5" became 5, and 2.0E+21 became
  * 2021. Refusing the value and using the default is the only answer that cannot silently
  * mean something else. The length cap is part of that: a four-hundred-digit limit was
  * being interpolated whole.
  */
  private static function value( $given, string $fallback ): string {
    if ( !is_scalar( $given ) ) {
      // An array here used to reach a string cast and log "Array to string conversion",
      // which on a site with WP_DEBUG_DISPLAY prepends warning text to the JSON-RPC body
      // and hands the client a parse error instead of a result.
      return $fallback;
    }
    $value = trim( (string) $given );
    return preg_match( '/^[0-9]{1,4}$/', $value ) ? $value : $fallback;
  }
}
