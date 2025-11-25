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

---

## Entity Types Requiring Implementation

### 1. Regional Calendar Data (`/data` endpoint)

| Entity Type       | Schema File                | Model Class      | Frontend Form                     | Status                           |
|-------------------|----------------------------|------------------|-----------------------------------|----------------------------------|
| Diocesan Calendar | `DiocesanCalendar.json`    | `DiocesanData`   | `extending.php?choice=diocesan`   | PUT: Broken, PATCH/DELETE: TBD   |
| National Calendar | `NationalCalendar.json`    | `NationalData`   | `extending.php?choice=national`   | PUT/PATCH: Partial, DELETE: TBD  |
| Wider Region      | `WiderRegionCalendar.json` | `WiderRegionData`| `extending.php?choice=widerRegion`| PUT/PATCH: Partial, DELETE: TBD  |

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

#### 1.1 Implement `JsonSerializable` Interface

All model classes that are serialized to JSON for storage must implement `JsonSerializable`.

**Affected base classes:**

- `AbstractJsonSrcData`
- `AbstractJsonSrcDataArray`

**Affected model classes (Diocesan):**

- `DiocesanData`
- `DiocesanLitCalItemCollection`
- `DiocesanLitCalItem`
- `DiocesanLitCalItemCreateNewFixed`
- `DiocesanLitCalItemCreateNewMobile`
- `DiocesanLitCalItemMetadata`
- `DiocesanMetadata`

**Affected model classes (National):**

- `NationalData`
- `NationalMetadata`
- `LitCalItemCreateNewFixed`
- `LitCalItemCreateNewMobile`
- `LitCalItemCreateNewMetadata`
- `LitCalItemMakePatron`
- `LitCalItemMakePatronMetadata`
- `LitCalItemMoveEvent`
- `LitCalItemMoveEventMetadata`
- `LitCalItemSetPropertyGrade`
- `LitCalItemSetPropertyGradeMetadata`
- `LitCalItemSetPropertyName`
- `LitCalItemSetPropertyNameMetadata`

**Affected model classes (Wider Region):**

- `WiderRegionData`
- `WiderRegionMetadata`

**Pattern for implementation:**

```php
final class DiocesanLitCalItemCollection extends AbstractJsonSrcDataArray
    implements \IteratorAggregate, \JsonSerializable
{
    /** @var DiocesanLitCalItem[] */
    public readonly array $litcalItems;

    /**
     * Serialize to JSON as a plain array (not wrapped in object).
     * @return array<int, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            fn(DiocesanLitCalItem $item) => $item->jsonSerialize(),
            $this->litcalItems
        );
    }
}
```

#### 1.2 Add Post-Serialization Validation

Before saving any data to disk, validate the serialized output against the schema:

```php
// In createDiocesanCalendar(), createNationalCalendar(), etc.
$calendarData = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Validate output against schema before saving
$serializedPayload = json_decode($calendarData);
if (!self::validateDataAgainstSchema($serializedPayload, LitSchema::DIOCESAN->path())) {
    throw new ImplementationException(
        'Internal serialization error: output does not conform to schema'
    );
}

// Only save if validation passes
file_put_contents($diocesanCalendarFile, $calendarData . PHP_EOL);
```

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

- [ ] Implement `JsonSerializable` on all diocesan model classes
- [ ] Add post-serialization validation in `createDiocesanCalendar()`
- [ ] Add post-serialization validation in `updateDiocesanCalendar()` (when implemented)
- [ ] Implement `deleteDiocesanCalendar()` fully
- [ ] Write PHPUnit tests for serialization round-trip

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

- [ ] Implement `JsonSerializable` on all national model classes
- [ ] Add post-serialization validation in `createNationalCalendar()`
- [ ] Review/complete `updateNationalCalendar()` implementation
- [ ] Implement `deleteNationalCalendar()` fully
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

- [ ] Implement `JsonSerializable` on wider region model classes
- [ ] Complete `createWiderRegionCalendar()` implementation
- [ ] Complete `updateWiderRegionCalendar()` implementation
- [ ] Complete `deleteWiderRegionCalendar()` implementation

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

### Immediate (Fix Current Bug)

1. Implement `JsonSerializable` on `DiocesanData` and related classes
2. Add post-serialization validation in `createDiocesanCalendar()`
3. Fix the malformed `Sede suburbicaria di Albano.json` file
4. Write tests to prevent regression

### Short-term (Complete Diocesan Implementation)

1. Complete PATCH implementation for diocesan calendars
2. Complete DELETE implementation for diocesan calendars
3. Add frontend validation
4. Write comprehensive tests

### Medium-term (National, Wider Region, and Missals)

1. Apply same pattern to `NationalData` and related classes
2. Apply same pattern to `WiderRegionData` and related classes
3. Design and implement missals model classes following established patterns
4. Align `admin.php` missals handling with `extending.js` patterns
5. Complete all CRUD operations for these entity types
6. Update frontend forms as needed

### Long-term (Decrees and Tests)

1. Design and implement decrees model classes
2. Implement CRUD handlers
3. Design and implement frontend UI
4. Comprehensive testing

---

## File Changes Summary

### API Backend Files to Modify

```text
src/Models/AbstractJsonSrcData.php          # Add JsonSerializable support
src/Models/AbstractJsonSrcDataArray.php     # Add JsonSerializable support

src/Models/RegionalData/DiocesanData/
├── DiocesanData.php                        # Implement JsonSerializable
├── DiocesanLitCalItem.php                  # Implement JsonSerializable
├── DiocesanLitCalItemCollection.php        # Implement JsonSerializable
├── DiocesanLitCalItemCreateNewFixed.php    # Implement JsonSerializable
├── DiocesanLitCalItemCreateNewMobile.php   # Implement JsonSerializable
├── DiocesanLitCalItemMetadata.php          # Implement JsonSerializable
└── DiocesanMetadata.php                    # Implement JsonSerializable

src/Models/RegionalData/NationalData/
├── NationalData.php                        # Implement JsonSerializable
├── NationalMetadata.php                    # Implement JsonSerializable
└── ... (all other model classes)

src/Models/RegionalData/WiderRegionData/
├── WiderRegionData.php                     # Implement JsonSerializable
└── WiderRegionMetadata.php                 # Implement JsonSerializable

src/Handlers/RegionalDataHandler.php        # Add post-serialization validation
src/Handlers/MissalsHandler.php             # Implement PUT/PATCH/DELETE with validation
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
