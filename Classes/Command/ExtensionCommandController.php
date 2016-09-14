<?php
namespace Helhum\Typo3Console\Command;

/*
 * This file is part of the TYPO3 console project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read
 * LICENSE file that was distributed with this source code.
 *
 */

use Helhum\Typo3Console\Mvc\Controller\CommandController;
use Helhum\Typo3Console\Utility\ExtensionManagerListUtility;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\ClassLoadingInformation;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extensionmanager\Utility\FileHandlingUtility;

/**
 * CommandController for working with extension management through CLI
 */
class ExtensionCommandController extends CommandController
{
    /**
     * @var bool
     */
    protected $requestAdminPermissions = true;

    /**
     * @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     * @inject
     */
    protected $signalSlotDispatcher;

    /**
     * @var \TYPO3\CMS\Extensionmanager\Utility\InstallUtility
     * @inject
     */
    protected $extensionInstaller;

    /**
     * @var \TYPO3\CMS\Core\Package\PackageManager
     * @inject
     */
    protected $packageManager;

    /**
     * Activate extension(s)
     *
     * Activates one or more extensions by key.
     * Marks extensions as active, sets them up and clears caches for every activated extension.
     *
     * @param array $extensionKeys Extension keys to activate. Separate multiple extension keys with comma.
     * @throws \TYPO3\CMS\Extensionmanager\Exception\ExtensionManagerException
     */
    public function activateCommand(array $extensionKeys)
    {
        $this->emitPackagesMayHaveChangedSignal();
        foreach ($extensionKeys as $extensionKey) {
            $this->extensionInstaller->install($extensionKey);
        }
        $extensionKeysAsString = implode('", "', $extensionKeys);
        if (count($extensionKeys) === 1) {
            $this->outputLine('<info>Extension "%s" is now active.</info>', [$extensionKeysAsString]);
        } else {
            $this->outputLine('<info>Extensions "%s" are now active.</info>', [$extensionKeysAsString]);
        }
    }

    /**
     * Deactivate extension(s)
     *
     * Deactivates one or more extensions by key.
     * Marks extensions as inactive in the system and clears caches for every deactivated extension.
     *
     * @param array $extensionKeys Extension keys to deactivate. Separate multiple extension keys with comma.
     * @throws \TYPO3\CMS\Extensionmanager\Exception\ExtensionManagerException
     */
    public function deactivateCommand(array $extensionKeys)
    {
        foreach ($extensionKeys as $extensionKey) {
            $this->extensionInstaller->uninstall($extensionKey);
        }
        $extensionKeysAsString = implode('", "', $extensionKeys);
        if (count($extensionKeys) === 1) {
            $this->outputLine('<info>Extension "%s" is now inactive.</info>', [$extensionKeysAsString]);
        } else {
            $this->outputLine('<info>Extensions "%s" are now inactive.</info>', [$extensionKeysAsString]);
        }
    }

    /**
     * Set up extension(s)
     *
     * Sets up one or more extensions by key.
     * Set up means:
     *
     * - Database migrations and additions
     * - Importing files and data
     * - Writing default extension configuration
     *
     * @param array $extensionKeys Extension keys to set up. Separate multiple extension keys with comma.
     */
    public function setupCommand(array $extensionKeys)
    {
        foreach ($extensionKeys as $extensionKey) {
            $this->extensionInstaller->processExtensionSetup($extensionKey);
        }
        $extensionKeysAsString = implode('", "', $extensionKeys);
        if (count($extensionKeys) === 1) {
            $this->outputLine('<info>Extension "%s" is now set up.</info>', [$extensionKeysAsString]);
        } else {
            $this->outputLine('<info>Extensions "%s" are now set up.</info>', [$extensionKeysAsString]);
        }
    }

    /**
     * Set up all active extensions
     *
     * Sets up all extensions that are marked as active in the system.
     *
     * This command is especially useful for deployment, where extensions
     * are already marked as active, but have not been set up yet or might have changed. It ensures every necessary
     * setup step for the (changed) extensions is performed.
     * As an additional benefit no caches are flushed, which significantly improves performance of this command
     * and avoids unnecessary cache clearing.
     *
     * @see extensionmanager:extension:setup
     * @see typo3_console:install:generatepackagestates
     * @see typo3_console:cache:flush
     */
    public function setupActiveCommand()
    {
        $activeExtensions = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $activeExtensions[] = $package->getPackageKey();
        }
        $this->setupCommand($activeExtensions);
    }

    /**
     * Create uploads folder for active extensions
     *
     * @cli
     * @return void
     */
    public function createExtensionFoldersCommand()
    {
        /** @var ExtensionManagerListUtility $listUtility */
        $listUtility = $this->objectManager->get(ExtensionManagerListUtility::class);
        /** @var FileHandlingUtility $fileHandlingUtility */
        $fileHandlingUtility = $this->objectManager->get(FileHandlingUtility::class);
        $extensions = $listUtility->getAvailableAndInstalledExtensionsWithAdditionalInformation();
        foreach ($extensions as $extension) {
            if (isset($extension['installed']) && $extension['installed']) {
                // Pass the complete extension information to the file-handling-function.
                // Only the extensions key is not sufficient
                $fileHandlingUtility->ensureConfiguredDirectoriesExist($extension);
            }
        }
        $this->outputLine('Create configured directories for installed extensions');
    }

    /**
     * Dump class auto-load
     *
     * Updates class loading information in non composer managed TYPO3 installations.
     *
     * This command is only needed during development. The extension manager takes care
     * creating or updating this info properly during extension (de-)activation.
     *
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\StopActionException
     */
    public function dumpAutoloadCommand()
    {
        if (Bootstrap::usesComposerClassLoading()) {
            $this->output->outputLine('<error>Class loading information is managed by composer. Use "composer dump-autoload" command to update the information.</error>');
            $this->quit(1);
        } else {
            ClassLoadingInformation::dumpClassLoadingInformation();
            $this->output->outputLine('Class Loading information has been updated.');
        }
    }

    /**
     * List all installed extensions
     *
     * @return void
     */
    public function listInstalledCommand()
    {
        $this->outputLine('%s', [implode(',', ExtensionManagementUtility::getLoadedExtensionListArray())]);
    }

    /**
     * Emits packages may have changed signal
     */
    protected function emitPackagesMayHaveChangedSignal()
    {
        $this->signalSlotDispatcher->dispatch('PackageManagement', 'packagesMayHaveChanged');
    }

    /**
     * Dump class auto-load (DEPRECATED)
     *
     * Updates class loading information.
     * Use <code>extension:dumpautoload</code> instead!
     *
     * @return void
     * @internal
     * @deprecated use dumpautoload instead
     * @see extensionmanager:extension:dumpautoload
     */
    public function dumpClassLoadingInformationCommand()
    {
        $this->outputLine('<comment>This command is deprecated. Please use <code>./typo3cms extension:dumpautoload</code> instead!</comment>');
        $this->dumpAutoloadCommand();
    }

    /**
     * Install extension (DEPRECATED)
     *
     * Installs an extension by key
     * Use "activate" command instead!
     *
     * @param string $extensionKey The "extension_key" format of extension key to be installed
     * @return void
     * @internal
     * @deprecated use activate instead
     * @see extensionmanager:extension:activate
     */
    public function installCommand($extensionKey)
    {
        $this->outputLine('<comment>This command is deprecated. Please use <code>./typo3cms extension:activate</code> instead!</comment>');
        $this->activateCommand([$extensionKey]);
    }

    /**
     * Uninstall extension (DEPRECATED)
     *
     * Uninstalls an extension by key
     * Use "deactivate" command instead!
     *
     * @param string $extensionKey The "extension_key" format of extension key to be uninstalled
     * @return void
     * @internal
     * @deprecated use deactivate instead
     * @see extensionmanager:extension:deactivate
     */
    public function uninstallCommand($extensionKey)
    {
        $this->outputLine('<comment>This command is deprecated. Please use <code>./typo3cms extension:deactivate</code> instead!</comment>');
        $this->deactivateCommand([$extensionKey]);
    }
}
