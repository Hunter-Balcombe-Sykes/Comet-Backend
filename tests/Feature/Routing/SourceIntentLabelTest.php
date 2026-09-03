<?php

// The coalesce-don't-clobber rule on SourceReconciler's intent advance
// (SourceReconciler.php, `if ($placement->identifierLabel !== null)` and the
// identifierIcon branch beside it).
//
// Both fields are network-born: a probe learns a store's name and mark by
// fetching /meta.json to identify the storefront at all, while most lanes have
// no name to give — a catalog detector captures an identifier out of a regex
// and knows nothing else. If the advance wrote its nulls unconditionally, the
// next re-scan of a store would blank what the probe paid an HTTP fetch for
// and the inbox card would silently regress to "Shopify store 23504463".
//
// Originally written on fix/suggestions-inbox-and-opentable-routing, whose
// feature half landed on development as 6feebdf73 by a different route; this
// case came across with no equivalent, so the rule shipped untested at the
// reconciler level (SuggestionsInboxTest covers the label only at the wire,
// through the seeder, where a clobber on re-advance never occurs).

use App\Routing\IriCanonicalizer;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

it('keeps a label and icon an earlier lane earned when a later bare lane re-advances the intent', function () {
    $pro = createTenant('label-sticky');
    $iri = app(IriCanonicalizer::class)->canonicalize('https://stali.com.au/collections/star-wars');
    $ctx = RoutingContext::forUser($pro, 'commerce_probe');
    $reconciler = app(SourceReconciler::class);

    $reconciler->reconcile(
        new Placement(
            Verdict::Choose,
            'shopify.store',
            '23504463',
            'needs_confirmation',
            'offered from a pasted link',
            identifierLabel: 'ST. ALi Coffee Roasters',
            identifierIcon: 'https://stali.com.au/favicon.ico',
        ),
        $ctx,
        $iri,
    );

    // The myshopify.com detector's lane: same surface, same identifier, no name.
    $reconciler->reconcile(
        new Placement(Verdict::Choose, 'shopify.store', '23504463', 'needs_confirmation', 'offered from a pasted link'),
        $ctx,
        $iri,
    );

    $intents = DB::table('routing.source_intents')
        ->where('user_id', $pro->id)
        ->where('surface_key', 'shopify.store')
        ->get();

    // Guards the assertions below against passing vacuously: if the second
    // pass had missed the live row and inserted its own, `first()` on an
    // unordered read could still hand back the labelled one.
    expect($intents)->toHaveCount(1);

    $intent = $intents->first();

    expect($intent->identifier_label)->toBe('ST. ALi Coffee Roasters');
    expect($intent->identifier_icon)->toBe('https://stali.com.au/favicon.ico');
});
