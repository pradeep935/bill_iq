import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDate, formatDateTime, formatDateField } from '../../resources/js/Support/dateFormat.js';
test('calendar dates and UTC midnight serialization display Indian date', () => {
 assert.equal(formatDate('2026-09-13'), '13/09/2026');
 assert.equal(formatDate('2026-09-12T18:30:00.000000Z'), '13/09/2026');
 assert.equal(formatDate('2026-12-31T20:00:00Z'), '01/01/2027');
});
test('date time uses Kolkata and uppercase 12-hour time', () => {
 assert.equal(formatDateTime('2026-09-13T05:12:00Z'), '13/09/2026, 10:42 AM');
 assert.equal(formatDateTime('2026-09-13 22:42:00'), '13/09/2026, 10:42 PM');
 assert.equal(formatDateTime('2026-09-13T00:00:00+05:30'), '13/09/2026, 12:00 AM');
});
test('empty and invalid values are safe; only date fields are transformed', () => {
 for (const value of [null, undefined, '', 'invalid']) assert.equal(formatDate(value), '—');
 assert.equal(formatDateField('voucher_date', '2026-09-13'), '13/09/2026');
 assert.equal(formatDateField('created_at', '2026-09-13T05:12:00Z'), '13/09/2026, 10:42 AM');
 assert.equal(formatDateField('voucher_number', 'REC-2026-00001'), 'REC-2026-00001');
});
