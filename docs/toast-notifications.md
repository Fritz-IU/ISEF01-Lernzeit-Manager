# Dokumentation: Toast-Benachrichtigungssystem (Issue #22)

Dieses Dokument beschreibt das zentrale Benachrichtigungssystem auf Basis von Bootstrap 5 Toasts im **Lernzeit-Manager**.

---

## 1. Ziel & Konzept

Um Layout-Verschiebungen (*Layout Shifts*) beim Einblenden von Erfolgs- und Fehlermeldungen zu vermeiden, nutzt die Anwendung zwei klar getrennte Feedback-Mechanismen:

1. **Schwebende Toast-Notifications (unten rechts):** Für alle Rückmeldungen auf Seitenebene (Erfolgsmeldungen nach dem Speichern/Löschen/Abhaken sowie allgemeine Fehler, Netzwerk- oder Sitzungsfehler).
2. **Inline-Feldvalidierung in Modals (`is-invalid` + `.invalid-feedback`):** Feldspezifische Eingabefehler innerhalb geöffneter Modals werden direkt unter dem betroffenen Formularfeld markiert, damit der Fokus im Formular bleibt.

---

## 2. Beteiligte Dateien

* **[`includes/footer.php`](../includes/footer.php) & [`login.php`](../login.php):** Rendern den zentralen Container `#toast-container` (`position-fixed bottom-0 end-0 p-3 app-toast-container`) und übergeben serverseitige Meldungen (`$_SESSION['flash_success']`, `$_SESSION['flash_error']`, `$_SESSION['flash_info']`, `$successMessage`, `$errorMessage`) als JSON im Attribut `data-initial-toasts`.
* **[`assets/js/toast.js`](../assets/js/toast.js):** Stellt die globale Hilfsfunktion `window.showToast(type, message, options)` bereit und zeigt beim Laden der Seite automatisch alle in `data-initial-toasts` hinterlegten Meldungen an.
* **[`assets/css/custom.css`](../assets/css/custom.css):** Definiert `.app-toast-container` (`z-index: 1090`, liegt damit auch über geöffneten Bootstrap-Modals) und `.app-toast`.

---

## 3. Verwendung

### 3.1 Clientseitig per JavaScript (`fetch`)

```javascript
// Erfolgsmeldung (grün, blendet nach 4,5 s automatisch aus)
window.showToast('success', 'Profildaten erfolgreich gespeichert.');

// Fehlermeldung (rot, blendet nach 6 s automatisch aus)
window.showToast('danger', 'Netzwerkfehler beim Speichern.');

// Info- oder Warnmeldung (blau bzw. gelb)
window.showToast('info', 'Hinweis zur Planung.');
window.showToast('warning', 'Bitte Eingaben prüfen.');
```

### 3.2 Serverseitig per PHP (vor einem Redirect / Reload)

```php
$_SESSION['flash_success'] = 'Das Lernziel wurde erfolgreich angelegt.';
header('Location: goals.php');
exit;
```

Beim nächsten Seitenaufruf liest [`includes/footer.php`](../includes/footer.php) die Session-Variable automatisch aus, löscht sie aus der Session und übergibt sie an [`assets/js/toast.js`](../assets/js/toast.js).
