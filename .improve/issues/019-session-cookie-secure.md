---
id: "019"
title: "Admin session cookie missing Secure flag"
severity: high
category: security
status: resolved
file: "admin/public/index.php"
line: 25-28
round_found: 2
round_resolved: 2
---

## Description
`session_start()` passed `cookie_httponly` and `cookie_samesite` but not `cookie_secure`. The admin session cookie could travel over plain HTTP in health checks, staging, or during an HTTP→HTTPS redirect.

## Fix
Added `'cookie_secure' => true`. Verified on b-pay.ink: `Set-Cookie: bcashpay_admin_session=…; secure; HttpOnly; SameSite=Lax`.
