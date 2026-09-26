// WordPress's own left menu is pinned and scrolls on its own on Bookyzo's
// screens (see the #adminmenuwrap rules in styles/tailwind.css). A scroll
// container clips anything that pokes out of it, so the other menus' hover
// flyouts are `position: fixed` there, and fixed needs viewport
// coordinates. They're measured from the menu item as the pointer or focus
// reaches it and handed to the CSS as custom properties on that <li>, which
// its .wp-submenu inherits. wp-admin's own adjustSubmenu() still lifts a
// flyout that would run past the fold (margin-top), and that works the
// same on a fixed element.
export function installWpMenuPin() {
  const menu = document.getElementById('adminmenu');
  if (!menu) return;

  const place = (event) => {
    const item = event.target.closest?.('li.menu-top');
    if (!item || !menu.contains(item)) return;
    const rect = item.getBoundingClientRect();
    // wp-admin opens the flyout 1px above the item unfolded, flush folded.
    const offset = document.body.classList.contains('folded') ? 0 : -1;
    item.style.setProperty('--byzo-flyout-top', `${rect.top + offset}px`);
    item.style.setProperty('--byzo-flyout-left', `${rect.right}px`);
  };

  menu.addEventListener('mouseover', place);
  menu.addEventListener('focusin', place);
}
