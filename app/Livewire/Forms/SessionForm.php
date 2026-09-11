<?php

namespace App\Livewire\Forms;

use App\Http\Requests\StoreWealthsimpleSessionRequest;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class SessionForm extends Form
{
    #[Validate('required', message: 'Paste a Wealthsimple session JSON object.')]
    public string $sessionJson = '';

    /**
     * @return array<string, mixed>|null
     */
    public function parsed(): ?array
    {
        try {
            $validated = StoreWealthsimpleSessionRequest::validatedFrom($this->sessionJson);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return null;
        }

        try {
            $parsed = json_decode($validated['sessionJson'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->addError('sessionJson', 'That is not valid JSON.');

            return null;
        }

        if (! is_array($parsed) || empty($parsed['refresh_token'])) {
            $this->addError('sessionJson', 'JSON needs a refresh_token (same shape as Bagholder’s wealthsimple-session.json).');

            return null;
        }

        return $parsed;
    }
}
