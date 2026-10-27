// Settings and helpers shared by the background worker and the popup.

// Where the Mail Track server runs. Change it in the popup (Settings) once the server is online.
export const DEFAULT_SERVER = 'http://127.0.0.1:8000';

export const DEFAULT_SETTINGS = {
  server: DEFAULT_SERVER,
  trackByDefault: true,
  trackLinks: true,
  notify: true,
};

export async function getSettings() {
  const { settings } = await chrome.storage.local.get('settings');
  return { ...DEFAULT_SETTINGS, ...(settings || {}) };
}

export async function getAuth() {
  const { auth } = await chrome.storage.local.get('auth');
  return auth || null;
}

export function normalizeServer(url) {
  return String(url || '').trim().replace(/\/+$/, '');
}

export function timeAgo(iso) {
  if (!iso) return '';
  const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (seconds < 60) return 'just now';
  const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
  for (const [name, size] of units) {
    const n = Math.floor(seconds / size);
    if (n >= 1) return `${n} ${name}${n > 1 ? 's' : ''} ago`;
  }
  return 'just now';
}
