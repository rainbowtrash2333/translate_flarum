# translate_flarum 纯插件化设计方案

> 版本: 1.0 | 日期: 2026-07-22
> 目标: 去除 FastAPI 后端,改为 Flarum 纯插件实现,降低复杂度
> 基线: Flarum 1.8.10 + 单 LLM 网关 (opencode-go)

---

## 1. 目标与范围

### 1.1 目标

- **纯插件实现**: 去除 `_~_/projects/translate_flarum/fastapi-translator_` Python 后端,所有功能由 Flarum PHP 扩展承担
- **per-post 整体翻译**: 每条 post 一次 LLM 调用,原文整体翻译为译文
- **自动事件触发**: `PostCreated` / `PostRevised` 事件触发翻译,后台开关控制
- **流式调用规避超时**: worker 通过 SSE 流式请求 opencode-go,避免 60s 超时
- **界面完整**: admin 后台提供翻译日志 / 提示词查看 / 批量补译 / 测试翻译 等
- **简化架构**: 单 provider (opencode-go 网关) + 单 PHP 进程顺序 worker

### 1.2 非目标

- 不做多 provider fallback (故障转移交 opencode-go 网关)
- 不做逐 token 流式回放
- 不做历史翻译版本保留 (post 更新即覆盖)
- 不做跨帖子 text-hash 去重缓存

### 1.3 作用域

- 改造范围: `~/projects/translate_flarum/flarum-ext-translate/` 全部
- 删除范围: `~/projects/translate_flarum/fastapi-translator/` 全部 (参考其提示词/调用逻辑搬到 PHP)
- 改造 Docker: `~/dockers/flarum-docker/docker-compose.dev.yml`, `docker-compose.prod.yml`, `Dockerfile`, `Dockerfile.dev`

---

## 2. 架构总览

```
┌─────────────────────────────┐
│  Flarum Web Container       │
│  (Nginx + PHP-FPM)           │
│  - 论坛 UI (PostSerializer    │
│    注入翻译字段)             │
│  - Admin 后台                │
│  - /api/translate/retry      │
│  - /api/translate/test       │
│  - /api/translate-logs       │ (admin 翻译日志 API)
│  - /api/translate/backfill   │ (admin 批量补译触发)
│  事件:                       │
│  - PostCreated → 插 pending  │
│  - PostRevised → 更新 pending│
└──────────┬───────────────────┘
           │ 共享 MariaDB
           ▼
┌─────────────────────────────┐
│  MariaDB                     │
│  - post_translations         │
│  - translation_logs          │
│  - posts (Flarum 原表)       │
└─────────────────────────────┘
           ▲
           │ 共享 MariaDB
┌──────────┴───────────────────┐
│  Translator Worker Container │
│  (同 image, 不同 command)    │
│  command: php flarum         │
│           translate:run      │
│  - 5s 轮询 pending 行         │
│  - 流式 SSE 调 opencode-go   │
│  - stdout = docker logs      │
│  - ORDER BY is_backfill,    │
│    created_at (新帖优先)     │
└──────────┬───────────────────┘
           │ HTTPS SSE
           ▼
┌─────────────────────────────┐
│  opencode-go (外部)          │
│  OpenAI 兼容 API 网关         │
│  流式响应 (规避 60s 超时)     │
└─────────────────────────────┘
```

### 2.1 数据流

**自动翻译 (新帖/编辑)**:
1. 用户发帖 / 编辑帖 → Flarum `PostCreated` / `PostRevised` 事件
2. 事件监听器检查 `auto_translate` setting 开关
   - ON: `INSERT … ON DUPLICATE KEY UPDATE` post_translations 行 (status=pending)
   - OFF: 不动表 (旧翻译保留)
3. worker 5s 轮询发现 pending → curl SSE opencode-go → 累积译文
4. worker 写回 post_translations.status=done + translated_content
5. worker 写一行 translation_logs (prompt_full + response_final 等)
6. 前端 5s 轮询 discussion → PostSerializer 注入字段 → 渲染译文

**批量补译 (admin 触发)**:
1. admin 进 TranslateAdminPage → 批量补译 tab → 选 lang 下拉 → 点扫描按钮
2. controller 一条 SQL `SELECT p.id FROM posts p LEFT JOIN post_translations t ON t.post_id=p.id AND t.target_lang=? WHERE t.post_id IS NULL OR t.source_content != p.content` 获得所有缺译 post
3. controller 对每个 post 插 pending 行 (`is_backfill=1`)
4. worker 优先消费 `is_backfill=0` 的 pending,消化完后才开始补译
5. admin 在同 tab 看进度 (查 `SELECT COUNT(*) FROM post_translations WHERE is_backfill=1 AND target_lang=? AND status IN ('pending','running','error')`)

---

## 3. 数据库设计

### 3.1 `post_translations` (worker 主消费表)

```sql
CREATE TABLE post_translations (
  post_id            INT UNSIGNED NOT NULL,
  target_lang        VARCHAR(10) NOT NULL,
  source_content     MEDIUMTEXT NOT NULL,
  translated_content MEDIUMTEXT NULL,
  source_lang        VARCHAR(10) NULL,
  status             ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
  error              TEXT NULL,
  is_backfill        TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, target_lang),
  KEY idx_status_backfill_created (status, is_backfill, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**设计要点**:
- 主键 `(post_id, target_lang)` 一个帖子每个目标语言一条记录
- `source_content` 翻译时的 post.content_html 快照,用于 staleness 比对 (与 post 当前 content_html strcmp)
- `status` 状态机: pending (等待) → running (worker 处理) → done / error
- `is_backfill` 1=补译扫描插入, 0=事件触发;worker 优先消费 0 让新帖优先翻译
- `error` 失败时的错误信息,前端 hover 显示

### 3.2 `translation_logs` (admin 日志表)

```sql
CREATE TABLE translation_logs (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id            INT UNSIGNED NOT NULL,
  target_lang        VARCHAR(10) NOT NULL,
  prompt_full        MEDIUMTEXT NOT NULL,      -- 完整 system+user messages JSON
  response_final     MEDIUMTEXT NOT NULL,      -- 完整 LLM 返回拼接
  source_content     MEDIUMTEXT NOT NULL,       -- 原文冗余便于日志直观
  translated_content MEDIUMTEXT NOT NULL,
  tokens_in          INT UNSIGNED NULL,        -- opencode-go usage.prompt_tokens
  tokens_out         INT UNSIGNED NULL,        -- opencode-go usage.completion_tokens
  latency_ms         INT UNSIGNED NULL,
  status             ENUM('done','error') NOT NULL,
  error              TEXT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post (post_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**设计要点**:
- 每次翻译完成 (无论 done 还是 error)新增一行,不替换→提供历史审计
- `prompt_full` 存当时的 system+user messages JSON,system_prompt 被改后历史日志不变
- `tokens_in/out` 从 opencode-go 最后的 chunk `usage` 字段拿,拿不到则 NULL
- 不存逐 token

### 3.3 Migration

通过 Flarum `Migration` schema builder 写两个 migration:
- `up`: 建表 + (可选) 从旧 `translation_cache` 表导入数据 (可选,迁移成本另议)
- `down`: 删表

migration 文件路径: `flarum-ext-translate/migrations/2026_07_22_000000_create_post_translations.php`, `..._create_translation_logs.php`

---

## 4. PHP 后端

### 4.1 命名空间与目录结构

```
src/
├── Api/
│   ├── Controller/
│   │   ├── AbstractTranslateController.php (复用/重写)
│   │   ├── TranslateRetryController.php     -- POST /api/translate/retry
│   │   ├── TranslateTestController.php      -- POST /api/translate/test (admin)
│   │   ├── TranslateBackfillController.php  -- POST /api/translate/backfill (admin)
│   │   └── TranslateLogController.php       -- GET  /api/translate-logs (admin)
│   └── Serializer/
│       └── PostSerializerAttributes.php      (注: 用 Extend\ApiSerializer 注入)
├── Command/
│   └── TranslateRunCommand.php               -- php flarum translate:run
├── Event/
│   └── TranslationEventListeners.php
├── Job/
│   └── Worker.php                            -- 主 worker 逻辑 (取行/翻译/写回)
├── Llm/
│   ├── OpenAiSseClient.php                   -- curl SSE 流式封装
│   ├── PromptBuilder.php                     -- 构建 system+user messages (搬 providers.py 的 _build_prompt)
│   └── ResponseParser.php                    -- 解析 SSE chunks 累积 content
├── Repository/
│   ├── PostTranslationRepository.php         -- post_translations 增删改查
│   └── TranslationLogRepository.php          -- translation_logs 增删改查
└── LangMapConfig.php                          -- 解析 lang_map setting 字符串
```

### 4.2 关键类职责

#### `TranslateRunCommand` (worker 入口)

注册: `extend.php` 中 `(new Extend\Console())->command('translate:run', TranslateRunCommand::class)`

执行流程:
1. `set_time_limit(0)`, `ini_set('memory_limit', '512M')`
2. 启动 cleanup: `UPDATE post_translations SET status='pending' WHERE status='running'` (复位孤儿)
3. `while (true)`:
   - `SELECT * FROM post_translations WHERE status='pending' ORDER BY is_backfill ASC, created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED`
   - 拿到行→`status='running'` commit→翻译→写回 status + translated_content→新增 translation_logs→commit
   - 空结果→`sleep(5)`

#### `Worker` (单帖翻译逻辑)

被 TranslateRunCommand 调用,处理一个 post:
1. 设置 status=running
2. `PromptBuilder` 构建 messages
3. `OpenAiSseClient` 流式请求 opencode-go,累积译文
4. 同步 echo 每个 token 到 stdout (实时刷新 docker logs)
5. 成功后:`status=done`, `translated_content=累积结果`
6. 失败 (curl 超时/LLM 报错): `status=error`, `error=消息`
7. 写 translation_logs 一行 (done/error)
8. 全程在一个 DB 事务内

#### `OpenAiSseClient`

封装 curl SSE 请求:
- `POST {base_url}/v1/chat/completions` body `{model, messages, temperature:0, stream:true}`
- Header `Authorization: Bearer {api_key}`
- curl options:
  - `CURLOPT_WRITEFUNCTION` 回调: 按 `\n\n` 边界累积 SSE chunks,解析 `data: {json}`,提取 `choices[0].delta.content` 累加
  - `data: [DONE]` 结束
  - 最后 chunk 的 `usage` 字段提取 tokens_in/out
  - `CURLOPT_LOW_SPEED_LIMIT=1`, `CURLOPT_LOW_SPEED_TIME=60`: 60s 无新 chunk 视为超时
- 同 provider 重试 `max_retries` 次 (transient 错),4xx 立即抛
- 同一个类被 worker 和 TranslateTestController 复用

#### `PromptBuilder`

搬 `fastapi-translator/app/providers.py::_build_prompt` 到 PHP:
- system message: ACGN 规则 + BBCode 保护 + 源语言即译文返原文那条 (默认值复用之前 providers.py 的字面文本, 可被 `twikura-translate.system_prompt` setting 覆盖)
- user message: 直接是 `post.content` (HTML/BBCode 原文,无 JSON 索引)
- 不再分多文本索引

#### `PostTranslationRepository`

- `enqueue(post_id, target_lang, source_content, is_backfill)`: `INSERT … ON DUPLICATE KEY UPDATE status='pending', source_content=?, error=NULL`
- `takeNext()`: `SELECT … WHERE status='pending' ORDER BY is_backfill ASC, created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED`
- `markRunning(id)`, `markDone(id, translation, source_lang)`, `markError(id, error)`
- `findForPost(postId, lang)`: 给 PostSerializer 用
- `findStalePostIds(lang, limit=5000)`: 给批量补译用,SQL 见 §2.1

#### `TranslationEventListeners`

注册: `extend.php` 中 `(new Extend\SimpleFlarum())->listen(PostCreated::class, …)->listen(PostRevised::class, …)`

监听器逻辑:
- 如果 `auto_translate` setting = OFF → 不动表,直接返回
- 否则取 post.content_html,调 `PostTranslationRepository::enqueue(post_id, lang, source_content, is_backfill=0)`
- 注意: 只处理 `CommentPost` (不处理 flags/likes 等其他 post type),沿用现有 JS 的 `post.contentType() === 'comment'` 判定逻辑
- target_lang 从哪里来?见 §4.4

#### `LangMapConfig`

解析 `twikura-translate.lang_map` setting 字符串:
- 输入格式: `"zh:Simplified Chinese\nja:Japanese\nen:English"` (一行 `code: name`)
- 输出: `['zh' => 'Simplified Chinese', 'ja' => 'Japanese', 'en' => 'English']`
- 容错: 空行/缺冒号/多余空格跳过,不报错
- worker 启动时读一次,admin 修改 setting 后需重启 worker (Redis cache:可加 admin 一个 `bust_cache` action)

### 4.3 extend.php 注册

```php
<?php

use Flarum\Extend;
use Twikura\Translate\Api\Controller\TranslateBackfillController;
use Twikura\Translate\Api\Controller\TranslateLogController;
use Twikura\Translate\Api\Controller\TranslateRetryController;
use Twikura\Translate\Api\Controller\TranslateTestController;
use Twikura\Translate\Command\TranslateRunCommand;
use Twikura\Translate\Event\TranslationEventListeners;
use Twikura\Translate\Repository\PostTranslationRepository;
use Twikura\Translate\Repository\TranslationLogRepository;
use Twikura\Translate\Llm\OpenAiSseClient;
use Twikura\Translate\Llm\PromptBuilder;

return [
    // Frontends
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    // Console command (worker)
    (new Extend\Console())
        ->command('translate:run', TranslateRunCommand::class),

    // API routes
    (new Extend\Routes('api'))
        ->post('/translate/retry', 'twikura-translate.retry', TranslateRetryController::class)
        ->post('/translate/test', 'twikura-translate.test', TranslateTestController::class)
        ->post('/translate/backfill', 'twikura-translate.backfill', TranslateBackfillController::class)
        ->get('/translate-logs', 'twikura-translate.logs', TranslateLogController::class),

    // PostSerializer 注入字段
    (new Extend\ApiSerializer(\Flarum\Post\PostSerializer::class))
        ->attributes(function ($serializer, $post, $attributes) {
            // 注入 translation_status / translated_content / translation_error
            // ...
        }),

    // Event listeners
    (new Extend\SimpleFlarum())
        ->listen(\Flarum\Post\Event\Created::class, [TranslationEventListeners::class, 'onPostCreated'])
        ->listen(\Flarum\Post\Event\Revised::class, [TranslationEventListeners::class, 'onPostRevised']),

    // Settings defaults
    (new Extend\Settings())
        ->default('twikura-translate.auto_translate', '1')
        ->default('twikura-translate.llm_base_url', 'http://opencode-go:3000')
        ->default('twikura-translate.llm_api_key', '')
        ->default('twikura-translate.llm_model', 'deepseek-v4-flash')
        ->default('twikura-translate.llm_timeout', '120')
        ->default('twikura-translate.llm_max_retries', '1')
        ->default('twikura-translate.lang_map', "zh:Simplified Chinese\nja:Japanese\nen:English\nko:Korean\nfr:French\nde:German\nes:Spanish\nru:Russian\npt:Portuguese\nth:Thai\nvi:Vietnamese\nar:Arabic")
        ->default('twikura-translate.system_prompt', <ACGN 规则模板,见 §7.1>)
        ->default('twikura-translate.allow_guests', '1')
        ->serializeToForum('twikuraTranslateAutoEnabled', 'twikura-translate.auto_translate', 'boolval', true)
        ->serializeToForum('twikuraTranslateAllowGuests', 'twikura-translate.allow_guests', 'boolval', true),

    new Extend\Locales(__DIR__.'/locale'),
];
```

### 4.4 target_lang 从哪里来?

**已决**: 事件触发时仅为 site default lang (Flarum `default_locale` setting) 插一条 pending, 其他 lang 通过 admin 批量补译补齐。

- 实现: `TranslationEventListeners::dispatchTranslationJob` 读 `SettingsRepositoryInterface::get('default_locale')`, 拿 site default locale (如 `zh`), 调 `PostTranslationRepository::enqueue(post_id, lang, source_content, is_backfill=0)`
- 理由: 配额可控, 符合"减少复杂度"目标; 论坛一般主 locale 只一个, 多语言场景下其他 lang 由 admin 显式触发批量补译 (Backfill tab)
- 注意: Flarum `default_locale` 是 admin 设置 (e.g. `zh`), 需确保该 code 在 `lang_map` setting 内存在, 否则跳过 (记 translation_logs 一行 status=error "default_locale not in lang_map")

### 4.5 PostSerializer 注入字段

通过 `Extend\ApiSerializer(\Flarum\Post\PostSerializer::class)->attributes(...)` 加:
- `translation_status`: 'pending' | 'running' | 'done' | 'error' | null (无翻译行)
- `translated_content`: string | null
- `translation_error`: string | null (error 时显示)
- `translation_target_lang`: string | null (当前译文 lang)

数据来自 `PostTranslationRepository::findForPost(post.id, current_lang)`,current_lang 从 request 的 `Accept-Language` 或 query param `?lang=zh` 拿,前端按 `document.documentElement.lang` 也传。

---

## 5. 前端 (JS)

### 5.1 构建工具链

`js/` 目录加入:

**`js/package.json`**:
```json
{
  "private": true,
  "name": "@twikura/flarum-ext-translate",
  "scripts": {
    "dev": "webpack --mode development --watch",
    "build": "webpack --mode production"
  },
  "devDependencies": {
    "flarum-tsconfig": "^1.0.2",
    "flarum-webpack-config": "^2.0.0",
    "typescript": "^4.5.4",
    "webpack": "^5.76.0",
    "webpack-cli": "^4.9.1"
  }
}
```

**`js/webpack.config.js`**:
```js
module.exports = require('flarum-webpack-config')();
```

**`js/tsconfig.json`**:
```json
{
  "extends": "flarum-tsconfig",
  "include": [
    "src/**/*",
    "../vendor/flarum/core/js/dist-typings/@types/**/*"
  ],
  "compilerOptions": {
    "declarationDir": "./dist-typings",
    "baseUrl": ".",
    "paths": {
      "flarum/*": ["../vendor/flarum/core/js/dist-typings/*"]
    }
  }
}
```

**入口**:
- `js/forum.ts` → `export * from './src/forum';`
- `js/admin.ts` → `export * from './src/admin';`

**TypeScript 1.8.x 版本约束** (来自 librarian 验证):
- `flarum-webpack-config: ^2.0.0` (NOT v3.x → 否则 flarum.reg 未定义)
- `flarum-tsconfig: ^1.0.2`
- import 路径 `flarum/forum/app` → `flarum.core.compat['forum/app']` 运行时

### 5.2 `js/src/forum/index.ts`

迁移 `js/dist/forum.js` 当前逻辑到 TS,核心改动:

```ts
import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import Post from 'flarum/forum/components/Post';
import CommentPost from 'flarum/forum/components/CommentPost';
import Button from 'flarum/common/components/Button';
import ItemList from 'flarum/common/utils/ItemList';

app.initializers.add('twikura-translate', () => {
  extend(HeaderSecondary.prototype, 'items', addAutoButton);
  extend(Post.prototype, 'footerItems', addPostButton);
  extend(CommentPost.prototype, 'bodyItems', replacePostBody);
});

// 核心组件: TranslatedPostBody
// - oninit: 拿 post.translation_status / translated_content / translation_error
// - status=done 且 auto-translate 开启: 渲染 translated_content
// - status=pending/running: 渲染原文 + "翻译中" spinner 角标
// - status=error: 渲染原文 + "翻译失败" + 重试按钮 (按 allow_guests 控制按钮可见性)
// - status=null (无翻译行): 渲染原文
// 5s 轮询: 有 pending/running post 时调 app.store.find('discussions', discussion_id), 全部 done 停轮询
// 重试按钮: app.request({method:'POST', url:'/api/translate/retry', body:{post_id, target_lang}})
```

### 5.3 `js/src/admin/index.ts`

迁移 `js/dist/admin.js` + 新增 TranslateAdminPage:

```ts
import app from 'flarum/admin/app';
import AdminPage from 'flarum/admin/components/AdminPage';

app.initializers.add('twikura-translate-admin', () => {
  // 1. 原 ExtensionPage settings (7 个基础 setting + system_prompt textarea + 测试翻译面板)
  app.extensionData
    .for('twikura-translate')
    .registerSetting(...)
    ...

  // 2. 新增 TranslateAdminPage, 注册 admin 路由 /admin/translate
  app.routes['twikura-translate'] = { path: '/translate', component: TranslateAdminPage };
  // 左侧导航加一项
});
```

**TranslateAdminPage** 内部三 tab (Mithril 状态切换):

```ts
class TranslateAdminPage extends AdminPage {
  // header: "Translate Management" + tab 切换
  // tabs:
  //   - Logs: 分页表 (GET /api/translate-logs?page=N&lang=zh), 展示时间/post 链接/lang/status/tokens/latency, 点行展开 prompt_full + response_final
  //   - Prompt: 显示当前 twikura-translate.system_prompt setting 值 (只读), 提示"去 extension settings 修改"
  //   - Backfill: lang 下拉 + "扫描并补译"按钮 + 进度显示 (pending/running/error 计数)
}
```

### 5.4 前端轮询策略

- Discussion 页面打开时:扫所有当前帖的 `translation_status`
- 有 pending/running → 启动 setInterval 5s,调 `app.store.find('discussions', discussion_id)` 重新拉所有 posts
- 所有 status=done 或 error → 停 setInterval
- `clearInterval` 在 DiscussionPage `onbeforeunload` / 组件卸载时

---

## 6. Docker 配置

### 6.1 `docker-compose.dev.yml` 改造

```yaml
services:
  flarum:
    # ...同现
    depends_on:
      - db
      - cache
      - translator-worker        # 改依赖为 worker (worker 早于 web 启动不影响)
    networks: [flarum-network]

  db: # 不变
  cache: # 不变

  translator-worker:
    build:
      context: .
      dockerfile: Dockerfile.dev  # 同 flarum 镜像
    restart: always
    command: ["php", "flarum", "translate:run"]
    environment:
      - TZ=Asia/Shanghai
      - DB_HOST=db
      - DB_NAME=${DB_NAME:-flarum}
      - DB_USER=${DB_USER:-flarum_user}
      - DB_PASSWORD=${DB_PASSWORD:-X_ZsxP9X9UsaQxkFgY}
    depends_on:
      - db
    networks: [flarum-network]
    healthcheck:
      test: ["CMD-SHELL", "pgrep -f 'flarum translate:run' || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3

  # 删除原 translator service (FastAPI)
  # 删除 DEEPSEEK_API_KEY env (改用 twikura-translate.llm_api_key setting,admin 后台填)
```

### 6.2 Dockerfile 改造

在现有 Dockerfile 生成 flarum 镜像的步骤里加入构建 JS 步骤:

```dockerfile
# ... 现有 flarum 安装步骤

# 构建扩展前端
COPY ./extensions/translate_flarum/flarum-ext-translate /extensions/translate_flarum/flarum-ext-translate
RUN composer config repositories.twikura-translate path /extensions/translate_flarum/flarum-ext-translate

# composer require 扩展
RUN composer require twikura/flarum-ext-translate:@dev
RUN php flarum extension:enable twikura-translate
RUN php flarum cache:clear

# 构建 JS (带 Node)
RUN apt-get update && apt-get install -y nodejs npm
WORKDIR /extensions/translate_flarum/flarum-ext-translate/js
RUN npm ci
RUN npm run build
WORKDIR /var/www/flarum

# 启动入口:
#  - 默认 (web container): php-fpm + nginx
#  - worker (translator-worker container): php flarum translate:run (由 compose command 重写)
```

---

## 7. 提示词与配置

### 7.1 默认 system_prompt

复用 `fastapi-translator/app/providers.py::_build_prompt` 里的系统规则文本 (调整为单文本模式,去掉 JSON 索引相关):

```
You are a professional translator expert in the ACGN (Anime, Comic, Games, Novel) field, gaming culture, and internet slang.
Please translate the provided forum post content into the specified target language according to the rules below.

Rules:
1. Target Language Detection (Crucial): If the source text is already in the target language, or if its core valid content is already in the target language, return the original text EXACTLY as it is. Do not force a secondary translation or localization.
2. Output Format: Return ONLY the translated text. Do not output any extra text, explanations, or Markdown code block wrappers.
3. Tone & Style: All translated content must align with the communication habits of online forums and gaming communities (natural, colloquial, and engaging). Accurately convey ACGN terminology, gaming jargon, and memes if present.
4. BBCode & Formatting Preservation: Strictly preserve all forum rich text formatting within the post. This includes BBCode tags (e.g., [b], [i], [url], [img], [code]), Markdown syntax (**, #), Emojis, and HTML tags. Do not alter, omit, or translate the tags themselves; only translate the text inside or around them, adapting their positions to fit the natural word order.
5. Forum Elements & Quotes: If the original post contains forum-specific elements like @usernames, #hashtags, or forum quote blocks ([quote]...[/quote]), keep them exactly as they are. Never translate user names or configuration attributes within tags.
6. Code Block Protection: If the post contains programming code blocks (e.g., ```javascript ... ``` or [code]...[/code]), DO NOT translate the content inside them. Only translate the discussion text before or after the code blocks.
7. Completeness: Strictly maintain the full content. Do not merge, truncate, summarize, or omit any text content.
8. Plain Output: The output must be plain text. Do not wrap it in Markdown code blocks (like ```json ... ```), and do not include any introductory or concluding pleasantries.

TARGET_LANGUAGE: {lang_name}
```

`{lang_name}` 占位符由 PromptBuilder 替换为 `lang_map[target_lang]`。

### 7.2 Admin Settings 清单 (Q17 锁定)

| setting key | 类型 | 默认值 | 说明 |
|---|---|---|---|
| `twikura-translate.auto_translate` | bool | `1` | 自动翻译开关 |
| `twikura-translate.llm_base_url` | text | `http://opencode-go:3000` | opencode-go API base URL |
| `twikura-translate.llm_api_key` | password | `''` | opencode-go API key (Flarum 1.8 settings 类型支持 password?) |
| `twikura-translate.llm_model` | text | `deepseek-v4-flash` | 模型 ID |
| `twikura-translate.llm_timeout` | number | `120` | 整体超时秒数 |
| `twikura-translate.llm_max_retries` | number | `1` | 同 provider 重试次数 |
| `twikura-translate.lang_map` | textarea | (见下) | 目标语言映射, 一行 `code: name` |
| `twikura-translate.system_prompt` | textarea | (见 §7.1) | 系统 prompt 模板 |
| `twikura-translate.allow_guests` | bool | `1` | 游客可见译文 + 可触发重试 |

`lang_map` 默认:
```
zh:Simplified Chinese
ja:Japanese
en:English
ko:Korean
fr:French
de:German
es:Spanish
ru:Russian
pt:Portuguese
th:Thai
vi:Vietnamese
ar:Arabic
```

---

## 8. Admin 后台 UI (TranslateAdminPage)

### 8.1 入口

- URL: `/admin/translate`
- 注册到 Flarum admin 左侧导航, 图标 `fas fa-language`
- Tab 切换无路由 (Mithril 状态),默认显示 Logs tab

### 8.2 Tab 1: Logs

- 表头:时间 / post ID (链到前台 `/d/{discussion_id}/{post_number}`) / lang / status / tokens in/out / latency / error
- 分页:每页 50 条,底部 `Prev | Page N | Next`
- 每行可展开:下拉显示 prompt_full (pre) + response_final (pre) + source_content (pre) + translated_content (pre)
- 顶部筛选:lang 下拉 + status 下拉 (done/error)
- API: `GET /api/translate-logs?page=1&lang=&status=`

### 8.3 Tab 2: Prompt

- 显示 `twikura-translate.system_prompt` 当前值 (只读 textarea)
- 提示文字:"修改请前往 Extensions → Translate → Settings"
- 旁边显示 lang_map 当前解析结果 (zh → Simplified Chinese, ja → Japanese, ...) 让 admin 一目了然

### 8.4 Tab 3: Backfill

- 表单:
  - lang 下拉 (从 lang_map 取)
  - "扫描并补译"按钮
- 点击按钮:
  - POST `/api/translate/backfill` body `{lang}` → controller 调 `findStalePostIds(lang, 5000)` 全扫
  - 返回 `{scanned: N, inserted: M}` 给前端显示
  - 之后前端轮询 `GET /api/translate/backfill/status?lang=zh` 返回 `{pending, running, done, error}` 计数,每 5s 刷新直到 `pending + running = 0`
  - 显示进度条: `(done + error) / (pending + running + done + error)`

---

## 9. API 路由清单

| 方法 | 路径 | Controller | 权限 | 说明 |
|---|---|---|---|---|
| POST | `/api/translate/retry` | TranslateRetryController | 登录 (或 allow_guests=true 时游客可) | body `{post_id, target_lang}`,把 status=error 的行改回 pending |
| POST | `/api/translate/test` | TranslateTestController | 仅 admin | body `{text, lang}`,同步 curl opencode-go SSE,返回译文 (经 OpenAiSseClient) |
| POST | `/api/translate/backfill` | TranslateBackfillController | 仅 admin | body `{lang}`,扫描全站缺译 post,插 pending 行 (is_backfill=1),返回数量 |
| GET | `/api/translate/backfill/status` | TranslateBackfillStatusController | 仅 admin | query `lang`,返回 `{pending, running, done, error}` 计数 |
| GET | `/api/translate-logs` | TranslateLogController | 仅 admin | query `page, lang, status`,返回日志分页数据 |

注: 没有_list*_retrieve 单帖翻译的 GET_ 路由,翻译字段通过 PostSerializer 注入 post response。

---

## 10. 已决问题收尾

### 10.1 事件触发时为哪些 lang 翻译?

**已决**: 仅为 site default lang (Flarum `default_locale` setting) 翻译,其他 lang 由 admin 批量补译补齐。

- 理由:符合"减少复杂度"目标,Flarum 通常设置一个主 locale,避免一帖插 N 条 pending 配额放大 N 倍
- 实现: `TranslationEventListeners::dispatchTranslationJob` 读 `SettingsRepositoryInterface::get('default_locale')`,取 lang_map 解析后的 code (e.g. Flarum default_locale `zh` → map 到 lang_map 的 `zh`), 唯一插一条 pending

### 10.2 旧 `translation_cache` 表数据迁移

**已决**: 不迁移,旧表丢弃,旧翻译重跑。

- 理由:旧表按 `text_hash + target_lang` 索引,无 `post_id` 关联,反查 `WHERE posts.content_html = translation_cache.original_text` 命中率有限且实现成本高
- 建议:首次启用新版后,admin 直接到 TranslateAdminPage → Backfill tab 跑一次全站点回填 (一条 SQL 即可)

### 10.3 site default lang 来源

Flarum `default_locale` setting 是 admin 设置的默认语言 (如 `zh`),worker 事件触发时只读这一项,符合"懒加载其他 lang via backfill"策略。

---

## 11. 实施步骤

### 11.1 阶段 1: PHP 核心 (per-post 自动翻译 MVP)

1. 移除 `flarum-ext-translate/src/Api/Controller/AbstractTranslateController::proxy` 相关 (指向 FastAPI 的代码)
2. 写两个 migration (建 post_translations + translation_logs 表)
3. 写 Repository 两个
4. 写 LangMapConfig
5. 写 PromptBuilder
6. 写 OpenAiSseClient (curl SSE 流式)
7. 写 Worker (单帖翻译逻辑)
8. 写 TranslateRunCommand (调 Worker 的 while 循环)
9. 写 TranslationEventListeners (PostCreated/PostRevised, 读 default_locale 插一条 pending)
10. 写 TranslateRetryController
11. 写 PostSerializer attributes 注入 (translation_status 等)
12. 改 extend.php 注册所有
13. 删旧 TranslateController / BatchTranslateController (FastAPI proxy 相关)

### 11.2 阶段 2: JS 构建 + 前端改造

1. 在 `flarum-ext-translate/js/` 加 package.json / tsconfig.json / webpack.config.js
2. `js/forum.ts` + `js/admin.ts` 入口
3. `js/src/forum/` 迁移 dist/forum.js 现有逻辑,改用 TS import,改请求路径,加 status 处理 + 5s 轮询 + spinner + 重试按钮
4. `js/src/admin/` 迁移 dist/admin.js 现有 settings + 测试翻译面板(改请求 endpoint 到 `/api/translate/test`)
5. 写 TranslateAdminPage (三 tab)
6. 改 extend.php 注册 AdminPage 路由 (wait, Flarum admin 路由 Extend 是? confirm 下)

### 11.3 阶段 3: admin 管理功能

1. TranslateLogController + 序列化
2. TranslateBackfillController + TranslateBackfillStatusController
3. TranslateAdminPage 三个 tab 完整 UI

### 11.4 阶段 4: Docker 改造

1. 改 docker-compose.dev.yml: 删 translator (FastAPI), 加 translator-worker
2. 改 Dockerfile.dev: 加 Node 安装 + JS 构建步骤
3. 改 docker-compose.prod.yml / Dockerfile 同步
4. 改 .env.example: 删 DEEPSEEK_API_KEY (admin setting 替代)
5. 改 README.md 更新架构图、settings 说明、启动方式

### 11.5 阶段 5: 清理

1. 删除 `fastapi-translator/` 目录 (保留为参考?另开玩笑,删干净)
2. 删除 `flarum-ext-translate/js/dist/*.js` 的 原 IIFE 版本 (由 webpack 生成替代)
3. 更新 AGENTS.md

---

## 12. 风险与缓解

| 风险 | 缓解 |
|---|---|
| opencode-go 60s 超时 | SSE 流式响应,每 chunk 重置 low-speed 计时,只 60s 内无新 chunk 视超时 |
| worker 单进程吞吐瓶颈 | MVP 接受,大论坛可后续 `php flarum translate:run --worker=2` 多进程跑 (pcntl 分叉,SKIP LOCKED 防冲突) |
| post 更新频繁致翻译频繁 | 同 post_id 同 lang 已有翻译行更新 status=pending,worker 串行消化;本质保证不堆积 (订一个 post 不会同时有两条 pending 行) |
| LLM 返回无效 JSON (旧机制) | 新机制单文本无 JSON 解析,LLM 返纯文本,无 JSON 错误路径 |
| admin 误操作批量补译炸 LLM 配额 | insertStalePostIds 默认 limit=5000, admin 看进度可控;worker 优先 is_backfill=0, 不堵新帖 |
| worker 死掉中途翻译完但写回未 commit | 翻译全程在一个 DB 事务内 commit,worker 异常 ROLLBACK,启动 orphan cleanup 复位 running=running → pending 自动重跑 |
| 译文 HTML 注入 XSS | translated_content 通过 `m.trust()` 渲染 (同现有逻辑,不加 esc); 但 worker 收到的 LLM 输出应信任 (LLM 输出由 system prompt 约束,不当 forum 代码注入风险高则需 esc;现状忽略,沿用 dist.js 行为) |

---

## 13. 验收清单

实施完成后需验证:

- [ ] Migration up/down 都可执行
- [ ] `php flarum translate:run` 启动后清理 orphan running,正确消费 pending
- [ ] 发帖 / 编辑帖 触发 pending 插入 (开关 ON)
- [ ] 开关 OFF 时新帖不触发
- [ ] worker 翻译完成写回 status=done + 新增 translation_logs
- [ ] worker 流式 stdout 在 docker logs 可见
- [ ] 前端 pending 显示 spinner
- [ ] 前端 done 后 5s 内自动渲染译文
- [ ] 前端 error 显示重试按钮,点击后 status 回 pending
- [ ] admin settings 页可编辑 9 个 setting
- [ ] admin 测试翻译按钮可同步返回译文
- [ ] admin TranslateAdminPage 三 tab 正常
- [ ] 批量补译按钮扫描全站正确插 pending
- [ ] worker 优先消费非补译行
- [ ] docker-compose up 启动 web + db + cache + translator-worker 正常
- [ ] translator-worker health check 通过
- [ ] `docker logs -f translator-worker` 看到流式翻译输出
- [ ] FastAPI translator 容器 及对应配置全部移除
- [ ] `fastapi-translator/` 目录已删
- [ ] README 更新完成

---

## 14. 附录:与旧 FastAPI 系统对比

| 维度 | 旧 (FastAPI) | 新 (纯插件) |
|---|---|---|
| 架构 | Flarum → FastAPI → DeepSeek | Flarum web + worker → opencode-go |
| 语言 | PHP + Python | 纯 PHP |
| 翻译触发 | 前端 on-demand (用户点翻译按钮) | 服务端事件 (created/revised) 自动 |
| 翻译粒度 | 多文本打包 JSON 索引 (batch) | 单 post 单 call (无 batch) |
| 翻译存储 | text_hash + target_lang (跨帖共享 cache) | post_id + target_lang (per-post 行) |
| 翻译状态机 | 无 (同步返回) | pending/running/done/error |
| 失败处理 | 同步返回 error | 表 status=error + 前端重试按钮 |
| 流式 | SSE,但前端没接 | worker stdout 流式 (规避 60s 超时) |
| Cache | SQLite + MariaDB (FastAPI 内部) | MariaDB (Flarum 内部) |
| Admin 日志 | FastAPI 自带 HTML 页 /admin/cache /logs | Flarum admin 后台 TranslateAdminPage |
| 提示词编辑 | 改 providers.py code | admin setting textarea |
| 批量回填 | 无 | admin TranslateAdminPage Backfill tab |
| Container 数 | 4 (flarum + db + cache + translator) | 4 (flarum + db + cache + translator-worker) |
| 复杂度 | 跨语言, 多 service, 双表 (cache + logs) | 单语言, 共享 image, 双表 (status + logs) |