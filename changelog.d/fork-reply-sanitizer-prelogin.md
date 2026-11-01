**Replying to an encrypted message can no longer make the page act on its own.** The decrypted
original is quoted into the composer, which is the app's own page, and its `data-*` attributes
were kept — a sender could plant one that started one of the app's own controllers and sent
requests in the reader's name, such as moving conversations to the trash. Only the composer's own
markers are kept now. The sign-in page also no longer tells whether an account exists when the
address is typed in a different case. No schema change.
