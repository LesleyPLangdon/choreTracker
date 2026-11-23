import { api, session } from '../api.js';
import { h, mount, field, errorBox, onSubmit, timezoneSelect, browserTimezone } from '../ui.js';

export function render(root) {
  const error = errorBox();
  const form = h('form', {},
    field('Your name', h('input', { name: 'display_name', autocomplete: 'name', required: true, maxlength: 60 })),
    field('Family name', h('input', { name: 'household_name', placeholder: 'The Smiths', required: true, maxlength: 100 })),
    field('Email', h('input', { type: 'email', name: 'email', autocomplete: 'username', required: true })),
    field('Password (at least 10 characters)', h('input', { type: 'password', name: 'password', autocomplete: 'new-password', required: true, minlength: 10 })),
    field('Time zone', timezoneSelect('timezone', browserTimezone())),
    error,
    h('button', { type: 'submit', class: 'block' }, 'Create account'));

  onSubmit(form, error, async (data) => {
    const result = await api('POST', '/register', Object.fromEntries(data));
    session.setParentToken(result.token);
    location.hash = '#/kids';
  });

  mount(root,
    h('h1', {}, 'Create your family account'),
    h('p', { class: 'muted' }, 'You\'ll add your kids next.'),
    h('div', { class: 'card' }, form),
    h('p', { class: 'center muted' }, 'Already have an account? ', h('a', { href: '#/login' }, 'Sign in')));
}
