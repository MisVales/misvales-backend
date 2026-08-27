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
                $relativePath = Str::after(str_replace('\\', '/', $file), str_replace('\\', '/', base_path()).'/');
                $entries[$code] ??= [
                    'code' => $code,
                    'client_definition' => $occurrence['message'] ?: $this->humanize($code),
                    'internal_definition' => $this->humanize($code),
                    'admin_definition' => 'Código emitido por la API. Revise las fuentes registradas y el request_id de la respuesta para diagnóstico.',
                    'http_statuses' => [],
                    'sources' => [],
                    'occurrences' => 0,
                ];

                if ($occurrence['message'] !== null && $entries[$code]['client_definition'] === $this->humanize($code)) {
                    $entries[$code]['client_definition'] = $occurrence['message'];
                }
                if ($occurrence['status'] !== null) {
                    $entries[$code]['http_statuses'][] = $occurrence['status'];
                }
                $entries[$code]['sources'][] = $relativePath;
                $entries[$code]['occurrences']++;
            }
        }

        foreach ($entries as &$entry) {
            $entry['http_statuses'] = array_values(array_unique($entry['http_statuses']));
            sort($entry['http_statuses']);
            $entry['sources'] = array_values(array_unique($entry['sources']));
            sort($entry['sources']);
            $modules = collect($entry['sources'])
                ->map(fn (string $source): string => Str::of($source)->after('app/')->beforeLast('/')->replace('/', ' › ')->toString())
                ->filter()
                ->unique()
                ->take(3)
                ->implode(', ');
            $statuses = $entry['http_statuses'] === [] ? 'variable' : implode(', ', $entry['http_statuses']);
            $entry['internal_definition'] = $this->humanize($entry['code']).($modules !== '' ? " Áreas relacionadas: {$modules}." : '');
            $entry['admin_definition'] = "Respuesta API con HTTP {$statuses}; {$entry['occurrences']} punto(s) de emisión en ".count($entry['sources']).' archivo(s). Correlacione request_id con auditoría y logs.';
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
