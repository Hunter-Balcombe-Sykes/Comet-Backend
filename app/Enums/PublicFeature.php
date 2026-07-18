<?php

namespace App\Enums;

// The enforceable non-integration public features. The backing value is the
// '<name>' part of the availability key 'feature.<name>'. Single source of truth
// for the submit gate (GatesPublicFeature) and the owner-surfacing map on
// SiteResource. Adding a feature = one case here + one gate call in its endpoint.
enum PublicFeature: string
{
    case Enquiries = 'enquiries';
    case EmailSignup = 'email_signup';
    case CustomerLeads = 'customer_leads';

    /** The full core.feature_availability key, e.g. 'feature.enquiries'. */
    public function availabilityKey(): string
    {
        return 'feature.'.$this->value;
    }

    /** Human label for owner-surfacing / any future staff meta endpoint. */
    public function label(): string
    {
        return match ($this) {
            self::Enquiries => 'Enquiry form',
            self::EmailSignup => 'Email signup',
            self::CustomerLeads => 'Customer lead capture',
        };
    }
}
