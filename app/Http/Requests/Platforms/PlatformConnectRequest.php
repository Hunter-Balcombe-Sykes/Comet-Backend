<?php

namespace App\Http\Requests\Platforms;

use App\Http\Requests\Platforms\Concerns\ResolvesConnectRules;
use Illuminate\Foundation\Http\FormRequest;

// The single connect request for every reducible platform. Its field, rules,
// custom 422 messages, and pre-validation normalisation all come from the
// platform descriptor resolved off the route's 'platform' default. Adding a
// platform = one ->connectInput(...) line in PlatformRegistryServiceProvider, not
// a new request class. GoogleBusiness (multi-field) keeps ConnectGoogleBusinessRequest.
class PlatformConnectRequest extends FormRequest
{
    use ResolvesConnectRules;
}
