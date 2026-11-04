// Einmaliges Konfetti auf der Dankeseite des Mitgliedsantrags. Entfaellt bei reduzierter Bewegung.
(function () {
    'use strict';
    var COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0891b2'];
    var COUNT = 160, DURATION = 4500;

    function burst(canvas) {
        var ctx = canvas.getContext && canvas.getContext('2d');
        if (!ctx) { return; }
        var dpr = window.devicePixelRatio || 1, width = 0, height = 0;
        function resize() {
            width = window.innerWidth; height = window.innerHeight;
            canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }
        resize();
        window.addEventListener('resize', resize);
        var pieces = [];
        for (var i = 0; i < COUNT; i++) {
            // Zwei Fontaenen von den unteren Ecken schraeg zur Mitte.
            var left = i % 2 === 0, angle = (left ? -60 : -120) * Math.PI / 180 + (Math.random() - 0.5) * 0.7;
            var speed = 9 + Math.random() * 9;
            pieces.push({x: left ? 0 : width, y: height * 0.85, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed,
                w: 6 + Math.random() * 6, h: 4 + Math.random() * 4, r: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.3,
                color: COLORS[i % COLORS.length]});
        }
        var start = null;
        function frame(now) {
            if (start === null) { start = now; }
            var elapsed = now - start;
            ctx.clearRect(0, 0, width, height);
            ctx.globalAlpha = elapsed > DURATION - 1000 ? Math.max(0, (DURATION - elapsed) / 1000) : 1;
            pieces.forEach(function (p) {
                p.vx *= 0.99; p.vy = p.vy * 0.99 + 0.25; p.x += p.vx; p.y += p.vy; p.r += p.vr;
                ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r);
                ctx.fillStyle = p.color; ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2)));
                ctx.restore();
            });
            if (elapsed < DURATION) { window.requestAnimationFrame(frame); } else {
                window.removeEventListener('resize', resize);
                canvas.remove();
            }
        }
        window.requestAnimationFrame(frame);
    }

    function init() {
        var canvas = document.querySelector('[data-confetti]');
        if (!canvas) { return; }
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { canvas.remove(); return; }
        burst(canvas);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
