=== Ask AI for WooCommerce ===
Contributors: zgrkaralar
Tags: ai, chatgpt, woocommerce, product, claude
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

"Is this product right for you?" buttons that open ChatGPT, Claude, Google AI Mode, Grok or Perplexity with a ready-made prompt about the product.

== Description ==

Shoppers already ask AI before they buy. Ask AI for WooCommerce adds an "Is this product right for you?" box to your product pages. One click opens the shopper's favourite AI assistant with a prompt about the product already filled in: its name, price, specs and a link to the product page. The AI then explains what the product is good for, its strengths and limitations, and suggests better-fitting alternatives from your store.

No API keys, no chatbot to host, no usage costs: the conversation happens in the AI assistant the shopper already uses.

**Supported AI assistants**

* ChatGPT
* Claude
* Google AI Mode
* Grok
* Perplexity

**Built for large catalogs**

You don't write a prompt per product. Write one template with placeholders, and every product fills it in automatically:

* A global template for the whole store
* A template per product category, inherited by sub-categories
* An optional custom prompt for individual products

**Features**

* Product placeholders: name, URL, SKU, price, stock, brand, categories, description, all attributes, single attributes, custom fields (ACF) and any taxonomy
* Lines with empty values are removed automatically ("Brand: {brand}" disappears for products without a brand)
* Live prompt preview with test links in the admin
* Heading and subheading per store, category or product
* Automatic placement on the product page, as a product tab, or anywhere with the `[aipc_buttons]` shortcode (Elementor, block themes, custom layouts)
* Products list column showing where each product's prompt comes from, plus bulk actions
* Works with the WooCommerce CSV importer, the REST API and WP-CLI
* Click tracking for Google Tag Manager and GA4
* Translation-ready, with WPML and Polylang support
* Compatible with WooCommerce High-Performance Order Storage (HPOS)

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload, or install it from the plugin directory.
2. Activate it. WooCommerce must be active.
3. Go to WooCommerce → Ask AI to choose the position, the AI assistants and the prompt template.
4. Optional: add a template per category under Products → Categories → Edit.

== Frequently Asked Questions ==

= Do I need an OpenAI, Anthropic or other API key? =

No. The buttons open the AI assistant's own website with the prompt pre-filled. Nothing is sent to an AI service from your store, and there are no usage costs.

= How are prompts chosen? =

1. The custom prompt on the product (Edit product → "Ask AI" box)
2. The template on the product's category. Sub-categories inherit it, and the Yoast / Rank Math primary category wins.
3. The global template (WooCommerce → Ask AI)

= Which placeholders can I use? =

`{product_name}` `{product_url}` `{product_id}` `{sku}` `{price}` `{regular_price}` `{stock_status}` `{short_description}` `{description}` `{category}` `{categories}` `{brand}` `{tags}` `{weight}` `{dimensions}` `{site_name}` `{site_url}` `{language}`

* `{attributes}`: every visible product attribute, one "Name: value" per line
* `{attr:pa_cpu}`: a single attribute by slug
* `{meta:_my_field}`: any custom field or ACF field
* `{tax:product_brand}`: the terms of any taxonomy

Developers can add their own with the `aipc_placeholders` or `aipc_placeholder_value` filters.

= Where do the product specs come from? =

From the standard WooCommerce product attributes (Products → Attributes, or the Attributes tab when editing a product). Only attributes marked "Visible on the product page" are included.

= Where does the box appear? =

Choose a position in the settings: after the Add to cart button, at the end of the product summary, below the summary, or as a product tab. Or choose "Manual only" and use the shortcode:

`[aipc_buttons]`
`[aipc_buttons id="123" providers="chatgpt,claude" display="icons_labels" heading="…"]`

= How do I manage 1000+ products? =

* Write one template per category instead of per product.
* Use bulk actions in the products list to hide or show the box, or remove custom prompts.
* Use the WooCommerce CSV importer/exporter with the columns `Meta: _aipc_prompt`, `Meta: _aipc_disabled` (value `yes`) and `Meta: _aipc_heading`.
* Use WP-CLI: `wp aipc preview 123 --links`, `wp aipc set --category=laptops --file=prompt.txt`, `wp aipc clear --all`, `wp aipc stats`.

= Why Google AI Mode and not Gemini? =

Gemini has no supported way to pre-fill a prompt from a link. Google AI Mode does.

= What if an AI assistant changes its link format? =

Each assistant's link is editable in the settings. `{prompt}` is replaced with the encoded prompt.

Defaults:

* ChatGPT: `https://chatgpt.com/?q={prompt}`
* Claude: `https://claude.ai/new?q={prompt}`
* Google AI Mode: `https://www.google.com/search?udm=50&q={prompt}`
* Grok: `https://grok.com/?q={prompt}`
* Perplexity: `https://www.perplexity.ai/search/new?q={prompt}`

= Can I track clicks? =

Yes. Each click pushes an `aipc_click` event (with `aipc_provider` and `aipc_product_id`) to the Google Tag Manager dataLayer, and sends a GA4 event through gtag when it is present.

= Is it translation-ready? =

Yes. The text domain is `ask-ai-for-woocommerce`, and `languages/ask-ai-for-woocommerce.pot` contains all PHP and JavaScript strings. Translate with Loco Translate or Poedit, or on translate.wordpress.org.

* The default heading, subheading and prompt template follow the site language until you change them.
* WPML and Polylang can translate the settings texts, category fields and product fields (via wpml-config.xml).
* `{language}` gives the store language in its own name ("English", "български", "Deutsch"), so it reads naturally in a translated template.

== External services ==

This plugin links to third-party AI assistants. It does not call any external API from your server, and it loads no external scripts, fonts or images.

When a shopper clicks one of the buttons, their browser opens the selected AI assistant in a new tab. The prompt is passed in the link (the `q` URL parameter). The prompt contains the product information you configured in the template, by default: product name, product page URL, SKU, brand, category, price, visible attributes, store name and store language. No customer, order or personal data is included. Nothing is sent until the shopper clicks a button, and only to the assistant they clicked.

Each assistant can be turned off in WooCommerce → Ask AI.

* **ChatGPT** (OpenAI), `https://chatgpt.com/`: [Terms of use](https://openai.com/policies/terms-of-use/), [Privacy policy](https://openai.com/policies/privacy-policy/)
* **Claude** (Anthropic), `https://claude.ai/`: [Consumer terms](https://www.anthropic.com/legal/consumer-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* **Google AI Mode** (Google), `https://www.google.com/search`: [Terms of service](https://policies.google.com/terms), [Privacy policy](https://policies.google.com/privacy)
* **Grok** (xAI), `https://grok.com/`: [Terms of service](https://x.ai/legal/terms-of-service), [Privacy policy](https://x.ai/legal/privacy-policy)
* **Perplexity** (Perplexity AI), `https://www.perplexity.ai/`: [Terms of service](https://www.perplexity.ai/hub/legal/terms-of-service), [Privacy policy](https://www.perplexity.ai/hub/legal/privacy-policy)

The optional click tracking only pushes an `aipc_click` event to a Google Tag Manager dataLayer or gtag.js that your site already loads. The plugin itself does not load Google Analytics or Tag Manager.

== Screenshots ==

1. The Ask AI box on a product page: one click opens ChatGPT, Claude, Google AI Mode, Grok or Perplexity with the product prompt filled in.
2. Settings (WooCommerce → Ask AI): placement, button style, heading and the global prompt template.
3. Click-to-insert placeholders, live prompt preview, and the AI assistants with their order and link format.
4. The Ask AI box on the product edit screen: shows which template the product uses, with an optional custom prompt and heading.

== Changelog ==

= 1.0.0 =
* First release.
