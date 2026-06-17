from __future__ import annotations

import logging
from typing import Optional

from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from sqlalchemy import select, func, desc
from sqlalchemy.dialects.mysql import insert as mysql_insert

from app.config import DatabaseConfig
from app.models import Base, TranslationCache

_engine = None
_async_session_factory = None
logger = logging.getLogger("translate.database")


async def init_db(config: DatabaseConfig) -> None:
    global _engine, _async_session_factory

    url = f"{config.driver}://{config.user}:{config.password}@{config.host}:{config.port}/{config.database}?charset=utf8mb4"

    try:
        _engine = create_async_engine(
            url,
            pool_size=config.pool_size,
            pool_recycle=3600,
            echo=False,
        )
    except Exception as error:
        logger.warning("MariaDB unavailable, running without cache: %s", error)
        _engine = None
        _async_session_factory = None
        return

    try:
        async with _engine.begin() as conn:
            await conn.run_sync(Base.metadata.create_all)
    except Exception as error:
        logger.warning("MariaDB unavailable, running without cache: %s", error)
        await _engine.dispose()
        _engine = None
        _async_session_factory = None
        return

    _async_session_factory = async_sessionmaker(
        _engine,
        class_=AsyncSession,
        expire_on_commit=False,
    )


async def close_db() -> None:
    global _engine, _async_session_factory

    if _engine is not None:
        await _engine.dispose()

    _engine = None
    _async_session_factory = None


async def get_cache(text_hash: str, target_lang: str) -> Optional[str]:
    if _async_session_factory is None:
        return None

    try:
        async with _async_session_factory() as session:
            result = await session.execute(
                select(TranslationCache).where(
                    TranslationCache.text_hash == text_hash,
                    TranslationCache.target_lang == target_lang,
                )
            )
            row = result.scalar_one_or_none()
            return row.translated_text if row is not None else None
    except Exception as error:
        logger.warning("Cache read failed: %s", error)
        return None


async def set_cache(
    text_hash: str,
    target_lang: str,
    original_text: str,
    translated_text: str,
    source_lang: Optional[str] = None,
) -> None:
    if _async_session_factory is None:
        return

    try:
        async with _async_session_factory() as session:
            stmt = mysql_insert(TranslationCache).values(
                text_hash=text_hash,
                target_lang=target_lang,
                original_text=original_text,
                translated_text=translated_text,
                source_lang=source_lang,
            )
            stmt = stmt.on_duplicate_key_update(
                original_text=stmt.inserted.original_text,
                translated_text=stmt.inserted.translated_text,
                source_lang=stmt.inserted.source_lang,
            )
            await session.execute(stmt)
            await session.commit()
    except Exception as error:
        logger.warning("Cache write failed: %s", error)


async def get_cache_count() -> int:
    """Get total number of cached translations.

    Returns:
        Total count, or 0 if cache is unavailable.
    """
    if _async_session_factory is None:
        return 0

    try:
        async with _async_session_factory() as session:
            result = await session.execute(
                select(func.count()).select_from(TranslationCache)
            )
            return result.scalar() or 0
    except Exception as error:
        logger.warning("Cache count failed: %s", error)
        return 0


async def get_all_cache(
    limit: int = 100,
    offset: int = 0,
) -> list[dict]:
    """Fetch translation cache entries with pagination, newest first.

    Args:
        limit: Maximum number of rows to return.
        offset: Number of rows to skip.

    Returns:
        List of dicts with keys: text_hash, target_lang, original_text,
        translated_text, source_lang, created_at, updated_at.
        Timestamps are returned as ISO-format strings.
        Returns empty list if cache is unavailable or error occurs.
    """
    if _async_session_factory is None:
        return []

    try:
        async with _async_session_factory() as session:
            stmt = (
                select(TranslationCache)
                .order_by(desc(TranslationCache.updated_at))
                .offset(offset)
                .limit(limit)
            )
            result = await session.execute(stmt)
            rows = result.scalars().all()

            return [
                {
                    "text_hash": row.text_hash,
                    "target_lang": row.target_lang,
                    "original_text": row.original_text,
                    "translated_text": row.translated_text,
                    "source_lang": row.source_lang,
                    "created_at": row.created_at.isoformat() if row.created_at else None,
                    "updated_at": row.updated_at.isoformat() if row.updated_at else None,
                }
                for row in rows
            ]
    except Exception as error:
        logger.warning("Cache listing failed: %s", error)
        return []
