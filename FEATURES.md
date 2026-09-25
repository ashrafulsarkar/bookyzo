# Bookyzo — Feature Specification

**Positioning:** One WordPress plugin covering Appointment + Table + Event + Generic Slot booking. WooCommerce-independent core, with an optional WooCommerce bridge. Built for SMBs that currently juggle multiple booking tools.

> **DIRECTION CHANGE 2026-09-02 (supersedes the original staged plan):** ALL FOUR booking types ship in the **Free** plugin together. Pro no longer gates booking types at all — Pro is extra features only (more bookable items per type, multi-shift, deposits, SMS/WhatsApp, self-service, analytics, and the type-specific Pro modules in B7). Free's per-type limit (one active bookable item per type) is the honest upgrade wall: 1 staff + 1 table + 1 event + 1 slot-type bookable item free; a 2nd of *any* type needs Pro.

> **COMPETITOR GAP-FILL 2026-09-03:** cross-checked this spec against Bookly, Booking Calendar (wpdevelop), and Amelia — the three leading WordPress booking plugins — feature by feature (see the "Booking Plugin Ladder" audit). Added everything real that was missing: custom form field builder, seasonal pricing, cart checkout, timeline view, availability search, webhooks, marketing pixels, multi-user staff logins, tax handling, customer CSV import/export, bulk marketing campaigns, and multilingual content. One deliberate tier change: **booking confirmation's "add to calendar" link moved from Pro to Free** (A5) — all three competitors give this away free, so gating it was an unnecessary wall, not a real limit.

> **ONBOARDING & DASHBOARD DEEPENED 2026-09-03:** user supplied a full onboarding-wizard + dashboard UX spec (welcome → type → booking details → availability → customer fields → pricing → confirmation/notifications → review → create → success → dashboard). Reconciled against Bookyzo's already-decided architecture rather than adopted verbatim — three real deviations, called out here so they're not missed on a skim: **(1)** the source spec's 7 booking-type options collapse onto Bookyzo's existing 4 (`Time Slot`→Slot, `Table Reservation`→Table, `Event/Ticket`→Event, `Room/Resource`→Slot, `Service`→ Appointment's own service field) — **`Custom Booking` (freeform user-defined types) is explicitly NOT adopted**, it would need a dynamic type system instead of the fixed `type` enum column every table/query is built around (§1.1); flag to the user if they actually want that later, it's a bigger architectural call than this pass makes alone. **(2)** the spec's flat "Availability" top-level nav item stays **bookable item-scoped** (a tab on each bookable item's edit screen, A2/A3) rather than becoming its own admin page — matches Bookyzo's existing bookable item-centric model, not the source spec's flatter one. **(3)** the spec's dashboard "Revenue" stat is added to **Free** (new A8) as one summed total for the period — Amelia's own free tier already does this (see the competitor audit above) — while the *breakdown* by bookable item/type/date-range stays Pro (B8), unchanged. Genuinely new (not a reconciliation, an actual gap): **auto-confirm vs. manual-approval** as a real Free setting (A4) — every booking previously always landed on `pending` with no way to skip that.

> **UNIVERSAL-BOOKING-PLUGIN GAP AUDIT 2026-09-05:** user supplied a full feature wishlist for a from-scratch "Universal Booking Plugin" (28 categories). Cross-checked against Bookyzo's existing scope — most items were already covered, often just named differently (the wishlist's "Service"/"Room-Resource" booking types already map onto Appointment/Slot per the 2026-09-03 onboarding-reconciliation callout above; SMS/WhatsApp, multi-location, deposits, analytics, QR check-in, Google Calendar sync, etc. were already in Part B). Genuinely new items added below: location/online-meeting-link field (A2), a `rejected` status distinct from `cancelled` + basic admin reschedule + an agenda calendar view (A4), add-on/extra-service line items + a flat booking/service fee (B6), a downloadable PDF invoice (B4), an automated post-visit follow-up email (B5), and explicit analytics breakdowns — popular bookable items/time slots/staff, booking trend charts, conversion rate (B8). **One item flagged, not adopted:** a bookable item needing a second entity free at the same time (e.g. a room, alongside staff) is a real architectural fork, not a feature-list addition — see `DETAILED-SPEC.md` §5 item 9, `ROADMAP.md`'s matching callout.

> **CUSTOMER DASHBOARD MOVED TO FREE 2026-09-06 (user decision):** self-service customer accounts — register, log in, view/reschedule/cancel your own bookings — move from Pro (the old B4) into **Free** as new **A11**, gated by a site-wide admin on/off toggle (default OFF — turning it on opens a real registration/login surface on the site, not something to silently enable). This was Free's most-named self-service gap (see the now-removed "no customer self-service" row in `ROADMAP.md`'s conversion table) and every competitor audited so far ships some form of it free, not paid. Accounts are real WordPress users (a new, zero-capability `byzo_customer` role — never wp-admin access) authenticated by password through WP core's own `wp_signon()`/lost-password machinery, not a magic-link/custom-session scheme — simpler to build correctly (reuses core-hardened auth code instead of hand-rolling it) and the foundation any future Pro extension on a real identity would need anyway. Guest booking is completely unaffected — the existing one-off "Manage Booking" signed-token link (A5) still works exactly as before for anyone who never creates an account. In its place, Pro's B4 gets a genuinely new, narrower feature: **per-ticket attendee details** — an Event booking with quantity > 1 collects each ticket's own name/email instead of one contact name for the whole purchase. Full schema/API detail in `DETAILED-SPEC.md`'s same-day companion callout; build order in `ROADMAP.md`'s new Phase 1.7.

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
- Business profile (name, currency, working hours; the timezone is WordPress's own) collected once, upfront, before the type-specific steps — unchanged from the current wizard.

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
- **Basic reschedule (added 2026-09-05, Phase 1.6):** move an existing booking to a different available slot from its detail view — releases the old slot's capacity, atomically claims the new one, same underlying mechanics as a fresh booking.
- **Drag-and-drop reschedule on the calendar (reclassified from Pro's B3 2026-09-12, Phase 2 step 11):** drag a booking block on the calendar grid to a different day/time — the same unrestricted reschedule action above, just triggered by a drag gesture instead of the detail view's dropdown. Originally scoped as Pro in B3, but the underlying endpoint has been fully free since it shipped (Phase 1.6) with no capability check — gating only the drag *gesture* while the dropdown stays free would be a UI-trigger distinction with no real backend difference behind it, not a genuine limit (see `DETAILED-SPEC.md` §3 B3's note).
- Basic booking list with filters (date, status, bookable item)
- Single cancellation policy (fixed time-limit rule)

### A5. Customer-Facing Booking Widget
- Gutenberg block + shortcode for the booking form
- **Per-bookable item shortcodes (decided 2026-09-02):** every bookable item gets its own shortcode (`[bookyzo_booking bookable_item_id="X"]`, pins the widget to that bookable item and skips the picker step), displayed with a copy button in the Bookable Items list — Contact-Form-7 style. Also: `[bookyzo_booking type="table"]` (picker filtered to one type) and bare `[bookyzo_booking]` (all active bookable items). The Gutenberg block gets the same bookable item/type selection.
- Multi-step booking flow (select bookable item/service → pick slot → enter details → confirm)
- **Month-calendar slot picker (added 2026-09-10):** the date step is a real calendar grid with month navigation and available days highlighted, rather than a flat list of date chips; times are grouped Morning/Afternoon/Evening, and a Continue button confirms the choice. The details and confirmation steps show a summary card of what's being booked (service, when, where) — and, since 2026-09-11, the **price or total** (price × quantity plus the flat tax rate, the same arithmetic the server charges; "Free" for zero-price items), so the confirm button is never pressed without the amount on screen. Every price in the widget, admin and account pages now renders through one currency-aware formatter (`src/lib/money.js`, e.g. `$65.00`) instead of a bare `65.00`. **Event bookings skip the calendar** — since an event is a set of specific dated occurrences, the widget lists those occurrences directly (date, time, seats remaining) instead of asking for a date and then a time.
- **Party size / quantity input** for capacity>1 bookable items (Table: "Party size", Event: "Tickets", Slot: "Spots") — capped by the slot's remaining capacity; hidden entirely for Appointment (always 1). Widget labels are type-aware. **For Table this is asked before the times are shown** (added 2026-09-11) and filters them to slots that can actually seat the party, rather than letting someone pick a time that turns out not to fit.
- **Type-aware widget wording (added 2026-09-11):** each booking type speaks its own language throughout the flow — a restaurant reads "Choose a table / Reserve table / Reservation received", an event reads "Choose an event / Get tickets / Tickets booked", a class reads "Choose a session / Reserve spot", and appointments keep "Choose a service / Confirm booking". Remaining-capacity wording follows too ("3 seats left" vs "3 tickets left" vs "3 spots left").
- Guest checkout (no account required)
- Basic custom form fields (name, email, phone, notes)
- Mobile-responsive booking widget
- **Widget appearance settings (added 2026-09-10):** an Appearance admin screen with preset controls for the widget's colors (accent, text, borders, background), corner rounding, maximum width, base font size, font family, and drop shadow, with a live preview. Each control maps onto a CSS custom property the widget stylesheet already declares, and only values that actually differ from the defaults are emitted — a site that never opens the screen renders identical markup to before. Free-form custom CSS and full theme authoring remain a Pro concern (B10); add-ons extend the output through the `byzo_appearance_css_vars` / `byzo_appearance_css_declarations` filters.
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
- One payment gateway integration (Stripe, pick one for Free) — a paid booking is marked paid and confirmed automatically whether or not Stripe's webhook can reach the site (fixed 2026-09-11: the widget confirms with the server too, and the server re-checks the payment with Stripe before believing it), so local, staging, and firewalled installs don't leave paid bookings stuck as pending
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
- **Settings** — business profile, tax rate, auto-confirm toggle, cancellation policy, customer accounts toggle (A11)
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
- **Privacy tools — DONE 2026-09-25 (QA report §6.2.5):** WordPress's own Tools → Export / Erase Personal Data find a person's customer profile and bookings by email. Erasing anonymises bookings and keeps the record. A booking that hasn't happened yet is kept and reported, so it can be cancelled first. There is also suggested text for the privacy policy under Settings → Privacy. Pro adds its class series and names the booking-form answers.

### A11. Customer Account & Dashboard (moved from Pro 2026-09-06)

Self-service so a business stops fielding "can I reschedule?" calls/emails for every little change — the single most-requested gap found across the competitor audit (Bookly/Booking Calendar/Amelia all ship some form of this on their free tier).

- **Admin on/off toggle** (Settings, default OFF): while off, the account/dashboard shortcode and its registration/login routes don't exist at all — no half-built or visibly-disabled state, the same "absent, not locked" rule the Free/Pro boundary already follows (Guideline 5), even though this particular toggle has nothing to do with Pro.
- Real WordPress accounts on a new `byzo_customer` role — zero admin capabilities, can never reach wp-admin. Registration/login/logout/forgot-password all use WordPress core's own account machinery (`wp_insert_user()`, `wp_signon()`, the native lost-password/reset screens) rather than a custom session system — a deliberate choice over the guest one-off link's token scheme (A5), since a *persistent* login needs a real account either way, and reusing core auth is both less custom code and more battle-tested than hand-rolling it.
- **No email verification at registration (v1)** — a booking with a fabricated email already carries the same trust level today's guest checkout does (nothing currently verifies a guest's email either), so an account shouldn't need a stronger bar than checkout already clears. Revisit only if real abuse shows up.
- `[bookyzo_account]` shortcode: a login/register form when logged out, the dashboard when logged in. Registering with an email that already has a guest customer record (A10) simply links to it — that customer's full existing booking history shows up immediately, no separate "claim your bookings" flow needed.
- Dashboard: the logged-in customer's own upcoming + past bookings, reschedule (reuses A4's existing reschedule action) and cancel (reuses A4's existing single cancellation-policy window — the same `cancellation_hours` rule the guest token-link already respects) — scoped strictly to their own bookings, never another customer's.
- **Booking-time opt-in**: a "Create an account" checkbox + password field on the widget's contact-details step (A5). Unchecked (default) books as a guest exactly as today; checked, the account is created in the same `POST /bookings` call, linked to the customer record that call already upserts (A10).
- Saved details for faster repeat booking (moved here from the old B4) — a logged-in customer's next booking pre-fills name/email/phone from their account. **Built 2026-09-22** (QA report B12 found it listed but not wired): the widget prefills from the customer record (falling back to the WP profile) and hides the "Create an account" opt-in for anyone already logged in.

---

## PART B — PRO PLUGIN (Paid upgrade)

Pro should unlock everything a growing/multi-location business actually needs — the things that create real operational pain when missing.

**When a Pro license expires (decided 2026-09-25, QA report B14):** nothing the site already uses switches off. Extra bookable items stay bookable, and services, pricing rules, reminders, the floor plan, check-in, payments and every other Pro screen keep working, including editing and deleting what exists. What stops is creating new Pro things: services, pricing rules, add-ons, form fields, ticket tiers, webhooks, class series, calendar connections, WooCommerce-linked items, campaigns, reminder rules, and active bookable items beyond Free's one per type. Automatic updates stop too. A revoked or suspended license (refund, abuse) is different: that switches Pro off completely. Renewing takes effect on the next daily check, or straight away with "Check again" on the License screen.

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
- ~~Drag-and-drop reschedule on the calendar~~ — **moved to Free's A4 2026-09-12** (Phase 2 step 11) — the underlying reschedule action has been unrestricted since Phase 1.6, so gating just the drag gesture had no real capability behind it.
- **Bulk actions (bulk cancel, bulk status change) — DONE 2026-09-12, Phase 2 step 11; moved 2026-09-25:** tick bookings on the main Bookings list, then Confirm / Complete / Mark no-show / Cancel them together, `POST /bookings/bulk-status`. (Until 2026-09-25 this lived on a separate "Bulk & Notes" screen that listed the same bookings a second time — QA report §4.9.)
- **Booking notes/internal tags (staff-only visibility) — DONE 2026-09-12, Phase 2 step 11; moved 2026-09-25:** edited in the booking popup (from Bookings or Calendar); tags also show on the Bookings list. `bookings.internal_notes`/`tags` (Free schema, Pro-only write UI).
- Customer booking history and profile (repeat customer detection)
- Multiple cancellation/refund policy tiers (flexible per bookable item type)
- Timeline view — all bookable items of a type shown as rows with time across the top, for scanning multi-bookable item availability at a glance (**screen built 2026-09-22**, Pro → Overview → Timeline) (only useful once Pro's unlimited bookable items are in play)
- Availability search — customers filter bookable items by date, capacity, or tags once more than one bookable item of a type exists (the per-item tag field itself ships with this — it was dropped from Free on 2026-09-06, since with Free's four-item cap nothing read it; see `DETAILED-SPEC.md` §1.1)
- Export the customer list to CSV, including each customer's booking and payment history — "Export CSV" on the Customers screen, next to Import (moved there 2026-09-25 from the old "Bulk & Notes" screen).
- **Duplicate booking — DONE 2026-09-12, Phase 2 step 18:** clone an existing booking's bookable item/customer/details as the starting point for a new one — walk-in regulars, recurring phone bookings that don't need a full series. A "Duplicate" action in the booking popup (the "Bulk & Notes" screen until 2026-09-25); calls the same unrestricted `POST /bookings` every other booking path already uses, no new backend surface.

### B4. Advanced Ticketing (renamed from "Customer Account & Self-Service" 2026-09-06 — the account/dashboard half of this section moved to Free, see A11)
- **Per-ticket attendee details — DONE 2026-09-12, Phase 2 step 20:** an Event booking with quantity/party size > 1 collects each ticket's own name (and optionally email) instead of one contact name for the whole purchase — e.g. buying 10 conference tickets prompts for all 10 attendees before checkout completes. Will feed B7 Event Pro's attendee list export and QR e-tickets once that still-unbuilt type-module exists; data collection starts now so nothing's missing retroactively. Distinct from B11's custom form field builder, which adds arbitrary new fields to a booking as a whole — this repeats one small fixed field set once per ticket.
- **Downloadable PDF invoice/receipt — DONE 2026-09-12, Phase 2 step 18:** branded, itemized (price, add-ons, booking fee, tax, deposit paid) — a "Download invoice" link on the now-Free dashboard (A11), only shown once Pro's PDF renderer is actually active; the formatted/downloadable file itself stays Pro, same "Free shows the number, Pro brands the document" line B10 already draws elsewhere. Built with dompdf (`DETAILED-SPEC.md` §5 item 10 resolved this step).

### B5. Notifications & Communication
- SMS notifications (via a chosen SMS gateway)
- WhatsApp Cloud API integration (confirmation, reminder, cancellation)
- Multiple, customizable reminder timings (e.g. 48h + 2h before)
- Visual email template builder (branding, logo, colors)
- **Admin real-time alerts (new booking, cancellation, no-show) — DONE 2026-09-25 (QA report §6.2.8):** any Bookyzo admin screen left open checks every 30 seconds. Each new booking, cancellation or no-show shows as a notification with a "View" button. A count sits on the sidebar's Bookings item until Bookings is opened. In a background tab the count goes into the tab title, and the notifications wait until you come back. No push service or websocket to set up.
- **Bulk/marketing campaigns — DONE 2026-09-12, Phase 2 step 15; rebuilt 2026-09-24:** send a one-off email or SMS blast to a filtered customer segment (tag, last-booking-before date, minimum no-show count, or only the customers who agreed to marketing email when they booked) — the "Campaigns" admin screen, `BYZOP_Campaigns`. Pressing Send queues the campaign and returns at once; it goes out in background batches of 25, so a long list can't time out the admin's own request, and the same screen lists the send history with live progress (sent / failed / skipped per campaign). Every email carries a one-click unsubscribe link — added automatically when the message doesn't place `{unsubscribe_link}` itself — and an unsubscribed customer drops out of every future segment. The consent checkbox on the booking form and the unsubscribe page itself are Free code (`BYZO_Marketing`), shown only on a site that actually sends campaigns.
- **Automated post-visit follow-up / thank-you email — DONE 2026-09-12, Phase 2 step 18:** e.g. a review-request link, sent a configurable number of hours after a booking is marked `completed` — distinct from the bulk/marketing campaigns above (a one-off segment blast); this fires automatically per booking, keyed off `bookings.completed_at` (`DETAILED-SPEC.md` §1.4). New "Post-visit follow-up" section on the renamed "Automated Messages" screen.

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
- **Add-ons / extra services — DONE 2026-09-12, Phase 2 step 17:** optional priced extras a customer attaches to a booking (e.g. "+30 minutes," "gift wrap," "extra guest"), each with its own price, added to `payment_amount` (and taxed) alongside the base price — `wp_byzo_addons`, new "Add-ons & Fees" admin screen, selectable checkboxes on the booking widget with a live-updating total.
- **Flat booking/service fee — DONE 2026-09-12, Phase 2 step 17:** an additional fixed charge on top of price, separate from tax, broken out as its own invoice line — a per-bookable-item flat amount, once per booking (not multiplied by party size), same "Add-ons & Fees" screen.

### B7. Type-Specific Pro Modules

#### Appointment Pro — module complete
- **Staff-specific service assignment and pricing — DONE 2026-09-11, Phase 2 step 5:** `wp_byzo_services` + per-staff price overrides, "Services" admin screen.
- **"Any available staff" auto-assignment — DONE 2026-09-11, Phase 2 step 5:** tries each candidate staff's slot in turn until one's atomic capacity claim succeeds. **Booking UI added 2026-09-22 (QA report B9):** the widget lists services first, then a "Choose who you'd like" step ("Any available" + each staff member with their price), and the admin's manual booking modal has a Service picker for staff who offer services.
- **Staff calendar sync (Google Calendar / Outlook two-way) — DONE 2026-09-12, Phase 2 step 9:** bring-your-own OAuth app, push (booking → external event) and pull (external busy time → blocked slot) both ways.
- **Commission/payout tracking per staff (optional) — DONE 2026-09-12, Phase 2 step 12:** not restricted to Appointment-type items despite the name — any bookable item can carry a commission rate. "Payouts" admin screen.
- **Per-item fees in the item dialog — DONE 2026-09-25 (QA report §4.8):** an item's tax-rate override, booking fee and commission are one "Fees & payouts" step of the same add/edit dialog as everything else about the item, and show on its Review step — before, each was only a row on its own screen (Pricing, Add-ons & Fees, Payouts), none of them where the item is edited. Those screens stay as the at-a-glance lists.

#### Table Pro — module complete
- **Visual floor plan / table layout editor (drag-drop) — DONE 2026-09-12, Phase 2 step 12:** native HTML5 drag-and-drop, purely visual position/size (`bookable_items.floor_x/y/width/height/shape`), no effect on booking/slot logic. New "Floor Plan" admin screen. **Live view added 2026-09-25 (QA report §4.13):** the screen now opens in "Live" mode — every table coloured and labelled by its state at a chosen moment (now by default, following the site clock minute by minute): seated (who, until when), arriving within the hour, free (and when the next booking is), or combined into another table's booking. Click a table for its whole day. "Arrange" is the old drag/resize editor. Built from the day's `GET /bookings` in the site's own timezone (`window.byzopAdmin.siteNow`, offset against the browser clock), so no new REST route.
- **Multiple service windows per day — already covered, confirmed 2026-09-12, Phase 2 step 12:** same capability as `byzo_max_shifts_per_day`, unlocked universally since steps 2/3 (Table included) — nothing separate to build.
- **Combine/split tables for larger parties — DONE 2026-09-12, Phase 2 step 12:** the primary table keeps the real booking (optionally updated to the new combined party size); each additional table's overlapping slot flips to `'blocked'`, the same mechanism blackout dates/calendar-sync already use. "Combine tables"/"Uncombine tables" actions in the booking popup of a table booking (the "Bulk & Notes" screen until 2026-09-25).
- **Waitlist for walk-ins during full periods — DONE 2026-09-12, Phase 2 step 12:** a physical right-now-arrivals queue (`wp_byzo_walkin_waitlist`) — genuinely distinct from the still-unbuilt general "notify when a slot frees up" waitlist below. New "Waitlist" admin screen.

#### Event Pro — module complete
- **Multiple ticket tiers (Early Bird, Regular, VIP) with different pricing — DONE 2026-09-12, Phase 2 step 12:** `wp_byzo_ticket_tiers`, capacity enforced per-tier AND at the event's own level simultaneously. New "Ticket Tiers" admin screen; a tier picker on the public widget when tiers exist.
- **QR-code e-tickets — DONE 2026-09-12, Phase 2 step 12:** one ticket per booking (not per attendee), a scannable PNG QR on the customer dashboard once downloaded/shown at the door.
- **Mobile check-in scanner (web-based, no app needed) — DONE 2026-09-12, Phase 2 step 12:** new "Check-in" admin screen, camera-based QR scanning (client-side decode, no app) plus a manual booking-ID fallback.
- **Attendee list export, capacity tracking per tier — DONE 2026-09-12, Phase 2 step 12:** CSV export expanding per-ticket attendee details (B4) with a purchaser fallback; capacity tracked per tier via `capacity_remaining`.
- Seating chart (optional, for smaller venues) — deferred, reuses Table Pro's floor-plan mechanism once there's concrete demand.

#### Slot/Class Pro
- **Recurring class series (e.g. weekly yoga class with a fixed roster) — DONE 2026-09-12, Phase 2 step 12:** `wp_byzo_class_series` — a series is just N already-generated weekly slots claimed by the same customer, grouped afterward. New "Class Series" admin screen.
- Class package redemption (use credits from a pre-purchased pack) — deferred, needs its own payment mode.
- Instructor/bookable item rotation for recurring series — deferred, needs concrete demand.

### B8. Advanced Analytics & Reporting
- Revenue dashboard (by bookable item, by booking type, by date range)
- Occupancy rate / utilization rate
- No-show rate tracking and trends
- Customer retention/repeat-booking metrics
- Exportable reports (CSV, scheduled email reports)
- **Popular bookable items / time slots / staff — DONE 2026-09-12, Phase 2 step 10 (confirmed/closed step 19):** which bookable items and time-of-day get booked most, for staffing and pricing decisions — Appointment-type bookable items are staff in this project's model, so no separate "staff" query was needed.
- **Booking trend charts — DONE 2026-09-12, Phase 2 step 10 (confirmed/closed step 19):** daily/weekly/monthly, on top of the existing count/revenue numbers — rendered on the Analytics screen as plain CSS bars.
- **Conversion rate (added 2026-09-05):** widget views vs. completed bookings — needs a view-tracking mechanism that doesn't exist today, see `DETAILED-SPEC.md` §5 item 11 before building

### B9. Integrations
- WooCommerce bridge (map bookable items to WC products, use WC cart/checkout, reuse WC payment gateways)
- Zapier/Make integration
- REST API access for custom integrations
- Google Calendar / Outlook two-way sync
- **Webhooks — DONE 2026-09-12, Phase 2 step 15:** fire an HMAC-signed HTTP callback on `booking_created`/`booking_status_changed` for custom integrations — new "Integrations" admin screen, `wp_byzo_webhooks`.
- **Marketing pixel integration (Facebook Pixel, Google Analytics, Google Tag Manager) — DONE 2026-09-12, Phase 2 step 15:** base tracking snippets only, injected on the booking widget via a new Free-side `byzo_widget_after_mount` filter; deeper conversion-event tracking flagged as a future piece.
- **Subscribable calendar feed — DONE 2026-09-12, Phase 2 step 15:** one token-gated `.ics` URL per bookable item, for feeding into Airbnb/Booking.com-style external calendars — new "Integrations" screen shows a copyable link per item.

### B10. Branding & Customization
- ~~White-label booking widget (remove plugin branding)~~ — **DROPPED 2026-09-22 (owner decision, QA report B13):** no plugin branding is shown on the widget or account pages, so there is nothing to remove. Not planned; the Get Pro comparison lists only "Custom CSS for the booking widget".
- **Custom CSS for the booking form, full theme authoring beyond Free's preset appearance controls (A5) — DONE 2026-09-22:** a "Custom CSS" section (`BYZOP_Appearance`) rendered inline on Free's own Appearance screen via the shared admin app's extension point (`extraAppearanceSection`, `src/lib/admin-extensions.js`) rather than a separate Pro page — same never-replace-the-screen rule, just plugged in as an added section instead of through the `byzo_appearance_css_vars`/`byzo_appearance_css_declarations` filters originally sketched here: those two only ever land inside one already-open `.bookyzo-widget{...}` block, which can't express an arbitrary child selector the way real theme authoring needs. Stored on `byzop_settings.custom_css` (`GET/PUT /pro-settings`, `wp_strip_all_tags` sanitized), printed as its own `<style>` tag from `wp_footer` once a booking widget/account screen has actually enqueued on the page.
- Custom domain for standalone booking page (if a hosted booking page option exists)

### B11. Advanced Form Builder & Localization
- **Custom form field builder — DONE 2026-09-12, Phase 2 step 16:** add arbitrary fields (dropdown, checkbox, number, date, address) beyond Free's fixed name/email/phone/notes set, global or scoped to one bookable item — new "Form Fields" admin screen, `wp_byzo_custom_fields`, rendered live on Free's public widget via a new `byzo_custom_fields_for_bookable_item` filter. **File upload fields deliberately deferred** (needs a real upload endpoint/storage/security scanning, a separate feature); the address type ships as plain text for v1, Google-Maps-autocomplete linking left for real demand.
- Multilingual booking-facing content — translate service, category, bookable item, and event names per visitor language (this is translating the site owner's own content; UI-string translation via WPML/Loco Translate already works in Free, see A5)

### B12. Multi-Site / Agency Features (higher tier)
- License covering multiple client sites
- Centralized management across sites (for agencies managing several client installs)

---

## PART C — LAUNCH SEQUENCING (Step-by-step)

**REVISED 2026-09-02** — the original plan shipped one booking type at a time (Appointment Free → Appointment Pro → add types one by one). Superseded: all four types now go into Free BEFORE the first WordPress.org submission, and the "all-in-one" positioning is the launch story from day one, not a later phase.

1. ✅ **Core engine only** — Bookable Item, Availability, Slot, Booking entities + REST API + Vue admin shell (no public release) — DONE 2026-09-02
2. ✅ **Appointment built first as the reference implementation** — full vertical slice (wizard → bookable item → availability → calendar → widget → notifications → payments → reporting) proving the engine end to end — DONE 2026-09-02 (ROADMAP.md Phase 1)
3. ✅ **Extend Free to all four types** (ROADMAP.md Phase 1.5): type-first Add New flow, per-bookable item shortcodes, capacity/party-size plumbing, Event's one-off date mode, type-aware widget/emails/copy, plus the standing Free gaps (cancellation policy, bookable item photo). This happens BEFORE submission — the WP.org listing leads with "all-in-one" from its first day. — DONE 2026-09-05, WP.org visual assets captured 2026-09-08
4. **Submit Free to WordPress.org** — readme/screenshots/plugin-check redone for the four-type positioning. **Code/assets ready, actual submission still NOT done** — manual/external step (registrar account, review queue), never performed autonomously.
5. ✅ **Add Pro tier** — extra features only, across all types (unlimited bookable items per type, multi-shift, deposits, SMS/WhatsApp, self-service, analytics, and the B7 type-specific modules) — validate people will actually pay — DONE 2026-09-12 (ROADMAP.md Phase 2, all 20 steps + all three B7 type-modules)
6. ✅ **WooCommerce bridge** — DONE 2026-09-13 (ROADMAP.md Phase 5, all 5 steps). "Optional add-on" means an optional Pro feature (opt in per bookable item, WooCommerce-conditional) — it ships inside `bookyzo-pro` itself, not as a separate plugin (an earlier same-day pass built it as one and that was corrected; see DETAILED-SPEC.md §3 B9's "Positioning" note).
7. **Agency/multi-site tier** — SKIPPED 2026-09-13 (user decision). Removed from ROADMAP.md; not part of the plan.

Each numbered step is still a shippable, testable release boundary — the four types share one engine (Phase 0), so step 3 is mostly UI + one new occurrence model for Event, not four separate builds.

---

## Naming

- **Plugin name (brand):** Bookyzo
- **Display name (WP.org listing / plugin header):** "Bookyzo – Booking & Appointments" — descriptive suffix decided 2026-09-02 so the listing surfaces for generic "booking"/"appointment" searches on WordPress.org; kept generic (not "Appointment Booking") since Table/Event/Slot types are on the roadmap and would otherwise force a rename later.
- WP.org slug and .com domain both confirmed clear via DNS/API checks on 2026-09-02 (not registrar-confirmed yet — do that before public announcement)
- Convention: `bookyzo` for Free, `bookyzo-pro` for Pro (matching the `codeholt-bundles-for-woocommerce` / `codeholt-bundles-for-woocommerce-pro` pattern used on other Codeholt plugins)

## Brand

- **Primary color:** `#2563eb` (deep blue) — deliberately distinct from other Codeholt products' admin colors (license-envato's indigo/green). Full design token set and Vue component conventions in `.claude/skills/bookyzo-design/SKILL.md`.
