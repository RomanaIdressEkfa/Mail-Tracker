# Mail Track

Free email tracking for Gmail: a Chrome extension shows ✓ when an email is sent and ✓✓ when it's opened,
how many times, when, and which links were clicked, plus a desktop notification on every open.

Two parts:

| Part | Folder | What it does |
|---|---|---|
| Server (Laravel 13) | this project | Serves the invisible pixel (`/o/{token}.gif`) and tracked links (`/c/{token}/{n}`), stores opens and clicks, and provides the API the extension uses |
| Chrome extension (Manifest V3) | `extension/` | Adds a "Tracking" toggle next to Gmail's Send button, inserts the pixel and tracked links on Send, and shows checkmarks, a status bar with history, and notifications |

## How tracking works

1. On Send, the extension generates a random token, adds `<img src="https://SERVER/o/TOKEN.gif">` to the email and
   rewrites links to `https://SERVER/c/TOKEN/N`, then registers the email (subject, recipients, links) with the server.
2. When the recipient opens the email, their mail program loads the image and the server records an open.
3. Accuracy safeguards:
   - **Your own views:** when you open your sent email in Gmail, the extension tells the server, which marks views from the
     last two minutes as yours and ignores the next minute.
   - **Machines:** security scanners (Barracuda, Mimecast, Proofpoint…) and hits within 3 seconds of sending are stored but not counted.
   - **Duplicates:** repeat loads from the same address within 30 seconds count once.
   - **Privacy:** recipient IPs are stored only as a salted hash; recipients get no cookies or sessions.

Limits that no tracker can avoid: Apple Mail Privacy Protection may load images on its own (shown as "Apple Mail"),
and mail programs that block images only show up when a tracked link is clicked.

## Run locally

```powershell
$env:Path = 'C:\laragon\bin\php\php-8.3.32-Win32-vs16-x64;' + $env:Path
php artisan migrate
php artisan serve          # http://127.0.0.1:8000
php artisan test           # 14 tests
```

Load the extension: Chrome → `chrome://extensions` → turn on **Developer mode** → **Load unpacked** → choose the
`extension` folder. Click the extension icon, create an account, open Gmail.

On `127.0.0.1` you can try sign-in, the toggle and the checkmarks, but **real opens need a public HTTPS server**:
Gmail loads images through Google's servers, which can't reach your computer.

## Deploy the server on cPanel

1. **Subdomain:** create e.g. `track.yourdomain.com` and point its document root to `mail-track/public`.
2. **SSL:** cPanel → SSL/TLS Status → run AutoSSL for the subdomain (the pixel must be HTTPS).
3. **Database:** cPanel → MySQL Databases → create a database and user with all privileges.
4. **Upload** the project (without `vendor/`, `node_modules/`, `extension/`, `.env`, `database/database.sqlite`).
5. **.env:** copy `.env.example` to `.env` and set:
   ```
   APP_NAME="Mail Track"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://track.yourdomain.com
   DB_CONNECTION=mysql
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...
   MAIL_FROM_ADDRESS=you@yourdomain.com
   ```
6. **Terminal** (cPanel → Terminal, or SSH):
   ```
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
   php artisan migrate --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
7. In the extension popup → **Settings** → Server: `https://track.yourdomain.com` → **Save**, then sign in again.

## Renaming or selling

- The name comes from `APP_NAME` (server) and `name`/`short_name` in `extension/manifest.json`. "Mailtrack" is a
  trademark of Mailsuite, so choose a distinct name before publishing or selling.
- The Chrome Web Store needs a one-time developer fee, a privacy policy (served at `/privacy`), and screenshots.
  Change `DEFAULT_SERVER` in `extension/shared.js` and `content.js` to your live server before publishing.
- In the EU and UK, open tracking can require the recipient's consent. The per-email toggle lets senders switch it off.

## API (for the extension)

All routes are under `/api`, with `Authorization: Bearer TOKEN` except sign-up and sign-in.

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/register`, `/auth/login` | Returns `{ token, user }` |
| POST | `/auth/logout` | Revokes this token |
| POST | `/emails` | Register a sent email `{ token, subject, recipients[], sent_at, links[{position,url}] }` (idempotent) |
| GET | `/emails?limit=300` | Recent emails with open/click counts |
| GET | `/emails/{token}` | One email with links and full event history |
| POST | `/emails/suppress` | `{ tokens[] }`: the sender is viewing these, so their views don't count |
| GET | `/activity?since=ISO` | Counted opens and clicks, newest first |
| GET | `/stats` | Sent / open rate / click rate, last 30 days |
