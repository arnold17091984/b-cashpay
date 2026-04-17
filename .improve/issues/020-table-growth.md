---
id: "020"
title: "scraper_tasks and telegram_updates grow unboundedly"
severity: medium
category: quality
status: open
file: ""
line: 
round_found: 2
---

## Description
Neither `scraper_tasks` (completed/failed rows) nor `telegram_updates` has a retention policy. At ~100 template children/day plus Telegram group noise, these tables will grow into the tens-of-thousands of rows per year.

## Fix
Deferred to a later round. Needed:
- Weekly `DELETE` jobs keyed on `updated_at < NOW() - INTERVAL 90 DAY` and `processed_at < NOW() - INTERVAL 30 DAY`.
- Matching range indexes (`idx_updated_at`, `idx_processed_at`) to keep the deletes O(log n).
- Either a cron entry on the VPS or a Laravel-style scheduled task.
