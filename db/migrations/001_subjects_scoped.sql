-- Migration: scope subjects per teacher
USE `testgramatikov`;

ALTER TABLE subjects
  ADD COLUMN owner_teacher_id BIGINT UNSIGNED NULL AFTER id;

-- Drop old global unique constraint on slug
ALTER TABLE subjects
  DROP INDEX uq_subjects_slug;

-- Add new scoped unique constraint
ALTER TABLE subjects
  ADD UNIQUE KEY uq_subjects_owner_slug (owner_teacher_id, slug);

-- Add FK to users (teachers)
ALTER TABLE subjects
  ADD CONSTRAINT fk_subjects_owner
  FOREIGN KEY (owner_teacher_id) REFERENCES users(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

