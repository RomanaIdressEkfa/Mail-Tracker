// Mail Track background worker: talks to the server, keeps a cache of tracked emails for the
// Gmail checkmarks, retries registrations that failed, and raises a notification on each new open.
import { getAuth, getSettings, normalizeServer } from './shared.js';

const SYNC_ALARM = 'mt-sync';
const MAX_ACTIVITY = 50;

chrome.runtime.onInstalled.addListener(() => {
  chrome.alarms.create(SYNC_ALARM, { periodInMinutes: 1 });
  blockOwnPixel();
});
chrome.runtime.onStartup.addListener(() => {
  chrome.alarms.create(SYNC_ALARM, { periodInMinutes: 1 });
  blockOwnPixel();
});
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && changes.settings) blockOwnPixel();
});

// The sender's own Gmail tab must never load the pixel: it would load the moment the pixel is
// added on Send, and whenever the sender looks at the email. Recipients are unaffected.
const BLOCK_RULE_ID = 1;
async function blockOwnPixel() {
  const server = normalizeServer((await getSettings()).server);
  await chrome.declarativeNetRequest.updateDynamicRules({
    removeRuleIds: [BLOCK_RULE_ID],
    addRules: /^https?:\/\//.test(server) ? [{
      id: BLOCK_RULE_ID,
      priority: 1,
      action: { type: 'block' },
      condition: { urlFilter: `|${server}/o/`, initiatorDomains: ['mail.google.com'], resourceTypes: ['image'] },
    }] : [],
  });
}
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === SYNC_ALARM) sync().catch(() => {});
});

async function api(path, { method = 'GET', body, auth = true } = {}) {
  const settings = await getSettings();
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth) {
    const a = await getAuth();
    if (!a) throw new Error('Not signed in');
    headers.Authorization = `Bearer ${a.token}`;
  }
  const res = await fetch(normalizeServer(settings.server) + '/api' + path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && auth) {
    await chrome.storage.local.remove(['auth', 'emails', 'activity']);
    throw new Error('Signed out: please sign in again');
  }
  if (!res.ok) {
    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
    throw new Error(first || data.message || `Server error ${res.status}`);
  }
  return data;
}

// ---- Tracked email registrations (queued so a network hiccup never loses one) ----

async function enqueue(payload) {
  const { queue = [] } = await chrome.storage.local.get('queue');
  queue.push({ payload, tries: 0 });
  await chrome.storage.local.set({ queue });
  // Show the ✓ in Gmail straight away, before the server answers.
  await mergeEmails([{ ...payload, opens: 0, clicks: 0, pending: true }]);
  await flushQueue();
}

async function flushQueue() {
  const { queue = [] } = await chrome.storage.local.get('queue');
  if (!queue.length) return;
  const left = [];
  for (const item of queue) {
    try {
      const { email } = await api('/emails', { method: 'POST', body: item.payload });
      await mergeEmails([email]);
    } catch (e) {
      // Give up after about a day of one-minute retries.
      if (++item.tries < 1440) left.push(item);
    }
  }
  await chrome.storage.local.set({ queue: left });
}

async function mergeEmails(list) {
  const { emails = {} } = await chrome.storage.local.get('emails');
  for (const e of list) emails[e.token] = { ...(emails[e.token] || {}), ...e, pending: !!e.pending };
  await chrome.storage.local.set({ emails });
}

// ---- Periodic sync ----

async function sync() {
  if (!(await getAuth())) return;
  await flushQueue();

  const { emails: list } = await api('/emails?limit=300');
  const { emails: cached = {} } = await chrome.storage.local.get('emails');
  const emails = {};
  for (const e of list) emails[e.token] = e;
  // Keep local entries the server hasn't confirmed yet.
  for (const [token, e] of Object.entries(cached)) if (e.pending && !emails[token]) emails[token] = e;
  await chrome.storage.local.set({ emails, lastSync: new Date().toISOString() });

  await pullActivity();
}

async function pullActivity() {
  const { activitySince, activity = [] } = await chrome.storage.local.get(['activitySince', 'activity']);
  const query = activitySince ? `?since=${encodeURIComponent(activitySince)}&limit=50` : '?limit=30';
  const { events, server_time } = await api('/activity' + query);

  // First run: fill the feed without a burst of old notifications.
  const fresh = activitySince ? events : [];
  const merged = [...events, ...activity].slice(0, MAX_ACTIVITY);
  const { unseen = 0 } = await chrome.storage.local.get('unseen');
  await chrome.storage.local.set({
    activity: merged,
    activitySince: events[0]?.at || activitySince || server_time,
    unseen: unseen + fresh.length,
  });
  await updateBadge(unseen + fresh.length);

  const settings = await getSettings();
  if (settings.notify) fresh.slice(0, 5).forEach(notify);
}

function notify(ev) {
  const who = ev.email.recipients?.[0] || 'Someone';
  const what = ev.type === 'click' ? 'clicked a link in' : 'opened';
  const times = ev.type === 'open' && ev.email.opens > 1 ? ` (${ordinal(ev.email.opens)} time)` : '';
  chrome.notifications.create(`mt-${ev.email.token}-${ev.at}`, {
    type: 'basic',
    iconUrl: 'icons/icon128.png',
    title: `${who} ${what} your email${times}`,
    message: ev.email.subject || '(no subject)',
    priority: 1,
  });
}

function ordinal(n) {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

async function updateBadge(count) {
  await chrome.action.setBadgeBackgroundColor({ color: '#0f9d6b' });
  await chrome.action.setBadgeText({ text: count > 0 ? String(Math.min(count, 99)) : '' });
}

chrome.notifications.onClicked.addListener(() => {
  chrome.tabs.create({ url: 'https://mail.google.com/mail/u/0/#sent' });
});

// ---- Messages from Gmail (content.js) and the popup ----

const handlers = {
  async register({ payload }) {
    await enqueue(payload);
    return { ok: true };
  },
  async suppress({ tokens }) {
    const { emails } = await api('/emails/suppress', { method: 'POST', body: { tokens } });
    await mergeEmails(emails);
    return { ok: true };
  },
  async detail({ token }) {
    return api(`/emails/${encodeURIComponent(token)}`);
  },
  async sync() {
    await sync();
    return { ok: true };
  },
  async stats() {
    return api('/stats');
  },
  async signIn({ mode, name, email, password }) {
    const body = { email, password, device_name: 'Chrome extension' };
    if (mode === 'register') body.name = name;
    const data = await api(mode === 'register' ? '/auth/register' : '/auth/login', { method: 'POST', body, auth: false });
    await chrome.storage.local.set({ auth: { token: data.token, user: data.user } });
    await chrome.storage.local.remove(['activitySince', 'activity', 'emails', 'unseen']);
    chrome.alarms.create(SYNC_ALARM, { periodInMinutes: 1 });
    await sync().catch(() => {});
    return { ok: true, user: data.user };
  },
  async signOut() {
    await api('/auth/logout', { method: 'POST' }).catch(() => {});
    await chrome.storage.local.remove(['auth', 'emails', 'activity', 'activitySince', 'unseen', 'queue']);
    await updateBadge(0);
    return { ok: true };
  },
  async seen() {
    await chrome.storage.local.set({ unseen: 0 });
    await updateBadge(0);
    return { ok: true };
  },
};

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  const handler = handlers[msg?.type];
  if (!handler) return false;
  handler(msg)
    .then((result) => sendResponse(result))
    .catch((e) => sendResponse({ error: e.message || String(e) }));
  return true; // keep the channel open for the async answer
});
