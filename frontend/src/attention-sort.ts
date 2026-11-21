import type { AttentionTask } from './models';

export function sortByLatestComment<T extends Pick<AttentionTask, 'latest_comment'>>(rows: readonly T[]): T[] {
  return rows.map((row, index) => {
    const timestamp = row.latest_comment ? Date.parse(row.latest_comment.created_at) : NaN;
    return { row, index, timestamp: Number.isFinite(timestamp) ? timestamp : -Infinity };
  }).sort((a, b) => b.timestamp - a.timestamp || a.index - b.index)
    .map(({ row }) => row);
}
