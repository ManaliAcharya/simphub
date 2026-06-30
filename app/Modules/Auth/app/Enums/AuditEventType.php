<?php

namespace Modules\Auth\Enums;

enum AuditEventType: string
{
    case INVITATION_CREATED = 'invitation_created';
    case INVITATION_ACCEPTED = 'invitation_accepted';
    case INVITATION_EXPIRED = 'invitation_expired';

    case LOGIN_SUCCESS = 'login_success';
    case LOGIN_FAILURE = 'login_failure';
    case LOGIN_LOCKOUT = 'login_lockout';
    case LOGIN_NEW_DEVICE = 'login_new_device';
    case LOGIN_BLOCKED = 'login_blocked';

    case LOGOUT = 'logout';

    case SESSION_IDLE_EXPIRED = 'session_idle_expired';
    case SESSION_ABSOLUTE_EXPIRED = 'session_absolute_expired';
    case SESSION_REVOKED_BY_USER = 'session_revoked_by_user';

    case OTP_REQUESTED = 'otp_requested';
    case OTP_REQUESTED_UNKNOWN = 'otp_requested_unknown';
    case OTP_RATE_LIMITED = 'otp_rate_limited';
    case OTP_VERIFY_SUCCESS = 'otp_verify_success';
    case OTP_VERIFY_FAILURE = 'otp_verify_failure';
    case OTP_ATTEMPTS_EXHAUSTED = 'otp_attempts_exhausted';

    case PASSWORD_CHANGED = 'password_changed';

    case REAUTH_SUCCESS = 'reauth_success';
    case REAUTH_FAILURE = 'reauth_failure';

    case ACCOUNT_SUSPENDED = 'account_suspended';
    case ACCOUNT_REACTIVATED = 'account_reactivated';

    case API_KEY_ROTATED = 'api_key_rotated';
    case WEBHOOK_SECRET_VIEWED = 'webhook_secret_viewed';
    case EMAIL_CHANGED = 'email_changed';
    case EMAIL_CHANGE_FAILED = 'email_changed_failed';
    case OTP_REQUESTED_UNKNOWN_EMAIL = 'otp_requested_unknown_email';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::INVITATION_CREATED => 'Invitation Created',
            self::INVITATION_ACCEPTED => 'Invitation Accepted',
            self::INVITATION_EXPIRED => 'Invitation Expired',

            self::LOGIN_SUCCESS => 'Login Success',
            self::LOGIN_FAILURE => 'Login Failure',
            self::LOGIN_LOCKOUT => 'Login Lockout',
            self::LOGIN_NEW_DEVICE => 'New Device Login',

            self::LOGOUT => 'Logout',

            self::SESSION_IDLE_EXPIRED => 'Session Idle Expired',
            self::SESSION_ABSOLUTE_EXPIRED => 'Session Absolute Expired',
            self::SESSION_REVOKED_BY_USER => 'Session Revoked By User',

            self::OTP_REQUESTED => 'OTP Requested',
            self::OTP_REQUESTED_UNKNOWN => 'OTP Requested For Unknown Email',
            self::OTP_RATE_LIMITED => 'OTP Rate Limited',
            self::OTP_VERIFY_SUCCESS => 'OTP Verify Success',
            self::OTP_VERIFY_FAILURE => 'OTP Verify Failure',
            self::OTP_ATTEMPTS_EXHAUSTED => 'OTP Attempts Exhausted',

            self::PASSWORD_CHANGED => 'Password Changed',

            self::REAUTH_SUCCESS => 'Re-authentication Success',
            self::REAUTH_FAILURE => 'Re-authentication Failure',

            self::ACCOUNT_SUSPENDED => 'Account Suspended',
            self::ACCOUNT_REACTIVATED => 'Account Reactivated',

            self::API_KEY_ROTATED => 'API Key Rotated',
            self::WEBHOOK_SECRET_VIEWED => 'Webhook Secret Viewed',

            self::EMAIL_CHANGED => 'Email Changed',
            self::EMAIL_CHANGE_FAILED => 'Email Changed Failed',

            self::OTP_REQUESTED_UNKNOWN_EMAIL => 'OTP Requested For Unknown Email',
        };
    }
}
