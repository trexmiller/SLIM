# SLIM — Simple Logging, Ingesting and Managing

**A lightweight, self-hosted web application for environmental monitoring data.**

SLIM helps research laboratories, environmental agencies, nonprofit organizations, and other monitoring programs collect, manage, quality-check, visualize, and share observations from remote instruments. It is designed for small and medium-sized monitoring networks whose users may not have experience in programming or server administration.

SLIM runs on conventional PHP and MySQL/MariaDB web hosting, including compatible commercial shared-hosting services. Users can install and administer the application using familiar web-hosting tools such as cPanel and a browser, without deploying containers or maintaining a dedicated application server.

## Features

- **Automated data ingestion:** Receive authenticated observations from monitoring instruments and telemetry services. New stations can automatically discover measurement fields from their first valid observation.
- **Station management:** Configure multiple stations, measurement variables, units, time zones, and access settings through a browser-based administrative interface.
- **Quality control and data management:** Configure quality-control checks, review observations, import historical data, and export data for further analysis.
- **Derived variables:** Create calculated measurements and common unit conversions.
- **Interactive dashboards:** Configure time-series plots, gauges, directional displays, spatial profiles, maps, images, and text for individual monitoring stations.
- **Data sharing:** Publish station dashboards and configure optional outbound webhooks.
- **Backups:** Preserve station observations and configurations during supported backup operations, and maintain current and previous dashboard configurations as private JSON backups.

## Hosting Requirements

SLIM requires a web server supporting **PHP 8.1 or newer** and a **MySQL-compatible database** with InnoDB support. The server must support HTTPS, required PHP extensions, and a writable private configuration directory outside the public web root. A command-line PHP environment and scheduled cron task are needed only if outbound webhook dispatch is enabled.

See the **SLIM Installation and User Manual** for the complete requirements, hosting recommendations, installation procedure, and operating instructions. **Please follow the manual rather than relying on this README for installation.**

## Documentation

The **SLIM Installation and User Manual** is the primary reference for installing SLIM, creating stations, connecting instruments, managing observations, configuring quality control, and building dashboards.

The manual will be included or linked from this repository at public release.

## Demonstration

A separate read-only demonstration site allows visitors to explore SLIM's administration and visualization features without changing station configurations or submitting observations.

*Demonstration URL to be added at release.*

## Release

**Initial public release: v0.3.32**

## Citation

If SLIM contributes to published research, please cite the associated software manuscript once its bibliographic details are available.

## License

License information will be provided with the public release.
