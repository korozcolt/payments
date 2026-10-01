<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter\Database\Migrations;

use CodeIgniter\Database\Migration;
use Korbytes\Payments\Pdo\Schema;

/**
 * Creates the payment tables from the core's bundled schema for the active driver.
 *
 *   php spark migrate -n "Korbytes\Payments\CodeIgniter"
 */
class CreatePaymentsTables extends Migration
{
    protected $DBGroup;

    public function __construct($forge = null)
    {
        parent::__construct($forge);

        $this->DBGroup = config('Payments')->dbGroup;
        $this->db = \Config\Database::connect($this->DBGroup);
    }

    public function up(): void
    {
        foreach (Schema::statements(Schema::dialectFor($this->db->DBDriver)) as $statement) {
            $this->db->query($statement);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(Schema::TABLES) as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$this->db->escapeIdentifiers($table));
        }
    }
}
