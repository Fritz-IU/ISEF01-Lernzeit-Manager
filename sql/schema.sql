-- Lernzeit-Manager – Datenbankschema (MariaDB 10.4)
--
-- Einzige verbindliche Quelle für die Tabellenstruktur.
-- Änderungen nur hier und per Pull Request, danach bei allen: php sql/reset.php
-- Kein Datenbankname im Skript – den legt sql/reset.php aus der .env fest.
-- Jede Anweisung endet mit einem Semikolon am Zeilenende (wichtig für reset.php).

SET NAMES utf8mb4;

DROP TABLE IF EXISTS time_entries;
DROP TABLE IF EXISTS learning_slots;
DROP TABLE IF EXISTS monthly_goals;
DROP TABLE IF EXISTS monthly_budgets;
DROP TABLE IF EXISTS learning_goals;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100) NOT NULL DEFAULT '',
    study_program VARCHAR(150) NULL     DEFAULT NULL,
    semester      TINYINT UNSIGNED NULL DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_goals (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    title          VARCHAR(150) NOT NULL,
    description    TEXT         NULL,
    target_hours   DECIMAL(6,2) NOT NULL,
    start_date     DATE         NOT NULL,
    end_date       DATE         NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_learning_goals_user (user_id),
    CONSTRAINT fk_learning_goals_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_learning_goals_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monthly_budgets (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    plan_month   CHAR(7)      NOT NULL,
    budget_hours DECIMAL(6,2) NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monthly_budgets_user_month (user_id, plan_month),
    CONSTRAINT fk_monthly_budgets_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_monthly_budgets_hours CHECK (budget_hours >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE monthly_goals (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    goal_id       INT UNSIGNED NOT NULL,
    plan_month    CHAR(7)      NOT NULL,
    title         VARCHAR(150) NOT NULL,
    description   TEXT         NULL,
    planned_hours DECIMAL(6,2) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_monthly_goals_user_month (user_id, plan_month),
    KEY idx_monthly_goals_goal (goal_id),
    CONSTRAINT fk_monthly_goals_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_monthly_goals_goal FOREIGN KEY (goal_id)
        REFERENCES learning_goals (id) ON DELETE CASCADE,
    CONSTRAINT chk_monthly_goals_hours CHECK (planned_hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE learning_slots (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    monthly_goal_id INT UNSIGNED NOT NULL,
    slot_date       DATE         NOT NULL,
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,
    notes           VARCHAR(255) NULL     DEFAULT NULL,
    is_completed    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_learning_slots_user_date (user_id, slot_date),
    KEY idx_learning_slots_monthly_goal (monthly_goal_id),
    CONSTRAINT fk_learning_slots_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_learning_slots_monthly_goal FOREIGN KEY (monthly_goal_id)
        REFERENCES monthly_goals (id) ON DELETE CASCADE,
    CONSTRAINT chk_learning_slots_time CHECK (end_time > start_time)
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
