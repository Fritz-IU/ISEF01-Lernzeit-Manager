-- Lernzeit-Manager – Datenbankschema (MariaDB 10.4)
--
-- Einzige verbindliche Quelle für die Tabellenstruktur.
-- Änderungen nur hier und per Pull Request, danach bei allen: php sql/reset.php
-- Kein Datenbankname im Skript – den legt sql/reset.php aus der .env fest.
-- Jede Anweisung endet mit einem Semikolon am Zeilenende (wichtig für reset.php).

SET NAMES utf8mb4;

DROP TABLE IF EXISTS time_entries;
DROP TABLE IF EXISTS learning_goals;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_goals (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    title          VARCHAR(150) NOT NULL,
    description    TEXT         NULL,
    target_minutes INT UNSIGNED NULL,
    deadline       DATE         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_learning_goals_user (user_id),
    CONSTRAINT fk_learning_goals_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE time_entries (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    goal_id         INT UNSIGNED NULL,
    started_at      DATETIME     NOT NULL,
    ended_at        DATETIME     NULL,
    tracked_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_time_entries_user (user_id),
    KEY idx_time_entries_goal (goal_id),
    CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_time_entries_goal FOREIGN KEY (goal_id)
        REFERENCES learning_goals (id) ON DELETE SET NULL,
    CONSTRAINT chk_time_entries_range CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
