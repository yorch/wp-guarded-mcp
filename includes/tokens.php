<?php

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

/**
* Named keys, each with its own reach and its own expiry date.
*
* The single shared bearer token is fine for one person connecting one client. It stops
* being fine the moment there are two of anything. It has one access level for everybody,
* it never expires, revoking it disconnects every client at once, and when something odd
* turns up in the activity log there is no way to tell which client did it.
*
* A key here carries a label, an access level, an optional expiry, and an optional list
* of the only tools it may call. That last part is the useful one: a key for a deploy
* script that may read posts and nothing else is a different kind of object from a key
* that can delete the theme, and until now they were the same string.
*
* Secrets are stored hashed. The plaintext is shown once, at creation, and then it is
* gone. This is deliberately different from the shared bearer token, which the settings
* screen still displays: that one predates this and people rely on being able to read it
* back. A key you can read back out of the database is a key that leaks with the database.
*
* Not a rate limiter and not an audit trail. Reach and lifetime, nothing else.
*/
class GMCP_Tokens {

  const OPTION = 'gmcp_tokens';
  const LEVELS = [ 'readonly', 'readwrite', 'admin' ];
  /** Writing last_used on every call would mean an option write per request. */
  const TOUCH_INTERVAL = 300;

  public static function all(): array {
    $rows = get_option( self::OPTION, [] );
    return is_array( $rows ) ? $rows : [];
  }

  /**
  * Create a key and return the plaintext exactly once.
  *
  * @param array $tools Tool names this key may call. Empty means every tool its level allows.
  * @return array{id:string,secret:string}
  */
  /**
  * @param int $owner The administrator this key acts as. Defaults to whoever is creating it.
  */
  public static function create( string $label, string $level, int $expires_days, array $tools,
    int $owner = 0 ): array {
    $rows = self::all();
    $owner = $owner > 0 ? $owner : get_current_user_id();

    $id = bin2hex( random_bytes( 4 ) );
    // Long enough that guessing is not a strategy, and hex so it survives being pasted
    // into a shell, a YAML file, or a JSON config without quoting surprises.
    $secret = bin2hex( random_bytes( 24 ) );
    $token = 'gmcp_' . $id . '_' . $secret;

    $rows[ $id ] = [
      'id' => $id,
      'label' => mb_substr( sanitize_text_field( $label ), 0, 60 ) ?: 'Unnamed key',
      'hash' => hash( 'sha256', $token ),
      'level' => in_array( $level, self::LEVELS, true ) ? $level : 'readonly',
      'tools' => array_values( array_filter( array_map( 'sanitize_key', $tools ) ) ),
      // The account this key acts as, recorded once at creation.
      //
      // Before this, every static credential borrowed the lowest-numbered administrator,
      // so the audit log named the same account whoever was holding the key and could not
      // answer "who did this". A key that carries its owner makes the log say something
      // true, and makes revoking one person's access revoke their agent with it.
      'owner' => $owner,
      'created' => time(),
      'expires' => $expires_days > 0 ? time() + ( $expires_days * DAY_IN_SECONDS ) : 0,
      'last_used' => 0,
    ];
    update_option( self::OPTION, $rows, false );

    return [ 'id' => $id, 'secret' => $token ];
  }

  public static function revoke( string $id ): bool {
    $rows = self::all();
    if ( !isset( $rows[ $id ] ) ) {
      return false;
    }
    unset( $rows[ $id ] );
    update_option( self::OPTION, $rows, false );
    return true;
  }

  /**
  * Match a presented token against the stored keys.
  *
  * The id travels in the token, so this is a direct lookup rather than a walk over every
  * stored hash. The comparison is still constant-time: knowing which key you are being
  * compared against tells an attacker nothing, but leaking how much of the secret they
  * got right would.
  *
  * @return array|null The key row, or null if unknown, malformed or expired.
  */
  public static function match( string $token ): ?array {
    $row = self::by_id( $token ) ?? self::legacy_match( $token );
    if ( !is_array( $row ) ) {
      return null;
    }
    if ( !empty( $row['expires'] ) && $row['expires'] < time() ) {
      return null;
    }
    // Checked on every request rather than at creation, because the answer changes
    // without anything touching this key: demoting the owner, or deleting the account,
    // has to take their agent's access with it. This is how OAuth already behaves, and a
    // static credential that outlived its owner's own access would be the hole the OAuth
    // path was careful not to leave.
    //
    // A key with no owner is one migrated from the retired shared token, which had no
    // identity to carry over. Those are checked against the fallback administrator
    // instead, and the screen asks you to replace them.
    if ( !self::owner_still_allowed( $row ) ) {
      return null;
    }
    return $row;
  }

  /** A key found by the id it carries, which is how every key this plugin issues works. */
  private static function by_id( string $token ): ?array {
    if ( strpos( $token, 'gmcp_' ) !== 0 ) {
      return null;
    }
    $parts = explode( '_', $token );
    if ( count( $parts ) !== 3 ) {
      return null;
    }
    $row = self::all()[ $parts[1] ] ?? null;
    if ( !is_array( $row ) || empty( $row['hash'] ) ) {
      return null;
    }
    return hash_equals( (string) $row['hash'], hash( 'sha256', $token ) ) ? $row : null;
  }

  /**
  * A secret carried over from the retired shared token, which has no id in it.
  *
  * The shared token was whatever string the site put in the box, so there is no id to
  * look up and the only way to recognise it is to hash what was presented and compare.
  * That is a scan, which is why it is confined to rows that say they came from the old
  * token: there is at most one, and an ordinary key never reaches this.
  */
  private static function legacy_match( string $token ): ?array {
    if ( $token === '' ) {
      return null;
    }
    $presented = hash( 'sha256', $token );
    foreach ( self::all() as $row ) {
      if ( empty( $row['legacy'] ) || empty( $row['hash'] ) ) {
        continue;
      }
      if ( hash_equals( (string) $row['hash'], $presented ) ) {
        return $row;
      }
    }
    return null;
  }

  /**
  * Turn a shared bearer token into a key, once.
  *
  * The shared token is gone, and an upgrade that simply dropped it would disconnect every
  * client on the site with no message anywhere explaining why. So the secret keeps
  * working, as a key: same string, now stored only as a hash, with the access level the
  * token had.
  *
  * The owner is left at 0 deliberately. The shared token never had an identity to carry
  * over, and inventing one would put a real person's name against calls they did not
  * make. A key with no owner falls back to the site's administrator and is flagged on the
  * screen as worth replacing.
  *
  * Runs on load rather than only on activation, because WordPress does not fire the
  * activation hook when a plugin is updated in place, and an upgrade is exactly when this
  * has to happen. @see GMCP_Audit::__construct() for the same reasoning.
  */
  public static function adopt_shared_token(): void {
    $core = $GLOBALS['gmcp_core'] ?? null;
    if ( !$core ) {
      return;
    }
    $token = (string) $core->get_option( 'mcp_bearer_token' );
    if ( $token === '' ) {
      return;
    }

    $rows = self::all();
    $id = bin2hex( random_bytes( 4 ) );
    $rows[ $id ] = [
      'id' => $id,
      'label' => __( 'Shared token (carried over)', 'guarded-mcp' ),
      'hash' => hash( 'sha256', $token ),
      'level' => in_array( $core->get_option( 'mcp_role', 'admin' ), self::LEVELS, true )
        ? $core->get_option( 'mcp_role', 'admin' ) : 'admin',
      'tools' => [],
      'owner' => 0,
      'legacy' => true,
      'created' => time(),
      'expires' => 0,
      'last_used' => 0,
    ];
    update_option( self::OPTION, $rows, false );

    // Only now, and this order matters: if the write above fails the secret is still in
    // the options row and the site still works. Cleared rather than kept, because leaving
    // it would mean the plaintext of a working credential stayed in the database, which
    // is the whole reason the shared token was retired.
    $core->update_option( 'mcp_bearer_token', '' );
  }

  /** Whether the account a key acts as may still authorise anything at all. */
  public static function owner_still_allowed( array $row ): bool {
    $owner = (int) ( $row['owner'] ?? 0 );
    if ( $owner <= 0 ) {
      return true;
    }
    return get_userdata( $owner ) && user_can( $owner, 'manage_options' );
  }

  /**
  * Record that a key was used, rarely enough that it is not an option write per request.
  *
  * There was a use counter here too. It was incremented and then thrown away on every
  * throttled call, because the early return came after the increment and before the
  * write, so it only ever counted one use per interval. Nothing displayed it. A counter
  * that is wrong and unread is worse than no counter, so it is gone.
  *
  * The re-read matters. This is a read-modify-write of one option row holding every key,
  * and writing back a copy fetched before the throttle check would resurrect a key
  * revoked in between. The object cache has to be dropped first or the second read
  * returns the same stale array. This narrows the window rather than closing it: two
  * simultaneous first uses of different keys can still lose one update. Nothing here is
  * a security boundary, only a "last used" hint, and revocation is checked on the next
  * request either way.
  */
  public static function touch( string $id ): void {
    $rows = self::all();
    if ( !isset( $rows[ $id ] ) ) {
      return;
    }
    if ( time() - (int) ( $rows[ $id ]['last_used'] ?? 0 ) < self::TOUCH_INTERVAL ) {
      return;
    }

    wp_cache_delete( self::OPTION, 'options' );
    $fresh = self::all();
    if ( !isset( $fresh[ $id ] ) ) {
      return;
    }
    $fresh[ $id ]['last_used'] = time();
    update_option( self::OPTION, $fresh, false );
  }

  /**
  * Whether a key is allowed to call a given tool.
  *
  * An empty list means "everything the access level allows", not "nothing". The two
  * readings are opposite and the wrong one fails open, so this is written out rather
  * than left to an empty-array truthiness check at each call site.
  */
  public static function allows_tool( array $row, string $tool ): bool {
    $tools = (array) ( $row['tools'] ?? [] );
    if ( !$tools ) {
      return true;
    }
    return in_array( $tool, $tools, true );
  }

  /** Expired keys, kept for the settings screen so a person can see why a client stopped working. */
  public static function is_expired( array $row ): bool {
    return !empty( $row['expires'] ) && $row['expires'] < time();
  }
}
