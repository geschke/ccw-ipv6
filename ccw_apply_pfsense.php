<?php
/*
 * ccw_apply_pfsense.php
 * 
 * Copyright (c) 2023 Ralf Geschke <ralf@kuerbis.org>.
 *
 * NOT part of pfSense (https://www.pfsense.org)
 * 
 * Most parts of this code are extracted from interface.php which is published under the following licenses:
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2023 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2006 Daniel S. Haischt
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


/* parse the configuration and include all functions used below */
require_once("globals.inc");
require_once("config.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("openvpn.inc");
require_once("Net/IPv6.php");
require_once("services.inc");
require_once("rrd.inc");

//global $oldipv6, $curwanipv6, $g;

//print_r($oldipv6);
//print_r($curwanipv6);
//print_r($g);


//log_error("applytest starting");
/*
$interface = "wan";
	$interface_real = get_real_interface($interface, "inet6");
	$curwanipv6 = get_interface_ipv6($interface, true);
print_r($interface);
print_r($interface_real);
print_r($curwanipv6);
*/



/*$interface = "lan";
interface_bring_down($interface);
$reloadall = true;
interface_configure($interface, $reloadall);
*/
/*$if = "lan";
init_config_arr(array('interfaces', $if));
$wancfg = &$config['interfaces'][$if];
print_r($wancfg);
$old_wancfg = $wancfg;
$old_wancfg['realif'] = get_real_interface($if);
print_r($old_wancfg);
*/
//$foo = 'a:1:{s:3:"lan";a:2:{s:5:"ifcfg";a:10:{s:6:"enable";s:0:"";s:2:"if";s:3:"re0";s:5:"descr";s:3:"LAN";s:8:"spoofmac";s:0:"";s:6:"ipaddr";s:12:"192.168.10.2";s:6:"subnet";s:2:"24";s:8:"ipaddrv6";s:6:"track6";s:16:"track6-interface";s:3:"wan";s:16:"track6-prefix-id";s:1:"0";s:6:"realif";s:3:"re0";}s:4:"ppps";a:0:{}}}';

/*
$bar = unserialize($foo);
print_r($bar);
*/
/* Result:
Array
(
    [lan] => Array
        (
            [ifcfg] => Array
                (
                    [enable] =>
                    [if] => re0
                    [descr] => LAN
                    [spoofmac] =>
                    [ipaddr] => 192.168.10.2
                    [subnet] => 24
                    [ipaddrv6] => track6
                    [track6-interface] => wan
                    [track6-prefix-id] => 0
                    [realif] => re0
                )

            [ppps] => Array
                (
                )

        )

)
*/

//$toapplylist = unserialize($foo);



/*foreach ($toapplylist as $ifapply => $ifcfgo) {
        echo "ifapply: ". $ifapply . "\n";
        echo "ifcfgo:\n";
        print_r($ifcfgo);
      }
  
*/
if (is_platform_booting()) {
  syslog(LOG_WARNING, "CCW_IPv6_APPLY: System is booting, exiting.");
  exit(1);
}
if (is_subsystem_dirty('interfaces')) {
  syslog(LOG_WARNING, "CCW_IPv6_APPLY: Interface configuration not applied, it seems that the configuration process is still in work, exiting.");
  exit(2);
}

echo "second:\n";
$ifapply = "lan";
// build interface config array as it comes from unserializing the configuration array when performing "apply" in LAN interface UI section
$ifConfig = config_get_path("interfaces/{$ifapply}");
$ifConfig['realif'] = $ifConfig['if'];
$ifcfgo['ifcfg'] = $ifConfig;
print_r($ifcfgo);



//$ifcfgo = $toapplylist[$ifapply];

// The following part comes from interface.php.
// Perform the "apply" action to restart and reconfigure LAN interface. 
// By doing this, the LAN interface gets the new IPv6 address, so the new 
// configuration can be distributed with DHCPv6 / RA.
//print_r($ifcfgo);
$realif = get_real_interface($ifapply);
echo "realif: " . $realif . "\n";
$ifmtu = get_interface_mtu($realif);
echo "ifmtu: " . $ifmtu . "\n";
if (config_path_enabled("interfaces/{$ifapply}")) {
  echo "config path enabled!\n";
  echo "ifcfgo: ";
  print_r($ifcfgo);
	interface_bring_down($ifapply, false, $ifcfgo);
	interface_configure($ifapply, true);
	if (config_get_path("interfaces/{$ifapply}/ipaddrv6") == "track6") {
    echo "in config get path track6\n";
		/* call interface_track6_configure with linkup true so
		   IPv6 IPs are added back. dhcp6c needs a HUP. Can't
		   just call interface_configure with linkup true as
		   that skips bridge membership addition.
		*/
		$wancfg = config_get_path("interfaces/{$ifapply}");
    echo "wancfg:\n";
    print_r($wancfg);
		interface_track6_configure($ifapply, $wancfg, true);
	}
} else {
  echo "in else mit interface bring down\n";
	interface_bring_down($ifapply, true, $ifcfgo);
}
echo "after all, now restart interface services\n";
restart_interface_services($ifapply, $ifcfg['ipaddrv6']);
			
/* sync filter configuration */
setup_gateways_monitor();

clear_subsystem_dirty('interfaces');

$retval |= filter_configure();

enable_rrd_graphing();

