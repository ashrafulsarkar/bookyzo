# Bookyzo — Feature Specification

**Positioning:** One WordPress plugin covering Appointment + Table + Event + Generic Slot booking. WooCommerce-independent core, with an optional WooCommerce bridge. Built for SMBs that currently juggle multiple booking tools.

**Stack:** WordPress plugin (PHP + custom DB tables), REST API, Vue 3 admin dashboard, optional WooCommerce integration, PayPal and Stripe for payments.

---

## PART A — FREE PLUGIN (WordPress.org core)

The free version should be genuinely usable for a small single-location business, not a crippled demo. Goal: real value + a natural upgrade path.

### A1. Setup & Onboarding
- Setup wizard on first activation (choose which booking type(s) to enable: Appointment / Table / Event / Slot)
- Business profile (name, timezone, currency, working hours)
- Guided resource creation (first staff/table/event walkthrough)

### A2. Resource Management (Core)
- Create/edit resources (staff, table, room, rental item, event — one core entity, type-specific fields)
- Single resource type active at a time on Free (e.g. only "Appointment" OR only "Table")
- Resource photo, description, short tags
- Capacity setting (1 person / N people)
- Resource status (active/inactive/archived)

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
- Multi-step booking flow (select resource/service → pick slot → enter details → confirm)
- Guest checkout (no account required)
- Basic custom form fields (name, email, phone, notes)
- Mobile-responsive booking widget

### A6. Notifications
- Email confirmation on booking
- Email reminder (single, fixed timing — e.g. 24h before)
- Email cancellation notice
- Basic email template editing (text only, no visual builder)

### A7. Payments
- Manual/offline payment option (cash, bank transfer — mark as paid manually)
- One payment gateway integration (Stripe, pick one for Free)
- No deposits/partial payments on Free (Pro feature)

### A8. Basic Reporting
- Total bookings count (day/week/month)
- Simple booking list export (CSV)

### A9. Type-Specific Free Scope
- **Appointment:** 1 staff member, 1 service, fixed duration
- **Table:** single service window (e.g. dinner only), no floor plan visual
- **Event:** single ticket tier, no QR check-in
- **Slot:** basic fixed-duration slots, no recurring class series

---

## PART B — PRO PLUGIN (Paid upgrade)

Pro should unlock everything a growing/multi-location business actually needs — the things that create real operational pain when missing.

### B1. Multi-Everything
- Unlimited resources (multiple staff, tables, rooms, event types simultaneously)
- **All booking types active at once** (Appointment + Table + Event + Slot together — this is the core Pro differentiator vs. single-type competitors)
- Multi-location/multi-branch support with per-location schedules
- Resource grouping/categories

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

### B4. Customer Account & Self-Service
- Customer dashboard (view/reschedule/cancel own bookings)
- Self-service reschedule within policy rules
- Booking confirmation page with calendar add-to-calendar links (Google/Outlook/iCal)
- Saved customer details for faster repeat booking

### B5. Notifications & Communication
- SMS notifications (via a chosen SMS gateway)
- WhatsApp Cloud API integration (confirmation, reminder, cancellation)
- Multiple, customizable reminder timings (e.g. 48h + 2h before)
- Visual email template builder (branding, logo, colors)
- Admin real-time alerts (new booking, cancellation, no-show)

### B6. Payments
- Deposit / partial payment support
- Multiple payment gateways simultaneously (Stripe + PayPal)
- Coupon/discount codes
- Package/membership pre-purchase (buy 10 sessions, redeem over time)
- Automated refund handling on cancellation

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

### B10. Branding & Customization
- White-label booking widget (remove plugin branding)
- Custom CSS/theme options for the booking form
- Custom domain for standalone booking page (if a hosted booking page option exists)

### B11. Multi-Site / Agency Features (higher tier)
- License covering multiple client sites
- Centralized management across sites (for agencies managing several client installs)

---

## PART C — LAUNCH SEQUENCING (Step-by-step)

Build/launch order — narrow scope first, expand after real usage data:

1. **Core engine only** — Resource, Availability, Slot, Booking entities + REST API + Vue admin shell (no public release yet)
2. **Pick ONE booking type** and ship it as the Free WP.org plugin, fully polished — **DECIDED 2026-09-02: Appointment** (see `DETAILED-SPEC.md` §5 and `ROADMAP.md` Phase 1)
3. **Add Pro tier** for that single type (multi-staff, SMS/WhatsApp, deposits, analytics) — validate people will actually pay
4. **Add second booking type** (e.g. Table or Slot) to both Free and Pro, once type #1 has traction
5. **Add third/fourth booking type**, market the "all-in-one" angle only once 2+ types are live and stable
6. **WooCommerce bridge** as a separate optional add-on once core is stable
7. **Agency/multi-site tier** last, once there's a base of paying single-site customers

This keeps each release shippable and testable instead of trying to launch the full four-type system at once.

---

## Naming

- **Plugin name:** Bookyzo
- WP.org slug and .com domain both confirmed clear via DNS/API checks on 2026-09-02 (not registrar-confirmed yet — do that before public announcement)
- Convention: `bookyzo` for Free, `bookyzo-pro` for Pro (matching the `codeholt-bundles-for-woocommerce` / `codeholt-bundles-for-woocommerce-pro` pattern used on other Codeholt plugins)

## Brand

- **Primary color:** `#2563eb` (deep blue) — deliberately distinct from other Codeholt products' admin colors (license-envato's indigo/green). Full design token set and Vue component conventions in `.claude/skills/bookyzo-design/SKILL.md`.
