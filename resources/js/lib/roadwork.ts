// Frontend mirror of App\Data\RoadworkStatus and App\Roadworks\RoadworkType,
// used to style the map popup from the GeoJSON feature properties (which carry
// raw Melvin fields rather than a server-rendered card DTO).

export type StatusKey = 'active' | 'planned' | 'done';

export interface StatusView {
    key: StatusKey;
    label: string;
    markerColor: string;
    chipBg: string;
    chipText: string;
}

const STATUS_PALETTE: Record<StatusKey, StatusView> = {
    active: {
        key: 'active',
        label: 'In uitvoering',
        markerColor: '#FFC400',
        chipBg: '#FFF3C2',
        chipText: '#7A5B00',
    },
    planned: {
        key: 'planned',
        label: 'Gepland',
        markerColor: '#2F6BD8',
        chipBg: '#E6EEFB',
        chipText: '#173E86',
    },
    done: {
        key: 'done',
        label: 'Afgerond',
        markerColor: '#1F8A5B',
        chipBg: '#E2F1E9',
        chipText: '#14633F',
    },
};

const NOW = () => Math.floor(Date.now() / 1000);

export function statusView(
    status: string | null,
    startTs: number | null,
    endTs: number | null,
): StatusView {
    if (status === 'running') {
        return STATUS_PALETTE.active;
    }
    if (status === 'final') {
        return STATUS_PALETTE.done;
    }
    const now = NOW();
    if (startTs !== null && startTs > now) {
        return STATUS_PALETTE.planned;
    }
    if (endTs !== null && endTs < now) {
        return STATUS_PALETTE.done;
    }
    return STATUS_PALETTE.active;
}

const TYPE_RULES: { keywords: string[]; label: string; icon: string }[] = [
    { keywords: ['gas'], label: 'Gas', icon: 'fa-fire-flame-simple' },
    { keywords: ['riol', 'riool'], label: 'Riool', icon: 'fa-droplet' },
    {
        keywords: ['water', 'drinkwater'],
        label: 'Water',
        icon: 'fa-faucet-drip',
    },
    { keywords: ['glasvezel', 'fiber'], label: 'Glasvezel', icon: 'fa-wifi' },
    {
        keywords: ['kabel', 'elektr', 'stroom', 'electr'],
        label: 'Kabels',
        icon: 'fa-bolt',
    },
    {
        keywords: ['asfalt', 'wegdek', 'bestrating', 'herstraat', 'klinker'],
        label: 'Wegdek',
        icon: 'fa-road',
    },
    { keywords: ['brug', 'kade'], label: 'Brug & kade', icon: 'fa-bridge' },
    { keywords: ['boom', 'groen', 'snoei'], label: 'Groen', icon: 'fa-tree' },
    {
        keywords: ['evenement', 'markt'],
        label: 'Evenement',
        icon: 'fa-calendar-day',
    },
];

export function typeView(
    activityType: string | null,
    title: string | null,
): { label: string; icon: string } {
    const haystack = `${activityType ?? ''} ${title ?? ''}`.toLowerCase();
    for (const rule of TYPE_RULES) {
        if (rule.keywords.some((keyword) => haystack.includes(keyword))) {
            return { label: rule.label, icon: rule.icon };
        }
    }
    return { label: 'Werkzaamheden', icon: 'fa-person-digging' };
}

const DATE_FMT = new Intl.DateTimeFormat('nl-NL', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
});

function fromTs(ts: number | null): string | null {
    return ts === null ? null : DATE_FMT.format(new Date(ts * 1000));
}

export function periodLabel(
    startTs: number | null,
    endTs: number | null,
): string {
    const start = fromTs(startTs);
    const end = fromTs(endTs);
    if (start && end) {
        return `${start} – ${end}`;
    }
    if (start) {
        return `Vanaf ${start}`;
    }
    if (end) {
        return `Tot ${end}`;
    }
    return 'Periode onbekend';
}
