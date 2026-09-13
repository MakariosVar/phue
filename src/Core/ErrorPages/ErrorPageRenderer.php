<?php

declare(strict_types=1);

namespace Phue\Core\ErrorPages;

use Throwable;

/**
 * Class ErrorPageRenderer
 *
 * Renders HTML error pages supporting Debug mode (stack trace, headers, timing)
 * and Production mode (clean branded page with "go home" CTA)
 *
 * @package Phue\Core\ErrorPages
 */
class ErrorPageRenderer
{
    /**
     * Render HTML error page based on exception and debug flag.
     *
     * @param Throwable $e The caught exception.
     * @param bool $debug Whether application is in debug mode.
     * @return string Rendered HTML.
     */
    public function render(Throwable $e, bool $debug): string
    {
        if ($debug) {
            $className = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
            $trace = nl2br(htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8'));
            $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
            $line = $e->getLine();

            return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>{$className}: {$message}</title>
                <style>
                    body { font-family: monospace; background: #0f172a; color: #f8fafc; padding: 24px; }
                    .card { background: #1e293b; padding: 20px; border-radius: 8px; border-left: 4px solid #ef4444; }
                    h1 { color: #f87171; font-size: 20px; }
                    .trace { white-space: pre-wrap; font-size: 13px; color: #94a3b8; margin-top: 16px; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>{$className}: {$message}</h1>
                    <p><strong>File:</strong> {$file} : line {$line}</p>
                    <div class="trace">{$trace}</div>
                </div>
            </body>
            </html>
            HTML;
        }

        // Production Mode: Clean branded page with "Go Home" CTA
        return <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Internal Server Error</title>
            <style>
                body { font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                .container { text-align: center; max-width: 480px; padding: 32px; }
                h1 { font-size: 32px; margin-bottom: 12px; }
                p { color: #475569; margin-bottom: 24px; }
                .btn { display: inline-block; background: #0284c7; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 500; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Something went wrong</h1>
                <p>We are experiencing an internal server issue. Please try again later.</p>
                <a href="/" class="btn">Go Home</a>
            </div>
        </body>
        </html>
        HTML;
    }
}