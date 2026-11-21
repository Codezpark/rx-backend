<?php
/**
 * Daily job: push trial-ending reminders (14 days, 3 days, ended). Add to cron once a day, e.g.
 *   0 9 * * * cd /home/USER/domains/prescriptionrx.in/public_html/api && php tools/trial_reminders.php >> logs/reminders.log 2>&1
 * Use --dry to list who would be reminded without sending or recording anything.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }

define('ROOT_PATH', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    foreach (['core', 'helpers', 'app/controllers', 'config'] as $dir) {
        $f = ROOT_PATH . "/$dir/$class.php";
        if (is_file($f)) { require_once $f; return; }
    }
});
require_once ROOT_PATH . '/config/Config.php';
date_default_timezone_set(Config::get('app.timezone', 'UTC'));

$dry  = in_array('--dry', $argv, true);
$done = TrialReminderHelper::run($dry);
echo date('c') . ($dry ? ' [dry] ' : ' ') . count($done) . " reminder(s)\n";
foreach ($done as $d) {
    echo "  doctor {$d['doctor_id']}: {$d['stage']} ({$d['days_left']} days left), pushed to {$d['pushed']} device(s)\n";
}
