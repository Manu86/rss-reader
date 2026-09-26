CREATE TABLE remote_action_attempts (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    attempted_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_remote_action_attempt_lookup
    ON remote_action_attempts(user_id, action, attempted_at);
