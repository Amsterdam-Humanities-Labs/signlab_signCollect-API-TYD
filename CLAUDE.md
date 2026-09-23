# CLAUDE.md

Guidance for Claude Code in this repo. Endpoints, hosting and config: see `README.md`.

## Layout
- Root `get*.php`, `index.php`, `submit.php`: one endpoint each. They include `src/config/{config,SecurityHeaders,ErrorReporting}.php`, then the services they need.
- `src/services/`: all query logic.
  - `SearchService`: search across sentences, glosses, words, synonyms; `groupGlossesByTheme()`. Gloss search turns spaces into hyphens ("niet waar" -> "NIET-WAAR") and drops `-B`..`-Z` variants.
  - `SentenceService`: `getAllThemas()`, `getSentencesByThema()`, `getVideoDataForSentence()`; glosses come from `sentences.glosses` (JSON); thumbnails are the video URL with `.jpg`.
  - `VideoService`: `getVideosForEntity($id, $type)` builds left/center/right URLs.
  - `FormService`, `NmmService`, `SignbankService`, `MocapService`, `LatestTranscriptionService`, `IdResolverService`, `RandomVideoService`.
  - `SuggestionService`: min 3 chars; only word and sentence suggestions are enabled.
  - `ApiLogger`: request timing.
- `admin/`: `index.html` + `js/app.js` front end, `api.php` backend (session login against `users`).

## Behaviour to keep
- `app_ready` filtering is disabled on purpose in `SentenceService` and `NmmService` (the sentence annotation tool in signlab_zinnen-annotation needs work-in-progress videos).
- When `form_data` and `nmm_data` share a `glos`, `form_data` wins.
- Response keys: `id` and `zinstring` are lowercase; everything else keeps its historical name.

## Commands
```bash
php tests/TestRunner.php               # all tests
php tests/TestSentenceServices.php     # one file
rm cache/themas.json cache/random_video.json   # clear caches; they regenerate on the next request
```

## Adding an endpoint
Create a root PHP file following an existing `get*.php`, add it to the direct-`.php` exemption list (and a rewrite rule, if wanted) in `.htaccess`, and add a test in `tests/`.
