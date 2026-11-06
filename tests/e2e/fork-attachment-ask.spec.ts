import { expect } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";

/**
 * FORK (FORK.md): "KI fragen" — the chat about one mail's attachments.
 *
 * The server's half — what is read of each attachment, what the model is sent,
 * whose mail it is — is pinned in ForkAttachmentAskTest. What only a browser
 * can show is the controller: a suggestion goes out with the ticked
 * attachments, each frame lands where it belongs, the checkboxes lock once the
 * conversation has begun, and a follow-up carries the conversation.
 *
 * The dialog is drawn only with the summary feature on, a singleton every
 * other spec would see (see fork-search-answer.spec.ts), so the dialog's
 * markup is put on the inbox page here and its endpoint answered here.
 */

const DIALOG = `
<div data-controller="fork--attachment-ask"
     data-fork--attachment-ask-url-value="/mail/message/1/fork/ask"
     data-fork-attachment-ask>
    <fieldset data-fork--attachment-ask-target="parts">
        <label data-part-id="11"><input type="checkbox" value="11" checked data-fork--attachment-ask-target="part"> rechnung.pdf <span data-slot="state"></span></label>
        <label data-part-id="12"><input type="checkbox" value="12" checked data-fork--attachment-ask-target="part"> plaene.zip <span data-slot="state"></span></label>
    </fieldset>
    <div data-fork--attachment-ask-target="scroller">
        <div data-fork--attachment-ask-target="suggestions">
            <button type="button" data-action="fork--attachment-ask#suggest" data-question="Fass das zusammen">Fass das zusammen</button>
        </div>
        <ol data-fork--attachment-ask-target="log"></ol>
    </div>
    <form data-action="submit->fork--attachment-ask#send">
        <textarea name="message" data-fork--attachment-ask-target="input" data-action="keydown->fork--attachment-ask#key"></textarea>
        <button type="submit" data-fork--attachment-ask-target="submit">Senden</button>
    </form>
    <template data-fork--attachment-ask-target="userTemplate">
        <li data-fork-ask-turn="user"><p data-slot="text"></p></li>
    </template>
    <template data-fork--attachment-ask-target="answerTemplate">
        <li data-fork-ask-turn="assistant">
            <p data-slot="status"><span data-slot="status-text">…</span></p>
            <p data-slot="text" data-fork-ask-answer hidden></p>
            <p data-slot="error" hidden></p>
        </li>
    </template>
    <template data-fork--attachment-ask-target="strings" data-thinking="Liest" data-failed="Fehler"
              data-unreadable="Nicht lesbar" data-nothing-chosen="Bitte wählen" data-state-text="gelesen"
              data-state-pages="%count% Seiten gelesen" data-state-images="Bild" data-state-truncated="nur der Anfang"
              data-state-scan="Scan" data-state-unsupported="Dateityp nicht lesbar" data-state-failed="nicht lesbar"></template>
</div>`;

const ndjson = (frames: object[]) => frames.map((f) => JSON.stringify(f)).join("\n") + "\n";

test("a suggestion asks about the ticked attachments, a follow-up keeps the conversation", async ({ page }) => {
    seed("seed-mail");

    await page.route(/\/mail\/inbox$/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace("</body>", `${DIALOG}</body>`);

        await route.fulfill({ response, body });
    });

    const sent: Record<string, string>[] = [];

    await page.route(/\/mail\/message\/1\/fork\/ask$/, async (route) => {
        const form = Object.fromEntries(new URLSearchParams(route.request().postData() ?? ""));
        sent.push(form);

        await route.fulfill({
            contentType: "application/x-ndjson",
            body: 1 === sent.length
                ? ndjson([
                    { type: "conversation", id: "b".repeat(32) },
                    { type: "documents", items: [
                        { id: 11, name: "rechnung.pdf", status: "text", pages: 3, truncated: false },
                        { id: 12, name: "plaene.zip", status: "unsupported", pages: null, truncated: false },
                    ] },
                    { type: "token", text: "Rechnung über " },
                    { type: "token", text: "123,45 EUR." },
                    { type: "done", answer: "Rechnung über 123,45 EUR." },
                ])
                : ndjson([
                    { type: "conversation", id: "b".repeat(32) },
                    { type: "documents", items: [] },
                    { type: "token", text: "Bis 30.11.2026 (S. 2)." },
                    { type: "done", answer: "Bis 30.11.2026 (S. 2)." },
                ]),
        });
    });

    await page.goto("/mail/inbox");

    const chat = page.locator("[data-fork-attachment-ask]");
    const answers = chat.locator('[data-fork-ask-turn="assistant"]');

    // Only the first attachment is asked about.
    await chat.locator('input[value="12"]').uncheck();
    await chat.getByRole("button", { name: "Fass das zusammen" }).click();

    await expect(answers.first().locator("[data-fork-ask-answer]")).toHaveText("Rechnung über 123,45 EUR.");
    await expect(chat.locator('[data-fork-ask-turn="user"]').first()).toHaveText("Fass das zusammen");
    expect(sent[0]).toEqual({ conversation: "", message: "Fass das zusammen", parts: "11" });

    await expect(chat.locator('[data-part-id="11"] [data-slot="state"]')).toHaveText("· 3 Seiten gelesen");
    await expect(chat.locator('[data-part-id="12"] [data-slot="state"]')).toHaveText("· Dateityp nicht lesbar");
    await expect(chat.locator('input[value="11"]')).toBeDisabled();
    await expect(chat.locator('[data-fork--attachment-ask-target="suggestions"]')).toBeHidden();

    // Enter sends; the conversation goes with it.
    await chat.locator("textarea").fill("Bis wann zahlen?");
    await chat.locator("textarea").press("Enter");

    await expect(answers.nth(1).locator("[data-fork-ask-answer]")).toHaveText("Bis 30.11.2026 (S. 2).");
    expect(sent[1]).toEqual({ conversation: "b".repeat(32), message: "Bis wann zahlen?", parts: "11" });
});

test("nothing ticked asks nothing", async ({ page }) => {
    seed("seed-mail");

    await page.route(/\/mail\/inbox$/, async (route) => {
        const response = await route.fetch();
        await route.fulfill({ response, body: (await response.text()).replace("</body>", `${DIALOG}</body>`) });
    });

    let asked = 0;
    await page.route(/\/mail\/message\/1\/fork\/ask$/, async (route) => {
        asked++;
        await route.abort();
    });

    await page.goto("/mail/inbox");

    const chat = page.locator("[data-fork-attachment-ask]");
    await chat.locator('input[value="11"]').uncheck();
    await chat.locator('input[value="12"]').uncheck();
    await chat.getByRole("button", { name: "Fass das zusammen" }).click();

    await expect(chat.locator('[data-slot="error"]').first()).toHaveText("Bitte wählen");
    expect(asked).toBe(0);
});
