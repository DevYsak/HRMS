/**
 * Friendly handling of failed Livewire requests.
 *
 *  419 (session expired): Livewire's default is a browser confirm() and a
 *       reload that loses what was typed. Instead show a banner that says what
 *       happened and lets the user sign in again when they are ready.
 *  403 (not permitted):   show a short message instead of the full error
 *       page inside a modal; the action simply did not happen.
 *
 * Everything else (e.g. 500) keeps Livewire's default, which shows the
 * friendly error page — never a stack trace in production.
 */
const BANNER_ID = 'pulse-request-error';

function showBanner(message, actionLabel, onAction) {
    document.getElementById(BANNER_ID)?.remove();

    const banner = document.createElement('div');
    banner.id = BANNER_ID;
    banner.setAttribute('role', 'alert');
    banner.className = 'fixed inset-x-4 bottom-4 z-[100] mx-auto flex max-w-lg items-center gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-lg dark:border-amber-500/40 dark:bg-zinc-900 dark:text-amber-200';

    const text = document.createElement('span');
    text.className = 'min-w-0 flex-1';
    text.textContent = message;
    banner.appendChild(text);

    if (actionLabel) {
        const action = document.createElement('button');
        action.type = 'button';
        action.className = 'shrink-0 rounded-xl bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600';
        action.textContent = actionLabel;
        action.addEventListener('click', onAction);
        banner.appendChild(action);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.className = 'shrink-0 text-amber-700 hover:text-amber-900 dark:text-amber-300';
    close.textContent = '✕';
    close.addEventListener('click', () => banner.remove());
    banner.appendChild(close);

    document.body.appendChild(banner);
}

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response?.status === 419) {
                preventDefault();
                showBanner(
                    'Your session expired, so that last action was not saved. Sign in again to continue.',
                    'Sign in again',
                    () => window.location.reload(),
                );
            }

            if (response?.status === 403) {
                preventDefault();
                showBanner("You don't have permission to do that. Nothing was changed.");
            }
        });
    });
});
