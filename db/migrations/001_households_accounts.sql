-- Households, parents, invites, kids, auth tokens.
-- MySQL 5.7 compatible. All timestamps are UTC DATETIMEs.

CREATE TABLE households (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100) NOT NULL,
  timezone      VARCHAR(64)  NOT NULL,                 -- IANA, e.g. America/New_York
  day_end_time  TIME         NOT NULL DEFAULT '00:00:00', -- household-local; when "today" rolls over
  plan          VARCHAR(20)  NOT NULL DEFAULT 'free',
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parents (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id   BIGINT UNSIGNED NOT NULL,
  email          VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  display_name   VARCHAR(60)  NOT NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parents_email (email),
  KEY idx_parents_household (household_id),
  CONSTRAINT fk_parents_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE parent_invites (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id         BIGINT UNSIGNED NOT NULL,
  invited_by_parent_id BIGINT UNSIGNED NOT NULL,
  email                VARCHAR(190) NULL,              -- optional: restrict to this address
  token_hash           CHAR(64)     NOT NULL,          -- sha256 of the invite token
  expires_at           DATETIME     NOT NULL,
  accepted_at          DATETIME     NULL,
  accepted_parent_id   BIGINT UNSIGNED NULL,
  revoked              TINYINT(1)   NOT NULL DEFAULT 0,
  created_at           DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invites_token (token_hash),
  KEY idx_invites_household (household_id),
  CONSTRAINT fk_invites_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_invites_inviter FOREIGN KEY (invited_by_parent_id) REFERENCES parents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kids are profiles, not accounts: no email, no PII.
CREATE TABLE kids (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id         BIGINT UNSIGNED NOT NULL,
  nickname             VARCHAR(30)  NOT NULL,
  avatar               VARCHAR(16)  NOT NULL,          -- one of a fixed set of emoji
  pin_hash             VARCHAR(255) NOT NULL,
  link_token_hash      CHAR(64)     NOT NULL,          -- sha256 of the link token; plaintext is never stored
  failed_pin_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  active               TINYINT(1)   NOT NULL DEFAULT 1,
  created_at           DATETIME     NOT NULL,
  updated_at           DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kids_link (link_token_hash),
  KEY idx_kids_household (household_id),
  CONSTRAINT fk_kids_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bearer tokens for parents and kids. Only the sha256 hash is stored.
CREATE TABLE auth_tokens (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash    CHAR(64)     NOT NULL,
  type          ENUM('parent','kid') NOT NULL,
  parent_id     BIGINT UNSIGNED NULL,
  kid_id        BIGINT UNSIGNED NULL,
  household_id  BIGINT UNSIGNED NOT NULL,
  expires_at    DATETIME     NOT NULL,
  revoked       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  last_used_at  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_parent (parent_id),
  KEY idx_tokens_kid (kid_id),
  CONSTRAINT fk_tokens_parent FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE,
  CONSTRAINT fk_tokens_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE,
  CONSTRAINT fk_tokens_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
