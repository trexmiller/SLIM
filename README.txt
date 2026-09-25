SLIM v0.22.3

Sharing, Logging, Ingesting and Managing (SLIM) is a lightweight, self-hosted
environmental monitoring data-management and visualization application built
for conventional PHP/MySQL web-server infrastructure.

INSTALLATION
------------
1. Create a MySQL database and database user using your hosting control panel or
   institutional database tools. Grant the user the permissions needed to create
   and manage the SLIM database tables. Keep the database name, username,
   password, and host information available for SLIM setup.

2. Upload or extract the SLIM application into the desired web-accessible
   directory, for example move the slim folder into public_html so all SLIM files are located in:

       /home/youraccount/public_html/slim

3. Before browsing to SLIM, create a private configuration directory outside the
   web-accessible public_html directory, one directory up from it will work. For example:

       /home/youraccount/slim_config

   The directory must already exist and be writable by PHP.

4. Edit config/EXTERNAL_CONFIG_PATH. Replace the example
   path:

       /home/USERNAME/slim_config

   with the absolute path to the private configuration directory created in the
   previous step. You can download this file and edit it in a text editor, 
   then upload it back to the /config directory and overwrite the existing file.

5. In a web browser, navigate to the SLIM administration area at www.your_domain_name/admin/. 
   On a new installation, SLIM will present the ownership page for creation of the 
   first administrator account. Create the administrator credentials immediately after installation.

6. After ownership is established, complete the web-based SLIM setup and enter
   the MySQL database connection information. SLIM will create its required
   configuration files and subdirectories in the private configuration directory.

7. Use the administration interface to configure the site and create or import
   monitoring stations. Station-specific API keys and ingestion settings are
   created and managed from within SLIM.

A fresh installation will not permit administrator creation while configuration
would be stored inside public_html. Routine SLIM installation and configuration
do not require modification of the application source code.

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
Operational configuration is stored outside the public web directory.
EXTERNAL_CONFIG_PATH identifies the private configuration location. Station
definitions and public/health display configurations are stored separately from
application code.

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
