---
name: bookyzo-dev
description: Use whenever building, editing, or planning code inside the Bookyzo WordPress plugin (wp-content/plugins/bookyzo or bookyzo-pro) — the unified Appointment/Table/Event/Slot booking plugin. Covers naming conventions, Free/Pro split rules, WordPress.org Guideline 5 (trialware) traps, and the review checklist (enqueue, escaping, nonces, activation) learned from the 2026-09-28 review.
---

# Bookyzo development conventions

Read `../../FEATURES.md` (Free/Pro feature list + launch sequencing), `../../DETAILED-SPEC.md` (data model, DB schema, REST API, open decisions), and `../../ROADMAP.md` (ordered build steps by phase + the Free→Pro conversion strategy table) before starting any implementation work in this plugin. These three files are the source of truth for scope and sequencing — don't re-derive feature lists or step order from memory or from this skill. When asked "what's next," check ROADMAP.md's phase list rather than guessing.

## Naming
- Free plugin: prefix `BYZO_` (classes) / `byzo_` (functions, hooks, options, meta/DB columns), text domain `bookyzo`, folder `wp-content/plugins/bookyzo/`.
- Pro add-on: prefix `BYZOP_` / `byzop_`, folder `wp-content/plugins/bookyzo-pro/`. Pro's *references to Free symbols* use `byzo_`; Pro's own new stuff uses `byzop_`.
- These prefixes are a first pass, not sacred — the sister project `codeholt-bundles-for-woocommerce` (formerly `bundle-product-for-woocommerce`, prefix `BPFW`→`CBFW`) went through a full rename after a WordPress.org reviewer flagged genericness/similarity to an existing plugin. If that happens here, do a full sweep (file names, class names, function names, hooks, option names, meta keys, CSS classes, JS handles, text domain) — don't leave it half-migrated.

## The one rule that matters most: don't build visible Pro locks into the Free plugin

The sister plugin (`codeholt-bundles-for-woocommerce`) was **rejected twice by WordPress.org for Guideline 5 (Trialware)** — once for a whole "locked preview" settings-UI subsystem (disabled fieldsets, lock icons, "Available in Pro" text), once for a hardcoded allowlist (`array('simple')`) gating a feature that technically already worked for other types, with help text that literally said "in the free version." Both times the fix was the same: **delete the artificial restriction and gate on a real, honest capability check instead of a commercial one.**

Apply this from the start in Bookyzo, don't wait for a rejection to learn it again:

- **Never** ship UI in the Free plugin that shows a Pro feature as visibly locked/disabled/greyed-out with an upsell link. If Pro isn't installed, the feature simply doesn't appear — no ghost menu item, no disabled fieldset, no "🔒 Upgrade to unlock" text.
- **Never** write help text, tooltips, or docblocks containing phrases like "in the free version", "only available in Pro", "upgrade to unlock" anywhere inside the Free plugin's shipped code.
- **Never cap a count in Free that Free's own code can handle** — bookable items per type, shifts per day, bookings, staff, locations, anything. A filterable "capability" limit that Pro raises is still a limit: **WordPress.org pended the first submission (2026-09-28) for exactly this** (`BYZO_Capabilities::max_bookable_items_per_type()` / `max_shifts_per_day()`, default 1, lifted by Pro). Both were removed; unlimited items and several shifts a day are Free features now. There is no "Free volume cap" and there must never be one again.
- The Free→Pro conversion strategy is **features that live in Pro's own code** (SMS/WhatsApp, services, deposits, pricing rules, floor plan, ticket tiers, analytics…), never a number Free holds back. If Pro needs a hook in Free, it's a hook that does nothing on its own (an action, or a filter returning an empty array / null).
- Before adding ANY restriction, grep for restriction vocabulary as a self-check (the exact heuristic that caught both real violations in the sister plugin): `free version|pro |premium|upgrade|unlock|licen[cs]|trial|paid|limited|restrict|only .* can be|locked` plus numeric caps like `limit'? => [0-9]+`. If a numeric cap exists for a genuine technical reason (e.g. a dropdown performance guard), make it filterable and comment *why* it exists, don't just leave a bare number.
- Data model / DB schema should generally NOT be Free-vs-Pro gated at the table level — build the full schema in Free where it's cheap to do so. But if Free's code can already use it (like `shift_group` for several shifts a day), Free must let people use it — hiding a working code path behind a Pro-only UI affordance is the same locked functionality.
- **Before adding any Pro-shaped feature or gating logic, check whether `bookyzo-pro` already owns that territory** (once the Pro plugin exists) — grep it first. The sister plugin once built a whole parallel, disconnected Pro-gating stub in the Free plugin because this check was skipped; caught late, had to be unwound.
- **A finished feature in Free behind a boolean filter that Pro flips is locked functionality**, even with nothing visible. Anyone can `add_filter( 'byzo_x_enabled', '__return_true' )` and get it free. Found and fixed in the 2026-09-26 audit: the `.ics` calendar feed and package store moved into Pro, and "use business hours" and per-ticket attendee names became Free (the second one as a setting). The rule: the *work* of a Pro feature lives in Pro. Free may only offer hooks and a place to render what an add-on hands it (custom fields, add-ons, tiers via filters that return arrays).
- **A control that shows in Free and always fails on save is a visible lock**, and hiding it at a cap is still a cap (see above). Make the feature free or move it into Pro.
- **No license check in Free.** Free never calls `byzop_is_licensed()` or reads Pro's license state, not even to hide a menu item: "Remove all license checks" is a line in the review email. To know whether Pro is there, use `defined( 'BYZOP_VERSION' )`.
- **Known residual risk (left on the owner's call):** `BYZO_Capabilities` still holds add-on UI switches (waitlist, discount/package codes, marketing opt-in, invoice/ticket links, staff logins) whose backend lives only in Pro. The 2026-09-28 reviewer read that class and flagged only the count limits, but warned that more issues "of the same nature" end the review. Don't add new switches of this kind; if a review cites them, move that UI into Pro through a widget-extension mechanism.
- The Get Pro page is hidden behind `byzo_show_get_pro()` (default `false`, owner's call 2026-09-27) until Pro can actually be bought. Wire `byzo_get_pro_url()` to the real purchase page before flipping it back.

## WordPress.org submission checklist (Free plugin)

These come from wordpress.org/plugins/developers/add, the Detailed Plugin Guidelines, and the 2026-09-26/27 audit. Keep every one true on every release. Reviewers read the code by hand; Plugin Check only catches part of it.

**Reviewers reject outright for:** unescaped output, unsanitized input, **form data without a nonce**, and anything against the guidelines.
- **Every form POST carries a nonce**, guest forms too. Customer register/login/logout build theirs with `BYZO_Customer_Auth::form_url()` (the nonce rides on the form's URL, so the Vue app needs no hidden field), and a stale nonce sends the visitor back with the `expired` error instead of a bare `wp_die()`. The guest cancel form uses `wp_nonce_field()`, and a stale nonce there just skips the cancel. A signed token does not replace the nonce.
- Sanitize every `$_GET`/`$_POST`/`$_REQUEST`/`$_FILES` read with `wp_unslash()` + the right sanitizer. Passwords are the one exception: use them exactly as typed and give that line an ignore comment with the reason. For uploads, check `is_uploaded_file()`.
- Every `phpcs:ignore`/`disable` names the exact sniff and gives a reason after `--`. Reviewers read these.
- **A GET never changes data**, even with a signed token: link scanners and mail clients open links. The page shows a button that POSTs with `wp_nonce_field()` + the token, and the handler checks both (`BYZO_Booking_Cancel`, `BYZO_Marketing::handle_choice()` are the pattern).

**Flagged in the 2026-09-28 review — keep these true everywhere:**
- **No `<script>` or `<style>` tag in PHP**, not in templates, not in returned strings, not in dev-only code (reviewers grep). Static CSS/JS goes in `assets/css/` / `assets/js/` and loads with `wp_register_*`/`wp_enqueue_*`; data goes through `wp_add_inline_script()`/`wp_add_inline_style()`; a dynamic snippet with no file uses `wp_register_script( $handle, false )` + `wp_add_inline_script()`. The theme-bypassing standalone pages (manage-booking, unsubscribe) enqueue in their render method and print with `wp_print_styles()`/`wp_print_scripts()`.
- **What a shortcode, block `render_callback` or `the_content`-style filter returns must be fully escaped.** Never append `apply_filters()` HTML from add-ons to that return value. An add-on that needs front-end code hooks the `byzo_widget_rendered` action and enqueues it (Pro's pixels and conversion beacon do this).
- **Activation on a clean install with `WP_DEBUG` on**: no fatal/warning/notice, no output, every table created with `$wpdb->prefix` + `get_charset_collate()` + dbDelta formatting, no remote request.
- **Assets only on our own screens**: admin CSS/JS only when `BYZO_Admin_Assets::is_bookyzo_screen()`, widget assets only when a form renders, CSS scoped so it can't restyle wp-admin.

**Slug and headers:**
- **The slug comes from the `Plugin Name` header** ("Bookyzo – Booking & Appointments" becomes `bookyzo-booking-appointments`). It must be `bookyzo` to match the text domain and folder. Change it on the submission page right after uploading; that's allowed once, before review. **After approval it can never change.** The display name can change later.
- `Version` in the header equals `Stable tag` in readme.txt. `Tested up to` = the current WordPress major (check api.wordpress.org/core/version-check/1.7/).
- `Requires at least` must be true: the bundles need the `wp_script_attributes` filter (6.3) to load as `type="module"`, so the minimum is **6.3**. Before using any newer WP API, bump it or add a fallback. `Requires PHP` is 7.4, so no `?->`, `match`, `str_contains`, attributes or enums in shipped PHP.
- Plugin URI / Author URI must resolve. Remove the header rather than point it at an unregistered domain.
- Global variables at file scope (uninstall.php, the main file) take the `byzo_` prefix.

**readme.txt:**
- Tags ≤ 5, short description ≤ 150 characters, Contributors = real wordpress.org usernames.
- An `== External services ==` section says, for every outside service (Stripe API + Stripe.js, the Google Calendar link), what is sent, when, and links its Terms and Privacy pages. Add to it whenever a new remote call or remote script appears.
- Never describe Pro as "planned" in one place and as existing in another. Keep the Free/Pro wording consistent.

**Guideline 4 (human-readable code):** `assets/` is minified, so the source must ship or be linked publicly. The repo is private for now, so `src/`, `package.json`, `package-lock.json` and the Vite/Tailwind/PostCSS configs ship (see `.distignore`), and readme's FAQ says how to rebuild. `jsconfig.json`/`components.json` are excluded because the build never reads them. **Plan: once approved, the owner makes the repo public, the source link goes in readme.txt, and `src/` + configs can go back into `.distignore`.**

**Zip contents** (`npm run build:release` → `release/bookyzo.zip`): no `.wordpress-org/` (those images go to SVN `assets/` after approval), no `BOOKYZO-QA-REPORT-*.md`, no scratchpad/tests/specs. `bin/build-release.js` reads `*` anywhere in a `.distignore` name pattern.

**Translations in the Vue bundles:** `wp i18n make-pot` reads the *built* files, and the minifier drops ordinary comments. So a string with a placeholder gets `/*! translators: %s: … */` written right before its `__()`/`_n()` call (inside template `{{ }}` too). The builds keep those legal comments, and `vite.translator-comments.js` turns them into `/* translators: */`. Keep each annotated call in **its own statement**, not an object literal. Compress stays off because it merges statements and moves notes onto the wrong strings. `npm run build:release` must end with zero make-pot warnings.

**Admin bundle:** one chunk on purpose (script translations load per built file, and Pro joins the one running app), so `chunkSizeWarningLimit` is 700 kB. Don't code-split the admin app.

**Uninstall:** it only purges when `delete_data_on_uninstall` is on (Settings → General switch), and then it drops every table and every option the plugin writes (payment keys included). Add any new option or table to `uninstall.php`.

**Before every submission/release:**
1. `npm run build:release`: zero warnings.
2. Plugin Check on the staged `release/bookyzo`, via a clone DB with plugin-check active: copy it to a sibling `wp-content/plugins/` folder and run `BYZOQA_DB=<clone> php -d auto_prepend_file="<scratchpad/qa-prepend.php>" C:/wp-cli/wp-cli.phar --path=C:/xampp/htdocs/test plugin check <folder> --slug=bookyzo` (without `--slug` every string reports TextDomainMismatch). **0 errors**. The remaining warnings should only be DirectQuery / NoCaching / UnescapedDBParameter / SchemaChange on custom tables. Template variables take the `byzo_` prefix too.
3. `bash scratchpad/run-suite.sh`: all pass (the runner now also fails on `Parse error`).
4. Re-run the Guideline 5 grep above, and check that no control in Free can only fail and no count is capped.
5. `grep -rnE "<script|<style" --include=*.php includes templates` finds nothing but comments.
6. Activate on a brand-new WordPress (empty DB, `wp_install()`, `WP_DEBUG` on): no output, no plugin notices, all tables there.

**Replying to a review:** reply on the same email thread, short, no list of changes (they re-review everything). Only give context they need (e.g. an environment error on their side). Fix every issue *and every other occurrence of the same kind* before replying: a second round with the same kind of issue ends the review.

## Vue admin shell

Both Free and Pro share ONE Vue 3 admin app (per the original brief — "admin panel Vue use korbe"). Build REST endpoints and Vue routes/components in Free from day one assuming Pro will add more routes to the same app, rather than Free shipping a separate PHP-rendered admin UI that Pro later has to replace. See `DETAILED-SPEC.md` §4 for the planned REST surface (`bookyzo/v1` namespace).

**The actual runtime mechanism is built (Phase 2 step 1, 2026-09-11) — read `DETAILED-SPEC.md` §3 "Shared Vue admin app" before adding any new Pro Vue screen.** Short version: Pro ships its own small `build.lib`/`iife` Vite bundle (external `vue`/`vue-router`, resolved against `window.Vue`/`window.VueRouterLib`) that calls `window.byzoAdmin.registerExtension({ routes, navItems })` to extend Free's already-running app — never a second mounted app, and never a normal ES-module Vite build on Pro's side (breaks the external→global mapping). Pro's script MUST carry `defer` (see `BYZOP_Admin_Assets::add_defer()`) or it can execute before Free's deferred module script has run.

For any visual/CSS/component work on the admin app — tokens, colors, reusable Vue components, layout conventions — see the companion **`bookyzo-design`** skill (design tokens incl. brand color `#2563eb`, the `Byzo*` base component vocabulary, and the same never-show-a-locked-Pro-feature rule applied to the design layer).

## FEATURES.md discipline

Like the sister plugins, keep a `FEATURES.md` (Free) and eventually `bookyzo-pro/FEATURES.md` (Pro) **in English**, updated with every feature change — this is a standing preference of the plugin author, not optional. `DETAILED-SPEC.md` is the technical companion (schema/API/open-decisions); update its "Open Decisions" section as each item gets resolved rather than leaving stale unresolved-sounding text after a decision is actually made.

## Decided (don't re-ask, don't re-derive — just build)

- **ALL FOUR booking types ship in Free** (`DETAILED-SPEC.md` §5 item 5, decided 2026-09-02 — supersedes the earlier "Appointment first, other types later" staging). Pro gates extra features only, never booking types or counts: Free has no limit on bookable items or shifts (the old one-per-type cap was removed after the 2026-09-28 WP.org review). Appointment was built first as the reference implementation (ROADMAP.md Phase 1, complete); Table/Event/Slot land via **ROADMAP.md Phase 1.5** before the first WP.org submission.
- **Type-first Add New flow + per-bookable item shortcodes** (`DETAILED-SPEC.md` §5 item 6): bookable item creation picks the booking type FIRST, then shows type-specific fields (field matrix in §A2/A3); every bookable item's pinned shortcode is shown copyable in the Bookable Items list.
- **Event = direct slot rows, no weekly schedule** (`DETAILED-SPEC.md` §5 item 7 and §1.3): event-type bookable items skip the generator; occurrences are admin-entered straight into `wp_byzo_slots`. Never wire event bookable items into the weekly-schedule path.
- **Timezone storage: site-local**, not UTC (`DETAILED-SPEC.md` §5 item 1) — all DATETIME columns hold WordPress's configured local time. Only becomes a live concern again once Pro's multi-location feature ships (per-location timezone must travel with any datetime read, see the full note in DETAILED-SPEC.md).
- **Brand color: `#2563eb`**, component library **shadcn-vue** — see `bookyzo-design` skill.

## Before writing PHP/Vue code for the first time in a session

1. Re-read `DETAILED-SPEC.md` §5 "Open Decisions" for anything that's changed or newly resolved since — don't assume this skill file's "Decided" section above is perfectly in sync; DETAILED-SPEC.md is the source of truth, this skill is a pointer to it.
2. Check `wp-content/plugins/` for whether `bookyzo-pro` exists yet — most early work is Free-only (Part C step 1–3 of `FEATURES.md` / Phase 0–1 of `ROADMAP.md`), so Pro-specific instructions above are forward-looking until that stage.
3. Check `ROADMAP.md`'s phase list for exactly which numbered step comes next — don't reorder or skip ahead without saying so.
