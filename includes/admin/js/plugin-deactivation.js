(function () {
    'use strict';

    const config = window.UltraCachePluginDeactivation || null;
    if (!config || !config.pluginBasename || !Array.isArray(config.options)) {
        return;
    }

    let modal = null;
    let activeLink = null;
    let busy = false;
    let previousFocus = null;

    function normalizeTheme(value) {
        return String(value || '').toLowerCase() === 'ultracache' ? 'ultracache' : 'native';
    }

    function getDeactivateLink(target) {
        const link = target && target.closest ? target.closest('a') : null;
        if (!link) {
            return null;
        }

        const row = link.closest('tr[data-plugin]');
        if (!row || row.getAttribute('data-plugin') !== config.pluginBasename) {
            return null;
        }

        const action = link.closest('.deactivate, .network_deactivate');
        return action ? link : null;
    }

    function createElement(tag, attributes, text) {
        const element = document.createElement(tag);
        Object.keys(attributes || {}).forEach(function (name) {
            if ('className' === name) {
                element.className = attributes[name];
            } else {
                element.setAttribute(name, attributes[name]);
            }
        });
        if (undefined !== text && null !== text) {
            element.textContent = String(text);
        }
        return element;
    }

    function getSelectedPolicy() {
        if (!modal) {
            return config.currentPolicy || 'delete_everything';
        }

        const checked = modal.querySelector('input[name="ultracache-uninstall-policy"]:checked');
        return checked ? checked.value : (config.currentPolicy || 'delete_everything');
    }

    function setStatus(message, isError) {
        if (!modal) {
            return;
        }

        const status = modal.querySelector('.ultracache-deactivation-status');
        status.textContent = message || '';
        status.classList.toggle('is-error', Boolean(isError));
    }

    function setBusy(nextBusy) {
        busy = Boolean(nextBusy);
        if (!modal) {
            return;
        }

        const confirmButton = modal.querySelector('.ultracache-deactivation-confirm');
        const cancelButton = modal.querySelector('.ultracache-deactivation-cancel');
        const inputs = modal.querySelectorAll('input[name="ultracache-uninstall-policy"]');

        confirmButton.disabled = busy;
        cancelButton.disabled = busy;
        confirmButton.textContent = busy ? config.savingLabel : config.confirmLabel;
        inputs.forEach(function (input) {
            input.disabled = busy;
        });
    }

    function closeModal() {
        if (!modal || busy) {
            return;
        }

        modal.remove();
        modal = null;
        activeLink = null;
        document.body.classList.remove('ultracache-deactivation-modal-open');

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
        previousFocus = null;
    }

    function buildModal() {
        const overlay = createElement('div', {
            className: 'ultracache-deactivation-overlay',
            role: 'presentation',
            'data-uc-theme': normalizeTheme(config.adminTheme)
        });
        const dialog = createElement('div', {
            className: 'ultracache-deactivation-dialog',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'ultracache-deactivation-title',
            'aria-describedby': 'ultracache-deactivation-intro'
        });
        const title = createElement('h2', {
            id: 'ultracache-deactivation-title',
            className: 'ultracache-deactivation-title'
        }, config.title);
        const intro = createElement('p', {
            id: 'ultracache-deactivation-intro',
            className: 'ultracache-deactivation-intro'
        }, config.intro);
        const choices = createElement('fieldset', {
            className: 'ultracache-deactivation-options'
        });
        const legend = createElement('legend', {
            className: 'screen-reader-text'
        }, config.title);

        choices.appendChild(legend);
        config.options.forEach(function (option, index) {
            const id = 'ultracache-uninstall-policy-' + index;
            const label = createElement('label', {
                className: 'ultracache-deactivation-option',
                for: id
            });
            const input = createElement('input', {
                id: id,
                type: 'radio',
                name: 'ultracache-uninstall-policy',
                value: option.value
            });
            if (option.value === (config.currentPolicy || 'delete_everything')) {
                input.checked = true;
            }

            const copy = createElement('span', { className: 'ultracache-deactivation-option-copy' });
            copy.appendChild(createElement('span', {
                className: 'ultracache-deactivation-option-label'
            }, option.label));
            copy.appendChild(createElement('span', {
                className: 'ultracache-deactivation-option-description'
            }, option.description));

            label.appendChild(input);
            label.appendChild(copy);
            choices.appendChild(label);
        });

        const status = createElement('p', {
            className: 'ultracache-deactivation-status',
            role: 'status',
            'aria-live': 'polite'
        });
        const actions = createElement('div', {
            className: 'ultracache-deactivation-actions'
        });
        const cancelButton = createElement('button', {
            type: 'button',
            className: 'button ultracache-deactivation-cancel'
        }, config.cancelLabel);
        const confirmButton = createElement('button', {
            type: 'button',
            className: 'button button-primary ultracache-deactivation-confirm'
        }, config.confirmLabel);

        cancelButton.addEventListener('click', closeModal);
        confirmButton.addEventListener('click', savePolicyAndDeactivate);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeModal();
            }
        });

        actions.appendChild(cancelButton);
        actions.appendChild(confirmButton);
        dialog.appendChild(title);
        dialog.appendChild(intro);
        dialog.appendChild(choices);
        dialog.appendChild(status);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);

        return overlay;
    }

    async function savePolicyAndDeactivate() {
        if (busy || !activeLink) {
            return;
        }

        setStatus('', false);
        setBusy(true);

        const body = new URLSearchParams();
        body.set('action', 'ultracache_save_uninstall_cleanup_policy');
        body.set('nonce', config.nonce || '');
        body.set('policy', getSelectedPolicy());

        try {
            const response = await window.fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            });
            const payload = await response.json();

            if (!response.ok || !payload || !payload.success) {
                const message = payload && payload.data && payload.data.message
                    ? payload.data.message
                    : config.errorLabel;
                throw new Error(message);
            }

            window.location.assign(activeLink.href);
        } catch (error) {
            setBusy(false);
            setStatus(error && error.message ? error.message : config.errorLabel, true);
        }
    }

    function openModal(link) {
        if (modal || busy) {
            return;
        }

        activeLink = link;
        previousFocus = document.activeElement;
        modal = buildModal();
        document.body.appendChild(modal);
        document.body.classList.add('ultracache-deactivation-modal-open');

        const selected = modal.querySelector('input[name="ultracache-uninstall-policy"]:checked');
        const firstFocusable = selected || modal.querySelector('button, input');
        if (firstFocusable) {
            firstFocusable.focus();
        }
    }

    document.addEventListener('click', function (event) {
        const link = getDeactivateLink(event.target);
        if (!link) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openModal(link);
    }, true);

    document.addEventListener('keydown', function (event) {
        if (!modal) {
            return;
        }

        if ('Escape' === event.key) {
            event.preventDefault();
            closeModal();
            return;
        }

        if ('Tab' !== event.key) {
            return;
        }

        const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled])'));
        if (!focusable.length) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}());
