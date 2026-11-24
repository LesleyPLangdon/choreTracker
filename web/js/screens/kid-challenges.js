import { api } from '../api.js';
import { h, mount, errorBox, showError, progress, formatWhen } from '../ui.js';

/** Each active challenge: progress, deadline, and the reward. */
export async function render(root) {
  const error = errorBox();
  const body = h('div', {});

  async function load() {
    const { challenges } = await api('GET', '/kid/challenges', undefined, { as: 'kid' });
    mount(body, challenges.length ? challenges.map(card) : h('div', { class: 'card' }, h('p', { class: 'muted', style: 'margin:0' }, 'No challenges right now.')));
  }

  function card(c) {
    const p = c.period;
    const open = p && p.open && p.status === 'in_progress';
    return h('div', { class: 'card' },
      h('div', { class: 'name', style: 'font-weight:800;font-size:1.1rem' }, `🏆 ${c.reward_name}`),
      c.reward_description ? h('div', { class: 'muted' }, c.reward_description) : null,
      c.earned.length ? h('div', { class: 'notice', style: 'margin:8px 0' }, h('strong', {}, 'Earned! Ask a parent.')) : null,
      p ? [
        h('div', { class: 'row-between', style: 'margin-top:8px' },
          h('span', {}, `${p.count} of ${c.target_count}${c.chores.length ? ' · ' + c.chores.join(', ') : ''}`),
          p.status === 'earned' ? h('span', { class: 'tag ok' }, 'Earned!') : null),
        progress(p.count, c.target_count),
        open ? h('div', { class: 'muted' }, `by ${formatWhen(p.end_local)}`) : null,
        !open && p.status === 'in_progress' ? h('div', { class: 'muted' }, 'This one has ended. A parent is checking it.') : null,
      ] : h('div', { class: 'muted', style: 'margin-top:6px' }, `${c.target_count} × ${c.chores.join(', ')}`),
      !open && c.next_start_local ? h('div', { class: 'muted' }, `Next one starts ${formatWhen(c.next_start_local)}`) : null);
  }

  mount(root, h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/kid' }, '‹ Home')), h('h1', {}, 'Challenges'), error, body);
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
