from __future__ import annotations

import json
import logging
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from html import escape
from threading import Lock

from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.responses import HTMLResponse, RedirectResponse, StreamingResponse
from fastapi.security import HTTPBasic, HTTPBasicCredentials
from pydantic import BaseModel, Field

from app.config import load_config, AppConfig
from app.database import get_all_cache, get_cache_count, init_db, close_db
from app.translator import translate_one, translate_many, translate_one_stream, translate_many_stream
from app.batch_queue import init_queue, shutdown_queue


MAX_LOGS = 200
TRANSLATION_LOGS: list[dict[str, str]] = []
TRANSLATION_LOGS_LOCK = Lock()

config: AppConfig = None


class TranslateRequest(BaseModel):
    lang: str = Field(..., min_length=1, max_length=32)
    text: str
    format: str = "auto"


class BatchTranslateItem(BaseModel):
    id: str = Field(..., min_length=1, max_length=128)
    text: str
    format: str = "auto"


class BatchTranslateRequest(BaseModel):
    lang: str = Field(..., min_length=1, max_length=32)
    items: list[BatchTranslateItem] = Field(default_factory=list)


def log_translation(kind: str, lang: str, source: str, translated: str, item_id: str = "") -> None:
    entry = {
        "time": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
        "type": kind,
        "lang": lang,
        "id": item_id,
        "source": source,
        "translated": translated,
    }

    with TRANSLATION_LOGS_LOCK:
        TRANSLATION_LOGS.insert(0, entry)
        del TRANSLATION_LOGS[MAX_LOGS:]


@asynccontextmanager
async def lifespan(app: FastAPI):
    global config
    # Load config
    config = load_config()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(name)s: %(message)s")
    logger = logging.getLogger("translate")
    logger.info("Starting Flarum Translate backend...")

    # Init database (may log warning if MariaDB unavailable)
    await init_db(config.database)

    # Init batch queue
    await init_queue(config)

    logger.info(f"Server ready on {config.server.host}:{config.server.port}")
    yield
    # Shutdown
    logger.info("Shutting down...")
    await shutdown_queue()
    await close_db()


app = FastAPI(title="Flarum Translate", version="0.2.0", lifespan=lifespan)


# --- HTTP Basic Auth ---
security = HTTPBasic(auto_error=False)


def verify_admin(credentials: HTTPBasicCredentials | None = Depends(security)) -> None:
    if (
        credentials is None
        or credentials.username != "admin"
        or credentials.password != config.auth.password
    ):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid credentials",
            headers={"WWW-Authenticate": "Basic"},
        )


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/translate")
async def translate(request: TranslateRequest) -> dict[str, str]:
    result = await translate_one(request.text, request.lang)
    log_translation("single", request.lang, request.text, result.get("translated", ""))
    return result


@app.post("/translate/batch")
async def translate_batch(request: BatchTranslateRequest) -> dict[str, object]:
    items = [{"id": item.id, "text": item.text, "format": item.format} for item in request.items]
    results = await translate_many(items, request.lang)
    for item, result in zip(request.items, results):
        log_translation("batch", request.lang, item.text, result.get("translated", ""), item.id)
    return {"lang": request.lang, "items": results}


@app.post("/translate/stream")
async def translate_stream(request: TranslateRequest) -> StreamingResponse:
    async def event_stream():
        final_result = None
        async for event in translate_one_stream(request.text, request.lang):
            if event["event"] == "done":
                final_result = event["data"]
            yield f"event: {event['event']}\ndata: {json.dumps(event['data'], ensure_ascii=False)}\n\n"
        if final_result:
            log_translation("single_stream", request.lang, request.text, final_result.get("translated", ""))

    return StreamingResponse(event_stream(), media_type="text/event-stream")


@app.post("/translate/batch/stream")
async def translate_batch_stream(request: BatchTranslateRequest) -> StreamingResponse:
    items = [{"id": item.id, "text": item.text, "format": item.format} for item in request.items]

    async def event_stream():
        async for event in translate_many_stream(items, request.lang):
            yield f"event: {event['event']}\ndata: {json.dumps(event['data'], ensure_ascii=False)}\n\n"

    return StreamingResponse(event_stream(), media_type="text/event-stream")


# --- Admin UI ---

@app.get("/admin", dependencies=[Depends(verify_admin)])
async def admin_index() -> RedirectResponse:
    return RedirectResponse(url="/admin/cache")


@app.get("/admin/cache", response_class=HTMLResponse, dependencies=[Depends(verify_admin)])
async def admin_cache() -> str:
    # Fetch cache data
    page = 0  # default page
    limit = 50
    offset = page * limit
    entries = await get_all_cache(limit=limit, offset=offset)
    total = await get_cache_count()
    total_pages = max(1, (total + limit - 1) // limit)

    rows = "\n".join(
        "<tr>"
        f"<td><code>{escape(entry['text_hash'][:12])}…</code></td>"
        f"<td>{escape(entry['target_lang'])}</td>"
        f"<td><pre>{escape(entry['original_text'])}</pre></td>"
        f"<td><pre>{escape(entry['translated_text'])}</pre></td>"
        f"<td>{escape(entry['source_lang'] or '')}</td>"
        f"<td>{escape(entry['updated_at'] or '')}</td>"
        "</tr>"
        for entry in entries
    )

    if not rows:
        rows = '<tr><td colspan="6" class="empty">No cached translations yet.</td></tr>'

    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Translation Cache</title>
  <style>
    body {{
      color: #1f2933;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 24px;
    }}
    header {{
      align-items: center;
      display: flex;
      gap: 12px;
      justify-content: space-between;
      margin-bottom: 16px;
    }}
    h1 {{ font-size: 22px; margin: 0; }}
    nav {{ display: flex; gap: 8px; }}
    nav a {{
      background: #f3f5f7;
      border: 1px solid #cfd7df;
      border-radius: 4px;
      color: #1f2933;
      cursor: pointer;
      padding: 6px 10px;
      text-decoration: none;
    }}
    nav a:hover {{ background: #e5e9ed; }}
    nav .active {{ background: #1f2933; color: #fff; border-color: #1f2933; }}
    table {{
      border-collapse: collapse;
      width: 100%;
    }}
    th, td {{
      border: 1px solid #d8dee6;
      padding: 8px;
      text-align: left;
      vertical-align: top;
    }}
    th {{
      background: #eef2f6;
      font-weight: 600;
      white-space: nowrap;
    }}
    pre {{
      font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
      margin: 0;
      max-width: 320px;
      overflow-x: auto;
      white-space: pre-wrap;
      word-break: break-word;
    }}
    code {{
      font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
      font-size: 12px;
    }}
    .empty {{
      color: #6b7280;
      text-align: center;
    }}
    .pagination {{
      align-items: center;
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-top: 16px;
    }}
    .pagination a {{
      background: #f3f5f7;
      border: 1px solid #cfd7df;
      border-radius: 4px;
      color: #1f2933;
      padding: 4px 10px;
      text-decoration: none;
    }}
    .pagination a:hover {{ background: #e5e9ed; }}
    .pagination span {{ color: #6b7280; font-size: 14px; }}
    .summary {{ color: #6b7280; font-size: 14px; margin-bottom: 12px; }}
  </style>
</head>
<body>
  <header>
    <h1>Translation Cache</h1>
    <nav>
      <a href="/admin/cache" class="active">Cache</a>
      <a href="/admin/logs">Logs</a>
    </nav>
  </header>
  <div class="summary">{total} entries total</div>
  <table>
    <thead>
      <tr>
        <th>Hash</th>
        <th>Lang</th>
        <th>Original</th>
        <th>Translated</th>
        <th>Source Lang</th>
        <th>Updated</th>
      </tr>
    </thead>
    <tbody>
      {rows}
    </tbody>
  </table>
</body>
</html>"""


@app.get("/admin/api/cache", dependencies=[Depends(verify_admin)])
async def admin_cache_api() -> dict:
    page = 0
    limit = 100
    offset = page * limit
    entries = await get_all_cache(limit=limit, offset=offset)
    total = await get_cache_count()
    return {"total": total, "limit": limit, "offset": offset, "entries": entries}


@app.get("/admin/logs", response_class=HTMLResponse, dependencies=[Depends(verify_admin)])
async def admin_logs() -> str:
    with TRANSLATION_LOGS_LOCK:
        logs = list(TRANSLATION_LOGS)

    rows = "\n".join(
        "<tr>"
        f"<td>{escape(entry['time'])}</td>"
        f"<td>{escape(entry['type'])}</td>"
        f"<td>{escape(entry['lang'])}</td>"
        f"<td>{escape(entry['id'])}</td>"
        f"<td><pre>{escape(entry['source'])}</pre></td>"
        f"<td><pre>{escape(entry['translated'])}</pre></td>"
        "</tr>"
        for entry in logs
    )

    if not rows:
        rows = '<tr><td colspan="6" class="empty">No translation requests yet.</td></tr>'

    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Translation Logs</title>
  <style>
    body {{
      color: #1f2933;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 24px;
    }}
    header {{
      align-items: center;
      display: flex;
      gap: 12px;
      justify-content: space-between;
      margin-bottom: 16px;
    }}
    h1 {{ font-size: 22px; margin: 0; }}
    nav {{ display: flex; gap: 8px; }}
    nav a {{
      background: #f3f5f7;
      border: 1px solid #cfd7df;
      border-radius: 4px;
      color: #1f2933;
      cursor: pointer;
      padding: 6px 10px;
      text-decoration: none;
    }}
    nav a:hover {{ background: #e5e9ed; }}
    nav .active {{ background: #1f2933; color: #fff; border-color: #1f2933; }}
    table {{
      border-collapse: collapse;
      width: 100%;
    }}
    th, td {{
      border: 1px solid #d8dee6;
      padding: 8px;
      text-align: left;
      vertical-align: top;
    }}
    th {{
      background: #eef2f6;
      font-weight: 600;
      white-space: nowrap;
    }}
    pre {{
      font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
      margin: 0;
      max-width: 560px;
      white-space: pre-wrap;
      word-break: break-word;
    }}
    .empty {{
      color: #6b7280;
      text-align: center;
    }}
  </style>
</head>
<body>
  <header>
    <h1>Translation Logs</h1>
    <nav>
      <a href="/admin/cache">Cache</a>
      <a href="/admin/logs" class="active">Logs</a>
    </nav>
  </header>
  <table>
    <thead>
      <tr>
        <th>Time</th>
        <th>Type</th>
        <th>Lang</th>
        <th>ID</th>
        <th>Source</th>
        <th>Translated</th>
      </tr>
    </thead>
    <tbody>
      {rows}
    </tbody>
  </table>
</body>
</html>"""


@app.get("/admin/api/logs", dependencies=[Depends(verify_admin)])
async def admin_logs_api() -> list[dict]:
    with TRANSLATION_LOGS_LOCK:
        return list(TRANSLATION_LOGS)


@app.post("/admin/logs/clear", dependencies=[Depends(verify_admin)])
async def admin_clear_logs() -> dict[str, str]:
    with TRANSLATION_LOGS_LOCK:
        TRANSLATION_LOGS.clear()

    return {"status": "ok"}


@app.get("/logs", response_class=HTMLResponse)
def logs_page() -> str:
    with TRANSLATION_LOGS_LOCK:
        logs = list(TRANSLATION_LOGS)

    rows = "\n".join(
        "<tr>"
        f"<td>{escape(entry['time'])}</td>"
        f"<td>{escape(entry['type'])}</td>"
        f"<td>{escape(entry['lang'])}</td>"
        f"<td>{escape(entry['id'])}</td>"
        f"<td><pre>{escape(entry['source'])}</pre></td>"
        f"<td><pre>{escape(entry['translated'])}</pre></td>"
        "</tr>"
        for entry in logs
    )

    if not rows:
        rows = '<tr><td colspan="6" class="empty">No translation requests yet.</td></tr>'

    return f"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Translation Logs</title>
  <style>
    body {{
      color: #1f2933;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 24px;
    }}
    header {{
      align-items: center;
      display: flex;
      gap: 12px;
      justify-content: space-between;
      margin-bottom: 16px;
    }}
    h1 {{
      font-size: 22px;
      margin: 0;
    }}
    .actions {{
      display: flex;
      gap: 8px;
    }}
    a,
    button {{
      background: #f3f5f7;
      border: 1px solid #cfd7df;
      border-radius: 4px;
      color: #1f2933;
      cursor: pointer;
      font: inherit;
      padding: 6px 10px;
      text-decoration: none;
    }}
    table {{
      border-collapse: collapse;
      width: 100%;
    }}
    th,
    td {{
      border: 1px solid #d8dee6;
      padding: 8px;
      text-align: left;
      vertical-align: top;
    }}
    th {{
      background: #eef2f6;
      font-weight: 600;
      white-space: nowrap;
    }}
    pre {{
      font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
      margin: 0;
      max-width: 560px;
      white-space: pre-wrap;
      word-break: break-word;
    }}
    .empty {{
      color: #6b7280;
      text-align: center;
    }}
  </style>
</head>
<body>
  <header>
    <h1>Translation Logs</h1>
    <div class="actions">
      <a href="/logs">Refresh</a>
      <a href="/logs.json">JSON</a>
      <form method="post" action="/logs/clear">
        <button type="submit">Clear</button>
      </form>
    </div>
  </header>
  <table>
    <thead>
      <tr>
        <th>Time</th>
        <th>Type</th>
        <th>Lang</th>
        <th>ID</th>
        <th>Source</th>
        <th>Translated</th>
      </tr>
    </thead>
    <tbody>
      {rows}
    </tbody>
  </table>
</body>
</html>"""


@app.get("/logs.json")
def logs_json() -> list[dict[str, str]]:
    with TRANSLATION_LOGS_LOCK:
        return list(TRANSLATION_LOGS)


@app.post("/logs/clear")
def clear_logs() -> dict[str, str]:
    with TRANSLATION_LOGS_LOCK:
        TRANSLATION_LOGS.clear()

    return {"status": "ok"}


if __name__ == "__main__":
    import uvicorn
    cfg = load_config()
    uvicorn.run("app.main:app", host=cfg.server.host, port=cfg.server.port, reload=False)
