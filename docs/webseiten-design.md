# Dokumentation: Grundlegendes Webseiten-Design & Layout

Dieses Dokument beschreibt die Architektur, Komponenten und Styling-Entscheidungen des grundlegenden Seitenlayouts im **Lernzeit-Manager** (Modul ISEF01 – Software Engineering).

---

## 1. Ziel und Zweck (Warum & Wofür)

### Wofür wurde es implementiert?
* **Einheitlicher visueller Rahmen:** Bereitstellung eines konsistenten, barrierearmen und wiederverwendbaren Layouts (Kopfzeile, Navigation, Inhaltsbereich, Fußzeile und Favicon) für alle bestehenden und zukünftigen Seiten des Lernzeit-Managers.
* **Zentraler Einstieg & Orientierung:** Klare Navigation zwischen Dashboard und Profil, Anzeige des angemeldeten Benutzers sowie direkter Absprung zum Dashboard über das Markenlogo.

### Warum wurde es so implementiert? (Entwurfsprinzipien)
* **DRY-Prinzip (*Don't Repeat Yourself*):** Vermeidung redundanten HTML-Codes. Das gesamte HTML-Grundgerüst (Doctype, `<head>`, Meta-Tags, Viewport, Stylesheets, Navigation und Fußbereich) liegt in zwei wiederverwendbaren PHP-Templates. Inhaltsseiten müssen sich nur um ihren spezifischen Inhalt kümmern.
* **Zentrale Wartbarkeit:** Änderungen an Schriftarten, Bootstrap-Versionen, Favicons oder Navigationslinks müssen nur an einer zentralen Stelle vorgenommen werden und greifen sofort anwendungsweit.
* **Mobile-First & Responsivität:** Saubere Darstellung sowohl auf Smartphones als auch auf Desktop-Monitoren durch das Bootstrap 5 Grid-System und responsive Navigationskomponenten.

---

## 2. Beteiligte Komponenten & Dateien

```text
lernzeit-manager/
├── assets/
│   ├── css/custom.css       # Ergänzendes Styling (Flexbox für Sticky Footer)
│   └── img/favicon.png      # Transparentes Favicon & Logo für die Navigation
├── docs/
│   └── webseiten-design.md  # Diese Dokumentation
├── includes/
│   ├── header.php           # Globaler Kopfbereich (Head, Meta, Favicon, Bootstrap Navbar)
│   └── footer.php           # Globaler Fußbereich (Sticky Footer, Bootstrap-Bundle JS)
├── index.php                # Startseite / Dashboard (nutzt Header und Footer)
└── login.php                # Anmeldeseite (bindet Favicon und Bootstrap ein)
```

---

## 3. Aufbau der Layout-Templates

### 3.1 Header-Komponente ([includes/header.php](../includes/header.php))
Nimmt optionale Konfigurationsvariablen der jeweiligen Seite entgegen (`$pageTitle`, `$activeNav`) und rendert:
1. **HTML5-Dokumentenkopf:**
   * UTF-8-Zeichensatz und Viewport-Meta-Tag für mobile Endgeräte (`width=device-width, initial-scale=1.0`).
   * Dynamischer Seitentitel: `<title><?= htmlspecialchars($pageTitle) ?></title>`.
   * Favicon-Einbindung: `<link rel="icon" type="image/png" href="assets/img/favicon.png">`.
   * Bootstrap 5 CSS via CDN mit Subresource Integrity (`integrity="sha384-..."`).
   * Projekt-Stylesheet [assets/css/custom.css](../assets/css/custom.css).
2. **Kopfzeile & Navigation:**
   * Responsive Bootstrap-Navbar mit primärer Markenfarbe (`bg-primary`).
   * Marken-Logo und Projektname als Home-Button zur Startseite (`index.php`).
   * Navigationslinks (aktuell `Dashboard`), die bei aktiver Seite mit der Klasse `.active` und `aria-current="page"` hervorgehoben werden.
   * Kompaktes Benutzer-Dropdown-Menü mit gekürzter Anzeige des Namens im Button, vollständigem Namen und E-Mail-Adresse im Kopfbereich des Menüs, direktem Link auf die Profilverwaltung (`profile.php`) sowie Abmelde-Button.
3. **Container-Eröffnung:**
   * Öffnet `<main class="py-4"><div class="container">` für den eigentlichen Seiteninhalt.

### 3.2 Footer-Komponente ([includes/footer.php](../includes/footer.php))
1. **Container-Abschluss:** Schließt `</div></main>`.
2. **Fußzeile (`<footer>`):** Dezent gestalteter Bereich mit Copyright- und Projekthinweis (`© 2026 Lernzeit-Manager – ISEF01 Software Engineering`).
3. **Skripteinbindung:**
   * Offizielles Bootstrap 5 JavaScript-Bundle (`bootstrap.bundle.min.js`) via CDN mit SRI-Prüfsumme.
   * Ausgabe seitenspezifischer Skripte (über die Variable `$extraScripts`, z. B. `assets/js/profile.js`).
4. **Dokumentenabschluss:** Schließt `</body></html>`.

---

## 4. Responsives Verhalten & Navigation

### 4.1 Responsive Navbar mit Hamburger-Menü
In [includes/header.php](../includes/header.php) wird die Bootstrap-Klasse `navbar-expand-md` eingesetzt:
* **Desktop- und Tablet-Monitore ($\ge 768\text{ px}$):** Das Menü wird horizontal vollständig ausgefahren dargestellt.
* **Smartphones ($< 768\text{ px}$):** Das Menü wird automatisch eingeklappt. Ein Klick auf den Hamburger-Button (`.navbar-toggler`) klappt die Navigation über das Bootstrap-Plugin vertikal ein bzw. aus.

### 4.2 Home-Button & Logo
Das Favicon (`assets/img/favicon.png`) ist direkt in die `.navbar-brand` eingebunden:
```html
<a class="navbar-brand fw-bold d-flex align-items-center" href="index.php">
    <img src="assets/img/favicon.png" alt="Lernzeit-Manager Logo" width="30" height="30" class="d-inline-block me-2">
    <span>Lernzeit-Manager</span>
</a>
```
Dadurch dient das Logo auf jeder Unterseite als intuitiver Schnellzugriff zurück zum Dashboard.

### 4.3 Benutzer-Dropdown & Sichere Abmeldung (POST-Logout)
Um auch bei langen Anzeigenamen (bis zu 100 Zeichen) auf allen Bildschirmgrößen eine einzeilige, schlanke Navigationsleiste zu gewährleisten, sind Benutzerinformationen und Aktionen in einem Bootstrap-Dropdown gebündelt. Im Button der Kopfzeile wird über `User::currentShortNavName()` nur der erste Namensteil (bis zum ersten Leerzeichen oder Bindestrich) angezeigt und zusätzlich per `text-truncate` (`max-width: 160px`) abgesichert. Im aufgeklappten Menü kann der vollständige Name (`User::currentNavName()`) sowie ggf. die E-Mail-Adresse mehrzeilig umbrechen.

Der Logout-Button innerhalb des Dropdown-Menüs ist bewusst nicht als gewöhnlicher Link (`<a href="logout.php">`), sondern als **HTML-Formular mit POST-Methode** implementiert:
```html
<form action="logout.php" method="POST" class="m-0">
    <button type="submit" class="dropdown-item text-danger">Abmelden</button>
</form>
```
Dies verhindert, dass fremde Webseiten einen Benutzer unbemerkt abmelden können (z. B. durch das Einbetten von `<img src="logout.php">`).

---

## 5. Sticky-Footer-Konzept (CSS Flexbox)

### Problemstellung
Auf Seiten mit wenig Inhalt (wie einfachen Bestätigungen oder leeren Dashboards) würde ein normaler Footer nach oben rutschen und unschön mitten im Bildschirm schweben.

### Lösung über Flexbox ([assets/css/custom.css](../assets/css/custom.css))
Durch ein einfaches CSS3-Flexbox-Modell wird sichergestellt, dass der Footer immer am unteren Bildschirmrand abschließt, bei längerem Inhalt aber natürlich nach unten wandert:

```css
html,
body {
    height: 100%;
}

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

main {
    flex: 1 0 auto; /* Nimmt den verbleibenden vertikalen Raum ein und drückt den Footer nach unten */
}
```

---

## 6. Integrationsbeispiel für neue Seiten

Jede neue Inhaltsseite (z. B. `goals.php` für Lernziele) kann das Layout mit minimalem Aufwand nutzen:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Classes/User.php';
use Classes\User;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!User::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$pageTitle = 'Lernziele – Lernzeit-Manager';
$activeNav = 'goals';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hier steht der eigentliche Inhalt der Seite -->
<div class="row">
    <div class="col-12">
        <h2>Meine Lernziele</h2>
    </div>
</div>

<?php
// Optional: Seitenspezifisches JavaScript vor </body> einbinden
$extraScripts = '<script src="assets/js/goals.js"></script>';
require_once __DIR__ . '/includes/footer.php';
?>
```

