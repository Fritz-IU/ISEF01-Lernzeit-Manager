# Ordnerstruktur & Entwicklungsrichtlinien – Lernzeit-Manager

Diese Dokumentation beschreibt die festgelegte Verzeichnisstruktur des Repositories für den **Lernzeit-Manager** (Projekt Software Engineering). Sie dient allen Teammitgliedern als verbindliche Orientierung für das Ablegen von Quellcode, Skripten, Layout-Elementen und Dokumenten im Rahmen von Meilenstein MS 4.

---

## 📁 Repository-Übersicht

```text
lernzeit-manager/
├── api/                     # Asynchrone Endpoints für fetch/AJAX-Anfragen (JSON-Antworten)
├── assets/                  # Statische Frontend-Ressourcen
│   ├── css/                 # Custom CSS-Dateien (z. B. custom.css)
│   ├── js/                  # JavaScript-Logik (z. B. stopwatch.js, dashboard.js)
│   └── img/                 # Bilder, Grafiken, Logos und Icons
├── config/                  # Zentrale Konfigurationsdateien
├── docs/                    # Dokumentation & Anleitungen fürs Team (z. B. XAMPP-Guide)
├── includes/                # Wiederverwendbare HTML/PHP-Layout-Bausteine (Header, Footer, Nav)
├── sql/                     # Datenbank-Skripte für MariaDB
├── src/                     # Objektorientierte PHP-Backend-Logik (OOP)
│   ├── Classes/             # Business-Klassen & Datenmodelle
│   └── Database/            # Datenbank-Hilfsklassen / PDO-Wrapper
├── .gitignore               # Ausschluss von lokalen Temp- und Systemdateien aus Git
├── index.php                # Hauptseite / Dashboard (zentraler Einstiegspunkt)
├── login.php                # Login- & Registrierungsseite
└── README.md                # Projektbeschreibung & Schnellstartanleitung
```

---

## 📌 Beschreibung der Verzeichnisse

### `api/`
Enthält schlanke PHP-Skripte für die asynchrone Kommunikation mit dem Frontend via `fetch()` API.
* **Merkmal:** Gibt **kein HTML** aus, sondern ausschließlich strukturierte **JSON-Daten**.
* **Aufgabe:** Schnittstelle zwischen den JavaScript-Funktionen (z. B. Stoppuhr) und der PHP-Backend-Logik.

### `assets/`
Sammelstelle für alle statischen Client-Ressourcen.
* `assets/css/`: Eigenes Styling zur Ergänzung von Bootstrap 5.
* `assets/js/`: Clientseitige Anwendungslogik (Vanilla JS / ES6+), z. B. Timer-Steuerung, Formularvalidierung, AJAX-Calls.
* `assets/img/`: Grafische Elemente, Favicons, Platzhalter.

### `config/`
Enthält sensible und zentrale Steuerungsskripte.
* `database.php`: Baut die PDO-Verbindung zur MariaDB auf (getrennt für lokale XAMPP-Umgebung und Alwaysdata-Produktivserver).
* `config.php`: Globale Pfade, Session-Handling und Fehler-Reporting.

### `docs/`
Interne Arbeitsdokumente, Leitfäden und Cheatsheets für das Entwicklerteam (z. B. XAMPP-Einrichtung, VS Code Empfehlungen, Testanleitungen).

### `includes/`
Wiederverwendbare Layout-Komponenten nach dem **DRY-Prinzip** (*Don't Repeat Yourself*).
* Werden mittels `require_once` in die Hauptseiten (`index.php`, `goals.php` etc.) eingebunden.

### `sql/`
Verzeichnis für das Datenbankschema und Testdatensätze.
* `schema.sql`: Definiert Tabellen, Primär-/Fremdschlüssel und Constraints.
* `seed.sql`: Beinhaltet synthetische Testdaten gemäß DSGVO-Vorgaben (`testuser@iu-demo.de`).

### `src/`
Der Kern der objektorientierten PHP 8 Backend-Entwicklung (OOP).
* `src/Classes/`: Enthält die fachlichen Klassen (`User`, `Goal`, `TimeTracker`).
* `src/Database/`: Hilfsklassen für Datenbank-Aktionen und Query-Building.

---

## ⚙️ Namenskonventionen & Richtlinien

1. **Dateinamen & Pfade:**
   * Ordner- und Dateinamen für Webseiten und Skripte werden **kleingeschrieben** (z. B. `save_time.php`, `custom.css`).
   * PHP-Klassen in `src/Classes/` nutzen **PascalCase** entsprechend dem Klassennamen (z. B. `TimeTracker.php`).

2. **Datenbank & SQL:**
   * Tabellennamen und Spalten in MariaDB werden in **snake_case** angelegt (z. B. `learning_goals`, `user_id`, `tracked_seconds`).

3. **Git-Workflow:**
   * Anpassen oder Erweitern der Ordnerstruktur sollte im Team abgesprochen und in dieser Dokumentation nachgeführt werden.
