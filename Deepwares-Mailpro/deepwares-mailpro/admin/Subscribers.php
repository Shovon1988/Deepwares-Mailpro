<?php
// admin/Subscribers.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dwmp_render_subscribers_page' ) ) :

/**
 * Subscribers admin page
 * - Keeps the existing UI/UX
 * - Fixes "blank page" by providing a global function
 * - Is defensive about missing DB tables
 */
function dwmp_render_subscribers_page() {
    global $wpdb;
    $p = $wpdb->prefix;

    $subs_table         = $p . 'dwmp_subscribers';
    $sub_lists_table    = $p . 'dwmp_subscriber_lists';
    $lists_table        = $p . 'dwmp_lists';

    $subs_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subs_table ) );
    $sub_lists_exists  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sub_lists_table ) );
    $lists_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table ) );

    // Helper: color from list name
    $get_list_color = function( $list_name ) {
        $hash = md5( $list_name );
        $r = hexdec( substr( $hash, 0, 2 ) );
        $g = hexdec( substr( $hash, 2, 2 ) );
        $b = hexdec( substr( $hash, 4, 2 ) );
        // Lighten and clamp to avoid extremes
        $r = min( 200, max( 60, $r ) );
        $g = min( 200, max( 60, $g ) );
        $b = min( 200, max( 60, $b ) );
        return "rgb({$r},{$g},{$b})";
    };

    // ------------------------------------------------------------------
    // Bail out early if the main subscribers table does not exist
    // ------------------------------------------------------------------
    if ( $subs_table_exists !== $subs_table ) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Subscribers', 'deepwares-mailpro' ); ?></h1>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e( 'The dwmp_subscribers table does not exist. Please run the plugin installer / migration to create required tables.', 'deepwares-mailpro' ); ?>
                </p>
            </div>
        </div>
        <?php
        return;
    }

    // ------------------------------------------------------------------
    // Handle Add Subscriber
    // ------------------------------------------------------------------
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['dwmp_add_subscriber'] )
        && check_admin_referer( 'dwmp_add_subscriber' )
    ) {
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $name  = isset( $_POST['name'] )  ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( ! empty( $email ) && is_email( $email ) ) {
            $token = wp_generate_password( 32, false );
            $wpdb->insert(
                $subs_table,
                array(
                    'email'             => $email,
                    'name'              => $name,
                    'status'            => 'active',
                    'unsubscribe_token' => $token,
                    'created_at'        => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
            $subscriber_id = (int) $wpdb->insert_id;

            if ( $subscriber_id && ! empty( $_POST['lists'] ) && is_array( $_POST['lists'] ) && $sub_lists_exists === $sub_lists_table ) {
                foreach ( $_POST['lists'] as $list_id ) {
                    $wpdb->insert(
                        $sub_lists_table,
                        array(
                            'subscriber_id' => $subscriber_id,
                            'list_id'       => intval( $list_id ),
                        ),
                        array( '%d', '%d' )
                    );
                }
            }
            echo '<div class="updated"><p>' . esc_html__( 'Subscriber added.', 'deepwares-mailpro' ) . '</p></div>';
        } else {
            echo '<div class="error"><p>' . esc_html__( 'Invalid email address.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Handle single delete
    // ------------------------------------------------------------------
    if ( isset( $_GET['delete'] ) && check_admin_referer( 'dwmp_delete_subscriber' ) ) {
        $id = intval( $_GET['delete'] );
        if ( $id ) {
            $wpdb->delete( $subs_table, array( 'id' => $id ), array( '%d' ) );
            if ( $sub_lists_exists === $sub_lists_table ) {
                $wpdb->delete( $sub_lists_table, array( 'subscriber_id' => $id ), array( '%d' ) );
            }
            echo '<div class="updated"><p>' . esc_html__( 'Subscriber deleted.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Handle bulk actions
    // ------------------------------------------------------------------
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['do_bulk'] )
        && check_admin_referer( 'dwmp_bulk_action' )
    ) {
        $ids     = array_map( 'intval', $_POST['subscriber_ids'] ?? array() );
        $action  = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $list_id = isset( $_POST['bulk_list_id'] ) ? intval( $_POST['bulk_list_id'] ) : 0;

        if ( $ids ) {
            if ( 'delete' === $action ) {
                foreach ( $ids as $id ) {
                    $wpdb->delete( $subs_table, array( 'id' => $id ), array( '%d' ) );
                    if ( $sub_lists_exists === $sub_lists_table ) {
                        $wpdb->delete( $sub_lists_table, array( 'subscriber_id' => $id ), array( '%d' ) );
                    }
                }
                echo '<div class="updated"><p>' . esc_html__( 'Subscribers deleted.', 'deepwares-mailpro' ) . '</p></div>';
            } elseif ( 'assign_list' === $action && $list_id && $sub_lists_exists === $sub_lists_table ) {
                foreach ( $ids as $id ) {
                    $wpdb->replace(
                        $sub_lists_table,
                        array(
                            'subscriber_id' => $id,
                            'list_id'       => $list_id,
                        ),
                        array( '%d', '%d' )
                    );
                }
                echo '<div class="updated"><p>' . esc_html__( 'Subscribers assigned to list.', 'deepwares-mailpro' ) . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html__( 'Please choose a valid bulk action and list (if assigning).', 'deepwares-mailpro' ) . '</p></div>';
            }
        } else {
            echo '<div class="error"><p>' . esc_html__( 'No subscribers selected.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Handle CSV import
    // ------------------------------------------------------------------
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['dwmp_import_csv'] )
        && check_admin_referer( 'dwmp_import_csv' )
    ) {
        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $list_id = isset( $_POST['import_list_id'] ) ? intval( $_POST['import_list_id'] ) : 0;
            $handle  = fopen( $_FILES['csv_file']['tmp_name'], 'r' );
            if ( $handle ) {
                $row = 0;
                while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                    $row++;
                    if ( 1 === $row ) {
                        continue; // header
                    }
                    $email = isset( $data[0] ) ? sanitize_email( $data[0] ) : '';
                    $name  = isset( $data[1] ) ? sanitize_text_field( $data[1] ) : '';

                    if ( $email && is_email( $email ) ) {
                        $exists = $wpdb->get_var(
                            $wpdb->prepare( "SELECT id FROM {$subs_table} WHERE email = %s", $email )
                        );
                        if ( ! $exists ) {
                            $token = wp_generate_password( 32, false );
                            $wpdb->insert(
                                $subs_table,
                                array(
                                    'email'             => $email,
                                    'name'              => $name,
                                    'status'            => 'active',
                                    'unsubscribe_token' => $token,
                                    'created_at'        => current_time( 'mysql' ),
                                ),
                                array( '%s', '%s', '%s', '%s', '%s' )
                            );
                            $subscriber_id = (int) $wpdb->insert_id;

                            if ( $list_id && $subscriber_id && $sub_lists_exists === $sub_lists_table ) {
                                $wpdb->insert(
                                    $sub_lists_table,
                                    array(
                                        'subscriber_id' => $subscriber_id,
                                        'list_id'       => $list_id,
                                    ),
                                    array( '%d', '%d' )
                                );
                            }
                        }
                    }
                }
                fclose( $handle );
            }

            echo '<div class="updated"><p>' . esc_html__( 'CSV imported successfully.', 'deepwares-mailpro' ) . '</p></div>';
        } else {
            echo '<div class="error"><p>' . esc_html__( 'Please select a CSV file.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Handle CSV export
    // ------------------------------------------------------------------
    if ( isset( $_GET['dwmp_export_csv'] ) && check_admin_referer( 'dwmp_export_csv' ) ) {
        if ( $sub_lists_exists === $sub_lists_table && $lists_table_exists === $lists_table ) {
            $subscribers_export = $wpdb->get_results(
                "
                SELECT s.id, s.email, s.name, s.status, s.created_at,
                       GROUP_CONCAT(l.name SEPARATOR ';') AS lists
                FROM {$subs_table} s
                LEFT JOIN {$sub_lists_table} sl ON s.id = sl.subscriber_id
                LEFT JOIN {$lists_table} l ON sl.list_id = l.id
                GROUP BY s.id
                ORDER BY s.created_at DESC
                ",
                ARRAY_A
            );
        } else {
            // Fallback export without list names
            $subscribers_export = $wpdb->get_results(
                "
                SELECT s.id, s.email, s.name, s.status, s.created_at, '' AS lists
                FROM {$subs_table} s
                ORDER BY s.created_at DESC
                ",
                ARRAY_A
            );
        }

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment;filename=subscribers.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'ID', 'Email', 'Name', 'Status', 'Created', 'Lists' ) );
        foreach ( $subscribers_export as $s ) {
            fputcsv( $out, $s );
        }
        fclose( $out );
        exit;
    }

    // ------------------------------------------------------------------
    // Pagination + filtering
    // ------------------------------------------------------------------
    $per_page = isset( $_GET['per_page'] ) ? max( 10, intval( $_GET['per_page'] ) ) : 100;
    $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset   = ( $paged - 1 ) * $per_page;

    // list_id: 0 = All, -1 = Unassigned, >0 = specific list
    $list_filter = isset( $_GET['list_id'] ) ? intval( $_GET['list_id'] ) : 0;

    // Fetch lists for UI (if lists table exists)
    $lists = array();
    if ( $lists_table_exists === $lists_table ) {
        $lists = $wpdb->get_results( "SELECT id, name FROM {$lists_table} ORDER BY name ASC" );
    }

    // ------------------------------------------------------------------
    // Build filtered / paginated query (defensive joins)
    // ------------------------------------------------------------------
    $subscribers = array();
    $total       = 0;

    $has_join_tables = ( $sub_lists_exists === $sub_lists_table && $lists_table_exists === $lists_table );

    if ( $has_join_tables && $list_filter > 0 ) {
        // Specific list
        $subscribers = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT s.id, s.email, s.name, s.status, s.created_at,
                       GROUP_CONCAT(DISTINCT l.name SEPARATOR ', ') AS lists
                FROM {$subs_table} s
                INNER JOIN {$sub_lists_table} sl ON s.id = sl.subscriber_id
                INNERJOIN {$lists_table} l ON sl.list_id = l.id
                WHERE sl.list_id = %d
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT %d OFFSET %d
                ",
                $list_filter,
                $per_page,
                $offset
            )
        );

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(DISTINCT s.id)
                FROM {$subs_table} s
                INNER JOIN {$sub_lists_table} sl ON s.id = sl.subscriber_id
                WHERE sl.list_id = %d
                ",
                $list_filter
            )
        );

    } elseif ( $has_join_tables && -1 === $list_filter ) {
        // Unassigned subscribers (no list)
        $subscribers = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT s.id, s.email, s.name, s.status, s.created_at
                FROM {$subs_table} s
                LEFT JOIN {$sub_lists_table} sl ON s.id = sl.subscriber_id
                WHERE sl.subscriber_id IS NULL
                ORDER BY s.created_at DESC
                LIMIT %d OFFSET %d
                ",
                $per_page,
                $offset
            )
        );

        $total = (int) $wpdb->get_var(
            "
            SELECT COUNT(*)
            FROM {$subs_table} s
            LEFT JOIN {$sub_lists_table} sl ON s.id = sl.subscriber_id
            WHERE sl.subscriber_id IS NULL
            "
        );

    } elseif ( $has_join_tables ) {
        // All subscribers with list names
        $subscribers = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT s.id, s.email, s.name, s.status, s.created_at,
                       COALESCE(GROUP_CONCAT(DISTINCT l.name SEPARATOR ', '), '') AS lists
                FROM {$subs_table} s
                LEFT JOIN {$sub_lists_table} sl ON s.id = sl.subscriber_id
                LEFT JOIN {$lists_table} l ON sl.list_id = l.id
                GROUP BY s.id
                ORDER BY s.created_at DESC
                LIMIT %d OFFSET %d
                ",
                $per_page,
                $offset
            )
        );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs_table}" );
    } else {
        // Fallback: no mapping tables → show basic subscribers, no list names
        $subscribers = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT s.id, s.email, s.name, s.status, s.created_at
                FROM {$subs_table} s
                ORDER BY s.created_at DESC
                LIMIT %d OFFSET %d
                ",
                $per_page,
                $offset
            )
        );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs_table}" );
    }

    $total_pages = max( 1, ceil( $total / $per_page ) );

    // Helpers for building URLs
    $base_url    = admin_url( 'admin.php?page=dwmp-subscribers' );
    $page_params = array(
        'page'     => 'dwmp-subscribers',
        'list_id'  => $list_filter,
        'per_page' => $per_page,
    );

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Subscribers', 'deepwares-mailpro' ); ?></h1>
        <p><?php esc_html_e( 'Manage your email subscribers.', 'deepwares-mailpro' ); ?></p>

        <!-- Toolbar with icons -->
        <div class="dwmp-toolbar">
            <a href="#dwmp-add-form" class="button button-black">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e( 'Add Subscriber', 'deepwares-mailpro' ); ?>
            </a>

            <form method="post" enctype="multipart/form-data" class="dwmp-inline-form">
                <?php wp_nonce_field( 'dwmp_import_csv' ); ?>
                <label class="button dwmp-upload-btn">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e( 'Import CSV', 'deepwares-mailpro' ); ?>
                    <input type="file" name="csv_file" accept=".csv" class="dwmp-hidden-file" onchange="this.form.submit()">
                </label>
                <select name="import_list_id" class="dwmp-upload-select" title="<?php esc_attr_e( 'Assign imported subscribers to list', 'deepwares-mailpro' ); ?>">
                    <option value=""><?php esc_html_e( 'Assign to List (optional)', 'deepwares-mailpro' ); ?></option>
                    <?php if ( ! empty( $lists ) ) : ?>
                        <?php foreach ( $lists as $l ) : ?>
                            <option value="<?php echo esc_attr( $l->id ); ?>">
                                <?php echo esc_html( $l->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <input type="hidden" name="dwmp_import_csv" value="1">
            </form>

            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'dwmp_export_csv' => 1 ), $base_url ), 'dwmp_export_csv' ) ); ?>" class="button">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( 'Export CSV', 'deepwares-mailpro' ); ?>
            </a>
        </div>

        <!-- Add Subscriber form -->
        <form method="post" id="dwmp-add-form" class="dwmp-card">
            <?php wp_nonce_field( 'dwmp_add_subscriber' ); ?>
            <h2 class="dwmp-card-title"><?php esc_html_e( 'Add Subscriber', 'deepwares-mailpro' ); ?></h2>
            <div class="dwmp-grid">
                <div class="dwmp-field">
                    <label for="email"><?php esc_html_e( 'Email', 'deepwares-mailpro' ); ?></label>
                    <input type="email" name="email" id="email" required class="regular-text">
                </div>
                <div class="dwmp-field">
                    <label for="name"><?php esc_html_e( 'Name', 'deepwares-mailpro' ); ?></label>
                    <input type="text" name="name" id="name" class="regular-text">
                </div>
            </div>
            <div class="dwmp-field">
                <label><?php esc_html_e( 'Assign to Lists', 'deepwares-mailpro' ); ?></label>
                <div class="dwmp-badges">
                    <?php if ( ! empty( $lists ) ) : ?>
                        <?php foreach ( $lists as $l ) : ?>
                            <?php $color = $get_list_color( $l->name ); ?>
                            <label class="dwmp-list-chip" style="--chip-bg: <?php echo esc_attr( $color ); ?>;">
                                <input type="checkbox" name="lists[]" value="<?php echo esc_attr( $l->id ); ?>">
                                <span class="dwmp-chip-label"><?php echo esc_html( $l->name ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <em><?php esc_html_e( 'No lists available. Create one first.', 'deepwares-mailpro' ); ?></em>
                    <?php endif; ?>
                </div>
            </div>
            <?php submit_button( __( 'Add Subscriber', 'deepwares-mailpro' ), 'primary button-black', 'dwmp_add_subscriber' ); ?>
        </form>

        <!-- Filters -->
        <form method="get" class="dwmp-filters">
            <input type="hidden" name="page" value="dwmp-subscribers">
            <label for="list_id"><?php esc_html_e( 'Filter:', 'deepwares-mailpro' ); ?></label>
            <select name="list_id" id="list_id" onchange="this.form.submit()">
                <option value="0" <?php selected( $list_filter, 0 ); ?>>
                    <?php esc_html_e( 'All Subscribers', 'deepwares-mailpro' ); ?>
                </option>
                <?php if ( ! empty( $lists ) ) : ?>
                    <?php foreach ( $lists as $l ) : ?>
                        <option value="<?php echo esc_attr( $l->id ); ?>" <?php selected( $list_filter, $l->id ); ?>>
                            <?php echo esc_html( $l->name ); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ( $has_join_tables ) : ?>
                    <option value="-1" <?php selected( $list_filter, -1 ); ?>>
                        <?php esc_html_e( 'Unassigned Subscribers', 'deepwares-mailpro' ); ?>
                    </option>
                <?php endif; ?>
            </select>

            <label for="per_page"><?php esc_html_e( 'Per page:', 'deepwares-mailpro' ); ?></label>
            <input type="number" min="10" name="per_page" id="per_page" value="<?php echo esc_attr( $per_page ); ?>">
            <input type="hidden" name="paged" value="1">
            <?php submit_button( __( 'Apply', 'deepwares-mailpro' ), 'secondary', '', false ); ?>
        </form>

        <!-- Bulk actions + table -->
        <form method="post">
            <?php wp_nonce_field( 'dwmp_bulk_action' ); ?>

            <div class="tablenav top dwmp-top-actions">
                <select name="bulk_action">
                    <option value=""><?php esc_html_e( 'Bulk actions', 'deepwares-mailpro' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete', 'deepwares-mailpro' ); ?></option>
                    <?php if ( $has_join_tables ) : ?>
                        <option value="assign_list"><?php esc_html_e( 'Assign to List', 'deepwares-mailpro' ); ?></option>
                    <?php endif; ?>
                </select>
                <?php if ( $has_join_tables ) : ?>
                    <select name="bulk_list_id">
                        <option value=""><?php esc_html_e( '-- Select List --', 'deepwares-mailpro' ); ?></option>
                        <?php if ( ! empty( $lists ) ) : ?>
                            <?php foreach ( $lists as $l ) : ?>
                                <option value="<?php echo esc_attr( $l->id ); ?>"><?php echo esc_html( $l->name ); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                <?php endif; ?>
                <?php submit_button( __( 'Apply', 'deepwares-mailpro' ), 'secondary', 'do_bulk', false ); ?>
            </div>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all">
                        </td>
                        <th><?php esc_html_e( 'Email', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Subscribed', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Lists', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'deepwares-mailpro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( ! empty( $subscribers ) ) : ?>
                    <?php foreach ( $subscribers as $s ) : ?>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" name="subscriber_ids[]" value="<?php echo esc_attr( $s->id ); ?>">
                            </th>
                            <td><?php echo esc_html( $s->email ); ?></td>
                            <td><?php echo esc_html( $s->name ); ?></td>
                            <td><?php echo esc_html( $s->created_at ); ?></td>
                            <td>
                                <?php if ( 'active' === $s->status ) : ?>
                                    <span class="dwmp-badge dwmp-status-active"><?php esc_html_e( 'Active', 'deepwares-mailpro' ); ?></span>
                                <?php elseif ( 'unsubscribed' === $s->status ) : ?>
                                    <span class="dwmp-badge dwmp-status-unsubscribed"><?php esc_html_e( 'Unsubscribed', 'deepwares-mailpro' ); ?></span>
                                <?php else : ?>
                                    <span class="dwmp-badge dwmp-status-other"><?php echo esc_html( $s->status ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="dwmp-badges">
                                <?php if ( ! empty( $s->lists ) ) : ?>
                                    <?php foreach ( explode( ',', $s->lists ) as $list_name_raw ) : ?>
                                        <?php
                                        $list_name = trim( $list_name_raw );
                                        if ( '' === $list_name ) {
                                            continue;
                                        }
                                        $color = $get_list_color( $list_name );
                                        ?>
                                        <span class="dwmp-badge dwmp-list-badge" style="--list-bg: <?php echo esc_attr( $color ); ?>">
                                            <?php echo esc_html( $list_name ); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php elseif ( ! $has_join_tables ) : ?>
                                    <em><?php esc_html_e( 'Lists table missing', 'deepwares-mailpro' ); ?></em>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'None', 'deepwares-mailpro' ); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'dwmp-subscribers', 'delete' => $s->id ), admin_url( 'admin.php' ) ), 'dwmp_delete_subscriber' ) ); ?>"
                                   class="dwmp-action-delete"
                                   title="<?php esc_attr_e( 'Delete', 'deepwares-mailpro' ); ?>"
                                   onclick="return confirm('<?php echo esc_js( __( 'Delete this subscriber?', 'deepwares-mailpro' ) ); ?>');">
                                    <span class="dashicons dashicons-trash"></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No subscribers found.', 'deepwares-mailpro' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </form>

        <!-- Pagination -->
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links(
                    array(
                        'base'    => add_query_arg( array_merge( $page_params, array( 'paged' => '%#%' ) ), $base_url ),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    )
                );
                ?>
            </div>
            <div class="dwmp-count">
                <?php
                printf(
                    esc_html__( 'Showing %1$s of %2$s subscribers', 'deepwares-mailpro' ),
                    esc_html( min( $per_page, max( 0, $total - $offset ) ) ),
                    esc_html( $total )
                );
                ?>
            </div>
        </div>

        <!-- Styling -->
        <style>
            .dwmp-toolbar {
                margin: 15px 0 20px;
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .dwmp-inline-form {
                display: inline-flex;
                gap: 8px;
                align-items: center;
            }
            .dwmp-hidden-file {
                display: none;
            }
            .button-black {
                background: #000 !important;
                color: #fff !important;
                border-color: #000 !important;
            }
            .button-black:hover {
                background: #222 !important;
                border-color: #222 !important;
            }
            .dwmp-upload-btn {
                position: relative;
                overflow: hidden;
            }
            .dwmp-upload-btn .dwmp-hidden-file {
                position: absolute;
                left: 0; top: 0;
                width: 100%; height: 100%;
                opacity: 0;
                cursor: pointer;
            }
            .dwmp-upload-select {
                min-width: 200px;
            }
            .dwmp-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 16px;
                margin-bottom: 20px;
            }
            .dwmp-card-title {
                margin-top: 0;
            }
            .dwmp-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .dwmp-field {
                margin-bottom: 12px;
            }
            .dwmp-badges {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }
            .dwmp-list-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 8px;
                border-radius: 16px;
                background: var(--chip-bg, #666);
                color: #fff;
                cursor: pointer;
            }
            .dwmp-list-chip input[type="checkbox"] {
                transform: translateY(1px);
            }
            .dwmp-chip-label {
                font-size: 12px;
            }
            .dwmp-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 12px;
                color: #fff;
                line-height: 18px;
            }
            .dwmp-status-active {
                background: #28a745;
            }
            .dwmp-status-unsubscribed {
                background: #dc3545;
            }
            .dwmp-status-other {
                background: #6c757d;
            }
            .dwmp-list-badge {
                background: var(--list-bg, #777);
            }
            .dwmp-top-actions {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .dwmp-action-delete .dashicons {
                color: #a00;
            }
            .dwmp-count {
                margin-top: 8px;
            }
            @media (max-width: 900px) {
                .dwmp-grid { grid-template-columns: 1fr; }
            }
        </style>

        <!-- Scripts -->
        <script>
        (function(){
            var selectAll = document.getElementById('cb-select-all');
            if (selectAll) {
                selectAll.addEventListener('click', function(e){
                    var checked = e.target.checked;
                    document.querySelectorAll('input[name="subscriber_ids[]"]').forEach(function(cb){
                        cb.checked = checked;
                    });
                });
            }
        })();
        </script>
    </div>
    <?php
}

endif;
