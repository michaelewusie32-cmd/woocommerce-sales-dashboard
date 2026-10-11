<?php
/*
Plugin Name: Sales Dashboard
Description: Daily, weekly, monthly, and total sales reports for WooCommerce.
Version: 1.0.0
Author: Your Name
License: GPL-2.0+
*/

if (!defined('ABSPATH')) {
    exit;
}

// Hook to admin menu
add_action('admin_menu', 'sales_dashboard_menu');

function sales_dashboard_menu() {
    add_menu_page(
        'Sales Dashboard',
        'Sales Dashboard',
        'manage_woocommerce',
        'sales-dashboard',
        'sales_dashboard_page',
        'dashicons-chart-bar',
        26
    );
}

/**
 * Get total sales for a given date range and optional user
 * Counts only 'completed' and 'processing' orders as actual sales
 */
function sales_dashboard_get_total_sales($start = null, $end = null, $user_id = null) {
    global $wpdb;

    $statuses = array('wc-completed', 'wc-processing');

    $query = "
        SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as total
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('" . implode("','", $statuses) . "')
        AND pm.meta_key = '_order_total'
    ";

    if ($start) {
        $start_date = date('Y-m-d 00:00:00', strtotime($start));
        $query .= $wpdb->prepare(" AND p.post_date >= %s", $start_date);
    }

    if ($end) {
        $end_date = date('Y-m-d 23:59:59', strtotime($end));
        $query .= $wpdb->prepare(" AND p.post_date <= %s", $end_date);
    }

    if ($user_id) {
        $query .= $wpdb->prepare(" 
            AND p.ID IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_customer_user' AND meta_value = %d
            )
        ", $user_id);
    }

    $result = $wpdb->get_var($query);
    return $result ? (float) $result : 0;
}

/**
 * Get sales summary for daily, weekly, monthly, and total
 */
function sales_dashboard_get_summary() {
    $daily_start = date('Y-m-d');
    $daily_end = date('Y-m-d');

    $weekly_start = date('Y-m-d', strtotime('monday this week'));
    $weekly_end = date('Y-m-d', strtotime('sunday this week'));

    $monthly_start = date('Y-m-01');
    $monthly_end = date('Y-m-t');

    return array(
        'daily'   => sales_dashboard_get_total_sales($daily_start, $daily_end),
        'weekly'  => sales_dashboard_get_total_sales($weekly_start, $weekly_end),
        'monthly' => sales_dashboard_get_total_sales($monthly_start, $monthly_end),
        'total'   => sales_dashboard_get_total_sales(),
    );
}

/**
 * Get sales breakdown by customer
 */
function sales_dashboard_get_user_sales() {
    global $wpdb;

    $statuses = array('wc-completed', 'wc-processing');

    $query = $wpdb->prepare("
        SELECT 
            pm_customer.meta_value as user_id,
            u.display_name,
            u.user_email,
            SUM(CAST(pm_total.meta_value AS DECIMAL(10,2))) as total_sales,
            COUNT(p.ID) as order_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_customer ON p.ID = pm_customer.post_id
        INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
        LEFT JOIN {$wpdb->users} u ON pm_customer.meta_value = u.ID
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('" . implode("','", $statuses) . "')
        AND pm_customer.meta_key = '_customer_user'
        AND pm_total.meta_key = '_order_total'
        GROUP BY pm_customer.meta_value, u.ID, u.display_name, u.user_email
        HAVING total_sales > 0
        ORDER BY total_sales DESC
    ");

    $results = $wpdb->get_results($query);
    return $results ? $results : array();
}

/**
 * Get recent orders
 */
function sales_dashboard_get_orders($limit = 20) {
    global $wpdb;

    $statuses = array('wc-completed', 'wc-processing');

    $query = "
        SELECT 
            p.ID,
            p.post_date,
            p.post_status,
            pm_total.meta_value as order_total,
            pm_customer.meta_value as customer_user,
            pm_email.meta_value as customer_email,
            pm_customer_name.meta_value as customer_name
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
        LEFT JOIN {$wpdb->postmeta} pm_customer ON p.ID = pm_customer.post_id AND pm_customer.meta_key = '_customer_user'
        LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
        LEFT JOIN {$wpdb->postmeta} pm_customer_name ON p.ID = pm_customer_name.post_id AND pm_customer_name.meta_key = '_billing_first_name'
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('" . implode("','", $statuses) . "')
        ORDER BY p.post_date DESC
        LIMIT " . intval($limit);

    $results = $wpdb->get_results($query);
    return $results ? $results : array();
}

/**
 * Get all orders with various statuses (for display purposes)
 */
function sales_dashboard_get_all_orders($limit = 50) {
    global $wpdb;

    $query = "
        SELECT 
            p.ID,
            p.post_date,
            p.post_status,
            pm_total.meta_value as order_total,
            pm_email.meta_value as customer_email
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
        LEFT JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
        WHERE p.post_type = 'shop_order'
        ORDER BY p.post_date DESC
        LIMIT " . intval($limit);

    $results = $wpdb->get_results($query);
    return $results ? $results : array();
}

/**
 * Display the admin page
 */
function sales_dashboard_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized access');
    }

    $summary = sales_dashboard_get_summary();
    $users = sales_dashboard_get_user_sales();
    $orders = sales_dashboard_get_orders(20);
    $all_orders = sales_dashboard_get_all_orders(50);
    ?>
    <div class="wrap">
        <h1>📊 WooCommerce Sales Dashboard</h1>
        <p style="color: #666; margin: 10px 0 30px 0;">Track your daily, weekly, monthly and total sales at a glance.</p>

        <!-- Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.9;">📅 Today's Sales</h3>
                <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo wc_price($summary['daily']); ?></p>
            </div>

            <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.9;">📆 This Week</h3>
                <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo wc_price($summary['weekly']); ?></p>
            </div>

            <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.9;">📊 This Month</h3>
                <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo wc_price($summary['monthly']); ?></p>
            </div>

            <div style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.9;">💰 Total All Time</h3>
                <p style="margin: 0; font-size: 28px; font-weight: bold;"><?php echo wc_price($summary['total']); ?></p>
            </div>
        </div>

        <!-- Sales by Customer -->
        <div style="background: white; padding: 20px; margin: 30px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2>👥 Sales by Customer</h2>
            <?php if (!empty($users)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 12px;">Customer Name</th>
                            <th style="padding: 12px;">Email</th>
                            <th style="padding: 12px; text-align: center;">Orders</th>
                            <th style="padding: 12px; text-align: right;">Total Sales</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user) : ?>
                            <tr>
                                <td style="padding: 12px;"><?php echo esc_html($user->display_name ? $user->display_name : 'Unknown'); ?></td>
                                <td style="padding: 12px;"><?php echo esc_html($user->user_email ? $user->user_email : 'N/A'); ?></td>
                                <td style="padding: 12px; text-align: center;"><strong><?php echo intval($user->order_count); ?></strong></td>
                                <td style="padding: 12px; text-align: right; font-weight: bold; color: #27ae60;"><?php echo wc_price($user->total_sales); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color: #999; padding: 20px;">No customer sales data found yet. Orders with "completed" or "processing" status will appear here.</p>
            <?php endif; ?>
        </div>

        <!-- Recent Completed/Processing Orders -->
        <div style="background: white; padding: 20px; margin: 30px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2>📦 Recent Processed Orders</h2>
            <p style="color: #666; margin: 0 0 15px 0;">Showing completed and processing orders only (actual sales)</p>
            <?php if (!empty($orders)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 12px;">Order ID</th>
                            <th style="padding: 12px;">Date</th>
                            <th style="padding: 12px;">Customer Email</th>
                            <th style="padding: 12px; text-align: center;">Status</th>
                            <th style="padding: 12px; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order) : ?>
                            <tr>
                                <td style="padding: 12px;">
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->ID . '&action=edit')); ?>" target="_blank">
                                        #<?php echo intval($order->ID); ?> →
                                    </a>
                                </td>
                                <td style="padding: 12px;"><?php echo esc_html(date('M d, Y H:i', strtotime($order->post_date))); ?></td>
                                <td style="padding: 12px;"><?php echo esc_html($order->customer_email ? $order->customer_email : 'Guest'); ?></td>
                                <td style="padding: 12px; text-align: center;">
                                    <span style="background: <?php echo $order->post_status === 'wc-completed' ? '#27ae60' : '#f39c12'; ?>; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
                                        <?php echo esc_html(ucfirst(str_replace('wc-', '', $order->post_status))); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; text-align: right; font-weight: bold; color: #2980b9;"><?php echo wc_price($order->order_total); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color: #999; padding: 20px;">No processed orders found yet.</p>
            <?php endif; ?>
        </div>

        <!-- All Orders (for reference) -->
        <div style="background: white; padding: 20px; margin: 30px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2>📋 All Orders (All Statuses)</h2>
            <p style="color: #666; margin: 0 0 15px 0;">Complete list including pending, on-hold, and cancelled orders</p>
            <?php if (!empty($all_orders)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 12px;">Order ID</th>
                            <th style="padding: 12px;">Date</th>
                            <th style="padding: 12px;">Customer Email</th>
                            <th style="padding: 12px; text-align: center;">Status</th>
                            <th style="padding: 12px; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_orders as $order) : 
                            $status_color = 'wc-completed' === $order->post_status ? '#27ae60' : 
                                          ('wc-processing' === $order->post_status ? '#f39c12' : 
                                          ('wc-pending' === $order->post_status ? '#3498db' : 
                                          ('wc-cancelled' === $order->post_status ? '#e74c3c' : '#95a5a6')));
                        ?>
                            <tr>
                                <td style="padding: 12px;">
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->ID . '&action=edit')); ?>" target="_blank">
                                        #<?php echo intval($order->ID); ?> →
                                    </a>
                                </td>
                                <td style="padding: 12px;"><?php echo esc_html(date('M d, Y H:i', strtotime($order->post_date))); ?></td>
                                <td style="padding: 12px;"><?php echo esc_html($order->customer_email ? $order->customer_email : 'Guest'); ?></td>
                                <td style="padding: 12px; text-align: center;">
                                    <span style="background: <?php echo $status_color; ?>; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
                                        <?php echo esc_html(ucfirst(str_replace('wc-', '', $order->post_status))); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; text-align: right; font-weight: bold;"><?php echo wc_price($order->order_total); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color: #999; padding: 20px;">No orders found.</p>
            <?php endif; ?>
        </div>

        <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; margin: 30px 0;">
            <p><strong>ℹ️ Note:</strong> Sales totals (daily, weekly, monthly, total) only count orders with "Completed" or "Processing" status. Other statuses are shown for reference but not included in sales calculations.</p>
        </div>
    </div>
    <?php
}
