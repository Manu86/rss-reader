CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE user_settings (
    user_id INTEGER PRIMARY KEY,
    articles_per_page INTEGER NOT NULL DEFAULT 25 CHECK (articles_per_page IN (10, 25, 50, 100)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE categories (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX idx_categories_user_name
    ON categories(user_id, name COLLATE NOCASE);

CREATE TABLE feeds (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    category_id INTEGER,
    name TEXT NOT NULL,
    feed_url TEXT NOT NULL,
    site_url TEXT,
    favicon_path TEXT,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    last_fetch_attempt_at TEXT,
    last_successful_fetch_at TEXT,
    last_article_at TEXT,
    last_fetch_status TEXT CHECK (last_fetch_status IN ('never', 'success', 'error')),
    last_fetch_error TEXT,
    etag TEXT,
    last_modified TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    UNIQUE (user_id, feed_url),
    UNIQUE (id, user_id)
);

CREATE TRIGGER feeds_category_owner_insert
BEFORE INSERT ON feeds
WHEN NEW.category_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM categories
      WHERE id = NEW.category_id AND user_id = NEW.user_id
  )
BEGIN
    SELECT RAISE(ABORT, 'feed category ownership violation');
END;

CREATE TRIGGER feeds_category_owner_update
BEFORE UPDATE OF category_id, user_id ON feeds
WHEN NEW.category_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM categories
      WHERE id = NEW.category_id AND user_id = NEW.user_id
  )
BEGIN
    SELECT RAISE(ABORT, 'feed category ownership violation');
END;

CREATE TABLE articles (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    feed_id INTEGER NOT NULL,
    guid TEXT,
    guid_hash TEXT,
    title TEXT NOT NULL,
    url TEXT,
    author TEXT,
    published_at TEXT,
    discovered_at TEXT NOT NULL,
    summary TEXT,
    content TEXT,
    image_path TEXT,
    is_read INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    deduplication_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (feed_id, user_id) REFERENCES feeds(id, user_id) ON DELETE CASCADE,
    UNIQUE (feed_id, deduplication_hash)
);

CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    summary,
    content,
    author,
    content='articles',
    content_rowid='id'
);

CREATE TRIGGER articles_fts_insert AFTER INSERT ON articles BEGIN
    INSERT INTO articles_fts(rowid, title, summary, content, author)
    VALUES (NEW.id, NEW.title, NEW.summary, NEW.content, NEW.author);
END;

CREATE TRIGGER articles_fts_delete AFTER DELETE ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, summary, content, author)
    VALUES ('delete', OLD.id, OLD.title, OLD.summary, OLD.content, OLD.author);
END;

CREATE TRIGGER articles_fts_update AFTER UPDATE OF title, summary, content, author ON articles BEGIN
    INSERT INTO articles_fts(articles_fts, rowid, title, summary, content, author)
    VALUES ('delete', OLD.id, OLD.title, OLD.summary, OLD.content, OLD.author);
    INSERT INTO articles_fts(rowid, title, summary, content, author)
    VALUES (NEW.id, NEW.title, NEW.summary, NEW.content, NEW.author);
END;

CREATE TABLE auth_login_attempts (
    id INTEGER PRIMARY KEY,
    identifier_hash TEXT NOT NULL,
    address_hash TEXT NOT NULL,
    attempted_at TEXT NOT NULL
);

CREATE INDEX idx_feeds_user_category ON feeds(user_id, category_id);
CREATE INDEX idx_feeds_active ON feeds(is_active, user_id);
CREATE INDEX idx_articles_user_date ON articles(user_id, published_at, discovered_at, id);
CREATE INDEX idx_articles_feed_date ON articles(feed_id, published_at, discovered_at, id);
CREATE INDEX idx_articles_user_unread ON articles(user_id, is_read);
CREATE INDEX idx_articles_user_favorite ON articles(user_id, is_favorite);
CREATE INDEX idx_auth_attempt_lookup
    ON auth_login_attempts(identifier_hash, address_hash, attempted_at);
