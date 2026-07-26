/**
 * Per-gap instant visual feedback for cloze questions.
 *
 * This AMD module computes HMAC-SHA256 hashes of student input
 * client-side and compares them against pre-computed salted hashes
 * of correct answers. No additional server requests are made after
 * the initial page load.
 *
 * Visual feedback is applied via CSS classes, styled by default with
 * background color and border:
 *   - .gapcheck-correct:   green background + solid border
 *   - .gapcheck-partial:   amber background + dashed border
 *   - .gapcheck-incorrect: red background   + dotted border
 *
 * All styles use CSS custom properties so they can be overridden
 * via Additional HTML without touching plugin code.
 *
 * Accessibility is supported via aria-invalid and title attributes.
 *
 * @module qbehaviour_gapcheck/gapcheck
 * @copyright 2026 Matthias Giger
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @example <caption>Enable debug logging from the browser console</caption>
 * // Set this flag to true before the module initialises, or toggle
 * // it at any time to see [gapcheck] log output:
 * window.gapcheckDebug = true;
 *
 * // You can also run code to see all fields that match:
 * // require(['qbehaviour_gapcheck/gapcheck'], function(m) { m.setDebug(true); });
 */
define(['core/notification'], function(Notification) {

    /**
     * Debug logging flag.
     *
     * Set to `true` to enable [gapcheck] console.log output.
     * Can be toggled at runtime via `gapcheckDebug` global or
     * by calling the exported `setDebug()` function.
     * @type {boolean}
     */
    var debug = (typeof window !== 'undefined' && window.gapcheckDebug === true);

    /** @type {WeakMap<Element, {data: Object, salt: string}>} */
    var fieldDataSet = new WeakMap();

    /** Whether the prototype value interceptor has been installed. */
    var interceptorInstalled = false;

    /**
     * Install a single interceptor on HTMLInputElement.prototype.value
     * so any programmatic `input.value = '...'` triggers validation.
     * Field data is looked up in `fieldDataSet` keyed by the element.
     */
    function setupValueInterceptor() {
        if (interceptorInstalled) {
            return;
        }
        interceptorInstalled = true;
        var desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
        if (!desc || !desc.configurable) {
            return;
        }
        var nativeSet = desc.set;
        Object.defineProperty(HTMLInputElement.prototype, 'value', {
            get: function() { return desc.get.call(this); },
            set: function(val) {
                nativeSet.call(this, val);
                var entry = fieldDataSet.get(this);
                if (entry) {
                    validateField(this, entry.data, entry.salt);
                }
            },
            configurable: true,
            enumerable: true,
        });
    }

    /**
     * Conditionally log a debug message.
     *
     * Only writes to the console when `debug` is true.
     * Accepts the same arguments as console.log.
     */
    function log() {
        if (debug) {
            console.log.apply(console, arguments);
        }
    }

    /**
     * Compute HMAC-SHA256 hex digest using the Web Crypto API.
     *
     * @param {string} key the HMAC secret key (salt)
     * @param {string} message the plaintext to hash
     * @returns {Promise<string>} 64-character hex digest
     */
    function hmacSha256(key, message) {
        var encoder = new TextEncoder();
        return crypto.subtle.importKey(
            'raw',
            encoder.encode(key),
            {name: 'HMAC', hash: 'SHA-256'},
            false,
            ['sign']
        ).then(function(cryptoKey) {
            return crypto.subtle.sign('HMAC', cryptoKey, encoder.encode(message));
        }).then(function(signature) {
            return Array.from(new Uint8Array(signature))
                .map(function(b) { return b.toString(16).padStart(2, '0'); })
                .join('');
        });
    }

    /**
     * Create a debounced version of a function.
     *
     * The function is called after `delay` milliseconds of inactivity.
     *
     * @param {Function} fn the function to debounce
     * @param {number} delay delay in milliseconds
     * @returns {Function} debounced wrapper
     */
    function debounce(fn, delay) {
        var timer;
        return function() {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(context, args);
            }, delay);
        };
    }

    /**
     * Apply visual feedback state to an input element.
     *
     * Toggles CSS classes only — background color is determined
     * by the stylesheet, making it easy to override via site-level
     * custom CSS without !important.
     *
     * @param {HTMLInputElement} input the input element
     * @param {string} status 'full', 'partial', or 'none'
     */
    function setInputState(input, status) {
        input.classList.remove('gapcheck-correct', 'gapcheck-partial', 'gapcheck-incorrect');
        if (status === 'full') {
            input.classList.add('gapcheck-correct');
            input.setAttribute('aria-invalid', 'false');
            input.title = input.getAttribute('data-gapcheck-label-correct') || 'Correct';
        } else if (status === 'partial') {
            input.classList.add('gapcheck-partial');
            input.setAttribute('aria-invalid', 'false');
            input.title = input.getAttribute('data-gapcheck-label-partial') || 'Partially correct';
        } else {
            input.classList.add('gapcheck-incorrect');
            input.setAttribute('aria-invalid', 'true');
            input.title = input.getAttribute('data-gapcheck-label-incorrect') || 'Incorrect';
        }
    }

    /**
     * Remove all visual feedback state from an input element.
     *
     * @param {HTMLInputElement} input the input element
     */
    function clearInputState(input) {
        input.classList.remove('gapcheck-correct', 'gapcheck-partial', 'gapcheck-incorrect');
        input.removeAttribute('aria-invalid');
        input.removeAttribute('title');
    }

    /**
     * Check student input against numerical tolerance entries.
     *
     * Compares the parsed float value against each tolerance entry,
     * considering absolute (type 0) and relative (type 1) tolerance.
     * Returns the best matching status.
     *
     * @param {string} value the student's raw input
     * @param {Array} entries array of tolerance objects {v, t, k, f}
     * @returns {string|null} 'full', 'partial', or null if no match
     */
    function checkNumerical(value, entries) {
        var studentNum = parseFloat(value.replace(/,/g, '.'));
        if (isNaN(studentNum)) {
            return null;
        }

        var bestFraction = 0;
        for (var i = 0; i < entries.length; i++) {
            var entry = entries[i];
            var correctNum = parseFloat(entry.v);
            if (isNaN(correctNum)) {
                continue;
            }

            var diff = Math.abs(studentNum - correctNum);
            var tol = entry.t || 0;
            if (entry.k === 1) {
                tol = tol * Math.abs(correctNum) / 100;
            }

            if (diff <= tol && entry.f > bestFraction) {
                bestFraction = entry.f;
            }
        }

        if (bestFraction >= 1.0) {
            return 'full';
        } else if (bestFraction > 0) {
            return 'partial';
        }
        return null;
    }

    /**
     * Normalize a value to an array.
     *
     * @param {*} value string, array, or undefined
     * @returns {Array} array representation
     */
    function toArray(value) {
        if (Array.isArray(value)) {
            return value;
        }
        if (typeof value === 'string') {
            return [value];
        }
        return [];
    }

    /**
     * Validate a single input field against its hash data.
     *
     * First tries a synchronous numerical tolerance check. If that
     * does not match (or no tolerance data exists), performs an
     * async HMAC-SHA256 comparison against full and partial credit
     * hashes.
     *
     * @param {HTMLInputElement} input the input element
     * @param {Object} fieldData hash entry {h, p, n, ci} or legacy array
     * @param {string} salt the HMAC salt
     */
    function validateField(input, fieldData, salt) {
        var fieldName = input.getAttribute('name');
        if (!fieldName || !fieldData) {
            return;
        }

        var value = input.value.trim();

        if (value === '') {
            clearInputState(input);
            return;
        }

        var h = [], p = [], n = null;
        if (typeof fieldData === 'object' && !Array.isArray(fieldData) && fieldData.h !== undefined) {
            h = toArray(fieldData.h);
            p = toArray(fieldData.p);
            n = fieldData.n || null;
        } else {
            h = toArray(fieldData);
        }

        if (n && n.length > 0) {
            var numResult = checkNumerical(value, n);
            if (numResult !== null) {
                setInputState(input, numResult);
                return;
            }
        }

        var compareValue = (fieldData.ci) ? value.toLowerCase() : value;
        hmacSha256(salt, compareValue).then(function(computedHash) {
            log('[gapcheck] field', fieldName, 'value:', value, 'compare:', compareValue, 'hash:', computedHash, 'full:', h, 'partial:', p, 'ci:', fieldData.ci);
            if (h.indexOf(computedHash) !== -1) {
                setInputState(input, 'full');
            } else if (p.indexOf(computedHash) !== -1) {
                setInputState(input, 'partial');
            } else {
                setInputState(input, 'none');
            }
        }).catch(function(err) {
            clearInputState(input);
            Notification.exception(err);
        });
    }

    /**
     * Attach blur, change, input (debounced), and initial-validation
     * events to an input field.
     *
     * Also intercepts the native value setter so that programmatic
     * changes (e.g. gapfill drag-and-drop) trigger validation
     * immediately — no DOM event is required.
     *
     * A `_gapcheckAttached` flag prevents double-attachment.
     *
     * @param {HTMLInputElement} input the input element
     * @param {Object} fieldData hash entry for this field
     * @param {string} salt the HMAC salt
     */
    function attachFieldEvents(input, fieldData, salt) {
        if (input._gapcheckAttached) {
            return;
        }
        input._gapcheckAttached = true;

        if (input instanceof HTMLInputElement) {
            fieldDataSet.set(input, {data: fieldData, salt: salt});
            setupValueInterceptor();
        }

        var debouncedValidate = debounce(function() {
            validateField(input, fieldData, salt);
        }, 400);

        input.addEventListener('blur', function() {
            validateField(input, fieldData, salt);
        });

        input.addEventListener('change', function() {
            validateField(input, fieldData, salt);
        });

        input.addEventListener('input', debouncedValidate);

        if (input.value.trim() !== '') {
            validateField(input, fieldData, salt);
        }
    }

    return {
        /**
         * Enable or disable debug logging at runtime.
         * @param {boolean} val true to enable console logging
         */
        setDebug: function(val) {
            debug = !!val;
            log('[gapcheck] debug logging', debug ? 'enabled' : 'disabled');
        },

        /**
         * Initialize gapcheck for a given outer question container.
         *
         * Reads the embedded JSON hash map from the
         * `.pergapcheck-hashmap` element, then attaches validation
         * events to all text/number input and select elements whose
         * names appear in the hash data.
         *
         * @param {string} outerDivId the ID of the question container
         */
        init: function(outerDivId) {
            log('[gapcheck] init called, outerDivId:', outerDivId);
            if (!window.crypto || !window.crypto.subtle) {
                log('[gapcheck] crypto.subtle unavailable (requires HTTPS)');
                return;
            }
            var outerDiv = document.getElementById(outerDivId);
            if (!outerDiv) {
                return;
            }

            var container = outerDiv.querySelector('.pergapcheck-hashmap');
            if (!container) {
                return;
            }

            var hashes, salt;
            try {
                hashes = JSON.parse(container.getAttribute('data-pergap-hashes'));
                salt = container.getAttribute('data-pergap-salt');
            } catch (e) {
                return;
            }

            if (!hashes || !salt || Object.keys(hashes).length === 0) {
                log('[gapcheck] no hash data for any field');
                return;
            }

            var correctLabel = container.getAttribute('data-gapcheck-label-correct') || 'Correct';
            var partialLabel = container.getAttribute('data-gapcheck-label-partial') || 'Partially correct';
            var incorrectLabel = container.getAttribute('data-gapcheck-label-incorrect') || 'Incorrect';

            var inputs = outerDiv.querySelectorAll('input[type="text"], input[type="number"], input:not([type]), select');
            inputs.forEach(function(input) {
                var name = input.getAttribute('name');
                if (name && hashes[name] !== undefined) {
                    input.setAttribute('data-gapcheck-label-correct', correctLabel);
                    input.setAttribute('data-gapcheck-label-partial', partialLabel);
                    input.setAttribute('data-gapcheck-label-incorrect', incorrectLabel);
                    log('[gapcheck] attaching events for field:', name, 'data:', JSON.stringify(hashes[name]));
                    attachFieldEvents(input, hashes[name], salt);
                } else if (name) {
                    log('[gapcheck] no hash data for field:', name);
                }
            });
        }
    };
});
