<?php

declare(strict_types=1);

namespace App\Mail;

use App\Repository\RecipeEmailQueueRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Slim\Views\Twig;
use Throwable;

/**
 * The "new recipe(s)" marketing-email pipeline, sitting on top of the queue
 * tables (db/migrations/20260831150000_create_recipe_email_queue.php).
 *
 *  - enqueuePending(): roll every published-but-unannounced recipe into one
 *    campaign -- `single` if there's exactly one, `digest` if more. Run
 *    daily by `mail:enqueue-recipe-notifications` (cron) and by the admin
 *    "Check now" button.
 *  - sendDue() / sendCampaign(): send campaigns whose scheduled time has
 *    passed. Run daily by `mail:send-queue` (cron); a single campaign is
 *    sent immediately by the admin "Send now" / "Retry" buttons.
 *
 * Dead-letter behaviour: the send loop stops on the first delivery error
 * (almost always a provider rate limit), marks the campaign `failed` with
 * the message, and leaves the remaining deliveries `pending` so a later
 * retry resumes exactly where it stopped.
 */
final class RecipeNotifications
{
    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly UserRepository $users,
        private readonly RecipeEmailQueueRepository $queue,
        private readonly Mailer $mailer,
        private readonly Twig $twig,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @return int|null the new campaign id, or null when there's nothing to
     *                  announce or nobody is opted in
     */
    public function enqueuePending(): ?int
    {
        $recipes = $this->recipes->listPublishedAwaitingAnnouncement();

        if ($recipes === []) {
            return null;
        }

        $recipeIds = array_map(static fn (array $r): int => (int) $r['id'], $recipes);
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $recipients = array_map(
            static fn (array $u): array => ['user_id' => (int) $u['id'], 'email' => (string) $u['email']],
            $this->users->listMarketingOptedIn(),
        );

        // Still mark them announced even with no subscribers -- a reader who
        // opts in tomorrow gets things from tomorrow on, not a backlog.
        if ($recipients === []) {
            $this->recipes->markAnnounced($recipeIds, $now);

            return null;
        }

        $kind = count($recipes) === 1 ? 'single' : 'digest';
        $subject = $kind === 'single'
            ? 'New recipe: ' . $recipes[0]['title']
            : count($recipes) . ' new recipes on Fantasy Recipes';

        $campaignId = $this->queue->createCampaign($kind, $subject, $now, $recipeIds, $recipients);
        $this->recipes->markAnnounced($recipeIds, $now);

        return $campaignId;
    }

    public function sendDue(): void
    {
        foreach ($this->queue->dueCampaignIds((new DateTimeImmutable())->format('Y-m-d H:i:s')) as $id) {
            $this->sendCampaign($id);
        }
    }

    /**
     * @return array{sent: int, failed: int, stopped: bool}
     */
    public function sendCampaign(int $id): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'stopped' => false];

        $campaign = $this->queue->findCampaign($id);
        if ($campaign === null || in_array($campaign['status'], ['sent', 'cancelled', 'sending'], true)) {
            return $result;
        }

        $this->queue->setCampaignStatus($id, 'sending');

        $recipes = $this->recipes->findByIds($this->queue->recipeIdsFor($id));
        usort(
            $recipes,
            static fn (array $a, array $b): int => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']],
        );

        $siteUrl = rtrim($this->appUrl, '/');

        foreach ($this->queue->pendingDeliveries($id) as $delivery) {
            $user = $this->users->findById($delivery['user_id']);
            $token = (string) ($user['unsubscribe_token'] ?? '');
            $unsubscribeUrl = $siteUrl . '/unsubscribe?u=' . urlencode($token);
            $body = $this->render((string) $campaign['kind'], $recipes, $token);

            try {
                $this->mailer->send(
                    (string) $delivery['recipient_email'],
                    (string) $campaign['subject'],
                    $body,
                    [
                        // RFC 8058 -- Gmail/Yahoo one-click unsubscribe.
                        'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ],
                );
                $this->queue->markDeliverySent((int) $delivery['id']);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->queue->markDeliveryFailed((int) $delivery['id'], $e->getMessage());
                $this->queue->setCampaignStatus($id, 'failed', $e->getMessage());
                $result['failed']++;
                $result['stopped'] = true;

                return $result;
            }
        }

        $this->queue->markCampaignSent($id);

        return $result;
    }

    public function cancelCampaign(int $id): bool
    {
        $campaign = $this->queue->findCampaign($id);
        if ($campaign === null || in_array($campaign['status'], ['sent', 'sending'], true)) {
            return false;
        }

        $this->queue->setCampaignStatus($id, 'cancelled');
        $this->recipes->clearAnnounced($this->queue->recipeIdsFor($id));

        return true;
    }

    /**
     * @return array{sent: int, failed: int, stopped: bool}
     */
    public function retryCampaign(int $id): array
    {
        $this->queue->requeueFailedDeliveries($id);
        $this->queue->setCampaignStatus($id, 'pending');

        return $this->sendCampaign($id);
    }

    /**
     * @param list<array<string, mixed>> $recipes
     */
    private function render(string $kind, array $recipes, string $unsubscribeToken): string
    {
        $siteUrl = rtrim($this->appUrl, '/');
        $template = $kind === 'single' ? 'emails/recipe_single.txt.twig' : 'emails/recipe_digest.txt.twig';

        return trim($this->twig->getEnvironment()->render($template, [
            'recipe' => $recipes[0] ?? null,
            'recipes' => $recipes,
            'site_url' => $siteUrl,
            'unsubscribe_url' => $siteUrl . '/unsubscribe?u=' . urlencode($unsubscribeToken),
        ])) . "\n";
    }
}
