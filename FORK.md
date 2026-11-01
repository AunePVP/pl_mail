# Fork AunePVP/pl_mail

Fork von [karatektus/pl_mail](https://github.com/karatektus/pl_mail). Hier steht **jede** Abweichung
vom Original, mit Grund. Wer etwas am Fork ändert, trägt es hier ein; wer ein Update vom Original
einspielt, prüft diese Liste gegen das Update (siehe [Update vom Original](#update-vom-original)).

Ziel ist ein **kleiner** Fork: Fehlerbehebungen gehen zusätzlich als Pull Request ans Original, und
was das Original übernimmt, fliegt hier wieder raus. Eigene Funktionen bleiben, wo nötig, aber
möglichst in neuen Dateien statt in Änderungen an bestehenden.

Im Code ist jede Fork-Stelle mit `FORK` markiert (`// FORK (FORK.md): …` oder im Docblock), damit sie
bei einem Merge-Konflikt sofort erkennbar ist:

```bash
git grep -n "FORK (FORK.md)"
```

## Abweichungen

### 1. IMAP-Befehls-Timeout 240 s statt 30 s

`src/Infrastructure/ImapClient/ImapConnection.php`: neue Konstante `COMMAND_TIMEOUT_SECONDS = 240.0`;
`command()` und das APPEND-Literal warten `max(timeoutSeconds, 240)` s auf die Antwort.
Verbinden, TLS und Login bleiben bei 30 s.

**Grund:** Im Original ist der 30-s-Timeout die Frist für die *ganze* Antwort eines Befehls. Eine
Import-Seite (50 Mails mit vollem Inhalt in einem `UID FETCH`) war auf einem Firmen-Postfach bis
569 MB groß, der Server liefert ~3,5 MB/s. 608 von 1 797 INBOX-Seiten brauchten länger als 30 s;
der Erstimport brach jede Viertelstunde an derselben Seite ab, nur neue Mails kamen an.
**Kandidat fürs Original:** ja (nächstes Issue dort: #95).

### 2. Erstimport nur ab einem Datum (`IMPORT_SINCE`)

`src/Service/Imap/ImapMailboxImporter.php`, `missingBelow()`: sucht `UID 1:<floor-1> SINCE <Datum>`
statt ohne Datum. Ältere Mails gelten als nicht fehlend, der Ordner ist fertig, wenn alles ab dem
Datum da ist. Neue Mail (MessageSyncer) ist nicht betroffen.

- Umgebungsvariable `IMPORT_SINCE`, Format `YYYY-MM-DD`; leer = ganze Historie (Original).
- SINCE vergleicht das Ankunftsdatum auf dem Server (INTERNALDATE), nicht den Date-Header.
- Nach einer Änderung für laufende Ordner die Zähler neu berechnen lassen:
  `update mailbox set import_total=null, import_remaining=null where import_floor_uid<>0;`
- Ein früheres Datum holt fehlende ältere Mails nach, solange der Ordner noch importiert; ein
  fertiger Ordner (`import_floor_uid = 0`) wird nicht neu geplant.

**Grund:** Postfächer mit >150 GB Historie; gewünscht sind nur die letzten Jahre.
**Kandidat fürs Original:** ja, dort aber als Einstellung pro Konto statt Umgebungsvariable.

### 3. Import-Seiten zusätzlich nach Bytes begrenzt (`IMPORT_PAGE_BYTES`)

`src/Service/Imap/ImapMailboxImporter.php`, neue Methode `capBySize()`: vor dem Abruf werden die
Größen der bis zu 50 Kandidaten geholt (`UID FETCH … (RFC822.SIZE)`), die Seite endet vor der Mail,
mit der `IMPORT_PAGE_BYTES` überschritten würde. Mindestens eine Mail bleibt immer, große Einzelmails
werden also geholt. `0`/leer = Original (immer 50 Mails).

**Grund:** PHP `memory_limit` im Image ist 2 GB; eine 427-MB-Seite ließ den Backlog-Worker beim
Parsen sterben, 76 Neustarts in 5 h, Import stand. Mit 100 MB (`100000000`) läuft er durch.
**Kandidat fürs Original:** ja.

### 4. Image-Build: immer amd64 + arm64

`.github/workflows/docker.yml`, Job `setup`: im Fork (`GITHUB_REPOSITORY != karatektus/pl_mail`)
baut jeder Push auf `main` beide Architekturen, im Original nur ein Release-Tag.

Dazu je ein Schritt „Lowercase the image name“ in den Jobs `build` und `merge`: Docker verlangt
Kleinbuchstaben, der Kontoname `AunePVP` hat Großbuchstaben (im Original ohne Wirkung).

**Grund:** Der Fork wird direkt von `main` betrieben, auf einem arm64-Host (NVIDIA DGX Spark) und
geplant auf einem amd64-Host (Unraid). Image: `ghcr.io/aunepvp/pl_mail:main` bzw. `:sha-<commit>`.

### 5. Abgeschaltete Workflows (nur Einstellung, keine Dateiänderung)

In den Actions-Einstellungen des Forks deaktiviert: `pages.yml`, `wiki.yml`,
`close-milestone-issues.yml`, `pr-hygiene.yml`. Sie gehören zur Projektpflege des Originals
(Website, Wiki, Meilensteine, Pflicht-Issue je PR) und würden im Fork nur fehlschlagen oder PRs
blockieren. Aktiv bleiben `docker.yml` (Image) und `e2e.yml` (Unit- und Browsertests bei jedem PR).

### 6. Anleitungen für KI-Assistenten

`CLAUDE.md` (Regeln, Arbeitsweise, Betrieb, Debuggen), `AGENTS.md` (Verweis darauf für Codex,
Cursor, Gemini CLI u. a.) und der Eintrag `CLAUDE.local.md` in `.gitignore`.

### 7. Sicherheit: Antwort auf verschlüsselte Mail, Prelogin

Zwei Funde aus einer Sicherheitsprüfung (2026-11-01), beide nicht im Original behoben.

- `assets/controllers/compose/encryption_controller.js`, `#clean()`: `ALLOW_DATA_ATTR: false`,
  dazu `data-quote-wrapped` in `ADD_ATTR`. Beim Antworten auf eine PGP-verschlüsselte Mail wird
  das entschlüsselte HTML in den Editor eingefügt, also in die App-Seite selbst, nicht in den
  Sandbox-Frame. DOMPurify lässt standardmäßig alle `data-*` stehen; ein Absender konnte so ein
  `data-controller="mail--thread-read"` mit beliebiger URL einschleusen, das Stimulus startet und
  das sofort mit dem CSRF-Token der Sitzung einen POST schickt (Threads in den Papierkorb, als Spam
  markieren, …). Keine Skriptausführung, kein Datenabfluss (CSP), aber Aktionen im Namen des Opfers.
- `src/Controller/Auth/PreloginController.php`: Ist die Adresse nicht genau so gespeichert, wird
  ohne Beachtung der Groß-/Kleinschreibung gesucht (nur bei genau einem Treffer). Vorher bekam eine
  bekannte Adresse in anderer Schreibweise das Ersatz-Salt, die exakte Schreibweise das echte; bei
  unbekannten Adressen sind beide gleich. Zwei Anfragen verrieten so, ob es ein Konto gibt.
  Test: `tests/Security/ForkPreloginCaseTest.php`.

**Kandidat fürs Original:** ja, beide (dort erst Issue, nur nach Freigabe).

### 8. Absenderprüfung bei iCloud-Konten

`src/Service/Mail/SenderAuthentication.php`: `KNOWN_RECEIVERS` bekommt `'me.com' => 'icloud.com'`;
`verdict()` liest die Authentication-Results-Zeilen neu (neue Methoden `isOneReport()`,
`reportPasses()`, `results()`). Test: neue Fälle `icloud: …` in
`tests/Service/Mail/SenderAuthenticationTest.php`.

- Zeilen ohne dmarc/dkim/spf-Ergebnis (iCloud schreibt BIMI und ARC je in eine eigene Zeile) sind
  neutral statt ein Fehlschlag; bleibt nichts übrig → `Unknown`.
- Berichten die geglaubten Zeilen DMARC höchstens einmal, werden sie als *ein* Bericht gelesen wie
  Gmails eine Zeile. Ein DMARC-Ergebnis außer `pass`/`none` ist dann `Fail`, auch wenn eine andere
  Zeile ein ausgerichtetes DKIM/SPF-pass hat. Berichten zwei Zeilen DMARC, gilt weiter die Regel
  des Originals (jede Zeile muss bestehen).

**Grund:** Auf `imap.mail.me.com` war jede Mail `Unknown` (Hostdomain `me.com`, Authserv-IDs
`*.icloud.com`), mit bloßer Zuordnung `Fail` (BIMI/ARC-Zeilen bestehen nie). Buchungen echter
Absender wurden so nie direkt in den Kalender geschrieben. Die Regel „jede Zeile muss bestehen“
pro Methodenzeile hätte nicht gereicht: iCloud schreibt eine `dkim-verifier`-Zeile pro Signatur, im
iCloud-Konto der Produktion bei 4149 von 13068 Mails mehrere (Absender + Versanddienst), die nicht
ausgerichtete wäre jedes Mal `Fail` gewesen. `dmarc.icloud.com` und `spf.icloud.com` kamen dort in
keiner Mail doppelt vor – passt zu RFC 8601 §5 (iCloud entfernt eingehende Zeilen mit seinen IDs),
ist aber kein Beweis; belegt hat Apple das nirgends.
**Kandidat fürs Original:** ja (dort erst Issue, nur nach Freigabe).

## Update vom Original

Das Original veröffentlicht etwa alle 2–4 Tage ein Release (`vX.Y.Z`). Eingespielt wird ein Release-Tag,
nicht der laufende `main` des Originals.

```bash
git fetch upstream --tags
git switch main && git pull
git switch -c update/vX.Y.Z
git merge vX.Y.Z
```

1. Konflikte lösen. Fork-Stellen sind mit `FORK (FORK.md)` markiert. Hat das Original eine
   Abweichung selbst gelöst, die Fork-Stelle entfernen und hier austragen.
2. `git diff vX.Y.Z -- $(git grep -l "FORK (FORK.md)")` ansehen: Sind alle Abweichungen noch da und
   passen sie zum neuen Code drumherum?
3. Push, Pull Request gegen `main` des Forks, Tests (e2e.yml) abwarten, dann **mit Merge-Commit**
   mergen (`gh pr merge --merge`), nie per Squash: sonst erkennt Git die Commits des Originals beim
   nächsten Update nicht wieder. Der Merge baut das Image.
4. Auf dem Server das neue Image ziehen. Migrationen laufen beim Start automatisch; vorher ein
   Datenbank-Backup machen (docs/install/backup-restore.md).
