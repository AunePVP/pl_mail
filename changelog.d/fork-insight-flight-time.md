**Flight cards show the departure time the mail states.** A time read out of a flight mail ("15:45")
was stored as UTC, so the "found in this conversation" card showed it an hour or two late. It is
now read in the reader's own time zone; `app:backfill insights` corrects cards already stored. And
a trip the chat model found is now offered on the card above its mail, where it can be added to the
calendar — it was stored, but no card ever showed it. No schema change.
