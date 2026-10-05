<?php declare(strict_types=1);

namespace VikusTest\Controller;

use Laminas\Form\Element\Csrf;
use Omeka\Test\AbstractHttpControllerTestCase;
use Vikus\Job\GenerateData;
use VikusTest\VikusTestTrait;

class ItemSetPlayerControllerTest extends AbstractHttpControllerTestCase
{
    use VikusTestTrait;

    protected $site;

    protected $itemSet;

    protected $key;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->site = $this->createSite('vikus-is' . bin2hex(random_bytes(3)));
        $this->itemSet = $this->createItemSet($this->site, 'Estampes');
        $this->key = 's' . $this->site->id() . '-r' . $this->itemSet->id();
    }

    public function tearDown(): void
    {
        $this->getServiceLocator()->get('Vikus\CacheStorage')->delete($this->key);
        parent::tearDown();
    }

    protected function path(string $key, string $action = '', ?string $slug = null): string
    {
        return '/s/' . ($slug ?? $this->site->slug()) . '/vikus/' . $key . ($action ? '/' . $action : '');
    }

    protected function countJobs(): int
    {
        return (int) $this->getServiceLocator()->get('Omeka\EntityManager')
            ->createQuery('SELECT COUNT(j.id) FROM Omeka\Entity\Job j WHERE j.class = :class')
            ->setParameter('class', GenerateData::class)
            ->getSingleScalarResult();
    }

    public function testConfigUsesTheTitleOfTheItemSet(): void
    {
        $this->dispatch($this->path($this->key, 'config'));
        $this->assertResponseStatusCode(200);
        $config = json_decode($this->getResponse()->getContent(), true);
        $this->assertSame('Estampes', $config['project']['name']);
        $this->assertStringEndsWith('/vikus/' . $this->key . '/data.csv', $config['loader']['items']);
    }

    public function testNotFoundForAnInvalidKey(): void
    {
        $this->dispatch($this->path('s' . $this->site->id() . '-r0', 'config'));
        $this->assertResponseStatusCode(404);
    }

    public function testNotFoundWhenTheKeyIsForAnotherSite(): void
    {
        $other = $this->createSite('vikus-is-other' . bin2hex(random_bytes(3)));
        $this->dispatch($this->path($this->key, 'config', $other->slug()));
        $this->assertResponseStatusCode(404);
    }

    public function testNotFoundWhenTheItemSetIsNotInTheSite(): void
    {
        $other = $this->createSite('vikus-is-out' . bin2hex(random_bytes(3)));
        $itemSet = $this->createItemSet($other, 'Ailleurs');
        $this->dispatch($this->path('s' . $this->site->id() . '-r' . $itemSet->id(), 'config'));
        $this->assertResponseStatusCode(404);
    }

    public function testNotFoundForAPrivateItemSetWhenAnonymous(): void
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $api->update('item_sets', $this->itemSet->id(), ['o:is_public' => false], [], ['isPartial' => true]);
        $path = $this->path($this->key, 'config');

        // A new application is required to get a real anonymous request: the
        // visibility filter of doctrine is set up once per application, from
        // the identity of its first request.
        $this->reset();
        $this->dispatch($path);
        $this->assertResponseStatusCode(404);

        $this->reset();
        $this->loginAdmin();
        $this->dispatch($path);
        $this->assertResponseStatusCode(200);
    }

    public function testPlayShowsButtonToAdminOnly(): void
    {
        if (!file_exists(dirname(__DIR__, 3) . '/asset/vendor/vikus-viewer/index.html')) {
            $this->markTestSkipped('Requires the viewer.');
        }
        $this->dispatch($this->path($this->key), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('vikus-regenerate', $this->getResponse()->getContent());

        $this->reset();
        // The researcher is searched or created with the rights of the admin.
        $this->loginAdmin();
        $this->loginAs('vikus-researcher@example.com');
        $this->dispatch($this->path($this->key), 'GET', ['config' => 'https://example.org/config']);
        $this->assertResponseStatusCode(200);
        $this->assertStringNotContainsString('vikus-regenerate', $this->getResponse()->getContent());
    }

    public function testGenerateIsForbiddenForAnonymous(): void
    {
        $this->getServiceLocator()->get('Omeka\AuthenticationService')->clearIdentity();
        $this->dispatch($this->path($this->key, 'generate'), 'POST', []);
        $this->assertResponseStatusCode(403);
    }

    public function testGenerateIsForbiddenWithoutTheRightToUpdate(): void
    {
        $this->loginAs('vikus-researcher@example.com');
        $csrf = (new Csrf('confirmform_csrf'))->getValue();
        $this->dispatch($this->path($this->key, 'generate'), 'POST', ['confirmform_csrf' => $csrf]);
        $this->assertResponseStatusCode(403);
    }

    public function testGenerateDispatchesJobAndRedirects(): void
    {
        $before = $this->countJobs();
        $csrf = (new Csrf('confirmform_csrf'))->getValue();
        $this->dispatch($this->path($this->key, 'generate'), 'POST', ['confirmform_csrf' => $csrf]);

        $this->assertResponseStatusCode(302);
        $this->assertSame($before + 1, $this->countJobs());

        $job = $this->getServiceLocator()->get('Omeka\EntityManager')
            ->createQuery('SELECT j FROM Omeka\Entity\Job j WHERE j.class = :class ORDER BY j.id DESC')
            ->setParameter('class', GenerateData::class)
            ->setMaxResults(1)
            ->getSingleResult();
        $this->assertSame(['key' => $this->key], $job->getArgs());
    }
}
