<?php
/**
  * This file handles submissions from the config form
  */
    require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR ."postie-functions.php");

    if (isset($_POST["action"])) {
        switch($_POST["action"]) {

            case "reset":
                ResetPostieConfig();
                $message = 1;
                break;
            case "cronless":
                check_postie();
                $message = 1;
                break;
            case "test":
                $location = get_option('siteurl') . '/wp-admin/options-general.php?page=postie/postie_test.php';
                header("Location: $location\n\n");
                exit;
                break;
            case "config":
                if( UpdatePostieConfig($_POST)) {
                    $message = 1;
                }
                else {
                    $message = 2;
                }
                break;
            default:
                $message = 2;
                break;
        }
        $location = get_option('siteurl') . '/wp-admin/options-general.php?page=postie/postie.php';
        header("Location: $location&message=$message\n\n");
        exit();
    }
?>
