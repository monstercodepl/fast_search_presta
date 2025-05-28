{*
* FastSearch Template
* Szablon wyszukiwarki dla PrestaShop
* 
* @author    FastSearch Team
* @version   1.0.0
* @copyright 2025 FastSearch
*}

{* Sprawdź czy moduł jest włączony *}
{if isset($fastsearch_enabled) && $fastsearch_enabled}

<div id="fastsearch-container" class="fastsearch-wrapper" data-config='{
    "searchUrl": "{$search_url|escape:'htmlall':'UTF-8'}",
    "minQueryLength": {$min_query_length|intval},
    "maxResults": {$max_results|intval},
    "debounceTime": {$debounce_time|intval},
    "showImages": {if $show_images}true{else}false{/if},
    "showPrices": {if $show_prices}true{else}false{/if},
    "showDescriptions": {if $show_descriptions}true{else}false{/if},
    "moduleDir": "{$module_dir|escape:'htmlall':'UTF-8'}",
    "language": "{$language.iso_code|escape:'htmlall':'UTF-8'}",
    "currency": "{$currency.sign|escape:'htmlall':'UTF-8'}"
}'>
    
    {* Główny kontener wyszukiwania *}
    <div class="fastsearch-input-container">
        <div class="fastsearch-input-wrapper">
            {* Ikona wyszukiwania *}
            <div class="fastsearch-search-icon">
                <i class="material-icons search" aria-hidden="true">search</i>
            </div>
            
            {* Pole wyszukiwania *}
            <input 
                type="text" 
                id="fastsearch-input" 
                class="fastsearch-input form-control" 
                placeholder="{l s='Search products...' mod='fastsearch'}" 
                autocomplete="off"
                autocorrect="off"
                autocapitalize="off"
                spellcheck="false"
                role="combobox"
                aria-expanded="false"
                aria-autocomplete="list"
                aria-haspopup="listbox"
                aria-label="{l s='Product search' mod='fastsearch'}"
                data-search-url="{$search_url|escape:'htmlall':'UTF-8'}"
                maxlength="100"
            />
            
            {* Przyciski akcji *}
            <div class="fastsearch-actions">
                {* Przycisk czyszczenia *}
                <button 
                    type="button" 
                    id="fastsearch-clear" 
                    class="fastsearch-clear-btn" 
                    style="display: none;"
                    aria-label="{l s='Clear search' mod='fastsearch'}"
                    title="{l s='Clear search' mod='fastsearch'}"
                >
                    <i class="material-icons" aria-hidden="true">close</i>
                </button>
                
                {* Przycisk wyszukiwania głosowego *}
                {if isset($voice_search_enabled) && $voice_search_enabled}
                <button 
                    type="button" 
                    id="fastsearch-voice" 
                    class="fastsearch-voice-btn" 
                    aria-label="{l s='Voice search' mod='fastsearch'}"
                    title="{l s='Voice search' mod='fastsearch'}"
                >
                    <i class="material-icons" aria-hidden="true">mic</i>
                </button>
                {/if}
                
                {* Loader *}
                <div id="fastsearch-loader" class="fastsearch-loader" style="display: none;" aria-hidden="true">
                    <div class="fastsearch-spinner"></div>
                </div>
            </div>
        </div>
        
        {* Filtry szybkie (opcjonalne) *}
        {if isset($quick_filters) && $quick_filters}
        <div class="fastsearch-quick-filters" style="display: none;">
            <div class="fastsearch-filter-group">
                <label class="fastsearch-filter-label">
                    <input type="checkbox" id="fastsearch-filter-instock" class="fastsearch-filter-checkbox">
                    <span class="fastsearch-filter-text">{l s='In stock only' mod='fastsearch'}</span>
                </label>
                
                <label class="fastsearch-filter-label">
                    <input type="checkbox" id="fastsearch-filter-sale" class="fastsearch-filter-checkbox">
                    <span class="fastsearch-filter-text">{l s='On sale' mod='fastsearch'}</span>
                </label>
                
                <div class="fastsearch-price-range">
                    <input type="number" id="fastsearch-price-min" placeholder="{l s='Min price' mod='fastsearch'}" min="0" step="0.01">
                    <span class="fastsearch-price-separator">-</span>
                    <input type="number" id="fastsearch-price-max" placeholder="{l s='Max price' mod='fastsearch'}" min="0" step="0.01">
                </div>
            </div>
        </div>
        {/if}
    </div>
    
    {* Kontener wyników *}
    <div id="fastsearch-results" class="fastsearch-results" style="display: none;" role="listbox" aria-label="{l s='Search results' mod='fastsearch'}">
        {* Wyniki będą ładowane dynamicznie przez JavaScript *}
    </div>
    
    {* Overlay do zamykania wyników *}
    <div id="fastsearch-overlay" class="fastsearch-overlay" style="display: none;"></div>
</div>

{* Predefiniowane szablony dla JavaScript *}
<script type="text/template" id="fastsearch-product-template">
    <div class="fastsearch-product" data-product-id="<%- id_product %>" data-position="<%- position %>" role="option">
        <% if (show_images && images && images.small) { %>
        <div class="fastsearch-image-wrapper">
            <img 
                src="<%- images.small %>" 
                alt="<%- name %>" 
                class="fastsearch-image"
                loading="lazy"
                onerror="this.src='<%- fallback_image %>'"
            />
            <% if (badges && badges.length > 0) { %>
            <div class="fastsearch-badges">
                <% badges.forEach(function(badge) { %>
                <span class="fastsearch-badge fastsearch-badge-<%- badge.type %>"><%- badge.text %></span>
                <% }); %>
            </div>
            <% } %>
        </div>
        <% } %>
        
        <div class="fastsearch-product-info">
            <div class="fastsearch-product-main">
                <h3 class="fastsearch-product-name">
                    <span class="fastsearch-name-text"><%- name %></span>
                    <% if (reference) { %>
                    <small class="fastsearch-reference"><%- reference %></small>
                    <% } %>
                </h3>
                
                <% if (show_descriptions && description_short) { %>
                <p class="fastsearch-description"><%- description_short %></p>
                <% } %>
                
                <div class="fastsearch-product-meta">
                    <% if (show_prices && formatted_price) { %>
                    <div class="fastsearch-price-wrapper">
                        <span class="fastsearch-price"><%- formatted_price %></span>
                        <% if (old_price && old_price !== formatted_price) { %>
                        <span class="fastsearch-old-price"><%- old_price %></span>
                        <% } %>
                    </div>
                    <% } %>
                    
                    <% if (category_name) { %>
                    <span class="fastsearch-category"><%- category_name %></span>
                    <% } %>
                </div>
            </div>
            
            <div class="fastsearch-product-actions">
                <div class="fastsearch-availability">
                    <span class="fastsearch-availability-text <%- available ? 'available' : 'unavailable' %>">
                        <%- availability_message %>
                    </span>
                    <% if (quantity > 0 && quantity <= 5) { %>
                    <small class="fastsearch-stock-level">{l s='Only' mod='fastsearch'} <%- quantity %> {l s='left' mod='fastsearch'}</small>
                    <% } %>
                </div>
                
                <div class="fastsearch-action-buttons">
                    <button type="button" class="fastsearch-btn fastsearch-btn-view" data-action="view">
                        <i class="material-icons" aria-hidden="true">visibility</i>
                        <span>{l s='View' mod='fastsearch'}</span>
                    </button>
                    
                    <% if (available) { %>
                    <button type="button" class="fastsearch-btn fastsearch-btn-cart" data-action="add-to-cart">
                        <i class="material-icons" aria-hidden="true">add_shopping_cart</i>
                        <span>{l s='Add to cart' mod='fastsearch'}</span>
                    </button>
                    <% } %>
                </div>
            </div>
        </div>
        
        <div class="fastsearch-product-link">
            <i class="material-icons" aria-hidden="true">arrow_forward</i>
        </div>
    </div>
</script>

<script type="text/template" id="fastsearch-suggestion-template">
    <div class="fastsearch-suggestion" data-suggestion="<%- text %>" role="option">
        <div class="fastsearch-suggestion-icon">
            <% if (type === 'recent') { %>
            <i class="material-icons" aria-hidden="true">history</i>
            <% } else if (type === 'popular') { %>
            <i class="material-icons" aria-hidden="true">trending_up</i>
            <% } else if (type === 'correction') { %>
            <i class="material-icons" aria-hidden="true">spellcheck</i>
            <% } else { %>
            <i class="material-icons" aria-hidden="true">search</i>
            <% } %>
        </div>
        
        <div class="fastsearch-suggestion-content">
            <span class="fastsearch-suggestion-text"><%- text %></span>
            <% if (message) { %>
            <small class="fastsearch-suggestion-message"><%- message %></small>
            <% } %>
        </div>
        
        <% if (frequency) { %>
        <div class="fastsearch-suggestion-meta">
            <small class="fastsearch-frequency"><%- frequency %></small>
        </div>
        <% } %>
        
        <button type="button" class="fastsearch-suggestion-remove" aria-label="{l s='Remove from history' mod='fastsearch'}">
            <i class="material-icons" aria-hidden="true">close</i>
        </button>
    </div>
</script>

<script type="text/template" id="fastsearch-no-results-template">
    <div class="fastsearch-no-results">
        <div class="fastsearch-no-results-icon">
            <i class="material-icons" aria-hidden="true">search_off</i>
        </div>
        
        <div class="fastsearch-no-results-content">
            <h3 class="fastsearch-no-results-title">{l s='No products found' mod='fastsearch'}</h3>
            <p class="fastsearch-no-results-message">{l s='Try different keywords or check spelling' mod='fastsearch'}</p>
            
            <% if (suggestions && suggestions.length > 0) { %>
            <div class="fastsearch-suggestions-section">
                <h4>{l s='Did you mean?' mod='fastsearch'}</h4>
                <div class="fastsearch-suggestions-list">
                    <% suggestions.forEach(function(suggestion) { %>
                    <button type="button" class="fastsearch-suggestion-btn" data-suggestion="<%- suggestion.text %>">
                        <%- suggestion.text %>
                    </button>
                    <% }); %>
                </div>
            </div>
            <% } %>
            
            <div class="fastsearch-search-tips">
                <h4>{l s='Search tips:' mod='fastsearch'}</h4>
                <ul>
                    <li>{l s='Use simple keywords' mod='fastsearch'}</li>
                    <li>{l s='Check spelling' mod='fastsearch'}</li>
                    <li>{l s='Try product codes or brand names' mod='fastsearch'}</li>
                    <li>{l s='Use fewer words' mod='fastsearch'}</li>
                </ul>
            </div>
        </div>
    </div>
</script>

<script type="text/template" id="fastsearch-loading-template">
    <div class="fastsearch-loading">
        <div class="fastsearch-loading-spinner">
            <div class="fastsearch-spinner"></div>
        </div>
        <div class="fastsearch-loading-text">
            {l s='Searching...' mod='fastsearch'}
        </div>
    </div>
</script>

<script type="text/template" id="fastsearch-error-template">
    <div class="fastsearch-error">
        <div class="fastsearch-error-icon">
            <i class="material-icons" aria-hidden="true">error_outline</i>
        </div>
        
        <div class="fastsearch-error-content">
            <h3 class="fastsearch-error-title">{l s='Search temporarily unavailable' mod='fastsearch'}</h3>
            <p class="fastsearch-error-message">{l s='Please try again in a moment' mod='fastsearch'}</p>
            
            <button type="button" class="fastsearch-retry-btn" onclick="FastSearch.retry()">
                <i class="material-icons" aria-hidden="true">refresh</i>
                {l s='Try again' mod='fastsearch'}
            </button>
        </div>
    </div>
</script>

<script type="text/template" id="fastsearch-recent-searches-template">
    <div class="fastsearch-recent-searches">
        <div class="fastsearch-section-header">
            <h3 class="fastsearch-section-title">
                <i class="material-icons" aria-hidden="true">history</i>
                {l s='Recent searches' mod='fastsearch'}
            </h3>
            <button type="button" class="fastsearch-clear-all" data-action="clear-recent">
                {l s='Clear all' mod='fastsearch'}
            </button>
        </div>
        
        <div class="fastsearch-recent-list">
            <!-- Recent searches will be populated by JavaScript -->
        </div>
    </div>
</script>

<script type="text/template" id="fastsearch-popular-searches-template">
    <div class="fastsearch-popular-searches">
        <div class="fastsearch-section-header">
            <h3 class="fastsearch-section-title">
                <i class="material-icons" aria-hidden="true">trending_up</i>
                {l s='Popular searches' mod='fastsearch'}
            </h3>
        </div>
        
        <div class="fastsearch-popular-list">
            <!-- Popular searches will be populated by JavaScript -->
        </div>
    </div>
</script>

<script type="text/template" id="fastsearch-results-footer-template">
    <div class="fastsearch-results-footer">
        <div class="fastsearch-results-info">
            <% if (total > 0) { %>
            <span class="fastsearch-results-count">
                {l s='Showing' mod='fastsearch'} <%- Math.min(offset + limit, total) %> 
                {l s='of' mod='fastsearch'} <%- total %> 
                {l s='results' mod='fastsearch'}
                <% if (execution_time) { %>
                <small class="fastsearch-timing">({l s='in' mod='fastsearch'} <%- execution_time %>ms)</small>
                <% } %>
            </span>
            <% } %>
        </div>
        
        <div class="fastsearch-results-actions">
            <% if (has_more) { %>
            <button type="button" class="fastsearch-load-more" data-action="load-more">
                {l s='Load more results' mod='fastsearch'}
                <i class="material-icons" aria-hidden="true">expand_more</i>
            </button>
            <% } %>
            
            <% if (total > max_results) { %>
            <a href="/search?q=<%- encodeURIComponent(query) %>" class="fastsearch-view-all">
                {l s='View all results' mod='fastsearch'}
                <i class="material-icons" aria-hidden="true">arrow_forward</i>
            </a>
            <% } %>
        </div>
    </div>
</script>

{* Konfiguracja JavaScript *}
<script>
// Globalna konfiguracja FastSearch
window.FastSearchConfig = {
    searchUrl: '{$search_url|escape:'javascript':'UTF-8'}',
    minQueryLength: {$min_query_length|intval},
    maxResults: {$max_results|intval},
    debounceTime: {$debounce_time|intval},
    showImages: {if $show_images}true{else}false{/if},
    showPrices: {if $show_prices}true{else}false{/if},
    showDescriptions: {if $show_descriptions}true{else}false{/if},
    moduleDir: '{$module_dir|escape:'javascript':'UTF-8'}',
    language: '{if isset($language.iso_code)}{$language.iso_code|escape:'javascript':'UTF-8'}{else}en{/if}',
    currency: '{if isset($currency.sign)}{$currency.sign|escape:'javascript':'UTF-8'}{else}€{/if}',
    fallbackImage: '{if isset($urls.no_picture_image.bySize.home_default.url)}{$urls.no_picture_image.bySize.home_default.url|escape:'javascript':'UTF-8'}{else}/img/p/en-default-home_default.jpg{/if}',
    translations: {
        searching: '{l s='Searching...' mod='fastsearch' js=1}',
        noResults: '{l s='No products found' mod='fastsearch' js=1}',
        errorMessage: '{l s='Search temporarily unavailable' mod='fastsearch' js=1}',
        tryAgain: '{l s='Try again' mod='fastsearch' js=1}',
        clearSearch: '{l s='Clear search' mod='fastsearch' js=1}',
        voiceSearch: '{l s='Voice search' mod='fastsearch' js=1}',
        recentSearches: '{l s='Recent searches' mod='fastsearch' js=1}',
        popularSearches: '{l s='Popular searches' mod='fastsearch' js=1}',
        didYouMean: '{l s='Did you mean?' mod='fastsearch' js=1}',
        loadMore: '{l s='Load more results' mod='fastsearch' js=1}',
        viewAll: '{l s='View all results' mod='fastsearch' js=1}',
        addToCart: '{l s='Add to cart' mod='fastsearch' js=1}',
        view: '{l s='View' mod='fastsearch' js=1}',
        inStock: '{l s='In stock' mod='fastsearch' js=1}',
        outOfStock: '{l s='Out of stock' mod='fastsearch' js=1}',
        limitedStock: '{l s='Limited stock' mod='fastsearch' js=1}'
    }
};

// Inicjalizacja po załadowaniu DOM
document.addEventListener('DOMContentLoaded', function() {
    if (typeof FastSearch !== 'undefined') {
        FastSearch.init();
    }
});
</script>

{* Schema.org structured data for SEO *}
{if isset($language.iso_code) && isset($shop.url)}
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebSite",
    "url": "{if isset($shop.url)}{$shop.url}{else}{$base_uri}{/if}",
    "potentialAction": {
        "@type": "SearchAction",
        "target": {
            "@type": "EntryPoint",
            "urlTemplate": "{if isset($shop.url)}{$shop.url}{else}{$base_uri}{/if}search?q={literal}{search_term_string}{/literal}"
        },
        "query-input": "required name=search_term_string"
    }
}
</script>
{/if}

{* CSS dla krytycznych stylów - inline dla szybkości *}
<style>
.fastsearch-wrapper {
    position: relative;
    z-index: 1000;
}

.fastsearch-input-container {
    position: relative;
}

.fastsearch-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    background: #fff;
    border: 2px solid #e0e0e0;
    border-radius: 50px;
    transition: all 0.3s ease;
}

.fastsearch-input-wrapper:focus-within {
    border-color: #25b9d7;
    box-shadow: 0 0 0 3px rgba(37, 185, 215, 0.1);
}

.fastsearch-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 12px 20px;
    font-size: 16px;
    outline: none;
}

.fastsearch-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.12);
    max-height: 70vh;
    overflow-y: auto;
    z-index: 1001;
    margin-top: 8px;
}

.fastsearch-spinner {
    width: 20px;
    height: 20px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #25b9d7;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .fastsearch-results {
        position: fixed;
        top: 60px;
        left: 10px;
        right: 10px;
        max-height: calc(100vh - 80px);
    }
}
</style>

{/if}