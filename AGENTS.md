# Working on this plugin

This is a security tool. It hands an AI agent administrative control of a WordPress site,
so a guard that looks right and is not, or a document that claims a guard that is not
there, is worse than no guard: both spend the reader's trust on something that will not
hold. Everything below exists because it went wrong once.

`CLAUDE.md` is a symlink to this file.

## Things that must stay true

**The plugin directory must be named `guarded-mcp`.** Not `wp-guarded-mcp`, not the name a
GitHub zip unpacks to. The plugin derives its identity from the folder through
`plugin_basename()`, and the guard that stops an agent deactivating or deleting the plugin
mid-call compares against that. Rename the folder and the self-protection stops matching,
silently.

**An order is not always a plain order.** `wc_get_order()` resolves a subscription id too,
because `WC_Subscription` extends `WC_Order`, and an order that *belongs* to a subscription
(the parent, a renewal, a switch) is an ordinary `shop_order` row. Both reach subscription
state: `wc_update_order_status` on a subscription made `set_status('completed')` silently
write `pending` while the reply said "completed", and cancelling a parent order moved its
subscription from active to pending-cancel. So `GMCP_Tools_Woo::order_or_null()` refuses
anything that is not `shop_order`, and `update_order_status()` separately refuses orders
that carry a subscription link. Both are reachable at `write` level, on shops that never
switch the subscriptions group on.

**A policy about a post type belongs to the post type, not to the tool that writes it.**
`GMCP_Core::commerce_post_guard()` is asked by every generic post and meta write
(`wp_update_post`, `wp_update_post_meta`, `wp_delete_post_meta`, `wp_duplicate_post`,
`wp_delete_post`), because an order and a subscription are `wp_posts` rows with the
ordering storage off, and their billing period, next payment date and status live in those
same rows. One write tool enforcing it and the next not is the same defect as the settings
tool that once enforced an option policy no other writer did.

**Never run `wp plugin install --force`, `wp plugin update` or `wp plugin delete` against
the `.dev` stack.** Its plugin directory is a bind mount of this repository, and WordPress
deletes the old directory before unpacking the new one. The delete goes through the mount
and takes the working tree, `.git` included. This has happened. To test a built package,
extract it under a different slug inside the container.

**A policy about an option belongs to the option, not to the tool that writes it.**
`GMCP_Core::option_write_policy()` is asked by the settings tool, the generic option tool
and the change journal's undo. Add a rule there, not in a caller. This exists because the
settings tool once enforced a policy no other writer did, so every refusal it made was
reachable by naming the same row through a different tool.

**Credential-shaped data never reaches the journal, the audit log, or the debug
error_log.** `user_pass` is dropped unconditionally, and
`GMCP_Core::field_looks_secret()` is the single answer both subsystems ask, so the
two cannot drift apart. The debug `error_log` at `server.php:604` routes tool
arguments through `GMCP_Core::redact()` for the same reason: it is a third channel
the same credential can reach, and a channel with no redaction is a leak waiting
for the first caller that passes a secret.

**There is no restore tool, at any access level.** Backups can be started and read. That is
deliberate and not an omission to be helpfully filled in.

**There is no kit import, and no tool that takes an archive.** `elementor_set_active_kit`
switches between kits the site already has, which is reversible because the previous one is
still there. Importing one rewrites the whole design system from an archive built elsewhere,
in a single call, with no restore tool behind it. Same category as the two above: deliberate,
and not an omission to be helpfully filled in.

**The audit log has no bulk actions and no row deletion in the UI.** Rows hash the row
before them, so removing one from the middle is exactly what the chain exists to make
visible. A convenient button for it would be building the attack into the product.

## Verification

Most of the defects in this repository's history were found by measuring and missed by
reading. A few habits, each earned:

**Assert the stored state, never the response.** A tool reporting "Option updated" and a
tool updating the option are different claims. Several bugs returned success while writing
nothing, or wrote something other than what they reported.

**Every "X is absent" check needs a control proving it can find a present X.** Absence and
a broken probe produce identical output. This has produced at least five false passes here:
a query that errored and returned null, a heredoc whose quoting failed silently, a `grep`
run from a subdirectory against a path that did not exist, an `echo` that ate an escape.

**A check that passes first time on something fiddly deserves suspicion.** So does a result
that is surprising in the safe direction.

**A seed can agree with the bug.** `smoke-learndash.sh` seeded a question's points under
`points`, which is the form field's name; LearnDash stores them under `question_points`, so
the tool reading `points` returned 0 on a real site and the suite, having seeded the same
wrong key, agreed with it. A fixture written from the same reading of the code as the code
under test proves nothing. Seed through the plugin's own writer where it has one, and where
it does not, take the key from the plugin's own read path rather than from the field name
in its form.

**A read tool can mutate.** LearnDash's `ld_course_access_expired()` is named like a
predicate and is not one: on a lapsed enrolment it deletes the enrolment row and, on a
course configured to, the user's course progress. Calling it from `access_state()` meant
every read tool — reachable with a readonly key — could delete the very data it was asked
to report, with nothing announced and no undo. Before calling a third-party function from a
read path, read its body. A name that starts with `is_`/`has_`/`*_expired` is not a promise.

**A plugin's own API can answer "none" for "not installed".** Elementor Pro creates its form
submission tables on the first submission, and until then its own query layer returns an
empty page with a total of 0 — measured, not inferred. A tool built on that number reports a
confident "no submissions" on every site that has never received one, and on every site where
the module is off. Before reporting a zero from a third-party reader, check that the thing it
reads from exists: `SHOW TABLES LIKE` for a table, the class and method for a module
constant, the directory for a file. The distinction the reply must make is between "there are
none" and "there is nothing to count", and only the check can tell them apart.

**Prove the container serves the tree you mean** before trusting anything it reports. Grep
inside it for a marker only your branch has. `docker inspect` is the authority on what a
running container mounts; the compose file only describes what a new one would.

**Seed through the plugin's own writer**, not with hand-built `INSERT`s. A hand-seeded row
that omits a `NOT NULL DEFAULT 0` column hashes as empty and reads back as `"0"`, and the
test then reports a code fault that is a seeding fault.

## Writing tests

The suites live in `.dev/`. `smoke.sh` covers transport, auth, content tools, prompts and
resources. `smoke-admin.sh` covers the administration tools and every guard, and is
destructive. `smoke-woo.sh` covers the shop tools, `smoke-elementor.sh` covers the Elementor tools,
`smoke-kirki.sh` covers the Kirki tools, `smoke-yoast.sh` covers the Yoast SEO tools,
`smoke-acf.sh` covers the ACF tools, `smoke-gravityforms.sh` covers the Gravity Forms tools,
`smoke-learndash.sh` covers the LearnDash tools, `smoke-elementor-pro.sh` covers the
Elementor Pro tools, and `smoke-woo-subscriptions.sh` covers the Subscriptions tools. Run all
twelve before committing. The last nine need
their plugin installed and say so and exit rather than reporting failures against a site that
simply does not have it.

**A test for a guard must not depend on that guard.** Read the value, make the hostile
call, read it back, **put it back**, and only then compare. The first version of the
option-policy tests asserted before repairing, so on unfixed code the site URL really was
left pointing at a bogus host, the site began answering 301, and twelve later checks failed
describing a catastrophe rather than one missing guard.

**Prove a new test fails without the fix.** Revert the source files, run, confirm that
exactly your assertions fail and your controls still pass, then restore. A test that would
have passed before the change tests nothing.

**Watch what a block leaves behind.** One block ended with `GMCP_Audit::clear()`, which
emptied the table the next block tampers with, so its `UPDATE` matched nothing, verification
correctly reported intact, and the neighbour failed as though detection had broken.
Truncating also resets the auto-increment, which matters wherever ids are compared against a
recorded boundary.

**Some failures are environmental.** The admin suite needs UpdraftPlus and Backuply present,
the shop suite needs WooCommerce, and the base suite expects post 1 to still be called
"Hello world!". Confirm by installing the dependency and re-running, never by explaining the
failure away. One suite also has to survive a plugin it does not name: `smoke-admin.sh`
asserts a rendered page contains a string once, and an SEO plugin's OpenGraph meta tag
repeats the post's own text, so a plain occurrence count returned 2 and failed while the
body it was checking was present and correct. It counts inside the content element now,
because the assertion is about the body, not about the page.

**A suite switches on the tool group it tests, and smoke-admin.sh did not.** `mcp_tools_admin`
defaults to off, correctly, so on a fresh stack none of the tools that suite names were
registered and it reported 147 failures describing every guard in the plugin as broken. One
missing setting, read as a catastrophe, and the second time this shape has appeared after the
missing credential that produced 226. When a suite fails in the hundreds, suspect its
preconditions before its subject.

**The suite's own credential is a named key it mints at startup.** So anything that clears
keys wholesale clears the suite out from under itself: every later request comes back 401,
and the checks describe features as broken. Use `gmcp_clear_other_keys` rather than deleting
the key store. This is not hypothetical; it turned one deleted credential into 226 failures
that all looked like regressions.

**Run one suite at a time against a stack.** They are destructive and share a database.
Running two concurrently produces failures in both that look exactly like real ones, which
is a mistake worth naming because the compose file warns about it and it still happened.

## Parallel work

One Compose project per worktree, always both variables, on every command including the
suite runs:

```
COMPOSE_PROJECT_NAME=wt-thing GMCP_PORT=8081 docker compose up -d
COMPOSE_PROJECT_NAME=wt-thing GMCP_PORT=8081 GMCP_URL=http://localhost:8081 ./smoke-admin.sh
```

Forgetting `COMPOSE_PROJECT_NAME` does not start a second stack. The compose file pins
`name: wptest`, so Compose reconciles the running one toward you: it recreates containers
against your worktree's bind mount and can destroy the database volume on the way. Worse,
it reconciles services independently, so you can end up with the web container serving one
worktree and the CLI container mounting another. Nothing errors, and a suite then tests two
branches at once and reports the difference as a regression. `.dev/README.md` has the
diagnosis.

If you find a stack in that state, do not reclaim it. Another session may be mid-run. Bring
up your own project on a free port, verify there, and say what happened.

The way this actually bites is not forgetting the variables on the first command. It is
setting them up in a helper, working happily for an hour, and then typing one bare
`docker compose exec -T cli wp ...` later in the same session. That one command silently
addresses `wptest`, and it is usually a repair: restoring a fixture on the wrong site,
leaving the real one broken, and turning the next suite run into a mystery. When a result
does not make sense, `wp option get siteurl` says which site you are actually holding, and
it costs nothing.

## Documentation

Docs are part of the change, not a follow-up. The recurring failure here is narrower than
carelessness and worth naming: **a sentence that is true of the case in front of the author
gets read as true of the neighbouring case.** That produced five documented overclaims and
one real security control defect. "Deleting takes two calls" was true of plugins and false
of content. "The screen recomputes the chain" was true when that meant the whole table and
false once it meant the most recent thousand rows.

When a guard's reach changes, search for every sentence that describes it, including in
`readme.txt`, which is the wordpress.org copy and drifts from `README.md`.

## Commits

Conventional Commits, atomic, and only your own changes. If a file holds edits you did not
make, stage yours alone. Explain in the body why the change is right, not what the diff
already shows; the commit log here is the reasoning record, and several entries exist
purely to say why an obvious-looking alternative was wrong.
