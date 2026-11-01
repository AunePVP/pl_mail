# AGENTS.md

Hinweise für KI-Assistenten (Codex, Cursor, Gemini CLI, Copilot, Claude und andere) in diesem Fork.

**Lies zuerst [CLAUDE.md](CLAUDE.md).** Dort stehen die verbindlichen Regeln, die Arbeitsweise, der
Betrieb und wie live debuggt wird. Sie gelten für jeden Assistenten, nicht nur für Claude.
Danach [FORK.md](FORK.md) (jede Abweichung vom Original) und [CONTRIBUTING.md](CONTRIBUTING.md).

Das Wichtigste in Kürze:

- Dies ist ein **öffentlicher Fork** von karatektus/pl_mail. Nichts Geheimes committen: keine
  Passwörter, Tokens, Mailinhalte, internen Adressen. Betriebsdaten stehen in `CLAUDE.local.md`
  (nicht im Repo).
- Mit den Nutzern **Deutsch** sprechen; Code, Kommentare und Commits auf Englisch.
- **Nie direkt auf `main`.** Eigener Branch, Pull Request gegen `main` **des Forks**
  (`AunePVP/pl_mail`), Tests abwarten. Jeder Merge auf `main` baut das Produktions-Image.
- Fork-Änderungen klein halten, mit `FORK (FORK.md)` im Code markieren und in FORK.md eintragen.
- Nichts zum Original (karatektus/pl_mail) schicken – kein Push, PR, Issue oder Kommentar –,
  außer der Nutzer sagt es im jeweiligen Fall ausdrücklich.
- An der laufenden Installation: erst lesen, dann fragen, dann ändern. Neustarts nur nach Absprache.
