const STATUS_LABELS = {
    running: 'Workers running',
    paused: 'Workers paused',
    inactive: 'Workers inactive',
    unknown: 'Workers unknown',
    unavailable: null,
};

export function HorizonPill({ horizon }) {
    if (!horizon || horizon.status === 'unavailable') {
        return null;
    }

    const label = STATUS_LABELS[horizon.status] ?? STATUS_LABELS.unknown;

    return (
        <span className={`tr-pill ${horizon.status}`}>
            <span className="tr-dot" />
            {label}
            <span className="tr-pill-count">{horizon.masters}</span>
        </span>
    );
}
