from __future__ import annotations

import json
import logging

import httpx

from app.config import ProviderConfig, ProvidersConfig

logger = logging.getLogger("translate.providers")


async def _call_provider(provider: ProviderConfig, payload: dict) -> dict:
    """Call a single OpenAI-compatible provider endpoint and return the parsed JSON response."""
    timeout = httpx.Timeout(
        connect=10.0,
        read=provider.timeout_seconds,
        write=10.0,
        pool=10.0,
    )
    url = f"{provider.base_url}/chat/completions"
    headers = {
        "Authorization": f"Bearer {provider.api_key}",
        "Content-Type": "application/json",
    }

    async with httpx.AsyncClient(timeout=timeout) as client:
        try:
            response = await client.post(url, json=payload, headers=headers)
            response.raise_for_status()
        except httpx.TimeoutException as e:
            raise RuntimeError(f"Provider {provider.name} timed out") from e
        except httpx.HTTPStatusError as e:
            status = e.response.status_code
            if 400 <= status < 500:
                # 4xx errors propagate immediately — do NOT trigger failover
                raise
            # 5xx errors are transient — raise RuntimeError to trigger failover
            raise RuntimeError(
                f"Provider {provider.name} returned {status}"
            ) from e
        except httpx.RequestError as e:
            raise RuntimeError(
                f"Provider {provider.name} network error: {e}"
            ) from e

    data = response.json()

    # Validate that the response contains non-empty content
    try:
        content = data["choices"][0]["message"]["content"]
        if not content:
            raise RuntimeError(
                f"Provider {provider.name} returned empty content"
            )
    except (KeyError, IndexError) as e:
        raise RuntimeError(
            f"Provider {provider.name} returned malformed response"
        ) from e

    return data


async def _call_provider_with_retry(
    provider: ProviderConfig, payload: dict
) -> dict:
    """Call a provider with up to (max_retries + 1) attempts."""
    last_exc: Exception | None = None
    total_attempts = provider.max_retries + 1

    for attempt in range(1, total_attempts + 1):
        try:
            return await _call_provider(provider, payload)
        except Exception as e:
            last_exc = e
            if attempt < total_attempts:
                logger.warning(
                    "Provider %s attempt %d/%d failed: %s — retrying",
                    provider.name,
                    attempt,
                    total_attempts,
                    e,
                )
            else:
                logger.warning(
                    "Provider %s attempt %d/%d failed: %s — no retries left",
                    provider.name,
                    attempt,
                    total_attempts,
                    e,
                )

    # All retries exhausted
    raise last_exc  # type: ignore[misc]


def _build_prompt(
    texts: list[str], target_lang: str, lang_map: dict[str, str]
) -> list[dict]:
    lang_name = lang_map.get(target_lang, target_lang)
    system = (
        "You are a professional translator expert in the ACGN (Anime, Comic, Games, Novel) field, gaming culture, and internet slang.\n"
        "Please translate the provided game forum post content (in JSON format) into the specified target language according to the rules below.\n\n"
        "Rules:\n"
        "1. Target Language Detection (Crucial): If the source text is already in the target language, or if its core valid content is already in the target language, return the original text EXACTLY as it is. Do not force a secondary translation or localization.\n"
        "2. Output Format: Return ONLY a valid JSON object. You must strictly maintain the exact same key structure as the input JSON. Do not output any extra text, explanations, or Markdown code block wrappers.\n"
        "3. Tone & Style: All translated content must align with the communication habits of online forums and gaming communities (natural, colloquial, and engaging). Accurately convey ACGN terminology, gaming jargon, and memes if present.\n"
        "4. BBCode & Formatting Preservation: Strictly preserve all forum rich text formatting within the post. This includes BBCode tags (e.g., [b], [i], [url], [img], [code]), Markdown syntax (`**`, `#`), Emojis, and HTML tags. Do not alter, omit, or translate the tags themselves; only translate the text inside or around them, adapting their positions to fit the natural word order.\n"
        "5. Forum Elements & Quotes: If the original post contains forum-specific elements like @usernames, #hashtags, or forum quote blocks ([quote]...[/quote]), keep them exactly as they are. Never translate user names or configuration attributes within tags.\n"
        "6. Code Block Protection: If the post contains programming code blocks (e.g., ```javascript ... ``` or [code]...[/code]), DO NOT translate the content inside them. Only translate the discussion text before or after the code blocks.\n"
        "7. Completeness: Strictly maintain the one-to-one mapping for each field. Do not merge, truncate, summarize, or omit any text content.\n"
        "8. JSON Only: The output must be a pure JSON string. Do not wrap it in Markdown code blocks (like ```json ... ```), and do not include any introductory or concluding pleasantries.\n\n"
        f"TARGET_LANGUAGE: {lang_name}"
    )
    width = len(str(len(texts)))
    indexed = {str(i).zfill(width): t for i, t in enumerate(texts)}
    user = json.dumps(indexed, ensure_ascii=False)
    return [
        {"role": "system", "content": system},
        {"role": "user", "content": user},
    ]


def _build_provider_payload(provider: ProviderConfig, messages: list[dict]) -> dict:
    """Build the request payload for a specific provider."""
    return {
        "model": provider.model,
        "messages": messages,
        "temperature": 0,
    }


def _parse_batch_result(texts: list[str], result: dict) -> list[str]:
    """Extract translated strings from the AI response, ordered to match input texts."""
    width = len(str(len(texts)))
    translated: list[str] = []
    for i in range(len(texts)):
        key = str(i).zfill(width)
        if key in result:
            translated.append(result[key])
        else:
            logger.warning(
                "Missing key '%s' in AI response, using original text as fallback",
                key,
            )
            translated.append(texts[i])
    return translated


def _extract_and_parse_content(response: dict) -> dict:
    """Extract assistant content from response and parse it as JSON."""
    content = response["choices"][0]["message"]["content"]
    return json.loads(content)


async def translate_via_providers(
    texts: list[str],
    target_lang: str,
    lang_map: dict[str, str],
    providers_config: ProvidersConfig,
) -> list[str]:
    """Translate texts using primary provider with automatic fallback on transient errors.

    4xx HTTP errors propagate immediately (no failover).
    Transient errors (timeout, 5xx, network, empty content, invalid JSON) trigger failover.
    Invalid JSON from the AI gets one retry on the same provider before failing over.
    """
    primary = providers_config.primary
    fallback = providers_config.fallback

    # --- Try primary provider ---
    try:
        messages = _build_prompt(texts, target_lang, lang_map)
        payload = _build_provider_payload(primary, messages)
        response = await _call_provider_with_retry(primary, payload)

        # Parse the AI content as JSON
        try:
            parsed = _extract_and_parse_content(response)
        except (json.JSONDecodeError, KeyError, IndexError) as e:
            # Invalid JSON — retry once on the same provider
            logger.warning(
                "Invalid JSON from primary, retrying once: %s", e
            )
            response = await _call_provider_with_retry(primary, payload)
            try:
                parsed = _extract_and_parse_content(response)
            except (json.JSONDecodeError, KeyError, IndexError) as e2:
                raise RuntimeError(
                    "Invalid JSON from primary after retry"
                ) from e2

        return _parse_batch_result(texts, parsed)

    except httpx.HTTPStatusError:
        # 4xx errors — propagate immediately, do NOT fall back
        raise
    except RuntimeError as primary_error:
        # Transient error — fall back to fallback provider
        logger.warning(
            "Primary provider failed: %s, switching to fallback",
            primary_error,
        )

    # --- Fallback provider ---
    try:
        messages = _build_prompt(texts, target_lang, lang_map)
        payload = _build_provider_payload(fallback, messages)
        response = await _call_provider_with_retry(fallback, payload)

        try:
            parsed = _extract_and_parse_content(response)
        except (json.JSONDecodeError, KeyError, IndexError) as e:
            logger.warning(
                "Invalid JSON from fallback, retrying once: %s", e
            )
            response = await _call_provider_with_retry(fallback, payload)
            try:
                parsed = _extract_and_parse_content(response)
            except (json.JSONDecodeError, KeyError, IndexError) as e2:
                raise RuntimeError(
                    "Invalid JSON from fallback after retry"
                ) from e2

        return _parse_batch_result(texts, parsed)

    except httpx.HTTPStatusError:
        # 4xx from fallback — propagate
        raise
    except RuntimeError as fallback_error:
        logger.error("All providers failed: fallback error: %s", fallback_error)
        raise RuntimeError("All providers failed") from fallback_error
