<?php declare(strict_types=1);

namespace VikusTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\SiteRepresentation;

trait VikusTestTrait
{
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        return $this->getApplication()->getServiceManager();
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

        $media = new \Omeka\Entity\Media();
        $media->setItem($entityManager->find(\Omeka\Entity\Item::class, $item->id()));
        $media->setIngester('upload');
        $media->setRenderer('file');
        $media->setStorageId($storageId);
        $media->setExtension('jpg');
        $media->setMediaType('image/jpeg');
        $media->setHasOriginal(true);
        $media->setHasThumbnails(true);
        $media->setIsPublic(true);
        $media->setPosition(1);
        $entityManager->persist($media);
        $entityManager->flush();
        $entityManager->clear();

        return $api->read('items', $item->id())->getContent();
    }
}
