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
 * (connection_aborted() there).
 *
 * Every finished turn is kept in sessionStorage under the question the page
 * was opened with (KEPT), and a page that comes back with it — Back, a reload
 * — draws those turns again instead of asking. Turbo keeps no snapshot of the
 * mail pages (turbo-cache-control no-cache in _layout/_mailbox.html.twig), so
 * every Back fetched the page anew, and this sent the question to the model
 * a second time as a new conversation: the same answer again, half a minute
 * of the model's time, and the follow-ups gone. The "KI-Suche" button forgets
 * the question first (forgetSearchChat()), so pressing it is a new chat.
 */

/** sessionStorage key: { [question]: { conversation, turns: [{ message, frames }], at } }. */
const KEPT = "fork-search-chat";

/** Questions kept per tab; the oldest goes first. */
const KEPT_MAX = 8;

/** Turns kept per question, as many as the server remembers (ForkSearchChat::MAX_TURNS counts messages). */
const KEPT_TURNS = 6;

function readKept() {
    try {
        const kept = JSON.parse(sessionStorage.getItem(KEPT) ?? "{}");

        return null !== kept && "object" === typeof kept && false === Array.isArray(kept) ? kept : {};
    } catch {
        return {};
    }
}

function writeKept(kept) {
    try {
        const newest = Object.entries(kept).sort(([, a], [, b]) => (b?.at ?? 0) - (a?.at ?? 0)).slice(0, KEPT_MAX);

        sessionStorage.setItem(KEPT, JSON.stringify(Object.fromEntries(newest)));
    } catch {
        // Full or switched off: the chat works as before, it only cannot come back.
    }
}

/** The "KI-Suche" button's: what is typed is a new chat, whatever was asked before. */
export function forgetSearchChat(question) {
    const kept = readKept();

    if (undefined === kept[question]) return;

    delete kept[question];
    writeKept(kept);
}

export default class extends Controller {
    static targets = ["log", "input", "submit", "userTemplate", "answerTemplate", "linkTemplate", "strings"];

    static values = { url: String, question: String, conversation: String };

    #controller = null;

    connect() {
        const question = this.questionValue.trim();

        if (0 !== this.logTarget.children.length || "" === question) return;

        if (true === this.#restore(question)) return;

        this.#ask(question);
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
        // What is kept of the turn: every frame but the tokens, which `done` repeats whole.
        const frames = [];

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
                if ("token" !== frame?.type) frames.push(frame);

                settled = this.#apply(turn, frame) || settled;
            }, isCurrent);

            if (true === isCurrent() && "done" === frames.at(-1)?.type) this.#keep(message, frames);

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

    /** The turns kept for this question, drawn again; whether there were any. */
    #restore(question) {
        const kept = readKept()[question];

        if (!Array.isArray(kept?.turns) || 0 === kept.turns.length) return false;

        if ("string" === typeof kept.conversation) this.conversationValue = kept.conversation;

        kept.turns.forEach((entry) => {
            const { message, frames } = entry ?? {};

            const user = this.userTemplateTarget.content.firstElementChild.cloneNode(true);
            this.#slot(user, "text").textContent = String(message ?? "");
            this.logTarget.append(user);

            const turn = this.answerTemplateTarget.content.firstElementChild.cloneNode(true);
            this.logTarget.append(turn);

            (Array.isArray(frames) ? frames : []).forEach((frame) => this.#apply(turn, frame ?? {}));
        });

        return true;
    }

    #keep(message, frames) {
        const question = this.questionValue.trim();

        if ("" === question) return;

        const kept = readKept();
        const turns = Array.isArray(kept[question]?.turns) ? kept[question].turns : [];

        kept[question] = { conversation: this.conversationValue, turns: [...turns, { message, frames }].slice(-KEPT_TURNS), at: Date.now() };
        writeKept(kept);
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
