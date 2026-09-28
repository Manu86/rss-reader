ALTER TABLE users ADD COLUMN email TEXT
    CHECK (email IS NULL OR length(email) <= 254);
