<template>
  <!-- A static replica of the real widget's three main steps (service
       list, calendar + time picker, details form), matching
       src/widget/widget.css's actual current markup/classes structurally
       (segmented progress bar, .option-card rows, month calendar +
       grouped times, .summary card) and driven by the same CSS custom
       property names, so what's rendered here is what those tokens will
       produce on the front end. Deliberately NOT the real widget bundle:
       that would need REST data, a booking flow, and a second Vue app
       inside wp-admin to show what is only ever a color/spacing check.
       Keep this in sync with widget.css/the widget/steps/*.vue markup if
       they change there. -->
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
        <span class="byzo-stage__url">{{ __('yoursite.com', 'bookyzo') }}</span>
      </div>

      <div class="byzo-stage__page">
        <div class="byzo-preview" :style="previewStyle">
          <!-- A segmented bar, not dots — matches src/widget/widget.css's
               own .progress: each fixed-width segment is one step, filled
               up to the current one. -->
          <div class="byzo-preview__progress">
            <span
              v-for="n in 3"
              :key="n"
              class="byzo-preview__dot"
              :class="{ 'byzo-preview__dot--active': n <= activeStep }"
            />
          </div>

          <template v-if="step === 'service'">
            <h2 class="byzo-preview__title">{{ __('Choose a service', 'bookyzo') }}</h2>
            <p class="byzo-preview__subtitle">{{ __('Pick what you\'d like to book.', 'bookyzo') }}</p>

            <div class="byzo-preview__card-list">
              <span
                v-for="service in services"
                :key="service.name"
                class="byzo-preview__card"
                :class="{ 'byzo-preview__card--hover': service.highlighted }"
              >
                <span class="byzo-preview__card-body">
                  <span class="byzo-preview__card-name">{{ service.name }}</span>
                  <span class="byzo-preview__card-meta">
                    <span>{{ service.duration }}</span>
                    <span class="byzo-preview__card-price">{{ service.price }}</span>
                  </span>
                </span>
                <span class="byzo-preview__card-go">&#8250;</span>
              </span>
            </div>
          </template>

          <template v-else-if="step === 'time'">
            <h2 class="byzo-preview__title">{{ __('Pick a time', 'bookyzo') }}</h2>
            <p class="byzo-preview__subtitle">{{ __('Maya Chen — Senior Stylist', 'bookyzo') }}</p>

            <!-- Month calendar beside grouped times, same as StepSlot.vue's
                 real .picker layout (not the old flat run of date chips). -->
            <div class="byzo-preview__picker">
              <div class="byzo-preview__cal">
                <div class="byzo-preview__cal-head">
                  <span class="byzo-preview__cal-nav">&#8249;</span>
                  <span class="byzo-preview__cal-title">{{ __('September 2026', 'bookyzo') }}</span>
                  <span class="byzo-preview__cal-nav">&#8250;</span>
                </div>
                <div class="byzo-preview__cal-grid byzo-preview__cal-dow">
                  <span v-for="dow in dayNames" :key="dow">{{ dow }}</span>
                </div>
                <div class="byzo-preview__cal-grid">
                  <span
                    v-for="(cell, index) in calendarCells"
                    :key="index"
                    class="byzo-preview__cal-cell"
                    :class="{
                      'byzo-preview__cal-cell--empty': !cell,
                      'byzo-preview__cal-cell--open': cell?.state === 'open',
                      'byzo-preview__cal-cell--selected': cell?.state === 'selected',
                    }"
                  >{{ cell?.day }}</span>
                </div>
              </div>

              <div class="byzo-preview__times">
                <div class="byzo-preview__times-head">{{ __('Thursday, September 10', 'bookyzo') }}</div>
                <div class="byzo-preview__time-group">
                  <div class="byzo-preview__time-group-label">{{ __('Morning', 'bookyzo') }}</div>
                  <div class="byzo-preview__chips">
                    <span class="byzo-preview__chip">9:00</span>
                    <span class="byzo-preview__chip byzo-preview__chip--selected">10:50</span>
                    <span class="byzo-preview__chip">11:30</span>
                  </div>
                </div>
                <div class="byzo-preview__time-group">
                  <div class="byzo-preview__time-group-label">{{ __('Afternoon', 'bookyzo') }}</div>
                  <div class="byzo-preview__chips">
                    <span class="byzo-preview__chip">12:40</span>
                    <span class="byzo-preview__chip">2:15</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="byzo-preview__actions">
              <span class="byzo-preview__btn byzo-preview__btn--outline">{{ __('Back', 'bookyzo') }}</span>
              <span class="byzo-preview__btn byzo-preview__btn--primary">{{ __('Continue', 'bookyzo') }}</span>
            </div>
          </template>

          <template v-else>
            <h2 class="byzo-preview__title">{{ __('Your details', 'bookyzo') }}</h2>

            <!-- Restated booking summary, same as StepDetails.vue's real
                 .summary card — not a bare form. -->
            <div class="byzo-preview__summary">
              <div class="byzo-preview__summary-row">
                <span class="byzo-preview__summary-label">{{ __('Service', 'bookyzo') }}</span>
                <span class="byzo-preview__summary-value">{{ __('Maya Chen — Senior Stylist', 'bookyzo') }}</span>
              </div>
              <div class="byzo-preview__summary-row">
                <span class="byzo-preview__summary-label">{{ __('When', 'bookyzo') }}</span>
                <span class="byzo-preview__summary-value">{{ __('Thu 10 Sept, 10:50', 'bookyzo') }}</span>
              </div>
              <div class="byzo-preview__summary-row byzo-preview__summary-row--total">
                <span class="byzo-preview__summary-label">{{ __('Total', 'bookyzo') }}</span>
                <span class="byzo-preview__summary-value">65.00</span>
              </div>
            </div>

            <div class="byzo-preview__field">
              <span class="byzo-preview__label">{{ __('Name', 'bookyzo') }}</span>
              <span class="byzo-preview__input">{{ __('Jamie Rivera', 'bookyzo') }}</span>
            </div>
            <div class="byzo-preview__field">
              <span class="byzo-preview__label">{{ __('Email', 'bookyzo') }}</span>
              <span class="byzo-preview__input byzo-preview__input--focus">{{ __('jamie@example.com', 'bookyzo') }}</span>
            </div>

            <div class="byzo-preview__actions">
              <span class="byzo-preview__btn byzo-preview__btn--outline">{{ __('Back', 'bookyzo') }}</span>
              <span class="byzo-preview__btn byzo-preview__btn--primary">{{ __('Confirm booking', 'bookyzo') }}</span>
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
import { i18n } from '@/lib/i18n';
import { ref, computed } from 'vue';

const props = defineProps({
  appearance: { type: Object, required: true },
});

const stepOptions = [
  { value: 'service', label: i18n.__('Service', 'bookyzo') },
  { value: 'time', label: i18n.__('Time', 'bookyzo') },
  { value: 'details', label: i18n.__('Details', 'bookyzo') },
];

const step = ref('time');

const services = [
  { name: 'Maya Chen — Senior Stylist', duration: '45 min', price: '65.00', highlighted: true },
  { name: 'Morning Flow Yoga', duration: '60 min', price: '18.00', highlighted: false },
];

const dayNames = [
  i18n._x('Su', 'Sunday, two letters', 'bookyzo'),
  i18n._x('Mo', 'Monday, two letters', 'bookyzo'),
  i18n._x('Tu', 'Tuesday, two letters', 'bookyzo'),
  i18n._x('We', 'Wednesday, two letters', 'bookyzo'),
  i18n._x('Th', 'Thursday, two letters', 'bookyzo'),
  i18n._x('Fr', 'Friday, two letters', 'bookyzo'),
  i18n._x('Sa', 'Saturday, two letters', 'bookyzo'),
];

// September 2026 starts on a Tuesday — 2 leading blanks, then 30 days.
// A handful marked "open" (bookable) and one "selected", the rest muted,
// same three cell states StepSlot.vue's real calendar uses.
const OPEN_DAYS = new Set([3, 4, 8, 11, 15, 17, 18, 22, 24, 25, 29]);
const SELECTED_DAY = 10;

const calendarCells = computed(() => {
  const cells = new Array(2).fill(null);
  for (let day = 1; day <= 30; day += 1) {
    cells.push({
      day,
      state: day === SELECTED_DAY ? 'selected' : OPEN_DAYS.has(day) ? 'open' : 'muted',
    });
  }
  return cells;
});

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

/* A segmented bar (flex-1 pills), not round dots — matches widget.css's
   real .progress exactly, just at the preview's own smaller scale. */
.byzo-preview__progress {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 18px;
}

.byzo-preview__dot {
  flex: 1;
  height: 4px;
  border-radius: 999px;
  background: var(--byzo-border);
}

.byzo-preview__dot--active {
  background: var(--byzo-primary);
}

.byzo-preview__title {
  margin: 0 0 4px;
  font-size: 1.29em;
  font-weight: 700;
  color: var(--byzo-text);
}

.byzo-preview__subtitle {
  margin: 0 0 16px;
  color: var(--byzo-text-muted);
}

/* Service list — real StepBookableItem.vue's .option-card: a full-width
   row (name + duration/price on the left, a "go" chevron on the right),
   not a bare bordered block. */
.byzo-preview__card-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.byzo-preview__card {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--byzo-border);
  border-radius: var(--byzo-radius-sm);
  padding: 12px;
  background: var(--byzo-surface);
}

/* Static stand-in for .option-card:hover — there's no real cursor here,
   so one card is shown in its hover state to demonstrate the accent
   color/light-tint tokens actually being used. */
.byzo-preview__card--hover {
  border-color: var(--byzo-primary);
  background: var(--byzo-primary-light);
}

.byzo-preview__card-body {
  flex: 1;
  min-width: 0;
}

.byzo-preview__card-name {
  display: block;
  font-weight: 600;
  margin-bottom: 2px;
}

.byzo-preview__card-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--byzo-text-muted);
  font-size: 0.87em;
}

.byzo-preview__card-price {
  color: var(--byzo-text);
  font-weight: 600;
}

.byzo-preview__card-go {
  color: var(--byzo-text-muted);
  font-size: 1.2em;
  flex-shrink: 0;
}

/* Calendar + grouped times, same layout as StepSlot.vue's real .picker.
   The calendar's own tint (--byzo-subtle in widget.css) is NOT a
   customizable Appearance field on the real widget either — kept as the
   same fixed value here so the preview doesn't imply a control that
   doesn't exist. */
.byzo-preview__picker {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
}

.byzo-preview__cal {
  flex: 0 0 auto;
  background: #f6f7f9;
  border-radius: var(--byzo-radius-sm);
  padding: 10px 12px;
}

.byzo-preview__cal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}

.byzo-preview__cal-title {
  font-weight: 650;
  font-size: 0.87em;
  white-space: nowrap;
}

.byzo-preview__cal-nav {
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: var(--byzo-surface);
  color: var(--byzo-text-muted);
  font-size: 0.8em;
  flex-shrink: 0;
}

.byzo-preview__cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 22px);
  justify-content: space-between;
  gap: 3px 0;
}

.byzo-preview__cal-dow {
  margin-bottom: 4px;
  font-size: 0.6em;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--byzo-text-muted);
  text-align: center;
}

.byzo-preview__cal-cell {
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  color: #b6bcc6;
  font-size: 0.7em;
}

.byzo-preview__cal-cell--empty {
  visibility: hidden;
}

.byzo-preview__cal-cell--open {
  color: var(--byzo-text);
  font-weight: 600;
  background: var(--byzo-surface);
}

.byzo-preview__cal-cell--selected {
  background: var(--byzo-primary);
  color: #fff;
}

.byzo-preview__times {
  flex: 1 1 160px;
  min-width: 0;
}

.byzo-preview__times-head {
  font-weight: 650;
  font-size: 0.93em;
  margin-bottom: 10px;
}

.byzo-preview__time-group + .byzo-preview__time-group {
  margin-top: 10px;
}

.byzo-preview__time-group-label {
  font-size: 0.7em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--byzo-text-muted);
  margin-bottom: 5px;
}

/* Details step — restated booking summary, same as StepDetails.vue's
   real .summary card. */
.byzo-preview__summary {
  background: var(--byzo-primary-light);
  border-radius: var(--byzo-radius-sm);
  padding: 10px 12px;
  margin-bottom: 16px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.byzo-preview__summary-row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 0.87em;
}

.byzo-preview__summary-label {
  color: var(--byzo-text-muted);
  flex-shrink: 0;
}

.byzo-preview__summary-value {
  font-weight: 600;
  text-align: right;
}

.byzo-preview__summary-row--total {
  border-top: 1px solid rgba(0, 0, 0, 0.1);
  margin-top: 1px;
  padding-top: 6px;
}

.byzo-preview__summary-row--total .byzo-preview__summary-value {
  font-size: 1.07em;
  color: var(--byzo-text);
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
  gap: 6px;
}

.byzo-preview__chip {
  border: 1px solid var(--byzo-border);
  border-radius: var(--byzo-radius-sm);
  padding: 6px 12px;
  font-size: 0.87em;
  font-weight: 600;
}

/* Filled, not just tinted — matches widget.css's real .chip.selected. */
.byzo-preview__chip--selected {
  border-color: var(--byzo-primary);
  background: var(--byzo-primary);
  color: #fff;
}

.byzo-preview__actions {
  display: flex;
  justify-content: space-between;
  margin-top: 20px;
}

.byzo-preview__btn {
  font-weight: 600;
  border-radius: var(--byzo-radius-sm);
  padding: 9px 16px;
  border: 1px solid transparent;
  font-size: 0.93em;
}

.byzo-preview__btn--primary {
  background: linear-gradient(135deg, var(--byzo-primary), var(--byzo-primary-dark));
  color: #fff;
  /* Hardcoded to match widget.css's own .btn-primary shadow, which is
     likewise NOT derived from the accent color token on the real widget. */
  box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
}

.byzo-preview__btn--outline {
  border-color: var(--byzo-border);
  color: var(--byzo-text);
}
</style>
