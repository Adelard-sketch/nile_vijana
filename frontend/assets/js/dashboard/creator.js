/*
 * Artist / choir and producer screens: overview, catalogue (with approval status,
 * rejection reasons and resubmission), upload, and analytics.
 * Data: GET /api/dashboard/music and /api/dashboard/beats (real streams, sales and ledger entries).
 */
(function () {
    'use strict';

    const { ui, media } = window.Dash;
    const { esc, num, money, date, dateTime, ago } = ui;
    const api = window.api;
    const core = window.NVCore;
    const MUSIC = ['artist', 'choir'];
    const BEATS = ['producer'];

    const needProfile = (ctx, what) => ctx.me.creator_profile ? '' : ui.notice({
        tone: 'warn', title: 'Set up your profile first',
        text: `Your ${what} is published under your public profile (name, photo, bio). It takes a minute.`,
        action: '<a class="d-btn d-btn--primary" href="#/profile">Create my profile</a>'
    });

    const rejectedNotice = (count, what, href) => count ? ui.notice({
        tone: 'bad', title: `${count} ${what}${count === 1 ? '' : 's'} need${count === 1 ? 's' : ''} changes`,
        text: 'The review team left a note on each one. Fix it and resubmit to get it published.',
        action: `<a class="d-btn" href="${href}">See what to fix</a>`
    }) : '';

    const transactions = rows => ui.table({
        columns: [
            { label: 'What', main: true, cell: t => `<div class="d-cell-title">${esc(t.label)}</div><div class="d-cell-sub">${esc(t.memo || '')}</div>` },
            { label: 'When', cell: t => `<span title="${esc(dateTime(t.created_at))}">${esc(ago(t.created_at))}</span>` },
            { label: 'Amount', num: true, cell: t => `<strong style="color:${t.amount < 0 ? '#f6a09f' : 'inherit'}">${t.amount > 0 ? '+' : ''}${money(t.amount)}</strong>` }
        ],
        rows,
        empty: ui.empty({ title: 'No money movements yet', text: 'Sales, tips and withdrawals show here.' })
    });

    /* ================================================================ music overview */
    Dash.route('/music', {
        title: 'Music overview',
        roles: MUSIC,
        async render(ctx) {
            const d = await api.get('/dashboard/music', { range: ctx.params.get('range') || 30 });
            const t = d.totals;
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/music/upload">Upload a song</a>');
            const days = d.range_days;
            ctx.view.innerHTML = `
                ${needProfile(ctx, 'music')}
                ${rejectedNotice(t.rejected, 'song', '#/music/songs?status=rejected')}
                <div class="d-toolbar">${ui.range(ctx)}<span class="d-spacer"></span><span class="d-muted">Streams count after 30 seconds of listening.</span></div>
                <div class="d-stats">
                    ${ui.stat({ label: 'Available balance', value: money(t.balance), hint: t.pending_payouts ? `${money(t.pending_payouts)} on its way to you` : 'Ready to withdraw', href: '#/earnings', tone: 'hero' })}
                    ${ui.stat({ label: 'Total earnings', value: money(t.earnings), hint: 'Song sales and tips, after commission' })}
                    ${ui.stat({ label: 'Pending earnings', value: money(t.pending_earnings), hint: 'Sales waiting for the buyer to approve payment' })}
                    ${ui.stat({ label: `Streams, last ${days} days`, value: num(t.streams_range), hint: `${num(t.listeners_range)} listeners, ${num(t.streams)} all time` })}
                    ${ui.stat({ label: 'Songs', value: num(t.uploads), hint: `${num(t.live)} live, ${num(t.in_review)} in review, ${num(t.rejected)} rejected`, href: '#/music/songs' })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-8', title: 'Streams per day', sub: `Last ${days} days, all your songs.`, body: '<div data-chart="streams"></div>' })}
                    ${ui.panel({ cls: 'd-col-4', title: 'Top songs', sub: `By streams in the last ${days} days.`, flush: true, body: ui.table({
                        columns: [
                            { label: 'Song', main: true, cell: s => `<div class="d-cell-media">${ui.thumb(s.cover_url, s.title)}<div class="d-cell-title">${esc(s.title)}</div></div>` },
                            { label: 'Streams', num: true, cell: s => num(s.plays_range) }
                        ], rows: d.top_songs, empty: ui.empty({ title: 'No live songs yet', text: 'Once a song is approved its streams show here.' })
                    }) })}
                    ${ui.panel({ cls: 'd-col-7', title: 'Earnings per month', sub: 'Song sales and tips after commission, last 12 months.', body: '<div data-chart="monthly"></div>' })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Recent transactions', flush: true, actions: '<a class="d-btn d-btn--sm" href="#/earnings">Wallet</a>', body: transactions(d.transactions) })}
                    ${ui.panel({ cls: 'd-col-12', title: 'Recent releases', flush: true, actions: '<a class="d-btn d-btn--sm" href="#/music/songs">All songs</a>', body: ui.table({
                        columns: [
                            { label: 'Song', main: true, cell: s => media.titleCell(s, 'song') },
                            { label: 'Released', cell: s => esc(date(s.released_at || s.release_date)) },
                            { label: 'Streams', num: true, cell: s => num(s.plays) },
                            { label: 'Favourites', num: true, cell: s => num(s.likes) },
                            { label: 'Sold', num: true, cell: s => num(s.sold) },
                            { label: 'Earned', num: true, cell: s => money(s.revenue) }
                        ], rows: d.recent_releases, empty: ui.empty({ title: 'Nothing released yet', action: '<a class="d-btn d-btn--primary" href="#/music/upload">Upload your first song</a>' })
                    }) })}
                </div>`;
            ui.chart.line(ctx.view.querySelector('[data-chart="streams"]'), { labels: d.daily_streams.map(x => x.label), series: [{ name: 'Streams', values: d.daily_streams.map(x => x.plays) }] });
            ui.chart.bars(ctx.view.querySelector('[data-chart="monthly"]'), { labels: d.monthly.map(m => m.label.replace(/ \d{4}$/, '')), series: [{ name: 'Song sales', values: d.monthly.map(m => m.sales) }, { name: 'Tips', values: d.monthly.map(m => m.tips) }], format: v => money(v) });
        }
    });

    /* ================================================================ my songs */
    const CATALOG_TABS = [['', 'All'], ['live', 'Live'], ['in_review', 'In review'], ['rejected', 'Needs changes']];
    Dash.route('/music/songs', {
        title: 'My songs',
        roles: MUSIC,
        async render(ctx) {
            const status = ctx.params.get('status') || '';
            const d = await api.get('/dashboard/music', { range: 30 });
            const all = d.songs;
            const rows = status ? all.filter(s => status === 'in_review' ? ['in_review', 'processing'].includes(s.status) : s.status === status) : all;
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/music/upload">Upload a song</a>');
            const counts = { live: d.totals.live, in_review: d.totals.in_review, rejected: d.totals.rejected };
            ctx.view.innerHTML = `${ui.tabs(CATALOG_TABS.map(([v, l]) => ({ label: l, href: ctx.link({ status: v }), current: status === v, count: v === 'rejected' ? counts.rejected : 0 })))}
                <p class="d-intro">New songs and changes are checked by the Nile Vijana team before they go public. If a song is rejected, you will see why here.</p>
                ${ui.panel({ flush: true, body: media.table('song', rows, {
                    engagement: true,
                    extra: [{ label: 'Sold', num: true, cell: r => num(r.sold) }, { label: 'Earned', num: true, cell: r => money(r.revenue) }],
                    emptyText: status ? 'No songs with this status.' : 'Upload a song to get started.',
                    emptyAction: status ? '' : '<a class="d-btn d-btn--primary" href="#/music/upload">Upload a song</a>'
                }) })}`;
            media.bind(ctx.view, 'song', rows, { ownerLabel: 'you' });
        }
    });

    /* ================================================================ upload a song */
    Dash.route('/music/upload', {
        title: 'Upload a song',
        roles: MUSIC,
        async render(ctx) {
            ctx.view.innerHTML = `${needProfile(ctx, 'song')}
                ${ui.notice({ tone: 'info', title: 'How publishing works', text: 'Your song goes to the review queue. The team checks the audio, cover and details, usually within two working days. You get a message when it is approved, or a note telling you what to change.' })}
                <form class="d-form" id="song-form" data-mode="create" novalidate>${media.songFields({ meta: ctx.meta })}
                <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit"${ctx.me.creator_profile ? '' : ' disabled'}>Submit for review</button><span class="d-form-msg" data-msg></span></div></form>`;
            const form = ctx.view.querySelector('#song-form');
            ui.onSubmit(form, async f => {
                f.querySelector('[data-msg]').textContent = 'Uploading… large files can take a minute.';
                try {
                    const song = await media.createSong(f);
                    core.toast(`"${song.title}" was submitted for review.`);
                    location.hash = '#/music/songs?status=in_review';
                } finally {
                    f.querySelector('[data-msg]').textContent = '';
                }
            }, { title: media.UPLOAD_TITLES.song });
        }
    });

    /* ================================================================ music analytics */
    Dash.route('/music/analytics', {
        title: 'Music analytics',
        roles: MUSIC,
        async render(ctx) {
            const d = await api.get('/dashboard/music', { range: ctx.params.get('range') || 30 });
            const days = d.range_days;
            const live = d.songs.filter(s => s.status === 'live');
            ctx.view.innerHTML = `
                <div class="d-toolbar">${ui.range(ctx)}</div>
                <div class="d-stats">
                    ${ui.stat({ label: `Streams, last ${days} days`, value: num(d.totals.streams_range) })}
                    ${ui.stat({ label: 'Listeners', value: num(d.totals.listeners_range), hint: 'Different people or devices' })}
                    ${ui.stat({ label: 'Followers', value: num(d.totals.followers) })}
                    ${ui.stat({ label: 'Favourites', value: num(d.totals.likes), hint: `${num(d.totals.saves)} saved to libraries` })}
                    ${ui.stat({ label: 'Shares', value: num(d.totals.shares), hint: 'Links shared from the player' })}
                    ${ui.stat({ label: 'Downloads', value: num(d.totals.downloads), hint: `${num(d.totals.songs_sold)} bought` })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-8', title: 'Streams per day', body: '<div data-chart="streams"></div>' })}
                    ${ui.panel({ cls: 'd-col-4', title: 'Where listeners are', sub: `Last ${days} days.`, body: ui.chart.hbars(d.top_cities.map(c => ({ label: c.city, value: c.plays }))) })}
                    ${ui.panel({ cls: 'd-col-12', title: 'Earnings per month', sub: 'After commission.', body: '<div data-chart="monthly"></div>' })}
                    ${ui.panel({ cls: 'd-col-12', title: 'Per song', flush: true, body: ui.table({
                        columns: [
                            { label: 'Song', main: true, cell: s => media.titleCell(s, 'song') },
                            { label: `Streams (${days} d)`, num: true, cell: s => num(s.plays_range) },
                            { label: 'Streams (all time)', num: true, cell: s => num(s.plays) },
                            { label: 'Favourites', num: true, cell: s => num(s.likes) },
                            { label: 'Saves', num: true, cell: s => num(s.saves) },
                            { label: 'Shares', num: true, cell: s => num(s.shares) },
                            { label: 'Downloads', num: true, cell: s => num(s.downloads) },
                            { label: 'Sold', num: true, cell: s => num(s.sold) },
                            { label: 'Revenue', num: true, cell: s => money(s.revenue) }
                        ], rows: live, empty: ui.empty({ title: 'No live songs yet' })
                    }) })}
                </div>`;
            ui.chart.line(ctx.view.querySelector('[data-chart="streams"]'), { labels: d.daily_streams.map(x => x.label), series: [{ name: 'Streams', values: d.daily_streams.map(x => x.plays) }] });
            ui.chart.bars(ctx.view.querySelector('[data-chart="monthly"]'), { labels: d.monthly.map(m => m.label.replace(/ \d{4}$/, '')), series: [{ name: 'Song sales', values: d.monthly.map(m => m.sales) }, { name: 'Tips', values: d.monthly.map(m => m.tips) }], format: v => money(v) });
        }
    });

    /* ================================================================ beats overview */
    const tierName = (meta, tier) => ((meta.license_tiers || []).find(t => t.tier === tier) || {}).name || tier;

    Dash.route('/beats', {
        title: 'Beats overview',
        roles: BEATS,
        async render(ctx) {
            const d = await api.get('/dashboard/beats', { range: ctx.params.get('range') || 30 });
            const t = d.totals;
            const days = d.range_days;
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/beats/upload">Upload a beat</a>');
            ctx.view.innerHTML = `
                ${needProfile(ctx, 'beat')}
                ${rejectedNotice(t.rejected, 'beat', '#/beats/list?status=rejected')}
                <div class="d-toolbar">${ui.range(ctx)}</div>
                <div class="d-stats">
                    ${ui.stat({ label: 'Available balance', value: money(t.balance), hint: t.pending_payouts ? `${money(t.pending_payouts)} on its way to you` : 'Ready to withdraw', href: '#/earnings', tone: 'hero' })}
                    ${ui.stat({ label: `Earned, last ${days} days`, value: money(t.revenue_range), hint: `${money(t.revenue)} all time, after commission` })}
                    ${ui.stat({ label: 'Pending earnings', value: money(t.pending_earnings), hint: 'Sales waiting for payment approval' })}
                    ${ui.stat({ label: 'Licences sold', value: num(t.licences_sold), hint: `${num(t.licences_range)} in the last ${days} days` })}
                    ${ui.stat({ label: 'Beats', value: num(t.uploads), hint: `${num(t.live)} on sale, ${num(t.in_review)} in review, ${num(t.rejected)} rejected`, href: '#/beats/list' })}
                    ${ui.stat({ label: 'Preview plays', value: num(t.plays) })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-8', title: 'Licence earnings per month', sub: 'After commission, last 12 months.', body: '<div data-chart="monthly"></div>' })}
                    ${ui.panel({ cls: 'd-col-4', title: 'Sales by licence', sub: 'All time.', body: ui.chart.hbars(d.by_tier.map(x => ({ label: tierName(ctx.meta, x.tier), value: x.sold })), v => `${num(v)} sold`) })}
                    ${ui.panel({ cls: 'd-col-7', title: 'Recent sales', flush: true, body: ui.table({
                        columns: [
                            { label: 'Beat', main: true, cell: s => `<div class="d-cell-title">${esc(s.title)}</div><div class="d-cell-sub">${esc(tierName(ctx.meta, s.tier))}, bought by ${esc(s.buyer)}</div>` },
                            { label: 'When', cell: s => esc(ago(s.paid_at)) },
                            { label: 'You earned', num: true, cell: s => money(s.earned) }
                        ], rows: d.recent_sales, empty: ui.empty({ title: 'No sales yet', text: 'When an artist buys a licence it shows here.' })
                    }) })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Popular beats', flush: true, body: ui.table({
                        columns: [
                            { label: 'Beat', main: true, cell: b => `<div class="d-cell-media">${ui.thumb(b.cover_url, b.title)}<div class="d-cell-title">${esc(b.title)}</div></div>` },
                            { label: 'Plays', num: true, cell: b => num(b.plays) },
                            { label: 'Sold', num: true, cell: b => num(b.sold) }
                        ], rows: d.popular, empty: ui.empty({ title: 'No beats yet' })
                    }) })}
                </div>`;
            ui.chart.bars(ctx.view.querySelector('[data-chart="monthly"]'), { labels: d.monthly.map(m => m.label.replace(/ \d{4}$/, '')), series: [{ name: 'Earned', values: d.monthly.map(m => m.earned) }], format: v => money(v) });
        }
    });

    Dash.route('/beats/list', {
        title: 'My beats',
        roles: BEATS,
        async render(ctx) {
            const status = ctx.params.get('status') || '';
            const d = await api.get('/dashboard/beats', { range: 30 });
            const rows = status ? d.beats.filter(b => status === 'in_review' ? ['in_review', 'processing'].includes(b.status) : b.status === status) : d.beats;
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/beats/upload">Upload a beat</a>');
            ctx.view.innerHTML = `${ui.tabs(CATALOG_TABS.map(([v, l]) => ({ label: l, href: ctx.link({ status: v }), current: status === v, count: v === 'rejected' ? d.totals.rejected : 0 })))}
                <p class="d-intro">Beats go on sale after the team approves them. Rejected beats show the reason; fix them and resubmit.</p>
                ${ui.panel({ flush: true, body: media.table('beat', rows, {
                    extra: [{ label: 'Sold', num: true, cell: r => num(r.sold) }, { label: 'Earned', num: true, cell: r => money(r.revenue) }],
                    emptyText: status ? 'No beats with this status.' : 'Upload a beat to start selling licences.',
                    emptyAction: status ? '' : '<a class="d-btn d-btn--primary" href="#/beats/upload">Upload a beat</a>'
                }) })}`;
            media.bind(ctx.view, 'beat', rows, { ownerLabel: 'you' });
        }
    });

    Dash.route('/beats/upload', {
        title: 'Upload a beat',
        roles: BEATS,
        async render(ctx) {
            ctx.view.innerHTML = `${needProfile(ctx, 'beat')}
                ${ui.notice({ tone: 'info', title: 'How publishing works', text: 'Your beat goes to the review queue first. Visitors only ever hear the tagged preview; buyers get the files of the licence they paid for.' })}
                <form class="d-form" id="beat-form" data-mode="create" novalidate>${media.beatFields({ meta: ctx.meta })}
                <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit"${ctx.me.creator_profile ? '' : ' disabled'}>Submit for review</button><span class="d-form-msg" data-msg></span></div></form>`;
            const form = ctx.view.querySelector('#beat-form');
            ui.onSubmit(form, async f => {
                f.querySelector('[data-msg]').textContent = 'Uploading… large files can take a minute.';
                try {
                    const beat = await media.createBeat(f);
                    core.toast(`"${beat.title}" was submitted for review.`);
                    location.hash = '#/beats/list?status=in_review';
                } finally {
                    f.querySelector('[data-msg]').textContent = '';
                }
            }, { title: media.UPLOAD_TITLES.beat, check: media.tierProblems });
        }
    });

    Dash.route('/beats/analytics', {
        title: 'Beat analytics',
        roles: BEATS,
        async render(ctx) {
            const d = await api.get('/dashboard/beats', { range: ctx.params.get('range') || 30 });
            const live = d.beats.filter(b => ['live', 'sold_exclusive'].includes(b.status));
            ctx.view.innerHTML = `
                <div class="d-toolbar">${ui.range(ctx)}</div>
                <div class="d-stats">
                    ${ui.stat({ label: `Earned, last ${d.range_days} days`, value: money(d.totals.revenue_range) })}
                    ${ui.stat({ label: `Licences sold, last ${d.range_days} days`, value: num(d.totals.licences_range) })}
                    ${ui.stat({ label: 'Preview plays', value: num(d.totals.plays), hint: 'All time' })}
                    ${ui.stat({ label: 'Sold exclusively', value: num(d.totals.sold_exclusive) })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-8', title: 'Licences sold per month', body: '<div data-chart="sold"></div>' })}
                    ${ui.panel({ cls: 'd-col-4', title: 'Earnings by licence', sub: 'All time, after commission.', body: ui.chart.hbars(d.by_tier.map(x => ({ label: tierName(ctx.meta, x.tier), value: x.earned })), v => money(v)) })}
                    ${ui.panel({ cls: 'd-col-12', title: 'Per beat', flush: true, body: ui.table({
                        columns: [
                            { label: 'Beat', main: true, cell: b => media.titleCell(b, 'beat') },
                            { label: 'Preview plays', num: true, cell: b => num(b.plays) },
                            { label: 'Licences sold', num: true, cell: b => num(b.sold) },
                            { label: 'Revenue', num: true, cell: b => money(b.revenue) },
                            { label: 'Plays per sale', num: true, cell: b => b.sold ? num(Math.round(b.plays / b.sold)) : '<span class="d-muted">No sales</span>' }
                        ], rows: live, empty: ui.empty({ title: 'No beats on sale yet' })
                    }) })}
                </div>`;
            ui.chart.bars(ctx.view.querySelector('[data-chart="sold"]'), { labels: d.monthly.map(m => m.label.replace(/ \d{4}$/, '')), series: [{ name: 'Licences sold', values: d.monthly.map(m => m.sold) }] });
        }
    });
})();
