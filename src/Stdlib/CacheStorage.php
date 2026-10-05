<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class CacheStorage
{
    protected string $basePath;

    protected string $baseUrl;

    public function __construct(string $basePath, string $baseUrl)
    {
        $this->basePath = rtrim($basePath, '/') . '/vikus';
        $this->baseUrl = rtrim($baseUrl, '/') . '/vikus';
    }

    public function path(int $blockId): string
    {
        return $this->basePath . '/b' . $blockId;
    }

    public function url(int $blockId): string
    {
        return $this->baseUrl . '/b' . $blockId;
    }

    public function readMeta(int $blockId): array
    {
        $file = $this->path($blockId) . '/meta.json';
        return is_file($file)
            ? json_decode((string) file_get_contents($file), true) ?: []
            : [];
    }

    /**
     * Create a unique, empty temporary directory and return its path.
     *
     * The directory is unique per call (not per block) so that two concurrent
     * jobs building the same block never share, and so never wipe, each other's
     * temporary directory.
     */
    public function prepareTemp(int $blockId): string
    {
        do {
            $temp = $this->path($blockId) . '.tmp-' . bin2hex(random_bytes(8));
        } while (is_dir($temp));
        if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $temp));
        }
        return $temp;
    }

    /**
     * Replace the cache of a block by the given temporary directory.
     */
    public function publish(int $blockId, string $temp): void
    {
        $path = $this->path($blockId);
        $old = $path . '.old';
        $this->remove($old);
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
     * an unrelated directory.
     */
    public function discardTemp(string $temp): void
    {
        if (!str_starts_with($temp, $this->basePath . '/') || !preg_match('~\.tmp-[^/]+$~', $temp)) {
            throw new RuntimeException(sprintf('Refusing to discard directory "%s": not a temporary cache directory.', $temp));
        }
        $this->remove($temp);
    }

    /**
     * Remove the cache of a block, including any leftover temporary or old
     * directory from an interrupted publish().
     */
    public function delete(int $blockId): void
    {
        $path = $this->path($blockId);
        $this->remove($path);
        $this->remove($path . '.old');
        foreach (glob($path . '.tmp-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->remove($dir);
        }
    }

    public function deleteAll(): void
    {
        $this->remove($this->basePath);
    }

    /**
     * @return int[]
     */
    public function listBlockIds(): array
    {
        $ids = [];
        foreach (glob($this->basePath . '/b*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('~/b(\d+)$~', $dir, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }
        sort($ids);
        return $ids;
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
