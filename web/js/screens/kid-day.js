import { api } from '../api.js';
import { h, mount, errorBox, showError, clearError, balanceText } from '../ui.js';
import { parentShell } from '../layout.js';
import { approvalButtons, choreTitle, choreTags } from '../chore-parts.js';

/** A parent's view of one kid's day: statuses, Mark done, and today's earnings. */
export async function render(root, kidId) {
  const error = errorBox();
  const header = h('div', {});
  const statusBox = h('div', { class: 'card daybox' });
  const earnedBox = h('div', { class: 'card' });
  const listBox = h('div', { class: 'card' });
  const refreshButton = h('button', { class: 'secondary small', onclick: () => load() }, 'Refresh');

  async function load() {
    clearError(error);
    const data = await api('GET', `/kids/${kidId}/today`, undefined, { as: 'parent' });
    mount(header, 
      h('div', { class: 'row', style: 'margin-bottom:10px' },
        h('div', { class: 'avatar' }, data.kid.avatar),
        h('div', { class: 'grow' }, h('h1', { style: 'margin:0' }, data.kid.nickname), h('div', { class: 'muted' }, 'Today, ' + data.today)),
        refreshButton));
    const locked = balanceText(data.day.locked);
    statusBox.className = `card daybox ${data.day.status}`;
    mount(statusBox,
      h('div', { class: 'state' }, { unlocked: '🔓 Unlocked', waiting: '⏳ Waiting for a parent to check', locked: '🔒 Locked' }[data.day.status]),
      data.day.status === 'locked' && data.day.required_left.length
        ? h('div', { class: 'muted' }, 'Still needed: ' + data.day.required_left.map((r) => r.title + (r.slot_label ? ` (${r.slot_label})` : '')).join(', '))
        : null,
      locked !== '0' ? h('div', { class: 'locked-amount' }, `🔒 ${locked} locked`) : null);
    mount(earnedBox,
      h('div', { class: 'balance' }, h('span', {}, 'Earned today'), h('strong', {}, balanceText(data.earned_today))),
      h('div', { class: 'balance' }, h('span', {}, 'Spending'), h('strong', {}, balanceText(data.spending))),
      h('p', { style: 'margin:8px 0 0' }, h('a', { class: 'btn secondary small', href: `#/history/${kidId}` }, 'History, back-fill & fixes')));
    paint(data);
  }

  function paint(data) {
    const rows = data.chores;
    const section = (title, items) => items.length ? [h('div', { class: 'section-title' }, title), ...items] : [];
    const row = (c) => {
      let actions = [];
      if (c.status === 'reported_done') {
        actions = approvalButtons(c.id, data.kid.nickname, load, (e) => showError(error, e));
      } else if (c.status === 'assigned' || c.status === 'sent_back') {
        actions = [h('button', {
          class: 'small',
          onclick: async (event) => {
            event.currentTarget.disabled = true;
            try { await api('POST', `/instances/${c.id}/mark-done`, {}, { as: 'parent' }); await load(); } catch (err) { showError(error, err); event.target.disabled = false; }
          },
        }, 'Mark done')];
      }
      const title = choreTitle(c, data.today);
      title.append(choreTags(c), c.send_back_note ? h('div', { class: 'note' }, `“${c.send_back_note}”`) : null);
      return h('div', { class: 'chore' + (c.status === 'approved' ? ' dim' : '') }, title, h('div', { class: 'actions' }, actions));
    };
    const todo = rows.filter((c) => c.status === 'assigned' || c.status === 'sent_back');
    const waiting = rows.filter((c) => c.status === 'reported_done');
    const done = rows.filter((c) => c.status === 'approved');
    mount(listBox, 
      rows.length ? [...section('To do', todo.map(row)), ...section('Waiting for approval', waiting.map(row)), ...section('Approved', done.map(row))]
        : h('p', { class: 'muted', style: 'margin:0' }, 'No chores today.'));
    if (listBox.firstChild?.className === 'section-title') listBox.firstChild.style.marginTop = '0';
  }

  mount(root, ...parentShell('/home',
    h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/home' }, '‹ Back')),
    header, error, statusBox, earnedBox, listBox));
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
