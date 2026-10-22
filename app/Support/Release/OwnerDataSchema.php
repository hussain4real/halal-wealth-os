<?php

declare(strict_types=1);

namespace App\Support\Release;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * @phpstan-type Column array{name:string,type:string,udt:string,nullable:string}
 * @phpstan-type Table array{table:string,scope:string,columns:list<Column>,order:list<string>}
 * @phpstan-type Contract array{schema_version:int,encoding:string,profile_fields:list<string>,excluded_reason:string,tables:list<Table>,sha256:string}
 */
final class OwnerDataSchema
{
    /** @return Contract */
    public static function current(Connection $connection): array
    {
        $bytes = file_get_contents(__DIR__.'/owner-data-schema.json');
        if ($bytes === false) {
            throw new RuntimeException('Owner export schema is unavailable.');
        }
        $root = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($root) || ($root['schema_version'] ?? null) !== 2 || ! is_array($root['tables'] ?? null) || ! array_is_list($root['tables'])) {
            throw new RuntimeException('Invalid owner export schema.');
        }
        $tables = [];
        foreach ($root['tables'] as $table) {
            if (! is_array($table) || ! is_array($table['columns'] ?? null) || ! array_is_list($table['columns'])) {
                throw new RuntimeException('Invalid owner export table contract.');
            }
            $columns = [];
            foreach ($table['columns'] as $column) {
                if (! is_array($column)) {
                    throw new RuntimeException('Invalid owner export column contract.');
                }
                $columns[] = ['name' => self::identifier($column, 'name'), 'type' => self::text($column, 'type'),
                    'udt' => self::text($column, 'udt'), 'nullable' => self::text($column, 'nullable')];
            }
            $scope = self::text($table, 'scope');
            if (! in_array($scope, ['owner', 'account', 'opening', 'profile', 'excluded'], true)) {
                throw new RuntimeException('Unknown export ownership contract.');
            }
            $tables[] = ['table' => self::identifier($table, 'table'), 'scope' => $scope, 'columns' => $columns, 'order' => self::identifiers($table['order'] ?? null)];
        }
        $contract = ['schema_version' => 2, 'encoding' => self::text($root, 'encoding'), 'profile_fields' => self::identifiers($root['profile_fields'] ?? null),
            'excluded_reason' => self::text($root, 'excluded_reason'), 'tables' => $tables, 'sha256' => hash('sha256', $bytes)];
        $expected = [];
        foreach ($contract['tables'] as $table) {
            foreach ($table['columns'] as $column) {
                $expected[] = [$table['table'], $column['name'], $column['type'], $column['udt'], $column['nullable']];
            }
        }
        $actual = [];
        foreach ($connection->select("SELECT table_name,column_name,data_type,udt_name,is_nullable FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position") as $column) {
            $actual[] = [$column->table_name, $column->column_name, $column->data_type, $column->udt_name, $column->is_nullable];
        }
        if ($actual !== $expected) {
            throw new RuntimeException('Owner export schema requires review before use.');
        }

        return $contract;
    }

    /** @param array<array-key,mixed> $record */
    private static function text(array $record, string $key): string
    {
        $value = $record[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Invalid owner export contract value.');
        }

        return $value;
    }

    /** @param array<array-key,mixed> $record */
    private static function identifier(array $record, string $key): string
    {
        $name = self::text($record, $key);
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $name)) {
            throw new RuntimeException('Invalid export schema identifier.');
        }

        return $name;
    }

    /** @return list<string> */
    private static function identifiers(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            throw new RuntimeException('Invalid export schema identifiers.');
        }
        $names = [];
        foreach ($values as $value) {
            $names[] = self::identifier(['value' => $value], 'value');
        }

        return $names;
    }
}
