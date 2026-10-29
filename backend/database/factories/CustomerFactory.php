<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = Str::lower(fake()->unique()->safeEmail());
        $phone = fake()->unique()->numerify('09########');

        return [
            'customer_code' => null,
            'user_id' => null,
            'name' => fake()->name(),
            'primary_email' => $email,
            'normalized_email' => $email,
            'primary_phone' => $phone,
            'normalized_phone' => '+84'.substr($phone, 1),
            'verified_email_at' => null,
            'verified_phone_at' => null,
            'status' => Customer::STATUS_ACTIVE,
            'source' => Customer::SOURCE_GUEST_BOOKING,
            'merged_into_customer_id' => null,
        ];
    }

    public function withUser(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user ?? User::factory()->customer(),
            'source' => Customer::SOURCE_REGISTERED,
        ]);
    }

    public function guest(): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'source' => Customer::SOURCE_GUEST_BOOKING,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => Customer::STATUS_INACTIVE,
        ]);
    }

    public function merged(?Customer $survivor = null): static
    {
        return $this->state(fn (): array => [
            'status' => Customer::STATUS_MERGED,
            'merged_into_customer_id' => $survivor ?? Customer::factory(),
        ]);
    }
}
