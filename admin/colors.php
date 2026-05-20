<?php
// Color customization settings page
function n8n_union_chat_colors_page() {
    if (!n8n_union_chat_current_user_can_manage()) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'n8n-chatagent-for-unions'));
    }
    
    if (isset($_POST['submit']) && check_admin_referer('n8n_union_colors_save', 'n8n_union_colors_nonce')) {
        if (isset($_POST['button_bg_color'])) {
            update_option('n8n_union_button_bg_color', sanitize_hex_color(wp_unslash($_POST['button_bg_color'])));
        }
        if (isset($_POST['button_hover_color'])) {
            update_option('n8n_union_button_hover_color', sanitize_hex_color(wp_unslash($_POST['button_hover_color'])));
        }
        if (isset($_POST['chat_bubble_user_color'])) {
            update_option('n8n_union_chat_bubble_user_color', sanitize_hex_color(wp_unslash($_POST['chat_bubble_user_color'])));
        }
        if (isset($_POST['header_gradient_start_color'])) {
            update_option('n8n_union_header_gradient_start_color', sanitize_hex_color(wp_unslash($_POST['header_gradient_start_color'])));
        }
        if (isset($_POST['header_gradient_end_color'])) {
            update_option('n8n_union_header_gradient_end_color', sanitize_hex_color(wp_unslash($_POST['header_gradient_end_color'])));
        }
        if (isset($_POST['chat_width'])) {
            update_option('n8n_union_chat_width', max(280, absint(wp_unslash($_POST['chat_width']))));
        }
        if (isset($_POST['chat_height_vh'])) {
            update_option('n8n_union_chat_height_vh', max(30, absint(wp_unslash($_POST['chat_height_vh']))));
        }
        if (isset($_POST['chat_height_max'])) {
            update_option('n8n_union_chat_height_max', max(400, absint(wp_unslash($_POST['chat_height_max']))));
        }
        if (isset($_POST['launcher_size'])) {
            $launcher_size = max(40, min(120, absint(wp_unslash($_POST['launcher_size']))));
            update_option('n8n_union_launcher_size', $launcher_size);
        }
        
        echo '<div class="notice notice-success"><p>' . esc_html__('Settings updated successfully.', 'n8n-chatagent-for-unions') . '</p></div>';
    }
    
    $button_bg = get_option('n8n_union_button_bg_color', '#C8102E');
    $button_hover = get_option('n8n_union_button_hover_color', '#00205B');
    $chat_bubble_user = get_option('n8n_union_chat_bubble_user_color', '#0033A0');
    $header_gradient_start = get_option('n8n_union_header_gradient_start_color', '#0033A0');
    $header_gradient_end = get_option('n8n_union_header_gradient_end_color', '#C8102E');
    $chat_width = get_option('n8n_union_chat_width', 370);
    $chat_height_vh = get_option('n8n_union_chat_height_vh', 60);
    $chat_height_max = get_option('n8n_union_chat_height_max', 720);
    $launcher_size = get_option('n8n_union_launcher_size', 64);
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-art n8n_union-colors-icon"></span>Colors & Styling</h1>
        <p>Customize the look and footprint of the n8n ChatAgent for Unions widget:</p>
        <form method="post" action="">
            <?php wp_nonce_field('n8n_union_colors_save', 'n8n_union_colors_nonce'); ?>
            <h2>Color Palette</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="button_bg_color">Button Background Color</label>
                            <p class="description">Default color for the launcher and send button.</p>
                        </th>
                        <td>
                            <input type="color" id="button_bg_color" name="button_bg_color" value="<?php echo esc_attr($button_bg); ?>" />
                            <input type="text" value="<?php echo esc_attr($button_bg); ?>" class="n8n_union-color-text" readonly>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="button_hover_color">Button Hover Color</label>
                            <p class="description">Color when hovering over buttons.</p>
                        </th>
                        <td>
                            <input type="color" id="button_hover_color" name="button_hover_color" value="<?php echo esc_attr($button_hover); ?>" />
                            <input type="text" value="<?php echo esc_attr($button_hover); ?>" class="n8n_union-color-text" readonly>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="chat_bubble_user_color">User Chat Bubble Color</label>
                            <p class="description">Background color of user messages.</p>
                        </th>
                        <td>
                            <input type="color" id="chat_bubble_user_color" name="chat_bubble_user_color" value="<?php echo esc_attr($chat_bubble_user); ?>" />
                            <input type="text" value="<?php echo esc_attr($chat_bubble_user); ?>" class="n8n_union-color-text" readonly>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="header_gradient_start_color">Header Gradient Start</label>
                        </th>
                        <td>
                            <input type="color" id="header_gradient_start_color" name="header_gradient_start_color" value="<?php echo esc_attr($header_gradient_start); ?>" />
                            <input type="text" value="<?php echo esc_attr($header_gradient_start); ?>" class="n8n_union-color-text" readonly>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="header_gradient_end_color">Header Gradient End</label>
                        </th>
                        <td>
                            <input type="color" id="header_gradient_end_color" name="header_gradient_end_color" value="<?php echo esc_attr($header_gradient_end); ?>" />
                            <input type="text" value="<?php echo esc_attr($header_gradient_end); ?>" class="n8n_union-color-text" readonly>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <h2>Chat Box Size</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="chat_width">Desktop Width (px)</label></th>
                        <td>
                            <input type="number" id="chat_width" name="chat_width" min="280" max="540" value="<?php echo esc_attr($chat_width); ?>" class="small-text">
                            <p class="description">Default: 370px. Applies on larger screens.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_height_vh">Desktop Height (% of viewport)</label></th>
                        <td>
                            <input type="number" id="chat_height_vh" name="chat_height_vh" min="30" max="90" value="<?php echo esc_attr($chat_height_vh); ?>" class="small-text">
                            <p class="description">Default: 60% of the viewport height.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_height_max">Max Height (px)</label></th>
                        <td>
                            <input type="number" id="chat_height_max" name="chat_height_max" min="400" max="900" value="<?php echo esc_attr($chat_height_max); ?>" class="small-text">
                            <p class="description">Default: 720px. The widget never exceeds this height.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="launcher_size">Launcher Button Size (px)</label></th>
                        <td>
                            <input type="number" id="launcher_size" name="launcher_size" min="40" max="120" value="<?php echo esc_attr($launcher_size); ?>" class="small-text">
                            <p class="description">Controls the round launcher bubble (default: 64px). Increase for larger custom icons.</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            
            <?php submit_button('Save Styling'); ?>
        </form>
        
        <?php
        wp_add_inline_script('jquery', 'jQuery(document).ready(function($){$("input[type=color]").on("change",function(){ $(this).next(".n8n_union-color-text").val(this.value); });});');
        
        $color_preview_css = '
        :root {
            --n8n_union-button-bg: ' . esc_attr($button_bg) . ';
            --n8n_union-button-hover: ' . esc_attr($button_hover) . ';
            --n8n_union-chat-bubble-user: ' . esc_attr($chat_bubble_user) . ';
        }
        .n8n_union-colors-icon { font-size:30px; margin-right:10px; }
        .form-table th { width:220px; }
        .n8n_union-color-text {
            font-family: monospace;
            background:#f0f0f0;
            border:1px solid #ccc;
            padding:3px 6px;
            border-radius:3px;
            margin-left:10px;
            width:80px;
        }';
        wp_add_inline_style('n8n-union-ai-admin-css', $color_preview_css);
        ?>
    </div>
    <?php
}
