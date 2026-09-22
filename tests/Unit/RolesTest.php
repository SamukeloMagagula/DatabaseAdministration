<?php

namespace Tests\Unit;

use App\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function test_viewer_can_only_select(): void
    {
        $this->assertTrue(Roles::canRunStatementType(Roles::VIEWER, 'SELECT'));
        $this->assertFalse(Roles::canRunStatementType(Roles::VIEWER, 'INSERT'));
        $this->assertFalse(Roles::canRunStatementType(Roles::VIEWER, 'DDL'));
    }

    public function test_editor_can_run_dml_but_not_ddl(): void
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $type) {
            $this->assertTrue(Roles::canRunStatementType(Roles::EDITOR, $type));
        }
        $this->assertFalse(Roles::canRunStatementType(Roles::EDITOR, 'DDL'));
    }

    public function test_admin_can_run_anything(): void
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DDL'] as $type) {
            $this->assertTrue(Roles::canRunStatementType(Roles::ADMIN, $type));
        }
    }

    public function test_other_statement_type_is_always_allowed(): void
    {
        $this->assertTrue(Roles::canRunStatementType(Roles::VIEWER, 'OTHER'));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Roles::isValid('admin'));
        $this->assertFalse(Roles::isValid('superuser'));
    }
}
