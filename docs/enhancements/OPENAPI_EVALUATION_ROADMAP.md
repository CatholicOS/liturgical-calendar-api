# OpenAPI Schema Evaluation Roadmap

This document evaluates the current OpenAPI schema (`jsondata/schemas/openapi.json`) against the API Client Libraries Roadmap and REST API best practices.

## Current State Summary

**OpenAPI Version:** 3.x
**API Version:** 5.0

### Documented Endpoints

| Path                                     | Methods                  | Tag                  |
|------------------------------------------|--------------------------|----------------------|
| `/auth/login`                            | POST                     | Authentication       |
| `/auth/refresh`                          | POST                     | Authentication       |
| `/calendar`                              | GET, POST                | Main API endpoint    |
| `/calendar/{year}`                       | GET, POST                | Main API endpoint    |
| `/calendar/nation/{calendar_id}`         | GET, POST                | Main API endpoint    |
| `/calendar/nation/{calendar_id}/{year}`  | GET, POST                | Main API endpoint    |
| `/calendar/diocese/{calendar_id}`        | GET, POST                | Main API endpoint    |
| `/calendar/diocese/{calendar_id}/{year}` | GET, POST                | Main API endpoint    |
| `/calendars`                             | GET, POST                | Calendars Index      |
| `/data/nation`                           | PUT                      | Calendar Source Data |
| `/data/nation/{key}`                     | GET, POST, PATCH, DELETE | Calendar Source Data |
| `/data/diocese`                          | PUT                      | Calendar Source Data |
| `/data/diocese/{key}`                    | GET, POST, PATCH, DELETE | Calendar Source Data |
| `/data/widerregion`                      | PUT                      | Calendar Source Data |
| `/data/widerregion/{key}`                | GET, POST, PATCH, DELETE | Calendar Source Data |
| `/decrees`                               | GET                      | Decrees              |
| `/decrees/{decree_id}`                   | GET                      | Decrees              |
| `/easter`                                | GET                      | Easter               |
| `/events`                                | GET, POST                | Liturgical events    |
| `/events/nation/{calendar_id}`           | GET, POST                | Liturgical events    |
| `/events/diocese/{calendar_id}`          | GET, POST                | Liturgical events    |
| `/missals`                               | GET                      | Missals              |
| `/missals/{missal_id}`                   | GET                      | Missals              |
| `/tests`                                 | GET, PUT                 | Unit Tests           |
| `/tests/{test_name}`                     | GET                      | Unit Tests           |

### Missing Endpoints

| Path                  | Required | Notes                      |
|-----------------------|----------|----------------------------|
| `/schemas`            | GET      | Not documented in OpenAPI  |
| `/schemas/{schema_id}`| GET      | Not documented in OpenAPI  |

## Gap Analysis: Missing CRUD Operations

Based on the API Client Libraries Roadmap, the following CRUD operations are missing:

### `/decrees` Endpoint

**Tag description states:** "Retrieve / create / update / delete Decrees..."

**Current:** GET only

**Missing:**

| Path                   | Method | Operation              | Authentication |
|------------------------|--------|------------------------|----------------|
| `/decrees`             | PUT    | Create new decree      | Required       |
| `/decrees/{decree_id}` | PATCH  | Update existing decree | Required       |
| `/decrees/{decree_id}` | DELETE | Delete decree          | Required       |

### `/missals` Endpoint

**Tag description states:** "Retrieve / create / update / delete Roman Missal definitions"

**Current:** GET only

**Missing:**

| Path                   | Method | Operation              | Authentication |
|------------------------|--------|------------------------|----------------|
| `/missals`             | PUT    | Create new missal      | Required       |
| `/missals/{missal_id}` | PATCH  | Update existing missal | Required       |
| `/missals/{missal_id}` | DELETE | Delete missal          | Required       |

### `/tests` Endpoint

**Tag description states:** "Retrieve / create / update / delete unit tests"

**Current:** GET (collection), GET (single), PUT (create on collection)

**Missing:**

| Path                 | Method | Operation            | Authentication |
|----------------------|--------|----------------------|----------------|
| `/tests/{test_name}` | PATCH  | Update existing test | Required       |
| `/tests/{test_name}` | DELETE | Delete test          | Required       |

**Issue:** PUT on `/tests` doesn't require authentication (security is empty `{}`).

### `/schemas` Endpoint

**Not documented at all in OpenAPI.**

**Required:**

| Path                   | Method | Operation                | Authentication |
|------------------------|--------|--------------------------|----------------|
| `/schemas`             | GET    | List available schemas   | None           |
| `/schemas/{schema_id}` | GET    | Retrieve specific schema | None           |

## Path Structure Analysis

### Issue 1: Inconsistent Parameter Naming

Current parameter names vary across endpoints:

| Endpoint                           | Parameter     | Pattern                          |
|------------------------------------|---------------|----------------------------------|
| `/data/nation/{key}`               | `key`         | Generic                          |
| `/data/diocese/{key}`              | `key`         | Generic                          |
| `/data/widerregion/{key}`          | `key`         | Generic                          |
| `/calendar/nation/{calendar_id}`   | `calendar_id` | Specific                         |
| `/calendar/diocese/{calendar_id}`  | `calendar_id` | Specific                         |
| `/decrees/{decree_id}`             | `decree_id`   | Specific                         |
| `/missals/{missal_id}`             | `missal_id`   | Specific                         |
| `/tests/{test_name}`               | `test_name`   | Specific (but uses name, not id) |

**Recommendation:** Standardize to `{resource_id}` pattern:

- `/data/nation/{nation_id}` or `/data/nation/{calendar_id}`
- `/data/diocese/{diocese_id}` or `/data/diocese/{calendar_id}`
- `/data/widerregion/{region_id}` or `/data/widerregion/{calendar_id}`
- `/tests/{test_id}` (instead of `{test_name}`)

### Issue 2: Inconsistent HTTP Method Usage

#### POST as Alternative Parameter Passing

Several endpoints accept both GET and POST for the same read operation:

- `/calendar` - GET and POST both retrieve calendar
- `/calendars` - GET and POST both retrieve metadata
- `/events` - GET and POST both retrieve events
- `/data/diocese/{key}` - POST duplicates GET functionality

**Design Decision (Acceptable):** POST is used as an alternative to GET for passing parameters in the request body
(JSON or form-data) rather than query strings. This is a valid pattern when:

- Query parameters would be too long or complex
- Structured data (nested objects, arrays) needs to be passed
- Proxies/firewalls impose URL length limits
- Consistent parameter handling across formats is desired

This pattern is used by GraphQL, Elasticsearch, and many production APIs.

**Documentation Recommendation:** The OpenAPI schema should clearly document that POST on read endpoints is for
parameter passing, not resource creation. Consider adding a note in the operation description:

```yaml
post:
  summary: "Retrieve calendar (alternative to GET with body parameters)"
  description: "Accepts parameters in request body as JSON or form-data as an alternative to query parameters."
```

#### PUT on Collection vs Resource

Current pattern for creation:

```text
PUT /data/diocese      → Creates new diocese (ID in body)
PUT /data/diocese/{key} → Should be update, but PATCH also exists
```

**REST Best Practice:**

- `POST /collection` → Create (server generates ID or ID in body)
- `PUT /collection/{id}` → Create or replace (client provides ID)
- `PATCH /collection/{id}` → Partial update

**Current confusion:**

- PUT on collection (`/data/diocese`) creates resource
- PATCH on resource (`/data/diocese/{key}`) updates resource
- What does PUT on resource do? (Not documented)

**Recommendation:** Choose one pattern:

**Option A (Standard REST):**

```text
POST /data/diocese           → Create (201 Created, Location header)
GET /data/diocese/{id}       → Read
PUT /data/diocese/{id}       → Replace entirely
PATCH /data/diocese/{id}     → Partial update
DELETE /data/diocese/{id}    → Delete
```

**Option B (Current with clarification):**

```text
PUT /data/diocese            → Create new (ID derived from body)
GET /data/diocese/{id}       → Read
PATCH /data/diocese/{id}     → Update
DELETE /data/diocese/{id}    → Delete
```

### Issue 3: Missing Authentication on Tests PUT

```json
"/tests": {
  "put": {
    "security": [{}]  // Empty = no authentication required
  }
}
```

This allows unauthenticated users to create tests. Should require `BearerAuth`.

### Issue 4: Path Hierarchy Inconsistency

Calendar data retrieval uses different patterns:

**Pattern A - Nested resource:**

```text
/calendar/nation/{calendar_id}
/calendar/diocese/{calendar_id}
```

**Pattern B - Query parameter:**

```text
/calendar?national_calendar=USA
/calendar?diocesan_calendar=BOSTON
```

**Pattern C - Flat with category:**

```text
/data/nation/{key}
/data/diocese/{key}
```

**Recommendation:** Standardize on one pattern. Options:

1. **Nested resources (RESTful):**

   ```text
   /calendars/nations/{id}
   /calendars/nations/{id}/calculated/{year}
   /calendars/dioceses/{id}
   /calendars/dioceses/{id}/calculated/{year}
   ```

2. **Flat with type prefix (current hybrid):**

   ```text
   /data/national/{id}
   /data/diocesan/{id}
   /calendar/national/{id}
   /calendar/diocesan/{id}
   ```

## Response Code Consistency

### Issue: PATCH Returns 201 Created

```json
"patch": {
  "responses": {
    "201": {
      "description": "201 Created. Even when updating..."
    }
  }
}
```

**REST Best Practice:**

- `201 Created` → New resource created
- `200 OK` → Existing resource updated
- `204 No Content` → Update successful, no body

**Recommendation:** PATCH should return `200 OK` for updates.

## Security Gaps

### Endpoints Missing Authentication

| Endpoint | Method | Current | Should Be  |
|----------|--------|---------|------------|
| `/tests` | PUT    | No auth | BearerAuth |

**Note:** POST on `/data/{category}/{key}` endpoints is acceptable without authentication since POST is used for parameter passing (read operation), not resource creation.

## Implementation Roadmap

### Phase 1: Add Missing CRUD Operations

**Priority: High**

1. **Decrees CRUD**
   - [ ] Add `PUT /decrees` for creation
   - [ ] Add `PATCH /decrees/{decree_id}` for updates
   - [ ] Add `DELETE /decrees/{decree_id}` for deletion
   - [ ] Add BearerAuth security to all mutating operations

2. **Missals CRUD**
   - [ ] Add `PUT /missals` for creation
   - [ ] Add `PATCH /missals/{missal_id}` for updates
   - [ ] Add `DELETE /missals/{missal_id}` for deletion
   - [ ] Add BearerAuth security to all mutating operations

3. **Tests CRUD completion**
   - [ ] Add `PATCH /tests/{test_name}` for updates
   - [ ] Add `DELETE /tests/{test_name}` for deletion
   - [ ] Fix security on `PUT /tests` to require BearerAuth

4. **Schemas endpoint**
   - [ ] Document `GET /schemas`
   - [ ] Document `GET /schemas/{schema_id}`

### Phase 2: Fix Authentication Gaps

**Priority: High**

- [ ] Add BearerAuth to `PUT /tests`
- [ ] Audit all mutating endpoints for proper security

### Phase 3: Standardize Path Parameters

**Priority: Medium**

- [ ] Rename `{key}` to `{calendar_id}` in `/data` endpoints
- [ ] Rename `{test_name}` to `{test_id}` in `/tests` endpoints
- [ ] Update all references in components/parameters

### Phase 4: Improve Documentation

**Priority: Medium**

- [ ] Document POST-for-parameters pattern in operation descriptions (see "POST as Alternative Parameter Passing" above)
- [ ] Clarify PUT vs PATCH semantics in descriptions
- [ ] Update response codes (PATCH should return 200, not 201)

### Phase 5: Path Structure Refactoring (Breaking Change)

**Priority: Low (requires API version bump)**

Consider restructuring paths for consistency:

```text
# Current
/data/nation/{key}
/calendar/nation/{calendar_id}

# Proposed (more RESTful)
/calendars/national/{id}
/calendars/national/{id}/data
/calendars/national/{id}/calculated
/calendars/national/{id}/calculated/{year}
```

This would be a breaking change requiring a new API version.

## Schema Completeness Checklist

### Request Body Schemas

| Endpoint            | Method | Schema Defined                   |
|---------------------|--------|----------------------------------|
| `/data/nation`      | PUT    | [x] NationalCalendar.json        |
| `/data/diocese`     | PUT    | [x] DiocesanCalendar.json        |
| `/data/widerregion` | PUT    | [x] WiderRegionCalendar.json     |
| `/tests`            | PUT    | [x] UnitTest schemas             |
| `/decrees`          | PUT    | [ ] Missing (needs LitCalDecree) |
| `/missals`          | PUT    | [ ] Missing (needs Missal)       |

### Response Schemas

| Endpoint   | Method | Schema Defined              |
|------------|--------|-----------------------------|
| `/decrees` | GET    | [x] LitCalDecreesSource.json|
| `/missals` | GET    | [x] LitCalMissalsPath.json  |
| `/tests`   | GET    | [x] UnitTestArray           |
| `/schemas` | GET    | [ ] Not documented          |

## Related Documents

- **API Client Libraries Roadmap:** `docs/API_CLIENT_LIBRARIES_ROADMAP.md`
- **Serialization Roadmap:** `docs/enhancements/SERIALIZATION_ROADMAP.md`
- **API Issue #265:** Resource creation/updating refactoring

## OpenAPI Linting

Run Redocly linter to check for additional issues:

```bash
composer lint:openapi
```

Common issues to watch for:

- Missing operation IDs
- Inconsistent naming conventions
- Missing examples
- Undefined schema references
