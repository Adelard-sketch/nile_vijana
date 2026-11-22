/*
 * Studio owner screens: overview, booking requests (accept / decline / complete),
 * rooms with prices, opening hours and blocked dates, and the studio profile.
 * Also provides Dash.studioEditor(), which admins use to edit any studio.
 * Data: GET /api/dashboard/studio and /api/studios/mine.
 */
(function () {
    'use strict';

    const { ui, media } = window.Dash;
    const { esc, num, money, date, dateTime, ago } = ui;
    const api = window.api;
    const core = window.NVCore;
    const OWNER = ['studio_owner'];
    const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /* ---------------------------------------------------------------- shared forms */
    function studioFields(s = {}) {
        return `<fieldset class="d-fieldset"><legend>Studio</legend><div class="d-fields">
            ${ui.field({ label: 'Studio name', name: 'name', value: s.name, required: true, attrs: 'maxlength="120"' })}
            ${ui.field({ label: 'City', name: 'city', value: s.city, required: true, attrs: 'maxlength="80"' })}
            ${ui.field({ label: 'Address', name: 'address', value: s.address, attrs: 'maxlength="255"', placeholder: 'Street and area, e.g. Oxford Street, Osu' })}
            ${ui.field({ label: 'Phone for bookings', name: 'phone', type: 'tel', value: s.phone, optional: true })}
            ${ui.field({ label: 'Description', name: 'description', type: 'textarea', value: s.description, wide: true, attrs: 'maxlength="3000"', placeholder: 'What kind of sessions you do, who works there, what makes the studio special.' })}
            ${ui.field({ label: 'Facilities and equipment', name: 'equipment', type: 'textarea', value: s.equipment, wide: true, attrs: 'maxlength="3000"', placeholder: 'Microphones, interface, monitors, software, instruments' })}
            ${ui.field({ label: 'Amenities', name: 'amenities', value: s.amenities, optional: true, attrs: 'maxlength="1000"', placeholder: 'Wi-Fi, parking, lounge' })}
            ${ui.field({ label: 'Backup power (generator or inverter)', name: 'backup_power', type: 'checkbox', value: s.backup_power })}
        </div></fieldset>`;
    }
    const studioValues = form => {
        const v = media.values(form, ['address', 'phone', 'description', 'equipment', 'amenities']);
        v.backup_power = form.querySelector('[name="backup_power"]').checked;
        delete v.cover;
        return v;
    };
    function roomFields(r = {}) {
        return `<div class="d-fields">
            ${ui.field({ label: 'Room name', name: 'name', value: r.name, required: true, attrs: 'maxlength="120"' })}
            ${ui.field({ label: 'Price per hour (GH₵)', name: 'hourly_rate', type: 'number', value: r.hourly_rate, required: true, missing: 'Hourly price is missing.', placeholder: '150', attrs: `min="${Number((Dash.state.meta || {}).min_room_rate || 20)}" max="${Number((Dash.state.meta || {}).max_room_rate || 10000)}" step="1"`, hint: 'Typical Accra rooms charge GH₵ 100–300 per hour.' })}
            ${ui.field({ label: 'Minimum booking (hours)', name: 'min_hours', type: 'number', value: r.min_hours || 1, attrs: 'min="1" max="12"' })}
            ${r.id ? ui.field({ label: 'Status', name: 'status', value: r.status || 'active', options: [['active', 'Open for bookings'], ['inactive', 'Closed (hidden from artists)']] }) : ''}
            ${ui.field({ label: 'Description', name: 'description', type: 'textarea', value: r.description, wide: true, optional: true, attrs: 'maxlength="2000"' })}
            ${ui.field({ label: 'Sound engineer included', name: 'engineer_included', type: 'checkbox', value: r.engineer_included })}
        </div>`;
    }
    const roomValues = form => {
        const v = media.values(form, ['description']);
        v.hourly_rate = Number(v.hourly_rate);
        v.min_hours = Number(v.min_hours || 1);
        v.engineer_included = form.querySelector('[name="engineer_included"]').checked;
        return v;
    };

    /** Admin (and owner) editor for a studio and its rooms. */
    Dash.studioEditor = studio => {
        const rooms = studio.rooms || [];
        ui.formModal({
            title: `Edit ${studio.name}`,
            submit: 'Save studio',
            fields: studioFields(studio)
                + `<fieldset class="d-fieldset"><legend>Cover photo</legend>${ui.field({ label: 'Replace the cover photo', name: 'cover', type: 'file', maxMb: Number(((Dash.state.meta || {}).uploads || {}).max_image_mb || 5), attrs: 'accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"', optional: true })}</fieldset>`
                + (rooms.length ? `<fieldset class="d-fieldset"><legend>Rooms and prices</legend><div class="d-rows">${rooms.map(r => `<div class="d-row" data-room-id="${r.id}" style="grid-template-columns:2fr 1fr 1fr 1fr">
                    <label class="d-field"><span>Room</span><input data-k="name" value="${esc(r.name)}" maxlength="120" required></label>
                    <label class="d-field"><span>Per hour (GH₵)</span><input data-k="hourly_rate" type="number" min="${Number((Dash.state.meta || {}).min_room_rate || 20)}" step="1" value="${esc(r.hourly_rate)}"></label>
                    <label class="d-field"><span>Min. hours</span><input data-k="min_hours" type="number" min="1" max="12" value="${esc(r.min_hours)}"></label>
                    <label class="d-field"><span>Status</span><select data-k="status"><option value="active"${r.status === 'active' ? ' selected' : ''}>Open</option><option value="inactive"${r.status !== 'active' ? ' selected' : ''}>Closed</option></select></label></div>`).join('')}</div></fieldset>` : ''),
            async onSubmit(form, close) {
                await api.patch(`/studios/${studio.id}`, studioValues(form));
                for (const row of form.querySelectorAll('[data-room-id]')) {
                    const original = rooms.find(r => String(r.id) === row.dataset.roomId);
                    const v = { name: row.querySelector('[data-k="name"]').value.trim(), hourly_rate: Number(row.querySelector('[data-k="hourly_rate"]').value), min_hours: Number(row.querySelector('[data-k="min_hours"]').value), status: row.querySelector('[data-k="status"]').value };
                    if (original.name !== v.name || Number(original.hourly_rate) !== v.hourly_rate || Number(original.min_hours) !== v.min_hours || original.status !== v.status) {
                        await api.patch(`/rooms/${original.id}`, v);
                    }
                }
                const cover = form.querySelector('[name="cover"]').files[0];
                if (cover) {
                    const fd = new FormData();
                    fd.append('cover', cover);
                    await api.upload(`/studios/${studio.id}/cover`, fd);
                }
                close();
                core.toast('Studio saved.');
                Dash.refresh();
            }
        });
    };

    function createStudioForm() {
        return `<form class="d-form" id="studio-create" novalidate>${studioFields()}
            <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">Create studio listing</button><span class="d-form-msg">An admin checks new studios before artists can book them. You can add rooms and prices right after.</span></div></form>`;
    }
    function bindCreate(ctx) {
        const form = ctx.view.querySelector('#studio-create');
        if (!form) return;
        ui.onSubmit(form, async f => {
            const v = studioValues(f);
            await api.post('/studios', v);
            core.toast(`${v.name} was created and sent for review. Now add your rooms.`);
            location.hash = '#/studio/rooms';
            await Dash.reloadMe();
        });
    }

    function statusNotice(s) {
        if (s.status === 'pending') return ui.notice({ tone: 'warn', title: `${s.name} is waiting for approval`, text: 'Artists cannot see or book it yet. Add rooms and prices now so it is ready when it is approved.' });
        if (s.status === 'rejected') return ui.notice({ tone: 'bad', title: `${s.name} needs changes before it can go public`, text: `<strong>Reviewer's note:</strong> ${esc(s.review_note || 'No reason given.')} Saving your changes sends it back for review.`, action: '<a class="d-btn" href="#/studio/profile">Edit the studio</a>' });
        if (s.status === 'suspended') return ui.notice({ tone: 'bad', title: `${s.name} is suspended`, text: 'Contact Nile Vijana support to find out why.' });
        return '';
    }

    /* ---------------------------------------------------------------- bookings table + actions */
    function bookingRows(rows, mode) {
        return ui.table({
            columns: [
                { label: 'Session', main: true, cell: b => `<div class="d-cell-title">${esc(dateTime(b.start_at))}</div><div class="d-cell-sub">${esc(b.room.name)}, ${esc(b.hours)} h, #${esc(b.id)}</div>${b.notes ? `<div class="d-cell-sub">“${esc(b.notes)}”</div>` : ''}` },
                { label: 'Artist', cell: b => esc(b.artist.name) },
                { label: 'Total', num: true, cell: b => money(b.total) },
                { label: 'Paid', num: true, cell: b => `${money(b.amount_paid)}${b.balance_due > 0 ? `<div class="d-cell-sub">${money(b.balance_due)} due</div>` : ''}` },
                { label: 'Status', cell: b => `${ui.badge(b.status, b.status === 'pending' ? 'Needs your answer' : '')}${b.status === 'pending' ? `<div class="d-cell-sub">Asked ${esc(ago(b.created_at))}</div>` : ''}${b.studio_note && b.status !== 'pending' ? `<div class="d-cell-sub">${esc(b.studio_note)}</div>` : ''}` },
                { label: '', actions: true, cell: b => bookingActions(b, mode) }
            ],
            rows,
            empty: ui.empty({ title: mode === 'requests' ? 'No requests waiting' : 'No bookings here', text: mode === 'requests' ? 'New requests appear here after the artist pays the deposit.' : '' })
        });
    }
    function bookingActions(b, mode) {
        const id = esc(b.id);
        const started = core.parseDate(b.start_at) <= new Date();
        if (b.status === 'pending') return `<div class="d-btn-row">${started ? '' : `<button class="d-btn d-btn--sm d-btn--good" type="button" data-booking="accept" data-id="${id}">Accept</button>`}<button class="d-btn d-btn--sm d-btn--bad" type="button" data-booking="reject" data-id="${id}">Decline</button></div>`;
        if (b.status === 'confirmed' && started) return `<div class="d-btn-row"><button class="d-btn d-btn--sm d-btn--good" type="button" data-booking="complete" data-id="${id}">Mark completed</button><button class="d-btn d-btn--sm d-btn--ghost" type="button" data-booking="no_show" data-id="${id}">Artist did not come</button></div>`;
        if (b.status === 'confirmed') return `<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-booking="cancel" data-id="${id}">Cancel session</button>`;
        return '';
    }
    function bindBookings(view, rows) {
        view.addEventListener('click', async e => {
            const btn = e.target.closest('[data-booking]');
            if (!btn) return;
            const b = rows.find(x => String(x.id) === btn.dataset.id);
            if (!b) return;
            const action = btn.dataset.booking;
            const patch = body => () => api.patch(`/bookings/${b.id}`, body);
            if (action === 'accept') return ui.act(btn, patch({ action: 'accept' }), `Booking #${b.id} confirmed. ${b.artist.name} gets an SMS.`).then(() => Dash.recount());
            if (action === 'reject') {
                const note = await ui.ask({ title: `Decline booking #${b.id}`, text: `${esc(b.artist.name)} gets the deposit (${money(b.deposit)}) back in their wallet and sees your reason.`, label: 'Reason', submit: 'Decline request', min: 3, placeholder: 'For example: the engineer is away that day. We are free on Friday.' });
                if (note) ui.act(btn, patch({ action: 'reject', note }), `Booking #${b.id} declined and refunded.`).then(() => Dash.recount());
                return;
            }
            if (action === 'complete') {
                if (await ui.confirm({ title: `Mark #${b.id} as completed?`, text: 'The money held for this session is released to your wallet, minus commission.', submit: 'Mark completed' })) ui.act(btn, patch({ action: 'complete' }), 'Session completed. The money is in your wallet.');
                return;
            }
            if (action === 'no_show') {
                if (await ui.confirm({ title: 'The artist did not come?', text: 'The deposit is released to you; the artist is not refunded.', submit: 'Confirm no-show', tone: 'bad' })) ui.act(btn, patch({ action: 'no_show' }), 'Marked as a no-show.');
                return;
            }
            if (action === 'cancel') {
                if (await ui.confirm({ title: `Cancel booking #${b.id}?`, text: 'The artist gets a full refund. Only cancel if you really cannot host the session.', submit: 'Cancel session', tone: 'bad' })) ui.act(btn, patch({ action: 'cancel' }), 'Session cancelled and refunded.');
            }
        });
    }

    /* ================================================================ overview */
    Dash.route('/studio', {
        title: 'Studio overview',
        roles: OWNER,
        async render(ctx) {
            const d = await api.get('/dashboard/studio', { range: ctx.params.get('range') || 30 });
            if (!d.studios.length) {
                ctx.view.innerHTML = ui.notice({ tone: 'info', title: 'Create your studio listing', text: 'Fill in the details below. After an admin approves it, artists can find and book your rooms.' }) + createStudioForm();
                bindCreate(ctx);
                return;
            }
            const t = d.totals;
            const days = d.range_days;
            ctx.setActions('<a class="d-btn d-btn--primary" href="#/studio/bookings">Requests and bookings</a>');
            ctx.view.innerHTML = `
                ${d.studios.map(statusNotice).join('')}
                ${d.studios.some(s => !s.rooms) ? ui.notice({ tone: 'warn', title: 'Add at least one room', text: 'Artists book rooms, so a studio without rooms cannot take bookings.', action: '<a class="d-btn" href="#/studio/rooms">Add a room</a>' }) : ''}
                <div class="d-toolbar">${ui.range(ctx)}</div>
                <div class="d-stats">
                    ${ui.stat({ label: 'Requests to answer', value: num(t.requests), hint: `Answer within ${esc(ctx.meta.booking_response_hours || 48)} hours or they are refunded`, href: '#/studio/bookings?tab=requests', tone: t.requests ? 'attention' : '' })}
                    ${ui.stat({ label: 'Upcoming sessions', value: num(t.upcoming), href: '#/studio/bookings?tab=upcoming' })}
                    ${ui.stat({ label: 'Available balance', value: money(t.balance), hint: t.pending_payouts ? `${money(t.pending_payouts)} being withdrawn` : 'Ready to withdraw', href: '#/earnings', tone: 'hero' })}
                    ${ui.stat({ label: `Revenue, last ${days} days`, value: money(t.revenue_range), hint: `${money(t.revenue)} all time, after commission` })}
                    ${ui.stat({ label: 'Pending payments', value: money(t.in_escrow), hint: 'Paid by artists, released when sessions are completed' })}
                    ${ui.stat({ label: 'Completed sessions', value: num(t.completed), hint: `${num(t.hours_range)} hours booked in the last ${days} days` })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-12', title: 'Requests waiting for your answer', flush: true, body: bookingRows(d.requests, 'requests') })}
                    ${ui.panel({ cls: 'd-col-7', title: 'Revenue per month', sub: 'Released to your wallet after completed sessions, last 12 months.', body: '<div data-chart="monthly"></div>' })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Booking requests per day', sub: `Last ${days} days.`, body: '<div data-chart="daily"></div>' })}
                    ${ui.panel({ cls: 'd-col-7', title: 'Coming up', flush: true, actions: '<a class="d-btn d-btn--sm" href="#/studio/bookings?tab=upcoming">Full schedule</a>', body: bookingRows(d.schedule.slice(0, 6), 'upcoming') })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Recent activity', flush: true, body: d.recent.length ? `<ul class="d-feed">${d.recent.map(b => `<li><span class="d-feed-icon">${ui.icon('calendar')}</span><span>${esc(b.artist.name)}, ${esc(b.room.name)}: ${ui.badge(b.status)}</span><time>${esc(ago(b.responded_at || b.created_at))}</time></li>`).join('')}</ul>` : ui.empty({ title: 'No bookings yet' }) })}
                </div>`;
            ui.chart.bars(ctx.view.querySelector('[data-chart="monthly"]'), { labels: d.monthly.map(m => m.label.replace(/ \d{4}$/, '')), series: [{ name: 'Revenue', values: d.monthly.map(m => m.total) }], format: v => money(v) });
            ui.chart.line(ctx.view.querySelector('[data-chart="daily"]'), { labels: d.daily_bookings.map(x => x.label), series: [{ name: 'Requests', values: d.daily_bookings.map(x => x.bookings) }] });
            bindBookings(ctx.view, [...d.requests, ...d.schedule]);
        }
    });

    /* ================================================================ bookings */
    Dash.route('/studio/bookings', {
        title: 'Requests and bookings',
        roles: OWNER,
        async render(ctx) {
            const tab = ctx.params.get('tab') || 'requests';
            const [d, all] = await Promise.all([api.get('/dashboard/studio', { range: 30 }), tab === 'history' ? api.get('/bookings', { as: 'studio' }) : Promise.resolve(null)]);
            const lists = {
                requests: d.requests,
                upcoming: d.schedule,
                to_close: d.to_close,
                history: all ? core.list(all).filter(b => !['held', 'expired'].includes(b.status)) : []
            };
            const rows = lists[tab] || [];
            const intro = {
                requests: `The artist has paid the deposit. Accept or decline within ${esc(ctx.meta.booking_response_hours || 48)} hours; unanswered requests are declined and refunded automatically.`,
                upcoming: 'Confirmed sessions. The rest of the price is paid at the studio or online before the session.',
                to_close: 'Sessions whose time has passed. Mark them completed to release the money to your wallet.',
                history: 'Every booking for your studio.'
            }[tab];
            ctx.view.innerHTML = `${ui.tabs([
                { label: 'Requests', href: ctx.link({ tab: 'requests' }), current: tab === 'requests', count: d.requests.length },
                { label: 'Upcoming', href: ctx.link({ tab: 'upcoming' }), current: tab === 'upcoming' },
                { label: 'To complete', href: ctx.link({ tab: 'to_close' }), current: tab === 'to_close', count: d.to_close.length },
                { label: 'History', href: ctx.link({ tab: 'history' }), current: tab === 'history' }
            ])}<p class="d-intro">${intro}</p>${ui.panel({ flush: true, body: bookingRows(rows, tab) })}`;
            bindBookings(ctx.view, rows);
        }
    });

    /* ================================================================ rooms and hours */
    Dash.route('/studio/rooms', {
        title: 'Rooms and hours',
        roles: OWNER,
        async render(ctx) {
            const studios = core.list(await api.get('/studios/mine'));
            if (!studios.length) {
                ctx.view.innerHTML = createStudioForm();
                bindCreate(ctx);
                return;
            }
            const sid = Number(ctx.params.get('studio') || studios[0].id);
            const s = studios.find(x => x.id === sid) || studios[0];
            ctx.setActions('<button class="d-btn d-btn--primary" type="button" data-add-room>Add a room</button>');
            ctx.view.innerHTML = `${studios.length > 1 ? `<div class="d-segment" role="group" aria-label="Studio">${studios.map(x => `<a href="${esc(ctx.link({ studio: x.id }))}" aria-current="${x.id === s.id}">${esc(x.name)}</a>`).join('')}</div>` : ''}
                ${statusNotice(s)}
                ${s.rooms.length ? `<div class="d-cards">${s.rooms.map(roomCard).join('')}</div>` : ui.panel({ body: ui.empty({ title: 'No rooms yet', text: 'Add the rooms artists can book, with a price per hour.', action: '<button class="d-btn d-btn--primary" type="button" data-add-room>Add a room</button>' }) })}`;
            const find = id => s.rooms.find(r => String(r.id) === String(id));
            const handler = async e => {
                const b = e.target.closest('[data-add-room], [data-room]');
                if (!b) return;
                if (b.hasAttribute('data-add-room')) {
                    ui.formModal({ title: `Add a room to ${s.name}`, submit: 'Add room', fields: roomFields(), async onSubmit(form, close) {
                        await api.post(`/studios/${s.id}/rooms`, roomValues(form));
                        close(); core.toast('Room added with opening hours Monday to Saturday, 09:00 to 21:00.'); Dash.refresh();
                    } });
                    return;
                }
                const room = find(b.dataset.id);
                const action = b.dataset.room;
                if (action === 'edit') {
                    ui.formModal({ title: `Edit ${room.name}`, submit: 'Save room', fields: roomFields(room), async onSubmit(form, close) {
                        await api.patch(`/rooms/${room.id}`, roomValues(form));
                        close(); core.toast('Room saved.'); Dash.refresh();
                    } });
                } else if (action === 'photo') {
                    ui.formModal({ title: `Photo for ${room.name}`, submit: 'Upload photo', fields: ui.field({ label: 'Room photo', name: 'photo', type: 'file', required: true, missing: 'Choose a photo of the room.', maxMb: Number(((Dash.state.meta || {}).uploads || {}).max_image_mb || 5), attrs: 'accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"' }), async onSubmit(form, close) {
                        const fd = new FormData(form);
                        await api.upload(`/rooms/${room.id}/photo`, fd);
                        close(); core.toast('Photo uploaded.'); Dash.refresh();
                    } });
                } else if (action === 'hours') {
                    const open = Object.fromEntries((room.hours || []).map(h => [h.weekday, h]));
                    ui.formModal({ title: `Opening hours for ${room.name}`, submit: 'Save hours', intro: 'Artists can only book inside these hours.',
                        fields: `<div class="d-hours">${DAYS.map((d, i) => { const h = open[i + 1]; return `<div class="d-hours-row" data-day="${i + 1}"><strong>${d}</strong><label class="d-check"><input type="checkbox" data-open${h ? ' checked' : ''}> Open</label><input class="d-input" type="time" data-from value="${esc(h ? h.open : '09:00')}" aria-label="${d} opens"><input class="d-input" type="time" data-to value="${esc(h ? h.close : '21:00')}" aria-label="${d} closes"></div>`; }).join('')}</div><div data-field="hours"></div>`,
                        async onSubmit(form, close) {
                            const hours = Array.from(form.querySelectorAll('[data-day]')).filter(r => r.querySelector('[data-open]').checked).map(r => ({ weekday: Number(r.dataset.day), open: r.querySelector('[data-from]').value, close: r.querySelector('[data-to]').value }));
                            await api.put(`/rooms/${room.id}/hours`, { hours });
                            close(); core.toast('Opening hours saved.'); Dash.refresh();
                        } });
                } else if (action === 'block') {
                    ui.formModal({ title: `Close ${room.name} on a date`, submit: 'Block date', intro: 'Use this for holidays or maintenance. Dates with bookings cannot be blocked.',
                        fields: `<div class="d-fields">${ui.field({ label: 'Date', name: 'date', type: 'date', required: true, attrs: `min="${new Date().toISOString().slice(0, 10)}"` })}${ui.field({ label: 'Reason', name: 'reason', optional: true, attrs: 'maxlength="255"' })}</div>`,
                        async onSubmit(form, close) {
                            await api.post(`/rooms/${room.id}/blocked-dates`, media.values(form));
                            close(); core.toast('Date blocked.'); Dash.refresh();
                        } });
                } else if (action === 'unblock') {
                    ui.act(b, () => api.delete(`/rooms/${room.id}/blocked-dates/${b.dataset.blocked}`), 'Date opened again.');
                } else if (action === 'toggle') {
                    ui.act(b, () => api.patch(`/rooms/${room.id}`, { status: room.status === 'active' ? 'inactive' : 'active' }), room.status === 'active' ? 'Room closed for bookings.' : 'Room open for bookings.');
                }
            };
            ctx.view.addEventListener('click', handler);
            document.getElementById('d-actions').onclick = handler;      // the "Add a room" button in the top bar
        }
    });

    function roomCard(r) {
        const hours = (r.hours || []);
        const hoursText = hours.length ? hours.map(h => `${h.day} ${h.open}–${h.close}`).join(', ') : 'No opening hours, so it cannot be booked';
        return `<article class="d-card"><div class="d-card-head">${ui.thumb(r.photo_url, r.name)}<div><h3>${esc(r.name)}</h3><p class="d-cell-sub">${money(r.hourly_rate)} per hour, at least ${esc(r.min_hours)} h</p></div><span style="margin-left:auto">${ui.badge(r.status === 'active' ? 'active' : 'inactive', r.status === 'active' ? 'Open' : 'Closed')}</span></div>
            <dl><dt>Engineer</dt><dd>${r.engineer_included ? 'Included' : 'Not included'}</dd><dt>Hours</dt><dd>${esc(hoursText)}</dd><dt>Closed on</dt><dd>${(r.blocked_dates || []).length ? r.blocked_dates.map(bd => `${esc(date(bd.date))} <button class="d-btn d-btn--ghost d-btn--sm" type="button" data-room="unblock" data-id="${r.id}" data-blocked="${bd.id}" aria-label="Open ${esc(date(bd.date))} again">${ui.icon('x')}</button>`).join(' ') : '<span class="d-muted">No blocked dates</span>'}</dd></dl>
            ${r.description ? `<p class="d-desc">${esc(r.description)}</p>` : ''}
            <div class="d-btn-row"><button class="d-btn d-btn--sm" type="button" data-room="edit" data-id="${r.id}">Edit room</button><button class="d-btn d-btn--sm" type="button" data-room="hours" data-id="${r.id}">Opening hours</button><button class="d-btn d-btn--sm" type="button" data-room="block" data-id="${r.id}">Block a date</button><button class="d-btn d-btn--sm d-btn--ghost" type="button" data-room="photo" data-id="${r.id}">Photo</button><button class="d-btn d-btn--sm d-btn--ghost" type="button" data-room="toggle" data-id="${r.id}">${r.status === 'active' ? 'Close room' : 'Open room'}</button></div></article>`;
    }

    /* ================================================================ studio profile */
    Dash.route('/studio/profile', {
        title: 'Studio profile',
        roles: OWNER,
        async render(ctx) {
            const studios = core.list(await api.get('/studios/mine'));
            if (!studios.length) {
                ctx.view.innerHTML = createStudioForm();
                bindCreate(ctx);
                return;
            }
            const sid = Number(ctx.params.get('studio') || studios[0].id);
            const s = studios.find(x => x.id === sid) || studios[0];
            ctx.setActions(s.status === 'verified' ? `<a class="d-btn" href="${core.ROOT}studio.html?id=${s.id}" target="_blank" rel="noopener">View public page</a>` : '');
            ctx.view.innerHTML = `${studios.length > 1 ? `<div class="d-segment" role="group" aria-label="Studio">${studios.map(x => `<a href="${esc(ctx.link({ studio: x.id }))}" aria-current="${x.id === s.id}">${esc(x.name)}</a>`).join('')}</div>` : ''}
                ${statusNotice(s)}
                <form class="d-form" id="studio-edit" novalidate>${studioFields(s)}
                    <fieldset class="d-fieldset"><legend>Cover photo</legend><div class="d-fields">${s.cover_url ? `<img src="${esc(s.cover_url)}" alt="" style="width:100%;max-width:320px;border-radius:8px">` : ''}${ui.field({ label: s.cover_url ? 'Replace the cover photo' : 'Cover photo', name: 'cover', type: 'file', attrs: 'accept="image/jpeg,image/png,image/webp"', optional: true })}</div></fieldset>
                    <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">${s.status === 'rejected' ? 'Save and send for review' : 'Save changes'}</button></div></form>
                <p class="d-muted">Want to list another studio? <a href="#/studio/new">Create a new listing</a>.</p>`;
            ui.onSubmit(ctx.view.querySelector('#studio-edit'), async f => {
                await api.patch(`/studios/${s.id}`, studioValues(f));
                const cover = f.querySelector('[name="cover"]').files[0];
                if (cover) {
                    const fd = new FormData();
                    fd.append('cover', cover);
                    await api.upload(`/studios/${s.id}/cover`, fd);
                }
                core.toast(s.status === 'rejected' ? 'Saved and sent back for review.' : 'Studio saved.');
                Dash.refresh();
            });
        }
    });

    Dash.route('/studio/new', {
        title: 'List a studio',
        render(ctx) {
            ctx.view.innerHTML = ui.notice({ tone: 'info', title: 'List your recording studio', text: 'After an admin approves the listing, artists can find it and book your rooms. Deposits are held safely until each session is completed.' }) + createStudioForm();
            bindCreate(ctx);
        }
    });

    Dash.studio = { bookingRows, bindBookings };
})();
