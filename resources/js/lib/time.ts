const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

/** "2 minutes ago", "in 5 hours"… from an ISO 8601 timestamp. */
export function relativeTime(
    iso: string | null,
    now: number = Date.now(),
): string {
    if (!iso) {
        return '';
    }

    const seconds = Math.round((Date.parse(iso) - now) / 1000);

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return '';
}
