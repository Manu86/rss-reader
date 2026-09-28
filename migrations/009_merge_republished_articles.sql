-- Fusionne les articles republices: meme flux, meme URL, meme titre et meme jour
-- de publication UTC, mais GUID differents. Le plus ancien est conserve; l'etat
-- de lecture, de favori et l'image deja telechargee sont conserves sur le survivant.
-- Les triggers FTS assurent la synchronisation de l'index avec la suppression.

UPDATE articles AS kept
SET is_read = 1
WHERE kept.is_read = 0
  AND kept.url IS NOT NULL
  AND date(kept.published_at) IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM articles AS duplicate
      WHERE duplicate.user_id = kept.user_id
        AND duplicate.feed_id = kept.feed_id
        AND duplicate.url = kept.url
        AND duplicate.title = kept.title
        AND date(duplicate.published_at) = date(kept.published_at)
        AND duplicate.is_read = 1
  );

UPDATE articles AS kept
SET is_favorite = 1
WHERE kept.is_favorite = 0
  AND kept.url IS NOT NULL
  AND date(kept.published_at) IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM articles AS duplicate
      WHERE duplicate.user_id = kept.user_id
        AND duplicate.feed_id = kept.feed_id
        AND duplicate.url = kept.url
        AND duplicate.title = kept.title
        AND date(duplicate.published_at) = date(kept.published_at)
        AND duplicate.is_favorite = 1
  );

UPDATE articles AS kept
SET image_path = (
    SELECT duplicate.image_path
    FROM articles AS duplicate
    WHERE duplicate.user_id = kept.user_id
      AND duplicate.feed_id = kept.feed_id
      AND duplicate.url = kept.url
      AND duplicate.title = kept.title
      AND date(duplicate.published_at) = date(kept.published_at)
      AND duplicate.image_path IS NOT NULL
    ORDER BY duplicate.id
    LIMIT 1
)
WHERE kept.image_path IS NULL
  AND kept.url IS NOT NULL
  AND date(kept.published_at) IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM articles AS duplicate
      WHERE duplicate.user_id = kept.user_id
        AND duplicate.feed_id = kept.feed_id
        AND duplicate.url = kept.url
        AND duplicate.title = kept.title
        AND date(duplicate.published_at) = date(kept.published_at)
        AND duplicate.image_path IS NOT NULL
  );

DELETE FROM articles AS duplicate
WHERE duplicate.url IS NOT NULL
  AND date(duplicate.published_at) IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM articles AS kept
      WHERE kept.user_id = duplicate.user_id
        AND kept.feed_id = duplicate.feed_id
        AND kept.url = duplicate.url
        AND kept.title = duplicate.title
        AND date(kept.published_at) = date(duplicate.published_at)
        AND kept.id < duplicate.id
  );
