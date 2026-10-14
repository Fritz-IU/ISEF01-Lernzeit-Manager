# Entwicklungs- und QS-Ablauf

Dieses Dokument beschreibt, wie eine Story oder Aufgabe vom Backlog bis „Done“ gelangt.
Grundlage: MS 3, Kapitel 1.6 (Vorgehensmodell) und Kapitel 3.2 (Abbildung 1 „Ablauf je User Story“).

**Grundregel:** Ein Issue gilt erst als erledigt, wenn die QS es getestet hat. Deshalb schließt **nur die QS** Issues, nie der Merge eines Pull Requests.

---

## 1. Board-Spalten

| Spalte | Bedeutung | Wer verschiebt |
|---|---|---|
| Product Backlog | im Jour Fixe akzeptiert, noch keinem Sprint zugeordnet | automatisch beim Anlegen |
| Sprint Backlog (To Do) | im Sprint-Planning für den aktuellen Sprint gewählt | Projektleitung im Sprint-Planning |
| In Progress | wird gerade umgesetzt | Entwickler*in |
| In Review / QA | Pull Request im Review oder wartet auf den Akzeptanztest | Entwickler*in beim Öffnen des Pull Requests |
| Done | Akzeptanztest bestanden | automatisch, wenn die QS das Issue schließt |

## 2. Ablauf

### Entwickler*in
1. Issue auf **In Progress** ziehen und sich selbst zuweisen (**Assignees**).
2. Eigenen Branch von `main` anlegen und dort arbeiten. Kein direkter Commit auf `main`.
3. Pull Request öffnen. Die Vorlage wird automatisch eingefügt, siehe [Abschnitt 3](#3-pull-request-ausfüllen).
4. Rechts unter **Reviewers** eine zweite Person anfordern.
5. **Issue-Karte von Hand auf „In Review / QA“ verschieben.** Das passiert nicht automatisch.

### Reviewer*in
6. Code lesen und die Häkchen der Definition of Done auf Plausibilität prüfen.
7. **Approve** oder **Request changes**. Bei Änderungswünschen passt die Entwickler*in den Code auf demselben Branch an.
8. Nach der Freigabe: **Merge**. Das Issue bleibt dabei offen.

### QS (Paul Grund, Vertretung Fritz Weiß)
9. Aktuellen Stand von `main` holen und die Testfälle der Story ausführen (lokal in XAMPP, ab Sprint 4 auf Alwaysdata).
10. **Bestanden:** Testergebnis als Kommentar ins Issue schreiben (Vorlage siehe [Abschnitt 4](#4-testergebnis-dokumentieren)) und das Issue **schließen**. Die Karte wandert automatisch auf **Done**.
11. **Nicht bestanden:** Über die Vorlage **Bug** ein neues Issue anlegen, mit Verweis auf die Story. Die Story-Karte zurück auf **In Progress** ziehen. Die Korrektur läuft über einen neuen Pull Request, danach beginnt der Ablauf ab Schritt 3 erneut.

```
In Progress ──PR öffnen──▶ In Review / QA ──Review + Merge──▶ (Issue bleibt offen)
     ▲                                                              │
     │                                                       QS testet
     │                                                              │
     └──────────── nicht bestanden (Bug-Issue) ◀────────────────────┤
                                                                    │
                                          bestanden → Issue schließen → Done
```

## 3. Pull Request ausfüllen

### Verweis auf das Issue: `Refs #` statt `Closes #`

Im Abschnitt „Zugehörige Story / Aufgabe“ immer **`Refs #<Nummer>`** schreiben. Nach `#` öffnet GitHub eine Auswahlliste der Issues.

| | `Closes #12` (auch `Fixes`, `Resolves`) | `Refs #12` |
|---|---|---|
| Verknüpfung | Pull Request erscheint im Issue | Pull Request erscheint im Verlauf des Issues |
| Beim Merge | **Issue wird automatisch geschlossen** | **Issue bleibt offen** |
| Board | Karte springt ungetestet auf **Done** | Karte bleibt in **In Review / QA** |

> ⚠️ Das Issue **nicht** über die Seitenleiste **„Development“** im Pull Request verknüpfen. Das wirkt wie `Closes` und schließt das Issue beim Merge.

### Definition of Done
- Alle Punkte der Checkliste prüfen und abhaken.
- Nicht zutreffende Punkte ebenfalls abhaken und mit **„entfällt“** markieren, z. B. `- [x] entfällt – Doku angepasst`.
- Die Häkchen lassen sich auch nach dem Erstellen des Pull Requests per Klick setzen.

### Hinweise für Review und QS
Beschreiben, was besonders zu prüfen ist, welche Testfälle betroffen sind und welcher Testaccount verwendet werden kann.

### Beispiel

```markdown
## Was wurde geändert?
Stoppuhr-Start mit Lernziel-Auswahl. Startzeit wird serverseitig gespeichert.

## Zugehörige Story / Aufgabe
Refs #12

## Definition of Done
### Allgemein
- [x] Review durch eine zweite Person angefordert (nicht Autor*in)
- [x] Datenbank-Änderungen als SQL-Datei im Repository
- [x] entfällt – Doku angepasst
...

## Hinweise für Review und QS
Bitte besonders TC-12-03 testen (Neuladen bei laufender Stoppuhr).
Testaccount: test1@test.local
```

## 4. Testergebnis dokumentieren

Kommentar im Issue, bevor es geschlossen wird:

```
Akzeptanztest bestanden ✅
TC-12-01 ✅  TC-12-02 ✅
Umgebung: XAMPP, Chrome – 23.10.2026
```

Zusätzlich wird jeder Testlauf im Testprotokoll (Teams) festgehalten.

## 5. Sonderfälle

| Situation | Vorgehen |
|---|---|
| Versehentlich `Closes #` verwendet, Issue ist nach dem Merge zu | Issue wieder öffnen (**Reopen**). Die Karte springt automatisch zurück auf **In Review / QA**. |
| Karte nach dem Merge noch auf „In Progress“ | Wer es bemerkt, verschiebt sie auf **In Review / QA**. Die QS prüft zusätzlich regelmäßig die zuletzt gemergten Pull Requests. |
| QS abwesend | Vertretung durch Fritz Weiß (MS 3, Tabelle 2) |

## 6. Einstellungen im GitHub Project

Unter **⋯ → Workflows**:

| Workflow | Einstellung |
|---|---|
| Item added to project | Status → Product Backlog |
| Item closed | Status → Done |
| Item reopened | Status → In Review / QA |
| Pull request merged | ausgeschaltet |
| Auto-add to project | Filter `is:issue` |
