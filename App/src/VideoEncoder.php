<?php

declare(strict_types=1);

namespace MyStash;

/**
 * Wraps `ffmpeg`/`ffprobe` for format checks and MP4/H.265 conversion.
 */
final class VideoEncoder
{
    public function __construct(
        private string $ffmpeg = 'ffmpeg',
        private string $ffprobe = 'ffprobe',
    ) {
    }

    /**
     * Returns the video codec name (e.g. "h264", "hevc") for $inputPath.
     */
    public function videoCodec(string $inputPath): ?string
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_name',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ];

        [$exitCode, $stdout] = $this->run($command);

        if ($exitCode !== 0) {
            return null;
        }

        $codec = trim($stdout);

        return $codec !== '' ? $codec : null;
    }

    public function isAlreadyMp4Hevc(string $inputPath): bool
    {
        return strtolower((string) pathinfo($inputPath, PATHINFO_EXTENSION)) === 'mp4'
            && $this->videoCodec($inputPath) === 'hevc';
    }

    public function durationSeconds(string $inputPath): ?float
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ];

        [$exitCode, $stdout] = $this->run($command);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return null;
        }

        return (float) trim($stdout);
    }

    /**
     * Whether the file has an audio stream at all. A filter graph that names
     * [0:a] fails outright on a silent video, so Trim has to know.
     */
    public function hasAudio(string $inputPath): bool
    {
        [$exitCode, $stdout] = $this->run([
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'a',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            $inputPath,
        ]);

        return $exitCode === 0 && trim($stdout) !== '';
    }

    public function videoHeight(string $inputPath): ?int
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=height',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $inputPath,
        ];

        [$exitCode, $stdout] = $this->run($command);

        if ($exitCode !== 0 || trim($stdout) === '') {
            return null;
        }

        return (int) trim($stdout);
    }

    /**
     * Frame rate and pixel size of the first video stream, for the Stats
     * screen's Inspect button.
     *
     * The frame rate is avg_frame_rate — what the file actually plays at —
     * falling back to r_frame_rate, which for variable-rate phone footage can
     * claim 90000/1 or similar and is only a last resort. Width and height are
     * as displayed: a phone clip stored landscape with a 90° rotation tag is
     * reported portrait, because that is the box the viewer sees.
     *
     * @return array{fps: ?float, width: ?int, height: ?int, codec: ?string}|null
     */
    public function probe(string $inputPath): ?array
    {
        [$exitCode, $stdout] = $this->run([
            $this->ffprobe,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=codec_name,width,height,avg_frame_rate,r_frame_rate:stream_side_data=rotation:stream_tags=rotate',
            '-of', 'json',
            $inputPath,
        ]);

        $stream = $exitCode === 0 ? (json_decode($stdout, true)['streams'][0] ?? null) : null;

        if (!is_array($stream)) {
            return null;
        }

        $fps = self::rate((string) ($stream['avg_frame_rate'] ?? ''))
            ?? self::rate((string) ($stream['r_frame_rate'] ?? ''));

        $width = isset($stream['width']) ? (int) $stream['width'] : null;
        $height = isset($stream['height']) ? (int) $stream['height'] : null;

        $rotation = (int) ($stream['tags']['rotate'] ?? 0);
        foreach ($stream['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                $rotation = (int) $side['rotation'];
            }
        }

        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        return [
            'fps' => $fps !== null ? round($fps, 3) : null,
            'width' => $width,
            'height' => $height,
            'codec' => isset($stream['codec_name']) ? (string) $stream['codec_name'] : null,
        ];
    }

    /**
     * "30000/1001" → 29.97. Null for ffprobe's "0/0" (unknown).
     */
    private static function rate(string $fraction): ?float
    {
        if (!preg_match('#^(\d+)/(\d+)$#', $fraction, $m) || (int) $m[2] === 0 || (int) $m[1] === 0) {
            return null;
        }

        return (int) $m[1] / (int) $m[2];
    }

    /**
     * Converts $inputPath to MP4/H.265 at $outputPath.
     *
     * With no $onProgress this is the plain blocking call it always was. Give
     * it one and it supervises ffmpeg instead: it asks for machine-readable
     * progress (`-progress pipe:1`, which emits `out_time_us=` as it goes —
     * far more reliable than scraping the human stderr banner), reports the
     * position against the duration, and kills a run that has stopped moving.
     *
     * The stall timer is deliberately *not* a total time limit. A 90-minute
     * video legitimately takes a long time to encode, and a ceiling short
     * enough to catch a wedged ffmpeg would murder a healthy one. What is
     * never normal is ffmpeg sitting at the same timestamp for minutes, so
     * that is what gets killed. $ceilingSeconds is only a last backstop.
     *
     * $videoFilter is an optional -vf chain (the Stats screen's 30fps and
     * 1920×1080 reductions, see VideoStats::filterFor()). Null converts the
     * video exactly as before.
     *
     * $filterGraph is the alternative for work one -vf chain cannot express:
     * a -filter_complex graph and the labelled outputs to -map (Trim, see
     * VideoTrim::filterGraph()). Its output is shorter than the input, so
     * $outputSeconds says how long it will be, or the bar would stop short.
     *
     * @param callable(float, float):void|null $onProgress (secondsDone, secondsTotal)
     * @param array{graph: string, maps: list<string>}|null $filterGraph
     */
    public function convertToMp4Hevc(
        string $inputPath,
        string $outputPath,
        ?callable $onProgress = null,
        int $stallSeconds = 180,
        int $ceilingSeconds = 21600,
        ?string $videoFilter = null,
        ?array $filterGraph = null,
        ?float $outputSeconds = null,
    ): bool {
        $maps = [];
        foreach ($filterGraph['maps'] ?? [] as $label) {
            array_push($maps, '-map', $label);
        }

        $command = [
            $this->ffmpeg,
            '-y',
            // Detached from any terminal: without this ffmpeg can block trying
            // to read the console for its interactive keys.
            '-nostdin',
            '-i', $inputPath,
            ...($videoFilter !== null ? ['-vf', $videoFilter] : []),
            ...($filterGraph !== null ? ['-filter_complex', $filterGraph['graph'], ...$maps] : []),
            '-c:v', 'libx265',
            '-c:a', 'aac',
            $outputPath,
        ];

        if ($onProgress === null) {
            return $this->run($command)[0] === 0;
        }

        // Known up front, so progress can be a percentage rather than a
        // spinner. 0.0 if ffprobe cannot say, and the caller renders
        // indeterminate rather than dividing by it.
        $total = $outputSeconds ?? $this->durationSeconds($inputPath) ?? 0.0;

        array_splice($command, -1, 0, ['-progress', 'pipe:1', '-nostats', '-loglevel', 'error']);

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $startedAt = time();
        $movedAt = time();
        $position = 0.0;
        $exitCode = 1;

        while (true) {
            $status = proc_get_status($process);

            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
            }

            // Drained and discarded: a full stderr pipe would block ffmpeg
            // itself, which would then look exactly like a stall.
            fread($pipes[2], 8192);

            while (($break = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $break));
                $buffer = substr($buffer, $break + 1);

                if (!str_starts_with($line, 'out_time_us=')) {
                    continue;
                }

                $microseconds = substr($line, strlen('out_time_us='));

                // ffmpeg reports N/A before the first frame is written.
                if (!ctype_digit($microseconds)) {
                    continue;
                }

                $seconds = ((int) $microseconds) / 1_000_000;

                if ($seconds > $position) {
                    $position = $seconds;
                    $movedAt = time();
                    $onProgress($position, $total);
                }
            }

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (time() - $movedAt > $stallSeconds || time() - $startedAt > $ceilingSeconds) {
                proc_terminate($process, 9);
                proc_close($process);

                // Half a file is not a conversion. The caller keeps the
                // original because it never overwrites it (StashCrypto::replace),
                // but leaving this behind would litter tmpfs.
                @unlink($outputPath);

                return false;
            }

            usleep(200_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $exitCode === 0;
    }

    /**
     * Extracts a single frame at $timestampSeconds as a JPEG.
     */
    public function extractFrame(string $inputPath, string $outputPath, float $timestampSeconds = 15.0): bool
    {
        $command = [
            $this->ffmpeg,
            '-y',
            '-ss', (string) $timestampSeconds,
            '-i', $inputPath,
            '-frames:v', '1',
            $outputPath,
        ];

        return $this->run($command)[0] === 0;
    }

    /**
     * Re-encodes any image ffmpeg can read into $outputPath's format, scaled to
     * fit within $maxEdge on its longest side. Used to normalise uploaded
     * creator avatars to PNG (see CreatorStore).
     */
    public function convertImage(string $inputPath, string $outputPath, int $maxEdge = 512): bool
    {
        $command = [
            $this->ffmpeg,
            '-y',
            '-i', $inputPath,
            // The cap is on the *longest* edge, so which dimension it binds
            // depends on the image: a portrait upload constrained on its width
            // came out taller than $maxEdge, which is neither what the docblock
            // promises nor a predictable file size. `min()` on the bound edge
            // and -2 on the other leaves an image already under the cap at its
            // own size rather than upscaling it.
            '-vf', "scale='if(gt(iw,ih),min({$maxEdge},iw),-2)':"
                . "'if(gt(iw,ih),-2,min({$maxEdge},ih))'",
            '-frames:v', '1',
            $outputPath,
        ];

        return $this->run($command)[0] === 0;
    }

    /**
     * Builds the silent preview clip: a timelapse that samples one frame every
     * $intervalSeconds across the whole video and plays them back at
     * $playbackFps, so hovering skims the entire video rather than showing one
     * continuous moment (Docs/SPECIFICATIONS.md §2.3).
     */
    public function buildPreviewClip(
        string $inputPath,
        string $outputPath,
        float $intervalSeconds = 15.0,
        int $playbackFps = 4,
    ): bool {
        // A fixed 15s stride assumes a video long enough to have 15s in it.
        // `fps=1/15` emits a frame per 15-second slot the input actually
        // spans, so a 5-second clip produced *no frames at all*, this returned
        // false, and ingestion then handed a path that did not exist to the
        // encrypter — a fatal on a plain upload of a short video. Below twice
        // the stride the interval is derived from the video instead, aiming at
        // eight frames, which is a skim rather than a single still.
        //
        // Only below that threshold: for anything longer the 15s stride is the
        // specified behaviour and stays exactly as it was.
        $duration = $this->durationSeconds($inputPath);

        if ($duration !== null && $duration > 0 && $duration < $intervalSeconds * 2) {
            // Eight frames where there is room for them, fewer where there is
            // not: at $playbackFps the clip is frames/fps seconds long, and a
            // preview that runs as long as the video it previews is not a
            // preview. Half the source is the ceiling, two frames the floor —
            // one frame is a still, and the tile already has one of those.
            $frames = (int) min(8, max(2, floor($duration * $playbackFps / 2)));
            $intervalSeconds = max(0.2, $duration / $frames);
        }

        // Done in two passes — sample the stills, then join them at the
        // playback rate. Re-timing in a single pass (setpts/-r) makes ffmpeg
        // drop most of the sampled frames.
        $framesDir = dirname($outputPath) . '/preview_frames_' . bin2hex(random_bytes(4));
        if (!mkdir($framesDir, 0700, true)) {
            return false;
        }

        try {
            $sampled = $this->run([
                $this->ffmpeg,
                '-y',
                '-i', $inputPath,
                '-vf', sprintf('fps=1/%s,scale=480:-2', $this->formatInterval($intervalSeconds)),
                '-an',
                "{$framesDir}/frame_%04d.jpg",
            ]);

            if ($sampled[0] !== 0 || glob("{$framesDir}/frame_*.jpg") === []) {
                return false;
            }

            return $this->run([
                $this->ffmpeg,
                '-y',
                '-framerate', (string) $playbackFps,
                '-i', "{$framesDir}/frame_%04d.jpg",
                '-an',
                '-c:v', 'libx264',
                '-crf', '30',
                '-pix_fmt', 'yuv420p',
                $outputPath,
            ])[0] === 0;
        } finally {
            array_map('unlink', glob("{$framesDir}/*") ?: []);
            rmdir($framesDir);
        }
    }

    private function formatInterval(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }

    /**
     * @return array{0: int, 1: string} [exitCode, stdout]
     */
    private function run(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start ffmpeg/ffprobe process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout];
    }
}
