<?php

use App\Catalog\Definitions\Acuity;
use App\Catalog\Definitions\AppleMusic;
use App\Catalog\Definitions\ApplePodcasts;
use App\Catalog\Definitions\Bandcamp;
use App\Catalog\Definitions\Behance;
use App\Catalog\Definitions\BellaBooking;
use App\Catalog\Definitions\BigCartel;
use App\Catalog\Definitions\Booksy;
use App\Catalog\Definitions\Bopple;
use App\Catalog\Definitions\Boulevard;
use App\Catalog\Definitions\Buymeacoffee;
use App\Catalog\Definitions\Calendly;
use App\Catalog\Definitions\Chope;
use App\Catalog\Definitions\Chownow;
use App\Catalog\Definitions\Circle;
use App\Catalog\Definitions\Codepen;
use App\Catalog\Definitions\Deliveroo;
use App\Catalog\Definitions\Discord;
use App\Catalog\Definitions\Doordash;
use App\Catalog\Definitions\Dribbble;
use App\Catalog\Definitions\Easi;
use App\Catalog\Definitions\EatApp;
use App\Catalog\Definitions\Eventbrite;
use App\Catalog\Definitions\Facebook;
use App\Catalog\Definitions\Fresha;
use App\Catalog\Definitions\Genbook;
use App\Catalog\Definitions\Github;
use App\Catalog\Definitions\Gitlab;
use App\Catalog\Definitions\Glossgenius;
use App\Catalog\Definitions\GoogleBusiness;
use App\Catalog\Definitions\Grubhub;
use App\Catalog\Definitions\Gumroad;
use App\Catalog\Definitions\Humanitix;
use App\Catalog\Definitions\Hungrypanda;
use App\Catalog\Definitions\Instagram;
use App\Catalog\Definitions\JustEat;
use App\Catalog\Definitions\Kajabi;
use App\Catalog\Definitions\Kick;
use App\Catalog\Definitions\Kitomba;
use App\Catalog\Definitions\KoFi;
use App\Catalog\Definitions\Linkedin;
use App\Catalog\Definitions\Luma;
use App\Catalog\Definitions\Mangomint;
use App\Catalog\Definitions\Medium;
use App\Catalog\Definitions\Menulog;
use App\Catalog\Definitions\Mindbody;
use App\Catalog\Definitions\Mixcloud;
use App\Catalog\Definitions\Noterro;
use App\Catalog\Definitions\Nowbookit;
use App\Catalog\Definitions\Opentable;
use App\Catalog\Definitions\Ordermate;
use App\Catalog\Definitions\OrderOnline;
use App\Catalog\Definitions\Ovatu;
use App\Catalog\Definitions\Oztix;
use App\Catalog\Definitions\Partiful;
use App\Catalog\Definitions\Partna;
use App\Catalog\Definitions\Patreon;
use App\Catalog\Definitions\Phorest;
use App\Catalog\Definitions\Quandoo;
use App\Catalog\Definitions\Reddit;
use App\Catalog\Definitions\Resdiary;
use App\Catalog\Definitions\ResidentAdvisor;
use App\Catalog\Definitions\Resy;
use App\Catalog\Definitions\Schedulicity;
use App\Catalog\Definitions\Setmore;
use App\Catalog\Definitions\Sevenrooms;
use App\Catalog\Definitions\Shopify;
use App\Catalog\Definitions\Shortcuts;
use App\Catalog\Definitions\SimplybookMe;
use App\Catalog\Definitions\Skipthedishes;
use App\Catalog\Definitions\Skool;
use App\Catalog\Definitions\Slice;
use App\Catalog\Definitions\Snapchat;
use App\Catalog\Definitions\Soundcloud;
use App\Catalog\Definitions\Spotify;
use App\Catalog\Definitions\Square;
use App\Catalog\Definitions\Stan;
use App\Catalog\Definitions\Strava;
use App\Catalog\Definitions\Substack;
use App\Catalog\Definitions\Tablecheck;
use App\Catalog\Definitions\Tablein;
use App\Catalog\Definitions\Telegram;
use App\Catalog\Definitions\Thefork;
use App\Catalog\Definitions\Threads;
use App\Catalog\Definitions\Ticketek;
use App\Catalog\Definitions\Ticketmaster;
use App\Catalog\Definitions\Tidal;
use App\Catalog\Definitions\Tiktok;
use App\Catalog\Definitions\Timely;
use App\Catalog\Definitions\Toast;
use App\Catalog\Definitions\Tock;
use App\Catalog\Definitions\Treatwell;
use App\Catalog\Definitions\Trybooking;
use App\Catalog\Definitions\Twitch;
use App\Catalog\Definitions\UberEats;
use App\Catalog\Definitions\Vagaro;
use App\Catalog\Definitions\Vimeo;
use App\Catalog\Definitions\Whatsapp;
use App\Catalog\Definitions\Wolt;
use App\Catalog\Definitions\Woocommerce;
use App\Catalog\Definitions\X;
use App\Catalog\Definitions\Youtube;
use App\Catalog\Definitions\YoutubeMusic;
use App\Catalog\Definitions\Zenoti;
use App\Catalog\Definitions\Zomato;

// Explicit, ordered list of definition classes catalog:compile reads.
// NO attribute scanning, NO directory globbing — adding a brand is adding a
// line here, and review sees it.
return [
    Acuity::class,
    AppleMusic::class,
    ApplePodcasts::class,
    Bandcamp::class,
    Behance::class,
    BigCartel::class,
    BellaBooking::class,
    Booksy::class,
    Bopple::class,
    Boulevard::class,
    Buymeacoffee::class,
    Calendly::class,
    Chope::class,
    Chownow::class,
    Circle::class,
    Codepen::class,
    Deliveroo::class,
    Discord::class,
    Doordash::class,
    Dribbble::class,
    Easi::class,
    EatApp::class,
    Eventbrite::class,
    Facebook::class,
    Fresha::class,
    Genbook::class,
    Github::class,
    Gitlab::class,
    Glossgenius::class,
    GoogleBusiness::class,
    Grubhub::class,
    Gumroad::class,
    Humanitix::class,
    Hungrypanda::class,
    Instagram::class,
    JustEat::class,
    Kajabi::class,
    Kick::class,
    Kitomba::class,
    KoFi::class,
    Linkedin::class,
    Luma::class,
    Mangomint::class,
    Medium::class,
    Menulog::class,
    Mindbody::class,
    Mixcloud::class,
    Noterro::class,
    Nowbookit::class,
    Opentable::class,
    OrderOnline::class,
    Ordermate::class,
    Ovatu::class,
    Oztix::class,
    Partiful::class,
    Partna::class,
    Patreon::class,
    Phorest::class,
    Quandoo::class,
    Reddit::class,
    Resdiary::class,
    ResidentAdvisor::class,
    Resy::class,
    Schedulicity::class,
    Setmore::class,
    Sevenrooms::class,
    Shopify::class,
    Shortcuts::class,
    SimplybookMe::class,
    Skipthedishes::class,
    Skool::class,
    Slice::class,
    Snapchat::class,
    Soundcloud::class,
    Spotify::class,
    Square::class,
    Stan::class,
    Strava::class,
    Substack::class,
    Tablecheck::class,
    Tablein::class,
    Telegram::class,
    Thefork::class,
    Threads::class,
    Ticketek::class,
    Ticketmaster::class,
    Tidal::class,
    Tiktok::class,
    Timely::class,
    Toast::class,
    Tock::class,
    Treatwell::class,
    Trybooking::class,
    Twitch::class,
    UberEats::class,
    Vagaro::class,
    Vimeo::class,
    Whatsapp::class,
    Wolt::class,
    Woocommerce::class,
    X::class,
    Youtube::class,
    YoutubeMusic::class,
    Zenoti::class,
    Zomato::class,
];
