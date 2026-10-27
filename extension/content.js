// Mail Track inside Gmail:
//  1. adds a "Track" toggle next to every Send button,
//  2. on Send, adds an invisible pixel (and tracked links) to the email and registers it,
//  3. shows ✓ / ✓✓ next to tracked emails in the list and a status bar in opened threads,
//  4. tells the server when you view your own email, so your views don't count as opens.
(() => {
  const DEFAULTS = { server: 'http://127.0.0.1:8000', trackByDefault: true, trackLinks: true };
  let settings = { ...DEFAULTS };
  let signedIn = false;
  let emails = {}; // token -> summary
  let index = new Map(); // normalized subject -> [summary]
  const suppressedAt = new Map(); // token -> ms of last suppress call

  const SEND_SELECTOR = 'div[role="button"].T-I.aoO';
  const BODY_SELECTOR = 'div[contenteditable="true"][role="textbox"], div.editable[contenteditable="true"]';

  // ---------- state ----------

  function server() {
    return String(settings.server || '').trim().replace(/\/+$/, '');
  }

  function alive() {
    return !!(chrome.runtime && chrome.runtime.id); // false after the extension is reloaded
  }

  async function load() {
    const data = await chrome.storage.local.get(['settings', 'auth', 'emails']);
    settings = { ...DEFAULTS, ...(data.settings || {}) };
    signedIn = !!data.auth;
    setEmails(data.emails || {});
  }

  function setEmails(map) {
    emails = map;
    index = new Map();
    for (const e of Object.values(map)) {
      const key = normSubject(e.subject);
      if (!index.has(key)) index.set(key, []);
      index.get(key).push(e);
    }
  }

  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local') return;
    if (changes.settings) settings = { ...DEFAULTS, ...(changes.settings.newValue || {}) };
    if (changes.auth) signedIn = !!changes.auth.newValue;
    if (changes.emails) setEmails(changes.emails.newValue || {});
    scheduleRender();
  });

  function send(message) {
    if (!alive()) return Promise.resolve({ error: 'Extension reloaded: refresh Gmail' });
    return new Promise((resolve) => chrome.runtime.sendMessage(message, (r) => resolve(r || {})));
  }

  // ---------- helpers ----------

  function normSubject(s) {
    let t = String(s || '').trim();
    while (/^(re|fwd?|aw|sv|vs|tr)\s*:\s*/i.test(t)) t = t.replace(/^(re|fwd?|aw|sv|vs|tr)\s*:\s*/i, '');
    return t.replace(/\s+/g, ' ').toLowerCase();
  }

  function newToken() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function timeAgo(iso) {
    if (!iso) return '';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    for (const [n, size] of [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]]) {
      const v = Math.floor(s / size);
      if (v >= 1) return `${v} ${n}${v > 1 ? 's' : ''} ago`;
    }
    return 'just now';
  }

  function when(iso) {
    return iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
  }

  // Only touch the DOM when something changed: every write re-triggers the MutationObserver.
  function setText(node, text) {
    if (node.textContent !== text) node.textContent = text;
  }

  function setTitle(node, title) {
    if (node.title !== title) node.title = title;
  }

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  // Combines several tracked emails (e.g. a follow-up in the same thread) into one status.
  function combine(list) {
    const out = { opens: 0, clicks: 0, last_opened_at: null, last_clicked_at: null, tokens: [], pending: false };
    for (const e of list) {
      out.opens += e.opens || 0;
      out.clicks += e.clicks || 0;
      if (e.last_opened_at && (!out.last_opened_at || e.last_opened_at > out.last_opened_at)) out.last_opened_at = e.last_opened_at;
      if (e.last_clicked_at && (!out.last_clicked_at || e.last_clicked_at > out.last_clicked_at)) out.last_clicked_at = e.last_clicked_at;
      out.tokens.push(e.token);
      out.pending = out.pending || !!e.pending;
    }
    return out;
  }

  function describe(s) {
    if (s.opens === 0 && s.clicks === 0) return s.pending ? 'Tracked · waiting for the server' : 'Sent · not viewed yet';
    const parts = [`Viewed ${s.opens} time${s.opens === 1 ? '' : 's'}`];
    if (s.last_opened_at) parts.push(`last ${timeAgo(s.last_opened_at)}`);
    if (s.clicks) parts.push(`${s.clicks} link click${s.clicks === 1 ? '' : 's'}`);
    return parts.join(' · ');
  }

  function findByThread(subject, participants) {
    const list = index.get(normSubject(subject)) || [];
    if (!participants || !participants.length) return list;
    const set = new Set(participants.map((p) => p.toLowerCase()));
    // Same subject and a shared recipient; emails saved without recipients match on subject alone.
    return list.filter((e) => !(e.recipients || []).length || e.recipients.some((r) => set.has(r)));
  }

  // ---------- compose: toggle + send ----------

  // The whole compose window: body, Send button, and the To/Subject fields above them.
  // The smallest ancestor holding the body is not enough, the To/Subject fields sit higher up.
  function composeRoot(sendButton) {
    let withBody = null;
    let node = sendButton.parentElement;
    for (let i = 0; node && node !== document.body && i < 40; i++, node = node.parentElement) {
      if (node.querySelectorAll(SEND_SELECTOR).length > 1) break; // reached a container of several composes
      if (!withBody && node.querySelector(BODY_SELECTOR)) withBody = node;
      if (withBody && node.querySelector('input[name="subjectbox"], [name="to"], div[aria-label="To"], div[aria-label^="To recipients"]')) {
        return node;
      }
    }
    return withBody;
  }

  function setupCompose(sendButton) {
    if (sendButton.dataset.mtReady) return;
    const root = composeRoot(sendButton);
    if (!root) return;
    sendButton.dataset.mtReady = '1';

    if (root.dataset.mtTrack === undefined) root.dataset.mtTrack = settings.trackByDefault ? 'on' : 'off';

    const toggle = el('div', 'mt-toggle');
    toggle.setAttribute('role', 'button');
    toggle.tabIndex = 0;
    const paint = () => {
      const on = root.dataset.mtTrack === 'on' && signedIn;
      toggle.classList.toggle('mt-on', on);
      setText(toggle, on ? '✓✓ Tracking' : '✓✓ Not tracked');
      setTitle(toggle, signedIn
        ? on ? 'Mail Track will track opens and clicks. Click to turn off for this email.' : 'Click to track this email.'
        : 'Sign in to Mail Track (click the extension icon) to track emails.');
    };
    const flip = (e) => {
      e.preventDefault();
      e.stopPropagation();
      root.dataset.mtTrack = root.dataset.mtTrack === 'on' ? 'off' : 'on';
      paint();
    };
    toggle.addEventListener('click', flip);
    toggle.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && flip(e));
    paint();
    root.mtPaint = paint;

    const holder = sendButton.closest('td') || sendButton.parentElement;
    holder.parentElement.insertBefore(wrapCell(holder, toggle), holder.nextSibling);

    // Capture phase on the compose root runs before Gmail's own handlers on the button.
    const beforeSend = () => prepareSend(root);
    root.addEventListener('mousedown', (e) => e.target.closest(SEND_SELECTOR) && beforeSend(), true);
    root.addEventListener('click', (e) => e.target.closest(SEND_SELECTOR) && beforeSend(), true);
    root.addEventListener('keydown', (e) => (e.ctrlKey || e.metaKey) && e.key === 'Enter' && beforeSend(), true);
  }

  function wrapCell(holder, toggle) {
    // The Send button sits in a table cell; keep the toolbar layout by adding a sibling cell.
    if (holder.tagName === 'TD') {
      const td = document.createElement('td');
      td.className = 'mt-cell';
      td.appendChild(toggle);
      return td;
    }
    return toggle;
  }

  function recipientsOf(root, body) {
    const found = new Set();
    root.querySelectorAll('[data-hovercard-id], [email], input[name="to"], input[name="cc"], input[name="bcc"]').forEach((n) => {
      if (body.contains(n)) return;
      const v = n.getAttribute('data-hovercard-id') || n.getAttribute('email') || n.value || '';
      v.split(/[,;\s]+/).forEach((part) => {
        const m = part.match(/[^\s<>"]+@[^\s<>"]+\.[^\s<>"]+/);
        if (m) found.add(m[0].toLowerCase());
      });
    });
    return [...found];
  }

  function subjectOf(root) {
    const input = root.querySelector('input[name="subjectbox"]');
    if (input && input.value) return input.value;
    const h2 = document.querySelector('h2.hP');
    return h2 ? h2.textContent : '';
  }

  function prepareSend(root) {
    if (!signedIn || root.dataset.mtTrack !== 'on' || !alive()) return;
    const body = root.querySelector(BODY_SELECTOR);
    const base = server();
    if (!body || !/^https?:\/\//.test(base)) return;
    if (body.querySelector(`img[src^="${base}/o/"]`)) return; // already prepared (mousedown + click)

    const token = newToken();
    const links = [];
    if (settings.trackLinks) {
      body.querySelectorAll('a[href]').forEach((a) => {
        const href = a.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href) || href.startsWith(base) || a.closest('.gmail_quote') || links.length >= 50) return;
        const position = links.length;
        links.push({ position, url: href });
        a.setAttribute('href', `${base}/c/${token}/${position}?u=${encodeURIComponent(href)}`);
      });
    }

    const pixel = document.createElement('img');
    pixel.src = `${base}/o/${token}.gif`;
    pixel.width = 1;
    pixel.height = 1;
    pixel.alt = '';
    pixel.setAttribute('style', 'width:1px;height:1px;border:0;opacity:0');
    const quote = body.querySelector('.gmail_quote');
    if (quote) quote.parentNode.insertBefore(pixel, quote);
    else body.appendChild(pixel);
    body.dispatchEvent(new Event('input', { bubbles: true }));

    send({
      type: 'register',
      payload: { token, subject: subjectOf(root), recipients: recipientsOf(root, body), sent_at: new Date().toISOString(), links },
    });
  }

  // ---------- list view: ✓ / ✓✓ ----------

  function badgeText(s) {
    if (s.opens > 0) return `✓✓ ${s.opens} view${s.opens === 1 ? '' : 's'}`;
    if (s.clicks > 0) return `✓✓ ${s.clicks} click${s.clicks === 1 ? '' : 's'}`;
    return '✓ Sent';
  }

  function renderRows() {
    document.querySelectorAll('tr.zA').forEach((row) => {
      const subjectEl = row.querySelector('span.bog');
      if (!subjectEl) return;
      const people = [...row.querySelectorAll('[email]')].map((n) => n.getAttribute('email'));
      const list = findByThread(subjectEl.textContent, people);
      let badge = row.querySelector('.mt-badge');
      if (!list.length) {
        badge?.remove();
        return;
      }
      const s = combine(list);
      if (!badge) {
        badge = el('span', 'mt-badge');
        badge.setAttribute('role', 'button');
        // Clicking the badge shows the view history instead of opening the email.
        badge.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          showPopover(badge, badge.dataset.tokens.split(','));
        });
        badge.addEventListener('mousedown', (e) => e.stopPropagation());
        // Right side of the row, just before the date.
        const dateCell = row.querySelector('td.xW');
        if (dateCell) dateCell.insertBefore(badge, dateCell.firstChild);
        else subjectEl.parentElement.append(badge);
      }
      badge.dataset.tokens = s.tokens.join(',');
      badge.classList.toggle('mt-opened', s.opens > 0 || s.clicks > 0);
      setText(badge, badgeText(s));
      setTitle(badge, `Mail Track: ${describe(s)}. Click for history.`);
    });
  }

  // ---------- view history (popover from the list, panel in the thread) ----------

  async function fillHistory(box, tokens) {
    box.replaceChildren(el('div', 'mt-history-row', 'Loading…'));
    const results = await Promise.all(tokens.map((token) => send({ type: 'detail', token })));
    box.replaceChildren();
    const error = results.find((r) => r.error);
    if (error) box.append(el('div', 'mt-history-row', error.error));
    const rows = results.flatMap((r) => r.events || []).sort((a, b) => (a.at < b.at ? 1 : -1));
    const counted = rows.filter((ev) => !ev.is_self && !ev.is_automatic);
    const views = counted.filter((ev) => ev.type === 'open').length;
    const clicks = counted.length - views;
    box.append(el('div', 'mt-history-head', `${views} view${views === 1 ? '' : 's'} · ${clicks} link click${clicks === 1 ? '' : 's'}`));
    if (!counted.length) box.append(el('div', 'mt-history-row', 'Not viewed by the recipient yet.'));
    counted.forEach((ev) => {
      const line = el('div', 'mt-history-row' + (ev.is_self || ev.is_automatic ? ' mt-muted' : ''));
      const label = ev.type === 'click' ? `Clicked ${ev.link ? new URL(ev.link).hostname : 'a link'}` : 'Viewed';
      const note = ev.is_self ? ' (you)' : ev.is_automatic ? ' (automatic scan, not counted)' : '';
      line.append(el('b', '', label), ` · ${when(ev.at)} · ${ev.client || 'Unknown'}${note}`);
      box.append(line);
    });
  }

  let popover = null;
  function closePopover() {
    popover?.remove();
    popover = null;
  }
  document.addEventListener('mousedown', (e) => popover && !popover.contains(e.target) && closePopover(), true);
  document.addEventListener('keydown', (e) => e.key === 'Escape' && closePopover());

  function showPopover(anchor, tokens) {
    const reopen = !popover || popover.dataset.tokens !== tokens.join(',');
    closePopover();
    if (!reopen) return;
    popover = el('div', 'mt-popover');
    popover.dataset.tokens = tokens.join(',');
    const r = anchor.getBoundingClientRect();
    popover.style.top = `${Math.min(r.bottom + 6, window.innerHeight - 260)}px`;
    popover.style.left = `${Math.max(8, Math.min(r.right - 320, window.innerWidth - 328))}px`;
    document.body.append(popover);
    fillHistory(popover, tokens);
  }

  // ---------- thread view: status bar + own-view suppression ----------

  function tokensOnPage() {
    const base = server();
    if (!base) return [];
    const host = base.replace(/^https?:\/\//, '');
    const found = new Set();
    document.querySelectorAll('div.adn img, div.ii img').forEach((img) => {
      const src = img.getAttribute('src') || '';
      // Gmail proxies images but keeps the original address after "#".
      if (!src.includes(host)) return;
      const m = src.match(/\/o\/([A-Za-z0-9_-]{16,40})\.gif/);
      if (m) found.add(m[1]);
    });
    return [...found];
  }

  function suppressOwnViews(tokens) {
    const now = Date.now();
    const due = tokens.filter((t) => emails[t] && now - (suppressedAt.get(t) || 0) > 45000);
    if (!due.length) return;
    due.forEach((t) => suppressedAt.set(t, now));
    send({ type: 'suppress', tokens: due });
  }

  function renderThread() {
    const h2 = document.querySelector('h2.hP');
    const existing = document.querySelector('.mt-bar');
    if (!h2) {
      existing?.remove();
      return;
    }

    const onPage = tokensOnPage();
    if (signedIn && onPage.length) suppressOwnViews(onPage);

    const people = [...document.querySelectorAll('div.adn [email]')].map((n) => n.getAttribute('email'));
    let list = onPage.map((t) => emails[t]).filter(Boolean);
    if (!list.length) list = findByThread(h2.textContent, people);
    if (!list.length) {
      existing?.remove();
      return;
    }

    const s = combine(list);
    const key = s.tokens.join(',') + '|' + s.opens + '|' + s.clicks + '|' + s.last_opened_at;
    if (existing && existing.dataset.key === key) return;

    const bar = existing || el('div', 'mt-bar');
    bar.dataset.key = key;
    bar.replaceChildren();
    const opened = s.opens > 0 || s.clicks > 0;
    bar.classList.toggle('mt-opened', opened);
    bar.append(el('span', 'mt-bar-mark', opened ? '✓✓' : '✓'), el('span', 'mt-bar-text', describe(s)));

    const more = el('button', 'mt-bar-more', 'View history');
    more.type = 'button';
    more.addEventListener('click', () => toggleHistory(bar, s.tokens));
    bar.append(more);

    if (!existing) (h2.closest('.ha') || h2).after(bar);
  }

  function toggleHistory(bar, tokens) {
    const open = bar.querySelector('.mt-history');
    if (open) return open.remove();
    const box = el('div', 'mt-history');
    bar.append(box);
    fillHistory(box, tokens);
  }

  // ---------- render loop ----------

  let pending = false;
  function scheduleRender() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => {
      pending = false;
      if (!alive()) return;
      document.querySelectorAll(SEND_SELECTOR).forEach(setupCompose);
      document.querySelectorAll('[data-mt-track]').forEach((root) => root.mtPaint && root.mtPaint());
      renderRows();
      renderThread();
    });
  }

  load().then(() => {
    new MutationObserver(scheduleRender).observe(document.body, { childList: true, subtree: true });
    scheduleRender();
    send({ type: 'sync' });
  });
})();
