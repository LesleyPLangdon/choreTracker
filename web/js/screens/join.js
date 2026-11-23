import { api, session } from '../api.js';
import { h, mount, field, errorBox, onSubmit } from '../ui.js';

/** A second parent opens the invite link and creates their own sign-in. */
export async function render(root, inviteToken) {
  let preview;
  try {
    preview = await api('GET', '/invite/' + encodeURIComponent(inviteToken));
  } catch (err) {
    return mount(root, h('div', { class: 'card' }, h('h2', {}, 'Invite not valid'), h('p', {}, err.message),
      h('a', { class: 'btn', href: '#/login' }, 'Sign in')));
  }
  const error = errorBox();
  const form = h('form', {},
    field('Your name', h('input', { name: 'display_name', autocomplete: 'name', required: true, maxlength: 60 })),
    field('Email', h('input', { type: 'email', name: 'email', autocomplete: 'username', required: true, value: preview.email || '' })),
    field('Password (at least 10 characters)', h('input', { type: 'password', name: 'password', autocomplete: 'new-password', required: true, minlength: 10 })),
    error,
    h('button', { type: 'submit', class: 'block' }, 'Join'));

  onSubmit(form, error, async (data) => {
    const result = await api('POST', '/invite/' + encodeURIComponent(inviteToken) + '/accept', Object.fromEntries(data));
    session.setParentToken(result.token);
    location.hash = '#/home';
  });

  mount(root,
    h('h1', {}, 'Join ' + preview.household_name),
    h('p', { class: 'muted' }, 'Create your sign-in to help manage this family.'),
    h('div', { class: 'card' }, form));
}
