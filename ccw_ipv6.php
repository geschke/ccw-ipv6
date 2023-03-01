<?php
/**
 * Check Current WAN IPv6 script (CCW_IPv6)
 * 
 * Copyright (c) 2023 Ralf Geschke <ralf@kuerbis.org>.
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
 
// config section

// WAN interface system identifier, as found in "Interface assignments" section. For example: "igb0", "igb3", "em0", "re0" or similar.
$wanInterface = "igb0";

// Full path of files used in this script. In most cases these options don't need to be changed.
$currentIpFile = "/tmp/_current_wan_ipv6.txt";
$currentIpFileLock = "/tmp/_current_wan_ipv6.lock";

/////////////// No further configuration necessary below this line ////////////////

// define some syslog messages
const SYSLOG_PREFIX = "CCW_IPv6: ";
const ERR_COULD_NOT_GET_IP = "Could not get IP from interface ";
const ERR_COULD_NOT_FIND_CURRENT_IP = "Could not find current IP of WAN interface";
const ERR_COULD_NOT_RECOGNIZE_CURRENT_IP = "Current IP found, but failed to recognize";
const ERR_COULD_NOT_WRITE_IP_FILE = "Could not write current IP into file";
const ERR_PROCESS_RUNNING = "Could not get lock, maybe another process is still running?";
const ERR_LOCK_TIMEOUT = "Lock timeout reached, clear previous locks.";
const INFO_STILL_VALID = "Current WAN IP is identical to IP found in file, so still valid: ";
const INFO_NEW_IP_APPLIED = "Current IP settings applied successfully: ";

/**
 * getIp reads all IPv6 information of submitted interface
 */
function getIp($interface) {
  $interface = escapeshellarg($interface);
  $pattern = "/.*inet6(.*)/";
  $text = shell_exec("ifconfig $interface");
  preg_match_all($pattern, $text, $matches);
  return $matches[1];
}

/**
 * findCurrentIp tries to find current IPv6 address in submitted array by filtering 
 */
function findCurrentIp(array $ips) {
  $currentIp = "";
  foreach ($ips as $ip) {
    $ip = trim($ip);
    print($ip . "\n  ");
  
    if (!preg_match("/^f[cdef][0-9a-f][0-9a-f].*$/", $ip) 
      && !preg_match("/^fe80.*$/", $ip) 
      && !preg_match("/^.*\sdeprecated\s.*$/", $ip) 
      ) {
      print "found!\n";
      $currentIp = $ip;
      break;
    } 
  }
  return $currentIp;  
}

function cleanIp($ip) {
  preg_match("/^(.*)\s.*$/U",$ip,$ma);
  if (count($ma)) {
    return $ma[1];
  }
  return "";
}


function saveCurrentIp($filename,$ip) {
  $res = file_put_contents($filename, $ip);
  if ($res === false) {
    return false;
  }
  return true;
}

function readIpFromFile($filename) {
  if (!file_exists($filename)) {
    return false;
  }
  $ip = file_get_contents($filename);
  if ($ip === false) {
    return false;
  }
  print_r($ip);
  return $ip;
}

function applyInterfaceLAN() {
  exec("php ccw_apply_pfsense.php", $output, $retval);
  if ($retval != 0) {
    return false;
  }
  return true;
}

function isLocked($lockFile, $seconds = 600) {
  if (file_exists($lockFile)) {
    // older than 10 minutes?
    $mTime = filemtime($lockFile);
    echo "$filename wurde zuletzt modifiziert: " . date ("F d Y H:i:s.", $mTime) . " ---- $mTime\n";
    $curTime = time();
    echo "current time: " .  date ("F d Y H:i:s.", $curTime) . " ..... $curTime\n";
    if (($curTime - $mTime) > $seconds) {
      echo "lock timeout, clear file\n";
      syslog(LOG_WARNING, SYSLOG_PREFIX . ERR_LOCK_TIMEOUT);
      unlink($lockFile);
      return false;
    } 
    return true;
  }
  return false;
}

function setLock($lockFile) {
  return touch($lockFile);
}

function removeLock($lockFile) {
  return unlink($lockFile);
}

function cleanupAndExit($errorCode, $logLevel, $logText) {
  global $currentIpFileLock;
  syslog($logLevel, $logText);
  removeLock($currentIpFileLock);
  exit($errorCode);
}

function main($wanInterface, $currentIpFile) {
  global $currentIpFileLock;
  if (isLocked($currentIpFileLock)) {
    echo "is locked!\n";
    syslog(LOG_WARNING, SYSLOG_PREFIX . ERR_PROCESS_RUNNING);
    exit(10);
  }

  echo "not locked, set lock\n";
  setLock($currentIpFileLock);
 
  $ips = getIp($wanInterface);
  if (count($ips) < 1) {
    cleanupAndExit(1, LOG_WARNING, SYSLOG_PREFIX . ERR_COULD_NOT_GET_IP . $wanInterface);
  }

  $currentIp = findCurrentIp($ips);
  if ($currentIp == "") {
    cleanupAndExit(2, LOG_WARNING, SYSLOG_PREFIX . ERR_COULD_NOT_FIND_CURRENT_IP);
  }

  $currentIp = cleanIp($currentIp);
  if ($currentIp == "") {
    cleanupAndExit(3, LOG_WARNING, SYSLOG_PREFIX . ERR_COULD_NOT_RECOGNIZE_CURRENT_IP);
  }

  echo "current IP: " . $currentIp . "\n";

  $fileIp = readIpFromFile($currentIpFile);
  if ($fileIp === false) {
    echo "no previous IP file or error\n";
    applyInterfaceLAN();
    $ok = saveCurrentIp($currentIpFile, $currentIp);
    if (!$ok) {
      cleanupAndExit(4, LOG_WARNING, SYSLOG_PREFIX . ERR_COULD_NOT_WRITE_IP_FILE);

    }
    cleanupAndExit(0, LOG_WARNING, SYSLOG_PREFIX . INFO_NEW_IP_APPLIED . $currentIp); // todo: switch to LOG_INFO
  } else {
    echo "current IP from file: " . $fileIp . "\n";
    //$currentIp .= "lalala";
    if ($currentIp == $fileIp) {
      echo "current IP is identical to file IP, so still valid, do nothing\n";
      cleanupAndExit(0, LOG_WARNING, SYSLOG_PREFIX . INFO_STILL_VALID . $currentIp); // todo: switch to LOG_INFO

    } else {
      echo "current IP differs from file IP, do something and save new ip into file!\n";
      applyInterfaceLAN();      
      $ok = saveCurrentIp($currentIpFile, $currentIp);
      if (!$ok) {
        cleanupAndExit(5, LOG_WARNING, SYSLOG_PREFIX . ERR_COULD_NOT_WRITE_IP_FILE);

      }
      cleanupAndExit(0, LOG_WARNING, SYSLOG_PREFIX . INFO_NEW_IP_APPLIED . $currentIp); // todo: switch to LOG_INFO
    }

  }
}

// todo: check pfSense config locks (interface.apply and /var/run/ something)
// todo: implement restart logic, implement file locking stuff
main($wanInterface, $currentIpFile);
