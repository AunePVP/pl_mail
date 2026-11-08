**File a mail under a Projektakte project while you read it.** With Projektakte connected, every
message in a conversation has an "Assign project" chip in its header. It opens Projektakte's
suggestions with their reason, a search over all projects, and "Not about a project"; nothing is
assigned until you choose, and × takes it back. The choice stays with the message when it is moved,
is never written to the mail server, and is read by Projektakte over JMAP (`plmail:projektakte`).

**Migration:** adds the table `fork_message_project`. The chip appears once `PROJEKTAKTE_URL` and
`PROJEKTAKTE_TOKEN` are set on the container; `php bin/console app:fork:projektakte` checks the
connection. Without them nothing changes.
