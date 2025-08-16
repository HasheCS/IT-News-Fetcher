=== IT News Fetcher ===
Contributors: hashe, mamoon
Tags: rss, news, aggregator, openai, bulk, seo, rank math
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 4.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetch and republish tech news into a "Tech News" CPT with per-feed checklist (check/fetch), batch fetching, live logs with Stop, OpenAI expansion (1200–1500 words), Bulk Rewrite, and Rank Math SEO generation/apply. Modular and WordPress.org-ready.

== Description ==
- Per-feed checklist UI: **Check** (new item count) and **Fetch** per row, plus **Fetch Selected** for batches.
- Live log with **Stop** button (cancel mid-run).
- Optional OpenAI rewrite to full **1200–1500 words** HTML.
- Bulk Rewrite tab to rework selected posts.
- Rank Math SEO tab to list **word count, focus keyword, meta description**, and generate/apply SEO fields.
- Cron runner + transient-based logs. Uses WordPress Settings API, nonces, and capability checks.

== Installation ==
1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate **IT News Fetcher**.
3. Go to **IT News Fetcher → Settings** and paste your feed URLs (comma- or newline-separated).
4. (Optional) Set OpenAI key and enable expansion.

== Frequently Asked Questions ==
= Does this require OpenAI? =
No. All features run without OpenAI; expansion and SEO generation are optional.

== Changelog ==
= 4.0.0 =
* Initial modular release: per-file architecture, feed checklist with check/fetch, batch runs, live logs, Stop, Bulk Rewrite, Rank Math SEO.

== Privacy ==
This plugin can call the OpenAI API if enabled by the site admin. No data is sent unless the admin enables the feature and triggers actions that use it.

