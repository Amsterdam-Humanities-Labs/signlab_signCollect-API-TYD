# Sign Language API Documentation

## Overview

This API provides access to a database of sign language resources including sentences, glosses (forms and SignBank records), and Non-Manual Markings (NMM). It allows for searching by keywords and retrieving video data for sign language content.

## API Endpoints

The API consists of four main endpoints:

1. **Search API**: `/web/zin/api/index.php` (POST)
2. **Video API**: `/web/zin/api/getVideos.php` (GET)
3. **Themes API**: `/web/zin/api/getThemas.php` (GET)
4. **Theme Videos API**: `/web/zin/api/getThemeVideos.php` (GET)

## Installation Requirements

- PHP 7.2 or higher
- MySQL database
- Appropriate file permissions for the web server user

## Search API

The Search API allows for searching sign language content by keyword.

### Endpoint

```
POST /web/zin/api/index.php
```

### Request Format

The API accepts POST requests with **multipart/form-data** content type. This allows for sending form data with multiple parts, which is especially useful when submitting text parameters alongside potential file uploads in the future.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| query | string | Yes | The search term to look for |
| offset | integer | No | Pagination offset (default: 0) |
| resultType | string | No | Filter results by type: 'all', 'sentences', 'glosses' |
| groupByThema | string | No | Set to 'true' to group results by theme |
| suggestions | string | No | Set to 'true' to get search suggestions instead of results |

### Response Format

```json
{
  "success": true,
  "data": {
    "words": [...],
    "sentences": [...],
    "glosses": [...],
    "synonyms": [...]
  },
  "errors": [],
  "response_time": 0.123
}
```

When `groupByThema=true` is set, results are grouped by theme:

```json
{
  "success": true,
  "data": {
    "words": [...],
    "synonyms": [...],
    "sentences_by_thema": {
      "Theme1": [...],
      "Theme2": [...]
    },
    "glosses_by_thema": {
      "Theme1": [...],
      "Theme2": [...]
    },
    "sb_records": [...]
  }
}
```

For suggestions (when `suggestions=true`):

```json
{
  "success": true,
  "data": {
    "suggestions": {
      "words": [...],
      "lemmas": [...],
      "synonyms": [...]
    }
  }
}
```

## Video API

The Video API retrieves detailed data for specific entities, including videos.

### Endpoint

```
GET /web/zin/api/getVideos.php
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| id | integer/string | Yes | The ID of the entity to retrieve |
| type | string | Yes | The type of entity: 'zin' (sentence), 'glos' (form), 'sb' (SignBank), or 'nmm' (Non-Manual Marking) |

### Response Format

```json
{
  "success": true,
  "data": {
    "id": 123,
    "videos": {
      "videoLeft": "https://media.signcollect.nl/path/to/left_video.mp4",
      "videoCenter": "https://media.signcollect.nl/path/to/center_video.mp4",
      "videoRight": "https://media.signcollect.nl/path/to/right_video.mp4"
    },
    // Other entity-specific fields
  },
  "response_time": 0.123
}
```

#### Response by Entity Type

Different entity types return different fields:

1. **Sentences (zin)**
   - ID
   - zinString (sentence text)
   - Nederlands (Dutch translation)
   - Gebaar_voor_Gebaar (sign-for-sign representation)
   - Signbank_ID_glossen (SignBank ID glosses)
   - videos
   - subtitleFiles

2. **Forms (glos)**
   - id
   - senses
   - signbank (SignBank ID)
   - videos
   - nmm_data (associated Non-Manual Markings)
   - thema (theme)

3. **SignBank Records (sb)**
   - id
   - senses_dutch (Dutch meanings)
   - videos

4. **Non-Manual Markings (nmm)**
   - id
   - type
   - thema (theme)
   - videos
   - other NMM-specific fields

## Examples

### Search Example

```bash
curl -X POST \
  http://your-domain.com/web/zin/api/index.php \
  -F 'query=house' \
  -F 'resultType=all'
```

### Get Video Example

```bash
curl -X GET \
  'http://your-domain.com/web/zin/api/getVideos.php?id=123&type=zin'
```

### Get Suggestions Example

```bash
curl -X POST \
  http://your-domain.com/web/zin/api/index.php \
  -F 'query=hou' \
  -F 'suggestions=true'
```

## Error Handling

The API returns appropriate HTTP status codes and error messages:

```json
{
  "success": false,
  "errors": ["Error message here"],
  "data": []
}
```

Common error scenarios:
- Missing required parameters
- Invalid entity type
- Entity not found
- Database connection issues

## Theme API

The Theme API provides access to themes from the sign language database and their associated videos.

### Get Themes

Retrieves a list of all available distinct themes.

#### Endpoint

```
GET /web/zin/api/getThemas.php
```

#### Parameters

No parameters required.

#### Response Format

```json
{
  "success": true,
  "data": [
    "Theme1",
    "Theme2",
    "Theme3",
    ...
  ],
  "errors": [],
  "response_time": 0.123
}
```

### Get Theme Videos

Retrieves all forms (glosses) associated with a specific theme.

#### Endpoint

```
GET /web/zin/api/getThemeVideos.php
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| theme | string | Yes | The name of the theme to retrieve forms for |

#### Response Format

```json
{
  "success": true,
  "data": {
    "theme": "ThemeName",
    "count": 10,
    "forms": [
      {
        "id": 123,
        "senses": ["sense1", "sense2"],
        "type": "glos"
      },
      {
        "id": 124,
        "senses": ["sense3"],
        "type": "glos"
      },
      ...
    ]
  },
  "errors": [],
  "response_time": 0.123
}
```

#### Usage Workflow

The Theme API is designed for a two-step process:

1. Call `getThemas.php` to get a list of all available themes
2. When a user selects a theme, call `getThemeVideos.php?theme=ThemeName` to get all forms associated with that theme
3. For each form, use the existing `getVideos.php?id={formId}&type=glos` endpoint to retrieve the actual video data

This approach allows for efficient loading and browsing of themed content.

### Examples

#### Get All Themes Example

```bash
curl -X GET \
  'http://your-domain.com/web/zin/api/getThemas.php'
```

#### Get Forms for a Theme Example

```bash
curl -X GET \
  'http://your-domain.com/web/zin/api/getThemeVideos.php?theme=Animals'
```

#### Get Video for a Form from a Theme

```bash
# After getting the form ID from getThemeVideos.php
curl -X GET \
  'http://your-domain.com/web/zin/api/getVideos.php?id=123&type=glos'
```

## Testing Interface

A visual testing interface is available at:

```
http://your-domain.com/web/zin/api/test.html
```

This provides an interactive way to test searches and view videos.
