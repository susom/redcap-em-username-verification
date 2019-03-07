<?php

namespace Stanford\UsernameVerification;

use \REDCap;
use \Project;
use \User;

class CleanupHelper
{





    /**
     * If a user is added to a project but doesn't exist in REDCap, they are only present in the redcap_user_roles table
     * and NOT in the redcap_user_information table.
     *
     * This function returns those records along with the user who added them and that user's email address.
     *
     * @return array
     */
    public static function getUserRightsOrphans() {

        // Get usernames added to user rights but not in the user table along with who added them if available
        $sql = "select
          a.username,
          a.project_id,
          b.user,
          b.user_email,
          b.ts
        from
          ( select
              rur.username,
              rur.project_id
            from redcap_user_rights rur
              left outer join redcap_user_information rui on rui.username = rur.username
            where
              rui.username is null
          ) as a
          left join
            ( select
                rle.user,
                rle.pk,
                rle.project_id,
                rle.ts,
                rui.user_email
              from redcap_log_event rle
                join redcap_user_information rui on rui.username = rle.user
              where
                rle.object_type     = 'redcap_user_rights'
                and rle.event       = 'INSERT'
                and rle.description = 'Add user'
            ) b on a.username = b.pk and a.project_id = b.project_id";
        $q = db_query($sql);
        $results = [];
        while ($row = db_fetch_assoc($q)) {
            $results[] = $row;
        }

        return $results;
    }


    public static function suspendUser($username) {
        // Update the user info table
        $sql = "update redcap_user_information set user_suspended_time = '".NOW."' where username = '".db_escape($username)."'";
    	if (db_query($sql)) {
            // Logging
            REDCap::logEvent("Username Verification - Suspension", "Suspended $username", $sql, null, null, null);
            return 1;
        } else {
            return 0;
        }
    }

    public static function deleteUser($username)
    {
        // Remove user from user info table
        $q1 = db_query("delete from redcap_user_information where username = '" . db_escape($username) . "'");
        $q1_rows = db_affected_rows();
        // Remove user from user rights table
        $q2 = db_query("delete from redcap_user_rights where username = '" . db_escape($username) . "'");
        // Remove user from auth table (in case if using Table-based authentication)
        $q3 = db_query("delete from redcap_auth where username = '" . db_escape($username) . "'");
        // Remove user from table
        $q4 = db_query("delete from redcap_user_whitelist where username = '" . db_escape($username) . "'");
        // Remove user from table
        $q5 = db_query("delete from redcap_auth_history where username = '" . db_escape($username) . "'");
        // Remove user from table
        $q6 = db_query("delete from redcap_external_links_users where username = '" . db_escape($username) . "'");

        // If all queries ran as expected, give positive response
        if ($q1_rows == 1 && $q1 && $q2 && $q3 && $q4 && $q5 && $q6) {
            // Logging
            REDCap::logEvent("Username Verification - Delete User $username", "Deleted $username from redcap_user_information\nredcap_user_rights\nredcap_auth\nredcap_auth_history\nredcap_external_links_users");
            // Give positive response
            return 1;
        } else {
            return 0;
        }
    }


    public static function deleteUserRightsUser($username,$pid) {
        $sql = sprintf("DELETE FROM redcap_user_rights where project_id = %d and username = '%s'",
            intval($pid),
            prep($username));
        $q = db_query($sql);
        REDCap::logEvent("Username Verification - Removed $username", "Removed $username from project $pid", $sql, null,null,$pid);
        return $q;
    }

    public static function emailCreator($to_email, $username, $pid) {
        $current_user = User::getUserInfo(USERID);
        $from_email = $current_user['user_email'];

        $project = new Project($pid);
        $title = $project->project['app_title'];

        $subject = "REDCap Maintenance: An incorrect/invalid username ($username) has been removed";

        $message = "
            <p>Dear REDCap user,</p>
            <p>During routine scanning of the server we discovered an invalid username you may have added to a REDCap project.</p>
            <table>
                <tr>
                    <th style='text-align:left;'><b>Affected Project: </b></th><td><u>$title</u> (#$pid)</td>
                </tr>
                <tr>
                    <th style='text-align:left;'><b>Invalid Username: </b></th><td><u>$username</u></td>
                </tr>
            </table>
            <p>This can happen when the username entered in user-rights isn't the same as the <u>official</u> user's id.
            For example, using <code>jane.doe</code> or <code>jane.doe@university.com</code> instead of the official 
            id of <code>jdoe</code>.</p>
            <p>Because <u>$username</u> was not valid and could not be used by the intended user, it has been removed 
            from your project's user-rights table.  If you wish to have the intended user access your project, please 
            visit user-rights and add them using a valid ID.</p>
            <p>Thank you!</p>
            <p>Sincerely,</p>
            <p> -- REDCap support</p>";

        $result = REDCap::email($to_email, $from_email, $subject, $message);
        REDCap::logEvent("Username Verification - Email Notification", "Notified $to_email re: $username on project $pid","",null,null,$pid);
        return $result;
    }
    
}