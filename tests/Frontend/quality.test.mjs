import assert from 'assert';
import { readFileSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { test } from './harness.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const publicRoot = join(root, 'public');

function read(path) {
    return readFileSync(join(publicRoot, path), 'utf8');
}

test('le CSS conserve les garanties responsive et de focus', () => {
    const css = read('assets/css/app.css');
    assert.match(css, /:focus-visible/);
    assert.match(css, /@media\s*\(/);
    assert.match(css, /@keyframes\s+spin/);
    assert.match(css, /touch-action|tap-highlight|\.button/);
    assert.doesNotMatch(css, /(^|[\n;}\s])\s*:focus-visible\s*\{[^}]*outline:\s*none/);
    assert.doesNotMatch(
        css,
        /(?:\.pane-header|\.management-view|\.settings-view) h1\[tabindex="-1"\]:focus-visible,[^.]*\{[^}]*outline:\s*(?!none)/,
    );
    assert.match(
        css,
        /(?:\.pane-header h1|\.management-view h1|\.settings-view h1)\[tabindex="-1"\]:focus[^}]*outline:\s*none/,
    );
    assert.match(css, /\.navigation-backdrop\s*\{[^}]*display:\s*none/);
    assert.match(css, /\.navigation-open \.navigation-backdrop\s*\{\s*display:\s*block/);
    assert.doesNotMatch(css, /\.navigation-sublist\s*\{[^}]*border-left/);
    assert.doesNotMatch(css, /\.navigation-sublist\s*\{[^}]*margin-left/);
    assert.doesNotMatch(css, /\.navigation-sublist \.navigation-link\s*\{[^}]*font-size/);
    assert.match(css, /\.article-card-topline, \.article-card-meta, \.navigation-feed-source\s*\{[^}]*gap:\s*\.4rem[^}]*font-size:\s*\.78rem/);
    assert.match(css, /\.navigation-favicon\s*\{\s*flex:\s*0 0 1\.25rem/);
    assert.match(css, /\.navigation-feed-link\s*\{[^}]*justify-content:\s*flex-start/);
    assert.match(css, /\.navigation-toggle\s*\{[^}]*width:\s*1\.85rem[^}]*height:\s*1\.85rem[^}]*border:\s*1px solid transparent/);
    assert.match(css, /\.navigation-toggle:hover:not\(:focus-visible\)\s*\{[^}]*border-color:\s*var\(--accent\)/);
    assert.match(css, /\.article-list-footer\s*\{[^}]*display:\s*flex[^}]*justify-content:\s*center/);
    assert.match(css, /\.article-list-footer-button\s*\{\s*max-width:\s*22rem/);
    assert.match(read('assets/js/app.js'), /function feedNavigationLink\(feed, current\)/);
    assert.match(read('assets/js/app.js'), /navigationFavicon\(feed\)/);
    assert.match(css, /\.sidebar\s*\{[^}]*position:\s*sticky[^}]*height:\s*calc\(100dvh - 4\.5rem\)[^}]*overflow-y:\s*auto[^}]*scrollbar-gutter:\s*stable/);
    assert.match(css, /@media\s*\(max-width:\s*70rem\)[\s\S]*\.sidebar\s*\{[^}]*position:\s*fixed[^}]*height:\s*100dvh[^}]*overflow-y:\s*auto/);
    assert.match(css, /\.article-card-content:has\(\.article-thumbnail\)\s*\{[^}]*grid-template-columns:\s*5\.5rem\s+minmax[^}]*grid-template-rows:\s*auto auto auto/);
    assert.match(css, /\.article-thumbnail\s*\{[^}]*display:\s*block[^}]*align-self:\s*start[^}]*width:\s*5\.5rem[^}]*height:\s*5\.5rem[^}]*object-fit:\s*cover/);
    assert.match(css, /\.article-thumbnail-placeholder\s*\{[^}]*place-items:\s*center[^}]*width:\s*100%[^}]*height:\s*100%/);
    assert.match(css, /\.article-card-content:has\(\.article-thumbnail\) \.article-card-topline\s*\{\s*grid-column:\s*1\s*\/\s*-1/);
    assert.match(css, /\.article-card-link:hover, \.article-card-link\[aria-current="true"\]\s*\{[^}]*background:\s*#f8fafc/);
    assert.match(css, /\.article-list-feed-actions\s*\{[^}]*display:\s*flex[^}]*margin-top:\s*\.65rem/);
    assert.match(css, /\.article-list-pane\s*\{[^}]*position:\s*sticky[^}]*height:\s*calc\(100dvh - 4\.5rem\)[^}]*overflow:\s*hidden/);
    assert.match(css, /\.article-list-scroll\s*\{[^}]*min-height:\s*0[^}]*overflow-y:\s*auto[^}]*scrollbar-gutter:\s*stable/);
    assert.match(css, /\.article-source-name, \.navigation-feed-name\s*\{[^}]*overflow:\s*hidden[^}]*text-overflow:\s*ellipsis[^}]*white-space:\s*nowrap/);
    assert.match(css, /\.article-favicon\s*\{[^}]*flex:\s*0 0 1\.25rem/);
    assert.match(css, /\.article-category-tag\s*\{[^}]*overflow:\s*hidden[^}]*text-overflow:\s*ellipsis[^}]*white-space:\s*nowrap/);
    assert.match(css, /\.infinite-scroll-sentinel\s*\{[^}]*display:\s*flex[^}]*min-height:\s*3\.5rem/);
    assert.doesNotMatch(css, /\.pagination(?:-|\s*\{)/);
    assert.doesNotMatch(css, /\.article-card--unread\s*\{[^}]*border-left/);
    assert.match(css, /\.reading-article-active \.article-list-pane\s*\{\s*display:\s*none/);
    assert.match(css, /\.reader-article-image\s*\{[^}]*width:\s*auto[^}]*height:\s*auto[^}]*object-fit:\s*contain/);
    assert.match(css, /\.reader-heading\s*\{[^}]*display:\s*grid[^}]*gap:\s*\.9rem/);
    assert.match(css, /\.article-card-title\s*\{[^}]*-webkit-line-clamp:\s*3/);
    assert.match(css, /html, body\s*\{[^}]*max-width:\s*100%[^}]*overflow-x:\s*clip/);
    assert.match(css, /\.global-search\s*\{[^}]*min-width:\s*0/);
    assert.match(css, /\.reader-article-content\s*\{[^}]*max-width:\s*100%[^}]*overflow-wrap:\s*anywhere/);
    assert.match(css, /\.reader-article-content table\s*\{[^}]*display:\s*block[^}]*max-width:\s*100%[^}]*overflow-x:\s*auto/);
    assert.match(css, /\.app-dialog\s*\{[^}]*max-width:\s*calc\(100vw - 2rem\)/);
    assert.match(css, /\.reader-actions\s*\{[^}]*gap:\s*\.4rem[^}]*flex-wrap:\s*nowrap/);
    assert.match(css, /\.reader-action-button\s*\{[^}]*flex-direction:\s*row[^}]*flex-wrap:\s*nowrap[^}]*direction:\s*ltr[^}]*min-height:\s*2\.15rem[^}]*font-size:\s*\.82rem/);
    assert.match(css, /\.reader-action-button \.icon\s*\{[^}]*order:\s*0/);
    assert.match(css, /\.reader-action-button \.button-label\s*\{[^}]*order:\s*1/);
    assert.match(css, /@media\s*\(max-width:\s*35rem\)[\s\S]*\.reader-action-button\s*\{[^}]*flex:\s*0 1 auto[^}]*font-size:\s*13px/);
    assert.match(css, /\.reader-action-button \.button-label\s*\{[^}]*white-space:\s*nowrap/);
    assert.match(css, /\.reader-action-button \.icon\s*\{[^}]*display:\s*block[^}]*width:\s*\.9em/);
});

test('le HTML associe les labels statiques à des contrôles existants', () => {
    const html = read('index.html');
    const ids = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map((match) => match[1]));
    const labels = [...html.matchAll(/<label[^>]+for="([^"]+)"/g)].map((match) => match[1]);
    labels.forEach((id) => assert.ok(ids.has(id), `Contrôle manquant pour le label ${id}`));
    assert.match(html, /<main\b[^>]+id="contenu-principal"/);
    assert.match(html, /<nav\b[^>]+aria-label=/);
    assert.match(html, /<nav id="primary-navigation"[^>]*>[\s\S]*?<h2>Articles<\/h2>/);
    assert.match(html, /<dialog\b/);
    assert.match(html, /id="management-navigation-link"/);
    assert.match(html, /id="management-navigation-link"[^>]+href="\/#\/gestion"[\s\S]*?<span>Voir les flux<\/span>/);
    assert.doesNotMatch(html, /id="feed-navigation"/);
    assert.doesNotMatch(read('assets/js/app.js'), /setChildren\(dom\.feedNavigation/);
    assert.match(html, /id="settings-navigation-link"/);
    assert.match(html, /<h1 id="article-list-title">[^<]+<\/h1>\s*<div id="article-list-feed-actions"/);
    assert.match(html, /<label for="article-category-filter">Catégorie<\/label>\s*<select id="article-category-filter"/);
    assert.match(html, /id="article-list-load-more"[^>]+role="status"[^>]+aria-live="polite"/);
    assert.match(html, /id="article-list-scroll"[^>]+role="region"[^>]+tabindex="0"/);
    assert.match(html, /id="article-list-scroll"[\s\S]*?<header class="pane-header">[\s\S]*?id="article-list"/);
    assert.doesNotMatch(html, /id="article-pagination"/);
    assert.match(html, /id="article-list-footer" class="article-list-footer" hidden/);
    assert.match(
        html,
        /<input id="login-remember" name="remember" type="checkbox">\s*<label for="login-remember">Se souvenir de moi<\/label>/,
    );
    assert.match(html, /id="login-password-toggle"[^>]+type="button"[^>]+aria-controls="login-password"/);
    assert.doesNotMatch(html, /id="login-remember"[^>]*checked/);
    const articlesView = read('assets/js/views/articles.js');
    assert.match(articlesView, /VIEW_LABELS\s*=\s*\{[^}]*recommendations:/);
    assert.match(articlesView, /context\.filter !== 'recommendations'/);
    assert.match(articlesView, /buildRoute\('unread'\)/);
});

test('le bloc de titre de la connexion respire verticalement', () => {
    const css = read('assets/css/app.css');
    const brand = read('index.html').match(/<div class="auth-brand">/);
    assert.ok(brand, 'le bloc de titre de la connexion est absent');
    assert.match(css, /\.auth-brand\s*\{[^}]*padding:\s*2\.25rem 1rem/);
    assert.match(css, /\.auth-brand\s*\{[^}]*background:\s*var\(--accent-soft\)/);
});

test('les interactions essentielles exposent un comportement clavier et des noms accessibles', () => {
    const html = read('index.html');
    const app = read('assets/js/app.js');
    const login = read('assets/js/views/login.js');
    assert.match(html, /id="navigation-toggle"[^>]*aria-controls="sidebar"[^>]*aria-expanded="false"/);
    assert.match(html, /<div id="navigation-backdrop"[^>]*aria-hidden="true"/);
    assert.match(html, /id="utility-pane"[^>]*aria-label="Outils"/);
    assert.doesNotMatch(html, /aria-labelledby="utility-title"/);
    assert.match(html, /id="add-category-button"[^>]*aria-label="Ajouter une catégorie"/);
    assert.match(html, /id="add-feed-button"[^>]*aria-label="Ajouter un flux"/);
    assert.match(html, /id="header-add-feed-button"[^>]*aria-label="Ajouter un flux"/);
    assert.doesNotMatch(html, /id="header-settings-link"/);
    assert.match(app, /dom\.addFeed\.addEventListener\('click', \(\) => openAddFeed\(\)\)/);
    assert.match(app, /dom\.headerAddFeed\.addEventListener\('click', \(\) => openAddFeed\(\)\)/);
    assert.doesNotMatch(app, /headerSettingsLink/);
    assert.match(app, /event\.key === 'Escape'[\s\S]*closeNavigation\(true\)/);
    assert.match(app, /function focusRoute\(/);
    assert.match(app, /element\.inert = inert/);
    assert.match(app, /const navigationRoute = route\.name === 'article'[\s\S]*parseRoute\(route\.query\.from \|\| '#\/'\)/);
    assert.match(app, /navigationRoute\.name === name/);
    assert.match(app, /\['Tous', buildRoute\('home'\), 'home', countFor\('global', 'all'\)\]/);
    // Ouvrir un article ne doit pas modifier l'état du menu : l'ancien
    // comportement repliait toutes les catégories pour n'ouvrir que celle
    // de l'article lu.
    assert.doesNotMatch(app, /revealArticleFeedGroup/);
    assert.doesNotMatch(app, /article\.feed_id/);
    assert.match(login, /setAttribute\('aria-describedby', 'login-error'\)/);
});

test('le frontend n’introduit pas de stockage persistant ou d’injection HTML brute', () => {
    const files = [
        'assets/js/app.js',
        'assets/js/api/client.js',
        'assets/js/components/dialog.js',
        'assets/js/components/feedback.js',
        'assets/js/utils/dom.js',
        'assets/js/views/articles.js',
        'assets/js/views/feed-dialogs.js',
        'assets/js/views/login.js',
        'assets/js/views/management.js',
        'assets/js/views/reader.js',
        'assets/js/views/settings.js',
    ];
    const source = files.map((file) => read(file)).join('\n');
    assert.doesNotMatch(source, /innerHTML|outerHTML|document\.write|eval\s*\(/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB/);
    const app = read('assets/js/app.js');
    assert.match(app, /app\.api\.updateArticle\(id, \{ is_read: true \}\)/);
    assert.match(app, /markArticleRead:\s*app\.route\.name === 'article' && previousRoute !== null/);
    assert.match(app, /renderReaderArticle\(article\);\s*app\.articlesView\.updateArticle\(article\);\s*return;/);
});

test('le lecteur ignore les réponses d’articles devenues obsolètes', () => {
    // Sans garde de génération, une réponse lente repeint le lecteur après une
    // autre navigation, et peut afficher l'article d'un compte précédent.
    const app = read('assets/js/app.js');
    const loadArticle = app.match(/async function loadArticle\([^)]*\) \{[\s\S]*?\n\}/);

    assert.ok(loadArticle, 'loadArticle introuvable');
    assert.match(app, /readerGeneration: 0,/);
    assert.match(loadArticle[0], /const generation = app\.readerGeneration \+ 1;\s*app\.readerGeneration = generation;/);
    const guards = loadArticle[0].match(/if \(generation !== app\.readerGeneration\) return;/g) || [];
    assert.equal(guards.length, 4, 'chaque await de loadArticle doit être suivi d’un contrôle de génération');
    assert.match(loadArticle[0], /renderReaderArticle\(article\);[\s\S]*?app\.readerView\.renderError\(error\);/);
    assert.match(app, /function invalidateReader\(\) \{[\s\S]*?app\.readerGeneration \+= 1;/);
    assert.match(app, /function showLogin\([^)]*\) \{[\s\S]*?invalidateReader\(\);/);
    assert.match(app, /invalidateReader\(\);\s*app\.readerView\.renderPlaceholder\(\);/);
    assert.match(app, /function openManagement\(\) \{\s*invalidateReader\(\);/);
    assert.match(app, /async function openSettings\(\) \{\s*invalidateReader\(\);/);
});

test('le panneau paramètres ne montre jamais les données d’un autre compte', () => {
    // Le panneau n'est visible qu'une fois son contenu remplacé, et la fin de
    // session le vide : sans cela, le compte suivant qui ouvre les paramètres
    // voit l'email, le nom et les sources du compte précédent.
    const app = read('assets/js/app.js');
    const settings = read('assets/js/views/settings.js');

    assert.match(
        app,
        /function resetUtilityPane\(\) \{[\s\S]*?app\.settingsView = null;[\s\S]*?app\.managementView = null;[\s\S]*?setVisible\(dom\.utility, false\);[\s\S]*?setChildren\(dom\.utilityContent, \[\]\)/,
    );
    assert.match(app, /function showLogin\([^)]*\) \{[\s\S]*?resetUtilityPane\(\);[\s\S]*?setVisible\(dom\.app, false\);/);
    assert.match(
        app,
        /app\.settingsView = view;\s*view\.renderLoading\(\);\s*setVisible\(dom\.reading, false\);\s*setVisible\(dom\.utility, true\);/,
    );
    assert.match(app, /if \(app\.settingsView !== view \|\| !app\.user\) return;/);
    assert.match(settings, /renderLoading\(\) \{[\s\S]*?spinnerBlock\('Chargement des paramètres…'\)/);
});

test('les paramètres exposent l’email et la fréquence des recommandations', () => {
    const settings = read('assets/js/views/settings.js');
    const app = read('assets/js/app.js');
    const client = read('assets/js/api/client.js');

    assert.match(settings, /field\('Adresse email', email/);
    assert.match(settings, /type:\s*'email'/);
    assert.match(settings, /autocomplete:\s*'email'/);
    assert.match(settings, /maxLength:\s*254/);
    assert.match(settings, /field\('Fréquence des recommandations', frequency/);
    for (const frequency of ['never', 'daily', 'weekly', 'monthly']) {
        assert.ok(settings.includes(`value: '${frequency}'`));
    }
    assert.match(settings, /email === '' && frequency !== 'never'/);
    assert.match(settings, /onUpdateProfile/);
    assert.match(app, /app\.api\.updateProfile\(profile\)/);
    assert.match(app, /dataOf\(await app\.api\.getSettings\(\)\)/);
    assert.match(client, /request\('\/settings\/profile', \{ method: 'PATCH', body: profile \}\)/);
});

test('les paramètres affichent la section apparence en premier', () => {
    const settings = read('assets/js/views/settings.js');
    const appearance = settings.indexOf('view.appendChild(this.renderAppearanceSection())');
    const profile = settings.indexOf('view.appendChild(this.renderProfileSection())');
    const refresh = settings.indexOf('view.appendChild(this.renderFeedRefreshSection())');
    const opml = settings.indexOf('view.appendChild(this.renderOpmlSection())');
    const password = settings.indexOf('view.appendChild(this.renderPasswordSection())');

    assert.ok(appearance >= 0);
    assert.ok(appearance < profile);
    assert.ok(profile < refresh);
    assert.ok(refresh < opml);
    assert.ok(opml < password);
});

test('les cartes de liste ne rendent pas les résumés d’article', () => {
    const articles = read('assets/js/views/articles.js');
    const cardSource = articles.slice(
        articles.indexOf('function createArticleCard'),
        articles.indexOf('function paginationData'),
    );
    assert.doesNotMatch(cardSource, /article-card-summary/);
    assert.doesNotMatch(cardSource, /articleSummary\(/);
    assert.doesNotMatch(cardSource, /article-read-state/);
    assert.doesNotMatch(cardSource, /Non lu|Lu/);
    assert.doesNotMatch(cardSource, /article-favorite|Ajouter aux favoris|Retirer des favoris/);
    assert.match(articles, /article-thumbnail-placeholder/);
    assert.match(articles, /isLocalMediaUrl\(faviconUrl\) \? \[createFavicon\(article\)\]/);
    assert.match(cardSource, /'aria-current':\s*'true'/);
    assert.match(articles, /requestOptions\.activeArticleId/);
    assert.match(articles, /new window\.IntersectionObserver/);
    assert.match(articles, /rootMargin:\s*'300px 0px'/);
    assert.match(articles, /root:\s*this\.scrollContainer/);
    assert.match(articles, /this\.callbacks\.onLoadMore\(this\.currentPage \+ 1\)/);
    assert.match(articles, /cards\.forEach\(\(card\) => this\.list\?\.appendChild\(card\)\)/);
    assert.match(articles, /setActiveArticle\(articleId\)/);
    assert.match(articles, /updateArticle\(article\)/);
    assert.doesNotMatch(articles, /Page précédente|Page suivante/);
    assert.match(cardSource, /className:\s*'article-category-tag'/);
    assert.match(cardSource, /categoryName\(article\)/);
    assert.match(articles, /className:\s*'button button-small article-list-delete-button'/);
    assert.doesNotMatch(articles, /button button-small button-danger[^\n]+Supprimer le flux/);
});

test('la liste d’articles d’un flux propose Modifier avant Supprimer', () => {
    const articles = read('assets/js/views/articles.js');
    const actions = articles.slice(
        articles.indexOf('_renderActions(context) {'),
        articles.indexOf('_clearStatus() {'),
    );

    assert.match(actions, /className:\s*'button button-small article-list-edit-button'/);
    assert.match(actions, /ariaLabel:\s*'Modifier le flux'/);
    assert.match(actions, /this\.callbacks\.onEditFeed\(context\.feedId\)/);
    assert.match(actions, /className:\s*'button button-small article-list-delete-button'/);
    assert.match(actions, /ariaLabel:\s*'Supprimer le flux'/);
    const edit = actions.indexOf("data-article-list-action': 'edit-feed'");
    const remove = actions.indexOf("data-article-list-action': 'delete-feed'");
    assert.ok(edit >= 0);
    assert.ok(remove >= 0);
    assert.ok(edit < remove);

    const app = read('assets/js/app.js');
    assert.match(app, /function editFeedFromArticles\(feedId\)/);
    assert.match(app, /openFeedEditorDialog\(\{\s*feed,\s*categories: app\.categories,/);
    assert.match(app, /onEditFeed:\s*\(id\) => editFeedFromArticles\(id\)/);
});

test('le lecteur structure le texte en paragraphes sans injecter de HTML', () => {
    const reader = read('assets/js/views/reader.js');
    const css = read('assets/css/app.css');
    assert.match(reader, /function renderArticleContent/);
    assert.match(reader, /className:\s*'article-category-tag reader-category-tag'/);
    assert.match(reader, /text:\s*categoryName\(article\)/);
    assert.match(reader, /new DOMParser\(\)/);
    assert.match(reader, /document\.importNode\(node, true\)/);
    assert.match(reader, /h\[1-4\]/);
    assert.match(reader, /img\|table\|thead\|tbody\|tfoot\|tr\|th\|td\|details\|summary/);
    assert.match(css, /\.reader-article-content img\s*\{[^}]*max-width:\s*100%[^}]*height:\s*auto/);
    assert.match(reader, /reader-image-trigger/);
    assert.match(reader, /reader-feed-favicon-visual/);
    assert.match(reader, /Agrandir l’image de la source/);
    assert.match(reader, /Agrandir l’image de l’article/);
    assert.match(reader, /variant: 'image'/);
    assert.ok(reader.includes('text.split(/\\n\\s*\\n/u)'));
    assert.match(reader, /paragraphs\[0\]\.length > 280/);
    assert.match(reader, /sentences\.slice\(index, index \+ 2\)/);
    assert.match(reader, /viewEl\('p', \{ text: paragraph\.trim\(\) \}\)/);
    assert.doesNotMatch(reader, /innerHTML|insertAdjacentHTML/);
    assert.doesNotMatch(css, /\.reader-article-content p\s*\{[^}]*white-space:\s*pre-wrap/);
});

test('la typographie reste identique dans le navigateur et dans la PWA installée', () => {
    const css = read('assets/css/app.css');
    assert.match(css, /font-family:\s*system-ui/);
    assert.doesNotMatch(css, /@import|@font-face|fonts\.(?:googleapis|gstatic)/);

    // Empêche le font boosting et l'inflation de texte en mode standalone.
    assert.match(css, /html\s*\{[^}]*-webkit-text-size-adjust:\s*100%[^}]*text-size-adjust:\s*100%/);

    // Les graisses non standard sont synthétisées par le navigateur et rendent
    // le texte trop fin sur les polices système.
    const weights = Array.from(css.matchAll(/font-weight:\s*([0-9]+)/g), (match) => Number(match[1]));
    assert.ok(weights.length > 0);
    for (const weight of weights) {
        assert.ok(weight % 100 === 0, `graisse non standard (synthétisée par le navigateur) : ${weight}`);
    }
});

test('les actions de l’onglet Flux partagent la ligne du nombre de flux, alignées à droite', () => {
    const css = read('assets/css/app.css');
    const management = read('assets/js/views/management.js');

    assert.match(css, /\.management-panel-toolbar\s*\{[^}]*display:\s*flex[^}]*justify-content:\s*space-between/);
    assert.match(css, /\.management-panel-actions\s*\{[^}]*margin-left:\s*auto/);
    assert.match(css, /\.management-panel-toolbar \.panel-summary\s*\{[^}]*margin:\s*0/);
    assert.doesNotMatch(css, /management-header-actions/);

    const toolbar = management.slice(
        management.indexOf('renderFeedsPanelToolbar()'),
        management.indexOf('renderFeedsPanel()', management.indexOf('renderFeedsPanelToolbar()')),
    );
    assert.match(toolbar, /className: 'management-panel-toolbar'/);
    assert.match(toolbar, /className: 'panel-summary'/);
    assert.match(toolbar, /renderHeaderActions\(\)/);

    const panel = management.slice(management.indexOf('renderFeedsPanel()'));
    assert.ok(panel.indexOf('renderFeedsPanelToolbar()') < panel.indexOf('stateBlock('));
    assert.doesNotMatch(management, /management-header-actions/);
    assert.match(management, /el\('details', \{ className: 'feed-accordion' \}\)/);
    assert.match(management, /el\('summary', \{ className: 'management-item-header' \}\)/);
    assert.match(management, /querySelectorAll\('\.feed-accordion\[open\]'\)/);
    assert.match(css, /\.feed-accordion > summary\s*\{[^}]*cursor:\s*pointer/);
});

test('le lecteur mobile propose un retour en bas d’article, comme en haut', () => {
    const css = read('assets/css/app.css');
    const reader = read('assets/js/views/reader.js');

    const footer = reader.slice(reader.indexOf("viewEl('div', { className: 'reader-footer' }"));
    assert.match(footer, /className: 'reader-footer'/);

    // Le pied de lecture est le dernier élément du corps de l'article.
    const body = reader.slice(reader.indexOf('_articleChildren(article,'));
    assert.match(reader, /viewButton\('Retour à la liste'/);
    assert.match(body, /renderBackButton\(this\.callbacks\.onBack, 'reader-back-button-bottom'\)/);
    assert.ok(
        body.indexOf("className: 'reader-footer'") > body.indexOf('renderShareButton(article)'),
        'le retour du pied doit suivre les actions de partage',
    );

    assert.match(css, /\.reader-footer\s*\{[^}]*display:\s*flex[^}]*margin-top/);
    assert.match(css, /@media\s*\(min-width:\s*70\.0625rem\)\s*\{\s*\.reader-back-button-bottom\s*\{\s*display:\s*none/);
});

test('le pied du lecteur propose les articles précédent et suivant lorsqu’ils existent', () => {
    const app = read('assets/js/app.js');
    const articles = read('assets/js/views/articles.js');
    const reader = read('assets/js/views/reader.js');
    const css = read('assets/css/app.css');

    assert.match(articles, /getAdjacentArticleIds\(articleIdValue\)/);
    assert.match(articles, /previousId:\s*index > 0 \? articleIds\[index - 1\] : null/);
    assert.match(articles, /nextId:\s*index \+ 1 < articleIds\.length \? articleIds\[index \+ 1\] : null/);
    assert.match(app, /buildRoute\('article', \{ articleId: id \}, \{ from: returnTo \}\)/);
    assert.match(app, /app\.readerView\.render\(article, articleNavigationOptions\(article\?\.id\)\)/);
    assert.match(reader, /previousHref !== null[\s\S]*text: 'Précédent'/);
    assert.match(reader, /nextHref !== null[\s\S]*text: 'Suivant'/);
    assert.match(reader, /aria-label': 'Navigation entre les articles'/);
    assert.match(css, /\.reader-next-button\s*\{[^}]*margin-left:\s*auto/);
});

test('la navigation place Recommandé en premier et Tous après Lus', () => {
    const app = read('assets/js/app.js');
    const main = app.match(/const main = \[([\s\S]*?)\n    \];/);
    assert.ok(main, 'la liste principale de navigation est introuvable');
    const entries = Array.from(main[1].matchAll(/'([^']+)', buildRoute\('([^']+)'\)/g))
        .map((match) => ({ label: match[1], route: match[2] }));
    assert.deepStrictEqual(entries, [
        { label: 'Recommandé', route: 'recommendations' },
        { label: 'Non lus', route: 'unread' },
        { label: 'Lus', route: 'read' },
        { label: 'Tous', route: 'home' },
        { label: 'Favoris', route: 'favorites' },
    ]);
});

test('le retour depuis le lecteur restaure la position de la liste', () => {
    const app = read('assets/js/app.js');
    const articles = read('assets/js/views/articles.js');

    // La position est mémorisée au moment du clic vers l'article, avec un
    // pivot : la liste est re-rendue au retour, il faut retrouver la carte.
    assert.match(app, /rememberArticleListScroll\(listRoute, route\.params\.id\)/);
    assert.match(app, /articleListScrollRestore: null/);
    assert.match(app, /app\.articleListScrollRestore = null;/);

    // La restauration applique la position mémorisée après re-rendu, en
    // rechargeant les pages nécessaires jusqu'au pivot, et seulement si la
    // route rendue est toujours celle de la capture.
    assert.match(articles, /_restoreScrollPosition\(requestOptions\)/);
    assert.match(articles, /restore\.key !== this\.returnTo/);
    assert.match(articles, /_loadPagesUntil\(pivotId\)/);
    assert.match(articles, /this\.pendingScrollRestore = null;/);
    assert.match(articles, /this\.scrollContainer\.scrollTo\(\{ top: target \}\)/);
    assert.match(articles, /articleCardElement\(pivotId\)/);
    assert.match(articles, /_scrollTargetFromPivot\(restore\)/);
    // Une nouvelle liste en cours de chargement annule la restauration.
    assert.match(articles, /renderLoading\(options = \{\}\) \{[\s\S]*?pendingScrollRestore = null/);
});
