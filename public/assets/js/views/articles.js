import { buildRoute } from '../router.js?v=26';
import { formatDate, UNKNOWN_DATE } from '../utils/format.js';
import {
    button,
    clear,
    el,
    icon,
    setChildren,
    spinnerBlock,
    stateBlock,
} from '../utils/dom.js';

const DEFAULT_TITLE = 'Tous les articles';
const DEFAULT_KICKER = 'Articles';
const VIEW_LABELS = {
    all: DEFAULT_TITLE,
    unread: 'Non lus',
    read: 'Lus',
    favorites: 'Favoris',
    search: 'Résultats de recherche',
    recommendations: 'Recommandé pour vous',
};
const FILTER_VALUES = new Set(Object.keys(VIEW_LABELS));
const NOOP = () => {};

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function textValue(value, fallback = '') {
    if (typeof value === 'string') {
        return value;
    }
    if (value === null || value === undefined) {
        return fallback;
    }
    return String(value);
}

function nonEmpty(value) {
    return value !== null && value !== undefined && textValue(value).trim() !== '';
}

function positiveInteger(value) {
    const number = typeof value === 'number' ? value : Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

function booleanValue(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

function elementById(id) {
    if (typeof document === 'undefined') {
        return null;
    }
    return document.getElementById(id);
}

function nestedOption(options, key) {
    if (!isObject(options)) {
        return undefined;
    }
    if (options[key] !== undefined) {
        return options[key];
    }
    return isObject(options.elements) ? options.elements[key] : undefined;
}

function callbackOption(options, callbacks, name, aliases = []) {
    if (typeof callbacks[name] === 'function') {
        return callbacks[name];
    }
    for (const alias of aliases) {
        if (typeof callbacks[alias] === 'function') {
            return callbacks[alias];
        }
    }
    if (typeof options[name] === 'function') {
        return options[name];
    }
    for (const alias of aliases) {
        if (typeof options[alias] === 'function') {
            return options[alias];
        }
    }
    return NOOP;
}

function normalizeReturnTo(value) {
    if (typeof value === 'function') {
        return normalizeReturnTo(value());
    }
    if (isObject(value)) {
        if (value.query !== undefined && isObject(value.query) && nonEmpty(value.query.from)) {
            return normalizeReturnTo(value.query.from);
        }
        if (nonEmpty(value.name)) {
            return normalizeReturnTo(value.name);
        }
        return null;
    }
    if (!nonEmpty(value)) {
        return null;
    }

    const normalized = textValue(value).trim();
    return normalized === 'home' || normalized === 'all' ? null : normalized;
}

function routeContext(route) {
    if (!isObject(route)) {
        return {};
    }
    return {
        filter: route.name,
        query: route.query && route.query.q,
        from: route.query && route.query.from,
    };
}

function isSafeUrl(value, allowRelative = true) {
    if (!nonEmpty(value)) {
        return false;
    }

    const source = textValue(value).trim();
    if (allowRelative && (source.startsWith('/') || source.startsWith('./') || source.startsWith('../'))) {
        return !source.startsWith('//');
    }

    try {
        const base = typeof document !== 'undefined' && document.baseURI
            ? document.baseURI
            : 'http://localhost/';
        const url = new URL(source, base);
        return (url.protocol === 'http:' || url.protocol === 'https:')
            && url.username === ''
            && url.password === '';
    } catch {
        return false;
    }
}

function isLocalMediaUrl(value) {
    if (!isSafeUrl(value, true)) {
        return false;
    }
    const source = textValue(value).trim();
    return source.startsWith('/') && !source.startsWith('//');
}

function addClass(node, className) {
    if (!node || !nonEmpty(className)) {
        return;
    }
    const classes = textValue(className).split(/\s+/).filter(Boolean);
    if (node.classList && typeof node.classList.add === 'function') {
        node.classList.add(...classes);
        return;
    }
    if (typeof node.setAttribute === 'function') {
        const current = node.getAttribute('class');
        node.setAttribute('class', [current, ...classes].filter(Boolean).join(' '));
    }
}

function viewIcon(name, className = '') {
    let node = null;
    try {
        node = icon(name);
    } catch {
        try {
            node = icon(name, { className });
        } catch {
            node = null;
        }
    }

    if (!node) {
        node = viewEl('span', {
            className: `icon ${className}`.trim(),
            attrs: { 'aria-hidden': 'true' },
        });
    }
    addClass(node, className);
    return node;
}

function viewButton(label, options = {}) {
    const source = isObject(options) ? options : {};
    const children = Array.isArray(source.children)
        ? source.children
        : (source.children === null || source.children === undefined ? [] : [source.children]);
    const config = {
        className: source.className,
        icon: source.icon,
        accessibleName: source.accessibleName,
        attrs: isObject(source.attrs) ? source.attrs : {},
        onClick: source.onClick,
        children,
    };
    let node = null;

    try {
        node = button(label, {
            className: config.className,
            icon: config.icon,
            accessibleName: config.accessibleName,
            children: config.children,
        });
    } catch {
        node = null;
    }

    if (!node) {
        try {
            node = button({
                label,
                className: config.className,
                icon: config.icon,
                accessibleName: config.accessibleName,
                children: config.children,
            });
        } catch {
            node = null;
        }
    }

    if (!node) {
        node = viewEl('button', {
            className: config.className || 'button',
            text: label,
            attrs: { type: 'button', ...config.attrs },
            onClick: config.onClick,
        }, config.children);
    }

    if (node) {
        setAttributes(node, config.attrs);
        bindEvents(node, { onClick: config.onClick });
        if (config.children.length === 0 && !nonEmpty(node.textContent)
            && typeof node.appendChild === 'function') {
            node.appendChild(viewEl('span', { text: label }));
        }
    }
    return node;
}

function stateWithAction(options) {
    let block = null;
    try {
        block = stateBlock({
            icon: options.icon,
            title: options.title,
            message: options.message,
        });
    } catch {
        block = null;
    }

    if (!block) {
        block = viewEl('div', { className: 'state-card' }, [
            viewEl('div', { className: 'state-icon', attrs: { 'aria-hidden': 'true' } }, [
                viewIcon(options.icon),
            ]),
            viewEl('h2', { className: 'state-title', text: options.title }),
            viewEl('p', { className: 'state-message', text: options.message }),
        ]);
    }

    if (!nonEmpty(options.actionLabel)
        || typeof options.onAction !== 'function'
        || options.onAction === NOOP) {
        return block;
    }

    return viewEl('div', { className: 'state-block-wrapper' }, [
        block,
        viewButton(options.actionLabel, {
            className: 'button button-primary',
            onClick: options.onAction,
        }),
    ]);
}

function clearNode(node) {
    if (node && typeof node !== 'undefined') {
        clear(node);
    }
}

function replaceChildren(node, children) {
    if (!node) {
        return;
    }
    clearNode(node);
    setChildren(node, children);
}

function articleId(article) {
    if (!isObject(article)) {
        return null;
    }
    return positiveInteger(article.id ?? article.article_id);
}

function articleBoolean(article, key, fallbackKey) {
    if (!isObject(article)) {
        return false;
    }
    if (article[key] !== undefined) {
        return booleanValue(article[key]);
    }
    if (fallbackKey !== undefined && article[fallbackKey] !== undefined) {
        return booleanValue(article[fallbackKey]);
    }
    return false;
}

function articleFeed(article) {
    if (!isObject(article)) {
        return {};
    }
    if (isObject(article.feed)) {
        return article.feed;
    }
    if (isObject(article.source)) {
        return article.source;
    }
    return {
        name: article.feed_name ?? article.source_name,
        favicon_url: article.favicon_url ?? article.feed_favicon_url,
    };
}

function sourceName(article) {
    const feed = articleFeed(article);
    return nonEmpty(feed.name) ? textValue(feed.name) : 'Source inconnue';
}

function categoryName(article) {
    const feed = isObject(article?.feed) ? article.feed : {};
    const category = isObject(feed.category) ? feed.category : null;
    if (category !== null && nonEmpty(category.name)) {
        return textValue(category.name).trim();
    }
    return 'Sans catégorie';
}

function sourceInitial(name) {
    const trimmed = textValue(name).trim();
    return trimmed === '' ? '?' : trimmed.charAt(0).toUpperCase();
}

function articleDate(article) {
    const published = isObject(article) ? article.published_at : null;
    const discovered = isObject(article) ? article.discovered_at : null;
    const raw = nonEmpty(published) ? published : (nonEmpty(discovered) ? discovered : null);
    return {
        raw,
        label: raw === null ? UNKNOWN_DATE : formatDate(raw),
    };
}

function articleTitle(article) {
    return nonEmpty(article && article.title) ? textValue(article.title) : 'Article sans titre';
}

function articleHref(id, returnTo) {
    if (id === null) {
        return null;
    }
    return buildRoute('article', { articleId: id }, { from: returnTo });
}

function setHidden(node, hidden) {
    if (!node) {
        return;
    }
    if ('hidden' in node) {
        node.hidden = hidden;
    } else if (typeof node.setAttribute === 'function') {
        if (hidden) {
            node.setAttribute('hidden', '');
        } else {
            node.removeAttribute('hidden');
        }
    }
}

function setText(node, value) {
    if (node) {
        node.textContent = textValue(value);
    }
}

function setAttribute(node, name, value) {
    if (node && typeof node.setAttribute === 'function') {
        node.setAttribute(name, String(value));
    }
}

function setAttributes(node, attributes) {
    if (!node || !isObject(attributes)) {
        return;
    }
    Object.entries(attributes).forEach(([name, value]) => {
        if (value === null || value === undefined) {
            if (typeof node.removeAttribute === 'function') {
                node.removeAttribute(name);
            }
            return;
        }
        if (typeof node.setAttribute === 'function') {
            node.setAttribute(name, String(value));
        }
    });
}

function bindEvents(node, options) {
    if (!node || typeof node.addEventListener !== 'function' || !isObject(options)) {
        return;
    }
    const handlers = isObject(options.events) ? { ...options.events } : {};
    Object.keys(options).forEach((name) => {
        if (/^on[A-Z]/.test(name) && typeof options[name] === 'function') {
            handlers[name.slice(2).toLowerCase()] = options[name];
        }
    });
    Object.entries(handlers).forEach(([name, handler]) => {
        if (typeof handler === 'function') {
            node.addEventListener(name, handler);
        }
    });
}

function viewEl(tag, options = {}, children = []) {
    const settings = isObject(options) ? { ...options } : {};
    const attributes = {
        ...(isObject(settings.attributes) ? settings.attributes : {}),
        ...(isObject(settings.attrs) ? settings.attrs : {}),
    };
    const helperOptions = {
        ...settings,
        attrs: attributes,
        attributes,
    };
    Object.keys(helperOptions).forEach((name) => {
        if (/^on[A-Z]/.test(name) || name === 'events') {
            delete helperOptions[name];
        }
    });
    const node = el(tag, helperOptions, children);
    if (!node) {
        return node;
    }
    if (settings.text !== undefined
        && (children === null || children === undefined
            || (Array.isArray(children) && children.length === 0))) {
        node.textContent = textValue(settings.text);
    }
    setAttributes(node, attributes);
    if (settings.id !== undefined && attributes.id === undefined) {
        setAttribute(node, 'id', settings.id);
    }
    bindEvents(node, settings);
    return node;
}

function createFavicon(article) {
    const feed = articleFeed(article);
    const name = sourceName(article);
    const fallback = viewEl('span', {
        className: 'article-favicon-fallback',
        text: sourceInitial(name),
        attrs: { 'aria-hidden': 'true' },
    });
    const url = feed.favicon_url;

    if (!isLocalMediaUrl(url)) {
        return viewEl('span', { className: 'article-favicon' }, [fallback]);
    }

    let image = null;
    const wrapper = viewEl('span', { className: 'article-favicon' }, [fallback]);
    image = viewEl('img', {
        className: 'article-favicon-image',
        attrs: {
            src: textValue(url),
            alt: '',
            loading: 'lazy',
            decoding: 'async',
        },
        onLoad: () => {
            if (image) {
                setHidden(image, false);
                setHidden(fallback, true);
            }
        },
        onError: () => {
            if (image) {
                setHidden(image, true);
                setHidden(fallback, false);
            }
        },
    });
    setHidden(image, false);
    setChildren(wrapper, [image, fallback]);
    return wrapper;
}

function createThumbnail(article) {
    const url = isObject(article) ? article.image_url : null;
    const faviconUrl = articleFeed(article).favicon_url;
    const placeholder = viewEl('span', {
        className: 'article-thumbnail-placeholder',
        attrs: { 'aria-hidden': 'true' },
    }, isLocalMediaUrl(faviconUrl) ? [createFavicon(article)] : [viewIcon('rss')]);
    const wrapper = viewEl('span', { className: 'article-thumbnail' }, [placeholder]);
    if (!isLocalMediaUrl(url)) return wrapper;

    let image = null;
    const showImage = () => {
        setHidden(image, false);
        setHidden(placeholder, true);
    };
    image = viewEl('img', {
        className: 'article-thumbnail',
        attrs: {
            src: textValue(url),
            alt: '',
            loading: 'lazy',
            decoding: 'async',
        },
        onLoad: () => {
            showImage();
        },
        onError: () => {
            setHidden(image, true);
            setHidden(placeholder, false);
        },
    });
    setHidden(placeholder, true);
    setChildren(wrapper, [image, placeholder]);
    if (image.complete && image.naturalWidth > 0) showImage();
    return wrapper;
}

function createArticleCard(article, returnTo, activeArticleId = null) {
    const id = articleId(article);
    const isActive = id !== null && id === positiveInteger(activeArticleId);
    const isRead = articleBoolean(article, 'is_read', 'read');
    const isFavorite = articleBoolean(article, 'is_favorite', 'favorite');
    const title = articleTitle(article);
    const date = articleDate(article);
    const titleId = id === null ? null : `article-title-${id}`;
    const linkChildren = [
        viewEl('div', { className: 'article-card-topline' }, [
            createFavicon(article),
            viewEl('span', { className: 'article-source-name', text: sourceName(article) }),
        ]),
        viewEl('h2', {
            className: 'article-card-title',
            text: title,
            attrs: titleId === null ? {} : { id: titleId },
        }),
        viewEl('div', { className: 'article-card-meta' }, [
            viewEl('time', {
                className: 'article-date',
                text: date.label,
                attrs: date.raw === null ? {} : { datetime: textValue(date.raw) },
            }),
            viewEl('span', {
                className: 'article-category-tag',
                text: categoryName(article),
            }),
        ]),
    ];
    const thumbnail = createThumbnail(article);
    if (thumbnail !== null) {
        linkChildren.push(thumbnail);
    }

    const cardContent = id === null
        ? viewEl('div', { className: 'article-card-content' }, linkChildren)
        : viewEl('a', {
            className: 'article-card-link',
            attrs: {
                href: articleHref(id, returnTo),
                ...(isActive ? { 'aria-current': 'true' } : {}),
            },
        }, [viewEl('div', { className: 'article-card-content' }, linkChildren)]);
    const card = viewEl('article', {
        className: `article-card ${isRead ? 'article-card--read' : 'article-card--unread'}${isFavorite ? ' article-card--favorite' : ''}`,
        dataset: {
            articleId: id === null ? '' : String(id),
            read: String(isRead),
            favorite: String(isFavorite),
        },
        attrs: titleId === null ? {} : { 'aria-labelledby': titleId },
    }, [cardContent]);
    return viewEl('li', { className: 'article-list-item' }, [card]);
}

function paginationData(value) {
    if (!isObject(value)) {
        return {};
    }
    return value;
}

function articlePage(payload, options) {
    const response = isObject(payload) ? payload : {};
    const requestOptions = isObject(options) ? options : {};
    const responseData = isObject(response.data) ? response.data : response;
    const articles = Array.isArray(requestOptions.articles)
        ? requestOptions.articles
        : Array.isArray(responseData.articles)
            ? responseData.articles
            : Array.isArray(response.data)
                ? response.data
                : Array.isArray(payload)
                    ? payload
                    : [];
    const pagination = requestOptions.pagination
        || responseData.pagination
        || response.pagination
        || {};

    return { response, requestOptions, responseData, articles, pagination };
}

export class ArticlesView {
    constructor(options = {}, callbacks = {}) {
        const source = isObject(options) ? options : {};
        const callbackValues = {
            ...(isObject(source.callbacks) ? source.callbacks : {}),
            ...(isObject(callbacks) ? callbacks : {}),
        };
        this.list = nestedOption(source, 'list')
            || nestedOption(source, 'articleList')
            || nestedOption(source, 'articleListElement')
            || elementById('article-list');
        this.status = nestedOption(source, 'status')
            || nestedOption(source, 'articleListStatus')
            || elementById('article-list-status');
        this.loadMore = nestedOption(source, 'loadMore')
            || nestedOption(source, 'infiniteScroll')
            || elementById('article-list-load-more');
        this.footer = nestedOption(source, 'footer')
            || nestedOption(source, 'articleListFooter')
            || elementById('article-list-footer');
        this.scrollContainer = nestedOption(source, 'scrollContainer')
            || nestedOption(source, 'articleListScroll')
            || elementById('article-list-scroll');
        this.title = nestedOption(source, 'title')
            || nestedOption(source, 'articleListTitle')
            || elementById('article-list-title');
        this.kicker = nestedOption(source, 'kicker')
            || nestedOption(source, 'articleListKicker')
            || elementById('article-list-kicker');
        this.clearSearchButton = nestedOption(source, 'clearSearchButton')
            || nestedOption(source, 'clearSearch')
            || elementById('clear-search-button');
        this.callbacks = {
            onToggleFavorite: callbackOption(source, callbackValues, 'onToggleFavorite', ['toggleFavorite']),
            onLoadMore: callbackOption(source, callbackValues, 'onLoadMore', ['loadMore', 'onPageChange', 'pageChange']),
            onRetry: callbackOption(source, callbackValues, 'onRetry', ['retry']),
            onAddFeed: callbackOption(source, callbackValues, 'onAddFeed', ['addFeed']),
            onClearSearch: callbackOption(source, callbackValues, 'onClearSearch', ['clearSearch']),
            onDeleteFeed: callbackOption(source, callbackValues, 'onDeleteFeed', ['deleteFeed']),
            onEditFeed: callbackOption(source, callbackValues, 'onEditFeed', ['editFeed']),
        };
        this.actions = nestedOption(source, 'actions') || elementById('article-list-feed-actions');
        this.returnTo = normalizeReturnTo(source.returnTo ?? source.from);
        this.context = {};
        this.currentPage = 0;
        this.totalPages = 0;
        this.loadingMore = false;
        this.observer = null;
        this._clearSearchHandler = () => this.callbacks.onClearSearch();
        if (this.clearSearchButton && typeof this.clearSearchButton.addEventListener === 'function') {
            this.clearSearchButton.addEventListener('click', this._clearSearchHandler);
        }
        if (this.loadMore
            && typeof window !== 'undefined'
            && typeof window.IntersectionObserver === 'function') {
            this.observer = new window.IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this._requestNextPage();
                }
            }, {
                root: this.scrollContainer,
                rootMargin: '300px 0px',
            });
        }
    }

    render(payload = {}, options = {}) {
        const { response, requestOptions, responseData, articles, pagination } = articlePage(payload, options);
        const context = this._context(response, requestOptions);
        this.context = context;
        this.returnTo = normalizeReturnTo(
            requestOptions.returnTo
            ?? requestOptions.from
            ?? response.returnTo
            ?? responseData.returnTo
            ?? context.from
            ?? context.filter,
        );
        this._applyHeader(context);
        this._renderActions(context);
        this._setSearchVisibility(context.query, requestOptions);
        this._clearStatus();
        this._setBusy(false);

        if (articles.length === 0) {
            this._resetInfiniteScroll();
            this._renderEmpty(context);
            return;
        }

        const cards = articles.map((article) => createArticleCard(
            article,
            this.getReturnTo(),
            requestOptions.activeArticleId,
        ));
        replaceChildren(this.list, cards);
        this._configureInfiniteScroll(pagination);
    }

    append(payload = {}, options = {}) {
        const { requestOptions, articles, pagination } = articlePage(payload, options);
        const cards = articles.map((article) => createArticleCard(
            article,
            this.getReturnTo(),
            requestOptions.activeArticleId,
        ));
        cards.forEach((card) => this.list?.appendChild(card));
        this._setBusy(false);
        this._configureInfiniteScroll(pagination);
    }

    renderLoading(options = {}) {
        const requestOptions = isObject(options) ? options : {};
        const context = this._context({}, requestOptions);
        this.context = context;
        this.returnTo = normalizeReturnTo(
            requestOptions.returnTo
            ?? requestOptions.from
            ?? context.from
            ?? context.filter,
        );
        this._applyHeader(context);
        this._renderActions(context);
        this._setSearchVisibility(context.query, requestOptions);
        this._clearStatus();
        this._setBusy(true);
        this._resetInfiniteScroll();
        replaceChildren(this.list, [viewEl('li', { className: 'article-list-state' }, [
            spinnerBlock('Chargement des articles…'),
        ])]);
    }

    renderError(error, options = {}) {
        const requestOptions = isObject(options) ? options : {};
        const context = this._context({}, requestOptions);
        this.context = context;
        this.returnTo = normalizeReturnTo(
            requestOptions.returnTo
            ?? requestOptions.from
            ?? context.from
            ?? context.filter,
        );
        this._applyHeader(context);
        this._renderActions(context);
        this._setSearchVisibility(context.query, requestOptions);
        this._setBusy(false);
        this._resetInfiniteScroll();
        replaceChildren(this.list, [viewEl('li', { className: 'article-list-state' }, [
            stateWithAction({
                icon: 'alert',
                title: 'Impossible de charger les articles',
                message: 'Les articles ne sont pas disponibles pour le moment.',
                actionLabel: 'Réessayer',
                onAction: this.callbacks.onRetry,
            }),
        ])]);
        this.setAnnouncement('Les articles ne sont pas disponibles pour le moment.');
    }

    renderMoreError() {
        this.loadingMore = false;
        this._stopObserving();
        if (!this.loadMore) return;
        setAttribute(this.loadMore, 'aria-busy', 'false');
        replaceChildren(this.loadMore, [
            viewEl('span', {
                className: 'infinite-scroll-error',
                text: 'Impossible de charger la suite.',
            }),
            viewButton('Réessayer', {
                className: 'button button-small',
                onClick: () => this._requestNextPage(),
            }),
        ]);
        setHidden(this.loadMore, false);
    }

    setAnnouncement(message) {
        if (!this.status) {
            return;
        }
        const value = nonEmpty(message) ? textValue(message) : '';
        setText(this.status, value);
        setHidden(this.status, value === '');
    }

    getReturnTo() {
        return this.returnTo;
    }

    getAdjacentArticleIds(articleIdValue) {
        const articleId = positiveInteger(articleIdValue);
        if (articleId === null || !this.list || typeof this.list.querySelectorAll !== 'function') {
            return { previousId: null, nextId: null };
        }
        const articleIds = Array.from(this.list.querySelectorAll('[data-article-id]'))
            .map((card) => positiveInteger(card.dataset?.articleId))
            .filter((id) => id !== null);
        const index = articleIds.indexOf(articleId);
        if (index < 0) {
            return { previousId: null, nextId: null };
        }
        return {
            previousId: index > 0 ? articleIds[index - 1] : null,
            nextId: index + 1 < articleIds.length ? articleIds[index + 1] : null,
        };
    }

    setActiveArticle(articleId) {
        if (!this.list || typeof this.list.querySelectorAll !== 'function') return;
        this.list.querySelectorAll('.article-card-link[aria-current="true"]').forEach((link) => {
            link.removeAttribute('aria-current');
        });
        const id = positiveInteger(articleId);
        if (id === null || typeof this.list.querySelector !== 'function') return;
        const link = this.list.querySelector(`[data-article-id="${id}"] .article-card-link`);
        link?.setAttribute('aria-current', 'true');
    }

    updateArticle(article) {
        const id = articleId(article);
        if (id === null || !this.list || typeof this.list.querySelector !== 'function') return;
        const card = this.list.querySelector(`[data-article-id="${id}"]`);
        if (!card) return;
        const isRead = articleBoolean(article, 'is_read', 'read');
        const isFavorite = articleBoolean(article, 'is_favorite', 'favorite');
        card.classList?.toggle('article-card--read', isRead);
        card.classList?.toggle('article-card--unread', !isRead);
        card.classList?.toggle('article-card--favorite', isFavorite);
        if (card.dataset) {
            card.dataset.read = String(isRead);
            card.dataset.favorite = String(isFavorite);
        }
    }

    _context(response, options) {
        const responseValue = isObject(response) ? response : {};
        const responseData = isObject(responseValue.data) ? responseValue.data : responseValue;
        const requestOptions = isObject(options) ? options : {};
        const sourceRoute = requestOptions.route || responseValue.route;
        const route = routeContext(sourceRoute);
        const filterValue = requestOptions.filter
            ?? requestOptions.view
            ?? route.filter
            ?? responseData.filter
            ?? 'all';
        const filter = FILTER_VALUES.has(filterValue) ? filterValue : 'all';
        const query = requestOptions.query
            ?? requestOptions.search
            ?? route.query
            ?? responseData.query
            ?? '';
        const from = requestOptions.returnTo
            ?? requestOptions.from
            ?? route.from
            ?? responseData.from
            ?? null;
        const name = requestOptions.title
            ?? requestOptions.heading
            ?? responseData.title
            ?? this._defaultTitle(filter, query, requestOptions, route);
        return {
            filter,
            query: nonEmpty(query) ? textValue(query) : '',
            from: normalizeReturnTo(from),
            title: nonEmpty(name) ? textValue(name) : DEFAULT_TITLE,
            kicker: nonEmpty(requestOptions.kicker)
                ? textValue(requestOptions.kicker)
                : (nonEmpty(responseData.kicker) ? textValue(responseData.kicker) : DEFAULT_KICKER),
            hasSubscriptions: requestOptions.hasSubscriptions ?? responseData.hasSubscriptions,
            categoryName: requestOptions.categoryName ?? responseData.categoryName,
            feedName: requestOptions.feedName ?? responseData.feedName,
            feedId: positiveInteger(sourceRoute?.name === 'feed'
                ? sourceRoute.params?.id
                : requestOptions.feedId ?? responseData.feedId),
            isSearch: nonEmpty(query) || filter === 'search' || requestOptions.isSearch === true,
        };
    }

    _defaultTitle(filter, query, options, route) {
        if (nonEmpty(query) || filter === 'search' || route.filter === 'search') {
            return 'Résultats de recherche';
        }
        if (nonEmpty(options.categoryName)) {
            return `Articles - ${textValue(options.categoryName)}`;
        }
        if (nonEmpty(options.feedName)) {
            return `Articles - ${textValue(options.feedName)}`;
        }
        return VIEW_LABELS[filter] || DEFAULT_TITLE;
    }

    _applyHeader(context) {
        setText(this.title, context.title);
        setText(this.kicker, context.kicker);
        this._renderFooter(context);
    }

    _renderFooter(context) {
        if (!this.footer) return;
        clear(this.footer);
        if (context.filter !== 'recommendations') {
            setHidden(this.footer, true);
            return;
        }
        this.footer.appendChild(el('a', {
            href: buildRoute('unread'),
            className: 'button article-list-footer-button',
        }, [icon('unread'), el('span', {}, 'Voir les articles non lus')]));
        setHidden(this.footer, false);
    }

    _setSearchVisibility(query, options) {
        const shouldShow = nonEmpty(query) && options.clearSearch !== false;
        setHidden(this.clearSearchButton, !shouldShow);
    }

    _renderActions(context) {
        if (!this.actions) return;
        this.actions.querySelectorAll('[data-article-list-action]').forEach((action) => action.remove());
        if (context.feedId === null) return;
        if (this.callbacks.onEditFeed !== NOOP) {
            this.actions.appendChild(button('Modifier le flux', {
                className: 'button button-small article-list-edit-button',
                icon: 'edit',
                attrs: { 'data-article-list-action': 'edit-feed' },
                ariaLabel: 'Modifier le flux',
                onClick: () => this.callbacks.onEditFeed(context.feedId),
            }));
        }
        if (this.callbacks.onDeleteFeed === NOOP) return;
        const deleteButton = button('Supprimer le flux', {
            className: 'button button-small article-list-delete-button',
            icon: 'trash',
            attrs: { 'data-article-list-action': 'delete-feed' },
            ariaLabel: 'Supprimer le flux',
            onClick: () => this.callbacks.onDeleteFeed(context.feedId),
        });
        this.actions.appendChild(deleteButton);
    }

    _clearStatus() {
        this.setAnnouncement('');
    }

    _setBusy(busy) {
        setAttribute(this.list, 'aria-busy', String(busy));
    }

    _configureInfiniteScroll(pagination) {
        const value = paginationData(pagination);
        this.currentPage = positiveInteger(value.page) || 1;
        this.totalPages = positiveInteger(value.total_pages) || 0;
        this.loadingMore = false;
        if (!this.loadMore
            || this.callbacks.onLoadMore === NOOP
            || this.currentPage >= this.totalPages) {
            this._resetInfiniteScroll(false);
            return;
        }

        setAttribute(this.loadMore, 'aria-busy', 'false');
        setHidden(this.loadMore, false);
        if (this.observer) {
            replaceChildren(this.loadMore, [viewEl('span', {
                className: 'visually-hidden',
                text: 'Chargement automatique des articles suivants',
            })]);
            this._stopObserving();
            this.observer.observe(this.loadMore);
            return;
        }

        replaceChildren(this.loadMore, [viewButton('Charger plus d’articles', {
            className: 'button button-small',
            onClick: () => this._requestNextPage(),
        })]);
    }

    _requestNextPage() {
        if (this.loadingMore || this.currentPage >= this.totalPages) return;
        this.loadingMore = true;
        this._stopObserving();
        if (this.loadMore) {
            setAttribute(this.loadMore, 'aria-busy', 'true');
            replaceChildren(this.loadMore, [
                viewEl('span', { className: 'spinner infinite-scroll-spinner', attrs: { 'aria-hidden': 'true' } }),
                viewEl('span', { text: 'Chargement des articles suivants…' }),
            ]);
            setHidden(this.loadMore, false);
        }
        Promise.resolve()
            .then(() => this.callbacks.onLoadMore(this.currentPage + 1))
            .catch(() => this.renderMoreError());
    }

    _resetInfiniteScroll(resetPages = true) {
        this._stopObserving();
        this.loadingMore = false;
        if (resetPages) {
            this.currentPage = 0;
            this.totalPages = 0;
        }
        if (!this.loadMore) return;
        clearNode(this.loadMore);
        setAttribute(this.loadMore, 'aria-busy', 'false');
        setHidden(this.loadMore, true);
    }

    _stopObserving() {
        if (this.observer && this.loadMore) {
            this.observer.unobserve(this.loadMore);
        }
    }

    _renderEmpty(context) {
        let title = 'Aucun article';
        let message = 'Les articles de vos flux apparaîtront ici.';
        let actionLabel = 'Ajouter un flux';
        let onAction = this.callbacks.onAddFeed;

        if (context.isSearch) {
            title = 'Aucun résultat';
            message = context.query === ''
                ? 'Aucun article ne correspond à votre recherche.'
                : `Aucun article ne correspond à « ${context.query} ».`;
            actionLabel = 'Effacer la recherche';
            onAction = this.callbacks.onClearSearch;
        } else if (context.filter === 'unread') {
            title = 'Aucun article non lu';
            message = 'Vous êtes à jour : aucun article non lu dans cette vue.';
        } else if (context.filter === 'read') {
            title = 'Aucun article lu';
            message = 'Les articles lus apparaîtront ici.';
        } else if (context.filter === 'favorites') {
            title = 'Aucun favori';
            message = 'Ajoutez des articles à vos favoris pour les retrouver ici.';
        } else if (context.hasSubscriptions === false) {
            title = 'Aucun abonnement';
            message = 'Ajoutez un flux pour recevoir vos premiers articles.';
        } else if (nonEmpty(context.categoryName) || nonEmpty(context.feedName)) {
            title = 'Aucun article dans cette sélection';
            message = 'Cette sélection ne contient aucun article pour le moment.';
            actionLabel = '';
            onAction = NOOP;
        }

        replaceChildren(this.list, [viewEl('li', { className: 'article-list-state' }, [
            stateWithAction({
                icon: context.isSearch ? 'search' : 'inbox',
                title,
                message,
                actionLabel,
                onAction,
            }),
        ])]);
    }
}
