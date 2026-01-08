<?php

namespace LiturgicalCalendar\Api;

class DateTime extends \DateTime implements \JsonSerializable
{
    /**
     * Json Serialize
     *
     * When json encoding a DateTime object, serialize it as an array with a date key
     * containing the ISO-8601 formatted date and time, and keys for the timezone
     *
     * @see https://www.php.net/manual/en/class.datetime.php
     * @return array{date:string,timezone:string,timezone_type:int}
     */
    public function jsonSerialize(): array
    {
        $timezone = $this->getTimezone();
        if ($timezone === false) {
            throw new \RuntimeException('Failed to get timezone from DateTime object');
        }

        $tzJson = json_encode($timezone, JSON_THROW_ON_ERROR);
        /** @var array{timezone:string,timezone_type:int} */
        $tz = json_decode($tzJson, true, 512, JSON_THROW_ON_ERROR);

        return [
            'date' => $this->format('c'), // serialize the DateTime object as an ISO-8601 date string
            ...$tz
        ];
    }

    /**
     * Create a DateTime from a day-month-year string.
     *
     * Uses UTC timezone intentionally - see public/index.php for the full timezone
     * design rationale. UTC is used for all internal calculations to avoid DST issues,
     * while the PHP default timezone (Europe/Vatican) is used for external display.
     *
     * @param string $time Date string in 'j-n-Y' format (e.g., '25-12-2024')
     * @return DateTime
     * @throws \InvalidArgumentException If the date string cannot be parsed
     */
    public static function fromFormat(string $time): DateTime
    {
        $dateTime = DateTime::createFromFormat('!j-n-Y', $time, new \DateTimeZone('UTC'));
        if ($dateTime === false) {
            throw new \InvalidArgumentException('Failed to create DateTime from ' . $time);
        }
        return $dateTime;
    }
}
