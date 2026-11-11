(() => {
    const apiBase = window.NILE_API_BASE || '/api';
    const tokenKey = 'nile-vijana-token';
    const htmlEscape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);
    const safeImage = (value) => {
        if (!value) return '';
        try {
            const imageUrl = new URL(value, window.location.origin);
            return ['http:', 'https:'].includes(imageUrl.protocol) ? imageUrl.href : '';
        } catch {
            return '';
        }
    };
    const money = (amount) => amount == null ? 'Ask for rates' : `${Number(amount).toLocaleString()} RWF`;
    const request = async (path, options = {}) => {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        const token = localStorage.getItem(tokenKey);
        if (token) headers.Authorization = `Bearer ${token}`;
        if (options.body && typeof options.body === 'string') headers['Content-Type'] = 'application/json';
        const response = await fetch(`${apiBase}${path}`, { ...options, headers });
        const payload = response.status === 204 ? null : await response.json().catch(() => null);
        if (!response.ok) throw new Error(payload?.error || `Request failed (${response.status})`);
        return payload;
    };
    const setError = (element, error, retry) => {
        element.innerHTML = `<div class="error-state">${htmlEscape(error.message)}${retry ? ` <a class="text-link" href="#" data-refresh="${htmlEscape(retry)}">Try again ↗</a>` : ''}</div>`;
    };
    const cover = (image, alt, letter) => {
        const url = safeImage(image);
        return url
            ? `<img class="cover-image" src="${htmlEscape(url)}" alt="${htmlEscape(alt)}" loading="lazy">`
            : `<div class="cover-placeholder" aria-hidden="true">${htmlEscape(letter || 'NV')}</div>`;
    };

    const tracksList = document.querySelector('#tracks-list');
    const beatsList = document.querySelector('#beats-list');
    const studioList = document.querySelector('#studio-list');
    const playerBar = document.querySelector('#player-bar');
    const audioPlayer = document.querySelector('#audio-player');
    const playerTitle = document.querySelector('#player-title');
    const toast = document.querySelector('#toast');
    let activeSearch = '';
    let toastTimer;

    const showToast = (message) => {
        toast.textContent = message;
        toast.classList.add('visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('visible'), 3200);
    };

    const playAudio = (url, title, creator) => {
        if (!url) {
            showToast('No preview is available for this track yet.');
            return;
        }
        audioPlayer.src = url;
        playerTitle.textContent = `${title}${creator ? ` · ${creator}` : ''}`;
        playerBar.hidden = false;
        audioPlayer.play().catch(() => showToast('This audio could not be played. Try again in a moment.'));
    };

    const renderTracks = (items) => {
        if (!items.length) {
            tracksList.innerHTML = `<div class="empty-state">${activeSearch ? 'No tracks match that search yet.' : 'The next sound is still being made. Check back soon.'}</div>`;
            return;
        }
        tracksList.innerHTML = items.map((track) => {
            const creator = track.creator?.stage_name || 'Independent artist';
            const title = track.title || 'Untitled track';
            const playUrl = track.preview_url || track.stream_url;
            return `<article class="track-card">
                <div class="cover-wrap">${cover(track.cover_url, `${title} cover`, title.slice(0, 2).toUpperCase())}
                    <button class="play-button" type="button" data-play-url="${htmlEscape(playUrl || '')}" data-play-title="${htmlEscape(title)}" data-play-creator="${htmlEscape(creator)}" aria-label="Play ${htmlEscape(title)}">▶</button>
                </div>
                <div class="card-meta"><div><h4 class="card-title">${htmlEscape(title)}</h4><p class="card-subtitle">${htmlEscape(creator)}</p></div><span class="track-tag">${htmlEscape(track.genre || 'NEW')}</span></div>
            </article>`;
        }).join('');
    };

    const renderBeats = (items) => {
        if (!items.length) {
            beatsList.innerHTML = `<div class="empty-state">${activeSearch ? 'No beats match that search yet.' : 'Fresh beats are on the way. Check back soon.'}</div>`;
            return;
        }
        beatsList.innerHTML = items.map((beat) => {
            const producer = beat.producer?.stage_name || 'Independent producer';
            const title = beat.title || 'Untitled beat';
            const details = [beat.bpm ? `${beat.bpm} BPM` : '', beat.key || '', beat.genre || ''].filter(Boolean).join(' · ');
            return `<article class="beat-card">
                <div class="beat-cover">${cover(beat.cover_url, `${title} cover`, '808')}
                    ${beat.preview_url ? `<button class="play-button beat-play" type="button" data-play-url="${htmlEscape(beat.preview_url)}" data-play-title="${htmlEscape(title)}" data-play-creator="${htmlEscape(producer)}" aria-label="Play ${htmlEscape(title)}">▶</button>` : ''}
                </div>
                <div class="beat-info"><h3 class="card-title">${htmlEscape(title)}</h3><p class="card-subtitle">by ${htmlEscape(producer)}</p>
                    <div class="beat-price">From ${htmlEscape(money(beat.price_from))}</div><div class="beat-specs">${htmlEscape(details || 'ORIGINAL PRODUCTION')}</div>
                </div>
            </article>`;
        }).join('');
    };

    const renderStudios = (items) => {
        if (!items.length) {
            studioList.innerHTML = `<div class="empty-state">${activeSearch ? 'No studios match that search yet.' : 'No studios are listed just yet. Stay tuned.'}</div>`;
            return;
        }
        studioList.innerHTML = items.map((studio) => {
            const phone = String(studio.phone || '').replace(/[^\d+]/g, '');
            const contact = phone ? `<a class="studio-contact" href="tel:${htmlEscape(phone)}" aria-label="Call ${htmlEscape(studio.name)}">CALL ↗</a>` : '';
            const address = [studio.city, studio.address].filter(Boolean).join(' · ');
            return `<article class="studio-card">
                <div class="studio-thumb" aria-hidden="true">NV</div>
                <div><h3>${htmlEscape(studio.name)}</h3><p>${htmlEscape(address || 'Creative space')}</p>
                    <p class="studio-price">${htmlEscape(money(studio.price_from))} <span>/ hour</span></p>
                </div>${contact}
            </article>`;
        }).join('');
    };

    const queryString = () => activeSearch ? `?q=${encodeURIComponent(activeSearch)}&per_page=8` : '?per_page=8';
    const loadTracks = async () => {
        tracksList.innerHTML = '<div class="loading-state">Finding the new sounds<span class="loading-dots">...</span></div>';
        try {
            const result = await request(`/tracks${queryString()}`);
            renderTracks(Array.isArray(result?.data) ? result.data : []);
        } catch (error) { setError(tracksList, error, 'tracks'); }
    };
    const loadBeats = async () => {
        beatsList.innerHTML = '<div class="loading-state">Tuning into the beat market<span class="loading-dots">...</span></div>';
        try {
            const result = await request(`/beats${queryString()}`);
            renderBeats(Array.isArray(result?.data) ? result.data : []);
        } catch (error) { setError(beatsList, error, 'beats'); }
    };
    const loadStudios = async () => {
        studioList.innerHTML = '<div class="loading-state">Looking for your next creative space<span class="loading-dots">...</span></div>';
        try {
            const result = await request(`/studios${queryString()}`);
            renderStudios(Array.isArray(result?.data) ? result.data : []);
        } catch (error) { setError(studioList, error, 'studios'); }
    };
    const loadCatalog = () => Promise.all([loadTracks(), loadBeats(), loadStudios()]);

    document.addEventListener('click', (event) => {
        const playButton = event.target.closest('[data-play-url]');
        if (playButton) {
            playAudio(playButton.dataset.playUrl, playButton.dataset.playTitle, playButton.dataset.playCreator);
            return;
        }
        const refreshLink = event.target.closest('[data-refresh]');
        if (refreshLink) {
            event.preventDefault();
            const loaders = { tracks: loadTracks, beats: loadBeats, studios: loadStudios };
            loaders[refreshLink.dataset.refresh]?.();
        }
    });

    document.querySelector('#search-form').addEventListener('submit', (event) => {
        event.preventDefault();
        activeSearch = document.querySelector('#catalog-search').value.trim();
        loadCatalog();
    });
    document.querySelector('#player-close').addEventListener('click', () => {
        audioPlayer.pause();
        audioPlayer.removeAttribute('src');
        playerBar.hidden = true;
    });
    document.querySelector('#current-year').textContent = new Date().getFullYear();

    const authDialog = document.querySelector('#auth-dialog');
    const authForm = document.querySelector('#auth-form');
    const authMessage = document.querySelector('#auth-message');
    const authButton = document.querySelector('#auth-button');
    let authMode = 'login';
    const updateAuthMode = (mode) => {
        authMode = mode;
        const register = mode === 'register';
        document.querySelector('#auth-title').innerHTML = register ? 'Start your<br><span class="serif-accent">next chapter.</span>' : 'Good to have<br><span class="serif-accent">you back.</span>';
        document.querySelector('#name-field').hidden = !register;
        document.querySelector('#login-field').hidden = register;
        document.querySelector('#email-field').hidden = !register;
        document.querySelector('#phone-field').hidden = !register;
        document.querySelector('#auth-form [name="login"]').required = !register;
        document.querySelector('#auth-form [name="name"]').required = register;
        document.querySelector('#auth-form [name="email"]').required = false;
        document.querySelector('#auth-form [name="phone"]').required = false;
        const passwordInput = document.querySelector('#auth-form [name="password"]');
        passwordInput.autocomplete = register ? 'new-password' : 'current-password';
        document.querySelector('.auth-submit').innerHTML = `${register ? 'Create account' : 'Sign in'} <span aria-hidden="true">↗</span>`;
        document.querySelectorAll('.auth-tab').forEach((tab) => {
            const selected = tab.dataset.mode === mode;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        authMessage.textContent = '';
        authMessage.classList.remove('success');
    };
    document.querySelectorAll('.auth-tab').forEach((tab) => tab.addEventListener('click', () => updateAuthMode(tab.dataset.mode)));
    authButton.addEventListener('click', () => {
        if (localStorage.getItem(tokenKey)) {
            localStorage.removeItem(tokenKey);
            authButton.textContent = 'Sign in';
            document.querySelector('#user-label').hidden = true;
            showToast('You have signed out.');
            return;
        }
        updateAuthMode('login');
        authDialog.showModal();
    });
    document.querySelector('#auth-close').addEventListener('click', () => authDialog.close());
    authDialog.addEventListener('click', (event) => {
        if (event.target === authDialog) authDialog.close();
    });
    authForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        authMessage.textContent = '';
        const submit = document.querySelector('.auth-submit');
        submit.disabled = true;
        const formData = new FormData(authForm);
        const body = authMode === 'login'
            ? { login: formData.get('login'), password: formData.get('password') }
            : {
                name: formData.get('name'),
                ...(formData.get('email') ? { email: formData.get('email') } : {}),
                ...(formData.get('phone') ? { phone: formData.get('phone') } : {}),
                password: formData.get('password'),
            };
        try {
            const result = await request(authMode === 'login' ? '/auth/login' : '/auth/register', {
                method: 'POST',
                body: JSON.stringify(body),
            });
            if (!result?.token || !result?.user) throw new Error('The server returned an incomplete sign-in response.');
            const registered = authMode === 'register';
            localStorage.setItem(tokenKey, result.token);
            const name = result.user.name || 'Creator';
            document.querySelector('#user-label').textContent = `Hi, ${name.split(' ')[0]}`;
            document.querySelector('#user-label').hidden = false;
            authButton.textContent = 'Sign out';
            authDialog.close();
            authForm.reset();
            updateAuthMode('login');
            showToast(registered ? 'Welcome to Nile Vijana!' : `Welcome back, ${name.split(' ')[0]}.`);
        } catch (error) {
            authMessage.textContent = error.message;
        } finally {
            submit.disabled = false;
        }
    });

    if (localStorage.getItem(tokenKey)) {
        request('/me').then((result) => {
            const name = result?.user?.name || result?.name;
            if (!name) return;
            document.querySelector('#user-label').textContent = `Hi, ${name.split(' ')[0]}`;
            document.querySelector('#user-label').hidden = false;
            authButton.textContent = 'Sign out';
        }).catch(() => {
            localStorage.removeItem(tokenKey);
            authButton.textContent = 'Sign in';
        });
    }

    loadCatalog();
})();
