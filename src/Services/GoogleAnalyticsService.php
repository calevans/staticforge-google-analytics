<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Services;

use Calevans\StaticForgeGoogleAnalytics\Models\AnalyticsSettings;
use EICC\Utils\Log;

class GoogleAnalyticsService
{
    /**
     * Acceptance is case-insensitive but the value is injected byte-for-byte
     * as configured (never uppercased). This regex is the output-escaping
     * control for buildSnippet(): resolveSettings() runs it before the ID is
     * ever interpolated into a URL query param and two JS string literals,
     * so passing it proves the ID is [A-Za-z0-9-] only and cannot carry a
     * quote, angle bracket, or backslash. Not a typo-catcher — do not relax
     * it, and do not additionally json_encode()/rawurlencode() the ID; that
     * would mask a validator regression rather than surface it. The /D
     * modifier is load-bearing: without it PHP's $ also matches before a
     * single trailing newline, so the guarantee would silently depend on
     * resolveTrackingId() trimming rather than on this pattern.
     */
    private const TRACKING_ID_PATTERN = '/^(?:G|GTM)-[A-Za-z0-9]{4,20}$/iD';

    private const KILL_SWITCH_VALUES = ['false', '0', 'off', 'no'];

    public function __construct(private readonly Log $logger)
    {
    }

    /**
     * @param array<string, mixed> $gaConfig
     */
    public function resolveSettings(array $gaConfig, ?string $envId, ?string $envEnabled): ?AnalyticsSettings
    {
        if ($this->isKillSwitchEngaged($envEnabled)) {
            $this->logger->log('INFO', 'GoogleAnalytics: injection suppressed by GOOGLE_ANALYTICS_ENABLED');
            return null;
        }

        if (empty($gaConfig['enabled'])) {
            return null;
        }

        $trackingId = $this->resolveTrackingId($envId, $gaConfig['tracking_id'] ?? null);

        if ($trackingId === null) {
            $this->logger->log(
                'WARNING',
                'GoogleAnalytics: enabled but no tracking id configured ' .
                '(GOOGLE_ANALYTICS_ID or google_analytics.tracking_id)'
            );
            return null;
        }

        if (preg_match(self::TRACKING_ID_PATTERN, $trackingId) !== 1) {
            $this->logger->log('ERROR', "GoogleAnalytics: malformed tracking id '{$trackingId}'");
            return null;
        }

        return new AnalyticsSettings(
            trackingId: $trackingId,
            debug: (bool) ($gaConfig['debug'] ?? false),
            exclude: $this->normalizeExclude($gaConfig['exclude'] ?? []),
        );
    }

    public function isExcluded(?string $outputPath, ?string $outputDir, AnalyticsSettings $settings): bool
    {
        if ($settings->exclude === []) {
            return false;
        }

        $relativePath = $this->relativizePath($outputPath ?? '', $outputDir);

        foreach ($settings->exclude as $pattern) {
            if (fnmatch($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    public function injectAnalytics(string $content, AnalyticsSettings $settings): string
    {
        $snippet = $this->buildSnippet($settings);

        $pos = $this->findInsertionOffset($content, '</head>');
        if ($pos !== false) {
            return substr_replace($content, "\n" . $snippet . "\n", $pos, 0);
        }

        $pos = $this->findInsertionOffset($content, '</body>');
        if ($pos !== false) {
            $this->logger->log('DEBUG', 'GoogleAnalytics: no </head> found, falling back to </body>');
            return substr_replace($content, "\n" . $snippet . "\n", $pos, 0);
        }

        // Unlike SocialMetadata (which skips injection outright when there is
        // no </head>, because an og: meta tag is only valid inside <head>), a
        // gtag <script> is valid anywhere in the document, so GA falls back
        // to appending at the end instead of dropping the snippet — this is
        // existing GA behavior that backward compatibility requires keeping.
        $this->logger->log(
            'WARNING',
            'GoogleAnalytics: no </head> or </body> found, appending snippet to end of document'
        );
        return $content . "\n" . $snippet;
    }

    /**
     * Locates the first occurrence of $tag that is not inside a
     * <script>...</script> region, a <style>...</style> region, or an
     * <!-- ... --> comment, so injection never lands inside a string
     * literal, style rule, or dead comment. A single forward scan over
     * the tokens that can open/close those regions (plus the target tag
     * itself), tracking which region (if any) is currently open.
     */
    private function findInsertionOffset(string $content, string $tag): int|false
    {
        $pattern = '/<!--|-->|<script\b[^>]*>|<\/script\s*>|<style\b[^>]*>|<\/style\s*>|'
            . preg_quote($tag, '/') . '/i';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        /** @var list<array{0: string, 1: int}> $tokens */
        $tokens = $matches[0];

        $inScript = false;
        $inStyle = false;
        $inComment = false;

        foreach ($tokens as [$token, $offset]) {
            $lower = strtolower($token);

            if ($inComment) {
                if ($lower === '-->') {
                    $inComment = false;
                }
                continue;
            }

            if ($inScript) {
                if (str_starts_with($lower, '</script')) {
                    $inScript = false;
                }
                continue;
            }

            if ($inStyle) {
                if (str_starts_with($lower, '</style')) {
                    $inStyle = false;
                }
                continue;
            }

            if ($lower === '<!--') {
                $inComment = true;
                continue;
            }

            if (str_starts_with($lower, '<script')) {
                $inScript = true;
                continue;
            }

            if (str_starts_with($lower, '<style')) {
                $inStyle = true;
                continue;
            }

            // Only the target tag can reach here: it's a live match.
            return $offset;
        }

        return false;
    }

    private function buildSnippet(AnalyticsSettings $settings): string
    {
        $trackingId = $settings->trackingId;

        $configCall = $settings->debug
            ? "gtag('config', '{$trackingId}', { 'debug_mode': true });"
            : "gtag('config', '{$trackingId}');";

        return <<<HTML
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$trackingId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  {$configCall}
</script>
HTML;
    }

    private function isKillSwitchEngaged(?string $envEnabled): bool
    {
        if ($envEnabled === null) {
            return false;
        }

        $normalized = strtolower(trim($envEnabled));

        return in_array($normalized, self::KILL_SWITCH_VALUES, true);
    }

    private function resolveTrackingId(?string $envId, mixed $configId): ?string
    {
        $envId = is_string($envId) ? trim($envId) : '';
        if ($envId !== '') {
            return $envId;
        }

        $configId = is_string($configId) ? trim($configId) : '';

        return $configId !== '' ? $configId : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeExclude(mixed $exclude): array
    {
        if (!is_array($exclude)) {
            return [];
        }

        $normalized = [];
        foreach ($exclude as $pattern) {
            $pattern = ltrim(trim((string) $pattern), '/');
            if ($pattern === '') {
                continue;
            }
            $normalized[] = $pattern;
        }

        return $normalized;
    }

    private function relativizePath(string $outputPath, ?string $outputDir): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $outputPath), '/');
        $normalizedDir = $outputDir !== null ? ltrim(str_replace('\\', '/', $outputDir), '/') : '';

        // Compare against the directory plus its separator so OUTPUT_DIR
        // 'site/public' does not also claim 'site/public-old/...' and strip a
        // prefix that was never there, which would make exclude patterns fail open.
        $normalizedDir = rtrim($normalizedDir, '/');
        if ($normalizedDir !== '' && str_starts_with($normalizedPath, $normalizedDir . '/')) {
            return substr($normalizedPath, strlen($normalizedDir) + 1);
        }

        return $normalizedPath;
    }
}
