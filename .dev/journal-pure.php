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
function wp_slash( $v ) {
  return $v;
}
function get_post_field( $field, $id, $context = 'display' ) {
  return $GLOBALS['gmcp_t_posts'][ (int) $id ][ $field ] ?? '';
}
// Records what it was asked to write and applies it, so a test can see every field that went in.
$GLOBALS['gmcp_t_post_writes'] = [];
function wp_update_post( $arr, $wp_error = false ) {
  $GLOBALS['gmcp_t_post_writes'][] = $arr;
  foreach ( $arr as $k => $v ) {
    if ( $k !== 'ID' ) {
      $GLOBALS['gmcp_t_posts'][ (int) $arr['ID'] ][ $k ] = $v;
    }
  }
  return (int) $arr['ID'];
}
function wp_json_encode( $v, $flags = 0 ) {
  return json_encode( $v, $flags );
}
function size_format( $b ) {
  return $b . ' B';
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
$GLOBALS['gmcp_t_salt'] = 'test-salt-one';
function wp_salt( $scheme = 'auth' ) {
  return $GLOBALS['gmcp_t_salt'] . '-' . $scheme;
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
  // The real one's answer for the two cases this file drives: a post of the submission type,
  // and (with a type named) the type itself. A post under one is not walked here.
  public static function personal_data_post_guard( $id, $type = '' ) {
    if ( $type === '' ) {
      $post = get_post( $id );
      $type = $post ? $post->post_type : '';
    }
    return $type === 'flamingo_inbound' ? 'A flamingo_inbound is a form submission.' : true;
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
  // The real one's answer for the two statuses, in one line.
  public static function status_write_refusal( $status, $noun = 'post' ) {
    return in_array( strtolower( trim( (string) $status ) ), [ 'trash', 'auto-draft' ], true ) ? 'refused: a deletion by another name' : null;
  }
  public static function redact_reversible( $v ) {
    return [ false, null ];
  }
  // The real one is the scanner plus the debug log's assignment rule; here, the scanner plus a
  // pattern for "a secret-looking name, a separator, something".
  public static function looks_credentialed( $text ) {
    return GMCP_Credential_Scan::text_holds( (string) $text )
      || (bool) preg_match( '/(?:pass|pwd|secret|token|key)[A-Za-z0-9_.\-\[\]]*["\']?\s*(?:=>|=|:)\s*\S/i', (string) $text );
  }
}
class GMCP_Audit {
  const CRON_HOOK = 'gmcp_t_cron';
}

require __DIR__ . '/../includes/credential-scan.php';
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
check( 'the fingerprint is keyed: it is not the md5 of the serialized value any more', [ strpos( GMCP_Journal::state_hash( true, 'guess' ), 'h:' ), GMCP_Journal::state_hash( true, 'guess' ) === 'v:' . md5( serialize( 'guess' ) ) ], [ 0, false ] );
check( 'CONTROL: the legacy form is still the unsalted md5, for entries already on record', GMCP_Journal::state_hash( true, 'guess', true ), 'v:' . md5( serialize( 'guess' ) ) );
$keyed_one = GMCP_Journal::state_hash( true, 'guess' );
$GLOBALS['gmcp_t_salt'] = 'test-salt-two';
check( 'the same value fingerprints differently under another site salt', GMCP_Journal::state_hash( true, 'guess' ) !== $keyed_one, true );
$GLOBALS['gmcp_t_salt'] = 'test-salt-one';
check( 'CONTROL: and identically under the same one', GMCP_Journal::state_hash( true, 'guess' ), $keyed_one );
check( 'absent is still a plain word in both forms', [ GMCP_Journal::state_hash( false, null ), GMCP_Journal::state_hash( false, null, true ) ], [ 'absent', 'absent' ] );
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

echo "-- an undo's recorded state: keyed now, and an entry with the old md5 still compares --\n";
update_option( 'fp_opt', 'one' );
$observer->observe( [ 'kind' => 'option', 'op' => 'updated', 'id' => 'fp_opt',
  'fields' => [ 'value' => [ 'from' => 'one', 'to' => 'two' ] ], 'tool' => 'wp_update_option', 'call' => 'fp1' ] );
update_option( 'fp_opt', 'two' );
$fp = end( $GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] );
check( 'CONTROL: the first undo goes through', GMCP_Journal::revert( $fp['id'] )['ok'], true );
$fp_undo = entry_by( $fp['id'] )['undone_by'];
$reversible_now = function ( string $id ): array {
  foreach ( GMCP_Journal::recent( 60 ) as $row ) {
    if ( $row['id'] === $id ) {
      return [ $row['reversible'], $row['not_reversible_because'] ?? '' ];
    }
  }
  return [ null, 'not listed' ];
};
check( 'the undo records its state keyed', strpos( (string) entry_by( $fp_undo )['after_state'], 'h:' ), 0 );
check( 'CONTROL: and it can itself be undone while the value is where the undo left it', $reversible_now( $fp_undo )[0], true );
$set_after_state = function ( string $id, string $state ) {
  $log = journal();
  foreach ( $log as $i => $e ) {
    if ( $e['id'] === $id ) {
      $log[ $i ]['after_state'] = $state;
    }
  }
  update_option( GMCP_Journal::OPTION, $log, false );
};
$set_after_state( $fp_undo, GMCP_Journal::state_hash( true, 'one', true ) );
check( 'an entry that carries the old md5 still compares equal, so upgrading does not strand it', $reversible_now( $fp_undo )[0], true );
update_option( 'fp_opt', 'changed by someone else' );
check( 'and the old form still refuses once the value has moved', [ $reversible_now( $fp_undo )[0], strpos( $reversible_now( $fp_undo )[1], 'changed since' ) !== false ], [ false, true ] );
$set_after_state( $fp_undo, GMCP_Journal::state_hash( true, 'one' ) );
check( 'the keyed form refuses once the value has moved too', [ $reversible_now( $fp_undo )[0], strpos( $reversible_now( $fp_undo )[1], 'changed since' ) !== false ], [ false, true ] );
$GLOBALS['gmcp_t_salt'] = 'test-salt-two';
update_option( 'fp_opt', 'one' );
check( 'a keyed state does not match under a different salt (the stored string is useless off this site)', $reversible_now( $fp_undo )[0], false );
$GLOBALS['gmcp_t_salt'] = 'test-salt-one';
check( 'CONTROL: it matches again under the right one, with the value back where the undo left it', $reversible_now( $fp_undo )[0], true );

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

echo "-- a post undo restores only the fields the recorder watches --\n";
// post_type is not one of them. An entry that names it was not made by the recorder, and
// restoring it would put 'shop_order' on a page: the commerce guard is asked about the post as
// it is now, never about the value being written.
$GLOBALS['gmcp_t_posts'][78]['post_title'] = 'Changed title';
$GLOBALS['gmcp_t_post_writes'] = [];
$observer->observe( [ 'kind' => 'post', 'op' => 'updated', 'id' => 78, 'subject' => 'post', 'label' => 'P', 'tool' => 'wp_update_post', 'call' => 'c9a',
  'fields' => [ 'post_title' => [ 'from' => 'Original title', 'to' => 'Changed title' ], 'post_type' => [ 'from' => 'shop_order', 'to' => 'post' ] ] ] );
$all = journal();
$pe = end( $all );
check( 'CONTROL: the entry carries both fields, so the probe has a post_type to find', [ $pe['fields'], $pe['previous']['post_type'] ?? null ], [ [ 'post_title', 'post_type' ], 'shop_order' ] );
$res = GMCP_Journal::revert( $pe['id'] );
check( 'the undo of the entry succeeds', $res['ok'], true );
check( 'CONTROL: the watched field was restored', $GLOBALS['gmcp_t_posts'][78]['post_title'], 'Original title' );
check( 'post_type was never written', array_key_exists( 'post_type', $GLOBALS['gmcp_t_post_writes'][0] ?? [] ), false );
check( 'the post is still the type it was', $GLOBALS['gmcp_t_posts'][78]['post_type'], 'post' );

echo "-- the text scanner: what it finds and, as much, what it leaves alone --\n";
// Each row is a text and whether it is credential-shaped. The false rows are the controls: a
// scanner that said yes to everything passes every leak check and ruins undo.
$scan_cases = [
  'a JSON snippet in the middle of a body'    => [ '<p>Config:</p>{"api_key":"sk_live_abcdefgh12345"}', true ],
  'a define() of a password constant'         => [ "<pre>define('DB_PASSWORD','hunter2x');</pre>", true ],
  'a define() of a salt in double quotes'     => [ 'define( "AUTH_KEY", "k3v!x9Zq77abcdEF-salt" );', true ],
  'an Authorization header'                   => [ 'Send header Authorization: Bearer abcdefghijklmnop1234', true ],
  'a bare bearer token'                       => [ 'use Bearer eyabcdefghijklmnopqrstuv', true ],
  'an env-style assignment'                   => [ "DB_PASSWORD=s3cretpass99\nDB_HOST=localhost", true ],
  'a "password: value" line'                  => [ 'login password: hunter2 and the rest', true ],
  'a token behind a URL with a colon in it'   => [ 'redirect:https://x.test/cb?token=abc123def456', true ],
  'a token in a query string'                 => [ '<a href="https://x.test/?token=abc123def456">x</a>', true ],
  'a private key block'                       => [ "-----BEGIN RSA PRIVATE KEY-----\nMIIE", true ],
  'user information in a DSN'                 => [ 'mysql://root:pw123@db.local/x', true ],
  'a JSON snippet with entity-encoded quotes' => [ '&quot;client_secret&quot;:&quot;zzzz1234&quot;', true ],
  'a JSON snippet with escaped quotes'        => [ '{\"access_token\":\"abcd1234\"}', true ],
  'a PHP array entry'                         => [ "'smtp_password' => 'abcdef'", true ],
  'an AWS key id'                             => [ 'AKIAABCDEFGHIJKLMNOP', true ],
  'a GitHub token'                            => [ 'ghp_abcdefghijklmnopqrstuvwx', true ],
  'CONTROL: prose that says password, secret key, API key, token' => [ 'Pick a strong password and never share your password. Password: required. Keep your secret key safe; the API key and token are shown once.', false ],
  'CONTROL: "password = required" and "token=none" in prose' => [ 'The password = required field and token=none here', false ],
  'CONTROL: a Gutenberg block with authorId and authorName' => [ '<!-- wp:post-author {"authorId":3,"authorName":"Alexander","showAvatar":true} /-->', false ],
  'CONTROL: block attributes called passwordLabel and keywords' => [ '<!-- wp:foo {"keywords":"seo wordpress plugin 2024 tips","passwordLabel":"Password","authToggle":"Enter your password"} -->', false ],
  'CONTROL: a login form'                     => [ '<label>Password</label><input type="password" name="pwd" placeholder="Password"><input name="token" value="">', false ],
  'CONTROL: an icon registry entry'           => [ '{"key":"eicon-star","author":"Jane Doe","title":"Hello"}', false ],
  'CONTROL: a short quoted password assignment is too thin to call' => [ "'smtp_password' => 'abc'", false ],
  'CONTROL: ordinary text'                    => [ str_repeat( 'Lorem ipsum dolor sit amet, consectetur. ', 20 ), false ],
  'CONTROL: a commit hash'                    => [ 'commit 0123456789abcdef0123456789abcdef01234567', false ],
  'CONTROL: an e-mail address and a URL with a port' => [ 'contact me at jane@example.com or http://example.com:8080/x', false ],
];
foreach ( $scan_cases as $label => [ $text, $want ] ) {
  check( "scanner: $label", GMCP_Credential_Scan::text_holds( $text ), $want );
}
// What a sample or a template writes where a secret goes is not a credential, and losing undo over it
// costs an ordinary edit. Each placeholder row has a counterpart that LOOKS real under the same name
// and is still flagged, so the list cannot have grown into "anything under a secret-named key".
$placeholder_cases = [
  [ "WordPress's own sample DB_PASSWORD line", "define( 'DB_PASSWORD', 'password_here' );", "define( 'DB_PASSWORD', 'h8Kq2xLm9pQz' );" ],
  [ 'the sample salt phrase', 'define( "AUTH_KEY", "put your unique phrase here" );', 'define( "AUTH_KEY", "k3v!x9Zq77abcdEF-salt" );' ],
  [ 'a const with YOUR_TOKEN_HERE', 'const token = "YOUR_TOKEN_HERE";', 'const token = "tk_9f8e7d6c5b4a3210";' ],
  [ 'a row of asterisks', 'password: "********"', 'password: "Tr0ub4dor&3"' ],
  [ 'a schema type name', '"password": "string"', '"password": "s3cr3tValue!"' ],
  [ 'an export with a hint', 'export API_KEY=your-api-key-here', 'export API_KEY=9f8e7d6c5b4a3210ff' ],
  [ 'sample user information in a URL', 'https://user:pass@host.example/x', 'https://deploy:Zq8x7Lm2@host.example/x' ],
  [ 'an angle-bracket placeholder', 'password: "<your-password>"', 'password: "p4ss-w0rd-real"' ],
  [ 'a shell variable', 'DB_PASSWORD=${DB_PASS}', 'DB_PASSWORD=Zq8x7Lm2Pw' ],
  [ 'a run of x', 'api_key = "xxxxxxxx"', 'api_key = "xK9xxQ4x7xx"' ],
  [ '"example"', 'token: "example"', 'token: "example7Zq9"' ],
  [ '"your_" with a digit is a real secret that starts that way', 'token: "your_token_9f8e7d6c"', null ],
];
foreach ( $placeholder_cases as [ $label, $placeholder, $real ] ) {
  if ( $real === null ) {
    check( "scanner: $label is still flagged", GMCP_Credential_Scan::text_holds( $placeholder ), true );
    continue;
  }
  check( "scanner: placeholder, $label, is not a credential", GMCP_Credential_Scan::text_holds( $placeholder ), false );
  check( "CONTROL: the same shape with a real-looking value, $label, is", GMCP_Credential_Scan::text_holds( $real ), true );
}
check( 'scanner: type="password" and passwordLabel stay unflagged, as before',
  [ GMCP_Credential_Scan::text_holds( '<input type="password" name="pwd">' ), GMCP_Credential_Scan::text_holds( '{"passwordLabel":"Your password"}' ) ], [ false, false ] );
check( 'scanner: a Basic credential of base64 with padding is flagged, the word "Basic" in prose is not',
  [ GMCP_Credential_Scan::text_holds( 'Authorization is Basic dXNlcjpwYXNzd29yZA==' ), GMCP_Credential_Scan::text_holds( 'Basic information and Bearer of good news' ) ], [ true, false ] );
check( 'scanner: a short Bearer token with a digit is flagged (the audit log used to blank it by a looser rule)',
  GMCP_Credential_Scan::text_holds( 'send Bearer abc12345 now' ), true );
check( 'scanner: a camelCase name is split, so apiKey is a secret outright and passwordLabel only weakly one',
  [ GMCP_Credential_Scan::name_strength( 'apiKey' ), GMCP_Credential_Scan::name_strength( 'passwordLabel' ), GMCP_Credential_Scan::name_strength( 'title' ) ], [ 2, 1, 0 ] );
check( 'scanner: holds() walks arrays and objects to a string leaf',
  [ GMCP_Credential_Scan::holds( [ 'a' => [ 'b' => (object) [ 'c' => 'Authorization: Bearer abcdefghijklmnop1234' ] ] ] ), GMCP_Credential_Scan::holds( [ 'a' => [ 'b' => 'fine' ] ] ) ], [ true, false ] );
check( 'scanner: a window bounds what is read, and a secret past it is not in the part that can be stored',
  [ GMCP_Credential_Scan::holds( str_repeat( 'a ', 5000 ) . ' define(\'DB_PASSWORD\',\'x9Zq77abc\');', 0, 1000 ), GMCP_Credential_Scan::holds( str_repeat( 'a ', 5000 ) . ' define(\'DB_PASSWORD\',\'x9Zq77abc\');' ) ], [ false, true ] );
$big = substr( str_repeat( '<p>Lorem ipsum dolor sit amet, "consectetur": adipiscing elit, key = value; <a href="https://example.com/a?b=c&amp;d=e">link</a> password reset token.</p>' . "\n", 3600 ), 0, 512000 );
$t0 = microtime( true );
$big_held = GMCP_Credential_Scan::text_holds( $big );
$big_ms = ( microtime( true ) - $t0 ) * 1000;
check( 'scanner: a half-megabyte ordinary body is not flagged', $big_held, false );
check( 'scanner: and is read in well under a second (a bound, not a benchmark; measured 10 to 60 ms)', $big_ms < 1000, true );
check( 'CONTROL: the same body with one wp-config line appended IS flagged', GMCP_Credential_Scan::text_holds( $big . "\ndefine('DB_PASSWORD','x9Zq77abc');" ), true );

echo "-- the text scanner, second review: escapes, long names, array indexes, prose --\n";
// Each flagged row names the secret, and the check is that blanking the spans gives back the text with
// exactly that secret replaced: which proves the OFFSETS are right through every escape, not only that
// something was found. Vendor-shaped keys are built at runtime so no literal in the tree looks like one.
$S = 'Zx9Qw3Lp7Rt2';
$flag_rows = [
  'a PHP array-index assignment'              => [ "\$cfg['db_password'] = '$S';", $S ],
  'an array index in double quotes'           => [ "\$cfg[\"smtp_pass\"] = \"$S\";", $S ],
  'define() with a value that starts with $'  => [ "define('DB_PASSWORD', '\$uperS3cret99')", '$uperS3cret99' ],
  'a Gutenberg attribute with \\u0026 in it'  => [ '<!-- wp:x {"smtpPassword":"p\\u0026ssw0rd99"} /-->', 'p\\u0026ssw0rd99' ],
  'a backslash path inside a quoted value'    => [ '"db_password": "C:\\\\vault\\\\' . $S . '"', 'C:\\\\vault\\\\' . $S ],
  'an escaped quote inside a define() value'  => [ "define('DB_PASSWORD', 'ab\\'cdef12345')", "ab\\'cdef12345" ],
  'an escaped quote inside an assignment'     => [ "\$smtp_password = 'ab\\'cdef12345';", "ab\\'cdef12345" ],
  'an HTML-escaped arrow in a code block'     => [ "'secret' =&gt; '$S'", $S ],
  'a JSON string escaped twice'               => [ '\\\\"api_key\\\\":\\\\"' . $S . '\\\\"', $S ],
  'a name of a hundred characters'            => [ '"' . str_repeat( 'a', 100 ) . '_password": "' . $S . '"', $S ],
  'a name of ten thousand characters'         => [ '"' . str_repeat( 'a', 10000 ) . '_password": "' . $S . '"', $S ],
  'a hyphenated name'                         => [ 'DB-PASSWORD=' . $S, $S ],
  'a bare Basic credential'                   => [ 'try Basic dXNlcjpwYXNzd29yZDEyMw== here', 'dXNlcjpwYXNzd29yZDEyMw==' ],
  'a Basic credential without padding'        => [ 'try Basic dXNlcjpwYXNzMQ here', 'dXNlcjpwYXNzMQ' ],
  'a Bearer token'                            => [ 'send Bearer abc12345 now', 'abc12345' ],
  'a qualified pass name, unquoted'           => [ 'DB_PASS: ' . $S . ' ok', $S ],
  'smtp_pass, unquoted'                       => [ 'smtp_pass=' . $S . ' ok', $S ],
  'a hyphenated passphrase under password'    => [ 'password=correct-horse-battery-staple', 'correct-horse-battery-staple' ],
  'a Maps-style key in a URL (still flagged: an AIza key can be a server secret)' => [ 'https://maps.example/api?key=' . 'AIza' . 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q', 'AIza' . 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q' ],
  'a secret key after a decoy that is prose'  => [ '<script src="https://evil.example/x.js"></script><!-- see Basic HTML/CSS 101 --> sk_' . 'live_abcdefgh12345678', 'sk_' . 'live_abcdefgh12345678' ],
];
foreach ( $flag_rows as $label => [ $text, $secret ] ) {
  check( "scanner: $label is flagged", GMCP_Credential_Scan::text_holds( $text ), true );
  check( "CONTROL: scanner: the probe for $label really contains the secret exactly once", substr_count( $text, $secret ), 1 );
  check( "scanner: $label: blanking the spans replaces exactly the secret and nothing else",
    GMCP_Credential_Scan::redact_spans( $text, '[x]' ), str_replace( $secret, '[x]', $text ) );
}
// Prose and public-by-design values, each beside a control that looks the same under a name that IS secret.
$prose_rows = [
  [ 'Course 1: Basic HTML/CSS for beginners', 'Course 1: Basic dXNlcjpwYXNzd29yZDEyMw== for beginners' ],
  [ 'Basic 2024/2025 membership', 'Basic YWRtaW46YWRtaW4xMjM0 membership' ],
  [ 'Season Pass: 2024-2025', 'DB_PASS: 2024-2025x9' ],
  [ 'Day pass: 10am-6pm daily', 'smtp_pass: 10am6pm9x' ],
  [ 'Token: 1st-come-first-served', 'Token: 9f8e7d6c5b4a3210ff' ],
  [ 'The ring Bearer 4-year-old nephew', 'The ring Bearer 4yearold1 nephew' ],
  [ 'Hall pass=2nd-floor', 'hall_pass=2ndfloor9z' ],
  [ '<div class="g-recaptcha" data-sitekey="6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"></div>', '<div data-secret="6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"></div>' ],
  [ 'publishable pk_' . 'live_abcdefghijklmnop12345 here', 'secret sk_' . 'live_abcdefghijklmnop12345 here' ],
  [ 'a test publishable key: pk_' . 'test_abcdefghijklmnop12345', 'a test secret key: sk_' . 'test_abcdefghijklmnop12345' ],
  [ "define('DB_PASSWORD', '\$DB_PASS')", "define('DB_PASSWORD', '\$Db_pass9')" ],
  [ "define('DB_PASSWORD', '\${DB_PASS}')", "define('DB_PASSWORD', '\${db_pass9}')" ],
  [ 'strlen_pass::count_nonzero_bytes_addr (x)', 'strlen_pass: count_nonzero_bytes_addr9' ],
  [ 'Token: v2.1.0', 'Token: v2.1.0x9f8e7d' ],
];
foreach ( $prose_rows as $i => [ $harmless, $control ] ) {
  check( "scanner: prose row $i is not flagged: " . substr( $harmless, 0, 50 ), GMCP_Credential_Scan::text_holds( $harmless ), false );
  check( "CONTROL: scanner: prose row $i has a counterpart that is flagged: " . substr( $control, 0, 50 ), GMCP_Credential_Scan::text_holds( $control ), true );
}
echo "-- the text scanner, third review: misses and false alarms found by .dev/measure-credential-scan.php --\n";
// Each row below was a measured miss (flagged rows) or a measured false alarm (prose rows) over a
// generated corpus of 3,000+ strings. Vendor-shaped keys are assembled at run time.
$S = 'Zx9Qw3Lp7Rt2';
$H64 = '9e65a8a2fb5e93eeea1932a6deaecded96bc9e6455bd054d6b34e009347757d9';
$B64 = 'kDZS1oFgzbLOQrSxe1dEQxCQ7a0FRU47Pbz' . '+AQz7xw0Hn2ba/Qw3Ep9Lm4Tr7Yv0Ac==';
$flag3 = [
  'curl -u user:password'                      => [ "curl -u deploy:$S https://example.com/api", $S ],
  'curl --user= in quotes'                      => [ "curl --user=\"deploy:$S\" https://example.com/api", $S ],
  'a URL with no user (redis; the pair is blanked, colon included)' => [ "REDIS_URL=redis://:$S@cache.example.com:6379/0", ':' . $S ],
  'an <input> with the name first'              => [ '<input type="hidden" name="api_key" value="' . $S . '">', $S ],
  'an <input> with the value first'             => [ "<input value='$S' type='text' name='smtp_password'>", $S ],
  'update_option with a token name'             => [ "update_option( 'acme_token', '$S' );", $S ],
  'set_transient with a key name'               => [ "set_transient( 'acme_api_key', \"$S\", 3600 );", $S ],
  'localStorage.setItem'                        => [ "localStorage.setItem('auth-token', '$S')", $S ],
  'a bare pass= in a log line'                  => [ 'login failed user=admin pass=' . $S, $S ],
  'a qualified _pass with words, hyphens, digits' => [ 'DB_PASS: gijn-mgmf-7430', 'gijn-mgmf-7430' ],
  'a password of letters mixed the way a generator mixes them' => [ 'smtp: AUTH failed, password: rzAnTNbJYjykXw', 'rzAnTNbJYjykXw' ],
  'a password of letters and a symbol, no digit' => [ 'user_pass=Txmyfk%WbZBu', 'Txmyfk%WbZBu' ],
  'the same with a trailing symbol'             => [ 'smtp: AUTH failed, password: RdCmYkaPEiSX!', 'RdCmYkaPEiSX!' ],
  'a name ending in pw'                         => [ 'MYSQL_ROOT_PW=' . $S, $S ],
  'secret_key_base'                             => [ 'secret_key_base=' . $H64, $H64 ],
  'an Azure AccountKey'                         => [ 'DefaultEndpointsProtocol=https;AccountName=acme;AccountKey=' . $B64 . ';EndpointSuffix=core.windows.net', $B64 ],
  'SSH_KEY and STORAGE_KEY'                     => [ 'SSH_KEY=' . $S . 'abcdef', $S . 'abcdef' ],
  'a sessionid cookie'                          => [ 'Cookie: sessionid=' . $S . 'abcd; path=/; HttpOnly', $S . 'abcd' ],
  'a WordPress login cookie'                    => [ 'Cookie: wordpress_logged_in_' . '0123456789abcdef' . '=' . $S . '%7C1700000000', $S . '%7C1700000000' ],
  'Authorization: ApiKey'                       => [ 'Authorization: ApiKey ' . $S . 'abcd', $S . 'abcd' ],
  'Authorization: OAuth'                        => [ 'Authorization: OAuth ' . $S . 'abcd', $S . 'abcd' ],
  'a webhook signing secret'                    => [ 'the signing secret is ' . 'whs' . 'ec_' . 'Tsb1dkryWUz1jCU3PmCgiu69', 'whs' . 'ec_' . 'Tsb1dkryWUz1jCU3PmCgiu69' ],
  'an npm token'                                => [ 'token ' . 'np' . 'm_' . 'L5My5RV3Sh23tmM94VtUSXzsR4IKC91tGsCZ' . ' leaked', 'np' . 'm_' . 'L5My5RV3Sh23tmM94VtUSXzsR4IKC91tGsCZ' ],
  'a GitLab token'                              => [ 'use ' . 'glp' . 'at-' . 'abcdefghij1234567890', 'glp' . 'at-' . 'abcdefghij1234567890' ],
  'a legacy Mailgun key'                        => [ 'api ' . 'key-' . '41bad7bd41722ede06c80afff11f3f15', 'key-' . '41bad7bd41722ede06c80afff11f3f15' ],
  'a Shopify token'                             => [ 'x ' . 'sh' . 'pat_' . '41bad7bd41722ede06c80afff11f3f15', 'sh' . 'pat_' . '41bad7bd41722ede06c80afff11f3f15' ],
  'a Telegram bot token'                        => [ 'bot ' . '1234567890:' . 'AA' . 'abcdefghij_klmnopqrstuvwxyz012345', '1234567890:' . 'AA' . 'abcdefghij_klmnopqrstuvwxyz012345' ],
  'a Slack webhook URL'                         => [ 'https://hooks.' . 'slack.com/services/T0M7ZJHCT/B5Z5RFSQDY6/' . 'Ua2Cc0AZG8lCuE4oBcJjlg3j', 'Ua2Cc0AZG8lCuE4oBcJjlg3j' ],
  'a Discord webhook URL'                       => [ 'https://discord' . '.com/api/webhooks/141439811760524679/' . str_repeat( 'lVZoCzNl', 7 ) . 'sfh7Wv', str_repeat( 'lVZoCzNl', 7 ) . 'sfh7Wv' ],
];
foreach ( $flag3 as $label => [ $text, $secret ] ) {
  check( "scanner: $label is flagged", GMCP_Credential_Scan::text_holds( $text ), true );
  check( "CONTROL: scanner: the probe for $label really contains the secret exactly once", substr_count( $text, $secret ), 1 );
  check( "scanner: $label: blanking the spans replaces exactly the secret and nothing else",
    GMCP_Credential_Scan::redact_spans( $text, '[x]' ), str_replace( $secret, '[x]', $text ) );
}
$prose3 = [
  [ '<input type="submit" name="reset_password" value="Reset Password">', '<input type="text" name="reset_password" value="' . $S . '">' ],
  [ '<input type="checkbox" name="remember_password" value="1" checked>', '<input type="checkbox" name="remember_password" value="' . $S . '">' ],
  [ '<input type="password" name="password" value="••••••••">', '<input type="password" name="password" value="' . $S . '">' ],
  [ '<input type="text" name="api_key" placeholder="Enter your API key" value="">', '<input type="text" name="api_key" placeholder="Enter your API key" value="' . $S . '">' ],
  [ 'docker run -u 1000:1000 nginx', 'curl -u deploy:' . $S . ' https://x.example/' ],
  [ 'podman run --user www-data:www-data php', 'curl --user www-data:' . $S . ' https://x.example/' ],
  [ 'curl --user admin:$WP_PASSWORD https://x.example/', 'curl --user admin:' . $S . ' https://x.example/' ],
  [ 'curl -u user:pass https://x.example/', 'curl -u deploy:' . $S . ' https://x.example/' ],
  [ 'const apiKey = process.env.API_KEY;', 'const apiKey = ' . $S . 'Zx9;' ],
  [ "\$headers['Authorization'] = 'Bearer ' . \$token;", "\$headers['Authorization'] = 'Bearer $S';" ],
  [ 'password = encryption_algorithm.password', 'password = ' . $S . 'enc' ],
  [ "full_headers['Authorization'] = authorization_header", "full_headers['Authorization'] = " . $S . 'abc' ],
  [ 'Mind the pass=1 and pass=true and ?pass=false', 'login failed pass=' . $S ],
  [ 'Season-Pass: 2024-2025', 'DB_PASS: 2024-2025' ],
  [ "update_option( 'acme_api_key', 'none' );", "update_option( 'acme_api_key', '$S' );" ],
  [ "update_option( 'acme_token', \$token ); set_transient( 'acme_token_cache', \$data, 3600 );", "set_transient( 'acme_token', '$S', 3600 );" ],
  [ '"password": "required"', '"password": "' . $S . '"' ],
  [ "password: hidden\napi_key: optional", "password: $S" ],
  [ 'cvs://:pserver:anoncvs@sources.example.com:/cvs/src', 'redis://:' . $S . '@cache.example.com' ],
  [ 'secret_key_base: <%= ENV["SECRET_KEY_BASE"] %>', 'secret_key_base: ' . $H64 ],
  [ 'Password: Administrator', 'password: rzAnTNbJYjykXw' ],
  [ 'Password: **hidden**', 'Password: Txmyfk%WbZBu' ],
  [ 'Password: *required* and API key: ~~revoked~~', 'password=!YAdnsyFJT^Q#' ],
  [ 'Account key: see the portal', 'AccountKey=' . $B64 ],
  [ 'Session id: 4521 for the booking', 'sessionid=' . $S . $S ],
];
foreach ( $prose3 as $i => [ $harmless, $control ] ) {
  check( "scanner: third-review prose row $i is not flagged: " . substr( $harmless, 0, 50 ), GMCP_Credential_Scan::text_holds( $harmless ), false );
  check( "CONTROL: scanner: third-review prose row $i has a counterpart that is flagged: " . substr( $control, 0, 50 ), GMCP_Credential_Scan::text_holds( $control ), true );
}
// Fourth review (an independent reviewer measured each of these against the scanner after it was
// widened). The first group are credentials the widening let through, the second ordinary text it
// started to flag, and each prose row has a counterpart of the same shape that is a credential.
$L24 = $S . $S;
$KEY20 = '9fK2qLx8Rr0aZ1bC4dE5';
$flag4 = [
  'a default password in define()'              => [ "define( 'DB_PASSWORD', 'password' );", 'password' ],
  'JWT_SECRET set to the word secret'           => [ "JWT_SECRET='secret'", 'secret' ],
  'a JSON password of changeme'                 => [ '{"username":"admin","password":"changeme"}', 'changeme' ],
  'curl -u elastic:changeme'                    => [ 'curl -u elastic:changeme https://localhost:9200', 'changeme' ],
  'a token that is the word token (userinfo)'    => [ 'https://deploy:token@git.example.com/repo.git', 'deploy:token' ],
  'a quoted password that begins like a lookup (this.)' => [ '{"password": "this.is.my.password1"}', 'this.is.my.password1' ],
  'a quoted password that begins like a lookup (Self.)' => [ "define( 'DB_PASSWORD', 'Self.Made#2024' );", 'Self.Made#2024' ],
  'a quoted password that begins like a lookup (Env[)'  => [ 'SMTP_PASSWORD="Env[prod]9xQ"', 'Env[prod]9xQ' ],
  'a quoted password that begins like a lookup (options.)' => [ "'api_key' => 'options.Tr4der99xx'", 'options.Tr4der99xx' ],
  'a quoted password that begins like a call'   => [ "'client_secret' => 'env(9fK2qLx8Rr0aZ1)'", 'env(9fK2qLx8Rr0aZ1)' ],
  'an unquoted password that begins like a lookup' => [ 'DB_PASSWORD=this.Kx92mQ!', 'this.Kx92mQ!' ],
  'a passphrase with spaces in a password field' => [ '<input type="password" name="password" value="correct horse battery 42">', 'correct horse battery 42' ],
  'a ">" inside a password field value'         => [ '<input type="password" name="password" value="Ab3>xyz9!q">', 'Ab3>xyz9!q' ],
  'a password field in a tag over 1000 bytes'   => [ '<input class="' . str_repeat( 'w-full ', 150 ) . '" type="password" name="password" value="Kx92mQ7zp">', 'Kx92mQ7zp' ],
  'a checkbox called remember_token with a token' => [ '<input type="checkbox" name="remember_token" value="Kx92mQ7zpL0aa">', 'Kx92mQ7zpL0aa' ],
  'an image input called api_key with a key'    => [ '<input type="image" name="api_key" value="Kx92mQ7zpL0">', 'Kx92mQ7zpL0' ],
  'curl -u with the pair attached'              => [ 'curl -uadmin:Kx92mQ7zp https://api.example.com', 'Kx92mQ7zp' ],
  'a key in the user slot with a one-letter password' => [ "curl -u $KEY20:X https://acme.freshdesk.com/api/v2/tickets", $KEY20 ],
  'a key in the user slot with no password'     => [ "curl -u $KEY20: https://api.example.com/v1/charges", $KEY20 ],
  'curl -u with a letters-only password'        => [ 'curl -u admin:Hunterxy https://api.example.com', 'Hunterxy' ],
  'a password with a colon after an empty user' => [ 'redis://:Kx92:mQ7zp@cache:6379', ':Kx92:mQ7zp' ],
  'an Azure storage key (a vendor settles the name)' => [ 'AZURE_STORAGE_KEY=' . $B64, $B64 ],
  'a quoted shared key that is the shape of a key' => [ '"shared_key": "' . $B64 . '"', $B64 ],
  'a bare pass= with a mixed-in digit'          => [ 'https://x.example/?pass=Kx92mQ7zp', 'Kx92mQ7zp' ],
];
foreach ( $flag4 as $label => [ $text, $secret ] ) {
  check( "scanner: $label is flagged", GMCP_Credential_Scan::text_holds( $text ), true );
  check( "CONTROL: scanner: the probe for $label really contains the secret exactly once", substr_count( $text, $secret ), 1 );
  check( "scanner: $label: blanking the spans replaces exactly the secret and nothing else",
    GMCP_Credential_Scan::redact_spans( $text, '[x]' ), str_replace( $secret, '[x]', $text ) );
}
$prose4 = [
  [ "const STORAGE_KEY = 'todos-vuejs-2.0'", "const STORAGE_KEY = '$L24'" ],
  [ 'self.primary_key = "product_code"', "self.primary_key = \"$L24\"" ],
  [ '{"table": "orders", "primary_key": "order_id"}', "{\"table\": \"orders\", \"primary_key\": \"$L24\"}" ],
  [ "const SESSION_KEY = 'user'", "const SESSION_KEY = '$L24'" ],
  [ "'root_key' => 'menu'", "'root_key' => '$L24'" ],
  [ '"service_key": "billing"', "\"service_key\": \"$L24\"" ],
  [ '"shared_key": "none yet"', "\"shared_key\": \"$L24\"" ],
  [ 'primary_key: order_id_2', "primary_key: $L24" ],
  [ 'ssh_key: "~/.ssh/id_ed25519.pub"', "ssh_key: \"$L24\"" ],
  [ 'PWD=/var/www/html/wp-content', "PWD=$L24" ],
  [ '"session_id": "abcd"', '"session_id": "' . bin2hex( 'Zx9Qw3Lp7Rt2Zx9Q' ) . '"' ],
  [ 'Buy here: https://park.example.com/buy?pass=annual2025', 'Buy here: https://park.example.com/buy?pass=' . $S ],
  [ 'Username: admin, Password: YourPassword', 'Username: admin, Password: rzAnTNbJYjykXw' ],
  [ 'Password: JavaScript (enable it)', 'Password: rzAnTNbJYjykXw (enable it)' ],
  [ '{"pw":"full"}', '{"pw":"' . $S . '"}' ],
  [ 'docker run --user deploy:www-data2 php:8.2-fpm', 'curl --user deploy:www-data2 https://x.example/' ],
  [ 'docker run -u 1000:docker1 image', 'curl -u 1000:docker1 https://x.example/' ],
  [ 'sudo -u www-data:wwwdata2 wp cache flush', 'sudo curl -u www-data:wwwdata2 https://x.example/' ],
  [ 'DB_PASSWORD=config.db.password', 'DB_PASSWORD=this.Kx92mQ!' ],
  [ '$token = $_POST[', '$token = Kx92mQ7zp.' ],
  [ '<input type="checkbox" name="remember_token" value="remember">', '<input type="checkbox" name="remember_token" value="Kx92mQ7zpL0aa">' ],
  [ '<input type="submit" name="regenerate_token" value="Regenerate-key">', '<input type="text" name="regenerate_token" value="Kx92mQ7zpL0aa">' ],
  [ '<input type="text" name="secret" value="Not set">', '<input type="password" name="secret" value="Not set up yet 1">' ],
];
foreach ( $prose4 as $i => [ $harmless, $control ] ) {
  check( "scanner: fourth-review prose row $i is not flagged: " . substr( $harmless, 0, 50 ), GMCP_Credential_Scan::text_holds( $harmless ), false );
  check( "CONTROL: scanner: fourth-review prose row $i has a counterpart that is flagged: " . substr( $control, 0, 50 ), GMCP_Credential_Scan::text_holds( $control ), true );
}
check( 'scanner: a quoted value is data: only an UNQUOTED complete lookup is exempt (control for the rows above)',
  [ GMCP_Credential_Scan::text_holds( 'password = process.env.DB_PASSWORD' ), GMCP_Credential_Scan::text_holds( "password = 'process.env.DB_PASSWORD'" ) ], [ false, true ] );
// A passphrase in a tag opened with several quotes and a ">" must not make the search quadratic, and a
// 50 KB tag is read.
check( 'scanner: a password field in a tag of 50 KB is read', GMCP_Credential_Scan::text_holds( '<input class="' . str_repeat( 'w ', 25000 ) . '" type="password" name="password" value="Kx92mQ7zp">' ), true );

// Documented gaps stay gaps until someone decides otherwise: the scanner does not claim these, and
// the guardrails page lists them. A bare secret, a password said in a sentence, a name that does not
// end in a secret word, and a short wordlike password after an unquoted name.
foreach ( [
  'a bare random string'                 => 'It is ' . 'h4PyKX2JRJhpaHDmkXq3GVXdLtiuNLLf' . '.',
  'a password said in a sentence'        => 'The password is hunter49 so please change it.',
  'a hash under an ordinary name'        => 'hmac = ' . $H64,
  'a short wordlike unquoted password'   => 'password=letmein',
  'a snake_case passphrase after an unquoted password name' => 'password=correct_horse_battery_staple',
] as $label => $text ) {
  check( "scanner: DOCUMENTED GAP, $label is not flagged", GMCP_Credential_Scan::text_holds( $text ), false );
}

// A 64-character name no longer matches only up to a bound, and a run of backslashes is one escape.
check( 'scanner: a name past the old 63-character bound is judged by its last word, not skipped',
  GMCP_Credential_Scan::name_strength( str_repeat( 'a', 70 ) . '_password' ), 2 );
check( 'scanner: data-sitekey is public, site_key is not exempt (the same words name a private key elsewhere)',
  [ GMCP_Credential_Scan::name_strength( 'data-sitekey' ), GMCP_Credential_Scan::name_strength( 'site_key' ) ], [ 0, 2 ] );
// Several credentials in one escaped text: every span lands on its own secret.
$multi = '{\\"password\\":\\"Abc12345\\"} and \\u0027x\\u0027 define(\'DB_PASSWORD\', \'q\\\'Z9y8x7w6\') then ' . 'sk_' . 'live_abcdefgh12345678';
check( 'scanner: three credentials in one mixed-escape text are each blanked and the text between is kept',
  GMCP_Credential_Scan::redact_spans( $multi, '[x]' ),
  '{\\"password\\":\\"[x]\\"} and \\u0027x\\u0027 define(\'DB_PASSWORD\', \'[x]\') then [x]' );
// A private key block is one span, and an unterminated run of them is one span to the end, not one search each.
$keys17 = str_repeat( "-----" . "BEGIN RSA PRIVATE KEY-----\n", 17500 );
$t0 = microtime( true );
$key_spans = GMCP_Credential_Scan::spans( 'before ' . $keys17 );
$key_ms = ( microtime( true ) - $t0 ) * 1000;
check( 'scanner: 17,500 unterminated BEGIN lines are one span from the first to the end of the text', $key_spans, [ [ 7, strlen( $keys17 ) ] ] );
check( 'scanner: and they are read in well under a second (3.4 s before; a bound, not a benchmark)', $key_ms < 1000, true );
check( 'scanner: two closed blocks are two spans and the text between them is kept',
  GMCP_Credential_Scan::redact_spans( "a -----" . "BEGIN RSA PRIVATE KEY-----\nAAA\n-----END RSA PRIVATE KEY----- b -----" . "BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY----- c", '[x]' ),
  'a [x] b [x] c' );
// Idempotence: a text that was already scrubbed is not changed by scrubbing it again.
$idem = true;
foreach ( $flag_rows as $label => [ $text ] ) {
  $once = GMCP_Credential_Scan::redact_spans( $text, '[redacted]' );
  $idem = $idem && GMCP_Credential_Scan::redact_spans( $once, '[redacted]' ) === $once;
}
check( 'scanner: scrubbing an already scrubbed text changes nothing, for every flagged row above', $idem, true );
// The budget: a value with more nodes than can be read is not "clean".
check( 'CONTROL: a value of a thousand short leaves is read and is clean', GMCP_Credential_Scan::holds( array_fill( 0, 1000, 'a' ) ), false );
check( 'a value of 150,000 one-character leaves is over the budget and is treated as unsafe to keep', GMCP_Credential_Scan::holds( array_fill( 0, 150000, 'a' ) ), true );
check( 'within_budget agrees', [ GMCP_Credential_Scan::within_budget( array_fill( 0, 1000, 'a' ) ), GMCP_Credential_Scan::within_budget( array_fill( 0, 150000, 'a' ) ) ], [ true, false ] );

echo "-- post fields never enter the journal carrying a credential --\n";
// The gap: holds_credential() judges field names and opens only a value that BEGINS as JSON or
// serialized data, so a post body with a settings snippet in the middle of it was copied whole.
$SECRET_JSON = 'sk_live_Zq81JsH2pLm94Xa';
$SECRET_DEFINE = 'Xq7!rT9vLm2Zp4';
$SECRET_BEARER = 'aB3dE5gH7jK9mN1pQ3sT5';
$body_json = '<p>Setup notes</p> {"api_key":"' . $SECRET_JSON . '"} <p>end</p>';
$body_define = "<pre>define('DB_PASSWORD','" . $SECRET_DEFINE . "');</pre>";
$body_bearer = 'Call it with Authorization: Bearer ' . $SECRET_BEARER . ' on every request.';
$journal_has = function ( string $needle ): bool {
  return strpos( serialize( journal() ), $needle ) !== false;
};
function last_entry(): array {
  $all = journal();
  return (array) end( $all );
}
function post_change( int $id, string $call, array $fields, string $label = 'Page' ): void {
  global $observer;
  $observer->observe( [ 'kind' => 'post', 'op' => 'updated', 'id' => $id, 'subject' => 'post', 'label' => $label,
    'tool' => 'wp_update_post', 'call' => $call, 'fields' => $fields ] );
}
$GLOBALS['gmcp_t_posts'][90] = [ 'post_status' => 'draft', 'post_type' => 'post', 'post_title' => 'New title', 'post_content' => 'new body' ];
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
post_change( 90, 'k1', [ 'post_title' => [ 'from' => 'Old title', 'to' => 'New title' ], 'post_content' => [ 'from' => $body_json, 'to' => 'new body' ] ] );
$e = last_entry();
check( 'CONTROL: the probe finds a value that was kept (the title)', $journal_has( 'Old title' ), true );
check( 'CONTROL: the probe would find the secret if it were there', strpos( serialize( [ 'x' => $body_json ] ), $SECRET_JSON ) !== false, true );
check( 'a JSON snippet inside a post body is not in the journal', $journal_has( $SECRET_JSON ), false );
check( 'the body field is named as never recorded', $e['redacted_fields'] ?? null, [ 'post_content' ] );
check( 'and the title field is kept', array_keys( $e['previous'] ), [ 'post_title' ] );
$listed = GMCP_Journal::recent( 5 )[0];
check( 'the listing says it restores part of it', [ $listed['reversible'], isset( $listed['partial_restore'] ) ], [ true, true ] );
$r = GMCP_Journal::revert( $e['id'] );
check( 'the title undo goes through', $r['ok'], true );
check( 'CONTROL: the title came back', $GLOBALS['gmcp_t_posts'][90]['post_title'], 'Old title' );
check( 'the body was left as it is, and is not a placeholder', $GLOBALS['gmcp_t_posts'][90]['post_content'], 'new body' );

foreach ( [ 'define' => [ $body_define, $SECRET_DEFINE ], 'bearer' => [ $body_bearer, $SECRET_BEARER ] ] as $shape => [ $body, $secret ] ) {
  $GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
  $GLOBALS['gmcp_t_post_writes'] = [];
  post_change( 90, 'k2' . $shape, [ 'post_content' => [ 'from' => $body, 'to' => 'new body' ] ] );
  $e = last_entry();
  check( "a $shape line in a post body is not in the journal", $journal_has( $secret ), false );
  check( "$shape: an entry whose only field was withheld is not reversible", GMCP_Journal::recent( 1 )[0]['reversible'], false );
  $r = GMCP_Journal::revert( $e['id'] );
  check( "$shape: reverting it is refused and writes nothing", [ $r['ok'], $GLOBALS['gmcp_t_post_writes'] ], [ false, [] ] );
}

// An ordinary body that only uses the word is still journalled and still undone.
$prose = '<p>Pick a strong password, never share your password, and keep the secret key and API key safe. Password: required. The token is shown once.</p>'
  . '<!-- wp:post-author {"authorId":3,"authorName":"Alexander","keywords":"password manager tips 2024"} /-->';
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
$GLOBALS['gmcp_t_posts'][90]['post_content'] = 'edited';
post_change( 90, 'k3', [ 'post_content' => [ 'from' => $prose, 'to' => 'edited' ] ] );
$e = last_entry();
check( 'CONTROL (false positive): a body that merely says "password" is kept whole', [ $e['previous']['post_content'] ?? null, isset( $e['redacted_fields'] ) ], [ $prose, false ] );
$r = GMCP_Journal::revert( $e['id'] );
check( 'and is restored byte for byte', [ $r['ok'], $GLOBALS['gmcp_t_posts'][90]['post_content'] ], [ true, $prose ] );

// What an undo overwrites is judged too: a credential written after the entry was recorded.
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
$GLOBALS['gmcp_t_posts'][90]['post_content'] = $body_json;
$GLOBALS['gmcp_t_posts'][90]['post_title'] = 'Live title';
post_change( 90, 'k4', [ 'post_title' => [ 'from' => 'Earlier title', 'to' => 'Live title' ], 'post_content' => [ 'from' => 'Earlier body', 'to' => $body_json ] ] );
$e = last_entry();
check( 'CONTROL: both earlier values were clean and are kept', array_keys( $e['previous'] ), [ 'post_title', 'post_content' ] );
$r = GMCP_Journal::revert( $e['id'] );
check( 'undo overwrites a body holding a credential', [ $r['ok'], $GLOBALS['gmcp_t_posts'][90]['post_content'] ], [ true, 'Earlier body' ] );
check( 'and the credential it overwrote is not recorded anywhere in the journal', $journal_has( $SECRET_JSON ), false );
$redo = entry_by( entry_by( $e['id'] )['undone_by'] );
check( 'the undo is on record, with the title it replaced and the body field named as withheld', [ $redo['previous']['post_title'] ?? null, $redo['redacted_fields'] ?? null ], [ 'Live title', [ 'post_content' ] ] );
$r = GMCP_Journal::revert( $redo['id'] );
check( 'reverting that undo puts the title back and never writes a placeholder over the body',
  [ $r['ok'], $GLOBALS['gmcp_t_posts'][90]['post_title'], $GLOBALS['gmcp_t_posts'][90]['post_content'] ], [ true, 'Live title', 'Earlier body' ] );

// An undo whose recorded value is a status WordPress deletes on.
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
foreach ( [ 'auto-draft', 'trash', 'Trash ' ] as $deleting ) {
  $GLOBALS['gmcp_t_posts'][90] = [ 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'Changed', 'post_content' => 'x' ];
  $GLOBALS['gmcp_t_post_writes'] = [];
  post_change( 90, 'k5', [ 'post_title' => [ 'from' => 'Before', 'to' => 'Changed' ], 'post_status' => [ 'from' => $deleting, 'to' => 'publish' ] ] );
  $r = GMCP_Journal::revert( last_entry()['id'] );
  check( "status \"$deleting\": the undo succeeds for the rest of the entry", [ $r['ok'], $GLOBALS['gmcp_t_posts'][90]['post_title'] ], [ true, 'Before' ] );
  check( "status \"$deleting\": the status is not written and is left published", [ array_key_exists( 'post_status', $GLOBALS['gmcp_t_post_writes'][0] ?? [] ), $GLOBALS['gmcp_t_posts'][90]['post_status'] ], [ false, 'publish' ] );
}
$GLOBALS['gmcp_t_posts'][90] = [ 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'Changed', 'post_content' => 'x' ];
$GLOBALS['gmcp_t_post_writes'] = [];
post_change( 90, 'k6', [ 'post_status' => [ 'from' => 'draft', 'to' => 'publish' ] ] );
GMCP_Journal::revert( last_entry()['id'] );
check( 'CONTROL: an ordinary earlier status IS written back', $GLOBALS['gmcp_t_posts'][90]['post_status'], 'draft' );

// A forged entry naming fields outside the recorder's list.
$GLOBALS['gmcp_t_posts'][90] = [ 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'Changed', 'post_content' => 'x', 'post_author' => 5, 'post_password' => '' ];
$GLOBALS['gmcp_t_post_writes'] = [];
post_change( 90, 'k7', [ 'post_title' => [ 'from' => 'Before', 'to' => 'Changed' ], 'post_type' => [ 'from' => 'shop_order', 'to' => 'post' ],
  'post_author' => [ 'from' => 1, 'to' => 5 ], 'post_password' => [ 'from' => 'pw', 'to' => '' ], 'post_date' => [ 'from' => '1970-01-01', 'to' => 'now' ] ] );
GMCP_Journal::revert( last_entry()['id'] );
check( 'CONTROL: the watched field was written', array_keys( $GLOBALS['gmcp_t_post_writes'][0] ?? [] ), [ 'ID', 'post_title' ] );
check( 'post_type, post_author, post_password and post_date are never written by an undo',
  [ $GLOBALS['gmcp_t_posts'][90]['post_type'], $GLOBALS['gmcp_t_posts'][90]['post_author'], $GLOBALS['gmcp_t_posts'][90]['post_password'] ], [ 'post', 5, '' ] );

echo "-- what lines and the audit summary never quote a credential --\n";
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
$GLOBALS['gmcp_t_posts'][90] = [ 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'x', 'post_content' => 'x' ];
post_change( 90, 'k8', [ 'post_title' => [ 'from' => 'Old', 'to' => 'x' ] ], "define('DB_PASSWORD','" . $SECRET_DEFINE . "')" );
check( 'a title that is itself a credential is not quoted in the "what" line', [ $journal_has( $SECRET_DEFINE ), last_entry()['what'] ], [ false, 'Post "[redacted]" changed: post_title' ] );
post_change( 90, 'k9', [ 'post_title' => [ 'from' => 'Old', 'to' => 'x' ] ], 'Plain title' );
check( 'CONTROL: an ordinary title is quoted', last_entry()['what'], 'Post "Plain title" changed: post_title' );

echo "-- the wider test for one-line descriptions, and the narrow one for what undo keeps --\n";
// A title that reads like an assignment but is not a shape the scanner recognises: six lowercase
// letters, no digit. It is in the "what" line and the audit summary, both shown by read-level
// tools, so it is withheld there. The value an undo would put back is judged by the scanner alone.
$SECRET_SHORT = 'hunterz';
post_change( 90, 'k10', [ 'post_title' => [ 'from' => 'Reset your password: easy guide', 'to' => 'x' ] ], 'smtp_pwd=' . $SECRET_SHORT );
$e = last_entry();
check( 'CONTROL: the scanner alone does not call that title a credential (so the wider test is what withholds it)', GMCP_Credential_Scan::text_holds( 'smtp_pwd=' . $SECRET_SHORT ), false );
check( 'a title that is an assignment is not quoted in the "what" line', [ strpos( $e['what'], $SECRET_SHORT ), $e['what'] ], [ false, 'Post "[redacted]" changed: post_title' ] );
check( 'CONTROL: the previous value, which only reads like prose about a password, is still kept for undo', $e['previous']['post_title'] ?? null, 'Reset your password: easy guide' );
check( 'CONTROL: and its entry is reversible', GMCP_Journal::recent( 1 )[0]['reversible'], true );
$observer->observe( [ 'kind' => 'option', 'op' => 'updated', 'id' => 'my_secret=' . $SECRET_SHORT, 'fields' => [ 'value' => [ 'from' => 'a', 'to' => 'b' ] ], 'tool' => 'wp_update_option', 'call' => 'k11' ] );
check( 'an option named like an assignment is not quoted in the "what" line', [ strpos( last_entry()['what'], $SECRET_SHORT ), last_entry()['what'] ], [ false, 'Option "[redacted]" changed' ] );
// An entry recorded before the check existed is re-read on the way out and on the way into an undo's line.
update_option( GMCP_Journal::OPTION, [
  [ 'id' => 'leg-cred', 't' => time(), 'kind' => 'post', 'ID' => 78, 'what' => 'Post "smtp_pwd=' . $SECRET_SHORT . '" changed: post_title', 'fields' => [ 'post_title' ], 'previous' => [ 'post_title' => 'x' ], 'tool' => 'wp_update_post', 'call' => 'kz1' ],
  [ 'id' => 'leg-ok', 't' => time(), 'kind' => 'post', 'ID' => 78, 'what' => 'Post "Ordinary" changed: post_title', 'fields' => [ 'post_title' ], 'previous' => [ 'post_title' => 'x' ], 'tool' => 'wp_update_post', 'call' => 'kz2' ],
], false );
$by = [];
foreach ( GMCP_Journal::recent( 10 ) as $row ) {
  $by[ $row['id'] ] = $row;
}
check( 'CONTROL: a stored entry with an ordinary description is listed as it was', $by['leg-ok']['what'], 'Post "Ordinary" changed: post_title' );
check( 'a stored entry whose description holds an assignment is listed withheld', [ strpos( $by['leg-cred']['what'], $SECRET_SHORT ), $by['leg-cred']['what'] ], [ false, 'A change whose description held something credential-shaped (withheld).' ] );
$undone = GMCP_Journal::revert( 'leg-cred' );
$undo_entry = null;
foreach ( journal() as $row ) {
  if ( ( $row['undoes'] ?? '' ) === 'leg-cred' ) {
    $undo_entry = $row;
  }
}
check( 'CONTROL: that stored entry can still be undone', $undone['ok'], true );
check( "and the undo's own line does not carry the assignment forward", [ $undo_entry !== null, strpos( (string) ( $undo_entry['what'] ?? '' ), $SECRET_SHORT ) ], [ true, false ] );
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];

$summary = json_decode( (string) GMCP_Changes::summarise( [ [ 'kind' => 'post', 'op' => 'updated', 'id' => 90, 'subject' => 'post', 'label' => 'Bearer ' . $SECRET_BEARER,
  'fields' => [ 'post_excerpt' => [ 'from' => $body_bearer, 'to' => 'A harmless excerpt' ], 'post_title' => [ 'from' => 'Plain', 'to' => 'Also plain' ] ] ] ], 16000 ), true );
$flat = json_encode( $summary );
check( 'CONTROL: the summary carries the harmless values', [ strpos( $flat, 'A harmless excerpt' ) !== false, strpos( $flat, 'Also plain' ) !== false ], [ true, true ] );
check( 'a short excerpt holding a bearer token is not in the audit summary, nor is a title that is one', strpos( $flat, $SECRET_BEARER ), false );
check( 'it is recorded as redacted rather than dropped', $summary[0]['fields']['post_excerpt']['from'], '[redacted]' );

echo "-- a form submission is neither named nor put back --\n";
$GLOBALS['gmcp_t_posts'][90] = [ 'post_status' => 'publish', 'post_type' => 'flamingo_inbound', 'post_title' => 'Visitor subject line' ];
check( 'CONTROL: an ordinary post is undoable (the probe can find a yes)', GMCP_Changes::undoable_post( 78 ), true );
check( 'a submission is not undoable', GMCP_Changes::undoable_post( 90 ), false );
// An entry written before records about submissions were withheld names the post by its title.
update_option( GMCP_Journal::OPTION, [
  [ 'id' => 'leg-sub', 't' => time(), 'kind' => 'post', 'ID' => 90, 'what' => 'Submission "Visitor subject line" changed: post_title', 'fields' => [ 'post_title' ], 'previous' => [ 'post_title' => 'x' ], 'tool' => 'wp_update_post', 'call' => 'k1' ],
  [ 'id' => 'leg-ord', 't' => time(), 'kind' => 'post', 'ID' => 78, 'what' => 'Post "Ordinary title" changed: post_title', 'fields' => [ 'post_title' ], 'previous' => [ 'post_title' => 'x' ], 'tool' => 'wp_update_post', 'call' => 'k2' ],
], false );
$shown = GMCP_Journal::recent( 10 );
$by = [];
foreach ( $shown as $row ) { $by[ $row['id'] ] = $row; }
check( 'CONTROL: an old entry about an ordinary post is listed with its title', strpos( $by['leg-ord']['what'], 'Ordinary title' ) !== false, true );
check( 'an old entry about a submission is listed without its title', strpos( $by['leg-sub']['what'], 'Visitor subject line' ) === false, true );
check( 'and says it is a form submission', strpos( $by['leg-sub']['what'], 'form submission' ) !== false, true );
$GLOBALS['gmcp_t_post_writes'] = [];
$res = GMCP_Journal::revert( 'leg-sub' );
check( 'an old entry about a submission is not put back', $res['ok'], false );
check( 'and nothing was written', $GLOBALS['gmcp_t_post_writes'], [] );

// A record whose values were withheld holds "[withheld]" where the previous values were. The post
// can be an ordinary row by the time the record is made (it was moved out of a submission's tree by
// a writer that asked no guard), so the journal cannot rely on the current type to refuse it.
$GLOBALS['gmcp_t_options'][ GMCP_Journal::OPTION ] = [];
$GLOBALS['gmcp_t_posts'][91] = [ 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_title' => 'Moved upload' ];
$observer->observe( [ 'kind' => 'post', 'op' => 'updated', 'id' => 91, 'subject' => 'attachment', 'label' => '#91', 'tool' => 'wp_update_post', 'call' => 'w1',
  'withheld' => true, 'fields' => [ 'post_title' => [ 'from' => '[withheld]', 'to' => '[withheld]' ] ] ] );
check( 'a withheld record is not journalled, so no undo can write the markers over a post', count( journal() ), 0 );
$observer->observe( [ 'kind' => 'post', 'op' => 'updated', 'id' => 91, 'subject' => 'attachment', 'label' => 'Moved upload', 'tool' => 'wp_update_post', 'call' => 'w2',
  'fields' => [ 'post_title' => [ 'from' => 'Before', 'to' => 'Moved upload' ] ] ] );
check( 'CONTROL: the same record without the flag is journalled (the probe sees an entry)', count( journal() ), 1 );

echo "\n$total checks, $fails failed\n";
exit( min( 255, $fails ) );
