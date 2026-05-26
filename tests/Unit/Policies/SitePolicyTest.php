<?php

use App\Models\Analytics\LeadSubmission;
use App\Models\Core\User\User;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Policies\SitePolicy;

beforeEach(function () {
    $this->policy = new SitePolicy;
});

// ---------------------------------------------------------------------------
// Site itself
// ---------------------------------------------------------------------------

describe('Site', function () {
    // Site.user_id is not in $fillable — use forceFill in tests
    // so the attribute lands in the raw attributes array.
    it('allows view when the actor owns the site', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['user_id' => 'pro-actor']);

        expect($this->policy->view($actor, $site))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the site', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['user_id' => 'pro-other']);

        $result = $this->policy->view($actor, $site);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });

    it('allows update when the actor owns the site and is active', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['user_id' => 'pro-actor']);

        expect($this->policy->update($actor, $site))->toBeTrue();
    });

    it('denies update with 404 when the actor does not own the site', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['user_id' => 'pro-other']);

        $result = $this->policy->update($actor, $site);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });

    it('denies update with 423 when the actor is pending deletion', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $site = (new Site)->forceFill(['user_id' => 'pro-actor']);

        $result = $this->policy->update($actor, $site);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });

    it('denies create with 423 when the actor is pending deletion', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $skeleton = (new Site)->forceFill(['user_id' => 'pro-actor']);

        $result = $this->policy->create($actor, $skeleton);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(423);
        expect($result->message())->toBe('Account is pending deletion.');
    });
});

// ---------------------------------------------------------------------------
// SiteMedia — ownership resolved via preloaded site relation
// ---------------------------------------------------------------------------

describe('SiteMedia', function () {
    // Site.user_id not in $fillable — must forceFill so it lands in getAttributes().
    // The policy verifies that $media->site_id matches the preloaded site's id, so both
    // must be set consistently.
    it('allows view when the site relation is owned by the actor', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'user_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);

        expect($this->policy->view($actor, $media))->toBeTrue();
    });

    it('denies view with 404 when the site relation belongs to a different owner', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-2', 'user_id' => 'pro-other']);
        $media = new SiteMedia(['site_id' => 'site-2']);
        $media->setRelation('site', $site);

        $result = $this->policy->view($actor, $media);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });

    it('allows delete when the actor owns the site relation', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'user_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);

        expect($this->policy->delete($actor, $media))->toBeTrue();
    });

    it('denies delete with 423 when actor is pending deletion', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'pending_deletion']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'user_id' => 'pro-actor']);
        $media = new SiteMedia(['site_id' => 'site-1']);
        $media->setRelation('site', $site);

        $result = $this->policy->delete($actor, $media);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(423);
    });
});

// ---------------------------------------------------------------------------
// Block — denormalized user_id
// ---------------------------------------------------------------------------

describe('Block', function () {
    it('allows view when the actor owns the block', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $block = new Block(['user_id' => 'pro-actor']);

        expect($this->policy->view($actor, $block))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the block', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $block = new Block(['user_id' => 'pro-other']);

        $result = $this->policy->view($actor, $block);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// SiteSubdomainAlias — ownership resolved via preloaded site relation (same as SiteMedia)
// ---------------------------------------------------------------------------

describe('SiteSubdomainAlias', function () {
    it('allows view when the site relation is owned by the actor', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-1', 'user_id' => 'pro-actor']);
        $alias = new SiteSubdomainAlias(['site_id' => 'site-1']);
        $alias->setRelation('site', $site);

        expect($this->policy->view($actor, $alias))->toBeTrue();
    });

    it('denies view with 404 when the site relation belongs to a different owner', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $site = (new Site)->forceFill(['id' => 'site-2', 'user_id' => 'pro-other']);
        $alias = new SiteSubdomainAlias(['site_id' => 'site-2']);
        $alias->setRelation('site', $site);

        $result = $this->policy->view($actor, $alias);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// Enquiry — denormalized user_id (same pathway as Block)
// ---------------------------------------------------------------------------

describe('Enquiry', function () {
    it('allows view when the actor owns the enquiry', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $enquiry = new Enquiry(['user_id' => 'pro-actor']);

        expect($this->policy->view($actor, $enquiry))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the enquiry', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $enquiry = new Enquiry(['user_id' => 'pro-other']);

        $result = $this->policy->view($actor, $enquiry);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// LeadSubmission — denormalized user_id (same pathway as Block)
// ---------------------------------------------------------------------------

describe('LeadSubmission', function () {
    it('allows view when the actor owns the lead submission', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $lead = new LeadSubmission(['user_id' => 'pro-actor']);

        expect($this->policy->view($actor, $lead))->toBeTrue();
    });

    it('denies view with 404 when the actor does not own the lead submission', function () {
        $actor = (new User)->forceFill(['id' => 'pro-actor', 'status' => 'active']);
        $lead = new LeadSubmission(['user_id' => 'pro-other']);

        $result = $this->policy->view($actor, $lead);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });
});

// ---------------------------------------------------------------------------
// Spoofing defense — injected site relation whose id doesn't match resource's site_id
// ---------------------------------------------------------------------------

describe('spoofing defense', function () {
    // The policy cross-checks resource->getAttributes()['site_id'] against the
    // preloaded site's id. If an attacker injects a relation to their own site
    // on a resource they don't own, the site_id mismatch fires and denies access.
    it('denies view when injected site relation id does not match resource site_id', function () {
        $actor = (new User)->forceFill(['id' => 'pro-attacker', 'status' => 'active']);
        $attackerSite = (new Site)->forceFill(['id' => 'site-attacker', 'user_id' => 'pro-attacker']);
        // Resource belongs to a different site, but attacker injects their own site relation
        $media = new SiteMedia(['site_id' => 'site-real']);
        $media->setRelation('site', $attackerSite);

        $result = $this->policy->view($actor, $media);

        expect($result)->toBeInstanceOf(\Illuminate\Auth\Access\Response::class);
        expect($result->status())->toBe(404);
    });
});
