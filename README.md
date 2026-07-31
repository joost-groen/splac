# Splac — Shopware Product Listing Acceleration Copilot

Shopware 6.7 plugin that accelerates product listing: pick a template, drop manufacturer
PDF datasheets, fill in the few manual facts (price, stock, category, sales channels) and an
LLM generates everything else. Nothing goes live without review — the product is created
**inactive** only after you approve the generated fields.

## Features

- **Listing templates** with a per-language description builder. Text blocks support optional
  semantic headings, rich HTML editing (lists, links, formatting, and images), and dynamic
  values wrapped in double curly braces.
- **Localized administration** in English and German, including the dashboard, listing
  workflow, review screens, template editors, notifications, and plugin configuration.
- **Conditional description blocks** select an if/else branch from source-grounded product
  facts, with presence, text, and numeric comparison operators.
- **Two generation modes per text field** (SEO title/description, tags, keywords):
  *instruction* (the LLM writes the whole text) or *placeholder* (your text, only tokens are
  filled in).
- **Template-level AI guidance** with a shared target market and optional instructions on
  individual generated table properties. Each instruction is attached to its exact placeholder
  without being treated as factual product evidence.
- **PDF grounding**: the LLM only uses facts found in the uploaded datasheets. Fields
  without source information stay empty and are flagged in review.
- **Category templates**: create a translated category via LLM when no existing one fits,
  including configurable name, description, meta title, meta description, and search keywords.
- **Property matching** against existing property groups/options (no invented values),
  manufacturer match-or-create, EAN/MPN extraction, unique product numbers from a pattern,
  tags and search keywords, DE + EN translations.
- **Async pipeline** via the Shopware message queue with a live-monitoring dashboard
  (retry, cancel, delete).
- **Per-listing Anthropic controls** for optional extended reasoning (low, medium, or high)
  and asynchronous Message Batch processing. Reasoning blocks are kept separate from the
  final structured JSON, while batch polling yields the worker between status checks.
- **Anthropic prompt caching** keeps each listing's stable source-document context in a
  five-minute provider cache across classification, description, SEO, property, and category
  requests, reducing repeated input-token processing without changing the generated prompts.
- **Usage cost tracking** based on provider-reported regular input, prompt-cache write/read,
  and output token counts plus configurable base token and OCR page rates, with per-listing
  costs and 24-hour, 30-day, and all-time totals.
  Changing the configured currency starts a separate set of dashboard totals; historical
  ledger entries retain the currency and rates used when each request was made.
- **Review screen** with per-field editing, HTML preview, and per-field regeneration
  before the inactive product is created. Generated categories include placement, storefront
  content, and search-result previews and are created only after the combined review is approved.

## Supported LLM providers

Anthropic is the only officially supported provider. Select Anthropic and enter its API key
and model name under *Settings → System → Plugins → Splac*.

OpenAI, Google Gemini, and Mistral are available only through the opt-in **Extended Beta**
mode. These integrations are experimental, are not officially supported by Splac, and are
intentionally hidden from the normal plugin settings. Advanced testers can enable a provider
through Shopware's system configuration:

```bash
bin/console system:config:set Splac.config.extendedBeta true --json
bin/console system:config:set Splac.config.provider openai
bin/console system:config:set Splac.config.openaiApiKey '<api-key>'
bin/console system:config:set Splac.config.openaiModel 'gpt-4o'
```

Use `gemini` or `mistral` as the provider key and configure the corresponding API key and
model keys when testing those integrations. Running
`bin/console system:config:set Splac.config.extendedBeta false --json` immediately returns
Splac to Anthropic, regardless of a stored Extended Beta provider.

## PDF extraction

Splac sends each uploaded PDF directly to the selected LLM provider for an OCR/document
transcription pass before any product data is generated. Anthropic uses its native PDF input
support. In Extended Beta mode, OpenAI and Gemini use their native PDF input support, while
Mistral uses its Document AI OCR endpoint. The transcription is stored once and reused by
every generation step.

The **OCR mode** setting offers provider OCR with local fallback (default), provider OCR
only, or local extraction only. Local extraction uses Poppler's `pdftotext` for PDFs whose
embedded object structure is unsupported by the PHP parser, and can use `pdftoppm` plus
Tesseract (including English language data) for scanned pages or embedded fonts without a
usable Unicode map.

On Debian/Ubuntu:

```bash
apt-get install poppler-utils tesseract-ocr tesseract-ocr-eng
```

On Alpine Linux:

```bash
apk add --no-cache poppler-utils tesseract-ocr tesseract-ocr-data-eng
```

## Installation

Run Composer from the Shopware project root (the directory containing Shopware's main
`composer.json`), not from inside `custom/plugins/splac`.

### Install directly from GitHub

The package is not currently published on Packagist and the repository does not yet contain
a stable release tag. Register the GitHub repository as a Composer VCS source and install the
`main` development branch:

```bash
composer config repositories.splac vcs https://github.com/joost-groen/splac.git
composer require "joostgroen/splac:dev-main" -W

bin/console plugin:refresh
bin/console plugin:install --activate Splac
bin/console cache:clear
```

Composer stores the repository declaration in the Shopware project's root `composer.json`.
If the repository is private, the server also needs GitHub credentials with read access.

### Install a published release

Once the repository has a stable release tag (for example `v1.0.0`) and is registered on
Packagist, install it with a stable version constraint:

```bash
composer require joostgroen/splac:^1.0
bin/console plugin:refresh
bin/console plugin:install --activate Splac
bin/console cache:clear
```

The message queue must be consumed for extraction/generation to run:

```bash
bin/console messenger:consume async
```

## Usage

1. Create a listing template under *Catalogues → Splac Templates* (description HTML
   structure, feature toggles, field modes, defaults). Optionally add category templates.
2. Start a new listing under *Catalogues → Splac Dashboard → New listing*: pick the
   template, drop the manufacturer PDFs, enter price/tax/stock/category/sales channels.
3. Watch progress on the dashboard. When the process reaches *Ready for review*, open it,
   edit or regenerate individual fields, then *Approve & create product*.
4. The product is created inactive — activate it in the standard product module when ready.

## Development

`bin/console splac:e2e-test <pdf>` runs the whole backend pipeline (template, process,
upload, extraction, generation, product creation) against a local PDF without the admin UI.
If no API key is configured, the LLM step falls back to simulated output automatically, so
the rest of the pipeline can still be verified.
