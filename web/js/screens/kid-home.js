import { api, session } from '../api.js';
import { h, mount, errorBox, showError, clearError, earnedText, formatClock } from '../ui.js';
import { choreTitle } from '../chore-parts.js';

/** The kid's day: today's chores, Done / Undo, and what they've earned today. */
export async function render(root) {
  const error = errorBox();
  const header = h('div', { class: 'center' });
  const earnedBox = h('div', { class: 'card earned' });
  const listBox = h('div', { class: 'card' });
  const refreshButton = h('button', { class: 'secondary small', onclick: () => load() }, '↻ Refresh');

  async function act(id, action) {
    clearError(error);
    try {
      await api('POST', `/kid/instances/${id}/${action}`, {}, { as: 'kid' });
    } catch (err) {
      showError(error, err);
    }
    await load();
  }

  async function load() {
    const data = await api('GET', '/kid/today', undefined, { as: 'kid' });
    mount(header, 
      h('div', { class: 'avatar big' }, data.kid.avatar),
      h('h1', {}, `Hi, ${data.kid.nickname}!`));
    mount(earnedBox, h('span', {}, 'Earned today'), h('strong', {}, earnedText(data.earned_today)));
    paint(data);
  }

  function paint(data) {
    const row = (c) => {
      const body = choreTitle(c, data.today);
      const tags = [];
      if (c.status === 'reported_done') tags.push(h('span', { class: 'tag' }, 'Waiting for a parent'));
      if (c.status === 'approved') tags.push(h('span', { class: 'tag ok' }, 'Approved ✓'));
      if (c.is_late || c.past_due_by) tags.push(h('span', { class: 'tag late' }, 'Late'));
      if (c.past_due_date) tags.push(h('span', { class: 'tag late' }, 'Past due date'));
      if (c.opens_at) tags.push(h('span', { class: 'tag' }, `Opens at ${formatClock(c.opens_at)}`));
      body.append(h('div', {}, tags));
      if (c.send_back_note) body.append(h('div', { class: 'note' }, `Sent back: “${c.send_back_note}”`));
      else if (c.status === 'sent_back') body.append(h('div', { class: 'note' }, 'Sent back — please try again.'));

      let button = null;
      if (c.can_report) button = h('button', { class: 'success', onclick: (e) => { e.currentTarget.disabled = true; act(c.id, 'done'); } }, 'Done');
      else if (c.status === 'reported_done') button = h('button', { class: 'secondary small', onclick: (e) => { e.currentTarget.disabled = true; act(c.id, 'undo'); } }, 'Undo');
      return h('div', { class: 'chore' + (c.opens_at ? ' dim' : '') }, body, button ? h('div', { class: 'actions' }, button) : null);
    };
    const todo = data.chores.filter((c) => c.status === 'assigned' || c.status === 'sent_back');
    const waiting = data.chores.filter((c) => c.status === 'reported_done');
    const done = data.chores.filter((c) => c.status === 'approved');
    const section = (title, items) => items.length ? [h('div', { class: 'section-title' }, title), ...items.map(row)] : [];
    mount(listBox, 
      data.chores.length
        ? [...section('To do', todo), ...section('Waiting for a parent', waiting), ...section('Done', done)]
        : h('p', { class: 'muted', style: 'margin:0' }, 'No chores today. Enjoy! 🎉'));
    if (listBox.firstChild?.className === 'section-title') listBox.firstChild.style.marginTop = '0';
    if (todo.length === 0 && data.chores.length > 0) {
      listBox.prepend(h('p', { class: 'center', style: 'margin:0 0 4px' }, 'All done for now! 🎉'));
    }
  }

  mount(root,
    h('div', { class: 'top-actions' }, h('span', {}), refreshButton),
    header, error, earnedBox, listBox,
    h('p', { class: 'center' }, h('button', {
      class: 'link',
      onclick: async () => {
        try { await api('POST', '/kid/logout', {}, { as: 'kid' }); } catch { /* already signed out */ }
        session.setKidToken(null);
        location.hash = session.kidLink() ? '#/k/' + session.kidLink() : '#/login';
      },
    }, 'Not you? Sign out')));
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
