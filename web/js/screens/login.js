import { api, session } from '../api.js';
import { h, mount, field, errorBox, onSubmit } from '../ui.js';

export function render(root) {
  const error = errorBox();
  const form = h('form', {},
    field('Email', h('input', { type: 'email', name: 'email', autocomplete: 'username', required: true })),
    field('Password', h('input', { type: 'password', name: 'password', autocomplete: 'current-password', required: true })),
    error,
    h('button', { type: 'submit', class: 'block' }, 'Sign in'));

  onSubmit(form, error, async (data) => {
    const result = await api('POST', '/login', { email: data.get('email'), password: data.get('password') });
    session.setParentToken(result.token);
    location.hash = '#/home';
  });

  mount(root,
    h('h1', {}, 'Chore Tracker'),
    h('p', { class: 'muted' }, 'Parent sign in'),
    h('div', { class: 'card' }, form),
    h('p', { class: 'center muted' }, 'New here? ', h('a', { href: '#/register' }, 'Create your family account')));
}
