/*
 * Admin screens: overview, unified onboarding, upload for someone, review queue,
 * songs, beats, studios, studio bookings, people and withdrawals.
 * Every number comes from the API (GET /api/admin/overview and the list endpoints).
 */
(function () {
    'use strict';

    const { ui, media } = window.Dash;
    const { esc, num, money, date, dateTime, ago, compact } = ui;
    const api = window.api;
    const core = window.NVCore;
    const ADMIN = ['admin'];

    /* ================================================================ overview */
    const FEED_ICON = { user: ['user', ''], upload: ['upload', 'warn'], check: ['check', 'good'], x: ['x', 'bad'], cart: ['cart', 'good'], calendar: ['calendar', ''], studio: ['studio', ''], wallet: ['wallet', 'warn'] };
    const feed = items => items.length ? `<ul class="d-feed">${items.map(e => {
        const [ic, tone] = FEED_ICON[e.icon] || ['info', ''];
        return `<li><span class="d-feed-icon${tone ? ` d-feed-icon--${tone}` : ''}">${ui.icon(ic)}</span><span>${esc(e.text)}${e.amount ? ` <span class="d-muted">(${money(e.amount)})</span>` : ''}</span><time datetime="${esc(e.at)}" title="${esc(dateTime(e.at))}">${esc(ago(e.at))}</time></li>`;
    }).join('')}</ul>` : ui.empty({ title: 'No activity yet' });

    Dash.route('/admin', {
        title: 'Admin overview',
        roles: ADMIN,
        async render(ctx) {
            const o = await api.get('/admin/overview');
            core.setCurrency(o.currency);
            const a = o.accounts, c = o.content, p = o.pending_approvals, r = o.revenue;
            const plural = (n, one, many) => (n === 1 ? one : many);
            const items = [
                ['songs', plural(p.songs, 'song to review', 'songs to review'), '#/admin/review?tab=songs'],
                ['beats', plural(p.beats, 'beat to review', 'beats to review'), '#/admin/review?tab=beats'],
                ['studios', plural(p.studios, 'studio to verify', 'studios to verify'), '#/admin/review?tab=studios'],
                ['payouts', plural(p.payouts, 'withdrawal to send', 'withdrawals to send'), '#/admin/payouts'],
                ['questions', plural(p.questions, 'question to answer', 'questions to answer'), '#/admin/questions']
            ].filter(([k]) => p[k] > 0);
            const waiting = items.reduce((s, [k]) => s + p[k], 0);
            const months = r.monthly;
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/admin/onboard">Onboard an account</a><a class="d-btn" href="#/admin/upload">Upload</a>');
            ctx.view.innerHTML = `
                <div class="d-attention${waiting ? '' : ' is-clear'}">
                    <strong>${waiting ? `${num(waiting)} thing${waiting === 1 ? '' : 's'} need${waiting === 1 ? 's' : ''} your decision` : 'Nothing is waiting for review'}</strong>
                    <div class="d-attention-items">${items.map(([k, label, href]) => `<a href="${href}"><b>${num(p[k])}</b> ${label}</a>`).join('')}${o.bookings.pending.length ? `<a href="#/admin/bookings?status=pending"><b>${num(o.bookings.by_status.pending || 0)}</b> ${(o.bookings.by_status.pending || 0) === 1 ? 'booking request waiting for a studio' : 'booking requests waiting for studios'}</a>` : ''}</div>
                </div>
                <div class="d-stats">
                    ${ui.stat({ label: 'Platform revenue', value: money(r.platform_revenue), hint: `${money(r.commission_this_month)} commission this month`, tone: 'hero' })}
                    ${ui.stat({ label: 'Total users', value: num(a.users), hint: `${num(a.new_this_month)} joined this month`, href: '#/admin/people?tab=accounts' })}
                    ${ui.stat({ label: 'Songs live', value: num(c.songs_live), hint: `${num(c.songs)} uploaded`, href: '#/admin/music' })}
                    ${ui.stat({ label: 'Beats live', value: num(c.beats_live), hint: `${num(c.beats)} uploaded`, href: '#/admin/beats' })}
                    ${ui.stat({ label: 'Pending approvals', value: num(p.total), hint: `${num(c.rejected)} rejected, waiting for fixes`, href: '#/admin/review', tone: p.total ? 'attention' : '' })}
                </div>
                <div class="d-stats d-stats--compact">
                    ${ui.stat({ label: 'Artists', value: num(a.artists), href: '#/admin/people?type=artist' })}
                    ${ui.stat({ label: 'Choirs', value: num(a.choirs), href: '#/admin/people?type=choir' })}
                    ${ui.stat({ label: 'Music producers', value: num(a.producers), href: '#/admin/people?type=producer' })}
                    ${ui.stat({ label: 'Artist managers', value: num(a.managers), href: '#/admin/people?type=manager' })}
                    ${ui.stat({ label: 'Vocal coaches', value: num(a.vocal_coaches), href: '#/admin/people?type=vocal_coach' })}
                    ${ui.stat({ label: 'Studios', value: num(a.studios), hint: `${num(a.studios_verified)} verified`, href: '#/admin/studios' })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-7', title: 'Platform revenue per month', sub: 'Commission Nile Vijana kept from sales and completed studio sessions, last 12 months.', body: '<div data-chart="commission"></div>' })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Money paid out to creators', sub: 'After commission, all time.', body: ui.chart.hbars([
                        { label: 'Artists and choirs', value: r.artist_earnings }, { label: 'Producers', value: r.producer_earnings }, { label: 'Studios', value: r.studio_earnings }
                    ], v => money(v)) + `<dl class="booking-facts" style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:18px">
                        <div><dt class="d-muted">Sales volume</dt><dd>${money(r.sales_volume)}</dd></div><div><dt class="d-muted">Studio payments</dt><dd>${money(r.booking_volume)}</dd></div>
                        <div><dt class="d-muted">Held in escrow</dt><dd>${money(r.held_in_escrow)}</dd></div><div><dt class="d-muted">Creator wallets</dt><dd>${money(r.creator_balances)}</dd></div></dl>` })}
                    ${ui.panel({ cls: 'd-col-12', title: 'Money through the platform', sub: 'Paid orders and studio payments per month.', body: '<div data-chart="volume"></div>' })}
                    ${ui.panel({ cls: 'd-col-7', title: 'Studio requests waiting for the studio', flush: true, actions: '<a class="d-btn d-btn--sm" href="#/admin/bookings">All bookings</a>', body: bookingTable(o.bookings.pending, true) })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Recent registrations', flush: true, actions: '<a class="d-btn d-btn--sm" href="#/admin/people?tab=accounts">People</a>', body: ui.table({
                        columns: [
                            { label: 'Name', main: true, cell: u => `<div class="d-cell-title">${esc(u.profile_name || u.name)}</div>${u.profile_name && u.profile_name !== u.name ? `<div class="d-cell-sub">${esc(u.name)}</div>` : ''}` },
                            { label: 'Type', cell: u => esc(ui.PROFILE_TYPES[u.profile_type] || roleLabel(u.roles)) },
                            { label: 'Joined', cell: u => `<span title="${esc(dateTime(u.created_at))}">${esc(ago(u.created_at))}</span>` }
                        ], rows: o.recent_registrations, empty: ui.empty({ title: 'No sign-ups yet' })
                    }) })}
                    ${ui.panel({ cls: 'd-col-12', title: 'Recent activity', flush: true, body: feed(o.activity) })}
                </div>`;
            ui.chart.bars(ctx.view.querySelector('[data-chart="commission"]'), { labels: months.map(m => m.label.replace(/ \d{4}$/, '')), series: [{ name: 'Commission', values: months.map(m => m.commission) }], format: v => money(v) });
            ui.chart.line(ctx.view.querySelector('[data-chart="volume"]'), { labels: months.map(m => m.label), series: [{ name: 'Paid orders', values: months.map(m => m.sales) }, { name: 'Studio payments', values: months.map(m => m.bookings) }], format: v => money(v) });
        }
    });

    const roleLabel = roles => {
        const map = { studio_owner: 'Studio owner', admin: 'Admin', fan: 'Listener', artist: 'Artist', producer: 'Producer', choir: 'Choir', manager: 'Manager', vocal_coach: 'Vocal coach' };
        const main = (roles || []).filter(x => x !== 'fan');
        return (main.length ? main : roles || ['fan']).map(x => map[x] || x).join(', ');
    };

    function bookingTable(rows, compactView = false) {
        return ui.table({
            columns: [
                { label: 'Booking', main: true, cell: b => `<div class="d-cell-title">${esc(b.studio.name)}</div><div class="d-cell-sub">${esc(b.room.name)}, #${esc(b.id)}</div>` },
                { label: 'Artist', cell: b => esc(b.artist.name) },
                { label: 'Session', cell: b => `${esc(dateTime(b.start_at))}<div class="d-cell-sub">${esc(b.hours)} h</div>` },
                ...(compactView ? [] : [{ label: 'Total', num: true, cell: b => money(b.total) }, { label: 'Paid', num: true, cell: b => money(b.amount_paid) }, { label: 'In escrow', num: true, cell: b => money(b.in_escrow) }]),
                { label: 'Status', cell: b => `${ui.badge(b.status, b.status === 'pending' ? 'Waiting for studio' : '')}${b.studio_note ? `<div class="d-cell-sub">${esc(b.studio_note)}</div>` : ''}` }
            ],
            rows,
            empty: ui.empty({ title: compactView ? 'No requests are waiting' : 'No bookings match this filter' })
        });
    }

    /* ================================================================ onboarding */
    const TYPES = [
        ['artist', 'Artist', 'music', 'Solo singer, rapper or band'],
        ['choir', 'Choir', 'users', 'Church, school or community choir'],
        ['producer', 'Music producer', 'beat', 'Makes and sells beats'],
        ['manager', 'Artist manager', 'briefcase', 'Looks after artists or choirs'],
        ['vocal_coach', 'Vocal coach', 'coach', 'Teaches singing, listed as a mentor'],
        ['studio', 'Studio', 'studio', 'Recording studio with rooms to book']
    ];
    const NAME_LABEL = { artist: 'Artist name', choir: 'Choir name', producer: 'Producer name', manager: "Manager's full name", vocal_coach: "Coach's name", studio: 'Studio name' };
    const PICTURE_LABEL = { artist: 'Profile picture', choir: 'Choir photo', producer: 'Profile picture', manager: 'Profile picture', vocal_coach: 'Profile picture', studio: 'Studio cover photo' };
    const BIO_LABEL = { artist: 'Bio', choir: 'Description', producer: 'Bio', manager: 'About', vocal_coach: 'Bio', studio: 'Description' };

    /** Groups of fields; each lists the types that use it. Hidden groups are disabled, so they are never sent. */
    function onboardForm(meta, musicians) {
        const only = (types, html) => `<div data-for="${types.join(' ')}" style="display:contents">${html}</div>`;
        const room = (i, r = {}) => `<div class="d-row" data-room>
            <label class="d-field" data-field="rooms.${i}.name"><span>Room name</span><input data-room-key="name" value="${esc(r.name || '')}" maxlength="120" placeholder="Vocal booth"></label>
            <label class="d-field" data-field="rooms.${i}.hourly_rate"><span>Price per hour (GH₵)</span><input type="number" data-room-key="hourly_rate" min="${Number(meta.min_room_rate || 20)}" max="${Number(meta.max_room_rate || 10000)}" step="1" placeholder="150" value="${esc(r.hourly_rate || '')}"></label>
            <label class="d-field" data-field="rooms.${i}.min_hours"><span>Minimum hours</span><input type="number" data-room-key="min_hours" min="1" max="12" value="${esc(r.min_hours || 1)}"></label>
            <label class="d-check"><input type="checkbox" data-room-key="engineer_included"${r.engineer_included ? ' checked' : ''}> Engineer included</label>
            <button class="d-btn d-btn--ghost d-btn--sm" type="button" data-remove-room aria-label="Remove room">${ui.icon('x')}</button></div>`;
        return `<form class="d-form" id="onboard-form" data-error-title="The account could not be created. Please complete the following:" novalidate>
            <fieldset class="d-fieldset" data-field="type"><legend>Account type</legend><p>Pick one; the form shows the fields that type needs.</p>
                <div class="d-types">${TYPES.map(([v, label, ic, help], i) => `<label class="d-type"><input type="radio" name="type" value="${v}"${i === 0 ? ' checked' : ''}>${ui.icon(ic)}<strong>${label}</strong><small>${help}</small></label>`).join('')}</div>
            </fieldset>
            <fieldset class="d-fieldset"><legend>Profile</legend><div class="d-fields">
                <label class="d-field" data-field="display_name"><span data-name-label>Artist name</span><input name="display_name" required maxlength="120" data-label="Artist name"></label>
                ${only(['studio'], ui.field({ label: "Owner's full name", name: 'contact_name', optional: true, hint: 'The person who will manage the studio account.' }))}
                ${only(['artist', 'choir', 'producer', 'vocal_coach'], ui.field({ label: 'City', name: 'city', optional: true, placeholder: 'Accra' }))}
                ${only(['choir'], ui.field({ label: 'Location', name: 'city', required: true, placeholder: 'Town or church, e.g. Cape Coast' }) + ui.field({ label: 'Number of members', name: 'members_count', type: 'number', required: true, attrs: 'min="2" max="5000"' }))}
                ${only(['artist', 'producer', 'choir'], `<label class="d-field" data-field="genres"><span data-genre-label>Genre</span><input name="genres" list="nv-genres" maxlength="255" data-label="Genre" placeholder="Afrobeats, Highlife"><small>Separate several with commas.</small></label>`)}
                ${only(['vocal_coach'], ui.field({ label: 'Years of experience', name: 'experience_years', type: 'number', required: true, attrs: 'min="0" max="80"' }) + ui.field({ label: 'Specialization', name: 'specialization', required: true, placeholder: 'Breath control, gospel runs, stage presence' }))}
                ${only(['manager'], ui.field({ label: 'Company', name: 'company', optional: true }))}
                ${only(['artist', 'choir', 'producer', 'vocal_coach', 'manager', 'studio'], `<label class="d-field d-field--wide" data-field="bio"><span data-bio-label>Bio</span><textarea name="bio" maxlength="3000"></textarea></label>`)}
                <label class="d-field" data-field="picture"><span data-picture-label>Profile picture</span><input type="file" name="picture" data-label="Profile picture" data-max-mb="${Number((meta.uploads || {}).max_image_mb || 5)}" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><small>JPG, PNG or WebP, up to ${Number((meta.uploads || {}).max_image_mb || 5)} MB.</small></label>
                ${ui.field({ label: 'Website or social link', name: 'website', optional: true, placeholder: 'https://' })}
            </div><datalist id="nv-genres">${(meta.genres || []).map(g => `<option value="${esc(g)}">`).join('')}</datalist></fieldset>

            ${only(['manager'], `<fieldset class="d-fieldset" data-field="managed_artists"><legend>Managed artists and choirs</legend><p>Tick the profiles this manager looks after. They can then follow their releases and earnings.</p>
                ${musicians.length ? `<div class="d-picker"><input type="search" placeholder="Search artists and choirs" aria-label="Search artists and choirs" data-picker-search><div class="d-picker-list">${musicians.map(m => `<label data-picker-name="${esc((m.stage_name || '').toLowerCase())}"><input type="checkbox" value="${esc(m.id)}" data-managed> ${esc(m.stage_name)} <span class="d-muted">${esc(ui.PROFILE_TYPES[m.type] || m.type)}</span></label>`).join('')}</div></div>` : '<p class="d-muted">No artists or choirs yet. Onboard them first, then link them here.</p>'}
            </fieldset>`)}

            ${only(['studio'], `<fieldset class="d-fieldset"><legend>Studio details</legend><div class="d-fields">
                ${ui.field({ label: 'City', name: 'city', required: true, placeholder: 'Accra, Kumasi, Takoradi…' })}
                ${ui.field({ label: 'Address', name: 'address', required: true, placeholder: 'Street and area, e.g. Oxford Street, Osu' })}
                ${ui.field({ label: 'Facilities and equipment', name: 'equipment', type: 'textarea', required: true, wide: true, placeholder: 'Microphones, interface, monitors, software, instruments…' })}
                ${ui.field({ label: 'Amenities', name: 'amenities', optional: true, placeholder: 'Wi-Fi, parking, lounge' })}
                ${ui.field({ label: 'Backup power (generator or inverter)', name: 'backup_power', type: 'checkbox' })}
            </div></fieldset>
            <fieldset class="d-fieldset" data-field="rooms"><legend>Rooms and pricing</legend><p>Add each room artists can book. Opening hours start as Monday to Saturday, 09:00 to 21:00; the owner can change them.</p>
                <div class="d-rows" data-rooms>${room(0)}</div>
                <div><button class="d-btn d-btn--sm" type="button" data-add-room>Add another room</button></div>
            </fieldset>`)}

            <fieldset class="d-fieldset"><legend>Contact and sign-in</legend><p>The person signs in with their email or username and a password, then a one-time code sent to the email. Leave the password empty and we email them how to set one.</p><div class="d-fields">
                ${ui.field({ label: 'Email address', name: 'email', type: 'email', required: true, missing: 'Email address is required: sign-in codes are sent to it.', attrs: 'autocomplete="off"' })}
                ${ui.field({ label: 'Phone number', name: 'phone', type: 'tel', optional: true, placeholder: '024 123 4567', hint: 'For payouts and booking alerts. Not used to sign in.' })}
                ${ui.field({ label: 'Username', name: 'username', optional: true, attrs: 'maxlength="30" autocomplete="off" pattern="[A-Za-z][A-Za-z0-9._]{2,29}" data-pattern-message="Username must be 3 to 30 characters, start with a letter, and use only letters, numbers, dots or underscores."', hint: 'Leave empty to create one from the email.' })}
                ${ui.field({ label: 'Starting password', name: 'password', type: 'password', optional: true, attrs: 'autocomplete="new-password" data-strength', hint: 'Share it privately; they can change it later.' })}
            </div></fieldset>
            <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">Create account</button><span class="d-form-msg">The profile is verified straight away.</span></div>
        </form>`;
    }

    Dash.route('/admin/onboard', {
        title: 'Onboard an account',
        roles: ADMIN,
        async render(ctx) {
            const musicians = core.list(await api.get('/admin/creator-profiles', { type: 'musician' }));
            ctx.view.innerHTML = `<p class="d-intro">One form for every account type. Choose the type and fill in the fields that appear.</p><div id="onboard-wrap">${onboardForm(ctx.meta, musicians)}</div>`;
            const form = ctx.view.querySelector('#onboard-form');
            const rooms = form.querySelector('[data-rooms]');
            const roomTemplate = rooms.innerHTML;
            const sync = () => {
                const type = form.type.value;
                form.querySelectorAll('[data-for]').forEach(group => {
                    const on = group.dataset.for.split(' ').includes(type);
                    group.querySelectorAll('input, select, textarea, button').forEach(el => { el.disabled = !on; });
                    group.hidden = !on;
                });
                form.querySelector('[data-name-label]').textContent = NAME_LABEL[type];
                form.querySelector('[name="display_name"]').dataset.label = NAME_LABEL[type];
                form.querySelector('[name="picture"]').dataset.label = PICTURE_LABEL[type];
                form.querySelector('[data-picture-label]').textContent = PICTURE_LABEL[type];
                const bio = form.querySelector('[data-bio-label]');
                if (bio) bio.textContent = BIO_LABEL[type];
                const genre = form.querySelector('[data-genre-label]');
                if (genre) genre.innerHTML = type === 'choir' ? 'Music styles <em>(optional)</em>' : type === 'producer' ? 'Genres they produce' : 'Genre';
                form.querySelectorAll('[name="genres"]').forEach(el => { el.required = type !== 'choir'; });
            };
            form.addEventListener('change', e => { if (e.target.name === 'type') { sync(); ui.showErrors(form, null); } });
            form.addEventListener('input', e => {
                if (e.target.matches('[data-picker-search]')) {
                    const q = e.target.value.trim().toLowerCase();
                    form.querySelectorAll('[data-picker-name]').forEach(l => { l.hidden = q && !l.dataset.pickerName.includes(q); });
                }
            });
            form.addEventListener('click', e => {
                if (e.target.closest('[data-add-room]')) {
                    const i = rooms.querySelectorAll('[data-room]').length;
                    if (i >= 10) return core.toast('A studio can list up to 10 rooms here.', 'error');
                    rooms.insertAdjacentHTML('beforeend', roomTemplate.replace(/rooms\.0\./g, `rooms.${i}.`));
                }
                const rm = e.target.closest('[data-remove-room]');
                if (rm) {
                    if (rooms.querySelectorAll('[data-room]').length === 1) return core.toast('A studio needs at least one room.', 'error');
                    rm.closest('[data-room]').remove();
                    rooms.querySelectorAll('[data-room]').forEach((row, i) => row.querySelectorAll('[data-field]').forEach(f => { f.dataset.field = f.dataset.field.replace(/rooms\.\d+\./, `rooms.${i}.`); }));
                }
            });
            sync();
            core.attachStrength(form.querySelector('[name="password"]'));
            ui.onSubmit(form, async f => {
                const type = f.type.value;
                const fd = media.formData(f);
                if (type === 'studio') {
                    fd.set('rooms', JSON.stringify(Array.from(rooms.querySelectorAll('[data-room]')).map(row => ({
                        name: row.querySelector('[data-room-key="name"]').value.trim(),
                        hourly_rate: Number(row.querySelector('[data-room-key="hourly_rate"]').value || 0),
                        min_hours: Number(row.querySelector('[data-room-key="min_hours"]').value || 1),
                        engineer_included: row.querySelector('[data-room-key="engineer_included"]').checked
                    }))));
                    fd.set('backup_power', f.querySelector('[name="backup_power"]').checked ? '1' : '0');
                }
                if (type === 'manager') {
                    fd.set('managed_artists', JSON.stringify(Array.from(f.querySelectorAll('[data-managed]:checked')).map(el => Number(el.value))));
                }
                const res = await api.upload('/admin/onboard', fd);
                const name = fd.get('display_name');
                const label = TYPES.find(t => t[0] === type)[1];
                const next = {
                    artist: [`#/admin/upload?type=song&owner=${res.user_id}`, 'Upload a song for them'],
                    choir: [`#/admin/upload?type=song&owner=${res.user_id}`, 'Upload a song for them'],
                    producer: [`#/admin/upload?type=beat&owner=${res.user_id}`, 'Upload a beat for them'],
                    studio: ['#/admin/studios', 'See the studio'],
                    manager: ['#/admin/people?type=manager', 'See managers'],
                    vocal_coach: ['#/admin/people?type=vocal_coach', 'See vocal coaches']
                }[type];
                ctx.view.querySelector('#onboard-wrap').innerHTML = `<div class="d-done" role="status"><h2>${esc(name)} is onboarded</h2><p>${esc(label)} account created and verified. Username: <strong>${esc(res.username || '')}</strong>. ${fd.get('password') ? 'They sign in with their email or username and the password you set, plus a code sent to their email.' : 'We emailed them how to set their password (Forgot password on the sign-in page).'}</p><div class="d-btn-row"><a class="d-btn d-btn--primary" href="${next[0]}">${next[1]}</a><button class="d-btn" type="button" data-again>Onboard another account</button></div></div>`;
                ctx.view.querySelector('[data-again]').addEventListener('click', () => Dash.refresh());
                core.toast(`${name} was onboarded.`);
            });
        }
    });

    /* ================================================================ upload for someone */
    Dash.route('/admin/upload', {
        title: 'Upload music or beats',
        roles: ADMIN,
        async render(ctx) {
            const kind = ctx.params.get('type') === 'beat' ? 'beat' : 'song';
            const owner = ctx.params.get('owner') || '';
            const ownerList = await media.owners(kind);
            ctx.view.innerHTML = `
                <div class="d-toolbar"><div class="d-segment" role="group" aria-label="What are you uploading">
                    <a href="${esc(ctx.link({ type: 'song' }))}" aria-current="${kind === 'song'}">Music (song)</a><a href="${esc(ctx.link({ type: 'beat' }))}" aria-current="${kind === 'beat'}">Beat</a>
                </div><span class="d-spacer"></span><a class="d-btn d-btn--ghost" href="#/admin/review">Open the review queue</a></div>
                <form class="d-form" id="upload-form" data-mode="create" novalidate>
                    ${kind === 'beat' ? media.beatFields({ meta: ctx.meta, admin: true, ownerList, owner }) : media.songFields({ meta: ctx.meta, admin: true, ownerList, owner })}
                    <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">Upload ${kind}</button><span class="d-form-msg" data-msg></span></div>
                </form>`;
            const form = ctx.view.querySelector('#upload-form');
            ui.onSubmit(form, async f => {
                f.querySelector('[data-msg]').textContent = 'Uploading… large files can take a minute.';
                try {
                    const item = kind === 'beat' ? await media.createBeat(f) : await media.createSong(f);
                    const live = item.status === 'live';
                    core.toast(`"${item.title}" ${live ? 'is live' : item.status === 'processing' ? 'is being processed' : 'is in the review queue'}.`);
                    f.reset();
                    f.querySelector('[data-msg]').innerHTML = `Uploaded "${esc(item.title)}". ${live ? `<a href="${core.ROOT}${kind === 'beat' ? 'beat' : 'track'}.html?id=${item.id}">View it on the site</a>` : '<a href="#/admin/review">Open the review queue</a>'}`;
                    Dash.recount();
                } catch (error) {
                    f.querySelector('[data-msg]').textContent = '';
                    throw error;
                }
            }, { title: media.UPLOAD_TITLES[kind], check: kind === 'beat' ? media.tierProblems : null });
        }
    });

    /* ================================================================ review queue */
    Dash.route('/admin/review', {
        title: 'Review queue',
        roles: ADMIN,
        async render(ctx) {
            const tab = ctx.params.get('tab') || 'songs';
            const [songs, beats, studios, queue] = await Promise.all([
                api.get('/admin/tracks', { status: 'in_review', per_page: 50 }),
                api.get('/admin/beats', { status: 'in_review', per_page: 50 }),
                api.get('/admin/studios', { status: 'pending', per_page: 50 }),
                api.get('/admin/queue')
            ]);
            const lists = { songs: core.list(songs), beats: core.list(beats), studios: core.list(studios), profiles: queue.creators || [] };
            const tabs = ui.tabs([
                { label: 'Songs', href: ctx.link({ tab: 'songs' }), current: tab === 'songs', count: lists.songs.length },
                { label: 'Beats', href: ctx.link({ tab: 'beats' }), current: tab === 'beats', count: lists.beats.length },
                { label: 'Studios', href: ctx.link({ tab: 'studios' }), current: tab === 'studios', count: lists.studios.length },
                { label: 'Profiles to verify', href: ctx.link({ tab: 'profiles' }), current: tab === 'profiles', count: lists.profiles.length }
            ]);
            const intro = '<p class="d-intro">Nothing here is public. Approve to publish it, or reject with a reason the creator can act on.</p>';
            let body;
            if (tab === 'songs' || tab === 'beats') {
                const kind = tab === 'beats' ? 'beat' : 'song';
                const rows = lists[tab];
                body = rows.length ? `<div class="d-cards">${rows.map(item => reviewCard(kind, item)).join('')}</div>` : ui.panel({ body: ui.empty({ title: `No ${tab} waiting`, text: 'New uploads from creators appear here.' }) });
                ctx.view.innerHTML = tabs + intro + body;
                media.bind(ctx.view, kind, rows, { admin: true });
                return;
            }
            if (tab === 'studios') {
                const rows = lists.studios;
                body = rows.length ? `<div class="d-cards">${rows.map(studioCard).join('')}</div>` : ui.panel({ body: ui.empty({ title: 'No studios waiting', text: 'Studios listed by their owners appear here before they go public.' }) });
                ctx.view.innerHTML = tabs + intro + body;
                bindStudioActions(ctx.view, rows);
                return;
            }
            const rows = lists.profiles;
            ctx.view.innerHTML = tabs + '<p class="d-intro">Profiles created by creators themselves. Verified profiles get a badge on the site.</p>' + ui.panel({ flush: true, body: ui.table({
                columns: [
                    { label: 'Profile', main: true, cell: p => `<div class="d-cell-title">${esc(p.stage_name)}</div><div class="d-cell-sub">${esc(p.city || '')}</div>` },
                    { label: 'Type', cell: p => esc(ui.PROFILE_TYPES[p.type] || p.type) },
                    { label: 'Phone', cell: p => esc(p.phone || '') },
                    { label: 'Created', cell: p => esc(date(p.created_at)) },
                    { label: '', actions: true, cell: p => `<button class="d-btn d-btn--sm d-btn--good" type="button" data-verify="${esc(p.id)}">Verify</button>` }
                ], rows, empty: ui.empty({ title: 'Every profile is verified' })
            }) });
            ctx.view.addEventListener('click', e => {
                const b = e.target.closest('[data-verify]');
                if (b) ui.act(b, () => api.post(`/admin/creators/${b.dataset.verify}/verify`, { verified: true }), 'Profile verified.');
            });
        }
    });

    function reviewCard(kind, item) {
        const owner = kind === 'beat' ? item.producer : item.creator;
        const facts = kind === 'beat'
            ? [['Producer', owner && owner.stage_name], ['Genre', item.genre], ['BPM / key', [item.bpm, item.key].filter(Boolean).join(' / ')], ['Licences', (item.licenses || []).filter(l => l.active).map(l => `${l.name} ${money(l.price)}`).join(', ')], ['Release date', date(item.release_date)], ['Submitted', ago(item.submitted_at || item.created_at)]]
            : [['Artist', owner && owner.stage_name], ['Genre', item.genre], ['Language', item.language], ['Price', Number(item.price) > 0 ? money(item.price) : 'Stream only'], ['Release date', date(item.release_date)], ['Submitted', ago(item.submitted_at || item.created_at)]];
        return `<article class="d-card"><div class="d-card-head">${ui.thumb(item.cover_url, item.title)}<div><h3>${esc(item.title)}</h3><p class="d-cell-sub">${esc(owner && owner.stage_name || '')}</p></div></div>
            ${ui.audio(item.listen_url)}
            <dl>${facts.filter(([, v]) => v).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>
            ${item.description ? `<p class="d-desc">${esc(item.description)}</p>` : ''}
            <div class="d-btn-row"><button class="d-btn d-btn--good" type="button" data-media="approve" data-id="${esc(item.id)}">Approve and publish</button><button class="d-btn d-btn--bad" type="button" data-media="reject" data-id="${esc(item.id)}">Reject</button><button class="d-btn d-btn--ghost" type="button" data-media="edit" data-id="${esc(item.id)}">Edit</button></div></article>`;
    }

    function studioCard(s) {
        const rooms = s.rooms || [];
        return `<article class="d-card"><div class="d-card-head">${ui.thumb(s.cover_url, s.name)}<div><h3>${esc(s.name)}</h3><p class="d-cell-sub">${esc([s.city, s.address].filter(Boolean).join(', '))}</p></div><span style="margin-left:auto">${ui.badge(s.status)}</span></div>
            <dl><dt>Owner</dt><dd>${esc(s.owner_name || '')}</dd><dt>Phone</dt><dd>${esc(s.phone || 'Not given')}</dd><dt>Rooms</dt><dd>${rooms.length ? rooms.map(r => `${esc(r.name)} (${money(r.hourly_rate)}/h)`).join(', ') : '<span class="d-muted">No rooms yet</span>'}</dd><dt>Backup power</dt><dd>${s.backup_power ? 'Yes' : 'No'}</dd></dl>
            ${s.equipment ? `<p class="d-desc"><strong>Equipment:</strong> ${esc(s.equipment)}</p>` : ''}${s.description ? `<p class="d-desc">${esc(s.description)}</p>` : ''}
            ${s.review_note ? `<p class="d-note">Last review: ${esc(s.review_note)}</p>` : ''}
            <div class="d-btn-row"><button class="d-btn d-btn--good" type="button" data-studio="approve" data-id="${esc(s.id)}">Verify studio</button><button class="d-btn d-btn--bad" type="button" data-studio="reject" data-id="${esc(s.id)}">Reject</button></div></article>`;
    }

    function bindStudioActions(view, rows) {
        view.addEventListener('click', async e => {
            const b = e.target.closest('[data-studio]');
            if (!b) return;
            const s = rows.find(x => String(x.id) === b.dataset.id);
            if (!s) return;
            const action = b.dataset.studio;
            if (action === 'approve') return ui.act(b, () => api.post(`/admin/studios/${s.id}/review`, { decision: 'approve' }), `${s.name} is verified and open for bookings.`);
            if (action === 'reject') {
                const note = await ui.ask({ title: `Reject ${s.name}`, text: 'The owner sees this, fixes the listing and it comes back for review.', label: 'What needs to change', submit: 'Reject' });
                if (note) ui.act(b, () => api.post(`/admin/studios/${s.id}/review`, { decision: 'reject', note }), `${s.name} was sent back to the owner.`);
                return;
            }
            if (action === 'archive') {
                const ok = await ui.confirm({ title: `Archive ${s.name}?`, text: 'It disappears from the site and cannot take new bookings. Booking history is kept and you can restore it.', submit: 'Archive', tone: 'bad' });
                if (ok) ui.act(b, () => api.delete(`/admin/studios/${s.id}`), `${s.name} was archived.`);
                return;
            }
            if (action === 'restore') return ui.act(b, () => api.post(`/admin/studios/${s.id}/restore`), `${s.name} was restored.`);
            if (action === 'edit') return Dash.studioEditor && Dash.studioEditor(s);
        });
    }

    /* ================================================================ songs and beats */
    const STATUS_TABS = [['', 'All'], ['in_review', 'In review'], ['live', 'Live'], ['rejected', 'Rejected'], ['processing', 'Processing'], ['removed', 'Taken down']];
    function catalogRoute(kind) {
        const beat = kind === 'beat';
        return {
            title: beat ? 'Beats' : 'Songs',
            roles: ADMIN,
            async render(ctx) {
                const status = ctx.params.get('status') || '';
                const q = ctx.params.get('q') || '';
                const page = Number(ctx.params.get('page') || 1);
                const res = await api.get(beat ? '/admin/beats' : '/admin/tracks', { status, q, page, per_page: 50 });
                const rows = core.list(res);
                const meta = res.meta || {};
                ctx.setActions(`<a class="d-btn d-btn--primary" href="#/admin/upload?type=${beat ? 'beat' : 'song'}">Upload ${beat ? 'a beat' : 'a song'}</a>`);
                ctx.view.innerHTML = `
                    ${ui.tabs(STATUS_TABS.map(([v, l]) => ({ label: l, href: ctx.link({ status: v, page: '' }), current: status === v })))}
                    <form class="d-toolbar" data-search><input class="d-input" type="search" name="q" value="${esc(q)}" placeholder="Search by title or ${beat ? 'producer' : 'artist'}" style="max-width:340px"><button class="d-btn" type="submit">Search</button>${q ? `<a class="d-btn d-btn--ghost" href="${esc(ctx.link({ q: '', page: '' }))}">Clear</a>` : ''}<span class="d-spacer"></span><span class="d-muted">${num(meta.total ?? rows.length)} ${beat ? 'beats' : 'songs'}</span></form>
                    ${ui.panel({ flush: true, body: media.table(kind, rows, { admin: true, showOwner: true, engagement: true }), foot: pager(ctx, meta) })}`;
                ctx.view.querySelector('[data-search]').addEventListener('submit', e => { e.preventDefault(); location.hash = ctx.link({ q: e.currentTarget.q.value.trim(), page: '' }); });
                media.bind(ctx.view, kind, rows, { admin: true });
            }
        };
    }
    const pager = (ctx, meta) => {
        const pages = Number(meta.pages || 1), page = Number(meta.page || 1);
        if (pages <= 1) return '';
        return `<div class="d-btn-row" style="justify-content:space-between;align-items:center"><span class="d-muted">Page ${page} of ${pages}</span><span class="d-btn-row">${page > 1 ? `<a class="d-btn d-btn--sm" href="${esc(ctx.link({ page: page - 1 }))}">Previous</a>` : ''}${page < pages ? `<a class="d-btn d-btn--sm" href="${esc(ctx.link({ page: page + 1 }))}">Next</a>` : ''}</span></div>`;
    };
    Dash.route('/admin/music', catalogRoute('song'));
    Dash.route('/admin/beats', catalogRoute('beat'));

    /* ================================================================ studios */
    Dash.route('/admin/studios', {
        title: 'Studios',
        roles: ADMIN,
        async render(ctx) {
            const status = ctx.params.get('status') || '';
            const q = ctx.params.get('q') || '';
            const res = await api.get('/admin/studios', { status, q, per_page: 50 });
            const rows = core.list(res);
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/admin/onboard">Onboard a studio</a>');
            ctx.view.innerHTML = `
                ${ui.tabs([['', 'All'], ['pending', 'Waiting for review'], ['verified', 'Verified'], ['rejected', 'Rejected'], ['suspended', 'Suspended']].map(([v, l]) => ({ label: l, href: ctx.link({ status: v }), current: status === v })))}
                <form class="d-toolbar" data-search><input class="d-input" type="search" name="q" value="${esc(q)}" placeholder="Search by studio, city or owner" style="max-width:340px"><button class="d-btn" type="submit">Search</button></form>
                ${ui.panel({ flush: true, body: ui.table({
                    columns: [
                        { label: 'Studio', main: true, cell: s => `<div class="d-cell-media">${ui.thumb(s.cover_url, s.name)}<div><div class="d-cell-title">${esc(s.name)}</div><div class="d-cell-sub">${esc(s.city)}, owner ${esc(s.owner_name || '')}</div>${s.status === 'rejected' && s.review_note ? `<p class="d-note">${esc(s.review_note)}</p>` : ''}</div></div>` },
                        { label: 'Status', cell: s => ui.badge(s.is_archived ? 'removed' : s.status, s.is_archived ? 'Archived' : '') },
                        { label: 'Rooms', num: true, cell: s => num(s.room_count) },
                        { label: 'From', num: true, cell: s => s.price_from ? `${money(s.price_from)}/h` : '<span class="d-muted">No rooms</span>' },
                        { label: 'Bookings', num: true, cell: s => num(s.booking_count) },
                        { label: '', actions: true, cell: s => `<div class="d-btn-row">${['pending', 'rejected'].includes(s.status) && !s.is_archived ? `<button class="d-btn d-btn--sm d-btn--good" type="button" data-studio="approve" data-id="${s.id}">Verify</button>` : ''}${s.status === 'pending' ? `<button class="d-btn d-btn--sm d-btn--bad" type="button" data-studio="reject" data-id="${s.id}">Reject</button>` : ''}<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-studio="edit" data-id="${s.id}">Edit</button>${s.is_archived ? `<button class="d-btn d-btn--sm" type="button" data-studio="restore" data-id="${s.id}">Restore</button>` : `<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-studio="archive" data-id="${s.id}">Archive</button>`}</div>` }
                    ], rows, empty: ui.empty({ title: 'No studios match this filter' })
                }) })}`;
            ctx.view.querySelector('[data-search]').addEventListener('submit', e => { e.preventDefault(); location.hash = ctx.link({ q: e.currentTarget.q.value.trim() }); });
            bindStudioActions(ctx.view, rows);
        }
    });

    /* ================================================================ bookings */
    Dash.route('/admin/bookings', {
        title: 'Studio bookings',
        roles: ADMIN,
        async render(ctx) {
            const status = ctx.params.get('status') || '';
            const page = Number(ctx.params.get('page') || 1);
            const res = await api.get('/admin/bookings', { status, page, per_page: 30 });
            const rows = core.list(res);
            ctx.view.innerHTML = `${ui.tabs([['', 'All'], ['pending', 'Waiting for studio'], ['confirmed', 'Confirmed'], ['completed', 'Completed'], ['rejected', 'Declined'], ['cancelled', 'Cancelled'], ['no_show', 'No-show']].map(([v, l]) => ({ label: l, href: ctx.link({ status: v, page: '' }), current: status === v })))}
                <p class="d-intro">Deposits stay in escrow until the session is completed. Requests the studio does not answer within ${esc(ctx.meta.booking_response_hours || 48)} hours are declined automatically and refunded.</p>
                ${ui.panel({ flush: true, body: bookingTable(rows), foot: pager(ctx, res.meta || {}) })}`;
        }
    });

    /* ================================================================ people */
    Dash.route('/admin/people', {
        title: 'People',
        roles: ADMIN,
        async render(ctx) {
            const tab = ctx.params.get('tab') || 'profiles';
            const type = ctx.params.get('type') || 'all';
            const q = ctx.params.get('q') || '';
            const tabs = ui.tabs([
                { label: 'Creator profiles', href: ctx.link({ tab: 'profiles', role: '' }), current: tab === 'profiles' },
                { label: 'All accounts', href: ctx.link({ tab: 'accounts', type: '' }), current: tab === 'accounts' }
            ]);
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/admin/onboard">Onboard an account</a>');
            if (tab === 'profiles') {
                const rows = core.list(await api.get('/admin/creator-profiles', { type, q }));
                const types = [['all', 'Everyone'], ['artist', 'Artists'], ['choir', 'Choirs'], ['producer', 'Producers'], ['manager', 'Managers'], ['vocal_coach', 'Vocal coaches']];
                ctx.view.innerHTML = `${tabs}<div class="d-toolbar"><div class="d-segment" role="group" aria-label="Profile type">${types.map(([v, l]) => `<a href="${esc(ctx.link({ type: v }))}" aria-current="${type === v}">${l}</a>`).join('')}</div>
                    <form data-search class="d-btn-row"><input class="d-input" type="search" name="q" value="${esc(q)}" placeholder="Name, phone or email"><button class="d-btn" type="submit">Search</button></form></div>
                    ${ui.panel({ flush: true, body: ui.table({
                        columns: [
                            { label: 'Profile', main: true, cell: p => `<div class="d-cell-media">${ui.thumb(p.avatar_url, p.stage_name)}<div><div class="d-cell-title">${esc(p.stage_name)}${p.verified ? ' <span class="d-badge d-badge--good">Verified</span>' : ''}</div><div class="d-cell-sub">${esc(profileFacts(p))}</div></div></div>` },
                            { label: 'Type', cell: p => esc(ui.PROFILE_TYPES[p.type] || p.type) },
                            { label: 'Contact', cell: p => `${esc(p.contact_phone || p.account.phone || '')}<div class="d-cell-sub">${esc(p.contact_email || p.account.email || '')}</div>` },
                            { label: 'Account', cell: p => ui.badge(p.account.status) },
                            { label: 'Joined', cell: p => esc(date(p.created_at)) },
                            { label: '', actions: true, cell: p => p.type === 'manager'
                                ? `<button class="d-btn d-btn--sm" type="button" data-manager="${p.id}">Linked artists (${num((p.managed_artists || []).length)})</button>`
                                : p.slug && ['artist', 'choir', 'producer', 'both'].includes(p.type) ? `<a class="d-btn d-btn--sm d-btn--ghost" href="${core.ROOT}creator.html?slug=${encodeURIComponent(p.slug)}" target="_blank" rel="noopener">Public page</a>` : '' }
                        ], rows, empty: ui.empty({ title: 'No profiles found', action: '<a class="d-btn d-btn--primary" href="#/admin/onboard">Onboard someone</a>' })
                    }) })}`;
                ctx.view.addEventListener('click', async e => {
                    const b = e.target.closest('[data-manager]');
                    if (!b) return;
                    const manager = rows.find(x => String(x.id) === b.dataset.manager);
                    const musicians = core.list(await api.get('/admin/creator-profiles', { type: 'musician' }));
                    const linked = new Set((manager.managed_artists || []).map(String));
                    ui.formModal({
                        title: `Artists managed by ${manager.stage_name}`,
                        submit: 'Save links',
                        intro: 'The manager sees releases, streams and earnings for these artists and choirs.',
                        fields: `<div class="d-picker" data-field="artist_ids"><div class="d-picker-list">${musicians.map(m => `<label><input type="checkbox" value="${esc(m.id)}"${linked.has(String(m.id)) ? ' checked' : ''}> ${esc(m.stage_name)} <span class="d-muted">${esc(ui.PROFILE_TYPES[m.type] || m.type)}</span></label>`).join('') || '<p class="d-muted">No artists or choirs yet.</p>'}</div></div>`,
                        async onSubmit(form, close) {
                            const ids = Array.from(form.querySelectorAll('input[type=checkbox]:checked')).map(el => Number(el.value));
                            await api.put(`/admin/managers/${manager.id}/artists`, { artist_ids: ids });
                            close();
                            core.toast(`${manager.stage_name} now manages ${ids.length} artist${ids.length === 1 ? '' : 's'}.`);
                            Dash.refresh();
                        }
                    });
                });
            } else {
                const role = ctx.params.get('role') || '';
                const res = await api.get('/admin/users', { q, role, per_page: 50 });
                const rows = core.list(res);
                const roles = [['', 'Any role'], ['fan', 'Listeners'], ['artist', 'Artists'], ['choir', 'Choirs'], ['producer', 'Producers'], ['studio_owner', 'Studio owners'], ['manager', 'Managers'], ['vocal_coach', 'Vocal coaches'], ['admin', 'Admins']];
                ctx.view.innerHTML = `${tabs}<form data-search class="d-toolbar"><input class="d-input" type="search" name="q" value="${esc(q)}" placeholder="Name, phone or email" style="max-width:300px"><select class="d-input" name="role" style="max-width:200px">${roles.map(([v, l]) => `<option value="${v}"${v === role ? ' selected' : ''}>${l}</option>`).join('')}</select><button class="d-btn" type="submit">Search</button><span class="d-spacer"></span><span class="d-muted">${num((res.meta || {}).total ?? rows.length)} accounts</span></form>
                    ${ui.panel({ flush: true, body: ui.table({
                        columns: [
                            { label: 'Name', main: true, cell: u => `<div class="d-cell-title">${esc(u.name || 'Unnamed')}</div><div class="d-cell-sub">${esc([u.email, u.phone].filter(Boolean).join(', '))}</div>` },
                            { label: 'Roles', cell: u => esc(roleLabel(u.roles)) },
                            { label: 'Status', cell: u => ui.badge(u.status || 'active') },
                            { label: 'Joined', cell: u => esc(date(u.created_at)) },
                            { label: '', actions: true, cell: u => u.roles && u.roles.includes('admin') ? '' : `<button class="d-btn d-btn--sm ${u.status === 'suspended' ? '' : 'd-btn--bad'}" type="button" data-user="${u.id}" data-next="${u.status === 'suspended' ? 'active' : 'suspended'}">${u.status === 'suspended' ? 'Reactivate' : 'Suspend'}</button>` }
                        ], rows, empty: ui.empty({ title: 'No accounts found' })
                    }), foot: pager(ctx, res.meta || {}) })}`;
                ctx.view.addEventListener('click', async e => {
                    const b = e.target.closest('[data-user]');
                    if (!b) return;
                    const suspend = b.dataset.next === 'suspended';
                    if (suspend && !(await ui.confirm({ title: 'Suspend this account?', text: 'They are signed out and cannot sign in until you reactivate them.', submit: 'Suspend', tone: 'bad' }))) return;
                    ui.act(b, () => api.patch(`/admin/users/${b.dataset.user}`, { status: b.dataset.next }), suspend ? 'Account suspended.' : 'Account reactivated.');
                });
            }
            ctx.view.querySelector('[data-search]').addEventListener('submit', e => {
                e.preventDefault();
                const f = e.currentTarget;
                location.hash = ctx.link({ q: f.q.value.trim(), role: f.role ? f.role.value : '' });
            });
        }
    });
    const profileFacts = p => {
        const bits = [p.city];
        if (p.genres && p.genres.length) bits.push(p.genres.join(', '));
        if (p.members_count) bits.push(`${p.members_count} members`);
        if (p.specialization) bits.push(p.specialization);
        if (p.experience_years != null && p.type === 'vocal_coach') bits.push(`${p.experience_years} years`);
        if (p.company) bits.push(p.company);
        return bits.filter(Boolean).join(', ');
    };

    /* ================================================================ withdrawals */
    Dash.route('/admin/payouts', {
        title: 'Withdrawals',
        roles: ADMIN,
        async render(ctx) {
            const status = ctx.params.get('status') || 'requested';
            const res = await api.get('/admin/payouts', { status });
            const rows = core.list(res);
            const manual = res.mode === 'manual';
            const phone = p => { const d = String(p || ''); return d.startsWith('233') && d.length === 12 ? `0${d.slice(3, 5)} ${d.slice(5, 8)} ${d.slice(8)}` : (d ? `+${d}` : ''); };
            const network = code => (ctx.meta.mobile_money_providers || []).find(x => x.code === code)?.name || code || '';
            ctx.view.innerHTML = `${ui.tabs([['requested', 'Requested'], ['processing', 'Sending'], ['completed', 'Sent'], ['failed', 'Failed'], ['rejected', 'Rejected']].map(([v, l]) => ({ label: l, href: ctx.link({ status: v }), current: status === v })))}
                <p class="d-intro">The amount is taken from the creator's wallet when they ask. ${manual
                    ? 'Automatic Paystack transfers are off: send the mobile money yourself, then choose <strong>Mark as sent</strong> and type the transaction reference. Rejecting puts the money back in their wallet.'
                    : 'Approving sends it to their mobile money with Paystack Transfers; rejecting puts it back in their wallet.'}</p>
                ${ui.panel({ flush: true, body: ui.table({
                    columns: [
                        { label: 'Creator', main: true, cell: p => `<div class="d-cell-title">${esc(p.user.name)}</div><div class="d-cell-sub">Account ${esc(phone(p.user.phone))}</div>` },
                        { label: 'Amount', num: true, cell: p => `<strong>${money(p.amount)}</strong>` },
                        { label: 'Send to', cell: p => `${esc(phone(p.phone))}<div class="d-cell-sub">${esc(network(p.provider))}</div>` },
                        { label: 'Wallet after', num: true, cell: p => money(p.balance_after) },
                        { label: 'Requested', cell: p => esc(dateTime(p.created_at)) },
                        { label: 'Status', cell: p => `${ui.badge(p.status === 'processing' ? 'processing' : p.status === 'completed' ? 'paid' : p.status, p.status === 'completed' ? 'Sent' : '')}${p.failure_reason ? `<div class="d-cell-sub">${esc(p.failure_reason)}</div>` : ''}${p.sent_note ? `<div class="d-cell-sub">Ref: ${esc(p.sent_note)}</div>` : ''}` },
                        { label: '', actions: true, cell: p => ['requested', 'processing'].includes(p.status) ? `<div class="d-btn-row">${p.status === 'requested' && !manual ? `<button class="d-btn d-btn--sm d-btn--good" type="button" data-payout="approve" data-id="${p.id}">Approve and send</button>` : ''}<button class="d-btn d-btn--sm${manual ? ' d-btn--good' : ' d-btn--ghost'}" type="button" data-payout="sent" data-id="${p.id}">Mark as sent</button>${p.status === 'requested' ? `<button class="d-btn d-btn--sm d-btn--bad" type="button" data-payout="reject" data-id="${p.id}">Reject</button>` : ''}</div>` : '' }
                    ], rows, empty: ui.empty({ title: status === 'requested' ? 'No withdrawals waiting' : 'Nothing in this list' })
                }) })}`;
            ctx.view.addEventListener('click', async e => {
                const b = e.target.closest('[data-payout]');
                if (!b) return;
                const p = rows.find(x => String(x.id) === b.dataset.id);
                if (b.dataset.payout === 'approve') {
                    if (await ui.confirm({ title: `Send ${money(p.amount)} to ${p.user.name}?`, text: `Paystack transfer to ${esc(phone(p.phone))} (${esc(network(p.provider))}).`, submit: 'Send money' })) ui.act(b, () => api.post(`/admin/payouts/${p.id}/approve`), 'Withdrawal approved and sent to Paystack.');
                    return;
                }
                if (b.dataset.payout === 'sent') {
                    const note = await ui.ask({ title: `Mark ${money(p.amount)} to ${p.user.name} as sent`, text: `Only after you have sent ${money(p.amount)} to ${esc(phone(p.phone))} (${esc(network(p.provider))}).`, label: 'Transaction reference', submit: 'Mark as sent', min: 3, placeholder: 'MoMo transaction ID or Paystack reference' });
                    if (note) ui.act(b, () => api.post(`/admin/payouts/${p.id}/mark-sent`, { note }), 'Withdrawal recorded as sent.');
                    return;
                }
                const reason = await ui.ask({ title: 'Reject withdrawal', text: 'The money goes back to their wallet.', label: 'Reason', submit: 'Reject', min: 3 });
                if (reason) ui.act(b, () => api.post(`/admin/payouts/${p.id}/reject`, { reason }), 'Withdrawal rejected; the money is back in their wallet.');
            });
        }
    });

    /* ================================================================ Q&A */
    Dash.route('/admin/questions', {
        title: 'Questions (Q&A)',
        roles: ADMIN,
        async render(ctx) {
            const status = ctx.params.get('status') || 'open';
            const q = ctx.params.get('q') || '';
            const res = await api.get('/admin/questions', { status, q, per_page: 50 });
            const rows = core.list(res);
            const counts = res.counts || {};
            ctx.setCount('questions', counts.open || 0);
            ctx.view.innerHTML = `${ui.tabs([['open', 'Unanswered', counts.open], ['answered', 'Answered', 0], ['closed', 'Closed', 0], ['all', 'All', 0]].map(([v, l, c]) => ({ label: l, href: ctx.link({ status: v, q: '' }), current: status === v, count: c })))}
                <form data-search class="d-toolbar"><input class="d-input" type="search" name="q" value="${esc(q)}" placeholder="Subject, name, email or reference" style="max-width:320px"><button class="d-btn" type="submit">Search</button><span class="d-spacer"></span><a class="d-btn d-btn--ghost" href="${core.ROOT}q-and-a.html" target="_blank" rel="noopener">Open the Q&amp;A page</a></form>
                ${ui.panel({ flush: true, body: ui.table({
                    columns: [
                        { label: 'Question', main: true, cell: x => `<div class="d-cell-title">${esc(x.subject)}</div><div class="d-cell-sub">${esc(x.category_label)} · ${esc(x.reference)}</div>` },
                        { label: 'From', cell: x => `${esc(x.name)}<div class="d-cell-sub">${esc(x.email)}${x.user ? ` · @${esc(x.user.username || '')}` : ' · guest'}</div>` },
                        { label: 'Sent', cell: x => `${esc(dateTime(x.created_at))}<div class="d-cell-sub">${esc(ago(x.created_at))}</div>` },
                        { label: 'Status', cell: x => `${ui.badge(x.status, x.status === 'open' ? 'Unanswered' : x.status === 'answered' ? 'Answered' : 'Closed')}${x.is_public ? '<div class="d-cell-sub">On the Q&amp;A page</div>' : ''}` },
                        { label: '', actions: true, cell: x => `<button class="d-btn d-btn--sm${x.status === 'open' ? ' d-btn--primary' : ''}" type="button" data-question="${x.id}">${x.status === 'open' ? 'Answer' : 'Open'}</button>` }
                    ], rows, empty: ui.empty({ title: status === 'open' ? 'No unanswered questions' : 'No questions here', text: status === 'open' ? 'New questions from the Q&A page appear here.' : '' })
                }) })}`;
            ctx.view.querySelector('[data-search]').addEventListener('submit', e => { e.preventDefault(); location.hash = ctx.link({ q: e.target.q.value.trim() }); });
            ctx.view.addEventListener('click', e => {
                const b = e.target.closest('[data-question]');
                if (!b) return;
                const x = rows.find(r => String(r.id) === b.dataset.question);
                const m = ui.formModal({
                    title: x.subject,
                    submit: x.answer ? 'Update answer' : 'Send answer',
                    intro: `<span class="d-muted">${esc(x.name)} · ${esc(x.email)}${x.phone ? ` · ${esc(x.phone)}` : ''} · ${esc(x.category_label)} · ${esc(dateTime(x.created_at))} · ${esc(x.reference)}</span>`,
                    fields: `<div class="d-qa-q">${esc(x.body)}</div>
                        ${ui.field({ label: 'Your answer', name: 'answer', type: 'textarea', value: x.answer || '', required: true, wide: true, attrs: 'minlength="10" maxlength="5000" rows="7"', missing: 'Write an answer before sending.', hint: 'Emailed to the person and shown in their account.' })}
                        ${ui.field({ label: 'Also show this question and answer on the public Q&A page', name: 'is_public', type: 'checkbox', value: x.is_public })}
                        ${x.status !== 'closed' ? `<button class="d-btn d-btn--ghost d-btn--sm" type="button" data-close-question>Close without answering</button>` : `<button class="d-btn d-btn--ghost d-btn--sm" type="button" data-reopen-question>Reopen</button>`}`,
                    async onSubmit(form, close) {
                        const r = await api.post(`/admin/questions/${x.id}/answer`, { answer: form.answer.value.trim(), is_public: form.is_public.checked });
                        close();
                        core.toast(r.emailed ? `Answer sent to ${x.email}.` : 'Answer saved, but the email could not be sent. Check the mail settings.', r.emailed ? 'success' : 'error');
                        Dash.refresh();
                    }
                });
                m.dialog.addEventListener('click', async ev => {
                    const closeBtn = ev.target.closest('[data-close-question], [data-reopen-question]');
                    if (!closeBtn) return;
                    await ui.act(closeBtn, () => api.patch(`/admin/questions/${x.id}`, { status: closeBtn.matches('[data-close-question]') ? 'closed' : 'open' }), 'Question updated.');
                    m.close();
                });
            });
        }
    });

    /* ================================================================ featured Spotify music */
    Dash.route('/admin/spotify', {
        title: 'Featured on Spotify',
        roles: ADMIN,
        async render(ctx) {
            const rows = core.list(await api.get('/admin/featured-spotify'));
            ctx.view.innerHTML = `<p class="d-intro">Songs, albums and playlists from Spotify shown on the home and Music pages. They play in Spotify's player: 30-second previews for visitors, full songs for people signed in to Spotify in their browser.</p>
                ${ui.panel({ title: 'Add from Spotify', body: `<form class="d-form" id="spotify-form" data-error-title="This could not be added:" novalidate><div class="d-fields">
                    ${ui.field({ label: 'Spotify link', name: 'link', required: true, wide: true, missing: 'Paste a Spotify link.', placeholder: 'https://open.spotify.com/track/…', hint: 'In Spotify: Share → Copy link to song, album or playlist.' })}
                    ${ui.field({ label: 'Title', name: 'title', optional: true, hint: 'Filled from Spotify automatically when left empty.' })}
                </div><div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">Add to featured</button></div></form>` })}
                ${ui.panel({ title: 'Featured now', flush: true, body: ui.table({
                    columns: [
                        { label: 'Music', main: true, cell: r => `<div class="d-cell-media">${ui.thumb(r.cover_url, r.title)}<div><div class="d-cell-title">${esc(r.title)}</div><div class="d-cell-sub">${esc(r.embed_type === 'track' ? 'Song' : r.embed_type === 'album' ? 'Album' : 'Playlist')} · <a href="${esc(r.spotify_url)}" target="_blank" rel="noopener">Open in Spotify</a></div></div></div>` },
                        { label: 'Order', num: true, cell: r => num(r.display_order) },
                        { label: 'Shown', cell: r => ui.badge(r.is_active ? 'active' : 'inactive', r.is_active ? 'Shown' : 'Hidden') },
                        { label: '', actions: true, cell: r => `<div class="d-btn-row"><button class="d-btn d-btn--sm" type="button" data-sp="toggle" data-id="${r.id}">${r.is_active ? 'Hide' : 'Show'}</button><button class="d-btn d-btn--sm d-btn--ghost" type="button" data-sp="refresh" data-id="${r.id}">Refresh details</button><button class="d-btn d-btn--sm d-btn--ghost" type="button" data-sp="remove" data-id="${r.id}">Remove</button></div>` }
                    ], rows, empty: ui.empty({ title: 'Nothing featured yet', text: 'Paste a Spotify link above.' })
                }) })}`;
            ui.onSubmit(ctx.view.querySelector('#spotify-form'), async f => {
                const r = await api.post('/admin/featured-spotify', { link: f.link.value.trim(), title: f.title.value.trim() || null });
                core.toast(`"${r.title}" is now featured.`);
                Dash.refresh();
            });
            ctx.view.addEventListener('click', async e => {
                const b = e.target.closest('[data-sp]');
                if (!b) return;
                const r = rows.find(x => String(x.id) === b.dataset.id);
                if (b.dataset.sp === 'toggle') return ui.act(b, () => api.patch(`/admin/featured-spotify/${r.id}`, { is_active: !r.is_active }), r.is_active ? 'Hidden from the site.' : 'Shown on the site.');
                if (b.dataset.sp === 'refresh') return ui.act(b, () => api.patch(`/admin/featured-spotify/${r.id}`, { refresh: true }), 'Details refreshed from Spotify.');
                if (await ui.confirm({ title: `Remove "${r.title}"?`, submit: 'Remove', tone: 'bad' })) ui.act(b, () => api.delete(`/admin/featured-spotify/${r.id}`), 'Removed.');
            });
        }
    });

    Dash.admin = { bookingTable, bindStudioActions, roleLabel };
})();
