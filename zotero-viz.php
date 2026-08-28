<?php
/**
 * Plugin Name: Zotero Visualizations
 * Description: Display interactive world maps and bar charts from Zotero collections
 * Version: 1.0.7
 * Author: Daniel J. Vreeman, PT, DPT, MS, FACMI, FIAHSI
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZOTERO_VIZ_VERSION', '1.0.6'); // Increment this to force cache refresh
define('ZOTERO_VIZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZOTERO_VIZ_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZOTERO_VIZ_CACHE_DIR', WP_CONTENT_DIR . '/cache/zotero-viz/');

// Create cache directory on activation
register_activation_hook(__FILE__, 'zotero_viz_activate');
function zotero_viz_activate() {
    if (!file_exists(ZOTERO_VIZ_CACHE_DIR)) {
        wp_mkdir_p(ZOTERO_VIZ_CACHE_DIR);
    }
    
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

// Parse Zotero URL to extract components
function zotero_viz_parse_url($url) {
    // Pattern: https://www.zotero.org/groups/{group_id}/{library_name}/collections/{collection_key}
    // or: https://www.zotero.org/groups/{group_id}/{library_name}/library
    
    $pattern = '/https:\/\/www\.zotero\.org\/groups\/(\d+)\/([^\/]+)\/(library|collections\/([A-Z0-9]+))/';
    
    if (preg_match($pattern, $url, $matches)) {
        $result = array(
            'group_id' => $matches[1],
            'library_name' => $matches[2],
            'collection_key' => isset($matches[4]) ? $matches[4] : '',
            'url' => $url
        );
        
        return $result;
    }
    
    return false;
}

// AJAX handler for URL parsing
add_action('wp_ajax_zotero_viz_parse_url', 'zotero_viz_ajax_parse_url');
function zotero_viz_ajax_parse_url() {
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
    // Handle form submissions
    if (isset($_POST['zotero_viz_save_settings'])) {
        $collections = array();
        if (isset($_POST['collections']) && is_array($_POST['collections'])) {
            foreach ($_POST['collections'] as $collection) {
                if (!empty($collection['url']) || (!empty($collection['library_name']) && !empty($collection['display_name']))) {
                    // Parse URL if provided
                    if (!empty($collection['url'])) {
                        $parsed = zotero_viz_parse_url($collection['url']);
                        if ($parsed) {
                            // Add display name to parsed data
                            $parsed['display_name'] = !empty($collection['display_name']) 
                                ? sanitize_text_field($collection['display_name']) 
                                : $parsed['library_name'];
                            $collections[] = $parsed;
                        }
                    } else {
                        // Use manual entry
                        $collections[] = array(
                            'library_name' => sanitize_text_field($collection['library_name']),
                            'display_name' => sanitize_text_field($collection['display_name']),
                            'group_id' => sanitize_text_field($collection['group_id']),
                            'collection_key' => sanitize_text_field($collection['collection_key']),
                            'url' => ''
                        );
                    }
                }
            }
        }
        update_option('zotero_viz_collections', $collections);
        update_option('zotero_viz_colors', $_POST['colors']);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    if (isset($_POST['zotero_viz_refresh_cache'])) {
        zotero_viz_refresh_all_caches();
        echo '<div class="notice notice-success"><p>Cache refreshed!</p></div>';
    }
    
    $collections = get_option('zotero_viz_collections', array());
    $colors = get_option('zotero_viz_colors', array(
        'highlight' => '#ff0000',
        'default' => '#cccccc',
        'border' => '#999999',
        'water' => '#e6f3ff'
    ));
    
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
                                <td><input type="text" name="collections[<?php echo $i; ?>][library_name]" value="<?php echo esc_attr($collection['library_name']); ?>" /></td>
                                <td><input type="text" name="collections[<?php echo $i; ?>][group_id]" value="<?php echo esc_attr($collection['group_id']); ?>" /></td>
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
                                <input type="color" name="colors[highlight]" value="<?php echo esc_attr($colors['highlight']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[highlight]" value="<?php echo esc_attr($colors['highlight']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#FF0000" />
                                <p class="description">Color for countries with citations (click color box or enter hex code)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Default Color</th>
                            <td>
                                <input type="color" name="colors[default]" value="<?php echo esc_attr($colors['default']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[default]" value="<?php echo esc_attr($colors['default']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#FCFCFC" />
                                <p class="description">Color for countries without citations (click color box or enter hex code)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Border Color</th>
                            <td>
                                <input type="color" name="colors[border]" value="<?php echo esc_attr($colors['border']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[border]" value="<?php echo esc_attr($colors['border']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#999999" />
                                <p class="description">Country border color (click color box or enter hex code)</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Water Color</th>
                            <td>
                                <input type="color" name="colors[water]" value="<?php echo esc_attr($colors['water']); ?>" style="margin-right: 10px;" class="color-picker" />
                                <input type="text" name="colors[water]" value="<?php echo esc_attr($colors['water']); ?>" style="width: 80px; padding: 3px 6px; font-family: monospace; text-transform: uppercase;" class="hex-input" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#E6F3FF" />
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
                <p class="description">Monitor the status of your cached Zotero data. Cache refreshes automatically daily, or click "Refresh Cache Now" on the Settings tab.</p>
                
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
                                $cache_file = ZOTERO_VIZ_CACHE_DIR . $display_name . '.json';
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
    $items = array();
    $start = 0;
    $limit = 100;
    
    // Determine API endpoint
    if ($collection_key) {
        // Specific collection
        $base_url = "https://api.zotero.org/groups/{$group_id}/collections/{$collection_key}/items";
    } else {
        // Entire library (excluding trash)
        $base_url = "https://api.zotero.org/groups/{$group_id}/items";
    }
    
    do {
        $url = $base_url . "?format=json&limit={$limit}&start={$start}";
        
        // Add parameter to exclude trashed items when fetching entire library
        if (!$collection_key) {
            $url .= "&itemType=-attachment&trashedItemsOnly=0";
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Zotero-API-Version' => '3'
            )
        ));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data)) {
            break;
        }
        
        // Filter out attachments and notes
        foreach ($data as $item) {
            if (isset($item['data']['itemType']) && 
                !in_array($item['data']['itemType'], array('attachment', 'note'))) {
                $items[] = $item;
            }
        }
        
        $start += $limit;
        
        // Check if we have more results
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
    $cache_file = ZOTERO_VIZ_CACHE_DIR . $atts['library'] . '.json';
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
    $collections = get_option('zotero_viz_collections', array());
    
    foreach ($collections as $collection) {
        // Skip if no group_id or library_name
        if (empty($collection['group_id']) || empty($collection['library_name'])) {
            continue;
        }
        
        // Get display name, fallback to library name if not set
        $display_name = !empty($collection['display_name']) ? $collection['display_name'] : $collection['library_name'];
        
        // Fetch items - collection_key is optional
        $collection_key = !empty($collection['collection_key']) ? $collection['collection_key'] : null;
        $items = zotero_viz_fetch_collection_items($collection['group_id'], $collection_key);
        
        if ($items !== false) {
            $map_data = zotero_viz_process_map_data($items);
            $timeline_data = zotero_viz_process_timeline_data($items);
            
            $cache_data = array(
                'map' => $map_data,
                'timeline' => $timeline_data,
                'updated' => time(),
                'item_count' => count($items),
                'collection_key' => $collection_key,
                'library_name' => $collection['library_name'], // Store original library name
                'display_name' => $display_name,
                'group_id' => $collection['group_id']
            );
            
            // Use display name for cache file
            $cache_file = ZOTERO_VIZ_CACHE_DIR . $display_name . '.json';
            file_put_contents($cache_file, json_encode($cache_data));
        }
    }
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
        $colors = get_option('zotero_viz_colors', array(
            'highlight' => '#ff0000',
            'default' => '#cccccc',
            'border' => '#999999',
            'water' => '#e6f3ff'
        ));
        
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
    $cache_file = ZOTERO_VIZ_CACHE_DIR . $atts['library'] . '.json';
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
    $cache_file = ZOTERO_VIZ_CACHE_DIR . $atts['library'] . '.json';
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