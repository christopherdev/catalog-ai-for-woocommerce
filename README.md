# Catalog AI for WooCommerce

AI-powered product catalog image generation for WooCommerce using Google Vertex AI Imagen models.

## Features

- **Virtual Try-On** — Upload a person/model image and a garment product image. The AI generates the person wearing the garment.
- **Background Swap** — Provide a product image and a scene prompt. The AI replaces the background using Imagen 3 (`imagen-3.0-capability-001`).
- **Product Recontextualization** *(Currently Unavailable)* — Provide a product image and a text prompt describing a scene. The AI places the product in that scene.
- **Async Processing** — Jobs are queued via Action Scheduler and processed in the background.
- **Media Library Integration** — Generated images are saved to the WordPress Media Library and automatically assigned to products (gallery or featured image).
- **Cost Estimation** — Live cost estimates on the dashboard based on selected products and mode.
- **Usage Tracking** — Monthly usage breakdown by mode with estimated costs.
- **MCP Server** — Model Context Protocol support for external AI agent integration.
- **Webhook Receiver** — REST API endpoint for external pipeline callbacks.

## How It Works

### Virtual Try-On

The plugin uses Google's `virtual-try-on-001` model on Vertex AI. You provide two images:

1. **Person / Model Image** — A photo of a person (the model)
2. **Product Image** — The garment/clothing item (from your WooCommerce product)

The AI generates a new image of the person wearing the product.

| Person Image | Product Image | Result |
|:---:|:---:|:---:|
| ![Person](screenshots/person-image.png) | ![Product](screenshots/product-image.jpg) | ![Result](screenshots/try-on-result.png) |
| ![Person](screenshots/person-image-2.jpg) | ![Product](screenshots/product-image-2.webp) | ![Result](screenshots/try-on-result-3.png) |

### Background Swap

Uses Google's `imagen-3.0-capability-001` model with `EDIT_MODE_BGSWAP`. You provide:

1. **Product Image** — Your product photo
2. **Scene Prompt** — A text description of the desired background (e.g., "Modern kitchen counter with natural morning light")

The AI replaces the product's background with the described scene.

| Prompt | Result |
|:---:|:---:|
| *"product on a white table on a sunny beach near the shore"* | ![Result](screenshots/bgswap-result.png) |

### Product Recontextualization *(Currently Unavailable)*

Uses Google's `imagen-product-recontext-preview-06-30` model. This mode requires preview access which is currently closed. You provide:

1. **Product Image** — Your product photo
2. **Scene Prompt** — A text description of the desired scene

The AI generates the product placed in the described scene.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce 7.0+
- Google Cloud account with Vertex AI API enabled
- Service account with Vertex AI permissions

## Installation

1. Download or clone this repository into `wp-content/plugins/`
2. Activate the plugin in WordPress Admin
3. Go to **Catalog AI > Settings** and configure:
   - **GCP Project ID** — Your Google Cloud project ID
   - **GCP Location** — Vertex AI region (default: `us-central1`)
   - **Service Account Key** — Paste the full JSON key file contents

## Usage

1. Go to **Catalog AI > Dashboard**
2. Select a **Mode** (Virtual Try-On or Recontextualization)
3. For Try-On: select a **Person / Model Image** from the Media Library
4. For Recontextualization: enter a **Scene Prompt**
5. Select one or more **products** from the card grid
6. Choose where to assign the generated image: **Product Gallery** or **Featured Image**
7. Click **Generate Images**
8. The job is queued and processed in the background via Action Scheduler
9. Generated images appear in the **Media Library** and on the product

## API Pricing (Estimated)

| Mode | Cost per Image |
|------|---------------|
| Virtual Try-On | $0.05 |
| Background Swap | $0.04 |
| Product Recontextualization | $0.06 |

Costs are based on Google Cloud Vertex AI pricing and may change. The plugin tracks usage and provides estimates on the dashboard.

## REST API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/wp-json/catalog-ai/v1/generate` | Submit a single generation job |
| POST | `/wp-json/catalog-ai/v1/generate/batch` | Submit a batch of products |
| GET | `/wp-json/catalog-ai/v1/jobs/{job_id}` | Get job status |
| GET | `/wp-json/catalog-ai/v1/batches/{batch_id}` | Get batch status |
| GET | `/wp-json/catalog-ai/v1/products/{id}/images` | List generated images for a product |
| GET | `/wp-json/catalog-ai/v1/usage` | Usage statistics |
| GET | `/wp-json/catalog-ai/v1/estimate` | Cost estimate |
| GET | `/wp-json/catalog-ai/v1/status` | Plugin health check |

All endpoints require `manage_woocommerce` capability.

## MCP Server

The plugin exposes an MCP (Model Context Protocol) server for external AI agents:

- `GET /wp-json/catalog-ai/v1/mcp/tools` — Discover available tools
- `POST /wp-json/catalog-ai/v1/mcp/execute` — Execute a tool

Supports WordPress authentication or `X-MCP-API-Key` header.

## Debugging

Enable WordPress debug logging in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Logs are written to `wp-content/debug.log` with the `[Catalog AI]` prefix.

## Disclaimer

**This plugin connects to Google Cloud Vertex AI, a paid third-party service.** Every image generation request incurs costs on your Google Cloud account. You are solely responsible for:

- Any charges incurred through the Google Cloud Vertex AI API.
- Managing your own API credentials, billing limits, and usage quotas.
- Reviewing and complying with [Google Cloud's Terms of Service](https://cloud.google.com/terms) and [Acceptable Use Policy](https://cloud.google.com/terms/aup).
- Ensuring generated images comply with applicable laws and regulations in your jurisdiction.

The author of this plugin provides it **"as-is" without warranty of any kind**, express or implied. **The author assumes no liability** for any costs, damages, or losses arising from the use of this plugin, including but not limited to unexpected API charges, service outages, or generated content. Use at your own risk.

It is strongly recommended to set up [billing alerts](https://cloud.google.com/billing/docs/how-to/budgets) and API quota limits in your Google Cloud Console before using this plugin in production.

## License

GPL-2.0-or-later
