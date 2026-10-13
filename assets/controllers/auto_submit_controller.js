import { Controller } from '@hotwired/stimulus';

/*
 * Submits a filter form by itself: at once when a select or checkbox changes,
 * and a moment after the user stops typing in a text field.
 *
 *   <form data-controller="auto-submit" data-turbo-frame="results">
 *       <input type="search" name="q">
 *       <select name="status">...</select>
 *       <button data-auto-submit-target="submit">Szukaj</button>
 *   </form>
 *
 * The submit button is only needed without JavaScript, so it is hidden once the controller is running.
 * Pointing the form at a Turbo Frame keeps the form itself on the page, and with it the cursor in the search field.
 */
export default class extends Controller {
    static targets = ['submit'];
    static values = { delay: { type: Number, default: 350 } };

    connect() {
        this.submitTargets.forEach((button) => { button.hidden = true; });
        this.lastQuery = this.query();

        this.onChange = () => this.submit();
        this.onInput = (event) => {
            if (event.target.matches('input[type="search"], input[type="text"]')) {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.submit(), this.delayValue);
            }
        };
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('input', this.onInput);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('input', this.onInput);
    }

    submit() {
        clearTimeout(this.timer);

        // Typing and then leaving the field fires both "input" and "change": send the same filters only once
        const query = this.query();
        if (query === this.lastQuery) {
            return;
        }
        this.lastQuery = query;

        this.element.requestSubmit();
    }

    query() {
        return new URLSearchParams(new FormData(this.element)).toString();
    }
}
