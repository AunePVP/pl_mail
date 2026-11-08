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
`command()` wartet nach dem Login `max(timeoutSeconds, 240)` s auf die Antwort, wenn der Aufrufer
nicht weniger als 30 s verlangt hat. Verbinden, TLS, `CAPABILITY` und Login (auch XOAUTH2) behalten
den Timeout des Aufrufers. Test: `tests/Infrastructure/ImapClient/ForkCommandDeadlineTest.php`.

Bis 2026-11-02 galten die 240 s für jeden Befehl, auch `CAPABILITY`/`LOGIN`/`AUTHENTICATE` vor dem
Login, und überschrieben kürzere Timeouts: Ein Server, der grüßt und dann hängt, hielt einen Sync
4 min fest, und der 10-s-Verbindungstest im Browser lief in PHPs 30-s-Grenze (Fatal Error statt
Meldung).

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
blockieren.

Seit 2026-11-02 ebenfalls deaktiviert: `e2e.yml` (PHPUnit und Playwright, ca. 15–17 min pro PR).
Stattdessen laufen PHPUnit und PHPStan vor jedem Merge lokal (CLAUDE.md → Arbeitsweise). Für einen
Lauf in der CI, etwa bei einem größeren Update vom Original: Actions → E2E → „Enable workflow“, dann
„Run workflow“ auf dem Branch, danach wieder „Disable workflow“. Aktiv bleibt nur `docker.yml` (Image).

### 6. Anleitungen für KI-Assistenten

`CLAUDE.md` (Regeln, Arbeitsweise, Betrieb, Debuggen), `AGENTS.md` (Verweis darauf für Codex,
Cursor, Gemini CLI u. a.) und der Eintrag `CLAUDE.local.md` in `.gitignore`.

Die iOS-App ist ein eigener Fork (AunePVP/pl_mail_ios, lokal `ios/`, nur über `.git/info/exclude`
ignoriert, keine Dateiänderung); CLAUDE.md → „iOS-App“ regelt, wann ein Web-Feature dort ein Issue bekommt.

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

### 9. Reasoning des Schreib-Modells abschaltbar

Admin → KI → Sprachmodell, Schalter „Schreib-Modell darf nachdenken (Reasoning)“ direkt unter dem
Schreib-Modell, Standard an. Aus schickt bei jeder Chat-Anfrage zusätzlich:

- OpenAI-kompatibel (vLLM, SGLang, llama.cpp): `chat_template_kwargs: {"enable_thinking": false}`
- Ollama: `think: false`

An = keine zusätzlichen Felder, Anfragen wie im Original. Embeddings und Test-Button sind nicht betroffen.

Neue Dateien: `src/Entity/Fork/ForkAiOptions.php`, `src/Repository/Fork/ForkAiOptionsRepository.php`,
`src/Service/Fork/ForkReasoning.php`, `src/Form/Fork/ForkReasoningExtension.php` (Form-Type-Extension
für `AiSettingsType`, nur bei `advanced: true`, also nicht im Einrichtungsassistenten; speichert nur
auf der Route `app_admin_ai_settings`), `translations/fork.*.yaml` (eigene Domain `fork`),
`migrations/Version20261102120000.php`, Tests `tests/Service/Fork/ForkReasoningTest.php` und
`tests/Controller/Admin/ForkReasoningSwitchTest.php`.

Änderungen an Dateien des Originals (alle mit `FORK (FORK.md)` markiert):

- `src/Service/Ai/OpenAiClient.php`: optionaler Konstruktorparameter `?ForkReasoning`, `payload()`
  hängt dessen Felder an.
- `src/Service/Ai/OllamaClient.php`: dasselbe in `chat()` und `chatStream()`, `authorised()` reicht
  den Parameter weiter.
- `templates/admin/ai/_frame.html.twig`: eine Zeile `form_row(form.forkReasoningEnabled)`.

**Datenbank:** eigene Tabelle `fork_ai_options` (eine Zeile, Spalte `reasoning_enabled`; seit
Abweichung 18 auch `rerank_url`), keine Spalte in `ai_settings`. Gelesen wird pro Anfrage als Skalar, damit lange laufende Worker eine
Änderung sofort sehen. Nicht im Konfigurations-Backup (`app:backup` sichert sie als Teil der
Datenbank, der Konfig-Export des Originals kennt sie nicht).

**Grund:** Qwen auf vLLM denkt standardmäßig vor jeder Antwort; für Einsortieren und kurze Entwürfe
kostet das viel Zeit und bringt wenig.
**Kandidat fürs Original:** ja, dort als Feld in `ai_settings` (dort erst Issue, nur nach Freigabe).

### 10. `Cache-Control: immutable` für `/assets`

`frankenphp/Caddyfile`: Matcher `@assets` (`path /assets/*` und `file`) mit
`Cache-Control: public, max-age=31536000, immutable` vor `php_server`.

**Grund:** Die Seite zeigt morgens sofort Mail, reagiert aber 10 s lang auf keinen Klick. Gemessen
(Caddy-Access-Log, 2026-11-02): nach dem HTML lädt der Browser ~185 einzelne ES-Module (alle
Stimulus-Controller sind eager; Stimulus startet erst, wenn alle da sind), letztes Modul 9,5–11,7 s
nach dem HTML, serverseitig jedes < 30 ms. Das Original sendet für `/assets` kein `Cache-Control`,
nur ETag und Last-Modified; der Browser schätzt die Frische und fragt nach einer Nacht alles neu an.
Caddys ETag ist mtime+Größe, jedes neue Image gibt allen Dateien eine neue mtime: 184 bedingte
Requests nach einem Update, 184× die volle Datei. Die Dateinamen tragen den Inhalts-Hash
(`asset-map:compile`), sind also sicher dauerhaft cachebar. `file` im Matcher, damit ein 404 aus
`index.php` für einen nicht mehr existierenden Namen nicht ein Jahr gecacht wird.
**Kandidat fürs Original:** ja (dort erst Issue, nur nach Freigabe).

### 11. Neue Mail wartet beim Einsortieren nicht mehr 20 s auf sich selbst

`src/Infrastructure/Messaging/Handler/ClassifyMailHandler.php`: `standAside()` nur noch, wenn die
Nachricht nicht von `enrich_live` kommt (`EnrichmentRouter::LIVE !== $queue`). Test:
`tests/Infrastructure/Messaging/Handler/ForkLiveMailNoSelfWaitTest.php`.

**Grund:** Neue Mail, die nicht zurückgehalten wird (z. B. von einem bekannten Absender), geht wie
zurückgehaltene auf `enrich_live`. Der Handler hielt jede nicht zurückgehaltene Mail für
Hintergrundarbeit und ließ `enrich_live` vor (`LiveMailPriority`). Der Umschlag, den der Worker
gerade bearbeitet, steht aber selbst noch geliefert in `messenger_messages` und zählt als
„live-Arbeit in Bearbeitung“ – die Mail wartete also jedes Mal die Obergrenze von 20 s auf sich
selbst ab, mehrere gleichzeitig ankommende nacheinander. Gemessen in der Produktion (2026-11-02):
Testmail um 11:37:41 gespeichert, KI gefragt um 11:38:01; bei per IDLE angekommener, nicht
zurückgehaltener Mail lag der Abstand meist bei 20–30 s oder über 60 s, bei zurückgehaltener im
Median bei 0,4 s. Ein „Neu fragen“ ohne Warteschlange und echte Hintergrundarbeit treten weiter
zur Seite.
**Kandidat fürs Original:** ja (dort erst Issue, nur nach Freigabe).

### 12. Filterregeln „Archivieren“ und „Als Spam markieren“ legen Mail richtig ab

`src/Service/Rule/RuleActionExecutor.php`: `moveOutOfInbox()` gibt bei „Archivieren“ das
Archiv-Label (wie `ThreadStatusUpdater::archive()`); „Als Spam markieren“ geht über die neue Methode
`moveToSpam()` denselben Weg wie das Verschieben-Menü (`attachLabel` + `moveCopies` +
`detachLabel(Inbox, moveOnImap: false)`), statt auf dem Server zu archivieren. Test:
`tests/Service/Rule/ForkRuleLeavesInboxTest.php`.

**Grund:** In der Produktion laufen 7 Regeln „Als Spam markieren“. Am 2026-11-02 lagen 66 so
erfasste Mails eines Kontos auf dem Server in Archivordnern statt im Spam-Ordner – plMail zeigte
sie als Spam, alle anderen Programme im Archiv, der Spamfilter des Servers lernte nichts. Per Regel archivierte Mail hatte gar kein Label mehr und stand in keiner
Liste (nicht Posteingang, nicht Archiv). Schon so abgelegte Mail wird nicht nachträglich verschoben.
**Kandidat fürs Original:** ja (dort erst Issue, nur nach Freigabe).

### 13. Abmelden-Knopf aus `List-Unsubscribe`

Im Kopf jeder Nachricht links neben dem Datum ein Knopf „Abmelden“, dazu derselbe Punkt im ⋮-Menü,
wenn die Mail einen `List-Unsubscribe`-Header hat (RFC 2369). Ein Weblink öffnet sich in einem neuen
Tab (`rel="noopener noreferrer nofollow"`), sonst öffnet ein `mailto:` einen Entwurf an die Adresse
(`app_compose_new?to=`, nur die Adresse, Betreff/Text des mailto werden nicht übernommen). Weblinks
gehen vor, egal in welcher Reihenfolge der Header sie nennt; nur `http(s)` und `mailto:`. Kein
Knopf in Spam und Papierkorb. plMail schickt selbst nichts ab (keine Ein-Klick-Abmeldung nach
RFC 8058 – das wäre ein Request des Servers an fremde Adressen).

**Ohne Header: Link aus dem Mailtext** (`ForkUnsubscribeFinder`, seit 2026-11-02). Gesucht wird nur,
wenn der Header fehlt oder nichts Brauchbares enthält, nicht in Entwürfen und nicht in Antworten
(`In-Reply-To`), und nur `http(s)`-Links außerhalb von Zitaten (`<blockquote>`, `gmail_quote` u. a.).
Der Hinweis am Knopf sagt dann, dass der Link aus dem Text stammt. Regeln, in dieser Reihenfolge:

1. Ein Link, dessen Text (oder `alt` eines Bildes darin) das sagt: Wortstämme wie `unsubscri`,
   `abmeld`, `abzumeld`, `abbestell`, `désabonn`, `opt-out`, `afmeld`, `退订`.
2. Sonst der einzige Weblink im kleinsten umgebenden Element (≤ 400 Zeichen), dessen Text so ein
   Wort enthält – und nur, wenn der Link „hier“/„here“/„Link“/„klicken Sie hier“ … heißt oder Text
   bzw. Adresse nach Abmeldung/Einstellungen aussieht (`preferences`, `settings`, `einstellungen`,
   `subscription`, …). Ohne diese Bedingung nahm die Suche „Help Centre“, „Datenschutzerklärung“,
   „Follow us on Twitter“ aus Sätzen wie „To unsubscribe, see our Help Centre“.
3. Von mehreren Treffern der letzte (Fußzeile). Nur-Text-Mails: eine Zeile mit so einem Wort und
   die eine URL darauf oder auf der nächsten Zeile.

Nie: `tools.google.com/dlpage/gaoptout` (Analytics-Opt-out aus Datenschutzzeilen). Geprüft am
2026-11-02 an allen Mails der Produktion ohne Header (nur lesend, im Container): Knopf bei 1 469 von
2 349 Mails mit Abmeldewort im Text, bei 18 von 21 755 ohne; die Fehltreffer der ersten Fassung
(s. o.) sind weg, Bitpanda u. ä. („kannst du dich hier abmelden“) werden gefunden.

Neue Dateien: `src/Twig/Fork/ForkUnsubscribeExtension.php` (Twig-Funktion `fork_unsubscribe()`,
liest auch MIME-kodierte Header und Links ohne spitze Klammern), `src/Service/Fork/ForkUnsubscribeFinder.php`,
`templates/fork/_unsubscribe.html.twig`, Schlüssel `message.fork_unsubscribe.*` in
`translations/fork.*.yaml`, Tests `tests/Twig/Fork/ForkUnsubscribeTest.php`,
`tests/Service/Fork/ForkUnsubscribeFinderTest.php` und `tests/e2e/fork-unsubscribe.spec.ts`.

Änderungen an Dateien des Originals (mit `FORK (FORK.md)` markiert), je ein `include`:

- `templates/mail/_thread_message.html.twig`: in `thread-message-meta` vor dem Datum.
- `templates/mail/_message_actions.html.twig`: vor „Filter aus dieser Nachricht erstellen“.

**Datenbank:** keine Änderung; der Header steht schon in `message.headers`.

**Grund:** Den Abmeldelink im Kleingedruckten am Ende einer Mail muss man suchen. In der Produktion
(2026-11-02) hatten 5 153 von 48 633 Mails den Header, 5 133 davon mit brauchbarem Ziel.
**Kandidat fürs Original:** ja, als Funktion (dort erst Issue, nur nach Freigabe).

### 14. Buchungszeiten ohne Zeitzone sind Ortszeit

schema.org-Zeiten ohne Offset (`"2026-03-12T18:10:00"`) liest der Extractor als Uhrzeit in der
Zeitzone des Nutzers statt als UTC, aber nur, wenn im ganzen Knoten keine einzige Zeit einen Offset
hat. Ganztägiges (Hotel mit reinem Datum) bleibt unverändert. Der Dedup-Schlüssel bleibt gleich (er
nimmt das Datum vom Zifferblatt), also korrigiert `app:backfill events` vorhandene Termine, statt sie
zu verdoppeln.

Neue Dateien: `src/Service/Fork/ForkFloatingTime.php`, Test `tests/Service/Fork/ForkFloatingTimeTest.php`.

Änderung an einer Datei des Originals (mit `FORK (FORK.md)` markiert):

- `src/Service/Calendar/Extraction/StructuredDataEventExtractor.php`: optionaler
  Konstruktorparameter `?UserTimezoneResolver`, in `extract()` vor `toExtractedEvent()` der Aufruf
  `ForkFloatingTime::localised()`.

**Datenbank:** keine Änderung.

**Grund:** Booking.com schickt Flugzeiten ohne Offset (Google: dann Ortszeit des Flughafens).
Ein Abflug um 18:10 stand in der Produktion (2026-11-02) um 19:10 im Kalender. Die Zone
des Flughafens steht nicht im Markup; die Zone des Nutzers ist für alle Reisen richtig, die in ihr
beginnen, und nie schlechter als UTC.
**Kandidat fürs Original:** ja (dort erst Issue, nur nach Freigabe).

### 15. Reisebuchungen ohne Markup liest das Sprachmodell

Ein weiterer Extractor `ForkLlmTravelExtractor` (Priorität 10, also nach ICS und JSON-LD) fragt das
Schreib-Modell nach Flügen, Zügen, Bussen, Hotels, Mietwagen und Tickets – aber nur bei Mail ohne
Kalenderteil und ohne JSON-LD, nicht in Werbung/Foren, nur wenn Betreff oder Text ein Reisewort
enthalten und der Schalter „Einsortieren“ (`AiFeature::Categorise`) an ist. Jeder Fund muss belegt
sein: Zitat, Flug-/Zugnummer, Buchungsnummer und Uhrzeit müssen wörtlich in der Mail stehen, sonst
wird er verworfen. Die Antwort wird pro Mail 30 Tage gecacht („Änderung übernehmen“ liest neu und
soll dasselbe sehen). Dedup-Schlüssel: Flug/Zug/Bus = Nummer + Ortsdatum (dieselbe Maschine aus
Agentur- und Airline-Mail ist ein Termin), sonst Absender-Domain + Buchungsnummer bzw. Startzeit.
Eine nackte Flugnummer („1051“) bekommt das Kürzel, das in der Mail (sonst im Titel des Modells)
direkt davor steht. Kein Vorschlag, wenn derselbe Termin (gleiche Art, gleiche Startzeit) schon aus
einer verlässlichen Quelle im Kalender des Nutzers steht (Booking.com: Bestätigung mit Markup, dann
„Buchungsinformationen“ ohne), und keiner für eine Reise, die seit über einem Tag vorbei ist – hier
gegen *jetzt* (Symfony Clock), nicht gegen das Mail-Datum, sonst bietet das Nachlesen alter Mail den
Urlaub vom letzten Sommer an.

Die Funde sind `EventSource::Llm` (vom Original für genau diesen Zweck angelegt, `isTrusted()` =
false) und landen **nur als Angebot** auf der Karte über der Mail („In den Kalender?“), nie
ungefragt im Kalender, auch nicht bei DKIM/DMARC-geprüftem Absender; eine spätere Mail ändert einen
solchen Termin nur nach Klick.

Für Mail, die schon da ist: `php bin/console app:fork:llm-events --since=2026-09-01` (`--dry-run`
zählt nur). Läuft inline über `ExtractEventsHandler`, wiederholbar.

Neue Dateien: `src/Service/Fork/Calendar/ForkLlmTravelExtractor.php`,
`src/Command/Fork/ForkLlmEventsCommand.php`, Test `tests/Service/Fork/ForkLlmTravelExtractorTest.php`.
Eine Zeile für den Befehl in der Befehlstabelle von `CONTRIBUTING.md` (verlangt
`DocumentationCoverageTest`).

Änderungen an Dateien des Originals (mit `FORK (FORK.md)` markiert):

- `src/Service/Calendar/EventReconciler.php`: in `isOfferedByMail()` und `mayApplyUnasked()` je
  eine Abfrage `$claim->source->isTrusted()` vorneweg. Das Original beschreibt dieses Verhalten für
  nicht vertrauenswürdige Quellen in `EventSource::isTrusted()`, setzt es im Reconciler aber nicht
  um (bisher gab es keine solche Quelle).
- `src/Repository/Calendar/EventSourceLinkRepository.php`: `ForkLlmTravelExtractor::NAME` in
  `CARD_EXTRACTORS`, sonst findet die Karte über der Mail den Vorschlag nicht – ohne Karte gibt es
  keinen „Zum Kalender hinzufügen“-Knopf (in der Produktion am 2026-11-02 bemerkt).

**Datenbank:** keine Änderung (Cache im normalen Symfony-Cache).

**Grund:** Trip.com, Parkplatz- und Security-Slot-Buchungen, Airlines (Check-in, Gate), Hotels schicken kein JSON-LD;
diese Buchungen kamen nie in den Kalender. Gemessen gegen Qwen auf dem Spark (2026-11-02, Reasoning
aus): ~5 s pro Reisemail, < 1 s für Werbung; Agentur-, Slot- und Gate-Mails richtig gelesen.
**Kandidat fürs Original:** als Idee ja (dort erst Issue, nur nach Freigabe); der Reconciler-Teil
allein ist eine Fehlerbehebung für die künftige Llm-Quelle.

### 16. Suche: kommende Termine und KI-Antwort über den Treffern

Auf der ersten Seite einer Suche mit Suchwörtern stehen über der Trefferliste zwei Felder, jedes ein
eigener Turbo-Frame mit `loading="lazy"`; die Liste wartet auf keins davon.

- **Termine dazu** (`ForkTripFinder`, ohne KI, Millisekunden): kommende Termine des Nutzers
  (gestern bis +400 Tage, nicht abgesagt/abgelehnt), wenn das Suchwort die Art nennt („Flug“,
  „Zug“, „Hotel“, „Paket“ … → `ExtractionKind`) oder im Titel/Ort steht. Darunter aufklappbar die
  zugehörigen Mails: alle, aus denen der Termin gelesen wurde (`event_source_link`), plus Mails, deren
  Betreff dieselbe Flug-/Zugnummer nennt (etwa eine Parkplatzbuchung zur selben Flugnummer). Nur angebotene Termine (Abweichung
  15) sind als „Vorschlag“ markiert.
  Eine Flugnummer fliegt jede Woche, deshalb gehört eine Mail per Nummer nur dann zum Termin, wenn sie
  frühestens 7 Tage vor seiner ersten eigenen Mail (der Buchung; ohne eigene Mail 90 Tage vor Beginn)
  und spätestens 2 Tage nach seinem Ende kam, an keinem anderen Termin hängt und kein anderer Termin
  mit derselben Nummer zwischen ihr und diesem liegt. Sonst hing die Slot&Fly-Buchung vom April am
  Flug im Dezember.
  Suchwörter passen nur am Wortanfang, und Fragewörter („ich“, „was“, „als“, „letztes“ …,
  `STOP_WORDS`) zählen nicht: „was habe ich als letztes bestellt“ fand sonst jeden Flug ab „Zur*ich*
  Airport“. „bestell…“/„order…“ nennen die Art Lieferung.
- Die Links in allen drei Feldern öffnen die Mail wie eine Zeile der Liste
  (`data-action="click->mail--mail-pane#open"`); die Frame-Platzhalter tragen `target="_top"`.
  Vorher navigierte ein Klick den Lazy-Frame selbst (Turbo übernimmt nur den *Inhalt* des
  geladenen Frames, nicht dessen `target`), und die Seite blieb weiß bis zum Neuladen. Browsertest
  `tests/e2e/fork-search-trips.spec.ts`.
- **Kurz gesagt** (`ForkSearchAnswer`, Schalter „Zusammenfassen“ = `AiFeature::Summary`): das
  Schreib-Modell bekommt die ersten 20 Treffer (Betreff, Absender, Datum, markierter Ausschnitt –
  nie ganze Mails) und die gefundenen Termine und antwortet mit 1–3 Sätzen, bis zu drei wichtigsten
  Mails und Gruppen von Treffern zum selben Vorgang. Nur IDs aus den gegebenen Treffern werden
  angezeigt. Cache 6 h pro Nutzer, Suchtext und Trefferstand. Die Treffer sind dieselben wie in der
  Liste, also auch die, die nur die Embedding-Suche gefunden hat; ohne Reranker die ersten 20 in der
  Sortierung der Liste (meist „Neueste zuerst“), mit Reranker die 20 besten von 50. Der Prompt steht
  im Code (`ForkSearchAnswer::messages()`), nicht in den Einstellungen.
  **Gestreamt:** Der Frame zeichnet erst einen Kasten (`fork--search-answer`,
  `assets/controllers/fork/search_answer_controller.js`), der `/mail/search/fork/answer/stream` als
  NDJSON liest (`token`, dann `done` oder `error`, wie die Schreibhilfe des Originals) und den Text
  zeigt, während das Modell schreibt. `ForkJsonFieldStream` zieht dafür das Feld `answer` aus dem
  halbfertigen JSON. Bei `done` lädt der Frame `…/answer?after=1` und zeigt die fertige Antwort mit
  Links und Gruppen aus dem Cache; `after` startet nie einen neuen Stream. Wer die Seite verlässt,
  bricht den Aufruf ab (`connection_aborted()`), das Modell hört dann auf.

Neue Dateien: `src/Controller/Fork/ForkSearchInsightController.php` (Routen
`/mail/search/fork/trips`, `/mail/search/fork/answer` und `/mail/search/fork/answer/stream`),
`src/Service/Fork/Search/ForkTripFinder.php`, `ForkTrip.php`, `ForkSearchAnswer.php`, `ForkAnswer.php`,
`ForkAnswerRequest.php`, `ForkJsonFieldStream.php`, `assets/controllers/fork/search_answer_controller.js`,
`templates/fork/search/_insight.html.twig`, `_trips.html.twig`, `_answer.html.twig`, Schlüssel
`search.fork_insight.*` in `translations/fork.*.yaml`, Tests
`tests/Controller/Fork/ForkSearchInsightTest.php`, `tests/Service/Fork/ForkJsonFieldStreamTest.php`,
`tests/e2e/fork-search-answer.spec.ts`.

Änderung an einer Datei des Originals (mit `FORK (FORK.md)` markiert):

- `templates/search/search.html.twig`: ein `include` von `fork/search/_insight.html.twig` unter dem
  Hinweis zur Suche nach Bedeutung.

**Datenbank:** keine Änderung.

**Grund:** Wer „Flug“ sucht, will seinen nächsten Flug sehen, nicht 284 Treffer, von denen acht zum
selben Flug gehören. Gemessen gegen Qwen auf dem Spark (2026-11-02, Reasoning aus): 8 s für „Flug“
mit 20 Treffern (~2 500 Tokens), drei richtige Gruppen.
**Kandidat fürs Original:** als Idee (dort erst Issue, nur nach Freigabe).

### 17. Zeichen, das über zwei MIME-Wörter geteilt ist

`src/Domain/Helper/MimeHeaderHelper.php` (`run()`, mit `FORK (FORK.md)` markiert): benachbarte
Encoded Words mit demselben Zeichensatz werden erst als Bytes aneinandergehängt und dann umgewandelt,
statt Wort für Wort. Test: `tests/Domain/Helper/ForkMimeSplitCharacterTest.php`.

**Datenbank:** keine Änderung. Schon gespeicherte Betreffs bleiben, wie sie sind (Produktion
2026-11-02: 14 Mails, davon 10 von Booking.com).

**Grund:** Booking.com teilt das „ä“ (`C3 A4`) zwischen zwei Base64-Wörtern
(`…best\xC3?=` `=?UTF-8?B?\xA4tigt…`), was RFC 2047 §5 verbietet. Wort für Wort umgewandelt, wurde
jede Hälfte als cp1252 „repariert“: „bestÃ¤tigt“.
**Kandidat fürs Original:** ja, Fehlerbehebung (dort erst Issue, nur nach Freigabe).

### 18. Reranker für die Suche (Admin → KI oder `FORK_RERANK_URL`)

**Admin → KI**, Feld „Reranker für die Suche (Adresse)“ unter „Suche nach Bedeutung“, oder die
Umgebungsvariable **`FORK_RERANK_URL`**: volle Adresse eines `/rerank`-Endpoints im Cohere/Jina-Format
(POST `{query, documents, top_n, instruction}`, Antwort `{results: [{index, relevance_score}]}`), z. B.
Qwen3-Reranker. Der Server dahinter gehört nicht zum Fork (Betrieb: `CLAUDE.local.md`). Die Adresse auf der Seite gilt vor der Variable; ist beides leer, ist der
Reranker aus und alles wie in Abweichung 16. Die Adresse muss **aus dem plmail-Container** erreichbar
sein (LAN-IP bzw. Docker-Gateway, nicht `*.local`). Timeout 8 s; jeder Fehler heißt „ohne Ranking“
und gilt für diese Suche 60 s, damit Stream und Nachladen von „Kurz gesagt“ nicht noch einmal warten.
(Mit 4 s scheiterte jedes Ranking: Qwen3-Reranker-4B braucht auf der A380 für 50 Treffer 5,9 s, mit
geteiltem Präfix-Cache 2,7 s.) Gleichzeitige Anfragen beider Felder rechnen nicht doppelt: der
Stampede-Lock des Symfony-Caches (`LockRegistry`, im Webserver aktiv) lässt die zweite warten.
„Testen“ schickt der Adresse im Feld eine kleine Probe (ein Treffer, ein Nicht-Treffer) und zeigt
Antwortzeit und Scores unter dem Feld, ohne zu speichern.

- `ForkSearchCandidates` holt die ersten 50 Treffer der Suche (wie die Liste, als angemeldeter
  Nutzer), lässt sie vom Reranker bewerten (Absender, Datum, Betreff, markierter Ausschnitt) und
  sortiert nach Score, mild nach Alter gewichtet (höchstens halbiert, Halbwertszeit grob 2 Monate).
  Ergebnis 10 min gecacht, damit beide Felder es nur einmal kosten.
- Neues Feld **„Beste Treffer“** (Frame `fork-search-best`, Route `/mail/search/fork/best`): bis zu 3
  Treffer mit Score ≥ 0,5, beim ersten Aufruf einer Suche nach 4–5 s (Suche plus Ranking), danach
  aus dem Cache.
- **„Kurz gesagt“** bekommt die 20 besten statt der 20 neuesten Treffer.

Neue Dateien: `src/Service/Fork/Search/ForkReranker.php`, `ForkSearchCandidates.php`,
`ForkCandidate.php`, `templates/fork/search/_best.html.twig`, Schlüssel
`search.fork_insight.best_heading`, Test `tests/Controller/Fork/ForkSearchRerankTest.php`. Geändert
(nur Fork-Dateien): `ForkSearchAnswer`, `ForkSearchInsightController`, `_insight.html.twig`.
Für das Feld: `src/Form/Fork/ForkRerankExtension.php` (wie `ForkReasoningExtension`, Abweichung 9),
`templates/fork/admin/_rerank.html.twig`, Schlüssel `admin.ai.field.rerank_url*` und
`admin.ai.fork_rerank.*`, `migrations/Version20261102210000.php`, Test
`tests/Controller/Admin/ForkRerankSettingTest.php`, Browsertest `tests/e2e/fork-rerank-setting.spec.ts`;
`ForkAiOptionsRepository::forWrite()`, damit
Reasoning-Schalter und Reranker beim ersten Speichern eine Zeile sind und nicht zwei.

Änderung an einer Datei des Originals (mit `FORK (FORK.md)` markiert):

- `templates/admin/ai/_frame.html.twig`: ein `include` von `fork/admin/_rerank.html.twig` nach
  `form.searchEnabled`.

**Datenbank:** Spalte `rerank_url` (TEXT, NULL) in der Fork-Tabelle `fork_ai_options`
(Abweichung 9), keine Änderung an Tabellen des Originals.

**Grund:** Die Suche sortiert nach Datum, nicht danach, ob eine Mail die Frage beantwortet. Gemessen
auf der Arc A380 (2026-11-02, 22 echte Suchen, je 50 Treffer, Noten 0/1/2 vom Schreib-Modell, mit der
Altersgewichtung oben):

| | Zeit für 50 | nDCG@20 | Treffer in den 20 |
|---|---|---|---|
| ohne Reranker | – | 0,644 | 0,587 |
| Qwen3-Reranker-0.6B INT8 | 1,1 s | 0,825 | – |
| Qwen3-Reranker-4B INT8 | 5,8 s | 0,836 | 0,792 |
| **4B INT4, KV-Export, Präfix einmal pro Batch, 16 pro Batch (läuft)** | **2,7 s** | **0,841** | **0,820** |

Der Reranker erkennt Buchungsmails, achtet aber nicht aufs Datum (deshalb die Altersgewichtung; mit
ihr erreicht „Flug“ 0,918 und „Flüge“ 0,844 statt 0,844 und 0,737 ohne Reranker). Nicht gelohnt
haben sich: RRF-Mischung mit der Reihenfolge der Suche, eine eigene Anweisung statt der
Standard-Anweisung, 50 pro Batch (doppelt so langsam), 0.6B mit INT4 (schlechter, kaum schneller).
**Kandidat fürs Original:** als Idee (dort erst Issue, nur nach Freigabe).

### 19. Insight-Flugkarte: Uhrzeit aus der Mail ist Ortszeit

`src/Service/Insight/Extractor/FlightExtractor.php` (mit `FORK (FORK.md)` markiert): optionaler
Konstruktorparameter `?UserTimezoneResolver`; eine im Text genannte Abflugzeit („15:45“) wird in der
Zeitzone des Nutzers gelesen statt als UTC. Der `dedupeKey` nimmt den Tag aus dem Text (wie bisher).
Ohne Resolver (die bestehenden Unit-Tests bauen den Extractor mit `new`) bleibt alles wie im Original.
Test: `testTheFlightInsightReadsTheTimeInTheReadersZone` in `tests/Service/Fork/ForkFloatingTimeTest.php`.
Vorhandene Karten korrigiert `app:backfill insights --since=…`.

**Datenbank:** keine Änderung.

**Grund:** Die Karte „In dieser Konversation gefunden“ zeigte einen 15:45-Abflug als 16:45 (2026-11-02).
**Kandidat fürs Original:** ja, Fehlerbehebung (dort erst Issue, nur nach Freigabe).

### 20. KI-Suche als Chat

Im Suchfeld oben steht rechts der Knopf **„KI-Suche“**. Der Chat braucht den Schalter
„Zusammenfassungen von Gesprächen“ (`AiFeature::Summary`, wie „Kurz gesagt“); ist er aus, sagt die
Suchseite das. Der Knopf selbst steht immer da: ihn davon abhängig zu machen, kostete auf jeder Seite
eine Abfrage (`ThreadListQueryBudgetTest`). Er öffnet die Suchseite mit `fork_ai=1`. Dann sucht
`SearchController` **nicht** nach dem Satz selbst (keine Trefferliste, kein Embedding, keine Felder
aus Abweichung 16/18), sondern zeigt `templates/fork/search/ai.html.twig` mit dem Chat, dessen erste
Nachricht der Text im Suchfeld ist. Darunter ein Eingabefeld für Rückfragen. Eine Runde
(`ForkSearchChat::turn()`):

1. `ForkSearchPlanner`: das Schreib-Modell liest die letzten Nachrichten und die neue und schreibt
   **eine** Suche (höchstens 2) aus 8–12 Alternativen in der Syntax der Suche selbst, verbunden mit
   `or` („bestellung or bestellt or bestellbestätigung or order or ordered or purchase …“), auf
   Deutsch **und** Englisch und mit allen Wortformen, denn eine Alternative trifft nur genau dieses
   Wort (bei `or` läuft im Original nur `websearch_to_tsquery`, kein Präfix). Eine Alternative darf
   aus mehreren Wörtern bestehen, die alle vorkommen müssen („hamburg flixbus or hamburg fernbus“).
   Für eine bestimmte Sache nur deren Wörter, keine allgemeinen daneben („jacke or jacket or …“,
   nicht „… or order“). Filter wie `from:` nur auf Wunsch. Dazu die Frage, wie sie für sich allein
   steht („und zurück?“ → „Wann fahre ich von Hamburg zurück?“), den gefragten Zeitraum („diese
   Woche“ = heute bis Sonntag, „was steht an“ = heute + 13 Tage, auch vergangen: „Dezember 2024“,
   „2023“; sonst `null`) und ob nach dem Ersten gefragt ist („das erste Mal“, „seit wann“ →
   `oldest`). Keine Suchen = Antwort aus den Treffern der letzten Runde. Eine erste Frage ohne Suchen
   und ohne Zeitraum sucht ihre eigenen Wörter (ohne Fragewörter, bis 4, mit `or`).
   Der Plan wird **gestreamt gelesen und abgebrochen**, sobald das JSON-Objekt zu ist oder 4000
   Zeichen überschreitet (Timeout 20 s): Bei einer Rückfrage schrieb Qwen einmal zwei Minuten lang
   „or …“ bis zum Timeout. Abgebrochen gilt, was sich lesen lässt, sonst die eigenen Wörter.
2. `ForkSearchCandidates::forSearches()`: die Suchen laufen als angemeldeter Nutzer über
   `MessageThreadRepository::searchPage()` (je 50 – bei weniger als 50 durchsucht das Original bei
   einem einzelnen Wort zusätzlich jeden Mailtext per `ILIKE`, 3 s –, abwechselnd zusammengelegt, bis
   40), nur nach Wörtern, **ohne Reranker**, neueste zuerst. Ein vergangener Zeitraum wird zu
   `after:`/`before:` jeder Suche und die Suche sortiert dann nach Trefferqualität (`Relevance`)
   statt nach Datum: innerhalb von 2023 füllten sonst 40 Dezember-Newsletter mit „booking“ den Platz,
   das Hotel fehlte. Ein kommender Zeitraum filtert nicht (die Buchung für nächste Woche kam vor
   Wochen). Bei `oldest` holt jede Suche ihre ältesten statt ihrer neuesten (die Suche kennt nur
   „neueste zuerst“, also deren letzte Seiten). Die Frage selbst wird nicht nach Bedeutung gesucht:
   das brachte vor allem Newsletter in Reisestimmung („Deine Reisewoche startet jetzt“ zu „welche
   Busfahrt habe ich diese Woche“).
3. Das Modell antwortet aus allen 40 (Betreff, Absender, Datum, Ausschnitt bis 200 Zeichen, JSON ohne
   Einrückung – nie ganze Mails) und dem Kalender (`ForkTripFinder::between()` für jeden Termin im
   Zeitraum, `find()` für die passenden), gestreamt, per „du“, mit bis zu 5 Mails zum Öffnen; bei
   „zuletzt“ die neuesten bis zu drei mit Datum. Verlinkt werden nur IDs aus den Treffern oder den
   Mails, aus denen ein Termin gelesen wurde; die Links öffnen die Mail im Lesebereich. Unter
   „Gesucht nach“ stehen die ersten 6 Alternativen, dann „…“.

Die Seite liest NDJSON von `POST /mail/search/fork/chat` (CSRF-Token `ajax` aus dem Meta-Tag):
`conversation`, `search`, `results`, `token`…, `done` oder `error`. Der Verlauf (12 Nachrichten, die
letzten Treffer) liegt 6 h im Cache unter Nutzer-ID + zufälliger ID, keine Tabelle.

**Warum ohne Reranker, und warum nicht alle Mails ans Modell** (gemessen 2026-11-04 auf der
Produktion, Qwen auf dem Spark, Reasoning aus): Qwen liest ~1.700 Tokens/s und schreibt ~46/s.
Alle Mails (48.700) wären ~1,3 Mio. Tokens; schon die Betreffzeilen von 30 Tagen (470 Mails,
12.000 Tokens) brauchen 7 s, bevor das erste Wort kommt. Der Reranker wählte 15 von 30 für das Modell
aus und brauchte dafür 2–3 s; 40 kompakte Treffer liest das Modell in ~2 s selbst, urteilt besser und
kennt anders als der Reranker das Datum. Die Suchen: „bestellung“, „bestellt“, „order“ einzeln
nacheinander 6,9 s; 15 Alternativen mit `or` in einer Suche 0,5–1,1 s.
Vorher fand „was habe ich als letztes bestellt“ (Suchen „bestellung“, „bestellt“) zwei von vier
Bestellungen der letzten Woche, nicht die Jacke, deren Mail „Order … confirmed“ heißt. Jetzt alle
vier; „und eine Jacke“ direkt die Jacke. Erstes Wort nach 4–6 s (Planen 1,6–3 s, Suche
0,3–1 s, Lesen ~2 s); „was steht die Woche an“ (nur Kalender) 2,5 s. Stand davor (2026-11-03): mit
Reranker und bis zu 3 Einzelsuchen 5–7 s, in der allerersten Fassung mit Suche nach Bedeutung 12–15 s.
Alte Mail: „wann bin ich das erste Mal mit Flixbus gefahren“ findet die älteste FlixBus-Mail im
Postfach, „was habe ich im Dezember 2024 bestellt“ die Bestellungen des ganzen Monats statt nur
seiner letzten Tage, „in welchem Hotel war ich 2023“ wieder das Hotel.

`ForkTripFinder` kennt seither auch „bus“/„fernbus“ als Art (Ticket), das gilt auch für „Termine
dazu“ (Abweichung 16).

Neue Dateien: `src/Service/Fork/Search/ForkSearchChat.php`, `ForkSearchPlanner.php`,
`ForkSearchPlan.php`, `src/Controller/Fork/ForkSearchChatController.php`,
`src/Twig/Fork/ForkSearchChatExtension.php` (`fork_search_chat()`),
`assets/controllers/fork/search_chat_controller.js`, `ai_search_controller.js`,
`templates/fork/search/ai.html.twig`, `_chat.html.twig`, `_ai_button.html.twig`, Schlüssel `search.fork_chat.*` in
`translations/fork.*.yaml`, Tests `tests/Controller/Fork/ForkSearchChatTest.php` und
`tests/e2e/fork-search-chat.spec.ts`. Geändert (nur Fork-Dateien): `ForkSearchCandidates`
(`forSearches()` ohne Reranker; `for()` für „Beste Treffer“ und „Kurz gesagt“ unverändert, mit
Reranker), `ForkTripFinder` (`between()`, `bus`).

Änderungen an Dateien des Originals (mit `FORK (FORK.md)` markiert):

- `src/Controller/Mail/SearchController.php`: bei `fork_ai=1` ein früher `return` mit
  `fork/search/ai.html.twig`, vor der Suche.
- `templates/_partials/_topbar.html.twig`: ein `include` von `fork/search/_ai_button.html.twig` im
  Suchfeld, und rechts mehr Innenabstand im Feld (`pr-10 sm:pr-28` statt `pr-4`), damit der Text nicht
  unter den Knopf läuft.

**Datenbank:** keine Änderung.

**Grund:** Die Suche verlangt, dass jedes Wort in der Mail steht; „wann ist meine Busfahrt nach
Hamburg“ fand die Busbuchung deshalb nicht (Produktion 2026-11-03: 9 Treffer, nur aus der
Suche nach Bedeutung, die Buchung nicht dabei).
**Kandidat fürs Original:** als Idee (dort erst Issue, nur nach Freigabe).

**Nachtrag 2026-11-07: KI-Kästen und Chat überstehen Listen-Refresh, „Zurück“ und Neuladen.**
Beobachtet auf der Produktion (Zugriffslog): Solange eine Suche offen ist, liest
`mail--mail-pane#_refreshList` die Liste bei jedem Sync-Ereignis, beim Polling und beim Zurückkehren
zum Tab neu (alle 15–60 s) und morpht den Bereich `rows`. Die Kästen aus Abweichung 16 und der Chat
stehen dort als erstes `<li>`, und die Server-Kopie davon ist leer: die drei Kästen klappten zu und
luden neu (aus dem Cache, darum sofort wieder da), der Chat verlor seine Nachrichten und die
Gesprächs-ID. Beide `<li>` (`_insight.html.twig`, `ai.html.twig`) tragen jetzt `data-swipe-owned`,
das Zeichen des Originals für „hält der Client gerade“: `_mayMorph()` überspringt solche Knoten
samt Inhalt. Kein Listeneintrag, also für Wischgesten und Zeilen-Code unsichtbar.
Zweitens öffnete „Zurück“ (und F5) die KI-Suche neu und schickte die Frage noch einmal ans Modell:
Die Mail-Seiten haben `turbo-cache-control: no-cache`, es gibt also nie einen Schnappschuss, und
`connect()` fragte bei leerem Verlauf. Jetzt hält der Chat jede fertige Runde (alle Frames außer
`token`) in `sessionStorage` (`fork-search-chat`, je Frage, höchstens 8 Fragen je Tab) und zeichnet
sie beim Zurückkommen neu, mit Gesprächs-ID für Rückfragen. Der Knopf „KI-Suche“ vergisst die Frage
vorher (`forgetSearchChat()`), Drücken ist also immer ein neuer Chat. Eine Runde, die beim Verlassen
noch lief, ist nicht gespeichert und wird beim Zurückkommen neu gefragt.
Geändert (nur Fork-Dateien): `templates/fork/search/_insight.html.twig`, `ai.html.twig`,
`assets/controllers/fork/search_chat_controller.js`, `ai_search_controller.js`; Browsertest
`tests/e2e/fork-search-refresh.spec.ts`. **Datenbank:** keine Änderung.

### 21. IDLE alle 5 statt 29 Minuten neu anstoßen

`IDLE_REISSUE_SECONDS` in `src/Service/Imap/ImapIdleListener.php` ist 5 Minuten statt 29 (mit
`FORK (FORK.md)` markiert, eine Zeile plus Kommentar).

**Grund:** Eine Push-Verbindung, die still stirbt (ohne FIN/RST, etwa weil ein NAT sie vergisst
oder der Server sie fallen lässt), merkt plMail erst am `DONE`, mit dem es IDLE neu anstößt. Bei 29
Minuten wartete neue Mail bis zum nächsten geplanten Sync. Gemessen am iCloud-Konto über drei Tage
(Produktion, 2026-11-04): die Hälfte der per IDLE gekommenen Mail nach 5 s, aber 14 Mails später als
30 s, sieben davon 4–14 Minuten. Eine eigene Testmail kam erst mit dem Sync-Klick. Jetzt bleibt eine
tote Verbindung höchstens gut 5 Minuten unbemerkt (5 min plus 30 s Wartezeit auf die Antwort zum
`DONE`). Beim Neuaufbau meldet `SELECT` den Bestand (`EXISTS`), und der Ordner wird synchronisiert.
Kosten: ein `DONE`/`IDLE` je Ordner alle 5 Minuten.

Nicht abgedeckt: Eine Verbindung, die lebt, aber vom Server nichts mehr gemeldet bekommt. Dafür
bleibt der geplante Sync (Abfrageintervall des Kontos).

**Datenbank:** keine Änderung.
**Kandidat fürs Original:** ja (dort erst Issue, nur nach Freigabe).

### 22. JMAP-Methoden für die iOS-App: Abmelden und KI-Suche

Zwei Methoden nur dieses Forks, damit die iOS-App (AunePVP/pl_mail_ios, dort FORK.md 4 und 5) zeigt,
was die Web-App kann:

- **`ForkUnsubscribe/get`** (`accountId`, `ids`) → `list` mit `{id, url, fromBody}` oder
  `{id, mailto}`: dasselbe Ziel wie der Abmelden-Knopf im Web (Abweichung 13), über
  `ForkUnsubscribeExtension::target()`, also mit denselben Ausnahmen (Spam, Papierkorb, Entwürfe,
  Antworten).
- **`ForkSearch/insight`** (`query`, `parts` ⊆ `best`, `trips`, `answer`) → die drei Felder über den
  Web-Suchtreffern (Abweichungen 16 und 18): beste Treffer (Reranker, Score ≥ 0,5, höchstens 3),
  kommende Termine mit ihren Mails, Antwort des Sprachmodells. Jeder Thread mit `threadId`,
  `accountId` und `emailId` der neuesten Mail. Nutzerweit wie die Web-Suche. Ein Teil, dessen Dienst
  aus ist, kommt leer bzw. `null` zurück, nie als Fehler. `parts` erlaubt, die schnellen Teile
  vor der langsamen Antwort abzurufen.
- **`ForkSearch/chat`** (`message`, `conversation`) → eine Runde des KI-Such-Chats (Abweichung 20),
  ganz (JMAP streamt nicht): `{conversation, queries, answer, important, results, error}`, Threads
  wie oben. Ohne Sprachmodell `error: true`.

Beide laufen unter `urn:ietf:params:jmap:mail`, ohne eigene Capability: der Server prüft beim Aufruf
nicht, zu welcher Capability eine Methode gehört, und eine neue URN müsste in `Capability.php` und
`SessionBuilder.php` (Dateien des Originals) eingetragen werden. Ein Server ohne den Fork antwortet
`unknownMethod`, und die App zeigt nichts.

Neue Dateien: `src/Jmap/Method/Fork/ForkUnsubscribeGetMethod.php`, `ForkSearchInsightMethod.php`,
`ForkSearchChatMethod.php`,
Tests `tests/Jmap/Method/Fork/`. Keine Änderung an Dateien des Originals (Methoden werden über
`_instanceof JmapMethod` automatisch registriert).

**Datenbank:** keine Änderung.

**Grund:** Die iOS-App spricht nur JMAP; ohne diese Methoden fehlten ihr Abmelden-Knopf und KI-Suche.
**Kandidat fürs Original:** nein (hängt an Abweichungen 13, 16, 18).

### 23. Sortierung „Ungelesene zuerst“

Im Sortiermenü jeder Mailliste (Posteingang, Ordner, Labels, Alle Nachrichten, Markiert …) gibt es
neben „Neueste zuerst“ und „Älteste zuerst“ eine dritte Wahl **„Ungelesene zuerst“**: Gespräche mit
ungelesener Mail oben, darunter die gelesenen, beide Gruppen nach Datum (neueste zuerst). Gemerkt
wird die Wahl wie die beiden anderen in der Sitzung (`mail.list_sort`).

Ungelesen heißt `unreadCount > 0`, derselbe Test wie Zähler und Filter „Ungelesen“. Ein geöffnetes
Gespräch wandert also beim nächsten Neuladen der Liste nach unten. Die Suche hat ihre eigene
Sortierung (`SearchSortOrder`) und bleibt unverändert; die iOS-App sortiert selbst über JMAP.

Geändert (markiert): `src/Domain/Enum/Mail/ListSortOrder.php` (neuer Fall `UnreadFirst`,
Sortierung über ein `HIDDEN`-Select, weil die Listenabfragen `DISTINCT` sind),
`translations/messages.*.yaml` (je eine Zeile `list.sort.unread`; das Sortiermenü übersetzt in der
Domäne `messages`). Test `tests/Repository/Mail/Fork/UnreadFirstSortTest.php`.

**Datenbank:** keine Änderung.
**Grund:** Wunsch der Nutzer: was noch zu lesen ist, oben sehen, ohne den Filter „nur ungelesene“.
**Kandidat fürs Original:** möglich (Funktion, kein Fehler; nur nach Freigabe vorschlagen).

### 24. PDF-Vorschau: Querformat (Pläne) nicht mehr verzerrt

Querformat-Seiten (z. B. Pläne in A3/A1) wurden in der PDF-Vorschau seitlich gestaucht. Jede Seite
bekam eine feste CSS-Breite und -Höhe, dazu `max-w-full`: War die Seite breiter als das Fenster,
kappte der Browser die Breite, die Höhe blieb – Verzerrung. Aus demselben Grund half Hineinzoomen
über die Fensterbreite hinaus nicht.

- Seiten ohne `max-w-full`; breiter als das Fenster heißt jetzt seitlich scrollen. Der Seitenstapel
  ist `w-fit min-w-full`, damit der linke Rand einer breiten Seite erreichbar bleibt.
- Anfangszoom passt die breiteste der ersten drei Seiten in die Fensterbreite, höchstens 1,2 wie
  bisher (Hochformat A4 öffnet unverändert). Herauszoomen geht bis 0,25 statt 0,5.

Geändert (markiert): `assets/controllers/mail/pdf_viewer_controller.js`,
`templates/mail/_attachment_preview.html.twig`. Test `tests/e2e/fork-pdf-landscape.spec.ts`
(scheitert mit dem alten Code). Unterschreiben unberührt: Es misst die Seite über
`getBoundingClientRect()`, nicht über die CSS-Größe (pdf-sign/pdf-geometry-Specs grün).

**Datenbank:** keine Änderung.
**Grund:** Pläne als PDF-Anhang waren in der Vorschau unbrauchbar.
**Kandidat fürs Original:** ja, Fehlerbehebung (dort erst Issue, nur nach Freigabe).

### 25. KI fragt die Anhänge („KI fragen“)

Neben den Anhängen einer Mail (Gesprächsansicht und Einzelansicht) steht der Knopf **„KI fragen“**.
Er öffnet einen Dialog mit einem Chat über die Anhänge dieser Mail: „Was kostet das?“, „Bis wann muss
ich zahlen?“, „Fass den Vertrag zusammen“. Drei Vorschläge stehen als Knöpfe da. Bei mehreren Anhängen
hat jeder ein Häkchen; was bei der ersten Frage angehakt ist, gilt für das ganze Gespräch. Neben jedem
Namen steht danach, was gelesen wurde („3 Seiten gelesen“, „als Bild gelesen“, „nur der Anfang“,
„Dateityp nicht lesbar“). Hinter dem Schalter „Zusammenfassungen von Gesprächen“
(`AiFeature::Summary`) wie die anderen Chats des Forks; ist er aus, steht der Knopf nicht da.

Anders als die KI-Suche (Abweichung 20) liest das Modell hier **das ganze Dokument**:

- **PDF:** `pdftotext -layout` mit einer Marke je Seite („--- Seite 3 ---“), damit die Antwort die
  Seite nennen kann. Hat ein PDF weniger als 40 Buchstaben je Seite, ist es ein Scan: die ersten
  6 Seiten gehen mit `pdftoppm` als Bilder (1600 px) ans Modell.
- **Word, Excel, PowerPoint, OpenDocument** (`docx`, `xlsx`, `pptx`, `odt`, `ods`, `odp`): das XML
  im ZIP, Absätze als Zeilen, Zellen durch Tabs.
- **Text, CSV, HTML, JSON, XML, ICS, EML:** wie sie sind (HTML ohne Tags).
- **Fotos** (JPEG, PNG, WebP, GIF): als Bild, auf 1600 px verkleinert.
- Alles andere (ZIP, DWG, …) und verschlüsselte Teile (`sealed`): „nicht lesbar“.

Grenzen: 150 000 Zeichen je Dokument (~40 000 Tokens), 240 000 für alle zusammen, 8 Bilder, Dateien
bis 40 MB. Bilder gehen nur an einen OpenAI-kompatiblen Server (vLLM, wie auf dem Spark: das Modell dort
liest Bilder); über Ollama sind Fotos und Scans „nicht lesbar“. Der Text eines Anhangs liegt 7 Tage im
Cache (nach Teil-ID; die Bytes eines Anhangs ändern sich nie), Bilder werden jedes Mal neu erzeugt.
Die Dokumente stehen in der ersten Nachricht ans Modell, vor der ersten Frage, und jede Rückfrage
schickt diese Nachricht unverändert mit: der Präfix-Cache von vLLM liest sie dann einmal je Gespräch.
Verlauf (16 Nachrichten, gewählte Anhänge) 6 h im Cache unter Nutzer, Mail und zufälliger ID, keine
Tabelle. Antwort gestreamt (NDJSON: `conversation`, `documents`, `token`…, `done` oder `error`).

Neue Dateien: `src/Service/Fork/Attachment/ForkAttachmentReader.php`, `ForkAttachmentContent.php`,
`ForkAttachmentChat.php`, `src/Controller/Fork/ForkAttachmentAskController.php` (Routen
`GET/POST /mail/message/{id}/fork/ask`), `templates/fork/attachment/_ask.html.twig`,
`_ask_button.html.twig`, `assets/controllers/fork/attachment_ask_controller.js`, Schlüssel
`attachment.fork_ask.*` in `translations/fork.*.yaml`, Test
`tests/Controller/Fork/ForkAttachmentAskTest.php`, Browsertest `tests/e2e/fork-attachment-ask.spec.ts`.

Änderungen an Dateien des Originals (mit `FORK (FORK.md)` markiert):

- `Dockerfile`: Paket `poppler-utils` (`pdftotext`, `pdftoppm`; ~5 MB) in der apt-Zeile.
- `src/Service/Ai/OpenAiClient.php` (`payload()`): ist `content` eine Liste von Teilen (Text und
  Bilder), geht sie unverändert raus statt durch `utf8()`.
- `templates/mail/_thread_message.html.twig`, `templates/mail/_message_content.html.twig`: je ein
  `include` von `fork/attachment/_ask_button.html.twig` hinter den Anhängen.

**Datenbank:** keine Änderung.
**Grund:** Wunsch der Nutzer: Fragen zu Rechnungen, Angeboten und Plänen im Anhang beantworten lassen,
ohne das PDF selbst durchzusehen.
**Kandidat fürs Original:** als Idee (dort erst Issue, nur nach Freigabe).

### 26. Schreibhilfe ändert keine Namen mehr

„Kürzer“, „förmlicher“ und „korrigieren“ in der Schreibhilfe des Editors schreiben Namen aus dem
Entwurf jetzt Buchstabe für Buchstabe ab. Kommt ein Name trotzdem nicht so zurück, steht statt
„Fertig“ in Rot „Bitte Namen prüfen – nicht wie in deinem Text: Strauss“, bevor jemand „Einfügen“ drückt.

**Der Fehler:** „Förmlicher“ machte aus „Frau Strauss“ ein „Frau Strauß“ (15 von 15 Versuchen mit dem echten
Entwurf) und einmal „Frau Straussler“. Mit Antwortkontext „ganzes Gespräch“ sieht das Modell den Verlauf,
und darin unterschreibt die Empfängerin mit „Strauß“; das Modell „korrigiert“ den Entwurf danach. Ohne
Verlauf 10 von 10 richtig. Die Prompts des Originals sagen „nichts hinzufügen“, aber nichts zu Namen.

Zwei Teile in `src/Service/Fork/ForkNameGuard.php`:

- **Regel im Prompt** (`RULE`): „Copy every name … letter for letter … never change ss to ß …“. Wird
  bei den drei Umschreib-Aufgaben an den Systemprompt gehängt, nach dem Prompt der Aufgabe (auch einem
  in Admin → KI geänderten) und vor der Persona. Gemessen: 15 von 15 Versuchen richtig; eine mildere
  Fassung („Namen beibehalten“) scheiterte noch 11 von 15 Mal. Nicht bei „Antwort entwerfen“: dort
  kommen Namen aus dem Gespräch, und die Schreibweise der Signatur ist meist die richtige.
- **Prüfung ohne KI** (`missing()`): Namen im Entwurf sind die Wörter hinter einer Anrede oder einem
  Gruß (Frau, Herr/Herrn, Hr., Fr., Mr./Mrs./Ms., Dear, Hallo, Hi, Liebe/r, Moin, Madame, Monsieur …,
  akademische Titel dazwischen übersprungen) und ein zweites großgeschriebenes Wort davor, wenn danach
  Satzzeichen oder Zeilenende kommt („Herr Max Müller,“). Jedes muss in der Antwort als ganzes Wort in
  gleicher Schreibung vorkommen. Bloß großgeschriebene Wörter zählen nicht (deutsche Substantive);
  „Team“, „Damen“, „Herren“ usw. auch nicht. Die Liste geht im `done`-Frame des Streams als `names` an
  den Browser.

Test: `tests/Service/Fork/ForkNameGuardTest.php`, Browsertest
`tests/e2e/fork-names-ai-compose.spec.ts` (Name endet auf `ai-compose.spec.ts`, damit
`playwright.config.ts` ihn ins Ein-Worker-Projekt legt, ohne dass die Konfiguration geändert wird).
Text `compose.fork_names` in `translations/fork.*.yaml`.

Änderungen an Dateien des Originals (mit `FORK (FORK.md)` markiert):

- `src/Service/Ai/WritingAssistant.php` (`messagesFor()`): `ForkNameGuard::ruleFor($task)` zwischen
  Aufgabenprompt und Persona.
- `src/Controller/Mail/ComposeAssistController.php` (`generate()`): Feld `names` im `done`-Frame.
  Der nicht gestreamte Endpunkt (`assist()`) bekommt die Regel, aber keine Prüfung; der Editor nutzt ihn nicht.
- `assets/controllers/compose/ai_assist_controller.js`: Wert `forkNamesLabel`; bei `done` mit
  `names` dieser Satz statt „Fertig“, rot (Inline-Farbe), `data-fork-names` am Statusfeld; `#say()`
  setzt beides zurück.
- `templates/compose/_window.html.twig`: Attribut `data-compose--ai-assist-fork-names-label-value`.

**Datenbank:** keine Änderung.
**Grund:** Ein falsch geschriebener Name in einer Geschäftsmail an die genannte Person darf nicht passieren.
**Kandidat fürs Original:** ja, als Fehler (dort erst Issue, nur nach Freigabe).

### 27. Mails beim Lesen einem Projekt aus Projektakte zuordnen

Projektakte ist ein eigenes Werkzeug des Büros neben plMail (Python/FastAPI, im LAN): Es liest die
Projektordner und über JMAP die Mails, ordnet sie Projekten zu und beantwortet Fragen je Projekt.
Die Zuordnung einer Mail entscheidet jetzt ein Mensch in plMail; Projektakte liest sie über JMAP und
liefert dafür Projektliste und Vorschläge über eine kleine HTTP-API. Der Vertrag steht in Projektakte
unter `docs/plmail-integration.md`.

**Was man sieht:** Im Kopf jeder Nachricht eines Verlaufs (und in der Einzelansicht unter den
Empfängern) ein Chip „Projekt zuordnen“, zugeordnet „BS-0000-0000 Projektname ×“ bzw. „Kein Projekt ×“
(auf dem Handy nur die Nummer). Klick öffnet ein Popover wie das Label-Menü:

- bis zu 3 **Vorschläge** von Projektakte mit Begründung und Sicherheit („sicher“, „wahrscheinlich“,
  „vielleicht“); der erste ist markiert, sodass Enter ihn nimmt, aber **nichts wird ohne Klick bzw.
  Taste zugeordnet**, auch bei „sicher“ nicht;
- Suchfeld und die Projektliste (Nummer + Name, im Browser gefiltert, Pfeiltasten/Enter);
- „Kein Projektbezug“, „Zuordnung entfernen“ und „In Projektakte öffnen“ (Link aus der Projektliste).

Nach der Wahl ersetzt ein Turbo Stream den Chip. Die Zuordnung gilt **pro Nachricht**, bleibt beim
Verschieben erhalten und geht **nie** zum Mailserver (kein Keyword, Label, Flag). Ohne Konfiguration
ist alles unsichtbar (kein Chip, keine Abfrage, Routen 404). Ist Projektakte konfiguriert, aber nicht
erreichbar, zeigt der Chip die gespeicherte Zuordnung weiter, das Popover sagt „Projektakte ist gerade
nicht erreichbar.“; „Kein Projektbezug“ und „Entfernen“ gehen weiter, Zuordnen nicht (die Nummer lässt
sich nicht prüfen).

**Konfiguration** (Umgebungsvariablen am Container, Unraid-Vorlage; nicht in `.env`):

- `PROJEKTAKTE_URL`: Basis-URL von Projektakte, **aus dem plmail-Container** erreichbar.
- `PROJEKTAKTE_TOKEN`: dasselbe Geheimnis wie `INTEGRATION_TOKEN` in Projektakte.
- Prüfen: `php bin/console app:fork:projektakte` (ruft `/api/integration/info`, zeigt Fehler samt
  `detail`). In Projektakte zeigt `GET /health/deps` bei plMail `"project_extension": true`.

**Schnittstelle zu Projektakte** (nur serverseitig, das Token kommt nie in den Browser; Header
`Authorization: Bearer …`, Timeout 5 s, keine Weiterleitungen, jeder Fehler = „nicht erreichbar“,
geloggt ohne Token):

- `GET /api/integration/projects?q=&limit=500` – Liste, 300 s gecacht (nach Fehler 60 s), Schlüssel
  je Adresse. Eine Nummer, die nicht in der Liste steht (neu angelegt oder älter als die ersten 500),
  wird beim Zuordnen einzeln mit `q=<Nummer>` nachgefragt.
- `POST /api/integration/suggest` (`subject`, `body` ≤ 20 000 Zeichen, `from`, Dateinamen der
  Anhänge, `limit` 3) – je Mail 10 min gecacht (nach Fehler 60 s).
- `POST /api/integration/notify` nach **jeder** Änderung, Timeout 2 s, Fehler nur im Log; die
  Zuordnung bleibt, Projektakte holt sie beim regulären Abgleich.
- `GET /api/integration/info` nur für `app:fork:projektakte`.

**JMAP:** Capability `urn:plmail:params:jmap:projektakte` (Wert `{}`) oben in der Session und in jedem
Konto, immer (auch ohne Konfiguration, die Daten stammen aus plMails Tabelle), und in
`Capability::SUPPORTED`, sonst lehnt `JmapProcessor` Anfragen mit der URN in `using` ab.
`Email/get` liefert die Eigenschaft **`plmail:projektakte`** nur, wenn sie in `properties` steht (wie
`replyFrom`), für alle Mails der Antwort in einer Abfrage: `null` (nicht zugeordnet, auch nach
„Entfernen“), `{"status":"assigned","project":"BS-0000-0000","projectName":"…","assignedBy":"…","assignedAt":"…Z"}`
oder `{"status":"none","project":null,"projectName":"",…}`. `assignedBy` ist Vor- und Nachname, sonst
die Anmeldeadresse, gespeichert als Text (überlebt das Löschen des Benutzers); `assignedAt` ist
`updated_at` in UTC. Jede Änderung schreibt die Mail (und ihren Thread) als *updated* ins Change-Log
(`MailChangeRecorder::emailChanged()`, ein `flush()` mit der Zeile), sonst sähe Projektakte sie nie in
`Email/changes`. Kein `Email/set`, kein Filter in `Email/query`.

Neue Dateien: `src/Entity/Fork/ForkMessageProject.php`, `src/Domain/Enum/Fork/ForkProjectStatus.php`,
`src/Repository/Fork/ForkMessageProjectRepository.php`, `src/Service/Fork/Projektakte/`
(`ForkProjektakteClient`, `ForkProjectAssigner`, `ForkProject`, `ForkProjectSuggestion`,
`ForkProjectRefused`), `src/Controller/Fork/ForkProjectController.php` (`GET/POST/DELETE
/mail/message/{id}/fork/project`), `src/Twig/Fork/ForkProjectExtension.php` (`fork_projektakte()`,
`fork_project()`: eine Abfrage je Verlauf), `src/Command/Fork/ForkProjektakteCommand.php`,
`templates/fork/project/_chip.html.twig`, `_picker.html.twig`, `_assigned.stream.html.twig`,
`assets/controllers/fork/project_picker_controller.js`, Schlüssel `message.fork_project.*` in
`translations/fork.*.yaml`, `migrations/Version20261108120000.php`, Tests
`tests/Controller/Fork/ForkProjectTest.php`, `tests/Jmap/Method/Fork/ForkProjektakteEmailTest.php`,
`tests/Support/Fork/FakeProjektakte.php`, Browsertest `tests/e2e/fork-project.spec.ts`.

Änderungen an Dateien des Originals (mit `FORK (FORK.md)` markiert):

- `src/Jmap/Protocol/Capability.php`: Konstante `PROJEKTAKTE`, Eintrag in `SUPPORTED`.
- `src/Jmap/Session/SessionBuilder.php` (`build()`): je eine Zeile in `accountCapabilities` und in
  den obersten `capabilities`.
- `src/Jmap/Method/Mail/EmailGetMethod.php`: optionaler Konstruktorparameter
  `?ForkMessageProjectRepository`, Opt-in `plmail:projektakte` neben `replyFrom`.
- `templates/mail/_thread_message.html.twig`: ein `include` des Chips in `thread-message-meta` vor dem
  Abmelden-Chip; `templates/mail/_message_content.html.twig`: eine Zeile unter den Empfängern.
- `CONTRIBUTING.md`: eine Zeile für `app:fork:projektakte` in der Befehlstabelle
  (`DocumentationCoverageTest`).

**Datenbank:** eigene Tabelle `fork_message_project` (`message_id` eindeutig, `ON DELETE CASCADE`;
`status` `assigned`/`none`; `project_number` VARCHAR(64) mit Index; `project_name`;
`assigned_by_id` → `"user"` `ON DELETE SET NULL`; `assigned_by_name`; `created_at`/`updated_at`).
„Entfernen“ löscht die Zeile. Keine Spalte in Tabellen des Originals. **Bekannte Grenze:**
`ImapCopyMerger::fold()` hängt nur die Kindtabellen des Originals um; wird ein doppelt gespeichertes
Exemplar einer Mail mit dem überlebenden zusammengelegt, verschwindet die Zuordnung des Duplikats per
CASCADE (die des überlebenden bleibt). Akzeptiert; selten, und Projektakte sieht die Mail dann als
nicht zugeordnet.

Nicht gebaut (optional im Auftrag): Badge mit der Projektnummer in der Mailliste (bräuchte eine
gebündelte Abfrage je Listenseite und eine Änderung an `_thread_row.html.twig`), Abschnitt in den
Admin-Einstellungen (dafür `app:fork:projektakte`).

**Grund:** Projektakte ordnete Mails bisher selbst über die Projektnummer im Betreff zu; das soll ein
Mensch beim Lesen entscheiden.
**Kandidat fürs Original:** nein (hängt an Projektakte, einem Werkzeug nur dieses Büros).

### 28. „Ungelesene zuerst“ ist die Standardsortierung

Jede Mailliste öffnet mit der Sortierung „Ungelesene zuerst“ (Abweichung 23) statt „Neueste
zuerst“, solange in der Sitzung nichts anderes gewählt wurde. Eine Wahl im Sortiermenü gilt wie
bisher für die ganze Sitzung (`mail.list_sort`); nach dem nächsten Anmelden steht wieder
„Ungelesene zuerst“. Die Suche (`SearchSortOrder`) und die iOS-App sind nicht betroffen.

Geändert (markiert): `src/Controller/Mail/MailController.php` (`listSort()`: Rückfallwert
`ListSortOrder::UnreadFirst`). Der Standard im Enum selbst (`fromSetting()`, Parameter der
Repository-Methoden) bleibt `Newest`, damit die Änderung eine Zeile bleibt. Test
`tests/Controller/Fork/ForkUnreadFirstDefaultTest.php`.

**Datenbank:** keine Änderung.
**Grund:** Wunsch der Nutzer: nicht bei jeder Sitzung erst von „Neueste“ auf „Ungelesene zuerst“
umstellen müssen.
**Kandidat fürs Original:** nein (Geschmackssache; das Original bleibt bei „Neueste zuerst“).

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
3. Tests lokal (PHPUnit, PHPStan; bei großen Updates auch Playwright oder e2e.yml von Hand, siehe
   Abweichung 5). Push, Pull Request gegen `main` des Forks, dann **mit Merge-Commit**
   mergen (`gh pr merge --merge`), nie per Squash: sonst erkennt Git die Commits des Originals beim
   nächsten Update nicht wieder. Der Merge baut das Image.
4. Auf dem Server das neue Image ziehen. Migrationen laufen beim Start automatisch; vorher ein
   Datenbank-Backup machen (docs/install/backup-restore.md).
