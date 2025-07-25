# SignCollect API

The SignCollect API provides access to a collection of sign language videos, glosses, and sentences.

## Base URL

`https://api.signcollect.nl`

## Endpoints

### Search

*   **URL:** `/search/{query}`
*   **Method:** GET (POST is also supported with `query` parameter in the body)
*   **Description:** Searches for words, sentences, and glosses related to the query. Supports both single-word and multi-word searches.
*   **Search Behavior:**
    *   **Single-word queries**: Uses lemma-based search for sentences, space-to-hyphen conversion for glosses
    *   **Multi-word queries**: Splits words and finds sentences containing ALL words (e.g., "mama broer" finds sentences with both "mama" and "broer")
    *   **Gloss searches**: Converts spaces to hyphens for gloss matching (e.g., "niet waar" → "NIET-WAAR")
*   **URL Parameters:**
    *   `query`: The search term (single word or multiple words separated by spaces).
*   **Query Parameters (optional):**
    *   `offset`: (integer) Pagination offset. Default is 0.
    *   `limit`: (integer) Results limit. Default is 8, maximum is 100.
    *   `resultType`: (string) Filter results by type. Allowed values: `all`, `sentences`, `glosses`. Default is `all`.
        *   `sentences`: Returns only sentences.
        *   `glosses`: Returns glosses from both `form_data` (SignCollect) and `sb_records` (Signbank).
    *   `groupByTheme`: (boolean) If `true`, results (sentences and glosses) will be grouped by theme. Default is `false`.
*   **Example (Single Word):** `https://api.signcollect.nl/search/huis`
*   **Example (Multi-Word):** `https://api.signcollect.nl/search/mama%20broer`
*   **Example with POST:**
    ```bash
    curl -X POST -d "query=mama broer&resultType=sentences&groupByTheme=true" https://api.signcollect.nl/index.php
    ```

### Get Videos for an Entity

*   **URL:** `/videos/{type}/{id}`
*   **Method:** GET
*   **Description:** Retrieves video information for a specific entity (sentence or gloss).
*   **URL Parameters:**
    *   `type`: The type of entity. Allowed values: `zin` (sentence), `glos` (gloss).
    *   `id`: The ID of the entity.
*   **Example:** `https://api.signcollect.nl/videos/zin/102` (for sentence with ID 102)
*   **Example:** `https://api.signcollect.nl/videos/glos/360` (for gloss with ID 360)
    *   This endpoint is typically accessed via `getVideos.php`. The `.htaccess` will rewrite `/videos/{type}/{id}` to `getVideos.php?type={type}&id={id}`.

### Get Themes

*   **URL:** `/themas`
*   **Method:** GET
*   **Description:** Retrieves a list of all available themes.
*   **Example:** `https://api.signcollect.nl/themas`
    *   This endpoint is typically accessed via `getThemas.php`. The `.htaccess` will rewrite `/themas` to `getThemas.php`.


### Get Glosses by Theme

*   **URL:** `/thema/{themeName}`
*   **Method:** GET
*   **Description:** Retrieves a list of glosses associated with a specific theme.
*   **URL Parameters:**
    *   `themeName`: The name of the theme (e.g., "dieren").
*   **Example:** `https://api.signcollect.nl/thema/dieren`
    *   This endpoint is typically accessed via `getThemeVideos.php`. The `.htaccess` will rewrite `/thema/{themeName}` to `getThemeVideos.php?theme={themeName}`.

### Get Random Video

*   **URL:** `/getRandomVideo.php`
*   **Method:** GET
*   **Description:** Retrieves a random video from the form_data collection with 24-hour caching.
*   **Cache:** Results are cached for 24 hours to reduce database load.
*   **Example:** `https://api.signcollect.nl/getRandomVideo.php`
*   **Response Format:**
    ```json
    {
        "id": "40260",
        "senses": ["doel"],
        "signbank": null,
        "thema": "INTAKE EN DOSSIER",
        "glos": "DOEL",
        "videos": {
            "videoLeft": "https://media.signcollect.nl/L20250331_7726.mp4",
            "videoCenter": "https://media.signcollect.nl/M20250331_6747.mp4",
            "videoRight": "https://media.signcollect.nl/R20250331_0355.mp4"
        },
        "nmm_data": []
    }
    ```

### Suggestions (Autocomplete)

*   **URL:** `/suggestions` or POST to `/index.php`
*   **Method:** POST
*   **Description:** Provides intelligent autocomplete suggestions for search queries, including both words and sentences. Limited to 8 total suggestions for optimal user experience.
*   **Requirements:**
    *   Minimum 3 characters required for suggestions
    *   Multi-word queries supported (e.g., "mama broer" finds sentences containing both words)
*   **POST Parameters:**
    *   `query`: The partial search term (minimum 3 characters)
    *   `suggestions`: Must be set to `"true"` to trigger suggestion mode
*   **Suggestion Distribution:**
    *   **Words**: Up to 3 word suggestions from `hh_words` table
    *   **Sentences**: Up to 3 sentence suggestions from `sentences` table
    *   **Lemmas**: Up to 1 lemma suggestion (temporarily disabled)
    *   **Synonyms**: Up to 1 synonym suggestion (temporarily disabled)
    *   **Total**: Maximum 8 suggestions across all categories
*   **Example Request (Single Word):**
    ```bash
    curl -X POST -d "query=mama&suggestions=true" https://api.signcollect.nl/index.php
    ```
*   **Example Request (Multi-Word):**
    ```bash
    curl -X POST -d "query=mama broer&suggestions=true" https://api.signcollect.nl/index.php
    ```
*   **Response Format:**
    ```json
    {
        "success": true,
        "data": {
            "suggestions": {
                "words": [
                    {
                        "text": "mama",
                        "lemma": "mama",
                        "type": "word"
                    },
                    {
                        "text": "mama's",
                        "lemma": "mama",
                        "type": "word"
                    }
                ],
                "sentences": [
                    {
                        "text": "Even bij mama blijven.",
                        "full_text": "Even bij mama blijven.",
                        "id": 30,
                        "thema": "Lorraine",
                        "type": "sentence"
                    },
                    {
                        "text": "Ga je straks mee boodschappen doen met papa of wil je dat...",
                        "full_text": "Ga je straks mee boodschappen doen met papa of wil je dat mama dat doet?",
                        "id": 31,
                        "thema": "Lorraine",
                        "type": "sentence"
                    },
                    {
                        "text": "Mama gaat even pinnen.",
                        "full_text": "Mama gaat even pinnen.",
                        "id": 39,
                        "thema": "Lorraine",
                        "type": "sentence"
                    }
                ]
            }
        },
        "response_time": 0.0234
    }
    ```
*   **Multi-Word Example Response (for "mama broer"):**
    ```json
    {
        "success": true,
        "data": {
            "suggestions": {
                "words": [],
                "sentences": [
                    {
                        "text": "Ik snap dat je het vervelend vindt dat mama nu met je bro...",
                        "full_text": "Ik snap dat je het vervelend vindt dat mama nu met je broer bezig is, maar je moet nu even wachten.",
                        "id": 157,
                        "thema": "Lorraine",
                        "type": "sentence"
                    }
                ]
            }
        },
        "response_time": 0.0156
    }
    ```
*   **Implementation Details:**
    *   **Word Suggestions**: Searches `hh_words` table for words starting with the query
    *   **Sentence Suggestions**: 
        *   Single-word: Uses lemma-based search in `sentences` table
        *   Multi-word: Splits query and finds sentences containing ALL words
        *   Truncates long sentences to 60 characters with "..." for display
        *   Includes full sentence text in `full_text` field
        *   Note: app_ready filtering has been disabled for ZIN Project endpoints
    *   **Response Structure**: Each suggestion includes `type` field for identification
    *   **Performance**: Optimized queries with appropriate limits per category

## ZIN Project Sentence Management Endpoints

The API provides specialized endpoints for the ZIN Project's sentence annotation tool, enabling efficient sentence browsing and video data retrieval for annotation workflows.

**Important Note**: As of recent updates, all ZIN Project endpoints (`getZinnen.php`, `getZinnenThemas.php`, `getZinnenVideos.php`) have had their `app_ready` filtering disabled. This means all sentences and videos are returned regardless of their readiness status, enabling comprehensive annotation workflows that include work-in-progress content.

### Get All Themas

*   **URL:** `/getZinnenThemas.php`
*   **Method:** GET
*   **Description:** Retrieves all unique themas from the sentences table to populate theme selection interfaces.
*   **Parameters:** None required
*   **Example:** `https://api.signcollect.nl/getZinnenThemas.php`
*   **Response Format:**
    ```json
    {
        "success": true,
        "data": {
            "themas": [
                "Aankleden",
                "Afspraken",
                "Attitude",
                "Auto",
                "Avondeten",
                // ... ~90 unique themas
            ]
        },
        "response_time": 0.006
    }
    ```

### Get Sentences by Thema

*   **URL:** `/getZinnen.php`
*   **Method:** GET or POST
*   **Description:** Retrieves sentences for a specific thema with pagination support for efficient browsing.
*   **Parameters:**
    *   `thema` (required): The thema to filter by (e.g., "Aankleden")
    *   `limit` (optional): Maximum results per page (default: 100, max: 1000)
    *   `offset` (optional): Pagination offset for browsing large collections (default: 0)
*   **Example:** `https://api.signcollect.nl/getZinnen.php?thema=Aankleden&limit=20&offset=0`
*   **Response Format:**
    ```json
    {
        "success": true,
        "data": {
            "sentences": [
                {
                    "id": 1,
                    "zinString": "Doe je je jas aan?",
                    "thema": "Aankleden"
                },
                {
                    "id": 15,
                    "zinString": "Trek je schoenen uit.",
                    "thema": "Aankleden"
                }
                // ... more sentences
            ],
            "thema": "Aankleden",
            "count": 89,
            "limit": 20,
            "offset": 0
        },
        "response_time": 0.012
    }
    ```

### Get Video Data for Sentence

*   **URL:** `/getZinnenVideos.php`
*   **Method:** GET or POST
*   **Description:** Retrieves comprehensive video data for a sentence including glosses, video URLs, thumbnails, and matched transcription details. Essential for annotation workflows.
*   **Parameters:**
    *   `sentenceId` (required): The sentence ID to fetch video data for
*   **Example:** `https://api.signcollect.nl/getZinnenVideos.php?sentenceId=4`
*   **Response Format:**
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
        },
        "response_time": 0.018
    }
    ```

### ZIN Project Annotation Workflow

The ZIN Project endpoints support a complete annotation workflow:

1. **Theme Selection**: Use `/getZinnenThemas.php` to populate a theme dropdown menu
2. **Sentence Browsing**: Use `/getZinnen.php?thema=X` to load sentences for the selected theme with pagination
3. **Video Loading**: Use `/getZinnenVideos.php?sentenceId=X` to load complete video data for annotation
4. **Timeline Annotation**: Use the returned glosses, videos, and thumbnails to create frame-precise annotations

**Key Features:**
*   **Dual Video Sources**: Provides both sentence-level videos (`sentenceVideos`) and individual gloss videos (`glossVideosData`)
*   **Multiple Camera Angles**: Left, center, and right video angles for comprehensive coverage
*   **Automatic Thumbnails**: Thumbnail URLs generated by converting .wav extensions to .jpg
*   **Gloss Integration**: Extracts glosses from sentences table in JSON format and provides individual videos for each gloss
*   **Smart Data Source Mapping**: Each gloss includes `dataSource` indicating whether video comes from `form_data`, `nmm_data`, or `mixed`
*   **NMM Fallback**: If no form_data video exists for a gloss, automatically searches NMM data as fallback
*   **Comprehensive IDs**: Provides both `formDataIds` and `nmmIds` arrays for reference tracking
*   **No app_ready Filtering**: All videos are returned regardless of app_ready status to support comprehensive annotation workflows

## Admin Interface

The API includes a web-based admin interface accessible at `/admin/` for content management:

*   **URL:** `https://api.signcollect.nl/admin/`
*   **Authentication:** Session-based login required
*   **Features:**
    *   **Video Management**: Browse sign language videos with square aspect ratio display
    *   **Hover Playback**: Videos auto-play on hover for quick preview
    *   **Status Toggle**: Mark videos as "Ready" or "Not Ready" for production use
    *   **Filtering**: Filter by readiness status, theme, and search terms
    *   **Bulk Operations**: Select multiple videos for batch status updates
    *   **Statistics**: Real-time dashboard showing counts of ready vs not-ready videos
    *   **Pagination**: Navigate through large collections of videos
*   **Technical Details:**
    *   Square (1:1) aspect ratio video thumbnails to match video format
    *   Responsive design with Tailwind CSS
    *   RESTful API backend for all operations
    *   Session management with password hashing support


## Response Format

All responses are in JSON format.

### Search Result Structure (Example for "sentences")

```json
{
    "success": true,
    "data": {
        "sentences": [
            {
                "id": 102,
                "zinstring": "Hoe ziet de poep van die dieren eruit?",
                "theme": "dieren",
                "type": "zin"
            }
            // ... more sentences
        ],
        "words": [ /* ... */ ],
        "glosses": [ /* ... */ ],
        "synonyms": [ /* ... */ ]
    },
    "errors": [],
    "debug": { /* ... only in non-production ... */ },
    "response_time": 0.12345
}
```

Key naming convention for JSON responses:
*   `id` (lowercase) for identifiers.
*   `zinstring` (lowercase) for sentence strings.

Other fields generally follow camelCase or snake_case as historically used, but new primary identifiers and main text fields will prefer lowercase.

## .htaccess for Friendly URLs

A `.htaccess` file is used to provide friendlier URLs:

```apache
RewriteEngine On

# Prevent direct access to .php files except index.php and specific allowed files
RewriteCond %{THE_REQUEST} ^[A-Z]{3,}\\s([^\\s]+)\\.php [NC]
RewriteCond %{REQUEST_URI} !/index\\.php$ [NC]
RewriteCond %{REQUEST_URI} !/getVideos\\.php$ [NC]
RewriteCond %{REQUEST_URI} !/getThemas\\.php$ [NC]
RewriteCond %{REQUEST_URI} !/getThemeVideos\\.php$ [NC]
RewriteCond %{REQUEST_URI} !/getRandomVideo\\.php$ [NC]
RewriteCond %{REQUEST_URI} !/submit\\.php$ [NC] # If submit.php needs to be directly accessible
RewriteRule ^(.*)\\.php$ /$1 [R=301,L]

# Route for search: /search/query
RewriteRule ^search/(.+)$ index.php?query=$1 [L,QSA]

# Route for videos: /videos/type/id
RewriteRule ^videos/([^/]+)/([^/]+)$ getVideos.php?type=$1&id=$2 [L,QSA]

# Route for themes list: /themas
RewriteRule ^themas$ getThemas.php [L,QSA]

# Route for glosses by theme: /thema/themeName
RewriteRule ^thema/(.+)$ getThemeVideos.php?theme=$1 [L,QSA]

# Route for suggestions (POST to index.php)
RewriteRule ^suggestions$ index.php [L,QSA]


# Standard front controller, if not already handled by specific rules
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?path=$1 [L,QSA]
```

This setup allows for URLs like:
*   `https://api.signcollect.nl/search/huis`
*   `https://api.signcollect.nl/videos/zin/102`
*   `https://api.signcollect.nl/themas`
*   `https://api.signcollect.nl/thema/dieren`
