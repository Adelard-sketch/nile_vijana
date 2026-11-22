/* Shared static-frontend layout and page controllers. */
(function () {
    const core = window.NVCore;
    const { esc, obj, list, toast, call } = core;
    const root = core.ROOT;
    const $ = (selector, scope = document) => scope.querySelector(selector);
    const money = value => core.money(value, { free: true });
    const query = new URLSearchParams(location.search);
    const token = () => window.api.getToken();
    const readCart = () => core.cart.read();
    const saveCart = items => core.cart.save(items);
    const PLAY_ICONS = '<svg class="is-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>'
        + '<svg class="is-pause" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" fill="currentColor"/></svg>';
    const svgIcon = (path, cls = '') => `<svg class="card-stat-icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg>`;
    const card = (item, type) => {
        const beat = type === 'beat';
        const studio = type === 'studio';
        const id = item.id;
        const title = item.title || item.name || 'Untitled';
        const cover = item.cover_url || item.image_url || '';
        const detail = studio ? `studio.html?id=${encodeURIComponent(id)}` : `${type}.html?id=${encodeURIComponent(id)}`;
        const creator = item.creator || item.producer || {};
        const person = creator.stage_name || creator.name || 'Nile Vijana Creator';
        const creatorLink = creator.slug ? `creator.html?slug=${encodeURIComponent(creator.slug)}` : '#';
        const plays   = Number(item.plays || item.play_count || 0);
        const likes   = Number(item.likes  || item.like_count  || 0);
        const saves   = Number(item.saves  || item.save_count  || 0);
        const liked   = Boolean(item.liked);
        const saved   = Boolean(item.saved);
        const trackStats = `
            <div class="track-stats">
                <span class="track-stat">${svgIcon('<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/>')}<span>${plays.toLocaleString()}</span></span>
                <span class="track-stat">${svgIcon('<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z">', liked ? 'is-active' : '')}<span>${likes.toLocaleString()}</span></span>
                <span class="track-stat">${svgIcon('<path d="M5 12l5 5L20 7">', saved ? 'is-active' : '')}<span>${saves.toLocaleString()}</span></span>
            </div>
            <div class="track-card-actions">
                <button type="button" class="card-action-btn card-btn-favorite${liked ? ' is-active' : ''}" data-card-action="favorite" data-track-id="${esc(id)}" aria-pressed="${liked}" aria-label="${liked ? 'Remove from favourites' : 'Add to favourites'}" title="Favourite">
                    ${svgIcon('<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>')}
                </button>
                <button type="button" class="card-action-btn card-btn-save${saved ? ' is-active' : ''}" data-card-action="save" data-track-id="${esc(id)}" aria-pressed="${saved}" aria-label="${saved ? 'Remove from library' : 'Save to library'}" title="Save">
                    ${saved ? svgIcon('<path d="M5 12l5 5L20 7"/>') : svgIcon('<path d="M12 5v14M5 12h14"/>')}
                </button>
            </div>`;
        return `<article class="${beat ? 'beat' : studio ? 'studio' : 'track'}-card">
            <div class="${beat ? 'beat' : studio ? 'studio' : 'track'}-cover">${cover ? `<img src="${esc(cover)}" alt="${esc(title)}" loading="lazy">` : `<div class="cover-placeholder">${esc(title.slice(0, 2).toUpperCase())}</div>`}
                ${!studio ? `<button type="button" class="btn-play ${beat ? 'btn-play-beat' : ''}" data-kind="${beat ? 'beat' : 'track'}" data-id="${esc(id)}" data-title="${esc(title)}" aria-label="Play ${esc(title)}">${PLAY_ICONS}</button>` : ''}
            </div>
            <div class="${beat ? 'beat' : studio ? 'studio' : 'track'}-info">
                <h3 class="${beat ? 'beat-title' : studio ? 'studio-name' : 'track-title'}"><a href="${detail}">${esc(title)}</a></h3>
                ${studio ? `<p class="studio-location">${esc([item.address, item.city].filter(Boolean).join(', ') || item.location || '')}</p><div class="studio-price">${Number(item.price_from || item.hourly_rate) ? `From <strong>${core.money(item.price_from || item.hourly_rate)}</strong> per hour` : 'Price on request'}</div>` :
                    `<p class="${beat ? 'beat-producer' : 'track-artist'}">by <a href="${creatorLink}">${esc(person)}</a></p>
                    <div class="${beat ? 'beat-tags' : 'track-meta'}">${beat ? `<span class="tag">${esc(item.bpm || '')}${item.bpm ? ' BPM' : ''}</span><span class="tag">${esc(item.genre || '')}</span><span class="beat-price">From ${money(item.price_from || item.price)}</span>` : `<span class="badge">${esc(item.genre || '')}</span>`}</div>
                    ${!beat ? trackStats : ''}`}
            </div>
        </article>`;
    };
    /* ---------- shared data ---------- */
    let metaPromise = null;
    /** Dropdown lists, limits and payment settings from GET /api/meta (loaded once). */
    const loadMeta = () => metaPromise || (metaPromise = window.api.get('/meta').then(m => { window.NVMeta = m; return m; }).catch(() => ({})));
    const svg = (d, cls = '') => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${d}"/></svg>`;
    const ICONS = {
        home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
        music: 'M9 18V5l12-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0m12-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        beat: 'M3 12h3l3-8 4 16 3-8h5',
        studio: 'M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3m7 9a7 7 0 0 1-14 0m7 7v4m-4 0h8',
        users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8m14 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
        grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        help: 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3m.1 4h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        coach: 'M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8m-7 7a7 7 0 0 1 14 0',
        briefcase: 'M10 6V4h4v2M3 7h18v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zm0 5h18',
        upload: 'M12 16V4m0 0-5 5m5-5 5 5M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3',
        calendar: 'M8 2v4m8-4v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2',
        chevron: 'M6 9l6 6 6-6',
        arrow: 'M5 12h14M13 6l6 6-6 6',
        lock: 'M6 11V8a6 6 0 0 1 12 0v3M5 11h14v10H5z',
        check: 'M5 12l5 5L20 7',
        mic: 'M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3m7 9a7 7 0 0 1-14 0',
        receipt: 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4',
        external: 'M14 4h6v6M20 4l-9 9M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5',
        close: 'M6 6l12 12M18 6 6 18'
    };
    const SPOTIFY_LOGO = '<svg class="spotify-logo" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm4.6 14.4a.62.62 0 0 1-.86.2c-2.35-1.43-5.3-1.76-8.79-.96a.62.62 0 1 1-.28-1.22c3.81-.87 7.09-.5 9.72 1.11.3.18.39.57.21.87Zm1.23-2.73a.78.78 0 0 1-1.07.26c-2.69-1.65-6.79-2.13-9.97-1.17a.78.78 0 1 1-.45-1.49c3.63-1.1 8.15-.57 11.23 1.33.37.22.48.7.26 1.07Zm.1-2.85C14.7 8.9 9.39 8.72 6.32 9.65a.94.94 0 1 1-.54-1.8c3.53-1.07 9.39-.86 13.1 1.34a.94.94 0 0 1-.95 1.63Z"/></svg>';
    const localPhone = p => {
        const d = String(p || '').replace(/\D/g, '');
        return d.startsWith('233') && d.length === 12 ? `0${d.slice(3, 5)} ${d.slice(5, 8)} ${d.slice(8)}` : (d ? `+${d}` : '');
    };

    /* ---------- Spotify cards: same look as our own music, they play in Spotify's player ---------- */
    const SPOTIFY_KIND = { track: 'Song', album: 'Album', playlist: 'Playlist' };
    function spotifyCard(item) {
        const type = item.embed_type || 'track';
        const id = String(item.spotify_id || item.id || '');
        if (!/^[A-Za-z0-9]{10,40}$/.test(id)) return '';
        const title = item.title || `Spotify ${SPOTIFY_KIND[type] || 'music'}`;
        const sub = item.artist || item.subtitle || `${SPOTIFY_KIND[type] || 'Music'} on Spotify`;
        const cover = item.cover_url || item.image_url || '';
        return `<article class="track-card spotify-card" data-spotify-card="${esc(type)}:${esc(id)}">
            <div class="track-cover">${cover ? `<img src="${esc(cover)}" alt="${esc(title)}" loading="lazy">` : `<div class="cover-placeholder spotify-cover-placeholder">${SPOTIFY_LOGO}</div>`}
                <span class="spotify-chip">${SPOTIFY_LOGO}${esc(SPOTIFY_KIND[type] || 'Spotify')}</span>
                <button type="button" class="btn-play btn-play-spotify" data-spotify="${esc(type)}:${esc(id)}" data-title="${esc(title)}" data-sub="${esc(sub)}" aria-label="Play ${esc(title)} on Spotify">${PLAY_ICONS}</button>
            </div>
            <div class="track-info">
                <h3 class="track-title"><a href="https://open.spotify.com/${esc(type)}/${esc(id)}" target="_blank" rel="noopener">${esc(title)}</a></h3>
                <p class="track-artist">${esc(sub)}</p>
                <p class="spotify-note">${type === 'track' ? 'Preview, or the full song when signed in to Spotify' : 'Plays in Spotify’s player'}</p>
            </div>
        </article>`;
    }
    const spotifySection = (title, items, sub = '') => {
        const cards = items.map(spotifyCard).filter(Boolean).join('');
        return cards ? `<section class="section spotify-section"><div class="container">
            <div class="section-header"><div><h2 class="section-title">${SPOTIFY_LOGO}${esc(title)}</h2>${sub ? `<p class="section-subtitle">${sub}</p>` : ''}</div></div>
            <div class="track-grid" data-play-scope>${cards}</div></div></section>` : '';
    };

    /* ---------- listing pages: Music, Beats, Studios ---------- */
    const PAGE_INTROS = {
        track: {
            eyebrow: 'Music',
            title: 'New music from Ghana and beyond',
            text: 'Stream Highlife, Afrobeats, Gospel and Hiplife from independent artists. Save the songs you love, share them, and support artists directly with a tip or a download.',
            facts: ['Free streaming', 'Tips and downloads in cedis', 'Spotify picks included']
        },
        beat: {
            eyebrow: 'Beat marketplace',
            title: 'License beats from Ghana’s producers',
            text: 'Preview tagged beats, then buy an MP3 lease, WAV lease or exclusive licence. Every purchase comes with the files and a signed licence PDF.',
            facts: ['Leases and exclusives', 'Signed licence PDF', 'Pay with Mobile Money or card']
        },
        studio: {
            eyebrow: 'Recording studios',
            title: 'Book studio time in Accra, Kumasi and beyond',
            text: 'Compare rooms, equipment and hourly rates, then request a session with a deposit. Your deposit is held safely until the session is done.',
            facts: ['Verified studios', 'Deposit held until your session', 'Free cancellation up to 24 hours before']
        }
    };
    const listing = (title, endpoint, type) => {
        const intro = PAGE_INTROS[type];
        const studio = type === 'studio';
        return `<section class="page-hero page-hero--${type}"><div class="container">
                <p class="eyebrow">${esc(intro.eyebrow)}</p>
                <h1 class="page-hero-title">${esc(intro.title)}</h1>
                <p class="page-hero-text">${esc(intro.text)}</p>
                <ul class="page-hero-facts">${intro.facts.map(f => `<li>${svg(ICONS.check)}${esc(f)}</li>`).join('')}</ul>
            </div></section>
            <section class="section listing-section"><div class="container">
            <form class="search-form" id="search-form" role="search" aria-label="Search ${esc(title.toLowerCase())}">
                <label class="visually-hidden" for="search-q">Search</label>
                <input class="form-input" id="search-q" name="q" type="search" value="${esc(query.get('q') || '')}" placeholder="${studio ? 'Search by studio name or area' : type === 'beat' ? 'Search beats, moods or producers' : 'Search songs or artists'}">
                <label class="visually-hidden" for="search-filter">${studio ? 'City' : 'Genre'}</label>
                <select class="form-input" id="search-filter" name="${studio ? 'city' : 'genre'}"><option value="">${studio ? 'All cities' : 'All genres'}</option></select>
                <button class="btn btn-primary">Search</button>
            </form>
            <div class="${type === 'beat' ? 'beat-grid' : studio ? 'studio-grid' : 'track-grid'}" id="results" data-play-scope><p class="loading-state" role="status">Loading…</p></div>
            ${type === 'track' ? '<div id="spotify-search-results" aria-live="polite"></div><div id="featured-spotify-music"></div>' : ''}
        </div></section>`;
    };
    /** Fills the genre (or city) filter from /api/meta, keeping a value from the address bar. */
    async function fillFilter(type) {
        const select = $('#search-filter');
        if (!select) return;
        const meta = await loadMeta();
        const values = type === 'studio' ? (meta.cities || []) : (meta.genres || []);
        const current = query.get(select.name) || '';
        select.insertAdjacentHTML('beforeend', values.map(v => `<option${v === current ? ' selected' : ''}>${esc(v)}</option>`).join(''));
    }
    const cardsInto = (container, items, type) => {
        container.innerHTML = items.length ? items.map(item => card(item, type)).join('') : `<p class="empty-state">${type === 'studio' ? 'No studios match your search yet. Try another city.' : 'Nothing matches your search yet. Try another genre or name.'}</p>`;
        if (type === 'track') bindCardActions(container);
    };
    const renderList = async (endpoint, type, target = '#results') => {
        const form = $('#search-form');
        const params = {};
        if (form) new FormData(form).forEach((value, key) => { if (String(value).trim()) params[key] = String(value).trim(); });
        const hasFilters = Boolean(params.q || params.genre);
        let items;
        if (type === 'track') {
            const perPage = 50;
            const firstPage = await call('get', endpoint, { ...params, per_page: perPage, page: 1 });
            const totalPages = Math.min(Number(firstPage && firstPage.meta && firstPage.meta.pages) || 1, 6);
            items = list(firstPage);
            for (let page = 2; page <= totalPages; page++) items.push(...list(await call('get', endpoint, { ...params, per_page: perPage, page })));
        } else {
            items = list(await call('get', endpoint, params));
        }
        const container = $(target);
        if (container) cardsInto(container, items, type);
        if (type !== 'track') return;

        // Spotify: search results when filtering, the team's featured picks otherwise.
        const searchBox = $('#spotify-search-results');
        const featuredBox = $('#featured-spotify-music');
        if (hasFilters) {
            if (featuredBox) featuredBox.innerHTML = '';
            try {
                const found = list(await window.api.get('/spotify/search', { q: params.q, genre: params.genre, limit: 8 }))
                    .map(x => ({ embed_type: 'track', spotify_id: x.id, title: x.title, artist: x.artist, cover_url: x.image_url }));
                searchBox.innerHTML = found.length ? spotifySection('Also on Spotify', found) : '';
            } catch (error) {
                // Spotify search needs credentials on the server; without them the section is simply left out.
                searchBox.innerHTML = '';
            }
        } else {
            searchBox.innerHTML = '';
            try {
                const featured = list(await window.api.get('/home/featured-spotify'));
                featuredBox.innerHTML = spotifySection('Featured on Spotify', featured, 'Picked by the Nile Vijana team. Songs play as previews, or in full if you are signed in to Spotify.');
            } catch (_) {
                featuredBox.innerHTML = '';
            }
        }
    };
    const detail = (title, type) => `<section class="section"><div class="container" id="detail"><p>Loading ${title.toLowerCase()}…</p></div></section>`;
    const pageData = {
        'index': ['Home', 'Stream music from Ghanaian artists, license beats, book recording studios and grow your creative career.'],
        'discover-artists': ['Artists and choirs', 'Find and follow artists and choirs from Accra, Kumasi, Cape Coast and across Ghana.'],
        'albums': ['Albums', 'Complete projects and releases from independent artists.'],
        'managers': ['Artist managers', 'Experienced managers who represent artists and choirs.'],
        'vocal-coaches': ['Vocal coaches', 'Book a one-to-one session with a vocal coach.'],
        'qa': ['Help and Q&A', 'Ask the Nile Vijana team a question about payments, licences, bookings or your account.'],
        'services': ['Services', 'Release and sell music, license beats, book studios and find vocal coaches.'],
        'browse-tracks': ['Music', 'New music from Ghana and beyond.'],
        'browse-beats': ['Beats', 'License beats from Ghanaian producers.'],
        'studios': ['Recording studios', 'Book studio time in Accra, Kumasi and beyond.'],
        'track': ['Song', 'Listen to music and support its creator.'],
        'beat': ['Beat', 'Preview and license a beat.'],
        'studio': ['Studio', 'Rooms, rates and availability.'],
        'creator': ['Creator', 'Music and beats from this creator.'],
        'cart': ['Your cart', 'Review the beats and songs you have selected.'],
        'checkout': ['Checkout', 'Pay securely with Paystack.'],
        'checkout-success': ['Order status', 'Thank you for supporting independent creators.'],
        'payment': ['Payment', 'Checking your payment with Paystack.'],
        'orders': ['My orders', 'Your purchases, downloads and licences.'],
        'login': ['Sign in', 'Sign in to Nile Vijana Creatives.'],
        'register': ['Create account', 'Join Nile Vijana Creatives.'],
        'verify-otp': ['Enter your code', 'Finish signing in with the code sent to your email.'],
        'logout': ['Signing out', 'Signing you out.']
    };

    /* ---------- layout: navigation bar, footer ---------- */
    const NAV = [
        ['index', 'index.html', 'Home', 'home'],
        ['browse-tracks', 'browse-tracks.html', 'Music', 'music'],
        ['browse-beats', 'browse-beats.html', 'Beats', 'beat'],
        ['studios', 'studios.html', 'Studios', 'studio']
    ];
    const CREATORS_MENU = [
        ['discover-artists', 'discover-artists.html', 'Artists and choirs', 'Find and follow new voices', 'users'],
        ['vocal-coaches', 'vocal-coaches.html', 'Vocal coaches', 'Book a one-to-one session', 'mic'],
        ['managers', 'managers.html', 'Artist managers', 'Professional representation', 'briefcase']
    ];

    function shell(page) {
        const user = window.api.getUser();
        const admin = Array.isArray(user && user.roles) && user.roles.includes('admin');
        const name = user && (user.name || user.stage_name) || 'Account';
        const initials = name.split(/\s+/).map(w => w[0] || '').join('').slice(0, 2).toUpperCase();
        const creatorsActive = CREATORS_MENU.some(([key]) => key === page);
        const navLink = ([key, href, label, icon]) => `<a class="nav-link${key === page ? ' active' : ''}" href="${root}${href}"${key === page ? ' aria-current="page"' : ''}>${svg(ICONS[icon], 'nav-ico')}<span>${label}</span></a>`;
        const account = token()
            ? `<div class="dropdown nav-account"><button class="avatar-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Account menu for ${esc(name)}"><span class="avatar-initials">${esc(initials || 'NV')}</span></button>
                <div class="dropdown-menu" role="menu"><div class="dropdown-header"><strong>${esc(name)}</strong>${user && user.username ? `<span>@${esc(user.username)}</span>` : ''}</div>
                    <a class="dropdown-item" role="menuitem" href="${root}dashboard/index.html">${svg(ICONS.grid)}${admin ? 'Admin dashboard' : 'My dashboard'}</a>
                    <a class="dropdown-item" role="menuitem" href="${root}orders.html">${svg(ICONS.receipt)}Orders and downloads</a>
                    <a class="dropdown-item" role="menuitem" href="${root}dashboard/index.html#/payments">${svg(ICONS.lock)}Payment history</a>
                    <a class="dropdown-item" role="menuitem" href="${root}dashboard/index.html#/my-questions">${svg(ICONS.help)}My questions</a>
                    <a class="dropdown-item dropdown-item--muted" role="menuitem" href="${root}auth/logout.html">Sign out</a></div></div>`
            : `<a class="btn btn-text nav-signin" href="${root}auth/login.html">Sign in</a><a class="btn btn-primary nav-join" href="${root}auth/register.html">Join free</a>`;
        return `<a class="skip-link" href="#page-content">Skip to content</a>
        <nav class="navbar" aria-label="Main navigation"><div class="container navbar-container">
            <a href="${root}index.html" class="brand" aria-label="Nile Vijana Creatives home"><img class="brand-logo" src="${root}assets/images/main_logo.svg" alt="Nile Vijana Creatives"></a>
            <div class="nav-links" id="primary-navigation">
                ${NAV.map(navLink).join('')}
                <div class="nav-group${creatorsActive ? ' active' : ''}">
                    <button class="nav-link nav-group-btn${creatorsActive ? ' active' : ''}" type="button" aria-expanded="false" aria-controls="nav-creators">${svg(ICONS.users, 'nav-ico')}<span>Creators</span>${svg(ICONS.chevron, 'nav-caret')}</button>
                    <div class="nav-submenu" id="nav-creators">${CREATORS_MENU.map(([key, href, label, sub, icon]) => `<a href="${root}${href}"${key === page ? ' aria-current="page"' : ''}>${svg(ICONS[icon])}<span><strong>${label}</strong><small>${sub}</small></span></a>`).join('')}</div>
                </div>
                ${navLink(['services', 'services.html', 'Services', 'grid'])}
                <div class="nav-mobile-extra">
                    <a class="nav-link${page === 'qa' ? ' active' : ''}" href="${root}q-and-a.html">${svg(ICONS.help, 'nav-ico')}<span>Help and Q&amp;A</span></a>
                    ${token() ? '' : `<div class="nav-mobile-auth"><a class="btn btn-outline" href="${root}auth/login.html">Sign in</a><a class="btn btn-primary" href="${root}auth/register.html">Join free</a></div>`}
                </div>
            </div>
            <div class="nav-actions">
                <a class="nav-icon nav-help${page === 'qa' ? ' active' : ''}" href="${root}q-and-a.html" aria-label="Help and questions" title="Help and Q&amp;A">${svg(ICONS.help)}</a>
                <a class="nav-icon nav-cart" href="${root}cart.html" aria-label="Cart" title="Cart"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 1.9-1.4L21 9H6" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/><circle cx="10" cy="20" r="1.4" fill="currentColor"/><circle cx="18" cy="20" r="1.4" fill="currentColor"/></svg><span id="cart-count" class="cart-badge" aria-live="polite"></span></a>
                ${account}
                <button class="nav-toggle" type="button" aria-label="Open the menu" aria-controls="primary-navigation" aria-expanded="false"><span></span><span></span><span></span></button>
            </div>
        </div><div class="nv-fabric" aria-hidden="true"></div></nav>
        <main class="main-content" tabindex="-1"><div id="page-content"></div></main>
        <footer class="footer"><div class="nv-fabric" aria-hidden="true"></div><div class="container">
            <div class="footer-grid">
                <div class="footer-about"><div class="brand brand-footer"><img class="brand-logo brand-logo-footer" src="${root}assets/images/footerlogo.svg" alt="Nile Vijana Creatives"></div>
                    <p class="footer-tagline">A home for African music and creative professionals: artists, producers, studios and the fans who support them. Built in Ghana.</p>
                    <p class="footer-pay">${svg(ICONS.lock)}Payments in Ghana cedis, secured by Paystack: Mobile Money and cards.</p></div>
                <div><h4 class="footer-heading">Discover</h4><ul class="footer-links"><li><a href="${root}browse-tracks.html">Music</a></li><li><a href="${root}browse-beats.html">Beat marketplace</a></li><li><a href="${root}studios.html">Recording studios</a></li><li><a href="${root}discover-artists.html">Artists and choirs</a></li></ul></div>
                <div><h4 class="footer-heading">For creators</h4><ul class="footer-links"><li><a href="${root}dashboard/index.html#/upload">Release music or beats</a></li><li><a href="${root}dashboard/index.html#/studio/new">List your studio</a></li><li><a href="${root}vocal-coaches.html">Vocal coaching</a></li><li><a href="${root}dashboard/index.html">Creator dashboard</a></li></ul></div>
                <div><h4 class="footer-heading">Support</h4><ul class="footer-links"><li><a href="${root}q-and-a.html">Help and Q&amp;A</a></li><li><a href="${root}q-and-a.html#ask">Ask a question</a></li><li><a href="${root}orders.html">Orders and downloads</a></li><li><a href="${root}services.html">All services</a></li></ul></div>
            </div>
            <div class="footer-bottom"><p>© ${new Date().getFullYear()} Nile Vijana Creatives · Accra, Ghana</p><p>Creators keep the rights to their work. Licences grant permission to use it.</p></div>
        </div></footer><div id="toast-container" class="toast-container"></div>`;
    }

    /* ---------- sign-in, sign-up and the email code ---------- */
    const TICKET_KEY = 'nv_signin';
    const saveTicket = data => { try { sessionStorage.setItem(TICKET_KEY, JSON.stringify(data)); } catch (_) { /* private mode */ } };
    const readTicket = () => { try { return JSON.parse(sessionStorage.getItem(TICKET_KEY) || 'null'); } catch (_) { return null; } };
    const clearTicket = () => { try { sessionStorage.removeItem(TICKET_KEY); } catch (_) { /* ignore */ } };
    const safeRedirect = () => {
        const r = query.get('redirect') || (readTicket() || {}).redirect || '';
        return /^[a-z0-9][\w\-/.]*\.html([?#].*)?$/i.test(r) ? `${root}${r}` : `${root}dashboard/index.html`;
    };
    const field = (label, control, hint = '') => `<label class="form-label" data-field="${control.match(/name="([^"]+)"/)[1]}"><span class="form-label-text">${label}</span>${control}${hint ? `<small class="form-hint">${hint}</small>` : ''}</label>`;
    const pwToggle = '<button class="pw-toggle" type="button" data-pw-toggle aria-label="Show password">Show</button>';

    function authForm(kind) {
        const register = kind === 'register';
        const head = register
            ? ['Create your account', 'Join artists, producers, studios and fans across Ghana. It is free.']
            : ['Welcome back', 'Sign in with your email address or username.'];
        const body = register
            ? `<form class="auth-form" id="auth-form" data-error-title="Your account could not be created. Please fix the following:" novalidate>
                ${field('Full name', '<input class="form-input" name="name" autocomplete="name" required minlength="2" maxlength="120" data-label="Full name">')}
                ${field('Username', '<input class="form-input" name="username" autocomplete="username" required maxlength="30" pattern="[A-Za-z][A-Za-z0-9._]{2,29}" data-label="Username" data-pattern-message="Username must be 3 to 30 characters, start with a letter, and use only letters, numbers, dots or underscores." placeholder="e.g. kofi.beats">', '<span data-username-hint>You can sign in with this or your email.</span>')}
                ${field('Email address', '<input class="form-input" name="email" type="email" autocomplete="email" required maxlength="190" data-label="Email address">', 'We send your sign-in codes here.')}
                ${field('Phone number', '<input class="form-input" name="phone" type="tel" autocomplete="tel" required data-label="Phone number" placeholder="024 123 4567">', 'For payments and booking alerts. Not used to sign in.')}
                <div class="pw-wrap">${field('Password', `<input class="form-input" name="password" type="password" autocomplete="new-password" required data-label="Password" data-strength>${pwToggle}`)}</div>
                ${field('Confirm password', '<input class="form-input" name="password_confirmation" type="password" autocomplete="new-password" required data-label="Confirm password" data-match="password" data-match-message="The two passwords do not match.">')}
                <fieldset class="form-label role-picker" data-field="role"><legend class="form-label-text">I am joining as</legend>
                    <label class="role-option"><input type="radio" name="role" value="fan" checked><span><strong>Listener</strong><small>Stream, buy and support</small></span></label>
                    <label class="role-option"><input type="radio" name="role" value="creator"><span><strong>Artist or producer</strong><small>Release music, sell beats</small></span></label>
                    <label class="role-option"><input type="radio" name="role" value="studio"><span><strong>Studio owner</strong><small>Take bookings</small></span></label>
                </fieldset>
                <label class="form-checkbox" data-field="terms"><input type="checkbox" name="terms" required data-label="Terms" data-missing="Please accept the terms to create your account."> <span>I agree to the terms of use and understand that creators keep the rights to their work.</span></label>
                <button class="btn btn-primary btn-block" type="submit">Create account</button>
              </form>`
            : `<form class="auth-form" id="auth-form" data-error-title="We could not sign you in:" novalidate>
                ${field('Email or username', '<input class="form-input" name="login" autocomplete="username" required maxlength="190" data-label="Email or username" data-missing="Enter your email address or username.">')}
                <div class="pw-wrap">${field('Password', `<input class="form-input" name="password" type="password" autocomplete="current-password" required data-label="Password" data-missing="Enter your password.">${pwToggle}`)}</div>
                <p class="auth-row"><button class="btn-link" type="button" data-forgot>Forgot password?</button></p>
                <button class="btn btn-primary btn-block" type="submit">Continue</button>
                <p class="auth-secure">${svg(ICONS.lock)}After your password, we email you a one-time code to finish signing in.</p>
              </form>`;
        return `<section class="section auth-container"><div class="auth-card" id="auth-card">
            <div class="auth-header"><h1 class="auth-title">${head[0]}</h1><p class="auth-subtitle">${head[1]}</p></div>
            ${body}
            <div class="auth-footer">${register ? 'Already have an account? <a class="auth-link" href="login.html">Sign in</a>' : 'New to Nile Vijana? <a class="auth-link" href="register.html">Create an account</a>'}</div>
        </div></section>`;
    }

    /** Step 2 of signing in (and of password reset): the 6-digit code from the email. */
    function codeStep(card, challenge, mode = 'login') {
        const reset = mode === 'reset';
        card.innerHTML = `<div class="auth-header"><h1 class="auth-title">${reset ? 'Reset your password' : 'Check your email'}</h1>
            <p class="auth-subtitle">${challenge.email ? `We sent a 6-digit code to <strong>${esc(challenge.email)}</strong>.` : 'If an account matches, we sent a 6-digit code to its email.'} It works for ${Math.round((challenge.expires_in || 600) / 60)} minutes.</p></div>
            ${challenge.dev_code ? `<p class="dev-code" role="status">Development mode: your code is <strong>${esc(challenge.dev_code)}</strong> (emails are saved to backend/storage/logs/mail).</p>` : ''}
            <form class="auth-form" id="code-form" data-error-title="${reset ? 'Your password was not changed:' : 'We could not sign you in:'}" novalidate>
                ${field('6-digit code', '<input class="form-input code-input" name="code" inputmode="numeric" autocomplete="one-time-code" required pattern="\\s*\\d{3}\\s?\\d{3}\\s*" maxlength="8" data-label="Code" data-missing="Enter the 6-digit code from the email." data-pattern-message="The code has 6 digits.">')}
                ${reset ? `<div class="pw-wrap">${field('New password', `<input class="form-input" name="password" type="password" autocomplete="new-password" required data-label="New password" data-strength>${pwToggle}`)}</div>` : ''}
                <button class="btn btn-primary btn-block" type="submit">${reset ? 'Save new password and sign in' : 'Verify and sign in'}</button>
                <p class="auth-row"><button class="btn-link" type="button" data-resend disabled>Send a new code</button><span class="resend-timer" data-timer></span></p>
            </form>
            <div class="auth-footer"><a class="auth-link" href="${root}auth/login.html">Use a different account</a></div>`;
        const form = card.querySelector('#code-form');
        if (reset) core.attachStrength(form.querySelector('[name="password"]'));
        form.querySelector('[name="code"]').focus();
        let wait = Number(challenge.resend_in || 60);
        const resend = form.querySelector('[data-resend]');
        const timer = form.querySelector('[data-timer]');
        const tick = () => {
            resend.disabled = wait > 0;
            timer.textContent = wait > 0 ? `You can ask for a new code in ${wait} s` : '';
            if (wait-- > 0) setTimeout(tick, 1000);
        };
        tick();
        resend.addEventListener('click', async () => {
            resend.disabled = true;
            try {
                const res = await window.api.post(reset ? '/auth/password/resend' : '/auth/login/resend', { ticket: challenge.ticket });
                Object.assign(challenge, res);
                if (!reset) saveTicket({ ...readTicket(), ...challenge });
                toast(res.dev_code ? `New development code: ${res.dev_code}` : 'We sent a new code. The previous one no longer works.');
                const dev = card.querySelector('.dev-code strong');
                if (dev && res.dev_code) dev.textContent = res.dev_code;
                wait = Number(res.resend_in || 60);
                tick();
            } catch (error) {
                toast(error.message, 'error');
                if (error.errors && error.errors.restart) setTimeout(() => location.assign(`${root}auth/login.html`), 1500);
                resend.disabled = false;
            }
        });
        core.forms.guard(form, async f => {
            const code = f.code.value.replace(/\D/g, '');
            let result;
            try {
                result = reset
                    ? await window.api.post('/auth/password/reset', { ticket: challenge.ticket, code, password: f.password.value })
                    : await window.api.post('/auth/login/verify', { ticket: challenge.ticket, code });
            } catch (error) {
                if (error.errors && error.errors.restart) {
                    clearTicket();
                    toast(error.message, 'error');
                    setTimeout(() => location.assign(`${root}auth/login.html`), 1500);
                }
                throw error;
            }
            const redirect = safeRedirect();
            clearTicket();
            window.api.setToken(result.token);
            window.api.setUser(result.user || {});
            toast(reset ? 'Password changed. Welcome back!' : `Welcome, ${(result.user && result.user.name || '').split(' ')[0] || 'back'}!`);
            location.assign(redirect);
        });
    }

    function forgotStep(card) {
        card.innerHTML = `<div class="auth-header"><h1 class="auth-title">Forgot your password?</h1><p class="auth-subtitle">Enter your email address or username. We will email you a code to choose a new password.</p></div>
            <form class="auth-form" id="forgot-form" data-error-title="We could not send the code:" novalidate>
                ${field('Email or username', '<input class="form-input" name="login" autocomplete="username" required maxlength="190" data-label="Email or username">')}
                <button class="btn btn-primary btn-block" type="submit">Send the code</button>
            </form>
            <div class="auth-footer"><a class="auth-link" href="${root}auth/login.html">Back to sign in</a></div>`;
        const form = card.querySelector('#forgot-form');
        form.login.focus();
        core.forms.guard(form, async f => {
            const res = await window.api.post('/auth/password/forgot', { login: f.login.value.trim() });
            codeStep(card, res, 'reset');
        });
    }

    function bindAuth(page) {
        const card = $('#auth-card');
        document.addEventListener('click', e => {
            const t = e.target.closest('[data-pw-toggle]');
            if (!t) return;
            const input = t.parentElement.querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            t.textContent = show ? 'Hide' : 'Show';
            t.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
        if (page === 'verify-otp') {
            const challenge = readTicket();
            if (!challenge || !challenge.ticket) return location.replace(`${root}auth/login.html`);
            return codeStep(card, challenge);
        }
        const form = $('#auth-form');
        if (page === 'login') {
            form.querySelector('[data-forgot]').addEventListener('click', () => forgotStep(card));
            if (query.get('forgot')) forgotStep(card);
        }
        if (page === 'register') {
            core.attachStrength(form.querySelector('[name="password"]'));
            const username = form.querySelector('[name="username"]');
            const hint = form.querySelector('[data-username-hint]');
            let timer;
            username.addEventListener('input', () => {
                clearTimeout(timer);
                const value = username.value.trim();
                if (value.length < 3) { hint.textContent = 'You can sign in with this or your email.'; hint.className = ''; return; }
                timer = setTimeout(async () => {
                    try {
                        const r = await window.api.get('/auth/username', { username: value });
                        hint.textContent = r.available ? `@${r.username} is available.` : r.problem;
                        hint.className = r.available ? 'is-ok' : 'is-bad';
                    } catch (_) { /* offline: the server checks again on submit */ }
                }, 350);
            });
        }
        core.forms.guard(form, async f => {
            const values = Object.fromEntries(new FormData(f));
            delete values.terms;
            const res = page === 'login'
                ? await window.api.post('/auth/login', { login: values.login.trim(), password: values.password })
                : await window.api.post('/auth/register', values);
            if (res.token) {                                  // (not expected: codes are always required)
                window.api.setToken(res.token);
                window.api.setUser(res.user || {});
                return location.assign(safeRedirect());
            }
            saveTicket({ ...res, redirect: query.get('redirect') || '' });
            if (page === 'register') toast('Account created. We emailed you a welcome message and a code.');
            codeStep(card, res);
            history.replaceState(null, '', `${root}auth/verify-otp.html${query.get('redirect') ? `?redirect=${encodeURIComponent(query.get('redirect'))}` : ''}`);
        });
    }

    /** The music player lives in player.js / player.css; it is loaded once per page, after the shell. */
    const ASSET_VERSION = '2026-11-22';
    function loadPlayer() {
        if (!document.querySelector('link[data-nvp-css]')) {
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = `${root}assets/css/player.css?v=${ASSET_VERSION}`;
            css.dataset.nvpCss = '1';
            document.head.appendChild(css);
        }
        if (!window.NVPlayer && !document.querySelector('script[data-nvp-js]')) {
            const js = document.createElement('script');
            js.src = `${root}assets/js/player.js?v=${ASSET_VERSION}`;
            js.dataset.nvpJs = '1';
            document.head.appendChild(js);
        }
    }
    /** Resolves when the player is ready (it loads in the background). */
    const whenPlayer = () => window.NVPlayer ? Promise.resolve(window.NVPlayer)
        : new Promise(resolve => document.addEventListener('nvp:ready', () => resolve(window.NVPlayer), { once: true }));

    /* ---- card-level favourite / save actions (home page and browse pages) ---- */
    function bindCardActions(container) {
        container.addEventListener('click', async e => {
            const btn = e.target.closest('[data-card-action]');
            if (!btn) return;
            e.stopPropagation();
            const action = btn.dataset.cardAction;   // 'favorite' | 'save'
            const trackId = btn.dataset.trackId;
            if (!trackId) return;
            if (!window.api.getToken()) {
                const back = encodeURIComponent(location.pathname + location.search);
                toast('Sign in to ' + (action === 'favorite' ? 'favourite' : 'save') + ' songs.', 'info');
                setTimeout(() => location.assign(`${root}auth/login.html?redirect=${back}`), 1200);
                return;
            }
            btn.disabled = true;
            try {
                const on = btn.getAttribute('aria-pressed') !== 'true';
                const res = action === 'favorite'
                    ? await window.api[on ? 'post' : 'delete'](`/tracks/${trackId}/like`)
                    : await window.api[on ? 'post' : 'delete'](`/tracks/${trackId}/save`);
                btn.setAttribute('aria-pressed', String(on));
                btn.classList.toggle('is-active', on);
                /* update the sibling stat count */
                const info = btn.closest('.track-info');
                if (info) {
                    const statIdx = action === 'favorite' ? 1 : 2;
                    const statVal = info.querySelectorAll('.track-stat span')[statIdx];
                    if (statVal) statVal.textContent = Number(res.likes ?? res.saves ?? 0).toLocaleString();
                    /* swap save icon */
                    if (action === 'save') {
                        btn.innerHTML = on
                            ? svgIcon('<path d="M5 12l5 5L20 7"/>')
                            : svgIcon('<path d="M12 5v14M5 12h14"/>');
                    }
                }
                toast(action === 'favorite'
                    ? (on ? 'Added to your favourites.' : 'Removed from favourites.')
                    : (on ? 'Saved to your library.'    : 'Removed from your library.'));
            } catch (err) {
                toast(err.message || 'That did not work. Try again.', 'error');
            }
            btn.disabled = false;
        });
    }

    /* keep card stat counts live when the player reports a change */
    document.addEventListener('nvp:item', e => {
        const item = e.detail;
        if (!item || item.producer) return;   // beats don't have these stats
        document.querySelectorAll(`.track-card [data-track-id="${CSS.escape(String(item.id))}"]`).forEach(btn => {
            const info = btn.closest('.track-info');
            if (!info) return;
            const statSpans = info.querySelectorAll('.track-stat span');
            if (item.plays  != null && statSpans[0]) statSpans[0].textContent = Number(item.plays).toLocaleString();
            if (item.likes  != null && statSpans[1]) statSpans[1].textContent = Number(item.likes).toLocaleString();
            if (item.saves  != null && statSpans[2]) statSpans[2].textContent = Number(item.saves).toLocaleString();
        });
    });

    async function renderHome() {
        const content = $('#page-content');
        const user = window.api.getUser();
        const firstName = user && (user.name || user.stage_name) ? esc((user.name || user.stage_name).split(' ')[0]) : null;
        const welcomeMsg = firstName
            ? `Welcome back, ${firstName}!`
            : 'Welcome to Nile Vijana';

        content.innerHTML = `
            <section class="hero">
                <div class="container">
                    <nav class="home-sidebar" aria-label="Quick menu">
                        <p class="home-sidebar-welcome">${welcomeMsg}</p>
                        <p class="home-sidebar-group">Discover</p>
                        <a class="home-sidebar-link" href="${root}discover-artists.html">${svg(ICONS.users)}<span>Artists and choirs</span></a>
                        <a class="home-sidebar-link" href="${root}browse-tracks.html">${svg(ICONS.music)}<span>New music</span></a>
                        <p class="home-sidebar-group">Grow</p>
                        <a class="home-sidebar-link" href="${root}vocal-coaches.html">${svg(ICONS.mic)}<span>Vocal coaches</span></a>
                        <a class="home-sidebar-link" href="${root}managers.html">${svg(ICONS.briefcase)}<span>Artist managers</span></a>
                        <a class="home-sidebar-link" href="${root}${token() ? 'dashboard/index.html#/upload' : 'auth/register.html'}">${svg(ICONS.upload)}<span>Release your music</span></a>
                        <p class="home-sidebar-group">Support</p>
                        <a class="home-sidebar-link" href="${root}q-and-a.html">${svg(ICONS.help)}<span>Help and Q&amp;A</span></a>
                    </nav>
                    <div class="hero-content">
                        <div class="hero-tagline">
                            <p class="hero-tagline-sub">Make your artistic dream come true with</p>
                            <h1 class="hero-tagline-title">Nile Vijana<br><span>Creatives</span></h1>
                            <p class="hero-tagline-desc">Stream new music from Ghanaian artists, license beats from local producers, book studios in Accra, Kumasi and beyond, and get paid in cedis.</p>
                            <div class="hero-tagline-actions">
                                <a class="hero-tagline-btn" href="${root}browse-tracks.html">Listen now &nbsp;→</a>
                                <a class="hero-tagline-btn hero-tagline-btn--outline" href="${root}browse-beats.html">Find a beat</a>
                            </div>
                        </div>
                    </div>
                    <div class="hero-visual">
                        <figure class="hero-artist-frame">
                            <img class="hero-artist-image" src="${root}assets/images/artist.svg" alt="Artist portrait in the Nile Vijana brand palette">
                            <figcaption class="hero-artist-caption">Creative voices · East Africa</figcaption>
                        </figure>
                    </div>
                </div>
            </section>
            <div id="home-tracks"></div>
            <div id="home-beats"></div>
            <div id="home-spotify-tracks"></div>
            <div id="home-spotify-playlists"></div>
            <div id="home-studios"></div>
            <section class="section cta-section">
                <div class="container">
                    <div class="cta-card">
                        <div class="cta-content">
                            <h2 class="cta-title">Ready to share your music?</h2>
                            <p class="cta-text">Release songs, sell beat licences or list your studio. You keep the rights to your work, see every stream and sale, and withdraw your earnings to Mobile Money.</p>
                            <a class="btn btn-primary btn-lg" href="${token() ? root + 'dashboard/index.html' : root + 'auth/register.html'}">${token() ? 'Go to my dashboard' : 'Create a free account'}</a>
                        </div>
                    </div>
                </div>
            </section>`;

        /* ---- uploaded tracks + beats + studios ---- */
        for (const [type, endpoint, target, label, cls] of [
            ['track',  '/tracks?per_page=6&sort=popular', '#home-tracks',  'Trending songs', 'track-grid'],
            ['beat',   '/beats?per_page=6&sort=newest',   '#home-beats',   'Fresh beats',    'beat-grid'],
            ['studio', '/studios?per_page=4',              '#home-studios', 'Recording studios', 'studio-grid']
        ]) {
            const data = await call('get', endpoint);
            const items = list(data);
            const section = $(target);
            if (!items.length) continue;
            section.innerHTML = `<section class="section"><div class="container">
                <div class="section-header">
                    <div><h2 class="section-title">${label}</h2></div>
                    <a class="btn btn-text" href="${type === 'track' ? 'browse-tracks.html' : type === 'beat' ? 'browse-beats.html' : 'studios.html'}">View all</a>
                </div>
                <div class="${cls}" data-play-scope>${items.map(item => card(item, type)).join('')}</div>
            </div></section>`;
            bindCardActions(section);

            if (type === 'studio') {
                const studios = items.slice(0, 3);
                const heroStudios = document.getElementById('featured-studios');
                if (heroStudios) {
                    heroStudios.innerHTML = studios.length ? studios.map(item => {
                        const room  = Array.isArray(item.rooms) ? item.rooms.find(r => r && r.photo_url) : null;
                        const image = item.cover_url || item.image_url || (room && room.photo_url) || `${root}assets/images/studio-placeholder.svg`;
                        const price = item.price_from ?? item.hourly_rate;
                        const priceLabel = price != null && Number.isFinite(Number(price))
                            ? `${Number(price).toLocaleString()} GHS / hour` : 'Price on request';
                        const detailUrl = `${root}studio.html?id=${encodeURIComponent(item.id)}`;
                        return `<article class="home-studio-card">
                            <a class="home-studio-cover" href="${detailUrl}" aria-label="View ${esc(item.name || 'studio')}">
                                <img src="${esc(image)}" alt="${esc(item.name || 'Recording studio')}" loading="lazy">
                            </a>
                            <div class="home-studio-info">
                                <h3>${esc(item.name || 'Recording Studio')}</h3>
                                <p>${esc(item.description || 'Studio description not available.')}</p>
                                <div class="home-studio-card-footer">
                                    <strong>${esc(priceLabel)}</strong>
                                    <a class="btn btn-primary" href="${detailUrl}">Book Studio</a>
                                </div>
                            </div>
                        </article>`;
                    }).join('') : '';
                }
            }
        }

        /* ---- Spotify content: same cards as our own music, playing in Spotify's player ---- */
        try {
            const embeds = list(await call('get', '/home/featured-spotify'));
            const box = $('#home-spotify-tracks');
            if (box) box.innerHTML = spotifySection('Featured on Spotify', embeds, 'Songs, albums and playlists picked by the Nile Vijana team. Songs play as previews, or in full if you are signed in to Spotify.');
            const old = $('#home-spotify-playlists');
            if (old) old.remove();
        } catch (error) {
            console.error('Featured Spotify content could not be loaded:', error);
        }
    }

    /* ---------- song and beat pages (share the player's actions) ---------- */
    const ico = (name, cls) => (window.NVPlayer ? window.NVPlayer.icon(name, cls) : '');
    const statChips = list => `<ul class="nvp-stats">${list.map(([icon, text]) => `<li>${ico(icon)}<span>${esc(text)}</span></li>`).join('')}</ul>`;
    const countLabel = (n, one, many) => `${Number(n || 0).toLocaleString()} ${Number(n) === 1 ? one : many}`;

    /** Keeps a page's play button in step with the player. */
    function bindPlayState(button, kind, id) {
        const update = () => {
            const now = window.NVPlayer && window.NVPlayer.current();
            const on = Boolean(now && now.item && now.item.kind === kind && String(now.item.id) === String(id) && now.playing);
            button.classList.toggle('is-playing', on);
            button.setAttribute('aria-label', on ? 'Pause' : 'Play');
        };
        document.addEventListener('nvp:state', update);
        update();
    }
    function playThis(kind, item, cover, artist) {
        whenPlayer().then(player => {
            const now = player.current();
            if (now.item && now.item.kind === kind && String(now.item.id) === String(item.id)) return player.toggle();
            player.playItems([{ kind, id: item.id, title: item.title, artist, cover }], 0);
        });
    }

    async function renderSongPage(item) {
        const player = await whenPlayer();
        const creator = item.creator || {};
        const cover = item.cover_url || '';
        const priced = Number(item.price) > 0;
        const box = $('#detail');
        const draw = () => {
            box.innerHTML = `<article class="np-page" style="--np-cover:${cover ? `url('${esc(cover)}')` : 'none'}">
                <div class="np-hero">
                    ${cover ? `<img class="np-cover" src="${esc(cover)}" alt="Cover of ${esc(item.title)}">` : `<div class="np-cover" aria-hidden="true"></div>`}
                    <div class="np-info">
                        <div class="np-kicker"><span class="nvp-chip">Song</span>${item.genre ? `<span class="nvp-chip">${esc(item.genre)}</span>` : ''}${item.language ? `<span class="nvp-chip">${esc(item.language)}</span>` : ''}</div>
                        <h1 class="np-title">${esc(item.title)}</h1>
                        <p class="np-by">by ${creator.slug ? `<a href="creator.html?slug=${encodeURIComponent(creator.slug)}">${esc(creator.stage_name || 'Unknown artist')}</a>` : esc(creator.stage_name || 'Unknown artist')}</p>
                        ${statChips([['headphones', countLabel(item.plays, 'stream', 'streams')], ['heart', countLabel(item.likes, 'favourite', 'favourites')], ['saved', countLabel(item.saves, 'save', 'saves')]])}
                        <div class="np-actions">
                            <button class="np-play" type="button" data-np="play" aria-label="Play">${ico('play', 'is-play')}${ico('pause', 'is-pause')}</button>
                            <button class="np-round" type="button" data-np="favorite" aria-pressed="${Boolean(item.liked)}" aria-label="${item.liked ? 'Remove from favourites' : 'Add to favourites'}" title="Favourite">${ico('heart')}</button>
                            <button class="np-round" type="button" data-np="save" aria-pressed="${Boolean(item.saved)}" aria-label="${item.saved ? 'Remove from my library' : 'Save to my library'}" title="Save">${ico(item.saved ? 'saved' : 'save')}</button>
                            <button class="np-round" type="button" data-np="share" aria-label="Share" title="Share">${ico('share')}</button>
                            <button class="nvp-btn" type="button" data-np="tip">${ico('tip')} Tip ${esc(creator.stage_name || 'the artist')}</button>
                            ${item.can_download || priced || item.allow_download ? `<button class="nvp-btn nvp-btn-primary" type="button" data-np="download">${ico(item.can_download || !priced ? 'download' : 'cart')} ${item.can_download || !priced ? 'Download' : `Buy and download, ${esc(core.money(item.price))}`}</button>` : ''}
                        </div>
                    </div>
                </div>
                <div class="np-body">
                    <section class="np-about"><h2>About this song</h2><p>${esc(item.description || `${item.title} by ${creator.stage_name || 'this artist'} on Nile Vijana.`)}</p></section>
                    <aside class="np-side" aria-label="Song details">
                        <dl class="np-facts"><div><dt>Streams</dt><dd>${Number(item.plays || 0).toLocaleString()}</dd></div><div><dt>Favourites</dt><dd>${Number(item.likes || 0).toLocaleString()}</dd></div><div><dt>Saves</dt><dd>${Number(item.saves || 0).toLocaleString()}</dd></div></dl>
                        <p class="form-hint">${item.release_date ? `Released ${esc(core.date(item.release_date))}. ` : ''}${priced ? `Download price ${esc(core.money(item.price))}.` : item.allow_download ? 'Free download for signed-in listeners.' : 'Streaming only.'} A stream counts after 30 seconds of listening.</p>
                    </aside>
                </div>
            </article>`;
            bindPlayState(box.querySelector('[data-np="play"]'), 'track', item.id);
        };
        draw();
        box.addEventListener('click', async event => {
            const btn = event.target.closest('[data-np]');
            if (!btn) return;
            const what = btn.dataset.np;
            try {
                if (what === 'play') return playThis('track', item, cover, creator.stage_name || '');
                if (what === 'favorite') { btn.disabled = true; await player.actions.favorite(item); }
                if (what === 'save') { btn.disabled = true; await player.actions.save(item); }
                if (what === 'share') player.actions.share(item, 'track');
                if (what === 'tip') player.actions.tip(creator);
                if (what === 'download') player.actions.download(item);
            } catch (error) {
                toast(error.message || 'That did not work. Try again.', 'error');
            }
            btn.disabled = false;
        });
        // counts change from the player (a stream counted, a favourite added there)
        document.addEventListener('nvp:item', e => {
            if (!e.detail || Number(e.detail.id) !== Number(item.id) || e.detail.producer) return;
            Object.assign(item, e.detail);
            draw();
        });
    }

    async function renderBeatPage(item) {
        const player = await whenPlayer();
        const producer = item.producer || {};
        const cover = item.cover_url || '';
        const licenses = (item.licenses || []).filter(l => l.active !== false);
        const box = $('#detail');
        box.innerHTML = `<article class="np-page" style="--np-cover:${cover ? `url('${esc(cover)}')` : 'none'}">
            <div class="np-hero">
                ${cover ? `<img class="np-cover" src="${esc(cover)}" alt="Cover of ${esc(item.title)}">` : '<div class="np-cover" aria-hidden="true"></div>'}
                <div class="np-info">
                    <div class="np-kicker"><span class="nvp-chip">Beat</span>${item.genre ? `<span class="nvp-chip">${esc(item.genre)}</span>` : ''}${item.bpm ? `<span class="nvp-chip">${esc(item.bpm)} BPM</span>` : ''}${item.key ? `<span class="nvp-chip">${esc(item.key)}</span>` : ''}</div>
                    <h1 class="np-title">${esc(item.title)}</h1>
                    <p class="np-by">prod. by ${producer.slug ? `<a href="creator.html?slug=${encodeURIComponent(producer.slug)}">${esc(producer.stage_name || 'Unknown producer')}</a>` : esc(producer.stage_name || 'Unknown producer')}</p>
                    ${statChips([['headphones', countLabel(item.plays, 'preview play', 'preview plays')]])}
                    <div class="np-actions">
                        <button class="np-play" type="button" data-np="play" aria-label="Play the preview"${item.preview_url ? '' : ' disabled'}>${ico('play', 'is-play')}${ico('pause', 'is-pause')}</button>
                        <button class="np-round" type="button" data-np="share" aria-label="Share" title="Share">${ico('share')}</button>
                        ${item.status === 'sold_exclusive' ? '<span class="nvp-chip">Sold exclusively</span>' : `<button class="nvp-btn nvp-btn-primary" type="button" data-np="buy"${licenses.length ? '' : ' disabled'}>${ico('cart')} Buy licence${item.price_from ? `, from ${esc(core.money(item.price_from))}` : ''}</button>`}
                    </div>
                </div>
            </div>
            <div class="np-body">
                <section class="np-about"><h2>About this beat</h2><p>${esc(item.description || 'Listen to the tagged preview, then choose the licence that fits your release.')}</p>${(item.tags || []).length ? `<p class="np-kicker" style="margin-top:12px">${item.tags.map(t => `<span class="nvp-chip">${esc(t)}</span>`).join('')}</p>` : ''}</section>
                <aside class="np-side" aria-label="Licences">
                    <h2>Licences</h2>
                    <div class="np-license-list">${licenses.map(l => `<div class="np-license"><div><strong>${esc(l.name || l.tier)}</strong><small>${esc((l.files || []).map(f => f.toUpperCase()).join(' + ') || 'MP3')}${l.stream_cap ? `, up to ${Number(l.stream_cap).toLocaleString()} streams` : l.tier === 'exclusive' ? ', full rights' : ', unlimited streams'}</small></div><b>${esc(core.money(l.price))}</b></div>`).join('') || '<p class="form-hint">No licence is for sale right now.</p>'}</div>
                    <p class="form-hint">Artists and listeners can buy. After paying you download the files and a signed licence PDF from My orders.</p>
                </aside>
            </div>
        </article>`;
        bindPlayState(box.querySelector('[data-np="play"]'), 'beat', item.id);
        box.addEventListener('click', event => {
            const btn = event.target.closest('[data-np]');
            if (!btn) return;
            if (btn.dataset.np === 'play') playThis('beat', item, cover, producer.stage_name || '');
            if (btn.dataset.np === 'share') player.actions.share(item, 'beat');
            if (btn.dataset.np === 'buy') player.actions.buyBeat(item);
        });
    }

    /** "From 15,000 GHS per hour", using the cheapest open room when the API gives no summary. */
    function studioPrice(item) {
        const rates = (item.rooms || []).map(room => Number(room.hourly_rate)).filter(rate => rate > 0);
        const from = Number(item.price_from) || (rates.length ? Math.min(...rates) : 0);
        return from ? `From ${core.money(from)} per hour` : 'Price on request';
    }

    async function renderDetail(type) {
        const id = query.get('id') || query.get('slug') || '';
        let response;
        try {
            response = await window.api.get(`/${type === 'creator' ? 'creators' : type === 'studio' ? 'studios' : type + 's'}/${encodeURIComponent(id)}`);
        } catch (error) {
            if (error.status === 404) {
                $('#detail').innerHTML = `<div class="empty-state"><h1>${esc(type)} not found</h1><p>This item may have been removed or the link may be incorrect.</p><a class="btn btn-primary" href="${root}index.html">Return home</a></div>`;
                return;
            }
            toast(error.message || 'This item could not be loaded.', 'error');
            throw error;
        }
        const data = obj(response);
        const item = data.data || data[type] || data;
        if (type === 'track') return renderSongPage(item);
        if (type === 'beat') return renderBeatPage(item);
        if (type === 'creator') return renderCreatorPage(data);
        const title = item.title || item.name || 'Details';
        const image = item.cover_url || item.avatar_url || '';
        const creator = item.creator || item.producer || {};
        $('#detail').innerHTML = `<div class="detail-layout">${image ? `<img class="detail-cover" src="${esc(image)}" alt="${esc(title)}">` : ''}<div><p class="eyebrow">${esc(type)}</p><h1 class="section-title">${esc(title)}</h1><p class="section-subtitle">${esc(item.description || item.bio || '')}</p>${item.genre ? `<span class="badge">${esc(item.genre)}</span>` : ''}${item.release_date ? ` <span class="badge">Released ${esc(core.date(item.release_date))}</span>` : ''}<p>${creator.stage_name ? `By <a href="creator.html?slug=${encodeURIComponent(creator.slug || '')}">${esc(creator.stage_name)}</a>` : esc(item.city || '')}</p><p>${type === 'studio' ? studioPrice(item) : ''}</p>
            ${type === 'studio' ? `<form class="auth-form" id="booking-form" data-error-title="Your booking could not be requested. Please check the following:" novalidate><label class="form-label" data-field="room_id"><span class="form-label-text">Room</span><select class="form-input" name="room_id" required data-label="Room">${(item.rooms || []).map(room => `<option value="${esc(room.id)}">${esc(room.name)}: ${esc(core.money(room.hourly_rate))} per hour, at least ${esc(room.min_hours || 1)} h</option>`).join('')}</select></label><label class="form-label" data-field="start_at"><span class="form-label-text">Date and start time</span><input class="form-input" type="datetime-local" name="start_at" step="3600" required data-label="Date and start time" data-missing="Choose the date and start time of your session."></label><label class="form-label" data-field="hours"><span class="form-label-text">Number of hours</span><input class="form-input" type="number" min="1" max="12" value="2" name="hours" required data-label="Number of hours"></label><label class="form-label" data-field="notes"><span class="form-label-text">Notes for the studio (optional)</span><textarea class="form-input" name="notes" maxlength="500" placeholder="What you are recording, who is coming, any gear you need"></textarea></label><p class="form-hint" data-deposit-hint>You pay a ${Math.round(Number((window.NVMeta || {}).booking && window.NVMeta.booking.deposit_rate || 0.3) * 100)}% deposit now with Paystack (Mobile Money or card). The studio then accepts or declines; if it declines or does not answer within 48 hours, the deposit goes back to your Nile Vijana wallet.</p><button class="btn btn-primary" ${!(item.rooms || []).length ? 'disabled' : ''}>Request booking and pay deposit</button></form><div id="booking-status" class="booking-status" aria-live="polite" hidden></div>` : `<button class="btn btn-primary" id="follow-creator">${item.is_following ? 'Following' : 'Follow Creator'}</button>`}</div></div>`;
        const bookingForm = $('#booking-form');
        if (bookingForm) core.forms.guard(bookingForm, async form => {
            if (!token()) return location.assign(`${root}auth/login.html?redirect=${encodeURIComponent(`studio.html?id=${id}`)}`);
            const values = Object.fromEntries(new FormData(form));
            values.room_id = Number(values.room_id);
            values.hours = Number(values.hours);
            values.start_at = String(values.start_at || '').replace('T', ' ');
            const booking = obj(await window.api.post('/bookings', values));
            trackBooking(booking);
            const result = await core.pay(booking.payment);
            if (result === 'cancelled') toast('Payment cancelled. Your time slot is held for a few minutes if you want to try again.', 'info');
            if (result === 'success') {
                const p = await core.confirmPayment(booking.payment.reference).catch(() => null);
                trackBooking((p && p.booking) || booking);
            }
        });
        $('#follow-creator')?.addEventListener('click', async () => {
            if (!token()) return location.assign(`${root}auth/login.html?redirect=creator.html?slug=${encodeURIComponent(id)}`);
            if (!item.id) return;
            await call(item.is_following ? 'delete' : 'post', `/creators/${encodeURIComponent(item.id)}/follow`);
            item.is_following = !item.is_following;
            $('#follow-creator').textContent = item.is_following ? 'Following' : 'Follow Creator';
        });
    }

    /** Shows the booking request and follows it: deposit payment -> waiting for the studio -> accepted. */
    async function trackBooking(booking) {
        const box = $('#booking-status');
        if (!box) return;
        box.hidden = false;
        const states = {
            held: ['Pay the deposit to send your request', 'Complete the deposit on the Paystack checkout. The time slot is held for a few minutes while you pay.'],
            pending: ['Request sent to the studio', 'Your deposit is paid and held safely. The studio will accept or decline within 48 hours.'],
            confirmed: ['Booking confirmed', 'The studio accepted your session. Pay the rest at the studio or from your bookings page.'],
            rejected: ['The studio could not take this booking', 'Your deposit was returned to your Nile Vijana wallet.'],
            cancelled: ['Booking cancelled', 'This request was cancelled.'],
            expired: ['Deposit not received', 'The slot was released because the payment was not approved in time. You can try again.']
        };
        const draw = b => {
            const [title, text] = states[b.status] || ['Booking update', ''];
            box.dataset.status = b.status;
            box.innerHTML = `<h3>${esc(title)}</h3><p>${esc(text)}</p>${b.studio_note ? `<p><strong>Studio note:</strong> ${esc(b.studio_note)}</p>` : ''}<dl class="booking-facts"><div><dt>Booking</dt><dd>#${esc(b.id)}</dd></div><div><dt>When</dt><dd>${esc(core.dateTime(b.start_at))} · ${esc(b.hours)} h</dd></div><div><dt>Deposit</dt><dd>${money(b.deposit)}</dd></div><div><dt>Total</dt><dd>${money(b.total)}</dd></div></dl>${['pending', 'confirmed'].includes(b.status) ? `<a class="btn btn-outline btn-sm" href="${root}dashboard/index.html#/my-bookings">See my bookings</a>` : ''}`;
        };
        draw(booking);
        if (booking.status === 'held' && booking.payment && booking.payment.checkout_url) {
            box.insertAdjacentHTML('beforeend', `<p><button class="btn btn-primary btn-sm" type="button" data-pay-again>Pay the deposit now</button></p>`);
            box.querySelector('[data-pay-again]').addEventListener('click', () => core.pay(booking.payment));
        }
        for (let i = 0; i < 40 && booking.status === 'held'; i++) {
            await new Promise(resolve => setTimeout(resolve, 3000));
            try { booking = obj(await window.api.get(`/bookings/${encodeURIComponent(booking.id)}`)); } catch (_) { break; }
            draw(booking);
        }
    }

    /** Vocal coaches onboarded by the team (GET /api/mentors) and booking a session with one. */
    async function renderCoaches(content) {
        content.innerHTML = `<section class="section"><div class="container"><h1 class="section-title">Vocal coaches</h1><p class="section-subtitle">Book a one-to-one session to work on your voice, breathing and stage presence.</p><div class="coach-grid" id="coach-list"><p role="status">Loading coaches…</p></div></div></section>`;
        const coaches = list(await call('get', '/mentors', { per_page: 50 }));
        const box = $('#coach-list');
        if (!coaches.length) {
            box.innerHTML = '<p class="empty-state">No vocal coaches are listed yet. Check back soon.</p>';
            return;
        }
        const soon = new Date(Date.now() + 86400000);
        soon.setMinutes(0, 0, 0);
        const local = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        box.innerHTML = coaches.map(c => `<article class="coach-card">
            <div class="coach-head">${c.avatar_url ? `<img src="${esc(c.avatar_url)}" alt="" loading="lazy">` : `<span class="coach-initials">${esc((c.stage_name || '?').slice(0, 2).toUpperCase())}</span>`}<div><h2>${esc(c.stage_name)}</h2><p>${esc(c.city || '')}</p></div></div>
            <p class="coach-headline">${esc(c.headline || '')}</p><p class="form-hint">${esc(c.specialties || '')}</p>
            <details class="coach-book"><summary class="btn btn-outline btn-sm">Book a session</summary>
                <form class="auth-form" data-coach="${esc(c.id)}"><label class="form-label">When<input class="form-input" type="datetime-local" name="start_at" min="${local(new Date())}" value="${local(soon)}" required></label>
                <label class="form-label">Length<select class="form-input" name="duration_minutes"><option value="30">30 minutes</option><option value="60" selected>1 hour</option><option value="90">1 hour 30</option><option value="120">2 hours</option></select></label>
                <label class="form-label">What do you want to work on?<input class="form-input" name="subject" minlength="3" maxlength="160" required placeholder="Breath control for live shows"></label>
                <label class="form-label">Message (optional)<textarea class="form-input" name="message" maxlength="1000"></textarea></label>
                <button class="btn btn-primary">Request session</button><p class="form-hint" data-msg role="status"></p></form></details></article>`).join('');
        box.addEventListener('submit', async event => {
            const form = event.target.closest('form[data-coach]');
            if (!form) return;
            event.preventDefault();
            if (!token()) return location.assign(`${root}auth/login.html?redirect=vocal-coaches.html`);
            const values = Object.fromEntries(new FormData(form));
            values.start_at = String(values.start_at).replace('T', ' ');
            values.duration_minutes = Number(values.duration_minutes);
            const button = form.querySelector('button');
            button.disabled = true;
            try {
                await call('post', `/mentors/${encodeURIComponent(form.dataset.coach)}/bookings`, values);
                form.querySelector('[data-msg]').textContent = 'Request sent. The coach will confirm it; you can follow it in your account.';
                form.reset();
            } finally {
                button.disabled = false;
            }
        });
    }

    async function renderCart() {
        const items = readCart();
        const total = items.reduce((sum, item) => sum + Number(item.price || 0), 0);
        $('#page-content').innerHTML = `<section class="section"><div class="container narrow">
            <h1 class="section-title">Your cart</h1>
            <div id="cart-items" class="cart-list">${items.length ? items.map((item, i) => `<article class="order-card">
                ${item.image_url ? `<img class="order-thumb" src="${esc(item.image_url)}" alt="">` : ''}
                <div class="order-main"><h3>${esc(item.title)}</h3><p>${esc(item.type === 'track' ? 'Song download' : `${item.license} licence`)}</p></div>
                <strong class="order-price">${money(item.price)}</strong>
                <button class="btn btn-text btn-sm" data-remove="${i}" aria-label="Remove ${esc(item.title)}">Remove</button></article>`).join('')
                : `<div class="empty-state"><p>Your cart is empty.</p><a class="btn btn-primary" href="browse-beats.html">Browse beats</a></div>`}</div>
            ${items.length ? `<div class="cart-total"><span>Total</span><strong>${money(total)}</strong></div>
            <div class="cart-actions"><a class="btn btn-text" href="browse-beats.html">Keep browsing</a><a class="btn btn-primary" href="checkout.html">Checkout</a></div>` : ''}
        </div></section>`;
        $('#cart-items').addEventListener('click', event => {
            const index = event.target.dataset.remove;
            if (index === undefined) return;
            const nextItems = items.slice();
            nextItems.splice(Number(index), 1);
            if (saveCart(nextItems)) renderCart();
        });
    }

    function checkoutForm() {
        const items = readCart();
        if (!token()) {
            location.assign(`${root}auth/login.html?redirect=checkout.html`);
            return;
        }
        if (!items.length) {
            location.assign(`${root}cart.html`);
            return;
        }
        const total = items.reduce((sum, x) => sum + Number(x.price || 0), 0);
        $('#page-content').innerHTML = `<section class="section"><div class="container auth-container"><div class="auth-card checkout-card">
            <h1 class="section-title">Checkout</h1>
            <ul class="checkout-items">${items.map(x => `<li><span>${esc(x.title)}<small>${esc(x.type === 'track' ? 'Song download' : `${x.license} licence`)}</small></span><b>${money(x.price)}</b></li>`).join('')}</ul>
            <p class="checkout-total"><span>Total</span><strong>${money(total)}</strong></p>
            <form class="auth-form" id="checkout-form" data-error-title="We could not start your payment:" novalidate>
                <div class="paystack-box">${svg(ICONS.lock)}<p><strong>Secure payment with Paystack</strong>Pay with MTN Mobile Money, Telecel Cash, AirtelTigo Money or a card. Your total is charged in Ghana cedis.</p></div>
                <label class="form-checkbox" data-field="terms"><input type="checkbox" name="terms" required data-label="Licence terms" data-missing="Please accept the licence terms to continue."> <span>I agree to the licence terms: I am buying permission to use this work, and the creator keeps the copyright.</span></label>
                <button class="btn btn-primary btn-block" type="submit">Pay ${money(total)}</button>
            </form>
            <p class="form-hint">Downloads and licence PDFs appear in My orders as soon as Paystack confirms the payment.</p>
        </div></div></section>`;
        core.forms.guard($('#checkout-form'), async () => {
            const cart = readCart();
            const payload = cart.map(x => x.type === 'track'
                ? { type: 'track', track_id: Number(x.id) }
                : { type: 'license', license_tier_id: Number(x.license_tier_id) });
            const order = obj(await window.api.post('/orders', { items: payload }));
            core.cart.clear();
            const result = await core.pay(order.payment);
            if (result === 'redirected') return;                       // Paystack brings the buyer back to payment.html
            location.assign(`checkout-success.html?order_id=${encodeURIComponent(order.id)}&reference=${encodeURIComponent(order.payment.reference)}${result === 'cancelled' ? '&cancelled=1' : ''}`);
        });
    }

    const ORDER_STATUS = { pending: 'Waiting for payment', paid: 'Paid', failed: 'Payment failed', expired: 'Expired', refunded: 'Refunded' };
    async function renderOrders() {
        if (!token()) {
            location.assign(`${root}auth/login.html?redirect=orders.html`);
            return;
        }
        const result = list(await call('get', '/orders'));
        $('#page-content').innerHTML = `<section class="section"><div class="container narrow">
            <div class="section-header"><div><h1 class="section-title">My orders</h1><p class="section-subtitle">Downloads and licence PDFs for everything you bought.</p></div><a class="btn btn-text" href="${root}dashboard/index.html#/payments">Payment history</a></div>
            <div id="order-list" class="cart-list">${result.length ? result.map(order => `<article class="order-card">
                <div class="order-main"><h3>Order #${esc(order.id)}</h3><p>${esc((order.items || []).map(i => i.title).join(', '))}</p>
                    <p class="order-meta"><span class="status-pill status-pill--${esc(order.status)}">${esc(ORDER_STATUS[order.status] || order.status)}</span> ${esc(core.dateTime(order.created_at))}${order.payment ? ` · Ref ${esc(order.payment.reference)}` : ''}</p>
                    <div id="downloads-${esc(order.id)}" class="order-downloads"></div></div>
                <div class="order-side"><strong class="order-price">${money(order.total)}</strong>${order.status === 'paid'
                    ? `<button class="btn btn-outline btn-sm" data-downloads="${esc(order.id)}">Get downloads</button>`
                    : order.status === 'pending' && order.payment && order.payment.checkout_url
                        ? `<button class="btn btn-primary btn-sm" data-pay-order="${esc(order.id)}">Finish paying</button><button class="btn btn-text btn-sm" data-refresh-order="${esc(order.id)}">Check status</button>`
                        : ''}</div></article>`).join('') : '<div class="empty-state"><p>You have no orders yet.</p><a class="btn btn-primary" href="browse-beats.html">Browse beats</a></div>'}</div>
        </div></section>`;
        $('#order-list')?.addEventListener('click', async event => {
            const btn = event.target.closest('button');
            if (!btn) return;
            const downloadId = btn.dataset.downloads;
            const refreshId = btn.dataset.refreshOrder;
            const payId = btn.dataset.payOrder;
            if (downloadId) {
                const downloads = obj(await call('get', `/orders/${encodeURIComponent(downloadId)}/downloads`));
                const links = (downloads.items || []).flatMap(item => item.files || []);
                $(`#downloads-${CSS.escape(downloadId)}`).innerHTML = links.map(file => `<a class="btn btn-sm btn-outline" href="${esc(file.url)}">${esc(file.label)}</a>`).join(' ');
            }
            if (payId) {
                const order = result.find(o => String(o.id) === payId);
                const r = await core.pay(order.payment);
                if (r !== 'redirected') location.assign(`checkout-success.html?order_id=${encodeURIComponent(order.id)}&reference=${encodeURIComponent(order.payment.reference)}${r === 'cancelled' ? '&cancelled=1' : ''}`);
            }
            if (refreshId) {
                const order = obj(await call('get', `/orders/${encodeURIComponent(refreshId)}`));
                toast(`Order #${order.id}: ${ORDER_STATUS[order.status] || order.status}.`);
                renderOrders();
            }
        });
    }

    /** checkout-success.html and payment.html: the result of a payment, verified by the server with Paystack. */
    async function renderPaymentResult(content) {
        const reference = query.get('reference') || query.get('trxref');
        const orderId = query.get('order_id');
        content.innerHTML = `<section class="section"><div class="container narrow"><div class="payment-result" id="payment-result" data-status="pending" aria-live="polite">
            <span class="payment-spinner" aria-hidden="true"></span><h1 class="section-title">Checking your payment…</h1><p>We are confirming the payment with Paystack. This takes a few seconds.</p></div></div></section>`;
        const box = $('#payment-result');
        if (!token()) {
            box.dataset.status = 'info';
            box.innerHTML = `<h1 class="section-title">Sign in to see your payment</h1><p>Your payment is safe. Sign in to see your order and downloads.</p><p><a class="btn btn-primary" href="${root}auth/login.html?redirect=${encodeURIComponent(location.pathname.split('/').pop() + location.search)}">Sign in</a></p>`;
            return;
        }
        const show = (status, title, text, actions) => {
            box.dataset.status = status;
            box.innerHTML = `<span class="payment-icon" aria-hidden="true">${svg(status === 'good' ? ICONS.check : status === 'bad' ? ICONS.close : ICONS.receipt)}</span><h1 class="section-title">${esc(title)}</h1><p>${text}</p><p class="detail-actions">${actions}</p>`;
        };
        if (query.get('cancelled')) {
            return show('info', 'Payment cancelled', 'You closed the Paystack checkout, so nothing was charged. You can finish paying from My orders.', `<a class="btn btn-primary" href="${root}orders.html">My orders</a><a class="btn btn-text" href="${root}browse-beats.html">Keep browsing</a>`);
        }
        let p = null;
        try {
            if (reference) p = await core.confirmPayment(reference, 10);
            else if (orderId) {
                const order = obj(await window.api.get(`/orders/${encodeURIComponent(orderId)}`));
                p = order.payment ? await core.confirmPayment(order.payment.reference, 10) : null;
            }
        } catch (error) {
            return show('bad', 'We could not find this payment', esc(error.message || 'Check My orders for its status.'), `<a class="btn btn-primary" href="${root}orders.html">My orders</a>`);
        }
        if (!p) return show('info', 'Payment not found', 'Check My orders for its status.', `<a class="btn btn-primary" href="${root}orders.html">My orders</a>`);
        const booking = p.booking;
        const nextGood = booking
            ? `<a class="btn btn-primary" href="${root}dashboard/index.html#/my-bookings">My bookings</a>`
            : `<a class="btn btn-primary" href="${root}orders.html">Downloads and licences</a><a class="btn btn-text" href="${root}browse-tracks.html">Keep listening</a>`;
        if (p.status === 'completed') {
            return show('good', booking ? 'Deposit paid: request sent to the studio' : 'Payment received. Thank you!',
                `${esc(money(p.amount))} paid ${p.channel === 'mobile_money' ? 'with Mobile Money' : p.channel === 'card' ? 'by card' : ''} · Ref ${esc(p.reference)}. ${booking ? 'The studio will accept or decline within 48 hours.' : 'Your files and licence PDFs are ready.'}`, nextGood);
        }
        if (p.status === 'failed') {
            return show('bad', 'The payment did not go through', `${esc(p.failure_reason || 'It was declined or not completed.')} You were not charged.`, `<a class="btn btn-primary" href="${booking ? `${root}studio.html?id=${encodeURIComponent(booking.studio.id)}` : `${root}cart.html`}">Try again</a><a class="btn btn-text" href="${root}q-and-a.html#ask">Get help</a>`);
        }
        show('info', 'Waiting for Paystack', `Paystack has not confirmed this payment yet. If you paid, it appears in a minute. Ref ${esc(p.reference)}.`, `${p.checkout_url ? `<button class="btn btn-primary" type="button" data-resume>Finish paying</button>` : ''}<a class="btn btn-text" href="${root}orders.html">My orders</a>`);
        box.querySelector('[data-resume]')?.addEventListener('click', () => core.pay(p));
    }

    /* ---------- creator profile ---------- */
    const CREATOR_TYPES = { artist: 'Artist', choir: 'Choir', producer: 'Producer', both: 'Artist and producer', manager: 'Artist manager', vocal_coach: 'Vocal coach' };
    async function renderCreatorPage(data) {
        const c = data.creator || data;
        document.title = `${c.stage_name} - Nile Vijana Creatives`;
        const tracks = data.tracks || [];
        const beats = data.beats || [];
        let following = Boolean(data.following);
        const box = $('#detail');
        box.innerHTML = `<article class="creator-page">
            <header class="creator-hero">
                <div class="creator-avatar">${c.avatar_url ? `<img src="${esc(c.avatar_url)}" alt="${esc(c.stage_name)}">` : `<span>${esc((c.stage_name || '?').slice(0, 2).toUpperCase())}</span>`}</div>
                <div class="creator-info">
                    <p class="eyebrow">${esc(CREATOR_TYPES[c.type] || 'Creator')}${c.verified ? ` <span class="verified-badge" title="Verified by Nile Vijana">${svg(ICONS.check)}Verified</span>` : ''}</p>
                    <h1 class="creator-name">${esc(c.stage_name)}</h1>
                    <p class="creator-meta">${[c.city, (c.genres || []).join(', '), c.members_count ? `${c.members_count} members` : '', c.company].filter(Boolean).map(esc).join(' · ')}</p>
                    <ul class="creator-stats"><li><strong data-followers>${Number(data.followers || 0).toLocaleString()}</strong> followers</li><li><strong>${Number(data.total_plays || 0).toLocaleString()}</strong> streams</li><li><strong>${tracks.length}</strong> songs</li>${beats.length ? `<li><strong>${beats.length}</strong> beats</li>` : ''}</ul>
                    <div class="creator-actions">
                        ${tracks.length ? `<button class="btn btn-primary" type="button" data-play-all>${svg('M8 5.5v13l11-6.5z')}Play all</button>` : ''}
                        <button class="btn btn-outline" type="button" data-follow aria-pressed="${following}">${following ? 'Following' : 'Follow'}</button>
                        <button class="btn btn-text" type="button" data-tip>Tip ${esc(c.stage_name)}</button>
                        ${c.website ? `<a class="btn btn-text" href="${esc(c.website)}" target="_blank" rel="noopener">${svg(ICONS.external)}Website</a>` : ''}
                    </div>
                </div>
            </header>
            ${c.bio ? `<section class="creator-bio"><h2>About</h2><p>${esc(c.bio)}</p></section>` : ''}
            ${tracks.length ? `<section class="creator-section"><h2 class="section-title">Songs</h2><div class="track-grid" data-play-scope id="creator-tracks">${tracks.map(t => card(t, 'track')).join('')}</div></section>` : ''}
            ${beats.length ? `<section class="creator-section"><h2 class="section-title">Beats for sale</h2><div class="beat-grid" data-play-scope>${beats.map(b => card(b, 'beat')).join('')}</div></section>` : ''}
            ${!tracks.length && !beats.length ? `<p class="empty-state">${esc(c.stage_name)} has not released music here yet. Follow to hear about new releases.</p>` : ''}
        </article>`;
        const trackBox = $('#creator-tracks');
        if (trackBox) bindCardActions(trackBox);
        box.addEventListener('click', async e => {
            if (e.target.closest('[data-play-all]')) {
                const first = box.querySelector('#creator-tracks [data-kind][data-id]');
                if (first) first.click();
            }
            if (e.target.closest('[data-tip]')) whenPlayer().then(p => p.actions.tip(c));
            const follow = e.target.closest('[data-follow]');
            if (follow) {
                if (!token()) return location.assign(`${root}auth/login.html?redirect=${encodeURIComponent(`creator.html?slug=${c.slug}`)}`);
                follow.disabled = true;
                try {
                    await window.api[following ? 'delete' : 'post'](`/creators/${encodeURIComponent(c.id)}/follow`);
                    following = !following;
                    follow.textContent = following ? 'Following' : 'Follow';
                    follow.setAttribute('aria-pressed', String(following));
                    const n = box.querySelector('[data-followers]');
                    n.textContent = (Number(n.textContent.replace(/\D/g, '')) + (following ? 1 : -1)).toLocaleString();
                } catch (error) {
                    toast(error.message, 'error');
                }
                follow.disabled = false;
            }
        });
    }

    /* ---------- directories: artists and choirs, managers ---------- */
    async function renderDirectory(content, page) {
        const managers = page === 'managers';
        const info = managers
            ? ['Artist managers', 'Experienced managers who look after artists and choirs on Nile Vijana. Send them a message through the team.', 'manager']
            : ['Artists and choirs', 'Independent artists and choirs from Accra, Kumasi, Cape Coast and across Ghana. Follow them to hear new releases first.', 'artist,choir'];
        content.innerHTML = `<section class="page-hero page-hero--creators"><div class="container"><p class="eyebrow">Creators</p><h1 class="page-hero-title">${esc(info[0])}</h1><p class="page-hero-text">${esc(info[1])}</p></div></section>
            <section class="section listing-section"><div class="container">
                <form class="search-form" id="dir-form" role="search"><label class="visually-hidden" for="dir-q">Search</label><input class="form-input" id="dir-q" name="q" type="search" placeholder="Search by name, genre or city"><button class="btn btn-primary">Search</button></form>
                <div class="creator-grid" id="dir-list"><p class="loading-state" role="status">Loading…</p></div>
            </div></section>`;
        const load = async () => {
            const q = $('#dir-q').value.trim();
            const rows = list(await call('get', '/creators', { type: info[2], q, per_page: 48 }));
            $('#dir-list').innerHTML = rows.length ? rows.map(c => `<a class="creator-card" href="creator.html?slug=${encodeURIComponent(c.slug)}">
                <span class="creator-card-avatar">${c.avatar_url ? `<img src="${esc(c.avatar_url)}" alt="" loading="lazy">` : esc((c.stage_name || '?').slice(0, 2).toUpperCase())}</span>
                <span class="creator-card-body"><strong>${esc(c.stage_name)}${c.verified ? ` <span class="verified-dot" title="Verified">${svg(ICONS.check)}</span>` : ''}</strong>
                <small>${esc([CREATOR_TYPES[c.type], c.city].filter(Boolean).join(' · '))}</small>
                <small class="creator-card-meta">${managers ? esc(c.company || 'Independent manager') : `${Number(c.live_tracks || 0)} songs${c.live_beats ? ` · ${c.live_beats} beats` : ''} · ${Number(c.followers || 0).toLocaleString()} followers`}</small></span></a>`).join('')
                : `<p class="empty-state">${managers ? 'No managers are listed yet.' : 'No artists match your search.'} <a href="${root}q-and-a.html#ask">Ask the team</a></p>`;
        };
        $('#dir-form').addEventListener('submit', e => { e.preventDefault(); load(); });
        return load();
    }

    /* ---------- Q&A ---------- */
    const QA_TOPICS = [['general', 'General'], ['account', 'My account and sign-in'], ['payments', 'Payments and refunds'], ['music', 'Music and streaming'], ['beats', 'Beats and licences'], ['studios', 'Studio bookings'], ['creators', 'Selling as a creator'], ['other', 'Something else']];
    async function renderQa(content) {
        const user = window.api.getUser() || {};
        const signedIn = Boolean(token());
        content.innerHTML = `<section class="page-hero page-hero--qa"><div class="container"><p class="eyebrow">Help and Q&amp;A</p><h1 class="page-hero-title">Ask the Nile Vijana team</h1>
                <p class="page-hero-text">Questions about payments, beat licences, studio bookings or your account go straight to our team in Accra. We answer by email, usually within two working days.</p></div></section>
            <section class="section"><div class="container qa-layout">
                <div class="qa-faq"><h2 class="section-title">Answered questions</h2><div id="faq-list"><p class="loading-state" role="status">Loading…</p></div></div>
                <div class="qa-ask" id="ask"><div class="auth-card"><h2 class="auth-title">Send a question</h2>
                    <form class="auth-form" id="qa-form" data-error-title="Your question was not sent. Please complete the following:" novalidate>
                        ${signedIn ? `<p class="form-hint">Signed in as <strong>${esc(user.name || '')}</strong>. We will reply to ${esc(user.email || 'your account email')}${signedIn ? ' and show the answer in <a href="dashboard/index.html#/my-questions">My questions</a>' : ''}.</p>` : `
                        ${field('Your name', '<input class="form-input" name="name" autocomplete="name" required minlength="2" maxlength="120" data-label="Your name" data-missing="Your name is missing.">')}
                        ${field('Email address', '<input class="form-input" name="email" type="email" autocomplete="email" required maxlength="190" data-label="Email address" data-missing="Email address is missing: we send the answer there.">', 'We send the answer to this address.')}
                        ${field('Phone number (optional)', '<input class="form-input" name="phone" type="tel" autocomplete="tel" data-label="Phone number" placeholder="024 123 4567">')}`}
                        ${field('Topic', `<select class="form-input" name="category" required data-label="Topic">${QA_TOPICS.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select>`)}
                        ${field('Subject', '<input class="form-input" name="subject" required minlength="5" maxlength="160" data-label="Subject" data-missing="Subject is missing." placeholder="e.g. Can I pay with Telecel Cash?">')}
                        ${field('Your question', '<textarea class="form-input" name="body" required minlength="20" maxlength="3000" rows="6" data-label="Your question" data-missing="Write your question." placeholder="Give as much detail as you can: order or booking numbers help."></textarea>', 'At least 20 characters.')}
                        <label class="hp-field" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
                        <button class="btn btn-primary btn-block" type="submit">Send question</button>
                    </form></div></div>
            </div></section>`;
        const form = $('#qa-form');
        core.forms.guard(form, async f => {
            const values = Object.fromEntries(new FormData(f));
            const res = obj(await window.api.post('/questions', values));
            f.closest('.auth-card').innerHTML = `<div class="qa-sent" role="status" tabindex="-1"><span class="payment-icon" aria-hidden="true">${svg(ICONS.check)}</span>
                <h2 class="auth-title">Your question has been sent</h2><p>${esc(res.message || 'Thank you. We will reply by email.')}</p>
                <p><strong>Reference:</strong> ${esc(res.reference)}</p>
                <p class="detail-actions">${signedIn ? `<a class="btn btn-primary" href="dashboard/index.html#/my-questions">See my questions</a>` : ''}<button class="btn btn-text" type="button" data-ask-again>Ask another question</button></p></div>`;
            const sent = $('.qa-sent');
            sent.focus();
            sent.querySelector('[data-ask-again]').addEventListener('click', () => renderQa(content));
        });
        try {
            const faq = list(await window.api.get('/questions/public'));
            $('#faq-list').innerHTML = faq.length ? faq.map(q => `<details class="faq-item"><summary><span class="faq-topic">${esc(q.category_label)}</span>${esc(q.subject)}</summary><div class="faq-answer"><p class="faq-q">${esc(q.body)}</p><p>${esc(q.answer)}</p></div></details>`).join('')
                : `<div class="faq-empty"><p>No published answers yet. Ask your question and the team will reply by email.</p>
                    <ul class="faq-quick"><li><strong>How do I pay?</strong> With Paystack: MTN Mobile Money, Telecel Cash, AirtelTigo Money or a card, in Ghana cedis.</li>
                    <li><strong>Who owns the music?</strong> Creators keep the copyright. When you buy a beat or a song you buy a licence to use it.</li>
                    <li><strong>How do studio deposits work?</strong> Your deposit is held until the session is done. If the studio declines, it comes back to your wallet.</li></ul></div>`;
        } catch (_) {
            $('#faq-list').innerHTML = '<p class="empty-state">Answers could not load. You can still send a question.</p>';
        }
        if (location.hash === '#ask') setTimeout(() => $('#ask').scrollIntoView({ behavior: 'smooth' }), 50);
    }

    /* ---------- Spotify player dock: Spotify music plays in Spotify's own embed ---------- */
    let spotifyApi = null;
    function loadSpotifyApi() {
        if (spotifyApi) return spotifyApi;
        spotifyApi = new Promise((resolve, reject) => {
            window.onSpotifyIframeApiReady = IFrameAPI => resolve(IFrameAPI);
            const s = document.createElement('script');
            s.src = 'https://open.spotify.com/embed/iframe-api/v1';
            s.async = true;
            s.onerror = () => { spotifyApi = null; reject(new Error('Spotify could not be reached')); };
            document.head.appendChild(s);
            setTimeout(() => reject(new Error('Spotify took too long to load')), 8000);
        });
        return spotifyApi;
    }
    let spotifyNow = null;
    function setSpotifyButtons(key, playing) {
        document.querySelectorAll('[data-spotify]').forEach(b => {
            const on = b.dataset.spotify === key && playing;
            b.classList.toggle('is-playing', on);
            b.setAttribute('aria-label', `${on ? 'Pause' : 'Play'} ${b.dataset.title || ''} on Spotify`);
        });
    }
    async function playSpotify(button) {
        const key = button.dataset.spotify;
        const [type, id] = key.split(':');
        if (window.NVPlayer) window.NVPlayer.pause();             // one sound at a time
        let dock = $('#spotify-dock');
        if (spotifyNow && spotifyNow.key === key && spotifyNow.controller) {
            spotifyNow.controller.togglePlay();
            return;
        }
        if (!dock) {
            document.body.insertAdjacentHTML('beforeend', `<aside class="spotify-dock" id="spotify-dock" aria-label="Spotify player"><div class="spotify-dock-head">${SPOTIFY_LOGO}<div><strong data-sd-title></strong><small data-sd-sub></small></div>
                <a class="spotify-dock-open" data-sd-open target="_blank" rel="noopener">Open in Spotify</a><button class="spotify-dock-close" type="button" aria-label="Close the Spotify player">${svg(ICONS.close)}</button></div>
                <div class="spotify-dock-frame"><div id="spotify-embed"></div></div><p class="spotify-dock-note" data-sd-note></p></aside>`);
            dock = $('#spotify-dock');
            dock.querySelector('.spotify-dock-close').addEventListener('click', () => {
                if (spotifyNow && spotifyNow.controller) { try { spotifyNow.controller.destroy(); } catch (_) { /* gone */ } }
                spotifyNow = null;
                dock.remove();
                setSpotifyButtons('', false);
            });
        }
        dock.querySelector('[data-sd-title]').textContent = button.dataset.title || 'Spotify';
        dock.querySelector('[data-sd-sub]').textContent = button.dataset.sub || '';
        dock.querySelector('[data-sd-open]').href = `https://open.spotify.com/${type}/${id}`;
        dock.querySelector('[data-sd-note]').textContent = type === 'track'
            ? 'Spotify plays a 30-second preview, or the full song if you are signed in to Spotify in this browser.'
            : 'Playing in Spotify’s player. Sign in to Spotify in this browser to hear full songs.';
        dock.classList.add('is-open');
        if (spotifyNow && spotifyNow.controller) { try { spotifyNow.controller.destroy(); } catch (_) { /* gone */ } }
        const frame = dock.querySelector('.spotify-dock-frame');
        frame.innerHTML = '<div id="spotify-embed"></div>';
        const height = type === 'track' ? 80 : 152;
        spotifyNow = { key, controller: null };
        setSpotifyButtons(key, true);
        const fallback = () => {
            frame.innerHTML = `<iframe title="${esc(button.dataset.title || 'Spotify player')}" src="https://open.spotify.com/embed/${type}/${id}?utm_source=generator" width="100%" height="${height}" frameborder="0" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" loading="eager"></iframe>`;
            const timer = setTimeout(() => {
                frame.insertAdjacentHTML('beforeend', `<p class="spotify-dock-error">If the player stays empty, Spotify may be unavailable here. <a href="https://open.spotify.com/${type}/${id}" target="_blank" rel="noopener">Listen on Spotify</a>.</p>`);
            }, 9000);
            frame.querySelector('iframe').addEventListener('load', () => clearTimeout(timer), { once: true });
        };
        try {
            const IFrameAPI = await loadSpotifyApi();
            IFrameAPI.createController($('#spotify-embed'), { uri: `spotify:${type}:${id}`, width: '100%', height }, controller => {
                if (!spotifyNow || spotifyNow.key !== key) return controller.destroy();
                spotifyNow.controller = controller;
                controller.addListener('ready', () => controller.play());
                controller.addListener('playback_update', e => setSpotifyButtons(key, !e.data.isPaused));
            });
        } catch (_) {
            fallback();
        }
    }
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-spotify]');
        if (!b) return;
        e.preventDefault();
        playSpotify(b);
    });
    // When our own player starts, pause Spotify.
    document.addEventListener('nvp:state', () => {
        const now = window.NVPlayer && window.NVPlayer.current();
        if (now && now.playing && spotifyNow && spotifyNow.controller) { try { spotifyNow.controller.pause(); } catch (_) { /* gone */ } }
    });

    function bindNavigation() {
        const navToggle = $('.nav-toggle');
        const navLinks = $('#primary-navigation');
        const group = $('.nav-group');
        const groupBtn = $('.nav-group-btn');
        const closeGroup = () => { group?.classList.remove('open'); groupBtn?.setAttribute('aria-expanded', 'false'); };
        const closeMenu = () => {
            navLinks.classList.remove('open');
            navToggle.classList.remove('active');
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Open the menu');
            document.body.classList.remove('nav-open');
        };
        navToggle.addEventListener('click', () => {
            const isOpen = !navLinks.classList.contains('open');
            navLinks.classList.toggle('open', isOpen);
            navToggle.classList.toggle('active', isOpen);
            navToggle.setAttribute('aria-expanded', String(isOpen));
            navToggle.setAttribute('aria-label', isOpen ? 'Close the menu' : 'Open the menu');
            document.body.classList.toggle('nav-open', isOpen);
        });
        groupBtn?.addEventListener('click', e => {
            e.stopPropagation();
            const open = !group.classList.contains('open');
            group.classList.toggle('open', open);
            groupBtn.setAttribute('aria-expanded', String(open));
        });
        navLinks.addEventListener('click', event => {
            if (event.target.closest('a')) { closeMenu(); closeGroup(); }
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.nav-group')) closeGroup();
            const avatar = e.target.closest('.avatar-btn');
            const dropdown = $('.nav-account');
            if (avatar) {
                const open = !dropdown.classList.contains('open');
                dropdown.classList.toggle('open', open);
                avatar.setAttribute('aria-expanded', String(open));
            } else if (dropdown && !e.target.closest('.nav-account .dropdown-menu')) {
                dropdown.classList.remove('open');
                $('.avatar-btn')?.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            closeMenu();
            closeGroup();
            $('.nav-account')?.classList.remove('open');
        });
        window.matchMedia('(min-width: 961px)').addEventListener?.('change', e => { if (e.matches) closeMenu(); });
    }

    async function renderPage(page) {
        const info = pageData[page] || pageData.index;
        document.title = `${info[0]} - Nile Vijana Creatives`;
        document.body.className = `nv-page-${page}`;
        document.body.innerHTML = shell(page);
        bindNavigation();
        loadPlayer();
        loadMeta();
        const cartCount = $('#cart-count');
        if (cartCount) cartCount.textContent = readCart().length || '';
        const content = $('#page-content');
        if (page === 'vocal-coaches') return renderCoaches(content);
        if (page === 'discover-artists' || page === 'managers') return renderDirectory(content, page);
        if (page === 'qa') return renderQa(content);
        if (page === 'albums') {
            content.innerHTML = `<section class="section placeholder-page"><div class="container"><div class="placeholder-card"><p class="eyebrow">Albums</p><h1 class="section-title">Full albums are coming soon</h1><p class="section-subtitle">Artists release singles on Nile Vijana today. Album pages, with every song from a project in one place, are being prepared.</p><p class="placeholder-note">Until then you can stream and buy every released song from the Music page.</p><div class="placeholder-actions"><a class="btn btn-primary" href="${root}browse-tracks.html">Browse music</a><a class="btn btn-outline" href="${root}discover-artists.html">Discover artists</a></div></div></div></section>`;
            return;
        }
        if (page === 'services') {
            const arrowSvg = svg(ICONS.arrow);
            const icons = {
                music:   `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="16" cy="36" r="6"/><circle cx="38" cy="30" r="6"/><path d="M22 36V12l22-5v18"/></svg>`,
                beats:   `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 24h4l4-10 6 20 4-16 4 10 4-6 4 2h8"/></svg>`,
                studios: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="14" width="36" height="24" rx="3"/><circle cx="24" cy="26" r="6"/><circle cx="24" cy="26" r="2" fill="currentColor" stroke="none"/><path d="M6 20h4M38 20h4M6 32h4M38 32h4"/></svg>`,
                creators:`<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="24" cy="16" r="8"/><path d="M8 42c0-8.837 7.163-16 16-16s16 7.163 16 16"/></svg>`,
                upload:  `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M24 32V16M17 23l7-7 7 7"/><path d="M12 36a8 8 0 0 1-2-15.6A12 12 0 0 1 36 18a8 8 0 0 1 2 14"/></svg>`,
                vocal:   `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="18" y="4" width="12" height="22" rx="6"/><path d="M10 24a14 14 0 0 0 28 0"/><path d="M24 38v6M18 44h12"/></svg>`,
            };
            const serviceCard = (mod, href, tag, title, desc, cta, imgSrc, iconSvg) => `
                <a class="service-card service-card--${mod}" href="${href}">
                    <div class="service-card__picture">
                        <img src="${imgSrc}" alt="" loading="lazy">
                        <div class="service-card__picture-overlay"></div>
                        <div class="service-card__icon-badge" aria-hidden="true">${iconSvg}</div>
                    </div>
                    <div class="service-card__body">
                        <span class="service-card__tag">${tag}</span>
                        <h2 class="service-card__title">${title}</h2>
                        <p class="service-card__desc">${desc}</p>
                    </div>
                    <div class="service-card__footer">
                        <span class="service-card__cta">${cta}</span>
                        <span class="service-card__arrow" aria-hidden="true">${arrowSvg}</span>
                    </div>
                </a>`;
            content.innerHTML = `<section class="section services-page"><div class="container">
                <p class="eyebrow">What we offer</p>
                <h1 class="section-title">Everything your music needs, in one place</h1>
                <p class="section-subtitle">Release and sell your songs, license beats from Ghanaian producers, book a recording studio or find a vocal coach. Prices are in cedis, and you pay with Mobile Money or a card.</p>
                <div class="services-grid">
                    ${serviceCard('music',    `${root}browse-tracks.html`,
                        'Listen', 'Music',
                        'Stream Highlife, Afrobeats, Gospel and more from independent artists. Tip them or buy a download.',
                        'Browse music',
                        `${root}assets/images/music3.jpg`, icons.music)}
                    ${serviceCard('beats',    `${root}browse-beats.html`,
                        'Create', 'Beat marketplace',
                        'Preview beats and buy an MP3 lease, WAV lease or exclusive licence with a signed licence PDF.',
                        'Explore beats',
                        `${root}assets/images/beat_picture2.jpg`, icons.beats)}
                    ${serviceCard('studios',  `${root}studios.html`,
                        'Record', 'Recording studios',
                        'Compare rooms and hourly rates in Accra, Kumasi and beyond, and book a session with a deposit.',
                        'Find a studio',
                        `${root}assets/images/beat_picture3.jpg`, icons.studios)}
                    ${serviceCard('creators', `${root}discover-artists.html`,
                        'Connect', 'Artists and choirs',
                        'Find the voices shaping Ghana’s sound, follow your favourites and explore their catalogues.',
                        'Discover artists',
                        `${root}assets/images/artist.jpg`, icons.creators)}
                    ${serviceCard('upload',   `${root}${token() ? 'dashboard/index.html#/upload' : 'auth/register.html'}`,
                        'Earn', 'Release and sell',
                        'Upload songs and beats, keep your rights, see every stream and sale, and withdraw to Mobile Money.',
                        token() ? 'Go to my dashboard' : 'Create a free account',
                        `${root}assets/images/upload.jpg`, icons.upload)}
                    ${serviceCard('vocal',    `${root}vocal-coaches.html`,
                        'Grow', 'Vocal coaching',
                        'Book a one-to-one session with an experienced coach to develop your voice and stage presence.',
                        'Find a coach',
                        `${root}assets/images/vocalcoach.jpg`, icons.vocal)}
                </div>
            </div></section>`;
            return;
        }
        if (page === 'index') return renderHome();
        if (page === 'browse-tracks' || page === 'browse-beats' || page === 'studios') {
            const type = page === 'browse-beats' ? 'beat' : page === 'studios' ? 'studio' : 'track';
            const endpoint = `/${type === 'track' ? 'tracks' : type === 'beat' ? 'beats' : 'studios'}`;
            content.innerHTML = listing(info[0], endpoint, type);
            await fillFilter(type);
            $('#search-form').addEventListener('submit', event => { event.preventDefault(); renderList(endpoint, type); });
            return renderList(endpoint, type);
        }
        if (['track', 'beat', 'studio', 'creator'].includes(page)) {
            content.innerHTML = detail(info[0], page);
            return renderDetail(page);
        }
        if (page === 'login' || page === 'register' || page === 'verify-otp') {
            if (token() && page !== 'verify-otp') return location.replace(safeRedirect());
            content.innerHTML = page === 'verify-otp' ? '<section class="section auth-container"><div class="auth-card" id="auth-card"></div></section>' : authForm(page);
            return bindAuth(page);
        }
        if (page === 'logout') {
            try { if (token()) await window.api.post('/auth/logout'); } catch (_) { /* already signed out */ }
            window.api.clearToken();
            clearTicket();
            return location.assign(`${root}index.html`);
        }
        if (page === 'cart') return renderCart();
        if (page === 'checkout') return checkoutForm();
        if (page === 'checkout-success' || page === 'payment') return renderPaymentResult(content);
        if (page === 'orders') return renderOrders();
        // Old dashboard page names now live in the dashboard app.
        location.replace(`${root}dashboard/index.html`);
    }

    async function start(page) {
        try {
            await renderPage(page);
        } catch (error) {
            console.error(`Failed to initialize the ${page} page:`, error);
            const content = $('#page-content');
            if (!content) {
                toast('This page could not be loaded. Please reload.', 'error');
                return;
            }
            content.innerHTML = `<section class="section"><div class="container"><h1 class="section-title">This page could not load</h1><p role="alert">Please check your connection and try again.</p><button class="btn btn-primary" id="retry-page" type="button">Try again</button></div></section>`;
            $('#retry-page').addEventListener('click', () => location.reload());
        }
    }

    window.NV = { start, toast, api: window.api };

    // Navbar gets a solid background once the page scrolls.
    let ticking = false;
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            document.querySelector('.navbar')?.classList.toggle('scrolled', window.pageYOffset > 100);
            ticking = false;
        });
    }, { passive: true });

    // Every page names itself: <body data-page="browse-beats">.
    document.addEventListener('DOMContentLoaded', () => start(document.body.dataset.page || 'index'));
    // Play buttons (data-kind / data-id) are handled by player.js; Spotify buttons (data-spotify) above.
})();
