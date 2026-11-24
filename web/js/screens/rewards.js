import { api } from '../api.js';
import { h, mount, errorBox, showError, toast, formDialog, formatValue, parseAmount, amountToInput } from '../ui.js';
import { parentShell } from '../layout.js';

const parent = { as: 'parent' };

/** The household's rewards: what kids can spend on. */
export async function render(root) {
  const error = errorBox();
  const body = h('div', {});
  let kids = [];

  async function load() {
    const [r, k] = await Promise.all([api('GET', '/rewards', undefined, parent), api('GET', '/kids', undefined, parent)]);
    kids = k.kids.filter((x) => x.active);
    mount(body, r.rewards.length ? r.rewards.map(card) : h('div', { class: 'card' }, h('p', { class: 'muted', style: 'margin:0' }, 'No rewards yet. Add things kids can spend on, like "Pick the movie".')));
  }

  const edit = (reward) => async () => {
    try {
      const editing = Boolean(reward);
      const saved = await formDialog({
        title: editing ? 'Edit reward' : 'Add a reward',
        fields: [
          { name: 'name', label: 'Reward', value: reward?.name ?? '', maxlength: 100, placeholder: 'Pick the movie' },
          { name: 'currency', label: 'Priced in', type: 'select', value: reward?.currency ?? 'points', options: [['points', 'Points'], ['money', 'Money ($)']] },
          { name: 'cost', label: 'Price (whole points, or dollars like 5.00)', value: editing ? amountToInput(reward.cost, reward.currency) : '', inputmode: 'decimal' },
          { name: 'kid_ids', label: 'Who can see it', type: 'checks', value: reward?.kid_ids ?? [], options: kids.map((k) => [k.id, `${k.avatar} ${k.nickname}`]), hint: 'Leave all unchecked for every kid.' },
        ],
        submitLabel: editing ? 'Save' : 'Add reward',
        onSubmit: async (v) => {
          const cost = parseAmount(v.cost, v.currency);
          if (cost === null || cost < 1) throw new Error(v.currency === 'money' ? 'Enter the price like 5.00.' : 'Enter the price as a whole number of points.');
          const payload = { name: v.name, currency: v.currency, cost, kid_ids: v.kid_ids.map(Number) };
          if (editing) await api('PUT', `/rewards/${reward.id}`, payload, parent);
          else await api('POST', '/rewards', payload, parent);
        },
      });
      if (saved) { toast('Saved'); await load(); }
    } catch (err) { showError(error, err); }
  };

  function card(r) {
    const who = r.kid_ids.length ? r.kid_ids.map((id) => kids.find((k) => k.id === id)?.nickname).filter(Boolean).join(', ') : 'Every kid';
    return h('div', { class: 'card' }, h('div', { class: 'row' },
      h('div', { class: 'grow' }, h('div', { style: 'font-weight:700' }, r.name), h('div', { class: 'muted' }, `${formatValue(r.cost, r.currency)} · ${who}`)),
      h('button', { class: 'secondary small', onclick: edit(r) }, 'Edit'),
      h('button', { class: 'danger small', onclick: async () => {
        if (!confirm(`Remove "${r.name}"?`)) return;
        try { await api('DELETE', `/rewards/${r.id}`, undefined, parent); toast('Removed'); await load(); } catch (err) { showError(error, err); }
      } }, 'Remove')));
  }

  mount(root, ...parentShell('/rewards',
    h('div', { class: 'top-actions' }, h('h1', { style: 'margin:0' }, 'Rewards'), h('button', { class: 'small', onclick: edit(null) }, '+ Add reward')),
    h('p', { class: 'muted' }, 'Kids ask for a reward and you approve it. The price comes out of their Spending. Rewards are usually points; money prices are there if your family works in dollars.'),
    error, body));
  try { await load(); } catch (err) { showError(error, err); }
}
