<?php
/**
 * @package Gitec_Sync
 * Plugin Name: Gitec Product Sync for WooCommerce
 * Description: Synchronizes WooCommerce products with Gitec API.
 * Version: 1.0
 * Author: Goga Trapaidze
 */

class GitecWooSync {
    private $api_url = 'https://b2b.gitec.ge/restapi/';
    private static $instance = null;
    private $log_table_name;

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new GitecWooSync();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->log_table_name = $wpdb->prefix . 'gitec_sync_logs';
        
        register_activation_hook(__FILE__, array($this, 'create_logs_table'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_start_gitec_sync', array($this, 'ajax_start_sync'));
        add_action('wp_ajax_check_gitec_sync_progress', array($this, 'ajax_check_progress'));
        add_action('do_gitec_sync', array($this, 'runSync'));
        
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        add_action('init', array($this, 'schedule_sync'));
        add_action('gitec_auto_sync', array($this, 'auto_sync_products'));
    }

    public function create_logs_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql_logs = "CREATE TABLE IF NOT EXISTS {$this->log_table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            message text NOT NULL,
            status varchar(50) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        $sql_history = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}gitec_sync_history (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_date datetime DEFAULT CURRENT_TIMESTAMP,
            sync_type varchar(50) NOT NULL,
            product_sku varchar(255) NOT NULL,
            change_type varchar(50) NOT NULL,
            old_value text,
            new_value text,
            PRIMARY KEY  (id),
            KEY sync_date (sync_date),
            KEY product_sku (product_sku)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_logs);
        dbDelta($sql_history);
    }

    private function log_product_change($sku, $change_type, $old_value = null, $new_value = null, $sync_type = 'auto') {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'gitec_sync_history',
            array(
                'sync_type' => $sync_type,
                'product_sku' => $sku,
                'change_type' => $change_type,
                'old_value' => $old_value,
                'new_value' => $new_value
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

    public function log($message, $status = 'info') {
        global $wpdb;
        
        $wpdb->insert(
            $this->log_table_name,
            array(
                'message' => $message,
                'status' => $status
            ),
            array('%s', '%s')
        );
    }

    public function add_admin_menu() {
        add_menu_page(
            'gITec სინქრონიზაცია',
            'gITec სინქრონიზაცია',
            'manage_options',
            'gitec-sync',
            array($this, 'main_page'),
            'dashicons-update'
        );

        add_submenu_page(
            'gitec-sync',
            'პარამეტრები',
            'პარამეტრები',
            'manage_options',
            'gitec-sync-settings',
            array($this, 'settings_page')
        );

        add_submenu_page(
            'gitec-sync',
            'ლოგები',
            'ლოგები',
            'manage_options',
            'gitec-sync-logs',
            array($this, 'logs_page')
        );

        add_submenu_page(
            'gitec-sync',
            'სინქრონიზაციის ისტორია',
            'ისტორია',
            'manage_options',
            'gitec-sync-history',
            array($this, 'history_page')
        );
    }

    public function register_settings() {
        register_setting('gitec_sync_options', 'gitec_sync_username');
        register_setting('gitec_sync_options', 'gitec_sync_password');
        register_setting('gitec_sync_options', 'gitec_sync_batch_size', array(
            'default' => 50
        ));
        register_setting('gitec_sync_options', 'gitec_sync_auto_enabled', array(
            'default' => 0
        ));
        register_setting('gitec_sync_options', 'gitec_sync_interval', array(
            'default' => 'hourly'
        ));
        
        add_settings_section(
            'gitec_sync_main',
            'API პარამეტრები',
            null,
            'gitec-sync-settings'
        );
        
        add_settings_field(
            'gitec_sync_username',
            'მომხმარებელი',
            array($this, 'username_field_callback'),
            'gitec-sync-settings',
            'gitec_sync_main'
        );
        
        add_settings_field(
            'gitec_sync_password',
            'პაროლი',
            array($this, 'password_field_callback'),
            'gitec-sync-settings',
            'gitec_sync_main'
        );

        add_settings_field(
            'gitec_sync_batch_size',
            'Batch ზომა',
            array($this, 'batch_size_field_callback'),
            'gitec-sync-settings',
            'gitec_sync_main'
        );

        add_settings_field(
            'gitec_sync_auto_enabled',
            'ავტომატური სინქრონიზაცია',
            array($this, 'auto_sync_field_callback'),
            'gitec-sync-settings',
            'gitec_sync_main'
        );

        add_settings_field(
            'gitec_sync_interval',
            'სინქრონიზაციის ინტერვალი',
            array($this, 'sync_interval_field_callback'),
            'gitec-sync-settings',
            'gitec_sync_main'
        );
    }

    public function username_field_callback() {
        $username = get_option('gitec_sync_username');
        echo '<input type="text" name="gitec_sync_username" value="' . esc_attr($username) . '" class="regular-text">';
    }

    public function password_field_callback() {
        $password = get_option('gitec_sync_password');
        echo '<input type="password" name="gitec_sync_password" value="' . esc_attr($password) . '" class="regular-text">';
    }

    public function batch_size_field_callback() {
        $batch_size = get_option('gitec_sync_batch_size', 50);
        echo '<input type="number" name="gitec_sync_batch_size" value="' . esc_attr($batch_size) . '" class="small-text" min="10" max="100">';
        echo '<p class="description">პროდუქტების რაოდენობა ერთ batch-ში (10-100)</p>';
    }

    public function auto_sync_field_callback() {
        $enabled = get_option('gitec_sync_auto_enabled', 0);
        echo '<input type="checkbox" name="gitec_sync_auto_enabled" value="1" ' . checked(1, $enabled, false) . '>';
        echo '<p class="description">ჩართეთ ავტომატური სინქრონიზაცია</p>';
    }

    public function sync_interval_field_callback() {
        $interval = get_option('gitec_sync_interval', 'hourly');
        $intervals = array(
            'thirty_minutes' => 'ყოველ 30 წუთში',
            'hourly' => 'ყოველ საათში',
            'six_hours' => 'ყოველ 6 საათში',
            'daily' => 'ყოველ 24 საათში'
        );
        
        echo '<select name="gitec_sync_interval">';
        foreach ($intervals as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($interval, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function main_page() {
        if (!wp_script_is('jquery', 'enqueued')) {
            wp_enqueue_script('jquery');
        }
        ?>
        <div class="wrap">
            <h1>gITec პროდუქტების სინქრონიზაცია</h1>
            <form method="post" id="sync-form">
                <?php wp_nonce_field('run_gitec_sync_action', 'gitec_sync_nonce'); ?>
                <input type="submit" name="run_sync" class="button button-primary" value="სინქრონიზაციის გაშვება">
            </form>
            
            <div id="sync-progress" style="display: none; margin-top: 20px;">
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width: 0%"></div>
                </div>
                <p class="progress-status"></p>
                <p class="sync-message"></p>
            </div>
            
            <?php
            global $wpdb;
            $last_sync = $wpdb->get_row("SELECT sync_date FROM {$wpdb->prefix}gitec_sync_history ORDER BY sync_date DESC LIMIT 1");
            if ($last_sync) {
                echo '<div class="sync-info" style="margin-top: 20px;">';
                echo '<h3>ბოლო სინქრონიზაცია</h3>';
                echo '<p>თარიღი: ' . esc_html($last_sync->sync_date) . '</p>';
                echo '</div>';
            }
            ?>
        </div>

        <style>
            .progress-bar {
                width: 100%;
                height: 20px;
                background-color: #f0f0f1;
                border-radius: 3px;
                margin-bottom: 10px;
            }
            .progress-bar-fill {
                height: 100%;
                background-color: #2271b1;
                border-radius: 3px;
                transition: width 0.3s ease-in-out;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#sync-form').on('submit', function(e) {
                e.preventDefault();
                
                $('#sync-progress').show();
                $('.progress-status').text('სინქრონიზაცია მიმდინარეობს...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'start_gitec_sync',
                        nonce: $('#gitec_sync_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            checkSyncProgress();
                        } else {
                            $('.sync-message').html('შეცდომა: ' + response.data.message);
                        }
                    }
                });
            });

            function checkSyncProgress() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'check_gitec_sync_progress',
                        nonce: $('#gitec_sync_nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.progress-bar-fill').css('width', response.data.progress + '%');
                            $('.progress-status').text(response.data.status);
                            $('.sync-message').html(response.data.message);
                            
                            if (!response.data.is_completed) {
                                setTimeout(checkSyncProgress, 2000);
                            } else {
                                location.reload();
                            }
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>gITec სინქრონიზაციის პარამეტრები</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('gitec_sync_options');
                do_settings_sections('gitec-sync-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function logs_page() {
        global $wpdb;
        
        if (isset($_POST['clear_logs']) && check_admin_referer('clear_logs_action', 'clear_logs_nonce')) {
            $wpdb->query("TRUNCATE TABLE {$this->log_table_name}");
            echo '<div class="notice notice-success"><p>ლოგები წაიშალა</p></div>';
        }
        
        $logs = $wpdb->get_results("SELECT * FROM {$this->log_table_name} ORDER BY timestamp DESC LIMIT 100");
        
        ?>
        <div class="wrap">
            <h1>სინქრონიზაციის ლოგები</h1>
            
            <form method="post">
                <?php wp_nonce_field('clear_logs_action', 'clear_logs_nonce'); ?>
                <input type="submit" name="clear_logs" class="button button-secondary" value="ლოგების გასუფთავება" 
                       onclick="return confirm('დარწმუნებული ხართ?');">
            </form>
            
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>დრო</th>
                        <th>სტატუსი</th>
                        <th>შეტყობინება</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td>
                                <span class="log-status <?php echo esc_attr($log->status); ?>">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <style>
        .log-status {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .log-status.info { background: #e5f5fa; color: #0286c2; }
        .log-status.success { background: #e7f7ed; color: #0a6b2d; }
        .log-status.error { background: #fae7e7; color: #dc2626; }
    </style>
    <?php
}

public function history_page() {
    global $wpdb;
    
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
    
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gitec_sync_history");
    
    $query = "SELECT * FROM {$wpdb->prefix}gitec_sync_history";
    if (!empty($filter_type)) {
        $query .= $wpdb->prepare(" WHERE change_type = %s", $filter_type);
    }
    $query .= " ORDER BY sync_date DESC LIMIT %d OFFSET %d";
    
    $history = $wpdb->get_results(
        $wpdb->prepare($query, $per_page, $offset)
    );
    
    ?>
    <div class="wrap">
        <h1>სინქრონიზაციის ისტორია</h1>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <form method="get">
                    <input type="hidden" name="page" value="gitec-sync-history">
                    <select name="filter_type">
                        <option value="">ყველა ტიპი</option>
                        <option value="price_update" <?php selected($filter_type, 'price_update'); ?>>ფასის განახლება</option>
                        <option value="stock_update" <?php selected($filter_type, 'stock_update'); ?>>მარაგის განახლება</option>
                        <option value="new_product" <?php selected($filter_type, 'new_product'); ?>>ახალი პროდუქტი</option>
                    </select>
                    <?php submit_button('ფილტრი', 'button', 'filter_submit', false); ?>
                </form>
            </div>
        </div>
        
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>თარიღი</th>
                    <th>სინქრონიზაციის ტიპი</th>
                    <th>SKU</th>
                    <th>ცვლილების ტიპი</th>
                    <th>ძველი მნიშვნელობა</th>
                    <th>ახალი მნიშვნელობა</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $item): 
                    $change_type_label = '';
                    switch ($item->change_type) {
                        case 'price_update':
                            $change_type_label = 'ფასის განახლება';
                            break;
                        case 'stock_update':
                            $change_type_label = 'მარაგის განახლება';
                            break;
                        case 'new_product':
                            $change_type_label = 'ახალი პროდუქტი';
                            break;
                    }
                    
                    $sync_type_label = $item->sync_type === 'auto' ? 'ავტომატური' : 'ხელით';
                ?>
                    <tr>
                        <td><?php echo esc_html($item->sync_date); ?></td>
                        <td><?php echo esc_html($sync_type_label); ?></td>
                        <td><?php echo esc_html($item->product_sku); ?></td>
                        <td><?php echo esc_html($change_type_label); ?></td>
                        <td><?php echo esc_html($item->old_value); ?></td>
                        <td><?php echo esc_html($item->new_value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php
        $total_pages = ceil($total_items / $per_page);
        if ($total_pages > 1) {
            $base_url = add_query_arg('filter_type', $filter_type);
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%', $base_url),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $total_pages,
                'current' => $current_page
            ));
            echo '</div></div>';
        }
        ?>
    </div>
    <?php
}

public function add_cron_intervals($schedules) {
    $schedules['thirty_minutes'] = array(
        'interval' => 1800,
        'display' => 'ყოველ 30 წუთში'
    );
    $schedules['six_hours'] = array(
        'interval' => 21600,
        'display' => 'ყოველ 6 საათში'
    );
    return $schedules;
}

public function schedule_sync() {
    $enabled = get_option('gitec_sync_auto_enabled', 0);
    $interval = get_option('gitec_sync_interval', 'hourly');
    
    $timestamp = wp_next_scheduled('gitec_auto_sync');
    if ($enabled) {
        if (!$timestamp) {
            wp_schedule_event(time(), $interval, 'gitec_auto_sync');
        } else {
            $current_interval = wp_get_schedule('gitec_auto_sync');
            if ($current_interval !== $interval) {
                wp_clear_scheduled_hook('gitec_auto_sync');
                wp_schedule_event(time(), $interval, 'gitec_auto_sync');
            }
        }
    } else if ($timestamp) {
        wp_clear_scheduled_hook('gitec_auto_sync');
    }
}

public function auto_sync_products() {
    $this->log('ავტომატური სინქრონიზაცია დაიწყო', 'info');
    
    $products = $this->getGitecProducts();
    if (!$products) return;

    $updated_count = 0;
    $new_count = 0;
    $price_changes = 0;
    $stock_changes = 0;

    foreach ($products as $product_data) {
        try {
            $product_id = wc_get_product_id_by_sku($product_data['Sku']);
            
            if ($product_id) {
                $product = wc_get_product($product_id);
                if (!$product) continue;

                $changes_made = false;

                // ფასის შემოწმება
                $old_price = $product->get_regular_price();
                $new_price = strval($product_data['ProductPrice']['PriceValue']);
                if ($old_price != $new_price) {
                    $this->log_product_change(
                        $product_data['Sku'],
                        'price_update',
                        $old_price,
                        $new_price,
                        'auto'
                    );
                    $product->set_regular_price($new_price);
                    $price_changes++;
                    $changes_made = true;
                }

                // მარაგის შემოწმება
                if (isset($product_data['CustomProperties']['Avaliable Quantity'])) {
                    $old_stock = $product->get_stock_quantity();
                    $new_stock = intval($product_data['CustomProperties']['Avaliable Quantity']);
                    if ($old_stock != $new_stock) {
                        $this->log_product_change(
                            $product_data['Sku'],
                            'stock_update',
                            $old_stock,
                            $new_stock,
                            'auto'
                        );
                        $product->set_manage_stock(true);
                        $product->set_stock_quantity($new_stock);
                        $product->set_stock_status($new_stock > 0 ? 'instock' : 'outofstock');
                        $stock_changes++;
                        $changes_made = true;
                    }
                }

                if ($changes_made) {
                    $product->save();
                    $updated_count++;
                }
            } else {
                // ახალი პროდუქტის შექმნა
                if ($this->syncProduct($product_data, 'auto')) {
                    $new_count++;
                }
            }
        } catch (Exception $e) {
            $this->log("შეცდომა პროდუქტის დამუშავებისას ({$product_data['Sku']}): " . $e->getMessage(), 'error');
            continue;
        }
    }
    
    $this->log(sprintf(
        'სინქრონიზაცია დასრულდა: %d პროდუქტი განახლდა (ფასი: %d, მარაგი: %d), %d ახალი პროდუქტი დაემატა',
        $updated_count,
        $price_changes,
        $stock_changes,
        $new_count
    ), 'success');
}

public function ajax_start_sync() {
    check_ajax_referer('run_gitec_sync_action', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'არასაკმარისი უფლებები']);
    }

    update_option('gitec_sync_status', [
        'is_running' => true,
        'progress' => 0,
        'message' => 'სინქრონიზაცია იწყება...',
        'processed' => 0,
        'total' => 0
    ]);

    wp_schedule_single_event(time(), 'do_gitec_sync');
    
    wp_send_json_success();
}

public function ajax_check_progress() {
    check_ajax_referer('run_gitec_sync_action', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'არასაკმარისი უფლებები']);
    }

    $status = get_option('gitec_sync_status', []);
    
    wp_send_json_success([
        'is_completed' => !isset($status['is_running']) || !$status['is_running'],
        'progress' => isset($status['progress']) ? $status['progress'] : 0,
        'status' => isset($status['message']) ? $status['message'] : '',
        'message' => sprintf(
            'დამუშავებულია: %d / %d',
            isset($status['processed']) ? $status['processed'] : 0,
            isset($status['total']) ? $status['total'] : 0
        )
    ]);
}

public function getGitecProducts() {
    $username = get_option('gitec_sync_username');
    $password = get_option('gitec_sync_password');
    
    if (empty($username) || empty($password)) {
        $this->log('API მომხმარებელი ან პაროლი არ არის მითითებული', 'error');
        return false;
    }

    $args = array(
        'headers' => array(
            'username' => $username,
            'password' => $password
        ),
        'timeout' => 120,
        'httpversion' => '1.1',
        'sslverify' => false,
        'blocking' => true,
        'cookies' => array(),
        'body' => null,
        'compress' => false,
        'decompress' => true,
        'stream' => false,
        'connect_timeout' => 30
    );

    $max_retries = 5;
    $retry_delay = 10;
    $backoff_factor = 1.5;

    for ($retry = 1; $retry <= $max_retries; $retry++) {
        $this->log("API მოთხოვნის მცდელობა {$retry}/{$max_retries}", 'info');
        
        $response = wp_remote_get($this->api_url . 'products?language=ge', $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("API შეცდომა (მცდელობა {$retry}/{$max_retries}): {$error_message}", 'error');
            
            if ($retry === $max_retries) {
                return false;
            }
            
            $wait_time = $retry_delay * pow($backoff_factor, $retry - 1);
            $this->log("მომდევნო მცდელობამდე დარჩენილია {$wait_time} წამი", 'info');
            sleep($wait_time);
            continue;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 503 || $response_code === 504 || $response_code === 408) {
            if ($retry === $max_retries) {
                $this->log("სერვერის შეცდომა (კოდი: {$response_code})", 'error');
                return false;
            }
            
            $wait_time = $retry_delay * pow($backoff_factor, $retry - 1);
            $this->log("მომდევნო მცდელობამდე დარჩენილია {$wait_time} წამი", 'info');
            sleep($wait_time);
            continue;
        }

        if ($response_code !== 200) {
            $this->log("მოულოდნელი პასუხის კოდი: {$response_code}", 'error');
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('JSON პარსინგის შეცდომა: ' . json_last_error_msg(), 'error');
            return false;
        }
        
        if (empty($data)) {
            $this->log('პროდუქტები ვერ მოიძებნა', 'error');
            return false;
        }
        
        $this->log('წარმატებით ჩაიტვირთა ' . count($data) . ' პროდუქტი',
        'success');
return $data;
}

return false;
}

public function runSync() {
@ini_set('max_execution_time', 0);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$this->log('დაიწყო სინქრონიზაცია', 'info');

$products = $this->getGitecProducts();
if (!$products) {
    update_option('gitec_sync_status', [
        'is_running' => false,
        'progress' => 0,
        'message' => 'შეცდომა პროდუქტების მიღებისას',
        'processed' => 0,
        'total' => 0  
    ]);
    return false;
}

$total = count($products);
$batch_size = get_option('gitec_sync_batch_size', 50);
$batches = array_chunk($products, $batch_size);

$processed = 0;

foreach ($batches as $batch_index => $batch) {
    $this->log("მუშავდება batch " . ($batch_index + 1) . " / " . count($batches), 'info');
    
    foreach ($batch as $product_data) {
        try {
            if ($this->syncProduct($product_data, 'manual')) {
                $processed++;
            }
        } catch (Exception $e) {
            $this->log('შეცდომა: ' . $e->getMessage(), 'error');  
        }

        $progress = round(($processed / $total) * 100);
        update_option('gitec_sync_status', [
            'is_running' => true,
            'progress' => $progress,
            'message' => 'სინქრონიზაცია მიმდინარეობს...',
            'processed' => $processed,
            'total' => $total
        ]);

        usleep(100000); 
    }
    
    sleep(2);
    wp_cache_flush();
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

update_option('gitec_sync_status', [
    'is_running' => false,
    'progress' => 100,
    'message' => 'სინქრონიზაცია დასრულდა',
    'processed' => $processed,
    'total' => $total
]);

$this->log("სინქრონიზაცია დასრულდა: $processed პროდუქტი დასინქრონდა", 'success');
return true;
}

public function syncProduct($product_data, $sync_type = 'manual') {
$product_id = wc_get_product_id_by_sku($product_data['Sku']);

try {
    if ($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            $this->log("პროდუქტი ვერ მოიძებნა ID-ით: {$product_id}", 'error');
            return false;
        }

        // არსებული პროდუქტის განახლება
        $old_price = $product->get_regular_price();
        $new_price = strval($product_data['ProductPrice']['PriceValue']);
        if ($old_price != $new_price) {
            $this->log_product_change(
                $product_data['Sku'], 
                'price_update',
                $old_price,
                $new_price,
                $sync_type
            );
            $product->set_regular_price($new_price);
        }

        if (isset($product_data['CustomProperties']['Avaliable Quantity'])) {
            $old_stock = $product->get_stock_quantity();
            $new_stock = intval($product_data['CustomProperties']['Avaliable Quantity']);
            if ($old_stock != $new_stock) {
                $this->log_product_change(
                    $product_data['Sku'],
                    'stock_update', 
                    $old_stock,
                    $new_stock,
                    $sync_type  
                );
                $product->set_manage_stock(true);
                $product->set_stock_quantity($new_stock);
                $product->set_stock_status($new_stock > 0 ? 'instock' : 'outofstock');
            }
        }

        $product->set_name($product_data['Name']);
        $product->set_description($product_data['FullDescription']);
        $product->set_short_description($product_data['ShortDescription']);
        
        if (!empty($product_data['DefaultPictureModel']['FullSizeImageUrl'])) {
            $this->handle_product_image($product, $product_data['DefaultPictureModel']['FullSizeImageUrl']);
        }
        
        $product->save();
        $this->log("პროდუქტი განახლდა: {$product_data['Sku']}", 'success');
    } else {
        // ახალი პროდუქტის შექმნა  
        $this->log_product_change(
            $product_data['Sku'],
            'new_product',
            null,
            $product_data['Name'],
            $sync_type
        );
        
        $product = new WC_Product_Simple();
        $product->set_name($product_data['Name']);
        $product->set_description($product_data['FullDescription']);
        $product->set_short_description($product_data['ShortDescription']);
        $product->set_regular_price(strval($product_data['ProductPrice']['PriceValue']));
        $product->set_sku($product_data['Sku']);
        
        if (!empty($product_data['DefaultPictureModel']['FullSizeImageUrl'])) {
            $this->handle_product_image($product, $product_data['DefaultPictureModel']['FullSizeImageUrl']);
        }
        
        if (isset($product_data['CustomProperties']['Avaliable Quantity'])) {
            $stock_quantity = intval($product_data['CustomProperties']['Avaliable Quantity']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_quantity);
            $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');
        }
        
        $product->save();
        $this->log("ახალი პროდუქტი შეიქმნა: {$product_data['Sku']}", 'success');
    }

    return true;
} catch (Exception $e) {
    $this->log("შეცდომა პროდუქტის დამუშავებისას ({$product_data['Sku']}): " . $e->getMessage(), 'error');
    return false;
}
}

private function handle_product_image($product, $image_url) {
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

$tmp = download_url($image_url);

if (is_wp_error($tmp)) {
    $this->log("სურათის ჩამოტვირთვის შეცდომა: " . $tmp->get_error_message(), 'error');
    return false;
}

$file_array = array(
    'name' => basename($image_url),
    'tmp_name' => $tmp  
);

$image_id = media_handle_sideload($file_array, 0);

if (is_wp_error($image_id)) {
    unlink($tmp);
    $this->log("სურათის დამატების შეცდომა: " . $image_id->get_error_message(), 'error'); 
    return false;
}

$product->set_image_id($image_id);

return true;
}
}

$GLOBALS['gitec_sync'] = GitecWooSync::getInstance();
