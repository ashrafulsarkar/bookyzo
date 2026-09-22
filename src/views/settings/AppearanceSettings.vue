<template>
  <div>
    <ByzoPageHeader title="Appearance" subtitle="Match the booking widget to your site's design.">
      <template #icon>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="13.5" cy="6.5" r=".5" fill="currentColor" />
          <circle cx="17.5" cy="10.5" r=".5" fill="currentColor" />
          <circle cx="8.5" cy="7.5" r=".5" fill="currentColor" />
          <circle cx="6.5" cy="12.5" r=".5" fill="currentColor" />
          <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
        </svg>
      </template>
      <template #actions>
        <Button variant="outline" :disabled="loading || saving" @click="resetToDefaults">Reset to defaults</Button>
        <Button :disabled="loading || saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
      </template>
    </ByzoPageHeader>

    <div class="px-6 pb-8">
      <ByzoLoading v-if="loading" />

      <!-- Controls and a live preview side by side, so a color change is
           visible without leaving wp-admin to reload a front-end page.
           Stacks on narrow screens. -->
      <div v-else class="flex flex-col xl:flex-row gap-4 items-start">
        <div class="flex flex-col gap-4 flex-1 min-w-0 w-full">
          <Card>
            <CardHeader>
              <CardTitle>Colors</CardTitle>
              <CardDescription>The accent color is used for buttons, the progress bar, and selected times.</CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-4">
              <ColorField
                v-for="field in colorFields"
                :key="field.key"
                :id="'a-' + field.key"
                v-model="form[field.key]"
                :label="field.label"
                :hint="field.hint"
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Layout &amp; typography</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
              <div class="grid grid-cols-2 gap-4">
                <div class="flex flex-col gap-1.5">
                  <Label for="a-max-width">Maximum width</Label>
                  <div class="flex items-center gap-2">
                    <Input id="a-max-width" v-model.number="form.max_width" type="number" min="280" max="900" step="10" class="w-24" />
                    <span class="text-sm text-muted-foreground">px</span>
                  </div>
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label for="a-font-size">Base font size</Label>
                  <div class="flex items-center gap-2">
                    <Input id="a-font-size" v-model.number="form.font_size" type="number" min="11" max="22" step="1" class="w-24" />
                    <span class="text-sm text-muted-foreground">px</span>
                  </div>
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label for="a-radius">Corner rounding</Label>
                  <div class="flex items-center gap-2">
                    <Input id="a-radius" v-model.number="form.radius" type="number" min="0" max="40" step="1" class="w-24" />
                    <span class="text-sm text-muted-foreground">px</span>
                  </div>
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label for="a-radius-sm">Button/input rounding</Label>
                  <div class="flex items-center gap-2">
                    <Input id="a-radius-sm" v-model.number="form.radius_sm" type="number" min="0" max="40" step="1" class="w-24" />
                    <span class="text-sm text-muted-foreground">px</span>
                  </div>
                </div>
              </div>

              <div class="flex flex-col gap-1.5">
                <Label for="a-font-family">Font family</Label>
                <Input id="a-font-family" v-model="form.font_family" type="text" placeholder="Leave empty to use your theme's font" />
                <p class="text-xs text-muted-foreground">
                  A CSS font list, e.g. <code>Poppins, sans-serif</code>. The font must already be loaded by your
                  theme — this setting picks a font, it doesn't download one.
                </p>
              </div>

              <label class="flex items-center gap-2 text-sm">
                <Switch v-model="form.show_shadow" />
                Show a drop shadow around the widget
              </label>
            </CardContent>
          </Card>

          <!-- Add-on extension point (FEATURES.md B10 — custom CSS/full
               theme authoring beyond these preset controls). Absent when
               nothing is registered, so Free renders exactly as before —
               see lib/admin-extensions.js. -->
          <component :is="extraAppearanceSection" v-if="extraAppearanceSection" />

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        </div>

        <Card class="w-full xl:w-[420px] xl:shrink-0 xl:sticky xl:top-8">
          <CardHeader>
            <CardTitle>Preview</CardTitle>
            <CardDescription>Updates as you edit. Save to apply it on your site.</CardDescription>
          </CardHeader>
          <CardContent>
            <WidgetPreview :appearance="form" />
          </CardContent>
        </Card>
      </div>
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
import ColorField from './appearance/ColorField.vue';
import WidgetPreview from './appearance/WidgetPreview.vue';
import { extraAppearanceSection } from '@/lib/admin-extensions';
import { api } from '@/api/client';

// Mirrors BYZO_Appearance_Settings::defaults() — kept in sync so the
// screen renders sensible controls before the GET lands, and so
// "Reset to defaults" needs no extra round-trip.
const DEFAULTS = {
  primary_color: '#2563eb',
  primary_dark_color: '#1d4ed8',
  primary_light_color: '#eff6ff',
  text_color: '#1d2327',
  muted_text_color: '#6b7280',
  border_color: '#e7e8ec',
  surface_color: '#ffffff',
  radius: 14,
  radius_sm: 8,
  max_width: 460,
  font_size: 14,
  font_family: '',
  show_shadow: true,
};

const colorFields = [
  { key: 'primary_color', label: 'Accent', hint: 'Buttons, progress bar, selected times.' },
  { key: 'primary_dark_color', label: 'Accent (dark)', hint: 'The darker end of the button gradient.' },
  { key: 'primary_light_color', label: 'Accent (light)', hint: 'Background of a selected date or time.' },
  { key: 'text_color', label: 'Text' },
  { key: 'muted_text_color', label: 'Secondary text' },
  { key: 'border_color', label: 'Borders' },
  { key: 'surface_color', label: 'Background' },
];

const form = ref({ ...DEFAULTS });
const loading = ref(true);
const saving = ref(false);
const error = ref('');

async function load() {
  loading.value = true;
  try {
    const appearance = await api.get('/appearance');
    form.value = { ...DEFAULTS, ...appearance };
  } catch (e) {
    error.value = e.message || 'Could not load appearance settings.';
  } finally {
    loading.value = false;
  }
}

// Local-only until saved, same as any other edit on this screen — so a
// misclick is undone by navigating away rather than needing an undo.
function resetToDefaults() {
  form.value = { ...DEFAULTS };
}

async function save() {
  error.value = '';
  saving.value = true;
  try {
    const appearance = await api.put('/appearance', form.value);
    form.value = { ...DEFAULTS, ...appearance };
    toast.success('Appearance saved.');
  } catch (e) {
    error.value = e.message || 'Could not save appearance settings.';
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>
