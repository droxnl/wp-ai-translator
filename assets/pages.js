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
    const clearButton = modal.querySelector('.wpait-modal__clear');
    const translateButton = document.getElementById('wpait-translate-selected');
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
            if (item.status) {
                const statusClass = `wpait-status--${item.status.toLowerCase()}`;
                row.classList.add(statusClass);
            }
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
            post_type: wpaitPages.postType,
        }).done((response) => {
            if (response.success) {
                renderQueue(response.data.queue || []);
            }
        });
    };

    const openModal = (postIds) => {
        selectedPostIds = postIds;
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

    if (translateButton) {
        translateButton.addEventListener('click', () => {
            if (!form) {
                return;
            }
            const checked = form.querySelectorAll('input[name="post[]"]:checked');
            const postIds = Array.from(checked).map((checkbox) => checkbox.value);
            if (!postIds.length) {
                window.alert(wpaitPages.emptySelectionText || 'Select at least one page.');
                return;
            }
            openModal(postIds);
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
            post_type: wpaitPages.postType,
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

    if (clearButton) {
        clearButton.addEventListener('click', () => {
            clearButton.disabled = true;
            $.post(wpaitPages.ajaxUrl, {
                action: 'wpait_clear_queue',
                nonce: wpaitPages.nonce,
            }).done((response) => {
                if (response.success) {
                    renderQueue([]);
                    setNotice(wpaitPages.clearedText || 'Translation history cleared.');
                } else {
                    setNotice(response.data && response.data.message ? response.data.message : 'Unable to clear history.', true);
                }
            }).fail(() => {
                setNotice('Unable to clear history.', true);
            }).always(() => {
                clearButton.disabled = false;
            });
        });
    }

    closeButton.addEventListener('click', closeModal);
    cancelButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
})(jQuery);
