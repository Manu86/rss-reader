ALTER TABLE user_settings ADD COLUMN recommendation_email_frequency TEXT NOT NULL DEFAULT 'never'
    CHECK (recommendation_email_frequency IN ('never', 'daily', 'weekly', 'monthly'));

ALTER TABLE user_settings ADD COLUMN recommendation_email_last_sent_at TEXT;
