import { expect } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * FORK (FORK.md): "Kurz gesagt" written into its box while the model writes it.
 *
 * The server's half — the box, the NDJSON, the kept answer — is pinned in
 * ForkSearchInsightTest. What only a browser can show is the controller:
 * that the tokens land in the box as they come, and that `done` loads the
 * frame again for the finished answer with its links.
 *
 * Switching the summary feature on is a singleton setting every other spec
 * would see, so the three requests are answered here instead: the frame with
 * the box the server draws, the stream, and the reload after it.
 */

const SHELL = `
<turbo-frame id="fork-search-answer" target="_top">
    <div data-controller="fork--search-answer"
         data-fork--search-answer-url-value="/mail/search/fork/answer/stream?q=e2e"
         data-fork--search-answer-after-value="/mail/search/fork/answer?q=e2e&amp;after=1"
         data-fork-answer-state="streaming">
        <div data-fork--search-answer-target="waiting">…</div>
        <div data-fork--search-answer-target="box" hidden>
            <p data-fork--search-answer-target="text"></p>
        </div>
    </div>
</turbo-frame>`;

const FINAL = `
<turbo-frame id="fork-search-answer" target="_top">
    <div data-fork-answer-state="ready"><p data-fork-answer-text>Dein Flug ist bestätigt.</p></div>
</turbo-frame>`;

test("the answer is written into its box, then drawn whole", async ({ page }, testInfo) => {
    seed("seed-mail");

    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => (release = resolve));

    await page.route(/\/mail\/search\/fork\/answer\?q=e2e&after=1/, (route) =>
        route.fulfill({ contentType: "text/html", body: FINAL }),
    );
    await page.route(/\/mail\/search\/fork\/answer\?q=e2e$/, (route) =>
        route.fulfill({ contentType: "text/html", body: SHELL }),
    );
    await page.route(/\/mail\/search\/fork\/answer\/stream/, async (route) => {
        // Held until the box has been looked at, so "while it writes" is a state the test can see.
        await held;

        const frames = ["Dein ", "Flug ", "ist ", "bestätigt."].map((text) => JSON.stringify({ type: "token", text }));

        await route.fulfill({ contentType: "application/x-ndjson", body: frames.join("\n") + "\n" });
    });

    await page.goto("/mail/search?q=e2e");

    const frame = page.locator("turbo-frame#fork-search-answer");
    await expect(frame.locator('[data-fork-answer-state="streaming"]')).toBeAttached({ timeout: 10_000 });
    await expect(frame.locator('[data-fork--search-answer-target="box"]')).toBeHidden();

    release();

    // No `done` in that stream: what arrived stays, readable, in the box.
    await expect(frame.locator('[data-fork--search-answer-target="text"]')).toHaveText("Dein Flug ist bestätigt.");
    await expect(frame.locator('[data-fork--search-answer-target="waiting"]')).toBeHidden();
    await settled(page);
    await page.screenshot({ path: testInfo.outputPath("streamed.png") });
});

test("done loads the finished answer into the frame", async ({ page }) => {
    seed("seed-mail");

    await page.route(/\/mail\/search\/fork\/answer\?q=e2e&after=1/, (route) =>
        route.fulfill({ contentType: "text/html", body: FINAL }),
    );
    await page.route(/\/mail\/search\/fork\/answer\?q=e2e$/, (route) =>
        route.fulfill({ contentType: "text/html", body: SHELL }),
    );
    await page.route(/\/mail\/search\/fork\/answer\/stream/, (route) =>
        route.fulfill({
            contentType: "application/x-ndjson",
            body: [{ type: "token", text: "Dein Flug" }, { type: "done" }].map((f) => JSON.stringify(f)).join("\n") + "\n",
        }),
    );

    await page.goto("/mail/search?q=e2e");

    const frame = page.locator("turbo-frame#fork-search-answer");
    await expect(frame.locator('[data-fork-answer-state="ready"] [data-fork-answer-text]')).toHaveText("Dein Flug ist bestätigt.", { timeout: 10_000 });
    await expect(frame.locator('[data-controller="fork--search-answer"]')).toHaveCount(0);
});
