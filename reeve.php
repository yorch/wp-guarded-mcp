<?php

/*
Plugin Name: Reeve
Plugin URI: https://github.com/yorch/reeve
Description: Safe MCP server for Claude and any AI agent. Full site administration with guardrails: confirmed deletions, repository-only installs, no API keys.
Version: 1.0.0
Requires at least: 6.0
Requires PHP: 8.1
Author: Jorge Barnaby
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: reeve
*/

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

define( 'REEVE_VERSION', '1.0.0' );
define( 'REEVE_PREFIX', 'reeve' );
define( 'REEVE_DOMAIN', 'reeve' );
define( 'REEVE_ENTRY', __FILE__ );
define( 'REEVE_PATH', dirname( __FILE__ ) );
define( 'REEVE_URL', plugin_dir_url( __FILE__ ) );

require_once( REEVE_PATH . '/includes/init.php' );
