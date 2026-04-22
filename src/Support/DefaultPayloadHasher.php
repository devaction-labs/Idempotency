<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Infinitypaul\Idempotency\Contracts\PayloadHasher;

final class DefaultPayloadHasher implements PayloadHasher
{
    /**
     * @param  list<string>  $ignore  Dot-notation paths to strip before hashing.
     */
    public function __construct(
        private readonly string $algo = 'sha256',
        private readonly bool $sortKeys = true,
        private readonly array $ignore = [],
        private readonly bool $includeFiles = true,
    ) {}

    public function hash(Request $request): string
    {
        /** @var array<string,mixed> $data */
        $data = $request->all();

        foreach ($this->ignore as $path) {
            data_forget($data, $path);
        }

        /** @var array<string,mixed> $data */
        if ($this->includeFiles) {
            $data['__files__'] = $this->fingerprintFiles($request);
        }

        if ($this->sortKeys) {
            $data = $this->recursiveKsort($data);
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = serialize($data);
        }

        return hash($this->algo, $json);
    }

    /**
     * @param  array<array-key,mixed>  $data
     * @return array<array-key,mixed>
     */
    private function recursiveKsort(array $data): array
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->recursiveKsort($value);
            }
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function fingerprintFiles(Request $request): array
    {
        $out = [];

        foreach ($request->allFiles() as $field => $file) {
            if (is_array($file)) {
                $out[$field] = array_map(fn (UploadedFile $f) => $this->fileFingerprint($f), $file);
            } elseif ($file instanceof UploadedFile) {
                $out[$field] = $this->fileFingerprint($file);
            }
        }

        if ($this->sortKeys) {
            ksort($out);
        }

        return $out;
    }

    /** @return array{name:string,size:int|false,mime:string,hash:string|null} */
    private function fileFingerprint(UploadedFile $file): array
    {
        return [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'hash' => $file->isValid() ? (string) hash_file('xxh128', $file->getRealPath()) : null,
        ];
    }
}
