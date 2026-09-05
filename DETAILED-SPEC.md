# Bookyzo — Detailed Technical Spec

Companion to `FEATURES.md` (the feature list). This document breaks each feature down into data model, DB schema, UI flow, and open decisions needed **before coding starts**. Keep this file in English (matches [[bundle-product-plugin]] convention).

> **COMPETITOR GAP-FILL 2026-09-03:** schema/API companion to the same-day `FEATURES.md` update (17 items added after auditing Bookly, Booking Calendar, and Amelia). New entity: `wp_byzo_customers` (§1.5, Free — needed because "import customers from CSV" requires real customer identity, not just denormalized fields on bookings). New Free-schema columns: `bookable_items.tax_rate_override`, `bookable_items.wp_user_id`, `bookings.customer_id`, `bookings.tax_amount`, `bookings.cart_id` — added to Free's own tables now, left unused by Free's UI, per the established "don't gate the data model" pattern (§1.2's `shift_group` precedent). New Pro-only tables (§1.6): `wp_byzo_pricing_rules`, `wp_byzo_webhooks`, `wp_byzo_custom_fields`. New REST endpoints in §4. One genuinely **open** question added to §5 (multilingual content storage) rather than silently decided, since it has real tradeoffs.

> **ONBOARDING & DASHBOARD DEEPENED 2026-09-03:** companion to the same-day `FEATURES.md` A1/A4/A6/A8 rewrite (user-supplied wizard+dashboard spec, reconciled — see that doc's callout for the 3 deliberate deviations: 4 types not 7/no custom type, Availability stays bookable item-scoped, Revenue added to Free as one total not a breakdown). New `byzo_settings` fields: `auto_confirm`, `require_phone`, `send_confirmation_email`, `send_admin_notification`. §A1 rewritten with the new wizard step components. §A8's `get_dashboard_summary()` gains a revenue field.

Naming convention used throughout this doc: prefix `BYZO_` (classes) / `byzo_` (functions, hooks, options, meta keys, DB table names), text domain `bookyzo`. Pro add-on: `BYZOP_` / `byzop_`, folder `bookyzo-pro`. (Matches the `BPFW`/`BPFWP` → `codeholt-bundles-for-woocommerce` precedent — short prefix now, can be renamed later if WordPress.org flags genericness, same as CBFW rename history.)

---

## 1. Core Data Model

Bookyzo is NOT built on WooCommerce products. It uses its own custom DB tables (per the original brief: "WooCommerce-independent core"). Four core entities:

### 1.1 `wp_byzo_bookable_items` — the bookable thing (staff / table / room / event / rental item)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| type | VARCHAR(20) | `appointment`, `table`, `event`, `slot` — ALL FOUR available on Free (direction change 2026-09-02, §5 item 5); Free's cap is one active *bookable item* per type (enforced in PHP via `BYZO_Capabilities::max_bookable_items_per_type()`, not schema). Type is chosen FIRST in the Add New flow and immutable after creation (changing it would orphan the schedule/slots). |
| name | VARCHAR(255) | |
| description | TEXT | |
| photo_id | BIGINT UNSIGNED | attachment ID |
| tags | VARCHAR(255) | comma-separated, Free-tier simple tagging |
| capacity | SMALLINT UNSIGNED | 1 = single person/seat, N = party/group size |
| price | DECIMAL(10,2) NULL | flat price for the bookable item's single service (Free's single-service-per-type model, see §A7); NULL = no price set / free booking |
| duration_minutes | SMALLINT UNSIGNED | slot length for this bookable item's single service (Free's single-service-per-type model, fixed duration per §A9); default 30. Added 2026-09-02 — the slot generator (§1.3) needs a concrete duration per bookable item, "fixed duration" in §A9 was previously prose-only with no schema column. |
| status | VARCHAR(20) | `active`, `inactive`, `archived` |
| location_id | BIGINT UNSIGNED NULL | NULL on Free (single location); FK to `wp_byzo_locations` on Pro |
| group_id | BIGINT UNSIGNED NULL | Pro: bookable item grouping/category |
| tax_rate_override | DECIMAL(5,2) NULL | Added 2026-09-03 (competitor gap-fill, B6). NULL on every Free bookable item — Free always falls back to the site-wide `byzo_settings.tax_rate` (A7). Pro's UI is the only thing that ever writes a non-NULL value here (per-service tax override). |
| wp_user_id | BIGINT UNSIGNED NULL | Added 2026-09-03 (competitor gap-fill, B1 "multi-user staff logins"). NULL on Free. Pro links a bookable item to a WordPress user account so that user's admin view is filtered to only their own bookable item's bookings. |
| created_at, updated_at | DATETIME | |

### 1.2 `wp_byzo_availability` — recurring weekly schedule + exceptions
Two sub-tables:

**`wp_byzo_schedules`** (recurring weekly pattern)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| bookable_item_id | BIGINT UNSIGNED | FK |
| weekday | TINYINT | 0=Sun..6=Sat |
| start_time | TIME | |
| end_time | TIME | |
| shift_group | TINYINT | Free: always `0` (one shift/day enforced in PHP). Pro: 0,1,2... for multiple shifts (lunch/dinner) |

**`wp_byzo_availability_exceptions`** (blackout / holiday overrides)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| bookable_item_id | BIGINT UNSIGNED NULL | NULL = business-wide holiday |
| date | DATE | |
| is_closed | TINYINT(1) | 1 = fully blocked that day |
| start_time, end_time | TIME NULL | if not fully closed, override hours for that date |

**Business-level settings** (options, not a table): `byzo_settings` option — business name, timezone, currency, default working hours, min advance notice (hours), max advance window (days), buffer time (minutes).

### 1.3 `wp_byzo_slots` — generated bookable time slots (materialized, not computed on the fly)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| bookable_item_id | BIGINT UNSIGNED | FK |
| start_datetime | DATETIME | stored in site-local time (WordPress site timezone — see §5 item 1) |
| end_datetime | DATETIME | |
| capacity_remaining | SMALLINT UNSIGNED | decremented on booking, for capacity>1 bookable items |
| status | VARCHAR(20) | `open`, `full`, `blocked` |

Generated by a background cron (`byzo_generate_slots` scheduled event) on a **rolling window** — e.g. always keep the next `max_advance_days` of slots materialized, regenerate nightly. Reason to materialize rather than compute on the fly: booking a slot must atomically decrement `capacity_remaining` (race-condition safety on concurrent bookings), which needs a real row to lock/update.

**Event-type bookable items bypass the generator entirely (decided 2026-09-02, §5 item 7).** An event is one or more *specific dated occurrences* ("Oct 15, 7pm, 100 seats"), not a weekly recurring pattern — so for `type = event` bookable items: no `wp_byzo_schedules` rows, no Availability/Exceptions tabs; the admin enters occurrences directly (a "Dates" tab on the bookable item form), and each occurrence is written **straight into `wp_byzo_slots`** (`start_datetime`/`end_datetime` per occurrence, `capacity_remaining` seeded from `bookable_item.capacity`). `BYZO_Slot_Generator` must skip `type = event` bookable items (both the nightly cron and the regenerate-on-save triggers — otherwise cleanup_future_open_slots-style logic could delete manually entered occurrences). Zero schema change needed: the materialized-slots model already fits one-off occurrences perfectly; booking, capacity decrement, and the public `/slots` read all work unchanged. **Built 2026-09-03 (ROADMAP.md Phase 1.5 step 5)**: `BYZO_Slot::create_occurrence()`/`delete_occurrence()`/`list_for_bookable_item()`, admin REST routes `GET/POST /bookable-items/{id}/slots` + `DELETE .../slots/{slot_id}` (§4), `BYZO_Slot_Generator::generate_for_bookable_item()`'s single early-return for `type = event`, and the "Dates" tab (`BookableItemDatesTab.vue`) replacing Availability/Exceptions on the bookable item edit screen for event items.

### 1.4 `wp_byzo_bookings` — the actual booking record
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| slot_id | BIGINT UNSIGNED NULL | FK; NULL if slot was later deleted (booking still retained for records) |
| bookable_item_id | BIGINT UNSIGNED | denormalized for reporting even if slot is gone |
| customer_id | BIGINT UNSIGNED NULL | Added 2026-09-03 (competitor gap-fill, §1.5). FK to `wp_byzo_customers`, upserted by email at booking-creation time. Nullable so nothing breaks if a customer row is later deleted; `customer_name`/`customer_email`/`customer_phone` below stay the source of truth for what THIS booking actually recorded even if the customer's live profile changes later. |
| customer_name, customer_email, customer_phone | VARCHAR | |
| customer_notes | TEXT | |
| party_size | SMALLINT UNSIGNED | for Table/group bookings |
| status | VARCHAR(20) | `pending`, `confirmed`, `completed`, `cancelled`, `no_show` |
| payment_status | VARCHAR(20) | `unpaid`, `paid_manual`, `paid_online`, `refunded` |
| payment_amount | DECIMAL(10,2) | |
| tax_amount | DECIMAL(10,2) NULL | Added 2026-09-03 (competitor gap-fill, A7/B6). The tax portion of `payment_amount`, broken out for invoice display — `payment_amount = price × party_size × (1 + effective_tax_rate)`, `tax_amount = payment_amount − price × party_size`. `effective_tax_rate` = the bookable item's `tax_rate_override` if set, else `byzo_settings.tax_rate`. NULL when no tax rate is configured (most Free installs). |
| cart_id | VARCHAR(64) NULL | Added 2026-09-03 (competitor gap-fill, B6 "cart checkout"). Bookings sharing a `cart_id` were created and paid together in one checkout transaction. NULL for a normal single-booking checkout (Free's only path today). Pro's cart flow generates one `cart_id` (random token, not sequential — avoids leaking cart volume), creates N booking rows with it, and opens a single Stripe PaymentIntent for their summed `payment_amount`; the webhook flips every booking sharing that `cart_id` to paid together. |
| custom_fields | LONGTEXT (JSON) | Free: name/email/phone/notes fixed; JSON reserved for Pro custom-field builder — keyed by `wp_byzo_custom_fields.id` (§1.6), added 2026-09-03 |
| created_at, updated_at | DATETIME | |
| cancelled_at | DATETIME NULL | |

**Indexes needed**: `bookable_item_id + start_datetime` composite on slots (calendar queries), `status` on bookings (admin filters), `customer_email` on bookings (Pro customer history lookup), `customer_id` on bookings (added 2026-09-03, same lookup once customers are first-class), `cart_id` on bookings (added 2026-09-03, cart-group lookups on webhook).

### 1.5 `wp_byzo_customers` — customer identity (added 2026-09-03, competitor gap-fill A10/B3)

Free-owned table (created by Free's own `dbDelta` activator, not gated to Pro) — needed because "import an existing customer list from CSV" (A10) requires a customer to exist independently of any booking, which the previous booking-only model couldn't represent.

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| name | VARCHAR(255) | |
| email | VARCHAR(255) | unique index — the natural key for upsert-on-booking and CSV import de-duplication |
| phone | VARCHAR(50) NULL | |
| notes | TEXT NULL | |
| tags | VARCHAR(255) NULL | comma-separated, mirrors `bookable_items.tags`'s Free-tier simple tagging |
| source | VARCHAR(20) | `booking` (auto-created the first time this email books) or `import` (created via A10's CSV import with no booking yet) |
| created_at, updated_at | DATETIME | |

**Booking creation upserts this table by email**: on `POST /bookings`, look up `wp_byzo_customers` by `customer_email`; if found, set `bookings.customer_id` to it (and refresh `name`/`phone` if changed — the customer record tracks their *current* details, the booking row keeps what was true *then*); if not found, insert a new customer row with `source = 'booking'`. CSV import (A10) inserts rows with `source = 'import'` directly, no booking involved. This table is what B3's "customer booking history and profile" and B3's "export customer list to CSV, including booking and payment history" (added 2026-09-03) actually query — `JOIN wp_byzo_bookings ON customer_id`.

---

### 1.6 Pro-only tables (competitor gap-fill, added 2026-09-03)

Created by Pro's own activation hook, following the exact pattern §3 already establishes for `wp_byzo_locations`/`wp_byzo_booking_series`/`wp_byzo_waitlist` — genuinely Pro-exclusive relational data, not a cheap nullable column, so it doesn't belong in Free's schema.

**`wp_byzo_pricing_rules`** (B6 seasonal/dynamic pricing)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| bookable_item_id | BIGINT UNSIGNED NULL | NULL = applies business-wide |
| rule_type | VARCHAR(20) | `season` (date range), `weekday`, or `lead_time` (early-bird / last-minute) |
| start_date, end_date | DATE NULL | for `season` |
| weekday | TINYINT NULL | 0=Sun..6=Sat, for `weekday` |
| lead_time_hours | INT NULL | for `lead_time` — hours between booking-made and slot-start |
| lead_time_comparison | VARCHAR(10) NULL | `before` (early-bird discount) or `after` (last-minute) |
| adjustment_type | VARCHAR(10) | `fixed` or `percent` |
| adjustment_value | DECIMAL(10,2) | |
| adjustment_direction | VARCHAR(10) | `increase` or `decrease` |
| priority | SMALLINT | lower = applied first when multiple rules match the same booking |

**`wp_byzo_webhooks`** (B9 webhooks)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| url | VARCHAR(500) | |
| events | LONGTEXT (JSON) | array of event names, e.g. `["booking_created","booking_cancelled"]` — matches the `byzo_booking_status_changed`/`byzo_booking_created` action names already fired by Free (§3) |
| secret | VARCHAR(64) | HMAC-signs the outgoing payload the same way `BYZO_Stripe::verify_webhook_signature()` already verifies Stripe's *incoming* one — same technique, opposite direction |
| active | TINYINT(1) | |
| created_at | DATETIME | |

**`wp_byzo_custom_fields`** (B11 advanced form builder)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| bookable_item_id | BIGINT UNSIGNED NULL | NULL = shown on every booking form; set = scoped to one bookable item |
| label | VARCHAR(255) | |
| field_type | VARCHAR(20) | `text`, `dropdown`, `checkbox`, `file`, `date`, `number`, `address` |
| options | LONGTEXT (JSON) NULL | choices for `dropdown`, or config like "required"/Google-Maps-linked for `address` |
| required | TINYINT(1) | |
| sort_order | SMALLINT | |

Submitted values are stored in `wp_byzo_bookings.custom_fields` (§1.4), keyed by this table's `id`.

---

## 2. Free Plugin — Feature-by-Feature Breakdown

### A1. Setup Wizard

**Rewritten 2026-09-03, built same day (ROADMAP.md Phase 1.5 step 6)** — was 3 steps (type → business profile → first bookable item), now the fuller 9-step flow `FEATURES.md` A1 documents. Component breakdown, extending the existing `SetupWizard.vue` shell:

- Triggered on activation redirect (`byzo_activation_redirect`, standard WP pattern — check `is_network_admin`/bulk-activate guard like most plugins do, to avoid redirect spam)
- `WelcomeStep.vue` — one line of framing, no fields
- `BookingTypeStep.vue` — now genuinely 4 real options (was hardcoded to Appointment-only prose despite the type already being real elsewhere — a stray oversight from Phase 1.5 steps 3-5, which updated `BookableItemTypeStep.vue` and the block editor's picker but missed this third copy of the same list; fixed here). Now also emits the chosen value (`next(selected)`) — previously didn't communicate it to the parent at all, since the old 3-step wizard silently hardcoded `type: 'appointment'` downstream.
- Business profile form — unchanged position and behavior (saves immediately via its own `PUT /settings` on Continue, as before); now also emits `{ currency, default_start_time, default_end_time }` on `next` so later steps (Availability's defaults, Pricing's currency label) don't need to re-fetch `/settings` themselves.
- `BookingDetailsStep.vue` (renamed/repurposed from `FirstBookableItemStep`'s field portion) — name/description/duration/capacity per A2's field matrix (duration hidden for Event, capacity hidden for Appointment, same rules as `BookableItemDetailsTab.vue`), type-appropriate defaults (Appointment → "Staff Member 1", Table → "Table 1", Event → "My Event", Slot → "Bookable Item 1"). **Price moved entirely to PricingStep** — the source spec listed price under both steps; asking twice would be redundant, so Booking Details stays purely descriptive/operational and Pricing is the one place money gets set. `FEATURES.md`'s Step 1 bullet updated to match (price removed from its list).
- `AvailabilityStep.vue` **(new)** — reuses `ByzoWeeklyScheduleGrid` (already built for the bookable item edit screen's Availability tab, A2/A3), seeded from the business profile's default hours on every day (the owner trims from there, rather than the old silent-apply). Event type shows a single date+start+end occurrence entry instead — no weekly grid, matching §1.3 — which the Create action below turns into one `POST .../slots` call instead of a schedule PUT.
- `CustomerFieldsStep.vue` **(new)** — a single toggle, "Require phone number?" (name/email are hardcoded required, never a toggle). Writes `byzo_settings.require_phone`, enforced in `BYZO_Booking::validate_for_create()` (400 `byzo_missing_phone` when set and no phone given — applies to admin manual bookings too, same route, no special-casing). No custom-field UI — Free has none (Guideline 5).
- `PricingStep.vue` **(new)** — free-or-paid toggle; if paid, price (writes to the bookable item, not settings; label becomes "Ticket price" for Event, matching `BookableItemDetailsTab.vue`). Currency read-only here, inherited from the business-profile step. **Tax rate deliberately NOT asked here** — `byzo_settings.tax_rate` belongs to Phase 1.5 step 9 (competitor gap-fill), not built yet; adding the wizard field ahead of the feature it configures would be a setting nobody reads. Revisit once step 9 lands — this step's own component is where it'll naturally go.
- `ConfirmationStep.vue` **(new)** — auto-confirm toggle (`byzo_settings.auto_confirm`) + two notification toggles (`byzo_settings.send_confirmation_email`, `byzo_settings.send_admin_notification`, both default `true`). **Cancellation policy deliberately NOT asked here** either — `byzo_settings.cancellation_hours` and its guest-cancel-link consumer are Phase 1.5 step 7's job (still `STILL UNBUILT` per A4 below), same "don't ask for a setting before its feature exists" reasoning as PricingStep's tax-rate omission.
  - **Backend behavior these two toggles actually gate (not just settings storage)**: `BYZO_Booking::create()` reads `auto_confirm` to pick the booking's *starting* status (`confirmed` vs `pending`) — see A4 below. `BYZO_Notifications::on_booking_created()` now checks the booking's *actual* resulting status to decide which customer template to send (`booking_confirmed` vs `booking_created`) rather than always assuming `pending`, gated by the new `send_confirmation_email` toggle (default `true`, applies to both the created-time and later-transition confirmation sends, not cancellation/reminder). A brand new `admin_new_booking` event (5th entry in `BYZO_Email_Templates::EVENTS`, same plain-text-editable shape as the other 4) fires to `get_option('admin_email')` on every new booking regardless of its status, gated by `send_admin_notification` (default `true`) — independent of the customer-facing toggle, so an owner can get admin alerts with customer confirmations turned off, or vice versa.
- `ReviewStep.vue` **(new)** — read-only summary of every prior step's answers, no new REST calls.
- Create action — unchanged endpoint sequence (`PUT /settings`, `POST /bookable-items`, `PUT /bookable-items/{id}/availability` — or `POST /bookable-items/{id}/slots` for Event, per AvailabilityStep's note above), extended to also `PUT /settings` the four new fields (`require_phone`/`auto_confirm`/`send_confirmation_email`/`send_admin_notification`) in the same call.
- `SuccessStep.vue` **(new)** — "Your booking is ready!" + 4 action buttons: View Dashboard (→ `/`), Manage Booking (→ the new bookable item's edit screen), View Booking Form (copies its shortcode to clipboard, reuses A5's copy-button logic), Add Another Booking Type (navigates to the regular Add New flow's own `BookableItemTypeStep.vue` picker — the setup wizard's own state machine has already reached `done` by this point and isn't built to restart partway through, so this reuses existing infrastructure rather than re-entering the completed wizard). **NOT yet filtered to "types not yet used"** as the source spec envisioned — that needs the Add New picker to know which types already have an active item, which it doesn't fetch today; flagged as a real follow-up, not silently dropped.
- Stored progress in option `byzo_setup_step` so a page reload resumes at the right STEP; a "Skip setup" link (hidden on Review/Success, shown everywhere else) sets a `byzo_setup_skipped` flag and drops straight into the bookable item list with an empty state — unchanged. **Known limitation**: unlike the step position, the *answers* accumulated across steps (name, schedule, price, toggles — everything before the final Create action fires) live only in the Vue component's in-memory state, not persisted server-side, so a genuine page reload mid-wizard resumes on the correct step but with those fields back at their defaults. No draft-persistence option was built for this — not called for in the spec, and a real page-reload-mid-wizard abandonment is a narrow enough case that building one wasn't justified without being asked.

### A2/A3. Bookable Item + Availability admin screens
- Single admin page `admin.php?page=byzo-bookable items` — list table (WP_List_Table) + edit screen
- **Type-first Add New flow (decided 2026-09-02):** clicking "Add bookable item" shows a booking-type picker FIRST (the same 4-option card/radio UI the setup wizard uses — reuse the component), then the type-specific form. The `POST /bookable-items` payload carries the chosen type; the hardcoded `type: 'appointment'` in `BookableItemForm.vue` goes away. Type is shown read-only on the edit screen (immutable after creation).
- **Per-type field matrix** (what the Details form shows — one shared form component with conditional fields, not four forms):

| Field | Appointment | Table | Event | Slot |
|---|---|---|---|---|
| name / description / photo / tags / status | ✓ | ✓ | ✓ | ✓ |
| duration_minutes | ✓ ("Appointment length") | ✓ ("Reservation length") | — (per-occurrence, end − start) | ✓ ("Slot length") |
| capacity | hidden, fixed 1 | ✓ ("Seats") | ✓ ("Total tickets/seats") | ✓ ("Spots per slot") |
| price | ✓ ("Price") | ✓ (optional, e.g. per-seat fee) | ✓ ("Ticket price") | ✓ ("Price") |

- Edit screen tabs: **Details** (per-type fields above), then for appointment/table/slot: **Availability** (weekly grid UI — 7 day rows, one start/end time picker per day on Free; Pro adds a "+ Add shift" button per day) + **Exceptions** (date picker + closed/custom-hours toggle); for event: a **Dates** tab instead (list of occurrences, add date + start/end time, delete occurrence — writes directly to `wp_byzo_slots`, see §1.3)
- Bookable Items list gains a **type column + type filter**, and a **shortcode column** showing each bookable item's own `[bookyzo_booking bookable_item_id="X"]` with a copy button (see A5)
- Weekly grid UI is a natural Vue component even in Free (per the "admin panel Vue use korbe" requirement) — build the REST endpoints from day one so Free and Pro share the same Vue admin shell, Pro just adds routes/components

### A3 cont. Slot generation cron
- `byzo_generate_slots` cron hooked at `wp_schedule_event` (e.g. daily), calls `BYZO_Slot_Generator::generate_for_bookable_item( $bookable_item_id, $days_ahead )`
- Must respect: weekly schedule × exceptions × buffer time × min/max advance window
- Idempotent — re-running for a date range that already has slots should not duplicate (upsert on `bookable_item_id + start_datetime` unique key)

### A4. Booking management (admin)
- Calendar view: day/week/month toggle — Vue component consuming `GET /wp-json/bookyzo/v1/bookings?from=&to=&bookable_item_id=`
- Manual booking creation modal (for phone/walk-in) — same REST endpoint as the public widget uses (`POST /wp-json/bookyzo/v1/bookings`), just called from wp-admin context with `current_user_can` check instead of guest flow
- Status transitions: pending→confirmed→completed, or →cancelled/no_show at any point before completed. Enforce valid transitions server-side (a state machine table, not free-form status writes) — prevents e.g. "completed" flipping back to "pending" by a stray API call
- **Auto-confirm (added 2026-09-03, built same day — Phase 1.5 step 6):** `BYZO_Booking::create()` reads `byzo_settings.auto_confirm` (default `false`) — when `true`, the row is inserted with `status = 'confirmed'` directly instead of `'pending'`. `byzo_booking_created` still fires unconditionally either way (unchanged); `BYZO_Notifications::on_booking_created()` is what actually adapts, checking the booking's real resulting status to send the `booking_confirmed` template instead of `booking_created` when it's already confirmed — simpler than introducing a second action name for the same outcome, and avoids the customer getting both a "request received" and a "confirmed" email back to back. The `TRANSITIONS` state machine itself is unchanged — this only picks the *starting* state, capacity-decrement/payment logic is identical either way.
- **Single cancellation policy (STILL UNBUILT as of 2026-09-02 — flagged in the four-type gap analysis):** one fixed time-limit rule in `byzo_settings` (e.g. `cancellation_hours = 24`, 0 = no guest cancellation). Consumed by the guest cancel link (see A5's `manage_token` note — a `PUT /bookings/{id}` variant authorized by token instead of admin capability, allowed only while `slot_start − now > cancellation_hours` and status is pending/confirmed). Admin cancellation stays unrestricted. Multiple policy tiers remain Pro (B3).

### A5. Public booking widget
- Gutenberg block `bookyzo/booking-form` + shortcode `[bookyzo_booking]`, both render the same Vue app mounted into a div, config passed via `data-*` attributes
- **Per-bookable item shortcodes (decided 2026-09-02):** every bookable item gets its own copyable shortcode, surfaced in the Bookable Items list (CF7 pattern). Attribute forms: `bookable_item_id="X"` (pins the widget to that one bookable item, picker step auto-skipped — the attribute already exists in `BYZO_Public_Widget::render_shortcode()`, what's new is surfacing it per-bookable item in admin), `type="table|event|appointment|slot"` (picker filtered to that type — new attribute, needs a `type` query param on `GET /bookable-items/public`), bare (all active bookable items). Gutenberg block mirrors the same options (bookableItemId attr exists; add a type attr).
- Multi-step flow as one SPA-like Vue component (no page reloads): Step 1 bookable item/service picker → Step 2 slot calendar/list → Step 3 contact form → Step 4 confirmation
- **Party size / quantity input** on the contact-form step for capacity>1 bookable items: sent as `party_size` on `POST /bookings` (backend already fully supports it — validation, atomic capacity decrement, `payment_amount = price × party_size` all shipped in Phase 0/1; the widget just never asked). Max selectable = the chosen slot's `capacity_remaining`. Label is type-aware ("Party size" / "Tickets" / "Spots"); hidden for Appointment (always 1). Same input goes in the admin `ManualBookingModal`.
- Widget copy must be type-aware generally — no hardcoded "appointment" wording in steps/confirmation once four types are live
- Guest flow: no wp user account created; a booking is identified by `customer_email` + a signed token in the confirmation email/URL for any future self-service links (Pro dashboard needs this same token mechanism, so build it in Free even though Free has no dashboard to use it yet — avoids a breaking change later). **Gap noted 2026-09-02:** `manage_token` is generated and returned on `POST /bookings` but no route consumes it yet — Free's A4 cancellation policy is the intended first consumer (a cancel link in the confirmation email, honoring the time-limit rule); wire that up rather than leaving the token dead, or the reviewer-visible dead code should be removed.
- **Elementor widget (added 2026-09-03, competitor gap-fill):** registers the same Vue mount point as the Gutenberg block via Elementor's widget API (`Widget_Base`), same `bookable_item_id`/`type` config surfaced as Elementor controls instead of block attributes. No new REST/PHP logic — purely another editor-side entry point onto the existing widget bundle.
- **Translation-readiness (added 2026-09-03):** all UI strings already need `__()`/`_e()` wrapping with the `bookyzo` text domain per normal WP practice; this item is really "audit for any string that slipped through unwrapped," plus shipping a `.pot` file in the release build (`bin/build-release.js`, Phase 1 step 10). Confirmed as WordPress.org-standard, not a new mechanism — WPML/Loco Translate both work against a correct `.pot` automatically.

### A6. Notifications
- Transactional emails via `wp_mail()`, templates as PHP files in `templates/emails/` overridable via theme (same `byzo_get_template()` pattern as CBFW's `cbfw_get_template()`)
- Events: `byzo_booking_created`, `byzo_booking_confirmed`, `byzo_booking_cancelled`, `byzo_booking_reminder` (fired by a cron scanning for bookings starting in exactly 24h, matched to the hour to avoid duplicate sends — store `reminder_sent_at` on the booking row)
- Free template editing = plain textarea with `{customer_name}`/`{bookable_item_name}`/`{start_time}`/`{business_name}` placeholders, no visual builder (Pro feature)
- **Notification toggles (added 2026-09-03, built same day — Phase 1.5 step 6):** `byzo_settings.send_confirmation_email` and `byzo_settings.send_admin_notification` (both default `true`) gate `BYZO_Notifications`' sends independently. `send_confirmation_email` gates the two customer-facing "you're booked" templates (`booking_created`, `booking_confirmed` — not `booking_cancelled`/`booking_reminder`, which stay unconditional). `send_admin_notification` gates a new 5th event, `admin_new_booking` (added to `BYZO_Email_Templates::EVENTS`, same plain-text-editable shape as the other 4), sent to `get_option('admin_email')` — no per-plugin override field for the admin address was built, matching what's actually in `byzo_settings` today. Both toggles fire independently of each other on every new booking (`BYZO_Notifications::on_booking_created()`), regardless of `auto_confirm`'s effect on which customer template gets picked. Neither is B5's "real-time alerts," which stays an in-admin-UI Pro feature layered on top.
- **"Add to calendar" link (added 2026-09-03, moved here from Pro per the same-day `FEATURES.md` tier reassignment):** computed on the fly, not stored — a `.ics` VEVENT built from the booking's `slot_start`/`slot_end`/bookable item name at confirmation-page-render and confirmation-email-send time, plus a Google Calendar `render?action=TEMPLATE` deep link (no API call, just a URL with the same fields query-string-encoded). No new column or token needed — this is a single-event link, not the subscribable multi-event feed (that's B9's calendar feed, which does need a token, see §4).

### A7. Payments
- Manual/offline: just a "Mark as paid" button in admin, sets `payment_status = paid_manual`
- One gateway (Stripe) via Stripe Elements on the confirmation step; `byzo_payment_intent` created server-side, webhook `wp-json/bookyzo/v1/stripe-webhook` confirms and flips `payment_status = paid_online` + `status = confirmed`
- No deposits on Free — full amount or nothing; bookable item/service price is a flat field on the bookable item (`price` column, see §1.1) for Free's single-service-per-type model
- **Flat site-wide tax rate (added 2026-09-03, competitor gap-fill):** new `tax_rate` field (DECIMAL, percent) in `byzo_settings`, optional/default 0. When set, `BYZO_Booking::create()`'s existing `payment_amount = price × party_size` computation (§ Phase 1 step 8's security fix) gains a second step: `tax_amount = payment_amount × tax_rate / 100`, `payment_amount += tax_amount` — both stored per §1.4. Per-bookable item override (`bookable_items.tax_rate_override`, §1.1) is Pro-only; Free's settings UI only ever writes the site-wide rate.

### A8. Dashboard (renamed from "Basic reporting" 2026-09-03 — see `FEATURES.md` A8)
- Simple aggregate query page: `SELECT status, COUNT(*) FROM wp_byzo_bookings WHERE start_datetime BETWEEN ...` grouped by day/week/month
- CSV export via the same `admin-post.php` + nonce pattern used in CBFW's import/export and license-envato's log export
- **`BYZO_Reports::get_dashboard_summary()` gains a `revenue` field (added 2026-09-03):** `SUM(payment_amount) WHERE payment_status IN ('paid_manual','paid_online')`, same three periods (today/this-week/this-month) as the existing status counts, same INNER JOIN-onto-slots convention so a booking whose slot was deleted doesn't inflate the total. One number per period — no bookable item/type/date-range breakdown, that stays Pro (B8).
- **Upcoming bookings widget**: `Dashboard.vue` gains a bounded query against the existing `GET /bookings` (`from=now`, `status=pending,confirmed`, a small `limit`, e.g. 5) — no new endpoint, just a narrower call to what A4's list already serves.
- **Welcome header**: reads the Free plugin's single active-bookable item-per-type set (already fetched for the Bookable Items screen) + `byzo_settings` — no new query, just a summary render of data the Dashboard route already has reason to fetch.

### A9. Type-specific Free constraints
Enforced centrally in one place — `BYZO_Capabilities::max_bookable_items_per_type()` returns `1` on Free, filtered to unlimited by Pro (`byzo_max_bookable_items_per_type` filter). With all four types in Free (2026-09-02), this per-type cap is Free's ONLY volume limit: up to 4 active bookable items total, one per type. **Do not scatter "if free, limit to 1" checks across multiple files** — this is exactly the kind of restriction WordPress.org's Guideline 5 reviewer flags if any UI text mentions "free version" while the code path still exists; keep the enforcement as a genuine capability query, not a visible lock (see CBFW's 2026-07-31 and 2026-08-03 Trialware rejection history — do not repeat that mistake here).

**`BYZO_Capabilities::max_active_bookable_item_types()` REMOVED** (2026-09-03, ROADMAP.md Phase 1.5 step 1): it was defined in Phase 0 for the old one-type-on-Free model but was never actually enforced anywhere (the gap analysis found no caller), and under the all-types-in-Free direction it had no reason to exist. Method and its `byzo_max_active_bookable_item_types` filter deleted from `class-byzo-capabilities.php`; also dropped from readme.txt's documented filter list.

### A10. Customer management (added 2026-09-03, competitor gap-fill)
- Customer list screen reads `wp_byzo_customers` (§1.5) directly rather than `SELECT DISTINCT customer_email FROM wp_byzo_bookings` (today's implicit approach) — searchable/sortable by name/email/phone.
- CSV import: `admin-post.php` handler (`admin_post_byzo_import_customers`), mirrors `BYZO_Booking_Export`'s architecture in reverse — a testable `parse_csv_rows()`/`upsert_customers()` pair separated from `handle()`'s auth/nonce/file-upload wrapper, same testability rationale as Phase 1 step 5's export refactor. Upserts by email (matches booking-creation's own upsert rule in §1.5) so re-importing an updated list doesn't duplicate rows.

---

## 3. Pro Plugin — Feature-by-Feature Breakdown

### B1. Multi-Everything
- `wp_byzo_locations` table (Pro-created, `dbDelta` in Pro's activation hook): id, name, address, timezone, working_hours (JSON)
- `bookable_item.location_id` FK becomes meaningful only when Pro active; Free code must treat NULL location as "the business" transparently (no Pro-check branching needed in Free's own queries — just always JOIN nullable)
- ~~All-types-at-once~~ — moved to Free 2026-09-02 (all four types ship in Free; see §5 item 5). Pro's B1 is now purely the volume unlock: `byzo_max_bookable_items_per_type` filtered from 1 to unlimited — still no separate "multi-type mode" flag, nothing else changes

### B2. Advanced availability
- Multiple shifts: Pro's admin UI adds "+ Add shift" to the weekly grid, writing additional `shift_group` rows — **the schema already supports this in Free's own table** (see 1.2), Pro just unlocks the UI affordance and the slot generator's shift_group>0 handling. Same "don't gate the data model, gate the UI/capability" principle as B1.
- Recurring bookings: a `wp_byzo_booking_series` table (Pro-only table) — parent record with RRULE-like fields (frequency, interval, count/until), child bookings in `wp_byzo_bookings` reference `series_id`
- Waitlist: `wp_byzo_waitlist` table (Pro-only) — bookable_item_id, requested_date_range, customer contact, notified_at; cron checks for freed capacity and emails/SMS the next waitlist entry FIFO

### Competitor gap-fill items (added 2026-09-03) — where each one plugs into B1–B11

- **B1 multi-user staff logins**: reads `bookable_items.wp_user_id` (§1.1). A new `byzo_bookable_items_query`-style filter (or a check inside `BYZO_Bookable_Item::query()`/`BYZO_Booking::query()`) narrows results to `WHERE wp_user_id = get_current_user_id()` whenever the current user lacks `manage_options` but has whatever Pro-defined capability marks them as a linked staff login. Free's own queries need zero changes — the column sits unused until Pro's capability check exists.
- **B3 timeline view / availability search**: no new table. Needs `/slots` and `/bookings` (§4) to accept an array of bookable item IDs (or a bare `type=` filter meaning "all active bookable items of this type") instead of today's single `bookable_item_id` — a REST surface widening, not a schema change. Free could theoretically call the same widened endpoint with one ID; Pro's UI is what actually uses the multi-bookable item shape.
- **B3 customer CSV export**: reads `wp_byzo_customers` JOIN `wp_byzo_bookings` (§1.5) — the export-side mirror of A10's import, same `admin-post.php` pattern, Pro-gated by capability check inside `handle()` rather than a separate export class.
- **B5 bulk/marketing campaigns**: no new table for v1 — reuses `wp_byzo_customers` (§1.5, filtered by tag/last-booking-date/no-show count) and the existing `byzo_get_template()`/`wp_mail()` (and Pro's SMS gateway, already planned for reminders) to send an immediate batch. A send-history log table can be added later if "campaign analytics" ever becomes its own feature — not needed to ship v1.
- **B6 seasonal/dynamic pricing**: `wp_byzo_pricing_rules` (§1.6). Applied inside the same payment-amount computation A7's tax step extends — pricing rules resolve first (against `slot_start` for season/weekday, against `booking-time vs. slot_start` for lead-time), then tax is computed on the adjusted price, matching real-world tax-on-final-price behavior.
- **B6 cart checkout**: `bookings.cart_id` (§1.4). Pro's cart UI collects N in-progress bookings client-side, submits them together, backend creates all N rows sharing one `cart_id` and one Stripe PaymentIntent for their summed total; the existing webhook handler's booking-lookup-by-payment-intent becomes a lookup-by-`cart_id` for the multi-row case.
- **B6 per-service tax rules**: `bookable_items.tax_rate_override` (§1.1) — already covered under A7's entry, just restating that Pro's Bookable Item form is the only UI that ever writes this column.
- **B9 webhooks**: `wp_byzo_webhooks` (§1.6). A dispatcher subscribed to the same `byzo_booking_status_changed`/`byzo_booking_created` actions B2/A6 already fire (no new action names needed) — for each active webhook row whose `events` JSON contains the fired event, `wp_remote_post()` the payload with an `X-Bookyzo-Signature` header (HMAC-SHA256 of the body using the row's `secret`), fire-and-forget via `wp_schedule_single_event` so a slow/dead receiver never blocks the booking request itself.
- **B9 marketing pixels**: no schema — `fb_pixel_id`/`ga_measurement_id`/`gtm_container_id` fields in Pro's settings section (per B3–B11's generic "extends Free's settings UI sections" pattern below), injected into the widget bundle's rendered `<head>`/inline script only when Pro is active and a value is set.
- **B9 subscribable calendar feed**: no schema — one new public REST endpoint, `GET /calendar-feed/{bookable_item_id}/{token}.ics` (§4), where `token` is a stateless HMAC of `bookable_item_id` (same `wp_salt('auth')`-keyed technique as `BYZO_Booking_Token`, a sibling `BYZO_Calendar_Feed_Token` rather than reusing the booking token's scope). Handler streams `text/calendar`, one VEVENT per confirmed booking on that bookable item — computed live on each request, nothing materialized.
- **B11 custom form field builder**: `wp_byzo_custom_fields` (§1.6) defines the fields; submitted values land in `bookings.custom_fields` (§1.4, already reserved for exactly this since Phase 0). Widget's contact-form step fetches the active field definitions for the chosen bookable item (`GET /custom-fields?bookable_item_id=`) and renders them after Free's fixed name/email/phone/notes set.
- **B11 multilingual booking-facing content**: **left as an open decision, see §5 item 8** — the storage strategy has real tradeoffs and isn't settled yet, unlike the items above.

### B2–B11, general pattern
Same pattern throughout for everything not called out above: Pro adds its own tables/columns where the feature is genuinely Pro-exclusive data (floor plan layout, ticket tiers, SMS logs, revenue rollups), and consumes Free's filter/action hooks where it's extending Free's existing flow (booking status changes, notification events, settings UI sections). **Full hook inventory should be written as each Free feature is built**, not speculated now — CBFW's real hook list (`bpfw_settings_sections`, `bpfw_default_settings`, etc.) only emerged feature-by-feature during actual implementation. Placeholder hook names to reserve conceptually: `byzo_booking_status_changed`, `byzo_settings_sections`, `byzo_default_settings`, `byzo_notification_events`, `byzo_can_book_bookable_item` (capability filter, mirrors CBFW's `cbfw_can_bundle_product` fix — a real capability check, never a hardcoded array allowlist).

---

## 4. REST API surface (v1, namespace `bookyzo/v1`)

| Endpoint | Method | Purpose |
|---|---|---|
| `/bookable-items` | GET, POST | list/create (admin) |
| `/bookable-items/public` | GET | public — whitelisted fields only (id/type/name/description/photo_id/duration_minutes/price/capacity), active bookable items only; added 2026-09-02 for the booking widget's bookable item/service picker (§A5), which has no admin session to use `/bookable-items` with. Needs a `type` query param (planned 2026-09-02) for the `[bookyzo_booking type="..."]` shortcode variant |
| `/bookable-items/{id}` | GET, PUT, DELETE | |
| `/bookable-items/{id}/availability` | GET, PUT | weekly schedule + exceptions (appointment/table/slot types only — event bookable items have no weekly schedule, see §1.3) |
| `/bookable-items/{id}/slots` | GET, POST | admin — list/create event occurrences directly as slot rows (event-type bookable items only, §1.3; non-event items get a 400 `byzo_not_event_type`). GET added 2026-09-03 alongside the planned POST — the "Dates" tab needs some way to list what it's managing, natural REST completion of the same collection route. Built 2026-09-03, ROADMAP.md Phase 1.5 step 5. |
| `/bookable-items/{id}/slots/{slot_id}` | DELETE | admin — remove an occurrence with no bookings against it; 409 `byzo_occurrence_has_bookings` if bookings exist; 404 if the slot doesn't belong to the `{id}` in the path. Built 2026-09-03. |
| `/slots` | GET | public — available slots for booking widget, filtered by bookable item/date range |
| `/bookings` | GET, POST | GET = admin list (capability-gated; query params `from`, `to`, `bookable_item_id`, `status` for calendar/list filtering per §A4 — `from`/`to` filter by the booking's *slot* start time, i.e. when the appointment happens, not `created_at`; a booking whose slot was later deleted has no date and is excluded from a date-filtered query, added 2026-09-02 while building the calendar screen); POST = public create (guest-allowed, rate-limited) |
| `/bookings/{id}` | GET, PUT | PUT = status change, admin or signed-token self-service |
| `/bookings/{id}/payment` | PUT | admin-only "mark as paid" (§A7) — separate narrow route from the general status PUT above, same "only one field writable here" rule |
| `/bookings/{id}/payment-intent` | POST | public — creates a Stripe PaymentIntent for the booking's server-computed `payment_amount`; guest-allowed but requires the request to know the booking's `customer_email` (added 2026-09-02 building §A7) |
| `/settings` | GET, PUT | business profile + Free/Pro settings sections |
| `/email-templates` | GET, PUT | admin-only, plain-text notification subject/body per event (§A6), added 2026-09-02 |
| `/payment-settings` | GET, PUT | admin-only, Stripe publishable/secret/webhook-signing keys (§A7), added 2026-09-02 — its own option, not folded into `/settings`, since API secrets are a different sensitivity class than general preferences |
| `/reports/summary` | GET | admin-only, today/this-week/this-month booking counts by status (§A8), added 2026-09-02 for the Dashboard |
| `/stripe-webhook` | POST | Stripe only, signature-verified, not nonce-based |
| `/customers` | GET, POST, PUT | admin-only — list/search (A10), create/edit; reads/writes `wp_byzo_customers` (§1.5); added 2026-09-03 |
| `/calendar-feed/{bookable_item_id}/{token}.ics` | GET | public, token-gated not nonce-gated (same rationale as the booking widget's own no-nonce decision below) — subscribable feed for external calendars (B9); added 2026-09-03, Pro-only in practice though the route itself can live in Free's controller registry same as any other capability-gated feature |
| `/webhooks` | GET, POST, PUT, DELETE | admin-only, Pro — CRUD on `wp_byzo_webhooks` (§1.6); added 2026-09-03 |
| `/pricing-rules` | GET, POST, PUT, DELETE | admin-only, Pro — CRUD on `wp_byzo_pricing_rules` (§1.6); added 2026-09-03 |
| `/custom-fields` | GET, POST, PUT, DELETE | admin CRUD (Pro) + public GET filtered by `bookable_item_id` (widget reads active field definitions); `wp_byzo_custom_fields` (§1.6); added 2026-09-03 |

**`/slots` and `/bookings` GET — widened 2026-09-03** for B3's timeline view/availability search: both now accept `bookable_item_id` as either a single ID (today's behavior, unchanged) or omitted with a `type=` filter meaning "all active bookable items of that type" — needed once Pro's unlimited-bookable items-per-type makes "show me everything" a real query, not just "show me the one Free bookable item."

Vue admin app talks to these via `wp.apiFetch` (standard WP REST nonce). **Public widget's abuse guard — DECIDED 2026-09-02: no nonce at all, honeypot + per-IP rate limit instead.** A nonce protects an authenticated user's cookie session against CSRF; a guest has no session to protect, so the widget's own fetch wrapper (`src/widget/api.js`) calls these routes with no nonce, sidestepping the page-cache staleness concern entirely. Real abuse mitigation is `BYZO_Public_Guard`: a hidden honeypot field (`website`) on the booking form, and a soft per-IP rate limit (20 requests/10min, filterable via `byzo_booking_rate_limit_max`/`_window`) — both skipped for an authenticated admin (`current_user_can('manage_options')`) so the admin's own manual-booking modal is never throttled by a guard meant for anonymous spam.

---

## 5. Open Decisions (resolve before/at start of coding, not mid-build)

1. **Timezone storage — DECIDED 2026-09-02: site-local.** All `DATETIME` columns (`wp_byzo_schedules.start_time`/`end_time`, `wp_byzo_availability_exceptions.date`/`start_time`/`end_time`, `wp_byzo_slots.start_datetime`/`end_datetime`, `wp_byzo_bookings` timestamps) store the WordPress site's configured local time (`wp_timestamp`/`current_time('mysql')`, matching core WP convention and most small WP booking plugins), NOT UTC. Consequence to design around: since Bookyzo is single-location on Free, this is safe. Once Pro's multi-location feature (B1) ships with per-location timezones, slot generation/display for a non-default-timezone location needs explicit conversion at read/write time (via `wp_timezone()`/`get_option('timezone_string')` per location) — the DB column itself still just holds a naive local datetime for that location, so the location's own timezone must always travel alongside any datetime read from `wp_byzo_slots`/`wp_byzo_bookings`, never assumed to be the site default. Flag this explicitly when B1/multi-location work starts.
2. **Which booking type ships first** — **DECIDED 2026-09-02: Appointment** as the reference implementation (built first, complete). ~~Table/Event/Slot not built until launch step 4~~ — **SUPERSEDED same day by item 5 below**: the other three types now go into Free BEFORE the first WP.org submission.
3. **Slug/prefix finality**: `bookyzo` / `BYZO_` used throughout this doc is a first pass, following the CBFW precedent of picking something short and renaming later only if WordPress.org's reviewer flags genericness — do not treat `BYZO_` as sacred if a review round asks for a change (see [[bundle-product-plugin]]'s full prefix-rename history for what that process looks like).
4. **WooCommerce bridge boundary** (B9): confirm at that stage whether it reuses `wp_byzo_bookings` with a `wc_order_id` column, or is a fully separate mapping table — deferred, not needed until Part C step 6.
5. **All four booking types in Free — DECIDED 2026-09-02 (user direction change).** Appointment + Table + Event + Slot all ship in the Free plugin before the first WordPress.org submission; Pro gates extra features only, never booking types. Free's volume cap = one active bookable item per type (`max_bookable_items_per_type`, unchanged). The never-enforced `max_active_bookable_item_types()` capability is to be deleted (see §A9). Build order for the remaining work: `ROADMAP.md` Phase 1.5.
6. **Type-first creation UX + per-bookable item shortcodes — DECIDED 2026-09-02 (user).** "Add New" bookable item flow picks the booking type FIRST, then shows a type-specific form (field matrix in §A2/A3); every bookable item's own pinned shortcode (`[bookyzo_booking bookable_item_id="X"]`) is displayed copyable in the Bookable Items list, plus a `type=""` shortcode/block variant for a type-filtered picker.
7. **Event occurrence model — DECIDED 2026-09-02: direct slot rows, no weekly schedule.** Event-type bookable items skip `wp_byzo_schedules`/the generator entirely; admin-entered occurrences are written straight into `wp_byzo_slots` (full rationale in §1.3). Chosen over adding event-date columns to the bookable items table because it needs zero schema change and reuses the whole existing booking/capacity path.
8. **Multilingual booking-facing content storage — OPEN, added 2026-09-03 (competitor gap-fill, B11).** Bookable Items/services aren't WP posts (§1, custom DB tables by design), so WPML/Polylang's normal post-based translation UI won't pick them up automatically the way it would a CPT. Two real options, not yet chosen: **(a)** register each translatable string via WPML's String Translation API (`icl_register_string`/the `wpml_register_string` action) at save-time, letting WPML's own String Translation screen manage the actual translated values — less new code, but ties the feature to WPML specifically and String Translation's UX wasn't built for a growing list of per-bookable item strings; **(b)** a Bookyzo-owned `wp_byzo_bookable_item_translations` table (`bookable_item_id`, `locale`, `name`, `description`) with Bookyzo's own admin UI for editing per-language values — more upfront work, but plugin-agnostic (works without WPML/Polylang installed at all) and fits this doc's existing "own the data model" pattern better. Resolve this before B11 implementation starts, not mid-build — whichever is chosen changes both the schema (§1.6 would need a new table for option b) and the Bookable Item admin screen's Details tab layout.
