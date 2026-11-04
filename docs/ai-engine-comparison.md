# AI Engine comparison: what Guarded MCP can learn

Guarded MCP is a fork of the MCP layer of [AI Engine](https://wordpress.org/plugins/ai-engine/)
3.7.7. AI Engine is now at 3.9.0 (2026-11-02). This note records what the upstream plugin has
shipped since, what this plugin already does better, and what is worth adopting.

**Provenance and limits.** Written from the wordpress.org listing, the vendor pages and the
official MCP docs (ai.thehiddendocs.com/mcp/). Not from AI Engine's source. Changelog entries
for 3.8.2 to 3.8.6 come from a summary of a truncated page; entries before 3.8.2 were not
retrieved. AI Engine does not publish individual MCP tool names (the live list is on its
admin screen), and "140+ tools" is the vendor's own number. Claims below that this repo
lacks something come from grepping `includes/`, with a control (`wp_remote_get`) confirming
the probe finds a present string. The authoritative next step is to diff the 3.9.0 MCP files
against 3.7.7.

## AI Engine's MCP server, as documented

- **Endpoint** `/wp-json/mcp/v1/http`; OAuth discovery under `/wp-json/mcp/v1/.well-known/`.
- **Auth.** OAuth (Full access or Read-only chosen at consent; approvers are `manage_options`
  users, plus Editors when Editor Access is on) or one shared bearer token with an access level
  of Admin, Read-Write or Read-Only. A Connections list shows one row per user and app, with
  Make read-only, Allow changes and Revoke.
- **Tool sets**, toggled per set: WordPress core content; SEO fields through Yoast, Rank Math,
  AIOSEO, SEOPress, The SEO Framework, Slim SEO and SEO Engine; ACF; page builders (Elementor,
  Beaver Builder, Divi, Avada, WPBakery, SiteOrigin); plugins and themes including file
  editing; a SQL tool; Polylang, WPML, WooCommerce; the WordPress Abilities API bridge;
  Dynamic REST.
- **Safety model.**
  - *Ask Before*: Never, Deleting (default), or Deleting and site changes. A triggered
    request does not run. It appears under "Waiting for You" for a human to Approve or
    Decline. Requests wait an hour; an approval lasts 15 minutes and runs once.
  - *Drafts Only*: assistants may create and edit drafts but not publish, schedule or change
    live content.
  - *Undo*: every change is recorded with what it replaced, kept 30 days. Covers posts and
    blocks, custom fields, terms, menus, CSS and site styles, settings, users (Pro adds
    Elementor pages, plugin activations, theme switches, WooCommerce products). An undo can
    be undone. Not undoable: permanent deletes, new users, file edits, SQL, order status.
  - Deletes without `force` always go to trash or are refused. Tools that can break things are
    flagged. Shop Managers cannot edit admin accounts; file tools honour `DISALLOW_FILE_EDIT`.
- **Hooks**: `mwai_mcp_oauth_user_can_authorize`, `mwai_allow_mcp`, `mwai_mcp_callback`.
- **Onboarding**: on connect, assistants get an introduction to the site and starter prompts.
  A Connection Test warns when the host strips the `Authorization` header.

## Where this repo is already ahead

The hash-chained audit log; option and post-type write policies that belong to the object
rather than the tool; protection of third-party audit logs; credential redaction in the
journal, audit log and debug log; self-deactivation protection; per-group guards
(Subscriptions, Gravity Forms, LearnDash); "no false zero" `log_state` checks; no SQL, no PHP
execution, no file editing, no restore, no archive import. AI Engine's own 3.8.5 to 3.8.8
security fixes (key exposure, Shop Manager escalation, `DISALLOW_FILE_EDIT`) are classes this
repo already guards.

## Where AI Engine is ahead

| AI Engine | This repo |
|---|---|
| Human approval queue, out of band | Two-step tokens the agent mints itself, so one prompt-injected agent completes both calls |
| Drafts Only mode | No publish-versus-draft restriction |
| Per-connection read-only for OAuth | OAuth callers are always full admin |
| Undo covers menus, terms, CSS, users; undo of undo | Settings, posts and post meta only |
| Rank Math, AIOSEO, SEOPress and others | Yoast only |
| Form entries for CF7/Flamingo, Fluent, Ninja | Gravity Forms only |
| Redirection, Polylang, WPML | None |
| Markdown converted to blocks | `wp_write_blocks` only |
| Read-only diagnostics: error log, page as a visitor sees it, draft preview links | Not found |

## Worth adopting

1. Human approval queue. A person approving out of band is a stronger guard than a token the
   agent mints.
2. Drafts-only flag on a key.
3. Read-only choice on the OAuth consent screen.
4. More SEO plugins (Rank Math, AIOSEO first).
5. Read-only form entries (CF7/Flamingo, Fluent, Ninja) at `admin`, counts and non-personal
   fields only, with a `log_state` block.
6. Redirection and Polylang/WPML groups.
7. Markdown to blocks.
8. Diagnostic reads: PHP error log (redacted, relative paths), page as a visitor sees it
   (restricted to `home_url()`), expiring draft preview links.
9. Wider undo coverage: menus, terms, Customizer CSS.
10. To check: MCP `initialize` `instructions`, and `readOnlyHint` / `destructiveHint`
    annotations on tools.

## Adopt only with restrictions

- **Abilities API bridge.** It exposes other plugins' abilities under their own permission
  checks, which bypasses `option_write_policy()` and `commerce_post_guard()`. If built: off
  by default, `admin`, read-annotated abilities only or every call through the approval queue.
- **Editor-role connections.** `gmcp_oauth_user_can_authorize` already permits it by filter.
  Needs a content-only tool set; a product decision.

## Do not adopt

SQL tool, plugin and theme file editing and the YOLO PHP companion (contradict "tools do not
execute arbitrary PHP or SQL"); Dynamic REST (`tools-rest.php` exists); a generic revision
restore tool (undo and snapshots cover it, and it sits next to the deliberate no-restore
rule); chatbots, AI Forms, embeddings, function calling, Workspace apps, Insights and cost
limits, and the provider connectors (this plugin never calls a model); Beaver, Divi, WPBakery
and Avada editing (large surface, small payoff).
