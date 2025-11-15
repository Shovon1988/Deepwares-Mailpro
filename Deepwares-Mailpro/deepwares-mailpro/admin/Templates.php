<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dwmp_render_templates_page' ) ) :

function dwmp_render_templates_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'deepwares-mailpro' ) );
    }

    $notice      = '';
    $page_slug   = 'dwmp-templates';
    $post_type   = 'dwmp_email';
    $tax_name    = 'dwmp_email_category';

    // ----------------------------------------------------
    // Helpers
    // ----------------------------------------------------
    $ensure_term = function( $name ) use ( $tax_name ) {
        if ( ! $name ) {
            return 0;
        }
        $term = get_term_by( 'name', $name, $tax_name );
        if ( $term && ! is_wp_error( $term ) ) {
            return (int) $term->term_id;
        }
        $result = wp_insert_term( $name, $tax_name );
        if ( is_wp_error( $result ) ) {
            return 0;
        }
        return (int) $result['term_id'];
    };

    // ----------------------------------------------------
    // Handle POST: create template from HTML upload/paste
    // ----------------------------------------------------
    if ( ! empty( $_POST['dwmp_tpl_nonce'] ) && wp_verify_nonce( $_POST['dwmp_tpl_nonce'], 'dwmp_tpl_new' ) ) {

        $name        = sanitize_text_field( $_POST['tpl_name'] ?? '' );
        $category    = sanitize_text_field( $_POST['tpl_category'] ?? 'General' );
        $new_cat_raw = sanitize_text_field( $_POST['tpl_category_new'] ?? '' );
        $subject     = sanitize_text_field( $_POST['tpl_subject'] ?? '' );
        $preview     = sanitize_text_field( $_POST['tpl_preview'] ?? '' );
        $html        = '';

        if ( $category === '_create_new' && $new_cat_raw ) {
            $category = $new_cat_raw;
        }
        if ( ! $category ) {
            $category = 'General';
        }

        // File upload takes priority
        if ( ! empty( $_FILES['tpl_file']['tmp_name'] ) ) {
            $raw = @file_get_contents( $_FILES['tpl_file']['tmp_name'] );
            if ( $raw !== false ) {
                $html = trim( $raw );
            }
        } else {
            $html = isset( $_POST['tpl_html'] ) ? wp_unslash( $_POST['tpl_html'] ) : '';
        }

        if ( ! $name ) {
            $name = __( 'Untitled Template', 'deepwares-mailpro' );
        }

        if ( $html ) {
            // Create dwmp_email post
            $post_id = wp_insert_post( array(
                'post_type'    => $post_type,
                'post_status'  => 'draft',
                'post_title'   => $name,
                'post_content' => $html,
            ) );

            if ( ! is_wp_error( $post_id ) && $post_id ) {
                // Subject / preview meta (matches what we use elsewhere)
                update_post_meta( $post_id, '_dwmp_subject', $subject );
                update_post_meta( $post_id, '_dwmp_preview', $preview );

                // Category via taxonomy
                $term_id = $ensure_term( $category );
                if ( $term_id ) {
                    wp_set_object_terms( $post_id, array( $term_id ), $tax_name, false );
                }

                $notice = 'created';
            } else {
                $notice = 'error';
            }
        } else {
            $notice = 'empty';
        }
    }

    // ----------------------------------------------------
    // Handle GET actions: delete / duplicate
    // ----------------------------------------------------
    if ( ! empty( $_GET['dwmp_tpl_action'] ) && ! empty( $_GET['id'] ) ) {
        $action = sanitize_text_field( $_GET['dwmp_tpl_action'] );
        $id     = absint( $_GET['id'] );

        if ( $id && get_post_type( $id ) === $post_type ) {

            if ( $action === 'delete'
                 && ! empty( $_GET['_wpnonce'] )
                 && wp_verify_nonce( $_GET['_wpnonce'], 'dwmp_tpl_delete_' . $id ) ) {

                wp_trash_post( $id );
                $notice = 'deleted';
            }

            if ( $action === 'duplicate'
                 && ! empty( $_GET['_wpnonce'] )
                 && wp_verify_nonce( $_GET['_wpnonce'], 'dwmp_tpl_duplicate_' . $id ) ) {

                $orig = get_post( $id );
                if ( $orig && $orig->post_type === $post_type ) {

                    $new_post_id = wp_insert_post( array(
                        'post_type'    => $post_type,
                        'post_status'  => 'draft',
                        'post_title'   => $orig->post_title . ' ' . __( '(Copy)', 'deepwares-mailpro' ),
                        'post_content' => $orig->post_content,
                    ) );

                    if ( ! is_wp_error( $new_post_id ) && $new_post_id ) {
                        // Copy meta
                        $meta = get_post_meta( $id );
                        foreach ( $meta as $key => $values ) {
                            foreach ( $values as $v ) {
                                add_post_meta( $new_post_id, $key, maybe_unserialize( $v ) );
                            }
                        }

                        // Copy taxonomy terms
                        $terms = wp_get_object_terms( $id, $tax_name, array( 'fields' => 'ids' ) );
                        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                            wp_set_object_terms( $new_post_id, $terms, $tax_name, false );
                        }
                    }
                }

                $notice = 'duplicated';
            }
        }
    }

    // ----------------------------------------------------
    // Load templates for display (cards)
    // ----------------------------------------------------
    $templates = get_posts( array(
        'post_type'      => $post_type,
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    // Build base categories list for dropdown
    $base_cats = array( 'General', 'Newsletter', 'Promotion', 'Event' );
    $terms     = get_terms( array(
        'taxonomy'   => $tax_name,
        'hide_empty' => false,
    ) );
    if ( ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            if ( ! in_array( $term->name, $base_cats, true ) ) {
                $base_cats[] = $term->name;
            }
        }
    }

    ?>
    <div class="wrap dwmp-templates-wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'Templates', 'deepwares-mailpro' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Manage reusable email templates or upload your own HTML.', 'deepwares-mailpro' ); ?>
        </p>

        <?php if ( $notice === 'created' ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:12px;">
                <p><?php esc_html_e( 'Template added.', 'deepwares-mailpro' ); ?></p>
            </div>
        <?php elseif ( $notice === 'deleted' ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:12px;">
                <p><?php esc_html_e( 'Template deleted.', 'deepwares-mailpro' ); ?></p>
            </div>
        <?php elseif ( $notice === 'duplicated' ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:12px;">
                <p><?php esc_html_e( 'Template duplicated.', 'deepwares-mailpro' ); ?></p>
            </div>
        <?php elseif ( $notice === 'empty' ) : ?>
            <div class="notice notice-warning is-dismissible" style="margin-top:12px;">
                <p><?php esc_html_e( 'No HTML was provided. Please upload a file or paste HTML.', 'deepwares-mailpro' ); ?></p>
            </div>
        <?php elseif ( $notice === 'error' ) : ?>
            <div class="notice notice-error is-dismissible" style="margin-top:12px;">
                <p><?php esc_html_e( 'There was a problem creating the template.', 'deepwares-mailpro' ); ?></p>
            </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1.1fr); gap:24px; align-items:flex-start; margin-top:18px;">

            <!-- LEFT: Saved templates -->
            <section style="background:#ffffff; border-radius:14px; border:1px solid #e5e7eb; padding:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Saved Templates', 'deepwares-mailpro' ); ?></h2>

                <?php if ( empty( $templates ) ) : ?>
                    <p><?php esc_html_e( 'No templates yet. Use the panel on the right or the Email Builder to add your first template.', 'deepwares-mailpro' ); ?></p>
                <?php else : ?>
                    <div style="margin-top:10px; display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:16px;">
                        <?php foreach ( $templates as $post ) : ?>
                            <?php
                            $id      = $post->ID;
                            $name    = get_the_title( $post ) ?: __( 'Untitled Template', 'deepwares-mailpro' );
                            $created = get_the_date( get_option( 'date_format' ), $post );
                            $status  = ucfirst( $post->post_status );

                            $cat_label = 'General';
                            $tpl_terms = get_the_terms( $id, $tax_name );
                            if ( ! is_wp_error( $tpl_terms ) && ! empty( $tpl_terms ) ) {
                                $cat_label = $tpl_terms[0]->name;
                            }

                            $thumb = '';
                            if ( has_post_thumbnail( $id ) ) {
                                $thumb = get_the_post_thumbnail_url( $id, 'medium' );
                            }

                            $edit_url = get_edit_post_link( $id );

                            $dup_url = wp_nonce_url(
                                admin_url( 'admin.php?page=' . $page_slug . '&dwmp_tpl_action=duplicate&id=' . $id ),
                                'dwmp_tpl_duplicate_' . $id
                            );
                            $del_url = wp_nonce_url(
                                admin_url( 'admin.php?page=' . $page_slug . '&dwmp_tpl_action=delete&id=' . $id ),
                                'dwmp_tpl_delete_' . $id
                            );

                            $preview_url = add_query_arg(
                                array(
                                    'dwmp_preview_email' => $id,
                                    '_wpnonce'           => wp_create_nonce( 'dwmp_preview_email_' . $id ),
                                ),
                                home_url( '/' )
                            );
                            ?>
                            <div style="border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; background:#fafafa; display:flex; flex-direction:column; min-height:220px;">
                                <div style="height:140px; background:#f0f2f5; display:flex; align-items:center; justify-content:center;">
                                    <?php if ( $thumb ) : ?>
                                        <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="max-width:100%; max-height:140px; display:block;">
                                    <?php else : ?>
                                        <div style="color:#6b7280; font-size:12px;"><?php esc_html_e( 'No thumbnail', 'deepwares-mailpro' ); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="padding:12px 12px 14px; flex:1;">
                                    <strong style="display:block; margin-bottom:4px;"><?php echo esc_html( $name ); ?></strong>
                                    <span style="display:inline-block; padding:2px 8px; font-size:11px; border-radius:999px; background:#eef2ff; color:#111827;">
                                        <?php echo esc_html( $cat_label ); ?>
                                    </span>
                                    <small style="opacity:.65; margin-left:8px;">
                                        <?php echo esc_html( $status . ' · ' . $created ); ?>
                                    </small>

                                    <div style="margin-top:12px; display:flex; gap:6px; flex-wrap:wrap;">
                                        <a class="button button-primary" href="<?php echo esc_url( $edit_url ); ?>">
                                            <?php esc_html_e( 'Edit in Builder', 'deepwares-mailpro' ); ?>
                                        </a>
                                        <a class="button" href="<?php echo esc_url( $preview_url ); ?>" target="_blank">
                                            <?php esc_html_e( 'Preview', 'deepwares-mailpro' ); ?>
                                        </a>
                                        <a class="button" href="<?php echo esc_url( $dup_url ); ?>">
                                            <?php esc_html_e( 'Duplicate', 'deepwares-mailpro' ); ?>
                                        </a>
                                        <a class="button button-link-delete"
                                           href="<?php echo esc_url( $del_url ); ?>"
                                           onclick="return confirm('<?php echo esc_js( __( 'Delete this template?', 'deepwares-mailpro' ) ); ?>');">
                                            <?php esc_html_e( 'Delete', 'deepwares-mailpro' ); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- RIGHT: Add new template -->
            <aside>
                <section style="background:#ffffff; border-radius:14px; border:1px solid #e5e7eb; padding:20px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Add New Template', 'deepwares-mailpro' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Upload an HTML file or paste HTML to save as a reusable template. You can edit it later in the Email Builder (Gutenberg).', 'deepwares-mailpro' ); ?>
                    </p>

                    <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
                        <?php wp_nonce_field( 'dwmp_tpl_new', 'dwmp_tpl_nonce' ); ?>

                        <label style="display:block; margin-top:4px;"><?php esc_html_e( 'Template Name', 'deepwares-mailpro' ); ?></label>
                        <input type="text" name="tpl_name" class="regular-text"
                               placeholder="<?php esc_attr_e( 'e.g., Cyber Monday Promo', 'deepwares-mailpro' ); ?>"
                               style="width:100%;">

                        <div style="display:flex; gap:10px; margin-top:12px;">
                            <div style="flex:1;">
                                <label style="display:block;"><?php esc_html_e( 'Category', 'deepwares-mailpro' ); ?></label>
                                <select name="tpl_category" id="dwmp_tpl_category" style="width:100%;">
                                    <?php foreach ( $base_cats as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $cat ); ?></option>
                                    <?php endforeach; ?>
                                    <option value="_create_new"><?php esc_html_e( '+ Create new…', 'deepwares-mailpro' ); ?></option>
                                </select>
                            </div>
                            <div style="flex:1;">
                                <label style="display:block;"><?php esc_html_e( 'New Category', 'deepwares-mailpro' ); ?></label>
                                <input type="text" name="tpl_category_new" id="dwmp_tpl_category_new"
                                       class="regular-text" style="width:100%; display:none;"
                                       placeholder="<?php esc_attr_e( 'Enter new category', 'deepwares-mailpro' ); ?>">
                            </div>
                        </div>

                        <div style="margin-top:12px;">
                            <label style="display:block;"><?php esc_html_e( 'Subject (optional)', 'deepwares-mailpro' ); ?></label>
                            <input type="text" name="tpl_subject" class="regular-text" style="width:100%;">
                        </div>

                        <div style="margin-top:8px;">
                            <label style="display:block;"><?php esc_html_e( 'Preheader / Preview (optional)', 'deepwares-mailpro' ); ?></label>
                            <input type="text" name="tpl_preview" class="regular-text" style="width:100%;">
                        </div>

                        <hr style="margin:16px 0;">

                        <label style="display:block; margin-bottom:6px;"><?php esc_html_e( 'Upload HTML file', 'deepwares-mailpro' ); ?></label>
                        <input type="file" name="tpl_file" accept=".html,.htm,.xhtml,.txt" style="width:100%;">

                        <div style="margin:8px 0; text-align:center; color:#6b7280;">
                            <?php esc_html_e( '— or —', 'deepwares-mailpro' ); ?>
                        </div>

                        <label style="display:block; margin-bottom:6px;"><?php esc_html_e( 'Paste HTML', 'deepwares-mailpro' ); ?></label>
                        <textarea name="tpl_html" rows="8" style="width:100%; font-family:monospace;"
                                  placeholder="&lt;!-- HTML or HTML + &lt;style&gt;...&lt;/style&gt; --&gt;"></textarea>

                        <p style="margin-top:14px;">
                            <button type="submit" class="button button-primary">
                                <?php esc_html_e( 'Save Template', 'deepwares-mailpro' ); ?>
                            </button>
                        </p>
                    </form>

                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e( 'You can also create templates directly from the Email Builder; they will automatically appear in this list.', 'deepwares-mailpro' ); ?>
                    </p>
                </section>
            </aside>
        </div>

        <!-- JS: handle "+ Create new..." category behaviour -->
        <script>
        (function(){
            document.addEventListener('DOMContentLoaded', function(){
                var selectCat = document.getElementById('dwmp_tpl_category');
                var newCat    = document.getElementById('dwmp_tpl_category_new');
                if (!selectCat || !newCat) return;

                function toggleNewCat() {
                    if (selectCat.value === '_create_new') {
                        newCat.style.display = '';
                        newCat.focus();
                    } else {
                        newCat.style.display = 'none';
                        newCat.value = '';
                    }
                }
                selectCat.addEventListener('change', toggleNewCat);
                toggleNewCat();
            });
        })();
        </script>
    </div>
<?php
}

endif;
