/*
 * Nile Vijana — shared browser runtime (loaded first on every page).
 *
 *   window.api     API client: get/post/patch/put/delete/upload, token + signed-in user storage
 *   window.NVCore  small helpers shared by the public site (site.js) and the dashboard
 *                  (dashboard/*.js): escaping, money/date formatting (GH₵), toasts, cart storage,
 *                  form validation with field-specific messages, password strength, and the
 *                  Paystack checkout (NVCore.pay)
 *
 * The API address is worked out from this file's location:
 *   frontend/assets/js/core.js  ->  backend/public/api/
 * If the API is hosted somewhere else, change API_BASE below.
 */
(function () {
    'use strict';

    const script = document.currentScript;
    const API_BASE = new URL('../../../backend/public/api/', script.src).href;
    const ROOT = new URL('../../', script.src).pathname;          // frontend root, e.g. /nilevijanacreatives/frontend/
    const TOKEN_KEY = 'auth_token';
    const USER_KEY = 'auth_user';
    const CART_KEY = 'nv_cart';

    /* ---------- storage that never throws (private mode, blocked cookies) ---------- */
    const store = {
        get(key) { try { return localStorage.getItem(key); } catch (_) { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); return true; } catch (_) { return false; } },
        remove(key) { try { localStorage.removeItem(key); } catch (_) { /* ignore */ } }
    };

    /* ---------- API client ---------- */
    const url = endpoint => new URL(String(endpoint).replace(/^\/+/, ''), API_BASE).href;

    async function request(endpoint, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        const token = store.get(TOKEN_KEY);
        if (token) headers.Authorization = `Bearer ${token}`;
        let body = options.body;
        if (body && typeof body === 'object' && !(body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }
        let response;
        try {
            response = await fetch(url(endpoint), { ...options, body, headers });
        } catch (networkError) {
            const error = new Error('Cannot reach the server. Check your connection and try again.');
            error.status = 0;
            error.errors = {};
            throw error;
        }
        if (response.status === 204) return null;
        let data = {};
        try {
            data = await response.json();
        } catch (_) {
            if (response.ok) throw new Error('The server sent an unexpected response.');
        }
        if (!response.ok) {
            if (response.status === 401) {
                store.remove(TOKEN_KEY);
                store.remove(USER_KEY);
            }
            const error = new Error(data.error || data.message || `Request failed (${response.status})`);
            error.status = response.status;
            error.errors = data.errors || {};
            throw error;
        }
        return data;
    }

    const withQuery = (endpoint, params) => {
        const clean = {};
        Object.entries(params || {}).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') clean[key] = value;
        });
        const query = new URLSearchParams(clean).toString();
        return query ? `${endpoint}${endpoint.includes('?') ? '&' : '?'}${query}` : endpoint;
    };

    /** Upload with a progress callback (fetch cannot report upload progress). onProgress(0..1) */
    function uploadWithProgress(endpoint, formData, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url(endpoint));
            xhr.setRequestHeader('Accept', 'application/json');
            const token = store.get(TOKEN_KEY);
            if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
            if (onProgress) xhr.upload.addEventListener('progress', e => { if (e.lengthComputable) onProgress(e.loaded / e.total); });
            xhr.addEventListener('load', () => {
                let data = {};
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (_) { /* not JSON */ }
                if (xhr.status >= 200 && xhr.status < 300) return resolve(data);
                if (xhr.status === 401) { store.remove(TOKEN_KEY); store.remove(USER_KEY); }
                const error = new Error(data.error || data.message || `Upload failed (${xhr.status})`);
                error.status = xhr.status;
                error.errors = data.errors || {};
                reject(error);
            });
            xhr.addEventListener('error', () => {
                const error = new Error('The upload stopped: check your connection and try again.');
                error.status = 0;
                error.errors = {};
                reject(error);
            });
            xhr.send(formData);
        });
    }

    window.api = {
        get: (endpoint, params) => request(withQuery(endpoint, params), { method: 'GET' }),
        post: (endpoint, data = null) => request(endpoint, { method: 'POST', body: data }),
        patch: (endpoint, data = null) => request(endpoint, { method: 'PATCH', body: data }),
        put: (endpoint, data = null) => request(endpoint, { method: 'PUT', body: data }),
        delete: endpoint => request(endpoint, { method: 'DELETE' }),
        upload: (endpoint, formData, onProgress) => onProgress
            ? uploadWithProgress(endpoint, formData, onProgress)
            : request(endpoint, { method: 'POST', body: formData }),
        url,
        getToken: () => store.get(TOKEN_KEY),
        setToken: token => store.set(TOKEN_KEY, token),
        clearToken: () => { store.remove(TOKEN_KEY); store.remove(USER_KEY); },
        getUser: () => { try { return JSON.parse(store.get(USER_KEY) || 'null'); } catch (_) { return null; } },
        setUser: user => store.set(USER_KEY, JSON.stringify(user || {}))
    };
    window.isLoggedIn = () => Boolean(window.api.getToken());

    /* ---------- formatting ---------- */
    const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, ch => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[ch]);
    let currency = 'GHS';
    const SYMBOLS = { GHS: 'GH₵', USD: '$', NGN: '₦' };
    const num = value => Number(value || 0).toLocaleString('en-GH');
    /** 1200 -> "GH₵ 1,200" (whole cedis; pesewas shown when present). Pass {free: true} to show 0 as "Free". */
    const money = (value, options = {}) => {
        if ((value == null || value === '' || Number(value) === 0) && options.free) return 'Free';
        const n = Number(value || 0);
        const code = options.currency || currency;
        const amount = n.toLocaleString('en-GH', { minimumFractionDigits: Number.isInteger(n) ? 0 : 2, maximumFractionDigits: 2 });
        return `${SYMBOLS[code] || code} ${amount}`;
    };
    /** Short form for axes and tiles: 1,200 / 12.5K / 1.2M */
    const compact = value => {
        const n = Number(value || 0);
        const abs = Math.abs(n);
        if (abs >= 1e6) return `${(n / 1e6).toFixed(abs >= 1e7 ? 0 : 1).replace(/\.0$/, '')}M`;
        if (abs >= 1e4) return `${(n / 1e3).toFixed(abs >= 1e5 ? 0 : 1).replace(/\.0$/, '')}K`;
        return n.toLocaleString();
    };
    const parseDate = value => {
        if (!value) return null;
        const d = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? null : d;
    };
    const date = value => {
        const d = parseDate(value);
        return d ? d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';
    };
    const dateTime = value => {
        const d = parseDate(value);
        return d ? d.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
    };
    const ago = value => {
        const d = parseDate(value);
        if (!d) return '';
        const s = Math.round((Date.now() - d.getTime()) / 1000);
        if (s < 60) return 'just now';
        if (s < 3600) return `${Math.floor(s / 60)} min ago`;
        if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
        if (s < 86400 * 7) return `${Math.floor(s / 86400)} d ago`;
        return date(value);
    };

    /* ---------- responses ---------- */
    const obj = response => (response && response.data && !Array.isArray(response.data) ? response.data : response);
    const list = response => {
        if (Array.isArray(response)) return response;
        if (response && Array.isArray(response.data)) return response.data;
        if (response && Array.isArray(response.items)) return response.items;
        return [];
    };

    /* ---------- notifications ---------- */
    function toast(message, type = 'success') {
        let host = document.getElementById('toast-container');
        if (!host) {
            host = document.createElement('div');
            host.id = 'toast-container';
            host.className = 'toast-container';
            document.body.appendChild(host);
        }
        host.setAttribute('aria-live', 'polite');
        const item = document.createElement('div');
        item.className = `toast toast-${type}`;
        item.setAttribute('role', type === 'error' ? 'alert' : 'status');
        item.textContent = message;
        host.appendChild(item);
        setTimeout(() => item.remove(), type === 'error' ? 6000 : 4000);
    }

    /** API call that shows the error as a toast and re-throws it. */
    async function call(method, endpoint, data) {
        try {
            return await window.api[method](endpoint, data);
        } catch (error) {
            toast(error.message || 'The request could not be completed.', 'error');
            throw error;
        }
    }

    /* ---------- account ---------- */
    const user = () => window.api.getUser() || {};
    const roles = () => (Array.isArray(user().roles) ? user().roles : []);
    const hasRole = (...wanted) => wanted.some(role => roles().includes(role));

    /* ---------- cart (kept in this browser until checkout) ---------- */
    const cart = {
        read() {
            try {
                const items = JSON.parse(store.get(CART_KEY) || '[]');
                return Array.isArray(items) ? items : [];
            } catch (_) {
                store.remove(CART_KEY);
                return [];
            }
        },
        save(items) {
            if (!store.set(CART_KEY, JSON.stringify(items))) {
                toast('Your browser blocked saving the cart. Check its storage settings.', 'error');
                return false;
            }
            return true;
        },
        clear() { store.remove(CART_KEY); }
    };

    /* ---------- password strength (same rules as the server) ---------- */
    /** strong: 8+ chars, uppercase, digit, special · medium: 8+ chars, uppercase, digit OR special · weak: refused */
    function passwordStrength(password) {
        const p = String(password || '');
        const checks = {
            length: p.length >= 8,
            upper: /\p{Lu}/u.test(p),
            digit: /\d/.test(p),
            special: /[^\p{L}\p{N}\s]/u.test(p)
        };
        const level = checks.length && checks.upper && checks.digit && checks.special ? 'strong'
            : checks.length && checks.upper && (checks.digit || checks.special) ? 'medium' : 'weak';
        const missing = [];
        if (!checks.length) missing.push('at least 8 characters');
        if (!checks.upper) missing.push('an uppercase letter');
        if (!checks.digit && !checks.special) missing.push('a number or a special character');
        return { level, checks, missing };
    }

    /**
     * Live strength meter under a password field: Weak (refused) / Medium (accepted) / Strong,
     * with the rules ticked off as they are met.
     */
    function attachStrength(input) {
        if (!input || input.dataset.meter) return;
        input.dataset.meter = '1';
        const box = document.createElement('div');
        box.className = 'pw-meter';
        box.dataset.level = 'empty';
        box.setAttribute('aria-live', 'polite');
        box.innerHTML = '<div class="pw-bar" aria-hidden="true"><i></i><i></i><i></i></div><p class="pw-text">Use at least 8 characters with an uppercase letter, a number and a special character.</p>'
            + '<ul class="pw-rules"><li data-rule="length">8+ characters</li><li data-rule="upper">Uppercase letter</li><li data-rule="digit">Number</li><li data-rule="special">Special character (!@#…)</li></ul>';
        (input.closest('[data-field], label, .form-group') || input).after(box);
        const update = () => {
            const value = input.value;
            const r = passwordStrength(value);
            box.dataset.level = value ? r.level : 'empty';
            Object.entries(r.checks).forEach(([rule, ok]) => box.querySelector(`[data-rule="${rule}"]`)?.classList.toggle('is-met', ok));
            box.querySelector('.pw-text').textContent = !value
                ? 'Use at least 8 characters with an uppercase letter, a number and a special character.'
                : r.level === 'strong' ? 'Strong password.'
                : r.level === 'medium' ? `Medium password: accepted. Add ${!r.checks.digit ? 'a number' : 'a special character'} to make it strong.`
                : `Weak password: add ${r.missing.join(', ')}.`;
        };
        input.addEventListener('input', update);
        update();
        return box;
    }

    /* ---------- forms: every problem listed at once, next to its field ---------- */
    const forms = {
        /** The name a person sees for a field: data-label, the field's caption, or the placeholder. */
        label(control) {
            if (control.dataset.label) return control.dataset.label;
            const holder = control.closest('[data-field], label, .form-group');
            const caption = holder && (holder.querySelector(':scope > span, :scope > .form-label-text, :scope > legend'));
            let text = caption ? caption.childNodes[0]?.textContent || caption.textContent : '';
            if (!text && holder && holder.tagName === 'LABEL') {
                text = Array.from(holder.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent).join(' ');
            }
            text = String(text || control.getAttribute('aria-label') || control.placeholder || control.name || 'This field')
                .replace(/\(optional\)/i, '').replace(/[*:]/g, '').trim();
            return text.charAt(0).toUpperCase() + text.slice(1);
        },

        /** Checks the HTML rules (required, type, minlength, min/max, accept, data-max-mb, data-match). */
        precheck(form) {
            const errors = {};
            const seen = new Set();
            form.querySelectorAll('input, select, textarea').forEach(control => {
                const name = control.name;
                if (!name || control.disabled || control.type === 'hidden' || seen.has(name)) return;
                if (control.closest('[hidden]')) return;
                const label = forms.label(control);
                const value = control.type === 'file' ? control.files : String(control.value || '').trim();
                const empty = control.type === 'file' ? !value || !value.length
                    : control.type === 'checkbox' ? !control.checked
                    : value === '';
                let message = '';
                if (control.required && empty) {
                    message = control.dataset.missing || (control.tagName === 'SELECT' ? `${label} has not been selected.`
                        : control.type === 'checkbox' ? `Please tick “${label}”.` : `${label} is required.`);
                } else if (!empty && control.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
                    message = `${label} must be a valid email address, like name@example.com.`;
                } else if (!empty && control.minLength > 0 && value.length < control.minLength) {
                    message = `${label} must be at least ${control.minLength} characters.`;
                } else if (!empty && control.type === 'number') {
                    const n = Number(value);
                    if (!Number.isFinite(n)) message = `${label} must be a number.`;
                    else if (control.min !== '' && n < Number(control.min)) message = control.dataset.rangeMessage || `${label} must be at least ${Number(control.min).toLocaleString()}.`;
                    else if (control.max !== '' && n > Number(control.max)) message = control.dataset.rangeMessage || `${label} must be at most ${Number(control.max).toLocaleString()}.`;
                } else if (!empty && control.type === 'file') {
                    const file = value[0];
                    const max = Number(control.dataset.maxMb || 0);
                    const accept = (control.accept || '').split(',').map(a => a.trim().toLowerCase()).filter(Boolean);
                    const ext = '.' + String(file.name.split('.').pop() || '').toLowerCase();
                    const typeOk = !accept.length || accept.some(a => a === ext || a === file.type.toLowerCase() || (a.endsWith('/*') && file.type.toLowerCase().startsWith(a.slice(0, -1))));
                    if (!typeOk) message = `${label} must be one of these file types: ${accept.filter(a => a.startsWith('.')).map(a => a.slice(1).toUpperCase()).join(', ') || accept.join(', ')}.`;
                    else if (max && file.size > max * 1048576) message = `${label} is too large (${(file.size / 1048576).toFixed(1)} MB): the maximum is ${max} MB.`;
                } else if (!empty && control.pattern && !new RegExp(`^(?:${control.pattern})$`).test(value)) {
                    message = control.dataset.patternMessage || `${label} is not in the right format.`;
                } else if (!empty && control.dataset.strength !== undefined && passwordStrength(value).level === 'weak') {
                    message = `${label} is too weak. Add ${passwordStrength(value).missing.join(', ')}.`;
                } else if (!empty && control.dataset.match) {
                    const other = form.querySelector(`[name="${control.dataset.match}"]`);
                    if (other && other.value !== control.value) message = control.dataset.matchMessage || 'The two values do not match.';
                }
                if (message) { errors[name] = message; seen.add(name); }
            });
            return errors;
        },

        clear(form) {
            form.querySelectorAll('.nv-error-summary').forEach(el => el.remove());
            form.querySelectorAll('.nv-field-error, .d-field-error').forEach(el => el.remove());
            form.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
            form.querySelectorAll('[aria-invalid="true"]').forEach(el => el.removeAttribute('aria-invalid'));
        },

        /** Shows a summary ("Your track could not be uploaded. Please complete the following:") and marks each field. */
        show(form, error, options = {}) {
            forms.clear(form);
            if (!error) return;
            const errors = error.errors || {};
            const items = [];
            Object.entries(errors).forEach(([key, message]) => {
                if (['restart', 'retry_after'].includes(key)) return;
                const base = key.split('.')[0];
                const control = form.querySelector(`[name="${CSS.escape(key)}"]`) || form.querySelector(`[name="${CSS.escape(base)}"]`);
                const holder = form.querySelector(`[data-field="${CSS.escape(key)}"]`) || form.querySelector(`[data-field="${CSS.escape(base)}"]`)
                    || (control && (control.closest('[data-field], label, .form-group') || control.parentElement));
                let id = '';
                if (holder) {
                    holder.classList.add('has-error');
                    const note = document.createElement('small');
                    note.className = 'nv-field-error';
                    note.id = `err-${Math.random().toString(36).slice(2, 9)}`;
                    note.textContent = String(message);
                    holder.appendChild(note);
                    if (control) {
                        control.setAttribute('aria-invalid', 'true');
                        control.setAttribute('aria-describedby', note.id);
                        if (!control.id) control.id = `f-${Math.random().toString(36).slice(2, 9)}`;
                        id = control.id;
                    }
                }
                items.push(`<li>${id ? `<a href="#${id}">${esc(message)}</a>` : esc(message)}</li>`);
            });
            const title = options.title || form.dataset.errorTitle || error.message || 'Please check the highlighted fields.';
            const box = document.createElement('div');
            box.className = 'nv-error-summary';
            box.setAttribute('role', 'alert');
            box.tabIndex = -1;
            box.innerHTML = `<p class="nv-error-summary-title">${esc(items.length && error.message && !options.title && !form.dataset.errorTitle ? error.message : title)}</p>${items.length ? `<ul>${items.join('')}</ul>` : ''}`;
            form.prepend(box);
            box.addEventListener('click', e => {
                const a = e.target.closest('a[href^="#"]');
                if (!a) return;
                e.preventDefault();
                const target = document.getElementById(a.getAttribute('href').slice(1));
                if (target) { target.focus(); target.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
            });
            box.scrollIntoView({ block: 'start', behavior: 'smooth' });
            box.focus({ preventScroll: true });
        },

        /**
         * Submit handler: blocks double submissions, checks the form first, then shows server
         * errors the same way. handler(form) returns a promise. Values stay in the form on errors.
         */
        guard(form, handler, options = {}) {
            if (form.dataset.guarded) return;
            form.dataset.guarded = '1';
            form.noValidate = true;
            form.addEventListener('input', e => forms.fixed(e.target));
            form.addEventListener('change', e => forms.fixed(e.target));
            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (form.dataset.busy) return;                       // a second click while sending
                const local = options.precheck === false ? {} : forms.precheck(form);
                if (options.check) Object.assign(local, options.check(form) || {});
                if (Object.keys(local).length) {
                    forms.show(form, { message: options.title || form.dataset.errorTitle || 'Please complete the following:', errors: local }, { title: options.title || form.dataset.errorTitle || 'Please complete the following:' });
                    return;
                }
                forms.clear(form);
                form.dataset.busy = '1';
                const buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
                buttons.forEach(b => { b.disabled = true; b.setAttribute('aria-busy', 'true'); });
                try {
                    await handler(form);
                } catch (error) {
                    if (error && error.status === 0) toast(error.message, 'error');
                    forms.show(form, error && Object.keys(error.errors || {}).length ? error : { message: (error && error.message) || 'Something went wrong. Please try again.', errors: {} }, {});
                } finally {
                    delete form.dataset.busy;
                    buttons.forEach(b => { b.disabled = false; b.removeAttribute('aria-busy'); });
                }
            });
        },

        /** Clears a field's error as soon as the person changes it. */
        fixed(control) {
            if (!control || control.getAttribute('aria-invalid') !== 'true') return;
            control.removeAttribute('aria-invalid');
            const holder = control.closest('.has-error');
            if (holder) {
                holder.classList.remove('has-error');
                holder.querySelectorAll('.nv-field-error').forEach(el => el.remove());
            }
        }
    };

    /* ---------- Paystack checkout ---------- */
    let paystackScript = null;
    function loadPaystack() {
        if (window.PaystackPop) return Promise.resolve(window.PaystackPop);
        if (!paystackScript) {
            paystackScript = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://js.paystack.co/v2/inline.js';
                s.async = true;
                s.onload = () => (window.PaystackPop ? resolve(window.PaystackPop) : reject(new Error('Paystack did not load')));
                s.onerror = () => { paystackScript = null; reject(new Error('Paystack could not be reached')); };
                document.head.appendChild(s);
                setTimeout(() => reject(new Error('Paystack took too long to load')), 8000);
            });
        }
        return paystackScript;
    }
    /**
     * Opens the Paystack checkout for a payment created by the API (it has access_code and
     * checkout_url). Resolves 'success' | 'cancelled' | 'redirected'. Paystack's popup is used
     * when it loads; otherwise the browser goes to Paystack's checkout page and comes back to
     * payment.html. Either way the SERVER verifies the payment before anything is delivered.
     */
    async function pay(payment) {
        if (!payment || (!payment.access_code && !payment.checkout_url)) throw new Error('This payment cannot be opened. Please try again.');
        try {
            if (!payment.access_code) throw new Error('no popup');
            const Pop = await loadPaystack();
            return await new Promise(resolve => {
                const popup = new Pop();
                popup.resumeTransaction(payment.access_code, {
                    onSuccess: () => resolve('success'),
                    onCancel: () => resolve('cancelled'),
                    onError: () => { location.assign(payment.checkout_url); resolve('redirected'); }
                });
            });
        } catch (_) {
            location.assign(payment.checkout_url);
            return 'redirected';
        }
    }
    /** Asks the server (which asks Paystack) for the result; waits a little for webhooks/processing. */
    async function confirmPayment(reference, tries = 8) {
        let p = null;
        for (let i = 0; i < tries; i++) {
            p = obj(await window.api.get(`/payments/${encodeURIComponent(reference)}`));
            if (p.status === 'completed' || p.status === 'failed') return p;
            await new Promise(r => setTimeout(r, 2000));
        }
        return p;
    }

    window.NVCore = {
        ROOT, store, esc, num, money, compact, parseDate, date, dateTime, ago, obj, list, toast, call,
        user, roles, hasRole, cart, forms, passwordStrength, attachStrength, pay, confirmPayment,
        setCurrency(code) { if (code) currency = code; },
        get currency() { return currency; }
    };
})();
