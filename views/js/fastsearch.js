/**
 * FastSearch JavaScript Module
 * Zaawansowana logika wyszukiwarki dla PrestaShop
 * 
 * @author    FastSearch Team
 * @version   1.0.0
 * @copyright 2025 FastSearch
 * @license   MIT License
 */

(function(window, document, undefined) {
    'use strict';

    /**
     * FastSearch Main Class
     */
    class FastSearch {
        constructor(config = {}) {
            // Default configuration
            this.config = {
                searchUrl: '/module/fastsearch/search',
                minQueryLength: 2,
                maxResults: 15,
                debounceTime: 150,
                cacheTime: 30 * 60 * 1000, // 30 minutes
                maxRecentSearches: 10,
                showImages: true,
                showPrices: true,
                showDescriptions: true,
                enableVoiceSearch: false,
                enableAnalytics: true,
                enableKeyboard: true,
                enableCache: true,
                animationDuration: 200,
                ...config
            };

            // DOM elements
            this.elements = {
                wrapper: null,
                input: null,
                results: null,
                loader: null,
                clearBtn: null,
                voiceBtn: null,
                overlay: null,
                quickFilters: null
            };

            // State management
            this.state = {
                isOpen: false,
                isLoading: false,
                isListening: false,
                currentQuery: '',
                selectedIndex: -1,
                lastResults: [],
                hasResults: false,
                filters: {},
                requestId: 0
            };

            // Cache and storage
            this.cache = new Map();
            this.recentSearches = this.loadRecentSearches();
            
            // API and requests
            this.currentRequest = null;
            this.searchTimeout = null;
            
            // Voice recognition
            this.recognition = null;
            
            // Templates
            this.templates = {
                product: null,
                suggestion: null,
                noResults: null,
                loading: null,
                error: null
            };

            // Event handlers (bound to this context)
            this.handlers = {
                input: this.handleInput.bind(this),
                focus: this.handleFocus.bind(this),
                blur: this.handleBlur.bind(this),
                keydown: this.handleKeydown.bind(this),
                clear: this.handleClear.bind(this),
                voice: this.handleVoiceSearch.bind(this),
                clickOutside: this.handleClickOutside.bind(this),
                resize: this.handleResize.bind(this),
                scroll: this.handleScroll.bind(this)
            };

            this.init();
        }

        /**
         * Initialize FastSearch
         */
        init() {
            try {
                this.initializeElements();
                this.loadTemplates();
                this.setupEventListeners();
                this.initializeVoiceSearch();
                this.initializeCache();
                this.setupPerformanceMonitoring();
                
                this.log('FastSearch initialized successfully');
                this.trigger('initialized', { config: this.config });
            } catch (error) {
                this.error('Failed to initialize FastSearch:', error);
            }
        }

        /**
         * Initialize DOM elements
         */
        initializeElements() {
            this.elements.wrapper = document.getElementById('fastsearch-container');
            if (!this.elements.wrapper) {
                throw new Error('FastSearch container not found');
            }

            this.elements.input = document.getElementById('fastsearch-input');
            this.elements.results = document.getElementById('fastsearch-results');
            this.elements.loader = document.getElementById('fastsearch-loader');
            this.elements.clearBtn = document.getElementById('fastsearch-clear');
            this.elements.voiceBtn = document.getElementById('fastsearch-voice');
            this.elements.overlay = document.getElementById('fastsearch-overlay');
            this.elements.quickFilters = document.querySelector('.fastsearch-quick-filters');

            // Validate required elements
            if (!this.elements.input || !this.elements.results) {
                throw new Error('Required FastSearch elements not found');
            }

            // Set ARIA attributes
            this.elements.input.setAttribute('aria-owns', 'fastsearch-results');
            this.elements.results.setAttribute('aria-live', 'polite');
        }

        /**
         * Load HTML templates
         */
        loadTemplates() {
            const templateIds = [
                'fastsearch-product-template',
                'fastsearch-suggestion-template',
                'fastsearch-no-results-template',
                'fastsearch-loading-template',
                'fastsearch-error-template'
            ];

            templateIds.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    const key = id.replace('fastsearch-', '').replace('-template', '');
                    this.templates[key] = this.compileTemplate(element.innerHTML);
                }
            });
        }

        /**
         * Compile Underscore.js style template
         */
        compileTemplate(source) {
            const template = source
                .replace(/<%=\s*(.+?)\s*%>/g, '${$1}')
                .replace(/<%\s*(.+?)\s*%>/g, '`; $1; html += `')
                .replace(/<%-\s*(.+?)\s*%>/g, '${this.escapeHtml($1)}');

            return new Function('data', `
                const { ${Object.keys(this.config).join(', ')} } = this.config;
                const { escapeHtml, formatCurrency, formatDate } = this;
                let html = \`${template}\`;
                return html;
            `).bind(this);
        }

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Input events
            this.elements.input.addEventListener('input', this.handlers.input);
            this.elements.input.addEventListener('focus', this.handlers.focus);
            this.elements.input.addEventListener('blur', this.handlers.blur);
            this.elements.input.addEventListener('keydown', this.handlers.keydown);

            // Button events
            if (this.elements.clearBtn) {
                this.elements.clearBtn.addEventListener('click', this.handlers.clear);
            }
            
            if (this.elements.voiceBtn) {
                this.elements.voiceBtn.addEventListener('click', this.handlers.voice);
            }

            // Overlay events
            if (this.elements.overlay) {
                this.elements.overlay.addEventListener('click', this.handlers.clickOutside);
            }

            // Global events
            document.addEventListener('click', this.handlers.clickOutside);
            window.addEventListener('resize', this.debounce(this.handlers.resize, 100));
            window.addEventListener('scroll', this.throttle(this.handlers.scroll, 16));

            // Results container events
            this.elements.results.addEventListener('click', this.handleResultClick.bind(this));
            this.elements.results.addEventListener('mouseenter', this.handleResultHover.bind(this), true);
        }

        /**
         * Handle input events
         */
        handleInput(event) {
            const query = event.target.value.trim();
            
            // Update clear button visibility
            this.toggleClearButton(query.length > 0);
            
            // Clear timeout
            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
            }
            
            // Reset selection
            this.state.selectedIndex = -1;
            this.updateSelection();
            
            // Handle empty query
            if (query.length === 0) {
                this.state.currentQuery = '';
                this.showRecentSearches();
                return;
            }
            
            // Check minimum length
            if (query.length < this.config.minQueryLength) {
                this.hideResults();
                return;
            }
            
            // Debounced search
            this.searchTimeout = setTimeout(() => {
                this.search(query);
            }, this.config.debounceTime);
        }

        /**
         * Handle focus events
         */
        handleFocus() {
            const query = this.elements.input.value.trim();
            
            if (query.length >= this.config.minQueryLength) {
                this.showResults();
            } else if (this.recentSearches.length > 0) {
                this.showRecentSearches();
            }
            
            this.elements.wrapper.classList.add('fastsearch-focused');
        }

        /**
         * Handle blur events (with delay for click handling)
         */
        handleBlur() {
            setTimeout(() => {
                if (!this.elements.wrapper.contains(document.activeElement)) {
                    this.hideResults();
                    this.elements.wrapper.classList.remove('fastsearch-focused');
                }
            }, 150);
        }

        /**
         * Handle keyboard navigation
         */
        handleKeydown(event) {
            if (!this.config.enableKeyboard || !this.state.isOpen) {
                return;
            }

            const items = this.elements.results.querySelectorAll(
                '.fastsearch-product, .fastsearch-suggestion'
            );

            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    this.state.selectedIndex = Math.min(
                        this.state.selectedIndex + 1, 
                        items.length - 1
                    );
                    this.updateSelection();
                    this.scrollToSelected();
                    break;

                case 'ArrowUp':
                    event.preventDefault();
                    this.state.selectedIndex = Math.max(
                        this.state.selectedIndex - 1, 
                        -1
                    );
                    this.updateSelection();
                    this.scrollToSelected();
                    break;

                case 'Enter':
                    event.preventDefault();
                    this.selectCurrentItem();
                    break;

                case 'Escape':
                    event.preventDefault();
                    this.hideResults();
                    this.elements.input.blur();
                    break;

                case 'Tab':
                    if (this.state.selectedIndex >= 0) {
                        event.preventDefault();
                        this.selectCurrentItem();
                    }
                    break;
            }
        }

        /**
         * Handle clear button
         */
        handleClear() {
            this.elements.input.value = '';
            this.elements.input.focus();
            this.state.currentQuery = '';
            this.toggleClearButton(false);
            this.showRecentSearches();
            this.trigger('cleared');
        }

        /**
         * Handle voice search
         */
        handleVoiceSearch() {
            if (!this.recognition || this.state.isListening) {
                return;
            }

            try {
                this.state.isListening = true;
                this.elements.voiceBtn.classList.add('listening');
                this.recognition.start();
                this.trigger('voiceStarted');
            } catch (error) {
                this.error('Voice search error:', error);
                this.state.isListening = false;
                this.elements.voiceBtn.classList.remove('listening');
            }
        }

        /**
         * Handle click outside
         */
        handleClickOutside(event) {
            if (!this.elements.wrapper.contains(event.target)) {
                this.hideResults();
            }
        }

        /**
         * Handle window resize
         */
        handleResize() {
            if (this.state.isOpen) {
                this.positionResults();
            }
        }

        /**
         * Handle scroll
         */
        handleScroll() {
            if (this.state.isOpen && window.innerWidth <= 768) {
                this.positionResults();
            }
        }

        /**
         * Handle result clicks
         */
        handleResultClick(event) {
            const product =.target.closest('.fastsearch-product');
            const suggestion = event.target.closest('.fastsearch-suggestion');
            const action = event.target.closest('[data-action]');

            if (action) {
                event.preventDefault();
                this.handleAction(action.dataset.action, action);
                return;
            }

            if (product) {
                const productId = parseInt(product.dataset.productId);
                const position = parseInt(product.dataset.position);
                this.trackEvent('click', this.state.currentQuery, productId, position);
                // Let the default link behavior handle navigation
            }

            if (suggestion) {
                event.preventDefault();
                const query = suggestion.dataset.suggestion;
                this.selectSuggestion(query);
            }
        }

        /**
         * Handle result hover
         */
        handleResultHover(event) {
            const item = event.target.closest('.fastsearch-product, .fastsearch-suggestion');
            if (item) {
                const items = this.elements.results.querySelectorAll(
                    '.fastsearch-product, .fastsearch-suggestion'
                );
                this.state.selectedIndex = Array.from(items).indexOf(item);
                this.updateSelection();
            }
        }

        /**
         * Handle various actions
         */
        handleAction(action, element) {
            switch (action) {
                case 'add-to-cart':
                    this.addToCart(element);
                    break;
                case 'view':
                    this.viewProduct(element);
                    break;
                case 'load-more':
                    this.loadMore();
                    break;
                case 'clear-recent':
                    this.clearRecentSearches();
                    break;
                default:
                    this.log('Unknown action:', action);
            }
        }

        /**
         * Main search function
         */
        async search(query, options = {}) {
            if (!query || query.length < this.config.minQueryLength) {
                return;
            }

            const searchOptions = {
                limit: this.config.maxResults,
                offset: 0,
                ...this.state.filters,
                ...options
            };

            // Check cache first
            const cacheKey = this.getCacheKey(query, searchOptions);
            if (this.config.enableCache && this.cache.has(cacheKey)) {
                const cached = this.cache.get(cacheKey);
                if (Date.now() - cached.timestamp < this.config.cacheTime) {
                    this.displayResults(cached.data, query);
                    return cached.data;
                }
            }

            // Cancel previous request
            if (this.currentRequest) {
                this.currentRequest.abort();
            }

            // Show loading state
            this.setLoadingState(true);
            this.state.currentQuery = query;

            try {
                const requestId = ++this.state.requestId;
                const startTime = performance.now();

                // Build request URL
                const url = new URL(this.config.searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('action', 'search');
                
                Object.entries(searchOptions).forEach(([key, value]) => {
                    if (value !== null && value !== undefined && value !== '') {
                        url.searchParams.set(key, value);
                    }
                });

                // Create request
                this.currentRequest = new AbortController();
                const response = await fetch(url.toString(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    signal: this.currentRequest.signal
                });

                // Check if request is still current
                if (requestId !== this.state.requestId) {
                    return;
                }

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();
                const endTime = performance.now();

                // Cache results
                if (this.config.enableCache) {
                    this.cache.set(cacheKey, {
                        data,
                        timestamp: Date.now()
                    });
                }

                // Display results
                this.displayResults(data, query);
                
                // Add to recent searches
                this.addToRecentSearches(query, data.total || 0);

                // Track search
                if (this.config.enableAnalytics) {
                    this.trackSearch(query, data.total || 0, endTime - startTime);
                }

                this.trigger('searchCompleted', { query, data, executionTime: endTime - startTime });
                return data;

            } catch (error) {
                if (error.name === 'AbortError') {
                    return; // Request was cancelled
                }

                this.error('Search error:', error);
                this.displayError(error.message);
                this.trigger('searchError', { query, error });
            } finally {
                this.setLoadingState(false);
                this.currentRequest = null;
            }
        }

        /**
         * Display search results
         */
        displayResults(data, query) {
            if (!data || !data.products) {
                this.displayError('Invalid response format');
                return;
            }

            const { products, total, suggestions } = data;
            
            this.state.lastResults = products;
            this.state.hasResults = products.length > 0;

            if (products.length === 0) {
                this.displayNoResults(query, suggestions);
            } else {
                this.renderProducts(products, query, data);
            }

            this.showResults();
            this.updateAriaLive(`${products.length} results found for ${query}`);
        }

        /**
         * Render products
         */
        renderProducts(products, query, data) {
            if (!this.templates.product) {
                this.error('Product template not found');
                return;
            }

            let html = '';
            
            products.forEach((product, index) => {
                try {
                    html += this.templates.product({
                        ...product,
                        position: index,
                        query: query,
                        show_images: this.config.showImages,
                        show_prices: this.config.showPrices,
                        show_descriptions: this.config.showDescriptions,
                        fallback_image: this.config.fallbackImage
                    });
                } catch (error) {
                    this.error('Error rendering product:', error, product);
                }
            });

            // Add footer if needed
            if (data.has_more || data.total > this.config.maxResults) {
                html += this.renderResultsFooter(data, query);
            }

            this.elements.results.innerHTML = html;
        }

        /**
         * Display no results
         */
        displayNoResults(query, suggestions = []) {
            if (!this.templates.noResults) {
                this.elements.results.innerHTML = `
                    <div class="fastsearch-no-results">
                        <p>No products found for "${this.escapeHtml(query)}"</p>
                    </div>
                `;
                return;
            }

            const html = this.templates.noResults({
                query: query,
                suggestions: suggestions
            });

            this.elements.results.innerHTML = html;
        }

        /**
         * Display error
         */
        displayError(message) {
            if (!this.templates.error) {
                this.elements.results.innerHTML = `
                    <div class="fastsearch-error">
                        <p>Search temporarily unavailable</p>
                        <button onclick="FastSearch.retry()">Try again</button>
                    </div>
                `;
                return;
            }

            const html = this.templates.error({
                message: message
            });

            this.elements.results.innerHTML = html;
        }

        /**
         * Show recent searches
         */
        showRecentSearches() {
            if (this.recentSearches.length === 0) {
                this.hideResults();
                return;
            }

            let html = '<div class="fastsearch-recent-searches">';
            html += '<div class="fastsearch-section-header">';
            html += '<h3 class="fastsearch-section-title">';
            html += '<i class="material-icons">history</i>';
            html += 'Recent searches';
            html += '</h3>';
            html += '<button class="fastsearch-clear-all" data-action="clear-recent">Clear all</button>';
            html += '</div>';

            this.recentSearches.slice(0, 5).forEach(search => {
                html += `
                    <div class="fastsearch-suggestion" data-suggestion="${this.escapeHtml(search.query)}">
                        <div class="fastsearch-suggestion-icon">
                            <i class="material-icons">history</i>
                        </div>
                        <div class="fastsearch-suggestion-content">
                            <span class="fastsearch-suggestion-text">${this.escapeHtml(search.query)}</span>
                            <small class="fastsearch-suggestion-message">
                                ${search.resultsCount} results
                            </small>
                        </div>
                        <button class="fastsearch-suggestion-remove" data-query="${this.escapeHtml(search.query)}">
                            <i class="material-icons">close</i>
                        </button>
                    </div>
                `;
            });

            html += '</div>';
            this.elements.results.innerHTML = html;
            this.showResults();
        }

        /**
         * Show/hide results
         */
        showResults() {
            if (this.state.isOpen) return;

            this.state.isOpen = true;
            this.elements.results.style.display = 'block';
            this.elements.results.classList.add('show');
            
            if (this.elements.overlay) {
                this.elements.overlay.style.display = 'block';
                this.elements.overlay.classList.add('show');
            }

            this.elements.input.setAttribute('aria-expanded', 'true');
            this.elements.wrapper.classList.add('fastsearch-active');
            
            this.positionResults();
            this.trigger('resultsShown');
        }

        hideResults() {
            if (!this.state.isOpen) return;

            this.state.isOpen = false;
            this.state.selectedIndex = -1;
            
            this.elements.results.classList.remove('show');
            
            if (this.elements.overlay) {
                this.elements.overlay.classList.remove('show');
            }

            this.elements.input.setAttribute('aria-expanded', 'false');
            this.elements.wrapper.classList.remove('fastsearch-active');

            setTimeout(() => {
                if (!this.state.isOpen) {
                    this.elements.results.style.display = 'none';
                    if (this.elements.overlay) {
                        this.elements.overlay.style.display = 'none';
                    }
                }
            }, this.config.animationDuration);

            this.trigger('resultsHidden');
        }

        /**
         * Position results container
         */
        positionResults() {
            if (window.innerWidth <= 768) {
                // Mobile: fixed positioning handled by CSS
                return;
            }

            const inputRect = this.elements.input.getBoundingClientRect();
            const resultsRect = this.elements.results.getBoundingClientRect();
            const viewportHeight = window.innerHeight;

            // Check if results would overflow viewport
            if (inputRect.bottom + resultsRect.height > viewportHeight - 20) {
                // Position above input
                this.elements.results.style.top = 'auto';
                this.elements.results.style.bottom = '100%';
                this.elements.results.style.marginTop = '0';
                this.elements.results.style.marginBottom = '8px';
            } else {
                // Position below input (default)
                this.elements.results.style.top = '100%';
                this.elements.results.style.bottom = 'auto';
                this.elements.results.style.marginTop = '8px';
                this.elements.results.style.marginBottom = '0';
            }
        }

        /**
         * Update selection highlighting
         */
        updateSelection() {
            const items = this.elements.results.querySelectorAll(
                '.fastsearch-product, .fastsearch-suggestion'
            );

            items.forEach((item, index) => {
                if (index === this.state.selectedIndex) {
                    item.classList.add('selected');
                    item.setAttribute('aria-selected', 'true');
                } else {
                    item.classList.remove('selected');
                    item.setAttribute('aria-selected', 'false');
                }
            });
        }

        /**
         * Scroll to selected item
         */
        scrollToSelected() {
            if (this.state.selectedIndex < 0) return;

            const items = this.elements.results.querySelectorAll(
                '.fastsearch-product, .fastsearch-suggestion'
            );
            
            const selectedItem = items[this.state.selectedIndex];
            if (selectedItem) {
                selectedItem.scrollIntoView({
                    block: 'nearest',
                    behavior: 'smooth'
                });
            }
        }

        /**
         * Select current item
         */
        selectCurrentItem() {
            const items = this.elements.results.querySelectorAll(
                '.fastsearch-product, .fastsearch-suggestion'
            );

            const selectedItem = items[this.state.selectedIndex];
            if (!selectedItem) return;

            if (selectedItem.classList.contains('fastsearch-product')) {
                // Track click and navigate
                const productId = parseInt(selectedItem.dataset.productId);
                const position = parseInt(selectedItem.dataset.position);
                this.trackEvent('click', this.state.currentQuery, productId, position);
                
                const link = selectedItem.querySelector('a') || selectedItem;
                if (link.href) {
                    window.location.href = link.href;
                }
            } else if (selectedItem.classList.contains('fastsearch-suggestion')) {
                const query = selectedItem.dataset.suggestion;
                this.selectSuggestion(query);
            }
        }

        /**
         * Select suggestion
         */
        selectSuggestion(query) {
            this.elements.input.value = query;
            this.elements.input.focus();
            this.search(query);
            this.trigger('suggestionSelected', { query });
        }

        /**
         * Toggle clear button visibility
         */
        toggleClearButton(show) {
            if (!this.elements.clearBtn) return;

            if (show) {
                this.elements.clearBtn.style.display = 'flex';
                this.elements.clearBtn.classList.add('show');
            } else {
                this.elements.clearBtn.classList.remove('show');
                setTimeout(() => {
                    if (!this.elements.clearBtn.classList.contains('show')) {
                        this.elements.clearBtn.style.display = 'none';
                    }
                }, 150);
            }
        }

        /**
         * Set loading state
         */
        setLoadingState(loading) {
            this.state.isLoading = loading;

            if (this.elements.loader) {
                this.elements.loader.style.display = loading ? 'flex' : 'none';
            }

            if (loading && this.templates.loading) {
                this.elements.results.innerHTML = this.templates.loading({});
                this.showResults();
            }
        }

        /**
         * Initialize voice search
         */
        initializeVoiceSearch() {
            if (!this.config.enableVoiceSearch || !('webkitSpeechRecognition' in window)) {
                if (this.elements.voiceBtn) {
                    this.elements.voiceBtn.style.display = 'none';
                }
                return;
            }

            this.recognition = new webkitSpeechRecognition();
            this.recognition.continuous = false;
            this.recognition.interimResults = false;
            this.recognition.lang = document.documentElement.lang || 'en-US';

            this.recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                this.elements.input.value = transcript;
                this.search(transcript);
                this.trigger('voiceResult', { transcript });
            };

            this.recognition.onend = () => {
                this.state.isListening = false;
                if (this.elements.voiceBtn) {
                    this.elements.voiceBtn.classList.remove('listening');
                }
                this.trigger('voiceEnded');
            };

            this.recognition.onerror = (event) => {
                this.state.isListening = false;
                if (this.elements.voiceBtn) {
                    this.elements.voiceBtn.classList.remove('listening');
                }
                this.error('Voice recognition error:', event.error);
                this.trigger('voiceError', { error: event.error });
            };
        }

        /**
         * Initialize cache
         */
        initializeCache() {
            // Clean up old cache entries
            if (this.cache.size > 100) {
                const entries = Array.from(this.cache.entries());
                entries.sort((a, b) => a[1].timestamp - b[1].timestamp);
                entries.slice(0, 50).forEach(([key]) => {
                    this.cache.delete(key);
                });
            }

            // Periodic cache cleanup
            setInterval(() => {
                const now = Date.now();
                for (const [key, value] of this.cache.entries()) {
                    if (now - value.timestamp > this.config.cacheTime) {
                        this.cache.delete(key);
                    }
                }
            }, 5 * 60 * 1000); // Every 5 minutes
        }

        /**
         * Setup performance monitoring
         */
        setupPerformanceMonitoring() {
            if (typeof PerformanceObserver !== 'undefined') {
                try {
                    const observer = new PerformanceObserver((list) => {
                        list.getEntries().forEach((entry) => {
                            if (entry.name.includes('fastsearch')) {
                                this.log('Performance:', entry.name, entry.duration + 'ms');
                            }
                        });
                    });
                    observer.observe({ entryTypes: ['measure'] });
                } catch (error) {
                    // PerformanceObserver not supported
                }
            }
        }

        /**
         * Recent searches management
         */
        loadRecentSearches() {
            try {
                const saved = localStorage.getItem('fastsearch_recent');
                return saved ? JSON.parse(saved) : [];
            } catch (error) {
                return [];
            }
        }

        saveRecentSearches() {
            try {
                localStorage.setItem('fastsearch_recent', JSON.stringify(this.recentSearches));
            } catch (error) {
                this.error('Failed to save recent searches:', error);
            }
        }

        addToRecentSearches(query, resultsCount) {
            if (!query || query.length < this.config.minQueryLength) return;

        addToRecentSearches(query, resultsCount) {
            if (!query || query.length < this.config.minQueryLength) return;

            // Remove existing entry
            this.recentSearches = this.recentSearches.filter(s => s.query !== query);

            // Add to beginning
            this.recentSearches.unshift({
                query: query,
                resultsCount: resultsCount,
                timestamp: Date.now()
            });

            // Limit size
            this.recentSearches = this.recentSearches.slice(0, this.config.maxRecentSearches);

            // Save to localStorage
            this.saveRecentSearches();
        }

        clearRecentSearches() {
            this.recentSearches = [];
            this.saveRecentSearches();
            this.hideResults();
            this.trigger('recentSearchesCleared');
        }

        /**
         * Analytics and tracking
         */
        trackSearch(query, resultsCount, executionTime) {
            // Google Analytics 4
            if (typeof gtag !== 'undefined') {
                gtag('event', 'search', {
                    search_term: query,
                    search_results: resultsCount,
                    custom_parameter_1: executionTime
                });
            }

            // Custom analytics
            this.sendAnalytics('search', {
                query: query,
                results: resultsCount,
                execution_time: executionTime,
                timestamp: Date.now()
            });
        }

        trackEvent(eventType, query, productId = 0, position = 0) {
            const url = new URL(this.config.searchUrl, window.location.origin);
            url.searchParams.set('action', 'track');
            url.searchParams.set('event', eventType);
            url.searchParams.set('query', query);
            url.searchParams.set('product_id', productId);
            url.searchParams.set('position', position);

            // Send asynchronously
            fetch(url.toString(), {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).catch(error => {
                this.error('Tracking error:', error);
            });

            this.trigger('eventTracked', { eventType, query, productId, position });
        }

        sendAnalytics(event, data) {
            // Custom analytics endpoint
            if (this.config.analyticsEndpoint) {
                fetch(this.config.analyticsEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ event, data })
                }).catch(error => {
                    this.error('Analytics error:', error);
                });
            }
        }

        /**
         * Product actions
         */
        async addToCart(element) {
            const product = element.closest('.fastsearch-product');
            if (!product) return;

            const productId = parseInt(product.dataset.productId);
            
            try {
                // Show loading state
                element.disabled = true;
                element.innerHTML = '<i class="material-icons">hourglass_empty</i>';

                // Add to cart via AJAX
                const response = await fetch('/cart', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=update&add=1&id_product=${productId}&qty=1`
                });

                if (response.ok) {
                    // Success feedback
                    element.innerHTML = '<i class="material-icons">check</i> Added';
                    element.classList.add('success');
                    
                    // Track conversion
                    this.trackEvent('conversion', this.state.currentQuery, productId);
                    
                    // Reset after delay
                    setTimeout(() => {
                        element.disabled = false;
                        element.innerHTML = '<i class="material-icons">add_shopping_cart</i><span>Add to cart</span>';
                        element.classList.remove('success');
                    }, 2000);

                    this.trigger('addedToCart', { productId });
                } else {
                    throw new Error('Failed to add to cart');
                }

            } catch (error) {
                this.error('Add to cart error:', error);
                
                // Error feedback
                element.innerHTML = '<i class="material-icons">error</i>';
                element.classList.add('error');
                
                setTimeout(() => {
                    element.disabled = false;
                    element.innerHTML = '<i class="material-icons">add_shopping_cart</i><span>Add to cart</span>';
                    element.classList.remove('error');
                }, 2000);
            }
        }

        viewProduct(element) {
            const product = element.closest('.fastsearch-product');
            if (!product) return;

            const productId = parseInt(product.dataset.productId);
            const position = parseInt(product.dataset.position);
            
            // Track view
            this.trackEvent('view', this.state.currentQuery, productId, position);
            
            // Navigate to product page
            const link = product.querySelector('a');
            if (link && link.href) {
                window.location.href = link.href;
            }
        }

        loadMore() {
            if (this.state.isLoading || !this.state.hasResults) return;

            const currentOffset = this.state.lastResults.length;
            this.search(this.state.currentQuery, { 
                offset: currentOffset,
                append: true 
            });
        }

        /**
         * Utility functions
         */
        getCacheKey(query, options) {
            return `${query}_${JSON.stringify(options)}`;
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        formatCurrency(amount, currency = '€') {
            return new Intl.NumberFormat('en-EU', {
                style: 'currency',
                currency: 'EUR'
            }).format(amount);
        }

        formatDate(date) {
            return new Intl.DateTimeFormat('en-EU').format(new Date(date));
        }

        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        throttle(func, limit) {
            let inThrottle;
            return function executedFunction(...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        }

        updateAriaLive(message) {
            const liveRegion = document.getElementById('fastsearch-live-region') || 
                (() => {
                    const region = document.createElement('div');
                    region.id = 'fastsearch-live-region';
                    region.setAttribute('aria-live', 'polite');
                    region.setAttribute('aria-atomic', 'true');
                    region.className = 'fastsearch-sr-only';
                    document.body.appendChild(region);
                    return region;
                })();
            
            liveRegion.textContent = message;
        }

        /**
         * Event system
         */
        trigger(eventName, data = {}) {
            const event = new CustomEvent(`fastsearch:${eventName}`, {
                detail: { ...data, instance: this }
            });
            document.dispatchEvent(event);
            this.log('Event triggered:', eventName, data);
        }

        on(eventName, callback) {
            document.addEventListener(`fastsearch:${eventName}`, callback);
        }

        off(eventName, callback) {
            document.removeEventListener(`fastsearch:${eventName}`, callback);
        }

        /**
         * Public API methods
         */
        retry() {
            if (this.state.currentQuery) {
                this.search(this.state.currentQuery);
            }
        }

        clearCache() {
            this.cache.clear();
            this.log('Cache cleared');
        }

        setConfig(newConfig) {
            this.config = { ...this.config, ...newConfig };
            this.log('Configuration updated:', newConfig);
        }

        getResults() {
            return this.state.lastResults;
        }

        getCurrentQuery() {
            return this.state.currentQuery;
        }

        isOpen() {
            return this.state.isOpen;
        }

        focus() {
            this.elements.input.focus();
        }

        blur() {
            this.elements.input.blur();
        }

        /**
         * Logging and debugging
         */
        log(...args) {
            if (this.config.debug || window.FastSearchDebug) {
                console.log('[FastSearch]', ...args);
            }
        }

        error(...args) {
            console.error('[FastSearch ERROR]', ...args);
        }

        /**
         * Cleanup
         */
        destroy() {
            // Cancel pending operations
            if (this.currentRequest) {
                this.currentRequest.abort();
            }
            if (this.searchTimeout) {
                clearTimeout(this.searchTimeout);
            }

            // Remove event listeners
            this.elements.input.removeEventListener('input', this.handlers.input);
            this.elements.input.removeEventListener('focus', this.handlers.focus);
            this.elements.input.removeEventListener('blur', this.handlers.blur);
            this.elements.input.removeEventListener('keydown', this.handlers.keydown);

            if (this.elements.clearBtn) {
                this.elements.clearBtn.removeEventListener('click', this.handlers.clear);
            }
            if (this.elements.voiceBtn) {
                this.elements.voiceBtn.removeEventListener('click', this.handlers.voice);
            }
            if (this.elements.overlay) {
                this.elements.overlay.removeEventListener('click', this.handlers.clickOutside);
            }

            document.removeEventListener('click', this.handlers.clickOutside);
            window.removeEventListener('resize', this.handlers.resize);
            window.removeEventListener('scroll', this.handlers.scroll);

            // Stop voice recognition
            if (this.recognition) {
                this.recognition.stop();
            }

            // Clear references
            this.elements = {};
            this.cache.clear();
            
            this.log('FastSearch destroyed');
        }

        /**
         * Render helpers
         */
        renderResultsFooter(data, query) {
            return `
                <div class="fastsearch-results-footer">
                    <div class="fastsearch-results-info">
                        <span class="fastsearch-results-count">
                            Showing ${Math.min(data.offset + data.limit, data.total)} of ${data.total} results
                            ${data.execution_time ? `<small class="fastsearch-timing">(in ${data.execution_time}ms)</small>` : ''}
                        </span>
                    </div>
                    <div class="fastsearch-results-actions">
                        ${data.has_more ? `
                            <button type="button" class="fastsearch-load-more" data-action="load-more">
                                Load more results
                                <i class="material-icons">expand_more</i>
                            </button>
                        ` : ''}
                        ${data.total > this.config.maxResults ? `
                            <a href="/search?q=${encodeURIComponent(query)}" class="fastsearch-view-all">
                                View all results
                                <i class="material-icons">arrow_forward</i>
                            </a>
                        ` : ''}
                    </div>
                </div>
            `;
        }
    }

    /**
     * FastSearch Plugin System
     */
    class FastSearchPlugin {
        constructor(name, options = {}) {
            this.name = name;
            this.options = options;
        }

        install(fastSearch) {
            // Plugin installation logic
        }

        uninstall(fastSearch) {
            // Plugin cleanup logic
        }
    }

    /**
     * Auto-suggestions Plugin
     */
    class AutoSuggestionsPlugin extends FastSearchPlugin {
        constructor(options = {}) {
            super('autoSuggestions', {
                enabled: true,
                minQueryLength: 2,
                maxSuggestions: 5,
                sources: ['recent', 'popular', 'products'],
                ...options
            });
        }

        install(fastSearch) {
            fastSearch.on('input', this.handleInput.bind(this));
        }

        async handleInput(event) {
            const query = event.detail.query;
            if (query.length >= this.options.minQueryLength) {
                const suggestions = await this.getSuggestions(query);
                // Display suggestions
            }
        }

        async getSuggestions(query) {
            // Implementation for getting suggestions
            return [];
        }
    }

    /**
     * Advanced Filters Plugin
     */
    class AdvancedFiltersPlugin extends FastSearchPlugin {
        constructor(options = {}) {
            super('advancedFilters', {
                enabled: true,
                showPriceRange: true,
                showCategoryFilter: true,
                showBrandFilter: true,
                showAvailabilityFilter: true,
                ...options
            });
        }

        install(fastSearch) {
            this.addFiltersUI(fastSearch);
            fastSearch.on('filtersChanged', this.handleFiltersChange.bind(this));
        }

        addFiltersUI(fastSearch) {
            // Add filters UI to the search interface
        }

        handleFiltersChange(event) {
            // Handle filter changes
        }
    }

    /**
     * Global initialization
     */
    let globalFastSearch = null;

    function initializeFastSearch() {
        if (globalFastSearch) {
            return globalFastSearch;
        }

        // Get configuration from global variable or data attributes
        const config = window.FastSearchConfig || {};
        const wrapper = document.getElementById('fastsearch-container');
        
        if (wrapper) {
            const dataConfig = wrapper.dataset.config;
            if (dataConfig) {
                try {
                    Object.assign(config, JSON.parse(dataConfig));
                } catch (error) {
                    console.error('Failed to parse FastSearch configuration:', error);
                }
            }
        }

        // Initialize FastSearch
        globalFastSearch = new FastSearch(config);
        
        // Install plugins
        if (config.plugins) {
            config.plugins.forEach(pluginConfig => {
                const plugin = FastSearchPlugin.create(pluginConfig);
                if (plugin) {
                    plugin.install(globalFastSearch);
                }
            });
        }

        return globalFastSearch;
    }

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeFastSearch);
    } else {
        initializeFastSearch();
    }

    // Expose to global scope
    window.FastSearch = globalFastSearch || {
        init: initializeFastSearch,
        getInstance: () => globalFastSearch
    };

    // AMD/CommonJS support
    if (typeof define === 'function' && define.amd) {
        define('FastSearch', [], () => FastSearch);
    } else if (typeof module !== 'undefined' && module.exports) {
        module.exports = FastSearch;
    }

    // jQuery plugin integration
    if (window.jQuery) {
        window.jQuery.fn.fastSearch = function(options) {
            return this.each(function() {
                if (!this.fastSearchInstance) {
                    this.fastSearchInstance = new FastSearch({
                        ...options,
                        wrapper: this
                    });
                }
            });
        };
    }

})(window, document);