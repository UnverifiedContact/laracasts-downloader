<?php

namespace App\Vimeo;

use App\Utils\Utils;
use GuzzleHttp\Client;

class VimeoDownloader
{
    /** @var VimeoRepository */
    private $repository;

    /** @var Client */
    public $client;

    public function __construct()
    {
        $this->client = new Client;

        $this->repository = new VimeoRepository($this->client);
    }

    /**
     * @return bool
     */
    public function download($vimeoId, $filepath)
    {
        $video = $this->repository->get($vimeoId);

        $master = $this->repository->getMaster($video);

        $sources = [];
        $sources[] = $master->getVideoById($video->getVideoIdByQuality());
        $sources[] = $master->getAudio();

        $filenames = [];

        foreach ($sources as $source) {
            $filename = $master->getClipId().$source['extension'];
            $this->downloadSource(
                $master->resolveURL($source['base_url']),
                $source,
                $filename
            );
            $filenames[] = $filename;
        }

        return $this->mergeSources($filenames[0], $filenames[1], $filepath);
    }

    private function downloadSource($baseURL, $sourceData, $filepath)
    {
        file_put_contents($filepath, base64_decode((string) $sourceData['init_segment'], true));

        $segmentURLs = array_map(fn ($segment) => $baseURL.$segment['url'], $sourceData['segments']);

        $sizes = array_column($sourceData['segments'], 'size');

        $this->downloadSegments($segmentURLs, $filepath, $sizes);
    }

    private function downloadSegments($segmentURLs, $filepath, $sizes)
    {
        $type = str_contains((string) $filepath, 'm4v') ? 'video' : 'audio';
        Utils::writeln("Downloading $type...");

        $downloadedBytes = 0;

        $totalBytes = array_sum($sizes);

        foreach ($segmentURLs as $index => $segmentURL) {
            $this->client->request('GET', $segmentURL, [
                'sink' => fopen($filepath, 'a'),
                'progress' => fn ($total, $downloaded) => Utils::showProgressBar($downloaded + $downloadedBytes, $totalBytes),
            ]);

            $downloadedBytes += $sizes[$index];
        }
    }

    /**
     * @param  string  $videoPath
     * @param  string  $audioPath
     * @param  string  $outputPath
     * @return bool
     */
    private function mergeSources($videoPath, $audioPath, $outputPath)
    {
        $code = 0;
        $output = [];

        if (PHP_OS === 'WINNT') {
            $command = "ffmpeg -i \"$videoPath\" -i \"$audioPath\" -vcodec copy -acodec copy -strict -2 \"$outputPath\" 2> nul";
        } else {
            $command = "ffmpeg -i '$videoPath' -i '$audioPath' -vcodec copy -acodec copy -strict -2 '$outputPath' >/dev/null 2>&1";
        }

        exec($command, $output, $code);

        if ($code == 0) {
            unlink($videoPath);
            unlink($audioPath);

            return true;
        }

        return false;
    }
}
