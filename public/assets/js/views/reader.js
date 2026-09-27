import { formatDate, UNKNOWN_DATE } from '../utils/format.js';
import { buildRoute } from '../router.js?v=26';
import { openDialog } from '../components/dialog.js';
import {
    button,
    clear,
    el,
    icon,
    setChildren,
    spinnerBlock,
    stateBlock,
} from '../utils/dom.js';

const DEFAULT_PLACEHOLDER_TITLE = 'Sélectionnez un article';
const DEFAULT_PLACEHOLDER_MESSAGE = 'Choisissez un article dans la liste pour le lire ici.';
const EMPTY_CONTENT = 'Le contenu de cet article n’est pas disponible.';
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

function optionValue(options, key) {
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
        const url = allowRelative ? new URL(source, base) : new URL(source);
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

function clearNode(node) {
    if (node) {
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

    if (nonEmpty(options.titleId)
        && block
        && typeof block.querySelector === 'function') {
        const heading = block.querySelector('h2');
        if (heading) {
            setAttribute(heading, 'id', options.titleId);
        }
    }
    if (nonEmpty(options.role)) {
        setAttribute(block, 'role', options.role);
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

function articleFrom(value) {
    if (isObject(value) && isObject(value.data)) {
        return value.data;
    }
    return isObject(value) ? value : null;
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
        id: article.feed_id,
        name: article.feed_name ?? article.source_name,
        favicon_url: article.favicon_url ?? article.feed_favicon_url,
    };
}

function sourceHref(article) {
    const id = articleFeed(article).id;
    return positiveInteger(id) ? buildRoute('feed', { id }) : null;
}

function sourceName(article) {
    const feed = articleFeed(article);
    return nonEmpty(feed.name) ? textValue(feed.name) : 'Source inconnue';
}

function categoryName(article) {
    const category = articleFeed(article).category;
    if (isObject(category) && nonEmpty(category.name)) {
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

function articleSummary(article) {
    return nonEmpty(article && article.summary) ? textValue(article.summary) : EMPTY_CONTENT;
}

function createFavicon(article) {
    const feed = articleFeed(article);
    const name = sourceName(article);
    const fallback = viewEl('span', {
        className: 'reader-favicon-fallback',
        text: sourceInitial(name),
        attrs: { 'aria-hidden': 'true' },
    });
    const url = feed.favicon_url;
    if (!isLocalMediaUrl(url)) {
        return viewEl('span', { className: 'reader-favicon' }, [fallback]);
    }

    let image = null;
    const wrapper = viewEl('span', { className: 'reader-favicon' }, [fallback]);
    image = viewEl('img', {
        className: 'reader-favicon-image',
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
    setChildren(wrapper, [image, fallback]);
    return wrapper;
}

function createArticleImage(article) {
    const url = isObject(article) ? article.image_url : null;
    if (!isLocalMediaUrl(url)) {
        return null;
    }

    let image = null;
    image = viewEl('img', {
        className: 'reader-article-image',
        attrs: {
            src: textValue(url),
            alt: articleTitle(article),
            loading: 'lazy',
            decoding: 'async',
        },
        onError: () => setHidden(image, true),
    });
    return viewEl('button', {
        className: 'reader-image-trigger',
        attrs: {
            type: 'button',
            'aria-label': 'Agrandir l’image de l’article',
            title: 'Agrandir l’image',
        },
        onClick: () => {
            const enlarged = viewEl('img', {
                className: 'reader-expanded-image',
                attrs: {
                    src: textValue(url),
                    alt: articleTitle(article),
                },
            });
            openDialog({
                title: 'Image de l’article',
                content: enlarged,
                variant: 'image',
            });
        },
    }, [image]);
}

function originalArticleUrl(article) {
    if (!isObject(article) || !isSafeUrl(article.url, false)) {
        return null;
    }
    return textValue(article.url).trim();
}

function invokeToggle(callback, article, value) {
    if (typeof callback !== 'function') {
        return;
    }
    const id = articleId(article);
    if (id !== null) {
        callback(id, value, article);
    }
}

const SHARE_NETWORKS = [
    {
        name: 'X',
        intent: (title, url) => `https://twitter.com/intent/tweet?url=${ensureShareUrl(url)}&text=${encodeShareText(title)}`,
        label: 'Partager sur X',
    },
    {
        name: 'Facebook',
        intent: (title, url) => `https://www.facebook.com/sharer/sharer.php?u=${ensureShareUrl(url)}&quote=${encodeShareText(title)}`,
        label: 'Partager sur Facebook',
    },
    {
        name: 'LinkedIn',
        intent: (title, url) => `https://www.linkedin.com/sharing/share-offsite/?url=${ensureShareUrl(url)}${title === '' ? '' : `&summary=${encodeShareText(title)}`}`,
        label: 'Partager sur LinkedIn',
    },
    {
        name: 'Bluesky',
        intent: (title, url) => `https://bsky.app/intent/compose?text=${encodeShareText(title === '' ? url : `${title}\n${url}`)}`,
        label: 'Partager sur Bluesky',
    },
];

function encodeShareText(value) {
    return encodeURIComponent(textValue(value));
}

function ensureShareUrl(url) {
    const absolute = typeof document !== 'undefined' && document.baseURI
        ? new URL(url, document.baseURI)
        : new URL(url);
    return encodeURIComponent(absolute.toString());
}

function shareQuery(article) {
    const url = originalArticleUrl(article);
    if (url === null) {
        return null;
    }
    return {
        url,
        title: articleTitle(article),
    };
}

function renderShareLink(network, share) {
    return viewEl('a', {
        className: 'button reader-share-link',
        attrs: {
            href: network.intent(share.title, share.url),
            target: '_blank',
            rel: 'noopener noreferrer',
            'aria-label': network.label,
            title: network.label,
        },
    }, [
        viewIcon('share', 'reader-share-icon'),
        viewEl('span', { className: 'button-label', text: network.name }),
    ]);
}

function renderShareButton(article) {
    const share = shareQuery(article);
    if (share === null) {
        return null;
    }

    const children = SHARE_NETWORKS.map((network) => renderShareLink(network, share));
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
        children.unshift(viewEl('button', {
            className: 'button reader-share-native',
            attrs: {
                type: 'button',
                'aria-label': 'Partager l’article',
                title: 'Partager l’article',
            },
            onClick: () => {
                try {
                    navigator.share({ title: share.title, url: share.url });
                } catch {
                }
            },
        }, [
            viewIcon('share', 'reader-share-icon'),
            viewEl('span', { className: 'button-label', text: 'Partager' }),
        ]));
    }

    return viewEl('div', { className: 'reader-share' }, children);
}

function articleTagList(article) {
    const value = isObject(article) ? article : {};
    const raw = Array.isArray(value.tags) ? value.tags : [];
    const tags = [];
    raw.forEach((tag) => {
        const text = nonEmpty(tag) ? textValue(tag).trim() : '';
        if (text !== '' && !tags.includes(text)) {
            tags.push(text);
        }
    });
    if (tags.length === 0) {
        return null;
    }
    return viewEl('div', {
        className: 'reader-tags',
        attrs: { 'aria-label': 'Sujets de l’article' },
    }, tags.map((tag) => viewEl('span', { className: 'reader-tag' }, [
        viewIcon('tag', 'reader-tag-icon'),
        viewEl('span', { text: tag }),
    ])));
}

function renderBackButton(onBack, extraClass = '') {
    return viewButton('Retour', {
        className: `reader-back-button button${extraClass === '' ? '' : ` ${extraClass}`}`,
        icon: 'left',
        attrs: { 'aria-label': 'Retour à la liste des articles' },
        onClick: () => {
            if (typeof onBack === 'function') {
                onBack();
            }
        },
    });
}

function renderFavoriteButton(article, callback) {
    const favorite = articleBoolean(article, 'is_favorite', 'favorite');
    const label = favorite ? 'Retirer des favoris' : 'Ajouter aux favoris';
    return viewButton(label, {
        className: `reader-action-button reader-favorite${favorite ? ' is-favorite' : ''}`,
        icon: favorite ? 'star-filled' : 'star',
        attrs: {
            'aria-label': label,
            'aria-pressed': String(favorite),
            title: label,
        },
        onClick: () => invokeToggle(callback, article, !favorite),
    });
}

function renderReadButton(article, callback) {
    const read = articleBoolean(article, 'is_read', 'read');
    const label = read ? 'Marquer comme non lu' : 'Marquer comme lu';
    return viewButton(label, {
        className: `reader-action-button reader-read-state${read ? ' is-read' : ' is-unread'}`,
        icon: read ? 'read' : 'unread',
        attrs: {
            'aria-label': label,
            'aria-pressed': String(read),
            title: label,
        },
        onClick: () => invokeToggle(callback, article, !read),
    });
}

function renderExternalLink(article) {
    const url = originalArticleUrl(article);
    if (url === null) {
        return viewEl('span', {
            className: 'reader-original-unavailable',
            text: 'Lien original indisponible',
        });
    }

    return viewEl('a', {
        className: 'reader-external-link',
        attrs: {
            href: url,
            target: '_blank',
            rel: 'noopener noreferrer',
            'aria-label': 'Ouvrir l’article original dans un nouvel onglet',
        },
    }, [
        viewIcon('external', 'reader-external-icon'),
        viewEl('span', { text: 'Ouvrir l’article original (nouvel onglet)' }),
    ]);
}

function renderArticleContent(value) {
    const text = textValue(value, EMPTY_CONTENT).trim();
    if (typeof DOMParser !== 'undefined' && /<\/?(?:p|h[1-4]|ul|ol|li|blockquote|pre|code|strong|em|a|img|table|thead|tbody|tfoot|tr|th|td|details|summary)\b/i.test(text)) {
        const parsed = new DOMParser().parseFromString(text, 'text/html');
        const content = viewEl('div', { className: 'reader-article-content' });
        Array.from(parsed.body.childNodes).forEach((node) => {
            content.appendChild(document.importNode(node, true));
        });
        return content;
    }
    const paragraphs = text.split(/\n\s*\n/u).map((paragraph) => paragraph.trim()).filter((paragraph) => paragraph !== '');
    const blocks = [];
    const pushLongParagraph = (paragraph) => {
        const sentences = paragraph.match(/[^.!?…]+(?:[.!?…]+|$)/gu) || [paragraph];
        if (sentences.length > 2) {
            for (let index = 0; index < sentences.length; index += 2) {
                blocks.push(viewEl('p', { text: sentences.slice(index, index + 2).join(' ').trim() }));
            }
            return;
        }
        blocks.push(viewEl('p', { text: paragraph.trim() }));
    };
    paragraphs.forEach((paragraph, index) => {
        const isLongParagraph = index === 0 ? paragraphs[0].length > 280 : paragraph.length > 280;
        const lines = paragraph.split('\n').map((line) => line.trim());
        if (lines.length > 0 && lines.every((line) => /^\s*>\s?/u.test(line))) {
            const quote = lines.map((line) => line.replace(/^\s*>\s?/u, '').trim()).join(' ').trim();
            blocks.push(viewEl('blockquote', {}, [viewEl('p', { text: quote })]));
            return;
        }
        if (isLongParagraph) {
            pushLongParagraph(paragraph);
            return;
        }
        blocks.push(viewEl('p', { text: paragraph.trim() }));
    });
    return viewEl('div', { className: 'reader-article-content' }, blocks);
}

export class ReaderView {
    constructor(options = {}, callbacks = {}) {
        const source = isObject(options) ? options : {};
        const callbackValues = {
            ...(isObject(source.callbacks) ? source.callbacks : {}),
            ...(isObject(callbacks) ? callbacks : {}),
        };
        this.pane = optionValue(source, 'pane')
            || optionValue(source, 'readerPane')
            || elementById('reader-pane');
        this.content = optionValue(source, 'content')
            || optionValue(source, 'readerContent')
            || elementById('reader-content');
        this.placeholder = optionValue(source, 'placeholder')
            || optionValue(source, 'readerPlaceholder')
            || elementById('reader-placeholder');
        this.callbacks = {
            onBack: callbackOption(source, callbackValues, 'onBack', ['back']),
            onToggleFavorite: callbackOption(
                source,
                callbackValues,
                'onToggleFavorite',
                ['toggleFavorite', 'onFavorite'],
            ),
            onToggleRead: callbackOption(
                source,
                callbackValues,
                'onToggleRead',
                ['onToggleReadState', 'toggleRead', 'readChange'],
            ),
            onRetry: callbackOption(source, callbackValues, 'onRetry', ['retry']),
        };
        if (this.callbacks.onToggleFavorite === NOOP && typeof callbackValues.onToggle === 'function') {
            this.callbacks.onToggleFavorite = callbackValues.onToggle;
        }
        if (this.callbacks.onToggleRead === NOOP && typeof callbackValues.onToggle === 'function') {
            this.callbacks.onToggleRead = callbackValues.onToggle;
        }
        this.article = null;
    }

    render(article, options = {}) {
        const value = articleFrom(article);
        if (!value) {
            this.renderPlaceholder(options);
            return;
        }

        this.article = value;
        this._setBusy(false);
        setHidden(this.placeholder, true);
        setHidden(this.content, false);
        setAttribute(this.pane, 'aria-labelledby', 'reader-article-title');
        replaceChildren(this.content, this._articleChildren(value));
    }

    renderLoading(options = {}) {
        const settings = isObject(options) ? options : {};
        this.article = null;
        this._setBusy(true);
        setHidden(this.content, true);
        setHidden(this.placeholder, false);
        setAttribute(this.pane, 'aria-labelledby', 'reader-title');
        replaceChildren(this.placeholder, [
            viewEl('h2', {
                className: 'visually-hidden',
                attrs: { id: 'reader-title' },
                text: 'Chargement de l’article',
            }),
            spinnerBlock(nonEmpty(settings.message)
                ? textValue(settings.message)
                : 'Chargement de l’article…'),
        ]);
    }

    renderError(error, options = {}) {
        this.article = null;
        this._setBusy(false);
        setHidden(this.content, true);
        setHidden(this.placeholder, false);
        setAttribute(this.pane, 'aria-labelledby', 'reader-title');
        const hasRetry = this.callbacks.onRetry !== NOOP;
        replaceChildren(this.placeholder, [
            stateWithAction({
                icon: 'alert',
                title: 'Impossible de charger l’article',
                titleId: 'reader-title',
                role: 'alert',
                message: 'Cet article n’est pas disponible pour le moment.',
                actionLabel: hasRetry ? 'Réessayer' : 'Retour',
                onAction: hasRetry ? this.callbacks.onRetry : this.callbacks.onBack,
            }),
        ]);
    }

    renderPlaceholder(options = {}) {
        const settings = typeof options === 'string'
            ? { message: options }
            : (isObject(options) ? options : {});
        this.article = null;
        this._setBusy(false);
        setHidden(this.content, true);
        setHidden(this.placeholder, false);
        setAttribute(this.pane, 'aria-labelledby', 'reader-title');
        if (nonEmpty(settings.title) || nonEmpty(settings.message)) {
            replaceChildren(this.placeholder, [
                stateWithAction({
                    icon: settings.icon || 'inbox',
                    title: nonEmpty(settings.title) ? textValue(settings.title) : DEFAULT_PLACEHOLDER_TITLE,
                    titleId: 'reader-title',
                    message: nonEmpty(settings.message)
                        ? textValue(settings.message)
                        : DEFAULT_PLACEHOLDER_MESSAGE,
                    actionLabel: settings.actionLabel,
                    onAction: settings.onAction,
                }),
            ]);
            return;
        }

        replaceChildren(this.placeholder, [
            viewEl('div', { className: 'state-icon', attrs: { 'aria-hidden': 'true' } }, [
                viewIcon('inbox'),
            ]),
            viewEl('h2', {
                className: 'state-title',
                attrs: { id: 'reader-title' },
                text: DEFAULT_PLACEHOLDER_TITLE,
            }),
            viewEl('p', {
                className: 'state-message',
                text: DEFAULT_PLACEHOLDER_MESSAGE,
            }),
        ]);
    }

    _setBusy(busy) {
        setAttribute(this.pane, 'aria-busy', String(busy));
        if (this.content) {
            setAttribute(this.content, 'aria-busy', String(busy));
        }
    }

    _articleChildren(article) {
        const title = articleTitle(article);
        const date = articleDate(article);
        const author = nonEmpty(article.author) ? textValue(article.author) : null;
        const contentText = nonEmpty(article.content)
            ? textValue(article.content)
            : articleSummary(article);
        const headerChildren = [
            renderBackButton(this.callbacks.onBack),
            viewEl('div', { className: 'reader-heading' }, [
                viewEl('h1', {
                    className: 'reader-title',
                    id: 'reader-article-title',
                    text: title,
                }),
                (() => {
                    const href = sourceHref(article);
                    return viewEl(href === null ? 'div' : 'a', {
                        className: `reader-source${href === null ? '' : ' reader-source-link'}`,
                        attrs: href === null ? {} : { href, title: 'Voir les articles de cette source' },
                    }, [
                        createFavicon(article),
                        viewEl('span', { className: 'reader-source-name', text: sourceName(article) }),
                    ]);
                })(),
                viewEl('div', { className: 'reader-meta' }, [
                    author === null ? null : viewEl('span', { className: 'reader-author', text: author }),
                    viewEl('time', {
                        className: 'reader-date',
                        text: date.label,
                        attrs: date.raw === null ? {} : { datetime: textValue(date.raw) },
                    }),
                    viewEl('span', {
                        className: 'article-category-tag reader-category-tag',
                        text: categoryName(article),
                    }),
                ]),
            ]),
            viewEl('div', { className: 'reader-actions' }, [
                renderFavoriteButton(article, this.callbacks.onToggleFavorite),
                renderReadButton(article, this.callbacks.onToggleRead),
            ]),
        ].filter(Boolean);
        const image = createArticleImage(article);
        const bodyChildren = [];
        if (image !== null) {
            bodyChildren.push(image);
        }
        bodyChildren.push(renderArticleContent(contentText));
        bodyChildren.push(articleTagList(article));
        bodyChildren.push(renderExternalLink(article));
        bodyChildren.push(renderShareButton(article));
        bodyChildren.push(viewEl('div', { className: 'reader-footer' }, [
            renderBackButton(this.callbacks.onBack, 'reader-back-button-bottom'),
        ]));

        return [
            viewEl('header', { className: 'reader-header' }, headerChildren),
            viewEl('div', { className: 'reader-body' }, bodyChildren),
        ];
    }
}
