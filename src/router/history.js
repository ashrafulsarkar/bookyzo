/**
 * A Vue Router history for an SPA that lives inside wp-admin, where each
 * of the app's top-level screens is a real WordPress admin page with its
 * own slug (`admin.php?page=bookyzo-bookings`) — see BYZO_Plugin::routes().
 *
 * Why not the stock histories: `createWebHistory` can't be used at all
 * here, because it would rewrite the path away from `admin.php`, which
 * WordPress needs intact to route the request to the page callback (there
 * is no wp-admin hook for serving arbitrary paths). `createWebHashHistory`
 * works but leaves a visible `#/` on every URL, and — the real problem —
 * hides the current screen from WordPress entirely, so the left-nav
 * submenu can never highlight the row the user is actually on.
 *
 * So: navigation rewrites the `page` parameter. Routes that map to a
 * registered admin page (the sidebar's screens) get that page's real
 * slug, which keeps WP's own menu highlighting correct and every URL
 * bookmarkable. Routes with no admin page of their own (`/setup`,
 * `/resources/new`, `/resources/:id/edit`) are reached from inside the
 * app and ride along on their section's slug via a `view` parameter.
 *
 * PHP hands over the boot route in `window.byzoAdmin.initialRoute` rather
 * than this file re-deriving it from the URL, so the slug→route map lives
 * in one authoritative place (PHP) — this file only needs the inverse
 * direction, for building outgoing URLs.
 */

const VIEW_PARAM = 'view';
const PAGE_PARAM = 'page';

/**
 * Route → admin page slug, for the routes that have a page of their own.
 * The inverse of BYZO_Plugin::routes(); keep the two in sync.
 */
const SLUG_FOR_ROUTE = {
  '/': 'bookyzo',
  '/calendar': 'bookyzo-calendar',
  '/bookings': 'bookyzo-bookings',
  '/resources': 'bookyzo-resources',
  '/settings/email-templates': 'bookyzo-emails',
  '/settings/payments': 'bookyzo-payments',
};

/**
 * The admin page a route should live under. A sub-route with no page of
 * its own stays on its section's page — so `/resources/new` keeps the
 * Resources row highlighted — by matching the longest route prefix that
 * does have one. Anything unmatched falls back to the top-level page.
 */
function slugFor(route) {
  if (SLUG_FOR_ROUTE[route]) {
    return SLUG_FOR_ROUTE[route];
  }

  const section = Object.keys(SLUG_FOR_ROUTE)
    .filter((path) => path !== '/' && route.startsWith(path + '/'))
    .sort((a, b) => b.length - a.length)[0];

  return section ? SLUG_FOR_ROUTE[section] : SLUG_FOR_ROUTE['/'];
}

function currentSearch() {
  return new URLSearchParams(window.location.search);
}

/**
 * Builds the browser URL for a router location, carrying over every other
 * query parameter already present. A route that owns an admin page needs
 * no `view`; one that doesn't gets it, so a reload or a shared link
 * returns to the same screen instead of its section's landing page.
 */
function urlFor(to) {
  const search = currentSearch();

  search.set(PAGE_PARAM, slugFor(to));

  if (SLUG_FOR_ROUTE[to]) {
    search.delete(VIEW_PARAM);
  } else {
    search.set(VIEW_PARAM, to);
  }

  return window.location.pathname + '?' + search.toString() + window.location.hash;
}

/**
 * The route for the URL as it currently stands. `view` wins when present
 * (that's a sub-route riding on its section's page); otherwise PHP's
 * initialRoute is authoritative, since it resolved the same slug map from
 * the server side. Falls back to the root route if the app is somehow
 * loaded with neither.
 */
function locationFromUrl() {
  const view = currentSearch().get(VIEW_PARAM);

  if (view && view.startsWith('/')) {
    return view;
  }

  const initial = window.byzoAdmin && window.byzoAdmin.initialRoute;

  return typeof initial === 'string' && initial.startsWith('/') ? initial : '/';
}

/**
 * Moves wp-admin's left-nav highlight to the row for `route`.
 *
 * Navigating rewrites the `page` parameter without a page load, so the
 * highlight WordPress rendered server-side would otherwise keep pointing
 * at whichever screen the user first landed on.
 *
 * Everything here is scoped to Bookyzo's own top-level menu item, never
 * the whole `#adminmenu`: clearing `.current` globally would strip the
 * state off other plugins' rows too, and since wp-admin only ever shows
 * one section as open, the visible result was another plugin's submenu
 * (Elementor's, whichever sits nearest) left standing open while Bookyzo
 * was the page actually being viewed.
 *
 * Matching is on the FULL href, not a substring: `page=bookyzo` is a
 * prefix of `page=bookyzo-calendar` and friends, so `[href*=…]` matched
 * the wrong row for the dashboard.
 *
 * Purely cosmetic — every branch bails if the markup isn't what's
 * expected, and navigation is unaffected either way.
 */
function syncMenuHighlight(route) {
  const menu = document.querySelector('#adminmenu');

  if (!menu) {
    return;
  }

  // Bookyzo's own top-level <li>, found via the top-level link. Anything
  // outside it belongs to another plugin and is left strictly alone.
  const rootLink = menu.querySelector('a[href$="admin.php?page=' + SLUG_FOR_ROUTE['/'] + '"]');
  const root = rootLink && rootLink.closest('#adminmenu > li');

  if (!root) {
    return;
  }

  // Search inside the submenu <ul> only, NOT `root` itself: the
  // top-level link's own href is `…page=bookyzo`, the exact same string
  // the dashboard route ends up matching, and it sits earlier in the DOM
  // than the "Dashboard" submenu row. A search scoped to `root` picked
  // that top-level link instead, marked IT current, and the dashboard
  // submenu row — never touched — lost the `current` WordPress had
  // already rendered onto it when the `.current` sweep below ran.
  const submenu = root.querySelector('ul.wp-submenu');

  if (!submenu) {
    return;
  }

  const target = submenu.querySelector('a[href$="admin.php?page=' + slugFor(route) + '"]');

  if (!target) {
    return;
  }

  // Clear only within our own submenu, then mark the active row. WP also
  // sets aria-current="page" on the active link (screen-reader affordance,
  // separate from the `current` class) — kept in sync the same way.
  submenu.querySelectorAll('.current').forEach((el) => el.classList.remove('current'));
  submenu.querySelectorAll('a[aria-current="page"]').forEach((el) => el.removeAttribute('aria-current'));

  target.classList.add('current');
  target.setAttribute('aria-current', 'page');

  const row = target.closest('li');

  if (row) {
    row.classList.add('current');
  }

  // WordPress renders the open section with these on the top-level <li>.
  // They are already correct on a normal page load (WP is serving one of
  // our pages either way); set them defensively in case a previous
  // navigation cleared them.
  root.classList.add('wp-has-current-submenu', 'wp-menu-open');
  root.classList.remove('wp-not-current-submenu');
}

export function createWpAdminHistory() {
  let location = locationFromUrl();
  const listeners = [];

  // Vue Router asks for the scroll position to restore on a popstate;
  // history.state is where it stashes it, so seed the current entry with
  // the shape it expects rather than leaving it null.
  if (!window.history.state) {
    window.history.replaceState({ back: null, current: location, forward: null, position: 0, replaced: true, scroll: null }, '');
  }

  // The URL WordPress served may not match the route the app is opening
  // on — a sub-route reached by deep link (`?page=bookyzo-resources&view=/resources/new`)
  // is the normal case. Sync the highlight once on boot so it starts
  // consistent with what's actually rendered.
  syncMenuHighlight(location);

  function notify(to, from, isPop) {
    listeners.forEach((callback) => {
      callback(to, from, {
        // `pop` covers back AND forward; `direction` is what tells them
        // apart, and Vue Router only uses it for scroll behaviour.
        type: isPop ? 'pop' : 'push',
        direction: '',
      });
    });
  }

  function navigate(to, replace) {
    const from = location;
    const state = { back: replace ? (window.history.state && window.history.state.back) || null : from, current: to, forward: null, replaced: replace, position: window.history.length, scroll: null };

    window.history[replace ? 'replaceState' : 'pushState'](state, '', urlFor(to));
    location = to;
    syncMenuHighlight(to);

    // Push/replace are driven BY the router, which already knows where
    // it's going — notifying here would double up the navigation.
    return from;
  }

  window.addEventListener('popstate', () => {
    const from = location;
    location = locationFromUrl();

    if (location !== from) {
      syncMenuHighlight(location);
      notify(location, from, true);
    }
  });

  return {
    // Vue Router reads these as live values, so they're getters rather
    // than a snapshot taken at creation time.
    get location() {
      return location;
    },
    get state() {
      return window.history.state;
    },
    base: '',

    push(to, data) {
      navigate(to, false);
      if (data) {
        window.history.replaceState({ ...window.history.state, ...data }, '');
      }
    },

    replace(to, data) {
      navigate(to, true);
      if (data) {
        window.history.replaceState({ ...window.history.state, ...data }, '');
      }
    },

    go(delta) {
      window.history.go(delta);
    },

    listen(callback) {
      listeners.push(callback);

      return () => {
        const index = listeners.indexOf(callback);
        if (index > -1) {
          listeners.splice(index, 1);
        }
      };
    },

    createHref(to) {
      return urlFor(to);
    },

    destroy() {
      listeners.length = 0;
    },
  };
}
