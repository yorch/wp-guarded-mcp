<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The whole admin surface: one page under Settings.
*
* Plain PHP and core's own form styles on purpose. The upstream plugin shipped a 1.1 MB
* minified React bundle with no source in the repository, which made the settings screen
* the one part of the plugin nobody could edit. Everything here is readable and fits on
* a screen.
*/
class REEVE_Settings {

  const PAGE_SLUG = 'reeve-settings';
  const NONCE_ACTION = 'reeve_save_settings';

  private $core = null;
  private $notice = null;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'admin_menu', [ $this, 'add_menu' ] );
    add_action( 'admin_init', [ $this, 'maybe_handle_post' ] );
    // A plugin whose whole configuration lives on one screen should link to it from
    // the row people are looking at when they activate it.
    add_filter( 'plugin_action_links_' . plugin_basename( REEVE_ENTRY ), [ $this, 'action_links' ] );
  }

  public function action_links( $links ) {
    $settings = sprintf(
      '<a href="%s">%s</a>',
      esc_url( self::page_url() ),
      esc_html__( 'Settings', 'reeve' )
    );
    array_unshift( $links, $settings );
    return $links;
  }

  /** Where the settings screen lives, in one place so a move does not strand links. */
  public static function page_url(): string {
    return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
  }

  public function add_menu() {
    // Top level rather than buried under Settings. This is the only screen the plugin
    // has, it is the first thing anyone needs after activating, and "Settings, then
    // scroll" is a poor answer to "where do I connect my agent".
    add_menu_page(
      __( 'MCP Server', 'reeve' ),
      __( 'MCP Server', 'reeve' ),
      'manage_options',
      self::PAGE_SLUG,
      [ $this, 'render' ],
      self::menu_icon(),
      80 // Just above Settings.
    );
  }

  /**
  * The shield-and-keyhole mark as an inline SVG data URI.
  *
  * Passed as a data URI rather than a file URL so WordPress applies its own admin
  * colour scheme to it: a data:image/svg+xml icon gets the current-colour treatment
  * that a dashicon does, and matches the rest of the menu in every colour scheme.
  */
  private static function menu_icon(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">'
      . '<path fill="black" d="M128 32 L204 62 V128 C204 174 170 208 128 224 C86 208 52 174 52 128 V62 Z'
      . 'M128 54 L74 76 V128 C74 163 98 189 128 202 C158 189 182 163 182 128 V76 Z"/>'
      . '<circle fill="black" cx="128" cy="116" r="20"/>'
      . '<path fill="black" d="M118 130 L110 172 H146 L138 130 Z"/>'
      . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
  }

  #region Save

  public function maybe_handle_post() {
    if ( empty( $_POST['reeve_action'] ) ) {
      return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
      return;
    }
    check_admin_referer( self::NONCE_ACTION );

    $action = sanitize_key( wp_unslash( $_POST['reeve_action'] ) );

    if ( $action === 'save' ) {
      $this->save_settings();
    }
    elseif ( $action === 'generate_token' ) {
      $this->core->update_option( 'mcp_bearer_token', wp_generate_password( 48, false, false ) );
      $this->notice = __( 'A new bearer token was generated. Update any client that used the old one.', 'reeve' );
    }
    elseif ( $action === 'clear_token' ) {
      $this->core->update_option( 'mcp_bearer_token', '' );
      $this->notice = __( 'Bearer token cleared. Clients must now connect through OAuth.', 'reeve' );
    }
    elseif ( $action === 'revoke_app' ) {
      $this->revoke_app();
    }
    elseif ( $action === 'self_test' ) {
      // Parked in a transient rather than carried through the redirect: the result is
      // several sentences and has no business in a query string.
      set_transient( 'reeve_setup_report', REEVE_SelfTest::report(), 5 * MINUTE_IN_SECONDS );
    }
    elseif ( $action === 'clear_activity' ) {
      REEVE_Activity::clear();
      $this->notice = __( 'Activity history cleared.', 'reeve' );
    }
    elseif ( $action === 'create_key' ) {
      $this->create_key();
    }
    elseif ( $action === 'revoke_key' ) {
      $id = isset( $_POST['key_id'] ) ? sanitize_key( wp_unslash( $_POST['key_id'] ) ) : '';
      $this->notice = REEVE_Tokens::revoke( $id )
        ? __( 'Key revoked. Any client using it is now locked out.', 'reeve' )
        : __( 'That key no longer exists.', 'reeve' );
    }

    // Redirect so a refresh does not repeat the action, and carry the notice across.
    $url = add_query_arg( [
      'page' => self::PAGE_SLUG,
      'reeve_notice' => $this->notice ? rawurlencode( $this->notice ) : null,
    ], admin_url( 'admin.php' ) );
    wp_safe_redirect( $url );
    exit;
  }

  /**
  * Mint a key and park the plaintext in a short-lived transient.
  *
  * It has to survive exactly one redirect and then stop existing. Putting it in the
  * query string would write the secret into the browser history, the server access log,
  * and any referrer header the next page sends.
  */
  private function create_key(): void {
    $label = isset( $_POST['key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['key_label'] ) ) : '';
    $level = isset( $_POST['key_level'] ) ? sanitize_key( wp_unslash( $_POST['key_level'] ) ) : 'readonly';
    $days = isset( $_POST['key_expires'] ) ? (int) $_POST['key_expires'] : 0;

    $tools = [];
    if ( !empty( $_POST['key_tools'] ) ) {
      // A textarea of names rather than a checklist: the tool set changes with which
      // groups are switched on, and a stale checklist is worse than a typed list the
      // person can copy out of tools/list.
      $raw = sanitize_textarea_field( wp_unslash( $_POST['key_tools'] ) );
      $tools = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ) );
    }

    $key = REEVE_Tokens::create( $label, $level, $days, $tools );
    set_transient( 'reeve_new_key', $key['secret'], MINUTE_IN_SECONDS );
    $this->notice = __( 'Key created. Copy it now: it is stored hashed and cannot be shown again.', 'reeve' );
  }

  private function save_settings() {
    $roles = [ 'admin', 'readwrite', 'readonly' ];
    $role = isset( $_POST['mcp_role'] ) ? sanitize_key( wp_unslash( $_POST['mcp_role'] ) ) : 'admin';

    $options = $this->core->get_all_options( true );
    $options['mcp_role'] = in_array( $role, $roles, true ) ? $role : 'admin';
    $options['mcp_tools_core'] = !empty( $_POST['mcp_tools_core'] );
    $options['mcp_tools_admin'] = !empty( $_POST['mcp_tools_admin'] );
    $options['mcp_tools_rest'] = !empty( $_POST['mcp_tools_rest'] );
    $options['mcp_tools_woo'] = !empty( $_POST['mcp_tools_woo'] );
    $options['mcp_debug_mode'] = !empty( $_POST['mcp_debug_mode'] );
    $options['mcp_activity_log'] = !empty( $_POST['mcp_activity_log'] );
    $options['mcp_change_journal'] = !empty( $_POST['mcp_change_journal'] );

    // The token is only rewritten when the field was actually submitted, so saving the
    // rest of the form never silently drops it.
    if ( isset( $_POST['mcp_bearer_token'] ) ) {
      $token = trim( sanitize_text_field( wp_unslash( $_POST['mcp_bearer_token'] ) ) );
      $options['mcp_bearer_token'] = $token;
    }

    $this->core->update_options( $options );
    $this->notice = __( 'Settings saved.', 'reeve' );
  }

  private function revoke_app() {
    $id = isset( $_POST['app_id'] ) ? (int) $_POST['app_id'] : 0;
    if ( $id <= 0 ) {
      return;
    }
    $oauth = $this->oauth();
    if ( !$oauth ) {
      return;
    }
    $request = new WP_REST_Request( 'DELETE' );
    $request->set_param( 'id', $id );
    $oauth->handle_apps_revoke( $request );
    $this->notice = __( 'Access revoked for that app.', 'reeve' );
  }

  #endregion

  #region Render

  /**
  * The server's OAuth instance. Never construct a second one: its constructor
  * registers REST routes and a parse_request handler, so a duplicate would hook
  * everything twice.
  */
  private function oauth() {
    return $this->core->server ? $this->core->server->get_oauth() : null;
  }

  private function endpoint_url() {
    return get_rest_url( null, 'mcp/v1/http' );
  }

  public function render() {
    if ( !current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to view this page.', 'reeve' ) );
    }

    $options = $this->core->get_all_options( true );
    $token = (string) $options['mcp_bearer_token'];
    $notice = isset( $_GET['reeve_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['reeve_notice'] ) ) : '';
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'MCP Server', 'reeve' ); ?></h1>

      <?php if ( $notice !== '' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
      <?php endif; ?>

      <p><?php esc_html_e( 'Connect an AI agent to this site. Point the client at the endpoint below.', 'reeve' ); ?></p>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><?php esc_html_e( 'Endpoint', 'reeve' ); ?></th>
          <td>
            <input type="text" class="large-text code" readonly
              value="<?php echo esc_attr( $this->endpoint_url() ); ?>"
              onfocus="this.select()">
            <p class="description">
              <?php esc_html_e( 'Clients that support OAuth need nothing else: they will send you to a WordPress login and a consent screen. Clients that cannot do OAuth use the bearer token below.', 'reeve' ); ?>
            </p>
          </td>
        </tr>
      </table>

      <?php $this->render_setup(); ?>

      <form method="post">
        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
        <input type="hidden" name="reeve_action" value="save">

        <h2 class="title"><?php esc_html_e( 'Access', 'reeve' ); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="mcp_bearer_token"><?php esc_html_e( 'Bearer token', 'reeve' ); ?></label></th>
            <td>
              <input type="text" id="mcp_bearer_token" name="mcp_bearer_token" class="large-text code"
                value="<?php echo esc_attr( $token ); ?>" autocomplete="off" spellcheck="false">
              <p class="description">
                <?php esc_html_e( 'Optional. A static token for clients that cannot do OAuth, such as a local CLI agent. Leave empty to require OAuth. Treat it like a password: it grants the access level selected below.', 'reeve' ); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Token access level', 'reeve' ); ?></th>
            <td>
              <fieldset>
                <?php
                $levels = [
                  'admin' => __( 'Admin. Every tool, including deleting content and changing users and options.', 'reeve' ),
                  'readwrite' => __( 'Read and write. Create and update, but no destructive tools.', 'reeve' ),
                  'readonly' => __( 'Read only. Nothing on the site can be changed.', 'reeve' ),
                ];
                foreach ( $levels as $value => $label ) : ?>
                  <label style="display:block;margin-bottom:6px">
                    <input type="radio" name="mcp_role" value="<?php echo esc_attr( $value ); ?>"
                      <?php checked( $options['mcp_role'], $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                  </label>
                <?php endforeach; ?>
              </fieldset>
              <p class="description">
                <?php esc_html_e( 'Applies to the bearer token only. OAuth connections always act as the administrator who approved them.', 'reeve' ); ?>
              </p>
            </td>
          </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Tools', 'reeve' ); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e( 'Available tools', 'reeve' ); ?></th>
            <td>
              <label style="display:block;margin-bottom:6px">
                <input type="checkbox" name="mcp_tools_core" value="1" <?php checked( !empty( $options['mcp_tools_core'] ) ); ?>>
                <?php esc_html_e( 'WordPress tools (posts, media, users, terms, comments, options, blocks)', 'reeve' ); ?>
              </label>
              <label style="display:block;margin-bottom:6px">
                <input type="checkbox" name="mcp_tools_admin" value="1" <?php checked( !empty( $options['mcp_tools_admin'] ) ); ?>>
                <?php esc_html_e( 'Site administration (plugins, themes, menus, widgets, settings, permalinks, site health)', 'reeve' ); ?>
              </label>
              <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                <label style="display:block;margin-bottom:6px">
                  <input type="checkbox" name="mcp_tools_woo" value="1" <?php checked( !empty( $options['mcp_tools_woo'] ) ); ?>>
                  <?php esc_html_e( 'WooCommerce (products, stock, orders, customers, sales figures)', 'reeve' ); ?>
                </label>
              <?php endif; ?>
              <label style="display:block">
                <input type="checkbox" name="mcp_tools_rest" value="1" <?php checked( !empty( $options['mcp_tools_rest'] ) ); ?>>
                <?php esc_html_e( 'Generate tools from this site\'s REST API routes', 'reeve' ); ?>
              </label>
              <p class="description">
                <?php esc_html_e( 'Administration tools install code on this site, so they are off by default. Installs are restricted to the wordpress.org repository, deletions take two steps, and they are refused entirely over the URL-token endpoint.', 'reeve' ); ?>
              </p>
              <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                <p class="description">
                  <?php esc_html_e( 'The WooCommerce tools are separate because the risk is a different shape: they read customer names, email addresses and delivery addresses and hand them to a model. Refunds are deliberately not included, and any action that emails a customer says so in its own description.', 'reeve' ); ?>
                </p>
              <?php endif; ?>
              <p class="description">
                <?php esc_html_e( 'The REST option exposes a large, generic surface. The curated WordPress tools are usually the better choice.', 'reeve' ); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Debug logging', 'reeve' ); ?></th>
            <td>
              <label>
                <input type="checkbox" name="mcp_debug_mode" value="1" <?php checked( !empty( $options['mcp_debug_mode'] ) ); ?>>
                <?php esc_html_e( 'Write protocol traffic to the PHP error log', 'reeve' ); ?>
              </label>
              <p class="description">
                <?php esc_html_e( 'Verbose. It also shortens the idle stream timeout from 180 to 30 seconds, so leave it off in normal use.', 'reeve' ); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Activity history', 'reeve' ); ?></th>
            <td>
              <label>
                <input type="checkbox" name="mcp_activity_log" value="1" <?php checked( !empty( $options['mcp_activity_log'] ) ); ?>>
                <?php esc_html_e( 'Record the last 100 tool calls, including refused ones', 'reeve' ); ?>
              </label>
              <p class="description">
                <?php esc_html_e( 'Without this an agent works with no visible record: you can see that something changed, but not what did it or when. Refusals are recorded too, since those are the interesting ones.', 'reeve' ); ?>
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e( 'Change journal', 'reeve' ); ?></th>
            <td>
              <label>
                <input type="checkbox" name="mcp_change_journal" value="1" <?php checked( !empty( $options['mcp_change_journal'] ) ); ?>>
                <?php esc_html_e( 'Remember previous values so changes can be reverted', 'reeve' ); ?>
              </label>
              <p class="description">
                <?php esc_html_e( 'Records what a setting or post said before an agent changed it, and lets the change be put back with the wp_undo_change tool. Only writes made through this API are recorded, never your own. Values that look like credentials are never stored.', 'reeve' ); ?>
              </p>
            </td>
          </tr>
        </table>

        <?php submit_button(); ?>
      </form>

      <h2 class="title"><?php esc_html_e( 'Token actions', 'reeve' ); ?></h2>
      <p>
        <form method="post" style="display:inline">
          <?php wp_nonce_field( self::NONCE_ACTION ); ?>
          <input type="hidden" name="reeve_action" value="generate_token">
          <button type="submit" class="button"><?php esc_html_e( 'Generate a new token', 'reeve' ); ?></button>
        </form>
        <?php if ( $token !== '' ) : ?>
          <form method="post" style="display:inline">
            <?php wp_nonce_field( self::NONCE_ACTION ); ?>
            <input type="hidden" name="reeve_action" value="clear_token">
            <button type="submit" class="button"><?php esc_html_e( 'Clear the token', 'reeve' ); ?></button>
          </form>
        <?php endif; ?>
      </p>

      <h2 class="title"><?php esc_html_e( 'Keys', 'reeve' ); ?></h2>
      <?php $this->render_keys(); ?>

      <h2 class="title"><?php esc_html_e( 'Connected apps', 'reeve' ); ?></h2>
      <?php $this->render_apps(); ?>

      <h2 class="title"><?php esc_html_e( 'Recent activity', 'reeve' ); ?></h2>
      <?php $this->render_activity(); ?>
    </div>
    <?php
  }

  private function render_keys(): void {
    $fresh = get_transient( 'reeve_new_key' );
    if ( $fresh ) {
      delete_transient( 'reeve_new_key' );
      ?>
      <div class="notice notice-success inline" style="padding:12px">
        <p><strong><?php esc_html_e( 'Your new key. This is the only time it is shown.', 'reeve' ); ?></strong></p>
        <p><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $fresh ); ?>"></p>
      </div>
      <?php
    }

    $keys = REEVE_Tokens::all();
    ?>
    <p class="description">
      <?php esc_html_e( 'The bearer token above is one secret with one access level, shared by every client. A key is narrower: it carries a label so you can tell clients apart in the activity list, it can expire on its own, and it can be limited to a named list of tools. Keys are stored hashed, so a key is shown once and never again.', 'reeve' ); ?>
    </p>

    <?php if ( $keys ) : ?>
      <table class="widefat striped" style="margin-bottom:16px">
        <thead>
          <tr>
            <th><?php esc_html_e( 'Label', 'reeve' ); ?></th>
            <th><?php esc_html_e( 'Access', 'reeve' ); ?></th>
            <th><?php esc_html_e( 'Limited to', 'reeve' ); ?></th>
            <th><?php esc_html_e( 'Expires', 'reeve' ); ?></th>
            <th><?php esc_html_e( 'Last used', 'reeve' ); ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $keys as $key ) : $expired = REEVE_Tokens::is_expired( $key ); ?>
          <tr<?php echo $expired ? ' style="opacity:.55"' : ''; ?>>
            <td><strong><?php echo esc_html( $key['label'] ); ?></strong></td>
            <td><?php echo esc_html( $key['level'] ); ?></td>
            <td>
              <?php if ( empty( $key['tools'] ) ) : ?>
                <span style="color:#646970"><?php esc_html_e( 'everything its access level allows', 'reeve' ); ?></span>
              <?php else : ?>
                <code style="font-size:11px"><?php echo esc_html( implode( ', ', $key['tools'] ) ); ?></code>
              <?php endif; ?>
            </td>
            <td>
              <?php if ( empty( $key['expires'] ) ) : ?>
                <?php esc_html_e( 'never', 'reeve' ); ?>
              <?php elseif ( $expired ) : ?>
                <strong><?php esc_html_e( 'expired', 'reeve' ); ?></strong>
              <?php else : ?>
                <?php echo esc_html( wp_date( 'M j, Y', (int) $key['expires'] ) ); ?>
              <?php endif; ?>
            </td>
            <td>
              <?php echo empty( $key['last_used'] )
                ? esc_html__( 'never', 'reeve' )
                : esc_html( $this->ago( (int) $key['last_used'] ) ); ?>
            </td>
            <td>
              <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                <input type="hidden" name="reeve_action" value="revoke_key">
                <input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>">
                <button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'reeve' ); ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post">
      <?php wp_nonce_field( self::NONCE_ACTION ); ?>
      <input type="hidden" name="reeve_action" value="create_key">
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="key_label"><?php esc_html_e( 'Label', 'reeve' ); ?></label></th>
          <td><input type="text" id="key_label" name="key_label" class="regular-text" placeholder="<?php esc_attr_e( 'Laptop, deploy script, the intern', 'reeve' ); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="key_level"><?php esc_html_e( 'Access', 'reeve' ); ?></label></th>
          <td>
            <select id="key_level" name="key_level">
              <option value="readonly"><?php esc_html_e( 'Read only', 'reeve' ); ?></option>
              <option value="readwrite"><?php esc_html_e( 'Read and write content', 'reeve' ); ?></option>
              <option value="admin"><?php esc_html_e( 'Full administration', 'reeve' ); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="key_expires"><?php esc_html_e( 'Expires after', 'reeve' ); ?></label></th>
          <td>
            <select id="key_expires" name="key_expires">
              <option value="0"><?php esc_html_e( 'Never', 'reeve' ); ?></option>
              <option value="1"><?php esc_html_e( 'A day', 'reeve' ); ?></option>
              <option value="7"><?php esc_html_e( 'A week', 'reeve' ); ?></option>
              <option value="30"><?php esc_html_e( '30 days', 'reeve' ); ?></option>
              <option value="90"><?php esc_html_e( '90 days', 'reeve' ); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="key_tools"><?php esc_html_e( 'Limit to these tools', 'reeve' ); ?></label></th>
          <td>
            <textarea id="key_tools" name="key_tools" rows="3" class="large-text code" placeholder="wp_get_posts wp_get_comments"></textarea>
            <p class="description">
              <?php esc_html_e( 'Optional. Names separated by spaces, commas or new lines. Leave empty to allow everything the access level above allows. A key limited this way sees only those tools, so an agent is never offered something that will be refused. mcp_ping always works.', 'reeve' ); ?>
            </p>
          </td>
        </tr>
      </table>
      <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create key', 'reeve' ); ?></button></p>
    </form>
    <?php
  }

  private function render_apps() {
    $oauth = $this->oauth();
    if ( !$oauth ) {
      echo '<p>' . esc_html__( 'The MCP server is not running, so connected apps cannot be listed.', 'reeve' ) . '</p>';
      return;
    }
    $response = $oauth->handle_apps_list();
    $data = $response instanceof WP_REST_Response ? $response->get_data() : [];
    $apps = isset( $data['apps'] ) && is_array( $data['apps'] ) ? $data['apps'] : [];

    if ( empty( $apps ) ) {
      echo '<p>' . esc_html__( 'No app has connected through OAuth yet.', 'reeve' ) . '</p>';
      return;
    }
    ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php esc_html_e( 'App', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Account', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Connected', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Last used', 'reeve' ); ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $apps as $app ) : ?>
          <tr>
            <td><?php echo esc_html( $app['client_name'] ); ?></td>
            <td><?php echo esc_html( $app['user_display'] . ' (' . $app['user_login'] . ')' ); ?></td>
            <td><?php echo esc_html( $this->format_date( $app['created'] ) ); ?></td>
            <td><?php echo esc_html( $app['last_used'] ? $this->format_date( $app['last_used'] ) : __( 'Never', 'reeve' ) ); ?></td>
            <td>
              <form method="post">
                <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                <input type="hidden" name="reeve_action" value="revoke_app">
                <input type="hidden" name="app_id" value="<?php echo esc_attr( $app['id'] ); ?>">
                <button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'reeve' ); ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
  }

  /**
  * Setup: how to connect each kind of client, and a readiness report.
  *
  * Both halves exist because of the same failure. A client that cannot configure itself
  * reports "couldn't determine the server settings" and names none of the half dozen
  * causes, so the site owner is left guessing. The report walks the same steps a client
  * does and shows which one breaks; the snippets remove the other half of the guessing,
  * which is what exactly to paste where.
  */
  private function render_setup(): void {
    $endpoint = rest_url( 'mcp/v1/http' );
    $fallback = home_url( '/index.php?rest_route=/mcp/v1/http' );
    $token = (string) $this->core->get_option( 'mcp_bearer_token' );
    $token_display = $token !== '' ? $token : 'YOUR_TOKEN';
    ?>
    <h2 class="title"><?php esc_html_e( 'Connect a client', 'reeve' ); ?></h2>

    <h3 style="margin-bottom:4px"><?php esc_html_e( 'Claude Desktop, or any client that supports OAuth', 'reeve' ); ?></h3>
    <p class="description" style="margin-top:0">
      <?php esc_html_e( 'Add a connector and give it this address. It will send you to a WordPress login and then a consent screen. There is no token to copy and nothing to configure.', 'reeve' ); ?>
    </p>
    <input type="text" class="large-text code" readonly onfocus="this.select()"
      value="<?php echo esc_attr( $endpoint ); ?>">

    <h3 style="margin-bottom:4px"><?php esc_html_e( 'Claude Code', 'reeve' ); ?></h3>
    <p class="description" style="margin-top:0">
      <?php esc_html_e( 'Run this in your project. It stores the token in Claude Code\'s own configuration.', 'reeve' ); ?>
    </p>
    <textarea class="large-text code" rows="2" readonly onfocus="this.select()"><?php
      echo esc_textarea( sprintf(
        "claude mcp add --transport http wordpress %s \\\n  --header \"Authorization: Bearer %s\"",
        $endpoint,
        $token_display
      ) );
    ?></textarea>

    <h3 style="margin-bottom:4px"><?php esc_html_e( 'Anything else that reads a JSON config', 'reeve' ); ?></h3>
    <textarea class="large-text code" rows="10" readonly onfocus="this.select()"><?php
      echo esc_textarea( wp_json_encode( [
        'mcpServers' => [
          'wordpress' => [
            'type' => 'http',
            'url' => $endpoint,
            'headers' => [ 'Authorization' => 'Bearer ' . $token_display ],
          ],
        ],
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
    ?></textarea>
    <?php if ( $token === '' ) : ?>
      <p class="description">
        <strong><?php esc_html_e( 'No bearer token is set yet.', 'reeve' ); ?></strong>
        <?php esc_html_e( 'Generate one below and this snippet will fill itself in. OAuth clients do not need one.', 'reeve' ); ?>
      </p>
    <?php endif; ?>

    <p class="description">
      <?php
      printf(
        /* translators: %s: the alternative endpoint URL. */
        esc_html__( 'If pretty permalinks are off or broken on this site, clients can use %s instead. It works regardless of rewrite rules.', 'reeve' ),
        '<code>' . esc_html( $fallback ) . '</code>'
      );
      ?>
    </p>

    <h2 class="title"><?php esc_html_e( 'Is this site ready', 'reeve' ); ?></h2>
    <form method="post">
      <?php wp_nonce_field( self::NONCE_ACTION ); ?>
      <input type="hidden" name="reeve_action" value="self_test">
      <button type="submit" class="button button-secondary"><?php esc_html_e( 'Run the setup checks', 'reeve' ); ?></button>
    </form>
    <?php $this->render_report(); ?>
    <?php
  }

  /**
  * The result of the last readiness run. Read once and cleared, so a stale result never
  * sits on the page looking like live status.
  */
  private function render_report(): void {
    $checks = get_transient( 'reeve_setup_report' );
    if ( !is_array( $checks ) ) {
      echo '<p class="description">' . esc_html__( 'Walks the same steps a client does when it configures itself, and shows which one fails. Safe to run at any time: it sends no credentials anywhere except to this site.', 'reeve' ) . '</p>';
      return;
    }
    delete_transient( 'reeve_setup_report' );

    $style = [
      'ok' => [ '#00a32a', "\u{2713}" ],
      'warn' => [ '#dba617', '!' ],
      'fail' => [ '#d63638', "\u{2715}" ],
      'skip' => [ '#787c82', "\u{2013}" ],
    ];
    ?>
    <table class="widefat striped" style="margin-top:12px;max-width:900px">
      <tbody>
        <?php foreach ( $checks as $check ) :
          list( $colour, $mark ) = $style[ $check['status'] ] ?? $style['skip']; ?>
          <tr>
            <td style="width:28px;color:<?php echo esc_attr( $colour ); ?>;font-weight:700;vertical-align:top">
              <?php echo esc_html( $mark ); ?>
            </td>
            <td>
              <strong><?php echo esc_html( $check['label'] ); ?></strong>
              <?php if ( !empty( $check['detail'] ) ) : ?>
                <p style="margin:4px 0 0;color:#50575e"><?php echo esc_html( $check['detail'] ); ?></p>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
  }

  /**
  * A compact age for the activity table. The full site-formatted date wrapped onto two
  * lines in that column, and for a list of things that mostly happened in the last few
  * minutes, "3 mins ago" is the more useful reading anyway.
  */
  private function ago( int $timestamp ): string {
    $now = time();
    if ( $timestamp > $now - 10 ) {
      return __( 'just now', 'reeve' );
    }
    if ( $timestamp > $now - DAY_IN_SECONDS ) {
      /* translators: %s: human-readable time difference, e.g. "5 mins". */
      return sprintf( __( '%s ago', 'reeve' ), human_time_diff( $timestamp, $now ) );
    }
    return wp_date( 'M j, H:i', $timestamp );
  }

  private function render_activity(): void {
    if ( empty( $this->core->get_option( 'mcp_activity_log' ) ) ) {
      echo '<p>' . esc_html__( 'Activity history is switched off, so nothing is being recorded.', 'reeve' ) . '</p>';
      return;
    }

    $entries = REEVE_Activity::recent( 25 );
    if ( empty( $entries ) ) {
      echo '<p>' . esc_html__( 'No tool calls recorded yet. Anything an agent does will appear here.', 'reeve' ) . '</p>';
      return;
    }
    ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php esc_html_e( 'When', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Tool', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Target', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Client', 'reeve' ); ?></th>
          <th><?php esc_html_e( 'Result', 'reeve' ); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $entries as $e ) : ?>
          <tr>
            <td style="white-space:nowrap"><?php echo esc_html( $this->ago( (int) $e['t'] ) ); ?></td>
            <td><code><?php echo esc_html( $e['tool'] ); ?></code></td>
            <td><?php echo $e['target'] !== '' ? '<code>' . esc_html( $e['target'] ) . '</code>' : '&mdash;'; ?></td>
            <td><?php echo esc_html( $e['who'] ); ?></td>
            <td>
              <?php if ( !empty( $e['ok'] ) ) : ?>
                <span style="color:#00a32a"><?php esc_html_e( 'Done', 'reeve' ); ?></span>
                <span style="color:#787c82"><?php echo esc_html( sprintf( '(%dms)', (int) $e['ms'] ) ); ?></span>
              <?php else : ?>
                <span style="color:#d63638"><?php esc_html_e( 'Refused', 'reeve' ); ?></span>
                <?php if ( $e['err'] !== '' ) : ?>
                  <span style="color:#787c82"><?php echo esc_html( $e['err'] ); ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p>
      <form method="post" style="display:inline">
        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
        <input type="hidden" name="reeve_action" value="clear_activity">
        <button type="submit" class="button"><?php esc_html_e( 'Clear the history', 'reeve' ); ?></button>
      </form>
    </p>
    <p class="description">
      <?php esc_html_e( 'The last 100 calls, newest first. This is a record for you to read, not a security log: it lives in an option, so it is not tamper-proof and a burst of simultaneous calls can lose an entry. Hook reeve_tool_called if you need a real audit trail.', 'reeve' ); ?>
    </p>
    <?php
  }

  /**
  * Stored timestamps are UTC. wp_date() renders them in the site's timezone, which is
  * what an admin reading this table expects to see.
  */
  private function format_date( $value ) {
    $timestamp = strtotime( (string) $value );
    if ( !$timestamp ) {
      return (string) $value;
    }
    return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
  }

  #endregion
}
