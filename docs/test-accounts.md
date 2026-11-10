# Test-Accounts für die Begutachtung

Diese Liste richtet sich an die Tutorin bzw. den Tutor. Jeder Account zeigt eine bestimmte Situation, sodass sich die wichtigsten Funktionen ohne eigene Vorbereitung prüfen lassen. Alle Daten sind erfunden (DSGVO-konform), die Adressen enden auf `@iu-demo.de`.

- **Adresse:** <https://lernzeit-manager.alwaysdata.net/>
- **Passwort für alle Accounts:** `Lernzeit-Test-2026`

## Übersicht

| Account | Name | Situation |
| --- | --- | --- |
| `testuser@iu-demo.de` | Max Mustermann | Laufendes Semester, im Zeitplan, alle Bereiche mit Daten gefüllt |
| `testuser2@iu-demo.de` | Erika Musterfrau | Leicht im Verzug, pausierte Stoppuhr |
| `verzug@iu-demo.de` | Jonas Weber | Im Verzug, seit 9 Tagen nicht angemeldet (Inaktivitäts-Hinweis) |
| `ueberplant@iu-demo.de` | Lena Schmidt | Planung mit Überschreitungen und Terminüberschneidung |
| `abgeschlossen@iu-demo.de` | Nina Schulz | Semesterpause: ein Semester abgeschlossen, das nächste noch nicht begonnen |
| `leer@iu-demo.de` | – | Neu registriert, noch keine Daten |

## Die Accounts im Einzelnen

### 1. Max Mustermann – `testuser@iu-demo.de`

Ein Informatik-Student im 3. Semester, der seine Planung einhält. Der Account eignet sich für einen ersten Rundgang durch alle Bereiche.

- **Dashboard:** Status „Im Zeitplan“ mit Vorsprung in Stunden, Diagramme zum Semesterverlauf, zu den Monaten und zur aktuellen Woche sowie die Zielstruktur (Semester → Module → Monatsziele). Die Knoten der Zielstruktur führen direkt in die Planung.
- **Planen → Semester:** zwei Semesterziele, das laufende und das folgende.
- **Planen → Monat:** Zwischenziele je Modul, Monatsbudget, abgeschlossene Ziele der Vormonate mit Datum.
- **Planen → Woche:** erledigte und offene Lerneinheiten. Die Einheit von gestern (Betriebssysteme) wurde nur teilweise erfüllt und hat deshalb ein gelbes Häkchen mit dem Hinweis, wie viel Zeit fehlt. Ist heute Montag, liegt sie in der Vorwoche.
- **Stoppuhr:** Liste der erfassten Lerneinheiten aus Stoppuhr und Nachbuchung. Hier lässt sich eine neue Stoppuhr starten oder über „+ Lernzeit nachbuchen“ Zeit nachtragen.
- **Semesterauswahl** oben rechts im Dashboard: Wechsel zum folgenden Semester („Noch nicht gestartet“).

### 2. Erika Musterfrau – `testuser2@iu-demo.de`

Eine Wirtschaftsinformatik-Studentin im 2. Semester, die etwas hinter ihrer Planung liegt.

- **Dashboard:** Status „Leicht im Verzug“ (zwischen 80 und 100 % der geplanten Zeit bis heute).
- **Stoppuhr:** Eine pausierte Stoppuhr mit 40 Minuten für Mathematik. Sie kann fortgesetzt („Weiterlernen“), gespeichert („Stopp & Speichern“) oder verworfen werden. Nach dem Speichern erscheint die Zeit im Dashboard.
- **Planen → Monat:** Im Vormonat wurde Mathematik nicht geschafft, das Zwischenziel ist noch offen.

### 3. Jonas Weber – `verzug@iu-demo.de`

Ein Student, der deutlich hinter seiner Planung liegt und sich neun Tage nicht angemeldet hat.

- **Dashboard beim ersten Login:** gelber Hinweis „10,5 h Lernzeit verpasst“ mit 6 verpassten Lerneinheiten. Die Tagesleiste zeigt die versäumte Zeit je Tag, an einem Tag sind zwei Module übereinander gestapelt. Beim Überfahren einer Säule erscheinen die einzelnen Einheiten. Ein Klick auf ein Modul führt in die Monatsplanung, „Lernzeit nachtragen“ zur Stoppuhr.
- **Dashboard:** Status „Im Verzug“ (unter 80 %).
- **Planen → Woche:** die verpassten Einheiten sind offen und ohne gebuchte Zeit.

**Wichtig:** Der Hinweis erscheint nur beim **ersten** Login nach dem Einspielen der Testdaten. Jeder Login speichert den Anmeldezeitpunkt neu, danach gibt es keine verpassten Einheiten mehr seit dem letzten Login.

### 4. Lena Schmidt – `ueberplant@iu-demo.de`

Eine Studentin, die mehr eingeplant hat, als ihr Zeitbudget hergibt. Ihre erfasste Lernzeit liegt im Plan, auffällig ist nur die Planung.

- **Planen → Monat (aktueller Monat):**
  - 32 h Zwischenziele bei 20 h Monatsbudget, also „Zeitbudget um 12,0 h überschritten“.
  - „Lernziel überschritten (148,0 / 100,0 h)“: Die Zwischenziele aller Monate ergeben mehr als das Semesterziel.
  - Marketing: 6 h Lerneinheiten eingeplant, aber nur 4 h als Zwischenziel vorgesehen.
- **Planen → Woche:** In den nächsten Tagen überschneiden sich zwei Lerneinheiten (Controlling 14:00–16:00 und Wirtschaftsrecht 15:00–17:00). Beide sind als Kollision markiert.

Alle Überschreitungen sind fachlich erlaubt und werden nur als Warnung angezeigt.

### 5. Nina Schulz – `abgeschlossen@iu-demo.de`

Eine Studentin zwischen zwei Semestern.

- **Dashboard:** Das nächste Semester beginnt in ein bis zwei Monaten, Status „Noch nicht gestartet“.
- **Semesterauswahl** oben rechts: das vergangene Semester mit Status „Abgeschlossen“. Alle Zwischenziele sind erledigt, der Semesterverlauf zeigt die erfasste Lernzeit über sechs Monate.
- **Planen → Semester:** das abgeschlossene und das kommende Semesterziel.

### 6. Neuer Nutzer – `leer@iu-demo.de`

Ein Account im Zustand direkt nach der Registrierung: kein Name, kein Studiengang, keine Planung, keine Lernzeit.

- **Leere Zustände** auf Dashboard, Planung und Stoppuhr mit Hinweisen, wie es weitergeht.
- **Profil bearbeiten** (Menü oben rechts): Name, Studiengang und Semester eintragen. Der Name erscheint danach sofort in der Navigation.
- **Eigene Planung von Grund auf:** Semesterziel anlegen, Zwischenziele auf Monate verteilen, Lerneinheiten in der Woche einplanen, Zeit per Stoppuhr erfassen. Für eigene Versuche eignet sich dieser Account am besten, weil dabei keine vorbereitete Situation verloren geht.

Alternativ lässt sich über den Reiter „Registrieren“ auf der Anmeldeseite ein eigener Account anlegen (Passwort mindestens 15 Zeichen).

## Welche Funktion wo zu sehen ist

| Funktion | Account |
| --- | --- |
| Registrierung, Profil | `leer@iu-demo.de` oder eigener Account |
| 6-Monats-Planung (Semesterziele) | alle außer `leer@` |
| Monatsplanung, Zwischenziele, Monatsbudget | Max, Lena |
| Wochenplanung mit Lerneinheiten | Max, Jonas, Lena |
| Erledigt-Status (auch teilweise erfüllt) | Max, Nina |
| Stoppuhr (laufend, pausiert, gespeichert) | Erika (pausiert), jeder Account zum Starten |
| Manuelle Nachbuchung | Max (vorhandene Einträge), jeder Account |
| Soll-Ist-Dashboard und Zeitplan-Status | Max (im Zeitplan), Erika (leicht im Verzug), Jonas (im Verzug), Nina (abgeschlossen, noch nicht gestartet) |
| Inaktivitäts-Hinweis | Jonas (nur beim ersten Login) |
| Warnungen bei Überplanung und Kollision | Lena |
| Trennung der Nutzerdaten | jeder Account sieht nur die eigenen Daten |

## Hinweise zu den Testdaten

- **Datumsangaben:** Die Testdaten werden beim Einspielen relativ zum aktuellen Datum berechnet. Semester, aktuelle Woche und Status passen deshalb zum Tag des Einspielens. Etwa eine Woche lang bleiben die beschriebenen Situationen erhalten. Danach verschieben sich die Status, weil geplante Lerneinheiten ohne Buchung verstreichen.
- **Eigene Änderungen** wirken sich nur auf den jeweiligen Account aus. Wer z. B. bei Erika die Stoppuhr speichert, verändert ihre Zahlen, aber keinen anderen Account.
- **Zurücksetzen:** Die Testdaten lassen sich jederzeit neu einspielen (`sql/schema.sql`, danach `sql/seed.sql`, siehe [deployment.md](deployment.md)). Danach ist auch der Inaktivitäts-Hinweis wieder sichtbar.
- **Passwort:** `Lernzeit-Test-2026` ist ein reines Testpasswort und wird nirgends sonst verwendet.
