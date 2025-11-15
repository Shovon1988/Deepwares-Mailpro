<?php
namespace Deepwares\MailPro;

defined( 'ABSPATH' ) || exit;

class Tracking {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'handle' ] );
    }

    public static function handle() {
        if ( ! isset( $_GET['dwmp_track'] ) ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $type          = sanitize_text_field( wp_unslash( $_GET['dwmp_track'] ) );
        $campaign_id   = isset( $_GET['c'] ) ? intval( $_GET['c'] ) : 0;
        $subscriber_id = isset( $_GET['s'] ) ? intval( $_GET['s'] ) : 0;

        // Security settings: allow disabling open/click tracking for privacy.
        $security         = get_option( 'dwmp_security', array() );
        $disable_tracking = ! empty( $security['disable_tracking'] );

        switch ( $type ) {

            case 'open':
                // If tracking is disabled, just return a transparent pixel without logging.
                if ( ! $disable_tracking ) {
                    $wpdb->insert(
                        "{$p}dwmp_events",
                        array(
                            'campaign_id'   => $campaign_id,
                            'subscriber_id' => $subscriber_id,
                            'type'          => 'open',
                            'created_at'    => current_time( 'mysql' ),
                        )
                    );
                }

                // Return 1x1 transparent gif
                header( 'Content-Type: image/gif' );
                echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
                exit;

            case 'click':
                $url = isset( $_GET['u'] ) ? esc_url_raw( base64_decode( wp_unslash( $_GET['u'] ) ) ) : home_url();

                if ( ! $disable_tracking ) {
                    $wpdb->insert(
                        "{$p}dwmp_events",
                        array(
                            'campaign_id'   => $campaign_id,
                            'subscriber_id' => $subscriber_id,
                            'type'          => 'click',
                            'meta'          => maybe_serialize( array( 'url' => $url ) ),
                            'created_at'    => current_time( 'mysql' ),
                        )
                    );
                }

                wp_redirect( $url );
                exit;

            case 'unsubscribe':
                $token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';

                // Verify token matches subscriber
                $subscriber = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$p}dwmp_subscribers WHERE id=%d AND unsubscribe_token=%s",
                        $subscriber_id,
                        $token
                    )
                );

                if ( $subscriber ) {
                    // Update status
                    $wpdb->update(
                        "{$p}dwmp_subscribers",
                        array(
                            'status'     => 'unsubscribed',
                            'updated_at' => current_time( 'mysql' ),
                        ),
                        array( 'id' => $subscriber_id )
                    );

                    // Log event
                    $wpdb->insert(
                        "{$p}dwmp_events",
                        array(
                            'campaign_id'   => $campaign_id,
                            'subscriber_id' => $subscriber_id,
                            'type'          => 'unsubscribe',
                            'created_at'    => current_time( 'mysql' ),
                        )
                    );

                    wp_die(
                        '<h2>You have been unsubscribed.</h2><p>You will no longer receive emails from this list.</p>',
                        'Unsubscribed'
                    );
                } else {
                    wp_die( '<h2>Invalid unsubscribe link.</h2>', 'Error' );
                }
                exit;

            case 'bounce':
                // For future: handle bounce webhooks
                $wpdb->insert(
                    "{$p}dwmp_events",
                    array(
                        'campaign_id'   => $campaign_id,
                        'subscriber_id' => $subscriber_id,
                        'type'          => 'bounce',
                        'created_at'    => current_time( 'mysql' ),
                    )
                );

                // Optionally alert on high bounce rate
                self::maybe_notify_high_bounce( $campaign_id );
                exit;
        }
    }

    /**
     * If high-bounce notifications are enabled and a campaign’s bounce rate
     * crosses the configured threshold, send a one-time alert email.
     */
    private static function maybe_notify_high_bounce( $campaign_id ) {
        if ( ! $campaign_id ) {
            return;
        }

        $notifications = get_option( 'dwmp_notifications', array() );
        $notifications = wp_parse_args(
            $notifications,
            array(
                'admin_email'          => get_option( 'admin_email' ),
                'notify_high_bounce'   => 0,
                'bounce_threshold'     => 5,
            )
        );

        if ( empty( $notifications['notify_high_bounce'] ) || empty( $notifications['admin_email'] ) ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        // Delivered & bounces for this campaign
        $sent    = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}dwmp_events WHERE campaign_id=%d AND type='delivered'",
                $campaign_id
            )
        );
        $bounces = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}dwmp_events WHERE campaign_id=%d AND type='bounce'",
                $campaign_id
            )
        );

        if ( $sent <= 0 || $bounces <= 0 ) {
            return;
        }

        $rate = ( $bounces / $sent ) * 100;

        if ( $rate < (int) $notifications['bounce_threshold'] ) {
            return;
        }

        // Avoid spamming: only alert once per campaign.
        $notified = get_option( 'dwmp_notified_high_bounce_campaigns', array() );
        if ( in_array( $campaign_id, $notified, true ) ) {
            return;
        }

        $subject = sprintf(
            __( 'MailPro: High bounce rate on campaign #%d', 'deepwares-mailpro' ),
            $campaign_id
        );

        $body = sprintf(
            "<p>%s</p><p>%s</p>",
            esc_html__( 'Your campaign has exceeded the configured bounce threshold.', 'deepwares-mailpro' ),
            esc_html( sprintf( 'Delivered: %d, Bounces: %d (%.1f%%)', $sent, $bounces, $rate ) )
        );

        // Use MailSender if available, otherwise fall back to wp_mail.
        if ( class_exists( __NAMESPACE__ . '\\MailSender' ) ) {
            MailSender::send( $notifications['admin_email'], $subject, $body );
        } else {
            wp_mail( $notifications['admin_email'], $subject, wp_strip_all_tags( $body ) );
        }

        $notified[] = $campaign_id;
        update_option( 'dwmp_notified_high_bounce_campaigns', array_unique( $notified ) );
    }
}
