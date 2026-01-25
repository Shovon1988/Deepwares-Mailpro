=== Deepwares MailPro ===
Contributors: Shovon_NZ
Donate link: https://pixelart.net.nz
Tags: email marketing, newsletter, smtp, campaigns, subscribers
Requires at least: 6.0
Tested up to: 6.4
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern email marketing and newsletter plugin for WordPress with campaign management, queue-based sending, and real-time analytics.

== Description ==

Deepwares MailPro is a lightweight but powerful email marketing plugin for WordPress.

It allows you to:

* Create and manage email campaigns
* Design emails using the Gutenberg editor
* Send campaigns immediately or schedule them
* Process sending in background queues (non-blocking)
* Track opens and clicks
* Manage subscribers and lists
* Use SMTP for reliable delivery
* Stay compliant with unsubscribe handling

The plugin is built with performance, scalability, and simplicity in mind.

== Features ==

* Gutenberg-based email editor
* Campaign scheduling and instant sending
* Background email queue processor
* Real-time open & click tracking
* Subscriber list management
* SMTP support
* Unsubscribe compliance built-in
* Clean and modern admin UI

== Installation ==

1. Upload the `deepwares-mailpro` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Configure SMTP settings
4. Create a campaign and send your first email

== Frequently Asked Questions ==

= Does this plugin use WP Cron? =
Yes. Email sending is handled via WP-Cron in small batches.

= Does it support SMTP? =
Yes. You can configure SMTP credentials in the plugin settings.

= Is unsubscribe handled automatically? =
Yes. All emails include a compliant unsubscribe link.

== Screenshots ==

1. Campaigns dashboard
2. Email editor
3. Subscribers management
4. Campaign analytics

== Changelog ==

= 1.1.0 =
* Fixed email rendering issues
* Added proper unsubscribe footer handling
* Improved campaign send workflow
* Fixed queue progress display
* Added real-time open and click stats

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.1.0 =
Important bug fixes and improved campaign statistics.

