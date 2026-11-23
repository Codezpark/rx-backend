-- Real arrival time for the waiting timer (was derived from updated_at, which resets on every change).
ALTER TABLE appointments ADD COLUMN arrived_at DATETIME NULL AFTER called_at;
UPDATE appointments SET arrived_at = updated_at WHERE status IN ('arrived','completed') AND arrived_at IS NULL;
