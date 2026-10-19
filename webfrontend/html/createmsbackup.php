<?php
// LoxBerry Miniserver Backup NG Plugin
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de

// Header output
header('Content-Type: text/plain; charset=utf-8');

// Calculate running time
$start =  microtime(true);
$last_save_stamp = roundToPrevMin(new DateTime(),5)->format('U'); // Round time to prev 5 min

// Go to the right directory
chdir(__DIR__);

// Include System Lib
require_once __DIR__ . '/bootstrap.php';

$inc_backups_to_keep    = 0;                                                   // Keep this number of incremental backups when 7z format is used
$plugin_config_file     = $lbpconfigdir."/miniserverbackup-ng.cfg";            // Plugin config
$workdir_data           = $lbpdatadir."/workdir";                              // Working directory, on RAM-Disk by default due to $workdir_tmp
$savedir_path           = $lbpdatadir."/.currentbackup";                       // Directory to hold latest backup to compare with
$backup_file_prefix     = "Backup_";                                           // Backup name prefix
$workdir_tmp            = "/tmp/miniserverbackup-ng";                          // The $workdir_data folder will be linked to this target
$minimum_free_workdir   = 134217728;                                           // In Bytes. Let minumum 128 MB free on workdir (RAMdisk in $workdir_tmp by default)
$bkp_dest_dir           = $lbphtmldir."/backups";                              // Where the browser on admin page points to
$default_finalstorage   = $lbpdatadir."/backups_storage";                      // Default localstorage
$backupstate_file       = $lbphtmldir."/miniserverbackup-ng_state.txt";        // State file, do not change! Linked to $backupstate_tmp
$backupstate_tmp        = "/tmp/miniserverbackup-ng_state.txt";                // State file on RAMdisk, do not change!
$cloud_requests_file    = "/tmp/miniserverbackup-ng_cloudrequests.txt";        // Request file on RAMdisk, do not change!
$logfileprefix          = LBPLOGDIR."/miniserverbackup-ng_";
$logfilesuffix          = ".txt";
$logfilename            = $logfileprefix.date("Y-m-d_H\hi\ms\s",time()).$logfilesuffix;
$L                      = LBSystem::readlanguage("language.ini");
$logfiles_to_keep       = 24;                                                  // Number of logfiles to keep (also done by LoxBerry Core /sbin/log_maint.pl)
$resultarray            = array();
$params = [
    "name" => $L["MINISERVERBACKUP.INF_0131_BACKUP_NAME"],
    "filename" => $logfilename,
    "addtime" => 1];

$log = LBLog::newLog ($params);
$date_time_format       = "m-d-Y h:i:s a";                         // Default Date/Time format
if (isset($L["GENERAL.DATE_TIME_FORMAT_PHP"])) $date_time_format = $L["GENERAL.DATE_TIME_FORMAT_PHP"];
LOGSTART ($L["MINISERVERBACKUP.INF_0130_BACKUP_CALLED"]);

// Error Reporting
error_reporting(E_ALL);
ini_set("display_errors", false);
ini_set("log_errors", 1);

$summary              = array();
$at_least_one_error   = 0;
$at_least_one_warning = 0;
function debug($line,$message = "", $loglevel = 7)
{
    global $L, $plugindata, $summary, $miniserver,$msno,$plugin_cfg,$at_least_one_error,$at_least_one_warning,$logfilename;
    if ( $plugindata['PLUGINDB_LOGLEVEL'] >= intval($loglevel) )
    {
        $message = preg_replace('/["]/','',$message); // Remove quotes => https://github.com/mschlenstedt/Loxberry/issues/655
        $raw_message = $message;
        if ( $plugindata['PLUGINDB_LOGLEVEL'] >= 6 && $L["ERRORS.LINE"] != "" ) $message .= " ".$L["ERRORS.LINE"]." ".$line;
        if ( isset($message) && $message != "" )
        {

            switch ($loglevel)
            {
                case 0:
                    // OFF
                    break;
                case 1:
                    $message = "<ALERT>".$message;
                    LOGALERT  (         $message);
                    array_push($summary,$message);
                    break;
                case 2:
                    $message = "<CRITICAL>".$message;
                    LOGCRIT   (         $message);
                    array_push($summary,$message);
                    break;
                case 3:
                    $message = "<ERROR>".$message;
                    LOGERR    (         $message);
                    array_push($summary,$message);
                    break;
                case 4:
                    $message = "<WARNING>".$message;
                    LOGWARN   (         $message);
                    array_push($summary,$message);
                    break;
                case 5:
                    $message = "<OK>".$message;
                    LOGOK     (         $message);
                    break;
                case 6:
                    $message = "<INFO>".$message;
                    LOGINF   (         $message);
                    break;
                case 7:
                default:
                    $message = $message;
                    LOGDEB   (         $message);
                    break;
            }
            if ( isset($msno) )
            {
                $msi = "MS#".$msno." ".$miniserver['Name'];
            }
            else
            {
                $msi = "";
            }
            if ( $loglevel == 4 )
            {
                $at_least_one_warning = 1;
                $search  = array('<WARNING>');
                $replace = array($L["LOGGING.NOTIFY_LOGLEVEL4"]);
                $notification = array (
                "PACKAGE" => LBPPLUGINDIR,
                "NAME" => $L['GENERAL.MY_NAME']." ".$msi,
                "MESSAGE" => str_replace($search, $replace, $raw_message),
                "SEVERITY" => 4,
                "LOGFILE"    => $logfilename);
                if ( $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "on" || $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "1" ) notify_ext ($notification);
                return;
            }
            if ( $loglevel <= 3 )
            {
                $at_least_one_error = 1;
                @system("php -f ".dirname($_SERVER['PHP_SELF']).'/ajax_config_handler.php LAST_ERROR'.$msno.'='.roundToPrevMin(new DateTime(),5)->format('U').' >/dev/null 2>&1');
                $search  = array('<ALERT>', '<CRITICAL>', '<ERROR>','<WARNING>');
                $replace = array($L["LOGGING.NOTIFY_LOGLEVEL1"],$L["LOGGING.NOTIFY_LOGLEVEL2"],$L["LOGGING.NOTIFY_LOGLEVEL3"],$L["LOGGING.NOTIFY_LOGLEVEL4"]);
                $notification = array (
                "PACKAGE" => LBPPLUGINDIR,
                "NAME" => $L['GENERAL.MY_NAME']." ".$msi,
                "MESSAGE" => str_replace($search, $replace, $raw_message),
                "SEVERITY" => 3,
                "LOGFILE"    => $logfilename);
                if ( $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "on" ||$plugin_cfg["MSBACKUP_USE_NOTIFY"] == "1" ) notify_ext ($notification);
                return;
            }
        }
    }
    return;
}

// Plugindata
$plugindata = LBSystem::plugindata();
debug(__line__,"Loglevel: ".$plugindata['PLUGINDB_LOGLEVEL'],6);

// Plugin version
debug(__line__,"Version: ".LBSystem::pluginversion(),6);

// Read language info
debug(__line__,count($L)." ".$L["MINISERVERBACKUP.INF_0001_NB_LANGUAGE_STRINGS_READ"],6);

// Logfile-Check
$logfiles = glob($logfileprefix."*".$logfilesuffix, GLOB_NOSORT);
if ( count($logfiles) > $logfiles_to_keep )
{
    usort($logfiles,"sort_by_mtime");
    $log_keeps = $logfiles;
    $log_keeps = array_slice($log_keeps, 0 - $logfiles_to_keep, $logfiles_to_keep);
    debug(__line__,str_ireplace("<number>",$logfiles_to_keep,$L["MINISERVERBACKUP.INF_0145_LOGFILE_CHECK"]),6);

    foreach($log_keeps as $log_keep)
    {
        debug(__line__," -> ".$L["MINISERVERBACKUP.INF_0146_LOGFILE_KEEP"]." ".$log_keep,7);
    }
    unset($log_keeps);

    if ( count($logfiles) > $logfiles_to_keep )
    {
        $log_deletions = array_slice($logfiles, 0, count($logfiles) - $logfiles_to_keep);

        foreach($log_deletions as $log_to_delete)
        {
            debug(__line__," -> ".$L["MINISERVERBACKUP.INF_0147_LOGFILE_DELETE"]." ".$log_to_delete,6);
            unlink($log_to_delete);
        }
        unset($log_deletions);
    }
}

if ( is_file($backupstate_file) )
{
    if ( file_get_contents($backupstate_file) != "-" && file_get_contents($backupstate_file) != "" )
    {
        debug(__line__,$L["ERRORS.ERR_0042_ERR_BACKUP_RUNNING"]." ".$backupstate_file,6);
        sleep(3);
        $log->LOGTITLE($L["MINISERVERBACKUP.INF_0139_BACKUP_ALREADY_RUNNING"]);
        LOGINF ($L["ERRORS.ERR_0042_ERR_BACKUP_RUNNING"]);
        LOGEND ("");
        exit(1);
    }
}

// Read Miniservers
debug(__line__,$L["MINISERVERBACKUP.INF_0002_READ_MINISERVERS"]);
$cfg = parse_ini_file(LBHOMEDIR . "/config/system/general.cfg", True, INI_SCANNER_RAW) or error_log("LoxBerry System ERROR: Could not read general.cfg in " . LBHOMEDIR . "/config/system/");
$clouddnsaddress = $cfg['BASE']['CLOUDDNS'] or LOGERR ($L["ERRORS.ERR_0068_PROBLEM_READING_CLOUD_DNS_ADDR"]);

// If no miniservers are defined, return NULL
$miniservercount = $cfg['BASE']['MINISERVERS'];
if (!$miniservercount || $miniservercount < 1)
{
    debug(__line__,$L["ERRORS.ERR_0001_NO_MINISERVERS_CONFIGURED"],3);
    $runtime = microtime(true) - $start;
    sleep(3); // To prevent misdetection in createmsbackup.pl
    file_put_contents($backupstate_file, "-");
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
    LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
    LOGEND ("");
    exit(1);
}

for ($msnr = 1; $msnr <= $miniservercount; $msnr++)
{
    @$miniservers[$msnr]['Name'] = $cfg["MINISERVER$msnr"]['NAME'];
    @$miniservers[$msnr]['IPAddress'] = $cfg["MINISERVER$msnr"]['IPADDRESS'];
    @$miniservers[$msnr]['Admin'] = $cfg["MINISERVER$msnr"]['ADMIN'];
    @$miniservers[$msnr]['Pass'] = $cfg["MINISERVER$msnr"]['PASS'];
    @$miniservers[$msnr]['Credentials'] = $miniservers[$msnr]['Admin'] . ':' . $miniservers[$msnr]['Pass'];
    @$miniservers[$msnr]['Note'] = $cfg["MINISERVER$msnr"]['NOTE'];
    @$miniservers[$msnr]['Port'] = $cfg["MINISERVER$msnr"]['PORT'];
    @$miniservers[$msnr]['PortHttps'] = $cfg["MINISERVER$msnr"]['PORTHTTPS'];
    @$miniservers[$msnr]['PreferHttps'] = $cfg["MINISERVER$msnr"]['PREFERHTTPS'];
    @$miniservers[$msnr]['UseCloudDNS'] = $cfg["MINISERVER$msnr"]['USECLOUDDNS'];
    @$miniservers[$msnr]['CloudURLFTPPort'] = $cfg["MINISERVER$msnr"]['CLOUDURLFTPPORT'];
    @$miniservers[$msnr]['CloudURL'] = $cfg["MINISERVER$msnr"]['CLOUDURL'];
    @$miniservers[$msnr]['Admin_RAW'] = urldecode($miniservers[$msnr]['Admin']);
    @$miniservers[$msnr]['Pass_RAW'] = urldecode($miniservers[$msnr]['Pass']);
    @$miniservers[$msnr]['Credentials_RAW'] = $miniservers[$msnr]['Admin_RAW'] . ':' . $miniservers[$msnr]['Pass_RAW'];
    @$miniservers[$msnr]['SecureGateway'] = isset($cfg["MINISERVER$msnr"]['SECUREGATEWAY']) && is_enabled($cfg["MINISERVER$msnr"]['SECUREGATEWAY']) ? 1 : 0;
    @$miniservers[$msnr]['EncryptResponse'] = isset ($cfg["MINISERVER$msnr"]['ENCRYPTRESPONSE']) && is_enabled($cfg["MINISERVER$msnr"]['ENCRYPTRESPONSE']) ? 1 : 0;
}

$ms = $miniservers;
if (!is_array($ms))
{
    debug(__line__,$L["ERRORS.ERR_0001_NO_MINISERVERS_CONFIGURED"],3);
    $runtime = microtime(true) - $start;
    sleep(3); // To prevent misdetection in createmsbackup.pl
    file_put_contents($backupstate_file, "-");
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
    LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
    LOGEND ("");
    exit(1);
}
else
{
    debug(__line__,count($ms)." ".$L["MINISERVERBACKUP.INF_0003_MINISERVERS_FOUND"],5);
}

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
                debug(__line__,$L["MINISERVERBACKUP.INF_0064_CONFIG_PARAM"]." ".$config_line[0]."=".$plugin_cfg[$config_line[0]]);
            }
        }
        }
    }
    fclose($plugin_cfg_handle);
}
else
{
    debug(__line__,$L["ERRORS.ERR_0028_ERROR_READING_CFG"],4);
    touch($plugin_config_file);
}

// Check if Plugin is disabled
if ( $plugin_cfg["MSBACKUP_USE"] == "on" || $plugin_cfg["MSBACKUP_USE"] == "1" )
{
    // Warning if Loglevel > 5 (OK)
    if ($plugindata['PLUGINDB_LOGLEVEL'] > 5 && $plugindata['PLUGINDB_LOGLEVEL'] <= 7) debug(__line__,$L["MINISERVERBACKUP.INF_0026_LOGLEVEL_WARNING"]." ".$L["LOGGING.LOGLEVEL".$plugindata['PLUGINDB_LOGLEVEL']]." (".$plugindata['PLUGINDB_LOGLEVEL'].")",4);
    debug(__line__,$L["MINISERVERBACKUP.INF_0112_PLUGIN_ENABLED"],5);
}
else
{
    $runtime = microtime(true) - $start;
    sleep(3); // To prevent misdetection in createmsbackup.pl
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0113_PLUGIN_DISABLED"]);
    LOGINF ($L["MINISERVERBACKUP.INF_0113_PLUGIN_DISABLED"]);
    LOGEND ("");
    exit(1);
}

// Prevent blocking / Recreate state file if missing or older than 60 min
if ( is_file($backupstate_tmp) )
{
    if ( ( time() - filemtime( $backupstate_tmp ) ) > (60 * 60) )
    {
        @file_put_contents($backupstate_tmp, "-");
    }
}
else
{
    @file_put_contents($backupstate_tmp, "-");
}
if ( ! is_link($backupstate_file) )
{
    if ( is_file($backupstate_file) )
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$backupstate_file);
        @unlink($backupstate_file);
    }
    @symlink($backupstate_tmp, $backupstate_file);
}

if ( ! is_link($backupstate_file) || ! is_file($backupstate_tmp) ) debug(__line__,$L["ERRORS.ERR_0029_PROBLEM_WITH_STATE_FILE"],3);

// Init Array for files to save
$curl = curl_init() or debug(__line__,$L["ERRORS.ERR_0002_ERROR_INIT_CURL"],3);
curl_setopt($curl, CURLOPT_RETURNTRANSFER    , true);
curl_setopt($curl, CURLOPT_HTTPAUTH            , constant("CURLAUTH_ANY"));
curl_setopt($curl, CURLOPT_CUSTOMREQUEST    , "GET");
curl_setopt($curl, CURLOPT_TIMEOUT            , 600);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER    , 0);
curl_setopt($curl, CURLOPT_SSL_VERIFYSTATUS    , 0);
curl_setopt($curl, CURLOPT_SSL_VERIFYHOST   , 0);

// Process all miniservers
set_time_limit(0);

$at_least_one_save = 0;
$saved_ms=array();
$problematic_ms=array();
array_push($summary,"<hr> ");
ksort($ms);
$connection_data_returncode0 = 0;
$randomsleep = 1;
$known_for_today = 0;
$all_cloudrequests = 0;
for ( $msno = 1; $msno <= count($ms); $msno++ )
{
    $miniserver = $ms[$msno];
    $prefix = ($miniserver['PreferHttps'] == 1) ? "https://":"http://";
    $port   = ($miniserver['PreferHttps'] == 1) ? $miniserver['PortHttps']:$miniserver['Port'];
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0135_BACKUP_STARTED_MS"]." #".$msno." (".$miniserver['Name'].")");
    if (isset($argv[2]))
    {
        if ( intval($argv[2]) == $msno )
        {
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0124_MANUAL_SAVE_SINGLE_MS"]." ".$msno."/".count($ms)." => ".$miniserver['Name'],5);
        }
        else if ( intval($argv[2]) == 0)
        {
            // No single manual save
        }
        else
        {
            // Single manual save but not the MS we want
            continue;
        }
    }

    file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]));
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0004_PROCESSING_MINISERVER"]." ".$msno."/".count($ms)." => ".$miniserver['Name'],5);
    $filetree["name"]         = array();
    $filetree["size"]         = array();
    $filetree["time"]         = array();
    $save_ok_list["name"]     = array();
    $save_ok_list["size"]     = array();
    $save_ok_list["time"]     = array();
    $percent_done             = "100";
    $percent_displ            = "";
    $finalstorage             = $default_finalstorage;
    $finalstorage             = $plugin_cfg["FINALSTORAGE".$msno];
    $backupinterval           = 0;
    $backupinterval           = $plugin_cfg["BACKUP_INTERVAL".$msno];
    $backup_schedule_time     = -1;
    $backup_schedule_time     = $plugin_cfg["BACKUP_SCHEDULE_TIME".$msno];
    $backups_to_keep          = 7;
    $backups_to_keep          = $plugin_cfg["BACKUPS_TO_KEEP".$msno];
    $ms_subdir                = "";
    if ( isset($plugin_cfg["MS_SUBDIR".$msno]) ) $ms_subdir    = "/".$plugin_cfg["MS_SUBDIR".$msno];
    $bkpfolder                = str_pad($msno,3,0,STR_PAD_LEFT)."_".$miniserver['Name'];

    $last_save                = "";
    if ( $backupinterval != "-1" )
    {
        if ( isset($plugin_cfg["LAST_SAVE".$msno]) ) $last_save = $plugin_cfg["LAST_SAVE".$msno];
        if ( $last_save > $last_save_stamp || $last_save == "" )
        {
            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0043_ERR_LAST_SAVE_INVALID"],6);
            $last_save = $last_save_stamp - (intval($backupinterval)*60) - 1;
        }
    }
    else
    {
        $last_save = $last_save_stamp;
    }
    // Manual Backup Button on Admin page
    $manual_backup = 0;
    if (isset($argv[1]))
    {
        if ( $argv[1] == "manual" )
        {
            $manual_backup = 1;
        }
        if ( $argv[1] == "symlink" && $backupinterval != "-1" )
        {
            // If it's a file, delete it
            if ( is_file($bkp_dest_dir."/".$bkpfolder) )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$bkp_dest_dir."/".$bkpfolder);
                @unlink($bkp_dest_dir."/".$bkpfolder);
            }
            // If it's no link, delete it
            if ( !is_link($bkp_dest_dir."/".$bkpfolder) )
            {
                // If it's a local dir, delete it
                if (is_dir($bkp_dest_dir."/".$bkpfolder))
                {
                    rrmdir($bkp_dest_dir."/".$bkpfolder);
                }
            }
            else
            {
                // If it's link, delete it
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$bkp_dest_dir."/".$bkpfolder);
                unlink($bkp_dest_dir."/".$bkpfolder);
            }

            if (substr($finalstorage, -1) == "+")
            {
                $finalstorage = substr($finalstorage,0, -1)."/".$bkpfolder;
            }
            if (substr($finalstorage, -1) == "~")
            {
                $finalstorage = substr($finalstorage,0, -1).$ms_subdir."/".$bkpfolder;
            }

            // Create a fresh local link from html file browser to final storage location
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0123_SYMLINKS_AFTER_UPGRADE"]." -> ".$bkp_dest_dir."/".$bkpfolder." => ".$finalstorage,5);
            symlink($finalstorage,$bkp_dest_dir."/".$bkpfolder);
            continue;
        }
    }
    // Check if we have to skip this MS
    // |- Backup was not triggered manually
    // |- Backup interval set to manual backups only
    if (
        $manual_backup != "1"
        && $backupinterval == "0"
    )
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0172_INTERVAL_IS_MANUAL"],5);
        continue;
    }
    // |- Backup was not triggered manually
    // |- Interval not elapsed
    // |- Backup time not specified or backup time specified but interval is less than 1440 minutes (1 day)
    else if (
        $manual_backup != "1"
        && $backupinterval >= ( ( ( time() - intval($last_save - 60)) / 60 ) )
        && (
            $backup_schedule_time == -1
            || (
                $backup_schedule_time != -1
                && $backupinterval < 1440
            )
        )
    )
    {
        debug(__line__,"MS#".$msno." ".str_ireplace("<interval>",$backupinterval,str_ireplace("<age>",round((time() - intval($last_save))/60,1),str_ireplace("<datetime>",date ($date_time_format, $last_save),$L["MINISERVERBACKUP.INF_0087_LAST_MODIFICATION_WAS"]))),5);
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0089_INTERVAL_NOT_ELAPSED"],5);
        continue;
    }
    // |- Backup was not triggered manually
    // |- Interval not elapsed
    // |- Backup time specified
    // |- Backup interval at is greater or equal than 1440 minutes (1 day)
    // |- Backup time is greater than current time
    else if (
        $manual_backup != "1"
        && (
            $backupinterval >= ( ( ( time() - intval($last_save - 60)) / 60 ) )
            || (
                $backup_schedule_time != -1
                && $backupinterval >= 1440
                && $backup_schedule_time > date("H:i")
            )
        )
    )
    {
        debug(__line__,"MS#".$msno." ".str_ireplace("<time>",$backup_schedule_time,str_ireplace("<interval>",$backupinterval,str_ireplace("<age>",round((time() - intval($last_save))/60,1),str_ireplace("<datetime>",date ($date_time_format, $last_save),$L["MINISERVERBACKUP.INF_0173_LAST_MODIFICATION_WAS"])))),5);
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0174_INTERVAL_NOT_ELAPSED"],5);
        continue;

    }
    else
    {
        if ( $backupinterval == "-1" )
        {
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0090_BACKUPS_DISABLED"],5);
            continue;
        }
        else
        {
            if ( $manual_backup == "1" )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0086_INFO_MANUAL_BACKUP_REQUEST"],5);
            }
            else
            {
                debug(__line__,"MS#".$msno." ".str_ireplace("<interval>",$backupinterval,str_ireplace("<age>",round((time() - intval($last_save))/60,1),str_ireplace("<datetime>",date ($date_time_format, $last_save),$L["MINISERVERBACKUP.INF_0087_LAST_MODIFICATION_WAS"]))),5);
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0088_INTERVAL_ELAPSED"],5);
                $last_error = 0;
                if ( isset($plugin_cfg["LAST_ERROR".$msno]) ) $last_error = $plugin_cfg["LAST_ERROR".$msno];
                if ( intval($last_error) + 86400 >= intval(time()) )
                {
                    debug(__line__,"MS#".$msno." ".str_ireplace("<until>",date($date_time_format, $last_error + 86400),$L["MINISERVERBACKUP.INF_0154_SKIP_ON_PREVIOUS_ERROR"]),5);
                    continue;
                }
                else
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0155_NO_PREVIOUS_ERROR"],5);
                }
            }
        }
    }

    $workdir_tmp = $plugin_cfg["WORKDIR_PATH"];
    if ( $plugin_cfg["WORKDIR_PATH_SUBDIR"] != "" )
    {
        if (strpbrk($plugin_cfg["WORKDIR_PATH_SUBDIR"], "\\/?%*:|\"<>") !== FALSE)
        {
            debug(__line__,$L["ERRORS.ERR_0047_ERR_WORK_SUBDIR_INVALID"]." ".$plugin_cfg["WORKDIR_PATH_SUBDIR"],3);
            $runtime = microtime(true) - $start;
            sleep(3); // To prevent misdetection in createmsbackup.pl
            file_put_contents($backupstate_file, "-");
            $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
            LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
            LOGEND ("");
            exit(1);
        }
        else
        {
            $workdir_tmp .= "/".$plugin_cfg["WORKDIR_PATH_SUBDIR"];
        }
    }

    debug(__line__,$L["MINISERVERBACKUP.INF_0032_CLEAN_WORKDIR_TMP"]." ".$workdir_tmp);
    create_clean_workdir_tmp($workdir_tmp);
    file_put_contents("/tmp/msb_free_space", $workdir_tmp, LOCK_EX);
    if (!realpath($workdir_tmp))
    {
        debug(__line__,$L["ERRORS.ERR_0022_PROBLEM_WITH_WORKDIR"],3);
        $runtime = microtime(true) - $start;
        sleep(3); // To prevent misdetection in createmsbackup.pl
        file_put_contents($backupstate_file, "-");
        $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
        LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
        LOGEND ("");
        exit(1);
    }

    debug(__line__,$L["MINISERVERBACKUP.INF_0038_DEBUG_DIR_FILE_LINK_EXISTS"]." -> ".$workdir_data);
    if ( is_file($workdir_data) || is_dir($workdir_data) || is_link( $workdir_data ) )
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"]." -> ".$L["MINISERVERBACKUP.INF_0039_DEBUG_IS_LINK"]." -> ".$workdir_data);
        if ( is_link( $workdir_data ) )
        {
            debug(__line__,$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"]." -> ".$L["MINISERVERBACKUP.INF_0042_DEBUG_CORRECT_TARGET"]." -> ".$workdir_data." => ".$workdir_tmp);
            if ( readlink($workdir_data) == $workdir_tmp )
            {
                debug(__line__,$L["MINISERVERBACKUP.INF_0030_WORKDIR_IS_SYMLINK"]);
                // Everything in place => ok!
            }
            else
            {
                debug(__line__,$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0043_DEBUG_DELETE_SYMLINK"]." -> ".$workdir_data);
                unlink($workdir_data);
                debug(__line__,$L["MINISERVERBACKUP.INF_0044_DEBUG_CREATE_SYMLINK"]." -> ".$workdir_data ."=>".$workdir_tmp);
                symlink ($workdir_tmp, $workdir_data);
            }
        }
        else
        {
            debug(__line__,$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0041_DEBUG_IS_DIR"]." -> ".$workdir_data);
            if (is_dir($workdir_data))
            {
                debug(__line__,$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"]." -> ".$L["MINISERVERBACKUP.INF_0034_DEBUG_DIRECTORY_DELETE"]." -> ".$workdir_data);
                rrmdir($workdir_data);
            }
            else
            {
                debug(__line__,$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0040_DEBUG_IS_FILE"]." -> ".$workdir_data);
                if (is_file($workdir_data))
                {
                    debug(__line__,$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"]." -> ".$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$workdir_data);
                    unlink($workdir_data);
                }
                else
                {
                    debug(__line__,"Oh no! You should never read this",2);
                }
            }
            debug(__line__,$L["MINISERVERBACKUP.INF_0044_DEBUG_CREATE_SYMLINK"]." -> ".$workdir_data ."=>".$workdir_tmp);
            symlink($workdir_tmp, $workdir_data);
        }
    }
    else
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0044_DEBUG_CREATE_SYMLINK"]." -> ".$workdir_data ."=>".$workdir_tmp);
        symlink($workdir_tmp, $workdir_data);
    }
    if (readlink($workdir_data) == $workdir_tmp)
    {
        chmod($workdir_tmp    , 0777);
        chmod($workdir_data    , 0777);
        debug(__line__,$L["MINISERVERBACKUP.INF_0031_SET_WORKDIR_AS_SYMLINK"]." (".$workdir_data.")",6);
    }
    else
    {
        debug(__line__,$L["ERRORS.ERR_0021_CANNOT_SET_WORKDIR_AS_SYMLINK"],3);
        $runtime = microtime(true) - $start;
        sleep(3); // To prevent misdetection in createmsbackup.pl
        file_put_contents($backupstate_file, "-");
        $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
        LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
        LOGEND ("");
        exit(1);
    }

    // Define and create save directories base folder
    if (is_file($savedir_path))
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0040_DEBUG_IS_FILE"]." -> ".$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"]." -> ".$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$savedir_path);
        unlink($savedir_path);
    }
    if (!is_dir($savedir_path))
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0041_DEBUG_IS_DIR"]." -> ".$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0035_DEBUG_DIRECTORY_CREATE"]." -> ".$savedir_path);
        $resultarray = array();
        @exec("mkdir -v -p ".escapeshellarg($savedir_path)." 2>&1",$resultarray,$retval);
    }
    if (!is_dir($savedir_path))
    {
        debug(__line__,$L["ERRORS.ERR_0020_CREATE_BACKUP_BASE_FOLDER"]." ".$savedir_path." (".join(" ",$resultarray).")",3);
        $runtime = microtime(true) - $start;
        sleep(3); // To prevent misdetection in createmsbackup.pl
        file_put_contents($backupstate_file, "-");
        $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
        LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
        LOGEND ("");
        exit(1);
    }
    debug(__line__,$L["MINISERVERBACKUP.INF_0046_BACKUP_BASE_FOLDER_OK"]." (".$savedir_path.")",6);

    // Check Connection in case of Cloud DNS
    if ( $miniserver['UseCloudDNS'] == "on" || $miniserver['UseCloudDNS'] == "1" )
    {
        // Check for earlier Cloud DNS requests on RAM Disk
        touch($cloud_requests_file); // Touch file to prevent errors if inexistent
        $checkurl = "http://".$cfg['BASE']['CLOUDDNS']."/?getip&snr=".$miniserver['CloudURL']."&json=true";
        $max_accepted_dns_errors     = 10;
        $dns_errors                 = 0;
        do
        {
            sleep(1);
            debug(__line__,"MS#".$msno." Call function get_connection_data => ".$miniserver['Name']);
            $connection_data_returncode = get_connection_data($checkurl);
            /*
                Possible connection_data_error codes:
                0 = ok
                1 = Other error, retry
                2 = Too many errors, stop retrying
                3 = Too much errors for today
                4 = Port not open / Remote connect disabled
                5 = Error init cURL, stop retrying
                6 = Error 403/405, stop retrying
            */
            if ( $connection_data_returncode == 1)
            {
                // Code 1 will repeat the loop until $max_accepted_dns_errors is reached
                // 1 = Other error, retry
                $dns_errors++;
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0159_CLOUD_DNS_FAIL"]." (#$dns_errors/$max_accepted_dns_errors)",6);
            }
            if ( $dns_errors > $max_accepted_dns_errors )
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0071_TOO_MANY_CLOUD_DNS_FAILS"]." (#$dns_errors/$max_accepted_dns_errors) ".$miniserver['Name']." ".curl_error($curl),3);
                $connection_data_returncode = 2;
            }
        } while ($connection_data_returncode == 1);

        if ( $connection_data_returncode >= 2 )
        {
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file,"-");
            array_push($summary,"<hr> ");
            if ( $connection_data_returncode != 3 ) array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
            continue;
        }
    }
    else
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0110_CLOUD_DNS_NOT_USED"]." => ".$miniserver['Name']." @ ".$miniserver['IPAddress'],5);
    }

    if ( $miniserver['IPAddress'] == "" )
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0003_MS_CONFIG_NO_IP"],3);
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        curl_close($curl_dns);
        $connection_data_returncode = 1;
        return $connection_data_returncode;
    }
    else
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0005_MS_IP_HOST_PORT"]."=".$miniserver['IPAddress'].":".$port,6);
    }

    curl_setopt($curl, CURLOPT_USERPWD, $miniserver['Credentials_RAW']);
    $url = $prefix.$miniserver['IPAddress'].":".$port."/dev/cfg/ip";
    curl_setopt($curl, CURLOPT_URL, $url);
    sleep(5);
    if(curl_exec($curl) === false)
    {
        debug(__line__,"MS#".$msno." ".$url);
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0018_ERROR_READ_LOCAL_MS_IP"]." ".$miniserver['Name']." ".curl_error($curl),3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;
    }
    else
    {   $local_ip = [];
        $read_line= curl_multi_getcontent($curl) or $read_line = "";
        if(preg_match("/.*dev\/cfg\/ip.*value.*\"(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\".*$/i", $read_line, $local_ip))
        {
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0028_LOCAL_MS_IP"]." ".$local_ip[1],6);
        }
        else
        {
            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0018_ERROR_READ_LOCAL_MS_IP"]." ".$url." => ".nl2br(htmlentities($read_line)),3);
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file,"-");
            array_push($summary,"<hr> ");
            array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
            continue;
        }
    }

    $url = $prefix.$miniserver['IPAddress'].":".$port."/dev/cfg/version";
    curl_setopt($curl, CURLOPT_URL, $url);
    if(curl_exec($curl) === false)
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0019_ERROR_READ_LOCAL_MS_VERSION"]." ".curl_error($curl),3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;
    }
    else
    {
        $read_line= curl_multi_getcontent($curl) or $read_line = "";
        if(preg_match("/.*dev\/cfg\/version.*value.*\"(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\".*$/i", $read_line, $ms_version))
        {
            $ms_version_dir = str_pad($ms_version[1],2,0,STR_PAD_LEFT).str_pad($ms_version[2],2,0,STR_PAD_LEFT).str_pad($ms_version[3],2,0,STR_PAD_LEFT).str_pad($ms_version[4],2,0,STR_PAD_LEFT);
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0029_LOCAL_MS_VERSION"]." ".$ms_version[1].".".$ms_version[2].".".$ms_version[3].".".$ms_version[4]." => ".$ms_version_dir,6);
        }
        else
        {
            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0019_ERROR_READ_LOCAL_MS_VERSION"]." ".curl_error($curl),3);
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file,"-");
            array_push($summary,"<hr> ");
            array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
            continue;
        }
    }

    $bkpdir     = $backup_file_prefix.trim($local_ip[1])."_".date("YmdHis",time())."_".$ms_version_dir;
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0027_CREATE_BACKUPFOLDER"]." ".$bkpdir." + ".$bkpfolder,6);

    $temp_finalstorage = $finalstorage;
    // Check if final target is on an external storage like SMB or USB
    if (strpos($finalstorage, '/system/storage/') !== false)
    {
        // Yes, is on an external storage
        // Check if subdir must be appended
        if (substr($finalstorage, -1) == "+")
        {
            $temp_finalstorage = substr($finalstorage,0, -1);
            exec("mountpoint '".$temp_finalstorage."' ", $retArr, $retVal);
            if ( $retVal == 0 )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0102_VALID_MOUNTPOINT"]." (".$temp_finalstorage.")",6);

            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0049_ERR_INVALID_MOUNTPOINT"]." ".$temp_finalstorage,3);
                create_clean_workdir_tmp($workdir_tmp);
                file_put_contents($backupstate_file,"-");
                array_push($summary,"<hr> ");
                array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
                continue;
            }
        }
        else if (substr($finalstorage, -1) == "~")
        {
            $temp_finalstorage = substr($finalstorage,0, -1);
            exec("mountpoint '".$temp_finalstorage."' ", $retArr, $retVal);
            if ( $retVal == 0 )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0102_VALID_MOUNTPOINT"]." (".$temp_finalstorage.")",6);
                $temp_finalstorage = $finalstorage.$ms_subdir;
            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0049_ERR_INVALID_MOUNTPOINT"]." ".$temp_finalstorage,3);
                create_clean_workdir_tmp($workdir_tmp);
                file_put_contents($backupstate_file,"-");
                array_push($summary,"<hr> ");
                array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
                continue;
            }
        }
        else
        {
            exec("mountpoint '".$finalstorage."' ", $retArr, $retVal);
            if ( $retVal == 0 )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0102_VALID_MOUNTPOINT"]." (".$finalstorage.")",6);
                $temp_finalstorage = $finalstorage;
            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0049_ERR_INVALID_MOUNTPOINT"]." ".$finalstorage,3);
                create_clean_workdir_tmp($workdir_tmp);
                file_put_contents($backupstate_file,"-");
                array_push($summary,"<hr> ");
                array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
                continue;
            }
        }
    }



    if (!is_dir($temp_finalstorage))
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0091_BACKUP_DIR_NOT_FOUND_TRY_BACKUP"],5);
    }
    // Set root dir to / and read it
    $folder = "/";
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0006_READ_DIRECTORIES_AND_FILES"]." ".$folder,6);
    // A partial listing must never be interpreted as remote file deletion.
    if (!read_ms_tree($folder) || count($filetree["name"]) == 0)
    {
        $at_least_one_error = 1;
        debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0074_INCOMPLETE_BACKUP"], 3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file, "-");
        array_push($problematic_ms, " #".$msno." (".$miniserver['Name'].")");
        continue;
    }
    $full_backup_size = array_sum($filetree["size"]);
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0015_BUILDING_FILELIST_COMPLETED"]." ".count($filetree["name"]),6);
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0048_REMOVING_ALREADY_SAVED_IDENTICAL_FILES_FROM_LIST"],6);
    if (!is_dir($savedir_path."/".$bkpfolder))
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0041_DEBUG_IS_DIR"]." -> ".$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0035_DEBUG_DIRECTORY_CREATE"]." -> ".$savedir_path."/".$bkpfolder);
        $resultarray = array();
        @exec("mkdir -v -p ".escapeshellarg($savedir_path."/".$bkpfolder)." 2>&1",$resultarray,$retval);
    }
    if (!is_dir($savedir_path."/".$bkpfolder))
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0024_CREATE_BACKUP_SUB_FOLDER"]." ".$savedir_path."/".$bkpfolder." (".join(" ",$resultarray).")",3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;
    }
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0047_BACKUP_SUB_FOLDER_OK"]." (".$savedir_path."/".$bkpfolder.")",6);
    $filestosave = 0;
    $cache_deletions = array();
    foreach (getDirContents($savedir_path."/".$bkpfolder) as &$file_on_disk)
    {
        $short_name = str_replace($savedir_path."/".$bkpfolder, '', $file_on_disk);

        if ($short_name != "" )
        {
            $key_in_filetree = array_search($short_name,$filetree["name"],true);
        }
        else
        {
            $key_in_filetree = false;
        }
        if ( !($key_in_filetree === false) )
        {
            if ( $filetree["size"][$key_in_filetree] == filesize($file_on_disk) && $filetree["time"][$key_in_filetree] == filemtime($file_on_disk) )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0049_COMPARE_FOUND_REMOVE_FROM_LIST"]." (".$short_name.")");
                unset($filetree["name"][$key_in_filetree]);
                unset($filetree["size"][$key_in_filetree]);
                unset($filetree["time"][$key_in_filetree]);
                $filestosave++;
            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0050_COMPARE_FOUND_DIFFER_KEEP_LIST"]." (".$short_name.")\nMS <=> LB ".$filetree["name"][$key_in_filetree]." <=> ".$short_name."\nMS <=> LB ".$filetree["size"][$key_in_filetree]." <=> ".filesize($file_on_disk)." Bytes \nMS <=> LB ".date("M d H:i",$filetree["time"][$key_in_filetree])." <=> ".date("M d H:i",filemtime($file_on_disk)),6);
                // Keep the previous file until all replacement downloads passed.
                $filestosave++;
            }
        }
        else
        {
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0051_COMPARE_NOT_ON_MS_ANYMORE"]." (".$short_name.") ".filesize($file_on_disk)." Bytes [".filemtime($file_on_disk)."]",6);
            $cache_deletions[] = $file_on_disk;
        }
    }

    $estimated_size = array_sum($filetree["size"])/1024;
    if (is_link($workdir_tmp) )
    {
        $workdir_space   = disk_free_space(readlink($workdir_tmp))/1024;
    }
    else
    {
        $workdir_space   = disk_free_space($workdir_tmp)/1024;
    }
    $free_space        = ($workdir_space - $estimated_size);
    debug(__line__,"MS#".$msno." ".str_ireplace("<free_space>",round($free_space,1),str_ireplace("<workdirbytes>",round($workdir_space,1),str_ireplace("<backupsize>",round($estimated_size,1),$L["MINISERVERBACKUP.INF_0095_CHECK_FREE_SPACE_IN_WORKDIR"]))),5);
    if ( $free_space < $minimum_free_workdir/1024 )
    {
        debug(__line__,"MS#".$msno." ".str_ireplace("<free_space>",round($free_space,1),str_ireplace("<workdirbytes>",round($workdir_space,1),str_ireplace("<backupsize>",round($estimated_size,1),$L["ERRORS.ERR_0045_NOT_ENOUGH_FREE_SPACE_IN_WORKDIR"]))),2);
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0032_CLEAN_WORKDIR_TMP"]." ".$workdir_tmp);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;
    }
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0015_BUILDING_FILELIST_COMPLETED"]." ".count($filetree["name"]),6);

    $crit_issue=0;
    if ( count($filetree["name"]) > 0 )
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0021_START_DOWNLOAD"],5);
        // Calculate download time
        $start_dwl =  microtime(true);
        foreach( $filetree["name"] as $k=>$file_to_save)
        {
            if (!download_ms_file($file_to_save, $k))
            {
                $crit_issue = 1;
                $at_least_one_error = 1;
                break;
            }
            $download_path = $workdir_tmp."/".$bkpfolder.$file_to_save;
            array_push($save_ok_list["name"], $file_to_save);
            array_push($save_ok_list["size"], filesize($download_path));
            array_push($save_ok_list["time"], filemtime($download_path));
            $percent_done = round((count($save_ok_list["name"]) *100 ) / count($filetree["name"]),0);
            file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0066_STATE_DOWNLOAD"]." ".$percent_done."%)");
            if ( ! ($percent_done % 5) )
            {
                if ( $percent_displ != $percent_done )
                {
                    if ($percent_done <= 95)
                    {
                        debug(__line__,"MS#".$msno." ".str_pad($percent_done,3," ",STR_PAD_LEFT).$L["MINISERVERBACKUP.INF_0022_PERCENT_DONE"]." (".str_pad(round(array_sum($save_ok_list["size"]),0),strlen(round(array_sum($filetree["size"]),0))," ", STR_PAD_LEFT)."/".str_pad(round(array_sum($filetree["size"]),0),strlen(round(array_sum($filetree["size"]),0))," ", STR_PAD_LEFT)." Bytes) [".str_pad(count($save_ok_list["name"]),strlen(count($filetree["name"]))," ", STR_PAD_LEFT)."/".str_pad(count($filetree["name"]),strlen(count($filetree["name"]))," ", STR_PAD_LEFT)."]",5);
                        $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0066_STATE_DOWNLOAD"]." ".$percent_done."%)");
                    }
                }
                $percent_displ = $percent_done;
            }
        }
        if ( $crit_issue == 1 )
        {
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file,"-");
            array_push($summary,"<hr> ");
            array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
            continue;
        }


        debug(__line__,"MS#".$msno." ".$percent_done.$L["MINISERVERBACKUP.INF_0022_PERCENT_DONE"]." (".round(array_sum($save_ok_list["size"]),0)."/".round(array_sum($filetree["size"]),0)." Bytes) [".count($save_ok_list["name"])."/".count($filetree["name"])."]",5);
        file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]));
        $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]));
        debug(__line__,"MS#".$msno." ".count($save_ok_list["name"])." ".$L["MINISERVERBACKUP.INF_0018_BACKUP_COMPLETE"]." (".array_sum($save_ok_list["size"])." Bytes)",5);
        if ( (count($filetree["name"]) - count($save_ok_list["name"])) > 0 )
        {
            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0010_SOME_FILES_NOT_SAVED"]." ".(count($filetree["name"]) - count($save_ok_list["name"])),4);
            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0011_SOME_FILES_NOT_SAVED_INFO"]."\n".implode("\n",array_diff($filetree["name"], $save_ok_list["name"])),6);
            $at_least_one_error = 1;
            debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0074_INCOMPLETE_BACKUP"], 3);
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file, "-");
            array_push($problematic_ms, " #".$msno." (".$miniserver['Name'].")");
            continue;
        }
        $runtime_dwl = (microtime(true) - $start_dwl);
        debug(__line__,"MS#".$msno." "."Runtime: ".$runtime_dwl." s");
        if ( round($runtime_dwl,1,PHP_ROUND_HALF_UP) < 0.5 ) $runtime_dwl = 0.5;
        $size_dwl = array_sum($save_ok_list["size"]);
        $size_dwl_kBs = round(  ($size_dwl / 1024) / $runtime_dwl ,2);
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0053_DOWNLOAD_TIME"]." ".secondsToTime(round($runtime_dwl,0,PHP_ROUND_HALF_UP))." s ".$size_dwl." Bytes => ".$size_dwl_kBs." kB/s",5);
    }
    else
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0052_NOSTART_DOWNLOAD"],5);
    }
    // Move to target dir

     // Check if final target is on an external storage like SMB or USB
    if (strpos($finalstorage, '/system/storage/') !== false)
    {
        // Yes, is on an external storage
        // Check if subdir must be appended
        if (substr($finalstorage, -1) == "+")
        {
            $finalstorage = substr($finalstorage,0, -1);
            exec("mountpoint '".$finalstorage."' ", $retArr, $retVal);
            if ( $retVal == 0 )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0102_VALID_MOUNTPOINT"]." (".$finalstorage.")",6);

            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0049_ERR_INVALID_MOUNTPOINT"]." ".$finalstorage,3);
                create_clean_workdir_tmp($workdir_tmp);
                file_put_contents($backupstate_file,"-");
                array_push($summary,"<hr> ");
                array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
                continue;
            }
            $finalstorage .= "/".$bkpfolder;
            $resultarray = array();
            @exec('mkdir -v -p "'.$finalstorage.'" 2>&1',$resultarray,$retval);
        }
        else if (substr($finalstorage, -1) == "~")
        {
            $finalstorage = substr($finalstorage,0, -1);
            exec("mountpoint '".$finalstorage."' ", $retArr, $retVal);
            if ( $retVal == 0 )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0102_VALID_MOUNTPOINT"]." (".$finalstorage.")",6);
                $finalstorage = $finalstorage.$ms_subdir;

            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0049_ERR_INVALID_MOUNTPOINT"]." ".$finalstorage,3);
                create_clean_workdir_tmp($workdir_tmp);
                file_put_contents($backupstate_file,"-");
                array_push($summary,"<hr> ");
                array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
                continue;
            }
            $finalstorage .= "/".$bkpfolder;
            $resultarray = array();
            @exec('mkdir -v -p "'.$finalstorage.'" 2>&1',$resultarray,$retval);
        }
        else
        {
            exec("mountpoint '".$finalstorage."' ", $retArr, $retVal);
            if ( $retVal == 0 )
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0102_VALID_MOUNTPOINT"]." (".$finalstorage.")",6);
            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0049_ERR_INVALID_MOUNTPOINT"]." ".$finalstorage,3);
                create_clean_workdir_tmp($workdir_tmp);
                file_put_contents($backupstate_file,"-");
                array_push($summary,"<hr> ");
                array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
                continue;
            }
        }
    }
    else
    {
        // No, is on local storage
        $finalstorage = $finalstorage."/".$bkpfolder;
        if (!is_dir($finalstorage))
        {
            $resultarray = array();
            @exec('mkdir -v -p "'.$finalstorage.'" 2>&1',$resultarray,$retval);
        }
    }
    if (!is_dir($finalstorage))
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0012_PROBLEM_CREATING_SAVE_DIR"]." ".$finalstorage." (".join(" ",$resultarray).")",3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;
    }

    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0083_DEBUG_FINAL_TARGET"]." ".$finalstorage,5);
    @exec('ls "'.$finalstorage.'"'); // Trigger an access to the target, needed for Samba shares
    sleep(5); // To give the system time to spin up a HDD which is possibly in Standby...
    if ( !is_writeable($finalstorage) )
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0039_FINAL_STORAGE_NOT_WRITABLE"],3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;
    }

    // If it's a file, delete it
    if ( is_file($bkp_dest_dir."/".$bkpfolder) )
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$bkp_dest_dir."/".$bkpfolder);
        @unlink($bkp_dest_dir."/".$bkpfolder);
    }
    // If it's no link, delete it
    if ( !is_link($bkp_dest_dir."/".$bkpfolder) )
    {
        // If it's a local dir, delete it
        if (is_dir($bkp_dest_dir."/".$bkpfolder))
        {
            rrmdir($bkp_dest_dir."/".$bkpfolder);
        }
    }
    else
    {
        // If it's link, delete it
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$bkp_dest_dir."/".$bkpfolder);
        unlink($bkp_dest_dir."/".$bkpfolder);
    }
    // Create a fresh local link from html file browser to final storage location
    symlink($finalstorage,$bkp_dest_dir."/".$bkpfolder);
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0020_MOVING_TO_SAVE_DIR"]." ".$workdir_tmp."/".$bkpfolder." =>".$savedir_path."/".$bkpfolder);
    // Merge only fully validated downloads; do not build or rotate an archive
    // if a local move fails. A deletion-only run has no download directory.
    $cache_ok = true;
    foreach ($cache_deletions as $cache_file)
    {
        if (is_file($cache_file) && !unlink($cache_file)) $cache_ok = false;
    }
    // Remove empty old directories too, allowing file/directory type changes.
    if ($cache_ok) $cache_ok = prune_empty_cache_dirs($savedir_path."/".$bkpfolder);
    if ($cache_ok && is_dir($workdir_tmp."/".$bkpfolder))
        $cache_ok = rmove($workdir_tmp."/".$bkpfolder, $savedir_path."/".$bkpfolder);
    if (!$cache_ok)
    {
        $at_least_one_error = 1;
        debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0076_CACHE_UPDATE_FAILED"], 3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file, "-");
        array_push($problematic_ms, " #".$msno." (".$miniserver['Name'].")");
        continue;
    }
    rrmdir($workdir_tmp."/".$bkpfolder);
    if (is_writeable($finalstorage))
    {
        $freespace = get_free_space($finalstorage);
        if ( $freespace < $full_backup_size + 33554432 )
        {

            debug (__line__,"MS#".$msno." ".str_ireplace("<free>",formatBytes($freespace,0),str_ireplace("<need>",formatBytes($full_backup_size,0),$L["ERRORS.ERR_0054_NOT_ENOUGH_FREE_SPACE"])),2);
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file, "-");
            array_push($summary,"<hr> ");
            array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
            continue;
        }
        else
        {
            debug (__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0114_ENOUGH_FREE_SPACE"]." ".formatBytes($freespace),5);
        }

        switch (strtoupper($plugin_cfg["FILE_FORMAT".$msno]))
        {
            case "ZIP":
                $fileformat = "ZIP";
                $fileformat_extension = ".zip";
                break;
            case "UNCOMPRESSED":
                $fileformat = "UNCOMPRESSED";
                $fileformat_extension = "";
                break;
            case "7Z":
                $fileformat = "7Z";
                $fileformat_extension = ".7z";
                switch (strtoupper($plugin_cfg["COMPRESSION".$msno]))
                {
                    case "0":
                        $compression = "-m0=copy ";
                        break;
                    case "5":
                        $compression = "-mx5 ";
                        break;
                    case "9":
                        $compression = "-mx9 ";
                        break;
                    default:
                        $compression = "";
                        break;
                }
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0171_COMPRESSION"]." ".$L["MINISERVERBACKUP.COMPRESSION_".$plugin_cfg["COMPRESSION".$msno]],6);
                break;
            default:
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0036_UNKNOWN_FILE_FORMAT"]." ".strtoupper($plugin_cfg["FILE_FORMAT".$msno]),4);
                $fileformat = "7Z";
                $fileformat_extension = ".7z";
                break;
        }
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0075_FILE_FORMAT"]." ".$L["MINISERVERBACKUP.FILE_FORMAT_".$fileformat],6);

        switch ($fileformat)
        {
            case "ZIP":
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0058_CREATE_ZIP_ARCHIVE"]." <br>".$savedir_path."/".$bkpfolder." => ".$finalstorage."/".$bkpdir.$fileformat_extension,6);
                file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0067_STATE_ZIP"].")");
                $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0067_STATE_ZIP"].")");
                MSbackupZIP::zipDir($savedir_path."/".$bkpfolder, $finalstorage."/".$bkpdir.$fileformat_extension);
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0061_CREATE_ZIP_ARCHIVE_DONE"]." ".$finalstorage."/".$bkpdir.$fileformat_extension." (". round( intval( filesize($finalstorage."/".$bkpdir.$fileformat_extension) ) / 1024 / 1024 ,2 ) ." MB)",5);
                break;
            case "UNCOMPRESSED":
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0076_NO_COMPRESS_COPY_START"]." <br>".$savedir_path."/".$bkpfolder." => ".$finalstorage."/".$bkpdir,5);
                file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0076_NO_COMPRESS_COPY_START"].")");
                $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0076_NO_COMPRESS_COPY_START"].")");
                $copied_bytes = 0;
                $copyerror = 0;
                recurse_copy($savedir_path."/".$bkpfolder,$finalstorage."/".$bkpdir,$copied_bytes,$filestosave);
                if ( $copyerror == 0 )
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0077_NO_COMPRESS_COPY_END"]." ".$finalstorage."/".$bkpdir." (". round( $copied_bytes / 1024 / 1024 ,2 ) ." MB)",5);
                }
                else
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0152_NO_COMPRESS_COPY_END_FAIL"],6);
                    $crit_issue = 1;
                }
                $copyerror = 0;
                break;
            case "7Z":
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0058_CREATE_ZIP_ARCHIVE"]." <br>".$savedir_path."/".$bkpfolder." => ".$finalstorage."/".$bkpdir.$fileformat_extension,6);
                $seven_zip_output = array();
                $seven_zip_check = array();
                $seven_zip_check_output = array();
                file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0067_STATE_ZIP"].")");
                $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"])." (".$L["MINISERVERBACKUP.INF_0067_STATE_ZIP"].")");
                $path = $bkp_dest_dir.'/'.$bkpfolder;
                $latest_ctime = 0;
                $latest_filename = '';
                $d = dir($path);
                while (false !== ($entry = $d->read()))
                {
                    $filepath = "{$path}/{$entry}";
                    // could do also other checks than just checking whether the entry is a file
                    if (is_file($filepath) && filectime($filepath) > $latest_ctime && substr($filepath,-3) == $fileformat_extension && substr(basename($filepath),0,strlen($backup_file_prefix)) == $backup_file_prefix  )
                    {
                        $latest_ctime = filectime($filepath);
                        $latest_filename = $entry;
                    }
                }

                if ( $latest_filename )
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0072_ZIP_PREVIOUS_BACKUP_FOUND"]." ".$latest_filename,5);
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0157_ZIP_CHECK_BACKUP_FOUND"]." ".$latest_filename,5);
                    exec('7za l '.$compression.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$latest_filename).' | grep -v "/" | grep -F -- '.escapeshellarg($bkpfolder).' | wc -l', $seven_zip_check);
                    debug(__line__,"MS#".$msno." Old Format=".intval(implode("\n",$seven_zip_check)));
                    if (intval(implode("\n",$seven_zip_check)) == 1)
                    {
                        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0158_ZIP_CHECK_BACKUP_OLD_FORMAT"],4);
                        exec('7za a '.$compression.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension).' '.escapeshellarg($savedir_path.'/'.$bkpfolder.'/*').' -ms=off -mx=9 -t7z 2>&1', $seven_zip_output);
                    }
                    else
                    {
                        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0164_ZIP_CHECK_INTEGRITY"],5);
                        exec('7za t '.$compression.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$latest_filename).' 2>&1', $seven_zip_check_output);
                        $seven_zip_check_output = implode("\n",$seven_zip_check_output);
                        if (preg_match('~Everything\sis\sOk~', $seven_zip_check_output, $m ) == 1)
                        {
                            debug(__line__,"MS#".$msno." OK: ".$seven_zip_check_output);
                            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0156_ZIP_SEEMS_NOT_TO_BE_IN_OLD_FORMAT"],5);
                            copy($bkp_dest_dir.'/'.$bkpfolder.'/'.$latest_filename, $bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension);
                            exec('7za u '.$compression.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension).' '.escapeshellarg($savedir_path.'/'.$bkpfolder.'/*').' -ms=off -mx=9 -t7z -up1q0r2x2y2z1w2 -up0q3r2x2y2z0w2!'.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.'Incremental_'.$bkpdir.$fileformat_extension).' 2>&1', $seven_zip_output);
                        }
                        else
                        {
                            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0073_ZIP_CHECK_INTEGRITY_FAIL"],4);
                            debug(__line__,"MS#".$msno." FAIL: ".$seven_zip_check_output,6);
                            exec('7za a '.$compression.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension).' '.escapeshellarg($savedir_path.'/'.$bkpfolder.'/*').' -ms=off -mx=9 -t7z 2>&1', $seven_zip_output);
                        }
                    }
                }
                else
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0073_ZIP_NO_PREVIOUS_BACKUP_FOUND"],5);
                    exec('7za a '.$compression.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension).' '.escapeshellarg($savedir_path.'/'.$bkpfolder.'/*').' -ms=off -mx=9 -t7z 2>&1', $seven_zip_output);
                }
                $zipresult=end($seven_zip_output);
                if ( $zipresult != "Everything is Ok" )
                {
                    unlink($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension); // Delete previously copied zip in case of errors
                    debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0060_CREATE_ZIP_ARCHIVE_FAILED"]." [".$zipresult."]",3);
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0074_ZIP_COMPRESSION_RESULT"]." ".implode("<br>",$seven_zip_output),6);
                    $crit_issue=1;
                }
                else
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0074_ZIP_COMPRESSION_RESULT"]." ".implode("<br>",$seven_zip_output),6);
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0061_CREATE_ZIP_ARCHIVE_DONE"]." ".$finalstorage."/".$bkpdir.$fileformat_extension." (". round( intval( filesize($finalstorage."/".$bkpdir.$fileformat_extension) ) / 1024 / 1024 ,2 ) ." MB)",5);
                }
                if ($crit_issue != 1)
                {
                    $seven_zip_verify_output = array();
                    exec('7za t '.escapeshellarg($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension).' 2>&1', $seven_zip_verify_output, $seven_zip_verify_status);
                    if ($seven_zip_verify_status !== 0)
                    {
                        @unlink($bkp_dest_dir.'/'.$bkpfolder.'/'.$bkpdir.$fileformat_extension);
                        $crit_issue = 1;
                        $at_least_one_error = 1;
                        debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0073_ZIP_CHECK_INTEGRITY_FAIL"], 3);
                    }
                }
                if ( is_file($savedir_path.'/'.$bkpfolder."/log/def.log"))
                {
                    MSbackupZIP::check_def_log($savedir_path.'/'.$bkpfolder."/log/def.log");
                }
                break;
        }
        if ( $crit_issue == 1 )
        {
            create_clean_workdir_tmp($workdir_tmp);
            file_put_contents($backupstate_file,"-");
            array_push($summary,"<hr> ");
            array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
            continue;
        }
        switch ($fileformat)
        {
            case "UNCOMPRESSED":
                ####################################################################################################
                debug(__line__,"MS#".$msno." ".str_ireplace("<cleaninfo>",$finalstorage."/".$backup_file_prefix.trim($local_ip[1])."_*",str_ireplace("<number>",$backups_to_keep,$L["MINISERVERBACKUP.INF_0092_CLEAN_UP_BACKUP"])),5);
                $files = glob($finalstorage."/".$backup_file_prefix.trim($local_ip[1])."_*", GLOB_ONLYDIR | GLOB_NOSORT);
                usort($files,"sort_by_mtime");
                $keeps = $files;
                if ( count($keeps) > $backups_to_keep )
                {
                    $keeps = array_slice($keeps, 0 - $backups_to_keep, $backups_to_keep);
                }
                foreach($keeps as $keep)
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0094_KEEP_BACKUP"]." ".$keep,5);
                }
                unset($keeps);

                if ( count($files) > $backups_to_keep )
                {
                    $deletions = array_slice($files, 0, count($files) - $backups_to_keep);

                    foreach($deletions as $to_delete)
                    {
                        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0093_REMOVE_BACKUP"]." ".$to_delete,5);
                        rrmdir($to_delete);
                    }
                    unset($deletions);
                }
                ####################################################################################################
            break;
            case "ZIP":
                ####################################################################################################
                debug(__line__,"MS#".$msno." ".str_ireplace("<cleaninfo>",$finalstorage."/".$backup_file_prefix.trim($local_ip[1])."_*".$fileformat_extension,str_ireplace("<number>",$backups_to_keep,$L["MINISERVERBACKUP.INF_0092_CLEAN_UP_BACKUP"])),5);
                $files = glob($finalstorage."/".$backup_file_prefix.trim($local_ip[1])."_*".$fileformat_extension, GLOB_NOSORT);
                usort($files,"sort_by_mtime");
                $keeps = $files;
                if ( count($keeps) > $backups_to_keep )
                {
                    $keeps = array_slice($keeps, 0 - $backups_to_keep, $backups_to_keep);
                }
                foreach($keeps as $keep)
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0094_KEEP_BACKUP"]." ".$keep,5);
                }
                unset($keeps);

                if ( count($files) > $backups_to_keep )
                {
                    $deletions = array_slice($files, 0, count($files) - $backups_to_keep);

                    foreach($deletions as $to_delete)
                    {
                        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0093_REMOVE_BACKUP"]." ".$to_delete,5);
                        unlink($to_delete);
                    }
                    unset($deletions);
                }
                ####################################################################################################
                break;
            case "7Z":
                ####################################################################################################
                debug(__line__,"MS#".$msno." ".str_ireplace("<cleaninfo>",$finalstorage."/".$backup_file_prefix.trim($local_ip[1])."_*".$fileformat_extension,str_ireplace("<number>",$backups_to_keep,$L["MINISERVERBACKUP.INF_0092_CLEAN_UP_BACKUP"])),6);
                $files = glob($finalstorage."/".$backup_file_prefix.trim($local_ip[1])."_*".$fileformat_extension, GLOB_NOSORT);
                usort($files,"sort_by_mtime");
                $keeps = $files;
                if ( count($keeps) > $backups_to_keep )
                {
                    $keeps = array_slice($keeps, 0 - $backups_to_keep, $backups_to_keep);
                }
                foreach($keeps as $keep)
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0094_KEEP_BACKUP"]." ".$keep,5);
                }
                unset($keeps);

                if ( count($files) > $backups_to_keep )
                {
                    $deletions = array_slice($files, 0, count($files) - $backups_to_keep);

                    foreach($deletions as $to_delete)
                    {
                        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0093_REMOVE_BACKUP"]." ".$to_delete,5);
                        unlink($to_delete);
                    }
                    unset($deletions);
                }
                debug(__line__,"MS#".$msno." ".str_ireplace("<cleaninfo>",$finalstorage."/Incremental_".$backup_file_prefix.trim($local_ip[1])."_*".$fileformat_extension,str_ireplace("<number>",$inc_backups_to_keep,$L["MINISERVERBACKUP.INF_0092_CLEAN_UP_BACKUP"])),6);
                $files = glob($finalstorage."/Incremental_".$backup_file_prefix.trim($local_ip[1])."_*".$fileformat_extension, GLOB_NOSORT);
                usort($files,"sort_by_mtime");
                $keeps = $files;
                if ( count($keeps) > $inc_backups_to_keep )
                {
                    $keeps = array_slice($keeps, 0 - $inc_backups_to_keep, $inc_backups_to_keep);
                }
                foreach($keeps as $keep)
                {
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0094_KEEP_BACKUP"]." ".$keep,5);
                }
                unset($keeps);

                if ( count($files) > $inc_backups_to_keep )
                {
                    $deletions = array_slice($files, 0, count($files) - $inc_backups_to_keep);

                    foreach($deletions as $to_delete)
                    {
                        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0093_REMOVE_BACKUP"]." ".$to_delete,5);
                        unlink($to_delete);
                    }
                    unset($deletions);
                }

                ####################################################################################################
                break;
            }
            $nbr_saved = count($filetree["size"]);
            switch ($nbr_saved)
            {
                case "0":
                    $fileinf = $L["MINISERVERBACKUP.INF_0100_NO_FILE_CHANGED"];
                    break;
                case "1":
                    $fileinf = $L["MINISERVERBACKUP.INF_0098_FILE_CHANGED"]." ".formatBytes(array_sum($filetree["size"]));
                    break;
                default:
                    $fileinf = $nbr_saved." ".$L["MINISERVERBACKUP.INF_0101_FILES_CHANGED"]." ".formatBytes(array_sum($filetree["size"]));
                    break;
            }
            $message = str_ireplace("<NAME>",$miniserver['Name'],str_ireplace("<MS>",$msno,$L["MINISERVERBACKUP.INF_0098_BACKUP_OF_MINISERVER_COMPLETED"]))." ".$fileinf;
            debug(__line__,"MS#".$msno." ".$message,5);
            $notification = array (
            "PACKAGE" => LBPPLUGINDIR,
            "NAME" => $L['GENERAL.MY_NAME']." ".$miniserver['Name'],
            "MESSAGE" => $message,
            "SEVERITY" => 6,
            "LOGFILE"    => $logfilename);
            if ( $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "on" || $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "1" )
            {
                @notify_ext ($notification);
            }
            array_push($summary,"MS#".$msno." "."<OK> ".$message);
    }
    else
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0039_FINAL_STORAGE_NOT_WRITABLE"]." ".$finalstorage,3);
        create_clean_workdir_tmp($workdir_tmp);
        file_put_contents($backupstate_file,"-");
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        continue;

    }
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0032_CLEAN_WORKDIR_TMP"]." ".$workdir_tmp);
    create_clean_workdir_tmp($workdir_tmp);
    file_put_contents($backupstate_file,str_ireplace("<MS>",$msno,$L["MINISERVERBACKUP.INF_0136_BACKUP_COMPLETED_MS"]));
    @system("php -f ".dirname($_SERVER['PHP_SELF']).'/ajax_config_handler.php LAST_SAVE'.$msno.'='.$last_save_stamp.' >/dev/null 2>&1');
    $at_least_one_save = 1;
    array_push($summary,"<hr> ");
    array_push($saved_ms," #".$msno." (".$miniserver['Name'].")");
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0136_BACKUP_COMPLETED_MS"]." #".$msno." (".$miniserver['Name'].")");
    @system("php -f ".dirname($_SERVER['PHP_SELF']).'/ajax_config_handler.php LAST_ERROR'.$msno.'=0 >/dev/null 2>&1');
}
if ( $msno > count($ms) ) { $msno = ""; };
array_push($summary," ");
debug(__line__,$L["MINISERVERBACKUP.INF_0019_BACKUPS_COMPLETE"],5);

if ( count($saved_ms) > 0 )
{
    $str_part_saved_ms = " <font color=green>".join(", ",$saved_ms)."</font>";
}
else
{
    $str_part_saved_ms = " ";
}
if ( count($problematic_ms) > 0 )
{

    $str_part_problematic_ms = " ".$L["MINISERVERBACKUP.INF_0141_BACKUP_COMPLETED_MS_FAIL"]." <font color=red>".join(", ",$problematic_ms)."</font>";
}
else
{
    $str_part_problematic_ms = "";
}

if ( count($saved_ms) > 0 )
{
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0134_BACKUP_FINISHED"]." ".$L["MINISERVERBACKUP.INF_0136_BACKUP_COMPLETED_MS"].$str_part_saved_ms.$str_part_problematic_ms);
}
else
{
    $log->LOGTITLE($L["MINISERVERBACKUP.INF_0134_BACKUP_FINISHED"]." ".$L["MINISERVERBACKUP.INF_0137_BACKUP_COMPLETED_NO_MS"].$str_part_problematic_ms);
}

curl_close($curl);
debug(__line__,$L["MINISERVERBACKUP.INF_0034_DEBUG_DIRECTORY_DELETE"]." -> ".$workdir_tmp);
rrmdir($workdir_tmp);

$runtime = microtime(true) - $start;

if ( count($summary) > 2 )
{
    error_log($L["MINISERVERBACKUP.INF_9999_SUMMARIZE_ERRORS"]);
    foreach ($summary as &$errors)
    {
        if ( ! preg_match("/<hr>/i", $errors) && $errors != " " )
        {
            error_log($errors);
        }
    }
}


$err_html = "";

foreach ($summary as &$errors)
{
    debug("Summary:\n".htmlentities($errors));
    $errors = nl2br($errors);
    if ( preg_match("/<INFO>/i", $errors) )
    {
        $err_html .= "<br><span style='color:#000000; background-color:#DDEFFF'>".$errors."</span>";
    }
    else if ( preg_match("/<OK>/i", $errors) )
    {
        $err_html .= "<br><span style='color:#000000; background-color:#D8FADC'>".$errors."</span>";
    }
    else if ( preg_match("/<WARNING>/i", $errors)  )
    {
        $err_html .= "<br><span style='color:#000000; background-color:#FFFFC0'>".$errors."</span>";
    }
    else if ( preg_match("/<ERROR>/i", $errors)  )
    {
        $err_html .= "<br><span style='color:#000000; background-color:#FFE0E0'>".$errors."</span>";
    }
    else if ( preg_match("/<CRITICAL>/i", $errors)  )
    {
        $err_html .= "<br><span style='color:#000000; background-color:#FFc0c0'>".$errors."</span>";
    }
    else if ( preg_match("/<ALERT>/i", $errors)  )
    {
        $err_html .= "<br><span style='color:#ffffff; background-color:#0000a0'>".$errors."</span>";
    }
    else
    {
        $err_html .= "<br>".$errors;
    }
}
# $err_html = preg_replace('/\\n+/i','',$err_html);
# $err_html = preg_replace('/\\r+/i','',$err_html);
$err_html = preg_replace('/\s\s+/i',' ',$err_html);
$err_html = preg_replace('/<hr>\s<br>+/i','<hr>',$err_html);
$err_html = preg_replace("/(<hr>)\\1+/", "$1", $err_html);
if (str_replace(array('<ALERT>', '<CRITICAL>','<ERROR>'),'', $err_html) != $err_html)
{
    $at_least_one_error = 1;
}
else if (str_replace(array('<WARNING>'),'', $err_html) != $err_html)
{
    $at_least_one_warning = 1;
}


if ( $plugin_cfg['MSBACKUP_USE_EMAILS'] == "fail" )
{
    debug(__line__,$L["MINISERVERBACKUP.INF_0116_MAIL_ENABLED"]." ".$L["MINISERVERBACKUP.INF_0140_EMAIL_ERROR_ONLY"],6);
}
else
{
    debug(__line__,$L["MINISERVERBACKUP.INF_0116_MAIL_ENABLED"],6);
}
if ( ( $at_least_one_error == 1 || $at_least_one_warning == 1 || $at_least_one_save == 1 ) && (( $plugin_cfg['MSBACKUP_USE_EMAILS'] == "on" || $plugin_cfg['MSBACKUP_USE_EMAILS'] == "1" )|| ( $plugin_cfg['MSBACKUP_USE_EMAILS'] == "fail" && $at_least_one_error == 1 ) ) )
{
    debug(__line__,$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"],6);
    $mail_config_file   = LBSCONFIGDIR."/mail.json";
    if (is_readable($mail_config_file))
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0115_READ_MAIL_CONFIG"]." => ".$mail_config_file,6);
        $mail_cfg  = json_decode(file_get_contents($mail_config_file), true);
    }
    else
    {
        debug(__line__,$L["ERRORS.ERR_0055_ERR_READ_EMAIL_CONFIG"]." => ".$mail_config_file,6);
        $mail_config_file   = LBSCONFIGDIR."/mail.cfg";
        debug(__line__,$L["MINISERVERBACKUP.INF_0117_TRY_OLD_EMAIL_CFG"]." => ".$mail_config_file,6);

        if (is_readable($mail_config_file))
        {
            debug(__line__,$L["MINISERVERBACKUP.INF_0115_READ_MAIL_CONFIG"]." => ".$mail_config_file,6);
            $mail_cfg    = parse_ini_file($mail_config_file,true);
        }
    }

    if ( !isset($mail_cfg) )
    {
        debug(__line__,$L["ERRORS.ERR_0055_ERR_READ_EMAIL_CONFIG"],4);
    }
    else
    {
        debug(__line__,$L["MINISERVERBACKUP.INF_0118_EMAIL_CFG_OK"],6);
        if ( $mail_cfg['SMTP']['ISCONFIGURED'] == "0" )
        {
            debug(__line__,$L["MINISERVERBACKUP.INF_0119_EMAIL_NOT_CONFIGURED"],6);
        }
        else
        {
            $datetime    = new DateTime;
            $datetime->getTimestamp();
            $outer_boundary= md5("o".time());
            $inner_boundary= md5("i".time());
            $htmlpic="";
            $mailTo = implode(",",explode(";",$plugin_cfg['EMAIL_RECIPIENT']));
            $mailFromName   = $L["EMAIL.EMAIL_FROM_NAME"];  // Sender name fix from Language file
            if ( isset($mail_cfg['SMTP']['EMAIL']) )
            {
            $mailFrom =    trim(str_ireplace('"',"",$mail_cfg['SMTP']['EMAIL']));
            if ( !isset($mailFromName) )
            {
                $mailFromName   = "\"LoxBerry\"";  // Sender name
            }
            }
            debug(__line__,$L["MINISERVERBACKUP.INF_0120_SEND_EMAIL_INFO"]." From: ".$mailFromName.htmlentities(" <".$mailFrom."> ")." To: ".$mailTo,6);
            if ( $at_least_one_error == 1 )
            {
                $status = "FAILED";
                // $status .= " =E2=9D=8C"; // X
            }
            else if ( $at_least_one_warning == 1 )
            {
                $status = "WARNING";
                // $status .= "=E2=9D=95"; // Exclamation mark
            }
            else
            {
                $status = "SUCCESS";
                // $status .= "=E2=9C=85"; // Checkmark
            }

            $html = "From: ".$mailFromName." <".$mailFrom.">
To: ".$mailTo."
Subject: =?utf-8?Q? ".$L["EMAIL.EMAIL_SUBJECT"]." - ".$status." ?=
MIME-Version: 1.0
Content-Type: multipart/alternative;
 boundary=\"------------".$outer_boundary."\"

This is a multi-part message in MIME format.
--------------".$outer_boundary."
Content-Type: text/plain; charset=utf-8; format=flowed
Content-Transfer-Encoding: 8bit

".strip_tags($L["EMAIL.EMAIL_BODY"])."\n".strip_tags(implode("\n", $summary))."

\n--\n".strip_tags($L["EMAIL.EMAIL_SINATURE"])."

--------------".$outer_boundary."
Content-Type: multipart/related;
 boundary=\"------------".$inner_boundary."\"

--------------".$inner_boundary."
Content-Type: text/html; charset=utf-8
Content-Transfer-Encoding: 8bit

<html>
    <head>
        <meta http-equiv=\"content-type\" content=\"text/html; charset=utf-8\">
        <style>
            body { margin: 0px; padding: 10px; color: #1e1e1e; background: #fafafa; font-family: 'Calibri', 'Verdana', sans-serif; }
            hr { margin: 4px 0;  }
        </style>
    </head>
    <body>
        <div>
            <img style=\"width: 100%; max-width: 800px;\" src=\"cid:logo_".$datetime->format("Y-m-d_i\hh\mH\s")."\" alt=\"Miniserver Backup NG\"><br>
            <br>
            ".$L["EMAIL.EMAIL_BODY"]."<br>
            <br><hr>
            ".preg_replace('/(^<br>|<br>\\s<br>+)/i', '', $err_html)."<br>
            ".$L["EMAIL.EMAIL_SINATURE"]."
        </div>
    </body>
</html>

--------------".$inner_boundary."
Content-Type: image/jpeg; name=\"logo_".$datetime->format("Y-m-d_i\hh\mH\s").".png\"
Content-Transfer-Encoding: base64
Content-ID: <logo_".$datetime->format("Y-m-d_i\hh\mH\s").">
Content-Disposition: inline; filename=\"logo_".$datetime->format("Y-m-d_i\hh\mH\s").".png\"

".chunk_split(base64_encode(file_get_contents('logo.png')))."
--------------".$inner_boundary."--
--------------".$outer_boundary."--";
            $condition = "";
            switch (strtolower($plugin_cfg['MSBACKUP_USE_EMAILS']))
            {
                case "on":
                case "1":
                    $condition = $L["GENERAL.TXT_LABEL_MSBACKUP_USE_EMAILS_ON"];
                    break;
                case "fail":
                    $condition = $L["GENERAL.TXT_LABEL_MSBACKUP_USE_EMAILS_ERROR"];
                    break;
            }
            if ( ( $plugin_cfg['MSBACKUP_USE_EMAILS'] == "fail" && $at_least_one_error == 1 ) || ( $plugin_cfg['MSBACKUP_USE_EMAILS'] == "on" || $plugin_cfg['MSBACKUP_USE_EMAILS'] == "1" ) )
            {
                debug(__line__,$L["MINISERVERBACKUP.INF_0125_SEND_EMAIL_ON_ERROR"]." ".$condition,6);
                $tmpfname = tempnam("/tmp", "msbackup_mail_");
                $handle = fopen($tmpfname, "w") or debug(__line__,$L["ERRORS.ERR_0056_ERR_OPEN_TEMPFILE_EMAIL"]." ".$tmpfname,4);
                fwrite($handle, $html) or debug(__line__,$L["ERRORS.ERR_0057_ERR_WRITE_TEMPFILE_EMAIL"]." ".$tmpfname,4);
                fclose($handle);
                $resultarray = array();
                @exec("/usr/sbin/sendmail -v -t 2>&1 < $tmpfname ",$resultarray,$retval);
                unlink($tmpfname) or debug(__line__,$L["ERRORS.ERR_0058_ERR_DELETE_TEMPFILE_EMAIL"]." ".$tmpfname,4);
                debug(__line__,"Sendmail:\n".htmlspecialchars(join("\n",$resultarray)),7);
                if($retval)
                {
                    debug(__line__,$L["ERRORS.ERR_0059_ERR_SEND_EMAIL"]." ".array_pop($resultarray),3);
                }
                else
                {
                    debug(__line__,$L["MINISERVERBACKUP.INF_0121_EMAIL_SEND_OK"],5);
                }
            }
            else
            {
                debug(__line__,$L["MINISERVERBACKUP.INF_0126_DO_NOT_SEND_EMAIL_ON_ERROR"]." ".$condition,6);
            }
        }
    }
}
else
{
    debug(__line__,$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"],6);
}

sleep(3); // To prevent misdetection in createmsbackup.pl
file_put_contents($backupstate_file, "-");

if ( isset($argv[1]) )
{
    if ( $argv[1] == "symlink" )
    {
        $log->LOGTITLE($L["MINISERVERBACKUP.INF_0153_SYMLINKS_AFTER_UPGRADE_OK"]);
        LOGOK ($L["MINISERVERBACKUP.INF_0153_SYMLINKS_AFTER_UPGRADE_OK"]);
    }
    else
    {
        LOGOK ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
    }
}
else
{
    LOGOK ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
}
LOGEND ("");
exit(count($problematic_ms) > 0 ? 1 : 0);

function get_connection_data($checkurl)
{
    global $different_cloudrequests,$connection_data_returncode,$all_cloudrequests,$known_for_today,$miniserver,$L,$msno,$workdir_tmp,$backupstate_file,$summary,$problematic_ms,$port,$prefix,$log,$date_time_format,$plugin_cfg,$cfg,$cloud_requests_file,$connection_data_returncode0,$manual_backup,$randomsleep;
    $connection_data_returncode    = 0;
    if ( $miniserver['UseCloudDNS'] == "on" || $miniserver['UseCloudDNS'] == "1" )
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0111_CLOUD_DNS_USED"]." => ".$miniserver['Name'],6);
        if ( $miniserver['CloudURL'] == "" )
        {
            debug(__line__,"MS#".$msno." ".$L["ERROR.ERR_0068_PROBLEM_READING_CLOUD_DNS_ADDR"]." => ".$miniserver['Name'],5);
        }
        if ( isset($checkurl) )
        {
            $sleep_start = time();
            $sleep_end = $sleep_start + 2;
            $sleep_until = date($date_time_format,$sleep_end);
            debug(__line__,"MS#".$msno." (".$miniserver['Name'].") ".str_ireplace("<wait_until>",$sleep_until,$L["MINISERVERBACKUP.INF_0107_SLEEP_BEFORE_SENDING_NEXT_CLOUD_DNS_QUERY"]),5);
            $wait_info_string = "MS#".$msno." (".$miniserver['Name'].") ".str_ireplace("<wait_until>",$sleep_until,str_ireplace("<time>",secondsToTime($sleep_end - time()),$L["MINISERVERBACKUP.INF_0142_TIME_TO_WAIT"]));
            file_put_contents($backupstate_file,$wait_info_string);
            $log->LOGTITLE($wait_info_string);
            sleep(2);
        }
        if ( date("i",time()) == "00" || date("i",time()) == "15" || date("i",time()) == "30" || date("i",time()) == "45" )
        {
            debug(__line__,"MS#".$msno." (".$miniserver['Name'].") ".$L["MINISERVERBACKUP.INF_0143_WAIT_FOR_RESTART"],6);
            sleep(5); // Fix for Loxone Cloud restarts at 0, 15, 30 and 45
        }
        if ( ($miniserver['UseCloudDNS'] == "on" ||$miniserver['UseCloudDNS'] == "1") && $randomsleep == 1 && $manual_backup != 1 )
        {
            if ( isset($plugin_cfg["RANDOM_SLEEP"]) )
            {
                $randomsleep = intval($plugin_cfg["RANDOM_SLEEP"]);
            }
            else
            {
                $randomsleep = random_int(2,300);
            }
            $sleep_start = time();
            $sleep_end = $sleep_start + $randomsleep;
            $sleep_until = date($date_time_format,$sleep_end);
            $wait_info_string = "MS#".$msno." (".$miniserver['Name'].") ".str_ireplace("<time>",$sleep_until." ($randomsleep s)",$L["MINISERVERBACKUP.INF_0144_RANDOM_SLEEP"]);
            debug(__line__,$wait_info_string,6);
            file_put_contents($backupstate_file,$wait_info_string);
            $log->LOGTITLE($wait_info_string);
            sleep($randomsleep);
        }

        $cloud_requests_json_array = json_decode(file_get_contents($cloud_requests_file),true);
        $known_for_today = 0;
        $all_cloudrequests = 0;
        if ($cloud_requests_json_array)
        {
            $key = array_search(strtolower($miniserver['CloudURL']), array_column($cloud_requests_json_array, 'cloudurl'));
            if ($key !== FALSE)
            {
                if ( substr($cloud_requests_json_array[$key]["date"],0,8) == date("Ymd",time()) )
                {
                    $cloud_requests_json_array[$key]["requests"]++;
                    $known_for_today = 1;
                }
                else
                {
                    $cloud_requests_json_array[$key]["requests"] = 1;
                }
                debug(__line__,"MS#".$msno." (".$miniserver['Name'].") ".str_ireplace("<no>",$cloud_requests_json_array[$key]["requests"],$L["MINISERVERBACKUP.INF_0149_CLOUD_DNS_REQUEST_DATA_MS_FOUND"]),6);
            }
            else
            {
                debug(__line__,"MS#".$msno." (".$miniserver['Name'].") ".$L["MINISERVERBACKUP.INF_0150_CLOUD_DNS_REQUEST_DATA_MS_NOT_FOUND"],6);
                unset ($cloud_request_array_to_push);
                $cloud_request_array_to_push['msno'] = $msno;
                $cloud_request_array_to_push['date'] = date("Ymd",time());
                $cloud_request_array_to_push['cloudurl'] = strtolower($miniserver['CloudURL']);
                $cloud_request_array_to_push['requests'] = 1;
                array_push($cloud_requests_json_array, $cloud_request_array_to_push);
            }
        }
        else
        {
            debug(__line__,"MS#".$msno." (".$miniserver['Name'].") ".$L["MINISERVERBACKUP.INF_0151_CLOUD_DNS_REQUEST_DATA_NOT_FOUND"],6);
            $cloud_requests_json_array = array();
            unset ($cloud_request_array_to_push);
            $cloud_request_array_to_push['msno'] = $msno;
            $cloud_request_array_to_push['date'] = date("Ymd",time());
            $cloud_request_array_to_push['cloudurl'] = strtolower($miniserver['CloudURL']);
            $cloud_request_array_to_push['requests'] = 1;
            array_push($cloud_requests_json_array, $cloud_request_array_to_push);
        }
        $cloud_requests_json_array_today = array_map("cloud_requests_today", $cloud_requests_json_array);
        $different_cloudrequests = 0;
        foreach($cloud_requests_json_array_today as $datapacket)
        {
            if ( intval($datapacket['requests']) > 0 )
            {
                $different_cloudrequests++;
                $all_cloudrequests = $all_cloudrequests + intval($datapacket['requests']);
            }
        }
        if ( $known_for_today != 1)
        {
            debug(__line__,"MS#".$msno." ".str_ireplace("<all>",$all_cloudrequests,str_ireplace("<max_different_request>",10,str_ireplace("<different_request>",$different_cloudrequests,$L["MINISERVERBACKUP.INF_0148_CLOUD_DNS_REQUEST_NUMBER"])))." (".$miniserver['CloudURL'].")",6);
        }
        if ( $different_cloudrequests >= 10 && $known_for_today != 1)
        {
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0066_CLOUDDNS_TOO_MUCH_REQUESTS_FOR_TODAY"]." => ".$miniserver['Name'],5);
                file_put_contents($cloud_requests_file,json_encode($cloud_requests_json_array_today));
                $connection_data_returncode = 3;
                return $connection_data_returncode;
        }
        file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]));
        $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]));
        file_put_contents($cloud_requests_file,json_encode($cloud_requests_json_array_today));

        $curl_dns = curl_init(str_replace(" ","%20",$checkurl));
        curl_setopt($curl_dns, CURLOPT_NOPROGRESS        , 1);
        curl_setopt($curl_dns, CURLOPT_FOLLOWLOCATION    , 0);
        curl_setopt($curl_dns, CURLOPT_CONNECTTIMEOUT    , 600);
        curl_setopt($curl_dns, CURLOPT_TIMEOUT            , 600);
        curl_setopt($curl_dns, CURLOPT_SSL_VERIFYPEER    , 0);
        curl_setopt($curl_dns, CURLOPT_SSL_VERIFYSTATUS    , 0);
        curl_setopt($curl_dns, CURLOPT_SSL_VERIFYHOST    , 0);
        curl_setopt($curl_dns, CURLOPT_RETURNTRANSFER     , 1);
        if ( !$curl_dns )
        {
            debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0002_ERROR_INIT_CURL"],3);
            curl_close($curl_dns);
            $connection_data_returncode    = 5;
            return $connection_data_returncode;
        }
        sleep(1);
        curl_exec($curl_dns);
        $response = curl_multi_getcontent($curl_dns);
        debug(__line__,"MS#".$msno." URL: $checkurl => Response: ".$response."\n");
        $response = json_decode($response,true);
        // Possible is for example
        // cmd getip
        // IP xxx.xxx.xxx.xxx
        // IPHTTPS xxx.xxx.xxx.xxx:yyyy
        // Code 403 (Forbidden) 200 (OK)
        // LastUpdated 2018-03-11 16:52:30
        // PortOpen           (true/false)
        // PortOpenHTTPS    (true/false)
        // DNS-Status         registered
        // RemoteConnect     (true/false)
        $HTTPS_mode     =    ($miniserver['PreferHttps'] == 1) ? "HTTPS":"";
        $code            =    curl_getinfo($curl_dns,CURLINFO_RESPONSE_CODE);

        switch ($code)
        {
            case "200":
                $RemoteConnect = ( isset($response["RemoteConnect"]) ) ? $response["RemoteConnect"]:"false";
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0109_CLOUD_DNS_QUERY_RESULT"]." ".$miniserver['Name']." => IP: ".$response["IP".$HTTPS_mode]." Code: ".$response["Code"]." LastUpdated: ".$response["LastUpdated"]." PortOpen".$HTTPS_mode.": ".$response["PortOpen".$HTTPS_mode]." DNS-Status: ".$response["DNS-Status"]." RemoteConnect: ".$RemoteConnect,5);
                if ( $response["Code"] == "405" )
                {
                    debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0063_CLOUDDNS_ERROR_405"]." => ".$miniserver['Name'],3);
                    debug(__line__,"MS#".$msno." URL: ".$checkurl." => Code ".$code);
                    debug(__line__,"MS#".$msno." ".join(" ",$response));
                    $connection_data_returncode = 6;
                    break;
                }
                if ( $response["Code"] != "200" )
                {
                    debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0064_CLOUDDNS_CODE_MISMATCH"]." => ".$miniserver['Name']."\nURL: ".$checkurl." => Code ".$code,4);
                    debug(__line__,"MS#".$msno." ".join(" ",$response));
                    $connection_data_returncode = 1;
                    break;
                }
                $ip_info = explode(":",$response["IP".$HTTPS_mode]);
                $miniserver['IPAddress']=$ip_info[0];
                if (count($ip_info) == 2)
                {
                    $port    = $ip_info[1];
                }
                else
                {
                    $port   = ($miniserver['PreferHttps'] == 1) ? 443:80;
                }
                if ( $response["PortOpen".$HTTPS_mode] != "true" )
                {
                    debug(__line__,"MS#".$msno." ".str_ireplace("<miniserver>",$miniserver['Name'],$L["ERRORS.ERR_0050_CLOUDDNS_PORT_NOT_OPEN"])." ".$response["LastUpdated"],3);
                    $connection_data_returncode = 4;
                    break;
                }
                else
                {
                    if ( isset($response["RemoteConnect"]) &&  $response["RemoteConnect"] != "true" && $HTTPS_mode == "HTTPS")
                    {
                        debug(__line__,"MS#".$msno." ".str_ireplace("<miniserver>",$miniserver['Name'],$L["ERRORS.ERR_0072_CLOUDDNS_REMOTE_CONNECT_NOT_TRUE"])." ".$response["LastUpdated"],3);
                        $connection_data_returncode = 4;
                        break;
                    }
                }
                break;
            case "403":
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0051_CLOUDDNS_ERROR_403"]." => ".$miniserver['Name'],3);
                $connection_data_returncode = 6;
                break;
            case "0":
                if ( $connection_data_returncode0 > 3 )
                {
                    debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0065_TOO_MANY_CLOUDDNS_ERROR_0"]." => ".$miniserver['Name'],4);
                }
                else
                {
                    debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0062_CLOUDDNS_ERROR_0"]." => ".$miniserver['Name'],5);
                    sleep(1);
                    $connection_data_returncode0++;
                }
                $connection_data_returncode = 1;
                break;
            case "418":
                debug(__line__,"MS#".$msno." (".$miniserver['Name'].") ".$L["ERRORS.ERR_0053_CLOUDDNS_ERROR_418"],5);
                $connection_data_returncode = 1;
                break;
            case "500":
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0061_CLOUDDNS_ERROR_500"]." => ".$miniserver['Name'],4);
                $connection_data_returncode = 1;
                break;
            default;
                debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0052_CLOUDDNS_UNEXPECTED_ERROR"]." => ".$miniserver['Name']."\nURL: ".$checkurl." => Code ".$code."\n".join("\n",$response),3);
                $connection_data_returncode = 1;
        }
        if ( $connection_data_returncode >= 1 )
        {
            curl_close($curl_dns);
            return $connection_data_returncode;
        }
        $connection_data_returncode0 = 0;
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0160_CLOUD_DNS_OKAY"],6);
    }
    else
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0110_CLOUD_DNS_NOT_USED"]." => ".$miniserver['Name']." @ ".$miniserver['IPAddress'],5);
    }

    if ( $miniserver['IPAddress'] == "0.0.0.0" )
    {
        debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0046_CLOUDDNS_IP_INVALID"]." => ".$miniserver['Name'],3);
        array_push($summary,"<hr> ");
        array_push($problematic_ms," #".$msno." (".$miniserver['Name'].")");
        $connection_data_returncode = 1;
        return $connection_data_returncode;
    }
    $connection_data_returncode = 0;
    return $connection_data_returncode;
}

function formatBytes($size, $precision = 2)
{
    if ( !is_numeric( $size ) || $size == 0 ) return "0 kB";
    $base = log($size, 1024);
    $suffixes = array('', 'kB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
}

function recurse_copy($src,$dst,$copied_bytes,$filestosave)
{
    global $L, $copied_bytes,$filestosave,$backupstate_file,$msno,$workdir_tmp, $miniserver,$log,$copyerror;
    if ( $copyerror == 1 ) return false;
    $dir = opendir($src);
    if ( ! is_dir($dst) )
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0035_DEBUG_DIRECTORY_CREATE"]." ".$dst);
        if(!@mkdir($dst))
        {
            $errors= error_get_last();
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.ERR_0067_PROBLEM_CREATING_FINAL_DIR"]." => ".$dst,3);
            debug(__line__,"MS#".$msno." Code: ".$errors['type']." Info: ".$errors['message']);
            $copyerror = 1;
            return false;
        }
    }
    while(false !== ( $file = readdir($dir)) )
    {
        if (( $file != '.' ) && ( $file != '..' ))
        {
            if ( is_dir($src . '/' . $file) )
            {
                recurse_copy($src . '/' . $file,$dst . '/' . $file,$copied_bytes,$filestosave);
            }
            else
            {
                debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0078_DEBUG_COPY_FILE"]." ".$src . '/' . $file .' => ' . $dst . '/' . $file);
                if ( basename($src)."/".$file == "log/def.log" )
                {
                    MSbackupZIP::check_def_log($src . '/' . $file);
                }
                if(!@copy($src . '/' . $file,$dst . '/' . $file))
                {
                    $errors= error_get_last();
                    debug(__line__,"MS#".$msno." ".$L["ERRORS.ERR_0044_ERR_COPY_FAIL"]." ".$dst . '/' . $file,3);
                    debug(__line__,"MS#".$msno." Code: ".$errors['type']." Info: ".$errors['message']);
                    $copyerror = 1;
                    return false;
                }
                $copied_bytes = $copied_bytes + filesize($dst . '/' . $file);
                $filestosave = $filestosave  - 1;
                if ( ! ($filestosave % 10) )
                {
                    $stateinfo = " (".$L["MINISERVERBACKUP.INF_0081_STATE_COPY"]." ".str_pad($filestosave,4," ",STR_PAD_LEFT).", ".$L["MINISERVERBACKUP.INF_0082_STATE_COPY_MB"]." ".round( $copied_bytes / 1024 / 1024 ,2 )." MB)";
                    file_put_contents($backupstate_file,str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]).$stateinfo);
                    $log->LOGTITLE(str_ireplace("<MS>",$msno." (".$miniserver['Name'].")",$L["MINISERVERBACKUP.INF_0068_STATE_RUN"]).$stateinfo);
                    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0079_DEBUG_COPY_PROGRESS"].$stateinfo,6);
                }
            }
        }
    }
    closedir($dir);
return $copied_bytes;
}

class MSbackupZIP
{
    private static function folderToZip($folder, &$zipFile, $exclusiveLength) {
        global $L,$summary,$miniserver,$msno;
        $handle = opendir($folder);
        while (false !== $f = readdir($handle)) {
            if ($f != '.' && $f != '..')
            {
                $filePath = "$folder/$f";
                // Remove prefix from file path before add to zip.
                $localPath = substr($filePath, $exclusiveLength);
                if ( basename(dirname($filePath))."/".basename($filePath) == "log/def.log" )
                {
                    MSbackupZIP::check_def_log($filePath);
                }

                if (is_file($filePath))
                {
                debug(__line__,"MS#".$msno." "."ZIP: ".$L["MINISERVERBACKUP.INF_0060_ADD_FILE_TO_ZIP"]." ".$filePath);
                $zipFile->addFile($filePath, $localPath);
                }
                elseif (is_dir($filePath))
                {
                // Add sub-directory.
                    debug(__line__,"MS#".$msno." "."ZIP: ".$L["MINISERVERBACKUP.INF_0059_ADD_FOLDER_TO_ZIP"]." ".$filePath,6);
                    $zipFile->addEmptyDir($localPath);
                    self::folderToZip($filePath, $zipFile, $exclusiveLength);
                }
            }
        }
        closedir($handle);
    }

    public static function zipDir($sourcePath, $outZipPath)
    {
        global $L,$msno;
        $z = new ZipArchive();
        $z->open($outZipPath, ZIPARCHIVE::CREATE);
        self::folderToZip($sourcePath, $z, strlen("$sourcePath/"));
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0065_COMPRESS_ZIP_WAIT"],5);
        $z->close();
    }

    public static function check_def_log($filePath)
    {
        global $L,$summary,$miniserver,$backupstate_file,$msno,$plugin_cfg;
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0080_CHECK_DEFLOG"]." (" . $miniserver['Name'] .")",6);
        $deflog = explode("\n",file_get_contents($filePath));
        $lookfor = "PRG Start|PRG Reboot";
        $matches = array_filter($deflog, function($var) use ($lookfor) { return preg_match("/\b$lookfor\b/", $var); });
        $last_reboot_key = array_pop($matches);
        array_push($summary,"MS#".$msno." "."<INFO> ".$L["MINISERVERBACKUP.INF_0062_LAST_MS_REBOOT"]." ".preg_replace("/[\n\r]/","",str_ireplace(';PRG Reboot',' (Version:',str_ireplace(';PRG Start',' (Version:',$last_reboot_key)).")"));
        if ( isset ( $last_reboot_key ) )
        {
            @system("php -f ".dirname($_SERVER['PHP_SELF']).'/ajax_config_handler.php LAST_REBOOT'.$msno.'="'.$last_reboot_key.'" >/dev/null 2>&1');
        }
        $key_in_deflog = array_search($last_reboot_key,$deflog,true);
        $deflog = array_slice($deflog, $key_in_deflog, NULL, TRUE);
        $lookfor = "SDC number of ";
        $SDC_matches = array_filter($deflog, function($var) use ($lookfor) { return preg_match("/\b$lookfor\b/i", $var); });
        if ( $SDC_matches !== false )
        {
            $error_count = array();
            $error_count_severe = array();
            $normal_SDC_errors = array();
            $severe_SDC_errors = array();
            foreach ($SDC_matches as $match)
            {
                $match = preg_replace( "/\r|\n/", "", $match );
                if ( preg_match("/\bSDC number of errors: \b(\d*).*/i", $match, $founds) )
                {
                    array_push($error_count,$founds[1]);
                    array_push($normal_SDC_errors,$match);
                }
                else if ( preg_match("/\bSDC number of severe errors: \b(\d*).*/i", $match, $founds_severe) )
                {
                    array_push($error_count_severe,$founds_severe[1]);
                    array_push($severe_SDC_errors,$match);
                    $match_severe=$match;
                }
            }
            if ( array_sum($error_count_severe) > 0 )
            {
                $all_error_count = array_sum($error_count)."+".array_sum($error_count_severe);
            }
            else
            {
                $all_error_count = array_sum($error_count);
            }
            if ( array_sum($error_count) > 0 || array_sum($error_count_severe) > 0 )
            {
                if ( array_sum($error_count) > 200 || array_sum($error_count_severe) > 0)
                {
                    array_push($summary,"MS#".$msno." "."<WARNING> ".str_ireplace("<counter>",$all_error_count,$L["ERRORS.ERR_0025_SD_CARD_ERRORS_DETECTED"])." ".$L["ERRORS.ERR_0027_LAST_SD_CARD_ERROR_DETECTED"]." ".substr($match,0,strpos($match,' ')));
                    array_push($summary,"MS#".$msno." "."<INFO> ".$L["MINISERVERBACKUP.INF_0104_SUMMARY_SD_ERRORS"]."</span>\n"."MS#".$msno." ".join("\n"."MS#".$msno." ",$normal_SDC_errors));
                }
                else
                {
                    array_push($summary,"MS#".$msno." "."<INFO> ".str_ireplace("<counter>",$all_error_count,$L["ERRORS.ERR_0025_SD_CARD_ERRORS_DETECTED"])." ".$L["ERRORS.ERR_0027_LAST_SD_CARD_ERROR_DETECTED"]." ".substr($match,0,strpos($match,' ')));
                    array_push($summary,"MS#".$msno." "."<INFO> ".$L["MINISERVERBACKUP.INF_0104_SUMMARY_SD_ERRORS"]."</span>\n"."MS#".$msno." ".join("\n"."MS#".$msno." ",$normal_SDC_errors));
                }
            }

            if ( array_sum($error_count_severe) > 0 )
            {
                array_push($summary,"MS#".$msno." "."<CRITICAL> ".str_ireplace("<counter>",array_sum($error_count_severe),$L["ERRORS.ERR_0026_SEVERE_SD_CARD_ERRORS_DETECTED"])." ".$L["ERRORS.ERR_0027_LAST_SD_CARD_ERROR_DETECTED"]." ".substr($match_severe,0,strpos($match_severe,' ')));
                array_push($summary,"MS#".$msno." "."<CRITICAL> ".$L["MINISERVERBACKUP.INF_0127_SUMMARY_SEVERE_SD_ERRORS"]."</span>\n"."MS#".$msno." ".join("\n"."MS#".$msno." ",$severe_SDC_errors));
                array_push($summary,"MS#".$msno." "."<ALERT> ".$L["MINISERVERBACKUP.INF_0063_SHOULD_REPLACE_SDCARD"]." (".$miniserver['Name'].")");
                if ( $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "on"  || $plugin_cfg["MSBACKUP_USE_NOTIFY"] == "1"  ) notify ( LBPPLUGINDIR, $L['GENERAL.MY_NAME']." "."MS#".$msno." ".$miniserver['Name'], $L["MINISERVERBACKUP.INF_0063_SHOULD_REPLACE_SDCARD"]. " (" . $miniserver['Name'] .") ".$L["MINISERVERBACKUP.INF_0062_LAST_MS_REBOOT"]." ".$last_reboot_key);
            }
        }

        if ( array_sum($error_count_severe) > 0  ||  array_sum($error_count) > 200 )
        {
            $url = $prefix.$miniserver['IPAddress'].":".$port."/dev/sys/sdtest";
            debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0105_PERFORM_SD_TEST"],6);
            $curl_save = curl_init(str_replace(" ","%20",$url));
            curl_setopt($curl_save, CURLOPT_USERPWD                , $miniserver['Credentials_RAW']);
            curl_setopt($curl_save, CURLOPT_NOPROGRESS            , 1);
            curl_setopt($curl_save, CURLOPT_FOLLOWLOCATION        , 0);
            curl_setopt($curl_save, CURLOPT_CONNECTTIMEOUT        , 60);
            curl_setopt($curl_save, CURLOPT_TIMEOUT                , 60);
            curl_setopt($curl_save, CURLOPT_RETURNTRANSFER        , true);
            curl_setopt($curl_save, CURLOPT_SSL_VERIFYPEER        , 0);
            curl_setopt($curl_save, CURLOPT_SSL_VERIFYSTATUS    , 0);
            curl_setopt($curl_save, CURLOPT_SSL_VERIFYHOST        , 0);

            $output_sd_test = curl_exec($curl_save);
            curl_close($curl_save);
            $search  = array('<?xml version="1.0" encoding="utf-8"?>',"\n","\r",'<LL control="dev/sys/sdtest" value="', '/>','" Code="200"');
            $replace = array('','','','','','');
            $test_result = str_replace($search, $replace, $output_sd_test);
            $pos = strpos($test_result, ",");
            array_push($summary,"MS#".$msno." "."<INFO> ".$L["MINISERVERBACKUP.INF_0106_RESULT_SD_TEST"]." ".substr($test_result,0,$pos));
            array_push($summary,"MS#".$msno." "."<INFO> ".substr($test_result,$pos+2));
        }
        return;
    }
}

function roundToPrevMin(\DateTime $dt, $precision = 5)
{
    $s = $precision * 60;
    $dt->setTimestamp($s * floor($dt->getTimestamp()/$s));
    return $dt;
}

function getDirContents($path)
{
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    $files = array();
    foreach ($rii as $file)
        if (!$file->isDir())
            $files[] = $file->getPathname();
    return $files;
}

function secondsToTime($seconds)
{
    global $L;
    $dtF = new \DateTime('@0');
    $dtT = new \DateTime("@$seconds");
    if ($seconds > 86400) {
        return $dtF->diff($dtT)->format('%a '.$L["MINISERVERBACKUP.INF_0054_DAYS"].' %h:%i:%s '.$L["MINISERVERBACKUP.INF_0055_HOURS"]);
    }
    if ($seconds > 3600) {
        return $dtF->diff($dtT)->format('%h:%I:%S '.$L["MINISERVERBACKUP.INF_0055_HOURS"]);
    }
    if ($seconds > 60) {
        return $dtF->diff($dtT)->format('%i:%S '.$L["MINISERVERBACKUP.INF_0056_MINUTES"]);
    }
    return $dtF->diff($dtT)->format('%s '.$L["MINISERVERBACKUP.INF_0057_SECONDS"]);
}

// Return false for any incomplete subtree; ignored paths and explicitly empty
// directories are successful. The caller keeps the old cache on failure.
function read_ms_tree ($folder)
{
    global $L, $curl, $miniserver, $filetree, $msno, $prefix, $port;
    if (substr($folder, -3) == "/./" || substr($folder, -4) == "/../"
        || strpos($folder, "/temp/") === 0 || strpos($folder, "/sys/internal/") === 0)
    {
        return true;
    }
    $url = $prefix.$miniserver['IPAddress'].":".$port."/dev/fslist".$folder;
    curl_setopt($curl, CURLOPT_URL, str_replace(" ", "%20", $url));
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false || $status != 200 || trim($response) === '')
    {
        debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0005_CURL_GET_CONTENT_FAILED"]." ".$folder." HTTP ".$status." ".curl_error($curl), 3);
        return false;
    }
    $response = trim($response);
    if (strcasecmp(rtrim($response, '.'), 'Directory empty') === 0) return true;
    foreach (explode("\n", $response) as $line)
    {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^d\s*\d*\s[a-zA-Z]{3}\s\d{1,2}\s\d{1,2}:\d{1,2}\s(.*)$/', $line, $entry))
        {
            $name = trim($entry[1]);
            if ($name === '.' || $name === '..') continue;
            if ($name === '' || strpos($name, '/') !== false || strpos($name, "\0") !== false)
            {
                debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0006_UNABLE_TO_EXTRACT_NAME"]." ".$line, 3);
                return false;
            }
            if (!read_ms_tree($folder.$name.'/')) return false;
        }
        elseif (preg_match('/^-\s*(\d+)\s([a-zA-Z]{3})\s(\d{1,2})\s(\d{1,2}:\d{1,2})\s(.*)$/', $line, $entry))
        {
            $name = trim($entry[5]);
            if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, "\0") !== false)
            {
                debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0006_UNABLE_TO_EXTRACT_NAME"]." ".$line, 3);
                return false;
            }
            if (($folder == '/sys/' && (preg_match('/^sys_.*\.zip/i', $name) || $name == 'tokens.xml'))
                || ($folder == '/update/' && preg_match('/^.*\.upd/i', $name))) continue;
            // Listings omit the year. Select the most recent valid past date,
            // including a leap day, without normalizing impossible dates.
            $timestamp = false;
            $current_year = (int)date('Y');
            for ($year = $current_year; $year >= $current_year - 4; $year--)
            {
                $date = DateTime::createFromFormat('!Y M j H:i', $year.' '.$entry[2].' '.$entry[3].' '.$entry[4]);
                $date_errors = DateTime::getLastErrors();
                if ($date !== false && (!$date_errors || (!$date_errors['warning_count'] && !$date_errors['error_count']))
                    && $date->getTimestamp() <= time())
                {
                    $timestamp = $date->getTimestamp();
                    break;
                }
            }
            if ($timestamp === false)
            {
                debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0006_UNABLE_TO_EXTRACT_NAME"]." ".$line, 3);
                return false;
            }
            // Keep the existing exclusion of zero-length entries.
            if ((int)$entry[1] == 0) continue;
            $filetree['name'][] = $folder.$name;
            $filetree['size'][] = (int)$entry[1];
            $filetree['time'][] = $timestamp;
        }
        else
        {
            debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0006_UNABLE_TO_EXTRACT_NAME"]." ".$line, 3);
            return false;
        }
    }
    return true;
}

// Each attempt opens a fresh output stream. Never append a retry to a partial
// response or accept a 200 response containing the Miniserver's error page.
function download_ms_file($file, $index)
{
    global $L, $curl, $filetree, $miniserver, $msno, $prefix, $port, $workdir_tmp, $bkpfolder;
    $target = $workdir_tmp.'/'.$bkpfolder.$file;
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0777, true)) return false;
    $url = str_replace(' ', '%20', $prefix.$miniserver['IPAddress'].':'.$port.'/dev/fsget'.$file);
    for ($attempt = 0; $attempt < 2; $attempt++)
    {
        $stream = @fopen($target, 'wb');
        if ($stream === false) break;
        $handle = curl_init($url);
        if ($handle === false)
        {
            fclose($stream);
            break;
        }
        curl_setopt_array($handle, array(
            CURLOPT_USERPWD => $miniserver['Credentials_RAW'],
            CURLOPT_HTTPAUTH => CURLAUTH_ANY,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_CONNECTTIMEOUT => 600,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYSTATUS => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FILE => $stream
        ));
        $transferred = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $flushed = fflush($stream);
        $closed = fclose($stream);
        curl_close($handle);
        clearstatcache(true, $target);
        $size = is_file($target) ? filesize($target) : false;
        $error_body = $size !== false
            && stripos((string)file_get_contents($target, false, null, 0, 255), '<html><head><title>error</title></head><body>') !== false;
        if ($transferred !== false && $status == 200 && $flushed && $closed
            && !$error_body && $size !== false && $size == $filetree['size'][$index])
        {
            if (!touch($target, $filetree['time'][$index])) break;
            return true;
        }
        // A file may change between listing and download. Refresh its listed
        // size once, then download it afresh; persistent differences abort.
        if ($attempt == 0 && $transferred !== false && $status == 200 && !$error_body)
        {
            $listing_url = $prefix.$miniserver['IPAddress'].':'.$port.'/dev/fslist'.rtrim(dirname($file), '/').'/';
            curl_setopt($curl, CURLOPT_URL, str_replace(' ', '%20', $listing_url));
            $listing = curl_exec($curl);
            if ($listing === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) != 200) break;
            $found = false;
            foreach (explode("\n", $listing) as $line)
            {
                if (preg_match('/^-\s*(\d+)\s[a-zA-Z]{3}\s\d{1,2}\s\d{1,2}:\d{1,2}\s(.*)$/', trim($line), $entry)
                    && trim($entry[2]) === basename($file))
                {
                    $filetree['size'][$index] = (int)$entry[1];
                    $found = true;
                    break;
                }
            }
            if (!$found) break;
        }
        usleep(50000);
    }
    debug(__line__, "MS#".$msno." ".$L["ERRORS.ERR_0075_INVALID_DOWNLOAD"]." ".$file, 3);
    return false;
}

function create_clean_workdir_tmp($workdir_tmp)
{
    global $L,$msno;
    debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0033_DEBUG_DIRECTORY_EXISTS"]." -> ".$workdir_tmp);
    if (is_dir($workdir_tmp))
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0036_DEBUG_YES"]." -> ".$L["MINISERVERBACKUP.INF_0034_DEBUG_DIRECTORY_DELETE"]." -> ".$workdir_tmp);
        rrmdir($workdir_tmp);
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0035_DEBUG_DIRECTORY_CREATE"]." -> ".$workdir_tmp);
        mkdir($workdir_tmp, 0777, true);
    }
    else
    {
        debug(__line__,"MS#".$msno." ".$L["MINISERVERBACKUP.INF_0037_DEBUG_NO"]." -> ".$L["MINISERVERBACKUP.INF_0035_DEBUG_DIRECTORY_CREATE"]." -> ".$workdir_tmp);
        mkdir($workdir_tmp, 0777, true);
    }
    return;
}

/**
* Recursively move files from one directory to another
*
* @param String $src – Source of files being moved
* @param String $dest – Destination of files being moved
*/
function prune_empty_cache_dirs($root)
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry)
    {
        if (!$entry->isDir() || $entry->isLink()) continue;
        $children = scandir($entry->getPathname());
        if ($children === false) return false;
        if (count($children) == 2 && !rmdir($entry->getPathname())) return false;
    }
    return true;
}

function rmove($src, $dest)
{
    if (!is_dir($src)) return false;
    if (!is_dir($dest) && !mkdir($dest, 0777, true)) return false;
    foreach (new DirectoryIterator($src) as $entry)
    {
        if ($entry->isDot()) continue;
        $source = $entry->getPathname();
        $target = $dest.'/'.$entry->getFilename();
        if ($entry->isDir())
        {
            if (!rmove($source, $target) || !rmdir($source)) return false;
        }
        elseif ($entry->isFile())
        {
            $modified = filemtime($source);
            if (!rename($source, $target) || !touch($target, $modified)) return false;
        }
        else return false;
    }
    return true;
}

function rrmdir($dir)
{
    global $L,$start,$backupstate_file,$msno;
    if (is_dir($dir))
    {
        if ( $msno != "" )
        {
                $msinfo = "MS#".$msno." ";
        }
        else
        {
                $msinfo    = "";
        }

        if (!is_writable($dir) )
        {
            debug(__line__,$msinfo.$L["ERRORS.ERR_0023_PERMISSON_PROBLEM"]." -> ".$dir,3);
            $runtime = microtime(true) - $start;
            sleep(3); // To prevent misdetection in createmsbackup.pl
            file_put_contents($backupstate_file, "-");
            $log->LOGTITLE($L["MINISERVERBACKUP.INF_0138_BACKUP_ABORTED_WITH_ERROR"]);
            LOGERR ($L["ERRORS.ERR_0000_EXIT"]." ".$runtime." s");
            LOGEND ("");
            exit(1);
        }
        $objects = scandir($dir);
        foreach ($objects as $object)
        {
            if ($object != "." && $object != "..")
            {
                if (filetype($dir."/".$object) == "dir")
                {
                    rrmdir($dir."/".$object);
                }
                else
                {
                    debug(__line__,$msinfo.$L["MINISERVERBACKUP.INF_0045_DEBUG_DELETE_FILE"]." -> ".$dir."/".$object);
                    unlink($dir."/".$object);
                }
            }
        }
        reset($objects);
        debug(__line__,$msinfo.$L["MINISERVERBACKUP.INF_0034_DEBUG_DIRECTORY_DELETE"]." -> ".$dir);
        rmdir($dir);
    }
}

function sort_by_mtime($file1,$file2)
{
    $time1 = filemtime($file1);
    $time2 = filemtime($file2);
    if ($time1 == $time2)
    {
        return 0;
    }
    return ($time1 > $time2) ? 1 : -1;
}

function get_free_space ( $path )
{
    $base=dirname($path);
    $free = @exec("if [ -d '".escapeshellcmd($path)."' ]; then df -k --output=avail '".escapeshellcmd($path)."' 2>/dev/null | grep -v Avail; fi");
    if ( $free == "" ) $free = @exec("if [ -d '".escapeshellcmd($base)."' ]; then df -k --output=avail '".escapeshellcmd($base)."' 2>/dev/null | grep -v Avail; fi");
    if ( $free == "" ) $free = "0";
    return $free*1024;
}

function cloud_requests_today($indata)
{
    if ( !isset($indata["date"]) ) return(false);
    if ( substr($indata["date"],0,8) == date("Ymd",time()) )
    {
        return($indata);
    }
    else
    {
        return(false);
    }
}
