<?php

use App\Services\Site\SocialLinkNormalizer;

// SEM-1 differential (repo convention: diff OLD vs NEW over a generated
// corpus for an ordered/branching pattern map — a slug silently becoming
// null is invisible in a normal unit test, only visible in a diff). This
// file generates one deterministic signature line per (platform, input)
// pair and compares the sorted set against an inline baseline. The baseline
// below was captured by running this same generator against the PRE-FIX
// tree (config/partna.php linkedin+spotify blocks with an unnamed capture
// group; SocialLinkNormalizer reading $matches[1] with no shape) via `cp`,
// then hand-corrected for the ~10 linkedin-/company/ and spotify-/artist/
// rows that the fix changes. See the implementation report for the
// red-before/green-after evidence.

/** One handle per registered platform, valid against that platform's handle_pattern. */
const SAMPLE_HANDLES = [
    'instagram' => 'acmecoach',
    'facebook' => 'acmecoach',
    'linkedin' => 'acmecoach',
    'youtube' => 'acmecoach',
    'tiktok' => 'acmecoach',
    'x' => 'acmecoach',
    'spotify' => 'acmecoach',
    'soundcloud' => 'acmecoach',
    'snapchat' => 'acmecoach',
    'threads' => 'acmecoach',
    'discord' => 'acmecoach',
    'reddit' => 'acmecoach',
    'telegram' => 'acmecoach',
    'whatsapp' => '61412345678',
    'fresha' => 'acmecoach',
    'booksy' => 'acmecoach',
    'timely' => 'acmecoach',
    'calendly' => 'acmecoach',
    'square' => 'acmecoach',
    'stan' => 'acmecoach',
    'skool' => 'acmecoach',
    'kajabi' => 'acmecoach',
    'circle' => 'acmecoach',
    'eventbrite' => 'acmecoach',
    'humanitix' => 'acmecoach',
    'luma' => 'acmecoach',
    'partiful' => 'acmecoach',
    'ticketmaster' => 'acmecoach',
    'apple_podcasts' => '123456',
    'substack' => 'acmecoach',
    'bandcamp' => 'acmecoach',
    'patreon' => 'acmecoach',
    'gumroad' => 'acmecoach',
    'medium' => 'acmecoach',
    'vimeo' => 'acmecoach',
    'twitch' => 'acmecoach',
    'kick' => 'acmecoach',
];

/** The 8 deterministic mutations applied to each platform's canonical URL. */
const CORPUS_MUTATIONS = ['as_is', 'http', 'www', 'uppercase_host', 'trailing_slash', 'trailing_dot_fqdn', 'utm', 'fragment'];

// Captured from the FIXED tree; the ~8 rows that differ from the PRE-FIX
// tree (linkedin /company/, spotify /artist/, as_is/http/trailing_slash/utm
// mutations) were verified via a temporary cp-based revert — see the
// implementation report for the red-before/green-after diff.
const CORPUS_BASELINE = [
    'apple_podcasts|http://podcasts.apple.com/us/podcast/id123456 => https://podcasts.apple.com/us/podcast/id123456|NULL',
    'apple_podcasts|https://PODCASTS.APPLE.COM/us/podcast/id123456 => https://podcasts.apple.com/us/podcast/id123456|NULL',
    'apple_podcasts|https://podcasts.apple.com./us/podcast/id123456 => https://podcasts.apple.com/us/podcast/id123456|NULL',
    'apple_podcasts|https://podcasts.apple.com/gb/podcast/acme-show/id123456 => https://podcasts.apple.com/us/podcast/id123456|123456',
    'apple_podcasts|https://podcasts.apple.com/us/podcast/id123456 => https://podcasts.apple.com/us/podcast/id123456|NULL',
    'apple_podcasts|https://podcasts.apple.com/us/podcast/id123456#frag => https://podcasts.apple.com/us/podcast/id123456#frag|NULL',
    'apple_podcasts|https://podcasts.apple.com/us/podcast/id123456/ => https://podcasts.apple.com/us/podcast/id123456/|NULL',
    'apple_podcasts|https://podcasts.apple.com/us/podcast/id123456?utm_source=x&utm_medium=y => https://podcasts.apple.com/us/podcast/id123456?utm_source=x&utm_medium=y|NULL',
    'apple_podcasts|https://www.podcasts.apple.com/us/podcast/id123456 => THROW',
    'bandcamp|http://acmecoach.bandcamp.com/ => https://acmecoach.bandcamp.com/|acmecoach',
    'bandcamp|https://ACMECOACH.BANDCAMP.COM/ => https://acmecoach.bandcamp.com/|acmecoach',
    'bandcamp|https://acmecoach.bandcamp.com./ => https://acmecoach.bandcamp.com/|acmecoach',
    'bandcamp|https://acmecoach.bandcamp.com/ => https://acmecoach.bandcamp.com/|acmecoach',
    'bandcamp|https://acmecoach.bandcamp.com/ => https://acmecoach.bandcamp.com/|acmecoach',
    'bandcamp|https://acmecoach.bandcamp.com/#frag => https://acmecoach.bandcamp.com/#frag|NULL',
    'bandcamp|https://acmecoach.bandcamp.com/?utm_source=x&utm_medium=y => https://acmecoach.bandcamp.com/?utm_source=x&utm_medium=y|NULL',
    'bandcamp|https://www.acmecoach.bandcamp.com/ => https://www.bandcamp.com/|www',
    'booksy|http://booksy.com/en-us/acmecoach => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://BOOKSY.COM/en-us/acmecoach => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://booksy.com./en-us/acmecoach => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://booksy.com/en-us/acmecoach => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://booksy.com/en-us/acmecoach#frag => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://booksy.com/en-us/acmecoach/ => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://booksy.com/en-us/acmecoach?utm_source=x&utm_medium=y => https://booksy.com/en-us/acmecoach|acmecoach',
    'booksy|https://booksy.com/fr-fr/12345_acme-salon => https://booksy.com/en-us/12345_acme-salon|12345_acme-salon',
    'booksy|https://www.booksy.com/en-us/acmecoach => https://booksy.com/en-us/acmecoach|acmecoach',
    'calendly|http://calendly.com/acmecoach => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://CALENDLY.COM/acmecoach => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://calendly.com./acmecoach => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://calendly.com/acmecoach => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://calendly.com/acmecoach#frag => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://calendly.com/acmecoach/ => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://calendly.com/acmecoach?utm_source=x&utm_medium=y => https://calendly.com/acmecoach|acmecoach',
    'calendly|https://www.calendly.com/acmecoach => https://calendly.com/acmecoach|acmecoach',
    'circle|http://acmecoach.circle.so/ => https://acmecoach.circle.so/|acmecoach',
    'circle|https://ACMECOACH.CIRCLE.SO/ => https://acmecoach.circle.so/|acmecoach',
    'circle|https://acmecoach.circle.so./ => https://acmecoach.circle.so/|acmecoach',
    'circle|https://acmecoach.circle.so/ => https://acmecoach.circle.so/|acmecoach',
    'circle|https://acmecoach.circle.so/ => https://acmecoach.circle.so/|acmecoach',
    'circle|https://acmecoach.circle.so/#frag => https://acmecoach.circle.so/#frag|NULL',
    'circle|https://acmecoach.circle.so/?utm_source=x&utm_medium=y => https://acmecoach.circle.so/?utm_source=x&utm_medium=y|NULL',
    'circle|https://www.acmecoach.circle.so/ => https://www.circle.so/|www',
    'discord|http://discord.gg/acmecoach => https://discord.gg/acmecoach|acmecoach',
    'discord|https://DISCORD.GG/acmecoach => https://discord.gg/acmecoach|acmecoach',
    'discord|https://discord.gg./acmecoach => https://discord.gg/acmecoach|acmecoach',
    'discord|https://discord.gg/acmecoach => https://discord.gg/acmecoach|acmecoach',
    'discord|https://discord.gg/acmecoach#frag => https://discord.gg/acmecoach|acmecoach',
    'discord|https://discord.gg/acmecoach/ => https://discord.gg/acmecoach|acmecoach',
    'discord|https://discord.gg/acmecoach?utm_source=x&utm_medium=y => https://discord.gg/acmecoach|acmecoach',
    'discord|https://www.discord.gg/acmecoach => THROW',
    'eventbrite|http://eventbrite.com/o/acmecoach => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://EVENTBRITE.COM/o/acmecoach => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://eventbrite.com./o/acmecoach => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://eventbrite.com/o/acmecoach => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://eventbrite.com/o/acmecoach#frag => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://eventbrite.com/o/acmecoach/ => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://eventbrite.com/o/acmecoach?utm_source=x&utm_medium=y => https://eventbrite.com/o/acmecoach|acmecoach',
    'eventbrite|https://www.eventbrite.com/o/acmecoach => https://eventbrite.com/o/acmecoach|acmecoach',
    'facebook|http://facebook.com/acmecoach => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://FACEBOOK.COM/acmecoach => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://facebook.com./acmecoach => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://facebook.com/acmecoach => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://facebook.com/acmecoach#frag => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://facebook.com/acmecoach/ => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://facebook.com/acmecoach?utm_source=x&utm_medium=y => https://facebook.com/acmecoach|acmecoach',
    'facebook|https://www.facebook.com/acmecoach => https://facebook.com/acmecoach|acmecoach',
    'fresha|http://fresha.com/a/acmecoach => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://FRESHA.COM/a/acmecoach => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://fresha.com./a/acmecoach => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://fresha.com/a/acmecoach => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://fresha.com/a/acmecoach#frag => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://fresha.com/a/acmecoach/ => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://fresha.com/a/acmecoach?utm_source=x&utm_medium=y => https://fresha.com/a/acmecoach|acmecoach',
    'fresha|https://www.fresha.com/a/acmecoach => https://fresha.com/a/acmecoach|acmecoach',
    'gumroad|http://gumroad.com/acmecoach => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://GUMROAD.COM/acmecoach => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://gumroad.com./acmecoach => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://gumroad.com/acmecoach => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://gumroad.com/acmecoach#frag => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://gumroad.com/acmecoach/ => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://gumroad.com/acmecoach?utm_source=x&utm_medium=y => https://gumroad.com/acmecoach|acmecoach',
    'gumroad|https://www.gumroad.com/acmecoach => https://gumroad.com/acmecoach|acmecoach',
    'humanitix|http://humanitix.com/host/acmecoach => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://HUMANITIX.COM/host/acmecoach => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://humanitix.com./host/acmecoach => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://humanitix.com/host/acmecoach => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://humanitix.com/host/acmecoach#frag => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://humanitix.com/host/acmecoach/ => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://humanitix.com/host/acmecoach?utm_source=x&utm_medium=y => https://humanitix.com/host/acmecoach|acmecoach',
    'humanitix|https://www.humanitix.com/host/acmecoach => https://humanitix.com/host/acmecoach|acmecoach',
    'instagram|http://instagram.com/acmecoach => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://INSTAGRAM.COM/acmecoach => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://evil-lookalike.example/acmecoach => THROW',
    'instagram|https://instagram.com./acmecoach => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://instagram.com/acmecoach => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://instagram.com/acmecoach#frag => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://instagram.com/acmecoach/ => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://instagram.com/acmecoach?utm_source=x&utm_medium=y => https://instagram.com/acmecoach|acmecoach',
    'instagram|https://instagram.com/p/abc123 => https://instagram.com/p/abc123|NULL',
    'instagram|https://www.instagram.com/acmecoach => https://instagram.com/acmecoach|acmecoach',
    'instagram|not a url at all => THROW',
    'kajabi|http://acmecoach.mykajabi.com/ => https://acmecoach.mykajabi.com/|acmecoach',
    'kajabi|https://ACMECOACH.MYKAJABI.COM/ => https://acmecoach.mykajabi.com/|acmecoach',
    'kajabi|https://acmecoach.mykajabi.com./ => https://acmecoach.mykajabi.com/|acmecoach',
    'kajabi|https://acmecoach.mykajabi.com/ => https://acmecoach.mykajabi.com/|acmecoach',
    'kajabi|https://acmecoach.mykajabi.com/ => https://acmecoach.mykajabi.com/|acmecoach',
    'kajabi|https://acmecoach.mykajabi.com/#frag => https://acmecoach.mykajabi.com/#frag|NULL',
    'kajabi|https://acmecoach.mykajabi.com/?utm_source=x&utm_medium=y => https://acmecoach.mykajabi.com/?utm_source=x&utm_medium=y|NULL',
    'kajabi|https://www.acmecoach.mykajabi.com/ => https://www.mykajabi.com/|www',
    'kick|http://kick.com/acmecoach => https://kick.com/acmecoach|acmecoach',
    'kick|https://KICK.COM/acmecoach => https://kick.com/acmecoach|acmecoach',
    'kick|https://kick.com./acmecoach => https://kick.com/acmecoach|acmecoach',
    'kick|https://kick.com/acmecoach => https://kick.com/acmecoach|acmecoach',
    'kick|https://kick.com/acmecoach#frag => https://kick.com/acmecoach|acmecoach',
    'kick|https://kick.com/acmecoach/ => https://kick.com/acmecoach|acmecoach',
    'kick|https://kick.com/acmecoach?utm_source=x&utm_medium=y => https://kick.com/acmecoach|acmecoach',
    'kick|https://www.kick.com/acmecoach => https://kick.com/acmecoach|acmecoach',
    'linkedin|http://linkedin.com/in/acmecoach => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|http://www.linkedin.com/company/acme-corp => https://linkedin.com/company/acme-corp|acme-corp',
    'linkedin|https://LINKEDIN.COM/in/acmecoach => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://linkedin.com./in/acmecoach => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://linkedin.com/in/acmecoach => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://linkedin.com/in/acmecoach#frag => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://linkedin.com/in/acmecoach/ => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://linkedin.com/in/acmecoach?utm_source=x&utm_medium=y => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://www.linkedin.com/company/acme-corp => https://linkedin.com/company/acme-corp|acme-corp',
    'linkedin|https://www.linkedin.com/company/acme-corp/ => https://linkedin.com/company/acme-corp|acme-corp',
    'linkedin|https://www.linkedin.com/company/acme-corp?utm_source=x&utm_medium=y => https://linkedin.com/company/acme-corp|acme-corp',
    'linkedin|https://www.linkedin.com/in/acmecoach => https://linkedin.com/in/acmecoach|acmecoach',
    'linkedin|https://www.linkedin.com/pub/joshhunter => https://www.linkedin.com/pub/joshhunter|NULL',
    'linkedin|https://www.linkedin.com/school/acme-university => https://www.linkedin.com/school/acme-university|NULL',
    'linkedin|https://www.www.linkedin.com/company/acme-corp => THROW',
    'luma|http://lu.ma/acmecoach => https://lu.ma/acmecoach|acmecoach',
    'luma|https://LU.MA/acmecoach => https://lu.ma/acmecoach|acmecoach',
    'luma|https://lu.ma./acmecoach => https://lu.ma/acmecoach|acmecoach',
    'luma|https://lu.ma/acmecoach => https://lu.ma/acmecoach|acmecoach',
    'luma|https://lu.ma/acmecoach#frag => https://lu.ma/acmecoach|acmecoach',
    'luma|https://lu.ma/acmecoach/ => https://lu.ma/acmecoach|acmecoach',
    'luma|https://lu.ma/acmecoach?utm_source=x&utm_medium=y => https://lu.ma/acmecoach|acmecoach',
    'luma|https://www.lu.ma/acmecoach => https://lu.ma/acmecoach|acmecoach',
    'medium|http://medium.com/@acmecoach => https://medium.com/@acmecoach|acmecoach',
    'medium|https://MEDIUM.COM/@acmecoach => https://medium.com/@acmecoach|acmecoach',
    'medium|https://medium.com./@acmecoach => https://medium.com/@acmecoach|acmecoach',
    'medium|https://medium.com/@acmecoach => https://medium.com/@acmecoach|acmecoach',
    'medium|https://medium.com/@acmecoach#frag => https://medium.com/@acmecoach|acmecoach',
    'medium|https://medium.com/@acmecoach/ => https://medium.com/@acmecoach|acmecoach',
    'medium|https://medium.com/@acmecoach?utm_source=x&utm_medium=y => https://medium.com/@acmecoach|acmecoach',
    'medium|https://www.medium.com/@acmecoach => https://medium.com/@acmecoach|acmecoach',
    'partiful|http://partiful.com/u/acmecoach => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://PARTIFUL.COM/u/acmecoach => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://partiful.com./u/acmecoach => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://partiful.com/u/acmecoach => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://partiful.com/u/acmecoach#frag => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://partiful.com/u/acmecoach/ => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://partiful.com/u/acmecoach?utm_source=x&utm_medium=y => https://partiful.com/u/acmecoach|acmecoach',
    'partiful|https://www.partiful.com/u/acmecoach => https://partiful.com/u/acmecoach|acmecoach',
    'patreon|http://patreon.com/acmecoach => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://PATREON.COM/acmecoach => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://patreon.com./acmecoach => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://patreon.com/acmecoach => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://patreon.com/acmecoach#frag => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://patreon.com/acmecoach/ => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://patreon.com/acmecoach?utm_source=x&utm_medium=y => https://patreon.com/acmecoach|acmecoach',
    'patreon|https://www.patreon.com/acmecoach => https://patreon.com/acmecoach|acmecoach',
    'reddit|http://reddit.com/u/acmecoach => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://REDDIT.COM/u/acmecoach => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://reddit.com./u/acmecoach => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://reddit.com/r/acmecoach => https://reddit.com/r/acmecoach|NULL',
    'reddit|https://reddit.com/u/acmecoach => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://reddit.com/u/acmecoach#frag => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://reddit.com/u/acmecoach/ => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://reddit.com/u/acmecoach?utm_source=x&utm_medium=y => https://reddit.com/u/acmecoach|acmecoach',
    'reddit|https://www.reddit.com/u/acmecoach => https://reddit.com/u/acmecoach|acmecoach',
    'skool|http://skool.com/acmecoach => https://skool.com/acmecoach|acmecoach',
    'skool|https://SKOOL.COM/acmecoach => https://skool.com/acmecoach|acmecoach',
    'skool|https://skool.com./acmecoach => https://skool.com/acmecoach|acmecoach',
    'skool|https://skool.com/acmecoach => https://skool.com/acmecoach|acmecoach',
    'skool|https://skool.com/acmecoach#frag => https://skool.com/acmecoach|acmecoach',
    'skool|https://skool.com/acmecoach/ => https://skool.com/acmecoach|acmecoach',
    'skool|https://skool.com/acmecoach?utm_source=x&utm_medium=y => https://skool.com/acmecoach|acmecoach',
    'skool|https://www.skool.com/acmecoach => https://skool.com/acmecoach|acmecoach',
    'snapchat|http://snapchat.com/add/acmecoach => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://SNAPCHAT.COM/add/acmecoach => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://snapchat.com./add/acmecoach => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://snapchat.com/add/acmecoach => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://snapchat.com/add/acmecoach#frag => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://snapchat.com/add/acmecoach/ => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://snapchat.com/add/acmecoach?utm_source=x&utm_medium=y => https://snapchat.com/add/acmecoach|acmecoach',
    'snapchat|https://www.snapchat.com/add/acmecoach => https://snapchat.com/add/acmecoach|acmecoach',
    'soundcloud|http://soundcloud.com/acmecoach => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://SOUNDCLOUD.COM/acmecoach => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://soundcloud.com./acmecoach => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://soundcloud.com/acmecoach => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://soundcloud.com/acmecoach#frag => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://soundcloud.com/acmecoach/ => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://soundcloud.com/acmecoach?utm_source=x&utm_medium=y => https://soundcloud.com/acmecoach|acmecoach',
    'soundcloud|https://www.soundcloud.com/acmecoach => https://soundcloud.com/acmecoach|acmecoach',
    'spotify|http://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4 => https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4|3TVXtAsR1Inumwj472S9r4',
    'spotify|http://open.spotify.com/user/acmecoach => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://OPEN.SPOTIFY.COM/user/acmecoach => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://open.spotify.com./user/acmecoach => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4 => https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4|3TVXtAsR1Inumwj472S9r4',
    'spotify|https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4/ => https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4|3TVXtAsR1Inumwj472S9r4',
    'spotify|https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4?utm_source=x&utm_medium=y => https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4|3TVXtAsR1Inumwj472S9r4',
    'spotify|https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M => https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M|NULL',
    'spotify|https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk => https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk|NULL',
    'spotify|https://open.spotify.com/user/acmecoach => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://open.spotify.com/user/acmecoach#frag => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://open.spotify.com/user/acmecoach/ => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://open.spotify.com/user/acmecoach?utm_source=x&utm_medium=y => https://open.spotify.com/user/acmecoach|acmecoach',
    'spotify|https://www.open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4 => THROW',
    'spotify|https://www.open.spotify.com/user/acmecoach => THROW',
    'square|http://book.squareup.com/appointments/acmecoach => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://BOOK.SQUAREUP.COM/appointments/acmecoach => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://book.squareup.com./appointments/acmecoach => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://book.squareup.com/appointments/acmecoach => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://book.squareup.com/appointments/acmecoach#frag => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://book.squareup.com/appointments/acmecoach/ => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://book.squareup.com/appointments/acmecoach?utm_source=x&utm_medium=y => https://book.squareup.com/appointments/acmecoach|acmecoach',
    'square|https://www.book.squareup.com/appointments/acmecoach => THROW',
    'stan|http://stan.store/acmecoach => https://stan.store/acmecoach|acmecoach',
    'stan|https://STAN.STORE/acmecoach => https://stan.store/acmecoach|acmecoach',
    'stan|https://stan.store./acmecoach => https://stan.store/acmecoach|acmecoach',
    'stan|https://stan.store/acmecoach => https://stan.store/acmecoach|acmecoach',
    'stan|https://stan.store/acmecoach#frag => https://stan.store/acmecoach|acmecoach',
    'stan|https://stan.store/acmecoach/ => https://stan.store/acmecoach|acmecoach',
    'stan|https://stan.store/acmecoach?utm_source=x&utm_medium=y => https://stan.store/acmecoach|acmecoach',
    'stan|https://www.stan.store/acmecoach => https://stan.store/acmecoach|acmecoach',
    'substack|http://acmecoach.substack.com/ => https://acmecoach.substack.com/|acmecoach',
    'substack|https://ACMECOACH.SUBSTACK.COM/ => https://acmecoach.substack.com/|acmecoach',
    'substack|https://acmecoach.substack.com./ => https://acmecoach.substack.com/|acmecoach',
    'substack|https://acmecoach.substack.com/ => https://acmecoach.substack.com/|acmecoach',
    'substack|https://acmecoach.substack.com/ => https://acmecoach.substack.com/|acmecoach',
    'substack|https://acmecoach.substack.com/#frag => https://acmecoach.substack.com/#frag|NULL',
    'substack|https://acmecoach.substack.com/?utm_source=x&utm_medium=y => https://acmecoach.substack.com/?utm_source=x&utm_medium=y|NULL',
    'substack|https://www.acmecoach.substack.com/ => https://www.substack.com/|www',
    'telegram|http://t.me/acmecoach => https://t.me/acmecoach|acmecoach',
    'telegram|https://T.ME/acmecoach => https://t.me/acmecoach|acmecoach',
    'telegram|https://t.me./acmecoach => https://t.me/acmecoach|acmecoach',
    'telegram|https://t.me/acmecoach => https://t.me/acmecoach|acmecoach',
    'telegram|https://t.me/acmecoach#frag => https://t.me/acmecoach|acmecoach',
    'telegram|https://t.me/acmecoach/ => https://t.me/acmecoach|acmecoach',
    'telegram|https://t.me/acmecoach?utm_source=x&utm_medium=y => https://t.me/acmecoach|acmecoach',
    'telegram|https://www.t.me/acmecoach => THROW',
    'threads|http://threads.net/@acmecoach => https://threads.net/@acmecoach|acmecoach',
    'threads|https://THREADS.NET/@acmecoach => https://threads.net/@acmecoach|acmecoach',
    'threads|https://threads.net./@acmecoach => https://threads.net/@acmecoach|acmecoach',
    'threads|https://threads.net/@acmecoach => https://threads.net/@acmecoach|acmecoach',
    'threads|https://threads.net/@acmecoach#frag => https://threads.net/@acmecoach|acmecoach',
    'threads|https://threads.net/@acmecoach/ => https://threads.net/@acmecoach|acmecoach',
    'threads|https://threads.net/@acmecoach?utm_source=x&utm_medium=y => https://threads.net/@acmecoach|acmecoach',
    'threads|https://www.threads.net/@acmecoach => https://threads.net/@acmecoach|acmecoach',
    'ticketmaster|http://ticketmaster.com/acmecoach => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://TICKETMASTER.COM/acmecoach => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://ticketmaster.com./acmecoach => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://ticketmaster.com/acmecoach => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://ticketmaster.com/acmecoach#frag => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://ticketmaster.com/acmecoach/ => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://ticketmaster.com/acmecoach?utm_source=x&utm_medium=y => https://ticketmaster.com/acmecoach|acmecoach',
    'ticketmaster|https://www.ticketmaster.com/acmecoach => https://ticketmaster.com/acmecoach|acmecoach',
    'tiktok|http://tiktok.com/@acmecoach => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://TIKTOK.COM/@acmecoach => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://tiktok.com./@acmecoach => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://tiktok.com/@acmecoach => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://tiktok.com/@acmecoach#frag => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://tiktok.com/@acmecoach/ => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://tiktok.com/@acmecoach?utm_source=x&utm_medium=y => https://tiktok.com/@acmecoach|acmecoach',
    'tiktok|https://www.tiktok.com/@acmecoach => https://tiktok.com/@acmecoach|acmecoach',
    'timely|http://book.gettimely.com/book/acmecoach => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://BOOK.GETTIMELY.COM/book/acmecoach => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://book.gettimely.com./book/acmecoach => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://book.gettimely.com/book/acmecoach => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://book.gettimely.com/book/acmecoach#frag => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://book.gettimely.com/book/acmecoach/ => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://book.gettimely.com/book/acmecoach?utm_source=x&utm_medium=y => https://book.gettimely.com/book/acmecoach|acmecoach',
    'timely|https://www.book.gettimely.com/book/acmecoach => THROW',
    'twitch|http://twitch.tv/acmecoach => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://TWITCH.TV/acmecoach => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://twitch.tv./acmecoach => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://twitch.tv/acmecoach => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://twitch.tv/acmecoach#frag => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://twitch.tv/acmecoach/ => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://twitch.tv/acmecoach?utm_source=x&utm_medium=y => https://twitch.tv/acmecoach|acmecoach',
    'twitch|https://www.twitch.tv/acmecoach => https://twitch.tv/acmecoach|acmecoach',
    'vimeo|http://vimeo.com/acmecoach => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://VIMEO.COM/acmecoach => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://vimeo.com./acmecoach => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://vimeo.com/acmecoach => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://vimeo.com/acmecoach#frag => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://vimeo.com/acmecoach/ => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://vimeo.com/acmecoach?utm_source=x&utm_medium=y => https://vimeo.com/acmecoach|acmecoach',
    'vimeo|https://www.vimeo.com/acmecoach => https://vimeo.com/acmecoach|acmecoach',
    'whatsapp|http://wa.me/61412345678 => https://wa.me/61412345678|61412345678',
    'whatsapp|https://WA.ME/61412345678 => https://wa.me/61412345678|61412345678',
    'whatsapp|https://wa.me./61412345678 => https://wa.me/61412345678|61412345678',
    'whatsapp|https://wa.me/61412345678 => https://wa.me/61412345678|61412345678',
    'whatsapp|https://wa.me/61412345678#frag => https://wa.me/61412345678|61412345678',
    'whatsapp|https://wa.me/61412345678/ => https://wa.me/61412345678|61412345678',
    'whatsapp|https://wa.me/61412345678?utm_source=x&utm_medium=y => https://wa.me/61412345678|61412345678',
    'whatsapp|https://www.wa.me/61412345678 => THROW',
    'x|http://x.com/acmecoach => https://x.com/acmecoach|acmecoach',
    'x|https://X.COM/acmecoach => https://x.com/acmecoach|acmecoach',
    'x|https://www.x.com/acmecoach => https://x.com/acmecoach|acmecoach',
    'x|https://x.com./acmecoach => https://x.com/acmecoach|acmecoach',
    'x|https://x.com/acmecoach => https://x.com/acmecoach|acmecoach',
    'x|https://x.com/acmecoach#frag => https://x.com/acmecoach|acmecoach',
    'x|https://x.com/acmecoach/ => https://x.com/acmecoach|acmecoach',
    'x|https://x.com/acmecoach?utm_source=x&utm_medium=y => https://x.com/acmecoach|acmecoach',
    'youtube|http://youtube.com/@acmecoach => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://YOUTUBE.COM/@acmecoach => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://www.youtube.com/@acmecoach => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://youtube.com./@acmecoach => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://youtube.com/@acmecoach => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://youtube.com/@acmecoach#frag => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://youtube.com/@acmecoach/ => https://youtube.com/@acmecoach|acmecoach',
    'youtube|https://youtube.com/@acmecoach?utm_source=x&utm_medium=y => https://youtube.com/@acmecoach|acmecoach',
];

/**
 * Extra inputs hitting alternative extractor branches and fallthrough paths
 * that a single canonical-URL-per-platform pass would never reach: the
 * linkedin/spotify shape branches this fix targets (5 mutations each — the
 * ~10 rows the fix actually changes), other platforms' alternative branches
 * (booksy locale, apple_podcasts country+slug), deep-link fallthroughs, and
 * the generic wrong-host / malformed-URL error paths.
 */
function corpusExtraInputs(): array
{
    $shapeMutations = ['as_is', 'http', 'www', 'trailing_slash', 'utm'];
    $extra = [];
    foreach (mutateUrlSet('https://www.linkedin.com/company/acme-corp', $shapeMutations) as $url) {
        $extra[] = ['linkedin', $url];
    }
    foreach (mutateUrlSet('https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4', $shapeMutations) as $url) {
        $extra[] = ['spotify', $url];
    }

    return array_merge($extra, [
        ['linkedin', 'https://www.linkedin.com/school/acme-university'],
        ['linkedin', 'https://www.linkedin.com/pub/joshhunter'],
        ['spotify', 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M'],
        ['spotify', 'https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk'],
        ['booksy', 'https://booksy.com/fr-fr/12345_acme-salon'],
        ['apple_podcasts', 'https://podcasts.apple.com/gb/podcast/acme-show/id123456'],
        ['instagram', 'https://instagram.com/p/abc123'],
        ['reddit', 'https://reddit.com/r/acmecoach'],
        ['instagram', 'https://evil-lookalike.example/acmecoach'],
        ['instagram', 'not a url at all'],
    ]);
}

/** @return list<string> */
function mutateUrlSet(string $url, array $mutations): array
{
    return array_map(fn (string $m): string => mutateUrl($url, $m), $mutations);
}

function mutateUrl(string $url, string $mutation): string
{
    $host = parse_url($url, PHP_URL_HOST) ?? '';

    return match ($mutation) {
        'as_is' => $url,
        'http' => preg_replace('#^https://#', 'http://', $url, 1),
        'www' => preg_replace('#^(https?://)#', '$1www.', $url, 1),
        'uppercase_host' => $host === '' ? $url : str_replace('://'.$host, '://'.strtoupper($host), $url),
        'trailing_slash' => rtrim($url, '/').'/',
        'trailing_dot_fqdn' => $host === '' ? $url : str_replace('://'.$host, '://'.$host.'.', $url),
        'utm' => $url.(str_contains($url, '?') ? '&' : '?').'utm_source=x&utm_medium=y',
        'fragment' => $url.'#frag',
        default => throw new RuntimeException("unknown mutation: {$mutation}"),
    };
}

function corpusSignature(SocialLinkNormalizer $normalizer, string $platform, string $input): string
{
    try {
        $result = $normalizer->normalize($platform, null, $input);
        $handle = $result['handle'] ?? 'NULL';

        return "{$platform}|{$input} => {$result['url']}|{$handle}";
    } catch (InvalidArgumentException) {
        // Exception message text is user-facing prose that will churn —
        // excluded from the signature deliberately.
        return "{$platform}|{$input} => THROW";
    }
}

/** @return list<string> unsorted */
function generateCorpusLines(): array
{
    $normalizer = new SocialLinkNormalizer;
    $lines = [];

    foreach (SAMPLE_HANDLES as $platform => $handle) {
        $config = config("partna.social_platforms.{$platform}");
        $canonical = str_replace('{handle}', $handle, $config['url_template']);
        foreach (CORPUS_MUTATIONS as $mutation) {
            $lines[] = corpusSignature($normalizer, $platform, mutateUrl($canonical, $mutation));
        }
    }

    foreach (corpusExtraInputs() as [$platform, $input]) {
        $lines[] = corpusSignature($normalizer, $platform, $input);
    }

    return $lines;
}

it('SAMPLE_HANDLES covers every registered social platform (anti-drift lock)', function () {
    $sampled = array_keys(SAMPLE_HANDLES);
    sort($sampled);
    $configured = array_keys(config('partna.social_platforms'));
    sort($configured);

    expect($sampled)->toBe($configured);
});

it('generates a corpus of more than 200 signatures (volume guard)', function () {
    $lines = generateCorpusLines();

    expect(count($lines))->toBeGreaterThan(200);
    // A harness silently emitting zero rows must fail loudly, not vacuously
    // pass an empty-vs-empty baseline diff below.
    expect($lines)->not->toBeEmpty();
});

it('every path-mode platform has at least one non-null handle signature (catches an everything-became-null regression)', function () {
    $lines = generateCorpusLines();
    $pathModePlatforms = collect(config('partna.social_platforms'))
        ->filter(fn (array $c): bool => ($c['handle_location'] ?? 'path') === 'path')
        ->keys();

    expect($pathModePlatforms)->not->toBeEmpty();

    foreach ($pathModePlatforms as $platform) {
        $hasNonNullHandle = collect($lines)
            ->filter(fn (string $line): bool => str_starts_with($line, "{$platform}|"))
            ->contains(fn (string $line): bool => ! str_ends_with($line, '|NULL') && ! str_ends_with($line, '=> THROW'));

        expect($hasNonNullHandle)->toBeTrue("platform {$platform} produced no non-null handle across the whole corpus");
    }
});

it('matches the recorded baseline exactly — a lost/changed handle or URL shows as a diff line (SEM-1 differential)', function () {
    $lines = generateCorpusLines();
    sort($lines);

    expect(count($lines))->toBe(count(CORPUS_BASELINE));
    expect($lines)->toBe(CORPUS_BASELINE);
});
