import { Controller } from "@hotwired/stimulus";
import { readFrames } from "../../ai/ndjson.js";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * FORK (FORK.md): "Anhänge fragen" — the chat about one mail's attachments
 * (ForkAttachmentChat), in the modal.
 *
 * Each question is one POST to ForkAttachmentAskController, read as NDJSON
 * through the composer's line reader: `conversation` (the id the next question
 * carries), `documents` (what could be read of each attachment), `token`
 * frames with the answer as it is written, then `done` or `error`.
 *
 * The attachments ticked at the first question are the conversation's; after
 * it the checkboxes are locked. Everything the server sends is set as text.
 *
 * Closing the dialog removes the element and aborts the fetch; the server
 * then stops the model (connection_aborted() there).
 */
export default class extends Controller {
    static targets = ["log", "input", "submit", "part", "parts", "suggestions", "scroller", "userTemplate", "answerTemplate", "strings"];

    static values = { url: String, conversation: String };

    #controller = null;

    connect() {
        this.inputTarget.focus({ preventScroll: true });
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

    suggest(event) {
        if (null !== this.#controller) return;

        this.#ask(event.currentTarget.dataset.question ?? "");
    }

    /** Enter sends, Shift+Enter is a new line. */
    key(event) {
        if ("Enter" === event.key && false === event.shiftKey && false === event.isComposing) {
            this.send(event);
        }
    }

    #chosen() {
        return this.partTargets
            .filter((input) => "checkbox" !== input.type || input.checked)
            .map((input) => input.value);
    }

    async #ask(message) {
        if ("" === message.trim()) return;

        const parts = this.#chosen();

        if (0 === parts.length) {
            this.#notice(this.#string("nothingChosen"));

            return;
        }

        this.suggestionsTarget.hidden = true;

        const user = this.userTemplateTarget.content.firstElementChild.cloneNode(true);
        this.#slot(user, "text").textContent = message;
        this.logTarget.append(user);

        const turn = this.answerTemplateTarget.content.firstElementChild.cloneNode(true);
        this.logTarget.append(turn);
        this.#scroll();

        const controller = new AbortController();
        this.#controller = controller;
        this.submitTarget.disabled = true;

        const isCurrent = () => this.#controller === controller;
        let settled = false;

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders({ "Content-Type": "application/x-www-form-urlencoded", Accept: "application/x-ndjson" }),
                body: new URLSearchParams({ conversation: this.conversationValue, message, parts: parts.join(",") }),
                signal: controller.signal,
            });

            if (false === isCurrent()) return;

            if (false === response.ok || null === response.body) {
                this.#fail(turn, this.#string("failed"));
                settled = true;

                return;
            }

            await readFrames(response.body, (frame) => {
                settled = this.#apply(turn, frame) || settled;
            }, isCurrent);

            if (true === isCurrent() && false === settled) this.#fail(turn, this.#string("failed"));
        } catch (error) {
            if ("AbortError" !== error?.name && true === isCurrent()) this.#fail(turn, this.#string("failed"));
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

                // The conversation is about what was ticked now.
                this.partTargets.forEach((input) => { input.disabled = true; });

                return false;

            case "documents":
                (Array.isArray(frame.items) ? frame.items : []).forEach((item) => this.#mark(item));
                this.#status(turn, this.#string("thinking"));

                return false;

            case "token":
                if ("string" === typeof frame.text) {
                    const text = this.#slot(turn, "text");

                    text.hidden = false;
                    text.textContent += frame.text;
                    this.#slot(turn, "status").hidden = true;
                    this.#scroll();
                }

                return false;

            case "done": {
                const text = this.#slot(turn, "text");

                if ("string" === typeof frame.answer && "" !== frame.answer) {
                    text.textContent = frame.answer;
                    text.hidden = false;
                }

                this.#slot(turn, "status").hidden = true;
                this.#stopPulse(turn);
                this.#scroll();

                return true;
            }

            case "error":
                this.#fail(turn, "unreadable" === frame.reason ? this.#string("unreadable") : this.#string("failed"));

                return true;

            default:
                return false;
        }
    }

    /** What could be read of one attachment, beside its name. */
    #mark(item) {
        if ("number" !== typeof item?.id) return;

        const label = this.partsTarget.querySelector(`[data-part-id="${item.id}"]`);
        const state = label?.querySelector('[data-slot="state"]');

        if (!state) return;

        let text;

        switch (item.status) {
            case "text":
                text = "number" === typeof item.pages
                    ? this.#string("statePages").replace("%count%", String(item.pages))
                    : this.#string("stateText");
                break;
            case "images":
                text = this.#string("stateImages");
                break;
            case "scan":
                text = this.#string("stateScan");
                break;
            case "unsupported":
                text = this.#string("stateUnsupported");
                break;
            default:
                text = this.#string("stateFailed");
        }

        if (true === item.truncated) text += ` · ${this.#string("stateTruncated")}`;

        state.textContent = `· ${text}`;
        state.classList.toggle("text-warning", "text" !== item.status && "images" !== item.status);
    }

    #notice(text) {
        const turn = this.answerTemplateTarget.content.firstElementChild.cloneNode(true);
        this.logTarget.append(turn);
        this.#fail(turn, text);
    }

    #fail(turn, text) {
        this.#slot(turn, "status").hidden = true;
        const error = this.#slot(turn, "error");
        error.textContent = text;
        error.hidden = false;
        this.#stopPulse(turn);
    }

    #status(turn, text) {
        this.#slot(turn, "status-text").textContent = text;
        this.#slot(turn, "status").hidden = false;
    }

    #scroll() {
        this.scrollerTarget.scrollTop = this.scrollerTarget.scrollHeight;
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
