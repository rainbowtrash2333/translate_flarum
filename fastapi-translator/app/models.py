from __future__ import annotations

from sqlalchemy import Column, String, Text, DateTime
from sqlalchemy.orm import DeclarativeBase
from sqlalchemy.sql import func


class Base(DeclarativeBase):
    pass


class TranslationCache(Base):
    __tablename__ = "translation_cache"

    text_hash = Column(String(64), primary_key=True)
    target_lang = Column(String(10), primary_key=True)
    original_text = Column(Text, nullable=False)
    translated_text = Column(Text, nullable=False)
    source_lang = Column(String(10), nullable=True)
    created_at = Column(DateTime, server_default=func.now())
    updated_at = Column(DateTime, server_default=func.now(), onupdate=func.now())
