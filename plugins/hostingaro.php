<?php
/*
Plugin Name: Hostingaro Wp
Description: Hostingaro Speed up your wordpress site 
Version: 1.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

define('NPC_VERSION', 0.2);
define('NBC_VERSION', 0.2);
// Do not access file directly!
if (!defined('WPINC')) {
    die;
}

if (!class_exists('Hostingaro_Browser_Cache')) {
    class Hostingaro_Browser_Cache
    {
        function __construct()
        {
            $this->hooks();
        }

        function hooks()
        {
            if ($this->is_enabled()) {
                add_filter('mod_rewrite_rules', array($this, 'htaccess_contents'));
            }
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'status_link'));
        }

        function htaccess_contents($rules)
        {
            $default_files = array(
                'image/jpg' => '5 minutes',
                'image/jpeg' => '5 minutes',
                'image/gif' => '5 minutes',
                'image/png' => '5 minutes',
                'text/css' => '5 minutes',
                'application/pdf' => '10 minutes',
                'text/javascript' => '5 minutes',
            );

            $file_types = wp_parse_args(get_option('hbc_filetype_expirations', array()), $default_files);

            $additions = "<IfModule mod_expires.c>\n\tExpiresActive On\n\t";
            foreach ($file_types as $file_type => $expires) {
                $additions .= 'ExpiresByType ' . $file_type . ' "access plus ' . $expires . '"' . "\n\t";
            }

            $additions .= "ExpiresByType image/x-icon \"access plus 30 minutes\"\n\tExpiresDefault \"access plus 3 minutes\"\n</IfModule>\n";
            return $additions . $rules;
        }

        function is_enabled()
        {
            $cache_settings = get_option('mm_cache_settings');
            if (isset($_GET['nsbc_toggle'])) {
                $valid_values = array('enabled', 'disabled');
                if (in_array($_GET['nsbc_toggle'], $valid_values)) {
                    $cache_settings['browser'] = $_GET['nsbc_toggle'];
                    update_option('mm_cache_settings', $cache_settings);
                    header('Location: ' . admin_url('plugins.php?plugin_status=mustuse'));
                }
            }
            if (isset($cache_settings['browser']) && 'disabled' == $cache_settings['browser']) {
                return false;
            } else {
                return true;
            }
        }

        function status_link($links)
        {
            if ($this->is_enabled()) {
                $links[] = '<a href="' . add_query_arg(array('nsbc_toggle' => 'disabled')) . '">Disable</a>';
            } else {
                $links[] = '<a href="' . add_query_arg(array('nsbc_toggle' => 'enabled')) . '">Enable</a>';
            }
            return $links;
        }
    }
}
if (!class_exists('Hostingaro_Page_Cache')) {
    class Hostingaro_Page_Cache
    {
        function __construct()
        {
            $this->hooks();
            $this->cache_dir = WP_CONTENT_DIR . '/hostingaro-page-cache';
            $this->cache_exempt = array('wp-admin', '.', 'checkout', 'cart', 'wp-json');
            if (!wp_next_scheduled('ns_purge')) {
                wp_schedule_event(time() + (HOUR_IN_SECONDS * 2), 'nsc_weekly', 'ns_purge');
            }
        }

        function hooks()
        {
            if ($this->is_enabled()) {
                add_action('init', array($this, 'start'));
                add_action('shutdown', array($this, 'finish'));

                add_filter('style_loader_src', array($this, 'remove_wp_ver_css_js'), 9999);
                add_filter('script_loader_src', array($this, 'remove_wp_ver_css_js'), 9999);

                add_filter('mod_rewrite_rules', array($this, 'htaccess_contents'));

                add_action('save_post', array($this, 'save_post'));
                add_action('edit_terms', array($this, 'edit_terms'), 10, 2);

                add_action('comment_post', array($this, 'comment'), 10, 2);

                add_action('updated_option', array($this, 'option_handler'), 10, 3);

                add_action('activated_plugin', array($this, 'purge_all'));
                add_action('deactivated_plugin', array($this, 'purge_all'));
                add_action('switch_theme', array($this, 'purge_all'));

                add_action('update_option_mm_coming_soon', array($this, 'purge_all'));

                add_action('nspc_purge', array($this, 'purge_all'));

                add_action('wp_update_nav_menu', array($this, 'purge_all'));

                add_action('admin_init', array($this, 'do_purge_all'));
            }

            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'status_link'));
        }

        function purge_cron($schedules)
        {
            $schedules['ns_weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display' => esc_html__('Weekly'),
            );
            return $schedules;
        }

        function option_handler($option, $old_value, $new_value)
        {
            if (false !== strpos($option, 'widget') && $old_value !== $new_value) {
                $this->purge_all();
            }
        }

        function comment($comment_id, $comment_approved)
        {
            $comment = get_comment($comment_id);
            if (property_exists($comment, 'comment_post_ID')) {
                $post_url = get_permalink($comment->comment_post_ID);
                $this->purge_single($post_url);
            }
        }

        function save_post($post_id)
        {
            $url = get_permalink($post_id);
            $this->purge_single($url);

            $taxonomies = get_post_taxonomies($post_id);
            foreach ($taxonomies as $taxonomy) {
                $terms = get_the_terms($post_id, $taxonomy);
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        $term_link = get_term_link($term);
                        $this->purge_single($term_link);
                    }
                }
            }

            if ($post_type_archive = get_post_type_archive_link(get_post_type($post_id))) {
                $this->purge_single($post_type_archive);
            }

            $post_date = (array)json_decode(get_the_date('{"\y":"Y","\m":"m","\d":"d"}', $post_id));
            if (!empty($post_date)) {
                $this->purge_all($this->uri_to_cache(get_year_link($post_date['y'])));
            }
        }

        function edit_terms($term_id, $taxonomy)
        {
            $url = get_term_link($term_id);
            $this->purge_single($url);
        }

        function write($page)
        {
            $base = parse_url(trailingslashit(get_option('home')), PHP_URL_PATH);

            if ($this->is_cachable() && false === strpos($page, 'nonce') && !empty($page)) {
                $this->path = WP_CONTENT_DIR . '/hostingaro-page-cache' . str_replace(get_option('home'), '',
                        esc_url($_SERVER['REQUEST_URI']));
                $this->path = str_replace('/hostingaro-page-cache' . $base, '/hostingaro-page-cache/', $this->path);
                $this->path = str_replace('//', '/', $this->path);

                if (file_exists($this->path . '_index.html') && filemtime($this->path . '_index.html') > time() - HOUR_IN_SECONDS) {
                    return $page;
                }

                if (!is_dir($this->path)) {
                    mkdir($this->path, 0755, true);
                }

                if (false !== strpos($page, '</body>')) {
                    $page = substr_replace($page, "\n<!--Generated by Neoistone Page Cache-->\n",
                        strpos($page, '</body>'), 0);
                }

                file_put_contents($this->path . '_index.html', str_replace(array('http://', 'https://'), '//', $page),
                    LOCK_EX);
            } else {
                $nocache = get_transient('ns_nocache_pages', array());
                $nocache[] = $_SERVER['REQUEST_URI'];
                delete_transient('ns_nocache_pages');
                set_transient('ns_nocache_pages', $nocache, DAY_IN_SECONDS);
            }
            return $page;
        }

        function purge_all($dir = null)
        {
            if (is_null($dir) || 'true' == $dir) {
                $dir = WP_CONTENT_DIR . '/hostingaro-page-cache';
            }
            if (!is_dir(WP_CONTENT_URL . '/hostingaro-page-cache')) {
                mkdir(WP_CONTENT_URL . '/hostingaro-page-cache');
            }
            $dir = str_replace('_index.html', '', $dir);
            if (is_dir($dir)) {
                $files = scandir($dir);
                if (is_array($files)) {
                    $files = array_diff($files, array('.', '..'));
                }

                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_dir($dir . '/' . $file)) {
                            $this->purge_all($dir . '/' . $file);
                        } else {
                            unlink($dir . '/' . $file);
                        }
                    }
                    rmdir($dir);
                }
            }
        }

        function purge_single($uri)
        {
            $cache_file = $this->uri_to_cache($uri);
            if (file_exists($cache_file)) {
                unlink($cache_file);
            }
            if (file_exists($this->cache_dir . '/_index.html')) {
                unlink($this->cache_dir . '/_index.html');
            }
        }

        function minify($content)
        {
            $content = str_replace("\r", '', $content);
            $content = str_replace("\n", '', $content);
            $content = str_replace("\t", '', $content);
            $content = str_replace('  ', ' ', $content);
            $content = trim($content);
            return $content;
        }

        function uri_to_cache($uri)
        {
            $path = str_replace(get_site_url(), '', $uri);
            return $this->cache_dir . $path . '_index.html';
        }

        function is_cachable()
        {

            $return = true;

            $nocache = get_transient('ns_nocache_pages', array());

            if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE == true) {
                $return = false;
            }

            if (is_array($nocache) && in_array($_SERVER['REQUEST_URI'], $nocache)) {
                $return = false;
            }

            if (is_404()) {
                $return = false;
            }

            if (is_admin()) {
                $return = false;
            }

            if (!get_option('permalink_structure')) {
                $return = false;
            }

            if (is_user_logged_in()) {
                $return = false;
            }

            if (isset($_GET) && !empty($_GET)) {
                $return = false;
            }

            if (isset($_POST) && !empty($_POST)) {
                $return = false;
            }

            if (is_feed()) {
                $return = false;
            }

            if (empty($_SERVER['REQUEST_URI'])) {
                $return = false;
            } else {
                $cache_exempt = apply_filters('ns_exempt_uri_contains', $this->cache_exempt);
                foreach ($cache_exempt as $exclude) {
                    if (false !== strpos($_SERVER['REQUEST_URI'], $exclude)) {
                        $return = false;
                    }
                }
            }

            return apply_filters('ns_is_cachable', $return);
        }

        function start()
        {
            if ($this->is_cachable()) {
                ob_start(array($this, 'write'));
            }
        }

        function finish()
        {
            if ($this->is_cachable()) {
                if (ob_get_contents()) {
                    ob_end_clean();
                }
            }
        }

        function remove_wp_ver_css_js($src)
        {
            if (strpos($src, 'ver=')) {
                $src = remove_query_arg('ver', $src);
            }
            return $src;
        }

        function htaccess_contents($rules)
        {
            $base = parse_url(trailingslashit(get_option('home')), PHP_URL_PATH);
            $cache_url = $base . str_replace(get_option('home'), '', WP_CONTENT_URL . '/hostingaro-page-cache');
            $cache_url = str_replace('//', '/', $cache_url);
            $additions = '<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase ' . $base . '
    RewriteRule ^' . $cache_url . '/ - [L]
    RewriteCond %{REQUEST_METHOD} !POST
    RewriteCond %{QUERY_STRING} !.*=.*
    RewriteCond %{HTTP_COOKIE} !(wordpress_test_cookie|comment_author|wp\-postpass|wordpress_logged_in|wptouch_switch_toggle|wp_woocommerce_session_) [NC]
    RewriteCond %{DOCUMENT_ROOT}' . $cache_url . '/$1/_index.html -f
    RewriteRule ^(.*)$ ' . $cache_url . '/$1/_index.html [L]
</IfModule>' . "\n";
            return $additions . $rules;
        }

        function is_enabled()
        {

            $active_plugins = get_option('active_plugins');
            if (!empty($active_plugins) && count($active_plugins) > 0) {
                $plugins = implode(' ', $active_plugins);
                if (strpos($plugins, 'cach') || strpos($plugins, 'wp-rocket')) {
                    return false;
                }
            }

            $cache_settings = get_option('mm_cache_settings');
            if (isset($_GET['ns_toggle'])) {
                $valid_values = array('enabled', 'disabled');
                if (in_array($_GET['ns_toggle'], $valid_values)) {
                    $cache_settings['page'] = $_GET['ns_toggle'];
                    update_option('mm_cache_settings', $cache_settings);
                    header('Location: ' . admin_url('plugins.php?plugin_status=mustuse'));
                }
            }
            if (isset($cache_settings['page']) && 'disabled' == $cache_settings['page']) {
                return false;
            } else {
                return true;
            }
        }

        function status_link($links)
        {
            if ($this->is_enabled()) {
                $links[] = '<a href="' . add_query_arg(array('nspc_toggle' => 'disabled')) . '">Disable</a>';
                $links[] = '<a href="' . add_query_arg(array('nspc_purge_all' => 'true')) . '">Purge Cache</a>';
            } else {
                $links[] = '<a href="' . add_query_arg(array('nspc_toggle' => 'enabled')) . '">Enable</a>';
            }
            return $links;
        }

        function do_purge_all()
        {
            if (isset($_GET['nspc_purge_all'])) {
                $this->purge_all();
                header('Location: ' . admin_url('plugins.php?plugin_status=mustuse'));
            }
        }
    }
}

function hostingaro_free_gifts(){
   add_filter( 'generate_copyright', function(){
	      $site_url  = get_bloginfo('url');
          $site_name = get_bloginfo('name');
	      return '<div class="creds"><p>Copyright 漏 ' . date('Y') . ' 路 <a href="'.$site_url.'">'.$site_name.'</a> - All Rights Reserved</p></div>';
   });
   add_action( 'admin_footer_text', function(){
     return "Thank using <a href='https://hostingaro.com/?ref=wp-dashboard-footer'>Hostingaro Hosting</a> Power by Wordpress";
   });
}
function register_management_page()
{
    add_management_page("Change URL", "Change URL", "manage_options", basename(__FILE__), "plugin_management_page");
}

function plugin_management_page()
{
    global $wpdb;

    $siteUrl = $wpdb->get_var("SELECT option_value FROM wp_options where option_name='siteurl'");
    $updated = false;
    if (isset($_POST['newurl']) && $_POST['newurl'] != '') {
        $newUrl = $_POST['newurl'];

        $wpdb->query($wpdb->prepare('UPDATE wp_posts SET post_content = replace(post_content, %s, %s)', $siteUrl, $newUrl));
        $wpdb->query($wpdb->prepare('UPDATE wp_posts SET post_excerpt = replace(post_excerpt, %s, %s)', $siteUrl, $newUrl));
        $wpdb->query($wpdb->prepare("UPDATE wp_posts SET guid = replace(guid, %s, %s)", $siteUrl, $newUrl));
        $wpdb->query($wpdb->prepare("UPDATE wp_links SET link_url = replace(link_url, %s, %s)", $siteUrl, $newUrl));
        $wpdb->query($wpdb->prepare('UPDATE wp_postmeta SET meta_value = replace(meta_value, %s, %s)', $siteUrl, $newUrl));
        $wpdb->query($wpdb->prepare('UPDATE wp_options SET option_value = replace(option_value, %s, %s)', $siteUrl, $newUrl));

        $siteUrl = $wpdb->get_var("SELECT option_value FROM wp_options where option_name='siteurl'");
        $updated = true;
    }
    ?>

    <div class="wrap">
        <?php if ($updated) : ?>
            <div id="message" class="updated notice is-dismissible"><p>Url was changed.</p>
                <button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>
            </div>
        <?php else: ?>
            <div style="color:red; font-weight:700;">
                WE RECOMMEND TO BACKUP YOUR WORDPRESS DATABASE BEFORE URL CHANGE !
            </div>
        <?php endif; ?>


        <h2>Change URL</h2>
        <form method="post" action="tools.php?page=<?php echo basename(__FILE__); ?>">
            <table style="width:100%">
                <tr>
                    <td style="width:100px;">Old url</td>
                    <td><input type="text" name="oldurl" style="width:50%;" value="<?php echo $siteUrl ?>" disabled></td>
                </tr>
                <tr>
                    <td>New url</td>
                    <td><input style="width:50%;" type="text" name="newurl"></td>
                </tr>
                <tr>
                    <td colspan="2"><br><input class="button button-primary" type="submit" value="Change URL"></td>
                </tr>
            </table>
        </form>
    </div>
<?php }
  add_action('admin_menu', 'register_management_page'); 
  (new Hostingaro_Page_Cache);
  (new Hostingaro_Browser_Cache);
  hostingaro_free_gifts();
?>
