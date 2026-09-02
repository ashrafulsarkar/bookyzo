<template>
  <div class="relative flex flex-col gap-1">
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
    <div class="flex justify-between px-1 text-[10px] text-muted-foreground">
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
import { ref, computed } from 'vue';

const props = defineProps({
  // Array of { date: 'YYYY-MM-DD', total: number }, oldest first.
  points: { type: Array, required: true },
});

const width = 600;
const height = 160;
const padding = { top: 12, right: 8, bottom: 4, left: 8 };

const activeIndex = ref(null);

const maxValue = computed(() => {
  const max = Math.max(1, ...props.points.map((p) => p.total));
  // Round the axis ceiling up to a clean step so gridlines land on
  // round numbers (marks-and-anatomy: "round to clean numbers").
  if (max <= 5) return 5;
  const magnitude = 10 ** Math.floor(Math.log10(max));
  return Math.ceil(max / magnitude) * magnitude;
});

const gridLines = computed(() => {
  const steps = 3;
  return Array.from({ length: steps + 1 }, (_, i) => {
    const value = Math.round((maxValue.value / steps) * i);
    return { value, pos: pointY(0, value) };
  });
});

const plotWidth = width - padding.left - padding.right;
const plotHeight = height - padding.top - padding.bottom;

function pointX(index) {
  if (props.points.length <= 1) return padding.left + plotWidth / 2;
  return padding.left + (index / (props.points.length - 1)) * plotWidth;
}

function pointY(index, overrideValue) {
  const value = overrideValue !== undefined ? overrideValue : props.points[index]?.total ?? 0;
  return padding.top + plotHeight - (value / maxValue.value) * plotHeight;
}

const stripWidth = computed(() => (props.points.length ? plotWidth / props.points.length : 0));

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
  const x = ((event.clientX - rect.left) / rect.width) * width;
  const ratio = Math.max(0, Math.min(1, (x - padding.left) / plotWidth));
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
  const leftPct = (pointX(activeIndex.value) / width) * 100;
  const clamped = Math.max(12, Math.min(88, leftPct));
  return { left: `${clamped}%`, top: '0px', transform: 'translate(-50%, -100%)' };
});
</script>
