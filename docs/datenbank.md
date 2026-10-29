# Datenbank – Einrichtung und Teamregeln

Jedes Teammitglied hat in XAMPP seine **eigene lokale MariaDB**. Gemeinsam ist nur der **Bauplan** im Repository:

| Datei | Zweck |
|---|---|
| `sql/schema.sql` | Tabellenstruktur – die einzige verbindliche Quelle |
| `sql/seed.sql` | Synthetische Testdaten (DSGVO-konform) |
| `sql/reset.php` | Löscht die lokale Datenbank und baut sie aus schema + seed neu auf |
| `.env.example` | Vorlage für die Zugangsdaten (wird committet) |
| `.env` | Deine lokalen Zugangsdaten (wird **nicht** committet) |
| `config/database.php` | Liest `.env` und liefert die PDO-Verbindung (`getDatabaseConnection()`) |

Wir arbeiten mit der **Reset-Strategie**: `schema.sql` beschreibt immer den vollständigen Endzustand. Es gibt keine Migrationen – lokal liegen ohnehin nur Testdaten in der Datenbank.

---

## Ersteinrichtung

1. In XAMPP **Apache** und **MySQL** starten.
2. Im Projektordner die Vorlage kopieren:

   ```bash
   cp .env.example .env
   ```

   Windows (Eingabeaufforderung): `copy .env.example .env`

   Die Werte passen zur XAMPP-Standardinstallation (`root` ohne Passwort). Wer ein Passwort gesetzt hat, trägt es in `DB_PASS` ein.

3. Datenbank aufbauen – mit dem PHP von XAMPP:

   ```bash
   /Applications/XAMPP/xamppfiles/bin/php sql/reset.php
   ```

   Windows: `C:\xampp\php\php.exe sql\reset.php`

4. In phpMyAdmin (`http://localhost/phpmyadmin`) erscheint die Datenbank `lernzeit_manager`.

## Nach jedem `git pull`

Hat sich etwas in `sql/` geändert, einfach erneut ausführen:

```bash
/Applications/XAMPP/xamppfiles/bin/php sql/reset.php
```

**Achtung:** Der Reset löscht alle lokalen Daten in `lernzeit_manager`. Was du zum Testen brauchst, gehört deshalb in `seed.sql`.

## Test-Login

Alle Nutzer aus `seed.sql` (`testuser@iu-demo.de`, `testuser2@iu-demo.de`) haben das Passwort `Lernzeit-Test-2026`. Es ist ein reines Testpasswort und darf nirgends sonst verwendet werden.

---

## Teamregeln

1. **Strukturänderungen nur über `schema.sql`.** Neue Tabelle oder Spalte → `schema.sql` (und bei Bedarf `seed.sql`) auf einem eigenen Branch anpassen → Pull Request. Der Code Owner prüft den PR.
2. **phpMyAdmin nur zum Anschauen und Ausprobieren.** Was dort geändert wird, existiert nur auf dem eigenen Rechner und ist beim nächsten Reset weg.
3. **Keine echten personenbezogenen Daten** in `seed.sql` – nur `@iu-demo.de`-Adressen und erfundene Namen.
4. **Namenskonvention:** Tabellen und Spalten in `snake_case`, Tabellennamen im Plural (`learning_goals`).
5. **Format der SQL-Dateien:** Jede Anweisung endet mit `;` am Zeilenende, Kommentare beginnen mit `--`. Danach richtet sich `reset.php` beim Zerlegen.
6. **Kein Datenbankname in den SQL-Dateien.** Den legt `reset.php` aus der `.env` fest.

## Zeiterfassung (`time_entries`)

Grundlage für Stoppuhr (#4), Soll-Ist-Dashboard (#6) und manuelle Nachbuchung (#9).

- **Gebucht wird immer auf ein Modul** (`monthly_goal_id` → `monthly_goals`). Das Semester (`learning_goals`) ist nur eine Organisationseinheit und ergibt sich über den Join.
- `learning_slot_id` verknüpft optional mit dem geplanten Timeslot. Wird der Slot gelöscht, bleibt die Buchung erhalten (`ON DELETE SET NULL`).
- `source` unterscheidet `stopwatch` und `manual`. `entry_date` ist der Lerntag, über den das Dashboard summiert.
- `tracked_seconds` ist immer die **Netto-Lernzeit** ohne Pausen.

Zustände einer Stoppuhr:

| Zustand | `ended_at` | `resumed_at` | `tracked_seconds` |
|---|---|---|---|
| läuft | `NULL` | Zeitpunkt des letzten Starts/Weiter | Summe bis zur letzten Pause |
| pausiert | `NULL` | `NULL` | Summe bis jetzt |
| beendet | gesetzt | `NULL` | endgültige Netto-Zeit |

Bei **Pause** und **Stopp** addiert das Backend `ended − resumed_at` auf `tracked_seconds` und setzt `resumed_at` auf `NULL`; bei **Weiter** wird `resumed_at` auf jetzt gesetzt.

Die Datenbank sichert zusätzlich ab:

- Pro Nutzer ist höchstens **eine Stoppuhr offen** (läuft oder pausiert) – über die berechnete Spalte `open_flag` und den UNIQUE-Index `uq_time_entries_open_stopwatch`. Ein zweiter Start schlägt mit einem Duplicate-Key-Fehler fehl.
- Eine manuelle Buchung hat keine Zeitstempel und `tracked_seconds > 0`.
- `ended_at` liegt nicht vor `started_at`; `resumed_at` ist nur bei offener Stoppuhr gesetzt.

## Erledigt-Status und Inaktivität

Vorbereitete Spalten für die folgenden Stories:

| Spalte | Story | Bedeutung |
|---|---|---|
| `learning_goals.completed_at` | #5 | Datum, an dem das Semester als erledigt markiert wurde; `NULL` = offen |
| `monthly_goals.completed_at` | #5 | Datum, an dem das Modul als erledigt markiert wurde; `NULL` = offen |
| `users.last_login_at` | #7, #11 | Zeitpunkt des letzten Logins – Ausgangspunkt für „seit dem letzten Besuch verpasst“ |
| `users.last_inactivity_mail_at` | #11 | Zeitpunkt der letzten Inaktivitäts-Mail, damit nicht mehrfach erinnert wird |

Verpasste Lernzeiten (#7) brauchen keine eigene Spalte: Es sind vergangene `learning_slots` ohne zugehörigen Eintrag in `time_entries`.

## Versionen und Zeichensatz

- **MariaDB 10.4** (wie in XAMPP 8.2, z. B. 10.4.28) – die CI testet mit derselben Hauptversion.
- **PHP 8.2**
- Zeichensatz **`utf8mb4`**, Collation **`utf8mb4_unicode_ci`** – fest im Schema und in der Verbindung gesetzt.

## Qualitätssicherung (GitHub Actions)

Bei jedem Pull Request mit Änderungen an `sql/`, `config/` oder `.env.example` startet der Workflow `.github/workflows/database.yml`. Er legt eine frische MariaDB an, führt `sql/reset.php` aus und prüft, ob Testdaten vorhanden sind. Ist der Check rot, wird nicht gemergt.

## Sicherheit

Das Projekt liegt in `htdocs` und ist damit über den Browser erreichbar. Die `.htaccess`-Dateien sperren deshalb `.env` und alle anderen versteckten Dateien sowie die Ordner `sql/` und `config/`. `reset.php` läuft zusätzlich nur über die Kommandozeile und verweigert bei `APP_ENV=production` den Reset.
