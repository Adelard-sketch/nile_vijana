/*
 * Nile Vijana music player (loaded on every public page by site.js).
 *
 *  - Mini player docked at the bottom, full-screen "Now playing" view (cover, waveform
 *    progress, shuffle / previous / play / next / repeat, queue, volume).
 *  - Listener actions: favourite, save to library, share, tip the artist, download
 *    (free when the artist allows it, otherwise buy and download), buy beat licences.
 *  - Streams: a song counts as a stream after 30 seconds of listening; the server
 *    applies the rule (POST /api/tracks/{id}/plays) and the artist and admins see the
 *    totals on their dashboards.
 *  - Keeps the queue and position when you open another page, and shows the song on
 *    the phone's lock screen (Media Session API).
 *
 * Public API: window.NVPlayer.playButtons(buttons, index), .playItems(items, index),
 * .toggle(), .current(), .actions.{favorite, save, share, tip, download, buyBeat}.
 */
(function () {
    'use strict';

    const core = window.NVCore;
    const api = window.api;
    const { esc, toast } = core;
    const ROOT = core.ROOT;
    const BEAT_PREVIEW_SECONDS = 10;      // visitors hear the first 10 s of a beat preview
    const STREAM_SECONDS = 30;            // reported to the server, which decides what counts
    const STATE_KEY = 'nv_player';

    /* ------------------------------------------------------------------ icons */
    const P = {
        play: '<path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.4-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z" fill="currentColor" stroke="none"/>',
        pause: '<rect x="6.5" y="5" width="4" height="14" rx="1.2" fill="currentColor" stroke="none"/><rect x="13.5" y="5" width="4" height="14" rx="1.2" fill="currentColor" stroke="none"/>',
        prev: '<path d="M6 5v14" /><path d="M19 6.2v11.6a.8.8 0 0 1-1.25.66L9.6 12.66a.8.8 0 0 1 0-1.32l8.15-5.8A.8.8 0 0 1 19 6.2z" fill="currentColor"/>',
        next: '<path d="M18 5v14" /><path d="M5 6.2v11.6a.8.8 0 0 0 1.25.66l8.15-5.8a.8.8 0 0 0 0-1.32L6.25 5.54A.8.8 0 0 0 5 6.2z" fill="currentColor"/>',
        shuffle: '<path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
        repeat: '<path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
        save: '<path d="M12 5v14M5 12h14"/>',
        saved: '<path d="M5 12l5 5L20 7"/>',
        share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
        tip: '<circle cx="12" cy="12" r="9"/><path d="M14.8 9.2c-.5-.9-1.6-1.4-2.8-1.4-1.6 0-2.8.9-2.8 2.1 0 2.9 5.8 1.5 5.8 4.3 0 1.2-1.3 2.1-3 2.1-1.3 0-2.4-.6-2.9-1.5M12 6v1.8M12 16.3V18"/>',
        download: '<path d="M12 3v12m0 0-5-5m5 5 5-5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        cart: '<path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 1.9-1.4L21 9H6"/><circle cx="10" cy="20" r="1.2" fill="currentColor"/><circle cx="18" cy="20" r="1.2" fill="currentColor"/>',
        down: '<path d="M6 9l6 6 6-6"/>',
        up: '<path d="M6 15l6-6 6 6"/>',
        queue: '<path d="M3 6h13M3 12h13M3 18h8M18 14v7l4-3.5z"/>',
        volume: '<path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/>',
        mute: '<path d="M11 5 6 9H3v6h3l5 4z"/><path d="M22 9l-6 6M16 9l6 6"/>',
        close: '<path d="M6 6l12 12M18 6 6 18"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        headphones: '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/>'
    };
    const icon = (name, cls = '') => `<svg class="nvp-i ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${P[name]}</svg>`;

    /* ------------------------------------------------------------------ helpers */
    const clock = s => (Number.isFinite(s) && s >= 0 ? `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}` : '0:00');
    const count = n => Number(n || 0).toLocaleString();
    const plural = (n, one, many) => `${count(n)} ${Number(n) === 1 ? one : many}`;
    const user = () => window.api.getUser() || {};
    const pageLink = (kind, id) => `${location.origin}${ROOT}${kind === 'beat' ? 'beat' : 'track'}.html?id=${encodeURIComponent(id)}`;
    const here = () => location.pathname.slice(ROOT.length) + location.search;
    const sessionId = () => {
        let id = null;
        try { id = sessionStorage.getItem('nv_session'); } catch (_) { /* private mode */ }
        if (!id) {
            id = Math.random().toString(36).slice(2) + Date.now().toString(36);
            try { sessionStorage.setItem('nv_session', id); } catch (_) { /* ignore */ }
        }
        return id;
    };
    /** Stable pseudo-random bar heights per song, so each one has its own waveform shape. */
    const bars = (seed, n) => {
        let a = 0;
        for (const ch of String(seed)) a = (a * 31 + ch.charCodeAt(0)) >>> 0;
        const out = [];
        for (let i = 0; i < n; i++) {
            a = (a + 0x6D2B79F5) >>> 0;
            let t = a;
            t = Math.imul(t ^ (t >>> 15), t | 1);
            t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
            const r = ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            const envelope = 0.55 + 0.45 * Math.sin((i / n) * Math.PI);
            out.push(Math.round((0.22 + r * 0.78) * envelope * 100));
        }
        return out;
    };
    const updateCartBadge = () => {
        const badge = document.getElementById('cart-count');
        if (badge) badge.textContent = core.cart.read().length || '';
    };

    /* ------------------------------------------------------------------ state */
    const state = {
        queue: [],          // [{ kind, id, title, artist, cover }]
        index: -1,
        shuffle: false,
        repeat: 'off',      // off | all | one
        detail: null,       // API record of the current item
        listened: 0,
        last: 0,
        reported: false,
        previewEnded: false,
        resumeAt: 0
    };
    const details = new Map();
    const audio = new Audio();
    audio.preload = 'metadata';

    /* ------------------------------------------------------------------ markup */
    const root = document.createElement('div');
    root.className = 'nvp';
    root.hidden = true;
    root.innerHTML = `
        <div class="nvp-mini" role="region" aria-label="Music player">
            <div class="nvp-mini-line" aria-hidden="true"><span></span></div>
            <button class="nvp-mini-open" type="button" data-nvp="open" aria-label="Open the player">
                <span class="nvp-mini-art"><img alt="" hidden><span class="nvp-eq" aria-hidden="true"><i></i><i></i><i></i></span></span>
                <span class="nvp-mini-text"><strong data-nvp-title>Nothing playing</strong><small data-nvp-artist></small></span>
            </button>
            <div class="nvp-mini-controls">
                <button class="nvp-icon-btn nvp-only-track" type="button" data-nvp="favorite" aria-pressed="false" aria-label="Add to favourites">${icon('heart')}</button>
                <button class="nvp-icon-btn nvp-hide-sm" type="button" data-nvp="prev" aria-label="Previous">${icon('prev')}</button>
                <button class="nvp-play-sm" type="button" data-nvp="toggle" aria-label="Play">${icon('play', 'is-play')}${icon('pause', 'is-pause')}</button>
                <button class="nvp-icon-btn" type="button" data-nvp="next" aria-label="Next">${icon('next')}</button>
                <button class="nvp-icon-btn nvp-hide-sm" type="button" data-nvp="open" aria-label="Open the player">${icon('up')}</button>
            </div>
        </div>
        <section class="nvp-full" role="dialog" aria-modal="true" aria-label="Now playing" hidden>
            <div class="nvp-bg" aria-hidden="true"></div>
            <header class="nvp-top">
                <button class="nvp-icon-btn" type="button" data-nvp="close" aria-label="Close the player">${icon('down')}</button>
                <div class="nvp-top-title"><span>Now playing</span><strong data-nvp-source></strong></div>
                <button class="nvp-icon-btn" type="button" data-nvp="queue" aria-pressed="false" aria-label="Show what plays next">${icon('queue')}</button>
            </header>
            <div class="nvp-body">
                <div class="nvp-art"><div class="nvp-art-ring" aria-hidden="true"></div><img alt="" hidden><span class="nvp-art-fallback" aria-hidden="true">${icon('headphones')}</span></div>
                <div class="nvp-main">
                    <div class="nvp-heading">
                        <div class="nvp-heading-text">
                            <span class="nvp-chip" data-nvp-kind></span>
                            <h2 data-nvp-title>Nothing playing</h2>
                            <a class="nvp-artist" data-nvp-artist-link href="#"><span data-nvp-artist></span></a>
                        </div>
                        <button class="nvp-icon-btn nvp-heart nvp-only-track" type="button" data-nvp="favorite" aria-pressed="false" aria-label="Add to favourites">${icon('heart')}</button>
                    </div>
                    <ul class="nvp-stats" aria-label="Song statistics" data-nvp-stats></ul>
                    <div class="nvp-wave" role="slider" tabindex="0" aria-label="Seek" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="0:00">
                        <div class="nvp-wave-bars"></div>
                        <div class="nvp-wave-bars nvp-wave-fill" aria-hidden="true"></div>
                    </div>
                    <div class="nvp-times"><span data-nvp-now>0:00</span><span data-nvp-preview hidden>Preview</span><span data-nvp-total>0:00</span></div>
                    <div class="nvp-controls">
                        <button class="nvp-icon-btn nvp-toggle" type="button" data-nvp="shuffle" aria-pressed="false" aria-label="Shuffle">${icon('shuffle')}</button>
                        <button class="nvp-icon-btn nvp-big" type="button" data-nvp="prev" aria-label="Previous">${icon('prev')}</button>
                        <button class="nvp-play" type="button" data-nvp="toggle" aria-label="Play">${icon('play', 'is-play')}${icon('pause', 'is-pause')}</button>
                        <button class="nvp-icon-btn nvp-big" type="button" data-nvp="next" aria-label="Next">${icon('next')}</button>
                        <button class="nvp-icon-btn nvp-toggle nvp-repeat" type="button" data-nvp="repeat" aria-pressed="false" aria-label="Repeat: off">${icon('repeat')}<b aria-hidden="true">1</b></button>
                    </div>
                    <div class="nvp-actions" data-nvp-actions></div>
                    <label class="nvp-volume nvp-hide-sm">${icon('volume')}<input type="range" min="0" max="100" value="100" aria-label="Volume" data-nvp-volume></label>
                </div>
                <aside class="nvp-queue" hidden aria-label="Up next">
                    <h3>Up next</h3>
                    <ol data-nvp-queue></ol>
                </aside>
            </div>
        </section>`;

    const $ = sel => root.querySelector(sel);
    const $$ = sel => Array.from(root.querySelectorAll(sel));
    const full = $('.nvp-full');

    /* ------------------------------------------------------------------ sheets (tip, share, buy...) */
    let sheetClosedAt = 0;      // Escape that closes a sheet must not also close the player
    function sheet({ title, body, onOpen }) {
        const dialog = document.createElement('dialog');
        dialog.className = 'nvp-sheet';
        dialog.innerHTML = `<div class="nvp-sheet-head"><h2>${esc(title)}</h2><button class="nvp-icon-btn" type="button" data-close aria-label="Close">${icon('close')}</button></div><div class="nvp-sheet-body">${body}</div>`;
        document.body.appendChild(dialog);
        const close = () => { if (dialog.open) dialog.close(); dialog.remove(); sheetClosedAt = Date.now(); };
        dialog.addEventListener('click', e => { if (e.target === dialog || e.target.closest('[data-close]')) close(); });
        dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
        dialog.showModal();
        if (onOpen) onOpen(dialog, close);
        return { dialog, close };
    }

    function needSignIn(what) {
        if (window.isLoggedIn()) return false;
        const back = encodeURIComponent(here());
        sheet({
            title: 'Sign in to continue',
            body: `<p class="nvp-sheet-text">Sign in or create a free account to ${esc(what)}.</p>
                <div class="nvp-sheet-row"><a class="nvp-btn nvp-btn-primary" href="${ROOT}auth/login.html?redirect=${back}">Sign in</a><a class="nvp-btn" href="${ROOT}auth/register.html?redirect=${back}">Create account</a></div>`
        });
        return true;
    }

    /** Pay with mobile money: creates the order, then follows it until it is paid or fails. */
    async function payOrder(box, items, phone, onPaid) {
        box.innerHTML = '<div class="nvp-pay"><span class="nvp-spinner" aria-hidden="true"></span><p><strong>Approve the payment on your phone</strong><br>We sent a mobile money request. This updates by itself.</p></div>';
        let order;
        try {
            order = core.obj(await api.post('/orders', { items, phone }));
        } catch (error) {
            box.innerHTML = `<p class="nvp-error" role="alert">${esc(error.message)}</p>`;
            return false;
        }
        for (let i = 0; i < 60; i++) {
            await new Promise(r => setTimeout(r, 2500));
            try { order = core.obj(await api.get(`/orders/${encodeURIComponent(order.id)}`)); } catch (_) { continue; }
            if (order.status === 'paid') {
                await onPaid(order);
                return true;
            }
            if (['failed', 'expired', 'refunded'].includes(order.status)) {
                box.innerHTML = `<p class="nvp-error" role="alert">The payment did not go through${order.payment && order.payment.failure_reason ? `: ${esc(order.payment.failure_reason)}` : ''}. Nothing was charged.</p>`;
                return false;
            }
        }
        box.innerHTML = '<p class="nvp-error" role="alert">We have not heard back from the mobile money network yet. Check My orders in a few minutes.</p>';
        return false;
    }

    function startDownload(url, filename) {
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || '';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    /* ------------------------------------------------------------------ listener actions */
    async function refreshDetail(kind, id) {
        const d = core.obj(await api.get(`/${kind === 'beat' ? 'beats' : 'tracks'}/${encodeURIComponent(id)}`));
        details.set(`${kind}:${id}`, d);
        const item = state.queue[state.index];
        if (item && item.kind === kind && String(item.id) === String(d.id)) {
            state.detail = d;
            renderDetail();
        }
        return d;
    }
    const currentKind = () => (state.queue[state.index] || {}).kind;
    const changed = () => document.dispatchEvent(new CustomEvent('nvp:details', { detail: state.detail }));

    const actions = {
        async favorite(track) {
            if (needSignIn('add songs to your favourites')) return null;
            const on = !track.liked;
            const res = await api[on ? 'post' : 'delete'](`/tracks/${track.id}/like`);
            track.liked = res.liked;
            track.likes = res.likes;
            toast(res.liked ? 'Added to your favourites.' : 'Removed from your favourites.');
            sync(track);
            return track;
        },
        async save(track) {
            if (needSignIn('save songs to your library')) return null;
            const on = !track.saved;
            const res = await api[on ? 'post' : 'delete'](`/tracks/${track.id}/save`);
            track.saved = res.saved;
            track.saves = res.saves;
            toast(res.saved ? 'Saved to your library.' : 'Removed from your library.');
            sync(track);
            return track;
        },
        share(item, kind = 'track') {
            const url = pageLink(kind, item.id);
            const by = kind === 'beat' ? (item.producer || {}).stage_name : (item.creator || {}).stage_name;
            const text = `${item.title}${by ? ` by ${by}` : ''} on Nile Vijana`;
            const record = channel => {
                if (kind !== 'track') return;
                api.post(`/tracks/${item.id}/share`, { channel, session_id: sessionId() }).then(res => {
                    item.shares = res.shares;
                    sync(item);
                }).catch(() => {});
            };
            const enc = encodeURIComponent;
            const channels = [
                ['whatsapp', 'WhatsApp', `https://wa.me/?text=${enc(`${text} ${url}`)}`],
                ['x', 'X', `https://twitter.com/intent/tweet?text=${enc(text)}&url=${enc(url)}`],
                ['facebook', 'Facebook', `https://www.facebook.com/sharer/sharer.php?u=${enc(url)}`],
                ['telegram', 'Telegram', `https://t.me/share/url?url=${enc(url)}&text=${enc(text)}`]
            ];
            sheet({
                title: `Share "${item.title}"`,
                body: `<div class="nvp-share-preview">${item.cover_url ? `<img src="${esc(item.cover_url)}" alt="">` : ''}<div><strong>${esc(item.title)}</strong><span>${esc(by || '')}</span></div></div>
                    <div class="nvp-share-grid">${channels.map(([c, label, href]) => `<a class="nvp-share-btn nvp-share-${c}" href="${esc(href)}" target="_blank" rel="noopener" data-channel="${c}">${esc(label)}</a>`).join('')}
                    ${navigator.share ? '<button class="nvp-share-btn nvp-share-native" type="button" data-native>More apps</button>' : ''}</div>
                    <div class="nvp-copy"><input readonly value="${esc(url)}" aria-label="Link to share"><button class="nvp-btn" type="button" data-copy>${icon('link')} Copy link</button></div>`,
                onOpen(dialog) {
                    dialog.addEventListener('click', async e => {
                        const link = e.target.closest('[data-channel]');
                        if (link) record(link.dataset.channel);
                        if (e.target.closest('[data-copy]')) {
                            try {
                                await navigator.clipboard.writeText(url);
                            } catch (_) {
                                const input = dialog.querySelector('.nvp-copy input');
                                input.select();
                                document.execCommand('copy');
                            }
                            record('link');
                            toast('Link copied.');
                        }
                        if (e.target.closest('[data-native]')) {
                            try {
                                await navigator.share({ title: item.title, text, url });
                                record('native');
                            } catch (_) { /* the person closed the share menu */ }
                        }
                    });
                }
            });
        },
        tip(creator) {
            if (!creator || !creator.id) return;
            if (needSignIn(`tip ${creator.stage_name || 'the artist'}`)) return;
            if (Number(user().id) === Number(creator.id)) return toast('You cannot tip yourself.', 'info');
            const min = Number((window.NVMeta || {}).min_tip || 100);
            const presets = [500, 1000, 2000, 5000].filter(v => v >= min);
            sheet({
                title: `Tip ${creator.stage_name || 'the artist'}`,
                body: `<form class="nvp-form" novalidate>
                    <p class="nvp-sheet-text">Your tip goes to the artist's wallet, after the platform commission.</p>
                    <div class="nvp-amounts" role="radiogroup" aria-label="Tip amount">${presets.map((v, i) => `<label class="nvp-amount"><input type="radio" name="preset" value="${v}"${i === 1 ? ' checked' : ''}><span>${core.money(v)}</span></label>`).join('')}</div>
                    <label class="nvp-field"><span>Or another amount (${esc(core.currency)})</span><input type="number" name="amount" min="${min}" step="100" inputmode="numeric" placeholder="${min}"></label>
                    <label class="nvp-field"><span>Mobile money number</span><input type="tel" name="phone" required value="${esc(user().phone ? `0${String(user().phone).slice(-9)}` : '')}"></label>
                    <div data-status></div>
                    <button class="nvp-btn nvp-btn-primary nvp-btn-block" type="submit">${icon('tip')} <span data-label>Send tip</span></button>
                </form>`,
                onOpen(dialog) {
                    const form = dialog.querySelector('form');
                    const amount = () => Number(form.amount.value || (form.querySelector('[name=preset]:checked') || {}).value || 0);
                    const label = () => { form.querySelector('[data-label]').textContent = amount() ? `Send ${core.money(amount())} tip` : 'Send tip'; };
                    form.addEventListener('input', e => {
                        if (e.target.name === 'amount' && e.target.value) form.querySelectorAll('[name=preset]').forEach(r => { r.checked = false; });
                        if (e.target.name === 'preset') form.amount.value = '';
                        label();
                    });
                    label();
                    form.addEventListener('submit', async e => {
                        e.preventDefault();
                        const value = amount();
                        const box = form.querySelector('[data-status]');
                        if (value < min) { box.innerHTML = `<p class="nvp-error" role="alert">The smallest tip is ${esc(core.money(min))}.</p>`; return; }
                        if (!form.phone.value.trim()) { box.innerHTML = '<p class="nvp-error" role="alert">Enter the mobile money number to pay from.</p>'; return; }
                        const button = form.querySelector('button[type=submit]');
                        button.disabled = true;
                        const ok = await payOrder(box, [{ type: 'tip', creator_id: creator.id, amount: value }], form.phone.value.trim(), async () => {
                            box.innerHTML = `<div class="nvp-done">${icon('saved')}<p><strong>Thank you!</strong> ${esc(creator.stage_name || 'The artist')} received your ${esc(core.money(value))} tip.</p></div>`;
                            button.hidden = true;
                        });
                        if (!ok) button.disabled = false;
                    });
                }
            });
        },
        async download(track) {
            if (needSignIn('download songs')) return;
            let t = track;
            try { t = await refreshDetail('track', track.id); } catch (_) { /* use what we have */ }
            if (t.can_download) {
                try {
                    const file = await api.get(`/tracks/${t.id}/download`);
                    startDownload(file.url, file.filename);
                    toast('Your download has started.');
                } catch (error) {
                    toast(error.message, 'error');
                }
                return;
            }
            if (!(Number(t.price) > 0)) return toast('The artist has not made this song available for download.', 'info');
            sheet({
                title: `Download "${t.title}"`,
                body: `<form class="nvp-form" novalidate>
                    <div class="nvp-share-preview">${t.cover_url ? `<img src="${esc(t.cover_url)}" alt="">` : ''}<div><strong>${esc(t.title)}</strong><span>${esc((t.creator || {}).stage_name || '')}</span></div><b class="nvp-price">${esc(core.money(t.price))}</b></div>
                    <p class="nvp-sheet-text">Buy the full-quality file. It stays in My orders, so you can download it again later.</p>
                    <label class="nvp-field"><span>Mobile money number</span><input type="tel" name="phone" required value="${esc(user().phone ? `0${String(user().phone).slice(-9)}` : '')}"></label>
                    <div data-status></div>
                    <button class="nvp-btn nvp-btn-primary nvp-btn-block" type="submit">${icon('download')} Buy and download for ${esc(core.money(t.price))}</button>
                </form>`,
                onOpen(dialog) {
                    const form = dialog.querySelector('form');
                    form.addEventListener('submit', async e => {
                        e.preventDefault();
                        const box = form.querySelector('[data-status]');
                        if (!form.phone.value.trim()) { box.innerHTML = '<p class="nvp-error" role="alert">Enter the mobile money number to pay from.</p>'; return; }
                        const button = form.querySelector('button[type=submit]');
                        button.disabled = true;
                        const ok = await payOrder(box, [{ type: 'track', track_id: t.id }], form.phone.value.trim(), async () => {
                            const file = await api.get(`/tracks/${t.id}/download`);
                            startDownload(file.url, file.filename);
                            box.innerHTML = `<div class="nvp-done">${icon('saved')}<p><strong>Paid. Your download has started.</strong> <a href="${file.url}" download>Download again</a></p></div>`;
                            button.hidden = true;
                            refreshDetail('track', t.id).catch(() => {});
                        });
                        if (!ok) button.disabled = false;
                    });
                }
            });
        },
        buyBeat(beat, { checkout = false } = {}) {
            const tiers = (beat.licenses || []).filter(l => l.active !== false);
            if (!tiers.length) return toast('This beat has no licence for sale right now.', 'info');
            if (Number(user().id) === Number((beat.producer || {}).id)) return toast('This is your own beat.', 'info');
            sheet({
                title: `Buy "${beat.title}"`,
                body: `<form class="nvp-form" novalidate>
                    <p class="nvp-sheet-text">Choose a licence. Artists and listeners can both buy; you get the files and a licence PDF after paying.</p>
                    <div class="nvp-tiers">${tiers.map((l, i) => `<label class="nvp-tier"><input type="radio" name="tier" value="${esc(l.id)}"${i === 0 ? ' checked' : ''}>
                        <span class="nvp-tier-body"><strong>${esc(l.name || l.tier)}</strong><small>${esc((l.files || []).map(f => f.toUpperCase()).join(' + ') || 'MP3')}${l.stream_cap ? `, up to ${count(l.stream_cap)} streams` : l.tier === 'exclusive' ? ', full rights' : ', unlimited streams'}</small></span>
                        <b>${esc(core.money(l.price))}</b></label>`).join('')}</div>
                    <div class="nvp-sheet-row"><button class="nvp-btn" type="button" data-add>${icon('cart')} Add to cart</button><button class="nvp-btn nvp-btn-primary" type="submit">Buy now</button></div>
                </form>`,
                onOpen(dialog, close) {
                    const form = dialog.querySelector('form');
                    const add = () => {
                        const tier = tiers.find(l => String(l.id) === String((form.querySelector('[name=tier]:checked') || {}).value));
                        if (!tier) return false;
                        const cart = core.cart.read().filter(entry => !(entry.type === 'beat' && Number(entry.id) === Number(beat.id)));
                        cart.push({ id: beat.id, type: 'beat', title: beat.title, price: Number(tier.price), license_tier_id: Number(tier.id), license: tier.name || tier.tier, image_url: beat.cover_url || '' });
                        if (!core.cart.save(cart)) return false;
                        updateCartBadge();
                        return true;
                    };
                    form.querySelector('[data-add]').addEventListener('click', () => {
                        if (add()) { toast(`"${beat.title}" is in your cart.`); close(); }
                    });
                    form.addEventListener('submit', e => {
                        e.preventDefault();
                        if (add()) location.assign(`${ROOT}checkout.html`);
                    });
                    if (checkout) form.querySelector('[type=submit]').focus();
                }
            });
        }
    };

    /** Keep every copy of the same song in step (player, track page, cards). */
    function sync(item) {
        if (state.detail && state.detail.id === item.id && currentKind() === 'track') {
            state.detail = Object.assign(state.detail, item);
            renderDetail();
        }
        document.dispatchEvent(new CustomEvent('nvp:item', { detail: item }));
    }

    /* ------------------------------------------------------------------ rendering */
    function renderMeta() {
        const item = state.queue[state.index] || {};
        const d = state.detail || {};
        const beat = item.kind === 'beat';
        const title = d.title || item.title || 'Untitled';
        const person = beat ? (d.producer || {}) : (d.creator || {});
        const artist = person.stage_name || item.artist || '';
        const cover = d.cover_url || item.cover || '';
        root.classList.toggle('is-beat', beat);
        $$('[data-nvp-title]').forEach(el => { el.textContent = title; });
        $$('[data-nvp-artist]').forEach(el => { el.textContent = artist; });
        const link = $('[data-nvp-artist-link]');
        link.href = person.slug ? `${ROOT}creator.html?slug=${encodeURIComponent(person.slug)}` : '#';
        $('[data-nvp-kind]').textContent = beat ? 'Beat preview' : (d.genre || 'Song');
        $('[data-nvp-source]').textContent = beat ? 'Beat marketplace' : 'Nile Vijana music';
        $$('.nvp-mini-art img, .nvp-art img').forEach(img => {
            img.hidden = !cover;
            if (cover && img.getAttribute('src') !== cover) img.src = cover;
        });
        $('.nvp-art-fallback').hidden = Boolean(cover);
        $('.nvp-bg').style.backgroundImage = cover ? `url("${cover.replace(/"/g, '%22')}")` : '';
        const heights = bars(`${item.kind}:${item.id}`, window.innerWidth < 600 ? 44 : 64);
        $$('.nvp-wave-bars').forEach(el => { el.innerHTML = heights.map(h => `<i style="height:${h}%"></i>`).join(''); });
        renderDetail();
        renderQueue();
        if ('mediaSession' in navigator) {
            navigator.mediaSession.metadata = new MediaMetadata({ title, artist, album: beat ? 'Beat preview' : 'Nile Vijana', artwork: cover ? [{ src: cover, sizes: '512x512' }] : [] });
        }
    }

    function renderDetail() {
        const item = state.queue[state.index] || {};
        const d = state.detail || {};
        const beat = item.kind === 'beat';
        $$('[data-nvp="favorite"]').forEach(btn => {
            btn.setAttribute('aria-pressed', String(Boolean(d.liked)));
            btn.setAttribute('aria-label', d.liked ? 'Remove from favourites' : 'Add to favourites');
        });
        const stats = [];
        if (d.plays != null) stats.push(['headphones', beat ? plural(d.plays, 'play', 'plays') : plural(d.plays, 'stream', 'streams')]);
        if (!beat && d.likes != null) stats.push(['heart', plural(d.likes, 'favourite', 'favourites')]);
        if (!beat && d.saves != null) stats.push(['saved', plural(d.saves, 'save', 'saves')]);
        if (beat && d.bpm) stats.push(['beat', `${d.bpm} BPM${d.key ? `, ${d.key}` : ''}`]);
        $('[data-nvp-stats]').innerHTML = stats.map(([ic, text]) => `<li>${ic === 'beat' ? '' : icon(ic)}<span>${esc(text)}</span></li>`).join('');
        const box = $('[data-nvp-actions]');
        if (beat) {
            box.innerHTML = `<button class="nvp-btn nvp-btn-primary nvp-btn-wide" type="button" data-nvp="buy">${icon('cart')} Buy licence${d.price_from ? ` from ${esc(core.money(d.price_from))}` : ''}</button>
                <button class="nvp-action" type="button" data-nvp="share">${icon('share')}<span>Share</span></button>
                <a class="nvp-action" href="${ROOT}beat.html?id=${encodeURIComponent(item.id)}">${icon('up')}<span>Details</span></a>`;
        } else {
            const priced = Number(d.price) > 0;
            box.innerHTML = `
                <button class="nvp-action" type="button" data-nvp="save" aria-pressed="${Boolean(d.saved)}">${icon(d.saved ? 'saved' : 'save')}<span>${d.saved ? 'Saved' : 'Save'}</span></button>
                <button class="nvp-action" type="button" data-nvp="share">${icon('share')}<span>Share</span></button>
                <button class="nvp-action" type="button" data-nvp="tip">${icon('tip')}<span>Tip artist</span></button>
                ${d.can_download || priced || d.allow_download
                    ? `<button class="nvp-action" type="button" data-nvp="download">${icon(d.can_download || !priced ? 'download' : 'cart')}<span>${d.can_download || !priced ? 'Download' : `Buy ${esc(core.money(d.price))}`}</span></button>`
                    : `<button class="nvp-action" type="button" data-nvp="download" aria-disabled="true" title="The artist has not made this song available for download">${icon('download')}<span>Download</span></button>`}`;
        }
    }

    function renderQueue() {
        const list = $('[data-nvp-queue]');
        list.innerHTML = state.queue.map((q, i) => `<li${i === state.index ? ' aria-current="true"' : ''}><button type="button" data-nvp-jump="${i}">${q.cover ? `<img src="${esc(q.cover)}" alt="">` : '<span class="nvp-q-dot"></span>'}<span><strong>${esc(q.title || 'Untitled')}</strong><small>${esc(q.artist || (q.kind === 'beat' ? 'Beat' : ''))}</small></span>${i === state.index ? '<span class="nvp-eq" aria-label="Playing"><i></i><i></i><i></i></span>' : ''}</button></li>`).join('');
    }

    function renderTime() {
        const total = Number.isFinite(audio.duration) ? audio.duration : 0;
        const cap = currentKind() === 'beat' ? Math.min(total || BEAT_PREVIEW_SECONDS, BEAT_PREVIEW_SECONDS) : total;
        const p = cap ? Math.min(1, audio.currentTime / cap) : 0;
        root.style.setProperty('--nvp-p', p.toFixed(4));
        $('[data-nvp-now]').textContent = clock(audio.currentTime);
        $('[data-nvp-total]').textContent = clock(cap);
        $('[data-nvp-preview]').hidden = currentKind() !== 'beat';
        const wave = $('.nvp-wave');
        wave.setAttribute('aria-valuenow', String(Math.round(p * 100)));
        wave.setAttribute('aria-valuetext', `${clock(audio.currentTime)} of ${clock(cap)}`);
    }

    function renderPlaying() {
        const playing = !audio.paused && !audio.ended;
        root.classList.toggle('is-playing', playing);
        $$('[data-nvp="toggle"]').forEach(b => b.setAttribute('aria-label', playing ? 'Pause' : 'Play'));
        const item = state.queue[state.index];
        document.querySelectorAll('[data-kind][data-id]').forEach(btn => {
            const on = item && btn.dataset.kind === item.kind && String(btn.dataset.id) === String(item.id);
            btn.classList.toggle('is-current', Boolean(on));
            btn.classList.toggle('is-playing', Boolean(on && playing));
            if (btn.dataset.title) btn.setAttribute('aria-label', `${on && playing ? 'Pause' : 'Play'} ${btn.dataset.title}`);
        });
        if ('mediaSession' in navigator) navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
        document.dispatchEvent(new CustomEvent('nvp:state', { detail: { item: item || null, playing } }));
    }

    /* ------------------------------------------------------------------ playback */
    async function load(index, { autoplay = true, at = 0 } = {}) {
        if (index < 0 || index >= state.queue.length) return;
        reportListen();
        state.index = index;
        const item = state.queue[index];
        state.listened = 0;
        state.last = 0;
        state.reported = false;
        state.previewEnded = false;
        state.resumeAt = at;
        state.detail = details.get(`${item.kind}:${item.id}`) || null;
        root.hidden = false;
        document.body.classList.add('nvp-has-player');
        renderMeta();
        audio.src = api.url(`/${item.kind === 'beat' ? 'beats' : 'tracks'}/${encodeURIComponent(item.id)}/${item.kind === 'beat' ? 'preview' : 'stream'}`);
        renderTime();
        persist();
        refreshDetail(item.kind, item.id).then(() => { renderMeta(); changed(); }).catch(() => {});
        if (autoplay) {
            try {
                await audio.play();
            } catch (error) {
                if (error && error.name === 'NotAllowedError') return;       // the browser wants a tap first
                toast('This audio could not be played. Check your connection and try again.', 'error');
            }
        }
        renderPlaying();
    }

    function nextIndex(step) {
        const n = state.queue.length;
        if (!n) return -1;
        if (state.shuffle && n > 1) {
            let i;
            do { i = Math.floor(Math.random() * n); } while (i === state.index);
            return i;
        }
        const i = state.index + step;
        if (i >= n) return state.repeat === 'all' ? 0 : -1;
        if (i < 0) return state.repeat === 'all' ? n - 1 : 0;
        return i;
    }

    function next(fromEnd = false) {
        if (fromEnd && state.repeat === 'one') {
            audio.currentTime = 0;
            audio.play().catch(() => {});
            return;
        }
        const i = nextIndex(1);
        if (i < 0) {
            audio.pause();
            audio.currentTime = 0;
            renderPlaying();
            return;
        }
        load(i);
    }

    function prev() {
        if (audio.currentTime > 3) { audio.currentTime = 0; return; }
        const i = nextIndex(-1);
        load(i < 0 ? 0 : i);
    }

    function toggle() {
        if (state.index < 0) return;
        if (state.previewEnded) { state.previewEnded = false; audio.currentTime = 0; }
        if (audio.paused) audio.play().catch(() => toast('Tap play again to start the audio.', 'info'));
        else audio.pause();
    }

    /** Report listening time once per play; the server counts a stream from 30 seconds. */
    function reportListen() {
        const item = state.queue[state.index];
        if (!item || state.reported || state.listened < 1) return;
        state.reported = true;
        if (item.kind === 'beat') {
            api.post(`/beats/${encodeURIComponent(item.id)}/plays`).catch(() => {});
            return;
        }
        api.post(`/tracks/${encodeURIComponent(item.id)}/plays`, {
            seconds: Math.round(state.listened),
            session_id: sessionId(),
            device: /Mobi/i.test(navigator.userAgent) ? 'mobile' : 'desktop'
        }).then(res => {
            if (res && res.counted && state.detail && state.detail.id === Number(item.id)) {
                state.detail.plays = res.plays;
                renderDetail();
                sync(state.detail);
            }
        }).catch(() => {});
    }

    audio.addEventListener('timeupdate', () => {
        const step = audio.currentTime - state.last;
        if (step > 0 && step < 2) state.listened += step;
        state.last = audio.currentTime;
        if (currentKind() === 'track' && state.listened >= STREAM_SECONDS) reportListen();
        if (currentKind() === 'beat' && audio.currentTime >= BEAT_PREVIEW_SECONDS && !state.previewEnded) {
            state.previewEnded = true;
            audio.pause();
            reportListen();
            toast('That was the free preview. Buy a licence to get the full beat.', 'info');
        }
        renderTime();
    });
    audio.addEventListener('loadedmetadata', () => {
        if (state.resumeAt && state.resumeAt < audio.duration) audio.currentTime = state.resumeAt;
        state.resumeAt = 0;
        renderTime();
    });
    audio.addEventListener('playing', () => { state.last = audio.currentTime; renderPlaying(); });
    audio.addEventListener('pause', () => { renderPlaying(); persist(); });
    audio.addEventListener('ended', () => { reportListen(); next(true); });
    audio.addEventListener('error', () => {
        if (audio.src && state.index >= 0) toast('This audio is not available right now.', 'error');
    });

    /* ------------------------------------------------------------------ remember the queue between pages */
    function persist() {
        try {
            sessionStorage.setItem(STATE_KEY, JSON.stringify({
                queue: state.queue.slice(0, 100), index: state.index, time: Math.floor(audio.currentTime || 0),
                shuffle: state.shuffle, repeat: state.repeat, volume: audio.volume
            }));
        } catch (_) { /* storage blocked: the player still works on this page */ }
    }
    window.addEventListener('pagehide', () => { reportListen(); persist(); });

    function restore() {
        let saved = null;
        try { saved = JSON.parse(sessionStorage.getItem(STATE_KEY) || 'null'); } catch (_) { saved = null; }
        if (!saved || !Array.isArray(saved.queue) || !saved.queue.length || saved.index < 0) return;
        state.queue = saved.queue;
        state.shuffle = Boolean(saved.shuffle);
        state.repeat = ['off', 'all', 'one'].includes(saved.repeat) ? saved.repeat : 'off';
        if (Number.isFinite(saved.volume)) { audio.volume = saved.volume; $('[data-nvp-volume]').value = String(Math.round(saved.volume * 100)); }
        renderToggles();
        load(Math.min(saved.index, state.queue.length - 1), { autoplay: false, at: saved.time || 0 });
    }

    /* ------------------------------------------------------------------ open / close the full player */
    let lastFocus = null;
    function openFull() {
        if (state.index < 0) return;
        lastFocus = document.activeElement;
        full.hidden = false;
        document.body.classList.add('nvp-open');
        requestAnimationFrame(() => full.classList.add('is-open'));
        $('[data-nvp="close"]').focus();
    }
    function closeFull() {
        full.classList.remove('is-open');
        document.body.classList.remove('nvp-open');
        setTimeout(() => { full.hidden = true; }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 260);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function renderToggles() {
        const sh = $('[data-nvp="shuffle"]');
        sh.setAttribute('aria-pressed', String(state.shuffle));
        const rp = $('[data-nvp="repeat"]');
        rp.setAttribute('aria-pressed', String(state.repeat !== 'off'));
        rp.classList.toggle('is-one', state.repeat === 'one');
        rp.setAttribute('aria-label', `Repeat: ${state.repeat === 'off' ? 'off' : state.repeat === 'all' ? 'all songs' : 'this song'}`);
    }

    /* ------------------------------------------------------------------ events */
    root.addEventListener('click', async e => {
        const jump = e.target.closest('[data-nvp-jump]');
        if (jump) { load(Number(jump.dataset.nvpJump)); return; }
        const btn = e.target.closest('[data-nvp]');
        if (!btn) return;
        const what = btn.dataset.nvp;
        const d = state.detail;
        try {
            switch (what) {
                case 'toggle': toggle(); break;
                case 'next': next(); break;
                case 'prev': prev(); break;
                case 'open': openFull(); break;
                case 'close': closeFull(); break;
                case 'shuffle': state.shuffle = !state.shuffle; renderToggles(); persist(); break;
                case 'repeat': state.repeat = state.repeat === 'off' ? 'all' : state.repeat === 'all' ? 'one' : 'off'; renderToggles(); persist(); break;
                case 'queue': {
                    const q = $('.nvp-queue');
                    q.hidden = !q.hidden;
                    btn.setAttribute('aria-pressed', String(!q.hidden));
                    break;
                }
                case 'favorite': if (d && currentKind() === 'track') { btn.disabled = true; await actions.favorite(d); btn.disabled = false; } break;
                case 'save': if (d) { btn.disabled = true; await actions.save(d); } break;
                case 'share': if (d) actions.share(d, currentKind()); break;
                case 'tip': if (d) actions.tip(d.creator); break;
                case 'download': if (d) actions.download(d); break;
                case 'buy': if (d) actions.buyBeat(d); break;
                default: break;
            }
        } catch (error) {
            btn.disabled = false;
            toast(error.message || 'That did not work. Try again.', 'error');
        }
    });

    // waveform seeking: click, drag, or arrow keys
    const wave = $('.nvp-wave');
    const seekTo = clientX => {
        const box = wave.getBoundingClientRect();
        const p = Math.min(1, Math.max(0, (clientX - box.left) / box.width));
        const total = currentKind() === 'beat' ? Math.min(audio.duration || BEAT_PREVIEW_SECONDS, BEAT_PREVIEW_SECONDS) : audio.duration;
        if (Number.isFinite(total) && total > 0) {
            audio.currentTime = p * total;
            state.last = audio.currentTime;
            renderTime();
        }
    };
    let dragging = false;
    wave.addEventListener('pointerdown', e => { dragging = true; wave.setPointerCapture(e.pointerId); seekTo(e.clientX); });
    wave.addEventListener('pointermove', e => { if (dragging) seekTo(e.clientX); });
    wave.addEventListener('pointerup', () => { dragging = false; });
    wave.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            audio.currentTime = Math.max(0, audio.currentTime + (e.key === 'ArrowRight' ? 5 : -5));
            state.last = audio.currentTime;
        }
    });
    $('[data-nvp-volume]').addEventListener('input', e => { audio.volume = Number(e.target.value) / 100; persist(); });

    document.addEventListener('keydown', e => {
        if (full.hidden || document.querySelector('dialog[open]') || Date.now() - sheetClosedAt < 400) return;
        if (e.key === 'Escape') closeFull();
        if (e.key === ' ' && !e.target.closest('input, textarea, select, button, a, [role=slider]')) { e.preventDefault(); toggle(); }
    });

    // cards, track pages and any other play button: <button data-kind="track|beat" data-id="..." data-title="...">
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-kind][data-id]');
        if (!btn || root.contains(btn)) return;
        e.preventDefault();
        const item = state.queue[state.index];
        if (item && item.kind === btn.dataset.kind && String(item.id) === String(btn.dataset.id)) { toggle(); return; }
        const scope = btn.closest('.track-grid, .beat-grid, [data-play-scope]') || document;
        const buttons = Array.from(scope.querySelectorAll('[data-kind][data-id]')).filter(b => b.dataset.kind === btn.dataset.kind);
        playButtons(buttons, Math.max(0, buttons.indexOf(btn)));
    });

    if ('mediaSession' in navigator) {
        const handle = (name, fn) => { try { navigator.mediaSession.setActionHandler(name, fn); } catch (_) { /* not supported */ } };
        handle('play', () => toggle());
        handle('pause', () => toggle());
        handle('previoustrack', () => prev());
        handle('nexttrack', () => next());
        handle('seekto', d => { if (Number.isFinite(d.seekTime)) audio.currentTime = d.seekTime; });
    }

    /* ------------------------------------------------------------------ public API */
    function itemFromButton(btn) {
        const card = btn.closest('article, .track-hero, .nvp-hero, li') || btn.parentElement;
        const img = card && card.querySelector('img');
        const by = card && card.querySelector('.track-artist a, .beat-producer a, [data-artist]');
        return {
            kind: btn.dataset.kind === 'beat' ? 'beat' : 'track',
            id: btn.dataset.id,
            title: btn.dataset.title || '',
            artist: btn.dataset.artist || (by ? by.textContent.trim() : ''),
            cover: btn.dataset.cover || (img ? img.getAttribute('src') : '')
        };
    }
    function playButtons(buttons, index) {
        const seen = new Set();
        const items = [];
        let at = 0;
        buttons.forEach((b, i) => {
            const key = `${b.dataset.kind}:${b.dataset.id}`;
            if (seen.has(key)) return;
            seen.add(key);
            if (i === index) at = items.length;
            items.push(itemFromButton(b));
        });
        playItems(items, at);
    }
    function playItems(items, index = 0) {
        state.queue = items.map(i => ({ kind: i.kind === 'beat' ? 'beat' : 'track', id: String(i.id), title: i.title || '', artist: i.artist || '', cover: i.cover || '' }));
        load(index);
    }

    document.body.appendChild(root);
    api.get('/meta').then(meta => { window.NVMeta = meta; }).catch(() => {});
    renderToggles();
    restore();

    window.NVPlayer = {
        playButtons, playItems, toggle,
        current: () => ({ item: state.queue[state.index] || null, detail: state.detail, playing: !audio.paused }),
        open: openFull,
        actions,
        icon
    };
    document.dispatchEvent(new CustomEvent('nvp:ready'));
})();
