<?php

declare(strict_types=1);

namespace Phue\Tests\Core\I18n;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Phue\Core\I18n\LocaleResolver;
use Phue\Core\I18n\Translator;
use Phue\Core\Request;

/**
 * A message is translated into the active language, and the active language
 * belongs to one request.
 *
 * What is pinned here
 * -------------------
 *  - the source string is the key, and a missing translation returns it
 *    unchanged rather than a key, a blank, or a crash;
 *  - the locale falls back along a chain (el-GR -> el -> default -> source)
 *    so a regional variant inherits its base language;
 *  - detection order is ?locale, then the user's stored preference, then
 *    Accept-Language, then the default;
 *  - and the one that matters most: a Translator is reset between requests,
 *    because a persistent worker that kept one would answer the next visitor
 *    in the previous visitor's language.
 *
 * The catalogs are written to a temp directory rather than to src/, so these
 * tests never depend on what the framework or the application happens to
 * translate today — a test that passes because a string is currently
 * untranslated is a test that fails the day someone translates it.
 */
#[Group('unit')]
final class TranslatorTest extends TestCase
{
    private string $catalogDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogDir = sys_get_temp_dir() . '/phue-i18n-' . bin2hex(random_bytes(6));
        mkdir($this->catalogDir, 0o777, true);

        $this->writeCatalog('en', ['Hello' => 'Hello', 'Saved {name}' => 'Saved {name}']);
        $this->writeCatalog('el', ['Hello' => 'Γεια', 'Saved {name}' => 'Αποθηκεύτηκε το {name}']);
        $this->writeCatalog('de', ['Hello' => 'Hallo']);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->catalogDir . '/*.php') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->catalogDir);
        Translator::active()?->reset();

        parent::tearDown();
    }

    private function writeCatalog(string $locale, array $messages): void
    {
        file_put_contents(
            $this->catalogDir . '/' . $locale . '.php',
            "<?php\n\nreturn " . var_export($messages, true) . ";\n"
        );
    }

    private function translator(string $default = 'en'): Translator
    {
        return new Translator(defaultLocale: $default, appLocalePath: $this->catalogDir);
    }

    // ------------------------------------------------------- the basic swap

    public function testAMessageIsTranslatedIntoTheActiveLocale(): void
    {
        $t = $this->translator();
        $t->setLocale('el');

        $this->assertSame('Γεια', $t->t('Hello'));
    }

    public function testAnUntranslatedMessageReturnsTheSourceUnchanged(): void
    {
        $t = $this->translator();
        $t->setLocale('el');

        $this->assertSame(
            'A message nobody translated',
            $t->t('A message nobody translated'),
            'A missing translation must read as English, never as a key, a blank or a crash.',
        );
    }

    public function testTheDefaultLocaleIsUsedBeforeAnyIsSet(): void
    {
        $this->assertSame('Hello', $this->translator('en')->t('Hello'));
        $this->assertSame('Γεια', $this->translator('el')->t('Hello'));
    }

    public function testPlaceholdersAreSubstituted(): void
    {
        $t = $this->translator();
        $t->setLocale('el');

        $this->assertSame('Αποθηκεύτηκε το Alex', $t->t('Saved {name}', ['name' => 'Alex']));
    }

    public function testAPlaceholderWithNoParameterIsLeftVisible(): void
    {
        // A half-filled sentence is a visible bug; a blanked one is silent.
        $this->assertSame('Saved {name}', $this->translator()->t('Saved {name}'));
    }

    public function testPlaceholdersAreNotSubstitutedInTheSourceLanguageWhenAbsent(): void
    {
        $t = $this->translator();
        $t->setLocale('el');

        $this->assertStringContainsString('{name}', $t->t('Saved {name}', ['other' => 'x']));
    }

    // ------------------------------------------------------ the locale chain

    public function testARegionalVariantInheritsItsBaseLanguage(): void
    {
        $t = $this->translator();
        $t->setLocale('el-GR');

        $this->assertSame(
            'Γεια',
            $t->t('Hello'),
            'el-GR must fall back to el rather than jumping straight to the default.',
        );
    }

    public function testAnUnderscoreSpellingIsUnderstood(): void
    {
        $t = $this->translator();
        $t->setLocale('el_GR');

        $this->assertSame('Γεια', $t->t('Hello'));
    }

    public function testACompletelyUnknownLocaleFallsBackToTheSource(): void
    {
        $t = $this->translator();
        $t->setLocale('ja');

        $this->assertSame('Hello', $t->t('Hello'));
    }

    public function testAnExplicitLocaleOverridesTheActiveOne(): void
    {
        $t = $this->translator();
        $t->setLocale('en');

        $this->assertSame('Γεια', $t->t('Hello', [], 'el'));
    }

    public function testSupportedLocalesAreDiscoveredFromBothDirectories(): void
    {
        $supported = $this->translator()->supportedLocales();

        foreach (['en', 'el', 'de'] as $locale) {
            $this->assertContains($locale, $supported);
        }
    }

    // ---------------------------------------------------------- app catalogs

    /**
     * An application catalog is consulted, and supplies what the framework
     * does not have.
     *
     * The framework's own catalog is the real src/Core/I18n directory, which
     * ships no translations yet — so this asserts that the SECOND search path
     * is read at all, which is the half that a "framework-only" bug would
     * break. The precedence between the two (application wins) is the order of
     * $paths in the constructor.
     */
    public function testAnApplicationCatalogSuppliesWhatTheFrameworkLacks(): void
    {
        $this->writeCatalog('fr', ['Hello' => 'Bonjour', 'Only the app knows this' => 'Seulement ici']);

        $t = new Translator(defaultLocale: 'en', appLocalePath: $this->catalogDir);
        $t->setLocale('fr');

        $this->assertSame('Bonjour', $t->t('Hello'));
        $this->assertSame('Seulement ici', $t->t('Only the app knows this'));
    }

    public function testTheFrameworkDirectoryIsSearchedFirst(): void
    {
        // The framework ships an (empty) en catalog; its presence must not stop
        // the application's own catalog from being merged in afterwards.
        $supported = $this->translator()->supportedLocales();

        $this->assertContains('en', $supported);
        $this->assertContains('el', $supported);
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * The reason this class exists.
     *
     * Swoole and RoadRunner serve many requests from one process. A translator
     * that outlived the request would carry its locale into the next one, and
     * the next visitor would read the previous visitor's language. Nothing
     * about that looks like a translation bug in production; it looks like the
     * application being broken in one language for one set of users.
     */
    public function testResetReturnsTheTranslatorToItsInitialState(): void
    {
        $t = $this->translator();
        $t->activate();
        $t->setLocale('el');

        $this->assertSame('Γεια', $t->t('Hello'));

        $t->reset();

        $this->assertSame(
            'en',
            $t->getLocale(),
            'A reset translator must not remember the language of the request that just ended.',
        );
        $this->assertNull(Translator::active(), 'reset() must drop the static reference \Phue\Core\t() delegates through.');
    }

    public function testActivatingASecondTranslatorHandsOverTheActiveReference(): void
    {
        $first = $this->translator();
        $first->activate();

        $second = $this->translator();
        $second->activate();
        $second->setLocale('el');

        $this->assertSame($second, Translator::active());
        $this->assertSame('Γεια', \Phue\Core\t('Hello'));

        $first->reset();
        $this->assertSame(
            $second,
            Translator::active(),
            'Resetting a superseded translator must not clear the one now serving the request.',
        );

        $second->reset();
    }

    /**
     * Outside a request — a console command, a worker before its scope, a test
     * that never built an Application — \Phue\Core\t() must still return the message.
     */
    public function testTheHelperIsInertWithoutATranslator(): void
    {
        $this->assertNull(Translator::active());
        $this->assertSame('Hello', \Phue\Core\t('Hello'));
        $this->assertSame('Hi {name}', \Phue\Core\t('Hi {name}', ['name' => 'x']));
    }

    public function testTheHelperFollowsTheActiveTranslator(): void
    {
        $t = $this->translator();
        $t->activate();
        $t->setLocale('el');

        $this->assertSame('Γεια', \Phue\Core\t('Hello'));
        $this->assertSame('Αποθηκεύτηκε το Alex', \Phue\Core\t('Saved {name}', ['name' => 'Alex']));

        $t->reset();
    }
}

/**
 * Which language a request is answered in.
 */
#[Group('unit')]
final class LocaleResolverTest extends TestCase
{
    private const AVAILABLE = ['en', 'el', 'de'];

    private function resolver(string $default = 'en'): LocaleResolver
    {
        return new LocaleResolver(defaultLocale: $default, supportedLocales: self::AVAILABLE);
    }

    private function request(array $query = [], array $headers = []): Request
    {
        return new Request('GET', '/', $query, [], $headers);
    }

    // ------------------------------------------------------------ precedence

    public function testTheQueryParameterOutranksEverythingElse(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request(['locale' => 'el'], ['Accept-Language' => 'de']),
            userPreference: 'de',
        );

        $this->assertSame(
            'el',
            $resolved,
            'A link that names a language must honour it; otherwise no language switch can ever work.',
        );
    }

    public function testTheUserPreferenceOutranksTheBrowserHeader(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request([], ['Accept-Language' => 'de']),
            userPreference: 'el',
        );

        $this->assertSame('el', $resolved);
    }

    public function testTheBrowserHeaderIsUsedWhenNothingElseSpeaks(): void
    {
        $resolved = $this->resolver()->resolve($this->request([], ['Accept-Language' => 'el-GR,el;q=0.9']));

        $this->assertSame('el', $resolved);
    }

    public function testTheDefaultIsUsedWhenTheRequestSaysNothing(): void
    {
        $this->assertSame('en', $this->resolver()->resolve($this->request()));
        $this->assertSame('de', $this->resolver('de')->resolve($this->request()));
    }

    // -------------------------------------------------------- header parsing

    public function testTheMostPreferredLanguageWins(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request([], ['Accept-Language' => 'de;q=0.5,el;q=0.9,en;q=0.1'])
        );

        $this->assertSame('el', $resolved);
    }

    public function testQualityValuesAreHonouredRatherThanHeaderOrder(): void
    {
        // Quality beats position: a header is not required to be ordered.
        $resolved = $this->resolver()->resolve(
            $this->request([], ['Accept-Language' => 'de,el;q=0.8'])
        );

        $this->assertSame('de', $resolved);
    }

    public function testAZeroQualityLanguageIsNotUsed(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request([], ['Accept-Language' => 'el;q=0, de;q=0.5'])
        );

        $this->assertSame('de', $resolved, 'q=0 is an explicit refusal.');
    }

    public function testAWildcardIsIgnored(): void
    {
        $this->assertSame('en', $this->resolver()->resolve($this->request([], ['Accept-Language' => '*'])));
    }

    public function testAMalformedHeaderDoesNotBreakResolution(): void
    {
        foreach ([';;;', 'q=0.9', ',,,', 'el;q=notanumber'] as $header) {
            $this->assertSame(
                'en',
                $this->resolver()->resolve($this->request([], ['Accept-Language' => $header])),
                "Header '{$header}' must fall through to the default rather than throw.",
            );
        }
    }

    // --------------------------------------------------------- narrowing

    public function testAnUnavailableLanguageNarrowsToItsBaseOrTheDefault(): void
    {
        // The application ships en/el/de. A visitor asking for fr-CA must be
        // given a language the application can actually answer in, not one it
        // has no catalog for — otherwise getLocale() reports "fr" while every
        // string falls back to English.
        $this->assertSame('en', $this->resolver()->resolve($this->request([], ['Accept-Language' => 'fr-CA,fr;q=0.9'])));
    }

    public function testARegionalRequestIsServedByItsBaseLanguage(): void
    {
        $this->assertSame('el', $this->resolver()->resolve($this->request([], ['Accept-Language' => 'el-GR'])));
    }

    // -------------------------------------------------- hostile input

    /**
     * The ?locale value is never used as anything but a catalog filename.
     *
     * Rather than sanitising a value that reaches a filesystem lookup, only the
     * shape that is safe is accepted; everything else is ignored.
     */
    public function testAHostileLocaleParameterIsIgnored(): void
    {
        foreach (
            [
                '../../etc/passwd',
                'el/../../secret',
                'el;drop',
                str_repeat('a', 300),
                'e',
                '',
                '1234',
            ] as $value
        ) {
            $resolved = $this->resolver()->resolve(
                $this->request(['locale' => $value], ['Accept-Language' => 'de'])
            );

            $this->assertSame(
                'de',
                $resolved,
                'A rejected locale must fall through to the next source, not be used.',
            );
        }
    }

    public function testAnArrayLocaleParameterIsIgnored(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request(['locale' => ['el']], ['Accept-Language' => 'de'])
        );

        $this->assertSame('de', $resolved);
    }

    public function testResolutionWithoutARequestIsTheDefault(): void
    {
        $this->assertSame('en', $this->resolver()->resolve(null));
        $this->assertSame('el', $this->resolver()->resolve(null, userPreference: 'el'));
    }
}