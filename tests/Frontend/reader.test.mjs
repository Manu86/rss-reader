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

test('le lecteur propose un partage e-mail avec toutes les données de l’article', async () => {
    const reader = await import('../../public/assets/js/views/reader.js');

    const article = {
        title: '« On ne supporte plus les inégalités » : la fronde se poursuit',
        url: 'https://site.test/articles/fronde',
        author: 'Marie Dupont',
        summary: '<p>Des lycéens bloquent leur établissement pour dénoncer les violences.</p>',
        published_at: '2026-09-30T08:00:00Z',
        feed: { id: 2, name: 'Reporterre' },
    };
    const share = reader.shareQuery(article);
    const emailNetwork = reader.shareNetworks().find((network) => network.kind === 'email');
    assert.ok(emailNetwork, 'le réseau e-mail est absent du lecteur');

    const href = decodeURIComponent(emailNetwork.intent(share.title, share.url, share.article));
    assert.match(href, /^mailto:\?subject=RSS Reader : /, 'le sujet identifie l’application avant le titre');
    assert.doesNotMatch(href, /<p>|<strong>|<a href=/, 'le corps reste en texte brut : le HTML d’un mailto est affiché tel quel');
    assert.match(href, /Bonjour,/, 'le corps s’ouvre sur une formule de politesse');
    assert.match(href, /L’article ci-dessous pourrait vous intéresser/, 'le corps annonce l’article');
    assert.match(href, /Marie Dupont — Reporterre/, 'l’auteur et la source figurent dans le corps');
    assert.match(href, /30 septembre 2026/, 'la date figure dans le corps');
    assert.match(href, /Des lycéens bloquent leur établissement/, 'l’extrait du résumé figure dans le corps');
    assert.match(href, /Consulter l’article : https:\/\/site\.test\/articles\/fronde/, 'le lien d’origine est annoncé et présent');
});
