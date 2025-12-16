<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\Enum\LitLocale;

/**
 * Locale-aware date formatting utility.
 *
 * Provides localized date formatting for liturgical calendar displays,
 * handling Latin, English, Italian, and other locales with appropriate
 * formatting patterns.
 */
class LocaleDateFormatter
{
    private \IntlDateFormatter $dayAndMonth;
    private \IntlDateFormatter $dayOfTheWeek;
    private string $locale;
    private string $primaryLanguage;

    /**
     * Create a new LocaleDateFormatter instance.
     *
     * @param string $locale The locale to use for formatting (e.g., 'en_US', 'it_IT', 'la')
     * @throws \RuntimeException If IntlDateFormatter creation fails
     */
    public function __construct(string $locale)
    {
        $this->locale          = $locale;
        $this->primaryLanguage = str_contains($locale, '_')
            ? substr($locale, 0, (int) strpos($locale, '_'))
            : $locale;

        $this->createFormatters();
    }

    /**
     * Create the IntlDateFormatter instances for the current locale.
     *
     * @throws \RuntimeException If formatter creation fails
     */
    private function createFormatters(): void
    {
        $dayAndMonth = \IntlDateFormatter::create(
            $this->primaryLanguage,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'd MMMM'
        );

        $dayOfTheWeek = \IntlDateFormatter::create(
            $this->primaryLanguage,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        if (null === $dayAndMonth || null === $dayOfTheWeek) {
            throw new \RuntimeException(
                sprintf('Failed to create IntlDateFormatter for locale "%s"', $this->locale)
            );
        }

        $this->dayAndMonth  = $dayAndMonth;
        $this->dayOfTheWeek = $dayOfTheWeek;
    }

    /**
     * Get the current locale.
     *
     * @return string The current locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Get the primary language from the locale.
     *
     * @return string The primary language code (e.g., 'en' from 'en_US')
     */
    public function getPrimaryLanguage(): string
    {
        return $this->primaryLanguage;
    }

    /**
     * Format a date according to the current locale.
     *
     * Handles Latin (using LatinUtils::LATIN_MONTHS), English (F jS format),
     * and other locales (using dayAndMonth IntlDateFormatter).
     *
     * @param DateTime $date The date to format
     * @return string The formatted date string
     */
    public function formatLocalizedDate(DateTime $date): string
    {
        if (str_starts_with($this->locale, LitLocale::LATIN_PRIMARY_LANGUAGE)) {
            return $date->format('j') . ' ' . LatinUtils::LATIN_MONTHS[(int) $date->format('n')];
        }
        if (str_starts_with($this->locale, 'en')) {
            return $date->format('F jS');
        }
        $formatted = $this->dayAndMonth->format($date->format('U'));
        return $formatted !== false ? $formatted : $date->format('j/n');
    }

    /**
     * Get the localized date identifier for Christmas weekday naming.
     *
     * Returns different formats based on locale, tailored for use in Christmas weekday names:
     * - Latin: Day of the week (e.g., "Feria II") from LatinUtils::LATIN_DAYOFTHEWEEK
     * - Italian: Day and month (e.g., "3 gennaio") using dayAndMonth formatter
     * - Other locales: Day of the week using dayOfTheWeek formatter
     *
     * Note: This is specifically designed for formatChristmasWeekdayName() usage,
     * where Italian uses day+month format ("Feria propria del 3 gennaio").
     *
     * @param DateTime $dateTime The date to format
     * @return string The localized date identifier for Christmas weekday naming
     */
    public function getChristmasWeekdayIdentifier(DateTime $dateTime): string
    {
        if (str_starts_with($this->locale, LitLocale::LATIN_PRIMARY_LANGUAGE)) {
            return LatinUtils::LATIN_DAYOFTHEWEEK[$dateTime->format('w')];
        }
        if (str_starts_with($this->locale, 'it')) {
            $formatted = $this->dayAndMonth->format($dateTime->format('U'));
            return Utilities::ucfirst($formatted !== false ? $formatted : $dateTime->format('l'));
        }
        $formatted = $this->dayOfTheWeek->format($dateTime->format('U'));
        return Utilities::ucfirst($formatted !== false ? $formatted : $dateTime->format('l'));
    }

    /**
     * Format the name of a Christmas weekday according to the current locale.
     *
     * Handles Latin ("X temporis Nativitatis"), Italian ("Feria propria del X"),
     * and other locales using gettext translation.
     *
     * @param string $dateIdentifier The localized date identifier from getChristmasWeekdayIdentifier().
     *                               For Latin/other locales: day of week. For Italian: day+month.
     * @return string The formatted Christmas weekday name
     */
    public function formatChristmasWeekdayName(string $dateIdentifier): string
    {
        return str_starts_with($this->locale, LitLocale::LATIN_PRIMARY_LANGUAGE)
            ? sprintf('%s temporis Nativitatis', $dateIdentifier)
            : ( str_starts_with($this->locale, 'it')
                ? sprintf('Feria propria del %s', $dateIdentifier)
                : sprintf(
                    /**translators: Christmas weekday name pattern */
                    _('%s - Christmas Weekday'),
                    $dateIdentifier
                )
            );
    }

    /**
     * Get the day and month formatter.
     *
     * Useful for testing or when direct access to the formatter is needed.
     *
     * @return \IntlDateFormatter The day and month formatter
     */
    public function getDayAndMonthFormatter(): \IntlDateFormatter
    {
        return $this->dayAndMonth;
    }

    /**
     * Get the day of the week formatter.
     *
     * Useful for testing or when direct access to the formatter is needed.
     *
     * @return \IntlDateFormatter The day of the week formatter
     */
    public function getDayOfTheWeekFormatter(): \IntlDateFormatter
    {
        return $this->dayOfTheWeek;
    }

    /**
     * Set the day and month formatter.
     *
     * Primarily for testing purposes to inject mock formatters.
     *
     * @param \IntlDateFormatter $formatter The formatter to use
     * @return self
     */
    public function setDayAndMonthFormatter(\IntlDateFormatter $formatter): self
    {
        $this->dayAndMonth = $formatter;
        return $this;
    }

    /**
     * Set the day of the week formatter.
     *
     * Primarily for testing purposes to inject mock formatters.
     *
     * @param \IntlDateFormatter $formatter The formatter to use
     * @return self
     */
    public function setDayOfTheWeekFormatter(\IntlDateFormatter $formatter): self
    {
        $this->dayOfTheWeek = $formatter;
        return $this;
    }
}
