# Attribution and changes

This file exists to satisfy GPLv2 sections 1 and 2(a), which require the upstream
copyright notice to be preserved and modified work to carry a statement of what changed.
It is the only place in this project that names the upstream work.

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
without it. The catalog went from 43 tools to 41.

**Renamed.** All classes, hooks, options, transients, database tables and CSS classes now
use the `BRNBY_` / `brnby_` prefix. Options live in a `brnby_options` row; the OAuth
tables are `{prefix}brnby_oauth_clients` and `{prefix}brnby_oauth_tokens`.

The REST namespace is deliberately unchanged at `mcp/v1`, so a client already pointed at
`/wp-json/mcp/v1/http` keeps working. OAuth grants do not carry over, because the tables
were renamed: connected apps need to be approved once more.

**Rewritten.** The logger no longer opens `WP_Filesystem` and writes into
`wp-content/uploads` on every request; it writes to the PHP error log, and debug-level
calls are gated. The settings screen is plain PHP instead of a minified JavaScript bundle.
`get_admin_user()` resolves the lowest-ID administrator rather than assuming user 1.

**Fixed.** Three bugs, each found by the smoke test in `.dev/`:

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
