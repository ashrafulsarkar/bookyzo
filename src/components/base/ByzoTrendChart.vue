<template>
  <div ref="rootEl" class="relative flex flex-col gap-1">
    <svg
      :viewBox="`0 0 ${width} ${height}`"
      class="w-full"
      :style="{ height: `${height}px` }"
      @pointermove="onPointerMove"
      @pointerleave="activeIndex = null"
    >
      <!-- gridlines: recessive, hairline, one-step-off-surface -->
      <line
        v-for="y in gridLines"
        :key="y.value"
        :x1="padding.left"
        :x2="width - padding.right"
        :y1="y.pos"
        :y2="y.pos"
        stroke="var(--byzo-border)"
        stroke-width="1"
      />

      <!-- y-axis ticks: clean whole numbers in muted text, so the gridlines
           mean something without hovering (QA report 2026-09-22 U8) -->
      <text
        v-for="y in gridLines"
        :key="'tick-' + y.value"
        :x="padding.left - 8"
        :y="y.pos"
        text-anchor="end"
        dominant-baseline="middle"
        font-size="11"
        fill="var(--byzo-text-muted)"
        style="font-variant-numeric: tabular-nums"
      >{{ y.value.toLocaleString() }}</text>

      <!-- area fill: series hue at ~10% opacity -->
      <path :d="areaPath" fill="var(--byzo-primary)" opacity="0.1" />

      <!-- line: 2px, round join/cap -->
      <path :d="linePath" fill="none" stroke="var(--byzo-primary)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

      <!-- crosshair + endpoint marker on hover -->
      <g v-if="activeIndex !== null">
        <line
          :x1="pointX(activeIndex)"
          :x2="pointX(activeIndex)"
          :y1="padding.top"
          :y2="height - padding.bottom"
          stroke="var(--byzo-border)"
          stroke-width="1"
        />
        <circle :cx="pointX(activeIndex)" :cy="pointY(activeIndex)" r="6" fill="var(--byzo-primary)" stroke="var(--byzo-surface)" stroke-width="2" />
      </g>

      <!-- always-on endpoint dot on the last point, per marks-and-anatomy's "label the endpoint" -->
      <circle
        v-if="points.length && activeIndex === null"
        :cx="pointX(points.length - 1)"
        :cy="pointY(points.length - 1)"
        r="5"
        fill="var(--byzo-primary)"
        stroke="var(--byzo-surface)"
        stroke-width="2"
      />

      <!-- transparent hit strips, one per day, so hover snaps to the nearest date rather than requiring pixel-perfect aim -->
      <rect
        v-for="(p, i) in points"
        :key="i"
        :x="stripX(i)"
        y="0"
        :width="stripWidth"
        :height="height"
        fill="transparent"
        @pointerenter="activeIndex = i"
      />
    </svg>

    <!-- x-axis labels: first, middle, last only — a label per day would be illegible at 14-90 points -->
    <div class="flex justify-between pr-1 text-[10px] text-muted-foreground" :style="{ paddingLeft: `${(padding.left / width) * 100}%` }">
      <span>{{ axisLabel(0) }}</span>
      <span v-if="points.length > 2">{{ axisLabel(Math.floor((points.length - 1) / 2)) }}</span>
      <span>{{ axisLabel(points.length - 1) }}</span>
    </div>

    <!-- tooltip: value leads, label follows; reachable state also sits in the always-visible endpoint/axis, so this only enhances -->
    <div
      v-if="activeIndex !== null"
      class="pointer-events-none absolute rounded-md border px-2.5 py-1.5 text-xs shadow-md"
      style="background: var(--byzo-surface); border-color: var(--byzo-border);"
      :style="tooltipStyle"
    >
      <div class="font-semibold tabular-nums">{{ points[activeIndex].total }} booking{{ points[activeIndex].total === 1 ? '' : 's' }}</div>
      <div class="text-muted-foreground">{{ fullLabel(activeIndex) }}</div>
    </div>
  </div>
</template>

<script setup>
// A single-series daily-trend area chart (Dashboard's booking-count
// history) — no shadcn-vue chart primitive exists for this, so it's a
// custom SVG component in the same vein as ByzoCalendar/ByzoWeeklyScheduleGrid,
// following the dataviz skill's mark specs (2px line, ~10% area wash,
// hairline recessive gridlines, crosshair+tooltip hover, single series
// needs no legend box since the card title already names the series).
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
  // Array of { date: 'YYYY-MM-DD', total: number }, oldest first.
  points: { type: Array, required: true },
});

// The drawing is laid out in real pixels at the card's own width: a fixed
// 600-unit viewBox under the default "meet" scaling sat centred in a wider
// card with empty bands either side, and would have shrunk the tick labels
// on a narrow one.
const width = ref(600);
const rootEl = ref(null);
let resizeObserver = null;

onMounted(() => {
  if (!rootEl.value || typeof ResizeObserver === 'undefined') return;
  resizeObserver = new ResizeObserver(([entry]) => {
    width.value = Math.max(240, Math.round(entry.contentRect.width));
  });
  resizeObserver.observe(rootEl.value);
});

onBeforeUnmount(() => resizeObserver?.disconnect());
const height = 160;
// left: room for the y-axis tick labels.
const padding = { top: 12, right: 8, bottom: 4, left: 36 };

const activeIndex = ref(null);

// A clean whole-number step (1, 2, 5 × 10ⁿ) giving at most four intervals,
// and the axis ceiling rounded up to it — so every gridline, and now every
// tick label, lands on a round number (marks-and-anatomy: "round to clean
// numbers"). Bookings are counted, so the step never drops below 1.
const step = computed(() => {
  const max = Math.max(1, ...props.points.map((p) => p.total));
  const raw = max / 4;
  const magnitude = 10 ** Math.floor(Math.log10(raw));
  const nice = [1, 2, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= raw);
  return Math.max(1, nice);
});

const maxValue = computed(() => {
  const max = Math.max(1, ...props.points.map((p) => p.total));
  return Math.max(step.value * 2, Math.ceil(max / step.value) * step.value);
});

const gridLines = computed(() => {
  const count = Math.round(maxValue.value / step.value);
  return Array.from({ length: count + 1 }, (_, i) => {
    const value = step.value * i;
    return { value, pos: pointY(0, value) };
  });
});

const plotWidth = computed(() => width.value - padding.left - padding.right);
const plotHeight = height - padding.top - padding.bottom;

function pointX(index) {
  if (props.points.length <= 1) return padding.left + plotWidth.value / 2;
  return padding.left + (index / (props.points.length - 1)) * plotWidth.value;
}

function pointY(index, overrideValue) {
  const value = overrideValue !== undefined ? overrideValue : props.points[index]?.total ?? 0;
  return padding.top + plotHeight - (value / maxValue.value) * plotHeight;
}

const stripWidth = computed(() => (props.points.length ? plotWidth.value / props.points.length : 0));

function stripX(index) {
  return padding.left + index * stripWidth.value;
}

const linePath = computed(() => {
  if (!props.points.length) return '';
  return props.points.map((_, i) => `${i === 0 ? 'M' : 'L'} ${pointX(i)} ${pointY(i)}`).join(' ');
});

const areaPath = computed(() => {
  if (!props.points.length) return '';
  const baseline = height - padding.bottom;
  const top = props.points.map((_, i) => `${i === 0 ? 'M' : 'L'} ${pointX(i)} ${pointY(i)}`).join(' ');
  return `${top} L ${pointX(props.points.length - 1)} ${baseline} L ${pointX(0)} ${baseline} Z`;
});

function onPointerMove(event) {
  if (!props.points.length) return;
  const svg = event.currentTarget;
  const rect = svg.getBoundingClientRect();
  const x = ((event.clientX - rect.left) / rect.width) * width.value;
  const ratio = Math.max(0, Math.min(1, (x - padding.left) / plotWidth.value));
  activeIndex.value = Math.round(ratio * (props.points.length - 1));
}

function dateLabel(index) {
  const [y, m, d] = props.points[index].date.split('-').map(Number);
  return new Date(y, m - 1, d);
}

function axisLabel(index) {
  return dateLabel(index).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function fullLabel(index) {
  return dateLabel(index).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

const tooltipStyle = computed(() => {
  if (activeIndex.value === null) return {};
  const leftPct = (pointX(activeIndex.value) / width.value) * 100;
  const clamped = Math.max(12, Math.min(88, leftPct));
  return { left: `${clamped}%`, top: '0px', transform: 'translate(-50%, -100%)' };
});
</script>
