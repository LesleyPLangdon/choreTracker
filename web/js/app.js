// Hash-based router. Each screen is a module in screens/ that exports render(root, params).
import { session } from './api.js';
import { refreshBadge } from './layout.js';
import { h, mount } from './ui.js';
import * as login from './screens/login.js';
import * as register from './screens/register.js';
import * as join from './screens/join.js';
import * as home from './screens/home.js';
import * as kids from './screens/kids.js';
import * as settings from './screens/settings.js';
import * as chores from './screens/chores.js';
import * as choreForm from './screens/chore-form.js';
import * as kidDay from './screens/kid-day.js';
import * as kidPin from './screens/kid-pin.js';
import * as kidHome from './screens/kid-home.js';

// [pattern, screen, who]  who: 'parent' needs a parent sign-in, 'kid' needs a kid sign-in, null = public
const routes = [
  [/^\/login$/, login, null],
  [/^\/register$/, register, null],
  [/^\/join\/([^/]+)$/, join, null],
  [/^\/home$/, home, 'parent'],
  [/^\/kids$/, kids, 'parent'],
  [/^\/settings$/, settings, 'parent'],
  [/^\/chores$/, chores, 'parent'],
  [/^\/chores\/new$/, choreForm, 'parent'],
  [/^\/chores\/edit\/(\d+)$/, choreForm, 'parent'],
  [/^\/day\/(\d+)$/, kidDay, 'parent'],
  [/^\/k\/([^/]+)$/, kidPin, null],
  [/^\/kid$/, kidHome, 'kid'],
];

const root = document.getElementById('app');

// A screen's render() may return a function that reloads just its data (without wiping forms).
// We call it when the app comes back to the foreground and about once a minute while it is visible.
let refreshScreen = null;

function currentPath() {
  return location.hash.replace(/^#/, '') || '/';
}

export function go(path) {
  location.hash = path;
}

async function route() {
  let path = currentPath();

  // The bare address: send people where they belong.
  if (path === '/') {
    if (session.parentToken()) path = '/home';
    else if (session.kidToken()) path = '/kid';
    else if (session.kidLink()) path = '/k/' + session.kidLink();
    else path = '/login';
    history.replaceState(null, '', '#' + path);
  }

  for (const [pattern, screen, who] of routes) {
    const match = path.match(pattern);
    if (!match) continue;
    if (who === 'parent' && !session.parentToken()) return go('/login');
    if (who === 'kid' && !session.kidToken()) {
      return go(session.kidLink() ? '/k/' + session.kidLink() : '/login');
    }
    const params = match.slice(1).map(decodeURIComponent);
    mount(root, h('p', { class: 'loading' }, 'Loading…'));
    refreshScreen = null;
    try {
      const result = await screen.render(root, ...params);
      refreshScreen = typeof result === 'function' ? result : null;
    } catch (err) {
      mount(root, h('div', { class: 'card' }, h('h2', {}, 'Oops'), h('p', {}, err.message || 'Something went wrong.'),
        h('a', { class: 'btn', href: '#/' }, 'Back')));
    }
    window.scrollTo(0, 0);
    return;
  }
  mount(root, h('div', { class: 'card' }, h('h2', {}, 'Page not found'), h('a', { class: 'btn', href: '#/' }, 'Go home')));
}

function refreshNow() {
  if (document.hidden) return;
  if (refreshScreen) Promise.resolve(refreshScreen()).catch(() => { /* keep showing what we have */ });
  else if (session.parentToken() && currentPath() !== '/login') refreshBadge();
}
document.addEventListener('visibilitychange', refreshNow);
window.addEventListener('focus', refreshNow);
setInterval(refreshNow, 60000);

window.addEventListener('hashchange', route);
window.addEventListener('ct:signed-out', (event) => {
  if (event.detail === 'kid') go(session.kidLink() ? '/k/' + session.kidLink() : '/login');
  else go('/login');
});
route();
