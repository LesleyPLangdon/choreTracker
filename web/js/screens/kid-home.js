import { api, session } from '../api.js';
import { h, mount } from '../ui.js';

/** Placeholder kid home. Chores arrive in Phase 2. */
export async function render(root) {
  const me = await api('GET', '/kid/me', undefined, { as: 'kid' });
  mount(root, h('div', { class: 'center' },
    h('div', { class: 'avatar big' }, me.kid.avatar),
    h('h1', {}, `Hi, ${me.kid.nickname}!`),
    h('div', { class: 'card' }, h('p', {}, 'Your chores will show up here soon.')),
    h('button', {
      class: 'link',
      onclick: async () => {
        try { await api('POST', '/kid/logout', {}, { as: 'kid' }); } catch { /* already signed out */ }
        session.setKidToken(null);
        location.hash = session.kidLink() ? '#/k/' + session.kidLink() : '#/login';
      },
    }, 'Not you? Sign out')));
}
