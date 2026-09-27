CREATE TABLE user_remember_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_user_remember_tokens_user
    ON user_remember_tokens(user_id);

CREATE INDEX idx_user_remember_tokens_expiry
    ON user_remember_tokens(expires_at);
