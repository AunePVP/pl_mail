import { test, expect } from "./support/test";
import { consoleCommand, mailRow, seed, TEST_USER } from "./support/config";
import { settled } from "./support/motion";

/**
 * FORK (FORK.md): the Unsubscribe button beside the date, and in the ⋮ menu.
 *
 * The conversation fixture has no List-Unsubscribe, so the newest message is
 * given one here. Only that message carries it, which is the point of the
 * second assertion: the button belongs to a message, not to the thread.
 */
const LINK = "https://news.example.com/unsubscribe?u=1&t=2";

function giveTheNewestMessageAnUnsubscribeHeader(): void {
    // Built in SQL: double quotes from JSON would end the shell argument.
    const header = `jsonb_build_object('list-unsubscribe', '<mailto:stop@example.com>, <${LINK}>')`;

    consoleCommand(
        `dbal:run-sql "UPDATE message SET headers = (COALESCE(headers::jsonb, '{}'::jsonb) || ${header})::json`
        + ` WHERE id = (SELECT m.id FROM message m JOIN account a ON a.id = m.account_id`
        + ` JOIN \\"user\\" u ON u.id = a.usr_id WHERE u.email = '${TEST_USER.email}'`
        + ` AND m.subject LIKE '%E2E Conversation' ORDER BY m.received_at DESC NULLS LAST, m.id DESC LIMIT 1)"`,
    );
}

test("a newsletter's Unsubscribe button opens the sender's link", async ({ page }, testInfo) => {
    seed("seed-conversation");
    giveTheNewestMessageAnUnsubscribeHeader();

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/mail/inbox");
    await settled(page);
    await mailRow(page, "E2E Conversation").click();

    const chip = page.locator(".thread-message-meta > a[data-fork-unsubscribe]");
    await expect(chip).toHaveCount(1, { timeout: 10_000 });
    await expect(chip).toBeVisible();
    await expect(chip).toHaveAttribute("href", LINK);
    await expect(chip).toHaveAttribute("target", "_blank");
    await settled(page);
    await page.screenshot({ path: testInfo.outputPath("header.png") });

    // The menu of the message that has the header.
    const head = page.locator(".thread-message-head", { has: chip });
    await head.locator('[data-action*="mail--message-actions#toggle"]').click();
    const entry = head.locator('[data-mail--message-actions-target="panel"] a[data-fork-unsubscribe]');
    await expect(entry).toBeVisible();
    await expect(entry).toHaveAttribute("href", LINK);
    await page.screenshot({ path: testInfo.outputPath("menu.png") });

    // Narrow: the meta row takes a line of its own; the chip must still fit.
    await page.keyboard.press("Escape");
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(chip).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath("narrow.png") });
});
