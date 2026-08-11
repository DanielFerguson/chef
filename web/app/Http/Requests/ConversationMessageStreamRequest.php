<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class ConversationMessageStreamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['bail', 'nullable', 'string', 'max:10000'],
            'approval' => ['bail', 'nullable', 'array:id,decision'],
            'approval.id' => ['required_with:approval', 'string', 'max:255'],
            'approval.decision' => ['required_with:approval', Rule::in(['approve', 'reject'])],
            'client_message_id' => ['required', 'uuid'],
            'images' => ['nullable', 'array', 'max:4'],
            'images.*' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'])->max('10mb'),
            ],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $content = $this->input('content');

            if (
                trim(is_string($content) ? $content : '') === ''
                && $this->file('images', []) === []
                && ! is_array($this->input('approval'))
            ) {
                $validator->errors()->add('content', 'Add a message or at least one photo.');
            }

            if (
                is_array($this->input('approval'))
                && (trim(is_string($content) ? $content : '') !== '' || $this->file('images', []) !== [])
            ) {
                $validator->errors()->add('approval', 'Plan approval cannot be combined with a message or photos.');
            }
        }];
    }

    /** @return array{id: string, decision: 'approve'|'reject'}|null */
    public function approval(): ?array
    {
        $approval = $this->validated('approval');

        if (! is_array($approval)) {
            return null;
        }

        return [
            'id' => (string) $approval['id'],
            'decision' => $approval['decision'] === 'approve' ? 'approve' : 'reject',
        ];
    }

    /** @return list<UploadedFile> */
    public function images(): array
    {
        $images = $this->file('images', []);

        return array_values($images);
    }
}
