#Multi LDAP authentication and LDAP Sync for osTicket v1.2
=====================================
Plugin for OS Ticket that allows for authentication with multiple domains and servers for agents and/or clients on osTicket also syncs user defined attributes from AD LDAP. 

Features
========
 - Multiple domain and server support.
 - SSL connection support.
 - LDAP login for both agents and clients (can be toggled for neither, either, or both).
 - Combines users in all domains into one for seamless searches.
 - Creates user accounts and syncs information as needed.
 - Sync accounts in LDAP with user defined schedule.
 - Disables or Enables Osticket users based on LDAP
 - Syncs all attributes only on users that have change via AD time and date.
 - Custom defined ldap map attributes 
 - Keeps track of updated users
 - Schedule is activated based on the cron job
 
 ![Alt text](http://osticket.com/forum/uploads/FileUpload/08/6bb40e0ef6b5739ec010c9f1391a68.png "User lookup")

Installing
==========

### Prebuilt

simply create a folder in the "includes\plugins\multi-ldap" on your osticket install

Configuration 
=============
It is pretty stright forward just when adding the second domain make user you put a "," or ";" where needed.
see image below
![Alt text](http://osticket.com/forum/uploads/FileUpload/25/721454d41a5d02335570dc6db6eb59.png "Config Page")


Roadmap
==========
Planning on adding a TAB feature to make setting section easier to manage.
Ldap Query caching.
### From source

to be updated.........
