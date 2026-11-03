import { Controller } from '@hotwired/stimulus';

/*
 * Shop filters: the panel starts open on wide screens, where there is room for it,
 * and the sort menu applies as soon as a new order is picked.
 */
export default class extends Controller {
    static targets = ['panel'];

    connect() {
        if (this.hasPanelTarget && window.matchMedia('(min-width: 1024px)').matches) {
            this.panelTarget.open = true;
        }
    }

    submit(event) {
        event.target.form.requestSubmit();
    }
}
