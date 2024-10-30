=== Bizuno Migrate ===
Contributors: phreesoft
Tags: bizuno, erp, accounting, bookkeeping, crm, quickbooks, phreebooks, inventory
Requires at least: 4.5
Tested up to: 5.5.3
Stable tag: 1.0.1
License: GPL3
License URI: https://www.gnu.org/licenses/gpl.html

Bizuno Migrate, by PhreeSoft, provides the tools and assistance needed to migrate from other accounting applications to Bizuno. Once the migration has completed, the plugin should be disabled and deleted.

== Description ==

Bizuno Migrate, by PhreeSoft, provides the tools and assistance needed to migrate from other accounting applications to Bizuno. Once the migration has completed, the plugin should be disabled and deleted.

* Convert from PhreeBooks up to Release 3.7
* Convert from PhreeBooks5
* Convert from PhreeSoft Cloud hosted Bizuno pre 6.0
* Convert from QuickBooks
* Assistance with 'Line in the Sand' approach to migrate from any other accounting application

== Installation ==

Follow the standard installation procedure for all WordPress apps. Once activated additional tabs will be added to the Bizuno Accounting -> Tools -> Import/Export screen.

= Minimum Requirements =

* Bizuno Accounting plugin
* PHP   version 5.4 or greater (PHP 5.6 or greater is recommended, tested with PHP 7.4)
* MySQL version 5.0 or greater (MySQL 5.6 or greater is recommended, tested with MySQL 5.6 & 5.7)
* WordPress 4.4+

This section describes how to install the plugin and get it working.

e.g.

1. Upload the plugin files to the `/wp-content/plugins/bizuno-migrate` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress.

== Upgrade Notice ==

= All Releases =
No User action is needed. The WordPress upgrade operation will take care of everything.

== Frequently Asked Questions ==

= How long does the PhreeSoft Cloud/PhreeBooks5 process take? =

* Since the database is native to Bizuno for WordPress, the process typically can be completed in a couple of hours. The two steps to migrate include changing the prefix on the database tables then performing a restore and moving over the data files.

== Screenshots ==

1. QuickBooks Conversion
1. PhreeBook5/PhreeSoft Cloud Migration
1. Line in the Sand Conversion

== Changelog ==

= 1.0.1 =
2020-12-08 - Add new migration for data files. Work on install/remove action
= 1.0.0 =
2020-11-12 - Initial Release

== About PhreeSoft ==

PhreeSoft was the original developer of the PhreeBooks open source ERP/Accounting application back in 2007. PhreeBooks development was replaced with Bizuno, the next generation ERP/Accounting application to provide faster, highly customizable, and a more user friendly experience.
