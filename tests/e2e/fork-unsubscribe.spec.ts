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

/** One message of this worker's conversation fixture: the newest, or the first. */
function updateMessage(set: string, order: "DESC" | "ASC"): void {
    consoleCommand(
        `dbal:run-sql "UPDATE message SET ${set}`
        + ` WHERE id = (SELECT m.id FROM message m JOIN account a ON a.id = m.account_id`
        + ` JOIN \\"user\\" u ON u.id = a.usr_id WHERE u.email = '${TEST_USER.email}'`
        + ` AND m.subject LIKE '%E2E Conversation' ORDER BY m.received_at ${order} NULLS LAST, m.id ${order} LIMIT 1)"`,
    );
}

function giveTheNewestMessageAnUnsubscribeHeader(): void {
    // Built in SQL: double quotes from JSON would end the shell argument.
    const header = `jsonb_build_object('list-unsubscribe', '<mailto:stop@example.com>, <${LINK}>')`;

    updateMessage(`headers = (COALESCE(headers::jsonb, '{}'::jsonb) || ${header})::json`, "DESC");
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

/**
 * No header, only a footer sentence: the link is found in the text. The first
 * message of the thread, because the replies carry In-Reply-To and a reply is
 * never searched. Unquoted href for the same shell reason as above.
 */
test("without the header the link is taken from the mail's text", async ({ page }) => {
    seed("seed-conversation");
    const footer = "https://clicks.example.com/f/a/2";
    updateMessage(
        `body_html = '<p>News.</p><p>Wenn du keine weiteren Nachrichten erhalten möchtest, kannst du dich`
        + ` <a href=${footer}>hier</a> abmelden.</p>'`,
        "ASC",
    );

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/mail/inbox");
    await settled(page);
    await mailRow(page, "E2E Conversation").click();

    const chip = page.locator(".thread-message-meta > a[data-fork-unsubscribe]");
    await expect(chip).toHaveCount(1, { timeout: 10_000 });
    await expect(chip).toHaveAttribute("href", footer);
    await expect(chip).toHaveAttribute("title", /text/);
});
