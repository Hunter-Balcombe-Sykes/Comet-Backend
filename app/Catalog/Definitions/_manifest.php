<?php

use App\Catalog\Definitions\Abacus;
use App\Catalog\Definitions\Acuity;
use App\Catalog\Definitions\Admitone;
use App\Catalog\Definitions\AmazonShop;
use App\Catalog\Definitions\AppleMusic;
use App\Catalog\Definitions\ApplePodcasts;
use App\Catalog\Definitions\Audiomack;
use App\Catalog\Definitions\Bandcamp;
use App\Catalog\Definitions\Bandsintown;
use App\Catalog\Definitions\Bark;
use App\Catalog\Definitions\Beatport;
use App\Catalog\Definitions\Behance;
use App\Catalog\Definitions\BellaBooking;
use App\Catalog\Definitions\BigCartel;
use App\Catalog\Definitions\Bluesky;
use App\Catalog\Definitions\Booksy;
use App\Catalog\Definitions\Bookwell;
use App\Catalog\Definitions\Bopple;
use App\Catalog\Definitions\Boulevard;
use App\Catalog\Definitions\Buymeacoffee;
use App\Catalog\Definitions\CalCom;
use App\Catalog\Definitions\Calendly;
use App\Catalog\Definitions\Cameo;
use App\Catalog\Definitions\CashApp;
use App\Catalog\Definitions\Chope;
use App\Catalog\Definitions\Chownow;
use App\Catalog\Definitions\Circle;
use App\Catalog\Definitions\Classpass;
use App\Catalog\Definitions\Cliniko;
use App\Catalog\Definitions\Codepen;
use App\Catalog\Definitions\Dailymotion;
use App\Catalog\Definitions\Deezer;
use App\Catalog\Definitions\Deliveroo;
use App\Catalog\Definitions\Depop;
use App\Catalog\Definitions\Dice;
use App\Catalog\Definitions\DirectBooking;
use App\Catalog\Definitions\Discord;
use App\Catalog\Definitions\Doordash;
use App\Catalog\Definitions\Dribbble;
use App\Catalog\Definitions\Easi;
use App\Catalog\Definitions\EatApp;
use App\Catalog\Definitions\Etix;
use App\Catalog\Definitions\Etsy;
use App\Catalog\Definitions\Eventbrite;
use App\Catalog\Definitions\Eventfinda;
use App\Catalog\Definitions\Eventim;
use App\Catalog\Definitions\Facebook;
use App\Catalog\Definitions\Fareharbor;
use App\Catalog\Definitions\FeatureFm;
use App\Catalog\Definitions\Fiverr;
use App\Catalog\Definitions\Flickr;
use App\Catalog\Definitions\Fresha;
use App\Catalog\Definitions\Genbook;
use App\Catalog\Definitions\GenericStore;
use App\Catalog\Definitions\Github;
use App\Catalog\Definitions\Gitlab;
use App\Catalog\Definitions\Glossgenius;
use App\Catalog\Definitions\GoogleAppointments;
use App\Catalog\Definitions\GoogleBusiness;
use App\Catalog\Definitions\Grubhub;
use App\Catalog\Definitions\Gumroad;
use App\Catalog\Definitions\Halaxy;
use App\Catalog\Definitions\HeyYou;
use App\Catalog\Definitions\Heyzine;
use App\Catalog\Definitions\Hotdoc;
use App\Catalog\Definitions\Houzz;
use App\Catalog\Definitions\Humanitix;
use App\Catalog\Definitions\Hungrypanda;
use App\Catalog\Definitions\Hypeddit;
use App\Catalog\Definitions\Instagram;
use App\Catalog\Definitions\JaneApp;
use App\Catalog\Definitions\JustEat;
use App\Catalog\Definitions\Kajabi;
use App\Catalog\Definitions\Kick;
use App\Catalog\Definitions\Kitomba;
use App\Catalog\Definitions\KoFi;
use App\Catalog\Definitions\Laylo;
use App\Catalog\Definitions\LibroFm;
use App\Catalog\Definitions\Linkedin;
use App\Catalog\Definitions\Linkfire;
use App\Catalog\Definitions\Luma;
use App\Catalog\Definitions\Mangomint;
use App\Catalog\Definitions\Medium;
use App\Catalog\Definitions\Megatix;
use App\Catalog\Definitions\Menulog;
use App\Catalog\Definitions\MicrosoftBookings;
use App\Catalog\Definitions\Mindbody;
use App\Catalog\Definitions\Mixcloud;
use App\Catalog\Definitions\Moshtix;
use App\Catalog\Definitions\MrYum;
use App\Catalog\Definitions\Noterro;
use App\Catalog\Definitions\Nowbookit;
use App\Catalog\Definitions\Obee;
use App\Catalog\Definitions\Opentable;
use App\Catalog\Definitions\Orchard;
use App\Catalog\Definitions\Ordermate;
use App\Catalog\Definitions\OrderOnline;
use App\Catalog\Definitions\Ovatu;
use App\Catalog\Definitions\Oztix;
use App\Catalog\Definitions\Partiful;
use App\Catalog\Definitions\Partna;
use App\Catalog\Definitions\Patreon;
use App\Catalog\Definitions\Paypal;
use App\Catalog\Definitions\Phorest;
use App\Catalog\Definitions\Pinterest;
use App\Catalog\Definitions\Postmates;
use App\Catalog\Definitions\ProductReviewAu;
use App\Catalog\Definitions\Quandoo;
use App\Catalog\Definitions\Redbubble;
use App\Catalog\Definitions\Reddit;
use App\Catalog\Definitions\Resdiary;
use App\Catalog\Definitions\ResidentAdvisor;
use App\Catalog\Definitions\Resy;
use App\Catalog\Definitions\Rezdy;
use App\Catalog\Definitions\Rumble;
use App\Catalog\Definitions\Schedulicity;
use App\Catalog\Definitions\SeeTickets;
use App\Catalog\Definitions\Setmore;
use App\Catalog\Definitions\Sevenrooms;
use App\Catalog\Definitions\Shopify;
use App\Catalog\Definitions\Shortcuts;
use App\Catalog\Definitions\SimplybookMe;
use App\Catalog\Definitions\Skiddle;
use App\Catalog\Definitions\Skipthedishes;
use App\Catalog\Definitions\Skool;
use App\Catalog\Definitions\Slice;
use App\Catalog\Definitions\Snapchat;
use App\Catalog\Definitions\Songkick;
use App\Catalog\Definitions\Soundcloud;
use App\Catalog\Definitions\Spotify;
use App\Catalog\Definitions\SpotifyPodcasts;
use App\Catalog\Definitions\Square;
use App\Catalog\Definitions\Squarespace;
use App\Catalog\Definitions\Stan;
use App\Catalog\Definitions\Strava;
use App\Catalog\Definitions\StripeLinks;
use App\Catalog\Definitions\Styleseat;
use App\Catalog\Definitions\Substack;
use App\Catalog\Definitions\Tablecheck;
use App\Catalog\Definitions\Tablein;
use App\Catalog\Definitions\Telegram;
use App\Catalog\Definitions\Thefork;
use App\Catalog\Definitions\Threads;
use App\Catalog\Definitions\Ticketek;
use App\Catalog\Definitions\TicketHype;
use App\Catalog\Definitions\Ticketmaster;
use App\Catalog\Definitions\Ticketweb;
use App\Catalog\Definitions\Tidal;
use App\Catalog\Definitions\Tiktok;
use App\Catalog\Definitions\TiktokShop;
use App\Catalog\Definitions\Timely;
use App\Catalog\Definitions\Tixr;
use App\Catalog\Definitions\Toast;
use App\Catalog\Definitions\Tock;
use App\Catalog\Definitions\Treatwell;
use App\Catalog\Definitions\Tripadvisor;
use App\Catalog\Definitions\Trustpilot;
use App\Catalog\Definitions\Trybooking;
use App\Catalog\Definitions\Tumblr;
use App\Catalog\Definitions\Twitch;
use App\Catalog\Definitions\UberEats;
use App\Catalog\Definitions\Upwork;
use App\Catalog\Definitions\Vagaro;
use App\Catalog\Definitions\Venmo;
use App\Catalog\Definitions\VenueInk;
use App\Catalog\Definitions\Vimeo;
use App\Catalog\Definitions\Vsco;
use App\Catalog\Definitions\Whatsapp;
use App\Catalog\Definitions\WixBookings;
use App\Catalog\Definitions\Wolt;
use App\Catalog\Definitions\Woocommerce;
use App\Catalog\Definitions\X;
use App\Catalog\Definitions\Yelp;
use App\Catalog\Definitions\YouCanBookMe;
use App\Catalog\Definitions\Youtube;
use App\Catalog\Definitions\YoutubeMusic;
use App\Catalog\Definitions\Zenoti;
use App\Catalog\Definitions\Zomato;

// Explicit, ordered list of definition classes catalog:compile reads.
// NO attribute scanning, NO directory globbing — adding a brand is adding a
// line here, and review sees it.
return [
    Abacus::class,
    Acuity::class,
    Admitone::class,
    AmazonShop::class,
    AppleMusic::class,
    ApplePodcasts::class,
    Audiomack::class,
    Bandcamp::class,
    Bandsintown::class,
    Bark::class,
    Beatport::class,
    Behance::class,
    BellaBooking::class,
    BigCartel::class,
    Bluesky::class,
    Booksy::class,
    Bookwell::class,
    Bopple::class,
    Boulevard::class,
    Buymeacoffee::class,
    CalCom::class,
    Calendly::class,
    Cameo::class,
    CashApp::class,
    Chope::class,
    Chownow::class,
    Circle::class,
    Classpass::class,
    Cliniko::class,
    Codepen::class,
    Dailymotion::class,
    Deezer::class,
    Deliveroo::class,
    Depop::class,
    Dice::class,
    DirectBooking::class,
    Discord::class,
    Doordash::class,
    Dribbble::class,
    Easi::class,
    EatApp::class,
    Etix::class,
    Etsy::class,
    Eventbrite::class,
    Eventfinda::class,
    Eventim::class,
    Facebook::class,
    Fareharbor::class,
    FeatureFm::class,
    Fiverr::class,
    Flickr::class,
    Fresha::class,
    Genbook::class,
    GenericStore::class,
    Github::class,
    Gitlab::class,
    Glossgenius::class,
    GoogleAppointments::class,
    GoogleBusiness::class,
    Grubhub::class,
    Gumroad::class,
    Halaxy::class,
    HeyYou::class,
    Heyzine::class,
    Hotdoc::class,
    Houzz::class,
    Humanitix::class,
    Hungrypanda::class,
    Hypeddit::class,
    Instagram::class,
    JaneApp::class,
    JustEat::class,
    Kajabi::class,
    Kick::class,
    Kitomba::class,
    KoFi::class,
    Laylo::class,
    LibroFm::class,
    Linkedin::class,
    Linkfire::class,
    Luma::class,
    Mangomint::class,
    Medium::class,
    Megatix::class,
    Menulog::class,
    MicrosoftBookings::class,
    Mindbody::class,
    Mixcloud::class,
    Moshtix::class,
    MrYum::class,
    Noterro::class,
    Nowbookit::class,
    Obee::class,
    Opentable::class,
    Orchard::class,
    OrderOnline::class,
    Ordermate::class,
    Ovatu::class,
    Oztix::class,
    Partiful::class,
    Partna::class,
    Patreon::class,
    Paypal::class,
    Phorest::class,
    Pinterest::class,
    Postmates::class,
    ProductReviewAu::class,
    Quandoo::class,
    Redbubble::class,
    Reddit::class,
    Resdiary::class,
    ResidentAdvisor::class,
    Resy::class,
    Rezdy::class,
    Rumble::class,
    Schedulicity::class,
    SeeTickets::class,
    Setmore::class,
    Sevenrooms::class,
    Shopify::class,
    Shortcuts::class,
    SimplybookMe::class,
    Skiddle::class,
    Skipthedishes::class,
    Skool::class,
    Slice::class,
    Snapchat::class,
    Songkick::class,
    Soundcloud::class,
    Spotify::class,
    SpotifyPodcasts::class,
    Square::class,
    Squarespace::class,
    Stan::class,
    Strava::class,
    StripeLinks::class,
    Styleseat::class,
    Substack::class,
    Tablecheck::class,
    Tablein::class,
    Telegram::class,
    Thefork::class,
    Threads::class,
    TicketHype::class,
    Ticketek::class,
    Ticketmaster::class,
    Ticketweb::class,
    Tidal::class,
    Tiktok::class,
    TiktokShop::class,
    Timely::class,
    Tixr::class,
    Toast::class,
    Tock::class,
    Treatwell::class,
    Tripadvisor::class,
    Trustpilot::class,
    Trybooking::class,
    Tumblr::class,
    Twitch::class,
    UberEats::class,
    Upwork::class,
    Vagaro::class,
    Venmo::class,
    VenueInk::class,
    Vimeo::class,
    Vsco::class,
    Whatsapp::class,
    WixBookings::class,
    Wolt::class,
    Woocommerce::class,
    X::class,
    Yelp::class,
    YouCanBookMe::class,
    Youtube::class,
    YoutubeMusic::class,
    Zenoti::class,
    Zomato::class,
];
