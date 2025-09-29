<?php
/**
 * Plugin Name: HTML Sitemap Builder
 * Plugin URI: https://pandyadhruv.com/
 * Description: Build customizable HTML sitemaps for your WordPress site. Include or exclude specific post types, skip noindex posts from SEO plugins, and control the display order with options like Alphabetical** or Newest to Oldest. The sitemap automatically updates by clearing the cache whenever changes are made.
 * Version: 1.0.0
 * Author: Dhruv Pandya
 * Author URI: https://pandyadhruv.com/
 * License: GPL2
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Text Domain: html-sitemap-builder
 */
if (!defined('ABSPATH')) {
    exit;
}
class HTML_Sitemap_Builder {
    const OPTION_KEY = 'hsb_settings';
    const TRANSIENT_BASE = 'hsb_sitemap_html';
    const NONCE_ACTION = 'hsb_admin_nonce';
    const MAX_MEMORY_PERCENT = 80; // Maximum memory usage percentage
    
    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Cache clearing hooks with rate limiting
        add_action('save_post', [$this, 'clear_all_transients_on_event'], 10, 1);
        add_action('deleted_post', [$this, 'clear_all_transients_on_event'], 10, 1);
        add_action('edit_post', [$this, 'clear_all_transients_on_event'], 10, 1);
        add_action('updated_postmeta', [$this, 'on_updated_postmeta'], 10, 4);
        
        add_shortcode('html_sitemap', [$this, 'render_shortcode']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
    }
    public function admin_assets($hook) {
        if ('toplevel_page_hsb-sitemap' !== $hook) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'hsb_admin', [
            'nonce' => wp_create_nonce(self::NONCE_ACTION)
        ]);
        
        $inline = "
        jQuery(document).ready(function($){
            $('.hsb-copy-btn').on('click',function(e){
                e.preventDefault();
                var t=$(this).siblings('input.hsb-shortcode');
                if(!t || !t.get(0)) return;
                t.get(0).select();
                t.get(0).setSelectionRange(0, 99999);
                try{ 
                    document.execCommand('copy'); 
                    var btn = $(this);
                    btn.text('" . esc_js(__('Copied!', 'html-sitemap-builder')) . "'); 
                    setTimeout(function(){
                        btn.text('" . esc_js(__('Copy shortcode', 'html-sitemap-builder')) . "');
                    }, 1500);
                }catch(e){ 
                    console.error('" . esc_js(__('Copy failed — select and press Ctrl/Cmd+C', 'html-sitemap-builder')) . "'); 
                }
            });
        });";
        wp_add_inline_script('jquery', $inline);
    }
    public function admin_menu() {
        add_menu_page(
            __('HTML Sitemap', 'html-sitemap-builder'),
            __('HTML Sitemap', 'html-sitemap-builder'),
            'manage_options',
            'hsb-sitemap',
            [$this, 'settings_page'],
            'dashicons-list-view',
            60
        );
    }
    public function register_settings() {
        register_setting('hsb_settings_group', self::OPTION_KEY, [$this, 'sanitize_settings']);
    }
    public function sanitize_settings($input) {
        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'hsb_settings_group-options')) {
            wp_die(esc_html__('Security verification failed.', 'html-sitemap-builder'));
        }
        
        $clean = [];
        
        // Validate post types against available ones
        $available_post_types = get_post_types(['public' => true], 'names');
        if (!empty($input['include_post_types']) && is_array($input['include_post_types'])) {
            $clean['include_post_types'] = [];
            foreach ($input['include_post_types'] as $pt) {
                $sanitized_pt = sanitize_key($pt);
                if (in_array($sanitized_pt, $available_post_types, true)) {
                    $clean['include_post_types'][] = $sanitized_pt;
                }
            }
        } else {
            $clean['include_post_types'] = [];
        }
        
        // Sanitize exclude posts with limits
        $clean['exclude_posts'] = isset($input['exclude_posts']) 
            ? $this->sanitize_id_list($input['exclude_posts']) : [];
        
        // Boolean validation - hierarchical now defaults to true
        $clean['exclude_noindex'] = !empty($input['exclude_noindex']) ? 1 : 0;
        $clean['hierarchical_display'] = isset($input['hierarchical_display']) ? (!empty($input['hierarchical_display']) ? 1 : 0) : 1; // Default to true
        
        // Validate cache minutes range
        $cache_minutes = isset($input['cache_minutes']) ? intval($input['cache_minutes']) : 60;
        $clean['cache_minutes'] = max(0, min(10080, $cache_minutes)); // 0 to 1 week
        
        // Validate max depth - default to 20 now
        $max_depth = isset($input['max_depth']) ? intval($input['max_depth']) : 20;
        $clean['max_depth'] = max(1, min(50, $max_depth)); // 1 to 50 levels (increased from 10)
        
        // NEW: Validate sorting order - default to alphabetical
        $valid_orders = ['alphabetical', 'recent'];
        $sort_order = isset($input['sort_order']) ? sanitize_key($input['sort_order']) : 'alphabetical';
        $clean['sort_order'] = in_array($sort_order, $valid_orders, true) ? $sort_order : 'alphabetical';
        
        $this->clear_all_transients();
        return $clean;
    }
    private function sanitize_id_list($text) {
        if (is_array($text)) {
            $arr = $text;
        } else {
            $text = substr(trim((string)$text), 0, 10000); // Limit input length
            $arr = preg_split('/[,\s]+/', $text);
        }
        
        $out = [];
        $count = 0;
        foreach ($arr as $v) {
            if ($count >= 1000) break; // Prevent DoS
            $v = trim($v);
            if ($v !== '' && is_numeric($v)) {
                $id = absint($v);
                if ($id > 0) {
                    $out[] = $id;
                    $count++;
                }
            }
        }
        return array_values(array_unique($out));
    }
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'html-sitemap-builder'));
        }
        
        $all_post_types = get_post_types(['public' => true], 'objects');
        unset($all_post_types['attachment']); // Remove attachment post type
        unset($all_post_types['elementor_library']); // Remove elementor ibrary post type
        $settings = get_option(self::OPTION_KEY, []);
        
        if (!is_array($settings)) {
            $settings = [];
        }
        
        // Set defaults if not set
        if (!isset($settings['hierarchical_display'])) {
            $settings['hierarchical_display'] = 1; // Default to true
        }
        if (!isset($settings['max_depth'])) {
            $settings['max_depth'] = 20; // Default to 20
        }
        if (!isset($settings['sort_order'])) {
            $settings['sort_order'] = 'alphabetical'; // Default to alphabetical
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('hsb_settings_group'); ?>
                
                <h2><?php esc_html_e('Available Post Types', 'html-sitemap-builder'); ?></h2>
                <p><?php esc_html_e('Select which public post types should appear in the sitemap.', 'html-sitemap-builder'); ?></p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Post Types', 'html-sitemap-builder'); ?></th>
                        <td>
                            <?php foreach ($all_post_types as $pt_slug => $pt_obj): 
                                $checked = (!empty($settings['include_post_types']) && 
                                             in_array($pt_slug, $settings['include_post_types'], true)) ? 'checked' : '';
                            ?>
                                <label style="display:block; margin-bottom:6px;">
                                    <input type="checkbox" 
                                           name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_post_types][]" 
                                           value="<?php echo esc_attr($pt_slug); ?>" 
                                           <?php echo esc_attr($checked); ?>>
                                    <?php echo esc_html($pt_obj->labels->name); ?> 
                                    (<?php echo esc_html($pt_slug); ?>)
                                    <?php if ($pt_obj->hierarchical): ?>
                                        <em style="color:#666;"> - <?php esc_html_e('Hierarchical', 'html-sitemap-builder'); ?></em>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e('If none selected, all public post types will be included.', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Hierarchical Display', 'html-sitemap-builder'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr(self::OPTION_KEY); ?>[hierarchical_display]" 
                                       value="1" 
                                       <?php checked(!empty($settings['hierarchical_display'])); ?>>
                                <?php esc_html_e('Display hierarchical post types (like pages) with parent-child structure.', 'html-sitemap-builder'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When enabled, child pages will appear nested under their parent pages. (Default: Enabled)', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum Depth', 'html-sitemap-builder'); ?></th>
                        <td>
                            <input type="number" 
                                   name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_depth]" 
                                   value="<?php echo isset($settings['max_depth']) ? intval($settings['max_depth']) : 20; ?>" 
                                   min="1" max="50" style="width:120px;"> 
                            <?php esc_html_e('levels', 'html-sitemap-builder'); ?>
                            <p class="description">
                                <?php esc_html_e('Maximum nesting depth for hierarchical display (1-50). Only applies when hierarchical display is enabled. (Default: 20)', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Sort Order', 'html-sitemap-builder'); ?></th>
                        <td>
                            <label style="display:block; margin-bottom:8px;">
                                <input type="radio" 
                                       name="<?php echo esc_attr(self::OPTION_KEY); ?>[sort_order]" 
                                       value="alphabetical" 
                                       <?php checked($settings['sort_order'], 'alphabetical'); ?>>
                                <?php esc_html_e('Alphabetical Order', 'html-sitemap-builder'); ?>
                            </label>
                            <label style="display:block; margin-bottom:8px;">
                                <input type="radio" 
                                       name="<?php echo esc_attr(self::OPTION_KEY); ?>[sort_order]" 
                                       value="recent" 
                                       <?php checked($settings['sort_order'], 'recent'); ?>>
                                <?php esc_html_e('Recent Posts First (Newest to Oldest)', 'html-sitemap-builder'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Choose how to order posts/pages in the sitemap. For hierarchical display, this affects the ordering within each level. (Default: Alphabetical)', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Exclude Posts/Pages (IDs)', 'html-sitemap-builder'); ?></th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[exclude_posts]" 
                                       rows="3" cols="50" class="large-text" maxlength="10000"><?php 
                                echo esc_textarea(!empty($settings['exclude_posts']) ? 
                                    implode(',', $settings['exclude_posts']) : ''); 
                            ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Comma or space separated post IDs. Maximum 1000 IDs.', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Exclude Noindex Posts/Pages', 'html-sitemap-builder'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo esc_attr(self::OPTION_KEY); ?>[exclude_noindex]" 
                                       value="1" 
                                       <?php checked(!empty($settings['exclude_noindex'])); ?>>
                                <?php esc_html_e('Exclude posts/pages marked as noindex by SEO plugins.', 'html-sitemap-builder'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Supports Yoast, Rank Math, and SEOPress.', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php esc_html_e('Cache Duration', 'html-sitemap-builder'); ?></th>
                        <td>
                            <input type="number" 
                                   name="<?php echo esc_attr(self::OPTION_KEY); ?>[cache_minutes]" 
                                   value="<?php echo isset($settings['cache_minutes']) ? intval($settings['cache_minutes']) : 60; ?>" 
                                   min="0" max="10080" style="width:120px;"> 
                            <?php esc_html_e('minutes', 'html-sitemap-builder'); ?>
                            <p class="description">
                                <?php esc_html_e('0 disables caching. Maximum 1 week (10080 minutes).', 'html-sitemap-builder'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <h2><?php esc_html_e('Shortcode', 'html-sitemap-builder'); ?></h2>
            <p><?php esc_html_e('Use this shortcode to display the sitemap:', 'html-sitemap-builder'); ?></p>
            <div style="margin:10px 0;">
                <input class="hsb-shortcode" readonly="readonly" value="[html_sitemap]" style="width:360px; padding:6px;">
                <button type="button" class="hsb-copy-btn button">
                    <?php esc_html_e('Copy shortcode', 'html-sitemap-builder'); ?>
                </button>
            </div>
            
            <h3><?php esc_html_e('Shortcode Attributes', 'html-sitemap-builder'); ?></h3>
            <ul>
                <li><code>exclude_posts="12,45"</code> — <?php esc_html_e('exclude specific post IDs', 'html-sitemap-builder'); ?></li>
                <li><code>cache_minutes="0"</code> — <?php esc_html_e('override cache duration', 'html-sitemap-builder'); ?></li>
                <li><code>hierarchical="true"</code> — <?php esc_html_e('enable/disable hierarchical display (overrides settings)', 'html-sitemap-builder'); ?></li>
                <li><code>max_depth="20"</code> — <?php esc_html_e('override maximum nesting depth (default: 20)', 'html-sitemap-builder'); ?></li>
                <li><code>sort_order="recent"</code> — <?php esc_html_e('override sort order (alphabetical or recent)', 'html-sitemap-builder'); ?></li>
            </ul>
            
            <h3><?php esc_html_e('Examples', 'html-sitemap-builder'); ?></h3>
            <ul>
                <li><code>[html_sitemap]</code> — <?php esc_html_e('Basic sitemap using plugin settings (hierarchical enabled, 20 levels deep, alphabetical order)', 'html-sitemap-builder'); ?></li>
                <li><code>[html_sitemap hierarchical="true" max_depth="5"]</code> — <?php esc_html_e('Hierarchical sitemap with 5 levels deep', 'html-sitemap-builder'); ?></li>
                <li><code>[html_sitemap hierarchical="false"]</code> — <?php esc_html_e('Flat sitemap (no hierarchy)', 'html-sitemap-builder'); ?></li>
                <li><code>[html_sitemap sort_order="recent"]</code> — <?php esc_html_e('Sitemap ordered by newest posts first', 'html-sitemap-builder'); ?></li>
                <li><code>[html_sitemap sort_order="alphabetical"]</code> — <?php esc_html_e('Sitemap ordered alphabetically by title', 'html-sitemap-builder'); ?></li>
                <li><code>[html_sitemap max_depth="50"]</code> — <?php esc_html_e('Deep hierarchical sitemap with maximum 50 levels', 'html-sitemap-builder'); ?></li>
            </ul>
        </div>
        <?php
    }
    public function render_shortcode($atts) {
        // Memory monitoring for DoS prevention
        $memory_limit = $this->get_memory_limit() * (self::MAX_MEMORY_PERCENT / 100);
        $initial_memory = memory_get_usage(true);
        
        if ($initial_memory > $memory_limit) {
            error_log('HTML Sitemap Builder: Memory usage too high, aborting render');
            return '<p>' . esc_html__('Sitemap temporarily unavailable due to high server load.', 'html-sitemap-builder') . '</p>';
        }
        $atts = shortcode_atts([
            'exclude_posts' => '',
            'cache_minutes' => '',
            'hierarchical' => '', // Can override settings
            'max_depth' => '',
            'sort_order' => '', // NEW: Can override settings
        ], $atts, 'html_sitemap');
        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        // Set defaults for new installations
        if (!isset($settings['hierarchical_display'])) {
            $settings['hierarchical_display'] = 1; // Default to true
        }
        if (!isset($settings['max_depth'])) {
            $settings['max_depth'] = 20; // Default to 20
        }
        if (!isset($settings['sort_order'])) {
            $settings['sort_order'] = 'alphabetical'; // Default to alphabetical
        }
        // Get and validate post types
        $all_public = get_post_types(['public' => true], 'names');
        $include_pt = !empty($settings['include_post_types']) ? $settings['include_post_types'] : $all_public;
        $include_pt = array_intersect($include_pt, $all_public);
        // Process exclude posts
        $exclude_posts = !empty($settings['exclude_posts']) ? $settings['exclude_posts'] : [];
        if (!empty($atts['exclude_posts'])) {
            $exclude_posts = array_merge($exclude_posts, $this->sanitize_id_list($atts['exclude_posts']));
        }
        $exclude_posts = array_unique(array_filter(array_map('absint', $exclude_posts)));
        // Determine hierarchical setting - defaults to true now
        $hierarchical = !empty($settings['hierarchical_display']);
        if ($atts['hierarchical'] !== '') {
            $hierarchical = ($atts['hierarchical'] === 'true' || $atts['hierarchical'] === '1');
        }
        // Determine max depth - default to 20 now
        $max_depth = isset($settings['max_depth']) ? intval($settings['max_depth']) : 20;
        if ($atts['max_depth'] !== '') {
            $max_depth = max(1, min(50, intval($atts['max_depth'])));
        }
        
        // NEW: Determine sort order - default to alphabetical
        $sort_order = isset($settings['sort_order']) ? $settings['sort_order'] : 'alphabetical';
        if ($atts['sort_order'] !== '') {
            $valid_orders = ['alphabetical', 'recent'];
            $sort_order = in_array($atts['sort_order'], $valid_orders, true) ? $atts['sort_order'] : 'alphabetical';
        }
        // Validate cache minutes
        $cache_minutes = isset($settings['cache_minutes']) ? intval($settings['cache_minutes']) : 60;
        if ($atts['cache_minutes'] !== '') {
            $cache_minutes = max(0, min(10080, intval($atts['cache_minutes'])));
        }
        // Create cache key
        $cache_key_string = implode('|', [
            'pt:' . implode(',', array_slice($include_pt, 0, 20)),
            'ex:' . implode(',', array_slice($exclude_posts, 0, 100)),
            'ni:' . (!empty($settings['exclude_noindex']) ? '1' : '0'),
            'hier:' . ($hierarchical ? '1' : '0'),
            'depth:' . $max_depth,
            'sort:' . $sort_order, // NEW: Include sort order in cache key
            'v:1.0.5'
        ]);
        
        $cache_key = self::TRANSIENT_BASE . '_' . wp_hash($cache_key_string);
        // Check cache
        if ($cache_minutes > 0) {
            $cached = get_transient($cache_key);
            if (false !== $cached && is_string($cached)) {
                return $cached;
            }
        }
        // Build sitemap
        $output = '<div class="hsb-sitemap">';
        $processed_types = 0;
        
        foreach ($include_pt as $pt) {
            if (memory_get_usage(true) > $memory_limit) {
                error_log('HTML Sitemap Builder: Memory limit approached, stopping at post type: ' . $pt);
                break;
            }
            
            if (!post_type_exists($pt) || $processed_types >= 20) {
                continue;
            }
            $pt_obj = get_post_type_object($pt);
            
            // Check if we should use hierarchical display for this post type
            if ($hierarchical && $pt_obj && $pt_obj->hierarchical) {
                $content = $this->build_hierarchical_content($pt, $exclude_posts, $settings, $max_depth, $sort_order);
            } else {
                $content = $this->build_flat_content($pt, $exclude_posts, $settings, $sort_order);
            }
            
            if (!empty($content)) {
                $label = $pt_obj && isset($pt_obj->labels->name) ? $pt_obj->labels->name : $pt;
                $safe_pt = esc_attr($pt);
                $safe_label = esc_html(wp_trim_words($label, 10));
                $output .= "<section class=\"hsb-pt-group hsb-pt-{$safe_pt}\">";
                $output .= "<h3>{$safe_label}</h3>{$content}</section>";
            }
            
            $processed_types++;
        }
        
        $output .= '</div>';
        
        // Add enhanced CSS
        $output .= '<style type="text/css">';
        $output .= '.hsb-sitemap h3{margin-top:16px;font-size:1.1em}';
        $output .= '.hsb-sitemap ul{list-style:disc;margin-left:20px}';
        $output .= '.hsb-sitemap li{margin:4px 0}';
        $output .= '.hsb-sitemap .hsb-children{margin-left:15px;margin-top:8px}';
        $output .= '.hsb-sitemap .hsb-children ul{list-style:circle;margin-left:15px}';
        $output .= '.hsb-sitemap .hsb-depth-2 ul{list-style:square}';
        $output .= '.hsb-sitemap .hsb-depth-3 ul{list-style:disc}';
        $output .= '.hsb-sitemap .hsb-depth-4 ul{list-style:circle}';
        $output .= '.hsb-sitemap .hsb-depth-5 ul{list-style:square}';
        $output .= '</style>';
        // Cache the result
        if ($cache_minutes > 0) {
            set_transient($cache_key, $output, $cache_minutes * MINUTE_IN_SECONDS);
        }
        return $output;
    }
    private function build_hierarchical_content($post_type, $exclude_posts, $settings, $max_depth = 20, $sort_order = 'alphabetical') {
        // Get parent posts first (posts with no parent)
        $parent_args = [
            'post_type' => $post_type,
            'posts_per_page' => 200,
            'post_status' => 'publish',
            'post_parent' => 0, // Only top-level parents
            'orderby' => $this->get_orderby_for_query($sort_order),
            'order' => $this->get_order_for_query($sort_order),
            'post__not_in' => $exclude_posts,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        
        $parent_query = new WP_Query($parent_args);
        
        if (!$parent_query->have_posts()) {
            return '';
        }
        
        $output = '<ul>';
        
        while ($parent_query->have_posts()) {
            $parent_query->the_post();
            $post_id = get_the_ID();
            
            if (!$post_id || in_array($post_id, $exclude_posts)) {
                continue;
            }
            
            // Check noindex if enabled
            if (!empty($settings['exclude_noindex']) && $this->is_noindex($post_id)) {
                continue;
            }
            
            $title = get_the_title($post_id);
            $permalink = get_permalink($post_id);
            
            if (empty($title) || empty($permalink)) {
                continue;
            }
            
            $safe_title = esc_html(wp_trim_words($title, 20));
            $safe_url = esc_url($permalink);
            
            $output .= "<li><a href=\"{$safe_url}\">{$safe_title}</a>";
            
            // Get child posts if we haven't reached max depth
            if ($max_depth > 1) {
                $children = $this->get_child_posts($post_id, $post_type, $exclude_posts, $settings, 1, $max_depth, $sort_order);
                if (!empty($children)) {
                    $output .= "<div class=\"hsb-children hsb-depth-1\">{$children}</div>";
                }
            }
            
            $output .= "</li>";
        }
        
        $output .= '</ul>';
        wp_reset_postdata();
        
        return $output;
    }
    private function get_child_posts($parent_id, $post_type, $exclude_posts, $settings, $current_depth = 1, $max_depth = 20, $sort_order = 'alphabetical') {
        if ($current_depth >= $max_depth) {
            return '';
        }
        
        $child_args = [
            'post_type' => $post_type,
            'posts_per_page' => 100,
            'post_status' => 'publish',
            'post_parent' => $parent_id,
            'orderby' => $this->get_orderby_for_query($sort_order),
            'order' => $this->get_order_for_query($sort_order),
            'post__not_in' => $exclude_posts,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        
        $child_query = new WP_Query($child_args);
        
        if (!$child_query->have_posts()) {
            return '';
        }
        
        $output = '<ul>';
        
        while ($child_query->have_posts()) {
            $child_query->the_post();
            $child_id = get_the_ID();
            
            if (!$child_id || in_array($child_id, $exclude_posts)) {
                continue;
            }
            
            // Check noindex if enabled
            if (!empty($settings['exclude_noindex']) && $this->is_noindex($child_id)) {
                continue;
            }
            
            $title = get_the_title($child_id);
            $permalink = get_permalink($child_id);
            
            if (empty($title) || empty($permalink)) {
                continue;
            }
            
            $safe_title = esc_html(wp_trim_words($title, 20));
            $safe_url = esc_url($permalink);
            
            $output .= "<li><a href=\"{$safe_url}\">{$safe_title}</a>";
            
            // Recursively get grandchildren if we haven't reached max depth
            $next_depth = $current_depth + 1;
            if ($next_depth < $max_depth) {
                $grandchildren = $this->get_child_posts($child_id, $post_type, $exclude_posts, $settings, $next_depth, $max_depth, $sort_order);
                if (!empty($grandchildren)) {
                    $output .= "<div class=\"hsb-children hsb-depth-{$next_depth}\">{$grandchildren}</div>";
                }
            }
            
            $output .= "</li>";
        }
        
        $output .= '</ul>';
        wp_reset_postdata();
        
        return $output;
    }
    private function build_flat_content($post_type, $exclude_posts, $settings, $sort_order = 'alphabetical') {
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => 500,
            'post_status' => 'publish',
            'orderby' => $this->get_orderby_for_query($sort_order),
            'order' => $this->get_order_for_query($sort_order),
            'post__not_in' => $exclude_posts,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        
        $query = new WP_Query($args);
        
        if (!$query->have_posts()) {
            return '';
        }
        
        $list_items = '';
        $item_count = 0;
        
        while ($query->have_posts() && $item_count < 500) {
            $query->the_post();
            $post_id = get_the_ID();
            
            if (!$post_id || !is_numeric($post_id)) {
                continue;
            }
            
            // Check noindex if enabled
            if (!empty($settings['exclude_noindex']) && $this->is_noindex($post_id)) {
                continue;
            }
            
            $title = get_the_title($post_id);
            $permalink = get_permalink($post_id);
            
            if (empty($title) || empty($permalink)) {
                continue;
            }
            
            $safe_title = esc_html(wp_trim_words($title, 20));
            $safe_url = esc_url($permalink);
            
            $list_items .= "<li><a href=\"{$safe_url}\">{$safe_title}</a></li>";
            $item_count++;
        }
        
        wp_reset_postdata();
        
        return $list_items ? "<ul>{$list_items}</ul>" : '';
    }
    
    /**
     * NEW: Get orderby parameter for WP_Query based on sort order setting
     */
    private function get_orderby_for_query($sort_order) {
        switch ($sort_order) {
            case 'recent':
                return 'date';
            case 'alphabetical':
            default:
                return 'title';
        }
    }
    
    /**
     * NEW: Get order parameter for WP_Query based on sort order setting
     */
    private function get_order_for_query($sort_order) {
        switch ($sort_order) {
            case 'recent':
                return 'DESC'; // Newest first
            case 'alphabetical':
            default:
                return 'ASC'; // A to Z
        }
    }
    
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === false || $memory_limit == -1) {
            return 134217728; // Default 128MB
        }
        return wp_convert_hr_to_bytes($memory_limit);
    }
    private function is_noindex($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || !get_post($post_id)) {
            return false;
        }
        $seo_keys = [
            '_yoast_wpseo_meta-robots-noindex',
            '_yoast_wpseo_noindex', 
            'rank_math_robots',
            '_rank_math_robots',
            '_seopress_robots_index',
            'seopress_robots',
        ];
        foreach ($seo_keys as $key) {
            if (!is_string($key) || strlen($key) > 100) continue;
            
            $val = get_post_meta($post_id, $key, true);
            if (empty($val)) continue;
            
            if (is_string($val) && strlen($val) > 1000) continue;
            
            if (is_array($val)) {
                if (count($val) > 50) continue;
                
                foreach ($val as $item) {
                    $item_str = is_string($item) ? strtolower(substr($item, 0, 100)) : '';
                    if (in_array($item_str, ['noindex', '1', 'yes'], true)) {
                        return true;
                    }
                }
                continue;
            }
            
            if (is_string($val) && is_serialized($val)) {
                $unserialized = maybe_unserialize($val);
                if (is_array($unserialized) && count($unserialized) <= 50) {
                    foreach ($unserialized as $item) {
                        $item_str = is_string($item) ? strtolower(substr($item, 0, 100)) : '';
                        if (in_array($item_str, ['noindex', '1', 'yes'], true)) {
                            return true;
                        }
                    }
                }
                continue;
            }
            
            $str = strtolower(substr((string)$val, 0, 100));
            if (strpos($str, 'noindex') !== false || 
                in_array($str, ['1', 'yes', 'true', 'on', 'no', 'noindex'], true)) {
                return true;
            }
        }
        return false;
    }
    private function clear_all_transients() {
        global $wpdb;
        
        try {
            $transient_like = $wpdb->esc_like('_transient_' . self::TRANSIENT_BASE) . '%';
            $timeout_like = $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_BASE) . '%';
            
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transient_like,
                $timeout_like
            ));
            
            if ($result === false) {
                error_log('HTML Sitemap Builder: Failed to clear transients');
            }
        } catch (Exception $e) {
            error_log('HTML Sitemap Builder: Transient cleanup error - ' . $e->getMessage());
        }
    }
    public function clear_all_transients_on_event($arg = null) {
        // Rate limiting to prevent abuse
        $last_clear = get_transient('hsb_last_cache_clear');
        if (false === $last_clear) {
            $this->clear_all_transients();
            set_transient('hsb_last_cache_clear', time(), MINUTE_IN_SECONDS);
        }
    }
    public function on_updated_postmeta($meta_id, $post_id, $meta_key, $_meta_value) {
        if (empty($meta_key) || !is_string($meta_key) || strlen($meta_key) > 255) {
            return;
        }
        
        $needle = strtolower($meta_key);
        $seo_terms = ['yoast', 'rank_math', 'seopress', 'noindex', 'robots'];
        
        foreach ($seo_terms as $term) {
            if (strpos($needle, $term) !== false) {
                $this->clear_all_transients_on_event();
                return;
            }
        }
    }
    public static function uninstall() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        delete_option(self::OPTION_KEY);
        
        global $wpdb;
        $transient_like = $wpdb->esc_like('_transient_' . self::TRANSIENT_BASE) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . self::TRANSIENT_BASE) . '%';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $transient_like,
            $timeout_like
        ));
    }
}
// Initialize only if WordPress is properly loaded
if (defined('ABSPATH') && function_exists('add_action')) {
    new HTML_Sitemap_Builder();
}
