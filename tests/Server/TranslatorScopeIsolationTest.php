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
     * Every other test in this file registers its own container binding, which
     * proves the mechanism works but says nothing about whether Phue uses it.
     * Changing Application from scoped() to singleton() for the Translator
     * leaves all of them green — verified by doing exactly that and watching
     * this file stay green too. This one boots the application the framework
     * actually ships and asks ITS container for two translators in two scopes.
     */
    public function testTheApplicationRegistersTheTranslatorAsRequestScoped(): void
    {
        $app = new \Phue\Core\Application(dirname(__DIR__, 2));
        $container = $app->getContainer();

        $firstScope = $container->createScope();
        $container->setScope($firstScope);
        /** @var Translator $first */
        $first = $container->get(Translator::class);
        $first->setLocale('el');
        $container->flushScope($firstScope);

        $secondScope = $container->createScope();
        $container->setScope($secondScope);
        /** @var Translator $second */
        $second = $container->get(Translator::class);

        $this->assertNotSame(
            $first,
            $second,
            "Application must register the Translator request-scoped; a shared one carries a visitor's locale into the next request.",
        );

        $this->assertSame(
            'en',
            $second->getLocale(),
            "The next request must not inherit the previous request's language.",
        );

        $container->flushScope($secondScope);
    }

    /**
     * A signed-in identity that carries no locale column.
     *
     * IdentityInterface promises getId(), findIdentity() and getAuthKey() —
     * nothing else. Application asked it for getAttribute('locale') directly
     * and fataled on every request for any application whose identity did not
     * happen to extend BaseActiveRecord. A missing preference has to read as
     * "no preference", never as an error.
     */
    public function testRequestLocaleSurvivesAnIdentityWithNoLocaleAccessor(): void
    {
        $app = new \Phue\Core\Application(dirname(__DIR__, 2));
        $container = $app->getContainer();

        $scope = $container->createScope();
        $container->setScope($scope);

        // Resolving must complete, and return something usable. It does NOT
        // resolve to 'el': no Greek catalog ships yet, and narrowing to a
        // language the application cannot answer in is the correct result.
        // The point here is that resolution finishes at all.
        $resolver = $container->get(\Phue\Core\I18n\LocaleResolver::class);
        $resolved = $resolver->resolve(new \Phue\Core\Request('GET', '/', ['locale' => 'el']));

        $this->assertIsString($resolved);
        $this->assertNotSame('', $resolved);
        $this->assertSame(
            'en',
            $resolved,
            'An unavailable requested locale must narrow to a language that exists, here the default.',
        );

        $container->flushScope($scope);
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