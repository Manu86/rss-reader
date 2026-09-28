ALTER TABLE articles ADD COLUMN favorited_at TEXT;

-- Les favoris existants ne disposent pas de l'instant exact du clic. Leur
-- dernière modification est le meilleur historique disponible pour amorcer
-- le nouveau signal sans perdre les préférences déjà enregistrées.
UPDATE articles
SET favorited_at = updated_at
WHERE is_favorite = 1;

CREATE INDEX idx_articles_user_favorited
    ON articles(user_id, is_favorite, favorited_at DESC, id DESC);
