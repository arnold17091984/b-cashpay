---
id: "007"
title: "Matcher silently substituted time() when transaction_date was unparseable"
severity: high
category: security
status: resolved
file: "api/src/Controllers/ScraperWebhookController.php"
line: 296-305
round_found: 1
round_resolved: 1
---
## Description
The "candidate.created_at <= deposit date" guard (introduced to prevent stale-deposit→new-link attribution, same bug class as the LionExpressPay incident) used `strtotime(...) ?: time()`. A deposit with a junk date bypassed the guard entirely.

## Fix
Deposits with unparseable dates are now persisted as unmatched and returned early. No fallback to now().
