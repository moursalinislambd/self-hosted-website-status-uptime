<?php
/*
 * Plugin Name:       Self Hosted Uptime Monitor
 * Plugin URI:        https://github.com/moursalinislambd/self-hosted-website-status-uptime
 * Description:       This Is Fully Self hosted. Just Install it and Check Wp-admin Dashboard
 * Version:           1.0
 * Author:            Moursalin Islam
 * Author URI:        https://www.facebook.com/onexusdev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       self-hosted-uptime
 * Domain Path:       /languages
 * Tags: islamic,quran,verse,quran verse, daily quran, Bangladesh
 * Requires at least: 5.0
 * Tested up to:      6.8.1
 * Requires PHP:      7.0
 * Update URI:        https://mosquesofbangladesh.xyz/post-category/wp-plugin/
 * ------------------------------------------------------------------------
 * This plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * This plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this plugin. If not, see <https://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

class Self_Uptime_Monitor {
    
    private $log_file;
    private $incidents_file;
    private $monitor_url;
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/uptime-logs.json';
        $this->incidents_file = WP_CONTENT_DIR . '/uptime-incidents.json';
        $this->monitor_url = home_url();
        
        // Initialize files
        $this->init_files();
        
        // Schedule monitoring
        add_action('init', array($this, 'schedule_monitoring'));
        add_action('self_uptime_check', array($this, 'perform_check'));
        
        // Create dashboard page
        add_action('admin_menu', array($this, 'add_admin_page'));
        
        // Add shortcode
        add_shortcode('uptime_status', array($this, 'uptime_shortcode'));
        
        // Add REST API endpoint
        add_action('rest_api_init', array($this, 'register_api_routes'));
    }
    
    private function init_files() {
        if (!file_exists($this->log_file)) {
            file_put_contents($this->log_file, json_encode(array()));
        }
        
        if (!file_exists($this->incidents_file)) {
            file_put_contents($this->incidents_file, json_encode(array()));
        }
    }
    
    public function schedule_monitoring() {
        if (!wp_next_scheduled('self_uptime_check')) {
            wp_schedule_event(time(), 'five_minutes', 'self_uptime_check');
        }
    }
    
    // Custom schedule interval
    public function add_cron_interval($schedules) {
        $schedules['five_minutes'] = array(
            'interval' => 300, // 5 minutes
            'display' => __('Every 5 Minutes')
        );
        return $schedules;
    }
    
    public function perform_check() {
        $start_time = microtime(true);
        
        // Check if site is accessible
        $response = wp_remote_get($this->monitor_url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        
        $end_time = microtime(true);
        $response_time = round(($end_time - $start_time) * 1000, 2); // Convert to ms
        
        if (is_wp_error($response)) {
            $status = 'down';
            $status_code = 0;
            $this->record_incident($response->get_error_message());
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $status = ($status_code >= 200 && $status_code < 400) ? 'up' : 'down';
            
            if ($status === 'down') {
                $this->record_incident("HTTP {$status_code}");
            }
        }
        
        $this->log_check(array(
            'timestamp' => current_time('timestamp'),
            'date' => current_time('Y-m-d H:i:s'),
            'status' => $status,
            'response_time' => $response_time,
            'status_code' => $status_code
        ));
    }
    
    private function log_check($data) {
        $logs = $this->get_logs();
        $logs[] = $data;
        
        // Keep only last 30 days of data (5 min checks = 8640 entries)
        $thirty_days_ago = current_time('timestamp') - (30 * 86400);
        $logs = array_filter($logs, function($log) use ($thirty_days_ago) {
            return $log['timestamp'] > $thirty_days_ago;
        });
        
        // Keep array indexes sequential
        $logs = array_values($logs);
        
        file_put_contents($this->log_file, json_encode($logs));
    }
    
    private function record_incident($reason) {
        $incidents = json_decode(file_get_contents($this->incidents_file), true);
        
        $last_incident = end($incidents);
        
        // If last incident was within 30 minutes and still ongoing, update it
        if ($last_incident && 
            $last_incident['resolved'] === false && 
            (current_time('timestamp') - $last_incident['start_time']) < 1800) {
            
            $incidents[count($incidents) - 1]['last_seen'] = current_time('timestamp');
            $incidents[count($incidents) - 1]['duration'] = 
                current_time('timestamp') - $last_incident['start_time'];
        } else {
            // New incident
            $incidents[] = array(
                'start_time' => current_time('timestamp'),
                'start_date' => current_time('Y-m-d H:i:s'),
                'last_seen' => current_time('timestamp'),
                'reason' => $reason,
                'resolved' => false,
                'resolved_time' => null,
                'duration' => 0
            );
        }
        
        file_put_contents($this->incidents_file, json_encode($incidents));
    }
    
    public function resolve_incidents() {
        $incidents = json_decode(file_get_contents($this->incidents_file), true);
        
        foreach ($incidents as $key => $incident) {
            if (!$incident['resolved']) {
                // Check if site is now up
                $logs = $this->get_logs();
                $last_log = end($logs);
                
                if ($last_log && $last_log['status'] === 'up') {
                    $incidents[$key]['resolved'] = true;
                    $incidents[$key]['resolved_time'] = current_time('timestamp');
                    $incidents[$key]['duration'] = 
                        $incidents[$key]['resolved_time'] - $incident['start_time'];
                }
            }
        }
        
        file_put_contents($this->incidents_file, json_encode($incidents));
    }
    
    public function get_logs($limit = null) {
        if (!file_exists($this->log_file)) {
            return array();
        }
        
        $logs = json_decode(file_get_contents($this->log_file), true);
        
        if ($limit && count($logs) > $limit) {
            $logs = array_slice($logs, -$limit);
        }
        
        return $logs ?: array();
    }
    
    public function get_uptime_stats($days = 30) {
        $logs = $this->get_logs();
        
        if (empty($logs)) {
            return array(
                'uptime_percentage' => 0,
                'avg_response_time' => 0,
                'total_checks' => 0,
                'successful_checks' => 0,
                'failed_checks' => 0
            );
        }
        
        $cutoff = current_time('timestamp') - ($days * 86400);
        $recent_logs = array_filter($logs, function($log) use ($cutoff) {
            return $log['timestamp'] > $cutoff;
        });
        
        if (empty($recent_logs)) {
            return array('uptime_percentage' => 0, 'avg_response_time' => 0);
        }
        
        $total = count($recent_logs);
        $up_count = 0;
        $total_response_time = 0;
        $response_times = array();
        
        foreach ($recent_logs as $log) {
            if ($log['status'] === 'up') {
                $up_count++;
                $response_times[] = $log['response_time'];
                $total_response_time += $log['response_time'];
            }
        }
        
        $uptime_percentage = ($total > 0) ? round(($up_count / $total) * 100, 3) : 0;
        $avg_response_time = ($up_count > 0) ? round($total_response_time / $up_count, 2) : 0;
        
        // Calculate p95 response time
        sort($response_times);
        $p95_index = floor(count($response_times) * 0.95);
        $p95_response_time = isset($response_times[$p95_index]) ? $response_times[$p95_index] : 0;
        
        return array(
            'uptime_percentage' => $uptime_percentage,
            'avg_response_time' => $avg_response_time,
            'p95_response_time' => $p95_response_time,
            'total_checks' => $total,
            'successful_checks' => $up_count,
            'failed_checks' => $total - $up_count
        );
    }
    
    public function add_admin_page() {
        add_menu_page(
            'Uptime Monitor',
            'Uptime Monitor',
            'manage_options',
            'self-uptime-monitor',
            array($this, 'admin_page_content'),
            'dashicons-chart-area',
            30
        );
    }
    
    public function admin_page_content() {
        $stats = $this->get_uptime_stats(30);
        $logs = $this->get_logs(100);
        $incidents = json_decode(file_get_contents($this->incidents_file), true);
        ?>
        <div class="wrap">
            <h1>Uptime Monitor Dashboard</h1>
            
            <div class="uptime-stats-grid">
                <div class="stat-card">
                    <h3>30-Day Uptime</h3>
                    <div class="stat-value"><?php echo $stats['uptime_percentage']; ?>%</div>
                </div>
                
                <div class="stat-card">
                    <h3>Avg Response Time</h3>
                    <div class="stat-value"><?php echo $stats['avg_response_time']; ?> ms</div>
                </div>
                
                <div class="stat-card">
                    <h3>Total Checks</h3>
                    <div class="stat-value"><?php echo $stats['total_checks']; ?></div>
                </div>
                
                <div class="stat-card">
                    <h3>Current Status</h3>
                    <div class="stat-value status-indicator">
                        <?php 
                        $last_log = end($logs);
                        if ($last_log && $last_log['status'] === 'up') {
                            echo '<span style="color: #2ecc71;">🟢 Operational</span>';
                        } else {
                            echo '<span style="color: #e74c3c;">🔴 Down</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <div class="uptime-chart-container">
                <h2>Response Time History (Last 24 Hours)</h2>
                <canvas id="responseTimeChart" width="800" height="200"></canvas>
            </div>
            
            <div class="recent-incidents">
                <h2>Recent Incidents</h2>
                <?php if (!empty($incidents)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Start Time</th>
                                <th>Duration</th>
                                <th>Reason</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse(array_slice($incidents, -10)) as $incident): ?>
                                <tr>
                                    <td><?php echo $incident['start_date']; ?></td>
                                    <td>
                                        <?php 
                                        $duration = $incident['duration'] ?: 
                                                   (current_time('timestamp') - $incident['start_time']);
                                        echo $this->format_duration($duration);
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($incident['reason']); ?></td>
                                    <td>
                                        <?php if ($incident['resolved']): ?>
                                            <span style="color: #2ecc71;">Resolved</span>
                                        <?php else: ?>
                                            <span style="color: #e74c3c;">Ongoing</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No incidents recorded in the last 30 days.</p>
                <?php endif; ?>
            </div>
            
            <div class="recent-checks">
                <h2>Recent Checks</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Response Time</th>
                            <th>HTTP Code</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <tr>
                                <td><?php echo $log['date']; ?></td>
                                <td>
                                    <?php if ($log['status'] === 'up'): ?>
                                        <span style="color: #2ecc71;">Up</span>
                                    <?php else: ?>
                                        <span style="color: #e74c3c;">Down</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $log['response_time']; ?> ms</td>
                                <td><?php echo $log['status_code']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .uptime-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            color: #555;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
        }
        .uptime-chart-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .recent-incidents, .recent-checks {
            margin: 30px 0;
        }
        </style>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            fetch('<?php echo rest_url('self-uptime/v1/chart-data'); ?>')
                .then(response => response.json())
                .then(data => {
                    renderChart(data);
                });
        });
        
        function renderChart(data) {
            var ctx = document.getElementById('responseTimeChart').getContext('2d');
            var chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Response Time (ms)',
                        data: data.values,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Response Time (ms)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Time'
                            }
                        }
                    }
                }
            });
        }
        </script>
        <?php
    }
    
    public function uptime_shortcode($atts) {
        $atts = shortcode_atts(array(
            'days' => 30,
            'show_chart' => true
        ), $atts);
        
        $stats = $this->get_uptime_stats($atts['days']);
        $logs = $this->get_logs(1);
        $last_log = end($logs);
        
        ob_start();
        ?>
        <div class="uptime-status-widget">
            <div class="uptime-header">
                <h3>Website Status</h3>
                <div class="current-status">
                    Status: 
                    <?php if ($last_log && $last_log['status'] === 'up'): ?>
                        <span class="status-up">🟢 Operational</span>
                    <?php else: ?>
                        <span class="status-down">🔴 Down</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="uptime-stats">
                <div class="stat">
                    <span class="stat-label">Uptime (<?php echo $atts['days']; ?> days):</span>
                    <span class="stat-value"><?php echo $stats['uptime_percentage']; ?>%</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Avg Response Time:</span>
                    <span class="stat-value"><?php echo $stats['avg_response_time']; ?> ms</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Last Check:</span>
                    <span class="stat-value"><?php echo $last_log ? $last_log['date'] : 'Never'; ?></span>
                </div>
            </div>
            
            <?php if ($atts['show_chart']): ?>
                <div class="uptime-chart">
                    <canvas id="uptimeChartShortcode" width="400" height="150"></canvas>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    fetch('<?php echo rest_url('self-uptime/v1/chart-data?hours=24'); ?>')
                        .then(response => response.json())
                        .then(data => {
                            var ctx = document.getElementById('uptimeChartShortcode').getContext('2d');
                            new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: data.labels,
                                    datasets: [{
                                        label: 'Response Time',
                                        data: data.values,
                                        borderColor: '#3498db',
                                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                        borderWidth: 2,
                                        fill: true
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: { legend: { display: false } },
                                    scales: {
                                        y: { beginAtZero: true }
                                    }
                                }
                            });
                        });
                });
                </script>
            <?php endif; ?>
        </div>
        
        <style>
        .uptime-status-widget {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            max-width: 600px;
            margin: 20px auto;
        }
        .uptime-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        .uptime-header h3 {
            margin: 0;
        }
        .status-up { color: #2ecc71; font-weight: bold; }
        .status-down { color: #e74c3c; font-weight: bold; }
        .uptime-stats {
            margin: 15px 0;
        }
        .stat {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            padding: 8px;
            background: white;
            border-radius: 4px;
        }
        .stat-label {
            color: #666;
        }
        .stat-value {
            font-weight: bold;
            color: #333;
        }
        .uptime-chart {
            margin-top: 20px;
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    public function register_api_routes() {
        register_rest_route('self-uptime/v1', '/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_stats'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('self-uptime/v1', '/chart-data', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_chart_data'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('self-uptime/v1', '/force-check', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_force_check'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
    }
    
    public function api_get_stats() {
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        return $this->get_uptime_stats($days);
    }
    
    public function api_get_chart_data() {
        $hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
        $logs = $this->get_logs();
        
        $cutoff = current_time('timestamp') - ($hours * 3600);
        $recent_logs = array_filter($logs, function($log) use ($cutoff) {
            return $log['timestamp'] > $cutoff;
        });
        
        $labels = array();
        $values = array();
        
        foreach ($recent_logs as $log) {
            $labels[] = date('H:i', $log['timestamp']);
            $values[] = $log['response_time'];
        }
        
        return array(
            'labels' => $labels,
            'values' => $values
        );
    }
    
    public function api_force_check() {
        $this->perform_check();
        $this->resolve_incidents();
        
        return array(
            'success' => true,
            'message' => 'Check performed successfully'
        );
    }
    
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return round($seconds) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    $self_uptime_monitor = new Self_Uptime_Monitor();
    
    // Add custom cron schedule
    add_filter('cron_schedules', array($self_uptime_monitor, 'add_cron_interval'));
});

// Activation hook
register_activation_hook(__FILE__, function() {
    // Create log files
    if (!file_exists(WP_CONTENT_DIR . '/uptime-logs.json')) {
        file_put_contents(WP_CONTENT_DIR . '/uptime-logs.json', json_encode(array()));
    }
    if (!file_exists(WP_CONTENT_DIR . '/uptime-incidents.json')) {
        file_put_contents(WP_CONTENT_DIR . '/uptime-incidents.json', json_encode(array()));
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clear scheduled event
    $timestamp = wp_next_scheduled('self_uptime_check');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'self_uptime_check');
    }

});
