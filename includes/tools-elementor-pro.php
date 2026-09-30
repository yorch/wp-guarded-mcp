<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Elementor Pro, and the one question the free group cannot answer.
*
* This is deliberately a small group. Most of what Elementor Pro adds is already reachable
* through the free group: a popup, a loop template and a mega menu are elementor_library
* posts with theme-builder conditions, so elementor_list_templates and
* elementor_get_conditions already report them, and a second implementation of that
* comparison would be free to disagree with the one that already reports it. What is left
* over is the question an agent cannot get any other way, and two things Pro does that an
* agent must not be handed.
*
* WHAT IS HERE. elementor_pro_status answers "is Pro really loaded, and which of its modules
* can actually be called", per module rather than from a version string, because Pro is
* optional, licensable, and can be present with a module switched off. And
* elementor_forms_briefing counts Elementor Pro's form submissions. The count is the part
* that matters: Pro creates its submissions tables on demand, on the first submission, and
* until then its own query layer answers "no submissions" while the table is missing — a
* clean-looking zero that means "this site has never received one" and "the feature is not
* running" at the same time. The briefing asks whether the tables exist before it counts,
* and says which of the two answers it is giving.
*
* WHAT IS DELIBERATELY ABSENT, and is not an omission to be helpfully filled in.
*
* No tool reads or writes Custom Code. A snippet's body is stored in post meta and echoed
* UNESCAPED into the page on every request for every visitor, which makes a write tool an
* arbitrary persistent script injection across the whole site, with no restore tool and no
* journal coverage behind it. A read tool for the same field is a reconnaissance tool whose
* only use is to feed the write tool. The briefing reports that snippets exist, where they
* are bound and how large they are, which is what an operator needs to find the one that is
* misbehaving, and never the code itself.
*
* No tool returns individual form submissions. This was considered and rejected: it is the
* same surface the Gravity Forms group already covers for a site's primary form backend,
* and the submissions here are a secondary copy. A submission is a visitor's personal data,
* and a tool that returns one puts the first thousand characters of it into this plugin's
* audit log, where it stays for the retention window — a channel that persists longer than
* the reply the model saw. Counts, which are not personal data, are returned; the rows are
* left to the Elementor admin screen.
*
* Pro tools are their own switch, not part of the free group's. The free group is switched
* on sites running free Elementor, and putting Pro tools there would put a dozen entries in
* front of a model that fail the moment it tries one. Registration is not gated on Pro
* being loaded, for the same reason the free group registers regardless: elementor_pro_status
* is written to answer with Pro switched off, and a gate inside a class that is never
* constructed cannot run.
*/
class GMCP_Tools_Elementor_Pro {

  /**
  * The Pro modules worth answering for, as class => the method a tool would call.
  *
  * The method is not decoration. A class_exists on an autoloaded name resolves on a copy of
  * Pro that registered its autoloader and then bailed out, which is the trap the free group
  * documents for Elementor itself, so each module is only reported as available when the
  * call a tool would make actually exists.
  */
  const MODULES = [
    'theme_builder'      => [ '\ElementorPro\Modules\ThemeBuilder\Module', 'instance' ],
    'forms'              => [ '\ElementorPro\Modules\Forms\Module', 'instance' ],
    'popup'              => [ '\ElementorPro\Modules\Popup\Module', 'instance' ],
    'display_conditions' => [ '\ElementorPro\Modules\DisplayConditions\Module', 'instance' ],
    'custom_code'        => [ '\ElementorPro\Modules\CustomCode\Module', 'instance' ],
    'role_manager'       => [ '\ElementorPro\Modules\RoleManager\Module', 'instance' ],
  ];

  /** The Pro submissions tables. Pro creates them on the first submission, not on install. */
  const SUBMISSION_TABLES = [ 'e_submissions', 'e_submissions_values', 'e_submissions_actions_log' ];

  const SNIPPET_CPT = 'elementor_snippet';
  const SNIPPET_META_PREFIX = '_elementor_';

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

  /**
  * Whether Elementor Pro is present AND working, checked per call.
  *
  * The version constant plus a class plus a method, the same shape as the Gravity Forms and
  * LearnDash groups: Pro is a separate plugin that can be deactivated between the group
  * being switched on and a call.
  */
  private function loaded(): bool {
    return defined( 'ELEMENTOR_PRO_VERSION' )
      && class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' )
      && method_exists( '\ElementorPro\Modules\ThemeBuilder\Module', 'instance' );
  }

  /** Whether one module is genuinely callable, not merely autoloadable. */
  private function module_available( string $key ): bool {
    if ( !isset( self::MODULES[ $key ] ) ) {
      return false;
    }
    [ $class, $method ] = self::MODULES[ $key ];
    return class_exists( $class ) && method_exists( $class, $method );
  }

  private function tools(): array {
    return [
      'elementor_pro_status' => [
        'name' => 'elementor_pro_status',
        'description' => 'Report whether Elementor Pro is really loaded, and which of its modules can actually be called: theme builder, forms, popups, display conditions, custom code and the role manager. Each module is checked by asking whether the class AND the method a tool would call both exist, not by reading a version string, because Pro can be present with a module switched off and because Pro registers an autoloader before it decides whether to boot. Also counts the Pro library templates that exist (popups, loop templates and the like) and reports whether the Pro form-submission tables have ever been created, which is the one fact elementor_forms_briefing needs to avoid reporting a false zero. Changes nothing.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
        'accessLevel' => 'read',
      ],
      'elementor_forms_briefing' => [
        'name' => 'elementor_forms_briefing',
        'description' => 'Count Elementor Pro form submissions: how many exist, how many are unread and how many are in the trash. Counts only, deliberately: this plugin does not return individual Elementor form submissions, because a submission is a visitor\'s personal data and the Gravity Forms tools already cover a site\'s form backend, and a reply containing one would be copied into this plugin\'s audit log for its retention window. Read the rows on the Elementor Submissions screen. The counts come with a warning that matters: Elementor Pro creates its submissions tables on demand, on the first submission, and until then its own query layer returns an empty list rather than an error, so "no submissions" and "the feature has never run" look identical from the totals alone. This tool checks whether the tables exist and says which answer it is giving, so a zero is never silently ambiguous. Changes nothing.',
        'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
        'accessLevel' => 'read',
      ],
    ];
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( !empty( $prev ) || !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    $r = [ 'jsonrpc' => '2.0', 'id' => $id ];

    // elementor_pro_status is answered even with Pro switched off, and that is the point of
    // it: it is the tool an operator reaches for at exactly the moment Pro is not loading,
    // and a gate that refuses before it reports cannot answer "why". The submissions
    // briefing does need Pro, because it counts Pro's data.
    if ( !$this->loaded() && $tool !== 'elementor_pro_status' ) {
      return $this->error( $r, 'Elementor Pro is not loaded on this site, so its tools cannot run.' );
    }

    try {
      switch ( $tool ) {
        case 'elementor_pro_status':    $r = $this->pro_status( $args, $r ); break;
        case 'elementor_forms_briefing': $r = $this->forms_briefing( $args, $r ); break;
        default:
          return $this->error( $r, 'Unknown tool', -32601 );
      }
    } catch ( \Throwable $e ) {
      return $this->error( $r, 'Elementor Pro threw an error while running ' . $tool . ': ' . $e->getMessage() );
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

  /** Whether one of Pro's submissions tables exists. Pro creates them on first submission. */
  private function table_exists( string $name ): bool {
    global $wpdb;
    $table = $wpdb->prefix . $name;
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
  }

  /** The Pro form-submission tables that are present, and those that are not. */
  private function submission_tables(): array {
    $present = [];
    $missing = [];
    foreach ( self::SUBMISSION_TABLES as $name ) {
      if ( $this->table_exists( $name ) ) {
        $present[] = $name;
      } else {
        $missing[] = $name;
      }
    }
    return [ $present, $missing ];
  }

  #endregion

  #region Reads

  private function pro_status( array $args, array $r ): array {
    $modules = [];
    foreach ( array_keys( self::MODULES ) as $key ) {
      $modules[ $key ] = $this->module_available( $key );
    }

    // Counts by Pro library type. Read from the posts table rather than through the free
    // group's template tool, because this must answer with the free group switched off.
    $library = [];
    foreach ( [ 'popup', 'loop-item', 'section', 'container', 'header', 'footer', 'single', 'archive', 'mega-menu' ] as $type ) {
      $n = get_posts( [
        'post_type' => 'elementor_library',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_query' => [ [ 'key' => '_elementor_template_type', 'value' => $type ] ],
      ] );
      if ( $n ) {
        $library[ $type ] = count( $n );
      }
    }

    [ $present, $missing ] = $this->submission_tables();

    $snippets = get_posts( [ 'post_type' => self::SNIPPET_CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
    $snippet_rows = [];
    foreach ( $snippets as $snippet_id ) {
      $snippet_rows[] = $this->snippet_row( (int) $snippet_id );
    }

    return $this->json( $r, [
      'pro_loaded' => $this->loaded(),
      'pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : null,
      'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : null,
      'theme_builder_loaded' => (bool) did_action( 'elementor/loaded' ),
      'modules' => $modules,
      'library_template_counts' => $library,
      'submissions' => [
        'tables_present' => $present,
        'tables_missing' => $missing,
        'tables_exist' => !$missing,
        'note' => $missing
          ? 'Elementor Pro creates its submissions tables on the first submission, so a missing table means the feature has never received one rather than that it is broken. Until they exist, Pro\'s own query layer answers "no submissions" and the two states are indistinguishable from the counts alone.'
          : 'The submissions tables exist, so a zero count from elementor_forms_briefing means no submissions rather than no feature.',
      ],
      'custom_code' => [
        'count' => count( $snippet_rows ),
        'snippets' => $snippet_rows,
        'note' => 'Title, location, priority and size only. The code body is never returned, and there is no tool that reads or writes it: Pro echoes a snippet unescaped into the page on every request, so writing one is an arbitrary persistent script injection with no restore behind it. Whether a snippet actually renders also depends on the theme: Pro prints a location by calling elementor_theme_do_location(), which a block theme such as Twenty Twenty-Five does not do, so a snippet can be stored, enabled and bound and still not appear.',
      ],
    ] );
  }

  /**
  * One Custom Code snippet's metadata, never its body.
  *
  * The meta keys are built from Pro's own constants rather than written as literals, because
  * Pro stores them as '_elementor_' . FIELD_LOCATION and the field name in its form is not
  * the stored key. Hardcoding 'location' would read nothing and report every snippet as
  * unbound, which is the 'seed agreed with the bug' mistake this repo has made before.
  */
  private function snippet_row( int $snippet_id ): array {
    $meta_base = self::SNIPPET_META_PREFIX;
    $location_key = $meta_base . 'location';
    $priority_key = $meta_base . 'priority';
    $code_key = $meta_base . 'code';

    $code = (string) get_post_meta( $snippet_id, $code_key, true );

    $conditions = get_post_meta( $snippet_id, '_elementor_conditions', true );
    $conditions = is_array( $conditions ) ? array_values( array_filter( array_map( 'strval', $conditions ) ) ) : [];

    return [
      'id' => $snippet_id,
      'title' => (string) get_the_title( $snippet_id ),
      'status' => (string) get_post_status( $snippet_id ),
      'location' => (string) get_post_meta( $snippet_id, $location_key, true ),
      'priority' => (int) get_post_meta( $snippet_id, $priority_key, true ),
      'code_bytes' => strlen( $code ),
      'code_sha256' => $code === '' ? null : hash( 'sha256', $code ),
      'conditions' => $conditions,
      'bound_to' => $conditions ? 'its conditions' : 'its location, on every request',
    ];
  }

  private function forms_briefing( array $args, array $r ): array {
    [ $present, $missing ] = $this->submission_tables();

    // The table check is the point. Pro's Query::get_submissions() returns an empty page
    // with total 0 when the table is missing, measured on 4.1.2, so counting without
    // checking would report "no submissions" on every site that has never received one.
    if ( $missing ) {
      return $this->json( $r, [
        'table_exists' => false,
        'counts' => null,
        'tables_missing' => $missing,
        'note' => 'Elementor Pro\'s submissions tables do not exist on this site (' . implode( ', ', $missing ) . '). Pro creates them on the first submission, so this means the feature has never received one. It is NOT the same as "no submissions": until the tables exist, Pro\'s own query layer returns an empty list and a total of 0, which is indistinguishable from a real zero. Nothing is being reported here because there is nothing to count.',
      ] );
    }

    if ( !$this->module_available( 'forms' ) ) {
      return $this->error( $r, 'The Elementor Pro forms module is not available on this site, so there are no submissions to count.' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'e_submissions';

    // Counted directly, and by status explicitly. Pro's own count_submissions_by_status()
    // returns a Collection rather than an array, and its totals merge read/unread into
    // "all", so neither is a good source for numbers this specific.
    $rows = $wpdb->get_results(
      "SELECT status, is_read, COUNT(*) AS n FROM `{$table}` GROUP BY status, is_read",
      ARRAY_A
    );
    $rows = is_array( $rows ) ? $rows : [];

    $all = 0;
    $unread = 0;
    $trash = 0;
    foreach ( $rows as $row ) {
      $n = (int) ( $row['n'] ?? 0 );
      $status = (string) ( $row['status'] ?? '' );
      if ( $status === 'trash' ) {
        $trash += $n;
        continue;
      }
      $all += $n;
      if ( (int) ( $row['is_read'] ?? 0 ) === 0 ) {
        $unread += $n;
      }
    }

    return $this->json( $r, [
      'table_exists' => true,
      'counts' => [
        'total' => $all,
        'unread' => $unread,
        'trash' => $trash,
      ],
      'note' => 'Counts only. Individual submissions are not returned by any tool in this plugin: a submission is a visitor\'s personal data, and read them on the Elementor Submissions screen.',
    ] );
  }

  #endregion

}
