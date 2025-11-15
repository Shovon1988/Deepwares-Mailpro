<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dwmp_build_send_queue_for_campaign' ) ) {
    /**
     * Build the send queue rows for a campaign based on its lists (or all subscribers).
     *
     * - Uses dwmp_campaign_lists if present.
     * - Falls back to dwmp_campaigns.list_id if that column exists.
     * - If no lists are found, sends to all active subscribers.
     * - Will not create duplicate queue rows if any already exist for this campaign.
     *
     * @param int    $campaign_id
     * @param string $scheduled_for MySQL datetime (site timezone) when the queue should unlock.
     */
    function dwmp_build_send_queue_for_campaign( $campaign_id, $scheduled_for ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $campaign_id   = (int) $campaign_id;
        $scheduled_for = trim( (string) $scheduled_for );

        if ( ! $campaign_id || '' === $scheduled_for ) {
            return;
        }

        $queue_table = $p . 'dwmp_send_queue';
        $queue_exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $queue_table )
        );
        if ( $queue_exists !== $queue_table ) {
            return; // safety: queue table not there
        }

        // If queue rows already exist for this campaign, don't duplicate.
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d",
                $campaign_id
            )
        );
        if ( $existing > 0 ) {
            return;
        }

        // Try campaign ↔ list mapping table first.
        $cl_table      = $p . 'dwmp_campaign_lists';
        $cl_table_exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $cl_table )
        );

        $list_ids = array();

        if ( $cl_table_exists === $cl_table ) {
            $list_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT list_id FROM {$cl_table} WHERE campaign_id = %d",
                    $campaign_id
                )
            );
        }

        // Fallback: campaign.list_id column (if it exists).
        $campaign_table = $p . 'dwmp_campaigns';
        $campaign       = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$campaign_table} WHERE id = %d",
                $campaign_id
            )
        );

        if ( $campaign ) {
            static $campaign_cols = null;
            if ( null === $campaign_cols ) {
                $campaign_cols = $wpdb->get_col( "DESC {$campaign_table}", 0 );
            }

            if ( in_array( 'list_id', (array) $campaign_cols, true ) && ! empty( $campaign->list_id ) ) {
                $list_ids[] = (int) $campaign->list_id;
            }
        }

        $list_ids = array_unique( array_filter( array_map( 'intval', $list_ids ) ) );

        // Resolve subscribers
        if ( $list_ids ) {
            $in         = implode( ',', $list_ids );
            $subscribers = $wpdb->get_col("
                SELECT DISTINCT s.id
                FROM {$p}dwmp_subscribers s
                INNER JOIN {$p}dwmp_subscriber_lists sl ON s.id = sl.subscriber_id
                WHERE sl.list_id IN ({$in}) AND s.status = 'active'
            ");
        } else {
            // No specific lists: send to all active subscribers
            $subscribers = $wpdb->get_col("
                SELECT s.id
                FROM {$p}dwmp_subscribers s
                WHERE s.status = 'active'
            ");
        }

        if ( empty( $subscribers ) ) {
            return;
        }

        foreach ( $subscribers as $sid ) {
            $wpdb->insert(
                $queue_table,
                array(
                    'campaign_id'   => $campaign_id,
                    'subscriber_id' => (int) $sid,
                    'status'        => 'queued',
                    'scheduled_for' => $scheduled_for,
                    'sent_at'       => null,
                    'error'         => null,
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s' )
            );
        }
    }
}

/**
 * Campaigns overview page: cards UI + actions + "New Campaign" drawer.
 *
 * - Lists campaigns from {$wpdb->prefix}dwmp_campaigns
 * - Implements:
 *      • Send (with "Send Now" / "Schedule" modal)
 *      • Duplicate
 *      • Delete
 * - Provides a "New Campaign" drawer that:
 *      • Lets you pick a dwmp_email template
 *      • Lets you pick a subscriber list
 *      • Creates a draft campaign row
 *
 * All DB operations are defensive: we detect which columns exist on
 * the dwmp_campaigns / dwmp_lists tables and only use those.
 */
if ( ! function_exists( 'dwmp_render_campaigns_page' ) ) :

function dwmp_render_campaigns_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'deepwares-mailpro' ) );
    }

    global $wpdb;
    $p          = $wpdb->prefix;
    $table_name = $p . 'dwmp_campaigns';

    // ------------------------------------------------------------------
    // Helpers: table / column existence
    // ------------------------------------------------------------------
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name )
    );

    $columns = array();
    if ( $table_exists === $table_name ) {
        $columns = $wpdb->get_col( "DESC {$table_name}", 0 ); // 0: Field (column name)
    }

    $has_col = function( $col ) use ( $columns ) {
        return in_array( $col, $columns, true );
    };

    // ------------------------------------------------------------------
    // Handle SEND (via POST + modal: Send Now / Schedule)
    // ------------------------------------------------------------------
    $notice = '';

    if (
        $table_exists === $table_name &&
        ! empty( $_POST['dwmp_send_action'] ) &&
        'send' === $_POST['dwmp_send_action'] &&
        isset( $_POST['dwmp_send_nonce'] ) &&
        wp_verify_nonce( $_POST['dwmp_send_nonce'], 'dwmp_send_campaign' )
    ) {
        $id   = isset( $_POST['dwmp_send_campaign_id'] ) ? absint( $_POST['dwmp_send_campaign_id'] ) : 0;
        $mode = isset( $_POST['dwmp_send_mode'] ) ? sanitize_key( $_POST['dwmp_send_mode'] ) : 'now';
        $dt   = isset( $_POST['dwmp_schedule_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['dwmp_schedule_datetime'] ) ) : '';

        if ( $id ) {
            $data                = array();
            $format              = array();
            $where               = array( 'id' => $id );
            $where_format        = array( '%d' );
            $scheduled_for_value = null;

            if ( $has_col( 'status' ) ) {
                // We mark as scheduled; optionally you could set 'sending' for "Send Now"
                $data['status'] = 'scheduled';
                $format[]       = '%s';
            }

            if ( $has_col( 'scheduled_at' ) ) {
                if ( 'schedule' === $mode && $dt ) {
                    // HTML datetime-local => convert to site-local MySQL datetime
                    $ts = strtotime( $dt );
                    if ( $ts ) {
                        $data['scheduled_at'] = date_i18n( 'Y-m-d H:i:s', $ts );
                    } else {
                        $data['scheduled_at'] = current_time( 'mysql' );
                    }
                } else {
                    // "Send Now"
                    $data['scheduled_at'] = current_time( 'mysql' );
                }
                $format[]          = '%s';
                $scheduled_for_value = $data['scheduled_at'];
            }

            // Touch updated_at if present
            if ( $has_col( 'updated_at' ) ) {
                $data['updated_at'] = current_time( 'mysql' );
                $format[]           = '%s';
            }

            if ( ! empty( $data ) ) {
                $wpdb->update( $table_name, $data, $where, $format, $where_format );
                // Build queue rows for this campaign
                if ( $scheduled_for_value ) {
                    dwmp_build_send_queue_for_campaign( $id, $scheduled_for_value );
                }
                $notice = 'sent';
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=dwmp-campaigns&dwmp_notice=' . urlencode( $notice ) ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Handle Duplicate / Delete (GET actions)
    // ------------------------------------------------------------------
    if ( $table_exists === $table_name && ! empty( $_GET['dwmp_campaign_action'] ) && ! empty( $_GET['id'] ) ) {
        $action = sanitize_key( $_GET['dwmp_campaign_action'] );
        $id     = absint( $_GET['id'] );

        if ( $id ) {
            // Duplicate
            if ( 'duplicate' === $action && ! empty( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dwmp_duplicate_campaign_' . $id ) ) {
                $orig = $wpdb->get_row(
                    $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id )
                );

                if ( $orig ) {
                    $data   = array();
                    $format = array();

                    if ( $has_col( 'name' ) && isset( $orig->name ) ) {
                        $data['name'] = $orig->name . ' ' . __( '(Copy)', 'deepwares-mailpro' );
                        $format[]     = '%s';
                    }
                    if ( $has_col( 'subject' ) && isset( $orig->subject ) ) {
                        $data['subject'] = $orig->subject;
                        $format[]        = '%s';
                    }
                    if ( $has_col( 'description' ) && isset( $orig->description ) ) {
                        $data['description'] = $orig->description;
                        $format[]            = '%s';
                    }
                    if ( $has_col( 'content' ) && isset( $orig->content ) ) {
                        $data['content'] = $orig->content;
                        $format[]        = '%s';
                    }
                    if ( $has_col( 'template_id' ) && isset( $orig->template_id ) ) {
                        $data['template_id'] = $orig->template_id;
                        $format[]            = '%d';
                    }
                    if ( $has_col( 'list_id' ) && isset( $orig->list_id ) ) {
                        $data['list_id'] = $orig->list_id;
                        $format[]        = '%d';
                    }
                    if ( $has_col( 'status' ) ) {
                        $data['status'] = 'draft';
                        $format[]       = '%s';
                    }
                    if ( $has_col( 'created_at' ) ) {
                        $data['created_at'] = current_time( 'mysql' );
                        $format[]           = '%s';
                    }
                    if ( $has_col( 'updated_at' ) ) {
                        $data['updated_at'] = current_time( 'mysql' );
                        $format[]           = '%s';
                    }
                    if ( $has_col( 'scheduled_at' ) ) {
                        $data['scheduled_at'] = null;
                        $format[]             = '%s';
                    }

                    if ( ! empty( $data ) ) {
                        $wpdb->insert( $table_name, $data, $format );
                        $notice = 'duplicated';
                    }
                }
            }

            // Delete
            if ( 'delete' === $action && ! empty( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dwmp_delete_campaign_' . $id ) ) {
                $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

                // Best-effort cleanup in related tables.
                $related_tables = array(
                    $p . 'dwmp_campaign_lists',
                    $p . 'dwmp_send_queue',
                    $p . 'dwmp_events',
                );
                foreach ( $related_tables as $rt ) {
                    $exists = $wpdb->get_var(
                        $wpdb->prepare( "SHOW TABLES LIKE %s", $rt )
                    );
                    if ( $exists === $rt ) {
                        $wpdb->query(
                            $wpdb->prepare( "DELETE FROM {$rt} WHERE campaign_id = %d", $id )
                        );
                    }
                }

                $notice = 'deleted';
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=dwmp-campaigns&dwmp_notice=' . urlencode( $notice ) ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Handle New Campaign creation (drawer form)
    // ------------------------------------------------------------------
    if (
        $table_exists === $table_name &&
        ! empty( $_POST['dwmp_new_campaign'] ) &&
        check_admin_referer( 'dwmp_new_campaign' )
    ) {
        $name        = sanitize_text_field( $_POST['dwmp_campaign_name'] ?? '' );
        $subject     = sanitize_text_field( $_POST['dwmp_campaign_subject'] ?? '' );
        $description = sanitize_textarea_field( $_POST['dwmp_campaign_description'] ?? '' );
        $template_id = absint( $_POST['dwmp_email_template'] ?? 0 );
        $list_id     = absint( $_POST['dwmp_campaign_list_id'] ?? 0 );

        $data   = array();
        $format = array();

        if ( $has_col( 'name' ) && $name ) {
            $data['name'] = $name;
            $format[]     = '%s';
        }
        if ( $has_col( 'subject' ) && $subject ) {
            $data['subject'] = $subject;
            $format[]        = '%s';
        }
        if ( $has_col( 'description' ) ) {
            $data['description'] = $description;
            $format[]            = '%s';
        }
        if ( $has_col( 'status' ) ) {
            $data['status'] = 'draft';
            $format[]       = '%s';
        }
        if ( $has_col( 'created_at' ) ) {
            $data['created_at'] = current_time( 'mysql' );
            $format[]           = '%s';
        }
        if ( $has_col( 'updated_at' ) ) {
            $data['updated_at'] = current_time( 'mysql' );
            $format[]           = '%s';
        }
        if ( $has_col( 'template_id' ) && $template_id ) {
            $data['template_id'] = $template_id;
            $format[]            = '%d';
        }
        if ( $has_col( 'list_id' ) && $list_id ) {
            $data['list_id'] = $list_id;
            $format[]        = '%d';
        }

        // If there is a content column, copy HTML from the chosen dwmp_email template.
        if ( $has_col( 'content' ) && $template_id ) {
            $tpl_post = get_post( $template_id );
            if ( $tpl_post && 'dwmp_email' === $tpl_post->post_type ) {
                $data['content'] = $tpl_post->post_content;
                $format[]        = '%s';
            }
        }

        if ( ! empty( $data ) ) {
            $wpdb->insert( $table_name, $data, $format );
            $campaign_id = (int) $wpdb->insert_id;

            // Also store mapping into dwmp_campaign_lists if table exists and list chosen.
            if ( $campaign_id && $list_id ) {
                $cl_table = $p . 'dwmp_campaign_lists';
                $exists   = $wpdb->get_var(
                    $wpdb->prepare( "SHOW TABLES LIKE %s", $cl_table )
                );
                if ( $exists === $cl_table ) {
                    $wpdb->insert(
                        $cl_table,
                        array(
                            'campaign_id' => $campaign_id,
                            'list_id'     => $list_id,
                        ),
                        array( '%d', '%d' )
                    );
                }
            }

            $notice = 'created';
        } else {
            $notice = 'error';
        }

        wp_safe_redirect( admin_url( 'admin.php?page=dwmp-campaigns&dwmp_notice=' . urlencode( $notice ) ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Fetch campaigns for listing
    // ------------------------------------------------------------------
    $campaigns = array();

    if ( $table_exists === $table_name ) {
        $campaigns = $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY created_at DESC"
        );
    }

    // ------------------------------------------------------------------
    // Fetch email templates for the New Campaign drawer
    // ------------------------------------------------------------------
    $email_templates = get_posts( array(
        'post_type'      => 'dwmp_email',
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    // ------------------------------------------------------------------
    // Fetch subscriber lists for dropdown (best effort)
    // ------------------------------------------------------------------
    $lists              = array();
    $list_name_map      = array();
    $lists_table        = $p . 'dwmp_lists';
    $lists_table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table )
    );

    if ( $lists_table_exists === $lists_table ) {
        $lists = $wpdb->get_results( "SELECT * FROM {$lists_table} ORDER BY name ASC" );
        foreach ( $lists as $l ) {
            $lid = 0;
            if ( isset( $l->id ) ) {
                $lid = (int) $l->id;
            } elseif ( isset( $l->list_id ) ) {
                $lid = (int) $l->list_id;
            }
            if ( ! $lid ) {
                continue;
            }
            $label = '';
            if ( isset( $l->name ) && $l->name ) {
                $label = $l->name;
            } elseif ( isset( $l->title ) && $l->title ) {
                $label = $l->title;
            } else {
                $label = sprintf( __( 'List #%d', 'deepwares-mailpro' ), $lid );
            }
            $list_name_map[ $lid ] = $label;
        }
    }

    // ------------------------------------------------------------------
    // Queue stats per campaign (size, progress, failed)
    // ------------------------------------------------------------------
    $queue_stats = array();
    $queue_table = $p . 'dwmp_send_queue';
    $queue_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $queue_table )
    );

    if ( $queue_exists === $queue_table && ! empty( $campaigns ) ) {
        $ids = implode( ',', array_map( 'intval', wp_list_pluck( $campaigns, 'id' ) ) );
        if ( $ids ) {
            $rows = $wpdb->get_results("
                SELECT campaign_id,
                       COUNT(*)                         AS total,
                       SUM(status='sent')              AS sent,
                       SUM(status='failed')            AS failed
                FROM {$queue_table}
                WHERE campaign_id IN ({$ids})
                GROUP BY campaign_id
            ");
            if ( $rows ) {
                foreach ( $rows as $row ) {
                    $queue_stats[ (int) $row->campaign_id ] = $row;
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Handle notices from redirects
    // ------------------------------------------------------------------
    if ( empty( $notice ) && ! empty( $_GET['dwmp_notice'] ) ) {
        $notice = sanitize_key( $_GET['dwmp_notice'] );
    }

    ?>
    <div class="wrap dwmp-campaigns-wrap">

        <!-- Header: title + New Campaign button -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <div>
                <h1 class="wp-heading-inline" style="margin-bottom:4px;">
                    <?php esc_html_e( 'Campaigns', 'deepwares-mailpro' ); ?>
                </h1>
                <p class="description" style="margin-top:4px;">
                    <?php esc_html_e( 'Create and manage your email campaigns.', 'deepwares-mailpro' ); ?>
                </p>
            </div>
            <div>
                <button type="button"
                        id="dwmp-toggle-new-campaign"
                        class="button button-primary"
                        style="background:#020617;border-color:#020617;padding:6px 18px;border-radius:999px;display:inline-flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-plus-alt2" style="margin-top:-1px;"></span>
                    <?php esc_html_e( 'New Campaign', 'deepwares-mailpro' ); ?>
                </button>
            </div>
        </div>

        <?php if ( 'created' === $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign created.', 'deepwares-mailpro' ); ?></p></div>
        <?php elseif ( 'sent' === $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign queued / marked as scheduled.', 'deepwares-mailpro' ); ?></p></div>
        <?php elseif ( 'duplicated' === $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign duplicated.', 'deepwares-mailpro' ); ?></p></div>
        <?php elseif ( 'deleted' === $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Campaign deleted.', 'deepwares-mailpro' ); ?></p></div>
        <?php elseif ( 'error' === $notice ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Unable to create campaign. Please check your campaign table columns.', 'deepwares-mailpro' ); ?></p></div>
        <?php endif; ?>

        <!-- New Campaign drawer -->
        <section id="dwmp-new-campaign-drawer"
                 style="margin-bottom:20px; display:none;">
            <div style="background:#ffffff;border-radius:16px;border:1px solid #e5e7eb;padding:18px 20px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h2 style="margin:0;font-size:16px;"><?php esc_html_e( 'Create New Campaign', 'deepwares-mailpro' ); ?></h2>
                    <button type="button"
                            class="button button-link"
                            id="dwmp-close-new-campaign">
                        <?php esc_html_e( 'Close', 'deepwares-mailpro' ); ?>
                    </button>
                </div>
                <p class="description" style="margin-top:0;margin-bottom:14px;">
                    <?php esc_html_e( 'Name your campaign, choose an email template and a subscriber list, and we’ll create a draft campaign entry for you.', 'deepwares-mailpro' ); ?>
                </p>

                <form method="post">
                    <?php wp_nonce_field( 'dwmp_new_campaign' ); ?>
                    <input type="hidden" name="dwmp_new_campaign" value="1" />

                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row">
                                <label for="dwmp_campaign_name"><?php esc_html_e( 'Campaign Name', 'deepwares-mailpro' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="dwmp_campaign_name"
                                       id="dwmp_campaign_name"
                                       class="regular-text"
                                       required
                                       placeholder="<?php esc_attr_e( 'Welcome Series – Week 1', 'deepwares-mailpro' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dwmp_campaign_subject"><?php esc_html_e( 'Email Subject', 'deepwares-mailpro' ); ?></label>
                            </th>
                            <td>
                                <input type="text"
                                       name="dwmp_campaign_subject"
                                       id="dwmp_campaign_subject"
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Welcome to our community! 🎉', 'deepwares-mailpro' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dwmp_campaign_description"><?php esc_html_e( 'Description (internal)', 'deepwares-mailpro' ); ?></label>
                            </th>
                            <td>
                                <textarea name="dwmp_campaign_description"
                                          id="dwmp_campaign_description"
                                          rows="3"
                                          class="large-text"
                                          placeholder="<?php esc_attr_e( 'Short internal note – this is not visible to subscribers.', 'deepwares-mailpro' ); ?>"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dwmp_email_template"><?php esc_html_e( 'Email Template', 'deepwares-mailpro' ); ?></label>
                            </th>
                            <td>
                                <?php if ( ! empty( $email_templates ) ) : ?>
                                    <select name="dwmp_email_template" id="dwmp_email_template" class="regular-text" style="max-width:320px;">
                                        <option value=""><?php esc_html_e( '— Select a template —', 'deepwares-mailpro' ); ?></option>
                                        <?php foreach ( $email_templates as $tpl ) : ?>
                                            <option value="<?php echo esc_attr( $tpl->ID ); ?>">
                                                <?php
                                                printf(
                                                    '%1$s (%2$s)',
                                                    esc_html( get_the_title( $tpl ) ?: __( 'Untitled email', 'deepwares-mailpro' ) ),
                                                    esc_html( ucfirst( $tpl->post_status ) )
                                                );
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" style="margin-top:4px;">
                                        <?php esc_html_e( 'Templates are created in the Email Builder (dwmp_email).', 'deepwares-mailpro' ); ?>
                                    </p>
                                <?php else : ?>
                                    <p style="margin-top:4px;">
                                        <?php esc_html_e( 'No email templates found yet. Create one from the Email Builder page first.', 'deepwares-mailpro' ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="dwmp_campaign_list_id"><?php esc_html_e( 'Subscriber List', 'deepwares-mailpro' ); ?></label>
                            </th>
                            <td>
                                <?php if ( ! empty( $lists ) ) : ?>
                                    <select name="dwmp_campaign_list_id" id="dwmp_campaign_list_id" class="regular-text" style="max-width:320px;">
                                        <option value=""><?php esc_html_e( '— Select a list —', 'deepwares-mailpro' ); ?></option>
                                        <?php foreach ( $lists as $l ) : ?>
                                            <?php
                                            $lid = 0;
                                            if ( isset( $l->id ) ) {
                                                $lid = (int) $l->id;
                                            } elseif ( isset( $l->list_id ) ) {
                                                $lid = (int) $l->list_id;
                                            }
                                            if ( ! $lid ) {
                                                continue;
                                            }
                                            $label = '';
                                            if ( isset( $l->name ) && $l->name ) {
                                                $label = $l->name;
                                            } elseif ( isset( $l->title ) && $l->title ) {
                                                $label = $l->title;
                                            } else {
                                                $label = sprintf( __( 'List #%d', 'deepwares-mailpro' ), $lid );
                                            }
                                            ?>
                                            <option value="<?php echo esc_attr( $lid ); ?>">
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" style="margin-top:4px;">
                                        <?php esc_html_e( 'Choose which subscriber list this campaign should target. If none is chosen, all active subscribers will receive it.', 'deepwares-mailpro' ); ?>
                                    </p>
                                <?php else : ?>
                                    <p style="margin-top:4px;">
                                        <?php esc_html_e( 'No subscriber lists found. You can still create the campaign and assign a list later.', 'deepwares-mailpro' ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <?php submit_button( __( 'Create Campaign', 'deepwares-mailpro' ), 'primary', 'submit', false ); ?>
                </form>
            </div>
        </section>

        <!-- Campaign list -->
        <section style="display:flex; flex-direction:column; gap:18px; margin-top:10px;">

            <?php if ( ! empty( $campaigns ) ) : ?>
                <?php foreach ( $campaigns as $c ) : ?>
                    <?php
                    $name        = isset( $c->name )        ? $c->name        : __( '(Untitled campaign)', 'deepwares-mailpro' );
                    $subject     = isset( $c->subject )     ? $c->subject     : '';
                    $description = isset( $c->description ) ? $c->description : '';
                    $status      = isset( $c->status )      ? $c->status      : 'draft';
                    $created_at  = isset( $c->created_at )  ? $c->created_at  : '';
                    $sent_at     = isset( $c->scheduled_at )? $c->scheduled_at: '';

                    $created_str = $created_at ? date_i18n( get_option( 'date_format' ), strtotime( $created_at ) ) : '';
                    $sent_str    = $sent_at    ? date_i18n( get_option( 'date_format' ), strtotime( $sent_at ) )     : '';

                    $sent_count  = isset( $c->sent_count )  ? intval( $c->sent_count )  : 0;
                    $open_rate   = isset( $c->open_rate )   ? floatval( $c->open_rate ) : 0;
                    $click_rate  = isset( $c->click_rate )  ? floatval( $c->click_rate ): 0;

                    $list_label = '';
                    if ( isset( $c->list_id ) && $c->list_id && isset( $list_name_map[ $c->list_id ] ) ) {
                        $list_label = $list_name_map[ $c->list_id ];
                    }

                    $pill_bg      = '#fee2e2';
                    $pill_fg      = '#991b1b';
                    $status_label = ucfirst( $status );

                    if ( 'sent' === $status ) {
                        $pill_bg = '#dcfce7';
                        $pill_fg = '#15803d';
                    } elseif ( 'scheduled' === $status ) {
                        $pill_bg = '#e0f2fe';
                        $pill_fg = '#0369a1';
                    } elseif ( 'sending' === $status ) {
                        $pill_bg = '#fef3c7';
                        $pill_fg = '#92400e';
                    }

                    $dup_url = wp_nonce_url(
                        admin_url( 'admin.php?page=dwmp-campaigns&dwmp_campaign_action=duplicate&id=' . $c->id ),
                        'dwmp_duplicate_campaign_' . $c->id
                    );
                    $del_url = wp_nonce_url(
                        admin_url( 'admin.php?page=dwmp-campaigns&dwmp_campaign_action=delete&id=' . $c->id ),
                        'dwmp_delete_campaign_' . $c->id
                    );

                    // Queue stats for this campaign
                    $queue_total  = 0;
                    $queue_sent   = 0;
                    $queue_failed = 0;
                    $queue_pct    = 0;

                    if ( isset( $queue_stats[ $c->id ] ) ) {
                        $qs = $queue_stats[ $c->id ];
                        $queue_total  = (int) $qs->total;
                        $queue_sent   = (int) $qs->sent;
                        $queue_failed = (int) $qs->failed;
                        if ( $queue_total > 0 ) {
                            $queue_pct = (int) round( ( $queue_sent / $queue_total ) * 100 );
                            if ( $queue_pct < 0 ) {
                                $queue_pct = 0;
                            }
                            if ( $queue_pct > 100 ) {
                                $queue_pct = 100;
                            }
                        }
                    }
                    ?>
                    <div style="
                        background:#ffffff;
                        border-radius:18px;
                        border:1px solid #e5e7eb;
                        padding:18px 20px;
                        display:flex;
                        align-items:flex-start;
                        gap:18px;
                    ">
                        <!-- Icon -->
                        <div style="
                            width:42px;
                            height:42px;
                            border-radius:999px;
                            background:#e5edff;
                            display:flex;
                            align-items:center;
                            justify-content:center;
                            flex-shrink:0;">
                            <span class="dashicons dashicons-email-alt"
                                  style="font-size:22px;color:#1d4ed8;"></span>
                        </div>

                        <!-- Main content -->
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                                <div style="min-width:0;">
                                    <div style="font-weight:600; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?php echo esc_html( $name ); ?>
                                    </div>
                                    <?php if ( $subject ) : ?>
                                        <div style="font-size:12px; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <?php echo esc_html( $subject ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <span style="
                                    display:inline-flex;
                                    align-items:center;
                                    padding:2px 9px;
                                    border-radius:999px;
                                    font-size:11px;
                                    font-weight:500;
                                    background:<?php echo esc_attr( $pill_bg ); ?>;
                                    color:<?php echo esc_attr( $pill_fg ); ?>;
                                    white-space:nowrap;
                                ">
                                    <?php echo esc_html( $status_label ); ?>
                                </span>
                            </div>

                            <?php if ( $description ) : ?>
                                <p style="margin:8px 0 6px; font-size:12px; color:#4b5563;">
                                    <?php echo esc_html( $description ); ?>
                                </p>
                            <?php else : ?>
                                <p style="margin:8px 0 6px; font-size:12px; color:#9ca3af;">
                                    <?php esc_html_e( 'Add a short summary for this campaign.', 'deepwares-mailpro' ); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Tags row: subscriber list label if we have one -->
                            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px;">
                                <?php if ( $list_label ) : ?>
                                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#eff6ff; color:#1d4ed8;">
                                        <?php echo esc_html( $list_label ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; background:#eff6ff; color:#1d4ed8;">
                                        <?php esc_html_e( 'Newsletter Subscribers', 'deepwares-mailpro' ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Meta & stats row -->
                            <div style="display:flex; flex-wrap:wrap; gap:14px; font-size:11px; color:#6b7280; margin-top:4px;">
                                <?php if ( $created_str ) : ?>
                                    <span>
                                        <span class="dashicons dashicons-calendar-alt" style="font-size:12px;vertical-align:middle;"></span>
                                        <?php
                                        printf(
                                            esc_html__( 'Created %s', 'deepwares-mailpro' ),
                                            esc_html( $created_str )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ( $sent_str ) : ?>
                                    <span>
                                        <?php
                                        printf(
                                            esc_html__( 'Scheduled %s', 'deepwares-mailpro' ),
                                            esc_html( $sent_str )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>

                                <span>
                                    <?php
                                    printf(
                                        esc_html__( 'Sent (summary): %s', 'deepwares-mailpro' ),
                                        esc_html( number_format_i18n( $sent_count ) )
                                    );
                                    ?>
                                </span>
                                <span>
                                    <?php
                                    printf(
                                        esc_html__( 'Opened: %s%%', 'deepwares-mailpro' ),
                                        esc_html( number_format_i18n( $open_rate, 1 ) )
                                    );
                                    ?>
                                </span>
                                <span>
                                    <?php
                                    printf(
                                        esc_html__( 'Clicked: %s%%', 'deepwares-mailpro' ),
                                        esc_html( number_format_i18n( $click_rate, 1 ) )
                                    );
                                    ?>
                                </span>
                            </div>

                            <!-- Queue progress + error stats -->
                            <?php if ( $queue_total > 0 ) : ?>
                                <div style="margin-top:8px;">
                                    <div style="display:flex;justify-content:space-between;font-size:11px;color:#6b7280;margin-bottom:3px;">
                                        <span>
                                            <?php
                                            printf(
                                                esc_html__( 'Queue: %1$s of %2$s sent', 'deepwares-mailpro' ),
                                                esc_html( number_format_i18n( $queue_sent ) ),
                                                esc_html( number_format_i18n( $queue_total ) )
                                            );
                                            ?>
                                        </span>
                                        <?php if ( $queue_failed > 0 ) : ?>
                                            <span style="color:#b91c1c;">
                                                <?php
                                                printf(
                                                    esc_html__( '%s failed', 'deepwares-mailpro' ),
                                                    esc_html( number_format_i18n( $queue_failed ) )
                                                );
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="height:5px;border-radius:999px;background:#e5e7eb;overflow:hidden;">
                                        <div style="width:<?php echo esc_attr( $queue_pct ); ?>%;height:100%;background:#22c55e;"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right-hand actions -->
                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex-shrink:0;">
                            <!-- Send: open modal -->
                            <button type="button"
                                    class="button button-primary dwmp-open-send-modal"
                                    data-campaign-id="<?php echo esc_attr( $c->id ); ?>"
                                    style="background:#020617;border-color:#020617;border-radius:999px;padding:4px 16px;display:inline-flex;align-items:center;gap:6px;">
                                <span class="dashicons dashicons-location" style="margin-top:-1px;"></span>
                                <?php esc_html_e( 'Send', 'deepwares-mailpro' ); ?>
                            </button>

                            <!-- Duplicate / Delete -->
                            <div style="display:flex; gap:6px; margin-top:4px;">
                                <a href="<?php echo esc_url( $dup_url ); ?>"
                                   class="button button-small"
                                   title="<?php esc_attr_e( 'Duplicate', 'deepwares-mailpro' ); ?>">
                                    <span class="dashicons dashicons-admin-page"></span>
                                </a>
                                <a href="<?php echo esc_url( $del_url ); ?>"
                                   class="button button-small"
                                   title="<?php esc_attr_e( 'Delete', 'deepwares-mailpro' ); ?>"
                                   onclick="return confirm('<?php echo esc_js( __( 'Delete this campaign?', 'deepwares-mailpro' ) ); ?>');">
                                    <span class="dashicons dashicons-trash" style="color:#ef4444;"></span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else : ?>

                <!-- Empty state -->
                <div style="
                    background:#ffffff;
                    border-radius:18px;
                    border:1px dashed #e5e7eb;
                    padding:28px;
                    text-align:center;
                    color:#6b7280;
                ">
                    <p style="margin:0 0 8px;font-size:14px;">
                        <?php esc_html_e( 'You haven’t created any campaigns yet.', 'deepwares-mailpro' ); ?>
                    </p>
                    <p style="margin:0 0 16px;font-size:12px;color:#9ca3af;">
                        <?php esc_html_e( 'Use the New Campaign button above to create your first campaign from an email template.', 'deepwares-mailpro' ); ?>
                    </p>
                    <button type="button"
                           class="button button-primary"
                           id="dwmp-empty-start"
                           style="background:#020617;border-color:#020617;border-radius:999px;padding:6px 18px;">
                        + <?php esc_html_e( 'Create Your First Campaign', 'deepwares-mailpro' ); ?>
                    </button>
                </div>

            <?php endif; ?>

        </section>
    </div>

    <!-- SEND MODAL -->
    <div id="dwmp-send-modal"
         style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.55);">
        <div style="max-width:420px;margin:10vh auto;background:#ffffff;border-radius:16px;padding:20px 22px;box-shadow:0 20px 40px rgba(15,23,42,0.35);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <h2 style="margin:0;font-size:16px;"><?php esc_html_e( 'Send Campaign', 'deepwares-mailpro' ); ?></h2>
                <button type="button" class="button button-link" id="dwmp-send-modal-close">
                    <?php esc_html_e( 'Close', 'deepwares-mailpro' ); ?>
                </button>
            </div>
            <p style="margin-top:0;margin-bottom:14px;font-size:13px;color:#4b5563;">
                <?php esc_html_e( 'Choose whether to send immediately or schedule for later. This will create queue entries for matching subscribers.', 'deepwares-mailpro' ); ?>
            </p>

            <form method="post" id="dwmp-send-form">
                <?php wp_nonce_field( 'dwmp_send_campaign', 'dwmp_send_nonce' ); ?>
                <input type="hidden" name="dwmp_send_action" value="send" />
                <input type="hidden" name="dwmp_send_campaign_id" id="dwmp_send_campaign_id" value="" />

                <p>
                    <label>
                        <input type="radio" name="dwmp_send_mode" value="now" checked>
                        <?php esc_html_e( 'Send Now', 'deepwares-mailpro' ); ?>
                    </label><br/>
                    <label style="margin-top:4px;display:inline-block;">
                        <input type="radio" name="dwmp_send_mode" value="schedule">
                        <?php esc_html_e( 'Schedule for later', 'deepwares-mailpro' ); ?>
                    </label>
                </p>

                <p id="dwmp-schedule-row" style="margin-top:6px;display:none;">
                    <label for="dwmp_schedule_datetime" style="display:block;font-weight:500;margin-bottom:2px;">
                        <?php esc_html_e( 'Schedule date & time', 'deepwares-mailpro' ); ?>
                    </label>
                    <input type="datetime-local"
                           name="dwmp_schedule_datetime"
                           id="dwmp_schedule_datetime"
                           class="regular-text"
                           style="max-width:220px;">
                    <span class="description" style="display:block;margin-top:2px;">
                        <?php esc_html_e( 'Uses your site timezone.', 'deepwares-mailpro' ); ?>
                    </span>
                </p>

                <?php submit_button( __( 'Confirm', 'deepwares-mailpro' ), 'primary', 'submit', false ); ?>
            </form>
        </div>
    </div>

    <script>
    (function(){
        document.addEventListener('DOMContentLoaded', function(){
            var drawer   = document.getElementById('dwmp-new-campaign-drawer');
            var toggle   = document.getElementById('dwmp-toggle-new-campaign');
            var closeBtn = document.getElementById('dwmp-close-new-campaign');
            var emptyBtn = document.getElementById('dwmp-empty-start');

            function openDrawer() {
                if (!drawer) return;
                drawer.style.display = 'block';
                drawer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            function closeDrawer() {
                if (!drawer) return;
                drawer.style.display = 'none';
            }

            if (toggle)   toggle.addEventListener('click', openDrawer);
            if (emptyBtn) emptyBtn.addEventListener('click', openDrawer);
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

            // Send modal
            var sendModal      = document.getElementById('dwmp-send-modal');
            var sendClose      = document.getElementById('dwmp-send-modal-close');
            var sendCampaignId = document.getElementById('dwmp_send_campaign_id');
            var sendButtons    = document.querySelectorAll('.dwmp-open-send-modal');
            var modeRadios     = document.querySelectorAll('input[name="dwmp_send_mode"]');
            var scheduleRow    = document.getElementById('dwmp-schedule-row');

            function openSendModal(campaignId) {
                if (!sendModal || !sendCampaignId) return;
                sendCampaignId.value = campaignId || '';
                sendModal.style.display = 'block';
            }
            function closeSendModal() {
                if (!sendModal) return;
                sendModal.style.display = 'none';
            }

            if (sendButtons && sendButtons.length) {
                sendButtons.forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var cid = this.getAttribute('data-campaign-id');
                        openSendModal(cid);
                    });
                });
            }

            if (sendClose) {
                sendClose.addEventListener('click', closeSendModal);
            }
            // Click on overlay to close
            if (sendModal) {
                sendModal.addEventListener('click', function(e){
                    if (e.target === sendModal) {
                        closeSendModal();
                    }
                });
            }

            // Toggle schedule datetime field
            if (modeRadios && scheduleRow) {
                modeRadios.forEach(function(radio){
                    radio.addEventListener('change', function(){
                        if (this.value === 'schedule') {
                            scheduleRow.style.display = 'block';
                        } else {
                            scheduleRow.style.display = 'none';
                        }
                    });
                });
            }
        });
    })();
    </script>
    <?php
}

endif;
