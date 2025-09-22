<?php

namespace privuma\helpers;

use privuma\privuma;

class redisCache
{
    private static $redis = null;
    private static $connected = false;
    private static $enabled = true;

    /**
     * Get Redis connection
     */
    private static function getRedis()
    {
        if (!self::$enabled) {
            return null;
        }

        if (self::$redis === null) {
            try {
                self::$redis = new \Redis();

                // Try to connect to Redis
                // Redis is on internal network at 172.20.0.98
                $connected = self::$redis->connect(privuma::getEnv('REDIS_HOST'), privuma::getEnv('REDIS_PORT'), 1); // 1 second timeout

                if (!$connected) {
                    error_log('Redis connection failed');
                    self::$enabled = false;
                    return null;
                }

                self::$connected = true;

            } catch (\Exception $e) {
                error_log('Redis connection error: ' . $e->getMessage());
                self::$enabled = false;
                self::$redis = null;
                return null;
            }
        }

        return self::$redis;
    }

    /**
     * Set a value in cache with TTL
     */
    public static function set($key, $value, $ttl = 3600)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return false;
        }

        try {
            $serialized = serialize($value);
            return $redis->setex($key, $ttl, $serialized);
        } catch (\Exception $e) {
            error_log('Redis set error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a value from cache
     */
    public static function get($key)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return null;
        }

        try {
            $value = $redis->get($key);
            if ($value === false) {
                return null;
            }
            return unserialize($value);
        } catch (\Exception $e) {
            error_log('Redis get error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a key from cache
     */
    public static function delete($key)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return false;
        }

        try {
            return $redis->del($key) > 0;
        } catch (\Exception $e) {
            error_log('Redis delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a key exists
     */
    public static function exists($key)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return false;
        }

        try {
            return $redis->exists($key) > 0;
        } catch (\Exception $e) {
            error_log('Redis exists error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cache
     */
    public static function clear()
    {
        $redis = self::getRedis();
        if (!$redis) {
            return false;
        }

        try {
            return $redis->flushAll();
        } catch (\Exception $e) {
            error_log('Redis clear error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or set pattern - get from cache, or compute and cache
     */
    public static function remember($key, $callback, $ttl = 3600)
    {
        $value = self::get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    /**
     * Increment a counter
     */
    public static function increment($key, $by = 1)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return false;
        }

        try {
            return $redis->incrBy($key, $by);
        } catch (\Exception $e) {
            error_log('Redis increment error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if Redis is available
     */
    public static function isEnabled()
    {
        return self::$enabled && self::getRedis() !== null;
    }
}
