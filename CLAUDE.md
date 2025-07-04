# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## SignCollect API Overview

This is a RESTful API providing access to sign language data including videos, glosses, sentences, and themes. The API serves as a backend for sign language applications, particularly the ZIN Project annotation tool.

## API Architecture

### Service Layer Structure
The API uses a service-oriented architecture with specialized services in `src/services/`:
- **SearchService**: Core search functionality across all data types
  - Converts spaces to hyphens for gloss searches (e.g., "niet waar" → "NIET-WAAR")
  - Filters out gloss variants ending with -B through -Z patterns
- **VideoService**: Handles video URL generation for entities
- **SentenceService**: Manages sentence data and relationships
- **SignbankService**: Integrates external Signbank database
- **FormService**: Handles SignCollect form/gloss data
  - Automatically converts spaces to hyphens in gloss searches
- **MocapService**: Motion capture data integration
- **NmmService**: Non-Manual Markers data
  - Automatically converts spaces to hyphens in gloss searches
- **SuggestionService**: Autocomplete functionality
  - Requires minimum 3 characters for suggestions
  - Returns up to 10 suggestions per category
  - Three methods: `getWordSuggestions()`, `getLemmaSuggestions()`, `getSynonymSuggestions()`
  - Currently only word suggestions are enabled in responses
- **RandomVideoService**: Fetches random videos from form_data
- **ApiLogger**: Request/performance logging

### Admin Interface
The API includes a web-based admin interface at `/admin/` for content management:
- **Video Management**: Browse and manage sign language videos with square aspect ratio display
- **Status Control**: Toggle video readiness status between "Ready" and "Not Ready"
- **Hover Playback**: Videos auto-play on hover for quick preview
- **Filtering**: Filter by status, theme, and search terms
- **Bulk Operations**: Select multiple videos for batch status updates
- **Statistics Dashboard**: Real-time counts of ready vs not-ready videos

### Database Integration
- Direct MySQLi connections with UTF-8 encoding
- Configuration files: `mysql_config.php` (production), `mysql_config_test.php` (testing)
- Key tables: `zinnen` (sentences), `videos`, `form_data` (glosses), `themas` (themes), `sb_records` (Signbank)

## Development Commands

### Running Tests
```bash
# Run all tests
php tests/TestRunner.php

# Run specific test file
php tests/TestVideoServices.php
php tests/TestSearchServices.php
php tests/TestSentenceServices.php
```

### Testing the API
- Use `test.html` in a browser for interactive API testing
- Direct endpoint testing: `php index.php` (supports CLI mode)

### Cache Management
```bash
# Clear theme cache
rm cache/themas.json

# Clear random video cache
rm cache/random_video.json

# Caches are auto-generated on first request after clearing:
# - Theme cache: regenerated on /themas endpoint
# - Random video cache: regenerated on /getRandomVideo.php endpoint (24-hour cache)
```

## API Endpoints and Usage

### Main Endpoints
1. **Search**: `/search/{query}` or POST to `/index.php`
   - Parameters: `q` (query), `resultType` (zinnen/glos/all), `groupByTheme` (true/false)
   - Returns: sentences, glosses, words, synonyms
   - Note: Spaces in gloss searches are automatically converted to hyphens (e.g., "niet waar" → "NIET-WAAR")

2. **Videos**: `/videos/{type}/{id}` or `/getVideos.php?type={type}&id={id}`
   - Types: zin, glos, sb, nmm
   - Returns: video URLs with multiple camera angles

3. **Themes**: `/themas` (cached) and `/thema/{themeName}`
   - Returns: theme listings and associated glosses

4. **Suggestions**: POST to `/index.php` with `suggestions=true`
   - Purpose: Provides autocomplete/typeahead functionality for search
   - Parameters: 
     - `query`: The partial search term (minimum 3 characters required)
     - `suggestions`: Must be set to `"true"` to trigger suggestion mode
   - Returns: Array of word suggestions with their lemmas
   - Implementation: 
     - Uses `SuggestionService` class in `src/services/SuggestionService.php`
     - Searches `hh_words` table for words starting with the query
     - Returns up to 10 suggestions ordered alphabetically
     - Currently only returns word suggestions (lemmas and synonyms temporarily disabled)
   - Example: `curl -X POST -d "query=hui&suggestions=true" https://api.signcollect.nl/index.php`

5. **Random Video**: `/getRandomVideo.php`
   - Returns: single random video from form_data with 24-hour caching
   - No parameters required
   - Response includes: id, glos, senses, thema, videos (3 angles), nmm_data

## Important Technical Details

### URL Structure
- Base API: `https://api.signcollect.nl`
- Media files: `https://media.signcollect.nl/`
- Subtitles: `https://media.signcollect.nl/zin/eaf/zin/`

### Response Format
All responses follow this structure:
```json
{
  "success": true/false,
  "data": {...},
  "error": "error message if applicable"
}
```

### Error Handling
- Development mode: Full error reporting (configured in `ErrorReporting.php`)
- Production mode: Limited error output
- API logs stored via ApiLogger service

### Security Headers
Applied via `SecurityHeaders.php`:
- CORS enabled for cross-origin requests
- Content-Type enforcement
- XSS protection headers

## Related Systems

This API works in conjunction with:
- **ZIN Project**: Web-based sign language annotation tool (../subBeta3.html, ../getZinnen.php)
- **SignCollect Database**: Primary data source for glosses and videos
- **Signbank**: External gloss database integration

## Common Development Tasks

### Adding New Service
1. Create service class in `src/services/`
2. Follow existing pattern: constructor with DB connection, public methods for operations
3. Add service initialization in relevant endpoint file
4. Create corresponding test in `tests/`

### Modifying Search Behavior
- Primary logic in `SearchService::search()` method
- Search combines results from sentences, glosses, words, and synonyms
- Theme grouping handled by `groupGlossesByTheme()` method

### Adding New Endpoint
1. Create PHP file in root directory
2. Include security headers and error reporting
3. Use `.htaccess` for clean URL routing if needed
4. Follow existing response format pattern

## Video URL Testing & Validation

### Overview
The API serves videos from `https://media.signcollect.nl/` with three camera angles:
- **links** (left camera)
- **midden** (center camera)  
- **rechts** (right camera)

### Data Sources for Videos
1. **Sentences (zin)**: From `sentences` table linked via `matched_transcriptions` with `zOg='zin'`
2. **Form Data (glos)**: From `form_data` table with `extern='1'` and `glosZichtbaar='0'` linked via `matched_transcriptions` with `zOg IN ('glos', 'extern')`
3. **NMM Data**: From `nmm_data` table linked via `matched_transcriptions` with `zOg='nmm'`

### Priority System for Gloss Data
When both `form_data` and `nmm_data` contain records with the same `glos` value:
- **form_data takes priority** and overrides nmm_data
- This prevents duplicate testing of the same conceptual gloss
- Deduplication is based on the `glos` field value

### Video URL Testing Commands
```bash
# Test all video URLs (sentences, form_data, nmm_data with deduplication)
php test_video_urls_complete.php

# Test only form_data videos
php test_form_videos.php

# Test only NMM videos  
php test_nmm_videos.php

# Check results
cat failed.json
```

### Video URL Testing Results
Results are saved to `failed.json` with this structure:
```json
{
  "timestamp": "2025-06-10 19:14:08",
  "statistics": {
    "total_tested": 15512,
    "total_failed": 186,
    "sentences": {"tested": 6386, "failed": 164},
    "form_data": {"tested": 3000, "failed": 50},
    "nmm": {"tested": 9126, "failed": 22},
    "deduplication": {
      "nmm_records_skipped_for_form_data": 234,
      "unique_glos_values_processed": 2800
    }
  },
  "failed_urls": [
    {
      "type": "form_data",
      "id": "123",
      "name": "EXAMPLE-GLOS",
      "angle": "links",
      "url": "https://media.signcollect.nl/L20250610_1234.mp4"
    }
  ]
}
```

### Video URL Testing Implementation
- Uses `VideoService->getVideosForEntity($id, $type)` for URL generation
- Tests HTTP 200 response for each camera angle
- Implements curl-based URL validation with 10-second timeout
- Supports both development and production media URL testing
- Tracks duplicate URLs to avoid redundant testing