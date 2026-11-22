/*
 * Nile Vijana dashboard — shell, router and shared components.
 *
 * One page (dashboard/index.html) serves every account type. Screens live in
 * admin.js, creator.js, studio.js and account.js and register themselves here:
 *
 *   Dash.route('/music/songs', { title: 'My songs', roles: ['artist', 'choir'], render: async ctx => { ... } });
 *
 * Routes are hash based (#/music/songs?status=rejected), so the page works on
 * any Apache/XAMPP setup without server rewrites. The sidebar only shows what
 * the signed-in account can use; the API enforces the same rules.
 */
(function () {
    'use strict';

    const core = window.NVCore;
    const { esc, num, money, compact, date, dateTime, ago, list, obj } = core;
    const ROOT = core.ROOT;

    /* ------------------------------------------------------------------ icons */
    const PATHS = {
        home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
        upload: 'M12 16V4m0 0-5 5m5-5 5 5M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3',
        userPlus: 'M15 19a6 6 0 0 0-12 0M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8m10-3v6m3-3h-6',
        review: 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9',
        music: 'M9 18V5l12-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0m12-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        beat: 'M3 12h3l3-8 4 16 3-8h5',
        studio: 'M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3m7 9a7 7 0 0 1-14 0m7 7v4m-4 0h8',
        calendar: 'M8 2v4m8-4v4M3 9h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2',
        users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8m14 10v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
        wallet: 'M20 7H5a2 2 0 0 1 0-4h13v4m2 0v13H5a2 2 0 0 1-2-2V5m14 9h.01',
        chart: 'M3 3v18h18M7 15l4-4 3 3 5-6',
        profile: 'M20 21a8 8 0 0 0-16 0m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        coach: 'M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8m-7 7a7 7 0 0 1 14 0M19 4l2 2-2 2',
        briefcase: 'M10 6V4h4v2M3 7h18v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zm0 5h18',
        payout: 'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
        menu: 'M4 6h16M4 12h16M4 18h16',
        close: 'M6 6l12 12M18 6 6 18',
        check: 'M5 12l5 5L20 7',
        x: 'M6 6l12 12M18 6 6 18',
        alert: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0',
        info: 'M12 16v-4m0-4h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        cart: 'M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 1.9-1.4L21 9H6',
        star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z',
        user: 'M20 21a8 8 0 0 0-16 0m8-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        door: 'M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5M10 17l5-5-5-5m5 5H3',
        sparkle: 'M12 3v4m0 10v4m9-9h-4M7 12H3m14.4-6.4-2.8 2.8M9.4 14.6l-2.8 2.8m0-11.6 2.8 2.8m5.2 5.2 2.8 2.8',
        help: 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3m.1 4h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        receipt: 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4',
        spotify: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20M7 9.5c3.5-1 7-.6 10 1M7.5 12.8c3-.8 6-.4 8.5.9M8 16c2.4-.6 4.6-.3 6.6.7'
    };
    const icon = name => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="${PATHS[name] || PATHS.info}"/></svg>`;

    /* ------------------------------------------------------------------ status labels */
    const STATUS = {
        live: ['Live', 'good'], verified: ['Verified', 'good'], confirmed: ['Confirmed', 'good'], completed: ['Completed', 'good'],
        paid: ['Paid', 'good'], active: ['Active', 'good'], sold_exclusive: ['Sold (exclusive)', 'info'],
        in_review: ['In review', 'warn'], pending: ['Waiting', 'warn'], requested: ['Requested', 'warn'], processing: ['Processing', 'info'],
        held: ['Awaiting deposit', 'info'],
        rejected: ['Rejected', 'bad'], failed: ['Failed', 'bad'], cancelled: ['Cancelled', 'muted'], removed: ['Removed', 'muted'],
        suspended: ['Suspended', 'bad'], expired: ['Expired', 'muted'], no_show: ['No-show', 'bad'], inactive: ['Inactive', 'muted'],
        refunded: ['Refunded', 'muted'], declined: ['Declined', 'bad'], open: ['Open', 'warn'], resolved: ['Resolved', 'good']
    };
    const PROFILE_TYPES = {
        artist: 'Artist', choir: 'Choir', producer: 'Music producer', both: 'Artist and producer',
        manager: 'Artist manager', vocal_coach: 'Vocal coach', studio: 'Studio'
    };

    /* ------------------------------------------------------------------ components */
    const ui = {
        icon, esc, num, money, compact, date, dateTime, ago, PROFILE_TYPES,

        badge(status, label) {
            const [text, tone] = STATUS[status] || [String(status || '').replace(/_/g, ' '), 'muted'];
            return `<span class="d-badge d-badge--${tone}">${esc(label || text)}</span>`;
        },

        /** Submitted -> In review -> Live (or Rejected) */
        steps(status) {
            const order = ['submitted', 'in_review', 'live'];
            if (status === 'rejected') {
                return '<span class="d-steps" aria-label="Approval: rejected"><span class="is-done">Submitted</span><i></i><span class="is-done">Reviewed</span><i></i><span class="is-bad">Rejected</span></span>';
            }
            if (status === 'removed') return '<span class="d-steps"><span class="is-bad">Taken down</span></span>';
            const at = status === 'live' || status === 'sold_exclusive' ? 2 : status === 'processing' ? 0 : 1;
            const names = ['Submitted', 'In review', 'Live'];
            return `<span class="d-steps" aria-label="Approval: ${esc(names[at])}">${order.map((_, i) => `${i ? '<i></i>' : ''}<span class="${i < at ? 'is-done' : i === at ? (at === 2 ? 'is-done' : 'is-now') : ''}">${names[i]}</span>`).join('')}</span>`;
        },

        stat({ label, value, hint = '', href = '', tone = '' }) {
            const tag = href ? 'a' : 'div';
            return `<${tag} class="d-stat${tone ? ` d-stat--${tone}` : ''}"${href ? ` href="${esc(href)}"` : ''}><span class="d-stat-label">${esc(label)}</span><strong class="d-stat-value">${value}</strong>${hint ? `<span class="d-stat-hint">${hint}</span>` : ''}</${tag}>`;
        },

        panel({ title = '', sub = '', actions = '', body = '', flush = false, foot = '', cls = '', id = '' }) {
            return `<section class="d-panel ${cls}"${id ? ` id="${esc(id)}"` : ''}>${title ? `<header class="d-panel-head"><h2>${esc(title)}</h2>${actions ? `<div class="d-panel-actions">${actions}</div>` : ''}${sub ? `<p>${sub}</p>` : ''}</header>` : ''}<div class="d-panel-body${flush ? ' d-panel-body--flush' : ''}">${body}</div>${foot ? `<footer class="d-panel-foot">${foot}</footer>` : ''}</section>`;
        },

        empty({ title, text = '', action = '' }) {
            return `<div class="d-empty"><h3>${esc(title)}</h3>${text ? `<p>${text}</p>` : ''}${action}</div>`;
        },

        notice({ tone = 'warn', title = '', text = '', action = '' }) {
            const ic = tone === 'good' ? 'check' : tone === 'bad' ? 'alert' : tone === 'info' ? 'info' : 'alert';
            return `<div class="d-notice d-notice--${tone}" role="status">${icon(ic)}<div>${title ? `<p><strong>${esc(title)}</strong></p>` : ''}${text ? `<p>${text}</p>` : ''}</div>${action}</div>`;
        },

        thumb(url, title) {
            return url ? `<img class="d-thumb" src="${esc(url)}" alt="" loading="lazy">` : `<span class="d-thumb" aria-hidden="true">${esc(String(title || '?').slice(0, 2).toUpperCase())}</span>`;
        },

        /** columns: [{ label, cell(row), num, main, actions }] — stacks into cards on phones */
        table({ columns, rows, empty = '', cls = '' }) {
            if (!rows.length) return empty || ui.empty({ title: 'Nothing here yet' });
            const head = columns.map(c => `<th scope="col"${c.num ? ' class="is-num"' : c.actions ? ' class="is-actions"' : ''}>${c.actions && !c.label ? '<span class="d-visually-hidden">Actions</span>' : esc(c.label)}</th>`).join('');
            const body = rows.map(row => `<tr>${columns.map(c => `<td data-label="${esc(c.label || '')}" class="${c.num ? 'is-num' : ''}${c.main ? ' is-main' : ''}${c.actions ? ' is-actions' : ''}">${c.cell(row)}</td>`).join('')}</tr>`).join('');
            return `<div class="d-table-wrap"><table class="d-table d-table--stack ${cls}"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
        },

        /** items: [{ label, href, current, count }] */
        tabs(items) {
            return `<nav class="d-tabs" aria-label="Filter">${items.map(t => `<a href="${esc(t.href)}"${t.current ? ' aria-current="page"' : ''}>${esc(t.label)}${t.count ? `<span class="d-nav-count">${num(t.count)}</span>` : ''}</a>`).join('')}</nav>`;
        },

        /** Date-range presets that scope everything on the page. */
        range(ctx) {
            const current = Number(ctx.params.get('range') || 30);
            const opts = [[7, '7 days'], [30, '30 days'], [90, '90 days'], [365, '12 months']];
            return `<div class="d-segment" role="group" aria-label="Time range">${opts.map(([days, label]) => `<a href="${esc(ctx.link({ range: days }))}" aria-current="${days === current}">${label}</a>`).join('')}</div>`;
        },

        audio(url) {
            return url ? `<audio controls preload="none" src="${esc(url)}"></audio>` : '<span class="d-muted">Audio not available</span>';
        },

        /* ---------- form fields ---------- */
        /** missing: message when a required field is empty ("Track title is missing."); maxMb: file size limit */
        field({ label, name, type = 'text', value = '', required = false, hint = '', options = null, attrs = '', wide = false, optional = false, placeholder = '', missing = '', maxMb = 0 }) {
            const req = (required ? ' required' : '') + ` data-label="${esc(label)}"` + (missing ? ` data-missing="${esc(missing)}"` : '') + (maxMb ? ` data-max-mb="${esc(maxMb)}"` : '');
            const ph = placeholder ? ` placeholder="${esc(placeholder)}"` : '';
            let control;
            if (options) {
                control = `<select name="${esc(name)}"${req} ${attrs}>${options.map(o => {
                    const [v, l] = Array.isArray(o) ? o : [o, o];
                    return `<option value="${esc(v)}"${String(v) === String(value ?? '') ? ' selected' : ''}>${esc(l)}</option>`;
                }).join('')}</select>`;
            } else if (type === 'textarea') {
                control = `<textarea name="${esc(name)}"${req}${ph} ${attrs}>${esc(value ?? '')}</textarea>`;
            } else if (type === 'checkbox') {
                return `<label class="d-check${wide ? ' d-field--wide' : ''}" data-field="${esc(name)}"><input type="checkbox" name="${esc(name)}" value="1"${value ? ' checked' : ''}${req} ${attrs}> ${esc(label)}</label>`;
            } else {
                control = `<input type="${esc(type)}" name="${esc(name)}"${type === 'file' ? '' : ` value="${esc(value ?? '')}"`}${req}${ph} ${attrs}>`;
            }
            return `<label class="d-field${wide ? ' d-field--wide' : ''}" data-field="${esc(name)}"><span>${esc(label)}${optional ? ' <em>(optional)</em>' : required ? ' <b class="d-req" aria-hidden="true">*</b>' : ''}</span>${control}${hint ? `<small>${hint}</small>` : ''}</label>`;
        },

        genreOptions(meta, current, blank = 'Choose a genre') {
            return [['', blank], ...(meta.genres || []).map(g => [g, g])].concat(current && !(meta.genres || []).includes(current) ? [[current, current]] : []);
        },

        /** Show errors: a summary at the top of the form listing every problem, and a note under each field. */
        showErrors(form, error, title) {
            core.forms.show(form, error, title ? { title } : {});
        },

        /**
         * Submit handler: checks the form first (required fields, file types and sizes),
         * blocks double submissions, then shows server errors the same way.
         * options.title: summary heading, e.g. "Your track could not be uploaded. Please complete the following:"
         * options.check(form): extra checks returning { field: message }
         */
        onSubmit(form, handler, options = {}) {
            core.forms.guard(form, handler, options);
        },

        /** A thin progress bar for uploads; returns { update(fraction), done() }. */
        progress(form, label = 'Uploading') {
            let bar = form.querySelector('.d-progress');
            if (!bar) {
                bar = document.createElement('div');
                bar.className = 'd-progress';
                bar.innerHTML = '<div class="d-progress-track"><div class="d-progress-fill"></div></div><span class="d-progress-text" role="status" aria-live="polite"></span>';
                (form.querySelector('.d-form-foot') || form).before(bar);
            }
            bar.hidden = false;
            const fill = bar.querySelector('.d-progress-fill');
            const text = bar.querySelector('.d-progress-text');
            return {
                update(f) {
                    const pct = Math.round(Math.max(0, Math.min(1, f)) * 100);
                    fill.style.width = `${pct}%`;
                    text.textContent = pct < 100 ? `${label}… ${pct}%` : 'Upload complete. Saving…';
                },
                done() { bar.hidden = true; }
            };
        },

        /* ---------- dialogs ---------- */
        modal({ title, body, onOpen }) {
            const dialog = document.createElement('dialog');
            dialog.className = 'd-modal';
            dialog.innerHTML = `<div class="d-modal-head"><h2>${esc(title)}</h2><button type="button" class="d-btn d-btn--ghost d-btn--sm" data-close aria-label="Close">${icon('close')}</button></div><div class="d-modal-body">${body}</div>`;
            document.querySelector('.nv-dash').appendChild(dialog);
            const close = () => { dialog.close(); dialog.remove(); };
            dialog.addEventListener('click', e => { if (e.target === dialog || e.target.closest('[data-close]')) close(); });
            dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
            dialog.showModal();
            if (onOpen) onOpen(dialog, close);
            return { dialog, close };
        },

        /** Modal form. onSubmit(form, close) may throw an API error (shown inline). */
        formModal({ title, fields, submit = 'Save', tone = 'primary', onSubmit, intro = '' }) {
            return ui.modal({
                title,
                body: `${intro ? `<p class="d-intro">${intro}</p>` : ''}<form class="d-form" novalidate>${fields}<div class="d-form-foot"><button class="d-btn d-btn--${tone}" type="submit">${esc(submit)}</button><button class="d-btn d-btn--ghost" type="button" data-close>Cancel</button></div></form>`,
                onOpen(dialog, close) {
                    const form = dialog.querySelector('form');
                    ui.onSubmit(form, async f => { await onSubmit(f, close); });
                    form.querySelector('input:not([type=hidden]), textarea, select')?.focus();
                }
            });
        },

        /** Ask for a reason (reject, decline). Resolves with the text, or null when cancelled. */
        ask({ title, text = '', label = 'Reason', submit = 'Send', min = 5, placeholder = '' }) {
            return new Promise(resolve => {
                let done = false;
                const m = ui.formModal({
                    title, intro: text, submit, tone: 'bad',
                    fields: ui.field({ label, name: 'reason', type: 'textarea', required: true, placeholder, hint: `This is shown to the person, so be specific. At least ${min} characters.` }),
                    async onSubmit(form, close) {
                        const value = form.reason.value.trim();
                        if (value.length < min) {
                            const err = new Error('Write a little more so they know what to change.');
                            err.errors = { reason: `At least ${min} characters` };
                            throw err;
                        }
                        done = true;
                        close();
                        resolve(value);
                    }
                });
                m.dialog.addEventListener('close', () => { if (!done) resolve(null); });
            });
        },

        confirm({ title, text = '', submit = 'Confirm', tone = 'primary' }) {
            return new Promise(resolve => {
                let done = false;
                const m = ui.formModal({ title, intro: text, submit, tone, fields: '', async onSubmit(form, close) { done = true; close(); resolve(true); } });
                m.dialog.addEventListener('close', () => { if (!done) resolve(false); });
            });
        },

        /** Runs an action from a button: busy state, toast on success, refresh after. */
        async act(button, fn, success) {
            if (button) button.disabled = true;
            try {
                await fn();
                if (success) core.toast(success);
                Dash.refresh();
            } catch (error) {
                core.toast(error.message || 'That did not work. Try again.', 'error');
                if (button) button.disabled = false;
            }
        },

        /* ---------- charts (SVG, no library) ---------- */
        chart: {}
    };

    /* --- chart helpers: one axis, hairline grid, 2px lines, <=24px bars with 4px rounded tops,
           hover tooltip on every chart, and a table view under each one. --- */
    const niceMax = max => {
        if (max <= 0) return 4;
        const pow = Math.pow(10, Math.floor(Math.log10(max)));
        const steps = [1, 2, 2.5, 5, 10];
        for (const s of steps) if (max <= s * pow) return s * pow;
        return 10 * pow;
    };
    const svgEl = (tag, attrs = {}) => {
        const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
        return el;
    };
    const COLORS = ['var(--d-s1)', 'var(--d-s2)', 'var(--d-s3)'];

    /* Charts are drawn at their real pixel width (so text stays 11px), which needs the element
       on the page. Screens render off-page first, so drawing waits until the view is attached
       and repeats when the panel is resized. */
    const pendingCharts = [];
    function whenAttached(el, draw) {
        if (!el) return;
        if (el.isConnected) { draw(); watch(el, draw); } else pendingCharts.push([el, draw]);
    }
    function flushCharts() {
        while (pendingCharts.length) {
            const [el, draw] = pendingCharts.shift();
            if (el.isConnected) { draw(); watch(el, draw); }
        }
    }
    function watch(el, draw) {
        if (!('ResizeObserver' in window)) return;
        let width = el.clientWidth;
        let frame = 0;
        new ResizeObserver(() => {
            if (Math.abs(el.clientWidth - width) < 24) return;
            width = el.clientWidth;
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(draw);
        }).observe(el);
    }

    function chartFrame(el, { labels, series, format = num, height = 220 }) {
        el.innerHTML = '';
        el.classList.add('d-chart');
        const W = Math.max(280, Math.round(el.clientWidth || 640)), H = height, L = 46, R = 14, T = 12, B = 26;
        const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, role: 'img' });
        const tip = document.createElement('div');
        tip.className = 'd-chart-tip';
        tip.hidden = true;
        if (series.length > 1) {
            const legend = document.createElement('div');
            legend.className = 'd-legend';
            series.forEach((s, i) => {
                const item = document.createElement('span');
                const key = document.createElement('i');
                key.style.background = s.color || COLORS[i];
                if (s.kind === 'line') key.className = 'is-line';
                item.append(key, document.createTextNode(s.name));
                legend.appendChild(item);
            });
            el.appendChild(legend);
        }
        el.append(svg, tip);
        // table view: every value reachable without hovering
        const details = document.createElement('details');
        details.className = 'd-chart-table';
        const summary = document.createElement('summary');
        summary.textContent = 'Show as table';
        const table = document.createElement('table');
        table.className = 'd-table';
        const thead = table.createTHead().insertRow();
        [''].concat(series.map(s => s.name)).forEach(h => { const th = document.createElement('th'); th.textContent = h; thead.appendChild(th); });
        const tbody = table.createTBody();
        labels.forEach((label, i) => {
            const row = tbody.insertRow();
            row.insertCell().textContent = label;
            series.forEach(s => { const c = row.insertCell(); c.className = 'is-num'; c.textContent = format(s.values[i] || 0); });
        });
        details.append(summary, table);
        el.appendChild(details);
        return { svg, tip, W, H, L, R, T, B };
    }

    function axis(svg, { W, H, L, R, T, B }, max, format) {
        const grid = svgEl('g', { class: 'grid' });
        const ax = svgEl('g', { class: 'axis' });
        for (let i = 0; i <= 4; i++) {
            const v = max * i / 4;
            const y = T + (H - T - B) * (1 - i / 4);
            grid.appendChild(svgEl('line', { x1: L, x2: W - R, y1: y, y2: y }));
            const t = svgEl('text', { x: L - 8, y: y + 4, 'text-anchor': 'end' });
            t.textContent = format === money ? compact(v) : compact(v);
            ax.appendChild(t);
        }
        svg.append(grid, ax);
        return ax;
    }

    function xLabels(ax, labels, xAt, f) {
        const fit = Math.max(2, Math.floor((f.W - f.L) / 64));      // about one label per 64px
        const every = Math.ceil(labels.length / fit);
        labels.forEach((label, i) => {
            if (i % every && i !== labels.length - 1) return;
            if (i !== labels.length - 1 && labels.length - 1 - i < every / 2) return;   // keep the last label clear
            const t = svgEl('text', { x: xAt(i), y: f.H - f.B + 16, 'text-anchor': 'middle' });
            t.textContent = label;
            ax.appendChild(t);
        });
    }

    function showTip(el, tip, x, y, when, rows) {
        tip.replaceChildren();
        const w = document.createElement('div');
        w.className = 'when';
        w.textContent = when;
        tip.appendChild(w);
        rows.forEach(r => {
            const row = document.createElement('div');
            row.className = 'row';
            if (rows.length > 1) { const key = document.createElement('i'); key.style.background = r.color; row.appendChild(key); }
            const b = document.createElement('b');
            b.textContent = r.value;
            row.appendChild(b);
            if (rows.length > 1) { const n = document.createElement('span'); n.textContent = r.name; row.appendChild(n); }
            tip.appendChild(row);
        });
        tip.hidden = false;
        const box = el.getBoundingClientRect();
        const left = Math.min(Math.max(8, x - tip.offsetWidth / 2), box.width - tip.offsetWidth - 8);
        tip.style.left = `${left}px`;
        tip.style.top = `${Math.max(0, y - tip.offsetHeight - 12)}px`;
    }

    /** Vertical bars; several series are stacked with a 2px gap between segments. */
    ui.chart.bars = (el, opts) => whenAttached(el, () => drawBars(el, opts));
    function drawBars(el, { labels, series, format = num, height = 220 }) {
        const f = chartFrame(el, { labels, series, format, height });
        const totals = labels.map((_, i) => series.reduce((s, x) => s + Number(x.values[i] || 0), 0));
        const max = niceMax(Math.max(...totals, 0));
        const ax = axis(f.svg, f, max, format);
        const band = (f.W - f.L - f.R) / labels.length;
        const bw = Math.min(24, band * 0.62);
        const xAt = i => f.L + band * i + band / 2;
        const yAt = v => f.T + (f.H - f.T - f.B) * (1 - v / max);
        labels.forEach((label, i) => {
            let base = 0;
            const g = svgEl('g', { tabindex: 0, class: 'bar', 'aria-label': `${label}: ${series.map(s => `${s.name} ${format(s.values[i] || 0)}`).join(', ')}` });
            const visible = series.filter(s => Number(s.values[i] || 0) > 0);
            series.forEach((s, si) => {
                const v = Number(s.values[i] || 0);
                if (v <= 0) return;
                const y0 = yAt(base), y1 = yAt(base + v);
                const top = visible[visible.length - 1] === s;
                const h = Math.max(1, y0 - y1 - (base > 0 ? 2 : 0));       // 2px surface gap between stacked parts
                const x = xAt(i) - bw / 2, y = y1;
                const r = top ? Math.min(4, h) : 0;
                const d = `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + bw - r} Q${x + bw},${y} ${x + bw},${y + r} V${y + h} Z`;
                g.appendChild(svgEl('path', { d, fill: s.color || COLORS[si] }));
                base += v;
            });
            // generous hit target: the whole band
            g.appendChild(svgEl('rect', { x: f.L + band * i, y: f.T, width: band, height: f.H - f.T - f.B, fill: 'transparent' }));
            const show = () => {
                const box = el.getBoundingClientRect();
                const scale = box.width / f.W;
                showTip(el, f.tip, xAt(i) * scale, yAt(totals[i]) * scale + (el.querySelector('.d-legend')?.offsetHeight || 0), label,
                    series.map((s, si) => ({ name: s.name, value: format(s.values[i] || 0), color: s.color || COLORS[si] })));
            };
            g.addEventListener('pointerenter', show);
            g.addEventListener('focus', show);
            g.addEventListener('pointerleave', () => { f.tip.hidden = true; });
            g.addEventListener('blur', () => { f.tip.hidden = true; });
            f.svg.appendChild(g);
        });
        xLabels(ax, labels, xAt, f);
    }

    /** Line chart with a soft area wash, an end dot and a crosshair tooltip. */
    ui.chart.line = (el, opts) => whenAttached(el, () => drawLine(el, opts));
    function drawLine(el, { labels, series, format = num, height = 220 }) {
        const f = chartFrame(el, { labels, series: series.map(s => ({ ...s, kind: 'line' })), format, height });
        const max = niceMax(Math.max(0, ...series.flatMap(s => s.values.map(Number))));
        const ax = axis(f.svg, f, max, format);
        const step = labels.length > 1 ? (f.W - f.L - f.R) / (labels.length - 1) : 0;
        const xAt = i => f.L + step * i;
        const yAt = v => f.T + (f.H - f.T - f.B) * (1 - v / max);
        series.forEach((s, si) => {
            const color = s.color || COLORS[si];
            const pts = s.values.map((v, i) => `${xAt(i)},${yAt(Number(v) || 0)}`);
            if (series.length === 1) {
                f.svg.appendChild(svgEl('path', { d: `M${xAt(0)},${yAt(0)} L${pts.join(' L')} L${xAt(labels.length - 1)},${yAt(0)} Z`, fill: color, opacity: 0.1 }));
            }
            f.svg.appendChild(svgEl('path', { d: `M${pts.join(' L')}`, fill: 'none', stroke: color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
            const last = labels.length - 1;
            f.svg.appendChild(svgEl('circle', { cx: xAt(last), cy: yAt(Number(s.values[last]) || 0), r: 4, fill: color, stroke: 'var(--d-panel)', 'stroke-width': 2 }));
        });
        xLabels(ax, labels, xAt, f);
        const cross = svgEl('line', { class: 'cross', y1: f.T, y2: f.H - f.B, x1: 0, x2: 0, visibility: 'hidden' });
        f.svg.appendChild(cross);
        const hit = svgEl('rect', { x: f.L, y: f.T, width: f.W - f.L - f.R, height: f.H - f.T - f.B, fill: 'transparent', tabindex: 0, 'aria-label': 'Chart values; use the table below to read every value' });
        f.svg.appendChild(hit);
        const at = clientX => {
            const box = f.svg.getBoundingClientRect();
            const x = (clientX - box.left) * (f.W / box.width);
            return Math.max(0, Math.min(labels.length - 1, Math.round((x - f.L) / (step || 1))));
        };
        const show = i => {
            cross.setAttribute('x1', xAt(i));
            cross.setAttribute('x2', xAt(i));
            cross.setAttribute('visibility', 'visible');
            const scale = el.getBoundingClientRect().width / f.W;
            const top = Math.max(...series.map(s => Number(s.values[i]) || 0));
            showTip(el, f.tip, xAt(i) * scale, yAt(top) * scale + (el.querySelector('.d-legend')?.offsetHeight || 0), labels[i],
                series.map((s, si) => ({ name: s.name, value: format(s.values[i] || 0), color: s.color || COLORS[si] })));
        };
        let focusIndex = labels.length - 1;
        hit.addEventListener('pointermove', e => show(at(e.clientX)));
        const hide = () => { f.tip.hidden = true; cross.setAttribute('visibility', 'hidden'); };
        hit.addEventListener('pointerleave', hide);
        hit.addEventListener('focus', () => show(focusIndex));
        hit.addEventListener('blur', hide);
        hit.addEventListener('keydown', e => {
            if (e.key === 'ArrowLeft') { focusIndex = Math.max(0, focusIndex - 1); show(focusIndex); }
            if (e.key === 'ArrowRight') { focusIndex = Math.min(labels.length - 1, focusIndex + 1); show(focusIndex); }
        });
    }

    /** Horizontal bars as HTML (rankings, breakdowns). */
    ui.chart.hbars = (rows, format = num) => {
        if (!rows.length) return ui.empty({ title: 'No data for this period yet' });
        const max = Math.max(...rows.map(r => Number(r.value) || 0), 1);
        return `<div class="d-hbars">${rows.map(r => `<div class="d-hbar"><span>${esc(r.label)}</span><div class="d-hbar-track"><div class="d-hbar-fill" style="width:${Math.max(0.5, (Number(r.value) || 0) / max * 100)}%"></div></div><b>${esc(format(r.value))}</b></div>`).join('')}</div>`;
    };

    /* ------------------------------------------------------------------ router + shell */
    const routes = {};
    const state = { me: null, meta: {}, counts: {}, seq: 0 };

    /** Which sidebar sections an account sees (the API enforces the same rules). */
    function navFor(roles) {
        const has = r => roles.includes(r);
        const groups = [];
        if (has('admin')) {
            groups.push(['Admin', [
                ['/admin', 'Overview', 'home'], ['/admin/onboard', 'Onboard accounts', 'userPlus'], ['/admin/upload', 'Upload music or beats', 'upload'],
                ['/admin/review', 'Review queue', 'review', 'review'], ['/admin/music', 'Songs', 'music'], ['/admin/beats', 'Beats', 'beat'],
                ['/admin/studios', 'Studios', 'studio'], ['/admin/bookings', 'Studio bookings', 'calendar'], ['/admin/people', 'People', 'users'],
                ['/admin/payouts', 'Withdrawals', 'payout', 'payouts'], ['/admin/questions', 'Questions (Q&A)', 'help', 'questions'],
                ['/admin/spotify', 'Featured on Spotify', 'spotify']
            ]]);
        }
        if (has('artist') || has('choir')) {
            groups.push([has('choir') && !has('artist') ? 'Choir' : 'Music', [
                ['/music', 'Overview', 'home'], ['/music/songs', 'My songs', 'music', 'rejected_songs'], ['/music/upload', 'Upload a song', 'upload'], ['/music/analytics', 'Analytics', 'chart']
            ]]);
        }
        if (has('producer')) {
            groups.push(['Beats', [
                ['/beats', 'Overview', 'home'], ['/beats/list', 'My beats', 'beat', 'rejected_beats'], ['/beats/upload', 'Upload a beat', 'upload'], ['/beats/analytics', 'Analytics', 'chart']
            ]]);
        }
        if (has('studio_owner')) {
            groups.push(['Studio', [
                ['/studio', 'Overview', 'home'], ['/studio/bookings', 'Requests and bookings', 'calendar', 'requests'], ['/studio/rooms', 'Rooms and hours', 'door'], ['/studio/profile', 'Studio profile', 'studio']
            ]]);
        }
        if (has('manager')) groups.push(['Management', [['/manager', 'My artists', 'briefcase']]]);
        if (has('vocal_coach')) groups.push(['Coaching', [['/coach', 'Coaching sessions', 'coach']]]);
        const earns = ['artist', 'choir', 'producer', 'studio_owner'].some(has);
        const account = [];
        if (!groups.length) account.push(['/start', 'Get started', 'sparkle']);
        if (earns) account.push(['/earnings', 'Earnings and wallet', 'wallet']);
        account.push(['/my-bookings', 'My bookings', 'calendar'], ['/payments', 'Payment history', 'receipt'], ['/my-questions', 'My questions', 'help'], ['/profile', 'Profile and settings', 'profile']);
        if (!earns && !has('admin')) account.splice(1, 0, ['/earnings', 'Wallet', 'wallet']);
        groups.push(['Account', account]);
        return groups;
    }

    function homeFor(roles) {
        const has = r => roles.includes(r);
        if (has('admin')) return '/admin';
        if (has('artist') || has('choir')) return '/music';
        if (has('producer')) return '/beats';
        if (has('studio_owner')) return '/studio';
        if (has('manager')) return '/manager';
        if (has('vocal_coach')) return '/coach';
        return '/start';
    }

    /** Shortcuts used by links from the public site and the old dashboard pages. */
    function alias(path, roles) {
        const has = r => roles.includes(r);
        if (path === '' || path === '/') return homeFor(roles);
        if (path === '/upload') return has('admin') ? '/admin/upload' : (has('artist') || has('choir')) ? '/music/upload' : has('producer') ? '/beats/upload' : '/start';
        if (path === '/analytics') return (has('artist') || has('choir')) ? '/music/analytics' : has('producer') ? '/beats/analytics' : has('studio_owner') ? '/studio' : homeFor(roles);
        if (path === '/bookings') return has('studio_owner') ? '/studio/bookings' : '/my-bookings';
        return null;
    }

    function parseHash() {
        const raw = location.hash.replace(/^#/, '');
        const [path, qs] = raw.split('?');
        return { path: (path || '/').replace(/\/+$/, '') || '/', params: new URLSearchParams(qs || '') };
    }

    function link(path, params) {
        const q = new URLSearchParams();
        Object.entries(params || {}).forEach(([k, v]) => { if (v !== '' && v != null) q.set(k, v); });
        const s = q.toString();
        return `#${path}${s ? `?${s}` : ''}`;
    }

    function shell() {
        const me = state.me;
        const u = me.user;
        const roles = u.roles || [];
        const profile = me.creator_profile;
        const display = (profile && profile.stage_name) || u.name || 'Account';
        const avatar = profile && profile.avatar_url ? `<img src="${esc(profile.avatar_url)}" alt="">` : esc(display.slice(0, 2).toUpperCase());
        const nav = navFor(roles).map(([title, items]) => `<div class="d-nav-group"><h2>${esc(title)}</h2><ul>${items.map(([path, label, ic, count]) => `<li><a href="#${path}" data-path="${path}">${icon(ic)}<span>${esc(label)}</span>${count ? `<span class="d-nav-count" data-count="${count}" hidden></span>` : ''}</a></li>`).join('')}</ul></div>`).join('');
        const roleNames = roles.filter(r => r !== 'fan').map(r => ({ admin: 'Admin', artist: 'Artist', choir: 'Choir', producer: 'Producer', studio_owner: 'Studio owner', manager: 'Manager', vocal_coach: 'Vocal coach' })[r] || r);
        document.body.innerHTML = `<div class="nv-dash"><div class="d-app">
            <aside class="d-side" id="d-side" aria-label="Dashboard navigation">
                <a class="d-brand" href="${ROOT}index.html" aria-label="Nile Vijana home"><img src="${ROOT}assets/images/main_logo.svg" alt="Nile Vijana"></a>
                <nav class="d-nav">${nav}</nav>
                <div class="d-side-foot">Signed in as <strong>${esc(u.name || display)}</strong><br>${esc(roleNames.join(', ') || 'Listener')}</div>
            </aside>
            <div class="d-scrim" data-menu-close></div>
            <div class="d-main">
                <header class="d-top">
                    <button class="d-btn d-btn--ghost d-menu-btn" type="button" aria-controls="d-side" aria-expanded="false" aria-label="Open menu">${icon('menu')}</button>
                    <h1 id="d-title">Dashboard</h1>
                    <div class="d-top-actions"><div id="d-actions" class="d-btn-row"></div>
                        <div class="d-account"><button class="d-account-btn" type="button" aria-haspopup="true" aria-expanded="false"><span class="d-avatar">${avatar}</span><span class="d-account-name">${esc(display)}</span></button>
                        <div class="d-account-menu" hidden><p><strong>${esc(u.name || display)}</strong>${esc(u.email || u.phone || '')}</p><a href="${ROOT}index.html">View the website</a><a href="${ROOT}orders.html">My orders</a><a href="#/profile">Profile and settings</a><a href="${ROOT}auth/logout.html">Sign out</a></div></div>
                    </div>
                </header>
                <main class="d-content" id="d-content" tabindex="-1"><div class="d-loading">Loading…</div></main>
            </div>
        </div><div id="toast-container" class="toast-container"></div></div>`;

        if (state.wired) return;                                // page-level listeners are added once
        state.wired = true;
        const setMenu = open => {
            document.querySelector('.d-app')?.classList.toggle('is-menu-open', open);
            document.querySelector('.d-menu-btn')?.setAttribute('aria-expanded', String(open));
        };
        document.addEventListener('click', e => {
            // a link to the page you are on reloads it (e.g. "Onboard accounts" after a success message)
            const same = e.target.closest('a[href^="#"]');
            if (same && same.getAttribute('href') === location.hash) render();
            if (e.target.closest('.d-menu-btn')) setMenu(!document.querySelector('.d-app').classList.contains('is-menu-open'));
            if (e.target.closest('[data-menu-close], .d-nav a')) setMenu(false);
            const accountBtn = e.target.closest('.d-account-btn');
            const menu = document.querySelector('.d-account-menu');
            if (!menu) return;
            if (accountBtn) {
                menu.hidden = !menu.hidden;
                accountBtn.setAttribute('aria-expanded', String(!menu.hidden));
            } else if (!e.target.closest('.d-account-menu')) {
                menu.hidden = true;
            }
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape' || document.querySelector('dialog[open]')) return;
            setMenu(false);
            const menu = document.querySelector('.d-account-menu');
            if (menu) menu.hidden = true;
        });
    }

    function setCount(key, value) {
        state.counts[key] = value;
        document.querySelectorAll(`[data-count="${key}"]`).forEach(el => {
            el.hidden = !value;
            el.textContent = num(value);
        });
    }

    async function render() {
        const roles = state.me.user.roles || [];
        const { path, params } = parseHash();
        const target = alias(path, roles);
        if (target) {
            history.replaceState(null, '', `#${target}${params.toString() ? `?${params}` : ''}`);
            return render();
        }
        const def = routes[path];
        const content = document.getElementById('d-content');
        const seq = ++state.seq;
        document.querySelectorAll('.d-nav a').forEach(a => {
            const active = a.dataset.path === path || (path.startsWith(a.dataset.path + '/') && !document.querySelector(`.d-nav a[data-path="${CSS.escape(path)}"]`));
            if (active) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        const actionsBar = document.getElementById('d-actions');
        actionsBar.innerHTML = '';
        actionsBar.onclick = null;
        if (!def) {
            setTitle('Page not found');
            content.innerHTML = ui.empty({ title: 'This page does not exist', text: 'The link may be old.', action: `<a class="d-btn d-btn--primary" href="#${homeFor(roles)}">Go to my dashboard</a>` });
            return;
        }
        if (def.roles && !def.roles.some(r => roles.includes(r))) {
            setTitle(def.title);
            content.innerHTML = ui.empty({ title: 'Not available for your account', text: 'This section is for other account types.', action: `<a class="d-btn d-btn--primary" href="#${homeFor(roles)}">Go to my dashboard</a>` });
            return;
        }
        setTitle(def.title);
        content.classList.add('is-loading');                  // keep the old frame while new data loads
        if (!content.children.length || content.querySelector('.d-empty, .d-loading')) content.innerHTML = '<div class="d-loading">Loading…</div>';
        const view = document.createElement('div');
        view.className = 'd-content-inner';
        view.style.display = 'contents';
        const ctx = {
            view, params, path, me: state.me, meta: state.meta, roles,
            has: (...r) => r.some(x => roles.includes(x)),
            link: changes => {
                const merged = Object.fromEntries(params.entries());
                Object.assign(merged, changes);
                return link(path, merged);
            },
            setActions: html => { if (seq === state.seq) document.getElementById('d-actions').innerHTML = html; },
            setTitle,
            setCount
        };
        try {
            await def.render(ctx);
        } catch (error) {
            console.error(error);
            if (error.status === 401) return location.assign(`${ROOT}auth/login.html?redirect=dashboard/index.html`);
            view.innerHTML = `<div class="d-panel">${ui.empty({ title: 'This page could not load', text: esc(error.message || 'Check your connection and try again.'), action: '<button class="d-btn d-btn--primary" type="button" data-retry>Try again</button>' })}</div>`;
            view.querySelector('[data-retry]').addEventListener('click', () => render());
        }
        if (seq !== state.seq) return;                         // the person already moved on
        content.classList.remove('is-loading');
        content.replaceChildren(view);
        flushCharts();
        if (def.after) def.after(ctx);
    }

    function setTitle(title) {
        const el = document.getElementById('d-title');
        if (el) el.textContent = title;
        document.title = `${title} - Nile Vijana`;
    }

    async function loadMe() {
        const me = await window.api.get('/me');
        window.api.setUser(me.user);
        state.me = me;
        core.setCurrency(me.currency);
        return me;
    }

    async function boot() {
        if (!window.api.getToken()) {
            location.replace(`${ROOT}auth/login.html?redirect=${encodeURIComponent('dashboard/index.html' + location.hash)}`);
            return;
        }
        try {
            const [, meta] = await Promise.all([loadMe(), window.api.get('/meta').catch(() => ({}))]);
            state.meta = meta || {};
        } catch (error) {
            if (error.status === 401) {
                location.replace(`${ROOT}auth/login.html?redirect=dashboard/index.html`);
                return;
            }
            document.body.innerHTML = `<div class="nv-dash"><div class="d-boot"><div class="d-empty"><h3>The dashboard could not load</h3><p>${esc(error.message)}</p><button class="d-btn d-btn--primary" onclick="location.reload()">Try again</button></div></div></div>`;
            return;
        }
        shell();
        window.addEventListener('hashchange', render);
        await render();
        loadCounts();
    }

    /** Sidebar counters: what needs attention. Cheap calls, run after the first screen. */
    async function loadCounts() {
        const roles = state.me.user.roles || [];
        const jobs = [];
        if (roles.includes('admin')) {
            jobs.push(window.api.get('/admin/queue').then(q => {
                setCount('review', (q.tracks || []).length + (q.beats || []).length + (q.studios || []).length + (q.creators || []).length);
                setCount('payouts', (q.payouts || []).length);
            }));
            jobs.push(window.api.get('/admin/questions', { status: 'open', per_page: 1 }).then(r => setCount('questions', (r.counts || {}).open || 0)));
        }
        if (roles.includes('artist') || roles.includes('choir')) {
            jobs.push(window.api.get('/tracks/mine').then(r => setCount('rejected_songs', list(r).filter(t => t.status === 'rejected').length)));
        }
        if (roles.includes('producer')) {
            jobs.push(window.api.get('/beats/mine').then(r => setCount('rejected_beats', list(r).filter(t => t.status === 'rejected').length)));
        }
        if (roles.includes('studio_owner')) {
            jobs.push(window.api.get('/bookings', { as: 'studio', status: 'pending' }).then(r => setCount('requests', list(r).length)));
        }
        await Promise.allSettled(jobs);
    }

    window.Dash = {
        ui,
        route(path, def) { routes[path] = def; },
        refresh: () => render(),
        go: hash => { if (location.hash === hash) render(); else location.hash = hash; },
        reloadMe: async () => { await loadMe(); shell(); await render(); loadCounts(); },
        recount: loadCounts,
        link,
        state,
        api: window.api,
        core
    };

    document.addEventListener('DOMContentLoaded', boot);
})();
