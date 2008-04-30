<?php
// try to connect to server with different protocols/ and userids
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR ."postie-functions.php");
include_once (dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR. "wp-config.php");
require_once('admin.php');
require_once("postie-functions.php");
$config = GetConfig();
$title = __("Postie Diagnosis");
$parent_file = 'options-general.php?page=postie/postie.php';
get_currentuserinfo();
?>
<?php if ($GLOBALS["user_level"] != 10 ) :?>
    <h2> Sorry only admin can run this file</h2>
    <?php exit();?>
<?php endif;?>

<?
    $images = array("Test.png",
                    "Test.jpg",
                    "Test.gif");
?>
<div class="wrap"> 
    <h1>Postie Configuration Test</h1>
    <?php
        if (TestForMarkdown()) {
            print("<h1>Warning!</h1>
                    <p>You currently have the Markdown plugin installed. It will cause problems if you send in HTML
                    email. Please turn it off if you intend to send email using HTML</p>");

        }
    ?>
    <br/>
    <?php 
        
        if (!TestWPVersion()) {
            print("<h1>Warning!</h1>
                    <p>Postie only works on on Word Press version 2.0 and above</p>");
        exit();
        }
         ?>

    <br/>
    <?php 
        
        if (!TestPostieDirectory()) {
            print("<h1>Warning!</h1>
                    <p>Postie expects to be in its own directory named postie.</p>");
        }
        else  {
            print("<p>Postie is in ".dirname(__FILE__)."</p>");
        }
         ?>

    <br/>
    <h2>GD Library Test<h2>
    <p>
    <?= HasGDInstalled();?>
    </p>
    <h2>Iconv Library Test<h2>
    <p><i>Only required if you want to support ISO-2022-JP</i>
    <?= HasIconvInstalled();?>
    </p>
    <br/>
    <h2>Clock Tests<h2>
    <p>This shows what time it would be if you posted right now</p>
    <?php
     $content ="";
     $data = DeterminePostDate($content);

    ?>
    <p><?php print("GMT:". $data[1]);?></p>
    <p><?php print("Current:". $data[0]);?></p>
    <h2>Mail Tests</h2>
    <p>These try to confirm that the email configuration is correct.</p>

    <table>
    <tr>
        <th>Test</th>
        <th>Result</th>
    </tr>
    <tr>
        <th>Connect to Mail Host</th>
        <td>
           <?php
                switch( strtolower($config["INPUT_PROTOCOL"]) ) {
                    case 'imap':
                    case 'imap-ssl':
                    case 'pop3-ssl':
                        if (!HasIMAPSupport()) {
                            print("Sorry - you do not have IMAP php module installed - it is required for this mail setting.");
                        }
                        else {
                            require_once("postieIMAP.php");
                            $mail_server = &PostieIMAP::Factory($config["INPUT_PROTOCOL"]);
                            if (!$mail_server->connect($config["MAIL_SERVER"], $config["MAIL_SERVER_PORT"],$config["MAIL_USERID"],$config["MAIL_PASSWORD"])) {
                                print("Unable to connect. The server said - ".$mail_server->error());
                                print("<br/>Try putting in your full email address as a userid and try again.");
                            }
                            else {
                                print("Yes");
                            }
                        }
                        break;
                    case 'pop3':
                    default: 
                        require_once(ABSPATH.WPINC.DIRECTORY_SEPARATOR.'class-pop3.php');
                        $pop3 = &new POP3();
                        if (!$pop3->connect($config["MAIL_SERVER"], $config["MAIL_SERVER_PORT"])) {
                                print("Unable to connect. The server said - ".$pop3->ERROR);
                                print("<br/>Try putting in your full email address as a userid and try again.");
                        }
                        else {
                            print("Yes");
                        }
                        break;

                }
           ?>
            </td>
    </tr>


    </table>
    <h2>File Tests</h2>

    <table>
    <tr>
        <th>Test</th>
        <th>Result</th>
    </tr>
    <tr>
        <th>Photos Directory Exists</th>
        <td><?php echo (is_dir($config["REALPHOTOSDIR"]) ? "Yes" : "No"); ?></td>
    </tr>
    <tr>
        <th>Files Directory Exists</th>
        <td><?php echo (is_dir($config["REALFILESDIR"]) ? "Yes" : "No"); ?></td>
    </tr>
    <tr>
        <th>Photos Directory Writable</th>
        <td><?php echo (is_writable($config["REALPHOTOSDIR"]) ? "Yes" : "No"); ?></td>
    </tr>
    <tr>
        <th>Files Directory Writable</th>
        <td><?php echo (is_writable($config["REALFILESDIR"]) ? "Yes" : "No"); ?></td>
    </tr>

    <?php if ($config["USE_IMAGEMAGICK"]):?>
    <tr>
        <th>Convert exists</th>
        <td><?php echo (file_exists($config["IMAGEMAGICK_CONVERT"]) ? "Yes" : "No"); ?></td>
    </tr>
    <tr>
        <th>Identify exists</th>
        <td><?php echo (file_exists($config["IMAGEMAGICK_IDENTIFY"]) ? "Yes" : "No"); ?></td>
    </tr>

    <?endif;?>

    </table>

    

    <h2>Image Tests</h2>
    <p>Three images should be here - they are the test files</p>
    <table>
    <tr>
        <td>&nbsp;</td>
        <td>PNG</td>
        <td>JPG</td>
        <td>GIF</td>
    </tr>
    <tr>
    <th>Plain Images</th>
        <?php
            foreach ($images as $image) {
                $size = DetermineImageSize(POSTIE_ROOT . DIRECTORY_SEPARATOR . "test_files" . DIRECTORY_SEPARATOR .$image);
                print("<td>$size[1] x $size[0]<br/>\n");
                print("<img src='../wp-content/plugins/postie/test_files/$image' ></td>\n");
            }
        ?>
    </tr>
    <tr>
    <?php if($config["AUTO_SMART_SHARP"]){ ?>
    <th> AutoSharpened <br/><p>WARNING-This feature takes a lot of processing power</p></th>
        <?php
            foreach ($images as $image) {
              
               print("<td>");

               ImageMagickSharpen(POSTIE_ROOT . DIRECTORY_SEPARATOR . "test_files" . DIRECTORY_SEPARATOR .$image,
                                $config["REALPHOTOSDIR"] ."Sharp-".$image);
                print("<img src='".$config["URLPHOTOSDIR"]."/Sharp-".$image."'>");
                print("</td>\n");
            }
        ?>
    </tr>
    <?php }?>
    <tr>
    <th> Scaled & Rotated</th>
        <?php
            foreach ($images as $image) {
                $result = ResizeImage(POSTIE_ROOT . DIRECTORY_SEPARATOR . "test_files" . DIRECTORY_SEPARATOR .$image,substr($image,-3,3));
              
                RotateImages(90,array(
                                array(null,$config["REALPHOTOSDIR"] . $result[0],'jpg')
                            ));
               print("<td>");
                $size = DetermineImageSize($config["REALPHOTOSDIR"] . DIRECTORY_SEPARATOR .$result[0]);
              print("$size[1] x $size[0]<br\>\n");
                print("<img src='".$config["URLPHOTOSDIR"].$result[0]."'>");
                print("</td>\n");
            }
        ?>
    </tr>
    </table>
    
</div>
