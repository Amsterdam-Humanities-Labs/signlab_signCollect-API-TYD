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
  - `getAllThemas()`: Fetch all unique themas from sentences table
  - `getSentencesByThema($thema, $limit, $offset)`: Get sentences for specific thema with pagination
  - `getVideoDataForSentence($sentenceId)`: Get complete video data with glosses, thumbnails, and matched transcriptions
  - Extracts glosses from sentences.glosses field (JSON format)
  - Generates thumbnail URLs by converting .wav to .jpg extensions
  - **app_ready filtering disabled**: Returns all data regardless of app_ready status for comprehensive annotation workflows
- **SignbankService**: Integrates external Signbank database
- **FormService**: Handles SignCollect form/gloss data
  - Automatically converts spaces to hyphens in gloss searches
- **MocapService**: Motion capture data integration
- **NmmService**: Non-Manual Markers data
  - Automatically converts spaces to hyphens in gloss searches
  - **app_ready filtering disabled**: Returns all NMM data regardless of app_ready status
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
php tests/TestGetZinnen.php
php tests/TestGetZinnenThemas.php
php tests/TestGetZinnenVideos.php
php tests/TestNmmServiceAppReady.php
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
   - Parameters: 
     - `q` (query): Search term
     - `resultType` (optional): zinnen/glos/all (default: all)
     - `groupByTheme` (optional): true/false (default: false)
     - `limit` (optional): Results limit (default: 8, max: 100)
     - `offset` (optional): Pagination offset (default: 0)
   - Returns: sentences, glosses, words, synonyms
   - **Search Behavior**:
     - **Single-word queries**: Uses lemma-based search for sentences, space-to-hyphen conversion for glosses
     - **Multi-word queries**: Splits words and finds sentences containing ALL words (e.g., "mama broer" finds sentences with both words)
     - **Gloss searches**: Converts spaces to hyphens for matching (e.g., "niet waar" → "NIET-WAAR")

2. **Videos**: `/videos/{type}/{id}` or `/getVideos.php?type={type}&id={id}`
   - Types: zin, glos, sb, nmm
   - Returns: video URLs with multiple camera angles

3. **Themes**: `/themas` (cached) and `/thema/{themeName}`
   - Returns: theme listings and associated glosses

4. **Suggestions**: POST to `/index.php` with `suggestions=true`
   - Purpose: Provides intelligent autocomplete/typeahead functionality for search
   - Parameters: 
     - `query`: The partial search term (minimum 3 characters required)
     - `suggestions`: Must be set to `"true"` to trigger suggestion mode
   - Returns: Mixed suggestions (words + sentences) limited to 8 total items
   - **Suggestion Distribution**:
     - **Words**: Up to 3 suggestions from `hh_words` table
     - **Sentences**: Up to 3 suggestions from `sentences` table  
     - **Lemmas**: Up to 1 suggestion (temporarily disabled)
     - **Synonyms**: Up to 1 suggestion (temporarily disabled)
   - **Multi-word Support**: Queries like "mama broer" find sentences containing both words
   - Implementation: 
     - Uses enhanced `SuggestionService` class in `src/services/SuggestionService.php`
     - Word suggestions: Search `hh_words` table for words starting with query
     - Sentence suggestions: Lemma-based search (single word) or multi-word AND logic
     - Sentence truncation: Long sentences truncated to 60 chars with "..."
     - Note: app_ready validation has been disabled for ZIN Project endpoints
   - Examples: 
     - Single word: `curl -X POST -d "query=mama&suggestions=true" https://api.signcollect.nl/index.php`
     - Multi-word: `curl -X POST -d "query=mama broer&suggestions=true" https://api.signcollect.nl/index.php`

5. **Random Video**: `/getRandomVideo.php`
   - Returns: single random video from form_data with 24-hour caching
   - No parameters required
   - Response includes: id, glos, senses, thema, videos (3 angles), nmm_data

## Recent Changes: app_ready Filtering Disabled

**Important Update**: The `app_ready` filtering has been disabled across all ZIN Project endpoints and related services to support comprehensive annotation workflows. This affects:

### Modified Services
- **SentenceService**: 
  - `getMatchedTranscriptionsByFormId()`: No longer filters by `app_ready = 1`
  - `getSentenceVideoData()`: No longer filters by `app_ready = 1`
- **NmmService**:
  - `searchNmmByGlos()`: No longer checks for `app_ready = 1` videos
  - `_fetchLastVideoSet()`: No longer filters by `app_ready = 1`

### Impact
- All sentences, videos, and NMM data are now returned regardless of readiness status
- Enables annotation of work-in-progress content
- Provides complete data access for comprehensive annotation workflows
- May include videos that are still being processed or reviewed

### Testing
- New test files created to verify app_ready filtering is properly disabled
- Existing tests updated to reflect the new behavior

## ZIN Project Sentence Management Endpoints

The API provides specialized endpoints for the ZIN Project's sentence annotation tool:

6. **Get All Themas**: `/getZinnenThemas.php`
   - Returns: all unique themas from the sentences table
   - No parameters required
   - Usage: `curl https://api.signcollect.nl/getZinnenThemas.php`
   - Response includes: array of 90+ unique themas

7. **Get Sentences by Thema**: `/getZinnen.php`
   - Parameters: 
     - `thema` (required): The thema to filter by
     - `limit` (optional): Maximum results (default: 100, max: 1000)
     - `offset` (optional): Pagination offset (default: 0)
   - Returns: sentences for the specified thema with pagination
   - Usage: `curl "https://api.signcollect.nl/getZinnen.php?thema=Aankleden&limit=20"`
   - Response includes: sentences array, count, pagination info

8. **Get Video Data for Sentence**: `/getZinnenVideos.php`
   - Parameters: `sentenceId` (required): The sentence ID to fetch video data for
   - Returns: complete video data with glosses, thumbnails, and individual gloss videos
   - Usage: `curl "https://api.signcollect.nl/getZinnenVideos.php?sentenceId=4"`
   - **Response Structure Explained**:
     - `sentenceVideos`/`sentenceThumbnails`: Videos for the complete sentence from matched_transcriptions with zOg='zin'
     - `glossVideosData`: Array of individual gloss video data, each containing:
       - `gloss`: The gloss string
       - `videos`/`thumbnails`: Video URLs for this specific gloss (3 camera angles)
       - `formDataId`/`nmmId`: Source record IDs (one will be null, depending on data source)
       - `dataSource`: Indicates origin ('form_data', 'nmm_data', or 'mixed' for multiple sources)
     - `formDataIds`/`nmmIds`: Summary arrays of all IDs used
     - `glossDataSource`: Overall data source summary ('form_data', 'nmm_data', 'mixed', or null)
   - Response format:
```json
{
  "success": true,
  "data": {
    "sentenceId": 4,
    "zinString": "Doe maar je armen omhoog.",
    "glosses": ["PT-1hand", "HAND-OMHOOG"],
    "sentenceVideos": {
      "left": "https://media.signcollect.nl/L20250522_9306.mp4",
      "center": "https://media.signcollect.nl/M20250522_8329.mp4",
      "right": "https://media.signcollect.nl/R20250522_1935.mp4"
    },
    "sentenceThumbnails": {
      "left": "https://media.signcollect.nl/L20250522_9306.jpg",
      "center": "https://media.signcollect.nl/M20250522_8329.jpg",
      "right": "https://media.signcollect.nl/R20250522_1935.jpg"
    },
    "glossVideosData": [
      {
        "gloss": "PT-1hand",
        "videos": {
          "left": "https://media.signcollect.nl/L20250601_1234.mp4",
          "center": "https://media.signcollect.nl/M20250601_5678.mp4",
          "right": "https://media.signcollect.nl/R20250601_9012.mp4"
        },
        "thumbnails": {
          "left": "https://media.signcollect.nl/L20250601_1234.jpg",
          "center": "https://media.signcollect.nl/M20250601_5678.jpg",
          "right": "https://media.signcollect.nl/R20250601_9012.jpg"
        },
        "formDataId": 12345,
        "nmmId": null,
        "dataSource": "form_data"
      },
      {
        "gloss": "HAND-OMHOOG",
        "videos": {
          "left": "https://media.signcollect.nl/L20250602_3456.mp4",
          "center": "https://media.signcollect.nl/M20250602_7890.mp4",
          "right": "https://media.signcollect.nl/R20250602_1234.mp4"
        },
        "thumbnails": {
          "left": "https://media.signcollect.nl/L20250602_3456.jpg",
          "center": "https://media.signcollect.nl/M20250602_7890.jpg",
          "right": "https://media.signcollect.nl/R20250602_1234.jpg"
        },
        "formDataId": null,
        "nmmId": 6789,
        "dataSource": "nmm_data"
      }
    ],
    "formDataIds": [12345],
    "nmmIds": [6789],
    "glossDataSource": "mixed"
  }
}
```

### ZIN Project Workflow
1. **Fetch Themas**: Use `/getZinnenThemas.php` to populate thema dropdown
2. **Browse Sentences**: Use `/getZinnen.php?thema=X` to get sentences for selected thema
3. **Load Video Data**: Use `/getZinnenVideos.php?sentenceId=X` to get complete video/gloss data
4. **Annotation**: Use returned glosses, videos, and thumbnails for timeline annotation

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
1. **Sentences (zin)**: From `sentences` table linked via `matched_transcriptions` with `zOg='zin'` (app_ready filtering disabled)
2. **Form Data (glos)**: From `form_data` table with `extern='1'` and `glosZichtbaar='0'` linked via `matched_transcriptions` with `zOg IN ('glos', 'extern')` (app_ready filtering disabled)
3. **NMM Data**: From `nmm_data` table linked via `matched_transcriptions` with `zOg='nmm'` (app_ready filtering disabled)

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