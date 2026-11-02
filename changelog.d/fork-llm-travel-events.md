**Trips from mail without booking markup are offered for the calendar.** Trip.com, airline check-in
and gate mails, security-lane slots and most hotels send no machine-readable booking, so they never
reached the calendar. With "Categorise" switched on, the chat model now reads flights, trains,
buses, stays, hire cars and tickets out of such mail. What it finds is only offered on the card
above the message, never put on the calendar by itself, and only when the flight number, booking
reference and time it names are written in the mail. `app:fork:llm-events --since=<date>` reads
mail that arrived earlier. No schema change.
