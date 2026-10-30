# Dokumentation: Stoppuhr & Zeiterfassung (US-4)

Dieses Dokument beschreibt die Architektur, Datenhaltung und Funktionsweise des Zeiterfassungs-Moduls (US-4: Stoppuhr und Zeiterfassung) im **Lernzeit-Manager** (Modul ISEF01 – Software Engineering).

---

## 1. Ziel und Zweck (Warum & Wofür)

### Wofür wurde es implementiert?
* **Live-Zeiterfassung per Stoppuhr:** Studierende können ihre Lernzeit zu einzelnen Modulen / Lernzielen in Echtzeit messen, pausieren, fortsetzen und mit Notizen abspeichern.
* **Neulade- und Ausfallsicherheit (TC-12-03):** Die Stoppuhr wird serverseitig in der Datenbank geführt. Beim Neuladen der Seite oder Schließen des Browsers läuft die Stoppuhr nahtlos weiter und synchronisiert sich präzise mit dem Server.
* **Lernhistorie & Übersicht:** Alle Einheiten werden tabellarisch aufgeführt und liefern aggregierte Kennzahlen (heute gelernt, Gesamtlernzeit, Anzahl Einheiten). (Hinweis: Die manuelle Nachbuchung wird separat in einem späteren Schritt ergänzt.)
* **Verknüpfung mit Tages-/Wochenzielen & Auto-Completion:** Gespeicherte Stoppuhr-Zeiten fließen automatisch als Ist-Zeit in die Tages-, Wochen- und Monatsplanung ([`goals.php`](../goals.php)) ein. Sobald die aufsummierte gestoppte Zeit eines Tagesziels (`learning_slots`) oder Monatsziels (`monthly_goals`) die geplante Soll-Zeit erreicht oder überschreitet, wird das Ziel automatisch als erledigt (`is_completed = 1` bzw. `completed_at`) abgehakt.

### Warum wurde es so implementiert? (Entwurfsprinzipien)
* **Separation of Concerns (SoC) & MVC/DAO-Muster:** Strikte Trennung zwischen Benutzeroberfläche ([`timer.php`](../timer.php)), Controller-/Geschäftslogik ([`src/Controllers/TimeTrackingController.php`](../src/Controllers/TimeTrackingController.php)), Datenzugriffsschicht ([`src/DAO/TimeTrackingDAO.php`](../src/DAO/TimeTrackingDAO.php)) und MariaDB-Datenbank ([`sql/schema.sql`](../sql/schema.sql)).
* **Mandantenisolation & Datenintegrität:** Alle Abfragen und Operationen erzwingen die `user_id` der aktiven Session (`$_SESSION['user_id']`). Ein Benutzer kann weder fremde Stoppuhren steuern noch fremde Zeiteinträge manipulieren.
* **Progressive Enhancement:** Voll funktionsfähig über Standard-Formulare mit CSRF-Schutz (Post/Redirect/Get) sowie dynamische Zeitanzeige via JavaScript ([`assets/js/timer.js`](../assets/js/timer.js)).

---

## 2. Beteiligte Komponenten & Dateien

```text
lernzeit-manager/
├── assets/
│   ├── css/custom.css                     # Stoppuhr-Styling (.timer-display, .timer-card, .timer-pulse-dot)
│   └── js/timer.js                        # Clientseitiger Timer-Ticker, Seitentitel-Update, Modal-Befüllung
├── docs/
│   └── timer.md                           # Diese Dokumentation
├── includes/
│   └── header.php                         # Hauptnavigation mit Link "Stoppuhr"
├── index.php                              # Dashboard mit Schnellzugriff "Stoppuhr"
├── timer.php                              # Hauptansicht für Stoppuhr, Kennzahlen und Zeithistorie
├── src/
│   ├── Controllers/
│   │   └── TimeTrackingController.php     # Geschäftslogik, Plausibilitätsprüfungen, Dauerberechnung
│   └── DAO/
│       └── TimeTrackingDAO.php            # CRUD-Operationen & Stoppuhr-Status auf time_entries
└── tests/Unit/Controllers/
    └── TimeTrackingControllerTest.php     # Unit-Tests mit Mocks, DataProvidern & Exception-Prüfung
```

---

## 3. Datenmodell & Stoppuhr-Zustände

### 3.1 Tabellenstruktur ([sql/schema.sql](../sql/schema.sql))

Zeiteinträge werden in der Tabelle `time_entries` gespeichert:

```sql
CREATE TABLE time_entries (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    monthly_goal_id  INT UNSIGNED NOT NULL,
    learning_slot_id INT UNSIGNED NULL     DEFAULT NULL,
    source           ENUM('stopwatch','manual') NOT NULL,
    entry_date       DATE         NOT NULL,
    started_at       DATETIME     NULL     DEFAULT NULL,
    ended_at         DATETIME     NULL     DEFAULT NULL,
    resumed_at       DATETIME     NULL     DEFAULT NULL,
    tracked_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
    note             VARCHAR(255) NULL     DEFAULT NULL,
    open_flag        TINYINT(1) AS (IF(source = 'stopwatch' AND ended_at IS NULL, 1, NULL)) PERSISTENT,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_time_entries_open_stopwatch (user_id, open_flag),
    CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_time_entries_monthly_goal FOREIGN KEY (monthly_goal_id) REFERENCES monthly_goals (id) ON DELETE CASCADE,
    CONSTRAINT chk_time_entries_stopwatch CHECK (source <> 'stopwatch' OR started_at IS NOT NULL),
    CONSTRAINT chk_time_entries_range CHECK (ended_at IS NULL OR ended_at >= started_at),
    CONSTRAINT chk_time_entries_resumed CHECK (resumed_at IS NULL OR ended_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 Zustandsautomat der Stoppuhr

| Zustand | `ended_at` | `resumed_at` | `tracked_seconds` | Bedeutung |
|---|---|---|---|---|
| **Läuft** | `NULL` | Timestamp | Vorherige Sekunden | Zeit läuft; aktuelle Gesamtzeit = `tracked_seconds + (now - resumed_at)` |
| **Pausiert** | `NULL` | `NULL` | Aufsummiert | Timer steht; `tracked_seconds` enthält die bisherige Netto-Lernzeit |
| **Beendet** | Timestamp | `NULL` | Endgültig | Session abgeschlossen und in der Lernhistorie persistiert |

* **Single-Timer-Garantie:** Pro Nutzer kann über die generierte Spalte `open_flag` und den Index `uq_time_entries_open_stopwatch` zu jedem Zeitpunkt höchstens eine Stoppuhr offen sein. Ein zweiter gleichzeitiger Start wird verhindert.
* **Pause / Weiter:** Beim Pausieren werden die seit `resumed_at` vergangenen Sekunden auf `tracked_seconds` addiert und `resumed_at` wird geleert. Beim Fortsetzen wird `resumed_at = now()` gesetzt.
* **Automatisches Pausieren beim Speichern:** Klickt der Nutzer auf *„Stopp & Speichern“*, pausiert die Stoppuhr sofort (im UI-Ticker und via Server-Synchronisation), damit die Eingabe von Notizen die gemessene Lernzeit nicht verfälscht. Klickt der Nutzer auf *„Weiterlaufen lassen“* oder schließt das Fenster ohne Speichern, wird die Stoppuhr nahtlos fortgesetzt.

---

## 4. Validierungsregeln ([TimeTrackingController.php](../src/Controllers/TimeTrackingController.php))

* **Lernziel / Modul (`goal_id`):** Pflichtfeld (`goal_id > 0`). Muss existieren und dem angemeldeten Benutzer gehören (`goalExistsForUser`). Fehlerfeld: `goal_id`.
* **Startzeit (`started_at`):** Pflichtfeld bei Zeitintervallen. Gültiges DateTime-Format. Fehlerfeld: `started_at`.
* **Endzeit (`ended_at`):** Muss ein gültiges DateTime-Format sein und zeitlich **am oder nach** der Startzeit liegen (`ended_at >= started_at`). Fehlerfeld: `ended_at`.
* **Dauerberechnung (`calculateDuration`):** Berechnet die Differenz in Sekunden (`endDt - startDt`). Wirft bei unplausibler Zeitfolge eine `ValidationException` für `ended_at`.
* **Lernzeit (`tracked_seconds`):** Darf nicht negativ sein und höchstens 24 Stunden (86.400 Sekunden) pro Einzeleintrag umfassen. Fehlerfeld: `tracked_seconds`.
* **Notiz (`note`):** Optional, maximal 255 Zeichen (`TimeTrackingController::MAX_NOTE_LENGTH`). Fehlerfeld: `note`.

---

## 5. Sicherheitsmaßnahmen

1. **Prepared Statements:** Sämtliche SQL-Abfragen in `TimeTrackingDAO` laufen ausnahmslos über PDO Prepared Statements mit strikter Parameter-Bindung.
2. **CSRF-Schutz:** Jedes schreibende Formular auf `timer.php` (Start, Pause, Weiter, Stopp, Verwerfen, Löschen) validiert ein kryptografisches Session-Token über `hash_equals()`.
3. **XSS-Prävention:** Alle dynamischen Ausgaben (Modulnamen, Notizen, Datumsangaben) werden in `timer.php` mit `htmlspecialchars()` maskiert.
4. **Mandantenisolation:** Sämtliche Operationen prüfen und filtern strikt nach `user_id`.

---

## 6. Unit-Tests ([TimeTrackingControllerTest.php](../tests/Unit/Controllers/TimeTrackingControllerTest.php))

Die Testsuite umfasst 55 isolierte Tests (125 Assertions) ohne Datenbankzugriff:
* **Pflichtfeldprüfungen & Grenzwerte:** `#[DataProvider]` für fehlende/ungültige `goal_id`, ungültige Start-/Endzeiten, Notizlängen und negative Zeiten.
* **Plausibilitätsprüfung:** `lehntEndzeitVorStartzeitAb` verifiziert, dass verkehrte Zeitfolgen abgewiesen werden.
* **Dauerberechnung:** Verifiziert `calculateDuration` über mehrere Szenarien (exakte Minuten, über Mitternacht, HTML-Datetimes).
* **Zustandsübergänge der Stoppuhr:** Testet Starten, Pausieren, Fortsetzen, Stoppen, Verwerfen und das Verhindern von Mehrfachstarts.
* **Auto-Completion & Soll-Ist-Abgleich:** `markiertZielAlsErledigtWennSollzeitErreicht`, `markiertZielNichtAlsErledigtWennSollzeitNochNichtErreicht`, `markiertMonatszielAlsErledigtWennAufsummierteZeitSollzeitUeberschreitet` sowie `bewertetErreichenDerSollzeitKorrekt` prüfen, ob Ziele bei Erreichen oder Überschreiten der geplanten Soll-Zeit automatisch als erledigt markiert werden.
* **Formatierungsfunktionen:** Prüft `formatSecondsToTime` (`00:00:00`) und `formatSecondsHuman` (`1 Std. 30 Min.`).

