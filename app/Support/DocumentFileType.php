<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Single source of truth for the intern-document upload allowlist.
 *
 * The client-supplied extension is never trusted on its own: the content type
 * detected from the file's magic bytes (finfo, via UploadedFile::getMimeType())
 * must match the type that the extension claims to be. Only then is the
 * document accepted, and the canonical type from this map is what gets stored
 * on the record — never the raw sniffed value.
 *
 * SEC-01a (WP-R1): this prevents an HTML/SVG/script payload renamed to an
 * allowed extension from ever reaching storage or an inline preview.
 *
 * Office container note: libmagic builds differ. Many servers report a valid
 * .docx/.xlsx as `application/zip` (the OOXML container) and legacy .doc/.xls
 * as `application/cdfv2` / `application/x-ole-storage` instead of the precise
 * OOXML/OLE types. Those generic container types are therefore accepted **only**
 * for the matching Office extension, and only after a structural check
 * (OOXML: ZIP package must contain a root `[Content_Types].xml` plus the
 * `word/` or `xl/` part; OLE: file must start with the OLE compound-file
 * signature). Active content types (text/html, image/svg+xml, *php*, etc.) are
 * never in this map, so they stay rejected regardless of extension.
 */
final class DocumentFileType
{
    /** Extension whitelist, kept for backwards compatibility with existing uploads. */
    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'jpg',
        'jpeg',
        'png',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'csv',
    ];

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** OLE compound-file signature shared by legacy .doc/.xls containers. */
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * extension => [detected MIME type => canonical MIME type to store].
     *
     * The canonical value is what identifies the document from then on, so the
     * preview endpoints can decide how to serve it without re-sniffing.
     */
    private const TYPES = [
        'pdf' => [
            'application/pdf' => 'application/pdf',
        ],
        'jpg' => [
            'image/jpeg' => 'image/jpeg',
        ],
        'jpeg' => [
            'image/jpeg' => 'image/jpeg',
        ],
        'png' => [
            'image/png' => 'image/png',
        ],
        'doc' => [
            'application/msword' => 'application/msword',
            // Legacy libmagic detection of the OLE container (validated below).
            'application/cdfv2' => 'application/msword',
            'application/x-ole-storage' => 'application/msword',
        ],
        'docx' => [
            self::DOCX => self::DOCX,
            // Legacy libmagic detection of the ZIP container (validated below).
            'application/zip' => self::DOCX,
        ],
        'xls' => [
            'application/vnd.ms-excel' => 'application/vnd.ms-excel',
            // Legacy libmagic detection of the OLE container (validated below).
            'application/cdfv2' => 'application/vnd.ms-excel',
            'application/x-ole-storage' => 'application/vnd.ms-excel',
        ],
        'xlsx' => [
            self::XLSX => self::XLSX,
            // Legacy libmagic detection of the ZIP container (validated below).
            'application/zip' => self::XLSX,
        ],
        'csv' => [
            'text/csv' => 'text/csv',
            'text/plain' => 'text/csv',
            'application/csv' => 'text/csv',
        ],
    ];

    /** User-facing validation error (field: file). */
    public const ERROR_MESSAGE = 'Unsupported file. Upload a PDF, JPG, JPEG, PNG, DOC, DOCX, XLS, XLSX or CSV whose content matches the file extension.';

    /** Lower-cased client extension, or an empty string when there is none. */
    public static function extension(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: '');
    }

    /** Content-detected MIME type (lower-cased, parameters such as charset stripped). */
    public static function detectedMime(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        return strtolower(trim(explode(';', $mime)[0]));
    }

    public static function hasAllowedExtension(UploadedFile $file): bool
    {
        return in_array(self::extension($file), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Canonical MIME type to persist, or null when the extension or the
     * detected content type is not allowed (i.e. the upload must be rejected).
     */
    public static function canonicalMime(UploadedFile $file): ?string
    {
        $extension = self::extension($file);
        $detected = self::detectedMime($file);

        $canonical = self::TYPES[$extension][$detected] ?? null;

        if ($canonical === null) {
            return null;
        }

        // Generic container detections are accepted only when the real
        // structure matches the claimed Office extension.
        if ($detected === 'application/zip') {
            $partPrefix = $extension === 'docx' ? 'word/' : 'xl/';

            return self::hasOoxmlPart($file, $partPrefix) ? $canonical : null;
        }

        if ($detected === 'application/cdfv2' || $detected === 'application/x-ole-storage') {
            return self::hasOleSignature($file) ? $canonical : null;
        }

        return $canonical;
    }

    /** True when the ZIP package is an OOXML file containing the expected part. */
    private static function hasOoxmlPart(UploadedFile $file, string $partPrefix): bool
    {
        $path = $file->getRealPath();

        if ($path === false || ! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_starts_with($name, $partPrefix)) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /** True when the file actually starts with the OLE compound-file signature. */
    private static function hasOleSignature(UploadedFile $file): bool
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, strlen(self::OLE_SIGNATURE));

            return $header === self::OLE_SIGNATURE;
        } finally {
            fclose($handle);
        }
    }
}
