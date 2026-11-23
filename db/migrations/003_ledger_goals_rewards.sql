-- Ledger, buckets, goals, rewards. (Used from Phase 2/4 on.)

-- Goals and reward requests are created before the ledger so it can reference them.
CREATE TABLE goals (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id          BIGINT UNSIGNED NOT NULL,
  kid_id                BIGINT UNSIGNED NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  currency              ENUM('points','money') NOT NULL,
  target                BIGINT UNSIGNED NOT NULL,        -- points or cents
  status                ENUM('requested','active','reached','cancelled') NOT NULL DEFAULT 'requested',
  approved_by_parent_id BIGINT UNSIGNED NULL,
  approved_at           DATETIME NULL,
  reached_at            DATETIME NULL,
  created_at            DATETIME NOT NULL,
  updated_at            DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_goals_kid_status (kid_id, status),
  KEY idx_goals_household (household_id, status),
  CONSTRAINT fk_goals_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_goals_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE,
  CONSTRAINT fk_goals_approver FOREIGN KEY (approved_by_parent_id) REFERENCES parents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rewards (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id  BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  currency      ENUM('points','money') NOT NULL,
  cost          BIGINT UNSIGNED NOT NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rewards_household (household_id, active),
  CONSTRAINT fk_rewards_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reward_requests (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id          BIGINT UNSIGNED NOT NULL,
  reward_id             BIGINT UNSIGNED NOT NULL,
  kid_id                BIGINT UNSIGNED NOT NULL,
  cost                  BIGINT UNSIGNED NOT NULL,         -- snapshot of the reward's cost at request time
  currency              ENUM('points','money') NOT NULL,  -- snapshot
  status                ENUM('requested','approved','denied','delivered') NOT NULL DEFAULT 'requested',
  approved_by_parent_id BIGINT UNSIGNED NULL,
  requested_at          DATETIME NOT NULL,
  decided_at            DATETIME NULL,
  delivered_at          DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_reqs_household_status (household_id, status),
  KEY idx_reqs_kid (kid_id),
  CONSTRAINT fk_reqs_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_reqs_reward FOREIGN KEY (reward_id) REFERENCES rewards (id) ON DELETE CASCADE,
  CONSTRAINT fk_reqs_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE,
  CONSTRAINT fk_reqs_approver FOREIGN KEY (approved_by_parent_id) REFERENCES parents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- APPEND-ONLY. Never UPDATE or DELETE rows; corrections are new offsetting rows.
-- Balances are always computed: SUM(amount) grouped by kid, currency, bucket.
-- amount is signed: whole points, or cents for money.
CREATE TABLE ledger_entries (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id        BIGINT UNSIGNED NOT NULL,
  kid_id              BIGINT UNSIGNED NOT NULL,
  currency            ENUM('points','money') NOT NULL,
  bucket              ENUM('pending','spending','savings','giving','goal') NOT NULL,
  entry_type          ENUM('earning','unlock','bucket_split','forfeit','goal_transfer','reward_redemption','payout_transfer','parent_adjustment') NOT NULL,
  amount              BIGINT NOT NULL,
  local_date          DATE NULL,                            -- the earning day this entry belongs to, if any
  chore_instance_id   BIGINT UNSIGNED NULL,
  goal_id             BIGINT UNSIGNED NULL,
  reward_request_id   BIGINT UNSIGNED NULL,
  created_by_type     ENUM('system','parent','kid') NOT NULL DEFAULT 'system',
  created_by_id       BIGINT UNSIGNED NULL,
  note                VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ledger_balance (kid_id, currency, bucket),
  KEY idx_ledger_day (kid_id, local_date),
  KEY idx_ledger_instance (chore_instance_id),
  KEY idx_ledger_goal (goal_id),
  KEY idx_ledger_household (household_id),
  CONSTRAINT fk_ledger_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_instance FOREIGN KEY (chore_instance_id) REFERENCES chore_instances (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_reward_request FOREIGN KEY (reward_request_id) REFERENCES reward_requests (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Percentages taken off the top when a day unlocks. Whole percents; the remainder goes to Spending.
CREATE TABLE bucket_settings (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kid_id       BIGINT UNSIGNED NOT NULL,
  currency     ENUM('points','money') NOT NULL,
  savings_pct  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  giving_pct   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bucket_settings (kid_id, currency),
  CONSTRAINT fk_bucket_settings_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
