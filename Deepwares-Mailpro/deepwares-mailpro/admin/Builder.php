<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dwmp_render_builder_page' ) ) :

/**
 * Email Builder hub page.
 *
 * Uses Gutenberg (dwmp_email) and your Templates page.
 */
function dwmp_render_builder_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'deepwares-mailpro' ) );
    }

    // Recent email templates (used in left "Templates" & right "Recent Templates")
    $recent_emails = get_posts( array(
        'post_type'      => 'dwmp_email',
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 6,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    // For the left column, feature up to 3 templates.
    $featured_templates = ! empty( $recent_emails ) ? array_slice( $recent_emails, 0, 3 ) : array();

    ?>
    <div class="wrap dwmp-email-builder-page">

        <h1 class="wp-heading-inline"><?php esc_html_e( 'Email Builder', 'deepwares-mailpro' ); ?></h1>
        <p class="description" style="margin-top:6px;">
            <?php esc_html_e( 'Create beautiful, responsive email templates using the WordPress Block Editor.', 'deepwares-mailpro' ); ?>
        </p>

        <div style="display:grid; grid-template-columns:minmax(260px, 0.9fr) minmax(420px, 1.4fr) minmax(320px, 1.1fr); gap:24px; margin-top:24px; align-items:flex-start;">

            <!-- LEFT: Template library cards -->
            <section style="background:#ffffff; border-radius:14px; border:1px solid #e5e7eb; padding:20px;">
                <h2 style="margin-top:0; margin-bottom:12px;"><?php esc_html_e( 'Templates', 'deepwares-mailpro' ); ?></h2>

                <div style="display:flex; flex-direction:column; gap:12px;">

                    <?php if ( ! empty( $featured_templates ) ) : ?>

                        <?php
                        // Colour accents to keep cards visually distinct
                        $bg_colors = array( '#f1f5f9', '#eef2ff', '#fef3c7' );
                        $i         = 0;

                        foreach ( $featured_templates as $email ) :
                            $edit_link   = get_edit_post_link( $email->ID );
                            $title       = get_the_title( $email ) ?: __( '(no title)', 'deepwares-mailpro' );

                            // Category label(s)
                            $category_labels = array();
                            $terms = get_the_terms( $email->ID, 'dwmp_email_category' );
                            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                                foreach ( $terms as $term ) {
                                    $category_labels[] = $term->name;
                                }
                            }
                            $cat_line = ! empty( $category_labels ) ? implode( ', ', $category_labels ) : __( 'General', 'deepwares-mailpro' );

                            // Pick a background colour
                            $bg = $bg_colors[ $i % count( $bg_colors ) ];
                            $i++;
                            ?>
                            <a href="<?php echo esc_url( $edit_link ); ?>"
                               class="dwmp-template-card-link"
                               style="display:block; text-decoration:none; color:inherit;">
                                <div style="border-radius:12px; border:1px solid #e5e7eb; overflow:hidden; background:#ffffff;">
                                    <div style="height:120px; background:<?php echo esc_attr( $bg ); ?>; display:flex; align-items:center; justify-content:center;">
                                        <span style="font-size:13px; color:#6b7280; text-align:center; padding:0 8px;">
                                            <?php echo esc_html( wp_trim_words( $email->post_excerpt ?: $email->post_title, 8, '…' ) ); ?>
                                        </span>
                                    </div>
                                    <div style="padding:10px 12px 12px;">
                                        <div style="font-weight:500;"><?php echo esc_html( $title ); ?></div>
                                        <div style="font-size:11px; color:#9ca3af;"><?php echo esc_html( $cat_line ); ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>

                    <?php else : ?>

                        <!-- Fallback starter layouts (pattern shortcuts) -->
                        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email' ) ); ?>"
                           class="dwmp-template-card-link"
                           style="display:block; text-decoration:none; color:inherit;">
                            <div style="border-radius:12px; border:1px solid #e5e7eb; overflow:hidden; background:#ffffff;">
                                <div style="height:120px; background:#f1f5f9; display:flex; align-items:center; justify-content:center;">
                                    <span style="font-size:13px; color:#6b7280;"><?php esc_html_e( 'Simple newsletter layout', 'deepwares-mailpro' ); ?></span>
                                </div>
                                <div style="padding:10px 12px 12px;">
                                    <div style="font-weight:500;"><?php esc_html_e( 'Simple Newsletter', 'deepwares-mailpro' ); ?></div>
                                    <div style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'General', 'deepwares-mailpro' ); ?></div>
                                </div>
                            </div>
                        </a>

                        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email&dwmp_pattern=hero_two_columns' ) ); ?>"
                           class="dwmp-template-card-link"
                           style="display:block; text-decoration:none; color:inherit;">
                            <div style="border-radius:12px; border:1px solid #e5e7eb; overflow:hidden; background:#ffffff;">
                                <div style="height:120px; background:#eef2ff; display:flex; align-items:center; justify-content:center;">
                                    <span style="font-size:13px; color:#4b5563;"><?php esc_html_e( 'Hero + call-to-action layout', 'deepwares-mailpro' ); ?></span>
                                </div>
                                <div style="padding:10px 12px 12px;">
                                    <div style="font-weight:500;"><?php esc_html_e( 'Product Launch', 'deepwares-mailpro' ); ?></div>
                                    <div style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Promotion', 'deepwares-mailpro' ); ?></div>
                                </div>
                            </div>
                        </a>

                        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email&dwmp_pattern=feature_grid' ) ); ?>"
                           class="dwmp-template-card-link"
                           style="display:block; text-decoration:none; color:inherit;">
                            <div style="border-radius:12px; border:1px solid #e5e7eb; overflow:hidden; background:#ffffff;">
                                <div style="height:120px; background:#fef3c7; display:flex; align-items:center; justify-content:center;">
                                    <span style="font-size:13px; color:#4b5563;"><?php esc_html_e( 'Feature / agenda layout', 'deepwares-mailpro' ); ?></span>
                                </div>
                                <div style="padding:10px 12px 12px;">
                                    <div style="font-weight:500;"><?php esc_html_e( 'Event Invitation', 'deepwares-mailpro' ); ?></div>
                                    <div style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Event', 'deepwares-mailpro' ); ?></div>
                                </div>
                            </div>
                        </a>

                    <?php endif; ?>
                </div>

                <div style="margin-top:12px; text-align:center;">
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-templates' ) ); ?>">
                        + <?php esc_html_e( 'Browse All Templates', 'deepwares-mailpro' ); ?>
                    </a>
                </div>
            </section>

            <!-- CENTER: Design summary + "create from pattern" -->
            <section style="background:#ffffff; border-radius:14px; border:1px solid #e5e7eb; padding:20px;">
                <h2 style="margin-top:0; margin-bottom:12px;"><?php esc_html_e( 'Design Your Email', 'deepwares-mailpro' ); ?></h2>

                <p style="margin-bottom:10px;">
                    <?php esc_html_e( 'Use the Block Editor to build responsive, mobile-friendly emails using sections, columns, images, and buttons.', 'deepwares-mailpro' ); ?>
                </p>

                <ul style="list-style:disc; margin-left:18px; font-size:13px; color:#4b5563; margin-bottom:16px;">
                    <li><?php esc_html_e( 'Add headings, paragraphs, images, and buttons as blocks.', 'deepwares-mailpro' ); ?></li>
                    <li><?php esc_html_e( 'Use columns and groups to structure your layout.', 'deepwares-mailpro' ); ?></li>
                    <li><?php esc_html_e( 'Save templates and reuse them across campaigns.', 'deepwares-mailpro' ); ?></li>
                </ul>

                <p style="margin-top:16px;">
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email' ) ); ?>"
                       class="button button-primary button-hero"
                       style="margin-right:8px;">
                        <?php esc_html_e( 'Create New Template', 'deepwares-mailpro' ); ?>
                    </a>

                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-templates' ) ); ?>"
                       class="button button-secondary">
                        <?php esc_html_e( 'Manage Existing Templates', 'deepwares-mailpro' ); ?>
                    </a>
                </p>

                <hr style="margin:18px 0;">

                <h3 style="margin:0 0 8px; font-size:14px;"><?php esc_html_e( 'Start from a pattern', 'deepwares-mailpro' ); ?></h3>
                <p style="font-size:12px; color:#6b7280; margin-top:0; margin-bottom:10px;">
                    <?php esc_html_e( 'Jump into Gutenberg with a ready-made layout. You can tweak the content and styling as needed.', 'deepwares-mailpro' ); ?>
                </p>

                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email&dwmp_pattern=hero_two_columns' ) ); ?>"
                       class="button button-secondary">
                        <?php esc_html_e( 'Hero + 2 Columns', 'deepwares-mailpro' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email&dwmp_pattern=feature_grid' ) ); ?>"
                       class="button button-secondary">
                        <?php esc_html_e( 'Feature Grid', 'deepwares-mailpro' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=dwmp_email&dwmp_pattern=footer_focus' ) ); ?>"
                       class="button button-secondary">
                        <?php esc_html_e( 'Footer / Compliance', 'deepwares-mailpro' ); ?>
                    </a>
                </div>

                <div style="margin-top:8px; padding:14px 14px 12px; border-radius:12px; background:#f9fafb; border:1px dashed #e5e7eb;">
                    <strong style="display:block; margin-bottom:4px; font-size:12px;"><?php esc_html_e( 'Tip', 'deepwares-mailpro' ); ?> 💡</strong>
                    <span style="font-size:12px; color:#6b7280;">
                        <?php esc_html_e( 'Use Email Categories (Newsletter, Promotion, Event, etc.) to organise your templates and filter them in the Templates view.', 'deepwares-mailpro' ); ?>
                    </span>
                </div>
            </section>

            <!-- RIGHT: Recent templates with thumbnails & categories -->
            <section style="background:#ffffff; border-radius:14px; border:1px solid #e5e7eb; padding:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Recent Templates', 'deepwares-mailpro' ); ?></h2>

                <?php if ( ! empty( $recent_emails ) ) : ?>
                    <div style="display:flex; flex-direction:column; gap:12px; margin-top:10px;">
                        <?php foreach ( $recent_emails as $email ) : ?>
                            <?php
                            $edit_link   = get_edit_post_link( $email->ID );
                            $title       = get_the_title( $email ) ?: __( '(no title)', 'deepwares-mailpro' );
                            $status      = ucfirst( $email->post_status );
                            $modified    = get_the_modified_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $email );
                            $thumb_html  = '';

                            if ( has_post_thumbnail( $email->ID ) ) {
                                $thumb_html = get_the_post_thumbnail(
                                    $email->ID,
                                    'thumbnail',
                                    array(
                                        'style' => 'width:64px;height:64px;object-fit:cover;border-radius:8px;'
                                    )
                                );
                            }

                            $category_labels = array();
                            $terms = get_the_terms( $email->ID, 'dwmp_email_category' );
                            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                                foreach ( $terms as $term ) {
                                    $category_labels[] = $term->name;
                                }
                            }
                            ?>
                            <a href="<?php echo esc_url( $edit_link ); ?>"
                               style="display:flex; gap:12px; align-items:center; padding:8px 10px; border-radius:10px; border:1px solid #e5e7eb; text-decoration:none; background:#ffffff; color:inherit;">
                                <div style="flex-shrink:0; width:64px; height:64px; border-radius:8px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                    <?php if ( $thumb_html ) : ?>
                                        <?php echo $thumb_html; ?>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-format-image" style="font-size:20px; color:#9ca3af;"></span>
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-weight:600; font-size:13px; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?php echo esc_html( $title ); ?>
                                    </div>
                                    <div style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:4px;">
                                        <span style="display:inline-block; padding:2px 6px; border-radius:999px; font-size:10px; background:#f3f4f6; color:#4b5563;">
                                            <?php echo esc_html( $status ); ?>
                                        </span>
                                        <?php if ( ! empty( $category_labels ) ) : ?>
                                            <?php foreach ( $category_labels as $cat_label ) : ?>
                                                <span style="display:inline-block; padding:2px 6px; border-radius:999px; font-size:10px; background:#eef2ff; color:#4f46e5;">
                                                    <?php echo esc_html( $cat_label ); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:11px; color:#9ca3af;">
                                        <?php
                                        printf(
                                            /* translators: %s: formatted date */
                                            esc_html__( 'Updated %s', 'deepwares-mailpro' ),
                                            esc_html( $modified )
                                        );
                                        ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p style="margin-top:10px;">
                        <?php esc_html_e( 'No email templates found yet. Click "Create New Template" to get started.', 'deepwares-mailpro' ); ?>
                    </p>
                <?php endif; ?>

                <div style="margin-top:14px; text-align:right;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dwmp-templates' ) ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'View All Templates', 'deepwares-mailpro' ); ?>
                    </a>
                </div>
            </section>
        </div>
    </div>
    <?php
}

endif;
