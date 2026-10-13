'use strict';
(() => {
    const root = document.getElementById('automatic-update');
    const form = document.getElementById('automatic-step');
    if (!root || !form || root.dataset.phase === 'recovery_required') return;
    const token = form.querySelector('[name="_csrf"]').value;
    const terminal = ['done', 'failed', 'cancelled', 'rolled_back'];
    let stopped = false;
    window.addEventListener('pagehide', () => { stopped = true; });
    async function advance() {
        if (stopped) return;
        try {
            const response = await fetch('/settings/auto-update/step', {
                method: 'POST', credentials: 'same-origin', headers: {'Accept':'application/json'},
                body: new URLSearchParams({_csrf: token}), signal: AbortSignal.timeout(25000)
            });
            if (response.status === 409) { window.setTimeout(advance, 1500); return; }
            if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) return;
            const state = await response.json();
            document.getElementById('automatic-status').textContent = document.querySelector(`#automatic-messages [data-phase="${state.phase}"]`)?.textContent || state.phase;
            document.getElementById('automatic-detail').textContent = state.backup_rows !== undefined
                ? `Gesicherte Datensätze: ${state.backup_rows}` : state.downloaded !== undefined ? `${state.downloaded} / ${state.size} Bytes` : '';
            if (state.error) {
                const error = document.getElementById('automatic-error'); error.hidden = false;
                error.textContent = document.querySelector(`#automatic-messages [data-error="${state.error}"]`)?.textContent || 'Update fehlgeschlagen.';
            }
            if (terminal.includes(state.phase) || state.phase === 'recovery_required') { window.location.reload(); return; }
            if (state.probe_url) {
                try { await fetch(state.probe_url, {credentials:'same-origin', cache:'no-store', signal:AbortSignal.timeout(10000)}); }
                catch (_) { /* Server validates the recorded probe; failure triggers rollback. */ }
            }
            window.setTimeout(advance, 500);
        } catch (_) { /* Manual continuation remains available; cron also resumes interrupted steps. */ }
    }
    window.setTimeout(advance, 500);
})();
