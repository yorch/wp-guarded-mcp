<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Site administration tools: the things an administrator does in wp-admin that the
* content tools in tools-core.php do not cover.
*
* Kept in its own class rather than added to tools-core.php for two reasons. That file
* is derived from upstream and stays close to it, and the operations here are a
* different risk class: installing a plugin is remote code execution by design, so the
* guards live together where they can be reviewed as a set.
*/
class GMCP_Tools_Admin {

  private $core = null;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  public function rest_api_init() {
    add_filter( 'gmcp_tools', [ $this, 'register_tools' ] );
    add_filter( 'gmcp_callback', [ $this, 'handle_call' ], 10, 4 );
  }

  #region Guards

  /**
  * Whether this request may modify files on disk at all.
  *
  * Two independent gates, both of which a real site may have closed:
  *
  * DISALLOW_FILE_MODS is the site owner saying "nothing installs or updates itself
  * here", usually because deploys are managed by Composer or git. Honouring it is not
  * optional: wp-admin hides the whole UI when it is set, so an agent that ignored it
  * would be doing something the site owner has explicitly forbidden.
  *
  * The filesystem method matters because WP_Filesystem falls back to asking for FTP
  * or SSH credentials through an HTML form. There is no form here, so a non-direct
  * host does not prompt, it just fails somewhere deep inside the upgrader with an
  * unhelpful message. Checking up front turns that into a sentence the agent can act on.
  *
  * @return true|string True if allowed, otherwise the reason it is not.
  */
  private function file_mods_blocked( string $context = 'plugin' ) {
    if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
      return 'This site has DISALLOW_FILE_MODS enabled, so plugins and themes cannot be installed, updated or deleted. That is a deliberate setting in wp-config.php, usually because deployments are managed outside WordPress.';
    }
    if ( !function_exists( 'get_filesystem_method' ) ) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    // The directory matters. Called with no arguments this probes WP_CONTENT_DIR, but
    // WP_Upgrader::fs_connect() probes the plugins or themes root, and the "direct"
    // decision is made by comparing the owner of a probe file in THAT directory. On a
    // hardened layout where wp-content belongs to the deploy user and only
    // wp-content/plugins is writable by PHP, the two disagree and this guard refuses
    // every install on a site where wp-admin's own installer works fine.
    $directory = $context === 'theme' ? get_theme_root() : WP_PLUGIN_DIR;
    $method = get_filesystem_method( [], $directory );
    if ( $method !== 'direct' ) {
      return "This site's filesystem method is '{$method}', which needs FTP or SSH credentials that cannot be collected over an API call. Installing, updating and deleting must be done another way on this host.";
    }
    return true;
  }

  /**
  * Two-step confirmation for irreversible operations, using a token the server mints.
  *
  * The obvious designs do not work. A `confirm: true` flag is free to send and carries
  * no evidence of intent. Echoing back the target's own name is better, since it proves
  * the caller knew which target it named, but it is still information the caller already
  * has in the same call, so it is satisfied on the first attempt. Both stop a typo.
  * Neither stops the thing that actually matters here: this agent reads comments and post
  * content that anonymous people wrote, so "delete plugin X" can arrive as an instruction
  * smuggled into content, and a single injected tool call should not be able to complete
  * a deletion.
  *
  * A server-minted token fixes exactly that. The first call changes nothing and returns a
  * random token; only a second call carrying it proceeds. An injected instruction cannot
  * predict the token, so it cannot complete in one shot, and the refusal message with its
  * preview passes through the transcript where a person can see what was about to happen.
  *
  * The token is keyed to the tool AND the normalized arguments, so a token minted to
  * delete one plugin cannot be replayed to delete another. It is single use and expires
  * in two minutes.
  *
  * @return true|string True to proceed, otherwise the message to return.
  */
  private function confirmed( string $tool, $target, array $a, string $summary ) {
    // Keyed on the RESOLVED target, not on the raw arguments. A caller may name a
    // plugin as "akismet" or "akismet/akismet.php"; both resolve to the same file, so
    // both must share one token, and the token has to be bound to what will actually
    // be deleted rather than to how it happened to be spelled.
    $key = 'gmcp_confirm_' . hash( 'sha256', $tool . '|' . (string) $target );

    $given = isset( $a['confirm'] ) ? (string) $a['confirm'] : '';
    $expected = get_transient( $key );

    if ( $given !== '' && is_string( $expected ) && hash_equals( $expected, $given ) ) {
      delete_transient( $key ); // Single use: a replay has to be confirmed again.
      return true;
    }

    $token = bin2hex( random_bytes( 8 ) );
    set_transient( $key, $token, 2 * MINUTE_IN_SECONDS );
    return $summary . " Nothing has been changed. To go ahead, call this tool again within two minutes with the same arguments plus confirm set to \"{$token}\".";
  }

  /**
  * The plugin file of this plugin, e.g. "guarded-mcp/guarded-mcp.php".
  *
  * Deactivating or deleting ourselves would tear down the endpoint handling the very
  * call that asked for it: the agent gets a dropped connection rather than a result,
  * and then has no way back in to undo it. Refuse instead.
  */
  private function self_plugin_file(): string {
    return plugin_basename( GMCP_ENTRY );
  }

  /**
  * Capability check.
  *
  * Worth doing even though a bearer-token request already runs as an administrator.
  * The OAuth path binds to a real user, the gmcp_allow filter lets a site widen who
  * gets in, and WordPress itself revokes some of these capabilities on multisite and
  * under DISALLOW_FILE_MODS. Asking WordPress the question is cheap and keeps the
  * answer correct in all of those cases instead of only the common one.
  *
  * @return true|string True if permitted, otherwise the message to return.
  */
  private function may( string $capability, string $action ) {
    if ( current_user_can( $capability ) ) {
      return true;
    }
    return "Not permitted: {$action} requires the '{$capability}' capability, which the authenticated account does not have.";
  }

  /**
  * A refusal the model is supposed to read and act on.
  *
  * Returned as a tool-level isError result, not a JSON-RPC error. The distinction is
  * not pedantic here: clients treat a protocol error as a broken server and may not
  * surface its text at all, while an isError result is handed to the model as the
  * tool's answer. Every refusal in this file depends on being read, and the two-step
  * confirmation depends on it completely, because the token only ever arrives inside
  * a refusal.
  *
  * server.php's own catch block documents the same rule for tools that throw; this
  * keeps the deliberate refusals consistent with the accidental ones.
  */
  private function error( array $r, string $message, int $code = -32603 ): array {
    // -32601 is "method not found", a genuine protocol-level condition, so that one
    // stays a real JSON-RPC error. Everything else is an outcome.
    if ( $code === -32601 ) {
      unset( $r['result'] );
      $r['error'] = [ 'code' => $code, 'message' => $message ];
      return $r;
    }
    $r['result'] = [
      'content' => [ [ 'type' => 'text', 'text' => $message ] ],
      'isError' => true,
    ];
    unset( $r['error'] );
    return $r;
  }

  private function text( array $r, string $message ): array {
    $r['result']['content'][] = [ 'type' => 'text', 'text' => $message ];
    return $r;
  }

  private function json( array $r, $data ): array {
    return $this->text( $r, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
  }

  #endregion

  #region Upgrader

  /**
  * Load the upgrader classes. wp-admin is not loaded during a REST request, so every
  * one of these has to be pulled in explicitly.
  */
  private function load_upgrader(): void {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
  }

  /**
  * A skin that collects messages instead of printing them.
  *
  * The default upgrader skins echo HTML straight out, which in a JSON-RPC response
  * means markup lands in the middle of the body and the client cannot parse it.
  * WP_Ajax_Upgrader_Skin exists for exactly this and buffers into a WP_Error instead.
  */
  private function skin(): WP_Ajax_Upgrader_Skin {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    return new WP_Ajax_Upgrader_Skin();
  }

  /**
  * Resolve what to install into a download URL.
  *
  * Only wordpress.org slugs are accepted by default. An arbitrary ZIP URL is a direct
  * path from "the model was talked into a URL" to code running on the site, and unlike
  * the .org repository nothing has reviewed what is inside it. Sites that genuinely
  * need it can open the door with the gmcp_allow_remote_install filter, which also
  * gives them somewhere to allowlist their own hosts.
  *
  * @return string|WP_Error The download URL, or an error explaining the refusal.
  */
  private function resolve_package( string $type, array $a ) {
    $slug = isset( $a['slug'] ) ? sanitize_key( $a['slug'] ) : '';
    $url = isset( $a['url'] ) ? trim( (string) $a['url'] ) : '';

    if ( $url !== '' ) {
      $allowed = apply_filters( 'gmcp_allow_remote_install', false, $url, $type );
      if ( !$allowed ) {
        return new WP_Error(
          'gmcp_remote_install_blocked',
          'Installing from an arbitrary URL is disabled, because it runs unreviewed code on this site. Use the wordpress.org "slug" argument instead. A site that needs URL installs can enable them with the gmcp_allow_remote_install filter.'
        );
      }
      if ( !wp_http_validate_url( $url ) ) {
        return new WP_Error( 'gmcp_bad_url', 'That URL is not a valid, externally reachable HTTP(S) URL.' );
      }
      return $url;
    }

    if ( $slug === '' ) {
      return new WP_Error( 'gmcp_no_package', 'Provide a wordpress.org "slug" (for example "classic-editor").' );
    }

    $this->load_upgrader();
    if ( $type === 'plugin' ) {
      $info = plugins_api( 'plugin_information', [
        'slug' => $slug,
        'fields' => [ 'sections' => false, 'short_description' => true ],
      ] );
    }
    else {
      $info = themes_api( 'theme_information', [ 'slug' => $slug ] );
    }

    if ( is_wp_error( $info ) ) {
      return new WP_Error(
        'gmcp_not_found',
        "No {$type} with the slug \"{$slug}\" was found on wordpress.org (" . $info->get_error_message() . ')'
      );
    }
    $link = is_object( $info ) ? ( $info->download_link ?? '' ) : ( $info['download_link'] ?? '' );
    if ( empty( $link ) ) {
      return new WP_Error( 'gmcp_no_package', "wordpress.org returned no download for \"{$slug}\"." );
    }
    // Asking wordpress.org is not the same as being answered by wordpress.org. Both
    // plugins_api() and themes_api() run filters (plugins_api, plugins_api_result and
    // their theme equivalents) that any installed plugin can use to rewrite
    // download_link to any host. Mirror and private-repository plugins do this
    // legitimately, which is exactly why it is reachable: a plugin installed one call
    // earlier could redirect the next install to a package of its choosing. Check the
    // host of the answer, not just the provenance of the question.
    $host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
    if ( $host !== 'wordpress.org' && substr( $host, -14 ) !== '.wordpress.org' ) {
      return new WP_Error(
        'gmcp_not_dot_org',
        "The download for \"{$slug}\" resolved to \"{$host}\", which is not a wordpress.org host. Something on this site is rewriting the repository response, so the package was not installed."
      );
    }
    return $link;
  }

  /**
  * Turn whatever the upgrader reported into one readable sentence.
  *
  * The upgrader signals failure in three different ways depending on where it broke:
  * a WP_Error return, a false return with the detail only on the skin, or a null
  * return when the package could not be read at all. Collapsing them here keeps every
  * caller from having to know that.
  */
  private function upgrader_error( $result, WP_Ajax_Upgrader_Skin $skin, string $fallback ): string {
    if ( is_wp_error( $result ) ) {
      return $result->get_error_message();
    }
    if ( $skin->get_errors() instanceof WP_Error && $skin->get_errors()->has_errors() ) {
      return $skin->get_errors()->get_error_message();
    }
    $messages = $skin->get_upgrade_messages();
    if ( !empty( $messages ) ) {
      return (string) end( $messages );
    }
    return $fallback;
  }

  #endregion

  #region Tool definitions

  private function tools(): array {
    return [

      /* -------- Plugins -------- */
      'wp_install_plugin' => [
        'name' => 'wp_install_plugin',
        'description' => 'Install a plugin from the wordpress.org repository by slug, optionally activating it. Installing from an arbitrary URL is disabled unless the site opts in. Returns the installed plugin file, which is what the activate/deactivate/delete tools take.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string', 'description' => 'wordpress.org slug, e.g. "classic-editor".' ],
            'url' => [ 'type' => 'string', 'description' => 'Direct ZIP URL. Refused unless the site enables URL installs.' ],
            'activate' => [ 'type' => 'boolean', 'description' => 'Activate immediately after install. Default false.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_activate_plugin' => [
        'name' => 'wp_activate_plugin',
        'description' => 'Activate an installed plugin. Takes the plugin file as listed by wp_list_plugins, e.g. "classic-editor/classic-editor.php".',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'plugin' => [ 'type' => 'string' ],
            'network_wide' => [ 'type' => 'boolean', 'description' => 'Multisite only. Activate across the network.' ],
          ],
          'required' => [ 'plugin' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_deactivate_plugin' => [
        'name' => 'wp_deactivate_plugin',
        'description' => 'Deactivate an active plugin. Refuses to deactivate the MCP plugin itself, which would end this connection.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'plugin' => [ 'type' => 'string' ] ],
          'required' => [ 'plugin' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_delete_plugin' => [
        'name' => 'wp_delete_plugin',
        'description' => 'Permanently delete an installed plugin from disk. Two steps: call it once to get a confirmation token, then call again with that token. The plugin must be inactive first.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'plugin' => [ 'type' => 'string' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token. Call without it first; the response supplies the token.' ],
          ],
          'required' => [ 'plugin' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_update_plugin' => [
        'name' => 'wp_update_plugin',
        'description' => 'Update an installed plugin to the latest wordpress.org version. Checks for available updates first and reports when none is pending.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'plugin' => [ 'type' => 'string' ] ],
          'required' => [ 'plugin' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Themes -------- */
      'wp_list_themes' => [
        'name' => 'wp_list_themes',
        'description' => 'List installed themes with their stylesheet directory, name, version, and which one is active.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],
      'wp_install_theme' => [
        'name' => 'wp_install_theme',
        'description' => 'Install a theme from the wordpress.org repository by slug, optionally activating it. Installing from an arbitrary URL is disabled unless the site opts in.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'slug' => [ 'type' => 'string' ],
            'url' => [ 'type' => 'string' ],
            'activate' => [ 'type' => 'boolean' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_activate_theme' => [
        'name' => 'wp_activate_theme',
        'description' => 'Switch the active theme. Takes the stylesheet directory as listed by wp_list_themes. Refuses a theme with a broken or missing stylesheet, which would leave the site unrenderable.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'stylesheet' => [ 'type' => 'string' ] ],
          'required' => [ 'stylesheet' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_delete_theme' => [
        'name' => 'wp_delete_theme',
        'description' => 'Permanently delete an installed theme from disk. Two steps: call it once to get a confirmation token, then call again with that token. Refuses the active theme and its parent.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'stylesheet' => [ 'type' => 'string' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token. Call without it first; the response supplies the token.' ],
          ],
          'required' => [ 'stylesheet' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_update_theme' => [
        'name' => 'wp_update_theme',
        'description' => 'Update an installed theme to the latest wordpress.org version.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'stylesheet' => [ 'type' => 'string' ] ],
          'required' => [ 'stylesheet' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Settings -------- */
      'wp_get_settings' => [
        'name' => 'wp_get_settings',
        'description' => 'Read the site settings that wp-admin exposes under General, Reading and Discussion. Pass a group to narrow it. Settings that could make the site or this API unreachable are not included.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'group' => [ 'type' => 'string', 'description' => 'general, reading or discussion. Omit for all three.' ],
          ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_update_settings' => [
        'name' => 'wp_update_settings',
        'description' => 'Change site settings. Takes an object of setting name to value; call wp_get_settings first to see what is available. Changing the administration email starts WordPress\'s own confirmation flow, so it only takes effect once someone clicks the link sent to the new address.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'settings' => [ 'type' => 'object', 'description' => 'Setting name to new value.' ],
          ],
          'required' => [ 'settings' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Permalinks -------- */
      'wp_get_permalink_structure' => [
        'name' => 'wp_get_permalink_structure',
        'description' => 'Get the current permalink structure and the category and tag base prefixes.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],
      'wp_set_permalink_structure' => [
        'name' => 'wp_set_permalink_structure',
        'description' => 'Set the permalink structure and flush the rewrite rules. Use an empty string for plain permalinks. A structure with no %postname% or %post_id% cannot produce unique URLs and is refused.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'structure' => [ 'type' => 'string', 'description' => 'For example /%postname%/ . Empty string for plain.' ],
            'category_base' => [ 'type' => 'string' ],
            'tag_base' => [ 'type' => 'string' ],
          ],
          'required' => [ 'structure' ],
        ],
        'accessLevel' => 'admin',
      ],


      /* -------- Orientation -------- */
      'wp_site_briefing' => [
        'name' => 'wp_site_briefing',
        'description' => 'Everything needed to orient on this site in one call: WordPress and PHP versions, the active theme, active plugins, post types with counts, taxonomies, the permalink structure, the front page setup, comment counts awaiting moderation, and how recently content changed. Call this first in a conversation instead of making a dozen separate queries.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],

      'wp_get_audit_log' => [
        'name' => 'wp_get_audit_log',
        'description' => 'Read this API\'s own audit log: every tool call made through it, including the refused ones, with the arguments each was given and why it was turned down. Useful for answering "what did I change last week" or "what has been refused and why". Read only; nothing here can prune or clear the log.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'limit' => [ 'type' => 'integer', 'description' => 'Default 50, maximum 500.' ],
            'offset' => [ 'type' => 'integer' ],
            'tool' => [ 'type' => 'string', 'description' => 'Only calls to this tool.' ],
            'outcome' => [ 'type' => 'string', 'description' => 'ok or refused.' ],
            'since' => [ 'type' => 'string', 'description' => 'GMT datetime, e.g. 2026-09-01 00:00:00.' ],
            'until' => [ 'type' => 'string' ],
            'search' => [ 'type' => 'string', 'description' => 'Matches the target, the arguments and the refusal message.' ],
          ],
        ],
        'accessLevel' => 'admin',
      ],
      /* -------- Site health -------- */
      'wp_get_site_health' => [
        'name' => 'wp_get_site_health',
        'description' => 'Run the Site Health checks that can run in-process and report which pass, which are recommended improvements, and which are critical. Also returns the environment summary (versions, memory limit, active theme and plugin counts).',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],

      /* -------- Menus -------- */
      'wp_list_menus' => [
        'name' => 'wp_list_menus',
        'description' => 'List navigation menus with their item counts, plus the theme\'s menu locations and which menu is assigned to each.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],
      'wp_get_menu_items' => [
        'name' => 'wp_get_menu_items',
        'description' => 'List the items in one menu, in order, with their ids, parents and targets.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'menu' => [ 'type' => 'string', 'description' => 'Menu id, slug or name.' ] ],
          'required' => [ 'menu' ],
        ],
        'accessLevel' => 'read',
      ],
      'wp_create_menu' => [
        'name' => 'wp_create_menu',
        'description' => 'Create an empty navigation menu. Optionally assign it to one or more theme locations at the same time.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'name' => [ 'type' => 'string' ],
            'locations' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Theme location slugs from wp_list_menus.' ],
          ],
          'required' => [ 'name' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_delete_menu' => [
        'name' => 'wp_delete_menu',
        'description' => 'Delete a navigation menu and every item in it. Two steps: call once to get a confirmation token, then call again with it.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'menu' => [ 'type' => 'string' ],
            'confirm' => [ 'type' => 'string', 'description' => 'Confirmation token. Call without it first; the response supplies the token.' ],
          ],
          'required' => [ 'menu' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_add_menu_item' => [
        'name' => 'wp_add_menu_item',
        'description' => 'Add an item to a menu. Use type "post_type" with object_id for a page or post, "taxonomy" with object_id for a category or tag, or "custom" with url. Returns the new item id.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'menu' => [ 'type' => 'string' ],
            'title' => [ 'type' => 'string' ],
            'type' => [ 'type' => 'string', 'description' => 'post_type, taxonomy or custom. Default custom.' ],
            'object_id' => [ 'type' => 'integer', 'description' => 'Post or term id, for post_type and taxonomy items.' ],
            'object' => [ 'type' => 'string', 'description' => 'Post type or taxonomy name. Inferred from object_id when omitted.' ],
            'url' => [ 'type' => 'string', 'description' => 'For custom items.' ],
            'parent_id' => [ 'type' => 'integer', 'description' => 'Menu item id to nest under.' ],
            'position' => [ 'type' => 'integer' ],
          ],
          'required' => [ 'menu' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_delete_menu_item' => [
        'name' => 'wp_delete_menu_item',
        'description' => 'Remove one item from a menu. Its children are moved up to the item\'s own parent rather than being orphaned.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'item_id' => [ 'type' => 'integer' ] ],
          'required' => [ 'item_id' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_assign_menu_location' => [
        'name' => 'wp_assign_menu_location',
        'description' => 'Assign a menu to a theme location, or clear a location by passing an empty menu. Locations belong to the active theme, so switching theme clears them.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'location' => [ 'type' => 'string' ],
            'menu' => [ 'type' => 'string', 'description' => 'Menu id, slug or name. Empty string clears the location.' ],
          ],
          'required' => [ 'location' ],
        ],
        'accessLevel' => 'admin',
      ],

      /* -------- Widgets -------- */
      'wp_list_sidebars' => [
        'name' => 'wp_list_sidebars',
        'description' => 'List the theme\'s widget areas and the widgets in each. Block themes register no widget areas at all, in which case this says so.',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'accessLevel' => 'read',
      ],
      'wp_add_widget' => [
        'name' => 'wp_add_widget',
        'description' => 'Add a widget to a widget area. With "content" it adds a block widget holding that block markup; with "id_base" and "settings" it adds a classic widget of that type. Returns the new widget id.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [
            'sidebar' => [ 'type' => 'string', 'description' => 'Widget area id from wp_list_sidebars.' ],
            'content' => [ 'type' => 'string', 'description' => 'Block markup for a block widget.' ],
            'id_base' => [ 'type' => 'string', 'description' => 'Classic widget type, e.g. "text" or "search".' ],
            'settings' => [ 'type' => 'object', 'description' => 'Instance settings for a classic widget.' ],
            'position' => [ 'type' => 'integer', 'description' => 'Index within the area. Appends when omitted.' ],
          ],
          'required' => [ 'sidebar' ],
        ],
        'accessLevel' => 'admin',
      ],
      'wp_delete_widget' => [
        'name' => 'wp_delete_widget',
        'description' => 'Remove a widget from its area and delete its stored settings.',
        'inputSchema' => [
          'type' => 'object',
          'properties' => [ 'widget_id' => [ 'type' => 'string', 'description' => 'For example block-3 or text-2.' ] ],
          'required' => [ 'widget_id' ],
        ],
        'accessLevel' => 'admin',
      ],
    ];
  }

  #endregion

  #region Registration

  public function register_tools( array $prev ): array {
    $tools = $this->tools();
    foreach ( $tools as &$tool ) {
      $tool['category'] = 'WordPress Admin';
      $name = $tool['name'];
      $readonly = strpos( $name, 'wp_list_' ) === 0 || strpos( $name, 'wp_get_' ) === 0;
      $tool['annotations'] = [
        'readOnlyHint' => $readonly,
        'destructiveHint' => strpos( $name, 'wp_delete_' ) === 0,
        'openWorldHint' => strpos( $name, 'wp_install_' ) === 0 || strpos( $name, 'wp_update_' ) === 0,
      ];
    }
    unset( $tool );
    return array_merge( $prev, array_values( $tools ) );
  }

  public function handle_call( $prev, string $tool, array $args, ?int $id ) {
    if ( $prev !== null ) {
      return $prev;
    }
    if ( !isset( $this->tools()[ $tool ] ) ) {
      return $prev;
    }
    return $this->dispatch( $tool, $args, $id );
  }

  #endregion

  #region Dispatcher

  private function dispatch( string $tool, array $a, ?int $id ): array {
    $result = $this->run( $tool, $a, $id );
    $failed = !empty( $result['error'] ) || !empty( $result['result']['isError'] );
    if ( !$failed && $this->is_mutating_tool( $tool ) ) {
      do_action( 'gmcp_mutate', $tool, $a, $result );
    }
    return $result;
  }

  private function run( string $tool, array $a, ?int $id ): array {
    $r = [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'content' => [] ] ];

    switch ( $tool ) {

      case 'wp_install_plugin':
        return $this->install_plugin( $r, $a );

      case 'wp_activate_plugin':
        return $this->activate_plugin_tool( $r, $a );

      case 'wp_deactivate_plugin':
        return $this->deactivate_plugin_tool( $r, $a );

      case 'wp_delete_plugin':
        return $this->delete_plugin_tool( $r, $a );

      case 'wp_update_plugin':
        return $this->update_plugin_tool( $r, $a );

      case 'wp_list_themes':
        return $this->list_themes( $r );

      case 'wp_install_theme':
        return $this->install_theme( $r, $a );

      case 'wp_activate_theme':
        return $this->activate_theme_tool( $r, $a );

      case 'wp_delete_theme':
        return $this->delete_theme_tool( $r, $a );

      case 'wp_update_theme':
        return $this->update_theme_tool( $r, $a );

      case 'wp_get_settings':
        return $this->read_settings( $r, $a );

      case 'wp_update_settings':
        return $this->write_settings( $r, $a );

      case 'wp_get_permalink_structure':
        return $this->read_permalinks( $r );

      case 'wp_set_permalink_structure':
        return $this->write_permalinks( $r, $a );

      case 'wp_get_site_health':
        return $this->site_health( $r );

      case 'wp_get_audit_log':
        return $this->audit_log( $a, $r );

      case 'wp_site_briefing':
        return $this->site_briefing( $r );

      case 'wp_list_menus':
        return $this->list_menus( $r );

      case 'wp_get_menu_items':
        return $this->get_menu_items( $r, $a );

      case 'wp_create_menu':
        return $this->create_menu( $r, $a );

      case 'wp_delete_menu':
        return $this->delete_menu( $r, $a );

      case 'wp_add_menu_item':
        return $this->add_menu_item( $r, $a );

      case 'wp_delete_menu_item':
        return $this->delete_menu_item( $r, $a );

      case 'wp_assign_menu_location':
        return $this->assign_menu_location( $r, $a );

      case 'wp_list_sidebars':
        return $this->list_sidebars( $r );

      case 'wp_add_widget':
        return $this->add_widget( $r, $a );

      case 'wp_delete_widget':
        return $this->delete_widget( $r, $a );
    }

    return $this->error( $r, 'Unknown tool', -32601 );
  }

  /**
  * Tools here that change site state. gmcp_mutate is fired for these so cache
  * integrations hear about them, exactly as they do for content changes.
  *
  * Listed as the read-only exceptions rather than the mutating ones, matching
  * tools-core.php: a tool added later is then covered by default, and the failure mode
  * of getting it wrong is a needless cache purge rather than a stale site.
  */
  private const READ_ONLY_TOOLS = [
    'wp_list_themes', 'wp_get_settings', 'wp_get_permalink_structure', 'wp_get_site_health',
    'wp_list_menus', 'wp_get_menu_items', 'wp_list_sidebars', 'wp_site_briefing',
    'wp_get_audit_log',
  ];

  private function is_mutating_tool( string $tool ): bool {
    return !in_array( $tool, self::READ_ONLY_TOOLS, true );
  }

  #endregion

  #region Plugins

  private function install_plugin( array $r, array $a ): array {
    $blocked = $this->file_mods_blocked();
    if ( $blocked !== true ) {
      return $this->error( $r, $blocked );
    }
    $may = $this->may( 'install_plugins', 'installing a plugin' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }

    // Same as themes: an already-installed plugin is a no-op, not a failure.
    $this->load_upgrader();
    $slug = isset( $a['slug'] ) ? sanitize_key( $a['slug'] ) : '';
    if ( $slug !== '' ) {
      $existing = $this->normalize_plugin_file( $slug );
      if ( $existing !== null ) {
        $out = [ 'installed' => $existing, 'already_installed' => true, 'activated' => is_plugin_active( $existing ) ];
        if ( !empty( $a['activate'] ) && !$out['activated'] ) {
          $activation = $this->activate_and_verify( $existing );
          $out['activated'] = $activation['active'];
          if ( $activation['error'] ) {
            $out['activation_error'] = $activation['error'];
          }
        }
        return $this->json( $r, $out );
      }
    }

    $package = $this->resolve_package( 'plugin', $a );
    if ( is_wp_error( $package ) ) {
      return $this->error( $r, $package->get_error_message() );
    }

    $skin = $this->skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result = $upgrader->install( $package );

    if ( $result !== true ) {
      return $this->error( $r, 'Install failed: ' . $this->upgrader_error( $result, $skin, 'the package could not be installed.' ) );
    }

    // plugin_info() reads the freshly extracted directory, which is the only reliable
    // way to learn the plugin file: the .org slug is a directory name, not a basename,
    // and plenty of plugins do not match the two up.
    $plugin_file = $upgrader->plugin_info();
    if ( empty( $plugin_file ) ) {
      return $this->text( $r, 'The plugin was installed, but its main file could not be determined. Use wp_list_plugins to find it.' );
    }

    $out = [ 'installed' => $plugin_file, 'activated' => false ];

    if ( !empty( $a['activate'] ) ) {
      $activation = $this->activate_and_verify( $plugin_file );
      $out['activated'] = $activation['active'];
      if ( $activation['error'] ) {
        $out['activation_error'] = $activation['error'];
      }
      if ( $activation['warning'] ) {
        $out['activation_warning'] = $activation['warning'];
      }
    }
    return $this->json( $r, $out );
  }

  private function activate_plugin_tool( array $r, array $a ): array {
    $may = $this->may( 'activate_plugins', 'activating a plugin' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $this->load_upgrader();

    $plugin = $this->normalize_plugin_file( (string) $a['plugin'] );
    if ( $plugin === null ) {
      return $this->error( $r, "No installed plugin matches \"{$a['plugin']}\". Use wp_list_plugins to see the exact plugin file." );
    }
    if ( is_plugin_active( $plugin ) ) {
      return $this->text( $r, "\"{$plugin}\" is already active." );
    }

    $network_wide = !empty( $a['network_wide'] ) && is_multisite();

    $activation = $this->activate_and_verify( $plugin, $network_wide );
    if ( !$activation['active'] ) {
      return $this->error( $r, 'Activation failed: ' . $activation['error'] );
    }
    $message = "Activated \"{$plugin}\"" . ( $network_wide ? ' network-wide.' : '.' );
    if ( $activation['warning'] ) {
      $message .= ' ' . $activation['warning'];
    }
    return $this->text( $r, $message );
  }

  private function deactivate_plugin_tool( array $r, array $a ): array {
    $may = $this->may( 'activate_plugins', 'deactivating a plugin' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $this->load_upgrader();

    $plugin = $this->normalize_plugin_file( (string) $a['plugin'] );
    if ( $plugin === null ) {
      return $this->error( $r, "No installed plugin matches \"{$a['plugin']}\"." );
    }
    if ( $plugin === $this->self_plugin_file() ) {
      return $this->error( $r, 'Refusing to deactivate the MCP plugin itself: that would close this connection mid-call and leave no way to undo it. Deactivate it from wp-admin if that is really what you want.' );
    }
    if ( !is_plugin_active( $plugin ) && !is_plugin_active_for_network( $plugin ) ) {
      return $this->text( $r, "\"{$plugin}\" is already inactive." );
    }
    deactivate_plugins( [ $plugin ] );
    return $this->text( $r, "Deactivated \"{$plugin}\"." );
  }

  private function delete_plugin_tool( array $r, array $a ): array {
    $blocked = $this->file_mods_blocked();
    if ( $blocked !== true ) {
      return $this->error( $r, $blocked );
    }
    $may = $this->may( 'delete_plugins', 'deleting a plugin' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $this->load_upgrader();

    $plugin = $this->normalize_plugin_file( (string) $a['plugin'] );
    if ( $plugin === null ) {
      return $this->error( $r, "No installed plugin matches \"{$a['plugin']}\"." );
    }
    if ( $plugin === $this->self_plugin_file() ) {
      return $this->error( $r, 'Refusing to delete the MCP plugin itself.' );
    }
    if ( is_plugin_active( $plugin ) || is_plugin_active_for_network( $plugin ) ) {
      return $this->error( $r, "\"{$plugin}\" is active. Deactivate it first with wp_deactivate_plugin." );
    }
    $ok = $this->confirmed( 'wp_delete_plugin', $plugin, $a, "This permanently deletes the plugin \"{$plugin}\" and all of its files from disk." );
    if ( $ok !== true ) {
      return $this->error( $r, $ok );
    }

    $result = delete_plugins( [ $plugin ] );
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Delete failed: ' . $result->get_error_message() );
    }
    if ( $result === false ) {
      return $this->error( $r, 'Delete failed: WordPress could not write to the plugins directory.' );
    }
    return $this->text( $r, "Deleted \"{$plugin}\"." );
  }

  private function update_plugin_tool( array $r, array $a ): array {
    $blocked = $this->file_mods_blocked();
    if ( $blocked !== true ) {
      return $this->error( $r, $blocked );
    }
    $may = $this->may( 'update_plugins', 'updating a plugin' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $this->load_upgrader();

    $plugin = $this->normalize_plugin_file( (string) $a['plugin'] );
    if ( $plugin === null ) {
      return $this->error( $r, "No installed plugin matches \"{$a['plugin']}\"." );
    }

    // The update transient is what the upgrader consults. It can be stale or empty on
    // a site that has not run its update cron, in which case the upgrader reports a
    // confusing "no update available" for a plugin that does have one. Refresh first.
    wp_update_plugins();
    $updates = get_site_transient( 'update_plugins' );
    if ( empty( $updates->response[ $plugin ] ) ) {
      return $this->text( $r, "\"{$plugin}\" is already up to date." );
    }

    $skin = $this->skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result = $upgrader->upgrade( $plugin );

    if ( $result !== true ) {
      return $this->error( $r, 'Update failed: ' . $this->upgrader_error( $result, $skin, 'the update could not be applied.' ) );
    }
    $new = $updates->response[ $plugin ]->new_version ?? '';
    return $this->text( $r, "Updated \"{$plugin}\"" . ( $new ? " to {$new}." : '.' ) );
  }

  /**
  * Activate a plugin and report what actually happened, not what the return value said.
  *
  * activate_plugin() is not a clean success/failure signal. Read wp-admin/includes/plugin.php:
  * it writes active_plugins and fires activated_plugin, and only THEN checks whether the
  * plugin printed anything, returning WP_Error('unexpected_output') if it did. So the most
  * common error it returns means the plugin is running fine and merely echoed a stray
  * newline. Reporting that as "activation failed" sends the agent off to fix a
  * non-problem, and worse, to retry an activation that already succeeded.
  *
  * The plugin is also included in THIS request, not a sandboxed one: plugin_sandbox_scrape()
  * is a plain include_once. What keeps a load-time fatal survivable is ordering, since
  * active_plugins is written after the include, so a crash leaves the plugin inactive.
  *
  * @return array{active:bool,warning:?string,error:?string}
  */
  private function activate_and_verify( string $plugin, bool $network_wide = false ): array {
    $result = activate_plugin( $plugin, '', $network_wide );

    // Observed state is the truth. Ask WordPress, do not infer from the return value.
    $active = $network_wide ? is_plugin_active_for_network( $plugin ) : is_plugin_active( $plugin );

    if ( !$active ) {
      $why = is_wp_error( $result ) ? $result->get_error_message() : 'the plugin did not become active.';
      return [ 'active' => false, 'warning' => null, 'error' => $why ];
    }

    $warning = null;
    if ( is_wp_error( $result ) && $result->get_error_code() === 'unexpected_output' ) {
      $warning = 'The plugin activated but printed output during activation, which can break pages that send headers later.';
    }

    $health = $this->front_end_healthy();
    if ( $health === false ) {
      deactivate_plugins( [ $plugin ], true );
      return [
        'active' => false,
        'warning' => null,
        'error' => 'The plugin activated but the site front end then returned a server error, so it was deactivated again. It is not compatible with this site as configured.',
      ];
    }

    return [ 'active' => true, 'warning' => $warning, 'error' => null ];
  }

  /**
  * Fetch the home page and say whether it is broken.
  *
  * A plugin that loads fine here can still fatal on the next request, because the hooks
  * that run on a front-end page load have not run yet. wp-admin catches this by
  * redirecting the browser, which re-requests the site; over an API there is no browser,
  * so make the second request ourselves.
  *
  * Returns null, not false, when the request could not be made at all. Plenty of hosts
  * block loopback requests, and treating "could not check" as "broken" would roll back
  * perfectly good activations on those hosts.
  *
  * @return bool|null True healthy, false broken, null could not tell.
  */
  private function front_end_healthy(): ?bool {
    $response = wp_remote_get( home_url( '/' ), [
      'timeout' => 10,
      'redirection' => 1,
      // Verification is off here, and unlike the self-test that is deliberate: this
      // request carries no credential. It fetches the public home page to ask one
      // question, "did activating that plugin just break the site", and a self-signed
      // certificate on a staging install would otherwise answer it with a WP_Error,
      // which this treats as "cannot tell" and so stops rolling back a plugin that
      // genuinely did break things. Nothing is disclosed by not verifying, and the
      // function that does send a secret verifies properly. See GMCP_SelfTest.
      'sslverify' => false,
      'headers' => [ 'Cache-Control' => 'no-cache' ],
    ] );
    if ( is_wp_error( $response ) ) {
      return null;
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code === 0 ) {
      return null;
    }
    return $code < 500;
  }

  /**
  * Accept either the plugin file ("akismet/akismet.php") or the bare directory slug
  * ("akismet"), because a model that installed by slug naturally reaches for the slug
  * afterwards. Returns the canonical plugin file, or null when nothing matches.
  */
  private function normalize_plugin_file( string $given ): ?string {
    $given = trim( $given );
    if ( $given === '' ) {
      return null;
    }
    $all = get_plugins();
    if ( isset( $all[ $given ] ) ) {
      return $given;
    }
    foreach ( array_keys( $all ) as $file ) {
      $dir = dirname( $file );
      // A single-file plugin at the plugins root has dirname "." , so without this a
      // caller passing "." would resolve to whichever such plugin sorts first.
      if ( $dir === '.' ) {
        continue;
      }
      if ( strtolower( $dir ) === strtolower( $given ) ) {
        return $file;
      }
    }
    return null;
  }

  #endregion

  #region Themes

  private function list_themes( array $r ): array {
    $active = get_stylesheet();
    $out = [];
    foreach ( wp_get_themes() as $stylesheet => $theme ) {
      $out[] = [
        'stylesheet' => $stylesheet,
        'name' => $theme->get( 'Name' ),
        'version' => $theme->get( 'Version' ),
        'parent' => $theme->parent() ? $theme->get_template() : null,
        'active' => $stylesheet === $active,
      ];
    }
    return $this->json( $r, $out );
  }

  private function install_theme( array $r, array $a ): array {
    $blocked = $this->file_mods_blocked( 'theme' );
    if ( $blocked !== true ) {
      return $this->error( $r, $blocked );
    }
    $may = $this->may( 'install_themes', 'installing a theme' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }

    // Already present is not a failure. The upgrader reports "destination folder
    // already exists", which reads as an error and stops an agent that only wanted the
    // theme active. Treat it as the no-op it is and honour the activate flag.
    $slug = isset( $a['slug'] ) ? sanitize_key( $a['slug'] ) : '';
    if ( $slug !== '' && wp_get_theme( $slug )->exists() ) {
      $out = [ 'installed' => $slug, 'already_installed' => true, 'activated' => false ];
      if ( !empty( $a['activate'] ) ) {
        $switch = $this->switch_to_theme( $slug );
        if ( $switch !== true ) {
          $out['activation_error'] = $switch;
        }
        else {
          $out['activated'] = true;
        }
      }
      return $this->json( $r, $out );
    }

    $package = $this->resolve_package( 'theme', $a );
    if ( is_wp_error( $package ) ) {
      return $this->error( $r, $package->get_error_message() );
    }

    $this->load_upgrader();
    $skin = $this->skin();
    $upgrader = new Theme_Upgrader( $skin );
    $result = $upgrader->install( $package );

    if ( $result !== true ) {
      return $this->error( $r, 'Install failed: ' . $this->upgrader_error( $result, $skin, 'the package could not be installed.' ) );
    }

    $stylesheet = $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : '';
    $out = [ 'installed' => $stylesheet, 'activated' => false ];

    if ( !empty( $a['activate'] ) && $stylesheet !== '' ) {
      $switch = $this->switch_to_theme( $stylesheet );
      if ( $switch !== true ) {
        $out['activation_error'] = $switch;
      }
      else {
        $out['activated'] = true;
      }
    }
    return $this->json( $r, $out );
  }

  private function activate_theme_tool( array $r, array $a ): array {
    $may = $this->may( 'switch_themes', 'switching the theme' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $stylesheet = trim( (string) $a['stylesheet'] );
    if ( $stylesheet === get_stylesheet() ) {
      return $this->text( $r, "\"{$stylesheet}\" is already the active theme." );
    }
    $switch = $this->switch_to_theme( $stylesheet );
    if ( $switch !== true ) {
      return $this->error( $r, $switch );
    }
    // Menu locations live in theme_mods_{stylesheet} and widgets in sidebars_widgets,
    // both keyed to the theme. Switching does not migrate them, so a site can come back
    // with no navigation and empty sidebars while every tool here reported success.
    return $this->text( $r, "Switched the active theme to \"{$stylesheet}\". Menu locations and widget areas are stored per theme, so navigation and sidebars will need reassigning for this theme." );
  }

  /**
  * switch_theme() does no validation at all: hand it a name that is not installed and
  * it will set the option anyway, leaving a site that renders nothing. WP_Theme's own
  * errors() check is what wp-admin uses before switching, so use the same gate.
  *
  * @return true|string
  */
  private function switch_to_theme( string $stylesheet ) {
    $theme = wp_get_theme( $stylesheet );
    if ( !$theme->exists() ) {
      return "No installed theme has the stylesheet \"{$stylesheet}\". Use wp_list_themes to see what is available.";
    }
    // Structural problems: missing stylesheet, missing or circular parent.
    $errors = $theme->errors();
    if ( $errors instanceof WP_Error ) {
      return "\"{$stylesheet}\" cannot be activated: " . $errors->get_error_message();
    }
    // Multisite: the network may not have enabled this theme for the site.
    if ( !$theme->is_allowed() ) {
      return "\"{$stylesheet}\" is not enabled for this site on the network.";
    }
    // The check that actually prevents an unrecoverable site. errors() says nothing
    // about "Requires PHP" or "Requires at least", so a theme needing a newer PHP than
    // this server runs activates cleanly and then parse-errors while WordPress includes
    // its functions.php. From that moment the front end, wp-admin and the REST API are
    // all dead, which means this tool cannot be called again to switch back.
    // Lives in wp-includes/theme.php, so it is always loaded, no require needed.
    $requirements = validate_theme_requirements( $stylesheet );
    if ( is_wp_error( $requirements ) ) {
      return "\"{$stylesheet}\" cannot be activated: " . $requirements->get_error_message();
    }
    switch_theme( $stylesheet );
    return true;
  }

  private function delete_theme_tool( array $r, array $a ): array {
    $blocked = $this->file_mods_blocked( 'theme' );
    if ( $blocked !== true ) {
      return $this->error( $r, $blocked );
    }
    $may = $this->may( 'delete_themes', 'deleting a theme' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $this->load_upgrader();

    $stylesheet = trim( (string) $a['stylesheet'] );
    $theme = wp_get_theme( $stylesheet );
    if ( !$theme->exists() ) {
      return $this->error( $r, "No installed theme has the stylesheet \"{$stylesheet}\"." );
    }
    if ( $stylesheet === get_stylesheet() ) {
      return $this->error( $r, 'Refusing to delete the active theme. Switch to another theme first with wp_activate_theme.' );
    }
    // A child theme keeps working only while its parent is installed, so deleting the
    // parent of the active theme breaks the site just as surely as deleting the theme.
    if ( $stylesheet === get_template() ) {
      return $this->error( $r, 'Refusing to delete "' . $stylesheet . '": it is the parent of the active theme.' );
    }
    $ok = $this->confirmed( 'wp_delete_theme', $stylesheet, $a, "This permanently deletes the theme \"{$stylesheet}\" and all of its files from disk." );
    if ( $ok !== true ) {
      return $this->error( $r, $ok );
    }

    $result = delete_theme( $stylesheet );
    if ( is_wp_error( $result ) ) {
      return $this->error( $r, 'Delete failed: ' . $result->get_error_message() );
    }
    if ( $result === false ) {
      return $this->error( $r, 'Delete failed: WordPress could not write to the themes directory.' );
    }
    return $this->text( $r, "Deleted the theme \"{$stylesheet}\"." );
  }

  private function update_theme_tool( array $r, array $a ): array {
    $blocked = $this->file_mods_blocked( 'theme' );
    if ( $blocked !== true ) {
      return $this->error( $r, $blocked );
    }
    $may = $this->may( 'update_themes', 'updating a theme' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $this->load_upgrader();

    $stylesheet = trim( (string) $a['stylesheet'] );
    if ( !wp_get_theme( $stylesheet )->exists() ) {
      return $this->error( $r, "No installed theme has the stylesheet \"{$stylesheet}\"." );
    }

    wp_update_themes();
    $updates = get_site_transient( 'update_themes' );
    if ( empty( $updates->response[ $stylesheet ] ) ) {
      return $this->text( $r, "\"{$stylesheet}\" is already up to date." );
    }

    $skin = $this->skin();
    $upgrader = new Theme_Upgrader( $skin );
    $result = $upgrader->upgrade( $stylesheet );

    if ( $result !== true ) {
      return $this->error( $r, 'Update failed: ' . $this->upgrader_error( $result, $skin, 'the update could not be applied.' ) );
    }
    $new = $updates->response[ $stylesheet ]['new_version'] ?? '';
    return $this->text( $r, "Updated \"{$stylesheet}\"" . ( $new ? " to {$new}." : '.' ) );
  }

  #endregion

  #region Settings

  /**
  * The settings an agent may change, grouped as wp-admin groups them, each with the
  * validator its value has to pass.
  *
  * An allowlist, not a blocklist. The options table is open-ended, holds whatever every
  * installed plugin decided to put there, and a blocklist would have to be right about
  * all of it forever. This list is small enough to reason about, and anything not on it
  * simply is not reachable through this tool.
  */
  private function settings_map(): array {
    return [
      'general' => [
        'blogname' => 'text',
        'blogdescription' => 'text',
        'timezone_string' => 'timezone',
        'gmt_offset' => 'float',
        'date_format' => 'text',
        'time_format' => 'text',
        'start_of_week' => 'int',
        'WPLANG' => 'text',
        'users_can_register' => 'bool',
        'default_role' => 'role',
      ],
      'reading' => [
        'show_on_front' => 'text',
        'page_on_front' => 'int',
        'page_for_posts' => 'int',
        'posts_per_page' => 'int',
        'posts_per_rss' => 'int',
        'rss_use_excerpt' => 'bool',
        'blog_public' => 'bool',
      ],
      'discussion' => [
        'default_comment_status' => 'text',
        'default_ping_status' => 'text',
        'default_pingback_flag' => 'bool',
        'require_name_email' => 'bool',
        'comment_registration' => 'bool',
        'close_comments_for_old_posts' => 'bool',
        'close_comments_days_old' => 'int',
        'thread_comments' => 'bool',
        'thread_comments_depth' => 'int',
        'page_comments' => 'bool',
        'comments_per_page' => 'int',
        'default_comments_page' => 'text',
        'comment_order' => 'text',
        'comments_notify' => 'bool',
        'moderation_notify' => 'bool',
        'comment_moderation' => 'bool',
        'comment_previously_approved' => 'bool',
        'comment_max_links' => 'int',
        'moderation_keys' => 'textarea',
        'disallowed_keys' => 'textarea',
        'show_avatars' => 'bool',
        'avatar_rating' => 'text',
        'avatar_default' => 'text',
      ],
    ];
  }

  /**
  * Settings deliberately not reachable, and why. Returned to the caller so an agent
  * that asks for one is told the reason rather than just "unknown setting".
  */
  private function refused_settings(): array {
    return [
      'siteurl' => 'Changing the site URL rewrites every generated URL including this API endpoint, and a wrong value locks wp-admin too, so there would be no way to undo it from here.',
      'home' => 'Same as siteurl: a wrong value makes the site and this endpoint unreachable with no recovery path through the API.',
      'admin_email' => 'Set "new_admin_email" instead. WordPress emails a confirmation link to the new address and only completes the change when someone clicks it. Writing admin_email directly skips that and silently repoints password recovery.',
    ];
  }

  private function read_settings( array $r, array $a ): array {
    $group = isset( $a['group'] ) ? strtolower( trim( (string) $a['group'] ) ) : '';
    $map = $this->settings_map();
    if ( $group !== '' && !isset( $map[ $group ] ) ) {
      return $this->error( $r, "Unknown group \"{$group}\". Use general, reading or discussion, or omit it for all three." );
    }
    $groups = $group !== '' ? [ $group => $map[ $group ] ] : $map;

    $out = [];
    foreach ( $groups as $name => $keys ) {
      foreach ( $keys as $key => $type ) {
        $out[ $name ][ $key ] = get_option( $key );
      }
    }
    // Reported alongside the real settings so an agent looking for the admin email
    // finds the supported route instead of concluding it cannot be changed.
    $out['not_settable'] = $this->refused_settings();
    $out['admin_email'] = get_option( 'admin_email' );
    return $this->json( $r, $out );
  }

  private function write_settings( array $r, array $a ): array {
    $may = $this->may( 'manage_options', 'changing site settings' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $settings = $a['settings'] ?? null;
    if ( !is_array( $settings ) || empty( $settings ) ) {
      return $this->error( $r, 'Provide a "settings" object of setting name to value.' );
    }

    $allowed = [];
    foreach ( $this->settings_map() as $keys ) {
      $allowed = array_merge( $allowed, $keys );
    }
    $refused = $this->refused_settings();

    $changed = [];
    $skipped = [];

    foreach ( $settings as $key => $value ) {
      $key = (string) $key;

      if ( isset( $refused[ $key ] ) ) {
        $skipped[ $key ] = $refused[ $key ];
        continue;
      }

      // The administration email is the one setting that routes through WordPress's
      // own confirmation flow rather than being written here.
      if ( $key === 'new_admin_email' ) {
        $result = $this->request_admin_email_change( (string) $value, $a );
        if ( is_array( $result ) ) {
          $changed[ $key ] = $result['message'];
        }
        else {
          $skipped[ $key ] = $result;
        }
        continue;
      }

      if ( !isset( $allowed[ $key ] ) ) {
        $skipped[ $key ] = 'Not a setting this tool can change. Call wp_get_settings to see what is available.';
        continue;
      }

      $clean = $this->coerce_setting( $allowed[ $key ], $value );
      if ( $clean instanceof WP_Error ) {
        $skipped[ $key ] = $clean->get_error_message();
        continue;
      }

      if ( $key === 'default_role' ) {
        $unsafe = $this->default_role_objection( $clean );
        if ( $unsafe !== true ) {
          $skipped[ $key ] = $unsafe;
          continue;
        }
      }

      if ( get_option( $key ) == $clean ) {
        continue; // Unchanged: not a failure, and not worth reporting as a change.
      }
      update_option( $key, $clean );
      $changed[ $key ] = $clean;
    }

    $out = [ 'changed' => $changed ];
    if ( !empty( $skipped ) ) {
      $out['not_changed'] = $skipped;
    }
    return $this->json( $r, $out );
  }

  /**
  * Whether a role is safe to hand to everyone who fills in the registration form.
  *
  * This started as "does the role have edit_posts", which was the wrong question and
  * failed open. A role can carry manage_options, promote_users, edit_users or
  * activate_plugins without carrying edit_posts, and a site with a hand-made
  * "api_admin" role holding only read and manage_options sailed through: one call
  * setting users_can_register with that default turned the public form into an
  * administrator factory.
  *
  * Naming the dangerous capabilities is the same mistake one level up, because the list
  * is open-ended and every plugin adds to it. Close it by construction instead: compare
  * against what a subscriber gets, and refuse anything extra. The failure mode of
  * getting that wrong is a refusal, not an escalation.
  *
  * The explicit list is a backstop for the case where the subscriber role itself has
  * been widened, which would otherwise raise the floor and let everything through.
  *
  * @return true|string True if safe, otherwise the refusal message.
  */
  private function default_role_objection( string $role_name ) {
    $role = get_role( $role_name );
    if ( !$role ) {
      return "\"{$role_name}\" is not a role on this site.";
    }

    $granted = array_keys( array_filter( (array) $role->capabilities ) );

    // Backstop: never acceptable for an account created by an anonymous form.
    $never = [
      'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users',
      'activate_plugins', 'install_plugins', 'edit_plugins', 'delete_plugins',
      'switch_themes', 'install_themes', 'edit_themes', 'delete_themes',
      'edit_files', 'unfiltered_html', 'manage_network', 'import', 'export',
    ];
    $dangerous = array_values( array_intersect( $granted, $never ) );
    if ( $dangerous ) {
      return "Refusing to make \"{$role_name}\" the default role: it grants "
        . implode( ', ', $dangerous ) . ' to anyone who registers. Assign that role to specific accounts instead.';
    }

    // Anything beyond a subscriber is more than an anonymous signup should receive.
    $subscriber = get_role( 'subscriber' );
    if ( $subscriber ) {
      $baseline = array_keys( array_filter( (array) $subscriber->capabilities ) );
      $extra = array_values( array_diff( $granted, $baseline ) );
      if ( $extra ) {
        return "Refusing to make \"{$role_name}\" the default role: it grants "
          . implode( ', ', $extra ) . ' beyond what a subscriber gets, to anyone who registers.';
      }
    }
    return true;
  }

  /**
  * Start WordPress's administration-email change, the same one Settings > General uses.
  *
  * The hook that sends the confirmation lives in wp-admin/includes/admin-filters.php,
  * which a REST request never loads, so simply writing the option here would store a
  * pending address and send nothing: the change would appear to have been requested and
  * then never arrive. Call the handler directly instead.
  *
  * @return true|string
  */
  private function request_admin_email_change( string $email, array $a ) {
    $email = sanitize_email( $email );
    if ( !is_email( $email ) ) {
      return 'That is not a valid email address.';
    }
    if ( get_option( 'admin_email' ) === $email ) {
      return 'That is already the administration email address.';
    }

    // This sends mail from the site's own domain to any address named, and core builds
    // the body by substituting ###SITENAME### from blogname, which this same tool can
    // rewrite in the same request. Left unmetered that is a phishing mailer with a
    // reputable From: and a legitimate-looking confirmation link. Two gates: a
    // confirmation token, so a single injected instruction cannot fire it, and a
    // cooldown, so it cannot be walked down a list of addresses.
    $ok = $this->confirmed(
      'admin_email_change',
      $email,
      $a,
      "This emails a confirmation link to \"{$email}\" from this site, and the address becomes the administration address once someone clicks it."
    );
    if ( $ok !== true ) {
      return $ok;
    }
    if ( get_transient( 'gmcp_admin_email_cooldown' ) ) {
      return 'An administration email change was already requested from here in the last 15 minutes. Wait before requesting another.';
    }

    require_once ABSPATH . 'wp-admin/includes/misc.php';
    if ( !function_exists( 'update_option_new_admin_email' ) ) {
      return 'This WordPress version does not expose the confirmation flow, so the address was not changed.';
    }

    set_transient( 'gmcp_admin_email_cooldown', 1, 15 * MINUTE_IN_SECONDS );

    // update_option_new_admin_email() returns nothing and swallows a wp_mail failure,
    // so without this the tool cheerfully reports "a confirmation link was emailed" on
    // every host with no mailer configured, which is most local and many shared hosts.
    $mail_error = null;
    $capture = function ( $error ) use ( &$mail_error ) {
      $mail_error = $error instanceof WP_Error ? $error->get_error_message() : 'unknown error';
    };
    add_action( 'wp_mail_failed', $capture );
    update_option( 'new_admin_email', $email, false );
    update_option_new_admin_email( get_option( 'admin_email' ), $email );
    remove_action( 'wp_mail_failed', $capture );

    if ( $mail_error !== null ) {
      return [ 'message' => "The change to \"{$email}\" is pending, but this site could not send the confirmation email ({$mail_error}). Until someone opens the link, the administration address is unchanged." ];
    }
    return [ 'message' => "A confirmation link was emailed to \"{$email}\". The administration address changes only when someone opens that link." ];
  }

  /**
  * @return mixed|WP_Error
  */
  private function coerce_setting( string $type, $value ) {
    switch ( $type ) {
      case 'bool':
        return ( $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes' ) ? 1 : 0;
      case 'int':
        if ( !is_numeric( $value ) ) {
          return new WP_Error( 'gmcp_bad_value', 'Expected a number.' );
        }
        return (int) $value;
      case 'float':
        if ( !is_numeric( $value ) ) {
          return new WP_Error( 'gmcp_bad_value', 'Expected a number.' );
        }
        return (float) $value;
      case 'timezone':
        $tz = trim( (string) $value );
        if ( $tz !== '' && !in_array( $tz, timezone_identifiers_list(), true ) ) {
          return new WP_Error( 'gmcp_bad_value', "\"{$tz}\" is not a recognised timezone identifier, e.g. Europe/Madrid." );
        }
        return $tz;
      case 'role':
        $role = sanitize_key( (string) $value );
        if ( !get_role( $role ) ) {
          return new WP_Error( 'gmcp_bad_value', "\"{$role}\" is not a role on this site." );
        }
        return $role;
      case 'textarea':
        return sanitize_textarea_field( (string) $value );
      case 'text':
      default:
        return sanitize_text_field( (string) $value );
    }
  }

  #endregion

  #region Permalinks

  private function read_permalinks( array $r ): array {
    global $wp_rewrite;
    return $this->json( $r, [
      'structure' => get_option( 'permalink_structure' ),
      'category_base' => get_option( 'category_base' ),
      'tag_base' => get_option( 'tag_base' ),
      'using_pretty_permalinks' => $wp_rewrite instanceof WP_Rewrite ? (bool) $wp_rewrite->using_permalinks() : (bool) get_option( 'permalink_structure' ),
    ] );
  }

  private function write_permalinks( array $r, array $a ): array {
    $may = $this->may( 'manage_options', 'changing the permalink structure' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    global $wp_rewrite;
    if ( !$wp_rewrite instanceof WP_Rewrite ) {
      return $this->error( $r, 'The rewrite system is not available on this request, so permalinks cannot be changed.' );
    }

    $structure = trim( (string) $a['structure'] );

    // A structure built only from dates or categories gives two posts published the
    // same day the same URL. WordPress does not stop you, it just starts serving the
    // wrong post, which is a slow and confusing thing to debug later.
    if ( $structure !== '' && strpos( $structure, '%postname%' ) === false && strpos( $structure, '%post_id%' ) === false ) {
      return $this->error( $r, 'That structure contains neither %postname% nor %post_id%, so two posts could resolve to the same URL. Include one of them.' );
    }

    $wp_rewrite->set_permalink_structure( $structure );

    if ( isset( $a['category_base'] ) ) {
      $wp_rewrite->set_category_base( sanitize_text_field( (string) $a['category_base'] ) );
    }
    if ( isset( $a['tag_base'] ) ) {
      $wp_rewrite->set_tag_base( sanitize_text_field( (string) $a['tag_base'] ) );
    }

    // A hard flush is what rewrites .htaccess on Apache. WP_Rewrite::flush_rules( true )
    // ends by calling save_mod_rewrite_rules() only if that function exists, and it
    // lives in wp-admin/includes/misc.php, which a REST request never loads. Without
    // this require the hard flush degraded silently to a soft one, .htaccess was never
    // written, and every inner URL 404'd while this tool reported success. The home
    // page still resolved through DirectoryIndex, so the front-end check did not catch
    // it either.
    // Both files, not just misc.php: save_mod_rewrite_rules() lives there but calls
    // get_home_path() and insert_with_markers(), which are in file.php. Loading only
    // misc.php made the call fatal on an undefined function instead of writing the
    // file, which the dispatcher then reported as a failed tool.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    $wp_rewrite->flush_rules( true );

    $note = '';
    if ( $structure !== '' ) {
      $health = $this->front_end_healthy();
      if ( $health === false ) {
        $note = ' The site returned a server error afterwards, which usually means the web server is not passing pretty URLs to WordPress. Set the structure back to an empty string if the site does not recover.';
      }
    }

    return $this->text(
      $r,
      'Permalink structure set to ' . ( $structure === '' ? 'plain' : "\"{$structure}\"" ) . ' and rewrite rules flushed.'
        . ' This API stays reachable either way, because it also answers on ?rest_route=.' . $note
    );
  }

  #endregion

  /**
  * One call that answers "what am I looking at".
  *
  * An agent starting a conversation about a site currently has to spend five or six
  * round trips working out what it is dealing with: which theme, which plugins, whether
  * there are custom post types, whether anything is waiting for attention. Each of those
  * is a tool call, and every one of them lands in the model's context in full. This
  * gathers the same picture in a single response, summarised rather than enumerated.
  *
  * Everything here is read-only and comes from data WordPress already has in memory or
  * in options. Update counts come from the stored transient rather than a fresh check,
  * because forcing one means an HTTP round trip to wordpress.org and this tool is meant
  * to be the cheap first call, not an expensive one.
  */
  private function site_briefing( array $r ): array {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $theme = wp_get_theme();

    $plugins = get_plugins();
    $active = [];
    foreach ( $plugins as $file => $data ) {
      if ( is_plugin_active( $file ) ) {
        $active[] = $data['Name'] . ' ' . $data['Version'];
      }
    }
    sort( $active );

    // Post types, with counts, so an agent knows a "portfolio" type exists before it
    // goes looking for portfolio items among the posts.
    $types = [];
    foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $type ) {
      if ( in_array( $type->name, [ 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ], true ) ) {
        continue;
      }
      $counts = (array) wp_count_posts( $type->name );
      $row = [
        'type' => $type->name,
        'label' => $type->labels->name ?? $type->name,
        'published' => (int) ( $counts['publish'] ?? 0 ),
      ];
      foreach ( [ 'draft', 'pending', 'future', 'private' ] as $status ) {
        if ( !empty( $counts[ $status ] ) ) {
          $row[ $status ] = (int) $counts[ $status ];
        }
      }
      if ( !empty( $counts['trash'] ) ) {
        $row['trash'] = (int) $counts['trash'];
      }
      $types[] = $row;
    }

    // Only taxonomies attached to something reported above. Otherwise the list carries
    // wp_pattern_category and link_category, which describe machinery rather than
    // content and would send an agent looking for posts that do not exist.
    $reported = wp_list_pluck( $types, 'type' );
    $taxonomies = [];
    foreach ( get_taxonomies( [ 'show_ui' => true ], 'objects' ) as $tax ) {
      if ( !array_intersect( (array) $tax->object_type, $reported ) ) {
        continue;
      }
      $count = wp_count_terms( [ 'taxonomy' => $tax->name, 'hide_empty' => false ] );
      $taxonomies[] = [
        'taxonomy' => $tax->name,
        'label' => $tax->labels->name ?? $tax->name,
        'terms' => is_wp_error( $count ) ? 0 : (int) $count,
        'applies_to' => array_values( (array) $tax->object_type ),
      ];
    }

    $comments = wp_count_comments();

    $users = count_users();

    // The front page is the single most confusing thing about a WordPress site an agent
    // has not seen before: whether "the home page" is a post list or an actual page it
    // can edit changes what every subsequent instruction means.
    $front = [ 'shows' => get_option( 'show_on_front' ) === 'page' ? 'a static page' : 'the latest posts' ];
    if ( get_option( 'show_on_front' ) === 'page' ) {
      $front_id = (int) get_option( 'page_on_front' );
      $posts_id = (int) get_option( 'page_for_posts' );
      $front['front_page'] = $front_id ? [ 'ID' => $front_id, 'title' => get_the_title( $front_id ) ] : null;
      $front['posts_page'] = $posts_id ? [ 'ID' => $posts_id, 'title' => get_the_title( $posts_id ) ] : null;
    }

    $latest = get_posts( [
      'post_type' => 'any',
      'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
      'numberposts' => 1,
      'orderby' => 'modified',
      'order' => 'DESC',
      'suppress_filters' => false,
    ] );

    return $this->json( $r, [
      'site' => [
        'name' => get_bloginfo( 'name' ),
        'tagline' => get_bloginfo( 'description' ),
        'url' => home_url(),
        'admin_url' => admin_url(),
        'language' => get_locale(),
        'timezone' => wp_timezone_string(),
        'multisite' => is_multisite(),
      ],
      'versions' => [
        'wordpress' => get_bloginfo( 'version' ),
        'php' => PHP_VERSION,
        'guarded-mcp' => GMCP_VERSION,
      ],
      'theme' => [
        'name' => $theme->get( 'Name' ),
        'version' => $theme->get( 'Version' ),
        'stylesheet' => get_stylesheet(),
        'parent' => $theme->parent() ? $theme->get_template() : null,
        'block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
      ],
      'plugins' => [
        'active_count' => count( $active ),
        'inactive_count' => count( $plugins ) - count( $active ),
        'active' => $active,
      ],
      'updates' => $this->pending_updates(),
      'content' => [
        'post_types' => $types,
        'taxonomies' => $taxonomies,
        'last_modified' => $latest ? [
          'title' => $latest[0]->post_title,
          'type' => $latest[0]->post_type,
          'status' => $latest[0]->post_status,
          'modified' => $latest[0]->post_modified_gmt . ' GMT',
        ] : null,
      ],
      'comments' => [
        'approved' => (int) $comments->approved,
        'awaiting_moderation' => (int) $comments->moderated,
        'spam' => (int) $comments->spam,
        'open_by_default' => get_option( 'default_comment_status' ) === 'open',
      ],
      'users' => [
        'total' => (int) ( $users['total_users'] ?? 0 ),
        'by_role' => array_map( 'intval', (array) ( $users['avail_roles'] ?? [] ) ),
      ],
      'permalinks' => [
        'structure' => get_option( 'permalink_structure' ) ?: 'plain',
        'rest_url' => rest_url(),
      ],
      'front_page' => $front,
      'recent_agent_activity' => $this->recent_activity_summary(),
    ] );
  }

  /**
  * What WordPress last saw as available, without asking wordpress.org again.
  *
  * The transients are populated by scheduled checks. If they are missing or stale the
  * honest answer is "not checked recently", not zero: reporting zero updates when the
  * truth is that nobody looked would let an agent tell someone their site is current
  * when it may be badly out of date.
  */
  private function pending_updates(): array {
    $out = [ 'plugins' => 0, 'themes' => 0, 'core' => false, 'last_checked' => null ];

    $plugins = get_site_transient( 'update_plugins' );
    $themes = get_site_transient( 'update_themes' );
    $core = get_site_transient( 'update_core' );

    if ( !$plugins && !$themes && !$core ) {
      return [ 'checked' => false, 'note' => 'WordPress has not run an update check recently, so this is unknown.' ];
    }

    $out['checked'] = true;
    $out['plugins'] = isset( $plugins->response ) ? count( (array) $plugins->response ) : 0;
    $out['themes'] = isset( $themes->response ) ? count( (array) $themes->response ) : 0;
    foreach ( (array) ( $core->updates ?? [] ) as $update ) {
      if ( ( $update->response ?? '' ) === 'upgrade' ) {
        $out['core'] = true;
        break;
      }
    }
    $checked = 0;
    foreach ( [ $plugins, $themes, $core ] as $transient ) {
      $checked = max( $checked, (int) ( $transient->last_checked ?? 0 ) );
    }
    $out['last_checked'] = $checked ? gmdate( 'Y-m-d H:i', $checked ) . ' GMT' : null;

    return $out;
  }

  /** A one-line pulse from the audit log, so an agent knows whether it is the first here. */
  private function recent_activity_summary(): array {
    if ( !class_exists( 'GMCP_Audit' ) || empty( $this->core->get_option( 'mcp_activity_log' ) ) ) {
      return [ 'available' => false ];
    }
    $recent = GMCP_Audit::query( [ 'limit' => 25 ] );
    if ( !$recent ) {
      return [ 'available' => true, 'calls' => 0 ];
    }
    $failed = 0;
    foreach ( $recent as $entry ) {
      if ( ( $entry['outcome'] ?? '' ) !== 'ok' ) {
        $failed++;
      }
    }
    return [
      'available' => true,
      'calls_recorded' => GMCP_Audit::count(),
      'refused_in_last_25' => $failed,
      'most_recent' => $recent[0]['ts'] . ' GMT',
      'most_recent_tool' => (string) $recent[0]['tool'],
    ];
  }

  #endregion

  /**
  * The audit log, as an agent is allowed to see it.
  *
  * Read only, deliberately. An agent that can prune its own audit trail is not being
  * audited, so nothing here deletes, and the settings screen is the only place the log
  * can be cleared.
  *
  * The chain verdict rides along with the rows, because a caller asking what happened
  * should be told immediately if the record it is reading has been altered, rather than
  * having to know to ask.
  */
  private function audit_log( array $a, array $r ): array {
    if ( !class_exists( 'GMCP_Audit' ) || empty( $this->core->get_option( 'mcp_activity_log' ) ) ) {
      return $this->error( $r, 'The audit log is switched off for this site, so there is nothing recorded to read. Turn it on under the MCP Server screen in the admin menu.' );
    }

    $filters = [];
    foreach ( [ 'tool', 'outcome', 'since', 'until', 'search' ] as $key ) {
      if ( isset( $a[ $key ] ) && is_scalar( $a[ $key ] ) && (string) $a[ $key ] !== '' ) {
        $filters[ $key ] = (string) $a[ $key ];
      }
    }
    $filters['limit'] = isset( $a['limit'] ) ? (int) $a['limit'] : 50;
    $filters['offset'] = isset( $a['offset'] ) ? (int) $a['offset'] : 0;

    $rows = [];
    foreach ( GMCP_Audit::query( $filters ) as $row ) {
      $rows[] = [
        'id' => (int) $row['id'],
        'when' => $row['ts'] . ' GMT',
        'tool' => $row['tool'],
        'target' => $row['target'],
        'outcome' => $row['outcome'],
        'ms' => (int) $row['ms'],
        'client' => $row['client'] ?: $row['auth_method'],
        'actor' => $row['actor_name'],
        'arguments' => $row['args'] ? json_decode( $row['args'], true ) : null,
        'detail' => $row['detail'],
      ];
    }

    $chain = GMCP_Audit::verify();
    return $this->json( $r, [
      'entries' => $rows,
      'total_recorded' => GMCP_Audit::count(),
      'retention_days' => GMCP_Audit::retention_days(),
      'tamper_check' => $chain['ok']
        ? 'The hash chain is intact across ' . $chain['checked'] . ' entries.'
        : 'The hash chain breaks at entry ' . $chain['broken_at'] . ': ' . $chain['reason']
          . ' Treat everything from that point on as unverified.',
      'note' => 'Arguments are recorded with credential-shaped fields replaced, so a value reading "[redacted]" means a secret was passed rather than that the field was empty.',
    ] );
  }

  #region Site health

  private function site_health( array $r ): array {
    // Site Health is a wp-admin class and its tests call freely into the rest of
    // wp-admin: get_core_updates() from update.php, get_plugins() from plugin.php, and
    // so on. None of that is loaded on a REST request, so each one has to be pulled in
    // or the first test fatals on an undefined function.
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    if ( !class_exists( 'WP_Site_Health' ) ) {
      return $this->error( $r, 'Site Health is not available on this WordPress version.' );
    }
    $health = WP_Site_Health::get_instance();
    $tests = WP_Site_Health::get_tests();

    $results = [ 'critical' => [], 'recommended' => [], 'good' => [] ];

    // Only the "direct" tests. The async ones are written to be called from
    // admin-ajax with their own request each, and several of them make loopback
    // requests, so running the whole set here would be slow and would report failures
    // that are really just a host blocking loopback.
    foreach ( $tests['direct'] ?? [] as $test ) {
      $callback = $test['test'] ?? null;
      $result = null;

      // A third-party test registered through the site_status_tests filter can throw,
      // and one bad test should cost its own row rather than the whole report.
      try {
        if ( is_string( $callback ) && method_exists( $health, 'get_test_' . $callback ) ) {
          $result = $health->{'get_test_' . $callback}();
        }
        elseif ( is_callable( $callback ) ) {
          $result = call_user_func( $callback );
        }
      }
      catch ( Throwable $e ) {
        $results['recommended'][] = [
          'label' => 'A Site Health check could not run',
          'description' => is_string( $callback ) ? "The \"{$callback}\" check failed with: " . $e->getMessage() : $e->getMessage(),
        ];
        continue;
      }
      if ( !is_array( $result ) || empty( $result['label'] ) ) {
        continue;
      }

      $status = $result['status'] ?? 'recommended';
      $bucket = $status === 'critical' ? 'critical' : ( $status === 'good' ? 'good' : 'recommended' );
      $results[ $bucket ][] = [
        'label' => wp_strip_all_tags( $result['label'] ),
        'description' => wp_strip_all_tags( $result['description'] ?? '' ),
      ];
    }

    $theme = wp_get_theme();
    return $this->json( $r, [
      'summary' => [
        'critical' => count( $results['critical'] ),
        'recommended' => count( $results['recommended'] ),
        'good' => count( $results['good'] ),
      ],
      'environment' => [
        'wordpress' => get_bloginfo( 'version' ),
        'php' => PHP_VERSION,
        'memory_limit' => ini_get( 'memory_limit' ),
        'is_multisite' => is_multisite(),
        'active_theme' => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
        'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
        'active_plugins' => count( (array) get_option( 'active_plugins', [] ) ),
        'debug_enabled' => defined( 'WP_DEBUG' ) && WP_DEBUG,
      ],
      'critical' => $results['critical'],
      'recommended' => $results['recommended'],
    ] );
  }

  #endregion


  #region Menus

  /**
  * Resolve whatever the caller called a menu into a term object: id, slug or name all
  * work, because a model that just created "Main Menu" naturally refers to it by name.
  */
  private function resolve_menu( $given ) {
    $given = trim( (string) $given );
    if ( $given === '' ) {
      return null;
    }
    $menu = wp_get_nav_menu_object( is_numeric( $given ) ? (int) $given : $given );
    if ( $menu ) {
      return $menu;
    }
    foreach ( wp_get_nav_menus() as $candidate ) {
      if ( strcasecmp( $candidate->name, $given ) === 0 ) {
        return $candidate;
      }
    }
    return null;
  }

  private function list_menus( array $r ): array {
    $locations = get_nav_menu_locations();
    $registered = get_registered_nav_menus();

    $menus = [];
    foreach ( wp_get_nav_menus() as $menu ) {
      $assigned = [];
      foreach ( $locations as $location => $menu_id ) {
        if ( (int) $menu_id === (int) $menu->term_id ) {
          $assigned[] = $location;
        }
      }
      $menus[] = [
        'id' => (int) $menu->term_id,
        'name' => $menu->name,
        'slug' => $menu->slug,
        'items' => (int) $menu->count,
        'locations' => $assigned,
      ];
    }

    $location_list = [];
    foreach ( $registered as $slug => $label ) {
      $location_list[] = [
        'location' => $slug,
        'description' => $label,
        'menu_id' => isset( $locations[ $slug ] ) ? (int) $locations[ $slug ] : null,
      ];
    }

    $out = [ 'menus' => $menus, 'locations' => $location_list ];
    if ( empty( $registered ) ) {
      // Block themes put navigation in template parts rather than registering
      // locations, so an empty list here is normal rather than a misconfiguration.
      $out['note'] = 'The active theme registers no menu locations. Block themes place navigation in template parts instead, so a menu created here will not appear until something references it.';
    }
    return $this->json( $r, $out );
  }

  private function get_menu_items( array $r, array $a ): array {
    $menu = $this->resolve_menu( $a['menu'] );
    if ( !$menu ) {
      return $this->error( $r, "No menu matches \"{$a['menu']}\". Use wp_list_menus to see what exists." );
    }
    $items = wp_get_nav_menu_items( $menu->term_id, [ 'post_status' => 'publish,draft' ] );
    $out = [];
    foreach ( (array) $items as $item ) {
      $out[] = [
        'item_id' => (int) $item->ID,
        'title' => $item->title,
        'type' => $item->type,
        'object' => $item->object,
        'object_id' => (int) $item->object_id,
        'url' => $item->url,
        'parent_id' => (int) $item->menu_item_parent,
        'position' => (int) $item->menu_order,
      ];
    }
    return $this->json( $r, [ 'menu' => $menu->name, 'menu_id' => (int) $menu->term_id, 'items' => $out ] );
  }

  private function create_menu( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'creating a menu' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $name = sanitize_text_field( (string) ( $a['name'] ?? '' ) );
    if ( $name === '' ) {
      return $this->error( $r, 'A menu name is required.' );
    }
    if ( wp_get_nav_menu_object( $name ) ) {
      return $this->error( $r, "A menu called \"{$name}\" already exists." );
    }
    $menu_id = wp_create_nav_menu( $name );
    if ( is_wp_error( $menu_id ) ) {
      return $this->error( $r, 'Could not create the menu: ' . $menu_id->get_error_message() );
    }

    $assigned = [];
    foreach ( (array) ( $a['locations'] ?? [] ) as $location ) {
      $result = $this->set_menu_location( (string) $location, (int) $menu_id );
      if ( $result === true ) {
        $assigned[] = $location;
      }
    }
    return $this->json( $r, [ 'menu_id' => (int) $menu_id, 'name' => $name, 'locations' => $assigned ] );
  }

  private function delete_menu( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'deleting a menu' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $menu = $this->resolve_menu( $a['menu'] );
    if ( !$menu ) {
      return $this->error( $r, "No menu matches \"{$a['menu']}\"." );
    }
    $ok = $this->confirmed( 'wp_delete_menu', (int) $menu->term_id, $a, "This deletes the menu \"{$menu->name}\" and all {$menu->count} of its items." );
    if ( $ok !== true ) {
      return $this->error( $r, $ok );
    }
    $result = wp_delete_nav_menu( $menu->term_id );
    if ( is_wp_error( $result ) || $result === false ) {
      return $this->error( $r, 'Could not delete the menu.' );
    }
    return $this->text( $r, "Deleted the menu \"{$menu->name}\"." );
  }

  private function add_menu_item( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'changing a menu' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $menu = $this->resolve_menu( $a['menu'] );
    if ( !$menu ) {
      return $this->error( $r, "No menu matches \"{$a['menu']}\"." );
    }

    $type = isset( $a['type'] ) ? sanitize_key( (string) $a['type'] ) : 'custom';
    $object_id = isset( $a['object_id'] ) ? (int) $a['object_id'] : 0;
    $title = isset( $a['title'] ) ? sanitize_text_field( (string) $a['title'] ) : '';
    $object = isset( $a['object'] ) ? sanitize_key( (string) $a['object'] ) : '';

    if ( $type === 'post_type' ) {
      $post = $object_id ? get_post( $object_id ) : null;
      if ( !$post ) {
        return $this->error( $r, 'A post_type item needs object_id set to an existing post or page id.' );
      }
      // The item stores its own copy of the title, and core's front-end filter
      // (_is_valid_nav_menu_item) only drops items whose target is missing or trashed.
      // So a draft, pending, private or password-protected target still renders: its
      // headline appears in the public navigation, linking to a URL that 404s for the
      // visitor, and a protected post arrives carrying core's "Protected:" prefix,
      // advertising that it exists.
      if ( get_post_status( $post ) !== 'publish' ) {
        return $this->error( $r, "Post {$object_id} is \"" . get_post_status( $post ) . "\", not published. Adding it would put its title in the public menu behind a link visitors cannot open. Publish it first, or add a custom item." );
      }
      if ( $post->post_password !== '' ) {
        return $this->error( $r, "Post {$object_id} is password protected, so adding it to a menu would advertise its title publicly." );
      }
      $object = $object ?: $post->post_type;
      $title = $title ?: get_the_title( $post );
    }
    elseif ( $type === 'taxonomy' ) {
      $term = $object_id ? get_term( $object_id ) : null;
      if ( !$term || is_wp_error( $term ) ) {
        return $this->error( $r, 'A taxonomy item needs object_id set to an existing term id.' );
      }
      $object = $object ?: $term->taxonomy;
      $title = $title ?: $term->name;
    }
    else {
      $type = 'custom';
      $url = isset( $a['url'] ) ? esc_url_raw( (string) $a['url'] ) : '';
      if ( $url === '' ) {
        return $this->error( $r, 'A custom item needs a url.' );
      }
      if ( $title === '' ) {
        return $this->error( $r, 'A custom item needs a title.' );
      }
    }

    $item = [
      'menu-item-title' => $title,
      'menu-item-type' => $type,
      'menu-item-object' => $object,
      'menu-item-object-id' => $object_id,
      'menu-item-parent-id' => isset( $a['parent_id'] ) ? (int) $a['parent_id'] : 0,
      // Without this the item is created as a draft and simply never renders, which
      // looks exactly like the call having silently done nothing.
      'menu-item-status' => 'publish',
    ];
    if ( $type === 'custom' ) {
      $item['menu-item-url'] = esc_url_raw( (string) $a['url'] );
    }
    if ( isset( $a['position'] ) ) {
      $item['menu-item-position'] = (int) $a['position'];
    }

    $item_id = wp_update_nav_menu_item( $menu->term_id, 0, $item );
    if ( is_wp_error( $item_id ) ) {
      return $this->error( $r, 'Could not add the item: ' . $item_id->get_error_message() );
    }
    return $this->json( $r, [ 'item_id' => (int) $item_id, 'menu_id' => (int) $menu->term_id, 'title' => $title ] );
  }

  private function delete_menu_item( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'changing a menu' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $item_id = (int) $a['item_id'];
    if ( !is_nav_menu_item( $item_id ) ) {
      return $this->error( $r, "{$item_id} is not a menu item id. Use wp_get_menu_items to find one." );
    }

    // Children reference the parent through postmeta, not the post tree, so deleting a
    // parent leaves them pointing at an id that no longer exists: they vanish from the
    // rendered menu while still sitting in the database. Re-parent them first.
    $parent = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );
    $children = get_posts( [
      'post_type' => 'nav_menu_item',
      'post_status' => 'any',
      'numberposts' => -1,
      'meta_key' => '_menu_item_menu_item_parent',
      'meta_value' => (string) $item_id,
      'fields' => 'ids',
    ] );
    foreach ( $children as $child ) {
      update_post_meta( $child, '_menu_item_menu_item_parent', (string) $parent );
    }

    wp_delete_post( $item_id, true );
    $moved = count( $children );
    return $this->text( $r, "Removed menu item {$item_id}." . ( $moved ? " {$moved} child item(s) moved up a level." : '' ) );
  }

  /**
  * @return true|string
  */
  private function set_menu_location( string $location, int $menu_id ) {
    $registered = get_registered_nav_menus();
    if ( !isset( $registered[ $location ] ) ) {
      return "The active theme has no menu location called \"{$location}\".";
    }
    $locations = get_nav_menu_locations();
    if ( $menu_id > 0 ) {
      $locations[ $location ] = $menu_id;
    }
    else {
      unset( $locations[ $location ] );
    }
    // Stored in theme_mods_{stylesheet}, so this is per theme and a theme switch
    // silently drops it.
    set_theme_mod( 'nav_menu_locations', $locations );
    return true;
  }

  private function assign_menu_location( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'assigning a menu location' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $location = sanitize_key( (string) $a['location'] );
    $given = isset( $a['menu'] ) ? trim( (string) $a['menu'] ) : '';

    if ( $given === '' ) {
      $result = $this->set_menu_location( $location, 0 );
      return $result === true
        ? $this->text( $r, "Cleared the menu location \"{$location}\"." )
        : $this->error( $r, $result );
    }

    $menu = $this->resolve_menu( $given );
    if ( !$menu ) {
      return $this->error( $r, "No menu matches \"{$given}\"." );
    }
    $result = $this->set_menu_location( $location, (int) $menu->term_id );
    return $result === true
      ? $this->text( $r, "Assigned \"{$menu->name}\" to the \"{$location}\" location." )
      : $this->error( $r, $result );
  }

  #endregion

  #region Widgets

  /**
  * Whether widgets can actually appear on this site.
  *
  * The obvious test, "are any sidebars registered", is wrong, and quietly so. A block
  * theme does not render widgets at all, but WordPress still registers the previous
  * classic theme's sidebars under one: _wp_block_theme_register_classic_sidebars()
  * runs on widgets_init and rebuilds them from the wp_classic_sidebars theme mod, so
  * that switching to a block theme does not destroy anyone's widgets.
  *
  * The result is a site where sidebar-1 exists, accepts writes, stores them correctly,
  * and displays nothing, because no template ever calls dynamic_sidebar(). A tool that
  * only checked for an empty list would report success for a widget that can never
  * appear, which is the worst answer available.
  *
  * @return true|string True if widgets render here, otherwise why they do not.
  */
  private function widgets_render() {
    if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
      return 'The active theme is a block theme, so it does not display widgets. Its sidebars and footers are template parts, edited in the Site Editor and stored as blocks. Any widget areas still listed are kept by WordPress so widgets survive a theme switch, but nothing renders them.';
    }
    global $wp_registered_sidebars;
    if ( empty( $wp_registered_sidebars ) || !is_array( $wp_registered_sidebars ) ) {
      return 'The active theme registers no widget areas.';
    }
    return true;
  }

  /**
  * The registered sidebars, whether or not the theme renders them. Listing has to keep
  * working on a block theme so an agent can see and clean up widgets left behind by a
  * previous theme.
  */
  private function registered_sidebars(): array {
    global $wp_registered_sidebars;
    return is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : [];
  }

  private function list_sidebars( array $r ): array {
    $sidebars = $this->registered_sidebars();
    $renders = $this->widgets_render();
    $assignments = wp_get_sidebars_widgets();

    $out = [];
    foreach ( $sidebars as $id => $sidebar ) {
      $widgets = [];
      foreach ( (array) ( $assignments[ $id ] ?? [] ) as $widget_id ) {
        $widgets[] = [
          'widget_id' => $widget_id,
          'type' => $this->widget_base( $widget_id ),
        ];
      }
      $out[] = [
        'sidebar' => $id,
        'name' => $sidebar['name'] ?? $id,
        'widgets' => $widgets,
      ];
    }
    $payload = [
      'sidebars' => $out,
      'inactive' => array_values( (array) ( $assignments['wp_inactive_widgets'] ?? [] ) ),
      'widgets_render' => $renders === true,
    ];
    if ( $renders !== true ) {
      $payload['note'] = $renders;
    }
    return $this->json( $r, $payload );
  }

  /** "block-3" -> "block", "text-2" -> "text". */
  private function widget_base( string $widget_id ): string {
    return (string) preg_replace( '/-\d+$/', '', $widget_id );
  }

  /**
  * Whether this site has opted into storing raw HTML in widgets.
  *
  * Off by default. A site that genuinely needs a tracking snippet in a sidebar can
  * turn it on, the same way gmcp_allow_remote_install works.
  */
  private function raw_widget_html_allowed( string $sidebar ): bool {
    return (bool) apply_filters( 'gmcp_allow_unfiltered_widget_html', false, $sidebar );
  }

  /**
  * Run a callback with unfiltered_html revoked for this request.
  *
  * Routing classic widget settings through WP_Widget::update() restores each widget
  * type's own sanitizer, which is necessary but not sufficient: several of those
  * sanitizers are themselves written as "if the current user may post unfiltered HTML,
  * store it verbatim". WP_Widget_Text::update() is exactly that. Since every caller
  * that reaches these tools is an administrator, that branch is always taken, and the
  * sanitizer sanitizes nothing.
  *
  * The capability is the wrong question. It describes who is making the request, and
  * the risk here is about who wrote the markup: the instruction may have arrived in a
  * comment or a post body that the model read while doing something else. So the
  * capability is dropped for the duration of the update, and core's own else-branch
  * does the filtering it was always meant to do.
  *
  * @param callable $callback
  * @return mixed
  */
  private function without_unfiltered_html( callable $callback, string $sidebar ) {
    if ( $this->raw_widget_html_allowed( $sidebar ) ) {
      return $callback();
    }
    $revoke = function ( $allcaps ) {
      $allcaps['unfiltered_html'] = false;
      return $allcaps;
    };
    add_filter( 'user_has_cap', $revoke, PHP_INT_MAX );
    try {
      return $callback();
    }
    finally {
      remove_filter( 'user_has_cap', $revoke, PHP_INT_MAX );
    }
  }

  private function add_widget( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'adding a widget' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    // Refuse rather than store: a widget written into a block theme's compatibility
    // sidebar is saved perfectly and never shown, so reporting success would be a lie
    // the agent has no way to detect.
    $renders = $this->widgets_render();
    if ( $renders !== true ) {
      return $this->error( $r, $renders );
    }
    $sidebars = $this->registered_sidebars();
    $sidebar = (string) $a['sidebar'];
    if ( !isset( $sidebars[ $sidebar ] ) ) {
      return $this->error( $r, "\"{$sidebar}\" is not a widget area on this theme. Use wp_list_sidebars to see them." );
    }

    $has_content = isset( $a['content'] ) && trim( (string) $a['content'] ) !== '';
    $id_base = isset( $a['id_base'] ) ? sanitize_key( (string) $a['id_base'] ) : ( $has_content ? 'block' : '' );
    if ( $id_base === '' ) {
      return $this->error( $r, 'Provide either "content" for a block widget, or "id_base" for a classic widget.' );
    }

    if ( $id_base === 'block' ) {
      if ( !$has_content ) {
        return $this->error( $r, 'A block widget needs "content" holding block markup.' );
      }
      // Always filtered, never conditioned on unfiltered_html.
      //
      // That capability check would be worse than useless here. It is always true: a
      // bearer-token request runs as an administrator, the OAuth path already demands
      // manage_options, and every single-site administrator holds unfiltered_html. So
      // the "safe" branch was unreachable and the code merely looked careful.
      //
      // The deeper problem is that it asks the wrong question. WP_Widget_Block::widget()
      // echoes the content through widget_block_content, whose core filters are
      // do_blocks, do_shortcode and wp_filter_content_tags, none of which escape. And
      // the instruction to add a widget may well have come from a comment or a post
      // body that an anonymous person wrote, which the model read while doing something
      // else. The caller being an administrator says nothing about who authored the
      // markup, so a script tag would render to every visitor on every page carrying
      // that sidebar. Filter it and let a site that genuinely needs raw markup say so.
      $content = (string) $a['content'];
      if ( !$this->raw_widget_html_allowed( $sidebar ) ) {
        $content = wp_kses_post( $content );
      }
      $instance = [ 'content' => $content ];
    }
    else {
      // Run the widget's own update() rather than storing the settings as given.
      // Every widget type sanitizes its fields there and nowhere else:
      // WP_Widget_Text::update() is where the wp_kses_post fallback for its text field
      // lives, and WP_Widget_Custom_HTML::update() likewise. Writing the option
      // directly walks straight past all of it. It also gives us the registered-type
      // check for free, so an unknown id_base cannot create an orphan instance that
      // nothing can render.
      global $wp_widget_factory;
      $widget = $wp_widget_factory instanceof WP_Widget_Factory
        ? $wp_widget_factory->get_widget_object( $id_base )
        : null;
      if ( !$widget ) {
        return $this->error( $r, "\"{$id_base}\" is not a widget type registered on this site." );
      }
      $submitted = is_array( $a['settings'] ?? null ) ? $a['settings'] : [];
      $instance = $this->without_unfiltered_html(
        function () use ( $widget, $submitted ) {
          return $widget->update( $submitted, [] );
        },
        $sidebar
      );
      if ( !is_array( $instance ) ) {
        return $this->error( $r, "The \"{$id_base}\" widget rejected those settings." );
      }
    }

    $option = 'widget_' . $id_base;
    $stored = get_option( $option, [] );
    $stored = is_array( $stored ) ? $stored : [];

    // Numeric keys only. _multiwidget must survive: without it core reads the row as
    // the pre-2.8 single-widget format and runs a conversion that mangles every
    // instance in it.
    $numbers = array_filter( array_keys( $stored ), 'is_numeric' );
    $next = $numbers ? ( max( array_map( 'intval', $numbers ) ) + 1 ) : 1;
    $stored[ $next ] = $instance;
    $stored['_multiwidget'] = 1;
    update_option( $option, $stored );

    $widget_id = $id_base . '-' . $next;
    $assignments = wp_get_sidebars_widgets();
    $list = array_values( (array) ( $assignments[ $sidebar ] ?? [] ) );
    $position = isset( $a['position'] ) ? max( 0, min( count( $list ), (int) $a['position'] ) ) : count( $list );
    array_splice( $list, $position, 0, [ $widget_id ] );
    $assignments[ $sidebar ] = $list;

    // wp_set_sidebars_widgets(), not update_option(): it clears the cached global so a
    // later read in this same request is not stale, and it adds array_version.
    wp_set_sidebars_widgets( $assignments );

    return $this->json( $r, [ 'widget_id' => $widget_id, 'sidebar' => $sidebar, 'position' => $position ] );
  }

  private function delete_widget( array $r, array $a ): array {
    $may = $this->may( 'edit_theme_options', 'removing a widget' );
    if ( $may !== true ) {
      return $this->error( $r, $may );
    }
    $widget_id = trim( (string) $a['widget_id'] );
    if ( !preg_match( '/^([a-z0-9_\-]+)-(\d+)$/i', $widget_id, $m ) ) {
      return $this->error( $r, "\"{$widget_id}\" is not a widget id. They look like block-3 or text-2." );
    }
    $id_base = $m[1];
    $number = (int) $m[2];

    $assignments = wp_get_sidebars_widgets();
    $found = false;
    foreach ( $assignments as $sidebar => $widgets ) {
      if ( !is_array( $widgets ) ) {
        continue;
      }
      $filtered = array_values( array_diff( $widgets, [ $widget_id ] ) );
      if ( count( $filtered ) !== count( $widgets ) ) {
        $found = true;
        $assignments[ $sidebar ] = $filtered;
      }
    }
    if ( !$found ) {
      return $this->error( $r, "\"{$widget_id}\" is not in any widget area." );
    }
    wp_set_sidebars_widgets( $assignments );

    $option = 'widget_' . $id_base;
    $stored = get_option( $option, [] );
    if ( is_array( $stored ) && isset( $stored[ $number ] ) ) {
      unset( $stored[ $number ] );
      $stored['_multiwidget'] = 1;
      update_option( $option, $stored );
    }
    return $this->text( $r, "Removed the widget \"{$widget_id}\"." );
  }

  #endregion

}
