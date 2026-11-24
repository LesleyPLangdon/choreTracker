import { api } from '../api.js';
import { h, mount, field, errorBox, showError, clearError, toast, formDialog, formatValue, parseAmount, amountToInput, progress, siteLink, amountsText } from '../ui.js';

const kid = { as: 'kid' };

/** Saving for something specific: create goals, move money in and out, and move it between goals. */
export async function render(root) {
  const error = errorBox();
  const listBox = h('div', {});
  const formBox = h('div', { class: 'card' });
  const spendingBox = h('div', { class: 'card' });

  async function load() {
    clearError(error);
    const data = await api('GET', '/kid/goals', undefined, kid);
    mount(spendingBox, h('div', { class: 'balance' }, h('span', {}, 'Spending (what you can use)'), h('strong', {}, amountsText(data.spending) || '0')));
    const live = data.goals.filter((g) => ['requested', 'active', 'reached'].includes(g.status));
    const past = data.goals.filter((g) => ['used', 'denied'].includes(g.status));
    mount(listBox,
      live.length ? h('div', { class: 'card' }, h('h2', {}, 'My goals'), live.map((g) => goalCard(g, live))) : null,
      past.length ? h('div', { class: 'card' }, h('h2', {}, 'Finished'), past.map((g) => h('div', { class: 'goalcard' },
        h('div', { class: 'row-between' }, h('span', { class: 'name' }, g.name), h('span', { class: `tag ${g.status === 'used' ? 'ok' : 'warn'}` }, g.status === 'used' ? 'Got it! 🎉' : 'Not approved')),
        g.status === 'denied' && g.decision_note ? h('div', { class: 'note' }, `“${g.decision_note}”`) : null))) : null);
    paintForm(data);
  }
  const attempt = (fn) => async () => { try { await fn(); } catch (err) { showError(error, err); window.scrollTo(0, 0); } };

  function goalCard(g, all) {
    const maxIn = null;
    const others = all.filter((o) => o.id !== g.id && o.currency === g.currency && ['active', 'reached'].includes(o.status));
    const open = g.status === 'active' || g.status === 'reached';
    const moveDialog = (action) => attempt(async () => {
      const toIn = action === 'in';
      const fields = [{ name: 'amount', label: toIn ? 'How much to add?' : 'How much?', inputmode: 'decimal', placeholder: g.currency === 'money' ? '2.00' : '10' }];
      if (action === 'transfer') fields.unshift({ name: 'to', label: 'Move it to which goal?', type: 'select', value: others[0].id, options: others.map((o) => [o.id, o.name]) });
      const done = await formDialog({
        title: { in: `Add to ${g.name}`, out: `Take out of ${g.name}`, transfer: `Move from ${g.name}` }[action],
        intro: toIn ? 'This comes out of your Spending.' : action === 'out' ? 'This goes back to your Spending.' : `You have ${formatValue(g.balance, g.currency)} in this goal.`,
        fields, submitLabel: 'Move it',
        onSubmit: async (v) => {
          const amount = parseAmount(v.amount, g.currency);
          if (amount === null || amount < 1) throw new Error(g.currency === 'money' ? 'Enter an amount like 2.00.' : 'Enter a whole number.');
          await api('POST', `/kid/goals/${g.id}/move`, { action, amount, ...(action === 'transfer' ? { to_goal_id: Number(v.to) } : {}) }, kid);
        },
      });
      if (done) { toast('Moved'); await load(); }
    });
    return h('div', { class: 'goalcard' },
      h('div', { class: 'row-between' }, h('span', { class: 'name' }, g.name),
        g.status === 'reached' ? h('span', { class: 'tag ok' }, 'You did it! 🎉') : g.status === 'requested' ? h('span', { class: 'tag' }, 'Waiting for a parent') : null),
      siteLink(g.link, g.link_host),
      h('div', { class: 'muted' }, `${formatValue(g.balance, g.currency)} of ${formatValue(g.target, g.currency)}`),
      progress(g.balance, g.target),
      g.status === 'reached' ? h('div', { class: 'muted' }, 'A parent will check and get it for you.') : null,
      g.pending_change ? h('div', { class: 'note', style: 'color:var(--muted)' }, 'A parent needs to approve your change.') : null,
      h('div', { class: 'actions-wrap' },
        open ? h('button', { class: 'small', onclick: moveDialog('in') }, 'Add money') : null,
        open && g.balance > 0 ? h('button', { class: 'secondary small', onclick: moveDialog('out') }, 'Take money out') : null,
        open && g.balance > 0 && others.length ? h('button', { class: 'secondary small', onclick: moveDialog('transfer') }, 'Move to another goal') : null,
        h('button', { class: 'secondary small', onclick: attempt(async () => {
          const done = await formDialog({
            title: 'Change my goal', intro: g.status === 'requested' ? '' : 'A parent will approve a new link or price.',
            fields: [{ name: 'name', label: 'Name', value: g.name, maxlength: 100 },
              { name: 'target', label: 'Price', value: amountToInput(g.target, g.currency), inputmode: 'decimal' },
              { name: 'link', label: 'Link (optional)', value: g.link ?? '', placeholder: 'https://…' }],
            submitLabel: 'Save',
            onSubmit: async (v) => {
              const target = parseAmount(v.target, g.currency);
              if (target === null || target < 1) throw new Error('Enter the price like 20.00.');
              await api('PUT', `/kid/goals/${g.id}`, { name: v.name, target, link: v.link.trim() === '' ? null : v.link.trim() }, kid);
            },
          });
          if (done) { toast('Saved'); await load(); }
        }) }, 'Change'),
        h('button', { class: 'danger small', onclick: attempt(async () => {
          if (!confirm(`Cancel "${g.name}"? Your ${formatValue(g.balance, g.currency)} goes back to Spending.`)) return;
          await api('POST', `/kid/goals/${g.id}/cancel`, {}, kid); toast('Goal cancelled'); await load();
        }) }, 'Cancel goal')));
  }

  function paintForm(data) {
    if (!data.can_add) {
      mount(formBox, h('h2', {}, 'New goal'), h('p', { class: 'muted', style: 'margin:0' }, 'You can save for one goal at a time right now. Finish or cancel your current goal to start another.'));
      return;
    }
    const currencies = data.currencies.length ? data.currencies : ['points', 'money'];
    const formError = errorBox();
    const name = h('input', { maxlength: 100, placeholder: 'New game', autocomplete: 'off', required: true });
    const currency = h('select', {}, currencies.map((c) => h('option', { value: c }, c === 'money' ? 'Money ($)' : 'Points')));
    const price = h('input', { inputmode: 'decimal', placeholder: '20.00', autocomplete: 'off', required: true });
    const link = h('input', { placeholder: 'https://… (optional)', autocomplete: 'off' });
    const form = h('form', {}, field('What are you saving for?', name),
      currencies.length > 1 ? field('Saved in', currency) : null, field('Price', price), field('Link to it (optional)', link), formError,
      h('button', { type: 'submit', class: 'block' }, 'Ask a parent'));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearError(formError);
      try {
        const amount = parseAmount(price.value, currency.value);
        if (amount === null || amount < 1) throw new Error(currency.value === 'money' ? 'Enter the price like 20.00.' : 'Enter the price as a whole number.');
        await api('POST', '/kid/goals', { name: name.value, currency: currency.value, target: amount, link: link.value.trim() === '' ? null : link.value.trim() }, kid);
        toast('Sent to a parent!');
        await load();
      } catch (err) { showError(formError, err); }
    });
    mount(formBox, h('h2', {}, 'New goal'), form);
  }

  mount(root, h('p', { style: 'margin:0 0 8px' }, h('a', { href: '#/kid' }, '‹ Home')), h('h1', {}, 'My goals'), error, spendingBox, listBox, formBox);
  try { await load(); } catch (err) { showError(error, err); }
  return () => load();
}
