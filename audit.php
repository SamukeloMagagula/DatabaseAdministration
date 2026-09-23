<?php

declare(strict_types=1);

// Who did what: row edits, SQL run, sign-ins. Read on the Audit Log page.

function audit_record(PDO $pdo, string $username, string $actionType, ?string $targetDb, ?string $targetTable, string $detail): void
{
    $table = app_table('audit_log');
    $stmt = $pdo->prepare(
        "INSERT INTO {$table} (username, action_type, target_db, target_table, detail)
         VALUES (:username, :action, :db, :table, :detail)"
    );
    $stmt->execute([
        'username' => $username,
        'action' => $actionType,
        'db' => $targetDb,
        'table' => $targetTable,
        'detail' => $detail,
    ]);
}

/** Recent entries, most recent first, optionally narrowed by username and/or date. */
function audit_recent(PDO $pdo, int $limit = 50, int $offset = 0, ?string $username = null, ?string $fromDate = null, ?string $toDate = null): array
{
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

    $table = app_table('audit_log');
    $stmt = $pdo->prepare(
        "SELECT * FROM {$table} {$whereSql} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
