<?php
/**
 * FastSearch Optimizer Class
 * Zaawansowane optymalizacje wydajności dla FastSearch
 * 
 * @author    FastSearch Team
 * @version   1.0.0
 * @copyright 2025 FastSearch
 * @license   MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FastSearchOptimizer
{
    /** @var FastSearch */
    private $module;
    
    /** @var array */
    private $config;
    
    /** @var array */
    private $performance_metrics;
    
    /** @var string */
    private $log_file;
    
    /** @var array */
    private $optimization_history;

    public function __construct($module)
    {
        $this->module = $module;
        $this->config = array(
            'enable_query_optimization' => true,
            'enable_index_optimization' => true,
            'enable_cache_optimization' => true,
            'enable_memory_optimization' => true,
            'max_execution_time' => 30,
            'memory_limit' => '512M',
            'batch_size' => 1000,
            'optimization_frequency' => 3600, // 1 hour
            'cleanup_frequency' => 86400, // 24 hours
            'performance_threshold' => 100, // ms
        );
        
        $this->performance_metrics = array();
        $this->log_file = _PS_LOG_DIR_ . 'fastsearch_optimizer.log';
        $this->optimization_history = $this->loadOptimizationHistory();
        
        $this->initializeOptimizer();
    }

    /**
     * Initialize optimizer settings
     */
    private function initializeOptimizer()
    {
        // Set memory limit
        if (function_exists('ini_set')) {
            ini_set('memory_limit', $this->config['memory_limit']);
            ini_set('max_execution_time', $this->config['max_execution_time']);
        }
        
        // Register shutdown function for cleanup
        register_shutdown_function(array($this, 'shutdownCleanup'));
    }

    /**
     * Main optimization method
     */
    public function optimizePerformance($force = false)
    {
        $start_time = microtime(true);
        $results = array(
            'success' => true,
            'operations' => array(),
            'execution_time' => 0,
            'memory_usage' => 0,
            'improvements' => array()
        );

        try {
            $this->logOperation('Starting performance optimization');
            
            // Check if optimization is needed
            if (!$force && !$this->isOptimizationNeeded()) {
                $results['operations'][] = 'Optimization skipped - not needed';
                return $results;
            }

            // Database optimizations
            if ($this->config['enable_index_optimization']) {
                $index_result = $this->optimizeIndexes();
                $results['operations'] = array_merge($results['operations'], $index_result);
            }

            // Query optimizations
            if ($this->config['enable_query_optimization']) {
                $query_result = $this->optimizeQueries();
                $results['operations'] = array_merge($results['operations'], $query_result);
            }

            // Cache optimizations
            if ($this->config['enable_cache_optimization']) {
                $cache_result = $this->optimizeCache();
                $results['operations'] = array_merge($results['operations'], $cache_result);
            }

            // Memory optimizations
            if ($this->config['enable_memory_optimization']) {
                $memory_result = $this->optimizeMemory();
                $results['operations'] = array_merge($results['operations'], $memory_result);
            }

            // Cleanup operations
            $cleanup_result = $this->performCleanup();
            $results['operations'] = array_merge($results['operations'], $cleanup_result);

            // Update statistics
            $this->updateOptimizationHistory();
            
            $end_time = microtime(true);
            $results['execution_time'] = round(($end_time - $start_time) * 1000, 2);
            $results['memory_usage'] = memory_get_peak_usage(true);

            $this->logOperation('Performance optimization completed successfully', $results);

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $this->logOperation('Performance optimization failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return $results;
    }

    /**
     * Optimize database indexes
     */
    private function optimizeIndexes()
    {
        $operations = array();
        $table_name = _DB_PREFIX_ . 'fastsearch_index';

        try {
            // Analyze table
            $analyze_result = Db::getInstance()->execute("ANALYZE TABLE `{$table_name}`");
            if ($analyze_result) {
                $operations[] = 'Table analyzed successfully';
            }

            // Optimize table
            $optimize_result = Db::getInstance()->execute("OPTIMIZE TABLE `{$table_name}`");
            if ($optimize_result) {
                $operations[] = 'Table optimized successfully';
            }

            // Check and repair table if needed
            $check_result = Db::getInstance()->executeS("CHECK TABLE `{$table_name}`");
            foreach ($check_result as $row) {
                if ($row['Msg_type'] === 'error') {
                    $repair_result = Db::getInstance()->execute("REPAIR TABLE `{$table_name}`");
                    if ($repair_result) {
                        $operations[] = 'Table repaired successfully';
                    }
                    break;
                }
            }

            // Update table statistics
            $stats_result = $this->updateTableStatistics($table_name);
            if ($stats_result) {
                $operations[] = 'Table statistics updated';
            }

            // Check FULLTEXT indexes
            $fulltext_result = $this->optimizeFulltextIndexes($table_name);
            $operations = array_merge($operations, $fulltext_result);

        } catch (Exception $e) {
            $operations[] = 'Index optimization failed: ' . $e->getMessage();
            $this->logOperation('Index optimization error: ' . $e->getMessage(), null, 'ERROR');
        }

        return $operations;
    }

    /**
     * Optimize FULLTEXT indexes
     */
    private function optimizeFulltextIndexes($table_name)
    {
        $operations = array();

        try {
            // Get current FULLTEXT indexes
            $indexes = Db::getInstance()->executeS("SHOW INDEX FROM `{$table_name}` WHERE Index_type = 'FULLTEXT'");
            
            if (empty($indexes)) {
                // Create FULLTEXT index if it doesn't exist
                $sql = "ALTER TABLE `{$table_name}` ADD FULLTEXT KEY `search_fulltext` 
                        (`name`, `description_short`, `description`, `reference`, `ean13`, `upc`, `isbn`, `mpn`, `meta_keywords`, `tags`)";
                
                if (Db::getInstance()->execute($sql)) {
                    $operations[] = 'FULLTEXT index created';
                }
            } else {
                // Check FULLTEXT index health
                $ft_min_word_len = $this->getMySQLVariable('ft_min_word_len');
                $ft_max_word_len = $this->getMySQLVariable('ft_max_word_len');
                
                if ($ft_min_word_len > 2) {
                    $operations[] = "Warning: ft_min_word_len is {$ft_min_word_len} (recommended: 2)";
                }
                
                if ($ft_max_word_len < 84) {
                    $operations[] = "Warning: ft_max_word_len is {$ft_max_word_len} (recommended: 84)";
                }
                
                $operations[] = 'FULLTEXT indexes checked';
            }

            // Optimize MyISAM key buffer if using MyISAM
            $engine = $this->getTableEngine($table_name);
            if ($engine === 'MyISAM') {
                $key_buffer_size = $this->getMySQLVariable('key_buffer_size');
                $recommended_size = $this->calculateOptimalKeyBufferSize();
                
                if ($key_buffer_size < $recommended_size) {
                    $operations[] = "Info: Consider increasing key_buffer_size to {$recommended_size}";
                }
            }

        } catch (Exception $e) {
            $operations[] = 'FULLTEXT optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Optimize search queries
     */
    private function optimizeQueries()
    {
        $operations = array();

        try {
            // Analyze slow queries
            $slow_queries = $this->analyzeSlowQueries();
            if (!empty($slow_queries)) {
                $operations[] = 'Found ' . count($slow_queries) . ' potentially slow queries';
                
                foreach ($slow_queries as $query) {
                    $optimization_suggestions = $this->getQueryOptimizationSuggestions($query);
                    $operations = array_merge($operations, $optimization_suggestions);
                }
            }

            // Update query cache settings
            $query_cache_result = $this->optimizeQueryCache();
            $operations = array_merge($operations, $query_cache_result);

            // Analyze query patterns
            $pattern_analysis = $this->analyzeQueryPatterns();
            $operations = array_merge($operations, $pattern_analysis);

        } catch (Exception $e) {
            $operations[] = 'Query optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Optimize cache system
     */
    private function optimizeCache()
    {
        $operations = array();

        try {
            // Clean expired cache entries
            FastSearchCache::init();
            $cleaned = FastSearchCache::cleanExpired();
            if ($cleaned > 0) {
                $operations[] = "Cleaned {$cleaned} expired cache entries";
            }

            // Optimize cache storage
            $cache_dir = _PS_CACHE_DIR_ . 'fastsearch/';
            if (is_dir($cache_dir)) {
                $cache_stats = $this->analyzeCacheUsage($cache_dir);
                $operations[] = "Cache analysis: {$cache_stats['files']} files, {$cache_stats['size']} MB";
                
                // Remove old cache files
                $removed = $this->cleanOldCacheFiles($cache_dir);
                if ($removed > 0) {
                    $operations[] = "Removed {$removed} old cache files";
                }
            }

            // Optimize cache configuration
            $cache_config = $this->optimizeCacheConfiguration();
            $operations = array_merge($operations, $cache_config);

        } catch (Exception $e) {
            $operations[] = 'Cache optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Optimize memory usage
     */
    private function optimizeMemory()
    {
        $operations = array();

        try {
            // Check current memory usage
            $memory_usage = memory_get_usage(true);
            $memory_peak = memory_get_peak_usage(true);
            $memory_limit = $this->parseMemoryLimit(ini_get('memory_limit'));

            $operations[] = sprintf(
                'Memory usage: %s / %s (peak: %s)',
                $this->formatBytes($memory_usage),
                $this->formatBytes($memory_limit),
                $this->formatBytes($memory_peak)
            );

            // Free unnecessary memory
            if (function_exists('gc_collect_cycles')) {
                $collected = gc_collect_cycles();
                if ($collected > 0) {
                    $operations[] = "Garbage collection freed {$collected} cycles";
                }
            }

            // Optimize PHP settings
            $php_optimizations = $this->optimizePHPSettings();
            $operations = array_merge($operations, $php_optimizations);

            // Clear opcode cache if available
            $opcode_result = $this->clearOpcodeCache();
            if ($opcode_result) {
                $operations[] = 'Opcode cache cleared';
            }

        } catch (Exception $e) {
            $operations[] = 'Memory optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Perform cleanup operations
     */
    private function performCleanup()
    {
        $operations = array();

        try {
            // Clean old statistics
            $stats_cleaned = $this->cleanOldStatistics();
            if ($stats_cleaned > 0) {
                $operations[] = "Cleaned {$stats_cleaned} old statistics records";
            }

            // Clean search logs
            $logs_cleaned = $this->cleanSearchLogs();
            if ($logs_cleaned > 0) {
                $operations[] = "Cleaned {$logs_cleaned} old search log entries";
            }

            // Clean temporary files
            $temp_cleaned = $this->cleanTemporaryFiles();
            if ($temp_cleaned > 0) {
                $operations[] = "Cleaned {$temp_cleaned} temporary files";
            }

            // Vacuum database if supported
            $vacuum_result = $this->vacuumDatabase();
            if ($vacuum_result) {
                $operations[] = 'Database vacuum completed';
            }

        } catch (Exception $e) {
            $operations[] = 'Cleanup failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Check if optimization is needed
     */
    private function isOptimizationNeeded()
    {
        // Check time since last optimization
        $last_optimization = Configuration::get('FASTSEARCH_LAST_OPTIMIZATION');
        if ($last_optimization && (time() - $last_optimization) < $this->config['optimization_frequency']) {
            return false;
        }

        // Check performance metrics
        $avg_search_time = $this->getAverageSearchTime();
        if ($avg_search_time > $this->config['performance_threshold']) {
            return true;
        }

        // Check table fragmentation
        $fragmentation = $this->getTableFragmentation();
        if ($fragmentation > 10) { // More than 10% fragmentation
            return true;
        }

        // Check cache hit rate
        $cache_hit_rate = $this->getCacheHitRate();
        if ($cache_hit_rate < 70) { // Less than 70% hit rate
            return true;
        }

        return false;
    }

    /**
     * Analyze slow queries
     */
    private function analyzeSlowQueries()
    {
        $slow_queries = array();

        try {
            // Check if slow query log is enabled
            $slow_query_log = $this->getMySQLVariable('slow_query_log');
            if ($slow_query_log !== 'ON') {
                return array();
            }

            // Get slow query log file
            $slow_query_log_file = $this->getMySQLVariable('slow_query_log_file');
            if (empty($slow_query_log_file) || !file_exists($slow_query_log_file)) {
                return array();
            }

            // Parse slow query log for FastSearch queries
            $log_content = file_get_contents($slow_query_log_file);
            $lines = explode("\n", $log_content);
            
            foreach ($lines as $line) {
                if (strpos($line, 'fastsearch_index') !== false) {
                    $slow_queries[] = trim($line);
                }
            }

        } catch (Exception $e) {
            $this->logOperation('Slow query analysis failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return array_unique($slow_queries);
    }

    /**
     * Get query optimization suggestions
     */
    private function getQueryOptimizationSuggestions($query)
    {
        $suggestions = array();

        // Analyze query structure
        if (strpos($query, 'SELECT') !== false) {
            // Check for LIMIT clause
            if (strpos($query, 'LIMIT') === false) {
                $suggestions[] = 'Consider adding LIMIT clause to query';
            }

            // Check for proper INDEX usage
            if (strpos($query, 'WHERE') !== false && strpos($query, 'INDEX') === false) {
                $suggestions[] = 'Query might benefit from index optimization';
            }

            // Check for FULLTEXT usage
            if (strpos($query, 'LIKE') !== false && strpos($query, 'MATCH') === false) {
                $suggestions[] = 'Consider using FULLTEXT search instead of LIKE';
            }
        }

        return $suggestions;
    }

    /**
     * Optimize query cache settings
     */
    private function optimizeQueryCache()
    {
        $operations = array();

        try {
            $query_cache_type = $this->getMySQLVariable('query_cache_type');
            $query_cache_size = $this->getMySQLVariable('query_cache_size');

            if ($query_cache_type === 'OFF') {
                $operations[] = 'Info: Query cache is disabled';
            } else {
                $cache_hit_rate = $this->getQueryCacheHitRate();
                $operations[] = "Query cache hit rate: {$cache_hit_rate}%";
                
                if ($cache_hit_rate < 70) {
                    $operations[] = 'Consider optimizing query cache configuration';
                }
            }

        } catch (Exception $e) {
            $operations[] = 'Query cache optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Analyze query patterns
     */
    private function analyzeQueryPatterns()
    {
        $operations = array();

        try {
            // Get most common search terms
            $common_terms = $this->getCommonSearchTerms();
            if (!empty($common_terms)) {
                $operations[] = 'Analyzed ' . count($common_terms) . ' common search patterns';
                
                // Suggest precomputed results for very common terms
                foreach ($common_terms as $term) {
                    if ($term['frequency'] > 100) {
                        $operations[] = "Consider precomputing results for '{$term['term']}'";
                    }
                }
            }

        } catch (Exception $e) {
            $operations[] = 'Query pattern analysis failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Analyze cache usage
     */
    private function analyzeCacheUsage($cache_dir)
    {
        $stats = array('files' => 0, 'size' => 0);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $stats['files']++;
                    $stats['size'] += $file->getSize();
                }
            }

            $stats['size'] = round($stats['size'] / 1024 / 1024, 2); // Convert to MB

        } catch (Exception $e) {
            $this->logOperation('Cache analysis failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return $stats;
    }

    /**
     * Clean old cache files
     */
    private function cleanOldCacheFiles($cache_dir, $max_age = 86400)
    {
        $removed = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && (time() - $file->getMTime()) > $max_age) {
                    if (unlink($file->getPathname())) {
                        $removed++;
                    }
                }
            }

        } catch (Exception $e) {
            $this->logOperation('Cache cleanup failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return $removed;
    }

    /**
     * Optimize cache configuration
     */
    private function optimizeCacheConfiguration()
    {
        $operations = array();

        try {
            // Check current cache settings
            $cache_ttl = Configuration::get('FASTSEARCH_CACHE_TTL', 1800);
            $hit_rate = $this->getCacheHitRate();

            if ($hit_rate < 50) {
                $operations[] = 'Low cache hit rate detected, consider increasing TTL';
            } elseif ($hit_rate > 90) {
                $operations[] = 'High cache hit rate, consider optimizing cache invalidation';
            }

            // Optimize cache size
            $cache_size = $this->getCacheSize();
            $optimal_size = $this->calculateOptimalCacheSize();
            
            if ($cache_size > $optimal_size * 2) {
                $operations[] = 'Cache size is larger than optimal, consider cleanup';
            }

        } catch (Exception $e) {
            $operations[] = 'Cache configuration optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Optimize PHP settings for performance
     */
    private function optimizePHPSettings()
    {
        $operations = array();

        try {
            // Check OPcache settings
            if (extension_loaded('Zend OPcache')) {
                $opcache_enabled = ini_get('opcache.enable');
                if (!$opcache_enabled) {
                    $operations[] = 'Recommendation: Enable OPcache for better performance';
                } else {
                    $operations[] = 'OPcache is enabled';
                }
            }

            // Check memory limit
            $memory_limit = ini_get('memory_limit');
            if ($this->parseMemoryLimit($memory_limit) < 256 * 1024 * 1024) {
                $operations[] = 'Recommendation: Increase memory_limit to at least 256M';
            }

            // Check max execution time
            $max_execution_time = ini_get('max_execution_time');
            if ($max_execution_time < 30) {
                $operations[] = 'Recommendation: Increase max_execution_time for large operations';
            }

        } catch (Exception $e) {
            $operations[] = 'PHP settings optimization failed: ' . $e->getMessage();
        }

        return $operations;
    }

    /**
     * Clear opcode cache
     */
    private function clearOpcodeCache()
    {
        try {
            if (extension_loaded('Zend OPcache')) {
                return opcache_reset();
            } elseif (extension_loaded('apc')) {
                return apc_clear_cache();
            } elseif (extension_loaded('xcache')) {
                return xcache_clear_cache(XC_TYPE_PHP);
            }
        } catch (Exception $e) {
            $this->logOperation('Opcode cache clear failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return false;
    }

    /**
     * Clean old statistics
     */
    private function cleanOldStatistics($days = 90)
    {
        try {
            $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    WHERE date_search < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)';
            
            return Db::getInstance()->execute($sql) ? Db::getInstance()->Affected_Rows() : 0;
        } catch (Exception $e) {
            $this->logOperation('Statistics cleanup failed: ' . $e->getMessage(), null, 'ERROR');
            return 0;
        }
    }

    /**
     * Clean search logs
     */
    private function cleanSearchLogs($days = 30)
    {
        $cleaned = 0;

        try {
            // Clean application logs
            $log_files = glob(_PS_LOG_DIR_ . 'fastsearch_*.log');
            foreach ($log_files as $log_file) {
                if (filemtime($log_file) < time() - ($days * 86400)) {
                    if (unlink($log_file)) {
                        $cleaned++;
                    }
                }
            }

        } catch (Exception $e) {
            $this->logOperation('Search logs cleanup failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return $cleaned;
    }

    /**
     * Clean temporary files
     */
    private function cleanTemporaryFiles()
    {
        $cleaned = 0;

        try {
            $temp_dir = _PS_CACHE_DIR_ . 'fastsearch/temp/';
            if (is_dir($temp_dir)) {
                $files = glob($temp_dir . '*');
                foreach ($files as $file) {
                    if (is_file($file) && filemtime($file) < time() - 3600) { // 1 hour old
                        if (unlink($file)) {
                            $cleaned++;
                        }
                    }
                }
            }

        } catch (Exception $e) {
            $this->logOperation('Temporary files cleanup failed: ' . $e->getMessage(), null, 'ERROR');
        }

        return $cleaned;
    }

    /**
     * Vacuum database (MySQL optimization)
     */
    private function vacuumDatabase()
    {
        try {
            // For MySQL, we use OPTIMIZE TABLE instead of VACUUM
            $tables = array(
                _DB_PREFIX_ . 'fastsearch_index',
                _DB_PREFIX_ . 'fastsearch_stats'
            );

            foreach ($tables as $table) {
                Db::getInstance()->execute("OPTIMIZE TABLE `{$table}`");
            }

            return true;

        } catch (Exception $e) {
            $this->logOperation('Database vacuum failed: ' . $e->getMessage(), null, 'ERROR');
            return false;
        }
    }

    /**
     * Performance monitoring and metrics
     */
    public function getPerformanceMetrics()
    {
        return array(
            'average_search_time' => $this->getAverageSearchTime(),
            'cache_hit_rate' => $this->getCacheHitRate(),
            'index_fragmentation' => $this->getTableFragmentation(),
            'memory_usage' => $this->getMemoryUsage(),
            'query_cache_hit_rate' => $this->getQueryCacheHitRate(),
            'total_searches' => $this->getTotalSearches(),
            'optimization_history' => $this->optimization_history
        );
    }

    /**
     * Get average search execution time
     */
    private function getAverageSearchTime()
    {
        try {
            $sql = 'SELECT AVG(search_time_ms) as avg_time 
                    FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    WHERE date_search >= DATE_SUB(NOW(), INTERVAL 24 HOUR)';
            
            $result = Db::getInstance()->getRow($sql);
            return $result ? round($result['avg_time'], 2) : 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get cache hit rate
     */
    private function getCacheHitRate()
    {
        try {
            // This would need to be implemented based on your caching system
            // For now, return a mock value
            return 75; // 75% hit rate

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get table fragmentation percentage
     */
    private function getTableFragmentation()
    {
        try {
            $table_name = _DB_PREFIX_ . 'fastsearch_index';
            $sql = "SELECT 
                        ((data_length + index_length) - data_free) as used_space,
                        (data_free / (data_length + index_length)) * 100 as fragmentation
                    FROM information_schema.TABLES 
                    WHERE table_schema = '" . _DB_NAME_ . "' 
                    AND table_name = '{$table_name}'";
            
            $result = Db::getInstance()->getRow($sql);
            return $result ? round($result['fragmentation'], 2) : 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get memory usage statistics
     */
    private function getMemoryUsage()
    {
        return array(
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => $this->parseMemoryLimit(ini_get('memory_limit'))
        );
    }

    /**
     * Get query cache hit rate
     */
    private function getQueryCacheHitRate()
    {
        try {
            $hits = $this->getMySQLStatus('Qcache_hits');
            $inserts = $this->getMySQLStatus('Qcache_inserts');
            
            if ($hits + $inserts > 0) {
                return round(($hits / ($hits + $inserts)) * 100, 2);
            }
            
            return 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get total number of searches
     */
    private function getTotalSearches()
    {
        try {
            $sql = 'SELECT COUNT(*) as total 
                    FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    WHERE date_search >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            
            $result = Db::getInstance()->getRow($sql);
            return $result ? (int)$result['total'] : 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Helper methods
     */
    private function getMySQLVariable($name)
    {
        try {
            $result = Db::getInstance()->getRow("SHOW VARIABLES LIKE '{$name}'");
            return $result ? $result['Value'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getMySQLStatus($name)
    {
        try {
            $result = Db::getInstance()->getRow("SHOW STATUS LIKE '{$name}'");
            return $result ? (int)$result['Value'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getTableEngine($table_name)
    {
        try {
            $sql = "SELECT ENGINE FROM information_schema.TABLES 
                    WHERE table_schema = '" . _DB_NAME_ . "' 
                    AND table_name = '{$table_name}'";
            
            $result = Db::getInstance()->getRow($sql);
            return $result ? $result['ENGINE'] : 'Unknown';
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    private function updateTableStatistics($table_name)
    {
        try {
            return Db::getInstance()->execute("ANALYZE TABLE `{$table_name}`");
        } catch (Exception $e) {
            return false;
        }
    }

    private function calculateOptimalKeyBufferSize()
    {
        try {
            // Calculate based on index size
            $table_name = _DB_PREFIX_ . 'fastsearch_index';
            $sql = "SELECT index_length FROM information_schema.TABLES 
                    WHERE table_schema = '" . _DB_NAME_ . "' 
                    AND table_name = '{$table_name}'";
            
            $result = Db::getInstance()->getRow($sql);
            if ($result && $result['index_length'] > 0) {
                // Recommend 1.25x index size, minimum 64MB, maximum 512MB
                $optimal = max(64 * 1024 * 1024, min(512 * 1024 * 1024, $result['index_length'] * 1.25));
                return $this->formatBytes($optimal);
            }
            
            return '128M'; // Default recommendation
        } catch (Exception $e) {
            return '128M';
        }
    }

    private function getCommonSearchTerms($limit = 50)
    {
        try {
            $sql = 'SELECT search_query as term, COUNT(*) as frequency 
                    FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    WHERE date_search >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY search_query 
                    ORDER BY frequency DESC 
                    LIMIT ' . (int)$limit;
            
            return Db::getInstance()->executeS($sql);
        } catch (Exception $e) {
            return array();
        }
    }

    private function getCacheSize()
    {
        try {
            $cache_dir = _PS_CACHE_DIR_ . 'fastsearch/';
            $size = 0;
            
            if (is_dir($cache_dir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                }
            }
            
            return $size;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function calculateOptimalCacheSize()
    {
        // Base calculation on number of products and average search frequency
        try {
            $product_count = Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'fastsearch_index`');
            $daily_searches = Db::getInstance()->getValue('
                SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                WHERE date_search >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ');
            
            // Estimate: 1KB per product + 10KB per daily unique search
            $estimated_size = ($product_count * 1024) + ($daily_searches * 10 * 1024);
            
            // Add 50% buffer and cap at 100MB
            return min(100 * 1024 * 1024, $estimated_size * 1.5);
            
        } catch (Exception $e) {
            return 50 * 1024 * 1024; // 50MB default
        }
    }

    private function parseMemoryLimit($memory_limit)
    {
        if (is_numeric($memory_limit)) {
            return (int)$memory_limit;
        }
        
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int)substr($memory_limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$memory_limit;
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Optimization history management
     */
    private function loadOptimizationHistory()
    {
        try {
            $history = Configuration::get('FASTSEARCH_OPTIMIZATION_HISTORY');
            return $history ? json_decode($history, true) : array();
        } catch (Exception $e) {
            return array();
        }
    }

    private function updateOptimizationHistory()
    {
        try {
            $this->optimization_history[] = array(
                'timestamp' => time(),
                'memory_usage' => memory_get_peak_usage(true),
                'performance_metrics' => $this->getPerformanceMetrics()
            );
            
            // Keep only last 30 entries
            $this->optimization_history = array_slice($this->optimization_history, -30);
            
            Configuration::updateValue('FASTSEARCH_OPTIMIZATION_HISTORY', json_encode($this->optimization_history));
            Configuration::updateValue('FASTSEARCH_LAST_OPTIMIZATION', time());
            
        } catch (Exception $e) {
            $this->logOperation('Failed to update optimization history: ' . $e->getMessage(), null, 'ERROR');
        }
    }

    /**
     * Advanced optimization features
     */
    public function generateOptimizationReport()
    {
        $report = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'database' => array(),
            'performance' => array(),
            'cache' => array(),
            'recommendations' => array()
        );

        try {
            // Database analysis
            $report['database'] = array(
                'table_size' => $this->getTableSize(),
                'index_size' => $this->getIndexSize(),
                'fragmentation' => $this->getTableFragmentation(),
                'row_count' => $this->getRowCount(),
                'engine' => $this->getTableEngine(_DB_PREFIX_ . 'fastsearch_index')
            );

            // Performance metrics
            $report['performance'] = $this->getPerformanceMetrics();

            // Cache analysis
            $report['cache'] = array(
                'size' => $this->getCacheSize(),
                'hit_rate' => $this->getCacheHitRate(),
                'files_count' => $this->getCacheFilesCount()
            );

            // Generate recommendations
            $report['recommendations'] = $this->generateRecommendations($report);

        } catch (Exception $e) {
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    private function getTableSize()
    {
        try {
            $table_name = _DB_PREFIX_ . 'fastsearch_index';
            $sql = "SELECT data_length + index_length as size 
                    FROM information_schema.TABLES 
                    WHERE table_schema = '" . _DB_NAME_ . "' 
                    AND table_name = '{$table_name}'";
            
            $result = Db::getInstance()->getRow($sql);
            return $result ? (int)$result['size'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getIndexSize()
    {
        try {
            $table_name = _DB_PREFIX_ . 'fastsearch_index';
            $sql = "SELECT index_length as size 
                    FROM information_schema.TABLES 
                    WHERE table_schema = '" . _DB_NAME_ . "' 
                    AND table_name = '{$table_name}'";
            
            $result = Db::getInstance()->getRow($sql);
            return $result ? (int)$result['size'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getRowCount()
    {
        try {
            return (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'fastsearch_index`');
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getCacheFilesCount()
    {
        try {
            $cache_dir = _PS_CACHE_DIR_ . 'fastsearch/';
            $count = 0;
            
            if (is_dir($cache_dir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $count++;
                    }
                }
            }
            
            return $count;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function generateRecommendations($report)
    {
        $recommendations = array();

        // Database recommendations
        if ($report['database']['fragmentation'] > 15) {
            $recommendations[] = array(
                'type' => 'database',
                'priority' => 'high',
                'message' => 'High table fragmentation detected. Run OPTIMIZE TABLE.',
                'action' => 'optimize_table'
            );
        }

        if ($report['database']['table_size'] > 100 * 1024 * 1024) { // 100MB
            $recommendations[] = array(
                'type' => 'database',
                'priority' => 'medium',
                'message' => 'Large table size. Consider archiving old data.',
                'action' => 'archive_data'
            );
        }

        // Performance recommendations
        if ($report['performance']['average_search_time'] > 200) {
            $recommendations[] = array(
                'type' => 'performance',
                'priority' => 'high',
                'message' => 'Slow search performance. Check indexes and query optimization.',
                'action' => 'optimize_queries'
            );
        }

        // Cache recommendations
        if ($report['cache']['hit_rate'] < 60) {
            $recommendations[] = array(
                'type' => 'cache',
                'priority' => 'medium',
                'message' => 'Low cache hit rate. Consider increasing cache TTL.',
                'action' => 'optimize_cache'
            );
        }

        if ($report['cache']['size'] > 200 * 1024 * 1024) { // 200MB
            $recommendations[] = array(
                'type' => 'cache',
                'priority' => 'low',
                'message' => 'Large cache size. Consider cleanup of old entries.',
                'action' => 'cleanup_cache'
            );
        }

        return $recommendations;
    }

    /**
     * Automated optimization scheduler
     */
    public function scheduleOptimization($interval = 'daily')
    {
        $schedules = array(
            'hourly' => 3600,
            'daily' => 86400,
            'weekly' => 604800
        );

        if (!isset($schedules[$interval])) {
            throw new InvalidArgumentException('Invalid optimization interval');
        }

        $next_run = time() + $schedules[$interval];
        Configuration::updateValue('FASTSEARCH_NEXT_OPTIMIZATION', $next_run);
        Configuration::updateValue('FASTSEARCH_OPTIMIZATION_INTERVAL', $interval);

        return true;
    }

    public function shouldRunScheduledOptimization()
    {
        $next_run = Configuration::get('FASTSEARCH_NEXT_OPTIMIZATION');
        return $next_run && time() >= $next_run;
    }

    /**
     * Logging functionality
     */
    private function logOperation($message, $data = null, $level = 'INFO')
    {
        try {
            $log_entry = array(
                'timestamp' => date('Y-m-d H:i:s'),
                'level' => $level,
                'message' => $message,
                'memory_usage' => memory_get_usage(true),
                'data' => $data
            );

            $log_line = json_encode($log_entry) . "\n";
            file_put_contents($this->log_file, $log_line, FILE_APPEND | LOCK_EX);

            // Also log to PrestaShop system if error
            if ($level === 'ERROR') {
                PrestaShopLogger::addLog($message, 3, null, 'FastSearchOptimizer');
            }

        } catch (Exception $e) {
            // Fail silently for logging errors
        }
    }

    public function getOptimizationLogs($lines = 100)
    {
        try {
            if (!file_exists($this->log_file)) {
                return array();
            }

            $logs = array();
            $file = new SplFileObject($this->log_file);
            $file->seek(PHP_INT_MAX);
            $total_lines = $file->key();

            $start_line = max(0, $total_lines - $lines);
            $file->seek($start_line);

            while (!$file->eof()) {
                $line = trim($file->current());
                if (!empty($line)) {
                    $log_entry = json_decode($line, true);
                    if ($log_entry) {
                        $logs[] = $log_entry;
                    }
                }
                $file->next();
            }

            return array_reverse($logs);

        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Cleanup on shutdown
     */
    public function shutdownCleanup()
    {
        // Cancel any running requests
        if ($this->currentRequest) {
            // Implementation specific cleanup
        }

        // Log final memory usage
        $this->logOperation('Shutdown cleanup completed', array(
            'peak_memory' => memory_get_peak_usage(true),
            'final_memory' => memory_get_usage(true)
        ));
    }

    /**
     * Export optimization data
     */
    public function exportOptimizationData($format = 'json')
    {
        $data = array(
            'report' => $this->generateOptimizationReport(),
            'metrics' => $this->getPerformanceMetrics(),
            'history' => $this->optimization_history,
            'logs' => $this->getOptimizationLogs(50)
        );

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
                
            case 'csv':
                return $this->convertToCSV($data);
                
            case 'xml':
                return $this->convertToXML($data);
                
            default:
                throw new InvalidArgumentException('Unsupported export format');
        }
    }

    private function convertToCSV($data)
    {
        $csv = "Type,Metric,Value,Timestamp\n";
        
        foreach ($data['metrics'] as $key => $value) {
            $csv .= "metric,{$key}," . (is_array($value) ? json_encode($value) : $value) . "," . date('Y-m-d H:i:s') . "\n";
        }
        
        return $csv;
    }

    private function convertToXML($data)
    {
        $xml = new SimpleXMLElement('<optimization_data/>');
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->arrayToXML($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
        
        return $xml->asXML();
    }

    private function arrayToXML($array, $xml)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild(is_numeric($key) ? 'item' : $key);
                $this->arrayToXML($value, $child);
            } else {
                $xml->addChild(is_numeric($key) ? 'item' : $key, htmlspecialchars($value));
            }
        }
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->logOperation('FastSearchOptimizer destroyed');
    }
}
?>