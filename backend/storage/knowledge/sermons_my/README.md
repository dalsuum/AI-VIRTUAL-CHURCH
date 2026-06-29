# Burmese Sermon Dataset Builder

Crawls Burmese-language sermon articles and converts them into a clean AI
training dataset (plain-text, instruction, and QA formats).

## Sources

- https://www.myanmar3am.com/p/plain-revelation-pr.html
- https://minlwin.wordpress.com/

## Install

```bash
pip install -r requirements.txt
```

## Run

```bash
python run.py
```

That's it — `run.py` discovers category pages, pagination, and internal sermon
links recursively; downloads each page once; cleans the HTML (drops nav, ads,
comments, share buttons) while preserving Myanmar Unicode and Bible verses; then
writes everything below.

Useful flags:

- `python run.py --max-pages 20` — crawl a small sample first.
- `python run.py --reset` — discard saved progress and start fresh.

## Resume & checkpoints

Progress is saved to `crawl_state.json` (visited URLs, content hashes for
dedupe, parsed sermons, and the pending queue). A checkpoint is written every
**20 pages**, so an interrupted run resumes from where it stopped — just run
`python run.py` again.

## Politeness

- Honours each site's `robots.txt`.
- 2-second delay between requests.
- Up to 3 retries with linear backoff on failures.
- Identifies itself with a descriptive `User-Agent`.

## Output layout

```
raw/<id>.json        one cleaned record per sermon (full fields)
metadata.csv         tabular index (id, title, author, date, url, refs, tags…)
dataset/
  train.jsonl        plain text records for continued pretraining
  instruction.jsonl  {instruction, input=title, output=sermon}
  qa.jsonl           auto-generated QA pairs (headings + Bible references)
```

### `train.jsonl` record

```json
{
  "id": "", "title": "", "author": "", "url": "",
  "series": "", "date": "", "language": "my", "text": "..."
}
```

### `instruction.jsonl` record

```json
{ "instruction": "Explain this sermon", "input": "<title>", "output": "<sermon>" }
```

### `qa.jsonl` record

```json
{ "id": "", "url": "", "question": "...", "answer": "..." }
```

## Train/validation/test split

The JSONL files are unsplit. To produce a 90/5/5 split before training:

```bash
shuf dataset/train.jsonl > /tmp/s.jsonl
n=$(wc -l < /tmp/s.jsonl); tr=$((n*90/100)); va=$((n*5/100))
head -n $tr /tmp/s.jsonl > dataset/train.split.jsonl
sed -n "$((tr+1)),$((tr+va))p" /tmp/s.jsonl > dataset/validation.jsonl
sed -n "$((tr+va+1)),${n}p" /tmp/s.jsonl > dataset/test.jsonl
```

## ⚠️ Licensing

You are responsible for confirming you have permission to use and redistribute
the crawled sermon content before training or publicly sharing any model or
dataset derived from it. This tool only automates collection; it grants no
rights to the source material.
