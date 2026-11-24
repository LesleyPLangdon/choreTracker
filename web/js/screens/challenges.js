import { api } from '../api.js';
import { h, mount, errorBox, showError, toast, formatClock, formatSqlMoment, describeDays, DAY_NAMES } from '../ui.js';
import { parentShell } from '../layout.js';
import { refreshBadge } from '../layout.js';

const parent = { as: 'parent' };
const STATUS = { in_progress: ['In progress', ''], earned: ['Earned', 'ok'], missed: ['Missed', 'warn'], delivered: ['Delivered', 'ok'] };

/** Challenges: rewards earned by doing chores a number of times inside a time window. */
export async function render(root) {
  const error = errorBox();
  const body = h('div', {});

  async function load() {
    const { challenges } = await api('GET', '/challenges', undefined, parent);
    mount(body, challenges.length ? challenges.map(card) : h('div', { class: 'card' }, h('p', { class: 'muted', style: 'margin:0' }, 'No challenges yet. Try "Walk the dog 7 times by Friday 5:00 PM → open gym".')));
  }
  const attempt = (fn, message) => async () => { try { await fn(); toast(message); await load(); refreshBadge(); } catch (err) { showError(error, err); } };

  function windowText(c) {
    const day = (n) => DAY_NAMES[n - 1];
    if (c.repeat_type === 'once') return `One time: ${formatSqlMoment(c.once_start.replace('T', ' ') + ':00')} to ${formatSqlMoment(c.once_end.replace('T', ' ') + ':00')}`;
    if (c.repeat_type === 'daily') return `${describeDays(c.days)}, ${formatClock(c.start_time)} to ${formatClock(c.end_time)}`;
    return `Weekly: ${day(c.start_weekday)} ${formatClock(c.start_time)} to ${day(c.end_weekday)} ${formatClock(c.end_time)}`;
  }

  function card(c) {
    return h('div', { class: 'card' },
      h('div', { class: 'row' },
        h('div', { class: 'avatar', style: 'width:40px;height:40px;font-size:1.4rem' }, c.kid.avatar),
        h('div', { class: 'grow' }, h('div', { style: 'font-weight:700' }, c.reward_name),
          h('div', { class: 'muted' }, `${c.kid.nickname}: ${c.target_count} × ${c.chores.join(' + ') || 'chores'}`), h('div', { class: 'muted' }, windowText(c))),
        h('button', { class: 'danger small', onclick: async () => {
          if (!confirm(`Remove the challenge "${c.reward_name}"?`)) return;
          await attempt(() => api('DELETE', `/challenges/${c.id}`, undefined, parent), 'Removed')();
        } }, 'Remove')),
      c.periods.length ? h('div', { class: 'section-title' }, 'Recent windows') : null,
      c.periods.map((p) => h('div', { class: 'chore' },
        h('div', { class: 'grow' },
          h('div', {}, `${formatSqlMoment(p.start_local)} → ${formatSqlMoment(p.end_local)}`),
          h('div', { class: 'muted' }, `${p.count} of ${p.target_count}`), h('span', { class: `tag ${STATUS[p.status][1]}` }, STATUS[p.status][0])),
        h('div', { class: 'actions' },
          p.status === 'earned' ? h('button', { class: 'success small', onclick: attempt(() => api('POST', `/challenge-periods/${p.id}/delivered`, {}, parent), 'Delivered') }, 'Delivered') : null,
          ['in_progress', 'missed'].includes(p.status) ? h('button', { class: 'secondary small', onclick: attempt(() => api('POST', `/challenge-periods/${p.id}/earned`, {}, parent), 'Marked earned') }, 'Mark earned') : null,
          ['in_progress', 'earned'].includes(p.status) ? h('button', { class: 'secondary small', onclick: attempt(() => api('POST', `/challenge-periods/${p.id}/missed`, {}, parent), 'Marked missed') }, 'Mark missed') : null))));
  }

  mount(root, ...parentShell('/challenges',
    h('div', { class: 'top-actions' }, h('h1', { style: 'margin:0' }, 'Challenges'), h('a', { class: 'btn small', href: '#/challenges/new' }, '+ Add challenge')),
    h('p', { class: 'muted' }, 'A reward earned by doing chores enough times inside a window. It costs nothing. A chore counts if the kid reported it inside the window and you approved it (even after the window closed).'),
    error, body));
  try { await load(); } catch (err) { showError(error, err); }
}
