from __future__ import annotations

import asyncio
import hashlib
import logging
from app.database import get_cache
from app.batch_queue import enqueue
logger = logging.getLogger("translate.translator")


def _compute_hash(text: str) -> str:
    return hashlib.sha256(text.strip().encode("utf-8")).hexdigest()


async def translate_one(text: str, lang: str) -> dict[str, str]:
    text_hash = _compute_hash(text)

    # Fast path: cache hit avoids queue overhead entirely
    cached = await get_cache(text_hash, lang)
    if cached is not None:
        return {"lang": lang, "source": text, "translated": cached}

    try:
        translated = await enqueue(text, lang)
    except RuntimeError as exc:
        if str(exc) == "All translation providers failed":
            logger.error("All providers failed for lang=%s text=%s", lang, text[:80])
            return {"lang": lang, "source": text, "translated": "", "error": "translation_failed"}
        logger.exception("Unexpected RuntimeError in translate_one")
        return {"lang": lang, "source": text, "translated": "", "error": str(exc)}
    except asyncio.TimeoutError:
        logger.error("Translation timeout for lang=%s text=%s", lang, text[:80])
        return {"lang": lang, "source": text, "translated": "", "error": "translation_timeout"}
    except Exception as exc:
        logger.exception("Unexpected error in translate_one")
        return {"lang": lang, "source": text, "translated": "", "error": str(exc)}

    # Cache write is handled by batch_queue._process_batch after result dispatch
    return {"lang": lang, "source": text, "translated": translated}


async def translate_many(items: list[dict], lang: str) -> list[dict[str, str]]:
    async def _translate_item(item: dict) -> dict[str, str]:
        result = await translate_one(item["text"], lang)
        result["id"] = item["id"]
        return result

    results = await asyncio.gather(*[_translate_item(item) for item in items])
    return list(results)


async def translate_one_stream(text: str, lang: str):
    """Async generator that yields SSE event dicts for single translation.

    Yields dicts with keys: {"event": str, "data": dict}

    Event sequence:
    - {"event": "start", "data": {"lang": lang, "source": text}}
    - Zero or more {"event": "token", "data": {"text": char, "index": n}}
    - On error: {"event": "error", "data": {"error": error_message}} (then stops)
    - On success: {"event": "done", "data": {"lang": lang, "source": text, "translated": translated_text}}
    """
    try:
        result = await translate_one(text, lang)

        if "error" in result:
            yield {"event": "error", "data": {"error": result["error"]}}
            return

        yield {"event": "start", "data": {"lang": lang, "source": text}}

        translated = result["translated"]
        for i, char in enumerate(translated):
            yield {"event": "token", "data": {"text": char, "index": i}}
            await asyncio.sleep(0.015)

        yield {"event": "done", "data": {"lang": lang, "source": text, "translated": translated}}
    except Exception as exc:
        yield {"event": "error", "data": {"error": str(exc)}}


async def translate_many_stream(items: list[dict], lang: str):
    """Async generator that yields SSE event dicts for batch translation.

    Yields dicts with keys: {"event": str, "data": dict}

    Event sequence:
    - {"event": "start", "data": {"lang": lang, "count": len(items)}}
    - For each item: {"event": "item_done", "data": {"id": item["id"], "lang": lang, "source": item["text"], "translated": result_str}}
    - On fatal error: {"event": "error", "data": {"error": error_message}} (then stops)
    - {"event": "complete", "data": {"lang": lang, "count": len(items)}}
    """
    try:
        yield {"event": "start", "data": {"lang": lang, "count": len(items)}}

        tasks = [asyncio.ensure_future(translate_one(item["text"], lang)) for item in items]
        task_map = {id(t): item for t, item in zip(tasks, items)}

        for done in asyncio.as_completed(tasks):
            result = await done
            item_data = task_map[id(done)]

            data = {
                "id": item_data["id"],
                "lang": lang,
                "source": item_data["text"],
                "translated": result.get("translated", ""),
            }
            if "error" in result:
                data["error"] = result["error"]

            yield {"event": "item_done", "data": data}

        yield {"event": "complete", "data": {"lang": lang, "count": len(items)}}
    except Exception as exc:
        yield {"event": "error", "data": {"error": str(exc)}}
