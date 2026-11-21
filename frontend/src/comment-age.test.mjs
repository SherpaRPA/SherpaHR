import assert from 'node:assert/strict';
import { test } from 'node:test';
import { formatCommentAge } from './comment-age.ts';

const now = Date.parse('2026-11-21T20:34:00Z');

for (const [label, seconds, expected] of [
  ['меньше минуты', 59, 'менее минуты'],
  ['ровно минута', 60, '1 мин'],
  ['59 полных минут', 3599, '59 мин'],
  ['ровно час', 3600, '1 ч.'],
  ['часы с минутами', 3719, '1 ч. 1 мин.'],
  ['ровно сутки', 86400, '1 д.'],
  ['дни с часами', 5 * 86400 + 2 * 3600 + 59 * 60, '5 д. 2 ч.'],
  ['ровно 30 суток', 30 * 86400, '30 д.'],
  ['30 суток и секунда', 30 * 86400 + 1, 'более месяца'],
  ['будущая метка', -60, 'менее минуты'],
]) {
  test(label, () => {
    assert.equal(formatCommentAge(new Date(now - seconds * 1000).toISOString(), now), expected);
  });
}

test('некорректная метка', () => {
  assert.equal(formatCommentAge('not a timestamp', now), null);
});

test('метка с явным смещением', () => {
  assert.equal(formatCommentAge('2026-11-21T16:24:00+03:00', now), '7 ч. 10 мин.');
});
