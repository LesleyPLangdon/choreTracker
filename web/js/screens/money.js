import { api } from '../api.js';
import { h, mount, errorBox, showError, clearError, toast, formDialog, formatValue, parseAmount, formatSqlMoment, formatDay, siteLink } from '../ui.js';
import { parentShell } from '../layout.js';
import { bucketSettingsDialog, goalDialog } from '../money-parts.js';

const parent = { as: 'parent' };
const CURRENCY_NAME = { points: 'Points', money: 'Money ($)' };

/** A parent's view of one kid's money: balances, payouts and transfers, goals, and the activity list. */
export async function render(root, kidId) {
  const error = errorBox();
  const body = h('div', {});

  async function load() {
    clearError(error);
    const d = await api('GET', `/kids/${kidId}/money`, undefined, parent);
    mount(body, header(d), balances(d), actions(d), goals(d), activity(d));
  }
  const reload = () => load().catch((e) => showError(error, e));
  const attempt = (fn) => async () => { try { await fn(); } catch (err) { showError(error, err); } };

  const header = (d) => h('div', { class: 'row', style: 'margin-bottom:10px' },
    h('div', { class: 'avatar' }, d.kid.avatar),
    h('div', { class: 'grow' }, h('h1', { style: 'margin:0' }, `${d.kid.nickname}'s money`)));

  function balances(d) {
    const cs = d.summary.currencies;
    return h('div', { class: 'card' }, h('h2', {}, 'Balances'),
      cs.length ? cs.map((c) => h('div', {},
        cs.length > 1 ? h('div', { class: 'section-title', style: 'margin-top:6px' }, CURRENCY_NAME[c]) : null,
        [['spending', 'Spending'], ['savings', 'Savings (long-term)'], ['giving', 'Giving']].map(([bucket, label]) =>
          h('div', { class: 'bucket-row' }, h('span', {}, label), h('strong', {}, formatValue(d.balances[bucket][c], c))))))
        : h('p', { class: 'muted', style: 'margin:0' }, 'No chores, goals, or balances yet.'));
  }

  function actions(d) {
    const cs = d.summary.currencies;
    // Default to a currency that actually has something in that bucket.
    const currencyField = (bucket, label = 'Currency') => ({
      name: 'currency', label, type: 'select',
      value: cs.find((c) => (d.balances[bucket]?.[c] ?? 0) > 0) || cs[0] || 'points',
      options: (cs.length ? cs : ['points', 'money']).map((c) => [c, CURRENCY_NAME[c]]),
    });
    const amountOf = (v, currency) => {
      const n = parseAmount(v.amount, currency);
      if (n === null || n < 1) throw new Error(currency === 'money' ? 'Enter the amount like 5 or 0.50.' : 'Enter a whole number of points.');
      return n;
    };
    const dialog = (opts) => attempt(async () => { if (await formDialog(opts)) { toast('Done'); await reload(); } });
    const buttons = [];
    if (cs.includes('money')) {
      buttons.push(h('button', { class: 'small', onclick: dialog({
        title: 'Pay out', intro: `Record money you gave ${d.kid.nickname}. It comes out of Spending.`,
        fields: [{ name: 'amount', label: 'Amount ($)', inputmode: 'decimal', placeholder: '5.00' }, { name: 'note', label: 'Note (optional)' }],
        submitLabel: 'Record payout',
        onSubmit: (v) => api('POST', `/kids/${kidId}/payout`, { amount: amountOf(v, 'money'), ...(v.note ? { note: v.note } : {}) }, parent),
      }) }, 'Pay out'));
    }
    buttons.push(
      h('button', { class: 'secondary small', onclick: dialog({
        title: 'Mark Savings transferred', intro: 'You moved this into a real savings account.',
        fields: [currencyField('savings'), { name: 'amount', label: 'Amount', inputmode: 'decimal' }, { name: 'note', label: 'Note (optional)' }], submitLabel: 'Mark transferred',
        onSubmit: (v) => api('POST', `/kids/${kidId}/savings-transfer`, { currency: v.currency, amount: amountOf(v, v.currency), ...(v.note ? { note: v.note } : {}) }, parent),
      }) }, 'Mark Savings transferred'),
      h('button', { class: 'secondary small', onclick: dialog({
        title: 'Mark Giving given', intro: 'You gave this to a cause.',
        fields: [currencyField('giving'), { name: 'amount', label: 'Amount', inputmode: 'decimal' }, { name: 'note', label: 'Note (optional)' }], submitLabel: 'Mark given',
        onSubmit: (v) => api('POST', `/kids/${kidId}/giving`, { currency: v.currency, amount: amountOf(v, v.currency), ...(v.note ? { note: v.note } : {}) }, parent),
      }) }, 'Mark Giving given'),
      h('button', { class: 'secondary small', onclick: dialog({
        title: 'Adjust a balance', intro: 'Add or subtract, with a note. It can never go below zero.',
        fields: [
          { name: 'bucket', label: 'Which balance', type: 'select', value: 'spending', options: [['spending', 'Spending'], ['savings', 'Savings'], ['giving', 'Giving']] },
          currencyField('spending'),
          { name: 'amount', label: 'Amount (use − to subtract)', inputmode: 'decimal', placeholder: '5 or -5' },
          { name: 'note', label: 'Note (required)' },
        ], submitLabel: 'Adjust',
        onSubmit: (v) => {
          const text = v.amount.trim();
          const negative = text.startsWith('-') || text.startsWith('−');
          const n = amountOf({ amount: text.replace(/^[+\-−]/, '') }, v.currency);
          return api('POST', `/kids/${kidId}/adjust`, { bucket: v.bucket, currency: v.currency, amount: negative ? -n : n, note: v.note }, parent);
        },
      }) }, 'Adjust balance'),
      h('button', { class: 'secondary small', onclick: attempt(() => bucketSettingsDialog(kidId, d.kid.nickname, reload)) }, 'Savings & Giving %'));
    return h('div', { class: 'card' }, h('h2', {}, 'Money actions'), h('div', { class: 'actions-wrap', style: 'margin-top:0' }, buttons));
  }

  function goals(d) {
    const live = d.goals.filter((g) => ['requested', 'active', 'reached'].includes(g.status));
    const addButton = h('button', { class: 'small', onclick: attempt(async () => {
      await goalDialog({ kidId, currencies: d.summary.currencies.length ? d.summary.currencies : ['points', 'money'], after: reload });
    }) }, '+ Add a goal');
    return h('div', { class: 'card' },
      h('div', { class: 'top-actions' }, h('h2', { style: 'margin:0' }, 'Goals'), d.can_add_goal ? addButton : null),
      !d.can_add_goal ? h('p', { class: 'hint', style: 'margin:0 0 8px' }, 'The free plan allows one goal at a time.') : null,
      live.length ? live.map((g) => goalRow(g)) : h('p', { class: 'muted', style: 'margin:0' }, 'No goals right now.'));
  }

  function goalRow(g) {
    const statusTag = { requested: ['Waiting for your approval (see Home)', ''], reached: ['Reached!', 'ok'], active: ['Saving', ''] }[g.status];
    return h('div', { class: 'goalcard' },
      h('div', { class: 'row-between' }, h('span', { class: 'name' }, g.name), h('span', { class: `tag ${statusTag[1]}` }, statusTag[0])),
      siteLink(g.link, g.link_host),
      h('div', { class: 'muted' }, `${formatValue(g.balance, g.currency)} of ${formatValue(g.target, g.currency)}`),
      h('div', { class: 'progress' + (g.balance >= g.target ? ' done' : '') }, h('div', { class: 'bar', style: `width:${Math.min(100, g.target ? Math.round((g.balance / g.target) * 100) : 0)}%` })),
      g.pending_change ? h('div', { class: 'note' }, 'The kid asked to change the link or price (see Home).') : null,
      h('div', { class: 'actions-wrap' },
        h('button', { class: 'secondary small', onclick: attempt(() => goalDialog({ kidId, goal: g, after: reload })) }, 'Edit'),
        g.status !== 'requested' ? h('button', { class: 'success small', onclick: attempt(async () => {
          if (!confirm(`Mark "${g.name}" as used? ${formatValue(Math.min(g.balance, g.target), g.currency)} comes out of the goal` + (g.balance > g.target ? ` and ${formatValue(g.balance - g.target, g.currency)} goes back to Spending.` : '.') + (g.balance < g.target ? ' It is not fully funded yet; you cover the rest.' : ''))) return;
          await api('POST', `/goals/${g.id}/used`, {}, parent); toast('Marked used'); await reload();
        }) }, 'Mark used') : null,
        h('button', { class: 'danger small', onclick: attempt(async () => {
          if (!confirm(`Cancel "${g.name}"? Its money goes back to Spending.`)) return;
          await api('POST', `/goals/${g.id}/cancel`, {}, parent); toast('Goal cancelled'); await reload();
        }) }, 'Cancel goal')));
  }

  function activity(d) {
    return h('div', { class: 'card' }, h('h2', {}, 'Activity (last 30 days)'),
      d.activity.length ? d.activity.map((a) => h('div', { class: 'activity' },
        h('div', { class: 'when' }, formatSqlMoment(a.at_local)), h('div', {}, a.text)))
        : h('p', { class: 'muted', style: 'margin:0' }, 'Nothing yet.'));
  }

  mount(root, ...parentShell('/kids',
    h('p', { style: 'margin:0 0 8px' }, h('a', { href: `#/day/${kidId}` }, '‹ Today')), error, body));
  try { await load(); } catch (err) { showError(error, err); }
}
