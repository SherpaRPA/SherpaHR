const minute = 60_000;
const hour = 60 * minute;
const day = 24 * hour;

export function formatCommentAge(createdAt: string, now: number): string | null {
  const elapsed = now - Date.parse(createdAt);
  if (!Number.isFinite(elapsed)) return null;
  if (elapsed < minute) return 'менее минуты';
  if (elapsed > 30 * day) return 'более месяца';
  if (elapsed < hour) return `${Math.floor(elapsed / minute)} мин`;

  if (elapsed < day) {
    const hours = Math.floor(elapsed / hour);
    const minutes = Math.floor((elapsed % hour) / minute);
    return `${hours} ч.${minutes ? ` ${minutes} мин.` : ''}`;
  }

  const days = Math.floor(elapsed / day);
  const hours = Math.floor((elapsed % day) / hour);
  return `${days} д.${hours ? ` ${hours} ч.` : ''}`;
}
