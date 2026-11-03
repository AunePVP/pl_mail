import { expect } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * FORK (FORK.md): the search chat behind the "KI-Suche" button.
 *
 * The server's half — the plan, the searches, the frames, whose conversation
 * it is — is pinned in ForkSearchChatTest. What only a browser can show is the
 * controller: that the box's text goes out as the first message, that each
 * frame lands where it belongs, that a follow-up carries the conversation, and
 * that a result opens in the reading pane like a row of the list.
 *
 * The chat is drawn only with the summary feature on, a singleton every other
 * spec would see (see fork-search-answer.spec.ts), so the page is the real
 * search page with the chat's markup put where _insight.html.twig puts it, and
 * the chat's endpoint is answered here.
 */

const CHAT = `
<div data-controller="fork--search-chat"
     data-fork--search-chat-url-value="/mail/search/fork/chat"
     data-fork--search-chat-question-value="wann fahre ich nach musterstadt"
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

test("asks what was typed, then a follow-up in the same conversation", async ({ page }, testInfo) => {
    seed("seed-mail");

    await page.setViewportSize({ width: 1280, height: 900 });

    // A real thread to link to: the first row of the inbox.
    await page.goto("/mail/inbox");
    const href = await page.locator('a[href^="/mail/thread/"]').first().getAttribute("href");
    expect(href).not.toBeNull();

    await page.route(/\/mail\/search\?q=e2e&fork_ai=1$/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(/(<li[^>]*data-fork-search-insight[^>]*>)/, `$1${CHAT}`);

        await route.fulfill({ response, body });
    });

    const sent: Record<string, string>[] = [];

    await page.route(/\/mail\/search\/fork\/chat$/, async (route) => {
        const form = Object.fromEntries(new URLSearchParams(route.request().postData() ?? ""));
        sent.push(form);

        const item = { id: 1, date: "2026-11-02", from: "Fernbus", subject: "Buchungsbestätigung", url: href };

        await route.fulfill({
            contentType: "application/x-ndjson",
            body: 1 === sent.length
                ? ndjson([
                    { type: "conversation", id: "a".repeat(32) },
                    { type: "search", queries: ["buchungsbestätigung", "musterstadt"] },
                    { type: "results", items: [item] },
                    { type: "token", text: "Am Donnerstag " },
                    { type: "token", text: "um 16:25." },
                    { type: "done", answer: "Am Donnerstag um 16:25.", important: [item] },
                ])
                : ndjson([
                    { type: "conversation", id: "a".repeat(32) },
                    { type: "search", queries: [] },
                    { type: "token", text: "Am Montag." },
                    { type: "done", answer: "Am Montag.", important: [] },
                ]),
        });
    });

    await page.goto("/mail/search?q=e2e&fork_ai=1");

    const chat = page.locator("[data-fork-search-chat]");
    const answers = chat.locator('[data-fork-chat-turn="assistant"]');

    await expect(answers.first().locator("[data-fork-chat-answer]")).toHaveText("Am Donnerstag um 16:25.", { timeout: 10_000 });
    await expect(chat.locator('[data-fork-chat-turn="user"]').first()).toHaveText("wann fahre ich nach musterstadt");
    await expect(answers.first().locator('[data-slot="queries-text"]')).toHaveText("buchungsbestätigung · musterstadt");
    await expect(answers.first().locator('[data-slot="results-summary"]')).toHaveText("1 Mail gefunden");
    expect(sent[0]).toEqual({ conversation: "", message: "wann fahre ich nach musterstadt" });

    await chat.locator('input[name="message"]').fill("und zurück?");
    await chat.locator('button[type="submit"]').click();

    await expect(answers.nth(1).locator("[data-fork-chat-answer]")).toHaveText("Am Montag.");
    expect(sent[1]).toEqual({ conversation: "a".repeat(32), message: "und zurück?" });
    await expect(answers.nth(1).locator('[data-slot="results"]')).toBeHidden();

    await settled(page);
    await page.screenshot({ path: testInfo.outputPath("chat.png") });

    // The mail worth opening opens in the pane like a row of the list, and
    // Back brings the chat back as it was: the list is hidden, not replaced.
    await answers.first().locator("[data-fork-chat-important] a").click();
    await expect(page).toHaveURL(/\/mail\/(thread|message)\/\d+/);
    await expect(page.locator(".thread-message-head").first()).toBeVisible({ timeout: 10_000 });

    await page.locator('[data-surface="reading"] [data-action="click->mail--mail-pane#close"]').first().click();
    await expect(chat).toBeVisible();
    await expect(answers).toHaveCount(2);
    await expect(answers.nth(1).locator("[data-fork-chat-answer]")).toHaveText("Am Montag.");

    // Back may load the list again through the route above; let it go rather than end mid-fetch.
    await page.unrouteAll({ behavior: "ignoreErrors" });
});

test("a failed turn says so and the box takes the next message", async ({ page }) => {
    seed("seed-mail");

    await page.route(/\/mail\/search\?q=e2e&fork_ai=1$/, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(/(<li[^>]*data-fork-search-insight[^>]*>)/, `$1${CHAT}`);

        await route.fulfill({ response, body });
    });
    await page.route(/\/mail\/search\/fork\/chat$/, (route) =>
        route.fulfill({ contentType: "application/x-ndjson", body: ndjson([{ type: "conversation", id: "b".repeat(32) }, { type: "error" }]) }),
    );

    await page.goto("/mail/search?q=e2e&fork_ai=1");

    const chat = page.locator("[data-fork-search-chat]");
    await expect(chat.locator('[data-slot="error"]').first()).toBeVisible({ timeout: 10_000 });
    await expect(chat.locator('button[type="submit"]')).toBeEnabled();

    await page.unrouteAll({ behavior: "ignoreErrors" });
});
