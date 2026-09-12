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

Make a key and call the server. There is no shared token to set any more, and a key is
shown once, so capture it when you create it:

```
KEY=$(docker compose exec -T cli wp eval '
  $a = get_users( [ "role" => "administrator", "number" => 1, "orderby" => "ID", "order" => "ASC" ] );
  $k = GMCP_Tokens::create( "local", "admin", 0, [], $a ? $a[0]->ID : 0 );
  echo $k["secret"];
' | tr -d '\r\n')

curl -sS -X POST 'http://localhost:8080/wp-json/mcp/v1/http' \
  -H "Authorization: Bearer $KEY" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

The suites do not need this: each one mints its own key under the label `smoke suite` and
asserts the shape of what it got back, so a failure to create one stops the run instead of
producing a hundred 401s that read as a hundred broken features.

A key acts as the administrator who created it and stops working when that account stops
holding `manage_options`, which is worth remembering on a test site where the suites
create and delete users.

Run the suites, one at a time against a stack. Each switches on the tool group it tests,
so there is no setting to turn on first; only the plugins below have to be installed.

```
./smoke.sh          # transport, auth, content tools, prompts, resources, previews

# smoke-admin.sh drives both backup adapters, so it needs both plugins present. It
# activates and deactivates them itself.
docker compose exec -T cli wp plugin install updraftplus backuply --activate
./smoke-admin.sh    # administration tools and every guard; rebuilds .htaccess, destructive

docker compose exec -T cli wp plugin install woocommerce --activate
./smoke-woo.sh      # the shop tools; needs WooCommerce

docker compose exec -T cli wp plugin install elementor --activate
./smoke-elementor.sh  # the Elementor tools; needs Elementor

docker compose exec -T cli wp plugin install kirki --activate
./smoke-kirki.sh      # the Kirki tools; needs Kirki
```

`smoke-admin.sh` also expects a theme named `futuretheme` that declares a PHP version this
site cannot meet, to prove activation is refused for a reason other than the theme being
absent. Without it that check still passes, on the missing-theme branch, which is the
wrong branch:

```
docker compose exec -T cli bash -c 'mkdir -p /var/www/html/wp-content/themes/futuretheme &&
  printf "/*\nTheme Name: Future Theme\nRequires PHP: 99.0\nVersion: 1.0\n*/\n" \
    > /var/www/html/wp-content/themes/futuretheme/style.css &&
  printf "<?php\n" > /var/www/html/wp-content/themes/futuretheme/index.php'
```

Never run `wp plugin install --force` against this stack. The plugin directory is a bind
mount of the repository, and WordPress deletes the old plugin directory before unpacking
the new one. The delete goes straight through the mount and takes the source tree, `.git`
included. To test a built package, extract it to a different directory name instead.

## Plugin Check

[WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/) is the static
analysis tool the WordPress.org directory review uses. A GitHub Actions workflow runs it
on every push and pull request (see `.github/workflows/plugin-check.yml`). To run the same
check locally against this stack:

```
docker compose exec -T cli wp plugin install plugin-check --activate
docker compose exec -T cli wp plugin check guarded-mcp \
  --exclude-directories=.dev,.github,tmp \
  --exclude-files=.gitignore,AGENTS.md,CLAUDE.md,README.md \
  --ignore-codes=hidden_files,github_directory \
  --format=table
```

The exclusions keep dev-only files out of the report: `.dev` is this test stack, `.github`
holds CI workflows, `tmp` holds build artifacts, and `README.md`/`AGENTS.md`/`CLAUDE.md`/`.gitignore` are repository-only
files the build script strips before publication. `hidden_files` and `github_directory`
are plugin-level findings that fire on `.git`, `.gitkeep`, and `.github` which never ship.

Errors fail; warnings do not. The remaining warnings are mostly intentional: direct
database access for the audit log, third-party hook names, and `error_log()` for the
connector's own diagnostics. Plugin Check passing is not the same as WordPress.org approval.

## Diagnosing a client that cannot auto-configure

When a client reports something like "couldn't determine the server settings", OAuth
discovery failed and the client will not say which step. `./diagnose-connector.sh
https://example.com` walks the same steps in order and stops at the first break. It is
read-only and sends no credentials.

## A second stack, for parallel work

The suites are destructive: `smoke-admin.sh` deletes `.htaccess`, resets options and
rebuilds rewrite rules. Two worktrees running them against one site do not merely fail,
they fail in ways that look like real regressions in both. Give each its own:

```
COMPOSE_PROJECT_NAME=wptest2 GMCP_PORT=8081 docker compose up -d
COMPOSE_PROJECT_NAME=wptest2 docker compose exec -T cli wp core install \
  --url=http://localhost:8081 --title="MCP Test" \
  --admin_user=admin --admin_password=admin --admin_email=a@b.test --skip-email

COMPOSE_PROJECT_NAME=wptest2 GMCP_URL=http://localhost:8081 ./smoke.sh
```

Both variables, every time, including on the suite runs. `GMCP_URL` steers only the HTTP
calls; every database assertion inside a suite goes through `docker compose exec`, which
reads `COMPOSE_PROJECT_NAME`. Set one without the other and the suite talks to the second
site over HTTP while checking the first site's database, which does not fail, it just
answers the wrong question.

Bringing a stack up from a worktree without `COMPOSE_PROJECT_NAME` is worse: the project
name defaults to `wptest`, Compose sees a changed bind mount on the running containers and
recreates them pointed at your worktree, silently taking over the main checkout's site.

Two things follow from that takeover, and both have happened.

The named volumes are project-scoped, so the recreate can take the database with it. The
site then asks to be installed again, with no clue as to which command did it. Volume
creation time dates the loss precisely, which is the quickest way to pin it on a command
somebody ran:

```
docker volume inspect wptest_wpdb --format '{{.CreatedAt}}'
```

And Compose reconciles services independently, so the takeover can be partial. The result
is a stack whose `wp` container serves one worktree while its `cli` container still mounts
another. Nothing errors. A suite driving the site over HTTP while asserting through
`docker compose exec` is then testing two branches at once, and reports the difference
between them as a regression in whichever one you are working on. It looks exactly like a
merge having dropped your changes.

`docker inspect` settles it. The compose file describes what a *new* container would
mount; only a running one knows what it does mount:

```
for c in $(docker ps --format '{{.Names}}' | grep '^wptest-'); do
  echo "== $c"
  docker inspect "$c" --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
done
```

Two different sources under one project name is the whole diagnosis. A content hash
confirms it, where a timestamp will not:

```
md5 -q includes/tools-core.php
docker compose exec -T cli md5sum /var/www/html/wp-content/plugins/guarded-mcp/includes/tools-core.php
docker compose exec -T wp  md5sum /var/www/html/wp-content/plugins/guarded-mcp/includes/tools-core.php
```

Before trusting any result from a shared stack, check that the *web* container serves the
tree you mean, using a marker only your branch has:

```
docker exec wptest-wp-1 grep -c some_symbol_only_on_your_branch \
  /var/www/html/wp-content/plugins/guarded-mcp/includes/tools-core.php
```

A zero means you are testing somebody else's branch, whatever the suite says.
`docker compose up -d --force-recreate` puts a split stack back together, but it pulls the
stack away from whoever else is using it, so check before you run it.

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
