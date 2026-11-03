import { Controller } from "@hotwired/stimulus";
import { readFrames } from "../../ai/ndjson.js";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * FORK (FORK.md): the search chat above the results (ForkSearchChat).
 *
 * Each message is one POST to ForkSearchChatController, read as NDJSON through
 * the composer's line reader: `conversation` (the id the next message carries),
 * `search` (what the model searches for), `results` (what it found), `token`
 * frames with the answer as it is written, then `done` with the mails worth
 * opening, or `error`.
 *
 * Every turn is drawn from a <template> in _chat.html.twig, and everything the
 * server sends is set as text: a subject is somebody else's words.
 *
 * Leaving the page aborts the fetch, and the server stops the model
 * (connection_aborted() there). A snapshot Turbo restores keeps its turns and
 * its conversation id on the element, and is not asked again.
 */
export default class extends Controller {
    static targets = ["log", "input", "submit", "userTemplate", "answerTemplate", "linkTemplate", "strings"];

    static values = { url: String, question: String, conversation: String };

    #controller = null;

    connect() {
        if (0 === this.logTarget.children.length && "" !== this.questionValue.trim()) {
            this.#ask(this.questionValue.trim());
        }
    }

    disconnect() {
        this.#controller?.abort();
        this.#controller = null;
    }

    send(event) {
        event.preventDefault();

        const message = this.inputTarget.value.trim();

        if ("" === message || null !== this.#controller) return;

        this.inputTarget.value = "";
        this.#ask(message);
    }

    async #ask(message) {
        const user = this.userTemplateTarget.content.firstElementChild.cloneNode(true);
        this.#slot(user, "text").textContent = message;
        this.logTarget.append(user);

        const turn = this.answerTemplateTarget.content.firstElementChild.cloneNode(true);
        this.logTarget.append(turn);

        const controller = new AbortController();
        this.#controller = controller;
        this.submitTarget.disabled = true;

        const isCurrent = () => this.#controller === controller;
        let settled = false;

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders({ "Content-Type": "application/x-www-form-urlencoded", Accept: "application/x-ndjson" }),
                body: new URLSearchParams({ conversation: this.conversationValue, message }),
                signal: controller.signal,
            });

            if (false === isCurrent()) return;

            if (false === response.ok || null === response.body) {
                this.#fail(turn);
                settled = true;

                return;
            }

            await readFrames(response.body, (frame) => {
                settled = this.#apply(turn, frame) || settled;
            }, isCurrent);

            if (true === isCurrent() && false === settled) this.#fail(turn);
        } catch (error) {
            if ("AbortError" !== error?.name && true === isCurrent()) this.#fail(turn);
        } finally {
            if (true === isCurrent()) {
                this.#controller = null;
                this.submitTarget.disabled = false;
                this.inputTarget.focus({ preventScroll: true });
            }
        }
    }

    /** @returns {boolean} whether the turn is over */
    #apply(turn, frame) {
        switch (frame.type) {
            case "conversation":
                if ("string" === typeof frame.id) this.conversationValue = frame.id;

                return false;

            case "search": {
                const queries = Array.isArray(frame.queries) ? frame.queries.filter((q) => "string" === typeof q) : [];

                this.#status(turn, queries.length > 0 ? this.#string("searching") : this.#string("noSearch"));

                if (queries.length > 0) {
                    this.#slot(turn, "queries-text").textContent = queries.join(" · ");
                    this.#slot(turn, "queries").hidden = false;
                }

                return false;
            }

            case "results": {
                const items = Array.isArray(frame.items) ? frame.items : [];
                const list = this.#slot(turn, "results-list");

                items.forEach((item) => list.append(this.#link(item)));
                this.#slot(turn, "results-summary").textContent = 0 === items.length
                    ? this.#string("nothing")
                    : 1 === items.length ? this.#string("foundOne") : this.#string("found").replace("%count%", String(items.length));
                this.#slot(turn, "results").hidden = false;
                this.#status(turn, this.#string("writing"));

                return false;
            }

            case "token":
                if ("string" === typeof frame.text) {
                    const text = this.#slot(turn, "text");

                    text.hidden = false;
                    text.textContent += frame.text;
                    this.#slot(turn, "status").hidden = true;
                }

                return false;

            case "done": {
                const text = this.#slot(turn, "text");

                // The answer as the server read it, which is what was kept.
                if ("string" === typeof frame.answer && "" !== frame.answer) {
                    text.textContent = frame.answer;
                    text.hidden = false;
                }

                const important = this.#slot(turn, "important");
                (Array.isArray(frame.important) ? frame.important : []).forEach((item) => important.append(this.#link(item, true)));
                important.hidden = 0 === important.children.length;

                this.#slot(turn, "status").hidden = true;
                this.#stopPulse(turn);

                return true;
            }

            case "error":
                this.#fail(turn);

                return true;

            default:
                return false;
        }
    }

    #link(item, withArrow = false) {
        const row = this.linkTemplateTarget.content.firstElementChild.cloneNode(true);
        const link = row.querySelector("a");

        if ("string" === typeof item.url && item.url.startsWith("/")) link.href = item.url;

        this.#slot(row, "date").textContent = this.#date(item.date);
        this.#slot(row, "subject").textContent = "string" === typeof item.subject && "" !== item.subject ? item.subject : "—";
        this.#slot(row, "from").textContent = "string" === typeof item.from && "" !== item.from ? `· ${item.from}` : "";

        if (true === withArrow) {
            const arrow = document.createElement("i");
            arrow.className = "fa-solid fa-arrow-right text-accent text-[10px] mr-1";
            arrow.setAttribute("aria-hidden", "true");
            link.prepend(arrow);
        }

        return row;
    }

    #date(value) {
        if ("string" !== typeof value || false === /^\d{4}-\d{2}-\d{2}$/.test(value)) return "";

        const [y, m, d] = value.split("-").map(Number);

        return new Date(y, m - 1, d).toLocaleDateString(document.documentElement.lang || undefined, { day: "numeric", month: "short", year: "numeric" });
    }

    #fail(turn) {
        this.#slot(turn, "status").hidden = true;
        this.#slot(turn, "error").hidden = false;
        this.#stopPulse(turn);
    }

    #status(turn, text) {
        this.#slot(turn, "status-text").textContent = text;
        this.#slot(turn, "status").hidden = false;
    }

    #stopPulse(turn) {
        turn.querySelectorAll(".animate-pulse").forEach((icon) => icon.classList.remove("animate-pulse"));
    }

    #slot(root, name) {
        return root.querySelector(`[data-slot="${name}"]`);
    }

    #string(name) {
        return this.stringsTarget.dataset[name] ?? "";
    }
}
