import { Controller } from '@hotwired/stimulus';

/* The product quick view and the mobile menu, both native dialogs in the base layout. */
export default class extends Controller {
    static targets = ['dialog', 'menu', 'image', 'type', 'name', 'price', 'old', 'description', 'colorBlock', 'colors', 'form', 'variant', 'link'];

    open(event) {
        const product = JSON.parse(event.currentTarget.dataset.quickViewPayload);

        this.imageTarget.src = product.image;
        this.imageTarget.alt = product.name;
        this.typeTarget.textContent = product.type;
        this.nameTarget.textContent = product.name;
        this.priceTarget.textContent = product.price;
        this.oldTarget.textContent = product.old;
        this.descriptionTarget.textContent = product.description;
        this.linkTarget.href = product.url;

        this.colorBlockTarget.hidden = product.colors.length === 0;
        this.colorsTarget.replaceChildren(...product.colors.map((color) => {
            const swatch = document.createElement('span');
            swatch.className = 'size-9 rounded-full border-2 border-white ring-1 ring-stone-300';
            swatch.style.background = color.hex;
            swatch.title = color.name;
            return swatch;
        }));

        // Products with several options are chosen on the product page.
        if (this.hasFormTarget) {
            this.formTarget.hidden = product.variant === null;
            this.variantTarget.value = product.variant ?? '';
        }

        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    openMenu() {
        this.menuTarget.showModal();
    }

    closeMenu() {
        this.menuTarget.close();
    }

    /* Turbo would otherwise cache the page with a dialog still open. */
    disconnect() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        if (this.hasMenuTarget && this.menuTarget.open) {
            this.menuTarget.close();
        }
    }
}
