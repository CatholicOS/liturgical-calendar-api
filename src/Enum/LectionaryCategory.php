<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Defines the lectionary categories for temporale events.
 *
 * Each temporale event has its lectionary readings stored in a specific
 * lectionary file based on its category. Some categories (like SUNDAYS_SOLEMNITIES)
 * have readings that vary by liturgical year cycle (A, B, C), while others
 * have the same readings every year.
 */
enum LectionaryCategory: string
{
    /**
     * Sundays and Solemnities lectionary (Year cycles A, B, C).
     *
     * Contains readings for most temporale events including:
     * - Advent Sundays (Advent1-4)
     * - Lent Sundays (Lent1-5)
     * - Easter Sundays (Easter, Easter2-7)
     * - Ordinary Time Sundays (OrdSunday2-33)
     * - Major celebrations (Christmas, Epiphany, Ascension, Pentecost, etc.)
     * - Triduum (HolyThurs, GoodFri, EasterVigil)
     */
    case SUNDAYS_SOLEMNITIES = 'dominicale_et_festivum';

    /**
     * Advent weekdays lectionary (flat structure, no year cycle).
     *
     * Contains readings for:
     * - AdventWeekday1-3[Day], AdventWeekdayDec17-24
     */
    case WEEKDAYS_ADVENT = 'feriale_tempus_adventus';

    /**
     * Christmas weekdays lectionary (flat structure, no year cycle).
     *
     * Contains readings for:
     * - ChristmasWeekdayDec29-31, ChristmasWeekdayJan2-7
     * - DayAfterEpiphany[Day], DayAfterEpiphanyJan7-12
     */
    case WEEKDAYS_CHRISTMAS = 'feriale_tempus_nativitatis';

    /**
     * Lent weekdays lectionary (flat structure, no year cycle).
     *
     * Contains readings for:
     * - AshWednesday, days after Ash Wednesday
     * - LentWeekday1-5[Day]
     * - MonHolyWeek, TueHolyWeek, WedHolyWeek
     */
    case WEEKDAYS_LENT = 'feriale_tempus_quadragesimae';

    /**
     * Easter weekdays lectionary (flat structure, no year cycle).
     *
     * Contains readings for:
     * - Easter Octave days (MonOctaveEaster, etc.)
     * - EasterWeekday2-7[Day]
     */
    case WEEKDAYS_EASTER = 'feriale_tempus_paschatis';

    /**
     * Ordinary Time weekdays lectionary (Year cycles I, II).
     *
     * Contains readings for:
     * - OrdWeekday1-34[Day] (Monday through Saturday of each week)
     *
     * Uses a two-year cycle (I, II) rather than the three-year (A, B, C) cycle.
     */
    case WEEKDAYS_ORDINARY = 'feriale_per_annum';

    /**
     * Sanctorum (Saints) lectionary (flat structure, no year cycle).
     *
     * Contains readings for temporale events that use sanctorum readings:
     * - ImmaculateHeart
     */
    case SANCTORUM = 'sanctorum';

    /**
     * Patterns for detecting WEEKDAYS_ADVENT events.
     */
    private const array WEEKDAYS_ADVENT_PATTERNS = [
        '/^AdventWeekday\d/',
        '/^AdventWeekdayDec\d+$/',
    ];

    /**
     * Patterns for detecting WEEKDAYS_CHRISTMAS events.
     */
    private const array WEEKDAYS_CHRISTMAS_PATTERNS = [
        '/^ChristmasWeekday/',
        '/^DayAfterEpiphany/',
    ];

    /**
     * Patterns for detecting WEEKDAYS_LENT events.
     */
    private const array WEEKDAYS_LENT_PATTERNS = [
        '/^AshWednesday$/',
        '/^(Friday|Saturday|Thursday)AfterAshWednesday$/',
        '/^LentWeekday\d/',
        '/^(Mon|Tue|Wed)HolyWeek$/',
    ];

    /**
     * Patterns for detecting WEEKDAYS_EASTER events.
     */
    private const array WEEKDAYS_EASTER_PATTERNS = [
        '/^(Mon|Tue|Wed|Thu|Fri|Sat)OctaveEaster$/',
        '/^EasterWeekday\d/',
    ];

    /**
     * Patterns for detecting WEEKDAYS_ORDINARY events.
     */
    private const array WEEKDAYS_ORDINARY_PATTERNS = ['/^OrdWeekday\d+/'];

    /**
     * Event keys that belong to the SANCTORUM category.
     */
    private const array SANCTORUM_EVENTS = ['ImmaculateHeart'];

    /**
     * Determine the lectionary category for a given event key.
     *
     * @param string $eventKey The temporale event key.
     * @return self The lectionary category for the event.
     */
    public static function forEventKey(string $eventKey): self
    {
        // Check pattern-based categories
        foreach (self::WEEKDAYS_ADVENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::WEEKDAYS_ADVENT;
            }
        }
        foreach (self::WEEKDAYS_CHRISTMAS_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::WEEKDAYS_CHRISTMAS;
            }
        }
        foreach (self::WEEKDAYS_LENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::WEEKDAYS_LENT;
            }
        }
        foreach (self::WEEKDAYS_EASTER_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::WEEKDAYS_EASTER;
            }
        }
        foreach (self::WEEKDAYS_ORDINARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::WEEKDAYS_ORDINARY;
            }
        }

        // Check explicit list for SANCTORUM
        if (in_array($eventKey, self::SANCTORUM_EVENTS, true)) {
            return self::SANCTORUM;
        }

        // Default: most temporale events are in the Sunday/Solemnity lectionary
        return self::SUNDAYS_SOLEMNITIES;
    }

    /**
     * Check if this category has three-year cycle readings (A, B, C).
     *
     * @return bool True if readings vary by liturgical year cycle (A, B, C).
     */
    public function hasYearCycle(): bool
    {
        return $this === self::SUNDAYS_SOLEMNITIES;
    }

    /**
     * Check if this category has two-year cycle readings (I, II).
     *
     * Ordinary Time weekdays use a two-year cycle where Year I is used in
     * odd-numbered years and Year II is used in even-numbered years.
     *
     * @return bool True if readings vary by two-year cycle (I, II).
     */
    public function hasTwoYearCycle(): bool
    {
        return $this === self::WEEKDAYS_ORDINARY;
    }

    /**
     * Get the JsonData enum case for the folder path.
     *
     * For SUNDAYS_SOLEMNITIES, returns the Year A folder as the base.
     * For WEEKDAYS_ORDINARY, returns the Year I folder as the base.
     * Use folderForYear() or folderForTwoYearCycle() to get a specific year's folder.
     *
     * @return JsonData The folder path enum case.
     */
    public function folder(): JsonData
    {
        return match ($this) {
            self::SUNDAYS_SOLEMNITIES => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER,
            self::WEEKDAYS_ORDINARY   => JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER,
            self::WEEKDAYS_ADVENT     => JsonData::LECTIONARY_WEEKDAYS_ADVENT_FOLDER,
            self::WEEKDAYS_CHRISTMAS  => JsonData::LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER,
            self::WEEKDAYS_LENT       => JsonData::LECTIONARY_WEEKDAYS_LENT_FOLDER,
            self::WEEKDAYS_EASTER     => JsonData::LECTIONARY_WEEKDAYS_EASTER_FOLDER,
            self::SANCTORUM           => JsonData::LECTIONARY_SAINTS_FOLDER,
        };
    }

    /**
     * Get the JsonData enum case for the file path (with locale placeholder).
     *
     * For SUNDAYS_SOLEMNITIES, returns the Year A file as the base.
     * For WEEKDAYS_ORDINARY, returns the Year I file as the base.
     * Use fileForYear() or fileForTwoYearCycle() to get a specific year's file.
     *
     * @return JsonData The file path enum case.
     */
    public function file(): JsonData
    {
        return match ($this) {
            self::SUNDAYS_SOLEMNITIES => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE,
            self::WEEKDAYS_ORDINARY   => JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE,
            self::WEEKDAYS_ADVENT     => JsonData::LECTIONARY_WEEKDAYS_ADVENT_FILE,
            self::WEEKDAYS_CHRISTMAS  => JsonData::LECTIONARY_WEEKDAYS_CHRISTMAS_FILE,
            self::WEEKDAYS_LENT       => JsonData::LECTIONARY_WEEKDAYS_LENT_FILE,
            self::WEEKDAYS_EASTER     => JsonData::LECTIONARY_WEEKDAYS_EASTER_FILE,
            self::SANCTORUM           => JsonData::LECTIONARY_SAINTS_FILE,
        };
    }

    /**
     * Get the liturgical color(s) for this category.
     *
     * For ferial categories, returns the standard liturgical color for that season.
     * For SUNDAYS_SOLEMNITIES and SANCTORUM, returns an empty array since colors
     * vary per event and must be obtained from event-specific data.
     *
     * @return string[] Array of liturgical color strings, or empty for varied categories.
     */
    public function liturgicalColor(): array
    {
        return match ($this) {
            self::WEEKDAYS_ADVENT,
            self::WEEKDAYS_LENT       => ['purple'],
            self::WEEKDAYS_CHRISTMAS,
            self::WEEKDAYS_EASTER     => ['white'],
            self::WEEKDAYS_ORDINARY   => ['green'],
            // SUNDAYS_SOLEMNITIES and SANCTORUM have varied colors per event
            // Return empty array to signal callers must use event-specific color data
            self::SUNDAYS_SOLEMNITIES,
            self::SANCTORUM           => [],
        };
    }

    /**
     * Check if this category represents ferial (weekday) events.
     *
     * @return bool True if this is a ferial category.
     */
    public function isFerial(): bool
    {
        return match ($this) {
            self::WEEKDAYS_ADVENT,
            self::WEEKDAYS_CHRISTMAS,
            self::WEEKDAYS_LENT,
            self::WEEKDAYS_EASTER,
            self::WEEKDAYS_ORDINARY   => true,
            self::SUNDAYS_SOLEMNITIES,
            self::SANCTORUM           => false,
        };
    }

    /**
     * Get the JsonData enum case for a specific year cycle's folder.
     *
     * @param string $year The year cycle ('A', 'B', or 'C').
     * @return JsonData The folder path enum case for the specified year.
     * @throws \InvalidArgumentException If the year is invalid or category doesn't have year cycles.
     */
    public function folderForYear(string $year): JsonData
    {
        if (!$this->hasYearCycle()) {
            throw new \InvalidArgumentException(
                "Lectionary category '{$this->value}' does not have year cycles"
            );
        }

        return match (strtoupper($year)) {
            'A'     => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER,
            'B'     => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER,
            'C'     => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER,
            default => throw new \InvalidArgumentException("Invalid year cycle: '{$year}'"),
        };
    }

    /**
     * Get the JsonData enum case for a specific year cycle's file.
     *
     * @param string $year The year cycle ('A', 'B', or 'C').
     * @return JsonData The file path enum case for the specified year.
     * @throws \InvalidArgumentException If the year is invalid or category doesn't have year cycles.
     */
    public function fileForYear(string $year): JsonData
    {
        if (!$this->hasYearCycle()) {
            throw new \InvalidArgumentException(
                "Lectionary category '{$this->value}' does not have year cycles"
            );
        }

        return match (strtoupper($year)) {
            'A'     => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE,
            'B'     => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FILE,
            'C'     => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FILE,
            default => throw new \InvalidArgumentException("Invalid year cycle: '{$year}'"),
        };
    }

    /**
     * Get the JsonData enum case for a specific two-year cycle's folder.
     *
     * @param string $year The year cycle ('I' or 'II').
     * @return JsonData The folder path enum case for the specified year.
     * @throws \InvalidArgumentException If the year is invalid or category doesn't have two-year cycles.
     */
    public function folderForTwoYearCycle(string $year): JsonData
    {
        if (!$this->hasTwoYearCycle()) {
            throw new \InvalidArgumentException(
                "Lectionary category '{$this->value}' does not have two-year cycles"
            );
        }

        return match (strtoupper($year)) {
            'I'     => JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER,
            'II'    => JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER,
            default => throw new \InvalidArgumentException("Invalid two-year cycle: '{$year}'"),
        };
    }

    /**
     * Get the JsonData enum case for a specific two-year cycle's file.
     *
     * @param string $year The year cycle ('I' or 'II').
     * @return JsonData The file path enum case for the specified year.
     * @throws \InvalidArgumentException If the year is invalid or category doesn't have two-year cycles.
     */
    public function fileForTwoYearCycle(string $year): JsonData
    {
        if (!$this->hasTwoYearCycle()) {
            throw new \InvalidArgumentException(
                "Lectionary category '{$this->value}' does not have two-year cycles"
            );
        }

        return match (strtoupper($year)) {
            'I'     => JsonData::LECTIONARY_WEEKDAYS_ORDINARY_I_FILE,
            'II'    => JsonData::LECTIONARY_WEEKDAYS_ORDINARY_II_FILE,
            default => throw new \InvalidArgumentException("Invalid two-year cycle: '{$year}'"),
        };
    }

    /**
     * Get patterns for detecting event keys in this category.
     *
     * @return string[]|null Array of regex patterns, or null for SANCTORUM (uses explicit list).
     */
    public function getPatterns(): ?array
    {
        return match ($this) {
            self::WEEKDAYS_ADVENT     => self::WEEKDAYS_ADVENT_PATTERNS,
            self::WEEKDAYS_CHRISTMAS  => self::WEEKDAYS_CHRISTMAS_PATTERNS,
            self::WEEKDAYS_LENT       => self::WEEKDAYS_LENT_PATTERNS,
            self::WEEKDAYS_EASTER     => self::WEEKDAYS_EASTER_PATTERNS,
            self::WEEKDAYS_ORDINARY   => self::WEEKDAYS_ORDINARY_PATTERNS,
            self::SANCTORUM           => null, // Uses explicit array
            self::SUNDAYS_SOLEMNITIES => null, // Default category
        };
    }

    /**
     * Get explicit event keys for categories that don't use patterns.
     *
     * @return string[]|null Array of event keys, or null for pattern-based/default categories.
     */
    public function eventKeys(): ?array
    {
        return match ($this) {
            self::SANCTORUM           => self::SANCTORUM_EVENTS,
            // Pattern-based and default categories return null
            self::WEEKDAYS_ADVENT,
            self::WEEKDAYS_CHRISTMAS,
            self::WEEKDAYS_LENT,
            self::WEEKDAYS_EASTER,
            self::WEEKDAYS_ORDINARY,
            self::SUNDAYS_SOLEMNITIES => null,
        };
    }

    /**
     * Get explicit event keys from the SANCTORUM category.
     *
     * Note: Only SANCTORUM uses explicit event keys; other categories use patterns.
     * For pattern-based categories, the event keys must be read from lectionary files.
     *
     * @return string[] Array of event keys in special explicit categories.
     */
    public static function specialEventKeys(): array
    {
        return self::SANCTORUM_EVENTS;
    }

    /**
     * Get all ferial categories (weekday lectionaries).
     *
     * @return self[] Array of ferial category cases.
     */
    public static function ferialCategories(): array
    {
        return [
            self::WEEKDAYS_ADVENT,
            self::WEEKDAYS_CHRISTMAS,
            self::WEEKDAYS_LENT,
            self::WEEKDAYS_EASTER,
            self::WEEKDAYS_ORDINARY,
        ];
    }

    /**
     * Get all lectionary categories.
     *
     * @return self[] Array of all category cases.
     */
    public static function all(): array
    {
        return self::cases();
    }
}
