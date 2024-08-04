#Multi LDAP authentication and LDAP Sync v1.9.15 for osTicket 
=====================================
Plugin for OS Ticket that allows for authentication with multiple domains and servers for agents and/or clients on osTicket also syncs user defined attributes from AD LDAP. 
Works and tested with version 1.10 to v1.17+ and PHP 8+
|CURRENTLY DO NOT SUPPORT MULTIPLE INSTANCES|


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
 
 ![image](https://github.com/user-attachments/assets/89b00289-ccd4-44aa-9fe6-627faa453408)

Sync Email

![image](https://user-images.githubusercontent.com/2892474/165946917-db6031dc-36ba-4470-8b54-b02154b50bfd.png)

Example of sync report in my environment.
![image](https://github.com/user-attachments/assets/6ba05d32-4b97-4bf0-b372-ffe9817b2679)

Installing
==========

### Prebuilt

simply create a folder in the "includes\plugins\multi-ldap" on your osticket install

Configuration 
=============
It is pretty stright forward just when adding the second domain make user you put a "," or ";" where needed.
see image below

![image](https://github.com/user-attachments/assets/19d5b1d1-fbe7-4661-9d68-a672fb0e96df)

Sync Settings

![image](https://github.com/user-attachments/assets/6712c595-5dce-4545-8b4d-8411f76a35a6)

CRON JOBS required for user syncing to run.

In my environment we have a Parent and Child domain
Parent domain 8000+ users
Child domain 20,000+ users

![image](https://github.com/user-attachments/assets/8aad036c-1584-450a-8eb3-34743f84bc83)

It syncs both the agents and users without issues with about 1000+ users registered automatically.
Made plugin backward compatible with older versions.

Bug fixes
===========
Syncing bug 
Added Instances support
removed "ldap.clinet" references to avoid conflicts.
sync_data table not refrenced or updated properly.
change instance logic 
updated and changed debug code.

Roadmap
==========
Better Instance support in plugin database.(almost done)
Proper manual Sync button
UTF8 support for languages.
Ldap caching for large LDAP domains
Planning on adding a TAB feature to make setting section easier to manage.
Add users from AD to helpdesk automatically.

### From source

to be updated.........
