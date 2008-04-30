<?php
/*
Plugin Name: Postie
Plugin URI: http://www.economysizegeek.com/?page_id=395
Description: Signifigantly upgrades the posting by mail features of Word Press (See <a href="../wp-content/plugins/postie/postie.php?postie_read_me=1">Quick Readme</a>)
Version: 1.1.1
Author: Dirk Elmendorf
Author URI: http://www.economysizegeek.com/
*/

/*
* -= Requests Pending =-
* German Umlats don't work
* Problems under PHP5 
* Problem with some mail server
* Config Form freaks out in some cases
* Multiple emails should tie to a single account
* Each user should be able to have a default category
* WP Switcher not compatible
* Setup poll
    - web server
    - mail clients
    - plain/html
    - phone/computer
    - os of server
    - os of client
    - number of users posting
* make sure it handles the case where the url is http://www.site.com/wordpress/ instead of just http://www.site.com/
* Test for calling from the command line
* Support userid/domain  as a valid username
* WP-switcher not compatiable http://www.alexking.org/index.php?content=software/wordpress/content.php#wp_120
* Test out a remote cron system
* Add ability to post to an existing page
* Add a download counter
* Add support for http://unknowngenius.com/wp-plugins/faq.html#one-click
*    www.cdavies.org/code/3gp-thumb.php.txt
*    www.cdavies.org/permalink/watchingbrowserembeddedgpvideosinlinux.php
* Support draft/private posts
* Make it possible to post without a script at all
*/

//Older Version History is in the HISTORY file

include_once (dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR. "wp-config.php");
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR ."postie-functions.php");
if (isset($_GET["postie_read_me"])) {
    include_once(ABSPATH . "wp-admin/admin.php");
    $title = __("Edit Plugins");
    $parent_file = 'plugins.php';
    include(ABSPATH . 'wp-admin/admin-header.php');
    postie_read_me();
    include(ABSPATH . 'wp-admin/admin-footer.php');
}
//Add Menu Configuration
add_action("admin_menu","PostieMenu");
?>
