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
class REEVE_Tokens {

  const OPTION = 'reeve_tokens';
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
  public static function create( string $label, string $level, int $expires_days, array $tools ): array {
    $rows = self::all();

    $id = bin2hex( random_bytes( 4 ) );
    // Long enough that guessing is not a strategy, and hex so it survives being pasted
    // into a shell, a YAML file, or a JSON config without quoting surprises.
    $secret = bin2hex( random_bytes( 24 ) );
    $token = 'reeve_' . $id . '_' . $secret;

    $rows[ $id ] = [
      'id' => $id,
      'label' => mb_substr( sanitize_text_field( $label ), 0, 60 ) ?: 'Unnamed key',
      'hash' => hash( 'sha256', $token ),
      'level' => in_array( $level, self::LEVELS, true ) ? $level : 'readonly',
      'tools' => array_values( array_filter( array_map( 'sanitize_key', $tools ) ) ),
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
    if ( strpos( $token, 'reeve_' ) !== 0 ) {
      return null;
    }
    $parts = explode( '_', $token );
    if ( count( $parts ) !== 3 ) {
      return null;
    }
    $rows = self::all();
    $row = $rows[ $parts[1] ] ?? null;
    if ( !is_array( $row ) || empty( $row['hash'] ) ) {
      return null;
    }
    if ( !hash_equals( (string) $row['hash'], hash( 'sha256', $token ) ) ) {
      return null;
    }
    if ( !empty( $row['expires'] ) && $row['expires'] < time() ) {
      return null;
    }
    return $row;
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
