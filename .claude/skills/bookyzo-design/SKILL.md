---
name: bookyzo-design
description: Use whenever building, styling, or reviewing the Bookyzo Vue 3 admin panel (calendar, resource/availability screens, booking list, settings, dashboard) — design tokens, component patterns, and layout conventions to keep the whole admin app visually consistent as it's built screen by screen.
---

# Bookyzo admin design system

Bookyzo's admin panel is a **Vue 3 SPA mounted inside wp-admin** (see `ROADMAP.md` Phase 0 step 7, `DETAILED-SPEC.md` §4). This skill exists because a Vue app built screen-by-screen across many sessions drifts visually unless every session works from the same tokens and component vocabulary from the start — don't invent ad hoc colors/spacing/radii per screen.

This is a **from-scratch design system**, not a copy of a PHP-rendered admin page. Adapt the *pattern* below (tokens → primitives → composed views) as real Vue single-file components, not as literal CSS classes bolted onto server-rendered HTML.

## Brand

**Primary brand color: `#2563eb`** (deep blue) — decided 2026-09-02, deliberately distinct from the author's other Codeholt products (`license-envato`'s indigo `#4f46e5` / green `#72b34d`, `bundle-product-for-woocommerce`'s own palette) so Bookyzo has its own visual identity. Use this exact hex as `--byzo-primary`. Do not reuse the other products' brand colors here even by convenience-copy — confirmed deliberately different by the user.

## Design tokens (define once, e.g. `assets/css/tokens.css` or a Vue `:root` style, imported by the admin app's root component)

Model: CSS custom properties, same *shape* as `license-envato`'s proven `admin.css` token set (semantic naming, light/soft-shadow aesthetic, pill badges, gradient icon badges) — but Bookyzo's own values:

```css
:root {
  --byzo-primary: #2563eb;
  --byzo-primary-dark: #1d4ed8;
  --byzo-primary-light: #eff6ff;

  --byzo-success: #16a34a;
  --byzo-success-bg: #f0fdf4;
  --byzo-danger: #dc2626;
  --byzo-danger-bg: #fef2f2;
  --byzo-warning: #d97706;
  --byzo-warning-bg: #fffbeb;

  --byzo-text: #1d2327;
  --byzo-text-muted: #6b7280;
  --byzo-border: #e7e8ec;
  --byzo-surface: #ffffff;
  --byzo-surface-alt: #f8f9fc;

  --byzo-radius: 14px;
  --byzo-radius-sm: 8px;
  --byzo-shadow: 0 1px 2px rgba(16,24,40,.04), 0 2px 10px rgba(16,24,40,.06);
  --byzo-shadow-md: 0 10px 28px rgba(16,24,40,.12);
  --byzo-shadow-lg: 0 16px 40px rgba(16,24,40,.16);
}
```

Booking-status colors (used constantly — calendar, booking list, badges — define these alongside the base tokens, not per-component):

```css
:root {
  --byzo-status-pending: #d97706;    /* warning */
  --byzo-status-confirmed: #2563eb;  /* primary */
  --byzo-status-completed: #16a34a;  /* success */
  --byzo-status-cancelled: #9ca3af;  /* muted gray, not danger-red — a cancellation isn't an error state */
  --byzo-status-no-show: #dc2626;    /* danger — this one IS a problem state, unlike a normal cancellation */
}
```

## Component library: shadcn-vue

Bookyzo's admin app uses **shadcn-vue** (https://www.shadcn-vue.com) as the base component layer instead of hand-rolling every primitive from scratch. This fits the token-driven approach above well: shadcn-vue is copy-in (via its CLI, components land as real `.vue` files under `src/components/ui/`, not an opaque npm dependency), built on Reka UI (headless/unstyled logic) + Tailwind, and themed entirely through CSS variables — so the `--byzo-*` tokens above map directly onto shadcn-vue's own CSS-variable theming convention rather than fighting it.

Practical setup notes:
- Install/add components via the shadcn-vue CLI (`npx shadcn-vue@latest add button card badge dialog ...`) into the Vue admin app's source tree, not by hand-copying from the docs site.
- Map the `--byzo-*` tokens onto shadcn-vue's expected theme variables (`--primary`, `--background`, `--border`, `--radius`, etc. — shadcn-vue's `init` step generates a base `:root`/`.dark` block; overwrite those values with the Bookyzo tokens rather than keeping shadcn's own default indigo/zinc palette). Keep the `--byzo-*` names too as the semantic layer the rest of this doc (status colors, etc.) refers to — a thin mapping, not a rename.
- Tailwind comes along with shadcn-vue by default — fine to use for layout/spacing utility classes in screen-level components, but keep color/radius/shadow values sourced from the CSS variables (via `theme()` or Tailwind's CSS-var-backed classes like `bg-primary`), never new hardcoded hex values dropped into a `class="..."` string.
- Because shadcn-vue components are copied into the repo, they're editable directly — fine to adjust a component's internals for a Bookyzo-specific need (e.g. `ByzoBadge` below is just a themed wrapper/preset over shadcn-vue's `Badge`, not a from-scratch component).
- Dark mode: shadcn-vue ships light/dark out of the box via its `.dark` variable block — decide whether wp-admin context needs it at all (WP admin itself doesn't really have a dark-mode convention) before spending time theming it; low priority, don't build it speculatively.

## Component vocabulary (thin, Bookyzo-specific wrappers/presets over shadcn-vue primitives, under `src/components/base/`)

Reuse these across every admin screen rather than re-deriving per-page styles. Each maps to a shadcn-vue primitive plus Bookyzo-specific defaults/props — build the wrapper once, don't reconfigure the raw shadcn-vue component ad hoc on every screen:

- **`ByzoPageHeader`** — icon badge (gradient `--byzo-primary`→`--byzo-primary-dark`) + title + subtitle + trailing action slot. Not a shadcn-vue primitive (no direct equivalent) — a Bookyzo-specific composite. Every top-level admin route (Dashboard, Resources, Calendar, Bookings, Settings) starts with one.
- **`ByzoCard`** — preset over shadcn-vue's `Card`. `--byzo-surface` bg, `--byzo-border` border, `--byzo-radius`, `--byzo-shadow`, hover → `--byzo-shadow-md`. The base container for every panel.
- **`ByzoStatCard`** / **`ByzoStatGrid`** — `ByzoCard` composite for dashboard metrics (today's bookings, this week's revenue once Pro analytics exists, etc.), `auto-fit minmax(220px,1fr)` grid.
- **`ByzoBadge`** — preset over shadcn-vue's `Badge`. Status pills for booking status (`pending`/`confirmed`/`completed`/`cancelled`/`no_show`), using the status tokens above via a `status` prop, not ad hoc colors per usage site.
- **`ByzoButton`** — preset over shadcn-vue's `Button`. Primary (gradient `--byzo-primary`), secondary (bordered), danger variants. Use shadcn-vue's own `variant` prop system rather than inventing a parallel one.
- **`ByzoModal`** — preset over shadcn-vue's `Dialog`. Booking detail view, manual-booking-creation dialog (see `DETAILED-SPEC.md` §A4), confirmation dialogs.
- **`ByzoCalendar`** — day/week/month booking calendar (`FEATURES.md` §A4). shadcn-vue has no calendar/scheduling-grid primitive suited to this (its `Calendar`/`RangeCalendar` are date-pickers, not event-grid views) — this is a genuinely custom component, and the single most load-bearing one in the whole admin app. Get its visual language (event blocks colored by `--byzo-status-*`, time-grid lines using `--byzo-border`) right early since Booking Management, and later Pro's drag-and-drop reschedule (`FEATURES.md` §B3), both build directly on it. Still theme it with shadcn-vue's own CSS variables/Tailwind classes rather than a separate one-off stylesheet, so it doesn't visually drift from the rest of the app.
- **`ByzoWeeklyScheduleGrid`** — the 7-day availability editor (`DETAILED-SPEC.md` §A2/A3), custom-built (compose from shadcn-vue's `Select`/time-input primitives, no direct equivalent exists). Free renders one shift-row per day; Pro's "+ Add shift" (`ROADMAP.md` Phase 2 step 3) extends the *same* component with multiple rows per day, so build it to accept N shifts per day from the start even though Free's UI only ever adds one (mirrors the "don't gate the data model" principle from `DETAILED-SPEC.md`/the `bookyzo-dev` skill — applies to component design too, not just schema).

Straightforward shadcn-vue primitives to use directly where no Bookyzo-specific preset is needed yet: `Input`/`Textarea`/`Select` (booking widget form fields, settings screens), `Tabs` (settings sections), `Table` (booking list — or WP's own list-table look, see Layout conventions below; decide per-screen), `Popover`/`Command` (resource/date pickers), `Toast`/`Sonner` (save-confirmation feedback). Wrap into a `Byzo*` preset only once a real Bookyzo-specific default (color, size, copy) would otherwise be repeated across multiple screens — don't pre-wrap everything speculatively.

## Locked/Pro-teaser UI: do not build this pattern at all

Unlike `license-envato-pro`'s admin (which uses red-bold "Get Pro"/"Activate License" upsell links and a Free-vs-Pro comparison table page — both fine, because they're separate, honest pages, not disabled/greyed-out real features), **never build a visually-disabled or lock-icon version of a Pro-only Vue component inside the Free admin bundle.** This is the same Guideline 5 (Trialware) constraint as the `bookyzo-dev` skill's code-level rule, applied to the design layer: if Pro's `ByzoWeeklyScheduleGrid`-with-multiple-shifts, drag-and-drop calendar, analytics dashboard, etc. isn't installed, that UI simply doesn't exist in the DOM — no dimmed preview, no padlock badge on a stat card. A genuine separate "Upgrade" page/tab (like license-envato's Get Pro comparison table) is fine and encouraged; a fake/disabled rendering of the real feature is not.

## Layout conventions

- wp-admin chrome (WP menu/toolbar) stays native — the Vue app mounts into the page content area only, same as any other wp-admin screen, not a full takeover.
- Internal navigation (Dashboard / Resources / Calendar / Bookings / Settings) is a client-side Vue Router inside one top-level wp-admin menu page, not five separate `add_submenu_page()` PHP routes each bootstrapping their own Vue instance — keeps state (e.g. selected date range) alive across navigation and avoids five separate script bundles.
- Match native WP admin conventions where Vue doesn't have a strong reason to diverge (list-table-style rows for the booking list, WP's own date-picker conventions) — the goal is "feels like a well-designed part of wp-admin," not "feels like an embedded unrelated web app."

## Before building a new admin screen

1. Check whether an existing `Byzo*` base component already covers the need — don't create a one-off styled `<div>` when `ByzoCard`/`ByzoBadge`/etc. already exists.
2. If a genuinely new primitive is needed, add it to `src/components/base/` with the same token-driven approach (no hardcoded hex/px values inside a screen-level component — pull from the CSS custom properties above).
3. Cross-check `ROADMAP.md`'s phase list — building a Pro-only screen's visuals before Phase 2 starts is premature; Free-phase work (Phase 1) only needs the base components list above minus anything explicitly Pro-only.
