# Bookyzo — Feature Specification

**Positioning:** One WordPress plugin covering Appointment + Table + Event + Generic Slot booking. WooCommerce-independent core, with an optional WooCommerce bridge. Built for SMBs that currently juggle multiple booking tools.

> **DIRECTION CHANGE 2026-09-02 (supersedes the original staged plan):** ALL FOUR booking types ship in the **Free** plugin together. Pro no longer gates booking types at all — Pro is extra features only (more bookable items per type, multi-shift, deposits, SMS/WhatsApp, self-service, analytics, and the type-specific Pro modules in B7). Free's per-type limit (one active bookable item per type) is the honest upgrade wall: 1 staff + 1 table + 1 event + 1 slot-type bookable item free; a 2nd of *any* type needs Pro.

> **COMPETITOR GAP-FILL 2026-09-03:** cross-checked this spec against Bookly, Booking Calendar (wpdevelop), and Amelia — the three leading WordPress booking plugins — feature by feature (see the "Booking Plugin Ladder" audit). Added everything real that was missing: custom form field builder, seasonal pricing, cart checkout, timeline view, availability search, webhooks, marketing pixels, multi-user staff logins, tax handling, customer CSV import/export, bulk marketing campaigns, and multilingual content. One deliberate tier change: **booking confirmation's "add to calendar" link moved from Pro to Free** (A5) — all three competitors give this away free, so gating it was an unnecessary wall, not a real limit.

> **ONBOARDING & DASHBOARD DEEPENED 2026-09-03:** user supplied a full onboarding-wizard + dashboard UX spec (welcome → type → booking details → availability → customer fields → pricing → confirmation/notifications → review → create → success → dashboard). Reconciled against Bookyzo's already-decided architecture rather than adopted verbatim — three real deviations, called out here so they're not missed on a skim: **(1)** the source spec's 7 booking-type options collapse onto Bookyzo's existing 4 (`Time Slot`→Slot, `Table Reservation`→Table, `Event/Ticket`→Event, `Room/Resource`→Slot, `Service`→ Appointment's own service field) — **`Custom Booking` (freeform user-defined types) is explicitly NOT adopted**, it would need a dynamic type system instead of the fixed `type` enum column every table/query is built around (§1.1); flag to the user if they actually want that later, it's a bigger architectural call than this pass makes alone. **(2)** the spec's flat "Availability" top-level nav item stays **bookable item-scoped** (a tab on each bookable item's edit screen, A2/A3) rather than becoming its own admin page — matches Bookyzo's existing bookable item-centric model, not the source spec's flatter one. **(3)** the spec's dashboard "Revenue" stat is added to **Free** (new A8) as one summed total for the period — Amelia's own free tier already does this (see the competitor audit above) — while the *breakdown* by bookable item/type/date-range stays Pro (B8), unchanged. Genuinely new (not a reconciliation, an actual gap): **auto-confirm vs. manual-approval** as a real Free setting (A4) — every booking previously always landed on `pending` with no way to skip that.

> **UNIVERSAL-BOOKING-PLUGIN GAP AUDIT 2026-09-05:** user supplied a full feature wishlist for a from-scratch "Universal Booking Plugin" (28 categories). Cross-checked against Bookyzo's existing scope — most items were already covered, often just named differently (the wishlist's "Service"/"Room-Resource" booking types already map onto Appointment/Slot per the 2026-09-03 onboarding-reconciliation callout above; SMS/WhatsApp, multi-location, deposits, analytics, QR check-in, Google Calendar sync, etc. were already in Part B). Genuinely new items added below: location/online-meeting-link field (A2), a `rejected` status distinct from `cancelled` + basic admin reschedule + an agenda calendar view (A4), add-on/extra-service line items + a flat booking/service fee (B6), a downloadable PDF invoice (B4), an automated post-visit follow-up email (B5), and explicit analytics breakdowns — popular bookable items/time slots/staff, booking trend charts, conversion rate (B8). **One item flagged, not adopted:** a bookable item needing a second entity free at the same time (e.g. a room, alongside staff) is a real architectural fork, not a feature-list addition — see `DETAILED-SPEC.md` §5 item 9, `ROADMAP.md`'s matching callout.

**Stack:** WordPress plugin (PHP + custom DB tables), REST API, Vue 3 admin dashboard, optional WooCommerce integration, PayPal and Stripe for payments.

---

## PART A — FREE PLUGIN (WordPress.org core)

The free version should be genuinely usable for a small single-location business, not a crippled demo. Goal: real value + a natural upgrade path.

### A1. Setup & Onboarding

**Deepened 2026-09-03** from the 3-step version (type → business profile → first bookable item) into a fuller guided flow — the goal stated by the source spec holds exactly: *Install → Choose type → Configure → Create → Dashboard*, no settings-page dump on first activation.

- **Welcome screen**: "Let's set up your first booking" — one line of framing before anything is asked.
- **Type picker**: "What do you want to accept bookings for?" — the real 4-option choice (Appointment / Table / Event / Slot). More types (a 2nd bookable item of a different type) can be added later from Bookable Items → Add New; the wizard just picks which one to start with.
- **Step 1 — Booking details**: name, description, duration, capacity — only the fields the chosen type actually needs, per the existing A2 per-type field matrix (already built, wizard now reuses it instead of a fixed "Staff Member 1" form). Price lives entirely in Step 4 (Pricing) below, not asked twice.
- **Step 2 — Availability**: weekly days + hours, buffer time, blackout dates, min/max advance window — the existing weekly-schedule grid (A3), now surfaced *during* the wizard instead of only reachable afterward via Bookable Items → Edit → Availability. Event-type bookable items skip this step entirely and land on their Dates tab instead (per A9's occurrence model — an event has no weekly schedule to configure).
- **Step 3 — Customer information**: name and email are always required (can't be turned off — a booking is meaningless without them); phone can be toggled required/optional. **No custom-field UI shown here** — Free has no custom-field builder (that's B11/Pro), so the step must not tease one, per the Guideline-5 rule.
- **Step 4 — Pricing**: free-or-paid toggle; if paid, price + currency (currency inherited from the business profile) + the new flat tax rate (A7). Payment-gateway choice defaults to whichever one gateway is configured (A7) — no gateway picker needed since Free only ever has one. Deposit/partial-payment is Pro and simply isn't shown, not shown-disabled.
- **Step 5 — Confirmation & notifications**: auto-confirm vs. manual-approval toggle (new — see A4); "send confirmation email to customer" and "send notification email to admin" toggles (both default on — see A6); cancellation policy's single time-limit rule (A4, e.g. "allow cancellation up to 24h before"). Kept to these essentials on purpose — anything beyond this (multiple reminder timings, SMS, visual template editing) stays in Settings for later, not the wizard.
- **Review**: a plain summary card of everything just configured (e.g. "Appointment · 30 minutes · Mon–Fri 9:00–17:00 · $25") before the final create action.
- **Create**: saves the settings and creates the first bookable item + its schedule in one action (already how `FirstBookableItemStep` works today — extended to also write availability/pricing/notification settings instead of just business-profile defaults).
- **Success screen**: "Your booking is ready!" with next actions — View Dashboard, Manage Booking, View Booking Form (copies the bookable item's shortcode, A5), Add Another Booking Type (re-enters the type picker for a *different* type — a 2nd bookable item of the *same* type still needs Pro, per A9's cap).
- Business profile (name, timezone, currency, working hours) collected once, upfront, before the type-specific steps — unchanged from the current wizard.

### A2. Bookable Item Management (Core)
- **Type-first creation flow (decided 2026-09-02):** "Add New" starts with a booking-type picker (Appointment / Table / Event / Slot), THEN shows the type-specific form — the fields shown depend on the chosen type (see A9 for what each type's form needs). Type is fixed after creation (changing type would orphan the bookable item's schedule/slots).
- Create/edit bookable items (staff, table, room, rental item, event — one core entity, type-specific fields)
- All four booking types available on Free; **one active bookable item per type** (the Free cap — enforced centrally per A9, so up to 4 active bookable items total, one of each type)
- Bookable Item photo, description
- Capacity setting (1 person / N people) — editable for Table (seats), Event (total tickets/seats) and Slot; fixed at 1 for Appointment (one-on-one by definition)
- Bookable Item status (active/inactive/archived)
- Bookable Items list shows each bookable item's own copyable shortcode (see A5) plus a type column/filter
- **Location / online meeting link (added 2026-09-05, Phase 1.6):** a free-text `location` field (address, room name, or "Online") plus an optional `meeting_link` (Zoom/Meet/Teams URL) — available on every type, but mainly useful for Appointment-type consultants. Shown on the widget's confirmation step and via new `{location}`/`{meeting_link}` email placeholders (A6). Not the same as B1's multi-location/branch system — this is one descriptive field per bookable item, no per-branch schedule logic attached.

### A3. Availability Engine
- Weekly recurring schedule per bookable item
- Holiday / blackout date exceptions
- One shift/time-block per day (Free); multiple shifts reserved for Pro
- Minimum advance booking notice (e.g. "must book at least 2 hours ahead")
- Maximum advance booking window (e.g. "up to 30 days ahead")
- Buffer time between bookings
- Automatic slot generation (rolling window, background cron)

### A4. Booking Management
- Unified calendar view (day/week/month, **plus agenda — added 2026-09-05, Phase 1.6**: a 4th mode, a flat chronological list, useful once a business has more than a handful of bookings on a given day)
- Manual booking creation/edit from admin (for phone/walk-in bookings)
- Booking statuses: pending, confirmed, completed, cancelled, no-show, **rejected (added 2026-09-05, Phase 1.6)** — distinct from cancelled: a `pending` request the business explicitly declines before ever confirming it, kept separate from `cancelled` (a booking called off after being made) so reporting can tell "we said no" apart from "they backed out."
- **Auto-confirm vs. manual approval (added 2026-09-03):** a site-wide `byzo_settings` toggle, set during onboarding (A1) or later in Settings. Manual approval (default) creates every booking as `pending`, same as today. Auto-confirm creates it straight as `confirmed`, skipping the admin-review step — for businesses with no capacity/legitimacy concerns who find manual approval pure friction. Per-bookable item override is a natural Pro extension (not built now — flag if it comes up).
- **Basic reschedule (added 2026-09-05, Phase 1.6):** move an existing booking to a different available slot from its detail view — releases the old slot's capacity, atomically claims the new one, same underlying mechanics as a fresh booking. Distinct from B3's Pro "drag-and-drop reschedule on the calendar," which is the visual/bulk version of the same underlying action.
- Basic booking list with filters (date, status, bookable item)
- Single cancellation policy (fixed time-limit rule)

### A5. Customer-Facing Booking Widget
- Gutenberg block + shortcode for the booking form
- **Per-bookable item shortcodes (decided 2026-09-02):** every bookable item gets its own shortcode (`[bookyzo_booking bookable_item_id="X"]`, pins the widget to that bookable item and skips the picker step), displayed with a copy button in the Bookable Items list — Contact-Form-7 style. Also: `[bookyzo_booking type="table"]` (picker filtered to one type) and bare `[bookyzo_booking]` (all active bookable items). The Gutenberg block gets the same bookable item/type selection.
- Multi-step booking flow (select bookable item/service → pick slot → enter details → confirm)
- **Party size / quantity input** for capacity>1 bookable items (Table: "Party size", Event: "Tickets", Slot: "Spots") — capped by the slot's remaining capacity; hidden entirely for Appointment (always 1). Widget labels are type-aware.
- Guest checkout (no account required)
- Basic custom form fields (name, email, phone, notes)
- Mobile-responsive booking widget
- Elementor widget, in addition to the Gutenberg block — same bookable item/type selection
- Translation-ready (WPML / Loco Translate compatible, ships with a .pot file) — this covers UI strings; translating the site owner's own service/category/bookable item *content* per visitor language is a Pro feature (B11)

### A6. Notifications
- Email confirmation on booking
- Email reminder (single, fixed timing — e.g. 24h before)
- Email cancellation notice
- Basic email template editing (text only, no visual builder)
- **Admin notification email on new booking (added 2026-09-03)**, togglable (default on) — separate from B5's Pro "real-time in-app alerts": this is a plain email to the business owner, that stays Free; a live toast/badge in the admin UI is the Pro upgrade on top of it.
- **"Add to calendar" link (Google / Outlook / .ics) on the booking confirmation** — moved here from Pro on 2026-09-03: Bookly, Booking Calendar, and Amelia all give this away free, so gating a single per-booking calendar link isn't a real limit
- **New placeholders (added 2026-09-05, Phase 1.6):** `{location}` and `{meeting_link}` (A2), usable in any template alongside the existing `{start_time}`/etc.

### A7. Payments
- Manual/offline payment option (cash, bank transfer — mark as paid manually)
- One payment gateway integration (Stripe, pick one for Free)
- No deposits/partial payments on Free (Pro feature)
- Optional flat tax/VAT rate, applied site-wide to every booking (single percentage — per-service/per-region rules are Pro, see B6)
- **Gateway architecture (added 2026-09-06):** Payments settings is a registry-driven list of on/off blocks, one per available gateway (a toggle per method, not a tab picker) — Free's registry only ever contains Stripe, extended by Pro (see B6) without any Free-side change.

### A8. Dashboard

**Expanded 2026-09-03** from a bare count widget into the plugin's real home screen — what an admin sees immediately after Setup finishes, and every time they open Bookyzo afterward.

- **Welcome header**: current setup at a glance — the bookable item's type, schedule summary, and price (e.g. "Appointment · Mon–Fri 9:00–17:00 · $25"), pulled from existing bookable item/settings data, no new schema.
- **Stat cards**: Total Bookings, Today's Bookings, Pending, Confirmed — already shipped (`BYZO_Reports::get_dashboard_summary()`) — plus **Revenue (this period), added 2026-09-03**: one summed total (`SUM(payment_amount)` for paid bookings in the period), not a breakdown. Matches Amelia's own free-tier precedent (see the competitor audit) — the per-bookable item/per-type/date-range *breakdown* stays Pro (B8), only the single total is Free.
- **Upcoming bookings widget**: next N bookings across every active bookable item — date, time, customer, booking type, status, payment, quick actions (same row shape as the full Bookings list, just capped and unfiltered) — a summary, not a replacement for the full A4 booking list/calendar.
- Simple booking list export (CSV) — unchanged.

### Admin Navigation (Free, documented 2026-09-03)

Bookyzo's admin IA, reconciled against the source onboarding spec's flatter nav (see the callout at the top of this doc for what changed and why):

- **Dashboard** — A8 above
- **Bookings** — All Bookings (A4 list) · Calendar (A4) · Add Booking (manual entry)
- **Bookable Items** — one screen for every booking type, with the existing type column/filter (A2) standing in for the source spec's separate "Appointments / Time Slots / Table Reservations / Events" menu items — Bookyzo's four types share one screen, not four
- **Customers** — A10
- **Payments** — payment gateway settings (A7)
- **Notifications** — email template settings (A6)
- **Settings** — business profile, tax rate, auto-confirm toggle, cancellation policy
- **Availability is NOT its own top-level item** — it stays a tab on each bookable item's edit screen (A2/A3), since Bookyzo's model is bookable item-scoped, not one shared business-wide calendar of availability rules

### A9. Type-Specific Free Scope

All four types are IN Free (direction change 2026-09-02). Free's cap is **one active bookable item per type**, and each type's Free scope is deliberately basic:

- **Appointment:** 1 staff member, 1 service, fixed duration, capacity fixed at 1. Weekly recurring schedule.
- **Table:** 1 table, capacity = seats, single service window (one shift/day — e.g. dinner only), party-size booking, no floor plan visual. Weekly recurring schedule.
- **Event:** 1 event, single ticket tier (one flat price), capacity = total seats/tickets, quantity booking, no QR check-in. **One-off dated occurrences, NOT a weekly schedule** — an event happens on specific date(s), so event bookable items use direct occurrence entry (a "Dates" tab) instead of the weekly availability grid; each occurrence is materialized straight into the slots table.
- **Slot:** 1 bookable item, basic fixed-duration slots, capacity = spots per slot, no recurring class series. Weekly recurring schedule.

The per-type cap (1 bookable item per type) stays enforced centrally via `BYZO_Capabilities::max_bookable_items_per_type()` — a real filterable capability, never scattered checks or visible locks (Guideline 5 rule, see `.claude/skills/bookyzo-dev/SKILL.md`).

### A10. Customer Management
- Customer list auto-populated from bookings, searchable and sortable
- Import an existing customer list from CSV (lowers the switching cost from another booking tool)

---

## PART B — PRO PLUGIN (Paid upgrade)

Pro should unlock everything a growing/multi-location business actually needs — the things that create real operational pain when missing.

### B1. Multi-Everything
- **Unlimited bookable items per type** (multiple staff, multiple tables, multiple events, multiple bookable items — Free allows exactly one of each; this is the primary volume-based upgrade wall, hit the day a business hires a 2nd staff member or adds a 2nd table)
- ~~All booking types active at once~~ — **moved to Free 2026-09-02.** All four types are Free now; Pro's differentiator is depth per type (unlimited bookable items + the B7 type-specific modules), not type access.
- Multi-location/multi-branch support with per-location schedules
- Bookable Item grouping/categories
- Multi-user staff logins — link a WordPress user account to a bookable item so a staff member or branch manager can log in and see/manage only their own bookings (mirrors Booking Calendar's MultiUser edition)

### B2. Advanced Availability
- Multiple shifts per day (e.g. lunch + dinner service windows)
- Per-staff individual schedules that differ from the business default
- Custom availability rules per bookable item (overrides)
- Recurring bookings (weekly class, monthly appointment series)
- Waitlist with automatic notification when a slot opens up
- Group bookings (multiple attendees under one booking)

### B3. Advanced Booking Management
- Drag-and-drop reschedule on the calendar
- Bulk actions (bulk cancel, bulk status change)
- Booking notes/internal tags (staff-only visibility)
- Customer booking history and profile (repeat customer detection)
- Multiple cancellation/refund policy tiers (flexible per bookable item type)
- Timeline view — all bookable items of a type shown as rows with time across the top, for scanning multi-bookable item availability at a glance (only useful once Pro's unlimited bookable items are in play)
- Availability search — customers filter bookable items by date, capacity, or tags once more than one bookable item of a type exists (the per-item tag field itself ships with this — it was dropped from Free on 2026-09-06, since with Free's four-item cap nothing read it; see `DETAILED-SPEC.md` §1.1)
- Export the customer list to CSV, including each customer's booking and payment history
- **Duplicate booking (added 2026-09-05):** clone an existing booking's bookable item/customer/details as the starting point for a new one — walk-in regulars, recurring phone bookings that don't need a full series

### B4. Customer Account & Self-Service
- Customer dashboard (view/reschedule/cancel own bookings)
- Self-service reschedule within policy rules
- Saved customer details for faster repeat booking
- **Downloadable PDF invoice/receipt (added 2026-09-05):** branded, itemized (price, add-ons, tax) — Free's confirmation email already includes a plain-text amount breakdown (A6/A7), this is the formatted/downloadable version. Needs a PDF-generation approach decided first, see `DETAILED-SPEC.md` §5 item 10.

### B5. Notifications & Communication
- SMS notifications (via a chosen SMS gateway)
- WhatsApp Cloud API integration (confirmation, reminder, cancellation)
- Multiple, customizable reminder timings (e.g. 48h + 2h before)
- Visual email template builder (branding, logo, colors)
- Admin real-time alerts (new booking, cancellation, no-show)
- Bulk/marketing campaigns — send a one-off email or SMS blast to a filtered customer segment (e.g. no-shows in the last 90 days)
- **Automated post-visit follow-up / thank-you email (added 2026-09-05):** e.g. a review-request link, sent a configurable number of hours after a booking is marked `completed` — distinct from the bulk/marketing campaigns above (a one-off segment blast); this fires automatically per booking, keyed off the new `bookings.completed_at` column (`DETAILED-SPEC.md` §1.4)

### B6. Payments
- Deposit / partial payment support
- Multiple payment gateways simultaneously (Stripe + PayPal; Paddle/Mollie are further candidates, added to the registry the same way once there's demand) — each gateway is its own toggle block on the Payments screen, not exclusive tabs
- **WooCommerce is NOT a gateway in this list** — routing checkout through WooCommerce's own configured gateways is the separate WooCommerce bridge (Part C launch step 6 / `ROADMAP.md` Phase 5), not another entry here
- Coupon/discount codes
- Package/membership pre-purchase (buy 10 sessions, redeem over time)
- Automated refund handling on cancellation
- Seasonal/dynamic pricing — different price by date range, day of week, or booking lead time (early-bird and last-minute rate adjustments)
- Cart checkout — book multiple bookable items, services, or event tickets in one transaction and pay once
- Per-service or per-region tax/VAT rules, with the rate broken out on the invoice (Free covers one flat site-wide rate, see A7)
- **Add-ons / extra services (added 2026-09-05):** optional priced extras a customer attaches to a booking (e.g. "+30 minutes," "gift wrap," "extra guest"), each with its own price, added to `payment_amount` alongside the base price — `wp_byzo_addons` (Pro-only table), `bookings.addons` JSON column already reserved on Free's own schema (`DETAILED-SPEC.md` §1.4/§1.6)
- **Flat booking/service fee (added 2026-09-05):** an additional fixed charge on top of price, separate from tax, broken out as its own invoice line

### B7. Type-Specific Pro Modules

#### Appointment Pro
- Staff-specific service assignment and pricing
- "Any available staff" auto-assignment
- Staff calendar sync (Google Calendar / Outlook two-way)
- Commission/payout tracking per staff (optional)

#### Table Pro
- Visual floor plan / table layout editor (drag-drop)
- Multiple service windows per day
- Combine/split tables for larger parties
- Waitlist for walk-ins during full periods

#### Event Pro
- Multiple ticket tiers (Early Bird, Regular, VIP) with different pricing
- QR-code e-tickets
- Mobile check-in scanner (web-based, no app needed)
- Attendee list export, capacity tracking per tier
- Seating chart (optional, for smaller venues)

#### Slot/Class Pro
- Recurring class series (e.g. weekly yoga class with a fixed roster)
- Class package redemption (use credits from a pre-purchased pack)
- Instructor/bookable item rotation for recurring series

### B8. Advanced Analytics & Reporting
- Revenue dashboard (by bookable item, by booking type, by date range)
- Occupancy rate / utilization rate
- No-show rate tracking and trends
- Customer retention/repeat-booking metrics
- Exportable reports (CSV, scheduled email reports)
- **Popular bookable items / time slots / staff (added 2026-09-05):** which bookable items and time-of-day get booked most, for staffing and pricing decisions — a straightforward aggregate query against existing tables
- **Booking trend charts (added 2026-09-05):** daily/weekly/monthly, on top of the existing count/revenue numbers
- **Conversion rate (added 2026-09-05):** widget views vs. completed bookings — needs a view-tracking mechanism that doesn't exist today, see `DETAILED-SPEC.md` §5 item 11 before building

### B9. Integrations
- WooCommerce bridge (map bookable items to WC products, use WC cart/checkout, reuse WC payment gateways)
- Zapier/Make integration
- REST API access for custom integrations
- Google Calendar / Outlook two-way sync
- Webhooks — fire an HTTP callback on booking created/updated/cancelled/completed for custom integrations
- Marketing pixel integration (Facebook Pixel, Google Analytics, Google Tag Manager) on the booking widget
- Subscribable calendar feed — one .ics URL covering all of a bookable item's (or the whole business's) bookings, for feeding into Airbnb/Booking.com-style external calendars

### B10. Branding & Customization
- White-label booking widget (remove plugin branding)
- Custom CSS/theme options for the booking form
- Custom domain for standalone booking page (if a hosted booking page option exists)

### B11. Advanced Form Builder & Localization
- Custom form field builder — add arbitrary fields (dropdown, checkbox, file upload, number, date, address with a Google Maps link) beyond Free's fixed name/email/phone/notes set; fields can be scoped per-service and shown conditionally
- Multilingual booking-facing content — translate service, category, bookable item, and event names per visitor language (this is translating the site owner's own content; UI-string translation via WPML/Loco Translate already works in Free, see A5)

### B12. Multi-Site / Agency Features (higher tier)
- License covering multiple client sites
- Centralized management across sites (for agencies managing several client installs)

---

## PART C — LAUNCH SEQUENCING (Step-by-step)

**REVISED 2026-09-02** — the original plan shipped one booking type at a time (Appointment Free → Appointment Pro → add types one by one). Superseded: all four types now go into Free BEFORE the first WordPress.org submission, and the "all-in-one" positioning is the launch story from day one, not a later phase.

1. ✅ **Core engine only** — Bookable Item, Availability, Slot, Booking entities + REST API + Vue admin shell (no public release) — DONE 2026-09-02
2. ✅ **Appointment built first as the reference implementation** — full vertical slice (wizard → bookable item → availability → calendar → widget → notifications → payments → reporting) proving the engine end to end — DONE 2026-09-02 (ROADMAP.md Phase 1)
3. **Extend Free to all four types** (ROADMAP.md Phase 1.5): type-first Add New flow, per-bookable item shortcodes, capacity/party-size plumbing, Event's one-off date mode, type-aware widget/emails/copy, plus the standing Free gaps (cancellation policy, bookable item photo). This happens BEFORE submission — the WP.org listing leads with "all-in-one" from its first day.
4. **Submit Free to WordPress.org** — readme/screenshots/plugin-check redone for the four-type positioning
5. **Add Pro tier** — extra features only, across all types (unlimited bookable items per type, multi-shift, deposits, SMS/WhatsApp, self-service, analytics, and the B7 type-specific modules) — validate people will actually pay
6. **WooCommerce bridge** as a separate optional add-on once core is stable
7. **Agency/multi-site tier** last, once there's a base of paying single-site customers

Each numbered step is still a shippable, testable release boundary — the four types share one engine (Phase 0), so step 3 is mostly UI + one new occurrence model for Event, not four separate builds.

---

## Naming

- **Plugin name (brand):** Bookyzo
- **Display name (WP.org listing / plugin header):** "Bookyzo – Booking & Appointments" — descriptive suffix decided 2026-09-02 so the listing surfaces for generic "booking"/"appointment" searches on WordPress.org; kept generic (not "Appointment Booking") since Table/Event/Slot types are on the roadmap and would otherwise force a rename later.
- WP.org slug and .com domain both confirmed clear via DNS/API checks on 2026-09-02 (not registrar-confirmed yet — do that before public announcement)
- Convention: `bookyzo` for Free, `bookyzo-pro` for Pro (matching the `codeholt-bundles-for-woocommerce` / `codeholt-bundles-for-woocommerce-pro` pattern used on other Codeholt plugins)

## Brand

- **Primary color:** `#2563eb` (deep blue) — deliberately distinct from other Codeholt products' admin colors (license-envato's indigo/green). Full design token set and Vue component conventions in `.claude/skills/bookyzo-design/SKILL.md`.
