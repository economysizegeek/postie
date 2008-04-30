#!/usr/bin/php -q
<?php

//Load up some usefull libraries
include_once (dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR."wp-config.php");
require_once (dirname(__FILE__). DIRECTORY_SEPARATOR . 'mimedecode.php');
require_once (dirname(__FILE__). DIRECTORY_SEPARATOR . 'postie-functions.php');
	
if (!TestWPVersion()) {
    print("<p>Postie Only Works For Word Press 2.0 and above.</p>");
    exit();
}

/* END OF USER VARIABLES */
//some variables
error_reporting(2037);
TestWPMailInstallation();

//Retreive emails 
print("<pre>\n");
$emails = FetchMail();
//loop through messages
foreach ($emails as $email) {
    //sanity check to see if there is any info in the message
    if ($email == NULL ) { print 'Dang, message is empty!'; continue; }
    
    $mimeDecodedEmail = DecodeMimeMail($email);
    $from = RemoveExtraCharactersInEmailAddress(trim($mimeDecodedEmail->headers["from"]));
    /*
    if ($from != "") {
        continue;
    }
    */

    //Check poster to see if a valid person
    $poster = ValidatePoster($mimeDecodedEmail);
    if (!empty($poster)) {
        DebugEmailOutput($email,$mimeDecodedEmail); 
        PostEmail($poster,$mimeDecodedEmail);
    }
    else {
        print("<p>Ignoring email - not authorized.\n");
    }
} // end looping over messages
print("</pre>\n");
    
/* END PROGRAM */

// end of script
?>
