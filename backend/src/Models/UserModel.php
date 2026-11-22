<?php
declare(strict_types=1);

namespace App\Models;

final class UserModel extends Model
{
    public function roles(int $userId): array
    {
        return array_column(
            $this->all('SELECT role FROM user_roles WHERE user_id = ? ORDER BY role', [$userId]),
            'role'
        );
    }

    public function addRole(int $userId, string $role): void
    {
        $this->run('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)', [$userId, $role]);
    }
}
