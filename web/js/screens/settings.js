import { api } from '../api.js';
import { h, mount, field, errorBox, onSubmit, toast, timezoneSelect, copyText, appBaseUrl, showError } from '../ui.js';
import { parentShell } from '../layout.js';

export async function render(root) {
  const [house, invites] = await Promise.all([
    api('GET', '/household', undefined, { as: 'parent' }),
    api('GET', '/invites', undefined, { as: 'parent' }),
  ]);
  const hh = house.household;
  const redraw = () => render(root);

  // ---- household settings ----
  const error = errorBox();
  const form = h('form', {},
    field('Family name', h('input', { name: 'name', required: true, maxlength: 100, value: hh.name })),
    field('Time zone', timezoneSelect('timezone', hh.timezone)),
    h('details', {},
      h('summary', {}, 'More options'),
      field('The day ends at', h('input', { type: 'time', name: 'day_end_time', value: hh.day_end_time })),
      h('p', { class: 'muted' }, 'Midnight is right for most families. If bedtime is late, pick a later time (like 2:00 AM) so chores done after midnight still count for the day before.')),
    error,
    h('button', { type: 'submit', class: 'block' }, 'Save'));
  onSubmit(form, error, async (data) => {
    await api('PUT', '/household', Object.fromEntries(data), { as: 'parent' });
    toast('Saved');
    redraw();
  });

  // ---- parents & invites ----
  const inviteError = errorBox();
  const inviteResult = h('div', {});
  const inviteForm = h('form', {},
    field('Their email (optional)', h('input', { type: 'email', name: 'email', autocomplete: 'off' })),
    inviteError,
    h('button', { type: 'submit', class: 'block secondary' }, 'Create invite link'));
  onSubmit(inviteForm, inviteError, async (data) => {
    const body = data.get('email') ? { email: data.get('email') } : {};
    const result = await api('POST', '/invites', body, { as: 'parent' });
    const url = appBaseUrl() + '#/join/' + result.token;
    inviteResult.replaceChildren(h('div', { class: 'notice' },
      h('div', { class: 'linkbox' }, url),
      h('button', { class: 'small', onclick: () => copyText(url) }, 'Copy invite link'),
      h('p', { class: 'muted', style: 'margin-top:8px' }, 'Send this to your co-parent. It works once and expires in 7 days.')));
  });

  const pending = invites.invites.map((inv) => h('div', { class: 'row', style: 'margin-top:8px' },
    h('div', { class: 'grow muted' }, `Pending invite${inv.email ? ' for ' + inv.email : ''}`),
    h('button', {
      class: 'danger small',
      onclick: async () => {
        try { await api('DELETE', `/invites/${inv.id}`, undefined, { as: 'parent' }); redraw(); } catch (err) { showError(inviteError, err); }
      },
    }, 'Cancel')));

  mount(root, ...parentShell('/settings',
    h('h1', {}, 'Settings'),
    h('div', { class: 'card' }, h('h2', {}, 'Household'), form),
    h('div', { class: 'card' },
      h('h2', {}, 'Parents'),
      house.parents.map((p) => h('div', { class: 'row', style: 'margin-bottom:6px' },
        h('div', { class: 'grow' }, h('strong', {}, p.display_name), h('div', { class: 'muted' }, p.email)))),
      pending,
      h('details', {}, h('summary', {}, 'Invite another parent'), inviteForm, inviteResult))));
}
