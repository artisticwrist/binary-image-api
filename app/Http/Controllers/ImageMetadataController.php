<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;


class ImageMetadataController extends Controller
{
    private const MAX_EXIFTOOL_OUTPUT = 32 * 1024 * 1024;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,bmp,tif,tiff,avif', 'max:5120'],
        ]);

        $file = $request->file('image');
        $path = $file->getPathname();
        $markers = [];
        $info = @getimagesize($path, $markers);

        if ($info === false) {
            throw ValidationException::withMessages([
                'image' => 'Could not read this image\'s properties.',
            ]);
        }

        $exifAvailable = function_exists('exif_read_data');
        $exifApplies = in_array($info[2], [
            IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM,
        ], true);
        $exif = null;
        $exifStatus = ! $exifAvailable ? 'unavailable' : 'not_supported_for_format';

        if ($exifAvailable && $exifApplies) {

            $exif = @exif_read_data($path, null, true, true);
            $exifStatus = is_array($exif) ? 'ok' : 'no_data_or_unreadable';
            $exif = is_array($exif) ? $exif : null;
        }

        $iptc = isset($markers['APP13']) ? (@iptcparse($markers['APP13']) ?: null) : null;
        $tool = $this->readExifTool($path);

        $result = [
            'upload' => [
                'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
            ],
            'image' => [
                'mime_type' => $info['mime'],
                'width' => $info[0],
                'height' => $info[1],
                'reported_bit_depth' => $info['bits'] ?? null,
                'channels' => $info['channels'] ?? null,
            ],
            'exif_reader_available' => $exifAvailable,
            'exif_reader_applies_to_this_format' => $exifApplies,
            'exif_reader_status' => $exifStatus,
            'embedded_exif' => $exif,
            'embedded_iptc' => $iptc,
            'exiftool_status' => $tool['status'],
            'exiftool_error' => $tool['error'],
            'exiftool_stderr' => $tool['stderr'],
            'exiftool_exit_code' => $tool['exit_code'],
            'exiftool_all' => $tool['data'],
            'reader_notes' => [
                'PHP FILE/COMPUTED sections and ExifTool System/File/SourceFile fields may describe the temporary upload, not the original photo.',
                'Binary PHP values are Base64 objects; ExifTool may use base64:-prefixed strings.',
            ],
        ];

        return response()->json($this->jsonSafe($result), $tool['http_status'], [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function readExifTool(string $path): array
    {
        $result = [
            'status' => 'unavailable',
            'http_status' => 503,
            'error' => null,
            'stderr' => null,
            'exit_code' => null,
            'data' => null,
        ];

        if (! function_exists('proc_open')) {
            $result['error'] = 'PHP proc_open is unavailable; it is required to run ExifTool.';
            return $result;
        }

        $binary = (string) config('services.exiftool.path', 'exiftool');
        $process = new Process([
            $binary,
            '-json',
            '-G0:1:3:4', // Metadata family/location/document/instance: retain duplicate names.
            '-a',       // Duplicate tags.
            '-U',       // Unknown tags, including unknown information in binary blocks.
            '-struct',  // Preserve XMP structures and lists.
            '-D', '-l', // IDs/descriptions/displayed values and numerical values when different.
            '-b',       // Include binary values in JSON, Base64 encoded when necessary.
            '-ee3',     // Read supported embedded documents.
            '-api', 'RequestAll=3',
            '-api', 'SaveFormat=1',
            '-api', 'SaveBin=1',
            '-api', 'LimitLongValues=0', // Disable long-value display truncation.
            '--',
            $path,      // Upload path is a separate argument, never a shell command.
        ]);
        $process->setTimeout(30);

        $stdout = '';
        $stderr = '';
        $seenBytes = 0;
        $outputExceeded = false;

        try {
            $process->run(function (string $type, string $chunk) use (
                &$stdout, &$stderr, &$seenBytes, &$outputExceeded, $process
            ): void {
                // We keep our own bounded buffers instead of duplicating output.
                $process->clearOutput();
                $process->clearErrorOutput();

                if ($outputExceeded) {
                    return;
                }

                $seenBytes += strlen($chunk);

                if ($seenBytes > self::MAX_EXIFTOOL_OUTPUT) {
                    $outputExceeded = true;
                    throw new \LengthException('ExifTool output exceeded the 32 MiB limit.');
                }

                if ($type === Process::OUT) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            });
        } catch (ProcessTimedOutException $exception) {
            $result['status'] = 'timeout';
            $result['http_status'] = 504;
            $result['error'] = 'ExifTool exceeded the 30-second timeout.';
            return $result;
        } catch (\LengthException $exception) {
            $process->stop(0);
            $result['status'] = 'output_limit_exceeded';
            $result['http_status'] = 422;
            $result['error'] = $exception->getMessage();
            return $result;
        } catch (\Throwable $exception) {
            $result['error'] = 'Could not start ExifTool. Check EXIFTOOL_PATH and that exiftool_files is beside the executable.';
            $result['stderr'] = $exception->getMessage();
            return $result;
        }

        $result['exit_code'] = $process->getExitCode();
        $result['stderr'] = trim($stderr) ?: null;

        try {
            $data = json_decode($stdout, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($data) || $data === []) {
                throw new \UnexpectedValueException('Expected a non-empty JSON array from ExifTool.');
            }
            $result['data'] = $data;
        } catch (\Throwable $exception) {
            $result['status'] = 'invalid_output';
            $result['http_status'] = 502;
            $result['error'] = 'ExifTool did not return valid metadata JSON.';
            return $result;
        }

        $result['status'] = $process->isSuccessful() ? 'ok' : 'failed';
        $result['http_status'] = $process->isSuccessful() ? 200 : 502;
        $result['error'] = $process->isSuccessful() ? null : 'ExifTool reported a read error; inspect exiftool_stderr and any Error tags in exiftool_all.';

        return $result;
    }

    private function jsonSafe(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->jsonSafe($item);
            }
        } elseif ($value instanceof \stdClass) {
            foreach ($value as $key => $item) {
                $value->{$key} = $this->jsonSafe($item);
            }
        } elseif (is_string($value) && (
            preg_match('//u', $value) !== 1 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1
        )) {
            return [
                'encoding' => 'base64',
                'size_bytes' => strlen($value),
                'data' => base64_encode($value),
            ];
        }

        return $value;
    }
}
