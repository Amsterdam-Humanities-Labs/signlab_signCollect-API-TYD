# SignCollect API

The SignCollect API provides access to a collection of sign language videos, glosses, and sentences.

## Base URL

`https://api.signcollect.nl`

## Endpoints

### Search

*   **URL:** `/search/{query}`
*   **Method:** GET (POST is also supported with `query` parameter in the body)
*   **Description:** Searches for words, sentences, and glosses related to the query.
*   **URL Parameters:**
    *   `query`: The search term.
*   **Query Parameters (optional):**
    *   `offset`: (integer) Pagination offset. Default is 0.
    *   `resultType`: (string) Filter results by type. Allowed values: `all`, `sentences`, `glosses`. Default is `all`.
        *   `sentences`: Returns only sentences.
        *   `glosses`: Returns glosses from both `form_data` (SignCollect) and `sb_records` (Signbank).
    *   `groupByTheme`: (boolean) If `true`, results (sentences and glosses) will be grouped by theme. Default is `false`.
*   **Example:** `https://api.signcollect.nl/search/huis`
*   **Example with POST:**
    ```bash
    curl -X POST -d "query=huis&resultType=sentences&groupByTheme=true" https://api.signcollect.nl/index.php
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
