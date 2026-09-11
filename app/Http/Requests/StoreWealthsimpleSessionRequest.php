<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StoreWealthsimpleSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sessionJson' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sessionJson.required' => 'Paste a Wealthsimple session JSON object.',
        ];
    }

    /**
     * Build a request from Livewire form state and return validated input via validated() / safe().
     *
     * @return array{sessionJson: string}
     */
    public static function validatedFrom(string $sessionJson): array
    {
        $request = static::create('/', 'POST', ['sessionJson' => $sessionJson]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));

        $validator = Validator::make(
            $request->all(),
            $request->rules(),
            $request->messages(),
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $request->setValidator($validator);

        return $request->safe()->only(['sessionJson']);
    }
}
