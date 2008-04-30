<?php
include_once (dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR . "wp-config.php");
define("POSTIE_ROOT",dirname(__FILE__));
define("POSTIE_TABLE",$GLOBALS["table_prefix"]. "postie_config");

/**
  * This is the main handler for all of the processing
  */
function PostEmail($poster,$mimeDecodedEmail) {
    $config = GetConfig();
    $GLOBALS["POSTIE_IMAGE_ROTATION"] = 0;
    $attachments = array(
            "html" => array(), //holds the html for each image
            "cids" => array(), //holds the cids for HTML email
            "image_files" => array() //holds the files for each image
            );
    print("<p>Message Id is :" . $mimeDecodedEmail->headers["message-id"] . "</p><br/>\n");
    print("<p>Email has following attachments:</p>");
    foreach($mimeDecodedEmail->parts as $parts) {
        print("<p>".$parts->ctype_primary ." ".$parts->ctype_secondary) ."</p><br />\n";
    }
    FilterTextParts($mimeDecodedEmail);
#print("<p>Email has following attachments after filtering:");
#    foreach($mimeDecodedEmail->parts as $parts) {
#        print("<p>".$parts->ctype_primary ." ".$parts->ctype_secondary) ."<br />\n";
#    }
    $content = GetContent($mimeDecodedEmail,$attachments);
    $subject = GetSubject($mimeDecodedEmail,$content);
    $rotation = GetRotation($mimeDecodedEmail,$content);
    if ($rotation != "0"
            && count($attachments["image_files"])) {
        RotateImages($rotation,$attachments["image_files"]);
    }
    SpecialMessageParsing($content,$attachments);
    $message_date = NULL;
    if (array_key_exists("date",$mimeDecodedEmail->headers)
            && !empty($mimeDecodedEmail->headers["date"])) {
        $message_date = HandleMessageEncoding($mimeDecodedEmail->headers["content-transfer-encoding"],
                                               $mimeDecodedEmail->ctype_parameters["charset"],
                                               $mimeDecodedEmail->headers["date"]);
    }
    list($post_date,$post_date_gmt) = DeterminePostDate($content, $message_date);

    ubb2HTML($content);	

    $content = FilterNewLines($content);
    
    $post_categories = GetPostCategories($subject);
    $comment_status = AllowCommentsOnPost($content);
    
    $details = array(
        'post_author'		=> $poster,
        'post_date'			=> $post_date,
        'post_date_gmt'		=> $post_date_gmt,
        'post_content'		=> preg_replace("/'/","\\'",$content),
        'post_title'		=> preg_replace("/'/","\\'",$subject),
        'post_modified'		=> $post_date,
        'post_modified_gmt'	=> $post_date_gmt,
        'ping_status' => get_option('default_ping_status'),
         'post_category' => $post_categories,
        'comment_status' => $comment_status,
        'post_name' => sanitize_title($subject),
        'post_status' => 'publish'
    );

    DisplayEmailPost($details);
    PostToDB($details); 
}
/** FUNCTIONS **/
function postie_read_me() {
    include(POSTIE_ROOT . DIRECTORY_SEPARATOR. "postie_read_me.php");
}
/**
*  This sets up the configuration menu
*/
function PostieMenu() {
    add_options_page("Configure Postie",
            "Configure Postie" , 
             0,
            POSTIE_ROOT .  "/postie.php",
            "ConfigurePostie");
}
/**
  * This handles actually showing the form
  */
function ConfigurePostie() {
    PostieAdminPermissions();
    if (current_user_can('config_postie')) {
        include(POSTIE_ROOT . DIRECTORY_SEPARATOR. "config_form.php");
    }
    else {
        postie_read_me();
    }
}

/**
  * This function handles determining the protocol and fetching the mail
  * @return array
  */ 
function FetchMail() {
    $config = GetConfig();
    $emails = array();
    if (!$config["MAIL_SERVER"]
            || !$config["MAIL_SERVER_PORT"]
            || !$config["MAIL_USERID"]) {
        die("Missing Configuration For Mail Server\n");
    }
    if ($config["MAIL_SERVER"] == "pop.gmail.com") {
        print("\nMAKE SURE POP IS TURNED ON IN SETTING AT Gmail\n");
    }
	switch ( strtolower($config["INPUT_PROTOCOL"]) ) {
		case 'smtp': //direct 
			$fd = fopen("php://stdin", "r");
			$input = "";
			while (!feof($fd)) {
			    $input .= fread($fd, 1024);
			}
			fclose($fd);
			$emails[0] = $input;
			break;
        case 'imap':
        case 'imap-ssl':
        case 'pop3-ssl':
            HasIMAPSupport(false);
            if ($config["TEST_EMAIL"]) {
                $emails = TestIMAPMessageFetch();
            }
            else {
                $emails = IMAPMessageFetch();
            }
            break;
        case 'pop3':
		default: 
            if ($config["TEST_EMAIL"]) {
			    $emails = TestPOP3MessageFetch();
            }
            else {
			    $emails = POP3MessageFetch();
            }
		}
    if (!$emails) {
		die("\nThere does not seem to be any new mail.\n");
    }
    return($emails);
}
/**
  *Handles fetching messages from an imap server
  */
function TestIMAPMessageFetch ( ) {			
    print("**************RUNING IN TESTING MODE************\n");
    $config = GetConfig();
	$config["MAIL_USERID"] = $config["TEST_EMAIL_ACCOUNT"];
    $config["MAIL_PASSWORD"] = $config["TEST_EMAIL_PASSWORD"];
    return(IMAPMessageFetch($config));

}
/**
  *Handles fetching messages from an imap server
  */
function IMAPMessageFetch ($config = NULL ) {			
    if (!$config) {
        $config = GetConfig();
    }
    require_once("postieIMAP.php");

    $mail_server = &PostieIMAP::Factory($config["INPUT_PROTOCOL"]);
    print("\nConnecting to $config[MAIL_SERVER]:$config[MAIL_SERVER_PORT] ($config[INPUT_PROTOCOL])) \n");
    if (!$mail_server->connect($config["MAIL_SERVER"], $config["MAIL_SERVER_PORT"],$config["MAIL_USERID"],$config["MAIL_PASSWORD"])) {
        print("Mail Connection Time Out\n
                Common Reasons: \n
                Server Down \n
                Network Issue \n
                Port/Protocol MisMatch \n
                ");
        die("The Server said:".$mail_server->error()."\n");
    }
    $msg_count = $mail_server->getNumberOfMessages();
    $emails = array();
	// loop through messages 
	for ($i=1; $i <= $msg_count; $i++) {
		$emails[$i] = $mail_server->fetchEmail($i);
        if ( $config["DELETE_MAIL_AFTER_PROCESSING"]) {
			$mail_server->deleteMessage($i);
		}
        else {
            print("Not deleting messages!\n");
        }
	}
    if ( $config["DELETE_MAIL_AFTER_PROCESSING"]) {
        $mail_server->expungeMessages();
    }
	//clean up
	$mail_server->disconnect();	
	return $emails;
}
function TestPOP3MessageFetch ( ) {			
    print("**************RUNING IN TESTING MODE************\n");
    $config = GetConfig();
	$config["MAIL_USERID"] = $config["TEST_EMAIL_ACCOUNT"];
    $config["MAIL_PASSWORD"] = $config["TEST_EMAIL_PASSWORD"];
    return(POP3MessageFetch($config));
}
/**
  *Retrieves email via POP3
  */
function POP3MessageFetch ($config = NULL) {			
    if (!$config) {
        $config = GetConfig();
    }
	require_once(ABSPATH.WPINC.DIRECTORY_SEPARATOR.'class-pop3.php');
	$pop3 = &new POP3();
    print("\nConnecting to $config[MAIL_SERVER]:$config[MAIL_SERVER_PORT] ($config[INPUT_PROTOCOL]))  \n");
    if (!$pop3->connect($config["MAIL_SERVER"], $config["MAIL_SERVER_PORT"])) {
        if (strpos($pop3->ERROR,"POP3: premature NOOP OK, NOT an RFC 1939 Compliant server") === false) {
            print("Mail Connection Time Out\n
                    Common Reasons: \n
                    Server Down \n
                    Network Issue \n
                    Port/Protocol MisMatch \n
                    ");
            die("The Server Said $pop3->ERROR \n");
        }
    }

	//Check to see if there is any mail, if not die
	$msg_count = $pop3->login($config["MAIL_USERID"], $config["MAIL_PASSWORD"]);
	if (!$msg_count) {
		$pop3->quit();
        return(array());
	}

	// loop through messages 
	for ($i=1; $i <= $msg_count; $i++) {
		$emails[$i] = implode ('',$pop3->get($i));
        if ( $config["DELETE_MAIL_AFTER_PROCESSING"]) {
			if( !$pop3->delete($i) ) {
				echo 'Oops '.$pop3->ERROR.'\n';
				$pop3->reset();
				exit;
			} else {
				echo "Mission complete, message $i deleted.\n";
			}
		}
        else {
            print("Not deleting messages!\n");
        }
	}
	//clean up
	$pop3->quit();	
	return $emails;
}
/**
  * Determines if it is a writable directory
  */
function IsWritableDirectory($directory) {
    if (!is_dir($directory)) {
        die ("Sorry but ".$directory." is not a valid directory.");
    }
    if (!is_writable($directory)) {
        die("The web server cannot write to ".$directory." please correct the permissions");
    }

}
/**
  * This function handles putting the actual entry into the database
  * @param array - categories to be posted to
  * @param array - details of the post
  */
function PostToDB($details) {
    $config = GetConfig();
    if ($config["POST_TO_DB"]) {
        //generate sql for insertion	    
        $_POST['publish'] = true; //Added to make subscribe2 work - it will only handle it if the global varilable _POST is set
        $post_ID = wp_insert_post($details);
        //do_action('publish_post', $post_ID); - no longer needed
        //do_action('publish_phone', $post_ID); -- seems to triger a double

    }
}

/**
  * This function determines if the mime attachment is on the BANNED_FILE_LIST
  * @param string
  * @return boolean
  */
function BannedFileName($filename) {
    $config = GetConfig();
    if (in_array($filename,$config["BANNED_FILES_LIST"])) {
        print("<p>Ignoreing $filename - it is on the banned files list.");
        return(true);
    }
    return(false);
}

//tear apart the meta part for useful information
function GetContent ($part,&$attachments) {
    $config = GetConfig();
    $meta_return = NULL;	

	DecodeBase64Part($part);
    if (BannedFileName($part->ctype_parameters['name'])
            || BannedFileName($part->ctype_parameters['name'])) {
        return(NULL);
    }
    
    if ($part->ctype_primary == "application"
            && $part->ctype_secondary == "octet-stream") {
        if ($part->disposition == "attachment") {
            $image_endings = array("jpg","png","gif","jpeg","pjpeg");
            foreach ($image_endings as $type) {
                if (eregi(".$type\$",$part->d_parameters["filename"])) {
                    $part->ctype_primary = "image";
                    $part->ctype_secondary = $type;
                    break;
                }
            }
        }
        else {
            $mimeDecodedEmail = DecodeMIMEMail($part->body);
            FilterTextParts($mimeDecodedEmail);
            foreach($mimeDecodedEmail->parts as $section) {
                $meta_return .= GetContent($section,$attachments);
            }
        }
    }
    if ($part->ctype_primary == "multipart"
            && $part->ctype_secondary == "appledouble") {
        $mimeDecodedEmail = DecodeMIMEMail("Content-Type: multipart/mixed; boundary=".$part->ctype_parameters["boundary"]."\n".$part->body);
        FilterTextParts($mimeDecodedEmail);
        FilterAppleFile($mimeDecodedEmail);
        foreach($mimeDecodedEmail->parts as $section) {
            $meta_return .= GetContent($section,$attachments);
        }
    }
    else { 
        switch ( strtolower($part->ctype_primary) ) {
            case 'multipart':
                FilterTextParts($part);
                foreach ($part->parts as $section) {
                    $meta_return .= GetContent($section,$attachments);
                }
                break;
            case 'text':

                HandleMessageEncoding($part->headers["content-transfer-encoding"],
                                      $part->ctype_parameters["charset"],
                                      $part->body);

                //go through each sub-section
                if ($part->ctype_secondary=='enriched') {
                    //convert enriched text to HTML
                    $meta_return .= etf2HTML($part->body ) . "\n";
                } elseif ($part->ctype_secondary=='html') {
                    //strip excess HTML
                    $meta_return .= HTML2HTML($part->body ) . "\n";
                } else {
                    //regular text, so just strip the pgp signature
                    if (ALLOW_HTML_IN_BODY) {
                        $meta_return .= $part->body  . "\n";
                    }
                    else {
                        $meta_return .= htmlentities( $part->body ) . "\n";
                    }
                    $meta_return = StripPGP($meta_return);
                }
                break;

            case 'image':
                $file = GenerateImageFileName($config["REALPHOTOSDIR"], $part->ctype_secondary);
                //This makes sure there is no collision
                $ctr = 0;
                while(file_exists($file) && $ctr < 1000) {
                    $file = GenerateImageFileName($config["REALPHOTOSDIR"], $part->ctype_secondary);
                    $ctr++;
                }
                if ($ctr >= 1000) {
                    die("Unable to find a name for images that does not collide\n");
                }
                $fileName = basename($file);
                $fp = fopen($file, 'w');
                fwrite($fp, $part->body);
                fclose($fp);
                @exec ('chmod 755 ' . $file);
                if ($config["USE_IMAGEMAGICK"] && $config["AUTO_SMART_SHARP"]) {
                            ImageMagickSharpen($file);
                }
                $mimeTag = '<!--Mime Type of File is '.$part->ctype_primary."/".$part->ctype_secondary.' -->';
                $thumbImage = NULL;
                $cid = trim($part->headers["content-id"],"<>");; //cids are in <cid>
                if ($config["RESIZE_LARGE_IMAGES"]) {
                    list($thumbImage, $fullImage) = ResizeImage($file,strtolower($part->ctype_secondary));
                }
                $attachments["image_files"][] = array(($thumbImage ? $config["REALPHOTOSDIR"] . $thumbImage:NULL),
                                                      $config["REALPHOTOSDIR"] . $fileName,
                                                      $part->ctype_secondary);
                if ($thumbImage) {
                        $marime=DetermineImageSize($file);
                        $marimex=$marime[0]+20;
                        $marimey=$marime[1]+20;
                        $attachments["html"][] .= $mimeTag.'<div class="' . $config["IMAGEDIV"].'"><a href="' . $config["URLPHOTOSDIR"] . $fullImage . '" onclick="window.open(' . "'"
                            . $config["URLPHOTOSDIR"] . $fullImage . "','"
                            . "full_size_image" . "','"
                            . "toolbar=0,scrollbars=0,location=0,status=0,menubar=0,resizable=1,height=" . $marimey . ",width=" . $marimex . "');" . "return false;"
                            . '"><img src="' . $config["URLPHOTOSDIR"] . $thumbImage . '" alt="'
                            . $part->ctype_parameters['name'] . '" title="' . $part->ctype_parameters['name'] . '" style="'.$config["IMAGESTYLE"].'" class="'.$config["IMAGECLASS"].'" /></a></div>' . "\n";
                    if ($cid) {
                        $attachments["cids"][$cid] = array($config["URLPHOTOSDIR"] . $fullImage,count($attachments["html"]) - 1);
                    }
                }
                else {
                    $attachments["html"][] .= $mimeTag .'<div class="' . $config["IMAGEDIV"].'"><img src="' . $config["URLPHOTOSDIR"] . $fileName 
                                           . '" alt="' . $part->ctype_parameters['name'] . '" style="' 
                                           . $config["IMAGESTYLE"] . '" class="' . $config["IMAGECLASS"] . '"  /></div>' . "\n";
                    if ($cid) {
                        $attachments["cids"][$cid] = array($config["URLPHOTOSDIR"] . $fileName,count($attachments["html"]) - 1);
                    }
                }
                break;
            default:
                if (in_array(strtolower($part->ctype_primary),$config["SUPPORTED_FILE_TYPES"])) {
                    //pgp signature - then forget it
                    if ( $part->ctype_secondary == 'pgp-signature' ) {break;}
                    //other attachments save to FILESDIR
                    $filename =  $part->ctype_parameters['name'];
                    $file = $config["REALFILESDIR"] . $filename;
                    $fp = fopen($file, 'w');
                    fwrite($fp, $part->body );
                    fclose($fp);
                    @exec ('chmod 755 ' . $file);
                    $cid = trim($part->headers["content-id"],"<>");; //cids are in <cid>

                     if ($part->ctype_secondary == "3gpp"
                             || $part->ctype_secondary == "3gp"
                             || $part->ctype_secondary == "3g2"
                             ||  $part->ctype_secondary == "3gpp2") {
                         if ($config["3GP_QT"]) {
                             //Shamelessly borrowed from http://www.postneo.com/2003/12/19/embedding-3gpp-in-html
                         $attachments["html"][] = '<!--Mime Type of File is '.$part->ctype_primary."/".$part->ctype_secondary.' -->' .
                            '<a href="'.$config["URLFILESDIR"] . $filename.'">Video'.
                            '<object '.
                                'classid="clsid:02BF25D5-8C17-4B23-BC80-D3488ABDDC6B" '.
                                'codebase="http://www.apple.com/qtactivex/qtplugin.cab" '.
                                'width="128" '.
                                'height="112"> '.
                                '<param name="src" VALUE="'. $config["URLFILESDIR"] . $filename .'"> '.
                                '<param name="autoplay" VALUE="false"> '.
                                '<param name="controller" VALUE="true"> '.
                               '<embed '.
                               'src="'. $config["URLFILESDIR"] . $filename .'" '.
                               'width="128" '.
                               'height="112" '.
                               'autoplay="true" '.
                               'controller="true" '.
                               'type="video/quicktime" '.
                               'pluginspage="http://www.apple.com/quicktime/download/" '.
                               'width="128" '.
                               'height="150"></embed> '.
                               '</object></a>';
                         }
                         else {
                             if (file_exists($config["3GP_FFMPEG"])) {
                                $fileName = basename($file);
                                //options from http://www.getid3.org/phpBB2/viewtopic.php?p=1290&
                                $scaledFileName =  "thumb.".$fileName;
                                $scaledFile = $config["REALPHOTOSDIR"] . $scaledFileName;

                                @exec (escapeshellcmd($config["3GP_FFMPEG"]) . 
                                            " -i " .
                                            escapeshellarg($file) .
                                            " -y -ss 00:00:01 -vframes 1 -an -sameq -f gif " . 
                                            escapeshellarg($scaledFile) );
                                @exec ('chmod 755 ' . escapeshellarg($scaledFile));

                                $attachments["html"][] .= '<!--Mime Type of File is '.$part->ctype_primary."/".$part->ctype_secondary.' --><div class="' . $config["3GPDIV"].'"><a href="' . $config["URLPHOTOSDIR"] . $fileName. '"><img src="' . $config["URLPHOTOSDIR"] . $scaledFileName . '" alt="' . $part->ctype_parameters['name'] . '" style="'.$config["IMAGESTYLE"].'" class="'.$config["IMAGECLASS"].'" /></a></div>' . "\n";
                             }
                             else {
                                $attachments["html"][] = '<!--Mime Type of File is '.$part->ctype_primary."/".$part->ctype_secondary.' --><div class="' . $config["ATTACHMENTDIV"].'"><a href="' . $config["URLFILESDIR"] . $filename . '" class="' . $config["3GPCLASS"].'">' . $part->ctype_parameters['name'] . '</a></div>' . "\n";
                            }

                         }
                    }
                     elseif ($part->ctype_secondary == "x-shockwave-flash") {
                         $attachments["html"][] = '<!--Mime Type of File is '.$part->ctype_primary."/".$part->ctype_secondary.' -->'.
                            '<object '.
                                'classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000"  '.
                                'codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,29,0" '.
                                'width=""  '.
                                'height=""> '.
                               '<param name="movie" value="'. $config["URLFILESDIR"] . $filename .'"> '.
                               '<param name="quality" value="high"> '.
                               '<embed  '.
                               'src="'. $config["URLFILESDIR"] . $filename .'"  '.
                               'width=""  '.
                               'height=""  '.
                               'quality="high"  '.
                               'pluginspage="http://www.macromedia.com/go/getflashplayer"  '.
                               'type="application/x-shockwave-flash"  '.
                               'width=""  '.
                               'height=""></embed> '.
                               '</object>'; 
                    }
                    else {
                        $attachments["html"][] = '<!--Mime Type of File is '.$part->ctype_primary."/".$part->ctype_secondary.' --><a href="' . $config["URLFILESDIR"] . $filename . '">' . $part->ctype_parameters['name'] . '</a>' . "\n";
                    }
                    if ($cid) {
                        $attachments["cids"][$cid] = array($config["URLFILESDIR"] . $filename,count($attachments["html"]) - 1);
                    }
                }
                break;
        }		
    }
    return $meta_return;
}

function ubb2HTML(&$text) {
	// Array of tags with opening and closing
	$tagArray['img'] = array('open'=>'<img src="','close'=>'">');
	$tagArray['b'] = array('open'=>'<b>','close'=>'</b>');
	$tagArray['i'] = array('open'=>'<i>','close'=>'</i>');
	$tagArray['u'] = array('open'=>'<u>','close'=>'</u>');
	$tagArray['url'] = array('open'=>'<a href="','close'=>'">\\1</a>');
	$tagArray['email'] = array('open'=>'<a href="mailto:','close'=>'">\\1</a>');
	$tagArray['url=(.*)'] = array('open'=>'<a href="','close'=>'">\\2</a>');
	$tagArray['email=(.*)'] = array('open'=>'<a href="mailto:','close'=>'">\\2</a>');
	$tagArray['color=(.*)'] = array('open'=>'<font color="','close'=>'">\\2</font>');
	$tagArray['size=(.*)'] = array('open'=>'<font size="','close'=>'">\\2</font>');
	$tagArray['font=(.*)'] = array('open'=>'<font face="','close'=>'">\\2</font>');
	// Array of tags with only one part
	$sTagArray['br'] = array('tag'=>'<br>');
	$sTagArray['hr'] = array('tag'=>'<hr>');
	
	foreach($tagArray as $tagName=>$replace) {
		$tagEnd = preg_replace('/\W/Ui','',$tagName);
		$text = preg_replace("|\[$tagName\](.*)\[/$tagEnd\]|Ui","$replace[open]\\1$replace[close]",$text);
	}
	foreach($sTagArray as $tagName=>$replace) {
		$text = preg_replace("|\[$tagName\]|Ui","$replace[tag]",$text);
	}
	return $text;
}


// This function turns Enriched Text into something similar to HTML
// Very basic at the moment, only supports some functionality and dumps the rest
// FIXME: fix colours: <color><param>FFFF,C2FE,0374</param>some text </color>
function etf2HTML ( $content ) {

	$search = array(
		'/<bold>/',
		'/<\/bold>/',
		'/<underline>/',
		'/<\/underline>/',
		'/<italic>/',
		'/<\/italic>/',
		'/<fontfamily><param>.*<\/param>/',
		'/<\/fontfamily>/',
		'/<x-tad-bigger>/',
		'/<\/x-tad-bigger>/',
		'/<bigger>/',
		'</bigger>/',
		'/<color>/',
		'/<\/color>/',	
		'/<param>.+<\/param>/'
	);
	
	$replace = array (
		'<b>',
		'</b>',
		'<u>',
		'</u>',
		'<i>',
		'</i>',
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		''
	);
		// strip extra line breaks
		$content = preg_replace($search,$replace,$content);
		return trim($content);
}


// This function cleans up HTML in the e-mail
function HTML2HTML ( $content ) {
	$search = array(
		'/<html>/',
		'/<\/html>/',
		'/<title>/',
		'/<\/title>/',
		'/<body.*>/',
		'/<\/body>/',
		'/<head>/',
		'/<\/head>/',
		'/<meta content=.*>/',
		'/<!DOCTYPE.*>/',
		'/<img src=".*>/'
//		'/<img src="cid:(.*)" .*>/'
	);
	
	$replace = array (
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		'',
		''
	);
		// strip extra line breaks
		$content = preg_replace($search,$replace,trim($content));
		return ($content);
}



/**
  * Determines if the sender is a valid user.
  * @return integer|NULL
  */
function ValidatePoster( &$mimeDecodedEmail ) {
    global $wpdb;
    $config = GetConfig();
    $poster = NULL;
    $from = RemoveExtraCharactersInEmailAddress(trim($mimeDecodedEmail->headers["from"]));

	if ( empty($from) ) { 
        echo 'Invalid Sender - Emtpy! ';
        return;
    }

    //See if the email address is one of the special authorized ones
    print("Confirming Access For $from \n");
    $sql = 'SELECT id FROM '. $wpdb->users.' WHERE user_email=\'' . addslashes($from) . "' LIMIT 1;";
    $user_ID= $wpdb->get_var($sql);
    $user = new WP_User($user_ID);
    if ($config["TURN_AUTHORIZATION_OFF"] || CheckEmailAddress($from)) {
    	if (empty($user_ID)){
        	print("$from is authorized to post as the administrator\n");
       		$from = get_option("admin_email");
        	$poster = $wpdb->get_var("SELECT ID FROM $wpdb->users WHERE ID = 1");
	    }
	    else {
		    $poster = $user_ID;
	    }
    }
    else if ($user->has_cap("post_via_postie")) {
            $poster = $user_ID;
    }
    if (!$poster) {
        echo 'Invalid sender: ' . htmlentities($from) . "! Not adding email!\n";
        if ($config["FORWARD_REJECTED_MAIL"]) {
            if (ForwardRejectedMailToAdmin($mimeDecodedEmail)) { 
                echo "A copy of the message has been forwarded to the administrator.\n"; 
            } else {
                echo "The message was unable to be forwarded to the adminstrator.\n";
            }
        }
        return;
    } 
    return $poster;
}

/**
  * Looks at the content for the start of the message and removes everything before that
  * If the pattern is not found everything is returned
  * @param string
  * @param string
  */
function StartFilter(&$content,$start) {
    $pos = strpos($content,$start);
    if ( $pos === false) {
        return($content);
    }
    $content = substr($content, $pos + strlen($start), strlen($content));
}

/**
  * Looks at the content for the start of the signature and removes all text
  * after that point
  * @param string
  * @param array - a list of patterns to determine if it is a sig block
  */
function RemoveSignature( &$content,$filterList = array('--','- --' )) {
	$arrcontent = explode("\n", $content);
	$i = 0;
	for ($i = 0; $i<=count($arrcontent); $i++) {
		$line = $arrcontent[$i];
		$nextline = $arrcontent[$i+1];
        foreach ($filterList as $pattern) {
            if (preg_match("/^$pattern/",trim($line))) {
                //print("<p>Found in $line");
                break 2;
            }
        } 
		$strcontent .= $line ."\n";
	}
    $content = $strcontent;
}
/**
  * Looks at the content for the given tag and removes all text
  * after that point
  * @param string
  * @param filter
  */
function EndFilter( &$content,$filter) {
	$arrcontent = explode("\n", $content);
	$i = 0;
	for ($i = 0; $i<=count($arrcontent); $i++) {
		$line = $arrcontent[$i];
		$nextline = $arrcontent[$i+1];
        if (preg_match("/^$filter/",trim($line))) {
            //print("<p>Found in $line");
            break;
        }
		$strcontent .= $line ."\n";
	}
    $content = $strcontent;
}

//filter content for new lines
function FilterNewLines ( $content ) {
		$search = array (
			"/\r\n/",
			"/\r/",
			"/\n\n/",
			"/\n/"
		);
		$replace = array (
            "\n",
            "\n",
            'ACTUAL_NEW_LINE',
			' '
		);
		// strip extra line breaks
        $result = preg_replace($search,$replace,$content);
        return(preg_replace('/ACTUAL_NEW_LINE/',"\n",$result));
}

//strip pgp stuff
function StripPGP ( $content ) {
		$search = array (
			'/-----BEGIN PGP SIGNED MESSAGE-----/',
			'/Hash: SHA1/'
		);
		$replace = array (
			' ',
			''
		);
		// strip extra line breaks
		$return = preg_replace($search,$replace,$content);
		return $return;
}

function ConvertToISO_8859_1($encoding,$charset, &$body ) {
    $config = GetConfig();
    $charset = strtolower($charset);
    $encoding = strtolower($encoding);
    if( (strtolower($config["MESSAGE_ENCODING"]) == "iso-8859-1") && (strtolower($charset) != 'iso-8859-1')) {
	    if( $encoding == 'base64' || $encoding == 'quoted-printable' ) {
		    $body = utf8_decode($body);
	    }
    }
}
function IsISO88591Blog() {
    $config = GetConfig();
    if( (strtolower($config["MESSAGE_ENCODING"]) == "iso-8859-1")) {
        return(true);
    }
    return(false);
}
function IsUTF8Blog() {
    $config = GetConfig();
    if( (strtolower($config["MESSAGE_ENCODING"]) == "utf-8")) {
        return(true);
    }
    return(false);
}
function HandleMessageEncoding($encoding, $charset,&$body) {
    $charset = strtolower($charset);
    $encoding = strtolower($encoding);
    HandleQuotedPrintable($encoding, $body);
    if (isISO88591Blog()) {
        ConvertToISO_8859_1($encoding,$charset,$body);
    }
    else {
        ConvertToUTF_8($encoding,$charset,$body);
    }
}
function ConvertToUTF_8($encoding,$charset,&$body) {
    $charset = strtolower($charset);
    $encoding = strtolower($encoding);
    switch($charset) {
        case "iso-8859-1":
            $body = utf8_encode($body);
            break;
        case "iso-2022-jp":
            $body = iconv("ISO-2022-JP//TRANSLIT","UTF-8",$body);
            break;
    }
}

/**
  * This function handles decoding base64 if needed
  */
function DecodeBase64Part( &$part ) {
    if ( strtolower($part->headers['content-transfer-encoding']) == 'base64' ) {
        $part->body = base64_decode($part->body);
    }
}

function HandleQuotedPrintable($encoding, &$body ) {
    $config = GetConfig();
    if ( $config["MESSAGE_DEQUOTE"] && strtolower($encoding) == 'quoted-printable' ) {
			$body = quoted_printable_decode($body);
    }
}


function GenerateImageFileName($dir,$type) {
    static $ctr;
    $config = GetConfig();
    $ctr++;
    $type = strtolower($type);
    if ($type == "jpeg"
            || $type = "pjpeg") {
        $type = "jpg";
    }
    if ($config["TEST_EMAIL"]) {
        return($dir . "TEST-" . date("Ymd-His-",time()) . $ctr . "." . $type);
    }
    else {
        return($dir . date("Ymd-His-",time()) . $ctr . "." . $type);
    }
}
function ConfirmTrailingDirectorySeperator($string) {
    if (substr($string,strlen($string) - 1,1) == DIRECTORY_SEPARATOR) {
        return(true);
    }
    return(false);
}
/**
  * This function handles figuring out the size of the image
  *@return array  - array(width,height)
*/
function DetermineImageSize($file) {
    $config = GetConfig();
    if ($config["USE_IMAGEMAGICK"]) {
        return(DetermineImageSizeWithImageMagick($file));
    }
    else {
        return(DetermineImageSizeWithGD($file));
    }
    return($size);
}
/**
  * This function handles figuring out the size of the image
  *@return array  - array(width,height)
*/
function DetermineImageSizeWithImageMagick($file) {
    $config = GetConfig();
    $size = array(0,0);
    if (file_exists($config["IMAGEMAGICK_IDENTIFY"])) {
        $geometry = @exec (escapeshellcmd($config["IMAGEMAGICK_IDENTIFY"]) . 
                           " -ping " .
                           escapeshellarg($file));
        preg_match("/([0-9]+)x([0-9]+)/",$geometry,$matches);
        if (isset($matches[1])) {
            $size[0] = $matches[1];
        }
        if (isset($matches[2])) {
            $size[1] = $matches[2];
        }
    }
    return($size);
}
/**
  * This function handles figuring out the size of the image
  *@return array  - array(width,height)
*/
function DetermineImageSizeWithGD($file) {
    return(getimagesize($file));
}

function ResizeImage($file,$type) {
    $config = GetConfig();
    $sizeInfo = DetermineImageSize($file);
    $fileName = basename($file);
    if (DetermineScale($sizeInfo[0],$sizeInfo[1],$config["MAX_IMAGE_WIDTH"], $config["MAX_IMAGE_HEIGHT"]) != 1) {
        if ($config["USE_IMAGEMAGICK"]) {
            return(ResizeImageWithImageMagick($file,$type));
        }
        else {
            return(ResizeImageWithGD($file,$type));
        }
    }
    return(array("",$fileName));

}
function RotateImages($rotation,$imageList) {
    $config = GetConfig();
    foreach ($imageList as $data) {
        if ($config["USE_IMAGEMAGICK"]) {
            if ($data[0]) {
                RotateImageWithImageMagick($data[0],$data[2],$rotation);
            }
            RotateImageWithImageMagick($data[1],$data[2],$rotation);
        }
        else {
            if ($data[0]) {
                RotateImageWithGD($data[0],$data[2],$rotation);
            }
            RotateImageWithGD($data[1],$data[2],$rotation);
        }
    }
}
function ImageMagickSharpen($source,$dest = null) {
    $config = GetConfig();
    if (!$dest) {
        $dest = $source;
    }
    @exec (escapeshellcmd($config["IMAGEMAGICK_CONVERT"]) .  " ".
             escapeshellarg($source) . " ".
                '\( +clone -modulate 100,0 \) \( +clone -unsharp 0x1+200+0 \) \( -clone 0 -edge 3 -colorspace GRAY -colors 256 -level 20%,95% -gaussian 10 -level 10%,95% \) -colorspace RGB -fx "u[0]+(((u[2]+1)/(u[1]+1))-1)*u[0]*u[3]" ' .
                escapeshellarg($dest) );
    @exec ('chmod 755 ' . escapeshellarg($dest));

}
function RotateImageWithImageMagick($file,$type,$rotation) {
    $config = GetConfig();
    @exec (escapeshellcmd($config["IMAGEMAGICK_CONVERT"]) . 
                " -rotate " .
                escapeshellarg($rotation) .
                " " .
                escapeshellarg($file) .
                " " .
                escapeshellarg($file) );
    @exec ('chmod 755 ' . escapeshellarg($file));
}
function RotateImageWithGD($file,$type,$rotation) {
    $config = GetConfig();
    $fileName = basename($file);
        $sourceImage = NULL;
        
        switch($type) {
            case "jpeg":
            case "jpg":
            case "pjpeg":
                $typePrefix = "jpeg";
                break;
            case "gif":
                $typePrefix = "gif";
                break;
            case "png":
                $typePrefix = "png";
                break;
            default:
                $typePrefix = NULL;
                break;
        }
        if ($typePrefix) {
            eval ('$sourceImage = imagecreatefrom'.$typePrefix.'($file);');
            if (function_exists("imagerotate")) {
                $rotatedImage = imagerotate($sourceImage,$rotation,0);
            }
            else {
                $rotatedImage = CustomImageRotate($sourceImage,$rotation);
            }
            eval ('image'.$typePrefix.'($rotatedImage,$file);');
            imagedestroy($sourceImage);
            @exec ('chmod 755 ' . escapeshellarg($file));
        }
}
/**
  * This function handles rotating in GD when you do not have imagerotate available 
  * Writen byu wulff at fyens dot dk
  * From http://us2.php.net/manual/en/function.imagerotate.php#50487
  */
// $src_img - a GD image resource
// $angle - degrees to rotate clockwise, in degrees
// returns a GD image resource
// USAGE:
// $im = imagecreatefrompng('test.png');
// $im = imagerotate($im, 15);
// header('Content-type: image/png');
// imagepng($im);
function CustomImageRotate($src_img, $angle, $bicubic=false) {
 
   // convert degrees to radians
   $angle = $angle + 180;
   $angle = deg2rad($angle);
 
   $src_x = imagesx($src_img);
   $src_y = imagesy($src_img);
 
   $center_x = floor($src_x/2);
   $center_y = floor($src_y/2);

   $cosangle = cos($angle);
   $sinangle = sin($angle);

   $corners=array(array(0,0), array($src_x,0), array($src_x,$src_y), array(0,$src_y));

   foreach($corners as $key=>$value) {
     $value[0]-=$center_x;        //Translate coords to center for rotation
     $value[1]-=$center_y;
     $temp=array();
     $temp[0]=$value[0]*$cosangle+$value[1]*$sinangle;
     $temp[1]=$value[1]*$cosangle-$value[0]*$sinangle;
     $corners[$key]=$temp;   
   }
  
   $min_x=1000000000000000;
   $max_x=-1000000000000000;
   $min_y=1000000000000000;
   $max_y=-1000000000000000;
  
   foreach($corners as $key => $value) {
     if($value[0]<$min_x)
       $min_x=$value[0];
     if($value[0]>$max_x)
       $max_x=$value[0];
  
     if($value[1]<$min_y)
       $min_y=$value[1];
     if($value[1]>$max_y)
       $max_y=$value[1];
   }

   $rotate_width=round($max_x-$min_x);
   $rotate_height=round($max_y-$min_y);

   $rotate=imagecreatetruecolor($rotate_width,$rotate_height);
   imagealphablending($rotate, false);
   imagesavealpha($rotate, true);

   //Reset center to center of our image
   $newcenter_x = ($rotate_width)/2;
   $newcenter_y = ($rotate_height)/2;

   for ($y = 0; $y < ($rotate_height); $y++) {
     for ($x = 0; $x < ($rotate_width); $x++) {
       // rotate...
       $old_x = round((($newcenter_x-$x) * $cosangle + ($newcenter_y-$y) * $sinangle))
         + $center_x;
       $old_y = round((($newcenter_y-$y) * $cosangle - ($newcenter_x-$x) * $sinangle))
         + $center_y;
    
       if ( $old_x >= 0 && $old_x < $src_x
             && $old_y >= 0 && $old_y < $src_y ) {

           $color = imagecolorat($src_img, $old_x, $old_y);
       } else {
         // this line sets the background colour
         $color = imagecolorallocatealpha($src_img, 255, 255, 255, 127);
       }
       imagesetpixel($rotate, $x, $y, $color);
     }
   }
  
  return($rotate);
}
function DetermineScale($width,$height, $max_width, $max_height) {
    if (!empty($max_width)) {
            return($max_width/$width);
    }
    else if (!empty($max_height)) {
            return($max_height/$height);
    }
    return(1);
}

function ResizeImageWithImageMagick($file,$type) {
    //print("<h1>Using ImageMagick</h1>");
    $config = GetConfig();
    $sizeInfo = DetermineImageSize($file);
    $fileName = basename($file);
    $scaledFileName = "";
    $scale = DetermineScale($sizeInfo[0],$sizeInfo[1],$config["MAX_IMAGE_WIDTH"], $config["MAX_IMAGE_HEIGHT"]);
    if ($scale != 1) {
            $scaledH = round($sizeInfo[1] * $scale );
            $scaledW = round($sizeInfo[0] * $scale );
            $scaledFileName =  "thumb.".$fileName;
            $scaledFile = $config["REALPHOTOSDIR"] . $scaledFileName;
            @exec (escapeshellcmd($config["IMAGEMAGICK_CONVERT"]) . 
                        " -resize " .
                        $scaledW .
                        "x" . 
                        $scaledH  . 
                        " " .
                        escapeshellarg($file) .
                        " " .
                        escapeshellarg($scaledFile) );

            @exec ('chmod 755 ' . escapeshellarg($scaledFile));
    }
    return(array($scaledFileName,$fileName));

}
function ResizeImageWithGD($file,$type) {
    $config = GetConfig();
    $sizeInfo = DetermineImageSize($file);
    $fileName = basename($file);
    $scaledFileName = "";
    $scale = DetermineScale($sizeInfo[0],$sizeInfo[1],$config["MAX_IMAGE_WIDTH"], $config["MAX_IMAGE_HEIGHT"]);
    if ($scale != 1) {
        $sourceImage = NULL;
        switch($type) {
            case "jpeg":
            case "jpg":
            case "pjpeg":
                $sourceImage = imagecreatefromjpeg($file);
                break;
            case "gif":
                $sourceImage = imagecreatefromgif($file);
                break;
            case "png":
                $sourceImage = imagecreatefrompng($file);
                break;
        }
        if ($sourceImage) {
            $scaledH = round($sizeInfo[1] * $scale );
            $scaledW = round($sizeInfo[0] * $scale );
            $scaledFileName =  "thumb.".$fileName;
            $scaledFile = $config["REALPHOTOSDIR"] . $scaledFileName;
            $scaledImage = imagecreatetruecolor($scaledW,$scaledH);
            imagecopyresized($scaledImage,$sourceImage,0,0,0,0,
                            $scaledW,$scaledH,
                            $sizeInfo[0],$sizeInfo[1]);
			imagejpeg($scaledImage,$scaledFile,$config["JPEGQUALITY"]);
            @exec ('chmod 755 ' . escapeshellarg($scaledFile));
            imagedestroy($scaledImage);
            imagedestroy($sourceImage);
        }
    }
    return(array($scaledFileName,$fileName));

}
/**
  * Checks for the comments tag
  * @return boolean
  */
function AllowCommentsOnPost(&$content) {
    $comments_allowed = get_option('default_comment_status');
    if (eregi("comments:([0|1|2])",$content,$matches)) {
        $content = ereg_replace("comments:$matches[1]","",$content);
        if ($matches[1] == "1") {
            $comments_allowed = "open";
        }
        else if ($matches[1] == "2") {
            $comments_allowed = "registered_only";
        }
        else {
            $comments_allowed = "closed";
        }
    }
    return($comments_allowed);
}
/**
  * This function figures out how much rotation should be applied to all images in the message
  */
function GetRotation(&$mimeDecodedEmail,&$content) {
    $rotation = 0;
    if (eregi("rotate:([0-9]+)",$content,$matches)
        && trim($matches[1])) {
        $delay = (($days * 24 + $hours) * 60 + $minutes) * 60;
        $rotation = $matches[1];
        $content = ereg_replace("rotate:$matches[1]","",$content);
    }
    return($rotation);
}
/**
  * Needed to be able to modify the content to remove the usage of the delay tag
  */
function DeterminePostDate(&$content, $message_date = NULL) {
    $config = GetConfig();
    $delay = 0;
    if (eregi("delay:([0-9dhm]+)",$content,$matches)
        && trim($matches[1])) {
        if (eregi("([0-9]+)d",$matches[1],$dayMatches)) {
            $days = $dayMatches[1];
        }
        if (eregi("([0-9]+)h",$matches[1],$hourMatches)) {
            $hours = $hourMatches[1];
        }
        if (eregi("([0-9]+)m",$matches[1],$minuteMatches)) {
            $minutes = $minuteMatches[1];
        }
        $delay = (($days * 24 + $hours) * 60 + $minutes) * 60;
        $content = ereg_replace("delay:$matches[1]","",$content);
    }
    if (!empty($message_date)) {
        $dateInSeconds = strtotime($message_date);
    }
    else {
        $dateInSeconds = time() + $delay;
    }
    $post_date = gmdate('Y-m-d H:i:s',$dateInSeconds + ($config["TIME_OFFSET"] * 3600));
    $post_date_gmt = gmdate('Y-m-d H:i:s',$dateInSeconds);

    return(array($post_date,$post_date_gmt));
}
/**
  * This function takes the content of the message - looks for a subject at the begining surrounded by # and then removes that from the content
  */
function ParseInMessageSubject($content) {
    $config = GetConfig();
    if (substr($content,0,1) != "#") {
        //print("<p>Didn't start with # '".substr(ltrim($content),0,10)."'");
        return(array($config["DEFAULT_TITLE"],$content));
    }
    $subjectEndIndex = strpos($content,"#",1);
    if (!$subjectEndIndex > 0) {
        return(array($config["DEFAULT_TITLE"],$content));
    }
    $subject = substr($content,1,$subjectEndIndex - 1);
    $content = substr($content,$subjectEndIndex + 1,strlen($content));
    return(array($subject,$content));
}
/**
  * This method sorts thru the mime parts of the message. It is looking for files labeled - "applefile" - current
  * this part of the file attachment is not supported
  *@param object
  */
function FilterAppleFile(&$mimeDecodedEmail) {
    $newParts = array();
    $found = false;
    for ($i = 0; $i < count($mimeDecodedEmail->parts); $i++)  {
        if ($mimeDecodedEmail->parts[$i]->ctype_secondary == "applefile") {
            $found = true;
        }
        else {
            $newParts[] = &$mimeDecodedEmail->parts[$i];
        }
    }
    if ($found && $newParts) {
        $mimeDecodedEmail->parts = $newParts; //This is now the filtered list of just the preferred type.
    }
}
/**
  * Searches for the existance of a certain MIME TYPE in the tree of mime attachments
  * @param primary mime
  * @param secondary mime
  * @return boolean
  */
function SearchForMIMEType($part,$primary,$secondary) {
    if ($part->ctype_primary == $primary && $part->ctype_secondary == $secondary) {
            return true;
    }
    if ($part->ctype_primary == "multipart") {
        for ($i = 0; $i < count($part->parts); $i++) {
            if (SearchForMIMEType($part->parts[$i], $primary,$secondary)) {
                return true;
            }
        }
    }
    return false;
}
/**
  * This method sorts thru the mime parts of the message. It is looking for a certain type of text attachment. If 
  * that type is present it filters out all other text types. If it is not - then nothing is done
  *@param object
  */
function FilterTextParts(&$mimeDecodedEmail) {
    $config = GetConfig();
    $newParts = array();
    $found = false;
    for ($i = 0; $i < count($mimeDecodedEmail->parts); $i++)  {
        if (in_array($mimeDecodedEmail->parts[$i]->ctype_primary,array("text","multipart"))) {
            if (SearchForMIMEType($mimeDecodedEmail->parts[$i],"text",$config["PREFER_TEXT_TYPE"])) {
            $newParts[] = &$mimeDecodedEmail->parts[$i];
            $found = true;
            }
        }
        else {
            $newParts[] = &$mimeDecodedEmail->parts[$i];
        }
    }
    if ($found && $newParts) {
        $mimeDecodedEmail->parts = $newParts; //This is now the filtered list of just the preferred type.
    }
}
/**
  *This forwards on the mail to the admin for review
  *It execpts an object containing the entire message
  */
function ForwardRejectedMailToAdmin( &$mail_content) {
    $config = GetConfig();
    if ($config["TEST_EMAIL"]) {
        return;
    }
	$user = get_userdata('1');
	$myname = $user->user_nicename;
	$myemailadd = get_option("admin_email");
	$blogname = get_option("blogname");
	$recipients = $myemailadd;
	if (count($recipients) == 0) {
		return false;
	}

    $from = trim($mail_content->headers["from"]);
    $subject = $mail_content->headers['subject'];
    
	// Set email subject
	$alert_subject = $blogname . ": Unauthorized Post Attempt";

	// Set sender details
	$headers = "From: " .$from ."\r\n";
    if (isset($mail_content->headers["mime-version"])) {
        $headers .= "Mime-Version: ". $mail_content->headers["mime-version"] . "\r\n";
    }
    if (isset($mail_content->headers["content-type"])) {
        $headers .= "Content-Type: ". $mail_content->headers["content-type"] . "\r\n";
    }

	// SDM 20041123
	foreach ($recipients as $recipient) {
		$recipient = trim($recipient);
		if (! empty($recipient)) {
			$headers .= "Bcc: " . $recipient . "\r\n";
		}
	}

	// construct mail message
	$message = "An unauthorized message has been sent to " . $blogname . " from " . $from. ". The subject of this message was: '" . $subject . "'.";
	$message .= "\n\nIf you wish to allow posts from this address, please add " . $from. " to the registered users list and manually add the content of the e-mail found below.";
	$message .= "\n\nOtherwise, the e-mail has already been deleted from the server and you can ignore this message.";
	$message .= "\n\nIf you would like to prevent wp-mail from forwarding mail in the future, please change FORWARD_REJECTED_MAIL to false in wp-mail.php."; 
	$message .= "\n\nThe original content of the e-mail has been attached.\n\n";
    $boundary = "--".$mail_content->ctype_parameters["boundary"] ."\n";

    $mailtext = $boundary;
    $mailtext .= "Content-Type: text/plain;format=flowed;charset=\"iso-8859-1\";reply-type=original\n";
    $mailtext .= "Content-Transfer-Encoding: 7bit\n";
    $mailtext .= "\n";
    $mailtext .= $message;
    foreach ($mail_content->parts as $part) {
        $mailtext .= $boundary;
        $mailtext .= "Content-Type: ".$part->headers["content-type"] . "\n";
        $mailtext .= "Content-Transfer-Encoding: ".$part->headers["content-transfer-encoding"] . "\n";
        if (isset($part->headers["content-disposition"])) {
            $mailtext .= "Content-Disposition: ".$part->headers["content-disposition"] . "\n";
        }
        $mailtext .= "\n";
        $mailtext .= $part->body;
    }
	
	// Send message
	mail($myemailadd, $alert_subject, $mailtext, $headers);

	return true;
}
/**
  * This function handles the basic mime decoding
  * @param string
  * @return array
  */
function DecodeMIMEMail($email) {
    $params = array();
    $params['include_bodies'] = true;
    $params['decode_bodies'] = false;
    $params['decode_headers'] = true;
    $params['input'] = $email;
    return(Mail_mimeDecode::decode($params));
}
    
/**
  * This is used for debugging the mimeDecodedEmail of the mail
  */
function DisplayMIMEPartTypes($mimeDecodedEmail) {
    foreach($mimeDecodedEmail->parts as $part) {
        print("<p>".$part->ctype_primary . " / ".$part->ctype_secondary . "/ ".$part->headers['content-transfer-encoding'] ."\n");
    }
}

/**
  * This compares the current address to the list of authorized addresses
  * @param string - email address
  * @return boolean
  */
function CheckEmailAddress($address) {
    $config = GetConfig();
    $address = strtolower($address);
    if (!is_array($config["AUTHORIZED_ADDRESSES"])
            || !count($config["AUTHORIZED_ADDRESSES"])) {
        return false;
    }
    return(in_array($address,$config["AUTHORIZED_ADDRESSES"]));
}
/**
  *This method works around a problemw with email address with extra <> in the email address
  * @param string
  * @return string
  */
function RemoveExtraCharactersInEmailAddress($address) {
    $matches = array();
    if (preg_match('/^[^<>]+<([^<> ()]+)>$/',$address,$matches)) {
        $address = $matches[1];
    }
    else if (preg_match('/<([^<> ()]+)>/',$address,$matches)) {
        $address = $matches[1];
    }

    return($address);
}

/**
  * When sending in HTML email the html refers to the content-id(CID) of the image - this replaces
  * the cid place holder with the actual url of the image sent in
  * @param string - text of post
  * @param array - array of HTML for images for post
  */
function ReplaceImageCIDs(&$content,&$attachments) {
    $used = array();
    foreach ($attachments["cids"] as $key => $info) {
        $pattern = "/cid:$key/";
        if(preg_match($pattern,$content)) {
            $content = preg_replace($pattern,$info[0],$content);
            $used[] = $info[1]; //Index of html to ignore
        }
    }
    $html = array();
    for ($i = 0; $i < count($attachments["html"]); $i++) {
        if (!in_array($i,$used)) {
            $html[] = $attachments["html"][$i];
        }
    }
    $attachments["html"] = $html;

}
/**
  * This function handles replacing image place holder #img1# with the HTML for that image
  * @param string - text of post
  * @param array - array of HTML for images for post
  */
function ReplaceImagePlaceHolders(&$content,$attachments) {
    $config = GetConfig();
    ($config["START_IMAGE_COUNT_AT_ZERO"] ? $startIndex = 0 :$startIndex = 1);
    foreach ( $attachments as $i => $value ) {
        // looks for ' #img1# ' etc... and replaces with image
        $img_placeholder_temp = str_replace("%", intval($startIndex + $i), $config["IMAGE_PLACEHOLDER"]);
        if ( stristr($content, $img_placeholder_temp) ) {
            $content = str_replace($img_placeholder_temp, $value, $content);
        }
        else {
            if ($config["IMAGES_APPEND"]) {
                $content .= $value;
            }
            else {
                $content = $value . $content;
            }
        }
    }
}
/**
  *This function handles finding and setting the correct subject
  * @return array - (subject,content)
  */
function GetSubject(&$mimeDecodedEmail,&$content) {
    $config = GetConfig();
    //assign the default title/subject
    if ( $mimeDecodedEmail->headers['subject'] == NULL ) {
        if ($config["ALLOW_SUBJECT_IN_MAIL"]) {
            list($subject,$content) = ParseInMessageSubject($content);
        }
        else {
            $subject = $config["DEFAULT_TITLE"];
        }
        $mimeDecodedEmail->headers['subject'] = $subject;
    } else {	
        $subject = $mimeDecodedEmail->headers['subject'];
        HandleMessageEncoding($mimeDecodedEmail->headers["content-transfer-encoding"],
                              $mimeDecodedEmail->ctype_parameters["charset"],
                              $subject);
        if (!$config["ALLOW_HTML_IN_SUBJECT"]) {
            $subject = htmlentities($subject);
        }
    }
    //This is for ISO-2022-JP - Can anyone confirm that this is still neeeded?
     // escape sequence is 'ESC $ B' == 1b 24 42 hex.
    if (strpos($subject, "\x1b\x24\x42") !== false) {
        // found iso-2022-jp escape sequence in subject... convert!
        $subject = iconv("ISO-2022-JP//TRANSLIT", "UTF-8", $subject);
    }
    return($subject);
}
/**
  * This function determines categories for the post
  * @return array
  */
function GetPostCategories(&$subject) {
    $config = GetConfig();
    global $wpdb;
    $post_categories = array();
    $matches = array();
    //try and determine category
    if ( preg_match('/(.+): (.*)/', $subject, $matches))  {
        $subject = trim($matches[2]);
        $matches[1] = array($matches[1]);
    }
    else if (preg_match_all('/\[(.[^\[]*)\]/', $subject, $matches)) {
        preg_match("/](.[^\[]*)$/",$subject,$subject_matches);
        $subject = trim($subject_matches[1]);
    }
    else if ( preg_match_all('/-(.[^-]*)-/', $subject, $matches) ) {
        preg_match("/-(.[^-]*)$/",$subject,$subject_matches);
        $subject = trim($subject_matches[1]);
    }
    if (count($matches)) {
        foreach($matches[1] as $match) {
            $match = trim($match);
            $category = NULL;
            print("Working on $match\n"); 
            //Work on the category search to see if we can determine the cat_id	
            //check the database to see if their is a category similar
            $sql_name = 'SELECT cat_ID 
                         FROM ' . $wpdb->categories. ' 
                         WHERE cat_name=\'' . addslashes($match) . '\'';
            $sql_id = 'SELECT cat_ID 
                       FROM ' . $wpdb->categories. ' 
                       WHERE cat_ID=\'' . addslashes($match) . '\'';
            $sql_sub_name = 'SELECT cat_ID 
                             FROM ' . $wpdb->categories. ' 
                             WHERE cat_name LIKE \'' . addslashes($match) . '%\' limit 1';

                
            if ( $category = $wpdb->get_var($sql_name) ) {
                //then category is a named and found 
            } elseif ( $category = $wpdb->get_var($sql_id) ) {
                //then cateogry was an ID and found 
            } elseif ( $category = $wpdb->get_var($sql_sub_name) ) {
                //then cateogry is a start of a name and found
            }  
        
            if ($category) {
                $post_categories[] = $category;
            }
        }
    }
    if (!count($post_categories)) {
        $post_categories[] =  $config["DEFAULT_POST_CATEGORY"];
    }
    return($post_categories);
}
/**
  *This function just outputs a simple html report about what is being posted in
  */
function DisplayEmailPost($details) {
            // Report
            print '</pre><p><b>Post Author</b>: ' . $details["post_author"]. '<br />' . "\n";
            print '<b>Date</b>: ' . $details["post_date"] . '<br />' . "\n";
            print '<b>Date GMT</b>: ' . $details["post_date_gmt"] . '<br />' . "\n";
            foreach($details["post_categories"] as $category) {
                print '<b>Category</b>: ' . $category . '<br />' . "\n";
            }
            print '<b>Ping Status</b>: ' . $details["ping_status"] . '<br />' . "\n";
            print '<b>Comment Status</b>: ' . $details["comment_status"] . '<br />' . "\n";
            print '<b>Subject</b>: ' . $details["post_title"]. '<br />' . "\n";
            print '<b>Postname</b>: ' . $details["post_name"] . '<br />' . "\n";
            print '<b>Posted content:</b></p><hr />' . $details["post_content"] . '<hr /><pre>';
}
/**
  * This function confirms that everything is setup correctly
  */
function TestWPMailInstallation() {
    $config = GetConfig();
    IsWritableDirectory($config["REALPHOTOSDIR"]);
    IsWritableDirectory($config["REALFILESDIR"]);
    if (!TestPostieDirectory) {
        print("<p>Postie should be in its own directory in wp-content/plugins/postie</p>");
    }
}
/**
  * Takes a value and builds a simple simple yes/no select box
  * @param string
  * @param string
  * @param string
  * @param string
  */
function BuildBooleanSelect($label,$id,$current_value,$recommendation = NULL) {
   return("<tr>
	<th scope=\"row\">". __($label).":</th>
	<td><select name=\"$id\" id=\"$id\">
    <option value=\"1\">".__("Yes")."</option>
    <option value=\"0\" ". (!$current_value ? "SELECTED" : NULL) . ">".__("No").'</option>
	</select>
    <br /><code>'.__($recommendation).'</code><br/>
    </td>
	</tr>');
}
/**
  * This takes an array and display a text box for editing
  *@param string
  *@param string
  *@param array
  *@param string
  */
function BuildTextArea($label,$id,$current_value,$recommendation = NULL) {
   $string = "<tr>
	<th scope=\"row\">".__($label).":</th></tr>";

    if ($recommendation) {
        $string .= "<tr><td>&nbsp;</td><td><code>".__($recommendation)."</code></td></tr>";
    }
   $string .=" <tr>
    <td>&nbsp;</td>
	<td><textarea cols=40 rows=5 name=\"$id\" id=\"$id\">";
        if (is_array($current_value)) {
            foreach($current_value as $item) {
                $string .= "$item\n";
            }
        }
    $string .= "</textarea></td>
	</tr>";
    return($string);
}
/**
  *Handles the creation of the table needed to store all the data
  */
function SetupConfiguration() {
    if (! function_exists('maybe_create_table')) {
        require_once(ABSPATH . DIRECTORY_SEPARATOR. 'wp-admin'.DIRECTORY_SEPARATOR.'upgrade-functions.php');
    }
    $create_table_sql = "CREATE TABLE ".POSTIE_TABLE ." (
         label text NOT NULL,
         value text not NULL
             );";

    maybe_create_table(POSTIE_TABLE,$create_table_sql);
}
/**
  *This function resets all the configuration options to the default
  */
function ResetPostieConfig() {
	global $wpdb;
    //Get rid of the old table
    $wpdb->query("DROP TABLE ". POSTIE_TABLE .";");
    $config = GetConfig();
    $key_arrays = GetListOfArrayConfig();
    foreach($key_arrays as $key) {
        $config[$key] = join("\n",$config[$key]);
    }
    UpdatePostieConfig($config);
}
/**
  * This function handles updating the configuration 
  *@return boolean
  */
function UpdatePostieConfig($data) {
    SetupConfiguration();
    $key_arrays = GetListOfArrayConfig();
    $config = GetDBConfig();
    foreach($config as $key => $value) {
        if (isset($data[$key])) {
            if (in_array($key,$key_arrays)) { //This is stored as an array
                $config[$key] = array();
                $values = explode("\n",$data[$key]);
                foreach($values as $item) {
                    if (trim($item)) {
                        $config[$key][] = trim($item);
                    }
                }
            }
            else {
                $config[$key] = $data[$key];
            }
        }
    }
    WriteConfig($config);
    UpdatePostiePermissions($data["ROLE_ACCESS"]);
    return(1);
}
/**
  * This handles actually writing out the changes
  *@param array
  */
function WriteConfig($config) {
    global $wpdb;
    foreach($config as $key=>$value) {
        $label = apply_filters('content_save_pre', $key);
        $q = $wpdb->query("DELETE FROM ". POSTIE_TABLE . " WHERE label = '$label';");
        if (!is_array($value)) {
            $q = $wpdb->query("INSERT INTO ". POSTIE_TABLE . " (label,value) VALUES ('$label','".apply_filters('content_save_pre', $value)."');");
        }
        else {
            foreach($value as $item) {
                $q = $wpdb->query("INSERT INTO ". POSTIE_TABLE . " (label,value) VALUES ('$label','".apply_filters('content_save_pre', $item)."');");
            }
        }
    }
}
/**
  *This handles actually reading the config from the database
  * @return array
  */
function ReadDBConfig() {
    SetupConfiguration();
    $config = array();
    global $wpdb;
    $data = $wpdb->get_results("SELECT label,value FROM ". POSTIE_TABLE .";");
    if (is_array($data)) {
        foreach($data as $row) {
            if (in_array($row->label,GetListOfArrayConfig())) {
                if (!is_array($config[$row->label])) {
                    $config[$row->label] = array();
                }
                $config[$row->label][] = $row->value;
            }
            else {
                $config[$row->label] = $row->value;
            }
        }
    }

    return($config);
}
/**
  * This handles the configs that are stored in the data base
  * You should never call this outside of the library
  * @return array
  * @access private
  */
function GetDBConfig() {
    $config = ReadDBConfig();
    if (!isset($config["PHOTOSDIR"])) { $config["PHOTOSDIR"] = DIRECTORY_SEPARATOR."wp-photos".DIRECTORY_SEPARATOR;}
    if (!isset($config["FILESDIR"])) { $config["FILESDIR"] = DIRECTORY_SEPARATOR."wp-filez".DIRECTORY_SEPARATOR;}
    if (!isset($config["PREFER_TEXT_TYPE"])) { $config["PREFER_TEXT_TYPE"] = "plain";}
    if (!isset($config["RESIZE_LARGE_IMAGES"])) { $config["RESIZE_LARGE_IMAGES"] = true;}
    if (!isset($config["MAX_IMAGE_WIDTH"])) { $config["MAX_IMAGE_WIDTH"] = 400;}
    if (!isset($config["MAX_IMAGE_HEIGHT"])) { $config["MAX_IMAGE_HEIGHT"] = "";}
    if (!isset($config["DEFAULT_TITLE"])) { $config["DEFAULT_TITLE"] = "Live From The Field";}
    if (!isset($config["INPUT_PROTOCOL"])) { $config["INPUT_PROTOCOL"] = "pop3";}
    if (!isset($config["IMAGE_PLACEHOLDER"])) { $config["IMAGE_PLACEHOLDER"] = "#img%#";}
    if (!isset($config["IMAGES_APPEND"])) { $config["IMAGES_APPEND"] = true;}
    if (!isset($config["IMAGECLASS"])) { $config["IMAGECLASS"] = "postie-image";}
    if (!isset($config["IMAGEDIV"])) { $config["IMAGEDIV"] = "postie-image-div";}
    if (!isset($config["3GPDIV"])) { $config["3GPDIV"] = "postie-3gp-div";}
    if (!isset($config["ATTACHMENTDIV"])) { $config["ATTACHMENTDIV"] = "postie-attachment-div";}
    if (!isset($config["3GPCLASS"])) { $config["3GPCLASS"] = "postie-video";}
    if (!isset($config["IMAGESTYLE"])) { $config["IMAGESTYLE"] = "border: none;";}
    if (!isset($config["JPEGQUALITY"])) { $config["JPEGQUALITY"] = 80;}
    if (!isset($config["AUTO_SMART_SHARP"])) { $config["AUTO_SMART_SHARP"] = false;}
    if (!isset($config["ALLOW_SUBJECT_IN_MAIL"])) { $config["ALLOW_SUBJECT_IN_MAIL"] = true;}
    if (!isset($config["DROP_SIGNATURE"])) { $config["DROP_SIGNATURE"] = true;}
    if (!isset($config["MESSAGE_START"])) { $config["MESSAGE_START"] = ":start";}
    if (!isset($config["MESSAGE_END"])) { $config["MESSAGE_END"] = ":end";}
    if (!isset($config["FORWARD_REJECTED_MAIL"])) { $config["FORWARD_REJECTED_MAIL"] = true;}
    if (!isset($config["ALLOW_HTML_IN_SUBJECT"])) { $config["ALLOW_HTML_IN_SUBJECT"] = true;}
    if (!isset($config["ALLOW_HTML_IN_BODY"])) { $config["ALLOW_HTML_IN_BODY"] = true;}
    if (!isset($config["START_IMAGE_COUNT_AT_ZERO"])) { $config["START_IMAGE_COUNT_AT_ZERO"] = false;}
    if (!isset($config["MESSAGE_ENCODING"])) { $config["MESSAGE_ENCODING"] = "UTF-8"; }
    if (!isset($config["MESSAGE_DEQUOTE"])) { $config["MESSAGE_DEQUOTE"] = true; }
    if (!isset($config["TURN_AUTHORIZATION_OFF"])) { $config["TURN_AUTHORIZATION_OFF"] = false;}
    if (!isset($config["USE_IMAGEMAGICK"])) { $config["USE_IMAGEMAGICK"] = false;}
    if (!isset($config["IMAGEMAGICK_CONVERT"])) { $config["IMAGEMAGICK_CONVERT"] = "/usr/bin/convert";}
    if (!isset($config["IMAGEMAGICK_IDENTIFY"])) { $config["IMAGEMAGICK_IDENTIFY"] = "/usr/bin/identify";}


    if (!isset($config["SIG_PATTERN_LIST"])) { $config["SIG_PATTERN_LIST"] = array('--','- --',"\?--");}
    if (!isset($config["BANNED_FILES_LIST"])) { $config["BANNED_FILES_LIST"] = array();}
    if (!isset($config["SUPPORTED_FILE_TYPES"])) { $config["SUPPORTED_FILE_TYPES"] = array("video","application");}
    if (!isset($config["AUTHORIZED_ADDRESSES"])) { $config["AUTHORIZED_ADDRESSES"] = array();}
    //if (!isset($config["MAIL_SERVER"])) { $config["MAIL_SERVER"] = get_option('mailserver_url'); }
    //if (!isset($config["MAIL_SERVER_PORT"])) { $config["MAIL_SERVER_PORT"] =  get_option('mailserver_port'); }
    //if (!isset($config["MAIL_USERID"])) { $config["MAIL_USERID"] =  get_option('mailserver_login'); }
    //if (!isset($config["MAIL_PASSWORD"])) { $config["MAIL_PASSWORD"] =  get_option('mailserver_pass'); }
    //if (!isset($config["DEFAULT_POST_CATEGORY"])) { $config["DEFAULT_POST_CATEGORY"] =  get_option('default_email_category'); }
    if (!isset($config["MAIL_SERVER"])) { $config["MAIL_SERVER"] = NULL; }
    if (!isset($config["MAIL_SERVER_PORT"])) { $config["MAIL_SERVER_PORT"] =  NULL; }
    if (!isset($config["MAIL_USERID"])) { $config["MAIL_USERID"] =  NULL; }
    if (!isset($config["MAIL_PASSWORD"])) { $config["MAIL_PASSWORD"] =  NULL; }
    if (!isset($config["DEFAULT_POST_CATEGORY"])) { $config["DEFAULT_POST_CATEGORY"] =  NULL; }
    if (!isset($config["TIME_OFFSET"])) { $config["TIME_OFFSET"] =  get_option('gmt_offset'); }
    if (!isset($config["3GP_QT"])) { $config["3GP_QT"] =  true; }
    if (!isset($config["3GP_FFMPEG"])) { $config["3GP_FFMPEG"] = "/usr/bin/ffmpeg";}
    return($config);
}
/**
  * This function handles building up the configuration array for the program
  * @return array
  */
function GetConfig() {
    $config = GetDBConfig();
    if (!ConfirmTrailingDirectorySeperator($config["PHOTOSDIR"])) {
        $config["PHOTOSDIR"] .= DIRECTORY_SEPARATOR;
    }
    if (!ConfirmTrailingDirectorySeperator($config["FILESDIR"])) {
        $config["FILESDIR"] .= DIRECTORY_SEPARATOR;
    }
    //These should only be modified if you are testing
    $config["DELETE_MAIL_AFTER_PROCESSING"] = true;
    $config["POST_TO_DB"] = true;
    $config["TEST_EMAIL"] = false;
    $config["TEST_EMAIL_ACCOUNT"] = "blog.test";
    $config["TEST_EMAIL_PASSWORD"] = "";
    //include(POSTIE_ROOT . "/../postie-test.php");
    // These are computed
    #$config["TIME_OFFSET"] = get_option('gmt_offset');
    if ($config["USE_IMAGEMAGICK"]) {
         if (!file_exists($config["IMAGEMAGICK_IDENTIFY"])
         ||!file_exists($config["IMAGEMAGICK_CONVERT"])) {
            $config["RESIZE_LARGE_IMAGES"] = false;
         }
    }
    else {
        if (!HasGDInstalled(false)) {
            $config["RESIZE_LARGE_IMAGES"] = false;
        }
    }
    $config["POSTIE_ROOT"] = POSTIE_ROOT;
    $config["URLPHOTOSDIR"] = get_option('siteurl') . ConvertFilePathToUrl($config["PHOTOSDIR"]);
    $config["REALPHOTOSDIR"] = realpath(ABSPATH . $config["PHOTOSDIR"]). DIRECTORY_SEPARATOR;
    $config["URLFILESDIR"] = get_option('siteurl') . ConvertFilePathToUrl($config["FILESDIR"]);
    $config["REALFILESDIR"] = realpath(ABSPATH . $config["FILESDIR"]) . DIRECTORY_SEPARATOR;
    for ($i = 0; $i < count($config["AUTHORIZED_ADDRESSES"]); $i++) {
        $config["AUTHORIZED_ADDRESSES"][$i] = strtolower($config["AUTHORIZED_ADDRESSES"][$i]);
    }
    return $config;
}
/**
  * Converts from one directory structure to url
  * @return string
  */
function ConvertFilePathToUrl($path) {
    return(str_replace(DIRECTORY_SEPARATOR , "/", $path));
}
/**
  * Returns a list of config keys that should be arrays
  *@return array
  */
function GetListOfArrayConfig() {
    return(array("SUPPORTED_FILE_TYPES","AUTHORIZED_ADDRESSES","SIG_PATTERN_LIST","BANNED_FILES_LIST"));
}
/**
  * Detects if they can do IMAP
  * @return boolean
  */
function HasIMAPSupport($display = true) {
    $function_list = array("imap_open",
                           "imap_delete",
                           "imap_expunge",
                           "imap_body",
                           "imap_fetchheader");
    return(HasFunctions($function_list,$display));
}
function HasIconvInstalled($display = true) {
    $function_list = array("iconv");
    return(HasFunctions($function_list,$display));
}
function HasGDInstalled($display = true) {
    $function_list = array("getimagesize",
                           "imagecreatefromjpeg",
                           "imagecreatefromgif",
                           "imagecreatefrompng",
                           "imagecreatetruecolor",
                           "imagecreatetruecolor",
                           "imagecopyresized",
                           "imagejpeg",
                           "imagedestroy");
    return(HasFunctions($function_list,$display));
}
/**
  * Handles verifing that a list of functions exists
  * @return boolean
  * @param array
  */
function HasFunctions($function_list,$display = true) {
    foreach ($function_list as $function) {
        if (!function_exists($function)) {
            if ($display) {
                print("<p>Missing $function");
            }
            return(false);
        }
    }
    return(true);

}
/**
  * This filter makes it easy to change the html from showing the thumbnail to the actual picture
  */
function filter_postie_thumbnail_with_full($content) {
     $content = str_replace("thumb.","",$content);
     return($content);
}
/**
  * This function tests to see if postie is its own directory
  */
function TestPostieDirectory() {
        $dir_parts = explode(DIRECTORY_SEPARATOR,dirname(__FILE__)); 
        $last_dir = array_pop($dir_parts);
        if ($last_dir != "postie") {
            return false;
        }
        return true;
}
/**
  *This function looks for markdown which causes problems with postie
  */
function TestForMarkdown() {
    if (in_array("markdown.php",get_option("active_plugins"))) {
        return(true);
    }
    return(false);

}
/**
  * This function handles setting up the basic permissions
  */
function PostieAdminPermissions() {
    global $wp_roles;
    $admin = $wp_roles->get_role("administrator");
    $admin->add_cap("config_postie");
    $admin->add_cap("post_via_postie");

}
function UpdatePostiePermissions($role_access) {
    global $wp_roles;
    PostieAdminPermissions();
    if (!is_array($role_access)) {
        $role_access = array();
    }
    foreach($wp_roles->role_names as $roleId => $name) {
        $role = &$wp_roles->get_role($roleId);
        if ($roleId != "administrator") {
            if ($role_access[$roleId]) {
                $role->add_cap("post_via_postie");
            }
            else {
                $role->remove_cap("post_via_postie");
            }
        }
    }
}
function TestWPVersion() {
    //fix from Mathew Boedicker
    $version_parts = explode('.', get_bloginfo('version'));
    if ((count($version_parts) > 0) && (intval($version_parts[0]) >= 2)) {
        return true;
    }
    return false;
}
function DebugEmailOutput(&$email,&$mimeDecodedEmail) {
    $config = GetConfig();
    if ($config["TEST_EMAIL"]) {
        $file = fopen("test_emails/" . $mimeDecodedEmail->headers["message-id"].".txt","w");
        fwrite($file, $email);
        fclose($file);
        $file = fopen("test_emails/" . $mimeDecodedEmail->headers["message-id"]."-mime.txt","w");
        fwrite($file, print_r($mimeDecodedEmail,true));
        fclose($file);
    }
}
/** 
  * This function provides a hook to be able to write special parses for provider emails that are difficult to work with 
  * If you want to extend this functionality - write a new function and call it from here
  */
function SpecialMessageParsing(&$content, &$attachments){
    $config = GetConfig();
    if ( preg_match('/You have been sent a message from Vodafone mobile/',$content)) {
        VodafoneHandler($content, $attachments); //Everything for this type of message is handled below
        return;
    }
    if ( $config["MESSAGE_START"] ) {
        StartFilter($content,$config["MESSAGE_START"]);
    }
    if ( $config["MESSAGE_END"] ) {
        EndFilter($content,$config["MESSAGE_END"]);
    }
    if ( $config["DROP_SIGNATURE"] ) { 
        RemoveSignature($content,$config["SIG_PATTERN_LIST"]);
    }
    if  ($config["PREFER_TEXT_TYPE"] == "html"
            && count($attachments["cids"])) {
        ReplaceImageCIDs($content,$attachments);
    }
    ReplaceImagePlaceHolders($content,$attachments["html"]);
}
/**
  * Special Vodafone handler - their messages are mostly vendor trash - this strips them down.
  */
function VodafoneHandler(&$content, &$attachments){
    $index = strpos($content,"TEXT:");
    if (strpos !== false) {
        $alt_content = substr($content,$index,strlen($content));
        if (preg_match("/<font face=\"verdana,helvetica,arial\" class=\"standard\" color=\"#999999\"><b>(.*)<\/b>/",$alt_content,$matches)) {
            //The content is now just the text of the message
            $content = $matches[1];
            //Now to clean up the attachments
            $vodafone_images = array("live.gif","smiley.gif","border_left_txt.gif","border_top.gif","border_bot.gif","border_right.gif","banner1.gif","i_text.gif","i_picture.gif",);
            while(list($key,$value) = each($attachments['cids'])) {
                if (!in_array($key, $vodafone_images)) {
                    $content .=  "<br/>".$attachments['html'][$attachments['cids'][$key][1]] ;
                }
            }
        }
    }

}
?>
