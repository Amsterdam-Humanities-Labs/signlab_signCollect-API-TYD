# signlab_sCAPI
Public read-only JSON API over the SignCollect corpus (`api.signcollect.nl`).

## What it does
- Plain PHP + mysqli, no framework, no build step, no Composer.
- `index.php` does search and autocomplete; each `get*.php` answers one question. Logic lives in `src/services/`.
- Queries `admin_gebarenoverleg` and returns JSON with video/subtitle URLs on `media.signcollect.nl` (never bytes).
- `getThemas.php` and `getRandomVideo.php` cache to `cache/` (regenerated when missing or stale).
- `admin/` is the only part that writes: a session-login page (users table) to toggle `form_data.tyd_app_ready`.

## Where it runs
- Production VPS, `/web/zin/api`, served as `https://api.signcollect.nl` (own vhost) and as `https://signcollect.nl/zin/api/`.
- Demo hosts alias `/api` to `<docroot>/zin/api` but leave the directory empty.

## Status
Production, with external consumers.

## Endpoints
| URL | File | Notes |
|---|---|---|
| `/search/{q}`, POST `index.php` | `index.php` | `offset`, `limit` (8, max 100), `resultType` all/sentences/glosses, `groupByTheme`; multi-word = all words |
| POST `/suggestions` | `index.php` | `query` (>= 3 chars) + `suggestions=true` |
| `/videos/{zin\|glos\|sb\|nmm}/{id}` | `getVideos.php` | left/center/right URLs |
| `/themas`, `/thema/{name}` | `getThemas.php`, `getThemeVideos.php` | themes cached |
| `/getRandomVideo.php` | | cached 24 h |
| `/getResolvedId` | `getResolvedId.php` | id resolver |
| `/list/{label}/glos`, `/list/{label}/zin`, `/list/zin/videos` | `getList*.php` | label lists |
| `getZinnenThemas.php`, `getZinnen.php?thema=`, `getZinnenVideos.php?sentenceId=` | | ZIN annotation tool; no `app_ready` filter |
| `submit.php` | | reachable directly |
| `/admin/` | `admin/api.php` | `list`, `update_status`, `bulk_update`, `themes`, `stats` |

Routing is in `.htaccess`. Responses: `{success, data, errors, response_time}`, plus `debug` when not `PROD`.

## How to run / deploy
```bash
php tests/TestRunner.php          # maintained test suite
```
- Not deployed by `interface_deploy` (`repos.tsv` leaves it out on purpose). Clone this repo directly.
- `signlab_zin` has a gitlink at `api` with no `.gitmodules`. Treat `zin/api` as a mount point: on production it is a separate checkout, and zin's `git clean` keep-list protects `/api/`.
- TODO: how production's `/web/zin/api` checkout gets updated (by hand or by a script) is not documented.
- Admin first-time setup: run `admin/migrate.sql` (or `php admin/setup.php`; over HTTP it needs a portal admin session) to add `tyd_app_ready`.
- PHP errors are logged, not displayed (`.htaccess`).

## Configuration
| Item | Notes |
|---|---|
| `mysql_config.php` | `<docroot>/mysql_config.php`, not in git; template `mysql_config.example.php` |
| `mysql_config_test.php` | same dir; used by `getRandomVideo.php`, `getThemas.php`, `getThemeVideos.php`, `submit.php`, `admin/setup.php` ([stack#31](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/issues/31)) |
| `API_ENVIRONMENT` | `DEV` (default) or `PROD`, read in `src/config/config.php` |
| `src/config/config.php` | `MEDIA_BASE_URL`, `SUBTITLE_BASE_URL` |

`sc_paths.php` is vendored from `signlab_signcollect-lib` (`consumer/sc_paths.php`). Edit it there, then re-vendor.

## Dependencies
- MySQL `admin_gebarenoverleg`: `sentences`, `form_data`, `sb_records`, `nmm_data`, `matched_transcriptions`, `hh_words`, `themas`, `users`.
- `media.signcollect.nl` for all media URLs.
- `signlab_signcollect-lib` (vendored `sc_paths.php`); `signlab_zin` (lives at `zin/api`, shares the docroot config).
- Deploy/stack overview: https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack
