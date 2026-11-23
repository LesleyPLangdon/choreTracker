import { api } from '../api.js';
import { h, mount, field, errorBox, showError, clearError, toast, askNote, balanceText, dollarsToCents, formatDay, formatSqlMoment } from '../ui.js';
import { parentShell } from '../layout.js';
import { choreTitle, choreTags } from '../chore-parts.js';

const STATUS_LABEL = { unlocked: 'Unlocked', forfeited: 'Forfeited', waiting: 'Waiting for a parent', locked: 'Locked' };
const STATUS_CLASS = { unlocked: 'ok', forfeited: 'bad', waiting: 'late', locked: '' };
const ACTION_LABEL = {
  back_fill: 'filled in a chore', unlock: 'unlocked this day', forfeit: 'forfeited this day',
  undo_approval: 'undid an approval', clear_late: 'cleared a late flag',
};

/** A kid's last 7 days (plus today): status, chores, earnings — with back-fill, overrides, and balance adjustment. */
export async function render(root, kidId) {
  const error = errorBox();
  const body = h('div', {});
  const balanceBox = h('div', { class: 'card' });
  const titleBox = h('div', {});

  async function reload() {
    clearError(error);
    const [data, today] = await Promise.all([
      api('GET', `/kids/${kidId}/history`, undefined, { as: 'parent' }),
      api('GET', `/kids/${kidId}/today`, undefined, { as: 'parent' }),
    ]);
    mount(titleBox, h('div', { class: 'row', style: 'margin-bottom:10px' },
      h('div', { class: 'avatar' }, today.kid.avatar),
      h('div', { class: 'grow' }, h('h1', { style: 'margin:0' }, `${today.kid.nickname}'s history`))));
    paintBalance(data);
    mount(body, data.days.map((d) => dayCard(d, data.today)));
  }

  /** Runs an action, then reloads; errors show at the top. */
  const act = (fn) => async () => {
    try { await fn(); await reload(); } catch (err) { showError(error, err); window.scrollTo(0, 0); }
  };

  function paintBalance(data) {
    const adjustError = errorBox();
    const currency = h('select', {}, h('option', { value: 'points' }, 'Points'), h('option', { value: 'money' }, 'Money ($)'));
    const amount = h('input', { inputmode: 'decimal', placeholder: '5 or -5', autocomplete: 'off', 'aria-label': 'Amount' });
    const note = h('input', { maxlength: 200, placeholder: 'Why? (required)', autocomplete: 'off', 'aria-label': 'Note' });
    const form = h('form', {},
      h('div', { class: 'inline' }, currency, amount),
      field('Note', note),
      adjustError,
      h('button', { type: 'submit', class: 'block secondary' }, 'Adjust Spending'));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearError(adjustError);
      try {
        const text = amount.value.trim();
        const negative = text.startsWith('-');
        const unsigned = text.replace(/^[+-]/, '');
        let value;
        if (currency.value === 'points') {
          if (!/^\d+$/.test(unsigned)) throw new Error('Enter a whole number of points, like 5 or -5.');
          value = Number(unsigned);
        } else {
          value = dollarsToCents(unsigned);
          if (value === null) throw new Error('Enter dollars like 1.50 or -1.50.');
        }
        await api('POST', `/kids/${kidId}/adjust`, { currency: currency.value, amount: negative ? -value : value, note: note.value }, { as: 'parent' });
        toast('Spending adjusted');
        await reload();
      } catch (err) { showError(adjustError, err); }
    });
    mount(balanceBox,
      h('div', { class: 'balance' }, h('span', {}, 'Spending'), h('strong', {}, balanceText(data.spending))),
      data.adjustments.length
        ? h('div', { class: 'event' }, 'Recent adjustments: ' + data.adjustments.slice(0, 3).map((a) =>
            `${a.amount > 0 ? '+' : '−'}${a.currency === 'money' ? '$' + (Math.abs(a.amount) / 100).toFixed(2) : Math.abs(a.amount) + ' pts'} “${a.note}” (${a.by}, ${formatSqlMoment(a.at_local)})`).join('; '))
        : null,
      h('details', {}, h('summary', {}, 'Adjust balance'), form));
  }

  function dayCard(d, today) {
    const buttons = [];
    if (d.can_unlock) {
      buttons.push(h('button', {
        class: 'small',
        onclick: act(async () => {
          const note = await askNote(`Unlock ${formatDay(d.date)}`, 'Optional note', 'Unlock day');
          if (note === null) return;
          await api('POST', `/kids/${kidId}/days/${d.date}/unlock`, note ? { note } : {}, { as: 'parent' });
          toast('Day unlocked');
        }),
      }, 'Unlock day'));
    }
    if (d.can_forfeit) {
      buttons.push(h('button', {
        class: 'danger small',
        onclick: act(async () => {
          const note = await askNote(`Forfeit ${formatDay(d.date)}`, 'Optional note', 'Forfeit day');
          if (note === null) return;
          await api('POST', `/kids/${kidId}/days/${d.date}/forfeit`, note ? { note } : {}, { as: 'parent' });
          toast('Day forfeited');
        }),
      }, 'Forfeit day'));
    }

    const rows = d.chores.map((c) => {
      const actions = [];
      if (c.can_back_fill) {
        actions.push(h('button', {
          class: 'small',
          onclick: act(async () => {
            const note = await askNote(`Mark "${c.title}" done on ${formatDay(d.date)}`, 'Optional note, e.g. "Done, forgot to tap"', 'Mark done');
            if (note === null) return;
            await api('POST', `/kids/${kidId}/back-fill`, { slot_id: c.slot_id, date: d.date, ...(note ? { note } : {}) }, { as: 'parent' });
            toast('Filled in');
          }),
        }, 'Back-fill'));
      }
      if (c.can_undo_approval) {
        actions.push(h('button', {
          class: 'secondary small',
          onclick: act(async () => {
            const note = await askNote(`Undo approval of "${c.title}"`, 'Optional note', 'Undo approval');
            if (note === null) return;
            await api('POST', `/instances/${c.id}/undo-approval`, note ? { note } : {}, { as: 'parent' });
            toast('Approval undone');
          }),
        }, 'Undo approval'));
      }
      if (c.can_clear_late) {
        actions.push(h('button', {
          class: 'secondary small',
          onclick: act(async () => {
            const note = await askNote(`Clear the late flag on "${c.title}"`, 'Optional note', 'Clear late');
            if (note === null) return;
            await api('POST', `/instances/${c.id}/clear-late`, note ? { note } : {}, { as: 'parent' });
            toast('Late flag cleared');
          }),
        }, 'Clear late'));
      }
      const title = choreTitle(c, today);
      title.append(choreTags(c));
      return h('div', { class: 'chore' }, title, h('div', { class: 'actions' }, actions));
    });

    const events = d.events.map((e) => h('div', { class: 'event' },
      `${e.by || 'A parent'} ${ACTION_LABEL[e.action] || e.action}${e.note ? ` — “${e.note}”` : ''} · ${formatSqlMoment(e.at_local)}`));

    const locked = balanceText(d.locked);
    return h('div', { class: 'history-day' },
      h('div', { class: 'row wrap' },
        h('strong', { class: 'grow' }, d.is_today ? `Today · ${formatDay(d.date)}` : formatDay(d.date)),
        h('span', { class: `tag ${STATUS_CLASS[d.status]}` }, STATUS_LABEL[d.status] + (d.manual ? ' (by a parent)' : ''))),
      h('div', { class: 'muted' }, `Earned ${balanceText(d.earned)}` + (locked !== '0' ? ` · ${locked} locked` : '')),
      rows.length ? rows : h('div', { class: 'muted', style: 'margin:8px 0' }, 'No chores that day.'),
      events,
      buttons.length ? h('div', { class: 'stack-actions' }, buttons) : null);
  }

  mount(root, ...parentShell('/home',
    h('p', { style: 'margin:0 0 8px' }, h('a', { href: `#/day/${kidId}` }, '‹ Today')),
    titleBox, error, balanceBox,
    h('div', { class: 'card' }, h('h2', {}, 'Last 7 days'), body)));
  try { await reload(); } catch (err) { showError(error, err); }
  // (No auto-refresh here: it would wipe a half-typed adjustment. The page reloads after every action.)
}
