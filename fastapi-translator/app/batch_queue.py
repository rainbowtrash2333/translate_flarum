from __future__ import annotations

import asyncio
import hashlib
import logging
from typing import Optional

from app.config import TranslationConfig, AppConfig
from app.database import get_cache, set_cache
from app.providers import translate_via_providers

_queue: asyncio.Queue = None  # asyncio.Queue of (text, target_lang, future) tuples
_config: TranslationConfig = None
_lang_map: dict[str, str] = {}
_providers_config = None  # ProvidersConfig from AppConfig
_batch_task: asyncio.Task = None
_shutdown_event: asyncio.Event = None
logger = logging.getLogger("translate.batch_queue")


async def init_queue(config: AppConfig) -> None:
    global _config, _lang_map, _providers_config, _queue, _shutdown_event, _batch_task
    _config = config.translation
    _lang_map = config.translation.lang_map
    _providers_config = config.providers
    _queue = asyncio.Queue()
    _shutdown_event = asyncio.Event()
    _batch_task = asyncio.create_task(_batch_loop())
    logger.info(
        f"Batch queue initialized with window={_config.batch_window_seconds}s, "
        f"max_batch={_config.max_batch_size}"
    )


async def shutdown_queue() -> None:
    global _batch_task, _shutdown_event
    if _shutdown_event:
        _shutdown_event.set()
    if _batch_task and not _batch_task.done():
        _batch_task.cancel()
        try:
            await _batch_task
        except asyncio.CancelledError:
            pass
    logger.info("Batch queue shut down")


def _compute_hash(text: str) -> str:
    stripped = text.strip()
    return hashlib.sha256(stripped.encode()).hexdigest()


async def enqueue(text: str, target_lang: str) -> str:
    text_hash = _compute_hash(text)
    cached = await get_cache(text_hash, target_lang)
    if cached is not None:
        return cached

    future = asyncio.get_running_loop().create_future()
    await _queue.put((text, target_lang, future))

    result = await asyncio.wait_for(future, timeout=120.0)
    return result


async def _batch_loop() -> None:
    while not _shutdown_event.is_set():
        batch = []
        try:
            item = await asyncio.wait_for(
                _queue.get(), timeout=_config.batch_window_seconds
            )
            batch.append(item)
        except asyncio.TimeoutError:
            continue

        for _ in range(_config.max_batch_size - 1):
            try:
                item = _queue.get_nowait()
                batch.append(item)
            except asyncio.QueueEmpty:
                break

        await _process_batch(batch)

    remaining = []
    while not _queue.empty():
        try:
            remaining.append(_queue.get_nowait())
        except asyncio.QueueEmpty:
            break
    if remaining:
        await _process_batch(remaining)


async def _process_batch(batch: list[tuple[str, str, asyncio.Future]]) -> None:
    texts = [item[0] for item in batch]
    target_lang = batch[0][1]
    futures = [item[2] for item in batch]

    # Verify all items share the same target_lang
    for item in batch:
        if item[1] != target_lang:
            logger.warning(
                f"Mixed target_lang in batch (expected {target_lang}, got {item[1]}); "
                f"using first item's lang"
            )
            break

    try:
        results = await translate_via_providers(
            texts, target_lang, _lang_map, _providers_config
        )
        for i, future in enumerate(futures):
            if not future.done():
                future.set_result(results[i])
            text_hash = _compute_hash(texts[i])
            try:
                asyncio.create_task(set_cache(text_hash, target_lang, texts[i], results[i]))
            except Exception:
                pass  # cache write is best-effort; must not break result dispatch
    except RuntimeError:
        for future in futures:
            if not future.done():
                future.set_exception(RuntimeError("All translation providers failed"))
    except asyncio.InvalidStateError:
        pass  # Future already resolved (e.g. cancelled by enqueue timeout); no-op
    except Exception as e:
        for future in futures:
            if not future.done():
                future.set_exception(e)
