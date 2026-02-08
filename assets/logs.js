(function ($) {
    if (!window.wpaitLogs) {
        return;
    }

    const tableBody = document.getElementById('wpait-logs-body');
    const clearButton = document.getElementById('wpait-clear-history');
    const modal = document.getElementById('wpait-log-modal');
    const modalClose = modal ? modal.querySelector('.wpait-modal__close') : null;
    const modalCancel = modal ? modal.querySelector('.wpait-modal__cancel') : null;
    const modalBody = document.getElementById('wpait-log-body');
    const modalMeta = document.getElementById('wpait-log-meta');

    let poller = null;
    let queueCache = [];
    let activeJobId = null;

    const renderEmptyState = () => {
        tableBody.innerHTML = '';
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.textContent = wpaitLogs.emptyQueueText;
        row.appendChild(cell);
        tableBody.appendChild(row);
    };

    const renderTable = (queue) => {
        tableBody.innerHTML = '';
        if (!queue.length) {
            renderEmptyState();
            return;
        }
        queue.forEach((job) => {
            const row = document.createElement('tr');
            ['post_title', 'language', 'status', 'message'].forEach((key) => {
                const cell = document.createElement('td');
                cell.textContent = job[key] || '';
                row.appendChild(cell);
            });
            const actionCell = document.createElement('td');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-secondary';
            button.textContent = wpaitLogs.viewLabel;
            button.addEventListener('click', () => openModal(job.id));
            actionCell.appendChild(button);
            row.appendChild(actionCell);
            tableBody.appendChild(row);
        });
    };

    const renderModal = (job) => {
        if (!modalBody) {
            return;
        }
        modalBody.innerHTML = '';
        if (modalMeta) {
            modalMeta.textContent = job ? `${job.post_title} • ${job.language}` : '';
        }
        if (!job || !job.log || !job.log.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 3;
            cell.textContent = wpaitLogs.emptyQueueText;
            row.appendChild(cell);
            modalBody.appendChild(row);
            return;
        }
        job.log.forEach((entry) => {
            const row = document.createElement('tr');
            const timestamp = document.createElement('td');
            const status = document.createElement('td');
            const message = document.createElement('td');
            timestamp.textContent = entry.timestamp || '';
            status.textContent = entry.status ? entry.status.toString() : '';
            message.textContent = entry.message || '';
            row.appendChild(timestamp);
            row.appendChild(status);
            row.appendChild(message);
            modalBody.appendChild(row);
        });
    };

    const fetchQueue = () => {
        return $.post(wpaitLogs.ajaxUrl, {
            action: 'wpait_get_queue_logs',
            nonce: wpaitLogs.nonce,
        }).done((response) => {
            if (!response.success) {
                return;
            }
            queueCache = response.data.queue || [];
            renderTable(queueCache);
            if (activeJobId) {
                const job = queueCache.find((item) => item.id === activeJobId);
                renderModal(job);
            }
        });
    };

    const openModal = (jobId) => {
        if (!modal) {
            return;
        }
        activeJobId = jobId;
        const job = queueCache.find((item) => item.id === jobId);
        renderModal(job);
        modal.classList.add('wpait-modal--active');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        if (!modal) {
            return;
        }
        modal.classList.remove('wpait-modal--active');
        modal.setAttribute('aria-hidden', 'true');
        activeJobId = null;
    };

    if (clearButton) {
        clearButton.addEventListener('click', () => {
            clearButton.disabled = true;
            $.post(wpaitLogs.ajaxUrl, {
                action: 'wpait_clear_queue',
                nonce: wpaitLogs.nonce,
            }).done((response) => {
                if (response.success) {
                    queueCache = [];
                    renderEmptyState();
                }
            }).always(() => {
                clearButton.disabled = false;
            });
        });
    }

    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }

    if (modalCancel) {
        modalCancel.addEventListener('click', closeModal);
    }

    if (modal) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    fetchQueue();
    poller = window.setInterval(fetchQueue, 4000);

    window.addEventListener('beforeunload', () => {
        if (poller) {
            window.clearInterval(poller);
        }
    });
})(jQuery);
