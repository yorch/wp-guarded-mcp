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
* A prompt is only text. It cannot do anything the tools do not already allow, and it
* runs under whatever access level the caller has, so a read-only token given the comment
* triage prompt will simply report and be unable to act.
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
        'title' => 'Triage the comment queue',
        'description' => 'Read the pending comments and recommend approve, spam or trash for each, with a reason.',
        'arguments' => [
          [ 'name' => 'limit', 'description' => 'How many to look at. Default 20.', 'required' => false ],
        ],
        'template' => 'Use wp_get_comments to fetch up to {limit} comments with status "hold" on this WordPress site.'
          . "\n\n" . 'For each one, judge whether it is genuine, spam, or abusive, and say which and why in one line. '
          . 'Weigh the actual text, whether it engages with the post, and whether the author name or URL looks promotional.'
          . "\n\n" . 'Then give me a single table: comment id, author, your verdict, your one-line reason. '
          . 'Do not approve, spam or delete anything yet. Wait for me to tell you which of your recommendations to apply.',
      ],
      [
        'name' => 'stale_drafts',
        'title' => 'Find forgotten drafts',
        'description' => 'List drafts that have not been touched in a while, with a short summary of each, so you can decide what to finish or bin.',
        'arguments' => [
          [ 'name' => 'months', 'description' => 'How old, in months. Default 6.', 'required' => false ],
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
        'title' => 'What changed on the site recently',
        'description' => 'A plain summary of recent posts, edits, comments and new users, for catching up after time away.',
        'arguments' => [
          [ 'name' => 'days', 'description' => 'How far back to look. Default 7.', 'required' => false ],
        ],
        'template' => 'Give me a summary of what has changed on this WordPress site in the last {days} days.'
          . "\n\n" . 'Cover: posts and pages published or modified, comments received, and users registered. '
          . 'Use wp_get_posts with a date filter, wp_get_comments, and wp_get_users.'
          . "\n\n" . 'Write it as a short briefing I can read in under a minute, not a list of raw records. '
          . 'Lead with anything that looks unusual: a spike in comments, a user registering with an odd address, '
          . 'a published post I might not have expected.',
      ],
      [
        'name' => 'update_review',
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
        'title' => 'Audit published content',
        'description' => 'Look over published posts for missing excerpts, absent featured images, thin content and untagged items.',
        'arguments' => [
          [ 'name' => 'limit', 'description' => 'How many posts to examine. Default 30.', 'required' => false ],
        ],
        'template' => 'Examine the most recent {limit} published posts on this WordPress site.'
          . "\n\n" . 'For each, check whether it has an excerpt, a featured image, at least one category or tag, '
          . 'and whether the body is substantial or unusually short.'
          . "\n\n" . 'Give me a table of only the posts with something missing, one row each, saying what is missing. '
          . 'Then tell me which three would most repay fixing first and why. Change nothing.',
      ],
      [
        'name' => 'site_health_brief',
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

  /** The catalogue as the protocol wants it: no templates, only the metadata. */
  public static function listing(): array {
    $out = [];
    foreach ( self::all() as $prompt ) {
      if ( empty( $prompt['name'] ) ) {
        continue;
      }
      $out[] = [
        'name' => $prompt['name'],
        'title' => $prompt['title'] ?? $prompt['name'],
        'description' => $prompt['description'] ?? '',
        'arguments' => array_values( (array) ( $prompt['arguments'] ?? [] ) ),
      ];
    }
    return $out;
  }

  /**
  * Render one prompt into the message a client will send.
  *
  * Arguments are substituted as {name} placeholders, and anything the caller did not
  * supply falls back to the default named in the template's own description rather than
  * being left as a literal brace, which would reach the model as noise.
  *
  * @return array|null The messages payload, or null when the name is unknown.
  */
  public static function render( string $name, array $args ): ?array {
    foreach ( self::all() as $prompt ) {
      if ( ( $prompt['name'] ?? '' ) !== $name ) {
        continue;
      }
      $text = (string) ( $prompt['template'] ?? '' );

      $defaults = [ 'limit' => '20', 'months' => '6', 'days' => '7' ];
      foreach ( $defaults as $key => $fallback ) {
        $value = isset( $args[ $key ] ) && $args[ $key ] !== '' ? (string) $args[ $key ] : $fallback;
        // Numeric arguments only: these land in a prompt a model acts on, so a value
        // carrying instructions of its own has no business being interpolated.
        $value = preg_replace( '/[^0-9]/', '', $value );
        $text = str_replace( '{' . $key . '}', $value !== '' ? $value : $fallback, $text );
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
}
