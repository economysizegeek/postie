<?php
/*
Plugin Name: Cronless Postie
Plugin URI: http://www.economysizegeek.com/?page_id=395
Description: This plugin allows you to setup your rss feeds to trigger postie (See <a href="../wp-content/plugins/postie/cronless_postie.php?cronless_postie_read_me=1">Quick Readme</a>)
Author: Dirk Elmendorf
Version: 1.1.1
Author URI: http://www.economysizegeek.com/
*/ 

include_once (dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR. "wp-config.php");
function check_postie() {
    $host = get_option('siteurl');
    preg_match("/http:\/\/(.[^\/]*)(.*)/",$host,$matches);
    $host = $matches[1];
    $url = "";
    if (isset($matches[2])) {
        $url .=  $matches[2];
    }
    $url .= "/wp-content/plugins/postie/get_mail.php";
    $port = 80;
	$fp=fsockopen($host,$port,$errno,$errstr);
    fputs($fp,"GET $url HTTP/1.0\r\n");
    fputs($fp,"User-Agent:  Cronless-Postie\r\n");
    fputs($fp,"Host: $host\r\n");
    fputs($fp,"\r\n");
    $page = '';
    while(!feof($fp)) {
        $page.=fgets($fp,128);
    }
#var_dump($page);
    fclose($fp);
}
function cronless_read_me() {
    include_once("cronless_read_me.php");
}
if (isset($_GET["cronless_postie_read_me"])) {
    include_once(ABSPATH . "wp-admin/admin.php");
    $title = __("Edit Plugins");
    $parent_file = 'plugins.php';
    include(ABSPATH . 'wp-admin/admin-header.php');
    cronless_read_me();
    include(ABSPATH . 'wp-admin/admin-footer.php');
    exit();
}


add_action('init','postie_cron');
function postie_cron() {
    if (!wp_next_scheduled('check_postie')) {
        wp_schedule_event(time(),'hourly','check_postie');
    }
}
add_action('check_postie', 'check_postie');
/**
  * Now just add the following line to all of the rss/atom pages 
  * Just make sure it is after the opening if statement
  
  do_action('check_postie'); 
  
 */

?>
