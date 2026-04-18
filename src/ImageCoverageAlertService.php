<?php
declare(strict_types=1);

/**
 * Evalúa cobertura de imágenes por fuente y notifica únicamente por Telegram.
 */
class ImageCoverageAlertService
{
    private TelegramNotifier $notifier;
    private FileAlertStateStore $stateStore;
    /** @var callable(string):void */
    private $logger;
    private int $minItems;
    private float $missingRateThreshold;
    private int $cooldownSeconds;

    /**
     * @param callable(string):void $logger
     */
    public function __construct(
        TelegramNotifier $notifier,
        FileAlertStateStore $stateStore,
        callable $logger,
        int $minItems = 8,
        float $missingRateThreshold = 0.40,
        int $cooldownSeconds = 21600
    ) {
        $this->notifier = $notifier;
        $this->stateStore = $stateStore;
        $this->logger = $logger;
        $this->minItems = max(1, $minItems);
        $this->missingRateThreshold = max(0.0, min(1.0, $missingRateThreshold));
        $this->cooldownSeconds = max(0, $cooldownSeconds);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function notifyIfNeeded(string $sourceName, array $items): void
    {
        $total = count($items);
        if ($total < $this->minItems) {
            return;
        }

        $missing = 0;
        foreach ($items as $item) {
            $img = trim((string)($item['image_url'] ?? ''));
            if ($img === '') {
                $missing++;
            }
        }

        $rate = $total > 0 ? ($missing / $total) : 0.0;
        if ($rate < $this->missingRateThreshold) {
            return;
        }

        if (!$this->isCooldownElapsed($sourceName)) {
            return;
        }

        $ratePercent = (int)round($rate * 100);
        $message = sprintf(
            'ALERTA FunesYa: %s con %d%% de notas sin imagen (%d/%d) en el ultimo ciclo.',
            $sourceName,
            $ratePercent,
            $missing,
            $total
        );

        if ($this->notifier->send($message)) {
            $this->stateStore->setLastSentAt($sourceName, time());
            ($this->logger)('[ALERT][TELEGRAM] ' . $message);
            return;
        }

        ($this->logger)('[WARN] No se pudo enviar alerta Telegram de imagenes para ' . $sourceName);
    }

    private function isCooldownElapsed(string $sourceName): bool
    {
        $lastSentAt = $this->stateStore->getLastSentAt($sourceName);
        return (time() - $lastSentAt) >= $this->cooldownSeconds;
    }
}
