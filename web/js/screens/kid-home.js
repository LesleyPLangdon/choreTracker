import { api, session } from '../api.js';
import { h, mount, errorBox, showError, clearError, balanceText, formatClock, formatDay } from '../ui.js';
import { choreTitle, choreTags } from '../chore-parts.js';

/** The kid's day: lock status, today's chores, Done / Undo, what they've earned, and their Spending balance. */
export async function render(root) {
  const error = errorBox();
  const header = h('div', { class: 'center' });
  const noticeBox = h('div', {});
  const statusBox = h('div', { class: 'card daybox' });
  const moneyBox = h('div', { class: 'card' });
  const listBox = h('div', { class: 'card' });
  const refreshButton = h('button', { class: 'secondary small', onclick: () => load() }, '↻ Refresh');

  async function act(id, action) {
    clearError(error);
    try {
      await api('POST', `/kid/instances/${id}/${action}`, {}, { as: 'kid' });
    } catch (err) {
      showError(error, err);
    }
    try { await load(); } catch (err) { showError(error, err); }
  }

  async function load() {
    const data = await api('GET', '/kid/today', undefined, { as: 'kid' });
    mount(header,
      h('div', { class: 'avatar big' }, data.kid.avatar),
      h('h1', {}, `Hi, ${data.kid.nickname}!`));
    paintNotices(data);
    paintStatus(data);
    mount(moneyBox,
      h('div', { class: 'balance' }, h('span', {}, 'Earned today'), h('strong', {}, balanceText(data.earned_today))),
      h('div', { class: 'balance' }, h('span', {}, 'Spending'), h('strong', {}, balanceText(data.spending))));
    paintList(data);
  }

  function dayName(date, today) {
    const yesterday = new Date(today + 'T12:00:00');
    yesterday.setDate(yesterday.getDate() - 1);
    const y = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`;
    return date === y ? 'Yesterday' : formatDay(date);
  }

  // Short, neutral notes about earlier days. Informational, not scolding.
  function paintNotices(data) {
    mount(noticeBox, data.notices.map((n) => n.type === 'forfeited'
      ? h('div', { class: 'notice kid' },
          h('span', {}, `${dayName(n.date, data.today)}'s earnings didn't unlock. Today is a fresh start!`),
          h('button', { class: 'small secondary', onclick: async () => { await api('POST', `/kid/days/${n.date}/ack`, {}, { as: 'kid' }); await load(); } }, 'OK'))
      : h('div', { class: 'notice' }, `${dayName(n.date, data.today)}'s earnings are waiting for a parent to check.`)));
  }

  function paintStatus(data) {
    const { day } = data;
    const locked = balanceText(day.locked);
    statusBox.className = `card daybox ${day.status}`;
    if (day.status === 'unlocked') {
      mount(statusBox, h('div', { class: 'state' }, '🔓 Today\'s earnings are unlocked!'));
    } else if (day.status === 'waiting') {
      mount(statusBox, h('div', { class: 'state' }, '⏳ Waiting for a parent to check.'),
        locked !== '0' ? h('div', { class: 'locked-amount' }, `🔒 ${locked} locked`) : null);
    } else {
      mount(statusBox,
        h('div', { class: 'state' }, '🔒 Finish these to unlock today\'s earnings:'),
        h('ul', {}, day.required_left.map((r) => h('li', {},
          r.title, r.slot_label ? ` — ${r.slot_label}` : '', r.opens_at ? `, opens at ${formatClock(r.opens_at)}` : ''))),
        locked !== '0' ? h('div', { class: 'locked-amount' }, `🔒 ${locked} locked`) : null);
    }
  }

  function paintList(data) {
    const row = (c) => {
      const body = choreTitle(c, data.today);
      body.append(choreTags(c, true));
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
    header, noticeBox, error, statusBox, moneyBox, listBox,
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
