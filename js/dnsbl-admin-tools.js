(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getConfig() {
        return window.tornevallDnsblAdminTools || {};
    }

    function getResultsContainer() {
        var config = getConfig();
        if (!config.resultSelector) {
            return null;
        }

        return document.querySelector(config.resultSelector);
    }

    function renderNotice(message, type) {
        var container = getResultsContainer();
        if (!container) {
            return;
        }

        container.innerHTML = '' +
            '<div class="notice notice-' + escapeHtml(type || 'error') + ' inline">' +
            '<p>' + escapeHtml(message) + '</p>' +
            '</div>';
    }

    function renderHtml(html) {
        var container = getResultsContainer();
        if (!container) {
            return;
        }

        container.innerHTML = html;
    }

    async function submitToolForm(form) {
        var config = getConfig();
        if (!config.ajaxUrl || !config.action) {
            return;
        }

        var submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
        var originalLabel = submitButton ? (submitButton.value || submitButton.textContent) : '';
        var formData = new FormData(form);
        formData.set('action', config.action);

        if (submitButton) {
            submitButton.disabled = true;
            if (submitButton.tagName === 'INPUT') {
                submitButton.value = config.loadingText || originalLabel;
            } else {
                submitButton.textContent = config.loadingText || originalLabel;
            }
        }

        renderNotice(config.loadingText || 'Loading…', 'info');

        try {
            var response = await fetch(config.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            var payload = await response.json();
            if (!response.ok || !payload || !payload.success) {
                renderNotice(payload && payload.data && payload.data.message ? payload.data.message : (config.errorText || 'Request failed.'), 'error');
                return;
            }

            renderHtml(payload.data && payload.data.html ? payload.data.html : '');
        } catch (error) {
            renderNotice(error && error.message ? error.message : (config.errorText || 'Request failed.'), 'error');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                if (submitButton.tagName === 'INPUT') {
                    submitButton.value = originalLabel;
                } else {
                    submitButton.textContent = originalLabel;
                }
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('[data-tornevall-dnsbl-tool-form="1"]');
        if (!forms.length) {
            return;
        }

        forms.forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                submitToolForm(form);
            });
        });
    });
})();

