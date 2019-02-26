<?php
namespace Stanford\UsernameVerification;
/** @var \Stanford\UsernameVerification\UsernameVerification $module */

use \User;
use \REDCap;

/**
 * This page can be accessed by any authenticated REDCap user for the purposes of sending a notification email
 * about a new user being added to a project
 */

$current_user = User::getUserInfo(USERID);
$new_username = $_POST['username'];

// Presumably the user doesn't exist so we need to re-pull the user object from the lookup.
list($status, $message, $user) = $module->verifyUsername($new_username);

// We need the user object
if (empty($user) || $status === false) exit("0");

// Build the email
global $lang, $Proj;

$app_title  = $Proj->project['app_title'];
$to         = $user['user_email'];
$from       = $current_user['user_email'];
$subject    = $lang['rights_122'];
$message    = "<html><body style='font-family:arial,helvetica;font-size:10pt;'>
    {$lang['global_21']}<br /><br />
    {$lang['rights_88']} \"".strip_tags(str_replace("<br>", " ", label_decode($app_title)))."\"{$lang['period']}
    {$lang['rights_89']} \"" . $user['user_displayname'] . "\", {$lang['rights_90']}<br /><br />
    ".APP_PATH_WEBROOT_FULL."
    </body></html>";


if (REDCap::email($to,$from,$subject,$message)) {
    exit("1");
} else {
    exit("0");
}
