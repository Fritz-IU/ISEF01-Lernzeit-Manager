# Einstellungen im GitHub Project

Project: https://github.com/users/Fritz-IU/projects/1

Dieses Dokument hält fest, wie das Project eingerichtet ist. Der Arbeitsablauf steht in [CONTRIBUTING.md](../CONTRIBUTING.md).

## Status-Spalten

Unter **⋯ → Settings → Status**:

| Spalte | Beschreibung |
|---|---|
| Product Backlog | Im Jour Fixe geprüft und akzeptiert, noch keinem Sprint zugeordnet. |
| Sprint Backlog (To Do) | Im Sprint-Planning für den aktuellen Sprint eingeplant, noch nicht begonnen. |
| In Progress | Wird gerade umgesetzt. Assignee ist gesetzt. |
| In Review / QA | Pull Request im Code-Review oder gemergt und wartet auf den Akzeptanztest der QS. |
| Done | Akzeptanztest bestanden, Issue von der QS geschlossen. |

## Felder

Unter **⋯ → Settings → Custom fields**:

| Feld | Typ | Werte |
|---|---|---|
| Sprint | Iteration | Sprint 1: 15.10.–21.10.2026 · Sprint 2: 22.10.–28.10.2026 · Sprint 3: 29.10.–04.11.2026 · Sprint 4: 05.11.–13.11.2026 |
| MoSCoW | Single select | Must · Should · Could · Won't |
| Bereich | Single select | Frontend · Backend · Datenbank · Infrastruktur · Qualitätssicherung · Dokumentation · Organisation |
| Aufwand geplant (h) | Number | |
| Aufwand Ist (h) | Number | |

Die Dropdowns der Issue-Vorlagen füllen diese Felder **nicht** automatisch. Sprint, MoSCoW, Bereich und Aufwand geplant werden im Sprint-Planning am Issue gesetzt.

## Ansichten

| Ansicht | Layout | Filter | Gruppierung / Summe |
|---|---|---|---|
| Sprint-Board | Board | `sprint:@current` | Spalten nach Status |
| Backlog | Table | `status:"Product Backlog"` | Group by MoSCoW |
| QS | Table | `status:"In Review / QA"` | Sort by Sprint |
| Bugs | Table | `label:bug is:open` | Group by Labels |
| Aufwand | Table | – | Field sum: Aufwand geplant (h), Aufwand Ist (h) |

### Auswertung des Aufwands

Gemessen wird nur der Gesamtaufwand. In **Aufwand Ist (h)** addiert jede Person ihre Zeit für Umsetzung, Review und Test zum jeweiligen Issue. Eine Zuordnung zu einzelnen Personen ist nicht vorgesehen.

Die Summe von **Aufwand Ist (h)** in der Ansicht **Aufwand** ist der tatsächliche Gesamtaufwand und wird mit der Schätzung für MS 4 aus MS 1 verglichen (ca. 190 Std.). Optional zeigt **Group by Sprint** die Summen je Sprint.

Für den Projektbericht lässt sich die Ansicht als CSV exportieren (Menü der Ansicht **⋯ → Export view data**).

## Workflows

Unter **⋯ → Workflows**:

| Workflow | Einstellung | Grund |
|---|---|---|
| Item added to project | Ein · nur Issues · Status → Product Backlog | Jedes neue Issue startet im Backlog. |
| Item reopened | Ein · nur Issues · Status → In Review / QA | Fängt ein versehentliches `Closes #` ab: Issue wieder öffnen, Karte springt zurück zur QS. |
| Item closed | Ein · nur Issues · Status → Done | Die QS schließt das Issue nach bestandenem Test. |
| Auto-close issue | Ein | Karte auf Done ⇒ Issue wird geschlossen. Board und Issue-Status passen so immer zusammen. |
| Pull request merged | Aus | Wirkt nur auf Pull-Request-Karten, Pull Requests liegen nicht auf dem Board. |
| Pull request linked to issue | Aus | Greift nur bei `Closes #` oder der Seitenleiste „Development“, beides wird nicht verwendet. |
| Code changes requested | Aus | Wirkt nur auf Pull-Request-Karten. |
| Code review approved | Aus | Wirkt nur auf Pull-Request-Karten. |
| Auto-add to project | Ein · Repository `Fritz-IU/ISEF01-Lernzeit-Manager` · Filter `is:issue` | Absicherung zusätzlich zu `projects:` in den Issue-Vorlagen. Wirkt nur auf neue oder geänderte Issues, ältere Issues über **+ Add item** von Hand hinzufügen. |
| Auto-archive items | Aus | Archivierte Karten verschwinden aus allen Ansichten und fallen aus den Aufwandssummen. |

## Warum `Refs #` statt `Closes #`

| | `Closes #12` (auch `Fixes`, `Resolves`) | `Refs #12` |
|---|---|---|
| Verknüpfung | Pull Request erscheint im Issue | Pull Request erscheint im Verlauf des Issues |
| Beim Merge | Issue wird automatisch geschlossen | Issue bleibt offen |
| Board | Karte springt ungetestet auf Done | Karte bleibt in In Review / QA |

Die Seitenleiste **Development** im Pull Request wirkt wie `Closes` und wird deshalb ebenfalls nicht verwendet.
