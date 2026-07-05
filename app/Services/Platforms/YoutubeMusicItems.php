<?php

namespace App\Services\Platforms;

// Feed videos → the music item shape: links land on music.youtube.com and each
// item carries the standard YouTube embed for inline playback. Shared by the
// YouTube Music connect/highlights strategies and YoutubeMusicFetch (refresh).
final class YoutubeMusicItems
{
    /**
     * @param  list<array{videoId:string, name:string, link:string, date:?string, thumbnail:string}>  $videos
     * @return list<array{itemId:string, name:string, thumbnail:string, link:string, date:?string, embedUrl:string}>
     */
    public static function map(array $videos): array
    {
        return array_map(fn (array $v) => [
            'itemId' => $v['videoId'],
            'name' => $v['name'],
            'thumbnail' => $v['thumbnail'],
            'link' => 'https://music.youtube.com/watch?v='.$v['videoId'],
            // YT Music uses the uploads feed, so the upload <published> doubles as
            // the release date — carried through for chronological sorting.
            'date' => $v['date'] ?? null,
            'embedUrl' => 'https://www.youtube.com/embed/'.$v['videoId'],
        ], $videos);
    }
}
