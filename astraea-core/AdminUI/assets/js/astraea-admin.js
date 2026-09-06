// STATUS: DIAMANT VGT SUPREME
/**
 * AstraeaOS Admin UI Modular JavaScript Engine.
 *
 * Core Capabilities:
 * 1. Global Command Palette (Ctrl+K, arrow navigation, live fuzzy search)
 * 2. Theme Mode Controller (Dark / Light persistence via localStorage)
 * 3. Notification Center Drawer (Notice harvesting, live counter, slide-out drawer)
 * 4. Keyboard Navigation Shortcuts (G+D, C+P, ESC trapping)
 *
 * Zero external libraries. 100% Native Vanilla ESNext. Offline-first.
 */

(function () {
    'use strict';

    const AstraeaAdmin = {
        config: window.AstraeaConfig || {
            ajaxUrl: '',
            cmdNonce: '',
            sessionNonce: '',
            adminUrl: '',
            currentTheme: 'dark'
        },

        state: {
            theme: 'dark',
            cmdSelectedIndex: 0,
            cmdResults: [],
            searchDebounceTimer: null,
            lastKeyPressed: '',
            lastKeyTime: 0
        },

        init() {
            document.addEventListener('DOMContentLoaded', () => {
                this.initTheme();
                this.initCommandPalette();
                this.initNotificationDrawer();
                this.initGlobalShortcuts();
                this.initSessionManagement();
            });
        },

        /* ======================================================================
           1. THEME MODE CONTROLLER
           ====================================================================== */
        initTheme() {
            const savedTheme = localStorage.getItem('astraea_theme') || 'dark';
            this.setTheme(savedTheme);

            const toggleBtn = document.getElementById('astraea-theme-toggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const newTheme = this.state.theme === 'dark' ? 'light' : 'dark';
                    this.setTheme(newTheme);
                });
            }
        },

        setTheme(theme) {
            this.state.theme = theme;
            localStorage.setItem('astraea_theme', theme);

            const body = document.body;
            if (theme === 'light') {
                body.classList.remove('astraea-theme-dark');
                body.classList.add('astraea-theme-light');
            } else {
                body.classList.remove('astraea-theme-light');
                body.classList.add('astraea-theme-dark');
            }

            const toggleBtn = document.getElementById('astraea-theme-toggle');
            if (toggleBtn) {
                const icon = document.createElement('span');
                icon.className = 'theme-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = theme === 'light' ? '\u2600' : '\u263E';
                toggleBtn.replaceChildren(icon);
                toggleBtn.setAttribute('title', `Theme: ${theme.toUpperCase()} (Click to toggle)`);
            }
        },

        /* ======================================================================
           2. COMMAND PALETTE (CTRL+K)
           ====================================================================== */
        initCommandPalette() {
            const modal = document.getElementById('astraea-command-modal');
            const input = document.getElementById('astraea-cmd-input');
            const resultsContainer = document.getElementById('astraea-cmd-results');
            const triggerButtons = document.querySelectorAll('#astraea-cmd-trigger, .astraea-command-trigger');

            if (!modal || !input) {
                return;
            }

            // Architectural guarantee: Anchor search pill directly into #wp-toolbar if nested in root-default
            const searchTriggerNode = document.getElementById('wp-admin-bar-astraea-search-trigger');
            const toolbar = document.getElementById('wp-toolbar');
            if (searchTriggerNode && toolbar && searchTriggerNode.parentElement !== toolbar) {
                toolbar.appendChild(searchTriggerNode);
            }

            // Trigger button click & keyboard activate
            triggerButtons.forEach((triggerBtn) => {
                triggerBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.openCommandPalette();
                });
                triggerBtn.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.openCommandPalette();
                    }
                });
            });

            // Backdrop click to close
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeCommandPalette();
                }
            });

            // Input live search
            input.addEventListener('input', (e) => {
                clearTimeout(this.state.searchDebounceTimer);
                this.state.searchDebounceTimer = setTimeout(() => {
                    this.searchCommands(input.value.trim());
                }, 150);
            });

            // Keyboard navigation inside input
            input.addEventListener('keydown', (e) => {
                const results = this.state.cmdResults;
                if (!results.length) {
                    if (e.key === 'Escape') {
                        this.closeCommandPalette();
                    }
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.state.cmdSelectedIndex = (this.state.cmdSelectedIndex + 1) % results.length;
                    this.renderCommandResults();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.state.cmdSelectedIndex = (this.state.cmdSelectedIndex - 1 + results.length) % results.length;
                    this.renderCommandResults();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const selected = results[this.state.cmdSelectedIndex];
                    if (selected && selected.url) {
                        window.location.href = selected.url;
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    this.closeCommandPalette();
                }
            });
        },

        openCommandPalette() {
            const modal = document.getElementById('astraea-command-modal');
            const input = document.getElementById('astraea-cmd-input');
            if (modal && input) {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                input.value = '';
                this.state.cmdSelectedIndex = 0;
                input.focus();
                this.searchCommands('');
            }
        },

        closeCommandPalette() {
            const modal = document.getElementById('astraea-command-modal');
            if (modal) {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            }
        },

        searchCommands(query) {
            const resultsContainer = document.getElementById('astraea-cmd-results');
            if (!resultsContainer) return;

            const url = `${this.config.ajaxUrl}?action=astraea_command_search&q=${encodeURIComponent(query)}`;

            fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-WP-Nonce': this.config.cmdNonce
                }
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data && data.success && Array.isArray(data.data)) {
                        this.state.cmdResults = data.data;
                        this.state.cmdSelectedIndex = 0;
                        this.renderCommandResults();
                    }
                })
                .catch((err) => {
                    console.error('[Astraea Command] Search error:', err);
                });
        },

        renderCommandResults() {
            const container = document.getElementById('astraea-cmd-results');
            if (!container) return;

            if (typeof container.replaceChildren === 'function') {
                container.replaceChildren();
            } else {
                while (container.firstChild) {
                    container.removeChild(container.firstChild);
                }
            }

            const results = this.state.cmdResults;
            if (!results.length) {
                const empty = document.createElement('div');
                empty.className = 'astraea-cmd-empty';
                empty.textContent = 'No matching commands or resources found.';
                container.appendChild(empty);
                return;
            }

            const fragment = document.createDocumentFragment();

            results.forEach((item, index) => {
                const isSelected = index === this.state.cmdSelectedIndex;

                const itemDiv = document.createElement('div');
                itemDiv.className = 'astraea-cmd-item' + (isSelected ? ' is-selected' : '');
                itemDiv.setAttribute('data-index', String(index));
                itemDiv.setAttribute('data-url', item.url || '');
                itemDiv.setAttribute('role', 'option');
                itemDiv.setAttribute('aria-selected', isSelected ? 'true' : 'false');

                // Left container
                const leftDiv = document.createElement('div');
                leftDiv.className = 'astraea-cmd-item-left';

                const iconSpan = document.createElement('span');
                iconSpan.className = 'astraea-cmd-item-icon';
                iconSpan.setAttribute('aria-hidden', 'true');
                iconSpan.textContent = this.decodeHtml(item.icon || '▸');

                const titleSpan = document.createElement('span');
                titleSpan.className = 'astraea-cmd-item-title';
                titleSpan.textContent = this.decodeHtml(item.title || '');

                leftDiv.appendChild(iconSpan);
                leftDiv.appendChild(titleSpan);

                // Right container
                const rightDiv = document.createElement('div');
                rightDiv.className = 'astraea-cmd-item-right';

                const catSpan = document.createElement('span');
                catSpan.className = 'astraea-cmd-item-cat';
                catSpan.textContent = item.category || '';
                rightDiv.appendChild(catSpan);

                if (item.shortcut) {
                    const shortcutSpan = document.createElement('span');
                    shortcutSpan.className = 'astraea-cmd-item-shortcut';
                    shortcutSpan.textContent = item.shortcut;
                    rightDiv.appendChild(shortcutSpan);
                }

                itemDiv.appendChild(leftDiv);
                itemDiv.appendChild(rightDiv);

                itemDiv.addEventListener('click', () => {
                    const targetUrl = itemDiv.getAttribute('data-url');
                    if (targetUrl) {
                        window.location.href = targetUrl;
                    }
                });

                fragment.appendChild(itemDiv);
            });

            container.appendChild(fragment);

            // Ensure selected item is scrolled into view
            const selectedEl = container.querySelector('.astraea-cmd-item.is-selected');
            if (selectedEl) {
                selectedEl.scrollIntoView({ block: 'nearest' });
            }
        },

        /* ======================================================================
           3. NOTIFICATION DRAWER CONTROLLER
           ====================================================================== */
        initNotificationDrawer() {
            const drawer = document.getElementById('astraea-notification-drawer');
            const backdrop = document.getElementById('astraea-drawer-backdrop');
            const toggleBtn = document.getElementById('astraea-drawer-toggle');
            const closeBtn = document.getElementById('astraea-drawer-close');
            const counter = document.getElementById('astraea-notice-counter');
            const drawerNotices = document.getElementById('astraea-drawer-notices');
            const emptyState = document.getElementById('astraea-empty-notices');

            if (!drawer || !drawerNotices) return;

            // Harvest notices from page
            const existingNotices = document.querySelectorAll('.wrap > .notice, .wrap > div.updated, .wrap > div.error, #astraea-notices-anchor .notice');
            let count = 0;

            if (existingNotices.length > 0) {
                count = existingNotices.length;
                if (emptyState) emptyState.style.display = 'none';

                existingNotices.forEach((notice) => {
                    const safeNotice = document.createElement('div');
                    safeNotice.className = 'notice astraea-harvested-notice';
                    safeNotice.textContent = notice.textContent || '';
                    drawerNotices.appendChild(safeNotice);
                });
            }

            // Update badge counter
            if (counter && count > 0) {
                counter.textContent = String(count);
                counter.style.display = 'inline-block';
            }

            const openDrawer = () => {
                drawer.classList.add('is-open');
                drawer.setAttribute('aria-hidden', 'false');
                if (backdrop) backdrop.classList.add('is-open');
            };

            const closeDrawer = () => {
                drawer.classList.remove('is-open');
                drawer.setAttribute('aria-hidden', 'true');
                if (backdrop) backdrop.classList.remove('is-open');
            };

            if (toggleBtn) {
                toggleBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (drawer.classList.contains('is-open')) {
                        closeDrawer();
                    } else {
                        openDrawer();
                    }
                });
            }

            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
            if (backdrop) backdrop.addEventListener('click', closeDrawer);
        },

        /* ======================================================================
           4. GLOBAL SHORTCUTS
           ====================================================================== */
        initGlobalShortcuts() {
            window.addEventListener('keydown', (e) => {
                // Ignore if user is inside an input/textarea/editable
                const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
                const isEditable = e.target && e.target.isContentEditable;
                const isInputField = tag === 'input' || tag === 'textarea' || tag === 'select' || isEditable;

                // 1. Ctrl + K / Cmd + K triggers Command Palette from ANYWHERE
                if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    this.openCommandPalette();
                    return;
                }

                // 2. Escape closes open drawers or modals
                if (e.key === 'Escape') {
                    this.closeCommandPalette();
                    const drawer = document.getElementById('astraea-notification-drawer');
                    const backdrop = document.getElementById('astraea-drawer-backdrop');
                    if (drawer) drawer.classList.remove('is-open');
                    if (backdrop) backdrop.classList.remove('is-open');
                    return;
                }

                // Sequential keyboard shortcuts only when NOT in an input
                if (isInputField) return;

                const now = Date.now();
                const key = e.key.toUpperCase();

                // G then D -> Go to Dashboard
                if (this.state.lastKeyPressed === 'G' && (now - this.state.lastKeyTime) < 1000) {
                    if (key === 'D') {
                        e.preventDefault();
                        window.location.href = this.config.adminUrl + 'index.php';
                    }
                }

                // C then P -> Create New Post
                if (this.state.lastKeyPressed === 'C' && (now - this.state.lastKeyTime) < 1000) {
                    if (key === 'P') {
                        e.preventDefault();
                        window.location.href = this.config.adminUrl + 'post-new.php';
                    }
                }

                this.state.lastKeyPressed = key;
                this.state.lastKeyTime = now;
            });
        },

        /* ======================================================================
           5. SESSION MANAGEMENT & REVOCATION
           ====================================================================== */
        initSessionManagement() {
            // Individual session revoke
            document.addEventListener('click', (e) => {
                const revokeBtn = e.target.closest('.astraea-session-revoke-btn');
                if (!revokeBtn) return;

                e.preventDefault();
                const verifier = revokeBtn.getAttribute('data-verifier');
                if (!verifier) return;

                if (!confirm('Are you sure you want to revoke this active session? The remote device will be immediately signed out.')) {
                    return;
                }

                revokeBtn.disabled = true;
                revokeBtn.textContent = 'Revoking...';

                const formData = new FormData();
                formData.append('action', 'astraea_revoke_session');
                formData.append('verifier', verifier);
                formData.append('_wpnonce', this.config.sessionNonce || '');

                fetch(this.config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-WP-Nonce': this.config.sessionNonce || ''
                    }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        const row = revokeBtn.closest('tr');
                        if (row) {
                            row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                            row.style.opacity = '0';
                            row.style.transform = 'scale(0.95)';
                            setTimeout(() => row.remove(), 300);
                        }
                    } else {
                        alert((data && data.data && data.data.message) ? data.data.message : 'Failed to revoke session.');
                        revokeBtn.disabled = false;
                        revokeBtn.textContent = 'Revoke';
                    }
                })
                .catch(err => {
                    console.error('[AstraeaOS] Revocation error:', err);
                    alert('Network error while attempting to revoke session.');
                    revokeBtn.disabled = false;
                    revokeBtn.textContent = 'Revoke';
                });
            });

            // Revoke all other sessions
            const revokeAllBtn = document.getElementById('astraea-revoke-all-others-btn');
            if (revokeAllBtn) {
                revokeAllBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (!confirm('Log out all other active sessions across all devices? Only your current device session will remain active.')) {
                        return;
                    }

                    revokeAllBtn.disabled = true;
                    revokeAllBtn.textContent = 'Revoking All...';

                    const formData = new FormData();
                    formData.append('action', 'astraea_revoke_other_sessions');
                    formData.append('_wpnonce', this.config.sessionNonce || '');

                    fetch(this.config.ajaxUrl, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-WP-Nonce': this.config.sessionNonce || ''
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.success) {
                            window.location.reload();
                        } else {
                            alert((data && data.data && data.data.message) ? data.data.message : 'Failed to revoke sessions.');
                            revokeAllBtn.disabled = false;
                            revokeAllBtn.textContent = 'Log Out All Other Sessions';
                        }
                    })
                    .catch(err => {
                        console.error('[AstraeaOS] Revocation error:', err);
                        alert('Network error while revoking all other sessions.');
                        revokeAllBtn.disabled = false;
                        revokeAllBtn.textContent = 'Log Out All Other Sessions';
                    });
                });
            }
        },

        escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        decodeHtml(html) {
            if (!html || typeof html !== 'string' || !html.includes('&')) {
                return html || '';
            }
            try {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                return doc.body.textContent || '';
            } catch (e) {
                return html;
            }
        }
    };

    window.AstraeaAdmin = AstraeaAdmin;
    AstraeaAdmin.init();
})();
