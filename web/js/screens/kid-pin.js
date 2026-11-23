import { api, session } from '../api.js';
import { h, mount } from '../ui.js';

/** The kid opens their link and types their PIN on a keypad. */
export async function render(root, linkToken) {
  let preview;
  try {
    preview = await api('GET', '/kid/link/' + encodeURIComponent(linkToken));
  } catch (err) {
    if (session.kidLink() === linkToken) session.setKidLink(null);
    return mount(root, h('div', { class: 'card center' }, h('h2', {}, 'Link not working'), h('p', {}, err.message)));
  }
  session.setKidLink(linkToken); // so the home-screen shortcut knows where to go next time

  let pin = '';
  const dots = h('div', { class: 'dots', 'aria-hidden': 'true' });
  const message = h('p', { class: 'center', role: 'alert', style: 'min-height:1.5em' });
  const buttons = [];

  function paint() {
    dots.replaceChildren(...Array.from({ length: Math.max(4, pin.length) }, (_, i) => h('i', { class: i < pin.length ? 'on' : '' })));
  }
  function say(text, bad = true) {
    message.textContent = text;
    message.style.color = bad ? 'var(--danger)' : 'var(--muted)';
  }
  function setBusy(busy) { buttons.forEach((b) => { b.disabled = busy; }); }

  async function submit() {
    if (pin.length < 4) return say('Type your PIN first.');
    setBusy(true);
    try {
      const result = await api('POST', '/kid/login', { link_token: linkToken, pin });
      session.setKidToken(result.token);
      location.hash = '#/kid';
    } catch (err) {
      pin = '';
      paint();
      let text = err.message;
      if (err.status === 401 && err.data.attempts_left !== undefined) text += ` (${err.data.attempts_left} tries left)`;
      if (err.status === 429) text = `${err.message}`;
      say(text);
      setBusy(err.status === 429);
    } finally {
      if (!(message.textContent.startsWith('Too many'))) setBusy(false);
    }
  }

  function key(label, handler, cls = '') {
    const b = h('button', { type: 'button', class: cls, onclick: handler }, label);
    buttons.push(b);
    return b;
  }
  const pad = h('div', { class: 'keypad' },
    ['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => key(d, () => { if (pin.length < 6) { pin += d; paint(); say(''); } })),
    key('⌫', () => { pin = pin.slice(0, -1); paint(); }),
    key('0', () => { if (pin.length < 6) { pin += '0'; paint(); say(''); } }),
    key('Go', submit, 'go'));

  paint();
  if (preview.kid.locked) { say('Too many wrong tries. Please wait a bit, or ask a parent to unlock you.'); setBusy(true); }

  mount(root, h('div', { class: 'center' },
    h('div', { class: 'avatar big' }, preview.kid.avatar),
    h('h1', {}, preview.kid.nickname),
    h('p', { class: 'muted' }, 'Type your PIN'),
    dots, message, pad));
}
