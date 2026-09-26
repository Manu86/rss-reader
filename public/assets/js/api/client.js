const MUTATING_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
const DEFAULT_BASE_URL = '/api';

function isFormData(value) {
    return typeof FormData !== 'undefined' && value instanceof FormData;
}

function normalizeHeaders(headers) {
    const normalized = {};

    if (headers === null || headers === undefined) {
        return normalized;
    }

    if (typeof headers.forEach === 'function') {
        headers.forEach((value, key) => {
            normalized[String(key).toLowerCase()] = String(value);
        });

        return normalized;
    }

    Object.keys(headers).forEach((key) => {
        const value = headers[key];
        if (value !== null && value !== undefined) {
            normalized[key.toLowerCase()] = String(value);
        }
    });

    return normalized;
}

function deleteHeader(headers, name) {
    const normalizedName = name.toLowerCase();
    Object.keys(headers).forEach((key) => {
        if (key === normalizedName) {
            delete headers[key];
        }
    });
}

function setHeader(headers, name, value) {
    deleteHeader(headers, name);
    headers[name.toLowerCase()] = value;
}

function appendQuery(parameters, key, value) {
    if (value === null || value === undefined) {
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item) => appendQuery(parameters, key, item));
        return;
    }

    parameters.append(key, String(value));
}

function validResponseStatus(status) {
    return Number.isInteger(status) && status >= 200 && status <= 599;
}

function objectFields(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? value
        : {};
}

export class ApiError extends Error {
    constructor(message, status = 0, code = 'UNKNOWN_ERROR', fields = {}) {
        super(message);
        this.name = 'ApiError';
        this.message = message;
        this.status = status;
        this.statusCode = status;
        this.code = code;
        this.fields = objectFields(fields);
    }
}

export class NetworkError extends Error {
    constructor(message = 'Impossible de joindre le serveur.', cause = null) {
        super(message);
        this.name = 'NetworkError';
        this.message = message;
        this.code = 'NETWORK_ERROR';
        this.cause = cause;
    }
}

export class ApiClient {
    constructor(options = {}) {
        if (typeof options === 'function') {
            options = { fetch: options };
        }

        const injectedFetch = options.fetch || options.fetchImpl || null;
        const globalFetch = typeof globalThis.fetch === 'function'
            ? globalThis.fetch.bind(globalThis)
            : null;

        this.fetchImpl = injectedFetch || globalFetch;
        this.baseUrl = String(options.baseUrl || DEFAULT_BASE_URL).replace(/\/+$/, '');
        this.onAuthExpired = typeof options.onAuthExpired === 'function'
            ? options.onAuthExpired
            : typeof options.onAuthenticationExpired === 'function'
                ? options.onAuthenticationExpired
                : null;
        this.csrfToken = null;
        this._csrfRequest = null;
    }

    request(path, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();

        if (MUTATING_METHODS.has(method)) {
            return this._requestWithCsrf(path, options, method);
        }

        return this._send(path, options, method);
    }

    async getCsrf(options = {}) {
        if (options.useCached === true && this.csrfToken !== null) {
            return { data: { csrf_token: this.csrfToken } };
        }

        return this.refreshCsrf();
    }

    async refreshCsrf() {
        if (this._csrfRequest !== null) {
            return this._csrfRequest;
        }

        const request = this._send('/auth/csrf', { method: 'GET' }, 'GET');
        this._csrfRequest = request;

        try {
            return await request;
        } finally {
            if (this._csrfRequest === request) {
                this._csrfRequest = null;
            }
        }
    }

    setCsrfToken(token) {
        this.csrfToken = typeof token === 'string' && token !== '' ? token : null;
    }

    clearCsrfToken() {
        this.csrfToken = null;
    }

    login(username, password) {
        const body = username !== null && typeof username === 'object'
            ? username
            : { username, password };

        return this.request('/auth/login', { method: 'POST', body });
    }

    async logout() {
        try {
            return await this.request('/auth/logout', { method: 'POST' });
        } finally {
            this.clearCsrfToken();
        }
    }

    me() {
        return this.request('/auth/me');
    }

    listCategories() {
        return this.request('/categories');
    }

    createCategory(category) {
        const body = typeof category === 'string' ? { name: category } : category;
        return this.request('/categories', { method: 'POST', body });
    }

    updateCategory(id, category) {
        const body = typeof category === 'string' ? { name: category } : category;
        return this.request(`/categories/${encodeURIComponent(String(id))}`, {
            method: 'PATCH',
            body,
        });
    }

    deleteCategory(id) {
        return this.request(`/categories/${encodeURIComponent(String(id))}`, {
            method: 'DELETE',
        });
    }

    discoverFeeds(url) {
        const body = url !== null && typeof url === 'object' ? url : { url };
        return this.request('/feed-discovery', { method: 'POST', body });
    }

    discover(url) {
        return this.discoverFeeds(url);
    }

    listFeeds(query = {}) {
        return this.request('/feeds', { query });
    }

    getFeed(id) {
        return this.request(`/feeds/${encodeURIComponent(String(id))}`);
    }

    createFeed(feed) {
        return this.request('/feeds', { method: 'POST', body: feed });
    }

    updateFeed(id, changes) {
        return this.request(`/feeds/${encodeURIComponent(String(id))}`, {
            method: 'PATCH',
            body: changes,
        });
    }

    deleteFeed(id) {
        return this.request(`/feeds/${encodeURIComponent(String(id))}`, {
            method: 'DELETE',
        });
    }

    refreshFeed(id) {
        return this.request(`/feeds/${encodeURIComponent(String(id))}/refresh`, {
            method: 'POST',
        });
    }

    refreshAllFeeds() {
        return this.request('/feeds/refresh', { method: 'POST' });
    }

    listArticles(query = {}) {
        return this.request('/articles', { query });
    }

    getArticle(id) {
        return this.request(`/articles/${encodeURIComponent(String(id))}`);
    }

    updateArticle(id, changes) {
        return this.request(`/articles/${encodeURIComponent(String(id))}`, {
            method: 'PATCH',
            body: changes,
        });
    }

    searchArticles(query, filters = {}) {
        const body = typeof query === 'string'
            ? { ...filters, q: query }
            : { ...(query || {}) };

        return this.request('/search', { query: body });
    }

    recommendations(query = {}) {
        return this.request('/recommendations', { query });
    }

    search(query, filters = {}) {
        return this.searchArticles(query, filters);
    }

    getCounts() {
        return this.request('/counts');
    }

    getSettings() {
        return this.request('/settings');
    }

    updateSettings(settings) {
        return this.request('/settings', { method: 'PATCH', body: settings });
    }

    changePassword(currentPassword, newPassword) {
        const body = currentPassword !== null && typeof currentPassword === 'object'
            ? currentPassword
            : {
                current_password: currentPassword,
                new_password: newPassword,
            };

        return this.request('/settings/password', { method: 'POST', body });
    }

    importOpml(file) {
        if (isFormData(file)) {
            return this.request('/opml/import', { method: 'POST', body: file });
        }

        if (typeof FormData === 'undefined') {
            return Promise.reject(new TypeError('FormData n’est pas disponible.'));
        }

        const body = new FormData();
        if (file !== null && file !== undefined) {
            if (typeof file.name === 'string' && file.name !== '') {
                body.append('file', file, file.name);
            } else {
                body.append('file', file);
            }
        }

        return this.request('/opml/import', { method: 'POST', body });
    }

    exportOpml() {
        return this.request('/opml/export', {
            accept: 'application/xml',
            responseType: 'text',
        });
    }

    _requestWithCsrf(path, options, method) {
        return this._ensureCsrf()
            .then(() => this._send(path, options, method).catch((error) => {
                if (!(
                    error instanceof ApiError
                    && error.status === 403
                    && error.code === 'INVALID_CSRF_TOKEN'
                )) {
                    throw error;
                }

                return this.refreshCsrf().then(() => this._send(path, options, method));
            }));
    }

    _ensureCsrf() {
        if (this.csrfToken !== null) {
            return Promise.resolve();
        }
        return this.refreshCsrf().then(() => undefined);
    }

    _buildUrl(path, query) {
        let resourcePath = String(path || '/');
        if (!resourcePath.startsWith('/')) {
            resourcePath = `/${resourcePath}`;
        }

        const parameters = new URLSearchParams();
        Object.keys(query || {}).forEach((key) => {
            appendQuery(parameters, key, query[key]);
        });
        const queryString = parameters.toString();
        const separator = resourcePath.includes('?') ? '&' : '?';
        const url = `${this.baseUrl}${resourcePath}${queryString === '' ? '' : `${separator}${queryString}`}`;

        return url;
    }

    _prepareBody(method, body, headers) {
        if (body === undefined || method === 'GET' || method === 'HEAD') {
            return undefined;
        }

        if (isFormData(body)) {
            deleteHeader(headers, 'content-type');
            return body;
        }

        setHeader(headers, 'content-type', 'application/json; charset=utf-8');
        return JSON.stringify(body);
    }

    async _send(path, options, method) {
        if (typeof this.fetchImpl !== 'function') {
            throw new NetworkError();
        }

        const headers = normalizeHeaders(options.headers);
        setHeader(headers, 'accept', options.accept || 'application/json');
        if (MUTATING_METHODS.has(method) && this.csrfToken !== null) {
            setHeader(headers, 'x-csrf-token', this.csrfToken);
        }

        const init = {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
        };
        const body = this._prepareBody(method, options.body, headers);

        if (body !== undefined) {
            init.body = body;
        }
        if (options.signal !== undefined) {
            init.signal = options.signal;
        }

        let response;
        try {
            response = await this.fetchImpl(this._buildUrl(path, options.query), init);
        } catch (error) {
            throw new NetworkError('Impossible de joindre le serveur.', error);
        }

        if (!validResponseStatus(response && response.status)) {
            throw new NetworkError('Réponse réseau invalide.');
        }

        const decoded = await this._decodeResponse(response, options.responseType || 'json');
        const status = response.status;

        if (status < 200 || status >= 300) {
            const error = this._apiError(status, decoded.json);
            this._handleAuthenticationExpiry(error, path);
            throw error;
        }

        if (decoded.invalidJson) {
            throw new ApiError(
                'La réponse du serveur est invalide.',
                status,
                'INVALID_RESPONSE',
            );
        }

        this._captureCsrfToken(decoded.json);
        return decoded.value;
    }

    async _decodeResponse(response, responseType) {
        if (response.status === 204 || response.status === 205) {
            return { value: null, json: null, invalidJson: false };
        }

        let text = null;
        if (typeof response.text === 'function') {
            try {
                text = await response.text();
            } catch (error) {
                throw new NetworkError('La réponse du serveur n’a pas pu être lue.', error);
            }
        } else if (typeof response.json === 'function') {
            try {
                const payload = await response.json();
                return { value: payload, json: payload, invalidJson: false };
            } catch (error) {
                throw new NetworkError('La réponse du serveur n’a pas pu être lue.', error);
            }
        } else {
            throw new NetworkError('Réponse réseau invalide.');
        }

        if (text === '') {
            return { value: null, json: null, invalidJson: false };
        }
        if (responseType === 'text') {
            try {
                return { value: text, json: JSON.parse(text), invalidJson: false };
            } catch {
                return { value: text, json: null, invalidJson: false };
            }
        }

        try {
            const payload = JSON.parse(text);
            return { value: payload, json: payload, invalidJson: false };
        } catch (error) {
            return { value: null, json: null, invalidJson: true };
        }
    }

    _apiError(status, payload) {
        const details = payload !== null && typeof payload === 'object'
            ? payload.error
            : null;
        const validDetails = details !== null && typeof details === 'object'
            ? details
            : {};
        const code = typeof validDetails.code === 'string' && validDetails.code !== ''
            ? validDetails.code
            : 'HTTP_ERROR';
        const message = typeof validDetails.message === 'string' && validDetails.message !== ''
            ? validDetails.message
            : 'La requête a échoué.';

        return new ApiError(message, status, code, validDetails.fields);
    }

    _captureCsrfToken(payload) {
        if (
            payload !== null
            && typeof payload === 'object'
            && payload.data !== null
            && typeof payload.data === 'object'
            && typeof payload.data.csrf_token === 'string'
            && payload.data.csrf_token !== ''
        ) {
            this.csrfToken = payload.data.csrf_token;
        }
    }

    _handleAuthenticationExpiry(error, path) {
        if (
            error.status !== 401
            || error.code !== 'AUTHENTICATION_REQUIRED'
            || path === '/auth/login'
        ) {
            return;
        }

        this.clearCsrfToken();
        if (this.onAuthExpired !== null) {
            try {
                this.onAuthExpired(error);
            } catch {
            }
        }
    }
}

export function createApiClient(options = {}) {
    return new ApiClient(options);
}

