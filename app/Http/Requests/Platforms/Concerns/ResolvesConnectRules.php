<?php

namespace App\Http\Requests\Platforms\Concerns;

use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;

// Shared connect-request behaviour: resolve the platform descriptor from the
// route's 'platform' default (set by every thin connect route) and drive
// validation entirely from its connectInput() metadata. 404 fail-closed when the
// platform is unknown or declares no connect contract.
trait ResolvesConnectRules
{
    private ?PlatformDescriptor $resolvedDescriptor = null;

    protected function connectDescriptor(): PlatformDescriptor
    {
        if ($this->resolvedDescriptor !== null) {
            return $this->resolvedDescriptor;
        }

        $platform = $this->route('platform');
        abort_if(! is_string($platform) || $platform === '', 404);

        $descriptor = app(PlatformRegistry::class)->get($platform);
        // Must exist AND declare a connect contract; a platform without
        // connectInput() (e.g. google-business) is not shared-request driven.
        abort_if($descriptor === null || $descriptor->connectField() === null, 404);

        return $this->resolvedDescriptor = $descriptor;
    }

    // Authorization is enforced at the trait chokepoint in the controller
    // (ManagesIntegrationConnection write/forget call authorizeForUser), matching
    // every per-platform request this replaces.
    public function authorize(): bool
    {
        return true;
    }

    // Normalise scheme-less pastes for platforms whose regex anchors on https?://
    // (fresha, square) before the rule runs — mirrors their old prepareForValidation.
    protected function prepareForValidation(): void
    {
        $descriptor = $this->connectDescriptor();
        if (! $descriptor->connectNormalizesUrlish()) {
            return;
        }

        $field = $descriptor->connectField();
        if (is_string($this->input($field))) {
            $this->merge([$field => PlatformInput::urlish((string) $this->input($field))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $descriptor = $this->connectDescriptor();

        return [$descriptor->connectField() => $descriptor->connectRules()];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->connectDescriptor()->connectMessages();
    }
}
