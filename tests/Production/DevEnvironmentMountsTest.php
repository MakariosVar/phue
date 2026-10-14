<?php

declare(strict_types=1);

namespace Phue\Tests\Production;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The dev environment must serve the developer's own dependencies.
 *
 * The failure this pins is not loud. vendor/ and ssr/node_modules/ were mounted
 * from named volumes, so the container ran a copy of the dependencies that
 * existed only inside Docker: `composer require` on the host changed the host's
 * vendor/, the container kept serving the volume, and the two drifted apart
 * without either side complaining. The visible cost lands on the tools that read
 * the host tree — an IDE resolving a reference into /app/vendor pointed at
 * packages the running application does not load, and the type it offered was
 * from a file that was no longer the one executing.
 *
 * The invariant is therefore about what the compose file does NOT do. Asserting
 * the vendor directory exists, or that composer ran, would pass just as well
 * against the volumes this file used to declare; only the absence of a mount
 * over those two paths distinguishes a shared working tree from a private copy.
 *
 * @package Phue\Tests\Production
 */
#[Group('unit')]
final class DevEnvironmentMountsTest extends TestCase
{
    private string $compose;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 2) . '/docker/dev/docker-compose.yml';

        $this->assertFileExists($path, 'The dev compose file is what this contract is about.');

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->compose = $contents;
    }

    /**
     * Every volume declaration in the file, one per line.
     *
     * @return list<string>
     */
    private function volumeLines(): array
    {
        $lines = preg_split('/\R/', $this->compose) ?: [];

        return array_values(array_filter(array_map(
            static fn(string $line): string => trim($line),
            $lines
        ), static fn(string $line): bool => str_starts_with($line, '- ')));
    }

    public function testDependencyDirectoriesAreNotShadowedByAMount(): void
    {
        $mounts = implode("\n", $this->volumeLines());

        $this->assertStringNotContainsString(
            ':/app/vendor',
            $mounts,
            'A volume over /app/vendor gives the container a dependency tree the IDE never sees.'
        );
        $this->assertStringNotContainsString(
            ':/app/ssr/node_modules',
            $mounts,
            'A volume over /app/ssr/node_modules gives the container a dependency tree the IDE never sees.'
        );
    }

    public function testNoDependencyVolumesAreDeclared(): void
    {
        // The named volumes are gone from the services, so a leftover top-level
        // declaration would only be confusing — and would invite adding the mount
        // back "just to use it".
        $this->assertDoesNotMatchRegularExpression(
            '/^volumes:\s*$/m',
            $this->compose,
            'Dependency volumes no longer exist; nothing may declare them.'
        );
    }

    public function testTheWorkingTreeIsBindMounted(): void
    {
        // Without this the container would run the image's copy of the source, and
        // the two "the vendor is the working tree" claims above would be about a
        // tree nothing is using.
        $this->assertStringContainsString('- ../..:/app', $this->compose);
    }

    public function testHostNetworkIsRetained(): void
    {
        // Not the subject of this change, but it is the other half of "the container
        // behaves like the developer's machine": the suite and the application both
        // reach 127.0.0.1 services, and a bridge network hides them.
        $this->assertStringContainsString('network_mode: host', $this->compose);
    }

    public function testContainerRunsAsTheHostUser(): void
    {
        // vendor/ and node_modules/ are now written by the container during a build.
        // Root-owned files in the developer's working tree break the IDE, the next
        // host-side composer run, and the next build.
        $this->assertStringContainsString('user: "1000:1000"', $this->compose);
    }
}
