# username_verification

This is a project to help verify usernames in REDCap for shibboleth or ldap based configurations

## Problem

If you use a non-table based authentication system, REDCap may permit your users to add any username
 they want - even if the username is invalid.  If a user later is added to the system with this
 invalid ID, they might erroneously get access to a project.  To prevent this from happening, this 
 module uses the redcap_custom_verify_username hook to do a lookup with a service you have
 to define.
 
 ## Availalbe Lookup Services
 
 I created a web-based validator for Stanford.  This is a web service that I have running on another 
 server that can access the university's directory.  When a user enters a new username, this module
 will make a POST to a URL ENDPOINT defined in the config.
 
 This EM post a `username` value as was entered by the end-user.  Your web-service should parse
 this and do a lookup.  It can then return three things as a json-encoded object:

 ```json
 [
    "status": TRUE/FALSE,
    "message": "<div>A html message with styling</div>",
    "user": {
      "user_displayname": "Dr. Jane Doe",
      "user_firstname": "Jane",
      "user_lastname": "Doe,",
      "user_email": "jane.doe@university.com"
    }
 ]
```

For messages with a `false` status there is no need to return a `user` object.

Here is an example from the Stanford web-service:
```json
[
    "status":  FALSE
    "message": "The specified SUNet ID, <u>test</u>, does not appear to be valid.<br/><br/> Many users have email aliases (e.g. Jane.Doe@stanford.edu) where the email prefix is not the same as their SUNet ID.  A SUNet ID should be 8 characters or less without any periods or hyphens.<br>Try searching the <div class='text-center'><a href='https://stanford.rimeto.io/search/test' target='_BLANK'> <b>Stanford Directory</b></a></div> to find a user and their SUNet ID<br><br> If you are unable to locate your collaborator, contact them and request their ID."
]
```

If the status is `false` then the message will be rendered in a RED div and the add-user workflow
 will be aborted.
 
If the status is 'true' then the message (if any) will be rendered in a GREEN div.
