// STATUS: DIAMANT VGT SUPREME
(() => {
    'use strict';
    const select = document.getElementById('astmail-provider');
    if (!select || typeof AstraeaMailPresets !== 'object' || AstraeaMailPresets === null) return;

    const host = document.getElementById('astmail-host');
    const port = document.getElementById('astmail-port');
    const encryption = document.getElementById('astmail-encryption');
    const authType = document.getElementById('astmail-auth-type');
    const oauthTokenUrl = document.getElementById('astmail-oauth-token-url');
    const oauthScope = document.getElementById('astmail-oauth-scope');
    const oauthSection = document.getElementById('astmail-oauth-section');
    const note = document.getElementById('astmail-provider-note');

    const syncOAuthVisibility = () => {
        if (!oauthSection || !authType) return;
        oauthSection.hidden = authType.value !== 'xoauth2';
    };

    const apply = (force) => {
        const preset = AstraeaMailPresets[select.value];
        if (!preset) return;
        if (force && host && typeof preset.host === 'string') host.value = preset.host;
        if (force && port && Number.isInteger(Number(preset.port))) port.value = String(preset.port);
        if (force && encryption && typeof preset.encryption === 'string') encryption.value = preset.encryption;
        if (force && authType && typeof preset.auth_type === 'string') authType.value = preset.auth_type;
        if (force && oauthTokenUrl && typeof preset.oauth_token_url === 'string') oauthTokenUrl.value = preset.oauth_token_url;
        if (force && oauthScope && typeof preset.oauth_scope === 'string') oauthScope.value = preset.oauth_scope;
        if (note) note.textContent = typeof preset.note === 'string' ? preset.note : '';
        syncOAuthVisibility();
    };

    select.addEventListener('change', () => apply(true));
    authType?.addEventListener('change', syncOAuthVisibility);
    apply(false);
})();
