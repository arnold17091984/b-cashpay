---
id: "008"
title: "CORS Access-Control-Allow-Origin: * reflected on every route"
severity: high
category: security
status: resolved
file: "api/public/index.php"
line: 33
round_found: 1
round_resolved: 1
---
## Description
Wildcard origin was sent unconditionally, widening the cross-origin attack surface against authenticated API routes.

## Fix
Allowlist of `b-pay.ink` and `admin.b-pay.ink`; other origins get no CORS header at all. Verified: evil.example.com gets no header; admin.b-pay.ink is reflected.
