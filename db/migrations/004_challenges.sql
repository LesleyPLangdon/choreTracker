-- Challenges: earned rewards that cost nothing (no ledger entries). Built in Phase 4.
-- Window times are household-local. "once" uses the *_local datetimes; "daily"/"weekly" use
-- start_time/end_time (and weekdays). Each concrete window becomes a row in challenge_periods
-- with its start/end converted to UTC.

CREATE TABLE challenges (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id        BIGINT UNSIGNED NOT NULL,
  kid_id              BIGINT UNSIGNED NOT NULL,
  reward_name         VARCHAR(100) NOT NULL,
  reward_description  VARCHAR(255) NULL,
  target_count        SMALLINT UNSIGNED NOT NULL,
  repeat_type         ENUM('once','daily','weekly') NOT NULL,
  -- once:
  once_start_local    DATETIME NULL,
  once_end_local      DATETIME NULL,
  -- daily and weekly:
  start_time          TIME NULL,
  end_time            TIME NULL,
  -- daily: which days (same bitmask as chores.days_of_week; Mon=1 .. Sun=64)
  days_of_week        TINYINT UNSIGNED NOT NULL DEFAULT 127,
  -- weekly: ISO weekday 1=Mon .. 7=Sun
  start_weekday       TINYINT UNSIGNED NULL,
  end_weekday         TINYINT UNSIGNED NULL,
  active              TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_challenges_household (household_id, active),
  KEY idx_challenges_kid (kid_id, active),
  CONSTRAINT fk_challenges_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_challenges_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which chores count: one or more, identified by chores.group_id (a chore "group" may span kids).
CREATE TABLE challenge_chores (
  challenge_id    BIGINT UNSIGNED NOT NULL,
  chore_group_id  CHAR(32) NOT NULL,
  PRIMARY KEY (challenge_id, chore_group_id),
  KEY idx_challenge_chores_group (chore_group_id),
  CONSTRAINT fk_challenge_chores_challenge FOREIGN KEY (challenge_id) REFERENCES challenges (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE challenge_periods (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  challenge_id           BIGINT UNSIGNED NOT NULL,
  period_start           DATETIME NOT NULL,                 -- UTC, inclusive
  period_end             DATETIME NOT NULL,                 -- UTC, exclusive
  status                 ENUM('in_progress','earned','missed','delivered') NOT NULL DEFAULT 'in_progress',
  approved_by_parent_id  BIGINT UNSIGNED NULL,              -- set when a parent marks it earned/delivered by hand
  earned_at              DATETIME NULL,
  delivered_at           DATETIME NULL,
  created_at             DATETIME NOT NULL,
  updated_at             DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_periods (challenge_id, period_start),
  KEY idx_periods_status (status),
  CONSTRAINT fk_periods_challenge FOREIGN KEY (challenge_id) REFERENCES challenges (id) ON DELETE CASCADE,
  CONSTRAINT fk_periods_approver FOREIGN KEY (approved_by_parent_id) REFERENCES parents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
