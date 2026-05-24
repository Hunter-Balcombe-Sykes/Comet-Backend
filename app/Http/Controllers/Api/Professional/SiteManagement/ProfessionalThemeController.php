<?php

namespace App\Http\Controllers\Api\Professional\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Resources\SiteResource;
use App\Http\Resources\ThemeResource;
use App\Models\Core\Site\Theme;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Http\Request;

// V2: Lists available site themes and allows selection of active theme for the professional's mini-site.
class ProfessionalThemeController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function index()
    {
        $themes = Theme::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'description', 'config', 'is_default']);

        return $this->success(['themes' => ThemeResource::collection($themes)]);
    }

    public function select(Request $request, Theme $theme, UpdateSiteAction $action)
    {
        $professional = $this->currentProfessional($request);

        $site = $action->execute($professional, [
            'theme_id' => $theme->id,
        ]);

        return $this->success(['site' => new SiteResource($site)]);
    }
}
