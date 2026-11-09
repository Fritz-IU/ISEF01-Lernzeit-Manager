# Dokumentation: Soll-Ist-Dashboard (Story #6)

Dieses Dokument beschreibt das Dashboard auf der Startseite ([`index.php`](../index.php)) im **Lernzeit-Manager** (Modul ISEF01 – Software Engineering).

---

## 1. Ziel und Zweck

- **User Story #6:** Studierende sehen auf einen Blick, ob ihre erfasste Lernzeit (Ist) mit der geplanten Lernzeit (Soll) mithält.
- **Akzeptanzkriterium:** Beim Aufruf des Dashboards werden die tatsächlich erfassten Lernzeiten den geplanten Soll-Lernzeiten gegenübergestellt und visuell aufbereitet.
- **Rein lesend:** Das Dashboard verändert keine Daten, die Knoten der Zielstruktur sind nur Links in die Planung. Die einzige Eingabe ist die Auswahl des Semesterziels per GET-Parameter `goal`. Die Buttons „Lernzeit erfassen“ (Stoppuhr und Nachbuchen auf `timer.php`) und „Planen“ (`goals.php`) verlinken nur auf die Seiten, auf denen man Daten ändert.

---

## 2. Beteiligte Komponenten & Dateien

```text
lernzeit-manager/
├── assets/
│   ├── css/custom.css                     # Abschnitt "Soll-Ist-Dashboard": Kennzahlen, Fortschrittsring, Zielbaum
│   └── js/dashboard.js                    # Diagramme mit Chart.js 4 (per CDN), Semesterauswahl
├── index.php                              # View: Kennzahlen, Diagramme, Zielstruktur
├── src/
│   ├── Controllers/DashboardController.php # Soll-Ist-Berechnung, Zeitplan-Status, Modulgruppierung
│   └── DAO/DashboardDAO.php               # Lesende SQL-Abfragen (Mandantentrennung über user_id)
└── tests/Unit/Controllers/
    └── DashboardControllerTest.php        # Unit-Tests mit DAO-Mock
```

---

## 3. Begriffe & Berechnung

| Begriff | Quelle |
| --- | --- |
| **Ist** | `time_entries.tracked_seconds`, nur manuelle Buchungen und beendete Stoppuhren (laufende/pausierte zählen noch nicht) |
| **Soll je Monat** | Summe `monthly_goals.planned_hours` der Zwischenziele des Monats |
| **Soll bis heute** | Monats-Soll gleichmäßig auf die Tage des Monats (innerhalb des Semesters) verteilt und bis heute aufsummiert. Ohne Zwischenziele: Zielwert `learning_goals.target_hours` linear über das Semester |
| **Soll der Woche** | Eingeplante Lerneinheiten (`learning_slots`) der laufenden Kalenderwoche, über alle Lernziele |
| **Monatsbudget** | `monthly_budgets.budget_hours`, als Markierung im Monatsdiagramm |

**Zeitplan-Status** (Ist bis heute ÷ Soll bis heute):

| Verhältnis | Status |
| --- | --- |
| ≥ 100 % | Im Zeitplan |
| ≥ 80 % | Leicht im Verzug |
| < 80 % | Im Verzug |

Sonderfälle: *Abgeschlossen* (Semesterziel abgehakt), *Noch nicht gestartet* (vor Semesterbeginn), *Noch kein Soll fällig*.

**Semesterauswahl:** angefragtes Semester (nur eigene IDs), sonst das laufende, sonst das nächste anstehende, sonst das letzte.

**Erledigt-Status der Zwischenziele:** gleiche Regel wie in der Planung, also gesetztes `completed_at` oder (ohne Timeslots) Soll-Zeit erreicht.

---

## 4. Aufbau der Seite

1. **Kennzahlen:** Semesterziel (Fortschrittsring), Zeitplan (Vorsprung/Rückstand in Stunden, vergangene Semesterzeit), aktueller Monat, aktuelle Woche. Auf dem Handy (< 576 px) als wischbare Reihe (CSS Scroll-Snap, ohne JS).
2. **Lernzeit im Semesterverlauf:** kumulierte Linien über alle Semestertage mit Markierung „Heute“, über die volle Breite.
3. **Lernzeit pro Monat:** gruppierte Balken mit Monatsbudget.
4. **Lernzeit aktuelle Woche:** eingeplante und erfasste Stunden je Wochentag.
5. **Zielstruktur:** Baum Semesterziel → Module (Zwischenziele mit gleichem Titel) → monatliche Zwischenziele, jeweils mit Ist/Soll und Erledigt-Status. Jedes Modul zeigt zusätzlich seinen Anteil an der gesamten erfassten Semesterzeit (ersetzt das frühere Ringdiagramm „Lernzeit je Modul“, das dieselben Zahlen doppelt zeigte). Ab 992 px zweigen die Module nebeneinander vom Semesterziel ab; die Zeilen sind die Monate des Semesters, parallel laufende Zwischenziele stehen also auf einer Höhe. Auf schmalen Bildschirmen stehen die Module untereinander. Jeder Knoten verlinkt in die Planung (`goals.php`) und springt dort per Anker zur passenden Zeile, die kurz hervorgehoben wird:
   - Semesterziel → `goals.php?tab=semester#goal-{id}`
   - Zwischenziel → `goals.php?tab=month&month={YYYY-MM}#monthly-goal-{id}`
   - Modul → wie Zwischenziel, Ziel ist das erste noch offene Zwischenziel des Moduls (bei erledigten Modulen das letzte)

Ohne JavaScript (oder wenn Chart.js nicht lädt) zeigen die Diagramm-Kacheln die Werte als Tabelle bzw. Text. `dashboard.js` blendet diese Ersatzdarstellung erst nach erfolgreichem Zeichnen aus (Klasse `is-rendered`).

Farben: Ist in der Markenfarbe (Bootstrap Primary), Soll neutral grau als Referenz. Module bekommen Farben in fester Reihenfolge (`--series-1` … `--series-8`), die Farbe kennzeichnet das Modul im Zielbaum. Status wird immer mit Icon und Text angezeigt, nie nur über die Farbe.

## 5. Inaktivitäts-Hinweis (Story #7)

Meldet sich ein Nutzer an, nachdem geplante Lerneinheiten ohne Lernzeit verstrichen sind, zeigt das Dashboard oberhalb der Kennzahlen einen gelben Hinweis. Er erscheint vorerst nur im Dashboard, ohne Pop-up und ohne E-Mail (die E-Mail-Erinnerung ist Story #11).

**Ablauf:**

1. `User::login()` legt den bisherigen Wert von `users.last_login_at` als `$_SESSION['previous_login_at']` ab und setzt `last_login_at` danach auf `NOW()`. Beim ersten Login ist der Wert `NULL`, es gibt dann keinen Hinweis.
2. `index.php` ruft `DashboardController::buildInactivityNotice()` mit diesem Zeitpunkt auf.
3. `DashboardDAO::findMissedSlots()` liefert alle Lerneinheiten (`learning_slots`), deren **Ende** zwischen dem vorherigen Login und jetzt liegt, die **nicht abgehakt** sind (`is_completed = 0`) und auf die **keine Lernzeit gebucht** wurde (kein manueller oder beendeter Stoppuhr-Eintrag mit dieser `learning_slot_id`).
4. Der Controller verdichtet das zu wenigen Zahlen statt einer Liste:
   - Gesamtzeit und Anzahl der verpassten Einheiten
   - eine **Tagesleiste** vom Tag des letzten Logins bis heute (höchstens `INACTIVITY_MAX_DAYS` = 14 Tage). Je Tag stehen die verpassten Stunden, aufgeteilt nach Modul.
   - die betroffenen **offenen Ziele** als Module, zusammengefasst über den Titel wie in der Zielstruktur und sortiert nach verpasster Zeit. Offen ist ein Modul, solange eines seiner betroffenen Zwischenziele nicht erledigt ist (`monthly_goals.completed_at IS NULL`).

Ohne verpasste Einheit liefert der Controller `null`, dann erscheint kein Hinweis.

**Darstellung:** Bewusst knapp, damit er auf einen Blick erfasst wird:
- Überschrift „9 h Lernzeit verpasst“ mit Warn-Icon, darunter „5 Lerneinheiten seit 28.10.“
- Module als Chips mit Farbpunkt und verpassten Stunden. Ein Klick springt per Anker zum ersten offenen Zwischenziel in der Monatsplanung.
- ein Button „Lernzeit nachtragen“ (`timer.php`)
- daneben (auf dem Handy darunter) die Tagesleiste: je Tag eine Säule, gestapelt in den Modulfarben. Tage ohne Versäumnis bleiben grau, heute ist fett beschriftet. Die Modulfarben kommen aus der Zielstruktur des gewählten Semesters (`--series-*`), ein Modul sieht im Hinweis und im Zielbaum also gleich aus. - **Tooltip je Tag:** Beim Überfahren einer Säule (auf dem Handy beim Antippen, per Tastatur mit Tab) zeigt ein Tooltip den Tag und die verpasste Summe. Darunter steht jede verpasste Lerneinheit mit Modul, Stunden, Uhrzeit und Notiz, links mit einem Strich in der Modulfarbe. Die Daten dazu liefert der Controller je Tag unter `slots`. Der Tooltip ist reines CSS (`:hover`/`:focus`) und öffnet nach unten, an den Rändern links bzw. rechts ausgerichtet. Auf dem Handy (< 576 px) nimmt er die ganze Breite der Leiste ein. Für Screenreader steht derselbe Inhalt als `aria-label` am Listeneintrag des Tages.
- **Mehrere Module an einem Tag:** Die Säule wird in den Modulfarben gestapelt, ein Abschnitt je Modul. Mehrere Einheiten desselben Moduls ergeben einen Abschnitt. Im Tooltip zeigt ein Mini-Balken die Aufteilung („2 Module an diesem Tag“), darunter stehen die Einheiten in zeitlicher Reihenfolge.

**Schließen:** Der Hinweis bleibt während der Sitzung sichtbar, bis er geschlossen wird. `dashboard.js` merkt sich das in `sessionStorage` unter `inactivityNoticeClosed:{vorheriger Login}`. Nach dem nächsten Login gilt ein neuer Schlüssel, neue Versäumnisse werden also wieder angezeigt.

**Zum Testen:** Nach `sql/reset.php` haben Testnutzer 1 (letzter Login 28.10.2026) und Testnutzer 2 (27.10.2026) verpasste Einheiten. Bei beiden gibt es einen Tag mit zwei verpassten Modulen: Testnutzer 1 am Do 5.11. (Datenbanken und Betriebssysteme), Testnutzer 2 am Do 29.10. (BWL und Mathematik). Jeder Login setzt `last_login_at` neu, für einen weiteren Test also die Datenbank zurücksetzen.
