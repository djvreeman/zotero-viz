<?php
/**
 * Plugin Name: Zotero Visualizations
 * Description: Display interactive world maps and bar charts from Zotero collections
 * Version: 1.0.11
 * Author: Daniel J. Vreeman, PT, DPT, MS, FACMI, FIAHSI
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZOTERO_VIZ_VERSION', '1.0.11'); // Increment this to force asset/cache refresh
define('ZOTERO_VIZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZOTERO_VIZ_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Persistent cache lives in uploads, not wp-content/cache.
 * Caching plugins routinely delete everything under wp-content/cache.
 */
function zotero_viz_legacy_cache_dir() {
    return WP_CONTENT_DIR . '/cache/zotero-viz/';
}

function zotero_viz_cache_dir() {
    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir'])) {
        return WP_CONTENT_DIR . '/zotero-viz-cache/';
    }
    return trailingslashit($upload['basedir']) . 'zotero-viz/';
}

function zotero_viz_ensure_cache_dir() {
    $dir = zotero_viz_cache_dir();
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    if (is_dir($dir) && !file_exists($dir . 'index.php')) {
        file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n");
    }
    return is_dir($dir) && is_writable($dir);
}

function zotero_viz_cache_file($display_name) {
    $safe = sanitize_file_name($display_name);
    if ($safe === '') {
        $safe = 'library';
    }
    return zotero_viz_cache_dir() . $safe . '.json';
}

function zotero_viz_find_cache_file($display_name) {
    $current = zotero_viz_cache_file($display_name);
    if (file_exists($current)) {
        return $current;
    }

    $legacy_names = array(
        zotero_viz_legacy_cache_dir() . $display_name . '.json',
        zotero_viz_legacy_cache_dir() . sanitize_file_name($display_name) . '.json',
    );
    foreach ($legacy_names as $legacy) {
        if (file_exists($legacy)) {
            return $legacy;
        }
    }

    return $current;
}

// Create cache directory on activation
register_activation_hook(__FILE__, 'zotero_viz_activate');
function zotero_viz_activate() {
    zotero_viz_ensure_cache_dir();
    
    // Schedule daily cache refresh
    if (!wp_next_scheduled('zotero_viz_daily_cache_refresh')) {
        wp_schedule_event(time(), 'daily', 'zotero_viz_daily_cache_refresh');
    }
}

// Clear scheduled events on deactivation
register_deactivation_hook(__FILE__, 'zotero_viz_deactivate');
function zotero_viz_deactivate() {
    wp_clear_scheduled_hook('zotero_viz_daily_cache_refresh');
}

function zotero_viz_default_colors() {
    return array(
        'highlight' => '#ff0000',
        'default' => '#cccccc',
        'border' => '#999999',
        'water' => '#e6f3ff'
    );
}

function zotero_viz_sanitize_colors($colors) {
    $defaults = zotero_viz_default_colors();
    if (!is_array($colors)) {
        return $defaults;
    }
    $clean = $defaults;
    foreach ($defaults as $key => $default) {
        if (empty($colors[$key]) || !is_string($colors[$key])) {
            continue;
        }
        $hex = strtoupper(trim($colors[$key]));
        if ($hex !== '' && $hex[0] !== '#') {
            $hex = '#' . $hex;
        }
        if (preg_match('/^#[0-9A-F]{6}$/', $hex)) {
            $clean[$key] = $hex;
        }
    }
    return $clean;
}

function zotero_viz_encryption_key() {
    $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . '|zotero-viz-api-key';
    return hash('sha256', $material, true);
}

function zotero_viz_encrypt_api_key($plain) {
    if (!is_string($plain) || $plain === '' || !function_exists('openssl_encrypt')) {
        return false;
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', zotero_viz_encryption_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return false;
    }
    return base64_encode($iv . $cipher);
}

function zotero_viz_decrypt_api_key($stored) {
    if (!is_string($stored) || $stored === '' || !function_exists('openssl_decrypt')) {
        return '';
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', zotero_viz_encryption_key(), OPENSSL_RAW_DATA, $iv);
    return is_string($plain) ? $plain : '';
}

function zotero_viz_sanitize_api_key($key) {
    $key = trim((string) $key);
    $key = preg_replace('/[^A-Za-z0-9]/', '', $key);
    return is_string($key) ? $key : '';
}

function zotero_viz_has_api_key() {
    $stored = get_option('zotero_viz_api_key', '');
    return is_string($stored) && $stored !== '';
}

function zotero_viz_get_api_key() {
    $stored = get_option('zotero_viz_api_key', '');
    if (!is_string($stored) || $stored === '') {
        return '';
    }
    return zotero_viz_decrypt_api_key($stored);
}

function zotero_viz_set_api_key($plain) {
    $encrypted = zotero_viz_encrypt_api_key($plain);
    if ($encrypted === false) {
        return false;
    }
    return update_option('zotero_viz_api_key', $encrypted, false);
}

function zotero_viz_clear_api_key() {
    delete_option('zotero_viz_api_key');
}

function zotero_viz_api_headers($api_key) {
    $headers = array(
        'Zotero-API-Version' => '3'
    );
    if (is_string($api_key) && $api_key !== '') {
        $headers['Zotero-API-Key'] = $api_key;
    }
    return $headers;
}

function zotero_viz_redact_api_error_body($body, $api_key) {
    $text = substr(wp_strip_all_tags((string) $body), 0, 200);
    if (is_string($api_key) && $api_key !== '') {
        $text = str_replace($api_key, '[redacted]', $text);
    }
    return $text;
}

function zotero_viz_validate_api_key($api_key) {
    $response = wp_remote_get('https://api.zotero.org/keys/current', array(
        'timeout' => 30,
        'headers' => zotero_viz_api_headers($api_key)
    ));

    if (is_wp_error($response)) {
        return new WP_Error(
            'zotero_viz_api_key',
            'Could not reach Zotero to validate the API key: ' . $response->get_error_message()
        );
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 403 || $code === 401) {
        return new WP_Error(
            'zotero_viz_api_key',
            'That Zotero API key is invalid or does not have access. Settings were not saved.'
        );
    }
    if ($code < 200 || $code >= 300) {
        return new WP_Error(
            'zotero_viz_api_key',
            'Zotero rejected the API key (HTTP ' . intval($code) . '). Settings were not saved.'
        );
    }

    return true;
}

// Parse Zotero URL to extract components
function zotero_viz_parse_url($url) {
    $url = trim(wp_unslash((string) $url));
    if ($url === '') {
        return false;
    }

    if (stripos($url, 'zotero.org') !== false && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }

    $path = trim($path, '/');
    if (!preg_match('#(?:^|/)groups/(\d+)/([^/]+)(?:/(.*))?$#i', $path, $matches)) {
        return false;
    }

    $group_id = $matches[1];
    $library_name = rawurldecode($matches[2]);
    $rest = isset($matches[3]) ? $matches[3] : '';
    $collection_key = '';
    if (preg_match('#collections/([A-Za-z0-9]+)#i', $rest, $collection_match)) {
        $collection_key = $collection_match[1];
    }

    $canonical = 'https://www.zotero.org/groups/' . $group_id . '/' . rawurlencode($library_name);
    if ($collection_key !== '') {
        $canonical .= '/collections/' . $collection_key;
    } else {
        $canonical .= '/library';
    }

    return array(
        'group_id' => $group_id,
        'library_name' => $library_name,
        'collection_key' => $collection_key,
        'url' => $canonical
    );
}

function zotero_viz_normalize_collection($collection) {
    if (!is_array($collection)) {
        return false;
    }

    $url = isset($collection['url']) ? trim(wp_unslash($collection['url'])) : '';
    $display_name = isset($collection['display_name']) ? sanitize_text_field(wp_unslash($collection['display_name'])) : '';
    $library_name = isset($collection['library_name']) ? sanitize_text_field(wp_unslash($collection['library_name'])) : '';
    $group_id = isset($collection['group_id']) ? sanitize_text_field(wp_unslash($collection['group_id'])) : '';
    $collection_key = isset($collection['collection_key']) ? sanitize_text_field(wp_unslash($collection['collection_key'])) : '';

    if ($url !== '') {
        $parsed = zotero_viz_parse_url($url);
        if ($parsed) {
            if ($group_id === '') {
                $group_id = $parsed['group_id'];
            }
            if ($library_name === '') {
                $library_name = $parsed['library_name'];
            }
            if ($collection_key === '') {
                $collection_key = $parsed['collection_key'];
            }
            $url = $parsed['url'];
        }
    }

    if ($group_id === '' || $library_name === '') {
        return false;
    }

    if ($display_name === '') {
        $display_name = $library_name;
        $display_name .= ($collection_key !== '') ? '_collection' : '_full';
    }

    if ($url === '') {
        $url = 'https://www.zotero.org/groups/' . rawurlencode($group_id) . '/' . rawurlencode($library_name);
        $url .= ($collection_key !== '') ? '/collections/' . rawurlencode($collection_key) : '/library';
    }

    return array(
        'url' => $url,
        'display_name' => $display_name,
        'library_name' => $library_name,
        'group_id' => $group_id,
        'collection_key' => $collection_key
    );
}

function zotero_viz_collection_row_has_input($collection) {
    if (!is_array($collection)) {
        return false;
    }
    foreach (array('url', 'display_name', 'library_name', 'group_id', 'collection_key') as $field) {
        if (!empty($collection[$field])) {
            return true;
        }
    }
    return false;
}

// AJAX handler for URL parsing
add_action('wp_ajax_zotero_viz_parse_url', 'zotero_viz_ajax_parse_url');
function zotero_viz_ajax_parse_url() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden');
    }
    check_ajax_referer('zotero_viz_parse', 'nonce');
    
    $url = sanitize_text_field($_POST['url']);
    $parsed = zotero_viz_parse_url($url);
    
    if ($parsed) {
        wp_send_json_success($parsed);
    } else {
        wp_send_json_error('Invalid Zotero URL format');
    }
}

// Add admin menu
add_action('admin_menu', 'zotero_viz_admin_menu');
function zotero_viz_admin_menu() {
    add_menu_page(
        'Zotero Visualizations',
        'Zotero Viz',
        'manage_options',
        'zotero-viz',
        'zotero_viz_admin_page',
        'dashicons-chart-area',
        100
    );
}

// Admin page
function zotero_viz_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'zotero-viz'));
    }

    // Handle form submissions
    if (isset($_POST['zotero_viz_save_settings'])) {
        check_admin_referer('zotero_viz_admin');

        $clear_key = !empty($_POST['zotero_viz_clear_api_key']);
        $posted_key = isset($_POST['zotero_viz_api_key'])
            ? zotero_viz_sanitize_api_key(wp_unslash($_POST['zotero_viz_api_key']))
            : '';
        $save_rejected = false;

        if ($clear_key) {
            zotero_viz_clear_api_key();
        } elseif ($posted_key !== '') {
            $validation = zotero_viz_validate_api_key($posted_key);
            if (is_wp_error($validation)) {
                $save_rejected = true;
                echo '<div class="notice notice-error"><p>' . esc_html($validation->get_error_message()) . '</p></div>';
            } elseif (!zotero_viz_set_api_key($posted_key)) {
                $save_rejected = true;
                echo '<div class="notice notice-error"><p>Could not store the Zotero API key. Settings were not saved.</p></div>';
            }
        }

        if (!$save_rejected) {
            $collections = array();
            $skipped = 0;
            if (isset($_POST['collections']) && is_array($_POST['collections'])) {
                foreach ($_POST['collections'] as $collection) {
                    $normalized = zotero_viz_normalize_collection($collection);
                    if ($normalized) {
                        $collections[] = $normalized;
                    } elseif (zotero_viz_collection_row_has_input($collection)) {
                        $skipped++;
                    }
                }
            }
            update_option('zotero_viz_collections', $collections);
            $colors_in = isset($_POST['colors']) ? wp_unslash($_POST['colors']) : array();
            update_option('zotero_viz_colors', zotero_viz_sanitize_colors($colors_in));
            echo '<div class="notice notice-success"><p>Settings saved! ' . count($collections) . ' collection' . (count($collections) === 1 ? '' : 's') . ' stored.</p></div>';
            if ($clear_key) {
                echo '<div class="notice notice-warning"><p>Zotero API key cleared. Cache refresh will fail until a new key is saved.</p></div>';
            } elseif ($posted_key !== '') {
                echo '<div class="notice notice-success"><p>Zotero API key saved.</p></div>';
            }
            if ($skipped > 0) {
                echo '<div class="notice notice-error"><p>' . intval($skipped) . ' row(s) were not saved. Each collection needs a valid Zotero group URL, or both Group ID and Library Name.</p></div>';
            }
        }
    }
    
    if (isset($_POST['zotero_viz_refresh_cache'])) {
        check_admin_referer('zotero_viz_admin');
        $results = zotero_viz_refresh_all_caches();
        if (empty($results)) {
            echo '<div class="notice notice-warning"><p>No collections to cache. Add a collection on the Settings tab first.</p></div>';
        } else {
            foreach ($results as $result) {
                $class = !empty($result['success']) ? 'notice-success' : 'notice-error';
                echo '<div class="notice ' . $class . '"><p><strong>' . esc_html($result['display_name']) . ':</strong> ' . esc_html($result['message']) . '</p></div>';
            }
        }
    }
    
    $collections = get_option('zotero_viz_collections', array());
    if (!is_array($collections)) {
        $collections = array();
    }
    $colors = zotero_viz_sanitize_colors(get_option('zotero_viz_colors', array()));
    $has_api_key = zotero_viz_has_api_key();
    
    // Get current tab
    $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
    ?>
    <div class="wrap">
        <h1>Zotero Visualizations</h1>
        
        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper">
            <a href="?page=zotero-viz&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                Settings
            </a>
            <a href="?page=zotero-viz&tab=cache" class="nav-tab <?php echo $current_tab === 'cache' ? 'nav-tab-active' : ''; ?>">
                Cache Status
            </a>
            <a href="?page=zotero-viz&tab=documentation" class="nav-tab <?php echo $current_tab === 'documentation' ? 'nav-tab-active' : ''; ?>">
                Documentation
            </a>
        </nav>
        
        <div class="tab-content" style="margin-top: 20px;">
            <?php if ($current_tab === 'settings'): ?>
                <!-- Settings Tab -->
                <form method="post">
                    <?php wp_nonce_field('zotero_viz_admin'); ?>
                    <h2>API Authentication</h2>
                    <?php if (!$has_api_key): ?>
                        <div class="notice notice-warning inline"><p>A Zotero API key is required to fetch library data. Create one at <a href="https://www.zotero.org/settings/keys" target="_blank" rel="noopener noreferrer">zotero.org/settings/keys</a> with access to your group libraries.</p></div>
                    <?php endif; ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="zotero_viz_api_key">Zotero API Key</label></th>
                            <td>
                                <input type="password" id="zotero_viz_api_key" name="zotero_viz_api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $has_api_key ? '••••••••••••••••' : ''; ?>" />
                                <?php if ($has_api_key): ?>
                                    <p class="description"><strong>API key is configured.</strong> Leave this field blank to keep the current key. The key is stored encrypted and is never shown again.</p>
                                <?php else: ?>
                                    <p class="description">Required for cache refresh. The key is used only on the server and is never sent to public pages.</p>
                                <?php endif; ?>
                                <p>
                                    <label>
                                        <input type="checkbox" name="zotero_viz_clear_api_key" value="1" />
                                        Clear API key
                                    </label>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2>Zotero Collections</h2>
                    <p class="description">Enter a Zotero URL (e.g., https://www.zotero.org/groups/5872416/hl_standards/library) or manually specify the components.</p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 25%">Zotero URL</th>
                                <th style="width: 15%">Display Name</th>
                                <th style="width: 15%">Library Name</th>
                                <th style="width: 12%">Group ID</th>
                                <th style="width: 13%">Collection Key</th>
                                <th style="width: 10%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="collections-list">
                            <?php foreach ($collections as $i => $collection): ?>
                            <tr>
                                <td><input type="text" name="collections[<?php echo $i; ?>][url]" value="<?php echo esc_attr($collection['url'] ?? ''); ?>" placeholder="https://www.zotero.org/groups/..." /></td>
                                <td><input type="text" name="collections[<?php echo $i; ?>][display_name]" value="<?php echo esc_attr($collection['display_name'] ?? ''); ?>" placeholder="e.g., hl_standards_full" /></td>
                                <td><input type="text" name="collections[<?php echo $i; ?>][library_name]" value="<?php echo esc_attr($collection['library_name'] ?? ''); ?>" /></td>
                                <td><input type="text" name="collections[<?php echo $i; ?>][group_id]" value="<?php echo esc_attr($collection['group_id'] ?? ''); ?>" /></td>
                                <td><input type="text" name="collections[<?php echo $i; ?>][collection_key]" value="<?php echo esc_attr($collection['collection_key'] ?? ''); ?>" /></td>
                                <td><button type="button" class="button remove-collection">Remove</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="button" class="button" id="add-collection">Add Collection</button></p>
                    
                    <div class="postbox" style="margin-top: 20px;">
                        <div class="postbox-header"><h3>Field Explanations</h3></div>
                        <div class="inside">
                            <ul>
                                <li><strong>Zotero URL:</strong> Full URL from Zotero (auto-fills other fields)</li>
                                <li><strong>Display Name:</strong> Unique identifier for shortcodes (e.g., "hl_standards_full", "hl_standards_fhir")</li>
                                <li><strong>Library Name:</strong> Actual library name in Zotero (must match exactly)</li>
                                <li><strong>Group ID:</strong> Numeric ID from the Zotero URL</li>
                                <li><strong>Collection Key:</strong> Leave empty for full library, or specify for specific collection</li>
                            </ul>
                            <p><strong>Example shortcode usage:</strong> <code>[zotero_timeline library="hl_standards_full"]</code></p>
                        </div>
                    </div>
                    
                    <h2>Color Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th>Highlight Color</th>
                            <td>
                                <input type="color" value="<?php echo esc_attr($colors['highlight']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[highlight]" value="<?php echo esc_attr($colors['highlight']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" placeholder="#FF0000" />
                                <p class="description">Color for countries with citations (click color box or enter hex code)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Default Color</th>
                            <td>
                                <input type="color" value="<?php echo esc_attr($colors['default']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[default]" value="<?php echo esc_attr($colors['default']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" placeholder="#FCFCFC" />
                                <p class="description">Color for countries without citations (click color box or enter hex code)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Border Color</th>
                            <td>
                                <input type="color" value="<?php echo esc_attr($colors['border']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[border]" value="<?php echo esc_attr($colors['border']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" placeholder="#999999" />
                                <p class="description">Country border color (click color box or enter hex code)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Water Color</th>
                            <td>
                                <input type="color" value="<?php echo esc_attr($colors['water']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[water]" value="<?php echo esc_attr($colors['water']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" placeholder="#E6F3FF" />
                                <p class="description">Ocean/water background color (click color box or enter hex code)</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="zotero_viz_save_settings" class="button-primary" value="Save Settings" />
                        <input type="submit" name="zotero_viz_refresh_cache" class="button-secondary" value="Refresh Cache Now" />
                    </p>
                </form>
                
            <?php elseif ($current_tab === 'cache'): ?>
                <!-- Cache Status Tab -->
                <h2>Cache Status</h2>
                <p class="description">Monitor the status of your cached Zotero data. Cache refreshes automatically daily, or click "Refresh Cache Now" below.</p>
                <p class="description"><strong>Cache directory:</strong> <code><?php echo esc_html(zotero_viz_cache_dir()); ?></code>
                    <?php if (zotero_viz_ensure_cache_dir()): ?>
                        — writable
                    <?php else: ?>
                        — <span style="color:#b32d2e;">not writable. WordPress cannot save cache files here.</span>
                    <?php endif; ?>
                </p>
                <?php if (!$has_api_key): ?>
                    <div class="notice notice-warning inline"><p>No Zotero API key is configured. Cache refresh will fail until you add one on the Settings tab.</p></div>
                <?php endif; ?>
                <form method="post" style="margin: 12px 0 16px;">
                    <?php wp_nonce_field('zotero_viz_admin'); ?>
                    <input type="submit" name="zotero_viz_refresh_cache" class="button button-secondary" value="Refresh Cache Now" />
                </form>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Display Name</th>
                            <th>Library Name</th>
                            <th>Type</th>
                            <th>Items Cached</th>
                            <th>Countries Tagged</th>
                            <th>Years Covered</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($collections)) {
                            echo '<tr><td colspan="7"><em>No collections configured yet. Go to the Settings tab to add collections.</em></td></tr>';
                        } else {
                            foreach ($collections as $collection) {
                                $display_name = $collection['display_name'] ?? $collection['library_name'];
                                $cache_file = zotero_viz_find_cache_file($display_name);
                                if (file_exists($cache_file)) {
                                    $cache_data = json_decode(file_get_contents($cache_file), true);
                                    $type = empty($collection['collection_key']) ? 'Full Library' : 'Collection';
                                    $countries = count($cache_data['map'] ?? []);
                                    $years = count($cache_data['timeline'] ?? []);
                                    $year_range = $years > 0 ? min(array_keys($cache_data['timeline'])) . '-' . max(array_keys($cache_data['timeline'])) : 'N/A';
                                    $updated = human_time_diff($cache_data['updated']) . ' ago';
                                    $item_count = isset($cache_data['item_count']) ? $cache_data['item_count'] : 'Unknown';
                                    
                                    echo '<tr>';
                                    echo '<td><strong>' . esc_html($display_name) . '</strong></td>';
                                    echo '<td>' . esc_html($collection['library_name']) . '</td>';
                                    echo '<td>' . esc_html($type) . '</td>';
                                    echo '<td>' . esc_html($item_count) . '</td>';
                                    echo '<td>' . esc_html($countries) . '</td>';
                                    echo '<td>' . esc_html($year_range) . '</td>';
                                    echo '<td>' . esc_html($updated) . '</td>';
                                    echo '</tr>';
                                } else {
                                    echo '<tr>';
                                    echo '<td><strong>' . esc_html($display_name) . '</strong></td>';
                                    echo '<td>' . esc_html($collection['library_name']) . '</td>';
                                    echo '<td colspan="5"><em>Not cached yet - refresh cache to populate</em></td>';
                                    echo '</tr>';
                                }
                            }
                        }
                        ?>
                    </tbody>
                </table>
                
            <?php elseif ($current_tab === 'documentation'): ?>
                <!-- Documentation Tab -->
                <div class="documentation-content">
                    <?php echo zotero_viz_render_readme(); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($current_tab === 'settings'): ?>
    <script>
    jQuery(document).ready(function($) {
        var collectionIndex = <?php echo count($collections); ?>;
        
        $('#add-collection').click(function() {
            var newRow = '<tr>' +
                '<td><input type="text" name="collections[' + collectionIndex + '][url]" placeholder="https://www.zotero.org/groups/..." /></td>' +
                '<td><input type="text" name="collections[' + collectionIndex + '][display_name]" placeholder="e.g., hl_standards_full" /></td>' +
                '<td><input type="text" name="collections[' + collectionIndex + '][library_name]" /></td>' +
                '<td><input type="text" name="collections[' + collectionIndex + '][group_id]" /></td>' +
                '<td><input type="text" name="collections[' + collectionIndex + '][collection_key]" /></td>' +
                '<td><button type="button" class="button remove-collection">Remove</button></td>' +
                '</tr>';
            $('#collections-list').append(newRow);
            collectionIndex++;
        });
        
        $(document).on('click', '.remove-collection', function() {
            $(this).closest('tr').remove();
        });
        
        // Auto-parse URL when entered
        $(document).on('blur', 'input[name*="[url]"]', function() {
            var $row = $(this).closest('tr');
            var url = $(this).val();
            
            if (url) {
                // Parse the URL with AJAX
                $.post(ajaxurl, {
                    action: 'zotero_viz_parse_url',
                    url: url,
                    nonce: '<?php echo wp_create_nonce('zotero_viz_parse'); ?>'
                }, function(response) {
                    if (response.success) {
                        $row.find('input[name*="[library_name]"]').val(response.data.library_name);
                        $row.find('input[name*="[group_id]"]').val(response.data.group_id);
                        $row.find('input[name*="[collection_key]"]').val(response.data.collection_key || '');
                        
                        // Auto-suggest display name if empty
                        var $displayName = $row.find('input[name*="[display_name]"]');
                        if (!$displayName.val()) {
                            var suggestedName = response.data.library_name;
                            if (response.data.collection_key) {
                                suggestedName += '_collection';
                            } else {
                                suggestedName += '_full';
                            }
                            $displayName.val(suggestedName);
                        }
                    } else {
                        window.alert('Could not parse that Zotero URL. Use a group library URL, or fill Group ID and Library Name manually.');
                    }
                });
            }
        });
        
        // Color picker and hex input synchronization
        $('.color-picker').on('change', function() {
            var hexValue = $(this).val().toUpperCase();
            $(this).siblings('.hex-input').val(hexValue);
        });
        
        $('.hex-input').on('input blur', function() {
            var hexValue = $(this).val().trim();
            
            // Add # if missing
            if (hexValue && !hexValue.startsWith('#')) {
                hexValue = '#' + hexValue;
            }
            
            // Validate hex color format
            if (/^#[0-9A-Fa-f]{6}$/.test(hexValue)) {
                $(this).val(hexValue.toUpperCase());
                $(this).siblings('.color-picker').val(hexValue);
                $(this).css('border-color', '#ddd'); // Reset border to normal
            } else if (hexValue === '') {
                // Allow empty value
                $(this).css('border-color', '#ddd');
            } else {
                // Invalid hex color - show red border
                $(this).css('border-color', '#dc3232');
            }
        });
    });
    </script>
    <?php endif; ?>
    
    <style>
    .documentation-content {
        background: #fff;
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 4px;
        max-width: none;
        font-size: 14px;
        line-height: 1.6;
    }
    
    .documentation-content h1 {
        color: #23282d;
        border-bottom: 2px solid #0073aa;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }
    
    .documentation-content h2 {
        color: #23282d;
        margin-top: 30px;
        padding-top: 10px;
        border-top: 1px solid #eee;
    }
    
    .documentation-content h3 {
        color: #555;
        margin-top: 25px;
    }
    
    .documentation-content code {
        background: #f1f1f1;
        padding: 2px 4px;
        border-radius: 2px;
        font-size: 13px;
    }
    
    .documentation-content pre {
        background: #f8f8f8;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
        overflow-x: auto;
        margin: 15px 0;
    }
    
    .documentation-content pre code {
        background: none;
        padding: 0;
    }
    
    .documentation-content ul, .documentation-content ol {
        margin-left: 20px;
        margin-bottom: 15px;
    }
    
    .documentation-content li {
        margin-bottom: 5px;
    }
    
    .documentation-content blockquote {
        border-left: 4px solid #0073aa;
        padding-left: 16px;
        margin-left: 0;
        color: #666;
        font-style: italic;
    }
    
    .documentation-content table {
        border-collapse: collapse;
        width: 100%;
        margin: 15px 0;
    }
    
    .documentation-content table th,
    .documentation-content table td {
        border: 1px solid #ddd;
        padding: 8px 12px;
        text-align: left;
    }
    
    .documentation-content table th {
        background: #f5f5f5;
        font-weight: bold;
    }
    </style>
    <?php
}

// Function to render README content
function zotero_viz_render_readme() {
    // Try to find README file in multiple formats (common in GitHub repos)
    $readme_files = array(
        'README.md',
        'README.txt',
        'README',
        'readme.md',
        'readme.txt',
        'readme',
        'Readme.md',
        'Readme.txt',
        'Readme'
    );
    
    $readme_file = null;
    $readme_content = null;
    
    // Try each possible README file
    foreach ($readme_files as $filename) {
        $filepath = ZOTERO_VIZ_PLUGIN_DIR . $filename;
        if (file_exists($filepath)) {
            $readme_file = $filepath;
            $readme_content = file_get_contents($filepath);
            break;
        }
    }
    
    // Handle file not found or read error
    if (!$readme_file) {
        return '<div class="notice notice-warning">
                    <p><strong>README file not found</strong></p>
                    <p>Create a README file in your plugin directory with one of these names:</p>
                    <ul>
                        <li><code>README.md</code> (recommended)</li>
                        <li><code>README.txt</code></li>
                        <li><code>README</code> (no extension)</li>
                    </ul>
                    <p>Plugin directory: <code>' . esc_html(ZOTERO_VIZ_PLUGIN_DIR) . '</code></p>
                    <p>The file should contain your plugin documentation in Markdown format.</p>
                </div>';
    }
    
    if ($readme_content === false) {
        return '<div class="notice notice-error">
                    <p>Error reading README file: <code>' . esc_html(basename($readme_file)) . '</code></p>
                </div>';
    }

    // Convert markdown to HTML (enhanced conversion with better code block handling)
    $html = $readme_content;
    
    // First, handle code blocks more carefully
    // Handle fenced code blocks (```) - must be done before other processing
    $html = preg_replace_callback('/```(\w+)?\s*\n(.*?)\n```/s', function($matches) {
        $language = !empty($matches[1]) ? ' class="language-' . $matches[1] . '"' : '';
        $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
        return '<pre><code' . $language . '>' . $code . '</code></pre>';
    }, $html);
    
    // Handle indented code blocks (4+ spaces or 1+ tabs at start of line)
    $lines = explode("\n", $html);
    $processed_lines = array();
    $in_code_block = false;
    $code_block_content = array();
    
    foreach ($lines as $line) {
        // Check if line is indented (4+ spaces or starts with tab)
        if (preg_match('/^(    |\t)/', $line)) {
            if (!$in_code_block) {
                $in_code_block = true;
                $code_block_content = array();
            }
            // Remove the indentation for display
            $code_block_content[] = preg_replace('/^(    |\t)/', '', $line);
        } else {
            // Not an indented line
            if ($in_code_block) {
                // End the code block
                $processed_lines[] = '<pre><code>' . htmlspecialchars(implode("\n", $code_block_content), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                $in_code_block = false;
                $code_block_content = array();
            }
            $processed_lines[] = $line;
        }
    }
    
    // Handle any remaining code block
    if ($in_code_block) {
        $processed_lines[] = '<pre><code>' . htmlspecialchars(implode("\n", $code_block_content), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }
    
    $html = implode("\n", $processed_lines);
    
    // Convert headers (order matters - do h4 before h3 before h2 before h1)
    $html = preg_replace('/^#### (.*$)/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $html);
    
    // Convert inline code (only if not already in a <pre> block)
    $html = preg_replace_callback('/`([^`\n]+)`/', function($matches) {
        return '<code>' . htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') . '</code>';
    }, $html);
    
    // Convert bold and italic
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
    
    // Convert links
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $html);
    
    // Convert bullet points and numbered lists
    $lines = explode("\n", $html);
    $in_list = false;
    $list_type = '';
    $processed_lines = array();
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Skip lines that are already HTML (like our processed code blocks)
        if (strpos($trimmed, '<pre>') === 0 || strpos($trimmed, '</pre>') !== false || 
            strpos($trimmed, '<h1>') === 0 || strpos($trimmed, '<h2>') === 0 || 
            strpos($trimmed, '<h3>') === 0 || strpos($trimmed, '<h4>') === 0) {
            
            if ($in_list) {
                $processed_lines[] = "</$list_type>";
                $in_list = false;
                $list_type = '';
            }
            $processed_lines[] = $line;
            continue;
        }
        
        // Check for bullet points
        if (preg_match('/^- (.*)/', $trimmed, $matches)) {
            if (!$in_list || $list_type !== 'ul') {
                if ($in_list) {
                    $processed_lines[] = "</$list_type>";
                }
                $processed_lines[] = '<ul>';
                $list_type = 'ul';
                $in_list = true;
            }
            $processed_lines[] = '<li>' . $matches[1] . '</li>';
        }
        // Check for numbered lists
        elseif (preg_match('/^\d+\. (.*)/', $trimmed, $matches)) {
            if (!$in_list || $list_type !== 'ol') {
                if ($in_list) {
                    $processed_lines[] = "</$list_type>";
                }
                $processed_lines[] = '<ol>';
                $list_type = 'ol';
                $in_list = true;
            }
            $processed_lines[] = '<li>' . $matches[1] . '</li>';
        }
        // Not a list item
        else {
            if ($in_list) {
                $processed_lines[] = "</$list_type>";
                $in_list = false;
                $list_type = '';
            }
            $processed_lines[] = $line;
        }
    }
    
    // Close any remaining list
    if ($in_list) {
        $processed_lines[] = "</$list_type>";
    }
    
    $html = implode("\n", $processed_lines);
    
    // Convert blockquotes
    $html = preg_replace('/^> (.*$)/m', '<blockquote>$1</blockquote>', $html);
    
    // Convert horizontal rules
    $html = preg_replace('/^---+$/m', '<hr>', $html);
    
    // Convert line breaks to paragraphs (but preserve existing HTML)
    $paragraphs = preg_split('/\n\s*\n/', $html);
    $html_paragraphs = array();
    
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if (!empty($paragraph)) {
            // Don't wrap if it's already HTML (starts with <)
            if (strpos($paragraph, '<') === 0) {
                $html_paragraphs[] = $paragraph;
            } else {
                // Only wrap in <p> if it's not empty and doesn't start with HTML
                $html_paragraphs[] = '<p>' . $paragraph . '</p>';
            }
        }
    }
    
    $html = implode("\n", $html_paragraphs);
    
    // Add a refresh notice with file info
    $last_modified = filemtime($readme_file);
    $filename = basename($readme_file);
    $refresh_notice = '<div class="notice notice-info" style="margin-bottom: 20px;">
        <p><strong>Documentation loaded from: ' . esc_html($filename) . '</strong><br>
        Last modified: ' . date('F j, Y \a\t g:i A', $last_modified) . '<br>
        <em>This documentation updates automatically when you modify the README file.</em></p>
    </div>';
    
    return $refresh_notice . $html;
}

// Zotero API functions
function zotero_viz_fetch_collection_items($group_id, $collection_key = null) {
    $api_key = zotero_viz_get_api_key();
    if ($api_key === '') {
        return new WP_Error(
            'zotero_viz_fetch',
            'Zotero API key is required. Add one on the Settings tab.'
        );
    }

    $items = array();
    $start = 0;
    $limit = 100;
    $max_retries = 3;
    
    $group_id = rawurlencode($group_id);
    if ($collection_key) {
        $base_url = "https://api.zotero.org/groups/{$group_id}/collections/" . rawurlencode($collection_key) . "/items/top";
    } else {
        $base_url = "https://api.zotero.org/groups/{$group_id}/items/top";
    }
    
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    
    do {
        $url = $base_url . "?format=json&limit={$limit}&start={$start}";
        $data = null;
        $response = null;
        $last_error = '';
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            if ($start > 0 || $attempt > 1) {
                usleep(200000);
            }
            
            $response = wp_remote_get($url, array(
                'timeout' => 60,
                'headers' => zotero_viz_api_headers($api_key)
            ));
            
            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                continue;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            if ($code == 429) {
                $last_error = 'Zotero API rate limit (HTTP 429)';
                sleep(3);
                continue;
            }
            if ($code == 401 || $code == 403) {
                return new WP_Error(
                    'zotero_viz_fetch',
                    'Zotero API key is invalid or does not have access to this library (HTTP ' . intval($code) . ')'
                );
            }
            if ($code < 200 || $code >= 300) {
                $body_preview = zotero_viz_redact_api_error_body(wp_remote_retrieve_body($response), $api_key);
                $last_error = 'Zotero API HTTP ' . $code . ': ' . $body_preview;
                continue;
            }
            
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($decoded)) {
                $last_error = 'Invalid JSON from Zotero API';
                continue;
            }
            if (isset($decoded['error']) && !isset($decoded[0])) {
                $last_error = 'Zotero API error: ' . $decoded['error'];
                continue;
            }
            
            $data = $decoded;
            break;
        }
        
        if ($data === null) {
            return new WP_Error('zotero_viz_fetch', $last_error ?: 'Failed to fetch items from Zotero');
        }
        
        if (empty($data)) {
            break;
        }
        
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['data']['itemType']) &&
                !in_array($item['data']['itemType'], array('attachment', 'note'), true)) {
                $items[] = $item;
            }
        }
        
        $start += $limit;
        
        $total_results = wp_remote_retrieve_header($response, 'total-results');
        if ($total_results && $start >= intval($total_results)) {
            break;
        }
        
    } while (count($data) == $limit);
    
    return $items;
}

// Process citations for map data
function zotero_viz_process_map_data($items) {
    $country_counts = array();
    
    foreach ($items as $item) {
        if (isset($item['data']['tags'])) {
            foreach ($item['data']['tags'] as $tag) {
                $country = $tag['tag'];
                // Comprehensive list of World Bank country names
                $valid_countries = array(
                    // North America
                    'United States', 'Canada', 'Mexico',
                    
                    // Central America & Caribbean
                    'Guatemala', 'Belize', 'El Salvador', 'Honduras', 'Nicaragua', 'Costa Rica', 'Panama',
                    'Cuba', 'Haiti', 'Dominican Republic', 'Jamaica', 'Trinidad and Tobago', 'Barbados',
                    'Saint Lucia', 'Grenada', 'Saint Vincent and the Grenadines', 'Antigua and Barbuda',
                    'Dominica', 'Saint Kitts and Nevis',
                    
                    // South America
                    'Brazil', 'Argentina', 'Chile', 'Peru', 'Colombia', 'Venezuela', 'Ecuador', 'Bolivia',
                    'Paraguay', 'Uruguay', 'Guyana', 'Suriname',
                    
                    // Europe
                    'United Kingdom', 'France', 'Germany', 'Spain', 'Italy', 'Poland', 'Netherlands',
                    'Belgium', 'Czech Republic', 'Greece', 'Portugal', 'Sweden', 'Hungary', 'Austria',
                    'Belarus', 'Switzerland', 'Bulgaria', 'Denmark', 'Finland', 'Slovakia', 'Norway',
                    'Ireland', 'Croatia', 'Moldova', 'Bosnia and Herzegovina', 'Albania', 'Lithuania',
                    'Slovenia', 'Latvia', 'Estonia', 'North Macedonia', 'Serbia', 'Montenegro',
                    'Luxembourg', 'Malta', 'Iceland', 'Andorra', 'Monaco', 'Liechtenstein', 'San Marino',
                    'Romania', 'Ukraine', 'Cyprus', 'Kosovo',
                    
                    // Asia
                    'China', 'Japan', 'India', 'South Korea', 'Indonesia', 'Thailand', 'Vietnam',
                    'Philippines', 'Malaysia', 'Singapore', 'Bangladesh', 'Pakistan', 'Afghanistan',
                    'Nepal', 'Sri Lanka', 'Myanmar', 'Cambodia', 'Laos', 'Mongolia', 'Bhutan',
                    'Timor-Leste', 'Brunei', 'Maldives', 'North Korea',
                    
                    // Middle East
                    'Turkey', 'Saudi Arabia', 'Israel', 'United Arab Emirates', 'Iran', 'Iraq', 'Jordan',
                    'Lebanon', 'Kuwait', 'Qatar', 'Bahrain', 'Oman', 'Yemen', 'Syria', 'Palestine',
                    
                    // Central Asia
                    'Kazakhstan', 'Uzbekistan', 'Turkmenistan', 'Tajikistan', 'Kyrgyzstan',
                    'Azerbaijan', 'Armenia', 'Georgia',
                    
                    // Africa
                    'South Africa', 'Nigeria', 'Kenya', 'Egypt', 'Morocco', 'Ethiopia', 'Ghana',
                    'Tanzania', 'Algeria', 'Sudan', 'Uganda', 'Mozambique', 'Madagascar', 'Cameroon',
                    'Angola', 'Niger', 'Burkina Faso', 'Mali', 'Malawi', 'Zambia', 'Senegal',
                    'Somalia', 'Chad', 'Zimbabwe', 'Guinea', 'Rwanda', 'Benin', 'Burundi', 'Tunisia',
                    'South Sudan', 'Togo', 'Sierra Leone', 'Libya', 'Liberia', 'Mauritania',
                    'Central African Republic', 'Eritrea', 'Gambia', 'Botswana', 'Namibia', 'Gabon',
                    'Lesotho', 'Guinea-Bissau', 'Equatorial Guinea', 'Mauritius', 'Eswatini',
                    'Djibouti', 'Comoros', 'Cape Verde', 'Sao Tome and Principe', 'Seychelles',
                    'Congo', 'Democratic Republic of the Congo', "Cote d'Ivoire",
                    
                    // Oceania
                    'Australia', 'New Zealand', 'Papua New Guinea', 'Fiji', 'Solomon Islands',
                    'Vanuatu', 'Samoa', 'Kiribati', 'Micronesia', 'Tonga', 'Palau', 'Marshall Islands',
                    'Tuvalu', 'Nauru',
                    
                    // Others
                    'Russia', 'Greenland'
                );
                
                if (in_array($country, $valid_countries)) {
                    if (!isset($country_counts[$country])) {
                        $country_counts[$country] = 0;
                    }
                    $country_counts[$country]++;
                }
            }
        }
    }
    
    return $country_counts;
}

// Process citations for timeline data
function zotero_viz_process_timeline_data($items) {
    $year_counts = array();
    
    foreach ($items as $item) {
        $year = null;
        
        // Extract year from date field
        if (isset($item['data']['date'])) {
            if (preg_match('/(\d{4})/', $item['data']['date'], $matches)) {
                $year = $matches[1];
            }
        }
        
        if ($year) {
            if (!isset($year_counts[$year])) {
                $year_counts[$year] = 0;
            }
            $year_counts[$year]++;
        }
    }
    
    ksort($year_counts);
    return $year_counts;
}
// Library stats shortcode
add_shortcode('zotero_stats', 'zotero_viz_stats_shortcode');
function zotero_viz_stats_shortcode($atts) {
    $atts = shortcode_atts(array(
        'library' => '', // Display name
        'format' => 'default', // Options: 'default', 'simple', 'detailed'
        'show_countries' => 'true',
        'show_citations' => 'true',
        'show_years' => 'false',
        'style' => 'default' // Options: 'default', 'minimal', 'highlighted'
    ), $atts);
    
    if (empty($atts['library'])) {
        return '<p>Please specify a library display name.</p>';
    }
    
    // Use display name for cache file lookup
    $cache_file = zotero_viz_find_cache_file($atts['library']);
    if (!file_exists($cache_file)) {
        return '<p>Library data not found. Please refresh cache. Looking for: ' . esc_html($atts['library']) . '</p>';
    }
    
    $cache_data = json_decode(file_get_contents($cache_file), true);
    
    // Calculate statistics
    $total_citations = $cache_data['item_count'] ?? 0;
    $countries_count = count($cache_data['map'] ?? []);
    $years_count = count($cache_data['timeline'] ?? []);
    
    // Get year range if we have timeline data
    $year_range = '';
    if (!empty($cache_data['timeline'])) {
        $years = array_keys($cache_data['timeline']);
        $min_year = min($years);
        $max_year = max($years);
        $year_range = ($min_year == $max_year) ? $min_year : $min_year . '–' . $max_year;
    }
    
    // Build output based on format
    $output = '';
    
    // Apply styling
    $css_class = 'zotero-stats';
    switch ($atts['style']) {
        case 'minimal':
            $css_class .= ' zotero-stats-minimal';
            break;
        case 'highlighted':
            $css_class .= ' zotero-stats-highlighted';
            break;
        default:
            $css_class .= ' zotero-stats-default';
    }
    
    ob_start();
    
    switch ($atts['format']) {
        case 'simple':
            // Simple one-line format
            $parts = array();
            if ($atts['show_citations'] === 'true') {
                $parts[] = number_format($total_citations) . ' citation' . ($total_citations != 1 ? 's' : '');
            }
            if ($atts['show_countries'] === 'true' && $countries_count > 0) {
                $parts[] = $countries_count . ' countr' . ($countries_count != 1 ? 'ies' : 'y');
            }
            if ($atts['show_years'] === 'true' && $years_count > 0) {
                $parts[] = $years_count . ' year' . ($years_count != 1 ? 's' : '') . ($year_range ? ' (' . $year_range . ')' : '');
            }
            
            if (!empty($parts)) {
                echo '<div class="' . esc_attr($css_class) . '">';
                echo 'This library contains ' . implode(' pertaining to ', $parts) . '.';
                echo '</div>';
            }
            break;
            
        case 'detailed':
            // Detailed format with more information
            echo '<div class="' . esc_attr($css_class) . '">';
            echo '<div class="zotero-stats-header">Library Statistics</div>';
            echo '<div class="zotero-stats-content">';
            
            if ($atts['show_citations'] === 'true') {
                echo '<div class="zotero-stat-item">';
                echo '<span class="zotero-stat-number">' . number_format($total_citations) . '</span> ';
                echo '<span class="zotero-stat-label">total citation' . ($total_citations != 1 ? 's' : '') . '</span>';
                echo '</div>';
            }
            
            if ($atts['show_countries'] === 'true' && $countries_count > 0) {
                echo '<div class="zotero-stat-item">';
                echo '<span class="zotero-stat-number">' . $countries_count . '</span> ';
                echo '<span class="zotero-stat-label">countr' . ($countries_count != 1 ? 'ies' : 'y') . ' represented</span>';
                echo '</div>';
            }
            
            if ($atts['show_years'] === 'true' && $years_count > 0) {
                echo '<div class="zotero-stat-item">';
                echo '<span class="zotero-stat-number">' . $years_count . '</span> ';
                echo '<span class="zotero-stat-label">year' . ($years_count != 1 ? 's' : '') . ' covered</span>';
                if ($year_range) {
                    echo ' <span class="zotero-stat-range">(' . $year_range . ')</span>';
                }
                echo '</div>';
            }
            
            echo '</div></div>';
            break;
            
        default:
            // Default format - matches your requested format
            $parts = array();
            
            if ($atts['show_citations'] === 'true') {
                $parts[] = '<strong>' . number_format($total_citations) . '</strong> total citation' . ($total_citations != 1 ? 's' : '');
            }
            
            if ($atts['show_countries'] === 'true' && $countries_count > 0) {
                $parts[] = '<strong>' . $countries_count . '</strong> countr' . ($countries_count != 1 ? 'ies' : 'y');
            }
            
            if (!empty($parts)) {
                echo '<div class="' . esc_attr($css_class) . '">';
                echo 'This library contains ' . implode(' pertaining to ', $parts) . '.';
                echo '</div>';
            }
    }
    
    return ob_get_clean();
}

// Cache refresh function
function zotero_viz_refresh_all_caches() {
    $results = array();
    $collections = get_option('zotero_viz_collections', array());
    
    if (!zotero_viz_ensure_cache_dir()) {
        return array(
            array(
                'display_name' => '(all)',
                'success' => false,
                'message' => 'Cache directory is not writable: ' . zotero_viz_cache_dir()
            )
        );
    }
    
    foreach ($collections as $collection) {
        $display_name = !empty($collection['display_name']) ? $collection['display_name'] : ($collection['library_name'] ?? '');
        if ($display_name === '') {
            $display_name = '(unnamed)';
        }
        
        if (empty($collection['group_id']) || empty($collection['library_name'])) {
            $results[] = array(
                'display_name' => $display_name,
                'success' => false,
                'message' => 'Skipped: missing Group ID or Library Name'
            );
            continue;
        }
        
        $collection_key = !empty($collection['collection_key']) ? $collection['collection_key'] : null;
        $items = zotero_viz_fetch_collection_items($collection['group_id'], $collection_key);
        
        if (is_wp_error($items)) {
            $results[] = array(
                'display_name' => $display_name,
                'success' => false,
                'message' => $items->get_error_message()
            );
            continue;
        }
        
        $cache_data = array(
            'map' => zotero_viz_process_map_data($items),
            'timeline' => zotero_viz_process_timeline_data($items),
            'updated' => time(),
            'item_count' => count($items),
            'collection_key' => $collection_key,
            'library_name' => $collection['library_name'],
            'display_name' => $display_name,
            'group_id' => $collection['group_id']
        );
        
        $cache_file = zotero_viz_cache_file($display_name);
        $json = wp_json_encode($cache_data);
        if ($json === false) {
            $results[] = array(
                'display_name' => $display_name,
                'success' => false,
                'message' => 'Failed to encode cache JSON'
            );
            continue;
        }
        
        $written = file_put_contents($cache_file, $json, LOCK_EX);
        if ($written === false) {
            $results[] = array(
                'display_name' => $display_name,
                'success' => false,
                'message' => 'Failed to write cache file: ' . $cache_file
            );
            continue;
        }
        
        $results[] = array(
            'display_name' => $display_name,
            'success' => true,
            'message' => count($items) . ' items cached'
        );
    }
    
    return $results;
}

// Daily cache refresh
add_action('zotero_viz_daily_cache_refresh', 'zotero_viz_refresh_all_caches');

// Enqueue scripts and styles
add_action('wp_enqueue_scripts', 'zotero_viz_enqueue_scripts');
function zotero_viz_enqueue_scripts() {
    // Get current post safely
    $current_post = get_post();
    
    // Check if we have a post and if it contains our shortcodes
    $has_shortcodes = false;
    if ($current_post && isset($current_post->post_content)) {
        $has_shortcodes = has_shortcode($current_post->post_content, 'zotero_map') || 
                        has_shortcode($current_post->post_content, 'zotero_timeline') ||
                        has_shortcode($current_post->post_content, 'zotero_stats');
    }
    
    // Also check if we're on a page that might use shortcodes in widgets or other contexts
    if (!$has_shortcodes) {
        global $wp_query;
        // Check if any queried posts contain our shortcodes
        if (isset($wp_query->posts) && is_array($wp_query->posts)) {
            foreach ($wp_query->posts as $post) {
                if (isset($post->post_content) && 
                    (has_shortcode($post->post_content, 'zotero_map') || 
                    has_shortcode($post->post_content, 'zotero_timeline') ||
                    has_shortcode($post->post_content, 'zotero_stats'))) {
                    $has_shortcodes = true;
                    break;
                }
            }
        }
    }
    
    // Only enqueue if shortcodes are present
    if ($has_shortcodes) {
        wp_enqueue_script('d3-js', 'https://d3js.org/d3.v7.min.js', array(), '7.0.0', true);
        wp_enqueue_script('topojson', 'https://d3js.org/topojson.v3.min.js', array('d3-js'), '3.0.0', true);
        wp_enqueue_script('zotero-viz', ZOTERO_VIZ_PLUGIN_URL . 'assets/zotero-viz.js', array('d3-js', 'topojson'), ZOTERO_VIZ_VERSION, true);
        wp_enqueue_style('zotero-viz', ZOTERO_VIZ_PLUGIN_URL . 'assets/zotero-viz.css', array(), ZOTERO_VIZ_VERSION);
        
        // Pass data to JavaScript
        $colors = zotero_viz_sanitize_colors(get_option('zotero_viz_colors', array()));
        
        wp_localize_script('zotero-viz', 'zoteroVizData', array(
            'pluginUrl' => ZOTERO_VIZ_PLUGIN_URL,
            'colors' => $colors
        ));
    }
}

// Map shortcode
add_shortcode('zotero_map', 'zotero_viz_map_shortcode');
function zotero_viz_map_shortcode($atts) {
    $atts = shortcode_atts(array(
        'library' => '', // Now expects display name
        'width' => '100%',
        'height' => '500px'
    ), $atts);
    
    if (empty($atts['library'])) {
        return '<p>Please specify a library display name.</p>';
    }
    
    // Use display name for cache file lookup
    $cache_file = zotero_viz_find_cache_file($atts['library']);
    if (!file_exists($cache_file)) {
        return '<p>Library data not found. Please refresh cache. Looking for: ' . esc_html($atts['library']) . '</p>';
    }
    
    $cache_data = json_decode(file_get_contents($cache_file), true);
    $map_data = $cache_data['map'];
    
    // Get library information from cache data for constructing Zotero URLs
    $group_id = $cache_data['group_id'] ?? '';
    $library_name = $cache_data['library_name'] ?? ''; // Original library name for URLs
    
    $unique_id = 'zotero-map-' . uniqid();
    
    ob_start();
    ?>
    <div id="<?php echo $unique_id; ?>" class="zotero-map" style="width: <?php echo esc_attr($atts['width']); ?>; height: <?php echo esc_attr($atts['height']); ?>;" 
         data-zotero-group-id="<?php echo esc_attr($group_id); ?>"
         data-zotero-library-name="<?php echo esc_attr($library_name); ?>"
         data-zotero-display-name="<?php echo esc_attr($atts['library']); ?>">
        <div class="zotero-map-container"></div>
        <div class="zotero-map-tooltip" style="display: none;"></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof drawZoteroMap === 'function') {
            drawZoteroMap('<?php echo $unique_id; ?>', <?php echo json_encode($map_data); ?>);
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

// Timeline shortcode
add_shortcode('zotero_timeline', 'zotero_viz_timeline_shortcode');
function zotero_viz_timeline_shortcode($atts) {
    $atts = shortcode_atts(array(
        'library' => '', // Now expects display name
        'width' => '100%',
        'height' => '400px',
        'show_projection_note' => 'true'
    ), $atts);
    
    if (empty($atts['library'])) {
        return '<p>Please specify a library display name.</p>';
    }
    
    // Use display name for cache file lookup
    $cache_file = zotero_viz_find_cache_file($atts['library']);
    if (!file_exists($cache_file)) {
        return '<p>Library data not found. Please refresh cache. Looking for: ' . esc_html($atts['library']) . '</p>';
    }
    
    $cache_data = json_decode(file_get_contents($cache_file), true);
    $timeline_data = $cache_data['timeline'];
    
    // Calculate projection for current year
    $current_year = date('Y');
    $current_month = date('n');
    $current_day = date('j');
    $days_in_year = 365;
    $days_elapsed = date('z') + 1; // Day of year (1-365/366)
    $days_remaining = $days_in_year - $days_elapsed;
    
    if (isset($timeline_data[$current_year]) && $days_remaining > 0) {
        // Calculate average from last 12 months
        $last_year = $current_year - 1;
        $total_last_12_months = 0;
        
        // Get the monthly breakdown for more accurate calculation
        // For now, we'll use the yearly totals
        if (isset($timeline_data[$last_year])) {
            // Use last year's data as baseline
            $total_last_12_months = $timeline_data[$last_year];
        }
        
        // Calculate daily average based on last year
        $daily_average = $total_last_12_months / 365;
        
        // If we have current year data, use it to calculate a better average
        $current_year_actual = $timeline_data[$current_year];
        if ($current_year_actual > 0 && $days_elapsed > 30) {
            // Use current year's rate if we have enough data
            $current_daily_average = $current_year_actual / $days_elapsed;
            // Blend the two averages, weighing current year more heavily
            $daily_average = ($current_daily_average * 0.7) + ($daily_average * 0.3);
        }
        
        // Project the remaining days
        $projected_additional = round($daily_average * $days_remaining);
        $timeline_data[$current_year . '_projected'] = $projected_additional;
        $timeline_data[$current_year . '_actual'] = $current_year_actual;
    }
    
    $unique_id = 'zotero-timeline-' . uniqid();
    
    ob_start();
    ?>
    <div id="<?php echo $unique_id; ?>" class="zotero-timeline" style="width: <?php echo esc_attr($atts['width']); ?>; height: <?php echo esc_attr($atts['height']); ?>;">
        <div class="zotero-timeline-container"></div>
    </div>
    <?php if ($atts['show_projection_note'] === 'true' && isset($timeline_data[$current_year . '_projected'])): ?>
    <p class="zotero-projection-note" style="font-size: 0.9em; color: #666; text-align: center; margin-top: 10px;">
        * <?php echo date('Y'); ?> projection based on <?php echo $days_elapsed; ?> days of data (as of <?php echo date('F j'); ?>)
    </p>
    <?php endif; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof drawZoteroTimeline === 'function') {
            drawZoteroTimeline('<?php echo $unique_id; ?>', <?php echo json_encode($timeline_data); ?>);
        }
    });
    </script>
    <?php
    return ob_get_clean();
}