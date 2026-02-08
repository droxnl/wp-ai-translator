(function ($) {
    const modal = document.getElementById('wpait-translate-modal');
    if (!modal || !window.wpaitPages) {
        return;
    }

    const form = document.getElementById('posts-filter');
    const languageContainer = document.getElementById('wpait-language-options');
    const queueBody = document.getElementById('wpait-queue-body');
    const notice = document.getElementById('wpait-modal-notice');
    const closeButton = modal.querySelector('.wpait-modal__close');
    const cancelButton = modal.querySelector('.wpait-modal__cancel');
    const confirmButton = modal.querySelector('.wpait-modal__confirm');
    let selectedPostIds = [];
    let poller = null;

    const buildLanguageOptions = () => {
        if (languageContainer.childElementCount) {
            return;
        }
        const fragment = document.createDocumentFragment();
        wpaitPages.languages.forEach((language) => {
            const label = document.createElement('label');
            label.className = 'wpait-modal__language';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'wpait_languages[]';
            checkbox.value = language.code;
            checkbox.checked = true;
            const text = document.createElement('span');
            text.textContent = `${language.label} (${language.code.toUpperCase()})`;
            label.appendChild(checkbox);
            label.appendChild(text);
            fragment.appendChild(label);
        });
        languageContainer.appendChild(fragment);
    };

    const renderQueue = (items) => {
        queueBody.innerHTML = '';
        if (!items.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.textContent = wpaitPages.emptyQueueText;
            row.appendChild(cell);
            queueBody.appendChild(row);
            return;
        }
        items.forEach((item) => {
            const row = document.createElement('tr');
            ['post_title', 'language', 'status', 'message'].forEach((key) => {
                const cell = document.createElement('td');
                cell.textContent = item[key] || '';
                row.appendChild(cell);
            });
            queueBody.appendChild(row);
        });
    };

    const setNotice = (message, isError) => {
        notice.textContent = message || '';
        notice.classList.toggle('wpait-modal__notice--error', Boolean(isError));
    };

    const fetchQueue = () => {
        return $.post(wpaitPages.ajaxUrl, {
            action: 'wpait_get_queue',
            nonce: wpaitPages.nonce,
        }).done((response) => {
            if (response.success) {
                renderQueue(response.data.queue || []);
            }
        });
    };

    const openModal = () => {
        buildLanguageOptions();
        modal.classList.add('wpait-modal--active');
        modal.setAttribute('aria-hidden', 'false');
        setNotice('');
        fetchQueue();
        poller = window.setInterval(fetchQueue, 4000);
    };

    const closeModal = () => {
        modal.classList.remove('wpait-modal--active');
        modal.setAttribute('aria-hidden', 'true');
        if (poller) {
            window.clearInterval(poller);
            poller = null;
        }
    };

    const getSelectedLanguages = () => {
        const checkboxes = languageContainer.querySelectorAll('input[type="checkbox"]');
        return Array.from(checkboxes)
            .filter((checkbox) => checkbox.checked)
            .map((checkbox) => checkbox.value);
    };

    const getBulkActionValue = () => {
        const actionTop = document.getElementById('bulk-action-selector-top');
        const actionBottom = document.getElementById('bulk-action-selector-bottom');
        const action = actionTop && actionTop.value !== '-1' ? actionTop.value : (actionBottom ? actionBottom.value : '-1');
        return action;
    };

    if (form) {
        form.addEventListener('submit', (event) => {
            const action = getBulkActionValue();
            if (action !== 'wpait_translate') {
                return;
            }
            event.preventDefault();
            const checked = form.querySelectorAll('input[name="post[]"]:checked');
            selectedPostIds = Array.from(checked).map((checkbox) => checkbox.value);
            if (!selectedPostIds.length) {
                window.alert(wpaitPages.emptySelectionText || 'Select at least one page.');
                return;
            }
            openModal();
        });
    }

    confirmButton.addEventListener('click', () => {
        const languages = getSelectedLanguages();
        if (!languages.length) {
            setNotice('Select at least one language.', true);
            return;
        }
        confirmButton.disabled = true;
        $.post(wpaitPages.ajaxUrl, {
            action: 'wpait_enqueue_translations',
            nonce: wpaitPages.nonce,
            post_ids: selectedPostIds,
            languages: languages,
        }).done((response) => {
            if (response.success) {
                const queued = response.data.queued || 0;
                setNotice(`${queued} translation job(s) queued.`);
                renderQueue(response.data.queue || []);
            } else {
                setNotice(response.data && response.data.message ? response.data.message : 'Unable to queue translations.', true);
            }
        }).fail(() => {
            setNotice('Unable to queue translations.', true);
        }).always(() => {
            confirmButton.disabled = false;
        });
    });

    closeButton.addEventListener('click', closeModal);
    cancelButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
})(jQuery);
