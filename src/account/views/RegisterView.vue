<template>
  <h1>{{ __('Create an account', 'bookyzo') }}</h1>
  <form method="post" :action="registerUrl">
    <div class="bookyzo-account__field">
      <label for="byzo-register-name">{{ __('Name', 'bookyzo') }}</label>
      <input id="byzo-register-name" type="text" name="name" required autocomplete="name">
    </div>
    <div class="bookyzo-account__field">
      <label for="byzo-register-email">{{ __('Email', 'bookyzo') }}</label>
      <input id="byzo-register-email" type="email" name="email" required autocomplete="email">
    </div>
    <div class="bookyzo-account__field">
      <label for="byzo-register-password">{{ __('Password', 'bookyzo') }}</label>
      <input id="byzo-register-password" type="password" name="password" minlength="8" required autocomplete="new-password">
    </div>
    <button type="submit" class="bookyzo-account__btn">{{ __('Create account', 'bookyzo') }}</button>
  </form>
  <p class="bookyzo-account__switch">
    <a @click="$emit('switch')">{{ __('Already have an account? Log in', 'bookyzo') }}</a>
  </p>
</template>

<script setup>
// See LoginView.vue's comment — registerUrl already carries
// ?action=byzo_customer_register, no hidden field needed.
defineProps({
  registerUrl: { type: String, required: true },
});
defineEmits(['switch']);
</script>
