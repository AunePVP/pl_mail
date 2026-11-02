**Flights from Booking.com are on the calendar at the right time.** Booking confirmations that
state their times without a zone (Booking.com does for every flight) were read as UTC, so an 18:10
departure from Frankfurt showed at 19:10 in winter and 20:10 in summer. Such times are now read in the
reader's own time zone. Events already on the calendar are corrected by `app:backfill events`. No
schema change.
