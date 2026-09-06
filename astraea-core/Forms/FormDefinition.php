<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Forms;

use Astraea\Exceptions\ValidationException;

/**
 * Validated Form Definition Model.
 *
 * @package Astraea\Forms
 */
final class FormDefinition {

    /**
     * @param string $id Unique form identifier
     * @param string $title Human-readable form title
     * @param list<FormField> $fields List of form fields
     * @param string $notifyEmail Recipient email for delivery via Astraea Mail Gateway
     * @param bool $storeEncrypted Whether submissions should be encrypted with Astraea Crypto
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $fields,
        public readonly string $notifyEmail = '',
        public readonly bool $storeEncrypted = true
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $id = sanitize_key((string)($data['id'] ?? ''));
        if ($id === '') {
            throw new ValidationException('Form ID is required.');
        }

        $title = sanitize_text_field((string)($data['title'] ?? 'Contact Form'));
        $fields = [];
        if (isset($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $fData) {
                if (is_array($fData)) {
                    $fields[] = FormField::fromArray($fData);
                }
            }
        }

        $notify = sanitize_email((string)($data['notify_email'] ?? get_option('admin_email')));
        $storeEncrypted = !empty($data['store_encrypted']);

        return new self($id, $title, $fields, $notify, $storeEncrypted);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'fields'          => array_map(static fn(FormField $f): array => $f->toArray(), $this->fields),
            'notify_email'    => $this->notifyEmail,
            'store_encrypted' => $this->storeEncrypted,
        ];
    }
}
