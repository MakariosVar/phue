<?php

declare(strict_types=1);

namespace Phue\Core\Security\Csrf;

use Psr\Log\LoggerInterface;
use Phue\Core\Request;
use Phue\Core\Response;
use Phue\Core\Routing\Router;
use Phue\Core\Security\Attributes\CsrfExempt;
use Phue\Core\Security\Cookie;
use Phue\Core\Security\Csrf\Exceptions\CsrfTokenMismatchException;
use Phue\Core\Security\Csrf\Exceptions\MissingApplicationKeyException;
use Phue\Core\Security\SecurityMiddleware;

/**
 * Rejects state-changing requests that do not carry a token this application issued.
 *
 * The token is a signed nonce: it lives in a readable cookie and comes back in a header or
 * a form field. See CsrfTokenManager for why the signature matters and why nothing is
 * stored server-side.
 *
 * Four classes of request skip verification, each for a specific reason rather than for
 * convenience:
 *
 *  - Safe methods. GET/HEAD/OPTIONS/TRACE do not change state.
 *  - Non-ambient credentials. A Bearer token is attached by the client's own code, so it
 *    cannot be ridden by a third-party page. This is what lets the Schii and Rest APIs
 *    work with no per-route opt-out.
 *  - #[CsrfExempt]. For routes that genuinely cannot carry a token.
 *  - Disabled. The master switch.
 *
 * Everything else must present a valid token, from the header or from the body field.
 *
 * @package Phue\Core\Security\Csrf
 */
final class CsrfMiddleware
{
    /**
     * The request matched a route and no exemption was declared: it must carry a token.
     */
    private const VERDICT_PROTECTED = 'protected';

    /**
     * The request matched a route carrying #[CsrfExempt].
     */
    private const VERDICT_EXEMPT = 'exempt';

    /**
     * The request matched no route at all. Nothing can be changed, so there is nothing to
     * protect; the router gets to answer with its own 404 or 405.
     */
    private const VERDICT_NO_ROUTE = 'no-route';

    /**
     * Built on first use rather than in the constructor. A missing APP_KEY must not stop
     * the application from booting or from serving reads; it has to stop the exact thing
     * it would endanger, which is the write.
     *
     * @var CsrfTokenManager|null
     */
    private ?CsrfTokenManager $manager = null;

    /**
     * @param CsrfConfig $config Verdict table and token transport names
     * @param LoggerInterface $logger Where rejection reasons go; the client is told nothing
     * @param Router $router Used to read #[CsrfExempt] off the matched handler
     * @param SecurityMiddleware $security Source of truth for whether this request is HTTPS
     */
    public function __construct(
        private readonly CsrfConfig $config,
        private readonly LoggerInterface $logger,
        private readonly Router $router,
        private readonly SecurityMiddleware $security,
    ) {
    }

    /**
     * @param Request $request Current HTTP request instance
     * @param callable $next Next middleware layer
     * @return Response
     *
     * @throws CsrfTokenMismatchException When an unsafe request presents no valid token
     * @throws MissingApplicationKeyException When a token is demanded but APP_KEY is unusable
     */
    public function __invoke(Request $request, callable $next): Response
    {
        if ($this->isExempt($request)) {
            return $next($request);
        }

        // Resolved before the client is judged, not after. Without a key nothing can be
        // verified, so every rejection would blame the client for what is our own
        // misconfiguration, and a 403 on an unconfigured application is a lie: it tells the
        // user to fix their form when the fix is `./phue generate:key`. Memoized, so this
        // costs nothing per request, and no crypto happens until a token is actually in hand.
        $manager = $this->manager();

        $presented = $this->presentedToken($request);

        if ($presented === null || $presented === '') {
            throw $this->reject($request, 'no token in header or body field');
        }

        if (!$manager->verify($presented)) {
            throw $this->reject($request, 'token failed signature verification');
        }

        return $next($request);
    }

    /**
     * Attach a token cookie to a response that a browser will render, unless one is
     * already present or already valid.
     *
     * Public and idempotent so the application can re-apply it to responses that never
     * passed through __invoke, the way SecurityMiddleware::applyHeaders() is re-applied to
     * the error path. Without that, an error page would ship no token and the client's
     * next form submission would fail with a 403 that looks like the user's mistake.
     *
     * A missing APP_KEY is logged and skipped here rather than thrown, while the write path
     * in __invoke() does throw. The asymmetry is deliberate: refusing to issue a token
     * leaves reads working and is fully logged, whereas throwing here would take down every
     * page in an application that has not been configured yet, including the login screen an
     * operator needs in order to fix it. The loud failure belongs on the write, which is
     * the operation that would actually be unguarded.
     *
     * @param Response $response Response to attach the cookie to
     * @param Request $request Request that produced it, for transport detection
     * @return Response
     */
    public function issueTokenCookie(Response $response, Request $request): Response
    {
        if (!$this->config->enabled || !$this->config->issueTokenOnHtmlResponses) {
            return $response;
        }

        if (!$this->isHtml($response)) {
            return $response;
        }

        // Already queued on this response: nothing to do, and re-issuing would rotate the
        // token on every render.
        foreach ($response->getCookies() as $cookie) {
            if ($cookie->name === $this->config->cookieName) {
                return $response;
            }
        }

        try {
            $token = $this->manager()->generate();
        } catch (MissingApplicationKeyException $e) {
            $this->logger->error('CSRF token not issued: ' . $e->getMessage());

            return $response;
        }

        return $response->withCookie($this->tokenCookie($token, $request));
    }

    /**
     * Whether this request is exempt from verification.
     *
     * @param Request $request
     * @return bool
     */
    private function isExempt(Request $request): bool
    {
        if (!$this->config->enabled) {
            return true;
        }

        if (in_array($request->getMethod(), $this->config->safeMethods, true)) {
            return true;
        }

        if ($this->hasNonAmbientCredentials($request)) {
            return true;
        }

        if ($this->routeVerdict($request) !== self::VERDICT_PROTECTED) {
            return true;
        }

        return !$this->hasAmbientState($request);
    }

    /**
     * Whether the request arrives carrying browser-managed state.
     *
     * This is what CSRF is actually about. The attack is "make the victim's browser borrow
     * its credentials"; it presupposes credentials riding along without the victim's intent.
     * A request that carries no cookies at all carries no ambient credential, so there is
     * nothing to ride, and an attacker who sent it could have sent it himself from curl.
     *
     * The consequence is that a public API keeps working untouched: a server-to-server
     * client, or a REST call from an anonymous browser tab with an empty cookie jar, is not
     * forced to invent a token it has no use for. Every browser-driven write, which is the
     * case that matters, does carry cookies and is therefore verified.
     *
     * Note what this is not: it is not "trust the client". The attacker cannot strip cookies
     * from a request the victim's browser makes, because they do not get to write its headers.
     *
     * @param Request $request
     * @return bool
     */
    private function hasAmbientState(Request $request): bool
    {
        return $request->getCookies() !== [];
    }

    /**
     * Decide whether the matched route is exempt, protected, or not there at all.
     *
     * Follows NPlusOneGuardMiddleware: re-dispatch through the Router and unwrap the
     * handler. That avoids adding a key to Router::$routes, whose shape is pinned by the
     * route cache format and by RouteCacheSafetyTest.
     *
     * A request that matches no route is exempt on purpose. This middleware runs before
     * the router does, so without that exemption a POST to a path that does not exist would
     * be answered with 403 instead of the 404 the router owes, and a DELETE against a
     * GET-only path would be answered with 403 instead of 405 plus its mandatory Allow
     * header. There is no handler to protect in either case, no state can be changed, and
     * letting CSRF answer first would also leak which routes exist.
     *
     * @param Request $request
     * @return string One of the VERDICT_* constants
     */
    private function routeVerdict(Request $request): string
    {
        try {
            $routeMatch = $this->router->dispatch($request->getMethod(), $request->getUri());
        } catch (\Phue\Core\Routing\Exceptions\RouteNotFoundException|\Phue\Core\Routing\Exceptions\MethodNotAllowedException) {
            return self::VERDICT_NO_ROUTE;
        }

        $handler = $routeMatch->getHandler();

        $class = null;
        $method = null;

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
        } elseif (is_array($handler) && is_string($handler[0] ?? null)) {
            $class = $handler[0];
            $method = $handler[1] ?? null;
        } elseif (is_object($handler) && !$handler instanceof \Closure) {
            $class = $handler::class;
            $method = '__invoke';
        }

        if (!is_string($class) || !is_string($method) || !method_exists($class, $method)) {
            // Matched, but the handler shape is unknown, so the attribute cannot be read.
            // Defaulting to protected is the safe direction: a route whose exemption we
            // cannot confirm stays guarded.
            return self::VERDICT_PROTECTED;
        }

        return (new \ReflectionMethod($class, $method))->getAttributes(CsrfExempt::class) === []
            ? self::VERDICT_PROTECTED
            : self::VERDICT_EXEMPT;
    }

    /**
     * Detect an Authorization header whose credentials the browser attaches on its own
     * initiative. It never does: only code on the page adds it, so a third-party page
     * cannot ride it, and demanding a CSRF token alongside it would be theatre.
     *
     * @param Request $request
     * @return bool
     */
    private function hasNonAmbientCredentials(Request $request): bool
    {
        $authorization = $request->getHeader('Authorization');

        if ($authorization === null) {
            return false;
        }

        foreach ($this->config->nonAmbientAuthSchemes as $scheme) {
            if (stripos(ltrim($authorization), $scheme . ' ') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token as presented by the client.
     *
     * Read from the header first, then the parsed body. Deliberately NOT input(): that
     * falls back to the query string, and a token in a URL leaks into the Referer header,
     * into access logs and into browser history. A request carrying only ?_csrf= must be
     * rejected, which is why the field is read from the body alone.
     *
     * @param Request $request
     * @return string|null
     */
    private function presentedToken(Request $request): ?string
    {
        $header = $request->getHeader($this->config->headerName);

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $field = $request->getParsedBody()[$this->config->fieldName] ?? null;

        return is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * @return CsrfTokenManager
     * @throws MissingApplicationKeyException
     */
    private function manager(): CsrfTokenManager
    {
        return $this->manager ??= CsrfTokenManager::fromConfigValue($this->config->appKey);
    }

    /**
     * @param string $token
     * @param Request $request
     * @return Cookie
     */
    private function tokenCookie(string $token, Request $request): Cookie
    {
        return new Cookie(
            name: $this->config->cookieName,
            value: $token,
            path: $this->config->cookiePath,
            // SameSite=Strict is the right pairing here, not Lax: the token is only ever
            // needed for same-site writes, and Strict means the browser withholds it from
            // cross-site requests altogether, which rejects the attack before any of this
            // code runs. That makes the double submit a second line of defence rather than
            // the only one.
            sameSite: $this->config->cookieSameSite,
            secure: $this->security->isSecure($request),
            // Not HttpOnly, deliberately. The client has to read this cookie to put the
            // token in the header, so HttpOnly would leave it unreadable and the header
            // path dead. It buys nothing either: script already running on the origin can
            // send an authenticated request outright, with or without the token.
            httpOnly: false,
        );
    }

    /**
     * @param Response $response
     * @return bool
     */
    private function isHtml(Response $response): bool
    {
        $contentType = $response->getHeader('Content-Type', '');

        return is_string($contentType) && stripos($contentType, 'text/html') === 0;
    }

    /**
     * Build the rejection and record why, without telling the client why.
     *
     * @param Request $request
     * @param string $reason
     * @return CsrfTokenMismatchException
     */
    private function reject(Request $request, string $reason): CsrfTokenMismatchException
    {
        $this->logger->warning('CSRF token rejected', [
            'reason' => $reason,
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'hasHeader' => $request->getHeader($this->config->headerName) !== null,
        ]);

        return CsrfTokenMismatchException::because($reason);
    }
}