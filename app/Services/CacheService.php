<?php
declare(strict_types=1);

/**
 * File: app/Services/CacheService.php
 *
 * Purpose:
 *   File-based cache with namespaced keys (P12, Technical §22). Keys are hashed
 *   and stored under cache/{namespace}/. Namespaces: settings, cultures, thumbs,
 *   reports. Invalidation is namespace-scoped.
 *
 * Dependencies: none (pure filesystem).
 *
 * Conventions:
 *   - Cache values are JSON-serialised with an expiry header.
 *   - Cache keys are md5 hashed so no raw key ever touches the filesystem.
 *
 * @package App\Services
 */

namespace App\Services;

/**
 * Class: CacheService
 *
 * Purpose: Provide namespaced, TTL-aware file caching.
 */
final class CacheService
{
    private string $root;
    private bool $enabled;

    /**
     * @param string $root    Cache root directory (absolute).
     * @param bool   $enabled Whether caching is on.
     */
    public function __construct(string $root, bool $enabled = true)
    {
        $this->root = rtrim($root, '/');
        $this->enabled = $enabled;
    }

    /**
     * Get a cached value, or produce and store it.
     *
     * @param string   $namespace Cache namespace.
     * @param string   $key       Logical key (hashed internally).
     * @param int      $ttl       Time-to-live in seconds.
     * @param callable $producer  Producer invoked on miss.
     * @return mixed Cached or freshly produced value.
     */
    public function get(string $namespace, string $key, int $ttl, callable $producer): mixed
    {
        if (!$this->enabled || $ttl <= 0) {
            return $producer();
        }
        $file = $this->path($namespace, $key);
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $payload = json_decode($raw, true);
                if (is_array($payload) && ($payload['expires'] ?? 0) > time()) {
                    return $payload['value'];
                }
            }
            @unlink($file);
        }
        $value = $producer();
        $this->put($namespace, $key, $value, $ttl);
        return $value;
    }

    /**
     * Store a value.
     *
     * @param string $namespace Namespace.
     * @param string $key       Logical key.
     * @param mixed  $value     Value.
     * @param int    $ttl       TTL seconds.
     * @return void
     */
    public function put(string $namespace, string $key, mixed $value, int $ttl): void
    {
        if (!$this->enabled) { return; }
        $dir = $this->dir($namespace);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($this->path($namespace, $key), json_encode([
            'expires' => time() + $ttl,
            'value' => $value,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Forget a single key.
     *
     * @param string $namespace Namespace.
     * @param string $key       Logical key.
     * @return void
     */
    public function forget(string $namespace, string $key): void
    {
        $file = $this->path($namespace, $key);
        if (is_file($file)) { @unlink($file); }
    }

    /**
     * Clear an entire namespace.
     *
     * @param string $namespace Namespace.
     * @return void
     */
    public function clearNamespace(string $namespace): void
    {
        $dir = $this->dir($namespace);
        if (!is_dir($dir)) { return; }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) { @unlink($file); }
        }
    }

    /**
     * Clear every namespace.
     *
     * @return void
     */
    public function clearAll(): void
    {
        foreach (['settings', 'cultures', 'thumbs', 'reports'] as $ns) {
            $this->clearNamespace($ns);
        }
    }

    /**
     * Resolve the namespace directory path.
     *
     * @param string $namespace Namespace.
     * @return string
     */
    private function dir(string $namespace): string
    {
        return $this->root . '/' . preg_replace('/[^a-z0-9_]/i', '', $namespace);
    }

    /**
     * Resolve the cache file path for a key.
     *
     * @param string $namespace Namespace.
     * @param string $key       Logical key.
     * @return string
     */
    private function path(string $namespace, string $key): string
    {
        return $this->dir($namespace) . '/' . md5($namespace . ':' . $key) . '.cache';
    }
}
