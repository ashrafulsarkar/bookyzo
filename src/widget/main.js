import { createApp } from 'vue';
import BookingWidget from './BookingWidget.vue';
import { installI18n } from '../lib/i18n';
import './widget.css';

// Multiple embeds are supported on one page (e.g. two shortcodes, or a
// block plus a shortcode) — each .bookyzo-widget-mount element
// (rendered by BYZO_Public_Widget::render()) gets its own independent
// Vue app instance and reads its own config from its own data-*
// attributes, so instances never share state.
// A link can pick a time for the visitor: ?byzo_item=ID&byzo_slot=ID (the
// waitlist's "a place opened up" email uses it). The embed opens on that
// item with the time selected; they still confirm it themselves.
const params = new URLSearchParams(window.location.search);
const linkedItemId = Number(params.get('byzo_item')) || null;
const linkedSlotId = Number(params.get('byzo_slot')) || null;

document.querySelectorAll('.bookyzo-widget-mount').forEach((el) => {
  const bookableItemId = el.dataset.bookableItemId ? Number(el.dataset.bookableItemId) : null;
  const linked = linkedItemId && (!bookableItemId || bookableItemId === linkedItemId);
  const config = {
    bookableItemId,
    type: el.dataset.type || null,
    linkedItemId: linked ? linkedItemId : null,
    linkedSlotId: linked ? linkedSlotId : null,
  };
  installI18n(createApp(BookingWidget, { config })).mount(el);
});
