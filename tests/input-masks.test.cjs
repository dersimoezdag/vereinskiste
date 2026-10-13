const {test} = require('node:test');
const assert = require('node:assert/strict');
const {money, compact, validIban, germanDate, isoDate} = require('../public/assets/input-masks.js');

test('money accepts the same decimal/grouping syntax as the server without rounding', () => {
    for (const [input, expected] of [['12', '12,00'], ['12.5', '12,50'], ['1.234,56', '1.234,56'],
        ['9.999.999,99', '9.999.999,99'], ['00012,5', '12,50'], ['1\u00a0234,56', '1.234,56']]) {
        assert.equal(money(input, false), expected);
    }
    for (const value of ['1.234', '1,234.56', '12,345', '10.000.000,00', '-12', '+12', '0', '1e3', 'abc', '']) {
        assert.equal(money(value, false), null, value);
    }
    assert.equal(money('-0.05', true), '-0,05');
    assert.equal(money('-0', true), '0,00');
    assert.equal(money('0', true), '0,00');
});

test('IBAN validates checksum and country length, accepts pasted whitespace and lowercase', () => {
    assert.equal(validIban('de89 3704 0044\n0532 0130 00'), true);
    assert.equal(validIban('DE88370400440532013000'), false);
    assert.equal(validIban('DE893704004405320130000'), false);
    assert.equal(validIban('not-an-iban'), false);
    assert.equal(validIban(''), false);
    assert.equal(compact('coba de ff xxx'), 'COBADEFFXXX');
});

 test('German dates round-trip and impossible dates remain invalid', () => {
    assert.equal(germanDate('2026-10-13'), '13.10.2026');
    assert.equal(isoDate('13.10.2026'), '2026-10-13');
    assert.equal(isoDate('29.02.2024'), '2024-02-29');
    for (const value of ['29.02.2026', '31.04.2026', '00.10.2026', '13.13.2026', '13.10.0000', '2026-10-13', '1.1.26']) {
        assert.equal(isoDate(value), null, value);
    }
});
