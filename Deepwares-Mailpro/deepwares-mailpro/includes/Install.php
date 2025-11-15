<?php
namespace Deepwares\MailPro;

defined('ABSPATH') || exit;

class Install {

    /**
     * Run on plugin activation.
     * Creates or updates all required DB tables via dbDelta().
     */
    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        // --------------------------------------------------
        // Subscribers
        // --------------------------------------------------
        dbDelta("CREATE TABLE {$p}dwmp_subscribers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            name VARCHAR(255) NULL,
            status ENUM('active','unsubscribed','bounced') DEFAULT 'active',
            unsubscribe_token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY status (status)
        ) $charset;");

        // --------------------------------------------------
        // Lists
        // --------------------------------------------------
        dbDelta("CREATE TABLE {$p}dwmp_lists (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL
        ) $charset;");

        // --------------------------------------------------
        // Subscriber ↔ List mapping
        // --------------------------------------------------
        dbDelta("CREATE TABLE {$p}dwmp_subscriber_lists (
            subscriber_id BIGINT UNSIGNED NOT NULL,
            list_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (subscriber_id, list_id),
            KEY list_id (list_id)
        ) $charset;");

        // --------------------------------------------------
        // Campaigns
        // --------------------------------------------------
        //
        // NOTE the extra columns:
        //   - template_id : dwmp_email post ID used as the base template
        //   - list_id     : primary subscriber list for this campaign
        //   - sent_count  : cached count of successfully sent emails
        //   - open_rate   : cached open rate percentage
        //   - click_rate  : cached click rate percentage
        //
        // dbDelta() will add these to existing installs if missing.
        //
        dbDelta("CREATE TABLE {$p}dwmp_campaigns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            description TEXT NULL,
            content LONGTEXT NULL,
            template_id BIGINT UNSIGNED NULL,
            list_id BIGINT UNSIGNED NULL,
            status ENUM('draft','scheduled','sending','sent') DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            sent_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            open_rate FLOAT NOT NULL DEFAULT 0,
            click_rate FLOAT NOT NULL DEFAULT 0,
            KEY status (status),
            KEY template_id (template_id),
            KEY list_id (list_id)
        ) $charset;");

        // --------------------------------------------------
        // Campaign ↔ List mapping (multi-list support)
        // --------------------------------------------------
        dbDelta("CREATE TABLE {$p}dwmp_campaign_lists (
            campaign_id BIGINT UNSIGNED NOT NULL,
            list_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (campaign_id, list_id),
            KEY list_id (list_id)
        ) $charset;");

        // --------------------------------------------------
        // Send queue
        // --------------------------------------------------
        dbDelta("CREATE TABLE {$p}dwmp_send_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            status ENUM('queued','sent','failed') DEFAULT 'queued',
            scheduled_for DATETIME NOT NULL,
            sent_at DATETIME NULL,
            error TEXT NULL,
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id),
            KEY status (status)
        ) $charset;");

        // --------------------------------------------------
        // Events (delivered, open, click, unsubscribe, bounce)
        // --------------------------------------------------
        dbDelta("CREATE TABLE {$p}dwmp_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            type ENUM('delivered','open','click','unsubscribe','bounce') NOT NULL,
            meta TEXT NULL,
            created_at DATETIME NOT NULL,
            KEY campaign_id (campaign_id),
            KEY subscriber_id (subscriber_id),
            KEY type (type)
        ) $charset;");
    }
}
