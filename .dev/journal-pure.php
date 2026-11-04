<?php
/**
* Exercises the change journal's undo logic without WordPress.
*
* Run from anywhere:  php .dev/journal-pure.php
* Exit status is the number of failed checks, capped at 255. Not shipped.
*
* WHAT THIS COVERS. The real includes/journal.php and includes/changes.php are loaded, and
* the pieces of WordPress they call are replaced by an in-memory options table and a small
* stand-in for GMCP_Core. That is enough to drive the real observe(), revert(), recent() and
* mark_in_log() for the option and theme-setting kinds, including undoing an undo and
* refusing to undo an undo whose target changed.
*
* WHAT IT DOES NOT COVER. Post, post-meta, term and term-assignment entries need a database
* and WordPress's own term and post functions, and are covered only by the (unrun) smoke
* additions in smoke-admin.sh. The stand-in for GMCP_Core is a stand-in: it proves the
* journal asks the policy and honours the answer, not that the real policy is right.
*
* Each "X is absent" check has a control beside it showing the probe can find a present X.
*/

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['gmcp_t_options'] = [];
$GLOBALS['gmcp_t_can_call'] = true;
// Drafts-only caller, and the posts and term sets the post/term stand-ins below answer for.
$GLOBALS['gmcp_t_drafts_only'] = false;
$GLOBALS['gmcp_t_posts'] = [ 77 => [ 'post_status' => 'publish', 'post_type' => 'post' ], 78 => [ 'post_status' => 'draft', 'post_type' => 'post' ], 79 => [ 'post_status' => 'publish', 'post_type' => 'shop_order' ] ];
$GLOBALS['gmcp_t_terms_of'] = [ 77 => [ 3 ], 78 => [ 3 ], 79 => [ 3 ] ];
$GLOBALS['gmcp_t_term_ids'] = [ 1, 2, 3 ];

function get_post( $id ) {
  return isset( $GLOBALS['gmcp_t_posts'][ (int) $id ] ) ? (object) ( $GLOBALS['gmcp_t_posts'][ (int) $id ] + [ 'ID' => (int) $id, 'post_parent' => 0 ] ) : null;
}
function taxonomy_exists( $t ) {
  return $t === 'category';
}
function term_exists( $id, $t = '' ) {
  return in_array( (int) $id, $GLOBALS['gmcp_t_term_ids'], true ) ? [ 'term_id' => (int) $id ] : null;
}
function is_wp_error( $v ) {
  return false;
}
function wp_set_object_terms( $post, $terms, $tax, $append = false ) {
  $GLOBALS['gmcp_t_terms_of'][ (int) $post ] = array_values( array_map( 'intval', $terms ) );
  return $terms;
}
class GMCP_T_Wpdb {
  public $term_relationships = 'tr';
  public $term_taxonomy = 'tt';
  public $prefix = 'wp_';
  public function prepare( $q, ...$args ) {
    return $args;
  }
  public function get_col( $args ) {
    return $GLOBALS['gmcp_t_terms_of'][ (int) $args[0] ] ?? [];
  }
}
$GLOBALS['wpdb'] = new GMCP_T_Wpdb();

function get_option( $key, $default = false ) {
  return array_key_exists( $key, $GLOBALS['gmcp_t_options'] ) ? $GLOBALS['gmcp_t_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
  $GLOBALS['gmcp_t_options'][ $key ] = $value;
  return true;
}
function delete_option( $key ) {
  unset( $GLOBALS['gmcp_t_options'][ $key ] );
  return true;
}
function maybe_serialize( $v ) {
  return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v;
}
function apply_filters( $tag, $value, ...$args ) {
  return $tag === 'gmcp_can_call_tool' ? $GLOBALS['gmcp_t_can_call'] : $value;
}

class GMCP_Core {
  const REDACTION_MARKER = '[redacted]';
  public static function caller_drafts_only() {
    return $GLOBALS['gmcp_t_drafts_only'];
  }
  // The real one's answer for a live post, in one line: refuse if drafts-only and live.
  public static function drafts_only_guard( $id, $verb = 'Changing', $new_status = '', $removing = false ) {
    $post = get_post( $id );
    if ( !$GLOBALS['gmcp_t_drafts_only'] || !$post ) {
      return true;
    }
    return in_array( $post->post_status, [ 'publish', 'future', 'private' ], true ) ? "$verb post #$id is refused: it is live." : true;
  }
  public static function is_commerce_post_type( $type ) {
    return $type === 'shop_order';
  }
  public static function commerce_post_guard( $id, $verb = 'Changing', $type = null ) {
    $post = get_post( $id );
    return $post && self::is_commerce_post_type( $post->post_type ) ? "$verb post #$id is refused: it is an order." : true;
  }
  public static function option_delete_policy( $key ) {
    return true;
  }
  public static function option_guard( $key ) {
    return $key === 'protected_opt' ? 'protected' : true;
  }
  public static function unwritable_options() {
    return [];
  }
  public static function option_write_policy( $key, $value ) {
    if ( $key === 'default_role' && $value === 'administrator' ) {
      return 'default_role may not be an administrator.';
    }
    return true;
  }
  public static function field_looks_secret( $k ) {
    return (bool) preg_match( '/secret|password|api_key/i', (string) $k );
  }
  public static function holds_credential( $v, $depth = 0, $max = 6 ) {
    if ( is_array( $v ) ) {
      foreach ( $v as $k => $inner ) {
        if ( self::field_looks_secret( $k ) || self::holds_credential( $inner, $depth + 1, $max ) ) {
          return true;
        }
      }
    }
    return false;
  }
  public static function redact_reversible( $v ) {
    return [ false, null ];
  }
}
class GMCP_Audit {
  const CRON_HOOK = 'gmcp_t_cron';
}

require __DIR__ . '/../includes/changes.php';
require __DIR__ . '/../includes/journal.php';

$fails = 0;
$total = 0;
function check( string $label, $got, $want ): void {
  global $fails, $total;
  $total++;
  if ( $got === $want ) {
    echo "ok   $label\n";
    return;
  }
  $fails++;
  echo "FAIL $label\n     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n";
}

function journal(): array {
  return (array) get_option( GMCP_Journal::OPTION, [] );
}
function entry_by( string $id ): ?array {
  foreach ( journal() as $e ) {
    if ( $e['id'] === $id ) {
      return $e;
    }
  }
  return null;
}
$observer = ( new ReflectionClass( 'GMCP_Journal' ) )->newInstanceWithoutConstructor();

echo "-- themod_diff and themod_apply (pure) --\n";
$old = [ 'a' => 1, 'b' => 'x', 'c' => [ 1, 2 ] ];
check( 'identical rows differ in nothing', GMCP_Journal::themod_diff( $old, $old ), [] );
check( 'one changed key is one element',
  array_column( (array) GMCP_Journal::themod_diff( $old, [ 'a' => 2 ] + $old ), 'key' ), [ 'a' ] );
$d = GMCP_Journal::themod_diff( $old, [ 'a' => 1, 'b' => 'x', 'c' => [ 1, 2 ], 'n' => 'new' ] );
check( 'a key that is new is marked absent before', [ $d[0]['key'], $d[0]['absent'], $d[0]['removed'] ], [ 'n', true, false ] );
$d = GMCP_Journal::themod_diff( $old, [ 'a' => 1, 'b' => 'x' ] );
check( 'a key that went is marked removed and keeps its old value', [ $d[0]['key'], $d[0]['removed'], $d[0]['from'] ], [ 'c', true, [ 1, 2 ] ] );
check( 'a non-array row is not diffed', GMCP_Journal::themod_diff( false, $old ), null );
$wide_new = [];
for ( $i = 0; $i < GMCP_Journal::THEMOD_MAX_KEYS + 1; $i++ ) {
  $wide_new[ "k$i" ] = $i;
}
check( 'more keys than the cap falls back to the whole row', GMCP_Journal::themod_diff( [], $wide_new ), null );
// Every diff element, applied to the new row, must give back the old value of that key and
// leave the others alone: this is the property undo relies on.
$new = [ 'a' => 2, 'c' => [ 9 ], 'n' => 'new' ];
$all_ok = true;
foreach ( (array) GMCP_Journal::themod_diff( $old, $new ) as $one ) {
  $back = GMCP_Journal::themod_apply( $new, $one['key'], $one['absent'], $one['from'] );
  $all_ok = $all_ok && ( array_key_exists( $one['key'], $old ) ? $back[ $one['key'] ] === $old[ $one['key'] ] : !array_key_exists( $one['key'], $back ) );
  foreach ( $new as $k => $v ) {
    if ( $k !== $one['key'] ) {
      $all_ok = $all_ok && $back[ $k ] === $v;
    }
  }
}
check( 'applying each element restores that key and no other', $all_ok, true );

echo "-- term sets and fingerprints (pure) --\n";
check( 'order is not a difference', GMCP_Changes::term_sets_differ( [ 3, 1, 2 ], [ 1, 2, 3 ] ), false );
check( 'a missing term is a difference', GMCP_Changes::term_sets_differ( [ 1, 2 ], [ 1 ] ), true );
check( 'CONTROL: an empty set differs from a populated one', GMCP_Changes::term_sets_differ( [], [ 4 ] ), true );
check( 'absent differs from null', GMCP_Journal::state_hash( false, null ) !== GMCP_Journal::state_hash( true, null ), true );
check( 'the same value hashes the same', GMCP_Journal::state_hash( true, [ 'x' => 1 ] ), GMCP_Journal::state_hash( true, [ 'x' => 1 ] ) );
check( 'a different value hashes differently', GMCP_Journal::state_hash( true, 'a' ) !== GMCP_Journal::state_hash( true, 'b' ), true );
$log = [ [ 'id' => 'a1' ], [ 'id' => 'b2' ] ];
$marked = GMCP_Journal::mark_in_log( $log, 'a1', 100, 'z9' );
check( 'mark_in_log marks the entry and links its undo', [ $marked[0]['undone'], $marked[0]['undone_by'] ], [ 100, 'z9' ] );
check( 'and leaves its neighbour alone', isset( $marked[1]['undone'] ), false );
check( 'CONTROL: a missing id marks nothing', GMCP_Journal::mark_in_log( $log, 'nope', 1, 'q' ), $log );

echo "-- a theme setting, through the real observe() and revert() --\n";
$row = 'theme_mods_twenty';
update_option( $row, [ 'color' => 'red', 'logo' => 5, 'nav_menu_locations' => [ 'primary' => 3 ] ] );
$before = get_option( $row );
update_option( $row, [ 'color' => 'blue', 'logo' => 5, 'nav_menu_locations' => [ 'primary' => 7 ] ] );
$observer->observe( [
  'kind' => 'option', 'op' => 'updated', 'id' => $row,
  'fields' => [ 'value' => [ 'from' => $before, 'to' => get_option( $row ) ] ],
  'tool' => 'wp_set_theme_mod', 'call' => 'c1',
] );
check( 'two changed settings are two entries, not one row entry', array_column( journal(), 'kind' ), [ 'themod', 'themod' ] );
check( 'the unchanged setting has no entry (control: the changed ones do)',
  [ in_array( 'logo', array_column( journal(), 'key' ), true ), in_array( 'color', array_column( journal(), 'key' ), true ) ], [ false, true ] );
$color = array_values( array_filter( journal(), function ( $e ) { return $e['key'] === 'color'; } ) )[0];

// A later, unrelated change to another setting must survive the undo of this one.
update_option( $row, array_merge( get_option( $row ), [ 'logo' => 99 ] ) );
$r = GMCP_Journal::revert( $color['id'] );
check( 'undoing one setting succeeds', $r['ok'], true );
check( 'that setting is back', get_option( $row )['color'], 'red' );
check( 'the other setting changed since is left alone (the whole-row restore would have lost it)', get_option( $row )['logo'], 99 );
check( 'and the sibling setting from the same call is untouched', get_option( $row )['nav_menu_locations']['primary'], 7 );
$undo_id = entry_by( $color['id'] )['undone_by'] ?? '';
check( 'the original links to its undo', $undo_id !== '', true );
$undo = entry_by( $undo_id );
check( 'the undo is on record, naming what it reversed', $undo['undoes'] ?? null, $color['id'] );
check( 'the original now refuses a second revert', GMCP_Journal::revert( $color['id'] )['ok'], false );
$rows = GMCP_Journal::recent( 10 );
check( 'wp_list_changes shows the undo as reversible with an undoes field',
  array_values( array_filter( $rows, function ( $x ) use ( $undo_id ) { return $x['id'] === $undo_id; } ) )[0]['undoes'] ?? '', $color['id'] );

$r = GMCP_Journal::revert( $undo_id );
check( 'undoing the undo succeeds', $r['ok'], true );
check( 'the edit is back', get_option( $row )['color'], 'blue' );
check( 'and the logo is still the later value', get_option( $row )['logo'], 99 );

echo "-- staleness: an undo is not undone over newer work --\n";
$undo2_id = entry_by( $undo_id )['undone_by'];
check( 'control: the re-applied entry is reversible while nothing has moved', GMCP_Journal::recent( 20 )[0]['reversible'] ?? null, true );
update_option( $row, array_merge( get_option( $row ), [ 'color' => 'green' ] ) );
$r = GMCP_Journal::revert( $undo2_id );
check( 'reverting it after the setting moved is refused', $r['ok'], false );
check( 'and the refusal says it changed since', strpos( $r['message'], 'changed since' ) !== false, true );
check( 'and the newer value is intact', get_option( $row )['color'], 'green' );

echo "-- gates and policy still apply to an undo --\n";
update_option( 'default_role', 'subscriber' );
$observer->observe( [ 'kind' => 'option', 'op' => 'updated', 'id' => 'default_role',
  'fields' => [ 'value' => [ 'from' => 'administrator', 'to' => 'subscriber' ] ], 'tool' => 'wp_update_settings', 'call' => 'c2' ] );
$role = end( $GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] );
// Recording is switched on the way a tool call switches it, so a refusal that forgot to
// resume would leave it off and the check after the revert could see that.
( new ReflectionClass( 'GMCP_Changes' ) )->newInstanceWithoutConstructor()->start( 'wp_undo_change' );
$r = GMCP_Journal::revert( $role['id'] );
check( 'option_write_policy refuses restoring a privileged default role', $r['ok'], false );
check( 'and the stored value is unchanged', get_option( 'default_role' ), 'subscriber' );
check( 'and the refused undo did not mark the entry reverted', isset( entry_by( $role['id'] )['undone'] ), false );
check( 'recording is still on after the refusal', GMCP_Changes::pause(), true );
GMCP_Changes::resume( false );
( new ReflectionClass( 'GMCP_Changes' ) )->newInstanceWithoutConstructor()->stop();
check( 'CONTROL: once stopped, pause() reports recording was off', GMCP_Changes::pause(), false );

$GLOBALS['gmcp_t_can_call'] = false;
$r = GMCP_Journal::revert( $undo2_id );
check( 'without gmcp_can_call_tool the undo refuses', $r['ok'], false );
check( 'and names the tool it needs', strpos( $r['message'], 'cannot call' ) !== false, true );
$GLOBALS['gmcp_t_can_call'] = true;

echo "-- credentials never reach the journal, including through an undo --\n";
update_option( $row, [ 'api_key' => 'LIVE-1', 'tone' => 'a' ] );
$before = get_option( $row );
update_option( $row, [ 'api_key' => 'LIVE-2', 'tone' => 'b' ] );
$observer->observe( [ 'kind' => 'option', 'op' => 'updated', 'id' => $row,
  'fields' => [ 'value' => [ 'from' => $before, 'to' => get_option( $row ) ] ], 'tool' => 'wp_set_theme_mod', 'call' => 'c3' ] );
$secret_entry = array_values( array_filter( journal(), function ( $e ) { return ( $e['call'] ?? '' ) === 'c3' && $e['key'] === 'api_key'; } ) )[0];
check( 'a credential-shaped setting is recorded as changed and redacted', !empty( $secret_entry['redacted'] ), true );
check( 'CONTROL: its sibling on the same row keeps its previous value',
  array_values( array_filter( journal(), function ( $e ) { return ( $e['call'] ?? '' ) === 'c3' && $e['key'] === 'tone'; } ) )[0]['previous'], 'a' );
check( 'the secret value is nowhere in the stored journal', strpos( serialize( journal() ), 'LIVE-' ), false );
check( 'CONTROL: the probe finds a value that is there', strpos( serialize( journal() ), 'tone' ) !== false, true );
check( 'the redacted entry refuses to revert', GMCP_Journal::revert( $secret_entry['id'] )['ok'], false );

// An undo that overwrites a credential must not copy it into the log either.
$tone = array_values( array_filter( journal(), function ( $e ) { return ( $e['call'] ?? '' ) === 'c3' && $e['key'] === 'tone'; } ) )[0];
update_option( 'opt_x', [ 'mode' => 'a' ] );
$observer->observe( [ 'kind' => 'option', 'op' => 'updated', 'id' => 'opt_x',
  'fields' => [ 'value' => [ 'from' => [ 'mode' => 'a' ], 'to' => [ 'mode' => 'b' ] ] ], 'tool' => 'wp_update_option', 'call' => 'c4' ] );
update_option( 'opt_x', [ 'mode' => 'b', 'secret_key' => 'LIVE-3' ] );
$ox = end( $GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] );
$r = GMCP_Journal::revert( $ox['id'] );
check( 'undoing an option whose current value holds a credential still works', $r['ok'], true );
check( 'the credential is not in the log afterwards', strpos( serialize( journal() ), 'LIVE-3' ), false );
$redo = entry_by( entry_by( $ox['id'] )['undone_by'] ?? '' );
check( 'and the undo of it is recorded as not reversible rather than as a copy', !empty( $redo['redacted'] ), true );

echo "-- an option, undone and undone again --\n";
update_option( 'blogdescription', 'before' );
$observer->observe( [ 'kind' => 'option', 'op' => 'updated', 'id' => 'blogdescription',
  'fields' => [ 'value' => [ 'from' => 'before', 'to' => 'after' ] ], 'tool' => 'wp_update_option', 'call' => 'c5' ] );
update_option( 'blogdescription', 'after' );
$bd = end( $GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] );
check( 'first undo', GMCP_Journal::revert( $bd['id'] )['ok'], true );
check( 'restores', get_option( 'blogdescription' ), 'before' );
$bd2 = entry_by( $bd['id'] )['undone_by'];
check( 'second undo (of the undo)', GMCP_Journal::revert( $bd2 )['ok'], true );
check( 'restores the edit', get_option( 'blogdescription' ), 'after' );
$bd3 = entry_by( $bd2 )['undone_by'];
check( 'and that can be undone again', GMCP_Journal::revert( $bd3 )['ok'], true );
check( 'back to the original', get_option( 'blogdescription' ), 'before' );
check( 'the chain shows one label, not a nest of prefixes', substr_count( entry_by( $bd3 )['what'], 'Undo of: ' ), 1 );

echo "-- an absent option --\n";
$observer->observe( [ 'kind' => 'option', 'op' => 'created', 'id' => 'fresh_opt', 'fields' => [], 'tool' => 'wp_update_option', 'call' => 'c6' ] );
update_option( 'fresh_opt', 'v' );
$fo = end( $GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] );
check( 'undoing a created option removes it', [ GMCP_Journal::revert( $fo['id'] )['ok'], array_key_exists( 'fresh_opt', $GLOBALS['gmcp_t_options'] ) ], [ true, false ] );
check( 'undoing that puts it back with its value', [ GMCP_Journal::revert( entry_by( $fo['id'] )['undone_by'] )['ok'], get_option( 'fresh_opt' ) ], [ true, 'v' ] );

echo "-- a term assignment on a live post, and the guards an undo is still held to --\n";
$term_call = function ( int $post, string $call ) use ( $observer ) {
  $GLOBALS['gmcp_t_terms_of'][ $post ] = [ 1, 2 ];
  $observer->observe( [ 'kind' => 'termrel', 'op' => 'updated', 'id' => $post, 'taxonomy' => 'category', 'subject' => 'post', 'label' => 'P',
    'fields' => [ 'category' => [ 'from' => [ 1, 2 ], 'to' => [ 3 ] ] ], 'tool' => 'wp_add_post_terms', 'call' => $call ] );
  $GLOBALS['gmcp_t_terms_of'][ $post ] = [ 3 ];
  $all = journal();
  return end( $all );
};
$live = $term_call( 77, 'c7' );
$draft = $term_call( 78, 'c8' );
$order = $term_call( 79, 'c9' );
check( 'CONTROL: the termrel entry for the live post was recorded', [ $live['kind'] ?? null, $live['ID'] ?? null ], [ 'termrel', 77 ] );

$GLOBALS['gmcp_t_drafts_only'] = true;
$terms_before = $GLOBALS['gmcp_t_terms_of'][ 77 ];
$r = GMCP_Journal::revert( $live['id'] );
check( 'a drafts-only caller is refused undoing a term change on a LIVE post', $r['ok'], false );
check( 'and the refusal is the drafts-only one', strpos( $r['message'], 'is refused: it is live' ) !== false, true );
check( 'and the live post kept the terms it has now', $GLOBALS['gmcp_t_terms_of'][ 77 ], $terms_before );
check( 'and the refused entry is not marked reverted', isset( entry_by( $live['id'] )['undone'] ), false );
$listed = array_values( array_filter( GMCP_Journal::recent( 50 ), function ( $e ) use ( $live ) { return $e['id'] === $live['id']; } ) );
check( 'and wp_list_changes does not offer it as reversible to that caller', $listed[0]['reversible'] ?? null, false );
check( 'CONTROL: a drafts-only caller may undo a term change on a DRAFT post', GMCP_Journal::revert( $draft['id'] )['ok'], true );
check( 'and the draft has its earlier terms back', $GLOBALS['gmcp_t_terms_of'][ 78 ], [ 1, 2 ] );

$GLOBALS['gmcp_t_drafts_only'] = false;
$r = GMCP_Journal::revert( $live['id'] );
check( 'CONTROL: the same entry is undone by a caller that is not drafts-only', $r['ok'], true );
check( 'and the live post has its earlier terms back', $GLOBALS['gmcp_t_terms_of'][ 77 ], [ 1, 2 ] );

// Undo of an undo is a write too: the redo entry of the draft undo, once its post goes live.
$redo_id = entry_by( $draft['id'] )['undone_by'] ?? '';
check( 'CONTROL: the draft undo left an undo entry to undo', $redo_id !== '', true );
$GLOBALS['gmcp_t_posts'][78]['post_status'] = 'publish';
$GLOBALS['gmcp_t_drafts_only'] = true;
$r = GMCP_Journal::revert( $redo_id );
check( 'a drafts-only caller is refused undoing an undo once the post has gone live', $r['ok'], false );
check( 'and the terms are as the first undo left them', $GLOBALS['gmcp_t_terms_of'][ 78 ], [ 1, 2 ] );
$GLOBALS['gmcp_t_drafts_only'] = false;

// The commerce policy applies to a term assignment on an order as to any post write.
$r = GMCP_Journal::revert( $order['id'] );
check( 'an undo of a term change on an order is refused by the commerce guard', [ $r['ok'], strpos( $r['message'], 'it is an order' ) !== false ], [ false, true ] );
check( 'and the order kept the terms it has now', $GLOBALS['gmcp_t_terms_of'][ 79 ], [ 3 ] );

// A term edit is not a post write: the post guard is not asked about a term id.
$GLOBALS['gmcp_t_drafts_only'] = true;
$GLOBALS['gmcp_t_posts'][5] = [ 'post_status' => 'publish', 'post_type' => 'post' ];
check( 'a term entry whose id equals a live post id is not judged as that post',
  ( new ReflectionMethod( 'GMCP_Journal', 'drafts_only_refusal' ) )->invoke( null, [ 'kind' => 'term', 'ID' => 5, 'taxonomy' => 'category' ] ), true );
check( 'CONTROL: a termrel entry on that same id is judged as the live post',
  is_string( ( new ReflectionMethod( 'GMCP_Journal', 'drafts_only_refusal' ) )->invoke( null, [ 'kind' => 'termrel', 'ID' => 5, 'taxonomy' => 'category' ] ) ), true );
$GLOBALS['gmcp_t_drafts_only'] = false;

echo "\n$total checks, $fails failed\n";
exit( min( 255, $fails ) );
