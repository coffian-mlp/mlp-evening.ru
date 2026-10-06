-- Additive continuation envelope; legacy interactions retain NULL.
ALTER TABLE command_interactions ADD COLUMN context_json JSON NULL AFTER options_json;
