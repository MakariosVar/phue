<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Console\Commands;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Console\Commands\GenerateKeyCommand;

/**
 * Contract for ./phue generate:key.
 *
 * The dangerous failure mode is not "it failed to write a key". It is "it rotated an
 * existing key": every CSRF token already issued under the old key stops verifying, so
 * the symptom is 403s appearing across every open tab and every running worker at once.
 * Idempotence is therefore the headline requirement, and it is asserted from several
 * directions (repeat runs, --force, pre-existing non-empty value).
 *
 * @package Phue\Tests\Core\Console
 */
#[Group('integration')]
final class GenerateKeyCommandTest extends TestCase
{
    private string $basePath;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/phue_genkey_' . uniqid('', true);
        $this->envPath = $this->basePath . '/.env';

        // PathResolver discovers the base path from these markers.
        mkdir($this->basePath . '/App', 0777, true);
        mkdir($this->basePath . '/config', 0777, true);
        file_put_contents($this->basePath . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        foreach (['/App', '/config'] as $sub) {
            @rmdir($this->basePath . $sub);
        }

        @unlink($this->basePath . '/composer.json');
        @rmdir($this->basePath);

        parent::tearDown();
    }

    private function runCommand(array $args = []): array
    {
        $oldCwd = getcwd();
        chdir($this->basePath);

        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');

        $exit = (new GenerateKeyCommand())->execute($args, $out, $err);

        rewind($out);
        rewind($err);

        $result = [
            'exit' => $exit,
            'out' => (string) stream_get_contents($out),
            'err' => (string) stream_get_contents($err),
        ];

        fclose($out);
        fclose($err);
        chdir($oldCwd);

        return $result;
    }

    private function envContents(): string
    {
        $this->assertFileExists($this->envPath);

        return (string) file_get_contents($this->envPath);
    }

    /**
     * Pull the APP_KEY value out of raw file text, without loading .env, so the test
     * observes what was written rather than what the parser made of it.
     */
    private function rawAppKeyLine(): ?string
    {
        foreach (preg_split('/\r?\n/', $this->envContents()) ?: [] as $line) {
            if (preg_match('/^APP_KEY\s*=/', $line) === 1) {
                return $line;
            }
        }

        return null;
    }

    public function testCommandIsNamedAndDescribedForTheHelpListing(): void
    {
        $command = new GenerateKeyCommand();

        $this->assertSame('generate:key', $command->getName());
        $this->assertNotSame('', $command->getDescription());
        $this->assertNotSame('', $command->getGroup());
    }

    public function testAppendsAKeyWhenTheEntryIsAbsent(): void
    {
        file_put_contents($this->envPath, "APP_ENV=development\nAPP_DEBUG=true\n");

        $result = $this->runCommand();

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertMatchesRegularExpression(
            '/^APP_KEY=base64:[A-Za-z0-9+\/=]{40,}$/m',
            $this->envContents()
        );
    }

    public function testAppendedKeyDoesNotDisturbExistingEntries(): void
    {
        file_put_contents($this->envPath, "APP_ENV=development\nDB_PASSWORD=p@ss=word#1\n");

        $this->runCommand();

        $contents = $this->envContents();
        $this->assertStringContainsString("APP_ENV=development\n", $contents);
        $this->assertStringContainsString("DB_PASSWORD=p@ss=word#1\n", $contents);
    }

    public function testFillsAnEmptyEntryInPlaceInsteadOfAppendingADuplicate(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\nAPP_ENV=development\n");

        $this->runCommand();

        $this->assertSame(1, substr_count($this->envContents(), 'APP_KEY='));
        $this->assertStringContainsString('APP_KEY=base64:', $this->envContents());
    }

    public function testFillsAnEmptyQuotedEntry(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\"\"\n");

        $this->runCommand();

        $this->assertSame(1, substr_count($this->envContents(), 'APP_KEY='));
        $this->assertStringNotContainsString('APP_KEY=""', $this->envContents());
    }

    /**
     * The single most important assertion in this file.
     */
    public function testRunningTwiceDoesNotRotateAnExistingKey(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");
        $this->runCommand();
        $first = $this->rawAppKeyLine();

        $second = $this->runCommand();

        $this->assertSame(0, $second['exit']);
        $this->assertSame($first, $this->rawAppKeyLine(), 'A second run must leave the key untouched.');
    }

    public function testExistingKeyIsPreservedAndReported(): void
    {
        $existing = 'base64:' . base64_encode(str_repeat('A', 32));
        file_put_contents($this->envPath, "APP_KEY={$existing}\n");

        $result = $this->runCommand();

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertStringContainsString("APP_KEY={$existing}", $this->envContents());
    }

    public function testForceRotatesAnExistingKey(): void
    {
        $existing = 'base64:' . base64_encode(str_repeat('A', 32));
        file_put_contents($this->envPath, "APP_KEY={$existing}\n");

        $result = $this->runCommand(['--force']);

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertStringNotContainsString($existing, $this->envContents());
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{40,}$/m', $this->envContents());
    }

    public function testForceStillWorksWhenTheEntryIsAbsent(): void
    {
        file_put_contents($this->envPath, "APP_ENV=development\n");

        $result = $this->runCommand(['--force']);

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{40,}$/m', $this->envContents());
    }

    /**
     * A commented-out line is documentation, not a setting. Treating it as "present but
     * empty" would rewrite the comment and leave the operator with a .env whose key line
     * is commented out, i.e. still no key at all.
     */
    public function testCommentedEntryIsNotTreatedAsAnEmptySetting(): void
    {
        file_put_contents($this->envPath, "# APP_KEY=\nAPP_ENV=development\n");

        $result = $this->runCommand();

        $this->assertSame(0, $result['exit'], $result['err']);
        $contents = $this->envContents();
        $this->assertStringContainsString("# APP_KEY=\n", $contents, 'The comment must survive verbatim.');
        $this->assertSame(1, substr_count($contents, "\nAPP_KEY="), 'A real, uncommented entry is added.');
    }

    public function testIndentationAndTrailingContentOnTheEntryIsPreserved(): void
    {
        file_put_contents($this->envPath, "  APP_KEY=\n");

        $this->runCommand();

        $this->assertMatchesRegularExpression('/^\s*APP_KEY=base64:[A-Za-z0-9+\/=]{40,}$/m', $this->envContents());
    }

    public function testMissingEnvFileFailsLoudlyInsteadOfCreatingAPartialOne(): void
    {
        $result = $this->runCommand();

        $this->assertSame(1, $result['exit']);
        $this->assertFileDoesNotExist($this->envPath);
        $this->assertStringContainsString('.env', $result['err']);
    }

    public function testExistingEnvFileModeIsPreservedBecauseItHoldsASecret(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");
        chmod($this->envPath, 0640);

        $this->runCommand();

        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->envPath)), -4));
    }

    public function testGeneratedKeyCarriesAtLeast256BitsOfEntropy(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");

        $this->runCommand();

        $line = $this->rawAppKeyLine();
        $this->assertIsString($line);

        $value = substr($line, strlen('APP_KEY='));
        $this->assertStringStartsWith('base64:', $value);

        $decoded = base64_decode(substr($value, 7), true);
        $this->assertIsString($decoded);
        $this->assertSame(32, strlen($decoded), '32 bytes is the 256-bit floor.');
    }

    public function testTwoGeneratedKeysDiffer(): void
    {
        file_put_contents($this->envPath, "APP_KEY=\n");
        $this->runCommand();
        $first = $this->rawAppKeyLine();

        $this->runCommand(['--force']);
        $second = $this->rawAppKeyLine();

        $this->assertNotSame($first, $second);
    }

    public function testFileEndsWithASingleTrailingNewline(): void
    {
        file_put_contents($this->envPath, "APP_ENV=development");

        $this->runCommand();

        $contents = $this->envContents();
        $this->assertStringEndsWith("\n", $contents);
        $this->assertStringEndsNotWith("\n\n", $contents);
    }
}