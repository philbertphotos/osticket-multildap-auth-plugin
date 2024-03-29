#Multi LDAP authentication and LDAP Sync v1.8 for osTicket 
=====================================
Plugin for OS Ticket that allows for authentication with multiple domains and servers for agents and/or clients on osTicket also syncs user defined attributes from AD LDAP. 
Works and tested with version 1.10 to v1.17+ and PHP 8+

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
 - manual sync button
 - create users and staff even if the system is private/closed mode.
 - Fixed issue with upgrading from 1.5+ to 1.7
 - Support for Plugin Instances
 
 User Lookup
 
 ![image](https://user-images.githubusercontent.com/2892474/173096208-20841dbe-53d0-4cd8-b29e-28067572dac1.png)

Sync Email

![image](https://user-images.githubusercontent.com/2892474/165946917-db6031dc-36ba-4470-8b54-b02154b50bfd.png)

Example of sync report in my environment.

![image](https://user-images.githubusercontent.com/2892474/173095409-b31fb9a6-bf32-4a0a-b40a-b01f284ea19f.png)


Installing
==========

### Prebuilt

simply create a folder in the "includes\plugins\multi-ldap" on your osticket install

Configuration 
=============
It is pretty stright forward just when adding the second domain make user you put a "," or ";" where needed.
see image below
![Alt text](http://osticket.com/forum/uploads/FileUpload/25/721454d41a5d02335570dc6db6eb59.png "Config Page")
CRON JOBS required for user syncing to run.

In my environment we have a Parent and Child domain
Parent domain 8000+ users
Child domain 20,000+ users

It syncs both the agents and users without issues with about 1000+ users registered automatically.
Made plugin backward compatible with older versions.

Bug fixes
===========
Syncing bug 
Added Instances support
removed "ldap.clinet" references to avoid conflicts.
sync_data table not refrenced or updated properly.

Roadmap
==========
Better Instance support in plugin database.
Proper manual Sync button
UTF8 support for languages.
Ldap caching for large LDAP domains
Planning on adding a TAB feature to make setting section easier to manage.
Add users from AD to helpdesk automatically.

### From source

to be updated.........
