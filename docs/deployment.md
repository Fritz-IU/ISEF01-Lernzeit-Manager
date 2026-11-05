# Deployment auf Alwaysdata – Lernzeit-Manager

Diese Anleitung dokumentiert das Hosting und die Aktualisierung des **Lernzeit-Managers** auf **Alwaysdata** ([https://lernzeit-manager.alwaysdata.net/](https://lernzeit-manager.alwaysdata.net/)).

---

## 1. Eckdaten der Hosting-Umgebung

| Einstellung | Wert |
|---|---|
| **Alwaysdata-Account** | `lernzeit-manager` |
| **Öffentliche URL** | `https://lernzeit-manager.alwaysdata.net/` |
| **Git-Repository** | `https://github.com/Fritz-IU/ISEF01-Lernzeit-Manager.git` |
| **Projektpfad (SSH)** | `~/www/ISEF01-Lernzeit-Manager` (`/home/lernzeit-manager/www/ISEF01-Lernzeit-Manager`) |
| **Application Path (Web-Site)** | `/www/ISEF01-Lernzeit-Manager` |
| **PHP-Version** | `8.3` |
| **Datenbank-Host (`DB_HOST`)** | `mysql-lernzeit-manager.alwaysdata.net` |
| **Datenbank-Port (`DB_PORT`)** | `3306` |
| **Datenbank-Name (`DB_NAME`)** | `lernzeit-manager_lernzeit` |
| **Datenbank-Benutzer (`DB_USER`)** | `lernzeit-manager` |

---

## 2. Ersteinrichtung Schritt für Schritt

### 2.1 MariaDB-Datenbank anlegen und initialisieren

1. Im Alwaysdata-Dashboard unter **Databases ➔ MySQL** die Datenbank `lernzeit-manager_lernzeit` anlegen.
2. Unter **Databases ➔ MySQL ➔ Users** sicherstellen, dass der Benutzer `lernzeit-manager` Vollzugriff auf die Datenbank hat und das Passwort bekannt ist.
3. Über den Button oben rechts im Alwaysdata-Menü **phpMyAdmin** öffnen und sich mit den MySQL-Zugangsdaten anmelden:
   - Links die Datenbank `lernzeit-manager_lernzeit` auswählen.
   - Über den Reiter **Importieren** zuerst [`sql/schema.sql`](../sql/schema.sql) hochladen und ausführen (legt alle Tabellen, Keys und Constraints an).
   - Danach über **Importieren** die Datei [`sql/seed.sql`](../sql/seed.sql) einspielen (legt die DSGVO-konformen Testnutzer und Beispieldaten an).

> **Hinweis zu `sql/reset.php`:** Das Skript [`sql/reset.php`](../sql/reset.php) ist ausschließlich für die lokale XAMPP-Umgebung gedacht. Auf Alwaysdata ist es durch `APP_ENV=production` gesperrt und kann dort auch technisch kein `DROP DATABASE` / `CREATE DATABASE` ausführen, da die Datenbankverwaltung über das Alwaysdata-Panel erfolgt.

---

### 2.2 Quellcode per Git klonen

1. Im Alwaysdata-Panel unter **Remote access ➔ SSH** den SSH-Zugang aktivieren bzw. direkt das **Web-SSH-Terminal** (Terminal-Icon oben rechts im Dashboard) starten.
2. In das `www`-Verzeichnis wechseln und das Repository klonen:

   ```bash
   cd ~/www
   git clone https://github.com/Fritz-IU/ISEF01-Lernzeit-Manager.git
   cd ~/www/ISEF01-Lernzeit-Manager
   ```

---

### 2.3 Umgebungsvariablen (`.env`) konfigurieren

Da die `.env`-Datei aus Sicherheitsgründen nicht im Git-Repository versioniert wird (`.gitignore`), muss sie auf dem Server direkt im Projektordner `~/www/ISEF01-Lernzeit-Manager/` angelegt werden:

1. Im Web-Terminal sicherstellen, dass man sich im Projektordner befindet:

   ```bash
   cd ~/www/ISEF01-Lernzeit-Manager
   cp .env.example .env
   nano .env
   ```

2. Folgende Werte eintragen:

   ```ini
   APP_ENV=production

   DB_HOST=mysql-lernzeit-manager.alwaysdata.net
   DB_PORT=3306
   DB_NAME=lernzeit-manager_lernzeit
   DB_USER=lernzeit-manager
   DB_PASS=<Passwort-des-MySQL-Benutzers>
   ```

3. Speichern (`STRG + O`, `Enter`) und Editor schließen (`STRG + X`).

> **Wichtig:** Die `.env`-Datei muss zwingend in `~/www/ISEF01-Lernzeit-Manager/.env` liegen (nicht eine Ebene darüber in `~/www/.env`), da [`config/database.php`](../config/database.php) die Datei relativ zum Projektstammverzeichnis sucht (`__DIR__ . '/../.env'`).

---

### 2.4 Website-Einstellungen in Alwaysdata prüfen

1. Im Alwaysdata-Dashboard auf **Web ➔ Sites** navigieren.
2. Die Site `lernzeit-manager.alwaysdata.net` bearbeiten und folgende Einstellungen sicherstellen:
   - **Type:** `PHP`
   - **PHP version:** `8.3`
   - **Working directory / Application path:** `/www/ISEF01-Lernzeit-Manager`
3. Speichern – Alwaysdata startet den Webserver automatisch neu.

---

## 3. Aktualisierung (Neuen Stand deployen)

Wenn neue Pull Requests auf GitHub in `main` gemergt wurden, kann der Serverstand in wenigen Sekunden aktualisiert werden:

1. **Web-SSH-Terminal** im Alwaysdata-Dashboard öffnen.
2. In das Projektverzeichnis wechseln und den aktuellen Stand ziehen:

   ```bash
   cd ~/www/ISEF01-Lernzeit-Manager
   git pull origin main
   ```

3. **Falls sich Dateien in `sql/` geändert haben:**
   - **phpMyAdmin** öffnen, in der Datenbank `lernzeit-manager_lernzeit` alle bestehenden Tabellen markieren und löschen (*Drop*).
   - Anschließend [`sql/schema.sql`](../sql/schema.sql) und [`sql/seed.sql`](../sql/seed.sql) neu importieren.

---

## 4. Funktionstest & Sicherheitscheck

Nach dem Deployment können folgende Punkte geprüft werden:

1. **Erreichbarkeit & Test-Login:**
   - Aufruf von [https://lernzeit-manager.alwaysdata.net/](https://lernzeit-manager.alwaysdata.net/)
   - Anmeldung mit einem Testaccount aus [`sql/seed.sql`](../sql/seed.sql):
     - **E-Mail:** `testuser@iu-demo.de` *(oder `testuser2@iu-demo.de`)*
     - **Passwort:** `Lernzeit-Test-2026`
2. **Schutz versteckter Dateien (`.htaccess`):**
   - Aufruf von `https://lernzeit-manager.alwaysdata.net/.env` im Browser.
   - Erwartetes Ergebnis: **403 Forbidden** (durch `RedirectMatch 403 /\.` in der [`.htaccess`](../.htaccess)).

---

## 5. Fehlerdiagnose (Troubleshooting)

Falls auf der Webseite die allgemeine Fehlermeldung *„Es ist ein technischer Fehler aufgetreten. Bitte versuche es später erneut.“* erscheint, werden die technischen Details nicht im Browser ausgegeben, sondern in das Server-Log geschrieben:

1. **Error-Logs einsehen:**
   - Im Alwaysdata-Dashboard unter **Web ➔ Sites** rechts neben der Website auf das **Logs-Symbol** klicken (oder im Dateisystem unter `~/admin/logs/http/` nachsehen).
2. **Datenbankverbindung per CLI prüfen:**
   - Im Web-SSH-Terminal im Ordner `~/www/ISEF01-Lernzeit-Manager` folgenden Befehl ausführen:

     ```bash
     php -r "require 'config/database.php'; var_dump(getDatabaseConnection());"
     ```

   - Gibt der Befehl `object(PDO)#1` zurück, steht die Datenbankverbindung fehlerfrei. Andernfalls wird die genaue Ursache (z. B. fehlende `.env`, falscher `DB_HOST` oder ungültiges Passwort) direkt ausgegeben.

