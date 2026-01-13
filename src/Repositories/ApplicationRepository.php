<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use PDO;

/**
 * Repository for managing registered applications.
 *
 * Applications are registered by developers who want to use the API.
 * Each application can have multiple API keys.
 */
class ApplicationRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Create a new application.
     *
     * @param string $userId Zitadel user ID of the owner
     * @param string $name Application name
     * @param string|null $description Application description
     * @param string|null $website Application website URL
     * @return array<string, mixed> The created application with UUID
     */
    public function create(
        string $userId,
        string $name,
        ?string $description = null,
        ?string $website = null
    ): array {
        $stmt = $this->db->prepare(
            'INSERT INTO applications (zitadel_user_id, name, description, website)
             VALUES (:user_id, :name, :description, :website)
             RETURNING id, uuid, name, description, website, is_active, created_at, updated_at'
        );

        $stmt->execute([
            'user_id'     => $userId,
            'name'        => $name,
            'description' => $description,
            'website'     => $website,
        ]);

        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();
        return is_array($result) ? $result : [];
    }

    /**
     * Get an application by UUID.
     *
     * @param string $uuid Application UUID
     * @return array<string, mixed>|null The application or null if not found
     */
    public function getByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, uuid, zitadel_user_id, name, description, website, is_active, created_at, updated_at
             FROM applications
             WHERE uuid = :uuid'
        );

        $stmt->execute(['uuid' => $uuid]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Get an application by ID.
     *
     * @param int $id Application ID
     * @return array<string, mixed>|null The application or null if not found
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, uuid, zitadel_user_id, name, description, website, is_active, created_at, updated_at
             FROM applications
             WHERE id = :id'
        );

        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Get all applications for a user.
     *
     * @param string $userId Zitadel user ID
     * @return array<int, array<string, mixed>> List of applications
     */
    public function getByUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, uuid, name, description, website, is_active, created_at, updated_at
             FROM applications
             WHERE zitadel_user_id = :user_id
             ORDER BY created_at DESC'
        );

        $stmt->execute(['user_id' => $userId]);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Update an application.
     *
     * @param string $uuid Application UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @param array<string, mixed> $data Fields to update (name, description, website)
     * @return array<string, mixed>|null Updated application or null if not found/unauthorized
     */
    public function update(string $uuid, string $userId, array $data): ?array
    {
        $allowedFields = ['name', 'description', 'website'];
        $updates       = [];
        $params        = ['uuid' => $uuid, 'user_id' => $userId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[]      = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            return $this->getByUuid($uuid);
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';

        $sql = sprintf(
            'UPDATE applications SET %s WHERE uuid = :uuid AND zitadel_user_id = :user_id
             RETURNING id, uuid, name, description, website, is_active, created_at, updated_at',
            implode(', ', $updates)
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch();

        return is_array($result) ? $result : null;
    }

    /**
     * Deactivate an application.
     *
     * This soft-deletes the application by setting is_active to false.
     * All associated API keys will also be invalidated.
     *
     * @param string $uuid Application UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if deactivated, false if not found/unauthorized
     */
    public function deactivate(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP
             WHERE uuid = :uuid AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['uuid' => $uuid, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate an application.
     *
     * @param string $uuid Application UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if reactivated, false if not found/unauthorized
     */
    public function reactivate(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE applications
             SET is_active = TRUE, updated_at = CURRENT_TIMESTAMP
             WHERE uuid = :uuid AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['uuid' => $uuid, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an application permanently.
     *
     * This will cascade delete all associated API keys.
     *
     * @param string $uuid Application UUID
     * @param string $userId Owner's Zitadel user ID (for authorization)
     * @return bool True if deleted, false if not found/unauthorized
     */
    public function delete(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM applications
             WHERE uuid = :uuid AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['uuid' => $uuid, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a user owns an application.
     *
     * @param string $uuid Application UUID
     * @param string $userId Zitadel user ID
     * @return bool True if the user owns the application
     */
    public function isOwner(string $uuid, string $userId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM applications
             WHERE uuid = :uuid AND zitadel_user_id = :user_id'
        );

        $stmt->execute(['uuid' => $uuid, 'user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Count applications for a user.
     *
     * @param string $userId Zitadel user ID
     * @return int Number of applications
     */
    public function countByUser(string $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM applications WHERE zitadel_user_id = :user_id'
        );

        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
