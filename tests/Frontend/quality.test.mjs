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
    assert.match(css, /\.article-source-name\s*\{[^}]*overflow:\s*hidden[^}]*text-overflow:\s*ellipsis[^}]*white-space:\s*nowrap/);
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
});

test('les interactions essentielles exposent un comportement clavier et des noms accessibles', () => {
    const html = read('index.html');
    const app = read('assets/js/app.js');
    const login = read('assets/js/views/login.js');
    assert.match(html, /id="navigation-toggle"[^>]*aria-controls="sidebar"[^>]*aria-expanded="false"/);
    assert.match(html, /<div id="navigation-backdrop"[^>]*aria-hidden="true"/);
    assert.match(html, /id="utility-pane"[^>]*aria-label="Outils"/);
    assert.doesNotMatch(html, /aria-labelledby="utility-title"/);
    assert.match(app, /event\.key === 'Escape'[\s\S]*closeNavigation\(true\)/);
    assert.match(app, /function focusRoute\(/);
    assert.match(app, /element\.inert = inert/);
    assert.match(app, /const navigationRoute = route\.name === 'article'[\s\S]*parseRoute\(route\.query\.from \|\| '#\/'\)/);
    assert.match(app, /navigationRoute\.name === name/);
    assert.match(app, /\['Tous', buildRoute\('home'\), 'home', countFor\('global', 'all'\)\]/);
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
    assert.match(app, /app\.readerView\.render\(article\);\s*app\.articlesView\.updateArticle\(article\);\s*return;/);
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
    assert.match(reader, /Agrandir l’image de l’article/);
    assert.match(reader, /variant: 'image'/);
    assert.ok(reader.includes('text.split(/\\n\\s*\\n/u)'));
    assert.match(reader, /paragraphs\[0\]\.length > 280/);
    assert.match(reader, /sentences\.slice\(index, index \+ 2\)/);
    assert.match(reader, /viewEl\('p', \{ text: paragraph\.trim\(\) \}\)/);
    assert.doesNotMatch(reader, /innerHTML|insertAdjacentHTML/);
    assert.doesNotMatch(css, /\.reader-article-content p\s*\{[^}]*white-space:\s*pre-wrap/);
});
