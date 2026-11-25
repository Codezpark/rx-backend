<?php

declare(strict_types=1);

/**
 * "Erase all user data" for the admin panel: removes every clinic, doctor, staff account, patient and
 * everything they created, and keeps the reference data we ship (medicines, lab / imaging catalogues,
 * paediatric doses, advice and symptom lists) and the admin accounts.
 *
 * Every table must appear in exactly one list below. If a new table is added and nobody classifies it,
 * the wipe refuses to run instead of guessing.
 *
 * A backup of everything removed is written to storage/backups before anything is deleted.
 */
class DataWipeHelper
{
    /** Emptied completely. */
    public const WIPE = [
        'prescription_medicines', 'prescription_advice', 'prescription_symptoms', 'lab_order_tests', 'lab_orders',
        'radiology_orders', 'bill_items', 'bill_payments', 'bills', 'intake_vitals', 'appointments', 'prescriptions',
        'patient_chronic_conditions', 'patients', 'followup_actions', 'wa_notifications', 'scheduled_jobs',
        'lab_partner_contacts', 'lab_partners', 'clinic_contacts', 'clinic_timings', 'clinic_holidays', 'clinics',
        'staff_tokens', 'clinic_staff', 'device_tokens', 'auth_tokens', 'refresh_tokens', 'verification',
        'plan_reminders', 'audit_logs', 'doctors',
    ];

    /** Shared lists: only the rows a doctor added themselves (doctor_id set) are removed. */
    public const PRIVATE_ROWS = ['medicines', 'symptoms', 'advice_templates', 'chronic_conditions'];

    /** Never touched. `users` is the unused legacy login table from the original backend. */
    public const KEEP = [
        'admins', 'medicine_categories', 'lab_tests_catalog', 'radiology_catalog', 'pediatric_drugs',
        'pediatric_drug_aliases', 'pediatric_drug_formulations', 'pediatric_dose_reference', 'rate_limits',
        'users', 'migrations',
    ];

    /** Folders (relative to the project root) that hold uploaded files. */
    private const FILE_DIRS = ['storage/signatures', 'storage/logos', 'storage/reports', 'uploads/user'];

    /** Placeholder files that belong to the project, not to a user. */
    private const KEEP_FILES = ['.htaccess', '.gitkeep', 'index.html', 'index.php'];

    public function __construct(private Database $db) {}

    /** @return list<string> tables present in this database */
    private function tables(): array
    {
        $rows = $this->db->getRows("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
        return array_map(fn ($r) => (string) $r['t'], $rows);
    }

    /** @return list<string> tables nobody has classified yet */
    public function unknownTables(): array
    {
        return array_values(array_diff($this->tables(), self::WIPE, self::PRIVATE_ROWS, self::KEEP));
    }

    private function count(string $table, string $where = '1'): int
    {
        return (int) ($this->db->getRow("SELECT COUNT(*) AS n FROM `{$table}` WHERE {$where}")['n'] ?? 0);
    }

    /** What would be removed and what stays, for the confirmation screen. */
    public function summary(): array
    {
        $have = $this->tables();
        $n    = fn (string $t) => in_array($t, $have, true) ? $this->count($t) : 0;
        $total = 0;
        foreach (self::WIPE as $t) $total += $n($t);
        foreach (self::PRIVATE_ROWS as $t) if (in_array($t, $have, true)) $total += $this->count($t, 'doctor_id IS NOT NULL');

        return [
            'remove' => [
                'clinics' => $n('doctors'), 'staff' => $n('clinic_staff'), 'patients' => $n('patients'),
                'appointments' => $n('appointments'), 'prescriptions' => $n('prescriptions'), 'bills' => $n('bills'),
                'files' => count($this->userFiles()), 'rows' => $total,
            ],
            'keep' => [
                'medicines' => in_array('medicines', $have, true) ? $this->count('medicines', 'doctor_id IS NULL') : 0,
                'lab_tests' => $n('lab_tests_catalog'), 'admins' => $n('admins'),
            ],
            'unknown_tables' => $this->unknownTables(),
        ];
    }

    /** @return list<string> uploaded files, relative to the project root */
    private function userFiles(): array
    {
        $out = [];
        foreach (self::FILE_DIRS as $dir) {
            $base = ROOT_PATH . '/' . $dir;
            if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !in_array($f->getFilename(), self::KEEP_FILES, true)) {
                    $out[] = substr($f->getPathname(), strlen(ROOT_PATH) + 1);
                }
            }
        }
        return $out;
    }

    /** Write every row that is about to be removed to a gzip file (one JSON object per line). */
    private function backup(string $path): void
    {
        $gz = gzopen($path, 'wb9');
        if (!$gz) throw new RuntimeException('Could not create the backup file.');
        $have = $this->tables();
        foreach (array_merge(self::WIPE, self::PRIVATE_ROWS) as $t) {
            if (!in_array($t, $have, true)) continue;
            $where = in_array($t, self::PRIVATE_ROWS, true) ? 'doctor_id IS NOT NULL' : '1';
            $stmt  = $this->db->executeQuery("SELECT * FROM `{$t}` WHERE {$where}");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $line = json_encode(['table' => $t, 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                if (gzwrite($gz, $line) !== strlen($line)) throw new RuntimeException('Could not write the backup file.');
            }
        }
        if (!gzclose($gz)) throw new RuntimeException('Could not finish the backup file.');
    }

    /**
     * Back up, then delete. Nothing is deleted if the backup fails; the database part is one
     * transaction, so a failure half-way leaves the data as it was.
     *
     * @return array{rows:int, files:int, backup:string}
     */
    public function wipe(): array
    {
        if ($this->unknownTables()) throw new RuntimeException('Unclassified tables: ' . implode(', ', $this->unknownTables()));

        $dir = ROOT_PATH . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Could not create the backup folder.');
        if (!is_file("$dir/.htaccess") && file_put_contents("$dir/.htaccess", "Require all denied\n") === false) {
            throw new RuntimeException('Could not protect the backup folder.');
        }
        $name = 'wipe-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $this->backup("$dir/$name.jsonl.gz");

        $have = $this->tables();
        $rows = 0;
        $this->db->executeQuery('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->beginTransaction();
        try {
            foreach (self::PRIVATE_ROWS as $t) {
                if (in_array($t, $have, true)) $rows += $this->db->executeQuery("DELETE FROM `{$t}` WHERE doctor_id IS NOT NULL")->rowCount();
            }
            foreach (self::WIPE as $t) {
                if (in_array($t, $have, true)) $rows += $this->db->executeQuery("DELETE FROM `{$t}`")->rowCount();
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        } finally {
            $this->db->executeQuery('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Files move into the backup folder (same disk), so they are recoverable too.
        $moved = 0;
        foreach ($this->userFiles() as $rel) {
            $to = "$dir/$name-files/$rel";
            if ((is_dir(dirname($to)) || @mkdir(dirname($to), 0770, true)) && @rename(ROOT_PATH . '/' . $rel, $to)) $moved++;
        }

        return ['rows' => $rows, 'files' => $moved, 'backup' => "storage/backups/$name.jsonl.gz"];
    }
}
