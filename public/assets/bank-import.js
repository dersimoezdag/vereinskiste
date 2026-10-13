'use strict';
(() => {
    const progress = document.getElementById('bank-progress');
    if (!progress) return;
    let stopped = false;
    window.addEventListener('pagehide', () => { stopped = true; });
    async function poll() {
        if (stopped) return;
        try {
            const response = await fetch(progress.dataset.statusUrl, {
                credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(8000)
            });
            if (!response.ok) return;
            const result = await response.json();
            if (result.status !== 'parsing') { window.location.reload(); return; }
            progress.textContent = `Vorprüfung: ${result.parsed} von ${result.total} Zeilen. Verarbeitung über Cron oder die Schaltfläche unten.`;
        } catch (_) { /* The manual continuation remains available. */ }
        if (!stopped) window.setTimeout(poll, 5000);
    }
    window.setTimeout(poll, 5000);
})();
