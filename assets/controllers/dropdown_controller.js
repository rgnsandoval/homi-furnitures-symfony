import { Controller } from '@hotwired/stimulus';

/* Closes a details menu on an outside click. */
export default class extends Controller {
    connect() {
        this.outside = (event) => {
            if (!this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        document.addEventListener('click', this.outside);
    }

    disconnect() {
        document.removeEventListener('click', this.outside);
    }
}
