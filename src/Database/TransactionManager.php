<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Throwable;

final readonly class TransactionManager
{
    public function __construct(private PDO $pdo) {}

    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function run(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
