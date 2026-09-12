=== Bookyzo – Booking & Appointments ===
Contributors: codeholt, ashrafulsarkar
Tags: booking calendar, appointment booking, event booking, table reservation, stripe
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Appointments, table reservations, event tickets and generic time-slot bookings — one booking widget, one admin panel, no WooCommerce required.

== Description ==

**Bookyzo** turns your WordPress site into a real booking system, whatever you're booking. Add a staff member for one-on-one appointments, a table for restaurant reservations, an event for ticketed occurrences, or a generic slot for anything else — set its availability, drop a booking form onto any page with a shortcode or block, and start taking bookings. No page builder plugin, no external booking service, no WooCommerce required.

= Why Bookyzo? =

* All four booking types — Appointment, Table, Event, Slot — in the free plugin, not locked behind an upsell
* Runs entirely on your own site — your booking data stays in your own database
* No WooCommerce dependency — works on any WordPress site
* A real Vue-powered admin panel, not a wall of settings screens
* Genuinely usable on the free plan — no artificial limits dressed up as a trial

= Four booking types, one plugin =

* **Appointment** — one staff member, one customer per time slot (haircuts, consultations, tutoring)
* **Table** — a restaurant table or venue space with a party-size capacity
* **Event** — a specific date/time occurrence you create yourself (a class, a workshop, a ticketed show), sold by ticket quantity
* **Slot** — a generic recurring time slot with room for more than one booking at once (a rental, a group session, anything that doesn't fit the other three)

Add a bookable item, set a name/price/photo, and configure the availability that fits its type — a weekly schedule with exceptions for Appointment/Table/Slot, or specific dates you add yourself for Event. Available time slots (or your entered event dates) are kept up to date automatically in the background.

= Booking calendar =

A day/week/month calendar in the admin panel shows every booking, color-coded by status. Click a booking to see its details or change its status, or add a walk-in/phone booking yourself straight from the calendar.

= Booking list & CSV export =

Filter every booking by date, bookable item or status, change status inline, and export the filtered list to CSV whenever you need it outside WordPress.

= Customer booking widget =

Add `[bookyzo_booking]` anywhere with the shortcode, use the **Booking Form** block, or drop it in with the Elementor widget if you build pages that way. Customers pick a time, enter their details, get a booking confirmation, and can add it straight to Google Calendar or download it as an .ics file — no account required.

= Customer management =

Every customer is saved automatically the first time they book — searchable by name, email or phone under **Bookyzo → Customers**, with notes and tags you can add yourself. Already have a customer list elsewhere? Import it from a CSV file.

= Email notifications =

Automatic emails when a booking is received, confirmed or cancelled, plus a reminder email sent the day before it's due, a self-service cancellation link the customer can use themselves right up to your cancellation cutoff, and an "add to calendar" link. Every email's subject and body is a plain-text template you can edit yourself, with placeholders for the customer's name, the bookable item, the booking time and your business name.

= Payments =

Mark bookings as paid manually for cash or in-person payments, or connect a Stripe account to let customers pay online by card when they book. An optional flat tax rate is added to every paid booking's total automatically.

= Dashboard =

See today's, this week's and this month's booking counts at a glance when you log in.

= Developer friendly =

Actions and filters throughout (capability limits, REST controllers, rate limiting, notifications) and a REST API under `bookyzo/v1`. See **Other Notes** for the hook list.

= Coming later (Pro) =

The free plugin is genuinely usable on its own — one active bookable item per type (so up to four: one appointment staff member, one table, one event, one slot type) with no locked-out UI. A paid Pro add-on is planned for businesses that outgrow that: more bookable items per type, multiple daily shifts, deposits, SMS/WhatsApp reminders, a customer self-service dashboard, and booking analytics.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it via the Plugins screen.
2. Activate the plugin — you'll be taken straight into a short setup wizard.
3. Choose what you're booking (appointment, table, event or slot), fill in your business details, and add your first bookable item.
4. Add the `[bookyzo_booking]` shortcode (or the **Booking Form** block) to any page.
5. Optionally connect Stripe under **Bookyzo → Payments** to accept card payments.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Bookyzo has its own booking system and database tables — it doesn't use WooCommerce products or orders at all.

= Can customers book without creating an account? =

Yes. Booking is a guest checkout by default — a customer only needs to give their name, email and phone number.

= Can I edit the confirmation and reminder emails? =

Yes, from **Bookyzo → Emails** in the admin panel. Every email is a plain-text template with placeholders for the customer's name, the bookable item, the booking time and your business name.

= How do reminder emails work? =

An hourly background task checks for bookings starting in roughly 24 hours and sends a reminder — no setup needed beyond keeping the booking confirmed.

= Does this plugin hide or lock any features behind an upsell? =

No. Every feature listed above is fully usable with no artificial limits, nag screens or disabled UI.

= Does Bookyzo support anything besides one-on-one appointments? =

Yes. Free includes all four booking types: Appointment (one staff member, one customer), Table (party-size reservations), Event (ticketed occurrences on dates you set yourself), and Slot (a generic recurring slot with room for more than one booking). Add as many *different* types as you need — the free-plan limit is one active bookable item *of each type*, not one bookable item total.

= What happens if I need a second staff member, table, or event? =

Free supports one active bookable item per type at a time. This maps to when most small businesses genuinely need to upgrade — hiring a second staff member, adding a second table, running a second event alongside the first. A Pro version with these is planned for a future release.

== Screenshots ==

1. The booking calendar — day/week/month view, color-coded by booking status.
2. A bookable item's edit screen — details, photo, and (depending on type) weekly availability with date exceptions, or manually-entered event dates.
3. The customer-facing booking widget — pick a bookable item and time, enter details, confirm.
4. The booking list with filters and CSV export.
5. The Dashboard showing today's, this week's and this month's booking counts.
6. The email template editor.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Other Notes ==

= Filters =

* `byzo_max_bookable_items_per_type` — ( int $max ) Active bookable items allowed per booking type. Default `1`.
* `byzo_max_shifts_per_day` — ( int $max ) Shifts per day in a bookable item's weekly schedule. Default `1`.
* `byzo_business_hours_link_enabled` — ( bool $enabled ) Whether a bookable item may link its schedule to the shared business-hours default instead of keeping its own. Default `false`.
* `byzo_bookable_items_offering_service` — ( int[] $bookable_item_ids, int $service_id ) Which bookable items offer a given service, for "any available staff" resolution. Default `array()`.
* `byzo_service_price` — ( float|null $price, int $service_id, int $bookable_item_id ) Resolved price for a service booked with a specific staff member. Default `null` (falls back to the bookable item's own price).
* `byzo_deposits_enabled` — ( bool $enabled ) Whether deposit/partial payments are available. Default `false`.
* `byzo_deposit_amount` — ( float|null $amount, float $payment_amount, int $bookable_item_id ) How much of a booking's total is due online now as a deposit. Default `null` (pay in full). Only consulted when `byzo_deposits_enabled` is true.
* `byzo_default_settings` — ( array $defaults ) Default values seeded into the business-settings option on activation.
* `byzo_rest_controllers` — ( array $controllers ) REST controller instances registered on `rest_api_init`.
* `byzo_payment_gateways` — ( array $gateways ) Registered payment gateways shown on the Payments settings screen, keyed by gateway id. Default: Stripe only.
* `byzo_payment_gateway_defaults` — ( array $defaults ) Default settings shape for each registered gateway, keyed by gateway id. Default: Stripe only.
* `byzo_validate_payment_gateway_settings` — ( array|WP_Error $current, string $gateway_id, array $data ) Validates and merges a settings patch for a non-Stripe gateway. Only consulted for a gateway that `byzo_payment_gateways` actually registered.
* `byzo_rest_public_permission` — ( bool $allowed, WP_REST_Request $request ) Permission check for guest-allowed REST routes.
* `byzo_booking_rate_limit_max` / `byzo_booking_rate_limit_window` — ( int ) Per-IP guest booking rate limit (default 20 requests / 600 seconds) and its window in seconds.
* `byzo_client_ip` — ( string $ip ) The IP address used for rate limiting.
* `byzo_dev_server_url` — ( string $url ) Vite dev server URL used only in local development.
* `byzo_unit_price` — ( float $price, int $bookable_item_id, array $slot ) Per-booking unit price adjustment applied right after service-price resolution and before tax. Default: unmodified `$price`.
* `byzo_calendar_feed_enabled` — ( bool $enabled ) Whether the subscribable per-bookable-item `.ics` calendar feed REST route is available. Default `false`.
* `byzo_widget_after_mount` — ( string $html, array $config ) HTML appended right after the booking widget's own mount markup — lets an add-on inject unrelated page-level snippets (e.g. marketing pixel base code) without touching the widget markup itself. Default `''`.
* `byzo_custom_fields_for_bookable_item` — ( array $fields, int $bookable_item_id ) Custom form fields to render on the booking widget for a given bookable item, each `{ id, label, field_type, options, required }`. Default `array()`.
* `byzo_appearance_css_vars` — ( string $css, array $settings ) `--prop:value;` custom-property pairs generated from the Appearance settings. Default: the built-in property set, or `''` when nothing was customized.
* `byzo_appearance_css_declarations` — ( string $css, array $settings ) `prop:value;` plain CSS declarations generated from the Appearance settings. Default: the built-in declaration set, or `''` when nothing was customized.

= Actions =

* `byzo_loaded` — Fires once the plugin is fully loaded.
* `byzo_bookable_item_created` — ( int $bookable_item_id ) After a new bookable item is created.
* `byzo_bookable_item_activated` — ( int $bookable_item_id ) After a bookable item transitions from inactive to active.
* `byzo_booking_created` — ( int $booking_id ) After a new booking is created.
* `byzo_booking_status_changed` — ( int $booking_id, string $new_status, string $old_status ) After a booking's status changes.
* `byzo_booking_payment_status_changed` — ( int $booking_id, string $new_status, string $old_status ) After a booking's payment status changes.
* `byzo_notification_sent` — ( string $event, int $booking_id ) After a notification email is sent (or attempted).
* `byzo_notification_channels` — ( string $event, array $booking, array $placeholders ) Fires after a notification email is sent, with the same placeholders — lets an add-on send the same event via another channel (e.g. SMS/WhatsApp).
* `byzo_booking_rescheduled` — ( int $booking_id, int $new_slot_id, int $old_slot_id ) After a booking is moved to a different slot (status itself is untouched, so this is separate from `byzo_booking_status_changed`).
