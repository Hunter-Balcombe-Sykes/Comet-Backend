<?php

// Real, currently-live ITEM and PROFILE urls, run through the pure URL grammar
// in MediaPageReader. The pair is the point: the same platform serves both, and
// telling them apart is the whole job. Confuse them one way and pasting a
// channel silently saves "one video"; confuse them the other and pasting a
// video tells the person to go connect a platform they already have.
//
// `items` MUST classify — each with the platform, kind and canonical form
// recorded here, and a kind that maps to a real pool. `profiles` MUST NOT
// classify as items, and must still be NAMED, because the name is what turns a
// dead-end 422 into "connect TikTok to bring its content in automatically".
//
// Nothing here is fetched at test time; the grammar is pure. A page going dark
// makes a row stale documentation rather than a red build.
//
// Found by this corpus on 2026-09-03, on real URLs a generated one could not
// have produced: TikTok had no item arm at all (a pasted video was refused
// from the Watch pool outright) and no profile arm (a pasted TikTok channel
// got the generic error), and a Spotify PLAYLIST — not one track, so rightly
// refused by the Listen pool — was refused with no advice attached.

return [
    'items' => [
        ['url' => 'https://music.apple.com/us/album/bohemian-rhapsody-the-original-soundtrack/1434899831', 'shape' => 'apple_music.album', 'platform' => 'apple-music', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://music.apple.com/us/album/bohemian-rhapsody-the-original-soundtrack/1434899831'],
        ['url' => 'https://music.apple.com/us/album/random-access-memories/617154241', 'shape' => 'apple_music.album', 'platform' => 'apple-music', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://music.apple.com/us/album/random-access-memories/617154241'],
        ['url' => 'https://music.apple.com/us/album/bohemian-rhapsody/1440650428?i=1440650711', 'shape' => 'apple_music.song', 'platform' => 'apple-music', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://music.apple.com/us/album/bohemian-rhapsody/1440650428?i=1440650711'],
        ['url' => 'https://music.apple.com/us/album/get-lucky/617154241?i=617154366', 'shape' => 'apple_music.song', 'platform' => 'apple-music', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://music.apple.com/us/album/get-lucky/617154241?i=617154366'],
        ['url' => 'https://podcasts.apple.com/us/podcast/serial-s01-ep-1-the-alibi/id917918570?i=1000319686008', 'shape' => 'apple_podcasts.episode', 'platform' => 'apple-podcast', 'kind' => 'episode', 'pool' => 'listen', 'canonical' => 'https://podcasts.apple.com/us/podcast/serial-s01-ep-1-the-alibi/id917918570?i=1000319686008'],
        ['url' => 'https://podcasts.apple.com/us/podcast/the-daily/id1200361736?i=1000787630226', 'shape' => 'apple_podcasts.episode', 'platform' => 'apple-podcast', 'kind' => 'episode', 'pool' => 'listen', 'canonical' => 'https://podcasts.apple.com/us/podcast/the-daily/id1200361736?i=1000787630226'],
        ['url' => 'https://fleetfoxes.bandcamp.com/album/helplessness-blues', 'shape' => 'bandcamp.album', 'platform' => 'bandcamp', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://fleetfoxes.bandcamp.com/album/helplessness-blues'],
        ['url' => 'https://fleetfoxes.bandcamp.com/album/shore', 'shape' => 'bandcamp.album', 'platform' => 'bandcamp', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://fleetfoxes.bandcamp.com/album/shore'],
        ['url' => 'https://fleetfoxes.bandcamp.com/track/helplessness-blues', 'shape' => 'bandcamp.track', 'platform' => 'bandcamp', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://fleetfoxes.bandcamp.com/track/helplessness-blues'],
        ['url' => 'https://fleetfoxes.bandcamp.com/track/montezuma', 'shape' => 'bandcamp.track', 'platform' => 'bandcamp', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://fleetfoxes.bandcamp.com/track/montezuma'],
        ['url' => 'https://www.mixcloud.com/NTSRadio/boom-bip-2nd-september-2026/', 'shape' => 'mixcloud.show', 'platform' => 'mixcloud', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://www.mixcloud.com/NTSRadio/boom-bip-2nd-september-2026/'],
        ['url' => 'https://www.mixcloud.com/NTSRadio/los-hitters-1st-september-2026/', 'shape' => 'mixcloud.show', 'platform' => 'mixcloud', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://www.mixcloud.com/NTSRadio/los-hitters-1st-september-2026/'],
        ['url' => 'https://soundcloud.com/monstercat/pegboard-nerds-disconnected', 'shape' => 'soundcloud.track', 'platform' => 'soundcloud', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://soundcloud.com/monstercat/pegboard-nerds-disconnected'],
        ['url' => 'https://soundcloud.com/postmalone/white-iverson', 'shape' => 'soundcloud.track', 'platform' => 'soundcloud', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://soundcloud.com/postmalone/white-iverson'],
        ['url' => 'https://open.spotify.com/album/2ANVost0y2y52ema1E9xAZ', 'shape' => 'spotify.album', 'platform' => 'spotify', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/album/2ANVost0y2y52ema1E9xAZ'],
        ['url' => 'https://open.spotify.com/album/4m2880jivSbbyEGAKfITCa', 'shape' => 'spotify.album', 'platform' => 'spotify', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/album/4m2880jivSbbyEGAKfITCa'],
        ['url' => 'https://open.spotify.com/episode/0pxfS52EOgVZSeUsSV4ID3', 'shape' => 'spotify.episode', 'platform' => 'spotify', 'kind' => 'episode', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/episode/0pxfS52EOgVZSeUsSV4ID3'],
        ['url' => 'https://open.spotify.com/episode/5pvqUeIuNF05Izyy64SEyB', 'shape' => 'spotify.episode', 'platform' => 'spotify', 'kind' => 'episode', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/episode/5pvqUeIuNF05Izyy64SEyB'],
        ['url' => 'https://open.spotify.com/track/4PTG3Z6ehGkBFwjybzWkR8', 'shape' => 'spotify.track', 'platform' => 'spotify', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/track/4PTG3Z6ehGkBFwjybzWkR8'],
        ['url' => 'https://open.spotify.com/track/6l8GvAyoUZwWDgF1e4822w', 'shape' => 'spotify.track', 'platform' => 'spotify', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/track/6l8GvAyoUZwWDgF1e4822w'],
        ['url' => 'https://open.spotify.com/intl-de/track/4PTG3Z6ehGkBFwjybzWkR8', 'shape' => 'spotify.track_intl', 'platform' => 'spotify', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/track/4PTG3Z6ehGkBFwjybzWkR8'],
        ['url' => 'https://open.spotify.com/intl-fr/track/6l8GvAyoUZwWDgF1e4822w', 'shape' => 'spotify.track_intl', 'platform' => 'spotify', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://open.spotify.com/track/6l8GvAyoUZwWDgF1e4822w'],
        ['url' => 'https://tidal.com/browse/album/1781800', 'shape' => 'tidal.album', 'platform' => 'tidal', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://tidal.com/album/1781800'],
        ['url' => 'https://tidal.com/browse/album/20115556', 'shape' => 'tidal.album', 'platform' => 'tidal', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://tidal.com/album/20115556'],
        ['url' => 'https://tidal.com/browse/track/491206012', 'shape' => 'tidal.track', 'platform' => 'tidal', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://tidal.com/track/491206012'],
        ['url' => 'https://tidal.com/browse/track/534050211', 'shape' => 'tidal.track', 'platform' => 'tidal', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://tidal.com/track/534050211'],
        ['url' => 'https://www.tiktok.com/@nike/video/7599948408773725471', 'shape' => 'tiktok.video', 'platform' => 'tiktok', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.tiktok.com/@nike/video/7599948408773725471'],
        ['url' => 'https://www.tiktok.com/@nike/video/7647200302189251854', 'shape' => 'tiktok.video', 'platform' => 'tiktok', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.tiktok.com/@nike/video/7647200302189251854'],
        ['url' => 'https://clips.twitch.tv/BrightMoldyCodCharlietheUnicorn-1TKU83PuTIpJqhMs', 'shape' => 'twitch.clip', 'platform' => 'twitch', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://clips.twitch.tv/BrightMoldyCodCharlietheUnicorn-1TKU83PuTIpJqhMs'],
        ['url' => 'https://clips.twitch.tv/SparklingAbstruseMuleFunRun-q1EL4cY06So1D_D-', 'shape' => 'twitch.clip', 'platform' => 'twitch', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://clips.twitch.tv/SparklingAbstruseMuleFunRun-q1EL4cY06So1D_D-'],
        ['url' => 'https://www.twitch.tv/videos/2854348714', 'shape' => 'twitch.vod', 'platform' => 'twitch', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.twitch.tv/videos/2854348714'],
        ['url' => 'https://www.twitch.tv/videos/2858803970', 'shape' => 'twitch.vod', 'platform' => 'twitch', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.twitch.tv/videos/2858803970'],
        ['url' => 'https://vimeo.com/513177164', 'shape' => 'vimeo.video', 'platform' => 'vimeo', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://vimeo.com/513177164'],
        ['url' => 'https://vimeo.com/863362136', 'shape' => 'vimeo.video', 'platform' => 'vimeo', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://vimeo.com/863362136'],
        ['url' => 'https://vimeo.com/1056619122/f6ec47dac6', 'shape' => 'vimeo.video_unlisted', 'platform' => 'vimeo', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://vimeo.com/1056619122'],
        ['url' => 'https://vimeo.com/736144311/7c14d6ea56', 'shape' => 'vimeo.video_unlisted', 'platform' => 'vimeo', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://vimeo.com/736144311'],
        ['url' => 'https://www.youtube.com/live/0FBiyFpV__g', 'shape' => 'youtube.live', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=0FBiyFpV__g'],
        ['url' => 'https://www.youtube.com/live/iipR5yUp36o', 'shape' => 'youtube.live', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=iipR5yUp36o'],
        ['url' => 'https://www.youtube.com/shorts/5mU6SRS2Bxo', 'shape' => 'youtube.short', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=5mU6SRS2Bxo'],
        ['url' => 'https://www.youtube.com/shorts/LiH-P4rSkLI', 'shape' => 'youtube.short', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=LiH-P4rSkLI'],
        ['url' => 'https://www.youtube.com/watch?v=9bZkp7q19f0', 'shape' => 'youtube.watch', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=9bZkp7q19f0'],
        ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'shape' => 'youtube.watch', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['url' => 'https://youtu.be/9bZkp7q19f0', 'shape' => 'youtube.youtu_be', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=9bZkp7q19f0'],
        ['url' => 'https://youtu.be/dQw4w9WgXcQ', 'shape' => 'youtube.youtu_be', 'platform' => 'youtube', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['url' => 'https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'shape' => 'youtube_music.track', 'platform' => 'youtube-music', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
        ['url' => 'https://music.youtube.com/watch?v=uViueiV8fME', 'shape' => 'youtube_music.track', 'platform' => 'youtube-music', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://music.youtube.com/watch?v=uViueiV8fME'],
        // Beatport, Hypeddit, and (2026-09-04 second pass, closing the same
        // corpus's own "no concrete example URL" gap for the remaining
        // platforms from the same research) Audiomack, Deezer, Dailymotion,
        // Rumble, Feature.fm, Laylo, Linkfire — all real, live-verified URLs.
        ['url' => 'https://beatport.com/track/lockup/28901951', 'shape' => 'beatport.track', 'platform' => 'beatport', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://beatport.com/track/lockup/28901951'],
        ['url' => 'https://hypeddit.com/drezcrankit', 'shape' => 'hypeddit.slug', 'platform' => 'hypeddit', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://hypeddit.com/drezcrankit'],
        ['url' => 'https://hypeddit.com/ls41blnk/booyafreedownload', 'shape' => 'hypeddit.artist_track', 'platform' => 'hypeddit', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://hypeddit.com/ls41blnk/booyafreedownload'],
        ['url' => 'https://hypeddit.com/track/5frhke', 'shape' => 'hypeddit.track_id', 'platform' => 'hypeddit', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://hypeddit.com/track/5frhke'],
        ['url' => 'https://audiomack.com/rob49/song/in-the-club-3094751', 'shape' => 'audiomack.song', 'platform' => 'audiomack', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://audiomack.com/rob49/song/in-the-club-3094751'],
        ['url' => 'https://audiomack.com/wassamrbz/album/kodak-black-the-mixtape', 'shape' => 'audiomack.album', 'platform' => 'audiomack', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://audiomack.com/wassamrbz/album/kodak-black-the-mixtape'],
        ['url' => 'https://www.deezer.com/en/track/3273194641', 'shape' => 'deezer.track', 'platform' => 'deezer', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://www.deezer.com/track/3273194641'],
        ['url' => 'https://www.deezer.com/en/album/725636751', 'shape' => 'deezer.album', 'platform' => 'deezer', 'kind' => 'release', 'pool' => 'listen', 'canonical' => 'https://www.deezer.com/album/725636751'],
        ['url' => 'https://www.dailymotion.com/video/x7tgad0', 'shape' => 'dailymotion.video', 'platform' => 'dailymotion', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.dailymotion.com/video/x7tgad0'],
        ['url' => 'https://dai.ly/x7tgad0', 'shape' => 'dailymotion.short', 'platform' => 'dailymotion', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://www.dailymotion.com/video/x7tgad0'],
        ['url' => 'https://rumble.com/v2667bs-are-the-demons-of-darkness-just-the-monsters-of-our-imagination....html', 'shape' => 'rumble.video', 'platform' => 'rumble', 'kind' => 'video', 'pool' => 'watch', 'canonical' => 'https://rumble.com/v2667bs-are-the-demons-of-darkness-just-the-monsters-of-our-imagination....html'],
        ['url' => 'https://ffm.to/picture-perfect-jb', 'shape' => 'feature_fm.smartlink', 'platform' => 'feature_fm', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://ffm.to/picture-perfect-jb'],
        ['url' => 'https://laylo.com/thoughtprocess/vvepde', 'shape' => 'laylo.drop', 'platform' => 'laylo', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://laylo.com/thoughtprocess/vvepde'],
        ['url' => 'https://lnk.to/EverywhereWeGo', 'shape' => 'linkfire.smartlink', 'platform' => 'linkfire', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://lnk.to/everywherewego'],
        ['url' => 'https://tokio-hotel.lnk.to/2001', 'shape' => 'linkfire.branded_subdomain', 'platform' => 'linkfire', 'kind' => 'track', 'pool' => 'listen', 'canonical' => 'https://tokio-hotel.lnk.to/2001'],
    ],
    'profiles' => [
        ['url' => 'https://music.apple.com/us/artist/drake/271256', 'shape' => 'apple_music.artist', 'account' => 'Apple Music'],
        ['url' => 'https://music.apple.com/us/artist/taylor-swift/159260351', 'shape' => 'apple_music.artist', 'account' => 'Apple Music'],
        ['url' => 'https://podcasts.apple.com/us/podcast/the-joe-rogan-experience/id360084272', 'shape' => 'apple_podcasts.show', 'account' => 'Apple Podcasts'],
        ['url' => 'https://podcasts.apple.com/us/podcast/this-american-life/id201671138', 'shape' => 'apple_podcasts.show', 'account' => 'Apple Podcasts'],
        ['url' => 'https://godspeedyoublackemperor.bandcamp.com', 'shape' => 'bandcamp.artist', 'account' => 'Bandcamp'],
        ['url' => 'https://iamddb.bandcamp.com', 'shape' => 'bandcamp.artist', 'account' => 'Bandcamp'],
        ['url' => 'https://www.mixcloud.com/BoilerRoom/', 'shape' => 'mixcloud.profile', 'account' => 'Mixcloud'],
        ['url' => 'https://www.mixcloud.com/NTSRadio/', 'shape' => 'mixcloud.profile', 'account' => 'Mixcloud'],
        ['url' => 'https://soundcloud.com/octobersveryown', 'shape' => 'soundcloud.profile', 'account' => 'SoundCloud'],
        ['url' => 'https://soundcloud.com/skrillex', 'shape' => 'soundcloud.profile', 'account' => 'SoundCloud'],
        ['url' => 'https://open.spotify.com/artist/06HL4z0CvFAxyc27GXpf02', 'shape' => 'spotify.artist', 'account' => 'Spotify'],
        ['url' => 'https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4', 'shape' => 'spotify.artist', 'account' => 'Spotify'],
        ['url' => 'https://open.spotify.com/playlist/37i9dQZF1DX0XUsuxWHRQd', 'shape' => 'spotify.playlist', 'account' => 'Spotify'],
        ['url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M', 'shape' => 'spotify.playlist', 'account' => 'Spotify'],
        ['url' => 'https://open.spotify.com/show/2mTUnDkuKUkhiueKcVWoP0', 'shape' => 'spotify.show', 'account' => 'Spotify'],
        ['url' => 'https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk', 'shape' => 'spotify.show', 'account' => 'Spotify'],
        ['url' => 'https://tidal.com/artist/10665', 'shape' => 'tidal.artist', 'account' => 'Tidal'],
        ['url' => 'https://tidal.com/artist/1566', 'shape' => 'tidal.artist', 'account' => 'Tidal'],
        ['url' => 'https://www.tiktok.com/@duolingo', 'shape' => 'tiktok.profile', 'account' => 'TikTok'],
        ['url' => 'https://www.tiktok.com/@nike', 'shape' => 'tiktok.profile', 'account' => 'TikTok'],
        ['url' => 'https://www.twitch.tv/ninja', 'shape' => 'twitch.channel', 'account' => 'Twitch'],
        ['url' => 'https://www.twitch.tv/riotgames', 'shape' => 'twitch.channel', 'account' => 'Twitch'],
        ['url' => 'https://vimeo.com/framestore', 'shape' => 'vimeo.profile', 'account' => 'Vimeo'],
        ['url' => 'https://vimeo.com/staffpicks', 'shape' => 'vimeo.profile', 'account' => 'Vimeo'],
        ['url' => 'https://www.youtube.com/c/GoogleDevelopers', 'shape' => 'youtube.channel_c', 'account' => 'YouTube'],
        ['url' => 'https://www.youtube.com/c/NASA', 'shape' => 'youtube.channel_c', 'account' => 'YouTube'],
        ['url' => 'https://www.youtube.com/@MrBeast', 'shape' => 'youtube.channel_handle', 'account' => 'YouTube'],
        ['url' => 'https://www.youtube.com/@NASA', 'shape' => 'youtube.channel_handle', 'account' => 'YouTube'],
        ['url' => 'https://www.youtube.com/channel/UCLA_DiR1FfKNvjuUpBHmylQ', 'shape' => 'youtube.channel_id', 'account' => 'YouTube'],
        ['url' => 'https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', 'shape' => 'youtube.channel_id', 'account' => 'YouTube'],
        // 2026-09-04 overnight run (§1C/W4) — real, live-verified profile
        // pages for the same new platforms above. Hypeddit is deliberately
        // absent: it has no public account/profile page shape at all
        // (confirmed live — see MediaPageReader.php's hypeddit arm).
        ['url' => 'https://audiomack.com/rob49', 'shape' => 'audiomack.profile', 'account' => 'Audiomack'],
        ['url' => 'https://www.beatport.com/artist/art-department/150625', 'shape' => 'beatport.artist', 'account' => 'Beatport'],
        ['url' => 'https://www.deezer.com/en/artist/12945219', 'shape' => 'deezer.artist', 'account' => 'Deezer'],
        ['url' => 'https://www.dailymotion.com/dailymotionplayerdemo2', 'shape' => 'dailymotion.channel', 'account' => 'Dailymotion'],
        ['url' => 'https://rumble.com/user/MagiMou', 'shape' => 'rumble.user', 'account' => 'Rumble'],
        ['url' => 'https://rumble.com/c/OANN', 'shape' => 'rumble.channel', 'account' => 'Rumble'],
        ['url' => 'https://ffm.bio/fatherjohnmisty', 'shape' => 'feature_fm.bio', 'account' => 'Feature.fm'],
        ['url' => 'https://laylo.com/thoughtprocess', 'shape' => 'laylo.profile', 'account' => 'Laylo'],
        ['url' => 'https://bio.to/tolstoys', 'shape' => 'linkfire.bio', 'account' => 'Linkfire'],
    ],
];
