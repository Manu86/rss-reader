import assert from 'assert';
import { selectArticleContent } from '../../public/assets/js/views/reader.js';
import { test } from './harness.mjs';

test('le lecteur remplace un contenu absent ou très court par le résumé', () => {
    const summary = '<p>Résumé suffisamment informatif fourni par le flux.</p>';

    assert.strictEqual(selectArticleContent({ content: null, summary }), summary);
    assert.strictEqual(selectArticleContent({ content: '<p>Lire la suite.</p>', summary }), summary);
});

test('le lecteur précède un contenu substantiel par son résumé sans le dupliquer', () => {
    const substantialContent = `<p>${'a'.repeat(200)}</p>`;
    const summary = '<p>Introduction de l’article.</p>';
    const shortContent = '<p>Texte court sans résumé.</p>';

    assert.strictEqual(
        selectArticleContent({ content: substantialContent, summary }),
        `${summary}\n${substantialContent}`,
    );
    assert.strictEqual(
        selectArticleContent({
            content: `<p>Introduction de l’article.</p>${substantialContent}`,
            summary,
        }),
        `<p>Introduction de l’article.</p>${substantialContent}`,
    );
    assert.strictEqual(selectArticleContent({ content: shortContent, summary: null }), shortContent);
});
