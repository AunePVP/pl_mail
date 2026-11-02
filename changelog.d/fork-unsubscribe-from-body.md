**The Unsubscribe button also appears for mail that only has the link in its text.** Some senders
send no List-Unsubscribe header and put "click here to unsubscribe" in the footer instead. Without
the header, plMail now looks for that link in the mail: a link that says unsubscribe, or the one
link in a short sentence that does. The button's tooltip says the link came from the text. Replies
and drafts are not searched, and a link inside a quote is never taken. No schema change.
