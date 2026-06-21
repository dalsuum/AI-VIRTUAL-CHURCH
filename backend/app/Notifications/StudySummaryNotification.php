<?php

namespace App\Notifications;

use App\Models\StudySession;
use App\Models\StudySummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a finished AI Bible Study summary (key verses, lessons, prayer, action
 * points, reflection questions, study plan). Sent on demand from the summary page.
 */
class StudySummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public StudySession $session,
        public StudySummary $summary,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('church.frontend_url'), '/');
        $mail = (new MailMessage)
            ->subject('Your AI Bible Study summary')
            ->greeting('Peace be with you.')
            ->line('Here is the summary of your Bible study discussion' . ($this->session->topic ? ' on “' . $this->session->topic . '”.' : '.'));

        $this->appendList($mail, '📖 Key Verses', $this->summary->key_verses);
        $this->appendList($mail, '💡 Main Lessons', $this->summary->lessons);
        if ($this->summary->prayer) {
            $mail->line('**🙏 Prayer**')->line($this->summary->prayer);
        }
        $this->appendList($mail, '✅ Action Points', $this->summary->action_points);
        $this->appendList($mail, '❓ Reflection Questions', $this->summary->reflection_questions);
        $this->appendList($mail, '🗓 Study Plan', $this->summary->study_plan);

        return $mail
            ->action('Open AI Bible Study', $base . '#bible-study')
            ->salutation('Grace and peace, ' . config('app.name'));
    }

    private function appendList(MailMessage $mail, string $heading, ?array $items): void
    {
        $items = array_filter(array_map('trim', (array) ($items ?? [])));
        if (! $items) {
            return;
        }
        $mail->line("**{$heading}**");
        foreach ($items as $item) {
            $mail->line('• ' . $item);
        }
    }
}
