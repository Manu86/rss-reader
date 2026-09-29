-- Chaque article entrant recherche une republication : un article du meme flux
-- dont l'URL et le titre sont deja stockes. Sans index, la requete ne peut
-- s'appuyer que sur les index par utilisateur ou par flux et par date, et
-- parcourt tous les articles de l'utilisateur a chaque article insere.
-- L'index permet de chercher directement le couple URL et titre du flux.
CREATE INDEX idx_articles_feed_url_title
    ON articles(feed_id, url, title);
