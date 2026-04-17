---
id: "001"
title: "Template /p/{token}/submit had no rate limit — unbounded child spawn"
severity: critical
category: security
status: resolved
file: "api/src/Controllers/PaymentPageController.php"
line: 110-181
round_found: 1
round_resolved: 1
---
## Description
Public POST /p/{token}/submit had no rate limit before dispatch. A template link could be spammed to exhaust the 7-digit reference-number space and DB write capacity.

## Fix
Applied two IP-keyed windows in `PaymentPageController::submit()`:
- per IP + token: 5 submits/min
- per IP across all templates: 30 submits/5min
Verified: 6th submit within 60s returns 429.
