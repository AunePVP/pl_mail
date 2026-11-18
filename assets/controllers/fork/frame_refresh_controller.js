import { Controller } from "@hotwired/stimulus";

/**
 * FORK (FORK.md 30): reload the Turbo Frame this element stands in after a
 * few seconds — the Projektakte section under Admin → Integrations, while
 * mails are waiting to be sent, so the count and the progress move on their
 * own. The server leaves this controller out once nothing waits, which ends
 * the loop; leaving the page disconnects it.
 */
export default class extends Controller {
    static values = { seconds: { type: Number, default: 5 } };

    connect() {
        this.timer = setTimeout(() => {
            const frame = this.element.closest("turbo-frame");

            if (frame !== null && document.visibilityState !== "hidden") {
                frame.reload();
            } else if (frame !== null) {
                // In a background tab: try again once it is looked at.
                document.addEventListener("visibilitychange", () => frame.reload(), { once: true });
            }
        }, this.secondsValue * 1000);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
