<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

/**
 * Why a governance operation was refused, in terms an administrator can act on.
 *
 * Every case maps from a PostgreSQL SQLSTATE raised by a bounded authority — never from
 * a message substring, which D-030.2.1 proved can be forged.
 */
enum AffiliateRefusalReason: string
{
    case Unavailable = 'unavailable';
    case InvalidInput = 'invalid_input';
    case NotADraft = 'not_a_draft';
    case VersionTaken = 'version_taken';
    case ConcurrentPublication = 'concurrent_publication';
    case StaleCodeRotation = 'stale_code_rotation';
    case StalePayoutTransition = 'stale_payout_transition';

    public function message(): string
    {
        return match ($this) {
            self::Unavailable => 'The affiliate governance operation could not be completed.',
            self::InvalidInput => 'The affiliate policy values were refused.',
            self::NotADraft => 'Only a draft policy can be edited or published.',
            self::VersionTaken => 'That policy version already exists.',
            self::ConcurrentPublication => 'Another policy was published at the same time. Reload and try again.',
            self::StaleCodeRotation => 'The affiliate code changed since this page was loaded. Reload the affiliate before rotating its code again.',
            self::StalePayoutTransition => 'The payout changed since this page was loaded. Reload it before deciding again.',
        };
    }
}
