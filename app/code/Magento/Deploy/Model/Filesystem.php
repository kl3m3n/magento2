<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Deploy\Model;

use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\DeploymentConfig\Reader;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Store\Model\Config\StoreView;
use Magento\Developer\Console\Command\CssDeployCommand;

/**
 * A class to manage Magento modes
 *
 */
class Filesystem
{
    /**
     * File access permissions
     */
    const PERMISSIONS_FILE = 0640;

    /**
     * Directory access permissions
     */
    const PERMISSIONS_DIR = 0750;

    /**
     * Default theme when no theme is stored in configuration
     */
    const DEFAULT_THEME = 'Magento/blank';

    /** @var \Magento\Framework\App\DeploymentConfig\Writer */
    private $writer;

    /** @var \Magento\Framework\App\DeploymentConfig\Reader */
    private $reader;

    /** @var ObjectManagerInterface */
    private $objectManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var Filesystem */
    private $directoryList;

    /** @var File */
    private $driverFile;

    /** @var StoreView */
    private $storeView;

    /** @var \Magento\Framework\Shell */
    private $shell;

    /** @var  string */
    private $functionCallPath;

    /**
     * @param Writer $writer
     * @param Reader $reader
     * @param ObjectManagerInterface $objectManager
     * @param \Magento\Framework\Filesystem $filesystem
     * @param DirectoryList $directoryList
     * @param File $driverFile
     * @param StoreView $storeView
     * @param \Magento\Framework\Shell $shell
     */
    public function __construct(
        Writer $writer,
        Reader $reader,
        ObjectManagerInterface $objectManager,
        \Magento\Framework\Filesystem $filesystem,
        DirectoryList $directoryList,
        File $driverFile,
        StoreView $storeView,
        \Magento\Framework\Shell $shell
    ) {
        $this->writer = $writer;
        $this->reader = $reader;
        $this->objectManager = $objectManager;
        $this->filesystem = $filesystem;
        $this->directoryList = $directoryList;
        $this->driverFile = $driverFile;
        $this->storeView = $storeView;
        $this->shell = $shell;
        $this->functionCallPath = 'php -f ' . BP . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'magento ';
    }

    /**
     * Regenerate static
     *
     * @param OutputInterface $output
     */
    public function regenerateStatic(OutputInterface $output)
    {
        // Сlean up /var/generation, /var/di/, /var/view_preprocessed and /pub/static directories
        $this->cleanupFilesystem(
            [
                DirectoryList::CACHE,
                DirectoryList::GENERATION,
                DirectoryList::DI,
                DirectoryList::TMP_MATERIALIZATION_DIR
            ]
        );
        $this->changePermissions(
            [
                DirectoryList::STATIC_VIEW
            ],
            self::PERMISSIONS_DIR,
            self::PERMISSIONS_DIR
        );

        // Trigger static assets compilation and deployment
        $this->deployStaticContent($output);
        $this->deployCss($output);
        // Trigger code generation
        $this->compile($output);
        $this->lockStaticResources();
    }

    /**
     * Deploy CSS
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function deployCss(OutputInterface $output)
    {
        $themeLocalePairs = $this->storeView->retrieveThemeLocalePairs();
        foreach ($themeLocalePairs as $themeLocalePair) {
            $theme = $themeLocalePair['theme'] ?: self::DEFAULT_THEME;
            $cmd = $this->functionCallPath . 'dev:css:deploy less'
                . ' --' . CssDeployCommand::THEME_OPTION . '="' . $theme . '"'
                . ' --' . CssDeployCommand::LOCALE_OPTION . '="' . $themeLocalePair['locale'] . '"';

            /**
             * @todo build a solution that does not depend on exec
             */
            $execOutput = $this->shell->execute($cmd);
            $output->writeln($execOutput);
        }
        $output->writeln('CSS deployment complete');
    }

    /**
     * Deploy static content
     *
     * @param OutputInterface $output
     * @return void
     * @throws \Exception
     */
    protected function deployStaticContent(OutputInterface $output)
    {
        $output->writeln('Static content deployment start');
        $cmd = $this->functionCallPath . 'setup:static-content:deploy '
            . implode(' ', $this->storeView->retrieveLocales());

        /**
         * @todo build a solution that does not depend on exec
         */
        $execOutput = $this->shell->execute($cmd);
        $output->writeln($execOutput);
        $output->writeln('Static content deployment complete');
    }

    /**
     * Runs code multi-tenant compiler to generate code and DI information
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function compile(OutputInterface $output)
    {
        $output->writeln('Start compilation');
        $this->cleanupFilesystem(
            [
                DirectoryList::CACHE,
                DirectoryList::GENERATION,
                DirectoryList::DI,
            ]
        );
        $cmd = $this->functionCallPath . 'setup:di:compile-multi-tenant';

        /**
         * exec command is necessary for now to isolate the autoloaders in the compiler from the memory state
         * of this process, which would prevent some classes from being generated
         *
         * @todo build a solution that does not depend on exec
         */
        $execOutput = $this->shell->execute($cmd);
        $output->writeln($execOutput);
        $output->writeln('Compilation complete');
    }

    /**
     * Deletes specified directories by code
     *
     * @param array $directoryCodeList
     * @return void
     */
    public function cleanupFilesystem($directoryCodeList)
    {
        $excludePatterns = ['#.htaccess#', '#deployed_version.txt#'];
        foreach ($directoryCodeList as $code) {
            if ($code == DirectoryList::STATIC_VIEW) {
                $directoryPath = $this->directoryList->getPath(DirectoryList::STATIC_VIEW);
                if ($this->driverFile->isExists($directoryPath)) {
                    $files = $this->driverFile->readDirectory($directoryPath);
                    foreach ($files as $file) {
                        foreach ($excludePatterns as $pattern) {
                            if (preg_match($pattern, $file)) {
                                continue 2;
                            }
                        }
                        if ($this->driverFile->isFile($file)) {
                            $this->driverFile->deleteFile($file);
                        } else {
                            $this->driverFile->deleteDirectory($file);
                        }
                    }
                }
            } else {
                $this->filesystem->getDirectoryWrite($code)
                    ->delete();
            }
        }
    }

    /**
     * Change permissions for directories by their code
     *
     * @param array $directoryCodeList
     * @param int $dirPermissions
     * @param int $filePermissions
     * @return void
     */
    protected function changePermissions($directoryCodeList, $dirPermissions, $filePermissions)
    {
        foreach ($directoryCodeList as $code) {
            $directoryPath = $this->directoryList->getPath($code);
            if ($this->driverFile->isExists($directoryPath)) {
                $this->filesystem->getDirectoryWrite($code)
                    ->changePermissionsRecursively('', $dirPermissions, $filePermissions);
            } else {
                $this->driverFile->createDirectory($directoryPath, $dirPermissions);
            }
        }
    }

    /**
     * Chenge permissions on static resources
     *
     * @return void
     */
    public function lockStaticResources()
    {
        // Lock /var/generation, /var/di/ and /var/view_preprocessed directories
        $this->changePermissions(
            [
                DirectoryList::GENERATION,
                DirectoryList::DI,
                DirectoryList::TMP_MATERIALIZATION_DIR,
            ],
            self::PERMISSIONS_DIR,
            self::PERMISSIONS_FILE
        );
    }
}
