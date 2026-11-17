import { Controller } from '@hotwired/stimulus';

const POLL_MS = 2000;

/*
 * Polls GET /speeches/{id}/status every 2 s while a Speech is processing (SPEC §7) and reloads the
 * page whenever the status changes, so the step indicators, the Report or the error are rendered
 * by the server.
 */
export default class extends Controller {
    static values = { url: String, status: String };

    connect() {
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    schedule() {
        this.timer = setTimeout(() => this.poll(), POLL_MS);
    }

    async poll() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.status === 404) {
                window.location.reload();
                return;
            }
            if (response.ok) {
                const { status } = await response.json();
                if (status !== this.statusValue) {
                    window.location.reload();
                    return;
                }
            }
        } catch {
            // A missed poll is fine; try again on the next tick.
        }

        this.schedule();
    }
}
