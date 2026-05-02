-- ============================================================================
-- App_Meta: a tiny key/value store for things like "when did the dormancy
-- sweep last run". Single source of truth so we can rate-limit cheap.
--
-- Dormancy_Log: who got auto-deactivated and when, so admins can review
-- and undo from menu.php.
-- ============================================================================

CREATE TABLE IF NOT EXISTS App_Meta (
    `meta_key`   VARCHAR(64) PRIMARY KEY,
    `meta_value` TEXT,
    `updated_at` DATETIME
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS Dormancy_Log (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    swept_at     DATETIME NOT NULL,
    entity_type  ENUM('project','client') NOT NULL,
    entity_id    INT NOT NULL,
    entity_label VARCHAR(255),
    last_activity DATE NULL,
    restored_at  DATETIME NULL,
    INDEX idx_dl_sweep (swept_at),
    INDEX idx_dl_entity (entity_type, entity_id)
) ENGINE=InnoDB;
