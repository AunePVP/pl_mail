import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";

/**
 * FORK (FORK.md): what a background refresh of the list does to the AI parts
 * of a search — nothing.
 *
 * A sync, the polling fallback or coming back to the tab re-reads the list
 * (mail--mail-pane#_refreshList) and morphs the rows region, and the panels
 * above the results sit in that region as its first <li>. Morphed, the
 * server's empty copy of them won: the three boxes emptied and loaded again
 * every 15–60 seconds, and the search chat lost its turns and its
 * conversation. They are client state the server's copy knows nothing about,
 * so the morph leaves them alone.
 *
 * And what Back or a reload does to the chat: draws the turns it had again,
 * without asking the model a second time. The mail pages keep no snapshot
 * for Back, so the page comes anew and the chat sent its question again.
 */

const ANSWER = `
<turbo-frame id="fork-search-answer" target="_top">
    <div data-fork-answer-state="ready"><p data-fork-answer-text>Dein Flug ist bestätigt.</p></div>
</turbo-frame>`;

/** The chat as _chat.html.twig draws it, cut to what the controller reads (as fork-search-chat.spec.ts). */
const CHAT = `
<div data-controller="fork--search-chat"
     data-fork--search-chat-url-value="/mail/search/fork/chat"
     data-fork--search-chat-question-value="e2e"
     data-fork-search-chat>
    <ol data-fork--search-chat-target="log"></ol>
    <form data-action="submit->fork--search-chat#send">
        <input type="text" name="message" data-fork--search-chat-target="input">
        <button type="submit" data-fork--search-chat-target="submit">Senden</button>
    </form>
    <template data-fork--search-chat-target="userTemplate">
        <li data-fork-chat-turn="user"><p data-slot="text"></p></li>
    </template>
    <template data-fork--search-chat-target="answerTemplate">
        <li data-fork-chat-turn="assistant">
            <p data-slot="status"><span data-slot="status-text">…</span></p>
            <p data-slot="queries" hidden><span data-slot="queries-text"></span></p>
            <p data-slot="text" data-fork-chat-answer hidden></p>
            <ul data-slot="important" data-fork-chat-important hidden></ul>
            <details data-slot="results" hidden><summary data-slot="results-summary"></summary><ul data-slot="results-list"></ul></details>
            <p data-slot="error" hidden>failed</p>
        </li>
    </template>
    <template data-fork--search-chat-target="linkTemplate">
        <li><a href="#" data-action="click->mail--mail-pane#open"><span data-slot="date"></span> <span data-slot="subject"></span> <span data-slot="from"></span></a></li>
    </template>
    <template data-fork--search-chat-target="strings" data-searching="Sucht" data-writing="Schreibt"
              data-no-search="Antwortet" data-found="%count% Mails gefunden" data-found-one="1 Mail gefunden" data-nothing="Keine"></template>
</div>`;

const ndjson = (frames: object[]) => frames.map((f) => JSON.stringify(f)).join("\n") + "\n";

/** What mail--mail-pane does when the tab is looked at again: one refresh of the list. */
async function refreshList(page: Page): Promise<void> {
    const fragment = page.waitForResponse((response) => null !== response.request().headers()["x-list-fragment"]);

    await page.evaluate(() => document.dispatchEvent(new Event("visibilitychange")));
    await fragment;
    // The morph runs after the body is read; give it its turn.
    await page.waitForTimeout(300);
}

test("a list refresh leaves the search's AI boxes as they are", async ({ page }) => {
    seed("seed-mail");

    const loads: string[] = [];

    await page.route(/\/mail\/search\/fork\/(trips|best|answer)\?q=e2e$/, async (route) => {
        const part = /fork\/(\w+)\?/.exec(route.request().url())?.[1] ?? "";
        loads.push(part);

        await route.fulfill({
            contentType: "text/html",
            body: "answer" === part ? ANSWER : `<turbo-frame id="fork-search-${part}" target="_top"></turbo-frame>`,
        });
    });

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/mail/search?q=e2e");

    await expect(page.locator("[data-fork-answer-text]")).toHaveText("Dein Flug ist bestätigt.");

    const before = [...loads];

    await refreshList(page);

    await expect(page.locator("[data-fork-answer-text]")).toHaveText("Dein Flug ist bestätigt.");
    expect(loads).toEqual(before);
});

test("the chat keeps its turns through a list refresh, Back and a reload, and asks again only when pressed", async ({ page }) => {
    seed("seed-mail");

    await page.setViewportSize({ width: 1280, height: 900 });

    // The summary feature is off on the test stack, so the chat is put where ai.html.twig puts it.
    await page.route(/\/mail\/search\?q=e2e&fork_ai=1$/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(/(<li[^>]*data-fork-search-insight[^>]*>)/, `$1${CHAT}`);

        await route.fulfill({ response, body });
    });

    const sent: Record<string, string>[] = [];
    const item = { id: 1, date: "2026-11-02", from: "Fernbus", subject: "Buchungsbestätigung", url: "/mail/inbox" };

    await page.route(/\/mail\/search\/fork\/chat$/, async (route) => {
        sent.push(Object.fromEntries(new URLSearchParams(route.request().postData() ?? "")));

        const id = String(sent.length).repeat(32);

        await route.fulfill({
            contentType: "application/x-ndjson",
            body: ndjson([
                { type: "conversation", id: "" === sent.at(-1)?.conversation ? id : sent.at(-1)?.conversation },
                { type: "search", queries: ["buchung", "booking"] },
                { type: "results", items: [item] },
                { type: "token", text: "Am Donnerstag." },
                { type: "done", answer: `Antwort ${sent.length}.`, important: [item] },
            ]),
        });
    });

    const chat = page.locator("[data-fork-search-chat]");
    const answers = chat.locator('[data-fork-chat-turn="assistant"] [data-fork-chat-answer]');

    await page.goto("/mail/inbox");
    await page.goto("/mail/search?q=e2e&fork_ai=1");
    await expect(answers).toHaveText(["Antwort 1."], { timeout: 10_000 });

    // A sync re-reads the list: the chat is left as it is, conversation and all.
    await refreshList(page);
    await expect(answers).toHaveText(["Antwort 1."]);

    await chat.locator('input[name="message"]').fill("und zurück?");
    await chat.locator('button[type="submit"]').click();
    await expect(answers).toHaveText(["Antwort 1.", "Antwort 2."]);
    expect(sent[1]?.conversation).toBe("1".repeat(32));

    // Back and forward again, then a reload: the page comes anew each time,
    // and the chat draws what it had instead of asking.
    await page.goBack();
    await expect(page).toHaveURL(/\/mail\/inbox/);
    await page.goForward();
    await expect(answers).toHaveText(["Antwort 1.", "Antwort 2."], { timeout: 10_000 });
    await page.reload();
    await expect(answers).toHaveText(["Antwort 1.", "Antwort 2."], { timeout: 10_000 });
    await expect(chat.locator("[data-fork-chat-important] a").first()).toContainText("Buchungsbestätigung");
    await expect(chat.locator('[data-slot="queries-text"]').first()).toHaveText("buchung · booking");
    expect(sent).toHaveLength(2);

    // The conversation goes on where it was.
    await chat.locator('input[name="message"]').fill("und wann genau?");
    await chat.locator('button[type="submit"]').click();
    await expect(answers).toHaveText(["Antwort 1.", "Antwort 2.", "Antwort 3."]);
    expect(sent[2]?.conversation).toBe("1".repeat(32));

    // The button is somebody asking: a new chat, even for the same words.
    await page.locator('#search-shell input[name="q"]').fill("e2e");
    await page.locator("[data-fork-ai-search]").click();
    await expect(answers).toHaveText(["Antwort 4."], { timeout: 10_000 });
    expect(sent[3]).toEqual({ conversation: "", message: "e2e" });

    await page.unrouteAll({ behavior: "ignoreErrors" });
});
