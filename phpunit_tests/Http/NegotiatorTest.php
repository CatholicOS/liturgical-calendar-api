<?php

namespace LiturgicalCalendar\Tests\Http;

use PHPUnit\Framework\TestCase;
use LiturgicalCalendar\Api\Http\Negotiator;
use Nyholm\Psr7\ServerRequest;

/**
 * Unit tests for the Negotiator class, specifically testing
 * RFC 5646 (hyphen) to PHP locale (underscore) normalization.
 */
class NegotiatorTest extends TestCase
{
    /**
     * Test that Accept-Language with hyphens (RFC 5646 format) matches
     * supported locales with underscores (PHP locale format).
     */
    public function testPickLanguageNormalizesHyphensToUnderscores(): void
    {
        // Client sends RFC 5646 format with hyphens
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'fr-CA, en-US;q=0.9, en;q=0.8'
        ]);

        // Server supports PHP locale format with underscores
        $supported = ['en_US', 'fr_CA', 'es_ES'];

        $result = Negotiator::pickLanguage($request, $supported);

        // Should match fr_CA from the supported list
        $this->assertSame('fr_CA', $result, 'Expected fr-CA to match fr_CA');
    }

    /**
     * Test that Accept-Language with underscores also works.
     */
    public function testPickLanguageAcceptsUnderscores(): void
    {
        // Client sends PHP locale format with underscores
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'fr_CA, en_US;q=0.9, en;q=0.8'
        ]);

        // Server supports PHP locale format
        $supported = ['en_US', 'fr_CA', 'es_ES'];

        $result = Negotiator::pickLanguage($request, $supported);

        $this->assertSame('fr_CA', $result, 'Expected fr_CA to match fr_CA');
    }

    /**
     * Test that Latin locale (la-VA) matches la_VA.
     * This was the specific bug reported in issue #396.
     */
    public function testPickLanguageMatchesLatinLocale(): void
    {
        // Client sends RFC 5646 format: la-VA
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'la-VA'
        ]);

        // Server supports la_VA (PHP locale format)
        $supported = ['en_US', 'la_VA', 'it_IT'];

        $result = Negotiator::pickLanguage($request, $supported);

        $this->assertSame('la_VA', $result, 'Expected la-VA to match la_VA');
    }

    /**
     * Test that Latin locale with just language code (la) also works.
     */
    public function testPickLanguageMatchesLatinLanguageCode(): void
    {
        // Client sends just language code
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'la'
        ]);

        // Server supports both la and la_VA
        $supported = ['en', 'la', 'la_VA', 'it'];

        $result = Negotiator::pickLanguage($request, $supported);

        // Should match 'la' exactly (100 specificity) over 'la_VA' (prefix match)
        $this->assertSame('la', $result, 'Expected la to match la exactly');
    }

    /**
     * Test prefix matching with normalized separators.
     */
    public function testPickLanguagePrefixMatchingWithNormalizedSeparators(): void
    {
        // Client sends 'en' (should match en_US as a prefix)
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'en'
        ]);

        // Server only supports en_US
        $supported = ['en_US', 'fr_FR'];

        $result = Negotiator::pickLanguage($request, $supported);

        $this->assertSame('en_US', $result, 'Expected en to prefix-match en_US');
    }

    /**
     * Test that mixed hyphen/underscore formats work together.
     */
    public function testPickLanguageMixedFormats(): void
    {
        // Client sends mixed formats
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'pt-BR, en_US;q=0.9'
        ]);

        // Server supports mixed formats
        $supported = ['en-US', 'pt_BR', 'es-MX'];

        $result = Negotiator::pickLanguage($request, $supported);

        // Should match pt_BR (both get normalized to pt_br)
        $this->assertSame('pt_BR', $result, 'Expected pt-BR to match pt_BR');
    }

    /**
     * Test parseAcceptLanguage directly to verify normalization.
     */
    public function testParseAcceptLanguageNormalizesHyphensToUnderscores(): void
    {
        $parsed = Negotiator::parseAcceptLanguage('fr-CA, en-US;q=0.9, la-VA;q=0.8');

        // All tags should be normalized to lowercase with underscores
        $this->assertSame('fr_ca', $parsed[0]['tag'], 'fr-CA should be normalized to fr_ca');
        $this->assertSame('en_us', $parsed[1]['tag'], 'en-US should be normalized to en_us');
        $this->assertSame('la_va', $parsed[2]['tag'], 'la-VA should be normalized to la_va');
    }

    /**
     * Test that specificity counting uses underscores.
     * Specificity = substr_count(tag, '_') + 1
     */
    public function testParseAcceptLanguageSpecificity(): void
    {
        $parsed = Negotiator::parseAcceptLanguage('en, en-US, en-US-x-custom');

        // Specificity should be based on underscore count + 1
        // en (0 underscores) → specificity 1
        // en_us (1 underscore) → specificity 2
        // en_us_x_custom (3 underscores) → specificity 4
        $this->assertSame(1, $parsed[2]['specificity'], 'en should have specificity 1');
        $this->assertSame(2, $parsed[1]['specificity'], 'en_us should have specificity 2');
        $this->assertSame(4, $parsed[0]['specificity'], 'en_us_x_custom should have specificity 4');
    }

    /* ========================= Edge Case Tests ========================= */

    /**
     * Test that missing Accept-Language header returns fallback
     */
    public function testMissingAcceptLanguageReturnsFallback(): void
    {
        $request = new ServerRequest('GET', '/test', []);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'la');
        $this->assertSame('la', $result, 'Should return fallback when Accept-Language is missing');
    }

    /**
     * Test that empty Accept-Language header returns fallback
     */
    public function testEmptyAcceptLanguageReturnsFallback(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => ''
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'la');
        $this->assertSame('la', $result, 'Should return fallback when Accept-Language is empty');
    }

    /**
     * Test that missing Accept-Language header returns first supported when no fallback
     */
    public function testMissingAcceptLanguageReturnsFirstSupported(): void
    {
        $request = new ServerRequest('GET', '/test', []);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], null);
        $this->assertSame('en', $result, 'Should return first supported locale when no fallback provided');
    }

    /**
     * Test that wildcard (*) matches any supported language
     */
    public function testWildcardMatchesAnyLanguage(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => '*'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'en');
        $this->assertNotNull($result, 'Wildcard should match at least one supported locale');
        $this->assertContains($result, ['en', 'it', 'la'], 'Wildcard result should be one of supported locales');
    }

    /**
     * Test that specific language takes precedence over wildcard
     */
    public function testSpecificLanguagePreferredOverWildcard(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'la, *;q=0.9'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'en');
        $this->assertSame('la', $result, 'Specific language should be preferred over wildcard with lower q');
    }

    /**
     * Test that wildcard with higher q wins (q-value takes precedence over specificity)
     * This is RFC 9110 compliant behavior: quality is the primary sorting factor
     */
    public function testWildcardWithHigherQualityWins(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => '*;q=1.0, it;q=0.5'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'en');
        // Wildcard with q=1.0 wins over specific it with q=0.5 (q is primary sort factor)
        // Result will be first supported locale that matches wildcard
        $this->assertContains($result, ['en', 'it', 'la'], 'Should match one of supported via wildcard');
    }

    /**
     * Test that specificity wins when q-values are equal
     */
    public function testSpecificityWinsWhenQualityEqual(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'it;q=1.0, *;q=1.0'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'en');
        // When q is equal, specificity should break the tie (it has specificity 1, * has 0)
        $this->assertSame('it', $result, 'Specific match should win over wildcard when q-values are equal');
    }

    /**
     * Test combined quality and specificity: more specific region code preferred
     */
    public function testQualityAndSpecificityCombined(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'en-US, en;q=0.9'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'en-US', 'it'], 'en');
        $this->assertSame('en-US', $result, 'More specific en-US should be preferred over generic en');
    }

    /**
     * Test that higher quality beats specificity
     */
    public function testHigherQualityBeatsSpecificity(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'en-US;q=0.5, en;q=1.0'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'en-US', 'it'], 'it');
        $this->assertSame('en', $result, 'Higher quality generic en should beat lower quality en-US');
    }

    /**
     * Test Latin with region code and quality parameters
     */
    public function testLatinWithQualityParameters(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'la-VA;q=1.0, la;q=0.8, en;q=0.5'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'la', 'la-VA'], 'en');
        $this->assertSame('la-VA', $result, 'la-VA with highest quality should be selected');
    }

    /**
     * Test that when both la and la-VA are supported, la-VA is preferred
     */
    public function testLatinWithRegionPreferred(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'la-VA, la;q=0.9'
        ]);
        $result  = Negotiator::pickLanguage($request, ['la', 'la-VA'], 'en');
        $this->assertSame('la-VA', $result, 'Should prefer more specific la-VA over generic la');
    }

    /**
     * Test complex Accept-Language with wildcards and quality
     */
    public function testComplexAcceptLanguageWithWildcard(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'it-IT, la;q=0.9, en-US;q=0.8, *;q=0.1'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'la', 'de'], 'en');
        $this->assertSame('la', $result, 'la with q=0.9 should be preferred when it-IT not available');
    }

    /**
     * Test that wildcard fallback works when no specific matches found
     */
    public function testWildcardFallbackWhenNoSpecificMatch(): void
    {
        $request = new ServerRequest('GET', '/test', [
            'Accept-Language' => 'ja-JP, zh-CN;q=0.9, *;q=0.1'
        ]);
        $result  = Negotiator::pickLanguage($request, ['en', 'it', 'la'], 'en');
        // Should match via wildcard since ja-JP and zh-CN are not supported
        $this->assertNotNull($result, 'Wildcard should provide fallback match');
        $this->assertContains($result, ['en', 'it', 'la'], 'Result should be one of supported locales');
    }
}
