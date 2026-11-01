# CLAUDE.md – Fork AunePVP/pl_mail

Dies ist ein **Fork** von [karatektus/pl_mail](https://github.com/karatektus/pl_mail), einem
selbst gehosteten Webmailer (Symfony 8, PHP 8.4, PostgreSQL 18, FrankenPHP, Hotwire). Er wird für
eine Familie und ein kleines Ingenieurbüro betrieben. Mit dir arbeiten hier mehrere Personen, jede
in eigenen Chats; dieses Dokument ist das, was alle gemeinsam wissen.

**Sprich mit den Nutzern Deutsch.** Code, Kommentare, Commit-Messages und Doku im Projekt bleiben
Englisch wie im Original; nur FORK.md, CLAUDE.md und Absprachen sind Deutsch.

## Zuerst lesen

- **[FORK.md](FORK.md)** – jede Abweichung vom Original, mit Grund, und wie ein Update vom Original
  eingespielt wird. Vor jeder Änderung lesen, nach jeder Änderung ergänzen.
- **[CONTRIBUTING.md](CONTRIBUTING.md)** – Stack, Dev-Setup, Tests, Konsolenbefehle. Gilt hier genauso.
- **[CODESTYLE.md](CODESTYLE.md)** und **[DESIGN.md](DESIGN.md)** – Code- und UI-Konventionen des Originals.
- **docs/internals/** – Architektur (`architecture.md`), Mail-Import (`mail-ingest.md`), KI
  (`ai-assist.md`), Sicherheitsmodell (`security-model.md`) usw. Vor Änderungen am jeweiligen Bereich lesen.

Der Code des Originals ist sehr ausführlich kommentiert: Die Kommentare erklären meist, *warum*
etwas so ist und welcher Fehler damit schon einmal behoben wurde. Lies sie, bevor du etwas
„vereinfachst“.

## Regeln für diesen Fork

1. **Klein halten.** Jede Zeile, die vom Original abweicht, kann bei einem Update in Konflikt
   geraten. Lieber neue Dateien (eigene Services, Controller, Templates) als Änderungen an
   bestehenden; bestehende Dateien nur so wenig wie möglich anfassen.
2. **Markieren.** Jede Änderung an einer Datei des Originals bekommt einen Kommentar
   `FORK (FORK.md): …` an der Stelle und einen Abschnitt in FORK.md.
3. **Datenbank mit Vorsicht.** Eigene Doctrine-Migrationen und neue Spalten in Tabellen des
   Originals sind die teuersten Konflikte: Das Original weiß nichts davon und kann später eine
   gleichnamige Spalte oder eine Migration anlegen, die mit unserer kollidiert. Vor jeder
   Schemaänderung mit dem Nutzer absprechen; wenn nötig, eigene Tabellen mit Präfix `fork_`
   statt neuer Spalten in bestehenden Tabellen.
4. **Fehler auch ans Original.** Ist eine Änderung eine Fehlerbehebung, die allen hilft, dem Nutzer
   vorschlagen, sie auch beim Original einzureichen (dort: erst Issue, dann PR, siehe
   CONTRIBUTING.md). **Nie selbst** ein Issue, einen Kommentar oder PR beim Original erstellen,
   ohne dass der Nutzer es ausdrücklich freigegeben hat. Der Remote `upstream` ist nur zum Holen da,
   Push dorthin ist gesperrt.
5. **Öffentlich.** Der Fork ist öffentlich. Nie committen: Passwörter, App-Passwörter, Tokens,
   `.env`-Geheimnisse, Mailinhalte, Namen von Kunden, interne Hostnamen oder IP-Adressen,
   Zertifikate von Firmenservern. Betriebsdaten eines Servers gehören in `CLAUDE.local.md` dort
   (ist in `.gitignore`).
6. **Lizenz AGPL-3.0.** Gilt auch für unsere Änderungen; der öffentliche Fork erfüllt die Pflicht,
   den Quellcode bereitzustellen.

## Arbeitsweise

- `main` ist das, was läuft: Jeder Push auf `main` baut das Image `ghcr.io/aunepvp/pl_mail:main`
  (amd64 + arm64). Deshalb **nie direkt auf `main` committen.**
- Jede Änderung auf einem eigenen Branch (`feature/…`, `fix/…`, `update/vX.Y.Z`), dann Pull
  Request gegen `main` **des Forks** (`gh pr create --repo AunePVP/pl_mail --base main`).
  `gh` wählt bei Forks sonst gern das Original als Ziel.
- Der PR startet `e2e.yml` (PHPUnit und Playwright). Erst mergen, wenn es grün ist; gemergt wird
  per Squash.
- Was ein Nutzer merkt, bekommt einen Eintrag in `changelog.d/` wie im Original beschrieben
  (changelog.d/README.md), mit Präfix `fork-` im Dateinamen.
- Update vom Original: siehe FORK.md → „Update vom Original“.

## Lokal entwickeln und testen

Wie in CONTRIBUTING.md:

```bash
docker compose -f compose.yaml -f compose.dev.yaml up --build   # Dev-Stack
npm ci && npx playwright install chromium
npm run test:unit:docker                                         # PHPUnit
npm run test:e2e:docker                                          # Browsertests
```

Der Test-Stack belegt Port 8001 (`TEST_HTTP_PORT`). Auf einem Rechner, auf dem 8001 schon belegt
ist, einen anderen Port und Projektnamen nehmen (CONTRIBUTING.md → Tests, „Running a second
stack“). **Nie** Dev- oder Test-Stack gegen die Datenbank einer laufenden Installation richten.

Auf einem Server mit laufender Installation: Den Stack der Installation nicht anfassen, außer der
Nutzer will genau das. Container-Neustarts vorher ankündigen und bestätigen lassen.

## Betrieb

Wie und wo der Fork läuft, steht nicht hier, sondern in `CLAUDE.local.md` auf dem jeweiligen
Server (nicht im Repo). Allgemein:

- Installation im All-in-one-Modus: ein plMail-Container plus Postgres
  (docs/install/unraid.md, docs/install/docker.md).
- Die Umgebungsvariablen dieses Forks (`IMPORT_SINCE`, `IMPORT_PAGE_BYTES`) sind in FORK.md beschrieben.
- Ohne `APP_ENCRYPTION_KEY` bzw. das Secrets-Volume sind die gespeicherten Zugangsdaten
  unlesbar – bei Umzug oder Backup immer mitnehmen (CONTRIBUTING.md → Secrets).
