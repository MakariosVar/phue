<?php

declare(strict_types=1);

namespace Phue\Tests\Support;

/**
 * Runs a script under the CGI SAPI so that Response::send() really emits headers.
 *
 * The CLI SAPI discards header() calls and headers_list() stays empty, so the one
 * behaviour this framework cannot otherwise observe is whether two Set-Cookie lines
 * both reach the client. PHP's header() replaces by header NAME unless replace is
 * false, so the "emit each cookie separately" fix is invisible from the CLI and would
 * otherwise regress silently into "only the last cookie is ever sent".
 *
 * php-cgi writes response headers to stdout ahead of the body, which is exactly what
 * a browser would receive, so the assertion runs against real output rather than a
 * restatement of the implementation.
 */
final class CgiResponseProbe
{
    /**
     * Build the probe script for a given project root.
     *
     * The root is embedded rather than read from $argv: the CGI SAPI does not populate
     * $argv unless arguments follow the script name, so a script that expects one dies
     * before it can report why.
     *
     * @param string $baseDir Project root that holds vendor/autoload.php
     * @return string
     */
    private static function scriptFor(string $baseDir): string
    {
        $autoload = var_export($baseDir . '/vendor/autoload.php', true);

        return <<<PHP
            <?php
            require {$autoload};

            \$response = new \\Phue\\Core\\Response('body', 200, ['Content-Type' => 'text/plain']);
            \$response->withCookie(\\Phue\\Core\\Security\\Cookie::create('first', 'one', sameSite: 'Lax'));
            \$response->withCookie(\\Phue\\Core\\Security\\Cookie::create('second', 'two', secure: true, httpOnly: true));
            \$response->send();
            PHP;
    }

    /**
     * Execute the probe and return the raw CGI stdout.
     *
     * @param string $baseDir Project root that holds vendor/autoload.php
     * @return string
     */
    public static function run(string $baseDir): string
    {
        $script = tempnam(sys_get_temp_dir(), 'phue_cgi_probe_');

        if ($script === false) {
            throw new \RuntimeException('Unable to allocate a temp script for the CGI probe.');
        }

        file_put_contents($script, self::scriptFor($baseDir));

        try {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            // This php-cgi build identifies the script through the CGI environment, and
            // neither -q nor -f may be used: -q suppresses header output (the thing being
            // observed) and -f implies it. Passing $env wholesale would strip PATH and
            // break the php-cgi lookup, so the inherited environment is merged instead.
            $env = array_merge(
                getenv(),
                [
                    'REDIRECT_STATUS' => '1',
                    'SCRIPT_FILENAME' => $script,
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/probe',
                ]
            );

            $process = proc_open(
                ['php-cgi'],
                $descriptors,
                $pipes,
                $baseDir,
                $env
            );

            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to start php-cgi for the response probe.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            if ($exit !== 0) {
                throw new \RuntimeException(sprintf(
                    'php-cgi probe exited with %d. stdout: %s stderr: %s',
                    $exit,
                    $stdout,
                    $stderr
                ));
            }

            if (!str_contains((string) $stdout, 'Set-Cookie')) {
                throw new \RuntimeException('php-cgi probe emitted no Set-Cookie header. stdout: ' . $stdout);
            }

            return (string) $stdout;
        } finally {
            @unlink($script);
        }
    }

    /**
     * Split CGI stdout into its Set-Cookie lines, preserving duplicates.
     *
     * Keeping duplicates matters: a probe that collapsed repeats would hide exactly
     * the bug it exists to catch.
     *
     * @param string $cgiOutput Raw CGI stdout
     * @return list<string>
     */
    public static function setCookieLines(string $cgiOutput): array
    {
        $headerBlock = strstr($cgiOutput, "\r\n\r\n", true);

        if ($headerBlock === false) {
            $headerBlock = strstr($cgiOutput, "\n\n", true) ?: '';
        }

        $lines = preg_split('/\r?\n/', $headerBlock) ?: [];

        $cookies = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (stripos($line, 'set-cookie:') === 0) {
                $cookies[] = trim(substr($line, strlen('set-cookie:')));
            }
        }

        return $cookies;
    }
}