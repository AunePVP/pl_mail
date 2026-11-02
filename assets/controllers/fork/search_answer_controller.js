import { Controller } from "@hotwired/stimulus";
import { readFrames } from "../../ai/ndjson.js";

/**
 * FORK (FORK.md): the chat model's answer above the search results, written
 * into the box while the model writes it.
 *
 * Reads the NDJSON of ForkSearchInsightController::answerStream — `token`
 * frames with the prose, then `done` or `error` — through the composer's own
 * line reader (assets/ai/ndjson.js). On `done` the frame is loaded again with
 * `after=1`: the server has kept the finished answer by then and draws it
 * with its links and groups, which are only ids until the whole object is in.
 *
 * Leaving the page aborts the fetch, and the server stops the model for a
 * reader who has gone (connection_aborted() there).
 */
export default class extends Controller {
    static targets = ["waiting", "box", "text"];

    static values = { url: String, after: String };

    #controller = null;

    connect() {
        // A snapshot Turbo restored may carry half an answer; this one starts again.
        this.textTarget.textContent = "";
        this.#start();
    }

    disconnect() {
        this.#controller?.abort();
        this.#controller = null;
    }

    async #start() {
        const controller = new AbortController();
        this.#controller = controller;

        const isCurrent = () => this.#controller === controller;

        try {
            const response = await fetch(this.urlValue, {
                headers: { Accept: "application/x-ndjson" },
                signal: controller.signal,
            });

            if (false === isCurrent()) return;

            if (false === response.ok || null === response.body) {
                this.#giveUp();

                return;
            }

            await readFrames(response.body, (frame) => this.#apply(frame), isCurrent);

            // Ended without `done` or `error`: what arrived stays readable.
            if (true === isCurrent()) this.#settle();
        } catch (error) {
            if ("AbortError" !== error?.name && true === isCurrent()) this.#giveUp();
        }
    }

    #apply(frame) {
        if ("token" === frame.type && "string" === typeof frame.text) {
            this.waitingTarget.hidden = true;
            this.boxTarget.hidden = false;
            this.textTarget.textContent += frame.text;

            return;
        }

        if ("done" === frame.type) {
            this.#controller = null;
            this.#reload();

            return;
        }

        if ("error" === frame.type) this.#giveUp();
    }

    /**
     * The frame again, for the finished answer.
     *
     * After its first load has settled, not at once: a lazy frame ignores a
     * new `src` until Turbo has marked that load done, and a `done` that comes
     * straight back (an answer already kept) can beat it — the box then sat
     * there, half written, for good.
     */
    async #reload() {
        const frameElement = this.element.closest("turbo-frame");

        if (null === frameElement) return;

        await frameElement.loaded;

        frameElement.src = this.afterValue;
    }

    /** Nothing to show, or the model failed: the panel goes, as an answer that says nothing does. */
    #giveUp() {
        this.#controller = null;

        if ("" === this.textTarget.textContent.trim()) {
            this.element.remove();

            return;
        }

        this.#settle();
    }

    #settle() {
        this.#controller = null;
        this.waitingTarget.hidden = true;
        this.element.querySelectorAll(".animate-pulse").forEach((icon) => icon.classList.remove("animate-pulse"));
    }
}
