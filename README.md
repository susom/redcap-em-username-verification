# Username Verification

This is a project to help verify usernames in REDCap for shibboleth or ldap based configurations

## Problem

If you use a non-table based authentication system, REDCap may permit your users to add any username
 they want - even if the username is invalid.  If a user later is added to the system with this
 invalid ID, they might erroneously get access to a project.  To prevent this from happening, this 
 module uses the redcap_custom_verify_username hook to do a lookup with a service you have
 to define.
 
 ## How it Works
 
 This module uses a lookup-service to determine if a username is valid.
 
 I created a web-based validator for Stanford.  This uses a different web service that I have running
 on another server that can access the university's directory.  When a user enters a new username, this module
 will make a POST to a URL ENDPOINT defined in the config.  You could create your own endpoint and customize
 url in the config.
 
 The em will post a `username` value as was entered by the end-user.  Your web-service should parse
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

For messages with a `false` status there is no need to return a `user` object.  If you return a 'message' it will be highlighted in RED.

Here is an example of a 'false' response from our Stanford web-service:
```json
[
    "status":  FALSE
    "message": "The specified SUNet ID, <u>test</u>, does not appear to be valid.<br/><br/> Many users have email aliases (e.g. Jane.Doe@stanford.edu) where the email prefix is not the same as their SUNet ID.  A SUNet ID should be 8 characters or less without any periods or hyphens.<br>Try searching the <div class='text-center'><a href='https://stanford.rimeto.io/search/test' target='_BLANK'> <b>Stanford Directory</b></a></div> to find a user and their SUNet ID<br><br> If you are unable to locate your collaborator, contact them and request their ID."
]
```

If the status is `true` then the message (if any) will be rendered in a GREEN div.

Here is an example success message which can be used as an affirmation:
```
Susan C Weber (scweber) will be added to this project but has never used this REDCap server before.
Click here to send them an email with a link to the project:  [BUTTON]

Please be sure you to configure appropriate user rights for this user.
We strongly recommend using User Roles to manage project rights.
If you are using Data Access Groups, assign the user to an appropriate DAG.
If you have questions about permissions or user roles, contact redcap-help@lists.stanford.edu
```
You can see that we know this user's full name because it was returned from the lookup -- this allows us to present
a nicer message to the end users.  We also have an option for the user to press a button and send an email to the
new user with an introduction to REDCap.  The normal REDCap mechanism does not support this since the new record
isn't really a user record, just a user-rights record.

It is your responsiblitiy to properly secure your webservice that REDCap uses.  For example, you might want to embed
a unique token in the URL and you may want to filter by IP address so it only accepts requests from your REDCap
server's IP address.


## Adding A Different Lookup

I made a 'stub' for someone to implement an LDAP lookup.  All you would have to do is build out the LADPVerifier class
and add the parameters to the config.json.

## Config

In order to use the email option you need to enable this module for all projects (as the email uses a project url).
This can be done globally in the system EM configuration page at the top.