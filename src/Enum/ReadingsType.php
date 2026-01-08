<?php

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Models\Lectionary\ReadingsMap;

/**
 * Defines the expected readings structure type for temporale events.
 *
 * Each temporale event has lectionary readings with a specific structure.
 * Most events use the standard Festive structure, but some special events
 * (Christmas, Pentecost, Easter Vigil, Palm Sunday, Easter, All Souls)
 * have unique structures.
 */
enum ReadingsType: string
{
    /**
     * Christmas readings: vigil, night, dawn, day masses.
     * Used by: Christmas
     */
    case CHRISTMAS = 'christmas';

    /**
     * Festive with vigil: vigil and day masses.
     * Used by: Pentecost
     */
    case FESTIVE_WITH_VIGIL = 'festive_with_vigil';

    /**
     * Easter Vigil: 7 Old Testament readings + epistle + gospel.
     * Used by: EasterVigil
     */
    case EASTER_VIGIL = 'easter_vigil';

    /**
     * Palm Sunday: includes palm_gospel procession reading.
     * Used by: PalmSun
     */
    case PALM_SUNDAY = 'palm_sunday';

    /**
     * With evening mass: day and evening masses.
     * Used by: Easter
     */
    case WITH_EVENING = 'with_evening';

    /**
     * Multiple schemas: three different reading sets.
     * Used by: AllSouls
     */
    case MULTIPLE_SCHEMAS = 'multiple_schemas';

    /**
     * Seasonal: different readings for easter season vs outside.
     * Used by: certain sanctorale events
     */
    case SEASONAL = 'seasonal';

    /**
     * Festive: standard 5 readings (first, psalm, second, acclamation, gospel).
     * Used by: Most Sundays, solemnities, feasts.
     */
    case FESTIVE = 'festive';

    /**
     * Ferial: 4 readings without second reading.
     * Used by: Seasonal weekdays (Advent, Christmas, Lent, Easter, Ordinary Time).
     *
     * Note: Ordinary Time weekdays use a two-year cycle (I, II) handled by
     * LectionaryCategory, but the readings structure itself is still ferial.
     */
    case FERIAL = 'ferial';

    /**
     * Event keys that use ReadingsChristmas structure.
     */
    private const array CHRISTMAS_EVENTS = ['Christmas'];

    /**
     * Event keys that use ReadingsFestiveWithVigil structure.
     */
    private const array FESTIVE_WITH_VIGIL_EVENTS = ['Pentecost'];

    /**
     * Event keys that use ReadingsEasterVigil structure.
     */
    private const array EASTER_VIGIL_EVENTS = ['EasterVigil'];

    /**
     * Event keys that use ReadingsPalmSunday structure.
     */
    private const array PALM_SUNDAY_EVENTS = ['PalmSun'];

    /**
     * Event keys that use ReadingsWithEvening structure.
     */
    private const array WITH_EVENING_EVENTS = ['Easter'];

    /**
     * Event keys that use ReadingsMultipleSchemas structure.
     */
    private const array MULTIPLE_SCHEMAS_EVENTS = ['AllSouls'];

    /**
     * Event keys that use ReadingsFerial structure.
     * All weekdays (Advent, Christmas, Lent, Easter, Ordinary Time) use ferial structure.
     *
     * Note: Ordinary Time weekdays use a two-year cycle (I, II) for file storage,
     * but the readings structure is still ferial. The cycle is handled by LectionaryCategory.
     */
    private const array FERIAL_EVENT_PATTERNS = [
        // Advent weekdays
        '/^AdventWeekday\d/',
        '/^AdventWeekdayDec\d+$/',
        // Christmas weekdays
        '/^ChristmasWeekday/',
        '/^DayAfterEpiphany/',
        // Lent weekdays (not AshWednesday which uses festive)
        '/^LentWeekday\d/',
        '/^(Friday|Saturday|Thursday)AfterAshWednesday$/',
        // Easter weekdays (not octave days which may have different structure)
        '/^EasterWeekday\d/',
        // Ordinary Time weekdays (two-year cycle I/II handled by LectionaryCategory)
        '/^OrdWeekday\d+/',
    ];

    /**
     * Determine the expected readings type for a given event key.
     *
     * @param string $eventKey The temporale event key.
     * @return self The expected readings type for the event.
     */
    public static function forEventKey(string $eventKey): self
    {
        if (in_array($eventKey, self::CHRISTMAS_EVENTS, true)) {
            return self::CHRISTMAS;
        }
        if (in_array($eventKey, self::FESTIVE_WITH_VIGIL_EVENTS, true)) {
            return self::FESTIVE_WITH_VIGIL;
        }
        if (in_array($eventKey, self::EASTER_VIGIL_EVENTS, true)) {
            return self::EASTER_VIGIL;
        }
        if (in_array($eventKey, self::PALM_SUNDAY_EVENTS, true)) {
            return self::PALM_SUNDAY;
        }
        if (in_array($eventKey, self::WITH_EVENING_EVENTS, true)) {
            return self::WITH_EVENING;
        }
        if (in_array($eventKey, self::MULTIPLE_SCHEMAS_EVENTS, true)) {
            return self::MULTIPLE_SCHEMAS;
        }

        // Check ferial patterns (includes Ordinary Time weekdays)
        foreach (self::FERIAL_EVENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::FERIAL;
            }
        }

        // Default: most events use festive structure
        return self::FESTIVE;
    }

    /**
     * Get the expected keys for this readings type.
     *
     * @return string[] The expected keys for the readings structure.
     */
    public function expectedKeys(): array
    {
        return match ($this) {
            self::CHRISTMAS          => ReadingsMap::READINGS_CHRISTMAS_KEYS,
            self::FESTIVE_WITH_VIGIL => ReadingsMap::READINGS_WITH_VIGIL_KEYS,
            self::EASTER_VIGIL       => ReadingsMap::EASTER_VIGIL_KEYS,
            self::PALM_SUNDAY        => ReadingsMap::PALM_SUNDAY_KEYS,
            self::WITH_EVENING       => ReadingsMap::READINGS_WITH_EVENING_MASS_KEYS,
            self::MULTIPLE_SCHEMAS   => ReadingsMap::READINGS_MULTIPLE_SCHEMAS_KEYS,
            self::SEASONAL           => ReadingsMap::READINGS_SEASONAL_KEYS,
            self::FESTIVE            => ReadingsMap::FESTIVE_KEYS,
            self::FERIAL             => ReadingsMap::FERIAL_KEYS,
        };
    }

    /**
     * Check if this readings type has nested structures.
     *
     * Two-dimensional types have nested reading sets (e.g., vigil.first_reading).
     * One-dimensional types have flat reading keys (e.g., first_reading).
     *
     * @return bool True if readings have nested structure.
     */
    public function hasNestedStructure(): bool
    {
        return match ($this) {
            self::CHRISTMAS,
            self::FESTIVE_WITH_VIGIL,
            self::WITH_EVENING,
            self::MULTIPLE_SCHEMAS,
            self::SEASONAL           => true,
            self::EASTER_VIGIL,
            self::PALM_SUNDAY,
            self::FESTIVE,
            self::FERIAL             => false,
        };
    }

    /**
     * Get the expected keys for nested readings (inner structure).
     *
     * For two-dimensional types, returns the keys expected in each nested object.
     * For one-dimensional types, returns null.
     *
     * @return string[]|null The expected nested keys, or null if not applicable.
     */
    public function nestedKeys(): ?array
    {
        if (!$this->hasNestedStructure()) {
            return null;
        }

        // All nested readings use festive structure (5 keys)
        // except SEASONAL which uses ferial (4 keys)
        return match ($this) {
            self::SEASONAL => ReadingsMap::FERIAL_KEYS,
            default        => ReadingsMap::FESTIVE_KEYS,
        };
    }

    /**
     * Validate that a readings object matches the expected structure.
     *
     * @param \stdClass $readings The readings object to validate.
     * @return bool True if the structure is valid.
     */
    public function validateStructure(\stdClass $readings): bool
    {
        $readingsArray = (array) $readings;
        $actualKeys    = array_keys($readingsArray);
        $expectedKeys  = $this->expectedKeys();

        // Check top-level keys match
        $missingKeys = array_diff($expectedKeys, $actualKeys);
        $extraKeys   = array_diff($actualKeys, $expectedKeys);

        if (!empty($missingKeys) || !empty($extraKeys)) {
            return false;
        }

        // For nested structures, validate inner objects
        if ($this->hasNestedStructure()) {
            $nestedKeys = $this->nestedKeys();
            if ($nestedKeys === null) {
                return false;
            }

            foreach ($expectedKeys as $key) {
                $nested = $readingsArray[$key];
                if (!$nested instanceof \stdClass) {
                    return false;
                }
                $nestedArray      = (array) $nested;
                $nestedActualKeys = array_keys($nestedArray);

                if (array_diff($nestedKeys, $nestedActualKeys) !== [] || array_diff($nestedActualKeys, $nestedKeys) !== []) {
                    return false;
                }

                // Validate all values are strings
                foreach ($nestedArray as $value) {
                    if (!is_string($value)) {
                        return false;
                    }
                }
            }
        } else {
            // Validate all values are strings for flat structure
            foreach ($readingsArray as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get validation error message for invalid structure.
     *
     * @param \stdClass $readings The readings object that failed validation.
     * @return string Detailed error message.
     */
    public function getValidationError(\stdClass $readings): string
    {
        $readingsArray = (array) $readings;
        $actualKeys    = array_keys($readingsArray);
        $expectedKeys  = $this->expectedKeys();

        $missingKeys = array_diff($expectedKeys, $actualKeys);
        $extraKeys   = array_diff($actualKeys, $expectedKeys);

        $errors = [];

        if (!empty($missingKeys)) {
            $errors[] = 'missing keys: ' . implode(', ', $missingKeys);
        }
        if (!empty($extraKeys)) {
            $errors[] = 'unexpected keys: ' . implode(', ', $extraKeys);
        }

        if (empty($errors) && $this->hasNestedStructure()) {
            $nestedKeys = $this->nestedKeys();
            if ($nestedKeys !== null) {
                foreach ($expectedKeys as $key) {
                    $nested = $readingsArray[$key];
                    if (!$nested instanceof \stdClass) {
                        $errors[] = "$key must be an object";
                        continue;
                    }
                    $nestedArray      = (array) $nested;
                    $nestedActualKeys = array_keys($nestedArray);

                    $nestedMissing = array_diff($nestedKeys, $nestedActualKeys);
                    $nestedExtra   = array_diff($nestedActualKeys, $nestedKeys);

                    if (!empty($nestedMissing)) {
                        $errors[] = "$key missing keys: " . implode(', ', $nestedMissing);
                    }
                    if (!empty($nestedExtra)) {
                        $errors[] = "$key unexpected keys: " . implode(', ', $nestedExtra);
                    }
                }
            }
        }

        return 'ReadingsType::' . $this->name . ' validation failed: ' . implode('; ', $errors);
    }

    /**
     * Get all event keys that use special (non-festive) readings types.
     *
     * @return string[] Array of event keys with special readings structures.
     */
    public static function specialEventKeys(): array
    {
        return array_merge(
            self::CHRISTMAS_EVENTS,
            self::FESTIVE_WITH_VIGIL_EVENTS,
            self::EASTER_VIGIL_EVENTS,
            self::PALM_SUNDAY_EVENTS,
            self::WITH_EVENING_EVENTS,
            self::MULTIPLE_SCHEMAS_EVENTS
        );
    }
}
