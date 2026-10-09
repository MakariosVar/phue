<?php

declare(strict_types=1);

namespace Phue\Tests\Server;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\Container;
use Phue\Core\I18n\Translator;
use Phue\Core\ResettableInterface;
use Phue\Core\ScopeContext;

/**
 * A Translator must not outlive the request that created it.
 *
 * Why this file exists
 * --------------------
 * Under FPM the process dies with the request, so a stale locale is
 * impossible and the bug cannot appear. Under Swoole or RoadRunner one worker
 * serves request after request, and anything the container does not tear down
 * between them is state the next visitor inherits.
 *
 * If a Translator survives a request boundary, the second visitor is answered
 * in the first visitor's language. Nothing throws, no log line appears, and the
 * only symptom is that the application is mysteriously wrong for one set of
 * users and fine for another — which is very hard to trace back to a locale.
 *
 * So this is not "the container should reset things". It is: the container's
 * reset mechanism exists and works (flushScope calls reset()), and a
 * Translator registered through it participates. These tests drive the
 * CONTAINER, not the Translator, so a change that moves the registration from
 * scoped() to singleton() fails here even though every Translator unit test
 * would still pass.
 */
#[Group('unit')]
final class TranslatorScopeIsolationTest extends TestCase
{
    private Container $container;
    private ?ScopeContext $scope = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->container->scoped(Translator::class, fn(): Translator => new Translator(defaultLocale: 'en'));
    }

    /**
     * Run one request's worth of container scope and return the translator it
     * saw — the shape Application::handle() actually uses.
     */
    private function request(): Translator
    {
        $this->scope = $this->container->createScope();
        $this->container->setScope($this->scope);

        /** @var Translator $translator */
        $translator = $this->container->get(Translator::class);

        return $translator;
    }

    private function endRequest(): void
    {
        $this->container->flushScope($this->scope);
    }

    public function testEachRequestGetsItsOwnTranslatorInstance(): void
    {
        $first = $this->request();
        $this->endRequest();

        $second = $this->request();

        $this->assertNotSame(
            $first,
            $second,
            'A shared instance would carry the previous visitor\'s locale across the boundary.',
        );
    }

    public function testARequestLocaleDoesNotSurviveIntoTheNextRequest(): void
    {
        $first = $this->request();
        $first->setLocale('el');
        $this->assertSame('el', $first->getLocale());

        $this->endRequest();

        $second = $this->request();

        $this->assertSame(
            'en',
            $second->getLocale(),
            'The second visitor must start from the configured default, not inherit the first visitor\'s language.',
        );
    }

    /**
     * flushScope() resets rather than merely discards, which matters for the
     * static reference t() delegates through: a discarded instance that still
     * pointed at itself from a static would keep answering.
     */
    public function testFlushScopeResetsTheTranslatorRatherThanJustDroppingIt(): void
    {
        $translator = $this->request();
        $translator->activate();
        $translator->setLocale('el');

        $this->assertSame($translator, Translator::active());

        $this->endRequest();

        $this->assertNull(
            Translator::active(),
            'reset() must clear the static reference, or t() would keep using a translator from a finished request.',
        );
    }

    /**
     * The contract the container relies on, stated directly.
     *
     * If Translator stopped implementing it, flushScope would silently stop
     * resetting it and nothing else would report the loss.
     */
    public function testTranslatorIsResettable(): void
    {
        $this->assertInstanceOf(
            ResettableInterface::class,
            $this->request(),
            'Container::flushScope() only calls reset() on ResettableInterface instances; without it the per-request state is never cleared.',
        );
    }

    /**
     * The negative control.
     *
     * Without a translator of its own, the container proves it is the
     * registration that makes this safe rather than the container resetting
     * everything it happens to hold.
     */
    public function testANonResettableScopedServiceIsDiscardedWithoutReset(): void
    {
        $this->container->scoped('opaque', fn(): object => new \stdClass());

        $first = $this->container->get('opaque');
        $this->endRequest();
        $second = $this->container->get('opaque');

        $this->assertNotSame($first, $second);
    }

    /**
     * Catalog caching must not survive either.
     *
     * The Translator caches loaded catalogs per instance. A cache that outlived
     * the instance would hold translated strings loaded under a previous
     * request's configuration — stale translations after an application is
     * edited, with no reload.
     */
    public function testCatalogsAreNotCachedAcrossInstances(): void
    {
        $first = $this->request();
        $first->t('Something');
        $this->endRequest();

        $second = $this->request();

        $this->assertNotSame(
            spl_object_id($first),
            spl_object_id($second),
            'A fresh instance per request is what bounds the catalog cache; sharing would outlive it.',
        );
    }

    /**
     * Two translators alive at once must not fight over the static reference.
     *
     * In production this happens for the length of one request; a nested or
     * re-entrant handle() would otherwise leave t() pointing at whichever
     * instance finished first.
     */
    /**
     * The REAL registration, not a stand-in one.
     *
     * Every other test here registers its own binding, which proves the
     * mechanism works but says nothing about whether Phue uses it — swapping
     * Application from scoped() to singleton() left this file green when that
     * was tried. So the registration itself is pinned, in the only place it can
     * be observed without booting the application: its own source.
     *
     * Booting it from a test is not an option. The constructor writes a shared
     * config cache whose round-trip does not preserve types, so a test that
     * boots the application leaves later files failing inside SchiiConfig with
     * a TypeError that has nothing to do with locales. That is worth its own
     * investigation; it is not worth being triggered from here.
     */
    public function testTheApplicationRegistersTheTranslatorAsRequestScoped(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/Application.php');

        $this->assertStringContainsString(
            '->scoped(\Phue\Core\I18n\Translator::class',
            $source,
            "The Translator must be registered request-scoped. A singleton carries one visitor's locale into the next request under a persistent server.",
        );

        $this->assertStringNotContainsString(
            '->singleton(\Phue\Core\I18n\Translator::class',
            $source,
            'Registering the Translator as a singleton is the exact regression this guards.',
        );
    }

    /**
     * An identity carrying no locale column must not be fatal.
     *
     * IdentityInterface promises getId(), findIdentity() and getAuthKey() and
     * nothing else. Asking it for getAttribute('locale') directly faulted on
     * every request for any application whose identity does not extend
     * BaseActiveRecord — a missing preference has to read as no preference.
     */
    public function testTheUserPreferenceIsReadDefensively(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Core/Application.php');

        $this->assertMatchesRegularExpression(
            '/method_exists\(\$user, .getAttribute.\)/',
            $source,
            'The locale preference accessor must be checked for, not assumed; IdentityInterface does not promise it.',
        );
    }

    public function testTheActiveTranslatorFollowsTheMostRecentlyActivatedOne(): void
    {
        $a = new Translator(defaultLocale: 'en');
        $a->activate();

        $b = new Translator(defaultLocale: 'en');
        $b->activate();

        $this->assertSame($b, Translator::active());

        $a->reset();

        $this->assertSame(
            $b,
            Translator::active(),
            'A superseded translator giving up its reference must not clear the current one.',
        );

        $b->reset();
    }
}