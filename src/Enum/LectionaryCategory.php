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
     * Lent weekdays lectionary (flat structure, no year cycle).
     *
     * Contains readings for:
     * - AshWednesday
     * - MonHolyWeek, TueHolyWeek, WedHolyWeek
     */
    case WEEKDAYS_LENT = 'feriale_tempus_quadragesimae';

    /**
     * Easter weekdays lectionary (flat structure, no year cycle).
     *
     * Contains readings for:
     * - MonOctaveEaster, TueOctaveEaster, WedOctaveEaster
     * - ThuOctaveEaster, FriOctaveEaster, SatOctaveEaster
     */
    case WEEKDAYS_EASTER = 'feriale_tempus_paschatis';

    /**
     * Sanctorum (Saints) lectionary (flat structure, no year cycle).
     *
     * Contains readings for temporale events that use sanctorum readings:
     * - ImmaculateHeart
     */
    case SANCTORUM = 'sanctorum';

    /**
     * Event keys that belong to the WEEKDAYS_LENT category.
     */
    private const array WEEKDAYS_LENT_EVENTS = [
        'AshWednesday',
        'MonHolyWeek',
        'TueHolyWeek',
        'WedHolyWeek',
    ];

    /**
     * Event keys that belong to the WEEKDAYS_EASTER category.
     */
    private const array WEEKDAYS_EASTER_EVENTS = [
        'MonOctaveEaster',
        'TueOctaveEaster',
        'WedOctaveEaster',
        'ThuOctaveEaster',
        'FriOctaveEaster',
        'SatOctaveEaster',
    ];

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
        if (in_array($eventKey, self::WEEKDAYS_LENT_EVENTS, true)) {
            return self::WEEKDAYS_LENT;
        }
        if (in_array($eventKey, self::WEEKDAYS_EASTER_EVENTS, true)) {
            return self::WEEKDAYS_EASTER;
        }
        if (in_array($eventKey, self::SANCTORUM_EVENTS, true)) {
            return self::SANCTORUM;
        }
        // Default: most temporale events are in the Sunday/Solemnity lectionary
        return self::SUNDAYS_SOLEMNITIES;
    }

    /**
     * Check if this category has year-cycle readings (A, B, C).
     *
     * @return bool True if readings vary by liturgical year cycle.
     */
    public function hasYearCycle(): bool
    {
        return $this === self::SUNDAYS_SOLEMNITIES;
    }

    /**
     * Get the JsonData enum case for the folder path.
     *
     * For SUNDAYS_SOLEMNITIES, returns the Year A folder as the base.
     * Use folderForYear() to get a specific year's folder.
     *
     * @return JsonData The folder path enum case.
     */
    public function folder(): JsonData
    {
        return match ($this) {
            self::SUNDAYS_SOLEMNITIES => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER,
            self::WEEKDAYS_LENT       => JsonData::LECTIONARY_WEEKDAYS_LENT_FOLDER,
            self::WEEKDAYS_EASTER     => JsonData::LECTIONARY_WEEKDAYS_EASTER_FOLDER,
            self::SANCTORUM           => JsonData::LECTIONARY_SAINTS_FOLDER,
        };
    }

    /**
     * Get the JsonData enum case for the file path (with locale placeholder).
     *
     * For SUNDAYS_SOLEMNITIES, returns the Year A file as the base.
     * Use fileForYear() to get a specific year's file.
     *
     * @return JsonData The file path enum case.
     */
    public function file(): JsonData
    {
        return match ($this) {
            self::SUNDAYS_SOLEMNITIES => JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE,
            self::WEEKDAYS_LENT       => JsonData::LECTIONARY_WEEKDAYS_LENT_FILE,
            self::WEEKDAYS_EASTER     => JsonData::LECTIONARY_WEEKDAYS_EASTER_FILE,
            self::SANCTORUM           => JsonData::LECTIONARY_SAINTS_FILE,
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
     * Get all event keys that belong to this category.
     *
     * Note: For SUNDAYS_SOLEMNITIES, this returns null since all events
     * not in other categories belong here (it's the default).
     *
     * @return string[]|null Array of event keys, or null for the default category.
     */
    public function eventKeys(): ?array
    {
        return match ($this) {
            self::WEEKDAYS_LENT   => self::WEEKDAYS_LENT_EVENTS,
            self::WEEKDAYS_EASTER => self::WEEKDAYS_EASTER_EVENTS,
            self::SANCTORUM       => self::SANCTORUM_EVENTS,
            self::SUNDAYS_SOLEMNITIES => null,
        };
    }

    /**
     * Get all event keys that belong to non-default categories.
     *
     * These are event keys that do NOT belong to SUNDAYS_SOLEMNITIES.
     *
     * @return string[] Array of all event keys in special categories.
     */
    public static function specialEventKeys(): array
    {
        return array_merge(
            self::WEEKDAYS_LENT_EVENTS,
            self::WEEKDAYS_EASTER_EVENTS,
            self::SANCTORUM_EVENTS
        );
    }

    /**
     * Get all lectionary categories.
     *
     * @return self[] Array of all category cases.
     */
    public static function all(): array
    {
        return [
            self::SUNDAYS_SOLEMNITIES,
            self::WEEKDAYS_LENT,
            self::WEEKDAYS_EASTER,
            self::SANCTORUM,
        ];
    }
}
