<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectCalendlyRequest;
use App\Http\Resources\Platforms\CalendlyConnectionResource;
use App\Services\Platforms\CalendlyApi;
use Illuminate\Http\JsonResponse;

// Calendly — connect by scheduling-page link or slug. The public booking
// API provides the profile (name / avatar / welcome text) and the bookable
// session types; each session deep-links to its real booking page.
class CalendlyController extends SingleSelectionPlatformController
{
    public function __construct(private readonly CalendlyApi $calendly) {}

    protected function platform(): string
    {
        return 'calendly';
    }

    protected function resourceClass(): string
    {
        return CalendlyConnectionResource::class;
    }

    // POST /api/platforms/calendly/connect
    public function connect(ConnectCalendlyRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $slug = $this->calendly->parseSlug($request->validated()['url']);
        if (! $slug) {
            return $this->error('Enter your Calendly link (calendly.com/yourname).', 422);
        }

        $profile = $this->calendly->fetchProfile($slug);
        if ($profile === null) {
            return $this->error('Could not find that Calendly page.', 404);
        }

        return $this->connected($user, [
            'url' => "https://calendly.com/{$slug}",
            'slug' => $slug,
            'name' => $profile['name'],
            'image' => $profile['image'],
            'description' => $profile['description'],
            'eventTypes' => $this->calendly->fetchEventTypes($slug),
        ]);
    }
}
