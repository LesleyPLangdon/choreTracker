import { api } from '../api.js';
import { h, mount, errorBox, showError, clearError, earnedText } from '../ui.js';
import { parentShell } from '../layout.js';
import { approvalButtons, choreTitle } from '../chore-parts.js';

/** A parent's view of one kid's day: statuses, Mark done, and today's earnings. */
export async function render(root, kidId) {
  const error = errorBox();
  const header = h('div', {});
  const earnedBox = h('div', { class: 'card earned' });
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
    mount(earnedBox, h('span', {}, 'Earned today'), h('strong', {}, earnedText(data.earned_today)));
    paint(data);
  }

  function paint(data) {
    const rows = data.chores;
    const section = (title, items) => items.length ? [h('div', { class: 'section-title' }, title), ...items] : [];
    const row = (c) => {
      const tags = [];
      if (c.status === 'reported_done') tags.push(h('span', { class: 'tag' }, 'Waiting for approval'));
      if (c.status === 'approved') tags.push(h('span', { class: 'tag ok' }, 'Approved'));
      if (c.status === 'sent_back') tags.push(h('span', { class: 'tag bad' }, 'Sent back'));
      if (c.is_late || c.past_due_by) tags.push(h('span', { class: 'tag late' }, 'Late'));
      if (c.past_due_date) tags.push(h('span', { class: 'tag late' }, 'Past due date'));
      if (c.opens_at) tags.push(h('span', { class: 'tag' }, 'Not open yet'));

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
      title.append(h('div', {}, tags), c.send_back_note ? h('div', { class: 'note' }, `“${c.send_back_note}”`) : null);
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
    header, error, earnedBox, listBox));
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
