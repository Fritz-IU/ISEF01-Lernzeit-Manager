# Arbeitsablauf

Ein Issue ist erst fertig, wenn die QS es getestet und geschlossen hat.

## 1. Sprint-Planning (Mittwoch, Team)
1. Issues für den Sprint aus **Product Backlog** nach **Sprint Backlog (To Do)** ziehen.
2. Am Issue setzen: **Sprint**, **MoSCoW**, **Bereich**, **Aufwand geplant (h)**.

## 2. Umsetzen (Entwickler*in)
1. Issue auf **In Progress** ziehen und sich unter **Assignees** eintragen.
2. Branch von `main` anlegen und dort arbeiten. Nie direkt auf `main` committen.
3. Pull Request öffnen (siehe [Abschnitt 3](#3-pull-request-ausfüllen)).
4. Unter **Reviewers** eine zweite Person anfordern.
5. Issue-Karte auf **In Review / QA** ziehen und **Aufwand Ist (h)** eintragen.

## 3. Pull Request ausfüllen
1. **Was wurde geändert?** – kurz beschreiben.
2. **Zugehörige Story / Aufgabe** – `Refs #<Nummer>` eintragen.
   - Nie `Closes #`, `Fixes #` oder `Resolves #` verwenden.
   - Das Issue nicht über die Seitenleiste **Development** verknüpfen.
3. **Definition of Done** – jeden Punkt prüfen und abhaken. Nicht zutreffende Punkte abhaken und mit „entfällt“ markieren:
   `- [x] entfällt – Doku angepasst`
4. **Hinweise für Review und QS** – was besonders zu prüfen ist, betroffene Testfälle, Testaccount.

Beispiel:

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

## 4. Review (Reviewer*in)
1. Code lesen, Häkchen der Definition of Done prüfen.
2. **Approve** oder **Request changes**.
3. Nach Freigabe **Merge**. Das Issue bleibt offen.
4. Review-Zeit zu **Aufwand Ist (h)** des Issues addieren.

## 5. Testen (QS)
1. Aktuellen Stand von `main` holen und die Testfälle der Story ausführen.
2. **Bestanden:** Ergebnis als Kommentar ins Issue schreiben und Issue **schließen**.
   ```
   Akzeptanztest bestanden ✅
   TC-12-01 ✅  TC-12-02 ✅
   Umgebung: XAMPP, Chrome – 23.10.2026
   ```
3. **Nicht bestanden:** Bug über die Vorlage **Bug** anlegen, Story verlinken, Story-Karte zurück auf **In Progress**.
4. Testlauf im Testprotokoll (Teams) eintragen.
5. Testzeit zu **Aufwand Ist (h)** des Issues addieren.

## 6. Aufwand eintragen
- In halben Stunden, mit Punkt: `0.5`, `1.5`, `3`.
- Jede Person addiert ihre Zeit (Umsetzung, Review, Test) zum **Aufwand Ist (h)** des Issues, an dem sie gearbeitet hat (z. B. 2 → 3.5).
- **Aufwand geplant (h)** nach dem Sprint-Planning nicht mehr ändern.
- Arbeit ohne Story als Aufgabe anlegen und dort eintragen.
- Vergessen? Nachträglich schätzen und eintragen, nicht leer lassen.

## 7. Wenn etwas schiefgeht
- **Issue nach dem Merge geschlossen oder Karte versehentlich auf Done:** Issue wieder öffnen (**Reopen**).
- **Karte nach dem Merge noch auf In Progress:** auf **In Review / QA** ziehen.
- **QS abwesend:** Fritz Weiß testet.
