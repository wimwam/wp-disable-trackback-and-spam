<?php
/*
Plugin Name: Valkemedia Disable trackback & spam
Plugin URI: https://valkemedia.nl/
Description: Disable comments, trackback & spam, remove wp_generator tag from wp theme
Version: 1.0
Author: Wiebe-Jan Valkema
Author URI: https://valkemedia.nl/
Gemaakt door: WJ Valkema
License: GPLv2 or later
Text Domain: valkemedia
*/



if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

class valkemedia_disable_trackback_and_spam
{
    /**
     * siteModule constructor.
     */
    public function __construct()
    {
        remove_action('wp_head', 'wp_generator');
        /**
         * comments blok uit menu halen
         */
        add_action('admin_init', function () {
            // Redirect any user trying to access comments page
            global $pagenow;

            if ($pagenow === 'edit-comments.php') {
                wp_redirect(admin_url());
                exit;
            }

            // Remove comments metabox from dashboard
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

            // Disable support for comments and trackbacks in post types
            foreach (get_post_types() as $post_type) {
                if (post_type_supports($post_type, 'comments')) {
                    remove_post_type_support($post_type, 'comments');
                    remove_post_type_support($post_type, 'trackbacks');
                }
            }
        });

        // Close comments on the front-end
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

        // Hide existing comments
        add_filter('comments_array', '__return_empty_array', 10, 2);

        // Remove comments page in menu
        add_action('admin_menu', function () {
            remove_menu_page('edit-comments.php');
        });

        // Remove comments links from admin bar
        add_action('init', function () {
            if (is_admin_bar_showing()) {
                remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
            }
        });
    }
}

$valkemedia = new valkemedia_disable_trackback_and_spam();