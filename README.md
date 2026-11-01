# ISEF01-Lernzeit-Manager

Repo für das Modul Projekt: Software Engineering (ISEF01). Entwicklung eines Lernzeit-Managers.

## 🛠️ XAMPP einrichten
[XAMPP-Einrichtungsanleitung](docs/XAMPP_ANLEITUNG.md) – Schritt-für-Schritt-Anleitung für Apache, MariaDB & XAMPP-Workflow

## 💾 Datenbank einrichten

1. `cp .env.example .env`
2. In XAMPP MySQL starten und `/Applications/XAMPP/xamppfiles/bin/php sql/reset.php` ausführen (Windows: `C:\xampp\php\php.exe sql\reset.php`)

Nach jedem `git pull` mit Änderungen in `sql/` den Reset erneut ausführen. Details und Teamregeln: [docs/datenbank.md](docs/datenbank.md)

## ✅ Unit-Tests ausführen

```bash
/Applications/XAMPP/xamppfiles/bin/php tools/phpunit.phar
```

Windows: `C:\xampp\php\php.exe tools\phpunit.phar`. Vorgehen und Konventionen: [docs/unit-tests.md](docs/unit-tests.md)

## 📜 Ordnerstruktur & Entwicklungsrichtlinien – Lernzeit-Manager

Diese Dokumentation beschreibt die festgelegte Verzeichnisstruktur des Repositories für den **Lernzeit-Manager** (Projekt Software Engineering). Sie dient allen Teammitgliedern als verbindliche Orientierung für das Ablegen von Quellcode, Skripten, Layout-Elementen und Dokumenten im Rahmen von Meilenstein MS 4.

---

### 📁 Repository-Übersicht

```text
lernzeit-manager/
├── api/                     # Asynchrone Endpoints für fetch/AJAX-Anfragen (JSON-Antworten)
├── assets/                  # Statische Frontend-Ressourcen
│   ├── css/                 # Custom CSS-Dateien (z. B. custom.css)
│   ├── js/                  # JavaScript-Logik (z. B. timer.js, profile.js, goals.js)
│   └── img/                 # Bilder, Grafiken, Logos und Icons (Favicons)
├── config/                  # Zentrale Konfigurationsdateien
├── docs/                    # Dokumentation & Anleitungen fürs Team (z. B. XAMPP-Guide, goals.md, timer.md)
├── includes/                # Wiederverwendbare HTML/PHP-Layout-Bausteine (Header, Footer, Nav)
├── sql/                     # Datenbank-Skripte für MariaDB
├── src/                     # Objektorientierte PHP-Backend-Logik (OOP)
│   ├── Classes/             # Fachliche Klassen & Datenmodelle (z. B. User, ValidationException)
│   ├── Controllers/         # Controller für Anwendungslogik & Eingabevalidierung (z. B. GoalController, TimeTrackingController)
│   ├── DAO/                 # Data Access Objects für Datenbankoperationen via PDO (z. B. GoalDAO, TimeTrackingDAO)
│   └── Database/            # Datenbank-Hilfsklassen / PDO-Wrapper
├── tests/                   # Unit-Tests (PHPUnit), Ordner spiegeln src/
│   ├── bootstrap.php        # Autoloader für src/, wird von PHPUnit geladen
│   └── Unit/                # Testklassen (z. B. Controllers/GoalControllerTest.php, TimeTrackingControllerTest.php)
├── tools/                   # Entwicklungswerkzeuge (phpunit.phar)
├── .gitignore               # Ausschluss von lokalen Temp- und Systemdateien aus Git
├── .htaccess                # Apache: Startseite, keine Verzeichnislisten, versteckte Dateien gesperrt
├── goals.php                # 6-Monats-Lernzielplanung (Grobplanung)
├── index.php                # Zentraler Einstiegspunkt / Dashboard
├── login.php                # Login- & Registrierungsseite
├── logout.php               # Abmelden (nur per POST)
├── phpunit.xml              # PHPUnit-Konfiguration
├── profile.php              # Profilverwaltung (Studiengang, Semester, Name)
├── timer.php                # Stoppuhr & Zeiterfassung
└── README.md                # Projektbeschreibung & Schnellstartanleitung
```

---

### 📌 Beschreibung der Verzeichnisse

#### `api/`

Enthält schlanke PHP-Skripte für die asynchrone Kommunikation mit dem Frontend via `fetch()` API.

- **Merkmal:** Gibt **kein HTML** aus, sondern ausschließlich strukturierte **JSON-Daten**.
- **Aufgabe:** Schnittstelle zwischen den JavaScript-Funktionen (z. B. Stoppuhr) und der PHP-Backend-Logik.

#### `assets/`

Sammelstelle für alle statischen Client-Ressourcen.

- `assets/css/`: Eigenes Styling zur Ergänzung von Bootstrap 5.
- `assets/js/`: Clientseitige Anwendungslogik (Vanilla JS / ES6+), z. B. Timer-Steuerung, Formularvalidierung, AJAX-Calls.
- `assets/img/`: Grafische Elemente, Favicons, Platzhalter.

#### `config/`

Enthält sensible und zentrale Steuerungsskripte.

- `database.php`: Baut die PDO-Verbindung zur MariaDB auf (getrennt für lokale XAMPP-Umgebung und Alwaysdata-Produktivserver).
- `config.php`: Globale Pfade, Session-Handling und Fehler-Reporting.

#### `docs/`

Interne Arbeitsdokumente, Leitfäden und Cheatsheets für das Entwicklerteam (z. B. XAMPP-Einrichtung, VS Code Empfehlungen, Testanleitungen).

#### `includes/`

Wiederverwendbare Layout-Komponenten nach dem **DRY-Prinzip** (_Don't Repeat Yourself_).

- Werden mittels `require_once` in die Hauptseiten (`index.php`, `goals.php` etc.) eingebunden.

#### `sql/`

Verzeichnis für das Datenbankschema und Testdatensätze.

- `schema.sql`: Definiert Tabellen, Primär-/Fremdschlüssel und Constraints.
- `seed.sql`: Beinhaltet synthetische Testdaten gemäß DSGVO-Vorgaben (`testuser@iu-demo.de`).

#### `src/`

Der Kern der objektorientierten PHP 8 Backend-Entwicklung (OOP), aufgeteilt nach dem MVC-/DAO-Muster:

- `src/Classes/`: Enthält fachliche Basisklassen (`User`) sowie exceptionspezifische Klassen (`ValidationException` für anwenderbezogene Fehlermeldungen).
- `src/Controllers/`: Controller zur Koordination von Benutzeranfragen, Formularen und Validierungsregeln vor dem Persistieren (z. B. `GoalController`).
- `src/DAO/`: Data Access Objects zur Kapselung aller Datenbankzugriffe mittels PDO Prepared Statements und Mandantenisolation (z. B. `GoalDAO`).
- `src/Database/`: Hilfsklassen für Datenbank-Aktionen und Query-Building.

#### `tests/` und `tools/`

Unit-Tests mit PHPUnit, siehe [docs/unit-tests.md](docs/unit-tests.md).

- `tests/Unit/`: Eine Testklasse je Klasse aus `src/`, gleiche Ordnerstruktur (`tests/Unit/Controllers/GoalControllerTest.php`).
- `tools/phpunit.phar`: PHPUnit als einzelne Datei, läuft mit dem PHP aus XAMPP ohne Installation.
- Beide Ordner sind per `.htaccess` für den Browser gesperrt.

---

### ⚙️ Namenskonventionen & Richtlinien

1. **Dateinamen & Pfade:**
    - Ordner- und Dateinamen für Webseiten und Skripte werden **kleingeschrieben** (z. B. `goals.php`, `custom.css`).
    - PHP-Klassen in `src/Classes/`, `src/Controllers/` und `src/DAO/` nutzen **PascalCase** entsprechend dem Klassennamen (z. B. `GoalController.php`, `GoalDAO.php`).

2. **Datenbank & SQL:**
    - Tabellennamen und Spalten in MariaDB werden in **snake_case** angelegt (z. B. `learning_goals`, `user_id`, `tracked_seconds`).

3. **Git-Workflow:**
    - Anpassen oder Erweitern der Ordnerstruktur sollte im Team abgesprochen und in dieser Dokumentation nachgeführt werden.
