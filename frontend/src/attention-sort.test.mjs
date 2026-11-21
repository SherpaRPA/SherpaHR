import assert from 'node:assert/strict';
import { test } from 'node:test';
import { sortByLatestComment } from './attention-sort.ts';

const row = (id, created_at) => ({ id, latest_comment: created_at ? { created_at } : null });
const ids = rows => rows.map(({ id }) => id);

test('sorts by absolute comment time, newest first', () => {
  const rows = [row(1, '2026-11-20T14:00:00+03:00'), row(2, '2026-11-20T12:00:00Z')];
  assert.deepEqual(ids(sortByLatestComment(rows)), [2, 1]);
});

test('preserves API order at equal instants', () => {
  const rows = [row(8, '2026-11-20T14:00:00+03:00'), row(1, '2026-11-20T11:00:00Z')];
  assert.deepEqual(ids(sortByLatestComment(rows)), [8, 1]);
});

test('puts tasks without comments last in API order', () => {
  const rows = [row(8), row(2, '2026-11-20T12:00:00Z'), row(1)];
  assert.deepEqual(ids(sortByLatestComment(rows)), [2, 8, 1]);
});

test('does not mutate the API array', () => {
  const rows = Object.freeze([row(1), row(2, '2026-11-20T12:00:00Z')]);
  const sorted = sortByLatestComment(rows);
  assert.notEqual(sorted, rows);
  assert.deepEqual(ids(sorted), [2, 1]);
  assert.deepEqual(ids(rows), [1, 2]);
});
