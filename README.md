# Splac — Shopware Product Listing Acceleration Copilot

Shopware 6.7 plugin that accelerates product listing: pick a template, drop manufacturer
PDF datasheets, fill in the few manual facts (price, stock, category, sales channels) and an
LLM generates everything else. Nothing goes live without review — the product is created
**inactive** only after you approve the generated fields.

## Features

- **Listing templates** with a per-language HTML description structure. Wrap placeholder
  names in double curly braces; static blocks (legal disclaimers, shipping info, …) pass
  through untouched.
- **Two generation modes per text field** (SEO title/description, tags, keywords):
  *instruction* (the LLM writes the whole text) or *placeholder* (your text, only tokens are
  filled in).
- **PDF grounding**: the LLM only uses facts found in the uploaded datasheets. Fields
  without source information stay empty and are flagged in review.
- **Category templates**: create a new category via LLM when no existing one fits.
- **Property matching** against existing property groups/options (no invented values),
  manufacturer match-or-create, EAN/MPN extraction, unique product numbers from a pattern,
  tags and search keywords, DE + EN translations.
- **Async pipeline** via the Shopware message queue with a live-monitoring dashboard
  (retry, cancel, delete).
- **Review screen** with per-field editing, HTML preview, and per-field regeneration
  before the inactive product is created.

## Supported LLM providers

OpenAI, Anthropic, Gemini, and Mistral. Select the provider and enter the API key and model
name under *Settings → System → Plugins → Splac*.

## Installation

```bash
composer require joostgroen/splac
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
