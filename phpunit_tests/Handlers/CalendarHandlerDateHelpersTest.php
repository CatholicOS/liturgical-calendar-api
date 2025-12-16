<?php

namespace LiturgicalCalendar\Tests\Handlers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\LatinUtils;
use ReflectionClass;
use ReflectionMethod;
use IntlDateFormatter;

/**
 * Unit tests for the CalendarHandler date helper methods.
 *
 * Tests formatLocalizedDate(), getChristmasWeekdayIdentifier(), and formatChristmasWeekdayName()
 * for representative locales (Latin, English, Italian, and other).
 */
class CalendarHandlerDateHelpersTest extends TestCase
{
    private CalendarHandler $handler;
    private ReflectionMethod $formatLocalizedDate;
    private ReflectionMethod $getChristmasWeekdayIdentifier;
    private ReflectionMethod $formatChristmasWeekdayName;

    /** @var string Original RUNTIME_LOCALE to restore after tests */
    private string $originalRuntimeLocale;

    /** @var string Original PRIMARY_LANGUAGE to restore after tests */
    private string $originalPrimaryLanguage;

    protected function setUp(): void
    {
        parent::setUp();

        // Save original values to restore after tests
        $this->originalRuntimeLocale   = LitLocale::$RUNTIME_LOCALE;
        $this->originalPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;

        // Create handler instance
        $this->handler = new CalendarHandler();

        // Get reflection methods for private methods
        $reflection = new ReflectionClass(CalendarHandler::class);

        $this->formatLocalizedDate = $reflection->getMethod('formatLocalizedDate');
        $this->formatLocalizedDate->setAccessible(true);

        $this->getChristmasWeekdayIdentifier = $reflection->getMethod('getChristmasWeekdayIdentifier');
        $this->getChristmasWeekdayIdentifier->setAccessible(true);

        $this->formatChristmasWeekdayName = $reflection->getMethod('formatChristmasWeekdayName');
        $this->formatChristmasWeekdayName->setAccessible(true);
    }

    protected function tearDown(): void
    {
        // Restore original values
        LitLocale::$RUNTIME_LOCALE   = $this->originalRuntimeLocale;
        LitLocale::$PRIMARY_LANGUAGE = $this->originalPrimaryLanguage;

        parent::tearDown();
    }

    /**
     * Set up the handler with formatters for a specific locale.
     *
     * This method sets the locale properties and creates IntlDateFormatters
     * using reflection to inject them into the handler.
     *
     * @param string $locale The locale to use
     */
    private function setUpLocale(string $locale): void
    {
        // Set the runtime locale
        LitLocale::$RUNTIME_LOCALE   = $locale;
        LitLocale::$PRIMARY_LANGUAGE = str_contains($locale, '_')
            ? substr($locale, 0, strpos($locale, '_'))
            : $locale;

        // Create formatters using the primary language
        $dayAndMonth = IntlDateFormatter::create(
            LitLocale::$PRIMARY_LANGUAGE,
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::GREGORIAN,
            'd MMMM'
        );

        $dayOfTheWeek = IntlDateFormatter::create(
            LitLocale::$PRIMARY_LANGUAGE,
            IntlDateFormatter::FULL,
            IntlDateFormatter::NONE,
            'UTC',
            IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        // Inject formatters via reflection
        $reflection = new ReflectionClass(CalendarHandler::class);

        $dayAndMonthProp = $reflection->getProperty('dayAndMonth');
        $dayAndMonthProp->setAccessible(true);
        $dayAndMonthProp->setValue($this->handler, $dayAndMonth);

        $dayOfTheWeekProp = $reflection->getProperty('dayOfTheWeek');
        $dayOfTheWeekProp->setAccessible(true);
        $dayOfTheWeekProp->setValue($this->handler, $dayOfTheWeek);
    }

    /* ========================= formatLocalizedDate Tests ========================= */

    /**
     * Test formatLocalizedDate for Latin locale.
     *
     * Latin format should be: "j + LATIN_MONTHS[n]" (e.g., "25 December" → "25 December")
     */
    public function testFormatLocalizedDateLatin(): void
    {
        $this->setUpLocale('la');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        $expected = '25 ' . LatinUtils::LATIN_MONTHS[12];
        $this->assertSame($expected, $result, 'Latin date should use LATIN_MONTHS array');
        $this->assertSame('25 December', $result);
    }

    /**
     * Test formatLocalizedDate for Latin with region code (la_VA).
     */
    public function testFormatLocalizedDateLatinWithRegion(): void
    {
        $this->setUpLocale('la_VA');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-01-06', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        $expected = '6 ' . LatinUtils::LATIN_MONTHS[1];
        $this->assertSame($expected, $result);
        $this->assertSame('6 Ianuarius', $result);
    }

    /**
     * Test formatLocalizedDate for English locale.
     *
     * English format should be: "F jS" (e.g., "December 25th")
     */
    public function testFormatLocalizedDateEnglish(): void
    {
        $this->setUpLocale('en');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        $this->assertSame('December 25th', $result);
    }

    /**
     * Test formatLocalizedDate for English with region code (en_US).
     */
    public function testFormatLocalizedDateEnglishUS(): void
    {
        $this->setUpLocale('en_US');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-01-01', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        $this->assertSame('January 1st', $result);
    }

    /**
     * Test formatLocalizedDate for Italian locale (uses IntlDateFormatter).
     */
    public function testFormatLocalizedDateItalian(): void
    {
        $this->setUpLocale('it_IT');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        // Italian format from IntlDateFormatter with 'd MMMM' pattern
        $this->assertSame('25 dicembre', $result);
    }

    /**
     * Test formatLocalizedDate for French locale (uses IntlDateFormatter).
     */
    public function testFormatLocalizedDateFrench(): void
    {
        $this->setUpLocale('fr_FR');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        // French format from IntlDateFormatter with 'd MMMM' pattern
        $this->assertSame('25 décembre', $result);
    }

    /**
     * Test formatLocalizedDate for German locale (uses IntlDateFormatter).
     */
    public function testFormatLocalizedDateGerman(): void
    {
        $this->setUpLocale('de_DE');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        // German format from IntlDateFormatter with 'd MMMM' pattern
        $this->assertSame('25 Dezember', $result);
    }

    /* ========================= getChristmasWeekdayIdentifier Tests ========================= */

    /**
     * Test getChristmasWeekdayIdentifier for Latin locale.
     *
     * Latin should return day of week from LATIN_DAYOFTHEWEEK array.
     */
    public function testGetChristmasWeekdayIdentifierLatin(): void
    {
        $this->setUpLocale('la');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        $expected = LatinUtils::LATIN_DAYOFTHEWEEK[(int) $date->format('w')];
        $this->assertSame($expected, $result);
        $this->assertSame('Feria II', $result); // Monday = Feria II
    }

    /**
     * Test getChristmasWeekdayIdentifier for Latin on Sunday.
     */
    public function testGetChristmasWeekdayIdentifierLatinSunday(): void
    {
        $this->setUpLocale('la_VA');

        // Sunday, December 29, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-29', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        $this->assertSame('Dominica', $result); // Sunday = Dominica
    }

    /**
     * Test getChristmasWeekdayIdentifier for Italian locale.
     *
     * Italian should return day and month (e.g., "30 dicembre").
     */
    public function testGetChristmasWeekdayIdentifierItalian(): void
    {
        $this->setUpLocale('it_IT');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        // Italian uses dayAndMonth formatter, then ucfirst
        $this->assertSame('30 dicembre', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier for English locale.
     *
     * English (and other non-Latin, non-Italian) should return day of week.
     */
    public function testGetChristmasWeekdayIdentifierEnglish(): void
    {
        $this->setUpLocale('en_US');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        // English uses dayOfTheWeek formatter with ucfirst
        $this->assertSame('Monday', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier for French locale.
     */
    public function testGetChristmasWeekdayIdentifierFrench(): void
    {
        $this->setUpLocale('fr_FR');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        // French uses dayOfTheWeek formatter with ucfirst
        $this->assertSame('Lundi', $result);
    }

    /* ========================= formatChristmasWeekdayName Tests ========================= */

    /**
     * Test formatChristmasWeekdayName for Latin locale.
     *
     * Latin format: "{dateIdentifier} temporis Nativitatis"
     */
    public function testFormatChristmasWeekdayNameLatin(): void
    {
        $this->setUpLocale('la');

        $result = $this->formatChristmasWeekdayName->invoke($this->handler, 'Feria II');

        $this->assertSame('Feria II temporis Nativitatis', $result);
    }

    /**
     * Test formatChristmasWeekdayName for Latin with region code.
     */
    public function testFormatChristmasWeekdayNameLatinWithRegion(): void
    {
        $this->setUpLocale('la_VA');

        $result = $this->formatChristmasWeekdayName->invoke($this->handler, 'Dominica');

        $this->assertSame('Dominica temporis Nativitatis', $result);
    }

    /**
     * Test formatChristmasWeekdayName for Italian locale.
     *
     * Italian format: "Feria propria del {dateIdentifier}"
     */
    public function testFormatChristmasWeekdayNameItalian(): void
    {
        $this->setUpLocale('it_IT');

        $result = $this->formatChristmasWeekdayName->invoke($this->handler, '30 dicembre');

        $this->assertSame('Feria propria del 30 dicembre', $result);
    }

    /**
     * Test formatChristmasWeekdayName for English locale.
     *
     * English format (via gettext): "{dateIdentifier} - Christmas Weekday"
     */
    public function testFormatChristmasWeekdayNameEnglish(): void
    {
        $this->setUpLocale('en_US');

        $result = $this->formatChristmasWeekdayName->invoke($this->handler, 'Monday');

        // Without gettext loaded, falls back to the format string
        $this->assertSame('Monday - Christmas Weekday', $result);
    }

    /**
     * Test formatChristmasWeekdayName for French locale.
     *
     * French uses gettext translation pattern.
     */
    public function testFormatChristmasWeekdayNameFrench(): void
    {
        $this->setUpLocale('fr_FR');

        $result = $this->formatChristmasWeekdayName->invoke($this->handler, 'Lundi');

        // Without gettext loaded with French translations, falls back to pattern
        $this->assertStringContainsString('Lundi', $result);
    }

    /* ========================= Edge Cases and Integration Tests ========================= */

    /**
     * Test full flow: getChristmasWeekdayIdentifier → formatChristmasWeekdayName for Latin.
     */
    public function testChristmasWeekdayFullFlowLatin(): void
    {
        $this->setUpLocale('la');

        // Tuesday, December 31, 2024
        $date = new \LiturgicalCalendar\Api\DateTime('2024-12-31', new \DateTimeZone('UTC'));

        $identifier = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);
        $name       = $this->formatChristmasWeekdayName->invoke($this->handler, $identifier);

        $this->assertSame('Feria III', $identifier);
        $this->assertSame('Feria III temporis Nativitatis', $name);
    }

    /**
     * Test full flow: getChristmasWeekdayIdentifier → formatChristmasWeekdayName for Italian.
     */
    public function testChristmasWeekdayFullFlowItalian(): void
    {
        $this->setUpLocale('it_IT');

        // Tuesday, December 31, 2024
        $date = new \LiturgicalCalendar\Api\DateTime('2024-12-31', new \DateTimeZone('UTC'));

        $identifier = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);
        $name       = $this->formatChristmasWeekdayName->invoke($this->handler, $identifier);

        $this->assertSame('31 dicembre', $identifier);
        $this->assertSame('Feria propria del 31 dicembre', $name);
    }

    /**
     * Test full flow: getChristmasWeekdayIdentifier → formatChristmasWeekdayName for English.
     */
    public function testChristmasWeekdayFullFlowEnglish(): void
    {
        $this->setUpLocale('en_US');

        // Tuesday, December 31, 2024
        $date = new \LiturgicalCalendar\Api\DateTime('2024-12-31', new \DateTimeZone('UTC'));

        $identifier = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);
        $name       = $this->formatChristmasWeekdayName->invoke($this->handler, $identifier);

        $this->assertSame('Tuesday', $identifier);
        $this->assertSame('Tuesday - Christmas Weekday', $name);
    }

    /**
     * Test formatLocalizedDate with various dates across different months.
     */
    #[DataProvider('dateProvider')]
    public function testFormatLocalizedDateVariousDates(
        string $locale,
        string $dateString,
        string $expectedPattern
    ): void {
        $this->setUpLocale($locale);

        $date   = new \LiturgicalCalendar\Api\DateTime($dateString, new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        $this->assertMatchesRegularExpression($expectedPattern, $result);
    }

    /**
     * Data provider for various date formatting tests.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function dateProvider(): array
    {
        return [
            'Latin January'            => ['la', '2024-01-15', '/^15 Ianuarius$/'],
            'Latin March'              => ['la', '2024-03-19', '/^19 Martius$/'],
            'English March'            => ['en', '2024-03-03', '/^March 3rd$/'],
            'English with ordinal 1st' => ['en', '2024-05-01', '/^May 1st$/'],
            'English with ordinal 2nd' => ['en', '2024-06-02', '/^June 2nd$/'],
            'Italian June'             => ['it', '2024-06-24', '/^24 giugno$/'],
        ];
    }

    /* ========================= IntlDateFormatter Fallback Tests ========================= */

    /**
     * Test formatLocalizedDate fallback when IntlDateFormatter::format() returns false.
     *
     * When the formatter fails, formatLocalizedDate should fall back to 'j/n' format.
     */
    public function testFormatLocalizedDateFormatterFallback(): void
    {
        $this->setUpLocale('it_IT');

        // Inject a mock formatter that returns false
        $mockFormatter = $this->createMock(IntlDateFormatter::class);
        $mockFormatter->method('format')->willReturn(false);

        $reflection      = new ReflectionClass(CalendarHandler::class);
        $dayAndMonthProp = $reflection->getProperty('dayAndMonth');
        $dayAndMonthProp->setAccessible(true);
        $dayAndMonthProp->setValue($this->handler, $mockFormatter);

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $this->formatLocalizedDate->invoke($this->handler, $date);

        // Should fall back to 'j/n' format
        $this->assertSame('25/12', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier fallback for Italian when IntlDateFormatter fails.
     *
     * When dayAndMonth formatter fails, Italian should fall back to DateTime::format('l').
     */
    public function testGetChristmasWeekdayIdentifierItalianFormatterFallback(): void
    {
        $this->setUpLocale('it_IT');

        // Inject a mock formatter that returns false
        $mockFormatter = $this->createMock(IntlDateFormatter::class);
        $mockFormatter->method('format')->willReturn(false);

        $reflection      = new ReflectionClass(CalendarHandler::class);
        $dayAndMonthProp = $reflection->getProperty('dayAndMonth');
        $dayAndMonthProp->setAccessible(true);
        $dayAndMonthProp->setValue($this->handler, $mockFormatter);

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        // Should fall back to DateTime::format('l') with ucfirst
        $this->assertSame('Monday', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier fallback for non-Latin/non-Italian when formatter fails.
     *
     * When dayOfTheWeek formatter fails, other locales should fall back to DateTime::format('l').
     */
    public function testGetChristmasWeekdayIdentifierOtherLocaleFormatterFallback(): void
    {
        $this->setUpLocale('fr_FR');

        // Inject a mock formatter that returns false
        $mockFormatter = $this->createMock(IntlDateFormatter::class);
        $mockFormatter->method('format')->willReturn(false);

        $reflection       = new ReflectionClass(CalendarHandler::class);
        $dayOfTheWeekProp = $reflection->getProperty('dayOfTheWeek');
        $dayOfTheWeekProp->setAccessible(true);
        $dayOfTheWeekProp->setValue($this->handler, $mockFormatter);

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $this->getChristmasWeekdayIdentifier->invoke($this->handler, $date);

        // Should fall back to DateTime::format('l') with ucfirst
        $this->assertSame('Monday', $result);
    }
}
