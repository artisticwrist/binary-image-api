<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImageBinaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        
        $validated = $request->validate([
            'image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'mode' => ['sometimes', 'string', 'in:file,pixels,blue_white'],
        ]);

        $mode = $validated['mode'] ?? 'file';
        $bytes = @file_get_contents($request->file('image')->getPathname());

        if ($bytes === false) {
            return response()->json(['message' => 'Could not read the uploaded image.'], 500);
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw ValidationException::withMessages([
                'image' => 'The uploaded file does not contain readable image dimensions.',
            ]);
        }

        [$width, $height] = $info;
        $metadata = [
            'width' => $width,
            'height' => $height,
            'mime_type' => $info['mime'],
        ];

        if ($mode === 'file') {
            $binary = '';
            $length = strlen($bytes);

            for ($i = 0; $i < $length; $i++) {
                $binary .= sprintf('%08b', ord($bytes[$i]));
            }

            return response()->json(array_merge($metadata, [
                'mode' => 'file',
                'encoding' => 'original-file-bytes',
                'byte_length' => $length,
                'bit_length' => $length * 8,
                'binary' => $binary,
            ]));
        }

        // Bound the decoded image before allocating its raster and binary text.
        if ($width * $height > 262144) {
            throw ValidationException::withMessages([
                'image' => 'Pixel modes accept up to 262144 pixels, for example 512 by 512.',
            ]);
        }

        if (! function_exists('imagecreatefromstring')) {
            return response()->json([
                'message' => 'Enable the PHP GD extension to use pixel modes.',
            ], 503);
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw ValidationException::withMessages([
                'image' => 'The image is corrupt or its format is unsupported by this GD build.',
            ]);
        }

        $rows = [];

        try {
            // Read left to right, then top to bottom, without resizing.
            for ($y = 0; $y < $height; $y++) {
                $row = '';

                for ($x = 0; $x < $width; $x++) {
                    // Works for both palette images and true-colour images.
                    $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));

                    if ($mode === 'blue_white') {
                        $blue = $colour['red'] === 0 && $colour['green'] === 0 && $colour['blue'] === 255;
                        $white = $colour['red'] === 255 && $colour['green'] === 255 && $colour['blue'] === 255;

                        if ((! $blue && ! $white) || $colour['alpha'] !== 0) {
                            throw ValidationException::withMessages([
                                'image' => "Blue/white mode requires opaque #0000FF or #FFFFFF pixels. Unsupported pixel at ({$x}, {$y}).",
                            ]);
                        }

                        $row .= $blue ? '0' : '1';
                    } else {
                        $alpha = (int) round((127 - $colour['alpha']) * 255 / 127);
                        $row .= sprintf('%08b%08b%08b%08b',
                            $colour['red'], $colour['green'], $colour['blue'], $alpha
                        );
                    }
                }

                $rows[] = $row;
            }
        } finally {
            // GdImage is released automatically when its last reference is removed.
            unset($image);
        }

        $bitsPerPixel = $mode === 'blue_white' ? 1 : 32;
        $result = array_merge($metadata, [
            'mode' => $mode,
            'encoding' => $mode === 'blue_white' ? 'palette-1bit' : 'rgba-8bit-from-gd',
            'bits_per_pixel' => $bitsPerPixel,
            'bit_length' => $width * $height * $bitsPerPixel,
            'scan_order' => 'left-to-right, top-to-bottom',
            'binary' => implode("\n", $rows),
        ]);

        if ($mode === 'blue_white') {
            $result['palette'] = (object) ['0' => '#0000FF', '1' => '#FFFFFF'];
        } else {
            $result['channels'] = ['red', 'green', 'blue', 'alpha'];
        }

        return response()->json($result);
    }
}
