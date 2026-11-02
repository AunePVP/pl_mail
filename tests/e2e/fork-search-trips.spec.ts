import { expect } from "@playwright/test";
import { test } from "./support/test";
import { TEST_USER, consoleCommand, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * FORK (FORK.md): the coming-events panel above the search results.
 *
 * A browser spec because the failure is only true in a browser: the panel is a
 * lazy <turbo-frame>, and a plain link in it navigated that frame. The server
 * answered 200 and the page went blank until a reload.
 *
 * Uses the "Happening Soon" fixture: one flight, read out of one mail.
 */

const TITLE = "E2E flight to Berlin";
const SOURCE_SUBJECT = "E2E Read Me";

test.describe("search trips panel", () => {
    // seed-mail first and in the same call, as in happening-soon.spec.ts.
    test.beforeAll(() => {
        seed("seed-mail", "seed-extracted-event");
    });

    test.afterAll(() => {
        consoleCommand(`app:test:seed-extracted-event --email=${TEST_USER.email} --clear`);
    });

    test("a mail of a coming flight opens like a row of the list", async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto("/mail/search?q=flight");

        const trip = page.locator("[data-fork-trip]").filter({ hasText: TITLE });
        await expect(trip).toBeVisible({ timeout: 10_000 });

        await trip.locator("summary").click();
        await trip.getByRole("link", { name: SOURCE_SUBJECT }).click();

        await expect(page).toHaveURL(/\/mail\/(thread|message)\/\d+/);
        await expect(page.locator(".thread-message-head").first()).toBeVisible({ timeout: 10_000 });
        await settled(page);
        await page.screenshot({ path: testInfo.outputPath("opened.png") });
    });
});
