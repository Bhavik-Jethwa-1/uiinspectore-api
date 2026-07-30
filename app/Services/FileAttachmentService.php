<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FileAttachmentService — processes file attachments for AI chat.
 *
 * Handles:
 * - Text extraction from code files, PDFs (text-based), markdown
 * - Image embedding as base64 data URIs
 * - Large file rejection (max 10MB per file)
 * - Store files for later retrieval
 */
class FileAttachmentService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_TOTAL_SIZE = 50 * 1024 * 1024; // 50MB total

    // Supported MIME types
    private const SUPPORTED_TYPES = [
        // Images
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        // Documents
        'text/plain', 'text/csv', 'text/html', 'text/css', 'text/javascript',
        'text/markdown', 'text/x-php', 'text/x-python', 'text/x-java',
        'text/x-c', 'text/x-c++', 'text/x-csharp', 'text/x-ruby',
        'text/x-go', 'text/x-rust', 'text/x-swift', 'text/x-kotlin',
        'application/json', 'application/xml', 'application/xhtml+xml',
        'application/pdf', // processed as binary; for full PDF support, install smalot/pdfparser
    ];

    public function __construct()
    {
    }

    /**
     * Process attachments from a request.
     * Attachments can come as:
     * - files[] (multipart upload)
     * - attachments[] (base64 encoded JSON objects)
     *
     * Returns array of processed attachment data ready to inject into messages.
     */
    public function processAttachments(Request $request): array
    {
        $attachments = [];

        // Handle multipart file uploads
        if ($request->hasFile('files')) {
            $files = $request->file('files');
            foreach (is_array($files) ? $files : [$files] as $file) {
                $result = $this->processUploadedFile($file);
                if ($result) $attachments[] = $result;
            }
        }

        // Handle base64-encoded attachments in JSON body
        $bodyAttachments = $request->input('attachments', []);
        if (is_string($bodyAttachments)) {
            $bodyAttachments = json_decode($bodyAttachments, true) ?? [];
        }
        foreach ($bodyAttachments as $att) {
            $result = $this->processBase64Attachment($att);
            if ($result) $attachments[] = $result;
        }

        return $attachments;
    }

    /**
     * Inject attachment content into the last user message.
     * Returns modified messages array with attachment content prepended.
     */
    public function injectIntoMessages(array $messages, array $attachments): array
    {
        if (empty($attachments)) return $messages;

        $attachmentContext = $this->buildAttachmentContext($attachments);

        // Find the last user message and prepend attachment context
        $found = false;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $originalContent = $messages[$i]['content'] ?? '';
                if (is_string($originalContent)) {
                    $messages[$i]['content'] = $attachmentContext . "\n\n" . $originalContent;
                } elseif (is_array($originalContent)) {
                    // Handle content as array of content blocks
                    array_unshift($originalContent, [
                        'type' => 'text',
                        'text' => $attachmentContext,
                    ]);
                    $messages[$i]['content'] = $originalContent;
                }
                $found = true;
                break;
            }
        }

        // If no user message found, prepend to first message
        if (!$found && !empty($messages)) {
            $originalContent = $messages[0]['content'] ?? '';
            if (is_string($originalContent)) {
                $messages[0]['content'] = $attachmentContext . "\n\n" . $originalContent;
            }
        }

        return $messages;
    }

    /**
     * Process an uploaded file and return its processed representation.
     */
    private function processUploadedFile($file): ?array
    {
        if (!$file || !$file->isValid()) return null;

        $size = $file->getSize();
        if ($size > self::MAX_FILE_SIZE) {
            Log::warning("FileAttachmentService: file too large", ['size' => $size, 'max' => self::MAX_FILE_SIZE]);
            return null;
        }

        $mime = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());

        return $this->processFileContent($file->get(), $mime, $originalName, $extension);
    }

    /**
     * Process a base64-encoded attachment.
     */
    private function processBase64Attachment(array $att): ?array
    {
        $data = $att['data'] ?? null;
        $filename = $att['filename'] ?? 'attachment';
        $mime = $att['mime'] ?? $this->inferMime($filename);

        if (!$data) return null;

        $content = base64_decode($data, true);
        if ($content === false) return null;
        if (strlen($content) > self::MAX_FILE_SIZE) return null;

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return $this->processFileContent($content, $mime, $filename, $extension);
    }

    /**
     * Core processing: extract content or create data URI based on file type.
     */
    private function processFileContent(string $content, string $mime, string $filename, string $extension): ?array
    {
        $isImage = str_starts_with($mime, 'image/');
        $isText = str_starts_with($mime, 'text/') || in_array($mime, [
            'application/json', 'application/xml', 'application/xhtml+xml',
        ]);
        $isPdf = $mime === 'application/pdf';

        if ($isImage) {
            return [
                'type' => 'image',
                'filename' => $filename,
                'mime' => $mime,
                'size' => strlen($content),
                'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($content),
            ];
        }

        if ($isPdf) {
            // Basic PDF text extraction (first page text via regex)
            // For full PDF support, install: composer require smalot/pdfparser
            $text = $this->extractPdfText($content);
            if ($text && strlen($text) > 50) {
                return [
                    'type' => 'document',
                    'filename' => $filename,
                    'mime' => $mime,
                    'size' => strlen($content),
                    'text' => $text,
                    'truncated' => strlen($text) > 50000,
                ];
            }
            // If PDF text extraction fails, fall back to description
            return [
                'type' => 'document',
                'filename' => $filename,
                'mime' => $mime,
                'size' => strlen($content),
                'text' => "[PDF document: $filename — " . number_format(strlen($content)) . " bytes. Full PDF parsing requires server-side PDF library installation.]",
                'truncated' => false,
            ];
        }

        if ($isText) {
            $text = $this->sanitizeText($content);
            if (strlen($text) > 50000) {
                $text = substr($text, 0, 50000) . "\n\n[... content truncated (file exceeds 50,000 character limit) ...]";
            }
            return [
                'type' => 'document',
                'filename' => $filename,
                'mime' => $mime,
                'size' => strlen($content),
                'text' => $text,
                'truncated' => strlen($content) > 50000,
            ];
        }

        // Unknown type — treat as binary description
        return [
            'type' => 'binary',
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($content),
            'text' => "[Binary file: $filename ($mime, " . number_format(strlen($content)) . " bytes) — cannot display content]",
            'truncated' => false,
        ];
    }

    /**
     * Build a text context string from processed attachments.
     */
    private function buildAttachmentContext(array $attachments): string
    {
        $parts = [];
        $imageCount = 0;
        $docCount = 0;

        foreach ($attachments as $att) {
            if ($att['type'] === 'image') {
                $imageCount++;
                $parts[] = "[Image attachment: {$att['filename']}]";
            } elseif ($att['type'] === 'document') {
                $docCount++;
                $truncated = $att['truncated'] ? ' (truncated)' : '';
                $parts[] = "[File: {$att['filename']}]\n{$att['text']}{$truncated}";
            } else {
                $parts[] = $att['text'];
            }
        }

        $summary = [];
        if ($imageCount > 0) $summary[] = "$imageCount image(s)";
        if ($docCount > 0) $summary[] = "$docCount document(s)";

        return "--- Attachment Context ({$imageCount} image(s), {$docCount} document(s)) ---\n\n"
            . implode("\n\n---\n\n", $parts)
            . "\n\n--- End Attachments ---";
    }

    /**
     * Basic PDF text extraction using regex (lightweight, no external library).
     * Extracts text from PDF stream objects. Not guaranteed to work on all PDFs.
     */
    private function extractPdfText(string $content): string
    {
        // Try to find text in PDF streams
        $text = '';

        // Match text in BT...ET blocks
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            foreach ($matches[1] as $block) {
                // Extract string literals
                if (preg_match_all('/\(([^)]*)\)\s*Tj/', $block, $tjMatches)) {
                    $text .= implode(' ', $tjMatches[1]) . ' ';
                }
                // Extract TJ arrays
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArrayMatches)) {
                    foreach ($tjArrayMatches[1] as $arr) {
                        preg_match_all('/\("([^"]*)"\)/', $arr, $strMatches);
                        $text .= implode('', $strMatches[1]) . ' ';
                    }
                }
            }
        }

        // Clean up escape sequences
        $text = preg_replace('/\\\s+/', ' ', $text);
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Sanitize text content from text files.
     */
    private function sanitizeText(string $content): string
    {
        // Remove null bytes and other control characters
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        // Normalize line endings
        $content = preg_replace("/\r\n|\r/", "\n", $content);
        return trim($content);
    }

    /**
     * Infer MIME type from filename.
     */
    private function inferMime(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf', 'txt' => 'text/plain',
            'md' => 'text/markdown', 'markdown' => 'text/markdown',
            'html' => 'text/html', 'htm' => 'text/html',
            'css' => 'text/css', 'js' => 'text/javascript',
            'jsx' => 'text/javascript', 'ts' => 'text/plain',
            'tsx' => 'text/plain', 'json' => 'application/json',
            'xml' => 'application/xml', 'py' => 'text/x-python',
            'php' => 'text/x-php', 'java' => 'text/x-java',
            'c' => 'text/x-c', 'cpp' => 'text/x-c++', 'h' => 'text/x-c',
            'cs' => 'text/x-csharp', 'rb' => 'text/x-ruby',
            'go' => 'text/x-go', 'rs' => 'text/x-rust',
            'swift' => 'text/x-swift', 'kt' => 'text/x-kotlin',
            'vue' => 'text/html', 'svelte' => 'text/html',
            'sql' => 'text/plain', 'sh' => 'text/plain',
            'yaml' => 'text/plain', 'yml' => 'text/plain',
            'toml' => 'text/plain', 'ini' => 'text/plain',
            'csv' => 'text/csv',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
