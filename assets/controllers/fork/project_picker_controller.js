import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { requestFailed } from "../../request_errors.js";

/**
 * FORK (FORK.md 27): the Projektakte project chip in a message's header and
 * the picker it opens.
 *
 * The picker's content is the server's (templates/fork/project/_picker.html.twig),
 * fetched every time it opens: the server asks Projektakte for its projects
 * and its guesses, because the token must never reach the browser. This
 * controller places the panel, filters the list as you type, and sends the
 * choice; the answer is a Turbo Stream that replaces the whole chip — and
 * with it this controller, which closes the panel on the way out.
 *
 * The first guess is marked (aria-selected) so that Enter takes it. Nothing is
 * sent without a click or a key: a guess, however sure, is a guess.
 *
 * Placement and the top layer are mail--label-menu's, for the same reasons:
 * the header sits in a pane with a backdrop filter, which traps a fixed panel
 * and clips an absolute one.
 *
 * Values
 *   url   String  GET → picker fragment, POST {number}|{none: true}, DELETE
 *   i18n  Object  loading, failed
 *
 * Targets
 *   trigger, panel                    the chip (_chip.html.twig)
 *   search, choice, item, noMatch,    the fragment (_picker.html.twig);
 *   status                            items are the list, choices every row
 *                                     that assigns (guesses and list)
 */
export default class extends Controller {
    static targets = ["trigger", "panel", "search", "choice", "item", "noMatch", "status"];

    static values = {
        url: String,
        i18n: Object,
    };

    connect() {
        this._boundClose = this._closeOnOutsideClick.bind(this);
        this._busy = false;
    }

    disconnect() {
        this._close();
    }

    toggle(event) {
        event.stopPropagation();

        if (false === this.panelTarget.classList.contains("hidden")) {
            this._close();

            return;
        }

        this.panelTarget.classList.remove("hidden");
        this.triggerTarget.setAttribute("aria-expanded", "true");
        this._escape();
        this._place();
        this._load();

        document.addEventListener("click", this._boundClose, { capture: true });
        window.addEventListener("scroll", this._boundClose, { capture: true });
        window.addEventListener("resize", this._boundClose);
    }

    /** Narrow the list to the projects whose number or name contains what was typed. */
    filter() {
        const wanted = this.searchTarget.value.trim().toLowerCase();
        let shown = 0;

        for (const item of this.itemTargets) {
            const match = "" === wanted || (item.dataset.search ?? "").includes(wanted);

            item.parentElement.hidden = false === match;
            shown += match ? 1 : 0;
        }

        if (this.hasNoMatchTarget) {
            this.noMatchTarget.hidden = 0 !== shown || 0 === this.itemTargets.length;
        }

        // Typing picks the first project that fits; an empty field goes back
        // to the first guess.
        this._select("" === wanted ? this._preselected() : this._visible().find((choice) => this.itemTargets.includes(choice)) ?? null);
    }

    /** Arrows walk the rows, Enter takes the marked one, Escape closes. */
    key(event) {
        if ("Escape" === event.key) {
            event.preventDefault();
            event.stopPropagation();
            this._close();
            this.triggerTarget.focus();

            return;
        }

        if ("Enter" === event.key) {
            event.preventDefault();

            const selected = this._selected();

            if (null !== selected) {
                this._send("POST", { number: selected.dataset.number });
            }

            return;
        }

        if ("ArrowDown" === event.key || "ArrowUp" === event.key) {
            event.preventDefault();

            const visible = this._visible();

            if (0 === visible.length) {
                return;
            }

            const at = visible.indexOf(this._selected());
            const next = "ArrowDown" === event.key ? Math.min(visible.length - 1, at + 1) : Math.max(0, at - 1);

            this._select(visible[next]);
            visible[next].scrollIntoView({ block: "nearest" });
        }
    }

    choose(event) {
        event.stopPropagation();
        this._send("POST", { number: event.currentTarget.dataset.number });
    }

    none(event) {
        event.stopPropagation();
        this._send("POST", { none: true });
    }

    clear(event) {
        event.stopPropagation();
        this._send("DELETE", null);
    }

    // ── Private ───────────────────────────────────────────────────────────

    async _load() {
        this.panelTarget.replaceChildren(this._note(this.i18nValue.loading ?? ""));

        let html;

        try {
            const response = await fetch(this.urlValue, { headers: { Accept: "text/html" } });

            if (false === response.ok) {
                throw new Error(String(response.status));
            }

            html = await response.text();
        } catch {
            this.panelTarget.replaceChildren(this._note(this.i18nValue.failed ?? ""));

            return;
        }

        // Closed while it loaded: leave it closed and empty.
        if (this.panelTarget.classList.contains("hidden")) {
            return;
        }

        this.panelTarget.innerHTML = html;
        this._place();
        this._select(this._preselected());

        // Typing straight away is how a long list is used; on a phone the
        // keyboard would cover the guesses.
        if (this.hasSearchTarget && false === window.matchMedia("(max-width: 767px)").matches) {
            this.searchTarget.focus({ preventScroll: true });
        }
    }

    /**
     * One change, then the chip the server draws for it. A refusal (Projektakte
     * down, a project it does not know) is said in the panel, which stays open.
     */
    async _send(method, body) {
        if (true === this._busy) {
            return;
        }

        this._busy = true;
        this._say("");

        let response;

        try {
            response = await fetch(this.urlValue, {
                method,
                headers: jsonCsrfHeaders(),
                body: null === body ? null : JSON.stringify(body),
            });
        } catch {
            this._busy = false;
            requestFailed(null);

            return;
        }

        this._busy = false;

        if (false === response.ok) {
            const error = await response.json().then((data) => data?.error ?? null, () => null);

            if (null !== error && false === this.panelTarget.classList.contains("hidden")) {
                this._say(error);
            } else {
                requestFailed(response);
            }

            return;
        }

        const html = await response.text();

        if ("" !== html.trim()) {
            Turbo.renderStreamMessage(html);
        }
    }

    _say(text) {
        if (false === this.hasStatusTarget) {
            return;
        }

        this.statusTarget.textContent = text;
        this.statusTarget.hidden = "" === text;
    }

    _preselected() {
        return this.choiceTargets.find((choice) => "preselected" in choice.dataset) ?? null;
    }

    _selected() {
        return this.choiceTargets.find((choice) => "true" === choice.getAttribute("aria-selected")) ?? null;
    }

    _select(choice) {
        for (const each of this.choiceTargets) {
            each.setAttribute("aria-selected", each === choice ? "true" : "false");
        }
    }

    _visible() {
        return this.choiceTargets.filter((choice) => false === choice.parentElement.hidden);
    }

    _note(text) {
        const note = document.createElement("p");

        note.className = "px-3 py-2 text-xs text-ink-faint";
        note.textContent = text;

        return note;
    }

    _closeOnOutsideClick(event) {
        if (false === this.element.contains(event.target)) {
            this._close();
        }
    }

    _close() {
        if (false === this.hasPanelTarget) {
            return;
        }

        this.panelTarget.classList.add("hidden");
        this.panelTarget.replaceChildren();

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute("aria-expanded", "false");
        }

        // hidePopover() throws on a popover that is not open.
        if (this._canEscape() && this.panelTarget.matches(":popover-open")) {
            this.panelTarget.hidePopover();
        }

        document.removeEventListener("click", this._boundClose, { capture: true });
        window.removeEventListener("scroll", this._boundClose, { capture: true });
        window.removeEventListener("resize", this._boundClose);

        for (const property of ["position", "top", "bottom", "left", "right", "margin", "maxHeight"]) {
            this.panelTarget.style[property] = "";
        }
    }

    /** Into the top layer while open — see mail--label-menu#_escape. */
    _escape() {
        if (false === this._canEscape()) {
            return;
        }

        if (false === this.panelTarget.hasAttribute("popover")) {
            this.panelTarget.setAttribute("popover", "manual");
        }

        this.panelTarget.showPopover();
    }

    _canEscape() {
        return "function" === typeof this.panelTarget.showPopover;
    }

    /**
     * Under the chip, right edges together, flipped above when there is more
     * room there; capped to the room it has. See mail--label-menu#_place.
     */
    _place() {
        const panel = this.panelTarget;
        const rect = this.triggerTarget.getBoundingClientRect();
        const margin = 8;

        if (false === panel.matches(":popover-open")) {
            return;
        }

        const below = window.innerHeight - rect.bottom - margin;
        const above = rect.top - margin;
        const flip = below < 240 && above > below;
        const width = panel.getBoundingClientRect().width;
        const right = this.element.getBoundingClientRect().right;
        const left = Math.min(
            Math.max(margin, right - width),
            Math.max(margin, window.innerWidth - width - margin),
        );

        panel.style.position = "fixed";
        panel.style.margin = "0";
        panel.style.left = `${Math.round(left)}px`;
        panel.style.right = "auto";
        panel.style.maxHeight = `${Math.max(160, Math.floor(flip ? above : below))}px`;

        if (flip) {
            panel.style.top = "auto";
            panel.style.bottom = `${Math.round(window.innerHeight - rect.top + 4)}px`;
        } else {
            panel.style.top = `${Math.round(rect.bottom + 4)}px`;
            panel.style.bottom = "auto";
        }
    }
}
