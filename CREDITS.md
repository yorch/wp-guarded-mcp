# Attribution and changes

This file exists to satisfy GPLv2 sections 1 and 2(a), which require the upstream
copyright notice to be preserved and modified work to carry a statement of what changed.
It carries the full attribution and the statement of changes. It is not the only place the
upstream work is named: the plugin header repeats the notice so it travels with the code,
`README.md` says what this is a fork of, and each derived file names the file it came from.

This file does not ship in the plugin zip. WordPress.org flags it as an unexpected
markdown file, so the build script strips it and the attribution in the plugin header and
per-file notices ships instead. The checks below still run against the source tree.

`.dev/build.sh` refuses to build a package whose `CREDITS.md` has lost the upstream author,
or where any of the four derived files has lost its own notice. It does not yet check the
plugin header, which is the copy GPLv2 2(a) most directly asks for.

## Upstream

Portions of this plugin are derived from **AI Engine 3.7.7**, Copyright (C) Jordy Meow,
distributed under GPLv2 or later via <https://wordpress.org/plugins/ai-engine/>. The MCP
transport, the OAuth 2.1 module and the WordPress tool catalog originate there. This
project keeps the same licence.

| This plugin | Upstream file |
|---|---|
| `includes/server.php` | `labs/mcp.php` |
| `includes/oauth.php` | `labs/mcp-oauth.php` |
| `includes/tools-core.php` | `labs/mcp-core.php` |
| `includes/tools-rest.php` | `labs/mcp-rest.php` |

`vendor/Parsedown.php` is Parsedown by Emanuil Rusev, MIT licence, unmodified.

Everything else in this repository is original work, Copyright (C) Jorge Barnaby.

## Statement of changes

**Removed.** The AI provider engines, the query and reply layer, chatbots, discussions,
workspace, forms, search, embeddings, content and image and video generation, copilot,
editor assistant, transcription, moderation, statistics, tasks, the public AI REST API,
the admin JavaScript bundle, the chatbot themes, and the shared vendor dashboard.
Roughly 38,900 lines of PHP and 2 MB of JavaScript.

The MCP layer referenced only three methods from all of that (`get_option`,
`get_admin_user`, `markdown_to_html`), which is why the extraction is a cut rather than a
rewrite.

**Dropped tools.** Two tools called the removed AI stack through a global and cannot work
without it.

**Added.** Work that is not derived from upstream at all: MCP prompts and resources, a
one-call site briefing, a change journal with a gated undo, named keys with their own
access level, expiry, tool list and owning account, preview mode on the tools whose effect
is not visible from the call, WooCommerce, Elementor, Kirki, Yoast SEO and ACF groups each
on their own switch, backups that can be started and read but never restored, and a
tamper-evident audit log in its own table with redacted arguments, field-level before and
after values, and bounded retention. The catalog is 50 content tools, 35 more with site
administration switched on, and 12 more again with WooCommerce.

Those three numbers are the same ones the access-level table in `README.md` carries, and
`smoke-admin.sh` checks that table against a running site. They were wrong here before that
check existed, which is the argument for keeping the count in one place and quoting it
rather than restating it.

**Renamed.** All classes, hooks, options, transients, database tables and CSS classes now
use the `GMCP_` / `gmcp_` prefix. Options live in a `gmcp_options` row; the OAuth tables
are `{prefix}gmcp_oauth_clients` and `{prefix}gmcp_oauth_tokens`. MCP resource URIs use
the `gmcp://` scheme.

The REST namespace is deliberately unchanged at `mcp/v1`, so a client already pointed at
`/wp-json/mcp/v1/http` keeps working. OAuth grants do not carry over, because the tables
were renamed: connected apps need to be approved once more.

**Rewritten.** The logger no longer opens `WP_Filesystem` and writes into
`wp-content/uploads` on every request; it writes to the PHP error log, and debug-level
calls are gated. The settings screen is plain PHP instead of a minified JavaScript bundle,
and is now five submenu pages rather than one.

The static credential model inherited from upstream is gone. There is no shared bearer
token: it was stored in the clear because the screen showed it back, carried no identity so
the log could not say who acted, could not expire and could not be scoped. A named key
answers all four and is the only static credential now, and an existing shared token is
carried over into one on upgrade. The token-in-URL route went with it, because it put the
credential in the request path where every proxy in front of the site wrote a copy into its
access log. `get_admin_user()` survives only as the fallback for a key migrated from that
shared token, which has no owner to act as.

**Fixed.** Bugs found by the smoke suites in `.dev/`, the first three inherited from
upstream and the rest introduced here:

- The media upload handler set the current user to a hardcoded user 1, which is often not
  an administrator and sometimes does not exist. It now resolves a real one and fails with
  a clear message if there is none.
- `wp_update_option` reported "Update failed" when the option already held the requested
  value, because `update_option()` returns false both for a failed write and an unchanged
  one. An agent writing a value that was already set was told its write had failed, which
  invites a retry loop. The two cases are now distinguished.
- A tool call missing a required argument raised an "Undefined array key" PHP warning and
  then behaved as though an empty value had been passed. The server now validates each
  call against that tool's own `inputSchema.required` and returns an error naming the
  missing argument.
