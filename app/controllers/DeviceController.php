<?php

declare(strict_types=1);

/**
 * DeviceController — registers a device's FCM token so the backend can push the
 * "call patient in" announcement to the clinic's devices (Module 4.4). Usable by
 * the doctor and the receptionist; every token is scoped to the clinic owner.
 */
class DeviceController extends BaseController
{
    /** POST device-tokens {token, platform?} */
    public function register(): void
    {
        $clinic = $this->requireClinic();
        $did    = (int) $clinic['id'];
        $token  = trim((string) $this->request->input('token', ''));
        if ($token === '' || strlen($token) > 512) {
            Response::validationError(['token' => ['A valid device token is required.']]);
        }
        $platform  = substr((string) $this->request->input('platform', 'android'), 0, 16);
        $ownerType = $this->isStaff() ? 'staff' : 'doctor';
        $ownerId   = $this->isStaff() ? (int) ($this->staff['id'] ?? 0) : $did;

        // One row per token: re-point it to this owner/clinic if already seen.
        $existing = $this->db->getRow('SELECT id FROM device_tokens WHERE token = :t', [':t' => $token]);
        if ($existing) {
            $this->db->update('device_tokens', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'doctor_id' => $did, 'platform' => $platform], 'id = :i', [':i' => (int) $existing['id']]);
        } else {
            $this->db->insert('device_tokens', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'doctor_id' => $did, 'token' => $token, 'platform' => $platform]);
        }
        Response::success([], 'Device registered for notifications');
    }

    /** POST device-tokens/remove {token} — drop this device on sign-out. */
    public function remove(): void
    {
        $this->requireClinic();
        $token = trim((string) $this->request->input('token', ''));
        if ($token !== '') $this->db->delete('device_tokens', 'token = :t', [':t' => $token]);
        Response::success([], 'Device removed');
    }
}
