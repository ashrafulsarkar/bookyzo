import { createApp } from 'vue';
import BookingWidget from './BookingWidget.vue';
import './widget.css';

// Multiple embeds are supported on one page (e.g. two shortcodes, or a
// block plus a shortcode) — each .bookyzo-widget-mount element
// (rendered by BYZO_Public_Widget::render()) gets its own independent
// Vue app instance and reads its own config from its own data-*
// attributes, so instances never share state.
document.querySelectorAll('.bookyzo-widget-mount').forEach((el) => {
  const config = {
    resourceId: el.dataset.resourceId ? Number(el.dataset.resourceId) : null,
    type: el.dataset.type || null,
  };
  createApp(BookingWidget, { config }).mount(el);
});
