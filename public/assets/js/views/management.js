import { buildRoute } from '../router.js';
import { errorMessage as domErrorMessage } from '../utils/dom.js';
import { formatDateTime, formatFeedStatus, formatNumber } from '../utils/format.js';
import {
    button,
    clear,
    el,
    icon,
    openConfirmDialog,
    setChildren,
    spinnerBlock,
    stateBlock,
} from './feed-dialogs.js';

let managementSequence = 0;

function isElement(value) {
    return value !== null
        && typeof value === 'object'
        && (typeof value.nodeType === 'number' || typeof value.querySelector === 'function');
}

function resolveRoot(root) {
    if (typeof root === 'string') {
        return globalThis.document ? globalThis.document.querySelector(root) : null;
    }
    if (isElement(root)) {
        if (root.id === 'utility-content') {
            return root;
        }
        if (typeof root.querySelector === 'function') {
            return root.querySelector('#utility-content') || root;
        }
    }
    return globalThis.document ? globalThis.document.querySelector('#utility-content') : null;
}

function constructorValues(root, callbacks) {
    if (
        root !== null
        && typeof root === 'object'
        && !isElement(root)
        && !Array.isArray(root)
    ) {
        return {
            root: root.root || '#utility-content',
            callbacks: root,
        };
    }
    return { root, callbacks: callbacks || {} };
}

function listValue(value) {
    return Array.isArray(value) ? value : [];
}

function textValue(value, fallback = '') {
    return value === null || value === undefined ? fallback : String(value);
}

function positiveId(value) {
    const number = typeof value === 'number' ? value : Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

function idKey(value) {
    const id = positiveId(value);
    return id === null ? '' : String(id);
}

function isHttpUrl(value) {
    try {
        const url = new URL(String(value));
        return (url.protocol === 'http:' || url.protocol === 'https:')
            && url.username === ''
            && url.password === '';
    } catch {
        return false;
    }
}

function isLocalUrl(value) {
    return typeof value === 'string'
        && value.startsWith('/')
        && !value.startsWith('//');
}

function callbackFor(callbacks, name) {
    return callbacks && typeof callbacks[name] === 'function' ? callbacks[name] : null;
}

function detailItem(label, value) {
    const item = el('div', { className: 'management-detail' });
    item.appendChild(el('dt', {}, label));
    if (isElement(value)) {
        item.appendChild(el('dd', {}, value));
    } else {
        item.appendChild(el('dd', {}, String(value)));
    }
    return item;
}

function externalLink(label, value) {
    const url = textValue(value, '');
    if (isHttpUrl(url)) {
        return el('a', {
            href: url,
            target: '_blank',
            rel: 'noopener noreferrer',
            className: 'management-url',
        }, url || label);
    }
    return el('span', { className: 'management-url' }, url || label);
}

function categoryName(categoryId, categories, fallback = 'Sans catégorie') {
    const key = idKey(categoryId);
    const category = listValue(categories).find((item) => idKey(item && item.id) === key);
    return category ? textValue(category.name, fallback) : fallback;
}

function booleanValue(value, fallback = true) {
    if (value === true || value === 1 || value === '1' || value === 'true') {
        return true;
    }
    if (value === false || value === 0 || value === '0' || value === 'false') {
        return false;
    }
    return fallback;
}

function feedIsActive(feed) {
    return feed !== null
        && typeof feed === 'object'
        && booleanValue(feed.is_active, booleanValue(feed.active, true));
}

function setError(node, message) {
    clear(node);
    node.hidden = !message;
    node.textContent = message ? String(message) : '';
}

function addMessage(root, message) {
    const node = el('p', { className: 'form-error', role: 'alert', hidden: true });
    setError(node, message);
    return node;
}

export class ManagementView {
    constructor(root = '#utility-content', callbacks = {}) {
        const values = constructorValues(root, callbacks);
        this.root = resolveRoot(values.root);
        this.callbacks = { ...(values.callbacks || {}) };
        this.instanceId = `management-${++managementSequence}`;
        this.state = {
            feeds: undefined,
            categories: undefined,
            busyFeedId: null,
            categoryFilter: 'all',
        };
        this.activeTab = 'feeds';
        this.pending = new Set();
        this.error = '';
    }

    render(data = {}) {
        if (data !== null && typeof data === 'object') {
            const responseData = data.data !== undefined ? data.data : data;
            const sources = [data, responseData];
            if (Array.isArray(responseData)) {
                sources.unshift({ feeds: responseData });
            }
            sources.forEach((source) => {
                if (source === null || typeof source !== 'object') {
                    return;
                }
                if (Object.prototype.hasOwnProperty.call(source, 'feeds')) {
                    this.state.feeds = listValue(source.feeds);
                }
                if (Object.prototype.hasOwnProperty.call(source, 'categories')) {
                    this.state.categories = listValue(source.categories);
                }
                if (Object.prototype.hasOwnProperty.call(source, 'busyFeedId')) {
                    this.state.busyFeedId = source.busyFeedId ?? null;
                }
            });
        }
        this.renderRoot();
        return this;
    }

    renderRoot() {
        if (!this.root) {
            return;
        }

        const headingId = `${this.instanceId}-title`;
        const view = el('section', {
            className: 'management-view',
            'aria-labelledby': headingId,
        });
        const header = el('header', { className: 'management-header' });
        const heading = el('div', {}, [
            el('p', { className: 'eyebrow' }, 'Organisation'),
            el('h1', { id: headingId }, 'Gérer les flux'),
        ]);
        header.appendChild(heading);
        view.appendChild(header);

        if (this.error) {
            view.appendChild(addMessage(view, this.error));
        }

        const tabList = el('div', {
            className: 'management-tabs',
            role: 'tablist',
            'aria-label': 'Gestion des flux et catégories',
        });
        const feedTabId = `${this.instanceId}-feeds-tab`;
        const categoryTabId = `${this.instanceId}-categories-tab`;
        const feedPanelId = `${this.instanceId}-feeds-panel`;
        const categoryPanelId = `${this.instanceId}-categories-panel`;
        const feedTab = this.renderTab('Flux', feedTabId, feedPanelId, this.activeTab === 'feeds');
        const categoryTab = this.renderTab('Catégories', categoryTabId, categoryPanelId, this.activeTab === 'categories');
        feedTab.addEventListener('click', () => this.activateTab('feeds'));
        categoryTab.addEventListener('click', () => this.activateTab('categories'));
        feedTab.addEventListener('keydown', (event) => this.tabKeydown(event, 'categories'));
        categoryTab.addEventListener('keydown', (event) => this.tabKeydown(event, 'feeds'));
        tabList.appendChild(feedTab);
        tabList.appendChild(categoryTab);
        view.appendChild(tabList);

        const feedPanel = el('div', {
            id: feedPanelId,
            className: 'management-panel',
            role: 'tabpanel',
            tabIndex: 0,
            'aria-labelledby': feedTabId,
        });
        feedPanel.hidden = this.activeTab !== 'feeds';
        setChildren(feedPanel, this.renderFeedsPanel());
        view.appendChild(feedPanel);

        const categoryPanel = el('div', {
            id: categoryPanelId,
            className: 'management-panel',
            role: 'tabpanel',
            tabIndex: 0,
            'aria-labelledby': categoryTabId,
        });
        categoryPanel.hidden = this.activeTab !== 'categories';
        setChildren(categoryPanel, this.renderCategoriesPanel());
        view.appendChild(categoryPanel);

        setChildren(this.root, view);
    }

    renderTab(label, id, panelId, selected) {
        return button(label, {
            id,
            className: `tab-button${selected ? ' is-active' : ''}`,
            role: 'tab',
            ariaSelected: selected,
            ariaControls: panelId,
            tabIndex: selected ? 0 : -1,
        });
    }

    activateTab(tab) {
        if (tab !== 'feeds' && tab !== 'categories') {
            return;
        }
        this.activeTab = tab;
        this.renderRoot();
        const selected = this.root && typeof this.root.querySelector === 'function'
            ? this.root.querySelector(`#${this.instanceId}-${tab}-tab`)
            : null;
        if (selected && typeof selected.focus === 'function') {
            selected.focus();
        }
    }

    tabKeydown(event, otherTab) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End') {
            return;
        }
        event.preventDefault();
        if (event.key === 'Home') {
            this.activateTab('feeds');
        } else if (event.key === 'End') {
            this.activateTab('categories');
        } else {
            this.activateTab(otherTab);
        }
    }

    renderHeaderActions() {
        const actions = el('div', { className: 'management-panel-actions' });
        if (this.activeTab === 'feeds') {
            const addCallback = callbackFor(this.callbacks, 'onAddFeed');
            const refreshCallback = callbackFor(this.callbacks, 'onRefreshAll');
            actions.appendChild(button('Ajouter un flux', {
                className: 'button button-primary',
                icon: 'plus',
                disabled: this.isPending('add-feed') || typeof addCallback !== 'function',
                onClick: () => this.runAction('add-feed', addCallback),
            }));
            actions.appendChild(button('Actualiser tous les flux', {
                className: 'button',
                icon: 'refresh',
                disabled: this.isPending('refresh-all') || this.isAllBusy() || typeof refreshCallback !== 'function',
                onClick: () => this.runAction('refresh-all', refreshCallback),
            }));
        }
        return actions;
    }

    filteredFeeds() {
        const feeds = listValue(this.state.feeds);
        const filter = textValue(this.state.categoryFilter, 'all');
        if (filter === 'all') {
            return feeds;
        }
        return feeds.filter((feed) => idKey(feed && feed.category_id)
            === (filter === 'uncategorized' ? '' : idKey(filter)));
    }

    renderFeedCategoryFilter(id) {
        const categories = listValue(this.state.categories)
            .filter((category) => idKey(category && category.id) !== '')
            .map((category) => ({
                value: idKey(category && category.id),
                label: textValue(category && category.name, 'Catégorie sans nom'),
            }));
        const select = el('select', {
            id,
            className: 'management-filter-select',
            value: textValue(this.state.categoryFilter, 'all'),
        });
        [
            { value: 'all', label: 'Toutes les catégories' },
            { value: 'uncategorized', label: 'Sans catégorie' },
            ...categories,
        ].forEach((option) => select.appendChild(el('option', {
            value: option.value,
            text: option.label,
            selected: option.value === textValue(this.state.categoryFilter, 'all'),
        })));
        return el('div', { className: 'management-filter' }, [
            el('label', { className: 'management-filter-label', htmlFor: id, text: 'Filtrer par catégorie' }),
            select,
        ]);
    }

    renderFeedsPanelToolbar() {
        const toolbar = el('div', { className: 'management-panel-toolbar' });
        if (this.state.feeds.length > 0) {
            toolbar.appendChild(el('p', { className: 'panel-summary' }, `${formatNumber(this.state.feeds.length)} flux`));
        }
        const actions = this.renderHeaderActions();
        if (actions.childElementCount > 0) {
            toolbar.appendChild(actions);
        }
        return toolbar;
    }

    renderFeedsPanel() {
        const panel = el('div', { className: 'management-panel-content' });
        if (this.state.feeds === undefined) {
            panel.appendChild(spinnerBlock('Chargement des flux…'));
            return panel;
        }
        const toolbar = this.renderFeedsPanelToolbar();
        if (toolbar.childElementCount > 0) {
            panel.appendChild(toolbar);
        }
        if (this.state.feeds.length === 0) {
            panel.appendChild(stateBlock('Aucun flux', 'Aucun flux pour le moment.', {
                iconName: 'rss',
                action: button('Ajouter un flux', {
                    className: 'button button-primary',
                    icon: 'plus',
                    disabled: typeof callbackFor(this.callbacks, 'onAddFeed') !== 'function',
                    onClick: () => this.runAction('add-feed', callbackFor(this.callbacks, 'onAddFeed')),
                }),
            }));
            return panel;
        }

        const filterControl = this.renderFeedCategoryFilter(`${this.instanceId}-category-filter`);
        const list = el('ul', { className: 'management-list feed-list', 'aria-label': 'Liste des flux' });
        const refreshList = () => {
            setChildren(list, this.filteredFeeds().map((feed) => this.renderFeed(feed)));
        };
        refreshList();
        filterControl.querySelector('select').addEventListener('change', (event) => {
            this.state.categoryFilter = event.target.value;
            refreshList();
        });
        panel.appendChild(filterControl);
        panel.appendChild(list);
        return panel;
    }

    renderFeed(feed) {
        const value = feed !== null && typeof feed === 'object' ? feed : {};
        const id = positiveId(value.id);
        const key = idKey(value.id);
        const name = textValue(value.name, 'Flux sans nom');
        const active = feedIsActive(value);
        const busy = this.feedBusy(value.id);
        const item = el('li', { className: 'management-item feed-item' });
        if (key !== '') {
            item.setAttribute('data-feed-id', key);
        }

        const itemHeader = el('div', { className: 'management-item-header' });
        const titleWrap = el('div', { className: 'management-item-title-wrap' });
        const faviconUrl = textValue(value.favicon_url, '');
        if (isLocalUrl(faviconUrl)) {
            titleWrap.appendChild(el('img', {
                className: 'feed-favicon',
                src: faviconUrl,
                alt: '',
                loading: 'lazy',
            }));
        }
        if (id === null) {
            titleWrap.appendChild(el('span', { className: 'management-item-title' }, name));
        } else {
            titleWrap.appendChild(el('a', {
                className: 'management-item-title',
                href: buildRoute('feed', { id }),
            }, name));
        }
        itemHeader.appendChild(titleWrap);
        itemHeader.appendChild(el('span', { className: `status-badge${active ? ' is-active' : ''}` }, active ? 'Actif' : 'Désactivé'));
        item.appendChild(itemHeader);

        const details = el('dl', { className: 'management-details' });
        details.appendChild(detailItem('Catégorie', categoryName(value.category_id, this.state.categories, textValue(value.category_name, 'Sans catégorie'))));
        details.appendChild(detailItem('État de récupération', formatFeedStatus(value)));
        details.appendChild(detailItem('Dernière tentative', formatDateTime(value.last_fetch_attempt_at ?? value.last_fetched_at)));
        details.appendChild(detailItem('Dernière récupération réussie', formatDateTime(value.last_successful_fetch_at)));
        details.appendChild(detailItem('Dernier article', formatDateTime(value.last_article_at)));
        details.appendChild(detailItem('URL du flux', externalLink('URL du flux', value.feed_url)));
        if (value.site_url) {
            details.appendChild(detailItem('Site source', externalLink('Site source', value.site_url)));
        }
        const count = value.article_count ?? value.articles_count;
        if (count !== null && count !== undefined) {
            details.appendChild(detailItem('Articles enregistrés', formatNumber(count)));
        }
        item.appendChild(details);

        if (typeof value.last_fetch_error === 'string' && value.last_fetch_error !== '') {
            item.appendChild(el('p', { className: 'feed-error' }, value.last_fetch_error));
        }

        const actions = el('div', { className: 'management-item-actions' });
        const refreshCallback = callbackFor(this.callbacks, 'onRefreshFeed');
        const toggleCallback = callbackFor(this.callbacks, 'onToggleFeed');
        const editCallback = callbackFor(this.callbacks, 'onEditFeed');
        const deleteCallback = callbackFor(this.callbacks, 'onDeleteFeed');
        const actionKey = (action) => `feed:${key}:${action}`;
        actions.appendChild(button('Actualiser', {
            className: 'button button-small',
            icon: 'refresh',
            disabled: busy || !active || typeof refreshCallback !== 'function',
            ariaLabel: `Actualiser ${name}`,
            onClick: () => this.runFeedAction(value, 'refresh', refreshCallback, actionKey('refresh')),
        }));
        actions.appendChild(button(active ? 'Désactiver' : 'Activer', {
            className: 'button button-small',
            icon: active ? 'offline' : 'check',
            disabled: busy || typeof toggleCallback !== 'function',
            ariaLabel: `${active ? 'Désactiver' : 'Activer'} ${name}`,
            onClick: () => this.runFeedAction(value, 'toggle', toggleCallback, actionKey('toggle')),
        }));
        actions.appendChild(button('Modifier', {
            className: 'button button-small',
            icon: 'edit',
            disabled: busy || typeof editCallback !== 'function',
            ariaLabel: `Modifier ${name}`,
            onClick: () => this.runAction(actionKey('edit'), editCallback, value),
        }));
        actions.appendChild(button('Supprimer', {
            className: 'button button-small button-danger',
            icon: 'trash',
            disabled: busy || typeof deleteCallback !== 'function',
            ariaLabel: `Supprimer ${name}`,
            onClick: () => this.confirmDeleteFeed(value, name, deleteCallback, actionKey('delete')),
        }));
        item.appendChild(actions);

        if (busy) {
            item.appendChild(el('p', { className: 'inline-status', role: 'status' }, 'Opération en cours…'));
        }
        return item;
    }

    renderCategoriesPanel() {
        const panel = el('div', { className: 'management-panel-content' });
        if (this.state.categories === undefined) {
            panel.appendChild(spinnerBlock('Chargement des catégories…'));
            return panel;
        }
        const addCallback = callbackFor(this.callbacks, 'onAddCategory');
        panel.appendChild(el('div', { className: 'panel-toolbar' }, [
            el('p', { className: 'panel-summary' }, `${formatNumber(this.state.categories.length)} catégorie${this.state.categories.length > 1 ? 's' : ''}`),
            button('Ajouter une catégorie', {
                className: 'button button-primary',
                icon: 'plus',
                disabled: this.isPending('add-category') || typeof addCallback !== 'function',
                onClick: () => this.runAction('add-category', addCallback),
            }),
        ]));
        if (this.state.categories.length === 0) {
            panel.appendChild(stateBlock('Aucune catégorie', 'Créez une première catégorie pour organiser vos flux.', {
                iconName: 'folder',
            }));
            return panel;
        }

        const list = el('ul', { className: 'management-list category-list', 'aria-label': 'Liste des catégories' });
        this.state.categories.forEach((category) => list.appendChild(this.renderCategory(category)));
        panel.appendChild(list);
        return panel;
    }

    renderCategory(category) {
        const value = category !== null && typeof category === 'object' ? category : {};
        const id = idKey(value.id);
        const name = textValue(value.name, 'Catégorie sans nom');
        const feeds = listValue(this.state.feeds).filter((feed) => idKey(feed && feed.category_id) === id);
        const item = el('li', { className: 'management-item category-item' });
        if (id !== '') {
            item.setAttribute('data-category-id', id);
        }
        const header = el('div', { className: 'management-item-header' }, [
            el('div', { className: 'management-item-title-wrap' }, [
                icon('folder', { className: 'icon category-icon', label: '' }),
                el('span', { className: 'management-item-title' }, name),
            ]),
            el('span', { className: 'status-badge' }, `${formatNumber(feeds.length)} flux`),
        ]);
        item.appendChild(header);
        item.appendChild(el('p', { className: 'management-item-description' }, 'La suppression conserve les flux et les place dans « Sans catégorie ».'));
        const actions = el('div', { className: 'management-item-actions' });
        const editCallback = callbackFor(this.callbacks, 'onEditCategory');
        const deleteCallback = callbackFor(this.callbacks, 'onDeleteCategory');
        actions.appendChild(button('Modifier', {
            className: 'button button-small',
            icon: 'edit',
            disabled: typeof editCallback !== 'function',
            ariaLabel: `Modifier ${name}`,
            onClick: () => this.runAction(`category:${id}:edit`, editCallback, value),
        }));
        actions.appendChild(button('Supprimer', {
            className: 'button button-small button-danger',
            icon: 'trash',
            disabled: typeof deleteCallback !== 'function',
            ariaLabel: `Supprimer ${name}`,
            onClick: () => this.confirmDeleteCategory(value, name, deleteCallback, `category:${id}:delete`),
        }));
        item.appendChild(actions);
        return item;
    }

    feedBusy(feedId) {
        const key = idKey(feedId);
        const externalKey = this.state.busyFeedId === 'all' ? 'all' : idKey(this.state.busyFeedId);
        if (externalKey === key && key !== '') {
            return true;
        }
        for (const pending of this.pending) {
            if (pending.startsWith(`feed:${key}:`)) {
                return true;
            }
        }
        return false;
    }

    isPending(key) {
        return this.pending.has(key);
    }

    isAllBusy() {
        return this.state.busyFeedId === 'all' || this.isPending('refresh-all');
    }

    confirmDeleteFeed(feed, name, callback, key) {
        if (typeof callback !== 'function') {
            return;
        }
        openConfirmDialog({
            title: 'Supprimer le flux',
            message: `Le flux « ${name} », ses articles et ses médias associés seront supprimés définitivement. Voulez-vous continuer ?`,
            confirmLabel: 'Supprimer le flux',
            tone: 'danger',
            onConfirm: () => this.runAction(key, callback, feed),
        });
    }

    confirmDeleteCategory(category, name, callback, key) {
        if (typeof callback !== 'function') {
            return;
        }
        openConfirmDialog({
            title: 'Supprimer la catégorie',
            message: `La catégorie « ${name} » sera supprimée. Les flux qu’elle contient resteront actifs dans « Sans catégorie ».`,
            confirmLabel: 'Supprimer la catégorie',
            tone: 'danger',
            onConfirm: () => this.runAction(key, callback, category),
        });
    }

    async runFeedAction(feed, action, callback, key) {
        if (typeof callback !== 'function') {
            return;
        }
        const id = positiveId(feed && feed.id);
        if (action === 'refresh') {
            await this.runAction(key, callback, id);
        } else if (action === 'toggle') {
            await this.runAction(key, callback, id, !feedIsActive(feed));
        }
    }

    async runAction(key, callback, ...args) {
        if (typeof callback !== 'function' || this.pending.has(key)) {
            return;
        }
        this.pending.add(key);
        this.error = '';
        this.renderRoot();
        try {
            const result = await callback(...args);
            if (result === false) {
                this.error = 'L’action n’a pas pu être effectuée.';
                return false;
            }
            return true;
        } catch (failure) {
            this.error = domErrorMessage(failure);
            return false;
        } finally {
            this.pending.delete(key);
            this.renderRoot();
        }
    }
}
