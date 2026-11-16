# Ask AI for WooCommerce

"Is this product right for you?" buttons for WooCommerce product pages. One click opens ChatGPT, Claude, Google AI Mode, Grok or Perplexity with a ready-made prompt about the product.

- No API keys, no hosted chatbot, no usage costs
- Prompt templates with product placeholders: global, per category (inherited), per product
- Automatic placement, product tab, or the `[aipc_buttons]` shortcode
- Bulk actions, CSV import/export, REST API and WP-CLI (`wp aipc`)
- GTM / GA4 click event (`aipc_click`), WPML / Polylang ready, HPOS compatible

Requires WordPress 6.5+, PHP 7.4+ and WooCommerce 7.0+.

The WordPress.org listing text lives in [`readme.txt`](readme.txt).

## Development

```bash
npx pressship verify .   # readme validation + Plugin Check
npx pressship demo .     # local WordPress Playground
npx pressship pack .     # build the release zip
wp i18n make-pot . languages/ask-ai-for-woocommerce.pot
```

## License

GPL-2.0-or-later
