<?php

declare(strict_types=1);

namespace Phue\Tests\Core\UI;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Security\Csrf\CsrfTokenManager;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\PhueComponentLoader;
use Phue\Core\UI\PhuePartialRenderer;
use Phue\Core\UI\View;

/**
 * The browser half of the CSRF contract.
 *
 * The server issues the token in a cookie and refuses any state-changing request that does
 * not send it back. If this patch is missing, every form in a working application starts
 * failing with 403 — which reads as "CSRF is broken" rather than "the client never learned
 * to send the token". That is why the assertion here is about the emitted script, not
 * about the middleware that was already tested elsewhere.
 *
 * @package Phue\Tests\Core\UI
 */
#[Group('unit')]
final class CsrfClientTransportTest extends TestCase
{
    private function renderHtml(?array $csrfConfig): string
    {
        $renderer = new ComponentRenderer(
            shellRenderer: new HtmlShellRenderer(new AssetRegistry()),
            assetRegistry: new AssetRegistry(),
            loader: null,
            layoutName: null,
            csrfConfig: $csrfConfig ?? [],
        );

        $view = new View(template: '<div>page</div>', state: ['a' => 1]);

        return $renderer->render('page', $view)->getContent();
    }

    public function testHeadScriptInstallsTheFetchPatch(): void
    {
        $html = $this->renderHtml(null);

        $this->assertStringContainsString('__phueCsrfPatched', $html);
        $this->assertStringContainsString('window.fetch =', $html);
    }

    /**
     * A deferred or module script would run after the body has begun, leaving a window in
     * which the first fetch goes out unprotected. The patch has to be in the synchronous
     * head script, before anything on the page can issue a request.
     */
    public function testThePatchIsSynchronousAndNotDeferred(): void
    {
        $html = $this->renderHtml(null);
        $position = strpos($html, '__phueCsrfPatched');

        $this->assertIsInt($position);
        $this->assertStringNotContainsString('defer', substr($html, max(0, $position - 200), 200));
        $this->assertStringNotContainsString('type="module"', substr($html, max(0, $position - 200), 200));
    }

    public function testThePatchRunsInsideHeadRatherThanAfterTheBody(): void
    {
        $html = $this->renderHtml(null);

        $headEnd = strpos($html, '</head>');
        $patch = strpos($html, '__phueCsrfPatched');

        $this->assertIsInt($headEnd);
        $this->assertIsInt($patch);
        $this->assertLessThan($headEnd, $patch, 'The patch must be installed before the body runs.');
    }

    public function testTheConfiguredCookieAndHeaderNamesReachTheScript(): void
    {
        $html = $this->renderHtml([
            'enabled' => true,
            'cookieName' => 'my_csrf',
            'headerName' => 'X-My-Csrf',
        ]);

        $this->assertStringContainsString("'my_csrf'", $html);
        $this->assertStringContainsString("'X-My-Csrf'", $html);
    }

    public function testSafeMethodsAreSkippedByThePatch(): void
    {
        $html = $this->renderHtml(null);

        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE'] as $method) {
            $this->assertStringContainsString($method, $html, $method . ' must not require a token.');
        }
    }

    public function testDisablingCsrfRemovesThePatchFromThePage(): void
    {
        $html = $this->renderHtml(['enabled' => false]);

        $this->assertStringNotContainsString('__phueCsrfPatched', $html);
    }

    /**
     * The cookie is readable on purpose, so the contract is only fulfilled if the server
     * actually issues it. Both halves have to agree on the name or every write 403s.
     */
    public function testTheTokenTheServerIssuesIsTheNameTheScriptLooksFor(): void
    {
        $key = 'base64:' . base64_encode(random_bytes(32));
        $token = CsrfTokenManager::fromConfigValue($key)->generate();
        $cookieName = 'phue_csrf';

        $cookie = new \Phue\Core\Security\Cookie(name: $cookieName, value: $token, sameSite: 'Strict');
        $headerLine = $cookie->toHeaderString();

        $this->assertStringStartsWith($cookieName . '=', $headerLine);
        $this->assertStringContainsString($token, $headerLine);

        $html = $this->renderHtml(null);
        $this->assertStringContainsString("'" . $cookieName . "'", $html);
    }

    /**
     * No top-level window/document reference, or importing the module during SSR throws.
     */
    public function testTheSharedJsModuleHasNoTopLevelDomAccess(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/UI/js/csrf.js');

        $this->assertStringNotContainsString('document.cookie;', $source);
        $this->assertMatchesRegularExpression('/^\s*(const|let|var)\s/m', $source);

        // The forbidden pattern is an assignment at column zero, not a mention inside a function.
        $this->assertDoesNotMatchRegularExpression('/^window\./m', $source);
        $this->assertDoesNotMatchRegularExpression('/^document\./m', $source);
    }

    public function testTheSharedJsModuleExportsTheDocumentedSurface(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/UI/js/csrf.js');

        foreach (['readCookie', 'csrfToken', 'csrfHeaders', 'installCsrfFetchPatch'] as $export) {
            $this->assertStringContainsString('export function ' . $export . '(', $source, $export);
        }
    }
}