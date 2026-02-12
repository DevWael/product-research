# Product Research

> AI-powered competitive intelligence for WooCommerce products.

![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-8892BF)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588A)

## Description

Product Research brings AI-powered competitive intelligence directly into your WooCommerce product editor. Analyze competitor pricing, variations, and features without leaving your WordPress dashboard. The plugin orchestrates web search, content extraction, and multi-provider AI analysis through a streamlined 4-step workflow — giving you actionable competitive insights in minutes.

## ✨ Features

- **Multi-Step AI Analysis** — 4-node workflow pipeline: Search → Extract → Analyze → Report
- **Multi-Provider AI Support** — Z.AI (OpenAI-compatible), Anthropic Claude, Google Gemini
- **Web Search & Extraction** — Tavily API for finding and extracting competitor data
- **Search Preview** — Review and filter search results before expensive analysis
- **AI-Powered Recommendations** — Strategic pricing and competitive recommendations
- **Summary Dashboard** — Price range, competitor count, key findings at a glance
- **Competitor Cards** — Expandable cards with pricing, variations, availability, features
- **Product List Badges** — Competitor count and price-position indicator in product list
- **Export** — CSV and PDF export of analysis reports
- **Session Persistence** — Resume analysis after page refresh or navigation
- **Encrypted API Keys** — AES-256-CBC encryption for all API credentials
- **Configurable Limits** — Cooldown, daily credit budget, capability-based access

## 📋 Requirements

| Requirement  | Version |
| ------------ | ------- |
| PHP          | 8.1+    |
| WordPress    | 6.0+    |
| WooCommerce  | 7.0+    |
| Composer     | 2.x     |

## 🚀 Installation

1. Clone the repository into your `wp-content/plugins/` directory:
   ```bash
   cd wp-content/plugins
   git clone <repo-url> product-research
   ```
2. Install dependencies:
   ```bash
   cd product-research
   composer install
   ```
3. Activate the plugin via **Plugins → Installed Plugins** in your WordPress admin.
4. Configure your API keys — see [Configuration](#️-configuration) below.

## ⚙️ Configuration

Navigate to **WooCommerce → Product Research** in your WordPress admin. The settings page has three sections:

- **API Configuration** — Select your AI provider (Z.AI, Anthropic Claude, or Google Gemini) and enter the corresponding API key. A Tavily API key is always required for web search and content extraction.
- **Search & Analysis** — Configure search depth, result limits, token budget, cache TTL, and domain exclusions to fine-tune the analysis pipeline.
- **Security & Limits** — Set the required WordPress capability for running analyses, configure cooldown periods between analyses, and set a daily credit budget.

> All API keys are encrypted at rest using AES-256-CBC.

## 🔄 How It Works

1. Open a WooCommerce product edit page.
2. Click **"Analyze Competitors"** in the Product Research metabox.
3. Review search results — deselect irrelevant URLs before proceeding.
4. The plugin extracts and sanitizes competitor page content.
5. AI analyzes each competitor for pricing, variations, and features.
6. View the summary dashboard with expandable competitor cards.
7. Export results as CSV or PDF.

## 🏗️ Architecture

Product Research is built on [Neuron AI v2](https://github.com/neuron-ai/neuron-ai) with a modular architecture using PSR-4 autoloading and a lightweight PSR-11 dependency injection container.

The analysis pipeline flows through a 4-node workflow:

```mermaid
flowchart LR
    A[StartEvent] --> B[SearchNode]
    B --> C[SearchCompletedEvent]
    C --> D[User Preview]
    D --> E[ExtractNode]
    E --> F[ExtractionCompletedEvent]
    F --> G[AnalyzeNode]
    G --> H[AnalysisCompletedEvent]
    H --> I[ReportNode]
    I --> J[StopEvent]
```

### Module Overview

| Module     | Description                                         |
| ---------- | --------------------------------------------------- |
| `AI`       | Agents, workflow nodes, structured output schemas    |
| `API`      | Tavily HTTP client, content sanitizer                |
| `Admin`    | Metabox, settings page, product list columns         |
| `Ajax`     | Research workflow AJAX endpoints                     |
| `Cache`    | WordPress Transients-based caching                   |
| `Export`   | CSV and PDF report export                            |
| `Report`   | Custom post type and repository                      |
| `Security` | API key encryption, sanitized logging                |

**Tech stack:** PHP 8.1+, Neuron AI v2, PSR-4 autoloading, lightweight PSR-11 DI container (12 services).

## 🤝 Contributing

1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/your-feature`).
3. Make your changes following these standards:
   - [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
   - Strict typing: `declare(strict_types=1);` in every PHP file
   - PSR-12 code style
   - Descriptive commit messages
4. Test your changes before submitting.
5. Open a Pull Request against the `main` branch.

## 📄 License

This project is licensed under the [GPL-2.0-or-later](LICENSE) license.

## 👤 Author

**Ahmad Wael**
Website: [bbioon.com](https://www.bbioon.com)
