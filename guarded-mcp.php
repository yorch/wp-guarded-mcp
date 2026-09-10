<?php

/*
Plugin Name: Guarded MCP
Plugin URI: https://github.com/yorch/guarded-mcp
Description: Safe MCP server for Claude and any AI agent. Full site administration with guardrails: confirmed deletions, repository-only installs, no API keys.
Version: 1.0.0
Requires at least: 6.0
Requires PHP: 8.1
Author: Jorge Barnaby
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: guarded-mcp
*/

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

define( 'GMCP_VERSION', '1.0.0' );
define( 'GMCP_PREFIX', 'guarded-mcp' );
define( 'GMCP_DOMAIN', 'guarded-mcp' );
define( 'GMCP_ENTRY', __FILE__ );
define( 'GMCP_PATH', dirname( __FILE__ ) );
define( 'GMCP_URL', plugin_dir_url( __FILE__ ) );

require_once( GMCP_PATH . '/includes/init.php' );
