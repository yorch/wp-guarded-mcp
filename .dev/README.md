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
docker compose exec -T cli wp plugin activate reeve
```

The repository is bind-mounted as the plugin directory, so edits apply immediately.

Set a token and call the server:

```
docker compose exec -T cli wp eval '
  $o = get_option("reeve_options", []);
  $o["mcp_bearer_token"] = "testtoken1234567890";
  update_option("reeve_options", $o, false);
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

Tear down, including the database:

```
docker compose down -v
```
