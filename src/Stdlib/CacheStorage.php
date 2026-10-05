<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class CacheStorage
{
    /**
     * A cache is keyed by a page block ("b{id}") or by an item set in a site
     * ("s{site-id}-r{item-set-id}"): the visible items and the links of an item
     * set depend on the site.
     */
    const KEY_PATTERN = '~^(?:b\d+|s\d+-r\d+)$~';

    protected string $basePath;

    protected string $baseUrl;

    public function __construct(string $basePath, string $baseUrl)
    {
        $this->basePath = rtrim($basePath, '/') . '/vikus';
        $this->baseUrl = rtrim($baseUrl, '/') . '/vikus';
    }

    public static function blockKey(int $blockId): string
    {
        return 'b' . $blockId;
    }

    public static function itemSetKey(int $siteId, int $itemSetId): string
    {
        return 's' . $siteId . '-r' . $itemSetId;
    }

    /**
     * Split a key into its ids, or return null when it is not a valid key.
     *
     * @return array|null Either ["block_id" => int] or ["site_id" => int,
     * "item_set_id" => int].
     */
    public static function parseKey(string $key): ?array
    {
        $matches = [];
        if (preg_match('~^b(\d+)$~', $key, $matches)) {
            return ['block_id' => (int) $matches[1]];
        }
        if (preg_match('~^s(\d+)-r(\d+)$~', $key, $matches)) {
            return ['site_id' => (int) $matches[1], 'item_set_id' => (int) $matches[2]];
        }
        return null;
    }

    public function path(string $key): string
    {
        return $this->basePath . '/' . $this->checkKey($key);
    }

    public function url(string $key): string
    {
        return $this->baseUrl . '/' . $this->checkKey($key);
    }

    public function readMeta(string $key): array
    {
        $file = $this->path($key) . '/meta.json';
        return is_file($file)
            ? json_decode((string) file_get_contents($file), true) ?: []
            : [];
    }

    /**
     * Create a unique, empty temporary directory and return its path.
     *
     * The directory is unique per call (not per key) so that two concurrent
     * jobs building the same cache never share, and so never wipe, each other's
     * temporary directory.
     */
    public function prepareTemp(string $key): string
    {
        do {
            $temp = $this->path($key) . '.tmp-' . bin2hex(random_bytes(8));
        } while (is_dir($temp));
        if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $temp));
        }
        return $temp;
    }

    /**
     * Replace the cache of a key by the given temporary directory.
     */
    public function publish(string $key, string $temp): void
    {
        $path = $this->path($key);
        // The name of the previous cache is unique per call, so two concurrent
        // publications of the same key never move it to the same place.
        $old = $path . '.old-' . bin2hex(random_bytes(8));
        $hasPrevious = is_dir($path);
        if ($hasPrevious && !@rename($path, $old)) {
            throw new RuntimeException(sprintf('Unable to move directory "%s" to "%s".', $path, $old));
        }
        if (!@rename($temp, $path)) {
            if ($hasPrevious) {
                rename($old, $path);
            }
            throw new RuntimeException(sprintf('Unable to move directory "%s" to "%s".', $temp, $path));
        }
        $this->remove($old);
    }

    /**
     * Remove a temporary directory created by prepareTemp().
     *
     * The path is checked against the cache base path and the ".tmp-" marker
     * before removal, so a caller cannot be tricked (or mistaken) into removing
     * an unrelated directory. It is checked once resolved too, so a traversal
     * like ".../b1/../../foo.tmp-1" cannot escape the cache directory.
     */
    public function discardTemp(string $temp): void
    {
        $resolved = realpath($temp);
        $base = realpath($this->basePath);
        if (!$this->isTempPath($temp, $this->basePath)
            || ($resolved !== false && !$this->isTempPath($resolved, (string) $base))
        ) {
            throw new RuntimeException(sprintf('Refusing to discard directory "%s": not a temporary cache directory.', $temp));
        }
        $this->remove($temp);
    }

    protected function isTempPath(string $path, string $base): bool
    {
        return $base !== ''
            && str_starts_with($path, $base . '/')
            && preg_match('~\.tmp-[^/]+$~', $path) === 1;
    }

    /**
     * Remove the cache of a key, including any leftover temporary or old
     * directory from an interrupted publish().
     */
    public function delete(string $key): void
    {
        $path = $this->path($key);
        $this->remove($path);
        foreach (glob($path . '.{old,tmp-}*', GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $dir) {
            $this->remove($dir);
        }
    }

    public function deleteAll(): void
    {
        $this->remove($this->basePath);
    }

    /**
     * @return string[]
     */
    public function listKeys(): array
    {
        $keys = [];
        foreach (glob($this->basePath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $key = basename($dir);
            if (preg_match(self::KEY_PATTERN, $key)) {
                $keys[] = $key;
            }
        }
        sort($keys);
        return $keys;
    }

    protected function checkKey(string $key): string
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new RuntimeException(sprintf('Invalid vikus cache key "%s".', $key));
        }
        return $key;
    }

    protected function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
