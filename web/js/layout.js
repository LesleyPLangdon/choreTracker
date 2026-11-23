import { api, session } from './api.js';
import { h } from './ui.js';

/** The frame around every signed-in parent screen: title bar + navigation. */
export function parentShell(active, ...content) {
  const link = (path, label) => h('a', { href: '#' + path, class: active === path ? 'active' : '' }, label);
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
    h('nav', { class: 'nav' }, link('/home', 'Home'), link('/kids', 'Kids'), link('/settings', 'Settings')),
    ...content,
  ];
}
