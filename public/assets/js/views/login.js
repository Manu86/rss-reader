import { isAbortError, setButtonBusy } from '../components/feedback.js';
import { errorMessage } from '../utils/dom.js';

function elementById(root, id) {
    if (root && typeof root.getElementById === 'function') {
        return root.getElementById(id);
    }

    return root ? root.querySelector(`[id="${id}"]`) : null;
}

function requiredElement(root, id) {
    const element = elementById(root, id);
    if (element === null) {
        throw new Error(`Élément #${id} introuvable.`);
    }

    return element;
}

function isFormValid(form, controls) {
    if (typeof form.reportValidity === 'function') {
        return form.reportValidity();
    }

    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
        return false;
    }

    return controls.every((control) => typeof control.checkValidity !== 'function' || control.checkValidity());
}

export function createLoginView(options = {}) {
    const {
        onLogin,
        message,
        sessionMessage,
        root = document,
    } = options;

    if (typeof onLogin !== 'function') {
        throw new TypeError('La fonction de connexion est requise.');
    }

    const view = requiredElement(root, 'login-view');
    const form = requiredElement(root, 'login-form');
    const username = requiredElement(root, 'login-username');
    const password = requiredElement(root, 'login-password');
    const remember = requiredElement(root, 'login-remember');
    const submit = requiredElement(root, 'login-submit');
    const error = requiredElement(root, 'login-error');
    const notice = requiredElement(root, 'login-notice');
    let busy = false;
    let destroyed = false;

    const clearError = () => {
        error.textContent = '';
        error.hidden = true;
        username.removeAttribute('aria-invalid');
        password.removeAttribute('aria-invalid');
        username.removeAttribute('aria-describedby');
        password.removeAttribute('aria-describedby');
    };

    const showError = (failure) => {
        error.textContent = errorMessage(failure);
        error.hidden = false;
        username.setAttribute('aria-invalid', 'true');
        password.setAttribute('aria-invalid', 'true');
        username.setAttribute('aria-describedby', 'login-error');
        password.setAttribute('aria-describedby', 'login-error');
    };

    const setMessage = (value) => {
        const text = value === null || value === undefined ? '' : String(value);
        notice.textContent = text;
        notice.hidden = text === '';
    };

    const focusUsername = () => {
        if (destroyed || view.isConnected === false) {
            return;
        }

        try {
            username.focus({ preventScroll: true });
        } catch {
            username.focus();
        }
    };

    const setBusy = (value) => {
        busy = value;
        username.disabled = value;
        password.disabled = value;
        remember.disabled = value;
        setButtonBusy(submit, value, 'Connexion…');
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        if (busy || destroyed || !isFormValid(form, [username, password])) {
            return;
        }

        clearError();
        setBusy(true);
        let succeeded = false;

        try {
            await onLogin({
                username: username.value,
                password: password.value,
                remember: remember.checked,
            });
            password.value = '';
            succeeded = true;
        } catch (failure) {
            if (!isAbortError(failure)) {
                showError(failure);
            }
        } finally {
            if (!destroyed) {
                setBusy(false);
            }
        }

        if (succeeded && !destroyed) {
            focusUsername();
        }
    };

    form.addEventListener('submit', handleSubmit);
    form.addEventListener('input', clearError);

    if (Object.prototype.hasOwnProperty.call(options, 'message')) {
        setMessage(message);
    } else if (Object.prototype.hasOwnProperty.call(options, 'sessionMessage')) {
        setMessage(sessionMessage);
    }

    focusUsername();

    return Object.freeze({
        element: view,
        form,
        focus: focusUsername,
        setMessage,
        destroy: () => {
            if (destroyed) {
                return;
            }

            if (busy) {
                setBusy(false);
            }
            destroyed = true;
            form.removeEventListener('submit', handleSubmit);
            form.removeEventListener('input', clearError);
        },
    });
}
