'use strict';
(() => {
    const root = document.getElementById('automatic-update');
    const form = document.getElementById('automatic-step');
    if (!root || !form || root.dataset.phase === 'recovery_required') return;
    const token = form.querySelector('[name="_csrf"]').value;
    const terminal = ['done', 'failed', 'cancelled', 'rolled_back'];
    const failedPhases = ['failed', 'cancelled', 'rollback', 'rolled_back', 'recovery_required'];
    const steps = Array.from(root.querySelectorAll('[data-step-label]'));
    const stalled = document.getElementById('automatic-stalled');
    const number = new Intl.NumberFormat('de-DE');
    const maxRetries = 6;
    let failures = 0;
    let stopped = false;
    window.addEventListener('pagehide', () => { stopped = true; });

    const message = (selector) => document.querySelector(`#automatic-messages ${selector}`)?.textContent;

    // Gleiche Zuordnung wie im Template: die Pipeline folgt der Phase live, ohne Neuladen.
    function paint(state) {
        const failed = failedPhases.includes(state.phase);
        const focus = failed ? state.failed_phase : state.phase;
        let current = steps.findIndex((step) => (step.dataset.phases || '').split(' ').includes(focus));
        if (state.phase === 'done') current = steps.length;
        steps.forEach((step, index) => {
            step.classList.remove('is-active', 'is-done', 'is-failed');
            step.removeAttribute('aria-current');
            if (index < current) {
                step.classList.add('is-done');
            } else if (index === current) {
                step.classList.add(failed ? 'is-failed' : 'is-active');
                if (!failed) step.setAttribute('aria-current', 'step');
            }
        });
        root.dataset.phase = state.phase;
    }

    function showError(reason) {
        const error = document.getElementById('automatic-error');
        error.hidden = false;
        const text = error.querySelector('span:last-child');
        (text || error).textContent = message(`[data-error="${reason}"]`) || message('[data-error="update"]') || '';
    }

    function stall() {
        if (stalled) stalled.hidden = false;
    }

    // Netzwerkfehler und Zeitueberschreitungen: Teilschritt mit wachsender Pause wiederholen, dann manuell fortsetzen lassen.
    function retry() {
        failures += 1;
        if (failures > maxRetries) { stall(); return; }
        window.setTimeout(advance, Math.min(15000, 1500 * 2 ** (failures - 1)));
    }

    async function advance() {
        if (stopped) return;
        try {
            const response = await fetch('/settings/auto-update/step', {
                method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'},
                body: new URLSearchParams({_csrf: token}), signal: AbortSignal.timeout(25000)
            });
            if (response.status === 409) { window.setTimeout(advance, 1500); return; }
            const json = response.headers.get('Content-Type')?.includes('application/json');
            if (!json) {
                if (response.status >= 500) { retry(); } else { stall(); }
                return;
            }
            const state = await response.json();
            if (!response.ok) {
                if (state.error) showError(state.error);
                stall();
                return;
            }
            failures = 0;
            if (stalled) stalled.hidden = true;
            document.getElementById('automatic-status').textContent = message(`[data-phase="${state.phase}"]`) || state.phase;
            const inBackup = ['waiting', 'backup'].includes(state.phase);
            const inDownload = ['download', 'inspect', 'extract'].includes(state.phase) && state.downloaded !== undefined;
            let detail = '';
            if (inBackup && state.backup_rows !== undefined) detail = `${message('[data-label="backup_rows"]') || ''} ${number.format(state.backup_rows)}`.trim();
            else if (inDownload) detail = `${number.format(state.downloaded)} / ${number.format(state.size)} Bytes`;
            document.getElementById('automatic-detail').textContent = detail;
            const measure = document.getElementById('automatic-measure');
            if (measure) {
                const max = inBackup ? state.backup_tables : inDownload ? state.size : 0;
                const value = inBackup ? state.backup_table : state.downloaded;
                measure.hidden = !(max > 0);
                if (max > 0) { measure.max = max; measure.value = value || 0; }
            }
            paint(state);
            if (state.error) showError(state.error);
            if (terminal.includes(state.phase) || state.phase === 'recovery_required') { window.location.reload(); return; }
            if (state.probe_url) {
                try { await fetch(state.probe_url, {credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(10000)}); }
                catch (_) { /* Server validates the recorded probe; failure triggers rollback. */ }
            }
            window.setTimeout(advance, 500);
        } catch (_) {
            retry();
        }
    }
    window.setTimeout(advance, 500);
})();
