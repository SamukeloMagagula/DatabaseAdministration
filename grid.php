<?php

declare(strict_types=1);

// The data grid: list, insert, update, delete rows in a chosen table.
// $db/$table are checked via assert_valid_table() before every query.
// Column names come only from information_schema, values are always bound.

require_once __DIR__ . '/db_browser.php';
require_once __DIR__ . '/audit.php';

function list_rows(PDO $pdo, string $db, string $table, int $page, int $pageSize, ?string $sortColumn, string $sortDir, array $filters): array
{
    assert_valid_table($pdo, $db, $table);
    $validColumns = array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME');

    // Placeholder names are synthetic (filter0, filter1, …): a column like
    // `order-date` isn't a valid PDO placeholder token.
    $where = [];
    $params = [];
    $i = 0;
    foreach ($filters as $col => $value) {
        if (!in_array($col, $validColumns, true) || $value === '') continue;
        $ph = 'filter' . $i++;
        $where[] = sprintf('`%s` LIKE :%s', $col, $ph);
        $params[$ph] = '%' . $value . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $orderSql = '';
    if ($sortColumn !== null && in_array($sortColumn, $validColumns, true)) {
        $dir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
        $orderSql = sprintf('ORDER BY `%s` %s', $sortColumn, $dir);
    }

    $pageSize = max(1, min($pageSize, GRID_PAGE_SIZE_MAX));
    $offset = max(0, ($page - 1) * $pageSize);

    $sql = sprintf('SELECT * FROM `%s`.`%s` %s %s LIMIT :limit OFFSET :offset', $db, $table, $whereSql, $orderSql);
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $countStmt = $pdo->prepare(sprintf('SELECT COUNT(*) FROM `%s`.`%s` %s', $db, $table, $whereSql));
    foreach ($params as $key => $value) {
        $countStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $countStmt->execute();

    return ['rows' => $rows, 'total' => (int) $countStmt->fetchColumn(), 'page' => $page, 'pageSize' => $pageSize];
}

/** All matching rows, ignoring pagination — used by CSV/SQL export. */
function all_rows(PDO $pdo, string $db, string $table, ?string $sortColumn, string $sortDir, array $filters): array
{
    $total = list_rows($pdo, $db, $table, 1, 1, $sortColumn, $sortDir, $filters)['total'];
    return list_rows($pdo, $db, $table, 1, max(1, $total), $sortColumn, $sortDir, $filters)['rows'];
}

function find_row(PDO $pdo, string $db, string $table, string $pkColumn, $pkValue): ?array
{
    assert_valid_table($pdo, $db, $table);
    if (!in_array($pkColumn, array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME'), true)) {
        throw new InvalidArgumentException('Invalid primary key column');
    }

    $stmt = $pdo->prepare(sprintf('SELECT * FROM `%s`.`%s` WHERE `%s` = :pk', $db, $table, $pkColumn));
    $stmt->execute(['pk' => $pkValue]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function insert_row(PDO $pdo, string $db, string $table, array $data, string $username): void
{
    assert_valid_table($pdo, $db, $table);
    $validColumns = array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME');
    $data = array_intersect_key($data, array_flip($validColumns));
    if (!$data) {
        throw new InvalidArgumentException('No valid columns supplied');
    }

    $columns = array_keys($data);
    $placeholders = [];
    $params = [];
    foreach ($columns as $i => $c) {
        $ph = 'v' . $i;
        $placeholders[] = ':' . $ph;
        $params[$ph] = $data[$c];
    }

    $sql = sprintf(
        'INSERT INTO `%s`.`%s` (%s) VALUES (%s)',
        $db,
        $table,
        implode(', ', array_map(fn($c) => "`$c`", $columns)),
        implode(', ', $placeholders)
    );
    $pdo->prepare($sql)->execute($params);

    audit_record($pdo, $username, 'ROW_INSERT', $db, $table, json_encode($data));
}

function update_row(PDO $pdo, string $db, string $table, string $pkColumn, $pkValue, array $data, string $username): void
{
    assert_valid_table($pdo, $db, $table);
    $validColumns = array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME');
    $data = array_intersect_key($data, array_flip($validColumns));
    unset($data[$pkColumn]);
    if (!$data) {
        throw new InvalidArgumentException('No valid columns supplied');
    }
    if (!in_array($pkColumn, $validColumns, true)) {
        throw new InvalidArgumentException('Invalid primary key column');
    }

    $setParts = [];
    $params = [];
    $i = 0;
    foreach ($data as $c => $v) {
        $ph = 'v' . $i++;
        $setParts[] = "`$c` = :$ph";
        $params[$ph] = $v;
    }
    $params['pk_value'] = $pkValue;

    $sql = sprintf('UPDATE `%s`.`%s` SET %s WHERE `%s` = :pk_value', $db, $table, implode(', ', $setParts), $pkColumn);
    $pdo->prepare($sql)->execute($params);

    audit_record($pdo, $username, 'ROW_UPDATE', $db, $table, json_encode(['pk' => $pkValue, 'set' => $data]));
}

function delete_row(PDO $pdo, string $db, string $table, string $pkColumn, $pkValue, string $username): void
{
    assert_valid_table($pdo, $db, $table);
    if (!in_array($pkColumn, array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME'), true)) {
        throw new InvalidArgumentException('Invalid primary key column');
    }

    $pdo->prepare(sprintf('DELETE FROM `%s`.`%s` WHERE `%s` = :pk_value', $db, $table, $pkColumn))
        ->execute(['pk_value' => $pkValue]);

    audit_record($pdo, $username, 'ROW_DELETE', $db, $table, json_encode(['pk' => $pkValue]));
}
