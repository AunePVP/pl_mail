import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { seed } from "./support/config";

/**
 * FORK (FORK.md 27): the Projektakte project chip and its picker.
 *
 * The server's half — asking Projektakte, checking the number, the JMAP change
 * log — is pinned in ForkProjectTest and ForkProjektakteEmailTest. What only a
 * browser can show is the controller: the picker opens with the first guess
 * marked and sends nothing until someone chooses, typing narrows the list,
 * Enter takes the marked row, and each answer replaces the chip.
 *
 * The chip is drawn only when PROJEKTAKTE_URL is set, which every other spec
 * would see, so its markup is put on the inbox page here and its endpoint
 * answered here — the markup as templates/fork/project/_chip.html.twig and
 * _picker.html.twig draw it.
 */

const URL = "/mail/message/1/fork/project";

type State = { status: "" | "assigned" | "none"; number?: string; name?: string };

function chip(state: State): string {
    const label = "assigned" === state.status
        ? `<span data-fork-project-number>${state.number}</span> <span>${state.name}</span>`
        : "none" === state.status ? "<span>Kein Projekt</span>" : "<span>Projekt zuordnen</span>";
    const clear = "" === state.status
        ? ""
        : `<button type="button" data-action="click->fork--project-picker#clear" data-fork-project-clear aria-label="Zuordnung entfernen">×</button>`;

    return `
<span id="fork_project_1" class="relative inline-flex" data-fork-project="${state.status}"
      data-controller="fork--project-picker"
      data-fork--project-picker-url-value="${URL}"
      data-fork--project-picker-i18n-value='{"loading":"Lädt Projekte …","failed":"Fehler"}'>
    <span class="inline-flex items-center">
        <button type="button" data-action="click->fork--project-picker#toggle"
                data-fork--project-picker-target="trigger" aria-expanded="false">${label}</button>
        ${clear}
    </span>
    <div data-fork--project-picker-target="panel" role="dialog"
         class="hidden absolute right-0 top-full mt-1 z-30 w-80 overflow-y-auto bg-surface border border-line"></div>
</span>`;
}

const PICKER = `
<div data-fork-project-picker>
    <ul>
        <li><button type="button" data-action="fork--project-picker#choose" data-fork--project-picker-target="choice"
                    data-number="BS-0000-0001" data-fork-project-suggestion data-preselected>
            BS-0000-0001 Rathaus Umbau <span>Projektnummer BS-0000-0001 im Betreff</span></button></li>
    </ul>
    <label><input type="search" data-fork--project-picker-target="search"
                  data-action="input->fork--project-picker#filter keydown->fork--project-picker#key" aria-label="Projekt suchen"></label>
    <ul data-fork-project-list>
        <li><button type="button" data-action="fork--project-picker#choose" data-fork--project-picker-target="choice item"
                    data-number="BS-0000-0002" data-search="bs-0000-0002 schule nord">BS-0000-0002 Schule Nord</button></li>
        <li><button type="button" data-action="fork--project-picker#choose" data-fork--project-picker-target="choice item"
                    data-number="BS-0000-0001" data-search="bs-0000-0001 rathaus umbau">BS-0000-0001 Rathaus Umbau</button></li>
    </ul>
    <p data-fork--project-picker-target="noMatch" hidden>Kein Projekt gefunden</p>
    <button type="button" data-action="fork--project-picker#none" data-fork-project-none>Kein Projektbezug</button>
    <p data-fork--project-picker-target="status" role="status" hidden></p>
</div>`;

const stream = (state: State) =>
    `<turbo-stream action="replace" target="fork_project_1"><template>${chip(state)}</template></turbo-stream>`;

type Sent = { method: string; body: unknown };

async function open(page: Page, sent: Sent[], refuse = false): Promise<void> {
    await page.route(/\/mail\/inbox$/, async (route) => {
        const response = await route.fetch();
        const holder = `<div style="position:fixed;top:96px;right:48px;z-index:50">${chip({ status: "" })}</div>`;

        await route.fulfill({ response, body: (await response.text()).replace("</body>", `${holder}</body>`) });
    });

    await page.route(new RegExp(`${URL}$`), async (route) => {
        const request = route.request();

        if ("GET" === request.method()) {
            await route.fulfill({ contentType: "text/html", body: PICKER });

            return;
        }

        const body = request.postDataJSON?.() ?? null;
        sent.push({ method: request.method(), body });

        if (true === refuse) {
            await route.fulfill({ status: 503, contentType: "application/json", body: JSON.stringify({ error: "Projektakte ist gerade nicht erreichbar." }) });

            return;
        }

        const state: State = "DELETE" === request.method()
            ? { status: "" }
            : true === (body as { none?: boolean }).none
                ? { status: "none" }
                : { status: "assigned", number: (body as { number: string }).number, name: "BS-0000-0001" === (body as { number: string }).number ? "Rathaus Umbau" : "Schule Nord" };

        await route.fulfill({ contentType: "text/vnd.turbo-stream.html", body: stream(state) });
    });

    await page.goto("/mail/inbox");
}

test("a guess is marked, and nothing is assigned until someone chooses", async ({ page }) => {
    seed("seed-mail");
    const sent: Sent[] = [];
    await open(page, sent);

    const project = page.locator("#fork_project_1");
    await project.getByRole("button", { name: "Projekt zuordnen" }).click();

    const guess = project.locator("[data-fork-project-suggestion]");
    await expect(guess).toHaveAttribute("aria-selected", "true");
    await expect(project.locator('[data-fork--project-picker-target="search"]')).toBeFocused();

    // Looking is not choosing.
    await page.waitForTimeout(300);
    expect(sent).toEqual([]);

    // A click on another project files it there.
    await project.locator('[data-fork-project-list] [data-number="BS-0000-0002"]').click();

    await expect(page.locator('#fork_project_1[data-fork-project="assigned"]')).toContainText("BS-0000-0002");
    await expect(page.locator('#fork_project_1 [data-fork--project-picker-target="panel"]')).toBeHidden();
    expect(sent).toEqual([{ method: "POST", body: { number: "BS-0000-0002" } }]);
});

test("typing narrows the list, Enter moves it, no project, and ×", async ({ page }) => {
    seed("seed-mail");
    const sent: Sent[] = [];
    await open(page, sent);

    const project = page.locator("#fork_project_1");
    await project.locator('[data-fork--project-picker-target="trigger"]').click();

    const search = project.locator('[data-fork--project-picker-target="search"]');
    await search.fill("rathaus");

    await expect(project.locator('[data-fork-project-list] [data-number="BS-0000-0002"]')).toBeHidden();
    await expect(project.locator('[data-fork-project-list] [data-number="BS-0000-0001"]')).toHaveAttribute("aria-selected", "true");

    await search.press("Enter");
    await expect(page.locator('#fork_project_1[data-fork-project="assigned"]')).toContainText("Rathaus Umbau");

    // Reopened on the new chip: "no project".
    await page.locator('#fork_project_1 [data-fork--project-picker-target="trigger"]').click();
    await page.locator("#fork_project_1 [data-fork-project-none]").click();
    await expect(page.locator('#fork_project_1[data-fork-project="none"]')).toContainText("Kein Projekt");

    // × takes it back without opening anything.
    await page.locator("#fork_project_1 [data-fork-project-clear]").click();
    await expect(page.locator('#fork_project_1[data-fork-project=""]')).toContainText("Projekt zuordnen");

    expect(sent).toEqual([
        { method: "POST", body: { number: "BS-0000-0001" } },
        { method: "POST", body: { none: true } },
        { method: "DELETE", body: null },
    ]);
});

test("a refusal is said in the picker, which stays open", async ({ page }) => {
    seed("seed-mail");
    const sent: Sent[] = [];
    await open(page, sent, true);

    const project = page.locator("#fork_project_1");
    await project.locator('[data-fork--project-picker-target="trigger"]').click();
    await project.locator("[data-fork-project-suggestion]").click();

    await expect(project.locator('[data-fork--project-picker-target="status"]')).toHaveText("Projektakte ist gerade nicht erreichbar.");
    await expect(project.locator('[data-fork--project-picker-target="panel"]')).toBeVisible();
    await expect(project).toHaveAttribute("data-fork-project", "");

    // Escape closes it.
    await project.locator('[data-fork--project-picker-target="search"]').press("Escape");
    await expect(project.locator('[data-fork--project-picker-target="panel"]')).toBeHidden();
});
