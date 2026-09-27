import assert from 'assert';
import { DEFAULT_ROUTE, buildRoute, parseRoute } from '../../public/assets/js/router.js';
import { test } from './harness.mjs';

test('le parseur reconnaît toutes les routes hash', () => {
    assert.deepStrictEqual(parseRoute('#/'), DEFAULT_ROUTE);
    assert.deepStrictEqual(parseRoute('#/tous'), {
        name: 'home', params: {}, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/non-lus'), {
        name: 'unread', params: {}, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/lus'), {
        name: 'read', params: {}, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/favoris'), {
        name: 'favorites', params: {}, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/categories/12'), {
        name: 'category', params: { id: 12 }, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/sans-categorie'), {
        name: 'uncategorized', params: {}, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/flux/7'), {
        name: 'feed', params: { id: 7 }, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/recherche?q=sqlite%20FTS'), {
        name: 'search', params: {}, query: { q: 'sqlite FTS' },
    });
    assert.deepStrictEqual(parseRoute('#/articles/9?from=non-lus'), {
        name: 'article', params: { id: 9 }, query: { from: 'non-lus' },
    });
    assert.deepStrictEqual(parseRoute('#/gestion'), {
        name: 'manage', params: {}, query: {},
    });
    assert.deepStrictEqual(parseRoute('#/parametres'), {
        name: 'settings', params: {}, query: {},
    });
});

test('les paramètres query inconnus sont ignorés', () => {
    assert.deepStrictEqual(parseRoute('#/recherche?q=php&autre=ignoré'), {
        name: 'search', params: {}, query: { q: 'php' },
    });
    assert.deepStrictEqual(parseRoute('#/articles/5?from=favoris&q=ignoré'), {
        name: 'article', params: { id: 5 }, query: { from: 'favoris' },
    });
    assert.deepStrictEqual(parseRoute('#/lus?from=favoris'), {
        name: 'read', params: {}, query: {},
    });
});

test('les routes de liste conservent le filtre de catégorie explicite', () => {
    assert.deepStrictEqual(parseRoute('#/non-lus?category_id=12'), {
        name: 'unread', params: {}, query: { category_id: '12' },
    });
    assert.deepStrictEqual(parseRoute('#/recherche?q=sqlite&category=uncategorized'), {
        name: 'search', params: {}, query: { q: 'sqlite', category: 'uncategorized' },
    });
    assert.deepStrictEqual(parseRoute('#/flux/7?category_id=12'), {
        name: 'feed', params: { id: 7 }, query: { category_id: '12' },
    });
    assert.deepStrictEqual(parseRoute('#/lus?category_id=0&category=invalid'), {
        name: 'read', params: {}, query: {},
    });
});

test('les identifiants invalides retombent sur la route liste sûre', () => {
    const invalidIds = ['abc', '0', '-1', '01', '1.5', '9007199254740992'];

    for (const id of invalidIds) {
        assert.deepStrictEqual(parseRoute(`#/categories/${id}`), DEFAULT_ROUTE);
        assert.deepStrictEqual(parseRoute(`#/flux/${id}`), DEFAULT_ROUTE);
        assert.deepStrictEqual(parseRoute(`#/articles/${id}`), DEFAULT_ROUTE);
        assert.strictEqual(buildRoute('category', { id }), '/#/');
        assert.strictEqual(buildRoute('feed', { id }), '/#/');
        assert.strictEqual(buildRoute('article', { id }), '/#/');
    }
});

test('le constructeur hash construit les routes et query connues', () => {
    assert.strictEqual(buildRoute(), '/#/tous');
    assert.strictEqual(buildRoute('all'), '/#/tous');
    assert.strictEqual(buildRoute('unread'), '/#/non-lus');
    assert.strictEqual(buildRoute('read'), '/#/lus');
    assert.strictEqual(buildRoute('favorites'), '/#/favoris');
    assert.strictEqual(buildRoute('category', { id: 12 }), '/#/categories/12');
    assert.strictEqual(buildRoute('uncategorized'), '/#/sans-categorie');
    assert.strictEqual(buildRoute('feed', { id: 7 }), '/#/flux/7');
    assert.strictEqual(buildRoute('search', { q: 'sqlite FTS' }), '/#/recherche?q=sqlite+FTS');
    assert.strictEqual(
        buildRoute('article', { id: 9, from: 'non-lus' }),
        '/#/articles/9?from=non-lus',
    );
    assert.strictEqual(buildRoute('manage'), '/#/gestion');
    assert.strictEqual(buildRoute('settings'), '/#/parametres');
});

test('Recommandé est la page par défaut', () => {
    const expected = { name: 'recommendations', params: {}, query: {} };
    assert.deepStrictEqual(DEFAULT_ROUTE, expected);
    assert.deepStrictEqual(parseRoute(''), expected);
    assert.deepStrictEqual(parseRoute('#/'), expected);
    assert.deepStrictEqual(parseRoute('#/inconnu'), expected);
    assert.strictEqual(buildRoute(DEFAULT_ROUTE.name), '/#/recommandations');
});

test('les routes construites sont réanalysables', () => {
    const routes = [
        buildRoute(),
        buildRoute('unread'),
        buildRoute('read'),
        buildRoute('favorites'),
        buildRoute('category', { id: 12 }),
        buildRoute('uncategorized'),
        buildRoute('feed', { id: 7 }),
        buildRoute('search', { q: 'sqlite FTS' }),
        buildRoute('article', { id: 9, from: 'non-lus' }),
        buildRoute('manage'),
        buildRoute('settings'),
    ];

    for (const route of routes) {
        assert.deepStrictEqual(parseRoute(route), parseRoute(route.slice(1)));
    }
});
