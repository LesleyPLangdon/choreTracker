import { api } from '../api.js';
import { h, mount, field, errorBox, onSubmit, toast, copyText, appBaseUrl, showError } from '../ui.js';
import { parentShell } from '../layout.js';
import { bucketSettingsDialog } from '../money-parts.js';

// Links are only known right after a kid is created or a link is reset (the server keeps just a hash),
// so we remember them here for the rest of this visit.
const freshLinks = new Map();

function avatarPicker(avatars, selected) {
  return h('div', { class: 'avatar-picker' },
    avatars.map((a, i) => h('label', {},
      h('input', { type: 'radio', name: 'avatar', value: a, checked: selected ? a === selected : i === 0 }),
      h('span', {}, a))));
}

function linkUrl(token) {
  return appBaseUrl() + '#/k/' + token;
}

export async function render(root) {
  const data = await api('GET', '/kids', undefined, { as: 'parent' });
  const redraw = () => render(root);

  // ---- add a kid ----
  const addError = errorBox();
  const addForm = h('form', {},
    field('Nickname', h('input', { name: 'nickname', required: true, maxlength: 30, autocomplete: 'off' })),
    field('Avatar', avatarPicker(data.avatars)),
    field('PIN (4 to 6 digits)', h('input', { name: 'pin', inputmode: 'numeric', pattern: '\\d{4,6}', minlength: 4, maxlength: 6, required: true, autocomplete: 'off' })),
    addError,
    h('button', { type: 'submit', class: 'block' }, 'Add kid'));
  onSubmit(addForm, addError, async (form) => {
    const result = await api('POST', '/kids', Object.fromEntries(form), { as: 'parent' });
    freshLinks.set(result.kid.id, result.link_token);
    toast('Kid added');
    redraw();
  });

  // ---- one card per kid ----
  const cards = data.kids.map((kid) => kidCard(kid, data.avatars, redraw));

  mount(root, ...parentShell('/kids',
    h('h1', {}, 'Kids'),
    h('p', { class: 'muted' }, 'Each kid gets a private link and a PIN. Put the link on their device; they type their PIN to open it.'),
    ...cards,
    h('div', { class: 'card' }, h('h2', {}, 'Add a kid'), addForm)));
}

function kidCard(kid, avatars, redraw) {
  const error = errorBox();
  const run = (fn) => async () => {
    try { await fn(); } catch (err) { showError(error, err); }
  };

  const link = freshLinks.get(kid.id);
  const linkSection = link
    ? h('div', { class: 'notice' },
        h('strong', {}, `${kid.nickname}'s link`),
        h('div', { class: 'linkbox' }, linkUrl(link)),
        h('button', { class: 'small', onclick: () => copyText(linkUrl(link)) }, 'Copy link'),
        h('p', { class: 'muted', style: 'margin-top:8px' }, 'For safety this link is only shown now. If you lose it, use "New link" to make another.'))
    : null;

  const actions = [];
  if (kid.active) {
    actions.push(
      h('button', {
        class: 'secondary small',
        onclick: run(async () => {
          if (!confirm(`Make a new link for ${kid.nickname}? The old link will stop working.`)) return;
          const result = await api('POST', `/kids/${kid.id}/link/reset`, {}, { as: 'parent' });
          freshLinks.set(kid.id, result.link_token);
          redraw();
        }),
      }, 'New link'),
      h('button', {
        class: 'secondary small',
        onclick: run(async () => {
          const pin = prompt(`New PIN for ${kid.nickname} (4 to 6 digits):`);
          if (pin === null) return;
          await api('POST', `/kids/${kid.id}/pin`, { pin }, { as: 'parent' });
          toast('PIN changed');
        }),
      }, 'Change PIN'),
      h('button', {
        class: 'secondary small',
        onclick: () => editDialog(kid, avatars, redraw),
      }, 'Edit'),
      h('button', {
        class: 'secondary small',
        onclick: run(() => bucketSettingsDialog(kid.id, kid.nickname)),
      }, 'Savings & Giving'),
      h('a', { class: 'btn secondary small', href: `#/money/${kid.id}` }, 'Money & goals'));
  }
  if (kid.locked) {
    actions.push(h('button', {
      class: 'small',
      onclick: run(async () => { await api('POST', `/kids/${kid.id}/unlock`, {}, { as: 'parent' }); toast('Unlocked'); redraw(); }),
    }, 'Unlock PIN'));
  }
  actions.push(h('button', {
    class: kid.active ? 'danger small' : 'secondary small',
    onclick: run(async () => {
      if (kid.active && !confirm(`Turn off ${kid.nickname}? Their link will stop working until you turn them back on.`)) return;
      await api('POST', `/kids/${kid.id}/active`, { active: !kid.active }, { as: 'parent' });
      redraw();
    }),
  }, kid.active ? 'Turn off' : 'Turn back on'));

  return h('div', { class: 'card' },
    h('div', { class: 'row' },
      h('div', { class: 'avatar' }, kid.avatar),
      h('div', { class: 'grow' }, h('strong', {}, kid.nickname)),
      !kid.active ? h('span', { class: 'badge' }, 'Off') : null,
      kid.locked ? h('span', { class: 'badge warn' }, 'PIN locked') : null),
    linkSection,
    h('div', { class: 'row wrap', style: 'margin-top:12px' }, ...actions),
    error);
}

function editDialog(kid, avatars, redraw) {
  const dialog = h('dialog', {});
  const error = errorBox();
  const form = h('form', {},
    h('h2', {}, 'Edit ' + kid.nickname),
    field('Nickname', h('input', { name: 'nickname', required: true, maxlength: 30, value: kid.nickname })),
    field('Avatar', avatarPicker(avatars, kid.avatar)),
    error,
    h('div', { class: 'row', style: 'margin-top:16px' },
      h('button', { type: 'submit' }, 'Save'),
      h('button', { type: 'button', class: 'secondary', onclick: () => dialog.close() }, 'Cancel')));
  onSubmit(form, error, async (data) => {
    await api('PUT', `/kids/${kid.id}`, Object.fromEntries(data), { as: 'parent' });
    dialog.close();
    redraw();
  });
  dialog.append(form);
  dialog.addEventListener('close', () => dialog.remove());
  document.body.append(dialog);
  dialog.showModal();
}
