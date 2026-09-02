# Bookyzo — Build Roadmap

Ordered, dependency-aware step list: what to build, and in what sequence, from zero to the full multi-type Pro product. Companion to `FEATURES.md` (what) and `DETAILED-SPEC.md` (how/schema). This file answers **"which step comes after which."**

Scope for now: **Phase 0–2** (core engine + Appointment Free + Appointment Pro) is the actionable near-term plan. Phases 3+ are sequenced but intentionally light on detail until Phase 2 has real usage data — per `FEATURES.md` Part C's own principle, don't over-plan stages that are months away.

---

## Phase 0 — Core Engine (no public release)

Nothing here is type-specific. Build once, every booking type reuses it.

1. ✅ **DONE (2026-09-02)** — Plugin bootstrap: main file header, activation/deactivation hooks, `dbDelta` schema installer for the 4 core tables (`wp_byzo_resources`, `wp_byzo_schedules`, `wp_byzo_availability_exceptions`, `wp_byzo_slots`, `wp_byzo_bookings` — see `DETAILED-SPEC.md` §1), `byzo_settings` option defaults, uninstall cleanup. Also added: `BYZO_Capabilities` (the real capability-filter layer per the Guideline-5 rule, e.g. `max_resources_per_type()`/`max_shifts_per_day()`), setup-wizard activation-redirect scaffold (`BYZO_Install_Redirect`), placeholder admin menu page (Vue mount point for step 7), and DB-version-bump upgrade path in `BYZO_Plugin::maybe_upgrade_db()`. Verified via two scratchpad smoke tests: raw `BYZO_Activator::activate()` (table/column/settings checks) and a full-flow test through WP's own `activate_plugin()` + `plugins_loaded` (confirms hook wiring, capability filter override, clean deactivation). All passed.
2. ✅ **DONE (2026-09-02)** — REST API skeleton: namespace `bookyzo/v1`, auth/capability checks per route, base controller class pattern. Added `BYZO_REST_Controller` (abstract base — `NAMESPACE_V1` constant, `admin_permissions_check()` for capability-gated routes, `public_permissions_check()` reserved hook point for the guest-widget abuse guard per DETAILED-SPEC.md §4) and `BYZO_REST` (registers on `rest_api_init`, collects concrete controllers via the `byzo_rest_controllers` filter so Pro adds its own controllers without patching Free). Verified via `scratchpad/smoke-rest-skeleton.php`: a throwaway test controller registered through the real filter chain, confirmed route registration, 401 for unauthenticated requests, and 200 for an admin request — the full chain concrete controllers will use starting step 3.
3. ✅ **DONE (2026-09-02)** — `/resources` CRUD endpoint + `/resources/{id}/availability` endpoint (schedules + exceptions). Added `BYZO_Resource` (data-access layer over `wp_byzo_resources` — validate/create/update/delete/query with type+status filtering and pagination), `BYZO_Availability` (over `wp_byzo_schedules` + `wp_byzo_availability_exceptions` — full-weekly-schedule replace, blackout exception add/delete), and `BYZO_REST_Resources_Controller` (all routes: `GET/POST /resources`, `GET/PUT/DELETE /resources/{id}`, `GET/PUT /resources/{id}/availability`, `POST /resources/{id}/availability/exceptions`, `DELETE .../exceptions/{id}`). Both the Free "1 active resource per type" cap (§A9) and the Free "1 shift/day" cap (§A3/§1.2) are enforced here as real, filterable capability checks (`byzo_max_resources_per_type`, `byzo_max_shifts_per_day`) — confirmed blocking on Free defaults AND unblocking under a simulated Pro filter override, per two scratchpad smoke tests (`smoke-resources-crud.php`, `smoke-availability.php`, 19 checks total, all passed) exercising the real REST dispatcher end-to-end (create/read/update/delete, validation errors, cap enforcement, auth).
4. ✅ **DONE (2026-09-02)** — Slot generator (`BYZO_Slot_Generator`): weekly schedule × exceptions × buffer × min/max advance window → rows in `wp_byzo_slots`. Cron registration (`byzo_generate_slots` daily, plus `byzo_send_reminders` hourly reserved for Phase 1's reminder emails), idempotent upsert logic (`ON DUPLICATE KEY UPDATE`, keyed on the `resource_id + start_datetime` unique index from step 1). Also: (a) discovered and fixed a schema gap — `wp_byzo_resources` had no `duration_minutes` column despite DETAILED-SPEC.md §A9 saying Free is "fixed duration"; added it (default 30, user decision 2026-09-02) and bumped `BYZO_DB_VERSION` to `1.1` so `BYZO_Plugin::maybe_upgrade_db()` picks it up on existing installs; (b) blackout exceptions now actively flip already-materialized `open` slots to `blocked` (`BYZO_Slot_Generator::block_slots_on_dates()`) rather than only skipping future generation — a blackout added after slots already exist would otherwise leave stale bookable slots; (c) resource create, availability schedule updates, and exception adds all trigger immediate regeneration for that resource (`byzo_resource_created` action + direct calls) so a business doesn't wait up to 24h for the next cron tick to see a bookable widget. Verified via `scratchpad/smoke-slot-generator.php` (12 checks: duration/buffer slot math, idempotent re-run, blackout blocking without duplicating/deleting rows, cron registration, min-advance-notice cutoff) — all passed, plus both earlier smoke test suites re-run clean (no regressions from the schema change).
5. ✅ **DONE (2026-09-02)** — `/slots` public read endpoint (filtered by resource + date range). Added `BYZO_Slot` (read-only data layer — date-range query defaulting `from`=today/`to`=`max_advance_days` out, `open_only` filter requiring `status='open' AND capacity_remaining>0`, the shape the public booking widget needs) and `BYZO_REST_Slots_Controller` (`GET /slots?resource_id=&from=&to=`, gated by `public_permissions_check()` — the guest-allowed permission callback reserved in step 2, not `admin_permissions_check()`). Inactive/archived/non-existent resources all return the same 404 rather than leaking resource-status via a different error shape. Verified via `scratchpad/smoke-slots-endpoint.php` (8 checks: unauthenticated/guest access succeeds, slot shape + duration correctness, validation errors, 404 on missing/inactive resource, date-range narrowing, full-capacity slots excluded from results) — all passed, plus all 3 earlier smoke suites re-run clean.
6. `/bookings` endpoint: POST (create, guest-allowed) + GET (admin list) + PUT (status transition state machine).
7. Vue 3 admin shell: build tooling (Vite), mount point in wp-admin, router skeleton with one placeholder route, `wp.apiFetch` wrapper talking to the REST API above.
8. Smoke tests (scratchpad CLI scripts loading `wp-load.php`, matching the sister-plugin testing convention): create a resource, generate slots, book one, transition its status, confirm slot capacity decrements correctly.

**Exit criteria for Phase 0:** a resource can be created, its availability set, slots generated by cron, and a booking made and status-changed — all via REST, no UI polish yet, not on WordPress.org.

---

## Phase 1 — Appointment Free (first WordPress.org release)

Builds ON Phase 0. Nothing here should require touching the 4 core tables' schema — only UI + type-specific defaults.

1. Setup wizard: booking-type choice (Appointment pre-selected/only option for v1 build, but keep the radio UI generic since Table/Event/Slot will reuse it in Phase 3), business profile form, first-staff-member walkthrough.
2. Resource admin screen (Vue): staff list, create/edit form (name, photo, description, tags, capacity=1 fixed for Appointment, price, status).
3. Availability admin screen (Vue): weekly grid (one shift/day), exceptions calendar picker.
4. Booking calendar admin screen (Vue): day/week/month view, manual booking modal.
5. Booking list admin screen: filters (date/status/resource), status change actions, CSV export.
6. Public booking widget: Gutenberg block + shortcode, 4-step Vue flow (staff/service → slot → details → confirm), guest flow with signed-token confirmation link.
7. Notifications: confirmation/reminder/cancellation emails, plain-text template editing screen, 24h-before reminder cron.
8. Payments: manual "mark as paid," Stripe Elements one-gateway checkout on the confirmation step, webhook handler.
9. Basic reporting: bookings-count widget, CSV export.
10. `readme.txt` + WordPress.org submission prep (screenshots, plugin-check run, following the exact process `bundle-product-plugin` went through — expect at least one review round).

**Exit criteria for Phase 1:** Appointment Free is a complete, submittable WordPress.org plugin. This is the first public release.

---

## Phase 2 — Appointment Pro (validate willingness to pay)

Per `FEATURES.md` Part C step 3 — do NOT start this until Phase 1 is live and has some real installs/feedback. Building Pro against a Free plugin nobody has used yet risks building the wrong things.

1. Pro plugin scaffold: `bookyzo-pro` folder, `BYZOP_` prefix, license-server integration reusing the exact pattern from `license-envato-pro-project` (activate/deactivate/update-check endpoints, self-update mechanism, `byzop_license_key`/`byzop_license_status` options).
2. Multi-staff: remove the `byzo_max_resources_per_type` cap via Pro's filter override (see Guideline-5 note below — this must be the ONLY thing that changes, no Free-side UI edits).
3. Multiple shifts per day: unlock the "+ Add shift" UI affordance in the availability grid (schema already supports `shift_group` from Phase 0).
4. Per-staff individual schedules overriding the business default.
5. "Any available staff" auto-assignment + staff-specific service/pricing.
6. Deposits/partial payments + PayPal as a second gateway.
7. SMS + WhatsApp Cloud API notifications, customizable multi-reminder timing.
8. Customer dashboard (self-service view/reschedule/cancel), built on the signed-token mechanism already in Free.
9. Staff calendar sync (Google/Outlook two-way).
10. Analytics dashboard: revenue, occupancy rate, no-show tracking, repeat-customer metrics.
11. Drag-and-drop reschedule, bulk actions, booking notes/tags.

**Exit criteria for Phase 2:** Appointment Pro is sellable, licensing works end-to-end, and there's a real Free→Pro upgrade path a paying customer can walk through.

---

## Phase 3 — Second Booking Type (Table or Slot)

Not detailed yet — sequence only, per `FEATURES.md` Part C step 4. Candidate order to revisit once Phase 2 has traction: Table (restaurant reservations) is architecturally closest to Appointment (recurring resource + weekly schedule), so it's the cheaper second type to add; Event is the most different (listing-like, not a recurring resource) and is likely the more expensive one to bolt on. Decide the actual order when this phase starts, informed by which niche Phase 1/2 customers are actually asking for.

## Phase 4 — Third/Fourth Type + "All-in-One" Positioning

Not detailed yet — per `FEATURES.md` Part C step 5. Only start marketing the all-in-one angle once 2+ types are live and stable.

## Phase 5 — WooCommerce Bridge

Not detailed yet — per `FEATURES.md` Part C step 6. Schema boundary question (own mapping table vs. `wc_order_id` column on `wp_byzo_bookings`) deferred to this phase, see `DETAILED-SPEC.md` §5 item 4.

## Phase 6 — Agency / Multi-Site Tier

Not detailed yet — per `FEATURES.md` Part C step 7. Last, once there's a base of paying single-site Pro customers.

---

## Free → Pro Conversion Strategy

**Principle:** every Pro feature must be something a Free user genuinely *runs into as a wall* through normal, growing use of their business — not something Claude or the plugin dangles in front of them with a lock icon. This is a hard constraint, not just good marketing: `bundle-product-plugin`'s WordPress.org submission was **rejected twice for Guideline 5 (Trialware)** specifically because it showed visible locked/disabled Pro UI in the Free plugin. See `.claude/skills/bookyzo-dev/SKILL.md` for the enforcement rules. The two goals (avoid Trialware rejection, and make Pro genuinely desirable) turn out to be the *same* goal solved the *same* way: real limits tied to real growth, discovered by hitting them, not by seeing them.

The upgrade motivation has to come from the **shape of Free's real limits**, each one a natural business-growth trigger:

| Free limit (real, not artificial) | What triggers the Free user to feel it | Why Pro's version solves it |
|---|---|---|
| One resource (staff/table/etc.) active | Business hires a 2nd staff member or opens a 2nd table | Pro: unlimited resources |
| One shift per day | Business adds a lunch+dinner split, or a staff member works split shifts | Pro: multiple shifts/day |
| One booking type active | Business realizes they also want to sell event tickets or rent equipment through the same site | Pro: all types simultaneously |
| Single location, no per-staff schedule override | Business opens a 2nd branch, or one staff member's hours differ from the shop default | Pro: multi-location + per-staff schedules |
| No deposits, one gateway | Business gets burned by no-shows on unpaid bookings, or customers ask for PayPal | Pro: deposits + multiple gateways |
| Email-only, single fixed reminder | Customers don't read email reminders and still no-show; business wants a 48h+2h double reminder | Pro: SMS/WhatsApp + multi-timing reminders |
| No customer self-service | Business gets flooded with "can I reschedule?" phone calls/emails | Pro: customer dashboard |
| No booking history/repeat-customer detection | Business wants to recognize/reward repeat customers, can't currently see who's a repeat | Pro: customer profiles + retention analytics |
| Basic count-only reporting | Business wants to know revenue by resource, occupancy rate, no-show trends to make staffing/pricing decisions | Pro: full analytics dashboard |
| No recurring bookings/waitlist | Business runs a weekly class or is fully booked and losing customers who'd rebook if notified | Pro: recurring series + waitlist |

**Practical implication for build order**: because these limits are real (not cosmetic), Free must be built with the *unlimited* version's data model in mind from day one (see `DETAILED-SPEC.md`'s repeated "don't gate the data model, gate the UI/capability" pattern) so that removing a limit in Pro is a filter override, never a schema migration or a rewrite. This is also why Phase 0 builds the full schema (multi-shift columns, etc.) even though Phase 1's UI only ever uses a subset of it.

**What NOT to do**, restated from the skill file because it's the single most expensive mistake the sister plugin made twice: never show a Pro feature as visible-but-disabled in Free with an upsell nag. If Free doesn't have a feature, it's just absent — the "wall" the user hits is a real operational need (can't add a 2nd staff member), not a UI element telling them no.
