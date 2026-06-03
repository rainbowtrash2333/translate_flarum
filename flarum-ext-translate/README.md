# Flarum Translate Extension

Flarum 1.8 extension that adds post translation controls and proxies requests to
a local FastAPI translator service.

## Install in a local Flarum

Add this extension as a Composer path repository from your Flarum install:

```bash
composer config repositories.twikura-translate path /home/twikura/projects/translate_flarum/flarum-ext-translate
composer require twikura/flarum-ext-translate:@dev
php flarum extension:enable twikura-translate
php flarum cache:clear
```

Set `FastAPI Base URL` in the extension settings. Use a local-only address such
as `http://127.0.0.1:8000` when FastAPI runs on the same host, or the Docker
service URL when both apps share a Docker network.
