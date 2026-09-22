<?php

declare(strict_types=1);

namespace Phue\Tests\Phii\Generator;

use Phue\Modules\Phii\Generator\BackupManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P15/D11 — backup lifecycle: single-nesting under `{root}/backups`, correct
 * relative entries (no double root), listing newest-first, and safe restore
 * that rejects Zip-Slip archives.
 *
 * @package Phue\Tests\Phii\Generator
 */
#[Group('unit')]
final class BackupManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phue_backup_' . uniqid();
        @mkdir($this->root . '/App/Models', 0755, true);
        file_put_contents($this->root . '/App/Models/Profile.php', '<?php // version 1');
        file_put_contents($this->root . '/readme.txt', 'keep me');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
        parent::tearDown();
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testCreateBackupLandsInSingleNestedBackupsDir(): void
    {
        $manager = new BackupManager();
        $zipPath = $manager->createBackup($this->root);

        $this->assertIsString($zipPath);
        $this->assertFileExists($zipPath);
        // P15: never `root/storage/generated/backups` double nesting — exactly `{root}/backups/`.
        $this->assertSame($this->root . '/backups', dirname((string) $zipPath));
        $this->assertStringStartsWith('backup_', basename((string) $zipPath));
        $this->assertStringEndsWith('.zip', (string) $zipPath);
    }

    public function testBackupZipContainsRelativeRootEntriesOnly(): void
    {
        $manager = new BackupManager();
        $zipPath = (string) $manager->createBackup($this->root);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath), 'Backup archive must be readable');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        // Entries are relative to the output root and never duplicate the root name.
        $this->assertContains('App/Models/Profile.php', $names, 'Backup must mirror the tree relative to the root');
        $this->assertContains('readme.txt', $names);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('backups/', $name, 'The backups dir itself must never be archived');
            $this->assertDoesNotMatchRegularExpression('#' . preg_quote(basename($this->root), '#') . '/#', $name, 'No double-nested root entry allowed');
        }
    }

    public function testListBackupsReturnsNewestFirst(): void
    {
        $manager = new BackupManager();
        $first = (string) $manager->createBackup($this->root);
        sleep(1);
        $second = (string) $manager->createBackup($this->root);

        $backups = $manager->listBackups($this->root);

        $this->assertCount(2, $backups);
        $this->assertSame(basename($second), $backups[0]['filename'], 'Newest backup must sort first');
        $this->assertSame(basename($first), $backups[1]['filename']);
        $this->assertFileExists($backups[0]['path']);
    }

    public function testRestoreRecreatesTreeFromBackup(): void
    {
        $manager = new BackupManager();
        $zipPath = (string) $manager->createBackup($this->root);

        // Corrupt the live tree, then restore.
        unlink($this->root . '/App/Models/Profile.php');
        file_put_contents($this->root . '/readme.txt', 'tampered');

        $this->assertTrue($manager->restore($zipPath, $this->root));

        $this->assertSame('<?php // version 1', (string) file_get_contents($this->root . '/App/Models/Profile.php'));
        $this->assertSame('keep me', (string) file_get_contents($this->root . '/readme.txt'));
    }

    public function testRestoreRejectsZipSlipEntries(): void
    {
        $zipPath = $this->root . '/evil.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE));
        $zip->addFromString('../escaped.txt', 'pwned');
        $zip->close();

        $manager = new BackupManager();
        $targetDir = $this->root . '/restore-target';
        @mkdir($targetDir, 0755, true);

        try {
            $manager->restore($zipPath, $targetDir);
            $this->fail('Zip-Slip entries must be rejected, not extracted.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('traversal', strtolower($e->getMessage()));
        }
        $this->assertFileDoesNotExist(dirname($targetDir) . '/escaped.txt');
    }

    public function testCreateBackupReturnsNullWhenRootMissing(): void
    {
        $this->assertNull((new BackupManager())->createBackup($this->root . '/does-not-exist'));
    }
}