<template>
  <div>
    <ByzoPageHeader title="Settings" subtitle="Business profile and booking behavior.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
      </template>
      <template #actions>
        <Button :disabled="loading || saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8 flex flex-col gap-4">
      <ByzoLoading v-if="loading" />

      <template v-else>
        <Card>
          <CardHeader>
            <CardTitle>Business profile</CardTitle>
          </CardHeader>
          <!-- Two-up grids throughout so short values (business name,
               timezone, currency, times) never stretch into enormous
               boxes even though the card itself now spans the full page
               width; grids collapse to one column on a phone. -->
          <CardContent class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="flex flex-col gap-1.5">
                <Label for="s-business-name">Business name</Label>
                <Input id="s-business-name" v-model="form.business_name" type="text" />
              </div>
              <div class="flex flex-col gap-1.5">
                <Label for="s-timezone">Timezone</Label>
                <Input id="s-timezone" v-model="form.timezone" type="text" placeholder="e.g. Asia/Dhaka" />
                <p class="text-xs text-muted-foreground">A PHP timezone identifier, e.g. Asia/Dhaka, or a UTC offset like +06:00.</p>
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="flex flex-col gap-1.5">
                <Label for="s-currency">Currency</Label>
                <Input id="s-currency" v-model="form.currency" type="text" maxlength="3" class="uppercase" />
                <p class="text-xs text-muted-foreground">A 3-letter ISO code, e.g. USD, EUR, BDT.</p>
              </div>
              <div class="flex flex-col gap-1.5">
                <Label for="s-tax-rate">Tax rate (%)</Label>
                <Input id="s-tax-rate" v-model.number="form.tax_rate" type="number" min="0" max="100" step="0.01" />
                <p class="text-xs text-muted-foreground">Added to every paid booking's total. Leave at 0 if you don't charge tax.</p>
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="flex flex-col gap-1.5">
                <Label for="s-start-time">Default opening time</Label>
                <Input id="s-start-time" v-model="form.default_start_time" type="time" />
              </div>
              <div class="flex flex-col gap-1.5">
                <Label for="s-end-time">Default closing time</Label>
                <Input id="s-end-time" v-model="form.default_end_time" type="time" />
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
            <CardTitle>Business hours</CardTitle>
            <CardDescription>The default weekly hours a bookable item can follow instead of setting its own.</CardDescription>
          </CardHeader>
          <CardContent>
            <ByzoWeeklyScheduleGrid v-model="form.business_hours" />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Booking behavior</CardTitle>
            <CardDescription>Set once during setup — change anytime here.</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.require_phone" />
              Require a phone number on every booking
            </label>
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.auto_confirm" />
              Automatically confirm new bookings (skip manual review)
            </label>

            <div class="flex flex-col gap-1.5">
              <label class="flex items-center gap-2 text-sm">
                <Switch v-model="form.allow_pay_in_person" />
                Let customers choose "Pay in person instead" and skip online payment
              </label>
              <p class="text-xs text-muted-foreground">
                On by default: a customer can finish booking without paying now, and you mark it paid
                yourself later (a booking's own Payment field, see Bookings). Turn this off to require
                online payment through a gateway (see Payments) before checkout completes — the option
                disappears from the booking widget entirely, it isn't just disabled.
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="s-hold-hours">Unfinished online payments</Label>
              <div class="flex items-center gap-2">
                <Input id="s-hold-hours" v-model.number="form.pending_booking_hold_hours" type="number" min="0" step="1" class="w-24" />
                <span class="text-sm text-muted-foreground">hours, then the booking is cancelled (0 = never)</span>
              </div>
              <p class="text-xs text-muted-foreground">
                A customer who opens the online payment form but never finishes paying holds that time until
                then. Bookings waiting for your approval, free bookings and "Pay in person" bookings are never
                cancelled automatically.
              </p>
            </div>

            <div class="flex flex-col gap-1.5">
              <Label for="s-cancellation-hours">Guest cancellation window</Label>
              <div class="flex items-center gap-2">
                <Input id="s-cancellation-hours" v-model.number="form.cancellation_hours" type="number" min="0" step="1" class="w-24" />
                <span class="text-sm text-muted-foreground">hours before the booking (0 = customers can't cancel online)</span>
              </div>
              <p class="text-xs text-muted-foreground">Customers can cancel their own booking from the link in their confirmation email, as long as it's at least this many hours away.</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Customer accounts</CardTitle>
            <CardDescription>Let customers register, log in, and manage their own bookings.</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.customer_accounts_enabled" />
              Enable customer accounts
            </label>
            <p class="text-xs text-muted-foreground">
              When on, visitors can create an account at booking time or from the
              <code>[bookyzo_account]</code> page you embed, and see their booking history,
              reschedule, and cancel without contacting you. Off by default — turning it on
              opens a real registration/login surface on your site. The existing one-off
              "manage my booking" email link keeps working for guests either way.
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Notifications</CardTitle>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.send_confirmation_email" />
              Send a confirmation email to the customer
            </label>
            <label class="flex items-center gap-2 text-sm">
              <Switch v-model="form.send_admin_notification" />
              Email me when a new booking comes in
            </label>
            <p class="text-xs text-muted-foreground">Edit the email subject/body themselves under Emails.</p>
          </CardContent>
        </Card>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
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
});
const loading = ref(true);
const saving = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  try {
    const settings = await api.get('/settings');
    form.value = { ...form.value, ...settings };
  } catch (e) {
    error.value = e.message || 'Could not load settings.';
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
    toast.success('Settings saved.');
  } catch (e) {
    error.value = e.message || 'Could not save settings. Please check the fields above.';
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
