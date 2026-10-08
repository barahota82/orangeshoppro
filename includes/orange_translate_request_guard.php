<?php
declare(strict_types=1);

/**
 * Server-side mirror of the ticket rules. The departments page uses the
 * JavaScript guard in js/content_locale_panel.js on input, clear, and
 * record switch. Saving still checks source identity against current text.
 */

final class OrangeTranslateRequestGuard
{
    private int $revision = 0;
    private int $seq = 0;
    private ?int $inflight = null;
    public string $sourceHash = '';
    public string $targetState = 'eligible';

    public function invalidate(): void
    {
        $this->revision++;
        $this->inflight = null;
    }

    public function begin(): array
    {
        $this->seq++;
        $this->inflight = $this->seq;
        return [
            'token' => $this->seq,
            'revision' => $this->revision,
            'source_hash' => $this->sourceHash,
            'target_state' => $this->targetState,
        ];
    }

    public function accept(array $ticket): bool
    {
        if ($this->inflight === null || (int) ($ticket['token'] ?? 0) !== $this->inflight) {
            return false;
        }
        if ((int) ($ticket['revision'] ?? -1) !== $this->revision) {
            return false;
        }
        if ((string) ($ticket['source_hash'] ?? '') !== $this->sourceHash) {
            return false;
        }
        if ((string) ($ticket['target_state'] ?? '') !== $this->targetState) {
            return false;
        }
        return true;
    }
}
