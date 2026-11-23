// Small helpers shared by all screens. No framework: h() builds DOM elements safely (text is never parsed as HTML).

export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === false || value === null || value === undefined) continue;
    if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key === 'class') el.className = value;
    else if (key === 'value') el.value = value;
    else if (value === true) el.setAttribute(key, '');
    else el.setAttribute(key, value);
  }
  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return el;
}

export function mount(root, ...nodes) {
  root.replaceChildren(...nodes.flat().filter(Boolean));
}

let toastTimer;
export function toast(message) {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2400);
}

export function errorBox() {
  return h('div', { class: 'error', role: 'alert', hidden: true });
}
export function showError(box, err) {
  box.textContent = err instanceof Error ? err.message : String(err);
  box.hidden = false;
}
export function clearError(box) {
  box.hidden = true;
}

/** Wraps a form submit: disables the button, shows errors in `box`, runs `fn`. */
export function onSubmit(form, box, fn) {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearError(box);
    const button = form.querySelector('button[type=submit]');
    if (button) button.disabled = true;
    try {
      await fn(new FormData(form));
    } catch (err) {
      showError(box, err);
    } finally {
      if (button) button.disabled = false;
    }
  });
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    toast('Copied!');
  } catch {
    // Older browsers / non-secure pages: fall back to selecting a temporary field.
    const input = h('input', { value: text });
    document.body.append(input);
    input.select();
    try { document.execCommand('copy'); toast('Copied!'); } catch { toast('Press and hold the link to copy it.'); }
    input.remove();
  }
}

export function field(label, input) {
  return h('div', {}, h('label', {}, label), input);
}

const COMMON_ZONES = [
  'America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles',
  'America/Anchorage', 'Pacific/Honolulu', 'America/Toronto', 'America/Vancouver', 'Europe/London',
  'Europe/Paris', 'Europe/Berlin', 'Asia/Tokyo', 'Australia/Sydney', 'UTC',
];

export function timezoneSelect(name, selected) {
  let zones = COMMON_ZONES;
  try {
    if (Intl.supportedValuesOf) zones = Intl.supportedValuesOf('timeZone');
  } catch { /* use the short list */ }
  if (!zones.includes('UTC')) zones = [...zones, 'UTC'];
  if (selected && !zones.includes(selected)) zones = [selected, ...zones];
  return h('select', { name, required: true },
    zones.map((z) => h('option', { value: z, selected: z === selected }, z.replaceAll('_', ' '))));
}

export function browserTimezone() {
  try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/New_York'; } catch { return 'America/New_York'; }
}

export function appBaseUrl() {
  return location.origin + location.pathname.replace(/index\.html$/, '');
}
