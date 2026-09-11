<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The whole admin surface: one page, four tabs.
*
* Plain PHP and core's own form styles on purpose. The upstream plugin shipped a 1.1 MB
* minified React bundle with no source in the repository, which made the settings screen
* the one part of the plugin nobody could edit. Everything here is readable and fits on
* a screen.
*
* The tabs are query arguments rather than anything client side, so the screen works with
* JavaScript off and every tab is a link somebody can bookmark or send to a colleague.
*/
class GMCP_Settings {

  const PAGE_SLUG = 'guarded-mcp-settings';
  const NONCE_ACTION = 'gmcp_save_settings';

  /**
  * The tabs, in the order they appear, and the order somebody meets them: connect a
  * client, decide who may connect, decide what they may touch, then read what they did.
  */
  const TABS = [ 'connect', 'access', 'tools', 'logs' ];

  private $core = null;
  private $notice = null;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'admin_menu', [ $this, 'add_menu' ] );
    add_action( 'admin_init', [ $this, 'maybe_handle_post' ] );
    // A plugin whose whole configuration lives on one screen should link to it from
    // the row people are looking at when they activate it.
    add_filter( 'plugin_action_links_' . plugin_basename( GMCP_ENTRY ), [ $this, 'action_links' ] );
    // Registering the screen option is not enough to make it persist. WordPress saves
    // only the options it recognises, and for anything else set_screen_options() asks
    // this filter and discards the value when nothing answers. Without it the panel
    // renders, accepts a number, reloads, and shows the old page size: a control that
    // looks like it works. Registered here rather than on the screen's load hook,
    // because the save runs before that hook does.
    add_filter( 'set_screen_option_gmcp_audit_per_page', static function ( $status, $option, $value ) {
      return max( 1, min( 500, (int) $value ) );
    }, 10, 3 );
  }

  public function action_links( $links ) {
    $settings = sprintf(
      '<a href="%s">%s</a>',
      esc_url( self::page_url() ),
      esc_html__( 'Settings', 'guarded-mcp' )
    );
    array_unshift( $links, $settings );
    return $links;
  }

  /**
  * Where the settings screen lives, in one place so a move does not strand links.
  *
  * The tab is omitted when empty, which lands on the first one. Callers that do not
  * care which tab they get should keep passing nothing.
  */
  public static function page_url( string $tab = '' ): string {
    $args = [ 'page' => self::PAGE_SLUG ];
    if ( $tab !== '' ) {
      $args['tab'] = $tab;
    }
    return add_query_arg( $args, admin_url( 'admin.php' ) );
  }

  public function add_menu() {
    // Top level rather than buried under Settings. This is the only screen the plugin
    // has, it is the first thing anyone needs after activating, and "Settings, then
    // scroll" is a poor answer to "where do I connect my agent".
    $hook = add_menu_page(
      __( 'MCP Server', 'guarded-mcp' ),
      __( 'MCP Server', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_SLUG,
      [ $this, 'render' ],
      self::menu_icon(),
      80 // Just above Settings.
    );
    // Hooked to this screen's own admin_head so the rules load on this page and nowhere
    // else. There is no stylesheet to enqueue and no build step to produce one; what is
    // here is the handful of things core has no class for.
    add_action( 'admin_head-' . $hook, [ $this, 'print_styles' ] );
    // Screen Options gives the reader the per-page control the list table reads. It has
    // to be registered on the screen's load hook: by the time render() runs, the Screen
    // Options panel has already been built and an option added then is never shown.
    add_action( 'load-' . $hook, [ $this, 'add_screen_options' ] );
  }

  /**
  * The per-page setting for the audit log.
  *
  * Registered on every tab rather than only the log, because the load hook fires before
  * the tab is known to matter and WordPress simply shows no panel on screens whose
  * option nothing reads. Guarding it would add a branch to save nothing.
  */
  public function add_screen_options(): void {
    add_screen_option( 'per_page', [
      'label' => __( 'Audit entries per page', 'guarded-mcp' ),
      'default' => 25,
      'option' => 'gmcp_audit_per_page',
    ] );
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

  /**
  * The few rules core does not already provide.
  *
  * The colours are WordPress's own admin palette, the same values that were previously
  * repeated as inline style attributes throughout this file. Status is never carried by
  * colour alone: every coloured mark below is accompanied by a word, visible or for a
  * screen reader.
  */
  public function print_styles(): void {
    ?>
    <style>
      /*
      Core sets `.form-table td fieldset label` to inline-block, which is three
      selectors deep and beats a bare class. These choices have to stack, so the rule
      that puts them back has to be at least as specific. The markup this replaced
      carried display:block as an inline style on every label, which won for the same
      reason without saying so.
      */
      .gmcp-choices label,
      .form-table td fieldset.gmcp-choices label { display: block; margin-bottom: 6px; }
      .gmcp-actions { margin: 12px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
      .gmcp-actions form { display: inline; }
      .gmcp-recipe { max-width: 800px; margin: 0 0 24px; }
      .gmcp-recipe h3 { margin: 0 0 4px; }
      .gmcp-recipe p.description { margin: 0 0 8px; }
      .gmcp-table { max-width: 900px; }
      .gmcp-mark { width: 28px; font-weight: 700; vertical-align: top; }
      .gmcp-ok { color: #00a32a; }
      .gmcp-warn { color: #dba617; }
      .gmcp-fail { color: #d63638; }
      .gmcp-muted { color: #787c82; }
      .gmcp-nowrap { white-space: nowrap; }
      .gmcp-expired td { opacity: .55; }
      .gmcp-detail { color: #787c82; font-size: 12px; }
      /* Darker than .gmcp-detail on purpose: what a call changed outranks what it said. */
      .gmcp-change { color: #1d2327; font-size: 12px; }
      /* Long enough to wrap on a narrow screen rather than widen the table. */
      .gmcp-hash { font-size: 11px; word-break: break-all; }
      /* A refusal is worth spotting from across the table, not by reading it. */
      tr.gmcp-row-refused td { box-shadow: inset 3px 0 0 #d63638; }
      .gmcp-args { white-space: pre-wrap; font-size: 11px; margin: 4px 0 0; }
      .gmcp-summary { cursor: pointer; color: #2271b1; font-size: 12px; }
      .gmcp-limits { font-size: 11px; }
      .gmcp-intro { max-width: 800px; }
    </style>
    <?php
  }

  #region Save

  /**
  * Which option this screen writes, and how each is read out of the request.
  *
  * Split out because a form now covers one tab rather than the whole screen. An
  * unchecked box and a box that was never rendered look identical in $_POST, so without
  * a record of what a given form was responsible for, saving the bearer token would read
  * every tool group as unticked and switch them all off.
  *
  * A form declares its keys in a hidden field. That field travels in the request like
  * any other, so it is intersected with this list rather than trusted: a name that is
  * not here writes nothing.
  */
  private static function field_kinds(): array {
    return [
      'mcp_bearer_token' => 'text',
      'mcp_role' => 'role',
      'mcp_tools_core' => 'bool',
      'mcp_tools_admin' => 'bool',
      'mcp_tools_rest' => 'bool',
      'mcp_tools_woo' => 'bool',
      'mcp_tools_elementor' => 'bool',
      'mcp_debug_mode' => 'bool',
      'mcp_activity_log' => 'bool',
      'mcp_audit_days' => 'days',
      'mcp_change_journal' => 'bool',
    ];
  }

  public function maybe_handle_post() {
    if ( empty( $_POST['gmcp_action'] ) ) {
      return;
    }
    if ( !current_user_can( 'manage_options' ) ) {
      return;
    }
    check_admin_referer( self::NONCE_ACTION );

    $action = sanitize_key( wp_unslash( $_POST['gmcp_action'] ) );

    if ( $action === 'save' ) {
      $this->save_settings();
    }
    elseif ( $action === 'generate_token' ) {
      $this->core->update_option( 'mcp_bearer_token', wp_generate_password( 48, false, false ) );
      $this->notice = __( 'A new bearer token was generated. Update any client that used the old one.', 'guarded-mcp' );
    }
    elseif ( $action === 'clear_token' ) {
      $this->core->update_option( 'mcp_bearer_token', '' );
      $this->notice = __( 'Bearer token cleared. Clients must now connect through OAuth.', 'guarded-mcp' );
    }
    elseif ( $action === 'revoke_app' ) {
      $this->revoke_app();
    }
    elseif ( $action === 'self_test' ) {
      // Parked in a transient rather than carried through the redirect: the result is
      // several sentences and has no business in a query string.
      set_transient( 'gmcp_setup_report', GMCP_SelfTest::report(), 5 * MINUTE_IN_SECONDS );
    }
    elseif ( $action === 'clear_activity' ) {
      GMCP_Audit::clear();
      $this->notice = __( 'Audit log cleared.', 'guarded-mcp' );
    }
    elseif ( $action === 'verify_audit' ) {
      // The full walk reads every recorded argument back out of the database, which on a
      // full table is tens of megabytes, so it is a button rather than something every
      // page load pays for.
      $chain = GMCP_Audit::verify( 'all' );
      $this->notice = $chain['ok']
        ? sprintf(
            /* translators: 1: entries verified, 2: entries carried over unchained. */
            __( 'Checked the whole chain: %1$d entries intact, %2$d carried over from before the log was chained.', 'guarded-mcp' ),
            $chain['checked'], $chain['imported'] )
        : sprintf(
            /* translators: 1: entry id, 2: the reason. */
            __( 'The chain breaks at entry %1$d: %2$s', 'guarded-mcp' ),
            $chain['broken_at'], $chain['reason'] );
    }
    elseif ( $action === 'prune_audit' ) {
      $gone = GMCP_Audit::prune();
      $total = array_sum( $gone );
      $this->notice = $total
        ? sprintf(
            /* translators: 1: number removed for age, 2: for the row cap, 3: for the size cap. */
            __( 'Pruned %1$d entries past the retention window, %2$d over the entry limit and %3$d over the size limit.', 'guarded-mcp' ),
            $gone['age'], $gone['rows'], $gone['bytes']
          )
        : __( 'Nothing needed pruning.', 'guarded-mcp' );
    }
    elseif ( $action === 'create_key' ) {
      $this->create_key();
    }
    elseif ( $action === 'revoke_key' ) {
      $id = isset( $_POST['key_id'] ) ? sanitize_key( wp_unslash( $_POST['key_id'] ) ) : '';
      $this->notice = GMCP_Tokens::revoke( $id )
        ? __( 'Key revoked. Any client using it is now locked out.', 'guarded-mcp' )
        : __( 'That key no longer exists.', 'guarded-mcp' );
    }

    // Redirect so a refresh does not repeat the action, and carry the notice across.
    // The tab comes back with the form so the answer appears where the question was
    // asked, rather than bouncing to the first tab on every save.
    $tab = isset( $_POST['gmcp_tab'] ) ? sanitize_key( wp_unslash( $_POST['gmcp_tab'] ) ) : '';
    $url = add_query_arg(
      [ 'gmcp_notice' => $this->notice ? rawurlencode( $this->notice ) : null ],
      self::page_url( in_array( $tab, self::TABS, true ) ? $tab : '' )
    );
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
  /** Where a newly minted key waits for the one page load that shows it. */
  private static function new_key_transient(): string {
    return 'gmcp_new_key_' . get_current_user_id();
  }

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

    $key = GMCP_Tokens::create( $label, $level, $days, $tools );
    // Named for the person who minted it, so it is not a fixed target that anything else
    // can go and read. Belt to the option guard's braces: without a persistent object
    // cache a transient is an ordinary wp_options row, so for its sixty seconds the
    // plaintext really is in the database, which is the exact thing hashing the stored
    // keys exists to avoid.
    set_transient( self::new_key_transient(), $key['secret'], MINUTE_IN_SECONDS );
    $this->notice = __( 'Key created. Copy it now: it is stored hashed and cannot be shown again.', 'guarded-mcp' );
  }

  private function save_settings() {
    $kinds = self::field_kinds();
    $declared = isset( $_POST['gmcp_fields'] )
      ? preg_split( '/\s+/', (string) wp_unslash( $_POST['gmcp_fields'] ), -1, PREG_SPLIT_NO_EMPTY )
      : [];

    $options = $this->core->get_all_options( true );
    foreach ( $declared as $key ) {
      $key = sanitize_key( $key );
      if ( !isset( $kinds[ $key ] ) ) {
        continue;
      }
      if ( $kinds[ $key ] === 'bool' ) {
        $options[ $key ] = !empty( $_POST[ $key ] );
      }
      elseif ( $kinds[ $key ] === 'text' ) {
        $options[ $key ] = isset( $_POST[ $key ] )
          ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) )
          : '';
      }
      elseif ( $kinds[ $key ] === 'role' ) {
        $role = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
        $options[ $key ] = in_array( $role, [ 'admin', 'readwrite', 'readonly' ], true ) ? $role : 'admin';
      }
      elseif ( $kinds[ $key ] === 'days' ) {
        $options[ $key ] = isset( $_POST[ $key ] ) ? max( 1, min( 3650, (int) $_POST[ $key ] ) ) : 90;
      }
    }

    $this->core->update_options( $options );
    $this->notice = __( 'Settings saved.', 'guarded-mcp' );
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
    $this->notice = __( 'Access revoked for that app.', 'guarded-mcp' );
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

  private function current_tab(): string {
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    return in_array( $tab, self::TABS, true ) ? $tab : 'connect';
  }

  /**
  * The hidden pair every POST form on this screen carries: the action, and the tab to
  * come back to. The nonce is printed alongside so no form can forget one of the three.
  */
  private function form_head( string $action, string $tab ): void {
    wp_nonce_field( self::NONCE_ACTION );
    printf(
      '<input type="hidden" name="gmcp_action" value="%s"><input type="hidden" name="gmcp_tab" value="%s">',
      esc_attr( $action ),
      esc_attr( $tab )
    );
  }

  /** Tells the save which options this particular form is answerable for. */
  private function form_fields( array $keys ): void {
    printf( '<input type="hidden" name="gmcp_fields" value="%s">', esc_attr( implode( ' ', $keys ) ) );
  }

  public function render() {
    if ( !current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to view this page.', 'guarded-mcp' ) );
    }

    $options = $this->core->get_all_options( true );
    $tab = $this->current_tab();
    $notice = isset( $_GET['gmcp_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['gmcp_notice'] ) ) : '';

    $labels = [
      'connect' => __( 'Connect', 'guarded-mcp' ),
      'access' => __( 'Access', 'guarded-mcp' ),
      'tools' => __( 'Tools', 'guarded-mcp' ),
      'logs' => __( 'Logs', 'guarded-mcp' ),
    ];
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'MCP Server', 'guarded-mcp' ); ?></h1>

      <?php if ( $notice !== '' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
      <?php endif; ?>

      <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'MCP Server sections', 'guarded-mcp' ); ?>">
        <?php foreach ( self::TABS as $slug ) : $active = $slug === $tab; ?>
          <a href="<?php echo esc_url( self::page_url( $slug ) ); ?>"
             class="nav-tab<?php echo $active ? ' nav-tab-active' : ''; ?>"
             <?php echo $active ? 'aria-current="page"' : ''; ?>>
            <?php echo esc_html( $labels[ $slug ] ); ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php
      if ( $tab === 'access' ) {
        $this->render_access( $options );
      }
      elseif ( $tab === 'tools' ) {
        $this->render_tools( $options );
      }
      elseif ( $tab === 'logs' ) {
        $this->render_logs( $options );
      }
      else {
        $this->render_connect();
      }
      ?>
    </div>
    <?php
  }

  #endregion

  #region Connect

  /**
  * The endpoint, how to give it to each kind of client, and a readiness report.
  *
  * All three exist because of the same failure. A client that cannot configure itself
  * reports "couldn't determine the server settings" and names none of the half dozen
  * causes, so the site owner is left guessing. The report walks the same steps a client
  * does and shows which one breaks; the snippets remove the other half of the guessing,
  * which is what exactly to paste where.
  */
  private function render_connect(): void {
    $endpoint = $this->endpoint_url();
    $fallback = home_url( '/index.php?rest_route=/mcp/v1/http' );
    $token = (string) $this->core->get_option( 'mcp_bearer_token' );
    $token_display = $token !== '' ? $token : 'YOUR_TOKEN';
    ?>
    <p class="gmcp-intro"><?php esc_html_e( 'Connect an AI agent to this site. Point the client at the endpoint below.', 'guarded-mcp' ); ?></p>

    <h2 class="title"><?php esc_html_e( 'Endpoint', 'guarded-mcp' ); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="gmcp_endpoint"><?php esc_html_e( 'Address', 'guarded-mcp' ); ?></label></th>
        <td>
          <input type="text" id="gmcp_endpoint" class="large-text code" readonly
            value="<?php echo esc_attr( $endpoint ); ?>"
            onfocus="this.select()">
          <p class="description">
            <?php esc_html_e( 'Clients that support OAuth need nothing else: they will send you to a WordPress login and a consent screen. Clients that cannot do OAuth use the bearer token from the Access tab.', 'guarded-mcp' ); ?>
          </p>
          <p class="description">
            <?php
            printf(
              /* translators: %s: the alternative endpoint URL. */
              esc_html__( 'If pretty permalinks are off or broken on this site, clients can use %s instead. It works regardless of rewrite rules.', 'guarded-mcp' ),
              '<code>' . esc_html( $fallback ) . '</code>'
            );
            ?>
          </p>
        </td>
      </tr>
    </table>

    <h2 class="title"><?php esc_html_e( 'Connect a client', 'guarded-mcp' ); ?></h2>

    <div class="gmcp-recipe">
      <h3><?php esc_html_e( 'Claude Desktop, or any client that supports OAuth', 'guarded-mcp' ); ?></h3>
      <?php // No second copy of the address here on purpose: it is the field above, and
            // two copy targets holding the same string is how somebody ends up pasting
            // the stale one after a site move. ?>
      <p class="description">
        <?php esc_html_e( 'Add a connector and give it the address above. It will send you to a WordPress login and then a consent screen. There is no token to copy and nothing else to configure.', 'guarded-mcp' ); ?>
      </p>
    </div>

    <div class="gmcp-recipe">
      <h3><?php esc_html_e( 'Claude Code', 'guarded-mcp' ); ?></h3>
      <p class="description">
        <?php esc_html_e( 'Run this in your project. It stores the token in Claude Code\'s own configuration.', 'guarded-mcp' ); ?>
      </p>
      <label class="screen-reader-text" for="gmcp_recipe_cli"><?php esc_html_e( 'Command for Claude Code', 'guarded-mcp' ); ?></label>
      <textarea id="gmcp_recipe_cli" class="large-text code" rows="2" readonly onfocus="this.select()"><?php
        echo esc_textarea( sprintf(
          "claude mcp add --transport http wordpress %s \\\n  --header \"Authorization: Bearer %s\"",
          $endpoint,
          $token_display
        ) );
      ?></textarea>
    </div>

    <div class="gmcp-recipe">
      <h3><?php esc_html_e( 'Anything else that reads a JSON config', 'guarded-mcp' ); ?></h3>
      <label class="screen-reader-text" for="gmcp_recipe_json"><?php esc_html_e( 'JSON configuration block', 'guarded-mcp' ); ?></label>
      <textarea id="gmcp_recipe_json" class="large-text code" rows="10" readonly onfocus="this.select()"><?php
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
          <strong><?php esc_html_e( 'No bearer token is set yet.', 'guarded-mcp' ); ?></strong>
          <?php
          printf(
            /* translators: %s: link to the Access tab. */
            esc_html__( 'Generate one on the %s tab and this snippet will fill itself in. OAuth clients do not need one.', 'guarded-mcp' ),
            '<a href="' . esc_url( self::page_url( 'access' ) ) . '">' . esc_html__( 'Access', 'guarded-mcp' ) . '</a>'
          );
          ?>
        </p>
      <?php endif; ?>
    </div>

    <h2 class="title"><?php esc_html_e( 'Is this site ready', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php $this->form_head( 'self_test', 'connect' ); ?>
      <button type="submit" class="button button-secondary"><?php esc_html_e( 'Run the setup checks', 'guarded-mcp' ); ?></button>
    </form>
    <?php $this->render_report(); ?>
    <?php
  }

  /**
  * The result of the last readiness run. Read once and cleared, so a stale result never
  * sits on the page looking like live status.
  */
  private function render_report(): void {
    $checks = get_transient( 'gmcp_setup_report' );
    if ( !is_array( $checks ) ) {
      echo '<p class="description">' . esc_html__( 'Walks the same steps a client does when it configures itself, and shows which one fails. Safe to run at any time: it sends no credentials anywhere except to this site.', 'guarded-mcp' ) . '</p>';
      return;
    }
    delete_transient( 'gmcp_setup_report' );

    // The word beside each mark is not decoration. A tick and a cross differ only by
    // colour to a screen reader otherwise, and colour is the one channel some readers
    // of this table do not have.
    $style = [
      'ok' => [ 'gmcp-ok', "\u{2713}", __( 'Passed', 'guarded-mcp' ) ],
      'warn' => [ 'gmcp-warn', '!', __( 'Warning', 'guarded-mcp' ) ],
      'fail' => [ 'gmcp-fail', "\u{2715}", __( 'Failed', 'guarded-mcp' ) ],
      'skip' => [ 'gmcp-muted', "\u{2013}", __( 'Not checked', 'guarded-mcp' ) ],
    ];
    ?>
    <table class="widefat striped gmcp-table" style="margin-top:12px">
      <caption class="screen-reader-text"><?php esc_html_e( 'Setup check results', 'guarded-mcp' ); ?></caption>
      <tbody>
        <?php foreach ( $checks as $check ) :
          list( $class, $mark, $word ) = $style[ $check['status'] ] ?? $style['skip']; ?>
          <tr>
            <td class="gmcp-mark <?php echo esc_attr( $class ); ?>">
              <span aria-hidden="true"><?php echo esc_html( $mark ); ?></span>
              <span class="screen-reader-text"><?php echo esc_html( $word ); ?></span>
            </td>
            <td>
              <strong><?php echo esc_html( $check['label'] ); ?></strong>
              <?php if ( !empty( $check['detail'] ) ) : ?>
                <p class="gmcp-detail" style="margin:4px 0 0"><?php echo esc_html( $check['detail'] ); ?></p>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
  }

  #endregion

  #region Access

  /** Who may connect, and how far each of them reaches. */
  private function render_access( array $options ): void {
    $token = (string) $options['mcp_bearer_token'];
    ?>
    <p class="gmcp-intro"><?php esc_html_e( 'Three ways in, in increasing order of how much you can tell apart afterwards: one shared token, named keys, and OAuth.', 'guarded-mcp' ); ?></p>

    <h2 class="title"><?php esc_html_e( 'Bearer token', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php
      $this->form_head( 'save', 'access' );
      $this->form_fields( [ 'mcp_bearer_token', 'mcp_role' ] );
      ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="mcp_bearer_token"><?php esc_html_e( 'Token', 'guarded-mcp' ); ?></label></th>
          <td>
            <input type="text" id="mcp_bearer_token" name="mcp_bearer_token" class="large-text code"
              value="<?php echo esc_attr( $token ); ?>" autocomplete="off" spellcheck="false">
            <p class="description">
              <?php esc_html_e( 'Optional. A static token for clients that cannot do OAuth, such as a local CLI agent. Leave empty to require OAuth. Treat it like a password: it grants the access level selected below.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Access level', 'guarded-mcp' ); ?></th>
          <td>
            <fieldset class="gmcp-choices">
              <legend class="screen-reader-text"><?php esc_html_e( 'What the bearer token may do', 'guarded-mcp' ); ?></legend>
              <?php
              $levels = [
                'admin' => __( 'Admin. Every tool, including deleting content and changing users and options.', 'guarded-mcp' ),
                'readwrite' => __( 'Read and write. Create and update, but no destructive tools.', 'guarded-mcp' ),
                'readonly' => __( 'Read only. Nothing on the site can be changed.', 'guarded-mcp' ),
              ];
              foreach ( $levels as $value => $label ) : ?>
                <label>
                  <input type="radio" name="mcp_role" value="<?php echo esc_attr( $value ); ?>"
                    <?php checked( $options['mcp_role'], $value ); ?>>
                  <?php echo esc_html( $label ); ?>
                </label>
              <?php endforeach; ?>
            </fieldset>
            <p class="description">
              <?php esc_html_e( 'Applies to the bearer token only. OAuth connections always act as the administrator who approved them.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>

    <div class="gmcp-actions">
      <form method="post">
        <?php $this->form_head( 'generate_token', 'access' ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Generate a new token', 'guarded-mcp' ); ?></button>
      </form>
      <?php if ( $token !== '' ) : ?>
        <form method="post">
          <?php $this->form_head( 'clear_token', 'access' ); ?>
          <button type="submit" class="button"><?php esc_html_e( 'Clear the token', 'guarded-mcp' ); ?></button>
        </form>
      <?php endif; ?>
    </div>
    <p class="description"><?php esc_html_e( 'These two act immediately and do not wait for Save changes.', 'guarded-mcp' ); ?></p>

    <h2 class="title"><?php esc_html_e( 'Named keys', 'guarded-mcp' ); ?></h2>
    <?php $this->render_keys(); ?>

    <h2 class="title"><?php esc_html_e( 'Connected apps', 'guarded-mcp' ); ?></h2>
    <?php $this->render_apps(); ?>
    <?php
  }

  private function render_keys(): void {
    $fresh = get_transient( self::new_key_transient() );
    if ( $fresh ) {
      delete_transient( self::new_key_transient() );
      ?>
      <div class="notice notice-success inline" style="padding:12px">
        <p><strong><?php esc_html_e( 'Your new key. This is the only time it is shown.', 'guarded-mcp' ); ?></strong></p>
        <p>
          <label class="screen-reader-text" for="gmcp_new_key"><?php esc_html_e( 'The new key', 'guarded-mcp' ); ?></label>
          <input type="text" id="gmcp_new_key" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $fresh ); ?>">
        </p>
      </div>
      <?php
    }

    $keys = GMCP_Tokens::all();
    ?>
    <p class="description gmcp-intro">
      <?php esc_html_e( 'The bearer token above is one secret with one access level, shared by every client. A key is narrower: it carries a label so you can tell clients apart in the activity list, it can expire on its own, and it can be limited to a named list of tools. Keys are stored hashed, so a key is shown once and never again.', 'guarded-mcp' ); ?>
    </p>

    <?php if ( $keys ) : ?>
      <table class="widefat striped" style="margin-bottom:16px">
        <thead>
          <tr>
            <th scope="col"><?php esc_html_e( 'Label', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Access', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Limited to', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Expires', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Last used', 'guarded-mcp' ); ?></th>
            <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'guarded-mcp' ); ?></span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $keys as $key ) : $expired = GMCP_Tokens::is_expired( $key ); ?>
          <tr<?php echo $expired ? ' class="gmcp-expired"' : ''; ?>>
            <td><strong><?php echo esc_html( $key['label'] ); ?></strong></td>
            <td><?php echo esc_html( $key['level'] ); ?></td>
            <td>
              <?php if ( empty( $key['tools'] ) ) : ?>
                <span class="gmcp-muted"><?php esc_html_e( 'everything its access level allows', 'guarded-mcp' ); ?></span>
              <?php else : ?>
                <code class="gmcp-limits"><?php echo esc_html( implode( ', ', $key['tools'] ) ); ?></code>
              <?php endif; ?>
            </td>
            <td>
              <?php if ( empty( $key['expires'] ) ) : ?>
                <?php esc_html_e( 'never', 'guarded-mcp' ); ?>
              <?php elseif ( $expired ) : ?>
                <strong><?php esc_html_e( 'expired', 'guarded-mcp' ); ?></strong>
              <?php else : ?>
                <?php echo esc_html( wp_date( 'M j, Y', (int) $key['expires'] ) ); ?>
              <?php endif; ?>
            </td>
            <td>
              <?php echo empty( $key['last_used'] )
                ? esc_html__( 'never', 'guarded-mcp' )
                : esc_html( $this->ago( (int) $key['last_used'] ) ); ?>
            </td>
            <td>
              <form method="post">
                <?php $this->form_head( 'revoke_key', 'access' ); ?>
                <input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>">
                <button type="submit" class="button button-small">
                  <?php esc_html_e( 'Revoke', 'guarded-mcp' ); ?>
                  <span class="screen-reader-text"><?php echo esc_html( $key['label'] ); ?></span>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3><?php esc_html_e( 'Create a key', 'guarded-mcp' ); ?></h3>
    <form method="post">
      <?php $this->form_head( 'create_key', 'access' ); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="key_label"><?php esc_html_e( 'Label', 'guarded-mcp' ); ?></label></th>
          <td><input type="text" id="key_label" name="key_label" class="regular-text" placeholder="<?php esc_attr_e( 'Laptop, deploy script, the intern', 'guarded-mcp' ); ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="key_level"><?php esc_html_e( 'Access', 'guarded-mcp' ); ?></label></th>
          <td>
            <select id="key_level" name="key_level">
              <option value="readonly"><?php esc_html_e( 'Read only', 'guarded-mcp' ); ?></option>
              <option value="readwrite"><?php esc_html_e( 'Read and write content', 'guarded-mcp' ); ?></option>
              <option value="admin"><?php esc_html_e( 'Full administration', 'guarded-mcp' ); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="key_expires"><?php esc_html_e( 'Expires after', 'guarded-mcp' ); ?></label></th>
          <td>
            <select id="key_expires" name="key_expires">
              <option value="0"><?php esc_html_e( 'Never', 'guarded-mcp' ); ?></option>
              <option value="1"><?php esc_html_e( 'A day', 'guarded-mcp' ); ?></option>
              <option value="7"><?php esc_html_e( 'A week', 'guarded-mcp' ); ?></option>
              <option value="30"><?php esc_html_e( '30 days', 'guarded-mcp' ); ?></option>
              <option value="90"><?php esc_html_e( '90 days', 'guarded-mcp' ); ?></option>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="key_tools"><?php esc_html_e( 'Limit to these tools', 'guarded-mcp' ); ?></label></th>
          <td>
            <textarea id="key_tools" name="key_tools" rows="3" class="large-text code" placeholder="wp_get_posts wp_get_comments"></textarea>
            <p class="description">
              <?php esc_html_e( 'Optional. Names separated by spaces, commas or new lines. Leave empty to allow everything the access level above allows. A key limited this way sees only those tools, so an agent is never offered something that will be refused. mcp_ping always works.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
      </table>
      <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create key', 'guarded-mcp' ); ?></button></p>
    </form>
    <?php
  }

  private function render_apps() {
    $oauth = $this->oauth();
    if ( !$oauth ) {
      echo '<p>' . esc_html__( 'The MCP server is not running, so connected apps cannot be listed.', 'guarded-mcp' ) . '</p>';
      return;
    }
    $response = $oauth->handle_apps_list();
    $data = $response instanceof WP_REST_Response ? $response->get_data() : [];
    $apps = isset( $data['apps'] ) && is_array( $data['apps'] ) ? $data['apps'] : [];

    if ( empty( $apps ) ) {
      echo '<p>' . esc_html__( 'No app has connected through OAuth yet.', 'guarded-mcp' ) . '</p>';
      return;
    }
    ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th scope="col"><?php esc_html_e( 'App', 'guarded-mcp' ); ?></th>
          <th scope="col"><?php esc_html_e( 'Account', 'guarded-mcp' ); ?></th>
          <th scope="col"><?php esc_html_e( 'Connected', 'guarded-mcp' ); ?></th>
          <th scope="col"><?php esc_html_e( 'Last used', 'guarded-mcp' ); ?></th>
          <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'guarded-mcp' ); ?></span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $apps as $app ) : ?>
          <tr>
            <td><?php echo esc_html( $app['client_name'] ); ?></td>
            <td><?php echo esc_html( $app['user_display'] . ' (' . $app['user_login'] . ')' ); ?></td>
            <td><?php echo esc_html( $this->format_date( $app['created'] ) ); ?></td>
            <td><?php echo esc_html( $app['last_used'] ? $this->format_date( $app['last_used'] ) : __( 'Never', 'guarded-mcp' ) ); ?></td>
            <td>
              <form method="post">
                <?php $this->form_head( 'revoke_app', 'access' ); ?>
                <input type="hidden" name="app_id" value="<?php echo esc_attr( $app['id'] ); ?>">
                <button type="submit" class="button button-small">
                  <?php esc_html_e( 'Revoke', 'guarded-mcp' ); ?>
                  <span class="screen-reader-text"><?php echo esc_html( $app['client_name'] ); ?></span>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
  }

  #endregion

  #region Tools

  /** What a connected agent is offered. Nothing on this tab is about who may connect. */
  private function render_tools( array $options ): void {
    $woo = class_exists( 'WooCommerce' );
    $elementor = did_action( 'elementor/loaded' );

    // Only the groups actually rendered are declared to the save. A checkbox that was
    // never on screen must keep its stored value rather than read as unticked, which is
    // what happens to the WooCommerce group on a site where Woo is switched off.
    $keys = [ 'mcp_tools_core', 'mcp_tools_admin', 'mcp_tools_rest' ];
    if ( $woo ) {
      $keys[] = 'mcp_tools_woo';
    }
    if ( $elementor ) {
      $keys[] = 'mcp_tools_elementor';
    }
    ?>
    <p class="gmcp-intro"><?php esc_html_e( 'Which groups of tools an agent is offered. A group that is off is not merely hidden: its tools are refused if asked for by name.', 'guarded-mcp' ); ?></p>

    <h2 class="title"><?php esc_html_e( 'Tool groups', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php
      $this->form_head( 'save', 'tools' );
      $this->form_fields( $keys );
      ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><?php esc_html_e( 'Available tools', 'guarded-mcp' ); ?></th>
          <td>
            <fieldset class="gmcp-choices">
              <legend class="screen-reader-text"><?php esc_html_e( 'Groups of tools to offer', 'guarded-mcp' ); ?></legend>
              <label>
                <input type="checkbox" name="mcp_tools_core" value="1" <?php checked( !empty( $options['mcp_tools_core'] ) ); ?>>
                <?php esc_html_e( 'WordPress tools (posts, media, users, terms, comments, options, blocks)', 'guarded-mcp' ); ?>
              </label>
              <label>
                <input type="checkbox" name="mcp_tools_admin" value="1" <?php checked( !empty( $options['mcp_tools_admin'] ) ); ?>>
                <?php esc_html_e( 'Site administration (plugins, themes, menus, widgets, settings, permalinks, site health)', 'guarded-mcp' ); ?>
              </label>
              <?php if ( $woo ) : ?>
                <label>
                  <input type="checkbox" name="mcp_tools_woo" value="1" <?php checked( !empty( $options['mcp_tools_woo'] ) ); ?>>
                  <?php esc_html_e( 'WooCommerce (products, stock, orders, customers, sales figures)', 'guarded-mcp' ); ?>
                </label>
              <?php endif; ?>
              <?php if ( $elementor ) : ?>
                <label>
                  <input type="checkbox" name="mcp_tools_elementor" value="1" <?php checked( !empty( $options['mcp_tools_elementor'] ) ); ?>>
                  <?php esc_html_e( 'Elementor (theme-builder conditions, regenerate CSS, apply a library template to a page)', 'guarded-mcp' ); ?>
                </label>
              <?php endif; ?>
              <label>
                <input type="checkbox" name="mcp_tools_rest" value="1" <?php checked( !empty( $options['mcp_tools_rest'] ) ); ?>>
                <?php esc_html_e( 'Generate tools from this site\'s REST API routes', 'guarded-mcp' ); ?>
              </label>
            </fieldset>
            <p class="description">
              <?php esc_html_e( 'Administration tools install code on this site, so they are off by default. Installs are restricted to the wordpress.org repository, deletions take two steps, and they are refused entirely over the URL-token endpoint.', 'guarded-mcp' ); ?>
            </p>
            <?php if ( $woo ) : ?>
              <p class="description">
                <?php esc_html_e( 'The WooCommerce tools are separate because the risk is a different shape: they read customer names, email addresses and delivery addresses and hand them to a model. Refunds are deliberately not included, and any action that emails a customer says so in its own description.', 'guarded-mcp' ); ?>
              </p>
            <?php endif; ?>
            <?php if ( $elementor ) : ?>
              <p class="description">
                <?php esc_html_e( 'The Elementor tools exist because setting a header or footer through the generic tools appears to work and does not: Elementor keeps a cached copy of which template applies where, and writing only the template leaves that cache stale. These write both halves together, and say what the cache holds.', 'guarded-mcp' ); ?>
              </p>
            <?php endif; ?>
            <p class="description">
              <?php esc_html_e( 'The REST option exposes a large, generic surface. The curated WordPress tools are usually the better choice.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>
    <?php
  }

  #endregion

  #region Logs

  /**
  * What the plugin records, and the record itself.
  *
  * The switches used to sit four hundred lines away from the log they fill, under a
  * heading about tools. Reading the log and deciding whether to keep one are the same
  * job, so they are the same tab.
  */
  private function render_logs( array $options ): void {
    ?>
    <p class="gmcp-intro"><?php esc_html_e( 'An agent that leaves no record is an agent you cannot review. These are the three things the plugin can remember, and below them, what it has remembered so far.', 'guarded-mcp' ); ?></p>

    <h2 class="title"><?php esc_html_e( 'What gets recorded', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php
      $this->form_head( 'save', 'logs' );
      $this->form_fields( [ 'mcp_activity_log', 'mcp_audit_days', 'mcp_change_journal', 'mcp_debug_mode' ] );
      ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><?php esc_html_e( 'Audit log', 'guarded-mcp' ); ?></th>
          <td>
            <label>
              <input type="checkbox" name="mcp_activity_log" value="1" <?php checked( !empty( $options['mcp_activity_log'] ) ); ?>>
              <?php esc_html_e( 'Record every tool call, including refused ones, with its arguments', 'guarded-mcp' ); ?>
            </label>
            <p class="description">
              <?php esc_html_e( 'Without this an agent works with no visible record: you can see that something changed, but not what did it or when. Refusals are recorded too, since those are the interesting ones.', 'guarded-mcp' ); ?>
            </p>
            <p>
              <label for="mcp_audit_days"><?php esc_html_e( 'Keep entries for', 'guarded-mcp' ); ?></label>
              <input type="number" id="mcp_audit_days" name="mcp_audit_days" min="1" max="3650" class="small-text"
                     value="<?php echo esc_attr( (int) ( $options['mcp_audit_days'] ?? 90 ) ); ?>">
              <?php esc_html_e( 'days', 'guarded-mcp' ); ?>
            </p>
            <p class="description">
              <?php esc_html_e( 'Pruned once a day. Two further limits apply whatever this says, because age alone does not bound a busy site: at most 50,000 entries and 50 MB of recorded arguments, oldest removed first.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Change journal', 'guarded-mcp' ); ?></th>
          <td>
            <label>
              <input type="checkbox" name="mcp_change_journal" value="1" <?php checked( !empty( $options['mcp_change_journal'] ) ); ?>>
              <?php esc_html_e( 'Remember previous values so changes can be reverted', 'guarded-mcp' ); ?>
            </label>
            <p class="description">
              <?php esc_html_e( 'Records what a setting or post said before an agent changed it, and lets the change be put back with the wp_undo_change tool. Only writes made through this API are recorded, never your own. Values that look like credentials are never stored.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Debug logging', 'guarded-mcp' ); ?></th>
          <td>
            <label>
              <input type="checkbox" name="mcp_debug_mode" value="1" <?php checked( !empty( $options['mcp_debug_mode'] ) ); ?>>
              <?php esc_html_e( 'Write protocol traffic to the PHP error log', 'guarded-mcp' ); ?>
            </label>
            <p class="description">
              <?php esc_html_e( 'Verbose. It also shortens the idle stream timeout from 180 to 30 seconds, so leave it off in normal use.', 'guarded-mcp' ); ?>
            </p>
          </td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>

    <h2 class="title"><?php esc_html_e( 'The audit log', 'guarded-mcp' ); ?></h2>
    <?php $this->render_activity(); ?>
    <?php
  }

  /**
  * A compact age for the activity table. The full site-formatted date wrapped onto two
  * lines in that column, and for a list of things that mostly happened in the last few
  * minutes, "3 mins ago" is the more useful reading anyway.
  */
  private function ago( int $timestamp ): string {
    return self::ago_for( $timestamp );
  }

  /** The same, reachable from the list table, which is not a method of this class. */
  public static function ago_for( int $timestamp ): string {
    $now = time();
    if ( $timestamp > $now - 10 ) {
      return __( 'just now', 'guarded-mcp' );
    }
    if ( $timestamp > $now - DAY_IN_SECONDS ) {
      /* translators: %s: human-readable time difference, e.g. "5 mins". */
      return sprintf( __( '%s ago', 'guarded-mcp' ), human_time_diff( $timestamp, $now ) );
    }
    return wp_date( 'M j, H:i', $timestamp );
  }

  /**
  * The audit log: one entry in full, or the list.
  *
  * A single `entry` in the query string wins, because a reader who followed a link to
  * one record wants that record and not the list they came from. The chain verdict names
  * an id, so that link has to lead somewhere.
  */
  private function render_activity(): void {
    if ( empty( $this->core->get_option( 'mcp_activity_log' ) ) ) {
      echo '<p>' . esc_html__( 'The audit log is switched off, so nothing is being recorded. Switch it on above and calls from then on will appear here.', 'guarded-mcp' ) . '</p>';
      return;
    }

    $entry = isset( $_GET['entry'] ) ? (int) $_GET['entry'] : 0;
    if ( $entry > 0 ) {
      $this->render_entry( $entry );
      return;
    }

    $this->render_entry_list();
  }

  /** Everything the log holds about one call, with nothing behind a summary. */
  private function render_entry( int $id ): void {
    $e = GMCP_Audit::get( $id );
    $back = GMCP_Settings::page_url( 'logs' );
    if ( !$e ) {
      printf(
        '<p>%s</p><p><a href="%s">%s</a></p>',
        esc_html__( 'There is no entry with that number. It may have been pruned: entries are removed oldest first once any of the retention bounds is reached.', 'guarded-mcp' ),
        esc_url( $back ),
        esc_html__( 'Back to the log', 'guarded-mcp' )
      );
      return;
    }

    $stamp = strtotime( $e['ts'] . ' UTC' );
    $row = GMCP_Audit::verify_row( $id );
    ?>
    <p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to the log', 'guarded-mcp' ); ?></a></p>

    <h3><?php printf( esc_html__( 'Entry #%d', 'guarded-mcp' ), (int) $e['id'] ); ?>
      <code><?php echo esc_html( (string) $e['tool'] ); ?></code>
      <?php if ( (string) $e['outcome'] === 'ok' ) : ?>
        <span class="gmcp-ok"><?php esc_html_e( 'Done', 'guarded-mcp' ); ?></span>
      <?php else : ?>
        <span class="gmcp-fail"><?php esc_html_e( 'Refused', 'guarded-mcp' ); ?></span>
      <?php endif; ?>
    </h3>

    <table class="widefat gmcp-table">
      <tbody>
        <tr>
          <th scope="row"><?php esc_html_e( 'When', 'guarded-mcp' ); ?></th>
          <td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $stamp ) ); ?>
            <span class="gmcp-muted"><?php echo esc_html( self::ago_for( $stamp ) ); ?></span></td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Target', 'guarded-mcp' ); ?></th>
          <td><?php echo (string) $e['target'] !== '' ? '<code>' . esc_html( (string) $e['target'] ) . '</code>' : '&mdash;'; ?></td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Called by', 'guarded-mcp' ); ?></th>
          <td>
            <?php echo esc_html( (string) ( $e['client'] ?: $e['auth_method'] ) ); ?>
            <?php if ( (string) $e['actor_name'] !== '' ) : ?>
              <br><span class="gmcp-muted"><?php echo esc_html( sprintf(
                __( 'ran as %s', 'guarded-mcp' ), $e['actor_name'] ) ); ?></span>
              <?php // Said in full here rather than as a tooltip. The list has to be
              // terse; this page is where somebody came to find out what it means. ?>
              <p class="description"><?php esc_html_e( 'The WordPress account the call ran as. A shared bearer token borrows one administrator account, so this names the account, not the person.', 'guarded-mcp' ); ?></p>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e( 'Took', 'guarded-mcp' ); ?></th>
          <td><?php echo esc_html( sprintf( '%dms', (int) $e['ms'] ) ); ?></td>
        </tr>
        <?php if ( (string) ( $e['detail'] ?? '' ) !== '' ) : ?>
          <tr>
            <th scope="row"><?php echo (string) $e['outcome'] === 'ok'
              ? esc_html__( 'What it reported', 'guarded-mcp' )
              : esc_html__( 'Why it was refused', 'guarded-mcp' ); ?></th>
            <?php // In full. The list truncates at 160 characters, and a refusal message
            // is the most interesting content in this whole table. ?>
            <td><?php echo esc_html( (string) $e['detail'] ); ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php $changes = $this->change_records( $e['changes'] ?? null ); ?>
    <h4><?php esc_html_e( 'What changed', 'guarded-mcp' ); ?></h4>
    <?php if ( !$changes ) : ?>
      <p class="gmcp-muted"><?php esc_html_e( 'Nothing was recorded as changed. A read changes nothing, and a refused call did not get far enough to.', 'guarded-mcp' ); ?></p>
    <?php else : ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th scope="col"><?php esc_html_e( 'Object', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Field', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Was', 'guarded-mcp' ); ?></th>
            <th scope="col"><?php esc_html_e( 'Became', 'guarded-mcp' ); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $changes as $record ) : ?>
          <?php if ( isset( $record['__gmcp_more'] ) ) : ?>
            <tr><td colspan="4" class="gmcp-muted"><?php printf(
              /* translators: %d: number of further changes not recorded in detail. */
              esc_html__( 'and %d more changes, too many to record in one entry', 'guarded-mcp' ),
              (int) $record['__gmcp_more'] ); ?></td></tr>
            <?php continue; ?>
          <?php endif; ?>
          <?php
          $what = trim( (string) ( $record['op'] ?? '' ) . ' ' . (string) ( $record['what'] ?? '' ) );
          $label = (string) ( $record['label'] ?? '' );
          $fields = (array) ( $record['fields'] ?? [] );
          $first = true;
          ?>
          <?php if ( !$fields ) : ?>
            <tr>
              <td><?php echo esc_html( $what ); ?>
                <?php if ( $label !== '' ) : ?><br><span class="gmcp-muted"><?php echo esc_html( $label ); ?></span><?php endif; ?></td>
              <td colspan="3" class="gmcp-muted"><?php esc_html_e( 'no field values recorded', 'guarded-mcp' ); ?></td>
            </tr>
          <?php else : ?>
            <?php foreach ( $fields as $field => $pair ) : ?>
              <tr>
                <?php if ( $first ) : // One cell spanning the object's fields, so the eye
                  // groups them without the name repeating down the column. ?>
                  <td rowspan="<?php echo (int) count( $fields ); ?>">
                    <?php echo esc_html( $what ); ?>
                    <?php if ( $label !== '' ) : ?><br><span class="gmcp-muted"><?php echo esc_html( $label ); ?></span><?php endif; ?>
                  </td>
                  <?php $first = false; ?>
                <?php endif; ?>
                <td><code><?php echo esc_html( (string) $field ); ?></code></td>
                <td><?php echo $this->value_cell( $pair['from'] ?? null, __( 'not set', 'guarded-mcp' ) ); ?></td>
                <td><?php echo $this->value_cell( $pair['to'] ?? null, __( 'removed', 'guarded-mcp' ) ); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h4><?php esc_html_e( 'Arguments it was given', 'guarded-mcp' ); ?></h4>
    <?php if ( empty( $e['args'] ) || in_array( $e['args'], [ '[]', '{}' ], true ) ) : ?>
      <p class="gmcp-muted"><?php esc_html_e( 'None.', 'guarded-mcp' ); ?></p>
    <?php else : ?>
      <pre class="gmcp-args"><?php echo esc_html( (string) wp_json_encode(
        json_decode( (string) $e['args'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
      <p class="description"><?php esc_html_e( 'Anything that looked like a credential was replaced before this was written. A field reading [redacted] is itself information: it says a secret was passed.', 'guarded-mcp' ); ?></p>
    <?php endif; ?>

    <h4><?php esc_html_e( 'Its place in the chain', 'guarded-mcp' ); ?></h4>
    <p>
      <?php if ( !$row['checked'] ) : ?>
        <span class="gmcp-muted"><?php echo esc_html( $row['reason'] ); ?></span>
      <?php elseif ( $row['ok'] ) : ?>
        <span class="gmcp-ok"><?php esc_html_e( 'This entry still matches its own hash.', 'guarded-mcp' ); ?></span>
        <?php // Said plainly, because the stronger reading is the tempting one: one row
        // verifying is not the chain verifying, and the chain is checked on the list. ?>
        <span class="gmcp-muted"><?php esc_html_e( 'That is about this entry alone. Whether the chain around it is whole is checked on the log itself.', 'guarded-mcp' ); ?></span>
      <?php else : ?>
        <strong class="gmcp-fail"><?php echo esc_html( $row['reason'] ); ?></strong>
      <?php endif; ?>
    </p>
    <table class="widefat gmcp-table">
      <tbody>
        <tr><th scope="row"><?php esc_html_e( 'Follows', 'guarded-mcp' ); ?></th>
          <td><code class="gmcp-hash"><?php echo esc_html( (string) $e['prev_hash'] ?: '—' ); ?></code></td></tr>
        <tr><th scope="row"><?php esc_html_e( 'This entry', 'guarded-mcp' ); ?></th>
          <td><code class="gmcp-hash"><?php echo esc_html( (string) $e['hash'] ?: '—' ); ?></code></td></tr>
      </tbody>
    </table>
    <?php
  }

  /** A recorded value, or a word for its absence. */
  private function value_cell( $value, string $absent ): string {
    if ( $value === null || $value === '' ) {
      return '<span class="gmcp-muted">' . esc_html( $absent ) . '</span>';
    }
    if ( (string) $value === '[redacted]' ) {
      return '<span class="gmcp-muted" title="' . esc_attr__( 'Recorded as changed, but the value looked like a credential and was not kept.', 'guarded-mcp' ) . '">[redacted]</span>';
    }
    return '<code>' . esc_html( (string) $value ) . '</code>';
  }

  /** The changes column, decoded, or an empty list if it holds nothing usable. */
  private function change_records( $json ): array {
    $records = $json ? json_decode( (string) $json, true ) : null;
    return is_array( $records ) ? $records : [];
  }

  /**
  * The log itself, as a list table.
  *
  * Filters are read here rather than inside the table, so one place decides what a
  * query-string value is allowed to mean and the table is handed values it need not
  * check again.
  */
  private function render_entry_list(): void {
    $outcome = isset( $_GET['gmcp_outcome'] ) ? sanitize_key( wp_unslash( $_GET['gmcp_outcome'] ) ) : '';
    $days = isset( $_GET['gmcp_since'] ) ? sanitize_key( wp_unslash( $_GET['gmcp_since'] ) ) : '';
    $filters = [
      'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
      'outcome' => in_array( $outcome, [ 'ok', 'refused' ], true ) ? $outcome : '',
      'tool' => isset( $_GET['gmcp_tool'] ) ? sanitize_key( wp_unslash( $_GET['gmcp_tool'] ) ) : '',
      'actor' => isset( $_GET['gmcp_actor'] ) ? (int) $_GET['gmcp_actor'] : 0,
      'since_days' => in_array( $days, [ '1', '7', '30' ], true ) ? $days : '',
    ];
    if ( $filters['since_days'] !== '' ) {
      $filters['since'] = gmdate( 'Y-m-d H:i:s', time() - ( (int) $filters['since_days'] * DAY_IN_SECONDS ) );
    }

    $table = new GMCP_Audit_Table( $filters );
    $table->prepare_items();

    // Refusals are the entries this table is kept for, so reaching them is one click
    // rather than a menu and a submit.
    $counts = [ '' => GMCP_Audit::count(), 'refused' => GMCP_Audit::count( [ 'outcome' => 'refused' ] ) ];
    $views = [];
    foreach ( [ '' => __( 'All', 'guarded-mcp' ), 'refused' => __( 'Refusals', 'guarded-mcp' ) ] as $key => $label ) {
      $url = self::page_url( 'logs' );
      if ( $key !== '' ) {
        $url = add_query_arg( 'gmcp_outcome', $key, $url );
      }
      $views[] = sprintf( '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
        esc_url( $url ),
        $outcome === $key ? ' class="current"' : '',
        esc_html( $label ),
        esc_html( number_format_i18n( $counts[ $key ] ) ) );
    }
    echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $views ) . '</li></ul>';
    ?>
    <form method="get">
      <?php // These travel with every filter, search and page link, or submitting the
      // search box drops the reader onto the first tab of a screen they were not on. ?>
      <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
      <input type="hidden" name="tab" value="logs">
      <?php if ( $filters['outcome'] !== '' ) : ?>
        <input type="hidden" name="gmcp_outcome" value="<?php echo esc_attr( $filters['outcome'] ); ?>">
      <?php endif; ?>
      <?php $table->search_box( __( 'Search the log', 'guarded-mcp' ), 'gmcp-audit-search' ); ?>
      <?php $table->display(); ?>
    </form>

    <?php $chain = GMCP_Audit::verify(); ?>
    <p style="margin-top:12px">
      <?php printf(
        esc_html__( '%1$s entries, %2$s of recorded arguments and changes. Kept for %3$d days, then pruned automatically.', 'guarded-mcp' ),
        esc_html( number_format_i18n( $counts[''] ) ),
        esc_html( size_format( GMCP_Audit::bytes() ) ),
        (int) GMCP_Audit::retention_days()
      ); ?>
      <?php if ( $chain['ok'] ) : ?>
        <span class="gmcp-ok"><?php echo esc_html( $chain['complete']
          ? sprintf(
              /* translators: %d: number of entries verified. */
              __( 'The chain is intact across all %d entries.', 'guarded-mcp' ), (int) $chain['checked'] )
          // Saying only "intact" would let a tenth of a log read as a whole one, which is
          // the mistake this wording exists to stop repeating.
          : sprintf(
              /* translators: 1: entries verified, 2: entries in total. */
              __( 'The chain is intact across the %1$d most recent entries, of %2$d.', 'guarded-mcp' ),
              (int) $chain['checked'], (int) $chain['total'] )
        ); ?></span>
        <?php if ( !empty( $chain['imported'] ) ) : ?>
          <span class="gmcp-muted"><?php printf(
            esc_html__( '%d older entries were carried over from before this log was chained and are not covered.', 'guarded-mcp' ),
            (int) $chain['imported'] ); ?></span>
        <?php endif; ?>
        <?php if ( !$chain['complete'] ) : ?>
          <?php // Through form_head, not by hand: the POST redirects, and a form that
          // forgets gmcp_tab sends the reader to the first tab to read a verdict about
          // the log they were just looking at. ?>
          <form method="post" style="display:inline">
            <?php $this->form_head( 'verify_audit', 'logs' ); ?>
            <button type="submit" class="button button-small"><?php esc_html_e( 'Check the whole chain', 'guarded-mcp' ); ?></button>
          </form>
        <?php endif; ?>
      <?php else : ?>
        <strong class="gmcp-fail"><?php printf(
          esc_html__( 'The chain breaks at entry %1$d: %2$s', 'guarded-mcp' ),
          (int) $chain['broken_at'], esc_html( $chain['reason'] )
        ); ?></strong>
        <?php // The verdict names an id, so it links to it. Before this the reader was
        // handed a number and no way to look at the entry it named. ?>
        <a href="<?php echo esc_url( GMCP_Audit_Table::entry_url( (int) $chain['broken_at'] ) ); ?>"><?php
          esc_html_e( 'Look at that entry', 'guarded-mcp' ); ?></a>
      <?php endif; ?>
    </p>

    <div class="gmcp-actions">
      <form method="post">
        <?php $this->form_head( 'prune_audit', 'logs' ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Prune now', 'guarded-mcp' ); ?></button>
      </form>
      <form method="post">
        <?php $this->form_head( 'clear_activity', 'logs' ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Clear everything', 'guarded-mcp' ); ?></button>
      </form>
    </div>
    <p class="description gmcp-intro">
      <?php esc_html_e( 'Every call, including the refused ones, with the arguments it was given. Anything that looks like a password or a key is replaced before the entry is written, so what you see here is what was recorded, not a redacted view of something fuller. Each entry hashes the one before it, so a row that is edited or removed later shows up as a break in the chain rather than disappearing quietly.', 'guarded-mcp' ); ?>
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
