<?php
/**
 * FastSearch Cache System
 * Zaawansowany wielopoziomowy system cache dla FastSearch
 * 
 * @author    FastSearch Team
 * @version   1.0.0
 * @copyright 2025 FastSearch
 * @license   MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FastSearchCache
{
    /** @var array */
    private static $memory_cache = array();
    
    /** @var string */
    private static $cache_dir = null;
    
    /** @var array */
    private static $config = array();
    
    /** @var array */
    private static $statistics = array(
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
        'memory_usage' => 0
    );
    
    /** @var array */
    private static $adapters = array();
    
    /** @var string */
    private static $default_adapter = 'file';
    
    /** @var bool */
    private static $initialized = false;
    
    /** @var array */
    private static $invalidation_tags = array();

    // Cache adapters
    const ADAPTER_MEMORY = 'memory';
    const ADAPTER_FILE = 'file';
    const ADAPTER_REDIS = 'redis';
    const ADAPTER_MEMCACHED = 'memcached';
    const ADAPTER_APCU = 'apcu';

    // Cache levels
    const LEVEL_L1_MEMORY = 1;
    const LEVEL_L2_PERSISTENT = 2;
    const LEVEL_L3_DISTRIBUTED = 3;

    /**
     * Initialize cache system
     */
    public static function init($config = array())
    {
        if (self::$initialized) {
            return true;
        }

        // Default configuration
        self::$config = array_merge(array(
            'enable_cache' => true,
            'default_ttl' => 1800, // 30 minutes
            'max_memory_items' => 1000,
            'max_memory_size' => 50 * 1024 * 1024, // 50MB
            'cache_dir' => _PS_CACHE_DIR_ . 'fastsearch/',
            'file_extension' => '.cache',
            'serialize_method' => 'json', // json, serialize, igbinary
            'compression' => false,
            'levels' => array(self::LEVEL_L1_MEMORY, self::LEVEL_L2_PERSISTENT),
            'adapters' => array(
                self::ADAPTER_MEMORY => true,
                self::ADAPTER_FILE => true,
                self::ADAPTER_REDIS => false,
                self::ADAPTER_MEMCACHED => false,
                self::ADAPTER_APCU => false
            ),
            'redis' => array(
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'prefix' => 'fastsearch:'
            ),
            'memcached' => array(
                'host' => '127.0.0.1',
                'port' => 11211,
                'prefix' => 'fastsearch:'
            )
        ), $config);

        self::$cache_dir = self::$config['cache_dir'];

        // Create cache directory
        if (!is_dir(self::$cache_dir)) {
            if (!mkdir(self::$cache_dir, 0755, true)) {
                throw new Exception('Cannot create cache directory: ' . self::$cache_dir);
            }
        }

        // Initialize adapters
        self::initializeAdapters();
        
        // Setup cleanup handlers
        self::registerCleanupHandlers();
        
        self::$initialized = true;
        return true;
    }

    /**
     * Initialize available cache adapters
     */
    private static function initializeAdapters()
    {
        // Memory adapter (always available)
        self::$adapters[self::ADAPTER_MEMORY] = new FastSearchMemoryAdapter();

        // File adapter
        if (self::$config['adapters'][self::ADAPTER_FILE]) {
            self::$adapters[self::ADAPTER_FILE] = new FastSearchFileAdapter(self::$config);
        }

        // Redis adapter
        if (self::$config['adapters'][self::ADAPTER_REDIS] && extension_loaded('redis')) {
            try {
                self::$adapters[self::ADAPTER_REDIS] = new FastSearchRedisAdapter(self::$config['redis']);
            } catch (Exception $e) {
                self::logError('Redis adapter initialization failed: ' . $e->getMessage());
            }
        }

        // Memcached adapter
        if (self::$config['adapters'][self::ADAPTER_MEMCACHED] && extension_loaded('memcached')) {
            try {
                self::$adapters[self::ADAPTER_MEMCACHED] = new FastSearchMemcachedAdapter(self::$config['memcached']);
            } catch (Exception $e) {
                self::logError('Memcached adapter initialization failed: ' . $e->getMessage());
            }
        }

        // APCu adapter
        if (self::$config['adapters'][self::ADAPTER_APCU] && extension_loaded('apcu')) {
            self::$adapters[self::ADAPTER_APCU] = new FastSearchAPCuAdapter();
        }
    }

    /**
     * Get value from cache with multi-level fallback
     */
    public static function get($key, $default = null)
    {
        if (!self::$config['enable_cache']) {
            return $default;
        }

        self::ensureInitialized();
        
        $normalized_key = self::normalizeKey($key);
        
        // Try each level in order
        foreach (self::$config['levels'] as $level) {
            $result = self::getFromLevel($normalized_key, $level);
            
            if ($result !== null) {
                self::$statistics['hits']++;
                
                // Promote to higher levels if found in lower level
                self::promoteToHigherLevels($normalized_key, $result, $level);
                
                return $result['data'];
            }
        }

        self::$statistics['misses']++;
        return $default;
    }

    /**
     * Set value in cache across multiple levels
     */
    public static function set($key, $data, $ttl = null, $tags = array())
    {
        if (!self::$config['enable_cache']) {
            return false;
        }

        self::ensureInitialized();
        
        $normalized_key = self::normalizeKey($key);
        $ttl = $ttl ?: self::$config['default_ttl'];
        
        $cache_item = array(
            'data' => $data,
            'created' => time(),
            'ttl' => $ttl,
            'expires' => time() + $ttl,
            'tags' => $tags,
            'key' => $key,
            'size' => self::calculateSize($data)
        );

        $success = false;
        
        // Store in all configured levels
        foreach (self::$config['levels'] as $level) {
            if (self::setToLevel($normalized_key, $cache_item, $level)) {
                $success = true;
            }
        }
        
        // Store tags for invalidation
        if (!empty($tags)) {
            self::storeTags($normalized_key, $tags);
        }

        if ($success) {
            self::$statistics['writes']++;
        }

        return $success;
    }

    /**
     * Delete from cache
     */
    public static function delete($key)
    {
        self::ensureInitialized();
        
        $normalized_key = self::normalizeKey($key);
        $success = false;

        // Delete from all levels
        foreach (self::$config['levels'] as $level) {
            if (self::deleteFromLevel($normalized_key, $level)) {
                $success = true;
            }
        }

        if ($success) {
            self::$statistics['deletes']++;
        }

        return $success;
    }

    /**
     * Check if key exists in cache
     */
    public static function has($key)
    {
        return self::get($key) !== null;
    }

    /**
     * Get multiple values at once
     */
    public static function getMultiple($keys, $default = null)
    {
        $results = array();
        
        foreach ($keys as $key) {
            $results[$key] = self::get($key, $default);
        }
        
        return $results;
    }

    /**
     * Set multiple values at once
     */
    public static function setMultiple($values, $ttl = null)
    {
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!self::set($key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Delete multiple keys
     */
    public static function deleteMultiple($keys)
    {
        $success = true;
        
        foreach ($keys as $key) {
            if (!self::delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Clear entire cache
     */
    public static function clear($level = null)
    {
        self::ensureInitialized();
        
        $levels = $level ? array($level) : self::$config['levels'];
        $success = true;

        foreach ($levels as $cache_level) {
            if (!self::clearLevel($cache_level)) {
                $success = false;
            }
        }

        // Reset statistics
        self::$statistics = array(
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'deletes' => 0,
            'memory_usage' => 0
        );

        return $success;
    }

    /**
     * Invalidate cache by tags
     */
    public static function invalidateByTags($tags)
    {
        self::ensureInitialized();
        
        if (!is_array($tags)) {
            $tags = array($tags);
        }

        $invalidated = 0;

        foreach ($tags as $tag) {
            if (isset(self::$invalidation_tags[$tag])) {
                foreach (self::$invalidation_tags[$tag] as $key) {
                    if (self::delete($key)) {
                        $invalidated++;
                    }
                }
                unset(self::$invalidation_tags[$tag]);
            }
        }

        return $invalidated;
    }

    /**
     * Clean expired entries
     */
    public static function cleanExpired()
    {
        self::ensureInitialized();
        
        $cleaned = 0;

        foreach (self::$config['levels'] as $level) {
            $cleaned += self::cleanExpiredFromLevel($level);
        }

        return $cleaned;
    }

    /**
     * Get cache statistics
     */
    public static function getStatistics()
    {
        self::ensureInitialized();
        
        $stats = self::$statistics;
        
        // Calculate hit rate
        $total_requests = $stats['hits'] + $stats['misses'];
        $stats['hit_rate'] = $total_requests > 0 ? ($stats['hits'] / $total_requests) * 100 : 0;
        
        // Memory usage
        $stats['memory_usage'] = self::calculateMemoryUsage();
        
        // Adapter statistics
        $stats['adapters'] = array();
        foreach (self::$adapters as $name => $adapter) {
            if (method_exists($adapter, 'getStatistics')) {
                $stats['adapters'][$name] = $adapter->getStatistics();
            }
        }
        
        return $stats;
    }

    /**
     * Advanced cache warming
     */
    public static function warm($patterns = array(), $callback = null)
    {
        self::ensureInitialized();
        
        $warmed = 0;
        
        foreach ($patterns as $pattern) {
            $keys = self::getKeysByPattern($pattern);
            
            foreach ($keys as $key) {
                if ($callback && is_callable($callback)) {
                    $data = call_user_func($callback, $key);
                    if ($data !== null) {
                        self::set($key, $data);
                        $warmed++;
                    }
                }
            }
        }
        
        return $warmed;
    }

    /**
     * Cache locking mechanism
     */
    public static function lock($key, $timeout = 10)
    {
        $lock_key = 'lock:' . $key;
        $lock_value = uniqid(php_uname('n'), true);
        
        // Try to acquire lock
        if (self::get($lock_key) === null) {
            self::set($lock_key, $lock_value, $timeout);
            return $lock_value;
        }
        
        return false;
    }

    public static function unlock($key, $lock_value)
    {
        $lock_key = 'lock:' . $key;
        
        if (self::get($lock_key) === $lock_value) {
            return self::delete($lock_key);
        }
        
        return false;
    }

    /**
     * Atomic operations
     */
    public static function increment($key, $step = 1, $initial = 0, $ttl = null)
    {
        $lock = self::lock($key, 5);
        if (!$lock) {
            return false;
        }

        try {
            $current = self::get($key, $initial);
            $new_value = is_numeric($current) ? $current + $step : $initial + $step;
            
            self::set($key, $new_value, $ttl);
            self::unlock($key, $lock);
            
            return $new_value;
        } catch (Exception $e) {
            self::unlock($key, $lock);
            throw $e;
        }
    }

    public static function decrement($key, $step = 1, $initial = 0, $ttl = null)
    {
        return self::increment($key, -$step, $initial, $ttl);
    }

    /**
     * Level-specific operations
     */
    private static function getFromLevel($key, $level)
    {
        switch ($level) {
            case self::LEVEL_L1_MEMORY:
                return self::getFromMemory($key);
                
            case self::LEVEL_L2_PERSISTENT:
                return self::getFromPersistent($key);
                
            case self::LEVEL_L3_DISTRIBUTED:
                return self::getFromDistributed($key);
                
            default:
                return null;
        }
    }

    private static function setToLevel($key, $item, $level)
    {
        switch ($level) {
            case self::LEVEL_L1_MEMORY:
                return self::setToMemory($key, $item);
                
            case self::LEVEL_L2_PERSISTENT:
                return self::setToPersistent($key, $item);
                
            case self::LEVEL_L3_DISTRIBUTED:
                return self::setToDistributed($key, $item);
                
            default:
                return false;
        }
    }

    private static function deleteFromLevel($key, $level)
    {
        switch ($level) {
            case self::LEVEL_L1_MEMORY:
                return self::deleteFromMemory($key);
                
            case self::LEVEL_L2_PERSISTENT:
                return self::deleteFromPersistent($key);
                
            case self::LEVEL_L3_DISTRIBUTED:
                return self::deleteFromDistributed($key);
                
            default:
                return false;
        }
    }

    private static function clearLevel($level)
    {
        switch ($level) {
            case self::LEVEL_L1_MEMORY:
                return self::clearMemory();
                
            case self::LEVEL_L2_PERSISTENT:
                return self::clearPersistent();
                
            case self::LEVEL_L3_DISTRIBUTED:
                return self::clearDistributed();
                
            default:
                return false;
        }
    }

    /**
     * Memory cache operations
     */
    private static function getFromMemory($key)
    {
        if (!isset(self::$memory_cache[$key])) {
            return null;
        }

        $item = self::$memory_cache[$key];
        
        // Check expiration
        if ($item['expires'] < time()) {
            unset(self::$memory_cache[$key]);
            return null;
        }

        return $item;
    }

    private static function setToMemory($key, $item)
    {
        // Check memory limits
        if (count(self::$memory_cache) >= self::$config['max_memory_items']) {
            self::evictFromMemory();
        }

        if (self::calculateMemoryUsage() + $item['size'] > self::$config['max_memory_size']) {
            self::evictFromMemory();
        }

        self::$memory_cache[$key] = $item;
        return true;
    }

    private static function deleteFromMemory($key)
    {
        if (isset(self::$memory_cache[$key])) {
            unset(self::$memory_cache[$key]);
            return true;
        }
        return false;
    }

    private static function clearMemory()
    {
        self::$memory_cache = array();
        return true;
    }

    /**
     * Persistent cache operations (File/APCu)
     */
    private static function getFromPersistent($key)
    {
        // Try APCu first if available
        if (isset(self::$adapters[self::ADAPTER_APCU])) {
            $result = self::$adapters[self::ADAPTER_APCU]->get($key);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback to file cache
        if (isset(self::$adapters[self::ADAPTER_FILE])) {
            return self::$adapters[self::ADAPTER_FILE]->get($key);
        }

        return null;
    }

    private static function setToPersistent($key, $item)
    {
        $success = false;

        // Store in APCu if available
        if (isset(self::$adapters[self::ADAPTER_APCU])) {
            if (self::$adapters[self::ADAPTER_APCU]->set($key, $item)) {
                $success = true;
            }
        }

        // Store in file cache
        if (isset(self::$adapters[self::ADAPTER_FILE])) {
            if (self::$adapters[self::ADAPTER_FILE]->set($key, $item)) {
                $success = true;
            }
        }

        return $success;
    }

    private static function deleteFromPersistent($key)
    {
        $success = false;

        if (isset(self::$adapters[self::ADAPTER_APCU])) {
            if (self::$adapters[self::ADAPTER_APCU]->delete($key)) {
                $success = true;
            }
        }

        if (isset(self::$adapters[self::ADAPTER_FILE])) {
            if (self::$adapters[self::ADAPTER_FILE]->delete($key)) {
                $success = true;
            }
        }

        return $success;
    }

    private static function clearPersistent()
    {
        $success = false;

        if (isset(self::$adapters[self::ADAPTER_APCU])) {
            if (self::$adapters[self::ADAPTER_APCU]->clear()) {
                $success = true;
            }
        }

        if (isset(self::$adapters[self::ADAPTER_FILE])) {
            if (self::$adapters[self::ADAPTER_FILE]->clear()) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Distributed cache operations (Redis/Memcached)
     */
    private static function getFromDistributed($key)
    {
        // Try Redis first
        if (isset(self::$adapters[self::ADAPTER_REDIS])) {
            $result = self::$adapters[self::ADAPTER_REDIS]->get($key);
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback to Memcached
        if (isset(self::$adapters[self::ADAPTER_MEMCACHED])) {
            return self::$adapters[self::ADAPTER_MEMCACHED]->get($key);
        }

        return null;
    }

    private static function setToDistributed($key, $item)
    {
        $success = false;

        if (isset(self::$adapters[self::ADAPTER_REDIS])) {
            if (self::$adapters[self::ADAPTER_REDIS]->set($key, $item)) {
                $success = true;
            }
        }

        if (isset(self::$adapters[self::ADAPTER_MEMCACHED])) {
            if (self::$adapters[self::ADAPTER_MEMCACHED]->set($key, $item)) {
                $success = true;
            }
        }

        return $success;
    }

    private static function deleteFromDistributed($key)
    {
        $success = false;

        if (isset(self::$adapters[self::ADAPTER_REDIS])) {
            if (self::$adapters[self::ADAPTER_REDIS]->delete($key)) {
                $success = true;
            }
        }

        if (isset(self::$adapters[self::ADAPTER_MEMCACHED])) {
            if (self::$adapters[self::ADAPTER_MEMCACHED]->delete($key)) {
                $success = true;
            }
        }

        return $success;
    }

    private static function clearDistributed()
    {
        $success = false;

        if (isset(self::$adapters[self::ADAPTER_REDIS])) {
            if (self::$adapters[self::ADAPTER_REDIS]->clear()) {
                $success = true;
            }
        }

        if (isset(self::$adapters[self::ADAPTER_MEMCACHED])) {
            if (self::$adapters[self::ADAPTER_MEMCACHED]->clear()) {
                $success = true;
            }
        }

        return $success;
    }

    /**
     * Utility methods
     */
    private static function normalizeKey($key)
    {
        // Ensure key is string and normalize
        $key = (string)$key;
        $key = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key);
        return substr($key, 0, 200); // Limit key length
    }

    private static function calculateSize($data)
    {
        if (function_exists('memory_get_usage')) {
            $before = memory_get_usage();
            $temp = $data;
            $after = memory_get_usage();
            unset($temp);
            return max(0, $after - $before);
        }
        
        // Fallback estimation
        return strlen(serialize($data));
    }

    private static function calculateMemoryUsage()
    {
        $total = 0;
        foreach (self::$memory_cache as $item) {
            $total += $item['size'];
        }
        return $total;
    }

    private static function evictFromMemory()
    {
        // LRU eviction - remove oldest items
        $to_remove = max(1, count(self::$memory_cache) * 0.1); // Remove 10%
        
        uasort(self::$memory_cache, function($a, $b) {
            return $a['created'] - $b['created'];
        });

        $removed = 0;
        foreach (self::$memory_cache as $key => $item) {
            if ($removed >= $to_remove) {
                break;
            }
            unset(self::$memory_cache[$key]);
            $removed++;
        }
    }

    private static function promoteToHigherLevels($key, $item, $current_level)
    {
        foreach (self::$config['levels'] as $level) {
            if ($level < $current_level) {
                self::setToLevel($key, $item, $level);
            }
        }
    }

    private static function storeTags($key, $tags)
    {
        foreach ($tags as $tag) {
            if (!isset(self::$invalidation_tags[$tag])) {
                self::$invalidation_tags[$tag] = array();
            }
            self::$invalidation_tags[$tag][] = $key;
        }
    }

    private static function cleanExpiredFromLevel($level)
    {
        $cleaned = 0;

        switch ($level) {
            case self::LEVEL_L1_MEMORY:
                foreach (self::$memory_cache as $key => $item) {
                    if ($item['expires'] < time()) {
                        unset(self::$memory_cache[$key]);
                        $cleaned++;
                    }
                }
                break;

            case self::LEVEL_L2_PERSISTENT:
                if (isset(self::$adapters[self::ADAPTER_FILE])) {
                    $cleaned += self::$adapters[self::ADAPTER_FILE]->cleanExpired();
                }
                break;

            case self::LEVEL_L3_DISTRIBUTED:
                // Redis and Memcached handle expiration automatically
                break;
        }

        return $cleaned;
    }

    private static function getKeysByPattern($pattern)
    {
        // This would need to be implemented based on available adapters
        // For now, return empty array
        return array();
    }

    private static function registerCleanupHandlers()
    {
        // Register shutdown function
        register_shutdown_function(array(__CLASS__, 'shutdown'));
        
        // Setup periodic cleanup (if running in web context)
        if (isset($_SERVER['REQUEST_METHOD'])) {
            // Random cleanup (1% chance)
            if (rand(1, 100) === 1) {
                self::cleanExpired();
            }
        }
    }

    public static function shutdown()
    {
        // Cleanup on shutdown
        self::evictFromMemory();
    }

    private static function ensureInitialized()
    {
        if (!self::$initialized) {
            self::init();
        }
    }

    private static function logError($message)
    {
        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            PrestaShopLogger::addLog('FastSearchCache: ' . $message, 3, null, 'FastSearchCache');
        }
    }
}

/**
 * Cache Adapter Interfaces and Implementations
 */
interface FastSearchCacheAdapterInterface
{
    public function get($key);
    public function set($key, $item);
    public function delete($key);
    public function clear();
    public function cleanExpired();
}

/**
 * Memory Cache Adapter
 */
class FastSearchMemoryAdapter implements FastSearchCacheAdapterInterface
{
    private $data = array();

    public function get($key)
    {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    public function set($key, $item)
    {
        $this->data[$key] = $item;
        return true;
    }

    public function delete($key)
    {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
            return true;
        }
        return false;
    }

    public function clear()
    {
        $this->data = array();
        return true;
    }

    public function cleanExpired()
    {
        $cleaned = 0;
        $now = time();
        
        foreach ($this->data as $key => $item) {
            if (isset($item['expires']) && $item['expires'] < $now) {
                unset($this->data[$key]);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
}

/**
 * File Cache Adapter
 */
class FastSearchFileAdapter implements FastSearchCacheAdapterInterface
{
    private $cache_dir;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
        $this->cache_dir = $config['cache_dir'];
    }

    public function get($key)
    {
        $file_path = $this->getFilePath($key);
        
        if (!file_exists($file_path)) {
            return null;
        }

        $data = file_get_contents($file_path);
        if ($data === false) {
            return null;
        }

        $item = $this->unserialize($data);
        
        // Check expiration
        if ($item && isset($item['expires']) && $item['expires'] < time()) {
            unlink($file_path);
            return null;
        }

        return $item;
    }

    public function set($key, $item)
    {
        $file_path = $this->getFilePath($key);
        $dir = dirname($file_path);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = $this->serialize($item);
        
        if ($this->config['compression'] && function_exists('gzcompress')) {
            $data = gzcompress($data);
        }

        return file_put_contents($file_path, $data, LOCK_EX) !== false;
    }

    public function delete($key)
    {
        $file_path = $this->getFilePath($key);
        
        if (file_exists($file_path)) {
            return unlink($file_path);
        }
        
        return false;
    }

    public function clear()
    {
        return $this->clearDirectory($this->cache_dir);
    }

    public function cleanExpired()
    {
        return $this->cleanExpiredFiles($this->cache_dir);
    }

    private function getFilePath($key)
    {
        $hash = md5($key);
        $subdir = substr($hash, 0, 2);
        return $this->cache_dir . $subdir . '/' . $hash . $this->config['file_extension'];
    }

    private function serialize($data)
    {
        switch ($this->config['serialize_method']) {
            case 'json':
                return json_encode($data);
            case 'igbinary':
                return function_exists('igbinary_serialize') ? igbinary_serialize($data) : serialize($data);
            default:
                return serialize($data);
        }
    }

    private function unserialize($data)
    {
        if ($this->config['compression'] && function_exists('gzuncompress')) {
            $data = gzuncompress($data);
        }

        switch ($this->config['serialize_method']) {
            case 'json':
                return json_decode($data, true);
            case 'igbinary':
                return function_exists('igbinary_unserialize') ? igbinary_unserialize($data) : unserialize($data);
            default:
                return unserialize($data);
        }
    }

    private function clearDirectory($dir)
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        return true;
    }

    private function cleanExpiredFiles($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $cleaned = 0;
        $now = time();
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === ltrim($this->config['file_extension'], '.')) {
                try {
                    $data = file_get_contents($file->getPathname());
                    if ($data !== false) {
                        $item = $this->unserialize($data);
                        if ($item && isset($item['expires']) && $item['expires'] < $now) {
                            unlink($file->getPathname());
                            $cleaned++;
                        }
                    }
                } catch (Exception $e) {
                    // Skip corrupted files
                    continue;
                }
            }
        }

        return $cleaned;
    }
}

/**
 * Redis Cache Adapter
 */
class FastSearchRedisAdapter implements FastSearchCacheAdapterInterface
{
    private $redis;
    private $prefix;

    public function __construct($config)
    {
        $this->redis = new Redis();
        
        if (!$this->redis->connect($config['host'], $config['port'])) {
            throw new Exception('Cannot connect to Redis server');
        }
        
        if (isset($config['database'])) {
            $this->redis->select($config['database']);
        }
        
        $this->prefix = $config['prefix'];
    }

    public function get($key)
    {
        $data = $this->redis->get($this->prefix . $key);
        
        if ($data === false) {
            return null;
        }

        return json_decode($data, true);
    }

    public function set($key, $item)
    {
        $data = json_encode($item);
        $ttl = isset($item['ttl']) ? $item['ttl'] : 3600;
        
        return $this->redis->setex($this->prefix . $key, $ttl, $data);
    }

    public function delete($key)
    {
        return $this->redis->del($this->prefix . $key) > 0;
    }

    public function clear()
    {
        $keys = $this->redis->keys($this->prefix . '*');
        
        if (!empty($keys)) {
            return $this->redis->del($keys) > 0;
        }
        
        return true;
    }

    public function cleanExpired()
    {
        // Redis handles expiration automatically
        return 0;
    }

    public function getStatistics()
    {
        $info = $this->redis->info();
        
        return array(
            'connected_clients' => $info['connected_clients'] ?? 0,
            'used_memory' => $info['used_memory'] ?? 0,
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0
        );
    }
}

/**
 * Memcached Cache Adapter
 */
class FastSearchMemcachedAdapter implements FastSearchCacheAdapterInterface
{
    private $memcached;
    private $prefix;

    public function __construct($config)
    {
        $this->memcached = new Memcached();
        
        if (!$this->memcached->addServer($config['host'], $config['port'])) {
            throw new Exception('Cannot connect to Memcached server');
        }
        
        $this->prefix = $config['prefix'];
    }

    public function get($key)
    {
        $data = $this->memcached->get($this->prefix . $key);
        
        if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
            return null;
        }
        
        return $data;
    }

    public function set($key, $item)
    {
        $ttl = isset($item['ttl']) ? $item['ttl'] : 3600;
        
        return $this->memcached->set($this->prefix . $key, $item, time() + $ttl);
    }

    public function delete($key)
    {
        return $this->memcached->delete($this->prefix . $key);
    }

    public function clear()
    {
        return $this->memcached->flush();
    }

    public function cleanExpired()
    {
        // Memcached handles expiration automatically
        return 0;
    }

    public function getStatistics()
    {
        $stats = $this->memcached->getStats();
        $server_stats = reset($stats);
        
        return array(
            'curr_connections' => $server_stats['curr_connections'] ?? 0,
            'bytes' => $server_stats['bytes'] ?? 0,
            'get_hits' => $server_stats['get_hits'] ?? 0,
            'get_misses' => $server_stats['get_misses'] ?? 0
        );
    }
}

/**
 * APCu Cache Adapter
 */
class FastSearchAPCuAdapter implements FastSearchCacheAdapterInterface
{
    public function get($key)
    {
        $success = false;
        $data = apcu_fetch($key, $success);
        
        return $success ? $data : null;
    }

    public function set($key, $item)
    {
        $ttl = isset($item['ttl']) ? $item['ttl'] : 3600;
        
        return apcu_store($key, $item, $ttl);
    }

    public function delete($key)
    {
        return apcu_delete($key);
    }

    public function clear()
    {
        return apcu_clear_cache();
    }

    public function cleanExpired()
    {
        // APCu handles expiration automatically
        return 0;
    }

    public function getStatistics()
    {
        $info = apcu_cache_info();
        
        return array(
            'num_slots' => $info['num_slots'] ?? 0,
            'num_hits' => $info['num_hits'] ?? 0,
            'num_misses' => $info['num_misses'] ?? 0,
            'mem_size' => $info['mem_size'] ?? 0
        );
    }
}

/**
 * Cache Warmer Class
 */
class FastSearchCacheWarmer
{
    private $cache;
    private $module;

    public function __construct($module)
    {
        $this->module = $module;
        $this->cache = FastSearchCache::class;
    }

    /**
     * Warm cache with popular search terms
     */
    public function warmPopularSearches($limit = 100)
    {
        try {
            $sql = 'SELECT search_query, COUNT(*) as frequency 
                    FROM `' . _DB_PREFIX_ . 'fastsearch_stats` 
                    WHERE date_search >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY search_query 
                    ORDER BY frequency DESC 
                    LIMIT ' . (int)$limit;
            
            $popular_searches = Db::getInstance()->executeS($sql);
            $warmed = 0;

            foreach ($popular_searches as $search) {
                $results = $this->module->searchProducts($search['search_query'], 15, 0);
                
                if (!empty($results['products'])) {
                    $cache_key = 'search_' . md5($search['search_query'] . '_15_0_' . Context::getContext()->language->id);
                    $this->cache::set($cache_key, $results, 3600, array('search', 'popular'));
                    $warmed++;
                }
            }

            return $warmed;

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Cache warming failed: ' . $e->getMessage(), 3, null, 'FastSearchCache');
            return 0;
        }
    }

    /**
     * Warm cache with product data
     */
    public function warmProductData($batch_size = 100)
    {
        try {
            $sql = 'SELECT id_product FROM `' . _DB_PREFIX_ . 'fastsearch_index` 
                    WHERE active = 1 ORDER BY date_upd DESC LIMIT ' . (int)$batch_size;
            
            $products = Db::getInstance()->executeS($sql);
            $warmed = 0;

            foreach ($products as $product) {
                $product_data = new Product($product['id_product'], true, Context::getContext()->language->id);
                
                if ($product_data->id) {
                    $cache_key = 'product_' . $product['id_product'] . '_' . Context::getContext()->language->id;
                    $this->cache::set($cache_key, $product_data, 7200, array('product'));
                    $warmed++;
                }
            }

            return $warmed;

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Product cache warming failed: ' . $e->getMessage(), 3, null, 'FastSearchCache');
            return 0;
        }
    }

    /**
     * Warm cache with category data
     */
    public function warmCategoryData()
    {
        try {
            $categories = Category::getCategories(Context::getContext()->language->id, true, false);
            $warmed = 0;

            foreach ($categories as $category) {
                $cache_key = 'category_' . $category['id_category'] . '_' . Context::getContext()->language->id;
                $this->cache::set($cache_key, $category, 14400, array('category')); // 4 hours
                $warmed++;
            }

            return $warmed;

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Category cache warming failed: ' . $e->getMessage(), 3, null, 'FastSearchCache');
            return 0;
        }
    }
}

/**
 * Cache Monitor Class
 */
class FastSearchCacheMonitor
{
    /**
     * Get comprehensive cache health report
     */
    public static function getHealthReport()
    {
        $report = array(
            'timestamp' => time(),
            'status' => 'healthy',
            'statistics' => FastSearchCache::getStatistics(),
            'memory_usage' => array(),
            'disk_usage' => array(),
            'performance' => array(),
            'recommendations' => array()
        );

        try {
            // Memory usage analysis
            $report['memory_usage'] = array(
                'php_memory_usage' => memory_get_usage(true),
                'php_memory_peak' => memory_get_peak_usage(true),
                'php_memory_limit' => self::parseMemoryLimit(ini_get('memory_limit'))
            );

            // Disk usage analysis
            $cache_dir = _PS_CACHE_DIR_ . 'fastsearch/';
            if (is_dir($cache_dir)) {
                $report['disk_usage'] = self::analyzeDiskUsage($cache_dir);
            }

            // Performance analysis
            $report['performance'] = array(
                'hit_rate' => $report['statistics']['hit_rate'],
                'avg_response_time' => self::getAverageResponseTime(),
                'cache_efficiency' => self::calculateCacheEfficiency($report['statistics'])
            );

            // Generate recommendations
            $report['recommendations'] = self::generateRecommendations($report);

            // Determine overall status
            $report['status'] = self::determineHealthStatus($report);

        } catch (Exception $e) {
            $report['status'] = 'error';
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    private static function analyzeDiskUsage($dir)
    {
        $total_size = 0;
        $file_count = 0;

        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $total_size += $file->getSize();
                    $file_count++;
                }
            }
        }

        return array(
            'total_size' => $total_size,
            'file_count' => $file_count,
            'avg_file_size' => $file_count > 0 ? $total_size / $file_count : 0,
            'disk_free' => disk_free_space($dir)
        );
    }

    private static function getAverageResponseTime()
    {
        // This would be implemented with actual performance tracking
        return 0;
    }

    private static function calculateCacheEfficiency($stats)
    {
        $total_requests = $stats['hits'] + $stats['misses'];
        
        if ($total_requests === 0) {
            return 0;
        }

        // Efficiency score based on hit rate and other factors
        $hit_rate_score = $stats['hit_rate'];
        $usage_score = min(100, ($stats['memory_usage'] / (50 * 1024 * 1024)) * 100); // Normalize to 50MB
        
        return ($hit_rate_score * 0.8) + ($usage_score * 0.2);
    }

    private static function generateRecommendations($report)
    {
        $recommendations = array();

        // Hit rate recommendations
        if ($report['statistics']['hit_rate'] < 70) {
            $recommendations[] = array(
                'type' => 'performance',
                'priority' => 'high',
                'message' => 'Low cache hit rate. Consider increasing TTL or warming cache.',
                'action' => 'increase_ttl'
            );
        }

        // Memory usage recommendations
        $memory_usage_percent = ($report['memory_usage']['php_memory_usage'] / $report['memory_usage']['php_memory_limit']) * 100;
        if ($memory_usage_percent > 80) {
            $recommendations[] = array(
                'type' => 'memory',
                'priority' => 'medium',
                'message' => 'High memory usage detected. Consider cleanup or increasing limits.',
                'action' => 'cleanup_memory'
            );
        }

        // Disk usage recommendations
        if (isset($report['disk_usage']['total_size']) && $report['disk_usage']['total_size'] > 200 * 1024 * 1024) {
            $recommendations[] = array(
                'type' => 'disk',
                'priority' => 'low',
                'message' => 'Large cache size on disk. Consider cleanup of old files.',
                'action' => 'cleanup_disk'
            );
        }

        return $recommendations;
    }

    private static function determineHealthStatus($report)
    {
        $issues = 0;

        // Check critical issues
        if ($report['statistics']['hit_rate'] < 50) {
            $issues += 2; // Critical issue
        }

        if (isset($report['memory_usage']['php_memory_usage'], $report['memory_usage']['php_memory_limit'])) {
            $memory_percent = ($report['memory_usage']['php_memory_usage'] / $report['memory_usage']['php_memory_limit']) * 100;
            if ($memory_percent > 90) {
                $issues += 2; // Critical issue
            } elseif ($memory_percent > 80) {
                $issues += 1; // Warning
            }
        }

        // Determine status
        if ($issues >= 2) {
            return 'critical';
        } elseif ($issues >= 1) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    private static function parseMemoryLimit($memory_limit)
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
}

/**
 * Utility functions
 */
function fastsearch_cache_get($key, $default = null)
{
    return FastSearchCache::get($key, $default);
}

function fastsearch_cache_set($key, $value, $ttl = null, $tags = array())
{
    return FastSearchCache::set($key, $value, $ttl, $tags);
}

function fastsearch_cache_delete($key)
{
    return FastSearchCache::delete($key);
}

function fastsearch_cache_clear()
{
    return FastSearchCache::clear();
}
?>