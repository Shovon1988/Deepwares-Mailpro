<?php
// admin/Dashboard.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dwmp_render_dashboard_page' ) ) :

function dwmp_render_dashboard_page() {
    global $wpdb;
    $p = $wpdb->prefix;

    $subs_table     = $p . 'dwmp_subscribers';
    $campaigns_table= $p . 'dwmp_campaigns';
    $events_table   = $p . 'dwmp_events';

    $subs_exists      = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $subs_table ) ) === $subs_table;
    $campaigns_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $campaigns_table ) ) === $campaigns_table;
    $events_exists    = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $events_table ) ) === $events_table;

    // ------------------------------------------------------------------
    // CSV exports
    // ------------------------------------------------------------------
    if ( isset( $_GET['dwmp_export_performance'] ) && check_admin_referer( 'dwmp_export_performance' ) ) {
        dwmp_dashboard_export_performance_csv( $wpdb, $p, $events_exists );
        return;
    }
    if ( isset( $_GET['dwmp_export_status'] ) && check_admin_referer( 'dwmp_export_status' ) ) {
        dwmp_dashboard_export_status_csv( $wpdb, $p, $subs_exists );
        return;
    }
    if ( isset( $_GET['dwmp_export_recent'] ) && check_admin_referer( 'dwmp_export_recent' ) ) {
        dwmp_dashboard_export_recent_csv( $wpdb, $p, $campaigns_exists, $events_exists );
        return;
    }

    // ------------------------------------------------------------------
    // Date range filter
    // ------------------------------------------------------------------
    $range = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '6m';

    $events_date_cond    = '1=1';
    $campaigns_date_cond = '1=1';

    if ( '7d' === $range ) {
        $events_date_cond    = "e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $campaigns_date_cond = "c.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ( '30d' === $range ) {
        $events_date_cond    = "e.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $campaigns_date_cond = "c.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } else { // 6m default
        $events_date_cond    = "e.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
        $campaigns_date_cond = "c.updated_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
    }

    // ------------------------------------------------------------------
    // Top metrics
    // ------------------------------------------------------------------
    $total_subscribers = 0;
    if ( $subs_exists ) {
        $total_subscribers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs_table}" );
    }

    $campaigns_sent = 0;
    if ( $campaigns_exists ) {
        $campaigns_sent = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$campaigns_table} c
            WHERE c.status='sent' AND {$campaigns_date_cond}
        ");
    }

    $sent_events = $opens_events = $clicks_events = 0;
    if ( $events_exists ) {
        $sent_events   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} e WHERE {$events_date_cond} AND e.type='delivered'" );
        $opens_events  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} e WHERE {$events_date_cond} AND e.type='open'" );
        $clicks_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} e WHERE {$events_date_cond} AND e.type='click'" );
    }

    $avg_open_rate  = $sent_events > 0 ? round( ( $opens_events  / $sent_events ) * 100, 1 ) : 0;
    $avg_click_rate = $sent_events > 0 ? round( ( $clicks_events / $sent_events ) * 100, 1 ) : 0;

    // Delivery Health KPI
    $delivered     = $sent_events;
    $delivery_rate = $sent_events > 0 ? round( ( $delivered / $sent_events ) * 100, 1 ) : 0;

    // Trend indicators (this month vs last month)
    $this_month_sent = $last_month_sent = 0;
    if ( $campaigns_exists ) {
        $this_month_sent = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$campaigns_table}
            WHERE status='sent' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())
        ");
        $last_month_sent = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$campaigns_table}
            WHERE status='sent'
              AND MONTH(updated_at)=MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
              AND YEAR(updated_at)=YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))
        ");
    }
    $trend_campaigns = $last_month_sent > 0
        ? round( ( ( $this_month_sent - $last_month_sent ) / $last_month_sent ) * 100, 1 )
        : ( $this_month_sent > 0 ? 100 : 0 );

    // ------------------------------------------------------------------
    // Campaign performance (group by month)
    // ------------------------------------------------------------------
    $labels = $sent_series = $open_series = $click_series = array();

    if ( $events_exists ) {
        $performance_rows = $wpdb->get_results("
            SELECT DATE_FORMAT(e.created_at, '%b') AS month_label,
                   SUM(e.type='delivered') AS sent,
                   SUM(e.type='open')      AS opened,
                   SUM(e.type='click')     AS clicked
            FROM {$events_table} e
            WHERE {$events_date_cond}
            GROUP BY YEAR(e.created_at), MONTH(e.created_at)
            ORDER BY YEAR(e.created_at), MONTH(e.created_at)
        ");

        foreach ( $performance_rows as $r ) {
            $labels[]       = esc_js( $r->month_label );
            $sent_series[]  = (int) $r->sent;
            $open_series[]  = (int) $r->opened;
            $click_series[] = (int) $r->clicked;
        }
    }

    if ( empty( $labels ) ) {
        $labels       = array( 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct' );
        $sent_series  = array_fill( 0, count( $labels ), 0 );
        $open_series  = array_fill( 0, count( $labels ), 0 );
        $click_series = array_fill( 0, count( $labels ), 0 );
    }

    // ------------------------------------------------------------------
    // Subscriber status distribution
    // ------------------------------------------------------------------
    $status_labels = $status_counts = array();

    if ( $subs_exists ) {
        $status_rows = $wpdb->get_results("
            SELECT status, COUNT(*) AS c
            FROM {$subs_table}
            GROUP BY status
        ");
        foreach ( $status_rows as $sr ) {
            $status_labels[] = esc_js( $sr->status );
            $status_counts[] = (int) $sr->c;
        }
    }
    if ( empty( $status_labels ) ) {
        $status_labels = array( 'active', 'unsubscribed', 'bounced' );
        $status_counts = array( 0, 0, 0 );
    }

    // ------------------------------------------------------------------
    // Recent Campaign Activity
    // ------------------------------------------------------------------
    $recent_data = array();
    if ( $campaigns_exists ) {
        $recent_campaigns = $wpdb->get_results("
            SELECT c.id, c.name, c.status, c.scheduled_at, c.created_at, c.updated_at
            FROM {$campaigns_table} c
            ORDER BY c.updated_at DESC, c.created_at DESC
            LIMIT 5
        ");

        if ( ! empty( $recent_campaigns ) && $events_exists ) {
            $ids = implode( ',', array_map( 'intval', wp_list_pluck( $recent_campaigns, 'id' ) ) );
            if ( ! empty( $ids ) ) {
                $agg = $wpdb->get_results("
                    SELECT e.campaign_id,
                           SUM(e.type='delivered') AS sent,
                           SUM(e.type='open')      AS opens,
                           SUM(e.type='click')     AS clicks
                    FROM {$events_table} e
                    WHERE e.campaign_id IN ($ids)
                    GROUP BY e.campaign_id
                ", OBJECT_K );

                foreach ( $recent_campaigns as $c ) {
                    $cid = (int) $c->id;
                    $recent_data[] = array(
                        'id'      => $cid,
                        'name'    => $c->name,
                        'status'  => $c->status,
                        'sent_at' => $c->updated_at, // proxy for sent timestamp
                        'sent'    => isset( $agg[ $cid ] ) ? (int) $agg[ $cid ]->sent  : 0,
                        'opens'   => isset( $agg[ $cid ] ) ? (int) $agg[ $cid ]->opens : 0,
                        'clicks'  => isset( $agg[ $cid ] ) ? (int) $agg[ $cid ]->clicks: 0,
                    );
                }
            }
        }
    }

    // IMPORTANT: your dashboard slug is "deepwares-mailpro"
    $base_url = admin_url( 'admin.php?page=deepwares-mailpro' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Dashboard Overview', 'deepwares-mailpro' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Track your email marketing performance.', 'deepwares-mailpro' ); ?></p>

        <!-- Date Range Filter -->
        <form method="get" class="dwmp-filters">
            <input type="hidden" name="page" value="deepwares-mailpro">
            <label for="range"><?php esc_html_e( 'Date Range:', 'deepwares-mailpro' ); ?></label>
            <select name="range" id="range" onchange="this.form.submit()">
                <option value="7d"  <?php selected( $range, '7d' );  ?>><?php esc_html_e( 'Last 7 Days', 'deepwares-mailpro' ); ?></option>
                <option value="30d" <?php selected( $range, '30d' ); ?>><?php esc_html_e( 'Last 30 Days', 'deepwares-mailpro' ); ?></option>
                <option value="6m"  <?php selected( $range, '6m' );  ?>><?php esc_html_e( 'Last 6 Months', 'deepwares-mailpro' ); ?></option>
            </select>
        </form>

        <!-- Top Stats Grid -->
        <div class="dwmp-grid dwmp-stats">
            <div class="dwmp-card">
                <div class="dwmp-icon dwmp-blue"><span class="dashicons dashicons-groups"></span></div>
                <div class="dwmp-metric">
                    <h3><?php echo (int) $total_subscribers; ?></h3>
                    <p><?php esc_html_e( 'Total Subscribers', 'deepwares-mailpro' ); ?></p>
                    <small>&nbsp;</small>
                </div>
            </div>
            <div class="dwmp-card">
                <div class="dwmp-icon dwmp-green"><span class="dashicons dashicons-email-alt"></span></div>
                <div class="dwmp-metric">
                    <h3><?php echo (int) $campaigns_sent; ?></h3>
                    <p><?php esc_html_e( 'Campaigns Sent', 'deepwares-mailpro' ); ?></p>
                    <small><?php echo ( $trend_campaigns >= 0 ? '+' : '' ) . $trend_campaigns; ?>% <?php esc_html_e( 'vs last month', 'deepwares-mailpro' ); ?></small>
                </div>
            </div>
            <div class="dwmp-card">
                <div class="dwmp-icon dwmp-orange"><span class="dashicons dashicons-visibility"></span></div>
                <div class="dwmp-metric">
                    <h3><?php echo esc_html( $avg_open_rate ); ?>%</h3>
                    <p><?php esc_html_e( 'Avg. Open Rate', 'deepwares-mailpro' ); ?></p>
                    <small><?php echo (int) $sent_events; ?> <?php esc_html_e( 'delivered', 'deepwares-mailpro' ); ?>, <?php echo (int) $opens_events; ?> <?php esc_html_e( 'opens', 'deepwares-mailpro' ); ?></small>
                </div>
            </div>
            <div class="dwmp-card">
                <div class="dwmp-icon dwmp-red"><span class="dashicons dashicons-chart-line"></span></div>
                <div class="dwmp-metric">
                    <h3><?php echo esc_html( $avg_click_rate ); ?>%</h3>
                    <p><?php esc_html_e( 'Avg. Click Rate', 'deepwares-mailpro' ); ?></p>
                    <small><?php echo (int) $clicks_events; ?> <?php esc_html_e( 'clicks', 'deepwares-mailpro' ); ?></small>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="dwmp-grid dwmp-charts">
            <div class="dwmp-card dwmp-chart-card">
                <h2><?php esc_html_e( 'Campaign Performance (Last 6 Months)', 'deepwares-mailpro' ); ?></h2>
                <p class="subtitle"><?php esc_html_e( 'Sent, Opened, Clicked trends', 'deepwares-mailpro' ); ?></p>
                <div class="dwmp-chart-container">
                    <canvas id="dwmp-campaign-performance"></canvas>
                </div>
                <div class="dwmp-card-footer">
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'dwmp_export_performance' => 1, 'range' => $range ), $base_url ), 'dwmp_export_performance' ) ); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Export report', 'deepwares-mailpro' ); ?>
                    </a>
                </div>
            </div>

            <div class="dwmp-card dwmp-chart-card">
                <h2><?php esc_html_e( 'Subscriber Status Distribution', 'deepwares-mailpro' ); ?></h2>
                <p class="subtitle"><?php esc_html_e( 'Active vs Unsubscribed vs Bounced', 'deepwares-mailpro' ); ?></p>
                <div class="dwmp-chart-container">
                    <canvas id="dwmp-subscriber-status"></canvas>
                </div>
                <div class="dwmp-card-footer">
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'dwmp_export_status' => 1 ), $base_url ), 'dwmp_export_status' ) ); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Export report', 'deepwares-mailpro' ); ?>
                    </a>
                </div>
            </div>

            <div class="dwmp-card dwmp-chart-card dwmp-kpi-card">
                <h2><?php esc_html_e( 'Delivery Health', 'deepwares-mailpro' ); ?></h2>
                <div class="dwmp-kpi">
                    <div class="dwmp-kpi-value"><?php echo esc_html( $delivery_rate ); ?>%</div>
                    <div class="dwmp-kpi-label"><?php esc_html_e( 'Delivery Rate', 'deepwares-mailpro' ); ?></div>
                    <div class="dwmp-kpi-sub">
                        <?php
                        printf(
                            esc_html__( '%1$s delivered of %2$s sent', 'deepwares-mailpro' ),
                            (int) $delivered,
                            (int) $sent_events
                        );
                        ?>
                    </div>
                </div>
                <div class="dwmp-card-footer"></div>
            </div>
        </div>

        <!-- Recent Campaign Activity -->
        <div class="dwmp-card">
            <h2><?php esc_html_e( 'Recent Campaign Activity', 'deepwares-mailpro' ); ?></h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Campaign', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Date Sent', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Sent', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Opens', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Clicks', 'deepwares-mailpro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'deepwares-mailpro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $recent_data ) ) : ?>
                        <?php foreach ( $recent_data as $rc ) : ?>
                            <tr>
                                <td><?php echo esc_html( $rc['name'] ); ?></td>
                                <td><?php echo esc_html( $rc['sent_at'] ); ?></td>
                                <td><?php echo (int) $rc['sent']; ?></td>
                                <td><?php echo (int) $rc['opens']; ?></td>
                                <td><?php echo (int) $rc['clicks']; ?></td>
                                <td>
                                    <?php if ( 'sent' === $rc['status'] ) : ?>
                                        <span class="dwmp-badge dwmp-status-sent"><?php esc_html_e( 'Sent', 'deepwares-mailpro' ); ?></span>
                                    <?php elseif ( 'scheduled' === $rc['status'] ) : ?>
                                        <span class="dwmp-badge dwmp-status-scheduled"><?php esc_html_e( 'Scheduled', 'deepwares-mailpro' ); ?></span>
                                    <?php elseif ( 'sending' === $rc['status'] ) : ?>
                                        <span class="dwmp-badge dwmp-status-sending"><?php esc_html_e( 'Sending', 'deepwares-mailpro' ); ?></span>
                                    <?php else : ?>
                                        <span class="dwmp-badge dwmp-status-draft"><?php esc_html_e( 'Draft', 'deepwares-mailpro' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6"><em><?php esc_html_e( 'No campaigns yet.', 'deepwares-mailpro' ); ?></em></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'dwmp_export_recent' => 1 ), $base_url ), 'dwmp_export_recent' ) ); ?>">
                <span class="dashicons dashicons-download"></span>
                <?php esc_html_e( 'Export recent activity', 'deepwares-mailpro' ); ?>
            </a>
        </div>

        <!-- Styles -->
        <style>
            .dwmp-filters { margin-bottom:15px; display:flex; gap:8px; align-items:center; }
            .dwmp-grid { display:grid; gap:20px; margin:20px 0; }
            .dwmp-stats { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }

            .dwmp-charts {
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
                align-items: stretch;
            }
            @media (max-width: 1200px) { .dwmp-charts { grid-template-columns: repeat(2, 1fr); } }
            @media (max-width: 900px)  { .dwmp-charts { grid-template-columns: 1fr; } }

            .dwmp-card {
                background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px;
            }

            .dwmp-chart-card {
                display:flex; flex-direction:column; height:400px;
            }
            .dwmp-chart-container {
                flex:1; min-height:0;
            }
            .dwmp-chart-container canvas {
                width:100%; height:100%;
            }
            .dwmp-card-footer {
                margin-top:12px; display:flex; justify-content:flex-start;
            }

            .dwmp-kpi-card .dwmp-kpi {
                margin:auto; text-align:center;
            }
            .dwmp-kpi-value { font-size:42px; font-weight:700; line-height:1; }
            .dwmp-kpi-label { color:#666; margin-top:6px; font-size:14px; }
            .dwmp-kpi-sub   { color:#888; margin-top:4px; font-size:12px; }

            .subtitle { margin:0 0 10px; color:#888; font-size:13px; }

            .dwmp-icon {
                width:40px; height:40px; border-radius:50%;
                display:flex; align-items:center; justify-content:center; color:#fff; margin-bottom:10px;
            }
            .dwmp-blue { background:#0073aa; }
            .dwmp-green { background:#46b450; }
            .dwmp-orange { background:#ff9800; }
            .dwmp-red { background:#dc3232; }

            .dwmp-metric h3 { margin:0; font-size:24px; }
            .dwmp-metric p  { margin:0; color:#666; }
            .dwmp-metric small { color:#888; }

            .dwmp-badge { padding:2px 8px; border-radius:12px; font-size:12px; color:#fff; }
            .dwmp-status-sent { background:#46b450; }
            .dwmp-status-scheduled { background:#ff9800; }
            .dwmp-status-sending { background:#2271b1; }
            .dwmp-status-draft { background:#6c757d; }
        </style>

        <!-- Chart.js via CDN -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            const perfLabels   = <?php echo wp_json_encode( $labels ); ?>;
            const perfSent     = <?php echo wp_json_encode( $sent_series ); ?>;
            const perfOpened   = <?php echo wp_json_encode( $open_series ); ?>;
            const perfClicked  = <?php echo wp_json_encode( $click_series ); ?>;

            const statusLabels = <?php echo wp_json_encode( $status_labels ); ?>;
            const statusCounts = <?php echo wp_json_encode( $status_counts ); ?>;

            // Campaign Performance Line Chart
            new Chart(document.getElementById('dwmp-campaign-performance').getContext('2d'), {
                type: 'line',
                data: {
                    labels: perfLabels,
                    datasets: [
                        { label:'Sent',    data: perfSent,   borderColor:'#0073aa', backgroundColor:'rgba(0,115,170,0.15)', tension:0.3, fill:true },
                        { label:'Opened',  data: perfOpened, borderColor:'#46b450', backgroundColor:'rgba(70,180,80,0.15)', tension:0.3, fill:true },
                        { label:'Clicked', data: perfClicked, borderColor:'#ff9800', backgroundColor:'rgba(255,152,0,0.15)', tension:0.3, fill:true }
                    ]
                },
                options: {
                    responsive:true,
                    maintainAspectRatio:false,
                    plugins: {
                        tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y}` } },
                        legend:  { position: 'bottom' }
                    },
                    scales: { y: { beginAtZero:true, ticks: { precision:0 } } }
                }
            });

            // Subscriber Status Pie Chart
            new Chart(document.getElementById('dwmp-subscriber-status').getContext('2d'), {
                type: 'pie',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: ['#46b450','#dc3232','#ff9800','#2271b1','#6c757d']
                    }]
                },
                options: {
                    responsive:true,
                    plugins: {
                        tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed} (${percentage(ctx.parsed, ctx.chart)})` } },
                        legend:  { position: 'bottom' }
                    }
                }
            });

            function percentage(value, chart) {
                const total = chart.data.datasets[0].data.reduce((a,b)=>a+b,0);
                if (!total) return '0%';
                return ((value/total)*100).toFixed(1) + '%';
            }
        </script>
    </div>
    <?php
}

/**
 * Export: Campaign performance CSV
 */
function dwmp_dashboard_export_performance_csv( $wpdb, $p, $events_exists ) {
    if ( ! $events_exists ) {
        wp_die( esc_html__( 'Events table not found.', 'deepwares-mailpro' ) );
    }

    $events_table = $p . 'dwmp_events';

    $rows = $wpdb->get_results("
        SELECT DATE_FORMAT(e.created_at, '%Y-%m') AS month,
               SUM(e.type='delivered') AS sent,
               SUM(e.type='open')      AS opened,
               SUM(e.type='click')     AS clicked
        FROM {$events_table} e
        WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(e.created_at), MONTH(e.created_at)
        ORDER BY YEAR(e.created_at), MONTH(e.created_at)
    ", ARRAY_A);

    header( 'Content-Type: text/csv' );
    header( 'Content-Disposition: attachment;filename=campaign_performance.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Month', 'Sent', 'Opened', 'Clicked' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, $r );
    }
    fclose( $out );
    exit;
}

/**
 * Export: Subscriber status CSV
 */
function dwmp_dashboard_export_status_csv( $wpdb, $p, $subs_exists ) {
    if ( ! $subs_exists ) {
        wp_die( esc_html__( 'Subscribers table not found.', 'deepwares-mailpro' ) );
    }

    $subs_table = $p . 'dwmp_subscribers';

    $rows = $wpdb->get_results("
        SELECT status, COUNT(*) AS count
        FROM {$subs_table}
        GROUP BY status
    ", ARRAY_A);

    header( 'Content-Type: text/csv' );
    header( 'Content-Disposition: attachment;filename=subscriber_status.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Status', 'Count' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, $r );
    }
    fclose( $out );
    exit;
}

/**
 * Export: Recent campaign activity CSV
 */
function dwmp_dashboard_export_recent_csv( $wpdb, $p, $campaigns_exists, $events_exists ) {
    if ( ! $campaigns_exists ) {
        wp_die( esc_html__( 'Campaigns table not found.', 'deepwares-mailpro' ) );
    }

    $campaigns_table = $p . 'dwmp_campaigns';
    $events_table    = $p . 'dwmp_events';

    $campaigns = $wpdb->get_results("
        SELECT c.id, c.name, c.status, c.updated_at
        FROM {$campaigns_table} c
        ORDER BY c.updated_at DESC, c.created_at DESC
        LIMIT 20
    ");

    $ids = ! empty( $campaigns ) ? implode( ',', array_map( 'intval', wp_list_pluck( $campaigns, 'id' ) ) ) : '';
    $agg = array();

    if ( $ids && $events_exists ) {
        $agg = $wpdb->get_results("
            SELECT e.campaign_id,
                   SUM(e.type='delivered') AS sent,
                   SUM(e.type='open')      AS opens,
                   SUM(e.type='click')     AS clicks
            FROM {$events_table} e
            WHERE e.campaign_id IN ($ids)
            GROUP BY e.campaign_id
        ", OBJECT_K );
    }

    header( 'Content-Type: text/csv' );
    header( 'Content-Disposition: attachment;filename=recent_campaign_activity.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'Campaign', 'Status', 'Date', 'Sent', 'Opens', 'Clicks' ) );

    foreach ( $campaigns as $c ) {
        $cid = (int) $c->id;
        $row = array(
            $c->name,
            $c->status,
            $c->updated_at,
            isset( $agg[ $cid ] ) ? (int) $agg[ $cid ]->sent  : 0,
            isset( $agg[ $cid ] ) ? (int) $agg[ $cid ]->opens : 0,
            isset( $agg[ $cid ] ) ? (int) $agg[ $cid ]->clicks: 0,
        );
        fputcsv( $out, $row );
    }
    fclose( $out );
    exit;
}

endif;
