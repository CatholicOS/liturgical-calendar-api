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
}
