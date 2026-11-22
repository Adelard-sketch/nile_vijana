# Nile Vijana Creatives — Music Marketplace (PHP + MySQL)

Responsive storefront and REST API for the Nile Vijana Creatives platform:

- **Beat market:** producers sell licences (MP3 lease, WAV lease, unlimited, exclusive) with PDF licences.
- **Songs:** artists upload music, fans stream for free, and every play is counted for the dashboard.
- **Fan store:** fans buy songs and tip artists.
- **Artist opportunities:** artists discover and apply to admin-managed promotion and music-distribution opportunities.
- **Studio booking:** studios list rooms and opening hours; artists book a slot and pay a deposit held in escrow.
- **Mobile money:** payments and payouts through pawaPay (MTN MoMo, Airtel Money), with a built-in sandbox for demos.
- **Admin:** moderation queue, payouts, reports and platform totals.

It's plain PHP with no framework and no Composer, so it runs on XAMPP as-is. It has been tested on PHP 8.3 with Apache and MariaDB 10.11, including MySQL 8's strict SQL mode. An end-to-end test of 126 checks covers sign-in, uploads, checkout, downloads, bookings, payouts and admin.

The public storefront at the project URL lets visitors browse live tracks, beats and verified studios. It uses the API below and includes audio previews plus sign-in and account creation.

---

## 1. Folder structure

```
nile-vijana-api/
├── public/              ← the only folder the web server exposes
│   ├── index.php        ← every /api request starts here
│   ├── .htaccess
│   └── uploads/         ← public images (covers, avatars, studio photos)
├── src/
│   ├── routes.php       ← ALL endpoints in one list
│   ├── Core/            ← Router, Request, Response, DB, Auth, Validator
│   ├── Controllers/     ← one class per area (Beats, Tracks, Studios, Orders…)
│   ├── Models/          ← domain persistence (users, creators, tracks, beats)
│   ├── Services/        ← business logic: payments, ledger, bookings, payouts, PDF, storage
│   └── Support/Present.php  ← shapes database rows into JSON
├── database/
│   ├── schema.sql       ← all tables (fresh installs)
│   ├── seed.sql         ← demo users and a demo studio
│   └── migrations/      ← upgrades for an existing database (run bin/migrate.php)
├── config/config.example.php   ← copy to config.php
├── storage/private/     ← audio files (never public)
├── storage/logs/        ← payments.log, sms.log, errors.log
├── bin/worker.php       ← background worker (payments, audio processing, expiry)
├── bin/migrate.php      ← applies database/migrations to an existing database
└── docs/postman_collection.json
```

The browser UI is static HTML, CSS, and JavaScript. PHP controllers handle API
requests and validation; domain models provide persistence methods; MySQL tables
are defined in `database/schema.sql`. Cart state remains in browser storage.
Homepage Nile Vijana tracks are loaded from `/api/tracks`; featured Spotify
embeds are managed in `featured_spotify_embeds` and loaded from
`/api/home/featured-spotify`. Track search can also query Spotify's catalog
through `/api/spotify/search`; Spotify credentials stay on the backend.

## 2. Requirements

| Tool | Version |
| --- | --- |
| PHP | 8.1 or newer, with the `pdo_mysql`, `curl`, `fileinfo`, `mbstring` and `iconv` extensions (all enabled in XAMPP) |
| Database | MySQL 8+ or MariaDB 10.4+ |
| Web server | Apache with `mod_rewrite` (XAMPP has it on), or PHP's built-in server for quick tests |
| FFmpeg (optional) | Makes low-bitrate stream files and beat previews automatically |

## 3. Set up on XAMPP (Windows)

1. Copy the `nile-vijana-api` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. **New database:** open http://localhost/phpmyadmin, go to the **Import** tab and import `database/schema.sql`, then `database/seed.sql`.
   **Existing database (upgrade, keeps all data):** back it up first (phpMyAdmin → Export), then run
   ```
   cd C:\xampp\htdocs\nilevijanacreatives\backend
   C:\xampp\php\php.exe bin\migrate.php
   ```
   It adds the missing tables and columns (new account types, review notes, booking requests...) and records
   what it did in `schema_migrations`, so running it again is safe. `php bin/migrate.php --status` lists them.
   Do not re-import `schema.sql` over an existing database to upgrade it: `CREATE TABLE IF NOT EXISTS` skips
   tables that already exist, so new columns would be missing.
4. Copy `config\config.example.php` to `config\config.php` and set:
   - `app.base_url` → `http://localhost/nile-vijana-api`
   - `app.key` → a long random string. Generate one with `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`
   - `db.user` / `db.pass` → `root` with an empty password on a fresh XAMPP install.
   - In this workspace's `backend/public` layout, use `http://localhost/nileVijana/backend/public` for `app.base_url` so generated API and upload links point to the served backend.
   - On the hosted layout, set `APP_URL` to the `backend/public` URL and `FRONTEND_URL` to the sibling `frontend` URL. `.env.local` is only used for loopback XAMPP requests (or CLI commands explicitly run with `NV_USE_LOCAL_ENV=true`).
   - To enable Spotify catalog search, create an app in the Spotify Developer Dashboard and add its `client_id` and `client_secret` under the `spotify` section in `backend/config/config.php`. Never put these credentials in frontend files.
5. Open http://localhost/nile-vijana-api/ for the storefront. Open http://localhost/nile-vijana-api/api/health to confirm the API is running.
6. To upload songs larger than the PHP limit, edit `C:\xampp\php\php.ini` and set `upload_max_filesize = 64M` and `post_max_size = 64M`, then restart Apache.
7. Start the worker in a terminal and leave it running:
   ```
   cd C:\xampp\htdocs\nile-vijana-api
   C:\xampp\php\php.exe bin\worker.php --loop
   ```

**Without XAMPP:** run `php -S localhost:8000 -t public public/index.php` and use `http://localhost:8000` as `base_url`.

## 4. Demo accounts (from seed.sql)

| Role | Email | Phone | Password |
| --- | --- | --- | --- |
| Admin | admin@nilevijana.test | 0780000001 | Admin@123 |
| Producer (Kivu Beats) | producer@nilevijana.test | 0780000002 | Demo@123 |
| Artist (Aline U) | artist@nilevijana.test | 0780000003 | Demo@123 |
| Studio owner (Inzozi Sound Lab) | studio@nilevijana.test | 0780000004 | Demo@123 |
| Fan | fan@nilevijana.test | 0780000005 | Demo@123 |
| Choir (Chorale Inyange) | choir@nilevijana.test | 0780000006 | Demo@123 |
| Artist manager (Eric Manzi, manages Aline U and the choir) | manager@nilevijana.test | 0780000007 | Demo@123 |
| Vocal coach (Diane Uwase) | coach@nilevijana.test | 0780000008 | Demo@123 |

All names are fictional. The studio has two rooms open Monday to Saturday 09:00–21:00 and Sunday 12:00–18:00.

## 5. How to call the API

- Send JSON with `Content-Type: application/json`. File uploads use `multipart/form-data`.
- Sign in with `POST /api/auth/login` or the SMS code flow, then send `Authorization: Bearer <token>` on every request.
- Errors always look like this:
  `{"error": "Some fields need fixing", "errors": {"phone": "This field is required"}}`
  The HTTP status tells you the kind of error: 401 sign in, 403 not allowed, 404 not found, 409 conflict, 422 invalid input.
- Lists return `{"data": [...], "meta": {"page", "per_page", "total", "pages"}}`. Use `?page=2&per_page=20` to page through them.
- Money is in whole Ghanaian cedis (GHS, integer amounts).

**Quickest way to try it:** import `docs/postman_collection.json` into Postman. Set `base_url` and run **Login as …**. The token is saved for you.

## 6. Payments (mobile money)

Mobile money is asynchronous: the buyer approves with their PIN on their phone. The flow works like this:

1. The frontend calls `POST /api/orders` or `POST /api/bookings`. The response has `status: "pending"` (or `"held"`) and a `payment.message` to show, such as "Check your phone…".
2. The frontend polls `GET /api/orders/{id}` or `GET /api/bookings/{id}` every 2–3 seconds. Each poll asks the provider for the real status.
3. When the payment completes, the order becomes `paid` (with download links) or the booking becomes `confirmed`.

The server only trusts a status it fetched from the provider itself. A callback is just a hint to check again, so a fake callback cannot mark anything paid.

**Sandbox (default, `payments.gateway = 'sandbox'`):** no account needed. Payments complete after 5 seconds, and any phone number ending in **99** fails, so you can demo the error screen.

**pawaPay:**
1. Create a sandbox account at pawaPay and copy your API token.
2. In `config.php`, set `payments.gateway` to `'pawapay'` and fill in `payments.pawapay.token`. Keep the sandbox `base_url` until you go live.
3. In the pawaPay dashboard, set both the deposit and payout callback URLs to `https://<your-domain>/api/webhooks/pawapay`.
4. pawaPay can't call `localhost`. Polling and the worker still pick up every result, so you only need the callback once the API is online.

Rwanda provider codes are `MTN_MOMO_RWA` (numbers starting 078 or 079) and `AIRTEL_RWA` (072 or 073). The API detects the network from the number when the frontend doesn't send one. This repository's current phone and provider configuration is Rwanda-specific; configure Ghana-supported providers before enabling live GHS payments.

## 7. Money rules

| Rule | Setting in config.php |
| --- | --- |
| 15% commission on beats, songs and tips | `money.commission_sales` |
| 10% commission on studio bookings | `money.commission_bookings` |
| 30% deposit to confirm a booking | `money.deposit_rate` |
| Free cancellation up to 24 hours before a session | `money.free_cancel_hours` |
| Booking slot held for 10 minutes while the artist pays | `money.booking_hold_minutes` |
| Minimum withdrawal of 1,000 GHS | `money.min_payout` |

- **The ledger is append-only.** Every money movement is a row in `ledger_entries`, and a wallet balance is the sum of that user's rows. Rows are never edited.
- **Escrow:** a booking deposit sits in escrow until the session. When the studio marks it `complete`, the money goes to the studio minus commission. If the artist cancels early, they get a full refund to their wallet. If they cancel late, the studio keeps the deposit.
- **Payouts:** a withdrawal leaves the wallet immediately, an admin approves it, and pawaPay sends it. If it fails or is rejected, the money returns to the wallet automatically.
- **Exclusive licences:** only one buyer can be paying for an exclusive at a time. Once one is sold, the beat is taken off sale. Leases sold earlier stay valid.

## 8. Background worker

`php bin/worker.php` runs once, which suits a scheduled task. `php bin/worker.php --loop` keeps running and checks every 10 seconds. Each run:

- processes audio uploads with FFmpeg, when `media.ffmpeg_enabled` is on
- checks payments still waiting for the buyer's PIN
- expires booking holds and unpaid orders
- checks payouts being sent
- cleans up old sign-in codes and tokens

To run it automatically:
- **Windows:** create a Task Scheduler task that runs `C:\xampp\php\php.exe C:\xampp\htdocs\nile-vijana-api\bin\worker.php` every minute.
- **Linux:** add the cron line `* * * * * cd /var/www/nile-vijana-api && php bin/worker.php`

## 8a. Approval workflow (songs, beats, studios)

Enforced by the API (`Services/Moderation.php`), not only hidden in the UI:

```
creator uploads ─▶ in_review (not public) ─▶ admin approves ─▶ live (public, streamable, for sale)
                                          └▶ admin rejects with a reason ─▶ rejected
rejected ─▶ creator fixes it and resubmits (POST /api/tracks|beats/{id}/resubmit) ─▶ in_review again
admin uploads for someone ─▶ admin chooses "publish now" (live) or "review queue" (in_review)
```

Public lists, detail pages, streams, previews and checkout only accept `live` items. The owner and admins can
listen to unpublished audio through a short-lived signed link (`listen_url`, served by `GET /api/media/{token}`).
A rejection needs a reason (at least 5 characters); it is stored in `review_note`, shown to the creator and sent by SMS.
Studios work the same way: a new listing is `pending` until an admin verifies it; a rejected studio goes back to
`pending` when the owner edits it. The old `moderation.auto_approve` setting no longer publishes creator uploads.

## 8b. Studio booking requests

```
held (slot reserved while the deposit is paid) ─▶ pending (deposit in escrow, studio must answer)
pending ─▶ studio accepts ─▶ confirmed ─▶ completed (money released to the studio) / no_show
pending ─▶ studio declines with a reason ─▶ rejected (deposit refunded to the artist's wallet)
pending and no answer within money.booking_response_hours (48 h), or the start time passes ─▶ rejected by the worker, refunded
```

A pending request blocks the time slot, so two artists cannot request the same hours.

## 9. API reference

🔓 = anyone · 🔑 = signed in · A = artist · P = producer · S = studio owner (checked against ownership) · ⚙️ = admin

| Method | Route | Who | What it does |
| --- | --- | --- | --- |
### Sign-in and account
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| POST | /api/auth/register | 🔓 | `{name, email or phone, password}` → token |
| POST | /api/auth/login | 🔓 | `{login (email or phone), password}` → token |
| POST | /api/auth/otp | 🔓 | `{phone}` sends a 6-digit SMS code (returns `dev_code` in development) |
| POST | /api/auth/verify | 🔓 | `{phone, code, name?}` → token (creates the account if new) |
| POST | /api/auth/logout | 🔑 | Ends this token |
| GET / PATCH | /api/me | 🔑 | My account, roles, profile, studios, balance / update name, email, language, password |
| GET | /api/me/wallet | 🔑 | Balance and money history |
| GET | /api/me/library | 🔑 | Purchases, liked songs, followed creators |
| PATCH | /api/creators/me | 🔑 | Create or edit my profile (`stage_name, type` = artist, producer, both, choir, manager or vocal_coach, `bio, city, genres, contact_email, contact_phone, website, members_count, experience_years, specialization, company, payout_phone, payout_provider`) |
| GET / PATCH / DELETE | /api/creators/me/manager | A | Read, save or remove one private manager contact (`name`, and `email` or `phone`; optional `company`) |
| POST | /api/creators/me/avatar | A/P | Upload a profile picture (field `avatar`) |
| GET | /api/creators/{slug} | 🔓 | Public page with songs, beats and followers |
| POST / DELETE | /api/creators/{id}/follow | 🔑 | Follow / unfollow |

### Songs
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| GET | /api/tracks?q=&genre=&mood=&language=&creator=&sort= | 🔓 | Browse live songs (`sort`: newest, popular, title) |
| GET | /api/tracks/mine | A | My songs in every status, with `review_note` when rejected |
| POST | /api/tracks | A | Multipart: `title, genre, mood, language, price, album_id, description, release_date` + files `audio`, `cover`. Goes to review. Admins also send `creator_id` (artist or choir) and `publish` = `live` or `in_review` |
| GET / PATCH / DELETE | /api/tracks/{id} | 🔓 / A | View (owner and admin also get `listen_url`) / edit / remove |
| POST | /api/tracks/{id}/resubmit | A | Multipart: changed fields and optional new `audio` / `cover`; a rejected or pending song goes back to review |
| POST | /api/tracks/{id}/cover | A | New cover image |
| GET | /api/tracks/{id}/stream | 🔓 | Audio stream (supports seeking) |
| GET | /api/tracks/{id}/preview | 🔓 | 30-second clip (when FFmpeg is on) |
| POST | /api/tracks/{id}/plays | 🔓 | `{seconds, session_id, city, device}`: counts a play after 30 seconds |
| POST / DELETE | /api/tracks/{id}/like | 🔑 | Like / unlike |
| POST | /api/albums · GET /api/albums/{id} | A / 🔓 | Create / view an album |

### Artist opportunities
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| GET | /api/opportunities?category=&q=&location= | 🔓 | Browse open promotion and distribution opportunities |
| GET | /api/opportunities/{id} | 🔓 | View an open opportunity, partner details, eligibility and deadline |
| POST | /api/opportunities/{id}/applications | A | `{pitch}` submits one application per artist |
| GET | /api/opportunities/me/applications | A | View my applications and their review status |

### Music mentors
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| GET | /api/mentors?q=&city= | 🔓 | Browse active music coaches |
| GET | /api/mentors/{slug} | 🔓 | View a coach's creator profile, headline and specialties |
| PATCH | /api/mentors/me | A/P | Opt in or update `{headline, specialties, active?}`; set `active:false` to leave the directory |
| POST | /api/mentors/{id}/bookings | 🔑 | Request a future session with `{start_at, duration_minutes, subject, message?}`; no payment is collected |
| GET | /api/mentor-bookings?as=mentee\|mentor&status= | 🔑 | View sessions requested or offered by me |
| PATCH | /api/mentor-bookings/{id} | 🔑 | `{action: confirm\|decline\|cancel\|complete, note?}`; only the mentor can confirm, decline or complete |

### Beats
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| GET | /api/beats?q=&genre=&mood=&key=&bpm_min=&bpm_max=&price_max=&producer=&sort= | 🔓 | Browse (`sort`: newest, popular, price_low, price_high) |
| GET | /api/beats/mine | P | My beats with sales |
| POST | /api/beats | P | Multipart: `title, bpm, key, genre, mood, tags, description, release_date, tiers` + files `mp3`, `wav`, `stems` (zip), `preview`, `cover`. Goes to review; admins also send `producer_id` and `publish` |
| POST | /api/beats/{id}/resubmit | P | Multipart: changed fields, `tiers`, optional new files; back to review |
| GET / PATCH / DELETE | /api/beats/{id} | 🔓 / P | View / edit details and licence tiers / take off sale |
| GET | /api/beats/{id}/preview | 🔓 | Tagged preview audio |
| POST | /api/beats/{id}/plays | 🔓 | Count a preview listen |

`tiers` example: `[{"tier":"mp3_lease","price":10000},{"tier":"wav_lease","price":25000},{"tier":"exclusive","price":150000}]`. WAV, unlimited and exclusive tiers need a WAV upload.

### Studios and bookings
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| GET | /api/studios?city=&q= | 🔓 | Verified studios with lowest price and rating |
| GET | /api/studios/mine | 🔑 | My studios |
| POST | /api/studios | 🔑 | List a studio (`name, city, address, phone, equipment, amenities, backup_power`); an admin verifies it |
| GET / PATCH | /api/studios/{id} | 🔓 / S | Details with rooms, hours and reviews / edit |
| POST | /api/studios/{id}/cover | S | Cover photo |
| POST | /api/studios/{id}/rooms | S | `{name, hourly_rate, min_hours, engineer_included, hours?}` |
| PATCH | /api/rooms/{id} | S | Edit a room, or set `status: inactive` |
| PUT | /api/rooms/{id}/hours | S | `{hours:[{weekday:1,open:"09:00",close:"21:00"}]}` (1 = Monday) |
| POST / DELETE | /api/rooms/{id}/blocked-dates[/{blockedId}] | S | Close or reopen a day |
| GET | /api/rooms/{id}/availability?date=YYYY-MM-DD | 🔓 | Hourly slots with free or busy |
| POST | /api/bookings | 🔑 | `{room_id, start_at:"2026-11-05 14:00", hours, phone, provider?, notes?}` holds the slot and asks for the deposit |
| GET | /api/bookings?as=artist\|studio&status=&upcoming=1 | 🔑 | My sessions / bookings at my studios |
| GET | /api/bookings/{id} | 🔑 | Details (poll this while paying) |
| PATCH | /api/bookings/{id} | 🔑 | `{action, note?}`: `accept` or `reject` (studio, for `pending` requests; `reject` needs `note`), `cancel` (artist or studio), `complete` or `no_show` (studio, after the start time), `studio_no_show` (artist or admin) |
| POST | /api/bookings/{id}/pay-balance | 🔑 | Pay the rest of the session price in the app |

### Checkout and downloads
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| POST | /api/orders | 🔑 | `{items:[{type:"license",license_tier_id}, {type:"track",track_id}, {type:"tip",creator_id,amount}], phone, provider?}` |
| GET | /api/orders · /api/orders/{id} | 🔑 | My orders / one order (poll this while paying) |
| GET | /api/orders/{id}/downloads | 🔑 | Signed links, valid for 24 hours, to the MP3, WAV, stems and licence PDF |
| GET | /api/order-items/{id}/license | 🔑 | Licence PDF |
| GET | /api/files/{token} | 🔓 | The signed download itself |
| POST | /api/webhooks/pawapay | pawaPay | Payment and payout callbacks |

### Dashboard, payouts, reviews and reports
| Method | Route | Who | What it does |
| --- | --- | --- | --- |
| GET | /api/dashboard/stats?range=7\|30\|90\|365 | 🔑 | Compact summary by role (kept for older pages) |
| GET | /api/dashboard/wallet | 🔑 | Balance, withdrawals in progress, pending sales, lifetime and monthly earnings (12 months), transactions, payouts |
| GET | /api/dashboard/music?range= | A / choir | Uploads by status, streams per day, listeners, followers, per-song plays/likes/sales/revenue, top songs, recent releases, top cities, monthly earnings |
| GET | /api/dashboard/beats?range= | P | Beats by status, plays, licences sold (by tier), revenue per beat, popular beats, recent sales, monthly earnings |
| GET | /api/dashboard/studio?range= | S | Requests to answer, schedule, sessions to close, revenue, money in escrow, bookings per day, monthly revenue |
| GET | /api/dashboard/manager?range= | manager | The artists and choirs linked to the manager, with releases, streams and earnings |
| POST / GET | /api/payouts | 🔑 | `{amount, phone?, provider?}` withdraw to mobile money / my withdrawals |
| GET / POST | /api/reviews | 🔓 / 🔑 | Reviews for a studio, beat or song. Studios need a completed session; beats need a purchase. |
| POST | /api/reports | 🔑 | `{target_type, target_id, reason: copyright\|abuse\|fraud\|other, details}` |

### Admin (⚙️)
`GET /api/admin/overview` (accounts by type, content, pending approvals, revenue and commission per month, creator and studio earnings, pending bookings, recent registrations, activity) · `GET /api/admin/activity?limit=` · `GET /api/admin/bookings?status=` · `POST /api/admin/onboard` (one form for every account type, see below) · `PUT /api/admin/managers/{id}/artists {artist_ids:[...]}` · `GET /api/admin/creator-profiles?type=artist|musician|producer|choir|manager|vocal_coach|all&q=` · `GET /api/admin/tracks?status=&q=` · `GET /api/admin/beats?status=&q=` · `GET /api/admin/studios?status=&q=` · `GET /api/admin/queue` · `GET / POST /api/admin/opportunities` · `PATCH /api/admin/opportunities/{id}` · `GET /api/admin/opportunities/{id}/applications?status=pending` · `PATCH /api/admin/opportunities/{id}/applications/{applicationId} {status: accepted|rejected, decision_note?}` · `POST /api/admin/{tracks|beats|studios}/{id}/review {decision: approve|reject, note}` · `POST /api/admin/creators/{id}/verify` · `GET /api/admin/payouts?status=` · `POST /api/admin/payouts/{id}/approve|reject` · `GET /api/admin/reports` · `POST /api/admin/reports/{id}/resolve {status, note, remove_content}` · `GET /api/admin/users?q=&role=` · `PATCH /api/admin/users/{id} {status, add_role, remove_role}`

`POST /api/admin/onboard` (multipart; optional file `picture`): `type` = artist, choir, producer, manager, vocal_coach or studio, `display_name`, `phone` and/or `email`, optional `password`, `contact_name`, `bio`, `city`, `website`. Per type: artist and producer need `genres`; choir needs `city` and `members_count`; vocal_coach needs `experience_years` and `specialization` (also listed on the mentors page); manager takes `company` and `managed_artists` (JSON list of artist/choir ids); studio needs `city`, `address`, `equipment` and `rooms` (JSON list of `{name, hourly_rate, min_hours, engineer_included}`), optional `amenities`, `backup_power`. The account gets the matching role and a verified profile (studios are created verified with Monday–Saturday 09:00–21:00 hours).

New opportunities are drafts by default. Include `category` (`promotion` or `distribution`), `partner_name`, `title`, and `description` when creating one; `partner_url` must use HTTPS. Set `status` to `open` to publish it. Only artists can apply, and an artist can apply once per opportunity.

Lists for dropdowns (genres, moods, keys, tiers, networks) come from `GET /api/meta`.

## 10. Example: buy a beat with curl

```bash
B=http://localhost/nile-vijana-api/api
TOKEN=$(curl -s -X POST $B/auth/login -H "Content-Type: application/json" \
  -d '{"login":"fan@nilevijana.test","password":"Demo@123"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')

curl -s "$B/beats?genre=Afrobeat"                                   # pick a licence id
curl -s -X POST $B/orders -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"items":[{"type":"license","license_tier_id":1}],"phone":"0780000005"}'
curl -s $B/orders/1 -H "Authorization: Bearer $TOKEN"              # repeat until "status":"paid"
curl -s $B/orders/1/downloads -H "Authorization: Bearer $TOKEN"    # signed links
```

## 11. Security in this code

- Every query uses prepared statements, so there's no SQL injection.
- Passwords are stored with Argon2id (or bcrypt). Login tokens and SMS codes are stored only as hashes.
- Sign-in codes are rate-limited: 3 per phone every 10 minutes and 20 per IP per hour, with 5 wrong attempts allowed per code.
- File uploads are checked by their real content, not the file name. Audio is stored outside the web folder, and scripts can never run from `uploads/`.
- Paid files are only reachable through expiring HMAC-signed links.
- Money changes run inside database transactions with row locks, so double bookings and double withdrawals are impossible.
- Payment results are re-checked with the provider; callbacks are never trusted on their own.

## 12. Before going live

- [ ] In `config.php`, set `app.env = 'production'` and `app.debug = false`, plus a real `app.key` and `base_url` with HTTPS.
- [ ] Register with Rwanda's National Cyber Security Authority (NCSA) as a data controller (Law No. 058/2021).
- [ ] Get a pawaPay live account, set `base_url` to `https://api.pawapay.io`, and configure the callback URLs.
- [ ] Set `sms.driver = 'africastalking'` with live credentials.
- [ ] Run the worker every minute (cron or Task Scheduler).
- [ ] Back up the database daily, plus `storage/private` and `public/uploads`.
- [ ] Have a lawyer review the licence wording in `src/Services/LicensePdf.php`.

## 13. Troubleshooting

| Problem | Fix |
| --- | --- |
| Every route returns Apache's own 404 | `mod_rewrite` is off, or `AllowOverride None` in httpd.conf. XAMPP allows overrides by default. |
| 401 even with a token | The header must be exactly `Authorization: Bearer <token>`. The included `.htaccess` passes it through to PHP. |
| "File too large" | Raise `upload_max_filesize` and `post_max_size` in php.ini, then restart Apache. |
| "Missing config/config.php" | Copy `config.example.php` to `config.php`. |
| Database not reachable | Check `db` settings and that MySQL is running in XAMPP. |
| Browser shows a CORS error | Add your frontend address (e.g. `http://localhost:5173`) to `app.cors_origins`. |
| Payment stuck on pending | Make sure the worker is running, and check `storage/logs/payments.log`. |
| Something else | Check `storage/logs/errors.log`. With `app.debug = true` the JSON error includes the file and line. |
