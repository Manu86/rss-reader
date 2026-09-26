const UNKNOWN_DATE = 'Date inconnue';
const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

function dateValue(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

function positiveInteger(value) {
    const number = typeof value === 'number' ? value : Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

function nonNegativeInteger(value) {
    const number = typeof value === 'number' ? value : Number(value);
    return Number.isSafeInteger(number) && number >= 0 ? number : null;
}

function lastFetchStatus(value) {
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
        if (value.is_active === false) {
            return 'disabled';
        }
        return value.last_fetch_status;
    }
    return value;
}

export function formatDate(value) {
    const date = dateValue(value);
    return date === null ? UNKNOWN_DATE : dateFormatter.format(date);
}

export function formatDateTime(value) {
    const date = dateValue(value);
    if (date === null) {
        return UNKNOWN_DATE;
    }

    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');
    return `${dateFormatter.format(date)} à ${hours}:${minutes} UTC`;
}

export function formatNumber(value, options = {}) {
    if (
        (typeof value !== 'number' && typeof value !== 'string')
        || (typeof value === 'string' && value.trim() === '')
    ) {
        return '—';
    }

    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '—';
    }

    return new Intl.NumberFormat('fr-FR', options).format(number);
}

export function formatFeedStatus(value) {
    const status = lastFetchStatus(value);

    if (status === null || status === undefined || status === 'never') {
        return 'Jamais récupéré';
    }
    if (status === 'success') {
        return 'Récupération réussie';
    }
    if (status === 'error') {
        return 'Erreur de récupération';
    }
    if (status === 'not_modified') {
        return 'Déjà à jour';
    }
    if (status === 'disabled') {
        return 'Désactivé';
    }
    if (status === 'pending') {
        return 'Récupération en cours';
    }

    return 'Statut inconnu';
}

export function formatPageLabel(page, totalPages) {
    const safePage = positiveInteger(page);
    const safeTotal = nonNegativeInteger(totalPages);

    if (safePage === null || safeTotal === null) {
        return 'Pagination inconnue';
    }
    if (safeTotal === 0) {
        return 'Aucune page';
    }

    return `Page ${formatNumber(safePage)} sur ${formatNumber(safeTotal)}`;
}

export function formatItemCountLabel(totalItems) {
    const total = nonNegativeInteger(totalItems);
    if (total === null) {
        return 'Nombre d’articles inconnu';
    }

    return `${formatNumber(total)} article${total > 1 ? 's' : ''}`;
}

export function formatPaginationLabels(pagination) {
    const value = pagination !== null && typeof pagination === 'object'
        ? pagination
        : {};

    return {
        page: formatPageLabel(value.page, value.total_pages),
        items: formatItemCountLabel(value.total_items),
    };
}

export function formatPaginationLabel(pagination) {
    const labels = formatPaginationLabels(pagination);
    return `${labels.page} · ${labels.items}`;
}

export { UNKNOWN_DATE };
