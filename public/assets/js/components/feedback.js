import { el, setChildren } from '../utils/dom.js';

const TOAST_REGION_ID = 'toast-region';
const DEFAULT_TOAST_DURATION = 4000;
const buttonStates = new WeakMap();

function safeTone(value) {
    const tone = String(value || 'info');
    return /^[A-Za-z0-9_-]+$/.test(tone) ? tone : 'info';
}

function safeDuration(value) {
    const duration = Number(value);
    return Number.isFinite(duration) && duration >= 0 ? duration : DEFAULT_TOAST_DURATION;
}

export function showToast(message, tone = 'info', duration = DEFAULT_TOAST_DURATION) {
    if (typeof document === 'undefined' || message === null || message === undefined) {
        return null;
    }

    const region = document.getElementById(TOAST_REGION_ID);
    if (region === null) {
        return null;
    }

    const normalizedTone = safeTone(tone);
    const assertive = normalizedTone === 'error' || normalizedTone === 'danger';
    const toast = el('div', {
        className: `toast toast-${normalizedTone}`,
        dataset: { tone: normalizedTone },
        attributes: {
            role: assertive ? 'alert' : 'status',
            'aria-atomic': 'true',
        },
    }, String(message));

    region.appendChild(toast);
    const timeout = safeDuration(duration);
    if (timeout > 0) {
        setTimeout(() => {
            if (toast.isConnected) {
                toast.remove();
            }
        }, timeout);
    }

    return toast;
}

export function setButtonBusy(button, busy, busyLabel = 'Chargement…') {
    if (
        button === null
        || typeof button !== 'object'
        || typeof button.setAttribute !== 'function'
        || typeof button.childNodes === 'undefined'
    ) {
        throw new TypeError('Un bouton DOM est requis.');
    }

    if (busy) {
        let state = buttonStates.get(button);
        if (!state) {
            state = {
                children: Array.from(button.childNodes),
                disabled: Boolean(button.disabled),
                ariaBusy: button.getAttribute('aria-busy'),
                busyValue: button.dataset.busy,
            };
            buttonStates.set(button, state);
        }

        state.label = busyLabel === null || busyLabel === undefined
            ? 'Chargement…'
            : String(busyLabel);
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.dataset.busy = 'true';
        setChildren(button, [
            el('span', { className: 'spinner', attributes: { 'aria-hidden': 'true' } }),
            el('span', { className: 'button-label' }, state.label),
        ]);
        return button;
    }

    const state = buttonStates.get(button);
    if (!state) {
        return button;
    }

    buttonStates.delete(button);
    button.disabled = state.disabled;
    if (state.ariaBusy === null) {
        button.removeAttribute('aria-busy');
    } else {
        button.setAttribute('aria-busy', state.ariaBusy);
    }
    if (state.busyValue === undefined) {
        delete button.dataset.busy;
    } else {
        button.dataset.busy = state.busyValue;
    }
    setChildren(button, state.children);
    return button;
}

export function isAbortError(error) {
    return error !== null
        && typeof error === 'object'
        && (error.name === 'AbortError' || error.code === 20);
}
