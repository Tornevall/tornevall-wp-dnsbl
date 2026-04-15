(function () {
    function toBool(value) {
        if (typeof value === 'boolean') {
            return value;
        }
        var normalized = String(value || '').toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on';
    }

    function setResult(node, ok, message) {
        if (!node) {
            return;
        }

        node.style.display = 'block';
        node.style.background = ok ? '#d1fae5' : '#fee2e2';
        node.style.color = ok ? '#065f46' : '#991b1b';
        node.style.borderColor = ok ? '#6ee7b7' : '#fca5a5';
        node.style.whiteSpace = 'pre-line';
        node.textContent = message;
    }

    function bindForm(form) {
        var submitBtn = form.querySelector('[data-removal-submit]');
        var delistBtn = form.querySelector('[data-removal-delist]');
        var submitBtnText = submitBtn ? submitBtn.textContent : 'Submit request';
        var delistBtnText = delistBtn ? delistBtn.textContent : 'Delist';
        var resultNode = form.querySelector('[data-removal-result]');
        var opSelect = form.querySelector('[data-removal-operation]');
        var oldWrap = form.querySelector('[data-removal-old-bitmask-wrap]');
        var oldInput = form.querySelector('input[name="old_bitmask"]');
        var ipInput = form.querySelector('input[name="ip"]');
        var cidrInput = form.querySelector('input[name="cidr_range"]');
        var advancedWrap = form.querySelector('[data-removal-advanced]');
        var advancedToggleBtn = form.querySelector('[data-removal-advanced-toggle]');
        var turnstileWrap = form.querySelector('[data-removal-turnstile-wrap]');
        var cloudflareTokenInput = form.querySelector('input[name="cf_turnstile_token"]');
        var checkerMode = toBool(form.getAttribute('data-checker-mode'));
        var canUseAdvancedCidr = toBool(form.getAttribute('data-can-cidr-delete'));
        var checkerConfirmed = false;
        var checkerIp = '';
        var checkerListed = false;
        var checkerBaseMessage = '';
        var checkerBackgroundPending = false;
        var checkerDeleteCandidates = [];
        var turnstileActivated = false;
        var checkerPrefillIp = ipInput ? String(ipInput.getAttribute('data-prefill-ip') || '') : '';
        var checkerPrefillCleared = false;
        var advancedEnabled = false;

        function normalizeStatusMessage(responseStatus, message, config) {
            if (responseStatus === 419) {
                // 419 from the WordPress AJAX endpoint = WordPress CSRF expired.
                // Any 419 from a remote (Tools) API is already mapped to 502 by the
                // PHP handler before it reaches here, so this branch truly means the
                // WP session is stale and the page should be refreshed.
                return String(config.csrfExpiredText || 'Security session expired (HTTP 419). Please refresh this page and try again.');
            }

            return String(message || '');
        }

        function clearCheckerDeleteCandidates() {
            checkerDeleteCandidates = [];
            delete form.dataset.checkerDeleteCandidates;
        }

        function persistCheckerDeleteCandidates(candidates) {
            checkerDeleteCandidates = Array.isArray(candidates) ? candidates : [];
            if (checkerDeleteCandidates.length) {
                form.dataset.checkerDeleteCandidates = JSON.stringify(checkerDeleteCandidates);
            } else {
                delete form.dataset.checkerDeleteCandidates;
            }
        }

        function buildCombinedMessage(baseMessage, followUpMessage) {
            var parts = [];
            if (String(baseMessage || '').trim() !== '') {
                parts.push(String(baseMessage || '').trim());
            }
            if (String(followUpMessage || '').trim() !== '') {
                parts.push(String(followUpMessage || '').trim());
            }

            return parts.join('\n');
        }

        function setFormDataValue(payload, key, value) {
            if (typeof payload.delete === 'function') {
                payload.delete(key);
            }
            payload.append(key, value);
        }

        function setCheckerButtonState() {
            if (!checkerMode || !submitBtn) {
                return;
            }
            var config = window.tornevallDnsblRemovalForm || {};
            submitBtnText = String(config.checkerCheckText || 'Check if listed');
            submitBtn.disabled = checkerConfirmed;
            submitBtn.textContent = submitBtnText;

            if (delistBtn) {
                delistBtn.textContent = delistBtnText;
                var canDelistNow = checkerListed && checkerIp !== '';
                delistBtn.style.display = checkerListed ? 'inline-block' : 'none';
                delistBtn.disabled = !canDelistNow;
                delistBtn.title = (checkerBackgroundPending && canDelistNow)
                    ? 'The Tools follow-up is still running, but this IP is already confirmed listed so you can delist now.'
                    : '';
            }

            if (ipInput) {
                ipInput.disabled = checkerConfirmed;
            }

            if (advancedWrap) {
                var showAdvanced = checkerConfirmed && canUseAdvancedCidr && advancedEnabled;
                advancedWrap.style.display = showAdvanced ? 'block' : 'none';
                if (!showAdvanced && cidrInput) {
                    cidrInput.value = '';
                }
            }

            if (advancedToggleBtn) {
                advancedToggleBtn.style.display = checkerConfirmed && canUseAdvancedCidr ? 'inline-block' : 'none';
                advancedToggleBtn.textContent = advancedEnabled
                    ? 'Advanced - ON'
                    : 'Advanced';
            }

            updateTurnstileState();
        }

        function setSubmissionButtonState(isDeleteSubmission, config) {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = checkerMode
                    ? String(isDeleteSubmission ? submitBtnText : (config.checkingText || 'Checking listing status...'))
                    : String(config.sendingText || 'Submitting request...');
            }

            if (delistBtn) {
                delistBtn.disabled = true;
                delistBtn.textContent = isDeleteSubmission
                    ? String(config.sendingText || 'Submitting request...')
                    : delistBtnText;
            }
        }

        function resetCheckerState() {
            checkerConfirmed = false;
            checkerIp = '';
            checkerListed = false;
            checkerBaseMessage = '';
            checkerBackgroundPending = false;
            clearCheckerDeleteCandidates();
            setCheckerButtonState();
        }

        function syncOperationFields() {
            if (!opSelect || !oldWrap || !oldInput) {
                return;
            }
            var update = String(opSelect.value || '').toLowerCase() === 'update';
            oldWrap.style.display = update ? 'block' : 'none';
            oldInput.required = update;
        }

        syncOperationFields();
        if (opSelect) {
            opSelect.addEventListener('change', syncOperationFields);
        }

        if (checkerMode && ipInput) {
            ipInput.addEventListener('input', function () {
                advancedEnabled = false;
                resetCheckerState();
            });

            var clearPrefilledIpOnFirstFocus = function () {
                if (checkerPrefillCleared || checkerPrefillIp === '') {
                    return;
                }
                if (String(ipInput.value || '').trim() === checkerPrefillIp) {
                    ipInput.value = '';
                    resetCheckerState();
                }
                checkerPrefillCleared = true;
            };

            ipInput.addEventListener('focus', clearPrefilledIpOnFirstFocus);
            ipInput.addEventListener('click', clearPrefilledIpOnFirstFocus);
            ipInput.addEventListener('touchstart', clearPrefilledIpOnFirstFocus, {passive: true});
            ipInput.addEventListener('pointerdown', clearPrefilledIpOnFirstFocus, {passive: true});
            ipInput.addEventListener('keydown', function () {
                clearPrefilledIpOnFirstFocus();
            });
        }

        if (advancedToggleBtn) {
            advancedToggleBtn.addEventListener('click', function (event) {
                event.preventDefault();
                if (!checkerConfirmed || !canUseAdvancedCidr) {
                    return;
                }
                advancedEnabled = !advancedEnabled;
                // When Advanced is opened after a confirmed checker hit,
                // seed CIDR with a safe single-IP range unless the user already set one.
                if (advancedEnabled && cidrInput && String(cidrInput.value || '').trim() === '' && checkerIp !== '') {
                    cidrInput.value = checkerIp + '/32';
                }
                setCheckerButtonState();
            });
        }

        function runBackgroundCheck(currentIp) {
            if (!checkerMode || !currentIp) {
                return Promise.resolve();
            }

            var config = window.tornevallDnsblRemovalForm || {};
            var backgroundPayload = new FormData(form);
            backgroundPayload.append('action', String(config.action || 'tornevall_dnsbl_removal_form_submit'));
            backgroundPayload.append('nonce', String(config.nonce || ''));
            backgroundPayload.append('background_api_check', '1');
            backgroundPayload.append('check_only', '1');
            backgroundPayload.append('confirmed_listed', '0');
            setFormDataValue(backgroundPayload, 'operation', 'check');

            checkerBackgroundPending = true;
            checkerConfirmed = checkerListed;
            setCheckerButtonState();
            setResult(
                resultNode,
                checkerListed,
                buildCombinedMessage(
                    checkerBaseMessage,
                    checkerListed
                        ? String(config.backgroundCheckingText || 'Checking Tools API in the background… You can already click Delist because the DNS lookup confirmed this IP as listed.')
                        : String(config.backgroundCheckingText || 'Checking Tools API in the background…')
                )
            );

            return fetch(String(config.ajaxUrl || ''), {
                method: 'POST',
                body: backgroundPayload,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (json) {
                        return {
                            ok: response.ok,
                            status: response.status,
                            json: json
                        };
                    });
                })
                .then(function (result) {
                    var data = result.json && result.json.data ? result.json.data : {};
                    // Use the actual message from the payload when it's available.
                    // Do not let normalizeStatusMessage replace it with the generic CSRF
                    // text when the HTTP status is 419 – that status means a server-to-
                    // server auth issue on the Tools call, not a WordPress session problem.
                    var rawMessage = String(data.message || (result.ok
                        ? 'Tools API follow-up completed.'
                        : (config.backgroundCheckFailedText || 'The Tools API follow-up could not be completed. The first DNS result is still shown above.')));
                    var followUpMessage = (result.status === 419 && rawMessage !== '')
                        ? rawMessage
                        : normalizeStatusMessage(result.status, rawMessage, config);
                    var candidates = Array.isArray(data.delete_candidates) ? data.delete_candidates : [];
                    var liveIpValue = ipInput ? String(ipInput.value || '').trim() : '';

                    if (liveIpValue !== currentIp) {
                        return;
                    }

                    checkerBackgroundPending = false;
                    checkerListed = checkerListed || !!data.listed;
                    checkerIp = currentIp;
                    if (candidates.length) {
                        persistCheckerDeleteCandidates(candidates);
                    }
                    checkerConfirmed = checkerListed;
                    setCheckerButtonState();

                    setResult(
                        resultNode,
                        checkerListed || !!result.ok,
                        buildCombinedMessage(checkerBaseMessage, followUpMessage)
                    );
                })
                .catch(function () {
                    var liveIpValue = ipInput ? String(ipInput.value || '').trim() : '';
                    if (liveIpValue !== currentIp) {
                        return;
                    }

                    checkerBackgroundPending = false;
                    checkerConfirmed = checkerListed;
                    setCheckerButtonState();
                    setResult(
                        resultNode,
                        checkerListed,
                        buildCombinedMessage(
                            checkerBaseMessage,
                            String(config.backgroundCheckFailedText || 'The Tools API follow-up could not be completed. The first DNS result is still shown above.')
                        )
                    );
                });
        }

        /**
         * Initialize or reset Cloudflare Turnstile CAPTCHA if present
         */
        function ensureTurnstileActivated() {
            if (!cloudflareTokenInput || !window.turnstile) {
                return;
            }
            if (turnstileActivated) {
                return;
            }
            var containerId = cloudflareTokenInput.getAttribute('data-turnstile-container');
            if (containerId) {
                var container = document.getElementById(containerId);
                if (container && container.getAttribute('data-turnstile-rendered') !== '1') {
                    window.turnstile.render('#' + containerId, {
                        sitekey: String(cloudflareTokenInput.getAttribute('data-turnstile-sitekey') || ''),
                        callback: function (token) {
                            cloudflareTokenInput.value = token;
                        },
                        'error-callback': function () {
                            cloudflareTokenInput.value = '';
                        }
                    });
                    container.setAttribute('data-turnstile-rendered', '1');
                }
                turnstileActivated = true;
            }
        }

        function updateTurnstileState() {
            if (!cloudflareTokenInput) {
                return;
            }

            var requiresTurnstile = !checkerMode || checkerListed;
            if (turnstileWrap) {
                turnstileWrap.style.display = requiresTurnstile ? 'block' : 'none';
            }

            if (requiresTurnstile) {
                cloudflareTokenInput.setAttribute('required', 'required');
                ensureTurnstileActivated();
            } else {
                cloudflareTokenInput.removeAttribute('required');
                cloudflareTokenInput.value = '';
            }
        }

        /**
         * Reset Turnstile before showing new check/delist
         */
        function resetTurnstile() {
            if (!cloudflareTokenInput || !window.turnstile) {
                return;
            }
            cloudflareTokenInput.value = '';
            var containerId = cloudflareTokenInput.getAttribute('data-turnstile-container');
            if (containerId) {
                var container = document.getElementById(containerId);
                if (container && container.getAttribute('data-turnstile-rendered') === '1') {
                    try {
                        // Reset only rendered widget instances.
                        window.turnstile.reset(containerId);
                    } catch (e) {
                        // Keep checker flow alive even if Turnstile widget reset fails.
                    }
                }
            }
        }

        // Initialize Turnstile on page load (if currently required)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateTurnstileState);
        } else {
            updateTurnstileState();
        }

        setCheckerButtonState();

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            // Validate Cloudflare token if required
            if (cloudflareTokenInput && cloudflareTokenInput.hasAttribute('required')) {
                var tokenValue = String(cloudflareTokenInput.value || '').trim();
                if (tokenValue === '') {
                    setResult(resultNode, false, 'Please complete the security verification (Cloudflare).');
                    return;
                }
            }

            var config = window.tornevallDnsblRemovalForm || {};
            var payload = new FormData(form);
            payload.append('action', String(config.action || 'tornevall_dnsbl_removal_form_submit'));
            payload.append('nonce', String(config.nonce || ''));

            if (checkerMode) {
                var currentIp = ipInput ? String(ipInput.value || '').trim() : '';
                var forceDelist = form.dataset.forceDelist === '1';
                var sendDelete = forceDelist && checkerListed && currentIp !== '' && currentIp === checkerIp;
                payload.append('check_only', sendDelete ? '0' : '1');
                payload.append('confirmed_listed', sendDelete ? '1' : '0');
                setFormDataValue(payload, 'operation', sendDelete ? 'delete' : 'check');
                if (sendDelete && form.dataset.checkerDeleteCandidates) {
                    payload.append('checker_delete_candidates', String(form.dataset.checkerDeleteCandidates || ''));
                }
                // The IP input is disabled when checkerConfirmed=true, so FormData
                // does not include it. Explicitly add/override the IP field here.
                if (currentIp !== '') {
                    setFormDataValue(payload, 'ip', currentIp);
                }
            }

            setSubmissionButtonState(checkerMode && payload.get('check_only') !== '1', config);

            fetch(String(config.ajaxUrl || ''), {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (json) {
                        return {
                            ok: response.ok,
                            status: response.status,
                            json: json
                        };
                    });
                })
                .then(function (result) {
                    var data = result.json && result.json.data ? result.json.data : {};
                    var ok = !!result.json.success && !!data.ok;
                    var message = normalizeStatusMessage(
                        result.status,
                        String(data.message || result.json.message || (ok ? 'Request completed.' : 'Request failed.')),
                        config
                    );
                    var shouldRunBackgroundCheck = checkerMode
                        && !toBool(data.background_api_check)
                        && toBool(data.background_check_available)
                        && toBool(data.check_only);

                    if (checkerMode) {
                        var currentIp = ipInput ? String(ipInput.value || '').trim() : '';
                        checkerBaseMessage = message;

                        if (toBool(data.check_only) && toBool(data.listed)) {
                            checkerIp = currentIp;
                            checkerListed = true;
                            checkerConfirmed = true;
                            advancedEnabled = false;
                            if (shouldRunBackgroundCheck) {
                                runBackgroundCheck(currentIp);
                            } else {
                                clearCheckerDeleteCandidates();
                                message = String(config.checkerReadyText || 'Delist is ready. You can now submit the request.');
                            }
                            resetTurnstile();
                        } else {
                            checkerConfirmed = false;
                            checkerIp = '';
                            checkerListed = !!data.listed;
                            clearCheckerDeleteCandidates();
                            if (shouldRunBackgroundCheck) {
                                runBackgroundCheck(currentIp);
                            }
                        }

                        if (!toBool(data.check_only) && ok) {
                            checkerConfirmed = false;
                            checkerIp = '';
                            checkerListed = false;
                            checkerBackgroundPending = false;
                            clearCheckerDeleteCandidates();
                        }
                    } else {
                        // Non-checker mode: reset Turnstile after submission
                        resetTurnstile();
                    }

                    var suffix = '';
                    if (toBool(data.dry_run)) {
                        suffix += ' Dry run active.';
                    }
                    // Only append the "API acknowledgement missing" hint when there
                    // was a real write attempt that went unacknowledged, not when the
                    // request failed at the auth/permission check stage (e.g. 502 from
                    // a remote 419 CSRF/token error) since the message already explains
                    // the failure clearly in that case.
                    if (data.api_acknowledged === false && result.status < 500) {
                        suffix += ' API acknowledgement missing.';
                    }
                    if (!shouldRunBackgroundCheck) {
                        setResult(resultNode, ok, message + suffix);
                    }
                })
                .catch(function (error) {
                    var config = window.tornevallDnsblRemovalForm || {};
                    var fallbackMessage = String(config.networkErrorText || 'Network error. Please try again.');
                    var message = (error && typeof error.message === 'string' && error.message.trim() !== '')
                        ? String(error.message)
                        : fallbackMessage;
                    setResult(resultNode, false, message);
                    // Reset Turnstile on error
                    resetTurnstile();
                })
                .finally(function () {
                    if (form.dataset.forceDelist) {
                        delete form.dataset.forceDelist;
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        setCheckerButtonState();
                    }
                });
        });

        if (delistBtn) {
            delistBtn.addEventListener('click', function (event) {
                event.preventDefault();
                if (!checkerConfirmed) {
                    return;
                }

                setSubmissionButtonState(true, window.tornevallDnsblRemovalForm || {});
                form.dataset.forceDelist = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', {cancelable: true}));
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('[data-tornevall-dnsbl-removal-form="1"]');
        forms.forEach(bindForm);
    });
})();

