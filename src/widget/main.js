import { createApp } from 'vue';
import BookingWidget from './BookingWidget.vue';
import { installI18n } from '../lib/i18n';
import './widget.css';

// Multiple embeds are supported on one page (e.g. two shortcodes, or a
// block plus a shortcode) — each .bookyzo-widget-mount element
// (rendered by BYZO_Public_Widget::render()) gets its own independent
// Vue app instance and reads its own config from its own data-*
// attributes, so instances never share state.
document.querySelectorAll('.bookyzo-widget-mount').forEach((el) => {
  const config = {
    bookableItemId: el.dataset.bookableItemId ? Number(el.dataset.bookableItemId) : null,
    type: el.dataset.type || null,
  };
  installI18n(createApp(BookingWidget, { config })).mount(el);
});
