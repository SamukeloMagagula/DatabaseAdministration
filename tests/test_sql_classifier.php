<?php

declare(strict_types=1);

test('classify_sql', function (): void {
    $cases = [
        ['SELECT * FROM widgets', 'SELECT'],
        ['  insert into widgets (name) values ("a")', 'INSERT'],
        ["UPDATE widgets SET name = 'b'", 'UPDATE'],
        ['DELETE FROM widgets WHERE id = 1', 'DELETE'],
        ['DROP TABLE widgets', 'DDL'],
        ['CREATE TABLE widgets (id INT)', 'DDL'],
        ['ALTER TABLE widgets ADD COLUMN qty INT', 'DDL'],
        ['TRUNCATE TABLE widgets', 'DDL'],
        ['SHOW TABLES', 'SELECT'],
        ['DESCRIBE widgets', 'SELECT'],
        // EXPLAIN is deliberately NOT read-only here: MariaDB's legacy
        // EXPLAIN ANALYZE form on an UPDATE/DELETE actually runs it.
        ['EXPLAIN SELECT 1', 'OTHER'],
        ["-- a comment\nSELECT 1", 'SELECT'],
    ];

    foreach ($cases as [$sql, $expected]) {
        same($expected, classify_sql($sql), $sql);
    }
});
