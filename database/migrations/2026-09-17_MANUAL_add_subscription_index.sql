-- MANUAL STEP ONLY — do not run automatically. This is DDL against a ~89M-row live table.
--
-- `subscription` has no index beyond its PRIMARY KEY(id) — not even on subscriber_msisdn or
-- date. Every subscriber lookup in the new Subscriptions screen (app/public/index.php,
-- ?page=subscriptions) therefore requires an exact MSISDN or Transaction ID and still ends up
-- doing a full table scan to find matching rows, because there is nothing to seek to. This adds
-- the index that makes that actually fast.
--
-- Read before running:
--   - This table has ~89 million rows. Adding an index takes real time and I/O. On MySQL 8 /
--     InnoDB this can run as an online DDL (ALGORITHM=INPLACE, LOCK=NONE) which does not block
--     reads/writes for the duration, but it is still substantial background work — run it during
--     a low-traffic window and monitor replication lag / disk space (InnoDB needs roughly the
--     size of the new index again in temp space while building it).
--   - Verify with `pt-online-schema-change --dry-run` (Percona Toolkit) first if you have it
--     available, as an extra safety check before running directly.

ALTER TABLE HeraProduction.subscription
  ADD INDEX idx_subscription_msisdn_date (subscriber_msisdn, date),
  ADD INDEX idx_subscription_txn (transaction_id),
  ALGORITHM=INPLACE, LOCK=NONE;

-- Repeat against HeraTesting once confirmed safe (that copy is smaller/lower-risk to test on first):
-- ALTER TABLE HeraTesting.subscription
--   ADD INDEX idx_subscription_msisdn_date (subscriber_msisdn, date),
--   ADD INDEX idx_subscription_txn (transaction_id),
--   ALGORITHM=INPLACE, LOCK=NONE;
