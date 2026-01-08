<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitSeason: string
{
    use EnumToArrayTrait;

    case ADVENT         = 'ADVENT';
    case CHRISTMAS      = 'CHRISTMAS';
    case LENT           = 'LENT';
    case EASTER_TRIDUUM = 'EASTER_TRIDUUM';
    case EASTER         = 'EASTER';
    case ORDINARY_TIME  = 'ORDINARY_TIME';

    /**
     * Patterns for detecting ADVENT events.
     */
    private const array ADVENT_PATTERNS = [
        '/^Advent\d/',
        '/^AdventWeekday/',
    ];

    /**
     * Patterns for detecting CHRISTMAS events.
     */
    private const array CHRISTMAS_PATTERNS = [
        '/^Christmas/',
        '/^HolyFamily$/',
        '/^Epiphany/',
        '/^BaptismLord$/',
        '/^MaryMotherOfGod$/',
        '/^DayAfterEpiphany/',
    ];

    /**
     * Patterns for detecting LENT events.
     */
    private const array LENT_PATTERNS = [
        '/^AshWednesday$/',
        '/^(Friday|Saturday|Thursday)AfterAshWednesday$/',
        '/^Lent\d/',
        '/^LentWeekday\d/',
        '/^PalmSun$/',
        '/^(Mon|Tue|Wed)HolyWeek$/',
        '/^HolyThursChrism$/',
    ];

    /**
     * Patterns for detecting EASTER_TRIDUUM events.
     */
    private const array EASTER_TRIDUUM_PATTERNS = [
        '/^HolyThurs$/',
        '/^GoodFri$/',
        '/^EasterVigil$/',
    ];

    /**
     * Patterns for detecting EASTER events.
     */
    private const array EASTER_PATTERNS = [
        '/^Easter\d*$/',
        '/^(Mon|Tue|Wed|Thu|Fri|Sat)OctaveEaster$/',
        '/^EasterWeekday\d/',
        '/^Ascension$/',
        '/^Pentecost$/',
    ];

    /**
     * Determine the liturgical season for a given temporale event key.
     *
     * @param string $eventKey The temporale event key.
     * @return self The liturgical season for the event.
     */
    public static function forEventKey(string $eventKey): self
    {
        foreach (self::ADVENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::ADVENT;
            }
        }
        foreach (self::CHRISTMAS_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::CHRISTMAS;
            }
        }
        foreach (self::LENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::LENT;
            }
        }
        foreach (self::EASTER_TRIDUUM_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::EASTER_TRIDUUM;
            }
        }
        foreach (self::EASTER_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::EASTER;
            }
        }

        // Default: Ordinary Time (includes OrdSunday*, OrdWeekday*, solemnities like Trinity, CorpusChristi, etc.)
        return self::ORDINARY_TIME;
    }

    /**
     * Translate a liturgical season value into the specified locale.
     *
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical season value.
     */
    public function i18n(string $locale): string
    {
        $isLatin = in_array($locale, [LitLocale::LATIN, LitLocale::LATIN_PRIMARY_LANGUAGE], true);
        return match ($this) {
            /**translators: context = liturgical season */
            LitSeason::ADVENT         => $isLatin ? 'Tempus Adventus'     : _('Advent'),
            /**translators: context = liturgical season */
            LitSeason::CHRISTMAS      => $isLatin ? 'Tempus Nativitatis'  : _('Christmas'),
            /**translators: context = liturgical season */
            LitSeason::LENT           => $isLatin ? 'Tempus Quadragesima' : _('Lent'),
            /**translators: context = liturgical season */
            LitSeason::EASTER_TRIDUUM => $isLatin ? 'Triduum Paschale'    : _('Easter Triduum'),
            /**translators: context = liturgical season */
            LitSeason::EASTER         => $isLatin ? 'Tempus Paschale'     : _('Easter'),
            /**translators: context = liturgical season */
            LitSeason::ORDINARY_TIME  => $isLatin ? 'Tempus per annum'    : _('Ordinary Time')
        };
    }
}
