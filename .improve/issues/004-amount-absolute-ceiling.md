---
id: "004"
title: "Customer-submitted amount had no absolute ceiling independent of per-link max"
severity: high
category: bug
status: resolved
file: "api/src/Controllers/PaymentPageController.php"
line: 144
round_found: 1
round_resolved: 1
---
## Description
An operator storing max_amount larger than DECIMAL(12,0) could allow a submit that subsequently truncates or throws at INSERT.

## Fix
Unconditional server-side ceiling of 9,999,999 JPY in `submit()` regardless of row.max_amount.
