// Unterschriftsfeld fuer Maus, Finger und Stift. Striche werden normiert (0..1) gespeichert,
// damit Feld und Vollbild-Dialog dieselbe Unterschrift zeigen; Export als PNG 900 x 240.
(function () {
    'use strict';
    var RATIO = 3.75, EXPORT_W = 900, EXPORT_H = 240, INK = '#111827';
    function text(key, fallback) {
        var value = window.vkText ? window.vkText(key) : key;
        return value === key ? fallback : value;
    }

    function Pad(root) {
        this.root = root;
        this.canvas = root.querySelector('canvas');
        this.input = root.querySelector('[data-signature-value]');
        this.strokes = [];
        this.base = null; // wiederhergestellte Unterschrift (Data-URL)
        if (this.input.value) { this.base = this.input.value; this.loadBase(); }
        this.bind(this.canvas);
        var self = this;
        root.querySelector('[data-signature-clear]').addEventListener('click', function () { self.clear(); });
        var enlarge = root.querySelector('[data-signature-enlarge]');
        if (enlarge) { enlarge.addEventListener('click', function () { self.openDialog(); }); }
        window.addEventListener('resize', function () { self.render(self.canvas); });
        this.render(this.canvas);
        this.mark();
    }

    Pad.prototype.loadBase = function () {
        if (typeof Image === 'undefined') { return; }
        var self = this, img = new Image();
        img.onload = function () { self.baseImage = img; self.render(self.canvas); };
        img.src = this.base;
    };

    Pad.prototype.size = function (canvas) {
        var rect = canvas.getBoundingClientRect();
        var width = rect.width || canvas.clientWidth || 600;
        return {width: width, height: width / RATIO};
    };

    Pad.prototype.render = function (canvas, exportSize) {
        var size = exportSize || this.size(canvas);
        var dpr = exportSize ? 1 : (window.devicePixelRatio || 1);
        canvas.width = Math.round(size.width * dpr);
        canvas.height = Math.round(size.height * dpr);
        if (!exportSize) { canvas.style.height = size.height + 'px'; }
        var ctx = canvas.getContext('2d');
        if (!ctx) { return; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, size.width, size.height);
        if (this.baseImage) { ctx.drawImage(this.baseImage, 0, 0, size.width, size.height); }
        ctx.lineWidth = Math.max(2, size.width / 250);
        ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = INK;
        for (var i = 0; i < this.strokes.length; i++) { this.drawStroke(ctx, this.strokes[i], size); }
    };

    Pad.prototype.drawStroke = function (ctx, points, size) {
        if (points.length < 2) { return; }
        ctx.beginPath();
        ctx.moveTo(points[0][0] * size.width, points[0][1] * size.height);
        for (var i = 1; i < points.length - 1; i++) {
            var mx = (points[i][0] + points[i + 1][0]) / 2, my = (points[i][1] + points[i + 1][1]) / 2;
            ctx.quadraticCurveTo(points[i][0] * size.width, points[i][1] * size.height, mx * size.width, my * size.height);
        }
        var last = points[points.length - 1];
        ctx.lineTo(last[0] * size.width, last[1] * size.height);
        ctx.stroke();
    };

    Pad.prototype.bind = function (canvas) {
        var self = this, current = null;
        function point(e) {
            var rect = canvas.getBoundingClientRect();
            var w = rect.width || 1, h = rect.height || w / RATIO;
            return [Math.min(1, Math.max(0, (e.clientX - rect.left) / w)), Math.min(1, Math.max(0, (e.clientY - rect.top) / h))];
        }
        canvas.addEventListener('pointerdown', function (e) {
            if (e.button !== undefined && e.button > 0) { return; }
            e.preventDefault();
            if (canvas.setPointerCapture && e.pointerId !== undefined) { canvas.setPointerCapture(e.pointerId); }
            current = [point(e)];
            self.strokes.push(current);
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!current) { return; }
            e.preventDefault();
            current.push(point(e));
            self.render(canvas);
        });
        function end() {
            if (!current) { return; }
            if (current.length < 2) { self.strokes.pop(); }
            current = null;
            if (canvas === self.canvas) { self.commit(); }
        }
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', end);
    };

    Pad.prototype.hasInk = function () { return this.strokes.length > 0 || !!this.base; };

    Pad.prototype.commit = function () {
        var value = '';
        if (this.hasInk()) {
            var out = document.createElement('canvas');
            this.render(out, {width: EXPORT_W, height: EXPORT_H});
            value = out.toDataURL('image/png');
        }
        this.input.value = value;
        this.render(this.canvas);
        this.mark();
        this.root.dispatchEvent(new CustomEvent('signature:change', {bubbles: true, detail: {value: value}}));
    };

    Pad.prototype.mark = function () {
        this.root.classList.toggle('has-signature', this.input.value !== '');
        if (this.input.value !== '') { this.root.classList.remove('is-invalid'); }
    };

    Pad.prototype.clear = function () {
        this.strokes = []; this.base = null; this.baseImage = null;
        this.commit();
    };

    Pad.prototype.openDialog = function () {
        var self = this;
        if (!this.dialog) {
            var d = document.createElement('div');
            d.className = 'signature-dialog';
            d.setAttribute('role', 'dialog');
            d.setAttribute('aria-modal', 'true');
            d.innerHTML = '<div class="signature-dialog-body"><div class="signature-surface"><canvas class="signature-canvas"></canvas></div>'
                + '<div class="mt-3 flex flex-wrap justify-end gap-2"><button type="button" class="btn" data-signature-dialog-clear></button>'
                + '<button type="button" class="btn btn-primary" data-signature-apply></button></div></div>';
            d.querySelector('[data-signature-dialog-clear]').textContent = text('js.signature_clear', 'Löschen');
            d.querySelector('[data-signature-apply]').textContent = text('js.signature_apply', 'Übernehmen');
            document.body.appendChild(d);
            this.dialog = d;
            this.bind(d.querySelector('canvas'));
            d.querySelector('[data-signature-dialog-clear]').addEventListener('click', function () {
                self.strokes = []; self.base = null; self.baseImage = null; self.render(d.querySelector('canvas'));
            });
            d.querySelector('[data-signature-apply]').addEventListener('click', function () {
                d.hidden = true; document.documentElement.classList.remove('overflow-hidden'); self.commit();
            });
        }
        this.dialog.hidden = false;
        document.documentElement.classList.add('overflow-hidden');
        this.render(this.dialog.querySelector('canvas'));
    };

    function init(root) {
        (root || document).querySelectorAll('[data-signature-pad]').forEach(function (el) {
            if (!el.vkSignaturePad) { el.vkSignaturePad = new Pad(el); }
        });
    }
    window.VkSignaturePad = {init: init};
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
})();
