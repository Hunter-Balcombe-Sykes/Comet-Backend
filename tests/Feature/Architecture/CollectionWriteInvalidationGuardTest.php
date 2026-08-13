<?php

// Slice 3b residuals, Unit 8: nothing enforced cache invalidation on
// content.collections / content.collection_items writes. All three lanes
// (BuildState::bump, site.sites.updated_at, the edge purge) are a caller
// obligation discharged by ManualServiceWriter::invalidate(), and slice 3b
// verified all of it by hand — which is exactly what does not survive the
// next contributor.
//
// An OBSERVER cannot do this job. Laravel observers fire on Eloquent events
// only, and NOT ONE write to either table goes through Eloquent:
// ServiceCollections writes the query builder
// (`DB::connection(self::CONNECTION)->table('content.collections')->insert`),
// ProjectionWriter writes raw `DB::table("content.{$table}")`, and ItemMerger
// hands the literal table name to a generic `moveWithoutClobbering($table…)`.
// App\Models\Content\Collection exists, but only as a policy/route-binding
// vehicle — AppServiceProvider::boot() registers ContentCollectionPolicy
// against it and UserServiceCategoryController builds an unsaved skeleton for
// authorization. Nothing in app/ ever calls save()/create()/delete() on it, so
// an observer on that model would fire on zero of the writes below.
//
// So: a static guard. Two halves.
//
//  1. The WRITER REGISTRY pins every method in app/ that writes either table.
//     A new write path — including a raw one — fails the build by name and
//     forces a lane decision instead of a silent bypass.
//  2. The DISCHARGE check walks every caller of a ServiceCollections mutator
//     plus every direct service-category-lane writer, and asserts each one
//     reaches ManualServiceWriter::invalidate() — either directly (a
//     type-resolved receiver) or through a $this-> helper in the same class.
//
// The receiver check is load-bearing and deliberately not name-based:
// `invalidate` is declared on four classes here, so resolving the call by
// method name alone would let a controller's own $this->invalidate() helper
// satisfy the guard after its body had lost the real ManualServiceWriter call.
// That is precisely the mutation exercised by the last test in this file.

/**
 * Every method under app/ that writes content.collections or
 * content.collection_items, and the lane that owns its invalidation.
 *
 * SEAM     — ServiceCollections, the declared single read/write for
 *            kind='service_category'. Its own docblock states that it never
 *            invalidates; the obligation sits with the caller and is enforced
 *            by the discharge test below.
 * SERVICE  — a direct raw write in the service-category lane. Must discharge
 *            on its own.
 * INGEST   — the connector projection lane. Invalidation rides the ingest run,
 *            not ManualServiceWriter.
 * MERGE    — identity merge; bumps BuildState via ItemMerger::bumpSites().
 * SHOP     — the kind='storefront' lane (slices 5a/5b). Different tables of
 *            record, different purge path; ManualServiceWriter does not apply.
 * READS    — the method only READS these two tables. It is listed because the
 *            scanner flags any method that names a guarded table AND issues
 *            some write terminal, and these write a DIFFERENT table.
 */
const COLLECTION_WRITE_REGISTRY = [
    'ServiceCollections::create' => 'SEAM',
    'ServiceCollections::rename' => 'SEAM',
    'ServiceCollections::reposition' => 'SEAM',
    'ServiceCollections::remove' => 'SEAM',
    'ServiceCollections::restore' => 'SEAM',
    'ServiceCollections::assign' => 'SEAM',

    'StaffServiceCategoryManagementController::forceDestroy' => 'SERVICE',

    'ProjectionWriter::upsertCollections' => 'INGEST',
    'ProjectionWriter::replaceCollections' => 'INGEST',

    'ItemMerger::foldInto' => 'MERGE',

    'ShopContentWriter::upsertStore' => 'SHOP',
    'ShopContentWriter::syncStore' => 'SHOP',
    'ShopContentWriter::retireStore' => 'SHOP',
    'ShopContentWriter::retireAbsent' => 'SHOP',
    'ShopBackfiller::linkToCollection' => 'SHOP',

    'ShopController::forget' => 'READS',
    'ProvisionShopPinsCommand::handle' => 'READS',
];

it('registers every app/ method that writes content.collections or content.collection_items', function () {
    $found = CollectionWriteScan::writers(CollectionWriteScan::appMethods());
    $registered = array_keys(COLLECTION_WRITE_REGISTRY);

    $unregistered = array_values(array_diff($found, $registered));
    $stale = array_values(array_diff($registered, $found));

    expect($unregistered)->toBe([], PHP_EOL.PHP_EOL
        .'New write path(s) into content.collections / content.collection_items:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn (string $m) => "  {$m}", $unregistered)).PHP_EOL.PHP_EOL
        .'No observer covers these tables (every write is query-builder or raw), so cache'.PHP_EOL
        .'invalidation is a caller obligation. Add each method to COLLECTION_WRITE_REGISTRY'.PHP_EOL
        .'with its lane. A service-category write must also discharge'.PHP_EOL
        .'ManualServiceWriter::invalidate() — see the next test.'.PHP_EOL);

    expect($stale)->toBe([], PHP_EOL.PHP_EOL
        .'COLLECTION_WRITE_REGISTRY names method(s) that no longer write either table:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn (string $m) => "  {$m}", $stale)).PHP_EOL.PHP_EOL
        .'Remove the entry. A stale entry is a standing exemption a later edit inherits unreviewed.'.PHP_EOL);
});

it('makes every service-category collection write reach ManualServiceWriter::invalidate()', function () {
    $methods = CollectionWriteScan::appMethods();

    // Callers of the ServiceCollections seam, plus the one method that writes
    // the tables raw in this lane. Both classes of caller owe the three lanes.
    $obliged = array_keys(CollectionWriteScan::seamCallers($methods));
    foreach (COLLECTION_WRITE_REGISTRY as $method => $lane) {
        if ($lane === 'SERVICE') {
            $obliged[] = $method;
        }
    }
    sort($obliged);

    // A guard over an empty set is not a guard. Slice 3b verified 21 write
    // paths by hand; if this collapses, the receiver resolution broke.
    expect(count($obliged))->toBeGreaterThanOrEqual(15);

    $undischarged = array_values(array_filter(
        $obliged,
        fn (string $id) => ! CollectionWriteScan::discharges($methods, $id),
    ));

    expect($undischarged)->toBe([], PHP_EOL.PHP_EOL
        .'These methods write content.collections / content.collection_items but never reach'.PHP_EOL
        .'ManualServiceWriter::invalidate():'.PHP_EOL
        .implode(PHP_EOL, array_map(fn (string $m) => "  {$m}", $undischarged)).PHP_EOL.PHP_EOL
        .'All three cache lanes (BuildState::bump, site.sites.updated_at, the edge purge) come'.PHP_EOL
        .'from that one call. Call it once per touched site — directly, or via a $this-> helper'.PHP_EOL
        .'in the same class that does. A same-named invalidate() on another object does not count.'.PHP_EOL);
});

it('reports a caller as undischarged once its helper loses the ManualServiceWriter call', function () {
    // The mutation this guard was proven against, kept as a permanent positive
    // control: without it, both tests above assert only that a set is empty and
    // would pass just as happily with a broken scanner.
    $discharged = <<<'PHP'
    <?php
    namespace App\Stub;
    use App\Services\Content\ManualServiceWriter;
    use App\Services\Content\ServiceCollections;
    class StubController {
        public function store(ServiceCollections $collections) {
            $collections->create('u', 'label');
            $this->invalidate('site');
        }
        private function invalidate(string $siteId): void {
            app(ManualServiceWriter::class)->invalidate([$siteId]);
        }
    }
    PHP;

    // Byte-identical but for the deleted invalidate() call in the helper.
    $mutated = str_replace(
        'app(ManualServiceWriter::class)->invalidate([$siteId]);',
        '// the call this guard exists to catch, deleted',
        $discharged,
    );

    $before = CollectionWriteScan::parse($discharged);
    $after = CollectionWriteScan::parse($mutated);

    expect(array_keys(CollectionWriteScan::seamCallers($before)))->toBe(['StubController::store'])
        ->and(CollectionWriteScan::discharges($before, 'StubController::store'))->toBeTrue()
        ->and(CollectionWriteScan::discharges($after, 'StubController::store'))->toBeFalse();
});

it('does not accept a same-named invalidate() on some other object as the real call', function () {
    // ->invalidate() is declared on ManualServiceWriter, UserCacheService and
    // two controllers. Name matching alone would green-light this.
    $source = <<<'PHP'
    <?php
    namespace App\Stub;
    use App\Services\Cache\UserCacheService;
    use App\Services\Content\ServiceCollections;
    class WrongReceiverController {
        public function store(ServiceCollections $collections) {
            $collections->create('u', 'label');
            app(UserCacheService::class)->invalidate(['site']);
        }
    }
    PHP;

    $methods = CollectionWriteScan::parse($source);

    expect(array_keys(CollectionWriteScan::seamCallers($methods)))->toBe(['WrongReceiverController::store'])
        ->and(CollectionWriteScan::discharges($methods, 'WrongReceiverController::store'))->toBeFalse();
});

/**
 * Token scanner for the two guards above.
 *
 * Declared as a class rather than the global functions FreshaMapperGuardTest
 * uses: a global helper in a test file is the shape CrossFileTestHelperGuardTest
 * exists to police, and a class cannot be called from a sibling file that a
 * --parallel worker never loaded.
 *
 * Brace counting treats T_CURLY_OPEN as a nesting level. "content.{$table}"
 * emits T_CURLY_OPEN for its `{` but a bare `}` for the close, so a naive
 * counter unbalances and truncates the enclosing method body — which silently
 * hid ProjectionWriter::replaceCollections and five controller actions during
 * development of this guard.
 */
final class CollectionWriteScan
{
    /**
     * String literals that name a guarded table. The bare 'collection_items'
     * is required: ProjectionWriter builds the table name by interpolation
     * (`DB::table("content.{$table}")`) from a map whose key is that literal,
     * so nothing in that method ever spells the qualified name.
     */
    private const TABLE_LITERALS = ['content.collections', 'content.collection_items', 'collection_items'];

    private const WRITE_TERMINALS = [
        'insert', 'insertGetId', 'insertUsing', 'insertOrIgnore',
        'update', 'updateOrInsert', 'upsert',
        'delete', 'forceDelete', 'truncate', 'increment', 'decrement',
    ];

    /** The mutating half of the ServiceCollections seam. */
    private const SEAM_MUTATORS = ['create', 'rename', 'reposition', 'remove', 'restore', 'assign'];

    /** @var array<string, array{display: string, class: string, file: string, body: list<mixed>}>|null */
    private static ?array $appMethods = null;

    /**
     * Every method under app/, keyed by fully-qualified Class::method so two
     * classes sharing a short name cannot overwrite each other.
     *
     * @return array<string, array{display: string, class: string, file: string, body: list<mixed>}>
     */
    public static function appMethods(): array
    {
        if (self::$appMethods !== null) {
            return self::$appMethods;
        }

        $methods = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $methods += self::parse((string) file_get_contents($file->getPathname()), $file->getPathname());
        }

        return self::$appMethods = $methods;
    }

    /**
     * @return array<string, array{display: string, class: string, file: string, body: list<mixed>}>
     */
    public static function parse(string $source, string $file = 'inline'): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        $namespace = '';
        $class = null;
        $methods = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace .= $tokens[$j][1];

                        continue;
                    }
                    break;
                }
            }

            if (is_array($token) && in_array($token[0], [T_CLASS, T_TRAIT, T_ENUM], true)
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING) {
                $class = $tokens[$i + 1][1];
            }

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            if (! is_array($tokens[$i + 1] ?? null) || $tokens[$i + 1][0] !== T_STRING) {
                continue;   // a closure, not a method
            }

            $name = $tokens[$i + 1][1];
            $start = null;
            for ($j = $i + 2; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $start = $j;
                    break;
                }
                if ($tokens[$j] === ';') {
                    break;   // abstract or interface method
                }
            }
            if ($start === null) {
                continue;
            }

            $depth = 0;
            // The signature rides along: a parameter type hint
            // (`ServiceCollections $collections`) and a promoted constructor
            // property are both bindings, and neither is inside the braces.
            $body = array_slice($tokens, $i, $start - $i);
            for ($j = $start; $j < $count; $j++) {
                $inner = $tokens[$j];
                if ($inner === '{' || (is_array($inner) && in_array($inner[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                }
                if ($inner === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $body[] = $inner;
            }

            $display = ($class ?? 'anonymous').'::'.$name;
            $methods[$namespace.'\\'.$display] = [
                'display' => $display,
                'class' => $class ?? 'anonymous',
                'file' => $file,
                'body' => $body,
            ];
            $i = $j;
        }

        // A promoted constructor property binds a name every OTHER method of
        // the class then reaches through $this->prop. Fold each constructor's
        // signature into its siblings so that binding is visible to them.
        foreach ($methods as $key => $method) {
            if (! str_ends_with($key, '::__construct')) {
                continue;
            }
            $signature = [];
            foreach ($method['body'] as $token) {
                if ($token === '{') {
                    break;
                }
                $signature[] = $token;
            }
            foreach ($methods as $siblingKey => $sibling) {
                if ($siblingKey !== $key && $sibling['class'] === $method['class']) {
                    $methods[$siblingKey]['body'] = array_merge($signature, $sibling['body']);
                }
            }
        }

        return $methods;
    }

    /**
     * Methods that name a guarded table AND issue a write terminal. Deliberately
     * method-granular rather than statement-granular: an indirect writer
     * (ItemMerger hands the table name to a generic mover) has no `->table('…')
     * ->delete()` chain to match, and it is still a write path.
     *
     * @param  array<string, array{display: string, class: string, file: string, body: list<mixed>}>  $methods
     * @return list<string>
     */
    public static function writers(array $methods): array
    {
        $writers = [];
        foreach ($methods as $method) {
            if (! self::namesGuardedTable($method['body'])) {
                continue;
            }
            if (! self::hasWriteTerminal($method['body'])) {
                continue;
            }
            $writers[] = $method['display'];
        }

        sort($writers);

        return array_values(array_unique($writers));
    }

    /**
     * Methods that call a mutator on a ServiceCollections-typed receiver.
     *
     * @param  array<string, array{display: string, class: string, file: string, body: list<mixed>}>  $methods
     * @return array<string, list<string>> display name => mutators called
     */
    public static function seamCallers(array $methods): array
    {
        $callers = [];
        foreach ($methods as $method) {
            $hits = self::callsOnType($method['body'], 'ServiceCollections', self::SEAM_MUTATORS);
            if ($hits !== []) {
                $callers[$method['display']] = $hits;
            }
        }
        ksort($callers);

        return $callers;
    }

    /**
     * Does $display reach ManualServiceWriter::invalidate()? Directly, or via a
     * $this-> helper on the same class that does.
     *
     * @param  array<string, array{display: string, class: string, file: string, body: list<mixed>}>  $methods
     * @param  list<string>  $seen  cycle break for mutually recursive helpers
     */
    public static function discharges(array $methods, string $display, array $seen = []): bool
    {
        if (in_array($display, $seen, true)) {
            return false;
        }
        $seen[] = $display;

        [$class] = explode('::', $display);

        foreach ($methods as $key => $method) {
            if ($method['display'] !== $display) {
                continue;
            }
            if (self::callsOnType($method['body'], 'ManualServiceWriter', ['invalidate']) !== []) {
                return true;
            }
            foreach (self::thisCalls($method['body']) as $name) {
                if (self::discharges($methods, $class.'::'.$name, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<mixed>  $body */
    private static function namesGuardedTable(array $body): bool
    {
        foreach ($body as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $value = trim($token[1], "'\"");
            foreach (self::TABLE_LITERALS as $literal) {
                if (str_contains($value, $literal)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<mixed>  $body */
    private static function hasWriteTerminal(array $body): bool
    {
        foreach (self::methodCalls($body) as [, $name]) {
            if (in_array($name, self::WRITE_TERMINALS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calls to $names on a receiver that resolves to $type. Type resolution is
     * what stops a same-named method on another object from counting.
     *
     * @param  list<mixed>  $body
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function callsOnType(array $body, string $type, array $names): array
    {
        $bound = self::variablesTyped($body, $type);
        $hits = [];

        foreach (self::methodCalls($body) as [$index, $name]) {
            if (! in_array($name, $names, true)) {
                continue;
            }
            if (self::receiverIsType($body, $index, $type, $bound)) {
                $hits[] = $name;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Variable names bound to $type inside this body — a parameter or promoted
     * constructor property (`ManualServiceWriter $writer`), or a local
     * container resolve (`$writer = app(ManualServiceWriter::class)`). Comments
     * are already stripped, so a docblock mentioning the type binds nothing.
     *
     * @param  list<mixed>  $body
     * @return list<string>
     */
    private static function variablesTyped(array $body, string $type): array
    {
        $bound = [];
        $count = count($body);

        for ($i = 0; $i < $count - 1; $i++) {
            $token = $body[$i];

            if (is_array($token) && $token[0] === T_STRING && $token[1] === $type
                && is_array($body[$i + 1]) && $body[$i + 1][0] === T_VARIABLE) {
                $bound[] = $body[$i + 1][1];

                continue;
            }

            if (! is_array($token) || $token[0] !== T_VARIABLE || ($body[$i + 1] ?? null) !== '=') {
                continue;
            }
            $text = '';
            for ($j = $i + 2; $j < $count && $body[$j] !== ';'; $j++) {
                $text .= is_array($body[$j]) ? $body[$j][1] : $body[$j];
            }
            if (str_contains($text, $type.'::class')) {
                $bound[] = $token[1];
            }
        }

        return array_values(array_unique($bound));
    }

    /**
     * Receiver immediately left of the `->` at $index: a bound variable,
     * `$this->prop` where prop is bound, or `app(Type::class)`.
     *
     * @param  list<mixed>  $body
     * @param  list<string>  $bound
     */
    private static function receiverIsType(array $body, int $index, string $type, array $bound): bool
    {
        $previous = $body[$index - 1] ?? null;
        if ($previous === null) {
            return false;
        }

        if (is_array($previous) && $previous[0] === T_VARIABLE) {
            return $previous[1] !== '$this' && in_array($previous[1], $bound, true);
        }

        // $this->prop->method()
        if (is_array($previous) && $previous[0] === T_STRING
            && is_array($body[$index - 2] ?? null) && $body[$index - 2][0] === T_OBJECT_OPERATOR
            && is_array($body[$index - 3] ?? null) && $body[$index - 3][0] === T_VARIABLE
            && $body[$index - 3][1] === '$this') {
            return in_array('$'.$previous[1], $bound, true);
        }

        // app(Type::class)->method()
        if ($previous === ')') {
            for ($i = $index - 1; $i >= 1; $i--) {
                if ($body[$i] !== '(') {
                    continue;
                }
                if (! is_array($body[$i - 1]) || $body[$i - 1][0] !== T_STRING || $body[$i - 1][1] !== 'app') {
                    continue;
                }
                $text = '';
                foreach (array_slice($body, $i + 1, $index - $i - 2) as $token) {
                    $text .= is_array($token) ? $token[1] : $token;
                }

                return str_contains($text, $type.'::class');
            }
        }

        return false;
    }

    /**
     * Names called on $this in this body.
     *
     * @param  list<mixed>  $body
     * @return list<string>
     */
    private static function thisCalls(array $body): array
    {
        $names = [];
        foreach (self::methodCalls($body) as [$index, $name]) {
            $receiver = $body[$index - 1] ?? null;
            if (is_array($receiver) && $receiver[0] === T_VARIABLE && $receiver[1] === '$this') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every `->name(` in $body as [index of the `->`, name].
     *
     * @param  list<mixed>  $body
     * @return list<array{0: int, 1: string}>
     */
    private static function methodCalls(array $body): array
    {
        $calls = [];
        $count = count($body);

        for ($i = 0; $i < $count - 2; $i++) {
            if (! is_array($body[$i]) || ! in_array($body[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            if (! is_array($body[$i + 1]) || $body[$i + 1][0] !== T_STRING) {
                continue;
            }
            if (($body[$i + 2] ?? null) !== '(') {
                continue;
            }
            $calls[] = [$i, $body[$i + 1][1]];
        }

        return $calls;
    }
}
