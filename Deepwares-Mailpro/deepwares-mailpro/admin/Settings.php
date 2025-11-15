<?php
namespace Deepwares\MailPro\Admin {

use Deepwares\MailPro\MailSender;

defined( 'ABSPATH' ) || exit;

class Settings {

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'deepwares-mailpro' ) );
        }

        $active_tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'smtp';
        $notice      = '';
        $notice_type = 'success';

        // ---------------------------------------------------------------------
        // Load options with defaults
        // ---------------------------------------------------------------------
        // SMTP
        $smtp = get_option( 'dwmp_smtp', array() );
        $smtp = wp_parse_args(
            $smtp,
            array(
                'host'       => '',
                'port'       => 587,
                'username'   => '',
                'password'   => '',
                'encryption' => '',
                'from_email' => '',
                'from_name'  => '',
                'reply_to'   => '',
            )
        );

        // Sender profile
        $sender = get_option( 'dwmp_sender', array() );
        $sender = wp_parse_args(
            $sender,
            array(
                'brand_name'     => '',
                'company_name'   => '',
                'postal_address' => '',
                'logo_url'       => '',
                'website_url'    => '',
                'support_email'  => '',
                'footer_text'    => '',
            )
        );

        // Notifications
        $notifications = get_option( 'dwmp_notifications', array() );
        $notifications = wp_parse_args(
            $notifications,
            array(
                'admin_email'             => get_option( 'admin_email' ),
                'notify_send_failure'     => 1,
                'notify_campaign_summary' => 1,
                'notify_weekly_digest'    => 0,
                'notify_high_bounce'      => 0,
                'bounce_threshold'        => 5, // %
            )
        );

        // Security
        $security = get_option( 'dwmp_security', array() );
        $security = wp_parse_args(
            $security,
            array(
                'access_capability'     => 'manage_options',
                'disable_tracking'      => 0,
                'events_retention_days' => 365,
                'protect_forms'         => 0,
            )
        );

        // ---------------------------------------------------------------------
        // Handle POST per-tab
        // ---------------------------------------------------------------------

        // SMTP form (Save + Test Connection)
        if ( 'smtp' === $active_tab && 'POST' === $_SERVER['REQUEST_METHOD'] ) {

            if (
                ! isset( $_POST['_wpnonce'] ) ||
                ! wp_verify_nonce( $_POST['_wpnonce'], 'dwmp_smtp_settings' )
            ) {
                $notice      = __( 'Security check failed. Please try again.', 'deepwares-mailpro' );
                $notice_type = 'error';
            } else {

                $posted = array(
                    'host'       => isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '',
                    'port'       => isset( $_POST['smtp_port'] ) ? (int) $_POST['smtp_port'] : 587,
                    'username'   => isset( $_POST['smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_username'] ) ) : '',
                    'password'   => isset( $_POST['smtp_password'] ) ? wp_unslash( $_POST['smtp_password'] ) : '',
                    'encryption' => isset( $_POST['smtp_encryption'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ) ) : '',
                    'from_email' => isset( $_POST['smtp_from_email'] ) ? sanitize_email( wp_unslash( $_POST['smtp_from_email'] ) ) : '',
                    'from_name'  => isset( $_POST['smtp_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_from_name'] ) ) : '',
                    'reply_to'   => isset( $_POST['smtp_reply_to'] ) ? sanitize_email( wp_unslash( $_POST['smtp_reply_to'] ) ) : '',
                );

                $smtp = array_merge( $smtp, $posted );

                $did_save = isset( $_POST['dwmp_smtp_save'] );
                $did_test = isset( $_POST['dwmp_smtp_test'] );

                if ( $did_save ) {
                    update_option( 'dwmp_smtp', $smtp );
                    $notice      = __( 'SMTP settings saved.', 'deepwares-mailpro' );
                    $notice_type = 'success';
                }

                if ( $did_test ) {
                    $old_smtp = get_option( 'dwmp_smtp', array() );
                    update_option( 'dwmp_smtp', $smtp ); // temporary

                    $test_to  = get_option( 'admin_email' );
                    $subject  = __( 'MailPro SMTP Test', 'deepwares-mailpro' );
                    $body     = '<p>' . esc_html__( 'This is a test email from Deepwares MailPro.', 'deepwares-mailpro' ) . '</p>';

                    $ok = MailSender::send( $test_to, $subject, $body );

                    update_option( 'dwmp_smtp', $old_smtp );

                    if ( $ok ) {
                        $notice      = sprintf(
                            __( 'Test email sent successfully to %s.', 'deepwares-mailpro' ),
                            esc_html( $test_to )
                        );
                        $notice_type = 'success';
                    } else {
                        $err = property_exists( MailSender::class, 'last_error' )
                            ? MailSender::$last_error
                            : '';

                        if ( ! $err ) {
                            $err = __( 'Unknown SMTP error. Please double-check your host, port, encryption, username/password, or server firewall.', 'deepwares-mailpro' );
                        }

                        $notice      = sprintf(
                            __( 'Test email failed: %s', 'deepwares-mailpro' ),
                            $err
                        );
                        $notice_type = 'error';
                    }
                }
            }
        }

        // Sender Info form
        if ( 'sender' === $active_tab && 'POST' === $_SERVER['REQUEST_METHOD'] ) {

            if (
                ! isset( $_POST['_wpnonce'] ) ||
                ! wp_verify_nonce( $_POST['_wpnonce'], 'dwmp_sender_settings' )
            ) {
                $notice      = __( 'Security check failed. Please try again.', 'deepwares-mailpro' );
                $notice_type = 'error';
            } else {
                $sender['brand_name']     = isset( $_POST['sender_brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sender_brand_name'] ) ) : '';
                $sender['company_name']   = isset( $_POST['sender_company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sender_company_name'] ) ) : '';
                $sender['postal_address'] = isset( $_POST['sender_postal_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sender_postal_address'] ) ) : '';
                $sender['logo_url']       = isset( $_POST['sender_logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['sender_logo_url'] ) ) : '';
                $sender['website_url']    = isset( $_POST['sender_website_url'] ) ? esc_url_raw( wp_unslash( $_POST['sender_website_url'] ) ) : '';
                $sender['support_email']  = isset( $_POST['sender_support_email'] ) ? sanitize_email( wp_unslash( $_POST['sender_support_email'] ) ) : '';
                $sender['footer_text']    = isset( $_POST['sender_footer_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sender_footer_text'] ) ) : '';

                update_option( 'dwmp_sender', $sender );

                $notice      = __( 'Sender profile saved.', 'deepwares-mailpro' );
                $notice_type = 'success';
            }
        }

        // Notifications form
        if ( 'notifications' === $active_tab && 'POST' === $_SERVER['REQUEST_METHOD'] ) {

            if (
                ! isset( $_POST['_wpnonce'] ) ||
                ! wp_verify_nonce( $_POST['_wpnonce'], 'dwmp_notifications_settings' )
            ) {
                $notice      = __( 'Security check failed. Please try again.', 'deepwares-mailpro' );
                $notice_type = 'error';
            } else {

                $notifications['admin_email']             = isset( $_POST['notif_admin_email'] ) ? sanitize_email( wp_unslash( $_POST['notif_admin_email'] ) ) : '';
                $notifications['notify_send_failure']     = ! empty( $_POST['notif_send_failure'] ) ? 1 : 0;
                $notifications['notify_campaign_summary'] = ! empty( $_POST['notif_campaign_summary'] ) ? 1 : 0;
                $notifications['notify_weekly_digest']    = ! empty( $_POST['notif_weekly_digest'] ) ? 1 : 0;
                $notifications['notify_high_bounce']      = ! empty( $_POST['notif_high_bounce'] ) ? 1 : 0;
                $notifications['bounce_threshold']        = isset( $_POST['notif_bounce_threshold'] ) ? max( 0, (int) $_POST['notif_bounce_threshold'] ) : 5;

                update_option( 'dwmp_notifications', $notifications );

                $notice      = __( 'Notification settings saved.', 'deepwares-mailpro' );
                $notice_type = 'success';
            }
        }

        // Security form
        if ( 'security' === $active_tab && 'POST' === $_SERVER['REQUEST_METHOD'] ) {

            if (
                ! isset( $_POST['_wpnonce'] ) ||
                ! wp_verify_nonce( $_POST['_wpnonce'], 'dwmp_security_settings' )
            ) {
                $notice      = __( 'Security check failed. Please try again.', 'deepwares-mailpro' );
                $notice_type = 'error';
            } else {

                $security['access_capability']     = isset( $_POST['sec_access_capability'] ) ? sanitize_text_field( wp_unslash( $_POST['sec_access_capability'] ) ) : 'manage_options';
                $security['disable_tracking']      = ! empty( $_POST['sec_disable_tracking'] ) ? 1 : 0;
                $security['events_retention_days'] = isset( $_POST['sec_events_retention_days'] ) ? max( 0, (int) $_POST['sec_events_retention_days'] ) : 365;
                $security['protect_forms']         = ! empty( $_POST['sec_protect_forms'] ) ? 1 : 0;

                update_option( 'dwmp_security', $security );

                $notice      = __( 'Security settings saved.', 'deepwares-mailpro' );
                $notice_type = 'success';
            }
        }

        // ---------------------------------------------------------------------
        // View
        // ---------------------------------------------------------------------
        ?>
        <div class="wrap dwmp-settings-wrap">
            <h1 class="dwmp-settings-title"><?php esc_html_e( 'Settings', 'deepwares-mailpro' ); ?></h1>
            <p class="description dwmp-settings-subtitle">
                <?php esc_html_e( 'Configure your email marketing system', 'deepwares-mailpro' ); ?>
            </p>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Inner tabs -->
            <div class="dwmp-settings-tabs">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-settings&tab=smtp' ) ); ?>"
                   class="dwmp-settings-tab <?php echo ( 'smtp' === $active_tab ) ? 'is-active' : ''; ?>">
                    <span class="dashicons dashicons-email-alt"></span>
                    <span><?php esc_html_e( 'SMTP Settings', 'deepwares-mailpro' ); ?></span>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-settings&tab=sender' ) ); ?>"
                   class="dwmp-settings-tab <?php echo ( 'sender' === $active_tab ) ? 'is-active' : ''; ?>">
                    <span class="dashicons dashicons-id"></span>
                    <span><?php esc_html_e( 'Sender Info', 'deepwares-mailpro' ); ?></span>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-settings&tab=notifications' ) ); ?>"
                   class="dwmp-settings-tab <?php echo ( 'notifications' === $active_tab ) ? 'is-active' : ''; ?>">
                    <span class="dashicons dashicons-megaphone"></span>
                    <span><?php esc_html_e( 'Notifications', 'deepwares-mailpro' ); ?></span>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-settings&tab=security' ) ); ?>"
                   class="dwmp-settings-tab <?php echo ( 'security' === $active_tab ) ? 'is-active' : ''; ?>">
                    <span class="dashicons dashicons-shield"></span>
                    <span><?php esc_html_e( 'Security', 'deepwares-mailpro' ); ?></span>
                </a>
            </div>

            <?php if ( 'smtp' === $active_tab ) : ?>

                <div class="dwmp-settings-card">
                    <form method="post">
                        <?php wp_nonce_field( 'dwmp_smtp_settings' ); ?>

                        <div class="dwmp-settings-card-header">
                            <div>
                                <h2><?php esc_html_e( 'SMTP Server Configuration', 'deepwares-mailpro' ); ?></h2>
                                <p><?php esc_html_e( 'Configure your hosting provider’s SMTP server to send emails.', 'deepwares-mailpro' ); ?></p>
                            </div>
                        </div>

                        <div class="dwmp-settings-grid">
                            <div class="dwmp-field">
                                <label for="smtp_host"><?php esc_html_e( 'SMTP Host', 'deepwares-mailpro' ); ?></label>
                                <input type="text" name="smtp_host" id="smtp_host"
                                       value="<?php echo esc_attr( $smtp['host'] ); ?>"
                                       placeholder="smtp.yourhostingprovider.com"
                                       class="regular-text" />
                            </div>

                            <div class="dwmp-field dwmp-field-small">
                                <label for="smtp_port"><?php esc_html_e( 'SMTP Port', 'deepwares-mailpro' ); ?></label>
                                <input type="number" name="smtp_port" id="smtp_port"
                                       value="<?php echo esc_attr( $smtp['port'] ); ?>"
                                       class="small-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Common: 587 (TLS), 465 (SSL), 25 (no encryption).', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field">
                                <label for="smtp_username"><?php esc_html_e( 'SMTP Username', 'deepwares-mailpro' ); ?></label>
                                <input type="text" name="smtp_username" id="smtp_username"
                                       value="<?php echo esc_attr( $smtp['username'] ); ?>"
                                       placeholder="your-email@domain.com"
                                       class="regular-text" />
                            </div>

                            <div class="dwmp-field">
                                <label for="smtp_password"><?php esc_html_e( 'SMTP Password', 'deepwares-mailpro' ); ?></label>
                                <input type="password" name="smtp_password" id="smtp_password"
                                       value="<?php echo esc_attr( $smtp['password'] ); ?>"
                                       class="regular-text" autocomplete="new-password" />
                            </div>

                            <div class="dwmp-field">
                                <label for="smtp_encryption"><?php esc_html_e( 'Encryption', 'deepwares-mailpro' ); ?></label>
                                <select name="smtp_encryption" id="smtp_encryption">
                                    <option value="" <?php selected( $smtp['encryption'], '' ); ?>>
                                        <?php esc_html_e( 'Auto / None', 'deepwares-mailpro' ); ?>
                                    </option>
                                    <option value="ssl" <?php selected( strtolower( $smtp['encryption'] ), 'ssl' ); ?>>
                                        SSL / SMTPS (465)
                                    </option>
                                    <option value="tls" <?php selected( strtolower( $smtp['encryption'] ), 'tls' ); ?>>
                                        TLS / STARTTLS (587)
                                    </option>
                                </select>
                            </div>

                            <div class="dwmp-field">
                                <label for="smtp_from_email"><?php esc_html_e( 'From Email', 'deepwares-mailpro' ); ?></label>
                                <input type="email" name="smtp_from_email" id="smtp_from_email"
                                       value="<?php echo esc_attr( $smtp['from_email'] ); ?>"
                                       class="regular-text" />
                            </div>

                            <div class="dwmp-field">
                                <label for="smtp_from_name"><?php esc_html_e( 'From Name', 'deepwares-mailpro' ); ?></label>
                                <input type="text" name="smtp_from_name" id="smtp_from_name"
                                       value="<?php echo esc_attr( $smtp['from_name'] ); ?>"
                                       class="regular-text" />
                            </div>

                            <div class="dwmp-field">
                                <label for="smtp_reply_to"><?php esc_html_e( 'Reply-To Email', 'deepwares-mailpro' ); ?></label>
                                <input type="email" name="smtp_reply_to" id="smtp_reply_to"
                                       value="<?php echo esc_attr( $smtp['reply_to'] ); ?>"
                                       class="regular-text" />
                            </div>
                        </div>

                        <div class="dwmp-settings-actions">
                            <button type="submit" name="dwmp_smtp_test" class="button">
                                <?php esc_html_e( 'Test Connection', 'deepwares-mailpro' ); ?>
                            </button>
                            <button type="submit" name="dwmp_smtp_save" class="button button-primary dwmp-button-black">
                                <?php esc_html_e( 'Save SMTP Settings', 'deepwares-mailpro' ); ?>
                            </button>
                        </div>

                        <div class="dwmp-settings-footer-info">
                            <h3><?php esc_html_e( 'Common SMTP Providers', 'deepwares-mailpro' ); ?></h3>
                            <ul>
                                <li><?php esc_html_e( 'cPanel/WHM: Usually mail.yourdomain.com:587', 'deepwares-mailpro' ); ?></li>
                                <li><?php esc_html_e( 'Plesk: Check your hosting control panel', 'deepwares-mailpro' ); ?></li>
                                <li><?php esc_html_e( 'Custom: Contact your hosting provider for SMTP details', 'deepwares-mailpro' ); ?></li>
                            </ul>
                        </div>

                        <!-- Merge tag help box -->
                        <div class="dwmp-merge-tags">
                            <h3><?php esc_html_e( 'Personalisation & Merge Tags', 'deepwares-mailpro' ); ?></h3>
                            <p>
                                <?php esc_html_e( 'Use these placeholders in your campaign content or email templates. They will be replaced for each subscriber when the campaign is sent.', 'deepwares-mailpro' ); ?>
                            </p>
                            <ul>
                                <li>
                                    <code>[[name]]</code> / <code>{{name}}</code> –
                                    <?php esc_html_e( 'subscriber’s name (from the Subscribers list).', 'deepwares-mailpro' ); ?>
                                </li>
                                <li>
                                    <code>[[email]]</code> / <code>{{email}}</code> –
                                    <?php esc_html_e( 'subscriber’s email address.', 'deepwares-mailpro' ); ?>
                                </li>
                                <li>
                                    <code>[[unsubscribe_url]]</code> / <code>{{unsubscribe_url}}</code> –
                                    <?php esc_html_e( 'one-click unsubscribe link unique to each subscriber.', 'deepwares-mailpro' ); ?>
                                </li>
                            </ul>
                            <p class="dwmp-merge-tags-tip">
                                <?php esc_html_e( 'Tip: open & click tracking, plus the unsubscribe event, are added automatically by MailPro when you send a campaign—you just need to place the merge tags where you want them to appear.', 'deepwares-mailpro' ); ?>
                            </p>
                        </div>
                    </form>
                </div>

            <?php elseif ( 'sender' === $active_tab ) : ?>

                <div class="dwmp-settings-card">
                    <form method="post">
                        <?php wp_nonce_field( 'dwmp_sender_settings' ); ?>

                        <div class="dwmp-settings-card-header">
                            <div>
                                <h2><?php esc_html_e( 'Default Sender Profile', 'deepwares-mailpro' ); ?></h2>
                                <p><?php esc_html_e( 'Define the default identity that appears in your campaigns and footers.', 'deepwares-mailpro' ); ?></p>
                            </div>
                        </div>

                        <div class="dwmp-settings-grid">
                            <div class="dwmp-field">
                                <label for="sender_brand_name"><?php esc_html_e( 'Brand / From Name', 'deepwares-mailpro' ); ?></label>
                                <input type="text" id="sender_brand_name" name="sender_brand_name"
                                       value="<?php echo esc_attr( $sender['brand_name'] ); ?>"
                                       placeholder="Pixelart NZ" />
                                <p class="description">
                                    <?php esc_html_e( 'This is the friendly name subscribers see in their inbox.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field">
                                <label for="sender_company_name"><?php esc_html_e( 'Company Name', 'deepwares-mailpro' ); ?></label>
                                <input type="text" id="sender_company_name" name="sender_company_name"
                                       value="<?php echo esc_attr( $sender['company_name'] ); ?>" />
                            </div>

                            <div class="dwmp-field">
                                <label for="sender_postal_address"><?php esc_html_e( 'Postal / Physical Address', 'deepwares-mailpro' ); ?></label>
                                <textarea id="sender_postal_address" name="sender_postal_address" rows="3"><?php echo esc_textarea( $sender['postal_address'] ); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'Used in footers for CAN-SPAM style compliance.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field">
                                <label for="sender_logo_url"><?php esc_html_e( 'Logo URL', 'deepwares-mailpro' ); ?></label>
                                <input type="url" id="sender_logo_url" name="sender_logo_url"
                                       value="<?php echo esc_attr( $sender['logo_url'] ); ?>"
                                       placeholder="https://example.com/logo.png" />
                            </div>

                            <div class="dwmp-field">
                                <label for="sender_website_url"><?php esc_html_e( 'Website URL', 'deepwares-mailpro' ); ?></label>
                                <input type="url" id="sender_website_url" name="sender_website_url"
                                       value="<?php echo esc_attr( $sender['website_url'] ); ?>"
                                       placeholder="https://example.com" />
                            </div>

                            <div class="dwmp-field">
                                <label for="sender_support_email"><?php esc_html_e( 'Support / Reply Email', 'deepwares-mailpro' ); ?></label>
                                <input type="email" id="sender_support_email" name="sender_support_email"
                                       value="<?php echo esc_attr( $sender['support_email'] ); ?>" />
                            </div>

                            <div class="dwmp-field" style="grid-column: 1 / -1;">
                                <label for="sender_footer_text"><?php esc_html_e( 'Default Footer Text', 'deepwares-mailpro' ); ?></label>
                                <textarea id="sender_footer_text" name="sender_footer_text" rows="3"
                                          placeholder="<?php esc_attr_e( 'You are receiving this email because you subscribed on our website…', 'deepwares-mailpro' ); ?>"><?php echo esc_textarea( $sender['footer_text'] ); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'You can insert this in templates with a helper later.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="dwmp-settings-actions">
                            <button type="submit" class="button button-primary dwmp-button-black">
                                <?php esc_html_e( 'Save Sender Settings', 'deepwares-mailpro' ); ?>
                            </button>
                        </div>
                    </form>
                </div>

            <?php elseif ( 'notifications' === $active_tab ) : ?>

                <div class="dwmp-settings-card">
                    <form method="post">
                        <?php wp_nonce_field( 'dwmp_notifications_settings' ); ?>

                        <div class="dwmp-settings-card-header">
                            <div>
                                <h2><?php esc_html_e( 'Admin Notifications', 'deepwares-mailpro' ); ?></h2>
                                <p><?php esc_html_e( 'Choose which events should send summary or alert emails.', 'deepwares-mailpro' ); ?></p>
                            </div>
                        </div>

                        <div class="dwmp-settings-grid">
                            <div class="dwmp-field" style="grid-column: 1 / -1;">
                                <label for="notif_admin_email"><?php esc_html_e( 'Notification Email', 'deepwares-mailpro' ); ?></label>
                                <input type="email" id="notif_admin_email" name="notif_admin_email"
                                       value="<?php echo esc_attr( $notifications['admin_email'] ); ?>" />
                                <p class="description">
                                    <?php esc_html_e( 'All alerts will be sent to this address.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field" style="grid-column: 1 / -1;">
                                <label>
                                    <input type="checkbox" name="notif_send_failure" value="1" <?php checked( $notifications['notify_send_failure'], 1 ); ?> />
                                    <?php esc_html_e( 'Alert me when a scheduled campaign fails to send.', 'deepwares-mailpro' ); ?>
                                </label>
                            </div>

                            <div class="dwmp-field" style="grid-column: 1 / -1;">
                                <label>
                                    <input type="checkbox" name="notif_campaign_summary" value="1" <?php checked( $notifications['notify_campaign_summary'], 1 ); ?> />
                                    <?php esc_html_e( 'Send a summary when a campaign finishes (opens, clicks, bounces).', 'deepwares-mailpro' ); ?>
                                </label>
                            </div>

                            <div class="dwmp-field" style="grid-column: 1 / -1;">
                                <label>
                                    <input type="checkbox" name="notif_weekly_digest" value="1" <?php checked( $notifications['notify_weekly_digest'], 1 ); ?> />
                                    <?php esc_html_e( 'Send a weekly digest of subscriber growth and list health.', 'deepwares-mailpro' ); ?>
                                </label>
                            </div>

                            <div class="dwmp-field">
                                <label>
                                    <input type="checkbox" name="notif_high_bounce" value="1" <?php checked( $notifications['notify_high_bounce'], 1 ); ?> />
                                    <?php esc_html_e( 'Alert me if bounce rate is high.', 'deepwares-mailpro' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Threshold (%) before we consider bounce rate “high”.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field dwmp-field-small">
                                <label for="notif_bounce_threshold"><?php esc_html_e( 'Bounce Threshold (%)', 'deepwares-mailpro' ); ?></label>
                                <input type="number" min="0" max="100" id="notif_bounce_threshold"
                                       name="notif_bounce_threshold"
                                       value="<?php echo esc_attr( $notifications['bounce_threshold'] ); ?>" />
                            </div>
                        </div>

                        <div class="dwmp-settings-actions">
                            <button type="submit" class="button button-primary dwmp-button-black">
                                <?php esc_html_e( 'Save Notification Settings', 'deepwares-mailpro' ); ?>
                            </button>
                        </div>
                    </form>
                </div>

            <?php elseif ( 'security' === $active_tab ) : ?>

                <div class="dwmp-settings-card">
                    <form method="post">
                        <?php wp_nonce_field( 'dwmp_security_settings' ); ?>

                        <div class="dwmp-settings-card-header">
                            <div>
                                <h2><?php esc_html_e( 'Security & Compliance', 'deepwares-mailpro' ); ?></h2>
                                <p><?php esc_html_e( 'Control access to MailPro and tracking behaviour.', 'deepwares-mailpro' ); ?></p>
                            </div>
                        </div>

                        <div class="dwmp-settings-grid">
                            <div class="dwmp-field">
                                <label for="sec_access_capability"><?php esc_html_e( 'Required Capability to Access MailPro', 'deepwares-mailpro' ); ?></label>
                                <select id="sec_access_capability" name="sec_access_capability">
                                    <option value="manage_options" <?php selected( $security['access_capability'], 'manage_options' ); ?>>
                                        <?php esc_html_e( 'Administrators only (manage_options)', 'deepwares-mailpro' ); ?>
                                    </option>
                                    <option value="edit_pages" <?php selected( $security['access_capability'], 'edit_pages' ); ?>>
                                        <?php esc_html_e( 'Editors & above (edit_pages)', 'deepwares-mailpro' ); ?>
                                    </option>
                                    <option value="edit_posts" <?php selected( $security['access_capability'], 'edit_posts' ); ?>>
                                        <?php esc_html_e( 'Authors & above (edit_posts)', 'deepwares-mailpro' ); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'Later you can enforce this in your admin_menu callbacks.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field">
                                <label>
                                    <input type="checkbox" name="sec_disable_tracking" value="1" <?php checked( $security['disable_tracking'], 1 ); ?> />
                                    <?php esc_html_e( 'Disable open & click tracking by default (privacy-friendly).', 'deepwares-mailpro' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'You can honour this flag when building tracking pixels and links.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field dwmp-field-small">
                                <label for="sec_events_retention_days"><?php esc_html_e( 'Event Retention (days)', 'deepwares-mailpro' ); ?></label>
                                <input type="number" min="0" id="sec_events_retention_days"
                                       name="sec_events_retention_days"
                                       value="<?php echo esc_attr( $security['events_retention_days'] ); ?>" />
                                <p class="description">
                                    <?php esc_html_e( 'For future cron job that purges old dwmp_events rows.', 'deepwares-mailpro' ); ?>
                                </p>
                            </div>

                            <div class="dwmp-field" style="grid-column: 1 / -1;">
                                <label>
                                    <input type="checkbox" name="sec_protect_forms" value="1" <?php checked( $security['protect_forms'], 1 ); ?> />
                                    <?php esc_html_e( 'Enable extra protection on public sign-up forms (honeypot / anti-spam).', 'deepwares-mailpro' ); ?>
                                </label>
                            </div>
                        </div>

                        <div class="dwmp-settings-actions">
                            <button type="submit" class="button button-primary dwmp-button-black">
                                <?php esc_html_e( 'Save Security Settings', 'deepwares-mailpro' ); ?>
                            </button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>
        </div>

        <style>
            .dwmp-settings-wrap {
                max-width: 1100px;
            }
            .dwmp-settings-title {
                margin-bottom: 4px;
            }
            .dwmp-settings-subtitle {
                margin-top: 0;
                color: #6b7280;
            }
            .dwmp-settings-tabs {
                display: inline-flex;
                gap: 8px;
                margin: 18px 0;
                padding: 4px;
                background: #f3f4f6;
                border-radius: 999px;
            }
            .dwmp-settings-tab {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 14px;
                border-radius: 999px;
                text-decoration: none;
                font-size: 13px;
                color: #4b5563;
                border: 1px solid transparent;
            }
            .dwmp-settings-tab .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .dwmp-settings-tab.is-active {
                background: #111827;
                color: #ffffff;
                border-color: #111827;
            }
            .dwmp-settings-tab.is-active .dashicons {
                color: #ffffff;
            }
            .dwmp-settings-card {
                background: #ffffff;
                border-radius: 12px;
                border: 1px solid #e5e7eb;
                padding: 20px 22px;
                box-shadow: 0 8px 20px rgba(15,23,42,0.05);
            }
            .dwmp-settings-card-header h2 {
                margin: 0 0 4px;
            }
            .dwmp-settings-card-header p {
                margin: 0;
                color: #6b7280;
                font-size: 13px;
            }
            .dwmp-settings-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px 24px;
                margin-top: 20px;
            }
            .dwmp-field {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .dwmp-field label {
                font-weight: 500;
            }
            .dwmp-field .description {
                margin: 0;
                font-size: 12px;
                color: #6b7280;
            }
            .dwmp-field-small input.small-text,
            .dwmp-field-small input[type="number"] {
                max-width: 120px;
            }
            .dwmp-settings-actions {
                margin-top: 24px;
                display: flex;
                gap: 10px;
            }
            .dwmp-button-black {
                background: #111827 !important;
                border-color: #111827 !important;
                color: #ffffff !important;
            }
            .dwmp-settings-footer-info {
                margin-top: 24px;
                padding: 14px 16px;
                border-radius: 10px;
                background: #f3f4ff;
                border: 1px solid #e5e7ff;
            }
            .dwmp-settings-footer-info h3 {
                margin-top: 0;
            }
            .dwmp-settings-footer-info ul {
                margin: 6px 0 0 18px;
                padding: 0;
                list-style: disc;
                font-size: 13px;
                color: #4b5563;
            }
            .dwmp-merge-tags {
                margin-top: 20px;
                padding: 14px 16px;
                border-radius: 10px;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                max-width: 860px;
            }
            .dwmp-merge-tags h3 {
                margin-top: 0;
                margin-bottom: 6px;
            }
            .dwmp-merge-tags p {
                margin: 0 0 6px;
                font-size: 13px;
                color: #4b5563;
            }
            .dwmp-merge-tags ul {
                margin: 4px 0 0 18px;
                padding: 0;
                list-style: disc;
                font-size: 13px;
                color: #374151;
            }
            .dwmp-merge-tags code {
                background: #e5e7eb;
                padding: 1px 4px;
                border-radius: 4px;
                font-size: 12px;
            }
            .dwmp-merge-tags-tip {
                font-size: 12px;
                color: #6b7280;
                margin-top: 8px;
            }
            @media (max-width: 900px) {
                .dwmp-settings-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
}

} // end namespace Deepwares\MailPro\Admin

namespace {
    // Global bridge for the admin menu callback in deepwares-mailpro.php
    if ( ! function_exists( 'dwmp_render_settings_page' ) ) {
        function dwmp_render_settings_page() {
            \Deepwares\MailPro\Admin\Settings::render();
        }
    }
}
