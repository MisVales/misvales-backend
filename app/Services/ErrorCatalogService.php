<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ErrorCatalogService
{
    private const CACHE_KEY = 'admin:error-catalog:v2';

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), fn (): array => $this->build());
    }

    /** @return array<int, array<string, mixed>> */
    private function build(): array
    {
        $entries = [];

        foreach ($this->sourceFiles() as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach ($this->extractOccurrences($contents) as $occurrence) {
                $code = $occurrence['code'];
                $entries[$code] ??= [
                    'code' => $code,
                    'client_messages' => [],
                    'http_statuses' => [],
                ];

                if ($occurrence['message'] !== null) {
                    $entries[$code]['client_messages'][] = $occurrence['message'];
                }
                if ($occurrence['status'] !== null) {
                    $entries[$code]['http_statuses'][] = $occurrence['status'];
                }
            }
        }

        foreach ($entries as &$entry) {
            $entry['client_messages'] = array_values(array_unique($entry['client_messages']));
            sort($entry['client_messages']);
            if ($entry['client_messages'] === []) {
                $entry['client_messages'] = [$this->humanize($entry['code'])];
            }
            $entry['client_message'] = $entry['client_messages'][0];
            $entry['http_statuses'] = array_values(array_unique($entry['http_statuses']));
            sort($entry['http_statuses']);
        }
        unset($entry);

        ksort($entries);

        return array_values($entries);
    }

    /** @return array<int, string> */
    private function sourceFiles(): array
    {
        $files = [base_path('bootstrap/app.php')];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return array<int, array{code: string, message: ?string, status: ?int}> */
    private function extractOccurrences(string $contents): array
    {
        $patterns = [
            '/new\s+(?:ApiException|BusinessException|Excepcion[A-Za-z]+)\s*\(\s*[\'\"]([A-Z][A-Z0-9_]{2,})[\'\"]\s*,\s*[\'\"]([^\'\"]*)[\'\"](?:\s*,\s*(\d{3}))?/s',
            '/\$respondError\s*\(\s*\$request\s*,\s*[\'\"]([A-Z][A-Z0-9_]{2,})[\'\"]\s*,\s*[\'\"]([^\'\"]*)[\'\"]\s*,\s*(\d{3})/s',
            '/[\'\"]code[\'\"]\s*=>\s*[\'\"]([A-Z][A-Z0-9_]{2,})[\'\"]/',
        ];
        $occurrences = [];

        foreach ($patterns as $index => $pattern) {
            preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $occurrences[] = [
                    'code' => $match[1],
                    'message' => $index < 2 && ($match[2] ?? '') !== '' ? $match[2] : null,
                    'status' => isset($match[3]) && ctype_digit($match[3]) ? (int) $match[3] : null,
                ];
            }
        }

        return $occurrences;
    }

    private function humanize(string $code): string
    {
        return Str::of($code)->lower()->replace('_', ' ')->ucfirst()->append('.')->toString();
    }
}
