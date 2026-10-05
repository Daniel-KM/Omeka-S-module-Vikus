<?php declare(strict_types=1);

namespace VikusTest\Site\ResourcePageBlockLayout;

use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Job\GenerateData;
use VikusTest\VikusTestTrait;

class VikusTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected $site;

    protected $itemSet;

    protected $key;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->site = $this->createSite('vikus-rpb' . bin2hex(random_bytes(3)));
        $this->itemSet = $this->createItemSet($this->site, 'Estampes');
        $this->key = 's' . $this->site->id() . '-r' . $this->itemSet->id();
        // The helpers need a site route match to build urls and to read the
        // settings of the site.
        $this->dispatch('/s/' . $this->site->slug() . '/item-set/' . $this->itemSet->id());
    }

    public function tearDown(): void
    {
        $this->getServiceLocator()->get('Vikus\CacheStorage')->delete($this->key);
        $this->cleanVikusTestFiles();
        parent::tearDown();
    }

    protected function render(): string
    {
        $services = $this->getServiceLocator();
        return $services->get('Omeka\ResourcePageBlockLayoutManager')->get('vikus')
            ->render($services->get('ViewRenderer'), $this->itemSet);
    }

    /**
     * Hash of the options of the item set, as the generator computes it.
     */
    protected function hash(): string
    {
        $services = $this->getServiceLocator();
        $options = \Vikus\Stdlib\OptionsNormalizer::fromSettings(
            $services->get('Omeka\Settings\Site'),
            $services->get('Config')['vikus']['site_settings']
        );
        $options['query'] = ['item_set_id' => $this->itemSet->id()];
        return \Vikus\Stdlib\DataBuilder::hash($options);
    }

    protected function publish(array $meta): void
    {
        $meta += ['hash' => $this->hash()];
        $storage = $this->getServiceLocator()->get('Vikus\CacheStorage');
        $temp = $storage->prepareTemp($this->key);
        file_put_contents($temp . '/meta.json', json_encode($meta));
        $storage->publish($this->key, $temp);
    }

    public function testLayoutIsRegisteredForItemSetsOnly(): void
    {
        $layout = $this->getServiceLocator()->get('Omeka\ResourcePageBlockLayoutManager')->get('vikus');
        $this->assertSame(['item_sets'], $layout->getCompatibleResourceNames());
    }

    protected function countJobs(): int
    {
        return (int) $this->getServiceLocator()->get('Omeka\EntityManager')
            ->createQuery('SELECT COUNT(j.id) FROM Omeka\Entity\Job j WHERE j.class = :class')
            ->setParameter('class', GenerateData::class)
            ->getSingleScalarResult();
    }

    public function testPendingDispatchesOneJobOnly(): void
    {
        $before = $this->countJobs();

        $html = $this->render();
        $this->assertStringContainsString('vikus-pending', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertSame($before + 1, $this->countJobs());

        // The job dispatched by the first render is still queued, so a second
        // render must not queue another one.
        $this->assertStringContainsString('vikus-pending', $this->render());
        $this->assertSame($before + 1, $this->countJobs());
    }

    /**
     * Queue a job for the key of the item set, started at a given time.
     */
    protected function queueJob(string $started): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $job = new \Omeka\Entity\Job();
        $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
        $job->setClass(GenerateData::class);
        $job->setArgs(['key' => $this->key]);
        $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
        $job->setOwner($entityManager->find(\Omeka\Entity\User::class, $identity->getId()));
        $entityManager->persist($job);
        $entityManager->flush();
        // "prePersist" of the job sets its start to now, so it is dated after.
        $job->setStarted(new \DateTime($started));
        $entityManager->flush();
    }

    public function testARecentJobBlocksANewDispatch(): void
    {
        $this->queueJob('-10 minutes');

        $before = $this->countJobs();
        $this->assertStringContainsString('vikus-pending', $this->render());
        $this->assertSame($before, $this->countJobs());
    }

    public function testAStaleJobDoesNotBlockANewDispatch(): void
    {
        // A job killed without updating its status keeps its row "in progress"
        // forever: it must not block the regeneration.
        $this->queueJob('-2 hours');

        $before = $this->countJobs();
        $this->assertStringContainsString('vikus-pending', $this->render());
        $this->assertSame($before + 1, $this->countJobs());
    }

    public function testOutdatedCacheIsDeletedAndRegenerated(): void
    {
        $this->publish(['total' => 2, 'hash' => 'stale']);

        $before = $this->countJobs();
        $this->assertStringContainsString('vikus-pending', $this->render());
        $this->assertSame($before + 1, $this->countJobs());
        $this->assertDirectoryDoesNotExist(
            $this->getServiceLocator()->get('Vikus\CacheStorage')->path($this->key)
        );
    }

    public function testEmpty(): void
    {
        $this->publish(['total' => 0]);
        $this->assertStringContainsString('vikus-empty', $this->render());
    }

    public function testIframe(): void
    {
        $this->publish(['total' => 3]);
        $before = $this->countJobs();
        $html = $this->render();
        $this->assertStringContainsString('<iframe', $html);
        // A cache matching the options is served as is, without a new job.
        $this->assertSame($before, $this->countJobs());
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('/s/' . $this->site->slug() . '/vikus/' . $this->key, $decoded);
        $this->assertStringContainsString('config=', $decoded);
    }
}
