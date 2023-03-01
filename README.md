# ccw-ipv6
Check Current WAN IPv6 address scripts

## Purpose

When using pfSense with IPv6 prefix delegation and dynamic address allocation, the LAN interface doesn't update its IPv6 address and the clients IPv6 addresses until DHCPv6 has run into timeout. To force an update immediately after the provider has assigned the new IPv6 prefix, this script checks the current WAN IPv6 address and performs an "apply" action to the LAN interface. It has to be run periodically by cron, e.g. every minute to notice if WAN IPv6 address has changed. 

## Installation

tbd

Basic steps:

* put the scripts into an appropriate folder
* modify ccw_ipv6.php: adjust identifier of WAN interface to your needs, e.g. "igb0", "em0" or similar
* add as cronjob: "cd /<SCRIPT FOLDER>/ && php ccw_ipv6.php" and let it run once per minute
  
  
