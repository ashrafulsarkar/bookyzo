<template>
  <div>
    <ByzoPageHeader :title="__('Settings', 'bookyzo')" :subtitle="__('Business profile and booking behavior.', 'bookyzo')">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
      </template>
      <template #actions>
        <Button :disabled="loading || saving" @click="save">{{ saving ? __('Saving…', 'bookyzo') : __('Save', 'bookyzo') }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8 flex flex-col gap-4">
      <ByzoLoading v-if="loading" />

      <template v-else>
        <Card>
          <CardHeader>
            <CardTitle>{{ __('Business profile', 'bookyzo') }}</CardTitle>
          </CardHeader>
          <!-- Two-up grids throughout so short values (business name,
               timezone, currency, times) never stretch into enormous
               boxes even though the card itself now spans the full page
               width; grids collapse to one column on a phone. -->
          <CardContent class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="flex flex-col gap-1.5">
                <Label for="s-business-name">{{ __('Business name', 'bookyzo') }}</Label>
                <Input id="s-business-name" v-model="form.business_name" type="text" />
              </div>
              <div class="flex flex-col gap-1.5">
                <!-- Read-only: every time Bookyzo shows or stores uses
                     WordPress's own timezone, so that's the one place to
                     change it. (This used to be an editable field that was
                     saved but never used.) -->
                <Label>{{ __('Timezone', 'bookyzo') }}</Label>
                <p id="s-timezone" class="flex h-9 items-center text-sm">{{ timezoneLabel }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ __('Bookyzo uses your site\'s timezone.', 'bookyzo') }}
                  <a href="options-general.php#timezone_string" class="text-primary underline-offset-2 hover:underline">{{ __('Change it in Settings → General', 'bookyzo') }}</a>.
                </p>
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="flex flex-col gap-1.5">
                <Label for="s-currency">{{ __('Currency', 'bookyzo') }}</Label>
                <!-- A picker rather than free text (QA report §4.7): a typo
                     here mislabels every price on the site. A code saved
                     before this list existed stays selectable. -->
                <select
                  id="s-currency"
                  v-model="form.currency"
                  class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <option v-for="code in currencyOptions" :key="code" :value="code">{{ currencyLabel(code) }}</option>
                </select>
              </div>
              <div class="flex flex-col gap-1.5">
                <Label for="s-tax-rate">{{ __('Tax rate (%)', 'bookyzo') }}</Label>
                <Input id="s-tax-rate" v-model.number="form.tax_rate" type="number" min="0" max="100" step="0.01" />
                <p class="text-xs text-muted-foreground">{{ __('Added to every paid booking\'s total. Leave at 0 if you don\'t charge tax.', 'bookyzo') }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Phase 2 step 4 (DETAILED-SPEC.md §A2) — the shared default a
             bookable item can link to instead of its own schedule
             (Bookable Items → Availability step, Pro-only link). Reuses
             ByzoWeeklyScheduleGrid unchanged — same row shape as an item's
             own schedule. -->
        <Card>
          <CardHeader>
            <CardTitle>{{ __('Business hours', 'bookyzo') }}</CardTitle>
            <CardDescription>{{ __('Your opening hours: what a new bookable item starts with, and what an item can follow instead of setting its own.', 'bookyzo') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <ByzoWeeklyScheduleGrid v-model="form.business_hours" :max-shifts="Infinity" />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{{ __('Booking behavior', 'bookyzo') }}</CardTitle>
            <CardDescription>{{ __('Set once during setup — change anytime here.', 'bookyzo') }}</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.require_phone" />
              {{ __('Require a phone number on every booking', 'bookyzo') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.auto_confirm" />
              {{ __('Automatically confirm new bookings (skip manual review)', 'bookyzo') }}
            </label>

            <div class="flex flex-col gap-1.5">
              <label class="flex items-center gap-2 text-sm">
                <Switch v-model="form.collect_attendee_details" />
                {{ __('Ask for each ticket holder\'s name on event bookings', 'bookyzo') }}
              </label>
              <p class="text-xs text-muted-foreground">
                {{ __('When someone books more than one ticket for an event, the booking form asks for a name (and optionally an email) for every ticket instead of one contact for the whole order.', 'bookyzo') }}
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <label class="flex items-center gap-2 text-sm">
                <Switch v-model="form.allow_pay_in_person" />
                {{ __('Let customers choose "Pay in person instead" and skip online payment', 'bookyzo') }}
              </label>
              <p class="text-xs text-muted-foreground">
                {{ __('On by default: a customer can finish booking without paying now, and you mark it paid yourself later (a booking\'s own Payment field, see Bookings). Turn this off to require online payment through a gateway (see Payments) before checkout completes — the option disappears from the booking widget entirely, it isn\'t just disabled.', 'bookyzo') }}
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="s-hold-hours">{{ __('Unfinished online payments', 'bookyzo') }}</Label>
              <div class="flex items-center gap-2">
                <Input id="s-hold-hours" v-model.number="form.pending_booking_hold_hours" type="number" min="0" step="1" class="w-24" />
                <span class="text-sm text-muted-foreground">{{ __('hours, then the booking is cancelled (0 = never)', 'bookyzo') }}</span>
              </div>
              <p class="text-xs text-muted-foreground">
                {{ __('A customer who opens the online payment form but never finishes paying holds that time until then. Bookings waiting for your approval, free bookings and "Pay in person" bookings are never cancelled automatically.', 'bookyzo') }}
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="s-cancellation-hours">{{ __('Guest cancellation window', 'bookyzo') }}</Label>
              <div class="flex items-center gap-2">
                <Input id="s-cancellation-hours" v-model.number="form.cancellation_hours" type="number" min="0" step="1" class="w-24" />
                <span class="text-sm text-muted-foreground">{{ __('hours before the booking (0 = customers can\'t cancel online)', 'bookyzo') }}</span>
              </div>
              <p class="text-xs text-muted-foreground">{{ __('Customers can cancel their own booking from the link in their confirmation email, as long as it\'s at least this many hours away.', 'bookyzo') }}</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{{ __('Customer accounts', 'bookyzo') }}</CardTitle>
            <CardDescription>{{ __('Let customers register, log in, and manage their own bookings.', 'bookyzo') }}</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.customer_accounts_enabled" />
              {{ __('Enable customer accounts', 'bookyzo') }}
            </label>
            <p class="text-xs text-muted-foreground">
              {{ __('When on, visitors can create an account at booking time or from the', 'bookyzo') }}
              <code>[bookyzo_account]</code> {{ __('page you embed, and see their booking history, reschedule, and cancel without contacting you. Off by default — turning it on opens a real registration/login surface on your site. The existing one-off "manage my booking" email link keeps working for guests either way.', 'bookyzo') }}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{{ __('Notifications', 'bookyzo') }}</CardTitle>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.send_confirmation_email" />
              {{ __('Send a confirmation email to the customer', 'bookyzo') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.send_admin_notification" />
              {{ __('Email me when a new booking comes in', 'bookyzo') }}
            </label>
            <p class="text-xs text-muted-foreground">{{ __('Edit the email subject/body themselves under Emails.', 'bookyzo') }}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{{ __('When you delete the plugin', 'bookyzo') }}</CardTitle>
          </CardHeader>
          <CardContent class="flex flex-col gap-1.5">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.delete_data_on_uninstall" />
              {{ __('Also delete all Bookyzo data', 'bookyzo') }}
            </label>
            <p class="text-xs text-muted-foreground">
              {{ __('When on, deleting Bookyzo from the Plugins screen also removes its bookings, bookable items, customers, schedules and settings, including your payment keys. Off by default, so your booking history survives a reinstall. Deactivating never deletes anything.', 'bookyzo') }}
            </p>
          </CardContent>
        </Card>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { i18n, locale } from '@/lib/i18n';
import { ref, computed, onMounted } from 'vue';
import { toast } from 'vue-sonner';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import ByzoPageHeader from '@/components/base/ByzoPageHeader.vue';
import ByzoLoading from '@/components/base/ByzoLoading.vue';
import ByzoWeeklyScheduleGrid from '@/components/base/ByzoWeeklyScheduleGrid.vue';
import { api } from '@/api/client';

// The single ongoing home for every byzo_settings field the setup
// wizard only ever writes ONCE (Phase 1.5 steps 1/6/7) — without this
// screen, require_phone/auto_confirm/the notification toggles/
// cancellation_hours would all be wizard-only and unreachable
// afterward, despite PricingStep/ConfirmationStep's own copy already
// promising "you can change this anytime" (DETAILED-SPEC.md §A1).
// tax_rate (Phase 1.5 step 9) has no wizard step of its own — this is
// its only UI, by design (PricingStep.vue's own comment deferred it here
// rather than asking before the feature existed).
const form = ref({
  business_name: '',
  timezone: '',
  currency: 'USD',
  default_start_time: '09:00',
  default_end_time: '17:00',
  tax_rate: 0,
  business_hours: [],
  require_phone: false,
  auto_confirm: false,
  cancellation_hours: 24,
  send_confirmation_email: true,
  send_admin_notification: true,
  // Phase 1.7 step 7 (DETAILED-SPEC.md §A11) — the last piece of the
  // customer-accounts feature; backend support (BYZO_Settings, the role,
  // the shortcode/bundle, every REST route) has existed since steps 1-6,
  // this is purely the missing admin control to actually turn it on.
  customer_accounts_enabled: false,
  // Added 2026-09-22 — gates StepPayment.vue's "Pay in person instead".
  allow_pay_in_person: true,
  pending_booking_hold_hours: 24,
  collect_attendee_details: false,
  delete_data_on_uninstall: false,
});
// The currencies most sites use, plus whatever this site already saved —
// the field was free text, so an existing value must never disappear.
const COMMON_CURRENCIES = ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'JPY', 'CNY', 'HKD', 'SGD', 'INR', 'BDT', 'PKR', 'LKR', 'NPR', 'IDR', 'MYR', 'THB', 'PHP', 'VND', 'AED', 'SAR', 'QAR', 'TRY', 'ILS', 'EGP', 'NGN', 'KES', 'ZAR', 'GHS', 'MAD', 'BRL', 'MXN', 'ARS', 'CLP', 'COP', 'RUB', 'UAH', 'KRW'];
const currencyOptions = computed(() => {
  const current = (form.value.currency || '').toUpperCase();
  return current && !COMMON_CURRENCIES.includes(current) ? [current, ...COMMON_CURRENCIES] : COMMON_CURRENCIES;
});

// "USD — US Dollar" where the browser knows the name, just the code otherwise.
function currencyLabel(code) {
  try {
    const name = new Intl.DisplayNames(locale, { type: 'currency' }).of(code);
    return name && name !== code ? `${code} — ${name}` : code;
  } catch {
    return code;
  }
}

// WordPress reports a bare offset ("+06:00") when no named zone is set.
const timezoneLabel = computed(() => (/^[+-]/.test(form.value.timezone) ? `UTC${form.value.timezone}` : form.value.timezone));
const loading = ref(true);
const saving = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  try {
    const settings = await api.get('/settings');
    form.value = { ...form.value, ...settings };
  } catch (e) {
    error.value = e.message || i18n.__('Could not load settings.', 'bookyzo');
  } finally {
    loading.value = false;
  }
}

async function save() {
  error.value = '';
  saving.value = true;
  try {
    const settings = await api.put('/settings', form.value);
    form.value = { ...form.value, ...settings };
    toast.success(i18n.__('Settings saved.', 'bookyzo'));
  } catch (e) {
    error.value = e.message || i18n.__('Could not save settings. Please check the fields above.', 'bookyzo');
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
