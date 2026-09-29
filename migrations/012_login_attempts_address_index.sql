-- La limitation de connexion compte les échecs par compte et par adresse
-- séparément : un compte visé depuis des adresses changeantes doit être bloqué,
-- et une adresse doit être bloquée même si elle change de comptes visés.
-- L'index existant commence par identifier_hash et ne sert pas le comptage par
-- adresse seule.
CREATE INDEX idx_auth_attempt_address
    ON auth_login_attempts(address_hash, attempted_at);
