// Shared by the parent's money screens: bucket settings, goal forms, and the approval items for goals, rewards, and challenges.
import { api } from './api.js';
import { h, formDialog, askNote, toast, formatValue, parseAmount, amountToInput, siteLink } from './ui.js';
import { refreshBadge } from './layout.js';

const parent = { as: 'parent' };

/** Savings % and Giving % for a kid, per currency. Shown on the kid's settings, not the main forms. */
export async function bucketSettingsDialog(kidId, nickname, after) {
  const { buckets } = await api('GET', `/kids/${kidId}/buckets`, undefined, parent);
  const pct = (name, label, value) => ({ name, label, type: 'number', value, inputmode: 'numeric', placeholder: '0' });
  await formDialog({
    title: `Savings & Giving for ${nickname}`,
    intro: 'Taken off the top when a day unlocks; the rest goes to Spending. Leave at 0 for none.',
    fields: [
      pct('points_s', 'Points: Savings %', buckets.points.savings_pct), pct('points_g', 'Points: Giving %', buckets.points.giving_pct),
      pct('money_s', 'Money: Savings %', buckets.money.savings_pct), pct('money_g', 'Money: Giving %', buckets.money.giving_pct),
    ],
    onSubmit: async (v) => {
      const n = (text) => {
        if (!/^\d{1,3}$/.test(String(text).trim() || '0')) throw new Error('Use whole numbers from 0 to 100.');
        return Number(String(text).trim() || '0');
      };
      await api('PUT', `/kids/${kidId}/buckets`, {
        points: { savings_pct: n(v.points_s), giving_pct: n(v.points_g) },
        money: { savings_pct: n(v.money_s), giving_pct: n(v.money_g) },
      }, parent);
      toast('Saved');
      if (after) await after();
    },
  });
}

/** A parent adds a goal for a kid (already approved) or edits one. */
export function goalDialog({ kidId, goal, currencies = ['points', 'money'], after }) {
  const editing = Boolean(goal);
  return formDialog({
    title: editing ? 'Edit goal' : 'Add a goal',
    fields: [
      { name: 'name', label: 'What is it?', value: goal?.name ?? '', maxlength: 100 },
      ...(editing ? [] : [{ name: 'currency', label: 'Saved in', type: 'select', value: currencies[0], options: currencies.map((c) => [c, c === 'money' ? 'Money ($)' : 'Points']) }]),
      { name: 'target', label: `Price (${(goal?.currency ?? 'points') === 'money' || !editing ? 'dollars like 20.00, or whole points' : 'whole points'})`, value: editing ? amountToInput(goal.target, goal.currency) : '', inputmode: 'decimal' },
      { name: 'link', label: 'Link to the item (optional, http or https)', value: goal?.link ?? '', placeholder: 'https://…' },
    ],
    submitLabel: editing ? 'Save' : 'Add goal',
    onSubmit: async (v) => {
      const currency = editing ? goal.currency : v.currency;
      const target = parseAmount(v.target, currency);
      if (target === null || target < 1) throw new Error(currency === 'money' ? 'Enter the price like 20.00.' : 'Enter the price as a whole number of points.');
      const body = { name: v.name, target, link: v.link.trim() === '' ? null : v.link.trim() };
      if (editing) await api('PUT', `/goals/${goal.id}`, body, parent);
      else await api('POST', `/kids/${kidId}/goals`, { ...body, currency }, parent);
      toast('Saved');
      if (after) await after();
    },
  });
}

const money = (amount, currency) => formatValue(amount, currency);

/** One row of the approvals list for the non-chore item types. `reload()` refreshes the screen; `fail(err)` shows an error. */
export function approvalItem(item, reload, fail) {
  const kid = item.kid.nickname;
  const avatar = h('div', { class: 'avatar', style: 'width:40px;height:40px;font-size:1.4rem' }, item.kid.avatar);
  const done = (message) => async () => { toast(message); await reload(); refreshBadge(); };
  const attempt = (fn, message) => async (event) => {
    event?.currentTarget && (event.currentTarget.disabled = true);
    try {
      if ((await fn()) === false) { if (event?.target) event.target.disabled = false; return; } // false = the parent backed out
      await done(message)();
    } catch (err) { fail(err); if (event?.target) event.target.disabled = false; }
  };
  const row = (title, detail, buttons) => h('div', { class: 'chore' }, avatar, h('div', { class: 'grow' },
    h('div', { class: 'title' }, title), h('div', { class: 'muted' }, detail)), h('div', { class: 'actions' }, buttons));

  switch (item.type) {
    case 'goal_request': {
      const g = item.goal;
      return row(`${kid} wants to save for: ${g.name}`, [money(g.target, g.currency), siteLink(g.link, g.link_host)], [
        h('button', { class: 'success small', onclick: async () => {
          await formDialog({
            title: `Approve ${kid}'s goal`, intro: 'You can fix the price or link before approving.',
            fields: [
              { name: 'name', label: 'Goal', value: g.name }, { name: 'target', label: 'Price', value: amountToInput(g.target, g.currency), inputmode: 'decimal' },
              { name: 'link', label: 'Link (optional)', value: g.link ?? '', placeholder: 'https://…' }, { name: 'note', label: 'Note (optional)', value: '' },
            ], submitLabel: 'Approve',
            onSubmit: async (v) => {
              const target = parseAmount(v.target, g.currency);
              if (target === null || target < 1) throw new Error('Enter the price like 20.00 (or whole points).');
              await api('POST', `/goals/${g.id}/approve`, { name: v.name, target, link: v.link.trim() === '' ? null : v.link.trim(), note: v.note }, parent);
            },
          });
          await done('Goal approved')();
        } }, 'Approve'),
        h('button', { class: 'secondary small', onclick: async () => {
          const note = await askNote(`Say no to ${kid}'s goal`, 'Optional note', 'Deny');
          if (note === null) return;
          await attempt(() => api('POST', `/goals/${g.id}/deny`, note ? { note } : {}, parent), 'Goal denied')();
        } }, 'Deny'),
      ]);
    }
    case 'goal_change': {
      const g = item.goal;
      const p = g.pending_change;
      const bits = [];
      if (p.link_changed) bits.push(p.link ? ['New link: ', siteLink(p.link, p.link_host)] : 'Remove the link');
      if (p.target !== null) bits.push(`Price ${money(g.target, g.currency)} → ${money(p.target, g.currency)}`);
      return row(`${kid} wants to change their goal: ${g.name}`, bits.map((b, i) => h('div', {}, b)), [
        h('button', { class: 'success small', onclick: attempt(() => api('POST', `/goals/${g.id}/approve-change`, {}, parent), 'Change approved') }, 'Approve'),
        h('button', { class: 'secondary small', onclick: attempt(() => api('POST', `/goals/${g.id}/deny-change`, {}, parent), 'Change denied') }, 'Deny'),
      ]);
    }
    case 'goal_reached': {
      const g = item.goal;
      return row(`${kid} reached the goal: ${g.name}`, [`${money(g.balance, g.currency)} saved of ${money(g.target, g.currency)}`, siteLink(g.link, g.link_host)], [
        h('button', { class: 'success small', onclick: attempt(async () => {
          if (!confirm(`Mark "${g.name}" as used? This takes ${money(Math.min(g.balance, g.target), g.currency)} out of ${kid}'s goal` + (g.balance > g.target ? ` and returns ${money(g.balance - g.target, g.currency)} to Spending.` : '.'))) return false;
          await api('POST', `/goals/${g.id}/used`, {}, parent);
        }, 'Marked used') }, 'Mark used'),
      ]);
    }
    case 'reward_request': {
      const q = item.request;
      return row(`${kid} asked for: ${q.name}`, money(q.cost, q.currency), [
        h('button', { class: 'success small', onclick: attempt(() => api('POST', `/reward-requests/${q.id}/approve`, {}, parent), 'Approved') }, 'Approve'),
        h('button', { class: 'secondary small', onclick: async () => {
          const note = await askNote(`Say no to ${kid}'s request`, 'Optional note', 'Deny');
          if (note === null) return;
          await attempt(() => api('POST', `/reward-requests/${q.id}/deny`, note ? { note } : {}, parent), 'Denied')();
        } }, 'Deny'),
      ]);
    }
    case 'reward_delivery': {
      const q = item.request;
      return row(`Give ${kid}: ${q.name}`, `${money(q.cost, q.currency)} was taken from their Spending`, [
        h('button', { class: 'success small', onclick: attempt(() => api('POST', `/reward-requests/${q.id}/delivered`, {}, parent), 'Delivered') }, 'Delivered'),
      ]);
    }
    case 'challenge_earned':
      return row(`${kid} earned: ${item.reward_name}`, 'Challenge complete', [
        h('button', { class: 'success small', onclick: attempt(() => api('POST', `/challenge-periods/${item.id}/delivered`, {}, parent), 'Delivered') }, 'Delivered'),
      ]);
    default:
      return null;
  }
}
