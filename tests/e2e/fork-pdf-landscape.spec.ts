import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * FORK (FORK.md 24): landscape pages keep their proportions.
 *
 * The page canvases had a fixed CSS width and height plus `max-w-full`. A page
 * wider than the reader — any landscape plan — had its width capped and its
 * height kept, and was drawn squashed sideways. Page two of
 * `SamplePdf::awkward()` is rotated 90°, so it is landscape.
 *
 * ⚠ Run `php bin/console asset-map:compile` after changing assets/.
 */
test.beforeEach(() => {
    seed("seed-mail", "seed-attachment");
});

const PAGES = '[data-mail--pdf-viewer-target="stack"] canvas';

async function openReader(page: Page) {
    await page.goto("/mail/inbox");
    await page.locator("#message-list li").filter({ hasText: "E2E Attachment" }).first().click();
    await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();
    await expect(page.locator(PAGES)).toHaveCount(2, { timeout: 20000 });
}

/** Drawn width/height against the width/height the page asked for. */
async function distortions(page: Page): Promise<number[]> {
    return page.locator(PAGES).evaluateAll((nodes) =>
        nodes.map((node) => {
            const canvas = node as HTMLCanvasElement;
            const box = canvas.getBoundingClientRect();
            const wanted = parseFloat(canvas.style.width) / parseFloat(canvas.style.height);

            return Math.abs(box.width / box.height - wanted);
        }),
    );
}

test.describe("the PDF reader with a landscape page", () => {
    test("opens the landscape page undistorted and inside the reader", async ({ page }) => {
        await openReader(page);

        for (const off of await distortions(page)) {
            expect(off).toBeLessThan(0.01);
        }

        const fits = await page.locator('[data-mail--pdf-viewer-target="scroller"]').evaluate(
            (scroller) => scroller.scrollWidth <= scroller.clientWidth + 1,
        );
        expect(fits, "the widest page should open at the reader's width").toBe(true);
    });

    test("zoomed past the reader's width, pages stay undistorted and scroll sideways", async ({ page }) => {
        await openReader(page);

        const zoomIn = page.getByRole("button", { name: "Zoom in" });
        for (let i = 0; i < 6; i += 1) {
            await zoomIn.click();
        }

        const scroller = page.locator('[data-mail--pdf-viewer-target="scroller"]');
        await expect
            .poll(() => scroller.evaluate((node) => node.scrollWidth > node.clientWidth))
            .toBe(true);

        for (const off of await distortions(page)) {
            expect(off).toBeLessThan(0.01);
        }

        // The left edge of the widest page is reachable: scrolled fully left,
        // it starts inside the scroller, not cut off beyond it.
        const cut = await scroller.evaluate((node) => {
            node.scrollLeft = 0;
            const left = node.getBoundingClientRect().left;

            return Array.from(node.querySelectorAll("canvas[data-page]")).some(
                (canvas) => canvas.getBoundingClientRect().left < left - 1,
            );
        });
        expect(cut, "a page's left edge is out of reach").toBe(false);
    });
});
