<?php
namespace Stanford\UsernameVerification;

include_once "emLoggerTrait.php";
include_once "src/WebServiceVerifier.php";

use \User;
use \REDCap;
use \Project;

class UsernameVerification extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $method;
    private $allowlist;      // Array of usernames to always return as true
    public $createNewUser;
    public $errors;         // An array to hold any errors for later display

    const  DEFAULT_CLEANUP_EMAIL_SUBJECT  = "REDCap Maintenance: An incorrect/invalid username ({{username}}) has been removed";
    const  DEFAULT_CLEANUP_EMAIL_TEMPLATE = <<<EOT
            <p>Dear REDCap user,</p>
            <p>During routine scanning of the server we discovered an invalid username you may have added to a REDCap project.</p>
            <table>
                <tr>
                    <th style='text-align:left;'><b>Affected Project: </b></th><td><u>{{title}}}</u> (#{{pid}})</td>
                </tr>
                <tr>
                    <th style='text-align:left;'><b>Invalid Username: </b></th><td><u>{{username}}</u></td>
                </tr>
            </table>
            <p>This can happen when the username entered in user-rights isn't the same as the <u>official</u> user's id.
            For example, using <code>jane.doe</code> or <code>jane.doe@university.com</code> instead of the official 
            id of <code>jdoe</code>.</p>
            <p>Because <u>{{username}}</u> was not valid and could not be used by the intended user, it has been removed 
            from your project's user-rights table.  If you wish to have the intended user access your project, please 
            visit <a href="{{app-url}}UserRights/index.php?pid={{pid}}" target="_blank">User Rights</a> and add the user
            with a valid ID.</p>
            <p>Thank you!</p>
            <p>Sincerely,</p>
            <p> -- REDCap support</p>
EOT;



    /**
     * Return method using internal cache
     * @return mixed
     */
    private function getMethod() {
        if (is_null($this->method)) {
            $this->method = $this->getSystemSetting('method');
        }
        return $this->method;
    }

    private function getAllowlist() {
        if (is_null($this->allowlist)) {
            $this->allowlist = array_map('trim', explode(",", $this->getSystemSetting('allowlist')));
        }
        return $this->allowlist;
    }


    /**
     * Since default config.json options aren't working reliably, we will fill them in here
     * @param $version
     */
    public function redcap_module_system_enable($version) {
        $this->setDefaults();
    }

    public function redcap_module_save_configuration($project_id) {
        $this->setDefaults();
    }


    /**
     * Set the ui config defaults
     */
    public function setDefaults() {
        // Set default email template
        $template = $this->getSystemSetting('cleanup-email-template');
        if (empty($template)) $this->setSystemSetting('cleanup-email-template', self::DEFAULT_CLEANUP_EMAIL_TEMPLATE);

        $subject = $this->getSystemSetting('cleanup-email-subject');
        if (empty($subject)) $this->setSystemSetting('cleanup-email-subject', self::DEFAULT_CLEANUP_EMAIL_SUBJECT);
    }


    /**
     * Is the configuration valid?
     * @return bool
     */
    public function validateSetup() {
        if (empty($this->getMethod())) $this->errors[] = "Please set a valid method in the configuration page";
        if ($this->getMethod() == 'web-service-url' && empty($this->getSystemSetting('web-service-url'))) $this->errors[] = "Please set a valid web-service-url that when called will return the required user details";

        return empty($this->errors);
    }



    /**
     * @param $username
     * @return array
     */
    public function redcap_custom_verify_username($username)
    {
        // Your function should return an associative array with two elements: 'status' (either TRUE or FALSE) and 'message'
        // (text that you wish to be displayed on the page). If 'message' is blank/null, then it will do nothing. If a
        // 'message' value is returned, then it will output the text to the page inside a red box. If 'status' is FALSE,
        // then it will display the message and stop processing (i.e., the user will NOT be granted access to the project),
        // but if 'status' is TRUE, then it will display the message but will allow the user to be granted access.
        // return array('status'=>FALSE, 'message'=>'ERROR: User $username is not a valid username!');

        $msg = [];

        // Do the lookup!
        list($status, $msg[], $user) = $this->verifyUsername($username);

        $this->emDebug("Looking up $username", $status, $msg);

        // Username is VALID
        if ($status === true) {

            // See if the user already exists in REDCap
            $existingUser = User::getUserInfo($username);
            $this->emDebug($status, $existingUser);

            if ($existingUser) {
                // This user already exists
                $msg[] = "<b>" . $existingUser['user_firstname'] . " ($username)</b> has been added to this project and will be " .
                    "notified by email with a link to the project.";
            } else {
                // Cater the message depending on if we have 'user info'
                if (empty($user)) {
                    $msg[] = "<b>$username</b> has been added to this project but does not have an account on this " .
                        "REDCap server.  Because there is no email address, you will have to manually contact the user " .
                        "and provide them with instructions to access REDCap at " . APP_PATH_WEBROOT_FULL;
                } else {
                    // Allow user to email user manually (since user doesn't exist in system)
                    $url = $this->getUrl("notify.php",false, false);
                    $msg[] = "<b>" . $user['user_displayname'] . " ($username)</b> will be added to this project " .
                            "but has never used this REDCap server before.<br>Click here to send them an email with " .
                            "a link to the project: <div id='send_intro_email' data-username='$username' " .
                            "data-url='$url' class='btn btn-xs btn-success'>Send Intro Email</div>." .
                            "<script type='text/javascript' src='" . $this->getUrl("notify.js") ."'></script>";
                }
            }

            $msg[] = "Please be sure you to configure appropriate user rights for this user.<br>" .
                "We strongly recommend using <u>User Roles</u> to manage project rights.<br>" .
                "If you are using Data Access Groups, assign the user to an appropriate DAG.<br>" .
                "If you have questions about permissions or user roles, contact " .
                "<a href='mailto:redcap-help@lists.stanford.edu'>redcap-help@lists.stanford.edu</a>";
        }

        // Build message
        $message = implode("<br><br>",array_filter($msg));

        return array('status'=>$status, 'message'=>$message);
    }


    /**
     * Does the actual verification
     * @param $username
     * @return array
     */
    public function verifyUsername($username) {
        $status  = false;
        $message = "";
        $user    = null;

        $this->emDebug("verifying $username with allowlist:" . json_encode($this->getAllowlist()));

        if (in_array($username, $this->getAllowlist())) {
            $status  = true;
        } elseif ($this->getMethod() == "web-service") {
            // Make a new verifier and link it to the EM for logging
            $verifier = new WebServiceVerifier($this);
            $base_url = $this->getSystemSetting('web-service-url');
            $status   = $verifier->verify($username, $base_url);
            $message      = $verifier->getMessage();
            $user     = $verifier->getUser();
        } else {
            $this->emError("Invalid Method");
            $message  = "There is a configuration problem (invalid method) with " . $this->PREFIX . " - please notify your system administrator";
        }

        return array($status, $message, $user);
    }


    /**
     * Replace template with values from context data array
     * @param       $template
     * @param array $data
     * @return mixed
     */
    public function pipe($template, $data = array()) {
        foreach ($data as $k => $v) {
            $template = str_replace("{{" . $k . "}}", $v, $template);
        }
        return $template;
    }


    /**
     * Send email
     * @param $to
     * @param $username
     * @param $pid
     * @return bool
     * @throws \Exception
     */
    public function sendCleanupEmail($to, $username, $pid) {

        $project = new Project($pid);

        $context = array(
            "title"     => $project->project['app_title'],
            "pid"       => $pid,
            "app-url"   => APP_PATH_WEBROOT_FULL,
            "username"  => $username
        );

        $subject = $this->getSystemSetting('cleanup-email-subject');
        if (empty($subject)) $subject = self::DEFAULT_CLEANUP_EMAIL_SUBJECT;
        $subject = $this->pipe($subject,$context);

        $body = $this->getSystemSetting('cleanup-email-template');
        if (empty($body)) $body = self::DEFAULT_CLEANUP_EMAIL_TEMPLATE;
        $body = $this->pipe($body,$context);

        $from = $this->getSystemSetting('cleanup-email-from');
        if (empty($from)) {
            $current_user = User::getUserInfo(USERID);
            $from         = $current_user['user_email'];
        }

        $result = REDCap::email($to, $from, $subject, $body);

        if ($result) {
            REDCap::logEvent("Username Verification Cleanup - Email Notification", "Notified $to re: $username on project $pid", "", null, null, $pid);
        }

        return $result;
    }




    // /**
    //  * In some cases we want to register the newly added user into REDCap as this will result in them being sent the standard
    //  * email about being added to a new project.  Alternately, we could manually email them.
    //  *
    //  * @param $user
    //  * @return bool
    //  */
    // private function registerNewUser($user) {
    // 	global $allow_create_db_default;
	//     $sql = sprintf("insert into redcap_user_information " .
    //             "  (username, user_email, user_firstname, user_lastname, user_creation, allow_create_db) ".
    //             "values ('%s', '%s', '%s', '%s', NOW(), %s)",
    //             prep($user['username']),
    //             prep($user['user_email']),
	// 		    prep($user['user_firstname']),
    //             prep($user['user_lastname']) . " (never logged in) ",
	// 		    $allow_create_db_default);
    // 	$q = db_query($sql);
    //     if ($q) {
    //         global $project_id;
    //         $desc = "Created by " . USERID;
    //         if (!empty($project_id)) $desc .= " in project $project_id";
    //         REDCap::logEvent("User Created From Add User",$desc, $sql);
    //         return true;
    //     } else {
    //         return false;
    //     }
    // }

}