<?php
namespace Stanford\UsernameVerification;


/**
 * Class LDAPVerifier
 * @package Stanford\UsernameVerification
 *
 * TODO:
 * THIS IS A SUB FOR SOMEONE TO COMPLETE USING A DIRECT LDAP LOOKUP
 *
 */

class LDAPVerifier {

    private $module;    // A reference to the parent EM module
    private $username;  // The incoming username to be checked
    private $status;    // The overall status (boolean true/false)
    private $message;   // The message returned
    private $user;      // User object for match if found, e.g.
                        //     [
                        //          "user_displayname" => "Dr. Jane Doe",
                        //          "user_firstname" => "Jane",
                        //          "user_lastname" => "Doe",
                        //          "user_email" => "jane.doe@university.edu"
                        //     ]


    public function __construct(UsernameVerification $module)
    {
        $this->module = $module;
    }


    /**
     * This method should set the status, message, and user object
     * @param $username
     * @return boolean status
     */
    public function verify($username) {
        $this->username = $username;

        // TODO: DO LOOKUP
        $result = null;

        $this->status  = isset($result['status'])  ? (boolean) $result['status']  : false;
        $this->message = isset($result['message']) ? $result['message']           : "Error in verify result";
        $this->user    = isset($result['user'])    ? $result['user']              : null;

        return $this->status;
    }


    /**
     * @return mixed
     */
    public function getStatus() {
        return $this->status;
    }


    /**
     * @return mixed
     */
    public function getMessage() {
        return $this->message;
    }

    /**
     * Return the user, if found.  Should have keys: user_displayname, user_firstname, user_lastname, user_email
     * @return mixed
     */
    public function getUser() {
        return $this->user;
    }





}