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
    (1, 1, '3. Semester – Informatik (WiSe 2026/27)',             'Module: Software Engineering, Datenbanken, Betriebssysteme', 180.00, '2026-09-01', '2027-02-28', '2026-09-01 08:15:00'),
    (2, 1, '4. Semester – Informatik (SoSe 2027)',                'Module: IT-Sicherheit, Web-Programmierung',                  150.00, '2027-03-01', '2027-08-31', '2026-09-03 18:00:00'),
    (3, 2, '2. Semester – Wirtschaftsinformatik (WiSe 2026/27)', 'Module: Mathematik, Betriebswirtschaftslehre',               120.00, '2026-09-01', '2027-02-28', '2026-09-05 10:00:00');

INSERT INTO monthly_budgets (id, user_id, plan_month, budget_hours, created_at) VALUES
    (1, 1, '2026-09', 30.00, '2026-09-01 08:20:00'),
    (2, 1, '2026-10', 45.00, '2026-09-01 08:21:00'),
    (3, 1, '2026-11', 40.00, '2026-09-01 08:22:00'),
    (4, 1, '2026-12', 25.00, '2026-09-01 08:23:00'),
    (5, 1, '2027-03', 30.00, '2026-09-03 18:05:00'),
    (6, 2, '2026-10', 25.00, '2026-09-05 10:05:00');

INSERT INTO monthly_goals (id, user_id, goal_id, plan_month, title, description, planned_hours, created_at) VALUES
    (1,  1, 1, '2026-09', 'Modul Software Engineering', 'Kapitel 1–2: Grundlagen & Vorgehensmodelle',     15.00, '2026-09-01 08:25:00'),
    (2,  1, 1, '2026-09', 'Modul Datenbanken',          'ER-Modellierung & Relationenschema',             12.00, '2026-09-01 08:30:00'),
    (3,  1, 1, '2026-10', 'Modul Software Engineering', 'Kapitel 3–4: Anforderungsanalyse & UML',         18.00, '2026-09-15 09:00:00'),
    (4,  1, 1, '2026-10', 'Modul Datenbanken',          'SQL-Joins & Normalisierung (1NF–3NF)',           15.00, '2026-09-15 09:10:00'),
    (5,  1, 1, '2026-10', 'Modul Betriebssysteme',      'Prozesse, Threads & Scheduling',                 10.00, '2026-09-15 09:20:00'),
    (6,  1, 1, '2026-11', 'Modul Software Engineering', 'Kapitel 5–6: Softwarearchitektur & Tests',       15.00, '2026-09-20 11:00:00'),
    (7,  1, 1, '2026-11', 'Modul Datenbanken',          'Transaktionen & Indexierung',                    12.00, '2026-09-20 11:15:00'),
    (8,  1, 1, '2026-11', 'Modul Betriebssysteme',      'Speicherverwaltung & Dateisysteme',              12.00, '2026-09-20 11:30:00'),
    (9,  1, 1, '2026-12', 'Modul Software Engineering', 'Klausurvorbereitung mit Altklausuren',           12.00, '2026-09-25 14:00:00'),
    (10, 1, 1, '2026-12', 'Modul Betriebssysteme',      'Rechnernetze & Prüfungsvorbereitung',            10.00, '2026-09-25 14:10:00'),
    (11, 1, 2, '2027-03', 'Modul IT-Sicherheit',        'Schutzziele, Kryptografie-Grundlagen',           15.00, '2026-09-03 18:10:00'),
    (12, 1, 2, '2027-03', 'Modul Web-Programmierung',   'HTML, CSS & JavaScript-Grundlagen',              12.00, '2026-09-03 18:15:00'),
    (13, 2, 3, '2026-10', 'Modul Mathematik',           'Lineare Algebra Grundlagen',                     10.00, '2026-09-05 10:10:00'),
    (14, 2, 3, '2026-10', 'Modul Betriebswirtschaftslehre', 'Kosten- und Leistungsrechnung',              8.00, '2026-09-05 10:15:00');

INSERT INTO learning_slots (id, user_id, monthly_goal_id, slot_date, start_time, end_time, notes, is_completed, created_at) VALUES
    (1,  1, 3,  '2026-10-20', '18:00:00', '20:00:00', 'Kapitel 3 lesen und Use-Case-Elemente notieren',      1, '2026-10-18 10:00:00'),
    (2,  1, 4,  '2026-10-21', '17:30:00', '19:00:00', 'INNER und LEFT JOIN an Beispieltabellen üben',        1, '2026-10-18 10:05:00'),
    (3,  1, 5,  '2026-10-23', '16:00:00', '18:00:00', 'Prozesszustände und Scheduling-Verfahren zusammenfassen', 1, '2026-10-18 10:10:00'),
    (4,  1, 3,  '2026-10-26', '18:00:00', '20:00:00', 'Klassendiagramme für Fallstudie modellieren',         1, '2026-10-25 09:00:00'),
    (5,  1, 4,  '2026-10-27', '17:00:00', '18:30:00', 'Normalisierungsaufgaben (1NF bis 3NF) durchrechnen',  1, '2026-10-25 09:05:00'),
    (6,  1, 5,  '2026-10-28', '16:00:00', '18:00:00', 'Übungsaufgaben zu Round Robin und Prioritäten lösen', 1, '2026-10-25 09:10:00'),
    (7,  1, 3,  '2026-10-30', '14:00:00', '16:00:00', 'Sequenz- und Aktivitätsdiagramme wiederholen',        0, '2026-10-25 09:15:00'),
    (8,  1, 4,  '2026-10-31', '10:00:00', '12:00:00', 'Views und Unterabfragen in MariaDB testen',           0, '2026-10-25 09:20:00'),
    (9,  1, 5,  '2026-11-01', '10:00:00', '11:30:00', 'Threads und Synchronisation (Semaphore) nachbereiten', 0, '2026-10-25 09:25:00'),
    (10, 1, 6,  '2026-11-03', '18:00:00', '20:00:00', 'Schichtenarchitektur und MVC-Muster zusammenfassen',  0, '2026-10-25 09:30:00'),
    (11, 1, 7,  '2026-11-04', '17:30:00', '19:00:00', 'ACID-Eigenschaften und Isolationsstufen lernen',      0, '2026-10-25 09:35:00'),
    (12, 2, 13, '2026-10-27', '09:00:00', '11:00:00', 'Vektorräume und Matrizenmultiplikation',              0, '2026-10-20 12:00:00'),
    (13, 2, 14, '2026-10-29', '14:00:00', '15:30:00', 'Deckungsbeitragsrechnung an Beispielen üben',         0, '2026-10-20 12:05:00');

-- Stoppuhr-Einträge mit Pause: tracked_seconds ist kleiner als ended_at - started_at.
INSERT INTO time_entries (id, user_id, monthly_goal_id, learning_slot_id, source, entry_date, started_at, ended_at, tracked_seconds, note, created_at) VALUES
    (1,  1, 1,  NULL, 'stopwatch', '2026-09-08', '2026-09-08 18:00:00', '2026-09-08 19:30:00', 5400, NULL,                                         '2026-09-08 19:30:00'),
    (2,  1, 2,  NULL, 'manual',    '2026-09-12', NULL,                  NULL,                  3600, 'Im Zug ER-Diagramme skizziert',              '2026-09-13 08:00:00'),
    (3,  1, 3,  1,    'stopwatch', '2026-10-20', '2026-10-20 18:05:00', '2026-10-20 20:00:00', 6300, NULL,                                         '2026-10-20 20:00:00'),
    (4,  1, 4,  2,    'stopwatch', '2026-10-21', '2026-10-21 17:30:00', '2026-10-21 18:55:00', 5100, NULL,                                         '2026-10-21 18:55:00'),
    (5,  1, 5,  3,    'manual',    '2026-10-23', NULL,                  NULL,                  5400, 'Offline in der Bibliothek gelernt',          '2026-10-24 09:00:00'),
    (6,  1, 3,  4,    'stopwatch', '2026-10-26', '2026-10-26 18:00:00', '2026-10-26 19:45:00', 5400, NULL,                                         '2026-10-26 19:45:00'),
    (7,  1, 4,  5,    'stopwatch', '2026-10-27', '2026-10-27 17:00:00', '2026-10-27 18:30:00', 5400, NULL,                                         '2026-10-27 18:30:00'),
    (8,  1, 5,  6,    'stopwatch', '2026-10-28', '2026-10-28 16:10:00', '2026-10-28 17:40:00', 5400, NULL,                                         '2026-10-28 17:40:00'),
    (9,  1, 5,  NULL, 'manual',    '2026-10-28', NULL,                  NULL,                  2700, 'Ungeplant: Scheduling-Aufgaben wiederholt',  '2026-10-28 21:00:00'),
    (10, 2, 13, 12,   'stopwatch', '2026-10-27', '2026-10-27 09:00:00', '2026-10-27 10:20:00', 4800, NULL,                                         '2026-10-27 10:20:00'),
    (11, 2, 14, NULL, 'manual',    '2026-10-25', NULL,                  NULL,                  1800, 'Karteikarten zur Kostenrechnung',            '2026-10-25 20:00:00');
