<?php
// admin/Lists.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dwmp_render_lists_page' ) ) :

/**
 * Subscriber Lists page
 * - Keeps the existing UI/cards
 * - Fixes:
 *   • nothing rendering because there was only a namespaced class
 *   • query breaking when dwmp_subscriber_lists table doesn't exist
 */
function dwmp_render_lists_page() {
    global $wpdb;
    $p = $wpdb->prefix;

    $lists_table        = $p . 'dwmp_lists';
    $sub_lists_table    = $p . 'dwmp_subscriber_lists';

    $lists_table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $lists_table )
    );
    $sub_table_exists   = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $sub_lists_table )
    );

    // Helper: color from list name
    $get_list_color = function( $list_name ) {
        $hash = md5( $list_name );
        $r = hexdec( substr( $hash, 0, 2 ) );
        $g = hexdec( substr( $hash, 2, 2 ) );
        $b = hexdec( substr( $hash, 4, 2 ) );

        // Clamp to avoid extremes
        $r = min( 200, max( 60, $r ) );
        $g = min( 200, max( 60, $g ) );
        $b = min( 200, max( 60, $b ) );

        return "rgb({$r},{$g},{$b})";
    };

    // Helper: dashicon based on name keywords
    $get_list_icon = function( $list_name ) {
        $name = strtolower( $list_name );
        if ( strpos( $name, 'newsletter' ) !== false ) return 'email-alt';
        if ( strpos( $name, 'premium' )   !== false ) return 'star-filled';
        if ( strpos( $name, 'update' )    !== false || strpos( $name, 'product' ) !== false ) return 'archive';
        if ( strpos( $name, 'member' )    !== false ) return 'groups';
        if ( strpos( $name, 'lead' )      !== false ) return 'admin-users';
        return 'list-view';
    };

    // ------------------------------------------------------------------
    // Bail early if the main lists table is missing
    // ------------------------------------------------------------------
    if ( $lists_table_exists !== $lists_table ) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Subscriber Lists', 'deepwares-mailpro' ); ?></h1>
            <div class="notice notice-error">
                <p>
                    <?php esc_html_e( 'The dwmp_lists table does not exist. Please run the plugin installer / migration to create required tables.', 'deepwares-mailpro' ); ?>
                </p>
            </div>
        </div>
        <?php
        return;
    }

    // ------------------------------------------------------------------
    // Handle Add List
    // ------------------------------------------------------------------
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['dwmp_add_list'] )
        && check_admin_referer( 'dwmp_add_list' )
    ) {
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $desc = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( ! empty( $name ) ) {
            $wpdb->insert(
                $lists_table,
                array(
                    'name'        => $name,
                    'description' => $desc,
                    'created_at'  => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s' )
            );
            echo '<div class="updated"><p>' . esc_html__( 'List created.', 'deepwares-mailpro' ) . '</p></div>';
        } else {
            echo '<div class="error"><p>' . esc_html__( 'List name is required.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Handle Edit List
    // ------------------------------------------------------------------
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['dwmp_edit_list'] )
        && check_admin_referer( 'dwmp_edit_list' )
    ) {
        $id   = isset( $_POST['list_id'] ) ? intval( $_POST['list_id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $desc = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( $id && ! empty( $name ) ) {
            $wpdb->update(
                $lists_table,
                array(
                    'name'        => $name,
                    'description' => $desc,
                ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            echo '<div class="updated"><p>' . esc_html__( 'List updated.', 'deepwares-mailpro' ) . '</p></div>';
        } else {
            echo '<div class="error"><p>' . esc_html__( 'List name is required.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Handle Delete List
    // ------------------------------------------------------------------
    if (
        isset( $_GET['delete'] )
        && check_admin_referer( 'dwmp_delete_list' )
    ) {
        $id = intval( $_GET['delete'] );

        if ( $id ) {
            $wpdb->delete( $lists_table, array( 'id' => $id ), array( '%d' ) );

            // Only delete from subscriber_lists if that table exists
            if ( $sub_table_exists === $sub_lists_table ) {
                $wpdb->delete( $sub_lists_table, array( 'list_id' => $id ), array( '%d' ) );
            }

            echo '<div class="updated"><p>' . esc_html__( 'List deleted.', 'deepwares-mailpro' ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Fetch Lists with subscriber counts (defensive JOIN)
    // ------------------------------------------------------------------
    if ( $sub_table_exists === $sub_lists_table ) {
        // Normal case: subscriber bridge table exists
        $lists = $wpdb->get_results(
            "
            SELECT l.*, COUNT(sl.subscriber_id) AS subscriber_count
            FROM {$lists_table} l
            LEFT JOIN {$sub_lists_table} sl ON l.id = sl.list_id
            GROUP BY l.id
            ORDER BY l.created_at DESC
            "
        );
    } else {
        // Fallback: show lists anyway, but count is 0
        $lists = $wpdb->get_results(
            "
            SELECT l.*, 0 AS subscriber_count
            FROM {$lists_table} l
            ORDER BY l.created_at DESC
            "
        );
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Subscriber Lists', 'deepwares-mailpro' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Organize subscribers into different segments', 'deepwares-mailpro' ); ?></p>

        <!-- Create List button -->
        <button id="dwmp-create-list-btn" class="button button-black">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( 'Create List', 'deepwares-mailpro' ); ?>
        </button>

        <!-- Create List Modal -->
        <div id="dwmp-create-list-modal" class="dwmp-modal" style="display:none;">
            <div class="dwmp-modal-content dwmp-card">
                <button type="button" class="button-link dwmp-modal-close" aria-label="<?php esc_attr_e( 'Close', 'deepwares-mailpro' ); ?>">&times;</button>
                <form method="post">
                    <?php wp_nonce_field( 'dwmp_add_list' ); ?>
                    <h2 class="dwmp-card-title"><?php esc_html_e( 'Create New List', 'deepwares-mailpro' ); ?></h2>
                    <p class="dwmp-field">
                        <label>
                            <?php esc_html_e( 'List Name', 'deepwares-mailpro' ); ?><br>
                            <input type="text" name="name" required class="regular-text">
                        </label>
                    </p>
                    <p class="dwmp-field">
                        <label>
                            <?php esc_html_e( 'Description', 'deepwares-mailpro' ); ?><br>
                            <textarea name="description" rows="3" class="large-text"></textarea>
                        </label>
                    </p>
                    <?php submit_button( __( 'Create List', 'deepwares-mailpro' ), 'primary button-black', 'dwmp_add_list' ); ?>
                </form>
            </div>
        </div>

        <!-- Edit List Modal -->
        <div id="dwmp-edit-list-modal" class="dwmp-modal" style="display:none;">
            <div class="dwmp-modal-content dwmp-card">
                <button type="button" class="button-link dwmp-modal-close" aria-label="<?php esc_attr_e( 'Close', 'deepwares-mailpro' ); ?>">&times;</button>
                <form method="post">
                    <?php wp_nonce_field( 'dwmp_edit_list' ); ?>
                    <input type="hidden" name="list_id" id="dwmp-edit-id">
                    <h2 class="dwmp-card-title"><?php esc_html_e( 'Edit List', 'deepwares-mailpro' ); ?></h2>
                    <p class="dwmp-field">
                        <label>
                            <?php esc_html_e( 'List Name', 'deepwares-mailpro' ); ?><br>
                            <input type="text" name="name" id="dwmp-edit-name" required class="regular-text">
                        </label>
                    </p>
                    <p class="dwmp-field">
                        <label>
                            <?php esc_html_e( 'Description', 'deepwares-mailpro' ); ?><br>
                            <textarea name="description" id="dwmp-edit-desc" rows="3" class="large-text"></textarea>
                        </label>
                    </p>
                    <?php submit_button( __( 'Update List', 'deepwares-mailpro' ), 'primary', 'dwmp_edit_list' ); ?>
                </form>
            </div>
        </div>

        <!-- Lists Grid -->
        <div class="dwmp-lists-grid">
            <?php if ( ! empty( $lists ) ) : ?>
                <?php foreach ( $lists as $l ) : ?>
                    <?php
                    $color = $get_list_color( $l->name );
                    $icon  = $get_list_icon( $l->name );
                    $count = intval( $l->subscriber_count );
                    ?>
                    <div class="dwmp-list-card">
                        <div class="dwmp-list-icon" style="background:<?php echo esc_attr( $color ); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
                        </div>

                        <div class="dwmp-list-content">
                            <h3 class="dwmp-list-title"><?php echo esc_html( $l->name ); ?></h3>
                            <?php if ( ! empty( $l->description ) ) : ?>
                                <p class="description"><?php echo esc_html( $l->description ); ?></p>
                            <?php endif; ?>
                            <p class="dwmp-list-stats">
                                <strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
                                <?php esc_html_e( 'Subscribers', 'deepwares-mailpro' ); ?>
                            </p>
                            <?php if ( ! empty( $l->created_at ) ) : ?>
                                <small class="dwmp-list-created">
                                    <?php esc_html_e( 'Created:', 'deepwares-mailpro' ); ?>
                                    <?php echo esc_html( $l->created_at ); ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="dwmp-list-actions">
                            <button
                                type="button"
                                class="button-link dwmp-edit-btn"
                                data-id="<?php echo esc_attr( $l->id ); ?>"
                                data-name="<?php echo esc_attr( $l->name ); ?>"
                                data-desc="<?php echo esc_attr( $l->description ); ?>"
                                title="<?php esc_attr_e( 'Edit', 'deepwares-mailpro' ); ?>">
                                <span class="dashicons dashicons-edit"></span>
                            </button>

                            <a
                                href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dwmp-lists&delete=' . $l->id ), 'dwmp_delete_list' ) ); ?>"
                                class="button-link dwmp-delete-btn"
                                onclick="return confirm('<?php echo esc_js( __( 'Delete this list? Subscribers will simply lose this association.', 'deepwares-mailpro' ) ); ?>');"
                                title="<?php esc_attr_e( 'Delete', 'deepwares-mailpro' ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p><?php esc_html_e( 'No lists found. Create your first list above.', 'deepwares-mailpro' ); ?></p>
            <?php endif; ?>
        </div>

        <style>
            .button-black {
                background:#000 !important;
                color:#fff !important;
                border-color:#000 !important;
            }
            .dwmp-lists-grid {
                display:grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap:20px;
                margin-top:20px;
            }
            .dwmp-list-card {
                background:#fff;
                border:1px solid #ddd;
                border-radius:10px;
                padding:16px;
                display:grid;
                grid-template-columns: 56px 1fr auto;
                gap:12px;
                align-items:start;
            }
            .dwmp-list-icon {
                width:56px;
                height:56px;
                border-radius:50%;
                display:flex;
                align-items:center;
                justify-content:center;
                color:#fff;
                font-size:24px;
            }
            .dwmp-list-content {
                display:flex;
                flex-direction:column;
                gap:6px;
            }
            .dwmp-list-title {
                margin:0;
                font-size:16px;
            }
            .dwmp-list-stats {
                margin:0;
                font-weight:500;
            }
            .dwmp-list-actions {
                display:flex;
                gap:8px;
            }
            .dwmp-list-actions .dashicons {
                font-size:18px;
            }
            /* Modal styles */
            .dwmp-modal {
                position:fixed;
                inset:0;
                background:rgba(0,0,0,0.35);
                display:flex;
                align-items:center;
                justify-content:center;
                z-index:1000;
            }
            .dwmp-modal-content {
                position:relative;
                max-width:520px;
                width:clamp(320px, 90vw, 520px);
            }
            .dwmp-modal-close {
                position:absolute;
                top:10px; right:14px;
                font-size:20px;
                color:#666;
            }
            .dwmp-card {
                background:#fff;
                border:1px solid #ddd;
                border-radius:8px;
                padding:16px;
            }
            .dwmp-card-title {
                margin-top:0;
            }
            .dwmp-field {
                margin-bottom:12px;
            }
            @media (max-width: 640px) {
                .dwmp-list-card { grid-template-columns: 40px 1fr; }
                .dwmp-list-actions { grid-column: 1 / -1; justify-content:flex-end; }
                .dwmp-list-icon { width:40px; height:40px; }
            }
        </style>

        <script>
        (function(){
            // Toggle create modal
            var createBtn   = document.getElementById('dwmp-create-list-btn');
            var createModal = document.getElementById('dwmp-create-list-modal');

            if (createBtn && createModal) {
                createBtn.addEventListener('click', function(){
                    createModal.style.display = 'flex';
                });
                var closeCreate = createModal.querySelector('.dwmp-modal-close');
                if (closeCreate) {
                    closeCreate.addEventListener('click', function(){
                        createModal.style.display = 'none';
                    });
                }
                createModal.addEventListener('click', function(e){
                    if (e.target === createModal) {
                        createModal.style.display = 'none';
                    }
                });
            }

            // Edit modal
            var editModal = document.getElementById('dwmp-edit-list-modal');
            var editId    = document.getElementById('dwmp-edit-id');
            var editName  = document.getElementById('dwmp-edit-name');
            var editDesc  = document.getElementById('dwmp-edit-desc');

            document.querySelectorAll('.dwmp-edit-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var id   = btn.getAttribute('data-id');
                    var name = btn.getAttribute('data-name') || '';
                    var desc = btn.getAttribute('data-desc') || '';

                    if (editId && editName && editDesc && editModal) {
                        editId.value   = id;
                        editName.value = name;
                        editDesc.value = desc;
                        editModal.style.display = 'flex';
                    }
                });
            });

            if (editModal) {
                var closeEdit = editModal.querySelector('.dwmp-modal-close');
                if (closeEdit) {
                    closeEdit.addEventListener('click', function(){
                        editModal.style.display = 'none';
                    });
                }
                editModal.addEventListener('click', function(e){
                    if (e.target === editModal) {
                        editModal.style.display = 'none';
                    }
                });
            }
        })();
        </script>
    </div>
    <?php
}

endif;
