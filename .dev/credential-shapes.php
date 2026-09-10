<?php
/**
* Seeds four options, each holding a credential in a shape the journal has to recognise.
*
* A file rather than an inline wp eval: nested array literals do not survive the quoting
* through docker exec, and a seed that silently fails to parse makes every check that
* follows it pass for the wrong reason. That happened while writing this.
*
* Used by smoke-admin.sh. Not shipped.
*/
update_option( 'probe_obj', (object) [ 'secret_key' => 'OBJ_LEAK_1' ] );
update_option( 'probe_json', '{"secret_key":"JSON_LEAK_2"}' );
update_option( 'probe_smtp', [ 'smtp' => [ 'pass' => 'SMTP_LEAK_3' ] ] );
$deep = [ 'api_key' => 'DEEP_LEAK_4' ];
for ( $i = 0; $i < 7; $i++ ) {
  $deep = [ 'level' . $i => $deep ];
}
update_option( 'probe_deep', $deep );
// Ordinary settings, to catch the opposite failure: a check so broad that nothing is
// journalled would pass every leak test and make the feature useless.
update_option( 'probe_plain', [ 'mode' => 'live', 'enabled' => true, 'currency' => 'USD' ] );
echo 'seeded';
