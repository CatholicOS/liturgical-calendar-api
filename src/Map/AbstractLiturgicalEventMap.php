<?php

namespace LiturgicalCalendar\Api\Map;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

/**
 * Abstract class for liturgical event maps.
 *
 * Maps event keys to LiturgicalEvent objects.
 *
 * Lifecycle: After calling mergeCollections(), the map enters a merged state.
 * In this state, toCollection() returns the merged collection and the map
 * should not be modified further (addEvent, removeEvent, etc.).
 *
 * @implements \IteratorAggregate<string,LiturgicalEvent>
 */
abstract class AbstractLiturgicalEventMap implements \IteratorAggregate
{
    /**
     * @var array<string,LiturgicalEvent> Map of event keys to LiturgicalEvent objects.
     */
    protected array $eventMap = [];

    /**
     * @var LiturgicalEvent[]|null Merged collection of events (indexed array).
     *
     * When set, this takes precedence over $eventMap for toCollection() output.
     * Used when merging liturgical years to preserve duplicate event_keys with different dates.
     */
    protected ?array $mergedCollection = null;

    /**
     * @var bool Whether the map has entered merged state via mergeCollections().
     *
     * Once true, mutation methods will throw LogicException to prevent inconsistency.
     */
    protected bool $isMerged = false;

    /**
     * Adds a LiturgicalEvent to the map.
     *
     * @param LiturgicalEvent $event The event to add.
     * @throws \LogicException If called after mergeCollections().
     */
    public function addEvent(LiturgicalEvent $event): void
    {
        $this->assertNotMerged(__METHOD__);
        $this->eventMap[$event->event_key] = $event;
    }

    /**
     * Retrieves a LiturgicalEvent by its key.
     *
     * @param string $key The key of the event to retrieve.
     * @return LiturgicalEvent|null The event if found, null otherwise.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getEvent(string $key): ?LiturgicalEvent
    {
        $this->assertNotMerged(__METHOD__);
        return $this->eventMap[$key] ?? null;
    }

    /**
     * Retrieves the first LiturgicalEvent that occures on the given date.
     *
     * @param DateTime $date The date of the event to retrieve.
     * @return LiturgicalEvent|null The event if found, null otherwise.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getEventByDate(DateTime $date): ?LiturgicalEvent
    {
        $this->assertNotMerged(__METHOD__);
        return array_find($this->eventMap, fn ($el) => $el->date == $date);
    }

    /**
     * Removes a LiturgicalEvent from the map by its key.
     *
     * @param string $key The key of the event to remove.
     * @return bool True if the event was removed, false if it did not exist.
     * @throws \LogicException If called after mergeCollections().
     */
    public function removeEvent(string $key): bool
    {
        $this->assertNotMerged(__METHOD__);
        if (isset($this->eventMap[$key])) {
            unset($this->eventMap[$key]);
            return true;
        }
        return false;
    }

    /**
     * Clears the event map.
     *
     * @throws \LogicException If called after mergeCollections().
     */
    public function clearEvents(): void
    {
        $this->assertNotMerged(__METHOD__);
        $this->eventMap = [];
    }

    /**
     * Returns the number of events in the map.
     *
     * @return int The number of events.
     * @throws \LogicException If called after mergeCollections().
     */
    public function countEvents(): int
    {
        $this->assertNotMerged(__METHOD__);
        return count($this->eventMap);
    }

    /**
     * Checks if the event map is empty.
     *
     * @return bool True if the map is empty, false otherwise.
     * @throws \LogicException If called after mergeCollections().
     */
    public function isEmpty(): bool
    {
        $this->assertNotMerged(__METHOD__);
        return empty($this->eventMap);
    }

    /**
     * Checks if an event exists in the map.
     *
     * @param string $key The key of the event to check.
     * @return bool True if the event exists, false otherwise.
     * @throws \LogicException If called after mergeCollections().
     */
    public function hasEvent(string $key): bool
    {
        $this->assertNotMerged(__METHOD__);
        return isset($this->eventMap[$key]);
    }

    /**
     * Checks if there is any event in the map that occurs on the specified date.
     *
     * @param DateTime $date The date to check for events.
     * @return bool True if an event occurs on the given date, false otherwise.
     * @throws \LogicException If called after mergeCollections().
     */
    public function hasDate(DateTime $date): bool
    {
        $this->assertNotMerged(__METHOD__);
        // important: DateTime objects cannot use strict comparison!
        return array_find($this->eventMap, fn ($el) => $el->date == $date) !== null;
    }

    /**
     * Checks if a given key exists in the event map.
     *
     * @param string $key The key to check.
     * @return bool True if the key exists, false otherwise.
     * @throws \LogicException If called after mergeCollections().
     */
    public function hasKey(string $key): bool
    {
        $this->assertNotMerged(__METHOD__);
        return array_key_exists($key, $this->eventMap);
    }

    /**
     * Retrieves all LiturgicalEvent objects in the map.
     *
     * @return array<string,LiturgicalEvent> An array of LiturgicalEvent objects.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getEvents(): array
    {
        $this->assertNotMerged(__METHOD__);
        return $this->eventMap;
    }

    /**
     * Retrieves all events that occur on the specified date.
     *
     * This method filters the event map and returns an array of events
     * whose date matches the given DateTime object.
     *
     * @param DateTime $date The date for which to retrieve events.
     * @return array<string,LiturgicalEvent> An array of events occurring on the specified date.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getEventsByDate(DateTime $date): array
    {
        $this->assertNotMerged(__METHOD__);
        // important: DateTime objects cannot use strict comparison!
        return array_filter($this->eventMap, fn ($el) => $el->date == $date);
    }

    /**
     * Returns the event map as a collection.
     *
     * If a merged collection exists (from mergeCollections()), returns that instead.
     * This preserves events with duplicate event_keys but different dates.
     *
     * @return LiturgicalEvent[] The event collection.
     */
    public function toCollection(): array
    {
        return $this->mergedCollection ?? array_values($this->eventMap);
    }

    /**
     * Returns the key of the first event that occurs on the given date.
     *
     * @param DateTime $date The date for which to find the event key.
     * @return string|null The key of the event at the given date, or null if no event exists.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getEventKeyByDate(DateTime $date): ?string
    {
        $this->assertNotMerged(__METHOD__);
        // important: DateTime objects cannot use strict comparison!
        return array_find_key($this->eventMap, fn ($el) => $el->date == $date);
    }

    /**
     * Moves the date of a LiturgicalEvent identified by its key to a new date.
     *
     * If the event with the specified key exists in the event map, its date is updated
     * to the provided new date.
     *
     * @param string $key The key of the event to update.
     * @param DateTime $date The new date for the event.
     * @return void
     * @throws \LogicException If called after mergeCollections().
     */
    public function moveEventDateByKey(string $key, DateTime $date): void
    {
        $this->assertNotMerged(__METHOD__);
        if (array_key_exists($key, $this->eventMap)) {
            $this->eventMap[$key]->date = $date;
        }
    }

    /**
     * Returns an array of keys for the events in the map.
     *
     * @return string[] An array of event keys.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getKeys(): array
    {
        $this->assertNotMerged(__METHOD__);
        return array_keys($this->eventMap);
    }

    /**
     * Sorts the event map by date and liturgical grade.
     *
     * The sort order is by date, and for events with the same date, by liturgical grade.
     * If a merged collection exists, it is also sorted.
     */
    public function sort(): void
    {
        uasort($this->eventMap, [self::class, 'compDateAndGrade']);
        if ($this->mergedCollection !== null) {
            usort($this->mergedCollection, [self::class, 'compDateAndGrade']);
        }
    }

    /**
     * Compares two LiturgicalEvent objects based on their date and liturgical grade.
     *
     * If the two LiturgicalEvent objects have different dates, the comparison is based on their date.
     * If the two LiturgicalEvent objects have different dates, the object with the later date is considered higher (i.e. it will come after the earlier date).
     * If the two LiturgicalEvent objects have the same date, the comparison is based on their grade.
     * If the two LiturgicalEvent objects have the same grade, the comparison is based on their liturgical season.
     * If the two LiturgicalEvent objects have the same liturgical season, the comparison result is 0 and no sorting will take place.
     * If the two LiturgicalEvent objects have different grades, the object with the higher grade is considered higher (i.e. it will come after the lower grade):
     *   this may seem counterintuitive, it would seem that a higher grade should come before a lower grade, but the most common case is for commemorations on weekdays,
     *   where the commemoration actually has a higher logical grade but is still optional so it should come after the weekday celebration.
     * However Vigil Masses will always be considered higher, i.e. will always come after the main celebration of the day, regardless of their grade.
     *
     * @param LiturgicalEvent $a The first LiturgicalEvent object to compare.
     * @param LiturgicalEvent $b The second LiturgicalEvent object to compare.
     *
     * @return int A value indicating the result of the comparison.
     *  -1 if $a is less than $b
     *   0 if $a is equal to $b
     *   1 if $a is greater than $b
     */
    public static function compDateAndGrade(LiturgicalEvent $a, LiturgicalEvent $b)
    {
        if ($a->date == $b->date) {
            if ($a->is_vigil_mass) {
                return +1;
            }
            if ($b->is_vigil_mass) {
                return -1;
            }
            if ($a->grade->value === $b->grade->value) {
                // When date and grade are equal, sort by liturgical season order.
                // This ensures events like HolyThursChrism (LENT) come before HolyThurs (EASTER_TRIDUUM).
                // Season order: ADVENT < CHRISTMAS < LENT < EASTER_TRIDUUM < EASTER < ORDINARY_TIME
                return self::compareSeasons($a, $b);
            }
            return ( $a->grade->value > $b->grade->value ) ? +1 : -1;
        }
        return ( $a->date > $b->date ) ? +1 : -1;
    }

    /**
     * Compares two LiturgicalEvent objects based on their liturgical season.
     *
     * The season order follows the liturgical year: ADVENT, CHRISTMAS, LENT, EASTER_TRIDUUM, EASTER, ORDINARY_TIME.
     * This is used as a tiebreaker when events have the same date and grade (e.g., HolyThursChrism vs HolyThurs).
     *
     * @param LiturgicalEvent $a The first LiturgicalEvent object to compare.
     * @param LiturgicalEvent $b The second LiturgicalEvent object to compare.
     *
     * @return int A value indicating the result of the comparison.
     */
    private static function compareSeasons(LiturgicalEvent $a, LiturgicalEvent $b): int
    {
        // Define season order using enum cases directly for maintainability
        // (lower = earlier in liturgical year)
        $seasonOrder = [
            LitSeason::ADVENT->value         => 0,
            LitSeason::CHRISTMAS->value      => 1,
            LitSeason::LENT->value           => 2,
            LitSeason::EASTER_TRIDUUM->value => 3,
            LitSeason::EASTER->value         => 4,
            LitSeason::ORDINARY_TIME->value  => 5,
        ];

        $seasonA = $a->liturgical_season?->value;
        $seasonB = $b->liturgical_season?->value;

        // If either season is null, treat as equal
        if ($seasonA === null || $seasonB === null) {
            return 0;
        }

        $orderA = $seasonOrder[$seasonA] ?? 99;
        $orderB = $seasonOrder[$seasonB] ?? 99;

        return $orderA <=> $orderB;
    }

    /**
     * Merges the events in the current map with the events in another AbstractLiturgicalEventMap.
     *
     * The events in the current map are updated with the events in the other map.
     * If an event with the same key exists in both maps, the event in the current map is replaced with the event from the other map.
     *
     * @param AbstractLiturgicalEventMap $litEvents The map of events to merge with the current map.
     * @return void
     * @throws \LogicException If called after mergeCollections().
     */
    public function merge(AbstractLiturgicalEventMap $litEvents): void
    {
        $this->assertNotMerged(__METHOD__);
        $this->eventMap = array_merge($this->eventMap, $litEvents->getEvents());
    }

    /**
     * Merges events as indexed collections, preserving duplicate event_keys with different dates.
     *
     * This method is used when combining two civil year calendars into a single liturgical year.
     * Unlike merge(), this method preserves events with the same event_key but different dates
     * (e.g., St. Andrew Apostle appearing on Nov 30 in both civil years of a liturgical year).
     *
     * The merged result is stored separately and returned by toCollection() instead of the eventMap.
     * After this call, the map enters merged state and should not be modified further.
     *
     * @param AbstractLiturgicalEventMap $litEvents The map of events to merge with the current map.
     * @return void
     */
    public function mergeCollections(AbstractLiturgicalEventMap $litEvents): void
    {
        // Always merge from raw eventMap arrays to avoid recursion/duplication on repeated calls
        // array_merge on indexed arrays appends rather than overwrites
        $this->mergedCollection = array_merge(
            array_values($this->eventMap),
            array_values($litEvents->getEvents())
        );
        $this->isMerged         = true;
    }

    /**
     * Returns an iterator for the events in the map.
     *
     * @return \Traversable<string,LiturgicalEvent> An iterator for the events in the map.
     * @throws \LogicException If called after mergeCollections().
     */
    public function getIterator(): \Traversable
    {
        $this->assertNotMerged(__METHOD__);
        return new \ArrayIterator($this->eventMap);
    }

    /**
     * Throws an exception if the map is in merged state.
     *
     * @param string $method The name of the method that was called.
     * @throws \LogicException If the map has been merged.
     */
    protected function assertNotMerged(string $method): void
    {
        if ($this->isMerged) {
            throw new \LogicException("Cannot call {$method}() after mergeCollections() has been called");
        }
    }
}
