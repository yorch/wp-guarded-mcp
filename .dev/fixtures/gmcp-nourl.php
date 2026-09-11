<?php
/**
* Test fixture, copied into wp-content/mu-plugins by smoke.sh and removed again.
*
* It lives in a file rather than being written inline by the suite because the inline
* version needed a heredoc inside a single-quoted sh -c inside a docker exec, and the
* terminator did not survive that. The rest of smoke.sh was written into this path, PHP
* refused to parse it, and every request for the remainder of the run returned 500.
*/
add_filter( 'gmcp_url_token_route', '__return_false' );
