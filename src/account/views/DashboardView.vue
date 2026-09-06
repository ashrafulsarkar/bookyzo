<template>
  <h1>Welcome back<template v-if="displayName">, {{ displayName }}</template></h1>
  <p class="bookyzo-account__empty">Your bookings will appear here.</p>
  <form method="post" :action="logoutUrl">
    <button type="submit" class="bookyzo-account__btn">Log out</button>
  </form>
</template>

<script setup>
// Deliberately no booking list yet — GET /bookings isn't widened for an
// authenticated byzo_customer request until Phase 1.7 step 5 (dashboard
// data, DETAILED-SPEC.md §A11). Building that list against a route that
// doesn't yet auto-scope to the caller's own bookings would either show
// nothing (still admin-only) or need a throwaway fetch call replaced days
// later — same "don't add a field/UI ahead of the feature that reads it"
// rule this codebase already applied to tax_rate/cancellation_hours during
// the wizard build (see ROADMAP.md Phase 1.5 step 6's note).
defineProps({
  logoutUrl: { type: String, required: true },
  displayName: { type: String, default: '' },
});
</script>
