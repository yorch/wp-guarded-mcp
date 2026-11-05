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

`GMCP_Core::plugin_config_post_types()` is the same rule for post types another plugin owns,
and it exists because a group that withholds something is decoration if a generic tool returns
it. A WPForms form is a `wpforms` post whose `post_content` is one JSON document holding the
fields, the notification recipients and the confirmation redirects, so `wpforms_get_form`
counting those settings and never returning them would mean nothing while `wp_get_post`
returned the blob and `wp_update_post` could rewrite the recipient — a readwrite key could
point every future submission at an address it chose, on a plugin that stores no submissions
at all. The guard is wired into the two existing ones (`commerce_post_guard` for writes,
`commerce_post_read_guard` for non-admin reads) rather than a third pair of call sites, and it
covers creating or retyping into the type. Stream's alert rules (`wp_stream_alerts`) are listed
for the same reason: a rule set to a disabled status stops that alert firing.

**A drafts-only key is held by the post, not by the tool.** `GMCP_Core::drafts_only_guard()`
(through `post_write_guard()` for the generic writers) is asked by every tool that writes a
post, its meta, its terms or its thumbnail, and `restrict_caller_to_drafts()` installs hooks
under WordPress that ask it again for writers that do not. A new tool that writes a post asks
the guard; the net is the backstop, not the plan. Attachments are filtered through
`wp_insert_attachment_data`, not `wp_insert_post_data`, and the net needs both. The flag caps the
key at `readwrite`; that cap is part of the guard.
What it covers is posts, their meta, term assignments, featured image and attached media, and
nothing else: comments, taxonomy terms, users, options and preview links are outside it, and
every sentence that describes it (refusal text, key label, README, readme.txt, guardrails) must
say so rather than "anything that is live". Media attached to no post is refused for edits as
well as deletes, because a live page may be showing it and nothing can find which.

**A read policy about a post belongs to the post, and follows what hangs from it.**
`GMCP_Core::post_read_guard()` (the form-submission policy at every level, the commerce read
policy below admin) judges a post as the nearest guarded post above it through `post_parent`,
because a revision of a submission, a file attached to one and a comment on one are ordinary
rows of another type that pass any check made on their own type. Every tool that returns a
post, its meta, its comments, its terms, its media or its title asks it, and so do the REST
tools, the briefing and the resources. The change records go through `GMCP_Changes::record()`
and carry a submission as an id with `[withheld]` values, because `wp_list_changes` is a
read-level tool and names a post by its title. A new reader of any of these asks the guard;
`smoke-forms.sh` block 0c seeds a submission with a revision, an upload, a comment and a term
and calls every reader with a read-only key.

**A deletion is not always a delete tool.** WordPress deletes on markers it reads from
ordinary rows: `wp_scheduled_delete()` removes every post with an old `_wp_trash_meta_time`
and `wp_delete_auto_drafts()` every old auto-draft. So `GMCP_Core::status_write_refusal()`
refuses a status of `trash` or `auto-draft` in the write tools (and `spam` on a comment, which
Akismet deletes after fifteen days), `protected_meta_refusal()`
refuses the `_wp_trash_meta_` keys in every meta writer (with a net under the WordPress
meta functions for writers that are not ours), and `GMCP_Approvals::classify_call()` reads
the arguments as well as the tool's name. A write-level key that can reach any of them has a
delete tool, whatever the README calls the level.

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

**An audit log this plugin does not own is still not writable through it.**
`option_write_policy()` refuses options whose name begins with a prefix in
`audit_log_option_prefixes()` — `wsal_` for WP Activity Log and `wp_stream` for Stream,
extendable through `gmcp_audit_log_prefixes` for any other logger. Those rows are ordinary
options, so every generic writer can reach them: one admin-level `wp_update_option` on
`wsal_disabled-alerts` stops chosen events being recorded, `wsal_delete-data` arms dropping the
log on uninstall, and on Stream one write to `wp_stream` with `general_records_ttl` set to 1
shortens retention to a day — measured on this stack before the rule existed, with the `wsal_`
refusal running as the control. A log an agent can switch off is not a log, and an agent that
can switch it off and then read it back can confirm its own silence.

**The same promise covers switching the plugin off, and the option rule alone did not.**
`GMCP_Core::watcher_plugin_files()` refuses deactivation, deletion and update of Wordfence,
Stream and WP Activity Log, because one `wp_deactivate_plugin` call stops the recording just as
effectively as rewriting its settings and is not an option write at all. Measured on this
stack: all three were deactivatable through the admin group while the README said a log an
agent can switch off is not a log. An update is refused too, because it can replace a working
watcher with one whose tables or settings the site has not migrated to.

Wordfence is deliberately not covered by the option rule, and that is measured rather than
assumed: 9.0.2 keeps its entire configuration in its own `wp_wfconfig` table, and its only
`wp_options` rows are `wordfence_version`, `wordfence_ls_version`, `wordfence_installed`,
`wordfenceActivated`, `wordfence_case` and `wf_plugin_act_error`. Writing `wordfence_version`
is accepted and then rewritten by the plugin on load, so there is no durable setting to
protect. A prefix rule there would guard nothing and would read as protection that is not
there, which is the failure this whole file is about.

**Credential-shaped data never reaches the journal, the audit log, or the debug
error_log.** `user_pass` is dropped unconditionally, and
`GMCP_Core::field_looks_secret()` is the single answer both subsystems ask, so the
two cannot drift apart. The debug `error_log` is a third channel the same credential
can reach, and a channel with no redaction is a leak waiting for the first caller
that passes a secret. So every debug line that prints something a client sent goes
through `GMCP_Core::log_text()`, which redacts arrays through `redact()` and then
blanks a credential written as an assignment inside a string (`smtp_pwd=...`, a
`Bearer` token). `GMCP_Logging` scrubs every message it writes as well, so a line
added later without the helper is still covered. A bare token with no key in front
of it is not recognised by any of this, and nothing in the docs claims it is.

**Free text is judged as text, in one place.** `holds_credential()` reads field names and opens
only values that begin as JSON or serialized data, so a post body with `{"api_key":"..."}` or a
`define('DB_PASSWORD', ...)` line in the middle of it passed. `GMCP_Credential_Scan` is the text
test, and the journal (`unsafe_to_keep()`, `post_previous()`, `label()`), `GMCP_Changes::describe()`,
the audit log's arguments, target and reply, and `redact_reversible()` all ask it. A post field that
fails is left out of `previous` and named in `redacted_fields`; a placeholder is never stored, because
undo would write it over live content. Tune it against false positives as hard as against misses: an
ordinary body that says "password" must stay undoable, and `.dev/journal-pure.php` holds that control.
Undo also refuses to restore a status `status_write_refusal()` refuses (`trash`, `auto-draft`).

**There is no restore tool, at any access level.** Backups can be started and read. That is
deliberate and not an omission to be helpfully filled in.

**No tool reads, lists, answers or edits the approval queue, at any access level.** The
approval queue (`GMCP_Approvals`) holds a call until a person signed in to wp-admin answers
it, and its whole value is that the agent cannot answer for itself. `decide()` is the only
place a request leaves pending, and it demands a logged-in cookie that belongs to the
current user, because a bearer key and an OAuth grant both set the current user to an
administrator with no cookie. A tool that listed pending requests would hand a
prompt-injected agent the ids and arguments to reason about; a tool that approved would
delete the feature. `smoke-admin.sh` asserts no such tool exists at any level. Which tools
are held is derived from their registration (`GMCP_Approvals::classify()`), never from a
list of names, and an unclassifiable tool is held.

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
Elementor Pro tools, `smoke-woo-subscriptions.sh` covers the Subscriptions tools, and
`smoke-wsal.sh` covers the WP Activity Log tools, `smoke-redirection.sh` covers the
Redirection tools, `smoke-polylang.sh` covers the Polylang tools, `smoke-seo.sh` covers the Rank Math and
All in One SEO tools, and `smoke-forms.sh` covers the form-submission tools (Contact Form 7 with
Flamingo, Fluent Forms, Ninja Forms, WPForms; its first block needs no form plugin).
`smoke-wordfence.sh` covers the Wordfence tools, `smoke-stream.sh` covers the Stream tools,
`smoke-beaver.sh` covers the Beaver Builder tools, `smoke-download.sh` covers the one-time
download endpoint and `smoke-export.sh` covers the site-file manifest, the approval-gated
archive and its one-time endpoint.
Run all
twenty-one before committing. The plugin-specific suites need
their plugin installed and say so and exit rather than reporting failures against a site that
simply does not have it; `smoke-download.sh` and `smoke-export.sh` need only this plugin.
(This sentence said fourteen while twelve suites existed and sixteen
while seventeen did, which is the same drift the access-level table had: a count nothing checks
moves on its own. It is twenty-one now, counted from `ls .dev/smoke*.sh`.)

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

**A fixture cleaned up with `wp_delete_post` leaves something behind under HPOS.**
WooCommerce's High-Performance Order Storage keeps an order as a row in `wc_orders` with no
`wp_posts` row at all, and it is on by default on a fresh store. So a suite that clears its
own orders with `get_posts( ['post_type' => 'shop_order'] )` finds nothing, and deleting by a
HPOS order id writes to the posts table. The cleanup silently does nothing — measured: 12
orders before it ran and 12 after — and because the shop suites assert exact counts, each run
hands the next one a failure that describes a tool defect which does not exist. Delete orders
through WooCommerce's own API (`wc_get_order( $id )->delete( true )`) and, where a suite
asserts counts, run it twice in a row: a leftover-fixture bug only appears on the second run.

**A suite switches on the tool group it tests, and smoke-admin.sh did not.** `mcp_tools_admin`
defaults to off, correctly, so on a fresh stack none of the tools that suite names were
registered and it reported 147 failures describing every guard in the plugin as broken. One
missing setting, read as a catastrophe, and the second time this shape has appeared after the
missing credential that produced 226. When a suite fails in the hundreds, suspect its
preconditions before its subject.

**A count table checked against reality only stays checked if its filter is exhaustive.** The
access-level table in `README.md` counts only the groups that are always present, and
`smoke-admin.sh` excludes the optional ones by tool-name prefix. Adding a group without adding
its prefix moves every number in the table, which reads as a numbering mistake rather than as
new tools. The prefixes are not guessable from the group names — LearnDash's tools are `ld_`,
Gravity Forms' are `gf_` — so take them from the group's own file rather than from its name.

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

**A pull request is written the same way.** Its title is a Conventional Commit, `type(scope):
subject`, in the imperative and under about 72 characters, because the title becomes the commit
subject when the PR is squash-merged and the log is the reasoning record. Use the types the
history already uses: `feat`, `fix`, `docs`, `test`, `refactor`, `ci`, `build`. Mark a breaking
change with `!` and a `BREAKING CHANGE:` line. Do not prefix the title with a model name,
"WIP" or a ticket number; a draft is marked by being a draft.

The description is the body of that commit, not a changelog of the diff. Put these in it, in
this order:

1. **Why**, in a sentence or two: the defect or the gap, and what was wrong with the obvious
   alternative if there was one.
2. **What changed**, grouped by behaviour a reader would notice (a new tool group, a guard whose
   reach changed), each as its own Conventional-Commit-style bullet, not by file.
3. **Guard and doc reach**, whenever a guard's reach changed: which sentences in `README.md`,
   `readme.txt` and `docs/guardrails.md` were updated, and what the change deliberately does
   *not* cover.
4. **Verification**, stated as measured: which suites ran and their counts, which new tests were
   shown to fail without the fix, and which suites **did not run and why**. A suite that could
   not run is listed as not run, never as passing, and an assumption about a third-party plugin
   that was not checked against its source is listed as unverified.

A PR that carries several commits keeps one Conventional Commit per logical change, so a squash
can be avoided when the history is worth keeping.
