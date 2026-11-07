import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { forgetSearchChat } from "./search_chat_controller.js";

/**
 * FORK (FORK.md): the "KI-Suche" button in the search box. Opens the search
 * page with what is typed as the search chat's first message (`fork_ai=1`),
 * in the list's frame as the box itself does (mail/search_controller.js
 * #_visit, which also says why the frame falls back to a page visit by itself).
 */
const LIST_FRAME_ID = "inbox-list-frame";

export default class extends Controller {
    static values = { url: String };

    ask(event) {
        event.preventDefault();

        const input = this.element.parentElement?.querySelector('input[name="q"]');
        const q = (input?.value ?? "").trim();

        if ("" === q) {
            input?.focus();

            return;
        }

        // The box's suggestions are still open from the typing: closed as
        // Escape closes them (mail/search_controller.js onKeydown), which also
        // keeps a reply still on its way from opening them again. Only while
        // open — Escape on a closed list empties the box.
        const dropdown = this.element.closest("#search-shell")?.querySelector('[data-mail--search-target="dropdown"]');

        if (dropdown && false === dropdown.classList.contains("hidden")) {
            input.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true }));
        }

        input.blur();

        // Pressed, it is a new chat: one kept for the same words from earlier
        // in this tab is what Back and a reload come back to, not this.
        forgetSearchChat(q);

        Turbo.visit(`${this.urlValue}?q=${encodeURIComponent(q)}&fork_ai=1`, { frame: LIST_FRAME_ID });
    }
}
