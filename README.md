# slim
A Low-Complexity Web Application for Sharing, Logging, Ingestion and Management (SLIM) of Real-Time Environmental Monitoring Data

SLIM v0.22.2

SLIM is a lightweight, self-hosted environmental monitoring data-management and visualization application built for conventional PHP/MySQL web-server infrastructure.

PUBLIC INTERFACES
-----------------
/                 Public station map and directory.
/about/            Configurable public About page.
/contact/          Configurable public Contact page.
/dashboard/        Public station dashboards.
/ingest/           HTTPS data-ingestion API protected by station API keys.

ADMINISTRATION
--------------
/admin/            Protected administration area.

Administrative modules provide account management, appearance and branding,
public and health dashboard configuration, database settings, export, CSV
import, setup, site content, station management, the station directory, recent
station data viewing, synthetic test data, and outbound webhook management.

CONFIGURATION
-------------
Operational configuration is stored outside the public web directory when an
external configuration path is configured. EXTERNAL_CONFIG_PATH identifies the
configuration location. Station definitions and public/health display
configurations are stored separately from application code.

TIME STANDARD
-------------
SLIM stores observation and receipt times in UTC. observed_at represents when
the observation was made; received_at represents when SLIM received it. Local
time zones are used for interpretation and display rather than altering the
canonical stored UTC time.

DATA INGESTION
--------------
JSON HTTPS POST is the preferred ingestion method. Optional form POST and GET
ingestion can be enabled for compatible dataloggers. Each station has its own
API key. Incoming variables are mapped and type-validated according to the
station configuration before parameterized database insertion.

DERIVED VARIABLES
-----------------
Station Manager can create stored numeric variables from safe arithmetic
combinations of existing numeric station variables and constants. Calculations
support addition, subtraction, multiplication, and division, with up to four
calculation steps and four different source variables. Calculations are applied
to new ingestion and CSV imports and may optionally be backfilled across
existing observations. Missing inputs and division by a zero-valued variable
produce NULL rather than an invalid numeric result.

SECURITY AND RESOURCE PROTECTION
--------------------------------
SLIM combines authenticated administration, anti-CSRF tokens, station API keys,
HTTPS ingestion, browser security headers, prepared SQL statements, input
validation, per-IP request limits, ingestion throttling, failed-authentication
throttling, and dashboard/import resource limits. Hosting-provider security and
resource controls remain an additional independent layer.

DASHBOARDS
----------
Public and administrator-only station-health dashboards use shared rendering
and configuration engines while retaining separate display configurations.
Dashboards can include time series, gauges, maps, images, directional displays,
profiles, text, and date-range controls. Dashboard configurations can be copied
between stations and edited independently.

OUTBOUND DATA
-------------
Observations may be queued for asynchronous outbound webhook delivery. Webhook
delivery is separated from ingestion so a slow or unavailable destination does
not prevent the original observation from being stored.

REQUIREMENTS
------------
A PHP/MySQL web environment with the PHP extensions required by the installer
checks. SLIM is intended to operate on standard LAMP infrastructure, including
compatible shared-hosting services and institutionally managed Linux servers.
