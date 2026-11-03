const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const markup = `<form><div class="signature-pad" data-signature-pad data-signature-required>
  <div class="signature-surface"><canvas class="signature-canvas"></canvas></div>
  <input type="hidden" name="signature[1]" value="" data-signature-value>
  <button type="button" data-signature-clear>Löschen</button>
  <button type="button" data-signature-enlarge>Größer</button></div></form>`;

function setup(value = '') {
    const dom = new JSDOM(markup.replace('value=""', `value="${value}"`), {runScripts: 'outside-only', pretendToBeVisual: true});
    const w = dom.window;
    const calls = [];
    w.HTMLCanvasElement.prototype.getContext = function () {
        return {setTransform() {}, clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, quadraticCurveTo() {}, stroke() {}, drawImage() {},
            set lineWidth(v) {}, set lineCap(v) {}, set lineJoin(v) {}, set strokeStyle(v) {}, scale() {}};
    };
    w.HTMLCanvasElement.prototype.toDataURL = function () { calls.push([this.width, this.height]); return 'data:image/png;base64,AAAA'; };
    w.HTMLCanvasElement.prototype.getBoundingClientRect = () => ({left: 0, top: 0, width: 375, height: 100});
    w.HTMLCanvasElement.prototype.setPointerCapture = () => {};
    require('./browser-texts.cjs')(w);
    w.eval(readFileSync(require.resolve('../public/assets/signature-pad.js'), 'utf8'));
    w.VkSignaturePad.init();
    return {w, d: w.document, calls};
}

function draw(w, canvas) {
    const ev = (type, x, y) => canvas.dispatchEvent(new w.MouseEvent(type, {bubbles: true, clientX: x, clientY: y, buttons: 1}));
    ev('pointerdown', 10, 50); ev('pointermove', 60, 40); ev('pointermove', 120, 60); ev('pointerup', 120, 60);
}

test('drawing writes a PNG data URL and fires a change event', () => {
    const {w, d, calls} = setup();
    try {
        const pad = d.querySelector('[data-signature-pad]'), hidden = d.querySelector('[data-signature-value]');
        let changes = 0; pad.addEventListener('signature:change', () => changes++);
        draw(w, d.querySelector('canvas'));
        assert.equal(hidden.value, 'data:image/png;base64,AAAA');
        assert.deepEqual(calls.at(-1), [900, 240]);
        assert.ok(pad.classList.contains('has-signature'));
        assert.equal(changes, 1);
    } finally { w.close(); }
});

test('a tap without movement does not count as signature', () => {
    const {w, d} = setup();
    try {
        const canvas = d.querySelector('canvas');
        canvas.dispatchEvent(new w.MouseEvent('pointerdown', {bubbles: true, clientX: 10, clientY: 10}));
        canvas.dispatchEvent(new w.MouseEvent('pointerup', {bubbles: true, clientX: 10, clientY: 10}));
        assert.equal(d.querySelector('[data-signature-value]').value, '');
    } finally { w.close(); }
});

test('clear empties the value and restored signatures count as present', () => {
    const {w, d} = setup('data:image/png;base64,BBBB');
    try {
        const pad = d.querySelector('[data-signature-pad]'), hidden = d.querySelector('[data-signature-value]');
        assert.ok(pad.classList.contains('has-signature'));
        d.querySelector('[data-signature-clear]').click();
        assert.equal(hidden.value, '');
        assert.ok(!pad.classList.contains('has-signature'));
    } finally { w.close(); }
});

test('enlarge opens a dialog and apply keeps strokes drawn there', () => {
    const {w, d} = setup();
    try {
        d.querySelector('[data-signature-enlarge]').click();
        const dialog = d.querySelector('.signature-dialog');
        assert.ok(dialog && !dialog.hidden);
        draw(w, dialog.querySelector('canvas'));
        dialog.querySelector('[data-signature-apply]').click();
        assert.ok(dialog.hidden);
        assert.equal(d.querySelector('[data-signature-value]').value, 'data:image/png;base64,AAAA');
    } finally { w.close(); }
});
