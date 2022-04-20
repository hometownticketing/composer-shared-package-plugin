<?php

/*
 * This file is part of the "Composer Shared Package Plugin" package.
 *
 * https://github.com/Letudiant/composer-shared-package-plugin
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LEtudiant\Composer\Installer;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Downloader\FilesystemException;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use LEtudiant\Composer\Data\Package\PackageDataManagerInterface;
use LEtudiant\Composer\Installer\Config\SharedPackageInstallerConfig;
use LEtudiant\Composer\Util\SymlinkFilesystem;

/**
 * @author Sylvain Lorinet <sylvain.lorinet@gmail.com>
 */
class SharedPackageInstaller extends LibraryInstaller
{
    const PACKAGE_TYPE = 'shared-package';
    const PACKAGE_PRETTY_NAME = 'hometownticketing/composer-shared-package-plugin';

    /**
     * @var SharedPackageInstallerConfig
     */
    protected $config;

    /**
     * @var PackageDataManagerInterface
     */
    protected $packageDataManager;

    /**
     * @var SymlinkFilesystem
     */
    protected $filesystem;


    /**
     * @param IOInterface                  $io
     * @param Composer                     $composer
     * @param SymlinkFilesystem            $filesystem
     * @param PackageDataManagerInterface  $dataManager
     * @param SharedPackageInstallerConfig $config
     */
    public function __construct(
        IOInterface $io,
        Composer $composer,
        SymlinkFilesystem $filesystem,
        PackageDataManagerInterface $dataManager,
        SharedPackageInstallerConfig $config
    )
    {
        $this->filesystem = $filesystem;

        parent::__construct($io, $composer, 'library', $this->filesystem);

        $this->config = $config;
        $this->vendorDir = $this->config->getVendorDir();
        $this->packageDataManager = $dataManager;
        $this->packageDataManager->setVendorDir($this->vendorDir);
    }

    /**
     * @inheritdoc
     */
    public function getInstallPath(PackageInterface $package)
    {
        $this->initializeVendorDir();

        $basePath =
            $this->vendorDir . DIRECTORY_SEPARATOR
            . $package->getPrettyName() . DIRECTORY_SEPARATOR
            . $package->getPrettyVersion()
        ;

        $targetDir = $package->getTargetDir();

        return $basePath . ($targetDir ? '/' . $targetDir : '');
    }

    /**
     * @param PackageInterface $package
     *
     * @return string
     */
    protected function getPackageVendorSymlink(PackageInterface $package)
    {
        return $this->config->getSymlinkDir() . DIRECTORY_SEPARATOR . $package->getPrettyName();
    }

    /**
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     */
    
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$this->filesystem->isReadable($this->getInstallPath($package)) || $this->filesystem->isDirEmpty($this->getInstallPath($package))) {
            $prom = parent::install($repo, $package);
        } elseif (!$repo->hasPackage($package)) {

            $prom = \React\Promise\resolve();
            $binaryInstaller = $this->binaryInstaller;
            $installPath = $this->getInstallPath($package);
    
            $prom = $prom->then(function () use ($binaryInstaller, $installPath, $package, $repo) {
                $binaryInstaller->installBinaries($package, $installPath);
                if (!$repo->hasPackage($package)) {
                    $repo->addPackage(clone $package);
                }
            });
        }

        $prom = $prom->then(function() use ($package) {
            $this->createPackageVendorSymlink($package);
            $this->packageDataManager->addPackageUsage($package);
        });
        return $prom;
    }
    

    /**
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     *
     * @return bool
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        // Just check if the sources folder and the link exist
        return
            $repo->hasPackage($package)
            && is_readable($this->getInstallPath($package))
            && is_link($this->getPackageVendorSymlink($package))
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->packageDataManager->setPackageInstallationSource($initial);
        $this->packageDataManager->setPackageInstallationSource($target);

        // The package need only a code update because the version (branch), only the commit changed
        if ($this->getInstallPath($initial) === $this->getInstallPath($target)) {
            $prom = parent::update($repo, $initial, $target)
            ->then(function() use ($target) {
                    $this->createPackageVendorSymlink($target);
            });
            return $prom;
        } else {
            // If the initial package sources folder exists, uninstall it
            $this->composer->getInstallationManager()->uninstall($repo, new UninstallOperation($initial));
            
            // Install the target package
            $this->composer->getInstallationManager()->install($repo, new InstallOperation($target));

            $prom = \React\Promise\resolve();
        }
        return $prom;
    }

    /**
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     *
     * @throws FilesystemException
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if ($this->isSourceDirUnused($package) && $this->io->askConfirmation(
                "The package version <info>" . $package->getPrettyName() . "</info> "
                . "(<fg=yellow>" . $package->getPrettyVersion() . "</fg=yellow>) seems to be unused."
                . PHP_EOL
                . 'Do you want to <fg=red>delete the source folder</fg=red> ? [y/n] (default: no) : ',
                false
            )) {
            $this->packageDataManager->setPackageInstallationSource($package);

            $prom = parent::uninstall($repo, $package);
        } else {
            $this->binaryInstaller->removeBinaries($package);
            $repo->removePackage($package);
            $prom = \React\Promise\resolve();
        }

        $prom = $prom->then(function() use ($package) {
            $this->packageDataManager->removePackageUsage($package);
            $this->removePackageVendorSymlink($package);
        });
        return $prom;
    }

    /**
     * Detect if other project use the dependency by using the "packages.json" file
     *
     * @param PackageInterface $package
     *
     * @return bool
     */
    protected function isSourceDirUnused(PackageInterface $package)
    {
        $usageData = $this->packageDataManager->getPackageUsage($package);

        return sizeof($usageData) <= 1;
    }

    /**
     * @param PackageInterface $package
     */
    protected function createPackageVendorSymlink(PackageInterface $package)
    {
        if ($this->config->isSymlinkEnabled() && $this->filesystem->ensureSymlinkExists(
                $this->getSymlinkSourcePath($package),
                $this->getPackageVendorSymlink($package)
            )
        ) {
            $this->io->write('  - Creating symlink for <info>' . $package->getPrettyName()
                . '</info> (<fg=yellow>' . $package->getPrettyVersion() . '</fg=yellow>)');
        }

        // Clean up after ourselves in Composer 2+
        $vendorDirJunk = rtrim($this->composer->getConfig()->get('vendor-dir'), '/')
        . DIRECTORY_SEPARATOR . $package->getPrettyName();
        try {
            rmdir($vendorDirJunk);
            rmdir(dirname($vendorDirJunk));
        } catch(\Throwable $e) {}

    }
    protected function getDirContents($dir, &$results = array()) {
        $files = scandir($dir);
    
        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                $results[] = $path;
            } else if ($value != "." && $value != "..") {
                $this->getDirContents($path, $results);
                $results[] = $path;
            }
        }
    
        return $results;
    }

    /**
     * @param PackageInterface $package
     *
     * @return string
     */
    protected function getSymlinkSourcePath(PackageInterface $package)
    {
        if (null != $this->config->getSymlinkBasePath()) {
            $targetDir = $package->getTargetDir();
            $sourcePath =
                $this->config->getSymlinkBasePath()
                . '/' . $package->getPrettyName()
                . '/' . $package->getPrettyVersion()
                . ($targetDir ? '/' . $targetDir : '')
            ;
        } else {
            $sourcePath = $this->getInstallPath($package);
        }

        return $sourcePath;
    }

    /**
     * @param PackageInterface $package
     *
     * @throws FilesystemException
     */
    protected function removePackageVendorSymlink(PackageInterface $package)
    {
        if (
            $this->config->isSymlinkEnabled()
            && $this->filesystem->removeSymlink($this->getPackageVendorSymlink($package))
        ) {
            $this->io->write('  - Deleting symlink for <info>' . $package->getPrettyName() . '</info> '
                . '(<fg=yellow>' . $package->getPrettyVersion() . '</fg=yellow>)');

            $symlinkParentDirectory = dirname($this->getPackageVendorSymlink($package));
            $this->filesystem->removeEmptyDirectory($symlinkParentDirectory);
        }
    }

    /**
     * @param string $packageType
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return true;
    }
}
