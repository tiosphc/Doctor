<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->admin(),
            'actor_name' => fake()->name(),
            'actor_role' => User::ROLE_ADMIN,
            'action' => 'UPDATE',
            'module' => 'STAFF',
            'target_type' => 'User',
            'target_id' => fake()->numberBetween(1, 1000),
            'target_name' => fake()->name(),
            'description' => fake()->sentence(),
            'old_values' => ['phone' => '0900000000'],
            'new_values' => ['phone' => '0911111111'],
            'metadata' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'request_method' => 'PATCH',
            'request_url' => 'http://localhost/api/admin/staff/1',
        ];
    }
}
