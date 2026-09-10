# Throwaway test site

A disposable WordPress for smoke-testing the plugin. Nothing here ships.

```
cd .dev
docker compose up -d

# Wait for the wp container to unpack WordPress, then:
docker compose exec -T cli wp core install \
  --url=http://localhost:8080 --title="MCP Test" \
  --admin_user=admin --admin_password=admin \
  --admin_email=a@b.test --skip-email

docker compose exec -T cli wp rewrite structure '/%postname%/' --hard
docker compose exec -T cli wp plugin activate guarded-mcp
```

The repository is bind-mounted as the plugin directory, so edits apply immediately.

Set a token and call the server:

```
docker compose exec -T cli wp eval '
  $o = get_option("gmcp_options", []);
  $o["mcp_bearer_token"] = "testtoken1234567890";
  update_option("gmcp_options", $o, false);
'

curl -sS -X POST 'http://localhost:8080/wp-json/mcp/v1/http' \
  -H 'Authorization: Bearer testtoken1234567890' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Run the suites:

```
./smoke.sh          # transport, auth, content tools, prompts, resources, previews
./smoke-admin.sh    # administration tools and every guard; rebuilds .htaccess, destructive

docker compose exec -T cli wp plugin install woocommerce --activate
./smoke-woo.sh      # the shop tools; needs WooCommerce
```

Never run `wp plugin install --force` against this stack. The plugin directory is a bind
mount of the repository, and WordPress deletes the old plugin directory before unpacking
the new one. The delete goes straight through the mount and takes the source tree, `.git`
included. To test a built package, extract it to a different directory name instead.

## Diagnosing a client that cannot auto-configure

When a client reports something like "couldn't determine the server settings", OAuth
discovery failed and the client will not say which step. `./diagnose-connector.sh
https://example.com` walks the same steps in order and stops at the first break. It is
read-only and sends no credentials.

## Building an installable zip

```
./build.sh
```

Writes `tmp/guarded-mcp-<version>.zip`, built from the current commit rather than from
the directory, so an untracked scratch file cannot end up inside a plugin somebody
installs. It refuses to run with uncommitted changes; `./build.sh --dirty` overrides that
and marks the filename. Every PHP file in the package is parsed before the zip is
written, and the build fails if `CREDITS.md` is missing or no longer names the upstream
author, because that file carries the attribution GPLv2 requires.

Never install the result over the bind-mounted plugin directory of this stack. See the
warning above.

Tear down. `down` on its own keeps the site; add `-v` to destroy the database and start
from nothing:

```
docker compose down       # stop, keep the site
docker compose down -v    # stop and wipe
```
