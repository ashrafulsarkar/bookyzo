# Bookyzo — Feature Specification

**Positioning:** One WordPress plugin covering Appointment + Table + Event + Generic Slot booking. WooCommerce-independent core, with an optional WooCommerce bridge. Built for SMBs that currently juggle multiple booking tools.

> **DIRECTION CHANGE 2026-09-02 (supersedes the original staged plan):** ALL FOUR booking types ship in the **Free** plugin together. Pro no longer gates booking types at all — Pro is extra features only (more resources per type, multi-shift, deposits, SMS/WhatsApp, self-service, analytics, and the type-specific Pro modules in B7). Free's per-type limit (one active resource per type) is the honest upgrade wall: 1 staff + 1 table + 1 event + 1 slot resource free; a 2nd of *any* type needs Pro.

> **COMPETITOR GAP-FILL 2026-09-03:** cross-checked this spec against Bookly, Booking Calendar (wpdevelop), and Amelia — the three leading WordPress booking plugins — feature by feature (see the "Booking Plugin Ladder" audit). Added everything real that was missing: custom form field builder, seasonal pricing, cart checkout, timeline view, availability search, webhooks, marketing pixels, multi-user staff logins, tax handling, customer CSV import/export, bulk marketing campaigns, and multilingual content. One deliberate tier change: **booking confirmation's "add to calendar" link moved from Pro to Free** (A5) — all three competitors give this away free, so gating it was an unnecessary wall, not a real limit.

**Stack:** WordPress plugin (PHP + custom DB tables), REST API, Vue 3 admin dashboard, optional WooCommerce integration, PayPal and Stripe for payments.

---

## PART A — FREE PLUGIN (WordPress.org core)

The free version should be genuinely usable for a small single-location business, not a crippled demo. Goal: real value + a natural upgrade path.

### A1. Setup & Onboarding
- Setup wizard on first activation: choose the FIRST booking type (real 4-option choice: Appointment / Table / Event / Slot — all four available on Free; more types can be added later from the Resources screen, the wizard just picks which one to start with)
- Business profile (name, timezone, currency, working hours)
- Guided resource creation (first staff/table/event walkthrough, copy and defaults matching the chosen type)

### A2. Resource Management (Core)
- **Type-first creation flow (decided 2026-09-02):** "Add New" starts with a booking-type picker (Appointment / Table / Event / Slot), THEN shows the type-specific form — the fields shown depend on the chosen type (see A9 for what each type's form needs). Type is fixed after creation (changing type would orphan the resource's schedule/slots).
- Create/edit resources (staff, table, room, rental item, event — one core entity, type-specific fields)
- All four booking types available on Free; **one active resource per type** (the Free cap — enforced centrally per A9, so up to 4 active resources total, one of each type)
- Resource photo, description, short tags
- Capacity setting (1 person / N people) — editable for Table (seats), Event (total tickets/seats) and Slot; fixed at 1 for Appointment (one-on-one by definition)
- Resource status (active/inactive/archived)
- Resources list shows each resource's own copyable shortcode (see A5) plus a type column/filter

### A3. Availability Engine
- Weekly recurring schedule per resource
- Holiday / blackout date exceptions
- One shift/time-block per day (Free); multiple shifts reserved for Pro
- Minimum advance booking notice (e.g. "must book at least 2 hours ahead")
- Maximum advance booking window (e.g. "up to 30 days ahead")
- Buffer time between bookings
- Automatic slot generation (rolling window, background cron)

### A4. Booking Management
- Unified calendar view (day/week/month)
- Manual booking creation/edit from admin (for phone/walk-in bookings)
- Booking statuses: pending, confirmed, completed, cancelled, no-show
- Basic booking list with filters (date, status, resource)
- Single cancellation policy (fixed time-limit rule)

### A5. Customer-Facing Booking Widget
- Gutenberg block + shortcode for the booking form
- **Per-resource shortcodes (decided 2026-09-02):** every bookable resource gets its own shortcode (`[bookyzo_booking resource_id="X"]`, pins the widget to that resource and skips the picker step), displayed with a copy button in the Resources list — Contact-Form-7 style. Also: `[bookyzo_booking type="table"]` (picker filtered to one type) and bare `[bookyzo_booking]` (all active resources). The Gutenberg block gets the same resource/type selection.
- Multi-step booking flow (select resource/service → pick slot → enter details → confirm)
- **Party size / quantity input** for capacity>1 resources (Table: "Party size", Event: "Tickets", Slot: "Spots") — capped by the slot's remaining capacity; hidden entirely for Appointment (always 1). Widget labels are type-aware.
- Guest checkout (no account required)
- Basic custom form fields (name, email, phone, notes)
- Mobile-responsive booking widget
- Elementor widget, in addition to the Gutenberg block — same resource/type selection
- Translation-ready (WPML / Loco Translate compatible, ships with a .pot file) — this covers UI strings; translating the site owner's own service/category/resource *content* per visitor language is a Pro feature (B11)

### A6. Notifications
- Email confirmation on booking
- Email reminder (single, fixed timing — e.g. 24h before)
- Email cancellation notice
- Basic email template editing (text only, no visual builder)
- **"Add to calendar" link (Google / Outlook / .ics) on the booking confirmation** — moved here from Pro on 2026-09-03: Bookly, Booking Calendar, and Amelia all give this away free, so gating a single per-booking calendar link isn't a real limit

### A7. Payments
- Manual/offline payment option (cash, bank transfer — mark as paid manually)
- One payment gateway integration (Stripe, pick one for Free)
- No deposits/partial payments on Free (Pro feature)
- Optional flat tax/VAT rate, applied site-wide to every booking (single percentage — per-service/per-region rules are Pro, see B6)

### A8. Basic Reporting
- Total bookings count (day/week/month)
- Simple booking list export (CSV)

### A9. Type-Specific Free Scope

All four types are IN Free (direction change 2026-09-02). Free's cap is **one active resource per type**, and each type's Free scope is deliberately basic:

- **Appointment:** 1 staff member, 1 service, fixed duration, capacity fixed at 1. Weekly recurring schedule.
- **Table:** 1 table, capacity = seats, single service window (one shift/day — e.g. dinner only), party-size booking, no floor plan visual. Weekly recurring schedule.
- **Event:** 1 event, single ticket tier (one flat price), capacity = total seats/tickets, quantity booking, no QR check-in. **One-off dated occurrences, NOT a weekly schedule** — an event happens on specific date(s), so event resources use direct occurrence entry (a "Dates" tab) instead of the weekly availability grid; each occurrence is materialized straight into the slots table.
- **Slot:** 1 bookable item, basic fixed-duration slots, capacity = spots per slot, no recurring class series. Weekly recurring schedule.

The per-type cap (1 resource per type) stays enforced centrally via `BYZO_Capabilities::max_resources_per_type()` — a real filterable capability, never scattered checks or visible locks (Guideline 5 rule, see `.claude/skills/bookyzo-dev/SKILL.md`).

### A10. Customer Management
- Customer list auto-populated from bookings, searchable and sortable
- Import an existing customer list from CSV (lowers the switching cost from another booking tool)

---

## PART B — PRO PLUGIN (Paid upgrade)

Pro should unlock everything a growing/multi-location business actually needs — the things that create real operational pain when missing.

### B1. Multi-Everything
- **Unlimited resources per type** (multiple staff, multiple tables, multiple events, multiple bookable items — Free allows exactly one of each; this is the primary volume-based upgrade wall, hit the day a business hires a 2nd staff member or adds a 2nd table)
- ~~All booking types active at once~~ — **moved to Free 2026-09-02.** All four types are Free now; Pro's differentiator is depth per type (unlimited resources + the B7 type-specific modules), not type access.
- Multi-location/multi-branch support with per-location schedules
- Resource grouping/categories
- Multi-user staff logins — link a WordPress user account to a resource so a staff member or branch manager can log in and see/manage only their own bookings (mirrors Booking Calendar's MultiUser edition)

### B2. Advanced Availability
- Multiple shifts per day (e.g. lunch + dinner service windows)
- Per-staff individual schedules that differ from the business default
- Custom availability rules per resource (overrides)
- Recurring bookings (weekly class, monthly appointment series)
- Waitlist with automatic notification when a slot opens up
- Group bookings (multiple attendees under one booking)

### B3. Advanced Booking Management
- Drag-and-drop reschedule on the calendar
- Bulk actions (bulk cancel, bulk status change)
- Booking notes/internal tags (staff-only visibility)
- Customer booking history and profile (repeat customer detection)
- Multiple cancellation/refund policy tiers (flexible per resource type)
- Timeline view — all resources of a type shown as rows with time across the top, for scanning multi-resource availability at a glance (only useful once Pro's unlimited resources are in play)
- Availability search — customers filter bookable resources by date, capacity, or tags once more than one resource of a type exists
- Export the customer list to CSV, including each customer's booking and payment history

### B4. Customer Account & Self-Service
- Customer dashboard (view/reschedule/cancel own bookings)
- Self-service reschedule within policy rules
- Saved customer details for faster repeat booking

### B5. Notifications & Communication
- SMS notifications (via a chosen SMS gateway)
- WhatsApp Cloud API integration (confirmation, reminder, cancellation)
- Multiple, customizable reminder timings (e.g. 48h + 2h before)
- Visual email template builder (branding, logo, colors)
- Admin real-time alerts (new booking, cancellation, no-show)
- Bulk/marketing campaigns — send a one-off email or SMS blast to a filtered customer segment (e.g. no-shows in the last 90 days)

### B6. Payments
- Deposit / partial payment support
- Multiple payment gateways simultaneously (Stripe + PayPal)
- Coupon/discount codes
- Package/membership pre-purchase (buy 10 sessions, redeem over time)
- Automated refund handling on cancellation
- Seasonal/dynamic pricing — different price by date range, day of week, or booking lead time (early-bird and last-minute rate adjustments)
- Cart checkout — book multiple resources, services, or event tickets in one transaction and pay once
- Per-service or per-region tax/VAT rules, with the rate broken out on the invoice (Free covers one flat site-wide rate, see A7)

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
- Instructor/resource rotation for recurring series

### B8. Advanced Analytics & Reporting
- Revenue dashboard (by resource, by booking type, by date range)
- Occupancy rate / utilization rate
- No-show rate tracking and trends
- Customer retention/repeat-booking metrics
- Exportable reports (CSV, scheduled email reports)

### B9. Integrations
- WooCommerce bridge (map resources to WC products, use WC cart/checkout, reuse WC payment gateways)
- Zapier/Make integration
- REST API access for custom integrations
- Google Calendar / Outlook two-way sync
- Webhooks — fire an HTTP callback on booking created/updated/cancelled/completed for custom integrations
- Marketing pixel integration (Facebook Pixel, Google Analytics, Google Tag Manager) on the booking widget
- Subscribable calendar feed — one .ics URL covering all of a resource's (or the whole business's) bookings, for feeding into Airbnb/Booking.com-style external calendars

### B10. Branding & Customization
- White-label booking widget (remove plugin branding)
- Custom CSS/theme options for the booking form
- Custom domain for standalone booking page (if a hosted booking page option exists)

### B11. Advanced Form Builder & Localization
- Custom form field builder — add arbitrary fields (dropdown, checkbox, file upload, number, date, address with a Google Maps link) beyond Free's fixed name/email/phone/notes set; fields can be scoped per-service and shown conditionally
- Multilingual booking-facing content — translate service, category, resource, and event names per visitor language (this is translating the site owner's own content; UI-string translation via WPML/Loco Translate already works in Free, see A5)

### B12. Multi-Site / Agency Features (higher tier)
- License covering multiple client sites
- Centralized management across sites (for agencies managing several client installs)

---

## PART C — LAUNCH SEQUENCING (Step-by-step)

**REVISED 2026-09-02** — the original plan shipped one booking type at a time (Appointment Free → Appointment Pro → add types one by one). Superseded: all four types now go into Free BEFORE the first WordPress.org submission, and the "all-in-one" positioning is the launch story from day one, not a later phase.

1. ✅ **Core engine only** — Resource, Availability, Slot, Booking entities + REST API + Vue admin shell (no public release) — DONE 2026-09-02
2. ✅ **Appointment built first as the reference implementation** — full vertical slice (wizard → resource → availability → calendar → widget → notifications → payments → reporting) proving the engine end to end — DONE 2026-09-02 (ROADMAP.md Phase 1)
3. **Extend Free to all four types** (ROADMAP.md Phase 1.5): type-first Add New flow, per-resource shortcodes, capacity/party-size plumbing, Event's one-off date mode, type-aware widget/emails/copy, plus the standing Free gaps (cancellation policy, resource photo). This happens BEFORE submission — the WP.org listing leads with "all-in-one" from its first day.
4. **Submit Free to WordPress.org** — readme/screenshots/plugin-check redone for the four-type positioning
5. **Add Pro tier** — extra features only, across all types (unlimited resources per type, multi-shift, deposits, SMS/WhatsApp, self-service, analytics, and the B7 type-specific modules) — validate people will actually pay
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
