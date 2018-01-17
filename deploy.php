<?php
    require 'vendor/autoload.php';
    use Monolog\Logger; 
    use Monolog\Handler\StreamHandler;
    $log = new Logger('name');
    $log->pushHandler(new StreamHandler('/var/log/git-pull.log', Logger::WARNING));
    
    $log->info('log info test');

    require_once("config.php");
    
    $content = file_get_contents("php://input");
    $json    = json_decode($content, true);
    $time    = time();
    $token   = false;
    
    // retrieve the token
    if (!$token && isset($_SERVER["HTTP_X_HUB_SIGNATURE"])) {
        list($algo, $token) = explode("=", $_SERVER["HTTP_X_HUB_SIGNATURE"], 2) + array("", "");
    } elseif (isset($_SERVER["HTTP_X_GITLAB_TOKEN"])) {
        $token = $_SERVER["HTTP_X_GITLAB_TOKEN"];
    } elseif (isset($_GET["token"])) {
        $token = $_GET["token"];
    }
    
    // log the time
    date_default_timezone_set("UTC");
    $log->info(date("d-m-Y (H:i:s)", $time));
    
    // function to forbid access
    function forbid($log, $reason) {
        // explain why
        if ($reason) $log->info("=== ERROR: " . $reason . " ===");
        $log->info("*** ACCESS DENIED ***");
    
        // forbid
        header("HTTP/1.0 403 Forbidden");
        exit;
    }
    
    // function to return OK
    function ok() {
        ob_start();
        header("HTTP/1.1 200 OK");
        header("Connection: close");
        header("Content-Length: " . ob_get_length());
        ob_end_flush();
        ob_flush();
        flush();
    }
    
    // Check for a GitHub signature
    if (!empty(TOKEN) && isset($_SERVER["HTTP_X_HUB_SIGNATURE"]) && $token !== hash_hmac($algo, $content, TOKEN)) {
        forbid($log, "X-Hub-Signature does not match TOKEN");
    // Check for a GitLab token
    } elseif (!empty(TOKEN) && isset($_SERVER["HTTP_X_GITLAB_TOKEN"]) && $token !== TOKEN) {
        forbid($log, "X-GitLab-Token does not match TOKEN");
    // Check for a $_GET token
    } elseif (!empty(TOKEN) && isset($_GET["token"]) && $token !== TOKEN) {
        forbid($log, "\$_GET[\"token\"] does not match TOKEN");
    // if none of the above match, but a token exists, exit
    } elseif (!empty(TOKEN) && !isset($_SERVER["HTTP_X_HUB_SIGNATURE"]) && !isset($_SERVER["HTTP_X_GITLAB_TOKEN"]) && !isset($_GET["token"])) {
        forbid($log, "No token detected");
    } else {
        // check if pushed branch matches branch specified in config
        if ($json["ref"] === BRANCH) {
            $log->info($content . PHP_EOL);
    
            // ensure directory is a repository
            if (file_exists(DIR . ".git") && is_dir(DIR)) {
                try {
                    // pull
                    chdir(DIR);
                    shell_exec(GIT . " pull");
    
                    // return OK to prevent timeouts on AFTER_PULL
                    ok();
    
                    // execute AFTER_PULL if specified
                    if (!empty(AFTER_PULL)) {
                        try {
                            shell_exec(AFTER_PULL);
                        } catch (Exception $e) {
                            $log->info($e);
                        }
                    }
    
                    $log->info("*** AUTO PULL SUCCESFUL ***");
                } catch (Exception $e) {
                    $log->info($e);
                }
            } else {
                $log->info("=== ERROR: DIR is not a repository ===");
            }
        } else{
            $log->info("=== ERROR: Pushed branch does not match BRANCH ===");
        }
    }
?>