<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Doctrine\ORM\EntityManager;
use Omeka\Entity\Job;
use Omeka\Job\Dispatcher;
use Vikus\Job\GenerateData;

/**
 * Generate the cache of a viewer when it is missing or outdated.
 *
 * A public page is rendered by any visitor, so a job is dispatched only when
 * no other one is building the same cache.
 */
class Generator
{
    const RUNNING = [Job::STATUS_STARTING, Job::STATUS_IN_PROGRESS];

    protected CacheStorage $cacheStorage;

    protected EntityManager $entityManager;

    protected Dispatcher $dispatcher;

    public function __construct(CacheStorage $cacheStorage, EntityManager $entityManager, Dispatcher $dispatcher)
    {
        $this->cacheStorage = $cacheStorage;
        $this->entityManager = $entityManager;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Dispatch a job when the cache of a key does not match the options.
     */
    public function ensure(string $key, array $options): void
    {
        $meta = $this->cacheStorage->readMeta($key);
        $hash = DataBuilder::hash($options);
        if (($meta['hash'] ?? null) === $hash) {
            return;
        }
        if ($meta) {
            // The published cache no longer matches the options, so it must not
            // be served while the new one is built.
            $this->cacheStorage->delete($key);
        }
        if ($this->isRunning($key)) {
            return;
        }
        $this->dispatcher->dispatch(GenerateData::class, ['key' => $key]);
    }

    /**
     * Check if a job is already building the cache of a key.
     */
    protected function isRunning(string $key): bool
    {
        $jobs = $this->entityManager->getRepository(Job::class)->findBy([
            'class' => GenerateData::class,
            'status' => self::RUNNING,
        ]);
        foreach ($jobs as $job) {
            if ((($job->getArgs() ?: [])['key'] ?? null) === $key) {
                return true;
            }
        }
        return false;
    }
}
