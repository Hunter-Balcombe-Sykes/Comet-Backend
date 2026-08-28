<?php

// Hand-written probe URLs for catalog surfaces that declare NO canonical_url_template.
// One entry per surface key. Keep the URL realistic (a real profile/venue shape).
// The sweep test lists every surface missing from here AND from the template set.
//
// Each URL below was checked against its surface's detector in
// bootstrap/catalog/compiled.php (registrable_key / path_pattern / subdomain_pattern)
// and run through the real WebsiteLinkHarvester::classify() before being committed —
// see docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md for the resulting
// bucket per surface.
return [
    // Booking (booking.book) — hand-table hosts (BOOKING_HOSTS), bare host is enough.
    'acuity.book' => 'https://acuityscheduling.com/schedule.php?owner=12345678',
    'bella_booking.book' => 'https://bellabooking.com/acme',
    'booksy.book' => 'https://booksy.com/en-us/1234567_acme-salon',
    'boulevard.book' => 'https://boulevard.io/book/acme',
    'direct.book' => 'https://acme-salon.example.com/book',
    'genbook.book' => 'https://genbook.com/biz/acme',
    'glossgenius.book' => 'https://book.glossgenius.com/acme',
    'kitomba.book' => 'https://kitomba.com/book/acme',
    'mangomint.book' => 'https://acme.mangomint.com/book',
    'mindbody.book' => 'https://clients.mindbodyonline.com/classic/ws?studioid=1234567',
    'noterro.book' => 'https://noterro.com/book/acme',
    'ovatu.book' => 'https://ovatu.com/book/acme',
    'phorest.book' => 'https://www.phorest.com/book/acme-salon',
    'schedulicity.book' => 'https://www.schedulicity.com/book/acme',
    'setmore.book' => 'https://acme.setmore.com/',
    'shortcuts.book' => 'https://acme.shortcuts.com.au/',
    'simplybook_me.book' => 'https://acme.simplybook.me/v2/',
    'square.book' => 'https://acme.square.site/',
    'timely.book' => 'https://acme.gettimely.com/book',
    'treatwell.book' => 'https://www.treatwell.co.uk/place/acme-salon',
    'vagaro.book' => 'https://www.vagaro.com/acme',
    'youcanbookme.book' => 'https://acme.youcanbook.me/',
    'zenoti.book' => 'https://acme.zenoti.com/webstoreNew/services',

    // Online ordering
    'bopple.order' => 'https://bopple.me/acme',
    'chownow.order' => 'https://order.chownow.com/order/1234/locations/1234',
    'deliveroo.order' => 'https://deliveroo.com/menu/london/acme/acme-restaurant',
    'doordash.order' => 'https://www.doordash.com/store/acme-1234567/',
    'easi.order' => 'https://easi.com.au/order/acme',
    'grubhub.order' => 'https://www.grubhub.com/restaurant/acme-123-main-st-sydney/1234567',
    'hungrypanda.order' => 'https://www.hungrypanda.co/en/restaurant/acme-1234567',
    'just_eat.order' => 'https://www.just-eat.co.uk/restaurants-acme/menu',
    'menulog.order' => 'https://www.menulog.com.au/restaurants-acme/menu',
    'order_online.order' => 'https://order.online/store/acme-1234567/',
    'ordermate.order' => 'https://ordermate.online/acme',
    'skipthedishes.order' => 'https://www.skipthedishes.com/acme-restaurant',
    'slice.order' => 'https://slicelife.com/restaurants/acme-pizza/menu',
    'square.order' => 'https://acme.square.site/s/order',
    'toast.order' => 'https://order.toasttab.com/online/acme-restaurant',
    'uber_eats.order' => 'https://www.ubereats.com/store/acme-restaurant/1234567',
    'wolt.order' => 'https://wolt.com/en/aus/sydney/restaurant/acme',
    'zomato.order' => 'https://www.zomato.com/sydney/acme-restaurant/order',

    // Reservations — hand-table hosts (RESERVATION_HOSTS)
    'chope.reserve' => 'https://chope.co/singapore-restaurants/restaurant/acme',
    'eat_app.reserve' => 'https://eatapp.co/book/acme-restaurant',
    'nowbookit.reserve' => 'https://www.nowbookit.com/Reservations/Create?RestaurantId=1234567',
    'quandoo.reserve' => 'https://www.quandoo.com/place/acme-restaurant-1234567',
    'resdiary.reserve' => 'https://acme.resdiary.com/',
    'resy.reserve' => 'https://resy.com/cities/syd/venue/acme',
    'sevenrooms.reserve' => 'https://www.sevenrooms.com/reservations/acme',
    'tablecheck.reserve' => 'https://www.tablecheck.com/en/acme-restaurant/reserve',
    'tablein.reserve' => 'https://tablein.com/book/acme-restaurant',
    'thefork.reserve' => 'https://www.thefork.com/restaurant/acme-r1234567',

    // Events / tickets
    'oztix.tickets' => 'https://oztix.com.au/events/acme-event',
    'resident_advisor.tickets' => 'https://ra.co/events/1234567',
    'ticketek.tickets' => 'https://premier.ticketek.com.au/shows/show.aspx?sh=ACMESH26',
    'ticketmaster.tickets' => 'https://www.ticketmaster.com/acme-event/event/1234567',
    'trybooking.tickets' => 'https://www.trybooking.com/events/acme',

    // Content / shop / misc — no host-based detector, or a URL-classifier surface
    // that legitimately does not resolve via classify() (see report §5).
    'apple_music.artist' => 'https://music.apple.com/us/artist/acme/1234567',
    'apple_podcasts.show' => 'https://podcasts.apple.com/us/podcast/acme/id1234567',
    'generic.store' => 'https://shop.example.com/products/acme-item',
    'google_business.listing' => 'https://www.google.com/maps/place/Acme+Salon/@-33.865,151.209,17z',
    'partna.manual_product' => 'https://acme.example.com/shop/handmade-necklace',
    'squarespace.store' => 'https://acme.squarespace.com/shop',
    'vimeo.account' => 'https://vimeo.com/acme',
    // T27a detect-only: the wixapps.net widget-host shape (wixsite.com is PSL-blocked).
    'wix_bookings.book' => 'https://bookings.wixapps.net/bookings/v1/acme',
    // T27a (2026-08-28) — the REAL urls each platform's live gate used (plan
    // doc phase-2 gate log), shapes verified against the deployed router.
    'jane_app.book' => 'https://revolutionwellnessclinic.janeapp.com/',
    'cliniko.book' => 'https://effective-physiotherapy-sports-injuries-clinic.cliniko.com/bookings',
    'halaxy.book' => 'https://eu.halaxy.com/profile/leeds-fittoworkmedicalscom/location/402572',
    'hotdoc.book' => 'https://www.hotdoc.com.au/medical-centres/port-melbourne-VIC-3207/port-melbourne-medical/doctors',
    'bookwell.book' => 'https://www.bookwell.com.au/venue/i-love-massage-clayfield/clayfield/4011',
    'styleseat.book' => 'https://www.styleseat.com/m/v/jj_styles',
    'mr_yum.order' => 'https://www.mryum.com/casanom',
    'rezdy.book' => 'https://greatoceanroadtours.rezdy.com/productList',
    'fareharbor.book' => 'https://fareharbor.com/embeds/book/sydneyharbourkayaks/',
    'google_appointments.book' => 'https://calendar.app.google/A1bC2dE3fG4hJ5k6',
    'microsoft_bookings.book' => 'https://outlook.office365.com/owa/calendar/bookings@contoso.com/bookings/',
    'woocommerce.store' => 'https://woocommerce.com/store/acme',

    // Wave 2 (2026-08-28) — real URLs verified during the batch's grammar
    // scout pass (bot-403'd hosts noted in each definition's docblock).
    'trustpilot.listing' => 'https://au.trustpilot.com/review/www.cheaperdomains.com.au',
    'bark.company' => 'https://www.bark.com/en/gb/company/startup-website-co/2369176/',
    'beatport.artist' => 'https://www.beatport.com/artist/johnny-rico/485532',
    'cash_app.profile' => 'https://cash.app/$cashapp',
    'dice.events' => 'https://dice.fm/artist/example-wwwwg',
    'eventfinda.tickets' => 'https://www.eventfinda.com.au/venue/boutique-nightclub-melbourne',
    'houzz.pro' => 'https://www.houzz.com.au/professionals/interior-designers-and-decorators/dani-louis-design-pfvwau-pf~503457392',
    'megatix.tickets' => 'https://megatix.com.au/events/kris-davis-trio',
    'moshtix.tickets' => 'https://www.moshtix.com.au/v2/venues/lazybones-lounge-restaurant-bar/7848',
    'skiddle.tickets' => 'https://www.skiddle.com/venues/1/',
    'stripe.payment_link' => 'https://buy.stripe.com/test_00g3eYaEO3BF5Ty144',
    'square.payment_link' => 'https://square.link/u/ExAmPle12',
    'tripadvisor.listing' => 'https://www.tripadvisor.com/Restaurant_Review-g255060-d27541618-Reviews-Sydney_Common-Sydney_New_South_Wales.html',

    // Link-only brands added in the 2026-08-28 cold-build round — every URL
    // shape taken from a real link seen in that run's scans, or from the
    // vendor's own documented URL grammar.
    'see_tickets.tickets' => 'https://www.seetickets.com/event/some-artist/the-venue/1234567',
    'etix.tickets' => 'https://www.etix.com/ticket/p/12345678/some-show-the-venue',
    'ticketweb.tickets' => 'https://www.ticketweb.com/event/some-artist-the-venue-tickets/1234567',
    'tixr.tickets' => 'https://www.tixr.com/e/12345',
    'admitone.tickets' => 'https://admitone.com/events/some-show-12345',
    'eventim.tickets' => 'https://www.eventim.de/event/some-artist-12345/',
    'linkfire.release' => 'https://lnk.to/some-release',
    'feature_fm.release' => 'https://ffm.to/some-release',
    'orchard.release' => 'https://orcd.co/some-release',
    'laylo.drop' => 'https://laylo.com/someartist/abc123',
    'venue_ink.book' => 'https://venue.ink/@someartist',
    'obee.reserve' => 'https://vouchers.obeeapp.com/some-venue/gift-voucher',
    'libro_fm.store' => 'https://libro.fm/some-bookshop',
    'abacus.order' => 'https://w.abacus.co/store/1234567/giftcards/landingPage',
    'heyzine.publication' => 'https://heyzine.com/flip-book/abc123def.html',
];
