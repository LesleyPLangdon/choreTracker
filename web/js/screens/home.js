import { api } from '../api.js';
import { h, mount, errorBox, showError, clearError, formatValue, formatLocalTime } from '../ui.js';
import { parentShell } from '../layout.js';
import { approvalButtons } from '../chore-parts.js';

export async function render(root) {
  const me = await api('GET', '/me', undefined, { as: 'parent' });
  const error = errorBox();
  const approvalsBox = h('div', { class: 'card' });
  const kidsBox = h('div', { class: 'card' });
  const refreshButton = h('button', { class: 'secondary small', onclick: () => load() }, 'Refresh');

  async function load() {
    clearError(error);
    const [approvals, kids] = await Promise.all([
      api('GET', '/approvals', undefined, { as: 'parent' }),
      api('GET', '/kids', undefined, { as: 'parent' }),
    ]);
    paintApprovals(approvals);
    paintKids(kids.kids.filter((k) => k.active));
  }

  function paintApprovals(data) {
    mount(approvalsBox, 
      h('div', { class: 'top-actions' }, h('h2', { style: 'margin:0' }, 'Needs your approval'), refreshButton),
      data.items.length
        ? data.items.map((item) => h('div', { class: 'chore' },
            h('div', { class: 'avatar', style: 'width:40px;height:40px;font-size:1.4rem' }, item.kid.avatar),
            h('div', { class: 'grow' },
              h('div', { class: 'title' }, item.title, item.slot_label ? h('span', { class: 'muted' }, ` · ${item.slot_label}`) : null),
              h('div', { class: 'muted' },
                `${item.kid.nickname} · ${formatLocalTime(item.reported_local)}`, item.value ? ` · ${formatValue(item.value, item.currency)}` : '',
                item.is_late ? h('span', { class: 'tag late', style: 'margin-left:6px' }, 'Late') : null)),
            h('div', { class: 'actions' }, approvalButtons(item.id, item.kid.nickname, load, (e) => showError(error, e)))))
        : h('p', { class: 'muted', style: 'margin:0' }, 'Nothing waiting. 🎉'));
  }

  function paintKids(kids) {
    mount(kidsBox, 
      h('h2', {}, 'Kids'),
      kids.length
        ? kids.map((k) => h('a', { class: 'chore', href: `#/day/${k.id}`, style: 'text-decoration:none;color:inherit' },
            h('div', { class: 'avatar', style: 'width:40px;height:40px;font-size:1.4rem' }, k.avatar),
            h('div', { class: 'grow title' }, k.nickname),
            k.locked ? h('span', { class: 'badge warn' }, 'PIN locked') : null,
            h('span', { class: 'muted' }, 'Today ›')))
        : [h('p', { class: 'muted' }, 'No kids yet.'), h('a', { class: 'btn secondary small', href: '#/kids' }, 'Add a kid')],
      kids.length ? h('p', { class: 'hint', style: 'margin:10px 0 0' }, 'Tap a kid to see their day or mark a chore done.') : null);
  }

  mount(root, ...parentShell('/home',
    h('h1', {}, me.household.name),
    h('p', { class: 'muted' }, `Hi ${me.parent.display_name}!`),
    error, approvalsBox, kidsBox,
    h('p', { class: 'center' }, h('a', { class: 'btn secondary small', href: '#/chores' }, 'Manage chores'))));
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
