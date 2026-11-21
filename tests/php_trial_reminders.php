<?php
// php tests/php_trial_reminders.php — trial-ending reminders: stages, once each, language, no late back-fill. Needs the local DB.
define('ROOT_PATH', dirname(__DIR__));
spl_autoload_register(function (string $c): void { foreach (['core', 'helpers', 'app/controllers', 'config'] as $d) { $f = ROOT_PATH . "/$d/$c.php"; if (is_file($f)) { require_once $f; return; } } });
require_once ROOT_PATH . '/config/Config.php';
date_default_timezone_set('UTC');
$fail = 0;
function check(string $name, bool $ok, $extra = ''): void { global $fail; echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok ? '' : '  -> ' . json_encode($extra)) . "\n"; if (!$ok) $fail++; }

check('stages by days left', TrialReminderHelper::stageFor(40) === null && TrialReminderHelper::stageFor(15) === null && TrialReminderHelper::stageFor(14) === 'd14'
    && TrialReminderHelper::stageFor(4) === 'd14' && TrialReminderHelper::stageFor(3) === 'd3' && TrialReminderHelper::stageFor(1) === 'd3' && TrialReminderHelper::stageFor(0) === 'ended' && TrialReminderHelper::stageFor(-5) === 'ended');

[$t, $b] = TrialReminderHelper::message('d3', 3, 'en');
check('English text', $t === 'Your free trial ends in 3 days.' && str_contains($b, 'Contact Prescription Rx'), [$t, $b]);
[$t1] = TrialReminderHelper::message('d3', 1, 'en');
check('singular', $t1 === 'Your free trial ends in 1 day.', $t1);
[$th] = TrialReminderHelper::message('d14', 10, 'hi');
check('Hindi text differs from English and keeps the number', $th !== 'Your free trial ends in 10 days.' && str_contains($th, '10'), $th);
[$te] = TrialReminderHelper::message('ended', 0, 'ar');
check('Arabic ended text is translated', $te !== 'Your free trial has ended.', $te);

$db = Database::getInstance();
function mk(string $name, string $when, string $lang = 'en', bool $device = true): int {
    global $db;
    $id = $db->insert('doctors', ['name' => $name, 'mobile' => '+9199' . random_int(10000000, 99999999), 'password_hash' => 'x', 'plan' => 'trial', 'is_active' => 1,
        'plan_started_at' => gmdate('Y-m-d H:i:s'), 'plan_expires_at' => gmdate('Y-m-d H:i:s', strtotime($when))]);
    $db->insert('clinics', ['doctor_id' => $id, 'name' => 'T', 'is_primary' => 1, 'language' => $lang]);
    if ($device) $db->insert('device_tokens', ['owner_type' => 'doctor', 'owner_id' => $id, 'doctor_id' => $id, 'token' => 'tok-' . $id, 'platform' => 'android']);
    return $id;
}
function cleanup(array $ids): void { global $db; foreach ($ids as $i) { $db->delete('plan_reminders', 'doctor_id = :i', [':i' => $i]); $db->delete('device_tokens', 'doctor_id = :i', [':i' => $i]); $db->delete('clinics', 'doctor_id = :i', [':i' => $i]); $db->delete('doctors', 'id = :i', [':i' => $i]); } }

$a = mk('RemTest A', '+10 days 3 hours', 'hi');
$b2 = mk('RemTest B', '+40 days');
$c = mk('RemTest C', '+2 days 3 hours');
$d = mk('RemTest D', '-1 day', 'en', false);
$ids = [$a, $b2, $c, $d];
$sent = [];
$fake = function (array $tokens, string $title, string $body, array $data) use (&$sent): int { $sent[] = compact('tokens', 'title', 'body', 'data'); return count($tokens); };
try {
    $r = array_filter(TrialReminderHelper::run(true, $fake), fn ($x) => in_array($x['doctor_id'], $ids, true));
    check('dry run lists but sends and records nothing', count($r) === 3 && !$sent && !$db->getRow('SELECT 1 AS x FROM plan_reminders WHERE doctor_id = :i', [':i' => $a]));

    $r = array_values(array_filter(TrialReminderHelper::run(false, $fake), fn ($x) => in_array($x['doctor_id'], $ids, true)));
    $by = array_column($r, 'stage', 'doctor_id');
    check('A gets the 14-day stage, C the 3-day stage, D "ended", B (40 days left) nothing', ($by[$a] ?? '') === 'd14' && ($by[$c] ?? '') === 'd3' && ($by[$d] ?? '') === 'ended' && !isset($by[$b2]), $by);
    $mine = array_values(array_filter($sent, fn ($s) => in_array($s['tokens'][0], ['tok-' . $a, 'tok-' . $c], true)));
    check('pushes went to the doctors\' devices with the stage in data', count($mine) === 2 && $mine[0]['data']['type'] === 'trial_reminder', $mine);
    check('A\'s push is in Hindi', in_array('tok-' . $a, array_column(array_column($mine, 'tokens'), 0), true) && array_values(array_filter($mine, fn ($s) => $s['tokens'][0] === 'tok-' . $a))[0]['title'] !== 'Your free trial ends in 10 days.');
    check('D (no device) is still recorded, pushed 0', (int) $db->getRow("SELECT pushed AS n FROM plan_reminders WHERE doctor_id = :i AND kind = 'ended'", [':i' => $d])['n'] === 0);
    check('C\'s earlier stage is recorded so it is never sent late', (bool) $db->getRow("SELECT 1 AS x FROM plan_reminders WHERE doctor_id = :i AND kind = 'd14'", [':i' => $c]));

    $sent = [];
    $r = array_filter(TrialReminderHelper::run(false, $fake), fn ($x) => in_array($x['doctor_id'], $ids, true));
    check('running again sends nothing', !$r && !$sent, $r);

    $db->update('doctors', ['plan_expires_at' => gmdate('Y-m-d H:i:s', strtotime('+2 days'))], 'id = :i', [':i' => $a]);
    $r = array_values(array_filter(TrialReminderHelper::run(false, $fake), fn ($x) => in_array($x['doctor_id'], $ids, true)));
    check('A moves on to the 3-day stage later', count($r) === 1 && $r[0]['stage'] === 'd3' && $r[0]['doctor_id'] === $a, $r);

    $db->update('doctors', ['plan' => 'pro', 'plan_expires_at' => gmdate('Y-m-d H:i:s', strtotime('+1 day'))], 'id = :i', [':i' => $b2]);
    $r = array_filter(TrialReminderHelper::run(false, $fake), fn ($x) => $x['doctor_id'] === $b2);
    check('a paid plan is not sent trial reminders', !$r);
} finally {
    cleanup($ids);
}
exit($fail ? 1 : 0);
