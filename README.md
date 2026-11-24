# binary-image-api

A Laravel API for exploring how image files are stored, decoded and represented as binary. Upload an image to inspect its file bytes, decoded pixels, basic properties and available metadata.

Built as a practical companion to [How computers turn 0s and 1s into images](https://georgejoe.hashnode.dev/how-computers-turn-0s-and-1s-into-images).

## Features

- Convert an image file's original bytes into a printable binary string.
- Decode images into RGBA pixel values using PHP GD.
- Represent pure blue-and-white images using one bit per pixel.
- Read dimensions, MIME type, file size and reported colour information.
- Extract PHP EXIF/IPTC data and comprehensive ExifTool metadata.
- Return structured JSON, preserving binary metadata through Base64 encoding.

The controllers read uploads from PHP's temporary upload directory. They do not save a permanent copy or modify the uploaded image.

## Requirements

| Requirement | Purpose |
| --- | --- |
| PHP 8.2+ | Runtime for the Laravel 11/12-compatible controllers; follow `composer.json` for the project's exact requirement |
| Composer | Install the application's PHP dependencies |
| PHP Fileinfo | Detect uploaded file types during validation |
| PHP GD | Required for the `pixels` and `blue_white` binary modes |
| PHP EXIF | Optional PHP EXIF extraction from JPEG/TIFF files |
| PHP Mbstring | Required by the Windows PHP EXIF extension |
| ExifTool | Required for the metadata endpoint's `exiftool_all` output |
| PHP `proc_open()` | Allows Symfony Process to launch ExifTool |

ExifTool is a separate command-line program. Enabling PHP's EXIF extension does not install ExifTool. GD is not needed for original-file binary conversion or metadata extraction.

## Installation

### 1. Clone the repository and install dependencies

Replace `<repository-url>` with this repository's Git clone URL.

```bash
git clone <repository-url> binary-image-api
cd binary-image-api
composer install
```

### 2. Create the environment file

On Linux, macOS or Git Bash:

```bash
cp .env.example .env
```

On Windows Command Prompt:

```cmd
copy .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

These image endpoints do not use application database tables. For local use without a database, configure file-based sessions and cache in `.env`:

```env
APP_URL=http://127.0.0.1:8000
SESSION_DRIVER=file
CACHE_STORE=file
```

If you use other database-backed features in the application, configure their database separately.

### 3. Enable PHP image extensions

For XAMPP on Windows, open **XAMPP Control Panel → Apache → Config → PHP (php.ini)**. Enable the following entries by removing any leading semicolon. Keep Mbstring before EXIF, and avoid adding duplicate enabled entries:

```ini
extension=gd
extension=mbstring
extension=exif
```

To accommodate the metadata endpoint's 5 MiB upload limit, PHP's limits must also allow the request, for example:

```ini
upload_max_filesize=5M
post_max_size=8M
```

Restart Apache if it serves the application. If using `php artisan serve`, stop and restart that server as well.

Check the PHP configuration and extensions used by your terminal:

```bash
php --ini
php --ri gd
php --ri exif
```

With XAMPP, you can target its PHP executable explicitly:

```cmd
C:\xampp\php\php.exe --ri gd
C:\xampp\php\php.exe --ri exif
```

The PHP process serving the API must have the extensions enabled. A successful check in a different PHP installation does not establish that they are loaded by the API.

### 4. Install and configure ExifTool

Download ExifTool from the [official project website](https://exiftool.org/). The Windows executable package is available through the project's [official SourceForge site](https://exiftool.sourceforge.net/).

For Windows/XAMPP:

1. Download the **Windows executable ZIP**, rather than the `Image-ExifTool-*.tar.gz` Perl distribution used with a separate Perl interpreter.
2. Extract the ZIP and rename `exiftool(-k).exe` to `exiftool.exe`.
3. Put the executable and its `exiftool_files` directory together, for example inside `C:\tools\exiftool\`.
4. Confirm that the resulting executable path is `C:\tools\exiftool\exiftool.exe`.

Test it in **Command Prompt**:

```cmd
"C:\tools\exiftool\exiftool.exe" -ver
```

It should print the installed version number. Double-clicking the executable is not required for the API to use it.

Add this entry inside the existing returned array in `config/services.php` if it is not already present:

```php
'exiftool' => [
    'path' => env('EXIFTOOL_PATH', 'exiftool'),
],
```

Set the actual executable path in `.env`:

```env
EXIFTOOL_PATH="C:/tools/exiftool/exiftool.exe"
```

If ExifTool is already available in the server's PATH, the value can be:

```env
EXIFTOOL_PATH=exiftool
```

Clear Laravel's cached configuration after changing these settings:

```bash
php artisan config:clear
```

### 5. Start the API

```bash
php artisan serve
```

Default base URL: `http://127.0.0.1:8000`.

To use XAMPP's PHP explicitly, run from the project root:

```cmd
C:\xampp\php\php.exe artisan serve
```

Verify the registered image routes:

```bash
php artisan route:list --path=images
```

## API reference

Send uploads as `multipart/form-data` with an `image` File field. Use `Accept: application/json`. Let your HTTP client generate the multipart Content-Type boundary.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/images/binary` | Original file bits or decoded pixel bits |
| POST | `/api/images/metadata` | Basic properties, PHP EXIF/IPTC and ExifTool output |

### POST `/api/images/binary`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `image` | File | Yes | PNG, JPEG or WebP; maximum 1 MiB |
| `mode` | String | No | `file`, `pixels` or `blue_white`; defaults to `file` |

| Mode | Representation |
| --- | --- |
| `file` | Eight binary digits per byte of the original file, including headers, metadata and compressed image data |
| `pixels` | Decoded RGBA pixels: red, green, blue and alpha, each written as eight bits |
| `blue_white` | One bit per pixel: `0` = opaque blue `#0000FF`; `1` = opaque white `#FFFFFF` |

Pixel modes accept at most **262,144 pixels**, such as a 512 × 512 image. The endpoint does not resize the upload. Blue/white mode returns HTTP 422 if any pixel has another colour or transparency.

Read pixel output left to right, then top to bottom. Newline characters separate rows in the `binary` string and are excluded from `bit_length`.

The output is printable binary text inside JSON. Original file bytes and decoded pixel values describe different representations: a 91-byte PNG produces 728 bits in `file` mode, while a 4 × 4 blue/white pixel grid produces 16 bits in `blue_white` mode.

#### Example requests

Replace the image filenames with paths to your own files. These one-line commands work with curl in Bash or Windows Command Prompt; in PowerShell, use `curl.exe` instead of the `curl` alias.

Original file bytes:

```bash
curl -X POST "http://127.0.0.1:8000/api/images/binary" -H "Accept: application/json" -F "image=@photo.png" -F "mode=file"
```

Decoded RGBA pixels:

```bash
curl -X POST "http://127.0.0.1:8000/api/images/binary" -H "Accept: application/json" -F "image=@photo.png" -F "mode=pixels"
```

Blue/white pixel values:

```bash
curl -X POST "http://127.0.0.1:8000/api/images/binary" -H "Accept: application/json" -F "image=@blue-white-4x4.png" -F "mode=blue_white"
```

For a 4 × 4 image whose rows alternate blue, white, blue, white:

```json
{
  "width": 4,
  "height": 4,
  "mime_type": "image/png",
  "mode": "blue_white",
  "encoding": "palette-1bit",
  "bits_per_pixel": 1,
  "bit_length": 16,
  "scan_order": "left-to-right, top-to-bottom",
  "binary": "0101\n0101\n0101\n0101",
  "palette": {
    "0": "#0000FF",
    "1": "#FFFFFF"
  }
}
```

Use PNG for this exact-colour example. JPEG's lossy compression can alter the RGB values.

GD represents alpha with 128 transparency levels. In `pixels` mode, the API maps these to an 8-bit opacity value, where 0 is transparent and 255 is opaque. This conversion is not a byte-for-byte preservation of the original image's alpha channel. Use `file` mode to inspect the original bytes exactly.

### POST `/api/images/metadata`

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `image` | File | Yes | PNG, JPEG, WebP, GIF, BMP, TIFF or AVIF; maximum 5 MiB |

```bash
curl -X POST "http://127.0.0.1:8000/api/images/metadata" -H "Accept: application/json" -F "image=@photo.png"
```

The endpoint uses PHP to read basic properties and, where applicable, EXIF/IPTC. It also runs ExifTool with options for duplicate and unknown tags, embedded documents, structured values and binary data.

| Response field | Meaning |
| --- | --- |
| `upload` | Client-supplied original filename and uploaded file size |
| `image` | MIME type, dimensions, reported bit depth and channels |
| `exif_reader_available` | Whether the serving PHP process exposes `exif_read_data()` |
| `exif_reader_applies_to_this_format` | Whether the controller uses PHP EXIF for this format: JPEG or TIFF |
| `exif_reader_status` | `ok`, `unavailable`, `not_supported_for_format` or `no_data_or_unreadable` |
| `embedded_exif` | All sections returned by PHP EXIF, including thumbnail data when available |
| `embedded_iptc` | Parsed IPTC tags, when available through PHP's JPEG APP13 reader |
| `exiftool_status` | ExifTool execution/extraction status |
| `exiftool_error` | Error message, or `null` on success |
| `exiftool_stderr` | Diagnostic output from ExifTool or its launcher |
| `exiftool_exit_code` | Process exit code; normally `0` on success |
| `exiftool_all` | Every JSON record returned by ExifTool, with grouped tag names |
| `reader_notes` | Notes about generated fields, temporary files and binary values |

ExifTool tag objects can contain:

| Field | Meaning |
| --- | --- |
| `val` | Value formatted for display |
| `num` | Value before display formatting, when different; it may be a number or string |
| `desc` | Human-readable tag description |
| `id` | Tag identifier or format-specific offset |
| `fmt`, `hex`, `rat` | Additional EXIF format, hexadecimal or rational-value information when available |

#### Interpreting the results

- Available tags depend on what the file contains and what each reader recognizes. Camera, GPS or author tags may be absent.
- PHP EXIF is used for JPEG/TIFF here. A PNG may report `not_supported_for_format` for that reader while ExifTool still extracts PNG metadata successfully.
- A `null` channel count means PHP did not report it. In an indexed PNG, bit depth describes the palette index size rather than the RGB sample depth of each palette colour.
- `SourceFile`, filesystem timestamps, permissions and PHP's `FILE` section describe the temporary uploaded copy. They do not establish the original photo's capture date or author.
- `Now`, `NewGUID`, `NewUUID` and `ProcessingTime` are ExifTool-generated inspection values requested by the command; they are not automatically written into the image.
- Binary PHP values are returned as objects with `encoding`, `size_bytes` and Base64 `data`. ExifTool may use strings beginning with `base64:`.

ExifTool extraction is limited to **30 seconds** and **32 MiB of combined output**. Limits produce explicit errors instead of silently returning truncated metadata.

## Using Postman

1. Select **POST** and enter either endpoint URL.
2. Add the header `Accept: application/json`.
3. Select **Body → form-data**.
4. Add `image`, change its type from **Text** to **File**, and choose an image.
5. For the binary endpoint, optionally add `mode` as a **Text** field.
6. Send the request and expand the JSON response.

## Responses and troubleshooting

| HTTP status | Meaning |
| --- | --- |
| `200` | Successful extraction or conversion |
| `422` | Invalid input, unsupported image/colour, pixel limit or ExifTool output limit |
| `500` | Uploaded image could not be read in the binary endpoint |
| `502` | ExifTool reported a read error or did not return valid metadata JSON |
| `503` | Required GD functionality or ExifTool process execution is unavailable |
| `504` | ExifTool exceeded its timeout |

| Problem | What to check |
| --- | --- |
| Missing `vendor/autoload.php` | Run `composer install` from the project root |
| GD appears in the terminal but pixel modes fail | Check the PHP binary and `php.ini` used by the API; stop the old server and launch with `C:\xampp\php\php.exe artisan serve` |
| `exiftool` is not recognized | Set `EXIFTOOL_PATH` to the actual `.exe`, check the `config/services.php` entry, clear configuration and restart the server |
| ExifTool did not return valid JSON | Inspect `exiftool_stderr`; a missing executable or incomplete Windows package can produce this message |
| ExifTool executable exists but does not start | Keep `exiftool_files` beside the executable and test the full path with `-ver` |
| `exif_reader_available` is false | Enable PHP EXIF; on Windows enable Mbstring before EXIF, then restart the serving process |
| PHP rejects an upload before controller validation | Check `upload_max_filesize` and `post_max_size` in the active PHP configuration |
| Blue/white mode returns 422 | Use only fully opaque `#0000FF` and `#FFFFFF` pixels; check for JPEG colour changes or transparency |
| Image routes return 404 | Check `routes/api.php`, its registration in `bootstrap/app.php`, and `php artisan route:list --path=images` |

## References

- [Laravel configuration](https://laravel.com/docs/12.x/configuration)
- [PHP GD setup](https://www.php.net/manual/en/image.installation.php)
- [PHP EXIF setup](https://www.php.net/manual/en/exif.installation.php)
- [ExifTool installation](https://exiftool.org/install.html)
- [ExifTool command-line options](https://exiftool.org/exiftool_pod.html)

## Author

**Joseph George** — Full-stack/backend developer and visual artist.
