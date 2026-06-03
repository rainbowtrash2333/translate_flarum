# Flarum Test Translator

Local FastAPI service used by the Flarum translation extension.

## Run

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

## API

- `GET /health`
- `POST /translate`
- `POST /translate/batch`

The test translator returns `tran_{lang}_{source}` and stores results in
`data/translations.sqlite3`.
