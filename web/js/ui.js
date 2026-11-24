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

// ---- formatting helpers shared by the chore screens ---------------------------------------------------

/** 10 points -> "10 pts"; 50 cents -> "$0.50"; nothing -> "". */
export function formatValue(value, currency) {
  if (value === null || value === undefined || !currency) return '';
  if (currency === 'money') return '$' + (value / 100).toFixed(2);
  return `${value} pt${value === 1 ? '' : 's'}`;
}

/** Cents -> "0.50" for a form field. */
export function centsToInput(cents) {
  return (cents / 100).toFixed(2);
}

/** "0.5" or "12.50" -> cents (50, 1250); null if it isn't a valid amount. */
export function dollarsToCents(text) {
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(text.trim());
  if (!match) return null;
  return parseInt(match[1], 10) * 100 + parseInt((match[2] || '').padEnd(2, '0') || '0', 10);
}

/** "Earned today" line: "10 pts · $0.50", or "Nothing yet". */
export function earnedText(earned) {
  const parts = [];
  if (earned.points) parts.push(formatValue(earned.points, 'points'));
  if (earned.money) parts.push(formatValue(earned.money, 'money'));
  return parts.length ? parts.join(' · ') : 'Nothing yet';
}

/** '18:00' -> '6:00 PM' */
export function formatClock(hhmm) {
  const [h24, m] = hhmm.split(':').map(Number);
  return `${((h24 + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h24 < 12 ? 'AM' : 'PM'}`;
}

/** '2026-12-01 15:12:00' (household-local) -> '3:12 PM' */
export function formatLocalTime(sql) {
  return formatClock(sql.slice(11, 16));
}

function dateFromDay(day) {
  const [y, m, d] = day.split('-').map(Number);
  return new Date(y, m - 1, d);
}

/** "Due Sun" if it's within the coming week, otherwise "Due Dec 20". `today` is the household's day. */
export function formatDue(dueDate, today) {
  const days = Math.round((dateFromDay(dueDate) - dateFromDay(today)) / 86400000);
  const date = dateFromDay(dueDate);
  if (days >= 0 && days < 7) return 'Due ' + date.toLocaleDateString(undefined, { weekday: 'short' });
  return 'Due ' + date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** [1,2,3,4,5,6,7] -> "Every day"; [1,2,3,4,5] -> "Weekdays"; else "Mon, Wed". */
export function describeDays(days) {
  if (days.length === 7) return 'Every day';
  if (days.join() === '1,2,3,4,5') return 'Weekdays';
  if (days.join() === '6,7') return 'Weekends';
  return days.map((d) => DAY_NAMES[d - 1]).join(', ');
}

/** A small pop-up asking for an optional note. Resolves to the text ('' is fine) or null if cancelled. */
export function askNote(title, placeholder, buttonLabel = 'Send back') {
  return new Promise((resolve) => {
    const dialog = h('dialog', {});
    const input = h('input', { maxlength: 200, placeholder, autocomplete: 'off' });
    let answer = null;
    const form = h('form', { method: 'dialog' },
      h('h2', {}, title),
      input,
      h('div', { class: 'row', style: 'margin-top:16px' },
        h('button', { type: 'submit', onclick: () => { answer = input.value.trim(); } }, buttonLabel),
        h('button', { type: 'button', class: 'secondary', onclick: () => dialog.close() }, 'Cancel')));
    dialog.append(form);
    dialog.addEventListener('close', () => { dialog.remove(); resolve(answer); });
    document.body.append(dialog);
    dialog.showModal();
    input.focus();
  });
}

/** '2026-12-01' -> "Tue, Dec 1" */
export function formatDay(day) {
  return dateFromDay(day).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

/** The two currencies side by side: "10 pts · $0.50" (or "0 pts" when both are zero). */
export function balanceText(amounts) {
  const parts = [];
  if (amounts.points) parts.push(formatValue(amounts.points, 'points'));
  if (amounts.money) parts.push(formatValue(amounts.money, 'money'));
  return parts.length ? parts.join(' · ') : '0';
}

/** '2026-12-01 23:59:00' (a household-local or UTC SQL time) -> "Tue, Dec 1 · 11:59 PM" */
export function formatSqlMoment(sql) {
  return `${formatDay(sql.slice(0, 10))} · ${formatLocalTime(sql)}`;
}

// ---- Phase 4 helpers: amounts, progress, links, form dialogs ---------------------------------------------

/** What a person types for an amount -> whole points, or cents for money. Null if it isn't valid. */
export function parseAmount(text, currency) {
  const t = String(text).trim();
  if (currency === 'money') return dollarsToCents(t);
  return /^\d+$/.test(t) ? Number(t) : null;
}

/** A cents/points amount back into what a person types: "5" points, "0.50" dollars. */
export function amountToInput(amount, currency) {
  return currency === 'money' ? centsToInput(amount) : String(amount);
}

/** A progress bar: value out of target. */
export function progress(value, target) {
  const pct = target > 0 ? Math.max(0, Math.min(100, Math.round((value / target) * 100))) : 0;
  return h('div', { class: 'progress' + (pct >= 100 ? ' done' : ''), role: 'progressbar', 'aria-valuenow': pct, 'aria-valuemin': 0, 'aria-valuemax': 100 },
    h('div', { class: 'bar', style: `width:${pct}%` }));
}

/**
 * A goal link shown as the site name (e.g. "dickssportinggoods.com"). It opens in a new tab and is never fetched or
 * previewed by us. Only http/https links ever get here (the server refuses anything else), and we check again.
 */
export function siteLink(url, host) {
  if (!url || !/^https?:\/\//i.test(url)) return null;
  return h('a', { class: 'sitelink', href: url, target: '_blank', rel: 'noopener noreferrer' }, `🔗 ${host || url}`);
}

/**
 * A pop-up form. fields: [{ name, label, type: 'text'|'number'|'select'|'checks'|'datetime-local'|'time', value, placeholder, options: [[value, label]] }].
 * `onSubmit(values)` runs when the form is sent; if it throws, the message shows in the dialog and it stays open.
 * Resolves true after a successful submit, false if cancelled.
 */
export function formDialog({ title, intro, fields, submitLabel = 'Save', onSubmit }) {
  return new Promise((resolve) => {
    const dialog = h('dialog', {});
    const error = errorBox();
    let result = false;
    const controls = fields.map((f) => {
      if (f.type === 'select') {
        const el = h('select', { name: f.name }, f.options.map(([v, l]) => h('option', { value: v, selected: String(v) === String(f.value) }, l)));
        return { f, el, read: () => el.value, node: field(f.label, el) };
      }
      if (f.type === 'checks') {
        const boxes = f.options.map(([v, l]) => h('label', {}, h('input', { type: 'checkbox', value: String(v), checked: (f.value || []).map(String).includes(String(v)), style: 'width:auto' }), ' ', l));
        return { f, read: () => boxes.map((b) => b.querySelector('input')).filter((i) => i.checked).map((i) => i.value),
          node: h('div', {}, h('label', {}, f.label), h('div', { class: 'checks' }, boxes), f.hint ? h('p', { class: 'hint', style: 'margin:4px 0 0' }, f.hint) : null) };
      }
      const el = h('input', { name: f.name, type: f.type || 'text', value: f.value ?? '', placeholder: f.placeholder || '', autocomplete: 'off', inputmode: f.inputmode, maxlength: f.maxlength });
      return { f, el, read: () => el.value, node: field(f.label, el) };
    });
    const form = h('form', {},
      h('h2', {}, title), intro ? h('p', { class: 'muted' }, intro) : null,
      controls.map((c) => c.node), error,
      h('div', { class: 'row', style: 'margin-top:16px' },
        h('button', { type: 'submit' }, submitLabel),
        h('button', { type: 'button', class: 'secondary', onclick: () => dialog.close() }, 'Cancel')));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearError(error);
      const button = form.querySelector('button[type=submit]');
      button.disabled = true;
      try {
        const values = {};
        controls.forEach((c) => { values[c.f.name] = c.read(); });
        await onSubmit(values);
        result = true;
        dialog.close();
      } catch (err) {
        showError(error, err);
      } finally {
        button.disabled = false;
      }
    });
    dialog.append(form);
    dialog.addEventListener('close', () => { dialog.remove(); resolve(result); });
    document.body.append(dialog);
    dialog.showModal();
    controls.find((c) => c.el)?.el.focus();
  });
}

/** '2026-12-04 17:00:00' -> "Fri 5:00 PM" (for deadlines like "by Fri 5:00 PM"). */
export function formatWhen(sql) {
  const [y, m, d] = sql.slice(0, 10).split('-').map(Number);
  return `${new Date(y, m - 1, d).toLocaleDateString(undefined, { weekday: 'short' })} ${formatLocalTime(sql)}`;
}

/** Spending-style text for a map of currency -> amount, only for the currencies given: "12 pts · $4.50". */
export function amountsText(map) {
  const parts = Object.entries(map).map(([currency, amount]) => formatValue(amount, currency));
  return parts.length ? parts.join(' · ') : '';
}
