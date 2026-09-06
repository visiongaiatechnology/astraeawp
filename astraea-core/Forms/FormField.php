<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Forms;

use Astraea\Exceptions\ValidationException;

/**
 * Validated Form Field Model for Astraea Forms Light.
 *
 * @package Astraea\Forms
 */
final class FormField {

    public const VALID_TYPES = [
        'text', 'email', 'textarea', 'number', 'select',
        'checkbox', 'radio', 'consent', 'hidden', 'file', 'date', 'url'
    ];

    /**
     * @param string $name Unique field identifier
     * @param string $label Human-readable field label
     * @param string $type Field type from VALID_TYPES
     * @param bool $required Whether field is required
     * @param list<string> $options Choices for select/radio/checkbox
     * @param string $placeholder Optional input placeholder
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly string $placeholder = ''
    ) {
        if (!in_array($this->type, self::VALID_TYPES, true)) {
            throw new ValidationException(sprintf('Invalid form field type: "%s".', $this->type));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        $name = sanitize_key((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Field name is required.');
        }

        $label = sanitize_text_field((string)($data['label'] ?? ucfirst($name)));
        $type = strtolower((string)($data['type'] ?? 'text'));
        $required = !empty($data['required']);
        $options = isset($data['options']) && is_array($data['options']) ? array_values(array_map('strval', $data['options'])) : [];
        $placeholder = sanitize_text_field((string)($data['placeholder'] ?? ''));

        return new self($name, $label, $type, $required, $options, $placeholder);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'name'        => $this->name,
            'label'       => $this->label,
            'type'        => $this->type,
            'required'    => $this->required,
            'options'     => $this->options,
            'placeholder' => $this->placeholder,
        ];
    }
}
