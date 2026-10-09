/**
 * Connected translations panel for the isolated departments model.
 * Closing the panel does not drop touched rows. Suggestions apply only
 * after the guard accepts the ticket for the current source and target.
 */
(function (global) {
    function utf8Binary(text) {
        var bytes = new TextEncoder().encode(String(text));
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += String.fromCharCode(bytes[i]);
        }
        return out;
    }

    function rightRotate(value, amount) {
        return (value >>> amount) | (value << (32 - amount));
    }

    function sha256(text) {
        var ascii = utf8Binary(text);
        var maxWord = Math.pow(2, 32);
        var words = [];
        var hash = sha256.h = sha256.h || [];
        var k = sha256.k = sha256.k || [];
        var primeCounter = k.length;
        var isComposite = {};
        var candidate;
        var i;
        for (candidate = 2; primeCounter < 64; candidate++) {
            if (!isComposite[candidate]) {
                for (i = 0; i < 313; i += candidate) {
                    isComposite[i] = candidate;
                }
                hash[primeCounter] = (Math.pow(candidate, 0.5) * maxWord) | 0;
                k[primeCounter++] = (Math.pow(candidate, 1 / 3) * maxWord) | 0;
            }
        }
        var asciiBitLength = ascii.length * 8;
        ascii += '\x80';
        while ((ascii.length % 64) - 56) {
            ascii += '\x00';
        }
        for (i = 0; i < ascii.length; i++) {
            var code = ascii.charCodeAt(i);
            words[i >> 2] |= code << ((3 - i) % 4) * 8;
        }
        words[words.length] = (asciiBitLength / maxWord) | 0;
        words[words.length] = asciiBitLength;
        for (var j = 0; j < words.length;) {
            var w = words.slice(j, j += 16);
            var oldHash = hash;
            hash = hash.slice(0, 8);
            for (i = 0; i < 64; i++) {
                var w15 = w[i - 15];
                var w2 = w[i - 2];
                var a = hash[0];
                var e = hash[4];
                var temp1 = hash[7]
                    + (rightRotate(e, 6) ^ rightRotate(e, 11) ^ rightRotate(e, 25))
                    + ((e & hash[5]) ^ ((~e) & hash[6]))
                    + k[i]
                    + (w[i] = (i < 16) ? w[i] : (
                        w[i - 16]
                        + (rightRotate(w15, 7) ^ rightRotate(w15, 18) ^ (w15 >>> 3))
                        + w[i - 7]
                        + (rightRotate(w2, 17) ^ rightRotate(w2, 19) ^ (w2 >>> 10))
                    ) | 0);
                var temp2 = (rightRotate(a, 2) ^ rightRotate(a, 13) ^ rightRotate(a, 22))
                    + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));
                hash = [(temp1 + temp2) | 0].concat(hash);
                hash[4] = (hash[4] + temp1) | 0;
            }
            for (i = 0; i < 8; i++) {
                hash[i] = (hash[i] + oldHash[i]) | 0;
            }
        }
        var result = '';
        for (i = 0; i < 8; i++) {
            for (var b = 3; b + 1; b--) {
                var value = (hash[i] >> (b * 8)) & 255;
                result += (value < 16 ? '0' : '') + value.toString(16);
            }
        }
        return result;
    }

    function Guard() {
        this.revision = 0;
        this.seq = 0;
        this.inflight = null;
        this.sourceHash = '';
        this.targetState = 'eligible';
    }
    Guard.prototype.invalidate = function () {
        this.revision += 1;
        this.inflight = null;
    };
    Guard.prototype.begin = function () {
        this.seq += 1;
        this.inflight = this.seq;
        return {
            token: this.seq,
            revision: this.revision,
            source_hash: this.sourceHash,
            target_state: this.targetState
        };
    };
    Guard.prototype.accept = function (ticket) {
        if (this.inflight === null || ticket.token !== this.inflight) return false;
        if (ticket.revision !== this.revision) return false;
        if (ticket.source_hash !== this.sourceHash) return false;
        if (ticket.target_state !== this.targetState) return false;
        return true;
    };

    function panelLocales(roles, activeLocales) {
        if (!roles || roles.mode !== 'configured' || !roles.base) return [];
        var active = activeLocales || [];
        var out = [];
        if (roles.base !== 'en' && active.indexOf('en') !== -1) out.push('en');
        (roles.content || []).forEach(function (code) {
            if (code !== roles.base && code !== 'en' && out.indexOf(code) === -1) out.push(code);
        });
        return out;
    }

    function payloadFromDrawer(baseText, drawer) {
        var body = { base_text: baseText, locales: {} };
        Object.keys(drawer || {}).forEach(function (code) {
            var row = drawer[code];
            if (!row || row.touched !== true) return;
            body.locales[code] = {
                present: true,
                text: row.text || '',
                intent: row.intent || 'manual',
                clear: row.clear === true,
                explicit_replace: row.explicit_replace === true,
                source_hash: row.source_hash || '',
                source_locale: row.source_locale || ''
            };
        });
        return body;
    }

    function slugify(text) {
        return String(text || '').toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/[\s-]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function mount(root, options) {
        options = options || {};
        var debounceMs = options.debounceMs || 600;
        var guard = new Guard();
        var drawer = {};
        var known = {};
        var roles = { mode: 'legacy_unconfigured', base: null, content: [], admin_ui: [], customer: [] };
        var active = [];
        var recordId = 0;
        var generation = 0;
        var timer = null;
        var autoSlug = true;
        var failures = {};
        var englishPivot = '';
        var rowPivot = {};
        var showSlug = options.showSlug !== false;
        var showSort = options.showSort !== false;
        var showSave = options.showSave !== false;
        var saveLabel = options.saveLabel || 'حفظ القسم';
        var idPrefix = String(options.idPrefix || '');
        function eid(name) { return idPrefix + name; }
        function q(name) { return root.querySelector('#' + eid(name)); }
        var html = ''
            + '<span id="' + eid('translation-transport-note') + '" hidden>تعذر طلب الترجمة</span>'
            + '<label for="' + eid('base-text') + '">اللغة الأساسية <span id="' + eid('base-code') + '"></span></label>'
            + '<input id="' + eid('base-text') + '" type="text">';
        if (showSlug) {
            html += '<label for="' + eid('slug') + '">Slug</label>'
                + '<input id="' + eid('slug') + '" type="text" dir="ltr" disabled>';
        }
        if (showSort) {
            html += '<label for="' + eid('sort_order') + '">الترتيب</label>'
                + '<input id="' + eid('sort_order') + '" type="number" value="0">';
        }
        html += '<button type="button" id="' + eid('translations-button') + '">الترجمات</button>'
            + '<span id="' + eid('translation-failure-flag') + '" hidden>تعذر بعضها</span>'
            + '<button type="button" id="' + eid('translate-button') + '">ترجمة</button>';
        if (showSave) {
            html += '<button type="button" id="' + eid('save-button') + '">' + saveLabel + '</button>';
        }
        html += '<div id="' + eid('translations-panel') + '" hidden></div>';
        root.innerHTML = html;
        var baseInput = q('base-text');
        var panel = q('translations-panel');
        var slugInput = showSlug ? q('slug') : null;
        var sortInput = showSort ? q('sort_order') : null;
        var saveButton = showSave ? q('save-button') : null;

        function applyAutoSlug(text) {
            if (!autoSlug || !slugInput) return;
            var next = slugify(text);
            if (next !== '') slugInput.value = next;
        }

        function localesNow() {
            return panelLocales(roles, active);
        }

        function targetState() {
            return JSON.stringify(localesNow().map(function (code) {
                var row = drawer[code] || {};
                var origin = (known[code] && known[code].origin) || '';
                return code + '|' + (row.text || '') + '|' + (row.clear ? '1' : '0') + '|' + (row.intent || '') + '|' + origin;
            }));
        }

        function rememberSource() {
            guard.sourceHash = sha256(baseInput.value);
            guard.targetState = targetState();
        }

        function invalidateNow() {
            guard.invalidate();
            rememberSource();
        }

        function refreshFlag() {
            var flag = q('translation-failure-flag');
            var any = Object.keys(failures).length > 0;
            flag.hidden = !(any && panel.hidden);
            var note = q('translation-transport-note');
            if (note) note.hidden = !failures.transport;
        }

        function paintStatuses() {
            panel.querySelectorAll('.locale-status').forEach(function (node) {
                var code = node.getAttribute('data-locale');
                node.textContent = failures[code] ? 'تعذرت الترجمة' : '';
            });
            refreshFlag();
        }

        function renderPanel() {
            var codes = localesNow();
            panel.innerHTML = '';
            q('base-code').textContent = roles.base ? '(' + roles.base + ')' : '';
            codes.forEach(function (code) {
                var wrap = document.createElement('div');
                wrap.className = 'locale-row';
                var label = document.createElement('label');
                label.textContent = code;
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'locale-field';
                input.setAttribute('data-locale', code);
                var shown = drawer[code] && drawer[code].touched ? drawer[code].text : ((known[code] && known[code].text) || '');
                input.value = shown || '';
                input.setAttribute('data-origin', (known[code] && known[code].origin) || '');
                input.addEventListener('input', function () {
                    var previous = known[code] || {};
                    drawer[code] = {
                        touched: true,
                        text: input.value,
                        intent: 'manual',
                        clear: false,
                        explicit_replace: false,
                        source_hash: '',
                        source_locale: ''
                    };
                    known[code] = {
                        origin: 'manual',
                        text: input.value,
                        source_locale: previous.source_locale || '',
                        source_hash: previous.source_hash || ''
                    };
                    delete failures[code];
                    paintStatuses();
                    invalidateNow();
                    if (code === 'en' && roles.base !== 'en') {
                        applyAutoSlug(input.value);
                        if (typeof options.onEnglishInput === 'function') options.onEnglishInput(input.value);
                        clearTimeout(timer);
                        timer = setTimeout(function () {
                            sendSuggest(baseInput.value, [], { correctedEnglish: input.value });
                        }, debounceMs);
                    }
                });
                var clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'locale-clear';
                clearBtn.setAttribute('data-locale', code);
                clearBtn.textContent = 'مسح';
                clearBtn.addEventListener('click', function () {
                    drawer[code] = {
                        touched: true,
                        text: '',
                        intent: 'manual',
                        clear: true,
                        explicit_replace: false,
                        source_hash: '',
                        source_locale: ''
                    };
                    known[code] = { origin: 'cleared', text: '' };
                    input.value = '';
                    invalidateNow();
                });
                var replaceBtn = document.createElement('button');
                replaceBtn.type = 'button';
                replaceBtn.className = 'locale-replace';
                replaceBtn.setAttribute('data-locale', code);
                replaceBtn.textContent = 'استبدال هذا الحقل';
                replaceBtn.addEventListener('click', function () {
                    clearTimeout(timer);
                    sendSuggest(baseInput.value, [code]);
                });
                var status = document.createElement('span');
                status.className = 'locale-status';
                status.setAttribute('data-locale', code);
                if (failures[code]) status.textContent = 'تعذرت الترجمة';
                wrap.appendChild(label);
                wrap.appendChild(input);
                wrap.appendChild(clearBtn);
                wrap.appendChild(replaceBtn);
                wrap.appendChild(status);
                panel.appendChild(wrap);
            });
            refreshFlag();
        }

        function applySuggestions(result) {
            var suggestions = (result && result.suggestions) || {};
            Object.keys(suggestions).forEach(function (code) {
                var item = suggestions[code];
                if (!item || item.apply !== true) return;
                drawer[code] = {
                    touched: true,
                    text: item.text || '',
                    intent: 'machine',
                    clear: false,
                    explicit_replace: item.explicit_replace === true,
                    source_hash: item.source_hash || '',
                    source_locale: item.source_locale || ''
                };
                known[code] = {
                    origin: 'machine',
                    text: item.text || '',
                    source_locale: item.source_locale || '',
                    source_hash: item.source_hash || ''
                };
                var input = panel.querySelector('.locale-field[data-locale="' + code + '"]');
                if (input) input.value = item.text || '';
                if (code === 'en') applyAutoSlug(item.text || '');
                if (code === 'en') englishPivot = item.text || '';
            });
        }

        function summarize(result) {
            var suggestions = (result && result.suggestions) || {};
            var applied = [];
            var kept = [];
            var failed = [];
            Object.keys(suggestions).forEach(function (code) {
                var item = suggestions[code];
                if (!item || code === 'base') return;
                if (item.apply === true && item.ok === true && String(item.text || '') !== '') {
                    applied.push(code);
                } else if (item.ok === false) {
                    failed.push(code);
                } else {
                    kept.push(code);
                }
            });
            return { applied: applied, kept: kept, failed: failed };
        }

        function sendSuggest(snapshot, explicitList, extra) {
            extra = extra || {};
            var gen = generation;
            var corrected = Object.prototype.hasOwnProperty.call(extra, 'correctedEnglish') ? String(extra.correctedEnglish) : null;
            invalidateNow();
            guard.sourceHash = sha256(snapshot);
            guard.targetState = targetState();
            var ticket = guard.begin();
            var req = {
                base_text: snapshot,
                source_hash: ticket.source_hash,
                explicit: explicitList.slice(),
                known: JSON.parse(JSON.stringify(known))
            };
            Object.keys(req.known).forEach(function (code) {
                if (Object.prototype.hasOwnProperty.call(rowPivot, code)) {
                    req.known[code].previous_english = rowPivot[code];
                }
            });
            if (corrected !== null) {
                req.from_corrected_english = true;
                req.corrected_english = corrected;
            }
            function staleNow() {
                var englishNow = '';
                var englishField = panel.querySelector('.locale-field[data-locale="en"]');
                if (englishField) englishNow = englishField.value;
                var staleEnglish = corrected !== null && sha256(englishNow) !== sha256(corrected);
                return gen !== generation || !guard.accept(ticket) || sha256(baseInput.value) !== ticket.source_hash || staleEnglish;
            }
            function notify(detail) {
                if (typeof options.onApplied === 'function') options.onApplied(detail);
            }
            function rejected() {
                root.setAttribute('data-last-apply', 'rejected');
                notify({ applied: false, rejected: true, label: 'rejected', english: '' });
                return { applied: false, rejected: true, failed: [], kept: [] };
            }
            function transportFailed() {
                if (staleNow()) return rejected();
                failures.transport = true;
                paintStatuses();
                root.setAttribute('data-last-apply', 'failed');
                notify({ applied: false, rejected: false, label: 'failed', english: '' });
                return { applied: false, rejected: false, failed: ['transport'], kept: [] };
            }
            return Promise.resolve().then(function () {
                return options.suggest(req);
            }).then(function (result) {
                if (staleNow()) return rejected();
                if (!result || typeof result !== 'object' || result.success === false || !result.suggestions) {
                    return transportFailed();
                }
                var summary = summarize(result);
                delete failures.transport;
                applySuggestions(result);
                var produced = '';
                var suggestions = (result && result.suggestions) || {};
                if (suggestions.en && suggestions.en.apply === true && suggestions.en.text) produced = suggestions.en.text;
                else if (corrected !== null) produced = corrected;
                else if (roles.base === 'en') produced = snapshot;
                else produced = (req.known.en && req.known.en.text) || '';
                summary.applied.forEach(function (code) {
                    delete failures[code];
                    if (code !== 'en' && produced !== '') rowPivot[code] = produced;
                });
                summary.kept.forEach(function (code) { delete failures[code]; });
                summary.failed.forEach(function (code) { failures[code] = true; });
                paintStatuses();
                var label = 'idle';
                if (summary.failed.length && summary.applied.length) label = 'partial';
                else if (summary.failed.length) label = 'failed';
                else if (summary.applied.length) label = 'applied';
                else if (summary.kept.length) label = 'kept';
                root.setAttribute('data-last-apply', label);
                if (corrected !== null) englishPivot = corrected;
                notify({
                    applied: summary.applied.length > 0,
                    rejected: false,
                    label: label,
                    english: produced,
                    englishApplied: !!(suggestions.en && suggestions.en.apply === true && String(suggestions.en.text || '') !== '')
                });
                return {
                    applied: summary.applied.length > 0,
                    rejected: false,
                    failed: summary.failed,
                    kept: summary.kept,
                    result: result
                };
            }).catch(function () {
                return transportFailed();
            });
        }

        baseInput.addEventListener('input', function () {
            invalidateNow();
            if (roles.base === 'en') applyAutoSlug(baseInput.value);
            if (typeof options.onBaseInput === 'function') options.onBaseInput(baseInput.value, roles.base);
            clearTimeout(timer);
            var snapshot = baseInput.value;
            timer = setTimeout(function () {
                sendSuggest(snapshot, []);
            }, debounceMs);
        });
        q('translations-button').addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            refreshFlag();
        });
        q('translate-button').addEventListener('click', function () {
            clearTimeout(timer);
            sendSuggest(baseInput.value, []);
        });
        if (saveButton) {
            saveButton.addEventListener('click', function () {
                var payload = payloadFromDrawer(baseInput.value, drawer);
                payload.record_id = recordId;
                if (slugInput) payload.slug = slugInput.value;
                if (sortInput) payload.sort_order = parseInt(sortInput.value || '0', 10) || 0;
                if (options.onSave) options.onSave(payload);
            });
        }

        return {
            guard: guard,
            setRoles: function (nextRoles, nextActive) {
                roles = nextRoles;
                active = nextActive || [];
                renderPanel();
            },
            openRecord: function (record) {
                generation += 1;
                clearTimeout(timer);
                invalidateNow();
                failures = {};
                englishPivot = '';
                rowPivot = {};
                recordId = record.id || 0;
                baseInput.value = record.base_text || '';
                if (slugInput) slugInput.value = record.slug || '';
                if (sortInput) sortInput.value = String(record.sort_order || 0);
                autoSlug = true;
                drawer = {};
                known = {};
                var locales = record.locales || {};
                Object.keys(locales).forEach(function (code) {
                    if (code === roles.base) return;
                    var shown = locales[code].text_value || locales[code].text || '';
                    known[code] = {
                        origin: locales[code].origin || '',
                        text: shown,
                        source_locale: locales[code].source_locale || '',
                        source_hash: locales[code].source_hash || ''
                    };
                    if (code === 'en') englishPivot = shown;
                });
                var identity = roles.base === 'en' ? (record.base_text || '') : englishPivot;
                Object.keys(known).forEach(function (code) {
                    var row = known[code];
                    if (row.source_locale === 'en' && row.source_hash && identity !== '' && sha256(identity) === row.source_hash) {
                        rowPivot[code] = identity;
                    }
                });
                renderPanel();
            },
            getPayload: function () {
                var payload = payloadFromDrawer(baseInput.value, drawer);
                if (recordId > 0 && String(baseInput.value || '').trim() === '') {
                    payload.base_explicit_empty = true;
                }
                return payload;
            },
            requestSuggest: function (explicitList) {
                clearTimeout(timer);
                return sendSuggest(baseInput.value, explicitList || []);
            },
            panelHidden: function () {
                return panel.hidden;
            }
        };
    }

    global.OrangeContentLocalePanel = {
        sha256: sha256,
        Guard: Guard,
        panelLocales: panelLocales,
        payloadFromDrawer: payloadFromDrawer,
        mount: mount
    };
}(typeof window !== 'undefined' ? window : globalThis));
