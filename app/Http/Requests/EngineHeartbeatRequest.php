<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EngineHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('engine');
    }

    public function rules(): array
    {
        return [
            'report_sequence' => ['required', 'integer', 'min:1'],
            'observed_at' => ['required', 'date'],
            'applied_config_revision' => ['required', 'integer', 'min:0'],
            'config_error' => ['present', 'nullable', 'array:revision,code,message'],
            'config_error.revision' => ['required_with:config_error', 'integer', 'min:1'],
            'config_error.code' => ['required_with:config_error', 'string', 'max:100'],
            'config_error.message' => ['required_with:config_error', 'string', 'max:1000'],
            'zones' => ['present', 'array', 'max:1000'],
            'zones.*' => ['array:zone_id,state,playlist_id,song_id,position_ms,volume,channel_mode,output,error'],
            'zones.*.zone_id' => ['required', 'string', 'distinct'],
            'zones.*.state' => ['required', 'in:stopped'],
            'zones.*.playlist_id' => ['present', 'nullable', 'string'],
            'zones.*.song_id' => ['present', 'nullable', 'prohibited'],
            'zones.*.position_ms' => ['present', 'nullable', 'prohibited'],
            'zones.*.volume' => ['required', 'integer', 'between:0,100'],
            'zones.*.channel_mode' => ['required', 'in:mono,stereo'],
            'zones.*.output' => ['present', 'nullable', 'prohibited'],
            'zones.*.error' => ['present', 'nullable', 'prohibited'],
        ];
    }
}
