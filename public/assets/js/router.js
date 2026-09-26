function route(name, params = {}, query = {}) {
    return { name, params: { ...params }, query: { ...query } };
}

function defaultRoute() {
    return route('unread');
}

function positiveId(value) {
    if (typeof value === 'number') {
        return Number.isSafeInteger(value) && value > 0 ? value : null;
    }
    if (typeof value === 'string' && /^[1-9]\d*$/.test(value)) {
        const parsed = Number(value);
        return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
    }
    return null;
}

function canonicalName(name) {
    const normalized = String(name || '').toLowerCase();
    const names = {
        '': 'home',
        '/': 'home',
        all: 'home',
        root: 'home',
        articles: 'home',
        'non-lus': 'unread',
        unread: 'unread',
        lus: 'read',
        read: 'read',
        favoris: 'favorites',
        favorites: 'favorites',
        favourite: 'favorites',
        recommandations: 'recommendations',
        recommandation: 'recommendations',
        recommendations: 'recommendations',
        'sans-categorie': 'uncategorized',
        uncategorized: 'uncategorized',
        recherche: 'search',
        search: 'search',
        gestion: 'manage',
        manage: 'manage',
        parametres: 'settings',
        paramètres: 'settings',
        settings: 'settings',
    };

    return names[normalized] || normalized;
}

function optionId(values, specificKey) {
    return positiveId(values.id ?? values[specificKey]);
}

function queryValues(values, query) {
    const nestedQuery = values.query !== null && typeof values.query === 'object'
        ? values.query
        : {};
    const directQuery = query !== null && typeof query === 'object' ? query : {};

    return {
        ...nestedQuery,
        ...directQuery,
        q: values.q ?? nestedQuery.q ?? directQuery.q,
        from: values.from ?? nestedQuery.from ?? directQuery.from,
    };
}

function withQuery(path, key, value) {
    if (value === null || value === undefined || value === '') {
        return path;
    }

    const parameters = new URLSearchParams();
    parameters.set(key, String(value));
    return `${path}?${parameters.toString()}`;
}

function articleCategoryQuery(parameters) {
    const categoryId = positiveId(parameters.get('category_id'));
    const category = parameters.get('category');
    if (categoryId !== null) {
        return { category_id: String(categoryId) };
    }
    return category === 'uncategorized' ? { category } : {};
}

export const DEFAULT_ROUTE = Object.freeze({
    name: 'unread',
    params: Object.freeze({}),
    query: Object.freeze({}),
});

export function parseRoute(hash = '') {
    let value = String(hash || '');
    const marker = value.indexOf('#');

    if (marker >= 0) {
        value = value.slice(marker + 1);
    } else if (!value.startsWith('/')) {
        return defaultRoute();
    }

    if (value === '' || value === '/') {
        return defaultRoute();
    }
    if (!value.startsWith('/')) {
        return defaultRoute();
    }

    const queryMarker = value.indexOf('?');
    const rawPath = queryMarker < 0 ? value : value.slice(0, queryMarker);
    const rawQuery = queryMarker < 0 ? '' : value.slice(queryMarker + 1);
    let path;

    try {
        path = decodeURIComponent(rawPath);
    } catch (error) {
        return defaultRoute();
    }

    if (path.length > 1 && path.endsWith('/')) {
        path = path.slice(0, -1);
    }

    const segments = path === '/' ? [] : path.slice(1).split('/');
    if (segments.some((segment) => segment === '')) {
        return defaultRoute();
    }

    const parameters = new URLSearchParams(rawQuery);
    const categoryQuery = articleCategoryQuery(parameters);
    const segment = segments[0];

    if (segment === undefined) {
        return defaultRoute();
    }
    if (segment === 'tous') {
        return route('home', {}, categoryQuery);
    }
    if (segment === 'non-lus') {
        return route('unread', {}, categoryQuery);
    }
    if (segment === 'lus') {
        return route('read', {}, categoryQuery);
    }
    if (segment === 'favoris') {
        return route('favorites', {}, categoryQuery);
    }
    if (segment === 'recommandations') {
        return route('recommendations', {}, categoryQuery);
    }
    if (segment === 'sans-categorie') {
        return route('uncategorized');
    }
    if (segment === 'recherche') {
        const q = parameters.get('q');
        return route('search', {}, { ...(q === null || q === '' ? {} : { q }), ...categoryQuery });
    }
    if (segment === 'gestion') {
        return route('manage');
    }
    if (segment === 'parametres') {
        return route('settings');
    }

    if (segment === 'categories' && segments.length === 2) {
        const id = positiveId(segments[1]);
        return id === null ? defaultRoute() : route('category', { id });
    }
    if (segment === 'flux' && segments.length === 2) {
        const id = positiveId(segments[1]);
        return id === null ? defaultRoute() : route('feed', { id }, categoryQuery);
    }
    if (segment === 'articles' && segments.length === 2) {
        const id = positiveId(segments[1]);
        if (id === null) {
            return defaultRoute();
        }
        const from = parameters.get('from');
        return route('article', { id }, from === null || from === '' ? {} : { from });
    }

    return defaultRoute();
}

export function buildRoute(name = 'home', values = {}, query = {}) {
    const canonical = canonicalName(name);
    const options = values !== null && typeof values === 'object' ? values : {};
    const queries = queryValues(options, query);

    if (canonical === 'home') {
        return '/#/tous';
    }
    if (canonical === 'unread') {
        return '/#/non-lus';
    }
    if (canonical === 'read') {
        return '/#/lus';
    }
    if (canonical === 'favorites') {
        return '/#/favoris';
    }
    if (canonical === 'recommendations') {
        return '/#/recommandations';
    }
    if (canonical === 'uncategorized') {
        return '/#/sans-categorie';
    }
    if (canonical === 'manage') {
        return '/#/gestion';
    }
    if (canonical === 'settings') {
        return '/#/parametres';
    }
    if (canonical === 'search') {
        return withQuery('/#/recherche', 'q', queries.q);
    }
    if (canonical === 'category') {
        const id = optionId(options, 'categoryId');
        return id === null ? '/#/' : `/#/categories/${id}`;
    }
    if (canonical === 'feed') {
        const id = optionId(options, 'feedId');
        return id === null ? '/#/' : `/#/flux/${id}`;
    }
    if (canonical === 'article') {
        const id = optionId(options, 'articleId');
        if (id === null) {
            return '/#/';
        }
        return withQuery(`/#/articles/${id}`, 'from', queries.from);
    }

    return '/#/';
}
