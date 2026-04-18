<?php
declare(strict_types=1);

/**
 * Persistencia de cooldown de alertas por clave (ej: fuente).
 */
class FileAlertStateStore
{
    private string $stateFile;

    public function __construct(string $stateFile)
    {
        $this->stateFile = $stateFile;
    }

    public function getLastSentAt(string $key): int
    {
        $state = $this->load();
        return (int)($state[$key]['last_sent_at'] ?? 0);
    }

    public function setLastSentAt(string $key, int $timestamp): void
    {
        $state = $this->load();
        $state[$key] = ['last_sent_at' => $timestamp];
        $this->save($state);
    }

    private function load(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function save(array $state): void
    {
        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
