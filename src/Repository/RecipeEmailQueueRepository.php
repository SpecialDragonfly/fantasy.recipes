<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * The "new recipe(s)" marketing-email queue -- see
 * db/migrations/20260831150000_create_recipe_email_queue.php for the table
 * shapes and the queue lifecycle. Thin PDO, no ORM, same as every other
 * repository here.
 *
 * @phpstan-type CampaignRow array{id: int, kind: string, subject: string, status: string, scheduled_for: string, recipients_total: int, recipients_sent: int, last_error: string|null, created_at: string, sent_at: string|null}
 * @phpstan-type DeliveryRow array{id: int, user_id: int, recipient_email: string}
 */
final class RecipeEmailQueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert a campaign plus its recipe links and one pending delivery row
     * per recipient, in one transaction.
     *
     * @param list<int>                                  $recipeIds
     * @param list<array{user_id: int, email: string}>   $recipients
     */
    public function createCampaign(
        string $kind,
        string $subject,
        string $scheduledFor,
        array $recipeIds,
        array $recipients,
    ): int {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO recipe_email_queue '
                . '(kind, subject, status, scheduled_for, recipients_total, recipients_sent, created_at) '
                . "VALUES (:kind, :subject, 'pending', :scheduled_for, :total, 0, :created_at)",
            );
            $insert->execute([
                'kind' => $kind,
                'subject' => $subject,
                'scheduled_for' => $scheduledFor,
                'total' => count($recipients),
                'created_at' => $now,
            ]);
            $queueId = (int) $this->pdo->lastInsertId();

            $linkRecipe = $this->pdo->prepare(
                'INSERT INTO recipe_email_queue_recipes (queue_id, recipe_id) VALUES (:queue_id, :recipe_id)',
            );
            foreach ($recipeIds as $recipeId) {
                $linkRecipe->execute(['queue_id' => $queueId, 'recipe_id' => $recipeId]);
            }

            $addDelivery = $this->pdo->prepare(
                'INSERT INTO recipe_email_queue_deliveries (queue_id, user_id, recipient_email, status) '
                . "VALUES (:queue_id, :user_id, :email, 'pending')",
            );
            foreach ($recipients as $recipient) {
                $addDelivery->execute([
                    'queue_id' => $queueId,
                    'user_id' => $recipient['user_id'],
                    'email' => $recipient['email'],
                ]);
            }

            $this->pdo->commit();

            return $queueId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * @return CampaignRow|null
     */
    public function findCampaign(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipe_email_queue WHERE id = :id');
        $statement->execute(['id' => $id]);

        /** @var CampaignRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Campaign ids that are due to send now: pending and past their
     * scheduled time.
     *
     * @return list<int>
     */
    public function dueCampaignIds(string $now): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM recipe_email_queue WHERE status = 'pending' AND scheduled_for <= :now ORDER BY scheduled_for ASC",
        );
        $statement->execute(['now' => $now]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return list<CampaignRow>
     */
    public function listCampaigns(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM recipe_email_queue ORDER BY created_at DESC, id DESC LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<CampaignRow> */
        return $statement->fetchAll();
    }

    /**
     * @return list<int>
     */
    public function recipeIdsFor(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT recipe_id FROM recipe_email_queue_recipes WHERE queue_id = :id',
        );
        $statement->execute(['id' => $campaignId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Recipe titles per campaign, in publish order, for the admin list.
     *
     * @param  list<int> $campaignIds
     * @return array<int, list<string>>
     */
    public function recipeTitlesFor(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($campaignIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT qr.queue_id, r.title FROM recipe_email_queue_recipes qr '
            . 'JOIN recipes r ON r.id = qr.recipe_id '
            . "WHERE qr.queue_id IN ($placeholders) "
            . 'ORDER BY r.created_at ASC, r.id ASC',
        );
        $statement->execute($campaignIds);

        $byCampaign = [];
        /** @var array{queue_id: int, title: string} $row */
        foreach ($statement->fetchAll() as $row) {
            $byCampaign[(int) $row['queue_id']][] = (string) $row['title'];
        }

        return $byCampaign;
    }

    /**
     * Pending deliveries for a campaign, oldest first.
     *
     * @return list<DeliveryRow>
     */
    public function pendingDeliveries(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, recipient_email FROM recipe_email_queue_deliveries '
            . "WHERE queue_id = :id AND status = 'pending' ORDER BY id ASC",
        );
        $statement->execute(['id' => $campaignId]);

        /** @var list<DeliveryRow> */
        return $statement->fetchAll();
    }

    /**
     * Every delivery row for a campaign, newest status and all, for the
     * per-recipient admin view.
     *
     * @return list<array{id: int, user_id: int, recipient_email: string, status: string, error: string|null, sent_at: string|null}>
     */
    public function listDeliveries(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, recipient_email, status, error, sent_at '
            . 'FROM recipe_email_queue_deliveries WHERE queue_id = :id ORDER BY id ASC',
        );
        $statement->execute(['id' => $campaignId]);

        /** @var list<array{id: int, user_id: int, recipient_email: string, status: string, error: string|null, sent_at: string|null}> */
        return $statement->fetchAll();
    }

    /**
     * One delivery row plus the campaign it belongs to, for the admin
     * "send this one" button.
     *
     * @return array{id: int, queue_id: int, user_id: int, recipient_email: string, status: string}|null
     */
    public function findDelivery(int $deliveryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, queue_id, user_id, recipient_email, status '
            . 'FROM recipe_email_queue_deliveries WHERE id = :id',
        );
        $statement->execute(['id' => $deliveryId]);

        /** @var array{id: int, queue_id: int, user_id: int, recipient_email: string, status: string}|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, int> status => count, for the admin list
     */
    public function deliveryStatusCounts(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n FROM recipe_email_queue_deliveries WHERE queue_id = :id GROUP BY status',
        );
        $statement->execute(['id' => $campaignId]);

        $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        /** @var array{status: string, n: int} $row */
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    public function setCampaignStatus(int $id, string $status, ?string $error = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipe_email_queue SET status = :status, last_error = :error WHERE id = :id',
        );
        $statement->execute(['status' => $status, 'error' => $error, 'id' => $id]);
    }

    public function markCampaignSent(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE recipe_email_queue SET status = 'sent', sent_at = :now, last_error = NULL WHERE id = :id",
        );
        $statement->execute(['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id]);
    }

    public function markDeliverySent(int $deliveryId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE recipe_email_queue_deliveries SET status = 'sent', error = NULL, sent_at = :now WHERE id = :id",
        );
        $statement->execute(['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $deliveryId]);

        $this->pdo->prepare(
            'UPDATE recipe_email_queue SET recipients_sent = recipients_sent + 1 '
            . 'WHERE id = (SELECT queue_id FROM recipe_email_queue_deliveries WHERE id = :id)',
        )->execute(['id' => $deliveryId]);
    }

    public function markDeliveryFailed(int $deliveryId, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE recipe_email_queue_deliveries SET status = 'failed', error = :error WHERE id = :id",
        );
        $statement->execute(['error' => $error, 'id' => $deliveryId]);
    }

    /**
     * Failed deliveries -> pending, for a "Retry" of a dead-lettered
     * campaign. Returns how many were reset.
     */
    public function requeueFailedDeliveries(int $campaignId): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE recipe_email_queue_deliveries SET status = 'pending', error = NULL "
            . "WHERE queue_id = :id AND status = 'failed'",
        );
        $statement->execute(['id' => $campaignId]);

        return $statement->rowCount();
    }

    public function rescheduleCampaign(int $id, string $scheduledFor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipe_email_queue SET scheduled_for = :when WHERE id = :id',
        );
        $statement->execute(['when' => $scheduledFor, 'id' => $id]);
    }
}
