import { ApiError, NetworkError, createApiClient } from './api/client.js';
import { closeDialog } from './components/dialog.js';
import { showToast } from './components/feedback.js';
import { ManagementView } from './views/management.js';
import { SettingsView } from './views/settings.js?v=2';
import { openAddFeedDialog, openCategoryDialog, openConfirmDialog, openFeedEditorDialog } from './views/feed-dialogs.js';
import { createLoginView } from './views/login.js';
import { ArticlesView } from './views/articles.js?v=25';
import { ReaderView } from './views/reader.js?v=29';
import { buildRoute, parseRoute } from './router.js?v=25';
import { errorMessage, el, icon, setChildren } from './utils/dom.js';

const app = {
    api: null,
    user: null,
    categories: [],
    feeds: [],
    counts: null,
    route: null,
    loginView: null,
    articlesView: null,
    readerView: null,
    managementView: null,
    settingsView: null,
    loadingRoute: false,
    articleListGeneration: 0,
    articleListRouteUrl: null,
    openCategoryIds: new Set(),
};

const dom = {};

function byId(id) {
    return document.getElementById(id);
}

function dataOf(response, fallback = {}) {
    return response && response.data !== undefined ? response.data : response ?? fallback;
}

function listOf(response, key) {
    const data = dataOf(response, {});
    if (Array.isArray(data)) return data;
    return Array.isArray(data[key]) ? data[key] : [];
}

function isAuthenticatedError(error) {
    return error instanceof ApiError && error.status === 401;
}

function setVisible(element, visible) {
    if (element) element.hidden = !visible;
}

function showStartup(visible) {
    setVisible(dom.startup, visible);
}

function showLogin(message = '') {
    app.user = null;
    app.articleListGeneration += 1;
    app.articleListRouteUrl = null;
    setVisible(dom.app, false);
    setVisible(dom.offline, false);
    setVisible(dom.login, true);
    document.title = 'Connexion - RSS Reader';
    if (!app.loginView) {
        app.loginView = createLoginView({
            root: document,
            onLogin: ({ username, password }) => login(username, password),
        });
    }
    app.loginView.setMessage(message);
    app.loginView.focus();
}

function showOffline(error) {
    setVisible(dom.login, false);
    setVisible(dom.app, false);
    setVisible(dom.offline, true);
    document.title = 'Serveur indisponible - RSS Reader';
    const message = byId('offline-message');
    if (message) {
        message.textContent = error instanceof NetworkError
            ? 'Le serveur est momentanément inaccessible. Vérifiez votre connexion puis réessayez.'
            : 'Le serveur ne peut pas être joint pour le moment.';
    }
    const heading = byId('offline-title');
    heading?.setAttribute('tabindex', '-1');
    focusWithoutScroll(heading);
}

function showApp() {
    setVisible(dom.startup, false);
    setVisible(dom.login, false);
    setVisible(dom.offline, false);
    setVisible(dom.app, true);
    if (dom.headerUsername) {
        dom.headerUsername.textContent = app.user?.username || app.user?.name || '';
    }
}

async function applyTheme(theme) {
    document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
}

async function loadTheme() {
    try {
        const response = await app.api.getSettings();
        const settings = dataOf(response);
        await applyTheme(settings && typeof settings === 'object' ? settings.theme : 'light');
    } catch {
    }
}

async function login(username, password) {
    const response = await app.api.login(username.trim(), password);
    app.user = dataOf(response).user || dataOf(response);
    await Promise.all([loadShell(), loadTheme()]);
    showApp();
    navigate();
}

async function logout() {
    try {
        await app.api.logout();
    } catch (error) {
        if (!isAuthenticatedError(error)) showToast(errorMessage(error), 'error');
    } finally {
        app.user = null;
        app.categories = [];
        app.feeds = [];
        app.counts = null;
        app.articleListGeneration += 1;
        app.articleListRouteUrl = null;
        closeDialog();
        showLogin('Vous êtes déconnecté.');
    }
}

async function loadShell() {
    const [categories, feeds, counts] = await Promise.all([
        app.api.listCategories(),
        app.api.listFeeds(),
        app.api.getCounts(),
    ]);
    app.categories = listOf(categories, 'categories');
    app.feeds = listOf(feeds, 'feeds');
    app.counts = dataOf(counts, {});
    renderNavigation();
}

function countFor(kind, id = null) {
    const counts = app.counts || {};
    if (kind === 'global') return Number(counts[id] || 0);
    const values = Array.isArray(counts[kind]) ? counts[kind] : [];
    const key = kind === 'categories' ? 'category_id' : 'feed_id';
    const item = values.find((value) => Number(value[key]) === Number(id));
    return item ? Number(item.unread || 0) : 0;
}

function navigationLink(label, href, count = null, current = false) {
    const link = el('a', {
        className: 'navigation-link',
        href,
        attributes: current ? { 'aria-current': 'page' } : {},
    });
    link.appendChild(el('span', {}, label));
    if (count !== null) link.appendChild(el('span', { className: 'navigation-count' }, String(count)));
    return link;
}

function categoryStateKey(categoryId) {
    return categoryId === null || categoryId === undefined ? 'uncategorized' : Number(categoryId);
}

function revealArticleFeedGroup(feedId) {
    const feed = app.feeds.find((value) => Number(value && value.id) === Number(feedId));
    const key = categoryStateKey(feed ? feed.category_id : null);
    if (app.openCategoryIds.size === 1 && app.openCategoryIds.has(key)) {
        return;
    }
    app.openCategoryIds.clear();
    app.openCategoryIds.add(key);
    renderNavigation();
}

function navigationRow(label, href, categoryId, current) {
    if (!Array.isArray(app.feeds) || app.feeds.length === 0) {
        return navigationLink(label, href, null, current);
    }

    const row = el('div', { className: 'navigation-group' });
    const link = el('a', {
        className: 'navigation-link',
        href,
        attributes: current ? { 'aria-current': 'page' } : {},
    });
    link.appendChild(el('span', {}, label));
    const listId = `navigation-feeds-${categoryId}`;
    const key = categoryStateKey(categoryId);
    const open = app.openCategoryIds.has(key);
    const toggle = el('button', {
        className: 'navigation-toggle',
        type: 'button',
        attrs: {
            'aria-expanded': String(open),
            'aria-controls': listId,
            'aria-label': `Afficher les abonnements de ${label}`,
            title: 'Afficher les abonnements',
        },
        onClick: () => {
            if (app.openCategoryIds.has(key)) {
                app.openCategoryIds.clear();
            } else {
                app.openCategoryIds.clear();
                app.openCategoryIds.add(key);
            }
            renderNavigation();
        },
    }, [icon('chevron', { className: 'navigation-toggle-icon' })]);
    link.appendChild(toggle);
    row.appendChild(link);

    const categoryKey = categoryId === null ? 'uncategorized' : Number(categoryId);
    const feeds = app.feeds.filter((feed) => (
        categoryId === null
            ? categoryKeyOf(feed) === null
            : categoryKeyOf(feed) === categoryKey
    ));
    const feedsRoute = feeds.map((feed) => navigationLink(
        feedName(feed),
        buildRoute('feed', { id: feedId(feed) }),
        null,
        navigationRouteFeedCurrent(feed),
    ));
    const sublist = el('div', {
        id: listId,
        className: 'navigation-sublist',
        attributes: open ? {} : { hidden: true },
    }, feedsRoute);
    row.appendChild(sublist);
    return row;
}

function categoryKeyOf(feed) {
    if (!feed || typeof feed !== 'object') return null;
    const id = feed.category_id ?? (feed.category && feed.category.id) ?? null;
    return id === null || id === undefined || id === '' ? null : Number(id);
}

function feedId(feed) {
    return feed && feed.id !== undefined ? feed.id : null;
}

function feedName(feed) {
    return feed && feed.name ? feed.name : 'Flux';
}

function navigationRouteFeedCurrent(feed) {
    const route = app.route || parseRoute(window.location.hash);
    const listRoute = route.name === 'article' ? parseRoute(route.query.from || '#/') : route;
    return listRoute.name === 'feed' && Number(listRoute.params.id) === Number(feedId(feed));
}

function renderNavigation() {
    if (!dom.mainNavigation) return;
    const route = app.route || parseRoute(window.location.hash);
    const navigationRoute = route.name === 'article'
        ? parseRoute(route.query.from || '#/')
        : route;
    [dom.managementLink, dom.settingsLink, dom.headerSettingsLink].forEach((link) => {
        link?.removeAttribute('aria-current');
    });
    if (route.name === 'manage') {
        dom.managementLink?.setAttribute('aria-current', 'page');
    }
    if (route.name === 'settings') {
        dom.settingsLink?.setAttribute('aria-current', 'page');
        dom.headerSettingsLink?.setAttribute('aria-current', 'page');
    }
    const main = [
        ['Tous', buildRoute('home'), 'home', countFor('global', 'all')],
        ['Non lus', buildRoute('unread'), 'unread', countFor('global', 'unread')],
        ['Lus', buildRoute('read'), 'read', countFor('global', 'read')],
        ['Favoris', buildRoute('favorites'), 'favorites', countFor('global', 'favorites')],
        ['Recommandé', buildRoute('recommendations'), 'recommendations', null],
    ];
    setChildren(dom.mainNavigation, main.map(([label, href, name, count]) => navigationLink(
        label,
        href,
        count,
        navigationRoute.name === name,
    )));
    setChildren(dom.categoryNavigation, [
        ...app.categories.map((category) => navigationRow(
            category.name,
            buildRoute('category', { id: category.id }),
            category.id,
            navigationRoute.name === 'category'
                && Number(navigationRoute.params.id) === Number(category.id),
        )),
        navigationRow(
            'Sans catégorie',
            buildRoute('uncategorized'),
            null,
            navigationRoute.name === 'uncategorized',
        ),
    ]);
    if (dom.articleCategoryFilter) {
        const currentListRoute = route.name === 'article'
            ? parseRoute(route.query.from || '#/')
            : route;
        const options = [
            Object.assign(document.createElement('option'), { value: 'all', textContent: 'Toutes les catégories' }),
            Object.assign(document.createElement('option'), { value: 'uncategorized', textContent: 'Sans catégorie' }),
            ...app.categories.map((category) => Object.assign(document.createElement('option'), {
                value: String(category.id),
                textContent: category.name,
            })),
        ];
        setChildren(dom.articleCategoryFilter, options);
        dom.articleCategoryFilter.value = selectedCategory(currentListRoute);
    }
}

function articleQuery(route, page = 1) {
    const query = { page };
    if (route.name === 'unread' || route.name === 'read' || route.name === 'favorites') query.filter = route.name;
    if (route.name === 'category') query.category_id = route.params.id;
    if (route.name === 'uncategorized') query.category = 'uncategorized';
    if (route.name === 'feed') query.feed_id = route.params.id;
    if (!['category', 'uncategorized'].includes(route.name)) {
        if (route.query?.category_id) query.category_id = route.query.category_id;
        else if (route.query?.category === 'uncategorized') query.category = 'uncategorized';
    }
    return query;
}

function routeContext(route) {
    const options = { route, filter: route.name, hasSubscriptions: app.feeds.length > 0 };
    if (route.name === 'search') {
        options.filter = 'search';
        options.query = route.query.q || '';
    }
    if (route.name === 'category') {
        const category = app.categories.find((value) => Number(value.id) === Number(route.params.id));
        options.categoryName = category?.name || 'Catégorie';
    }
    if (route.name === 'feed') {
        const feed = app.feeds.find((value) => Number(value.id) === Number(route.params.id));
        options.feedName = feed?.name || 'Flux';
    }
    return options;
}

function routeUrl(route) {
    if (!route || typeof route !== 'object') return '/#/';
    if (route.name === 'category') return buildRoute('category', { id: route.params.id });
    if (route.name === 'uncategorized') return buildRoute('uncategorized');
    let url;
    if (route.name === 'feed') url = buildRoute('feed', { id: route.params.id });
    else if (route.name === 'unread' || route.name === 'read'
        || route.name === 'favorites' || route.name === 'recommendations') {
        url = buildRoute(route.name);
    } else if (route.name === 'search') url = buildRoute('search', { q: route.query.q || '' });
    else url = buildRoute('home');
    if (!['category', 'uncategorized', 'article'].includes(route.name)) {
        if (route.query?.category_id) {
            url += `${url.includes('?') ? '&' : '?'}category_id=${encodeURIComponent(route.query.category_id)}`;
        } else if (route.query?.category === 'uncategorized') {
            url += `${url.includes('?') ? '&' : '?'}category=uncategorized`;
        }
    }
    return url;
}

function selectedCategory(route) {
    if (route.name === 'category') return String(route.params.id);
    if (route.name === 'uncategorized') return 'uncategorized';
    return route.query?.category_id
        || (route.query?.category === 'uncategorized' ? 'uncategorized' : 'all');
}

function categoryFilterUrl(route, selection) {
    if (route.name === 'category' || route.name === 'uncategorized') {
        if (selection === 'all') return buildRoute('home');
        if (selection === 'uncategorized') return buildRoute('uncategorized');
        return buildRoute('category', { id: selection });
    }
    const baseRoute = { ...route, query: { ...(route.query || {}) } };
    delete baseRoute.query.category_id;
    delete baseRoute.query.category;
    const baseUrl = routeUrl(baseRoute);
    if (selection === 'all') return baseUrl;
    const separator = baseUrl.includes('?') ? '&' : '?';
    return `${baseUrl}${separator}${selection === 'uncategorized'
        ? 'category=uncategorized'
        : `category_id=${encodeURIComponent(selection)}`}`;
}

async function loadArticles(route, page = 1, activeArticleId = null, append = false) {
    const context = routeContext(route);
    const options = { ...context, returnTo: routeUrl(route), activeArticleId };
    const generation = append ? app.articleListGeneration : app.articleListGeneration + 1;
    if (!append) {
        app.articleListGeneration = generation;
        app.articleListRouteUrl = null;
        app.articlesView.renderLoading(options);
    }
    try {
        const query = articleQuery(route, page);
        if (!append && route.name === 'recommendations') {
            const query = articleQuery(route, page);
            delete query.page;
            const response = await app.api.recommendations(query);
            if (generation !== app.articleListGeneration) return;
            app.articlesView.render({ data: dataOf(response) }, { ...options, page });
            app.articleListRouteUrl = routeUrl(route);
            return;
        }
        const response = route.name === 'search'
            ? await app.api.searchArticles(route.query.q || '', query)
            : await app.api.listArticles(query);
        if (generation !== app.articleListGeneration) return;
        if (append) {
            app.articlesView.append(response, { ...options, page });
        } else {
            app.articlesView.render(response, { ...options, page });
            app.articleListRouteUrl = routeUrl(route);
        }
    } catch (error) {
        if (generation !== app.articleListGeneration) return;
        if (append) {
            app.articlesView.renderMoreError();
        } else {
            app.articlesView.renderError(error, context);
        }
    }
}

function currentArticleListContext() {
    const route = app.route || parseRoute(window.location.hash);
    if (route.name === 'article') {
        return {
            route: parseRoute(route.query.from || '#/'),
            activeArticleId: route.params.id,
        };
    }
    return { route, activeArticleId: null };
}

function loadMoreArticles(page) {
    const context = currentArticleListContext();
    return loadArticles(context.route, page, context.activeArticleId, true);
}

function retryArticleList() {
    const context = currentArticleListContext();
    return loadArticles(context.route, 1, context.activeArticleId);
}

async function loadArticle(id, markRead = false) {
    app.readerView.renderLoading();
    try {
        const response = await app.api.getArticle(id);
        let article = dataOf(response);
        if (markRead && article.is_read !== true) {
            const updated = await app.api.updateArticle(id, { is_read: true });
            article = dataOf(updated);
            app.articleListRouteUrl = null;
            await loadShell();
        }
        app.readerView.render(article);
        app.articlesView.updateArticle(article);
        revealArticleFeedGroup(article.feed_id);
    } catch (error) {
        app.readerView.renderError(error);
    }
}

async function renderReading(route, options = {}) {
    setVisible(dom.utility, false);
    setVisible(dom.reading, true);
    dom.reading.classList.toggle('reading-article-active', route.name === 'article');
    if (dom.searchInput) {
        dom.searchInput.value = route.name === 'search' ? (route.query.q || '') : '';
    }
    if (!app.articlesView) {
        app.articlesView = new ArticlesView({
            list: dom.articleList,
            status: dom.articleStatus,
            loadMore: dom.articleLoadMore,
            scrollContainer: dom.articleListScroll,
            title: dom.articleTitle,
            kicker: dom.articleKicker,
            clearSearchButton: dom.clearSearch,
            onToggleFavorite: toggleFavorite,
            onLoadMore: loadMoreArticles,
            onRetry: retryArticleList,
            onAddFeed: () => openAddFeed(),
            onClearSearch: () => { window.location.hash = '#/'; },
            onDeleteFeed: (id) => confirmDeleteFeedFromArticles(id),
        });
    }
    if (!app.readerView) {
        app.readerView = new ReaderView({
            pane: dom.readerPane,
            content: dom.readerContent,
            placeholder: dom.readerPlaceholder,
            onBack: () => window.history.back(),
            onToggleFavorite: toggleFavorite,
            onToggleRead: toggleRead,
        });
    }
    renderNavigation();
    if (route.name === 'article') {
        const listRoute = parseRoute(route.query.from || '#/');
        if (app.articleListRouteUrl === routeUrl(listRoute)) {
            app.articlesView.setActiveArticle(route.params.id);
        } else {
            await loadArticles(listRoute, 1, route.params.id);
        }
        await loadArticle(route.params.id, options.markArticleRead === true);
        return;
    }
    app.readerView.renderPlaceholder();
    if (app.articleListRouteUrl === routeUrl(route)) {
        app.articlesView.setActiveArticle(null);
        return;
    }
    await loadArticles(route);
}

async function toggleArticle(id, changes) {
    try {
        const response = await app.api.updateArticle(id, changes);
        const article = dataOf(response);
        await loadShell();
        app.articleListRouteUrl = null;
        if (app.route?.name === 'article' && Number(app.route.params.id) === Number(id)) {
            app.readerView.render(article);
            app.articlesView.updateArticle(article);
            return;
        }
        await renderReading(app.route);
    } catch (error) {
        showToast(errorMessage(error), 'error');
    }
}

function toggleFavorite(id, value) {
    return toggleArticle(id, { is_favorite: value });
}

function toggleRead(id, value) {
    return toggleArticle(id, { is_read: value });
}

function confirmDeleteFeedFromArticles(feedId) {
    const feed = app.feeds.find((value) => Number(value.id) === Number(feedId));
    const name = feed?.name || 'ce flux';
    openConfirmDialog({
        title: 'Supprimer le flux',
        message: `Le flux « ${name} », ses articles et ses médias associés seront supprimés définitivement. Voulez-vous continuer ?`,
        confirmLabel: 'Supprimer le flux',
        tone: 'danger',
        onConfirm: async () => {
            await app.api.deleteFeed(feedId);
            await loadShell();
            window.location.hash = '#/';
        },
    });
}

function openAddFeed() {
    openAddFeedDialog({
        discover: (url) => app.api.discover(url),
        createFeed: (feed) => app.api.createFeed(feed),
        categories: app.categories,
        onCreated: async () => { await refreshCurrentView(); showToast('Flux ajouté.', 'success'); },
    });
}

async function refreshCurrentView() {
    await loadShell();
    if (app.route?.name === 'manage' && app.managementView) {
        app.managementView.render({ feeds: app.feeds, categories: app.categories });
        return;
    }
    if (app.route && app.user) {
        app.articleListRouteUrl = null;
        await renderReading(app.route);
    }
}

function openManagement() {
    setVisible(dom.reading, false);
    setVisible(dom.utility, true);
    app.managementView = new ManagementView(dom.utilityContent, {
        onAddFeed: openAddFeed,
        onRefreshAll: async () => { await app.api.refreshAllFeeds(); await refreshCurrentView(); },
        onRefreshFeed: async (id) => { await app.api.refreshFeed(id); await refreshCurrentView(); },
        onToggleFeed: async (id, active) => { await app.api.updateFeed(id, { is_active: active }); await refreshCurrentView(); },
        onEditFeed: (feed) => openFeedEditorDialog({ feed, categories: app.categories, onSave: async (changes) => { await app.api.updateFeed(feed.id, changes); await refreshCurrentView(); } }),
        onDeleteFeed: async (feed) => { await app.api.deleteFeed(feed.id); await refreshCurrentView(); },
        onAddCategory: () => openCategoryDialog({ onSave: async (category) => { await app.api.createCategory(category); await refreshCurrentView(); } }),
        onEditCategory: (category) => openCategoryDialog({ category, onSave: async (changes) => { await app.api.updateCategory(category.id, changes); await refreshCurrentView(); } }),
        onDeleteCategory: async (category) => { await app.api.deleteCategory(category.id); await refreshCurrentView(); },
    });
    app.managementView.render({ feeds: app.feeds, categories: app.categories });
}

async function openSettings() {
    setVisible(dom.reading, false);
    setVisible(dom.utility, true);
    app.settingsView = new SettingsView(dom.utilityContent, {
        user: app.user,
        onChangePassword: (value) => app.api.changePassword(value),
        onImportOpml: async (file) => { const result = await app.api.importOpml(file); await loadShell(); return result; },
        onRefreshFeeds: async () => {
            const result = await app.api.refreshAllFeeds();
            await loadShell();
            const payload = dataOf(result);
            return { data: { ...payload, feeds: app.feeds } };
        },
        onApplyTheme: async (theme) => {
            const response = await app.api.updateSettings({ theme });
            await applyTheme(dataOf(response).theme || theme);
            return response;
        },
    });
    app.settingsView.render({ user: app.user, feeds: app.feeds });
}

async function navigate() {
    if (!app.user || app.loadingRoute) return;
    app.loadingRoute = true;
    const previousRoute = app.route;
    app.route = parseRoute(window.location.hash);
    closeNavigation();
    renderNavigation();
    try {
        if (app.route.name === 'manage') openManagement();
        else if (app.route.name === 'settings') await openSettings();
        else await renderReading(app.route, {
            markArticleRead: app.route.name === 'article' && previousRoute !== null,
        });
        focusRoute(app.route, previousRoute);
    } catch (error) {
        if (error instanceof NetworkError) showOffline(error);
        else showToast(errorMessage(error), 'error');
    } finally {
        app.loadingRoute = false;
    }
}

function focusRoute(route, previousRoute = null) {
    let target = null;
    if (route.name === 'article') {
        target = dom.readerPane?.querySelector('#reader-article-title, #reader-title') || null;
    } else if (route.name === 'manage' || route.name === 'settings') {
        target = dom.utilityContent?.querySelector('h1') || null;
    } else if (previousRoute?.name === 'article') {
        target = dom.articleList?.querySelector(
            `[data-article-id="${Number(previousRoute.params.id)}"] .article-card-link`,
        ) || null;
    }
    target ||= dom.articleTitle || dom.main;
    if (!target) return;
    if (target.tabIndex < 0 && !target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
    }
    try {
        target.focus({ preventScroll: true });
    } catch {
        target.focus();
    }
    const pageName = route.name === 'article'
        ? (target.textContent.trim() || 'Article')
        : route.name === 'manage'
            ? 'Gérer les flux'
            : route.name === 'settings'
                ? 'Paramètres'
                : (dom.articleTitle?.textContent.trim() || 'Articles');
    document.title = `${pageName} - RSS Reader`;
}

function bindEvents() {
    window.addEventListener('hashchange', () => navigate());
    window.addEventListener('online', () => {
        if (app.user) {
            navigate();
            showToast('Connexion rétablie.', 'success');
        } else {
            start();
        }
    });
    dom.searchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const query = dom.searchInput.value.trim();
        window.location.hash = query === '' ? '#/' : `#/recherche?q=${encodeURIComponent(query)}`;
    });
    dom.clearSearch.addEventListener('click', () => { dom.searchInput.value = ''; window.location.hash = '#/'; });
    dom.articleCategoryFilter.addEventListener('change', () => {
        const route = currentArticleListContext().route;
        const nextUrl = categoryFilterUrl(route, dom.articleCategoryFilter.value);
        window.location.hash = nextUrl.slice(nextUrl.indexOf('#'));
    });
    [dom.logout, dom.sidebarLogout].forEach((button) => button?.addEventListener('click', logout));
    dom.refreshAll.addEventListener('click', async () => {
        try { await app.api.refreshAllFeeds(); await loadShell(); await navigate(); showToast('Flux actualisés.', 'success'); }
        catch (error) { showToast(errorMessage(error), 'error'); }
    });
    dom.addCategory.addEventListener('click', () => openCategoryDialog({ onSave: async (value) => { await app.api.createCategory(value); await refreshCurrentView(); } }));
    dom.offlineRetry.addEventListener('click', start);
    dom.navigationToggle.addEventListener('click', () => {
        openNavigation();
    });
    [dom.navigationClose, dom.navigationBackdrop].forEach((element) => element?.addEventListener(
        'click',
        () => closeNavigation(true),
    ));
    document.addEventListener('keydown', handleNavigationKeydown);
    dom.sidebar.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (dom.appLayout.classList.contains('navigation-open')
            && target?.closest('a[href]')) {
            closeNavigation(true);
        }
    });
    window.addEventListener('resize', () => {
        if (dom.appLayout.classList.contains('navigation-open')
            && window.matchMedia
            && !window.matchMedia('(max-width: 70rem)').matches) {
            closeNavigation(false);
        }
    });
}

function setNavigationBackgroundInert(inert) {
    [dom.header, dom.main].forEach((element) => {
        if (!element) return;
        if ('inert' in element) element.inert = inert;
        else if (inert) element.setAttribute('inert', '');
        else element.removeAttribute('inert');
    });
}

function openNavigation() {
    dom.appLayout.classList.add('navigation-open');
    dom.navigationToggle.setAttribute('aria-expanded', 'true');
    setNavigationBackgroundInert(true);
    focusWithoutScroll(dom.navigationClose);
}

function closeNavigation(restoreFocus = false) {
    const wasOpen = dom.appLayout.classList.contains('navigation-open');
    dom.appLayout.classList.remove('navigation-open');
    dom.navigationToggle.setAttribute('aria-expanded', 'false');
    setNavigationBackgroundInert(false);
    if (restoreFocus && wasOpen) dom.navigationToggle.focus({ preventScroll: true });
}

function handleNavigationKeydown(event) {
    if (!dom.appLayout.classList.contains('navigation-open')) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        closeNavigation(true);
        return;
    }
    if (event.key !== 'Tab') return;
    const focusable = Array.from(dom.sidebar.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )).filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
    if (focusable.length === 0) {
        event.preventDefault();
        dom.navigationClose.focus();
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function focusWithoutScroll(element) {
    if (!element || typeof element.focus !== 'function') return;
    try {
        element.focus({ preventScroll: true });
    } catch {
        element.focus();
    }
}

async function start() {
    showStartup(true);
    try {
        const response = await app.api.me();
        app.user = dataOf(response);
        await Promise.all([loadShell(), loadTheme()]);
        showApp();
        await navigate();
    } catch (error) {
        if (isAuthenticatedError(error)) showLogin();
        else showOffline(error);
    } finally {
        showStartup(false);
    }
}

function collectDom() {
    dom.startup = byId('startup-view');
    dom.header = document.querySelector('.app-header');
    dom.login = byId('login-view');
    dom.offline = byId('offline-view');
    dom.app = byId('app-view');
    dom.reading = byId('reading-layout');
    dom.utility = byId('utility-pane');
    dom.utilityContent = byId('utility-content');
    dom.main = byId('contenu-principal');
    dom.mainNavigation = byId('main-navigation');
    dom.categoryNavigation = byId('category-navigation');
    dom.sidebar = byId('sidebar');
    dom.articleList = byId('article-list');
    dom.articleListScroll = byId('article-list-scroll');
    dom.articleStatus = byId('article-list-status');
    dom.articleLoadMore = byId('article-list-load-more');
    dom.articleTitle = byId('article-list-title');
    dom.articleKicker = byId('article-list-kicker');
    dom.articleCategoryFilter = byId('article-category-filter');
    dom.clearSearch = byId('clear-search-button');
    dom.readerPane = byId('reader-pane');
    dom.readerContent = byId('reader-content');
    dom.readerPlaceholder = byId('reader-placeholder');
    dom.searchForm = byId('search-form');
    dom.searchInput = byId('global-search-input');
    dom.headerUsername = byId('header-username');
    dom.managementLink = byId('management-navigation-link');
    dom.settingsLink = byId('settings-navigation-link');
    dom.headerSettingsLink = byId('header-settings-link');
    dom.logout = byId('logout-button');
    dom.sidebarLogout = byId('sidebar-logout-button');
    dom.refreshAll = byId('refresh-all-button');
    dom.addCategory = byId('add-category-button');
    dom.offlineRetry = byId('offline-retry');
    dom.navigationToggle = byId('navigation-toggle');
    dom.navigationClose = byId('navigation-close');
    dom.navigationBackdrop = byId('navigation-backdrop');
    dom.appLayout = byId('app-layout');
}

collectDom();
app.api = createApiClient({ onAuthExpired: () => showLogin('Votre session a expiré.') });
bindEvents();
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/service-worker.js').catch(() => {});
start();
