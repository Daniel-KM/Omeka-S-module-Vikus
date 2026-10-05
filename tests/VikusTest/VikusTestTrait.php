<?php declare(strict_types=1);

namespace VikusTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use VikusTest\Job\NullDispatchStrategy;

trait VikusTestTrait
{
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        return $this->getApplication()->getServiceManager();
    }

    /**
     * Install a null job dispatch strategy on every new application.
     *
     * The default "PhpCli" strategy would fork a "php perform-job.php" process
     * reading config/database.ini (the development database) with job ids from
     * the test database. "Omeka\Job\DispatchStrategy" is only an alias to
     * "PhpCli" (see application/config/module.config.php), so overriding it
     * here, rather than the "Omeka\Job\Dispatcher" service itself, is what
     * makes "Omeka\Job\Dispatcher::dispatch()" pick it up without any change to
     * the production dispatch code. This is done here, on every application
     * built by "getApplication()" (including after "$this->reset()", which
     * discards the previous one), so it survives for the whole test regardless
     * of how many applications it goes through.
     */
    public function getApplication()
    {
        $isNewApplication = $this->application === null;
        $application = parent::getApplication();
        if ($isNewApplication) {
            $services = $application->getServiceManager();
            $services->setAllowOverride(true);
            $services->setService('Omeka\Job\DispatchStrategy', new NullDispatchStrategy());
            $services->setAllowOverride(false);
        }
        return $application;
    }

    protected function loginAdmin(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        if ($auth->hasIdentity()) {
            return;
        }
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Create a user with a role and authenticate as it.
     */
    protected function loginAs(string $email, string $role = 'researcher'): void
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $existing = $api->search('users', ['email' => $email, 'limit' => 1])->getContent();
        if (!$existing) {
            $user = $api->create('users', [
                'o:email' => $email,
                'o:name' => $email,
                'o:role' => $role,
                'o:is_active' => true,
            ])->getContent();
            $services->get('Omeka\EntityManager')
                ->find(\Omeka\Entity\User::class, $user->id())
                ->setPassword('root');
            $services->get('Omeka\EntityManager')->flush();
        }

        $auth = $services->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
        $adapter = $auth->getAdapter();
        $adapter->setIdentity($email);
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    protected function createSite(string $slug): SiteRepresentation
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager')->create('sites', [
            'o:title' => $slug,
            'o:slug' => $slug,
            'o:theme' => 'default',
            'o:is_public' => true,
        ])->getContent();
    }

    /**
     * Create an item with a generated jpeg, ingested via "sideload"-free way.
     *
     * The file is written in files/original and files/medium directly and the
     * media entity is pointed to it, to avoid a dependency on a thumbnailer in
     * the test environment.
     */
    protected function createItemWithImage(array $values, int $width, int $height): ItemRepresentation
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');

        $item = $api->create('items', $values)->getContent();

        $storageId = 'vikustest' . bin2hex(random_bytes(8));
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        foreach (['original' => [$width, $height], 'large' => [$width, $height], 'medium' => [(int) ($width / 2), (int) ($height / 2)]] as $type => [$w, $h]) {
            if (!is_dir("$basePath/$type")) {
                mkdir("$basePath/$type", 0775, true);
            }
            $image = imagecreatetruecolor(max(1, $w), max(1, $h));
            imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
            imagejpeg($image, "$basePath/$type/$storageId.jpg");
            imagedestroy($image);
        }

        $itemEntity = $entityManager->find(\Omeka\Entity\Item::class, $item->id());

        $media = new \Omeka\Entity\Media();
        $media->setItem($itemEntity);
        $media->setIngester('upload');
        $media->setRenderer('file');
        $media->setStorageId($storageId);
        $media->setExtension('jpg');
        $media->setMediaType('image/jpeg');
        $media->setHasOriginal(true);
        $media->setHasThumbnails(true);
        $media->setIsPublic(true);
        $media->setPosition(1);
        $media->setCreated(new \DateTime('now'));
        // The item's media collection may already be initialized as empty (it
        // was fetched before the media existed), so add the media to it
        // directly: cascade persist takes care of persisting it on flush.
        $itemEntity->getMedia()->add($media);
        $entityManager->flush();

        return $api->read('items', $item->id())->getContent();
    }

    /**
     * Check if the module DigitalObject is active, through the api only.
     *
     * The module may be absent, so none of its classes can be referenced: the
     * presence of its api resource is the marker of an active module.
     */
    protected function hasDigitalObject(): bool
    {
        return $this->getServiceLocator()->get('Omeka\ApiAdapterManager')->has('digital_objects');
    }

    /**
     * Create a digital object with a generated jpeg and link it to an item.
     *
     * The api requires a real file or url to ingest, so the derivatives are
     * written on disk and the entity is persisted directly, as
     * createItemWithImage() does for a media. The digital object is an
     * autonomous resource: it is attached to the item by a value of type
     * "resource:digitalobject".
     */
    protected function createDigitalObjectForItem(int $itemId, int $width, int $height, string $term = 'dcterms:hasFormat'): int
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $easyMeta = $services->get('Common\EasyMeta');

        $storageId = 'vikustest' . bin2hex(random_bytes(8));
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        foreach (['original' => [$width, $height], 'large' => [$width, $height], 'medium' => [(int) ($width / 2), (int) ($height / 2)]] as $type => [$w, $h]) {
            if (!is_dir("$basePath/$type")) {
                mkdir("$basePath/$type", 0775, true);
            }
            $image = imagecreatetruecolor(max(1, $w), max(1, $h));
            imagefill($image, 0, 0, imagecolorallocate($image, 60, 200, 60));
            imagejpeg($image, "$basePath/$type/$storageId.jpg");
            imagedestroy($image);
        }

        $class = 'DigitalObject\\Entity\\DigitalObject';
        $digitalObject = new $class();
        $digitalObject->setStorageId($storageId);
        $digitalObject->setExtension('jpg');
        $digitalObject->setMediaType('image/jpeg');
        $digitalObject->setHasOriginal(true);
        $digitalObject->setHasThumbnails(true);
        $digitalObject->setIsPublic(true);
        $digitalObject->setCreated(new \DateTime('now'));
        $entityManager->persist($digitalObject);
        $entityManager->flush();

        $api->update('items', $itemId, [
            $term => [[
                'type' => 'resource:digitalobject',
                'property_id' => $easyMeta->propertyId($term),
                'value_resource_id' => $digitalObject->getId(),
            ]],
        ], [], ['isPartial' => true]);

        return $digitalObject->getId();
    }

    /**
     * Remove a digital object created for a test, with its derivatives.
     */
    protected function deleteDigitalObject(int $id): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $digitalObject = $entityManager->find('DigitalObject\\Entity\\DigitalObject', $id);
        if ($digitalObject) {
            $entityManager->remove($digitalObject);
            $entityManager->flush();
        }
    }

    protected function createPageWithBlock(
        SiteRepresentation $site,
        array $data = [],
        string $layout = 'vikus',
        bool $isPublic = true
    ): \Omeka\Api\Representation\SitePageBlockRepresentation {
        $page = $this->getServiceLocator()->get('Omeka\ApiManager')->create('site_pages', [
            'o:site' => ['o:id' => $site->id()],
            'o:title' => 'Vikus ' . bin2hex(random_bytes(3)),
            'o:slug' => 'vikus-' . bin2hex(random_bytes(3)),
            'o:is_public' => $isPublic,
            'o:block' => [
                ['o:layout' => $layout, 'o:data' => $data],
            ],
        ])->getContent();
        return $page->blocks()[0];
    }

    /**
     * Create an item set attached to a site.
     */
    protected function createItemSet(SiteRepresentation $site, string $title): \Omeka\Api\Representation\ItemSetRepresentation
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $easyMeta = $services->get('Common\EasyMeta');
        $itemSet = $api->create('item_sets', [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('dcterms:title'),
                '@value' => $title,
            ]],
        ])->getContent();

        $api->update('sites', $site->id(), [
            'o:site_item_set' => [
                ['o:item_set' => ['o:id' => $itemSet->id()]],
            ],
        ], [], ['isPartial' => true]);

        return $itemSet;
    }

    /**
     * Run the job generating the cache of a key, in the current process.
     */
    protected function runJob(string $key, string $status = \Omeka\Entity\Job::STATUS_IN_PROGRESS): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $job = new \Omeka\Entity\Job();
        $job->setStatus($status);
        $job->setClass(\Vikus\Job\GenerateData::class);
        $job->setArgs(['key' => $key]);
        // The builder clears the entity manager, so the identity of a previous
        // run in the same test may be detached: it is fetched again.
        $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
        $job->setOwner($entityManager->find(\Omeka\Entity\User::class, $identity->getId()));
        $entityManager->persist($job);
        $entityManager->flush();
        (new \Vikus\Job\GenerateData($job, $services))->perform();
    }

    /**
     * Check if the module Mapping is active, through the api only.
     *
     * The module may be absent, so none of its classes can be referenced: the
     * presence of its api resource is the marker of an active module.
     */
    protected function hasMapping(): bool
    {
        return $this->getServiceLocator()->get('Omeka\ApiAdapterManager')->has('mapping_features');
    }

    /**
     * Add a point to an item with the api of the module Mapping.
     */
    protected function addMappingPoint(int $itemId, float $lng, float $lat): void
    {
        $this->getServiceLocator()->get('Omeka\ApiManager')->create('mapping_features', [
            'o:item' => ['o:id' => $itemId],
            'o-module-mapping:geography-type' => 'Point',
            'o-module-mapping:geography-coordinates' => [$lng, $lat],
        ]);
    }

    /**
     * Remove the jpegs created by createItemWithImage() in files/*.
     */
    protected function cleanVikusTestFiles(): void
    {
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        foreach (['original', 'large', 'medium'] as $type) {
            foreach (glob("$basePath/$type/vikustest*") ?: [] as $file) {
                unlink($file);
            }
        }
    }
}
