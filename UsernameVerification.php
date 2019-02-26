<?php
namespace Stanford\UsernameVerification;

include_once "emLoggerTrait.php";
include_once "src/WebServiceVerifier.php";

use \User;
use \REDCap;


class UsernameVerification extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    public $method;
    public $whitelist;      // Array of usernames to always return as true
    public $createNewUser;
    public $errors;         // An array to hold any errors for later display


    public function __construct()
    {
        parent::__construct();

        // Load the method
        $this->method = $this->getSystemSetting('method');
        $this->whitelist = preg_split ('/(\s*,\s*)*,+(\s*,\s*)*/', $this->getSystemSetting('whitelist'));
    }


    /**
     * Is the configuration valid?
     * @return bool
     */
    public function validateSetup() {
        if (empty($this->method)) $this->errors[] = "Please set a valid method in the configuration page";
        if ($this->method == 'web-service-url' && empty($this->getSystemSetting('web-service-url'))) $this->errors[] = "Please set a valid web-service-url that when called will return the required user details";

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
                // They are NOT an existing user

                // Do we want to try and create a new user?
                $createNewUser = $this->getSystemSetting('create-new-user');

                if ($createNewUser) {
                    // Did we get user information back from the web service?
                    if (empty($user)) {
                        // Insufficient user information
                        $msg[] = "<b>$username</b> has been added to this project but does not have an account on this " .
                            "REDCap server.  Because there is no email address, you will have to manually contact the user " .
                            "and provide them with instructions to access REDCap at " . APP_PATH_WEBROOT;
                    } else {
                        $result = $this->registerNewUser($user);
                        $this->emDebug("Adding new user", $user, $result);
                        if ($result) {
                            // User Created
                            $msg[] = "<b>" . $user['user_firstname'] . " ($username)</b>  has been added to this project " .
                                " but has never used this REDCap server before.  A new user account was just created " .
                                " and the user should be receiving an automated email with a link to this project.";
                        } else {
                            // User Creation Failed
                            $msg[] = "<b>" . $user['user_firstname'] . " ($username)</b>  has been added to this project " .
                                " but the server was unable to create a new user for them.  You will have to manually " .
                                " contact the user and provide them with instructions to access REDCap at " . APP_PATH_WEBROOT;
                        }
                    }
                } else {
                    // We do not create new users
                    $msg[] = "<b>$username</b> has been added to this project but does not have an account on this " .
                        "REDCap server.  You will have to manually contact the user and provide them with instructions " .
                        "to access REDCap at " . APP_PATH_WEBROOT;
                }
            }


            $msg[] = "Please be sure you to configure appropriate user rights for this user.<br>" .
                "We strongly recommend using <u>User Roles</u> to manage project rights.<br>" .
                "If you are using Data Access Groups, assign the user to an appropriate DAG.<br>" .
                "If you have questions about permissions or user roles, contact " .
                "<a href='mailto:redcap-help@lists.stanford.edu'>redcap-help@lists.stanford.edu</a>";
        }

        // Build message
        $message = implode("<br><br>",$msg);


        // Previously with this hook, you returned $status and $message, but now you have to render the results yourself.
        if ($status) {
            if (strlen($message) > 0) echo "<div class='alert alert-success text-center'>" . $message . "</div>";
        } else {
            echo "<div class='alert alert-danger text-center'>" . $message . "</div>";
            exit();
        }
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

        if (in_array($username, $this->whitelist)) {
            $status  = true;
        } elseif ($this->method == "web-service") {
            // Make a new verifier and link it to the EM for logging
            $verifier = new WebServiceVerifier($this);
            $base_url = $this->getSystemSetting('web-service-url');
            $status   = $verifier->verify($username, $base_url);
            $message      = $verifier->getMessage();
            $user     = $verifier->getUser();
        } else {
            $this->emError("Invalid Method");
            $status   = false;
            $message      = "There is a configuration problem (invalid method) with " . $this->PREFIX . " - please notify your system administrator";
        }

        return array($status, $message, $user);
    }




    /**
     * In some cases we want to register the newly added user into REDCap as this will result in them being sent the standard
     * email about being added to a new project.  Alternately, we could manually email them.
     *
     * @param $user
     * @return bool
     */
    private function registerNewUser($user) {
    	global $allow_create_db_default;
	    $sql = sprintf("insert into redcap_user_information " .
                "  (username, user_email, user_firstname, user_lastname, user_creation, allow_create_db) ".
                "values ('%s', '%s', '%s', '%s', NOW(), %s)",
                prep($user['username']),
                prep($user['user_email']),
			    prep($user['user_firstname']),
                prep($user['user_lastname']) . " (never logged in) ",
			    $allow_create_db_default);
    	$q = db_query($sql);
        if ($q) {
            global $project_id;
            $desc = "Created by " . USERID;
            if (!empty($project_id)) $desc .= " in project $project_id";
            REDCap::logEvent("User Created From Add User",$desc, $sql);
            return true;
        } else {
            return false;
        }
    }

}