<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;
use Phue\Core\Console\PathResolver;

/**
 * CLI Command to generate the application secret (APP_KEY).
 *
 * APP_KEY signs CSRF tokens. A missing or empty value is filled in automatically; an
 * existing one is never touched unless --force is passed. That asymmetry is deliberate:
 * silently rotating would invalidate every token already handed out, which presents as
 * a burst of unrelated 403s rather than as a key change.
 *
 * Usage:
 *   ./phue generate:key            # writes the key only when absent or empty
 *   ./phue generate:key --force    # rotates an existing key
 *
 * @package Phue\Core\Console\Commands
 */
final class GenerateKeyCommand implements CommandInterface
{
    /**
     * Number of random bytes behind the key. 32 bytes is the 256-bit floor; the value is
     * emitted base64-encoded with a "base64:" prefix so it is self-describing.
     *
     * @var int
     */
    private const KEY_BYTES = 32;

    /**
     * @var string
     */
    protected string $group = 'Build';

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getName(): string
    {
        return 'generate:key';
    }

    public function getDescription(): string
    {
        return 'Generates APP_KEY in .env when missing or empty (use --force to rotate).';
    }

    /**
     * @param array<int, string> $args
     * @param resource           $outputStream
     * @param resource           $errorStream
     * @return int
     */
    public function execute(array $args = [], $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $force = in_array('--force', $args, true);
        $envPath = PathResolver::resolveBasePath() . '/.env';

        if (!is_file($envPath)) {
            // Not creating one: a .env holding nothing but a key would look like a
            // configured environment while silently dropping every other setting.
            fwrite($errorStream, sprintf(
                "\033[31mError: no .env file found at %s\033[0m\n"
                    . "Copy .env.example to .env first, then re-run this command.\n",
                $envPath
            ));

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($envPath);
        $existing = self::currentKey($contents);

        if ($existing !== null && $existing !== '' && !$force) {
            fwrite($outputStream, "  ! APP_KEY already set, leaving it untouched (use --force to rotate)\n");

            return self::SUCCESS;
        }

        $key = 'base64:' . base64_encode(random_bytes(self::KEY_BYTES));
        $updated = self::withKey($contents, $key);

        // Keep the existing mode: this file holds a secret, so it should not have become
        // world-readable, but tightening the operator's own choice is not this command's job.
        $permissions = file_exists($envPath) ? (fileperms($envPath) & 0777) : 0600;

        if (file_put_contents($envPath, $updated, LOCK_EX) === false) {
            fwrite($errorStream, "\033[31mError: could not write {$envPath}\033[0m\n");

            return self::FAILURE;
        }

        @chmod($envPath, $permissions);

        if ($existing !== null && $existing !== '') {
            fwrite($outputStream, "  ✓ APP_KEY rotated\n");
            fwrite(
                $outputStream,
                "    \033[33mAny token signed with the previous key is now invalid.\033[0m\n"
            );
        } else {
            fwrite($outputStream, "  ✓ APP_KEY generated\n");
        }

        return self::SUCCESS;
    }

    /**
     * Read the value of the uncommented APP_KEY entry, or null when there is none.
     *
     * A commented line is documentation, not a setting: treating "# APP_KEY=" as an empty
     * key would rewrite the comment and leave the operator with no key at all.
     *
     * @param string $contents Raw .env text
     * @return string|null
     */
    private static function currentKey(string $contents): ?string
    {
        $line = self::entryLine($contents);

        if ($line === null) {
            return null;
        }

        $value = substr($line, strpos($line, '=') + 1);
        $value = trim($value);

        if (strlen($value) >= 2 && (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        return trim($value);
    }

    /**
     * Return the uncommented APP_KEY line, verbatim, or null when absent.
     *
     * @param string $contents Raw .env text
     * @return string|null
     */
    private static function entryLine(string $contents): ?string
    {
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*APP_KEY\s*=/', $line) === 1) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Return the .env text with APP_KEY set to the given value.
     *
     * The line is rewritten in place when it exists, keeping its indentation; otherwise it
     * is appended. Every other byte of the file, comments included, is preserved: this is a
     * text edit on a file people hand-maintain, not a parse-and-reserialise.
     *
     * @param string $contents Raw .env text
     * @param string $key Fully formed key value, including the base64: prefix
     * @return string
     */
    public static function withKey(string $contents, string $key): string
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)APP_KEY\s*=.*$/', $line, $matches) !== 1) {
                continue;
            }

            $lines[$index] = $matches[1] . 'APP_KEY=' . $key;

            return implode("\n", $lines);
        }

        if ($contents !== '' && !str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        return $contents . 'APP_KEY=' . $key . "\n";
    }
}