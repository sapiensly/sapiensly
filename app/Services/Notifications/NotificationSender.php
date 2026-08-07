<?php

namespace App\Services\Notifications;

use App\Mail\AppNotificationMail;
use App\Models\App;
use App\Models\AppNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends what a workflow's `notify.send` step asked for, and reports exactly
 * what happened.
 *
 * The step is an OUTBOUND channel reachable, indirectly, by anyone who can
 * write a record — including an anonymous visitor on a public portal. So the
 * order here is: resolve, cap, claim quota, then send. A recipient that cannot
 * be resolved, or that falls outside the hour's remaining capacity, is reported
 * back rather than dropped, because "the workflow ran" and "the person was told"
 * are different facts and the author needs both.
 */
class NotificationSender
{
    public function __construct(
        private readonly RecipientResolver $resolver,
        private readonly NotificationQuota $quota,
        private readonly PushSender $push,
    ) {}

    /**
     * @param  list<string>  $references  raw `to` entries, already expression-resolved
     * @return array{
     *     sent: int,
     *     channel: string,
     *     recipients: list<string>,
     *     unresolved: list<string>,
     *     throttled: int,
     *     failed: list<string>,
     *     simulated?: bool,
     * }
     */
    public function send(
        App $app,
        string $channel,
        array $references,
        string $title,
        string $body,
        ?string $link = null,
        ?string $workflowId = null,
        ?string $workflowRunId = null,
        bool $dryRun = false,
    ): array {
        ['recipients' => $recipients, 'unresolved' => $unresolved] = $this->resolver->resolve($app, $references);

        // Cap BEFORE the quota claim, so one over-broad step cannot consume an
        // organization's whole hour.
        $throttled = 0;
        if (count($recipients) > NotificationQuota::MAX_RECIPIENTS_PER_STEP) {
            $throttled += count($recipients) - NotificationQuota::MAX_RECIPIENTS_PER_STEP;
            $recipients = array_slice($recipients, 0, NotificationQuota::MAX_RECIPIENTS_PER_STEP);
        }

        // A verification run resolves and reports, but must never reach a person.
        if ($dryRun) {
            return [
                'sent' => 0,
                'channel' => $channel,
                'recipients' => array_map(fn (NotificationRecipient $r): string => (string) ($r->email ?? 'user:'.$r->userId), $recipients),
                'unresolved' => $unresolved,
                'throttled' => $throttled,
                'failed' => [],
                'simulated' => true,
            ];
        }

        // Said once, plainly, instead of once per recipient as a delivery
        // failure. An installation with no VAPID pair is missing a feature, not
        // failing to use one, and the fix is an environment variable rather
        // than anything about this workflow.
        if ($channel === 'push' && ! $this->push->isConfigured()) {
            return [
                'sent' => 0,
                'channel' => $channel,
                'recipients' => [],
                'unresolved' => $unresolved,
                'throttled' => $throttled,
                'failed' => ['push notifications are not configured on this installation'],
            ];
        }

        $granted = $this->quota->claim($app, count($recipients));
        if ($granted < count($recipients)) {
            $throttled += count($recipients) - $granted;
            $recipients = array_slice($recipients, 0, $granted);
        }

        $sent = 0;
        $failed = [];
        $addressed = [];

        foreach ($recipients as $recipient) {
            $addressed[] = (string) ($recipient->email ?? 'user:'.$recipient->userId);

            try {
                if ($channel === 'in_app') {
                    $this->storeInApp($app, $recipient, $title, $body, $link, $workflowId, $workflowRunId);
                } elseif ($channel === 'push') {
                    // Addressed to a person, delivered to their DEVICES. An
                    // address with no account behind it cannot be one: a push
                    // subscription is made by a browser somebody signed into.
                    if ($recipient->userId === null) {
                        $failed[] = (string) $recipient->email.' (not a member, so no device)';

                        continue;
                    }

                    $result = $this->push->sendToUser($app, $recipient->userId, $title, $body, $link);

                    // Reported rather than counted as sent. "Nobody allowed
                    // notifications yet" and "the push service refused" are
                    // different problems with different fixes, and an author
                    // who is told neither goes looking in the wrong place.
                    if ($result['sent'] === 0) {
                        $failed[] = 'user:'.$recipient->userId.($result['devices'] === 0
                            ? ' (no device has allowed notifications)'
                            : ' (the push service would not deliver)');

                        continue;
                    }
                } else {
                    if ($recipient->email === null) {
                        $failed[] = 'user:'.$recipient->userId.' (no email address)';

                        continue;
                    }
                    Mail::to($recipient->email)->send(new AppNotificationMail($app, $title, $body, $link));
                }
                $sent++;
            } catch (\Throwable $e) {
                // The failure is the author's to see, but the detail is ours:
                // a mail transport error can carry credentials or addresses.
                Log::warning('notify.send delivery failed', [
                    'app_id' => $app->id,
                    'channel' => $channel,
                    'workflow_id' => $workflowId,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = (string) ($recipient->email ?? 'user:'.$recipient->userId);
            }
        }

        return [
            'sent' => $sent,
            'channel' => $channel,
            'recipients' => $addressed,
            'unresolved' => $unresolved,
            'throttled' => $throttled,
            'failed' => $failed,
        ];
    }

    private function storeInApp(
        App $app,
        NotificationRecipient $recipient,
        string $title,
        string $body,
        ?string $link,
        ?string $workflowId,
        ?string $workflowRunId,
    ): void {
        AppNotification::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'recipient_user_id' => $recipient->userId,
            'recipient_email' => $recipient->email,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'workflow_id' => $workflowId,
            'workflow_run_id' => $workflowRunId,
        ]);
    }
}
