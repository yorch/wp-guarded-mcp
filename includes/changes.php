<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* What actually changed during a tool call, worked out once.
*
* Two subsystems need the same facts from different angles. The change journal needs the
* previous value so it can put it back. The audit log needs a description of the
* difference so a reader can see that a post went from private to publish, or that the
* title changed and the body did not. Both were going to compute it from the same
* WordPress hooks, and two copies of a diff drift: the day somebody adds a field to one
* list, the other silently stops mentioning it.
*
* So the listening and the diffing happen here, and each subsystem takes what it needs.
* The journal subscribes to gmcp_change and keeps its own policy about what is safe and
* sensible to undo. The audit log asks for captured() at the end of the call and stores a
* summary. Neither of them listens to WordPress any more.
*
* It listens to WordPress rather than to the tools, which is the same choice the journal
* made and for the same reason: every write eventually goes through wp_update_post or
* update_option, so hooking there covers tools that did not exist when this was written.
* A tool that changes a widget or a menu item is really writing an option or a post, and
* this is where that is translated back into what a person would call it.
*
* Only writes made during a tool call are recorded. A person saving a settings page is
* not the agent's doing.
*
* Two deliberate omissions.
*
* POST META IS NOT WATCHED. It looks like the obvious next listener and it is not. Every
* post save writes _edit_lock and _edit_last, every block editor save writes more, and a
* handful of plugins write their own on save_post. An audit entry for one title change
* would arrive with a dozen meta rows attached and the one line worth reading would be
* buried. If a tool that edits meta deliberately arrives later, give it its own listener
* rather than watching everything.
*
* VALUES ARE HELD, NOT WRITTEN. The records carry the real before and after values,
* because the journal needs them to revert. Nothing here writes them anywhere: summarise()
* is what reaches the audit table, and it replaces anything credential-shaped and anything
* large with a description. The rule is that a value leaves this class only through a
* consumer that has decided what it is allowed to keep.
*/
class GMCP_Changes {

  /** True only while a tool call is in flight. */
  private static $recording = false;

  /** The tool that was in flight, stamped onto every record. */
  private static $tool = '';

  /** Records observed during the current call. */
  private static $seen = [];

  /**
  * How many further changes there were after the cap.
  *
  * A counter rather than more records. Holding a placeholder per observation meant a
  * call that wrote ten thousand options kept ten thousand arrays alive to say a number
  * the summary prints once, which turned a bounded record list back into an unbounded
  * one by the back door.
  */
  private static $overflow = 0;

  /** Term and comment diffs need the row as it was, which only a pre-write hook has. */
  private static $before = [];

  /** Records kept per call. A bulk tool can write hundreds; nobody reads hundreds. */
  const MAX_RECORDS = 40;

  /** Values longer than this are described rather than quoted, in the summary only. */
  const MAX_VALUE = 120;

  /**
  * The post fields worth diffing.
  *
  * Identical to the list the journal used to keep, and deliberately so: the journal
  * restores exactly the fields a record names, so widening this list here would widen
  * what undo puts back. post_password is absent for that reason rather than by oversight.
  */
  const POST_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_status',
    'post_name', 'post_parent', 'menu_order' ];

  /** User fields worth diffing. user_pass is here and is never recorded in the clear. */
  const USER_FIELDS = [ 'user_login', 'user_email', 'user_url', 'user_nicename',
    'display_name', 'user_pass' ];

  public function __construct() {
    add_action( 'gmcp_tool_start', [ $this, 'start' ], 10, 1 );
    // Priority 1, ahead of the audit log's recorder at 5. Recording stops here but the
    // records stay readable, because the entry that describes them is written after.
    add_action( 'gmcp_tool_called', [ $this, 'stop' ], 1 );
    // Belt and braces for worker SAPIs. Under mod_php a static dies with the request, so
    // a fatal between start and stop costs nothing. Under FrankenPHP or RoadRunner the
    // worker survives, and a fatal mid-tool would leave the flag set for whatever that
    // worker served next, quietly attributing a person's own wp-admin save to the agent.
    add_action( 'shutdown', [ $this, 'stop' ], 0 );

    add_action( 'updated_option', [ $this, 'option_changed' ], 10, 3 );
    add_action( 'added_option', [ $this, 'option_added' ], 10, 2 );
    add_action( 'deleted_option', [ $this, 'option_deleted' ], 10, 1 );

    add_action( 'post_updated', [ $this, 'post_changed' ], 10, 3 );
    add_action( 'wp_insert_post', [ $this, 'post_inserted' ], 10, 3 );
    add_action( 'deleted_post', [ $this, 'post_deleted' ], 10, 2 );

    // Post meta, in pre/post pairs. The previous value only exists before the write, and
    // whether the write happened at all is only known after: update_metadata() returns
    // false and fires nothing when the new value equals the old, so recording at the
    // pre-hook alone would journal edits that never occurred.
    add_action( 'update_post_meta', [ $this, 'meta_before' ], 10, 4 );
    add_action( 'updated_post_meta', [ $this, 'meta_updated' ], 10, 4 );
    add_action( 'added_post_meta', [ $this, 'meta_added' ], 10, 4 );
    add_action( 'delete_post_meta', [ $this, 'meta_delete_before' ], 10, 4 );
    add_action( 'deleted_post_meta', [ $this, 'meta_deleted' ], 10, 4 );
    // An attachment is a post and fires neither of the two hooks above: wp_insert_post
    // branches for attachments and fires these instead. Listening to post_updated alone
    // meant an upload and a rename through the media tools both recorded nothing, which
    // was measured rather than assumed. attachment_updated has post_updated's signature.
    add_action( 'add_attachment', [ $this, 'attachment_added' ], 10, 1 );
    add_action( 'attachment_updated', [ $this, 'post_changed' ], 10, 3 );

    add_action( 'user_register', [ $this, 'user_registered' ], 10, 1 );
    add_action( 'profile_update', [ $this, 'user_changed' ], 10, 2 );
    add_action( 'set_user_role', [ $this, 'user_role_changed' ], 10, 3 );
    add_action( 'deleted_user', [ $this, 'user_deleted' ], 10, 3 );

    add_action( 'created_term', [ $this, 'term_created' ], 10, 3 );
    add_action( 'edit_term', [ $this, 'term_before' ], 10, 3 );
    add_action( 'edited_term', [ $this, 'term_changed' ], 10, 3 );
    add_action( 'delete_term', [ $this, 'term_deleted' ], 10, 4 );

    add_action( 'wp_insert_comment', [ $this, 'comment_inserted' ], 10, 2 );
    add_action( 'transition_comment_status', [ $this, 'comment_status_changed' ], 10, 3 );
    add_action( 'deleted_comment', [ $this, 'comment_deleted' ], 10, 2 );

    add_action( 'activated_plugin', [ $this, 'plugin_activated' ], 10, 1 );
    add_action( 'deactivated_plugin', [ $this, 'plugin_deactivated' ], 10, 1 );
    add_action( 'switch_theme', [ $this, 'theme_switched' ], 10, 3 );
  }

  #region The in-flight signal

  /**
  * An id for the call now starting, stamped on every record it produces.
  *
  * One tool call routinely changes several things: writing a page can move an option that
  * some other plugin keeps in step with it, and a page-builder save writes a handful of
  * meta keys. Those arrive as separate records, which is right, and then read as a list of
  * unrelated events, which is wrong. The id is what lets the log say "these happened
  * because of that one call" and what lets undo put a whole call back rather than asking
  * somebody to spot its pieces among their neighbours.
  */
  private static $call = '';

  public static function call_id(): string {
    return self::$call;
  }

  public function start( $tool ): void {
    self::$recording = true;
    self::$tool = (string) $tool;
    self::$call = bin2hex( random_bytes( 5 ) );
    self::$seen = [];
    self::$overflow = 0;
    self::$before = [];
  }

  public function stop(): void {
    self::$recording = false;
  }

  /**
  * Stop recording without losing what is already recorded, and put it back afterwards.
  *
  * The journal's revert writes options and posts like anything else, and recording that
  * would put the change back on the list as though the agent had made it.
  */
  public static function pause(): bool {
    $was = self::$recording;
    self::$recording = false;
    return $was;
  }

  public static function resume( bool $was ): void {
    self::$recording = $was;
  }

  /** The records observed during the call now ending, and how many did not fit. */
  public static function captured(): array {
    $out = self::$seen;
    if ( self::$overflow ) {
      $out[] = [ 'kind' => 'overflow', 'count' => self::$overflow ];
    }
    return $out;
  }

  #endregion

  #region Recording

  /**
  * @param array $record kind, op, id, subject, label, fields
  */
  private function record( array $record ): void {
    $record['tool'] = self::$tool;
    $record['call'] = self::$call;
    // Counted past the cap, so a truncated summary can say how many were left out rather
    // than implying the call changed forty things exactly.
    if ( count( self::$seen ) < self::MAX_RECORDS ) {
      self::$seen[] = $record;
    }
    else {
      self::$overflow++;
    }
    // The journal keeps its own view of which of these it can undo, so it gets every
    // record and decides. Anything else that wants to watch an agent's writes can hook
    // this too, which is why it carries the values rather than a description of them.
    do_action( 'gmcp_change', $record );
  }

  private static function diff( array $fields, $before, $after ): array {
    $changed = [];
    foreach ( $fields as $field ) {
      $was = is_object( $before ) ? ( $before->$field ?? null ) : ( $before[ $field ] ?? null );
      $now = is_object( $after ) ? ( $after->$field ?? null ) : ( $after[ $field ] ?? null );
      if ( $was !== $now ) {
        $changed[ $field ] = [ 'from' => $was, 'to' => $now ];
      }
    }
    return $changed;
  }

  #endregion

  #region Options, and the things that are really options

  /**
  * Option names that are noise rather than change.
  *
  * Public and static because the journal needs the same list: it adds what undo cannot
  * sensibly put back on top of this, rather than keeping a second copy that drifts. That
  * is how option_guard() ended up in GMCP_Core, and it had already drifted twice by then.
  *
  * This plugin's own rows are excluded because they are its bookkeeping rather than the
  * agent's doing: a named key updates its last-used stamp on every single call, and an
  * audit log that says so on every line says nothing.
  *
  * active_plugins and category_children are excluded for a sharper reason. Both are
  * written as a side effect of something this class already records properly, and they
  * arrived in the log as a second, worse description of it: activating a plugin produced
  * an entry naming the plugin and another saying a setting called active_plugins went
  * from 45 bytes to 90, and every term write produced two entries about a hierarchy
  * cache. The one that reads like what happened is kept.
  */
  public static function is_noise( string $key ): bool {
    if ( strpos( $key, '_transient' ) === 0 || strpos( $key, '_site_transient' ) === 0 ) {
      return true;
    }
    if ( strpos( $key, 'gmcp_' ) === 0 || strpos( $key, '_wp_' ) === 0 ) {
      return true;
    }
    return in_array( $key, [ 'cron', 'rewrite_rules', 'recently_activated', 'auto_updater.lock',
      'db_upgraded', 'can_compress_scripts', 'user_count', 'admin_email_lifespan',
      'active_plugins', 'category_children' ], true );
  }

  /**
  * What an option really is, to somebody reading the log.
  *
  * A widget lives in an option and a menu item is a post, so a change to either arrives
  * here wearing the wrong clothes. An entry reading "option widget_text changed" is
  * technically true and nearly useless. The names are WordPress's own storage
  * conventions rather than a guess: core writes widget_<id_base> for each widget type,
  * sidebars_widgets for which widget sits in which area, and theme_mods_<stylesheet>
  * for everything the customiser holds.
  *
  * @return array{0:string,1:string} subject, and the label to show after it
  */
  private function option_subject( string $key ): array {
    if ( $key === 'sidebars_widgets' ) {
      return [ 'widget area', 'which widgets sit in which area' ];
    }
    if ( strpos( $key, 'widget_' ) === 0 ) {
      return [ 'widget', substr( $key, strlen( 'widget_' ) ) ];
    }
    if ( strpos( $key, 'theme_mods_' ) === 0 ) {
      return [ 'theme setting', substr( $key, strlen( 'theme_mods_' ) ) ];
    }
    return [ 'setting', $key ];
  }

  public function option_changed( $key, $old, $new ): void {
    if ( !self::$recording || !is_string( $key ) || self::is_noise( $key ) || $old === $new ) {
      return;
    }
    [ $subject, $label ] = $this->option_subject( $key );
    $this->record( [
      'kind' => 'option',
      'op' => 'updated',
      'id' => $key,
      'subject' => $subject,
      'label' => $label,
      'fields' => [ 'value' => [ 'from' => $old, 'to' => $new ] ],
    ] );
  }

  public function option_added( $key, $value ): void {
    if ( !self::$recording || !is_string( $key ) || self::is_noise( $key ) ) {
      return;
    }
    [ $subject, $label ] = $this->option_subject( $key );
    // "Previously absent" has to be a distinct state from "previously empty", or undo
    // leaves a row behind that WordPress never had.
    $this->record( [
      'kind' => 'option',
      'op' => 'created',
      'id' => $key,
      'subject' => $subject,
      'label' => $label,
      'fields' => [ 'value' => [ 'from' => null, 'to' => $value ] ],
    ] );
  }

  public function option_deleted( $key ): void {
    if ( !self::$recording || !is_string( $key ) || self::is_noise( $key ) ) {
      return;
    }
    [ $subject, $label ] = $this->option_subject( $key );
    $this->record( [
      'kind' => 'option',
      'op' => 'deleted',
      'id' => $key,
      'subject' => $subject,
      'label' => $label,
      'fields' => [],
    ] );
  }

  #endregion

  #region Posts, and the things that are really posts

  /** A menu item is a post, and saying "nav_menu_item 41 changed" helps nobody. */
  private function post_subject( WP_Post $post ): string {
    if ( $post->post_type === 'nav_menu_item' ) {
      return 'menu item';
    }
    $object = get_post_type_object( $post->post_type );
    return $object ? strtolower( $object->labels->singular_name ) : $post->post_type;
  }

  private function post_label( WP_Post $post ): string {
    $title = (string) $post->post_title;
    if ( $title === '' && $post->post_type === 'nav_menu_item' ) {
      // Menu items keep their visible text in the linked object, not in post_title.
      $title = (string) get_the_title( (int) get_post_meta( $post->ID, '_menu_item_object_id', true ) );
    }
    return mb_substr( $title, 0, 80 );
  }

  /** Revisions are posts, and saving one fires these hooks. So does the empty auto-draft. */
  private function skip_post( WP_Post $post ): bool {
    return $post->post_type === 'revision' || $post->post_status === 'auto-draft';
  }

  public function post_changed( $post_id, $after, $before ): void {
    if ( !self::$recording || !( $before instanceof WP_Post ) || !( $after instanceof WP_Post ) ) {
      return;
    }
    if ( $this->skip_post( $after ) ) {
      return;
    }
    $changed = self::diff( self::POST_FIELDS, $before, $after );
    if ( !$changed ) {
      return;
    }
    $this->record( [
      'kind' => 'post',
      'op' => 'updated',
      'id' => (int) $post_id,
      'subject' => $this->post_subject( $after ),
      'label' => $this->post_label( $after ),
      'fields' => $changed,
    ] );
  }

  public function post_inserted( $post_id, $post, $update ): void {
    if ( !self::$recording || $update || !( $post instanceof WP_Post ) || $this->skip_post( $post ) ) {
      return;
    }
    $this->record( [
      'kind' => 'post',
      'op' => 'created',
      'id' => (int) $post_id,
      'subject' => $this->post_subject( $post ),
      'label' => $this->post_label( $post ),
      'fields' => [
        'post_status' => [ 'from' => null, 'to' => $post->post_status ],
        'post_title' => [ 'from' => null, 'to' => $post->post_title ],
      ],
    ] );
  }

  /**
  * Meta keys that are bookkeeping rather than content.
  *
  * The same reasoning as is_noise() for options, and the list is short on purpose. Two
  * kinds qualify: keys that say who is editing rather than what the post holds, and keys
  * Elementor derives from _elementor_data and regenerates on demand. Journalling the
  * derived ones would record a second, worse description of a change already recorded
  * properly, and restoring one without the document it was derived from would put back a
  * stylesheet that no longer matches the page.
  *
  * Filterable as a whole rather than as an addendum, so a site can remove an entry as
  * well as add one. A key not listed here is journalled, which is the safe default: the
  * cost of a needless entry is noise, and the cost of a missing one is an edit nobody can
  * put back.
  */
  public static function meta_noise_keys(): array {
    return (array) apply_filters( 'gmcp_meta_noise_keys', [
      '_edit_lock', '_edit_last',
      '_pingme', '_encloseme',
      '_elementor_css', '_elementor_page_assets', '_elementor_controls_usage',
      '_elementor_inspector_data', '_elementor_element_cache',
    ] );
  }

  /** Whether a meta write is worth recording at all. */
  private function skip_meta( $object_id, $meta_key ): bool {
    if ( !self::$recording || !is_string( $meta_key ) || $meta_key === '' ) {
      return true;
    }
    if ( in_array( $meta_key, self::meta_noise_keys(), true ) ) {
      return true;
    }
    $post = get_post( (int) $object_id );
    return !$post || $this->skip_post( $post );
  }

  /** Where a pre-hook leaves the previous value for its matching post-hook. */
  private function meta_stash_key( $object_id, $meta_key ): string {
    return 'meta:' . (int) $object_id . ':' . $meta_key;
  }

  public function meta_before( $meta_id, $object_id, $meta_key, $meta_value ): void {
    if ( $this->skip_meta( $object_id, $meta_key ) ) {
      return;
    }
    // Read before the write, because afterwards it is gone. single = true matches how
    // update_post_meta() without a prior value behaves and how undo will write it back.
    self::$before[ $this->meta_stash_key( $object_id, $meta_key ) ] = [
      'value' => get_post_meta( (int) $object_id, $meta_key, true ),
      'existed' => metadata_exists( 'post', (int) $object_id, $meta_key ),
    ];
  }

  public function meta_updated( $meta_id, $object_id, $meta_key, $meta_value ): void {
    if ( $this->skip_meta( $object_id, $meta_key ) ) {
      return;
    }
    $stash = self::$before[ $this->meta_stash_key( $object_id, $meta_key ) ] ?? null;
    unset( self::$before[ $this->meta_stash_key( $object_id, $meta_key ) ] );
    $this->record_meta( (int) $object_id, $meta_key, 'updated', $stash['value'] ?? null, !empty( $stash['existed'] ) );
  }

  public function meta_added( $meta_id, $object_id, $meta_key, $meta_value ): void {
    if ( $this->skip_meta( $object_id, $meta_key ) ) {
      return;
    }
    // Nothing was there. Recorded as a distinct state rather than as "was empty", or undo
    // leaves a row behind that the post never had.
    $this->record_meta( (int) $object_id, $meta_key, 'created', null, false );
  }

  public function meta_delete_before( $meta_ids, $object_id, $meta_key, $meta_value ): void {
    if ( $this->skip_meta( $object_id, $meta_key ) ) {
      return;
    }
    self::$before[ $this->meta_stash_key( $object_id, $meta_key ) ] = [
      'value' => get_post_meta( (int) $object_id, $meta_key, true ),
      'existed' => metadata_exists( 'post', (int) $object_id, $meta_key ),
    ];
  }

  public function meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ): void {
    if ( $this->skip_meta( $object_id, $meta_key ) ) {
      return;
    }
    $stash = self::$before[ $this->meta_stash_key( $object_id, $meta_key ) ] ?? null;
    unset( self::$before[ $this->meta_stash_key( $object_id, $meta_key ) ] );
    $this->record_meta( (int) $object_id, $meta_key, 'deleted', $stash['value'] ?? null, !empty( $stash['existed'] ) );
  }

  private function record_meta( int $post_id, string $meta_key, string $op, $previous, bool $existed ): void {
    $post = get_post( $post_id );
    $this->record( [
      'kind' => 'meta',
      'op' => $op,
      'id' => $post_id,
      'subject' => $post ? $this->post_subject( $post ) : 'post',
      'label' => $post ? $this->post_label( $post ) : (string) $post_id,
      'meta_key' => $meta_key,
      'existed' => $existed,
      // Carried whole rather than diffed. A meta value has no fields to compare and the
      // journal needs the previous value itself to put it back.
      'previous' => $previous,
    ] );
  }

  public function attachment_added( $post_id ): void {
    $this->post_inserted( $post_id, get_post( (int) $post_id ), false );
  }

  public function post_deleted( $post_id, $post = null ): void {
    if ( !self::$recording || !( $post instanceof WP_Post ) || $this->skip_post( $post ) ) {
      return;
    }
    $this->record( [
      'kind' => 'post',
      'op' => 'deleted',
      'id' => (int) $post_id,
      'subject' => $this->post_subject( $post ),
      'label' => $this->post_label( $post ),
      'fields' => [],
    ] );
  }

  #endregion

  #region Users

  public function user_registered( $user_id ): void {
    if ( !self::$recording ) {
      return;
    }
    $user = get_userdata( (int) $user_id );
    if ( !$user ) {
      return;
    }
    $this->record( [
      'kind' => 'user',
      'op' => 'created',
      'id' => (int) $user_id,
      'subject' => 'user',
      'label' => (string) $user->user_login,
      'fields' => [
        'user_email' => [ 'from' => null, 'to' => $user->user_email ],
        'roles' => [ 'from' => null, 'to' => implode( ', ', (array) $user->roles ) ],
      ],
    ] );
  }

  public function user_changed( $user_id, $before ): void {
    if ( !self::$recording || !is_object( $before ) ) {
      return;
    }
    $after = get_userdata( (int) $user_id );
    if ( !$after ) {
      return;
    }
    // user_pass is compared as the stored hash, which changes when the password does.
    // What reaches the log is the fact that it changed: summarise() redacts the field by
    // name, so neither the old hash nor the new one is written down.
    $changed = self::diff( self::USER_FIELDS, $before->data ?? $before, $after->data );
    if ( !$changed ) {
      return;
    }
    $this->record( [
      'kind' => 'user',
      'op' => 'updated',
      'id' => (int) $user_id,
      'subject' => 'user',
      'label' => (string) $after->user_login,
      'fields' => $changed,
    ] );
  }

  /**
  * A role change is a separate hook and the most consequential user write there is.
  *
  * wp_update_user fires this as well as profile_update when the role is part of the
  * call, so the two arrive as two records. That is honest: they are two different
  * changes, and collapsing them would mean holding one back to see whether the other
  * turned up.
  */
  public function user_role_changed( $user_id, $role, $old_roles ): void {
    if ( !self::$recording ) {
      return;
    }
    $user = get_userdata( (int) $user_id );
    $this->record( [
      'kind' => 'user',
      'op' => 'updated',
      'id' => (int) $user_id,
      'subject' => 'user',
      'label' => $user ? (string) $user->user_login : (string) $user_id,
      'fields' => [
        'roles' => [ 'from' => implode( ', ', (array) $old_roles ), 'to' => (string) $role ],
      ],
    ] );
  }

  public function user_deleted( $user_id, $reassign, $user = null ): void {
    if ( !self::$recording ) {
      return;
    }
    $this->record( [
      'kind' => 'user',
      'op' => 'deleted',
      'id' => (int) $user_id,
      'subject' => 'user',
      'label' => is_object( $user ) ? (string) $user->user_login : (string) $user_id,
      'fields' => $reassign ? [ 'content_reassigned_to' => [ 'from' => null, 'to' => (int) $reassign ] ] : [],
    ] );
  }

  #endregion

  #region Terms

  /** A navigation menu is a term in the nav_menu taxonomy, and nobody calls it that. */
  private static function taxonomy_subject( string $taxonomy ): string {
    return $taxonomy === 'nav_menu' ? 'menu' : $taxonomy;
  }

  public function term_created( $term_id, $tt_id, $taxonomy ): void {
    if ( !self::$recording ) {
      return;
    }
    $term = get_term( (int) $term_id, (string) $taxonomy );
    $this->record( [
      'kind' => 'term',
      'op' => 'created',
      'id' => (int) $term_id,
      'subject' => self::taxonomy_subject( (string) $taxonomy ),
      'label' => $term instanceof WP_Term ? (string) $term->name : (string) $term_id,
      'fields' => $term instanceof WP_Term
        ? [ 'name' => [ 'from' => null, 'to' => $term->name ], 'slug' => [ 'from' => null, 'to' => $term->slug ] ]
        : [],
    ] );
  }

  /**
  * edited_term fires after the write and is handed no previous values, so the row has to
  * be read while it still says what it used to. This is that read.
  */
  public function term_before( $term_id, $tt_id, $taxonomy ): void {
    if ( !self::$recording ) {
      return;
    }
    $term = get_term( (int) $term_id, (string) $taxonomy );
    if ( $term instanceof WP_Term ) {
      self::$before[ 'term:' . (int) $term_id ] = $term->to_array();
    }
  }

  public function term_changed( $term_id, $tt_id, $taxonomy ): void {
    if ( !self::$recording ) {
      return;
    }
    $key = 'term:' . (int) $term_id;
    $before = self::$before[ $key ] ?? null;
    unset( self::$before[ $key ] );
    $after = get_term( (int) $term_id, (string) $taxonomy );
    if ( !is_array( $before ) || !( $after instanceof WP_Term ) ) {
      return;
    }
    $changed = self::diff( [ 'name', 'slug', 'description', 'parent' ], $before, $after->to_array() );
    if ( !$changed ) {
      return;
    }
    $this->record( [
      'kind' => 'term',
      'op' => 'updated',
      'id' => (int) $term_id,
      'subject' => self::taxonomy_subject( (string) $taxonomy ),
      'label' => (string) $after->name,
      'fields' => $changed,
    ] );
  }

  public function term_deleted( $term_id, $tt_id, $taxonomy, $deleted = null ): void {
    if ( !self::$recording ) {
      return;
    }
    $this->record( [
      'kind' => 'term',
      'op' => 'deleted',
      'id' => (int) $term_id,
      'subject' => self::taxonomy_subject( (string) $taxonomy ),
      'label' => is_object( $deleted ) ? (string) $deleted->name : (string) $term_id,
      'fields' => [],
    ] );
  }

  #endregion

  #region Comments

  public function comment_inserted( $comment_id, $comment = null ): void {
    if ( !self::$recording || !is_object( $comment ) ) {
      return;
    }
    $this->record( [
      'kind' => 'comment',
      'op' => 'created',
      'id' => (int) $comment_id,
      'subject' => 'comment',
      'label' => 'on post ' . (int) $comment->comment_post_ID,
      'fields' => [
        'comment_approved' => [ 'from' => null, 'to' => (string) $comment->comment_approved ],
      ],
    ] );
  }

  /**
  * Approving, unapproving, spamming or trashing a comment.
  *
  * This is the comment change worth auditing. Whether a comment says one thing or
  * another matters far less than whether an agent published something a stranger wrote,
  * which is the specific way this plugin expects to be got at.
  */
  public function comment_status_changed( $new_status, $old_status, $comment ): void {
    if ( !self::$recording || !is_object( $comment ) || $new_status === $old_status ) {
      return;
    }
    $this->record( [
      'kind' => 'comment',
      'op' => 'updated',
      'id' => (int) $comment->comment_ID,
      'subject' => 'comment',
      'label' => 'on post ' . (int) $comment->comment_post_ID,
      'fields' => [ 'status' => [ 'from' => (string) $old_status, 'to' => (string) $new_status ] ],
    ] );
  }

  public function comment_deleted( $comment_id, $comment = null ): void {
    if ( !self::$recording ) {
      return;
    }
    $this->record( [
      'kind' => 'comment',
      'op' => 'deleted',
      'id' => (int) $comment_id,
      'subject' => 'comment',
      'label' => is_object( $comment ) ? 'on post ' . (int) $comment->comment_post_ID : (string) $comment_id,
      'fields' => [],
    ] );
  }

  #endregion

  #region Plugins and themes

  public function plugin_activated( $plugin ): void {
    if ( !self::$recording ) {
      return;
    }
    $this->record( [
      'kind' => 'plugin',
      'op' => 'updated',
      'id' => (string) $plugin,
      'subject' => 'plugin',
      'label' => (string) $plugin,
      'fields' => [ 'state' => [ 'from' => 'inactive', 'to' => 'active' ] ],
    ] );
  }

  public function plugin_deactivated( $plugin ): void {
    if ( !self::$recording ) {
      return;
    }
    $this->record( [
      'kind' => 'plugin',
      'op' => 'updated',
      'id' => (string) $plugin,
      'subject' => 'plugin',
      'label' => (string) $plugin,
      'fields' => [ 'state' => [ 'from' => 'active', 'to' => 'inactive' ] ],
    ] );
  }

  public function theme_switched( $new_name, $new_theme = null, $old_theme = null ): void {
    if ( !self::$recording ) {
      return;
    }
    $this->record( [
      'kind' => 'theme',
      'op' => 'updated',
      'id' => is_object( $new_theme ) ? (string) $new_theme->get_stylesheet() : (string) $new_name,
      'subject' => 'theme',
      'label' => (string) $new_name,
      'fields' => [ 'active_theme' => [
        'from' => is_object( $old_theme ) ? (string) $old_theme->get( 'Name' ) : null,
        'to' => (string) $new_name,
      ] ],
    ] );
  }

  #endregion

  #region Summarising, for the audit log

  /**
  * One value, as the audit log is allowed to keep it.
  *
  * Three outcomes, and each is information. A short scalar is kept as it is, because
  * "private" to "publish" is the whole point. Anything credential-shaped becomes
  * [redacted], which records that a secret changed without recording either version of
  * it. Anything long or structured becomes its size, which is what makes "the title
  * changed and the body did not" answerable without keeping a copy of the body.
  */
  private static function describe( string $field, $value, string $context = '' ) {
    // The context is the name of the thing the field belongs to, and for an option it is
    // the only name that says anything. An option record's field is always called
    // "value", so testing the field name alone asked whether "value" looks like a secret,
    // which it never does, while the option was called mailchimp_key and said so plainly.
    // Both names are tested, and either one is enough to redact.
    if ( GMCP_Core::field_looks_secret( $field )
      || ( $context !== '' && GMCP_Core::field_looks_secret( $context ) )
      || GMCP_Core::holds_credential( $value ) ) {
      return '[redacted]';
    }
    if ( $value === null ) {
      return null;
    }
    if ( is_bool( $value ) ) {
      return $value ? 'true' : 'false';
    }
    if ( is_scalar( $value ) ) {
      $text = (string) $value;
      return mb_strlen( $text ) <= self::MAX_VALUE
        ? $text
        : '[' . size_format( strlen( $text ) ) . ' of text]';
    }
    // An array or an object gets the same allowance as a string rather than being
    // described by size on principle. A widget's settings and most option values are a
    // few short fields, and "[115 B of structured data]" tells a reader nothing they
    // could act on when the whole value would have fitted.
    $json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
    if ( is_string( $json ) && mb_strlen( $json ) <= self::MAX_VALUE ) {
      return $json;
    }
    return '[' . size_format( strlen( maybe_serialize( $value ) ) ) . ' of structured data]';
  }

  /**
  * The records as JSON for one audit row, or null when nothing was observed.
  *
  * Null rather than an empty array, and that distinction carries weight: the audit log's
  * hash chain reads a row with no changes column as a row written before the column
  * existed, so an empty string here would quietly move a row into the wrong hashing
  * scheme. @see GMCP_Audit::hash().
  *
  * The byte cap is the same idea as the one on arguments, and needs the same honesty: a
  * summary that was cut short says so and says how much was cut, so a reader can tell a
  * large change from a missing one.
  */
  public static function summarise( array $records, int $maxBytes ): ?string {
    if ( !$records ) {
      return null;
    }
    $out = [];
    $dropped = 0;
    foreach ( $records as $record ) {
      if ( ( $record['kind'] ?? '' ) === 'overflow' ) {
        $dropped += max( 1, (int) ( $record['count'] ?? 1 ) );
        continue;
      }
      // An option's own name decides whether its value is credential-shaped. Nothing
      // else identifies itself that way, so nothing else supplies a context.
      $context = ( $record['kind'] ?? '' ) === 'option' ? (string) ( $record['id'] ?? '' ) : '';
      $fields = [];
      foreach ( (array) ( $record['fields'] ?? [] ) as $field => $pair ) {
        $fields[ $field ] = [
          'from' => self::describe( (string) $field, $pair['from'] ?? null, $context ),
          'to' => self::describe( (string) $field, $pair['to'] ?? null, $context ),
        ];
      }
      $out[] = [
        'what' => trim( ( $record['subject'] ?? $record['kind'] ) . ' ' . ( $record['id'] ?? '' ) ),
        'label' => (string) ( $record['label'] ?? '' ),
        'op' => (string) ( $record['op'] ?? '' ),
        'kind' => (string) ( $record['kind'] ?? '' ),
        'fields' => $fields,
      ];
    }

    // Trimmed from the end, oldest change first: the first thing a call did is usually
    // the thing it was asked to do, and the rest are consequences.
    //
    // The marker is measured along with what it describes rather than added afterwards.
    // Appending it once the loop had finished pushed the row back over the cap by the
    // length of the marker, which is a small overshoot and still an unenforced limit.
    $encode = function ( array $items, int $short ) {
      if ( $short ) {
        $items[] = [ '__gmcp_more' => $short,
          'note' => 'more changes were made than fit in one entry' ];
      }
      return (string) wp_json_encode( $items, JSON_UNESCAPED_SLASHES );
    };

    $json = $encode( $out, $dropped );
    while ( $out && strlen( $json ) > $maxBytes ) {
      array_pop( $out );
      $dropped++;
      $json = $encode( $out, $dropped );
    }
    return $json === '' ? null : $json;
  }

  #endregion
}
