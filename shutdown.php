<?php
// Load configuration, constants, and internationalization helper (Class L)
if (file_exists(__DIR__ . "/sub-common.php")) {
    include_once(__DIR__ . "/sub-common.php");
}
if (file_exists(__DIR__ . "/sub-i18n-loader.php")) {
    include_once(__DIR__ . "/sub-i18n-loader.php");
}

$action = "";
$message = "Processing request...";

if (isset($_POST["reboot"]) || isset($_GET["reboot"])) {
    $action = "reboot";
    $message = (class_exists("L") && isset(L::$main_reboot_m)) ? L::main_reboot_m : "Rebooting Little Backup Box...";
} elseif (isset($_POST["shutdown"]) || isset($_GET["shutdown"])) {
    $action = "poweroff";
    $message = (class_exists("L") && isset(L::$main_shutdown_m)) ? L::main_shutdown_m : "Shutting down Little Backup Box...";
}

if (file_exists(__DIR__ . "/sub-standards-header-loader.php")) {
    include_once(__DIR__ . "/sub-standards-header-loader.php");
}
if (file_exists(__DIR__ . "/sub-menu.php")) {
    include_once(__DIR__ . "/sub-menu.php");
}
?>

<div class="container" style="margin-top: 3em;">
    <div class="card text-center" style="padding: 2em; max-width: 500px; margin: 0 auto;">
        <h3><?php echo htmlspecialchars($message); ?></h3>
        <p style="margin-top: 1em;">You can safely close this window or disconnect power once the status LEDs turn off.</p>
    </div>
</div>

<?php 
if (file_exists(__DIR__ . "/sub-footer.php")) {
    include_once(__DIR__ . "/sub-footer.php");
}

// Flush output to browser before executing non-blocking system command
if (function_exists("fastcgi_finish_request")) {
    @fastcgi_finish_request();
} else {
    @ob_flush();
    @flush();
}

if ($action === "reboot") {
    exec("sudo python3 /var/www/little-backup-box/lib_poweroff.py reboot > /dev/null 2>&1 &");
} elseif ($action === "poweroff") {
    exec("sudo python3 /var/www/little-backup-box/lib_poweroff.py poweroff > /dev/null 2>&1 &");
}
?>
