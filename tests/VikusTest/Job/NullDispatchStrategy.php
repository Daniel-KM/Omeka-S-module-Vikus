<?php declare(strict_types=1);

namespace VikusTest\Job;

use Omeka\Entity\Job;
use Omeka\Job\DispatchStrategy\StrategyInterface;

/**
 * A dispatch strategy that never runs a job.
 *
 * "Omeka\Job\Dispatcher::dispatch()" always persists the Job entity before
 * sending it, so job counts stay meaningful in tests. Nothing is forked and
 * nothing is run, so the entity manager is never cleared: tests that need a
 * generated cache must run the job themselves, as
 * "GenerateDataTest::runJob()" does.
 */
class NullDispatchStrategy implements StrategyInterface
{
    public function send(Job $job)
    {
    }
}
