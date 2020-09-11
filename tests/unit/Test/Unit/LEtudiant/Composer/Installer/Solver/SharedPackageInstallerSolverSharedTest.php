<?php

/*
 * This file is part of the "Composer Shared Package Plugin" package.
 *
 * https://github.com/Letudiant/composer-shared-package-plugin
 *
 * For the full license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Test\Unit\LEtudiant\Composer\Installer\Solver;

use Composer\Installer\LibraryInstaller;
use Composer\Package\Package;
use Composer\Repository\InstalledRepositoryInterface;
use LEtudiant\Composer\Data\Package\SharedPackageDataManager;
use LEtudiant\Composer\Installer\Config\SharedPackageInstallerConfig;
use LEtudiant\Composer\Installer\Solver\SharedPackageSolver;
use LEtudiant\Composer\Installer\SharedPackageInstaller;
use LEtudiant\Composer\Installer\Solver\SharedPackageInstallerSolver;

/**
 * @author Sylvain Lorinet <sylvain.lorinet@gmail.com>
 *
 * @covers \LEtudiant\Composer\Installer\Solver\SharedPackageInstallerSolver
 */
class SharedPackageInstallerSolverSharedTest extends SharedPackageInstallerSolverNotSharedTest
{
    /**
     * @var SharedPackageInstaller|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $installer;

    /**
     * @var InstalledRepositoryInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $repository;


    /**
     * @inheritdoc
     */
    protected function setUp():void
    {
        parent::setUp();

        $this->installer = $this->getMockBuilder('\LEtudiant\Composer\Installer\SharedPackageInstaller')
            ->disableOriginalConstructor()
            ->getMockBuilder()
        ;

        $this->repository = $this->getMockBuilder('\Composer\Repository\InstalledRepositoryInterface');
    }

    /**
     * @return SharedPackageInstallerSolver
     */
    protected function createSolver()
    {
        /** @var LibraryInstaller|\PHPUnit_Framework_MockObject_MockObject $defaultInstaller */
        $defaultInstaller = $this->getMockBuilder('\Composer\Installer\LibraryInstaller')
            ->disableOriginalConstructor()
            ->getMockBuilder()
        ;

        $config = new SharedPackageInstallerConfig('foo', 'bar', array(
            SharedPackageInstaller::PACKAGE_TYPE => array(
                'vendor-dir' => 'foo'
            )
        ));

        return new SharedPackageInstallerSolver(new SharedPackageSolver($config), $this->installer, $defaultInstaller);
    }

    /**
     * @test
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Package is not installed : letudiant/foo-bar
     */
    public function updateWhenInitialNotInstalledException()
    {
        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();

        $this->installer
            ->expects($this->never())
            ->method('update')
            ->with($this->repository, $initial, $target)
        ;

        $this->createSolver()->update($this->repository, $initial, $target);
    }

    /**
     * @test
     */
    public function uninstall()
    {
        $package = $this->createPackageMock();

        $this->installer
            ->expects($this->once())
            ->method('uninstall')
            ->with($this->repository, $package)
        ;

        $this->repository
            ->expects($this->once())
            ->method('hasPackage')
            ->with($package)
            ->willReturn(true)
        ;

        $this->createSolver()->uninstall($this->repository, $package);
    }

    /**
     * @test
     */
    public function update()
    {
        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();

        $this->installer
            ->expects($this->once())
            ->method('update')
            ->with($this->repository, $initial, $target)
        ;

        $this->repository
            ->expects($this->once())
            ->method('hasPackage')
            ->with($initial)
            ->willReturn(true)
        ;

        $this->createSolver()->update($this->repository, $initial, $target);
    }

    /**
     * @test
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Package is not installed : letudiant/foo-bar
     */
    public function uninstallWhenPackageNotInstalledException()
    {
        $package = $this->createPackageMock();

        $this->installer
            ->expects($this->never())
            ->method('uninstall')
            ->with($this->repository, $package)
        ;

        $this->createSolver()->uninstall($this->repository, $package);
    }

    /**
     * @test
     */
    public function supports()
    {
        $this->assertTrue($this->createSolver()->supports(SharedPackageInstaller::PACKAGE_TYPE));
    }

    /**
     * @return Package|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function createPackageMock()
    {
        /** @var Package|\PHPUnit_Framework_MockObject_MockObject $package */
        $package = $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs(array(md5(mt_rand()), 'dev-develop', 'dev-develop'))
            ->getMockBuilder()
        ;

        $package
            ->expects($this->any())
            ->method('getType')
            ->willReturn(SharedPackageInstaller::PACKAGE_TYPE)
        ;

        $package
            ->expects($this->any())
            ->method('isDev')
            ->willReturn(true)
        ;

        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->willReturn('letudiant/foo-bar')
        ;

        $package
            ->expects($this->any())
            ->method('getPrettyVersion')
            ->willReturn('dev-develop')
        ;

        $package
            ->expects($this->any())
            ->method('getVersion')
            ->willReturn('dev-develop')
        ;

        $package
            ->expects($this->any())
            ->method('getInstallationSource')
            ->willReturn(SharedPackageDataManager::PACKAGE_INSTALLATION_SOURCE)
        ;

        return $package;
    }
}
