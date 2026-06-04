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
