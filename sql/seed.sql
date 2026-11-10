-- Lernzeit-Manager – synthetische Testdaten (DSGVO-konform, keine echten Personen)
--
-- Wird von sql/reset.php nach schema.sql eingespielt (auf Alwaysdata per phpMyAdmin-Import).
-- Test-Login für alle Testnutzer: Passwort "Lernzeit-Test-2026" (siehe docs/datenbank.md)
-- Jede Anweisung endet mit einem Semikolon am Zeilenende (wichtig für reset.php).
--
-- Alle Datumswerte sind RELATIV zum Zeitpunkt des Einspielens (CURDATE()/NOW()).
-- Dadurch passen Semester, aktuelle Woche, Zeitplan-Status und Inaktivitäts-Hinweis immer zu "heute".
-- Die Werte werden beim Import einmal berechnet: Für eine Vorführung also kurz vorher neu einspielen.
--
-- Testnutzer und ihre Situation:
--   1 testuser@iu-demo.de       Musterstudent: laufendes Semester, im Zeitplan, alle Funktionen mit Daten
--   2 testuser2@iu-demo.de      Leicht im Verzug, pausierte Stoppuhr
--   3 leer@iu-demo.de           Neu registriert: keine Daten, leere Zustände, erster Login
--   4 verzug@iu-demo.de         Im Verzug, seit 9 Tagen nicht angemeldet -> Inaktivitäts-Hinweis
--   5 ueberplant@iu-demo.de     Überplanung: Monatsbudget, Semesterziel und Zwischenziel überschritten, Timeslot-Kollision
--   6 abgeschlossen@iu-demo.de  Semesterpause: letztes Semester abgeschlossen, nächstes noch nicht gestartet

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Bezugsdaten
-- ---------------------------------------------------------------------------

SET @heute = CURDATE();
SET @jetzt = NOW();
-- Erster Tag des aktuellen Monats
SET @m0 = @heute - INTERVAL (DAYOFMONTH(@heute) - 1) DAY;

-- Planungsmonate (YYYY-MM) relativ zum aktuellen Monat: @p_2 = vor zwei Monaten, @p0 = aktuell, @p1 = nächster
-- Nur zum Einfügen verwenden, nicht in WHERE-Vergleichen: Variablen tragen die Kollation der Verbindung,
-- die von der Tabellenkollation abweichen kann ("Illegal mix of collations").
SET @p_6 = DATE_FORMAT(@m0 - INTERVAL 6 MONTH, '%Y-%m');
SET @p_5 = DATE_FORMAT(@m0 - INTERVAL 5 MONTH, '%Y-%m');
SET @p_4 = DATE_FORMAT(@m0 - INTERVAL 4 MONTH, '%Y-%m');
SET @p_3 = DATE_FORMAT(@m0 - INTERVAL 3 MONTH, '%Y-%m');
SET @p_2 = DATE_FORMAT(@m0 - INTERVAL 2 MONTH, '%Y-%m');
SET @p_1 = DATE_FORMAT(@m0 - INTERVAL 1 MONTH, '%Y-%m');
SET @p0 = DATE_FORMAT(@m0, '%Y-%m');
SET @p1 = DATE_FORMAT(@m0 + INTERVAL 1 MONTH, '%Y-%m');
SET @p2 = DATE_FORMAT(@m0 + INTERVAL 2 MONTH, '%Y-%m');
SET @p3 = DATE_FORMAT(@m0 + INTERVAL 3 MONTH, '%Y-%m');
SET @p4 = DATE_FORMAT(@m0 + INTERVAL 4 MONTH, '%Y-%m');

-- Laufendes Semester (6 Monate): begann vor zwei Monaten
SET @sem_start = @m0 - INTERVAL 2 MONTH;
SET @sem_end = @m0 + INTERVAL 4 MONTH - INTERVAL 1 DAY;

-- Nutzer 6: vergangenes Semester bis Ende letzten Monats, nächstes beginnt in zwei Monaten (Semesterpause)
SET @alt_start = @m0 - INTERVAL 6 MONTH;
SET @alt_end = @m0 - INTERVAL 1 DAY;
SET @neu_start = @m0 + INTERVAL 2 MONTH;
SET @neu_end = @m0 + INTERVAL 8 MONTH - INTERVAL 1 DAY;

-- Nutzer 5: Tag für die Warnungs-Beispiele, nahe heute, aber sicher im aktuellen Monat
SET @basis5 = LEAST(@heute + INTERVAL 1 DAY, LAST_DAY(@heute) - INTERVAL 3 DAY);

-- ---------------------------------------------------------------------------
-- Nutzer
-- ---------------------------------------------------------------------------

INSERT INTO users (id, email, password_hash, display_name, study_program, semester, last_login_at, created_at) VALUES
    (1, 'testuser@iu-demo.de',      '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Max Mustermann',   'Informatik',            3,    @jetzt - INTERVAL 1 DAY,                    TIMESTAMP(@sem_start - INTERVAL 3 DAY, '08:00:00')),
    (2, 'testuser2@iu-demo.de',     '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Erika Musterfrau', 'Wirtschaftsinformatik', 2,    @jetzt - INTERVAL 1 DAY,                    TIMESTAMP(@sem_start - INTERVAL 2 DAY, '09:30:00')),
    -- Nutzer 3 wie frisch registriert: kein Name, kein Profil, noch nie angemeldet, keine weiteren Daten
    (3, 'leer@iu-demo.de',          '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', '',                 NULL,                    NULL, NULL,                                       @jetzt - INTERVAL 1 DAY),
    (4, 'verzug@iu-demo.de',        '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Jonas Weber',      'Informatik',            4,    TIMESTAMP(@heute - INTERVAL 9 DAY, '20:15:00'), TIMESTAMP(@sem_start - INTERVAL 5 DAY, '11:00:00')),
    (5, 'ueberplant@iu-demo.de',    '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Lena Schmidt',     'Wirtschaftsinformatik', 5,    @jetzt - INTERVAL 2 HOUR,                   TIMESTAMP(@sem_start - INTERVAL 4 DAY, '14:00:00')),
    (6, 'abgeschlossen@iu-demo.de', '$2y$10$crH3Dq6gADtWjO70Kz8pAuiHFx9jXyAb8jUCaegA9DjAWhEjQRd26', 'Nina Schulz',      'Informatik',            3,    @jetzt - INTERVAL 3 DAY,                    TIMESTAMP(@alt_start - INTERVAL 7 DAY, '10:00:00'));

-- ---------------------------------------------------------------------------
-- Semesterziele (6-Monats-Planung)
-- ---------------------------------------------------------------------------

INSERT INTO learning_goals (id, user_id, title, description, target_hours, start_date, end_date) VALUES
    (1, 1, CONCAT('3. Semester – Informatik (', DATE_FORMAT(@sem_start, '%m/%Y'), '–', DATE_FORMAT(@sem_end, '%m/%Y'), ')'),
        'Module: Software Engineering, Datenbanken, Betriebssysteme', 180.00, @sem_start, @sem_end),
    (2, 1, CONCAT('4. Semester – Informatik (', DATE_FORMAT(@sem_end + INTERVAL 1 DAY, '%m/%Y'), '–', DATE_FORMAT(@sem_end + INTERVAL 6 MONTH, '%m/%Y'), ')'),
        'Module: IT-Sicherheit, Web-Programmierung', 150.00, @sem_end + INTERVAL 1 DAY, @sem_end + INTERVAL 6 MONTH),
    (3, 2, CONCAT('2. Semester – Wirtschaftsinformatik (', DATE_FORMAT(@sem_start, '%m/%Y'), '–', DATE_FORMAT(@sem_end, '%m/%Y'), ')'),
        'Module: Mathematik, Betriebswirtschaftslehre', 120.00, @sem_start, @sem_end),
    (4, 4, CONCAT('4. Semester – Informatik (', DATE_FORMAT(@sem_start, '%m/%Y'), '–', DATE_FORMAT(@sem_end, '%m/%Y'), ')'),
        'Module: Algorithmen und Datenstrukturen, Statistik, Projektmanagement', 150.00, @sem_start, @sem_end),
    -- Zielwert 100 h, die Zwischenziele summieren sich aber auf 148 h (Überplanung des Semesterziels)
    (5, 5, CONCAT('5. Semester – Wirtschaftsinformatik (', DATE_FORMAT(@sem_start, '%m/%Y'), '–', DATE_FORMAT(@sem_end, '%m/%Y'), ')'),
        'Module: Marketing, Controlling, Wirtschaftsrecht', 100.00, @sem_start, @sem_end),
    (6, 6, CONCAT('2. Semester – Informatik (', DATE_FORMAT(@alt_start, '%m/%Y'), '–', DATE_FORMAT(@alt_end, '%m/%Y'), ')'),
        'Module: Programmierung, Mathematik I', 145.00, @alt_start, @alt_end),
    (7, 6, CONCAT('3. Semester – Informatik (', DATE_FORMAT(@neu_start, '%m/%Y'), '–', DATE_FORMAT(@neu_end, '%m/%Y'), ')'),
        'Module: Software Engineering, Datenbanken', 160.00, @neu_start, @neu_end);

-- ---------------------------------------------------------------------------
-- Monatsbudgets
-- ---------------------------------------------------------------------------

INSERT INTO monthly_budgets (user_id, plan_month, budget_hours) VALUES
    (1, @p_2, 25.00), (1, @p_1, 40.00), (1, @p0, 40.00), (1, @p1, 35.00), (1, @p2, 25.00), (1, @p4, 30.00),
    (2, @p_1, 22.00), (2, @p0, 25.00),
    (4, @p0, 30.00),
    -- Nutzer 5: im aktuellen Monat sind 32 h geplant, das Budget beträgt nur 20 h
    (5, @p_1, 35.00), (5, @p0, 20.00), (5, @p1, 35.00),
    (6, @p2, 30.00), (6, @p3, 30.00);

-- ---------------------------------------------------------------------------
-- Monatliche Zwischenziele (Module je Monat)
-- ---------------------------------------------------------------------------

INSERT INTO monthly_goals (user_id, goal_id, plan_month, title, description, planned_hours) VALUES
    -- Nutzer 1: Musterstudent
    (1, 1, @p_2, 'Modul Software Engineering', 'Kapitel 1–2: Grundlagen & Vorgehensmodelle',     12.00),
    (1, 1, @p_2, 'Modul Datenbanken',          'ER-Modellierung & Relationenschema',             10.00),
    (1, 1, @p_1, 'Modul Software Engineering', 'Kapitel 3–4: Anforderungsanalyse & UML',         14.00),
    (1, 1, @p_1, 'Modul Datenbanken',          'SQL-Joins & Normalisierung (1NF–3NF)',           12.00),
    (1, 1, @p_1, 'Modul Betriebssysteme',      'Prozesse, Threads & Scheduling',                 10.00),
    (1, 1, @p0,  'Modul Software Engineering', 'Kapitel 5–6: Softwarearchitektur & Tests',       14.00),
    (1, 1, @p0,  'Modul Datenbanken',          'Transaktionen & Indexierung',                    12.00),
    (1, 1, @p0,  'Modul Betriebssysteme',      'Speicherverwaltung & Dateisysteme',              12.00),
    (1, 1, @p1,  'Modul Software Engineering', 'Kapitel 7–8: Qualitätssicherung & Wartung',      12.00),
    (1, 1, @p1,  'Modul Datenbanken',          'NoSQL & verteilte Datenbanken',                  10.00),
    (1, 1, @p1,  'Modul Betriebssysteme',      'Rechnernetze & Sicherheit',                      12.00),
    (1, 1, @p2,  'Modul Software Engineering', 'Klausurvorbereitung mit Altklausuren',           12.00),
    (1, 1, @p2,  'Modul Betriebssysteme',      'Prüfungsvorbereitung',                           10.00),
    (1, 1, @p3,  'Modul Software Engineering', 'Projektbericht abschließen',                      6.00),
    (1, 2, @p4,  'Modul IT-Sicherheit',        'Schutzziele, Kryptografie-Grundlagen',           15.00),
    (1, 2, @p4,  'Modul Web-Programmierung',   'HTML, CSS & JavaScript-Grundlagen',              12.00),
    -- Nutzer 2: leicht im Verzug
    (2, 3, @p_2, 'Modul Mathematik',               'Folgen und Reihen',                          10.00),
    (2, 3, @p_2, 'Modul Betriebswirtschaftslehre', 'Grundlagen der Unternehmensführung',          8.00),
    (2, 3, @p_1, 'Modul Mathematik',               'Lineare Algebra Grundlagen',                 12.00),
    (2, 3, @p_1, 'Modul Betriebswirtschaftslehre', 'Kosten- und Leistungsrechnung',              10.00),
    (2, 3, @p0,  'Modul Mathematik',               'Differentialrechnung',                       12.00),
    (2, 3, @p0,  'Modul Betriebswirtschaftslehre', 'Investition und Finanzierung',               10.00),
    (2, 3, @p1,  'Modul Mathematik',               'Integralrechnung',                           12.00),
    (2, 3, @p1,  'Modul Betriebswirtschaftslehre', 'Marketing-Grundlagen',                        8.00),
    (2, 3, @p2,  'Modul Mathematik',               'Klausurvorbereitung',                        10.00),
    (2, 3, @p2,  'Modul Betriebswirtschaftslehre', 'Klausurvorbereitung',                         6.00),
    -- Nutzer 4: im Verzug
    (4, 4, @p_2, 'Modul Algorithmen und Datenstrukturen', 'Laufzeitanalyse & Sortierverfahren',  10.00),
    (4, 4, @p_2, 'Modul Statistik',                       'Deskriptive Statistik',                8.00),
    (4, 4, @p_1, 'Modul Algorithmen und Datenstrukturen', 'Bäume & Hashing',                     12.00),
    (4, 4, @p_1, 'Modul Statistik',                       'Wahrscheinlichkeitsrechnung',         10.00),
    (4, 4, @p_1, 'Modul Projektmanagement',               'Projektphasen & Projektauftrag',       8.00),
    (4, 4, @p0,  'Modul Algorithmen und Datenstrukturen', 'Graphenalgorithmen',                  12.00),
    (4, 4, @p0,  'Modul Statistik',                       'Verteilungen & Schätzverfahren',      10.00),
    (4, 4, @p0,  'Modul Projektmanagement',               'Netzplantechnik & Risikomanagement',   8.00),
    (4, 4, @p1,  'Modul Algorithmen und Datenstrukturen', 'Dynamische Programmierung',           12.00),
    (4, 4, @p1,  'Modul Statistik',                       'Hypothesentests',                     10.00),
    (4, 4, @p1,  'Modul Projektmanagement',               'Agile Methoden',                       8.00),
    (4, 4, @p2,  'Modul Algorithmen und Datenstrukturen', 'Klausurvorbereitung',                 10.00),
    (4, 4, @p2,  'Modul Statistik',                       'Klausurvorbereitung',                  8.00),
    -- Nutzer 5: Überplanung (Summe 148 h bei 100 h Semesterziel; aktueller Monat 32 h bei 20 h Budget)
    (5, 5, @p_2, 'Modul Marketing',        'Marketing-Mix & Marktforschung',                     10.00),
    (5, 5, @p_2, 'Modul Controlling',      'Kostenarten- und Kostenstellenrechnung',             10.00),
    (5, 5, @p_1, 'Modul Marketing',        'Konsumentenverhalten',                               12.00),
    (5, 5, @p_1, 'Modul Controlling',      'Deckungsbeitragsrechnung',                           12.00),
    (5, 5, @p_1, 'Modul Wirtschaftsrecht', 'Vertragsrecht (BGB AT)',                              8.00),
    -- Nur 4 h geplant, aber 6 h Lerneinheiten eingeplant (Zwischenziel überplant)
    (5, 5, @p0,  'Modul Marketing',        'Fallstudie Markenstrategie',                          4.00),
    (5, 5, @p0,  'Modul Controlling',      'Budgetierung & Abweichungsanalyse',                  14.00),
    (5, 5, @p0,  'Modul Wirtschaftsrecht', 'Kaufrecht & Gewährleistung',                         14.00),
    (5, 5, @p1,  'Modul Marketing',        'Online-Marketing',                                   12.00),
    (5, 5, @p1,  'Modul Controlling',      'Kennzahlensysteme',                                  12.00),
    (5, 5, @p1,  'Modul Wirtschaftsrecht', 'Handels- und Gesellschaftsrecht',                     8.00),
    (5, 5, @p2,  'Modul Marketing',        'Klausurvorbereitung',                                10.00),
    (5, 5, @p2,  'Modul Controlling',      'Klausurvorbereitung',                                10.00),
    (5, 5, @p3,  'Modul Controlling',      'Hausarbeit überarbeiten',                             6.00),
    (5, 5, @p3,  'Modul Wirtschaftsrecht', 'Fallbearbeitung üben',                                6.00),
    -- Nutzer 6: vergangenes Semester (komplett erledigt) und kommendes Semester
    (6, 6, @p_6, 'Modul Programmierung', 'Variablen, Kontrollstrukturen & Funktionen',           15.00),
    (6, 6, @p_6, 'Modul Mathematik I',   'Mengenlehre & Logik',                                  12.00),
    (6, 6, @p_5, 'Modul Programmierung', 'Objektorientierung: Klassen & Vererbung',              15.00),
    (6, 6, @p_5, 'Modul Mathematik I',   'Funktionen & Folgen',                                  12.00),
    (6, 6, @p_4, 'Modul Programmierung', 'Collections & Exceptions',                             15.00),
    (6, 6, @p_4, 'Modul Mathematik I',   'Differentialrechnung',                                 12.00),
    (6, 6, @p_3, 'Modul Programmierung', 'Dateien, Streams & Unit-Tests',                        12.00),
    (6, 6, @p_3, 'Modul Mathematik I',   'Integralrechnung',                                     12.00),
    (6, 6, @p_2, 'Modul Programmierung', 'Projektaufgabe',                                       12.00),
    (6, 6, @p_2, 'Modul Mathematik I',   'Lineare Gleichungssysteme',                            10.00),
    (6, 6, @p_1, 'Modul Programmierung', 'Klausurvorbereitung',                                  10.00),
    (6, 6, @p_1, 'Modul Mathematik I',   'Klausurvorbereitung',                                   8.00),
    (6, 7, @p2,  'Modul Software Engineering', 'Kapitel 1–2: Grundlagen & Vorgehensmodelle',     15.00),
    (6, 7, @p2,  'Modul Datenbanken',          'ER-Modellierung & Relationenschema',             12.00),
    (6, 7, @p3,  'Modul Software Engineering', 'Kapitel 3–4: Anforderungsanalyse & UML',         15.00),
    (6, 7, @p3,  'Modul Datenbanken',          'SQL-Joins & Normalisierung',                     12.00);

-- ---------------------------------------------------------------------------
-- Lerneinheiten (Timeslots)
-- Werden über Modultitel und Monat des Termins dem passenden Zwischenziel zugeordnet.
-- ---------------------------------------------------------------------------

CREATE TEMPORARY TABLE seed_slots (
    id           INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    module       VARCHAR(150) NOT NULL,
    slot_date    DATE         NOT NULL,
    start_time   TIME         NOT NULL,
    end_time     TIME         NOT NULL,
    notes        VARCHAR(255) NULL,
    is_completed TINYINT(1)   NOT NULL
);

INSERT INTO seed_slots (id, user_id, module, slot_date, start_time, end_time, notes, is_completed) VALUES
    -- Nutzer 1: letzte Tage erledigt (Slot 6 gestern nur teilweise -> gelbes Häkchen), kommende Tage offen
    (1,  1, 'Modul Software Engineering', @heute - INTERVAL 6 DAY, '18:00:00', '20:00:00', 'Schichtenarchitektur und MVC-Muster zusammenfassen', 1),
    (2,  1, 'Modul Datenbanken',          @heute - INTERVAL 5 DAY, '17:30:00', '19:00:00', 'ACID-Eigenschaften und Isolationsstufen lernen', 1),
    (3,  1, 'Modul Betriebssysteme',      @heute - INTERVAL 4 DAY, '16:00:00', '18:00:00', 'Paging und Seitenersetzung (LRU, FIFO) üben', 1),
    (4,  1, 'Modul Software Engineering', @heute - INTERVAL 3 DAY, '18:00:00', '20:00:00', 'Testpyramide und Unit-Tests mit PHPUnit', 1),
    (5,  1, 'Modul Datenbanken',          @heute - INTERVAL 2 DAY, '17:00:00', '18:30:00', 'B-Baum-Index an Beispielabfragen nachvollziehen', 1),
    (6,  1, 'Modul Betriebssysteme',      @heute - INTERVAL 1 DAY, '16:00:00', '18:00:00', 'Dateisysteme (FAT, ext4) vergleichen', 1),
    (7,  1, 'Modul Software Engineering', @heute + INTERVAL 1 DAY, '18:00:00', '20:00:00', 'Entwurfsmuster: Beobachter und Strategie', 0),
    (8,  1, 'Modul Datenbanken',          @heute + INTERVAL 2 DAY, '17:30:00', '19:00:00', 'Deadlocks und Sperrverfahren', 0),
    (9,  1, 'Modul Betriebssysteme',      @heute + INTERVAL 3 DAY, '10:00:00', '11:30:00', 'Virtueller Speicher: Übungsblatt', 0),
    (10, 1, 'Modul Software Engineering', @heute + INTERVAL 5 DAY, '14:00:00', '16:00:00', 'Komponentendiagramme modellieren', 0),
    (11, 1, 'Modul Datenbanken',          @heute + INTERVAL 6 DAY, '10:00:00', '12:00:00', 'Views und Unterabfragen in MariaDB testen', 0),
    (12, 1, 'Modul Betriebssysteme',      @heute + INTERVAL 8 DAY, '16:00:00', '18:00:00', 'Threads und Synchronisation (Semaphore)', 0),
    (13, 1, 'Modul Software Engineering', @heute + INTERVAL 10 DAY, '18:00:00', '20:00:00', 'Altklausur Kapitel 5 bearbeiten', 0),
    -- Nutzer 2
    (14, 2, 'Modul Mathematik',               @heute - INTERVAL 5 DAY, '09:00:00', '10:30:00', 'Ableitungsregeln üben', 1),
    (15, 2, 'Modul Betriebswirtschaftslehre', @heute - INTERVAL 3 DAY, '14:00:00', '15:00:00', 'Kapitalwertmethode an Beispielen', 1),
    (16, 2, 'Modul Mathematik',               @heute + INTERVAL 1 DAY, '09:00:00', '11:00:00', 'Kurvendiskussion', 0),
    (17, 2, 'Modul Betriebswirtschaftslehre', @heute + INTERVAL 3 DAY, '14:00:00', '15:30:00', 'Annuitätenmethode', 0),
    (18, 2, 'Modul Mathematik',               @heute + INTERVAL 5 DAY, '09:00:00', '10:30:00', 'Extremwertaufgaben', 0),
    -- Nutzer 4: zwei Einheiten vor dem letzten Login erledigt, danach sechs verpasst (an einem Tag zwei Module)
    (19, 4, 'Modul Algorithmen und Datenstrukturen', @heute - INTERVAL 13 DAY, '18:00:00', '20:00:00', 'Breitensuche und Tiefensuche', 1),
    (20, 4, 'Modul Statistik',                       @heute - INTERVAL 11 DAY, '17:00:00', '18:30:00', 'Binomialverteilung', 1),
    (21, 4, 'Modul Algorithmen und Datenstrukturen', @heute - INTERVAL 8 DAY,  '18:00:00', '20:00:00', 'Dijkstra-Algorithmus nachvollziehen', 0),
    (22, 4, 'Modul Statistik',                       @heute - INTERVAL 6 DAY,  '17:00:00', '18:30:00', 'Normalverteilung und z-Werte', 0),
    (23, 4, 'Modul Algorithmen und Datenstrukturen', @heute - INTERVAL 4 DAY,  '16:00:00', '17:30:00', 'Minimale Spannbäume (Kruskal, Prim)', 0),
    (24, 4, 'Modul Projektmanagement',               @heute - INTERVAL 4 DAY,  '18:00:00', '19:30:00', 'Netzplan für Fallbeispiel erstellen', 0),
    (25, 4, 'Modul Statistik',                       @heute - INTERVAL 2 DAY,  '18:00:00', '20:00:00', 'Konfidenzintervalle berechnen', 0),
    (26, 4, 'Modul Algorithmen und Datenstrukturen', @heute - INTERVAL 1 DAY,  '10:00:00', '12:00:00', 'Topologische Sortierung', 0),
    (27, 4, 'Modul Projektmanagement',               @heute + INTERVAL 2 DAY,  '18:00:00', '19:30:00', 'Risikomatrix erstellen', 0),
    (28, 4, 'Modul Algorithmen und Datenstrukturen', @heute + INTERVAL 4 DAY,  '18:00:00', '20:00:00', 'Übungsblatt Graphen', 0),
    -- Nutzer 5: 3 x 2 h Marketing bei 4 h Soll; Controlling und Wirtschaftsrecht überschneiden sich am selben Tag
    (29, 5, 'Modul Marketing',        @basis5,                   '09:00:00', '11:00:00', 'Fallstudie lesen und Zielgruppe bestimmen', 0),
    (30, 5, 'Modul Marketing',        @basis5 + INTERVAL 1 DAY,  '09:00:00', '11:00:00', 'Markenpositionierung ausarbeiten', 0),
    (31, 5, 'Modul Controlling',      @basis5 + INTERVAL 1 DAY,  '14:00:00', '16:00:00', 'Abweichungsanalyse an Beispielbudget', 0),
    (32, 5, 'Modul Wirtschaftsrecht', @basis5 + INTERVAL 1 DAY,  '15:00:00', '17:00:00', 'Fälle zur Gewährleistung lösen', 0),
    (33, 5, 'Modul Marketing',        @basis5 + INTERVAL 2 DAY,  '09:00:00', '11:00:00', 'Präsentation der Fallstudie vorbereiten', 0),
    -- Nutzer 6: Klausurvorbereitung im letzten Monat des vergangenen Semesters (erledigt)
    (34, 6, 'Modul Programmierung', @m0 - INTERVAL 10 DAY, '10:00:00', '12:00:00', 'Altklausur Programmierung', 1),
    (35, 6, 'Modul Mathematik I',   @m0 - INTERVAL 5 DAY,  '10:00:00', '12:00:00', 'Altklausur Mathematik', 1),
    (36, 6, 'Modul Software Engineering', @neu_start + INTERVAL 7 DAY, '18:00:00', '20:00:00', 'Einführungsvorlesung nacharbeiten', 0);

INSERT INTO learning_slots (id, user_id, monthly_goal_id, slot_date, start_time, end_time, notes, is_completed)
SELECT s.id, s.user_id, mg.id, s.slot_date, s.start_time, s.end_time, s.notes, s.is_completed
FROM seed_slots s
JOIN monthly_goals mg
    ON mg.user_id = s.user_id
   AND mg.title = s.module
   AND mg.plan_month = DATE_FORMAT(s.slot_date, '%Y-%m');

-- ---------------------------------------------------------------------------
-- Erfasste Lernzeit, Teil 1: regelmäßige Lerneinheiten je Modul und Monat
-- "Nutzer lernt Modul X im Monat Y in N Einheiten, zusammen H Stunden."
-- Die Einheiten werden gleichmäßig über den Monat verteilt; nur Tage vor heute werden gebucht.
-- Jede dritte Einheit ist eine Nachbuchung, jede zweite Stoppuhr-Einheit enthält 10 Min. Pause.
-- ---------------------------------------------------------------------------

CREATE TEMPORARY TABLE seed_numbers (n INT UNSIGNED NOT NULL);

INSERT INTO seed_numbers (n) VALUES (1), (2), (3), (4), (5), (6), (7), (8), (9), (10), (11), (12);

CREATE TEMPORARY TABLE seed_sessions (
    user_id      INT UNSIGNED NOT NULL,
    module       VARCHAR(150) NOT NULL,
    month_offset INT          NOT NULL,
    hours        DECIMAL(6,2) NOT NULL,
    sessions     INT UNSIGNED NOT NULL,
    day_shift    INT UNSIGNED NOT NULL,
    start_time   TIME         NOT NULL,
    until_date   DATE         NULL
);

INSERT INTO seed_sessions (user_id, module, month_offset, hours, sessions, day_shift, start_time, until_date) VALUES
    -- Nutzer 1: Vormonate vollständig (leicht übererfüllt), aktueller Monat im Plan
    (1, 'Modul Software Engineering', -2, 13.00, 8, 0, '18:00:00', NULL),
    (1, 'Modul Datenbanken',          -2, 11.00, 7, 1, '17:00:00', NULL),
    (1, 'Modul Software Engineering', -1, 15.00, 9, 0, '18:00:00', NULL),
    (1, 'Modul Datenbanken',          -1, 12.50, 8, 1, '17:00:00', NULL),
    (1, 'Modul Betriebssysteme',      -1, 10.50, 7, 2, '16:00:00', NULL),
    (1, 'Modul Software Engineering',  0, 14.00, 9, 0, '18:00:00', NULL),
    (1, 'Modul Datenbanken',           0, 12.00, 8, 1, '17:00:00', NULL),
    (1, 'Modul Betriebssysteme',       0, 12.00, 8, 2, '16:00:00', NULL),
    -- Nutzer 2: Mathematik im Vormonat nicht geschafft, aktueller Monat bei ca. 80 %
    (2, 'Modul Mathematik',               -2, 10.00, 6, 0, '09:00:00', NULL),
    (2, 'Modul Betriebswirtschaftslehre', -2,  8.00, 5, 1, '14:00:00', NULL),
    (2, 'Modul Mathematik',               -1,  8.00, 5, 0, '09:00:00', NULL),
    (2, 'Modul Betriebswirtschaftslehre', -1, 10.00, 6, 1, '14:00:00', NULL),
    (2, 'Modul Mathematik',                0,  9.50, 7, 0, '09:00:00', NULL),
    (2, 'Modul Betriebswirtschaftslehre',  0,  8.00, 6, 1, '14:00:00', NULL),
    -- Nutzer 4: deutlich zu wenig, seit dem letzten Login gar nichts mehr
    (4, 'Modul Algorithmen und Datenstrukturen', -2, 10.00, 6, 0, '18:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Statistik',                       -2,  5.00, 3, 1, '17:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Algorithmen und Datenstrukturen', -1,  6.00, 4, 0, '18:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Statistik',                       -1,  4.00, 3, 1, '17:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Projektmanagement',               -1,  3.00, 2, 2, '18:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Algorithmen und Datenstrukturen',  0,  5.00, 4, 0, '18:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Statistik',                        0,  3.00, 3, 1, '17:00:00', @heute - INTERVAL 9 DAY),
    (4, 'Modul Projektmanagement',                0,  2.00, 2, 2, '18:00:00', @heute - INTERVAL 9 DAY),
    -- Nutzer 5: lernt planmäßig (leicht übererfüllt), nur die Planung ist zu voll
    (5, 'Modul Marketing',        -2, 11.00, 6, 0, '10:00:00', NULL),
    (5, 'Modul Controlling',      -2, 11.00, 6, 1, '14:00:00', NULL),
    (5, 'Modul Marketing',        -1, 14.00, 7, 0, '10:00:00', NULL),
    (5, 'Modul Controlling',      -1, 14.00, 7, 1, '14:00:00', NULL),
    (5, 'Modul Wirtschaftsrecht', -1,  8.00, 5, 2, '16:00:00', NULL),
    (5, 'Modul Controlling',       0, 14.00, 8, 1, '14:00:00', NULL),
    (5, 'Modul Wirtschaftsrecht',  0, 14.00, 8, 2, '16:00:00', NULL),
    -- Nutzer 6: vergangenes Semester vollständig erfüllt
    (6, 'Modul Programmierung', -6, 15.00, 8, 0, '10:00:00', NULL),
    (6, 'Modul Mathematik I',   -6, 12.00, 6, 1, '14:00:00', NULL),
    (6, 'Modul Programmierung', -5, 15.00, 8, 0, '10:00:00', NULL),
    (6, 'Modul Mathematik I',   -5, 12.00, 6, 1, '14:00:00', NULL),
    (6, 'Modul Programmierung', -4, 15.50, 8, 0, '10:00:00', NULL),
    (6, 'Modul Mathematik I',   -4, 12.00, 6, 1, '14:00:00', NULL),
    (6, 'Modul Programmierung', -3, 12.00, 6, 0, '10:00:00', NULL),
    (6, 'Modul Mathematik I',   -3, 12.50, 6, 1, '14:00:00', NULL),
    (6, 'Modul Programmierung', -2, 12.00, 6, 0, '10:00:00', NULL),
    (6, 'Modul Mathematik I',   -2, 10.00, 5, 1, '14:00:00', NULL),
    (6, 'Modul Programmierung', -1,  8.00, 4, 0, '10:00:00', NULL),
    (6, 'Modul Mathematik I',   -1,  6.50, 3, 1, '14:00:00', NULL);

INSERT INTO time_entries (user_id, monthly_goal_id, source, entry_date, started_at, ended_at, tracked_seconds, note, created_at)
SELECT
    x.user_id,
    mg.id,
    x.source,
    x.entry_date,
    IF(x.source = 'stopwatch', TIMESTAMP(x.entry_date, x.start_time), NULL),
    IF(x.source = 'stopwatch', TIMESTAMP(x.entry_date, x.start_time) + INTERVAL (x.minutes + x.pause_minutes) MINUTE, NULL),
    x.minutes * 60,
    x.note,
    TIMESTAMP(x.entry_date, x.start_time) + INTERVAL (x.minutes + x.pause_minutes) MINUTE
FROM (
    SELECT
        d.user_id,
        d.module,
        d.start_time,
        d.until_date,
        d.month_start + INTERVAL (LEAST(d.days_in_month, FLOOR((d.n - 1) * d.days_in_month / d.sessions) + 1 + d.day_shift) - 1) DAY AS entry_date,
        IF(d.n % 3 = 0, 'manual', 'stopwatch') AS source,
        ROUND(d.hours * 60 / d.sessions) AS minutes,
        IF(d.n % 3 <> 0 AND d.n % 2 = 0, 10, 0) AS pause_minutes,
        IF(d.n % 3 = 0, IF(d.n % 2 = 0, 'Skript offline durchgearbeitet', 'Übungsaufgaben auf Papier gelöst'), NULL) AS note
    FROM (
        SELECT s.*, n.n, @m0 + INTERVAL s.month_offset MONTH AS month_start,
               DAY(LAST_DAY(@m0 + INTERVAL s.month_offset MONTH)) AS days_in_month
        FROM seed_sessions s
        JOIN seed_numbers n ON n.n <= s.sessions
    ) d
) x
JOIN monthly_goals mg
    ON mg.user_id = x.user_id
   AND mg.title = x.module
   AND mg.plan_month = DATE_FORMAT(x.entry_date, '%Y-%m')
WHERE x.entry_date < @heute
  AND (x.until_date IS NULL OR x.entry_date < x.until_date);

-- ---------------------------------------------------------------------------
-- Erfasste Lernzeit, Teil 2: Buchungen auf geplante Lerneinheiten
-- Datum und Zwischenziel kommen aus der Lerneinheit.
-- ---------------------------------------------------------------------------

CREATE TEMPORARY TABLE seed_slot_entries (
    slot_id       INT UNSIGNED NOT NULL,
    source        VARCHAR(10)  NOT NULL,
    start_time    TIME         NOT NULL,
    minutes       INT UNSIGNED NOT NULL,
    pause_minutes INT UNSIGNED NOT NULL,
    note          VARCHAR(255) NULL
);

INSERT INTO seed_slot_entries (slot_id, source, start_time, minutes, pause_minutes, note) VALUES
    (1,  'stopwatch', '18:00:00', 120, 10, NULL),
    (2,  'stopwatch', '17:30:00',  90,  0, NULL),
    (3,  'manual',    '16:00:00', 120,  0, 'Offline in der Bibliothek gelernt'),
    (4,  'stopwatch', '18:00:00', 120,  0, NULL),
    (5,  'stopwatch', '17:00:00',  90,  0, NULL),
    -- Gestern nur 75 von 120 geplanten Minuten -> gelbes Häkchen in der Wochenansicht
    (6,  'stopwatch', '16:00:00',  75, 10, 'Früher abgebrochen'),
    (14, 'stopwatch', '09:00:00',  90,  0, NULL),
    (15, 'manual',    '14:00:00',  60,  0, 'In der Mittagspause gelernt'),
    (19, 'stopwatch', '18:00:00', 120,  0, NULL),
    (20, 'stopwatch', '17:00:00',  90,  0, NULL),
    (34, 'stopwatch', '10:00:00', 120,  0, NULL),
    (35, 'stopwatch', '10:00:00', 110, 10, NULL);

INSERT INTO time_entries (user_id, monthly_goal_id, learning_slot_id, source, entry_date, started_at, ended_at, tracked_seconds, note, created_at)
SELECT
    ls.user_id,
    ls.monthly_goal_id,
    ls.id,
    e.source,
    ls.slot_date,
    IF(e.source = 'stopwatch', TIMESTAMP(ls.slot_date, e.start_time), NULL),
    IF(e.source = 'stopwatch', TIMESTAMP(ls.slot_date, e.start_time) + INTERVAL (e.minutes + e.pause_minutes) MINUTE, NULL),
    e.minutes * 60,
    e.note,
    TIMESTAMP(ls.slot_date, e.start_time) + INTERVAL (e.minutes + e.pause_minutes) MINUTE
FROM seed_slot_entries e
JOIN learning_slots ls ON ls.id = e.slot_id;

-- Nutzer 1: zusätzliche, nicht eingeplante Nachbuchung
INSERT INTO time_entries (user_id, monthly_goal_id, source, entry_date, tracked_seconds, note, created_at)
SELECT 1, mg.id, 'manual', @heute - INTERVAL 2 DAY, 45 * 60, 'Ungeplant: Übungsaufgaben wiederholt', TIMESTAMP(@heute - INTERVAL 2 DAY, '21:00:00')
FROM monthly_goals mg
WHERE mg.user_id = 1 AND mg.title = 'Modul Betriebssysteme' AND mg.plan_month = DATE_FORMAT(@heute - INTERVAL 2 DAY, '%Y-%m');

-- Nutzer 2: pausierte Stoppuhr (40 Min. erfasst, weder beendet noch laufend)
INSERT INTO time_entries (user_id, monthly_goal_id, source, entry_date, started_at, ended_at, resumed_at, tracked_seconds, note, created_at)
SELECT 2, mg.id, 'stopwatch', @heute, GREATEST(@jetzt - INTERVAL 75 MINUTE, TIMESTAMP(@heute)), NULL, NULL, 40 * 60, NULL,
       GREATEST(@jetzt - INTERVAL 75 MINUTE, TIMESTAMP(@heute))
FROM monthly_goals mg
WHERE mg.user_id = 2 AND mg.title = 'Modul Mathematik' AND mg.plan_month = DATE_FORMAT(@heute, '%Y-%m');

-- ---------------------------------------------------------------------------
-- Erledigt-Status
-- ---------------------------------------------------------------------------

-- Zwischenziele vergangener Monate, deren Soll erreicht ist, gelten als erledigt (wie die automatische
-- Erledigung beim Stoppen). Abschlussdatum ist der Tag der letzten Buchung.
UPDATE monthly_goals mg
JOIN (
    SELECT monthly_goal_id, SUM(tracked_seconds) AS tracked_seconds, MAX(entry_date) AS last_entry
    FROM time_entries
    WHERE source = 'manual' OR ended_at IS NOT NULL
    GROUP BY monthly_goal_id
) t ON t.monthly_goal_id = mg.id
SET mg.completed_at = t.last_entry
WHERE mg.plan_month < DATE_FORMAT(@heute, '%Y-%m')
  AND t.tracked_seconds >= mg.planned_hours * 3600;

-- Nutzer 6: vergangenes Semester abgeschlossen
UPDATE learning_goals SET completed_at = @alt_end WHERE id = 6;

DROP TEMPORARY TABLE seed_slot_entries;

DROP TEMPORARY TABLE seed_sessions;

DROP TEMPORARY TABLE seed_numbers;

DROP TEMPORARY TABLE seed_slots;
