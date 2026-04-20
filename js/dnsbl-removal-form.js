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

    function clearResult(node) {
        if (!node) {
            return;
        }

        node.style.display = 'none';
        node.textContent = '';
    }

    function bindForm(form) {
        var submitBtn = form.querySelector('[data-removal-submit]');
        var delistBtn = form.querySelector('[data-removal-delist]');
        var resetBtn = form.querySelector('[data-removal-reset]');
        var submitBtnText = submitBtn ? submitBtn.textContent : 'Submit request';
        var delistBtnText = delistBtn ? delistBtn.textContent : 'Delist';
        var resultNode = form.querySelector('[data-removal-result]');
        var opSelect = form.querySelector('[data-removal-operation]');
        var oldWrap = form.querySelector('[data-removal-old-bitmask-wrap]');
        var oldInput = form.querySelector('input[name="old_bitmask"]');
        var ipInput = form.querySelector('input[name="ip"]');
        var cidrInput = form.querySelector('input[name="cidr_range"]');
        var cidrScanTokenInput = form.querySelector('input[name="cidr_scan_token"]');
        var advancedWrap = form.querySelector('[data-removal-advanced]');
        var advancedToggleBtn = form.querySelector('[data-removal-advanced-toggle]');
        var cidrCheckBtn = form.querySelector('[data-removal-cidr-check]');
        var cidrProgressWrap = form.querySelector('[data-removal-cidr-progress-wrap]');
        var cidrProgressBar = form.querySelector('[data-removal-cidr-progress-bar]');
        var cidrProgressText = form.querySelector('[data-removal-cidr-progress-text]');
        var cidrStatusNode = form.querySelector('[data-removal-cidr-status]');
        var cidrHitListWrap = form.querySelector('[data-removal-cidr-hitlist]');
        var cidrHitListSummary = form.querySelector('[data-removal-cidr-hitlist-summary]');
        var cidrHitListItems = form.querySelector('[data-removal-cidr-hitlist-items]');
        var busyNode = form.querySelector('[data-removal-busy]');
        var busyTextNode = form.querySelector('[data-removal-busy-text]');
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
        var cidrScanInProgress = false;
        var cidrScanComplete = false;
        var cidrScanListedCount = 0;
        var cidrScanId = '';
        var checkerStateVersion = 0;
        var cidrScanVersion = 0;

        function formatTemplate(template, replacements) {
            var output = String(template || '');
            Object.keys(replacements || {}).forEach(function (key) {
                var value = String(replacements[key]);
                output = output.replace(new RegExp('%' + key + '\\$[ds]', 'g'), value);
            });

            return output;
        }

        function wait(delayMs) {
            return new Promise(function (resolve) {
                window.setTimeout(resolve, Math.max(0, parseInt(delayMs, 10) || 0));
            });
        }

        function normalizeTypedCidr(value) {
            var raw = String(value || '').trim();
            var match = raw.match(/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/);
            if (!match) {
                return '';
            }

            var octets = String(match[1]).split('.');
            var validOctets = octets.length === 4 && octets.every(function (octet) {
                var number = parseInt(octet, 10);
                return String(number) === String(parseInt(octet, 10)) && number >= 0 && number <= 255;
            });
            if (!validOctets) {
                return '';
            }

            var prefix = parseInt(match[2], 10);
            var config = window.tornevallDnsblRemovalForm || {};
            var minPrefix = parseInt(form.getAttribute('data-cidr-min-prefix') || config.cidrMinPrefix || 24, 10);
            if (isNaN(minPrefix) || minPrefix < 24 || minPrefix > 32) {
                minPrefix = 24;
            }
            if (prefix < minPrefix || prefix > 32) {
                return '';
            }

            return String(match[1]) + '/' + String(prefix);
        }

        function normalizeStatusMessage(responseStatus, message, config) {
            if (responseStatus === 419) {
                return String(config.csrfExpiredText || 'Security session expired (HTTP 419). Please refresh this page and try again.');
            }

            return String(message || '');
        }

        function clearCheckerDeleteCandidates() {
            checkerDeleteCandidates = [];
            delete form.dataset.checkerDeleteCandidates;
        }

        function invalidateCheckerResponses() {
            checkerStateVersion += 1;
            return checkerStateVersion;
        }

        function invalidateCidrScanRequests() {
            cidrScanVersion += 1;
            return cidrScanVersion;
        }

        function setBusyState(active, message, config) {
            var fallbackText = String((config && config.busyText) || 'Working…');

            if (busyNode) {
                busyNode.style.display = active ? 'flex' : 'none';
            }

            if (busyTextNode) {
                busyTextNode.textContent = active
                    ? String(message || fallbackText)
                    : fallbackText;
            }

            if (active) {
                form.setAttribute('aria-busy', 'true');
            } else {
                form.removeAttribute('aria-busy');
            }
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

        function getTrimmedCidrValue() {
            return cidrInput ? String(cidrInput.value || '').trim() : '';
        }

        function hasAdvancedCidrValue() {
            return advancedEnabled && getTrimmedCidrValue() !== '';
        }

        function hideCidrStatus() {
            if (!cidrStatusNode) {
                return;
            }

            cidrStatusNode.style.display = 'none';
            cidrStatusNode.textContent = '';
        }

        function clearCidrHitList() {
            if (cidrHitListItems) {
                cidrHitListItems.innerHTML = '';
            }
            if (cidrHitListSummary) {
                cidrHitListSummary.textContent = '';
            }
            if (cidrHitListWrap) {
                cidrHitListWrap.style.display = 'none';
            }
        }

        function renderCidrHitList(listedIps, total, complete, config) {
            if (!cidrHitListWrap || !cidrHitListItems) {
                return;
            }

            if (!Array.isArray(listedIps) || listedIps.length < 1) {
                clearCidrHitList();
                return;
            }

            cidrHitListWrap.style.display = 'block';
            cidrHitListItems.innerHTML = '';
            listedIps.forEach(function (listedIp) {
                var item = document.createElement('li');
                item.textContent = String(listedIp || '');
                cidrHitListItems.appendChild(item);
            });

            if (cidrHitListSummary) {
                var template = complete
                    ? String(config.cidrHitListSummaryCompleteText || 'Listed addresses found: %1$d of %2$d')
                    : String(config.cidrHitListSummaryProgressText || 'Listed addresses found so far: %1$d');
                cidrHitListSummary.textContent = formatTemplate(template, {
                    1: listedIps.length,
                    2: total
                });
            }
        }

        function clearCidrScanState(options) {
            options = options || {};
            if (options.invalidateRequest !== false) {
                invalidateCidrScanRequests();
            }
            cidrScanInProgress = false;
            cidrScanComplete = false;
            cidrScanListedCount = 0;
            cidrScanId = '';

            if (cidrScanTokenInput) {
                cidrScanTokenInput.value = '';
            }

            if (cidrProgressBar) {
                cidrProgressBar.style.width = '0%';
            }

            if (cidrProgressText) {
                cidrProgressText.textContent = '';
            }

            if (cidrProgressWrap && !options.keepProgressVisible) {
                cidrProgressWrap.style.display = 'none';
            }

            if (!options.keepStatus) {
                hideCidrStatus();
            }

            clearCidrHitList();
        }

        function updateCidrProgress(processed, total, message) {
            if (cidrProgressWrap) {
                cidrProgressWrap.style.display = 'block';
            }

            var percent = total > 0 ? Math.min(100, Math.floor((processed / total) * 100)) : 0;
            if (cidrProgressBar) {
                cidrProgressBar.style.width = String(percent) + '%';
            }

            if (cidrProgressText) {
                var prefix = total > 0
                    ? String(processed) + '/' + String(total)
                    : '0/0';
                var details = String(message || '').trim();
                cidrProgressText.textContent = details !== ''
                    ? prefix + ' — ' + details
                    : prefix;
            }
        }

        function hasReadyCidrScan() {
            var tokenValue = cidrScanTokenInput ? String(cidrScanTokenInput.value || '').trim() : '';
            return getTrimmedCidrValue() !== ''
                && cidrScanComplete
                && cidrScanListedCount > 0
                && tokenValue !== '';
        }

        function sendAjaxPayload(payload, config) {
            return fetch(String(config.ajaxUrl || ''), {
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
                });
        }

        function setCheckerButtonState() {
            if (!checkerMode || !submitBtn) {
                return;
            }
            var config = window.tornevallDnsblRemovalForm || {};
            var cidrValue = getTrimmedCidrValue();
            var cidrNeedsScan = advancedEnabled && cidrValue !== '';
            var cidrReady = cidrNeedsScan && hasReadyCidrScan();
            submitBtnText = String(config.checkerCheckText || 'Check if listed');
            submitBtn.disabled = cidrScanInProgress || (advancedEnabled && cidrValue !== '');
            submitBtn.textContent = submitBtnText;

            if (delistBtn) {
                delistBtn.textContent = delistBtnText;
                var canDelistNow = cidrReady || (checkerListed && checkerIp !== '' && (!cidrNeedsScan || cidrReady));
                delistBtn.style.display = (checkerListed || cidrReady) ? 'inline-block' : 'none';
                delistBtn.disabled = !canDelistNow || cidrScanInProgress;
                delistBtn.title = (checkerBackgroundPending && canDelistNow)
                    ? 'The Tools follow-up is still running, but this IP is already confirmed listed so you can delist now.'
                    : '';
            }

            if (resetBtn) {
                resetBtn.disabled = false;
            }

            if (ipInput) {
                ipInput.disabled = cidrScanInProgress;
            }

            if (advancedWrap) {
                var showAdvanced = canUseAdvancedCidr && advancedEnabled;
                advancedWrap.style.display = showAdvanced ? 'block' : 'none';
                if (!showAdvanced && cidrInput) {
                    cidrInput.value = '';
                    clearCidrScanState();
                }
            }

            if (advancedToggleBtn) {
                advancedToggleBtn.style.display = canUseAdvancedCidr && (checkerConfirmed || advancedEnabled) ? 'inline-block' : 'none';
                advancedToggleBtn.disabled = cidrScanInProgress;
                advancedToggleBtn.textContent = advancedEnabled
                    ? 'Advanced - ON'
                    : 'Advanced';
            }

            if (cidrCheckBtn) {
                var showCidrCheck = canUseAdvancedCidr && advancedEnabled;
                cidrCheckBtn.style.display = showCidrCheck ? 'inline-block' : 'none';
                cidrCheckBtn.disabled = !showCidrCheck || cidrScanInProgress || cidrValue === '';
                cidrCheckBtn.textContent = cidrScanInProgress
                    ? String(config.cidrCheckingText || 'Checking CIDR locally…')
                    : String(config.cidrCheckText || 'Check CIDR locally');
            }

            updateTurnstileState();
        }

        function setSubmissionButtonState(isDeleteSubmission, config) {
            setBusyState(
                true,
                checkerMode
                    ? String(isDeleteSubmission ? (config.busyDelistText || config.sendingText || 'Sending delist request…') : (config.busyCheckingText || config.checkingText || 'Checking listing status…'))
                    : String(config.busyText || config.sendingText || 'Working…'),
                config
            );

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

        function restoreIdleButtonState() {
            if (form.dataset.forceDelist) {
                delete form.dataset.forceDelist;
            }
            setBusyState(false, '', window.tornevallDnsblRemovalForm || {});
            setCheckerButtonState();
        }

        function moveTypedCidrToAdvanced(rawValue, showMessage) {
            var normalizedCidr = normalizeTypedCidr(rawValue);
            var config = window.tornevallDnsblRemovalForm || {};

            if (!checkerMode || !canUseAdvancedCidr || normalizedCidr === '' || !cidrInput || !ipInput) {
                return false;
            }

            checkerConfirmed = false;
            checkerIp = '';
            checkerListed = false;
            checkerBaseMessage = '';
            checkerBackgroundPending = false;
            clearCheckerDeleteCandidates();
            clearCidrScanState();
            advancedEnabled = true;
            cidrInput.value = normalizedCidr;
            ipInput.value = '';
            setBusyState(false, '', config);
            setCheckerButtonState();

            if (showMessage) {
                setResult(
                    resultNode,
                    true,
                    String(config.cidrMovedToAdvancedText || 'CIDR detected. Advanced mode has been opened and the range was moved there. Run the local CIDR check from Advanced, then continue the delist flow from that approved CIDR scope.')
                );
            }

            return true;
        }

        function resetCheckerState(options) {
            options = options || {};
            invalidateCheckerResponses();
            checkerConfirmed = false;
            checkerIp = '';
            checkerListed = false;
            checkerBaseMessage = '';
            checkerBackgroundPending = false;
            clearCheckerDeleteCandidates();
            if (!options.keepAdvanced) {
                advancedEnabled = false;
                if (cidrInput) {
                    cidrInput.value = '';
                }
            }
            clearCidrScanState();
            if (!options.keepIp && ipInput) {
                var restorePrefill = options.restorePrefill !== false && checkerPrefillIp !== '';
                ipInput.value = restorePrefill ? checkerPrefillIp : '';
                checkerPrefillCleared = !restorePrefill;
            }
            clearResult(resultNode);
            setBusyState(false, '', window.tornevallDnsblRemovalForm || {});
            resetTurnstile();
            if (form.dataset.forceDelist) {
                delete form.dataset.forceDelist;
            }
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
                resetCheckerState({keepIp: true});
            });

            var clearPrefilledIpOnFirstFocus = function () {
                if (checkerPrefillCleared || checkerPrefillIp === '') {
                    return;
                }
                if (String(ipInput.value || '').trim() === checkerPrefillIp) {
                    ipInput.value = '';
                    resetCheckerState({keepIp: true});
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
                if (!canUseAdvancedCidr || (!checkerConfirmed && !advancedEnabled)) {
                    return;
                }
                advancedEnabled = !advancedEnabled;
                if (advancedEnabled && cidrInput && String(cidrInput.value || '').trim() === '' && checkerIp !== '') {
                    cidrInput.value = checkerIp + '/32';
                }
                if (!advancedEnabled) {
                    clearCidrScanState();
                }
                setCheckerButtonState();
            });
        }

        if (cidrInput) {
            cidrInput.addEventListener('input', function () {
                clearCidrScanState();
                setCheckerButtonState();
            });
        }

        function runLocalCidrScan() {
            var config = window.tornevallDnsblRemovalForm || {};
            var cidrValue = getTrimmedCidrValue();

            if (!advancedEnabled || !canUseAdvancedCidr) {
                return;
            }

            if (cidrValue === '') {
                setResult(cidrStatusNode, false, String(config.cidrEmptyText || 'Enter a CIDR range like 203.0.113.0/24 before running the local check.'));
                return;
            }

            clearCidrScanState({keepProgressVisible: true, keepStatus: true});
            var activeScanVersion = cidrScanVersion;
            hideCidrStatus();
            cidrScanInProgress = true;
            updateCidrProgress(0, 0, String(config.cidrStartingText || 'Starting local CIDR scan…'));
            clearCidrHitList();
            setCheckerButtonState();

            var requestNextBatch = function (activeScanId) {
                if (activeScanVersion !== cidrScanVersion) {
                    return Promise.resolve();
                }

                if (getTrimmedCidrValue() !== cidrValue) {
                    cidrScanInProgress = false;
                    setCheckerButtonState();
                    return Promise.resolve();
                }

                var payload = new FormData();
                payload.append('action', String(config.action || 'tornevall_dnsbl_removal_form_submit'));
                payload.append('nonce', String(config.nonce || ''));
                payload.append('cidr_scan', '1');
                payload.append('checker_mode', '1');
                if (activeScanId) {
                    payload.append('cidr_scan_id', String(activeScanId));
                } else {
                    payload.append('cidr_range', cidrValue);
                }

                return sendAjaxPayload(payload, config)
                    .then(function (result) {
                        if (activeScanVersion !== cidrScanVersion) {
                            return Promise.resolve();
                        }

                        var data = result.json && result.json.data ? result.json.data : {};
                        var ok = !!result.json.success && !!data.ok;
                        if (!ok) {
                            throw new Error(normalizeStatusMessage(
                                result.status,
                                String(data.message || result.json.message || (config.cidrFailedText || 'The local CIDR check could not be completed.')),
                                config
                            ));
                        }

                        if (getTrimmedCidrValue() !== cidrValue) {
                            cidrScanInProgress = false;
                            setCheckerButtonState();
                            return Promise.resolve();
                        }

                        cidrScanId = String(data.scan_id || '');
                        cidrScanListedCount = parseInt(data.listed_count || 0, 10) || 0;
                        renderCidrHitList(
                            Array.isArray(data.listed_ips) ? data.listed_ips : [],
                            parseInt(data.total || 0, 10) || 0,
                            toBool(data.complete),
                            config
                        );
                        updateCidrProgress(
                            parseInt(data.processed || 0, 10) || 0,
                            parseInt(data.total || 0, 10) || 0,
                            String(data.message || '')
                        );

                        if (toBool(data.complete)) {
                            cidrScanInProgress = false;
                            cidrScanComplete = true;
                            if (cidrScanTokenInput) {
                                cidrScanTokenInput.value = String(data.cidr_scan_token || '');
                            }
                            setResult(
                                cidrStatusNode,
                                cidrScanListedCount > 0,
                                String(data.message || (cidrScanListedCount > 0
                                    ? (config.cidrReadyText || 'CIDR delist is ready. Listed addresses were found in the block.')
                                    : (config.cidrEmptyResultText || 'No listed addresses were found in that CIDR block.')))
                            );
                            setCheckerButtonState();
                            return Promise.resolve();
                        }

                        return wait(config.cidrBatchPauseMs || 0)
                            .then(function () {
                                if (activeScanVersion !== cidrScanVersion) {
                                    return Promise.resolve();
                                }
                                return requestNextBatch(cidrScanId);
                            });
                    });
            };

            requestNextBatch('')
                .catch(function (error) {
                    if (activeScanVersion !== cidrScanVersion) {
                        return;
                    }

                    var message = (error && typeof error.message === 'string' && error.message.trim() !== '')
                        ? String(error.message)
                        : String(config.cidrFailedText || 'The local CIDR check could not be completed.');
                    clearCidrScanState({keepProgressVisible: true, keepStatus: true});
                    setResult(cidrStatusNode, false, message);
                })
                .finally(function () {
                    if (activeScanVersion !== cidrScanVersion) {
                        return;
                    }

                    cidrScanInProgress = false;
                    setCheckerButtonState();
                });
        }

        if (cidrCheckBtn) {
            cidrCheckBtn.addEventListener('click', function (event) {
                event.preventDefault();
                runLocalCidrScan();
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function (event) {
                event.preventDefault();
                resetCheckerState({restorePrefill: true});
            });
        }

        function runBackgroundCheck(currentIp) {
            if (!checkerMode || !currentIp) {
                return Promise.resolve();
            }

            var config = window.tornevallDnsblRemovalForm || {};
            var requestVersion = checkerStateVersion;
            var backgroundPayload = new FormData(form);
            backgroundPayload.append('action', String(config.action || 'tornevall_dnsbl_removal_form_submit'));
            backgroundPayload.append('nonce', String(config.nonce || ''));
            backgroundPayload.append('background_api_check', '1');
            backgroundPayload.append('check_only', '1');
            backgroundPayload.append('confirmed_listed', '0');
            setFormDataValue(backgroundPayload, 'operation', 'check');

            checkerBackgroundPending = true;
            checkerConfirmed = checkerListed;
            setBusyState(true, String(config.backgroundCheckingText || 'Checking Tools API in the background…'), config);
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
                    var rawMessage = String(data.message || (result.ok
                        ? 'Tools API follow-up completed.'
                        : (config.backgroundCheckFailedText || 'The Tools API follow-up could not be completed. The first DNS result is still shown above.')));
                    var followUpMessage = (result.status === 419 && rawMessage !== '')
                        ? rawMessage
                        : normalizeStatusMessage(result.status, rawMessage, config);
                    var candidates = Array.isArray(data.delete_candidates) ? data.delete_candidates : [];
                    var liveIpValue = ipInput ? String(ipInput.value || '').trim() : '';

                    if (requestVersion !== checkerStateVersion || liveIpValue !== currentIp) {
                        return;
                    }

                    checkerBackgroundPending = false;
                    checkerListed = checkerListed || !!data.listed;
                    checkerIp = currentIp;
                    if (candidates.length) {
                        persistCheckerDeleteCandidates(candidates);
                    }
                    checkerConfirmed = checkerListed;
                    setBusyState(false, '', config);
                    setCheckerButtonState();

                    setResult(
                        resultNode,
                        checkerListed || !!result.ok,
                        buildCombinedMessage(checkerBaseMessage, followUpMessage)
                    );
                })
                .catch(function () {
                    var liveIpValue = ipInput ? String(ipInput.value || '').trim() : '';
                    if (requestVersion !== checkerStateVersion || liveIpValue !== currentIp) {
                        return;
                    }

                    checkerBackgroundPending = false;
                    checkerConfirmed = checkerListed;
                    setBusyState(false, '', config);
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

            var requiresTurnstile = !checkerMode || checkerListed || hasReadyCidrScan();
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
                    restoreIdleButtonState();
                    setResult(resultNode, false, 'Please complete the security verification (Cloudflare).');
                    return;
                }
            }

            var config = window.tornevallDnsblRemovalForm || {};
            var payload = new FormData(form);
            var requestVersion = checkerStateVersion;
            payload.append('action', String(config.action || 'tornevall_dnsbl_removal_form_submit'));
            payload.append('nonce', String(config.nonce || ''));

            if (checkerMode) {
                var currentIp = ipInput ? String(ipInput.value || '').trim() : '';
                if (moveTypedCidrToAdvanced(currentIp, true)) {
                    restoreIdleButtonState();
                    return;
                }
                var forceDelist = form.dataset.forceDelist === '1';
                var cidrAuthoritativeDelete = forceDelist && hasAdvancedCidrValue() && hasReadyCidrScan();
                var sendDelete = cidrAuthoritativeDelete || (forceDelist && checkerListed && currentIp !== '' && currentIp === checkerIp);
                payload.append('check_only', sendDelete ? '0' : '1');
                payload.append('confirmed_listed', sendDelete ? '1' : '0');
                setFormDataValue(payload, 'operation', sendDelete ? 'delete' : 'check');
                if (sendDelete && form.dataset.checkerDeleteCandidates) {
                    payload.append('checker_delete_candidates', String(form.dataset.checkerDeleteCandidates || ''));
                }
                if (currentIp !== '') {
                    setFormDataValue(payload, 'ip', currentIp);
                }
            }

            if (checkerMode && payload.get('check_only') !== '1' && getTrimmedCidrValue() !== '' && !hasReadyCidrScan()) {
                restoreIdleButtonState();
                setResult(
                    resultNode,
                    false,
                    String(config.cidrScanRequiredText || 'Run the local CIDR check first. CIDR delist is only enabled after that scan has found at least one listed address in the block.')
                );
                return;
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
                    if (checkerMode && requestVersion !== checkerStateVersion) {
                        return;
                    }

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
                            if (hasAdvancedCidrValue()) {
                                advancedEnabled = false;
                                if (cidrInput) {
                                    cidrInput.value = '';
                                }
                                clearCidrScanState();
                            }
                        }
                    } else {
                        resetTurnstile();
                    }

                    var suffix = '';
                    if (toBool(data.dry_run)) {
                        suffix += ' Dry run active.';
                    }
                    if (data.api_acknowledged === false && result.status < 500) {
                        suffix += ' API acknowledgement missing.';
                    }
                    if (!shouldRunBackgroundCheck) {
                        setBusyState(false, '', config);
                        setResult(resultNode, ok, message + suffix);
                    }
                })
                .catch(function (error) {
                    if (checkerMode && requestVersion !== checkerStateVersion) {
                        return;
                    }

                    var runtimeConfig = window.tornevallDnsblRemovalForm || {};
                    var fallbackMessage = String(runtimeConfig.networkErrorText || 'Network error. Please try again.');
                    var message = (error && typeof error.message === 'string' && error.message.trim() !== '')
                        ? String(error.message)
                        : fallbackMessage;
                    setBusyState(false, '', runtimeConfig);
                    setResult(resultNode, false, message);
                    resetTurnstile();
                })
                .finally(function () {
                    if (checkerMode && requestVersion !== checkerStateVersion) {
                        return;
                    }

                    if (form.dataset.forceDelist) {
                        delete form.dataset.forceDelist;
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        setCheckerButtonState();
                    }
                    if (!checkerBackgroundPending) {
                        setBusyState(false, '', window.tornevallDnsblRemovalForm || {});
                    }
                });
        });

        if (delistBtn) {
            delistBtn.addEventListener('click', function (event) {
                event.preventDefault();
                if (!checkerConfirmed && !hasReadyCidrScan()) {
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

