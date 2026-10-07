<?php

declare(strict_types=1);

namespace Phue\Core\Http\Middleware;

use Phue\Core\Debug\AsyncDumper;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Container;

/**
 * Middleware that flushes collected dumps at the end of the request.
 *
 * Injects dumps into HTML responses (for Dev Toolbar), JSON responses,
 * or outputs to stdout for CLI.
 *
 * @package Phue\Core\Http\Middleware
 */
final class DumperFlushMiddleware
{
    public function __construct(
        private Container $container
    ) {}

    /**
     * Handles the request and injects dumps into the response.
     *
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function __invoke(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Get the CURRENT request's AsyncDumper (scoped, not the one from middleware creation)
        $dumper = $this->container->get(\Phue\Core\Debug\AsyncDumper::class);
        $dumps = $dumper->getDumps();

        if ($dumps === []) {
            return $response;
        }

        // CLI: output to stderr
        // CLI: output to stderr (but not for Swoole/web requests)
        if (php_sapi_name() === 'cli' && !($request instanceof \Phue\Core\Request)) {
            $this->outputCli($dumps);
            return $response;
        }

        // JSON/AJAX: inject into response body
        $contentType = $response->getHeader('Content-Type', '');
        if (str_contains($contentType, 'application/json') || $request->isXmlHttpRequest()) {
            return $this->injectJson($response, $dumps);
        }

        // HTML: inject script tag for Dev Toolbar
        if ($this->isHtmlResponse($contentType)) {
            return $this->injectHtml($response, $dumps);
        }

        return $response;
    }

    /**
     * Outputs dumps to CLI stderr.
     */
    private function outputCli(array $dumps): void
    {
        $dumper = $this->container->get(\Phue\Core\Debug\AsyncDumper::class);
        fwrite(STDERR, "\n=== PHUE DUMPS (Request: " . $dumper->getRequestId() . ") ===\n\n");

        foreach ($dumps as $i => $dump) {
            $time = date('H:i:s.u', (int) $dump['time']);
            $label = $dump['label'] ? " [{$dump['label']}]" : '';
            $group = $dump['group'] !== 'default' ? " ({$dump['group']})" : '';

            fwrite(STDERR, "--- Dump #{$i}{$label}{$group} @ {$time} ---\n");
            fwrite(STDERR, $this->formatValueForHtml($dump['value']) . "\n");

            if ($dump['trace'] !== []) {
                fwrite(STDERR, "  Stack trace:\n");
                foreach ($dump['trace'] as $frame) {
                    $class = $frame['class'] ? $frame['class'] . '::' : '';
                    fwrite(STDERR, "    {$frame['file']}:{$frame['line']} {$class}{$frame['function']}\n");
                }
            }
            fwrite(STDERR, "\n");
        }

        fwrite(STDERR, "=== END DUMPS ===\n\n");
    }

    /**
     * Injects dumps into JSON response.
     */
    private function injectJson(Response $response, array $dumps): Response
    {
        $content = (string)$response->getContent();
        $data = json_decode($content, true);

        if (!is_array($data)) {
            $data = ['data' => $data];
        }

        $data['__dumps'] = array_map(function ($dump) {
            return [
                'label' => $dump['label'],
                'group' => $dump['group'],
                'time' => $dump['time'],
                'value' => $dump['value'],
                'trace' => $dump['trace'],
                'request_id' => $dump['request_id'],
            ];
        }, $dumps);

        $response->setContent(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }

    /**
     * Injects dumps into HTML response for Dev Toolbar.
     */
    private function injectHtml(Response $response, array $dumps): Response
    {
        $body = (string)$response->getContent();

        // Prepare dumps data for JavaScript (for Dev Toolbar)
        $dumpsJson = json_encode(array_map(function ($dump) {
            return [
                'label' => $dump['label'],
                'group' => $dump['group'],
                'time' => $dump['time'],
                'value' => $dump['value'],
                'trace' => $dump['trace'],
                'request_id' => $dump['request_id'],
            ];
        }, $dumps), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $script = "<script>window.__PHUE_DUMPS = {$dumpsJson};</script>";

        // Find injection point (before </body> or </html>)
        $injectionPoint = $this->findInjectionPoint($body);
        if ($injectionPoint === null) {
            return $response;
        }

        $response->setContent(
            substr($body, 0, $injectionPoint) . $script . substr($body, $injectionPoint)
        );

        return $response;
    }

    /**
     * Checks if response is HTML.
     */
    private function isHtmlResponse(string $contentType): bool
    {
        if ($contentType === '') {
            return true;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));
        return str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }

    /**
     * Finds injection point in HTML body.
     */
    private function findInjectionPoint(string $body): ?int
    {
        if ($body === '') {
            return null;
        }

        foreach (['</body>', '</BODY>'] as $needle) {
            $offset = strripos($body, $needle);
            if ($offset !== false) {
                return $offset;
            }
        }

        $offset = strripos($body, '</html>');
        if ($offset !== false) {
            return $offset;
        }

        return str_contains($body, '<') ? strlen($body) : null;
    }

    /**
     * Formats a value for HTML display.
     */
    private function formatValueForHtml(mixed $value, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $lines = ['['];
            foreach ($value as $k => $v) {
                $lines[] = $pad . '  ' . var_export($k, true) . ' => ' . $this->formatValueForHtml($v, $indent + 1) . ',';
            }
            $lines[] = $pad . ']';
            return implode("\n", $lines);
        }

        if (is_object($value)) {
            return get_class($value) . ' ' . $this->formatValueForHtml((array)$value, $indent);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return var_export($value, true);
    }
}
