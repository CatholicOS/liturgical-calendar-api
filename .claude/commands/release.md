# Prepare a New Release

Prepare a new release for the Liturgical Calendar API.

## Step 1: Update API Version

Update the API version in two locations:

1. **CalendarHandler.php** (`src/Handlers/CalendarHandler.php`):
   - Find `public const API_VERSION = 'X.Y';`
   - Increment to the next minor version (e.g., `5.6` → `5.7`)
   - If there are breaking changes, increment the major version instead (e.g., `5.6` → `6.0`)

2. **openapi.json** (`jsondata/schemas/openapi.json`):
   - Find `"version": "X.Y"` in the `info` section
   - Update to match the new version

## Step 2: Update CHANGELOG.md

Add a new entry at the top of the changelog (after any commented unreleased section):

1. **Format**: `## [vX.Y](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/releases/tag/vX.Y) (Month DDth YYYY)`

2. **Changes**: List the changes made since the last release as bullet points. Check git log for commits since the last release tag.

3. **Liturgical celebration note**: Check if today has a significant liturgical celebration:
   - First, try fetching from local API: `http://localhost:8000/calendar?year_type=CIVIL`
   - If local is not running, use: `https://litcal.johnromanodorazio.com/api/dev/calendar?year_type=CIVIL`
   - Use `Accept-Language: en-US` header
   - Filter the `litcal` results for entries where `date` matches today's date formatted as RFC 3339 (e.g., `2025-12-15T00:00:00+00:00`)
   - If there's a celebration with grade >= FEAST (grade 5 or higher), or it's a notable memorial, add a note similar to previous entries:
     - "Saint X, pray for us! [emoji]"
     - "Happy Feast of X! [emoji]"
     - "X, [relevant prayer/exclamation]! [emoji]"
   - Look at previous changelog entries for style examples

## Step 3: Commit and Push

1. Stage the changed files:
   - `src/Handlers/CalendarHandler.php`
   - `jsondata/schemas/openapi.json`
   - `CHANGELOG.md`

2. Commit with message: `Release vX.Y`

3. Push to the `development` branch

## Step 4: Open Pull Request

Create a PR from `development` to `stable`:

```bash
gh pr create --base stable --head development --title "Release vX.Y" --body "$(cat <<'EOF'
## Release vX.Y

[Summary of changes from the changelog]

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Return the PR URL when complete.

---

**Note**: If unsure whether changes are breaking, ask the user before proceeding.
