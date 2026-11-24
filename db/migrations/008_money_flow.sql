-- Phase 4 (Money flow): goals with links and pending changes, reward visibility, linked "save for this" goals,
-- and grouping tags on ledger entries so the activity list can read naturally.

-- A goal can have a link to the item. A kid's later change to the link or price on an approved goal is held in
-- the pending_* columns until a parent approves or denies it (the goal itself stays active).
-- reward_id links a "Save for this" goal to its reward. New statuses: denied (request refused), used.
ALTER TABLE goals
  MODIFY COLUMN status ENUM('requested','active','reached','cancelled','denied','used') NOT NULL DEFAULT 'requested',
  ADD COLUMN link VARCHAR(500) NULL AFTER target,
  ADD COLUMN pending_link VARCHAR(500) NULL AFTER link,
  ADD COLUMN pending_link_set TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_link,
  ADD COLUMN pending_target BIGINT UNSIGNED NULL AFTER pending_link_set,
  ADD COLUMN change_requested_at DATETIME NULL AFTER pending_target,
  ADD COLUMN reward_id BIGINT UNSIGNED NULL AFTER change_requested_at,
  ADD COLUMN decision_note VARCHAR(255) NULL AFTER reward_id,
  ADD COLUMN used_at DATETIME NULL AFTER reached_at,
  ADD KEY idx_goals_reward (reward_id),
  ADD CONSTRAINT fk_goals_reward FOREIGN KEY (reward_id) REFERENCES rewards (id) ON DELETE SET NULL;

-- Which kids can see a reward. No rows = every kid in the household.
CREATE TABLE reward_kids (
  reward_id  BIGINT UNSIGNED NOT NULL,
  kid_id     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (reward_id, kid_id),
  CONSTRAINT fk_reward_kids_reward FOREIGN KEY (reward_id) REFERENCES rewards (id) ON DELETE CASCADE,
  CONSTRAINT fk_reward_kids_kid FOREIGN KEY (kid_id) REFERENCES kids (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reward_requests
  MODIFY COLUMN status ENUM('requested','approved','denied','delivered','cancelled') NOT NULL DEFAULT 'requested',
  ADD COLUMN decision_note VARCHAR(255) NULL AFTER delivered_at;

-- batch_id groups the entries of ONE move (e.g. an unlock's pending-out, spending-in, and bucket splits);
-- action says what kind of move it was. Both are only used to word the activity list; balances ignore them.
ALTER TABLE ledger_entries
  ADD COLUMN batch_id CHAR(16) NULL AFTER note,
  ADD COLUMN action VARCHAR(30) NULL AFTER batch_id,
  ADD KEY idx_ledger_activity (kid_id, created_at);
