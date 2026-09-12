<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* The whole admin surface: one menu, five pages.
*
* Plain PHP and core's own form styles on purpose. The upstream plugin shipped a 1.1 MB
* minified React bundle with no source in the repository, which made the settings screen
* the one part of the plugin nobody could edit. Everything here is readable and fits on
* a screen.
*
* The pages are separate submenu items rather than anything client side, so every page
* works with JavaScript off and each one is a link somebody can bookmark or send to a
* colleague.
*/
class GMCP_Settings {

  const PAGE_SLUG = 'guarded-mcp-settings';
  const PAGE_ACCESS = 'guarded-mcp-access';
  const PAGE_TOOLS = 'guarded-mcp-tools';
  const PAGE_LOGGING = 'guarded-mcp-logging';
  const PAGE_LOGS = 'guarded-mcp-logs';
  const NONCE_ACTION = 'gmcp_save_settings';

  /**
  * The pages, in the order they appear, and the order somebody meets them: connect a
  * client, decide who may connect, decide what they may touch, decide what gets
  * remembered, then read what they did.
  *
  * The array maps the historic tab name to its page slug, so page_url() keeps
  * accepting the same argument and every caller survives the move from tabs to pages.
  */
  const PAGES = [
    'connect' => self::PAGE_SLUG,
    'access' => self::PAGE_ACCESS,
    'tools' => self::PAGE_TOOLS,
    'logging' => self::PAGE_LOGGING,
    'logs' => self::PAGE_LOGS,
  ];

  private $core = null;
  private $notice = null;

  public function __construct( $core ) {
    $this->core = $core;
    add_action( 'admin_menu', [ $this, 'add_menu' ] );
    add_action( 'admin_init', [ $this, 'maybe_handle_post' ] );
    add_action( 'admin_init', [ $this, 'maybe_redirect_legacy_tab' ] );
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
  * Where a settings page lives, in one place so a move does not strand links.
  *
  * The page key is historic: callers pass 'connect', 'access', 'tools', 'logging'
  * or 'logs' and get the page that tab became. Empty lands on the first page. Callers
  * that do not care which page they get should keep passing nothing.
  */
  public static function page_url( string $page_key = '' ): string {
    $slug = $page_key !== '' && isset( self::PAGES[ $page_key ] ) ? self::PAGES[ $page_key ] : self::PAGE_SLUG;
    return add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) );
  }

  /**
  * The page a slug names, or the first page when the slug is none of ours.
  *
  * The POST forms submit back to the page they were rendered on, so the redirect
  * after a save is answered by the request itself rather than by a hidden field
  * naming where to return to.
  */
  private static function page_for_slug( string $slug ): string {
    $pages = array_values( self::PAGES );
    return in_array( $slug, $pages, true ) ? $slug : self::PAGE_SLUG;
  }

  /**
  * Old bookmarks and sent links still name a tab on the parent page. Send each one
  * to the page that tab became, carrying its entry, filter, paging or sort along,
  * so the move from tabs to pages does not strand them. Anything else is dropped,
  * notices included: see the carry list below for why.
  */
  public function maybe_redirect_legacy_tab(): void {
    if ( !is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
      return;
    }
    if ( !isset( $_GET['page'], $_GET['tab'] ) ) {
      return;
    }
    $page = sanitize_key( wp_unslash( $_GET['page'] ) );
    $page_key = sanitize_key( wp_unslash( $_GET['tab'] ) );
    if ( $page !== self::PAGE_SLUG || !isset( self::PAGES[ $page_key ] ) ) {
      return;
    }
    $target = self::page_url( $page_key );
    // Carry only what names something on the new page: an entry id, a log filter,
    // the list table's paging and sort. Everything else is dropped, gmcp_notice
    // included: carrying arbitrary text into a green success-styled box would let a
    // crafted link put words in the screen's mouth.
    $carry = [ 'entry', 's', 'gmcp_outcome', 'gmcp_tool', 'gmcp_actor', 'gmcp_since', 'gmcp_deep', 'paged', 'orderby', 'order' ];
    foreach ( wp_unslash( $_GET ) as $key => $value ) {
      $key = sanitize_key( $key );
      if ( !in_array( $key, $carry, true ) || !is_scalar( $value ) ) {
        continue;
      }
      $target = add_query_arg( $key, rawurlencode( (string) $value ), $target );
    }
    wp_safe_redirect( $target );
    exit;
  }

  public function add_menu() {
    // Top level rather than buried under Settings. This is the only screen the plugin
    // has, it is the first thing anyone needs after activating, and "Settings, then
    // scroll" is a poor answer to "where do I connect my agent". The first submenu
    // repeats the parent, which is WordPress convention: without it the parent label
    // still opens the first page but the submenu highlights nothing.
    $hooks = [];
    $hooks[] = $hook = add_menu_page(
      __( 'MCP Server', 'guarded-mcp' ),
      __( 'MCP Server', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_SLUG,
      [ $this, 'render_connect_page' ],
      self::menu_icon(),
      80 // Just above Settings.
    );
    $hooks[] = add_submenu_page(
      self::PAGE_SLUG,
      __( 'Connection', 'guarded-mcp' ),
      __( 'Connection', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_SLUG,
      [ $this, 'render_connect_page' ]
    );
    $hooks[] = add_submenu_page(
      self::PAGE_SLUG,
      __( 'Access', 'guarded-mcp' ),
      __( 'Access', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_ACCESS,
      [ $this, 'render_access_page' ]
    );
    $hooks[] = add_submenu_page(
      self::PAGE_SLUG,
      __( 'Tools', 'guarded-mcp' ),
      __( 'Tools', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_TOOLS,
      [ $this, 'render_tools_page' ]
    );
    $hooks[] = add_submenu_page(
      self::PAGE_SLUG,
      __( 'Logging', 'guarded-mcp' ),
      __( 'Logging', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_LOGGING,
      [ $this, 'render_logging_page' ]
    );
    $hooks[] = $logs_hook = add_submenu_page(
      self::PAGE_SLUG,
      __( 'Audit Log', 'guarded-mcp' ),
      __( 'Audit Log', 'guarded-mcp' ),
      'manage_options',
      self::PAGE_LOGS,
      [ $this, 'render_logs_page' ]
    );
    // Hooked to each screen's own admin_head so the rules load on these pages and
    // nowhere else. There is no stylesheet to enqueue and no build step to produce
    // one; what is here is the handful of things core has no class for.
    foreach ( $hooks as $screen_hook ) {
      add_action( 'admin_head-' . $screen_hook, [ $this, 'print_styles' ] );
    }
    // Screen Options gives the reader the per-page control the list table reads. It has
    // to be registered on the screen's load hook: by the time the page renders, the
    // Screen Options panel has already been built and an option added then is never shown.
    // Only the audit log page needs it, now that each page has its own load hook.
    add_action( 'load-' . $logs_hook, [ $this, 'add_screen_options' ] );
  }

  /**
  * The per-page setting for the audit log.
  *
  * Registered only on the logs page's load hook. WordPress simply shows no panel on
  * screens whose option nothing reads, so the other pages need no guard.
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
      /* More prominent than .gmcp-detail on purpose: what a call changed outranks what
      it said about itself. Said by inheriting the admin's text colour rather than by
      naming a dark one, because the old #1d2327 only meant "prominent" on a light page.
      Rendered dark, it was near-black text on a near-black table and the column could not
      be read at all. WordPress 7.1's own admin CSS has no dark rules, so this is not core
      doing it, but a browser forcing dark mode does it, a dark-admin plugin does it, and
      whatever core eventually ships will do it. Inheriting is right under all of them. */
      .gmcp-change { color: inherit; font-size: 12px; }
      /* Long enough to wrap on a narrow screen rather than widen the table. */
      .gmcp-hash { font-size: 11px; word-break: break-all; }
      /*
      Three chain verdicts, and only one of them is a sentence. A complete pass stays in
      the line of prose under the table, because there is nothing to do about it. A check
      that covered part of the table and a chain that is broken become blocks, since the
      whole worth of a tamper-evident log is that a person can glance at it, and two
      sentences differing only in their words are read as the same reassurance.
      Amber rather than red for the partial one: it is neither a pass nor a failure, and
      dressing it as a failure sends the reader hunting for tampering nobody has found.
      Both are blocks because a bordered partial next to a one-line break would put the
      heavier mark on the smaller problem.
      */
      /* Border and nothing else. Naming a background here would mean naming a light one,
      which is the mistake .gmcp-change above records: rendered dark it becomes a white
      panel of near-white text. The shape carries the difference on either page. */
      .gmcp-verdict { max-width: 800px; margin: 12px 0; padding: 8px 12px;
        border: 1px solid #dcdcde; border-left-width: 4px; }
      .gmcp-verdict p { margin: 0 0 6px; }
      .gmcp-verdict p:last-child { margin-bottom: 0; }
      .gmcp-verdict-partial { border-left-color: #dba617; }
      .gmcp-verdict-broken { border-left-color: #d63638; }
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
  * Split out because a form now covers one page rather than the whole screen. An
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
      'mcp_tools_core' => 'bool',
      'mcp_tools_admin' => 'bool',
      'mcp_tools_rest' => 'bool',
      'mcp_tools_woo' => 'bool',
      'mcp_tools_elementor' => 'bool',
      'mcp_tools_kirki' => 'bool',
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
      // Kept, because this notice is printed once by the redirect and then gone, and the
      // window check that runs on every load cannot say anything at all about the rows
      // below it. The screen renders what is kept with the date it was found on.
      GMCP_Audit::remember_full_check( $chain );
      $this->notice = $chain['ok']
        ? sprintf(
            /* translators: 1: entries verified, 2: entries in the log, 3: entries carried over unchained. */
            __( 'Checked the whole chain: %1$d of %2$d entries intact, %3$d carried over from before the log was chained.', 'guarded-mcp' ),
            $chain['checked'], $chain['total'], $chain['imported'] )
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
    elseif ( $action === 'export_csv' || $action === 'export_json' ) {
      // Sends a file and exits, so it never reaches the redirect below. Capability and
      // nonce were settled at the top of this method, the same two gates the prune and
      // clear buttons pass through, and nothing has been printed yet on admin_init.
      $this->export_log( $action === 'export_csv' ? 'csv' : 'json' );
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
    // The form posts back to the page it was rendered on, so the answer appears
    // where the question was asked, rather than bouncing to the first page on save.
    // The page comes from the request's own query string rather than the form: every
    // form on these screens submits back to the page it was rendered on, so the two
    // agree, and a forged page value can at most misplace a notice, never an action.
    $slug = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
    $page_key = array_search( self::page_for_slug( $slug ), self::PAGES, true );
    $url = add_query_arg(
      [ 'gmcp_notice' => $this->notice ? rawurlencode( $this->notice ) : null ],
      self::page_url( is_string( $page_key ) ? $page_key : '' )
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

  /**
  * The log the filters select, as a file.
  *
  * Exactly the rows on screen rather than the whole table, because an operator who has
  * narrowed to four refusals wants those four, and a fifty-thousand-row file is not an
  * answer to the question they asked. The filters arrive posted back from the form they
  * were entered in and go through the same reader the list uses, so the two cannot
  * disagree about what a value meant.
  */
  private function export_log( string $format ): void {
    $filters = self::log_filters( $_POST );
    $rows = GMCP_Audit::export_rows( $filters );

    // export_rows() stops on a byte budget as well as a row ceiling, so a short return
    // is not the same as a small match and the row count cannot tell the two apart.
    // Counting the match is the only way to know, and a truncated export that looks
    // complete is the one outcome worth paying a second query to avoid. What is missing
    // is always the oldest end, since the walk comes newest first.
    $matching = GMCP_Audit::count( $filters );
    $exported = count( $rows );
    $complete = $exported >= $matching;

    // Said in the file name, because a file outlives the screen it was taken from and
    // has nowhere else to carry the caveat.
    $name = 'guarded-mcp-audit-log-' . wp_date( 'Y-m-d-Hi' )
      . ( $complete ? '' : '-newest-' . $exported . '-of-' . $matching ) . '.' . $format;

    // Anything already buffered would be written into the file ahead of the headers.
    while ( ob_get_level() > 0 ) {
      ob_end_clean();
    }
    nocache_headers();
    header( 'Content-Type: ' . ( $format === 'csv' ? 'text/csv' : 'application/json' ) . '; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $name . '"' );
    header( 'X-Content-Type-Options: nosniff' );

    if ( $format === 'csv' ) {
      self::write_csv( $rows );
    }
    else {
      self::write_json( $rows, $filters, $matching );
    }
    exit;
  }

  /**
  * One line per entry, for somebody who is going to open this in a spreadsheet.
  *
  * The columns the table shows, plus the two the screen keeps for a different question:
  * the reason a call was refused, and the pair of hashes, so a file taken today can be
  * checked against the live table later.
  *
  * Arguments and changes are not here. They are nested structures, and a cell holding a
  * JSON blob is neither readable nor sortable; the JSON export is where they belong.
  */
  private static function write_csv( array $rows ): void {
    $out = fopen( 'php://output', 'w' );
    // Excel reads a UTF-8 file as the system code page unless a byte order mark says
    // otherwise, and post titles and comment text arrive in any script.
    fwrite( $out, "\xEF\xBB\xBF" );
    self::csv_line( $out, [
      __( 'Entry', 'guarded-mcp' ),
      __( 'When', 'guarded-mcp' ),
      __( 'Tool', 'guarded-mcp' ),
      __( 'Target', 'guarded-mcp' ),
      __( 'Result', 'guarded-mcp' ),
      __( 'Client', 'guarded-mcp' ),
      __( 'Let in by', 'guarded-mcp' ),
      __( 'Ran as', 'guarded-mcp' ),
      __( 'WordPress user ID', 'guarded-mcp' ),
      __( 'Took (ms)', 'guarded-mcp' ),
      __( 'Reason', 'guarded-mcp' ),
      __( 'Follows hash', 'guarded-mcp' ),
      __( 'Entry hash', 'guarded-mcp' ),
    ] );
    foreach ( $rows as $row ) {
      self::csv_line( $out, [
        (int) ( $row['id'] ?? 0 ),
        // Stored UTC, written in the site's timezone with the offset spelled out: the
        // same reading as the screen, and still unambiguous in a file that travels.
        wp_date( 'c', strtotime( (string) ( $row['ts'] ?? '' ) . ' UTC' ) ),
        (string) ( $row['tool'] ?? '' ),
        (string) ( $row['target'] ?? '' ),
        (string) ( $row['outcome'] ?? '' ) === 'ok'
          ? __( 'Done', 'guarded-mcp' )
          : __( 'Refused', 'guarded-mcp' ),
        (string) ( $row['client'] ?? '' ),
        (string) ( $row['auth_method'] ?? '' ),
        (string) ( $row['actor_name'] ?? '' ),
        (int) ( $row['actor'] ?? 0 ),
        (int) ( $row['ms'] ?? 0 ),
        (string) ( $row['detail'] ?? '' ),
        (string) ( $row['prev_hash'] ?? '' ),
        (string) ( $row['hash'] ?? '' ),
      ] );
    }
    fclose( $out );
  }

  /**
  * One row, every cell defused first.
  *
  * The escape character is disabled rather than left at PHP's default backslash, which
  * is not what RFC 4180 says and mangles any value containing a backslash before a
  * quote into something a reader decodes differently from what was stored.
  */
  private static function csv_line( $handle, array $cells ): void {
    fputcsv( $handle, array_map( [ self::class, 'csv_cell' ], $cells ), ',', '"', '' );
  }

  /**
  * One cell, made inert.
  *
  * A spreadsheet reads a cell that opens with =, +, - or @ as a formula and evaluates
  * it. This log carries post titles, refusal messages and comment text that an
  * anonymous person wrote, which is the same reason the plugin exists, so opening the
  * export would be handing that person the spreadsheet. Tab and carriage return count
  * as well: Excel skips them and reads what follows the same way.
  *
  * A leading apostrophe makes the cell literal text. It is visible in the file, which is
  * the honest half of the trade against a spreadsheet that executes the audit log.
  * Numbers are left alone, since no formula is one.
  */
  private static function csv_cell( $value ): string {
    $value = (string) $value;
    if ( $value === '' || is_numeric( $value ) ) {
      return $value;
    }
    return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
  }

  /**
  * The same rows, for something that is going to read them rather than look at them.
  *
  * Written out a row at a time rather than encoded in one go. export_rows() already holds
  * twenty megabytes of payload in memory; encoding all of it into one more string before
  * anything is sent doubles that for no gain, and the reader is downloading a file rather
  * than waiting on a response that has to arrive whole.
  *
  * `args` and `changes` are decoded back into structures. They are stored as JSON text,
  * and passing the text straight through would give the reader JSON quoted inside JSON
  * to unpick a second time.
  */
  private static function write_json( array $rows, array $filters, int $matching ): void {
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $active = array_filter( $filters, static function ( $value ) {
      return $value !== '' && $value !== 0 && $value !== false;
    } );

    echo "{\n";
    echo '  "site": ' . wp_json_encode( home_url() ) . ",\n";
    echo '  "exported": ' . wp_json_encode( gmdate( 'c' ) ) . ",\n";
    // Named so a file found later says what it is a view of, not just that it is a log.
    echo '  "filters": ' . wp_json_encode( (object) $active ) . ",\n";
    echo '  "entries_exported": ' . count( $rows ) . ",\n";
    echo '  "entries_matching": ' . $matching . ",\n";
    echo '  "complete": ' . ( count( $rows ) >= $matching ? 'true' : 'false' ) . ",\n";
    echo '  "entries": [';

    $separator = "\n";
    foreach ( $rows as $row ) {
      $row['id'] = (int) ( $row['id'] ?? 0 );
      $row['actor'] = (int) ( $row['actor'] ?? 0 );
      $row['ms'] = (int) ( $row['ms'] ?? 0 );
      $row['args'] = self::decoded( $row['args'] ?? null );
      $row['changes'] = self::decoded( $row['changes'] ?? null );
      echo $separator . wp_json_encode( $row, $flags );
      $separator = ",\n";
    }

    echo "\n  ]\n}\n";
  }

  /** A stored JSON column as data, or the text itself when it will not decode. */
  private static function decoded( $json ) {
    if ( (string) $json === '' ) {
      return null;
    }
    $value = json_decode( (string) $json, true );
    return json_last_error() === JSON_ERROR_NONE ? $value : (string) $json;
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

  /**
  * The hidden action every POST form on these screens carries. The nonce is printed
  * alongside so no form can forget either of the two. The page to come back to is
  * the page the form was rendered on, which the request already says, so nothing
  * else travels with it.
  */
  private function form_head( string $action ): void {
    wp_nonce_field( self::NONCE_ACTION );
    printf(
      '<input type="hidden" name="gmcp_action" value="%s">',
      esc_attr( $action )
    );
  }

  /** Tells the save which options this particular form is answerable for. */
  private function form_fields( array $keys ): void {
    printf( '<input type="hidden" name="gmcp_fields" value="%s">', esc_attr( implode( ' ', $keys ) ) );
  }

  /**
  * One page of the five: the capability gate, the notice and the wrap, shared so the
  * five callbacks differ only in their title and their body.
  */
  private function render_page( string $title, callable $body ): void {
    if ( !current_user_can( 'manage_options' ) ) {
      wp_die( esc_html__( 'You do not have permission to view this page.', 'guarded-mcp' ) );
    }

    $notice = isset( $_GET['gmcp_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['gmcp_notice'] ) ) : '';
    ?>
    <div class="wrap">
      <h1><?php echo esc_html( $title ); ?></h1>

      <?php if ( $notice !== '' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
      <?php endif; ?>

      <?php $body(); ?>
    </div>
    <?php
  }

  /** The Connection page, and the parent menu callback. */
  public function render_connect_page() {
    $this->render_page( __( 'Connection', 'guarded-mcp' ), function () {
      $this->render_connect();
    } );
  }

  /** The Access page. */
  public function render_access_page() {
    $this->render_page( __( 'Access', 'guarded-mcp' ), function () {
      $options = $this->core->get_all_options( true );
      $this->render_access( $options );
    } );
  }

  /** The Tools page. */
  public function render_tools_page() {
    $this->render_page( __( 'Tools', 'guarded-mcp' ), function () {
      $options = $this->core->get_all_options( true );
      $this->render_tools( $options );
    } );
  }

  /** The Logging page. */
  public function render_logging_page() {
    $this->render_page( __( 'Logging', 'guarded-mcp' ), function () {
      $options = $this->core->get_all_options( true );
      $this->render_logging( $options );
    } );
  }

  /** The Audit Log page. */
  public function render_logs_page() {
    $this->render_page( __( 'Audit Log', 'guarded-mcp' ), function () {
      $this->render_logs();
    } );
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
    // A placeholder, always. Keys are stored hashed and shown once, so there is no
    // secret here to print back, which is the point of them. The snippet says where to
    // get one rather than quietly producing a config that does not work.
    $token_display = 'YOUR_KEY';
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
            <?php esc_html_e( 'Clients that support OAuth need nothing else: they will send you to a WordPress login and a consent screen. Clients that cannot do OAuth use a named key from the Access page.', 'guarded-mcp' ); ?>
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
        <?php printf(
          /* translators: %s: a link to the Access page. */
          esc_html__( 'Run this in your project, with a key from %s in place of YOUR_KEY. It stores the key in Claude Code\'s own configuration.', 'guarded-mcp' ),
          '<a href="' . esc_url( self::page_url( 'access' ) ) . '">' . esc_html__( 'the Access page', 'guarded-mcp' ) . '</a>'
        ); ?>
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
      <p class="description">
        <?php
        printf(
          /* translators: %s: link to the Access page. */
          esc_html__( 'Uses a key from the %s page in place of YOUR_KEY. OAuth clients do not need one.', 'guarded-mcp' ),
          '<a href="' . esc_url( self::page_url( 'access' ) ) . '">' . esc_html__( 'Access', 'guarded-mcp' ) . '</a>'
        );
        ?>
      </p>
    </div>

    <h2 class="title"><?php esc_html_e( 'Is this site ready', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php $this->form_head( 'self_test' ); ?>
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
    ?>
    <p class="gmcp-intro"><?php esc_html_e( 'Two ways in. A named key, for clients that cannot do OAuth, and OAuth itself for clients that can.', 'guarded-mcp' ); ?></p>

    <?php // The shared bearer token that used to sit above the keys is gone. It was
    // stored in the clear because this screen showed it back, it carried no identity so
    // the log could not say who acted, it could not expire, and it could not be limited
    // to anything. A key answers all four, and one shared secret that does none of them
    // is not a simpler option, only a quieter one. ?>

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
      <?php esc_html_e( 'A key carries a label so you can tell clients apart in the activity list, it can expire on its own, and it can be limited to a named list of tools. Keys are stored hashed, so a key is shown once and never again.', 'guarded-mcp' ); ?>
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
                <?php $this->form_head( 'revoke_key' ); ?>
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
      <?php $this->form_head( 'create_key' ); ?>
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
                <?php $this->form_head( 'revoke_app' ); ?>
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

  /** What a connected agent is offered. Nothing on this page is about who may connect. */
  private function render_tools( array $options ): void {
    $woo = class_exists( 'WooCommerce' );
    $elementor = did_action( 'elementor/loaded' );
    $kirki = class_exists( 'Kirki' );

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
    if ( $kirki ) {
      $keys[] = 'mcp_tools_kirki';
    }
    ?>
    <p class="gmcp-intro"><?php esc_html_e( 'Which groups of tools an agent is offered. A group that is off is not merely hidden: its tools are refused if asked for by name.', 'guarded-mcp' ); ?></p>

    <h2 class="title"><?php esc_html_e( 'Tool groups', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php
      $this->form_head( 'save' );
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
              <?php if ( $kirki ) : ?>
                <label>
                  <input type="checkbox" name="mcp_tools_kirki" value="1" <?php checked( !empty( $options['mcp_tools_kirki'] ) ); ?>>
                  <?php esc_html_e( 'Kirki (customizer field discovery, value get/set, Google Fonts cache)', 'guarded-mcp' ); ?>
                </label>
              <?php endif; ?>
              <label>
                <input type="checkbox" name="mcp_tools_rest" value="1" <?php checked( !empty( $options['mcp_tools_rest'] ) ); ?>>
                <?php esc_html_e( 'Generate tools from this site\'s REST API routes', 'guarded-mcp' ); ?>
              </label>
            </fieldset>
            <p class="description">
              <?php esc_html_e( 'Administration tools install code on this site, so they are off by default. Installs are restricted to the wordpress.org repository, and deletions take two steps.', 'guarded-mcp' ); ?>
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
            <?php if ( $kirki ) : ?>
              <p class="description">
                <?php esc_html_e( 'The Kirki tools resolve a field\'s storage model from its registration and write through the right path, so a value written through them lands where Kirki reads it. Writing through the generic option tools instead is a silent success: the value lands in a row nothing reads, and the front end keeps rendering the old one.', 'guarded-mcp' ); ?>
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
  * What the plugin records.
  *
  * Deliberately not on the same page as the log itself. The switches are set once
  * and the table is visited daily, and the table buried below a settings form served
  * neither. The switched-off notice over there links back here, so the way from an
  * empty log to its switch is still one click.
  */
  private function render_logging( array $options ): void {
    ?>
    <p class="gmcp-intro"><?php
      printf(
        /* translators: %s: link to the Audit Log page. */
        esc_html__( 'An agent that leaves no record is an agent you cannot review. These are the three things the plugin can remember. What it has remembered so far lives on the %s.', 'guarded-mcp' ),
        '<a href="' . esc_url( self::page_url( 'logs' ) ) . '">' . esc_html__( 'Audit Log page', 'guarded-mcp' ) . '</a>'
      ); ?></p>

    <h2 class="title"><?php esc_html_e( 'What gets recorded', 'guarded-mcp' ); ?></h2>
    <form method="post">
      <?php
      $this->form_head( 'save' );
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
              <?php esc_html_e( 'Records what a setting or post said before an agent changed it, and lets the change be put back with the wp_undo_change tool. Only writes made through this API are recorded, never your own. Fields that look like credentials are blanked and never stored; the rest of the value is kept, and putting it back leaves those fields as they are.', 'guarded-mcp' ); ?>
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
    <?php
  }

  /**
  * The record itself: the table, one entry, or the off notice.
  *
  * Nothing to configure here on purpose. The switches that fill this table live on
  * the Logging page, one menu item away, because a settings form above the table
  * buried the thing people visit daily under the thing they set once.
  */
  private function render_logs(): void {
    ?>
    <p class="gmcp-intro"><?php
      printf(
        /* translators: %s: link to the Logging page. */
        esc_html__( 'What the plugin has remembered so far. The switches that decide what gets recorded live on the %s.', 'guarded-mcp' ),
        '<a href="' . esc_url( self::page_url( 'logging' ) ) . '">' . esc_html__( 'Logging page', 'guarded-mcp' ) . '</a>'
      ); ?></p>

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
      printf(
        '<p>' . esc_html__( 'The audit log is switched off, so nothing is being recorded. Switch it on on the %s and calls from then on will appear here.', 'guarded-mcp' ) . '</p>',
        '<a href="' . esc_url( self::page_url( 'logging' ) ) . '">' . esc_html__( 'Logging page', 'guarded-mcp' ) . '</a>'
      );
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
              <p class="description"><?php esc_html_e( 'The WordPress account the call ran as. The call borrows one administrator account, so this names the account, not the person.', 'guarded-mcp' ); ?></p>
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

    <?php
    $changes = $this->change_records( $e['changes'] ?? null );
    $asked = $this->asked_for( $e['args'] ?? null, $changes );
    ?>
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
                <td><?php echo $this->value_cell( $pair['from'] ?? null,
                  __( 'not set', 'guarded-mcp' ), __( 'empty', 'guarded-mcp' ) ); ?></td>
                <td><?php echo $this->value_cell( $pair['to'] ?? null,
                  __( 'removed', 'guarded-mcp' ), __( 'empty', 'guarded-mcp' ) ); ?>
                  <?php if ( isset( $asked[ $field ] ) ) : ?>
                    <br><span class="gmcp-detail"><?php printf(
                      /* translators: %s: the value the call sent, as it was sent. */
                      esc_html__( 'the call sent %s', 'guarded-mcp' ),
                      '<code>' . esc_html( self::shorten( $asked[ $field ] ) ) . '</code>'
                    ); ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ( $asked ) : ?>
        <?php // Only when a field really does differ. Saying it on every entry would
        // train the reader to skip the one line on this screen worth stopping at. ?>
        <p class="description"><?php esc_html_e( 'A field was stored as something other than what the call sent. Values are filtered on the way in, so a call can be recorded as done and still not have stored what it asked for.', 'guarded-mcp' ); ?></p>
      <?php endif; ?>
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

  /**
  * A recorded value, or a word for what it is instead.
  *
  * Absent and empty are two different things and were shown as one word. A post created
  * with markup in its title stores an empty title, because the plugin's own filter
  * strips the markup; the record reads from null to "", and rendering that as
  * "Was: not set, Became: removed" told the reader somebody deleted a title that had
  * never existed. Null is the absence, the empty string is a value, and the column
  * headings mean the two of them differently either side, so the words are passed in.
  */
  private function value_cell( $value, string $absent, string $empty ): string {
    if ( $value === null ) {
      return '<span class="gmcp-muted">' . esc_html( $absent ) . '</span>';
    }
    if ( $value === '' ) {
      return '<span class="gmcp-muted">' . esc_html( $empty ) . '</span>';
    }
    if ( (string) $value === '[redacted]' ) {
      return '<span class="gmcp-muted" title="' . esc_attr__( 'Recorded as changed, but the value looked like a credential and was not kept.', 'guarded-mcp' ) . '">[redacted]</span>';
    }
    return '<code>' . esc_html( (string) $value ) . '</code>';
  }

  /**
  * Fields the call asked for one value and the site stored another.
  *
  * This entry is the only place both halves are in scope: every other screen has the
  * arguments or the result, never the pair. The plugin strips markup out of values on
  * the way in, so an agent that was told to put a script tag in a title gets a
  * successful call and an empty title, and nothing on the site says the two differ.
  *
  * Nothing here is inferred. A field is compared only against an argument spelled
  * exactly the same, which is how the write tools spell them: wp_create_post takes
  * post_title, not title. The argument may be nested, since wp_update_post accepts its
  * fields either at the top level or under "fields", so the whole tree is searched, but
  * only for that exact name.
  *
  * Attempted only when the entry records one changed object. A call that created three
  * posts carries one set of arguments and three records, and there is no honest way to
  * say which record a given post_title belongs to.
  *
  * @return array<string,string> field name to the value the call sent
  */
  private function asked_for( $args_json, array $changes ): array {
    if ( count( $changes ) !== 1 || empty( $changes[0]['fields'] ) ) {
      return [];
    }
    $args = json_decode( (string) $args_json, true );
    if ( !is_array( $args ) ) {
      return [];
    }

    $out = [];
    foreach ( (array) $changes[0]['fields'] as $field => $pair ) {
      $stored = $pair['to'] ?? null;
      if ( $stored === null || !is_scalar( $stored ) ) {
        continue;
      }
      $sent = self::arg_named( $args, (string) $field );
      // A redacted argument says a secret was passed and nothing about its value, so
      // it can never be compared against what was stored.
      if ( $sent === null || $sent === '[redacted]' || $sent === (string) $stored ) {
        continue;
      }
      $out[ (string) $field ] = $sent;
    }
    return $out;
  }

  /**
  * The one value given under this exact argument name, or null.
  *
  * Null when the name was not given, and equally when it was given twice with different
  * values: ambiguous is not an answer, and picking either one would be the guesswork
  * this comparison exists to avoid.
  */
  private static function arg_named( array $args, string $name ): ?string {
    $found = [];
    $walk = static function ( $node ) use ( &$walk, $name, &$found ) {
      foreach ( (array) $node as $key => $value ) {
        if ( $key === $name && is_scalar( $value ) ) {
          $found[] = (string) $value;
        }
        if ( is_array( $value ) ) {
          $walk( $value );
        }
      }
    };
    $walk( $args );

    $found = array_values( array_unique( $found ) );
    return count( $found ) === 1 ? $found[0] : null;
  }

  /**
  * A value short enough to sit in a table cell, with an ellipsis if it was cut.
  *
  * Counted in characters rather than bytes, so a cut never lands inside a multi-byte
  * one and leaves the cell holding a broken sequence. WordPress supplies mb_substr and
  * mb_strlen where the extension is missing.
  */
  private static function shorten( string $value, int $limit = 120 ): string {
    $flat = preg_replace( '/\s+/', ' ', $value );
    $flat = trim( $flat === null ? $value : $flat );
    return mb_strlen( $flat ) > $limit ? mb_substr( $flat, 0, $limit ) . '…' : $flat;
  }

  /** The changes column, decoded, or an empty list if it holds nothing usable. */
  private function change_records( $json ): array {
    $records = $json ? json_decode( (string) $json, true ) : null;
    return is_array( $records ) ? $records : [];
  }

  /**
  * What a request is allowed to say about which entries to look at.
  *
  * Taken from a supplied array rather than $_GET directly, because the export posts the
  * same filters back and has to select the rows the reader was looking at, not a wider
  * set. One place decides what a value may mean, so the list and the export cannot come
  * to different conclusions about the same query string.
  */
  private static function log_filters( array $source ): array {
    $outcome = isset( $source['gmcp_outcome'] ) ? sanitize_key( wp_unslash( $source['gmcp_outcome'] ) ) : '';
    $days = isset( $source['gmcp_since'] ) ? sanitize_key( wp_unslash( $source['gmcp_since'] ) ) : '';
    $filters = [
      'search' => isset( $source['s'] ) ? sanitize_text_field( wp_unslash( $source['s'] ) ) : '',
      'outcome' => in_array( $outcome, [ 'ok', 'refused' ], true ) ? $outcome : '',
      'tool' => isset( $source['gmcp_tool'] ) ? sanitize_key( wp_unslash( $source['gmcp_tool'] ) ) : '',
      'actor' => isset( $source['gmcp_actor'] ) ? (int) $source['gmcp_actor'] : 0,
      'since_days' => in_array( $days, [ '1', '7', '30' ], true ) ? $days : '',
      // The wide search. Truthy rather than compared against a value, because it arrives
      // from a checkbox, and an unticked box sends nothing at all rather than a false.
      // The query layer reads it only when there is a search to widen.
      'deep' => !empty( $source['gmcp_deep'] ),
    ];
    if ( $filters['since_days'] !== '' ) {
      $filters['since'] = gmdate( 'Y-m-d H:i:s', time() - ( (int) $filters['since_days'] * DAY_IN_SECONDS ) );
    }
    return $filters;
  }

  /**
  * The filters as hidden fields, under the names they arrived by.
  *
  * So a form that is not the search form still submits the view the reader is looking
  * at. Named for the request rather than for the filter array, because log_filters()
  * reads them back out under those same names.
  */
  private static function filter_fields( array $filters ): void {
    $names = [
      's' => 'search',
      'gmcp_outcome' => 'outcome',
      'gmcp_tool' => 'tool',
      'gmcp_actor' => 'actor',
      'gmcp_since' => 'since_days',
      // Carried like the rest, or an export would quietly select the narrow match while
      // the screen it claims to copy was showing the wide one, at a row count plausible
      // enough that nothing would look wrong.
      'gmcp_deep' => 'deep',
    ];
    foreach ( $names as $name => $key ) {
      if ( empty( $filters[ $key ] ) ) {
        continue;
      }
      printf(
        '<input type="hidden" name="%s" value="%s">',
        esc_attr( $name ),
        esc_attr( (string) $filters[ $key ] )
      );
    }
  }

  /**
  * The log itself, as a list table.
  *
  * Filters are read here rather than inside the table, so one place decides what a
  * query-string value is allowed to mean and the table is handed values it need not
  * check again.
  */
  private function render_entry_list(): void {
    $filters = self::log_filters( $_GET );
    $outcome = $filters['outcome'];
    ?>
    <h2 class="title"><?php esc_html_e( 'The audit log', 'guarded-mcp' ); ?></h2>
    <?php
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
      // search box drops the reader onto the Connection page they were not on. ?>
      <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_LOGS ); ?>">
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
      <?php // Only the complete pass is said here. The other two verdicts are blocks
      // below, because a partial check that reads like a whole one at a glance is the
      // thing this arrangement exists to stop.
      //
      // A complete pass is three sentences rather than one, because "all" is a claim
      // about the log and the walk only covers rows that carry a hash. Imported rows
      // are skipped rather than failed and are not in checked, so on a site holding
      // them the old sentence named the smaller number and still called it all.
      //
      // Most sites have no imported rows, and there this reads exactly as it always
      // did: a caveat that applies to few readers should not be paid for by every one
      // of them. Where there are some, the count is scoped to what was signed and the
      // carried-over rows are named in the same sentence, which is why the muted line
      // below no longer runs here. Two sentences dividing one fact between them is
      // worse than one sentence holding it.
      //
      // "Intact" survives both, because a chain really was walked and really did hold.
      // It does not survive the third: with nothing signed there is no chain, and a
      // green "intact" over a log that could not be checked at all is the largest
      // overclaim this screen could make. An empty log matches none of the three and
      // says nothing, which is the same reasoning with the numbers at zero. ?>
      <?php if ( $chain['ok'] && $chain['complete'] && $chain['checked'] > 0 && empty( $chain['imported'] ) ) : ?>
        <span class="gmcp-ok"><?php printf(
          /* translators: %s: number of entries verified. */
          esc_html__( 'The chain is intact across all %s entries.', 'guarded-mcp' ),
          esc_html( number_format_i18n( $chain['checked'] ) ) ); ?></span>
      <?php elseif ( $chain['ok'] && $chain['complete'] && $chain['checked'] > 0 ) : ?>
        <span class="gmcp-ok"><?php printf(
          /* translators: 1: entries verified, 2: entries carried over from before the chain. */
          esc_html__( 'The chain is intact across the %1$s signed entries; the other %2$s were carried over from before this log was chained and were never signed.', 'guarded-mcp' ),
          esc_html( number_format_i18n( $chain['checked'] ) ),
          esc_html( number_format_i18n( $chain['imported'] ) ) ); ?></span>
      <?php elseif ( $chain['ok'] && $chain['complete'] && !empty( $chain['imported'] ) ) : ?>
        <span class="gmcp-muted"><?php printf(
          /* translators: %s: entries carried over from before the chain. */
          esc_html__( 'None of the %s entries are signed: every one was carried over from before this log was chained, so there is no chain to check.', 'guarded-mcp' ),
          esc_html( number_format_i18n( $chain['imported'] ) ) ); ?></span>
      <?php endif; ?>
      <?php // The partial block counts only what it checked against the whole table, so
      // the carried-over rows still need saying beside it. Only alongside a verdict that
      // reached the end of its walk: a break stops the walk, so the count is whatever
      // had been passed by then rather than a count of the log. ?>
      <?php if ( $chain['ok'] && !$chain['complete'] && !empty( $chain['imported'] ) ) : ?>
        <span class="gmcp-muted"><?php printf(
          esc_html__( '%s older entries were carried over from before this log was chained and are not covered.', 'guarded-mcp' ),
          esc_html( number_format_i18n( $chain['imported'] ) ) ); ?></span>
      <?php endif; ?>
    </p>

    <?php if ( $chain['ok'] && !$chain['complete'] ) : ?>
      <div class="gmcp-verdict gmcp-verdict-partial">
        <?php // The heading carries the state in words, so the mark beside it is
        // decoration and is hidden rather than read out twice. ?>
        <p><strong><span class="gmcp-warn" aria-hidden="true">!</span>
          <?php esc_html_e( 'Only part of the chain was checked.', 'guarded-mcp' ); ?></strong></p>
        <?php // Counted rather than described. "Intact" over an unstated slice of the
        // table is the sentence that made a two per cent check read like a whole one.
        // Unchecked is total minus checked, so the two numbers account for every row:
        // an entry from before the chain was never checkable, and saying so is the
        // muted line above, not an adjustment to this arithmetic. ?>
        <p><?php printf(
          /* translators: 1: entries checked, 2: entries in the log, 3: entries not checked. */
          esc_html__( '%1$s of %2$s entries were checked, the most recent first, and those are intact. The other %3$s were not looked at.', 'guarded-mcp' ),
          esc_html( number_format_i18n( $chain['checked'] ) ),
          esc_html( number_format_i18n( $chain['total'] ) ),
          esc_html( number_format_i18n( max( 0, (int) $chain['total'] - (int) $chain['checked'] ) ) )
        ); ?></p>
        <?php // Through form_head, not by hand: the POST redirects back to the page it
        // was submitted from, and a form that forgets its action sends the reader
        // nowhere useful to read a verdict about the log they were just looking at. ?>
        <form method="post">
          <?php $this->form_head( 'verify_audit' ); ?>
          <button type="submit" class="button"><?php esc_html_e( 'Check the whole chain', 'guarded-mcp' ); ?></button>
        </form>
      </div>
    <?php elseif ( !$chain['ok'] ) : ?>
      <div class="gmcp-verdict gmcp-verdict-broken">
        <p><strong class="gmcp-fail"><span aria-hidden="true">&#10007;</span>
          <?php printf(
            /* translators: 1: entry id, 2: the reason. */
            esc_html__( 'The chain breaks at entry %1$d: %2$s', 'guarded-mcp' ),
            (int) $chain['broken_at'], esc_html( $chain['reason'] )
          ); ?></strong></p>
        <?php // The verdict names an id, so it links to it. Before this the reader was
        // handed a number and no way to look at the entry it named. ?>
        <p><a href="<?php echo esc_url( GMCP_Audit_Table::entry_url( (int) $chain['broken_at'] ) ); ?>"><?php
          esc_html_e( 'Look at that entry', 'guarded-mcp' ); ?></a></p>
      </div>
    <?php endif; ?>

    <?php // What the last full walk found, dated, because the button's own answer died
    // with the redirect that printed it and the window check can say nothing at all
    // about the rows underneath it. Never phrased as current state: see
    // GMCP_Audit::remember_full_check() for what this can and cannot notice. ?>
    <?php $last = GMCP_Audit::last_full_check(); ?>
    <?php // A remembered pass is dropped the moment the live walk disagrees with it.
    // Editing a row in place changes neither the count nor the highest id, so the record
    // stays "current" through exactly the tampering the chain is built to catch, and it
    // was measured sitting under a break saying all 50,001 entries were intact and
    // nothing had changed since. A remembered break is kept either way: it covers rows
    // the window never reaches, so the window finding nothing does not answer it. ?>
    <?php if ( $last && ( !$last['ok'] || $chain['ok'] ) ) : ?>
      <p class="<?php echo $last['ok'] ? 'gmcp-muted' : 'gmcp-fail'; ?>">
        <?php if ( !$last['ok'] ) : ?>
          <?php printf(
            /* translators: 1: when the check ran, 2: entry id, 3: the reason. */
            esc_html__( 'A check of the whole chain on %1$s found it broken at entry %2$d: %3$s', 'guarded-mcp' ),
            esc_html( $this->format_date( $last['ran_at'] ) ),
            (int) $last['broken_at'], esc_html( $last['reason'] ) ); ?>
          <a href="<?php echo esc_url( GMCP_Audit_Table::entry_url( (int) $last['broken_at'] ) ); ?>"><?php
            esc_html_e( 'Look at that entry', 'guarded-mcp' ); ?></a>
        <?php elseif ( $last['current'] ) : ?>
          <?php printf(
            /* translators: 1: when the check ran, 2: entries verified. */
            esc_html__( 'The whole chain was checked on %1$s, and all %2$s entries were intact then. Nothing has been added or removed since, but only another check can say whether an entry was altered in place.', 'guarded-mcp' ),
            esc_html( $this->format_date( $last['ran_at'] ) ),
            esc_html( number_format_i18n( $last['checked'] ) ) ); ?>
        <?php else : ?>
          <?php printf(
            /* translators: 1: when the check ran, 2: entries verified, 3: entries in the log at the time. */
            esc_html__( 'The whole chain was checked on %1$s, when %2$s of %3$s entries were intact. Entries have been added or removed since, so that no longer describes the log as it is now.', 'guarded-mcp' ),
            esc_html( $this->format_date( $last['ran_at'] ) ),
            esc_html( number_format_i18n( $last['checked'] ) ),
            esc_html( number_format_i18n( $last['total'] ) ) ); ?>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="gmcp-actions">
      <form method="post">
        <?php $this->form_head( 'prune_audit' ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Prune now', 'guarded-mcp' ); ?></button>
      </form>
      <form method="post">
        <?php $this->form_head( 'clear_activity' ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Clear everything', 'guarded-mcp' ); ?></button>
      </form>
      <?php // Each carries the filters it was pressed under, so the file holds the rows
      // on screen. A separate form per button because the action is a hidden field. ?>
      <form method="post">
        <?php $this->form_head( 'export_csv' ); ?>
        <?php self::filter_fields( $filters ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Export as CSV', 'guarded-mcp' ); ?></button>
      </form>
      <form method="post">
        <?php $this->form_head( 'export_json' ); ?>
        <?php self::filter_fields( $filters ); ?>
        <button type="submit" class="button"><?php esc_html_e( 'Export as JSON', 'guarded-mcp' ); ?></button>
      </form>
    </div>
    <p class="description gmcp-intro">
      <?php esc_html_e( 'Every call, including the refused ones, with the arguments it was given. Anything that looks like a password or a key is replaced before the entry is written, so what you see here is what was recorded, not a redacted view of something fuller. Each entry hashes the one before it, so a row that is edited or removed later shows up as a break in the chain rather than disappearing quietly.', 'guarded-mcp' ); ?>
    </p>
    <?php // After the paragraph above rather than before it, so the sentence about what
    // an export cannot hand over can lean on the one that says why, instead of saying
    // the same thing a second time a paragraph away from the first. ?>
    <p class="description gmcp-intro">
      <?php esc_html_e( 'An export holds the entries the filters above select: not the whole log, and not only the page you are looking at. The CSV is one line per entry for reading, the JSON carries the arguments and the changes as well, and a match too large to send in one file is cut at its oldest end with the file name saying so. Because the redaction happened on the way in rather than on the way out, an export cannot give up a secret the table never held.', 'guarded-mcp' ); ?>
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
