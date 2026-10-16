<?php

namespace App\Notifications;

use App\Models\Intern;
use Illuminate\Notifications\Notification;

/**
 * Generic status alert for interns, covering the three lifecycle events
 * raised by the scheduled commands:
 *   - contract_expiring : contract ends within the warning window
 *   - expired           : contract has ended (status flipped to inactive)
 *   - completed         : rendered hours reached the completion threshold
 */
class InternStatusAlert extends Notification
{
    public function __construct(
        public readonly Intern $intern,
        public readonly string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'intern_'.$this->kind,
            'intern_id' => (int) $this->intern->id,
            'intern_name' => $this->intern->name,
            'contract_end_date' => $this->intern->contract_end_date?->toDateString(),
            'rendered_hours' => (int) $this->intern->rendered_hours,
            'required_hours' => (int) $this->intern->required_hours,
            'message' => $this->buildMessage(),
            'url_route' => 'interns.show',
            'url_params' => ['intern' => $this->intern->id],
        ];
    }

    private function buildMessage(): string
    {
        $name = $this->intern->name;

        return match ($this->kind) {
            'contract_expiring' => sprintf(
                '%s\'s internship contract ends on %s.',
                $name,
                $this->intern->contract_end_date?->format('M d, Y') ?? 'N/A'
            ),
            'expired' => sprintf('%s\'s internship contract has ended.', $name),
            'completed' => sprintf(
                '%s has completed %d of %d required hours.',
                $name,
                (int) $this->intern->rendered_hours,
                (int) $this->intern->required_hours
            ),
            default => sprintf('Update for intern %s.', $name),
        };
    }
}
