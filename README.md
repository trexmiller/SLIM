# SLIM — Simple Logging, Ingesting and Managing

**A lightweight, self-hosted web application for environmental monitoring data.**

SLIM helps environmental monitoring programs collect, manage, quality-check, visualize, and share observations from real-time observing stations. It is designed for small and medium-sized monitoring networks and does not require coding or IT expertise to install and use.

SLIM runs on conventional PHP and MySQL/MariaDB web hosting, including compatible commercial shared-hosting services. Users can install and administer the application using familiar web-hosting tools such as cPanel and a browser, without deploying containers or maintaining a dedicated application server. It was designed to be used with low cost shared web hosting services (see below for options).

## Features

- **Automated data ingestion:** Receive authenticated observations from monitoring instruments and telemetry services. New stations can automatically discover measurement fields from their first valid observation.
- **Station management:** Configure multiple stations, measurement variables, units, time zones, and access settings through a browser-based administrative interface.
- **Quality control and data management:** Configure quality-control checks, review observations, import historical data, and export data for further analysis.
- **Derived variables:** Create calculated measurements and common unit conversions.
- **Interactive dashboards:** Configure time-series plots, gauges, directional displays, spatial profiles, maps, images, and text for individual monitoring stations.
- **Data sharing:** Publish station dashboards and configure optional outbound webhooks.
- **Backups:** Preserve station observations and configurations during supported backup operations, and maintain current and previous dashboard configurations as private JSON backups.

## Hosting Requirements

**You do not need to be a computer scientist or have experience managing web servers to install SLIM.** Commercial shared-hosting services provide nearly everything SLIM needs in a ready-to-use hosting account. Signing up for a compatible hosting plan can be as simple as selecting a plan and creating an account. The hosting provider takes care of installing and maintaining the underlying web-server software.

SLIM was specifically designed to work with these inexpensive shared-hosting services. Users only need to learn a few basic tasks, such as navigating cPanel, uploading files, and creating a database. No programming, command-line experience, Docker, or dedicated server is required.

For those interested in the technical details, SLIM requires PHP 8.1 or newer, a MySQL-compatible database, HTTPS, and a private configuration directory. These capabilities are commonly included with compatible shared-hosting plans. Optional outbound webhook delivery also requires scheduled task support.

The **SLIM Installation and User Manual** provides step-by-step instructions, including hosting recommendations and guidance for users who have never configured a website or database.

## Documentation

The **SLIM Installation and User Manual** is the primary reference for installing SLIM, creating stations, connecting instruments, managing observations, configuring quality control, and building dashboards.

## Documentation

Detailed instructions for installing, configuring, and operating SLIM are provided in the installation manual.

**[SLIM Installation and User Manual](docs/INSTALLATION.md)**

## Demonstration

A separate read-only demonstration site allows visitors to explore SLIM's administration and visualization features without changing station configurations or submitting observations.

- **Read-only demo:** https://y5nkdzo5.a2hosted.com/slim_demo/demo.php
- **Live site:** https://y5nkdzo5.a2hosted.com/slim_demo

## Release

**Initial public release: v0.3.32**

## Citation

If SLIM contributes to published research, please cite the associated software manuscript.

## License

SLIM is licensed under the GNU GENERAL PUBLIC LICENSE version 3, 29 June 2007
