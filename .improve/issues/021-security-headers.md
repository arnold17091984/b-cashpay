---
id: "021"
title: "Security headers missing (HSTS, CSP, Referrer-Policy, X-Frame-Options)"
severity: medium
category: security
status: resolved
file: "api/public/index.php"
line: 28-52
round_found: 2
round_resolved: 2
---

## Description
No route sent HSTS, CSP, or Referrer-Policy. The `/p/{token}` URL (a capability) could leak via Referer; the payment and admin pages had no clickjacking protection beyond PaymentPageController's one-off `X-Frame-Options: SAMEORIGIN`.

## Fix
Added a centralised block at the top of api/public/index.php:

- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- `Content-Security-Policy` with self-only defaults + the external font CDNs the payment page actually uses

Verified via `curl -sSI https://b-pay.ink/` — all five headers present.
