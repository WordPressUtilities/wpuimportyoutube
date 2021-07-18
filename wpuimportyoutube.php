<?php

/*
Plugin Name: WPU Import Youtube
Plugin URI: https://github.com/WordPressUtilities/wpuimportyoutube
Version: 0.4.0
Description: Import latest youtube videos.
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUImportYoutube {

    private $plugin_version = '0.4.0';

    private $users = array();
    private $post_type = '';
    private $is_importing = false;
    private $option_id = 'wpuimportyoutube_options';
    private $cronhook = 'wpuimportyoutube__cron_hook';

    public function __construct() {
        $this->set_options();
        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_action('init', array(&$this,
            'init'
        ));
        add_action($this->cronhook, array(&$this,
            'import'
        ));

        // Cron
        include 'inc/WPUBaseCron/WPUBaseCron.php';
        $this->basecron = new \wpuimportyoutube\WPUBaseCron();
    }

    public function set_options() {
        load_plugin_textdomain('wpuimportyoutube', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        $this->post_type = apply_filters('wpuimportyoutube_posttypehook', 'youtube_videos');
        $this->post_type_info = apply_filters('wpuimportyoutube_posttypeinfo', array(
            'public' => true,
            'name' => __('Youtube Video', 'wpuimportyoutube'),
            'label' => __('Youtube Video', 'wpuimportyoutube'),
            'plural' => __('Youtube Videos', 'wpuimportyoutube'),
            'female' => 1,
            'menu_icon' => 'dashicons-format-video'
        ));
        /* Taxo author */
        $this->taxonomy_author = apply_filters('wpuimportyoutube_taxonomy_author', 'youtube_authors');
        $this->taxonomy_author_info = apply_filters('wpuimportyoutube_taxonomy_author_info', array(
            'label' => __('Authors', 'wpuimportyoutube'),
            'plural' => __('Authors', 'wpuimportyoutube'),
            'name' => __('Author', 'wpuimportyoutube'),
            'hierarchical' => true,
            'post_type' => $this->post_type
        ));
        $this->options = array(
            'plugin_publicname' => 'Youtube Import',
            'plugin_name' => 'Youtube Import',
            'plugin_userlevel' => 'manage_options',
            'plugin_id' => 'wpuimportyoutube',
            'plugin_pageslug' => 'wpuimportyoutube'
        );
        $this->settings_values = get_option($this->option_id);
        $this->options['admin_url'] = admin_url('edit.php?post_type=' . $this->post_type . '&page=' . $this->options['plugin_id']);
        $this->import_draft = (isset($this->settings_values['import_draft']) && $this->settings_values['import_draft'] == '1');
        $this->users = $this->get_users_ids();
        $this->channels = $this->get_channels_ids();
    }

    public function plugins_loaded() {
        if (!is_admin()) {
            return;
        }

        // Admin page
        add_action('admin_menu', array(&$this,
            'admin_menu'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), array(&$this,
            'add_settings_link'
        ));
        add_action('admin_post_wpuimportyoutube_postaction', array(&$this,
            'postAction'
        ));
    }

    public function init() {

        /* Post types */
        if (class_exists('wputh_add_post_types_taxonomies')) {
            add_filter('wputh_get_posttypes', array(&$this, 'set_posttypes'));
            add_filter('wputh_get_taxonomies', array(&$this, 'set_taxonomies'));
        } else {
            /* Post type for videos */
            register_post_type(
                $this->post_type,
                $this->post_type_info
            );
            /* Taxonomy for authors */
            register_taxonomy(
                $this->taxonomy_author,
                $this->post_type,
                $this->taxonomy_author_info
            );
        }

        /* Messages */
        if (is_admin()) {
            include dirname(__FILE__) . '/inc/WPUBaseMessages/WPUBaseMessages.php';
            $this->messages = new \wpuimportyoutube\WPUBaseMessages($this->options['plugin_id']);
        }

        include dirname(__FILE__) . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpuimportyoutube\WPUBaseUpdate(
            'WordPressUtilities',
            'wpuimportyoutube',
            $this->plugin_version);

        /* Settings */
        $this->settings_details = array(
            'plugin_id' => 'wpuimportyoutube',
            'option_id' => $this->option_id,
            'sections' => array(
                'import' => array(
                    'name' => __('Import Settings', 'wpuimportyoutube')
                )
            )
        );
        $this->settings = array(
            'import_draft' => array(
                'section' => 'import',
                'type' => 'checkbox',
                'label_check' => __('Posts are created with a draft status.', 'wpuimportyoutube'),
                'label' => __('Import as draft', 'wpuimportyoutube')
            ),
            'account_ids' => array(
                'section' => 'import',
                'type' => 'textarea',
                'help' => __('One Youtube account ID by line (ex : pewdiepie, caseyneistat)', 'wpuimportyoutube'),
                'label' => __('Account IDs', 'wpuimportyoutube')
            ),
            'channel_ids' => array(
                'section' => 'import',
                'type' => 'textarea',
                'help' => __('One Youtube channel ID by line (ex : channel/UCtinbF-Q-fVthA0qrFQTgXQ)', 'wpuimportyoutube'),
                'label' => __('Channel IDs', 'wpuimportyoutube')
            )
        );

        if (is_admin()) {
            // Settings
            include dirname(__FILE__) . '/inc/WPUBaseSettings/WPUBaseSettings.php';
            new \wpuimportyoutube\WPUBaseSettings($this->settings_details, $this->settings);
        }
    }

    public function set_posttypes($post_types) {
        $post_types[$this->post_type] = $this->post_type_info;
        return $post_types;
    }

    public function set_taxonomies($taxonomies) {
        $taxonomies[$this->taxonomy_author] = $this->taxonomy_author_info;
        return $taxonomies;
    }

    /* ----------------------------------------------------------
      Admin config
    ---------------------------------------------------------- */

    /* Admin page */

    public function admin_menu() {
        add_submenu_page('edit.php?post_type=' . $this->post_type, $this->options['plugin_name'] . ' - ' . __('Settings'), __('Import Settings', 'wpuimportyoutube'), $this->options['plugin_userlevel'], $this->options['plugin_pageslug'], array(&$this,
            'admin_settings'
        ), 110);
    }

    /* Settings link */

    public function add_settings_link($links) {
        $settings_link = '<a href="' . $this->options['admin_url'] . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function admin_page__prevnext() {
        $str = array();
        $prev = $this->basecron->get_previous_exec();
        $next = $this->basecron->get_next_scheduled();
        if (is_array($prev)) {
            $str[] = sprintf(__('Previous automated import %s’%s’’ ago', 'wpuimportyoutube'), $prev['min'], $prev['sec']);
        }
        if (is_array($next)) {
            $str[] = sprintf(__('Next automated import in %s’%s’’', 'wpuimportyoutube'), $next['min'], $next['sec']);
        }
        if (!empty($str)) {
            return '<p>' . implode('<br />', $str) . '</p>';
        }
    }

    public function admin_settings() {

        echo '<div class="wrap"><h1>' . get_admin_page_title() . '</h1>';

        settings_errors($this->settings_details['option_id']);
        echo '<h2>' . __('Tools') . '</h2>';
        echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
        echo '<input type="hidden" name="action" value="wpuimportyoutube_postaction">';
        echo $this->admin_page__prevnext();
        echo '<p class="submit">';
        if (!$this->is_importing) {
            submit_button(__('Import now', 'wpuimportyoutube'), 'primary', 'import_now', false);
            echo ' ';
        }
        echo '</p>';
        echo '</form>';
        echo '<hr />';

        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpuimportyoutube'));
        echo '</form>';

        echo '</div>';
    }

    public function postAction() {
        if (isset($_POST['import_now'])) {
            $nb_imports = $this->import();
            if ($nb_imports === false) {
                $this->messages->set_message('already_import', __('An import is already running', 'wpuimportyoutube'), 'error');
            } else {
                if ($nb_imports > 0) {
                    $this->messages->set_message('imported_nb', sprintf(__('Imported videos : %s', 'wpuimportyoutube'), $nb_imports),'updated');
                } else {
                    $this->messages->set_message('imported_0', __('No new imports', 'wpuimportyoutube'), 'updated');
                }
            }
        }
        wp_safe_redirect(wp_get_referer());
        die();
    }

    /* ----------------------------------------------------------
      Help
    ---------------------------------------------------------- */

    public function get_users_ids() {
        $users = array();

        if (isset($this->settings_values['account_ids'])) {
            $_account_ids = preg_replace('/\W+/', "#", $this->settings_values['account_ids']);
            $_account_ids = explode("#", $_account_ids);
            foreach ($_account_ids as $id) {
                if (!empty($id)) {
                    $users[] = $id;
                }
            }
        }
        return $users;
    }

    public function get_channels_ids() {
        $channels = array();

        if (isset($this->settings_values['channel_ids'])) {
            $_channel_ids = preg_replace('/([^A-Za-z0-9-_]+)/', "#", $this->settings_values['channel_ids']);
            $_channel_ids = explode("#", $_channel_ids);
            foreach ($_channel_ids as $id) {
                if (!empty($id)) {
                    $channels[] = $id;
                }
            }
        }
        return $channels;
    }

    /* ----------------------------------------------------------
      Import
    ---------------------------------------------------------- */

    public function import() {
        $nb_imports = 0;
        $this->is_importing = true;
        foreach ($this->users as $user) {
            $nb_imports += $this->import_videos_to_posts($this->get_videos_for_user($user));
        }
        foreach ($this->channels as $channel) {
            $nb_imports += $this->import_videos_to_posts($this->get_videos_for_channel($channel));
        }
        $this->is_importing = false;
        return $nb_imports;
    }

    public function import_videos_to_posts($videos) {
        $nb_imports = 0;
        $ids = $this->get_last_imported_videos_ids();
        if (!is_array($videos)) {
            return 0;
        }
        foreach ($videos as $id => $video) {
            if (in_array($id, $ids)) {
                continue;
            }
            if (is_numeric($this->create_post_from_video($video))) {
                $nb_imports += 1;
            }
        }
        return $nb_imports;
    }

    public function get_last_imported_videos_ids() {
        global $wpdb;
        /* Import ids from SQL */
        return $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'wpuimportyoutube_id' ORDER BY meta_id DESC LIMIT 0,2000");
    }

    public function get_videos_for_user($user = false) {
        return $this->get_videos_from_url('https://www.youtube.com/feeds/videos.xml?user=' . $user, 'user=' . $user);
    }

    public function get_videos_for_channel($channel = false) {
        return $this->get_videos_from_url('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel, 'channel_id=' . $channel);
    }

    public function get_videos_from_url($_url = false, $_urlinfo = false) {
        $videos = array();
        if (!$_url) {
            return $videos;
        }
        /* Import from RSS */
        $_request = wp_remote_get($_url);
        if (is_wp_error($_request)) {
            return array();
        }
        /* Extract datas */
        $_body = wp_remote_retrieve_body($_request);
        $datas = simplexml_load_string($_body);
        if (!is_object($datas)) {
            if ($_urlinfo) {
                $this->messages->set_message('import_failed', sprintf(__('WPU Import Youtube : Import from %s failed', 'wpuimportyoutube'), $_urlinfo),'error');
            }

            return array();
        }
        $namespaces = $datas->getNamespaces(true);
        $videos = array();
        foreach ($datas->entry as $item) {
            $media_group = $item->children($namespaces['media'])->group->children($namespaces['media']);
            $yt_id = str_replace('yt:video:', '', (string) $item->id);
            $videos[$yt_id] = array(
                'id' => $yt_id,
                'yt_id' => trim((string) $item->id),
                'title' => trim((string) $item->title),
                'description' => trim((string) $media_group->description),
                'time' => strtotime($item->published),
                'thumbnail' => trim((string) $media_group->thumbnail->attributes()->url),
                'video' => trim((string) $media_group->content->attributes()->url),
                'author' => trim((string) $item->author->name)
            );
        }
        return $videos;
    }

    public function create_post_from_video($video) {

        /* Set taxonomy author */
        $taxo_author = false;
        if (!empty($video['author'])) {
            $taxo_author = $this->get_or_create_post_taxonomy($this->taxonomy_author, $video['author'], strtolower($video['author']));
        }

        /* Post details */
        $video_post = array(
            'post_title' => $video['title'],
            'post_content' => $video['description'] . "\n\n" . $video['video'],
            'post_date' => date('Y-m-d H:i:s', $video['time']),
            'post_status' => $this->import_draft ? 'draft' : 'published',
            'post_author' => 1,
            'post_type' => $this->post_type
        );

        // Insert the post into the database
        $post_id = wp_insert_post($video_post);

        // Thumbnail
        $this->add_thumbnail_from_video($post_id, $video);

        // Taxos
        if ($taxo_author) {
            wp_set_post_terms($post_id, array($taxo_author->term_id), $this->taxonomy_author);
        }

        // Metas
        update_post_meta($post_id, 'wpuimportyoutube_id', $video['id']);
        update_post_meta($post_id, 'wpuimportyoutube_video', $video['video']);

        return $post_id;
    }

    public function get_or_create_post_taxonomy($taxonomy, $name, $term_slug) {
        /* Create it if null */
        $tmp_taxo = get_term_by('slug', $term_slug, $taxonomy);
        if (!$tmp_taxo) {
            $tmp_term = wp_insert_term($name, $taxonomy, array(
                'slug' => $term_slug
            ));
            $tmp_taxo = get_term_by('id', $tmp_term['term_id'], $taxonomy);
        }
        return $tmp_taxo;
    }

    public function add_thumbnail_from_video($post_id, $video) {
        global $wpdb;

        /* Test biggest thumbnail */
        $biggest_thumbnail = $this->get_biggest_thumbnail_available($video['id']);
        if ($biggest_thumbnail) {
            $video['thumbnail'] = $biggest_thumbnail;
        }

        // Upload image
        $src = media_sideload_image($video['thumbnail'], $post_id, $video['title'], 'src');

        // Extract attachment id
        $att_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid='%s'",
            $src
        ));

        set_post_thumbnail($post_id, $att_id);
    }

    public function get_biggest_thumbnail_available($video_id) {
        $resolution = array(
            'maxresdefault',
            'sddefault'
        );
        foreach ($resolution as $k) {
            $big_thumbnail = 'https://img.youtube.com/vi/' . $video_id . '/' . $k . '.jpg';
            $big_thumbnail_resp_code = wp_remote_retrieve_response_code(wp_remote_head($big_thumbnail));
            if ($big_thumbnail_resp_code == 200) {
                return $big_thumbnail;
            }
        }
        return false;
    }

    /* ----------------------------------------------------------
      Install
    ---------------------------------------------------------- */

    public function install() {
        flush_rewrite_rules();
        $this->basecron->install();
    }

    public function deactivation() {
        flush_rewrite_rules();
        $this->basecron->uninstall();
    }

    public function uninstall() {
        delete_option($this->option_id);
        delete_post_meta_by_key('wpuimportyoutube_id');
        delete_post_meta_by_key('wpuimportyoutube_video');
        flush_rewrite_rules();
        $this->basecron->uninstall();
    }

}

$WPUImportYoutube = new WPUImportYoutube();

register_activation_hook(__FILE__, array(&$WPUImportYoutube,
    'install'
));
register_deactivation_hook(__FILE__, array(&$WPUImportYoutube,
    'deactivation'
));
