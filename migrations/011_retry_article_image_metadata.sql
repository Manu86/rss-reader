-- Le récupérateur de pages HTML dispose désormais d'un User-Agent distinct.
-- Les articles dont l'ancien essai a été bloqué doivent pouvoir bénéficier
-- une fois de ce nouveau chemin lors de leur prochaine synchronisation.
UPDATE articles
SET image_metadata_checked_at = NULL
WHERE image_path IS NULL
  AND image_metadata_checked_at IS NOT NULL;
