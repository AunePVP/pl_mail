**Mail filed under a Projektakte project is sent to Projektakte, and nothing else is.** A moment after
you file a message under a project, plMail sends it with its attachments; moving it to another project
updates Projektakte, and "Not about a project", removing the assignment or deleting the mail for good
takes it out again. Delivery runs in the background through a queue that survives restarts and retries
while Projektakte is down. Admin → Integrations → Projektakte shows whether it answers, what is
waiting and the last error, and can send all filed mail at once. Projektakte no longer needs an app
password for the mailboxes.

**Migration:** adds the table `fork_projektakte_outbox`. After updating, press "Send all filed mail to
Projektakte" once, then delete Projektakte's app password in plMail.
