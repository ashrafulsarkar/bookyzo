# Bookyzo – Booking & Appointments

A booking plugin for WordPress that handles appointments, table reservations, event tickets and generic time-slot bookings in one place: one booking widget, one admin panel, and no WooCommerce dependency.

- **Four booking types in the free plugin:** Appointment, Table, Event and Slot.
- **No limits** on bookable items, shifts per day or bookings.
- **Runs on your own site.** Bookings live in your own database, with no external booking service.
- **Vue 3 admin panel** with a dashboard, a day/week/month calendar, a booking list with CSV export, customers, email templates, payments and widget appearance settings.
- **Customer booking widget** as a shortcode (`[bookyzo_booking]`), a Gutenberg block or an Elementor widget, with guest checkout, add-to-calendar links and a self-service manage/cancel link.
- **Payments:** mark bookings paid manually, or take card payments through your own Stripe account.
- **For developers:** actions and filters throughout, and a REST API under `bookyzo/v1`.

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

The workflow then checks that the tag matches all three version numbers (and stops if it doesn't), installs dependencies, builds the bundles, commits the result to the WordPress.org SVN `trunk` and a new SVN tag, syncs `.wordpress-org/` to the SVN `assets/` folder, and attaches the built `bookyzo.zip` to the GitHub release. The source in `src/` and the build configuration are not shipped (see `.distignore`); `readme.txt` links back to this repository for them.

One-time setup: add the WordPress.org account that has commit access to the plugin as repository secrets **SVN_USERNAME** and **SVN_PASSWORD** (**Settings → Secrets and variables → Actions**).

## Contributing

Issues and pull requests are welcome. For a pull request, run `npm run build` to make sure the bundles still build, and test the change in a WordPress install.

## License

[GPL-2.0-or-later](LICENSE), the same license as WordPress.
