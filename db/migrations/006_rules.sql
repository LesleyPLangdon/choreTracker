-- Phase 3 (Rules): required chores, day settlement, overrides.

-- The last household day that has been fully settled (end-of-day work done). NULL = never settled yet.
ALTER TABLE households
  ADD COLUMN settled_through DATE NULL AFTER plan;

-- The first household day a chore counts as required. Making a chore required (or adding a required chore)
-- only takes effect from the next household day, so a day that already unlocked never re-locks.
ALTER TABLE chores
  ADD COLUMN required_from DATE NULL AFTER is_required;

-- Slots remember when they were added/removed, so settling days nobody opened never invents instances
-- for a slot that did not exist yet (or was already gone).
ALTER TABLE chore_slots
  ADD COLUMN created_at DATETIME NULL AFTER active,
  ADD COLUMN deactivated_at DATETIME NULL AFTER created_at;

-- required_for_date: the household day this instance gates (NULL = not required).
--   Repeating chores: the instance's own day. Required one-time chores: their due date (or creation day).
ALTER TABLE chore_instances
  ADD COLUMN required_for_date DATE NULL AFTER local_date,
  ADD KEY idx_instances_required (kid_id, required_for_date);

-- "waiting" = every required chore was reported by end of day but some are not approved yet.
-- notified_at: when the kid dismissed the "yesterday's earnings didn't unlock" note.
ALTER TABLE kid_days
  MODIFY COLUMN status ENUM('locked','waiting','unlocked','forfeited') NOT NULL DEFAULT 'locked',
  ADD COLUMN notified_at DATETIME NULL AFTER overridden_at;

-- Who did what, and when, for back-fills and overrides.
CREATE TABLE day_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id  BIGINT UNSIGNED NOT NULL,
  kid_id        BIGINT UNSIGNED NOT NULL,
  local_date    DATE NOT NULL,
  instance_id   BIGINT UNSIGNED NULL,
  action        ENUM('back_fill','unlock','forfeit','undo_approval','clear_late') NOT NULL,
  parent_id     BIGINT UNSIGNED NULL,
  note          VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_day_events_kid_date (kid_id, local_date),
  CONSTRAINT fk_day_events_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE,
  CONSTRAINT fk_day_events_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE,
  CONSTRAINT fk_day_events_instance FOREIGN KEY (instance_id) REFERENCES chore_instances (id) ON DELETE SET NULL,
  CONSTRAINT fk_day_events_parent FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
