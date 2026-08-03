# AGENTS.md

## Repo Layout

- `flarum-ext-translate/` — Flarum 1.8 PHP extension (pure plugin, no backend service)
  - `src/` PHP source (Controller, Command, Event, Job, Llm, Repository)
  - `js/` TS source (webpack builds → `dist/`)
  - `migrations/` Flarum migration (2 tables)
  - `locale/` i18n (en/zh-Hans/ja/es/pt/ru YML)
  - `less/` CSS
- `DESIGN.md` — full plugin-ization architecture doc (795 lines, design reference)
- `AGENTS.md` — this file

**Deleted**: `fastapi-translator/` (pure-plugin migration complete)

## Key Facts

### Pure PHP — no Python backend
All translation logic lives in the Flarum PHP extension:
- `src/Command/TranslateRunCommand.php` — 5s polling loop (`php flarum translate:run`), orphan cleanup on start, 30s backoff on 429
- `src/Job/Worker.php` — single-post full lifecycle (SSE stream, DB writeback with transaction)
- `src/Llm/OpenAiSseClient.php` — curl SSE streaming wrapper (300 lines, full SSE parser + retry + timeout)
- `src/Llm/PromptBuilder.php` — builds system+user messages
- `src/Event/TranslationEventListeners.php` — PostCreated / PostRevised auto-triggers
- `src/Repository/PostTranslationRepository.php` — post_translations CRUD (uses FOR UPDATE SKIP LOCKED)
- `src/Repository/TranslationLogRepository.php` — translation_logs CRUD
- `src/LangMapConfig.php` — parses lang_map setting string
- `src/Api/Controller/` — 5 web controllers (TranslateRetry, TranslateTest, TranslateBackfill, TranslateBackfillStatus, TranslateLog)

### JS: webpack toolchain
- `js/package.json` — flarum-webpack-config ^2.0.0, TypeScript ^4.5.4, webpack ^5.76.0
- **Critical**: Use `flarum-webpack-config` v2, NOT v3. v3 breaks `flarum.reg` at runtime.
- `js/webpack.config.js` — `module.exports = require('flarum-webpack-config')();`
- `js/tsconfig.json` — extends flarum-tsconfig, imports from `flarum/*` via paths → `vendor/flarum/core/js/dist-typings`
- `js/forum.ts` → `export * from './src/forum'`
- `js/admin.ts` → `export * from './src/admin'`
- `js/src/forum/index.ts` — 318 lines (auto-toggle, polling, retry, TranslatedPostBody)
- `js/src/admin/index.ts` — Settings registration + test panel + route registration
- `js/src/admin/components/TranslateAdminPage.ts` — 3-tab admin page (Logs/Prompt/Backfill)
- `js/dist/` — webpack output (forum.js + admin.js + .map)

### PHP namespace + extend.php
- Extension ID: `twikura-translate`
- Namespace: `Twikura\Translate\`
- Type: flarum-extension
- `extend.php` is **already updated** to use new controllers+settings (no stale references)

### Database tables
- `post_translations(post_id, target_lang)` — work table. State machine: pending → running → done/error
  - PRIMARY KEY `(post_id, target_lang)`
  - `source_content` snapshot (for staleness comparison), `translated_content` result
  - `is_backfill` flag (worker consumes `is_backfill=0` first)
  - Index: `KEY idx_status_backfill_created (status, is_backfill, created_at)`
- `translation_logs(id)` — append-only audit log per translation
  - `prompt_full` + `response_final` full record, `tokens_in/out`, `latency_ms`

### Translation triggers
- `PostCreated` / `PostRevised` auto-trigger: reads site `default_locale` as target language, validates via `LangMapConfig`, then enqueues
- Admin triggers bulk backfill from TranslateAdminPage Backfill tab
- `auto_translate` setting toggle controls event triggering
- Only handles `CommentPost` type

### Worker (TranslateRunCommand)
- `php flarum translate:run` — console command
- 5s poll loop, `FOR UPDATE SKIP LOCKED`, single-process sequential
- On startup: orphan cleanup (`UPDATE ... SET status='pending' WHERE status='running'`)
- **Important**: Converts s9e XML stored in `posts.content` back to BBCode via `Formatter::unparse()` before sending to LLM
- SSE stream to opencode-go, each token echoed to stdout (visible in docker logs)
- Translation inside a single DB transaction: failure = ROLLBACK
- **429 rate-limit handling**: 30s backoff sleep before next poll cycle (line 133 in TranslateRunCommand)

### LLM gateway
- Single provider (opencode-go), Flarum settings `llm_base_url` + `llm_api_key` + `llm_model`
- SSE streaming avoids 60s timeout (`CURLOPT_LOW_SPEED_LIMIT=1`, `CURLOPT_LOW_SPEED_TIME=60`)
- Failover handled by opencode-go (PHP side retries same provider `max_retries` times)
- `OpenAiSseClient` shared between Worker and TranslateTestController

### PostSerializer locale fallback chain
The PostSerializer callback resolves target language in this order:
1. `?lang=` query param (explicit front-end override)
2. Symfony Translator locale (respects user language preference)
3. Flarum `default_locale` setting
4. If targetLang contains region code (e.g. `zh-Hans`), retries bare lang code (e.g. `zh`)

## Commands

### Local development
- JS build: `cd flarum-ext-translate/js && npm ci && npm run build` (or `npm run dev` for watch mode)
- DB migration: inside Flarum container — `php flarum migrate`
- Start worker: `docker compose up translator-worker` (or `php flarum translate:run`)

### Docker
- Development docker config is in `~/dockers/flarum-docker/` (separate repo)
- 4 containers: flarum / db / cache / translator-worker (same image, different command)
- Configure LLM API key: admin → Extensions → Translate → Settings, fill `llm_api_key`
- `translator-worker` health check: `pgrep -f 'flarum translate:run'`

### Installation in Flarum
```
composer config repositories.twikura-translate path /path/to/flarum-ext-translate
composer require twikura/flarum-ext-translate:@dev
php flarum extension:enable twikura-translate
php flarum cache:clear
```
Then JS must be built (`npm ci && npm run build` in `js/`) before enabling.

## Architecture Notes

### Extension settings (9 keys)
| key | type | default |
|---|---|---|
| `auto_translate` | bool | `1` |
| `llm_base_url` | text | `http://opencode-go:3000` |
| `llm_api_key` | password | `''` |
| `llm_model` | text | `deepseek-v4-flash` |
| `llm_timeout` | number | `120` |
| `llm_max_retries` | number | `1` |
| `lang_map` | textarea | (12 languages) |
| `system_prompt` | textarea | ACGN rules template |
| `allow_guests` | bool | `1` |

Frontend serialized: `twikuraTranslateAutoEnabled` (auto_translate), `twikuraTranslateAllowGuests` (allow_guests)

### API routes
| Method | Path | Controller | Permission |
|--------|------|-----------|------------|
| POST | `/api/translate/retry` | TranslateRetryController | logged-in / allow_guests |
| POST | `/api/translate/test` | TranslateTestController | admin |
| POST | `/api/translate/backfill` | TranslateBackfillController | admin |
| GET | `/api/translate/backfill/status` | TranslateBackfillStatusController | admin |
| GET | `/api/translate-logs` | TranslateLogController | admin |

### Data flow
```
user posts → PostCreated event
  → TranslationEventListeners reads default_locale
  → PostTranslationRepository::enqueue() (status=pending)
  → worker 5s poll → picks pending row (FOR UPDATE SKIP LOCKED)
  → Formatter::unparse() s9e XML → BBCode
  → PromptBuilder builds messages
  → OpenAiSseClient SSE stream to opencode-go
  → writes post_translations (done + translated_content)
  → writes translation_logs row
  → frontend 5s polls discussion → PostSerializer injects translation_status
  → TranslatedPostBody component renders translation
```

### Frontend state
- `translation_status`: pending/running/done/error/null (from PostSerializer attribute)
- Auto-translate toggle: localStorage + forum attribute initialization
- 5s polling: `setInterval` scans current discussion posts' `translation_status`, stops when all done/error
- TranslatedPostBody: done→renders translation, pending/running→spinner, error→retry button

### README.md note
`flarum-ext-translate/README.md` still references the old FastAPI architecture. Update this if the README matters for the current task.

### No CI / no tests
This repo has **no CI workflows**, **no Makefile**, **no test configuration** (no phpunit, no jest, no playwright). Single branch (`dev`). No tags. No linter/formatter config files.

### Locale files
- YML: `en.yml`, `zh-Hans.yml`, `zh.yml`, `ja.yml`, `es.yml`, `pt.yml`, `ru.yml`
- Subdirectories `en/` and `zh-Hans/` exist but are empty (reserved)
- Keys under `twikura-translate.forum.*` (user-facing) and `twikura-translate.admin.*` (admin)
