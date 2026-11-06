# Dokumentation: Soll-Ist-Dashboard (Story #6)

Dieses Dokument beschreibt das Dashboard auf der Startseite ([`index.php`](../index.php)) im **Lernzeit-Manager** (Modul ISEF01 – Software Engineering).

---

## 1. Ziel und Zweck

- **User Story #6:** Studierende sehen auf einen Blick, ob ihre erfasste Lernzeit (Ist) mit der geplanten Lernzeit (Soll) mithält.
- **Akzeptanzkriterium:** Beim Aufruf des Dashboards werden die tatsächlich erfassten Lernzeiten den geplanten Soll-Lernzeiten gegenübergestellt und visuell aufbereitet.
- **Rein lesend:** Das Dashboard verändert keine Daten. Die einzige Eingabe ist die Auswahl des Semesterziels per GET-Parameter `goal`. Die Schnellzugriffe (Lernzeit nachbuchen, Stoppuhr) führen in die bestehenden Funktionen.

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

1. **Kennzahlen:** Semesterziel (Fortschrittsring), Zeitplan (Vorsprung/Rückstand in Stunden, vergangene Semesterzeit), aktueller Monat, aktuelle Woche.
2. **Soll-Ist-Verlauf:** kumulierte Linien über alle Semestertage mit Markierung „Heute“.
3. **Erfasste Lernzeit je Modul:** Ringdiagramm mit HTML-Legende.
4. **Soll und Ist je Monat:** gruppierte Balken mit Monatsbudget, zusätzlich als Tabelle aufklappbar.
5. **Diese Woche:** eingeplante und erfasste Stunden je Wochentag.
6. **Zielstruktur:** Baum Semesterziel → Module (Zwischenziele mit gleichem Titel) → monatliche Zwischenziele, jeweils mit Ist/Soll und Erledigt-Status.

Farben: Ist in der Markenfarbe (Bootstrap Primary), Soll neutral grau als Referenz. Module bekommen Farben in fester Reihenfolge (`--series-1` … `--series-8`), die Farbe folgt dem Modul in Ringdiagramm und Zielbaum. Status wird immer mit Icon und Text angezeigt, nie nur über die Farbe.
