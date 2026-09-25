/**
 * Translations for the Vue apps (QA report 2026-09-22 §6.2.1).
 *
 * WordPress does the work: each bundle depends on `wp-i18n`, and PHP calls
 * wp_set_script_translations(), which loads the language pack's JSON for
 * that exact built file into wp.i18n before the bundle runs.
 *
 * Every call must keep the shape `something.__('Text', 'bookyzo')` in the
 * BUILT file, or `wp i18n make-pot` (what translate.wordpress.org runs)
 * can't find the string: it matches calls by their property name, and the
 * minifier renames plain functions. So:
 * - templates call the app-wide `__()` / `_n()` / `_x()` / `sprintf()`
 *   installed below; they compile to `_ctx.__(...)`.
 * - script code calls `i18n.__('Text', 'bookyzo')` on the object exported
 *   here; never destructure it into a bare `__`.
 * The text domain is always written out as a literal for the same reason.
 * Pro's components render inside Free's admin app, so they use the same
 * template helpers with their own 'bookyzo-pro' domain.
 */

// Outside WordPress (or before wp-i18n loads) the text comes back as is.
function fallbackSprintf(format, ...args) {
  let next = 0;
  return String(format).replace(/%(?:(\d+)\$)?([sd%])/g, (match, position, kind) => {
    if (kind === '%') return '%';
    const value = args[position ? Number(position) - 1 : next++];
    return kind === 'd' ? String(parseInt(value, 10)) : String(value);
  });
}

const fallback = {
  __: (text) => text,
  _x: (text) => text,
  _n: (single, plural, number) => (Number(number) === 1 ? single : plural),
  sprintf: fallbackSprintf,
};

export const i18n = (typeof window !== 'undefined' && window.wp && window.wp.i18n) || fallback;

/** Make the helpers available in every template of `app`. */
export function installI18n(app) {
  Object.assign(app.config.globalProperties, {
    __: (text, domain) => i18n.__(text, domain),
    _x: (text, context, domain) => i18n._x(text, context, domain),
    _n: (single, plural, number, domain) => i18n._n(single, plural, number, domain),
    sprintf: (format, ...args) => i18n.sprintf(format, ...args),
  });
  return app;
}

/**
 * The language dates, times and amounts are written in: the page's own
 * `<html lang>`, which WordPress sets to the site's language (the user's,
 * in wp-admin), so they match the translated text around them. The
 * browser's own when it's missing or not a tag Intl knows.
 */
export const locale = (() => {
  try {
    const lang = document.documentElement.lang;
    return lang && Intl.DateTimeFormat.supportedLocalesOf([lang]).length ? lang : undefined;
  } catch (error) {
    return undefined;
  }
})();
