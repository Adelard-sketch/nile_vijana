/*
 * Songs and beats — the forms, tables and actions shared by the admin screens
 * (upload for someone, review, edit) and the creator screens (upload, edit,
 * resubmit after a rejection). One implementation, so both sides behave alike.
 */
(function () {
    'use strict';

    const { ui } = window.Dash;
    const { esc, money, num, date } = ui;
    const api = window.api;
    const core = window.NVCore;

    const TIER_HELP = {
        mp3_lease: 'MP3 file, limited streams',
        wav_lease: 'WAV + MP3, more streams',
        unlimited: 'WAV + stems, no stream limit',
        exclusive: 'Full rights, beat comes off sale'
    };

    /** FormData without empty file inputs (so "no new file" is not sent as a broken upload). */
    function formData(form) {
        const fd = new FormData(form);
        for (const [key, value] of Array.from(fd.entries())) {
            if (value instanceof File && !value.name && !value.size) fd.delete(key);
            else if (typeof value === 'string' && value.trim() === '' && !['price'].includes(key)) fd.delete(key);
        }
        return fd;
    }

    /** JSON from a form (for PATCH): empty optional fields become null so they can be cleared. */
    function values(form, nullable = []) {
        const out = {};
        new FormData(form).forEach((value, key) => {
            if (value instanceof File) return;
            const v = String(value).trim();
            if (v === '') { if (nullable.includes(key)) out[key] = null; return; }
            out[key] = v;
        });
        return out;
    }

    /* ---------------------------------------------------------------- owners */
    const ownerCache = {};
    async function owners(kind) {
        const type = kind === 'beat' ? 'producer' : 'musician';
        if (!ownerCache[type]) ownerCache[type] = core.list(await api.get('/admin/creator-profiles', { type }));
        return ownerCache[type];
    }
    function ownerField(kind, list, selected) {
        const label = kind === 'beat' ? 'Producer' : 'Artist or choir';
        const options = [['', list.length ? `Choose the ${kind === 'beat' ? 'producer' : 'artist or choir'}` : 'Onboard someone first']]
            .concat(list.map(p => [p.id, `${p.stage_name} (${ui.PROFILE_TYPES[p.type] || p.type})`]));
        return ui.field({ label, name: kind === 'beat' ? 'producer_id' : 'creator_id', options, value: selected || '', required: true,
            missing: kind === 'beat' ? 'Choose the producer this beat belongs to.' : 'Choose the artist or choir this song belongs to.',
            hint: `The ${kind} is published under this profile and the earnings go to it. <a href="#/admin/onboard">Onboard someone new</a>` });
    }
    function publishField() {
        return ui.field({ label: 'Publishing status', name: 'publish', value: 'live', options: [
            ['live', 'Publish now (public right away)'],
            ['in_review', 'Save to the review queue (not public yet)']
        ] });
    }

    /* ---------------------------------------------------------------- song form */
    const limits = meta => ({
        audio: Number((meta.uploads || {}).max_audio_mb || 50),
        image: Number((meta.uploads || {}).max_image_mb || 5),
        stems: Number((meta.uploads || {}).max_stems_mb || 200),
        trackPrice: Number(meta.max_track_price || 500),
        licenceMin: Number(meta.min_license_price || 10),
        licenceMax: Number(meta.max_license_price || 50000)
    });

    function songFields({ item = {}, meta, mode = 'create', admin = false, ownerList = [], owner = '' }) {
        const create = mode === 'create';
        const files = mode !== 'edit';
        const lim = limits(meta);
        return `
            ${admin && create ? `<fieldset class="d-fieldset"><legend>Who is it for</legend><div class="d-fields">${ownerField('song', ownerList, owner)}${publishField()}</div></fieldset>` : ''}
            <fieldset class="d-fieldset"><legend>Song details</legend><div class="d-fields">
                ${ui.field({ label: 'Track title', name: 'title', value: item.title, required: true, missing: 'Track title is missing.', attrs: 'maxlength="160"', wide: true })}
                ${ui.field({ label: 'Genre', name: 'genre', value: item.genre, required: true, missing: 'Genre has not been selected.', options: ui.genreOptions(meta, item.genre) })}
                ${ui.field({ label: 'Mood', name: 'mood', value: item.mood, options: [['', 'Choose a mood'], ...(meta.moods || []).map(m => [m, m])], optional: true })}
                ${ui.field({ label: 'Language', name: 'language', value: item.language, options: [['', 'Choose a language'], ...(meta.languages || []).map(m => [m, m])], optional: true })}
                ${ui.field({ label: 'Release date', name: 'release_date', type: 'date', value: item.release_date, optional: true })}
                ${ui.field({ label: 'Download price (GH₵)', name: 'price', type: 'number', value: item.price ?? 0, attrs: `min="0" max="${lim.trackPrice}" step="1" data-range-message="Download price must be between GH₵ 0 and GH₵ ${lim.trackPrice}."`, hint: `0 means free to stream only. Most songs in Ghana sell for GH₵ 5–20 (maximum GH₵ ${lim.trackPrice}).` })}
                <label class="d-check d-check--hint d-field--wide" data-field="allow_download"><input type="checkbox" name="allow_download" value="1"${item.allow_download ? ' checked' : ''}>
                    <span>Free download for signed-in listeners<small>Leave this off to sell the download at the price above. Streaming is always free.</small></span></label>
                ${ui.field({ label: 'Description', name: 'description', type: 'textarea', value: item.description, attrs: 'maxlength="3000"', wide: true, optional: true, placeholder: 'Credits, the story behind the song, lyrics language…' })}
            </div></fieldset>
            ${files ? `<fieldset class="d-fieldset"><legend>Files</legend>${mode === 'resubmit' ? '<p>Only add files you want to replace.</p>' : ''}<div class="d-fields">
                ${ui.field({ label: 'Audio file', name: 'audio', type: 'file', required: create, missing: 'Audio file is required.', maxMb: lim.audio, attrs: 'accept=".mp3,.wav,.m4a,.aac,.flac,.ogg,audio/mpeg,audio/wav,audio/x-wav,audio/mp4,audio/aac,audio/flac,audio/ogg"', hint: `MP3, WAV, M4A or FLAC, up to ${lim.audio} MB.`, optional: !create })}
                ${ui.field({ label: 'Cover artwork', name: 'cover', type: 'file', required: create, missing: 'Cover artwork is missing.', maxMb: lim.image, attrs: 'accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"', hint: `Square JPG, PNG or WebP, at least 600 × 600 px, up to ${lim.image} MB.`, optional: !create })}
            </div></fieldset>` : ''}`;
    }

    /* ---------------------------------------------------------------- beat form */
    function tierBuilder(meta, current = []) {
        const have = Object.fromEntries((current || []).filter(t => t.active !== false).map(t => [t.tier, t]));
        const tiers = meta.license_tiers && meta.license_tiers.length ? meta.license_tiers : Object.keys(TIER_HELP).map(t => ({ tier: t, name: t }));
        const anyCurrent = Object.keys(have).length > 0;
        return `<fieldset class="d-fieldset" data-field="tiers"><legend>Licence options and prices</legend><p>Tick each licence you sell. WAV-based licences need the WAV file.</p><div class="d-tiers">
            ${tiers.map(t => {
                const on = anyCurrent ? Boolean(have[t.tier]) : t.tier === 'mp3_lease' || t.tier === 'wav_lease';
                // Suggested starting prices for Ghana, in cedis.
                const price = have[t.tier] ? have[t.tier].price : ({ mp3_lease: 150, wav_lease: 300, unlimited: 800, exclusive: 3000 })[t.tier] || '';
                const cap = have[t.tier] && have[t.tier].stream_cap != null ? have[t.tier].stream_cap : '';
                return `<div class="d-tier" data-tier="${esc(t.tier)}"><label class="d-check"><input type="checkbox" data-tier-on${on ? ' checked' : ''}> <span>${esc(t.name || t.tier)}<small>${esc(TIER_HELP[t.tier] || '')}</small></span></label>
                    <label class="d-field" data-field="tier-${esc(t.tier)}"><span>Price (GH₵)</span><input type="number" min="1" step="1" data-tier-price data-label="${esc(t.name || t.tier)} price" value="${esc(price)}"></label>
                    <label class="d-field"><span>Stream limit <em>(optional)</em></span><input type="number" min="0" data-tier-cap value="${esc(cap)}" placeholder="${t.default_stream_cap == null ? 'Unlimited' : esc(t.default_stream_cap)}"${t.tier === 'unlimited' || t.tier === 'exclusive' ? ' disabled' : ''}></label></div>`;
            }).join('')}
        </div></fieldset>`;
    }
    /** Problems with the licence rows, as { field: message } (empty when fine). */
    function tierProblems(form) {
        const meta = Dash.state.meta || {};
        const lim = limits(meta);
        const errors = {};
        let any = false;
        form.querySelectorAll('.d-tier').forEach(row => {
            if (!row.querySelector('[data-tier-on]').checked) return;
            any = true;
            const price = Number(row.querySelector('[data-tier-price]').value || 0);
            const name = row.querySelector('[data-tier-price]').dataset.label;
            if (!price) errors[`tier-${row.dataset.tier}`] = `${name} is missing.`;
            else if (price < lim.licenceMin || price > lim.licenceMax) errors[`tier-${row.dataset.tier}`] = `${name} must be between GH₵ ${lim.licenceMin} and GH₵ ${lim.licenceMax.toLocaleString()}.`;
        });
        if (!any) errors.tiers = 'Add at least one licence with a price.';
        const wavInput = form.querySelector('[name="wav"]');
        const needsWav = ['wav_lease', 'unlimited', 'exclusive'].some(t => form.querySelector(`.d-tier[data-tier="${t}"] [data-tier-on]`)?.checked);
        if (wavInput && wavInput.required === false && needsWav && !wavInput.files.length && form.dataset.mode === 'create') {
            errors.wav = 'WAV file is required for WAV lease, unlimited and exclusive licences.';
        }
        return errors;
    }

    function readTiers(form) {
        const problems = tierProblems(form);
        delete problems.wav;
        if (Object.keys(problems).length) {
            const err = new Error('Check the licence prices.');
            err.errors = problems;
            throw err;
        }
        const tiers = [];
        form.querySelectorAll('.d-tier').forEach(row => {
            if (!row.querySelector('[data-tier-on]').checked) return;
            const t = { tier: row.dataset.tier, price: Number(row.querySelector('[data-tier-price]').value || 0) };
            const cap = row.querySelector('[data-tier-cap]');
            if (cap && !cap.disabled && cap.value !== '') t.stream_cap = Number(cap.value);
            tiers.push(t);
        });
        return tiers;
    }

    function beatFields({ item = {}, meta, mode = 'create', admin = false, ownerList = [], owner = '' }) {
        const create = mode === 'create';
        const files = mode !== 'edit';
        const previewRequired = create && !meta.makes_previews;
        const lim = limits(meta);
        return `
            ${admin && create ? `<fieldset class="d-fieldset"><legend>Who is it for</legend><div class="d-fields">${ownerField('beat', ownerList, owner)}${publishField()}</div></fieldset>` : ''}
            <fieldset class="d-fieldset"><legend>Beat details</legend><div class="d-fields d-fields--3">
                ${ui.field({ label: 'Beat title', name: 'title', value: item.title, required: true, missing: 'Beat title is missing.', attrs: 'maxlength="160"', wide: true })}
                ${ui.field({ label: 'Genre', name: 'genre', value: item.genre, required: true, missing: 'Genre has not been selected.', options: ui.genreOptions(meta, item.genre) })}
                ${ui.field({ label: 'BPM', name: 'bpm', type: 'number', value: item.bpm, attrs: 'min="40" max="250"', optional: true })}
                ${ui.field({ label: 'Key', name: 'key', value: item.key, options: [['', 'Choose a key'], ...(meta.keys || []).map(k => [k, k])], optional: true })}
                ${ui.field({ label: 'Mood', name: 'mood', value: item.mood, options: [['', 'Choose a mood'], ...(meta.moods || []).map(m => [m, m])], optional: true })}
                ${ui.field({ label: 'Tags', name: 'tags', value: item.tags, attrs: 'maxlength="255"', optional: true, placeholder: 'log drum, dark, afro' })}
                ${ui.field({ label: 'Release date', name: 'release_date', type: 'date', value: item.release_date, optional: true })}
                ${ui.field({ label: 'Description', name: 'description', type: 'textarea', value: item.description, attrs: 'maxlength="3000"', wide: true, optional: true, placeholder: 'What the beat sounds like, what it suits, credits…' })}
            </div></fieldset>
            ${tierBuilder(meta, item.licenses)}
            ${files ? `<fieldset class="d-fieldset"><legend>Files</legend>${mode === 'resubmit' ? '<p>Only add files you want to replace.</p>' : ''}<div class="d-fields">
                ${ui.field({ label: 'MP3 file', name: 'mp3', type: 'file', required: create, missing: 'MP3 file is required.', maxMb: lim.audio, attrs: 'accept=".mp3,audio/mpeg"', optional: !create, hint: `The file buyers download. Up to ${lim.audio} MB.` })}
                ${ui.field({ label: 'WAV file', name: 'wav', type: 'file', maxMb: lim.audio * 3, attrs: 'accept=".wav,audio/wav,audio/x-wav"', optional: true, hint: 'Needed for WAV lease, unlimited and exclusive licences.' })}
                ${ui.field({ label: 'Preview audio', name: 'preview', type: 'file', required: previewRequired, missing: 'Preview audio is required: upload a short tagged preview (30 to 60 seconds).', maxMb: 20, attrs: 'accept=".mp3,.m4a,.wav,audio/*"', optional: !previewRequired, hint: meta.makes_previews ? 'Leave empty and the server makes a tagged 60-second preview.' : 'A 30–60 second preview with your voice tag. Visitors hear only this.' })}
                ${ui.field({ label: 'Stems (ZIP)', name: 'stems', type: 'file', maxMb: lim.stems, attrs: 'accept=".zip,application/zip"', optional: true, hint: 'For unlimited and exclusive licences.' })}
                ${ui.field({ label: 'Cover artwork', name: 'cover', type: 'file', required: create, missing: 'Cover artwork is missing.', maxMb: lim.image, attrs: 'accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"', optional: !create, wide: true, hint: `Square JPG, PNG or WebP, up to ${lim.image} MB.` })}
            </div></fieldset>` : ''}`;
    }

    /* ---------------------------------------------------------------- submit helpers */
    async function createSong(form) {
        const fd = formData(form);
        const bar = ui.progress(form, 'Uploading your song');
        try {
            return await api.upload('/tracks', fd, f => bar.update(f));
        } finally {
            bar.done();
        }
    }
    async function createBeat(form) {
        const tiers = readTiers(form);
        const fd = formData(form);
        fd.set('tiers', JSON.stringify(tiers));
        const bar = ui.progress(form, 'Uploading your beat');
        try {
            return await api.upload('/beats', fd, f => bar.update(f));
        } finally {
            bar.done();
        }
    }
    const UPLOAD_TITLES = {
        song: 'Your track could not be uploaded. Please complete the following:',
        beat: 'Your beat could not be uploaded. Please complete the following:'
    };

    /* ---------------------------------------------------------------- tables */
    function titleCell(item, kind, showOwner) {
        const owner = kind === 'beat' ? item.producer : item.creator;
        const sub = [showOwner && owner && owner.stage_name, item.genre, kind === 'beat' && item.bpm ? `${item.bpm} BPM` : ''].filter(Boolean).join(', ');
        return `<div class="d-cell-media">${ui.thumb(item.cover_url, item.title)}<div><div class="d-cell-title">${esc(item.title)}</div><div class="d-cell-sub">${esc(sub)}</div>${item.status === 'rejected' && item.review_note ? `<p class="d-note"><strong>Why it was rejected:</strong> ${esc(item.review_note)}</p>` : ''}</div></div>`;
    }

    /** Favourites, saves, shares and downloads of a song, as one short cell. */
    const count = (n, one, many) => `${num(n || 0)} ${Number(n) === 1 ? one : many}`;
    function engagement(r) {
        return `<div class="d-engage"><span>${count(r.likes, 'favourite', 'favourites')}</span><span>${count(r.saves, 'save', 'saves')}</span>`
            + `<span>${count(r.shares, 'share', 'shares')}</span><span>${count(r.downloads, 'download', 'downloads')}</span></div>`;
    }

    /** opts: { admin, showOwner, engagement, extra: [columns] } */
    function table(kind, rows, opts = {}) {
        const beat = kind === 'beat';
        const columns = [
            { label: beat ? 'Beat' : 'Song', main: true, cell: r => titleCell(r, kind, opts.showOwner) },
            { label: 'Status', cell: r => `<div style="display:grid;gap:6px;justify-items:start">${ui.badge(r.status)}${opts.admin ? '' : ui.steps(r.status)}</div>` },
            { label: beat ? 'Plays' : 'Streams', num: true, cell: r => num(r.plays) },
            ...(opts.engagement && !beat ? [{ label: 'Engagement', cell: r => engagement(r) }] : []),
            ...(opts.extra || []),
            { label: beat ? 'From' : 'Price', num: true, cell: r => beat ? (r.price_from ? money(r.price_from) : '<span class="d-muted">No licence</span>') : (Number(r.price) > 0 ? money(r.price) : '<span class="d-muted">Stream only</span>') },
            { label: 'Submitted', cell: r => esc(date(r.submitted_at || r.created_at)) },
            { label: '', actions: true, cell: r => actions(kind, r, opts) }
        ];
        return ui.table({
            columns, rows,
            empty: ui.empty({ title: beat ? 'No beats here' : 'No songs here', text: opts.emptyText || '', action: opts.emptyAction || '' })
        });
    }

    function actions(kind, r, opts) {
        const b = [];
        const id = esc(r.id);
        b.push(`<button class="d-btn d-btn--sm" type="button" data-media="listen" data-id="${id}">Listen</button>`);
        if (opts.admin && r.status === 'in_review') {
            b.push(`<button class="d-btn d-btn--sm d-btn--good" type="button" data-media="approve" data-id="${id}">Approve</button>`);
            b.push(`<button class="d-btn d-btn--sm d-btn--bad" type="button" data-media="reject" data-id="${id}">Reject</button>`);
        }
        if (!opts.admin && ['rejected', 'in_review'].includes(r.status)) {
            b.push(`<button class="d-btn d-btn--sm${r.status === 'rejected' ? ' d-btn--primary' : ''}" type="button" data-media="resubmit" data-id="${id}">${r.status === 'rejected' ? 'Fix and resubmit' : 'Update submission'}</button>`);
        }
        if (r.status !== 'removed' && r.status !== 'sold_exclusive') {
            b.push(`<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-media="edit" data-id="${id}">Edit</button>`);
            b.push(`<button class="d-btn d-btn--sm d-btn--ghost" type="button" data-media="remove" data-id="${id}">${opts.admin ? 'Take down' : 'Remove'}</button>`);
        }
        return `<div class="d-btn-row">${b.join('')}</div>`;
    }

    /** Wires the buttons from table(); rows are the items shown. */
    function bind(view, kind, rows, opts = {}) {
        const beat = kind === 'beat';
        const base = beat ? '/beats' : '/tracks';
        const label = beat ? 'beat' : 'song';
        const byId = id => rows.find(r => String(r.id) === String(id));
        view.addEventListener('click', async event => {
            const btn = event.target.closest('[data-media]');
            if (!btn) return;
            const item = byId(btn.dataset.id);
            if (!item) return;
            const action = btn.dataset.media;
            if (action === 'listen') return listen(kind, item);
            if (action === 'approve') return ui.act(btn, () => api.post(`/admin/${beat ? 'beats' : 'tracks'}/${item.id}/review`, { decision: 'approve' }), `"${item.title}" is approved and now public.`);
            if (action === 'reject') {
                const note = await ui.ask({ title: `Reject "${item.title}"`, text: `The ${opts.ownerLabel || 'creator'} sees this reason, fixes the ${label} and resubmits it.`, label: 'What needs to change', submit: 'Reject', placeholder: 'For example: the cover has a watermark, please upload the clean artwork.' });
                if (note) ui.act(btn, () => api.post(`/admin/${beat ? 'beats' : 'tracks'}/${item.id}/review`, { decision: 'reject', note }), `"${item.title}" was rejected. The reason was sent.`);
                return;
            }
            if (action === 'remove') {
                const ok = await ui.confirm({ title: opts.admin ? `Take down "${item.title}"?` : `Remove "${item.title}"?`, text: `It disappears from the site. Past sales and downloads stay valid.`, submit: opts.admin ? 'Take down' : 'Remove', tone: 'bad' });
                if (ok) ui.act(btn, () => api.delete(`${base}/${item.id}`), `"${item.title}" was removed.`);
                return;
            }
            const meta = Dash.state.meta;
            if (action === 'edit') {
                // fetch the full record (licences, description) before editing
                const full = await api.get(`${base}/${item.id}`).catch(() => item);
                ui.formModal({
                    title: `Edit "${item.title}"`,
                    submit: 'Save changes',
                    intro: item.status === 'live' ? 'Changes to the details show on the site straight away.' : '',
                    fields: (beat ? beatFields({ item: full, meta, mode: 'edit' }) : songFields({ item: full, meta, mode: 'edit' }))
                        + `<fieldset class="d-fieldset"><legend>Cover artwork</legend>${ui.field({ label: 'Replace the cover', name: 'cover', type: 'file', maxMb: limits(meta).image, attrs: 'accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"', optional: true })}</fieldset>`,
                    async onSubmit(form, close) {
                        const data = values(form, ['description', 'release_date', 'mood', 'language', 'tags']);
                        if (beat) data.tiers = readTiers(form);
                        if (!beat) {
                            data.price = Number(data.price || 0);
                            data.allow_download = form.querySelector('[name="allow_download"]').checked;
                        }
                        await api.patch(`${base}/${item.id}`, data);
                        const cover = form.querySelector('[name="cover"]').files[0];
                        if (cover) {
                            const fd = new FormData();
                            fd.append('cover', cover);
                            await api.upload(`${base}/${item.id}/cover`, fd);
                        }
                        close();
                        core.toast('Changes saved.');
                        Dash.refresh();
                    }
                });
                return;
            }
            if (action === 'resubmit') {
                const full = await api.get(`${base}/${item.id}`).catch(() => item);
                ui.formModal({
                    title: item.status === 'rejected' ? `Fix and resubmit "${item.title}"` : `Update "${item.title}"`,
                    submit: 'Send for review',
                    intro: item.review_note ? `<strong>Reviewer's note:</strong> ${esc(item.review_note)}` : 'Your changes go back to the review queue.',
                    fields: beat ? beatFields({ item: full, meta, mode: 'resubmit' }) : songFields({ item: full, meta, mode: 'resubmit' }),
                    async onSubmit(form, close) {
                        const fd = formData(form);
                        if (beat) fd.set('tiers', JSON.stringify(readTiers(form)));
                        else fd.set('allow_download', form.querySelector('[name="allow_download"]').checked ? '1' : '0');
                        await api.upload(`${base}/${item.id}/resubmit`, fd);
                        close();
                        core.toast(`"${fd.get('title') || item.title}" was sent for review again.`);
                        Dash.refresh();
                        Dash.recount();
                    }
                });
            }
        });
    }

    /** Plays any song or beat the person may hear: public ones stream, unpublished ones use a private link. */
    async function listen(kind, item) {
        let url = item.listen_url;
        if (!url) {
            try {
                const full = await api.get(`/${kind === 'beat' ? 'beats' : 'tracks'}/${item.id}`);
                url = full.listen_url || (kind === 'beat' ? full.preview_url : full.stream_url);
            } catch (error) {
                core.toast(error.message, 'error');
                return;
            }
        }
        ui.modal({
            title: item.title,
            body: `${ui.audio(url)}${item.status !== 'live' ? '<p class="d-muted">Private preview. Only you and the review team can hear this until it is approved.</p>' : ''}`,
            onOpen(dialog) { dialog.querySelector('audio')?.play().catch(() => {}); }
        });
    }

    window.Dash.media = { songFields, beatFields, createSong, createBeat, readTiers, tierProblems, UPLOAD_TITLES, formData, values, owners, table, bind, listen, titleCell, engagement };
})();
