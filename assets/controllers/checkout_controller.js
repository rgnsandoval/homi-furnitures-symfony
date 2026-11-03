import { Controller } from '@hotwired/stimulus';

/* Asks the server for totals. The browser only displays what comes back and never calculates money. */
export default class extends Controller {
    static targets = ['summary', 'errors', 'field'];
    static values = { url: String, csrf: String };

    connect() {
        this.refresh();
    }

    async refresh() {
        const data = new FormData(this.element);
        this.latest = (this.latest ?? 0) + 1;
        const request = this.latest;

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue },
                body: JSON.stringify({
                    address: data.get('address'),
                    shippingMethod: data.get('shippingMethod'),
                    paymentMethod: data.get('paymentMethod'),
                    voucher: data.get('voucher'),
                    assembly: data.has('assembly'),
                }),
            });
            const quote = await response.json();

            // A slower earlier answer must not overwrite a newer one.
            if (request !== this.latest) {
                return;
            }

            this.fieldTargets.forEach((node) => {
                const value = quote[node.dataset.field] ?? '';
                node.textContent = 'money' in node.dataset ? this.peso(value) : value;
            });
            this.showErrors(quote.errors ?? []);
        } catch {
            this.showErrors(['We could not update the totals. Your order total is confirmed when you place the order.']);
        }
    }

    /* Display formatting only, done on the string so the amount never becomes a float. */
    peso(amount) {
        const match = /^(-?)(\d+)\.(\d{2})$/.exec(String(amount));
        if (!match) {
            return amount;
        }
        return `${match[1]}₱${match[2].replace(/\B(?=(\d{3})+$)/g, ',')}.${match[3]}`;
    }

    showErrors(messages) {
        this.errorsTarget.replaceChildren(...messages.map((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            return item;
        }));
    }
}
