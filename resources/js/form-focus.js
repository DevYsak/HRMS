/**
 * After a form action (a Livewire method call) comes back with validation
 * errors, move focus to the first invalid field in that component so keyboard
 * and screen-reader users land on what needs fixing. Live field updates (no
 * method call) never steal focus. Input is preserved — Livewire keeps state.
 */
const INVALID_FIELD = [
    'input[data-invalid]', 'textarea[data-invalid]', 'select[data-invalid]',
    '[data-invalid] input', '[data-invalid] textarea', '[data-invalid] select',
    'input[aria-invalid="true"]', 'textarea[aria-invalid="true"]', 'select[aria-invalid="true"]',
].join(', ');

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ component, commit, succeed }) => {
        if (!commit?.calls?.length) return;

        succeed(() => {
            requestAnimationFrame(() => {
                const field = component.el?.querySelector(INVALID_FIELD);
                if (field && !field.contains(document.activeElement)) {
                    field.focus({ preventScroll: false });
                    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            });
        });
    });
});
