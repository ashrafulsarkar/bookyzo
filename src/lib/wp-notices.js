/**
 * wp-admin notices on Bookyzo's screens.
 *
 * WordPress prints admin notices (core's update nag, other plugins'
 * `admin_notices`, legacy `.updated`/`.error` boxes) as direct children of
 * #wpbody-content, right above #byzo-admin-app. On these screens that
 * spot is under Bookyzo's fixed sidebar and sticky page header, so a
 * notice was half hidden; the update nag's 25px top margin also collapsed
 * through #wpbody and pushed wp-admin's own left menu down, leaving a
 * white strip above it.
 *
 * Instead, every notice is moved into one host element that sits directly
 * under the current screen's page header (Free's .byzo-page-header, Pro's
 * .byzop-header), or at the top of the setup wizard, and styled the same
 * way (styles/tailwind.css, "wp-admin notices"). The host is a plain DOM
 * node Vue doesn't own: when a route change removes the old screen the
 * host goes with it, and the observer below puts it back under the new
 * header. Notices added later by other scripts are picked up the same way.
 */

import { i18n } from './i18n';

const NOTICE_SELECTOR = ':scope > .notice, :scope > .update-nag, :scope > .updated, :scope > .error';
const HEADER_SELECTOR = '.byzo-shell-main > * > .byzo-page-header, .byzo-shell-main > * > .byzop-header';

export function installWpNotices() {
  const body = document.getElementById('wpbody-content');
  const app = document.getElementById('byzo-admin-app');
  if (!body || !app) {
    return;
  }

  const host = document.createElement('div');
  host.className = 'byzo-wp-notices';
  host.setAttribute('role', 'region');
  host.setAttribute('aria-label', i18n.__('Notices', 'bookyzo'));

  function collect() {
    body.querySelectorAll(NOTICE_SELECTOR).forEach((notice) => {
      // The update nag is an inline-block by design; inside the host every
      // notice is the same full-width block.
      notice.classList.remove('inline');
      host.appendChild(notice);
    });
  }

  function place() {
    const header = app.querySelector(HEADER_SELECTOR);
    if (header) {
      if (header.nextElementSibling !== host) {
        header.after(host);
      }
      return;
    }
    const setup = app.querySelector('.byzo-setup');
    if (setup) {
      if (setup.firstElementChild !== host) {
        setup.prepend(host);
      }
      return;
    }
    // A screen still loading has no header yet — park the host at the
    // top of the mount point until one renders.
    if (host.parentNode !== app) {
      app.prepend(host);
    }
  }

  collect();
  place();

  let queued = false;
  const observer = new MutationObserver(() => {
    if (queued) return;
    queued = true;
    // One pass per frame: a route change fires a burst of mutations.
    requestAnimationFrame(() => {
      queued = false;
      collect();
      place();
    });
  });
  observer.observe(body, { childList: true, subtree: true });
}
