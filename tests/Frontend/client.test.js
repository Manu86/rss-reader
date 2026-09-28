import assert from 'assert';
import {
    ApiClient,
    ApiError,
    NetworkError,
    createApiClient,
} from '../../public/assets/js/api/client.js';
import { test } from './harness.mjs';

class TestFormData {
    constructor() {
        this.entries = [];
    }

    append(name, value, filename) {
        this.entries.push({ name, value, filename });
    }
}

globalThis.FormData = TestFormData;

function response(status, payload, text = null) {
    return {
        status,
        async text() {
            if (text !== null) {
                if (text instanceof Error) {
                    throw text;
                }
                return text;
            }
            if (payload === undefined) {
                throw new Error('Le corps ne doit pas être lu.');
            }
            return JSON.stringify(payload);
        },
    };
}

function errorResponse(status, code, message, fields = {}) {
    return response(status, { error: { code, message, fields } });
}

test('le client envoie du JSON avec session, no-store et CSRF', async () => {
    const calls = [];
    const fetch = async (url, init) => {
        calls.push({ url, init });
        return response(201, { data: { id: 4, name: 'PHP' } });
    };
    const client = new ApiClient({ fetch });
    client.setCsrfToken('jeton-1');

    const result = await client.createCategory('PHP');

    assert.deepStrictEqual(result, { data: { id: 4, name: 'PHP' } });
    assert.strictEqual(calls.length, 1);
    assert.strictEqual(calls[0].url, '/api/categories');
    assert.strictEqual(calls[0].init.method, 'POST');
    assert.strictEqual(calls[0].init.credentials, 'same-origin');
    assert.strictEqual(calls[0].init.cache, 'no-store');
    assert.strictEqual(calls[0].init.headers.accept, 'application/json');
    assert.strictEqual(calls[0].init.headers['content-type'], 'application/json; charset=utf-8');
    assert.strictEqual(calls[0].init.headers['x-csrf-token'], 'jeton-1');
    assert.strictEqual(calls[0].init.body, '{"name":"PHP"}');
});

test('le client charge le CSRF en mémoire avant une écriture', async () => {
    const calls = [];
    const fetch = async (url, init) => {
        calls.push({ url, init });
        if (url === '/api/auth/csrf') {
            return response(200, { data: { csrf_token: 'jeton-memoire' } });
        }
        return response(200, { data: { ok: true } });
    };
    const client = createApiClient(fetch);

    await client.request('/test', { method: 'PUT', body: { value: 1 } });

    assert.deepStrictEqual(calls.map((call) => call.url), ['/api/auth/csrf', '/api/test']);
    assert.strictEqual(calls[0].init.headers['x-csrf-token'], undefined);
    assert.strictEqual(calls[1].init.headers['x-csrf-token'], 'jeton-memoire');
    assert.strictEqual(client.csrfToken, 'jeton-memoire');
});

test('le client actualise le jeton retourné par la connexion', async () => {
    const fetch = async (url) => {
        if (url === '/api/auth/csrf') {
            return response(200, { data: { csrf_token: 'avant' } });
        }
        return response(200, {
            data: { user: { id: 1 }, csrf_token: 'apres-connexion' },
        });
    };
    const client = new ApiClient({ fetch });

    const result = await client.login('alice', 'secret');

    assert.strictEqual(result.data.user.id, 1);
    assert.strictEqual(client.csrfToken, 'apres-connexion');
});

test('un 403 INVALID_CSRF_TOKEN est retenté une seule fois', async () => {
    const calls = [];
    const responses = [
        errorResponse(403, 'INVALID_CSRF_TOKEN', 'Jeton invalide.'),
        response(200, { data: { csrf_token: 'jeton-2' } }),
        response(204),
    ];
    const fetch = async (url, init) => {
        calls.push({ url, init });
        return responses.shift();
    };
    const client = new ApiClient({ fetch });
    client.setCsrfToken('jeton-1');

    const result = await client.deleteCategory(8);

    assert.strictEqual(result, null);
    assert.deepStrictEqual(calls.map((call) => call.url), [
        '/api/categories/8',
        '/api/auth/csrf',
        '/api/categories/8',
    ]);
    assert.strictEqual(calls[0].init.headers['x-csrf-token'], 'jeton-1');
    assert.strictEqual(calls[2].init.headers['x-csrf-token'], 'jeton-2');
});

test('un second 403 CSRF ne déclenche pas un second retry', async () => {
    let calls = 0;
    const fetch = async () => {
        calls += 1;
        if (calls === 1 || calls === 3) {
            return errorResponse(403, 'INVALID_CSRF_TOKEN', 'Jeton invalide.');
        }
        return response(200, { data: { csrf_token: 'jeton-2' } });
    };
    const client = new ApiClient({ fetch });
    client.setCsrfToken('jeton-1');

    await assert.rejects(
        client.updateSettings({ articles_per_page: 25 }),
        (error) => error instanceof ApiError && error.code === 'INVALID_CSRF_TOKEN',
    );
    assert.strictEqual(calls, 3);
});

test('une réponse 204 est convertie en null sans lecture de corps', async () => {
    const client = new ApiClient({
        fetch: async () => response(204),
    });
    client.setCsrfToken('jeton');

    assert.strictEqual(await client.deleteFeed(3), null);
});

test('une erreur API conserve status, code, message et fields', async () => {
    const client = new ApiClient({
        fetch: async () => errorResponse(
            422,
            'VALIDATION_ERROR',
            'Données invalides.',
            { name: 'Nom invalide.' },
        ),
    });

    await assert.rejects(
        client.createCategory(''),
        (error) => {
            assert.ok(error instanceof ApiError);
            assert.strictEqual(error.status, 422);
            assert.strictEqual(error.statusCode, 422);
            assert.strictEqual(error.code, 'VALIDATION_ERROR');
            assert.strictEqual(error.message, 'Données invalides.');
            assert.deepStrictEqual(error.fields, { name: 'Nom invalide.' });
            return true;
        },
    );
});

test('un rejet fetch devient une NetworkError avec sa cause', async () => {
    const cause = new TypeError('offline');
    const client = new ApiClient({ fetch: async () => { throw cause; } });

    await assert.rejects(
        client.listArticles(),
        (error) => {
            assert.ok(error instanceof NetworkError);
            assert.strictEqual(error.code, 'NETWORK_ERROR');
            assert.strictEqual(error.cause, cause);
            return true;
        },
    );
});

test('FormData est envoyé sans Content-Type manuel', async () => {
    let captured = null;
    const client = new ApiClient({
        fetch: async (url, init) => {
            captured = { url, init };
            return response(200, { data: { imported: 1 } });
        },
    });
    client.setCsrfToken('jeton');
    const file = { name: 'abonnements.opml' };

    const result = await client.importOpml(file);

    assert.deepStrictEqual(result, { data: { imported: 1 } });
    assert.ok(captured.init.body instanceof TestFormData);
    assert.deepStrictEqual(captured.init.body.entries, [{
        name: 'file',
        value: file,
        filename: 'abonnements.opml',
    }]);
    assert.strictEqual(captured.init.headers['content-type'], undefined);
    assert.strictEqual(captured.init.headers['x-csrf-token'], 'jeton');
});

test('une authentification expirée déclenche le callback une fois', async () => {
    let expired = 0;
    const client = new ApiClient({
        fetch: async () => errorResponse(401, 'AUTHENTICATION_REQUIRED', 'Session expirée.'),
        onAuthExpired: (error) => {
            expired += 1;
            assert.strictEqual(error.code, 'AUTHENTICATION_REQUIRED');
        },
    });
    client.setCsrfToken('jeton');

    await assert.rejects(client.me(), ApiError);
    assert.strictEqual(expired, 1);
    assert.strictEqual(client.csrfToken, null);
});

test('un échec de connexion ne signale pas une session expirée', async () => {
    let expired = 0;
    const client = new ApiClient({
        fetch: async () => errorResponse(401, 'AUTHENTICATION_FAILED', 'Identifiants invalides.'),
        onAuthExpired: () => { expired += 1; },
    });
    client.setCsrfToken('jeton');

    await assert.rejects(client.login('alice', 'mauvais'), ApiError);
    assert.strictEqual(expired, 0);
});

test('les méthodes de convenience utilisent les routes API documentées', async () => {
    const calls = [];
    const fetch = async (url, init) => {
        calls.push({ url, init });
        if (init.method === 'DELETE') {
            return response(204);
        }
        if (url === '/api/opml/export') {
            return response(200, null, '<opml />');
        }
        return response(200, { data: [] });
    };
    const client = new ApiClient({ fetch });
    client.setCsrfToken('jeton');

    await client.getCsrf({ useCached: true });
    await client.listCategories();
    await client.updateCategory(2, 'PHP');
    await client.discover('https://example.test');
    await client.listFeeds({ active: true, category_id: 2 });
    await client.getFeed(3);
    await client.createFeed({ feed_url: 'https://example.test/feed' });
    await client.updateFeed(3, { is_active: false });
    await client.deleteFeed(3);
    await client.refreshFeed(3);
    await client.refreshAllFeeds();
    await client.listArticles({ filter: 'unread', page: 2 });
    await client.getArticle(4);
    await client.updateArticle(4, { is_favorite: true });
    await client.searchArticles('sqlite', { per_page: 10 });
    await client.getCounts();
    await client.getSettings();
    await client.updateSettings({ articles_per_page: 50 });
    await client.updateProfile({ email: 'alice@example.org', recommendation_email_frequency: 'daily' });
    await client.changePassword('ancien', 'nouveau-long');
    await client.importOpml(new TestFormData());
    const exported = await client.exportOpml();

    assert.strictEqual(exported, '<opml />');
    assert.deepStrictEqual(calls.map((call) => call.url), [
        '/api/categories',
        '/api/categories/2',
        '/api/feed-discovery',
        '/api/feeds?active=true&category_id=2',
        '/api/feeds/3',
        '/api/feeds',
        '/api/feeds/3',
        '/api/feeds/3',
        '/api/feeds/3/refresh',
        '/api/feeds/refresh',
        '/api/articles?filter=unread&page=2',
        '/api/articles/4',
        '/api/articles/4',
        '/api/search?per_page=10&q=sqlite',
        '/api/counts',
        '/api/settings',
        '/api/settings',
        '/api/settings/profile',
        '/api/settings/password',
        '/api/opml/import',
        '/api/opml/export',
    ]);
});

test('la liste et la recherche transmettent les filtres de lecture au serveur', async () => {
    const calls = [];
    const client = new ApiClient({
        fetch: async (url) => {
            calls.push(url);
            return response(200, { data: [] });
        },
    });

    await client.listArticles({
        filter: 'unread',
        category_id: 4,
        feed_id: 9,
        page: 2,
        per_page: 50,
    });
    await client.searchArticles('rss reader', {
        filter: 'favorites',
        category: 'uncategorized',
        page: 3,
        per_page: 10,
    });

    assert.strictEqual(
        calls[0],
        '/api/articles?filter=unread&category_id=4&feed_id=9&page=2&per_page=50',
    );
    assert.strictEqual(
        calls[1],
        '/api/search?filter=favorites&category=uncategorized&page=3&per_page=10&q=rss+reader',
    );
});

test('les mutations d’article utilisent les deux états documentés', async () => {
    const calls = [];
    const client = new ApiClient({
        fetch: async (url, init) => {
            calls.push({ url, init });
            return response(200, { data: { id: 12 } });
        },
    });
    client.setCsrfToken('jeton');

    await client.updateArticle(12, { is_read: true });
    await client.updateArticle(12, { is_favorite: false });

    assert.deepStrictEqual(calls.map((call) => call.url), [
        '/api/articles/12',
        '/api/articles/12',
    ]);
    assert.strictEqual(calls[0].init.body, '{"is_read":true}');
    assert.strictEqual(calls[1].init.body, '{"is_favorite":false}');
});

test('les mutations de flux et de catégories utilisent les payloads documentés', async () => {
    const calls = [];
    const client = new ApiClient({
        fetch: async (url, init) => {
            calls.push({ url, init });
            return response(init.method === 'DELETE' ? 204 : 200, { data: { id: 3 } });
        },
    });
    client.setCsrfToken('jeton');

    await client.createCategory({ name: 'Développement' });
    await client.updateCategory(3, { name: 'Backend' });
    await client.deleteCategory(3);
    await client.createFeed({ feed_url: 'https://example.test/feed.xml', category_id: 3 });
    await client.updateFeed(4, { name: 'Nouvelles', category_id: null, is_active: false });
    await client.refreshFeed(4);
    await client.refreshAllFeeds();
    await client.deleteFeed(4);

    assert.deepStrictEqual(calls.map((call) => call.url), [
        '/api/categories',
        '/api/categories/3',
        '/api/categories/3',
        '/api/feeds',
        '/api/feeds/4',
        '/api/feeds/4/refresh',
        '/api/feeds/refresh',
        '/api/feeds/4',
    ]);
    assert.strictEqual(calls[0].init.body, '{"name":"Développement"}');
    assert.strictEqual(calls[3].init.body, '{"feed_url":"https://example.test/feed.xml","category_id":3}');
    assert.strictEqual(calls[4].init.body, '{"name":"Nouvelles","category_id":null,"is_active":false}');
});

test('le profil, les paramètres, le mot de passe et OPML utilisent les contrats dédiés', async () => {
    const calls = [];
    const client = new ApiClient({
        fetch: async (url, init) => {
            calls.push({ url, init });
            if (url === '/api/opml/export') return response(200, null, '<opml />');
            return response(200, { data: { articles_per_page: 50, csrf_token: 'jeton-2' } });
        },
    });
    client.setCsrfToken('jeton');

    await client.getSettings();
    await client.updateSettings({ articles_per_page: 50 });
    await client.updateProfile({ email: 'alice@example.org', recommendation_email_frequency: 'weekly' });
    await client.changePassword({
        current_password: 'ancien-mot-de-passe',
        new_password: 'nouveau-mot-de-passe',
    });
    await client.importOpml({ name: 'subscriptions.opml' });
    assert.strictEqual(await client.exportOpml(), '<opml />');

    assert.deepStrictEqual(calls.map((call) => call.url), [
        '/api/settings',
        '/api/settings',
        '/api/settings/profile',
        '/api/settings/password',
        '/api/opml/import',
        '/api/opml/export',
    ]);
    assert.strictEqual(calls[1].init.body, '{"articles_per_page":50}');
    assert.strictEqual(
        calls[2].init.body,
        '{"email":"alice@example.org","recommendation_email_frequency":"weekly"}',
    );
    assert.strictEqual(
        calls[3].init.body,
        '{"current_password":"ancien-mot-de-passe","new_password":"nouveau-mot-de-passe"}',
    );
    assert.ok(calls[4].init.body instanceof TestFormData);
    assert.strictEqual(calls[5].init.headers.accept, 'application/xml');
    assert.strictEqual(client.csrfToken, 'jeton-2');
});
