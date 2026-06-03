from __future__ import annotations

import hashlib
import sqlite3
from datetime import datetime, timezone
from html import escape
from pathlib import Path
from threading import Lock
from typing import Iterable

from fastapi import FastAPI
from fastapi.responses import HTMLResponse
from pydantic import BaseModel, Field


BASE_DIR = Path(__file__).resolve().parent.parent
DATA_DIR = BASE_DIR / "data"
DB_PATH = DATA_DIR / "translations.sqlite3"
TRANSLATION_CACHE_VERSION = "test-format-v2"
MAX_LOGS = 200
TRANSLATION_LOGS: list[dict[str, str]] = []
TRANSLATION_LOGS_LOCK = Lock()


class TranslateRequest(BaseModel):
    lang: str = Field(..., min_length=1, max_length=32)
    text: str


class BatchTranslateItem(BaseModel):
    id: str = Field(..., min_length=1, max_length=128)
    text: str


class BatchTranslateRequest(BaseModel):
    lang: str = Field(..., min_length=1, max_length=32)
    items: list[BatchTranslateItem] = Field(default_factory=list)


app = FastAPI(title="Flarum Test Translator", version="0.1.0")


def init_db() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect(DB_PATH) as connection:
        connection.execute(
            """
            CREATE TABLE IF NOT EXISTS translations (
                cache_key TEXT PRIMARY KEY,
                lang TEXT NOT NULL,
                source TEXT NOT NULL,
                translated TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            """
        )


@app.on_event("startup")
def startup() -> None:
    init_db()


def cache_key(lang: str, text: str) -> str:
    digest = hashlib.sha256(f"{TRANSLATION_CACHE_VERSION}\0{lang}\0{text}".encode("utf-8")).hexdigest()

    return digest


def fake_translate(lang: str, text: str) -> str:
    return f"tran_{lang}_ {text}"


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


def translate_one(lang: str, text: str, kind: str = "single", item_id: str = "") -> dict[str, str]:
    key = cache_key(lang, text)

    with sqlite3.connect(DB_PATH) as connection:
        row = connection.execute(
            "SELECT translated FROM translations WHERE cache_key = ?",
            (key,),
        ).fetchone()

        if row:
            translated = row[0]
        else:
            translated = fake_translate(lang, text)
            connection.execute(
                """
                INSERT INTO translations (cache_key, lang, source, translated)
                VALUES (?, ?, ?, ?)
                """,
                (key, lang, text, translated),
            )

    log_translation(kind, lang, text, translated, item_id)

    return {
        "lang": lang,
        "source": text,
        "translated": translated,
    }


def translate_many(lang: str, items: Iterable[BatchTranslateItem]) -> list[dict[str, str]]:
    translated_items = []

    for item in items:
        result = translate_one(lang, item.text, "batch", item.id)
        result["id"] = item.id
        translated_items.append(result)

    return translated_items


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/translate")
def translate(request: TranslateRequest) -> dict[str, str]:
    return translate_one(request.lang, request.text)


@app.post("/translate/batch")
def translate_batch(request: BatchTranslateRequest) -> dict[str, object]:
    return {
        "lang": request.lang,
        "items": translate_many(request.lang, request.items),
    }


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
