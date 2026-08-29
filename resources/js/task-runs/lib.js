/**
 * Human label for a task type — hosts can pretty-print by choosing readable type keys.
 */
export function typeLabel(type) {
    return type.replaceAll('-', ' ').replaceAll('_', ' ');
}

/**
 * Compact relative time, dependency-free.
 */
export function timeAgo(iso) {
    if (!iso) {
        return '';
    }

    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    return `${Math.round(hours / 24)}d ago`;
}
