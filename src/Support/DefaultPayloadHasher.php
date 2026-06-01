<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Support;

use DevactionLabs\Idempotency\Contracts\PayloadHasher;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use JsonException;

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

    /**
     * @throws JsonException
     */
    public function hash(Request $request): string
    {
        /** @var array<string,mixed> $data */
        $data = $request->all();
        $data = $this->stripFilesFromPayload($data, $request->allFiles());

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

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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

    /**
     * @param  array<array-key,mixed>  $data
     * @param  array<array-key,mixed>  $files
     * @return array<array-key,mixed>
     */
    private function stripFilesFromPayload(array $data, array $files): array
    {
        foreach ($files as $field => $file) {
            if ($file instanceof UploadedFile) {
                unset($data[$field]);

                continue;
            }

            if (is_array($file) && isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = $this->stripFilesFromPayload($data[$field], $file);
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
                $out[$field] = $this->fingerprintFileArray($file);

                continue;
            }

            if ($file instanceof UploadedFile) {
                $out[$field] = $this->fileFingerprint($file);
            }
        }

        if ($this->sortKeys) {
            ksort($out);
        }

        return $out;
    }

    /**
     * @param  array<array-key,mixed>  $files
     * @return array<array-key,mixed>
     */
    private function fingerprintFileArray(array $files): array
    {
        $out = [];

        foreach ($files as $field => $file) {
            if ($file instanceof UploadedFile) {
                $out[$field] = $this->fileFingerprint($file);

                continue;
            }

            if (is_array($file)) {
                $out[$field] = $this->fingerprintFileArray($file);
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
