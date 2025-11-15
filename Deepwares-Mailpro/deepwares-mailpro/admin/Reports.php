<?php
if (!defined('ABSPATH')) exit;

/**
 * Get campaign stats (dummy data by default, overridable via filter).
 *
 * @param int    $campaign_id
 * @param string $range       e.g. '7d', '30d', '90d', 'all'
 * @return array
 */
if (!function_exists('dwmp_get_campaign_stats')) :
function dwmp_get_campaign_stats($campaign_id, $range = '7d') {

    // --- Default example data (matches your mockup) ---
    $stats = [
        'meta' => [
            'name'       => 'Welcome Series - Week 1',
            'subject'    => 'Welcome to Our Community! 🎉',
            'sent_date'  => '2024-10-02',
            'list_name'  => 'Newsletter Subscribers',
            'range'      => $range,
        ],
        'summary' => [
            'total_sent'   => 1250,
            'delivered'    => 1215,
            'open_rate'    => 44,
            'click_rate'   => 15.1,
            'bounce_rate'  => 1.2,
            'unsubscribed' => 8,
        ],
        'time_series' => [
            // label, opens, clicks
            ['label' => '0h',  'opens' => 15,  'clicks' => 10],
            ['label' => '2h',  'opens' => 40,  'clicks' => 20],
            ['label' => '4h',  'opens' => 80,  'clicks' => 35],
            ['label' => '6h',  'opens' => 140, 'clicks' => 60],
            ['label' => '8h',  'opens' => 210, 'clicks' => 90],
            ['label' => '12h', 'opens' => 280, 'clicks' => 120],
            ['label' => '24h', 'opens' => 360, 'clicks' => 150],
        ],
        'funnel' => [
            ['label' => __('Delivered', 'deepwares-mailpro'), 'value' => 1400, 'color' => '#22c55e'],
            ['label' => __('Opened', 'deepwares-mailpro'),    'value' => 640,  'color' => '#0ea5e9'],
            ['label' => __('Clicked', 'deepwares-mailpro'),   'value' => 187,  'color' => '#f97316'],
            ['label' => __('Bounced', 'deepwares-mailpro'),   'value' => 15,   'color' => '#ef4444'],
        ],
        'devices' => [
            ['label' => __('Desktop', 'deepwares-mailpro'), 'percent' => 45, 'color' => '#3b82f6'],
            ['label' => __('Mobile',  'deepwares-mailpro'), 'percent' => 42, 'color' => '#22c55e'],
            ['label' => __('Tablet',  'deepwares-mailpro'), 'percent' => 13, 'color' => '#facc15'],
        ],
        'links' => [
            ['label' => __('Call to Action Button', 'deepwares-mailpro'), 'clicks' => 89, 'percent' => 47.6],
            ['label' => __('Learn More Link', 'deepwares-mailpro'),      'clicks' => 52, 'percent' => 27.8],
            ['label' => __('Footer Social Media', 'deepwares-mailpro'),  'clicks' => 28, 'percent' => 15.0],
            ['label' => __('Unsubscribe Link', 'deepwares-mailpro'),     'clicks' => 18, 'percent' => 9.6],
        ],
    ];

    /**
     * Filter: override stats from your own DB.
     *
     * Example (in a mu-plugin or functions.php):
     *
     * add_filter('dwmp_campaign_stats', function($stats, $campaign_id, $range){
     *     global $wpdb;
     *     $table = $wpdb->prefix . 'dwmp_campaign_stats';
     *     // Query your own table here and populate $stats...
     *     return $stats;
     * }, 10, 3);
     */
    return apply_filters('dwmp_campaign_stats', $stats, $campaign_id, $range);
}
endif;


if (!function_exists('dwmp_render_reports_page')) :
function dwmp_render_reports_page() {

    // ----------------------------------------------------
    // Build campaign list (from CPT if available, else demo)
    // ----------------------------------------------------
    $campaigns = [];

    if (post_type_exists('dwmp_campaign')) {
        $posts = get_posts([
            'post_type'      => 'dwmp_campaign',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        foreach ($posts as $p) {
            $campaigns[] = [
                'id'        => $p->ID,
                'label'     => $p->post_title ?: __('(Untitled campaign)', 'deepwares-mailpro'),
                'sent_date' => get_the_date('', $p),
            ];
        }
    }

    // Fallback demo campaigns if no CPT data
    if (empty($campaigns)) {
        $campaigns = [
            ['id' => 1, 'label' => __('Welcome Series - Week 1', 'deepwares-mailpro'), 'sent_date' => '2024-10-02'],
            ['id' => 2, 'label' => __('Black Friday Blast', 'deepwares-mailpro'),     'sent_date' => '2024-11-29'],
            ['id' => 3, 'label' => __('Re-engagement Campaign', 'deepwares-mailpro'), 'sent_date' => '2024-12-10'],
        ];
    }

    // ----------------------------------------------------
    // Read current selection (campaign + range)
    // ----------------------------------------------------
    $selected_campaign_id = isset($_GET['dwmp_campaign'])
        ? absint($_GET['dwmp_campaign'])
        : (int)$campaigns[0]['id'];

    $range = isset($_GET['dwmp_range'])
        ? sanitize_text_field($_GET['dwmp_range'])
        : '7d';

    $valid_ranges = ['7d','30d','90d','all'];
    if (!in_array($range, $valid_ranges, true)) {
        $range = '7d';
    }

    // Find selected label for the header
    $selected_campaign_label = '';
    $selected_sent_date      = '';
    foreach ($campaigns as $c) {
        if ((int)$c['id'] === $selected_campaign_id) {
            $selected_campaign_label = $c['label'];
            $selected_sent_date      = $c['sent_date'];
            break;
        }
    }

    // ----------------------------------------------------
    // Get stats for the selected campaign & range
    // ----------------------------------------------------
    $stats       = dwmp_get_campaign_stats($selected_campaign_id, $range);
    $summary     = $stats['summary'];
    $time_series = $stats['time_series'];
    $funnel      = $stats['funnel'];
    $devices     = $stats['devices'];
    $links       = $stats['links'];
    $meta        = $stats['meta'];

    // Override details if provided by stats
    if (!empty($meta['name']))      $selected_campaign_label = $meta['name'];
    if (!empty($meta['sent_date'])) $selected_sent_date      = $meta['sent_date'];

    ?>
    <div class="wrap dwmp-reports-wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Campaign Reports', 'deepwares-mailpro'); ?></h1>
        <p class="description">
            <?php esc_html_e('Detailed analytics for your campaigns', 'deepwares-mailpro'); ?>
        </p>

        <!-- Filter row -->
        <form method="get" style="margin-top:14px; margin-bottom:10px;">
            <!-- Keep the WordPress page param so we stay on this screen -->
            <input type="hidden" name="page" value="dwmp-reports" />
            <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:13px; color:#6b7280;"><?php esc_html_e('Campaign', 'deepwares-mailpro'); ?>:</span>
                    <select name="dwmp_campaign" style="min-width:260px;">
                        <?php foreach ($campaigns as $c): ?>
                            <option value="<?php echo esc_attr($c['id']); ?>" <?php selected((int)$c['id'], $selected_campaign_id); ?>>
                                <?php echo esc_html($c['label']); ?>
                                <?php if (!empty($c['sent_date'])) echo ' — ' . esc_html($c['sent_date']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:13px; color:#6b7280;"><?php esc_html_e('Date Range', 'deepwares-mailpro'); ?>:</span>
                    <select name="dwmp_range">
                        <option value="7d"  <?php selected($range, '7d');  ?>><?php esc_html_e('Last 7 days', 'deepwares-mailpro'); ?></option>
                        <option value="30d" <?php selected($range, '30d'); ?>><?php esc_html_e('Last 30 days', 'deepwares-mailpro'); ?></option>
                        <option value="90d" <?php selected($range, '90d'); ?>><?php esc_html_e('Last 90 days', 'deepwares-mailpro'); ?></option>
                        <option value="all" <?php selected($range, 'all'); ?>><?php esc_html_e('All time', 'deepwares-mailpro'); ?></option>
                    </select>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'deepwares-mailpro'); ?></button>
                </div>
            </div>
        </form>

        <!-- Top metric cards -->
        <div style="display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:14px; margin-bottom:16px;">
            <!-- Total Sent -->
            <div class="dwmp-report-card" style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:16px;">
                <div style="font-size:12px; color:#6b7280; margin-bottom:4px;"><?php esc_html_e('Total Sent', 'deepwares-mailpro'); ?></div>
                <div style="font-size:22px; font-weight:600;"><?php echo number_format_i18n($summary['total_sent']); ?></div>
                <div style="font-size:11px; color:#9ca3af;"><?php echo esc_html(number_format_i18n($summary['delivered'])); esc_html_e(' delivered', 'deepwares-mailpro'); ?></div>
            </div>

            <!-- Open Rate -->
            <div class="dwmp-report-card" style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:16px;">
                <div style="font-size:12px; color:#6b7280; margin-bottom:4px;"><?php esc_html_e('Open Rate', 'deepwares-mailpro'); ?></div>
                <div style="font-size:22px; font-weight:600;"><?php echo esc_html($summary['open_rate']); ?>%</div>
                <div style="font-size:11px; color:#16a34a;"><?php esc_html_e('▲ Above average', 'deepwares-mailpro'); ?></div>
            </div>

            <!-- Click Rate -->
            <div class="dwmp-report-card" style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:16px;">
                <div style="font-size:12px; color:#6b7280; margin-bottom:4px;"><?php esc_html_e('Click Rate', 'deepwares-mailpro'); ?></div>
                <div style="font-size:22px; font-weight:600;"><?php echo esc_html($summary['click_rate']); ?>%</div>
                <div style="font-size:11px; color:#9ca3af;"><?php esc_html_e('Total clicks', 'deepwares-mailpro'); ?></div>
            </div>

            <!-- Bounce Rate -->
            <div class="dwmp-report-card" style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:16px;">
                <div style="font-size:12px; color:#6b7280; margin-bottom:4px;"><?php esc_html_e('Bounce Rate', 'deepwares-mailpro'); ?></div>
                <div style="font-size:22px; font-weight:600;"><?php echo esc_html($summary['bounce_rate']); ?>%</div>
                <div style="font-size:11px; color:#f97316;"><?php esc_html_e('Bounced emails', 'deepwares-mailpro'); ?></div>
            </div>

            <!-- Unsubscribed -->
            <div class="dwmp-report-card" style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:16px;">
                <div style="font-size:12px; color:#6b7280; margin-bottom:4px;"><?php esc_html_e('Unsubscribed', 'deepwares-mailpro'); ?></div>
                <div style="font-size:22px; font-weight:600;"><?php echo esc_html($summary['unsubscribed']); ?></div>
                <div style="font-size:11px; color:#9ca3af;"><?php esc_html_e('Unsubscribe rate', 'deepwares-mailpro'); ?></div>
            </div>
        </div>

        <!-- Middle row: charts -->
        <div style="display:grid; grid-template-columns:1.2fr 1fr; gap:16px; margin-bottom:16px;">
            <!-- Engagement Over Time -->
            <div style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:18px;">
                <div style="font-weight:600; margin-bottom:4px;"><?php esc_html_e('Engagement Over Time', 'deepwares-mailpro'); ?></div>
                <div style="font-size:12px; color:#9ca3af; margin-bottom:10px;"><?php esc_html_e('Opens and clicks across send window', 'deepwares-mailpro'); ?></div>

                <div style="height:220px; position:relative; border-radius:10px; background:linear-gradient(to top, #f9fafb, #f3f4f6); padding:12px;">
                    <div style="position:absolute; left:12px; top:12px; right:12px; bottom:24px; display:flex; align-items:flex-end; gap:10px;">
                        <?php foreach ($time_series as $point):
                            $clicks = max(1, (int)$point['clicks']);
                            $opens  = max(1, (int)$point['opens']);
                            $height_click = min(100, $clicks / 3);
                            $height_open  = min(100, $opens  / 4);
                            ?>
                            <div style="flex:1; display:flex; flex-direction:column; justify-content:flex-end; gap:4px;">
                                <div style="height:<?php echo $height_open; ?>px; border-radius:6px 6px 0 0; background:#22c55e;"></div>
                                <div style="height:<?php echo $height_click; ?>px; border-radius:6px 6px 0 0; background:#0ea5e9; opacity:.9;"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="position:absolute; left:12px; right:12px; bottom:4px; display:flex; justify-content:space-between; font-size:10px; color:#9ca3af;">
                        <?php foreach ($time_series as $point): ?>
                            <span><?php echo esc_html($point['label']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:8px; display:flex; gap:14px; font-size:12px; color:#6b7280;">
                    <span><span style="display:inline-block; width:10px; height:10px; border-radius:999px; background:#0ea5e9; margin-right:4px;"></span><?php esc_html_e('Clicks', 'deepwares-mailpro'); ?></span>
                    <span><span style="display:inline-block; width:10px; height:10px; border-radius:999px; background:#22c55e; margin-right:4px;"></span><?php esc_html_e('Opens', 'deepwares-mailpro'); ?></span>
                </div>
            </div>

            <!-- Engagement Funnel -->
            <div style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:18px;">
                <div style="font-weight:600; margin-bottom:4px;"><?php esc_html_e('Engagement Funnel', 'deepwares-mailpro'); ?></div>
                <div style="font-size:12px; color:#9ca3af; margin-bottom:10px;"><?php esc_html_e('From delivered to clicks', 'deepwares-mailpro'); ?></div>

                <?php
                $max = 0;
                foreach ($funnel as $row) {
                    if ($row['value'] > $max) $max = $row['value'];
                }
                ?>

                <div style="margin-top:8px; display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($funnel as $row):
                        $width = $max > 0 ? ($row['value'] / $max) * 100 : 0;
                        ?>
                        <div>
                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:2px;">
                                <span><?php echo esc_html($row['label']); ?></span>
                                <span style="color:#6b7280;"><?php echo esc_html($row['value']); ?></span>
                            </div>
                            <div style="height:14px; background:#f3f4f6; border-radius:999px; overflow:hidden;">
                                <div style="width:<?php echo $width; ?>%; height:100%; background:<?php echo esc_attr($row['color']); ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Bottom row: device breakdown & top links -->
        <div style="display:grid; grid-template-columns:1.1fr 1.1fr; gap:16px; margin-bottom:16px;">
            <!-- Device Breakdown -->
            <div style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:18px;">
                <div style="font-weight:600; margin-bottom:4px;"><?php esc_html_e('Device Breakdown', 'deepwares-mailpro'); ?></div>
                <div style="font-size:12px; color:#9ca3af; margin-bottom:10px;"><?php esc_html_e('Where your subscribers read this email', 'deepwares-mailpro'); ?></div>

                <div style="display:flex; align-items:center; gap:24px; margin-top:10px;">
                    <!-- Fake pie chart (CSS only) -->
                    <div style="position:relative; width:150px; height:150px;">
                        <?php
                        // Build conic-gradient string from device percentages
                        $offset = 0;
                        $segments = [];
                        foreach ($devices as $d) {
                            $start = $offset;
                            $end   = $offset + $d['percent'];
                            $segments[] = $d['color'] . ' ' . $start . '% ' . $end . '%';
                            $offset = $end;
                        }
                        $gradient = implode(', ', $segments);
                        ?>
                        <div style="position:absolute; inset:0; border-radius:999px; background:conic-gradient(<?php echo esc_attr($gradient); ?>);"></div>
                        <div style="position:absolute; inset:22px; border-radius:999px; background:#ffffff;"></div>
                        <div style="position:absolute; inset:38px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600;">
                            <?php echo esc_html(sprintf(__('Desktop %d%%', 'deepwares-mailpro'), $devices[0]['percent'] ?? 0)); ?>
                        </div>
                    </div>
                    <div style="font-size:13px; color:#374151;">
                        <?php foreach ($devices as $d): ?>
                            <p style="margin:0 0 6px;">
                                <span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:<?php echo esc_attr($d['color']); ?>;margin-right:6px;"></span>
                                <?php echo esc_html($d['label'] . ': ' . $d['percent'] . '%'); ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Top Clicked Links -->
            <div style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:18px;">
                <div style="font-weight:600; margin-bottom:4px;"><?php esc_html_e('Top Clicked Links', 'deepwares-mailpro'); ?></div>
                <div style="font-size:12px; color:#9ca3af; margin-bottom:10px;"><?php esc_html_e('Most engaged CTAs from this campaign', 'deepwares-mailpro'); ?></div>

                <?php
                $max_clicks = 0;
                foreach ($links as $row) {
                    if ($row['clicks'] > $max_clicks) $max_clicks = $row['clicks'];
                }
                ?>

                <div style="margin-top:6px; display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($links as $row):
                        $width = $max_clicks > 0 ? ($row['clicks'] / $max_clicks) * 100 : 0;
                        ?>
                        <div>
                            <div style="display:flex; justify-content:space-between; font-size:13px; margin-bottom:3px;">
                                <span><?php echo esc_html($row['label']); ?></span>
                                <span style="font-size:11px; color:#6b7280;">
                                    <?php echo esc_html($row['clicks'] . ' ' . sprintf(__('clicks (%.1f%%)', 'deepwares-mailpro'), $row['percent'])); ?>
                                </span>
                            </div>
                            <div style="height:8px; border-radius:999px; background:#e5e7eb; overflow:hidden;">
                                <div style="width:<?php echo $width; ?>%; height:100%; border-radius:999px; background:#3b82f6;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Campaign Details -->
        <div style="background:#fff; border-radius:12px; border:1px solid #e5e7eb; padding:16px; margin-top:8px;">
            <div style="font-weight:600; margin-bottom:10px;"><?php esc_html_e('Campaign Details', 'deepwares-mailpro'); ?></div>
            <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; font-size:13px;">
                <div>
                    <div style="color:#9ca3af;"><?php esc_html_e('Campaign Name', 'deepwares-mailpro'); ?></div>
                    <div><?php echo esc_html($meta['name'] ?? $selected_campaign_label); ?></div>
                </div>
                <div>
                    <div style="color:#9ca3af;"><?php esc_html_e('Subject Line', 'deepwares-mailpro'); ?></div>
                    <div><?php echo esc_html($meta['subject'] ?? __('(Unknown)', 'deepwares-mailpro')); ?></div>
                </div>
                <div>
                    <div style="color:#9ca3af;"><?php esc_html_e('Sent Date', 'deepwares-mailpro'); ?></div>
                    <div><?php echo esc_html($meta['sent_date'] ?? $selected_sent_date); ?></div>
                </div>
                <div>
                    <div style="color:#9ca3af;"><?php esc_html_e('List', 'deepwares-mailpro'); ?></div>
                    <div><?php echo esc_html($meta['list_name'] ?? __('Newsletter Subscribers', 'deepwares-mailpro')); ?></div>
                </div>
            </div>
        </div>
    </div>
<?php
}
endif;
