<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = preg_replace('~/(?:public/)?index\.php$~', '', $requestPath) ?? $requestPath;
$basePath = rtrim($basePath, '/');
$healthUrl = $basePath . '/api/health';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#141d33">
    <meta name="description" content="Nile Vijana Creatives backend health check and development progress.">
    <title>Nile Vijana — Health &amp; progress</title>
    <style>
        :root {
            color-scheme: dark;
            --berry: #7a0b47;
            --amber: #f0a232;
            --midnight: #141d33;
            --paper: #f7f3ee;
            --ok: #3aa874;
            --bad: #e0584a;
            --bg: #141d33;
            --bg-2: #1a2440;
            --bg-3: #24304f;
            --line: rgba(247, 243, 238, .12);
            --text: #f7f3ee;
            --muted: rgba(247, 243, 238, .7);
            --font-display: 'Bricolage Grotesque Variable', 'Arial Narrow', system-ui, sans-serif;
            --font-ui: 'Manrope Variable', system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(ellipse at 90% 0%, rgba(122, 11, 71, .3), transparent 38rem),
                var(--bg);
            color: var(--text);
            font: 16px/1.6 var(--font-ui);
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--amber); text-underline-offset: 3px; }
        a:focus-visible, button:focus-visible {
            outline: 3px solid var(--amber);
            outline-offset: 3px;
        }
        .shell { width: min(100% - 40px, 920px); margin: 0 auto; padding: 38px 0 64px; }
        .topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 22px;
            border-bottom: 1px solid var(--line);
        }
        .brand { display: flex; align-items: center; gap: 12px; font: 700 1rem var(--font-display); letter-spacing: .08em; }
        .mark { display: flex; align-items: end; gap: 3px; height: 23px; }
        .mark i { display: block; width: 5px; border-radius: 5px; background: var(--amber); }
        .mark i:nth-child(1) { height: 12px; }
        .mark i:nth-child(2) { height: 22px; }
        .mark i:nth-child(3) { height: 16px; }
        .brand-light { color: var(--amber); }
        .eyebrow { margin: 0 0 12px; color: var(--amber); font-size: .76rem; font-weight: 800; letter-spacing: .14em; }
        header { padding: clamp(42px, 9vw, 82px) 0 40px; }
        h1, h2, h3 { margin: 0; font-family: var(--font-display); line-height: 1.12; }
        h1 { max-width: 720px; font-size: clamp(2.4rem, 7vw, 4.5rem); letter-spacing: -.045em; }
        h1 span { color: var(--amber); }
        .intro { max-width: 600px; margin: 18px 0 0; color: var(--muted); font-size: 1.05rem; }
        .panel {
            padding: clamp(22px, 5vw, 34px);
            border: 1px solid var(--line);
            border-radius: 20px;
            background: linear-gradient(145deg, rgba(36, 48, 79, .88), rgba(26, 36, 64, .88));
            box-shadow: 0 16px 46px rgba(5, 9, 20, .22);
        }
        .health { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 18px; }
        .health h2 { font-size: 1.45rem; }
        .health-copy { margin-top: 6px; color: var(--muted); font-size: .92rem; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-height: 40px;
            padding: 8px 13px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: rgba(247, 243, 238, .05);
            font-size: .85rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--amber); box-shadow: 0 0 0 4px rgba(240, 162, 50, .12); }
        .status[data-state="ok"] .dot { background: var(--ok); box-shadow: 0 0 0 4px rgba(58, 168, 116, .14); }
        .status[data-state="error"] .dot { background: var(--bad); box-shadow: 0 0 0 4px rgba(224, 88, 74, .14); }
        .health-details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 25px; }
        .detail { min-width: 0; padding: 14px; border: 1px solid var(--line); border-radius: 12px; background: rgba(20, 29, 51, .38); }
        .detail-label { display: block; color: var(--muted); font-size: .75rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .detail-value { display: block; margin-top: 5px; overflow-wrap: anywhere; font-size: .93rem; font-weight: 700; }
        .health-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 18px; color: var(--muted); font-size: .84rem; }
        .retry {
            padding: 8px 13px;
            border: 1px solid rgba(240, 162, 50, .46);
            border-radius: 9px;
            background: rgba(240, 162, 50, .12);
            color: var(--amber);
            font: inherit;
            font-size: .85rem;
            font-weight: 800;
        }
        .progress { margin-top: 34px; }
        .section-head { display: flex; align-items: end; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .section-head h2 { font-size: clamp(1.55rem, 4vw, 2rem); }
        .section-head p { color: var(--muted); font-size: .85rem; }
        .milestones { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .milestone { display: flex; gap: 13px; padding: 17px; border: 1px solid var(--line); border-radius: 14px; background: var(--bg-2); }
        .check {
            display: grid;
            flex: 0 0 25px;
            width: 25px;
            height: 25px;
            place-items: center;
            border-radius: 50%;
            background: rgba(58, 168, 116, .14);
            color: #79d4a7;
            font-size: .85rem;
            font-weight: 900;
        }
        .milestone h3 { font-size: 1rem; }
        .milestone p { margin-top: 5px; color: var(--muted); font-size: .85rem; line-height: 1.5; }
        footer { display: flex; justify-content: space-between; gap: 12px; margin-top: 34px; padding-top: 18px; border-top: 1px solid var(--line); color: var(--muted); font-size: .8rem; }
        @media (max-width: 640px) {
            .shell { width: min(100% - 28px, 920px); padding-top: 22px; }
            .health { grid-template-columns: 1fr; }
            .status { justify-self: start; }
            .health-details { grid-template-columns: 1fr; }
            .milestones { grid-template-columns: 1fr; }
            .section-head { align-items: start; flex-direction: column; }
            footer { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="topline">
            <div class="brand" aria-label="Nile Vijana Creatives">
                <span class="mark" aria-hidden="true"><i></i><i></i><i></i></span>
                <span>NILE<span class="brand-light">VIJANA</span></span>
            </div>
            <a href="<?= htmlspecialchars($healthUrl, ENT_QUOTES, 'UTF-8') ?>">API health JSON <span aria-hidden="true">↗</span></a>
        </div>

        <header>
            <p class="eyebrow">BACKEND STATUS</p>
            <h1>Health check <span>&amp;</span><br>progress made.</h1>
            <p class="intro">A quick view of the Nile Vijana Creatives API and the creator tools currently implemented in the backend.</p>
        </header>

        <main>
            <section class="panel" aria-labelledby="health-title">
                <div class="health">
                    <div>
                        <h2 id="health-title">System health</h2>
                        <p class="health-copy" id="health-message" role="status" aria-live="polite">Checking the API and database connection…</p>
                    </div>
                    <div class="status" id="health-status" data-state="checking">
                        <span class="dot" aria-hidden="true"></span>
                        <span id="health-label">Checking</span>
                    </div>
                </div>
                <div class="health-details">
                    <div class="detail"><span class="detail-label">Application</span><span class="detail-value" id="app-name">—</span></div>
                    <div class="detail"><span class="detail-label">Database</span><span class="detail-value" id="database-status">Checking…</span></div>
                    <div class="detail"><span class="detail-label">Last checked</span><span class="detail-value" id="checked-at">—</span></div>
                </div>
                <div class="health-footer">
                    <span>Health endpoint: <a href="<?= htmlspecialchars($healthUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($healthUrl, ENT_QUOTES, 'UTF-8') ?></a></span>
                    <button class="retry" id="retry-health" type="button">Check again</button>
                </div>
            </section>

            <section class="progress" aria-labelledby="progress-title">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">BACKEND MILESTONES</p>
                        <h2 id="progress-title">Progress made</h2>
                    </div>
                    <p>Implemented API capabilities</p>
                </div>
                <div class="milestones">
                    <article class="milestone">
                        <span class="check" aria-hidden="true">✓</span>
                        <div><h3>Artist profiles &amp; music</h3><p>Creator pages, song uploads, albums, streaming, likes, fan tips and creator analytics.</p></div>
                    </article>
                    <article class="milestone">
                        <span class="check" aria-hidden="true">✓</span>
                        <div><h3>Producer beat marketplace</h3><p>Beat listings, licence tiers, checkout, downloadable files and PDF licences.</p></div>
                    </article>
                    <article class="milestone">
                        <span class="check" aria-hidden="true">✓</span>
                        <div><h3>Studios &amp; bookings</h3><p>Studio discovery, room availability, reservations, mobile-money deposits and escrow.</p></div>
                    </article>
                    <article class="milestone">
                        <span class="check" aria-hidden="true">✓</span>
                        <div><h3>Promotion &amp; distribution</h3><p>Opportunity listings, artist applications and admin review of submissions.</p></div>
                    </article>
                    <article class="milestone">
                        <span class="check" aria-hidden="true">✓</span>
                        <div><h3>Music mentorship</h3><p>Creator-coach profiles and session requests with mentor confirmation; no in-app payment.</p></div>
                    </article>
                    <article class="milestone">
                        <span class="check" aria-hidden="true">✓</span>
                        <div><h3>Payments &amp; platform operations</h3><p>Wallets, payouts, moderation, reports and a sandbox/pawaPay payment flow.</p></div>
                    </article>
                </div>
            </section>
        </main>

        <footer>
            <span>Nile Vijana Creatives · Backend status</span>
            <span>Progress reflects implemented backend API features.</span>
        </footer>
    </div>
    <script>
        const healthUrl = <?= json_encode($healthUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const status = document.getElementById('health-status');
        const label = document.getElementById('health-label');
        const message = document.getElementById('health-message');
        const appName = document.getElementById('app-name');
        const database = document.getElementById('database-status');
        const checkedAt = document.getElementById('checked-at');

        async function checkHealth() {
            status.dataset.state = 'checking';
            label.textContent = 'Checking';
            message.textContent = 'Checking the API and database connection…';
            database.textContent = 'Checking…';
            try {
                const response = await fetch(healthUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || `Health check returned HTTP ${response.status}`);
                const healthy = result.ok === true;
                status.dataset.state = healthy ? 'ok' : 'error';
                label.textContent = healthy ? 'Operational' : 'Degraded';
                message.textContent = healthy ? 'The API is responding and its database is reachable.' : 'The API responded, but the database is not reachable.';
                appName.textContent = result.app || 'Nile Vijana Creatives';
                database.textContent = result.database || (healthy ? 'Connected' : 'Unavailable');
                database.style.color = healthy ? 'var(--ok)' : 'var(--bad)';
                checkedAt.textContent = new Date(result.time || Date.now()).toLocaleString();
            } catch (error) {
                status.dataset.state = 'error';
                label.textContent = 'Unavailable';
                message.textContent = error instanceof Error ? error.message : 'Unable to reach the health endpoint.';
                appName.textContent = 'Nile Vijana Creatives';
                database.textContent = 'Unknown';
                database.style.color = 'var(--bad)';
                checkedAt.textContent = new Date().toLocaleString();
            }
        }

        document.getElementById('retry-health').addEventListener('click', checkHealth);
        checkHealth();
    </script>
</body>
</html>
