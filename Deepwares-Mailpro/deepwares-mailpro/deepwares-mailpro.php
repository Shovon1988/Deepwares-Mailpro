<?php
/**
 * Plugin Name: Deepwares MailPro
 * Description: Mailchimp-like email marketing plugin for WordPress.
 * Version:     1.0.0
 * Author:      Shovon
 * Text Domain: deepwares-mailpro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/
define( 'DWMP_VERSION',       '1.0.0' );
define( 'DWMP_PLUGIN_FILE',   __FILE__ );
define( 'DWMP_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'DWMP_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/
if ( file_exists( DWMP_PLUGIN_DIR . 'includes/Install.php' ) ) {
    require_once DWMP_PLUGIN_DIR . 'includes/Install.php';
}
if ( file_exists( DWMP_PLUGIN_DIR . 'includes/QueueProcessor.php' ) ) {
    require_once DWMP_PLUGIN_DIR . 'includes/QueueProcessor.php';
}
if ( file_exists( DWMP_PLUGIN_DIR . 'includes/MailSender.php' ) ) {
    require_once DWMP_PLUGIN_DIR . 'includes/MailSender.php';
}
if ( file_exists( DWMP_PLUGIN_DIR . 'includes/Tracking.php' ) ) {
    require_once DWMP_PLUGIN_DIR . 'includes/Tracking.php';
}

if ( is_admin() ) {
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Dashboard.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Dashboard.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Subscribers.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Subscribers.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Lists.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Lists.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Campaigns.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Campaigns.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Builder.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Builder.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Reports.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Reports.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Settings.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Settings.php';
    }
    if ( file_exists( DWMP_PLUGIN_DIR . 'admin/Templates.php' ) ) {
        require_once DWMP_PLUGIN_DIR . 'admin/Templates.php';
    }
}

/*
|--------------------------------------------------------------------------
| Activation - run installer
|--------------------------------------------------------------------------
*/
register_activation_hook( __FILE__, 'dwmp_plugin_activate' );
function dwmp_plugin_activate() {
    if ( class_exists( '\Deepwares\MailPro\Install' ) ) {
        \Deepwares\MailPro\Install::activate();
    }
}

/*
|--------------------------------------------------------------------------
| Tracking bootstrap
|--------------------------------------------------------------------------
*/
add_action( 'plugins_loaded', function () {
    if ( class_exists( '\Deepwares\MailPro\Tracking' ) ) {
        \Deepwares\MailPro\Tracking::init();
    }
} );

/*
|--------------------------------------------------------------------------
| Helper: required capability for MailPro
|--------------------------------------------------------------------------
|
| Reads dwmp_security['access_capability'] so you can control who sees
| MailPro (Admins only, Editors+, etc). Falls back to manage_options.
|
*/
if ( ! function_exists( 'dwmp_required_capability' ) ) {
    function dwmp_required_capability() {
        $security = get_option( 'dwmp_security', array() );
        if ( ! empty( $security['access_capability'] ) ) {
            return $security['access_capability'];
        }
        return 'manage_options';
    }
}

/*
|--------------------------------------------------------------------------
| Gutenberg Email Template Custom Post Type
|--------------------------------------------------------------------------
*/
add_action( 'init', 'dwmp_register_email_cpt' );
function dwmp_register_email_cpt() {
    $labels = array(
        'name'               => __( 'Email Templates', 'deepwares-mailpro' ),
        'singular_name'      => __( 'Email Template', 'deepwares-mailpro' ),
        'add_new'            => __( 'Add New', 'deepwares-mailpro' ),
        'add_new_item'       => __( 'Add New Email Template', 'deepwares-mailpro' ),
        'edit_item'          => __( 'Edit Email Template', 'deepwares-mailpro' ),
        'new_item'           => __( 'New Email Template', 'deepwares-mailpro' ),
        'view_item'          => __( 'View Email Template', 'deepwares-mailpro' ),
        'search_items'       => __( 'Search Email Templates', 'deepwares-mailpro' ),
        'not_found'          => __( 'No email templates found', 'deepwares-mailpro' ),
        'not_found_in_trash' => __( 'No email templates found in Trash', 'deepwares-mailpro' ),
    );

    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => false, // attached to our own menu
        'show_in_rest'       => true,  // Gutenberg
        'supports'           => array( 'title', 'editor', 'thumbnail' ),
        'capability_type'    => 'post',
    );

    register_post_type( 'dwmp_email', $args );
}

/*
|--------------------------------------------------------------------------
| Email Category Taxonomy for dwmp_email
|--------------------------------------------------------------------------
*/
add_action( 'init', 'dwmp_register_email_category_tax' );
function dwmp_register_email_category_tax() {

    $labels = array(
        'name'          => __( 'Email Categories', 'deepwares-mailpro' ),
        'singular_name' => __( 'Email Category', 'deepwares-mailpro' ),
        'search_items'  => __( 'Search Email Categories', 'deepwares-mailpro' ),
        'all_items'     => __( 'All Email Categories', 'deepwares-mailpro' ),
        'edit_item'     => __( 'Edit Email Category', 'deepwares-mailpro' ),
        'update_item'   => __( 'Update Email Category', 'deepwares-mailpro' ),
        'add_new_item'  => __( 'Add New Email Category', 'deepwares-mailpro' ),
        'new_item_name' => __( 'New Email Category', 'deepwares-mailpro' ),
        'menu_name'     => __( 'Email Categories', 'deepwares-mailpro' ),
    );

    register_taxonomy(
        'dwmp_email_category',
        'dwmp_email',
        array(
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'hierarchical' => false,
        )
    );

    // Ensure the default categories exist.
    $defaults = array( 'General', 'Newsletter', 'Promotion', 'Event' );
    foreach ( $defaults as $term_name ) {
        if ( ! term_exists( $term_name, 'dwmp_email_category' ) ) {
            wp_insert_term( $term_name, 'dwmp_email_category' );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Subject & Preview meta box for dwmp_email
|--------------------------------------------------------------------------
*/
add_action( 'add_meta_boxes', 'dwmp_add_email_meta_boxes' );
function dwmp_add_email_meta_boxes() {
    add_meta_box(
        'dwmp_email_meta',
        __( 'Email Settings', 'deepwares-mailpro' ),
        'dwmp_email_meta_box_html',
        'dwmp_email',
        'side',
        'default'
    );

    // Custom Category dropdown (besides the default taxonomy panel).
    add_meta_box(
        'dwmp_email_category_box',
        __( 'Email Category', 'deepwares-mailpro' ),
        'dwmp_email_category_box_html',
        'dwmp_email',
        'side',
        'default'
    );
}

function dwmp_email_meta_box_html( $post ) {
    $subject = get_post_meta( $post->ID, '_dwmp_subject', true );
    $preview = get_post_meta( $post->ID, '_dwmp_preview', true );
    wp_nonce_field( 'dwmp_email_meta_save', 'dwmp_email_meta_nonce' );
    ?>
    <p>
        <label for="dwmp_subject"><strong><?php esc_html_e( 'Subject Line', 'deepwares-mailpro' ); ?></strong></label><br/>
        <input type="text" name="dwmp_subject" id="dwmp_subject"
               value="<?php echo esc_attr( $subject ); ?>"
               class="widefat" />
    </p>
    <p>
        <label for="dwmp_preview"><strong><?php esc_html_e( 'Preview Text', 'deepwares-mailpro' ); ?></strong></label><br/>
        <input type="text" name="dwmp_preview" id="dwmp_preview"
               value="<?php echo esc_attr( $preview ); ?>"
               class="widefat" />
    </p>
    <?php
}

/**
 * Custom dropdown for Email Category (General / Newsletter / Promotion / Event / +Create new…)
 */
function dwmp_email_category_box_html( $post ) {
    wp_nonce_field( 'dwmp_email_category_save', 'dwmp_email_category_nonce' );

    // Get all terms.
    $terms = get_terms(
        array(
            'taxonomy'   => 'dwmp_email_category',
            'hide_empty' => false,
        )
    );

    $base_cats  = array( 'General', 'Newsletter', 'Promotion', 'Event' );
    $have_slugs = array();

    foreach ( $terms as $t ) {
        $have_slugs[ $t->name ] = $t;
    }

    // Make sure defaults exist in this dropdown list.
    foreach ( $base_cats as $name ) {
        if ( ! isset( $have_slugs[ $name ] ) ) {
            $term = get_term_by( 'name', $name, 'dwmp_email_category' );
            if ( $term ) {
                $have_slugs[ $name ] = $term;
            }
        }
    }

    // Current selection.
    $current_terms = wp_get_object_terms(
        $post->ID,
        'dwmp_email_category',
        array( 'fields' => 'ids' )
    );
    $current_id = ! empty( $current_terms ) ? (int) $current_terms[0] : 0;
    ?>
    <p>
        <label for="dwmp_email_category_select"><strong><?php esc_html_e( 'Category', 'deepwares-mailpro' ); ?></strong></label><br/>
        <select name="dwmp_email_category" id="dwmp_email_category_select" class="widefat">
            <option value=""><?php esc_html_e( '— Select —', 'deepwares-mailpro' ); ?></option>
            <?php
            foreach ( $have_slugs as $term ) {
                if ( ! $term || is_wp_error( $term ) ) {
                    continue;
                }
                ?>
                <option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $current_id, $term->term_id ); ?>>
                    <?php echo esc_html( $term->name ); ?>
                </option>
                <?php
            }
            ?>
            <option value="_create_new"><?php esc_html_e( '+ Create new…', 'deepwares-mailpro' ); ?></option>
        </select>
    </p>
    <p>
        <input type="text"
               name="dwmp_email_category_new"
               id="dwmp_email_category_new"
               class="widefat"
               style="display:none;"
               placeholder="<?php esc_attr_e( 'Enter new category', 'deepwares-mailpro' ); ?>" />
    </p>
    <script>
        (function(){
            document.addEventListener('DOMContentLoaded', function(){
                var sel  = document.getElementById('dwmp_email_category_select');
                var input = document.getElementById('dwmp_email_category_new');
                if (!sel || !input) return;

                function toggleNew() {
                    if (sel.value === '_create_new') {
                        input.style.display = '';
                        input.focus();
                    } else {
                        input.style.display = 'none';
                        input.value = '';
                    }
                }
                sel.addEventListener('change', toggleNew);
                toggleNew();
            });
        })();
    </script>
    <?php
}

/*
|--------------------------------------------------------------------------
| Save meta + category (+ generate thumbnail)
|--------------------------------------------------------------------------
*/
add_action( 'save_post_dwmp_email', 'dwmp_save_email_meta' );
function dwmp_save_email_meta( $post_id ) {

    // Subject + Preview.
    if (
        isset( $_POST['dwmp_email_meta_nonce'] )
        && wp_verify_nonce( $_POST['dwmp_email_meta_nonce'], 'dwmp_email_meta_save' )
        && ( ! defined( 'DOING_AUTOSAVE' ) || ! DOING_AUTOSAVE )
        && current_user_can( 'edit_post', $post_id )
    ) {
        $subject = isset( $_POST['dwmp_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['dwmp_subject'] ) ) : '';
        $preview = isset( $_POST['dwmp_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['dwmp_preview'] ) ) : '';

        update_post_meta( $post_id, '_dwmp_subject', $subject );
        update_post_meta( $post_id, '_dwmp_preview', $preview );
    }

    // Category dropdown → taxonomy.
    if (
        isset( $_POST['dwmp_email_category_nonce'] )
        && wp_verify_nonce( $_POST['dwmp_email_category_nonce'], 'dwmp_email_category_save' )
        && ( ! defined( 'DOING_AUTOSAVE' ) || ! DOING_AUTOSAVE )
        && current_user_can( 'edit_post', $post_id )
    ) {
        $cat_val  = isset( $_POST['dwmp_email_category'] ) ? wp_unslash( $_POST['dwmp_email_category'] ) : '';
        $new_name = isset( $_POST['dwmp_email_category_new'] ) ? sanitize_text_field( wp_unslash( $_POST['dwmp_email_category_new'] ) ) : '';

        $term_id = 0;

        if ( '_create_new' === $cat_val && $new_name ) {
            $existing = term_exists( $new_name, 'dwmp_email_category' );
            if ( $existing && ! is_wp_error( $existing ) ) {
                $term_id = (int) $existing['term_id'];
            } else {
                $created = wp_insert_term( $new_name, 'dwmp_email_category' );
                if ( ! is_wp_error( $created ) ) {
                    $term_id = (int) $created['term_id'];
                }
            }
        } elseif ( $cat_val && '_create_new' !== $cat_val ) {
            $term_id = (int) $cat_val;
        }

        if ( $term_id ) {
            wp_set_object_terms( $post_id, array( $term_id ), 'dwmp_email_category', false );
        } else {
            // If nothing selected, you can optionally assign "General" by default.
            $general = get_term_by( 'name', 'General', 'dwmp_email_category' );
            if ( $general && ! is_wp_error( $general ) ) {
                wp_set_object_terms( $post_id, array( (int) $general->term_id ), 'dwmp_email_category', false );
            }
        }
    }

    // After saving meta + category, auto-generate a thumbnail if needed.
    dwmp_maybe_generate_email_thumbnail( $post_id );
}

/**
 * Auto-generate a simple thumbnail image for dwmp_email templates.
 *
 * - Uses GD if available.
 * - Only runs if the post does not already have a featured image.
 */
function dwmp_maybe_generate_email_thumbnail( $post_id ) {
    // Only for dwmp_email posts.
    if ( get_post_type( $post_id ) !== 'dwmp_email' ) {
        return;
    }

    // Don't overwrite an existing featured image.
    if ( has_post_thumbnail( $post_id ) ) {
        return;
    }

    // We need GD.
    if ( ! function_exists( 'imagecreatetruecolor' ) ) {
        return;
    }

    $width  = 800;
    $height = 450;

    $im = imagecreatetruecolor( $width, $height );
    if ( ! $im ) {
        return;
    }

    // Colours.
    $bg        = imagecolorallocate( $im, 249, 250, 251 ); // light grey
    $bar       = imagecolorallocate( $im, 59, 130, 246 );  // blue bar
    $text_main = imagecolorallocate( $im, 255, 255, 255 ); // white

    // Background + top bar.
    imagefilledrectangle( $im, 0, 0, $width, $height, $bg );
    imagefilledrectangle( $im, 0, 0, $width, 90, $bar );

    // Title + category text.
    $title = get_the_title( $post_id );
    if ( ! $title ) {
        $title = __( 'Email Template', 'deepwares-mailpro' );
    }

    // Fetch first category name, if any.
    $category_label = '';
    $terms = get_the_terms( $post_id, 'dwmp_email_category' );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        $category_label = $terms[0]->name;
    }

    // Trim text a bit to avoid overflow.
    if ( function_exists( 'mb_strimwidth' ) ) {
        $title          = mb_strimwidth( $title, 0, 45, '…', 'UTF-8' );
        $category_label = mb_strimwidth( $category_label, 0, 30, '…', 'UTF-8' );
    } else {
        $title          = strlen( $title ) > 45 ? substr( $title, 0, 42 ) . '…' : $title;
        $category_label = strlen( $category_label ) > 30 ? substr( $category_label, 0, 27 ) . '…' : $category_label;
    }

    // Simple built-in font text.
    imagestring( $im, 5, 24, 32, $title, $text_main );
    if ( $category_label ) {
        imagestring( $im, 3, 26, 60, $category_label, $text_main );
    }

    // Card outline
    $outline = imagecolorallocate( $im, 209, 213, 219 );
    imagerectangle( $im, 40, 130, $width - 40, $height - 40, $outline );

    // Save to a temp file.
    $tmp = wp_tempnam( 'dwmp-thumb' );
    if ( ! $tmp ) {
        imagedestroy( $im );
        return;
    }

    imagepng( $im, $tmp );
    imagedestroy( $im );

    // Move into uploads folder and create attachment.
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        @unlink( $tmp );
        return;
    }

    $filename = 'dwmp-email-thumb-' . $post_id . '.png';
    $dest     = trailingslashit( $upload_dir['path'] ) . $filename;

    if ( ! @rename( $tmp, $dest ) ) {
        if ( ! @copy( $tmp, $dest ) ) {
            @unlink( $tmp );
            return;
        }
        @unlink( $tmp );
    }

    $filetype = wp_check_filetype( $filename, null );

    $attachment = array(
        'post_mime_type' => $filetype['type'],
        'post_title'     => get_the_title( $post_id ) . ' thumbnail',
        'post_content'   => '',
        'post_status'    => 'inherit',
    );

    $attach_id = wp_insert_attachment( $attachment, $dest, $post_id );
    if ( ! $attach_id || is_wp_error( $attach_id ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attach_data = wp_generate_attachment_metadata( $attach_id, $dest );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    set_post_thumbnail( $post_id, $attach_id );
}

/*
|--------------------------------------------------------------------------
| Admin Menu (uses dwmp_required_capability)
|--------------------------------------------------------------------------
*/
add_action( 'admin_menu', 'dwmp_register_admin_menu' );
function dwmp_register_admin_menu() {

    $cap = dwmp_required_capability();

    add_menu_page(
        __( 'MailPro', 'deepwares-mailpro' ),
        __( 'MailPro', 'deepwares-mailpro' ),
        $cap,
        'deepwares-mailpro',
        function () {
            if ( function_exists( 'dwmp_render_dashboard_page' ) ) {
                dwmp_render_dashboard_page();
            }
        },
        'dashicons-email-alt2',
        26
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Dashboard', 'deepwares-mailpro' ),
        __( 'Dashboard', 'deepwares-mailpro' ),
        $cap,
        'deepwares-mailpro',
        function () {
            if ( function_exists( 'dwmp_render_dashboard_page' ) ) {
                dwmp_render_dashboard_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Subscribers', 'deepwares-mailpro' ),
        __( 'Subscribers', 'deepwares-mailpro' ),
        $cap,
        'dwmp-subscribers',
        function () {
            if ( function_exists( 'dwmp_render_subscribers_page' ) ) {
                dwmp_render_subscribers_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Lists', 'deepwares-mailpro' ),
        __( 'Lists', 'deepwares-mailpro' ),
        $cap,
        'dwmp-lists',
        function () {
            if ( function_exists( 'dwmp_render_lists_page' ) ) {
                dwmp_render_lists_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Campaigns', 'deepwares-mailpro' ),
        __( 'Campaigns', 'deepwares-mailpro' ),
        $cap,
        'dwmp-campaigns',
        function () {
            if ( function_exists( 'dwmp_render_campaigns_page' ) ) {
                dwmp_render_campaigns_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Email Builder', 'deepwares-mailpro' ),
        __( 'Email Builder', 'deepwares-mailpro' ),
        $cap,
        'dwmp-builder',
        function () {
            if ( function_exists( 'dwmp_render_builder_page' ) ) {
                dwmp_render_builder_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Reports', 'deepwares-mailpro' ),
        __( 'Reports', 'deepwares-mailpro' ),
        $cap,
        'dwmp-reports',
        function () {
            if ( function_exists( 'dwmp_render_reports_page' ) ) {
                dwmp_render_reports_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Templates', 'deepwares-mailpro' ),
        __( 'Templates', 'deepwares-mailpro' ),
        $cap,
        'dwmp-templates',
        function () {
            if ( function_exists( 'dwmp_render_templates_page' ) ) {
                dwmp_render_templates_page();
            }
        }
    );

    add_submenu_page(
        'deepwares-mailpro',
        __( 'Settings', 'deepwares-mailpro' ),
        __( 'Settings', 'deepwares-mailpro' ),
        $cap,
        'dwmp-settings',
        function () {
            if ( function_exists( 'dwmp_render_settings_page' ) ) {
                dwmp_render_settings_page();
            }
        }
    );
}

/*
|--------------------------------------------------------------------------
| Admin Assets
|--------------------------------------------------------------------------
*/
add_action( 'admin_enqueue_scripts', 'dwmp_admin_assets' );
function dwmp_admin_assets( $hook ) {

    $screen         = get_current_screen();
    $is_dwmp_screen = false;

    if ( isset( $screen->id ) ) {
        if (
            strpos( $screen->id, 'deepwares-mailpro' ) !== false ||
            strpos( $screen->id, 'dwmp-' ) !== false ||
            ( isset( $screen->post_type ) && 'dwmp_email' === $screen->post_type )
        ) {
            $is_dwmp_screen = true;
        }
    }

    if ( ! $is_dwmp_screen ) {
        return;
    }

    wp_enqueue_style(
        'dwmp-admin',
        DWMP_PLUGIN_URL . 'assets/admin.css',
        array(),
        DWMP_VERSION
    );

    wp_enqueue_script(
        'dwmp-admin',
        DWMP_PLUGIN_URL . 'assets/admin.js',
        array( 'jquery' ),
        DWMP_VERSION,
        true
    );
}

/*
|--------------------------------------------------------------------------
| TRUE HTML PREVIEW FOR dwmp_email
|--------------------------------------------------------------------------
*/
add_action( 'template_redirect', 'dwmp_maybe_render_email_preview' );
function dwmp_maybe_render_email_preview() {
    if ( empty( $_GET['dwmp_preview_email'] ) ) {
        return;
    }

    $post_id = absint( $_GET['dwmp_preview_email'] );
    if ( ! $post_id || 'dwmp_email' !== get_post_type( $post_id ) ) {
        return;
    }

    $nonce = isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '';
    if ( ! wp_verify_nonce( $nonce, 'dwmp_preview_email_' . $post_id ) ) {
        wp_die( esc_html__( 'Invalid preview link.', 'deepwares-mailpro' ) );
    }

    // Use security setting for access instead of hard-coded manage_options
    if ( ! is_user_logged_in() || ! current_user_can( dwmp_required_capability() ) ) {
        wp_die( esc_html__( 'You must be logged in with permission to preview this email.', 'deepwares-mailpro' ) );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_die( esc_html__( 'Email template not found.', 'deepwares-mailpro' ) );
    }

    $subject = get_post_meta( $post_id, '_dwmp_subject', true );
    $preview = get_post_meta( $post_id, '_dwmp_preview', true );
    $html    = apply_filters( 'the_content', $post->post_content );

    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <title><?php echo esc_html( $subject ? $subject : $post->post_title ); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                margin: 0;
                padding: 0;
                background: #f3f4f6;
                font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            }
            .dwmp-email-preview-wrapper {
                max-width: 680px;
                margin: 20px auto;
                background: #ffffff;
                border-radius: 8px;
                box-shadow: 0 10px 30px rgba(15,23,42,0.15);
                overflow: hidden;
            }
            .dwmp-email-preview-header {
                padding: 12px 16px;
                border-bottom: 1px solid #e5e7eb;
                font-size: 12px;
                color: #6b7280;
                background:#f9fafb;
            }
            .dwmp-email-preview-body {
                padding: 0;
            }
        </style>
    </head>
    <body>
        <div class="dwmp-email-preview-wrapper">
            <div class="dwmp-email-preview-header">
                <?php if ( $subject ) : ?>
                    <div><strong><?php echo esc_html( $subject ); ?></strong></div>
                <?php endif; ?>
                <?php if ( $preview ) : ?>
                    <div><?php echo esc_html( $preview ); ?></div>
                <?php endif; ?>
            </div>
            <div class="dwmp-email-preview-body">
                <?php echo $html; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| GUTENBERG PATTERNS FOR dwmp_email (default_content + default_title)
|--------------------------------------------------------------------------
*/
add_filter( 'default_content', 'dwmp_default_email_content', 10, 2 );
function dwmp_default_email_content( $content, $post ) {
    if ( ! $post || 'dwmp_email' !== $post->post_type ) {
        return $content;
    }

    if ( empty( $_GET['dwmp_pattern'] ) ) {
        return $content;
    }

    $pattern = sanitize_key( $_GET['dwmp_pattern'] );

    switch ( $pattern ) {
        case 'hero_two_columns':
            $content = <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"40px","right":"24px","bottom":"40px","left":"24px"}}},"layout":{"contentSize":"640px"}} -->
<div class="wp-block-group" style="padding-top:40px;padding-right:24px;padding-bottom:40px;padding-left:24px">
<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">Big Announcement Headline</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Use this hero section to introduce your main offer in one or two short sentences.</p>
<!-- /wp:paragraph -->

<!-- wp:columns {"style":{"spacing":{"margin":{"top":"24px"}}}} -->
<div class="wp-block-columns" style="margin-top:24px">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Key Benefit #1</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Describe the first key benefit or feature of your product, service or campaign.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Key Benefit #2</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Highlight another reason your audience should care or click through.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"24px"}}}} -->
<div class="wp-block-buttons" style="margin-top:24px">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Primary Call To Action</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
HTML;
            break;

        case 'feature_grid':
            $content = <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"36px","right":"24px","bottom":"36px","left":"24px"}}},"layout":{"contentSize":"640px"}} -->
<div class="wp-block-group" style="padding-top:36px;padding-right:24px;padding-bottom:36px;padding-left:24px">
<!-- wp:heading {"textAlign":"center","level":2} -->
<h2 class="wp-block-heading has-text-align-center">Featured Highlights</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Use this grid to showcase products, features, articles or benefits.</p>
<!-- /wp:paragraph -->

<!-- wp:columns {"style":{"spacing":{"margin":{"top":"24px"}}}} -->
<div class="wp-block-columns" style="margin-top:24px">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Feature One</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Short description that focuses on the main value of this feature.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Feature Two</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Explain why this matters for your subscribers in one or two sentences.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4} -->
<h4 class="wp-block-heading">Feature Three</h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Another benefit, product or link you want to draw attention to.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
HTML;
            break;

        case 'footer_focus':
            $content = <<<HTML
<!-- wp:group {"style":{"spacing":{"padding":{"top":"32px","right":"24px","bottom":"32px","left":"24px"}}},"backgroundColor":"black","textColor":"white","layout":{"contentSize":"640px"}} -->
<div class="wp-block-group has-white-color has-black-background-color has-text-color has-background" style="padding-top:32px;padding-right:24px;padding-bottom:32px;padding-left:24px">
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">You are receiving this email because you subscribed to updates.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">Company Name · 123 Example Street · City · Country</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><a href="#" class="has-white-color has-text-color">View in browser</a> · <a href="#" class="has-white-color has-text-color">Update preferences</a> · <a href="#" class="has-white-color has-text-color">Unsubscribe</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
HTML;
            break;
    }

    return $content;
}

add_filter( 'default_title', 'dwmp_default_email_title', 10, 2 );
function dwmp_default_email_title( $title, $post ) {
    if ( ! $post || 'dwmp_email' !== $post->post_type ) {
        return $title;
    }

    if ( empty( $_GET['dwmp_pattern'] ) ) {
        return $title;
    }

    $pattern = sanitize_key( $_GET['dwmp_pattern'] );

    switch ( $pattern ) {
        case 'hero_two_columns':
            return __( 'Hero + Two Columns Email', 'deepwares-mailpro' );
        case 'feature_grid':
            return __( 'Feature Grid Email', 'deepwares-mailpro' );
        case 'footer_focus':
            return __( 'Footer / Compliance Email', 'deepwares-mailpro' );
        default:
            return $title;
    }
}
