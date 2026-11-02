import { test, expect } from "./support/test";
import { TEST_ADMIN, login, seedUser } from "./support/config";
import { settled } from "./support/motion";

/**
 * FORK (FORK.md): the search reranker's address in Admin → AI, and what Test
 * says about it, beside the field.
 *
 * Tested and never saved: fork_ai_options is one row for the whole suite, and
 * a saved address would send every other spec's searches to it.
 */
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    seedUser({ email: TEST_ADMIN.email, password: TEST_ADMIN.password, admin: true });
});

test("Test asks the reranker's address and says what it found", async ({ page }, testInfo) => {
    await login(page, TEST_ADMIN.email, TEST_ADMIN.password);
    await page.goto("/admin?section=ai");

    const field = page.locator("#ai_settings_forkRerankUrl");
    const card = page.locator("details").filter({ has: field });

    if (0 < (await card.count()) && null === (await card.first().getAttribute("open"))) {
        await card.first().locator("summary").first().click();
    }

    await expect(field).toBeVisible();

    // A port nothing listens on: refused at once, so the answer is quick.
    await field.fill("http://127.0.0.1:9/rerank");
    await page.locator('button[formaction$="/admin/ai/test"]').click();

    const answer = page.locator('[data-fork-rerank-probe="failed"]');
    await expect(answer).toBeVisible({ timeout: 15_000 });
    await expect(page.locator("#ai_settings_forkRerankUrl")).toHaveValue("http://127.0.0.1:9/rerank");
    await settled(page);
    await answer.scrollIntoViewIfNeeded();
    await page.screenshot({ path: testInfo.outputPath("rerank-test.png") });
});
