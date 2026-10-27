import { DEFAULT_SETTINGS, getAuth, getSettings, normalizeServer, timeAgo } from './shared.js';

const $ = (id) => document.getElementById(id);
let mode = 'login';

function send(message) {
  return new Promise((resolve) => chrome.runtime.sendMessage(message, (r) => resolve(r || {})));
}

async function render() {
  const auth = await getAuth();
  $('auth').hidden = !!auth;
  $('main').hidden = !auth;
  $('signOut').hidden = !auth;
  $('who').textContent = auth ? auth.user.email : '';
  if (auth) {
    send({ type: 'seen' });
    renderFeed();
    loadStats();
  }
}

async function loadStats() {
  const s = await send({ type: 'stats' });
  if (s.error) return;
  $('sSent').textContent = s.sent;
  $('sOpen').textContent = `${s.open_rate}%`;
  $('sClick').textContent = `${s.click_rate}%`;
}

async function renderFeed() {
  const { activity = [] } = await chrome.storage.local.get('activity');
  const feed = $('feed');
  feed.replaceChildren();
  if (!activity.length) {
    const li = document.createElement('li');
    li.className = 'empty';
    li.textContent = 'No opens yet. Send a tracked email from Gmail.';
    feed.append(li);
    return;
  }
  for (const ev of activity) {
    const who = ev.email.recipients?.[0] || 'Someone';
    const li = document.createElement('li');
    const avatar = document.createElement('div');
    avatar.className = 'avatar';
    avatar.textContent = who[0] || '?';
    const text = document.createElement('div');
    text.className = 'text';
    const line1 = document.createElement('div');
    line1.className = 'line1';
    const subj = document.createElement('span');
    subj.className = 'subj';
    subj.textContent = `“${ev.email.subject || '(no subject)'}”`;
    line1.append(`${who} ${ev.type === 'click' ? 'clicked a link in' : 'opened'} `, subj);
    const line2 = document.createElement('div');
    line2.className = 'line2';
    const nth = ev.type === 'open' && ev.email.opens > 1 ? ` · opened ${ev.email.opens} times` : '';
    line2.textContent = `${timeAgo(ev.at)} · ${ev.client || 'Unknown'}${nth}`;
    text.append(line1, line2);
    li.append(avatar, text);
    feed.append(li);
  }
}

function setMode(next) {
  mode = next;
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.mode === mode));
  $('nameRow').hidden = mode !== 'register';
  $('authBtn').textContent = mode === 'register' ? 'Create account' : 'Sign in';
  $('authForm').elements.password.autocomplete = mode === 'register' ? 'new-password' : 'current-password';
}

document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => setMode(t.dataset.mode)));

$('authForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target.elements;
  $('authError').hidden = true;
  $('authBtn').disabled = true;
  const r = await send({ type: 'signIn', mode, name: f.name.value || f.email.value.split('@')[0], email: f.email.value, password: f.password.value });
  $('authBtn').disabled = false;
  if (r.error) {
    $('authError').textContent = r.error;
    $('authError').hidden = false;
    return;
  }
  render();
});

$('refresh').addEventListener('click', async () => {
  $('refresh').textContent = '…';
  await send({ type: 'sync' });
  $('refresh').textContent = 'Refresh';
  renderFeed();
  loadStats();
});

$('signOut').addEventListener('click', async () => {
  await send({ type: 'signOut' });
  render();
});

async function loadSettings() {
  const s = await getSettings();
  $('trackByDefault').checked = s.trackByDefault;
  $('trackLinks').checked = s.trackLinks;
  $('notify').checked = s.notify;
  $('server').value = s.server;
  // Until a real server is set, keep settings open so it's easy to find.
  if (s.server === DEFAULT_SETTINGS.server) $('settingsBox').open = true;
}

$('save').addEventListener('click', async () => {
  const settings = {
    trackByDefault: $('trackByDefault').checked,
    trackLinks: $('trackLinks').checked,
    notify: $('notify').checked,
    server: normalizeServer($('server').value) || DEFAULT_SETTINGS.server,
  };
  await chrome.storage.local.set({ settings });
  $('saved').hidden = false;
  setTimeout(() => ($('saved').hidden = true), 1500);
});

chrome.storage.onChanged.addListener((changes) => {
  if (changes.activity) renderFeed();
});

setMode('login');
loadSettings();
render();
