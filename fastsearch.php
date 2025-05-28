<?php
/**
 * Fast Search Module for PrestaShop
 * Szybka wyszukiwarka dla dużych baz danych produktów
 * 
 * @author    FastSearch Team
 * @version   1.0.0
 * @copyright 2025 FastSearch
 * @license   MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoload klas modułu
require_once(dirname(__FILE__) . '/classes/FastSearchOptimizer.php');
require_once(dirname(__FILE__) . '/classes/FastSearchCache.php');

class FastSearch extends Module
{
    public function __construct()
    {
        $this->name = 'fastsearch';
        $this->tab = 'search_filter';
        $this->version = '1.0.1';
        $this->author = 'Monster Code';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Szybka Wyszukiwarka Big Data');
        $this->description = $this->l('Zaawansowana wyszukiwarka dla dużych baz produktów z indeksowaniem full-text MySQL. Obsługuje 100k+ produktów z błyskawiczną prędkością.');
        $this->confirmUninstall = $this->l('Czy na pewno chcesz odinstalować moduł Szybka Wyszukiwarka? Wszystkie dane wyszukiwania zostaną usunięte.');
        
        // Dodaj informacje o hookach
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
    }

    /**
     * Instalacja modułu
     */
    public function install()
    {
        // Sprawdź wymagania systemowe
        if (!$this->checkRequirements()) {
            return false;
        }

        // Instaluj moduł i hooki
        if (!parent::install()) {
            return false;
        }

        // Rejestruj hooki - dodaj wszystkie dostępne pozycje
        $hooks_to_register = array(
            'displayTop',
            'displayNav',
            'displayNavFullWidth', 
            'displayHeader',
            'displaySearch',
            'displayTopColumn',
            'displayNavigation',
            'actionProductAdd',
            'actionProductUpdate',
            'actionProductDelete',
            'actionProductSave',
            'actionUpdateQuantity'
        );
        
        foreach ($hooks_to_register as $hook) {
            if (!$this->registerHook($hook)) {
                $this->_errors[] = sprintf('Cannot register hook %s', $hook);
                // Don't fail installation for optional hooks
                if (in_array($hook, array('actionProductAdd', 'actionProductUpdate', 'actionProductDelete'))) {
                    return false;
                }
            }
        }

        // Utwórz tabele
        if (!$this->createTables()) {
            return false;
        }

        // Skonfiguruj ustawienia
        $this->installConfiguration();

        // Zbuduj indeks
        $this->buildSearchIndex();

        return true;
    }

    /**
     * Odinstalowanie modułu
     */
    public function uninstall()
    {
        // Usuń tabele
        $this->dropTables();
        
        // Usuń konfigurację
        $this->uninstallConfiguration();
        
        // Wyczyść cache
        FastSearchCache::clear();

        return parent::uninstall();
    }

    /**
     * Sprawdza wymagania systemowe
     */
    private function checkRequirements()
    {
        $errors = array();

        // Sprawdź wersję PHP
        if (version_compare(PHP_VERSION, '7.1.0', '<')) {
            $errors[] = $this->l('Wymagana wersja PHP 7.1 lub nowsza');
        }

        // Sprawdź MySQL
        $mysql_version = Db::getInstance()->getVersion();
        if (version_compare($mysql_version, '5.6.0', '<')) {
            $errors[] = $this->l('Wymagana wersja MySQL 5.6 lub nowsza');
        }

        // Sprawdź obsługę FULLTEXT
        $engines = Db::getInstance()->executeS("SHOW ENGINES");
        $myisam_available = false;
        foreach ($engines as $engine) {
            if ($engine['Engine'] == 'MyISAM' && in_array($engine['Support'], array('YES', 'DEFAULT'))) {
                $myisam_available = true;
                break;
            }
        }
        
        if (!$myisam_available) {
            $errors[] = $this->l('Wymagana obsługa silnika MyISAM dla indeksów FULLTEXT');
        }

        // Sprawdź uprawnienia katalogów
        $cache_dir = _PS_CACHE_DIR_ . 'fastsearch/';
        if (!is_writable(_PS_CACHE_DIR_)) {
            $errors[] = $this->l('Brak uprawnień zapisu do katalogu cache');
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->_errors[] = $error;
            }
            return false;
        }

        return true;
    }

    /**
     * Tworzy wymagane tabele
     */
    private function createTables()
    {
        $success = true;

        // Tabela głównego indeksu wyszukiwania
        $sql_index = '
        CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fastsearch_index` (
            `id_product` INT(10) UNSIGNED NOT NULL,
            `id_lang` INT(10) UNSIGNED NOT NULL,
            `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
            `name` VARCHAR(255) NOT NULL DEFAULT "",
            `description_short` TEXT,
            `description` LONGTEXT,
            `reference` VARCHAR(64) DEFAULT "",
            `ean13` VARCHAR(13) DEFAULT "",
            `upc` VARCHAR(12) DEFAULT "",
            `isbn` VARCHAR(32) DEFAULT "",
            `mpn` VARCHAR(40) DEFAULT "",
            `meta_keywords` TEXT,
            `meta_title` VARCHAR(255) DEFAULT "",
            `meta_description` VARCHAR(512) DEFAULT "",
            `tags` TEXT,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `available_for_order` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `show_price` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `online_only` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `price` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `wholesale_price` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `unity` VARCHAR(255) DEFAULT "",
            `unit_price_ratio` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `additional_shipping_cost` DECIMAL(20,2) NOT NULL DEFAULT 0,
            `weight` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `out_of_stock` INT(10) UNSIGNED NOT NULL DEFAULT 2,
            `quantity` INT(10) NOT NULL DEFAULT 0,
            `minimal_quantity` INT(10) UNSIGNED NOT NULL DEFAULT 1,
            `low_stock_threshold` INT(10) DEFAULT NULL,
            `low_stock_alert` TINYINT(1) NOT NULL DEFAULT 0,
            `pack_stock_type` INT(11) UNSIGNED NOT NULL DEFAULT 3,
            `state` INT(11) UNSIGNED NOT NULL DEFAULT 1,
            `additional_delivery_times` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `delivery_in_stock` VARCHAR(255) DEFAULT "",
            `delivery_out_stock` VARCHAR(255) DEFAULT "",
            `product_type` ENUM("standard", "pack", "virtual", "combinations", "") NOT NULL DEFAULT "",
            `on_sale` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `ecotax` DECIMAL(17,6) NOT NULL DEFAULT 0,
            `minimal_quantity_fractional` TINYINT(1) NOT NULL DEFAULT 0,
            `low_stock_threshold_fractional` TINYINT(1) NOT NULL DEFAULT 0,
            `customizable` TINYINT(2) NOT NULL DEFAULT 0,
            `uploadable_files` TINYINT(4) NOT NULL DEFAULT 0,
            `text_fields` TINYINT(4) NOT NULL DEFAULT 0,
            `advanced_stock_management` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_product`, `id_lang`, `id_shop`),
            KEY `product_shop` (`id_product`, `id_shop`),
            KEY `reference` (`reference`),
            KEY `ean13` (`ean13`),
            KEY `upc` (`upc`),
            KEY `isbn` (`isbn`),
            KEY `active` (`active`),
            KEY `date_add` (`date_add`),
            KEY `date_upd` (`date_upd`),
            KEY `name` (`name`),
            KEY `price` (`price`),
            KEY `quantity` (`quantity`),
            FULLTEXT KEY `search_fulltext` (`name`, `description_short`, `description`, `reference`, `ean13`, `upc`, `isbn`, `mpn`, `meta_keywords`, `tags`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        if (!Db::getInstance()->execute($sql_index)) {
            $success = false;
            $this->_errors[] = $this->l('Nie można utworzyć tabeli indeksu wyszukiwania');
        }

        // Tabela statystyk wyszukiwania
        $sql_stats = '
        CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fastsearch_stats` (
            `id_stat` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_query` VARCHAR(255) NOT NULL,
            `results_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `click_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `conversion_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `id_lang` INT(10) UNSIGNED NOT NULL,
            `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
            `id_customer` INT(10) UNSIGNED NULL,
            `ip_address` VARCHAR(45) DEFAULT "",
            `user_agent` TEXT,
            `referer` VARCHAR(255) DEFAULT "",
            `search_time_ms` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `date_search` DATETIME NOT NULL,
            PRIMARY KEY (`id_stat`),
            KEY `search_query` (`search_query`),
            KEY `date_search` (`date_search`),
            KEY `id_lang` (`id_lang`),
            KEY `id_shop` (`id_shop`),
            KEY `results_count` (`results_count`),
            KEY `search_performance` (`search_query`, `search_time_ms`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        if (!Db::getInstance()->execute($sql_stats)) {
            $success = false;
            $this->_errors[] = $this->l('Nie można utworzyć tabeli statystyk');
        }

        // Tabela synonimów
        $sql_synonyms = '
        CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fastsearch_synonyms` (
            `id_synonym` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `word` VARCHAR(100) NOT NULL,
            `synonyms` TEXT NOT NULL,
            `id_lang` INT(10) UNSIGNED NOT NULL,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_synonym`),
            UNIQUE KEY `word_lang` (`word`, `id_lang`),
            KEY `active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        if (!Db::getInstance()->execute($sql_synonyms)) {
            $success = false;
            $this->_errors[] = $this->l('Nie można utworzyć tabeli synonimów');
        }

        return $success;
    }

    /**
     * Usuwa tabele modułu
     */
    private function dropTables()
    {
        $tables = array(
            'fastsearch_index',
            'fastsearch_stats', 
            'fastsearch_synonyms'
        );

        foreach ($tables as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }

        return true;
    }

    /**
     * Instaluje konfigurację domyślną
     */
    private function installConfiguration()
    {
        $configs = array(
            'FASTSEARCH_ENABLED' => 1,
            'FASTSEARCH_MIN_QUERY_LENGTH' => 2,
            'FASTSEARCH_MAX_RESULTS' => 15,
            'FASTSEARCH_SEARCH_IN_DESCRIPTION' => 1,
            'FASTSEARCH_SEARCH_IN_SHORT_DESCRIPTION' => 1,
            'FASTSEARCH_SEARCH_IN_REFERENCE' => 1,
            'FASTSEARCH_SEARCH_IN_EAN13' => 1,
            'FASTSEARCH_SEARCH_IN_UPC' => 1,
            'FASTSEARCH_SEARCH_IN_TAGS' => 1,
            'FASTSEARCH_SHOW_IMAGES' => 1,
            'FASTSEARCH_SHOW_PRICES' => 1,
            'FASTSEARCH_SHOW_DESCRIPTIONS' => 1,
            'FASTSEARCH_SHOW_CATEGORIES' => 1,
            'FASTSEARCH_ENABLE_STATS' => 1,
            'FASTSEARCH_CACHE_TTL' => 1800,
            'FASTSEARCH_DEBOUNCE_TIME' => 150,
            'FASTSEARCH_SECURE_KEY' => Tools::passwdGen(32),
            'FASTSEARCH_AUTO_COMPLETE' => 1,
            'FASTSEARCH_HIGHLIGHT_TERMS' => 1,
            'FASTSEARCH_FUZZY_SEARCH' => 1,
            'FASTSEARCH_SEARCH_WEIGHT_NAME' => 100,
            'FASTSEARCH_SEARCH_WEIGHT_REFERENCE' => 90,
            'FASTSEARCH_SEARCH_WEIGHT_EAN' => 85,
            'FASTSEARCH_SEARCH_WEIGHT_DESCRIPTION_SHORT' => 60,
            'FASTSEARCH_SEARCH_WEIGHT_DESCRIPTION' => 40,
            'FASTSEARCH_SEARCH_WEIGHT_TAGS' => 70,
            'FASTSEARCH_INDEX_LAST_UPDATE' => date('Y-m-d H:i:s')
        );

        foreach ($configs as $key => $value) {
            Configuration::updateValue($key, $value);
        }
    }

    /**
     * Usuwa konfigurację
     */
    private function uninstallConfiguration()
    {
        $configs = array(
            'FASTSEARCH_ENABLED',
            'FASTSEARCH_MIN_QUERY_LENGTH',
            'FASTSEARCH_MAX_RESULTS',
            'FASTSEARCH_SEARCH_IN_DESCRIPTION',
            'FASTSEARCH_SEARCH_IN_SHORT_DESCRIPTION',
            'FASTSEARCH_SEARCH_IN_REFERENCE',
            'FASTSEARCH_SEARCH_IN_EAN13',
            'FASTSEARCH_SEARCH_IN_UPC',
            'FASTSEARCH_SEARCH_IN_TAGS',
            'FASTSEARCH_SHOW_IMAGES',
            'FASTSEARCH_SHOW_PRICES',
            'FASTSEARCH_SHOW_DESCRIPTIONS',
            'FASTSEARCH_SHOW_CATEGORIES',
            'FASTSEARCH_ENABLE_STATS',
            'FASTSEARCH_CACHE_TTL',
            'FASTSEARCH_DEBOUNCE_TIME',
            'FASTSEARCH_SECURE_KEY',
            'FASTSEARCH_AUTO_COMPLETE',
            'FASTSEARCH_HIGHLIGHT_TERMS',
            'FASTSEARCH_FUZZY_SEARCH',
            'FASTSEARCH_SEARCH_WEIGHT_NAME',
            'FASTSEARCH_SEARCH_WEIGHT_REFERENCE',
            'FASTSEARCH_SEARCH_WEIGHT_EAN',
            'FASTSEARCH_SEARCH_WEIGHT_DESCRIPTION_SHORT',
            'FASTSEARCH_SEARCH_WEIGHT_DESCRIPTION',
            'FASTSEARCH_SEARCH_WEIGHT_TAGS',
            'FASTSEARCH_INDEX_LAST_UPDATE'
        );

        foreach ($configs as $config) {
            Configuration::deleteByName($config);
        }
    }

    /**
     * Buduje pełny indeks wyszukiwania
     */
    public function buildSearchIndex($limit = 1000, $offset = 0)
    {
        $start_time = microtime(true);
        $languages = Language::getLanguages(true);
        $shops = Shop::getShops(true);
        $processed = 0;

        try {
            // Jeśli offset = 0, czyścimy tabelę
            if ($offset == 0) {
                Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'fastsearch_index`');
            }

            foreach ($shops as $shop) {
                $id_shop = (int)$shop['id_shop'];
                
                foreach ($languages as $language) {
                    $id_lang = (int)$language['id_lang'];
                    
                    // Główne zapytanie z paginacją
                    $sql = 'SELECT 
                        p.id_product,
                        p.reference,
                        p.ean13,
                        p.upc,
                        p.isbn,
                        p.mpn,
                        p.active,
                        p.available_for_order,
                        p.show_price,
                        p.online_only,
                        p.price,
                        p.wholesale_price,
                        p.unity,
                        p.unit_price_ratio,
                        p.additional_shipping_cost,
                        p.weight,
                        p.out_of_stock,
                        p.minimal_quantity,
                        p.low_stock_threshold,
                        p.low_stock_alert,
                        p.pack_stock_type,
                        p.state,
                        p.additional_delivery_times,
                        p.product_type,
                        p.on_sale,
                        p.ecotax,
                        p.minimal_quantity_fractional,
                        p.low_stock_threshold_fractional,
                        p.customizable,
                        p.uploadable_files,
                        p.text_fields,
                        p.advanced_stock_management,
                        p.date_add,
                        p.date_upd,
                        COALESCE(pl.name, "") as name,
                        COALESCE(pl.description_short, "") as description_short,
                        COALESCE(pl.description, "") as description,
                        COALESCE(pl.meta_keywords, "") as meta_keywords,
                        COALESCE(pl.meta_title, "") as meta_title,
                        COALESCE(pl.meta_description, "") as meta_description,
                        COALESCE(pl.delivery_in_stock, "") as delivery_in_stock,
                        COALESCE(pl.delivery_out_stock, "") as delivery_out_stock,
                        COALESCE(sa.quantity, 0) as quantity,
                        GROUP_CONCAT(DISTINCT tl.name SEPARATOR " ") as tags
                    FROM `' . _DB_PREFIX_ . 'product` p
                    LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . $id_lang . ' AND pl.id_shop = ' . $id_shop . ')
                    LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $id_shop . ')
                    LEFT JOIN `' . _DB_PREFIX_ . 'product_tag` pt ON (p.id_product = pt.id_product AND pt.id_lang = ' . $id_lang . ')
                    LEFT JOIN `' . _DB_PREFIX_ . 'tag` t ON (pt.id_tag = t.id_tag)
                    LEFT JOIN `' . _DB_PREFIX_ . 'tag_lang` tl ON (t.id_tag = tl.id_tag AND tl.id_lang = ' . $id_lang . ')
                    WHERE 1=1
                    GROUP BY p.id_product
                    ORDER BY p.id_product ASC
                    LIMIT ' . (int)$offset . ', ' . (int)$limit;

                    $products = Db::getInstance()->executeS($sql);
                    
                    if (!$products) {
                        continue;
                    }

                    // Przygotuj dane do wstawienia
                    $values_array = array();
                    
                    foreach ($products as $product) {
                        $values_array[] = '(' . 
                            (int)$product['id_product'] . ', ' .
                            $id_lang . ', ' .
                            $id_shop . ', ' .
                            '"' . pSQL($product['name']) . '", ' .
                            '"' . pSQL($product['description_short']) . '", ' .
                            '"' . pSQL($product['description']) . '", ' .
                            '"' . pSQL($product['reference']) . '", ' .
                            '"' . pSQL($product['ean13']) . '", ' .
                            '"' . pSQL($product['upc']) . '", ' .
                            '"' . pSQL($product['isbn']) . '", ' .
                            '"' . pSQL($product['mpn']) . '", ' .
                            '"' . pSQL($product['meta_keywords']) . '", ' .
                            '"' . pSQL($product['meta_title']) . '", ' .
                            '"' . pSQL($product['meta_description']) . '", ' .
                            '"' . pSQL($product['tags']) . '", ' .
                            (int)$product['active'] . ', ' .
                            (int)$product['available_for_order'] . ', ' .
                            (int)$product['show_price'] . ', ' .
                            (int)$product['online_only'] . ', ' .
                            (float)$product['price'] . ', ' .
                            (float)$product['wholesale_price'] . ', ' .
                            '"' . pSQL($product['unity']) . '", ' .
                            (float)$product['unit_price_ratio'] . ', ' .
                            (float)$product['additional_shipping_cost'] . ', ' .
                            (float)$product['weight'] . ', ' .
                            (int)$product['out_of_stock'] . ', ' .
                            (int)$product['quantity'] . ', ' .
                            (int)$product['minimal_quantity'] . ', ' .
                            ($product['low_stock_threshold'] ? (int)$product['low_stock_threshold'] : 'NULL') . ', ' .
                            (int)$product['low_stock_alert'] . ', ' .
                            (int)$product['pack_stock_type'] . ', ' .
                            (int)$product['state'] . ', ' .
                            (int)$product['additional_delivery_times'] . ', ' .
                            '"' . pSQL($product['delivery_in_stock']) . '", ' .
                            '"' . pSQL($product['delivery_out_stock']) . '", ' .
                            '"' . pSQL($product['product_type']) . '", ' .
                            (int)$product['on_sale'] . ', ' .
                            (float)$product['ecotax'] . ', ' .
                            (int)$product['minimal_quantity_fractional'] . ', ' .
                            (int)$product['low_stock_threshold_fractional'] . ', ' .
                            (int)$product['customizable'] . ', ' .
                            (int)$product['uploadable_files'] . ', ' .
                            (int)$product['text_fields'] . ', ' .
                            (int)$product['advanced_stock_management'] . ', ' .
                            '"' . pSQL($product['date_add']) . '", ' .
                            '"' . pSQL($product['date_upd']) . '"' .
                        ')';
                        
                        $processed++;
                    }

                    if (!empty($values_array)) {
                        $insert_sql = 'INSERT INTO `' . _DB_PREFIX_ . 'fastsearch_index` 
                        (id_product, id_lang, id_shop, name, description_short, description, reference, ean13, upc, isbn, mpn, meta_keywords, meta_title, meta_description, tags, active, available_for_order, show_price, online_only, price, wholesale_price, unity, unit_price_ratio, additional_shipping_cost, weight, out_of_stock, quantity, minimal_quantity, low_stock_threshold, low_stock_alert, pack_stock_type, state, additional_delivery_times, delivery_in_stock, delivery_out_stock, product_type, on_sale, ecotax, minimal_quantity_fractional, low_stock_threshold_fractional, customizable, uploadable_files, text_fields, advanced_stock_management, date_add, date_upd) 
                        VALUES ' . implode(', ', $values_array);

                        if (!Db::getInstance()->execute($insert_sql)) {
                            throw new Exception('Błąd podczas wstawiania danych do indeksu');
                        }
                    }
                }
            }

            // Aktualizuj czas ostatniej aktualizacji
            Configuration::updateValue('FASTSEARCH_INDEX_LAST_UPDATE', date('Y-m-d H:i:s'));

            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);

            return array(
                'success' => true,
                'processed' => $processed,
                'execution_time' => $execution_time,
                'memory_usage' => memory_get_peak_usage(true)
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => $processed
            );
        }
    }

    /**
     * Aktualizuje indeks dla konkretnego produktu
     */
    public function updateProductIndex($id_product)
    {
        if (!$id_product) {
            return false;
        }

        $languages = Language::getLanguages(true);
        $shops = Shop::getShops(true);

        // Usuń stary wpis
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'fastsearch_index` WHERE id_product = ' . (int)$id_product);

        foreach ($shops as $shop) {
            $id_shop = (int)$shop['id_shop'];
            
            foreach ($languages as $language) {
                $id_lang = (int)$language['id_lang'];
                
                $sql = 'SELECT 
                    p.id_product,
                    p.reference,
                    p.ean13,
                    p.upc,
                    p.isbn,
                    p.mpn,
                    p.active,
                    p.available_for_order,
                    p.show_price,
                    p.online_only,
                    p.price,
                    p.wholesale_price,
                    p.unity,
                    p.unit_price_ratio,
                    p.additional_shipping_cost,
                    p.weight,
                    p.out_of_stock,
                    p.minimal_quantity,
                    p.low_stock_threshold,
                    p.low_stock_alert,
                    p.pack_stock_type,
                    p.state,
                    p.additional_delivery_times,
                    p.product_type,
                    p.on_sale,
                    p.ecotax,
                    p.minimal_quantity_fractional,
                    p.low_stock_threshold_fractional,
                    p.customizable,
                    p.uploadable_files,
                    p.text_fields,
                    p.advanced_stock_management,
                    p.date_add,
                    p.date_upd,
                    COALESCE(pl.name, "") as name,
                    COALESCE(pl.description_short, "") as description_short,
                    COALESCE(pl.description, "") as description,
                    COALESCE(pl.meta_keywords, "") as meta_keywords,
                    COALESCE(pl.meta_title, "") as meta_title,
                    COALESCE(pl.meta_description, "") as meta_description,
                    COALESCE(pl.delivery_in_stock, "") as delivery_in_stock,
                    COALESCE(pl.delivery_out_stock, "") as delivery_out_stock,
                    COALESCE(sa.quantity, 0) as quantity,
                    GROUP_CONCAT(DISTINCT tl.name SEPARATOR " ") as tags
                FROM `' . _DB_PREFIX_ . 'product` p
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . $id_lang . ' AND pl.id_shop = ' . $id_shop . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa ON (p.id_product = sa.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . $id_shop . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'product_tag` pt ON (p.id_product = pt.id_product AND pt.id_lang = ' . $id_lang . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'tag` t ON (pt.id_tag = t.id_tag)
                LEFT JOIN `' . _DB_PREFIX_ . 'tag_lang` tl ON (t.id_tag = tl.id_tag AND tl.id_lang = ' . $id_lang . ')
                WHERE p.id_product = ' . (int)$id_product . '
                GROUP BY p.id_product';

                $product = Db::getInstance()->getRow($sql);
                
                if ($product) {
                    $insert_sql = 'INSERT INTO `' . _DB_PREFIX_ . 'fastsearch_index` 
                    (id_product, id_lang, id_shop, name, description_short, description, reference, ean13, upc, isbn, mpn, meta_keywords, meta_title, meta_description, tags, active, available_for_order, show_price, online_only, price, wholesale_price, unity, unit_price_ratio, additional_shipping_cost, weight, out_of_stock, quantity, minimal_quantity, low_stock_threshold, low_stock_alert, pack_stock_type, state, additional_delivery_times, delivery_in_stock, delivery_out_stock, product_type, on_sale, ecotax, minimal_quantity_fractional, low_stock_threshold_fractional, customizable, uploadable_files, text_fields, advanced_stock_management, date_add, date_upd) 
                    VALUES (' . 
                        (int)$product['id_product'] . ', ' .
                        $id_lang . ', ' .
                        $id_shop . ', ' .
                        '"' . pSQL($product['name']) . '", ' .
                        '"' . pSQL($product['description_short']) . '", ' .
                        '"' . pSQL($product['description']) . '", ' .
                        '"' . pSQL($product['reference']) . '", ' .
                        '"' . pSQL($product['ean13']) . '", ' .
                        '"' . pSQL($product['upc']) . '", ' .
                        '"' . pSQL($product['isbn']) . '", ' .
                        '"' . pSQL($product['mpn']) . '", ' .
                        '"' . pSQL($product['meta_keywords']) . '", ' .
                        '"' . pSQL($product['meta_title']) . '", ' .
                        '"' . pSQL($product['meta_description']) . '", ' .
                        '"' . pSQL($product['tags']) . '", ' .
                        (int)$product['active'] . ', ' .
                        (int)$product['available_for_order'] . ', ' .
                        (int)$product['show_price'] . ', ' .
                        (int)$product['online_only'] . ', ' .
                        (float)$product['price'] . ', ' .
                        (float)$product['wholesale_price'] . ', ' .
                        '"' . pSQL($product['unity']) . '", ' .
                        (float)$product['unit_price_ratio'] . ', ' .
                        (float)$product['additional_shipping_cost'] . ', ' .
                        (float)$product['weight'] . ', ' .
                        (int)$product['out_of_stock'] . ', ' .
                        (int)$product['quantity'] . ', ' .
                        (int)$product['minimal_quantity'] . ', ' .
                        ($product['low_stock_threshold'] ? (int)$product['low_stock_threshold'] : 'NULL') . ', ' .
                        (int)$product['low_stock_alert'] . ', ' .
                        (int)$product['pack_stock_type'] . ', ' .
                        (int)$product['state'] . ', ' .
                        (int)$product['additional_delivery_times'] . ', ' .
                        '"' . pSQL($product['delivery_in_stock']) . '", ' .
                        '"' . pSQL($product['delivery_out_stock']) . '", ' .
                        '"' . pSQL($product['product_type']) . '", ' .
                        (int)$product['on_sale'] . ', ' .
                        (float)$product['ecotax'] . ', ' .
                        (int)$product['minimal_quantity_fractional'] . ', ' .
                        (int)$product['low_stock_threshold_fractional'] . ', ' .
                        (int)$product['customizable'] . ', ' .
                        (int)$product['uploadable_files'] . ', ' .
                        (int)$product['text_fields'] . ', ' .
                        (int)$product['advanced_stock_management'] . ', ' .
                        '"' . pSQL($product['date_add']) . '", ' .
                        '"' . pSQL($product['date_upd']) . '"' .
                    ')';

                    Db::getInstance()->execute($insert_sql);
                }
            }
        }
        
        return true;
    }

    /**
     * Główna funkcja wyszukiwania z optymalizacjami
     */
    public function searchProducts($query, $limit = 20, $offset = 0, $filters = array())
    {
        $start_time = microtime(true);
        
        if (strlen($query) < Configuration::get('FASTSEARCH_MIN_QUERY_LENGTH', 2)) {
            return array('products' => array(), 'total' => 0, 'execution_time' => 0);
        }

        // Sprawdź cache
        $cache_key = 'search_' . md5($query . '_' . $limit . '_' . $offset . '_' . serialize($filters) . '_' . $this->context->language->id . '_' . $this->context->shop->id);
        FastSearchCache::init();
        
        $cached_results = FastSearchCache::get($cache_key);
        if ($cached_results !== false && Configuration::get('FASTSEARCH_CACHE_TTL', 1800) > 0) {
            return $cached_results;
        }

        $id_lang = (int)$this->context->language->id;
        $id_shop = (int)$this->context->shop->id;
        $query_escaped = pSQL($query);
        
        // Przygotuj wagi wyszukiwania
        $weight_name = (int)Configuration::get('FASTSEARCH_SEARCH_WEIGHT_NAME', 100);
        $weight_reference = (int)Configuration::get('FASTSEARCH_SEARCH_WEIGHT_REFERENCE', 90);
        $weight_ean = (int)Configuration::get('FASTSEARCH_SEARCH_WEIGHT_EAN', 85);
        $weight_desc_short = (int)Configuration::get('FASTSEARCH_SEARCH_WEIGHT_DESCRIPTION_SHORT', 60);
        $weight_desc = (int)Configuration::get('FASTSEARCH_SEARCH_WEIGHT_DESCRIPTION', 40);
        $weight_tags = (int)Configuration::get('FASTSEARCH_SEARCH_WEIGHT_TAGS', 70);

        // Przygotuj zapytanie FULLTEXT
        $search_query = '"' . str_replace('"', '""', $query) . '"';
        
        // Buduj warunki WHERE
        $where_conditions = array();
        $where_conditions[] = 'fsi.id_lang = ' . $id_lang;
        $where_conditions[] = 'fsi.id_shop = ' . $id_shop;
        $where_conditions[] = 'fsi.active = 1';
        
        // Dodaj filtry
        if (isset($filters['price_min']) && $filters['price_min'] > 0) {
            $where_conditions[] = 'fsi.price >= ' . (float)$filters['price_min'];
        }
        if (isset($filters['price_max']) && $filters['price_max'] > 0) {
            $where_conditions[] = 'fsi.price <= ' . (float)$filters['price_max'];
        }
        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $where_conditions[] = 'fsi.quantity > 0';
        }
        if (isset($filters['category']) && $filters['category'] > 0) {
            $where_conditions[] = 'cp.id_category = ' . (int)$filters['category'];
        }

        // Główne zapytanie wyszukiwania z wagami
        $sql = 'SELECT DISTINCT
            fsi.id_product,
            fsi.name,
            fsi.description_short,
            fsi.reference,
            fsi.ean13,
            fsi.upc,
            fsi.price,
            fsi.quantity,
            fsi.active,
            fsi.available_for_order,
            fsi.show_price,
            p.id_default_image,
            cl.name as category_name,
            cp.id_category,
            (
                CASE 
                    WHEN fsi.name = "' . $query_escaped . '" THEN ' . $weight_name . ' * 2
                    WHEN fsi.name LIKE "' . $query_escaped . '%" THEN ' . $weight_name . ' * 1.5
                    WHEN fsi.name LIKE "%' . $query_escaped . '%" THEN ' . $weight_name . '
                    ELSE 0
                END +
                CASE 
                    WHEN fsi.reference = "' . $query_escaped . '" THEN ' . $weight_reference . '
                    WHEN fsi.reference LIKE "' . $query_escaped . '%" THEN ' . $weight_reference . ' * 0.8
                    ELSE 0
                END +
                CASE 
                    WHEN fsi.ean13 = "' . $query_escaped . '" THEN ' . $weight_ean . '
                    WHEN fsi.upc = "' . $query_escaped . '" THEN ' . $weight_ean . ' * 0.9
                    ELSE 0
                END +
                CASE 
                    WHEN fsi.description_short LIKE "%' . $query_escaped . '%" THEN ' . $weight_desc_short . '
                    ELSE 0
                END +
                CASE 
                    WHEN fsi.description LIKE "%' . $query_escaped . '%" THEN ' . $weight_desc . '
                    ELSE 0
                END +
                CASE 
                    WHEN fsi.tags LIKE "%' . $query_escaped . '%" THEN ' . $weight_tags . '
                    ELSE 0
                END +
                (MATCH(fsi.name, fsi.description_short, fsi.description, fsi.reference, fsi.ean13, fsi.upc, fsi.tags) 
                AGAINST("' . pSQL($search_query) . '" IN BOOLEAN MODE) * 10)
            ) as relevance_score
        FROM `' . _DB_PREFIX_ . 'fastsearch_index` fsi
        LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON (fsi.id_product = p.id_product)
        LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON (p.id_product = cp.id_product AND cp.id_category != 1)
        LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cp.id_category = cl.id_category AND cl.id_lang = ' . $id_lang . ')
        WHERE ' . implode(' AND ', $where_conditions) . '
        AND (
            MATCH(fsi.name, fsi.description_short, fsi.description, fsi.reference, fsi.ean13, fsi.upc, fsi.tags) 
            AGAINST("' . pSQL($search_query) . '" IN BOOLEAN MODE)
            OR fsi.name LIKE "%' . $query_escaped . '%"
            OR fsi.reference LIKE "%' . $query_escaped . '%"
            OR fsi.ean13 = "' . $query_escaped . '"
            OR fsi.upc = "' . $query_escaped . '"
            OR fsi.description_short LIKE "%' . $query_escaped . '%"
            OR fsi.tags LIKE "%' . $query_escaped . '%"
        )
        HAVING relevance_score > 0
        ORDER BY relevance_score DESC, fsi.name ASC
        LIMIT ' . (int)$offset . ', ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);
        
        if (!$results) {
            $results = array();
        }

        // Zapytanie o całkowitą liczbę wyników
        $count_sql = 'SELECT COUNT(DISTINCT fsi.id_product) as total
        FROM `' . _DB_PREFIX_ . 'fastsearch_index` fsi
        LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON (fsi.id_product = p.id_product)
        LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON (p.id_product = cp.id_product AND cp.id_category != 1)
        WHERE ' . implode(' AND ', $where_conditions) . '
        AND (
            MATCH(fsi.name, fsi.description_short, fsi.description, fsi.reference, fsi.ean13, fsi.upc, fsi.tags) 
            AGAINST("' . pSQL($search_query) . '" IN BOOLEAN MODE)
            OR fsi.name LIKE "%' . $query_escaped . '%"
            OR fsi.reference LIKE "%' . $query_escaped . '%"
            OR fsi.ean13 = "' . $query_escaped . '"
            OR fsi.upc = "' . $query_escaped . '"
            OR fsi.description_short LIKE "%' . $query_escaped . '%"
            OR fsi.tags LIKE "%' . $query_escaped . '%"
        )';

        $total_result = Db::getInstance()->getRow($count_sql);
        $total = $total_result ? (int)$total_result['total'] : 0;

        // Przetwarzanie wyników
        foreach ($results as &$result) {
            // Dodaj obrazki
            if ($result['id_default_image']) {
                $result['image_url'] = $this->context->link->getImageLink(
                    Tools::str2url($result['name']),
                    $result['id_product'] . '-' . $result['id_default_image'],
                    ImageType::getFormatedName('home')
                );
                $result['image_url_small'] = $this->context->link->getImageLink(
                    Tools::str2url($result['name']),
                    $result['id_product'] . '-' . $result['id_default_image'],
                    ImageType::getFormatedName('small')
                );
            } else {
                $result['image_url'] = $this->context->link->getImageLink(
                    '',
                    $this->context->language->iso_code . '-default',
                    ImageType::getFormatedName('home')
                );
                $result['image_url_small'] = $this->context->link->getImageLink(
                    '',
                    $this->context->language->iso_code . '-default',
                    ImageType::getFormatedName('small')
                );
            }
            
            // Link do produktu
            $result['product_url'] = $this->context->link->getProductLink(
                $result['id_product'],
                Tools::str2url($result['name'])
            );
            
            // Formatuj cenę
            $result['formatted_price'] = Tools::displayPrice($result['price']);
            
            // Skróć opis jeśli za długi
            if (strlen($result['description_short']) > 150) {
                $result['description_short'] = Tools::substr($result['description_short'], 0, 147) . '...';
            }
            
            // Dostępność
            $result['available'] = $result['quantity'] > 0 || $result['available_for_order'];
            $result['availability_message'] = $result['available'] ? 
                $this->l('Dostępny') : 
                $this->l('Niedostępny');
        }

        $end_time = microtime(true);
        $execution_time = round(($end_time - $start_time) * 1000, 2);

        $search_result = array(
            'products' => $results,
            'total' => $total,
            'execution_time' => $execution_time,
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset
        );

        // Zapisz w cache
        if (Configuration::get('FASTSEARCH_CACHE_TTL', 1800) > 0) {
            FastSearchCache::set($cache_key, $search_result, Configuration::get('FASTSEARCH_CACHE_TTL', 1800));
        }

        // Loguj wyszukiwanie
        if (Configuration::get('FASTSEARCH_ENABLE_STATS', 1)) {
            $this->logSearchQuery($query, $total, $execution_time);
        }

        return $search_result;
    }

    /**
     * Wyszukiwanie sugestii
     */
    public function getSuggestions($query, $limit = 5)
    {
        if (strlen($query) < 2) {
            return array();
        }

        $id_lang = (int)$this->context->language->id;
        $id_shop = (int)$this->context->shop->id;
        $query_escaped = pSQL($query);
        
        $sql = '
        SELECT DISTINCT 
            LEFT(name, 50) as suggestion, 
            COUNT(*) as frequency,
            AVG(price) as avg_price
        FROM `' . _DB_PREFIX_ . 'fastsearch_index`
        WHERE id_lang = ' . $id_lang . '
        AND id_shop = ' . $id_shop . '
        AND active = 1
        AND name LIKE "' . $query_escaped . '%"
        AND name != ""
        GROUP BY LEFT(name, 50)
        ORDER BY frequency DESC, suggestion ASC
        LIMIT ' . (int)$limit;
        
        return Db::getInstance()->executeS($sql);
    }

    /**
     * Logowanie wyszukiwań do statystyk
     */
    public function logSearchQuery($query, $results_count = 0, $execution_time = 0)
    {
        if (strlen($query) < 2 || !Configuration::get('FASTSEARCH_ENABLE_STATS', 1)) {
            return;
        }
        
        $id_customer = $this->context->customer->isLogged() ? (int)$this->context->customer->id : null;
        $ip_address = Tools::getRemoteAddr();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'fastsearch_stats` 
                (search_query, results_count, id_lang, id_shop, id_customer, ip_address, user_agent, referer, search_time_ms, date_search) VALUES 
                ("' . pSQL($query) . '", ' . (int)$results_count . ', ' . (int)$this->context->language->id . ', ' . (int)$this->context->shop->id . ', ' . ($id_customer ? $id_customer : 'NULL') . ', "' . pSQL($ip_address) . '", "' . pSQL($user_agent) . '", "' . pSQL($referer) . '", ' . (int)$execution_time . ', NOW())';
        
        Db::getInstance()->execute($sql);
    }

    /**
     * Hooki do aktualizacji indeksu
     */
    public function hookActionProductAdd($params)
    {
        if (isset($params['id_product'])) {
            $this->updateProductIndex($params['id_product']);
        }
    }

    public function hookActionProductUpdate($params)
    {
        if (isset($params['id_product'])) {
            $this->updateProductIndex($params['id_product']);
        }
    }

    public function hookActionProductSave($params)
    {
        if (isset($params['id_product'])) {
            $this->updateProductIndex($params['id_product']);
        }
    }

    public function hookActionProductDelete($params)
    {
        if (isset($params['id_product'])) {
            Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'fastsearch_index` WHERE id_product = ' . (int)$params['id_product']);
        }
    }

    public function hookActionUpdateQuantity($params)
    {
        if (isset($params['id_product'])) {
            // Aktualizuj tylko pole quantity bez przebudowy całego indeksu
            $quantity = isset($params['quantity']) ? (int)$params['quantity'] : 0;
            Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'fastsearch_index` SET quantity = ' . $quantity . ' WHERE id_product = ' . (int)$params['id_product']);
        }
    }

    /**
     * Hook wyświetlający wyszukiwarkę - uniwersalny dla różnych pozycji
     */
    public function hookDisplayTop($params = null)
    {
        return $this->displaySearchWidget($params);
    }
    
    public function hookDisplayNav($params = null)
    {
        return $this->displaySearchWidget($params);
    }
    
    public function hookDisplayNavFullWidth($params = null)
    {
        return $this->displaySearchWidget($params);
    }
    
    public function hookDisplaySearch($params = null)
    {
        return $this->displaySearchWidget($params);
    }
    
    public function hookDisplayTopColumn($params = null)
    {
        return $this->displaySearchWidget($params);
    }
    
    public function hookDisplayNavigation($params = null)
    {
        return $this->displaySearchWidget($params);
    }
    
    /**
     * Główna funkcja wyświetlająca widget wyszukiwarki
     */
    private function displaySearchWidget($params = null)
    {
        if (!Configuration::get('FASTSEARCH_ENABLED', 1)) {
            return '';
        }

        // Dodaj CSS i JS
        $this->context->controller->addJS($this->_path . 'views/js/fastsearch.js');
        $this->context->controller->addCSS($this->_path . 'views/css/fastsearch.css');
        
        // Przygotuj zmienne dla szablonu
        $this->smarty->assign(array(
            'search_url' => $this->context->link->getModuleLink('fastsearch', 'search'),
            'module_dir' => $this->_path,
            'min_query_length' => Configuration::get('FASTSEARCH_MIN_QUERY_LENGTH', 2),
            'max_results' => Configuration::get('FASTSEARCH_MAX_RESULTS', 15),
            'debounce_time' => Configuration::get('FASTSEARCH_DEBOUNCE_TIME', 150),
            'show_images' => Configuration::get('FASTSEARCH_SHOW_IMAGES', 1),
            'show_prices' => Configuration::get('FASTSEARCH_SHOW_PRICES', 1),
            'show_descriptions' => Configuration::get('FASTSEARCH_SHOW_DESCRIPTIONS', 1),
            'show_categories' => Configuration::get('FASTSEARCH_SHOW_CATEGORIES', 1),
            'enable_voice_search' => Configuration::get('FASTSEARCH_VOICE_SEARCH', 0),
            'fastsearch_enabled' => true,
            'language' => $this->context->language,
            'currency' => $this->context->currency,
            'urls' => array(
                'no_picture_image' => array(
                    'bySize' => array(
                        'home_default' => array(
                            'url' => $this->context->link->getImageLink('', $this->context->language->iso_code . '-default', 'home_default')
                        )
                    )
                )
            )
        ));
        
        return $this->display(__FILE__, 'fastsearch.tpl');
    }

    /**
     * Hook do dodania meta tagów
     */
    public function hookDisplayHeader()
    {
        if (!Configuration::get('FASTSEARCH_ENABLED', 1)) {
            return '';
        }
        
        return '<meta name="fastsearch-enabled" content="true">';
    }

    /**
     * Panel administracyjny
     */
    public function getContent()
    {
        $output = '';
        
        // Przetwarzanie formularzy
        if (Tools::isSubmit('submit_fastsearch_config')) {
            $output .= $this->processConfiguration();
        }
        
        if (Tools::isSubmit('submit_rebuild_index')) {
            $output .= $this->processRebuildIndex();
        }
        
        if (Tools::isSubmit('submit_optimize_index')) {
            $output .= $this->processOptimizeIndex();
        }
        
        if (Tools::isSubmit('submit_clear_cache')) {
            $output .= $this->processClearCache();
        }
        
        if (Tools::isSubmit('submit_export_stats')) {
            $this->processExportStats();
        }

        // Wyświetl statystyki
        $output .= $this->displayStats();
        
        // Formularz konfiguracji
        $output .= $this->displayConfiguration();
        
        // Narzędzia administracyjne
        $output .= $this->displayAdminTools();

        return $output;
    }

    /**
     * Przetwarzanie konfiguracji
     */
    private function processConfiguration()
    {
        $configs = array(
            'FASTSEARCH_ENABLED',
            'FASTSEARCH_MIN_QUERY_LENGTH',
            'FASTSEARCH_MAX_RESULTS',
            'FASTSEARCH_SEARCH_IN_DESCRIPTION',
            'FASTSEARCH_SEARCH_IN_SHORT_DESCRIPTION',
            'FASTSEARCH_SEARCH_IN_REFERENCE',
            'FASTSEARCH_SEARCH_IN_EAN13',
            'FASTSEARCH_SEARCH_IN_UPC',
            'FASTSEARCH_SEARCH_IN_TAGS',
            'FASTSEARCH_SHOW_IMAGES',
            'FASTSEARCH_SHOW_PRICES',
            'FASTSEARCH_SHOW_DESCRIPTIONS',
            'FASTSEARCH_SHOW_CATEGORIES',
            'FASTSEARCH_ENABLE_STATS',
            'FASTSEARCH_CACHE_TTL',
            'FASTSEARCH_DEBOUNCE_TIME',
            'FASTSEARCH_AUTO_COMPLETE',
            'FASTSEARCH_HIGHLIGHT_TERMS',
            'FASTSEARCH_FUZZY_SEARCH'
        );

        foreach ($configs as $config) {
            $value = Tools::getValue($config);
            Configuration::updateValue($config, $value);
        }

        return $this->displayConfirmation($this->l('Konfiguracja została zapisana pomyślnie!'));
    }

    /**
     * Przebudowa indeksu
     */
    private function processRebuildIndex()
    {
        $limit = (int)Tools::getValue('batch_size', 1000);
        $offset = (int)Tools::getValue('offset', 0);
        
        $result = $this->buildSearchIndex($limit, $offset);
        
        if ($result['success']) {
            $message = sprintf(
                $this->l('Indeks został przebudowany pomyślnie! Przetworzono %d produktów w %s ms. Użyto %s MB pamięci.'),
                $result['processed'],
                $result['execution_time'],
                round($result['memory_usage'] / 1024 / 1024, 2)
            );
            return $this->displayConfirmation($message);
        } else {
            return $this->displayError($this->l('Błąd podczas przebudowy indeksu: ') . $result['error']);
        }
    }

    /**
     * Optymalizacja indeksu
     */
    private function processOptimizeIndex()
    {
        try {
            $optimizer = new FastSearchOptimizer($this);
            $optimizer->optimizePerformance();
            
            return $this->displayConfirmation($this->l('Indeks został zoptymalizowany pomyślnie!'));
        } catch (Exception $e) {
            return $this->displayError($this->l('Błąd podczas optymalizacji: ') . $e->getMessage());
        }
    }

    /**
     * Czyszczenie cache
     */
    private function processClearCache()
    {
        FastSearchCache::clear();
        return $this->displayConfirmation($this->l('Cache został wyczyszczony pomyślnie!'));
    }

    /**
     * Eksport statystyk
     */
    private function processExportStats()
    {
        $stats = $this->getSearchStats(30);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=fastsearch_stats_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // Header CSV
        fputcsv($output, array(
            'Zapytanie',
            'Liczba wyszukiwań',
            'Średnia liczba wyników',
            'Średni czas wykonania (ms)',
            'Ostatnie wyszukiwanie'
        ));
        
        // Dane
        foreach ($stats as $stat) {
            fputcsv($output, array(
                $stat['search_query'],
                $stat['search_count'],
                $stat['avg_results'],
                $stat['avg_execution_time'],
                $stat['last_search']
            ));
        }
        
        fclose($output);
        exit;
    }

    /**
     * Pobiera statystyki wyszukiwania
     */
    public function getSearchStats($days = 30)
    {
        $sql = '
        SELECT 
            search_query,
            COUNT(*) as search_count,
            AVG(results_count) as avg_results,
            AVG(search_time_ms) as avg_execution_time,
            MAX(date_search) as last_search
        FROM `' . _DB_PREFIX_ . 'fastsearch_stats`
        WHERE date_search >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)
        AND id_shop = ' . (int)$this->context->shop->id . '
        GROUP BY search_query
        ORDER BY search_count DESC, avg_execution_time ASC
        LIMIT 100';
        
        return Db::getInstance()->executeS($sql);
    }

    /**
     * Wyświetla statystyki w panelu admin
     */
    private function displayStats()
    {
        $stats = $this->getSearchStats(7);
        $total_searches = 0;
        $avg_execution_time = 0;
        
        if ($stats) {
            foreach ($stats as $stat) {
                $total_searches += $stat['search_count'];
                $avg_execution_time += $stat['avg_execution_time'];
            }
            $avg_execution_time = $avg_execution_time / count($stats);
        }

        // Statystyki indeksu
        $index_stats = Db::getInstance()->getRow('
            SELECT 
                COUNT(*) as total_products,
                COUNT(DISTINCT id_product) as unique_products,
                MAX(date_upd) as last_update
            FROM `' . _DB_PREFIX_ . 'fastsearch_index`
            WHERE active = 1
        ');

        $html = '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-bar-chart"></i>
                ' . $this->l('Statystyki FastSearch (ostatnie 7 dni)') . '
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="kpi-container">
                            <div class="kpi kpi-success">
                                <i class="icon-search"></i>
                                <span class="kpi-value">' . $total_searches . '</span>
                                <span>' . $this->l('Wyszukiwań') . '</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-container">
                            <div class="kpi kpi-primary">
                                <i class="icon-clock-o"></i>
                                <span class="kpi-value">' . round($avg_execution_time, 1) . 'ms</span>
                                <span>' . $this->l('Średni czas') . '</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-container">
                            <div class="kpi kpi-info">
                                <i class="icon-cubes"></i>
                                <span class="kpi-value">' . ($index_stats['unique_products'] ?? 0) . '</span>
                                <span>' . $this->l('Produktów w indeksie') . '</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-container">
                            <div class="kpi kpi-warning">
                                <i class="icon-refresh"></i>
                                <span class="kpi-value">' . (isset($index_stats['last_update']) ? date('d.m.Y', strtotime($index_stats['last_update'])) : '-') . '</span>
                                <span>' . $this->l('Ostatnia aktualizacja') . '</span>
                            </div>
                        </div>
                    </div>
                </div>';

        if (!empty($stats)) {
            $html .= '
                <hr>
                <h4>' . $this->l('Najpopularniejsze wyszukiwania') . '</h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th>' . $this->l('Zapytanie') . '</th>
                            <th>' . $this->l('Liczba wyszukiwań') . '</th>
                            <th>' . $this->l('Średnia liczba wyników') . '</th>
                            <th>' . $this->l('Średni czas (ms)') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            foreach (array_slice($stats, 0, 10) as $stat) {
                $html .= '
                        <tr>
                            <td><strong>' . htmlspecialchars($stat['search_query']) . '</strong></td>
                            <td>' . $stat['search_count'] . '</td>
                            <td>' . round($stat['avg_results'], 1) . '</td>
                            <td>' . round($stat['avg_execution_time'], 1) . '</td>
                        </tr>';
            }
            
            $html .= '
                    </tbody>
                </table>';
        }

        $html .= '
            </div>
        </div>';

        return $html;
    }

    /**
     * Wyświetla formularz konfiguracji
     */
    private function displayConfiguration()
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submit_fastsearch_config';
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->show_cancel_button = false;

        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Konfiguracja Szybkiej Wyszukiwarki'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Włącz moduł'),
                        'name' => 'FASTSEARCH_ENABLED',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Minimalna długość zapytania'),
                        'name' => 'FASTSEARCH_MIN_QUERY_LENGTH',
                        'class' => 'input-fixed-width-sm',
                        'desc' => $this->l('Minimalna liczba znaków wymagana do uruchomienia wyszukiwania')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Maksymalna liczba wyników'),
                        'name' => 'FASTSEARCH_MAX_RESULTS',
                        'class' => 'input-fixed-width-sm',
                        'desc' => $this->l('Maksymalna liczba produktów wyświetlanych w wynikach')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Czas debounce (ms)'),
                        'name' => 'FASTSEARCH_DEBOUNCE_TIME',
                        'class' => 'input-fixed-width-sm',
                        'desc' => $this->l('Opóźnienie przed wysłaniem zapytania podczas pisania (w milisekundach)')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Czas cache (sekundy)'),
                        'name' => 'FASTSEARCH_CACHE_TTL',
                        'class' => 'input-fixed-width-sm',
                        'desc' => $this->l('Jak długo wyniki są przechowywane w cache (0 = wyłączone)')
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Zapisz konfigurację'),
                    'class' => 'btn btn-default pull-right'
                )
            )
        );

        $search_fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Pola wyszukiwania'),
                    'icon' => 'icon-search'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Wyszukuj w krótkimi opisie'),
                        'name' => 'FASTSEARCH_SEARCH_IN_SHORT_DESCRIPTION',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'short_desc_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'short_desc_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Wyszukuj w pełnym opisie'),
                        'name' => 'FASTSEARCH_SEARCH_IN_DESCRIPTION',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'desc_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'desc_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Wyszukuj w referencji (SKU)'),
                        'name' => 'FASTSEARCH_SEARCH_IN_REFERENCE',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'ref_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'ref_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Wyszukuj w kodzie EAN13'),
                        'name' => 'FASTSEARCH_SEARCH_IN_EAN13',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'ean_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'ean_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Wyszukuj w kodzie UPC'),
                        'name' => 'FASTSEARCH_SEARCH_IN_UPC',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'upc_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'upc_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Wyszukuj w tagach'),
                        'name' => 'FASTSEARCH_SEARCH_IN_TAGS',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'tags_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'tags_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Zapisz ustawienia pól'),
                    'class' => 'btn btn-default pull-right'
                )
            )
        );

        $display_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Ustawienia wyświetlania'),
                    'icon' => 'icon-eye'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Pokaż obrazki produktów'),
                        'name' => 'FASTSEARCH_SHOW_IMAGES',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'images_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'images_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Pokaż ceny produktów'),
                        'name' => 'FASTSEARCH_SHOW_PRICES',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'prices_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'prices_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Pokaż opisy produktów'),
                        'name' => 'FASTSEARCH_SHOW_DESCRIPTIONS',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'desc_show_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'desc_show_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Pokaż kategorie produktów'),
                        'name' => 'FASTSEARCH_SHOW_CATEGORIES',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'cat_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'cat_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Podświetlaj wyszukiwane frazy'),
                        'name' => 'FASTSEARCH_HIGHLIGHT_TERMS',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'highlight_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'highlight_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Włącz zbieranie statystyk'),
                        'name' => 'FASTSEARCH_ENABLE_STATS',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'stats_on', 'value' => 1, 'label' => $this->l('Tak')),
                            array('id' => 'stats_off', 'value' => 0, 'label' => $this->l('Nie'))
                        ),
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Zapisz ustawienia wyświetlania'),
                    'class' => 'btn btn-default pull-right'
                )
            )
        );

        // Aktualne wartości
        $helper->fields_value = array(
            'FASTSEARCH_ENABLED' => Configuration::get('FASTSEARCH_ENABLED', 1),
            'FASTSEARCH_MIN_QUERY_LENGTH' => Configuration::get('FASTSEARCH_MIN_QUERY_LENGTH', 2),
            'FASTSEARCH_MAX_RESULTS' => Configuration::get('FASTSEARCH_MAX_RESULTS', 15),
            'FASTSEARCH_DEBOUNCE_TIME' => Configuration::get('FASTSEARCH_DEBOUNCE_TIME', 150),
            'FASTSEARCH_CACHE_TTL' => Configuration::get('FASTSEARCH_CACHE_TTL', 1800),
            'FASTSEARCH_SEARCH_IN_SHORT_DESCRIPTION' => Configuration::get('FASTSEARCH_SEARCH_IN_SHORT_DESCRIPTION', 1),
            'FASTSEARCH_SEARCH_IN_DESCRIPTION' => Configuration::get('FASTSEARCH_SEARCH_IN_DESCRIPTION', 1),
            'FASTSEARCH_SEARCH_IN_REFERENCE' => Configuration::get('FASTSEARCH_SEARCH_IN_REFERENCE', 1),
            'FASTSEARCH_SEARCH_IN_EAN13' => Configuration::get('FASTSEARCH_SEARCH_IN_EAN13', 1),
            'FASTSEARCH_SEARCH_IN_UPC' => Configuration::get('FASTSEARCH_SEARCH_IN_UPC', 1),
            'FASTSEARCH_SEARCH_IN_TAGS' => Configuration::get('FASTSEARCH_SEARCH_IN_TAGS', 1),
            'FASTSEARCH_SHOW_IMAGES' => Configuration::get('FASTSEARCH_SHOW_IMAGES', 1),
            'FASTSEARCH_SHOW_PRICES' => Configuration::get('FASTSEARCH_SHOW_PRICES', 1),
            'FASTSEARCH_SHOW_DESCRIPTIONS' => Configuration::get('FASTSEARCH_SHOW_DESCRIPTIONS', 1),
            'FASTSEARCH_SHOW_CATEGORIES' => Configuration::get('FASTSEARCH_SHOW_CATEGORIES', 1),
            'FASTSEARCH_HIGHLIGHT_TERMS' => Configuration::get('FASTSEARCH_HIGHLIGHT_TERMS', 1),
            'FASTSEARCH_ENABLE_STATS' => Configuration::get('FASTSEARCH_ENABLE_STATS', 1)
        );

        return $helper->generateForm(array($form, $search_fields_form, $display_form));
    }

    /**
     * Wyświetla narzędzia administracyjne
     */
    private function displayAdminTools()
    {
        $html = '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-wrench"></i>
                ' . $this->l('Narzędzia administracyjne') . '
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4>' . $this->l('Zarządzanie indeksem') . '</h4>
                        <form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '">
                            <div class="form-group">
                                <label>' . $this->l('Rozmiar paczki') . '</label>
                                <input type="number" name="batch_size" value="1000" min="100" max="10000" class="form-control input-fixed-width-sm">
                                <p class="help-block">' . $this->l('Liczba produktów przetwarzanych jednocześnie') . '</p>
                            </div>
                            <button type="submit" name="submit_rebuild_index" class="btn btn-primary">
                                <i class="icon-refresh"></i> ' . $this->l('Przebuduj indeks') . '
                            </button>
                            <button type="submit" name="submit_optimize_index" class="btn btn-info">
                                <i class="icon-magic"></i> ' . $this->l('Optymalizuj indeks') . '
                            </button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <h4>' . $this->l('Cache i statystyki') . '</h4>
                        <form method="post" action="' . AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules') . '">
                            <button type="submit" name="submit_clear_cache" class="btn btn-warning">
                                <i class="icon-trash"></i> ' . $this->l('Wyczyść cache') . '
                            </button>
                            <button type="submit" name="submit_export_stats" class="btn btn-success">
                                <i class="icon-download"></i> ' . $this->l('Eksportuj statystyki (CSV)') . '
                            </button>
                        </form>
                    </div>
                </div>
                
                <hr>
                
                <div class="alert alert-info">
                    <h4>' . $this->l('Automatyzacja (Cron Jobs)') . '</h4>
                    <p>' . $this->l('Dla optymalnej wydajności zaleca się skonfigurowanie następujących zadań cron:') . '</p>
                    <pre><code># Optymalizacja co godzinę
0 * * * * wget -q -O - "' . $this->context->shop->getBaseURL() . 'module/fastsearch/cronoptimize?secure_key=' . Configuration::get('FASTSEARCH_SECURE_KEY') . '" > /dev/null 2>&1

# Pełna przebudowa indeksu raz dziennie (w nocy)  
0 2 * * * wget -q -O - "' . $this->context->shop->getBaseURL() . 'module/fastsearch/cronrebuild?secure_key=' . Configuration::get('FASTSEARCH_SECURE_KEY') . '" > /dev/null 2>&1</code></pre>
                </div>
                
                <div class="alert alert-warning">
                    <h4>' . $this->l('Wskazówki dotyczące wydajności') . '</h4>
                    <ul>
                        <li>' . $this->l('Regularne uruchamianie OPTIMIZE TABLE poprawia wydajność zapytań') . '</li>
                        <li>' . $this->l('Cache TTL 30 minut (1800s) to dobry kompromis między wydajnością a aktualnością danych') . '</li>
                        <li>' . $this->l('Dla sklepów z częstymi zmianami cen rozważ krótszy cache TTL') . '</li>
                        <li>' . $this->l('Monitoruj wykorzystanie pamięci podczas przebudowy indeksu') . '</li>
                    </ul>
                </div>
            </div>
        </div>';

        return $html;
    }

    /**
     * Endpoint do cron jobs - optymalizacja
     */
    public function cronOptimize()
    {
        if (!Tools::getValue('secure_key') || Tools::getValue('secure_key') !== Configuration::get('FASTSEARCH_SECURE_KEY')) {
            http_response_code(401);
            die('Unauthorized');
        }
        
        try {
            $optimizer = new FastSearchOptimizer($this);
            $optimizer->optimizePerformance();
            
            // Czyści stare statystyki (starsze niż 90 dni)
            Db::getInstance()->execute('
                DELETE FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                WHERE date_search < DATE_SUB(NOW(), INTERVAL 90 DAY)
            ');
            
            http_response_code(200);
            die('OK - Optimization completed at ' . date('Y-m-d H:i:s'));
            
        } catch (Exception $e) {
            http_response_code(500);
            die('ERROR - ' . $e->getMessage());
        }
    }

    /**
     * Endpoint do cron jobs - przebudowa
     */
    public function cronRebuild()
    {
        if (!Tools::getValue('secure_key') || Tools::getValue('secure_key') !== Configuration::get('FASTSEARCH_SECURE_KEY')) {
            http_response_code(401);
            die('Unauthorized');
        }
        
        $result = $this->buildSearchIndex(2000, 0); // Większe paczki dla cron
        
        if ($result['success']) {
            http_response_code(200);
            die('OK - Rebuild completed. Processed: ' . $result['processed'] . ' products in ' . $result['execution_time'] . 'ms');
        } else {
            http_response_code(500);
            die('ERROR - ' . $result['error']);
        }
    }

    /**
     * Sprawdza status modułu i wyświetla ostrzeżenia
     */
    public function getWarnings()
    {
        $warnings = array();
        
        // Sprawdź czy indeks istnieje i nie jest pusty
        $index_count = Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'fastsearch_index`');
        if ($index_count == 0) {
            $warnings[] = $this->l('Indeks wyszukiwania jest pusty. Kliknij "Przebuduj indeks" w konfiguracji modułu.');
        }
        
        // Sprawdź czy indeks jest aktualny (nie starszy niż 7 dni)
        $last_update = Configuration::get('FASTSEARCH_INDEX_LAST_UPDATE');
        if ($last_update && strtotime($last_update) < strtotime('-7 days')) {
            $warnings[] = $this->l('Indeks wyszukiwania nie był aktualizowany od ponad 7 dni. Zaleca się przebudowę.');
        }
        
        // Sprawdź konfigurację MySQL
        $ft_min_word_len = Db::getInstance()->getValue("SHOW VARIABLES LIKE 'ft_min_word_len'");
        if ($ft_min_word_len && $ft_min_word_len > 2) {
            $warnings[] = $this->l('Zmienna MySQL ft_min_word_len jest ustawiona na wartość większą niż 2. Może to wpływać na wyszukiwanie krótkich słów.');
        }
        
        return $warnings;
    }
}
?>