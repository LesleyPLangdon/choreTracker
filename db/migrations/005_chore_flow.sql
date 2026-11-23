-- Phase 2 (Chores): a note when a parent sends a chore back, and soft-removal of slots.

-- Optional short note shown to the kid when a chore is sent back.
ALTER TABLE chore_instances
  ADD COLUMN send_back_note VARCHAR(255) NULL AFTER sent_back_at;

-- Removing a slot from a chore deactivates it instead of deleting it, so instances (and the
-- ledger entries that reference them) are never lost through the ON DELETE CASCADE.
ALTER TABLE chore_slots
  ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER due_by;
