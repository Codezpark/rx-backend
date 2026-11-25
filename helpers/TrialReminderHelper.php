<?php

declare(strict_types=1);

/**
 * Trial-ending reminders by push: once at about 14 days left, once at 3 days, once when it has ended.
 * Run daily by tools/trial_reminders.php. Each stage is sent at most once per doctor (plan_reminders).
 * A stage that was already passed when the job first sees a doctor is skipped, not back-filled.
 * The text is in the clinic's language.
 */
class TrialReminderHelper
{
    public const STAGES = ['d14' => 14, 'd3' => 3, 'ended' => 0];

    /** Which stage a trial with $daysLeft days left is in (null = none yet). */
    public static function stageFor(int $daysLeft): ?string
    {
        if ($daysLeft <= 0) return 'ended';
        if ($daysLeft <= 3) return 'd3';
        if ($daysLeft <= 14) return 'd14';
        return null;
    }

    /** [title, body] in $lang. */
    public static function message(string $stage, int $daysLeft, string $lang): array
    {
        Lang::use($lang);
        if ($stage === 'ended') {
            return [Lang::t('Your free trial has ended.'), Lang::t('Contact Prescription Rx to continue. Your records stay available to view and export.')];
        }
        return [
            Lang::n('Your free trial ends in {n} day.', 'Your free trial ends in {n} days.', $daysLeft),
            Lang::t('Contact Prescription Rx to keep full access after that.'),
        ];
    }

    /**
     * @param callable|null $send  fn(array $tokens, string $title, string $body, array $data): int  (default: FCM)
     * @return array<int, array{doctor_id:int, stage:string, days_left:int, pushed:int}>
     */
    public static function run(bool $dry = false, ?callable $send = null): array
    {
        $db   = Database::getInstance();
        $send ??= static fn (array $t, string $title, string $body, array $d): int => PushHelper::sendToTokens($t, $title, $body, $d, null);
        $rows = $db->getRows(
            "SELECT d.id, d.plan_expires_at,
                    COALESCE((SELECT c.language FROM clinics c WHERE c.doctor_id = d.id AND c.deleted_at IS NULL ORDER BY c.is_primary DESC, c.id LIMIT 1), 'en') AS lang
             FROM doctors d
             WHERE d.deleted_at IS NULL AND d.is_active = 1 AND d.clinic_owner_id IS NULL AND d.plan = 'trial' AND d.plan_expires_at IS NOT NULL
               AND d.plan_expires_at < NOW() + INTERVAL 15 DAY AND d.plan_expires_at > NOW() - INTERVAL 30 DAY"
        );
        $out = [];
        foreach ($rows as $r) {
            $left  = (int) ceil((strtotime((string) $r['plan_expires_at']) - time()) / 86400);
            $stage = self::stageFor($left);
            if ($stage === null) continue;
            $id = (int) $r['id'];
            if ($db->getRow('SELECT 1 AS x FROM plan_reminders WHERE doctor_id = :d AND kind = :k', [':d' => $id, ':k' => $stage])) continue;

            $pushed = 0;
            if (!$dry) {
                $tokens = array_column($db->getRows("SELECT token FROM device_tokens WHERE owner_type = 'doctor' AND owner_id = :d", [':d' => $id]), 'token');
                if ($tokens) {
                    [$title, $body] = self::message($stage, max(0, $left), (string) $r['lang']);
                    try { $pushed = (int) $send($tokens, $title, $body, ['type' => 'trial_reminder', 'stage' => $stage]); } catch (Throwable $e) { $pushed = 0; }
                }
                // Record this stage and every earlier one, so a stage that was skipped is never sent late.
                $order = array_keys(self::STAGES);
                foreach (array_slice($order, 0, array_search($stage, $order, true) + 1) as $k) {
                    $db->executeQuery('INSERT IGNORE INTO plan_reminders (doctor_id, kind, pushed) VALUES (:d, :k, :p)', [':d' => $id, ':k' => $k, ':p' => $k === $stage ? $pushed : 0]);
                }
            }
            $out[] = ['doctor_id' => $id, 'stage' => $stage, 'days_left' => $left, 'pushed' => $pushed];
        }
        return $out;
    }
}
