<?php
namespace Stanford\UsernameVerification;

class WebServiceVerifier {

    private $module;    // A reference to the parent EM module
    private $url;       // The endpoint for the verification service
    private $username;  // The incoming username to be checked
    private $status;    // The overall status (boolean true/false)
    private $message;   // The message returned
    private $user;      // User object for match if found

    public function __construct(UsernameVerification $module)
    {
        $this->module = $module;
    }


    /**
     * @param $username
     * @return boolean status
     */
    public function verify($username, $base_url) {
        $this->username = $username;

        // Build username into query
        $querystring = parse_url($base_url, PHP_URL_QUERY);

        $url = $base_url . (empty($querystring) ? "?" : "&") . "username=" . htmlentities($username);
        $this->module->emDebug("Final Url: " . $url);

        $json = http_get($url);

        $result = json_decode($json,true);

        $this->module->emDebug($result);

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