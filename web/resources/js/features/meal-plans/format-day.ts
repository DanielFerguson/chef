const dayFormatter = new Intl.DateTimeFormat('en-AU', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

export const formatDay = (date: string) =>
    dayFormatter.format(new Date(`${date.slice(0, 10)}T00:00:00Z`));
