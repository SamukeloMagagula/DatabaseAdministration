<?php

declare(strict_types=1);

require_once __DIR__ . '/db_browser.php';

/** Streams $rows as a downloadable CSV. */
function stream_csv(string $filename, array $columns, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn($v) => $v === null ? '' : (string) $v, $row));
    }
    fclose($out);
}

/** CREATE TABLE + INSERT statements for one table, as plain SQL text. */
function sql_dump_table(PDO $pdo, string $db, string $table): string
{
    assert_valid_table($pdo, $db, $table);
    $create = $pdo->query(sprintf('SHOW CREATE TABLE `%s`.`%s`', $db, $table))->fetch();
    $sql = $create['Create Table'] . ";\n\n";

    $stmt = $pdo->query(sprintf('SELECT * FROM `%s`.`%s`', $db, $table));
    while ($row = $stmt->fetch()) {
        $values = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
        $sql .= sprintf(
            "INSERT INTO `%s` (%s) VALUES (%s);\n",
            $table,
            implode(', ', array_map(fn($c) => "`$c`", array_keys($row))),
            implode(', ', $values)
        );
    }
    return $sql . "\n";
}

/** A dump of every table in $db, as plain SQL text. */
function sql_dump_database(PDO $pdo, string $db): string
{
    $sql = '';
    foreach (list_tables($pdo, $db) as $table) {
        $sql .= sql_dump_table($pdo, $db, $table);
    }
    return $sql;
}
