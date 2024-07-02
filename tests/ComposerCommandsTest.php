<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Json\JsonFile;
use Composer\Repository\FilesystemRepository;
use Tuf\ComposerIntegration\TufValidatedComposerRepository;

/**
 * Tests TUF protection when using Composer in an example project.
 */
class ComposerCommandsTest extends FunctionalTestBase
{
    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->startServer();
        $this->composer(['require', 'php-tuf/composer-integration']);

        // Enable TUF protection at the command line so we know it works.
        $this->assertStringContainsString(
          "http://localhost:8080 is now protected by TUF.",
          $this->composer(['tuf:protect', 'fixture'])->getOutput(),
        );
    }

    /**
     * Tests that the `tuf:protect` command fails on non-Composer repositories.
     */
    public function testCannotProtectNonComposerRepository(): void
    {
        $output = $this->composer(['tuf:protect', 'plugin'], 1)
          ->getErrorOutput();
        $this->assertStringContainsString('Only Composer repositories can be protected by TUF.', $output);
    }

    /**
     * Tests requiring and removing a TUF-protected package with a dependency.
     */
    public function testRequireAndRemove(): void
    {
        $vendorDir = $this->workingDir . '/vendor';

        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/token");
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/pathauto");

        // Run Composer in very, very verbose mode so that we can capture and assert the
        // debugging messages generated by the plugin, which will be logged to STDERR.
        $debug = $this->composer(['require', 'drupal/pathauto', '--with-all-dependencies', '-vvv'])
            ->getErrorOutput();
        $this->assertStringContainsString('TUF integration enabled.', $debug);
        $this->assertStringContainsString('[TUF] Root metadata for http://localhost:8080 loaded from ', $debug);
        $this->assertStringContainsString('[TUF] Packages from http://localhost:8080 are verified by TUF.', $debug);
        $this->assertStringContainsString('[TUF] Metadata source: http://localhost:8080/metadata/', $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' limited to 92 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/pathauto.json' limited to 1610 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/pathauto.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/token.json' limited to 1330 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/token.json' validated.", $debug);
        // token~dev.json doesn't exist, so the plugin will limit it to a hard-coded maximum
        // size, and there should not be a message saying that it was validated.
        $this->assertStringContainsString("[TUF] Target 'drupal/token~dev.json' limited to " . TufValidatedComposerRepository::MAX_404_BYTES, $debug);
        $this->assertStringNotContainsStringIgnoringCase("[TUF] Target 'drupal/token~dev.json' validated.", $debug);
        // The plugin won't report the maximum download size of package targets; instead, that
        // information will be stored in the transport options saved to the lock file.
        $this->assertStringContainsString("[TUF] Target 'drupal/token/1.9.0.0' validated.", $debug);

        // Even though we are searching delegated roles for multiple targets, we should see the TUF metadata
        // loaded from the static cache.
        $this->assertStringContainsString('[TUF] Loading http://localhost:8080/metadata/1.package_metadata.json from static cache.', $debug);
        $this->assertStringContainsString('[TUF] Loading http://localhost:8080/metadata/1.package.json from static cache.', $debug);
        // The metadata should actually be *downloaded* twice -- once while the dependency tree is
        // being solved by Composer, and again when the solved dependencies are actually downloaded
        // (which is done by Composer effectively re-invoking itself, which results in the static
        // cache being reset).
        // @see \Composer\Command\RequireCommand::doUpdate()
        $this->assertStringContainsStringCount('Downloading http://localhost:8080/metadata/1.package_metadata.json', $debug, 2);
        $this->assertStringContainsStringCount('Downloading http://localhost:8080/metadata/1.package.json', $debug, 2);

        $this->assertDirectoryExists("$vendorDir/drupal/token");
        $this->assertDirectoryExists("$vendorDir/drupal/pathauto");

        // Load the locked package to ensure that the TUF information was saved.
        // @see \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions()
        $lock = new JsonFile($this->workingDir . '/composer.lock');
        $this->assertTrue($lock->exists());
        $lock = new FilesystemRepository($lock);

        $transportOptions = $lock->findPackage('drupal/token', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8080', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $transportOptions = $lock->findPackage('drupal/pathauto', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8080', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/pathauto/1.12.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $this->composer(['remove', 'drupal/pathauto', 'drupal/token']);
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/token");
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/pathauto");
    }

    private function assertStringContainsStringCount(string $needle, string $haystack, int $count): void
    {
        $this->assertSame($count, substr_count($haystack, $needle), "Failed asserting that '$needle' appears $count time(s) in '$haystack'.");
    }
}
