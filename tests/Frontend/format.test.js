import assert from 'assert';
import {
    formatDate,
    formatDateTime,
    formatFeedStatus,
    formatItemCountLabel,
    formatNumber,
    formatPageLabel,
    formatPaginationLabel,
    formatPaginationLabels,
} from '../../public/assets/js/utils/format.js';
import { test } from './harness.mjs';

test('les dates sont françaises, inconnues ou rendues explicitement en UTC', () => {
    process.env.TZ = 'America/New_York';
    const date = '2026-09-25T23:30:00Z';

    assert.match(formatDate(date), /25/);
    assert.match(formatDate(date), /2026/);
    assert.strictEqual(formatDateTime(date).endsWith('à 23:30 UTC'), true);
    assert.strictEqual(formatDate(null), 'Date inconnue');
    assert.strictEqual(formatDate('date-invalide'), 'Date inconnue');
});

test('les nombres utilisent le format français', () => {
    assert.strictEqual(formatNumber(1234567).replace(/\s/g, ' '), '1 234 567');
    assert.strictEqual(formatNumber('42'), '42');
    assert.strictEqual(formatNumber(Number.NaN), '-');
    assert.strictEqual(formatNumber(null), '-');
});

test('les statuts de flux sont lisibles en français', () => {
    assert.strictEqual(formatFeedStatus(null), 'Jamais récupéré');
    assert.strictEqual(formatFeedStatus('success'), 'Récupération réussie');
    assert.strictEqual(formatFeedStatus('error'), 'Erreur de récupération');
    assert.strictEqual(formatFeedStatus({ is_active: false }), 'Désactivé');
    assert.strictEqual(formatFeedStatus('inconnu'), 'Statut inconnu');
});

test('les libellés de pagination couvrent nombres, pages et zéro', () => {
    assert.strictEqual(formatPageLabel(2, 5).replace(/\s/g, ' '), 'Page 2 sur 5');
    assert.strictEqual(formatPageLabel(1, 0), 'Aucune page');
    assert.strictEqual(formatItemCountLabel(0), '0 article');
    assert.strictEqual(formatItemCountLabel(1), '1 article');
    assert.strictEqual(formatItemCountLabel(12), '12 articles');
    assert.deepStrictEqual(
        formatPaginationLabels({ page: 2, total_pages: 5, total_items: 42 }),
        { page: 'Page 2 sur 5', items: '42 articles' },
    );
    assert.strictEqual(
        formatPaginationLabel({ page: 2, total_pages: 5, total_items: 42 }),
        'Page 2 sur 5 · 42 articles',
    );
});
