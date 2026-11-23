import { api } from '../api.js';
import { h, mount } from '../ui.js';
import { parentShell } from '../layout.js';

export async function render(root) {
  const [me, kids] = await Promise.all([api('GET', '/me', undefined, { as: 'parent' }), api('GET', '/kids', undefined, { as: 'parent' })]);
  const active = kids.kids.filter((k) => k.active);

  mount(root, ...parentShell('/home',
    h('h1', {}, me.household.name),
    h('p', { class: 'muted' }, `Hi ${me.parent.display_name}! Today is ${me.household.today}.`),
    h('div', { class: 'card' },
      h('h2', {}, 'Kids'),
      active.length
        ? active.map((k) => h('div', { class: 'row', style: 'margin-bottom:8px' },
            h('div', { class: 'avatar' }, k.avatar), h('div', { class: 'grow' }, k.nickname),
            k.locked ? h('span', { class: 'badge warn' }, 'Locked') : null))
        : h('p', { class: 'muted' }, 'No kids yet.'),
      h('a', { class: 'btn secondary small', href: '#/kids' }, active.length ? 'Manage kids' : 'Add a kid')),
    h('div', { class: 'card' },
      h('h2', {}, 'Chores'),
      h('p', { class: 'muted' }, 'Creating chores and approving them is coming in the next phase.'))));
}
