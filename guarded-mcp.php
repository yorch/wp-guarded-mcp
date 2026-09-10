<?php

/*
Plugin Name: Guarded MCP
Plugin URI: https://github.com/yorch/wp-guarded-mcp
Description: Safe MCP server for Claude and any AI agent. Full site administration with guardrails: confirmed deletions, repository-only installs, no API keys.
Version: 1.0.0
Requires at least: 6.0
Requires PHP: 8.1
Author: Jorge Barnaby
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: guarded-mcp
*/

/*
Portions of this plugin are derived from AI Engine 3.7.7, Copyright (C) Jordy Meow,
distributed under GPLv2 or later via https://wordpress.org/plugins/ai-engine/. The MCP
transport, the OAuth module and the WordPress tool catalog originate there.

CREDITS.md, distributed alongside this file, carries the full attribution and the
statement of changes that GPLv2 sections 1 and 2(a) require. This notice is repeated
here so it travels with the code rather than only with the repository.
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
