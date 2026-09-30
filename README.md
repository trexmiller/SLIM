SLIM v0.22.15

Sharing, Logging, Ingesting and Managing (SLIM) is a lightweight, self-hosted
environmental monitoring data-management and visualization application built
for conventional PHP/MySQL web-server infrastructure.

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

INSTALLATION
------------
1. Create a MySQL database and database user using your hosting control panel or
   institutional database tools. Grant the user the permissions needed to create
   and manage the SLIM database tables. Keep the database name, username,
   password, and host information available for SLIM setup.

2. Upload or extract the SLIM application into the desired web-accessible
   directory, for example:

       /home/youraccount/public_html/slim

3. Before browsing to SLIM, create a private configuration directory outside the
   web-accessible public_html directory. For example:

       /home/youraccount/slim_config

   The directory must already exist and be writable by PHP.

4. Edit config/EXTERNAL_CONFIG_PATH in the SLIM application. Replace the example
   path:

       /home/USERNAME/slim_config

   with the absolute path to the private configuration directory created in the
   previous step. Save the file.

5. Browse to the SLIM administration area at /admin/. On a new installation,
   SLIM will present the ownership page for creation of the first administrator
   account. Create the administrator credentials immediately after installation.

6. After ownership is established, complete the web-based SLIM setup and enter
   the MySQL database connection information. SLIM will create its required
   configuration files and subdirectories in the private configuration directory.

7. Use the administration interface to configure the site and create or import
   monitoring stations. Station-specific API keys and ingestion settings are
   created and managed from within SLIM.

A fresh installation will not permit administrator creation while configuration
would be stored inside public_html. Routine SLIM installation and configuration
do not require modification of the application source code.

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


TIME-SERIES RANGE
-----------------
The administrator can set the maximum calendar-day range available through dashboard date-range selectors in Admin > Setup. The default is 30 days and the configurable software maximum is 3650 days. Large ranges remain subject to dashboard row and hosting-resource limits.

## Time-series range and public downsampling

SLIM treats public and administrator-only health time-series displays differently.
Public dashboards default to a maximum selectable range of 30 days. An administrator
may increase that limit under **Admin → Site → Public Dashboard Protection** and may
also set the maximum number of points returned for a public time-series plot.

When a public request contains more observations than the configured plotting limit,
SLIM systematically selects raw observations at approximately uniform row intervals
across the complete requested period. Values are not averaged, aggregated, or otherwise
transformed. This preserves coverage of the requested time span while limiting database,
network, and browser load. Because systematic subsampling can omit brief events between
selected observations, it is intended for public visualization rather than full-resolution
analysis. Stored observations and CSV exports are never changed by dashboard downsampling.

Private health dashboards are intended for administrative inspection and do not apply the
public row limit or downsampling. They return all observations in the selected interval.
The administrator can set the maximum health-dashboard date range under **Admin → Setup →
System Defaults**. Large ranges of high-frequency observations may require substantial
query, transfer, and browser-rendering time.


## Custom Text formatting

Custom Text dashboard displays include a lightweight WYSIWYG toolbar. Administrators can apply Normal, Large, or Extra large text to selected text and use bold, italic, underline, bulleted or numbered lists, links, subscript, and superscript. Text sizes are predefined by SLIM rather than arbitrary font sizes so dashboards remain consistent and responsive. Saved formatted content is sanitized server-side using an allowlist before it is displayed. Existing plain-text Custom Text displays remain supported.


## Public typography

SLIM v0.22.7 increases the base typography on public-facing pages by approximately 20–25% to improve readability on desktop, mobile, and public displays. The change applies to the public station directory/home page, public dashboards, About, and Contact pages. Headings and already-prominent display elements use a moderated scale rather than being enlarged uniformly. Administrative pages and private health dashboards retain their denser typography. Custom Text Normal, Large, and Extra large sizes remain relative to the public dashboard text scale.


## Accessibility

SLIM v0.22.11 establishes WCAG 2.1 Level AA as the accessibility target for the application. Public pages provide a keyboard-accessible skip link, visible focus indicators, responsive reflow support, scalable text, meaningful image alternative text, and reduced-motion behavior when requested by the operating system. Administrative help dialogs expose dialog semantics, move keyboard focus into the dialog when opened, return focus to the triggering control when closed, close with Escape, and keep Tab navigation within the open dialog. Standard HTML form controls and descriptive labels are retained wherever practical.

SLIM's controlled Custom Text formatting intentionally limits arbitrary styling that could undermine readability. Administrators remain responsible for the accessibility of content they supply, including meaningful image alternative text, descriptive link text, and avoiding reliance on color alone to communicate scientific meaning. Dashboard line colors should therefore be supplemented by labels, line styles, symbols, or other identifying information when color distinctions are important.

WCAG conformance cannot be established by source-code changes or automated checks alone. Deployments should be manually evaluated with keyboard-only navigation, 200% text zoom, 320 CSS-pixel reflow, contrast testing for administrator-selected theme colors, and representative screen-reader testing before making a formal WCAG 2.1 AA conformance claim.


### v0.22.11 gauge readability
Gauge supporting information—including units, minimum/maximum scale labels, recent-valid observation notes, and configured-range annotations—uses larger, high-contrast white text to improve readability on dark dashboard backgrounds.


### v0.22.11 Custom Text size selection
Custom Text size choices are mutually exclusive. Changing selected text among Normal, Large, and Extra large now replaces the previous SLIM size formatting rather than nesting size spans, preventing repeated size changes from progressively enlarging text.


### v0.22.11 Custom Text size correction
Custom Text Normal, Large, and Extra large sizes now use fixed, non-compounding sizes in both the editor and public renderer. This prevents legacy or browser-created nested size spans from multiplying relative `em` sizes. The editor also preserves the selected text range when the size selector receives focus, making size changes reliably replace the visual size in either direction.


### v0.22.15 time-series display text
Removed the redundant “Last N hours of available data” annotation from time-series plot headers. The plotted date/time axis provides the displayed time range. Downsampling and administrative full-resolution notices are retained when applicable.


### v0.22.15 polar display observation range
Polar displays now show the earliest and latest `observed_at` timestamps for the valid paired observations actually used to construct the display, together with the number of observations included. Polar selection and timestamp reporting use `observed_at` rather than `received_at`.


### v0.22.15 display readability and date-range guidance
- Removed the redundant “Directional frequency by magnitude class” subtitle from polar displays.
- Increased the spatial-profile legend unit label to 14 px with higher contrast so it is comparable in size to plotted numeric labels.
- Clarified the public date-range selector: viewers may select up to the administrator-configured number of calendar days, and large datasets are automatically downsampled across the selected period to limit the number of displayed points. Health dashboards continue to use all observations.


### v0.22.32 date-range guidance and Custom Text compatibility
- Moved the date-range/downsampling guidance directly beneath the date controls so it is clearly visible to viewers.
- Added compatibility handling for Custom Text formatting saved as escaped HTML by earlier editor revisions.
- The Custom Text sanitizer now collapses simple legacy nested SLIM size wrappers, retaining the innermost explicit size choice.


### v0.22.32 version footer restoration
Restored the SLIM version number in the shared page footer. `VERSION.txt` again uses the `SLIM vX.Y.Z` format expected by `slim_version()`, so public dashboards, public pages, setup pages, and administrative pages using the shared footer display the current version.


### v0.22.32 skip-link visibility
Corrected the accessibility “Skip to main content” control so it remains visually hidden during normal mouse/touch use and becomes visible only when it receives keyboard focus. The link remains available to keyboard and screen-reader users and continues to target the page's main-content landmark.


### v0.22.32 site-title configuration cleanup
- Renamed “Public site title” to “Site Title” in Public Site Configuration.
- Removed the redundant “System Name” field from System Defaults.
- Existing `dashboard_name` values remain preserved in `config/system.php` for backward compatibility when System Defaults are saved; the setting is no longer exposed in the administrator interface.


### v0.22.32 System Defaults cleanup
- Removed the unused “Organization” field from the System Defaults administrator interface.
- Existing `organization` values remain preserved in `config/system.php` for backward compatibility when System Defaults are saved.
- System Defaults now focuses on settings that affect application behavior rather than unused identity metadata.


### v0.22.32 image display presentation
Image-display titles remain required for identifying displays in the Dashboard Builder but are no longer rendered above images on dashboards. Optional image captions remain visible when configured, and image alt text continues to be used for accessibility.


### v0.22.32 station dashboard heading
Station Name is now rendered as the page-level H1 at the top of each station dashboard, including private station-health dashboards, regardless of the configured logo/branding mode. The existing Station Code remains the internal/URL identifier and is still shown in the dashboard metadata. Browser page titles continue to use the station name.


### v0.22.32 Dashboard Setup station selector
Corrected station switching in Dashboard Setup. Changing the Station selector now navigates directly to the selected station's setup URL and reloads that station's independent dashboard configuration. A normal “Select Station” submit button is also provided as a non-JavaScript fallback. The same selector behavior is used for public-dashboard and station-health-dashboard setup.


### v0.22.32 spatial/profile color scaling
Spatial/profile displays now support optional Color scale minimum and Color scale maximum settings. A supplied bound is fixed; a blank bound is calculated from the full available dataset across all variables configured in that profile. This keeps a given value mapped to the same color over time instead of rescaling colors from only the current profile. The profile legend now shows the effective maximum and minimum values. These settings affect color mapping only, not the spatial/profile axis.


### v0.22.32 station map presentation
Station Map display titles remain required for identifying map displays in Dashboard Setup but are no longer rendered on dashboards. The station latitude and longitude remain visible above the map because they provide useful location information.


### v0.22.32 dashboard information controls and setup cleanup
- Added per-station, per-dashboard checkboxes for showing station code, station coordinates, last received time, and local time at the top of dashboards. Existing dashboards default to showing all four items.
- Station Name remains the page-level heading and is not optional.
- Removed the redundant “Select Station” button from Dashboard Setup; changing the station dropdown continues to switch stations immediately.
- Updated the Display Title help text to explain that titles identify/manage displays within SLIM and are shown publicly only for certain display types.


### v0.22.32 Dashboard Setup correction
Corrected two regressions introduced with the Dashboard Information controls. The station selector now uses the same proven automatic form-submit behavior as Station Management and no longer depends on an inline JavaScript listener. The Dashboard Information form now uses SLIM's existing dashboard CSRF token field rather than an unavailable helper function, allowing the four visibility checkboxes and save button to render and save normally.


### v0.22.32 per-variable station quality control
SLIM now supports optional quality control for individual numeric station variables. Administrators can enable QC independently for each variable, define optional minimum and maximum acceptable values, and define instrument diagnostic/error values with descriptive meanings. For QC-enabled variables, SLIM preserves the value exactly as normalized from the incoming payload in a companion `<field>_raw` column. Values matching a configured diagnostic code or falling outside the configured acceptable range are stored as `NULL` in the normal station field, while `<field>_qc` records `valid`, `missing`, `diagnostic`, `out_of_range`, or `legacy_unchecked`. Diagnostic codes are evaluated before range limits. Public dashboards and normal exports continue to use the normal station field, and outbound webhook payloads use the quality-controlled value, so rejected sensor values are forwarded as JSON `null` rather than as environmental observations. When QC companion columns are first added, SLIM creates its normal station CSV/configuration backup before altering the table; existing values are copied into the raw column and marked `legacy_unchecked` rather than being retroactively rejected.


### v0.22.32 public-dashboard accessibility improvements
Public scientific graphics now provide nonvisual accessible names and descriptions without adding visible dashboard text. Station maps are exposed as labeled regions identifying the station location. Spatial/profile SVG plots use an accessible title and dynamically generated description of the profile, while recent directional SVG displays describe the latest magnitude and bearing and note that older observations are shown with reduced emphasis. These descriptions are available to assistive technology but do not add visual clutter to the dashboard.

Very small public-facing dashboard text was also increased. Meaningful labels and metadata that had been rendered at approximately 10–12 px—including profile statistic labels, directional annotations, display notes, date-range guidance, local-time information, gauge titles, and related secondary text—now use larger sizes, generally 13–14 px or greater.


### v0.22.32 Dygraphs accessibility
Time-series displays now provide an accessible HTML wrapper around each Dygraphs visualization. The visible display title labels the chart region, while a visually hidden description identifies the plotted series, observation period, axes when configured, and any systematic downsampling. A supplementary visually hidden HTML table summarizes each plotted series with its units, plotted observation count, minimum, maximum, and latest plotted value. The Dygraphs canvas itself is hidden from assistive technology to avoid exposing an unstructured graphical control in addition to the accessible equivalent. These additions do not add visible text or change the visual appearance of public dashboards.


### v0.22.32 accessibility rendering correction
Corrected the Dygraphs accessibility description code to use SLIM's existing `$displayTimezone` object. v0.22.31 incorrectly referenced an undefined `$tz` variable while rendering time-series accessibility metadata. On pages containing a time-series display this could stop PHP rendering before the dashboard initialization JavaScript was emitted, leaving Leaflet maps and JavaScript/SVG visualizations blank. The accessibility wrapper and supplementary time-series summary remain enabled.
