# AGENTS.md

## Repo Layout

- `flarum-ext-translate/` — Flarum 1.8 PHP extension (纯插件,无后端服务)
  - `src/` PHP 源码 (Controller, Command, Event, Job, Llm, Repository)
  - `js/` TS 源码 (webpack 构建 → dist/)
  - `migrations/` Flarum migration (2 个表)
  - `locale/` 国际化 (en/zh/ja 等 YML)
  - `less/` CSS
- `DESIGN.md` — 纯插件化设计方案 (完整架构文档)
- `AGENTS.md` — 本文件

**不再存在**:
- ~~`fastapi-translator/`~~ (已删除,纯插件化完成)

## Key Facts

### 纯 PHP 实现
取消了 FastAPI Python 后端。所有翻译逻辑由 Flarum 扩展 PHP 承担:
- `src/Command/TranslateRunCommand.php` — 5s 轮询 console command (`php flarum translate:run`)
- `src/Job/Worker.php` — 单帖翻译完整生命周期 (SSE 流式调用,写回 DB)
- `src/Llm/OpenAiSseClient.php` — curl SSE 流式封装 (299 行,完整的 SSE 解析 + 重试 + 超时)
- `src/Llm/PromptBuilder.php` — 构建 system+user messages
- `src/Event/TranslationEventListeners.php` — PostCreated / PostRevised 事件自动触发
- `src/Repository/PostTranslationRepository.php` — post_translations 表增删改查 (含 FOR UPDATE SKIP LOCKED)
- `src/Repository/TranslationLogRepository.php` — translation_logs 表增删改查
- `src/LangMapConfig.php` — 解析 lang_map setting 字符串
- `src/Api/Controller/` — 5 个 Web 控制器 (TranslateRetry, TranslateTest, TranslateBackfill, TranslateBackfillStatus, TranslateLog)

### JS 用 webpack 工具链构建
- `js/package.json` — flarum-webpack-config ^2.0.0, TypeScript ^4.5.4, webpack ^5.76.0
- `js/webpack.config.js` — `module.exports = require('flarum-webpack-config')();`
- `js/tsconfig.json` — extends flarum-tsconfig
- `js/forum.ts` — entry point → `export * from './src/forum'`
- `js/admin.ts` — entry point → `export * from './src/admin'`
- `js/src/forum/index.ts` — 313 行完整前端逻辑 (auto-toggle, polling, retry, TranslatedPostBody)
- `js/src/admin/index.ts` — Settings 注册 + 测试翻译面板 + 路由注册
- `js/src/admin/components/TranslateAdminPage.ts` — 三 tab admin 页面 (Logs/Prompt/Backfill)
- `js/dist/` — webpack 输出 (forum.js + admin.js + .map)

### PHP 命名空间 + extend.php 注册
- 扩展 ID: `twikura-translate`
- 命名空间: `Twikura\Translate\`
- 类型: flarum-extension

**注意**: extend.php (`flarum-ext-translate/extend.php`) 目前仍引用旧的控制器类 (`TranslateController`, `BatchTranslateController`) 和旧的 setting key (`api_base_url`, `max_text_length`, `max_batch_size`)。代理旧 FastAPI 的控制器文件 (AbstractTranslateController, TranslateController, BatchTranslateController) 已在阶段 5 删除。extend.php 需要使用新控制器 (TranslateRetryController, TranslateTestController, TranslateBackfillController, TranslateBackfillStatusController, TranslateLogController) 和新 setting (llm_base_url, llm_api_key, llm_model 等) 以及 Console/Event/Serializer 注册来更新。详见 `DESIGN.md` §4.3。

### 数据库表
- `post_translations(post_id, target_lang)` — 主工作台。状态机: pending → running → done/error
  - PRIMARY KEY `(post_id, target_lang)`
  - source_content 原文快照(用于 stale 比对), translated_content 译文
  - is_backfill 标记 (worker 优先消费 is_backfill=0 的行)
  - 索引: `KEY idx_status_backfill_created (status, is_backfill, created_at)`
- `translation_logs(id)` — 每次翻译的历史日志(新增不替换,审计用)
  - prompt_full + response_final 完整记录, tokens_in/out, latency_ms

### 翻译触发
- `PostCreated` / `PostRevised` 事件自动触发: 读 site `default_locale` 为目标语言, `LangMapConfig` 校验存在后 enqueue
- admin 在 TranslateAdminPage 的 Backfill tab 手动触发批量补译
- `auto_translate` setting 开关控制事件触发
- 仅处理 `CommentPost` 类型 (不处理 flags/likes 等)

### Worker
- `php flarum translate:run` console command (TranslateRunCommand)
- 5s 轮询 pending 行, `FOR UPDATE SKIP LOCKED` 单进程顺序处理
- 启动时 orphan cleanup: `UPDATE post_translations SET status='pending' WHERE status='running'`
- 流式 SSE 调 opencode-go,每个 token 实时 echo 到 stdout (docker logs 可见)
- 翻译过程在一个 DB 事务内: 失败 ROLLBACK, 启动时 orphan 复位

### LLM 网关
- 单 provider (opencode-go), Flarum setting `twikura-translate.llm_base_url` + `llm_api_key` + `llm_model`
- SSE 流式规避 60s 超时 (`CURLOPT_LOW_SPEED_LIMIT=1`, `CURLOPT_LOW_SPEED_TIME=60`)
- 故障转移交 opencode-go (PHP 侧只做 `max_retries` 同 provider 重试)
- `OpenAiSseClient` 被 Worker 和 TranslateTestController 共享

## Commands

### 本地开发
- JS 构建: `cd js && npm ci && npm run build` (或 `npm run dev` watch)
- DB migration: 在 Flarum 容器里 `php flarum migrate`
- 启动 worker: `docker compose up translator-worker` (或 `php flarum translate:run`)

### Docker
- 开发: `make dev-build && make dev-up` (在 `~/dockers/flarum-docker/`)
- 4 个容器: flarum / db / cache / translator-worker (同 image, 不同 command)
- 配置 LLM API key: 访问 admin → Extensions → Translate → Settings 填 `llm_api_key`

## Architecture Notes

### Extension ID and settings keys
- Flarum extension ID: `twikura-translate`
- PHP namespace: `Twikura\Translate\`
- 9 settings:
  | key | type | default |
  |---|---|---|
  | `auto_translate` | bool | `1` |
  | `llm_base_url` | text | `http://opencode-go:3000` |
  | `llm_api_key` | password | `''` |
  | `llm_model` | text | `deepseek-v4-flash` |
  | `llm_timeout` | number | `120` |
  | `llm_max_retries` | number | `1` |
  | `lang_map` | textarea | (12 languages) |
  | `system_prompt` | textarea | ACGN 规则模板 |
  | `allow_guests` | bool | `1` |
- 前端 serializeToForum: `twikuraTranslateAutoEnabled` (auto_translate), `twikuraTranslateAllowGuests` (allow_guests)

### API 路由
| Method | Path | Controller | Permission |
|--------|------|-----------|------------|
| POST | `/api/translate/retry` | TranslateRetryController | 登录/allow_guests |
| POST | `/api/translate/test` | TranslateTestController | admin |
| POST | `/api/translate/backfill` | TranslateBackfillController | admin |
| GET | `/api/translate/backfill/status` | TranslateBackfillStatusController | admin |
| GET | `/api/translate-logs` | TranslateLogController | admin |

### 数据流
```
用户发帖 → PostCreated 事件
  → TranslationEventListeners 读 default_locale
  → PostTranslationRepository::enqueue() (status=pending)
  → worker 5s 轮询 → 取 pending 行 (FOR UPDATE SKIP LOCKED)
  → PromptBuilder 构建 messages
  → OpenAiSseClient SSE 流式调 opencode-go
  → 写 post_translations (status=done+translated_content)
  → 写 translation_logs 一行
  → 前端 5s 轮询 discussion → PostSerializer 注入 translation_status
  → TranslatedPostBody 组件渲染译文
```

### 前端状态管理
- `translation_status`: pending/running/done/error/null (从 PostSerializer attribute 读)
- auto-translate toggle: 本地 localStorage + forum attribute 初始化
- 5s 轮询: `setInterval` 扫当前 discussion 所有 post 的 translation_status, 全部 done/error 停轮询
- TranslatedPostBody 组件: status=done 渲染译文, pending/running 显示 spinner, error 显示重试按钮

### Locale files
- `locale/en.yml` / `locale/zh-Hans.yml` / `locale/ja.yml` 等多语言
- 翻译字段在 `twikura-translate.forum.*` (用户面) 和 `twikura-translate.admin.*` (管理面) 下

### 目录结构

```
translate_flarum/
├── flarum-ext-translate/
│   ├── src/
│   │   ├── Api/Controller/
│   │   │   ├── TranslateRetryController.php
│   │   │   ├── TranslateTestController.php
│   │   │   ├── TranslateBackfillController.php
│   │   │   ├── TranslateBackfillStatusController.php
│   │   │   └── TranslateLogController.php
│   │   ├── Command/
│   │   │   └── TranslateRunCommand.php
│   │   ├── Event/
│   │   │   └── TranslationEventListeners.php
│   │   ├── Job/
│   │   │   └── Worker.php
│   │   ├── Llm/
│   │   │   ├── OpenAiSseClient.php
│   │   │   └── PromptBuilder.php
│   │   ├── Repository/
│   │   │   ├── PostTranslationRepository.php
│   │   │   └── TranslationLogRepository.php
│   │   └── LangMapConfig.php
│   ├── js/
│   │   ├── forum.ts                     # 入口
│   │   ├── admin.ts                     # 入口
│   │   ├── src/forum/index.ts           # 用户面 TS
│   │   ├── src/admin/index.ts           # Admin 面 TS
│   │   ├── src/admin/components/
│   │   │   └── TranslateAdminPage.ts    # 三 tab admin 页
│   │   ├── dist/                        # webpack 输出
│   │   ├── package.json
│   │   ├── webpack.config.js
│   │   └── tsconfig.json
│   ├── migrations/
│   │   ├── 2026_07_22_000000_create_post_translations.php
│   │   └── 2026_07_22_000001_create_translation_logs.php
│   ├── locale/
│   │   ├── en.yml / zh-Hans.yml / ja.yml / es.yml / pt.yml / ru.yml 等
│   ├── less/
│   │   └── forum.less
│   ├── extend.php
│   ├── composer.json
│   └── README.md
├── DESIGN.md                      # 纯插件化设计方案
└── AGENTS.md                      # 本文件
```
