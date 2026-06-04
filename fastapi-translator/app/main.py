from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from datetime import datetime, timezone
from html import escape
from threading import Lock

from fastapi import FastAPI
from fastapi.responses import HTMLResponse
from pydantic import BaseModel, Field

from app.config import load_config, AppConfig
from app.database import init_db, close_db
from app.translator import translate_one, translate_many
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
