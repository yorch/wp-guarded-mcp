<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Explicit class map. The plugin has a handful of classes, so a map is clearer and
* cheaper than prefix matching, and a typo fails loudly instead of silently resolving
* to a file that does not exist.
*/
spl_autoload_register( function ( $class ) {
  static $map = [
    'REEVE_Core' => '/includes/core.php',
    'REEVE_Logging' => '/includes/logging.php',
    'REEVE_Settings' => '/includes/settings.php',
    'REEVE_Server' => '/includes/server.php',
    'REEVE_OAuth' => '/includes/oauth.php',
    'REEVE_Tools_Core' => '/includes/tools-core.php',
    'REEVE_Tools_Rest' => '/includes/tools-rest.php',
    'REEVE_Tools_Woo' => '/includes/tools-woo.php',
    'REEVE_Tools_Admin' => '/includes/tools-admin.php',
    'REEVE_Activity' => '/includes/activity.php',
    'REEVE_Journal' => '/includes/journal.php',
    'REEVE_Tokens' => '/includes/tokens.php',
    'REEVE_SelfTest' => '/includes/selftest.php',
    'REEVE_Prompts' => '/includes/prompts.php',
    'REEVE_Resources' => '/includes/resources.php',
    'Parsedown' => '/vendor/Parsedown.php',
  ];
  if ( isset( $map[$class] ) ) {
    require_once( REEVE_PATH . $map[$class] );
  }
} );

global $reeve_core;
$reeve_core = new REEVE_Core();

// Registered here rather than from the server class: on activation WordPress includes
// the plugin after plugins_loaded has already fired, so no instance exists to hook it.
// The OAuth discovery documents are cached, and their contents depend on the plugin
// being active, so an activation has to invalidate them.
register_activation_hook( REEVE_ENTRY, function () {
  require_once( REEVE_PATH . '/includes/oauth.php' );
  REEVE_OAuth::purge_discovery_cache();
} );
