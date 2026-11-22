# Nile Vijana static frontend

The frontend is static HTML, CSS and vanilla JavaScript; PHP runs only in the API
under `../backend`. Nothing here needs a build step.

## Run locally

Place the repository at `C:\xampp\htdocs\nilevijanacreatives` and start Apache and MySQL.
Open `http://localhost/nilevijanacreatives/frontend/` for the website and
`http://localhost/nilevijanacreatives/frontend/dashboard/` for the dashboard.
The JavaScript client finds the API relative to `assets/js/core.js`
(`../../../backend/public/api/`). If the API is hosted elsewhere, change `API_BASE`
in `core.js` and allow the frontend origin in the backend CORS settings.

## Files

```
frontend/
├── *.html, auth/*.html     public pages: each is a small shell with <body data-page="...">
├── dashboard/index.html    the dashboard app (every account type)
├── dashboard/*.html        old dashboard addresses; they redirect into the app (bookmarks keep working)
└── assets/
    ├── css/
    │   ├── main.css         design tokens, layout, navbar (shared)
    │   ├── components.css   buttons, cards, forms, toasts (shared)
    │   ├── site.css         public page styles (auth, services, music, detail pages, coaches, booking status)
    │   ├── index.css        home page only
    │   └── dashboard.css    dashboard layout and components (d- prefixed)
    └── js/
        ├── core.js          loaded first everywhere: API client (window.api) + helpers (window.NVCore)
        ├── site.js          public website: navbar, player, pages, cart, checkout, studio booking
        └── dashboard/
            ├── ui.js        shell (sidebar, top bar), hash router, components, SVG charts
            ├── media.js     song and beat forms, tables and actions shared by admins and creators
            ├── admin.js     admin overview, onboarding, uploads, review queue, songs, beats, studios, bookings, people, withdrawals
            ├── creator.js   artist / choir and producer overview, catalogue, upload, analytics
            ├── studio.js    studio overview, booking requests, rooms and hours, studio profile
            └── account.js   earnings and wallet, my bookings, profile, getting started, manager, vocal coach
```

Each public page loads `core.js` and `site.js`; `site.js` starts the page named by
`<body data-page>`. The dashboard loads `core.js`, then `dashboard/ui.js`, then the
area modules, which register their screens with `Dash.route(path, { title, roles, render })`.

## Dashboard routes

The sidebar only shows what the signed-in account can use (the API checks the same rules).

| Account | Screens |
| --- | --- |
| Admin | `#/admin` overview, `#/admin/onboard`, `#/admin/upload?type=song\|beat`, `#/admin/review`, `#/admin/music`, `#/admin/beats`, `#/admin/studios`, `#/admin/bookings`, `#/admin/people`, `#/admin/payouts` |
| Artist / choir | `#/music`, `#/music/songs`, `#/music/upload`, `#/music/analytics` |
| Producer | `#/beats`, `#/beats/list`, `#/beats/upload`, `#/beats/analytics` |
| Studio owner | `#/studio`, `#/studio/bookings`, `#/studio/rooms`, `#/studio/profile` |
| Manager / vocal coach | `#/manager` / `#/coach` |
| Everyone | `#/earnings`, `#/my-bookings`, `#/profile`, `#/start` (listeners), `#/studio/new` |

Shortcuts: `#/upload`, `#/analytics` and `#/bookings` open the right screen for the account.

## Notes

- Authentication: the token and the user are kept in `localStorage` (`auth_token`, `auth_user`);
  the cart in `nv_cart` until checkout creates an order.
- Charts are drawn in SVG by `dashboard/ui.js` (no library). Every chart has a hover tooltip and a
  "Show as table" view. Series colours were checked for colour-blind contrast on the dashboard background.
- All numbers on the dashboards come from the API; nothing is hard-coded.
- When changing CSS or JS, bump the `?v=` query in the HTML files so browsers fetch the new files
  (`.htaccess` lets browsers cache CSS and JS for a month).
