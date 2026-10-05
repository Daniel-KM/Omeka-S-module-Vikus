<?php declare(strict_types=1);

namespace Vikus\Stdlib;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Omeka\Entity\Job;
use Omeka\Job\Dispatcher;
use Vikus\Job\GenerateData;

/**
 * Generate the cache of a viewer when it is missing or outdated.
 *
 * A public page is rendered by any visitor, so a job is dispatched only when
 * no other one is building the same cache. Two simultaneous renders may still
 * dispatch two jobs: it is harmless, since they build the same data in their
 * own temporary directory and publish the same hash.
 */
class Generator
{
    const RUNNING = [Job::STATUS_STARTING, Job::STATUS_IN_PROGRESS];

    /**
     * A job killed without updating its status keeps its row "in progress"
     * forever, so a job is only taken into account during one hour.
     */
    const RUNNING_MAX_AGE = 3600;

    protected CacheStorage $cacheStorage;

    protected EntityManager $entityManager;

    protected Dispatcher $dispatcher;

    protected LoggerInterface $logger;

    public function __construct(
        CacheStorage $cacheStorage,
        EntityManager $entityManager,
        Dispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        $this->cacheStorage = $cacheStorage;
        $this->entityManager = $entityManager;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
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
            try {
                $this->cacheStorage->delete($key);
            } catch (\RuntimeException $e) {
                $this->logger->err(
                    'Vikus cache "{key}": {message}', // @translate
                    ['key' => $key, 'message' => $e->getMessage()]
                );
            }
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
        $oldest = time() - self::RUNNING_MAX_AGE;
        foreach ($jobs as $job) {
            $started = $job->getStarted();
            if ((($job->getArgs() ?: [])['key'] ?? null) === $key
                && $started
                && $started->getTimestamp() >= $oldest
            ) {
                return true;
            }
        }
        return false;
    }
}
