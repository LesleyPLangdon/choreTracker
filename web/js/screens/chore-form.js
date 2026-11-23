import { api } from '../api.js';
import { h, mount, field, errorBox, showError, clearError, toast, centsToInput, dollarsToCents, DAY_NAMES } from '../ui.js';
import { parentShell } from '../layout.js';

/** Add a chore (no id) or edit one (id of the chore as listed on the Chores screen). */
export async function render(root, id) {
  const editing = id !== undefined;
  let group = null;
  let kids = [];
  if (editing) {
    const data = await api('GET', '/chores', undefined, { as: 'parent' });
    group = data.chores.find((c) => String(c.id) === id);
    if (!group) throw new Error('That chore is not on your list anymore.');
  } else {
    kids = (await api('GET', '/kids', undefined, { as: 'parent' })).kids.filter((k) => k.active);
    if (!kids.length) {
      return mount(root, ...parentShell('/chores',
        h('div', { class: 'card' }, h('h2', {}, 'Add a kid first'), h('p', {}, 'Chores are assigned to kids, so add a kid before adding chores.'),
          h('a', { class: 'btn', href: '#/kids' }, 'Go to Kids'))));
    }
  }

  const error = errorBox();

  // ---- name ----
  const title = h('input', { name: 'title', required: true, maxlength: 100, value: group?.title ?? '', autocomplete: 'off' });

  // ---- value ----
  const kind = h('select', {},
    h('option', { value: 'none' }, 'No value'), h('option', { value: 'points' }, 'Points'), h('option', { value: 'money' }, 'Money ($)'));
  const amount = h('input', { inputmode: 'decimal', autocomplete: 'off', placeholder: '0', 'aria-label': 'Amount' });
  if (group?.currency) {
    kind.value = group.currency;
    amount.value = group.currency === 'money' ? centsToInput(group.value) : String(group.value);
  }
  const syncValueFields = () => {
    amount.hidden = kind.value === 'none';
    amount.placeholder = kind.value === 'money' ? '0.50' : '10';
  };
  kind.addEventListener('change', syncValueFields);
  syncValueFields();

  // ---- schedule ----
  const startsOneOff = group?.schedule_type === 'one_off';
  const radio = (value, label, checked) => h('label', { class: 'check' },
    h('input', { type: 'radio', name: 'sched', value, checked, disabled: editing }), label);
  const repeats = radio('daily', 'Repeats', !startsOneOff);
  const oneTime = radio('one_off', 'One time', startsOneOff);
  const schedType = () => (oneTime.querySelector('input').checked ? 'one_off' : 'daily');

  const dayBoxes = DAY_NAMES.map((name, i) => h('label', {},
    h('input', { type: 'checkbox', value: String(i + 1), checked: group && !startsOneOff ? group.days.includes(i + 1) : true }), h('span', {}, name)));
  const setDays = (list) => dayBoxes.forEach((l, i) => { l.querySelector('input').checked = list.includes(i + 1); });
  const daysBox = h('div', {},
    h('div', { class: 'chips' }, dayBoxes),
    h('div', { class: 'row', style: 'margin-top:8px' },
      h('button', { type: 'button', class: 'link', onclick: () => setDays([1, 2, 3, 4, 5, 6, 7]) }, 'Every day'),
      h('button', { type: 'button', class: 'link', onclick: () => setDays([1, 2, 3, 4, 5]) }, 'Weekdays'),
      h('button', { type: 'button', class: 'link', onclick: () => setDays([6, 7]) }, 'Weekends')));
  const dueDate = h('input', { type: 'date', value: group?.due_date ?? '' });
  const dueBox = h('div', {}, h('label', {}, 'Due date (optional)'), dueDate,
    h('p', { class: 'hint', style: 'margin:6px 0 0' }, 'The kid sees it on their list every day until it is done.'));

  // ---- more options: times of day, auto-approve, if not done ----
  const slotRows = [];
  const slotsBox = h('div', {});
  const addSlotButton = h('button', { type: 'button', class: 'secondary small', onclick: () => { addSlot({}); paintSlots(); } }, '+ Add another time of day');
  function addSlot(slot) {
    const label = h('input', { maxlength: 40, value: slot.label && slot.label !== 'Anytime' ? slot.label : '', placeholder: 'Anytime', autocomplete: 'off' });
    const from = h('input', { type: 'time', value: slot.available_from ?? '' });
    const due = h('input', { type: 'time', value: slot.due_by ?? '' });
    const row = { id: slot.id ?? null, label, from, due, el: null };
    row.el = h('div', { class: 'slot' },
      h('label', {}, 'Name (like Morning or Evening)'), label,
      h('div', { class: 'times' },
        h('div', {}, h('label', {}, 'Available from'), from), h('div', {}, h('label', {}, 'Due by'), due)),
      h('button', {
        type: 'button', class: 'danger small', style: 'margin-top:8px',
        onclick: () => { slotRows.splice(slotRows.indexOf(row), 1); paintSlots(); },
      }, 'Remove this time'));
    slotRows.push(row);
  }
  function paintSlots() {
    slotRows.forEach((r) => { r.el.querySelector('button.danger').hidden = slotRows.length === 1; });
    slotsBox.replaceChildren(...slotRows.map((r) => r.el));
    addSlotButton.hidden = slotRows.length >= 6;
  }
  (group?.slots?.length ? group.slots : [{}]).forEach(addSlot);
  paintSlots();

  const autoApprove = h('input', { type: 'checkbox', checked: group?.auto_approve ?? false });
  const required = h('input', { type: 'checkbox', checked: group?.required ?? false });
  const overdue = h('select', {},
    h('option', { value: 'expire' }, 'Goes away (expires)'), h('option', { value: 'carry_over' }, 'Stays on the list (carries over)'));
  let overdueTouched = Boolean(group);
  overdue.value = group?.overdue_behavior ?? 'expire';
  overdue.addEventListener('change', () => { overdueTouched = true; });
  const syncDefaults = () => {
    daysBox.hidden = schedType() !== 'daily';
    dueBox.hidden = schedType() !== 'one_off';
    if (!overdueTouched) overdue.value = schedType() === 'daily' ? 'expire' : 'carry_over';
  };
  repeats.addEventListener('change', syncDefaults);
  oneTime.addEventListener('change', syncDefaults);
  syncDefaults();

  // ---- who: kids to assign to (new) or which copies to change (edit) ----
  let kidChecks = [];
  let whoBox;
  let applyTo = 'all';
  if (editing) {
    if (group.kids.length > 1) {
      const choice = h('select', {},
        h('option', { value: 'all' }, `All kids (${group.kids.map((k) => k.nickname).join(', ')})`),
        group.kids.map((k) => h('option', { value: String(k.chore_id) }, `Just ${k.nickname}`)));
      choice.addEventListener('change', () => { applyTo = choice.value; });
      whoBox = field('Change this chore for', choice);
    } else {
      whoBox = h('p', { class: 'muted' }, `Assigned to ${group.kids[0].avatar} ${group.kids[0].nickname}`);
    }
  } else {
    kidChecks = kids.map((k) => h('label', {}, h('input', { type: 'checkbox', value: String(k.id), checked: kids.length === 1 }), h('span', {}, `${k.avatar} ${k.nickname}`)));
    whoBox = h('div', {}, h('label', {}, 'Assign to'), h('div', { class: 'chips' }, kidChecks));
  }

  // ---- build the request ----
  function collect() {
    const body = { title: title.value.trim(), schedule_type: schedType(), auto_approve: autoApprove.checked, required: required.checked, overdue_behavior: overdue.value };
    if (kind.value !== 'none') {
      const text = amount.value.trim();
      if (kind.value === 'points') {
        if (!/^\d+$/.test(text) || Number(text) < 1) throw new Error('Enter the points as a whole number, like 10.');
        body.value = Number(text);
      } else {
        const cents = dollarsToCents(text);
        if (cents === null || cents < 1) throw new Error('Enter the amount like 0.50 or 5.');
        body.value = cents;
      }
      body.currency = kind.value;
    }
    if (body.schedule_type === 'daily') {
      body.days = dayBoxes.map((l) => l.querySelector('input')).filter((i) => i.checked).map((i) => Number(i.value));
      if (!body.days.length) throw new Error('Pick at least one day.');
    } else if (dueDate.value) {
      body.due_date = dueDate.value;
    }
    const target = group && applyTo !== 'all' ? group.kids.find((k) => String(k.chore_id) === applyTo) : null;
    body.slots = slotRows.map((r) => {
      const slot = { label: r.label.value.trim(), available_from: r.from.value || null, due_by: r.due.value || null };
      if (r.id !== null) {
        // Ids are those of the first copy; when changing just one kid's copy, use that copy's own slot ids.
        const position = group.slots.findIndex((s) => s.id === r.id);
        slot.id = target ? target.slot_ids[position] : r.id;
      }
      return slot;
    });
    return body;
  }

  const form = h('form', {},
    field('Chore', title),
    h('label', {}, 'Value'),
    h('div', { class: 'inline' }, kind, amount),
    h('label', {}, 'When'),
    h('div', { class: 'row wrap' }, repeats, oneTime),
    daysBox, dueBox,
    h('div', { style: 'margin-top:12px' }, whoBox),
    h('label', { class: 'check' }, required, 'Required'),
    h('p', { class: 'hint', style: 'margin:0' }, 'Earnings stay locked until every required chore is done and approved. If a day ends with one not done, that day\'s earnings are forfeited. Changes start tomorrow.'),
    h('details', {},
      h('summary', {}, 'More options'),
      h('label', {}, 'Times of day'),
      h('p', { class: 'hint', style: 'margin:0' }, 'Most chores happen once a day. Add more for things like Morning and Evening. "Due by" is just a heads-up: kids can still finish later and it gets a "late" tag.'),
      slotsBox, addSlotButton,
      h('label', { class: 'check' }, autoApprove, 'Approve automatically when the kid taps Done'),
      field('If it isn\'t done', overdue)),
    error,
    h('button', { type: 'submit', class: 'block' }, editing ? 'Save changes' : 'Add chore'));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError(error);
    const button = form.querySelector('button[type=submit]');
    button.disabled = true;
    try {
      const body = collect();
      if (editing) {
        const targetId = applyTo === 'all' ? group.id : Number(applyTo);
        await api('PUT', `/chores/${targetId}`, { ...body, apply_to: applyTo === 'all' ? 'all' : 'one' }, { as: 'parent' });
      } else {
        body.kid_ids = kidChecks.map((l) => l.querySelector('input')).filter((i) => i.checked).map((i) => Number(i.value));
        if (!body.kid_ids.length) throw new Error('Choose at least one kid.');
        await api('POST', '/chores', body, { as: 'parent' });
      }
      toast('Saved');
      location.hash = '#/chores';
    } catch (err) {
      showError(error, err);
    } finally {
      button.disabled = false;
    }
  });

  const removeButton = editing ? h('button', {
    type: 'button', class: 'danger block',
    onclick: async () => {
      const who = applyTo === 'all' ? (group.kids.length > 1 ? 'for all kids' : '') : 'for that kid';
      if (!confirm(`Remove "${group.title}" ${who}? Past history is kept.`)) return;
      try {
        const targetId = applyTo === 'all' ? group.id : Number(applyTo);
        await api('DELETE', `/chores/${targetId}`, { apply_to: applyTo === 'all' ? 'all' : 'one' }, { as: 'parent' });
        toast('Removed');
        location.hash = '#/chores';
      } catch (err) { showError(error, err); }
    },
  }, 'Remove chore') : null;

  mount(root, ...parentShell('/chores',
    h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/chores' }, '‹ Back')),
    h('h1', {}, editing ? 'Edit chore' : 'Add a chore'),
    editing ? h('p', { class: 'hint' }, 'Changes apply from today on. Chores already done or approved keep their original details.') : null,
    h('div', { class: 'card' }, form),
    removeButton));
}
