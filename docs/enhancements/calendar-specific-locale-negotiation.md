# Enhancement: Calendar-Specific Locale Negotiation

## Issue

**Status:** Proposed
**Priority:** Low
**Component:** CalendarHandler
**Related Code:** `src/Handlers/CalendarHandler.php:5043-5045`

## Background

Currently, when negotiating the locale from the `Accept-Language` header in `CalendarHandler::handle()`, we pass an empty `$supported` array to
`Negotiator::pickLanguage()`:

```php
$locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
```

This means the negotiation uses the global list of all `LitLocale` values (all supported locales across all calendars).

## Proposed Enhancement

Pass calendar-specific supported locales to `Negotiator::pickLanguage()` instead of the global locale list.

**Benefits:**

- More accurate locale negotiation based on what the specific calendar actually supports
- Better user experience - Accept-Language negotiation reflects available translations for that calendar
- Prevents selecting a locale that isn't supported by the requested calendar

**Example:**

If the USA calendar only supports `['en', 'es', 'la']`, and a user requests `Accept-Language: fr,en;q=0.9`, the negotiator would skip French and select
English instead of potentially returning French (which the calendar doesn't support).

## Current Behavior

```php
// Line 5043-5045 in CalendarHandler.php
// TODO: Future enhancement - pass calendar-specific supported locales once calendar
// metadata is available (requires reordering to parse calendar param first)
$locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
```

## Implementation Roadmap

### Phase 1: Metadata Infrastructure

**Goal:** Ensure calendar metadata includes supported locales

1. **Verify metadata structure**
   - Check if `MetadataHandler` already returns `supported_locales` for each calendar
   - If not, add `supported_locales` to metadata response schema
   - Update national/diocesan calendar JSON files to include `supported_locales` array

2. **Create metadata accessor**
   - Add method to `MetadataHandler` or create utility class to retrieve supported locales for a given calendar
   - Example: `MetadataHandler::getSupportedLocales(string $calendar): array`

### Phase 2: Parameter Parsing Refactor

**Goal:** Parse calendar parameter before locale negotiation

1. **Refactor parameter parsing order in `CalendarHandler::handle()`**
   - Current order: locale → calendar → other params
   - New order: calendar → locale (using calendar's supported locales) → other params

2. **Handle edge cases**
   - What if calendar parameter is invalid/missing?
   - Fallback to global locale list if calendar metadata unavailable
   - Default to General Roman Calendar's locales if no calendar specified

### Phase 3: Integration

**Goal:** Connect calendar metadata to locale negotiation

1. **Update `CalendarHandler::handle()` flow**

   ```php
   // Step 1: Parse calendar parameter first
   $calendar = $params['calendar'] ?? 'VA'; // Vatican/General Roman Calendar default

   // Step 2: Get supported locales for this calendar
   $supportedLocales = MetadataHandler::getSupportedLocales($calendar);

   // Step 3: Negotiate locale with calendar-specific list
   $locale = Negotiator::pickLanguage($request, $supportedLocales, LitLocale::LATIN);
   ```

2. **Add tests**
   - Test locale negotiation with calendar-specific locale lists
   - Test fallback behavior when metadata unavailable
   - Test edge cases (invalid calendar, missing supported_locales, etc.)

### Phase 4: Documentation & Rollout

1. **Update documentation**
   - Document the new behavior in CLAUDE.md
   - Update OpenAPI schema if needed
   - Add inline code comments explaining the flow

2. **Gradual rollout**
   - Test with a few calendars first
   - Monitor for unexpected behavior
   - Expand to all calendars once stable

## Technical Considerations

### Performance

- Fetching calendar metadata adds an extra lookup before locale negotiation
- Consider caching calendar metadata to minimize overhead
- Measure performance impact on high-traffic routes

### Backwards Compatibility

- This is a transparent enhancement - API contract doesn't change
- May change which locale is selected for some requests if user's Accept-Language includes unsupported locales
- Should not break existing clients

### Dependencies

- Requires calendar metadata to be complete and accurate
- May need to audit all calendar JSON files to ensure `supported_locales` is present and correct

## Related Issues

- None currently

## References

- CodeRabbit suggestion: [PR review comment](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/XXX) *(update with actual PR link)*
- Related code: `src/Handlers/CalendarHandler.php:5043-5045`
- Negotiator implementation: `src/Http/Negotiator.php`

## Notes

- This enhancement is **not urgent** - current implementation works correctly
- Consider implementing as part of a broader metadata refactor
- Low priority compared to other API improvements
