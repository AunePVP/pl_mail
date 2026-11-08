import { test, expect } from "./support/test";
import { consoleCommand } from "./support/config";

/**
 * FORK (FORK.md): the composer says which names a rewrite did not bring back.
 *
 * ForkNameGuardTest pins what counts as a changed name and that the rule is in
 * the prompt; what only a browser shows is the sentence where Insert is, and
 * that it goes again with the next thing said.
 *
 * The file name ends in "ai-compose.spec.ts" on purpose: playwright.config.ts
 * puts that into the one-worker project, because writing help is an
 * install-wide switch (see ai-compose.spec.ts).
 */
const DOCK = "#compose_dock";
const MENU_BUTTON = `${DOCK} button[aria-label="Help me write"]`;
const STATUS = `${DOCK} [data-compose--ai-assist-target="status"]`;

test.beforeAll(() => {
    consoleCommand("app:test:ai-writing-help on");
});

test.afterAll(() => {
    consoleCommand("app:test:ai-writing-help off");
});

const ndjson = (frames: object[]) => frames.map((f) => JSON.stringify(f)).join("\n") + "\n";

test("a changed name is said in red, a clean answer is just finished", async ({ page }) => {
    let calls = 0;

    await page.route("**/compose/assist/stream", async (route) => {
        calls++;

        await route.fulfill({
            contentType: "application/x-ndjson",
            body: 1 === calls
                ? ndjson([
                    { type: "state", value: "generating" },
                    { type: "token", text: "Sehr geehrte Frau Strauß," },
                    { type: "done", text: "Sehr geehrte Frau Strauß,", names: ["Strauss"] },
                ])
                : ndjson([
                    { type: "state", value: "generating" },
                    { type: "token", text: "Sehr geehrte Frau Strauss," },
                    { type: "done", text: "Sehr geehrte Frau Strauss,", names: [] },
                ]),
        });
    });

    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();

    await page.locator(MENU_BUTTON).click();
    await page.getByRole("button", { name: "Fix spelling and grammar" }).click();

    const status = page.locator(STATUS);
    await expect(status).toHaveText("Check the names – not as you wrote them: Strauss");
    await expect(status).toHaveAttribute("data-fork-names", "Strauss");
    await expect(status).toHaveAttribute("style", /color/);

    await page.locator(MENU_BUTTON).click();
    await page.getByRole("button", { name: "Fix spelling and grammar" }).click();

    await expect.poll(() => calls).toBe(2);
    await expect(status).not.toHaveAttribute("data-fork-names", /.*/);
    await expect(status).not.toHaveAttribute("style", /color/);
});
