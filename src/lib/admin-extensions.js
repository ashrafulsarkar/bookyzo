import { reactive, ref } from 'vue';
import { router } from '../router';

/**
 * Extension point for Pro (or any future add-on) to add routes/nav items
 * to this SAME running Vue app rather than shipping a second admin app —
 * see bookyzo-dev SKILL.md's "one Vue 3 admin app" rule and
 * DETAILED-SPEC.md §3.
 *
 * Pro ships its own small Vite bundle (own manifest, own JS file —
 * BYZOP_Admin_Assets) enqueued with Free's `byzo-admin` handle as a script
 * dependency. WordPress prints dependencies' <script> tags before the
 * dependent's, and neither is `async`, so Pro's module is guaranteed to
 * execute after this one has already run main.js and set
 * `window.byzoAdmin.registerExtension` — no ready-event/queue needed.
 *
 * `router.addRoute()` is safe to call after the router (and the app) are
 * already mounted — Vue Router re-resolves matches for any add made before
 * the next navigation.
 */
// Every pushed item needs a `section` matching one of ByzoSidebar.vue's
// SECTION_ORDER values (Overview, Booking Tools, Pricing & Payments,
// Reports, Marketing, Customization, Integrations, Settings) — an item
// with no matching section silently doesn't render, since the sidebar
// groups by section rather than rendering a flat list. Pick the section
// by what the screen fundamentally IS (a settings/config screen vs. a
// report/ledger vs. a booking-operations tool), not by proximity to a
// similarly-named screen — this is what keeps lookalike names like
// "Payments" (Pricing & Payments) and "Payouts" (Reports) from reading as
// siblings.
export const extraNavItems = reactive([]);

// Gateway id -> settings component, e.g. { paypal: PayPalGatewaySettings }.
// PaymentSettings.vue merges this with its own hardcoded map (Stripe) so a
// Pro-registered gateway (BYZO_Payment_Gateways::all()'s `byzo_payment_gateways`
// filter, PHP-side) gets a real settings form instead of the "aren't
// available in this version" fallback. Added Phase 2 step 6 (PayPal),
// DETAILED-SPEC.md §A7 — same registerExtension() call as routes/navItems,
// just a third recognized key.
export const extraPaymentGatewayComponents = reactive({});

// Single extra section AppearanceSettings.vue renders below its own preset
// controls (custom CSS / theme authoring today, FEATURES.md B10) — a ref
// rather than a map like extraPaymentGatewayComponents above since there's
// only ever one appearance screen to extend, not a per-id list. null until
// (if ever) an add-on registers one, so Free never renders an empty section.
export const extraAppearanceSection = ref(null);

// One extra step BookableItemEditDialog.vue inserts between Pricing and
// Review (QA report 2026-09-22 §4.8) — so settings an add-on keeps per
// item (fees, tax, commission…) are edited with the item instead of on a
// screen of their own. Shape:
//   { label, component, initial(item) -> data, save(itemId, data), summary?(data, { currency }) -> [{ label, value }] }
// The dialog owns `data` (so it survives Back/Continue), hands it to the
// component as `modelValue` to edit in place, and calls save() after its
// own save. null = no such step, and Free's dialog is exactly as before.
export const extraItemEditStep = ref(null);

// Bookings screen hooks (QA report 2026-09-22 §4.9) — an add-on's bulk
// actions and per-booking tools live on the Bookings list and in the
// booking popup itself, not on a second bookings list of their own.
// - bookingBulkActions: [{ label, danger?, run(ids) -> Promise<{ message? }> }].
//   Any at all turns on the list's checkbox column and a bar of these
//   buttons for the selection; none, and the list has no checkboxes.
// - bookingDetailSection: a component BookingDetailModal.vue renders under
//   its own sections, given `booking`. It emits `updated(booking)` when it
//   changed that booking, and `changed` when it changed others (created
//   one, freed a table…) so the list behind it reloads.
export const extraBookingBulkActions = reactive([]);
export const extraBookingDetailSection = ref(null);

// Extra buttons in the Customers screen header: [{ label, href }] — e.g. an
// add-on's customer export.
export const extraCustomerListActions = reactive([]);

export function registerExtension(extension = {}) {
  (extension.routes || []).forEach((route) => router.addRoute(route));
  extraNavItems.push(...(extension.navItems || []));
  Object.assign(extraPaymentGatewayComponents, extension.paymentGatewayComponents || {});
  if (extension.appearanceSection) {
    extraAppearanceSection.value = extension.appearanceSection;
  }
  if (extension.itemEditStep) {
    extraItemEditStep.value = extension.itemEditStep;
  }
  extraBookingBulkActions.push(...(extension.bookingBulkActions || []));
  if (extension.bookingDetailSection) {
    extraBookingDetailSection.value = extension.bookingDetailSection;
  }
  extraCustomerListActions.push(...(extension.customerListActions || []));
}
