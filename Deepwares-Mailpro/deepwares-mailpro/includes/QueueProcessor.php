<?php
namespace Deepwares\MailPro;

defined( 'ABSPATH' ) || exit;

class QueueProcessor {

    /**
     * Number of queued emails to process per cron run.
     */
    const BATCH_SIZE = 50;

    /**
     * Hook everything up.
     * Called from deepwares-mailpro.php on plugins_loaded.
     */
    public static function init() {
        // Add a custom every-minute schedule if not already present.
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );

        // Hook the processor.
        add_action( 'dwmp_process_queue', [ __CLASS__, 'process' ] );

        // Schedule if not already scheduled.
        if ( ! wp_next_scheduled( 'dwmp_process_queue' ) ) {
            wp_schedule_event( time() + 60, 'dwmp_every_minute', 'dwmp_process_queue' );
        }
    }

    /**
     * Custom cron schedule: every minute.
     */
    public static function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['dwmp_every_minute'] ) ) {
            $schedules['dwmp_every_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every Minute (MailPro Queue)', 'deepwares-mailpro' ),
            ];
        }
        return $schedules;
    }

    /**
     * Process a batch of queued messages.
     *
     * - Picks queued rows with scheduled_for <= now
     * - Sends via MailSender
     * - Marks send_queue rows as sent/failed
     * - Inserts "delivered" events on success
     * - If a campaign has no queued rows left, marks that campaign as "sent"
     * - On repeated failures, optionally emails an alert based on dwmp_notifications
     */
    public static function process() {
        global $wpdb;

        $p             = $wpdb->prefix;
        $queue_table   = "{$p}dwmp_send_queue";
        $camp_table    = "{$p}dwmp_campaigns";
        $subs_table    = "{$p}dwmp_subscribers";
        $events_table  = "{$p}dwmp_events";

        $now = current_time( 'mysql' );

        // Safety: ensure queue table exists.
        $queue_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table )
        );
        if ( $queue_exists !== $queue_table ) {
            return;
        }

        // Grab a batch of queued jobs.
        $batch = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$queue_table}
                 WHERE status = 'queued'
                   AND scheduled_for <= %s
                 ORDER BY id ASC
                 LIMIT %d",
                $now,
                self::BATCH_SIZE
            )
        );

        if ( empty( $batch ) ) {
            return;
        }

        $touched_campaigns = [];

        foreach ( $batch as $job ) {
            $job_id        = (int) $job->id;
            $campaign_id   = (int) $job->campaign_id;
            $subscriber_id = (int) $job->subscriber_id;

            // Load campaign + subscriber.
            $campaign = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$camp_table} WHERE id = %d",
                    $campaign_id
                )
            );
            $subscriber = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$subs_table} WHERE id = %d",
                    $subscriber_id
                )
            );

            if ( ! $campaign || ! $subscriber ) {
                // Mark as failed if we cannot resolve campaign or subscriber.
                $wpdb->update(
                    $queue_table,
                    [
                        'status'  => 'failed',
                        'sent_at' => $now,
                        'error'   => 'Missing campaign or subscriber',
                    ],
                    [ 'id' => $job_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                $touched_campaigns[ $campaign_id ] = true;
                continue;
            }

            // Build subject & body.
            $subject = isset( $campaign->subject ) ? $campaign->subject : '';
            if ( ! $subject ) {
                $subject = get_bloginfo( 'name' );
            }

            $html = self::build_email_html( $campaign, $subscriber );

            // Attempt send.
            $to      = $subscriber->email;
            $sent_ok = false;

            if ( class_exists( __NAMESPACE__ . '\\MailSender' ) ) {
                $sent_ok = MailSender::send( $to, $subject, $html );
            }

            if ( $sent_ok ) {
                // Mark queue row as sent.
                $wpdb->update(
                    $queue_table,
                    [
                        'status'  => 'sent',
                        'sent_at' => $now,
                        'error'   => null,
                    ],
                    [ 'id' => $job_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                // Insert "delivered" event.
                $events_exists = $wpdb->get_var(
                    $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table )
                );
                if ( $events_exists === $events_table ) {
                    $wpdb->insert(
                        $events_table,
                        [
                            'campaign_id'   => $campaign_id,
                            'subscriber_id' => $subscriber_id,
                            'type'          => 'delivered',
                            'meta'          => null,
                            'created_at'    => $now,
                        ],
                        [ '%d', '%d', '%s', '%s', '%s' ]
                    );
                }
            } else {
                // Capture a more descriptive error if MailSender exposes one.
                $error_message = 'Send failed';
                if ( class_exists( __NAMESPACE__ . '\\MailSender' ) && property_exists( MailSender::class, 'last_error' ) && MailSender::$last_error ) {
                    $error_message = MailSender::$last_error;
                }

                // Mark queue row as failed.
                $wpdb->update(
                    $queue_table,
                    [
                        'status'  => 'failed',
                        'sent_at' => $now,
                        'error'   => $error_message,
                    ],
                    [ 'id' => $job_id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );

                // Optionally notify admin if this campaign is failing a lot.
                self::maybe_notify_send_failures( $campaign_id );
            }

            $touched_campaigns[ $campaign_id ] = true;
        }

        // After processing batch, check campaigns we touched:
        // if they have no remaining queued rows, mark campaign as "sent".
        if ( ! empty( $touched_campaigns ) ) {
            foreach ( array_keys( $touched_campaigns ) as $cid ) {
                self::maybe_mark_campaign_sent( $cid );
            }
        }
    }

    /**
     * Build the HTML body for a given campaign + subscriber.
     *
     * - Starts from campaign->content (if present)
     * - Optionally falls back to dwmp_email template if campaign->template_id exists
     * - Injects simple merge tags: [[name]], {{name}}, [[email]], {{email}}
     * - Replaces [[unsubscribe_url]] / {{unsubscribe_url}} with a real unsubscribe link
     * - Optionally appends a 1x1 tracking pixel (?dwmp_track=open) unless disabled
     * - Optionally wraps links with click tracking (?dwmp_track=click) unless disabled
     * - Appends a sender info footer built from dwmp_sender
     */
    protected static function build_email_html( $campaign, $subscriber ) {
        // Security settings: determine if tracking is disabled.
        $security = get_option( 'dwmp_security', [] );
        $security = wp_parse_args(
            $security,
            [
                'disable_tracking' => 0,
            ]
        );
        $disable_tracking = ! empty( $security['disable_tracking'] );

        $raw_html = '';
        $content  = isset( $campaign->content ) ? $campaign->content : '';

        // If campaign has stored content, use that.
        if ( $content ) {
            $raw_html = $content;
        } else {
            // Optionally fallback to dwmp_email template if template_id exists.
            if ( isset( $campaign->template_id ) && $campaign->template_id ) {
                $tpl = get_post( (int) $campaign->template_id );
                if ( $tpl && 'dwmp_email' === $tpl->post_type ) {
                    $raw_html = $tpl->post_content;
                }
            }
        }

        // Run through standard WP content filters (shortcodes, blocks, etc.).
        $html = apply_filters( 'the_content', $raw_html );

        $subscriber_name  = isset( $subscriber->name )  ? $subscriber->name  : '';
        $subscriber_email = isset( $subscriber->email ) ? $subscriber->email : '';

        // Simple merge tags.
        $replacements = [
            '[[name]]'  => $subscriber_name,
            '{{name}}'  => $subscriber_name,
            '[[email]]' => $subscriber_email,
            '{{email}}' => $subscriber_email,
        ];
        $html = strtr( $html, $replacements );

        // Unsubscribe URL.
        $unsubscribe_url = self::build_unsubscribe_url(
            (int) $campaign->id,
            (int) $subscriber->id,
            isset( $subscriber->unsubscribe_token ) ? $subscriber->unsubscribe_token : ''
        );

        if ( $unsubscribe_url ) {
            $html = str_replace(
                [ '[[unsubscribe_url]]', '{{unsubscribe_url}}' ],
                esc_url( $unsubscribe_url ),
                $html
            );
        }

        // Click tracking wrapper (unless tracking is disabled).
        if ( ! $disable_tracking ) {
            $html = self::wrap_links_with_click_tracking(
                $html,
                (int) $campaign->id,
                (int) $subscriber->id
            );
        }

        // Build sender info footer from dwmp_sender.
        $footer_html = self::build_sender_footer_html( $campaign, $subscriber );

        // Build open-tracking pixel (unless tracking is disabled).
        $open_pixel = '';
        if ( ! $disable_tracking ) {
            $open_pixel = self::build_open_pixel(
                (int) $campaign->id,
                (int) $subscriber->id
            );
        }

        // Insert footer + pixel before closing body/html if present; else append.
        $insertion = $footer_html . $open_pixel;

        if ( false !== stripos( $html, '</body>' ) ) {
            $html = str_ireplace( '</body>', $insertion . '</body>', $html );
        } elseif ( false !== stripos( $html, '</html>' ) ) {
            $html = str_ireplace( '</html>', $insertion . '</html>', $html );
        } else {
            $html .= $insertion;
        }

        /**
         * Filter: allow overrides of the final rendered HTML.
         */
        $html = apply_filters( 'dwmp_render_campaign_html', $html, $campaign, $subscriber );

        return $html;
    }

    /**
     * Build the sender footer HTML from dwmp_sender settings.
     *
     * Uses:
     * - brand_name
     * - company_name
     * - postal_address
     * - website_url
     * - support_email
     * - footer_text
     */
    protected static function build_sender_footer_html( $campaign, $subscriber ) {
        $sender = get_option( 'dwmp_sender', [] );
        $sender = wp_parse_args(
            $sender,
            [
                'brand_name'     => '',
                'company_name'   => '',
                'postal_address' => '',
                'logo_url'       => '',
                'website_url'    => '',
                'support_email'  => '',
                'footer_text'    => '',
            ]
        );

        // If nothing is set, don't bloat the email.
        if (
            ! $sender['footer_text']
            && ! $sender['company_name']
            && ! $sender['postal_address']
            && ! $sender['support_email']
        ) {
            return '';
        }

        $html  = "\n<hr style=\"border:none;border-top:1px solid #e5e7eb;margin:32px 0 16px;\" />\n";

        if ( $sender['footer_text'] ) {
            $html .= sprintf(
                '<p style="font-size:12px;line-height:1.5;color:#6b7280;margin:0 0 4px;">%s</p>',
                esc_html( $sender['footer_text'] )
            );
        }

        if ( $sender['company_name'] || $sender['postal_address'] ) {
            $company_line = trim( $sender['company_name'] );
            if ( $company_line && $sender['postal_address'] ) {
                $company_line .= ' · ' . $sender['postal_address'];
            } elseif ( ! $company_line && $sender['postal_address'] ) {
                $company_line = $sender['postal_address'];
            }

            $html .= sprintf(
                '<p style="font-size:11px;line-height:1.5;color:#9ca3af;margin:2px 0 0;">%s</p>',
                nl2br( esc_html( $company_line ) )
            );
        }

        if ( $sender['website_url'] || $sender['support_email'] ) {
            $bits = [];

            if ( $sender['website_url'] ) {
                $bits[] = sprintf(
                    '<a href="%s" style="color:#3b82f6;text-decoration:none;">%s</a>',
                    esc_url( $sender['website_url'] ),
                    esc_html( parse_url( $sender['website_url'], PHP_URL_HOST ) ?: $sender['website_url'] )
                );
            }

            if ( $sender['support_email'] ) {
                $bits[] = sprintf(
                    '<a href="mailto:%s" style="color:#3b82f6;text-decoration:none;">%s</a>',
                    esc_attr( $sender['support_email'] ),
                    esc_html( $sender['support_email'] )
                );
            }

            if ( ! empty( $bits ) ) {
                $html .= sprintf(
                    '<p style="font-size:11px;line-height:1.5;color:#9ca3af;margin:4px 0 0;">%s</p>',
                    implode( ' · ', $bits )
                );
            }
        }

        return $html . "\n";
    }

    /**
     * Build unsubscribe URL for a given campaign + subscriber.
     */
    protected static function build_unsubscribe_url( $campaign_id, $subscriber_id, $token ) {
        if ( ! $campaign_id || ! $subscriber_id || ! $token ) {
            return '';
        }

        $args = [
            'dwmp_track' => 'unsubscribe',
            'c'          => $campaign_id,
            's'          => $subscriber_id,
            't'          => $token,
        ];

        return add_query_arg( $args, home_url( '/' ) );
    }

    /**
     * Build 1x1 open tracking pixel HTML snippet.
     */
    protected static function build_open_pixel( $campaign_id, $subscriber_id ) {
        if ( ! $campaign_id || ! $subscriber_id ) {
            return '';
        }

        $url = add_query_arg(
            [
                'dwmp_track' => 'open',
                'c'          => $campaign_id,
                's'          => $subscriber_id,
            ],
            home_url( '/' )
        );

        // inline styles to hide the pixel
        return sprintf(
            '<img src="%s" width="1" height="1" style="display:none;" alt="" />',
            esc_url( $url )
        );
    }

    /**
     * Wrap <a href="..."> links with click tracking URLs.
     *
     * - Original URL is base64-encoded into ?u=
     * - We use dwmp_track=click&c=..&s=.. so Tracking::handle() can log the click.
     */
    protected static function wrap_links_with_click_tracking( $html, $campaign_id, $subscriber_id ) {
        if ( ! $campaign_id || ! $subscriber_id || ! $html ) {
            return $html;
        }

        // Very simple regex – intentionally conservative.
        return preg_replace_callback(
            '#<a\s+[^>]*href=("|\')(https?://[^"\']+)\1#i',
            function ( $matches ) use ( $campaign_id, $subscriber_id ) {
                $quote = $matches[1];
                $url   = $matches[2];

                // Encode original URL.
                $encoded   = base64_encode( $url );
                $track_url = add_query_arg(
                    [
                        'dwmp_track' => 'click',
                        'c'          => $campaign_id,
                        's'          => $subscriber_id,
                        'u'          => rawurlencode( $encoded ),
                    ],
                    home_url( '/' )
                );

                return str_replace( $url, esc_url( $track_url ), $matches[0] );
            },
            $html
        );
    }

    /**
     * If a campaign has no more queued rows, mark it as "sent".
     *
     * - Checks dwmp_send_queue for status='queued'
     * - If none found, updates dwmp_campaigns.status to 'sent' (if that column exists)
     *   and sets updated_at = now (if that column exists).
     */
    protected static function maybe_mark_campaign_sent( $campaign_id ) {
        global $wpdb;

        $campaign_id = (int) $campaign_id;
        if ( ! $campaign_id ) {
            return;
        }

        $p           = $wpdb->prefix;
        $queue_table = "{$p}dwmp_send_queue";
        $camp_table  = "{$p}dwmp_campaigns";

        // Safety: ensure tables exist.
        $queue_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table )
        );
        $camp_exists  = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $camp_table )
        );

        if ( $queue_exists !== $queue_table || $camp_exists !== $camp_table ) {
            return;
        }

        // Any queued rows left for this campaign?
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table}
                 WHERE campaign_id = %d AND status = 'queued'",
                $campaign_id
            )
        );

        if ( $remaining > 0 ) {
            return;
        }

        // Fetch columns to see if status/updated_at exist.
        $columns        = $wpdb->get_col( "DESC {$camp_table}", 0 );
        $has_status     = in_array( 'status', $columns, true );
        $has_updated_at = in_array( 'updated_at', $columns, true );

        $data   = [];
        $format = [];
        if ( $has_status ) {
            $data['status'] = 'sent';
            $format[]       = '%s';
        }
        if ( $has_updated_at ) {
            $data['updated_at'] = current_time( 'mysql' );
            $format[]           = '%s';
        }

        if ( ! empty( $data ) ) {
            $wpdb->update(
                $camp_table,
                $data,
                [ 'id' => $campaign_id ],
                $format,
                [ '%d' ]
            );
        }
    }

    /**
     * If send failures become frequent for a campaign, notify the admin.
     *
     * Uses dwmp_notifications:
     * - admin_email          (defaults to site admin email)
     * - notify_send_failures (0/1)
     * - failure_threshold    (number of failed rows before first alert, default 10)
     *
     * Only sends one alert email per campaign (tracked in option:
     * dwmp_notified_send_failure_campaigns).
     */
    protected static function maybe_notify_send_failures( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( ! $campaign_id ) {
            return;
        }

        $notifications = get_option( 'dwmp_notifications', [] );
        $notifications = wp_parse_args(
            $notifications,
            [
                'admin_email'          => get_option( 'admin_email' ),
                'notify_send_failures' => 0,
                'failure_threshold'    => 10,
            ]
        );

        if ( empty( $notifications['notify_send_failures'] ) || empty( $notifications['admin_email'] ) ) {
            return;
        }

        global $wpdb;
        $p           = $wpdb->prefix;
        $queue_table = "{$p}dwmp_send_queue";

        // Safety: ensure table exists.
        $queue_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table )
        );
        if ( $queue_exists !== $queue_table ) {
            return;
        }

        // Total failed rows for this campaign.
        $failed = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table}
                 WHERE campaign_id = %d AND status = 'failed'",
                $campaign_id
            )
        );

        if ( $failed < (int) $notifications['failure_threshold'] ) {
            return;
        }

        // Avoid spamming: only alert once per campaign.
        $notified = get_option( 'dwmp_notified_send_failure_campaigns', [] );
        if ( in_array( $campaign_id, $notified, true ) ) {
            return;
        }

        $subject = sprintf(
            __( 'MailPro: Send failures detected on campaign #%d', 'deepwares-mailpro' ),
            $campaign_id
        );

        $body = sprintf(
            "<p>%s</p><p>%s</p>",
            esc_html__( 'Several messages in this campaign failed to send.', 'deepwares-mailpro' ),
            esc_html( sprintf( 'Current failed count: %d (threshold: %d)', $failed, (int) $notifications['failure_threshold'] ) )
        );

        if ( class_exists( __NAMESPACE__ . '\\MailSender' ) ) {
            MailSender::send( $notifications['admin_email'], $subject, $body );
        } else {
            wp_mail( $notifications['admin_email'], $subject, wp_strip_all_tags( $body ) );
        }

        $notified[] = $campaign_id;
        update_option( 'dwmp_notified_send_failure_campaigns', array_unique( $notified ) );
    }
}
