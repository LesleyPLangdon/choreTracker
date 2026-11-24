import { api } from '../api.js';
import { h, mount, errorBox, showError, formatSqlMoment } from '../ui.js';

/** The kid's own money activity for the last 30 days, in plain words. */
export async function render(root) {
  const error = errorBox();
  const box = h('div', { class: 'card' });
  mount(root, h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/kid' }, '‹ Home')), h('h1', {}, 'My activity'), error, box);
  try {
    const { activity } = await api('GET', '/kid/activity', undefined, { as: 'kid' });
    mount(box, activity.length ? activity.map((a) => h('div', { class: 'activity' }, h('div', { class: 'when' }, formatSqlMoment(a.at_local)), h('div', {}, a.text)))
      : h('p', { class: 'muted', style: 'margin:0' }, 'Nothing yet.'));
  } catch (err) { showError(error, err); }
}
