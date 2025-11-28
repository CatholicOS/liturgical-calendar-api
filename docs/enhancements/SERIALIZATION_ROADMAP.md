# Serialization Coordination Roadmap

This document outlines the strategy for coordinating data serialization between the frontend and the API backend,
ensuring that data structures are consistent, schema-validated, and properly handled throughout the request lifecycle.

## Executive Summary

A critical issue was discovered where valid input data passes schema validation, but the data saved to disk becomes
invalid due to PHP object serialization behavior. This document provides a comprehensive plan to:

1. Fix the immediate serialization issue
2. Establish patterns for future implementations
3. Coordinate frontend/backend development for all entity types

---

## Current State Analysis

### The Problem

When creating/updating calendar data via PUT/PATCH requests:

1. **Input**: Frontend sends valid JSON data (e.g., `{ "litcal": [...] }`)
2. **Validation**: API validates input against JSON schema - **PASSES**
3. **Model Conversion**: API converts to PHP model objects (`DiocesanData`, etc.)
4. **Serialization**: API calls `json_encode($modelObject)` to save
5. **Output**: PHP serializes internal object structure, producing **INVALID** JSON

**Example of the mismatch**:

```json
// Expected (schema-compliant):
{
    "litcal": [
        { "liturgical_event": {...}, "metadata": {...} }
    ]
}

// Actual (PHP serialized):
{
    "litcal": {
        "litcalItems": [
            { "liturgical_event": {...}, "metadata": {...} }
        ]
    }
}
```

### Why Schema Validation Appears to "Pass"

The schema validation IS working correctly - it validates the **input** data from the frontend. The issue is:

- Validation happens **before** model conversion
- No validation happens **after** serialization (before saving)
- PHP's default `json_encode()` on objects produces a different structure than the input

### Root Cause

PHP model classes (`DiocesanData`, `DiocesanLitCalItemCollection`, etc.) do not implement `JsonSerializable`.
When `json_encode()` is called on these objects, PHP serializes all public properties, including internal
wrapper properties like `$litcalItems`, resulting in nested structures that don't match the schema.

### Additional Issue: Incorrect PHPStan Type Declarations (FIXED)

During analysis, an additional issue was discovered: the `@phpstan-type` declarations in several model classes
were describing **computed output data** (with properties like `missal`, `grade_lcl`, `common_lcl`) instead of
**raw source data** (with `{ liturgical_event, metadata }` structure).

**Affected files (now fixed):**

- `src/Models/LitCalItemCollection.php` - Incorrect `LiturgicalEventArray/Object` types
- `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemCollection.php` - Imported incorrect types
- `src/Models/RegionalData/DiocesanData/DiocesanData.php` - Imported incorrect types
- `src/Models/RegionalData/WiderRegionData/WiderRegionData.php` - Imported incorrect types
- `src/Models/RegionalData/NationalData/NationalData.php` - Had local incorrect types

**Fix applied:** Updated all type declarations to correctly reference the `{ liturgical_event, metadata }`
structure defined in `LitCalItem` and `DiocesanLitCalItem`.

---

## Recommended Solution: Skip DTO Conversion for Write Operations

After further analysis, a simpler approach was identified that avoids the serialization issue entirely:

### The Problem with DTO Conversion

The current PUT/PATCH flow is:

1. Raw JSON payload received
2. Schema validation **PASSES**
3. Convert to DTO: `DiocesanData::fromObject($payload)` ← **Unnecessary for write operations**
4. `json_encode($dto)` to write to disk ← **Produces INVALID structure**

### The Simpler Solution

Since schema validation already ensures data integrity, the handler can write the validated raw payload directly:

```php
// Current code (problematic):
if (RegionalDataHandler::validateDataAgainstSchema($payload, LitSchema::DIOCESAN->path())) {
    $params['payload'] = DiocesanData::fromObject($payload);  // ← Converts to DTO
}
// Later...
$calendarData = json_encode($payload, ...);  // ← DTO serializes incorrectly

// Proposed fix:
if (RegionalDataHandler::validateDataAgainstSchema($payload, LitSchema::DIOCESAN->path())) {
    // Keep raw payload for writing to disk
    $params['rawPayload'] = $payload;  // stdClass - validated against schema

    // Only convert to DTO if we need to access typed properties (e.g., for metadata extraction)
    $params['payload'] = DiocesanData::fromObject($payload);
}
// Later...
$calendarData = json_encode($params['rawPayload'], ...);  // ← Write raw validated JSON
```

### Benefits of This Approach

1. **No new models needed** - Avoids code duplication and bloat
2. **Schema validation ensures correctness** - Already validated before conversion
3. **DTOs remain for their intended purpose** - Reading and manipulating data programmatically
4. **Simple fix** - Minimal code changes required
5. **Consistent with GET flow** - GET already returns raw JSON from files

### When DTOs Are Still Needed

DTOs should still be used when:

- Extracting typed properties (e.g., `$params['payload']->metadata->nation`)
- Iterating over items with type safety
- Applying translations or other business logic

### Validation: i18n Required for PUT/PATCH (2025-11)

An important validation rule was added: the `i18n` property is **required** for PUT/PATCH operations,
even though the JSON schema marks it as optional.

**Why the difference?**

- **JSON Schema** marks `i18n` as optional because it reflects the **stored file structure** -
  i18n data is extracted and written to separate locale files, so it's not present in the
  calendar resource file after processing.
- **PUT/PATCH requests** must include `i18n` because without translations, the calendar data
  would be incomplete and unusable.

**Implementation:**

```php
// In RegionalDataHandler - before processing PUT/PATCH
// Schema marks i18n as optional (for stored files), but it's required for PUT/PATCH
if (!property_exists($payload, 'i18n')) {
    throw new UnprocessableContentException('The i18n property is required for PUT/PATCH operations');
}
```

This explicit validation ensures that:

1. Calendar data always has associated translations
2. The API fails fast with a clear error message
3. Schema validation alone isn't relied upon for business rules

---

## Entity Types Requiring Implementation

### 1. Regional Calendar Data (`/data` endpoint)

| Entity Type       | Schema File                | Model Class      | Frontend Form                     | Status                                                  |
|-------------------|----------------------------|------------------|-----------------------------------|---------------------------------------------------------|
| Diocesan Calendar | `DiocesanCalendar.json`    | `DiocesanData`   | `extending.php?choice=diocesan`   | PUT/PATCH/DELETE: ✅ Working (raw payload serialization) |
| National Calendar | `NationalCalendar.json`    | `NationalData`   | `extending.php?choice=national`   | PUT/PATCH/DELETE: ✅ Working (raw payload serialization) |
| Wider Region      | `WiderRegionCalendar.json` | `WiderRegionData`| `extending.php?choice=widerRegion`| PUT/PATCH/DELETE: ✅ Working (raw payload serialization) |

> **Note (2025-11):** Audit logging has been added to all write operations (PUT/PATCH/DELETE). The serialization
> issue has been **fixed** - handlers now use the raw payload (`\stdClass`) for `json_encode()` instead of DTOs,
> preserving the schema-compliant JSON structure when saving to disk.

### 2. Missals Data (`/missals` endpoint)

| Entity Type         | Schema File              | Model Class | Frontend Form         | Status                                |
|---------------------|--------------------------|-------------|-----------------------|---------------------------------------|
| Proprium de Sanctis | `PropriumDeSanctis.json` | TBD         | `admin.php` (partial) | Partial frontend, API not implemented |
| Proprium de Tempore | `PropriumDeTempore.json` | TBD         | `admin.php` (partial) | Partial frontend, API not implemented |

> **Note**: There is partial support for handling missals data in the frontend `admin.php`. However, this will need
> significant work and should be aligned with the same workflow patterns used for creating national, diocesan, and
> wider region calendar data. The goal is to have a consistent approach across all entity types for data serialization,
> validation, and API communication.

### 3. Decrees Data (`/decrees` endpoint)

| Entity Type | Schema File                | Model Class | Frontend Form | Status          |
|-------------|----------------------------|-------------|---------------|-----------------|
| Decrees     | `LitCalDecreesSource.json` | TBD         | TBD           | Not Implemented |

### 4. Tests Data (`/tests` endpoint)

| Entity Type | Schema File       | Model Class | Frontend Form | Status  |
|-------------|-------------------|-------------|---------------|---------|
| Test Cases  | `LitCalTest.json` | TBD         | TBD           | Partial |

---

## Implementation Strategy

### Phase 1: Fix Immediate Serialization Issues

#### 1.1 Use Raw Payload for Write Operations (RECOMMENDED)

Instead of implementing `JsonSerializable` on all model classes (which is complex and error-prone),
the simpler approach is to write the validated raw payload directly to disk.

**Changes required in `RegionalDataHandler`:**

1. Add `rawPayload` property to `RegionalDataParams`
2. Store the raw `\stdClass` payload alongside the DTO
3. Use raw payload when writing to files

**Implementation (actual):**

```php
// In RegionalDataHandler::parsePayload() - after schema validation
$this->validateDataAgainstSchema($payload, LitSchema::DIOCESAN->path());
$params['rawPayload'] = $payload;  // Keep raw stdClass for writing
$params['payload'] = DiocesanData::fromObject($payload);  // DTO for typed property access

// In createDiocesanCalendar() - use raw payload for writing
// Remove i18n first (written separately)
unset($this->params->rawPayload->i18n);
$calendarData = json_encode($this->params->rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents($diocesanCalendarFile, $calendarData . PHP_EOL);
```

> **Status (2025-11):** This approach is now fully implemented for all calendar types
> (diocesan, national, wider region). The raw payload strategy avoids the complexity
> of implementing `JsonSerializable` on all model classes.

#### 1.2 Fix PHPStan Type Declarations (COMPLETED)

The incorrect `@phpstan-type` declarations have been fixed. See "Additional Issue" section above.

#### 1.3 Optional: Post-Write Validation

For extra safety, validate the written file after saving:

```php
// After writing to disk
$writtenData = Utilities::jsonFileToObject($diocesanCalendarFile);
if (!self::validateDataAgainstSchema($writtenData, LitSchema::DIOCESAN->path())) {
    // Log error, rollback, or alert
    throw new ImplementationException('Written data does not conform to schema');
}
```

### Alternative Approach: Implement `JsonSerializable` (More Complex)

If the raw payload approach is not feasible for some use cases, `JsonSerializable` can be implemented
on model classes. This is more complex because:

1. All nested classes must also implement `JsonSerializable`
2. Enums must serialize to their `value` property, not `name`
3. Computed properties must be excluded
4. Collection classes must serialize as arrays, not objects

**If needed, affected classes would be:**

- `AbstractJsonSrcData`, `AbstractJsonSrcDataArray` (base classes)
- `DiocesanData`, `DiocesanLitCalItemCollection`, `DiocesanLitCalItem`, etc.
- `NationalData`, `LitCalItemCollection`, `LitCalItem`, etc.
- `WiderRegionData`, `WiderRegionMetadata`
- All `LitCalItem*` subclasses for different action types

---

## Data Flow: Frontend to Backend to Storage

Understanding how data flows from the frontend through the backend to storage is essential for coordinating
this effort.

### Expected Payload Structure (from JSON Schema)

The frontend must produce a payload matching the JSON schema. For diocesan calendars (`DiocesanCalendar.json`):

```json
{
    "litcal": [
        {
            "liturgical_event": {
                "event_key": "StExampleSaint",
                "color": ["white"],
                "grade": 3,
                "common": ["Martyrs"],
                "day": 15,
                "month": 6
            },
            "metadata": {
                "form_rownum": 0,
                "since_year": 2020
            }
        }
    ],
    "metadata": {
        "diocese_id": "DIOCESE_ID",
        "diocese_name": "Diocese Name",
        "nation": "US",
        "locales": ["en_US"],
        "timezone": "America/New_York"
    },
    "settings": {
        "epiphany": "SUNDAY_JAN2_JAN8",
        "ascension": "SUNDAY",
        "corpus_christi": "SUNDAY"
    },
    "i18n": {
        "en_US": {
            "StExampleSaint": "Saint Example"
        }
    }
}
```

### Backend Write Operations (How Data is Split)

The backend handles the payload in two stages:

1. **Write `i18n` data** to separate locale files in the `i18n/` folder
2. **Write remaining data** (`litcal`, `metadata`, `settings`) to the calendar resource file

```text
Frontend Payload
       │
       ▼
┌──────────────────────────────────────┐
│  Backend receives payload            │
│  - Validates against JSON schema     │
│  - Converts to DTO (for property     │
│    access like metadata.diocese_id)  │
└──────────────────────────────────────┘
       │
       ├──────────────────────────────────────────────────┐
       ▼                                                  ▼
┌──────────────────────────────┐    ┌─────────────────────────────────────┐
│  Write i18n data             │    │  Write calendar data                │
│                              │    │                                     │
│  For each locale in i18n:    │    │  Remove i18n from payload           │
│  - Write to                  │    │  Write to:                          │
│    i18n/{locale}.json        │    │    {calendar_id}.json               │
│                              │    │                                     │
│  Example:                    │    │  Contains:                          │
│  i18n/en_US.json =           │    │  - litcal (array)                   │
│  {"StExampleSaint":          │    │  - metadata (object)                │
│   "Saint Example"}           │    │  - settings (object, optional)      │
└──────────────────────────────┘    └─────────────────────────────────────┘
```

### Current Implementation Issues

**Problem 1: `litcal` serialization**

```php
// In createDiocesanCalendar()
$payload = $this->params->payload;  // DiocesanData DTO
// ...
$calendarData = json_encode($payload, ...);  // ← Serializes DTO incorrectly!
```

The `DiocesanData` DTO has a `$litcal` property of type `DiocesanLitCalItemCollection`, which has a
`$litcalItems` property. Without `JsonSerializable`, this produces:

```json
{ "litcal": { "litcalItems": [...] } }  // WRONG!
```

Instead of:

```json
{ "litcal": [...] }  // Correct
```

**Problem 2: `i18n` serialization**

```php
foreach ($payload->i18n as $locale => $litCalEventsI18n) {
    json_encode($litCalEventsI18n, ...);  // ← TranslationMap with private properties
}
```

`TranslationMap` has **private** properties (`$translations`, `$keys`), so `json_encode()` produces `{}`
(empty object) instead of the translation data.

### Solution: Use Raw Payload for Write Operations

The fix is straightforward - use the raw `\stdClass` payload for writing instead of the DTO:

**Step 1: Store raw payload in `RegionalDataParams`**

```php
// In RegionalDataParams.php
public DiocesanData|NationalData|WiderRegionData $payload;
public \stdClass $rawPayload;  // NEW: Keep raw payload for writing
```

**Step 2: Store raw payload during initialization**

```php
// In RegionalDataHandler::initParams()
if (RegionalDataHandler::validateDataAgainstSchema($payload, LitSchema::DIOCESAN->path())) {
    $params['rawPayload'] = $payload;  // Raw stdClass for writing
    $params['payload'] = DiocesanData::fromObject($payload);  // DTO for property access
    $key = $params['payload']->metadata->diocese_id;
}
```

**Step 3: Use raw payload for writing**

```php
// In createDiocesanCalendar()
$rawPayload = $this->params->rawPayload;

// Write i18n from raw payload
foreach ($rawPayload->i18n as $locale => $litCalEventsI18n) {
    $diocesanCalendarI18nFile = /* ... */;
    file_put_contents(
        $diocesanCalendarI18nFile,
        json_encode($litCalEventsI18n, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL
    );
}

// Remove i18n from raw payload before writing calendar file
unset($rawPayload->i18n);

// Write calendar data from raw payload
$calendarData = json_encode($rawPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents($diocesanCalendarFile, $calendarData . PHP_EOL);
```

---

### Phase 2: Establish Contract Between Frontend and Backend

#### 2.1 Create TypeScript Type Definitions

Generate TypeScript interfaces from JSON schemas to ensure frontend serialization matches backend expectations.

**Location**: `liturgy-components-js/src/types/`

```typescript
// Generated from DiocesanCalendar.json
export interface DiocesanCalendar {
    litcal: DiocesanLitCalItem[];
    metadata: DiocesanMetadata;
    settings?: DiocesanSettings;
    i18n?: Record<string, Record<string, string>>;
}

export interface DiocesanLitCalItem {
    liturgical_event: DiocesanLiturgicalEvent;
    metadata: DiocesanItemMetadata;
}
// ... etc.
```

#### 2.2 Create Shared Validation Utilities

Both frontend and backend should use the same validation approach:

**Frontend (JavaScript):**

```javascript
import Ajv from 'ajv';
import diocesanSchema from './schemas/DiocesanCalendar.json';

const ajv = new Ajv();
const validate = ajv.compile(diocesanSchema);

function validateDiocesanCalendar(data) {
    const valid = validate(data);
    if (!valid) {
        console.error('Validation errors:', validate.errors);
        throw new Error('Data does not conform to schema');
    }
    return true;
}
```

**Backend (PHP):**

```php
// Already exists in RegionalDataHandler::validateDataAgainstSchema()
// Ensure it's called both on input AND before saving output
```

#### 2.3 Document Expected Data Structures

Create comprehensive documentation of expected payload structures for each endpoint.

**Location**: `docs/api/payloads/`

- `diocesan-calendar-payload.md`
- `national-calendar-payload.md`
- `wider-region-payload.md`
- `missals-payload.md`
- `decrees-payload.md`

### Phase 3: Coordinate Implementation for Each Entity Type

#### 3.1 Diocesan Calendars (Priority: HIGH)

**Backend Tasks:**

- [x] ~~Implement `JsonSerializable` on all diocesan model classes~~ (Using raw payload approach instead)
- [x] Add `rawPayload` property to `RegionalDataParams` and use it for writing
- [ ] Add post-serialization validation in `createDiocesanCalendar()`
- [ ] Add post-serialization validation in `updateDiocesanCalendar()`
- [x] Implement `deleteDiocesanCalendar()` fully
- [x] Add audit logging to write operations
- [x] Write PHPUnit tests for serialization round-trip ✅ (`PayloadValidationTest.php`)

**Frontend Tasks:**

- [ ] Review `saveDiocesanCalendar_btnClicked()` in `extending.js`
- [ ] Add client-side schema validation before submission
- [ ] Ensure `CalendarData` structure matches `DiocesanCalendar.json` schema
- [ ] Add error handling for validation failures

**Testing:**

- [ ] Create integration test: submit from frontend → validate API response
- [ ] Create round-trip test: save → load → verify identical structure
- [ ] Test edge cases: empty litcal array, null settings, multiple locales

#### 3.2 National Calendars (Priority: HIGH)

**Backend Tasks:**

- [x] ~~Implement `JsonSerializable` on all national model classes~~ (Using raw payload approach instead)
- [x] Use `rawPayload` for writing in `createNationalCalendar()`
- [ ] Add post-serialization validation in `createNationalCalendar()`
- [x] `updateNationalCalendar()` implementation exists
- [x] `deleteNationalCalendar()` implementation exists
- [x] Add audit logging to write operations
- [ ] Handle complex litcal item types (makePatron, setProperty, moveEvent, createNew)

**Frontend Tasks:**

- [ ] Review `serializeNationalCalendarData()` in `extending.js`
- [ ] Ensure all action types serialize correctly
- [ ] Add client-side validation

**Testing:**

- [ ] Test each litcal action type individually
- [ ] Test combinations of action types
- [ ] Test i18n data handling

#### 3.3 Wider Region Calendars (Priority: MEDIUM)

**Backend Tasks:**

- [x] ~~Implement `JsonSerializable` on wider region model classes~~ (Using raw payload approach instead)
- [x] `createWiderRegionCalendar()` implemented with raw payload approach ✅
- [x] `updateWiderRegionCalendar()` uses raw payload for writing ✅
- [x] `deleteWiderRegionCalendar()` implementation exists (via generic `deleteCalendar()`)
- [x] Add audit logging to write operations

**Frontend Tasks:**

- [ ] Review `serializeWiderRegionData()` in `extending.js`
- [ ] Ensure proper locale handling
- [ ] Add client-side validation

#### 3.4 Missals Data (Priority: MEDIUM)

> **Important**: The frontend `admin.php` already has partial support for missals data management. The implementation
> should follow the same patterns established for calendar data (diocesan, national, wider region) to maintain
> consistency across the codebase.

**Backend Tasks:**

- [ ] Design model classes for Proprium de Sanctis (following established patterns)
- [ ] Design model classes for Proprium de Tempore (following established patterns)
- [ ] Implement PUT/PATCH/DELETE handlers in `MissalsHandler`
- [ ] Implement `JsonSerializable` on all classes
- [ ] Add post-serialization validation

**Frontend Tasks:**

- [ ] Review existing `admin.php` missals functionality
- [ ] Align serialization logic with patterns from `extending.js`
- [ ] Implement consistent form handling and validation
- [ ] Ensure authentication integration matches other protected endpoints

**Alignment Goals:**

- Use the same `CalendarData`-style state management pattern
- Implement the same validation flow (client-side then server-side)
- Use consistent error handling and user feedback patterns
- Follow the same authentication/authorization patterns

#### 3.5 Decrees Data (Priority: LOW)

**Backend Tasks:**

- [ ] Design model classes for decrees
- [ ] Implement PUT/PATCH/DELETE handlers in `DecreesHandler`
- [ ] Implement `JsonSerializable` on all classes

**Frontend Tasks:**

- [ ] Design UI for decrees management (if needed)
- [ ] Implement form and serialization logic following established patterns

---

## Testing Strategy

### Unit Tests

For each model class that implements `JsonSerializable`:

```php
public function testJsonSerializeProducesSchemaCompliantOutput(): void
{
    $data = DiocesanData::fromObject($this->getValidTestData());
    $serialized = json_encode($data);
    $decoded = json_decode($serialized);

    $this->assertTrue(
        RegionalDataHandler::validateDataAgainstSchema($decoded, LitSchema::DIOCESAN->path())
    );
}

public function testRoundTripPreservesData(): void
{
    $original = $this->getValidTestData();
    $model = DiocesanData::fromObject($original);
    $serialized = json_encode($model);
    $decoded = json_decode($serialized);

    $this->assertEquals($original->litcal, $decoded->litcal);
    $this->assertEquals($original->metadata, $decoded->metadata);
}
```

### Integration Tests

```php
public function testCreateDiocesanCalendarStoresValidData(): void
{
    // Submit valid data via API
    $response = $this->createDiocesanCalendar($validPayload);
    $this->assertEquals(201, $response->getStatusCode());

    // Read back the stored file
    $storedData = file_get_contents($expectedFilePath);
    $decoded = json_decode($storedData);

    // Validate against schema
    $this->assertTrue(
        RegionalDataHandler::validateDataAgainstSchema($decoded, LitSchema::DIOCESAN->path())
    );
}
```

### End-to-End Tests

Using a test framework (e.g., Playwright, Cypress) to test the full flow:

1. Fill out the diocesan calendar form in the frontend
2. Submit via the Save button
3. Verify API response is successful
4. Load the calendar data back
5. Verify all fields are correctly populated

---

## Implementation Order

### ~~Immediate (Fix Current Bug)~~ ✅ COMPLETED

1. ~~Add `rawPayload` property to `RegionalDataParams`~~ ✅ Done
2. ~~Modify `RegionalDataHandler` to store raw `\stdClass` payload alongside DTO~~ ✅ Done
3. ~~Use raw payload for `json_encode()` in all create/update methods~~ ✅ Done
4. Add post-serialization validation before returning success response
5. ~~Write serialization round-trip tests to prevent regression~~ ✅ Done (`PayloadValidationTest.php`)

### Short-term (Complete All Calendar Implementations)

1. ~~Complete PATCH implementation for diocesan calendars~~ ✅ Done
2. ~~Complete DELETE implementation for diocesan calendars~~ ✅ Done
3. ~~Add audit logging to write operations~~ ✅ Done
4. ~~Implement `createWiderRegionCalendar()`~~ ✅ Done
5. Add frontend validation
6. Write comprehensive tests

### Medium-term (Missals)

1. Design and implement missals model classes following established patterns
2. Align `admin.php` missals handling with `extending.js` patterns
3. Complete all CRUD operations for missals
4. Update frontend forms as needed

### Long-term (Decrees and Tests)

1. Design and implement decrees model classes
2. Implement CRUD handlers
3. Design and implement frontend UI
4. Comprehensive testing

---

## File Changes Summary

### API Backend Files Modified (Raw Payload Approach)

> **Note (2025-11):** The original plan to implement `JsonSerializable` on all model classes
> has been superseded by the raw payload approach. The files below were modified to support
> the raw payload strategy instead.

```text
src/Params/RegionalDataParams.php           # ✅ Added rawPayload property
src/Handlers/RegionalDataHandler.php        # ✅ Uses rawPayload for json_encode()
                                            # ✅ Added writeI18nFiles() helper
                                            # ✅ Added updateI18nFiles() helper
                                            # ✅ Added audit logging

phpunit_tests/Schemas/PayloadValidationTest.php  # ✅ Round-trip serialization tests
phpunit_tests/fixtures/payloads/                 # ✅ Test fixtures for all calendar types
```

### API Backend Files - No Changes Needed

The following model classes do **not** need `JsonSerializable` implementation because
the raw payload approach writes the original `\stdClass` directly:

```text
src/Models/RegionalData/DiocesanData/*      # No changes needed (raw payload used)
src/Models/RegionalData/NationalData/*      # No changes needed (raw payload used)
src/Models/RegionalData/WiderRegionData/*   # No changes needed (raw payload used)
```

### Future Work

```text
src/Handlers/MissalsHandler.php             # TODO: Implement PUT/PATCH/DELETE with validation
```

### Frontend Files to Review/Modify

```text
LiturgicalCalendarFrontend/assets/js/extending.js
├── saveDiocesanCalendar_btnClicked()       # Review serialization
├── serializeNationalCalendarData()         # Review serialization
└── serializeWiderRegionData()              # Review serialization

LiturgicalCalendarFrontend/admin.php        # Align missals handling with calendar patterns
LiturgicalCalendarFrontend/assets/js/admin.js  # (if exists) Align with extending.js patterns
```

### New Files to Create

```text
docs/api/payloads/diocesan-calendar-payload.md
docs/api/payloads/national-calendar-payload.md
docs/api/payloads/wider-region-payload.md
docs/api/payloads/missals-payload.md

phpunit_tests/Models/DiocesanDataSerializationTest.php
phpunit_tests/Models/NationalDataSerializationTest.php
phpunit_tests/Models/WiderRegionDataSerializationTest.php
phpunit_tests/Models/MissalsDataSerializationTest.php
```

---

## Success Criteria

1. **Schema Compliance**: All data saved to disk validates against its respective JSON schema
2. **Round-Trip Integrity**: Data loaded from disk and re-serialized produces identical output
3. **Test Coverage**: All serialization paths have unit tests
4. **Documentation**: All payload formats are documented with examples
5. **Error Handling**: Clear error messages when validation fails (both frontend and backend)
6. **Consistency**: All entity types (calendars, missals, decrees) follow the same patterns

---

## Related GitHub Issues

This roadmap addresses technical details for work tracked in the following GitHub issues:

### API Backend

- **[LiturgicalCalendarAPI#265](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/265)**:
  "Refactor resource creation / updating via PUT/PATCH/DELETE requests"

  This is the parent issue tracking all PUT/PATCH/DELETE implementation across:
  - Roman Missal sanctorale data (`/missals`)
  - National Calendar data (`/data/nation`) - marked complete but has serialization bug
  - Diocesan Calendar data (`/data/diocese`) - marked complete but has serialization bug
  - Decrees data (`/decrees`)
  - Unit tests (`/tests`)

  **Critical finding**: The "complete" status for National and Diocesan calendar data needs revision.
  While the handlers exist, the serialization bug documented in this roadmap means saved data
  does not conform to the JSON schemas.

### Frontend

- **[LiturgicalCalendarFrontend#142](https://github.com/Liturgical-Calendar/LiturgicalCalendarFrontend/issues/142)**:
  "Align `extending` frontends with new path backends"

  This issue tracks frontend alignment with API changes including:
  - Router implementation changes
  - Data shape changes (snake_case properties)
  - Extending frontend updates

  The serialization coordination work in this roadmap directly supports this issue by ensuring
  frontend serialization produces data that the API can correctly process and store.

### Related Documentation

- [Authentication Roadmap](AUTHENTICATION_ROADMAP.md) - JWT authentication implementation
- [OpenAPI Evaluation Roadmap](OPENAPI_EVALUATION_ROADMAP.md) - API schema gaps and missing CRUD operations
- [API Client Libraries Roadmap](../../../docs/API_CLIENT_LIBRARIES_ROADMAP.md) - Client library coordination

---

## DTO Architecture and Type System

This section documents the design of the DTO (Data Transfer Object) type system, explaining how raw source data
is transformed into computed properties and ensuring type coherence across the codebase.

### Type System Overview

The codebase distinguishes between two categories of data:

1. **Raw Source Data**: JSON data stored in `jsondata/sourcedata/` files
2. **Computed Data**: Properties derived from raw data plus i18n translations

### PHPStan Type Declarations

The `@phpstan-type` declarations define the shape of **raw source data** (what's in the JSON files),
NOT the computed output data. This is intentional and correct.

**Example - LitCalItemCreateNewFixed:**

```php
/**
 * @phpstan-type LitCalItemCreateNewFixedObject \stdClass&object{
 *      event_key:string,      // ✓ In raw JSON
 *      day:int,               // ✓ In raw JSON
 *      month:int,             // ✓ In raw JSON
 *      color:string[],        // ✓ In raw JSON
 *      grade:int,             // ✓ In raw JSON
 *      common:string[]        // ✓ In raw JSON
 *      // NOTE: `name` is NOT here - it's computed from i18n
 * }
 */
```

### The `name` Property: Computed from i18n

The `name` property is a **computed property** that does not exist in raw source data. It is:

1. **Declared** in `LiturgicalEventData` base class: `public string $name;`
2. **Not initialized** in subclass constructors
3. **Populated** via `setName()` from i18n translation data

**Data Flow:**

```text
Raw JSON Source Data              DTO Creation                    Translation Step
────────────────────              ────────────────                ────────────────
jsondata/sourcedata/              LitCalItem::fromObject()        NationalData::applyTranslations()
calendars/nations/US.json         DiocesanData::fromObject()      DiocesanData::applyTranslations()
                                  WiderRegionData::fromObject()   WiderRegionData::applyTranslations()
{                                        │                               │
  "litcal": [                            │                               │
    {                                    ▼                               ▼
      "liturgical_event": {        ┌─────────────────┐           ┌─────────────────┐
        "event_key": "...",        │ LitCalItem      │           │ setName()       │
        "day": 15,                 │ - event_key ✓   │    ──►    │ - name ✓ (set)  │
        "month": 6,                │ - day ✓         │           │                 │
        "color": ["white"],        │ - month ✓       │           │ Translation     │
        "grade": 3,                │ - color ✓       │           │ from i18n/*.json│
        "common": [...]            │ - grade ✓       │           └─────────────────┘
      },                           │ - common ✓      │
      "metadata": {...}            │ - name ✗        │  ← Uninitialized until
    }                              │   (uninitialized)│    applyTranslations()
  ]                                └─────────────────┘
}
```

### Translation Application

Each regional data class provides methods to populate the `name` property:

| Class             | Method                  | i18n Source                              |
|-------------------|-------------------------|------------------------------------------|
| `NationalData`    | `applyTranslations()`   | `i18n/{nation}/{locale}.json`            |
| `NationalData`    | `setNames()`            | External translation array               |
| `DiocesanData`    | `applyTranslations()`   | `i18n/{nation}/{diocese}/{locale}.json`  |
| `WiderRegionData` | `applyTranslations()`   | `i18n/{region}/{locale}.json`            |

**Example from NationalData:**

```php
public function applyTranslations(string $locale): void
{
    foreach ($this->litcal as $litcalItem) {
        $translation = $this->i18n->getTranslation($litcalItem->getEventKey(), $locale);
        if (null === $translation) {
            throw new \ValueError('translation not found for event key: ' . $litcalItem->getEventKey());
        }
        $litcalItem->setName($translation);  // ← Populates the computed `name` property
    }
}
```

### CalendarHandler Type Annotations

In `CalendarHandler`, `@var` annotations are used to narrow union types after conditional checks.
These annotations reference the DTO classes (not the `@phpstan-type` declarations) and assume
`name` has been populated via translations.

**Example type narrowing:**

```php
// Line 3383 - Union type before narrowing
/** @var LitCalItemCreateNewFixed|LitCalItemCreateNewMobile|LitCalItemMakePatron $liturgicalEvent */
$liturgicalEvent = $litEvent->liturgical_event;

// Line 3390 - Narrowed after property check
if (property_exists($liturgicalEvent, 'strtotime') && $liturgicalEvent->strtotime !== '') {
    /** @var LitCalItemCreateNewMobile $liturgicalEvent */
    // ... now PHPStan knows $liturgicalEvent has strtotime property
}
```

### Type Annotation Patterns

| Pattern                  | Purpose                    | Example                                                  |
|--------------------------|----------------------------|----------------------------------------------------------|
| `@phpstan-type`          | Define raw JSON structure  | `LitCalItemCreateNewFixedObject`                         |
| `@phpstan-import-type`   | Reuse types across files   | `@phpstan-import-type LitCalItemArray from LitCalItem`   |
| `@var ClassName $var`    | Narrow union types         | `/** @var LitCalItemMakePatron $liturgicalEvent */`      |
| `@param TypeName $param` | Document parameter types   | `@param LitCalItemObject $data`                          |

### Validation Summary

The type system has been verified to be coherent:

- **PHPStan level 10**: ✅ Passes with no errors
- **All tests**: ✅ 108 tests passing
- **Raw vs computed separation**: ✅ Correctly implemented
- **i18n flow**: ✅ `name` properly populated before use in CalendarHandler

### Key Design Decisions

1. **`@phpstan-type` = Raw Input**: Types describe JSON schema, not runtime object state
2. **`name` is Computed**: Never in source JSON, always from i18n lookup
3. **Translations Before Processing**: `applyTranslations()` must be called before accessing `name`
4. **Type Narrowing**: `@var` annotations help PHPStan understand conditional type refinement

---

## Appendix: Quick Reference

### JSON Schema Locations

| Schema                | Path                                         |
|-----------------------|----------------------------------------------|
| Diocesan Calendar     | `jsondata/schemas/DiocesanCalendar.json`     |
| National Calendar     | `jsondata/schemas/NationalCalendar.json`     |
| Wider Region Calendar | `jsondata/schemas/WiderRegionCalendar.json`  |
| Proprium de Sanctis   | `jsondata/schemas/PropriumDeSanctis.json`    |
| Proprium de Tempore   | `jsondata/schemas/PropriumDeTempore.json`    |
| Decrees Source        | `jsondata/schemas/LitCalDecreesSource.json`  |
| Unit Tests            | `jsondata/schemas/LitCalTest.json`           |

### API Endpoints for Write Operations

| Endpoint                 | Methods            | Handler               | Auth Required            |
|--------------------------|--------------------|-----------------------|--------------------------|
| `/data/diocese/{id}`     | PUT, PATCH, DELETE | `RegionalDataHandler` | Yes                      |
| `/data/nation/{id}`      | PUT, PATCH, DELETE | `RegionalDataHandler` | Yes                      |
| `/data/widerregion/{id}` | PUT, PATCH, DELETE | `RegionalDataHandler` | Yes                      |
| `/missals/{id}`          | PUT, PATCH, DELETE | `MissalsHandler`      | Yes (TBD)                |
| `/decrees/{id}`          | PUT, PATCH, DELETE | `DecreesHandler`      | Yes (TBD)                |
| `/tests/{id}`            | PUT, PATCH, DELETE | `TestsHandler`        | WARN: PUT without auth   |

> **Security Note:** The OpenAPI Evaluation Roadmap identified that `PUT /tests` currently lacks authentication.
> This should be fixed before production use. See `OPENAPI_EVALUATION_ROADMAP.md` for details.

### Frontend Entry Points

| Entity Type       | Frontend File                      | Main Function/Handler               |
|-------------------|------------------------------------|-------------------------------------|
| Diocesan Calendar | `extending.php?choice=diocesan`    | `saveDiocesanCalendar_btnClicked()` |
| National Calendar | `extending.php?choice=national`    | `serializeNationalCalendarData()`   |
| Wider Region      | `extending.php?choice=widerRegion` | `serializeWiderRegionData()`        |
| Missals           | `admin.php`                        | TBD (needs alignment)               |
| Decrees           | TBD                                | TBD                                 |
| Tests             | `UnitTestInterface/admin.php`      | TBD (needs modernization)           |

> **Note:** The UnitTestInterface is a separate repository. See the API Client Libraries Roadmap for details on
> UnitTestInterface modernization needs.
