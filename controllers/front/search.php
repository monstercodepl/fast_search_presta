<?php
/**
 * FastSearch AJAX Controller
 * Kontroler do obsługi zapytań AJAX wyszukiwarki
 * 
 * @author    FastSearch Team
 * @version   1.0.0
 * @copyright 2025 FastSearch
 * @license   MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FastSearchSearchModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    
    /**
     * Inicjalizacja kontrolera
     */
    public function init()
    {
        parent::init();
        
        // Sprawdź czy moduł jest włączony
        if (!Configuration::get('FASTSEARCH_ENABLED', 1)) {
            $this->ajaxResponse(array('error' => 'FastSearch is disabled'), 503);
            return;
        }
        
        // Rate limiting
        if ($this->isRateLimited()) {
            $this->ajaxResponse(array('error' => 'Rate limit exceeded'), 429);
            return;
        }
    }

    /**
     * Główna metoda obsługująca zawartość
     */
    public function initContent()
    {
        parent::initContent();
        
        // Sprawdź czy to zapytanie AJAX
        if (!$this->isAjaxRequest()) {
            Tools::redirect($this->context->link->getPageLink('index'));
            return;
        }
        
        // Routing na podstawie akcji
        $action = Tools::getValue('action', 'search');
        
        switch ($action) {
            case 'search':
                $this->processSearch();
                break;
                
            case 'suggestions':
                $this->processSuggestions();
                break;
                
            case 'track':
                $this->processTracking();
                break;
                
            case 'popular':
                $this->processPopularSearches();
                break;
                
            default:
                $this->ajaxResponse(array('error' => 'Invalid action'), 400);
        }
    }

    /**
     * Główna funkcja wyszukiwania
     */
    private function processSearch()
    {
        $start_time = microtime(true);
        
        try {
            // Pobierz parametry
            $query = trim(Tools::getValue('q', ''));
            $limit = min((int)Tools::getValue('limit', Configuration::get('FASTSEARCH_MAX_RESULTS', 15)), 50);
            $offset = max((int)Tools::getValue('offset', 0), 0);
            $category = (int)Tools::getValue('category', 0);
            $price_min = (float)Tools::getValue('price_min', 0);
            $price_max = (float)Tools::getValue('price_max', 0);
            $in_stock = (bool)Tools::getValue('in_stock', false);
            $sort = Tools::getValue('sort', 'relevance'); // relevance, name, price, date
            
            // Walidacja zapytania
            if (empty($query)) {
                $this->ajaxResponse(array(
                    'products' => array(),
                    'total' => 0,
                    'query' => $query,
                    'execution_time' => 0
                ));
                return;
            }
            
            $min_length = Configuration::get('FASTSEARCH_MIN_QUERY_LENGTH', 2);
            if (strlen($query) < $min_length) {
                $this->ajaxResponse(array(
                    'error' => sprintf('Query must be at least %d characters long', $min_length),
                    'min_length' => $min_length
                ), 400);
                return;
            }
            
            // Sanityzacja zapytania
            $query = $this->sanitizeQuery($query);
            
            // Przygotuj filtry
            $filters = array();
            if ($category > 0) $filters['category'] = $category;
            if ($price_min > 0) $filters['price_min'] = $price_min;
            if ($price_max > 0) $filters['price_max'] = $price_max;
            if ($in_stock) $filters['in_stock'] = true;
            if ($sort !== 'relevance') $filters['sort'] = $sort;
            
            // Wykonaj wyszukiwanie
            $search_result = $this->module->searchProducts($query, $limit, $offset, $filters);
            
            // Przetwórz wyniki dla JSON
            $products = array();
            foreach ($search_result['products'] as $product) {
                $products[] = $this->formatProductForJson($product, $query);
            }
            
            // Przygotuj odpowiedź
            $response = array(
                'products' => $products,
                'total' => $search_result['total'],
                'query' => $query,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $search_result['total'],
                'execution_time' => $search_result['execution_time'],
                'filters_applied' => !empty($filters),
                'suggestions' => array()
            );
            
            // Dodaj sugestie jeśli brak wyników
            if (empty($products) && strlen($query) >= 3) {
                $response['suggestions'] = $this->generateSuggestions($query);
            }
            
            // Dodaj metadata dla SEO/Analytics
            $response['metadata'] = array(
                'search_type' => 'product_search',
                'language' => $this->context->language->iso_code,
                'shop' => $this->context->shop->name,
                'timestamp' => time(),
                'user_agent' => $this->getUserAgent()
            );
            
            $this->ajaxResponse($response);
            
        } catch (Exception $e) {
            $this->logError('Search error: ' . $e->getMessage(), $query);
            $this->ajaxResponse(array(
                'error' => 'Search temporarily unavailable',
                'debug' => _PS_MODE_DEV_ ? $e->getMessage() : null
            ), 500);
        }
    }

    /**
     * Obsługa sugestii wyszukiwania
     */
    private function processSuggestions()
    {
        try {
            $query = trim(Tools::getValue('q', ''));
            $limit = min((int)Tools::getValue('limit', 5), 10);
            
            if (strlen($query) < 2) {
                $this->ajaxResponse(array('suggestions' => array()));
                return;
            }
            
            $suggestions = $this->module->getSuggestions($query, $limit);
            
            // Formatuj sugestie
            $formatted_suggestions = array();
            foreach ($suggestions as $suggestion) {
                $formatted_suggestions[] = array(
                    'text' => $suggestion['suggestion'],
                    'frequency' => (int)$suggestion['frequency'],
                    'avg_price' => $suggestion['avg_price'] ? Tools::displayPrice($suggestion['avg_price']) : null,
                    'type' => 'product_name'
                );
            }
            
            // Dodaj popularne wyszukiwania jeśli za mało sugestii
            if (count($formatted_suggestions) < 3) {
                $popular = $this->getPopularSearches(3 - count($formatted_suggestions));
                foreach ($popular as $pop) {
                    $formatted_suggestions[] = array(
                        'text' => $pop['search_query'],
                        'frequency' => (int)$pop['search_count'],
                        'avg_price' => null,
                        'type' => 'popular_search'
                    );
                }
            }
            
            $this->ajaxResponse(array(
                'suggestions' => $formatted_suggestions,
                'query' => $query
            ));
            
        } catch (Exception $e) {
            $this->logError('Suggestions error: ' . $e->getMessage(), Tools::getValue('q', ''));
            $this->ajaxResponse(array('suggestions' => array()), 500);
        }
    }

    /**
     * Tracking kliknięć i konwersji
     */
    private function processTracking()
    {
        try {
            $event_type = Tools::getValue('event'); // click, conversion, view
            $query = Tools::getValue('query', '');
            $product_id = (int)Tools::getValue('product_id', 0);
            $position = (int)Tools::getValue('position', 0);
            
            if (!in_array($event_type, array('click', 'conversion', 'view'))) {
                $this->ajaxResponse(array('error' => 'Invalid event type'), 400);
                return;
            }
            
            // Loguj event
            $this->logSearchEvent($event_type, $query, $product_id, $position);
            
            // Aktualizuj statystyki w tabeli
            if ($event_type === 'click' && !empty($query)) {
                Db::getInstance()->execute('
                    UPDATE `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    SET click_count = click_count + 1 
                    WHERE search_query = "' . pSQL($query) . '" 
                    AND DATE(date_search) = CURDATE()
                    ORDER BY date_search DESC 
                    LIMIT 1
                ');
            }
            
            if ($event_type === 'conversion' && !empty($query)) {
                Db::getInstance()->execute('
                    UPDATE `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    SET conversion_count = conversion_count + 1 
                    WHERE search_query = "' . pSQL($query) . '" 
                    AND DATE(date_search) = CURDATE()
                    ORDER BY date_search DESC 
                    LIMIT 1
                ');
            }
            
            $this->ajaxResponse(array('status' => 'tracked'));
            
        } catch (Exception $e) {
            $this->logError('Tracking error: ' . $e->getMessage());
            $this->ajaxResponse(array('error' => 'Tracking failed'), 500);
        }
    }

    /**
     * Popularne wyszukiwania
     */
    private function processPopularSearches()
    {
        try {
            $limit = min((int)Tools::getValue('limit', 10), 20);
            $days = min((int)Tools::getValue('days', 7), 30);
            
            $popular = $this->getPopularSearches($limit, $days);
            
            $this->ajaxResponse(array(
                'popular_searches' => $popular,
                'period_days' => $days
            ));
            
        } catch (Exception $e) {
            $this->logError('Popular searches error: ' . $e->getMessage());
            $this->ajaxResponse(array('popular_searches' => array()), 500);
        }
    }

    /**
     * Formatuje produkt dla odpowiedzi JSON
     */
    private function formatProductForJson($product, $query = '')
    {
        $formatted = array(
            'id_product' => (int)$product['id_product'],
            'name' => $product['name'],
            'reference' => $product['reference'],
            'ean13' => $product['ean13'],
            'price' => (float)$product['price'],
            'formatted_price' => $product['formatted_price'],
            'quantity' => (int)$product['quantity'],
            'available' => $product['available'],
            'availability_message' => $product['availability_message'],
            'product_url' => $product['product_url'],
            'category_name' => $product['category_name'],
            'relevance_score' => isset($product['relevance_score']) ? (float)$product['relevance_score'] : 0
        );
        
        // Obrazki - różne rozmiary
        if (Configuration::get('FASTSEARCH_SHOW_IMAGES', 1)) {
            $formatted['images'] = array(
                'small' => $product['image_url_small'] ?? $product['image_url'],
                'medium' => $product['image_url'],
                'large' => str_replace('home_default', 'large_default', $product['image_url'])
            );
        }
        
        // Opisy
        if (Configuration::get('FASTSEARCH_SHOW_DESCRIPTIONS', 1)) {
            $description = $product['description_short'];
            
            // Podświetl wyszukiwaną frazę
            if (!empty($query) && Configuration::get('FASTSEARCH_HIGHLIGHT_TERMS', 1)) {
                $description = $this->highlightSearchTerms($description, $query);
                $formatted['name'] = $this->highlightSearchTerms($product['name'], $query);
            }
            
            $formatted['description_short'] = $description;
        }
        
        // Dodatkowe informacje
        $formatted['badges'] = array();
        if ($product['on_sale']) {
            $formatted['badges'][] = array('type' => 'sale', 'text' => $this->module->l('Sale'));
        }
        if ($product['quantity'] <= $product['low_stock_threshold'] && $product['quantity'] > 0) {
            $formatted['badges'][] = array('type' => 'low_stock', 'text' => $this->module->l('Limited stock'));
        }
        if ($product['online_only']) {
            $formatted['badges'][] = array('type' => 'online_only', 'text' => $this->module->l('Online only'));
        }
        
        return $formatted;
    }

    /**
     * Podświetla wyszukiwane terminy w tekście
     */
    private function highlightSearchTerms($text, $query)
    {
        if (empty($text) || empty($query)) {
            return $text;
        }
        
        $words = explode(' ', $query);
        $words = array_filter($words, function($word) {
            return strlen(trim($word)) > 1;
        });
        
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 1) {
                $pattern = '/(' . preg_quote($word, '/') . ')/iu';
                $text = preg_replace($pattern, '<mark class="fastsearch-highlight">$1</mark>', $text);
            }
        }
        
        return $text;
    }

    /**
     * Generuje sugestie dla zapytania bez wyników
     */
    private function generateSuggestions($query)
    {
        $suggestions = array();
        
        // Usuń ostatnie słowo i spróbuj ponownie
        $words = explode(' ', trim($query));
        if (count($words) > 1) {
            array_pop($words);
            $shorter_query = implode(' ', $words);
            $suggestions[] = array(
                'text' => $shorter_query,
                'type' => 'shorter_query',
                'message' => 'Try shorter search'
            );
        }
        
        // Sprawdź częste błędy pisowni
        $common_typos = array(
            'telfon' => 'telefon',
            'komputre' => 'komputer',
            'samochodz' => 'samochód',
            'ubrianie' => 'ubranie'
        );
        
        $lower_query = strtolower($query);
        foreach ($common_typos as $typo => $correct) {
            if (strpos($lower_query, $typo) !== false) {
                $corrected = str_ireplace($typo, $correct, $query);
                $suggestions[] = array(
                    'text' => $corrected,
                    'type' => 'spelling_correction',
                    'message' => 'Did you mean?'
                );
                break;
            }
        }
        
        // Dodaj popularne wyszukiwania z podobnymi słowami
        $similar = $this->findSimilarSearches($query, 2);
        foreach ($similar as $sim) {
            $suggestions[] = array(
                'text' => $sim['search_query'],
                'type' => 'similar_search',
                'message' => 'Similar searches'
            );
        }
        
        return array_slice($suggestions, 0, 3);
    }

    /**
     * Znajduje podobne wyszukiwania
     */
    private function findSimilarSearches($query, $limit = 3)
    {
        $words = explode(' ', strtolower($query));
        $word_conditions = array();
        
        foreach ($words as $word) {
            if (strlen(trim($word)) > 2) {
                $word_conditions[] = 'LOWER(search_query) LIKE "%' . pSQL(trim($word)) . '%"';
            }
        }
        
        if (empty($word_conditions)) {
            return array();
        }
        
        $sql = '
        SELECT search_query, COUNT(*) as search_count
        FROM `' . _DB_PREFIX_ . 'fastsearch_stats`
        WHERE (' . implode(' OR ', $word_conditions) . ')
        AND search_query != "' . pSQL($query) . '"
        AND date_search >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY search_query
        ORDER BY search_count DESC
        LIMIT ' . (int)$limit;
        
        return Db::getInstance()->executeS($sql);
    }

    /**
     * Pobiera popularne wyszukiwania
     */
    private function getPopularSearches($limit = 10, $days = 7)
    {
        $sql = '
        SELECT 
            search_query,
            COUNT(*) as search_count,
            AVG(results_count) as avg_results
        FROM `' . _DB_PREFIX_ . 'fastsearch_stats`
        WHERE date_search >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)
        AND id_shop = ' . (int)$this->context->shop->id . '
        AND results_count > 0
        GROUP BY search_query
        ORDER BY search_count DESC
        LIMIT ' . (int)$limit;
        
        return Db::getInstance()->executeS($sql);
    }

    /**
     * Sanityzuje zapytanie wyszukiwania
     */
    private function sanitizeQuery($query)
    {
        // Usuń niebezpieczne znaki
        $query = preg_replace('/[<>"\'\(\){}[\]]/', '', $query);
        
        // Ogranicz długość
        $query = Tools::substr($query, 0, 100);
        
        // Usuń wielokrotne spacje
        $query = preg_replace('/\s+/', ' ', $query);
        
        return trim($query);
    }

    /**
     * Rate limiting
     */
    private function isRateLimited()
    {
        $ip = Tools::getRemoteAddr();
        $cache_key = 'fastsearch_rate_' . md5($ip);
        
        // Sprawdź w cache
        $requests = (int)Cache::getInstance()->get($cache_key);
        $max_requests = 100; // 100 zapytań na minutę
        
        if ($requests >= $max_requests) {
            return true;
        }
        
        // Zwiększ licznik
        Cache::getInstance()->set($cache_key, $requests + 1, 60);
        
        return false;
    }

    /**
     * Sprawdza czy to zapytanie AJAX
     */
    private function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Pobiera User Agent
     */
    private function getUserAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? 
               Tools::substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    }

    /**
     * Loguje wydarzenia wyszukiwania
     */
    private function logSearchEvent($event_type, $query, $product_id = 0, $position = 0)
    {
        if (!Configuration::get('FASTSEARCH_ENABLE_STATS', 1)) {
            return;
        }
        
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'fastsearch_events` 
                (event_type, search_query, id_product, position, id_customer, ip_address, user_agent, date_add) 
                VALUES 
                ("' . pSQL($event_type) . '", "' . pSQL($query) . '", ' . (int)$product_id . ', ' . (int)$position . ', ' . 
                ($this->context->customer->isLogged() ? (int)$this->context->customer->id : 'NULL') . ', 
                "' . pSQL(Tools::getRemoteAddr()) . '", "' . pSQL($this->getUserAgent()) . '", NOW())';
        
        // Utwórz tabelę jeśli nie istnieje
        $this->createEventsTableIfNotExists();
        
        Db::getInstance()->execute($sql);
    }

    /**
     * Tworzy tabelę eventów jeśli nie istnieje
     */
    private function createEventsTableIfNotExists()
    {
        static $table_checked = false;
        
        if ($table_checked) {
            return;
        }
        
        $sql = '
        CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fastsearch_events` (
            `id_event` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_type` ENUM("click", "conversion", "view") NOT NULL,
            `search_query` VARCHAR(255) NOT NULL,
            `id_product` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `position` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `id_customer` INT(10) UNSIGNED NULL,
            `ip_address` VARCHAR(45) DEFAULT "",
            `user_agent` TEXT,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_event`),
            KEY `event_type` (`event_type`),
            KEY `search_query` (`search_query`),
            KEY `date_add` (`date_add`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        
        Db::getInstance()->execute($sql);
        $table_checked = true;
    }

    /**
     * Loguje błędy
     */
    private function logError($message, $query = '')
    {
        if (_PS_MODE_DEV_) {
            PrestaShopLogger::addLog(
                'FastSearch Error: ' . $message . ($query ? ' (Query: ' . $query . ')' : ''),
                3,
                null,
                'FastSearch',
                null,
                true
            );
        }
    }

    /**
     * Wysyła odpowiedź JSON
     */
    private function ajaxResponse($data, $status_code = 200)
    {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        
        // CORS headers jeśli potrzebne
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $allowed_origins = array(
                $this->context->shop->getBaseURL(),
                'https://' . $_SERVER['HTTP_HOST'],
                'http://' . $_SERVER['HTTP_HOST']
            );
            
            if (in_array($_SERVER['HTTP_ORIGIN'], $allowed_origins)) {
                header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
            }
        }
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>