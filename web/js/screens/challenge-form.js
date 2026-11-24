import { api } from '../api.js';
import { h, mount, field, errorBox, showError, clearError, toast, DAY_NAMES } from '../ui.js';
import { parentShell } from '../layout.js';

const parent = { as: 'parent' };

/** Create a challenge: a kid, the reward, the chores that count, how many times, and the window. */
export async function render(root) {
  const [kidData, choreData] = await Promise.all([api('GET', '/kids', undefined, parent), api('GET', '/chores', undefined, parent)]);
  const kids = kidData.kids.filter((k) => k.active);
  if (!kids.length || !choreData.chores.length) {
    return mount(root, ...parentShell('/challenges', h('div', { class: 'card' }, h('h2', {}, 'Add kids and chores first'),
      h('p', {}, 'A challenge counts chores a kid does, so you need at least one kid and one chore.'), h('a', { class: 'btn', href: '#/chores' }, 'Go to Chores'))));
  }
  const error = errorBox();

  const kid = h('select', {}, kids.map((k) => h('option', { value: k.id }, `${k.avatar} ${k.nickname}`)));
  const reward = h('input', { maxlength: 100, placeholder: 'Open gym', autocomplete: 'off' });
  const target = h('input', { inputmode: 'numeric', value: '7', autocomplete: 'off' });
  const choresBox = h('div', { class: 'chips' });
  const paintChores = () => {
    const kidId = Number(kid.value);
    const mine = choreData.chores.filter((c) => c.kids.some((k) => k.kid_id === kidId));
    mount(choresBox, mine.length ? mine.map((c) => h('label', {}, h('input', { type: 'checkbox', value: c.group_id }), h('span', {}, c.title))) : h('p', { class: 'muted' }, 'This kid has no chores yet.'));
  };
  kid.addEventListener('change', paintChores);
  paintChores();

  const type = h('select', {}, h('option', { value: 'weekly' }, 'Weekly'), h('option', { value: 'daily' }, 'Daily'), h('option', { value: 'once' }, 'One time only'));
  const weekdayOptions = (selected) => DAY_NAMES.map((n, i) => h('option', { value: i + 1, selected: i + 1 === selected }, n));

  const onceStart = h('input', { type: 'datetime-local' });
  const onceEnd = h('input', { type: 'datetime-local' });
  const dStart = h('input', { type: 'time', value: '06:00' });
  const dEnd = h('input', { type: 'time', value: '07:30' });
  const dayBoxes = DAY_NAMES.map((n, i) => h('label', {}, h('input', { type: 'checkbox', value: String(i + 1), checked: i < 5 }), h('span', {}, n)));
  const wStartDay = h('select', {}, weekdayOptions(5));
  const wStartTime = h('input', { type: 'time', value: '17:00' });
  const wEndDay = h('select', {}, weekdayOptions(5));
  const wEndTime = h('input', { type: 'time', value: '17:00' });

  const onceBox = h('div', {}, field('Starts', onceStart), field('Ends', onceEnd));
  const dailyBox = h('div', {}, h('label', {}, 'On these days'), h('div', { class: 'chips' }, dayBoxes),
    h('div', { class: 'row', style: 'margin-top:8px' }, field('From', dStart), field('Until', dEnd)));
  const weeklyBox = h('div', {},
    h('div', { class: 'row' }, field('Starts on', wStartDay), field('at', wStartTime)),
    h('div', { class: 'row' }, field('Ends on', wEndDay), field('at', wEndTime)),
    h('p', { class: 'hint' }, 'The same day and time for both means a full week (like Friday 5:00 PM to Friday 5:00 PM).'));
  const syncType = () => { onceBox.hidden = type.value !== 'once'; dailyBox.hidden = type.value !== 'daily'; weeklyBox.hidden = type.value !== 'weekly'; };
  type.addEventListener('change', syncType);
  syncType();

  const form = h('form', {},
    field('For', kid), field('Reward', reward), field('How many times', target),
    h('label', {}, 'Chores that count'), choresBox,
    field('Window', type), onceBox, dailyBox, weeklyBox, error,
    h('button', { type: 'submit', class: 'block' }, 'Add challenge'));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError(error);
    const button = form.querySelector('button[type=submit]');
    button.disabled = true;
    try {
      const count = Number(target.value);
      if (!Number.isInteger(count) || count < 1) throw new Error('Enter how many times, like 7.');
      const groups = [...choresBox.querySelectorAll('input:checked')].map((i) => i.value);
      const body = { kid_id: Number(kid.value), reward_name: reward.value.trim(), target_count: count, chore_group_ids: groups, repeat_type: type.value };
      if (type.value === 'once') Object.assign(body, { once_start: onceStart.value, once_end: onceEnd.value });
      else if (type.value === 'daily') Object.assign(body, { start_time: dStart.value, end_time: dEnd.value, days: dayBoxes.map((l) => l.querySelector('input')).filter((i) => i.checked).map((i) => Number(i.value)) });
      else Object.assign(body, { start_weekday: Number(wStartDay.value), start_time: wStartTime.value, end_weekday: Number(wEndDay.value), end_time: wEndTime.value });
      await api('POST', '/challenges', body, parent);
      toast('Challenge added');
      location.hash = '#/challenges';
    } catch (err) {
      showError(error, err);
    } finally {
      button.disabled = false;
    }
  });

  mount(root, ...parentShell('/challenges',
    h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/challenges' }, '‹ Back')),
    h('h1', {}, 'Add a challenge'), h('div', { class: 'card' }, form)));
}
