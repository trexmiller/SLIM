## v0.3.28 — Ingestion/webhook failure isolation

- Commit valid observations before rendering or queuing webhooks. Webhook failures are logged and do not fail ingestion.
- Provision the webhook queue table when saving/updating the database connection in Setup, not during ingest. Existing installs can re-save database settings in Setup; visiting Webhook Deliveries also provisions the table.
- A queue failure after observation commit may leave a missing webhook delivery; it is not automatically retried.

# SLIM v0.3.27 — Station API key replacement

This maintenance release adds a **Replace Station API Key** control under **Station Management → Station Details**. Administrators can replace a lost station ingestion key without deleting the station or its historical observations. The old key is immediately invalidated; update the transmitting device with the newly displayed key. The new key is shown once.

**Changes from v0.3.26**
- Add administrator-controlled station API key regeneration, with CSRF protection and station-code confirmation.
- Keep the replacement control in Station Details rather than repeating it in other station-management forms.
- No database schema changes are required.

**Deployment:** Back up the existing installation and configuration. For an upgrade from v0.3.26, the application change is in `admin/station/manage.php`; `VERSION.txt` and this README have also been updated. Keep your existing configuration and database. Verify key replacement on a test station before relying on it for a field device.

**Demonstration:** This is the standard, unrestricted application release. Public read-only demo authentication and demo-only restrictions are maintained separately and are **not included**.

---

# SLIM v0.3.26 — Independent cron webhook dispatcher

**Architecture change:** ingestion and outbound webhook HTTP delivery are separated. One optional cPanel cron job per installation dispatches all station webhook jobs. A saved observation and its queued webhook job are committed together; no outbound HTTP call is made during ingestion. Cron dispatch is CLI-only, serialized with a MySQL advisory lock, limited to 8 jobs per run, and makes one automatic attempt per delivery. Manual retries are queued for cron rather than sent during an admin request.

Install cron from **Admin → Webhook Deliveries → Configure and verify the webhook scheduler**. Use the correct PHP CLI executable for your hosting platform. The setup page shows the installation-specific absolute script path and a last-run heartbeat. Review old pending jobs before enabling cron, as they will be eligible for dispatch. Without cron, ingestion and storage continue but webhooks stay pending.

**Deployment caution:** This is a significant change and has not been tested against a live hosting platform. Back up the database and configuration and test on a separate installation first. Ingest response speed and queued-job throughput need validation on target hosts. Do not assume exactly-once delivery; a receiver can accept data even when its acknowledgment times out.

---

# SLIM v0.3.20 — Configurable webhook timeout

- Station Webhook Setup now has an HTTP timeout setting (1–30 seconds, default 15).
- The timeout is used for both live synchronous deliveries and test deliveries.
- One attempt, no automatic retries, and no cron are unchanged.
- Previously configured stations default to 15 seconds until saved with another timeout.
- A timeout may occur after a receiving service has accepted an observation; avoid blind manual retries.

# SLIM v0.3.20 — Simplified webhook delivery

This first-release baseline supports **synchronous webhook delivery only**.
Each enabled station webhook is attempted **once** after the observation is stored,
with a **3-second HTTP timeout** and **no automatic retries**. A failed webhook
does not change a successfully stored observation into an ingest error.

Queued delivery, cron configuration, and scheduler installation are not part of
this release. Any existing station configuration that specifies `delivery_mode=queued`
is treated as synchronous; saving its webhook settings removes that legacy option.

Webhook Deliveries retains delivery history, a manual one-attempt Retry for failed
records, and an explicit option to close old pending records **without sending**.
Previously scheduled cron jobs should be removed from the hosting control panel;
the retained `webhook/dispatch.php` file is inert to prevent legacy cron jobs
from sending data after upgrading.

No database schema changes are required. Back up your installation before updating.
Test a station observation and confirm one delivery attempt in the history.

The full ZIP is based on v0.3.17, not the experimental v0.3.18 scheduler.


### v0.3.29
- Fix station deletion cleanup: remove public and health dashboard configuration files and their backups, plus legacy flat-path dashboard configs, for either station deletion mode.


## v0.3.31 station setup improvements

- Failed station creation preserves all submitted station metadata and measurement rows for correction.
- Client-side duplicate and invalid incoming-field checks supplement PHP validation.
- Station Details shows the installation-specific ingestion endpoint with copy support and authentication guidance.

## v0.3.30 maintenance changes
- Revokes administrator sessions on account deletion, password changes, or recreation. All existing sessions must log in again.
- Uses shared QC normalization for CSV imports and API ingestion, including diagnostic/raw/QC columns and 255-character text limits.
- CSV naive timestamps now use the station timezone.
- Webhook pending queue bounded to 1000 jobs and 7 days; dispatcher prunes automatically and processes up to 50 jobs per run with a 45-second soft deadline. Jobs dropped when full are logged.
- See RELEASE_TEST_PLAN.md before deployment.
