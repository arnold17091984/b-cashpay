#!/bin/bash
# Expire pending payment_links whose expires_at has already passed.
# Templates are excluded by design — they re-issue child links on demand
# and never expire themselves.  Credentials come from the env provided by
# systemd EnvironmentFile=/opt/bpay/api/.env.
set -eu

exec mysql -u"${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" -e \
  "UPDATE payment_links
      SET status = 'expired', updated_at = NOW()
    WHERE status = 'pending'
      AND link_type != 'template'
      AND expires_at < NOW()"
