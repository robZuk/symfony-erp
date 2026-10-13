import { Controller } from '@hotwired/stimulus';

/*
 * Switches between the light and the dark colour theme and remembers the choice.
 * The theme is the "dark" class on <html>; base.html.twig applies the saved one on page load.
 *
 *   <button data-controller="theme" data-action="theme#toggle">...</button>
 */
export default class extends Controller {
    toggle() {
        const dark = document.documentElement.classList.toggle('dark');

        try {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
        } catch (e) {
            // Storage can be unavailable (private mode); the theme then lasts until the next full page load
        }
    }
}
