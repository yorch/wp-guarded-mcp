# Handoff: `fix/highs` — remaining findings from the repo-wide review

Temporary working note. Fold the still-open items into the PR description and delete this
file before merging. It exists because the review's own notes lived in a temp directory that
was pruned, leaving the remaining list only in a chat transcript.

## State

Branch `fix/highs`, five commits on top of `main` (`7b966ad`), unpushed at the time of
writing. Each commit body carries the measurement that justified it and the mutation result
that proved it; read those before re-deriving anything.

Worktree: `/Users/yorch/code-personal/ai-engine-wt-highs`
Compose project: `wt-highs`, port `8084`.

## Fixed and proven

| Commit | Finding |
|---|---|
| `543a509` | `active_plugins`, `active_sitewide_plugins`, `template`, `stylesheet`, `cron`, `wp_user_roles` were writable through `wp_update_option`, bypassing the tool that normally enforces each one's policy. Added to `unwritable_options()`. |
| `f7f9381` | An `id` inside `fields` replaced the checked target after `commerce_post_guard` / `edit_user` / `promote_user` had run, in `wp_update_post` and `wp_update_user`. Now refused by name. |
| `c0f0644` | `commerce_post_guard` missing from 10 generic writers; `wp_create_post`/`wp_create_posts` could make a phantom `shop_order` (row exists, `wc_orders` does not, `wc_get_order()` still resolves it). Shared `refuse_commerce_post()` + `commerce_post_type_objection()`. |
| `a1127e8` | `wp_alter_post` wrote its replacement unsanitized: `<script>` in published content at `write` level. Now routed through `store_html()` / `sanitize_text_field` by field. |
| `c4bdcab` | `wp_update_user` ran `user_pass` through `sanitize_text_field`, so `Pa<ss>word&1` set `Paword&1` and still reported success. Password now passed through as sent. |

Suite state after each: `smoke.sh` 132/0, `smoke-woo.sh` 69/0, `smoke-admin.sh` 491/0.

## Verified as NOT defects — do not re-open without a new measurement

- **`wp_create_user` minting admins.** `get_editable_roles()` *does* filter by caller —
  measured: an editor's editable roles exclude `administrator`. An administrator creating an
  administrator is a permission they already hold. The guard is live, not inert.
- **`wp_write_blocks` XSS.** It builds markup through `clean_html()`, so a `<script>` in a
  paragraph is stripped before the write. Only `wp_alter_post` had the gap.
- **"Generated REST tools" XSS.** `server.php`'s generated-tools reference is a revision
  counter (`bump_tools_revision`), not an output path. No vector found.

## Still open

1. **Activity log switchable off through four routes** that bypass the `wsal_` prefix rule in
   `option_write_policy()` / `audit_log_option_prefixes()`. Find all four before fixing; the
   prefix rule is the shape that already exists, so the fix is likely adding the missing
   prefixes or closing the write paths that reach the rows under other names.
2. **Audit / journal credential leaks**, three related items:
   - tool replies are stored unredacted in the audit log's `detail`;
   - the journal's option path stores credentials that the audit log redacts
     (`field_looks_secret()` is meant to be the single answer both ask);
   - the debug `error_log` at `server.php` leaks secrets inside JSON strings and in
     non-`key` fields, despite routing through `GMCP_Core::redact()`.
3. **Post-type policy beyond commerce.** Elementor Pro Custom Code, the active kit, and
   conditioned templates are post/meta rows the generic tools can reach. Same "policy belongs
   to the post type" shape as `commerce_post_guard()`.
4. **Journal undo and `commerce_post_guard`** — the writers are fixed; confirm the undo path
   cannot restore an order/subscription row through the generic post path either.
5. **`smoke-wsal.sh` bug of my own.** Two checks grep for `"Mozilla"`, which no seeded event
   contains, so they can never fail. Fix with the next wsal change.

## Environment notes

- The Docker volumes were recreated empty mid-session (docker VM reset). Fix the stack with
  the staging recipe in `.dev/README.md`; commercial plugins live under
  `/private/var/folders/8k/4fn7ttbj4_5g3wtn81flknf40000gn/T/opencode/pluginsrc`
  (`gravityforms`, `sfwd-lms`, `learndash-hub`, `elementor-pro`,
  `woocommerce-subscriptions`, `stops-core-theme-and-plugin-updates`, and others). Verify a
  staged plugin has top-level `*.php` files before activating it — an earlier bad extraction
  produced directories holding only subfolders.
- `smoke-admin.sh` and `smoke-woo.sh` can report one failure on a first run against state
  left by the previous run, then 0 on a second run. Run twice before believing a single
  failure; this predates the current branch.
