<?php

declare(strict_types=1);

namespace Ameos\AmeosFilemanager\Service;

use Ameos\AmeosFilemanager\Domain\Model\Folder;
use Ameos\AmeosFilemanager\Domain\Repository\FileRepository;
use Ameos\AmeosFilemanager\Domain\Repository\FolderRepository;
use Ameos\AmeosFilemanager\Enum\Configuration;
use Ameos\AmeosFilemanager\Exception\AccessDeniedException;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Folder as ResourceFolder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\Generic\QueryResult;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class FolderService
{
    /**
     * @param FolderRepository $folderRepository
     * @param FileRepository $fileRepository
     * @param AccessService $accessService
     * @param ResourceFactory $resourceFactory
     */
    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly FileRepository $fileRepository,
        private readonly AccessService $accessService,
        private readonly ResourceFactory $resourceFactory
    ) {
    }

    /**
     * return root folder
     *
     * @param array $settings
     * @return ?Folder
     */
    public function getRootFolder(array $settings): ?Folder
    {
        return $this->folderRepository->findByUid($settings[Configuration::SETTINGS_STARTFOLDER]);
    }

    /**
     * return current folder
     * @param ?int $folderIdentifier
     * @param array $settings
     * @return ?Folder
     */
    public function getCurrentFolder(?int $folderIdentifier, array $settings): ?Folder
    {
        $rootFolder = $this->getRootFolder($settings);
        $currentFolder = $folderIdentifier ? $this->folderRepository->findByUid($folderIdentifier) : $rootFolder;
        
        // check if current folder is a child of root folder
        if (!$currentFolder || !$currentFolder->isChildOf($rootFolder->getUid())) {
            throw new AccessDeniedException(LocalizationUtility::translate('accessDenied', Configuration::EXTENSION_KEY));
        }

        // check recursion TODO V12
      /*  if (
            FilemanagerUtility::hasTooMuchRecursion(
                $rootFolder,
                $currentFolder,
                $settings[Configuration::SETTINGS_RECURSION]
            )
        ) {
            throw new TooMuchRecursionException(LocalizationUtility::translate('tooMuchRecursion', Configuration::EXTENSION_KEY));
        }*/


        return $currentFolder;
    }

    /**
     * load Folder
     *
     * @param int $identifier
     * @return ?Folder
     */
    public function load(int $identifier): ?Folder
    {
        return $this->folderRepository->findByUid($identifier) ?? null;
    }

    /**
     * load Folder
     *
     * @param ResourceFolder $folder
     * @return ?Folder
     */
    public function loadByResourceFolder(ResourceFolder $folder): ?Folder
    {
        return $this->loadByStorageAndIdentifier($folder->getStorage(), $folder->getIdentifier());
    }

    /**
     * load Folder
     *
     * @param ResourceStorage $storage
     * @param string $identifier
     * @return ?Folder
     */
    public function loadByStorageAndIdentifier(ResourceStorage $storage, string $identifier): ?Folder
    {
        $data = $this->folderRepository->findRawByStorageAndIdentifier($storage->getUid(), $identifier);
        return $data ? $this->load((int)$data['uid']) : null;
    }

    /**
     * remove folder
     *
     * @param Folder $folder
     * @return bool
     */
    public function remove(Folder $folder): bool
    {
        $storage = $this->resourceFactory->getStorageObject($folder->getStorage());

        if ($folder && $this->accessService->canWriteFolder($GLOBALS['TSFE']->fe_user->user, $folder)) {
            $storage->deleteFolder($storage->getFolder($folder->getIdentifier()), true);
            $this->folderRepository->remove($folder);
            return true;
        }
        return false;
    }

    /**
     * find files for a folder
     *
     * @param Folder $folder
     * @param string $sort
     * @param string $direction
     * @return QueryResult
     */
    public function findFiles(Folder $folder, string $sort = 'sys_file.name', string $direction = 'ASC'): QueryResult
    {
        return $this->fileRepository->findFilesForFolder($folder, $sort, $direction);
    }

    /**
     * create folder from request
     * 
     * @param Folder $parent
     * @param RequestInterface $request
     * @param array $settings
     * @return Folder
     */
    public function create(Folder $parent, RequestInterface $request, array $settings): Folder
    {
        $folder = new Folder();
        $storage = $this->resourceFactory->getStorageObject($settings['storage']);
        $driver = GeneralUtility::makeInstance(LocalDriver::class);

        $title = $this->createPhysicalFolder(
            $storage->getFolder($parent->getIdentifier()),
            $driver->sanitizeFileName($request->getArgument('title'))
        );

        $folder->setUidParent($parent->getUid());
        $folder->setIdentifier($parent->getIdentifier() . $title . '/');
        $folder->setTitle($title);
        $this->populateFolderFromRequest($folder, $request, $settings);

        $this->folderRepository->add($folder);
        $persitenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persitenceManager->persistAll();

        return $folder;
    }

    /**
     * update folder from request
     * 
     * @param Folder $folder
     * @param RequestInterface $request
     * @param array $settings
     * @return Folder
     */
    public function update(Folder $folder, RequestInterface $request, array $settings): Folder
    {
        $storage = $this->resourceFactory->getStorageObject($settings['storage']);
        $driver = GeneralUtility::makeInstance(LocalDriver::class);

        $title = $this->renamePhysicalFolder(
            $storage->getFolder($folder->getIdentifier()),
            $driver->sanitizeFileName($request->getArgument('title'))
        );

        $folder->setTitle($title);
        $folder->setIdentifier($folder->getIdentifier());
        $this->populateFolderFromRequest($folder, $request, $settings);

        $this->folderRepository->add($folder);
        $persitenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persitenceManager->persistAll();

        return $folder;
    }

    /**
     * unindex folder
     *
     * @param ResourceFolder $resourceFolder
     * @return void
     */
    public function unindex(ResourceFolder $resourceFolder): void
    {
        $folder = $this->loadByResourceFolder($resourceFolder);
        if ($folder) {
            $this->folderRepository->remove($folder);
            $persitenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
            $persitenceManager->persistAll();
        }

        foreach ($resourceFolder->getSubfolders() as $subFolder) {
            $this->unindex($subFolder);
        }
    }

    /**
     * index resource folder
     *
     * @param ResourceFolder $resourceFolder
     * @return Folder
     */
    public function index(ResourceFolder $resourceFolder): Folder
    {
        $parent = $this->loadByResourceFolder($resourceFolder->getParentFolder());

        $title = $resourceFolder->getName();

        $folder = new Folder();
        $folder->setUidParent($parent->getUid());
        $folder->setIdentifier($parent->getIdentifier() . $title . '/');
        $folder->setTitle($title);
        $folder->setStorage($resourceFolder->getStorage()->getUid());

        $this->folderRepository->add($folder);
        $persitenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persitenceManager->persistAll();

        return $folder;
    }

    /**
     * rename folder
     *
     * @param ResourceFolder $resourceFolder
     * @param string $name
     * @return Folder
     */
    public function rename(ResourceFolder $resourceFolder, string $name): Folder
    {
        $folder = $this->loadByResourceFolder($resourceFolder);
        $parent = $this->loadByResourceFolder($resourceFolder->getParentFolder());

        $folder->setTitle($name);
        $folder->setIdentifier($parent->getIdentifier() . $name . '/');

        $this->folderRepository->add($folder);
        $persitenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persitenceManager->persistAll();

        foreach ($resourceFolder->getSubfolders() as $subFolder) {
            $this->move($subFolder, $folder->getIdentifier());
        }

        return $folder;
    }

    /**
     * rename folder
     *
     * @param ResourceFolder $resourceFolder
     * @param string $targetIdentifier
     * @return Folder
     */
    public function move(ResourceFolder $resourceFolder, string $targetIdentifier): Folder
    {
        $target = $this->loadByStorageAndIdentifier($resourceFolder->getStorage(), $targetIdentifier);
        $folder = $this->loadByResourceFolder($resourceFolder);

        $folder->setUidParent($target->getUid());
        $folder->setIdentifier($target->getIdentifier() . $resourceFolder->getName() . '/');

        $this->folderRepository->add($folder);
        $persitenceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persitenceManager->persistAll();
        
        foreach ($resourceFolder->getSubfolders() as $subFolder) {
            $this->move($subFolder, $folder->getIdentifier());
        }

        return $folder;
    }

    /**
     * return number of files
     *
     * @param Folder $folder
     * @return int
     */
    public function getNumberOfFiles(Folder $folder): int
    {
        return $this->folderRepository->countFilesForFolder($folder);
    }

    /**
     * return size of files
     *
     * @param Folder $folder
     * @return int
     */
    public function getSizeOfFiles(Folder $folder): int
    {
        return $this->folderRepository->countFilesizeForFolder($folder);
    }

    /**
     * create physical folder and return title
     * 
     * @param ResourceFolder $resourceFolder
     * @param string $title
     * @return string
     */
    private function createPhysicalFolder(ResourceFolder $resourceFolder, string $title): string
    {
        try {
            $resourceFolder->createFolder($title);
            return $title;
        } catch (ExistingTargetFolderException $e) {
            $title = $this->appendSuffixToFolderName($title);
            return $this->createPhysicalFolder($resourceFolder, $title);
        }
    }

    /**
     * rename physical folder and return new title
     * 
     * @param ResourceFolder $resourceFolder
     * @param string $title
     * @return string
     */
    private function renamePhysicalFolder(ResourceFolder $resourceFolder, string $title): string
    {
        $newFolderPath = Environment::getPublicPath()
            . '/'
            . preg_replace('/\/$/i', '', $resourceFolder->getStorage()->getConfiguration()['basePath'])
            . preg_replace(
                '/\/' . $resourceFolder->getName() . '\/$/',
                '/' . $title . '/',
                $resourceFolder->getIdentifier()
            );
        if (is_dir($newFolderPath)) {
            $title = $this->appendSuffixToFolderName($title);
            return $this->renamePhysicalFolder($resourceFolder, $title);
        }
        $resourceFolder->rename($title);
        return $title;
    }

    /**
     * append suffix to folder name
     * 
     * @param string $name
     * @return string
     */
    private function appendSuffixToFolderName(string $name): string
    {
        if (preg_match('/(\d+)$/i', $name, $matches)) {
            return preg_replace('/\d+$/i', (string)((int)$matches[1] + 1), $name);
        }
        return $name . '_1';
    }

    /**
     * populate folder from request
     * 
     * @param Folder $folder
     * @param RequestInterface $request
     * @param array $settings
     * @return void
     */
    private function populateFolderFromRequest(Folder $folder, RequestInterface $request, array $settings): void
    {
        $folder->setStorage((int)$settings['storage']);
        $folder->setDescription($request->getArgument('description'));
        $folder->setKeywords($request->getArgument('keywords'));
        if ($request->hasArgument('no_read_access')) {
            $folder->setNoReadAccess((bool)$request->getArgument('no_read_access'));
        }
        if ($request->hasArgument('no_write_access')) {
            $folder->setNoWriteAccess((bool)$request->getArgument('no_write_access'));
        }
        if ($request->hasArgument('fe_group_read')) {
            $folder->setArrayFeGroupRead($request->getArgument('fe_group_read'));
        }
        if ($request->hasArgument('fe_group_write')) {
            $folder->setArrayFeGroupWrite($request->getArgument('fe_group_write'));
        }
        if ($request->hasArgument('fe_group_addfile')) {
            $folder->setArrayFeGroupAddfile($request->getArgument('fe_group_addfile'));
        }
        if ($request->hasArgument('fe_group_addfolder')) {
            $folder->setArrayFeGroupAddfolder($request->getArgument('fe_group_addfolder'));
        }
        if ($request->hasArgument('categories')) {
            $folder->setCategories($request->getArgument('categories'));
        }
        $folder->setOwnerHasReadAccess(
            isset($settings['newFolder']['owner_has_read_access'])
                ? $settings['newFolder']['owner_has_read_access']
                : 1
        );
        $folder->setOwnerHasWriteAccess(
            isset($settings['newFolder']['owner_has_write_access'])
                ? $settings['newFolder']['owner_has_write_access']
                : 1
        );
    }
}