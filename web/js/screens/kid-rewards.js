import { api } from '../api.js';
import { h, mount, errorBox, showError, clearError, toast, formatValue, progress } from '../ui.js';

const kid = { as: 'kid' };

/** Rewards the kid can ask for, with whether they can afford each one. */
export async function render(root) {
  const error = errorBox();
  const body = h('div', {});

  async function load() {
    clearError(error);
    const data = await api('GET', '/kid/rewards', undefined, kid);
    mount(body,
      data.rewards.length ? h('div', { class: 'card' }, h('h2', {}, 'Rewards'), data.rewards.map(card)) : h('div', { class: 'card' }, h('p', { class: 'muted', style: 'margin:0' }, 'No rewards yet.')),
      data.requests.length ? h('div', { class: 'card' }, h('h2', {}, 'My requests'), data.requests.map((q) => h('div', { class: 'goalcard' },
        h('div', { class: 'row-between' }, h('span', { class: 'name' }, q.name), h('span', { class: 'muted' }, formatValue(q.cost, q.currency))),
        q.status === 'requested' ? [h('span', { class: 'tag' }, 'Waiting for a parent'), h('div', { class: 'actions-wrap' }, h('button', { class: 'secondary small', onclick: act(() => api('POST', `/kid/reward-requests/${q.id}/cancel`, {}, kid), 'Cancelled') }, 'Cancel request'))]
          : q.status === 'approved' ? h('span', { class: 'tag ok' }, 'Approved! A parent will get it to you.')
          : h('div', {}, h('span', { class: 'tag warn' }, 'Not this time'), q.decision_note ? h('div', { class: 'note' }, `“${q.decision_note}”`) : null)))) : null);
  }
  const act = (fn, message) => async () => {
    try { await fn(); toast(message); await load(); } catch (err) { showError(error, err); window.scrollTo(0, 0); }
  };

  function card(r) {
    return h('div', { class: 'goalcard' },
      h('div', { class: 'row-between' }, h('span', { class: 'name' }, r.name), h('strong', {}, formatValue(r.cost, r.currency))),
      h('div', { class: 'muted' }, `You have ${formatValue(r.spending, r.currency)} in Spending`),
      r.goal ? [h('div', { class: 'muted' }, `Saving for this: ${formatValue(r.goal.balance, r.goal.currency)} of ${formatValue(r.goal.target, r.goal.currency)}`), progress(r.goal.balance, r.goal.target)] : null,
      h('div', { class: 'actions-wrap' },
        r.request_id ? h('span', { class: 'tag' }, 'You asked for this') : r.affordable ? h('button', { class: 'success small', onclick: act(() => api('POST', `/kid/rewards/${r.id}/request`, {}, kid), 'Sent to a parent!') }, 'Request') : null,
        r.can_save ? h('button', { class: 'small', onclick: act(() => api('POST', `/kid/rewards/${r.id}/save`, {}, kid), 'Started saving for it!') }, 'Save for this') : null,
        !r.affordable && r.goal ? h('a', { class: 'btn secondary small', href: '#/kid/goals' }, 'Add money to my goal') : null));
  }

  mount(root, h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/kid' }, '‹ Home')), h('h1', {}, 'Rewards'), error, body);
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
