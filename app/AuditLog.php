<?php

namespace App;

use PDO;

final class AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(
        ?int $userId,
        string $username,
        string $actionType,
        ?string $targetDb,
        ?string $targetTable,
        string $detail
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (app_user_id, username, action_type, target_db, target_table, detail)
             VALUES (:uid, :username, :action, :db, :table, :detail)'
        );
        $stmt->execute([
            'uid' => $userId,
            'username' => $username,
            'action' => $actionType,
            'db' => $targetDb,
            'table' => $targetTable,
            'detail' => $detail,
        ]);
    }

    public function recent(
        int $limit = 50,
        int $offset = 0,
        ?string $username = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $where = [];
        $params = [];
        if ($username) {
            $where[] = 'username = :username';
            $params['username'] = $username;
        }
        if ($fromDate) {
            $where[] = 'created_at >= :from_date';
            $params['from_date'] = $fromDate . ' 00:00:00';
        }
        if ($toDate) {
            $where[] = 'created_at <= :to_date';
            $params['to_date'] = $toDate . ' 23:59:59';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM audit_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
