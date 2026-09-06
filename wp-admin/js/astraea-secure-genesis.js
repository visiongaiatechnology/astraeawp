// STATUS: DIAMANT VGT SUPREME
(() => {
    'use strict';

    const form = document.getElementById('astraea-secure-genesis');
    if (!form) return;

    const steps = Array.from(form.querySelectorAll('[data-genesis-step]'));
    const progress = Array.from(document.querySelectorAll('[data-progress-node]'));
    const back = form.querySelector('[data-genesis-back]');
    const next = form.querySelector('[data-genesis-next]');
    const submit = form.querySelector('[data-genesis-submit]');
    const recoveryNode = document.getElementById('astraea-recovery-key');
    let current = 1;

    const setStep = (step) => {
        current = Math.max(1, Math.min(8, step));
        steps.forEach((node) => {
            const active = Number(node.getAttribute('data-genesis-step')) === current;
            node.classList.toggle('is-active', active);
            node.hidden = !active;
        });
        progress.forEach((node) => {
            const index = Number(node.getAttribute('data-progress-node'));
            node.classList.toggle('is-active', index === current);
            node.classList.toggle('is-complete', index < current);
        });
        if (back instanceof HTMLButtonElement) back.disabled = current === 1;
        if (next instanceof HTMLButtonElement) next.hidden = current === 8;
        if (submit instanceof HTMLButtonElement) submit.hidden = current !== 8;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const validateCurrent = () => {
        const active = steps.find((node) => Number(node.getAttribute('data-genesis-step')) === current);
        if (!active) return true;
        const required = Array.from(active.querySelectorAll('input[required], select[required], textarea[required]'));
        for (const field of required) {
            if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                if (!field.checkValidity()) {
                    field.reportValidity();
                    return false;
                }
            }
        }
        return true;
    };

    next?.addEventListener('click', () => {
        if (validateCurrent()) setStep(current + 1);
    });
    back?.addEventListener('click', () => setStep(current - 1));

    form.addEventListener('submit', (event) => {
        if (!validateCurrent() || !form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
            submit.textContent = 'Compiling Secure Genesis…';
        }
        if (back instanceof HTMLButtonElement) back.disabled = true;
    });

    document.querySelector('[data-copy-recovery]')?.addEventListener('click', async () => {
        if (!recoveryNode) return;
        const value = recoveryNode.textContent || '';
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            const area = document.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', 'readonly');
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
    });

    document.querySelector('[data-download-recovery]')?.addEventListener('click', () => {
        if (!recoveryNode) return;
        const key = recoveryNode.textContent || '';
        const body = `AstraeaOS WP — ThroneGuard Recovery Key\n\n${key}\n\nStore offline. Astraea does not retain this plaintext key.\n`;
        const blob = new Blob([body], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'astraea-throneguard-recovery-key.txt';
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });

    const syncCustomState = () => {
        const selected = form.querySelector('input[name="astraea_genesis[security_profile]"]:checked');
        const custom = selected instanceof HTMLInputElement && selected.value === 'custom';
        form.querySelectorAll('[data-custom-security] input').forEach((node) => {
            if (node instanceof HTMLInputElement) node.disabled = !custom;
        });
        form.querySelectorAll('[data-custom-security]').forEach((node) => node.classList.toggle('is-disabled', !custom));
    };
    form.querySelectorAll('input[name="astraea_genesis[security_profile]"]').forEach((node) => node.addEventListener('change', syncCustomState));
    syncCustomState();
    setStep(1);
})();
