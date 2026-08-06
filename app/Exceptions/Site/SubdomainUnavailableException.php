<?php

namespace App\Exceptions\Site;

use RuntimeException;

/**
 * SIGNUP-1: SiteProvisioningService::createSiteForHandle() refused to provision a
 * site because the handle is not usable verbatim as a subdomain.
 *
 * Typed rather than a bare RuntimeException so callers can tell "the subdomain is
 * unavailable" apart from any other RuntimeException and choose their own
 * response. The two live callers deliberately choose differently:
 *
 *  - PreAccountBuildService report()s it and re-throws. The handle there is
 *    MACHINE-allocated by HandleAllocator, which has already proved it free as a
 *    subdomain, so reaching here means the allocator's guarantee broke — an
 *    anomaly that must be loud, not a 4xx the client can act on. (It propagates
 *    out of the build controllers, which catch only PreAccountBuildException.
 *    EarlyAccessService is the exception: it swallows Throwable into its own
 *    report(), so that flow files the same anomaly twice — pre-existing, and
 *    harmless beyond a duplicate Nightwatch issue.)
 *  - UserBootstrapService translates it to HANDLE_ALREADY_TAKEN. The handle there
 *    is USER-supplied, and BootstrapRequest never validates it against the
 *    reserved list or against subdomains held by legacy diverged rows, so this is
 *    reachable by ordinary input and deserves an actionable 409.
 */
class SubdomainUnavailableException extends RuntimeException {}
