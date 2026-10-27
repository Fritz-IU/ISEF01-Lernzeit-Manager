## Was wurde geändert?


## Zugehörige Story / Aufgabe
Refs #

## Definition of Done

### Allgemein
- [ ] Review durch eine zweite Person angefordert (nicht Autor*in)
- [ ] Datenbank-Änderungen als SQL-Datei im Repository
- [ ] Doku angepasst (falls Architektur, Schnittstellen oder Datenbank betroffen)

### Code-Qualität
- [ ] Einheitliche Benennung und Formatierung eingehalten
- [ ] Schichten getrennt: keine SQL-Abfragen in der Darstellung, keine Geschäftslogik im HTML
- [ ] Backend objektorientiert umgesetzt (PHP-Klassen)

### Sicherheit & Datenschutz
- [ ] Datenbankzugriffe nur über PDO Prepared Statements
- [ ] Nutzereingaben werden escaped ausgegeben (`htmlspecialchars` bzw. `textContent`)
- [ ] Jeder Datenzugriff prüft, dass der Datensatz dem eingeloggten Nutzer gehört
- [ ] Keine Zugangsdaten oder Passwörter im Code

### Oberfläche (nur bei Frontend-Änderungen)
- [ ] UI-Texte auf Deutsch und auf Rechtschreibung geprüft
- [ ] Einheitliche Bootstrap-Elemente verwendet

### Tests
- [ ] Akzeptanzkriterien lokal (XAMPP) selbst geprüft
- [ ] Unit-Tests laufen lokal grün (`php tools/phpunit.phar`)
- [ ] Neue oder geänderte Validierungs- bzw. Geschäftslogik hat Unit-Tests (siehe `docs/unit-tests.md`)

## Hinweise für Review und QS
