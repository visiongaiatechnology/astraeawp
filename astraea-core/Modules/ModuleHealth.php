<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Modules;

/**
 * Health probe evaluation result for a first-party Astraea module.
 * Never outputs synthetic PASS; unverified metrics default to UNKNOWN.
 *
 * @package Astraea\Modules
 */
final class ModuleHealth {
    public const STATUS_HEALTHY  = 'HEALTHY';
    public const STATUS_DEGRADED = 'DEGRADED';
    public const STATUS_CRITICAL = 'CRITICAL';
    public const STATUS_UNKNOWN  = 'UNKNOWN';

    public const HEALTHY  = self::STATUS_HEALTHY;
    public const DEGRADED = self::STATUS_DEGRADED;
    public const CRITICAL = self::STATUS_CRITICAL;
    public const UNKNOWN  = self::STATUS_UNKNOWN;

    /**
     * @param string $status One of STATUS_HEALTHY, STATUS_DEGRADED, STATUS_CRITICAL, STATUS_UNKNOWN.
     * @param string $label Human-readable concise status label.
     * @param string $message Detailed evidence-based health explanation.
     * @param array<string, mixed> $metrics Measured telemetry metrics.
     * @param float $latencyMs Probe execution latency in milliseconds.
     * @param int $timestamp Unix timestamp when probe was evaluated.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $message,
        public readonly array $metrics = [],
        public readonly float $latencyMs = 0.0,
        public readonly int $timestamp = 0
    ) {}

    /**
     * Factory method supporting both canonical 4-argument and concise 3-argument probe results.
     *
     * Canonical: ModuleHealth::healthy(string $label, string $message = '', array $metrics = [], float $latencyMs = 0.0)
     * Concise:   ModuleHealth::healthy(string $message, array $metrics = [], float|int $latencyMs = 0.0)
     *
     * @param string $labelOrMessage Short status label or detailed evidence message.
     * @param string|array<string, mixed> $messageOrMetrics Detailed message string or metrics array.
     * @param array<string, mixed>|float|int $metricsOrLatency Metrics array or probe execution latency in ms.
     * @param float|int $latencyMs Probe execution latency in ms (canonical 4-arg form).
     */
    public static function healthy(
        string $labelOrMessage,
        string|array $messageOrMetrics = '',
        array|float|int $metricsOrLatency = [],
        float|int $latencyMs = 0.0
    ): self {
        return self::create(self::STATUS_HEALTHY, $labelOrMessage, $messageOrMetrics, $metricsOrLatency, $latencyMs);
    }

    public static function degraded(
        string $labelOrMessage,
        string|array $messageOrMetrics = '',
        array|float|int $metricsOrLatency = [],
        float|int $latencyMs = 0.0
    ): self {
        return self::create(self::STATUS_DEGRADED, $labelOrMessage, $messageOrMetrics, $metricsOrLatency, $latencyMs);
    }

    public static function critical(
        string $labelOrMessage,
        string|array $messageOrMetrics = '',
        array|float|int $metricsOrLatency = [],
        float|int $latencyMs = 0.0
    ): self {
        return self::create(self::STATUS_CRITICAL, $labelOrMessage, $messageOrMetrics, $metricsOrLatency, $latencyMs);
    }

    public static function unknown(
        string $labelOrMessage,
        string|array $messageOrMetrics = '',
        array|float|int $metricsOrLatency = [],
        float|int $latencyMs = 0.0
    ): self {
        return self::create(self::STATUS_UNKNOWN, $labelOrMessage, $messageOrMetrics, $metricsOrLatency, $latencyMs);
    }

    /**
     * Dispatches construction between canonical (4-arg) and concise (3-arg) signatures.
     *
     * @param string $status Health status constant.
     * @param string $labelOrMessage Short status label or detailed message.
     * @param string|array<string, mixed> $messageOrMetrics Detailed message or metrics payload.
     * @param array<string, mixed>|float|int $metricsOrLatency Metrics payload or latency in ms.
     * @param float|int $latencyMs Latency in ms for canonical 4-argument calls.
     */
    private static function create(
        string $status,
        string $labelOrMessage,
        string|array $messageOrMetrics,
        array|float|int $metricsOrLatency,
        float|int $latencyMs
    ): self {
        $defaultLabel = match ($status) {
            self::STATUS_HEALTHY  => 'Operational',
            self::STATUS_DEGRADED => 'Degraded',
            self::STATUS_CRITICAL => 'Critical',
            default               => 'Unknown',
        };

        if (is_array($messageOrMetrics)) {
            // Polymorphic concise form: ($message, $metrics = [], $latencyMs = 0.0)
            $metrics = $messageOrMetrics;
            $latency = is_numeric($metricsOrLatency) ? (float)$metricsOrLatency : (float)$latencyMs;
            $message = $labelOrMessage;
            $label   = $defaultLabel;
        } else {
            // Canonical form: ($label, $message = '', $metrics = [], $latencyMs = 0.0)
            $label   = $labelOrMessage !== '' ? $labelOrMessage : $defaultLabel;
            $message = $messageOrMetrics !== '' ? $messageOrMetrics : $label;
            $metrics = is_array($metricsOrLatency) ? $metricsOrLatency : [];
            $latency = (float)$latencyMs;
        }

        return new self($status, $label, $message, $metrics, $latency, time());
    }

    public function isHealthy(): bool {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isOperational(): bool {
        return $this->status === self::STATUS_HEALTHY || $this->status === self::STATUS_DEGRADED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'status'     => $this->status,
            'label'      => $this->label,
            'message'    => $this->message,
            'metrics'    => $this->metrics,
            'latency_ms' => $this->latencyMs,
            'timestamp'  => $this->timestamp,
        ];
    }
}
