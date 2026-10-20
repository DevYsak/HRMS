/**
 * A thin bar at the top of the window while a Livewire update is in flight
 * for longer than a moment (filters, saves, exports) — so a slow request on a
 * page without its own wire:loading markup still shows that work is happening
 * (Phase 5). Navigation keeps Livewire's own progress bar.
 */
const BAR_ID = 'pulse-busy';
const DELAY_MS = 300;

let inFlight = 0;
let timer = null;

function bar() {
    let el = document.getElementById(BAR_ID);
    if (!el) {
        el = document.createElement('div');
        el.id = BAR_ID;
        el.setAttribute('aria-hidden', 'true');
        document.body.appendChild(el);
    }

    return el;
}

function start() {
    inFlight++;
    if (inFlight === 1) {
        timer = setTimeout(() => {
            bar().dataset.active = '';
            document.documentElement.setAttribute('aria-busy', 'true');
        }, DELAY_MS);
    }
}

function finish() {
    inFlight = Math.max(0, inFlight - 1);
    if (inFlight === 0) {
        clearTimeout(timer);
        delete bar().dataset.active;
        document.documentElement.removeAttribute('aria-busy');
    }
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed, fail }) => {
        start();
        succeed(() => finish());
        fail(() => finish());
    });
});
