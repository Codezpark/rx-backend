<?php
// php tests/php_callin_targets.php — which devices announce a call-in (no database needed).
require __DIR__ . '/../helpers/PushHelper.php';
$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n"; if (!$ok) $fail++; }

$doc  = ['token' => 'D1', 'owner_type' => 'doctor'];
$rec1 = ['token' => 'R1', 'owner_type' => 'staff'];
$rec2 = ['token' => 'R2', 'owner_type' => 'staff'];

check('receptionist devices announce, the doctor\'s do not', PushHelper::callInTokens([$doc, $rec1, $rec2]) === ['R1', 'R2']);
check('the device that pressed Call in is not excluded (receptionist calling from own phone)', in_array('R1', PushHelper::callInTokens([$doc, $rec1]), true));
check('a clinic with no receptionist device falls back to the doctor\'s devices', PushHelper::callInTokens([$doc]) === ['D1']);
check('no devices, no targets', PushHelper::callInTokens([]) === []);
check('duplicates collapse', PushHelper::callInTokens([$rec1, $rec1]) === ['R1']);
exit($fail ? 1 : 0);
