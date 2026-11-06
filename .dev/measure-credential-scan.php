<?php
/**
* Measures GMCP_Credential_Scan against a generated corpus of realistic strings, and says how many
* credentials it misses and how much ordinary prose it flags.
*
* Run:  php .dev/measure-credential-scan.php [--seed=N] [--per=N] [--show=N] [--dump=FILE] [--assert]
*
*   --seed  the random seed (default 1; the corpus is a pure function of it)
*   --per   how many samples to draw from each template (default 20)
*   --show  how many failing samples to print per class (default 1; 0 hides, 100 also lists the residual classes)
*   --dump  write the corpus to FILE, one JSON object per line. The file holds strings shaped like
*           real vendor keys (built here at run time), so do NOT commit it: GitHub push protection
*           blocks the push. This file contains none, which is why the shapes are assembled below.
*   --assert  exit non-zero if a class not listed in KNOWN_RESIDUAL has a miss, if any prose is
*           flagged, or if a KNOWN_RESIDUAL class has none (a residual that is now found should be
*           moved out of the list, so the document stays true).
*
* Not shipped, needs no WordPress. The corpus is generated, not collected: every template is a
* layout that really appears in a post body, a pasted config, a support ticket or a log, filled
* with names and values drawn at random. A "miss" is a sample whose secret the scanner did not
* find, or found without covering the secret's bytes (the audit log blanks only the spans). A
* "false alarm" is a sample of ordinary text that holds no credential and is flagged anyway.
*
* What this is not: a measure of how the scanner does on text this file's author did not think of.
* The templates were written after reading the scanner's rules and after reading what people paste,
* and the false-alarm side is prose written to resemble the cases the rules were built around. The
* numbers are a regression instrument and a map of the known gaps, not a recall estimate.
*/

require __DIR__ . '/lib-core-bootstrap.php';

$opt = [ 'seed' => 1, 'per' => 20, 'show' => 1, 'dump' => '', 'assert' => false ];
foreach ( array_slice( $argv, 1 ) as $arg ) {
  if ( $arg === '--assert' ) {
    $opt['assert'] = true;
  }
  elseif ( preg_match( '/^--(seed|per|show|dump)=(.*)$/', $arg, $m ) ) {
    $opt[ $m[1] ] = is_numeric( $m[2] ) ? (int) $m[2] : $m[2];
  }
}
mt_srand( (int) $opt['seed'] );

/* ---------------------------------------------------------------------------------------------
 * Random material
 * ------------------------------------------------------------------------------------------- */
function pick( array $a ) {
  return $a[ mt_rand( 0, count( $a ) - 1 ) ];
}
function chars( string $set, int $n ): string {
  $out = '';
  $max = strlen( $set ) - 1;
  for ( $i = 0; $i < $n; $i++ ) {
    $out .= $set[ mt_rand( 0, $max ) ];
  }
  return $out;
}
function alnum( int $n ): string {
  // At least one digit and one letter, as a generated secret has.
  $s = chars( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $n );
  return preg_match( '/\d/', $s ) && preg_match( '/[A-Za-z]/', $s ) ? $s : 'k7' . substr( $s, 2 );
}
function hex( int $n ): string {
  return chars( '0123456789abcdef', $n );
}
function b64( int $n ): string {
  return alnum( $n - 2 ) . chars( '+/', 1 ) . chars( 'AZ09', 1 );
}
function b64url( int $n ): string {
  return alnum( $n - 2 ) . chars( '-_', 1 ) . chars( 'az09', 1 );
}
/** A password a person would choose or a generator would make. */
function password(): string {
  return pick( [
    fn() => alnum( mt_rand( 10, 24 ) ),
    fn() => 'P@ss' . mt_rand( 100, 9999 ) . '!',
    fn() => pick( [ 'Summer', 'Winter', 'Welcome', 'Monkey', 'Dragon', 'Liverpool' ] ) . mt_rand( 10, 2099 ) . pick( [ '!', '#', '', '$' ] ),
    fn() => 'hunter' . mt_rand( 2, 99 ),
    fn() => pick( [ 'admin', 'root', 'secret' ] ) . mt_rand( 10, 999 ),
    fn() => chars( 'abcdefghijkmnpqrstuvwxyz', 4 ) . '-' . chars( 'abcdefghijkmnpqrstuvwxyz', 4 ) . '-' . mt_rand( 1000, 9999 ),
    fn() => chars( 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%^*', mt_rand( 12, 20 ) ),
  ] )();
}
/** Vendor-shaped keys, assembled here so no literal in this tree looks like one. */
function vendor_key(): string {
  $a = [ 's' . 'k_' . 'live_', 's' . 'k_' . 'test_', 'r' . 'k_' . 'live_' ];
  return pick( [
    fn() => pick( $a ) . alnum( 24 ),
    fn() => 'AK' . 'IA' . chars( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', 16 ),
    fn() => 'gh' . 'p_' . alnum( 36 ),
    fn() => 'github' . '_pat_' . alnum( 22 ) . '_' . alnum( 40 ),
    fn() => 'xo' . 'xb-' . mt_rand( 1000000000, 9999999999 ) . '-' . mt_rand( 1000000000, 9999999999 ) . '-' . alnum( 24 ),
    fn() => 'AI' . 'za' . b64url( 35 ),
    fn() => 's' . 'k-' . alnum( 48 ),
    fn() => 's' . 'k-ant-' . 'api03-' . b64url( 60 ),
  ] )();
}
/** A vendor key shape that FIXED does not know. Used for the "unknown prefix" class. */
function other_vendor_key(): string {
  return pick( [
    fn() => 'whs' . 'ec_' . alnum( 32 ),
    fn() => 'np' . 'm_' . alnum( 36 ),
    fn() => 'gl' . 'pat-' . alnum( 20 ),
    fn() => 'sh' . 'pat_' . hex( 32 ),
    fn() => 'S' . 'G.' . b64url( 22 ) . '.' . b64url( 43 ),
    fn() => 'do' . 'p_v1_' . hex( 64 ),
    fn() => 'h' . 'f_' . alnum( 34 ),
    fn() => 'key-' . hex( 32 ),
    fn() => 'xo' . 'xe.xoxp-1-' . alnum( 60 ),
  ] )();
}
function jwt(): string {
  return 'eyJ' . b64url( 20 ) . '.eyJ' . b64url( 40 ) . '.' . b64url( 43 );
}
function pw_name(): string {
  return pick( [ 'DB_PASSWORD', 'db_password', 'MYSQL_PASSWORD', 'MAIL_PASSWORD', 'SMTP_PASS', 'smtp_password', 'admin_password', 'root_password', 'REDIS_PASSWORD', 'FTP_PASS', 'ftp_password', 'passphrase', 'user_pass', 'password', 'Password', 'PASSWORD', 'passwd', 'DB_PASS', 'mysql_root_password', 'POSTGRES_PASSWORD', 'RABBITMQ_DEFAULT_PASS', 'smtp_pwd', 'DB_PWD', 'wp_admin_password', 'login_password', 'user_password', 'pwd' ] );
}
function key_name(): string {
  return pick( [ 'API_KEY', 'api_key', 'apiKey', 'x-api-key', 'X-Api-Key', 'SECRET_KEY', 'secret_key', 'STRIPE_SECRET_KEY', 'AWS_SECRET_ACCESS_KEY', 'PRIVATE_KEY', 'ENCRYPTION_KEY', 'LICENSE_KEY', 'license_key', 'SIGNING_KEY', 'client_secret', 'CLIENT_SECRET', 'APP_SECRET', 'JWT_SECRET', 'SESSION_SECRET', 'WEBHOOK_SECRET', 'NEXTAUTH_SECRET', 'SENDGRID_API_KEY', 'OPENAI_API_KEY', 'AUTH_TOKEN', 'ACCESS_TOKEN', 'access_token', 'refresh_token', 'BOT_TOKEN', 'GITHUB_TOKEN', 'NPM_TOKEN', 'api_token', 'authToken', 'secret', 'token', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'recaptcha_secret', 'mailgun_api_key' ] );
}
function secret_value(): string {
  return pick( [
    fn() => alnum( mt_rand( 20, 40 ) ),
    fn() => hex( pick( [ 32, 40, 64 ] ) ),
    fn() => b64( mt_rand( 28, 44 ) ),
    fn() => b64url( mt_rand( 24, 48 ) ),
    fn() => vendor_key(),
  ] )();
}
function host(): string {
  return pick( [ 'db.internal', 'mysql.example.org', 'localhost', '10.0.4.12', 'redis-1.prod', 'smtp.mailhost.net', 'cache.example.com', 'pg.corp.local' ] );
}
function user(): string {
  return pick( [ 'admin', 'deploy', 'wpuser', 'svc_backup', 'jsmith', 'root', 'app', 'reports' ] );
}
function words( int $n ): string {
  static $w = [ 'the', 'site', 'we', 'our', 'update', 'plugin', 'theme', 'settings', 'page', 'visitors', 'members', 'login', 'account', 'to', 'for', 'and', 'with', 'new', 'release', 'security', 'team', 'guide', 'steps', 'before', 'after', 'your', 'check', 'option', 'configuration', 'server', 'email', 'form', 'checkout', 'shipping', 'cache', 'backup', 'import', 'export', 'dashboard', 'editor', 'block', 'pattern', 'media', 'image', 'gallery', 'widget', 'menu', 'footer', 'header', 'sidebar', 'redirect', 'domain', 'hosting', 'upgrade', 'version', 'notes' ];
  $out = [];
  for ( $i = 0; $i < $n; $i++ ) {
    $out[] = pick( $w );
  }
  return implode( ' ', $out );
}
function sentence(): string {
  return ucfirst( words( mt_rand( 6, 14 ) ) ) . '.';
}

/** A surrounding post body: some paragraphs before and after, as HTML or plain text. */
function around( string $core ): string {
  $style = mt_rand( 0, 3 );
  $pre = sentence() . ' ' . sentence();
  $post = sentence();
  switch ( $style ) {
    case 0:
      return $pre . "\n\n" . $core . "\n\n" . $post;
    case 1:
      return "<!-- wp:paragraph -->\n<p>" . $pre . "</p>\n<!-- /wp:paragraph -->\n\n" . $core . "\n\n<!-- wp:paragraph -->\n<p>" . $post . "</p>\n<!-- /wp:paragraph -->";
    case 2:
      return '<p>' . $pre . '</p><p>' . $core . '</p><p>' . $post . '</p>';
    default:
      return $core;
  }
}
/** A code sample as a post body holds it: in a block, entity-escaped, or bare. */
function codeblock( string $code ): string {
  $esc = htmlspecialchars( $code, ENT_QUOTES );
  switch ( mt_rand( 0, 3 ) ) {
    case 0:
      return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . $esc . "</code></pre>\n<!-- /wp:code -->";
    case 1:
      return '<pre><code>' . $esc . '</code></pre>';
    case 2:
      return "```\n" . $code . "\n```";
    default:
      return $code;
  }
}

/* ---------------------------------------------------------------------------------------------
 * Templates. Each returns [ text, secret ] for a credential, or [ text ] for ordinary text.
 * ------------------------------------------------------------------------------------------- */
$POS = [];   // class => [ templates ]
$NEG = [];

// ---- credentials: layouts the scanner is built for
$POS['wp-config define'] = [
  fn() => [ codeblock( "define( '" . pick( [ 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_SALT', 'WP_MAIL_SMTP_PASS', 'JWT_AUTH_SECRET_KEY' ] ) . "', '" . ( $s = secret_value() ) . "' );" ), $s ],
  fn() => [ codeblock( 'define("DB_PASSWORD","' . ( $s = password() ) . '");' ), $s ],
  fn() => [ around( "define('" . pick( [ 'DB_PASSWORD', 'FTP_PASS', 'SMTP_PASSWORD' ] ) . "', '" . ( $s = password() ) . "'); // " . words( 3 ) ), $s ],
];
$POS['.env file'] = [
  fn() => [ codeblock( "APP_ENV=production\nAPP_DEBUG=false\n" . pw_name() . '=' . ( $s = password() ) . "\nCACHE_DRIVER=file" ), $s ],
  fn() => [ codeblock( "# mail\nMAIL_HOST=" . host() . "\n" . key_name() . '=' . ( $s = secret_value() ) . "\nMAIL_PORT=587" ), $s ],
  fn() => [ codeblock( 'export ' . key_name() . '="' . ( $s = secret_value() ) . '"' ), $s ],
  fn() => [ codeblock( key_name() . "='" . ( $s = secret_value() ) . "'" ), $s ],
  fn() => [ around( "Here is my .env:\n" . key_name() . '=' . ( $s = secret_value() ) . "\nDB_HOST=" . host() ), $s ],
];
$POS['curl and HTTP headers'] = [
  fn() => [ codeblock( 'curl -H "Authorization: Bearer ' . ( $s = jwt() ) . '" https://api.example.com/v1/items' ), $s ],
  fn() => [ codeblock( "curl -H 'Authorization: Bearer " . ( $s = secret_value() ) . "' https://api.example.com/v1/items" ), $s ],
  fn() => [ codeblock( 'curl -H "X-API-Key: ' . ( $s = secret_value() ) . '" https://api.example.com/' ), $s ],
  fn() => [ codeblock( 'curl -u ' . user() . ':' . ( $s = password() ) . ' https://' . host() . '/api' ), $s ],
  fn() => [ codeblock( "curl -X POST https://example.com/login -d 'user=" . user() . '&password=' . ( $s = password() ) . "'" ), $s ],
  fn() => [ codeblock( 'curl "https://example.com/api?token=' . ( $s = secret_value() ) . '&format=json"' ), $s ],
  fn() => [ around( 'Request: GET /v1/me HTTP/1.1' . "\nHost: api.example.com\nAuthorization: Bearer " . ( $s = secret_value() ) ), $s ],
  fn() => [ 'Authorization: Basic ' . ( $s = base64_encode( user() . ':' . password() ) ), $s ],
  fn() => [ 'wget --header="Authorization: token ' . ( $s = alnum( 40 ) ) . '" https://example.com/x.zip', $s ],
];
$POS['JSON'] = [
  fn() => [ codeblock( '{"' . key_name() . '": "' . ( $s = secret_value() ) . '", "region": "eu-west-1"}' ), $s ],
  fn() => [ codeblock( "{\n  \"db\": {\n    \"host\": \"" . host() . "\",\n    \"" . pw_name() . "\": \"" . ( $s = password() ) . "\"\n  }\n}" ), $s ],
  fn() => [ '{"' . pw_name() . '":"' . ( $s = password() ) . '"}', $s ],
  fn() => [ '<!-- wp:acme/form {"apiKey":"' . ( $s = secret_value() ) . '","list":"newsletter"} /-->', $s ],
  fn() => [ htmlspecialchars( '{"' . key_name() . '":"' . ( $s = secret_value() ) . '"}', ENT_QUOTES ), $s ],
  fn() => [ str_replace( '"', '\\"', '{"' . pw_name() . '":"' . ( $s = alnum( 14 ) ) . '"}' ), $s ],
];
$POS['YAML and INI'] = [
  fn() => [ codeblock( "database:\n  host: " . host() . "\n  " . pw_name() . ': ' . ( $s = password() ) . "\n  port: 3306" ), $s ],
  fn() => [ codeblock( "services:\n  api:\n    environment:\n      - " . key_name() . '=' . ( $s = secret_value() ) ), $s ],
  fn() => [ codeblock( key_name() . ': "' . ( $s = secret_value() ) . '"' ), $s ],
  fn() => [ codeblock( "[mysqld]\nuser = " . user() . "\n" . pw_name() . ' = ' . ( $s = password() ) ), $s ],
  fn() => [ codeblock( "[client]\n" . pw_name() . '=' . ( $s = password() ) . "\nhost=" . host() ), $s ],
];
$POS['PHP array'] = [
  fn() => [ codeblock( "'" . pw_name() . "' => '" . ( $s = password() ) . "'," ), $s ],
  fn() => [ codeblock( '$config[\'' . strtolower( pw_name() ) . '\'] = "' . ( $s = password() ) . '";' ), $s ],
  fn() => [ codeblock( '$' . strtolower( key_name() ) . " = '" . ( $s = secret_value() ) . "';" ), $s ],
  fn() => [ codeblock( "array(\n  'host' => '" . host() . "',\n  'api_key' => '" . ( $s = secret_value() ) . "',\n)" ), $s ],
];
$POS['URL userinfo'] = [
  fn() => [ pick( [ 'mysql', 'postgres', 'redis', 'amqp', 'mongodb', 'https', 'ftp', 'smtp' ] ) . '://' . user() . ':' . ( $s = rawurlencode( password() ) ) . '@' . host() . '/' . pick( [ 'wp', 'app', 'main' ] ), $s ],
  fn() => [ codeblock( 'DATABASE_URL=postgres://' . user() . ':' . ( $s = alnum( 20 ) ) . '@' . host() . ':5432/app' ), $s ],
  fn() => [ around( 'git clone https://' . user() . ':' . ( $s = 'gh' . 'p_' . alnum( 36 ) ) . '@github.com/acme/site.git' ), $s ],
  fn() => [ 'REDIS_URL=redis://:' . ( $s = alnum( 24 ) ) . '@' . host() . ':6379/0', $s ],
  fn() => [ 'See "' . 'https://' . user() . ':' . ( $s = rawurlencode( password() ) ) . '@' . host() . '/dashboard" for the panel.', $s ],
];
$POS['vendor key in prose'] = [
  fn() => [ around( pick( [ 'My key is ', 'Use this one: ', 'I pasted the key ', 'Here it is - ', 'key=' ] ) . ( $s = vendor_key() ) ), $s ],
  fn() => [ 'Error: invalid credentials for ' . ( $s = vendor_key() ) . ' (401)', $s ],
  fn() => [ '<code>' . ( $s = vendor_key() ) . '</code>', $s ],
];
$POS['JWT'] = [
  fn() => [ around( 'Session token ' . ( $s = jwt() ) . ' expires soon.' ), $s ],
  fn() => [ 'Cookie: session=' . ( $s = jwt() ), $s ],
  fn() => [ 'jwt = ' . ( $s = jwt() ), $s ],
];
$POS['private key block'] = [
  fn() => [ codeblock( '-----BEGIN ' . pick( [ 'RSA ', 'EC ', 'OPENSSH ', '' ] ) . "PRIVATE KEY-----\n" . chunk_split( b64( 120 ), 64, "\n" ) . '-----END PRIVATE KEY-----' ), 'PEM' ],
];
$POS['log line'] = [
  fn() => [ '[' . date( 'Y-m-d H:i:s', mt_rand( 1.6e9, 1.8e9 ) ) . '] ERROR login failed user=' . user() . ' ' . pick( [ 'password', 'pwd', 'pass' ] ) . '=' . ( $s = alnum( 12 ) ), $s ],
  fn() => [ '127.0.0.1 - - [10/Oct/2024:13:55:36 +0000] "GET /wp-json/x/v1/sync?api_key=' . ( $s = secret_value() ) . ' HTTP/1.1" 200 512', $s ],
  fn() => [ 'DEBUG request headers: Authorization: Bearer ' . ( $s = secret_value() ), $s ],
  fn() => [ 'smtp: AUTH PLAIN failed for ' . user() . ' password: ' . ( $s = password() ), $s ],
  fn() => [ 'PHP Notice: connecting with ' . pw_name() . '=' . ( $s = password() ) . ' failed in /var/www/wp-config.php on line 31', $s ],
];
$POS['WordPress options and shortcodes'] = [
  fn() => [ '[acme_mailer host="' . host() . '" ' . pick( [ 'password', 'api_key', 'token' ] ) . '="' . ( $s = secret_value() ) . '"]', $s ],
  fn() => [ '<input type="hidden" name="api_key" value="' . ( $s = secret_value() ) . '">', $s ],
  fn() => [ '<script>var cfg = {apiKey: "' . ( $s = secret_value() ) . '", debug: false};</script>', $s ],
  fn() => [ '<script>window.__INIT__ = {"accessToken":"' . ( $s = secret_value() ) . '"}</script>', $s ],
  fn() => [ 'update_option( \'acme_' . pick( [ 'api_key', 'secret', 'token', 'password' ] ) . '\', \'' . ( $s = secret_value() ) . '\' );', $s ],
];

// ---- the defaults and look-alikes a placeholder list or a code-reference test used to excuse. A real
// default password (the one a product ships with) is a credential, and a string literal that merely
// begins like a lookup is data. These were seeded as ordinary text before, which is how the generator
// agreed with the defect it was written to find.
$POS['default passwords and lookalike literals'] = [
  fn() => [ codeblock( "define( 'DB_PASSWORD', '" . ( $s = pick( [ 'password', 'secret', 'changeme', 'token', 'admin123', 'root' ] ) ) . "' );" ), $s ],
  fn() => [ codeblock( pick( [ 'JWT_SECRET', 'SESSION_SECRET', 'APP_SECRET' ] ) . "='" . ( $s = pick( [ 'secret', 'changeme', 'password' ] ) ) . "'" ), $s ],
  fn() => [ '{"username":"svc","password":"' . ( $s = pick( [ 'changeme', 'password', 'secret', 'admin' ] ) ) . '"}', $s ],
  fn() => [ 'curl -u elastic:' . ( $s = pick( [ 'changeme', 'password', 'elastic' . mt_rand( 1, 9 ) ] ) ) . ' https://localhost:9200/_cluster/health', $s ],
  fn() => [ '{"password": "' . ( $s = pick( [ 'this', 'self', 'config', 'env', 'options' ] ) . '.' . pick( [ 'is.my.', 'Kx', 'Made#' ] ) . mt_rand( 10, 9999 ) ) . '"}', $s ],
  fn() => [ codeblock( "define( 'DB_PASSWORD', '" . ( $s = pick( [ 'Self', 'Config', 'Env[', 'This' ] ) . pick( [ '.', '::', '' ] ) . alnum( 8 ) . '!' ) . "' );" ), $s ],
  fn() => [ 'DB_PASSWORD=' . ( $s = pick( [ 'this.', 'self.', 'config.', 'env.' ] ) . alnum( 8 ) . '!' ), $s ],
  fn() => [ 'redis://:' . ( $s = alnum( 6 ) . ':' . alnum( 8 ) ) . '@' . host() . ':6379', $s ],
];
$POS['inputs and curl forms that carry a value'] = [
  fn() => [ '<input type="password" name="password" value="' . ( $s = pick( [ 'correct horse', 'blue train', 'my long phrase' ] ) . ' ' . mt_rand( 10, 99 ) ) . '">', $s ],
  fn() => [ '<input type="password" name="password" value="' . ( $s = alnum( 4 ) . '>' . alnum( 5 ) . '!' ) . '">', $s ],
  fn() => [ '<input class="' . str_repeat( 'w-full ', mt_rand( 140, 200 ) ) . '" type="password" name="password" value="' . ( $s = alnum( 10 ) ) . '">', $s ],
  fn() => [ '<input type="' . pick( [ 'checkbox', 'image', 'radio' ] ) . '" name="' . pick( [ 'remember_token', 'api_key', 'auth_token' ] ) . '" value="' . ( $s = alnum( mt_rand( 12, 24 ) ) ) . '">', $s ],
  fn() => [ 'curl -u' . user() . ':' . ( $s = password() ) . ' https://api.example.com/v1/items', $s ],
  fn() => [ 'curl -u ' . ( $s = alnum( 20 ) ) . pick( [ ':X', ':', ':x' ] ) . ' https://acme.example.com/api/v2/tickets', $s ],
  fn() => [ 'curl -u ' . user() . ':' . ( $s = ucfirst( chars( 'abcdefghijkmnpqrstuvwxyz', 8 ) ) ) . ' https://api.example.com/', $s ],
];

// ---- credentials in layouts that are known not to be recognised. They are measured so the gap is a
// number, not a guess. A class in KNOWN_RESIDUAL is expected to miss; --assert fails if it stops.
$POS['RESIDUAL bare secret'] = [
  fn() => [ around( 'It is ' . ( $s = alnum( 32 ) ) . '.' ), $s ],
  fn() => [ around( 'The code ' . ( $s = b64( 40 ) ) . ' unlocks it' ), $s ],
];
$POS['RESIDUAL password said in a sentence'] = [
  fn() => [ around( 'The password is ' . ( $s = password() ) . ' so please change it.' ), $s ],
  fn() => [ around( 'Use ' . ( $s = password() ) . ' as the password.' ), $s ],
  fn() => [ around( 'Login with ' . user() . ' / ' . ( $s = password() ) ), $s ],
];
$POS['RESIDUAL value split from its name'] = [
  fn() => [ '<input type="password" name="pw" value="' . ( $s = password() ) . '">', $s ],
  fn() => [ '<label>Password</label><input value="' . ( $s = password() ) . '">', $s ],
  fn() => [ "| Field | Value |\n| --- | --- |\n| user | " . user() . " |\n| password | " . ( $s = password() ) . ' |', $s ],
  fn() => [ "Username: " . user() . "\nPassword: " . ( $s = password() . ' ' . chars( 'abcdefgh', 4 ) ), $s ],
];
$POS['RESIDUAL short or wordlike unquoted password'] = [
  fn() => [ 'password=' . ( $s = pick( [ 'letmein', 'admin', 'secret', 'qwerty', 'dragon', 'monkey' ] ) ), $s ],
  fn() => [ 'DB_PASSWORD: ' . ( $s = pick( [ 'root', 'toor', 'mysql', 'wordpress' ] ) ), $s ],
];
$POS['RESIDUAL hyphenated words under a bare pass'] = [
  // "Hall pass=2nd-floor" is prose, and a password of short words and numbers joined by hyphens has the same shape.
  fn() => [ 'login failed user=' . user() . ' pass=' . ( $s = chars( 'abcdefghijkmnpqrstuvwxyz', 4 ) . '-' . chars( 'abcdefghijkmnpqrstuvwxyz', 4 ) . '-' . mt_rand( 1000, 9999 ) ), $s ],
];
$POS['RESIDUAL base64 or hex under an ordinary name'] = [
  fn() => [ 'signature=' . ( $s = b64( 44 ) ), $s ],
  fn() => [ 'nonce_value: ' . ( $s = hex( 32 ) ), $s ],
  fn() => [ 'hmac = ' . ( $s = hex( 64 ) ), $s ],
];

// ---- credential shapes that a name list or a prefix list can reach, measured as a separate group
// so the fix for each is visible. Whether each is in the "found" or the "residual" group is decided
// by the measurement, not by the author.
$POS['other vendor prefixes'] = [
  fn() => [ around( 'token ' . ( $s = other_vendor_key() ) . ' leaked' ), $s ],
  fn() => [ '<code>' . ( $s = other_vendor_key() ) . '</code>', $s ],
  fn() => [ 'Authorization header was ' . ( $s = other_vendor_key() ), $s ],
];
$POS['webhook and connection strings'] = [
  fn() => [ 'https://hooks.' . 'slack.com/services/T' . chars( 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789', 8 ) . '/B' . chars( 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789', 10 ) . '/' . ( $s = alnum( 24 ) ), $s ],
  fn() => [ 'https://discord' . '.com/api/webhooks/' . mt_rand( 1e17, 9e17 ) . '/' . ( $s = b64url( 68 ) ), $s ],
  fn() => [ 'DefaultEndpointsProtocol=https;AccountName=acme;AccountKey=' . ( $s = b64( 64 ) ) . ';EndpointSuffix=core.windows.net', $s ],
  fn() => [ 'Server=' . host() . ';Database=app;User Id=' . user() . ';Password=' . ( $s = password() ) . ';', $s ],
  fn() => [ 'jdbc:mysql://' . host() . ':3306/app?user=' . user() . '&password=' . ( $s = password() ), $s ],
];
$POS['unusual names'] = [
  fn() => [ pick( [ 'MYSQL_ROOT_PW', 'wifi_pw', 'DB_PW' ] ) . '=' . ( $s = password() ), $s ],
  fn() => [ pick( [ 'SECRET_KEY_BASE', 'secret_key_base' ] ) . '=' . ( $s = hex( 64 ) ), $s ],
  fn() => [ pick( [ 'AZURE_STORAGE_KEY', 'STRIPE_KEY', 'OPENAI_KEY', 'DEPLOY_KEY', 'SSH_KEY', 'JWT_KEY' ] ) . '=' . ( $s = secret_value() ), $s ],
  fn() => [ pick( [ 'client_secret', 'consumer_secret' ] ) . ' = ' . ( $s = b64url( 40 ) ), $s ],
  fn() => [ pick( [ 'auth_code', 'oauth_token_secret', 'bearer_token', 'session_token', 'csrf_token' ] ) . ': ' . ( $s = alnum( 32 ) ), $s ],
  fn() => [ pick( [ 'Cookie', 'Set-Cookie' ] ) . ': ' . pick( [ 'PHPSESSID', 'sessionid', 'wordpress_logged_in_' . hex( 8 ) ] ) . '=' . ( $s = alnum( 32 ) ) . '; path=/; HttpOnly', $s ],
  fn() => [ 'Authorization: ' . pick( [ 'ApiKey', 'Hawk', 'Negotiate', 'NTLM', 'Bot', 'OAuth' ] ) . ' ' . ( $s = b64url( 36 ) ), $s ],
  fn() => [ 'X-Auth-Token: ' . ( $s = alnum( 40 ) ), $s ],
  fn() => [ 'password : ' . ( $s = password() ), $s ],
  fn() => [ 'PASSWORD => ' . "'" . ( $s = password() ) . "'", $s ],
];

// ---- ordinary text that must not be flagged
$NEG['prose about passwords and keys'] = [
  fn() => [ around( pick( [ 'Choose a strong password of at least twelve characters and never reuse it.', 'Your password must contain a number, a capital letter and a symbol.', 'Forgot your password? Use the reset link and we will email you a new one.', 'Never share your API key or token with anyone, and rotate your secret key every quarter.', 'A password manager stores every password for you so you only remember one master passphrase.', 'The secret to good bread is patience, and the key is a hot oven.', 'Two-factor authentication adds a second step after the password.', 'Tokens expire after an hour; request a new access token when the old one is rejected.', 'Passwords are hashed with bcrypt, so we cannot read them.', 'Keep your license key safe: you need it to receive updates.' ] ) ) ],
  fn() => [ '<p>' . pick( [ 'Password', 'Username', 'API key', 'Secret', 'Token', 'Passphrase', 'Email' ] ) . ': ' . pick( [ 'required', 'optional', 'none', 'hidden', 'see below', 'not stored', 'your choice', 'case sensitive', 'min 8 characters', 'n/a', 'sent by email', 'ask support' ] ) . '</p>' ],
  fn() => [ around( 'Enter your password: ' . pick( [ '********', '••••••••', '(hidden)', '[redacted]', 'xxxxxxxx' ] ) ) ],
  fn() => [ around( pick( [ 'Password: **hidden**', 'Password: *required*', '**Password**: required', 'API key: ~~revoked~~', 'Secret: <strong>hidden</strong>', 'Token: _not shown_', 'Password: @username', 'Password: #1 priority', 'Passphrase: &mdash; none &mdash;' ] ) ) ],
  fn() => [ around( 'Reset steps: 1) open ' . pick( [ '/wp-login.php?action=lostpassword', '/my-account/lost-password/' ] ) . ' 2) enter your email 3) check your inbox for the reset key.' ) ],
  fn() => [ '<label for="pass1">New password</label><input type="password" name="pass1" id="pass1" autocomplete="new-password">' ],
  fn() => [ '<!-- wp:paragraph {"className":"password-strength","metadata":{"name":"Secret santa"}} --><p>Join our secret santa: the key dates are below.</p><!-- /wp:paragraph -->' ],
  fn() => [ around( 'Key takeaways: ' . words( 8 ) . '. Secret ingredient: ' . pick( [ 'smoked paprika', 'love', 'lemon zest' ] ) . '. Token gesture: ' . words( 3 ) . '.' ) ],
  fn() => [ around( 'Our ' . pick( [ 'loyalty token', 'gift token', 'arcade token', 'season pass', 'day pass', 'boarding pass', 'hall pass' ] ) . ' scheme: ' . pick( [ 'valid 10am-6pm', 'expires 2025-12-31', '1st-come-first-served', 'one per household', '3-for-2 on Fridays' ] ) . '.' ) ],
  fn() => [ pick( [ 'Pass: 10am-6pm', 'Day pass: 10am-6pm daily', 'Season Pass: 2024-2025', 'Hall pass=2nd-floor', 'Token: 1st-come-first-served', 'Token: v2.1.0', 'Secret: sauce', 'Key: 3 bedrooms' ] ) ],
  fn() => [ around( 'The keyboard shortcut is Ctrl+K; the passage on page 4 mentions the bypass road, and the passport office opens at 9.' ) ],
  fn() => [ around( 'Authorization: required for all admin screens. Authentication failed? Check the keywords field and the author id.' ) ],
  fn() => [ 'Basic HTML/CSS for beginners. Course 1: Basic ' . pick( [ 'JavaScript', 'PHP basics', 'WordPress', '2024/2025 membership' ] ) . '.' ],
  fn() => [ 'The ring Bearer ' . pick( [ '4-year-old nephew', 'walked slowly', 'was late' ] ) . ' at the wedding.' ],
  fn() => [ around( 'Terms: by signing in you accept that the password is yours alone and the secret questions must be truthful.' ) ],
  fn() => [ 'Reading time: 5 min. Level: ' . pick( [ 'Beginner', 'Advanced' ] ) . '. Tokens used: ' . mt_rand( 100, 9000 ) . '. Passes: ' . mt_rand( 1, 9 ) . '.' ],
  fn() => [ around( 'Use the hotkey: key=value pairs are separated by & signs, e.g. color=blue&size=large.' ) ],
];
$NEG['code that names a secret but holds none'] = [
  fn() => [ codeblock( '$password = $_POST[\'password\'];' ) ],
  fn() => [ codeblock( '$token = wp_create_nonce( \'my_action\' );' ) ],
  fn() => [ codeblock( "define( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );" ) ],
  fn() => [ codeblock( "define( 'DB_PASSWORD', 'password_here' );" ) ],
  fn() => [ codeblock( "define( 'AUTH_KEY', 'put your unique phrase here' );" ) ],
  fn() => [ codeblock( "'api_key' => get_option( 'acme_api_key' )," ) ],
  fn() => [ codeblock( '$api_key = sanitize_text_field( $_POST[\'api_key\'] );' ) ],
  fn() => [ codeblock( 'const apiKey = process.env.API_KEY;' ) ],
  fn() => [ codeblock( 'secret = os.environ["SECRET_KEY"]' ) ],
  fn() => [ codeblock( 'password: ${{ secrets.DB_PASSWORD }}' ) ],
  fn() => [ codeblock( 'password: "{{ vault_db_password }}"' ) ],
  fn() => [ codeblock( '"password": null, "token": "", "secret": false' ) ],
  fn() => [ codeblock( 'token: undefined' ) ],
  fn() => [ codeblock( pw_name() . '=' ) ],
  fn() => [ codeblock( key_name() . '=<' . pick( [ 'your key', 'token', 'api-key' ] ) . '>' ) ],
  fn() => [ codeblock( key_name() . '=' . pick( [ 'YOUR_API_KEY', 'your-api-key-here', 'CHANGE_ME', 'YourApiKey', 'xxxxxxxxxxxx', '********', '${' . strtoupper( key_name() ) . '}', '$API_KEY', '{{ api_key }}' ] ) ) ],
  fn() => [ codeblock( "curl -H \"Authorization: Bearer \$TOKEN\" https://api.example.com/me" ) ],
  fn() => [ codeblock( "curl -H 'Authorization: Bearer <your token>' https://api.example.com/me" ) ],
  fn() => [ codeblock( 'curl -u user:pass https://example.com/' ) ],
  fn() => [ codeblock( 'mysql -u root -p' ) ],
  fn() => [ codeblock( 'wp user update admin --user_pass=' . pick( [ '<new-password>', 'NEW_PASSWORD', '"$PASS"' ] ) ) ],
  fn() => [ codeblock( '<input type="password" name="pwd" id="user_pass" class="input" value="" size="20" autocomplete="current-password" />' ) ],
  fn() => [ codeblock( 'wp_set_password( $new_password, $user_id );' ) ],
  fn() => [ codeblock( 'if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) { return new WP_Error( \'bad_password\' ); }' ) ],
  fn() => [ codeblock( '$headers[\'Authorization\'] = \'Bearer \' . $token;' ) ],
  fn() => [ codeblock( "register_setting( 'acme', 'acme_api_key', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );" ) ],
  fn() => [ codeblock( "git clone git@github.com:acme/site.git && ssh -i ~/.ssh/id_ed25519 deploy@" . host() ) ],
  fn() => [ codeblock( 'https://example.com:8443/path?x=1' ) ],
  fn() => [ codeblock( 'DB_HOST=' . host() . "\nDB_USER=" . user() . "\nDB_NAME=wp\nAPP_ENV=production\nCACHE_TTL=3600" ) ],
  fn() => [ '<!-- wp:html --><script async src="https://www.googletagmanager.com/gtag/js?id=G-' . strtoupper( alnum( 10 ) ) . '"></script><!-- /wp:html -->' ],
  fn() => [ '<div class="g-recaptcha" data-sitekey="6Le' . b64url( 37 ) . '"></div>' ],
  fn() => [ 'Publishable key: pk_' . 'live_' . alnum( 24 ) ],
  fn() => [ 'See https://' . pick( [ 'user' ] ) . ':' . pick( [ 'pass', 'password' ] ) . '@example.com/ for the format of a URL with credentials.' ],
];
$NEG['forms, flags and setters that hold no secret'] = [
  fn() => [ '<input type="submit" name="reset_password" value="Reset Password">' ],
  fn() => [ '<input type="submit" name="' . pick( [ 'login_password', 'save_api_key', 'regenerate_token' ] ) . '" value="' . pick( [ 'Save changes', 'Generate', 'Regenerate-key' ] ) . '" class="button">' ],
  fn() => [ '<input type="text" name="api_key" placeholder="Enter your API key" value="">' ],
  fn() => [ '<input type="password" name="pwd" id="user_pass" class="input" value="" size="20" autocomplete="current-password" />' ],
  fn() => [ '<input type="checkbox" name="show_password" value="1" checked> Show password' ],
  fn() => [ '<input type="hidden" name="_wpnonce" value="' . hex( 10 ) . '"><input type="hidden" name="action" value="update">' ],
  fn() => [ '<input type="password" name="password" value="••••••••">' ],
  fn() => [ '<input type="text" name="secret" value="Not set">' ],
  fn() => [ 'docker run -u 1000:1000 ' . pick( [ 'nginx', 'php:8.2-fpm' ] ) . ' && useradd -u 1001 deploy' ],
  fn() => [ pick( [ 'docker run --user www-data:www-data php:8.2-fpm', 'podman run --user nobody:nogroup alpine', 'chown -R 1000:1000 /var/www && sudo -u www-data:www-data wp cache flush' ] ) ],
  fn() => [ 'curl --user admin:$WP_PASSWORD https://example.com/wp-json/ && curl -u "$USER:$PASS" https://example.com/' ],
  fn() => [ codeblock( "update_option( 'acme_api_key', '' );" ) ],
  fn() => [ codeblock( "update_option( 'acme_" . pick( [ 'token', 'secret', 'api_key' ] ) . "', \$value );" ) ],
  fn() => [ codeblock( "update_option( 'acme_secret_enabled', 'yes' ); set_transient( 'acme_token_cache', \$data, HOUR_IN_SECONDS );" ) ],
  fn() => [ codeblock( "update_option( 'acme_api_key', '" . pick( [ 'none', 'required', 'null', 'CHANGE_ME' ] ) . "' );" ) ],
  fn() => [ codeblock( '"password": "' . pick( [ 'required', 'optional', 'hidden', 'redacted' ] ) . '"' ) ],
  fn() => [ codeblock( "password: " . pick( [ '<password>', '{{ password }}', '[hidden]', 'null', '~' ] ) ) ],
  fn() => [ codeblock( "secret_key_base: <%= ENV[\"SECRET_KEY_BASE\"] %>" ) ],
  fn() => [ around( 'Mind the pass: pass=1 for the first lap, pass=true to skip, and ?pass=false to retry. The hall pass=2nd-floor stays.' ) ],
  fn() => [ around( 'Session id: ' . mt_rand( 1000, 9999 ) . ' for the booking, sessionId 1 of 3, Cookie: consent=accepted; theme=dark; lang=en' ) ],
];
$NEG['names that end in key, pass or pw but hold a name, a path or a label'] = [
  fn() => [ codeblock( "const STORAGE_KEY = 'todos-vuejs-" . mt_rand( 1, 3 ) . ".0'" ) ],
  fn() => [ codeblock( 'self.primary_key = "' . pick( [ 'product_code', 'order_id', 'sku' ] ) . '"' ) ],
  fn() => [ '{"table": "orders", "primary_key": "' . pick( [ 'order_id', 'id', 'uuid' ] ) . '"}' ],
  fn() => [ codeblock( "const SESSION_KEY = '" . pick( [ 'user', 'cart', 'locale' ] ) . "'" ) ],
  fn() => [ codeblock( "'" . pick( [ 'root_key', 'service_key', 'shared_key', 'secondary_key' ] ) . "' => '" . pick( [ 'menu', 'billing', 'none yet', 'order_id' ] ) . "'," ) ],
  fn() => [ codeblock( 'primary_key: ' . pick( [ 'order_id_2', 'product_code', 'id' ] ) ) ],
  fn() => [ codeblock( 'ssh_key: "~/.ssh/id_' . pick( [ 'ed25519', 'rsa' ] ) . '.pub"' ) ],
  fn() => [ 'PWD=/var/www/html/wp-content' ],
  fn() => [ around( 'Buy here: https://park.example.com/buy?pass=' . pick( [ 'annual', 'season', 'summer' ] ) . mt_rand( 2020, 2029 ) ) ],
  fn() => [ 'Username: admin, Password: ' . pick( [ 'YourPassword', 'YourApiKey', 'JavaScript', 'WordPress', 'TypeScript' ] ) ],
  fn() => [ '{"pw": "' . pick( [ 'full', 'half', 'auto' ] ) . '"}' ],
  fn() => [ pick( [ 'docker run --user deploy:www-data2 php', 'docker run -u 1000:docker1 image', 'sudo -u www-data:wwwdata2 wp cache flush', 'chown --user deploy:staff2 /srv' ] ) ],
  fn() => [ '{"session_id": "' . pick( [ 'abcd', 'main', 'guest' ] ) . '"}' ],
  fn() => [ '<input type="checkbox" name="remember_token" value="' . pick( [ 'remember', 'yes', '1' ] ) . '" checked>' ],
];
$NEG['hashes, ids and other long strings that are not secrets'] = [
  fn() => [ around( 'Fixed in commit ' . hex( 40 ) . ' (see the changelog).' ) ],
  fn() => [ around( 'Order ' . mt_rand( 10000, 99999 ) . ' tracking ' . strtoupper( alnum( 18 ) ) . ' shipped on ' . date( 'Y-m-d', mt_rand( 1.6e9, 1.8e9 ) ) . '.' ) ],
  fn() => [ around( 'Checksum (sha256): ' . hex( 64 ) ) ],
  fn() => [ around( 'Session id ' . sprintf( '%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand() ) . ' was created' ) ],
  fn() => [ '<img src="https://cdn.example.com/uploads/2024/05/' . alnum( 24 ) . '.jpg" alt="' . words( 3 ) . '" width="800" height="600">' ],
  fn() => [ '<a href="https://example.com/?utm_source=newsletter&utm_medium=email&utm_campaign=' . words( 1 ) . '&ref=' . alnum( 10 ) . '">' . words( 3 ) . '</a>' ],
  fn() => [ '<!-- wp:image {"id":' . mt_rand( 10, 9999 ) . ',"sizeSlug":"large","linkDestination":"none"} -->' ],
  fn() => [ '<iframe src="https://www.youtube.com/embed/' . b64url( 11 ) . '?rel=0" width="560" height="315"></iframe>' ],
  fn() => [ '<!-- wp:block {"ref":' . mt_rand( 10, 9999 ) . '} /-->' ],
  fn() => [ 'Tracking ID: UA-' . mt_rand( 10000, 99999 ) . '-' . mt_rand( 1, 9 ) . ', GTM-' . strtoupper( chars( 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789', 7 ) ) ],
  fn() => [ '{"id":' . mt_rand( 10, 9999 ) . ',"title":"' . words( 3 ) . '","slug":"' . str_replace( ' ', '-', words( 3 ) ) . '","authorId":' . mt_rand( 1, 20 ) . ',"keywords":"' . words( 4 ) . '"}' ],
  fn() => [ 'Order number: ' . mt_rand( 100000, 999999 ) . ' - key: ' . alnum( 6 ) ],
];
$NEG['ordinary post bodies'] = [
  fn() => [ '<!-- wp:heading --><h2>' . ucfirst( words( 4 ) ) . '</h2><!-- /wp:heading -->' . around( sentence() . ' ' . sentence() . ' ' . sentence() ) ],
  fn() => [ "<!-- wp:list -->\n<ul><li>" . words( 4 ) . '</li><li>' . words( 5 ) . '</li><li>' . words( 3 ) . "</li></ul>\n<!-- /wp:list -->" ],
  fn() => [ '<blockquote><p>' . sentence() . '</p><cite>' . words( 2 ) . '</cite></blockquote>' ],
  fn() => [ '<p>Contact: <a href="mailto:info@example.com">info@example.com</a>, phone 555-0' . mt_rand( 100, 199 ) . ', open Mon-Fri 9:00-17:30.</p>' ],
  fn() => [ '<table><tr><th>Plan</th><th>Price</th></tr><tr><td>Basic</td><td>$9/mo</td></tr><tr><td>Pro</td><td>$29/mo</td></tr></table>' ],
  fn() => [ around( 'Ingredients: 2 cups flour, 1 tsp salt, 3 eggs. Bake at 180C for 25-30 minutes. Serves 4: ' . words( 5 ) . '.' ) ],
  fn() => [ around( 'Version 6.' . mt_rand( 0, 9 ) . '.' . mt_rand( 0, 9 ) . ' requires PHP 7.4+ and MySQL 5.7 or MariaDB 10.4. Update at: Dashboard: Updates.' ) ],
  fn() => [ '[gallery ids="' . mt_rand( 10, 99 ) . ',' . mt_rand( 100, 199 ) . '" columns="3" link="file"] [contact-form-7 id="' . mt_rand( 100, 999 ) . '" title="Contact"]' ],
  fn() => [ around( 'Meeting at 14:30: agenda: budget: approve; staffing: hire two; keys: return office keys to reception.' ) ],
  fn() => [ around( 'Q&A: How do I log in? Click Login, type your email address and the password you chose when you registered.' ) ],
  fn() => [ "Subject: Re: invoice " . mt_rand( 1000, 9999 ) . "\nFrom: billing@example.com\nTo: " . user() . "@example.org\n\nHi, the PO number is " . mt_rand( 10000, 99999 ) . ". Thanks!" ],
];

/* ---------------------------------------------------------------------------------------------
 * What is expected to be missed, by class. A class listed here has been measured to miss, and
 * the document says so. --assert fails when one of them stops missing (move it out) or when a
 * class not listed here misses at all.
 * ------------------------------------------------------------------------------------------- */
const KNOWN_RESIDUAL = [
  'RESIDUAL bare secret',
  'RESIDUAL password said in a sentence',
  'RESIDUAL value split from its name',
  'RESIDUAL short or wordlike unquoted password',
  'RESIDUAL base64 or hex under an ordinary name',
  'RESIDUAL hyphenated words under a bare pass',
];

/* ---------------------------------------------------------------------------------------------
 * Run
 * ------------------------------------------------------------------------------------------- */
$corpus = [];
foreach ( $POS as $class => $templates ) {
  foreach ( $templates as $ti => $t ) {
    for ( $i = 0; $i < $opt['per']; $i++ ) {
      [ $text, $secret ] = $t();
      $corpus[] = [ 'kind' => 'secret', 'class' => $class, 'tpl' => $ti, 'text' => $text, 'secret' => $secret ];
    }
  }
}
foreach ( $NEG as $class => $templates ) {
  foreach ( $templates as $ti => $t ) {
    for ( $i = 0; $i < $opt['per']; $i++ ) {
      $r = $t();
      $corpus[] = [ 'kind' => 'prose', 'class' => $class, 'tpl' => $ti, 'text' => $r[0], 'secret' => null ];
    }
  }
}
if ( $opt['dump'] !== '' ) {
  file_put_contents( $opt['dump'], implode( "\n", array_map( 'json_encode', $corpus ) ) . "\n" );
}

$stats = [];
$bad = [];
$t0 = microtime( true );
foreach ( $corpus as $s ) {
  $c = $s['class'];
  $stats[ $c ] ??= [ 'kind' => $s['kind'], 'n' => 0, 'fail' => 0, 'span' => 0 ];
  $stats[ $c ]['n']++;
  $held = GMCP_Credential_Scan::text_holds( $s['text'] );
  $failed = false;
  if ( $s['kind'] === 'prose' ) {
    $failed = $held;
  }
  elseif ( !$held ) {
    $failed = true;
  }
  elseif ( $s['secret'] !== 'PEM' ) {
    // Found, but is the secret itself inside the blanked ranges? The audit log keeps the rest.
    // Counted, not searched for: a secret that is a word the sample also uses elsewhere ("password":
    // "password") is covered when it occurs one time fewer afterwards.
    $left = GMCP_Credential_Scan::redact_spans( $s['text'], '[x]' );
    if ( substr_count( $left, $s['secret'] ) >= substr_count( $s['text'], $s['secret'] ) ) {
      $stats[ $c ]['span']++;
      $failed = true;
    }
  }
  if ( $failed ) {
    $stats[ $c ]['fail']++;
    $bad[ $c ][ $s['tpl'] ] = $bad[ $c ][ $s['tpl'] ] ?? [ 'n' => 0, 'text' => $s['text'] ];
    $bad[ $c ][ $s['tpl'] ]['n']++;
  }
}
$ms = ( microtime( true ) - $t0 ) * 1000;

$secrets = $prose = $miss = $alarm = 0;
$unexpected = [];
$stale = [];
printf( "corpus: %d samples (seed %d, %d per template), scanned in %.0f ms\n\n", count( $corpus ), $opt['seed'], $opt['per'], $ms );
printf( "%-9s %-52s %6s %6s\n", 'kind', 'class', 'n', 'wrong' );
foreach ( $stats as $class => $st ) {
  printf( "%-9s %-52s %6d %6d%s\n", $st['kind'], $class, $st['n'], $st['fail'], $st['span'] ? "  ({$st['span']} found but not covered)" : '' );
  if ( $st['kind'] === 'secret' ) {
    $secrets += $st['n'];
    $miss += $st['fail'];
    if ( $st['fail'] && !in_array( $class, KNOWN_RESIDUAL, true ) ) {
      $unexpected[] = $class;
    }
    if ( !$st['fail'] && in_array( $class, KNOWN_RESIDUAL, true ) ) {
      $stale[] = $class;
    }
  }
  else {
    $prose += $st['n'];
    $alarm += $st['fail'];
    if ( $st['fail'] ) {
      $unexpected[] = $class;
    }
  }
}
printf( "\ncredentials: %d, missed %d (%.1f%%)   prose: %d, flagged %d (%.2f%%)\n", $secrets, $miss, $secrets ? 100 * $miss / $secrets : 0, $prose, $alarm, $prose ? 100 * $alarm / $prose : 0 );
$resid_miss = 0;
foreach ( $stats as $class => $st ) {
  if ( $st['kind'] === 'secret' && in_array( $class, KNOWN_RESIDUAL, true ) ) {
    $resid_miss += $st['fail'];
  }
}
printf( "of the misses, %d are in classes documented as residual; %d are not\n", $resid_miss, $miss - $resid_miss );

if ( $opt['show'] > 0 ) {
  foreach ( $bad as $class => $byTpl ) {
    if ( $opt['show'] < 100 && strpos( $class, 'RESIDUAL' ) === 0 ) {
      continue;
    }
    echo "\n-- $class --\n";
    foreach ( $byTpl as $tpl => $one ) {
      $text = strlen( $one['text'] ) > 220 ? '...' . substr( $one['text'], (int) max( 0, strlen( $one['text'] ) / 2 - 100 ), 200 ) . '...' : $one['text'];
      echo "   [template $tpl: {$one['n']} of {$opt['per']}] " . json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    }
  }
}
if ( $opt['assert'] ) {
  $problems = [];
  foreach ( $unexpected as $c ) {
    $problems[] = "unexpected failures in: $c";
  }
  foreach ( $stale as $c ) {
    $problems[] = "documented as residual but fully found now: $c";
  }
  if ( $problems ) {
    echo "\nASSERT FAILED\n  " . implode( "\n  ", $problems ) . "\n";
    exit( 1 );
  }
  echo "\nASSERT OK\n";
}
