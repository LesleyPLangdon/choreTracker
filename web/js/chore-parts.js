// Small pieces shared by the parent screens that show chores (Home approvals and a kid's day).
import { api } from './api.js';
import { h, askNote, toast, formatValue, formatDue, formatDay, formatClock } from './ui.js';
import { refreshBadge } from './layout.js';

/** Approve / Send back buttons for something waiting on a parent. `after()` reloads the screen. */
export function approvalButtons(instanceId, kidName, after, fail) {
  return [
    h('button', {
      class: 'success small',
      onclick: async (event) => {
        event.currentTarget.disabled = true;
        try {
          await api('POST', `/instances/${instanceId}/approve`, {}, { as: 'parent' });
          toast('Approved');
          await after();
          refreshBadge();
        } catch (err) { fail(err); event.target.disabled = false; }
      },
    }, 'Approve'),
    h('button', {
      class: 'secondary small',
      onclick: async () => {
        const note = await askNote(`Send back to ${kidName}`, 'Optional note, e.g. "The bed isn\'t made"');
        if (note === null) return; // cancelled
        try {
          await api('POST', `/instances/${instanceId}/send-back`, note ? { note } : {}, { as: 'parent' });
          toast('Sent back');
          await after();
          refreshBadge();
        } catch (err) { fail(err); }
      },
    }, 'Send back'),
  ];
}

/** Title line for a chore row: name, slot label, value, due date. */
export function choreTitle(row, today) {
  return h('div', { class: 'grow' },
    h('div', { class: 'title' }, row.title, row.slot_label ? h('span', { class: 'muted' }, ` · ${row.slot_label}`) : null),
    h('div', { class: 'muted' },
      [formatValue(row.value, row.currency), row.due_date ? formatDue(row.due_date, today) : null].filter(Boolean).join(' · ')));
}

/** Little status tags for a chore row. `kid` switches the wording to what a kid should see. */
export function choreTags(c, kid = false) {
  const tags = [];
  if (c.required) tags.push(h('span', { class: 'tag req' }, 'Required'));
  if (c.status === 'reported_done') tags.push(h('span', { class: 'tag' }, kid ? 'Waiting for a parent' : 'Waiting for approval'));
  if (c.status === 'approved') tags.push(h('span', { class: 'tag ok' }, kid ? 'Approved ✓' : 'Approved'));
  if (c.status === 'sent_back') tags.push(h('span', { class: 'tag bad' }, 'Sent back'));
  if (c.status === 'missed') tags.push(h('span', { class: 'tag warn' }, 'Missed'));
  if (c.overdue) tags.push(h('span', { class: 'tag warn' }, c.for_date && !c.is_one_off ? `Overdue · from ${formatDay(c.for_date)}` : 'Overdue'));
  if ((c.is_late || c.past_due_by) && !c.overdue) tags.push(h('span', { class: 'tag late' }, 'Late'));
  if (c.back_filled) tags.push(h('span', { class: 'tag' }, 'Back-filled'));
  if (c.opens_at) tags.push(h('span', { class: 'tag' }, `${kid ? 'Opens at' : 'Not open until'} ${formatClock(c.opens_at)}`));
  return h('div', {}, tags);
}
