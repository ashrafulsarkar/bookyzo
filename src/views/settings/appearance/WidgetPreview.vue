<template>
  <!-- A static replica of the real widget's slot-picking step, driven by
       the same CSS custom property names src/widget/widget.css uses, so
       what's rendered here is what those tokens will produce on the front
       end. Deliberately NOT the real widget bundle: that would need REST
       data, a booking flow, and a second Vue app inside wp-admin to show
       what is only ever a color/spacing check. Keep the token names in
       sync with widget.css if they ever change there. -->
  <div class="byzo-stage">
    <!-- A mock browser chrome around the widget. The point is context:
         on its own, the widget's own white card is invisible against the
         admin card behind it, so a background/shadow change reads as
         "nothing happened". Framed against a page-like backdrop, every
         token in the panel has something to visibly act on. -->
    <div class="byzo-stage__frame">
      <div class="byzo-stage__bar">
        <span class="byzo-stage__light" />
        <span class="byzo-stage__light" />
        <span class="byzo-stage__light" />
        <span class="byzo-stage__url">yoursite.com</span>
      </div>

      <div class="byzo-stage__page">
        <div class="byzo-preview" :style="previewStyle">
          <div class="byzo-preview__progress">
            <span
              v-for="n in 3"
              :key="n"
              class="byzo-preview__dot"
              :class="{ 'byzo-preview__dot--active': n <= activeStep }"
            />
          </div>

          <template v-if="step === 'service'">
            <h2 class="byzo-preview__title">Choose a service</h2>
            <p class="byzo-preview__subtitle">Pick what you'd like to book.</p>

            <div class="byzo-preview__cards">
              <span
                v-for="service in services"
                :key="service.name"
                class="byzo-preview__card"
                :class="{ 'byzo-preview__card--selected': service.selected }"
              >
                <span class="byzo-preview__card-name">{{ service.name }}</span>
                <span class="byzo-preview__card-meta">{{ service.meta }}</span>
              </span>
            </div>
          </template>

          <template v-else-if="step === 'time'">
            <h2 class="byzo-preview__title">Pick a time</h2>
            <p class="byzo-preview__subtitle">Maya Chen — Senior Stylist</p>

            <div class="byzo-preview__chips">
              <span class="byzo-preview__chip byzo-preview__chip--selected">Thu 10 Sept</span>
              <span class="byzo-preview__chip">Fri 11 Sept</span>
              <span class="byzo-preview__chip">Sat 12 Sept</span>
            </div>

            <div class="byzo-preview__chips byzo-preview__chips--times">
              <span class="byzo-preview__chip">9:00</span>
              <span class="byzo-preview__chip">10:50</span>
              <span class="byzo-preview__chip">12:40</span>
            </div>

            <div class="byzo-preview__actions">
              <span class="byzo-preview__btn byzo-preview__btn--outline">Back</span>
              <span class="byzo-preview__btn byzo-preview__btn--primary">Continue</span>
            </div>
          </template>

          <template v-else>
            <h2 class="byzo-preview__title">Your details</h2>
            <p class="byzo-preview__subtitle">Thu 10 Sept at 10:50</p>

            <div class="byzo-preview__field">
              <span class="byzo-preview__label">Name</span>
              <span class="byzo-preview__input">Jamie Rivera</span>
            </div>
            <div class="byzo-preview__field">
              <span class="byzo-preview__label">Email</span>
              <span class="byzo-preview__input byzo-preview__input--focus">jamie@example.com</span>
            </div>

            <div class="byzo-preview__actions">
              <span class="byzo-preview__btn byzo-preview__btn--outline">Back</span>
              <span class="byzo-preview__btn byzo-preview__btn--primary">Confirm booking</span>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Each step exercises a different subset of the tokens (cards vs.
         chips vs. inputs), so switching is the only way to see what a
         border or radius change does everywhere it applies. -->
    <div class="byzo-stage__steps">
      <button
        v-for="option in stepOptions"
        :key="option.value"
        type="button"
        class="byzo-stage__step"
        :class="{ 'byzo-stage__step--active': step === option.value }"
        @click="step = option.value"
      >
        {{ option.label }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
  appearance: { type: Object, required: true },
});

const stepOptions = [
  { value: 'service', label: 'Service' },
  { value: 'time', label: 'Time' },
  { value: 'details', label: 'Details' },
];

const step = ref('time');

const services = [
  { name: 'Maya Chen — Senior Stylist', meta: '45 min · 65.00', selected: true },
  { name: 'Morning Flow Yoga', meta: '60 min · 18.00', selected: false },
];

const activeStep = computed(() => stepOptions.findIndex((o) => o.value === step.value) + 1);

const previewStyle = computed(() => {
  const a = props.appearance;

  return {
    '--byzo-primary': a.primary_color,
    '--byzo-primary-dark': a.primary_dark_color,
    '--byzo-primary-light': a.primary_light_color,
    '--byzo-text': a.text_color,
    '--byzo-text-muted': a.muted_text_color,
    '--byzo-border': a.border_color,
    '--byzo-surface': a.surface_color,
    '--byzo-radius': `${a.radius}px`,
    '--byzo-radius-sm': `${a.radius_sm}px`,
    '--byzo-shadow': a.show_shadow
      ? '0 1px 2px rgba(16, 24, 40, 0.04), 0 2px 10px rgba(16, 24, 40, 0.06)'
      : 'none',
    fontSize: `${a.font_size}px`,
    fontFamily: a.font_family || undefined,
    // Honored as-is: the widget is `width:100%` inside the mock page, so
    // a max-width narrower than the frame visibly narrows it, and a wider
    // one simply fills the frame the way it would fill a real column.
    maxWidth: `${a.max_width}px`,
  };
});
</script>

<style scoped>
/* The stage (frame, backdrop, step switcher) is admin chrome — it uses
   the ADMIN theme's own variables, deliberately not the --byzo-* widget
   tokens, so a customized widget never restyles the frame around it. */
.byzo-stage {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.byzo-stage__frame {
  border: 1px solid hsl(var(--border));
  border-radius: 10px;
  overflow: hidden;
  background: hsl(var(--background));
}

.byzo-stage__bar {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 8px 10px;
  background: hsl(var(--muted));
  border-bottom: 1px solid hsl(var(--border));
}

.byzo-stage__light {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: hsl(var(--muted-foreground) / 0.35);
}

.byzo-stage__url {
  margin-left: 6px;
  padding: 2px 10px;
  flex: 1;
  border-radius: 999px;
  background: hsl(var(--background));
  color: hsl(var(--muted-foreground));
  font-size: 10px;
}

/* A neutral page-like backdrop with a faint grid, so the widget's own
   surface color and shadow have something to read against. */
.byzo-stage__page {
  padding: 22px 16px;
  display: flex;
  justify-content: center;
  background-color: hsl(var(--muted) / 0.5);
  background-image:
    linear-gradient(hsl(var(--border) / 0.5) 1px, transparent 1px),
    linear-gradient(90deg, hsl(var(--border) / 0.5) 1px, transparent 1px);
  background-size: 16px 16px;
}

.byzo-stage__steps {
  display: flex;
  gap: 4px;
  padding: 3px;
  border-radius: 8px;
  background: hsl(var(--muted));
}

.byzo-stage__step {
  flex: 1;
  padding: 5px 8px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
}

.byzo-stage__step--active {
  background: hsl(var(--background));
  color: hsl(var(--foreground));
  box-shadow: 0 1px 2px rgb(16 24 40 / 0.08);
}

/* Values below intentionally duplicate src/widget/widget.css rather than
   importing it: that stylesheet is a global, unscoped `.bookyzo-widget`
   sheet built into a separate front-end bundle, and pulling it into the
   admin app would ship it on every admin screen. */
.byzo-preview {
  width: 100%;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  line-height: 1.5;
  color: var(--byzo-text);
  background: var(--byzo-surface);
  border: 1px solid var(--byzo-border);
  border-radius: var(--byzo-radius);
  box-shadow: var(--byzo-shadow);
  padding: 24px;
}

.byzo-preview__progress {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 20px;
}

.byzo-preview__dot {
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--byzo-border);
}

.byzo-preview__dot--active {
  width: 20px;
  background: var(--byzo-primary);
}

.byzo-preview__title {
  margin: 0 0 4px;
  font-size: 1.29em;
  font-weight: 600;
  color: var(--byzo-text);
}

.byzo-preview__subtitle {
  margin: 0 0 16px;
  color: var(--byzo-text-muted);
}

.byzo-preview__cards {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.byzo-preview__card {
  display: block;
  border: 1px solid var(--byzo-border);
  border-radius: var(--byzo-radius-sm);
  padding: 12px;
}

.byzo-preview__card--selected {
  border-color: var(--byzo-primary);
}

.byzo-preview__card-name {
  display: block;
  font-weight: 600;
}

.byzo-preview__card-meta {
  display: block;
  color: var(--byzo-text-muted);
  font-size: 0.93em;
}

.byzo-preview__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 14px;
}

.byzo-preview__label {
  font-weight: 500;
  font-size: 0.93em;
}

.byzo-preview__input {
  border: 1px solid var(--byzo-border);
  border-radius: var(--byzo-radius-sm);
  padding: 8px 10px;
  color: var(--byzo-text-muted);
}

.byzo-preview__input--focus {
  outline: 2px solid var(--byzo-primary);
  outline-offset: 1px;
}

.byzo-preview__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.byzo-preview__chips--times {
  margin-top: 12px;
}

.byzo-preview__chip {
  border: 1px solid var(--byzo-border);
  border-radius: 999px;
  padding: 6px 14px;
  font-size: 0.93em;
}

.byzo-preview__chip--selected {
  border-color: var(--byzo-primary);
  color: var(--byzo-primary);
  background: var(--byzo-primary-light);
}

.byzo-preview__actions {
  display: flex;
  justify-content: space-between;
  margin-top: 20px;
}

.byzo-preview__btn {
  font-weight: 500;
  border-radius: var(--byzo-radius-sm);
  padding: 9px 16px;
  border: 1px solid transparent;
  font-size: 0.93em;
}

.byzo-preview__btn--primary {
  background: linear-gradient(135deg, var(--byzo-primary), var(--byzo-primary-dark));
  color: #fff;
}

.byzo-preview__btn--outline {
  border-color: var(--byzo-border);
  color: var(--byzo-text);
}
</style>
