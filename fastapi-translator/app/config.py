from dataclasses import dataclass, field
import os
import re
import yaml
import sys
from pathlib import Path
from typing import Optional


@dataclass
class ProviderConfig:
    name: str
    base_url: str
    api_key: str
    model: str
    timeout_seconds: int = 30
    max_retries: int = 1


@dataclass
class DatabaseConfig:
    driver: str = "mysql+aiomysql"
    host: str = "127.0.0.1"
    port: int = 3306
    user: str = "flarum_translate"
    password: str = "password"
    database: str = "flarum_translate"
    pool_size: int = 5


@dataclass
class ServerConfig:
    host: str = "127.0.0.1"
    port: int = 8000


@dataclass
class TranslationConfig:
    max_batch_size: int = 30
    batch_window_seconds: float = 1.0
    max_segment_chars: int = 4000
    lang_map: dict = field(default_factory=dict)


@dataclass
class ProvidersConfig:
    primary: ProviderConfig
    fallback: ProviderConfig


@dataclass
class AppConfig:
    server: ServerConfig
    database: DatabaseConfig
    providers: ProvidersConfig
    translation: TranslationConfig


def _substitute_env(value: str) -> str:
    """Replace ${VAR_NAME} placeholders with os.environ values."""
    if not isinstance(value, str):
        return value

    def _replacer(match: re.Match) -> str:
        var_name = match.group(1)
        return os.environ.get(var_name, "")

    return re.sub(r"\$\{(\w+)\}", _replacer, value)


def _substitute_env_in_dict(d: dict) -> dict:
    """Recursively substitute ${VAR_NAME} placeholders in all string values."""
    result = {}
    for key, value in d.items():
        if isinstance(value, dict):
            result[key] = _substitute_env_in_dict(value)
        elif isinstance(value, str):
            result[key] = _substitute_env(value)
        else:
            result[key] = value
    return result


def load_config(path: str = "config.yaml") -> AppConfig:
    """Load and validate config.yaml, substituting environment variables."""
    config_path = Path(path)
    if not config_path.is_absolute():
        config_path = Path(__file__).parent.parent / path

    if not config_path.exists():
        print(f"Error: Config file not found: {config_path}", file=sys.stderr)
        sys.exit(1)

    with open(config_path, "r") as f:
        raw = yaml.safe_load(f)

    cfg = _substitute_env_in_dict(raw)

    for key in ("server", "database", "providers", "translation"):
        if key not in cfg:
            print(f"Error: Missing required config key: {key}", file=sys.stderr)
            sys.exit(1)

    providers = cfg["providers"]
    for key in ("primary", "fallback"):
        if key not in providers:
            print(f"Error: Missing required provider key: {key}", file=sys.stderr)
            sys.exit(1)

    for key in ("primary", "fallback"):
        api_key = providers[key].get("api_key", "")
        if not api_key:
            print(
                f"Warning: {key} provider api_key is empty after env substitution",
                file=sys.stderr,
            )

    server = cfg["server"]
    server_config = ServerConfig(
        host=server.get("host", "127.0.0.1"),
        port=server.get("port", 8000),
    )

    db = cfg["database"]
    database_config = DatabaseConfig(
        driver=db.get("driver", "mysql+aiomysql"),
        host=db.get("host", "127.0.0.1"),
        port=db.get("port", 3306),
        user=db.get("user", "flarum_translate"),
        password=db.get("password", "password"),
        database=db.get("database", "flarum_translate"),
        pool_size=db.get("pool_size", 5),
    )

    primary = providers["primary"]
    fallback = providers["fallback"]
    providers_config = ProvidersConfig(
        primary=ProviderConfig(
            name=primary.get("name", "primary"),
            base_url=primary.get("base_url", ""),
            api_key=primary.get("api_key", ""),
            model=primary.get("model", ""),
            timeout_seconds=primary.get("timeout_seconds", 30),
            max_retries=primary.get("max_retries", 1),
        ),
        fallback=ProviderConfig(
            name=fallback.get("name", "fallback"),
            base_url=fallback.get("base_url", ""),
            api_key=fallback.get("api_key", ""),
            model=fallback.get("model", ""),
            timeout_seconds=fallback.get("timeout_seconds", 30),
            max_retries=fallback.get("max_retries", 1),
        ),
    )

    trans = cfg["translation"]
    translation_config = TranslationConfig(
        max_batch_size=trans.get("max_batch_size", 30),
        batch_window_seconds=trans.get("batch_window_seconds", 1.0),
        max_segment_chars=trans.get("max_segment_chars", 4000),
        lang_map=trans.get("lang_map", {}),
    )

    return AppConfig(
        server=server_config,
        database=database_config,
        providers=providers_config,
        translation=translation_config,
    )
