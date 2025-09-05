/**
 * Selector generation utility class
 * Generates stable, unique selectors for DOM elements
 */
class SelectorGenerator {
    constructor(priority = ['data-testid', 'id', 'name']) {
        this.priority = priority;
    }

    /**
     * Generate the best possible selector for an element
     */
    generate(element) {
        if (!element || element.nodeType !== Node.ELEMENT_NODE) {
            return null;
        }

        // Try priority attributes first
        for (const attr of this.priority) {
            const selector = this.tryAttribute(element, attr);
            if (selector && this.isUnique(selector)) {
                return selector;
            }
        }

        // Try common attributes
        const commonSelectors = [
            this.tryAttribute(element, 'data-cy'),
            this.tryAttribute(element, 'data-test'),
            this.tryAttribute(element, 'role'),
            this.tryClass(element),
            this.tryTagWithText(element)
        ].filter(Boolean);

        for (const selector of commonSelectors) {
            if (this.isUnique(selector)) {
                return selector;
            }
        }

        // Fallback to CSS path
        return this.generateCssPath(element);
    }

    /**
     * Try to generate selector using specific attribute
     */
    tryAttribute(element, attribute) {
        const value = element.getAttribute(attribute);
        if (!value) return null;

        switch (attribute) {
            case 'id':
                return `#${this.escapeSelector(value)}`;
            case 'name':
                return `[name="${this.escapeSelector(value)}"]`;
            case 'data-testid':
                return `[data-testid="${this.escapeSelector(value)}"]`;
            case 'data-cy':
                return `[data-cy="${this.escapeSelector(value)}"]`;
            case 'data-test':
                return `[data-test="${this.escapeSelector(value)}"]`;
            case 'role':
                return `[role="${this.escapeSelector(value)}"]`;
            default:
                return `[${attribute}="${this.escapeSelector(value)}"]`;
        }
    }

    /**
     * Try to generate selector using CSS classes
     */
    tryClass(element) {
        const classes = Array.from(element.classList)
            .filter(cls => cls && !cls.startsWith('_') && !cls.includes('random'))
            .slice(0, 3);

        if (classes.length === 0) return null;
        
        return `.${classes.map(cls => this.escapeSelector(cls)).join('.')}`;
    }

    /**
     * Try to generate selector using tag and text content
     */
    tryTagWithText(element) {
        const tag = element.tagName.toLowerCase();
        const text = element.textContent?.trim();
        
        if (!text || text.length > 30) return null;
        
        // For browsers that support :contains() (non-standard)
        return `${tag}:contains("${text.replace(/"/g, '\\"')}")`;
    }

    /**
     * Generate full CSS path as fallback
     */
    generateCssPath(element) {
        const path = [];
        let current = element;

        while (current && current.nodeType === Node.ELEMENT_NODE) {
            let selector = current.tagName.toLowerCase();
            
            if (current.id) {
                selector += `#${this.escapeSelector(current.id)}`;
                path.unshift(selector);
                break;
            }
            
            if (current.className) {
                const classes = Array.from(current.classList)
                    .filter(cls => cls && !cls.startsWith('_'))
                    .slice(0, 2);
                if (classes.length > 0) {
                    selector += `.${classes.map(cls => this.escapeSelector(cls)).join('.')}`;
                }
            }
            
            // Add nth-child if needed for uniqueness
            const siblings = Array.from(current.parentNode?.children || [])
                .filter(sibling => sibling.tagName === current.tagName);
            if (siblings.length > 1) {
                const index = siblings.indexOf(current) + 1;
                selector += `:nth-child(${index})`;
            }
            
            path.unshift(selector);
            current = current.parentElement;
            
            // Limit path depth
            if (path.length >= 5) break;
        }

        return path.join(' > ');
    }

    /**
     * Check if selector is unique in the document
     */
    isUnique(selector) {
        try {
            return document.querySelectorAll(selector).length === 1;
        } catch (e) {
            return false;
        }
    }

    /**
     * Escape CSS selector values
     * Fallback for browsers without CSS.escape()
     */
    escapeSelector(value) {
        if (typeof CSS !== 'undefined' && CSS.escape) {
            return CSS.escape(value);
        }
        
        // Fallback implementation
        return value.replace(/[!"#$%&'()*+,.\/:;<=>?@[\\\]^`{|}~]/g, '\\$&');
    }
}
/**
 * Browser-side recorder for capturing user interactions
 * Records clicks, inputs, and other events for test generation
 * 
 * @class PestRecorder
 * @version 1.0.0
 * @author Pest Plugin Browser Recording
 */
class PestRecorder {
    /**
     * @param {Object} config - Configuration options
     */
    constructor(config = {}) {
        this.config = {
            timeout: 1800,
            autoAssertions: true,
            selectorPriority: ['data-testid', 'id', 'name'],
            includeHoverActions: false,
            captureKeyboardShortcuts: false,
            recordScrollPosition: false,
            ...config
        };
        
        this.actions = [];
        this.isRecording = false;
        this.sessionId = Math.random().toString(36).substr(2, 9);
        
        // Event listener registry for cleanup
        this.eventListeners = new Map();
        
        // Observers for cleanup
        this.mutationObserver = null;
        this.intersectionObserver = null;
        
        // Throttling for high-frequency events
        this.scrollThrottle = this.throttle(this.handleScroll.bind(this), 100);
        this.inputDebounce = this.debounce(this.handleInput.bind(this), 300);
        
        // Initialize selector generation
        this.selectorGenerator = new SelectorGenerator(this.config.selectorPriority);
    }

    /**
     * Start recording user interactions
     */
    start() {
        if (this.isRecording) {
            console.warn('[PestRecorder] Already recording');
            return;
        }

        console.log('[PestRecorder] Starting recording session:', this.sessionId);
        this.isRecording = true;
        
        this.attachEventListeners();
        this.setupMutationObserver();
        this.setupIntersectionObserver();
        
        // Record session start
        this.recordAction('session:start', {
            sessionId: this.sessionId,
            url: window.location.href,
            timestamp: Date.now(),
            viewport: {
                width: window.innerWidth,
                height: window.innerHeight
            },
            userAgent: navigator.userAgent
        });
    }

    /**
     * Stop recording and clean up
     */
    stop() {
        if (!this.isRecording) {
            console.warn('[PestRecorder] Not currently recording');
            return;
        }

        console.log('[PestRecorder] Stopping recording session:', this.sessionId);
        this.isRecording = false;
        
        // Record session end
        this.recordAction('session:end', {
            sessionId: this.sessionId,
            totalActions: this.actions.length,
            timestamp: Date.now()
        });
        
        this.cleanup();
    }

    /**
     * Attach all event listeners using event delegation
     */
    attachEventListeners() {
        // Click events
        this.addEventListener(document, 'click', this.handleClick.bind(this));
        this.addEventListener(document, 'dblclick', this.handleDoubleClick.bind(this));
        this.addEventListener(document, 'contextmenu', this.handleRightClick.bind(this));
        
        // Input events
        this.addEventListener(document, 'input', this.inputDebounce);
        this.addEventListener(document, 'change', this.handleChange.bind(this));
        this.addEventListener(document, 'focus', this.handleFocus.bind(this));
        this.addEventListener(document, 'blur', this.handleBlur.bind(this));
        
        // Form events
        this.addEventListener(document, 'submit', this.handleSubmit.bind(this));
        
        // Navigation events
        this.addEventListener(window, 'beforeunload', this.handleBeforeUnload.bind(this));
        this.addEventListener(window, 'popstate', this.handlePopState.bind(this));
        
        // Monitor history API for SPA navigation
        this.patchHistoryAPI();
        
        // Optional events based on config
        if (this.config.captureKeyboardShortcuts) {
            this.addEventListener(document, 'keydown', this.handleKeydown.bind(this));
        }
        
        if (this.config.recordScrollPosition) {
            this.addEventListener(window, 'scroll', this.scrollThrottle);
        }
        
        if (this.config.includeHoverActions) {
            this.addEventListener(document, 'mouseenter', this.handleMouseEnter.bind(this), true);
            this.addEventListener(document, 'mouseleave', this.handleMouseLeave.bind(this), true);
        }
    }

    /**
     * Add event listener and track for cleanup
     */
    addEventListener(target, event, handler, useCapture = false) {
        target.addEventListener(event, handler, useCapture);
        
        if (!this.eventListeners.has(target)) {
            this.eventListeners.set(target, []);
        }
        this.eventListeners.get(target).push({ event, handler, useCapture });
    }

    /**
     * Record an action and send to PHP process
     */
    recordAction(type, data) {
        if (!this.isRecording) return;

        const action = {
            type,
            data,
            timestamp: Date.now(),
            sessionId: this.sessionId,
            url: window.location.href
        };

        this.actions.push(action);

        // Send to PHP process via polling array
        if (window.__pestRecordingActions) {
            window.__pestRecordingActions.push(action);
        }
        
        console.log('[PestRecorder] Recorded action:', type, data);
    }

    /**
     * Clean up event listeners and observers
     */
    cleanup() {
        // Remove event listeners
        this.eventListeners.forEach((listeners, target) => {
            listeners.forEach(({ event, handler, useCapture }) => {
                target.removeEventListener(event, handler, useCapture);
            });
        });
        this.eventListeners.clear();
        
        // Disconnect observers
        if (this.mutationObserver) {
            this.mutationObserver.disconnect();
            this.mutationObserver = null;
        }
        
        if (this.intersectionObserver) {
            this.intersectionObserver.disconnect();
            this.intersectionObserver = null;
        }
        
        console.log('[PestRecorder] Cleanup completed');
    }

    // Utility methods
    throttle(func, delay) {
        let timeoutId;
        let lastExecTime = 0;
        return function (...args) {
            const currentTime = Date.now();
            
            if (currentTime - lastExecTime > delay) {
                func.apply(this, args);
                lastExecTime = currentTime;
            } else {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => {
                    func.apply(this, args);
                    lastExecTime = Date.now();
                }, delay - (currentTime - lastExecTime));
            }
        };
    }

    debounce(func, delay) {
        let timeoutId;
        return function (...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), delay);
        };
    }
}
/**
 * Event handler methods for PestRecorder
 * These extend the PestRecorder class with comprehensive event handling
 */

// Add event handler methods to PestRecorder prototype
Object.assign(PestRecorder.prototype, {
    /**
     * Handle click events
     */
    handleClick(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('click', {
            selector,
            tagName: event.target.tagName.toLowerCase(),
            text: this.getElementText(event.target),
            coordinates: {
                x: event.clientX,
                y: event.clientY
            },
            modifiers: {
                ctrl: event.ctrlKey,
                shift: event.shiftKey,
                alt: event.altKey,
                meta: event.metaKey
            }
        });
    },

    /**
     * Handle double-click events
     */
    handleDoubleClick(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('dblclick', {
            selector,
            tagName: event.target.tagName.toLowerCase(),
            text: this.getElementText(event.target)
        });
    },

    /**
     * Handle right-click events
     */
    handleRightClick(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('rightclick', {
            selector,
            tagName: event.target.tagName.toLowerCase(),
            text: this.getElementText(event.target)
        });
    },

    /**
     * Handle input events (debounced)
     */
    handleInput(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        const value = this.sanitizeValue(event.target.value, event.target.type);
        
        this.recordAction('input', {
            selector,
            value,
            inputType: event.target.type,
            tagName: event.target.tagName.toLowerCase()
        });
    },

    /**
     * Handle change events (select, checkbox, radio)
     */
    handleChange(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        const target = event.target;
        
        let data = {
            selector,
            tagName: target.tagName.toLowerCase(),
            type: target.type
        };
        
        if (target.type === 'checkbox' || target.type === 'radio') {
            data.checked = target.checked;
            data.value = target.value;
        } else if (target.tagName.toLowerCase() === 'select') {
            data.value = target.value;
            data.selectedIndex = target.selectedIndex;
            data.selectedText = target.options[target.selectedIndex]?.text;
        } else {
            data.value = this.sanitizeValue(target.value, target.type);
        }
        
        this.recordAction('change', data);
    },

    /**
     * Handle focus events
     */
    handleFocus(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('focus', {
            selector,
            tagName: event.target.tagName.toLowerCase()
        });
    },

    /**
     * Handle blur events
     */
    handleBlur(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('blur', {
            selector,
            tagName: event.target.tagName.toLowerCase()
        });
    },

    /**
     * Handle form submission
     */
    handleSubmit(event) {
        if (!this.isRecording) return;
        
        const form = event.target;
        const selector = this.selectorGenerator.generate(form);
        
        // Capture form data
        const formData = new FormData(form);
        const data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = this.sanitizeValue(value);
        }
        
        this.recordAction('submit', {
            selector,
            action: form.action,
            method: form.method,
            data: data
        });
    },

    /**
     * Handle keyboard shortcuts
     */
    handleKeydown(event) {
        if (!this.isRecording) return;
        
        // Only record significant keyboard shortcuts
        if (event.ctrlKey || event.metaKey || event.altKey) {
            this.recordAction('keydown', {
                key: event.key,
                code: event.code,
                modifiers: {
                    ctrl: event.ctrlKey,
                    shift: event.shiftKey,
                    alt: event.altKey,
                    meta: event.metaKey
                }
            });
        }
    },

    /**
     * Handle scroll events (throttled)
     */
    handleScroll(event) {
        if (!this.isRecording) return;
        
        this.recordAction('scroll', {
            scrollX: window.scrollX,
            scrollY: window.scrollY,
            target: event.target === document ? 'document' : this.selectorGenerator.generate(event.target)
        });
    },

    /**
     * Handle mouse enter events
     */
    handleMouseEnter(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('hover', {
            selector,
            action: 'enter'
        });
    },

    /**
     * Handle mouse leave events
     */
    handleMouseLeave(event) {
        if (!this.isRecording || this.shouldIgnoreElement(event.target)) return;
        
        const selector = this.selectorGenerator.generate(event.target);
        
        this.recordAction('hover', {
            selector,
            action: 'leave'
        });
    },

    /**
     * Handle before unload
     */
    handleBeforeUnload(event) {
        if (!this.isRecording) return;
        
        this.recordAction('beforeunload', {
            url: window.location.href
        });
    },

    /**
     * Handle browser back/forward navigation
     */
    handlePopState(event) {
        if (!this.isRecording) return;
        
        this.recordAction('navigation', {
            type: 'popstate',
            url: window.location.href,
            state: event.state
        });
    },

    /**
     * Setup MutationObserver for dynamic content
     */
    setupMutationObserver() {
        this.mutationObserver = new MutationObserver((mutations) => {
            if (!this.isRecording) return;
            
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    // Record significant DOM changes
                    const addedElements = Array.from(mutation.addedNodes)
                        .filter(node => node.nodeType === Node.ELEMENT_NODE)
                        .filter(element => this.isSignificantElement(element));
                    
                    if (addedElements.length > 0) {
                        this.recordAction('dom:added', {
                            count: addedElements.length,
                            target: this.selectorGenerator.generate(mutation.target),
                            elements: addedElements.map(el => ({
                                tagName: el.tagName.toLowerCase(),
                                selector: this.selectorGenerator.generate(el)
                            }))
                        });
                    }
                }
            });
        });
        
        this.mutationObserver.observe(document.body, {
            childList: true,
            subtree: true
        });
    },

    /**
     * Setup IntersectionObserver for viewport tracking
     */
    setupIntersectionObserver() {
        this.intersectionObserver = new IntersectionObserver((entries) => {
            if (!this.isRecording) return;
            
            entries.forEach((entry) => {
                if (this.isSignificantElement(entry.target)) {
                    this.recordAction('visibility', {
                        selector: this.selectorGenerator.generate(entry.target),
                        visible: entry.isIntersecting,
                        intersectionRatio: entry.intersectionRatio
                    });
                }
            });
        }, {
            threshold: [0, 0.5, 1]
        });
        
        // Observe significant elements
        document.querySelectorAll('button, a, input, select, textarea, [role="button"]')
            .forEach(el => this.intersectionObserver.observe(el));
    },

    /**
     * Patch History API to capture SPA navigation
     */
    patchHistoryAPI() {
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;
        
        history.pushState = (...args) => {
            if (this.isRecording) {
                this.recordAction('navigation', {
                    type: 'pushstate',
                    url: args[2] || window.location.href,
                    state: args[0]
                });
            }
            return originalPushState.apply(history, args);
        };
        
        history.replaceState = (...args) => {
            if (this.isRecording) {
                this.recordAction('navigation', {
                    type: 'replacestate',
                    url: args[2] || window.location.href,
                    state: args[0]
                });
            }
            return originalReplaceState.apply(history, args);
        };
    },

    /**
     * Helper methods
     */
    
    /**
     * Check if element should be ignored
     */
    shouldIgnoreElement(element) {
        // Ignore script tags, style tags, and recorder-related elements
        const ignoredTags = ['SCRIPT', 'STYLE', 'META', 'LINK', 'TITLE'];
        if (ignoredTags.includes(element.tagName)) return true;
        
        // Ignore elements with specific classes or data attributes
        if (element.classList.contains('pest-recorder-ignore')) return true;
        if (element.dataset.pestIgnore) return true;
        
        return false;
    },

    /**
     * Check if element is significant for tracking
     */
    isSignificantElement(element) {
        const significantTags = ['BUTTON', 'A', 'INPUT', 'SELECT', 'TEXTAREA', 'FORM'];
        return significantTags.includes(element.tagName) || 
               element.hasAttribute('role') || 
               element.hasAttribute('data-testid');
    },

    /**
     * Get text content of element (truncated)
     */
    getElementText(element) {
        let text = element.textContent || element.value || element.placeholder || '';
        return text.trim().substring(0, 100);
    },

    /**
     * Sanitize sensitive values
     */
    sanitizeValue(value, inputType = 'text') {
        if (typeof value !== 'string') return value;
        
        // Mask password fields
        if (inputType === 'password') {
            return '*'.repeat(value.length);
        }
        
        // Mask potentially sensitive fields based on name/id patterns
        const sensitivePatterns = /password|ssn|social|credit|card|cvv|pin/i;
        if (sensitivePatterns.test(value)) {
            return '*'.repeat(value.length);
        }
        
        return value;
    }
});
