<?php

namespace Tests\Support\Architecture;

use Symfony\Component\Finder\Finder;

final class ModelSweep
{
    /**
     * Every resolvable model class under $dir.
     *
     * The namespace is derived from app_path() for ALL callers — previously
     * SoftDeletePurgeCoverageTest hardcoded 'App\Models\' while the other two
     * derived it, so a PSR-4 refactor could break one sweep and not the others
     * with no visible difference in any result (COV-GUARD-4).
     *
     * @return list<class-string>
     */
    public static function resolvedModelClasses(?string $dir = null): array
    {
        $dir = $dir ?? app_path('Models');

        if (! is_dir($dir)) {
            return [];
        }

        $classes = [];

        foreach ((new Finder)->files()->in($dir)->name('*.php')->notName('BaseModel.php')->notPath('Views') as $file) {
            $class = str_replace([app_path(), '/', '.php'], ['App', '\\', ''], (string) $file->getRealPath());

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
