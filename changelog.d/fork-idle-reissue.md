**New mail by push arrives sooner after a dropped connection.** plMail now checks its push (IDLE)
connection to the mail server every 5 minutes instead of every 29, so mail that a silently dropped
connection missed shows up within about 5 minutes instead of waiting for the next scheduled sync.
No schema change.
