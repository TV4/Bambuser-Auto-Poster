<?php
/*
Plugin Name: Bambuser Auto-Poster
Plugin URI: http://github.com/TV4/Bambuser-Auto-Poster
Description: Publish Bambuser videocasts on a blog
Author: David Hall (TV4 AB), parts of code from Mattias Norell
Version: 0.20
Author URI: http://www.tv4.se/
License: GPL2
*/

/*
 *  Bambuser Auto-Poster, Wordpress plugin to automatically make posts with Bambuser embeds.
 *  Copyright (C) 2010 TV4 AB
 *
 * Parts of this program are based on "Bambuser for Wordpress - Shortcode" by Mattias Norell
 * released under the GPL2 license. Copyright (C) 2010 Mattias Norell
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 *
 */


if (!class_exists('BambuserAutoposter')) {

    class BambuserAutoposter
    {
        var $opt_key = 'tv4se_bambuser_options';

        var $default_options = array(
            'username' => 'bambuser',
            'postuser' => 1,
            'category'	=> 1,
            'maxposts' => 1,
            'interval' => 30,
            'secret_key' => '',
            'revision' => 2);

        var $o = array();

        function BambuserAutoposter() {
            $this->read_options();
            $this->actions_filters();
        }

        function read_options() {
            $this->o = get_option($this->opt_key);
        }

        function parse_request($wp) {
            if (array_key_exists('bambuser', $wp->query_vars)
                    && $wp->query_vars['bambuser'] == 'post') {
                if($this->is_authentic_request($wp->query_vars)) {
                    wp_die('Authentic request to BambuserAutoposter!');
                } else {
                    wp_die('Request to BambuserAutoposter was not authentic!');
                }
            }
        }

        function query_vars($vars) {
            $vars[] = 'bambuser';
            $vars[] = 'method';
            $vars[] = 'usertoken';
            $vars[] = 'vid';
            $vars[] = 'title';
            $vars[] = 'type';
            $vars[] = 'username';
            $vars[] = 'created';
            $vars[] = 'ts';
            $vars[] = 'hash';
            return $vars;
        }


        function actions_filters() {
            add_filter('cron_schedules', array ( &$this, 'cron' ));
            add_action('tv4se_bambuser_event', array ( &$this, 'fetch_and_insert' ));
            register_activation_hook(__FILE__, array ( &$this, 'install_plugin' ));
            register_deactivation_hook(__FILE__,array ( &$this,  'uninstall_plugin'));
            add_action('admin_init', array ( &$this, 'admin_init' ));
            add_filter( 'wp_feed_cache_transient_lifetime', array(&$this, 'cachetime' ), 10, 2 );
            add_action('admin_menu', array ( &$this, 'settings_menu' ));
            add_shortcode('bambuser', array(&$this, 'shortcode'));
            add_filter('update_option_tv4se_bambuser_options',array(&$this, 'option_was_updated'),10,2);
            add_action('parse_request', array(&$this, 'parse_request'));
            add_filter('query_vars', array(&$this, 'query_vars'));
        }

        function option_was_updated($oldvalue, $newvalue) {
            if($oldvalue['interval']!=$newvalue['interval']) {
                $interval = intval($newvalue['interval'])*60;
                $timestamp = wp_next_scheduled( 'tv4se_bambuser_event');
                wp_unschedule_event($timestamp, 'tv4se_bambuser_event');
                wp_schedule_event(time()+(60*interval), 'tv4se_bambuser_update', 'tv4se_bambuser_event');
                update_option($this->feed_cache_name(), (time()+(60*interval)-10));
            }
        }

        function feed_name() {
            return 'http://feed.bambuser.com/channel/'.$this->o['username'].'.rss';
        }

        function feed_cache_name() {
            return '_transient_timeout_feed_'.md5($this->feed_name());
        }

        function cron($schedules)
        {
            $interval = intval($this->o['interval'])*60;
            if($interval==0) { $interval=1800; }

            return array_merge($schedules, array(
                'tv4se_bambuser_update' => array(
                    'interval' => $interval,
                    'display' => sprintf('Bambuser update(every %d seconds)', $interval)
                )));
        }


        function install_plugin(){
            $this->o = get_option($this->opt_key);

            if (!is_array($this->o) || empty($this->o) ) {
                update_option($this->opt_key, $this->default_options);
                $this->o = get_option($this->opt_key);
            }
            else {
                $this->o = $this->o + $this->default_options;
                $this->o["revision"] = $this->default_options["revision"];
                update_option( $this->opt_key, $this->o);
            }

            if (!wp_next_scheduled('tv4se_bambuser_event')) {
                wp_schedule_event(time()+30, 'tv4se_bambuser_update', 'tv4se_bambuser_event');
            }
        }

        function uninstall_plugin(){
            $timestamp = wp_next_scheduled( 'tv4se_bambuser_event');
            wp_unschedule_event($timestamp, 'tv4se_bambuser_event');
        }

        function cachetime($lifetime, $url) {
            $interval = intval($this->o['interval'])*60;
            if($interval==0) { $interval=1800; }
            if ( $url==md5($this->feed_name())) {
                $lifetime = $interval-10;
            }
            return $lifetime;
        }

        function settings_menu(){
            add_options_page('Bambuser Autoposter Settings', 'Bambuser Autoposter', 'manage_options', 'bambuser',
                array ( &$this, 'options_page' ));
        }

        function options_page(){
            echo "<div>";
            echo "<h2>Bambuser Autoposter Settings</h2>";
            $timestamp = wp_next_scheduled( 'tv4se_bambuser_event');
            $last_save = intval(get_option('tv4se_bambuser_lastpub'));
            $cache_timeout = intval(get_option($this->feed_cache_name()));
            print "<p>Next update at ".date("Y-m-d H:i:s",$timestamp+get_option( 'gmt_offset' ) * 3600).'</p>';
            print '<p>Last clip from '.date("Y-m-d H:i:s",$last_save+get_option( 'gmt_offset' ) * 3600).'</p>';
            print '<p>Feed cache times out at '.date("Y-m-d H:i:s",$cache_timeout+get_option( 'gmt_offset' ) * 3600).'</p>';
            echo '<form action="options.php" method="post">';
            settings_fields('tv4se_bambuser_options');
            do_settings_sections('bambuser');
            echo '<input name="Submit" type="submit" value="'. esc_attr('Save Changes') .'" />
</form></div>';
        }


        function admin_init(){
            register_setting( 'tv4se_bambuser_options', 'tv4se_bambuser_options', array ( &$this, 'options_validate' ));
            add_settings_section('tv4se_bambuser_autoposter', '',  array ( &$this, 'details_text' ), 'bambuser');
            add_settings_field('tv4se_bambuser_field_1', 'User name', array ( &$this, 'field_display'), 'bambuser',
                'tv4se_bambuser_autoposter','username');
            add_settings_field('tv4se_bambuser_field_2', 'Post as user', array ( &$this, 'field_display'), 'bambuser',
                'tv4se_bambuser_autoposter','postuser');
            add_settings_field('tv4se_bambuser_field_3', 'Post in category', array ( &$this, 'field_display'), 'bambuser',
                'tv4se_bambuser_autoposter','category');
            add_settings_field('tv4se_bambuser_field_4', 'Maximum posts to publish', array ( &$this, 'field_display'),
                'bambuser', 'tv4se_bambuser_autoposter','maxposts');
            add_settings_field('tv4se_bambuser_field_5', 'Update interval', array ( &$this, 'field_display'), 'bambuser',
                'tv4se_bambuser_autoposter','interval');
            add_settings_field('tv4se_bambuser_field_6', 'Secret API key', array ( &$this, 'field_display'), 'bambuser',
                'tv4se_bambuser_autoposter','secret_key');

        }



        function field_display($field){
            switch ($field) {
                case "username":
                    echo "<input id='tv4se_bambuser_field' name='tv4se_bambuser_options[username]' size='20' type='text'";
                    echo "value='{$this->o['username']}' />";
                    break;
                case "postuser":
                    $blogusers = get_users_of_blog();
                    echo '<select name="tv4se_bambuser_options[postuser]">';
                    foreach ($blogusers as $usr) {
                        echo "<option value=\"{$usr->ID}\"";
                        if($this->o['postuser']==$usr->ID) {
                            echo ' selected="selected"';
                        }
                        echo ">{$usr->user_login} ({$usr->display_name})</option>";
                    }
                    echo '</select>';
                    break;

                case "category":
                    echo '<select name="tv4se_bambuser_options[category]">';
                    $categories=  get_categories();
                    foreach ($categories as $category) {
                        $option = '<option value="'.$category->term_id.'"';
                        if($this->o['category']==$category->term_id) {
                            $option .= ' selected="selected"';
                        }
                        $option .= '>'.$category->cat_name;
                        $option .= ' ('.$category->category_count.')';
                        $option .= '</option>';
                        echo $option;
                    }
                    echo '</select>';
                    break;
                case "maxposts":
                    echo "<input id='tv4se_bambuser_field' name='tv4se_bambuser_options[maxposts]' size='5' type='text'";
                    echo " value='{$this->o['maxposts']}' />";
                    break;
                case "interval":
                    echo "<input id='tv4se_bambuser_field' name='tv4se_bambuser_options[interval]' size='5' type='text'";
                    echo " value='{$this->o['interval']}' /> minutes";
                    break;
                case "secret_key":
                    echo "<input id='tv4se_bambuser_field' name='tv4se_bambuser_options[secret_key]' size='20' type='text'";
                    echo " value='{$this->o['secret_key']}' />";
                    break;
            }

        }

        function details_text(){
            echo "<p>Bambuser id information and post settings</p>";
        }

        function options_validate($input){
            preg_match("/[A-Za-z0-9\-_\.\ ]*/", $input['username'], $matches);
            $newinput['username'] = $matches[0];
            $newinput['postuser'] = intval($input['postuser']);
            $newinput['category'] = intval($input['category']);
            $newinput['maxposts'] = intval($input['maxposts']);
            $newinput['interval'] = abs(intval($input['interval']));
            $newinput['secret_key'] = $input['secret_key'];
            return $newinput;
        }

        function get_shortcode($link) {
            preg_match("/vid=([0-9]*)/", $link, $matches);
            $id = $matches[1];
            return '[bambuser id="'.$id.'"]';
        }

        function fetch_and_insert(){
            $last_save = intval(get_option('tv4se_bambuser_lastpub'));
            $username = $this->o['username'];
            $feed = fetch_feed($this->feed_name());
            if($feed && $feed->get_items()) {
                $maxitems = $this->o['maxposts'];
                $items = array_slice($feed->get_items(), 0, $maxitems);
                $counter = 0;
                foreach ( $items as $item ) :
                    $counter++;
                    if(intval($item->get_date('U')) > $last_save) {
                        $my_post = array(
                            'post_title' => $item->get_title(),
                            'post_content' => $this->get_shortcode($item->get_enclosure()->get_link()),
                            'post_date' => date('Y-m-d H:i:s',intval($item->get_date('U'))+get_option( 'gmt_offset' )
                                    * 3600),
                            'post_status' => 'publish',
                            'post_author' => $this->o['postuser'],
                            'post_category' => array($this->o['category'])
                        );
                        $post_id = wp_insert_post( $my_post );
                        if($counter==1) {
                            update_option('tv4se_bambuser_lastpub', $item->get_date('U'));
                        }
                    }

                endforeach;
            }
        }

        function is_authentic_request($vars) {
            $secret_key = $this->o['secret_key'];
            if(isset($secret_key) && isset($vars['method']) && isset($vars['vid']) && isset($vars['type']) && isset($vars['created'])) {
                $my_hash = sha1(
                    $secret_key
                            . $vars['method']
                            . $vars['vid']
                            . $vars['type']
                            . $vars['created']
                );
                if ($my_hash == $vars['hash']) {
                    return TRUE;
                }
            }
            return FALSE;
        }

        function shortcode($atts, $content=null) {
            extract(shortcode_atts(array(
                'id' 	=> '',
                'channel' => '',
                'playlist' => 'hide',
                'width' 	=> '424',
                'height' 	=> '321',
            ), $atts));

            if($channel !== '' && $height == 321){$height = 500;}
            if($playlist=='show' && $height == 321){$height = 500;}

            if (!is_numeric($id)){
                return '<object id="bplayer" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" width="'.$width.'"'
                        . 'height="'.$height.'"><embed name="bplayer"'
                        . 'src="http://bambuser.com/r/player.swf?username='.$channel.'" type="application/x-shockwave-flash"'
                        . 'width="'.$width.'" height="'.$height.'" allowfullscreen="true" wmode="opaque"></embed><param '
                        . 'name="movie" value="http://bambuser.com/r/player.swf?username='.$channel.'"></param><param '
                        . 'name="allowfullscreen" value="true"></param><param name="wmode" value="opaque"></param></object>';
            } else {
                return '<object id="bplayer" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" width="'.$width.'"'
                        . 'height="'.$height.'"><embed name="bplayer" src="http://static.bambuser.com/r/player.swf?vid='
                        . $id.'" type="application/x-shockwave-flash" width="'.$width.'" height="'.$height.'"'
                        . 'allowfullscreen="true" wmode="opaque"></embed><param name="movie" '
                        . 'value="http://static.bambuser.com/r/player.swf?vid='.$id.'"></param><param name="allowfullscreen"'
                        . 'value="true"></param><param name="wmode" value="opaque"></param></object>';
            }
        }



    }

    $bambuser_autoposter = new BambuserAutoposter();

}
?>