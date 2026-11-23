-- Chores, slots, daily instances, and per-day lock status. (Used from Phase 2 on.)
-- days_of_week is a bitmask: Mon=1, Tue=2, Wed=4, Thu=8, Fri=16, Sat=32, Sun=64 (127 = every day).

CREATE TABLE chores (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id      BIGINT UNSIGNED NOT NULL,
  kid_id            BIGINT UNSIGNED NOT NULL,            -- one row per kid
  group_id          CHAR(32)     NOT NULL,               -- links the copies made when one chore is assigned to several kids
  title             VARCHAR(100) NOT NULL,
  value             INT UNSIGNED NULL,                   -- points (whole) or cents; NULL = no value
  currency          ENUM('points','money') NULL,         -- NULL when there is no value
  is_required       TINYINT(1)   NOT NULL DEFAULT 0,
  schedule_type     ENUM('daily','one_off') NOT NULL DEFAULT 'daily',
  days_of_week      TINYINT UNSIGNED NOT NULL DEFAULT 127,
  due_date          DATE         NULL,                   -- one-off chores
  overdue_behavior  ENUM('expire','carry_over') NOT NULL DEFAULT 'expire',
  auto_approve      TINYINT(1)   NOT NULL DEFAULT 0,
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL,
  updated_at        DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_chores_household (household_id),
  KEY idx_chores_kid (kid_id, active),
  KEY idx_chores_group (group_id),
  CONSTRAINT fk_chores_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_chores_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every chore has one or more slots (most have one).
CREATE TABLE chore_slots (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chore_id        BIGINT UNSIGNED NOT NULL,
  label           VARCHAR(40)  NOT NULL,                 -- e.g. "Morning", "Evening"
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_from  TIME         NULL,                     -- household-local; NULL = anytime
  due_by          TIME         NULL,                     -- household-local; SOFT (late flag only)
  PRIMARY KEY (id),
  KEY idx_slots_chore (chore_id, sort_order),
  CONSTRAINT fk_slots_chore FOREIGN KEY (chore_id) REFERENCES chores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One per chore SLOT per kid per household-local date.
CREATE TABLE chore_instances (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id           BIGINT UNSIGNED NOT NULL,
  chore_id               BIGINT UNSIGNED NOT NULL,
  slot_id                BIGINT UNSIGNED NOT NULL,
  kid_id                 BIGINT UNSIGNED NOT NULL,
  local_date             DATE         NOT NULL,          -- household-local "day" (uses end-of-day time)
  status                 ENUM('assigned','reported_done','approved','sent_back','missed','expired') NOT NULL DEFAULT 'assigned',
  value                  INT UNSIGNED NULL,              -- snapshot of the chore's value when the instance was created
  currency               ENUM('points','money') NULL,    -- snapshot
  reported_at            DATETIME     NULL,              -- UTC; used for challenge windows
  is_late                TINYINT(1)   NOT NULL DEFAULT 0,
  approved_by_parent_id  BIGINT UNSIGNED NULL,           -- NULL + status approved = auto-approved
  approved_at            DATETIME     NULL,
  sent_back_at           DATETIME     NULL,
  is_back_filled         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at             DATETIME     NOT NULL,
  updated_at             DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_instances_slot_date (slot_id, local_date),
  KEY idx_instances_kid_date (kid_id, local_date),
  KEY idx_instances_chore_reported (chore_id, reported_at),
  KEY idx_instances_household_status (household_id, status),
  CONSTRAINT fk_instances_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_instances_chore FOREIGN KEY (chore_id) REFERENCES chores (id) ON DELETE CASCADE,
  CONSTRAINT fk_instances_slot FOREIGN KEY (slot_id) REFERENCES chore_slots (id) ON DELETE CASCADE,
  CONSTRAINT fk_instances_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE,
  CONSTRAINT fk_instances_approver FOREIGN KEY (approved_by_parent_id) REFERENCES parents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Whether a kid's earnings for a day are locked, unlocked, or forfeited.
CREATE TABLE kid_days (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kid_id                BIGINT UNSIGNED NOT NULL,
  local_date            DATE         NOT NULL,
  status                ENUM('locked','unlocked','forfeited') NOT NULL DEFAULT 'locked',
  unlocked_at           DATETIME     NULL,
  forfeited_at          DATETIME     NULL,
  override_by_parent_id BIGINT UNSIGNED NULL,
  override_note         VARCHAR(255) NULL,
  overridden_at         DATETIME     NULL,
  created_at            DATETIME     NOT NULL,
  updated_at            DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kid_days (kid_id, local_date),
  CONSTRAINT fk_kid_days_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE,
  CONSTRAINT fk_kid_days_parent FOREIGN KEY (override_by_parent_id) REFERENCES parents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
