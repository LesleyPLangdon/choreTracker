import { api } from '../api.js';
import { h, mount, formatValue, formatClock, formatDue, describeDays, errorBox, showError } from '../ui.js';
import { parentShell } from '../layout.js';

/** The list of chores, with Add and Edit. */
export async function render(root) {
  const error = errorBox();
  const body = h('div', {});
  const me = await api('GET', '/me', undefined, { as: 'parent' });

  async function load() {
    const data = await api('GET', '/chores', undefined, { as: 'parent' });
    body.replaceChildren(...(data.chores.length
      ? data.chores.map((c) => card(c, me.household.today))
      : [h('div', { class: 'card' }, h('p', { class: 'muted', style: 'margin:0' }, 'No chores yet. Add the first one!'))]));
  }

  mount(root, ...parentShell('/chores',
    h('div', { class: 'top-actions' }, h('h1', { style: 'margin:0' }, 'Chores'), h('a', { class: 'btn small', href: '#/chores/new' }, '+ Add chore')),
    error, body));
  try { await load(); } catch (err) { showError(error, err); }
}

function card(chore, today) {
  const schedule = chore.schedule_type === 'daily'
    ? describeDays(chore.days)
    : 'One time' + (chore.due_date ? ' · ' + formatDue(chore.due_date, today) : '');
  const slots = chore.slots.length > 1 || chore.slots.some((s) => s.available_from || s.due_by)
    ? chore.slots.map((s) => [s.label, s.available_from ? `from ${formatClock(s.available_from)}` : null, s.due_by ? `by ${formatClock(s.due_by)}` : null].filter(Boolean).join(' '))
    : [];
  return h('div', { class: 'card' },
    h('div', { class: 'row' },
      h('div', { class: 'grow' },
        h('div', { class: 'title', style: 'font-weight:700' }, chore.title),
        h('div', { class: 'muted' }, [formatValue(chore.value, chore.currency) || 'No value', schedule].join(' · ')),
        slots.length ? h('div', { class: 'muted' }, slots.join(' | ')) : null,
        chore.auto_approve ? h('div', {}, h('span', { class: 'tag' }, 'Auto-approve')) : null),
      h('a', { class: 'btn secondary small', href: `#/chores/edit/${chore.id}` }, 'Edit')),
    h('div', { class: 'row', style: 'margin-top:8px' }, chore.kids.map((k) =>
      h('span', { class: 'tag' }, `${k.avatar} ${k.nickname}`))));
}
