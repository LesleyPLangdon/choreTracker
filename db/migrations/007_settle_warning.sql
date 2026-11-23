-- When settling a household's ended days fails, remember it so parents can be warned on Home.
-- Cleared by the next settle that succeeds.
ALTER TABLE households
  ADD COLUMN settle_failed_at DATETIME NULL AFTER settled_through;
