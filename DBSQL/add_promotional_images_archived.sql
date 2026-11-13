-- Adds soft-delete (archive) support to promotional_images: deleting an
-- image from the admin carousel manager now archives it instead of
-- permanently removing the row. Safe to re-run.
ALTER TABLE promotional_images
    ADD COLUMN IF NOT EXISTS is_archived BOOLEAN DEFAULT FALSE AFTER is_active;
