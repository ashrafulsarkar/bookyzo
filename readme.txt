=== Bookyzo – Booking & Appointments ===
Contributors: codeholt, ashrafulsarkar
Tags: appointment booking, booking calendar, scheduling, booking form, stripe
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept online appointment bookings — staff scheduling, a booking widget, email reminders, and optional Stripe payments.

== Description ==

**Bookyzo** turns your WordPress site into a real appointment booking system. Add a staff member, set their weekly availability, drop a booking form onto any page with a shortcode or block, and start taking bookings — no page builder plugin, no external booking service, no WooCommerce required.

= Why Bookyzo? =

* Runs entirely on your own site — your booking data stays in your own database
* No WooCommerce dependency — works on any WordPress site
* A real Vue-powered admin panel, not a wall of settings screens
* Genuinely usable on the free plan — no artificial limits dressed up as a trial

= Staff & availability =

Add a staff member with a name, description, price and appointment length, then set their weekly hours on a day-by-day grid. Block out holidays or give one day different hours with date-specific exceptions. Available time slots are generated automatically and kept up to date in the background.

= Booking calendar =

A day/week/month calendar in the admin panel shows every booking, color-coded by status. Click a booking to see its details or change its status, or add a walk-in/phone booking yourself straight from the calendar.

= Booking list & CSV export =

Filter every booking by date, staff member or status, change status inline, and export the filtered list to CSV whenever you need it outside WordPress.

= Customer booking widget =

Add `[bookyzo_booking]` anywhere with the shortcode, or use the **Booking Form** block. Customers pick a time, enter their details, and get a booking confirmation — no account required.

= Email notifications =

Automatic emails when a booking is received, confirmed or cancelled, plus a reminder email sent the day before the appointment. Every email's subject and body is a plain-text template you can edit yourself, with placeholders for the customer's name, the resource, the appointment time and your business name.

= Payments =

Mark bookings as paid manually for cash or in-person payments, or connect a Stripe account to let customers pay online by card when they book.

= Dashboard =

See today's, this week's and this month's booking counts at a glance when you log in.

= Developer friendly =

Actions and filters throughout (capability limits, REST controllers, rate limiting, notifications) and a REST API under `bookyzo/v1`. See **Other Notes** for the hook list.

= Coming later =

Bookyzo currently supports appointment-style bookings (one staff member, one time slot). Table reservations, event ticketing and class/slot bookings are on the roadmap for a future update.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it via the Plugins screen.
2. Activate the plugin — you'll be taken straight into a short setup wizard.
3. Choose a booking type, fill in your business details, and add your first staff member.
4. Add the `[bookyzo_booking]` shortcode (or the **Booking Form** block) to any page.
5. Optionally connect Stripe under **Bookyzo → Payments** to accept card payments.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Bookyzo has its own booking system and database tables — it doesn't use WooCommerce products or orders at all.

= Can customers book without creating an account? =

Yes. Booking is a guest checkout by default — a customer only needs to give their name, email and phone number.

= Can I edit the confirmation and reminder emails? =

Yes, from **Bookyzo → Emails** in the admin panel. Every email is a plain-text template with placeholders for the customer's name, the resource, the appointment time and your business name.

= How do reminder emails work? =

An hourly background task checks for bookings starting in roughly 24 hours and sends a reminder — no setup needed beyond keeping the booking confirmed.

= Does this plugin hide or lock any features behind an upsell? =

No. Every feature listed above is fully usable with no artificial limits, nag screens or disabled UI.

= What happens if I need more than one staff member or more than one booking type? =

Free supports one active staff member (or table/event/slot resource, once those types ship) at a time. This maps to when most small businesses genuinely need to upgrade — hiring a second staff member, adding a second service, wanting SMS reminders instead of just email. A Pro version with these is planned for a future release.

== Screenshots ==

1. The booking calendar — day/week/month view, color-coded by booking status.
2. The staff/resource edit screen — details, weekly availability and date exceptions.
3. The customer-facing booking widget — pick a time, enter details, confirm.
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

* `byzo_max_resources_per_type` — ( int $max ) Active resources allowed per booking type. Default `1`.
* `byzo_max_active_resource_types` — ( int $max ) Booking types active at once. Default `1`.
* `byzo_max_shifts_per_day` — ( int $max ) Shifts per day in a resource's weekly schedule. Default `1`.
* `byzo_deposits_enabled` — ( bool $enabled ) Whether deposit/partial payments are available. Default `false`.
* `byzo_default_settings` — ( array $defaults ) Default values seeded into the business-settings option on activation.
* `byzo_rest_controllers` — ( array $controllers ) REST controller instances registered on `rest_api_init`.
* `byzo_rest_public_permission` — ( bool $allowed, WP_REST_Request $request ) Permission check for guest-allowed REST routes.
* `byzo_booking_rate_limit_max` / `byzo_booking_rate_limit_window` — ( int ) Per-IP guest booking rate limit (default 20 requests / 600 seconds) and its window in seconds.
* `byzo_client_ip` — ( string $ip ) The IP address used for rate limiting.
* `byzo_dev_server_url` — ( string $url ) Vite dev server URL used only in local development.

= Actions =

* `byzo_loaded` — Fires once the plugin is fully loaded.
* `byzo_resource_created` — ( int $resource_id ) After a new resource is created.
* `byzo_resource_activated` — ( int $resource_id ) After a resource transitions from inactive to active.
* `byzo_booking_created` — ( int $booking_id ) After a new booking is created.
* `byzo_booking_status_changed` — ( int $booking_id, string $new_status, string $old_status ) After a booking's status changes.
* `byzo_booking_payment_status_changed` — ( int $booking_id, string $new_status, string $old_status ) After a booking's payment status changes.
* `byzo_notification_sent` — ( string $event, int $booking_id ) After a notification email is sent (or attempted).
