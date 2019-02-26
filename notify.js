// This script file exists only to add javascript to the notify by email when adding a new user that has never logged in

$('#send_intro_email').on('click',function(){
    btn      = $(this);
    url      = btn.data('url');
    username = btn.data('username');

    // console.log('Here', btn, url, username);

    $.ajax({
        type: 'POST',
        url: url,
        data: { "username": username },
        success: function(data) {
            if (data == "1") {
                // success
                console.log("Email sent!");
                btn.off('click').after(" <i class='far fa-check-square'></i> Sent");//.remove().;
            }
        }
    });
});

