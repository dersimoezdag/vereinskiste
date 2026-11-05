const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const markup = `<form><div class="signature-pad" data-signature-pad data-signature-required>
  <div class="signature-surface"><canvas class="signature-canvas"></canvas></div>
  <input type="hidden" name="signature[1]" value="" data-signature-value>
  <button type="button" data-signature-clear>Löschen</button>
  <button type="button" data-signature-enlarge>Größer</button></div></form>`;

function setup(value = '', rectangle = () => ({left: 0, top: 0, width: 375, height: 100}), observer) {
    const dom = new JSDOM(markup.replace('value=""', `value="${value}"`), {runScripts: 'outside-only', pretendToBeVisual: true});
    const w = dom.window;
    if (observer) { w.ResizeObserver = observer; }
    const calls = [];
    w.HTMLCanvasElement.prototype.getContext = function () {
        return {setTransform() {}, clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, quadraticCurveTo() {}, stroke() {}, drawImage() {},
            set lineWidth(v) {}, set lineCap(v) {}, set lineJoin(v) {}, set strokeStyle(v) {}, scale() {}};
    };
    w.HTMLCanvasElement.prototype.toDataURL = function () { calls.push([this.width, this.height]); return 'data:image/png;base64,AAAA'; };
    w.HTMLCanvasElement.prototype.getBoundingClientRect = rectangle;
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

test('revealing a mobile pad and tapping or drawing never overrides its CSS height', () => {
    let width = 0, resize;
    const observed = [];
    class Observer {
        constructor(callback) { resize = callback; }
        observe(canvas) { observed.push(canvas); }
    }
    const {w, d, calls} = setup('', () => ({left: 0, top: 0, width, height: width / 3.75}), Observer);
    try {
        const canvas = d.querySelector('canvas');
        assert.equal(canvas.style.height, '');
        width = 300;
        assert.ok(observed.includes(canvas));
        resize([{target: canvas}]);
        assert.equal(canvas.width, 300);
        canvas.dispatchEvent(new w.MouseEvent('pointerdown', {clientX: 10, clientY: 10}));
        canvas.dispatchEvent(new w.MouseEvent('pointerup', {clientX: 10, clientY: 10}));
        assert.equal(canvas.style.height, '');
        assert.equal(d.querySelector('[data-signature-value]').value, '');
        draw(w, canvas);
        assert.equal(canvas.style.height, '');
        assert.equal(canvas.width, 300);
        assert.equal(canvas.height, 80);
        assert.deepEqual(calls.at(-1), [900, 240]);
        width = 375;
        w.dispatchEvent(new w.Event('resize'));
        assert.equal(canvas.width, 375);
        assert.equal(canvas.style.height, '');
        assert.equal(d.querySelector('[data-signature-value]').value, 'data:image/png;base64,AAAA');
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

test('cancel, escape and backdrop close the dialog and keep the previous signature', () => {
    const {w, d} = setup('data:image/png;base64,BBBB');
    try {
        const hidden = d.querySelector('[data-signature-value]');
        const close = [
            dialog => dialog.querySelector('[data-signature-cancel]').click(),
            dialog => dialog.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true})),
            dialog => dialog.dispatchEvent(new w.MouseEvent('click', {bubbles: true})),
        ];
        for (const action of close) {
            d.querySelector('[data-signature-enlarge]').click();
            const dialog = d.querySelector('.signature-dialog');
            dialog.querySelector('[data-signature-dialog-clear]').click();
            draw(w, dialog.querySelector('canvas'));
            action(dialog);
            assert.ok(dialog.hidden);
            assert.ok(!d.documentElement.classList.contains('overflow-hidden'));
            assert.equal(hidden.value, 'data:image/png;base64,BBBB');
        }
    } finally { w.close(); }
});
