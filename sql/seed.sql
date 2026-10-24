-- Lernzeit-Manager – synthetische Testdaten (DSGVO-konform, keine echten Personen)
--
-- Wird von sql/reset.php nach schema.sql eingespielt.
-- Test-Login für alle Testnutzer: Passwort "Lernzeit-Test-2026" (siehe docs/datenbank.md)
-- Jede Anweisung endet mit einem Semikolon am Zeilenende (wichtig für reset.php).

SET NAMES utf8mb4;

INSERT INTO users (id, email, password_hash, display_name, study_program, semester, created_at) VALUES
    (1, 'testuser@iu-demo.de',  '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Test Nutzer',  'Informatik',            3, '2026-09-01 08:00:00'),
    (2, 'testuser2@iu-demo.de', '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Test Nutzerin', 'Wirtschaftsinformatik', 2, '2026-09-02 09:30:00');

INSERT INTO learning_goals (id, user_id, title, description, target_hours, start_date, end_date, created_at) VALUES
    (1, 1, 'Software Engineering – Klausurvorbereitung', 'Skript Kapitel 1–6 durcharbeiten', 20.00, '2026-09-01', '2026-12-15', '2026-09-01 08:15:00'),
    (2, 1, 'Datenbanken – Übungsaufgaben',               'SQL-Joins und Normalisierung üben', 10.00, '2026-09-01', '2026-11-30', '2026-09-03 18:00:00'),
    (3, 2, 'Mathematik – Wiederholung',                  NULL,                                 8.00, '2026-09-01', '2027-02-28', '2026-09-05 10:00:00');

INSERT INTO monthly_budgets (id, user_id, plan_month, budget_hours, created_at) VALUES
    (1, 1, '2026-09', 10.00, '2026-09-01 08:20:00'),
    (2, 1, '2026-10', 12.00, '2026-09-01 08:21:00'),
    (3, 1, '2026-11', 10.00, '2026-09-01 08:22:00'),
    (4, 1, '2026-12',  8.00, '2026-09-01 08:23:00'),
    (5, 2, '2026-10',  5.00, '2026-09-05 10:05:00');

INSERT INTO monthly_goals (id, user_id, goal_id, plan_month, title, description, planned_hours, created_at) VALUES
    (1, 1, 1, '2026-09', 'Kapitel 1–2: Grundlagen & Vorgehensmodelle', 'Zusammenfassung schreiben',              5.00, '2026-09-01 08:25:00'),
    (2, 1, 2, '2026-09', 'ER-Modellierung & Relationenschema',         'Übungsblatt 1 und 2 lösen',              3.50, '2026-09-03 18:05:00'),
    (3, 1, 1, '2026-10', 'Kapitel 3–4: Anforderungsanalyse & UML',     'Use-Case- und Klassendiagramme zeichnen', 6.00, '2026-09-15 09:00:00'),
    (4, 1, 2, '2026-10', 'SQL-Joins & Normalisierung (1NF–3NF)',       'Praxisaufgaben in MariaDB testen',        4.00, '2026-09-15 09:10:00'),
    (5, 1, 1, '2026-11', 'Kapitel 5–6: Softwarearchitektur & Tests',   'Altklausuren sichten',                    5.00, '2026-09-20 11:00:00'),
    (6, 1, 2, '2026-11', 'Transaktionen & Indexierung',                'Restliche Übungsaufgaben abschließen',    2.50, '2026-09-20 11:15:00'),
    (7, 1, 1, '2026-12', 'Generalprobe & Karteikarten wiederholen',    'Prüfungssimulation unter Zeitdruck',      4.00, '2026-09-25 14:00:00'),
    (8, 2, 3, '2026-10', 'Lineare Algebra Grundlagen',                 NULL,                                      4.00, '2026-09-05 10:10:00');

INSERT INTO time_entries (id, user_id, goal_id, started_at, ended_at, tracked_seconds) VALUES
    (1, 1, 1,    '2026-10-01 09:00:00', '2026-10-01 10:30:00', 5400),
    (2, 1, 1,    '2026-10-03 14:00:00', '2026-10-03 14:45:00', 2700),
    (3, 1, 2,    '2026-10-05 19:00:00', '2026-10-05 20:00:00', 3600),
    (4, 1, NULL, '2026-10-06 08:00:00', '2026-10-06 08:20:00', 1200),
    (5, 2, 3,    '2026-10-04 16:00:00', '2026-10-04 17:15:00', 4500);
