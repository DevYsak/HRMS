/**
 * Wide tables: mark a horizontally scrolling table container with
 * data-scroll-left / data-scroll-right while there is more to see on that
 * side, so app.css can fade that edge (Phase 5 — "is there more?" was
 * invisible on phones). Re-checks on scroll, resize and Livewire morphs.
 */
const SCROLLERS = '.overflow-x-auto, .overflow-auto';

function mark(el) {
    if (!el.querySelector('table')) {
        return;
    }

    const more = el.scrollWidth - el.clientWidth;
    el.toggleAttribute('data-scroll-left', more > 2 && el.scrollLeft > 2);
    el.toggleAttribute('data-scroll-right', more > 2 && el.scrollLeft < more - 2);
}

const resizes = new ResizeObserver((entries) => entries.forEach((entry) => mark(entry.target)));
const watched = new WeakSet();

function scan(root = document) {
    root.querySelectorAll(SCROLLERS).forEach((el) => {
        if (!watched.has(el)) {
            watched.add(el);
            el.addEventListener('scroll', () => mark(el), { passive: true });
            resizes.observe(el);
        }
        mark(el);
    });
}

let queued = false;
function scanSoon() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
        queued = false;
        scan();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    scan();
    new MutationObserver(scanSoon).observe(document.body, { childList: true, subtree: true });
});
document.addEventListener('livewire:navigated', scanSoon);
