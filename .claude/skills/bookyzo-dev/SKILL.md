---
name: bookyzo-dev
description: Use whenever building, editing, or planning code inside the Bookyzo WordPress plugin (wp-content/plugins/bookyzo or bookyzo-pro) — the unified Appointment/Table/Event/Slot booking plugin. Covers naming conventions, Free/Pro split rules, and WordPress.org Guideline 5 (trialware) traps to avoid before writing gating code.
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
- Enforce Free/Pro limits (one resource per type, one shift per day, single cancellation policy, etc.) via a genuine **capability function** (e.g. `byzo_max_resources_per_type()`, filterable as `byzo_max_resources_per_type`) that Pro overrides by hooking the filter — not by a hardcoded array/if-check scattered across multiple files. See `DETAILED-SPEC.md` §A9 and the planned `byzo_can_book_resource` filter (mirrors CBFW's `cbfw_can_bundle_product` fix).
- These same limits are also the **entire Free→Pro conversion strategy** (see `ROADMAP.md`'s "Free → Pro Conversion Strategy" table) — every limit must be something a real growing business runs into (2nd staff member, 2nd shift, wanting SMS reminders after email ones get ignored), never a cosmetic restriction invented to create upsell pressure. Avoiding Trialware rejection and building a genuinely convincing upgrade path are the same constraint solved the same way — don't treat them as being in tension.
- Before adding ANY restriction, grep for restriction vocabulary as a self-check (the exact heuristic that caught both real violations in the sister plugin): `free version|pro |premium|upgrade|unlock|licen[cs]|trial|paid|limited|restrict|only .* can be|locked` plus numeric caps like `limit'? => [0-9]+`. If a numeric cap exists for a genuine technical reason (e.g. a dropdown performance guard), make it filterable and comment *why* it exists, don't just leave a bare number.
- Data model / DB schema should generally NOT be Free-vs-Pro gated at the table level — build the full schema in Free where it's cheap to do so (e.g. `shift_group` column exists in Free's own `wp_byzo_schedules` table even though Free's UI only ever writes `shift_group = 0`), and let Pro unlock the *UI affordance* and generation logic. This avoids painful schema migrations later and avoids the "code path exists but is artificially blocked" trap entirely, since the code path for extra shifts genuinely doesn't run without Pro's UI ever writing those rows.
- **Before adding any Pro-shaped feature or gating logic, check whether `bookyzo-pro` already owns that territory** (once the Pro plugin exists) — grep it first. The sister plugin once built a whole parallel, disconnected Pro-gating stub in the Free plugin because this check was skipped; caught late, had to be unwound.

## Vue admin shell

Both Free and Pro share ONE Vue 3 admin app (per the original brief — "admin panel Vue use korbe"). Build REST endpoints and Vue routes/components in Free from day one assuming Pro will add more routes to the same app, rather than Free shipping a separate PHP-rendered admin UI that Pro later has to replace. See `DETAILED-SPEC.md` §4 for the planned REST surface (`bookyzo/v1` namespace).

For any visual/CSS/component work on the admin app — tokens, colors, reusable Vue components, layout conventions — see the companion **`bookyzo-design`** skill (design tokens incl. brand color `#2563eb`, the `Byzo*` base component vocabulary, and the same never-show-a-locked-Pro-feature rule applied to the design layer).

## FEATURES.md discipline

Like the sister plugins, keep a `FEATURES.md` (Free) and eventually `bookyzo-pro/FEATURES.md` (Pro) **in English**, updated with every feature change — this is a standing preference of the plugin author, not optional. `DETAILED-SPEC.md` is the technical companion (schema/API/open-decisions); update its "Open Decisions" section as each item gets resolved rather than leaving stale unresolved-sounding text after a decision is actually made.

## Decided (don't re-ask, don't re-derive — just build)

- **ALL FOUR booking types ship in Free** (`DETAILED-SPEC.md` §5 item 5, decided 2026-09-02 — supersedes the earlier "Appointment first, other types later" staging). Pro gates extra features only, never booking types. Free's volume cap = one active resource per type (`byzo_max_resources_per_type`, so up to 4 active resources total). Appointment was built first as the reference implementation (ROADMAP.md Phase 1, complete); Table/Event/Slot land via **ROADMAP.md Phase 1.5** before the first WP.org submission.
- **Type-first Add New flow + per-resource shortcodes** (`DETAILED-SPEC.md` §5 item 6): resource creation picks the booking type FIRST, then shows type-specific fields (field matrix in §A2/A3); every resource's pinned shortcode is shown copyable in the Resources list.
- **Event = direct slot rows, no weekly schedule** (`DETAILED-SPEC.md` §5 item 7 and §1.3): event-type resources skip the generator; occurrences are admin-entered straight into `wp_byzo_slots`. Never wire event resources into the weekly-schedule path.
- **Timezone storage: site-local**, not UTC (`DETAILED-SPEC.md` §5 item 1) — all DATETIME columns hold WordPress's configured local time. Only becomes a live concern again once Pro's multi-location feature ships (per-location timezone must travel with any datetime read, see the full note in DETAILED-SPEC.md).
- **Brand color: `#2563eb`**, component library **shadcn-vue** — see `bookyzo-design` skill.

## Before writing PHP/Vue code for the first time in a session

1. Re-read `DETAILED-SPEC.md` §5 "Open Decisions" for anything that's changed or newly resolved since — don't assume this skill file's "Decided" section above is perfectly in sync; DETAILED-SPEC.md is the source of truth, this skill is a pointer to it.
2. Check `wp-content/plugins/` for whether `bookyzo-pro` exists yet — most early work is Free-only (Part C step 1–3 of `FEATURES.md` / Phase 0–1 of `ROADMAP.md`), so Pro-specific instructions above are forward-looking until that stage.
3. Check `ROADMAP.md`'s phase list for exactly which numbered step comes next — don't reorder or skip ahead without saying so.
