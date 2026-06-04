# AGENTS.md

## Repo Layout

Two independent projects in one monorepo:

- `fastapi-translator/` — Python FastAPI **mock** translator (local dev service)
- `flarum-ext-translate/` — Flarum 1.8 PHP extension (proxies requests to FastAPI)

They communicate via HTTP. FastAPI runs locally on `127.0.0.1:8000` by default.

## Key Facts

### The translator is a MOCK
`fastapi-translator` returns `tran_{lang}_{source}` — it is a **test/mock service**, not a real translator. Do not treat it as production-ready translation. It exists so the Flarum extension can be developed and tested end-to-end.

### JS is pre-built — no source in the repo
`flarum-ext-translate/js/dist/` contains compiled `forum.js` and `admin.js`. There is **no source JS, no `package.json`, no webpack/vite config**. The JS is not buildable from this repo — it was built externally. When changing frontend behavior, you must either edit the dist files directly (fragile) or reintroduce source + build tooling.

### PHP is the proxy layer
The Flarum extension does NOT translate itself. Controllers validate input, check guest permissions, then curl-proxy to FastAPI. The actual `src/` PHP code is thin: one abstract controller with cURL logic, plus two concrete controllers for single and batch endpoints.

### Composer path repository for local install
The Flarum extension is installed into a Flarum instance via `composer path repository`. The README in `flarum-ext-translate/` documents this. Do not try to publish to Packagist.

### No tests exist
No test files in either project. No CI config. No linter/formatter config.

## Commands

### Run FastAPI translator
```bash
cd fastapi-translator
python -m venv .venv && . .venv/bin/activate && pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

### Install extension into a local Flarum
From the Flarum install root:
```bash
composer config repositories.twikura-translate path /home/twikura/projects/translate_flarum/flarum-ext-translate
composer require twikura/flarum-ext-translate:@dev
php flarum extension:enable twikura-translate
php flarum cache:clear
```

## Architecture Notes

### Extension ID and settings keys
- Flarum extension ID: `twikura-translate`
- PHP namespace: `Twikura\Translate\`
- Settings keys: `twikura-translate.api_base_url`, `twikura-translate.allow_guests`, `twikura-translate.max_text_length`, `twikura-translate.max_batch_size`
- Frontend JS global prefix: `EXT_ID = 'twikura-translate'`

### API route flow
```
Browser → Flarum (/api/translate) → PHP Controller (validates) → cURL → FastAPI (/translate) → mock result
```

The extension registers two API routes under Flarum's `/api` prefix (set in `extend.php`). The JS uses `app.forum.attribute('apiUrl')` to discover the base path.

### Locale files
Actual translations are in `.yml` files at `flarum-ext-translate/locale/` root. The `locale/en/` and `locale/zh-Hans/` subdirectories exist but are empty. The YML format is Flarum's standard locale structure keyed by `twikura-translate.admin.settings.*`.

### FastAPI caching
Translations are cached in `fastapi-translator/data/translations.sqlite3`. Cache key is SHA-256 of `version\0lang\0text`. In-memory logs (last 200) are viewable at `/logs`.
