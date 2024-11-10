<?php
/**
 * Plugin Name: Woocommerce Auto-Featured Products
 * Description: This plugin automatically selects 10 products as featured daily and unfeatures the 10 products from the previous day. It displays a list of the most recently featured products.
 * Version: 1.0
 * Author: Jouke Siekman
 * Author URI: https://siekman.io
 * Email: info@siekman.io
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Auto_Featured_Products {
    private $products_to_feature = 10;
    private $log_option_name = 'auto_featured_products_log';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activation']);
        register_deactivation_hook(__FILE__, [$this, 'deactivation']);
        add_action('auto_featured_products_daily_event', [$this, 'run_daily_featured_products']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('wp_ajax_manual_run_auto_featured', [$this, 'manual_run_auto_featured']);
    }

    public function activation() {
        if (!wp_next_scheduled('auto_featured_products_daily_event')) {
            wp_schedule_event(time(), 'daily', 'auto_featured_products_daily_event');
        }
    }

    public function deactivation() {
        wp_clear_scheduled_hook('auto_featured_products_daily_event');
    }

    public function run_daily_featured_products() {
        $this->log_message('Starting daily featured products run');
        $this->unfeature_old_products();
        $featured_products = $this->feature_new_products();
        $this->log_featured_products($featured_products);
        $this->log_message('Finished daily featured products run');
    }

    private function unfeature_old_products() {
        $this->log_message('Unfeaturing old products...');
        
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $this->products_to_feature,
            'tax_query'      => [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'featured',
                ],
            ],
            'orderby'        => 'date',
            'order'          => 'ASC',
        ];

        $old_featured_products = get_posts($args);
        foreach ($old_featured_products as $product) {
            $wc_product = wc_get_product($product->ID);
            if ($wc_product) {
                $wc_product->set_featured(false);
                $wc_product->save();
                $this->log_message('Unfeatured product ID: ' . $product->ID);
            }
        }
    }

    private function feature_new_products() {
        $this->log_message('Featuring new products...');
        
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $this->products_to_feature,
            'tax_query'      => [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'featured',
                    'operator' => 'NOT IN',
                ],
            ],
            'orderby'        => 'rand',
        ];

        $new_products = get_posts($args);
        $featured_product_titles = [];
        foreach ($new_products as $product) {
            $wc_product = wc_get_product($product->ID);
            if ($wc_product) {
                $wc_product->set_featured(true);
                $wc_product->save();
                $featured_product_titles[] = $wc_product->get_name();
                $this->log_message('Featured product ID: ' . $product->ID);
            }
        }
        return $featured_product_titles;
    }

    private function log_featured_products($products) {
        update_option($this->log_option_name, $products);
    }

    public function admin_menu() {
        add_menu_page(
            'Auto-Featured Products',
            'Auto-Featured',
            'manage_options',
            'auto-featured-products',
            [$this, 'settings_page'],
            'dashicons-star-filled',
            58
        );
    }

    public function settings_page() {
        $featured_products = get_option($this->log_option_name, []);
        ?>
        <div class="wrap">
            <h1>Woocommerce Auto-Featured Products</h1>
            <p>Click the button below to run the plugin manually.</p>
            <button id="run-featured" class="button button-primary">Run manually</button>
            <div id="run-message" style="margin-top: 10px;"></div>
            <h2>Most Recently Featured Products:</h2>
            <ul>
                <?php if (!empty($featured_products)) : ?>
                    <?php foreach ($featured_products as $product_title) : ?>
                        <li><?php echo esc_html($product_title); ?></li>
                    <?php endforeach; ?>
                <?php else : ?>
                    <li>No products have been marked as featured yet.</li>
                <?php endif; ?>
            </ul>
        </div>

        <script type="text/javascript">
            document.getElementById("run-featured").addEventListener("click", function() {
                document.getElementById("run-message").textContent = "Bezig...";
                fetch(ajaxurl + '?action=manual_run_auto_featured', {
                    method: "POST",
                    credentials: "same-origin",
                }).then(response => response.json())
                  .then(data => {
                      document.getElementById("run-message").textContent = data.message;
                      location.reload(); // Reload page to update featured list
                  });
            });
        </script>
        <?php
    }

    public function manual_run_auto_featured() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Geen toestemming.']);
        }

        $this->run_daily_featured_products();
        wp_send_json_success(['message' => 'Handmatige run voltooid.']);
    }

    private function log_message($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Auto-Featured Products] ' . $message);
        }
    }
}

new Auto_Featured_Products();
