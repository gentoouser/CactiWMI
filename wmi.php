#!/usr/bin/php -q
<?php
/**
 * CactiWMI
 * Version 0.0.8-git
 *
 * Updated by Paul Fuller
 * Date 2017-03-17
 *  Added  better debugging output
 *  Changed it so filter values can use + for spaces and ~ for :.
 *  Added extra variable scrubbing to remove spaces and single quotes.
 *  Better condition validation
 * Date 2015-06-17
 *  Merged fixes from https://github.com/jhg03a/CactiWMI/blob/master/wmi.php (jhg03a)
 *
 * Copyright (c) 2008-2010 Ross Fawcett
 *
 * This file is the main application which interfaces the wmic binary with the
 * input and output from Cacti. The idea of this is to move the configuration
 * into Cacti rather than creating a new script for each item that you wish to
 * monitor via WMI.
 *
 * The only configurable options are listed under general configuration and are
 * the debug level, log location and wmic location. Other than that all other
 * configuration is done via the templates.
 */

// general configuration
$wmiexe = trim(`which wmic`); // executable for the wmic command
if (empty($wmiexe)) {
        print "You must install the wmi client to use this script.\n\n";
        exit;
}
$pw_location = '/etc/cacti/'; // location of the password files, ensure the trailing slash
$log_location = '/var/log/cacti/wmi/'; // location for the log files, ensure trailing slash
$dbug = 0; // debug level 0,1 or 2

// globals
$output = null; // by default the output is null
$inc = null; // by default needs to be null
$sep = " "; // character to use between results
$dbug_levels = array(0,1,2); // valid debug levels
$version = '0.0.8-git'; // version
$namespace = escapeshellarg('root\CIMV2'); // default name-space
$columns = '*'; // default to select all columns
$trimchr = '\'"\\'; // default characters to remove
$condition_key = null;
$condition_val = null;
// grab arguments
$args = getopt("h:u:w:c:k:v:n:d:");

$opt_count = count($args); // count number of options, saves having to recount later
$arg_count = count($argv); // count number of arguments, again saving recounts further on


function display_help() {
        echo "wmi.php version $GLOBALS[version]\n",
             "\n",
             "Usage:\n",
                 "       -h <hostname>         Hostname of the server to query. (required)\n",
                 "       -u <credential path>  Path to the credential file. See format below. (required)\n",
                 "       -w <wmi class>        WMI Class to be used. (required)\n",
                 "       -n <namespace>        What namespace to use. (optional, defaults to root\\CIMV2)\n",
                 "       -c <columns>          What columns to select. (optional, defaults to *)\n",
                 "       -k <filter key>       What key to filter on. (optional, default is no filter)\n",
                 "       -v <filter value>     What value for the key. (required, only when using filter key)\n",
                 "       -d <debug level>      Debug level. (optional, default is none, levels are 1 & 2)\n",
                 "\n",
                 "                             All special characters and spaces must be escaped or enclosed in single quotes!\n",
                 "\n",
             "Example: wmi.php -h 10.0.0.1 -u /etc/wmi.pw -w Win32_ComputerSystem -c PrimaryOwnerName,NumberOfProcessors -n 'root\\CIMV2' \n",
                 "\n",
                 "Password file format: Plain text file with the following 3 lines replaced with your details.\n",
                 "\n",
                 "                      username=<your username>\n",
                 "                      password=<your password>\n",
                 "                      domain=<your domain> (can be WORKGROUP if not using a domain)\n",
                 "\n";
        exit;
}

if ($opt_count > 0) { // test to see if using new style arguments and if so default to use them
        $host =  trim($args['h'],$trimchr); // hostname in form xxx.xxx.xxx.xxx
        if (empty($host)) { display_help();} // Test for required data in variable
        $credential =  trim($args['u'],$trimchr); // credential from wmi-logins to use for the query
        if (empty($credential)) { display_help();} // Test for required data in variable
        $wmiclass =  trim($args['w'],$trimchr); // what wmic class to query in form Win32_ClassName
        if (empty($wmiclass)) { display_help();} // Test for required data in variable

        if (isset($args['d'])) {
                if (in_array($args['d'],$dbug_levels)) { // enables debug mode when the argument is passed (and is valid)
                        $dbug = $args['d'];
                };
        };
        if (!empty(trim($args['c'],$trimchr))) {
                $columns = trim($args['c'],$trimchr); // default characters filtered out
        }

        if (isset($args['n']) && (!empty(trim($args['n'],$trimchr))))  { // test to check if name-space was passed
                 $temparray = explode("\\",trim(escapeshellarg($args['n'],$trimchr))); // Remove any extra backslashes
                foreach ($temparray as &$tempvalue) {
                        if (!empty($tempvalue)) {
                                $namespace = $namespace.$tempvalue."\\";
                        };
                };
                if (substr($namespace, -1) == "\\") {
                        $namespace = substr($namespace, 0, -1);
                };

        };

        if (isset($args['k']) && (isset($args['v'])  && (!empty(trim($args['k'],$trimchr)))) && (!empty(trim($args['v'],$trimchr)))) { // check to see if a filter is being used, also check to see if it is "none" as required to work around cacti...
                $condition_key = trim(escapeshellarg(str_replace("+"," ",$args['k'])),$trimchr); // the condition key we are filtering on, and also strip out any slashes (backwards compatibility) and spaces
                $condition_val = "'".trim(escapeshellarg(str_replace("+"," ",$args['v'])),$trimchr)."'"; // the value we are filtering with, and also strip out any slashes (backwards compatibility) and spaces
        };
} elseif ($opt_count == 0 && $arg_count == 1) { // display help if old style arguments are not present and no new style arguments passed
        display_help();
} elseif ($opt_count == 0 && $arg_count > 1) { // if using old style arguments, process them accordingly
        $host = $argv[1]; // hostname in form xxx.xxx.xxx.xxx
        $credential = $argv[2]; // credential from wmi-logins to use for the query
        $wmiclass = $argv[3]; // what wmic class to query in form Win32_ClassName
        $columns = $argv[4]; // what columns to retrieve
        if (isset($argv[5])) { // if the number of arguments isnt above 5 then don't bother with the where = etc
                $condition_key = $argv[5];
                $condition_val = escapeshellarg($argv[6]);
        }
}else {
        display_help();
}

$wmiquery = 'SELECT '.$columns.' FROM '.$wmiclass; // basic query built
if ($condition_key !=null && $condition_val != null) {
        $wmiquery = $wmiquery.' WHERE '.$condition_key.'='.$condition_val; // if the query has a filter argument add it in
}
$wmiquery = '"'.$wmiquery.'"'; // encapsulate the query in " "

$wmiexec = $wmiexe.' --namespace='.$namespace.' --authentication-file='.$credential.' //'.$host.' '.$wmiquery. ' 2>/dev/null'; // setup the query to be run and hide error messages

exec($wmiexec,$wmiout,$execstatus); // execute the query and store output in $wmiout and return code in $execstatus

if ($execstatus != 0) {
        $dbug = 1;
        echo "\n\nReturn code non-zero, debug mode enabled!\n\n";
}

if ($dbug == 1) { // basic debug, show output in easy to read format and display the exact execution command
        echo "\n Hostname Raw: \e[0;31m".$args['h']."\e[0m Formatted: \e[0;32m".$host."\e[0m";
        echo "\n Credential Raw: \e[0;31m".$args['u']."\e[0m Formatted: \e[0;32m".$credential."\e[0m";
        echo "\n WMI Class Raw: \e[0;31m".$args['w']."\e[0m Formatted: \e[0;32m".$wmiclass."\e[0m";
        echo "\n Columns Raw: \e[0;31m".$args['c']."\e[0m Formatted: \e[0;32m".$columns."\e[0m";
        echo "\n NameSpace Raw: \e[0;31m".$args['n']."\e[0m Formatted: \e[0;32m".$namespace."\e[0m";
        echo "\n Condition Key Raw: \e[0;31m".$args['k']."\e[0m Formatted: \e[0;32m".$condition_key."\e[0m";
        echo "\n Condition Value Raw: \e[0;31m".$args['v']."\e[0m Formatted: \e[0;32m".$condition_val."\e[0m";
        echo "\n\n".$wmiexec."\nExec Status: ".$execstatus."\n\n";

}
if ($dbug == 2) { // advanced debug, logs everything to file for full debug
        $dbug_log = $log_location.'dbug_'.$host.'.log';
        $fp = fopen($dbug_log,'a+');
        if ($fp) { //Test that log file is open.
                $dbug_time = date('l jS \of F Y h:i:s A');
                fwrite($fp,"Time: $dbug_time\nWMI Class: $wmiclass\nCredential: $credential\nColumns: $columns\nCondition Key: $condition_key\nCondition Val: $condition_val\nQuery: $wmiquery\nExec: $wmiexec\nOutput:\n".$wmiout[0]."\n".$wmiout[1]."\n");
        } else {
                echo "\nUnable to open log file. Either file does not exist or user does not have access to write to it.\n";
                // Skip future writes.
                $dbug = 1;
        }
}

// If client failed parsing code is going to fail so drop out without verbose errors.
if ($execstatus) {
        echo "WMI Client Output: " . implode("\n", $wmiout) . "\n";
        exit($execstatus);
}
// Chomp any errors that wmic might have thrown, but still worked
$classindex = -1;
for($i=0;$i<count($wmiout);$i++)
{
        if(0 === strpos($wmiout[$i], 'CLASS: '))
        {
                $classindex = $i;
                break;
        }
}
// Abort is the wmi output isn't normally structured
if($classindex == -1)
{
        echo "WMI Class Chomp Failed!\nWMI Client Output: ".implode("\n", $wmiout)."\n";
        exit(1);
}
for($i=0;$i<$classindex;$i++)
{
        unset($wmiout[$i]);
}
// reindex the array output
$wmiout = array_values($wmiout);

$wmi_count = count($wmiout); // count the number of lines returned from wmic, saves recounting later

if ($wmi_count > 0) {

        $names = explode('|',$wmiout[1]); // build the names list to dynamically output it

        for($i=2;$i<$wmi_count;$i++) { // dynamically output the key:value pairs to suit cacti
                $data = explode('|',$wmiout[$i]);
                if ($dbug == 2) {
                        fwrite($fp,$wmiout[$i]."\n");
                };
                $j=0;
                foreach($data as $item) {
                        if ( $wmi_count > 3 ) { $inc = $i-2; }; // if there are multiple rows returned add an incremental number to the returned key name
                                if ($dbug == 1) {
                                        //better format output for troubleshooting
                                        $output = $output.$names[$j++].$inc.':'.str_replace(array(' '),array('+'),$item)."\n";
                                } else {
                                        $output = $output.$names[$j++].$inc.':'.str_replace(array(' '),array('+'),$item).$sep;
                                };
                        };
        }

}

if ($dbug == 2) {
        fwrite($fp,"Output to Cacti: $output\n\n\n");
        fclose($fp);
}
if ($dbug != 1 ) {
        echo substr($output,0,-1); // strip of the trailing space just in case cacti doesn't like it
} else {
        echo $output; // cleans up output when debugging in console
}
?>
