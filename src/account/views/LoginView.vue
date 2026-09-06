<template>
  <h1>Log in</h1>
  <form method="post" :action="loginUrl">
    <div class="bookyzo-account__field">
      <label for="byzo-login-email">Email</label>
      <input id="byzo-login-email" type="email" name="email" required autocomplete="email">
    </div>
    <div class="bookyzo-account__field">
      <label for="byzo-login-password">Password</label>
      <input id="byzo-login-password" type="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="bookyzo-account__btn">Log in</button>
  </form>
  <p class="bookyzo-account__switch">
    <a @click="$emit('switch')">Don't have an account? Create one</a>
    <template v-if="lostPasswordUrl">
      &nbsp;·&nbsp;
      <a :href="lostPasswordUrl">Forgot password?</a>
    </template>
  </p>
</template>

<script setup>
// Real <form method="post"> submits (not a fetch call) — this reuses the
// exact same admin-post.php handlers BYZO_Customer_Auth already built in
// step 2 unchanged; Vue only replaces how the FORM is rendered, not how
// it's processed. A failed submit round-trips through a full page load
// back to whatever page hosts [bookyzo_account], landing back on this
// same component with state/errorMessage freshly resolved server-side.
// loginUrl already includes ?action=byzo_customer_login (built server-side
// via add_query_arg on admin_url('admin-post.php')) — admin-post.php reads
// 'action' from the query string just as readily as a POST field, so no
// hidden input is needed and PHP stays the single source of truth for the
// action-name string, not duplicated into this component.
defineProps({
  loginUrl: { type: String, required: true },
  lostPasswordUrl: { type: String, default: '' },
});
defineEmits(['switch']);
</script>
