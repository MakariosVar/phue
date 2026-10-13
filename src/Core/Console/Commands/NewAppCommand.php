<?php

declare(strict_types=1);

namespace Phue\Core\Console\Commands;

use Phue\Core\Console\CommandInterface;

/**
 * Interactive scaffolder for a new Phue application.
 *
 * The installer this replaces copied files. Copying is what made it rot: a
 * project received a snapshot of the framework taken at one moment, so every
 * framework release afterwards was invisible to it, and two projects created
 * from the same template were never the same twice. This command writes instead
 * — the module list into config/app.php, and the selected modules' own config
 * files out of the installed package — so an application tracks the framework
 * it is installed against, by construction.
 *
 * The screen is the one from the installer, unchanged in what the user sees:
 * a list, arrows to move, enter to choose. What changed is underneath, and it
 * is worth stating because it is why this is a class and not a script. The
 * installer drew frames by counting the lines it had printed and moving the
 * cursor back up that many, in bash, with the whole thing untestable without a
 * pty harness in another language. Here the arithmetic is three methods —
 * fit(), bodyRows(), scrollWindow() — each a pure function over numbers, and
 * they are tested as such.
 *
 * @package Phue\Core\Console\Commands
 */
final class NewAppCommand implements CommandInterface
{
    /**
     * Command group shown in the console listing.
     *
     * @var string
     */
    private string $group = 'Phue';

    /**
     * Terminal width assumed when the real one cannot be measured.
     *
     * @var int
     */
    private const FALLBACK_COLUMNS = 80;

    /**
     * Terminal height assumed when the real one cannot be measured.
     *
     * @var int
     */
    private const FALLBACK_ROWS = 24;

    /**
     * Narrower than this and the layout stops being legible.
     *
     * @var int
     */
    private const MIN_COLUMNS = 60;

    /**
     * The module every Phue application gets.
     *
     * Not an option, and not a default that can be unticked: the Swoole adapter
     * is what `serve` runs and what the WebSocket transport is built on. A
     * project without it is not a smaller Phue project, it is a Phue project
     * that cannot serve, and the user finds that out from a fatal at run time
     * rather than from the thing that created it.
     *
     * @var string
     */
    private const MANDATORY_MODULE = 'server';

    /**
     * Row the first frame is drawn on, empty when the terminal did not say.
     *
     * @var string
     */
    private string $anchorRow = '';

    /**
     * Lines the previous frame printed, reset by home().
     *
     * @var int
     */
    private int $frameLines = 0;

    /**
     * First visible row of a list that is taller than the window.
     *
     * @var int
     */
    private int $viewFrom = 0;

    /**
     * Terminal settings captured before raw mode, restored on every exit path.
     *
     * @var string
     */
    private string $savedTerminal = '';

    /**
     * Terminal width in columns.
     *
     * @var int
     */
    private int $columns = self::FALLBACK_COLUMNS;

    /**
     * Terminal height in rows.
     *
     * @var int
     */
    private int $rows = self::FALLBACK_ROWS;

    /**
     * Raw mode and the hidden cursor are active.
     *
     * @var bool
     */
    private bool $interactive = false;

    /**
     * Retrieves command group name.
     *
     * @return string Console group.
     */
    public function getGroup(): string
    {
        return $this->group;
    }

    /**
     * Retrieves command identifier name.
     *
     * @return string Command name.
     */
    public function getName(): string
    {
        return 'new';
    }

    /**
     * Retrieves command description.
     *
     * @return string Command description.
     */
    public function getDescription(): string
    {
        return 'Scaffold a new Phue application: pick the modules, write config/app.php and their configs.';
    }

    /**
     * Executes the application scaffolding command.
     *
     * @param array<int, string> $args Positional arguments and --flags.
     * @param mixed $outputStream Output stream resource.
     * @param mixed $errorStream Error stream resource.
     * @return int Exit code: 0 on success, 1 on failure.
     */
    public function execute(array $args, $outputStream = \STDOUT, $errorStream = \STDERR): int
    {
        $basePath = $this->basePath($args);
        $target = $this->targetDirectory($args);

        if ($target === null) {
            fwrite($errorStream, "Error: Missing project directory. Usage: ./phue new <directory> [--modules=a,b]" . PHP_EOL);

            return 1;
        }

        if (!$this->isValidName(basename($target))) {
            fwrite($errorStream, "Error: '" . basename($target) . "' is not a usable project name." . PHP_EOL);

            return 1;
        }

        if ($this->targetExists($target) && !$this->hasFlag($args, '--force')) {
            fwrite($errorStream, "Error: '$target' already exists. Use --force to write into it." . PHP_EOL);

            return 1;
        }

        $selection = $this->chooseModules($basePath, $args, $outputStream, $errorStream);

        if ($selection === null) {
            return 1;
        }

        return $this->write($basePath, $target, $selection, $this->hasFlag($args, '--force'), $outputStream, $errorStream);
    }

    /**
     * Where the framework is installed, which is where module configs come from.
     *
     * @param array<int, string> $args
     */
    private function basePath(array $args): string
    {
        $flag = $this->flagValue($args, '--framework=');

        if ($flag !== null && is_dir($flag)) {
            return rtrim($flag, '/');
        }

        // This command lives in src/Core/Console/Commands inside the package,
        // so walking up four levels lands on the package root — whether that
        // is the framework repository or vendor/makarios-var/phue.
        return dirname(__DIR__, 4);
    }

    /**
     * @param array<int, string> $args
     */
    private function targetDirectory(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '-')) {
                continue;
            }

            return rtrim($arg, '/');
        }

        return null;
    }

    /**
     * @param array<int, string> $args
     * @param string $name
     */
    private function hasFlag(array $args, string $name): bool
    {
        return in_array($name, $args, true);
    }

    /**
     * @param array<int, string> $args
     */
    private function flagValue(array $args, string $prefix): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) {
                $value = trim(substr($arg, strlen($prefix)));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    private function targetExists(string $target): bool
    {
        return file_exists($target) && !(is_dir($target) && $this->isEmptyDirectory($target));
    }

    private function isEmptyDirectory(string $path): bool
    {
        $entries = scandir($path);

        return $entries === false || count(array_diff($entries, ['.', '..'])) === 0;
    }

    private function isValidName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1;
    }

    // ---------------------------------------------------------------- modules

    /**
     * Every module the installed package ships, as id => one-line summary.
     *
     * Read from the package rather than kept in a table here, so a module added
     * to the framework is installable without editing this file. That was the
     * original installer's rule and it is worth keeping: a hardcoded list is a
     * list that is wrong the day someone adds a module.
     *
     * @return array<string, string>
     */
    private function availableModules(string $basePath): array
    {
        $modulesDir = $basePath . '/src/Modules';
        $modules = [];

        if (!is_dir($modulesDir)) {
            return $modules;
        }

        foreach (scandir($modulesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($modulesDir . '/' . $entry)) {
                continue;
            }

            $classFile = null;

            foreach (glob($modulesDir . '/' . $entry . '/*.php') ?: [] as $candidate) {
                $code = (string) file_get_contents($candidate);

                if (preg_match('/class\s+\w*Module\b/', $code) === 1) {
                    $classFile = $code;
                    break;
                }
            }

            if ($classFile === null) {
                continue;
            }

            $id = $entry;

            if (preg_match('/public\s+string\s+\$id\s*=\s*\'([^\']*)\'/', $classFile, $m) === 1) {
                $id = $m[1];
            }

            $modules[$id] = $this->summary($classFile) ?: $id;
        }

        ksort($modules);

        return $modules;
    }

    /**
     * First sentence of a class docblock, which is where this codebase keeps them.
     */
    private function summary(string $code): string
    {
        if (preg_match('/\/\*\*(.*?)\*\//s', $code, $m) !== 1) {
            return '';
        }

        $lines = [];

        foreach (explode("\n", $m[1]) as $line) {
            $line = trim(preg_replace('#^\s*\*\s?#', '', $line) ?? '');

            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines[0] ?? '';
    }

    /**
     * The config file a module owns, if it has one.
     *
     * Matched by module id, falling back to the directory name — the same two
     * candidates the installer's scanner used, because a module called
     * HttpSigning owning rate-limit.php is a real case and not a typo.
     */
    private function moduleConfig(string $basePath, string $id, string $dir): ?string
    {
        foreach ([$id, $dir] as $candidate) {
            $path = $basePath . '/config/' . $candidate . '.php';

            if (is_file($path)) {
                return 'config/' . $candidate . '.php';
            }
        }

        return null;
    }

    // ------------------------------------------------------------- selection

    /**
     * @param array<int, string> $args
     * @param mixed $outputStream
     * @param mixed $errorStream
     * @return array<int, string>|null Null means the user cancelled.
     */
    private function chooseModules(string $basePath, array $args, $outputStream, $errorStream): ?array
    {
        $available = $this->availableModules($basePath);

        if ($available === []) {
            fwrite($errorStream, "Error: no modules found under $basePath/src/Modules" . PHP_EOL);

            return null;
        }

        $flag = $this->flagValue($args, '--modules=');

        if ($flag !== null) {
            return $this->resolveList($flag, $available, $errorStream);
        }

        if (!$this->isInteractive()) {
            // Not a terminal: take every module rather than asking a question
            // nobody is there to answer. A scaffolder that exits silently in a
            // pipeline is worse than one that over-delivers.
            return array_merge([self::MANDATORY_MODULE], array_keys($available));
        }

        return $this->interactiveSelect($available, $outputStream);
    }

    /**
     * @param array<string, string> $available id => summary
     * @param mixed $errorStream
     * @return array<int, string>|null
     */
    private function resolveList(string $list, array $available, $errorStream): ?array
    {
        $ids = [];

        foreach (preg_split('/[,\s]+/', $list) ?: [] as $raw) {
            if ($raw === '') {
                continue;
            }

            // Matched against the id, not looked up as an array key. $available is
            // id => summary, so a case-insensitive lookup would hand back the
            // summary — and then write that sentence into config/app.php as if
            // it were a module class.
            $resolved = null;

            foreach (array_keys($available) as $id) {
                if (strtolower($id) === strtolower($raw)) {
                    $resolved = $id;
                    break;
                }
            }

            if ($resolved === null) {
                fwrite($errorStream, "Error: unknown module '$raw'. Available: " . implode(', ', array_keys($available)) . PHP_EOL);

                return null;
            }

            $ids[] = $resolved;
        }

        return $this->withMandatory($ids);
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, string>
     */
    private function withMandatory(array $ids): array
    {
        if (!in_array(self::MANDATORY_MODULE, $ids, true)) {
            array_unshift($ids, self::MANDATORY_MODULE);
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------- tui

    private function isInteractive(): bool
    {
        return function_exists('stream_isatty') ? @stream_isatty(\STDIN) : false;
    }

    /**
     * @param array<string, string> $available
     * @param mixed $outputStream
     * @return array<int, string>|null Null means the user cancelled.
     */
    private function interactiveSelect(array $available, $outputStream): ?array
    {
        $ids = array_keys($available);
        $flags = array_fill(0, count($ids), false);
        $mandatoryIndex = (int) array_search(self::MANDATORY_MODULE, $ids, true);

        if ($mandatoryIndex >= 0) {
            $flags[$mandatoryIndex] = true;
        }

        $cursor = 0;
        $last = count($ids) - 1;

        $this->begin();

        try {
            while (true) {
                $this->home();
                $this->draw($ids, $available, $flags, $cursor, $outputStream);

                $key = $this->readKey();

                if ($key === 'INTERRUPT' || $key === 'q' || $key === 'ESC' || $key === 'EOF') {
                    return null;
                }

                switch ($key) {
                    case 'UP':
                        $cursor = $cursor === 0 ? $last : $cursor - 1;
                        break;
                    case 'DOWN':
                        $cursor = $cursor === $last ? 0 : $cursor + 1;
                        break;
                    case 'SPACE':
                        // The mandatory module is pre-ticked and ignores this,
                        // because a checkbox that looks like the others and does
                        // not respond reads as a broken menu rather than as a
                        // locked choice.
                        if ($ids[$cursor] !== self::MANDATORY_MODULE) {
                            $flags[$cursor] = !$flags[$cursor];
                        }
                        break;
                    case 'a':
                        foreach ($ids as $index => $id) {
                            $flags[$index] = $id !== self::MANDATORY_MODULE;
                        }
                        break;
                    case 'n':
                        foreach ($ids as $index => $id) {
                            $flags[$index] = $id === self::MANDATORY_MODULE;
                        }
                        break;
                    case 'ENTER':
                        $chosen = [];

                        foreach ($ids as $index => $id) {
                            if ($flags[$index]) {
                                $chosen[] = $id;
                            }
                        }

                        return $this->withMandatory($chosen);
                }
            }
        } finally {
            // Every exit path — cancel, ESC, EOF — restores the terminal. A
            // shell left with echo off is a shell the user has to fix by hand.
            $this->end();
        }
    }

    /**
     * @param array<int, string> $ids
     * @param array<string, string> $available
     * @param array<int, bool> $flags
     * @param mixed $outputStream
     */
    private function draw(array $ids, array $available, array $flags, int $cursor, $outputStream): void
    {
        $this->line($outputStream, '');
        $this->line($outputStream, "  Modules  pick what this project needs");
        $this->line($outputStream, '  ' . $this->dim($this->fit(
            count($ids) . ' available · space toggles · a all · n none',
            $this->columns - 2
        )));
        $this->line($outputStream, '');

        $perScreen = intdiv($this->bodyRows(), 2);
        $this->scrollWindow(count($ids), $perScreen, $cursor);

        for ($index = $this->viewFrom; $index < count($ids) && $index < $this->viewFrom + $perScreen; $index++) {
            $id = $ids[$index];
            $locked = $id === self::MANDATORY_MODULE;
            $box = $flags[$index] ? $this->green('◼') : $this->dim('◻');
            $lock = $flags[$index] && $locked ? ' ' . $this->dim('always') : '';
            $label = $this->fit($id, $this->columns - 16);
            $label = $index === $cursor ? $this->bold($label) : $label;
            $pointer = $index === $cursor ? $this->cyan('❯') : ' ';

            $this->line($outputStream, "  {$pointer}{$box} {$label}{$lock}");
            $this->line($outputStream, '       ' . $this->dim($this->fit($available[$id], $this->columns - 7)));
        }

        $this->line($outputStream, '');
        $this->rule($outputStream, 'space toggle · a all · n none · q quit');
    }

    /**
     * Shorten text to a width, marking that it was cut.
     *
     * Wrapping is what makes an over-long frame unrepairable rather than
     * merely ugly: the frame counter counts the lines the script *thinks* it
     * printed, so a description that wraps costs two rows on the terminal while
     * counting as one, the redraw erases a row short, and the menu degrades
     * into a column of fragments as the cursor moves. Cut it here instead.
     */
    public function fit(string $text, int $width): string
    {
        $width = max($width, 8);

        if (mb_strlen($text) <= $width) {
            return $text;
        }

        return mb_substr($text, 0, $width - 1) . '…';
    }

    /**
     * Rows available to a list once the frame's own chrome has taken its share.
     *
     * Heading, blank, hint, blank, trailing blank, bar, label: nine.
     */
    public function bodyRows(): int
    {
        return max($this->rows - 9, 2);
    }

    /**
     * Move the window so the cursor row is inside it.
     *
     * Both ends are handled, because a list scrolled to the bottom with the
     * cursor above the fold is a menu the user cannot leave: pressing up moves
     * the highlight and not the list, so nothing appears to happen.
     *
     * @param int $total Rows in the list.
     * @param int $visible Rows that fit.
     * @param int $cursor Current row.
     */
    public function scrollWindow(int $total, int $visible, int $cursor): void
    {
        $visible = max($visible, 1);
        $maxFrom = max($total - $visible, 0);

        if ($cursor < $this->viewFrom) {
            $this->viewFrom = $cursor;
        } elseif ($cursor >= $this->viewFrom + $visible) {
            $this->viewFrom = $cursor - $visible + 1;
        }

        $this->viewFrom = min(max($this->viewFrom, 0), $maxFrom);
    }

    /**
     * First visible row, so a caller that draws can assert on it.
     */
    public function viewFrom(): int
    {
        return $this->viewFrom;
    }

    // -------------------------------------------------------------- terminal

    private function begin(): void
    {
        $this->columns = $this->measureColumns();
        $this->rows = $this->measureRows();
        $this->savedTerminal = (string) @shell_exec('stty -g 2>/dev/null');
        @shell_exec('stty -echo -icanon min 1 time 0 2>/dev/null');
        $this->anchorRow = $this->anchor();
        $this->frameLines = 0;
        $this->viewFrom = 0;
        $this->interactive = true;
        print "\033[?25l";
    }

    private function end(): void
    {
        if (!$this->interactive) {
            return;
        }

        $this->interactive = false;

        // Erase the frame before handing the terminal back. The menu was drawn
        // with the cursor hidden, so whatever was last painted stays painted;
        // without this the shell prompt lands in the middle of a half-drawn menu
        // and the terminal reads as broken even though every mode was restored.
        $this->home();
        print "\033[?25h\n";

        if ($this->savedTerminal !== '') {
            @shell_exec('stty ' . escapeshellarg($this->savedTerminal) . ' 2>/dev/null');
        }
    }

    /**
     * The row the first frame will start on, asked of the terminal once.
     *
     * A terminal that does not answer leaves the fallback: move up by the number
     * of lines the last frame printed.
     */
    private function anchor(): string
    {
        print "\033[6n";

        $reply = '';

        while (($char = fgetc(\STDIN)) !== false) {
            $reply .= $char;

            if ($char === 'R') {
                break;
            }
        }

        if (preg_match('/\[(\d+);\d+R/', $reply, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * Erase the previous frame and reset the line count.
     *
     * The reset is the part that is easy to get wrong: left accumulating, the
     * count becomes the total of every frame drawn rather than the height of
     * the last one, so the fallback moves twice as far on the second frame and
     * three times as far on the third — the menu climbs off the top of the
     * screen and each redraw appears somewhere new.
     */
    private function home(): void
    {
        if ($this->anchorRow !== '') {
            print "\033[{$this->anchorRow};1H\033[J";
        } elseif ($this->frameLines > 0) {
            print "\033[{$this->frameLines}A\r\033[J";
        } else {
            print "\r\033[J";
        }

        $this->frameLines = 0;
    }

    /**
     * @param mixed $outputStream
     */
    private function line($outputStream, string $text = ''): void
    {
        fwrite($outputStream, $text . "\n");
        $this->frameLines++;
    }

    /**
     * @param mixed $outputStream
     */
    private function rule($outputStream, string $label): void
    {
        fwrite($outputStream, '  ' . $this->dim(str_repeat('─', max($this->columns - 4, 8))) . "\n");
        $this->frameLines++;
        fwrite($outputStream, '  ' . $this->dim($this->fit($label, $this->columns - 2)) . "\n");
        $this->frameLines++;
    }

    private function readKey(): string
    {
        $key = fgetc(\STDIN);

        if ($key === false) {
            return 'EOF';
        }

        // A newline arrives as a carriage return and ICRNL turns it into "\n",
        // which read-style helpers report as an empty string rather than data.
        if ($key === '' || $key === "\n") {
            return 'ENTER';
        }

        if ($key === "\x03") {
            return 'INTERRUPT';
        }

        if ($key === "\x1b") {
            // ESC is both "quit" and the start of every arrow key, so it is
            // resolved by whether a byte follows it within the timeout.
            stream_set_blocking(\STDIN, false);
            $next = fread(\STDIN, 2);
            stream_set_blocking(\STDIN, true);
            $next = (string) $next;

            return match (true) {
                str_starts_with($next, '[A') => 'UP',
                str_starts_with($next, '[B') => 'DOWN',
                default => 'ESC',
            };
        }

        return match ($key) {
            ' ' => 'SPACE',
            "\r" => 'ENTER',
            'a', 'A' => 'a',
            'n', 'N' => 'n',
            'q', 'Q' => 'q',
            default => $key,
        };
    }

    private function measureColumns(): int
    {
        $value = getenv('COLUMNS');

        if ($value === false || !ctype_digit((string) $value)) {
            $value = (string) @shell_exec('tput cols 2>/dev/null');
        }

        $columns = ctype_digit(trim((string) $value)) ? (int) trim((string) $value) : self::FALLBACK_COLUMNS;

        return max($columns, self::MIN_COLUMNS);
    }

    private function measureRows(): int
    {
        $value = getenv('LINES');

        if ($value === false || !ctype_digit((string) $value)) {
            $value = (string) @shell_exec('tput lines 2>/dev/null');
        }

        $rows = ctype_digit(trim((string) $value)) ? (int) trim((string) $value) : self::FALLBACK_ROWS;

        return max($rows, 10);
    }

    private function bold(string $text): string
    {
        return "\033[1m{$text}\033[0m";
    }

    private function dim(string $text): string
    {
        return "\033[2m{$text}\033[0m";
    }

    private function green(string $text): string
    {
        return "\033[32m{$text}\033[0m";
    }

    private function cyan(string $text): string
    {
        return "\033[36m{$text}\033[0m";
    }

    // ----------------------------------------------------------------- write

    /**
     * @param array<int, string> $selection
     * @param mixed $outputStream
     * @param mixed $errorStream
     */
    private function write(string $basePath, string $target, array $selection, bool $force, $outputStream, $errorStream): int
    {
        if (!$this->mkdir($target)) {
            fwrite($errorStream, "Error: could not create '$target'." . PHP_EOL);

            return 1;
        }

        $moduleList = [];

        foreach ($selection as $id) {
            $moduleList[] = $this->moduleClass($basePath, $id);
        }

        $appConfig = $target . '/config/app.php';

        if (is_file($appConfig) && !$force) {
            // config/app.php is the one file that already exists in an app, and
            // rewriting it wholesale would drop the modules it already lists —
            // which is how a project loses Auth by re-running its scaffolder.
            $existing = (string) file_get_contents($appConfig);
            $missing = array_values(array_filter(
                $moduleList,
                static fn(string $class): bool => !str_contains($existing, $class)
            ));

            if ($missing === []) {
                fwrite($outputStream, '  config/app.php already lists every selected module.' . PHP_EOL);
            } else {
                $this->appendModules($appConfig, $missing);

                foreach ($missing as $class) {
                    fwrite($outputStream, '  + ' . $this->green($class) . ' added to config/app.php' . PHP_EOL);
                }
            }
        } else {
            $this->writeFile($appConfig, $this->renderAppConfig($moduleList));
            fwrite($outputStream, '  config/app.php written — ' . count($moduleList) . ' modules.' . PHP_EOL);
        }

        foreach ($selection as $id) {
            $relative = $this->moduleConfig($basePath, $id, $id);

            if ($relative === null) {
                fwrite($outputStream, '  - ' . $this->dim($id) . ' has no config file; nothing to copy.' . PHP_EOL);

                continue;
            }

            $destination = $target . '/' . $relative;

            if (is_file($destination)) {
                continue;
            }

            $this->writeFile($destination, (string) file_get_contents($basePath . '/' . $relative));
            fwrite($outputStream, '  ✓ ' . $this->green($relative) . PHP_EOL);
        }

        fwrite($outputStream, PHP_EOL . '  ' . $this->bold('Created ') . $target . ' — ' . implode(', ', $selection) . PHP_EOL);
        fwrite($outputStream, PHP_EOL . '  Next: composer install, then ./phue serve' . PHP_EOL);

        return 0;
    }

    private function moduleClass(string $basePath, string $id): string
    {
        foreach (glob($basePath . '/src/Modules/*/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);

            if (preg_match('/public\s+string\s+\$id\s*=\s*\'([^\']*)\'/', $code, $m) !== 1 || $m[1] !== $id) {
                continue;
            }

            if (preg_match('/^namespace\s+([^;]+);/m', $code, $n) === 1) {
                return trim($n[1]) . '\\' . basename($file, '.php');
            }
        }

        return 'Phue\\Modules\\' . ucfirst($id) . '\\' . ucfirst($id) . 'Module';
    }

    /**
     * @param array<int, string> $classes
     */
    private function renderAppConfig(array $classes): string
    {
        $entries = '';

        foreach ($classes as $class) {
            $entries .= '        \\' . $class . "::class,\n";
        }

        if ($entries === '') {
            $entries = "        // No modules selected yet.\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        use function Phue\\Core\\env;

        return [
            'name'     => env('APP_NAME', 'Phue App'),
            'env'      => env('APP_ENV', 'development'),
            'debug'    => env('APP_DEBUG', true),
            'appKey'   => env('APP_KEY'),
            'trustedProxies' => [],
            'modules' => [
        {$entries}    ],
        ];

        PHP;
    }

    /**
     * @param array<int, string> $classes
     */
    private function appendModules(string $path, array $classes): void
    {
        $contents = (string) file_get_contents($path);
        $entries = '';

        foreach ($classes as $class) {
            $entries .= '        \\' . $class . "::class,\n";
        }

        // Insert before the closing bracket of the 'modules' array. Anchored on
        // the last module line rather than a brace count so a config that has
        // other arrays after this one is not spliced in the wrong place.
        $updated = preg_replace(
            '/(\n\s*\]\s*,\s*\n\s*\];)/',
            "\n{$entries}    ],\n];",
            $contents,
            1,
            $count
        );

        if ($updated !== null && $count === 1) {
            $this->writeFile($path, $updated);

            return;
        }

        fwrite(\STDERR, '  - could not locate the modules array in ' . basename($path) . '; not modified.' . PHP_EOL);
    }

    private function mkdir(string $path): bool
    {
        return is_dir($path) || mkdir($path, 0777, true) || is_dir($path);
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
