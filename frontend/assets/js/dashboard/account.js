/*
 * Screens every account shares: earnings and wallet (withdrawals), my studio
 * bookings, profile and settings, getting started, plus the manager and
 * vocal coach views.
 */
(function () {
    'use strict';

    const { ui, media } = window.Dash;
    const { esc, num, money, date, dateTime, ago } = ui;
    const api = window.api;
    const core = window.NVCore;
    /** 233241234567 -> 024 123 4567 (other countries keep +digits). */
    const localPhone = p => {
        const d = String(p || '').replace(/\D/g, '');
        if (d.startsWith('233') && d.length === 12) return `0${d.slice(3, 5)} ${d.slice(5, 8)} ${d.slice(8)}`;
        return d ? `+${d}` : '';
    };

    /** Opens Paystack for a payment and reports the result once the server has verified it. */
    async function payWithPaystack(payment, what) {
        const result = await core.pay(payment);
        if (result === 'redirected') return;                      // Paystack's page brings them back to payment.html
        if (result === 'cancelled') { core.toast('Payment cancelled. Nothing was charged.', 'info'); return; }
        core.toast('Checking your payment…', 'info');
        const p = await core.confirmPayment(payment.reference);
        if (p && p.status === 'completed') core.toast(`${what} paid. Thank you!`);
        else if (p && p.status === 'failed') core.toast(p.message || 'The payment did not go through.', 'error');
        else core.toast('Payment received by Paystack. It will show here in a moment.', 'info');
        Dash.refresh();
    }

    /* ================================================================ earnings and wallet */
    Dash.route('/earnings', {
        title: 'Earnings and wallet',
        async render(ctx) {
            const w = await api.get('/dashboard/wallet');
            const studioOnly = ctx.has('studio_owner') && !ctx.has('artist', 'choir', 'producer');
            const series = studioOnly
                ? [{ name: 'Studio sessions', values: w.monthly.map(m => m.bookings) }]
                : [{ name: 'Sales', values: w.monthly.map(m => m.sales) }, { name: 'Tips', values: w.monthly.map(m => m.tips) }].concat(ctx.has('studio_owner') ? [{ name: 'Studio sessions', values: w.monthly.map(m => m.bookings) }] : []);
            const canWithdraw = w.balance >= w.min_payout;
            ctx.view.innerHTML = `
                <div class="d-stats">
                    ${ui.stat({ label: 'Available balance', value: money(w.balance), hint: canWithdraw ? 'You can withdraw this now' : `You can withdraw from ${money(w.min_payout)}`, tone: 'hero' })}
                    ${ui.stat({ label: 'Withdrawals in progress', value: money(w.pending_payouts), hint: 'Requested and being sent' })}
                    ${ui.stat({ label: 'Pending sales', value: money(w.pending_sales), hint: 'Waiting for buyers to approve payment' })}
                    ${ui.stat({ label: 'Earned this month', value: money(w.this_month) })}
                    ${ui.stat({ label: 'Earned all time', value: money(w.lifetime.total), hint: `${money(w.withdrawn)} withdrawn so far` })}
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-8', title: 'Earnings per month', sub: 'After commission, last 12 months.', body: '<div data-chart="monthly"></div>' })}
                    ${ui.panel({ cls: 'd-col-4', title: 'Withdraw to mobile money', body: `<form class="d-form" id="payout-form" data-error-title="Your withdrawal could not be requested:" novalidate>
                        ${ui.field({ label: 'Amount (GH₵)', name: 'amount', type: 'number', required: true, attrs: `min="${w.min_payout}" max="${w.balance}" step="1" data-range-message="Enter an amount between ${money(w.min_payout)} and your balance of ${money(w.balance)}."${canWithdraw ? '' : ' disabled'}`, value: canWithdraw ? w.balance : '' , hint: `Minimum ${money(w.min_payout)}.` })}
                        ${ui.field({ label: 'Mobile money number', name: 'phone', type: 'tel', value: localPhone(w.payout_phone || w.account_phone), required: true, placeholder: '024 123 4567', attrs: canWithdraw ? '' : 'disabled' })}
                        ${ui.field({ label: 'Network', name: 'provider', value: '', options: [['', 'Detect from the number'], ...(ctx.meta.mobile_money_providers || []).map(x => [x.code, x.name])], attrs: canWithdraw ? '' : 'disabled' })}
                        <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit"${canWithdraw ? '' : ' disabled'}>Request withdrawal</button></div>
                        <p class="d-muted" style="font-size:.8125rem">The amount leaves your balance now and is sent after an admin approves it. If it fails, it comes back to your wallet.</p></form>` })}
                    ${ui.panel({ cls: 'd-col-7', title: 'Transactions', flush: true, body: ui.table({
                        columns: [
                            { label: 'What', main: true, cell: t => `<div class="d-cell-title">${esc(t.label)}</div><div class="d-cell-sub">${esc(t.memo || '')}</div>` },
                            { label: 'Date', cell: t => esc(dateTime(t.created_at)) },
                            { label: 'Amount', num: true, cell: t => `<strong>${t.amount > 0 ? '+' : ''}${money(t.amount)}</strong>` }
                        ], rows: w.transactions, empty: ui.empty({ title: 'No transactions yet' })
                    }) })}
                    ${ui.panel({ cls: 'd-col-5', title: 'Withdrawals', flush: true, body: ui.table({
                        columns: [
                            { label: 'Amount', main: true, cell: p => `<div class="d-cell-title">${money(p.amount)}</div><div class="d-cell-sub">To ${esc(localPhone(p.phone))}</div>` },
                            { label: 'Requested', cell: p => esc(date(p.created_at)) },
                            { label: 'Status', cell: p => `${ui.badge(p.status === 'completed' ? 'paid' : p.status, p.status === 'completed' ? 'Sent' : '')}${p.failure_reason ? `<div class="d-cell-sub">${esc(p.failure_reason)}</div>` : ''}` }
                        ], rows: w.payouts, empty: ui.empty({ title: 'No withdrawals yet' })
                    }) })}
                </div>`;
            ui.chart.bars(ctx.view.querySelector('[data-chart="monthly"]'), { labels: w.monthly.map(m => m.label.replace(/ \d{4}$/, '')), series, format: v => money(v) });
            ui.onSubmit(ctx.view.querySelector('#payout-form'), async f => {
                const amount = Number(f.amount.value);
                if (!(await ui.confirm({ title: `Withdraw ${money(amount)}?`, text: `It will be sent to ${esc(f.phone.value)}.`, submit: 'Request withdrawal' }))) return;
                await api.post('/payouts', { amount, phone: f.phone.value.trim(), provider: f.provider.value || null });
                core.toast('Withdrawal requested. You will get a message when it is sent.');
                Dash.refresh();
            });
        }
    });

    /* ================================================================ my studio bookings (as the artist) */
    Dash.route('/my-bookings', {
        title: 'My bookings',
        async render(ctx) {
            const [studioRes, coachingRes] = await Promise.all([api.get('/bookings', { as: 'artist' }), api.get('/mentor-bookings', { as: 'mentee' }).catch(() => ({ data: [] }))]);
            const rows = core.list(studioRes).filter(b => b.status !== 'held' || core.parseDate(b.hold_expires_at) > new Date());
            const coaching = core.list(coachingRes);
            ctx.setActions(`<a class="d-btn d-btn--primary" href="${core.ROOT}studios.html">Find a studio</a>`);
            ctx.view.innerHTML = `<p class="d-intro">Your deposit is held safely until the session is completed. If a studio declines or does not answer within ${esc(ctx.meta.booking_response_hours || 48)} hours, the deposit goes back to your wallet.</p>
                ${ui.panel({ flush: true, body: ui.table({
                    columns: [
                        { label: 'Studio', main: true, cell: b => `<div class="d-cell-title">${esc(b.studio.name)}</div><div class="d-cell-sub">${esc(b.room.name)}, #${esc(b.id)}</div>` },
                        { label: 'Session', cell: b => `${esc(dateTime(b.start_at))}<div class="d-cell-sub">${esc(b.hours)} h</div>` },
                        { label: 'Total', num: true, cell: b => money(b.total) },
                        { label: 'Paid', num: true, cell: b => `${money(b.amount_paid)}${b.balance_due > 0 && ['pending', 'confirmed'].includes(b.status) ? `<div class="d-cell-sub">${money(b.balance_due)} left to pay</div>` : ''}` },
                        { label: 'Status', cell: b => `${ui.badge(b.status, b.status === 'pending' ? 'Waiting for the studio' : b.status === 'rejected' ? 'Declined, refunded' : '')}${b.studio_note ? `<div class="d-cell-sub">${esc(b.studio_note)}</div>` : ''}` },
                        { label: '', actions: true, cell: b => {
                            const btns = [];
                            if (b.status === 'confirmed' && b.balance_due > 0 && core.parseDate(b.start_at) > new Date()) btns.push(`<button class="d-btn d-btn--sm d-btn--primary" type="button" data-pay="${b.id}">Pay the balance</button>`);
                            if (['pending', 'confirmed'].includes(b.status) && core.parseDate(b.start_at) > new Date()) btns.push(`<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-cancel="${b.id}">Cancel</button>`);
                            if (b.status === 'confirmed' && core.parseDate(b.start_at) < new Date()) btns.push(`<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-noshow="${b.id}">Studio was closed</button>`);
                            return `<div class="d-btn-row">${btns.join('')}</div>`;
                        } }
                    ], rows,
                    empty: ui.empty({ title: 'No studio bookings yet', action: `<a class="d-btn d-btn--primary" href="${core.ROOT}studios.html">Find a studio</a>` })
                }) })}
                ${ui.panel({ title: 'Vocal coaching sessions', flush: true, actions: `<a class="d-btn d-btn--sm" href="${core.ROOT}vocal-coaches.html">Find a coach</a>`, body: ui.table({
                    columns: [
                        { label: 'Session', main: true, cell: m => `<div class="d-cell-title">${esc(m.subject)}</div><div class="d-cell-sub">with ${esc(m.mentor_name)}</div>` },
                        { label: 'When', cell: m => `${esc(dateTime(m.start_at))}<div class="d-cell-sub">${esc(m.duration_minutes)} min</div>` },
                        { label: 'Status', cell: m => ui.badge(m.status) },
                        { label: '', actions: true, cell: m => ['requested', 'confirmed'].includes(m.status) ? `<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-coaching-cancel="${m.id}">Cancel</button>` : '' }
                    ], rows: coaching, empty: ui.empty({ title: 'No coaching sessions yet' })
                }) })}`;
            ctx.view.addEventListener('click', async e => {
                const coachingCancel = e.target.closest('[data-coaching-cancel]');
                if (coachingCancel) {
                    if (await ui.confirm({ title: 'Cancel this coaching session?', submit: 'Cancel session', tone: 'bad' })) ui.act(coachingCancel, () => api.patch(`/mentor-bookings/${coachingCancel.dataset.coachingCancel}`, { action: 'cancel' }), 'Session cancelled.');
                    return;
                }
                const pay = e.target.closest('[data-pay]');
                const cancel = e.target.closest('[data-cancel]');
                const noshow = e.target.closest('[data-noshow]');
                const b = rows.find(x => String(x.id) === (pay || cancel || noshow || {}).dataset?.[pay ? 'pay' : cancel ? 'cancel' : 'noshow']);
                if (!b) return;
                if (pay) {
                    ui.formModal({ title: `Pay ${money(b.balance_due)} for booking #${b.id}`, submit: 'Pay with Paystack',
                        intro: 'You pay on Paystack’s secure checkout with Mobile Money (MTN, Telecel, AirtelTigo) or a card.', fields: '',
                        async onSubmit(form, close) {
                            const res = await api.post(`/bookings/${b.id}/pay-balance`, {});
                            close();
                            await payWithPaystack(res.payment, `Booking #${b.id}`);
                        } });
                } else if (cancel) {
                    const free = b.status === 'pending' || (core.parseDate(b.start_at) - Date.now()) / 36e5 >= 24;
                    if (await ui.confirm({ title: `Cancel booking #${b.id}?`, text: free ? 'You get a full refund to your wallet.' : 'The session is less than 24 hours away, so the deposit is not refunded.', submit: 'Cancel booking', tone: 'bad' })) ui.act(cancel, () => api.patch(`/bookings/${b.id}`, { action: 'cancel' }), 'Booking cancelled.');
                } else if (noshow) {
                    if (await ui.confirm({ title: 'Report that the studio was closed?', text: 'You get a full refund and the team is notified.', submit: 'Report and refund', tone: 'bad' })) ui.act(noshow, () => api.patch(`/bookings/${b.id}`, { action: 'studio_no_show' }), 'Reported. Your money is back in your wallet.');
                }
            });
        }
    });

    /* ================================================================ profile and settings */
    const PROFILE_TYPES = [['artist', 'Artist'], ['choir', 'Choir'], ['producer', 'Music producer'], ['both', 'Artist and producer'], ['manager', 'Artist manager'], ['vocal_coach', 'Vocal coach']];

    function profileFields(p, meta, presetType) {
        const type = (p && p.type) || presetType || 'artist';
        const only = (types, html) => `<div data-for="${types.join(' ')}" style="display:contents">${html}</div>`;
        return `<fieldset class="d-fieldset"><legend>Public profile</legend><p>Shown on your page and next to your releases.</p><div class="d-fields">
            ${ui.field({ label: 'Profile name', name: 'stage_name', value: p && p.stage_name, required: true, missing: 'Profile name is missing.', attrs: 'maxlength="80"', hint: 'Your artist, choir, producer or business name.' })}
            ${ui.field({ label: 'Profile type', name: 'type', value: type, options: PROFILE_TYPES })}
            ${ui.field({ label: 'City or location', name: 'city', value: p && p.city, optional: true, placeholder: 'Accra, Kumasi, Tamale…' })}
            ${only(['artist', 'choir', 'producer', 'both'], ui.field({ label: 'Genres', name: 'genres', value: p && p.genres ? p.genres.join(', ') : '', optional: true, attrs: 'list="nv-genres"', hint: 'Separate several with commas.' }))}
            ${only(['choir'], ui.field({ label: 'Number of members', name: 'members_count', type: 'number', value: p && p.members_count, attrs: 'min="2" max="5000"' }))}
            ${only(['vocal_coach'], ui.field({ label: 'Years of experience', name: 'experience_years', type: 'number', value: p && p.experience_years, attrs: 'min="0" max="80"' }) + ui.field({ label: 'Specialization', name: 'specialization', value: p && p.specialization }))}
            ${only(['manager'], ui.field({ label: 'Company', name: 'company', value: p && p.company, optional: true }))}
            ${ui.field({ label: 'Website or social link', name: 'website', value: p && p.website, optional: true })}
            ${ui.field({ label: 'Bio', name: 'bio', type: 'textarea', value: p && p.bio, wide: true, attrs: 'maxlength="2000"' })}
        </div><datalist id="nv-genres">${(meta.genres || []).map(g => `<option value="${esc(g)}">`).join('')}</datalist></fieldset>
        <fieldset class="d-fieldset"><legend>Contact and payouts</legend><p>Private. Used by the Nile Vijana team and for sending your earnings.</p><div class="d-fields">
            ${ui.field({ label: 'Contact email', name: 'contact_email', type: 'email', value: p && p.contact_email, optional: true })}
            ${ui.field({ label: 'Contact phone', name: 'contact_phone', type: 'tel', value: p && p.contact_phone, optional: true })}
            ${ui.field({ label: 'Mobile money number for payouts', name: 'payout_phone', type: 'tel', value: localPhone(p && p.payout_phone), optional: true, placeholder: '024 123 4567' })}
            ${ui.field({ label: 'Mobile money network', name: 'payout_provider', value: p && p.payout_provider, options: [['', 'Detect from the number'], ...(meta.mobile_money_providers || []).map(x => [x.code, x.name])] })}
        </div></fieldset>`;
    }
    function bindTypeToggle(form) {
        const sync = () => {
            const type = form.type.value;
            form.querySelectorAll('[data-for]').forEach(g => {
                const on = g.dataset.for.split(' ').includes(type);
                g.hidden = !on;
                g.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = !on; });
            });
        };
        form.type.addEventListener('change', sync);
        sync();
    }

    Dash.route('/profile', {
        title: 'Profile and settings',
        async render(ctx) {
            const me = ctx.me;
            const p = me.creator_profile;
            const isMusician = ctx.has('artist', 'choir');
            const manager = isMusician && p ? (await api.get('/creators/me/manager').catch(() => ({}))).manager : null;
            ctx.view.innerHTML = `
                <div class="d-grid">
                    <div class="d-col-8" style="display:grid;gap:20px;align-content:start">
                        ${p || !ctx.has('admin') ? `<form class="d-form" id="profile-form" novalidate>${profileFields(p, ctx.meta, ctx.params.get('type'))}<div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">${p ? 'Save profile' : 'Create my profile'}</button></div></form>` : ''}
                        ${isMusician && p ? ui.panel({ title: 'Your manager', sub: 'Private: not shown on your public page.', body: `<form class="d-form" id="manager-form" novalidate><div class="d-fields">
                            ${ui.field({ label: "Manager's name", name: 'name', value: manager && manager.name, required: true })}
                            ${ui.field({ label: 'Company', name: 'company', value: manager && manager.company, optional: true })}
                            ${ui.field({ label: 'Email', name: 'email', type: 'email', value: manager && manager.email })}
                            ${ui.field({ label: 'Phone', name: 'phone', type: 'tel', value: manager && manager.phone })}
                        </div><div class="d-form-foot"><button class="d-btn" type="submit">Save manager</button>${manager ? '<button class="d-btn d-btn--ghost" type="button" data-remove-manager>Remove manager</button>' : ''}</div></form>` }) : ''}
                    </div>
                    <div class="d-col-4" style="display:grid;gap:20px;align-content:start">
                        ${p ? ui.panel({ title: 'Profile picture', body: `<div style="display:grid;gap:12px;justify-items:start">${p.avatar_url ? `<img src="${esc(p.avatar_url)}" alt="" style="width:120px;height:120px;border-radius:50%;object-fit:cover">` : ''}<form id="avatar-form" class="d-form">${ui.field({ label: 'Profile picture', name: 'avatar', type: 'file', required: true, missing: 'Choose a photo to upload.', maxMb: Number((ctx.meta.uploads || {}).max_image_mb || 5), attrs: 'accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"' })}<div class="d-form-foot"><button class="d-btn" type="submit">Upload photo</button></div></form>${p.slug && ['artist', 'choir', 'producer', 'both'].includes(p.type) ? `<a class="d-btn d-btn--ghost" href="${core.ROOT}creator.html?slug=${encodeURIComponent(p.slug)}" target="_blank" rel="noopener">View my public page</a>` : ''}</div>` }) : ''}
                        ${ui.panel({ title: 'Account and sign-in', body: `<form class="d-form" id="account-form" data-error-title="Your account changes were not saved:" novalidate>
                            ${ui.field({ label: 'Your name', name: 'name', value: me.user.name, required: true })}
                            ${ui.field({ label: 'Username', name: 'username', value: me.user.username || '', required: true, attrs: 'maxlength="30" autocomplete="username" pattern="[A-Za-z][A-Za-z0-9._]{2,29}" data-pattern-message="Username must be 3 to 30 characters, start with a letter, and use only letters, numbers, dots or underscores."', hint: 'You can sign in with this or your email.' })}
                            ${ui.field({ label: 'Email address', name: 'email', type: 'email', value: me.user.email, required: true, hint: me.user.email_verified ? 'Verified. Sign-in codes are sent here.' : 'Sign-in codes are sent here.' })}
                            ${ui.field({ label: 'Phone number', name: 'phone', type: 'tel', value: localPhone(me.user.phone), optional: true, placeholder: '024 123 4567', hint: 'For payments and booking alerts. Not used to sign in.' })}
                            ${ui.field({ label: 'Current password', name: 'current_password', type: 'password', optional: true, attrs: 'autocomplete="current-password"', hint: 'Only needed to change your password.' })}
                            ${ui.field({ label: 'New password', name: 'new_password', type: 'password', optional: true, attrs: 'autocomplete="new-password" data-strength' })}
                            <div class="d-form-foot"><button class="d-btn" type="submit">Save account</button></div></form>` })}
                    </div>
                </div>`;
            const profileForm = ctx.view.querySelector('#profile-form');
            if (profileForm) {
                bindTypeToggle(profileForm);
                ui.onSubmit(profileForm, async f => {
                    const data = media.values(f, p ? ['bio', 'city', 'genres', 'website', 'company', 'specialization', 'contact_email', 'contact_phone'] : []);
                    ['members_count', 'experience_years'].forEach(k => { if (data[k] != null) data[k] = Number(data[k]); });
                    Object.keys(data).forEach(k => { if (data[k] === null) delete data[k]; });   // the API keeps fields it is not sent
                    const res = await api.patch('/creators/me', data);
                    core.toast(p ? 'Profile saved.' : 'Profile created. Welcome!');
                    if (!p || JSON.stringify(res.roles) !== JSON.stringify(ctx.me.user.roles)) await Dash.reloadMe(); else Dash.refresh();
                });
            }
            const managerForm = ctx.view.querySelector('#manager-form');
            if (managerForm) {
                ui.onSubmit(managerForm, async f => {
                    const v = media.values(f);
                    if (!v.email && !v.phone) { const err = new Error('Your manager could not be saved:'); err.errors = { email: "Add the manager's email address or phone number." }; throw err; }
                    await api.patch('/creators/me/manager', v);
                    core.toast('Manager saved.');
                    Dash.refresh();
                });
                managerForm.querySelector('[data-remove-manager]')?.addEventListener('click', async e => {
                    if (await ui.confirm({ title: 'Remove your manager?', submit: 'Remove', tone: 'bad' })) ui.act(e.currentTarget, () => api.delete('/creators/me/manager'), 'Manager removed.');
                });
            }
            const avatar = ctx.view.querySelector('#avatar-form');
            if (avatar) ui.onSubmit(avatar, async f => {
                await api.upload('/creators/me/avatar', new FormData(f));
                core.toast('Photo updated.');
                await Dash.reloadMe();
            });
            const accountForm = ctx.view.querySelector('#account-form');
            core.attachStrength(accountForm.querySelector('[name="new_password"]'));
            ui.onSubmit(accountForm, async f => {
                await api.patch('/me', media.values(f, ['phone']));   // the API checks the current password before changing it
                core.toast('Account saved.');
                await Dash.reloadMe();
            });
        }
    });

    /* ================================================================ get started (listeners) */
    Dash.route('/start', {
        title: 'Welcome to Nile Vijana',
        async render(ctx) {
            const orders = core.list(await api.get('/orders').catch(() => []));
            ctx.view.innerHTML = `<p class="d-intro">You can listen, buy beats and book studios with this account. To publish your own work, set up a profile.</p>
                <div class="d-cards">
                    <article class="d-card"><h3>Publish music</h3><p class="d-desc">Create an artist or choir profile, upload songs and earn from downloads and tips.</p><div class="d-btn-row"><a class="d-btn d-btn--primary" href="#/profile?type=artist">I am an artist</a><a class="d-btn" href="#/profile?type=choir">We are a choir</a></div></article>
                    <article class="d-card"><h3>Sell beats</h3><p class="d-desc">Create a producer profile and sell MP3, WAV and exclusive licences.</p><div class="d-btn-row"><a class="d-btn d-btn--primary" href="#/profile?type=producer">I am a producer</a></div></article>
                    <article class="d-card"><h3>List a studio</h3><p class="d-desc">Let artists book your rooms by the hour. Deposits are held until each session is done.</p><div class="d-btn-row"><a class="d-btn d-btn--primary" href="#/studio/new">List my studio</a></div></article>
                </div>
                <div class="d-grid">
                    ${ui.panel({ cls: 'd-col-12', title: 'Recent orders', flush: true, actions: `<a class="d-btn d-btn--sm" href="${core.ROOT}orders.html">All orders and downloads</a>`, body: ui.table({
                        columns: [
                            { label: 'Order', main: true, cell: o => `<div class="d-cell-title">#${esc(o.id)}</div><div class="d-cell-sub">${esc((o.items || []).map(i => i.title).join(', '))}</div>` },
                            { label: 'Date', cell: o => esc(date(o.created_at)) },
                            { label: 'Total', num: true, cell: o => money(o.total) },
                            { label: 'Status', cell: o => ui.badge(o.status) }
                        ], rows: orders.slice(0, 5), empty: ui.empty({ title: 'No orders yet', action: `<a class="d-btn" href="${core.ROOT}browse-beats.html">Browse beats</a>` })
                    }) })}
                </div>`;
        }
    });

    /* ================================================================ payment history */
    const PURPOSE = { order: 'Purchase', booking_deposit: 'Studio deposit', booking_balance: 'Studio balance' };
    Dash.route('/payments', {
        title: 'Payment history',
        async render(ctx) {
            const rows = core.list(await api.get('/payments'));
            ctx.view.innerHTML = `<p class="d-intro">Every payment you started with Paystack. A payment only counts once Paystack confirms it to our server.</p>
                ${ui.panel({ flush: true, body: ui.table({
                    columns: [
                        { label: 'What', main: true, cell: p => `<div class="d-cell-title">${esc(p.description || PURPOSE[p.purpose] || 'Payment')}</div><div class="d-cell-sub">Ref ${esc(p.reference)}</div>` },
                        { label: 'Date', cell: p => esc(dateTime(p.created_at)) },
                        { label: 'Method', cell: p => esc(p.channel === 'mobile_money' ? 'Mobile money' : p.channel === 'card' ? 'Card' : p.channel ? p.channel.replace(/_/g, ' ') : '–') },
                        { label: 'Amount', num: true, cell: p => `<strong>${money(p.amount)}</strong>` },
                        { label: 'Status', cell: p => `${ui.badge(p.status === 'completed' ? 'paid' : ['initiated', 'accepted'].includes(p.status) ? 'pending' : p.status, ['initiated', 'accepted'].includes(p.status) ? 'Not finished' : '')}${p.status === 'failed' && p.failure_reason ? `<div class="d-cell-sub">${esc(p.failure_reason)}</div>` : ''}` },
                        { label: '', actions: true, cell: p => ['initiated', 'accepted'].includes(p.status) && p.checkout_url ? `<div class="d-btn-row"><button class="d-btn d-btn--sm d-btn--primary" type="button" data-resume="${esc(p.reference)}">Finish paying</button><button class="d-btn d-btn--sm d-btn--ghost" type="button" data-check="${esc(p.reference)}">Check status</button></div>` : '' }
                    ], rows, empty: ui.empty({ title: 'No payments yet', action: `<a class="d-btn" href="${core.ROOT}browse-tracks.html">Discover music</a>` })
                }) })}`;
            ctx.view.addEventListener('click', async e => {
                const resume = e.target.closest('[data-resume]');
                const check = e.target.closest('[data-check]');
                const p = rows.find(r => r.reference === (resume || check || {}).dataset?.[resume ? 'resume' : 'check']);
                if (!p) return;
                if (resume) return payWithPaystack(p, p.description || 'Payment');
                ui.act(check, async () => {
                    const fresh = core.obj(await api.get(`/payments/${encodeURIComponent(p.reference)}`));
                    core.toast(fresh.status === 'completed' ? 'Payment confirmed.' : fresh.status === 'failed' ? (fresh.message || 'Payment not completed.') : 'Still waiting for this payment.', fresh.status === 'failed' ? 'error' : 'success');
                });
            });
        }
    });

    /* ================================================================ my questions (Q&A) */
    Dash.route('/my-questions', {
        title: 'My questions',
        async render(ctx) {
            const rows = core.list(await api.get('/questions/mine'));
            ctx.setActions(`<a class="d-btn d-btn--primary" href="${core.ROOT}q-and-a.html#ask">Ask a question</a>`);
            ctx.view.innerHTML = rows.length ? `<div class="d-qa-list">${rows.map(q => `<article class="d-panel d-qa">
                    <header class="d-panel-head"><h2>${esc(q.subject)}</h2><div class="d-panel-actions">${ui.badge(q.status, q.status === 'open' ? 'Waiting for an answer' : q.status === 'answered' ? 'Answered' : 'Closed')}</div><p>${esc(q.category_label)} · ${esc(q.reference)} · asked ${esc(ago(q.created_at))}</p></header>
                    <div class="d-panel-body"><p class="d-qa-q">${esc(q.body)}</p>${q.answer ? `<div class="d-qa-a"><strong>Answer from the Nile Vijana team</strong><p>${esc(q.answer)}</p><small>${esc(dateTime(q.answered_at))}</small></div>` : '<p class="d-muted">We usually answer within two working days. The answer is also emailed to you.</p>'}</div>
                </article>`).join('')}</div>`
                : ui.empty({ title: 'You have not asked anything yet', text: 'Questions about payments, licences, studio bookings or your account go straight to the team.', action: `<a class="d-btn d-btn--primary" href="${core.ROOT}q-and-a.html#ask">Ask a question</a>` });
        }
    });

    /* ================================================================ manager */
    Dash.route('/manager', {
        title: 'My artists',
        roles: ['manager'],
        async render(ctx) {
            const d = await api.get('/dashboard/manager', { range: ctx.params.get('range') || 30 });
            const t = d.totals;
            ctx.view.innerHTML = `
                <div class="d-toolbar">${ui.range(ctx)}<span class="d-spacer"></span><span class="d-muted">Artists are linked to you by the Nile Vijana team.</span></div>
                <div class="d-stats">
                    ${ui.stat({ label: 'Artists and choirs', value: num(t.artists) })}
                    ${ui.stat({ label: 'Live songs', value: num(t.live_songs), hint: `${num(t.in_review)} waiting for review` })}
                    ${ui.stat({ label: `Streams, last ${d.range_days} days`, value: num(t.streams_range) })}
                    ${ui.stat({ label: `Earned, last ${d.range_days} days`, value: money(t.earned_range), hint: 'Across all your artists, after commission' })}
                </div>
                ${ui.panel({ title: 'Artists you manage', flush: true, body: ui.table({
                    columns: [
                        { label: 'Artist', main: true, cell: a => `<div class="d-cell-media">${ui.thumb(a.avatar_url, a.stage_name)}<div><div class="d-cell-title">${esc(a.stage_name)}</div><div class="d-cell-sub">${esc(ui.PROFILE_TYPES[a.type] || a.type)}${a.city ? `, ${esc(a.city)}` : ''}</div></div></div>` },
                        { label: 'Live songs', num: true, cell: a => num(a.live_songs) },
                        { label: 'In review', num: true, cell: a => num(a.in_review) },
                        { label: 'Need changes', num: true, cell: a => a.rejected ? `<span class="d-badge d-badge--bad">${num(a.rejected)}</span>` : '0' },
                        { label: `Streams (${d.range_days} d)`, num: true, cell: a => num(a.streams_range) },
                        { label: `Earned (${d.range_days} d)`, num: true, cell: a => money(a.earned_range) },
                        { label: 'Followers', num: true, cell: a => num(a.followers) },
                        { label: '', actions: true, cell: a => a.slug ? `<a class="d-btn d-btn--sm d-btn--ghost" href="${core.ROOT}creator.html?slug=${encodeURIComponent(a.slug)}" target="_blank" rel="noopener">Public page</a>` : '' }
                    ], rows: d.artists, empty: ui.empty({ title: 'No artists linked yet', text: 'Ask the Nile Vijana team to link the artists and choirs you manage.' })
                }) })}
                ${ui.panel({ title: 'Latest releases', flush: true, body: ui.table({
                    columns: [
                        { label: 'Song', main: true, cell: s => media.titleCell(s, 'song', true) },
                        { label: 'Status', cell: s => ui.badge(s.status) },
                        { label: 'Streams', num: true, cell: s => num(s.plays) },
                        { label: 'Submitted', cell: s => esc(date(s.submitted_at || s.created_at)) }
                    ], rows: d.releases, empty: ui.empty({ title: 'No releases yet' })
                }) })}`;
        }
    });

    /* ================================================================ vocal coach */
    Dash.route('/coach', {
        title: 'Coaching sessions',
        roles: ['vocal_coach'],
        async render(ctx) {
            const status = ctx.params.get('status') || '';
            const [res, mentor] = await Promise.all([
                api.get('/mentor-bookings', { as: 'mentor', status }),
                ctx.me.creator_profile && ctx.me.creator_profile.slug ? api.get(`/mentors/${encodeURIComponent(ctx.me.creator_profile.slug)}`).catch(() => null) : Promise.resolve(null)
            ]);
            const rows = core.list(res);
            const m = mentor && (mentor.mentor || mentor);
            ctx.view.innerHTML = `
                <div class="d-grid">
                    <div class="d-col-8" style="display:grid;gap:16px;align-content:start">
                        ${ui.tabs([['', 'All'], ['requested', 'Requests'], ['confirmed', 'Confirmed'], ['completed', 'Completed']].map(([v, l]) => ({ label: l, href: ctx.link({ status: v }), current: status === v })))}
                        ${ui.panel({ flush: true, body: ui.table({
                            columns: [
                                { label: 'Session', main: true, cell: b => `<div class="d-cell-title">${esc(b.subject)}</div><div class="d-cell-sub">${esc(b.mentee_name)}${b.message ? `: “${esc(b.message)}”` : ''}</div>` },
                                { label: 'When', cell: b => `${esc(dateTime(b.start_at))}<div class="d-cell-sub">${esc(b.duration_minutes)} min</div>` },
                                { label: 'Status', cell: b => ui.badge(b.status) },
                                { label: '', actions: true, cell: b => `<div class="d-btn-row">${b.status === 'requested' ? `<button class="d-btn d-btn--sm d-btn--good" data-mentor="confirm" data-id="${b.id}">Confirm</button><button class="d-btn d-btn--sm d-btn--bad" data-mentor="decline" data-id="${b.id}">Decline</button>` : ''}${b.status === 'confirmed' && core.parseDate(b.end_at || b.start_at) <= new Date() ? `<button class="d-btn d-btn--sm d-btn--good" data-mentor="complete" data-id="${b.id}">Mark completed</button>` : ''}${['requested', 'confirmed'].includes(b.status) ? `<button class="d-btn d-btn--sm d-btn--ghost" data-mentor="cancel" data-id="${b.id}">Cancel</button>` : ''}</div>` }
                            ], rows, empty: ui.empty({ title: 'No coaching sessions yet', text: 'Artists book you from the vocal coaches page.' })
                        }) })}
                    </div>
                    ${ui.panel({ cls: 'd-col-4', title: 'Coaching listing', sub: 'What artists see on the mentors page.', body: `<form class="d-form" id="mentor-form" novalidate>
                        ${ui.field({ label: 'Headline', name: 'headline', value: m && m.headline, required: true, attrs: 'maxlength="160"' })}
                        ${ui.field({ label: 'What you teach', name: 'specialties', type: 'textarea', value: m && m.specialties, required: true, attrs: 'maxlength="500"' })}
                        ${ui.field({ label: 'Accept new bookings', name: 'active', type: 'checkbox', value: m ? m.active !== false : true })}
                        <div class="d-form-foot"><button class="d-btn d-btn--primary" type="submit">Save listing</button></div></form>` })}
                </div>`;
            ctx.view.addEventListener('click', async e => {
                const b = e.target.closest('[data-mentor]');
                if (!b) return;
                const action = b.dataset.mentor;
                let note = null;
                if (action === 'decline' || action === 'cancel') {
                    note = await ui.ask({ title: action === 'decline' ? 'Decline this session' : 'Cancel this session', label: 'Message to the artist', submit: action === 'decline' ? 'Decline' : 'Cancel session', min: 3 });
                    if (!note) return;
                }
                ui.act(b, () => api.patch(`/mentor-bookings/${b.dataset.id}`, note ? { action, note } : { action }), { confirm: 'Session confirmed.', decline: 'Session declined.', complete: 'Session completed.', cancel: 'Session cancelled.' }[action]);
            });
            ui.onSubmit(ctx.view.querySelector('#mentor-form'), async f => {
                await api.patch('/mentors/me', { headline: f.headline.value.trim(), specialties: f.specialties.value.trim(), active: f.active.checked });
                core.toast('Listing saved.');
                Dash.refresh();
            });
        }
    });
})();
