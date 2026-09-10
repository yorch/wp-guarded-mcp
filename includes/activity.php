<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* A short history of what agents have actually done.
*
* The rest of this plugin is built on the premise that an agent will occasionally do the
* wrong thing, and that the guards should make the irreversible cases hard to reach. That
* premise has a hole in it: without a record, an agent operates completely invisibly. You
* can see that a plugin is gone, but not that your agent deleted it, when, or on whose
* instruction. Every refusal this plugin makes is also invisible, so a site owner cannot
* tell a quiet afternoon from one where something tried repeatedly to delete a theme.
*
* So this records the last hundred tool calls, including the ones that were refused,
* because the refusals are the interesting ones.
*
* What it is not: a security log. It lives in an option, a concurrent pair of calls can
* lose an entry to a read-modify-write race, and anyone who can write options can rewrite
* it. It is there so a person can see what happened, not so it can be relied on in an
* argument. A site that needs a real audit trail should hook gmcp_tool_called and write
* somewhere append-only.
*/
class GMCP_Activity {

  const OPTION = 'gmcp_activity';
  const LIMIT = 100;

  public function __construct() {
    add_action( 'gmcp_tool_called', [ $this, 'record' ] );
  }

  /**
  * Arguments are deliberately not stored wholesale. They can carry a whole post body,
  * which would bloat the option badly, and they can carry things a site owner would not
  * expect to find sitting in wp_options. But "deleted a plugin" without saying which
  * plugin is not worth recording, so pull out the handful of keys that identify a
  * target and keep only those, truncated.
  */
  private function target( array $args ): string {
    // Ordered most-specific first, so an update naming both an ID and a title reports
    // the ID. post_title is last because it is the only identifier a create has.
    foreach ( [ 'plugin', 'stylesheet', 'ID', 'post_id', 'item_id', 'widget_id', 'menu',
      'key', 'sidebar', 'user_id', 'slug', 'name', 'post_title' ] as $key ) {
      if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
        $value = (string) $args[ $key ];
        if ( $value !== '' ) {
          return mb_substr( $value, 0, 60 );
        }
      }
    }
    return '';
  }

  public function record( $call ): void {
    if ( !is_array( $call ) || empty( $call['tool'] ) ) {
      return;
    }

    $entry = [
      't' => time(),
      'tool' => (string) $call['tool'],
      'target' => $this->target( is_array( $call['args'] ?? null ) ? $call['args'] : [] ),
      // A refusal is reported as an isError result rather than a JSON-RPC error, so
      // "did this actually do anything" has to consider both.
      'ok' => ( ( $call['status'] ?? '' ) === 'success' ) && empty( $call['result']['result']['isError'] ),
      'ms' => (int) ( $call['duration_ms'] ?? 0 ),
      'who' => (string) ( $call['client_name'] ?: ( $call['auth_method'] ?? 'unknown' ) ),
      'err' => $call['error_msg'] ? mb_substr( wp_strip_all_tags( (string) $call['error_msg'] ), 0, 200 ) : '',
    ];

    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];
    $log[] = $entry;
    if ( count( $log ) > self::LIMIT ) {
      $log = array_slice( $log, -self::LIMIT );
    }
    // autoload false: this is read on one admin screen and never on a front-end request.
    update_option( self::OPTION, $log, false );
  }

  /** Most recent first. */
  public static function recent( int $limit = 25 ): array {
    $log = get_option( self::OPTION, [] );
    $log = is_array( $log ) ? $log : [];
    return array_slice( array_reverse( $log ), 0, $limit );
  }

  public static function clear(): void {
    delete_option( self::OPTION );
  }
}
