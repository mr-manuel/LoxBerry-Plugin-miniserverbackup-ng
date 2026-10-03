<?php
######################################################################################
#    Miniserver Backup NG API                                                        #
#                                                                                    #
# Usage:                                                                             #
# http://loxberry/plugins/miniserverbackup/API.php?search=504F94ABCDEF               #
# http://loxberry/plugins/miniserverbackup/API.php?search=192.168.178.200            #
# Returns Date of last save as String or ? if not found or INF_0167_API_NEVER_SAVED  #
######################################################################################

header("Content-type: text/plain");
require_once "loxberry_system.php";
require_once "loxberry_log.php";
// Read language
$L = LBSystem::readlanguage("language.ini");
$plugin_config_file = $lbpconfigdir."/miniserverbackup-ng.cfg";
$params = ["name" => $L["MINISERVERBACKUP.INF_0165_API_NAME"]." "];
$log = LBLog::newLog ($params);
LOGSTART ($L["MINISERVERBACKUP.INF_0166_API_CALLED"]);

// Error Reporting
error_reporting(E_ALL);
ini_set("display_errors", false);
ini_set("log_errors", 1);
$summary            = array();
$output             = "?";

// Plugindata
$plugindata = LBSystem::plugindata();
$plugin_cfg_handle = @fopen($plugin_config_file, "r");
if ($plugin_cfg_handle)
{
    while (!feof($plugin_cfg_handle))
    {
        $line_of_text = fgets($plugin_cfg_handle);
        if (strlen($line_of_text) > 3)
        {
            $config_line = explode('=', $line_of_text);
            if ($config_line[0])
            {
                if (!isset($config_line[1])) $config_line[1] = "";

                if ( $config_line[1] != "" )
                {
                    $plugin_cfg[$config_line[0]]=preg_replace('/\r?\n|\r/','', str_ireplace('"','',$config_line[1]));
                    LOGINF($L["MINISERVERBACKUP.INF_0064_CONFIG_PARAM"]." ".$config_line[0]."=".$plugin_cfg[$config_line[0]]);
                }
            }
        }
    }
    fclose($plugin_cfg_handle);
}

foreach ($_REQUEST as $request_key => $request_value)
{
    $plugin_cfg[strtoupper($request_key)] = strtoupper($request_value);
}

$plugin_cfg["VERSION"] = LBSystem::pluginversion();

ksort($plugin_cfg);

// Miniserver data comes from the LoxBerry library (general.json), not from the legacy general.cfg
$miniservers = LBSystem::get_miniservers();
# If no miniservers are defined, return NULL
if (!is_array($miniservers) || count($miniservers) < 1)
{
    LOGERR ($L["ERRORS.ERR_0001_NO_MINISERVERS_CONFIGURED"]);
    LOGEND ("");
    die($output);
}

$ms = $miniservers;

if (!is_array($ms))
{
    LOGERR($L["ERRORS.ERR_0001_NO_MINISERVERS_CONFIGURED"]);
    LOGEND("");
    die($L["ERRORS.ERR_0001_NO_MINISERVERS_CONFIGURED"]);
}

$max_ms = count($ms);
$found  = 0;
foreach ($ms as $ms_key => $ms_value)
{
    if ( $plugin_cfg["SEARCH"] == strtoupper($ms_value['CloudURL']) || $plugin_cfg["SEARCH"] == strtoupper($ms_value['IPAddress']) )
    {
        $output = $L["MINISERVERBACKUP.INF_0167_API_NEVER_SAVED"];
        if ( isset($plugin_cfg["LAST_SAVE".$ms_key]) )
        {
            $stamp = $plugin_cfg["LAST_SAVE".$ms_key];
            LOGDEB("LAST_SAVE".$ms_key." => ".$stamp);
            if ( $stamp != "" )
            {
                $output = date($L["GENERAL.DATE_TIME_FORMAT_PHP"],date($stamp));
            }
        }
        LOGOK($L["MINISERVERBACKUP.INF_0168_API_LAST_SAVED"]." (#$ms_key) ".$plugin_cfg["SEARCH"]." => ".$output);
        $found = 1;
    }
}
if ( $found == 0 )
{
    LOGWARN($L["MINISERVERBACKUP.INF_0169_API_MS_NOT_FOUND"]." ".$plugin_cfg["SEARCH"]);
    $output = $L["MINISERVERBACKUP.INF_0169_API_MS_NOT_FOUND"]." ".$plugin_cfg["SEARCH"];
}
echo $output;
LOGEND("");
