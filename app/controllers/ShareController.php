<?php

declare(strict_types=1);

/**
 * Sending a document to a patient as a link.
 *
 * The app uploads the PDF it built, and gets back an address holding a long random code. Opening it
 * needs no account, only the last 4 digits of the patient's mobile number. A link stops working after
 * 90 days, when its prescription / order / bill is cancelled, when lab results are changed after
 * sending, or after too many wrong digits.
 */
class ShareController extends BaseController
{
    public const KINDS = ['prescription', 'lab_report', 'test_request', 'receipt'];
    public const DAYS = 90;
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const TRIES_PER_LOCK = 5;      // wrong digits before a pause
    private const LOCK_MINUTES = 15;
    private const MAX_FAILURES = 20;       // then the link is switched off for good

    // ── POST /documents/share  (multipart: kind, ref_id, file) ───────────────
    public function create(): void
    {
        $kind = (string) $this->request->input('kind', '');
        $ref  = (int) $this->request->input('ref_id', 0);
        if (!in_array($kind, self::KINDS, true) || $ref <= 0) Response::validationError(['kind' => ['Unknown document.']]);

        // Reception handles receipts; clinical documents are sent by a doctor.
        $clinic = $kind === 'receipt' ? $this->requireClinic() : $this->requireDoctor();
        $tenant = (int) $clinic['id'];

        $source = $this->source($kind, $ref, $tenant, $this->ownScope());
        if (!$source) Response::notFound('This document cannot be sent.');

        $digits = preg_replace('/\D/', '', (string) $source['mobile']);
        if (strlen($digits) < 7) Response::validationError(['mobile' => ['This patient has no mobile number']], 'This patient has no mobile number');

        $path = $this->storeFile($tenant);
        $old  = $this->db->getRow('SELECT * FROM shared_documents WHERE doctor_id = :d AND kind = :k AND ref_id = :r', [':d' => $tenant, ':k' => $kind, ':r' => $ref]);
        $keepLink = $old && $old['revoked_at'] === null;
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) ($this->request->file('file')['name'] ?? 'document.pdf')));
        $row = [
            'shared_by'       => $this->actorId,
            'patient_id'      => (int) $source['patient_id'],
            'token'           => $keepLink ? $old['token'] : self::newToken(),
            'file_path'       => $path,
            'file_name'       => substr($name === '' ? 'document.pdf' : $name, -150),
            'phone_last4'     => substr($digits, -4),
            'source_hash'     => $source['hash'],
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + self::DAYS * 86400),
            'revoked_at'      => null,
            'failed_attempts' => 0,
            'locked_until'    => null,
        ];
        if ($old) {
            $this->db->update('shared_documents', $row, 'id = :i', [':i' => $old['id']]);
            $this->removeFile($old['file_path']);
        } else {
            $this->db->insert('shared_documents', ['doctor_id' => $tenant, 'kind' => $kind, 'ref_id' => $ref] + $row);
        }
        $this->purge($tenant);

        Response::created(['url' => Config::get('app.share_url') . '#' . $row['token'], 'expires_at' => $row['expires_at']], 'Link ready');
    }

    // ── POST /shared/meta  {token} ───────────────────────────────────────────
    /** What the page shows before the digits are entered: the clinic's name, never the patient's. */
    public function meta(): void
    {
        [$share, $source] = $this->live();
        $clinic = $this->db->getRow('SELECT name, language FROM clinics WHERE doctor_id = :d ORDER BY id LIMIT 1', [':d' => $share['doctor_id']]);
        $owner  = $this->db->getRow('SELECT name FROM doctors WHERE id = :d', [':d' => $share['doctor_id']]);
        header('Cache-Control: no-store');
        Response::success([
            'kind'       => $share['kind'],
            'clinic'     => ($clinic['name'] ?? '') !== '' ? $clinic['name'] : ($owner['name'] ?? ''),
            'language'   => $clinic['language'] ?? 'en',
            'expires_at' => $share['expires_at'],
        ], 'Document fetched');
    }

    // ── POST /shared/file  {token, pin} ──────────────────────────────────────
    public function file(): void
    {
        [$share] = $this->live();
        $pin = preg_replace('/\D/', '', (string) $this->request->input('pin', ''));
        if (strlen($pin) !== 4) Response::validationError(['pin' => ['Enter the last 4 digits of your mobile number.']], 'Enter the last 4 digits of your mobile number.');

        // The row is locked while a try is judged, so parallel guesses cannot slip past the limit.
        $this->db->beginTransaction();
        $row = $this->db->getRow('SELECT * FROM shared_documents WHERE id = :i FOR UPDATE', [':i' => $share['id']]);
        $now = gmdate('Y-m-d H:i:s');
        if (!$row || $row['revoked_at'] !== null) {
            $this->db->rollback();
            self::gone();
        }
        if ($row['locked_until'] !== null && $row['locked_until'] > $now) {
            $this->db->rollback();
            Response::tooManyRequests('Too many tries. Please wait 15 minutes.');
        }
        if (!hash_equals($row['phone_last4'], $pin)) {
            $failures = (int) $row['failed_attempts'] + 1;
            $this->db->update('shared_documents', [
                'failed_attempts' => $failures,
                'locked_until'    => $failures % self::TRIES_PER_LOCK === 0 ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null,
                'revoked_at'      => $failures >= self::MAX_FAILURES ? $now : null,
            ], 'id = :i', [':i' => $row['id']]);
            $this->db->commit();
            if ($failures >= self::MAX_FAILURES) self::gone();
            if ($failures % self::TRIES_PER_LOCK === 0) Response::tooManyRequests('Too many tries. Please wait 15 minutes.');
            Response::forbidden('Those digits do not match the mobile number on this document.');
        }
        $this->db->update('shared_documents', ['failed_attempts' => 0, 'locked_until' => null, 'views' => (int) $row['views'] + 1, 'last_viewed_at' => $now], 'id = :i', [':i' => $row['id']]);
        $this->db->commit();

        $full = $this->fullPath($row['file_path']);
        if (!$full) self::gone();
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: inline; filename="' . $row['file_name'] . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        header('Access-Control-Expose-Headers: Content-Disposition');
        readfile($full);
        exit;
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private static function gone(): never
    {
        Response::notFound('This link is no longer available. Please ask your clinic to send it again.');
    }

    /** The share behind the posted token, only while it may still be opened. */
    private function live(): array
    {
        $token = (string) $this->request->input('token', '');
        if (!preg_match('/^[A-Za-z0-9_-]{22}$/', $token)) self::gone();
        $share = $this->db->getRow(
            'SELECT s.* FROM shared_documents s JOIN doctors d ON d.id = s.doctor_id AND d.deleted_at IS NULL AND d.is_active = 1
             WHERE s.token = :t AND s.revoked_at IS NULL AND s.expires_at > :now',
            [':t' => $token, ':now' => gmdate('Y-m-d H:i:s')]
        );
        if (!$share) self::gone();
        $source = $this->source($share['kind'], (int) $share['ref_id'], (int) $share['doctor_id'], null);
        if (!$source || $source['hash'] !== $share['source_hash']) self::gone();
        return [$share, $source];
    }

    /**
     * The record a document was made from, if it may be sent / shown: patient, mobile, and for lab
     * reports a fingerprint of the results so a corrected result switches the old copy off.
     * $own limits a second doctor's receipts to their own bills.
     */
    private function source(string $kind, int $ref, int $tenant, ?int $own): ?array
    {
        $patient = 'JOIN patients pt ON pt.id = x.patient_id AND pt.deleted_at IS NULL';
        $args = [':i' => $ref, ':d' => $tenant];
        switch ($kind) {
            case 'prescription':
            case 'test_request':
                $sql = "SELECT x.patient_id, pt.mobile FROM prescriptions x $patient WHERE x.id = :i AND x.doctor_id = :d AND x.status = 'final'";
                if ($kind === 'test_request') {
                    $sql .= " AND (EXISTS (SELECT 1 FROM lab_orders l WHERE l.prescription_id = x.id AND l.status <> 'cancelled' AND l.deleted_at IS NULL)
                              OR EXISTS (SELECT 1 FROM radiology_orders r WHERE r.prescription_id = x.id AND r.status <> 'cancelled' AND r.deleted_at IS NULL))";
                }
                break;
            case 'lab_report':
                $sql = "SELECT x.patient_id, pt.mobile, x.result_summary FROM lab_orders x $patient
                        JOIN prescriptions p ON p.id = x.prescription_id AND p.status = 'final'
                        WHERE x.id = :i AND x.doctor_id = :d AND x.status <> 'cancelled' AND x.deleted_at IS NULL";
                break;
            case 'receipt':
                $sql = "SELECT x.patient_id, pt.mobile FROM bills x $patient WHERE x.id = :i AND x.doctor_id = :d AND x.status <> 'cancelled'" . self::byDoctor('x.consultant_id', $own);
                break;
            default:
                return null;
        }
        $row = $this->db->getRow($sql, $args);
        if (!$row) return null;
        $hash = null;
        if ($kind === 'lab_report') {
            $tests = $this->db->getRows("SELECT id, result_value, result_flag FROM lab_order_tests WHERE lab_order_id = :i AND result_value IS NOT NULL AND result_value <> '' ORDER BY id", [':i' => $ref]);
            if (!$tests) return null;
            $hash = sha1(json_encode([array_map(fn ($t) => [(int) $t['id'], $t['result_value'], $t['result_flag']], $tests), (string) $row['result_summary']]));
        }
        return ['patient_id' => $row['patient_id'], 'mobile' => $row['mobile'], 'hash' => $hash];
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    private function storeFile(int $tenant): string
    {
        $f = $this->request->file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::validationError(['file' => ['The upload did not complete. Please try again.']]);
        if ($f['size'] > self::MAX_BYTES) Response::validationError(['file' => ['The file is too large (max 10 MB).']]);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'application/pdf') Response::validationError(['file' => ['Only a PDF can be sent as a link.']]);

        $dir = ROOT_PATH . '/storage/shared/' . $tenant;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create the shared folder.');
        $name = bin2hex(random_bytes(16)) . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new RuntimeException('Could not save the uploaded file.');
        @chmod("$dir/$name", 0664);
        @chmod($dir, 0775);
        return "shared/$tenant/$name";
    }

    private function fullPath(string $rel): ?string
    {
        $full = realpath(ROOT_PATH . '/storage/' . $rel);
        $base = realpath(ROOT_PATH . '/storage/shared');
        return $full && $base && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full) ? $full : null;
    }

    private function removeFile(string $rel): void
    {
        if ($full = $this->fullPath($rel)) @unlink($full);
    }

    /** Old copies are not kept: delete the files of this clinic's links that have run out. */
    private function purge(int $tenant): void
    {
        $dead = $this->db->getRows('SELECT file_path FROM shared_documents WHERE doctor_id = :d AND (expires_at <= :now OR revoked_at IS NOT NULL)', [':d' => $tenant, ':now' => gmdate('Y-m-d H:i:s')]);
        foreach ($dead as $d) $this->removeFile($d['file_path']);
    }
}
