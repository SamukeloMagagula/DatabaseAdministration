<?php

namespace Tests\Unit;

use App\SqlStatementClassifier;
use PHPUnit\Framework\TestCase;

final class SqlStatementClassifierTest extends TestCase
{
    /** @dataProvider statements */
    public function test_classify(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlStatementClassifier::classify($sql));
    }

    public static function statements(): array
    {
        return [
            ['SELECT * FROM widgets', 'SELECT'],
            ['  insert into widgets (name) values ("a")', 'INSERT'],
            ["UPDATE widgets SET name = 'b'", 'UPDATE'],
            ['DELETE FROM widgets WHERE id = 1', 'DELETE'],
            ['DROP TABLE widgets', 'DDL'],
            ['CREATE TABLE widgets (id INT)', 'DDL'],
            ['ALTER TABLE widgets ADD COLUMN qty INT', 'DDL'],
            ['TRUNCATE TABLE widgets', 'DDL'],
            ['SHOW TABLES', 'OTHER'],
            ["-- a comment\nSELECT 1", 'SELECT'],
        ];
    }
}
