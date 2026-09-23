<?php

declare(strict_types=1);

/**
 * The data grid: listing, inserting, updating and deleting rows in a
 * visitor-chosen table.
 *
 * $db and $table are trusted here — every entry point calls
 * assert_valid_table() (db_browser.php) first — but column names still come
 * from information_schema, never from $_GET/$_POST directly, and every value
 * is bound. See list_rows()'s placeholder-naming note for the one place that
 * is not obvious.
 */

require_once __DIR__ . '/db_browser.php';
require_once __DIR__ . '/audit.php';

/** One page of rows, optionally sorted and filtered. */
function list_rows(
    PDO $pdo,
    string $db,
    string $table,
    int $page,
    int $pageSize,
    ?string $sortColumn,
    string $sortDir,
    array $filters
): array {
    assert_valid_table($pdo, $db, $table);
    $validColumns = array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME');

    // Placeholder names are synthetic (filter0, filter1, …) rather than
    // derived from the column name: a legal column such as `order-date` or
    // `first name` is not a valid PDO placeholder token.
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

function insert_row(PDO $pdo, string $db, string $table, array $data, int $userId, string $username): void
{
    assert_valid_table($pdo, $db, $table);
    $validColumns = array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME');
    $data = array_intersect_key($data, array_flip($validColumns));
    if (!$data) {
        throw new InvalidArgumentException('No valid columns supplied');
    }

    // Synthetic placeholder names (v0, v1, …) — see the note in list_rows().
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

    audit_record($pdo, $userId, $username, 'ROW_INSERT', $db, $table, json_encode($data));
}

function update_row(PDO $pdo, string $db, string $table, string $pkColumn, $pkValue, array $data, int $userId, string $username): void
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

    // Synthetic placeholder names (v0, v1, …) — see the note in list_rows().
    // This also removes the chance of a column literally named `pk_value`
    // colliding with the WHERE-clause placeholder.
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

    audit_record($pdo, $userId, $username, 'ROW_UPDATE', $db, $table, json_encode(['pk' => $pkValue, 'set' => $data]));
}

function delete_row(PDO $pdo, string $db, string $table, string $pkColumn, $pkValue, int $userId, string $username): void
{
    assert_valid_table($pdo, $db, $table);
    if (!in_array($pkColumn, array_column(table_columns($pdo, $db, $table), 'COLUMN_NAME'), true)) {
        throw new InvalidArgumentException('Invalid primary key column');
    }

    $pdo->prepare(sprintf('DELETE FROM `%s`.`%s` WHERE `%s` = :pk_value', $db, $table, $pkColumn))
        ->execute(['pk_value' => $pkValue]);

    audit_record($pdo, $userId, $username, 'ROW_DELETE', $db, $table, json_encode(['pk' => $pkValue]));
}
