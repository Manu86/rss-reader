ALTER TABLE articles ADD COLUMN content_source TEXT
    CHECK (content_source IN ('feed', 'page'));

ALTER TABLE articles ADD COLUMN content_page_checked_at TEXT;

UPDATE articles
SET content_source = 'feed'
WHERE content IS NOT NULL;
