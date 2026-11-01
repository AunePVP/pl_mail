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
   CONTRIBUTING.md). Vorschlagen ja, selbst handeln nein: **Nichts geht zum Original
   (karatektus/pl_mail), ohne dass der Nutzer es ausdrücklich sagt** – kein Push, kein Branch,
   kein PR, kein Issue, kein Kommentar, kein Review. Die Freigabe gilt nur für den einen Fall,
   nicht für spätere. „Mergen“, „PR aufmachen“ oder „pushen“ ohne ausdrückliche Nennung des
   Originals meint immer den Fork. Der Remote `upstream` ist nur zum Holen da, Push dorthin ist
   gesperrt; das nicht umgehen (Push-URL nicht umbiegen, kein `gh … --repo karatektus/pl_mail`
   mit schreibender Wirkung).
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
- Der PR startet `e2e.yml` (PHPUnit und Playwright). Erst mergen, wenn es grün ist.
- **Eigene Änderungen per Squash mergen, Updates vom Original (`update/vX.Y.Z`) nie:** die mit
  einem normalen Merge-Commit (`gh pr merge --merge`). Ein Squash würde die Commits des Originals
  zu einem neuen Commit zusammenfassen; Git wüsste dann nicht mehr, dass sie schon drin sind, und
  das nächste Update brächte dieselben Konflikte noch einmal.
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

Konkrete Adressen, Zugänge und Pfade stehen **nicht** hier (der Fork ist öffentlich), sondern in
`CLAUDE.local.md` auf dem Rechner, von dem aus betrieben wird. Fehlt die Datei, den Nutzer fragen.

**Aufbau.** plMail läuft auf einem Unraid-Server im All-in-one-Modus (docs/install/unraid.md):
Container `plmail` (Image `ghcr.io/aunepvp/pl_mail:main`) und `plmail-db` (`postgres:18-alpine`) in
einem eigenen Docker-Netz, angelegt aus Unraid-Vorlagen (`my-plmail.xml`, `my-plmail-db.xml` unter
`/boot/config/plugins/dockerMan/templates-user/`). Davor ein Reverse Proxy (Nginx Proxy Manager) mit
`proxy_buffering off` und langem Read-Timeout (docs/install/reverse-proxy.md). Die KI-Modelle
laufen auf einem eigenen Rechner und werden über das VPN erreicht (Admin → KI).

- Die Pfade im Container (`/app/var/raw`, `/app/var/attachments`, `/app/var/uploads`,
  `/app/var/secrets`) **nicht ändern**: plMail speichert Dateipfade als `var/raw/…` bzw.
  `var/attachments/…` in der Datenbank. Ein anderes `APP_STORAGE_DIR` verwaist alle Anhänge.
- Ohne `APP_ENCRYPTION_KEY` bzw. den Secrets-Ordner sind die gespeicherten Zugangsdaten
  unlesbar – bei Umzug oder Backup immer mitnehmen (CONTRIBUTING.md → Secrets).
- Die Umgebungsvariablen dieses Forks (`IMPORT_SINCE`, `IMPORT_PAGE_BYTES`) stehen in FORK.md.
  Einstellungen am Container ändert der Nutzer in der Unraid-Vorlage (Docker-Tab → plmail → Edit),
  nicht per `docker run` daneben – sonst überschreibt das nächste Update sie.

### Ausrollen

1. PR im Fork gemergt → `docker.yml` baut `ghcr.io/aunepvp/pl_mail:main` (amd64 + arm64), ca. 15 min.
   Erst ausrollen, wenn dieser Lauf grün ist (`gh run list --repo AunePVP/pl_mail --branch main`).
2. Vor Updates mit Datenbank-Migrationen ein Backup machen (`php bin/console app:backup`,
   docs/install/backup-restore.md). Migrationen laufen beim Start automatisch.
3. Auf dem Unraid: Docker-Tab → plmail → „Update“ (oder „Check for Updates“). Per SSH dasselbe:
   `/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container plmail`
   – baut den Container aus der Vorlage neu. plMail ist dabei rund eine Minute weg.
   **Nur nach Absprache mit dem Nutzer.**
4. Danach prüfen: Container `healthy`, `docker logs plmail` ohne `CRITICAL`, Anmeldung, ein Sync.
5. Zurück: in der Vorlage statt `:main` den vorherigen Stand `:sha-<commit>` eintragen und „Apply“.

### Live debuggen

Zugriff per SSH auf den Unraid (Details in `CLAUDE.local.md`). Grundsatz: **erst lesen, dann
fragen, dann ändern.** Die Installation enthält echte Mail von echten Menschen.

- **Logs:** `docker logs plmail --since 30m` (stderr, JSON); dauerhaft in der Tabelle `log_entry`
  (auch Admin → Protokolle). Deprecation-Zeilen ignorieren.
- **Datenbank:** `docker exec plmail-db psql -U app -d app -At -c "select …"` – nur `select`,
  ändern nur nach Rückfrage und in einer Transaktion.
- **Konsole:** `docker exec plmail php bin/console list app`, z. B. `app:imap:test -a <id>`,
  `app:mail:sync <id>`, `messenger:failed:show --transport=failed`.
- **Warteschlangen:** Tabelle `messenger_messages` (Spalte `queue_name`); Worker-Neustarts stehen
  als `app:work: … exited` im Log.
- **Hypothesen prüfen:** Ein kleines PHP-Skript nach `/tmp` im Container kopieren und dort mit
  `php` ausführen; es kann den Symfony-Kernel booten und Services des kompilierten Containers
  benutzen (rein lesend, danach löschen).
- **Einen Fix vorab ausprobieren:** Die geänderte Datei read-only über das Original mounten
  (Vorlage → „Add another Path“), Container neu erstellen – `opcache.validate_timestamps` ist aus,
  ohne Neustart greift nichts. Nur nach Absprache, und danach als PR sauber in den Fork, Mount wieder
  raus. Nie Code direkt im laufenden Container ändern.
- **Nicht** gegen die Produktions-Postfächer experimentieren (keine Test-Mails, kein Verschieben
  oder Löschen auf dem Server). Für Versuche den Dev-Stack bzw. Test-Stack nehmen.
