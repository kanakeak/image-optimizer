<?php
/**
 * Plugin Name: Image Optimizer with auto ALT
 * Plugin URI: https://factprobe.blogspot.com/
 * Description: Optimizes images without losing quality.
 * Version: 1.0
 * Author: Eyasir A
 * Author URI: https://factprobe.blogspot.com/
 */

// Register activation hook
register_activation_hook(__FILE__, 'io_activate_plugin');

// Function to run on plugin activation
function io_activate_plugin() {
    // Create database table for optimized images
    global $wpdb;
    $table_name = $wpdb->prefix . 'io_optimized_images';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id INT(11) NOT NULL AUTO_INCREMENT,
        image_id INT(11) NOT NULL,
        optimized_file VARCHAR(255) NOT NULL,
        width INT(11) NOT NULL,
        height INT(11) NOT NULL,
        filesize INT(11) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
// Register filter for adding attachment metadata
add_filter('wp_generate_attachment_metadata', 'io_optimize_attachment_metadata', 10, 2);

// Function to optimize attachment metadata
function io_optimize_attachment_metadata($metadata, $attachment_id) {
    // Get attachment file path
    $file_path = get_attached_file($attachment_id);

    // Optimize image
    $optimized_file_path = io_optimize_image($file_path);

    // Update metadata with optimized image data
    if ($optimized_file_path) {
        $optimized_file = str_replace(wp_basename($file_path), wp_basename($optimized_file_path), $file_path);
        $metadata['file'] = $optimized_file;
        $metadata['sizes']['full']['file'] = $optimized_file;
        list($width, $height) = getimagesize($optimized_file_path);
        $metadata['width'] = $width;
        $metadata['height'] = $height;
        $metadata['filesize'] = filesize($optimized_file_path);

        // Save optimized image data to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'io_optimized_images';
        $wpdb->replace($table_name, array(
            'image_id' => $attachment_id,
            'optimized_file' => $optimized_file,
            'width' => $width,
            'height' => $height,
            'filesize' => $metadata['filesize']
        ));
    }

    return $metadata;
}
// Register filter for editing attachment metadata
add_filter('wp_update_attachment_metadata', 'io_optimize_attachment_metadata', 10, 2);
// Register filter for deleting attachment files
add_filter('wp_delete_file', 'io_delete_optimized_image_file', 10, 2);

// Function to delete optimized image file
function io_delete_optimized_image_file($file, $attachment_id) {
    // Check if file is an optimized image
    global $wpdb;
    $table_name = $wpdb->prefix . 'io_optimized_images';
    $query = $wpdb->prepare("SELECT optimized_file FROM $table_name WHERE image_id = %d", $attachment_id);
    $optimized_file = $wpdb->get_var($query);
    if ($optimized_file && file_exists($optimized_file)) {
        // Delete optimized image file
        unlink($optimized_file);
        // Remove optimized image data from database
        $wpdb->delete($table_name, array('image_id' => $attachment_id));
    }

    return $file;
}
// Function to optimize image
function io_optimize_image($file_path) {
    // Get image editor
    $editor = wp_get_image_editor($file_path);

    if (!is_wp_error($editor)) {
        // Get original image data
        $original_size = filesize($file_path);
        $original_dimensions = $editor->get_size();
        $original_ratio = $original_dimensions['width'] / $original_dimensions['height'];

        // Define max image dimensions and quality
        $max_width = 1920;
        $max_height = 1080;
        $quality = 80;

        // Calculate new image dimensions
        $new_dimensions = array(
            'width' => $original_dimensions['width'],
            'height' => $original_dimensions['height']
        );
        if ($new_dimensions['width'] > $max_width) {
            $new_dimensions['width'] = $max_width;
            $new_dimensions['height'] = round($max_width / $original_ratio);
        }
        if ($new_dimensions['height'] > $max_height) {
            $new_dimensions['height'] = $max_height;
            $new_dimensions['width'] = round($max_height * $original_ratio);
        }

        // Resize and save optimized image
        $editor->resize($new_dimensions['width'], $new_dimensions['height'], true);
        $optimized_file_path = str_replace('.jpg', '-optimized.jpg', $file_path);
        $editor->set_quality($quality);
        $editor->save($optimized_file_path);

        // Check if optimized image is smaller than original
        $optimized_size = filesize($optimized_file_path);
        if ($optimized_size >= $original_size) {
            unlink($optimized_file_path);
            $optimized_file_path = '';
        }
    } else {
        $optimized_file_path = '';
    }

    return $optimized_file_path;
}
// Register admin menu
add_action('admin_menu', 'io_admin_menu');

// Function to add settings page to admin menu
function io_admin_menu() {
    add_options_page('Image Optimizer Settings', 'Image Optimizer', 'manage_options', 'image-optimizer', 'io_settings_page');
}
// Function to display settings page
function io_settings_page() {
    // Check if user has permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Process form data
    if (isset($_POST['io_submit'])) {
        // Update settings
        update_option('io_max_width', intval($_POST['io_max_width']));
        update_option('io_max_height', intval($_POST['io_max_height']));
        update_option('io_quality', intval($_POST['io_quality']));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // Display settings form
    $max_width = get_option('io_max_width', 1920);
    $max_height = get_option('io_max_height', 1080);
    $quality = get_option('io_quality', 80);
    ?>
    <div class="wrap">
        <h1>Image Optimizer Settings</h1>
        <form method="post">
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="io_max_width">Maximum Width:</label></th>
                        <td><input type="number" name="io_max_width" id="io_max_width" min="0" step="1" value="<?php echo $max_width; ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="io_max_height">Maximum Height:</label></th>
                        <td><input type="number" name="io_max_height" id="io_max_height" min="0" step="1" value="<?php echo $max_height; ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="io_quality">Image Quality:</label></th>
                        <td><input type="number" name="io_quality" id="io_quality" min="0" max="100" step="1" value="<?php echo $quality; ?>" required></td>
                    </tr>
                </tbody>
            </table>
            <p class="submit"><input type="submit" name="io_submit" id="io_submit" class="button button-primary" value="Save Changes"></p>
        </form>
    </div>
    <?php
}
// Register admin scripts and styles
add_action('admin_enqueue_scripts', 'io_admin_enqueue_scripts');

// Function to enqueue scripts and styles for admin pages
function io_admin_enqueue_scripts($hook) {
    if ($hook == 'settings_page_image-optimizer') {
        wp_enqueue_style('io-admin-css', plugins_url('image-optimizer/admin/admin.css'));
    }
}
// Register scripts and styles
add_action('wp_enqueue_scripts', 'io_enqueue_scripts');

// Function to enqueue scripts and styles for front-end
function io_enqueue_scripts() {
    wp_enqueue_script('io-lazyload', plugins_url('image-optimizer/lazyload.min.js'), array('jquery'), null, true);
}
// Hook image optimizer function to "wp_generate_attachment_metadata" filter
add_filter('wp_generate_attachment_metadata', 'io_optimize_attachment');
