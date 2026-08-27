<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Tests\Unit\Services;

use Calevans\StaticForgeGoogleAnalytics\Models\AnalyticsSettings;
use Calevans\StaticForgeGoogleAnalytics\Services\GoogleAnalyticsService;
use EICC\Utils\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsServiceTest extends TestCase
{
    private Log&MockObject $logger;
    private GoogleAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(Log::class);
        $this->service = new GoogleAnalyticsService($this->logger);
    }

    // -----------------------------------------------------------------
    // Kill switch
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function killSwitchEngagesProvider(): array
    {
        return [
            'false' => ['false'],
            'digit zero' => ['0'],
            'off' => ['off'],
            'no' => ['no'],
            'mixed case' => ['FaLsE'],
            'surrounding whitespace' => [' FALSE '],
        ];
    }

    #[DataProvider('killSwitchEngagesProvider')]
    public function testResolveSettingsKillSwitchEngages(string $envEnabled): void
    {
        $this->logger->expects($this->once())
            ->method('log')
            ->with('INFO', $this->stringContains('suppressed by GOOGLE_ANALYTICS_ENABLED'));

        $result = $this->service->resolveSettings(
            ['enabled' => true, 'tracking_id' => 'G-XXXXXXXXXX'],
            null,
            $envEnabled,
        );

        $this->assertNull($result);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function killSwitchDoesNotEngageProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'true' => ['true'],
            'digit one' => ['1'],
            'yes' => ['yes'],
            'maybe' => ['maybe'],
        ];
    }

    #[DataProvider('killSwitchDoesNotEngageProvider')]
    public function testResolveSettingsKillSwitchDoesNotEngage(?string $envEnabled): void
    {
        // Empty string is load-bearing: a copied .env.example with
        // GOOGLE_ANALYTICS_ENABLED="" must not disable analytics.
        $this->logger->expects($this->never())->method('log');

        $result = $this->service->resolveSettings(
            ['enabled' => true, 'tracking_id' => 'G-XXXXXXXXXX'],
            null,
            $envEnabled,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame('G-XXXXXXXXXX', $result->trackingId);
    }

    public function testResolveSettingsKillSwitchWinsEvenWhenEnabledAndIdAreValid(): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'tracking_id' => 'G-XXXXXXXXXX'],
            'G-YYYYYYYYYY',
            'false',
        );

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------
    // enabled flag
    // -----------------------------------------------------------------

    public function testResolveSettingsEnabledAbsentReturnsNullSilently(): void
    {
        $this->logger->expects($this->never())->method('log');

        $result = $this->service->resolveSettings([], null, null);

        $this->assertNull($result);
    }

    public function testResolveSettingsEnabledFalseReturnsNullSilently(): void
    {
        $this->logger->expects($this->never())->method('log');

        $result = $this->service->resolveSettings(['enabled' => false], 'G-XXXXXXXXXX', null);

        $this->assertNull($result);
    }

    public function testResolveSettingsEnabledZeroReturnsNullSilently(): void
    {
        $this->logger->expects($this->never())->method('log');

        $result = $this->service->resolveSettings(['enabled' => 0], 'G-XXXXXXXXXX', null);

        $this->assertNull($result);
    }

    public function testResolveSettingsEnabledTrueNoIdFromEitherSourceLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('log')
            ->with('WARNING', $this->stringContains('no tracking id configured'));

        $result = $this->service->resolveSettings(['enabled' => true], null, null);

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------
    // tracking id precedence
    // -----------------------------------------------------------------

    public function testResolveSettingsEnvIdWinsOverSiteconfigTrackingId(): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'tracking_id' => 'G-CONFIGCONFIG'],
            'G-ENVENVENV',
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame('G-ENVENVENV', $result->trackingId);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function blankEnvIdProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    #[DataProvider('blankEnvIdProvider')]
    public function testResolveSettingsUsesSiteconfigTrackingIdWhenEnvIdBlank(?string $envId): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'tracking_id' => 'G-CONFIGCONFIG'],
            $envId,
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame('G-CONFIGCONFIG', $result->trackingId);
    }

    // -----------------------------------------------------------------
    // tracking id format validation
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function validTrackingIdProvider(): array
    {
        return [
            'GA4' => ['G-XXXXXXXXXX'],
            'GTM' => ['GTM-XXXXXXX'],
            'lowercase g' => ['g-abcd1234'],
        ];
    }

    #[DataProvider('validTrackingIdProvider')]
    public function testResolveSettingsAcceptsValidTrackingIdFormats(string $id): void
    {
        $result = $this->service->resolveSettings(['enabled' => true], $id, null);

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame($id, $result->trackingId);
    }

    public function testResolveSettingsNeverUppercasesLowercaseId(): void
    {
        $result = $this->service->resolveSettings(['enabled' => true], 'g-abcd1234', null);

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame('g-abcd1234', $result->trackingId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTrackingIdProvider(): array
    {
        return [
            'universal analytics no longer supported' => ['UA-12345-6'],
            'no suffix' => ['G-'],
            'unknown prefix' => ['XYZ-1234'],
            'too short' => ['G-abc'],
            'injection shaped' => ["G-1234'); alert(1);//"],
        ];
    }

    #[DataProvider('malformedTrackingIdProvider')]
    public function testResolveSettingsRejectsMalformedTrackingId(string $id): void
    {
        $this->logger->expects($this->once())
            ->method('log')
            ->with('ERROR', $this->stringContains($id));

        $result = $this->service->resolveSettings(['enabled' => true], $id, null);

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------
    // debug flag
    // -----------------------------------------------------------------

    public function testResolveSettingsDebugDefaultsFalse(): void
    {
        $result = $this->service->resolveSettings(['enabled' => true], 'G-XXXXXXXXXX', null);

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertFalse($result->debug);
    }

    public function testResolveSettingsDebugTrueCarriedOntoDto(): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'debug' => true],
            'G-XXXXXXXXXX',
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertTrue($result->debug);
    }

    // -----------------------------------------------------------------
    // exclude normalization
    // -----------------------------------------------------------------

    public function testResolveSettingsExcludeAbsentDefaultsToEmptyArray(): void
    {
        $result = $this->service->resolveSettings(['enabled' => true], 'G-XXXXXXXXXX', null);

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame([], $result->exclude);
    }

    public function testResolveSettingsExcludeNonArrayDefaultsToEmptyArray(): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'exclude' => 'drafts/*'],
            'G-XXXXXXXXXX',
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame([], $result->exclude);
    }

    public function testResolveSettingsExcludeStripsLeadingSlashes(): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'exclude' => ['/drafts/*']],
            'G-XXXXXXXXXX',
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame(['drafts/*'], $result->exclude);
    }

    public function testResolveSettingsExcludeDropsEntriesEmptyAfterLtrim(): void
    {
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'exclude' => ['drafts/*', '', '/', '/private/*']],
            'G-XXXXXXXXXX',
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame(['drafts/*', 'private/*'], $result->exclude);
    }

    public function testResolveSettingsExcludeDropsWhitespaceOnlyEntries(): void
    {
        // A quoted YAML pattern can carry stray surrounding whitespace, which
        // would otherwise silently never fnmatch() a real path. Patterns are
        // trimmed before the leading '/' is stripped, so junk entries drop out
        // and ' drafts/* ' still matches.
        $result = $this->service->resolveSettings(
            ['enabled' => true, 'exclude' => [' /drafts/* ', '   ', 'private/*']],
            'G-XXXXXXXXXX',
            null,
        );

        $this->assertInstanceOf(AnalyticsSettings::class, $result);
        $this->assertSame(['drafts/*', 'private/*'], $result->exclude);
    }

    // -----------------------------------------------------------------
    // isExcluded()
    // -----------------------------------------------------------------

    /**
     * @param list<string> $exclude
     */
    private function settingsWithExclude(array $exclude): AnalyticsSettings
    {
        return new AnalyticsSettings('G-XXXXXXXXXX', false, $exclude);
    }

    public function testIsExcludedAlwaysFalseForEmptyExcludeList(): void
    {
        $settings = $this->settingsWithExclude([]);

        $this->assertFalse($this->service->isExcluded('drafts/anything.html', null, $settings));
    }

    public function testIsExcludedRelativizesAbsoluteOutputPathAgainstOutputDir(): void
    {
        $settings = $this->settingsWithExclude(['drafts/*']);

        $this->assertTrue($this->service->isExcluded(
            '/srv/site/public/drafts/x.html',
            '/srv/site/public',
            $settings,
        ));
    }

    public function testIsExcludedUsesPathAsIsWhenOutputDirIsNull(): void
    {
        $settings = $this->settingsWithExclude(['index.html']);

        $this->assertTrue($this->service->isExcluded('index.html', null, $settings));
    }

    public function testIsExcludedUsesPathAsIsWhenOutputDirDoesNotMatch(): void
    {
        $settings = $this->settingsWithExclude(['index.html']);

        $this->assertTrue($this->service->isExcluded('index.html', '/some/other/dir', $settings));
    }

    public function testIsExcludedGlobStarCrossesPathSeparator(): void
    {
        $settings = $this->settingsWithExclude(['drafts/*']);

        $this->assertTrue($this->service->isExcluded('drafts/2024/post.html', null, $settings));
    }

    public function testIsExcludedMatchingIsCaseSensitive(): void
    {
        $settings = $this->settingsWithExclude(['drafts/*']);

        $this->assertFalse($this->service->isExcluded('Drafts/x.html', null, $settings));
    }

    public function testIsExcludedNormalizesBackslashPathsToForwardSlashes(): void
    {
        $settings = $this->settingsWithExclude(['drafts/*']);

        $this->assertTrue($this->service->isExcluded('drafts\\2024\\post.html', null, $settings));
    }

    // -----------------------------------------------------------------
    // injectAnalytics()
    // -----------------------------------------------------------------

    public function testInjectAnalyticsInsertsBeforeClosingHeadTag(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = '<html><head><title>t</title></head><body></body></html>';

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertStringContainsString('googletagmanager.com', $result);
        $headPos = strpos($result, '</head>');
        $scriptPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($headPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($headPos, $scriptPos);
    }

    public function testInjectAnalyticsPreservesUppercaseHeadTagCasing(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = '<HTML><HEAD><TITLE>t</TITLE></HEAD><BODY></BODY></HTML>';

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertStringContainsString('</HEAD>', $result);
        $this->assertStringNotContainsString('</head>', $result);
    }

    public function testInjectAnalyticsOnlyAffectsFirstHeadOccurrence(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = <<<HTML
<html><head>
<script>var s = "</head>";</script>
</head><body></body></html>
HTML;

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertSame(1, substr_count($result, 'googletagmanager.com'));

        // The textual "</head>" inside the inline script's string literal
        // must be ignored: the snippet belongs after that script closes,
        // before the real </head>, and the script's own source must survive
        // untouched (no corrupted string literal, no stray injected text
        // inside the <script> element).
        $scriptClosePos = strpos($result, '</script>');
        $snippetPos = strpos($result, 'googletagmanager.com');
        $realHeadPos = strpos($result, '</head>', $scriptClosePos !== false ? $scriptClosePos : 0);

        $this->assertNotFalse($scriptClosePos);
        $this->assertNotFalse($snippetPos);
        $this->assertNotFalse($realHeadPos);
        $this->assertGreaterThan($scriptClosePos, $snippetPos);
        $this->assertLessThan($realHeadPos, $snippetPos);

        $this->assertStringContainsString('<script>var s = "</head>";</script>', $result);
    }

    public function testInjectAnalyticsSkipsHeadClosingTagInsideComment(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = <<<HTML
<html><head>
<!-- legacy: </head> -->
</head><body></body></html>
HTML;

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertSame(1, substr_count($result, 'googletagmanager.com'));
        $this->assertStringContainsString('<!-- legacy: </head> -->', $result);

        $commentPos = strpos($result, '<!-- legacy:');
        $snippetPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($commentPos);
        $this->assertNotFalse($snippetPos);
        $this->assertGreaterThan($commentPos, $snippetPos);
    }

    public function testInjectAnalyticsSkipsHeadClosingTagInsideStyleBlock(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = <<<HTML
<html><head>
<style>/* content: "</head>"; */</style>
</head><body></body></html>
HTML;

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertSame(1, substr_count($result, 'googletagmanager.com'));
        $this->assertStringContainsString('<style>/* content: "</head>"; */</style>', $result);

        $styleClosePos = strpos($result, '</style>');
        $snippetPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($styleClosePos);
        $this->assertNotFalse($snippetPos);
        $this->assertGreaterThan($styleClosePos, $snippetPos);
    }

    public function testInjectAnalyticsFallsBackToBodyWhenHeadOnlyExistsInsideScript(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = <<<HTML
<html><head>
<script>var s = "</head>";</script>
</head-typo><body></body></html>
HTML;

        $this->logger->expects($this->once())
            ->method('log')
            ->with('DEBUG', $this->stringContains('falling back to </body>'));

        $result = $this->service->injectAnalytics($content, $settings);

        $bodyPos = strpos($result, '</body>');
        $scriptPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($bodyPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($bodyPos, $scriptPos);
    }

    public function testInjectAnalyticsUnclosedScriptSwallowsHeadAndBodyAndAppendsAtEnd(): void
    {
        // An unclosed <script> has no closing tag, so (matching real HTML5
        // parsing) everything after it -- including both the </head> and the
        // </body> that follow -- is "inside" it and unsafe to insert into.
        // Both context-safe fallbacks correctly find nothing, so the final
        // append-at-end fallback (with its WARNING) is the only correct
        // outcome, not corruption of either tag.
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = <<<HTML
<html><head>
<script>var s = "</head>";
</head><body></body></html>
HTML;

        $this->logger->expects($this->once())
            ->method('log')
            ->with('WARNING', $this->stringContains('no </head> or </body> found'));

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertSame(1, substr_count($result, 'googletagmanager.com'));
        $this->assertStringStartsWith($content, $result);
        $scriptPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($scriptPos);
        $this->assertGreaterThan(strlen($content), $scriptPos);
    }

    public function testInjectAnalyticsOrdinaryDocumentUnaffectedByContextAwareScan(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = '<html><head><title>t</title></head><body></body></html>';

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertSame(1, substr_count($result, 'googletagmanager.com'));
        $headPos = strpos($result, '</head>');
        $scriptPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($headPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($headPos, $scriptPos);
    }

    public function testInjectAnalyticsFallsBackToBeforeBodyWhenNoHead(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = '<html><body>Content</body></html>';

        $this->logger->expects($this->once())
            ->method('log')
            ->with('DEBUG', $this->stringContains('falling back to </body>'));

        $result = $this->service->injectAnalytics($content, $settings);

        $bodyPos = strpos($result, '</body>');
        $scriptPos = strpos($result, 'googletagmanager.com');
        $this->assertNotFalse($bodyPos);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($bodyPos, $scriptPos);
    }

    public function testInjectAnalyticsAppendsAtEndWhenNeitherTagExists(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = 'plain text document';

        $this->logger->expects($this->once())
            ->method('log')
            ->with('WARNING', $this->stringContains('no </head> or </body> found'));

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertStringStartsWith('plain text document', $result);
        $this->assertStringContainsString('googletagmanager.com', $result);
    }

    public function testInjectAnalyticsDebugOffEmitsConfigCallWithoutDebugMode(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', false, []);
        $content = '<html><head></head><body></body></html>';

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertStringContainsString("gtag('config', 'G-XXXXXXXXXX');", $result);
        $this->assertStringNotContainsString('debug_mode', $result);
    }

    public function testInjectAnalyticsDebugOnEmitsDebugMode(): void
    {
        $settings = new AnalyticsSettings('G-XXXXXXXXXX', true, []);
        $content = '<html><head></head><body></body></html>';

        $result = $this->service->injectAnalytics($content, $settings);

        $this->assertStringContainsString(
            "gtag('config', 'G-XXXXXXXXXX', { 'debug_mode': true });",
            $result,
        );
    }
}
