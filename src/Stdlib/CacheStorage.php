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
     * Create an empty temporary directory and return its path.
     */
    public function prepareTemp(int $blockId): string
    {
        $temp = $this->path($blockId) . '.tmp';
        $this->remove($temp);
        if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $temp));
        }
        return $temp;
    }

    /**
     * Replace the cache by the temporary directory.
     */
    public function publish(int $blockId): void
    {
        $path = $this->path($blockId);
        $old = $path . '.old';
        $this->remove($old);
        if (is_dir($path)) {
            rename($path, $old);
        }
        rename($path . '.tmp', $path);
        $this->remove($old);
    }

    public function discardTemp(int $blockId): void
    {
        $this->remove($this->path($blockId) . '.tmp');
    }

    public function delete(int $blockId): void
    {
        $this->remove($this->path($blockId));
        $this->discardTemp($blockId);
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
