# Unit-Tests – Vorgehen und Teamregeln

Unit-Tests prüfen einzelne PHP-Klassen aus `src/` automatisch: ohne Datenbank, ohne Browser, ohne Session. Sie ergänzen die Akzeptanztests der QS (TC-xx), ersetzen sie nicht. Werkzeug ist **PHPUnit 11**, es liegt als einzelne Datei im Repository und braucht keine Installation.

| Datei / Ordner        | Zweck                                                               |
| --------------------- | ------------------------------------------------------------------- |
| `tools/phpunit.phar`  | PHPUnit 11 als ausführbares Archiv (läuft mit dem PHP aus XAMPP)    |
| `phpunit.xml`         | Konfiguration: Testsuite `Unit`, Bootstrap, Cache-Ordner            |
| `tests/bootstrap.php` | Lädt die Klassen aus `src/` automatisch nach, setzt die Zeitzone    |
| `tests/Unit/`         | Die Tests, Ordner spiegeln `src/` (z. B. `tests/Unit/Controllers/`) |
| `.phpunit.cache/`     | Wird beim Lauf angelegt, steht in `.gitignore`                      |

---

## Tests ausführen

Im Projektordner, mit dem PHP von XAMPP:

```bash
/Applications/XAMPP/xamppfiles/bin/php tools/phpunit.phar
```

Windows: `C:\xampp\php\php.exe tools\phpunit.phar`

| Ziel                      | Aufruf (an den Pfad oben anhängen)              |
| ------------------------- | ----------------------------------------------- |
| Eine Testdatei            | `tests/Unit/Controllers/GoalControllerTest.php` |
| Nur passende Testmethoden | `--filter formatiertMonat`                      |
| Mehr Ausgabe je Test      | `--testdox`                                     |

Grün heißt `OK (n tests, m assertions)`. Bei Rot zeigt PHPUnit Datei, Zeile und den Unterschied zwischen erwartetem und tatsächlichem Wert.

## Was wird getestet, was nicht

| Testen                                                            | Nicht testen (macht die QS im Browser bzw. die CI)        |
| ----------------------------------------------------------------- | --------------------------------------------------------- |
| Eingabevalidierung und Geschäftsregeln in `src/Controllers/`      | DAOs und SQL in `src/DAO/` (brauchen MariaDB)             |
| Statische Hilfsmethoden (z. B. `GoalController::formatYearMonth`) | Seiten in der Projektwurzel (`goals.php`, `login.php`, …) |
| Reine Parser wie `User::parseProfileInput`                        | JSON-Endpunkte in `api/`                                  |
| Berechnungen (Budgets, Prozentwerte, Monatsfenster)               | JavaScript in `assets/js/`                                |

Faustregel: Alles, was eine `ValidationException` wirft oder einen Wert berechnet, bekommt einen Test. Alles, was eine Datenbank, `$_SESSION` oder HTML braucht, nicht.

**Datenbank ersetzen:** Controller bekommen ihr DAO per Konstruktor. Im Test wird es durch einen Mock ersetzt, der feste Werte liefert:

```php
$dao = $this->createMock(GoalDAO::class);
$dao->method('findByIdAndUserId')->willReturn(['id' => 1, 'start_date' => '2026-10-01', 'end_date' => '2027-03-31']);
$controller = new GoalController($dao);
```

## Konventionen

1. **Ort und Name:** Ein Test je Klasse, Ordner wie in `src/`, Dateiname `<Klasse>Test.php`
   (`src/Controllers/GoalController.php` → `tests/Unit/Controllers/GoalControllerTest.php`). Namespace `Tests\Unit\<Ordner>`.
2. **Methodennamen** auf Deutsch, beschreiben das erwartete Verhalten: `lehntLeerenTitelAb`, `rundetZielstundenAufZweiStellen`. Attribut `#[Test]` statt Präfix `test`.
3. **Aufbau** jeder Methode: Vorbereiten, Ausführen, Prüfen. Gemeinsame Vorbereitung in `setUp()`.
4. **Grenzwerte** (leer, Minimum, Maximum, Komma statt Punkt, falscher Typ) als `#[DataProvider]` mit sprechenden Schlüsseln, nicht als Kopien der Testmethode.
5. **Fehlerfälle:** Bei `ValidationException` immer `getField()` und/oder die Meldung prüfen, damit das Formular das richtige Feld rot markiert.
6. **Keine Umgebung:** Kein `getDatabaseConnection()`, keine `.env`, kein `session_start()`, kein `date()` ohne feste Werte. Was Zeit braucht, bekommt den Zeitpunkt als Parameter oder festen Testwert.
7. **Formatierung** wie im übrigen PHP-Code (`.editorconfig`, 4 Leerzeichen, `declare(strict_types=1)`).

Vorlage zum Kopieren: [`tests/Unit/Controllers/GoalControllerTest.php`](../tests/Unit/Controllers/GoalControllerTest.php).

## Im Arbeitsablauf

- **Vor dem Pull Request** laufen die Tests lokal grün (Punkt in der Definition of Done).
- **Neue oder geänderte Validierungs- bzw. Geschäftslogik** bringt ihre Tests im selben Pull Request mit.
- **GitHub Actions:** Der Workflow `.github/workflows/tests.yml` führt bei jedem Pull Request und auf `main` alle Tests aus. Ist der Check rot, wird nicht gemergt (wie beim Datenbank-Workflow).
- **Roter Test:** Erst klären, ob der Code oder der Test falsch ist. Tests werden nicht gelöscht oder übersprungen, um grün zu werden. Ändert sich eine Geschäftsregel bewusst, wird der Test mit angepasst.
- **Aufwand:** Zeit für Tests zählt zum **Aufwand Ist (h)** des Issues (siehe [CONTRIBUTING.md](../CONTRIBUTING.md)).
- **Bugs:** Wird ein Bug behoben, kommt zuerst ein Test, der ihn reproduziert, dann die Korrektur.

## Vorhandene Tests

| Testklasse                                      | Geprüfte Methoden                                                                                                                                    |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/Unit/Controllers/GoalControllerTest.php`         | `formatYearMonth`, `validateYearMonth`, `validateAndParseInput`, `validateAndParseMonthlyGoalInput`, `saveMonthlyBudget`, `buildMonthlyPlanningData` |
| `tests/Unit/Controllers/TimeTrackingControllerTest.php` | `calculateDuration`, `validateAndParseInput`, `startStopwatch`, `pauseStopwatch`, `resumeStopwatch`, `stopAndSaveStopwatch`, `evaluateAndCompleteGoals`, `hasReachedTargetTime`, `getCurrentStopwatch`, `formatSecondsToTime`, `formatSecondsHuman` |
| `tests/Unit/Classes/UserTest.php`                       | `parseProfileInput`, `isLoggedIn`, `currentNavName`, `currentShortNavName`                                                                           |

Zwei Sonderfälle, die in den Tests gelöst sind und als Muster dienen:

- **`$_SESSION`:** Methoden wie `currentShortNavName` lesen aus `$_SESSION`. Im Test wird kein `session_start()` aufgerufen, sondern `$_SESSION` in `setUp()` als leeres Array gesetzt und in `tearDown()` wieder entfernt. Jeder Test befüllt nur die Schlüssel, die er braucht.
- **`date()`:** `buildMonthlyPlanningData` nutzt den aktuellen Kalendermonat nur als Fallback. Die Tests übergeben immer einen gültigen Monat und feste Lernziel-Daten, nur der Fallback-Test vergleicht gegen `date('Y-m')`. Neue Logik, die von „heute“ abhängt, bekommt den Zeitpunkt besser als Parameter.

Noch ohne Tests: `GoalController::createGoal`, `updateGoal`, `deleteGoal` und die Monatsziel-Varianten (reine Weiterleitung an das DAO) sowie `User::register`, `login`, `updateProfile` (brauchen Datenbank bzw. Session).
