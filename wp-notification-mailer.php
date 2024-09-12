<?php
/*
Plugin Name: WP notification mailer
Description: Adds a notification button next to posts/pages in the admin edit pages to send e-mails to all users to notify them about changes or additions.
Version: 1.0.0
Author: Glenn Hofman
Homepage: https://www.glennhofman.nl
download_link: https://github.com/g-hofman/wp-notification-mailer/blob/main/wp-notification-mailer.zip
Text Domain: wp-notification-mailer
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class CategoryUpdateEmailNotifications {
    
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_menu', [$this, 'create_options_page']);
        add_filter('post_row_actions', [$this, 'add_send_notification_link'], 10, 2); // Hook to add "Send Notification" link
        add_action('admin_init', [$this, 'process_send_notification']);
        // Display admin notice when the notification is sent
        add_action('admin_notices', function() {
            if (isset($_GET['notification_sent'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Notification email sent successfully!</p></div>';
            }
        });
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_script('notification-comment-script', plugin_dir_url(__FILE__) . 'notification-comment.js', ['jquery'], null, true);
    }

    // Add the "Send Notification" link next to the "Edit" link
    public function add_send_notification_link($actions, $post) {
        // Add the link for published posts and pages
        if ($post->post_status == 'publish' && in_array($post->post_type, ['post', 'page'])) {
            $url = add_query_arg([
                'action' => 'send_notification',
                'post_id' => $post->ID,
                '_wpnonce' => wp_create_nonce('send_notification_' . $post->ID)
            ], admin_url('edit.php'));

            $actions['send_notification'] = '<a href="' . esc_url($url) . '">Send Notification</a>';
        }
        return $actions;
    }

    // Process the "Send Notification" action
    public function process_send_notification() {
        if (isset($_GET['action']) && $_GET['action'] === 'send_notification' && isset($_GET['post_id'])) {
            $post_id = intval($_GET['post_id']);
            $nonce = $_GET['_wpnonce'];

            // Verify nonce for security
            if (!wp_verify_nonce($nonce, 'send_notification_' . $post_id)) {
                wp_die('Security check failed');
            }

            // Get the comment
            $comment = isset($_GET['comment']) ? sanitize_text_field(urldecode($_GET['comment'])) : '';

            if (empty($comment)) {
                wp_die('Comment cannot be empty.');
            }

            // Get the post
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                // Send the email with the comment
                $this->send_email_notification($post_id, $post, true, $comment);

                // Add the comment to the backlog
                $this->add_comment_to_backlog($post_id, $comment);

                wp_redirect(admin_url('edit.php?post_type=' . $post->post_type . '&notification_sent=1'));
                exit;
            }
        }
    }

    // Create options page
    public function create_options_page() {
        add_options_page('Category Email Notifications', 'Category Email Notifications', 'manage_options', 'category-email-notifications', [$this, 'options_page_html']);
    }

    // HTML for options page
    public function options_page_html() {
        if (isset($_POST['save_settings'])) {
            // Sanitize and save the email template
            $email_template = wp_kses_post($_POST['email_template']);
            update_option('email_template', $email_template);

            // Save other settings
            update_option('selected_categories', $_POST['categories']);
            update_option('test_email_enabled', isset($_POST['test_email_enabled']) ? 1 : 0);
            update_option('test_email_address', sanitize_email($_POST['test_email_address']));
            update_option('email_notifications_enabled', isset($_POST['email_notifications_enabled']) ? 1 : 0);
        }

        // Get saved options
        $email_template = get_option('email_template', '');
        $email_template = wp_unslash($email_template);
        $selected_categories = get_option('selected_categories', []);
        $test_email_enabled = get_option('test_email_enabled', 0);
        $test_email_address = get_option('test_email_address', '');
        $email_notifications_enabled = get_option('email_notifications_enabled', 0);
        $categories = get_categories(); // Fetch all post categories

        // Admin page HTML
        echo '<div class="wrap">';
        echo '<h1>Category Email Notifications</h1>';
        echo '<form method="post">';
        // Enable/Disable Email Notifications Checkbox
        $checked = $email_notifications_enabled ? 'checked' : '';
        echo '<h2>Email Notifications</h2>';
        echo '<input type="checkbox" name="email_notifications_enabled" '.$checked.'> Enable Email Notifications<br>';

        echo '<label for="email_template">Email Template:</label><br>';
        echo '<textarea name="email_template" rows="10" cols="50">'.esc_textarea($email_template).'</textarea><br>';
        echo '<p>Use the following template tags: {{username}}, {{post_url}}, {{post_title}}</p>';

        echo '<h2>Select Categories for Notifications</h2>';
        foreach ($categories as $category) {
            $checked = in_array($category->term_id, $selected_categories) ? 'checked' : '';
            echo '<input type="checkbox" name="categories[]" value="'.$category->term_id.'" '.$checked.'> '.$category->name.'<br>';
        }

        echo '<h2>Testing</h2>';
        $checked = $test_email_enabled ? 'checked' : '';
        echo '<input type="checkbox" name="test_email_enabled" '.$checked.'> Send to test email only<br>';
        echo '<label for="test_email_address">Test Email Address:</label><br>';
        echo '<input type="email" name="test_email_address" value="'.esc_html($test_email_address).'"><br>';

        echo '<input type="submit" name="save_settings" value="Save Settings">';
        echo '</form>';
        echo '</div>';

        $backlog = get_option('notification_backlog', []);
        // Display backlog
        echo '<h2>Notification Backlog</h2>';
        echo '<span style="display: block; height: 150px; overflow-y: scroll; border: 1px solid #ccc;">';
        if ($backlog) {
            foreach ($backlog as $entry) {
                echo '<p><strong>' . esc_html($entry['timestamp']) . '</strong>: ' . esc_html($entry['title']) . ' - ' . esc_html($entry['comment']) . '</p>';
            }
        } else {
            echo '<p>No notifications sent yet.</p>';
        }
        echo '</span>';
    }

    public function send_email_notification($post_id, $post, $update, $comment = '') {
        if ($post->post_status !== 'publish') return;

        // Check if email notifications are enabled
        $email_notifications_enabled = get_option('email_notifications_enabled', 0);
        if (!$email_notifications_enabled) return;

        $selected_categories = get_option('selected_categories', []);
        $categories = wp_get_post_categories($post_id);

        // Check if post belongs to any selected categories
        if (!array_intersect($selected_categories, $categories)) return;

        // Get email template and replace template tags
        $email_template = get_option('email_template', '');
        $email_template = str_replace('{{post_url}}', get_permalink($post_id), $email_template);
        $email_template = str_replace('{{post_title}}', get_the_title($post_id), $email_template);
        $email_template = str_replace('{{comments}}', esc_html($comment), $email_template);

        // Set content type to HTML
        add_filter('wp_mail_content_type', function() {
            return 'text/html';
        });

        // Check if test email is enabled
        if (get_option('test_email_enabled')) {
            $test_email_address = get_option('test_email_address', '');
            if (!empty($test_email_address)) {
                wp_mail($test_email_address, 'Post Updated: ' . get_the_title($post_id), $email_template);
            }
        } else {
            // Send email to all users
            $users = get_users();
            foreach ($users as $user) {
                $user_email = $user->user_email;
                $email_content = str_replace('{{username}}', $user->display_name, $email_template);
                wp_mail($user_email, 'Post Updated: ' . get_the_title($post_id), $email_content);
            }
        }

        // Reset content type to plain text
        remove_filter('wp_mail_content_type', function() {
            return 'text/html';
        });
    }

    public function add_comment_to_backlog($post_id, $comment) {
        $backlog = get_option('notification_backlog', []);
        $backlog[] = [
            'timestamp' => current_time('mysql'),
            'title' => get_the_title($post_id),
            'comment' => $comment
        ];
        update_option('notification_backlog', $backlog);
    }
}

new CategoryUpdateEmailNotifications();

// Plugin updater
class PluginUpdater {
    private $current_version;
    private $remote_url;
    private $plugin_slug;

    public function __construct($current_version, $remote_url, $plugin_slug) {
        $this->current_version = $current_version;
        $this->remote_url = $remote_url;
        $this->plugin_slug = $plugin_slug;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    // Check for plugin updates
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get the remote plugin information
        $remote_info = $this->get_remote_plugin_info();

        // If the remote version is greater than the current version, update
        if (version_compare($this->current_version, $remote_info->version, '<')) {
            $plugin_slug_with_path = $this->plugin_slug . '/' . $this->plugin_slug . '.php';

            $transient->response[$plugin_slug_with_path] = (object) [
                'new_version' => $remote_info->version,
                'slug'        => $this->plugin_slug,
                'package'     => $remote_info->download_url,
            ];
        }

        return $transient;
    }

    // Get plugin information from the remote server
    private function get_remote_plugin_info() {
        $remote_info = wp_remote_get($this->remote_url);

        if (!is_wp_error($remote_info) && wp_remote_retrieve_response_code($remote_info) == 200) {
            return json_decode(wp_remote_retrieve_body($remote_info));
        }

        return false;
    }

    // Provide plugin details to the WordPress plugin page
    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
            return false;
        }

        $remote_info = $this->get_remote_plugin_info();
        if (!$remote_info) {
            return $res;
        }

        $res = (object) [
            'name'          => $remote_info->name,
            'slug'          => $this->plugin_slug,
            'version'       => $remote_info->version,
            'author'        => $remote_info->author,
            'homepage'      => $remote_info->homepage,
            'download_link' => $remote_info->download_url,
            'sections'      => [
                'description'  => $remote_info->description,
            ],
        ];

        return $res;
    }
}

// Initialize the updater
$plugin_updater = new PluginUpdater('1.0.0', 'https://github.com/g-hofman/wp-notification-mailer/blob/main/plugin-info.json', 'wp-notification-mailer');
