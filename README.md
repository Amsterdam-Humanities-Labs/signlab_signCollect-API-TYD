# signlab_signCollect-API-TYD
The public JSON API over the SignCollect corpus (`api.signcollect.nl`). It is read-only apart from two small write paths.

## What it does
- Plain PHP with mysqli. No framework, no build step, no Composer.
- `index.php` handles search and autocomplete. Each `get*.php` file answers one question. The logic is in `src/services/`.
- Reads the `admin_gebarenoverleg` database and returns JSON with video and subtitle URLs on `media.signcollect.nl`. It never returns the media files themselves.
- `getThemas.php` and `getRandomVideo.php` cache results in `cache/` for 24 hours. The cache is rebuilt when it is missing or out of date.
- Writes: `submit.php` stores a JSON POST (`query`, `email`, `timestamp`) in `form_submissions`. `admin/` is a login page (`users` table) for switching `form_data.tyd_app_ready` on or off.

## Where it runs
- Core server, `/web/zin/api`. Served at `https://api.signcollect.nl` (its own vhost) and at `https://signcollect.nl/zin/api/`.
- Demo hosts: deployed to `<docroot>/zin/api` and served at `/api`.

## Status
Production, with external users.

## Endpoints
| URL | File | Notes |
|---|---|---|
| `/search/{q}`, POST `index.php` | `index.php` | `offset`, `limit` (default 8, max 100), `resultType` (all, sentences or glosses), `groupByTheme`. With several words, all must match |
| POST `/suggestions` | `index.php` | `query` (at least 3 characters) and `suggestions=true` |
| `/videos/{zin\|glos\|sb\|nmm}/{id}` | `getVideos.php` | Left, center and right video URLs |
| `/themas`, `/thema/{name}` | `getThemas.php`, `getThemeVideos.php` | The theme list is cached for 24 h |
| `/getRandomVideo.php` | | Cached for 24 h |
| `/getResolvedId` | `getResolvedId.php` | `id` and `type` (GET or POST): maps a gloss id between `form_data` and `nmm_data`; `form_data` wins when it has recordings |
| `/list/{label}/glos`, `/list/{label}/zin`, `/list/zin/videos` | `getList*.php` | Lists per label |
| `getZinnenThemas.php`, `getZinnen.php?thema=`, `getZinnenVideos.php?sentenceId=` | | Used by the sentence (zin) annotation tool. No `app_ready` filter |
| POST `submit.php` | | JSON body `query`, `email`, `timestamp`; writes `form_submissions` |
| `/admin/` | `admin/api.php` | `login`, `logout`, `user_info`, `list`, `update_status`, `bulk_update`, `themes`, `stats` |

Routing is in `.htaccess`. A direct `.php` request for a file not on its allow-list is redirected to the path without `.php`. Every response has `{success, data, errors, response_time}`, plus `debug` when the environment is not `PROD`.

## How to run / deploy
```bash
php tests/TestRunner.php          # maintained test suite
```
- The tests query a real database through `<docroot>/mysql_config_test.php`.
- `interface_deploy` checks this repo out into `zin/api` as its own component (a row in `repos.tsv`). `signlab_zinnen-annotation` ignores `api/`.
- Core server: the owner updates `/web/zin/api` by hand with `git pull`. See [production.md](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/blob/main/docs/production.md).
- First-time admin setup: run `admin/migrate.sql` to add `tyd_app_ready`. Or run `php admin/setup.php`; over HTTP it needs a portal admin session.
- PHP errors are logged, not shown (set in `.htaccess`).

## Configuration
| Item | Notes |
|---|---|
| `mysql_config.php` | `<docroot>/mysql_config.php`, not in git. Template: `mysql_config.example.php` |
| `mysql_config_test.php` | Same folder. Used by `getRandomVideo.php`, `getThemas.php`, `getThemeVideos.php`, `submit.php` and `admin/setup.php` ([stack#31](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack/issues/31)) |
| `API_ENVIRONMENT` | `DEV` (default) or `PROD`. Read in `src/config/config.php` |
| `src/config/config.php` | `MEDIA_BASE_URL`, `SUBTITLE_BASE_URL` |

`sc_paths.php` is a copy of `consumer/sc_paths.php` from [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib). Edit it there, then copy it here again.

## Dependencies
- MySQL database `admin_gebarenoverleg`: `sentences`, `form_data`, `sb_records`, `nmm_data`, `matched_transcriptions`, `hh_words`, `themas`, `users`, `form_submissions`.
- `media.signcollect.nl` for all media URLs.
- [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib) (copied `sc_paths.php`).
- [signlab_zinnen-annotation](https://github.com/Amsterdam-Humanities-Labs/signlab_zinnen-annotation): this API lives at `zin/api` and shares the docroot config.
- Deploy and stack overview: [signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
