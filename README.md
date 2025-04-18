# Sign Language API Documentation

## Overview

This API provides access to a database of sign language resources including sentences, forms, SignBank records, and Non-Manual Markings (NMM). It allows for searching by keywords and retrieving video data for sign language content.

## API Endpoints

The API consists of two main endpoints:

1. **Search API**: `/web/zin/api/index.php` (POST)
2. **Video API**: `/web/zin/api/getVideos.php` (GET)

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

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| query | string | Yes | The search term to look for |
| offset | integer | No | Pagination offset (default: 0) |
| resultType | string | No | Filter results by type: 'all', 'sentences', 'forms', or 'sb_records' |
| groupByThema | string | No | Set to 'true' to group results by theme |
| suggestions | string | No | Set to 'true' to get search suggestions instead of results |

### Response Format

```json
{
  "success": true,
  "data": {
    "words": [...],
    "sentences": [...],
    "forms": [...],
    "sb_records": [...],
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
    "forms_by_thema": {
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

## Testing Interface

A visual testing interface is available at:

```
http://your-domain.com/web/zin/api/test.html
```

This provides an interactive way to test searches and view videos.
