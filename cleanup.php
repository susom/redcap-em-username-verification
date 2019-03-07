<?php
namespace Stanford\UsernameVerification;
/** @var \Stanford\UsernameVerification\UsernameVerification $module */

require_once ("src/CleanupHelper.php");

use \User;


// Validate setup
$isValid = $module->validateSetup();


// Get Users
$allUsers = User::getUsernames([],true);

// Get User Rights Orphans
$userRightsOrphans = CleanupHelper::getUserRightsOrphans();


// Handle valid post requests
if (!empty($_POST) && $isValid && SUPER_USER) {

    $result = [];

    // SCAN USER INFORMATION TABLE
    if (@$_POST['scan'] == 1) {
        // Scan users
        foreach ($allUsers as $user) {
            $parts = explode(" ", $user);
            $username = $parts[0];
            list($success, $message, $user) = $module->verifyUsername($username);

            if ($success == false) {
                // Failed
                // Has the user ever logged in?
                $userInfo           = User::getUserInfo($username);
                $firstVisit         = $userInfo['user_firstvisit'];
                $lastActivity       = $userInfo['user_lastactivity'];
                $userSuspendedTime  = $userInfo['user_suspended_time'];
                $userEmail          = $userInfo['user_email'];

                $btn_suspend = !empty($userSuspendedTime) ? "" : "<div " .
                    "data-action='suspend' " .
                    "data-username='" . $username . "' " .
                    "class='btn btn-xs btn-primary'>Suspend</div>";

                $btn_delete = "<div " .
                    "data-action='delete_user' " .
                    "data-username='" . $username . "' " .
                    "class='btn btn-xs btn-danger'>Delete</div>";

                $result[] = [
                    $username,
                    $userEmail,
                    $firstVisit,
                    $lastActivity,
                    $userSuspendedTime,
                    $btn_suspend,
                    $btn_delete
                ];
            }
        }

    }

    // SCAN USER RIGHTS TABLE FOR ORPHANS
    if (@$_POST['scan-user-rights'] == 1) {
        // Scan users
        foreach ($userRightsOrphans as $ur_user) {
            $username = $ur_user['username'];
            list($success, $message, $user) = $module->verifyUsername($username);

            if ($success == false) {
                // Failed

                $ts               = empty($ur_user['ts']) ? "" : \DateTimeRC::format_ts_from_int_to_ymd($ur_user['ts']);
                $pid              = $ur_user['project_id'];
                $created_by_user  = $ur_user['user'];
                $created_by_email = $ur_user['user_email'];

                // Build buttons
                $btn_email        = empty($created_by_email) ? "" : "<div " .
                    "data-action='email' "   .
                    "data-pid='"             . $pid             . "' " .
                    "data-username='"        . $username        . "' " .
                    "data-created_by_email='". $created_by_email. "' " .
                    "data-created_by_user='" . $created_by_user . "' " .
                    "class='btn btn-xs btn-success'>Email Creator</div>";

                $btn_delete    = "<div " .
                    "data-action='delete_user_rights' " .
                    "data-pid='" . $pid . "' " .
                    "data-username='" . $username . "' " .
                    "class='btn btn-xs btn-danger'>Delete</div>";


                $result[] = [
                    $username,
                    $pid,
                    $created_by_user,
                    $created_by_email,
                    $ts,
                    $btn_email,
                    $btn_delete
                ];
            }
        }
    }

    // HANDLE BUTTON CLICKS
    if (isset($_POST['data']['action'])) {
        $action            = $_POST['data']['action'];
        $username          = @$_POST['data']['username'];
        $pid               = @$_POST['data']['pid'];
        $created_by_user   = @$_POST['data']['created_by_user'];
        $created_by_email  = @$_POST['data']['created_by_email'];

        switch($action) {
            case "suspend":
                $result = CleanupHelper::suspendUser($username);
                break;
            case "delete_user":
                $result = CleanupHelper::deleteUser($username);
                break;
            case "delete_user_rights":
                $result = CleanupHelper::deleteUserRightsUser($username, $pid);
                break;
            case "email":
                $result = CleanupHelper::emailCreator($created_by_email, $username, $pid);
                break;
            default:
                $module->emError("Unhandled Action: $action");
                break;
        }
        $module->emDebug($action, $result);
    }

    header("Content-type: application/json");
    echo json_encode($result);
    exit();
}




// RENDER PAGE
require APP_PATH_DOCROOT . "ControlCenter/header.php";


// VERIFY SUPERUSER
if (!SUPER_USER) {
    ?>
    <div class="jumbotron text-center">
        <h3><span class="glyphicon glyphicon-exclamation-sign"></span> This utility is only available for REDCap Administrators</h3>
    </div>
    <?php
    exit();
}



// START PAGE
?>
<div class="container">
    <h5><?php echo $module->getModuleName()?></h5>
<?php

// Validate setup
if (! $isValid) {
    ?>
        <p>
            The follow configuration errors need to be addressed:
        </p>
    <?php

    foreach ($module->errors as $error) {
        echo "<div class='alert alert-danger'>" . $error . "</div>";
    }
} else {
    ?>
        <p>
            This module's configuration looks: <span style="font-size: 12pt;" class="badge badge-success text-success>"><i class="fas fa-check-circle"></i> Great</span>
        </p>
    <?php
};

?>

    <h6>About:</h6>
    <p>
        This module helps validate user accounts when a user is added to a redcap project.  If you do not use
        table-based authentication, REDCap does not have a mechanism to verify that the user-entered account is
        actually valid.  Failure to validate an account can potentially grant access to an unintended user.
    </p>
    <p>
        This module will do a lookup and notify the user.  You can customize your lookup results message from your
        web service to provide additional details.  See the official documentation for more examples.
    </p>
    <p>
        The purpose of this particular page is to help you 'clean up' any users that may have been previously entered
        into projects but with invalid user accounts.
    </p>


    <hr>
    <h5>Scan Existing Users:</h5>
    <p>
        Initiate a scan of all <?php echo count($allUsers) ?> existing users against your defined validator.
        <div id="scan" class="btn btn-primary btn-sm">SCAN ALL REDCAP USERS</div>
    </p>

    <div class="hidden loader" id="scan_loader"></div>
    <table id="scan_results" class="display" width="100%"></table>



    <hr>
    <h5>Scan Users Orphaned in User Rights (never logged in)</h5>
    <p>
        When you add a user to a project that has never logged it, REDCap 'reserves' a spot for them in the project
        by adding a row to the redcap_user_rights table even though there is NO matching user in the official
        redcap_user_information table.  If that username is incorrect two things can happen:  1) the name is just
        orphaned and will never log in, or 2) someone else with that username could unintentionally get access to the
        project.  So, for this reason, we recommend REMOVING all users that are not valid from REDCap User Rights.

        If a user IS valid but has never logged in, then we assume that they have just never gotten around to visiting
        REDCap.
    </p>
    <p>
        If you click on the 'email creator' button it will notify the person who created the invalid user account
        that the user was being removed from the project as the ID was not valid.  If they wish to re-add the user
        they should go back to user rights and correct the issue.
    </p>
    <p>
        Initiate a scan of all <?php echo count($userRightsOrphans) ?> usernames.
        <div id="scan-user-rights" class="btn btn-primary btn-sm">SCAN USER RIGHTS ENTRIES</div>
    </p>

    <div class="hidden loader" id="scan_user_rights_loader"></div>
    <table id="scan_user_rights_results" class="display" width="100%"></table>


<!--    <h6>DEBUG:</h6>-->
<!--    <pre>--><?php //echo implode("\n", $allUsers); ?><!--</pre>-->

</div>


<script>

    // Render user information table
    $('#scan').on('click', function() {
        startScan();
    });

    // Render orphaned user rights table
    $('#scan-user-rights').on('click', function() {
        startScanUserRights();
    });

    // Handle table clicks
    $('div.container').on('click', 'td.actions .btn:not(.done)', function() {
        //this is the button itself
        let btn = this;
        let data = $(btn).data();

        $.ajax({
            type: 'POST',
            data: { "action": data.action, "data": data },
            success: function(data) {
                console.log("Done with Action", data, btn);
                $(btn).addClass('done').attr('disabled','disabled').off('click');
            }
        })
    });


    function startScan() {
        console.log("StartScan");
        $('#scan').hide();  // attr("disabled", "disabled").off('click');
        $('#scan_loader').removeClass('hidden');

        $.ajax({
            type: 'POST',
            data: {"scan": 1},
            success: function (data) {
                console.log("Done with Post", data);
                $('#scan_loader').addClass('hidden');

                $('#scan_results').DataTable( {
                    data: data,
                    columns: [
                        // { title: "", className: "select-checkbox", orderable: false },
                        { title: "Username", className: "username"},
                        { title: "Email" },
                        { title: "First Visit" },
                        { title: "Last Activity" },
                        { title: "Suspended" },
                        { title: "", className: "actions suspend" },
                        { title: "", className: "actions delete" }
                    ]
                } );
            }
        });
    }


    function startScanUserRights() {
        console.log("StartScanUserRights");
        $('#scan-user-rights').hide();  // attr("disabled", "disabled").off('click');
        $('#scan_user_rights_loader').removeClass('hidden');

        $.ajax({
            type: 'POST',
            data: {"scan-user-rights": 1},
            success: function (data) {
                console.log("Done with Post", data);
                $('#scan_user_rights_loader').addClass('hidden');

                $('#scan_user_rights_results').DataTable({
                    data: data,
                    columns: [
                        {title: "Username", className: "username"},
                        {title: "Project ID"},
                        {title: "Created By"},
                        {title: "Created By Email"},
                        {title: "Created Date"},
                        {title: "", className: "actions email"},
                        {title: "", className: "actions delete"}
                    ]
                });
            }
        });
    }

</script>

<style>

    .loader {
      border: 16px solid #f3f3f3; /* Light grey */
      border-top: 16px solid #3498db; /* Blue */
      border-radius: 50%;
      width: 80px;
      height: 80px;
      animation: spin 2s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .btn.done {
        background-color: #666;
        cursor: not-allowed !important;
    }

</style>
