<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class EngineProtocol
{
    public static function reject(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code' => $code, 'message' => $message, 'details' => [], 'request_id' => (string) Str::uuid(),
        ]], $status));
    }

    public static function hash(array $data): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($data), JSON_THROW_ON_ERROR));
    }
}
