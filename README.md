# Bookyzo – Booking & Appointments

A booking plugin for WordPress that handles appointments, table reservations, event tickets and generic time-slot bookings in one place: one booking widget, one admin panel, and no WooCommerce dependency.

## Free features

All of these are in the free plugin, with no license needed.

**Booking types**

- Appointment — one staff member, one customer per time slot (haircuts, consultations, tutoring)
- Table — a restaurant table or venue space with a party-size capacity
- Event — a date and time you create yourself (a class, a workshop, a ticketed show), sold by ticket quantity
- Slot — a recurring time slot with room for more than one booking (a rental, a group session, anything that doesn't fit the other three)
- No limits on bookable items, shifts or bookings

**Bookable items & availability**

- Name, description, photo, price, duration and capacity for each item
- A location and an online meeting link on any item
- A weekly schedule per item, with as many shifts per day as you like (e.g. lunch and dinner)
- Shared business hours an item can follow instead of its own schedule
- Date exceptions to block a holiday or change the hours for a single date
- Specific dates added by hand for events
- Available times kept up to date automatically in the background

**Customer booking form**

- The `[bookyzo_booking]` shortcode, the Booking Form block and the Elementor widget
- Show every item, one booking type (`[bookyzo_booking type="table"]`) or a single item (`[bookyzo_booking bookable_item_id="5"]`)
- Guest booking with a name, an email and a phone number, and you choose whether phone is required
- Party size for tables, ticket quantity for events and spots for slots
- Optional ticket holder names when someone books several event tickets
- Add to Google Calendar or download an .ics file from the confirmation screen
- Appearance settings for colors, width, font, corner rounding and shadow, with a live preview
- Spam protection with a honeypot field and a per-IP rate limit

**Booking management**

- Day, week, month and agenda calendar views, color-coded by status
- Drag a booking to another time on the calendar to reschedule it
- Add walk-in and phone bookings yourself
- Filter the booking list by date, bookable item or status, and change a status inline
- Export the filtered booking list to CSV
- Pending, confirmed, completed, cancelled, rejected and no-show statuses
- Confirm new bookings automatically, or review each one first

**Dashboard**

- Booking counts for today, this week and this month
- Revenue for the current month
- A chart of the last 14 days of bookings
- A list of upcoming bookings

**Customers**

- Customers saved automatically the first time they book
- Search by name, email or phone
- Your own notes and tags on each customer
- Import an existing customer list from a CSV file

**Emails**

- Automatic emails when a booking is received, confirmed or cancelled
- A reminder email the day before a booking
- An email to you for each new booking, which you can turn off
- Editable plain-text subject and body for every email, with placeholders for the customer's name, the bookable item, the booking time, the total and your business name

**Customer self-service**

- A link in each email to move a booking to another open time or cancel it, up to your cancellation cutoff, with no account needed
- Optional customer accounts (off by default) with a `[bookyzo_account]` page for upcoming and past bookings, rescheduling and cancelling

**Payments**

- Online card payments through your own Stripe account
- Mark bookings as paid manually for cash or in-person payments
- A "Pay in person" choice for customers, which you can turn off
- An optional flat tax rate on every paid booking's total
- A time released automatically when a customer leaves an online payment unfinished

**Privacy, translation & developer tools**

- WordPress's Export and Erase Personal Data tools
- Your booking data stays when you delete the plugin, unless you choose to remove it in Settings
- Translation-ready, including the booking form, the account page and the admin screens
- Actions, filters and a REST API under `bookyzo/v1`

## Pro features

These come only with [Bookyzo Pro](https://codeholt.com/products/bookyzo-pro/), a separate paid plugin that needs the free plugin installed. None of them are in the free plugin.

**Booking tools**

- Services with per-staff pricing and "any available staff" booking
- Recurring bookings you create for any non-event item, repeating every 1 to 4 weeks
- A waitlist for full times that emails the next person when a place frees up
- Bulk actions to confirm, complete, cancel or mark no-show on several bookings at once
- Internal notes and tags on a booking
- Duplicate a booking to start a new one
- Google Calendar and Outlook two-way sync — bookings go in, busy times block slots

**Locations & staff**

- Multiple locations, each with its own timezone and opening hours
- Staff logins that see only their own items' bookings

**Pricing & payments**

- Deposits as a percentage or a fixed amount
- PayPal alongside Stripe
- Refunds on cancellation, approved by you — refund in full, keep the deposit or booking fee, or decline
- Discount codes as a percentage or a fixed amount, limited by item, booking type, date range and number of uses
- Prepaid packages (e.g. "10 classes for $150") with their own store (`[bookyzo_packages]`, block and Elementor widget)
- Seasonal and dynamic pricing by date range, day of the week or booking lead time
- Add-ons customers can attach to a booking
- Booking fees and per-item tax
- WooCommerce checkout when WooCommerce is active

**Messages**

- SMS (Twilio) and WhatsApp confirmations, cancellations and reminders
- Your own reminder timings (e.g. one 48 hours before and another closer to the booking)
- Email and SMS campaigns to a filtered customer list
- Post-visit follow-up emails, sent a set number of hours after a booking is completed
- Live admin alerts for new bookings, cancellations and no-shows

**Tables**

- A floor plan to arrange your tables and see each one's state in a live view
- Combined tables for larger parties
- A walk-in waitlist

**Events**

- Ticket tiers (e.g. Early Bird, Regular, VIP) with their own prices and capacity
- QR e-tickets
- A web check-in scanner that uses the device's camera, with no app to install and a manual fallback
- Attendee list export

**Reports**

- An analytics dashboard for revenue, occupancy, no-shows, repeat customers, conversion rate, popular items and hours, filterable by date and location, with CSV export
- Commission and payout tracking
- Customer list export with booking history
- Branded PDF invoices

**Customization & integrations**

- Custom form fields (text, dropdown, checkbox, date, number and address), for all items or just one
- Custom CSS for the booking form
- Signed webhooks when a booking is created or its status changes
- Marketing pixels for Facebook, GA4 and Google Tag Manager

**License & updates**

- Automatic Pro updates on the Plugins screen
- If a license expires, everything already set up keeps working; only creating new Pro items stops

The user-facing description, FAQ, hook list and changelog are in [`readme.txt`](readme.txt), the file WordPress.org reads.

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 6.3 |
| PHP | 7.4 |
| Node.js (development only) | 22 |

## Installation

- **WordPress.org:** search for "Bookyzo" under **Plugins → Add New** once the plugin is listed.
- **From a release:** download `bookyzo.zip` from the [Releases](https://github.com/ashrafulsarkar/bookyzo/releases) page and upload it under **Plugins → Add New → Upload Plugin**.

Activating the plugin opens a short setup wizard. Afterwards, add `[bookyzo_booking]` (or the **Booking Form** block) to any page.

> A clone of this repository does not run as-is: the compiled admin, widget and account bundles in `assets/admin`, `assets/widget` and `assets/account` are build output and are not committed. Build them first (see below), or install a release zip.

## Development

```bash
git clone https://github.com/ashrafulsarkar/bookyzo.git wp-content/plugins/bookyzo
cd wp-content/plugins/bookyzo
npm ci
npm run build
```

| Command | What it does |
| --- | --- |
| `npm run dev` | Starts the Vite dev server (port 5173) with hot reload for the admin app. While it runs, wp-admin loads the admin app from it automatically; stop it and the plugin falls back to the built files. |
| `npm run build` | Builds all three bundles: admin (`assets/admin`), booking widget (`assets/widget`) and customer account page (`assets/account`). |
| `npm run build:release` | Runs the build, then stages the shippable files (everything not in [`.distignore`](.distignore)) in `release/bookyzo/` and zips them as `release/bookyzo.zip`. |

The booking widget and account bundles have no dev server; rebuild them with `npm run build` after changing them.

### Project layout

```
bookyzo.php          Plugin bootstrap
uninstall.php        Opt-in data removal (off by default)
includes/            PHP: data layer, REST controllers (includes/rest), notifications, payments
templates/           PHP templates: admin mount point, emails, manage-booking page
blocks/              Gutenberg "Booking Form" block
src/                 Vue 3 source: admin app, widget/ (booking widget), account/ (customer account)
assets/              Built bundles + plain CSS/JS that ship as-is
.wordpress-org/      Banners, icons and screenshots for the WordPress.org listing
```

The admin app uses Tailwind CSS and shadcn-vue components (`src/components/ui`). The booking widget uses its own scoped, hand-written CSS so it doesn't clash with the host theme.

### Translations

Strings in the Vue apps go through `wp.i18n`. Templates call `__()`, `_n()`, `_x()` and `sprintf()` directly; script code calls `i18n.__('Text', 'bookyzo')` from `src/lib/i18n.js`. Keep the text domain a literal `'bookyzo'` so `wp i18n make-pot` finds every string, and put a `/*! translators: … */` comment right before any call with placeholders.

## Releasing

Publishing a GitHub release deploys that version to WordPress.org through [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml):

1. Bump the version in three places so they match: the `Version:` header and `BYZO_VERSION` in `bookyzo.php`, and `Stable tag:` in `readme.txt`. Add a changelog entry to `readme.txt`.
2. Commit and push to `main`.
3. Create a release with a tag of the same version (`1.2.0` or `v1.2.0`) and publish it.

The workflow then checks that the tag matches all three version numbers (and stops if it doesn't), installs dependencies, builds the bundles, generates `languages/bookyzo.pot` with WP-CLI, commits the result to the WordPress.org SVN `trunk` and a new SVN tag, syncs `.wordpress-org/` to the SVN `assets/` folder, and attaches the built `bookyzo.zip` to the GitHub release. The source in `src/` and the build configuration are not shipped (see `.distignore`); `readme.txt` links back to this repository for them.

One-time setup: add the WordPress.org account that has commit access to the plugin as repository secrets **SVN_USERNAME** and **SVN_PASSWORD** (**Settings → Secrets and variables → Actions**).

## Contributing

Issues and pull requests are welcome. For a pull request, run `npm run build` to make sure the bundles still build, and test the change in a WordPress install.

## License

[GPL-2.0-or-later](LICENSE), the same license as WordPress.
