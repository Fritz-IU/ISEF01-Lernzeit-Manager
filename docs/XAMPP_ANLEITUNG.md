# XAMPP-Anleitung für den Lernzeit-Manager

Diese Anleitung beschreibt die Einrichtung, den Start- und Shutdown-Prozess sowie den Entwicklungsworkflow mit XAMPP für den Lernzeit-Manager.

## 1. Download & Benötigte Module

* **Download:** apachefriends.org
* **Benötigte Module:** Apache, MySQL (MariaDB)

## 2. Pfad für den Quellcode (htdocs)

* **Windows (Standard):** `C:\xampp\htdocs\lernzeit-manager\` 
* **macOS (Standard):** `/Applications/XAMPP/xamppfiles/htdocs/lernzeit-manager/` 

## 3. Startprozedur

1. XAMPP Control Panel öffnen (unter Windows bevorzugt als Administrator ausführen).
2. Apache Webserver starten:
   * Warten, bis der Hintergrund grün wird und die Standardports 80, 443 angezeigt werden.
3. MySQL (MariaDB) starten:
   * Warten, bis der Hintergrund grün wird und der Standardport 3306 angezeigt wird.

## 4. Adressen im Browser

* **Webapp:** `http://localhost/ISEF01-Lernzeit-Manager/` 
* **phpMyAdmin:** `http://localhost/phpmyadmin/` 

## 5. Entwicklungsworkflow

1. Änderungen am Quellcode in VS Code speichern.
2. Webseite im Browser neu laden.
3. Neu laden ohne Cache: `STRG + F5` (Windows) bzw. `CMD + Umschalt + R` (macOS).

## 6. Shutdownprozedur

1. Alles in VS Code speichern und auf GitHub laden.
2. Apache stoppen (warten bis nicht mehr grün; stoppt auch Schreibvorgänge auf die Datenbank).
3. MySQL stoppen.
4. XAMPP Control Panel beenden.

## 7. Portprobleme

Falls Port 80 oder 3306 bereits belegt ist: In den XAMPP-Einstellungen (httpd.conf) den Port von 80 auf 8080 ändern.
