import { api, session } from './api.js';
import { h } from './ui.js';

/** The frame around every signed-in parent screen: title bar + navigation (with the approvals badge). */
export function parentShell(active, ...content) {
  const link = (path, label, extra) => h('a', { href: '#' + path, class: active === path ? 'active' : '' }, label, extra);
  const nav = h('nav', { class: 'nav' },
    link('/home', 'Home', h('span', { class: 'count', id: 'approval-badge', hidden: true })),
    link('/chores', 'Chores'), link('/kids', 'Kids'), link('/rewards', 'Rewards'), link('/challenges', 'Challenges'), link('/settings', 'Settings'));
  refreshBadge();
  return [
    h('div', { class: 'topbar' },
      h('span', { class: 'brand' }, 'Chore Tracker'),
      h('button', {
        class: 'link',
        onclick: async () => {
          try { await api('POST', '/logout', {}, { as: 'parent' }); } catch { /* already signed out */ }
          session.setParentToken(null);
          location.hash = '#/login';
        },
      }, 'Sign out')),
    nav,
    ...content,
  ];
}

/** Updates the "needs your approval" count on the Home tab. Safe to call any time. */
export async function refreshBadge() {
  if (!session.parentToken()) return;
  try {
    const { count } = await api('GET', '/approvals/count', undefined, { as: 'parent' });
    // The nav may not be on the page yet when this first runs; look again next tick.
    setTimeout(() => {
      const badge = document.getElementById('approval-badge');
      if (!badge) return;
      badge.textContent = String(count);
      badge.hidden = count === 0;
    }, 0);
  } catch { /* the screen itself will show real errors */ }
}
