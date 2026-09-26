import { button, el, setChildren } from '../utils/dom.js';

const DIALOG_ID = 'app-dialog';
const CONTENT_ID = 'dialog-content';
const TITLE_ID = 'dialog-title';
const DESCRIPTION_ID = 'dialog-description';
const FOCUSABLE_SELECTOR = [
    'a[href]',
    'area[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[contenteditable="true"]',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

let dialogElement = null;
let dialogContent = null;
let previousFocus = null;
let activeOnClose = null;

function connected(element) {
    return element !== null && element.isConnected !== false;
}

function focusElement(element) {
    if (!connected(element) || typeof element.focus !== 'function') {
        return;
    }

    try {
        element.focus({ preventScroll: true });
    } catch {
        element.focus();
    }
}

function getDialog() {
    if (typeof document === 'undefined') {
        throw new Error('DOM indisponible.');
    }

    if (connected(dialogElement)) {
        return dialogElement;
    }

    const existing = document.getElementById(DIALOG_ID);
    if (existing !== null && String(existing.localName).toLowerCase() !== 'dialog') {
        throw new TypeError('#app-dialog doit être un élément dialog.');
    }

    dialogElement = existing || document.createElement('dialog');
    if (!connected(dialogElement)) {
        document.body.appendChild(dialogElement);
    }

    const existingContent = document.getElementById(CONTENT_ID);
    dialogContent = existingContent || document.createElement('div');
    if (!dialogContent.id) {
        dialogContent.id = CONTENT_ID;
    }

    dialogElement.addEventListener('cancel', (event) => {
        if (!dialogElement.open) {
            return;
        }

        event.preventDefault();
        closeDialog();
    });

    dialogElement.addEventListener('close', () => {
        if (!dialogElement.open) {
            completeClose();
        }
    });

    dialogElement.addEventListener('click', (event) => {
        if (!dialogElement.open || event.target !== dialogElement) {
            return;
        }

        if (typeof dialogElement.getBoundingClientRect !== 'function') {
            closeDialog();
            return;
        }

        const rectangle = dialogElement.getBoundingClientRect();
        const outside = event.clientX < rectangle.left
            || event.clientX > rectangle.right
            || event.clientY < rectangle.top
            || event.clientY > rectangle.bottom;
        if (outside) {
            closeDialog();
        }
    });

    return dialogElement;
}

function focusInitialElement(dialog) {
    const preferred = dialogContent.querySelector('[autofocus]');
    if (preferred && typeof preferred.focus === 'function') {
        focusElement(preferred);
        return;
    }

    const candidates = Array.from(dialogContent.querySelectorAll(FOCUSABLE_SELECTOR));
    const initial = candidates.find((candidate) => !candidate.disabled
        && candidate.getAttribute('aria-hidden') !== 'true');
    if (initial) {
        focusElement(initial);
        return;
    }

    const closeButton = dialog.querySelector('[data-dialog-close]');
    if (closeButton) {
        focusElement(closeButton);
        return;
    }

    if (!dialog.hasAttribute('tabindex')) {
        dialog.setAttribute('tabindex', '-1');
    }
    focusElement(dialog);
}

function completeClose() {
    const callback = activeOnClose;
    const focusTarget = previousFocus;
    activeOnClose = null;
    previousFocus = null;
    focusElement(focusTarget);
    if (typeof callback === 'function') {
        try {
            callback();
        } catch {
        }
    }
}

function resolveContent(content) {
    if (typeof content === 'function') {
        try {
            return content();
        } catch {
            return el('p', { className: 'form-error', attributes: { role: 'alert' } }, 'Ce contenu ne peut pas être affiché.');
        }
    }

    return content;
}

export function openDialog({ title, description = '', content = null, onClose = null, variant = '' } = {}) {
    const dialog = getDialog();
    const normalizedTitle = String(title || '').trim();
    if (normalizedTitle === '') {
        throw new TypeError('Un titre de dialogue est requis.');
    }

    if (dialog.open) {
        closeDialog();
    }
    dialog.classList.toggle('app-dialog-image', variant === 'image');

    previousFocus = document.activeElement === document.body
        ? null
        : document.activeElement;
    activeOnClose = typeof onClose === 'function' ? onClose : null;

    const titleNode = el('h2', {
        attributes: { id: TITLE_ID },
    }, normalizedTitle);
    const headingChildren = [titleNode];
    const normalizedDescription = description === null || description === undefined
        ? ''
        : String(description);
    let descriptionNode = null;

    if (normalizedDescription !== '') {
        descriptionNode = el('p', {
            className: 'dialog-description',
            attributes: { id: DESCRIPTION_ID },
        }, normalizedDescription);
        headingChildren.push(descriptionNode);
    }

    const closeButton = button('', {
        className: 'icon-button dialog-close',
        icon: 'x',
        accessibleName: 'Fermer',
        attributes: { 'data-dialog-close': 'true' },
        events: {
            click: () => closeDialog(),
        },
    });
    const header = el('header', { className: 'dialog-header' }, [
        el('div', { className: 'dialog-heading' }, headingChildren),
        closeButton,
    ]);

    dialogContent.className = 'dialog-content';
    setChildren(dialogContent, resolveContent(content));
    setChildren(dialog, [header, dialogContent]);
    dialog.setAttribute('aria-labelledby', TITLE_ID);
    if (descriptionNode === null) {
        dialog.removeAttribute('aria-describedby');
    } else {
        dialog.setAttribute('aria-describedby', DESCRIPTION_ID);
    }

    try {
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else if (typeof dialog.show === 'function') {
            dialog.show();
        } else {
            dialog.setAttribute('open', '');
        }
    } catch {
        if (typeof dialog.show === 'function') {
            dialog.show();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    if (!dialog.open) {
        dialog.setAttribute('open', '');
    }
    focusInitialElement(dialog);
    return dialog;
}

export function closeDialog() {
    if (typeof document === 'undefined') {
        return false;
    }

    const dialog = document.getElementById(DIALOG_ID);
    if (dialog === null || !dialog.open) {
        activeOnClose = null;
        previousFocus = null;
        return false;
    }

    if (typeof dialog.close === 'function') {
        dialog.close();
    } else {
        dialog.removeAttribute('open');
    }
    completeClose();
    return true;
}
